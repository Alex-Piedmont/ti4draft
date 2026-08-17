<?php

declare(strict_types=1);

namespace App\Draft;

use App\TwilightImperium\Faction;
use App\TwilightImperium\Tile;
use App\TwilightImperium\TileType;

class Draft
{
    public function __construct(
        // @todo implement DraftId value object
        public string   $id,
        public bool     $isDone,
        /** @var array<string, Player> $players */
        public array    $players,
        public Settings $settings,
        public Secrets  $secrets,
        /** @var array<Slice> $slicePool */
        public array $slicePool,
        /** @var array<Faction> $factionPool */
        public array $factionPool,
        /** @var array<Pick> $log */
        public array $log = [],
        public ?PlayerId $currentPlayerId = null,
    ) {
    }

    public static function fromJson($data)
    {
        /**
         * @var array<string, Player>
         */
        $players = array_reduce($data['draft']['players'], function ($players, $playerData) {
            $player = Player::fromJson($playerData);
            $players[$player->id->value] = $player;

            return $players;
        }, []);

        $settings = Settings::fromJson($data['config']);
        $slices = self::slicesFromJson($data['slices'], $settings->minorFactionsMode);
        $factions = self::factionsFromJson($data['factions']);

        if ($settings->minorFactionsMode) {
            self::validateMinorFactionState($slices, $factions, $settings);
        }

        return new self(
            $data['id'],
            $data['done'],
            $players,
            $settings,
            Secrets::fromJson($data['secrets']),
            $slices,
            $factions,
            array_map(fn ($logData) => Pick::fromJson($logData), $data['draft']['log']),
            $data['draft']['current'] != null ? PlayerId::fromString($data['draft']['current']) : null,
        );
    }

    /**
     * @param Slice[] $slices
     * @param Faction[] $draftableFactions
     */
    private static function validateMinorFactionState(array $slices, array $draftableFactions, Settings $settings): void
    {
        $minorNames = [];
        $draftableNames = array_map(fn (Faction $faction): string => $faction->name, $draftableFactions);

        foreach ($slices as $slice) {
            $minor = $slice->minorFaction;
            if ($minor === null) {
                throw new \InvalidArgumentException('Minor Factions draft is missing a persisted slice assignment');
            }

            $enabled = in_array($minor->faction->edition, $settings->factionSets, true)
                || ($minor->faction->name === 'The Council Keleres' && $settings->includeCouncilKeleresFaction);
            if (! $enabled) {
                throw new \InvalidArgumentException('Persisted Minor Faction is not from an enabled faction set');
            }

            if (in_array($minor->faction->name, $minorNames, true)) {
                throw new \InvalidArgumentException('Persisted Minor Factions must be unique');
            }
            if (in_array($minor->faction->name, $draftableNames, true)) {
                throw new \InvalidArgumentException('Persisted Minor Faction overlaps the draftable faction pool');
            }

            $minorNames[] = $minor->faction->name;
        }
    }

    /**
     * @return array<Slice>
     */
    private static function slicesFromJson($slicesData, bool $minorFactionsMode): array
    {
        $allTiles = Tile::all();

        return array_map(function (array $sliceData) use ($allTiles, $minorFactionsMode) {
            $tiles = array_map(
                fn (string|int $tileId) => $allTiles[$tileId],
                $sliceData['tiles'],
            );

            if ($minorFactionsMode && ! isset($sliceData['minor_faction'])) {
                throw new \InvalidArgumentException('Minor Factions draft is missing a persisted slice assignment');
            }

            $minorFaction = isset($sliceData['minor_faction'])
                ? MinorFaction::fromArray($sliceData['minor_faction'])
                : null;

            return new Slice($tiles, $minorFactionsMode, $minorFaction);
        }, $slicesData);
    }

    /**
     * @return array<Faction>
     */
    private static function factionsFromJson($factionNames): array
    {
        $allFactions = Faction::all();

        return array_map(function (string $name) use ($allFactions) {
            return $allFactions[$name];
        }, $factionNames);
    }

    public function toFileContent(): string
    {
        return json_encode($this->baseArray(true));
    }

    public function toArray($includeSecrets = false): array
    {
        $data = $this->baseArray($includeSecrets);
        $data['slices'] = array_map(function (Slice $slice): array {
            $sliceData = $slice->toJson();
            if ($slice->minorFaction !== null) {
                $sliceData['minor_faction'] = $slice->minorFaction->toArray();
                $sliceData['equidistant'] = [
                    'index' => Slice::EQUIDISTANT_INDEX,
                    ...Slice::EQUIDISTANT_COORDINATE,
                ];
            }

            return $sliceData;
        }, $this->slicePool);
        $data['minor_factions'] = (new MinorFactionAssignments(
            $this->settings->minorFactionsMode,
            $this->players,
            $this->factionPool,
        ))->toArray();

        return $data;
    }

    private function baseArray(bool $includeSecrets): array
    {
        $data = [
            'id' => $this->id,
            'done' => $this->isDone,
            'config' => $this->settings->toArray(),
            'draft' => [
                'players' => array_map(fn (Player $player) => $player->toArray(), $this->players),
                'log' => array_map(fn (Pick $pick) => $pick->toArray(), $this->log),
                'current' => $this->currentPlayerId?->value,
            ],
            'factions' => array_map(fn (Faction $f) => $f->name, $this->factionPool),
            'slices' => array_map(function (Slice $slice): array {
                $data = ['tiles' => $slice->tileIds()];
                if ($slice->minorFaction !== null) {
                    $data['minor_faction'] = $slice->minorFaction->toPersistedArray();
                }

                return $data;
            }, $this->slicePool),
        ];

        if ($includeSecrets) {
            $data['secrets'] = $this->secrets->toArray();
        }

        return $data;
    }

    public function updateCurrentPlayer(): void
    {
        $doneSteps = count($this->log);
        $snakeDraft = array_merge(array_keys($this->players), array_keys(array_reverse($this->players)));

        if (count($this->log) >= (count($this->players) * 3)) {
            $this->isDone = true;
            $this->currentPlayerId = null;
        } else {
            $this->isDone = false;
            $this->currentPlayerId = PlayerId::fromString($snakeDraft[$doneSteps % count($snakeDraft)]);
        }
    }

    public function canRegenerate(): bool
    {
        return empty($this->log);
    }

    public function playerById(PlayerId $id): Player
    {
        foreach ($this->players as $p) {
            if ($p->id->equals($id)) {
                return $p;
            }
        }

        throw new \Exception('No player found with id ' . $id->value);
    }

    public function updatePlayerData(Player $newPlayerData): void
    {
        if (! isset($this->players[$newPlayerData->id->value])) {
            throw new \Exception('No player found with id ' . $newPlayerData->id->value);
        }

        $this->players[$newPlayerData->id->value] = $newPlayerData;
    }

}
