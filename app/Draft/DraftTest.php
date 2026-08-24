<?php

declare(strict_types=1);

namespace App\Draft;

use App\Draft\Commands\GenerateDraft;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\Testing\TestDrafts;
use App\TwilightImperium\Faction;
use App\TwilightImperium\Tile;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

/**
 * We shouldn't worry about players/settings getting initialised or serialised again
 * That should be tested in their own class. Just checking that they're in the right place.
 */
class DraftTest extends TestCase
{
    #[DataProviderExternal(TestDrafts::class, 'provideTestDrafts')]
    #[Test]
    public function ìtCanBeInitialisedFromJson($data): void
    {
        $draft = Draft::fromJson($data);

        $this->assertNotEmpty($draft->players);
        $this->assertSame($data['config']['name'], (string) $draft->settings->name);
        $this->assertSame($draft->id, $data['id']);
        $this->assertSame($draft->isDone, $data['done']);

        $factionPoolNames = array_map(fn (Faction $f) => $f->name, $draft->factionPool);

        foreach($data['factions'] as $faction) {
            $this->assertContains($faction, $factionPoolNames);
        }
        $this->assertSame($draft->currentPlayerId->value, $data['draft']['current']);
    }

    #[Test]
    public function itCanBeConvertedToArray(): void
    {
        $factions = Faction::all();
        $tiles = Tile::all();
        $player = new Player(
            PlayerId::fromString('player_123'),
            'Alice',
        );
        $draft = new Draft(
            '1243',
            true,
            [$player->id->value => $player],
            DraftSettingsFactory::make(),
            new Secrets(
                'secret123',
            ),
            [
               new Slice([
                   $tiles['64'],
                   $tiles['33'],
                   $tiles['42'],
                   $tiles['67'],
                   $tiles['59'],
               ]),
            ],
            [
                $factions['The Barony of Letnev'],
                $factions['The Embers of Muaat'],
                $factions['The Clan of Saar'],
            ],
            [new Pick($player->id, PickCategory::FACTION, 'Vulraith')],
            $player->id,
        );

        $data = $draft->toArray();

        $this->assertSame($draft->settings->toArray(), $data['config']);
        $this->assertSame($draft->id, $data['id']);
        $this->assertSame($draft->isDone, $data['done']);
        $this->assertSame($player->name, $data['draft']['players'][$player->id->value]['name']);
        $this->assertSame($player->id->value, $data['draft']['current']);
        $this->assertSame('Vulraith', $data['draft']['log'][0]['value']);
        foreach($draft->factionPool as $faction) {
            $this->assertContains($faction->name, $data['factions']);
        }
        foreach($draft->slicePool as $index => $slice) {
            $this->assertSame($slice->tileIds(), $data['slices'][$index]['tiles']);
        }
    }

    #[Test]
    public function itCanUpdatePlayerData(): void
    {
        $draft = (new GenerateDraft(DraftSettingsFactory::make()))->handle();

        $playerId = PlayerId::fromString(array_keys($draft->players)[3]);

        $player = $draft->playerById($playerId);

        $newPlayerData = $player->pick(new Pick($playerId, PickCategory::FACTION, 'Xxcha'));

        $draft->updatePlayerData($newPlayerData);

        $this->assertSame($draft->playerById($playerId)->toArray(), $newPlayerData->toArray());
        $this->assertSame($draft->playerById($playerId)->getPick(PickCategory::FACTION), 'Xxcha');
    }

    #[Test]
    public function itCanUpdateCurrentPlayerInSnakeDraft(): void
    {
        $draft = (new GenerateDraft(DraftSettingsFactory::make()))->handle();

        // the order for three rounds: Regular + Reverse + Regular
        $order = array_merge(array_keys($draft->players), array_keys(array_reverse($draft->players)), array_keys($draft->players));

        foreach($order as $expectedCurrentPlayer) {
            $this->assertSame($draft->currentPlayerId->value, $expectedCurrentPlayer);
            $draft->log[] = new Pick($draft->currentPlayerId, PickCategory::FACTION, 'foo');
            $draft->updateCurrentPlayer();
        }

        // it sets the draft to done at the end
        $this->assertNull($draft->currentPlayerId);
        $this->assertTrue($draft->isDone);
    }

