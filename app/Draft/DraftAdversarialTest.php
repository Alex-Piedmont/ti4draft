<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use App\TwilightImperium\Tile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class DraftAdversarialTest extends TestCase
{
    #[Test]
    public function duplicateMinorFactionAssignmentsAcrossSlicesAreRejected(): void
    {
        $saved = $this->twoSliceMinorDraftPayload();
        $saved['slices'][1]['minor_faction'] = $saved['slices'][0]['minor_faction'];
        $saved['slices'][1]['tiles'][Slice::EQUIDISTANT_INDEX] = $saved['slices'][0]['minor_faction']['tile_id'];

        $this->expectException(\InvalidArgumentException::class);

        Draft::fromJson($saved);
    }

    #[Test]
    public function minorFactionOutsideTheEnabledFactionCatalogIsRejected(): void
    {
        $saved = $this->twoSliceMinorDraftPayload([
            'factionSets' => [Edition::BASE_GAME],
        ]);
        $discordantMinor = MinorFaction::fromFaction(Faction::all()['Augurs of Ilyxum']);
        $saved['slices'][0]['minor_faction'] = $discordantMinor->toPersistedArray();
        $saved['slices'][0]['tiles'][Slice::EQUIDISTANT_INDEX] = $discordantMinor->homeSystem->id;

        $this->expectException(\InvalidArgumentException::class);

        Draft::fromJson($saved);
    }

    #[Test]
    public function sliceOrderPairingsAndTotalsSurviveRoundTrip(): void
    {
        $draft = Draft::fromJson($this->twoSliceMinorDraftPayload());
        $before = array_map(static fn (Slice $slice): array => [
            'minor' => $slice->minorFaction?->toPersistedArray(),
            'tiles' => $slice->tileIds(),
            'resources' => $slice->totalResources,
            'influence' => $slice->totalInfluence,
            'optimal_resources' => $slice->optimalResources,
            'optimal_influence' => $slice->optimalInfluence,
        ], $draft->slicePool);

        $reloaded = Draft::fromJson(json_decode($draft->toFileContent(), true));
        $after = array_map(static fn (Slice $slice): array => [
            'minor' => $slice->minorFaction?->toPersistedArray(),
            'tiles' => $slice->tileIds(),
            'resources' => $slice->totalResources,
            'influence' => $slice->totalInfluence,
            'optimal_resources' => $slice->optimalResources,
            'optimal_influence' => $slice->optimalInfluence,
        ], $reloaded->slicePool);

        $this->assertSame($before, $after);
        $this->assertSame("Sardakk N'orr", $after[0]['minor']['name']);
        $this->assertSame('The Arborec', $after[1]['minor']['name']);
        $this->assertSame('13', $after[0]['tiles'][Slice::EQUIDISTANT_INDEX]);
        $this->assertSame('5', $after[1]['tiles'][Slice::EQUIDISTANT_INDEX]);
    }

    #[Test]
    public function ordinaryTileAtTheAssignedMinorSlotIsRejected(): void
    {
        $saved = $this->twoSliceMinorDraftPayload();
        $saved['slices'][0]['tiles'][Slice::EQUIDISTANT_INDEX] = '1';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('left second-ring slot');

        Draft::fromJson($saved);
    }

    public static function invalidSerializedAssignmentShapes(): iterable
    {
        yield 'scalar assignment' => ['invalid'];
        yield 'list assignment' => [['Sardakk N\'orr', '13']];
        yield 'null faction name' => [['name' => null, 'tile_id' => '13']];
    }

    #[DataProvider('invalidSerializedAssignmentShapes')]
    #[Test]
    public function invalidSerializedAssignmentShapeIsReportedAsDomainFailure(mixed $assignment): void
    {
        $saved = $this->twoSliceMinorDraftPayload();
        $saved['slices'][0]['minor_faction'] = $assignment;

        $this->expectException(\InvalidArgumentException::class);

        Draft::fromJson($saved);
    }

    #[Test]
    public function disabledModeWithoutAssignmentsRoundTripsOrdinaryFiveTileSlices(): void
    {
        $saved = $this->twoSliceMinorDraftPayload(['minorFactionsMode' => false]);
        foreach ($saved['slices'] as &$slice) {
            unset($slice['minor_faction']);
        }
        unset($slice);

        $draft = Draft::fromJson($saved);
        $reloaded = Draft::fromJson(json_decode($draft->toFileContent(), true));

        $this->assertCount(2, $reloaded->slicePool);
        foreach ($reloaded->slicePool as $index => $slice) {
            $this->assertNull($slice->minorFaction);
            $this->assertFalse($slice->minorFactionsMode);
            $this->assertCount(5, $slice->effectiveTiles());
            $this->assertSame($saved['slices'][$index]['tiles'], $slice->tileIds());
        }
    }

    /** @return array<string, mixed> */
    private function twoSliceMinorDraftPayload(array $settingsOverrides = []): array
    {
        $factions = Faction::all();
        $tiles = Tile::all();
        $sardakk = MinorFaction::fromFaction($factions["Sardakk N'orr"]);
        $arborec = MinorFaction::fromFaction($factions['The Arborec']);
        $settings = DraftSettingsFactory::make(array_merge([
            'playerNames' => ['Alice', 'Bob', 'Carol'],
            'numberOfSlices' => 3,
            'numberOfFactions' => 3,
            'minorFactionsMode' => true,
        ], $settingsOverrides));

        $draft = new Draft(
            'adversarial-minors',
            false,
            [
                'a' => new Player(PlayerId::fromString('a'), 'Alice'),
                'b' => new Player(PlayerId::fromString('b'), 'Bob'),
                'c' => new Player(PlayerId::fromString('c'), 'Carol'),
            ],
            $settings,
            new Secrets('secret'),
            [
                new Slice([$tiles['64'], $tiles['33'], $tiles['42'], $sardakk->homeSystem, $tiles['67']], true, $sardakk),
                new Slice([$tiles['59'], $tiles['31'], $tiles['41'], $arborec->homeSystem, $tiles['69']], true, $arborec),
            ],
            [$factions['The Barony of Letnev'], $factions['The Clan of Saar'], $factions['The Embers of Muaat']],
        );

        return json_decode($draft->toFileContent(), true);
    }
}
