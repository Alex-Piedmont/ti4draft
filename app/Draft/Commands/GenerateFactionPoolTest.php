<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Shared\Command;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\TestCase;
use App\Testing\TestSets;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

class GenerateFactionPoolTest extends TestCase
{
    #[Test]
    public function itImplementsCommand(): void
    {
        $cmd = new GenerateFactionPool(DraftSettingsFactory::make());
        $this->assertInstanceOf(Command::class, $cmd);
    }
    
    #[Test]
    #[DataProviderExternal(TestSets::class, 'setCombinations')]
    public function itCanGenerateChoicesFromFactionSets($sets): void
    {
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'factionSets' => $sets,
            'numberOfFactions' => 10,
        ]));

        $choices = $generator->handle();
        $choicesNames = array_map(fn (Faction $faction) => $faction->name, $choices);

        $this->assertCount(10, $choices);
        $this->assertCount(10, array_unique($choicesNames));
        foreach($choices as $choice) {
            $this->assertContains($choice->edition, $sets);
        }
    }

    #[Test]
    public function itUsesOnlyCustomFactionsWhenEnoughAreProvided(): void
    {
        $customFactions = [
            'The Barony of Letnev',
            'The Clan of Saar',
            'The Emirates of Hacan',
            'The Ghosts of Creuss',
        ];
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'customFactions' => $customFactions,
            'factionSets' => [Edition::BASE_GAME],
            'numberOfFactions' => 3,
        ]));

        $choices = $generator->handle();

        $this->assertCount(3, $choices);
        foreach($choices as $choice) {
            $this->assertContains($choice->name, $customFactions);
        }
    }

    #[Test]
    public function itGeneratesTheSameFactionsFromTheSameSeed(): void
    {
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'seed' => 123,
            'factionSets' => [Edition::BASE_GAME],
            'numberOfFactions' => 3,
        ]));
        $previouslyGeneratedChoices = [
            'The Ghosts of Creuss',
            'The Emirates of Hacan',
            'The Yssaril Tribes',
        ];

        $choices = $generator->handle();

        foreach($previouslyGeneratedChoices as $i => $name) {
            $this->assertSame($name, $choices[$i]->name);
        }
    }

    #[Test]
    public function itTakesFromSetsWhenNotEnoughCustomFactionsAreProvided(): void
    {
        $customFactions = [
            'The Ghosts of Creuss',
            'The Emirates of Hacan',
            'The Yssaril Tribes',
        ];
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => $customFactions,
            'numberOfFactions' => 10,
        ]));

        $choices = $generator->handle();
        $choicesNames = array_map(fn (Faction $faction) => $faction->name, $choices);

        foreach($customFactions as $f) {
            $this->assertContains($f, $choicesNames);
        }

        foreach($choices as $c) {
            $this->assertEquals($c->edition, Edition::BASE_GAME);
        }
    }

    #[Test]
    public function minorFactionsPoolContainsTwoEligibleFactionsPerPlayer(): void
    {
        $choices = (new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 6,
            'numberOfFactions' => 12,
            'factionSets' => [Edition::BASE_GAME],
            'minorFactionsMode' => true,
            'seed' => 123,
        ])))->handle();

        $eligible = array_filter($choices, fn (Faction $faction) => $faction->minorFactionEligible);

        $this->assertCount(12, $choices);
        $this->assertCount(12, $eligible);
    }

    #[Test]
    public function minorFactionsPoolPreservesPinnedIneligibleFactionsWhenThereIsRoom(): void
    {
        $choices = (new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 6,
            'numberOfFactions' => 13,
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => ['The Ghosts of Creuss'],
            'minorFactionsMode' => true,
            'seed' => 456,
        ])))->handle();

        $names = array_map(fn (Faction $faction) => $faction->name, $choices);
        $eligible = array_filter($choices, fn (Faction $faction) => $faction->minorFactionEligible);

        $this->assertContains('The Ghosts of Creuss', $names);
        $this->assertCount(12, $eligible);
    }

    #[Test]
    public function minorFactionsPoolRejectsPinnedChoicesThatCrowdOutTheReserve(): void
    {
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 6,
            'numberOfFactions' => 12,
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => ['The Ghosts of Creuss'],
            'minorFactionsMode' => true,
        ]));

        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(
            InvalidDraftSettingsException::notEnoughFactionsForMinorFactions(12)->getMessage(),
        );

        $generator->handle();
    }

    #[Test]
    public function minorFactionsPoolRejectsEnabledSourcesWithTooFewEligibleFactions(): void
    {
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 6,
            'numberOfFactions' => 12,
            'factionSets' => [Edition::THUNDERS_EDGE],
            'minorFactionsMode' => true,
        ]));

        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(
            InvalidDraftSettingsException::notEnoughFactionsForMinorFactions(12)->getMessage(),
        );

        $generator->handle();
    }

    #[Test]
    public function minorFactionsPoolIsStableForTheSameSeed(): void
    {
        $settings = [
            'numberOfPlayers' => 6,
            'numberOfFactions' => 13,
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => ['The Ghosts of Creuss'],
            'minorFactionsMode' => true,
            'seed' => 789,
        ];

        $first = (new GenerateFactionPool(DraftSettingsFactory::make($settings)))->handle();
        $second = (new GenerateFactionPool(DraftSettingsFactory::make($settings)))->handle();

        $this->assertSame(
            array_map(fn (Faction $faction) => $faction->name, $first),
            array_map(fn (Faction $faction) => $faction->name, $second),
        );
    }

    #[Test]
    public function minorFactionsPoolAtTheExactMinimumRetainsEligiblePinnedChoicesWithoutDuplicates(): void
    {
        $pinned = [
            'The Barony of Letnev',
            'The Emirates of Hacan',
        ];
        $choices = (new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 6,
            'numberOfFactions' => 12,
            'factionSets' => [Edition::BASE_GAME],
            'customFactions' => $pinned,
            'minorFactionsMode' => true,
            'seed' => 2468,
        ])))->handle();

        $names = array_map(fn (Faction $faction) => $faction->name, $choices);

        $this->assertCount(12, $choices);
        $this->assertCount(12, array_unique($names));
        $this->assertEmpty(array_diff($pinned, $names));
        $this->assertCount(
            12,
            array_filter($choices, fn (Faction $faction) => $faction->minorFactionEligible),
        );
        foreach ($choices as $choice) {
            $this->assertSame(Edition::BASE_GAME, $choice->edition);
        }
    }

    #[Test]
    public function minorFactionsPoolContainsExactlyTheEnabledSourceWhenEveryFactionFits(): void
    {
        $choices = (new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 7,
            'factionSets' => [Edition::PROPHECY_OF_KINGS],
            'minorFactionsMode' => true,
            'seed' => 1357,
        ])))->handle();

        $expectedNames = array_map(
            fn (Faction $faction) => $faction->name,
            array_filter(
                Faction::all(),
                fn (Faction $faction) => $faction->edition === Edition::PROPHECY_OF_KINGS,
            ),
        );
        $actualNames = array_map(fn (Faction $faction) => $faction->name, $choices);

        sort($expectedNames);
        sort($actualNames);

        $this->assertSame($expectedNames, $actualNames);
    }

    #[Test]
    public function minorFactionsPoolDoesNotSupplementAnInsufficientEnabledSetFromDisabledSources(): void
    {
        $generator = new GenerateFactionPool(DraftSettingsFactory::make([
            'numberOfPlayers' => 3,
            'numberOfFactions' => 6,
            'factionSets' => [Edition::THUNDERS_EDGE],
            'minorFactionsMode' => true,
        ]));

        $this->expectException(InvalidDraftSettingsException::class);
        $this->expectExceptionMessage(
            InvalidDraftSettingsException::notEnoughFactionsForMinorFactions(6)->getMessage(),
        );

        $generator->handle();
    }
}
