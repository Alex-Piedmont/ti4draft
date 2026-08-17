<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use PHPUnit\Framework\Attributes\Test;

class SettingsAdversarialTest extends TestCase
{
    #[Test]
    public function minorModeAcceptsExactlyOneCustomSliceRowPerConfiguredSlice(): void
    {
        $settings = DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 6,
            'numberOfSlices' => 3,
            'minorFactionsMode' => true,
            'customSlices' => array_fill(0, 3, ['64', '33', '42', '59', '67']),
        ]);

        $this->assertTrue($settings->validate());
    }

    #[Test]
    public function disabledModeStillAppliesTheOrdinaryThreeBlueTileCapacity(): void
    {
        $settings = DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 6,
            'numberOfSlices' => 6,
            'tileSets' => [Edition::BASE_GAME],
            'factionSets' => [Edition::BASE_GAME],
            'minorFactionsMode' => false,
            'minimumLegendaryPlanets' => 0,
        ]);

        $this->expectException(Exceptions\InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(
            Exceptions\InvalidDraftSettingsException::notEnoughTilesForSlices(5)->getMessage(),
        );

        $settings->validate();
    }
}
