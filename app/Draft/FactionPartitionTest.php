<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\TestCase;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

final class FactionPartitionTest extends TestCase
{
    #[Test]
    public function itAcceptsDisjointUniquePools(): void
    {
        $factions = Faction::all();
        $partition = new FactionPartition(
            [$factions['The Arborec']],
            [$factions["Sardakk N'orr"]],
        );

        $this->assertCount(1, $partition->draftable);
        $this->assertCount(1, $partition->minors);
    }

    #[Test]
    public function itAcceptsAnEmptyMinorPool(): void
    {
        $partition = new FactionPartition([Faction::all()['The Arborec']]);
        $this->assertSame([], $partition->minors);
    }

    #[Test]
    public function itRejectsOverlap(): void
    {
        $faction = Faction::all()['The Arborec'];
        $this->expectException(\InvalidArgumentException::class);
        new FactionPartition([$faction], [$faction]);
    }

    #[Test]
    public function itRejectsIneligibleMinors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FactionPartition([], [Faction::all()['The Ghosts of Creuss']]);
    }
}
