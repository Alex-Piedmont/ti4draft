<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Slice;
use App\Shared\Command;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\Testing\UsesTestDraft;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RegenerateDraftTest extends TestCase
{
    use UsesTestDraft;

    #[Test]
    public function itImplementsCommand(): void
    {
        $cmd = new RegenerateDraft($this->testDraft, true, true, false);
        $this->assertInstanceOf(Command::class, $cmd);
    }

    public static function options() {
        yield 'When regenerating slices' => [
            'slices' => true,
            'factions' => false,
            'order' => false,
        ];
        yield 'When regenerating factions' => [
            'slices' => false,
            'factions' => true,
            'order' => false,
        ];
        yield 'When regenerating order' => [
            'slices' => false,
            'factions' => false,
            'order' => true,
        ];
        yield 'When regenerating everything' => [
            'slices' => true,
            'factions' => true,
            'order' => true,
        ];
    }

    #[Test]
    #[DataProvider('options')]
    public function itCanRegenerateDraft(bool $slices, bool $factions, bool $order): void
    {
        $oldSlices = array_map(fn (Slice $slice) => $slice->tileIds(), $this->testDraft->slicePool);
        $oldFactions = array_map(fn (Faction $faction) => $faction->name, $this->testDraft->factionPool);
        $oldOrder = array_keys($this->testDraft->players);

        $cmd = new RegenerateDraft($this->testDraft, $slices, $factions, $order);
        $cmd->handle();

        $this->reloadDraft();

        $newSlices = array_map(fn (Slice $slice) => $slice->tileIds(), $this->testDraft->slicePool);
        $newFactions = array_map(fn (Faction $faction) => $faction->name, $this->testDraft->factionPool);
        $newOrder = array_keys($this->testDraft->players);

        if ($slices) {
            $this->assertNotSame($oldSlices, $newSlices);
        } else {
            $this->assertSame($oldSlices, $newSlices);
        }

        if ($factions) {
            $this->assertNotSame($oldFactions, $newFactions);
        } else {
            $this->assertSame($oldFactions, $newFactions);
        }

        if ($order) {
            $this->assertEqualsCanonicalizing($oldOrder, $newOrder);
            $this->assertNotEquals($oldOrder, $newOrder);
        } else {
            $this->assertSame($oldOrder, $newOrder);
        }
    }

    #[Test]
    public function itUpdatesCurrentPlayerWhenRegeneratingPlayerOrder(): void
    {
        $cmd = new RegenerateDraft($this->testDraft, false, false, true);

        $cmd->handle();
        $this->reloadDraft();

        $this->assertSame($this->testDraft->currentPlayerId->value, array_key_first($this->testDraft->players));
    }

    #[Test]
    public function minorModeRegeneratesFactionAndSliceStateTogetherAndPersistsTheSeed(): void
    {
        $draft = (new GenerateDraft($this->minorSettings(24680)))->handle();
        $oldSeed = $draft->settings->seed->getValue();
        $oldFactions = array_map(fn (Faction $faction) => $faction->name, $draft->factionPool);
        $oldSlices = array_map(fn (Slice $slice) => $slice->tileIds(), $draft->slicePool);

        (new RegenerateDraft($draft, true, false, false))->handle();

        $this->assertNotSame($oldSeed, $draft->settings->seed->getValue());
        $this->assertNotSame($oldFactions, array_map(fn (Faction $faction) => $faction->name, $draft->factionPool));
        $this->assertNotSame($oldSlices, array_map(fn (Slice $slice) => $slice->tileIds(), $draft->slicePool));

        $partition = (new GenerateFactionPool($draft->settings))->handle();
        $replayedSlices = (new GenerateSlicePool($draft->settings, $partition->minors))->handle();
        $this->assertSame(
            array_map(fn (Faction $faction) => $faction->name, $draft->factionPool),
            array_map(fn (Faction $faction) => $faction->name, $partition->draftable),
        );
        $this->assertSame(
            array_map(fn (Slice $slice) => $slice->tileIds(), $draft->slicePool),
            array_map(fn (Slice $slice) => $slice->tileIds(), $replayedSlices),
        );
    }

    #[Test]
    public function failedMinorRegenerationLeavesAllInMemoryStateUntouched(): void
    {
        $draft = (new GenerateDraft($this->minorSettings(13579)))->handle();
        $draft->settings = DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 6,
            'numberOfSlices' => 4,
            'minorFactionsMode' => true,
            'factionSets' => [Edition::THUNDERS_EDGE],
            'seed' => 13579,
        ]);
        $before = $draft->toFileContent();

        try {
            (new RegenerateDraft($draft, true, true, true))->handle();
            $this->fail('Impossible regeneration unexpectedly succeeded');
        } catch (\Throwable) {
            $this->assertSame($before, $draft->toFileContent());
        }
    }

    #[Test]
    public function combinedMinorRegenerationSeedReproducesOrderCurrentPoolsAndPairings(): void
    {
        $draft = (new GenerateDraft($this->minorSettings(54321)))->handle();
        $originalIds = array_keys($draft->players);

        (new RegenerateDraft($draft, true, true, true))->handle();

        $draft->settings->seed->setForPlayerOrder();
        shuffle($originalIds);
        $this->assertSame($originalIds, array_keys($draft->players));
        $this->assertSame($originalIds[0], $draft->currentPlayerId->value);

        $partition = (new GenerateFactionPool($draft->settings))->handle();
        $slices = (new GenerateSlicePool($draft->settings, $partition->minors))->handle();
        $this->assertSame(
            array_map(fn (Faction $faction) => $faction->name, $partition->draftable),
            array_map(fn (Faction $faction) => $faction->name, $draft->factionPool),
        );
        $this->assertSame(
            array_map(fn (Slice $slice) => $slice->tileIds(), $slices),
            array_map(fn (Slice $slice) => $slice->tileIds(), $draft->slicePool),
        );
    }

    private function minorSettings(int $seed): \App\Draft\Settings
    {
        return DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 6,
            'numberOfSlices' => 4,
            'minorFactionsMode' => true,
            'seed' => $seed,
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
            'minimumLegendaryPlanets' => 0,
            'minimumTwoAlphaBetaWormholes' => false,
            'maxOneWormholePerSlice' => false,
        ]);
    }
}
