<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\TestCase;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

final class FactionPartitionAdversarialTest extends TestCase
{
    #[Test]
    public function duplicateDraftableNamesAreRejected(): void
    {
        $faction = Faction::all()['The Arborec'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Draftable faction pool must be unique');

        new FactionPartition([$faction, $faction]);
    }

    #[Test]
    public function duplicateMinorNamesAreRejected(): void
    {
        $faction = Faction::all()['The Arborec'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Minor faction pool must be unique');

        new FactionPartition([], [$faction, $faction]);
    }
}
