<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Player;
use App\Http\HtmlResponse;
use App\Http\HttpRequest;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
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
    public function itRendersPendingMinorFactionGuidanceAndReservedSlicePlaceholders(): void
    {
        $this->testDraft->settings->minorFactionsMode = true;
        app()->repository->save($this->testDraft);

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringContainsString('id="minor-factions"', $body);
        $this->assertStringContainsString('data-status="pending"', $body);
        $this->assertStringContainsString('Assignments appear after every faction and speaker position', $body);
        $this->assertStringContainsString('Reserved for a Minor Faction', $body);
        $this->assertStringContainsString('<label>Minor Factions:</label> <strong>yes</strong>', $body);
    }

    #[Test]
    public function itRendersResolvedMinorFactionAssignments(): void
    {
        $leftovers = $this->completeMinorFactionPicks(false);

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringContainsString('data-status="resolved"', $body);
        $this->assertStringContainsString('minor-factions-assignments', $body);
        $this->assertStringContainsString($leftovers[0]->name, $body);
        $this->assertStringContainsString($leftovers[0]->homesystem(), $body);
    }

    #[Test]
    public function itRendersInvalidMinorFactionResolutionWithoutPartialRows(): void
    {
        $this->completeMinorFactionPicks(true);

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringContainsString('data-status="invalid"', $body);
        $this->assertStringContainsString('insufficient_eligible_candidates', $body);
        $this->assertStringNotContainsString('minor-factions-assignments', $body);
    }

    #[Test]
    public function disabledDraftDoesNotRenderMinorFactionPresentation(): void
    {
        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertStringNotContainsString('id="minor-factions"', $body);
        $this->assertStringNotContainsString('Reserved for a Minor Faction', $body);
        $this->assertStringContainsString('<label>Minor Factions:</label> <strong>no</strong>', $body);
    }

    #[Test]
    public function itExplainsTheEligibleReserveRequirementInTheEnabledDraftView(): void
    {
        $this->testDraft->settings->minorFactionsMode = true;
        app()->repository->save($this->testDraft);

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertMatchesRegularExpression('/twice (?:the )?player count/i', $body);
    }

    #[Test]
    public function itRendersEveryAssignmentInSpeakerOrderIncludingDiscordantStarsHomeTokens(): void
    {
        $eligible = array_values(array_filter(
            Faction::all(),
            static fn (Faction $faction): bool => $faction->minorFactionEligible,
        ));
        $discordant = array_values(array_filter(
            $eligible,
            static fn (Faction $faction): bool => str_starts_with($faction->homesystem(), 'DS_'),
        ))[0];
        $playerCount = count($this->testDraft->players);
        $selected = array_slice(array_values(array_filter(
            $eligible,
            static fn (Faction $faction): bool => $faction !== $discordant,
        )), 0, $playerCount);
        $leftovers = [$discordant];
        foreach ($eligible as $faction) {
            if ($faction === $discordant || in_array($faction, $selected, true)) {
                continue;
            }
            $leftovers[] = $faction;
            if (count($leftovers) === $playerCount) {
                break;
            }
        }

        $this->testDraft->settings->minorFactionsMode = true;
        $this->testDraft->factionPool = array_merge($selected, $leftovers);
        $updatedPlayers = [];
        foreach (array_values($this->testDraft->players) as $position => $player) {
            $updatedPlayers[$player->id->value] = new Player(
                $player->id,
                $player->name,
                $player->claimed,
                (string) $position,
                $selected[$position]->name,
                $player->pickedSlice,
                $player->team,
            );
        }
        $this->testDraft->players = array_reverse($updatedPlayers, true);
        app()->repository->save($this->testDraft);

        $body = $this->handleRequest(['id' => $this->testDraft->id])->getBody();

        $this->assertSame($playerCount, substr_count($body, '<tr data-position="'));
        $this->assertStringContainsString($discordant->homesystem(), $body);
        $previousPositionOffset = -1;
        foreach (array_keys($leftovers) as $position) {
            $positionOffset = strpos($body, '<tr data-position="' . $position . '">');
            $this->assertNotFalse($positionOffset);
            $this->assertGreaterThan($previousPositionOffset, $positionOffset);
            $previousPositionOffset = $positionOffset;
            $this->assertStringContainsString($leftovers[$position]->name, $body);
            $this->assertStringContainsString($leftovers[$position]->homesystem(), $body);
        }
    }

    /** @return array<Faction> */
    private function completeMinorFactionPicks(bool $makeInvalid): array
    {
        $eligible = array_values(array_filter(
            Faction::all(),
            static fn (Faction $faction): bool => $faction->minorFactionEligible,
        ));
        $playerCount = count($this->testDraft->players);
        $selected = array_slice($eligible, 0, $playerCount);
        $leftovers = array_slice($eligible, $playerCount, $playerCount);
        $this->testDraft->settings->minorFactionsMode = true;
        $this->testDraft->factionPool = $makeInvalid ? $selected : array_merge($selected, $leftovers);

        foreach (array_values($this->testDraft->players) as $position => $player) {
            $this->testDraft->players[$player->id->value] = new Player(
                $player->id,
                $player->name,
                $player->claimed,
                (string) $position,
                $selected[$position]->name,
                $player->pickedSlice,
                $player->team,
            );
        }

        app()->repository->save($this->testDraft);

        return $leftovers;
    }

}
