<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\GenerateDraft;
use App\Draft\Settings;
use App\Http\HttpRequest;
use App\Testing\FakesCommands;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class HandleGenerateDraftRequestAdversarialTest extends RequestHandlerTestCase
{
    use FakesCommands;
    use UsesTestDraft;

    protected string $requestHandlerClass = HandleGenerateDraftRequest::class;

    public static function mismatchedCustomSliceRows(): iterable
    {
        yield 'one row too few' => [
            "64,33,42,59,67\n65,34,43,60,68",
        ];
        yield 'one row too many' => [
            "64,33,42,59,67\n65,34,43,60,68\n66,35,44,61,69\n62,36,45,63,70",
        ];
    }

    #[Test]
    #[DataProvider('mismatchedCustomSliceRows')]
    public function mismatchedMinorCustomSliceRowsReturn400BeforeDispatch(string $customSlices): void
    {
        $response = $this->handleRequest([], $this->minorRequest([
            'custom_slices' => $customSlices,
        ]));

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString('Custom slices error', $response->getBody());
        $this->assertCommandWasDispatched(GenerateDraft::class, 0);
    }

    #[Test]
    public function aTrailingBlankLineDoesNotCreateAnAdditionalCustomSlice(): void
    {
        $this->setExpectedReturnValue($this->testDraft);

        $response = $this->handleRequest([], $this->minorRequest([
            'custom_slices' => "64,33,42,59,67\n65,34,43,60,68\n66,35,44,61,69\n",
        ]));

        $this->assertResponseOk($response);
        $this->assertCommandWasDispatched(GenerateDraft::class);
    }

    #[Test]
    public function ordinaryConstraintValuesReachTheSingleDispatchedSettingsObjectUnchanged(): void
    {
        $this->setExpectedReturnValue($this->testDraft);
        $handler = new HandleGenerateDraftRequest(new HttpRequest([], $this->minorRequest(), []));
        $settingsProperty = new \ReflectionProperty(HandleGenerateDraftRequest::class, 'settings');
        $parsed = $settingsProperty->getValue($handler);

        $response = $handler->handle();
        $dispatched = $this->dispatchedSettings();

        $this->assertResponseOk($response);
        $this->assertSame($parsed, $dispatched);
        $this->assertSame(4.0, $dispatched->minimumOptimalInfluence);
        $this->assertSame(2.5, $dispatched->minimumOptimalResources);
        $this->assertSame(9.0, $dispatched->minimumOptimalTotal);
        $this->assertSame(13.0, $dispatched->maximumOptimalTotal);
        $this->assertNotNull($dispatched->seed->getValue());
        $this->assertSame($parsed->seed->getValue(), $dispatched->seed->getValue());
    }

    #[Test]
    public function tileCapacityFailureReturns400BeforeGenerationDispatch(): void
    {
        $response = $this->handleRequest([], $this->minorRequest([
            'num_slices' => 7,
            'num_factions' => 10,
            'tileSets' => ['BaseGame' => 'on'],
        ]));

        $this->assertResponseCode(400, $response);
        $this->assertStringContainsString('only supports 6 slices', $response->getBody());
        $this->assertCommandWasDispatched(GenerateDraft::class, 0);
    }

    #[Test]
    public function untouchedDefaultMinorRequestRunsTheRealGeneratorAndPersistsTheSingleSeed(): void
    {
        app()->dontSpyOnDispatcher();
        $handler = new HandleGenerateDraftRequest(new HttpRequest([], $this->minorRequest([
            'num_factions' => 3,
        ]), []));
        $settingsProperty = new \ReflectionProperty(HandleGenerateDraftRequest::class, 'settings');
        $parsed = $settingsProperty->getValue($handler);
        $draftId = null;

        try {
            $response = $handler->handle();
            $this->assertResponseOk($response);
            $payload = json_decode($response->getBody(), true);
            $draftId = $payload['id'];
            $saved = app()->repository->load($draftId);

            $this->assertSame($parsed->seed->getValue(), $saved->settings->seed->getValue());
            $this->assertCount(3, $saved->factionPool);
            $this->assertCount(3, $saved->slicePool);
            foreach ($saved->slicePool as $slice) {
                $this->assertNotNull($slice->minorFaction);
            }
        } finally {
            if ($draftId !== null) {
                app()->repository->delete($draftId);
            }
        }
    }

    private function minorRequest(array $overrides = []): array
    {
        return array_replace([
            'num_players' => 3,
            'player' => ['Amy', 'Ben', 'Charlie'],
            'tileSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'factionSets' => ['BaseGame' => 'on', 'PoK' => 'on', 'TE' => 'on'],
            'num_slices' => 3,
            'num_factions' => 6,
            'minor_factions_on' => 'on',
            'min_legendaries' => 0,
            'min_inf' => 4,
            'min_res' => 2.5,
            'min_total' => 9,
            'max_total' => 13,
        ], $overrides);
    }

    private function dispatchedSettings(): Settings
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
