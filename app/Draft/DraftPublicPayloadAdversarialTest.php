<?php

declare(strict_types=1);

namespace App\Draft;

use App\Draft\Commands\GenerateDraft;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use PHPUnit\Framework\Attributes\Test;

final class DraftPublicPayloadAdversarialTest extends TestCase
{
    #[Test]
    public function everyGeneratedAndExtraSlicePublishesOnlyTheAuthoritativeAssignmentShape(): void
    {
        $draft = $this->minorDraft();
        $payload = $draft->toArray();
        $names = [];

        $this->assertCount($draft->settings->numberOfSlices, $payload['slices']);
        foreach ($payload['slices'] as $index => $sliceData) {
            $assignment = $sliceData['minor_faction'];
            $this->assertSame(['name', 'tile_id', 'render_token'], array_keys($assignment));
            $this->assertSame(['index' => 3, 'q' => -1, 'r' => 0], $sliceData['equidistant']);
            $this->assertSame($assignment['tile_id'], $sliceData['tiles'][Slice::EQUIDISTANT_INDEX]);
            $this->assertSame($draft->slicePool[$index]->totalResources, $sliceData['total_resources']);
            $this->assertSame($draft->slicePool[$index]->totalInfluence, $sliceData['total_influence']);
            $names[] = $assignment['name'];
        }

        $this->assertCount(count($names), array_unique($names));
    }

    #[Test]
    public function modeEnabledDraftCannotPublishAPartialSliceWithoutAnAssignment(): void
    {
        $draft = $this->minorDraft();
        $slice = $draft->slicePool[0];
        $draft->slicePool[0] = new Slice($slice->tiles, true, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing a persisted slice assignment');

        $draft->toArray();
    }

    private function minorDraft(): Draft
    {
        return (new GenerateDraft(DraftSettingsFactory::make([
            'playerNames' => ['Amy', 'Ben', 'Charlie'],
            'numberOfSlices' => 5,
            'numberOfFactions' => 5,
            'minorFactionsMode' => true,
            'seed' => 919191,
            'tileSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'factionSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'minimumTwoAlphaBetaWormholes' => false,
            'minimumLegendaryPlanets' => 0,
            'maxOneWormholePerSlice' => false,
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
        ])))->handle();
    }
}
