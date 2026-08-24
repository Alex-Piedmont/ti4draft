<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Draft\Commands\GenerateDraft;
use App\Draft\MinorFaction;
use App\Draft\Slice;
use App\Http\HtmlResponse;
use App\Testing\Factories\DraftSettingsFactory;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

class HandleViewDraftAllianceAbilityAdversarialTest extends RequestHandlerTestCase
{
    use UsesTestDraft;

    protected string $requestHandlerClass = HandleViewDraftRequest::class;

    #[Test]
    public function eachRenderedRowUsesTheAbilityOfItsNamedFaction(): void
    {
        $this->replaceWithMinorFactionDraft();
        $first = $this->testDraft->slicePool[0]->minorFaction->faction;
        $second = $this->testDraft->slicePool[1]->minorFaction->faction;

        $body = HtmlResponse::renderTemplate('templates/draft.php', ['draft' => $this->testDraft]);

        $this->assertNotSame($first->name, $second->name);
        $this->assertRowContainsFactionAndAbility($body, 0, $first);
        $this->assertRowContainsFactionAndAbility($body, 1, $second);
    }

    #[Test]
    public function invalidUtf8AndMarkupAreRenderedAsInertSubstitutedText(): void
    {
        $this->replaceWithMinorFactionDraft();
        $slice = $this->testDraft->slicePool[0];
        $original = $slice->minorFaction->faction;
        $ability = "Invalid \xC3 markup <aside data-injected=\"yes\">Captain's & Ω</aside>";
        $faction = new Faction(
            $original->name,
            $original->id,
            $original->homeSystemTileNumber,
            $original->linkToWiki,
            $original->edition,
            $ability,
            true,
        );
        $this->assignMinorFaction(0, $faction);

        $body = HtmlResponse::renderTemplate('templates/draft.php', ['draft' => $this->testDraft]);
        $expected = htmlspecialchars($ability, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->assertStringContainsString($expected, $body);
        $this->assertStringContainsString("Invalid \u{FFFD} markup", $body);
        $this->assertStringNotContainsString('<aside data-injected="yes">', $body);
    }

    #[Test]
    public function discordantStarsAndOfficialRowsKeepTheirOwnAbilitiesAndAssetTokens(): void
    {
        $this->replaceWithMinorFactionDraft([
            Edition::BASE_GAME,
            Edition::PROPHECY_OF_KINGS,
            Edition::THUNDERS_EDGE,
            Edition::DISCORDANT_STARS,
        ], 123456);
        $discordantIndex = null;
        $officialIndex = null;
        foreach ($this->testDraft->slicePool as $index => $slice) {
            if ($slice->minorFaction->faction->edition === Edition::DISCORDANT_STARS) {
                $discordantIndex ??= $index;
            } else {
                $officialIndex ??= $index;
            }
        }
        $this->assertNotNull($discordantIndex, 'Fixed seed must yield a Discordant Stars Minor Faction');
        $this->assertNotNull($officialIndex, 'Fixed seed must yield an official-set Minor Faction');
        $discordant = $this->testDraft->slicePool[$discordantIndex]->minorFaction->faction;
        $official = $this->testDraft->slicePool[$officialIndex]->minorFaction->faction;

        $body = HtmlResponse::renderTemplate('templates/draft.php', ['draft' => $this->testDraft]);

        $this->assertRowContainsFactionAndAbility($body, $discordantIndex, $discordant);
        $this->assertRowContainsFactionAndAbility($body, $officialIndex, $official);
        $this->assertStringContainsString('/img/tiles/' . $discordant->homesystem() . '.png', $body);
        $this->assertStringNotContainsString('/img/tiles/ST_' . $discordant->homesystem() . '.png', $body);
        $this->assertStringContainsString('/img/tiles/ST_' . $official->homesystem() . '.png', $body);
    }

    #[Test]
    public function draftRendersFromLocalCatalogWhenHttpsAccessIsUnavailable(): void
    {
        $this->replaceWithMinorFactionDraft();
        $minorFaction = $this->testDraft->slicePool[0]->minorFaction;
        $this->assertNotNull($minorFaction);
        $this->assertTrue(stream_wrapper_unregister('https'));

        try {
            $body = HtmlResponse::renderTemplate('templates/draft.php', ['draft' => $this->testDraft]);
        } finally {
            stream_wrapper_restore('https');
        }

        $this->assertStringContainsString(
            htmlspecialchars($minorFaction->faction->allianceAbility, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $body,
        );
        $this->assertStringContainsString('minor-factions-attribution', $body);
    }

    private function assignMinorFaction(int $sliceIndex, Faction $faction): void
    {
        $minorFaction = MinorFaction::fromFaction($faction);
        $slice = $this->testDraft->slicePool[$sliceIndex];
        $tiles = $slice->tiles;
        $tiles[Slice::EQUIDISTANT_INDEX] = $minorFaction->homeSystem;
        $this->testDraft->slicePool[$sliceIndex] = new Slice($tiles, true, $minorFaction);
    }

    private function assertRowContainsFactionAndAbility(string $body, int $sliceIndex, Faction $faction): void
    {
        $pattern = sprintf(
            '/<tr data-slice="%d">\s*<td>Slice %d<\/td>\s*<td>%s<\/td>\s*<td>%s<\/td>\s*<\/tr>/',
            $sliceIndex,
            $sliceIndex + 1,
            preg_quote(htmlspecialchars($faction->name), '/'),
            preg_quote(htmlspecialchars($faction->allianceAbility, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/'),
        );

        $this->assertMatchesRegularExpression($pattern, $body);
    }

    /** @param Edition[]|null $factionSets */
    private function replaceWithMinorFactionDraft(?array $factionSets = null, ?int $seed = null): void
    {
        app()->repository->delete($this->testDraft->id);
        $this->testDraft = (new GenerateDraft(DraftSettingsFactory::make([
            'numberOfPlayers' => 6,
            'minorFactionsMode' => true,
            'tileSets' => [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'factionSets' => $factionSets ?? [Edition::BASE_GAME, Edition::PROPHECY_OF_KINGS, Edition::THUNDERS_EDGE],
            'seed' => $seed,
            'minimumTwoAlphaBetaWormholes' => false,
            'minimumLegendaryPlanets' => 0,
            'maxOneWormholePerSlice' => false,
            'minimumOptimalInfluence' => 0,
            'minimumOptimalResources' => 0,
            'minimumOptimalTotal' => 0,
            'maximumOptimalTotal' => 100,
        ])))->handle();
        app()->repository->save($this->testDraft);
    }
}
