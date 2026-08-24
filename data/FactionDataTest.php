<?php

namespace data;

use App\TwilightImperium\Planet;
use App\TwilightImperium\SpaceStation;
use App\TwilightImperium\Tile;
use App\TwilightImperium\TileType;
use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class FactionDataTest extends TestCase
{
    protected static function getJsonData(): array
    {
        return json_decode(file_get_contents('data/factions.json'), true);
    }

    public static function allJsonFactions(): iterable
    {
        $data = self::getJsonData();
        foreach($data as $key => $factionData) {
            yield 'For Faction ' . $factionData['name'] => [
                'key' => $key,
                'factionData' => $factionData
            ];
        }
    }

    #[Test]
    #[DataProvider('allJsonFactions')]
    public function eachFactionHasData($key, $factionData) {
        $this->assertNotEmpty($factionData['set']);
        if ($key != 'The Council Keleres') {
            $this->assertNotEmpty($factionData['homesystem']);
        }
        $this->assertNotEmpty($factionData['name']);
        $this->assertNotEmpty($factionData['wiki']);
        $this->assertArrayHasKey('alliance_ability', $factionData);
        $this->assertIsString($factionData['alliance_ability']);
        $this->assertNotSame('', trim($factionData['alliance_ability']));
        $this->assertArrayHasKey('minor_faction_eligible', $factionData);
        $this->assertIsBool($factionData['minor_faction_eligible']);
    }

    #[Test]
    public function allAllianceAbilitiesMatchThePinnedReferenceByFactionIdentity(): void
    {
        $reference = json_decode(
            file_get_contents('tests/fixtures/ti4-reference-alliance-abilities-0c2e2b66.json'),
            true,
        );

        $this->assertSame('0c2e2b66e8ccfb38c3cc7f1fc1f5f2e82a53ecb7', $reference['source_revision']);

        $expected = array_map(
            static fn (array $entry): string => $entry['text'],
            $reference['abilities'],
        );
        $actual = array_map(
            static fn (array $faction): string => $faction['alliance_ability'],
            self::getJsonData(),
        );

        $this->assertCount(64, $expected);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    #[DataProvider('allJsonFactions')]
    public function eachEligibleMinorFactionHasAPlanetaryHomeSystem($key, $factionData): void
    {
        if (! $factionData['minor_faction_eligible']) {
            $this->addToAssertionCount(1);
            return;
        }

        $tiles = Tile::all();
        $homeSystem = (string) $factionData['homesystem'];

        $this->assertArrayHasKey($homeSystem, $tiles, $key);
        $this->assertSame(TileType::GREEN, $tiles[$homeSystem]->tileType, $key);
        $this->assertNotEmpty($tiles[$homeSystem]->planets, $key);
    }

    #[Test]
    public function onlyDocumentedExceptionsAreIneligibleMinorFactions(): void
    {
        $ineligibleFactions = array_keys(array_filter(
            self::getJsonData(),
            static fn (array $factionData): bool => ! $factionData['minor_faction_eligible'],
        ));
        sort($ineligibleFactions);

        $expected = [
            'Ghoti Wayfarers',
            'The Council Keleres',
            'The Crimson Rebellion',
            'The Ghosts of Creuss',
        ];
        sort($expected);

        $this->assertSame($expected, $ineligibleFactions);
    }


    #[Test]
    #[DataProvider('allJsonFactions')]
    public function eachFactionHasNameAsKey($key, $factionData) {
        $this->assertSame($factionData['name'], $key);
    }

    /**
     * Changing the name of a faction would break old drafts
     *
     * @return void
     */
    #[Test]
    public function allHistoricFactionsHaveData() {
        $historicFactions = json_decode(file_get_contents('data/historic-test-data/all-factions-ever.json'));

        $currentFactions = array_keys(self::getJsonData());

        foreach($historicFactions as $name) {
            $this->assertContains($name, $currentFactions);
        }
    }
}
