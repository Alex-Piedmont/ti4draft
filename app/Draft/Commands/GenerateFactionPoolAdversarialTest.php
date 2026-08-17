<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Draft\FactionPartition;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

final class GenerateFactionPoolAdversarialTest extends TestCase
{
    #[Test]
    public function playerCountBoundaryKeepsRequestedDraftablesAndAllExtraSlicesAsMinors(): void
    {
        foreach (range(3, 8) as $playerCount) {
            $partition = $this->generate([
                'numberOfPlayers' => $playerCount,
                'numberOfFactions' => $playerCount,
                'numberOfSlices' => $playerCount + 2,
            ]);

            $this->assertCount($playerCount, $partition->draftable, "{$playerCount}-player draftable count");
            $this->assertCount($playerCount + 2, $partition->minors, "{$playerCount}-player minor count");
            $this->assertSame([], array_intersect($this->names($partition->draftable), $this->names($partition->minors)));
        }
    }

    #[Test]
    public function exactTotalCatalogCapacityIsAccepted(): void
    {
        // Prophecy of Kings contains exactly seven factions.
        $partition = $this->generate([
            'numberOfFactions' => 3,
            'numberOfSlices' => 4,
            'factionSets' => [Edition::PROPHECY_OF_KINGS],
        ]);

        $this->assertCount(3, $partition->draftable);
        $this->assertCount(4, $partition->minors);
    }

    #[Test]
    public function oneBelowTotalCatalogCapacityReportsCatalogShortage(): void
    {
        // The catalog has seven entries but this partition requires eight.
        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessageMatches('/catalog|enabled factions?.*8.*required/i');

        $this->generate([
            'numberOfFactions' => 4,
            'numberOfSlices' => 4,
            'factionSets' => [Edition::PROPHECY_OF_KINGS],
        ]);
    }

    #[Test]
    public function exactEligibleNonPinnedCapacityIsAccepted(): void
    {
        // Base has sixteen eligible factions plus the ineligible Ghosts pin.
        $partition = $this->generate([
            'numberOfFactions' => 1,
            'numberOfSlices' => 16,
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => ['The Ghosts of Creuss'],
        ]);

        $this->assertSame(['The Ghosts of Creuss'], $this->names($partition->draftable));
        $this->assertCount(16, $partition->minors);
    }

    #[Test]
    public function oneBelowEligibleNonPinnedCapacityReportsEligibilityShortage(): void
    {
        // Pinning one of base game's sixteen eligible factions leaves only fifteen minors.
        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessageMatches('/eligible/i');

        $this->generate([
            'numberOfFactions' => 1,
            'numberOfSlices' => 16,
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => ['The Arborec'],
        ]);
    }

    #[Test]
    public function enabledIneligibleKeleresCanFillDraftableCapacityButNeverMinorCapacity(): void
    {
        // Thunder's Edge has four eligible factions; enabled Keleres is the sixth catalog entry.
        $partition = $this->generate([
            'numberOfFactions' => 2,
            'numberOfSlices' => 4,
            'factionSets' => [Edition::THUNDERS_EDGE],
            'includeCouncilKeleresFaction' => true,
            'customFactions' => ['The Council Keleres'],
        ]);

        $this->assertContains('The Council Keleres', $this->names($partition->draftable));
        $this->assertNotContains('The Council Keleres', $this->names($partition->minors));
        $this->assertCount(4, $partition->minors);
    }

    #[Test]
    public function oneSeedPhaseProducesTheContractedOrderedPartition(): void
    {
        $partition = $this->generate([
            'numberOfFactions' => 3,
            'numberOfSlices' => 4,
            'factionSets' => [Edition::PROPHECY_OF_KINGS],
            'seed' => 789,
        ]);

        $this->assertSame([
            'The Argent Flight',
            "The Vuil'raith Cabal",
            'The Mahact Gene-sorcerers',
        ], $this->names($partition->draftable));
        $this->assertSame([
            'The Naaz-Rokha Alliance',
            'The Empyrean',
            'The Titans of Ul',
            'The Nomad',
        ], $this->names($partition->minors));
    }

    private function generate(array $overrides): FactionPartition
    {
        return (new GenerateFactionPool(DraftSettingsFactory::make(array_merge([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 3,
            'numberOfSlices' => 4,
            'factionSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'minorFactionsMode' => true,
            'seed' => 789,
        ], $overrides))))->handle();
    }

    /** @param Faction[] $factions */
    private function names(array $factions): array
    {
        return array_map(static fn (Faction $faction): string => $faction->name, $factions);
    }
}
