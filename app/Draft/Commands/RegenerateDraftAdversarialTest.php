<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Draft;
use App\Draft\Slice;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RegenerateDraftAdversarialTest extends TestCase
{
    /** @var string[] */
    private array $draftIds = [];

    #[After]
    public function cleanUpDrafts(): void
    {
        foreach ($this->draftIds as $draftId) {
            try {
                app()->repository->delete($draftId);
            } catch (\Throwable) {
                // A failed generation may legitimately leave no file to delete.
            }
        }
    }

    #[Test]
    public function orderOnlyRegenerationPreservesEveryFactionSliceAndMinorPairing(): void
    {
        $draft = $this->savedMinorDraft(24681357);
        $oldSeed = $draft->settings->seed->getValue();
        $oldPlayerIds = array_keys($draft->players);
        $oldFactions = $this->factionNames($draft);
        $oldSlices = $this->sliceIds($draft);
        $oldMinors = $this->minorNames($draft);

        (new RegenerateDraft($draft, false, false, true))->handle();

        $this->assertNotSame($oldSeed, $draft->settings->seed->getValue());
        $this->assertSame($oldFactions, $this->factionNames($draft));
        $this->assertSame($oldSlices, $this->sliceIds($draft));
        $this->assertSame($oldMinors, $this->minorNames($draft));

        $draft->settings->seed->setForPlayerOrder();
        shuffle($oldPlayerIds);
        $this->assertSame($oldPlayerIds, array_keys($draft->players));
        $this->assertSame(array_key_first($draft->players), $draft->currentPlayerId->value);

        $reloaded = app()->repository->load($draft->id);
        $this->assertSame($draft->toFileContent(), $reloaded->toFileContent());
    }

    public static function coupledRegenerationFlags(): iterable
    {
        yield 'faction-only request' => [false, true];
        yield 'slice-only request' => [true, false];
    }

    #[Test]
    #[DataProvider('coupledRegenerationFlags')]
    public function eitherMinorPoolRegenerationRequestPersistsACompleteCoupledState(
        bool $regenerateSlices,
        bool $regenerateFactions,
    ): void {
        $draft = $this->savedMinorDraft(97531);

        (new RegenerateDraft($draft, $regenerateSlices, $regenerateFactions, false))->handle();

        $this->assertCount($draft->settings->numberOfFactions, $draft->factionPool);
        $this->assertCount($draft->settings->numberOfSlices, $draft->slicePool);
        $this->assertSame([], array_intersect($this->factionNames($draft), $this->minorNames($draft)));

        foreach ($draft->slicePool as $slice) {
            $this->assertNotNull($slice->minorFaction);
            $this->assertSame(
                $slice->minorFaction->homeSystem->id,
                $slice->tileIds()[Slice::EQUIDISTANT_INDEX],
            );
            $this->assertCount(5, $slice->effectiveTiles());
        }

        $partition = (new GenerateFactionPool($draft->settings))->handle();
        $replayedSlices = (new GenerateSlicePool($draft->settings, $partition->minors))->handle();
        $this->assertSame(
            array_map(static fn (Faction $faction): string => $faction->name, $partition->draftable),
            $this->factionNames($draft),
        );
        $this->assertSame(
            array_map(static fn (Slice $slice): array => $slice->tileIds(), $replayedSlices),
            $this->sliceIds($draft),
        );

        $reloaded = app()->repository->load($draft->id);
        $this->assertSame($draft->toFileContent(), $reloaded->toFileContent());
    }

    #[Test]
    public function failedCoupledRegenerationPreservesObjectStateAndExactSavedBytes(): void
    {
        $draft = $this->savedMinorDraft(86420);
        $draft->settings = DraftSettingsFactory::make([
            'playerNames' => array_map(static fn ($player): string => $player->name, $draft->players),
            'presetDraftOrder' => true,
            'numberOfFactions' => 6,
            'numberOfSlices' => 4,
            'minorFactionsMode' => true,
            'factionSets' => [Edition::THUNDERS_EDGE],
            'seed' => 86420,
        ]);
        app()->repository->save($draft);

        $path = env('STORAGE_PATH') . '/draft_' . $draft->id . '.json';
        $savedBytes = file_get_contents($path);
        $objectState = $draft->toFileContent();

        try {
            (new RegenerateDraft($draft, true, true, true))->handle();
            $this->fail('Impossible coupled regeneration unexpectedly succeeded');
        } catch (\Throwable) {
            $this->assertSame($objectState, $draft->toFileContent());
            $this->assertSame($savedBytes, file_get_contents($path));
        }
    }

    private function savedMinorDraft(int $seed): Draft
    {
        $draft = (new GenerateDraft(DraftSettingsFactory::make([
            'playerNames' => ['Alice', 'Bob', 'Carol'],
            'presetDraftOrder' => true,
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
        ])))->handle();
        app()->repository->save($draft);
        $this->draftIds[] = $draft->id;

        return $draft;
    }

    /** @return string[] */
    private function factionNames(Draft $draft): array
    {
        return array_map(static fn (Faction $faction): string => $faction->name, $draft->factionPool);
    }

    /** @return array<int, array<int, string>> */
    private function sliceIds(Draft $draft): array
    {
        return array_map(static fn (Slice $slice): array => $slice->tileIds(), $draft->slicePool);
    }

    /** @return string[] */
    private function minorNames(Draft $draft): array
    {
        return array_map(
            static fn (Slice $slice): string => $slice->minorFaction->faction->name,
            $draft->slicePool,
        );
    }
}
