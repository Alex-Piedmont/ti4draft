<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\GenerateDraft;
use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Http\HttpRequest;
use App\Testing\FakesCommands;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
use App\TwilightImperium\AllianceTeamMode;
use App\TwilightImperium\AllianceTeamPosition;
use App\TwilightImperium\Edition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class HandleGenerateDraftRequestTest extends RequestHandlerTestCase
{
    use FakesCommands;
    use UsesTestDraft;

    protected string $requestHandlerClass = HandleGenerateDraftRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/api/generate');
    }

    #[Test]
    public function itReturnsErrorWhenSettingsAreInvalid(): void
    {
        $response = $this->handleRequest([], [
            'seed' => -1,
        ]);

        $this->assertSame($response->code, 400);
        $this->assertJsonResponseSame(['error' => InvalidDraftSettingsException::invalidSeed()->getMessage()], $response);
    }

    public static function settingsPayload()
    {
        yield 'Player Names' => [
            'postData' => [
                'num_players' => 4,
                'player' => [
                    'John', 'Paul', 'George', 'Ringo',
                ],
            ],
            'field' => 'playerNames',
            'expected' => ['John', 'Paul', 'George', 'Ringo'],
            'expectedWhenNotSet' => [],
        ];
        yield 'Player Names containing empties' => [
            'postData' => [
                'num_players' => 6,
                'player' => [
                    'John', 'Paul', 'George', 'Ringo', '', '',
                ],
            ],
            'field' => 'playerNames',
            'expected' => ['John', 'Paul', 'George', 'Ringo', '', ''],
            'expectedWhenNotSet' => [],
        ];
        yield 'Alliance Mode' => [
            'postData' => [
                'alliance_on' => true,
                'alliance_teams' => AllianceTeamMode::RANDOM->value,
                'alliance_teams_position' => AllianceTeamPosition::NONE->value,
            ],
            'field' => 'allianceMode',
            'expected' => true,
            'expectedWhenNotSet' => false,
        ];
        yield 'Minor Factions Mode' => [
            'postData' => [
                'minor_factions_on' => 'on',
            ],
            'field' => 'minorFactionsMode',
            'expected' => true,
            'expectedWhenNotSet' => false,
        ];
        yield 'Custom Slices' => [
            'postData' => [
                'custom_slices' => "01,2,03,4,5\n6,7,8,9,10\n11,012,13,014,15A",
            ],
            'field' => 'customSlices',
            'expected' => [
               ['1', '2', '3', '4', '5'],
               ['6', '7', '8', '9', '10'],
               ['11', '12', '13', '14', '15A'],
            ],
            'expectedWhenNotSet' => [],
        ];
        yield 'Preset Draft Order' => [
            'postData' => [
                'preset_draft_order' => 'on',
            ],
            'field' => 'presetDraftOrder',
            'expected' => true,
            'expectedWhenNotSet' => false,
        ];
        yield 'Number of slices' => [
            'postData' => [
                'num_slices' => '8',
            ],
            'field' => 'numberOfSlices',
            'expected' => 8,
            'expectedWhenNotSet' => 0,
        ];
        yield 'Number of factions' => [
            'postData' => [
                'num_factions' => '7',
            ],
            'field' => 'numberOfFactions',
            'expected' => 7,
            'expectedWhenNotSet' => 0,
        ];
        yield 'Tile sets (official)' => [
            'postData' => [
                'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            ],
            'field' => 'tileSets',
            'expected' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'expectedWhenNotSet' => [Edition::BASE_GAME],
        ];
        yield 'Tile sets (everything)' => [
            'postData' => [
                'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on', 'DSPlus' => 'on'],
            ],
            'field' => 'tileSets',
            'expected' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE, Edition::DISCORDANT_STARS_PLUS],
            'expectedWhenNotSet' => [Edition::BASE_GAME],
        ];
        yield 'Faction sets (base only)' => [
            'postData' => [
                'factionSets' => ['BaseGame' => 'on'],
            ],
            'field' => 'factionSets',
            'expected' => [Edition::BASE_GAME],
            'expectedWhenNotSet' => [],
        ];
        yield 'Faction sets (official)' => [
            'postData' => [
                'factionSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            ],
            'field' => 'factionSets',
            'expected' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'expectedWhenNotSet' => [],
        ];
        yield 'Faction sets (all)' => [
            'postData' => [
                'factionSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on', 'DS' => 'on', 'DSPlus' => 'on'],
            ],
            'field' => 'factionSets',
            'expected' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE, Edition::DISCORDANT_STARS, Edition::DISCORDANT_STARS_PLUS],
            'expectedWhenNotSet' => [],
        ];
        yield 'Council Keleres' => [
            'postData' => [
                'include_keleres' => 'on',
            ],
            'field' => 'includeCouncilKeleresFaction',
            'expected' => true,
            'expectedWhenNotSet' => false,
        ];
        yield 'Minimum legendary planets' => [
            'postData' => [
                'min_legendaries' => '1',
            ],
            'field' => 'minimumLegendaryPlanets',
            'expected' => 1,
            'expectedWhenNotSet' => 0,
        ];
        yield 'Minimum 2 wormholes' => [
            'postData' => [
                'wormholes' => 'on',
            ],
            'field' => 'minimumTwoAlphaAndBetaWormholes',
            'expected' => true,
            'expectedWhenNotSet' => false,
        ];
        yield 'Minimum optimal Influence' => [
            'postData' => [
                'min_inf' => '4.5',
            ],
            'field' => 'minimumOptimalInfluence',
            'expected' => 4.5,
            'expectedWhenNotSet' => 0.0,
        ];
        yield 'Minimum optimal Resources' => [
            'postData' => [
                'min_res' => '3',
            ],
            'field' => 'minimumOptimalResources',
            'expected' => 3.0,
            'expectedWhenNotSet' => 0.0,
        ];
        yield 'Minimum optimal total' => [
            'postData' => [
                'min_total' => '7.3',
            ],
            'field' => 'minimumOptimalTotal',
            'expected' => 7.3,
            'expectedWhenNotSet' => 0.0,
        ];
        yield 'Maximum optimal total' => [
            'postData' => [
                'min_total' => '13',
            ],
            'field' => 'minimumOptimalTotal',
            'expected' => 13.0,
            'expectedWhenNotSet' => 0.0,
        ];
        yield 'Custom Factions' => [
            'postData' => [
                'custom_factions' => ['Xxcha', 'Keleres'],
            ],
            'field' => 'customFactions',
            'expected' => ['Xxcha', 'Keleres'],
            'expectedWhenNotSet' => [],
        ];
        yield 'Alliance Team Mode' => [
            'postData' => [
                'alliance_on' => true,
                'alliance_teams' => 'random',
                'alliance_teams_position' => 'neighbors',
            ],
            'field' => 'allianceTeamMode',
            'expected' => AllianceTeamMode::RANDOM,
            'expectedWhenNotSet' => null,
        ];
        yield 'Alliance Team Position' => [
            'postData' => [
                'alliance_on' => true,
                'alliance_teams' => 'random',
                'alliance_teams_position' => 'neighbors',
            ],
            'field' => 'allianceTeamPosition',
            'expected' => AllianceTeamPosition::NEIGHBORS,
            'expectedWhenNotSet' => null,
        ];
        yield 'Alliance Force double picks' => [
            'postData' => [
                'alliance_on' => true,
                'force_double_picks' => 'on',
                'alliance_teams' => 'random',
                'alliance_teams_position' => 'neighbors',
            ],
            'field' => 'allianceForceDoublePicks',
            'expected' => true,
            'expectedWhenNotSet' => null,
        ];
    }

    #[Test]
    #[DataProvider('settingsPayload')]
    public function itParsesSettingsFromRequest($postData, $field, $expected, $expectedWhenNotSet): void
    {
        $handler = new HandleGenerateDraftRequest(new HttpRequest([], $postData, []));

        $this->assertSame($expected, $handler->settingValue($field));
    }

    #[Test]
    #[DataProvider('settingsPayload')]
    public function itParsesSettingsFromRequestWhenNotSet($postData, $field, $expected, $expectedWhenNotSet): void
    {
        $handler = new HandleGenerateDraftRequest(new HttpRequest([], [], []));
        $this->assertSame($expectedWhenNotSet, $handler->settingValue($field));
    }

    #[Test]
    public function itGeneratesADraft(): void
    {
        $this->setExpectedReturnValue($this->testDraft);

        $response = $this->handleRequest([
            'num_players' => 4,
            'player' => ['John', 'Paul', 'George', 'Ringo'],
            'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'factionSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'num_slices' => 4,
            'num_factions' => 4,
        ]);;

        $this->assertCommandWasDispatched(GenerateDraft::class);

        $this->assertResponseOk($response);
        $this->assertResponseJson($response);
    }

    #[Test]
    public function itAcceptsOneDraftableFactionPerPlayerInMinorMode(): void
    {
        $this->setExpectedReturnValue($this->testDraft);
        $response = $this->handleRequest([], [
            'num_players' => 6,
            'player' => ['Amy', 'Ben', 'Charlie', 'Desmond', 'Esther', 'Frank'],
            'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'factionSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'num_slices' => 7,
            'num_factions' => 6,
            'minor_factions_on' => 'on',
            'min_legendaries' => 0,
            'min_inf' => 0,
            'min_res' => 0,
            'min_total' => 0,
            'max_total' => 20,
        ]);

        $this->assertResponseOk($response);
        $this->assertCommandWasDispatched(GenerateDraft::class);
        $this->assertSame(6, $this->settingsFromDispatchedGenerateDraft()->numberOfFactions);
    }

    #[Test]
    public function itAcceptsTheExactMinorFactionBoundaryAndDispatchesEnabledSettings(): void
    {
        $this->setExpectedReturnValue($this->testDraft);

        $response = $this->handleRequest([], [
            'num_players' => 6,
            'player' => ['Amy', 'Ben', 'Charlie', 'Desmond', 'Esther', 'Frank'],
            'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'factionSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'num_slices' => 7,
            'num_factions' => 6,
            'minor_factions_on' => 'on',
            'max_total' => 20,
        ]);

        $this->assertResponseOk($response);
        $settings = $this->settingsFromDispatchedGenerateDraft();
        $this->assertTrue($settings->minorFactionsMode);
        $this->assertSame(6, $settings->numberOfFactions);
    }

    #[Test]
    public function itDispatchesOrdinaryDraftSettingsWithMinorFactionsDisabled(): void
    {
        $this->setExpectedReturnValue($this->testDraft);

        $response = $this->handleRequest([], [
            'num_players' => 3,
            'player' => ['Amy', 'Ben', 'Charlie'],
            'tileSets' => ['BaseGame' => 'on'],
            'factionSets' => ['BaseGame' => 'on'],
            'num_slices' => 3,
            'num_factions' => 3,
            'max_total' => 20,
        ]);

        $this->assertResponseOk($response);
        $this->assertFalse($this->settingsFromDispatchedGenerateDraft()->minorFactionsMode);
    }

    #[Test]
    public function itValidatesAndDispatchesTheSameSingleParsedSettingsInstance(): void
    {
        $post = [
            'num_players' => 3,
            'player' => ['Amy', 'Ben', 'Charlie'],
            'tileSets' => ['BaseGame' => 'on'],
            'factionSets' => ['BaseGame' => 'on'],
            'num_slices' => 3,
            'num_factions' => 3,
            'max_total' => 20,
        ];
        $handler = new HandleGenerateDraftRequest(new HttpRequest([], $post, []));
        $property = new \ReflectionProperty(HandleGenerateDraftRequest::class, 'settings');
        $parsed = $property->getValue($handler);
        $this->setExpectedReturnValue($this->testDraft);

        $response = $handler->handle();

        $this->assertResponseOk($response);
        $this->assertSame($parsed, $this->settingsFromDispatchedGenerateDraft());
    }

    #[Test]
    public function generationDomainFailuresReturn400AndSaveNothing(): void
    {
        app()->dontSpyOnDispatcher();
        $before = glob(env('STORAGE_PATH') . '/draft_*.json') ?: [];
        $response = $this->handleRequest([], [
            'num_players' => 3,
            'player' => ['Amy', 'Ben', 'Charlie'],
            'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on'],
            'factionSets' => ['PoK' => 'on'],
            'num_slices' => 4,
            'num_factions' => 4,
            'minor_factions_on' => 'on',
            'min_legendaries' => 0,
            'min_inf' => 0,
            'min_res' => 0,
            'min_total' => 0,
            'max_total' => 100,
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString('catalog shortage', $response->getBody());
        $this->assertSame($before, glob(env('STORAGE_PATH') . '/draft_*.json') ?: []);
    }

    #[Test]
    public function unexpectedGenerationFailuresAreNotConvertedToValidationErrors(): void
    {
        $this->setExpectedReturnValue(new \stdClass());
        $this->expectException(\TypeError::class);
        $this->handleRequest([], [
            'num_players' => 3,
            'player' => ['Amy', 'Ben', 'Charlie'],
            'tileSets' => ['BaseGame' => 'on'],
            'factionSets' => ['BaseGame' => 'on'],
            'num_slices' => 3,
            'num_factions' => 3,
            'max_total' => 20,
        ]);
    }

    private function settingsFromDispatchedGenerateDraft(): \App\Draft\Settings
    {
        $commands = array_values(array_filter(
            app()->spy->dispatchedCommands,
            fn ($command) => $command instanceof GenerateDraft,
        ));
        $this->assertCount(1, $commands);

        $property = new \ReflectionProperty(GenerateDraft::class, 'settings');

        return $property->getValue($commands[0]);
    }
}
