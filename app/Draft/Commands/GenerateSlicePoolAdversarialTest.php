<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

class GenerateSlicePoolAdversarialTest extends TestCase
{
    #[Test]
    public function minorModeRejectsFewerCustomRowsThanTheConfiguredSliceCount(): void
    {
        $generator = new GenerateSlicePool(DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfSlices' => 2,
            'minorFactionsMode' => true,
            'customSlices' => [
                ['64', '33', '42', '59', '67'],
            ],
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
            'minimumLegendaryPlanets' => 0,
            'minimumTwoAlphaBetaWormholes' => false,
            'maxOneWormholePerSlice' => false,
        ]), $this->minorFactions(2));

        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(InvalidDraftSettingsException::invalidCustomSlices()->getMessage());

        $generator->handle();
    }

    #[Test]
    public function minorModeRejectsMoreCustomRowsThanTheConfiguredSliceCount(): void
    {
        $generator = new GenerateSlicePool(DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfSlices' => 1,
            'minorFactionsMode' => true,
            'customSlices' => [
                ['64', '33', '42', '59', '67'],
                ['29', '66', '20', '39', '47'],
            ],
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
            'minimumLegendaryPlanets' => 0,
            'minimumTwoAlphaBetaWormholes' => false,
            'maxOneWormholePerSlice' => false,
        ]), $this->minorFactions(1));

        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(InvalidDraftSettingsException::invalidCustomSlices()->getMessage());

        $generator->handle();
    }

    #[Test]
    public function minorModeRejectsAFourTileCustomRowWithAnActionableDomainError(): void
    {
        $generator = new GenerateSlicePool(DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfSlices' => 1,
            'minorFactionsMode' => true,
            'customSlices' => [
                ['64', '33', '42', '59'],
            ],
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
            'minimumLegendaryPlanets' => 0,
            'minimumTwoAlphaBetaWormholes' => false,
            'maxOneWormholePerSlice' => false,
        ]), $this->minorFactions(1));

        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(InvalidDraftSettingsException::invalidCustomSlices()->getMessage());

        $generator->handle();
    }

    /** @return Faction[] */
    private function minorFactions(int $count): array
    {
        return array_slice(array_values(array_filter(
            Faction::all(),
            static fn (Faction $faction): bool => $faction->minorFactionEligible,
        )), 0, $count);
    }
}
