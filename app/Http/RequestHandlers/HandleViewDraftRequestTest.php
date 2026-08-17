<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\GenerateDraft;
use App\Draft\MinorFaction;
use App\Draft\Slice;
use App\Http\HtmlResponse;
use App\Http\HttpRequest;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

class HandleViewDraftRequestTest extends RequestHandlerTestCase
{
    use UsesTestDraft;

    protected string $requestHandlerClass = HandleViewDraftRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/d/123');
    }

    #[Test]
    public function itCanFetchDraft(): void
    {
        $handler = new HandleViewDraftRequest(new HttpRequest([], ['id' => $this->testDraft->id], []));

        $response = $handler->handle();

        $this->assertSame(200, $response->code);
        $this->assertNotSame(HtmlResponse::CONTENT_TYPE, $response->code);
    }

    #[Test]
    public function itShowsAnErrorPageWhenDraftIsNotFound(): void
    {
        $handler = new HandleViewDraftRequest(new HttpRequest([], ['id' => '123'], []));
        $response = $handler->handle();

        $this->assertSame(404, $response->code);
        $this->assertNotSame(HtmlResponse::CONTENT_TYPE, $response->code);
    }

    #[Test]
    public function itRendersFaceUpMinorFactionsAndTheirHomeSystemsBeforeAnyPicks(): void
    {
        $this->replaceWithMinorFactionDraft();

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringContainsString('id="minor-factions"', $body);
        $this->assertStringContainsString('data-status="assigned"', $body);
        $this->assertSame(count($this->testDraft->slicePool), substr_count($body, '<tr data-slice="'));
        foreach ($this->testDraft->slicePool as $slice) {
            $minor = $slice->minorFaction;
            $this->assertStringContainsString($minor->faction->name, $body);
            $tileAsset = str_starts_with($minor->faction->homesystem(), 'DS_')
                ? $minor->faction->homesystem()
                : 'ST_' . $minor->faction->homesystem();
            $this->assertStringContainsString($tileAsset . '.png', $body);
            $this->assertStringContainsString(htmlspecialchars($minor->faction->name . ' Minor Faction'), $body);
        }
        $this->assertStringNotContainsString('Reserved for a Minor Faction', $body);
        $this->assertStringNotContainsString('Assignments appear after', $body);
    }

    #[Test]
    public function renderedSliceTotalsIncludeTheMinorFactionHomeSystem(): void
    {
        $this->replaceWithMinorFactionDraft();
        $slice = $this->testDraft->slicePool[0];

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringContainsString('class="resources">' . $slice->totalResources . '</abbr>', $body);
        $this->assertStringContainsString('class="influence">' . $slice->totalInfluence . '</abbr>', $body);
    }

    #[Test]
    public function itUsesDiscordantStarsRenderTokensForMinorFactionTileAssets(): void
    {
        $this->replaceWithMinorFactionDraft([Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE, Edition::DISCORDANT_STARS]);
        $usedNames = array_merge(
            array_map(static fn (Faction $faction): string => $faction->name, $this->testDraft->factionPool),
            array_map(static fn (Slice $slice): string => $slice->minorFaction->faction->name, array_slice($this->testDraft->slicePool, 1)),
        );
        $discordant = array_values(array_filter(
            Faction::all(),
            static fn (Faction $faction): bool => $faction->minorFactionEligible
                && str_starts_with($faction->homesystem(), 'DS_')
                && ! in_array($faction->name, $usedNames, true),
        ))[0];
        $minor = MinorFaction::fromFaction($discordant);
        $tiles = $this->testDraft->slicePool[0]->tiles;
        $tiles[Slice::EQUIDISTANT_INDEX] = $minor->homeSystem;
        $this->testDraft->slicePool[0] = new Slice($tiles, true, $minor);
        app()->repository->save($this->testDraft);

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringContainsString('/' . $discordant->homesystem() . '.png', $body);
        $this->assertStringNotContainsString('ST_' . $discordant->homesystem() . '.png', $body);
        $this->assertStringContainsString($discordant->name, $body);
    }

    #[Test]
    public function disabledDraftDoesNotPublishOrRenderMinorFactionState(): void
    {
        $payload = $this->testDraft->toArray(false);
        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertArrayNotHasKey('minor_factions', $payload);
        foreach ($payload['slices'] as $slice) {
            $this->assertArrayNotHasKey('minor_faction', $slice);
            $this->assertArrayNotHasKey('equidistant', $slice);
        }
        $this->assertStringNotContainsString('id="minor-factions"', $body);
        $this->assertStringNotContainsString('minor-faction-home', $body);
        $this->assertStringContainsString('<label>Minor Factions:</label> <strong>no</strong>', $body);
    }

    /** @param Edition[]|null $factionSets */
    private function replaceWithMinorFactionDraft(?array $factionSets = null): void
    {
        app()->repository->delete($this->testDraft->id);
        $this->testDraft = (new GenerateDraft(DraftSettingsFactory::make([
            'num_players' => 6,
            'minorFactionsMode' => true,
            'tileSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'factionSets' => $factionSets ?? [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'minimumTwoAlphaBetaWormholes' => false,
            'minimumLegendaryPlanets' => 0,
            'maxOneWormholePerSlice' => true,
        ])))->handle();
        app()->repository->save($this->testDraft);
    }
}
