<?php

declare(strict_types=1);

namespace App\Draft;

use App\Draft\Commands\GenerateDraft;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\Testing\TestDrafts;
use App\TwilightImperium\Faction;
use App\TwilightImperium\Tile;
use App\TwilightImperium\TileType;
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
        foreach($draft->slicePool as $slice) {
            $this->assertContains(['tiles' => $slice->tileIds()], $data['slices']);
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
    public function publicOutputDerivesMinorAssignmentsButSavedOutputDoesNotPersistThem(): void
    {
        $draft = $this->completedMinorFactionDraft();

        $public = $draft->toArray();
        $saved = json_decode($draft->toFileContent(), true);

        $this->assertSame(MinorFactionAssignments::STATUS_RESOLVED, $public['minor_factions']['status']);
        $this->assertCount(3, $public['minor_factions']['assignments']);
        $this->assertArrayNotHasKey('minor_factions', $saved);
        $this->assertTrue($saved['config']['minor_factions']);
    }

    #[Test]
    public function minorAssignmentsAreStableAfterSavingAndReloading(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $before = $draft->toArray()['minor_factions'];

        $reloaded = Draft::fromJson(json_decode($draft->toFileContent(), true));

        $this->assertSame($before, $reloaded->toArray()['minor_factions']);
        $this->assertTrue($reloaded->slicePool[0]->minorFactionsMode);
    }

    #[Test]
    public function oldMinorFactionDraftsMoveTheReservedBlueTileToTheLeftSecondRingSlot(): void
    {
        $saved = json_decode($this->completedMinorFactionDraft()->toFileContent(), true);
        $saved['slices'][0]['tiles'] = ['64', '33', '42', '67', '59'];

        $reloaded = Draft::fromJson($saved);

        $this->assertSame(['64', '33', '42', '59', '67'], $reloaded->slicePool[0]->tileIds());
        $this->assertSame(TileType::BLUE, $reloaded->slicePool[0]->tiles[Slice::EQUIDISTANT_INDEX]->tileType);
    }

    #[Test]
    public function minorAssignmentsDisappearAfterUndoAndReturnIdenticallyAfterRecompletion(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $resolved = $draft->toArray()['minor_factions'];
        $player = $draft->players['b'];

        $draft->updatePlayerData($player->unpick(PickCategory::FACTION));
        $pending = $draft->toArray()['minor_factions'];

        $this->assertSame(MinorFactionAssignments::STATUS_PENDING, $pending['status']);
        $this->assertSame([], $pending['assignments']);

        $draft->updatePlayerData(
            $draft->players['b']->pick(new Pick(
                $draft->players['b']->id,
                PickCategory::FACTION,
                (string) $player->pickedFaction,
            )),
        );

        $this->assertSame($resolved, $draft->toArray()['minor_factions']);
    }

    #[Test]
    public function malformedMinorFactionDraftCanBePersistedAndReportsInvalidWithoutPartialAssignments(): void
    {
        $draft = $this->completedMinorFactionDraft();
        $draft->factionPool = array_slice($draft->factionPool, 0, 4);

        $savedContent = $draft->toFileContent();
        $public = $draft->toArray();

        $this->assertJson($savedContent);
        $this->assertSame([
            'enabled' => true,
            'equidistant_index' => Slice::EQUIDISTANT_INDEX,
            'status' => MinorFactionAssignments::STATUS_INVALID,
            'assignments' => [],
            'error' => MinorFactionAssignments::ERROR_INSUFFICIENT_ELIGIBLE_CANDIDATES,
        ], $public['minor_factions']);

        $saved = json_decode($savedContent, true);
        $this->assertTrue($saved['config']['minor_factions']);
        $this->assertArrayNotHasKey('minor_factions', $saved);

        $reloaded = Draft::fromJson($saved);
        $this->assertSame($public['minor_factions'], $reloaded->toArray()['minor_factions']);
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
            [new Slice([$tiles['64'], $tiles['33'], $tiles['42'], $tiles['59'], $tiles['67']], true)],
            [
                $factions['The Arborec'],
                $factions['The Barony of Letnev'],
                $factions['The Emirates of Hacan'],
                $factions["Sardakk N'orr"],
                $factions['The Clan of Saar'],
                $factions['The Embers of Muaat'],
            ],
        );
    }
}
