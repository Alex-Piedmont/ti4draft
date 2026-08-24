<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\TestCase;
use App\TwilightImperium\Faction;
use App\TwilightImperium\Tile;
use PHPUnit\Framework\Attributes\Test;

final class MinorFactionTest extends TestCase
{
    #[Test]
    public function itRoundTripsCanonicalAndRenderIdentifiers(): void
    {
        $faction = Faction::all()['Augurs of Ilyxum'];
        $minor = MinorFaction::fromFaction($faction);
        $persisted = [
            'name' => 'Augurs of Ilyxum',
            'tile_id' => '4215',
        ];
        $restored = MinorFaction::fromArray($persisted);

        $this->assertSame($faction->homeSystemTileNumber, $minor->homeSystem->id);
        $this->assertSame($persisted, $restored->toPersistedArray());
        $this->assertSame([
            'name' => 'Augurs of Ilyxum',
            'tile_id' => '4215',
            'render_token' => 'DS_ilyxum',
        ], $restored->toArray());
        $this->assertSame($faction->allianceAbility, $restored->faction->allianceAbility);
    }

    #[Test]
    public function itRejectsIneligibleFactions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MinorFaction::fromFaction(Faction::all()['The Ghosts of Creuss']);
    }

    #[Test]
    public function itRejectsMismatchedHomeSystems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MinorFaction(Faction::all()["Sardakk N'orr"], Tile::all()['1']);
    }

    #[Test]
    public function itRejectsMalformedPersistedState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MinorFaction::fromArray(['name' => "Sardakk N'orr"]);
    }
}
