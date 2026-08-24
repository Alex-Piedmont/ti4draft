<?php

declare(strict_types=1);

namespace App\TwilightImperium;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Historical data integrity and such is tested in data/FactionDataTest
 */
class FactionTest extends TestCase
{
    #[Test]
    public function allFactionsCanBeInitialisedFromJson(): void
    {
        $rawData = json_decode(file_get_contents('data/factions.json'), true);
        $factions = Faction::all();

        foreach($rawData as $key => $data) {
            $faction = $factions[$key];

            $this->assertSame($faction->name, $data['name']);
            $this->assertSame($faction->id, $data['id']);
            $this->assertSame($faction->homeSystemTileNumber, $data['homesystem']);
            $this->assertSame($faction->linkToWiki, $data['wiki']);
            $this->assertSame($faction->allianceAbility, $data['alliance_ability']);
            $this->assertSame($faction->minorFactionEligible, $data['minor_faction_eligible']);
        }
    }

    #[Test]
    public function itExposesMinorFactionEligibilityForExceptionalFactions(): void
    {
        $factions = Faction::all();

        foreach ([
            'The Ghosts of Creuss',
            'The Council Keleres',
            'The Crimson Rebellion',
            'Ghoti Wayfarers',
        ] as $name) {
            $this->assertFalse($factions[$name]->minorFactionEligible, $name);
        }

        $firmament = $factions['The Firmament / The Obsidian'];
        $this->assertTrue($firmament->minorFactionEligible);
        $this->assertSame('96a', $firmament->homeSystemTileNumber);
        $this->assertSame(
            'You may treat planets in systems that contain your ships as if you controlled them for the purpose of scoring secret objectives.',
            $firmament->allianceAbility,
        );
    }

    #[Test]
    public function itRejectsNonBooleanMinorFactionEligibility(): void
    {
        $data = json_decode(file_get_contents('data/factions.json'), true)['The Arborec'];
        $data['minor_faction_eligible'] = 'true';

        $this->expectException(\TypeError::class);

        Faction::fromJson($data);
    }

    #[Test]
    public function itRejectsMissingMinorFactionEligibility(): void
    {
        $data = json_decode(file_get_contents('data/factions.json'), true)['The Arborec'];
        unset($data['minor_faction_eligible']);

        $this->expectException(\TypeError::class);

        Faction::fromJson($data);
    }

    public static function malformedAllianceAbilities(): iterable
    {
        yield 'missing' => [null, true];
        yield 'not a string' => [[], false];
        yield 'empty' => ['', false];
        yield 'whitespace only' => [" \t\n", false];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAllianceAbilities')]
    public function itRejectsMalformedAllianceAbilities(mixed $value, bool $unset): void
    {
        $data = json_decode(file_get_contents('data/factions.json'), true)['The Arborec'];
        if ($unset) {
            unset($data['alliance_ability']);
        } else {
            $data['alliance_ability'] = $value;
        }

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Faction alliance_ability must be a non-empty string');

        Faction::fromJson($data);
    }
}
