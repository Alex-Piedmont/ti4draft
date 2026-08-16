<?php

declare(strict_types=1);

namespace App\Draft;

use App\TwilightImperium\Faction;

final class MinorFactionAssignments
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_INVALID = 'invalid';
    public const ERROR_INSUFFICIENT_ELIGIBLE_CANDIDATES = 'insufficient_eligible_candidates';

    /**
     * @param array<Player> $players
     * @param array<Faction> $factionPool
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $players,
        private readonly array $factionPool,
    ) {
    }

    public function toArray(): array
    {
        $result = [
            'enabled' => $this->enabled,
            'equidistant_index' => Slice::EQUIDISTANT_INDEX,
            'status' => self::STATUS_PENDING,
            'assignments' => [],
        ];

        if (! $this->enabled || ! $this->allRequiredPicksAreComplete()) {
            return $result;
        }

        $selectedFactions = array_map(
            static fn (Player $player): string => (string) $player->pickedFaction,
            $this->players,
        );
        $candidates = array_values(array_filter(
            $this->factionPool,
            static fn (Faction $faction): bool =>
                $faction->minorFactionEligible &&
                ! in_array($faction->name, $selectedFactions, true),
        ));

        if (count($candidates) < count($this->players)) {
            $result['status'] = self::STATUS_INVALID;
            $result['error'] = self::ERROR_INSUFFICIENT_ELIGIBLE_CANDIDATES;

            return $result;
        }

        $playersByPosition = array_values($this->players);
        usort(
            $playersByPosition,
            static fn (Player $left, Player $right): int =>
                (int) $left->pickedPosition <=> (int) $right->pickedPosition,
        );

        $result['status'] = self::STATUS_RESOLVED;
        $result['assignments'] = array_map(
            static fn (Player $player, int $index): array => [
                'position' => (int) $player->pickedPosition,
                'faction' => $candidates[$index]->name,
                'home_system' => $candidates[$index]->homesystem(),
            ],
            $playersByPosition,
            array_keys($playersByPosition),
        );

        return $result;
    }

    private function allRequiredPicksAreComplete(): bool
    {
        foreach ($this->players as $player) {
            if (! $player->hasPickedFaction() || ! $player->hasPickedPosition()) {
                return false;
            }
        }

        return true;
    }
}