    #[Test]
    public function minorFactionAssignmentsArePersistedPerSlice(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $saved = json_decode($draft->toFileContent(), true);

        $this->assertSame(
            $draft->slicePool[0]->minorFaction->toPersistedArray(),
            $saved['slices'][0]['minor_faction'],
        );
        $this->assertTrue($saved['config']['minor_factions']);
    }

    #[Test]
    public function minorAssignmentsAreStableAfterSavingAndReloading(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $before = $draft->slicePool[0]->minorFaction->toArray();

        $reloaded = Draft::fromJson(json_decode($draft->toFileContent(), true));

        $this->assertSame($before, $reloaded->slicePool[0]->minorFaction->toArray());
        $this->assertSame($draft->slicePool[0]->tileIds(), $reloaded->slicePool[0]->tileIds());
        $this->assertTrue($reloaded->slicePool[0]->minorFactionsMode);
    }

    #[Test]
    public function modeEnabledDraftsRequirePersistedAssignments(): void
    {
        $saved = json_decode($this->completedMinorFactionDraft()->toFileContent(), true);
        unset($saved['slices'][0]['minor_faction']);

        $this->expectException(\InvalidArgumentException::class);
        Draft::fromJson($saved);
    }

    #[Test]
    public function minorAssignmentsRemainStableAcrossPlayerChanges(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $resolved = $draft->slicePool[0]->minorFaction->toArray();
        $player = $draft->players['b'];

        $draft->updatePlayerData($player->unpick(PickCategory::FACTION));
        $this->assertSame($resolved, $draft->slicePool[0]->minorFaction->toArray());

        $draft->updatePlayerData(
            $draft->players['b']->pick(new Pick(
                $draft->players['b']->id,
                PickCategory::FACTION,
                (string) $player->pickedFaction,
            )),
        );

        $this->assertSame($resolved, $draft->slicePool[0]->minorFaction->toArray());
    }

    #[Test]
    public function publicSliceAssignmentAndTotalsAreStableAcrossPollAndUndo(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $before = $draft->toArray()['slices'][0];
        $player = $draft->players['b'];

        $this->assertSame(['index' => 3, 'q' => -1, 'r' => 0], $before['equidistant']);
        $this->assertSame(
            $draft->slicePool[0]->minorFaction->homeSystem->id,
            $before['tiles'][Slice::EQUIDISTANT_INDEX],
        );

        $draft->updatePlayerData($player->unpick(PickCategory::FACTION));
        $afterUndo = $draft->toArray()['slices'][0];
        $afterPoll = $draft->toArray()['slices'][0];

        $this->assertSame(json_encode($before['minor_faction']), json_encode($afterUndo['minor_faction']));
        $this->assertSame($before, $afterUndo);
        $this->assertSame($before, $afterPoll);
    }

    #[Test]
    public function mismatchedPersistedMinorFactionStateFailsClosed(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $saved = json_decode($draft->toFileContent(), true);
        $saved['slices'][0]['minor_faction']['tile_id'] = '1';

        $this->expectException(\InvalidArgumentException::class);
        Draft::fromJson($saved);
    }

    private function completedMinorFactionDraft(): Draft
    {
        $factions = Faction::all();
        $tiles = Tile::all();
        $players = [
            'a' => new Player(PlayerId::fromString('a'), 'Alice', pickedPosition: '2', pickedFaction: 'The Arborec'),
            'b' => new Player(PlayerId::fromString('b'), 'Bob', pickedPosition: '0', pickedFaction: 'The Barony of Letnev'),
            'c' => new Player(PlayerId::fromString('c'), 'Carol', pickedPosition: '1', pickedFaction: 'The Emirates of Hacan'),
        ];

        $minorFaction = MinorFaction::fromFaction($factions["Sardakk N'orr"]);

        return new Draft(
            'minor-test',
            true,
            $players,
            DraftSettingsFactory::make([
                'numberOfPlayers' => 3,
                'numberOfFactions' => 6,
                'minorFactionsMode' => true,
            ]),
            new Secrets('secret'),
            [new Slice([
                $tiles['64'],
                $tiles['33'],
                $tiles['42'],
                $minorFaction->homeSystem,
                $tiles['67'],
            ], true, $minorFaction)],
            [
                $factions['The Arborec'],
                $factions['The Barony of Letnev'],
                $factions['The Emirates of Hacan'],
                $factions['The Xxcha Kingdom'],
                $factions['The Clan of Saar'],
                $factions['The Embers of Muaat'],
            ],
        );
    }
}
