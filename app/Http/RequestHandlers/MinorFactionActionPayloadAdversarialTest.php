<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\GenerateDraft;
use App\Draft\Draft;
use App\Draft\PickCategory;
use App\Http\HttpRequest;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\RequestHandlerTestCase;
use App\TwilightImperium\Edition;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;

final class MinorFactionActionPayloadAdversarialTest extends RequestHandlerTestCase
{
    private ?Draft $minorDraft = null;

    #[After]
    public function deleteMinorDraft(): void
    {
        if ($this->minorDraft !== null) {
            app()->repository->delete($this->minorDraft->id);
        }
    }

    #[Test]
    public function pickPollAndUndoResponsesPreserveEveryAssignmentPairingAndTotal(): void
    {
        $draft = $this->createMinorDraft();
        $publicPayload = json_decode(json_encode($draft->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $before = $this->authoritativeSliceState($publicPayload['slices']);
        $player = $draft->currentPlayerId;
        $faction = $draft->factionPool[0];

        $pickResponse = (new HandlePickRequest(new HttpRequest([
            'id' => $draft->id,
            'index' => 0,
            'player' => $player->value,
            'admin' => $draft->secrets->adminSecret,
            'category' => PickCategory::FACTION->value,
            'value' => $faction->name,
        ], [], [])))->handle();
        $this->assertResponseOk($pickResponse);
        $afterPick = json_decode($pickResponse->getBody(), true)['draft']['slices'];

        $pollResponse = (new HandleGetDraftRequest(new HttpRequest([
            'id' => $draft->id,
        ], [], [])))->handle();
        $this->assertResponseOk($pollResponse);
        $afterPoll = json_decode($pollResponse->getBody(), true)['slices'];

        $undoResponse = (new HandleUndoRequest(new HttpRequest([
            'id' => $draft->id,
            'admin' => $draft->secrets->adminSecret,
        ], [], [])))->handle();
        $this->assertResponseOk($undoResponse);
        $afterUndo = json_decode($undoResponse->getBody(), true)['draft']['slices'];

        $this->assertSame($before, $this->authoritativeSliceState($afterPick));
        $this->assertSame($before, $this->authoritativeSliceState($afterPoll));
        $this->assertSame($before, $this->authoritativeSliceState($afterUndo));
    }

    private function createMinorDraft(): Draft
    {
        $this->minorDraft = (new GenerateDraft(DraftSettingsFactory::make([
            'playerNames' => ['Amy', 'Ben', 'Charlie'],
            'numberOfSlices' => 4,
            'numberOfFactions' => 4,
            'minorFactionsMode' => true,
            'seed' => 818181,
            'tileSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'factionSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'minimumTwoAlphaBetaWormholes' => false,
            'minimumLegendaryPlanets' => 0,
            'maxOneWormholePerSlice' => false,
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
        ])))->handle();
        app()->repository->save($this->minorDraft);

        return $this->minorDraft;
    }

    private function authoritativeSliceState(array $slices): array
    {
        return array_map(static fn (array $slice): array => [
            'minor_faction' => $slice['minor_faction'],
            'equidistant' => $slice['equidistant'],
            'tiles' => $slice['tiles'],
            'total_resources' => $slice['total_resources'],
            'total_influence' => $slice['total_influence'],
            'optimal_resources' => $slice['optimal_resources'],
            'optimal_influence' => $slice['optimal_influence'],
        ], $slices);
    }
}
