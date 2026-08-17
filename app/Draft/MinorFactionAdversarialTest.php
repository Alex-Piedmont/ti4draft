<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\TestCase;
use App\TwilightImperium\Edition;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MinorFactionAdversarialTest extends TestCase
{
    public static function malformedAssignments(): iterable
    {
        yield 'blank faction name' => [['name' => '', 'tile_id' => '13']];
        yield 'blank home tile' => [['name' => "Sardakk N'orr", 'tile_id' => '']];
        yield 'non-string faction name' => [['name' => [], 'tile_id' => '13']];
        yield 'non-string home tile' => [['name' => "Sardakk N'orr", 'tile_id' => 13]];
        yield 'unknown faction name' => [['name' => 'Not a real faction', 'tile_id' => '13']];
        yield 'unknown home tile' => [['name' => "Sardakk N'orr", 'tile_id' => 'not-a-tile']];
    }

    #[DataProvider('malformedAssignments')]
    #[Test]
    public function malformedPersistedAssignmentsFailExplicitly(array $assignment): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MinorFaction::fromArray($assignment);
    }

    #[Test]
    public function factionWithoutCanonicalHomeMappingFailsClosed(): void
    {
        $faction = new Faction(
            'Missing Home',
            'missing-home',
            'not-a-real-home-system',
            '',
            Edition::BASE_GAME,
            true,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown home system');

        MinorFaction::fromFaction($faction);
    }
}
