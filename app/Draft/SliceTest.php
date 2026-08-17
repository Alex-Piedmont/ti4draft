<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\Factories\PlanetFactory;
use App\Testing\Factories\TileFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Planet;
use App\TwilightImperium\TileType;
use App\TwilightImperium\Wormhole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SliceTest extends TestCase
{
    #[Test]
    public function itCalculatesTotalAndOptimalValues(): void
    {
        $planets = [
            PlanetFactory::make([
                'resources' => 4,
                'influence' => 2,
            ]),  // optimal: 4, 0
            PlanetFactory::make([
                'resources' => 3,
                'influence' => 3,
            ]), // optimal: 1.5, 1.5
            PlanetFactory::make([
                'resources' => 1,
                'influence' => 0,
            ]), // optimal: 1, 0
            PlanetFactory::make([
                'resources' => 1,
                'influence' => 2,
            ]), // optimal: 0, 2
        ];

        $totalInfluence = array_reduce($planets, fn ($sum, Planet $p) => $sum += $p->influence);
        $totalResources = array_reduce($planets, fn ($sum, Planet $p) => $sum += $p->resources);
        $optimalInfluence = array_reduce($planets, fn ($sum, Planet $p) => $sum += $p->optimalInfluence);
        $optimalResources = array_reduce($planets, fn ($sum, Planet $p) => $sum += $p->optimalResources);

        $slice = new Slice([
            TileFactory::make([$planets[0], $planets[1]]),
            TileFactory::make([$planets[2], $planets[3]]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $this->assertSame($totalResources, $slice->totalResources);
        $this->assertSame($totalInfluence, $slice->totalInfluence);
        $this->assertSame($optimalResources, $slice->optimalResources);
        $this->assertSame($optimalInfluence, $slice->optimalInfluence);
        $this->assertSame($optimalResources + $optimalInfluence, $slice->optimalTotal);
    }

    public static function tileConfigurations(): iterable
    {
        yield 'When it has no anomalies' => [
            'tiles' => [
                TileFactory::make([], [], null),
                TileFactory::make([], [], null),
                TileFactory::make([], [], null),
                TileFactory::make([], [], null),
                TileFactory::make([], [], null),
            ],
            'canBeArranged' => true,
        ];
        yield 'When it has some anomalies' => [
            'tiles' => [
                TileFactory::make([], [], 'nebula'),
                TileFactory::make([], [], 'asteroid field'),
                TileFactory::make([], [], null),
                TileFactory::make([], [], null),
                TileFactory::make([], [], null),
            ],
            'canBeArranged' => true,
        ];
        yield 'When it has too many anomalies' => [
            'tiles' => [
                TileFactory::make([], [], 'nebula'),
                TileFactory::make([], [], 'asteroid field'),
                TileFactory::make([], [], 'gravity-rift'),
                TileFactory::make([], [], 'supernova'),
                TileFactory::make([], [], null),
            ],
            'canBeArranged' => false,
        ];
    }

    #[DataProvider('tileConfigurations')]
    #[Test]
    public function itCanArrangeTiles(array $tiles, bool $canBeArranged): void
    {
        $slice = new Slice($tiles);
        $seed = new Seed(1);

        $arranged = $slice->arrange($seed);

        $this->assertSame($canBeArranged, $arranged);
        $this->assertSame($canBeArranged, $slice->tileArrangementIsValid());
    }

    #[Test]
    public function itWontAllowSlicesWithTooManyWormholes(): void
    {
        $slice = new Slice([
            TileFactory::make([], [Wormhole::ALPHA]),
            TileFactory::make([], [Wormhole::ALPHA]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(0, 0, 0, 0, true);

        $this->assertFalse($valid);
    }

    #[Test]
    public function itWontAllowSlicesWithTooManyLegendaryPlanets(): void
    {
        $slice = new Slice([
            TileFactory::make([PlanetFactory::make(['legendary' => 'Yes'])]),
            TileFactory::make([PlanetFactory::make(['legendary' => 'Yes'])]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(0, 0, 0, 0, false);

        $this->assertFalse($valid);
    }

    #[Test]
    public function itCanValidateMaxWormholes(): void
    {
        $slice = new Slice([
            TileFactory::make([], [Wormhole::ALPHA]),
            TileFactory::make([], [Wormhole::BETA]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(0, 0, 0, 0, true);

        $this->assertFalse($valid);
    }

    #[Test]
    public function itCanValidateMinimumOptimalInfluence(): void
    {
        $slice = new Slice([
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 2,
                    'resources' => 3,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 1,
                    'resources' => 0,
                ]),
            ]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(
            2,
            0,
            0,
            0,
            false,
        );

        $this->assertFalse($valid);
    }

    #[Test]
    public function itCanValidateMinimumOptimalResources(): void
    {
        $slice = new Slice([
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 5,
                    'resources' => 2,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 1,
                    'resources' => 1,
                ]),
            ]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(
            0,
            3,
            0,
            0,
            false,
        );
        $this->assertFalse($valid);
    }

    #[Test]
    public function itCanValidateMinimumOptimalTotal(): void
    {
        $slice = new Slice([
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 4,
                    'resources' => 2,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 1,
                    'resources' => 1,
                ]),
            ]),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(
            0,
            0,
            5,
            0,
            false,
        );

        $this->assertFalse($valid);
    }

    #[Test]
    public function itCanValidateMaximumOptimalTotal(): void
    {
        $slice = new Slice([
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 2,
                    'resources' => 4,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 2,
                    'resources' => 1,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 3,
                    'resources' => 1,
                ]),
            ]),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(
            0,
            0,
            0,
            4,
            false,
        );
        $this->assertFalse($valid);
    }

    #[Test]
    public function itCanValidateAValidSlice(): void
    {
        $slice = new Slice([
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 2,
                    'resources' => 3,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 2,
                    'resources' => 1,
                ]),
            ]),
            TileFactory::make([
                PlanetFactory::make([
                    'influence' => 1,
                    'resources' => 1,
                ]),
                PlanetFactory::make([
                    'influence' => 1,
                    'resources' => 1,
                ]),
            ]),
            TileFactory::make(),
            TileFactory::make(),
        ]);

        $valid = $slice->validate(
            1,
            3,
            5,
            7,
            false,
        );

        $this->assertTrue($valid);
    }

    #[Test]
    public function minorFactionsExcludesTheEquidistantTileFromEffectiveValuesAndSpecials(): void
    {
        $reserved = TileFactory::make(
            [PlanetFactory::make(['resources' => 9, 'influence' => 9, 'legendary' => 'Reserved'])],
            [Wormhole::ALPHA],
        );
        $slice = new Slice([
            TileFactory::make([PlanetFactory::make(['resources' => 2])]),
            TileFactory::make(),
            TileFactory::make(),
            $reserved,
            TileFactory::make(),
        ], true);

        $this->assertSame(3, Slice::EQUIDISTANT_INDEX);
        $this->assertCount(4, $slice->effectiveTiles());
        $this->assertSame(2, $slice->totalResources);
        $this->assertFalse($slice->hasLegendary());
        $this->assertFalse($slice->hasWormhole(Wormhole::ALPHA));
        $this->assertFalse($slice->validate(0, 0, 3, 20, false));
        $this->assertCount(5, $slice->tileIds());
    }

    #[Test]
    public function minorFactionsArrangementPlacesABlueTileAtTheEquidistantIndex(): void
    {
        $tiles = array_map(fn () => TileFactory::make(), range(1, 5));
        $tiles[0]->tileType = TileType::RED;
        $tiles[1]->tileType = TileType::RED;
        $slice = new Slice($tiles, true);

        $this->assertTrue($slice->arrange(new Seed(123)));
        $this->assertSame(TileType::BLUE, $slice->tiles[Slice::EQUIDISTANT_INDEX]->tileType);
    }

    #[Test]
    public function reservedTileCannotSatisfyAnyMinimumValueConstraint(): void
    {
        $reserved = TileFactory::make([
            PlanetFactory::make(['resources' => 10, 'influence' => 10]),
        ]);
        $slice = new Slice([
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
            $reserved,
            TileFactory::make(),
        ], true);

        $this->assertFalse($slice->validate(0, 1, 0, 100, false));
        $this->assertFalse($slice->validate(1, 0, 0, 100, false));
        $this->assertFalse($slice->validate(0, 0, 1, 100, false));
    }

    #[Test]
    public function reservedSpecialsCannotCauseRetainedSliceLimitsToFail(): void
    {
        $retained = TileFactory::make(
            [PlanetFactory::make([
                'name' => 'Retained',
                'resources' => 0,
                'influence' => 0,
                'legendary' => 'Retained',
            ])],
            [Wormhole::ALPHA],
        );
        $reserved = TileFactory::make(
            [PlanetFactory::make(['resources' => 10, 'influence' => 10, 'legendary' => 'Reserved'])],
            [Wormhole::ALPHA],
        );
        $slice = new Slice([
            $retained,
            TileFactory::make(),
            TileFactory::make(),
            $reserved,
            TileFactory::make(),
        ], true);

        $this->assertTrue($slice->validate(0, 0, 0, 0, true));
        $this->assertSame(['Retained'], array_map(
            static fn (string $description): string => explode(':', $description, 2)[0],
            $slice->legendaryPlanets,
        ));
        $this->assertSame([Wormhole::ALPHA], $slice->wormholes);
    }

    #[Test]
    public function disabledModeKeepsTheFifthTileInAllExistingSliceCalculations(): void
    {
        $fifthTile = TileFactory::make(
            [PlanetFactory::make(['resources' => 4, 'influence' => 2, 'legendary' => 'Included'])],
            [Wormhole::BETA],
        );
        $slice = new Slice([
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
            TileFactory::make(),
            $fifthTile,
        ], false);

        $this->assertCount(5, $slice->effectiveTiles());
        $this->assertSame(4, $slice->totalResources);
        $this->assertSame(2, $slice->totalInfluence);
        $this->assertTrue($slice->hasLegendary());
        $this->assertTrue($slice->hasWormhole(Wormhole::BETA));
        $this->assertTrue($slice->validate(0, 4, 4, 4, true));
        $this->assertCount(5, $slice->toJson()['tiles']);
    }
}
