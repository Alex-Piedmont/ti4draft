<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\Factories\PlanetFactory;
use App\Testing\Factories\TileFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use App\TwilightImperium\TileType;
use App\TwilightImperium\Wormhole;
use PHPUnit\Framework\Attributes\Test;

class SliceAdversarialTest extends TestCase
{
    #[Test]
    public function persistedMinorHomeCanMakeAnOtherwiseEmptySliceMeetValueMinimums(): void
    {
        $minor = MinorFaction::fromFaction(Faction::all()["Sardakk N'orr"]);
        $slice = new Slice([
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
            $minor->homeSystem,
            TileFactory::make(),
        ], true, $minor);

        $this->assertGreaterThan(0, $minor->homeSystem->optimalTotal);
        $this->assertTrue($slice->validate(
            $minor->homeSystem->optimalInfluence,
            $minor->homeSystem->optimalResources,
            $minor->homeSystem->optimalTotal,
            $minor->homeSystem->optimalTotal,
            false,
        ));
    }

    #[Test]
    public function persistedMinorHomeCanMakeASliceExceedTheMaximumValue(): void
    {
        $minor = MinorFaction::fromFaction(Faction::all()["Sardakk N'orr"]);
        $slice = new Slice([
            TileFactory::make([PlanetFactory::make(['resources' => 1])]),
            TileFactory::make(),
            TileFactory::make(),
            $minor->homeSystem,
            TileFactory::make(),
        ], true, $minor);

        $this->assertFalse($slice->validate(
            0,
            0,
            0,
            $minor->homeSystem->optimalTotal,
            false,
        ));
    }

    #[Test]
    public function minorHomeSpecialsAndAnomalyParticipateInFinalSliceRules(): void
    {
        $home = TileFactory::make(
            [PlanetFactory::make(['name' => 'Minor', 'legendary' => 'Power'])],
            [Wormhole::ALPHA],
            'rift',
        );
        $home->tileType = TileType::GREEN;
        $faction = new Faction('Synthetic Minor', 'synthetic', $home->id, '', Edition::BASE_GAME, true);
        $minor = new MinorFaction($faction, $home);
        $otherSpecial = TileFactory::make(
            [PlanetFactory::make(['name' => 'Other', 'legendary' => 'Power'])],
            [Wormhole::ALPHA],
            'nebula',
        );
        $slice = new Slice([
            $otherSpecial,
            TileFactory::make(),
            TileFactory::make(),
            $home,
            TileFactory::make(),
        ], true, $minor);

        $this->assertTrue($slice->hasWormhole(Wormhole::ALPHA));
        $this->assertTrue($slice->hasLegendary());
        $this->assertFalse($slice->validate(0, 0, 0, 100, false));
        $this->assertFalse($slice->tileArrangementIsValid());
    }
}
