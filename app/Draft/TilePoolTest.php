<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class TilePoolTest extends TestCase
{
    #[Test]
    public function minorSelectionShufflesTheCombinedBlueTiersBeforeDrawing(): void
    {
        $pool = new TilePool(
            ['H1'],
            ['M1', 'M2', 'M3'],
            ['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'],
            ['R1', 'R2', 'R3', 'R4'],
        );

        mt_srand(123);
        $first = $pool->sliceForMinorFactions(2);
        mt_srand(123);
        $second = $pool->sliceForMinorFactions(2);

        $this->assertSame($first->allIds(), $second->allIds());
        $this->assertCount(4, $first->highTier);
        $this->assertCount(4, $first->redTier);
        $this->assertLessThanOrEqual(1, count(array_filter($first->highTier, fn (string $id): bool => $id[0] === 'H')));
        $this->assertNotEmpty(array_filter($first->highTier, fn (string $id): bool => $id[0] !== 'H'));
    }
}
