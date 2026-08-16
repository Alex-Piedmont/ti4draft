<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\PickCategory;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

class HandlePickRequestAdversarialTest extends RequestHandlerTestCase
{
    use UsesTestDraft;

    protected string $requestHandlerClass = HandlePickRequest::class;

    #[Test]
    public function itAcceptsAnExactInPoolFactionThroughTheRealRequestPath(): void
    {
        $playerId = $this->testDraft->currentPlayerId;
        $factionName = $this->testDraft->factionPool[0]->name;

        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'index' => 0,
            'player' => $playerId->value,
            'admin' => $this->testDraft->secrets->adminSecret,
            'category' => PickCategory::FACTION->value,
            'value' => $factionName,
        ]);

        $this->assertResponseCode(200, $response);
        $this->assertResponseJson($response);
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['success']);

        $this->reloadDraft();
        $this->assertSame($factionName, $this->testDraft->playerById($playerId)->pickedFaction);
        $this->assertSame($factionName, $this->testDraft->log[0]->pickedOption);
    }

    #[Test]
    public function itRejectsAGloballyKnownFactionOutsideThePoolWithTheExistingErrorContract(): void
    {
        $poolNames = array_map(static fn (Faction $faction): string => $faction->name, $this->testDraft->factionPool);
        $unavailableFaction = current(array_filter(
            Faction::all(),
            static fn (Faction $faction): bool => ! in_array($faction->name, $poolNames, true),
        ));
        $this->assertInstanceOf(Faction::class, $unavailableFaction, 'The fixture must leave a catalog faction outside the pool');

        $playerId = $this->testDraft->currentPlayerId;
        $before = $this->testDraft->toArray(true);
        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'index' => 0,
            'player' => $playerId->value,
            'admin' => $this->testDraft->secrets->adminSecret,
            'category' => PickCategory::FACTION->value,
            'value' => $unavailableFaction->name,
        ]);

        $this->assertResponseCode(400, $response);
        $this->assertResponseJson($response);
        $this->assertJsonResponseSame([
            'error' => "Faction is not available in this draft: {$unavailableFaction->name}",
        ], $response);

        $this->reloadDraft();
        $this->assertSame($before, $this->testDraft->toArray(true));
    }

    #[Test]
    public function itKeepsTheRealRequestPathForNonFactionPicksUnchanged(): void
    {
        $playerId = $this->testDraft->currentPlayerId;

        $response = $this->handleRequest([
            'id' => $this->testDraft->id,
            'index' => 0,
            'player' => $playerId->value,
            'admin' => $this->testDraft->secrets->adminSecret,
            'category' => PickCategory::SLICE->value,
            'value' => '1',
        ]);

        $this->assertResponseCode(200, $response);
        $this->assertResponseJson($response);
        $this->reloadDraft();
        $this->assertSame('1', $this->testDraft->playerById($playerId)->pickedSlice);
    }
}
