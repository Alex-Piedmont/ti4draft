<?php

declare(strict_types=1);

namespace App\TwilightImperium;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FactionAllianceAbilityAdversarialTest extends TestCase
{
    #[Test]
    public function hydrationPreservesWhitespaceUnicodeAndHtmlSignificantCharactersExactly(): void
    {
        $data = json_decode(file_get_contents('data/factions.json'), true)['The Arborec'];
        $ability = "  At any time: Alex's \"unit\" gains <Production> & Ω.  ";
        $data['alliance_ability'] = $ability;

        $faction = Faction::fromJson($data);

        $this->assertSame($ability, $faction->allianceAbility);
    }

    #[Test]
    public function allianceAbilityCannotBeMutatedAfterHydration(): void
    {
        $faction = Faction::all()['The Arborec'];

        $this->expectException(\Error::class);

        $faction->allianceAbility = 'Replacement text';
    }

    #[Test]
    public function ineligibleFactionRetainsItsFullIdentityAndAllianceMetadata(): void
    {
        $data = json_decode(file_get_contents('data/factions.json'), true)['The Ghosts of Creuss'];

        $faction = Faction::fromJson($data);

        $this->assertSame($data['name'], $faction->name);
        $this->assertSame($data['id'], $faction->id);
        $this->assertSame($data['homesystem'], $faction->homeSystemTileNumber);
        $this->assertSame($data['wiki'], $faction->linkToWiki);
        $this->assertSame(Edition::BASE_GAME, $faction->edition);
        $this->assertSame($data['alliance_ability'], $faction->allianceAbility);
        $this->assertFalse($faction->minorFactionEligible);
        $this->assertSame($data['homesystem'], $faction->homesystem());
    }
}
