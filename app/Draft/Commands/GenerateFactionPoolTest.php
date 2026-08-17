<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Draft\FactionPartition;
use App\Shared\Command;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

final class GenerateFactionPoolTest extends TestCase
{
    #[Test]
    public function itAlwaysReturnsAPartition(): void
    {
        $command = new GenerateFactionPool(DraftSettingsFactory::make());
        $this->assertInstanceOf(Command::class, $command);
        $partition = $command->handle();
        $this->assertInstanceOf(FactionPartition::class, $partition);
        $this->assertCount(8, $partition->draftable);
        $this->assertSame([], $partition->minors);
    }

    #[Test]
    public function ordinaryModePreservesSeededSelection(): void
    {
        $partition = (new GenerateFactionPool(DraftSettingsFactory::make([
            'seed' => 123,
            'factionSets' => [Edition::BASE_GAME],
            'numberOfFactions' => 3,
        ])))->handle();
        $this->assertSame([
            'The Ghosts of Creuss', 'The Emirates of Hacan', 'The Yssaril Tribes',
        ], $this->names($partition->draftable));
    }

    #[Test]
    public function itCreatesExactDisjointPools(): void
    {
        $partition = $this->minorPartition();
        $this->assertCount(6, $partition->draftable);
        $this->assertCount(4, $partition->minors);
        $this->assertSame([], array_intersect($this->names($partition->draftable), $this->names($partition->minors)));
        $this->assertCount(4, array_filter($partition->minors, fn (Faction $faction) => $faction->minorFactionEligible));
    }

    #[Test]
    public function pinnedFactionsRemainExclusivelyDraftable(): void
    {
        $partition = $this->minorPartition(['customFactions' => ['The Ghosts of Creuss']]);
        $this->assertContains('The Ghosts of Creuss', $this->names($partition->draftable));
        $this->assertNotContains('The Ghosts of Creuss', $this->names($partition->minors));
    }

    #[Test]
    public function itUsesOnlyEnabledSetsAndRequiresTheKeleresToggle(): void
    {
        $without = $this->minorPartition(['factionSets' => [Edition::BASE_GAME, Edition::THUNDERS_EDGE]]);
        $with = $this->minorPartition([
            'factionSets' => [Edition::BASE_GAME, Edition::THUNDERS_EDGE],
            'includeCouncilKeleresFaction' => true,
            'customFactions' => ['The Council Keleres'],
        ]);
        $this->assertNotContains('The Council Keleres', $this->names([...$without->draftable, ...$without->minors]));
        $this->assertContains('The Council Keleres', $this->names([...$with->draftable, ...$with->minors]));
        $this->assertNotContains('The Council Keleres', $this->names($with->minors));
        foreach ([...$without->draftable, ...$without->minors] as $faction) {
            $this->assertContains($faction->edition, [Edition::BASE_GAME, Edition::THUNDERS_EDGE]);
        }
    }

    #[Test]
    public function identicalSeedReproducesBothOrderedPools(): void
    {
        $first = $this->minorPartition();
        $second = $this->minorPartition();
        $this->assertSame($this->names($first->draftable), $this->names($second->draftable));
        $this->assertSame($this->names($first->minors), $this->names($second->minors));
    }

    #[Test]
    public function itRejectsInsufficientCatalogOrEligibleCapacity(): void
    {
        foreach ([
            ['numberOfFactions' => 7, 'numberOfSlices' => 6, 'factionSets' => [Edition::PROPHECY_OF_KINGS]],
            ['numberOfFactions' => 3, 'numberOfSlices' => 6, 'factionSets' => [Edition::THUNDERS_EDGE], 'includeCouncilKeleresFaction' => true],
        ] as $overrides) {
            try {
                $this->minorPartition($overrides);
                $this->fail('Insufficient faction capacity was accepted');
            } catch (InvalidDraftSettingsException $exception) {
                $this->assertMatchesRegularExpression('/catalog shortage|additional eligible factions/', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itRejectsInvalidPins(): void
    {
        foreach ([
            [['The Arborec', 'The Arborec'], 'duplicates are not allowed'],
            [['Unknown Faction'], 'Unknown Faction is unknown or disabled'],
            [['The Naaz-Rokha Alliance'], 'The Naaz-Rokha Alliance is unknown or disabled'],
            [['The Arborec', 'The Barony of Letnev', 'The Clan of Saar', 'The Emirates of Hacan'], 'more factions were pinned than requested'],
        ] as [$pins, $expectedCondition]) {
            try {
                $this->minorPartition(['numberOfFactions' => 3, 'customFactions' => $pins, 'factionSets' => [Edition::BASE_GAME, Edition::THUNDERS_EDGE]]);
                $this->fail('Invalid pins were accepted');
            } catch (InvalidDraftSettingsException $exception) {
                $this->assertStringContainsString('Invalid custom faction selection', $exception->getMessage());
                $this->assertStringContainsString($expectedCondition, $exception->getMessage());
            }
        }
    }

    private function minorPartition(array $overrides = []): FactionPartition
    {
        return (new GenerateFactionPool(DraftSettingsFactory::make(array_merge([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 6,
            'numberOfSlices' => 4,
            'factionSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'minorFactionsMode' => true,
            'seed' => 789,
        ], $overrides))))->handle();
    }

    /** @param Faction[] $factions */
    private function names(array $factions): array
    {
        return array_map(fn (Faction $faction): string => $faction->name, $factions);
    }
}
