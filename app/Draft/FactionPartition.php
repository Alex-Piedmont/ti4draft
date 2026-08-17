<?php

declare(strict_types=1);

namespace App\Draft;

use App\TwilightImperium\Faction;

final class FactionPartition
{
    /**
     * @param Faction[] $draftable
     * @param Faction[] $minors
     */
    public function __construct(
        public readonly array $draftable,
        public readonly array $minors = [],
    ) {
        $draftableNames = array_map(fn (Faction $faction): string => $faction->name, $draftable);
        $minorNames = array_map(fn (Faction $faction): string => $faction->name, $minors);

        if (count($draftableNames) !== count(array_unique($draftableNames))) {
            throw new \InvalidArgumentException('Draftable faction pool must be unique');
        }
        if (count($minorNames) !== count(array_unique($minorNames))) {
            throw new \InvalidArgumentException('Minor faction pool must be unique');
        }
        if (array_intersect($draftableNames, $minorNames) !== []) {
            throw new \InvalidArgumentException('Draftable and Minor Faction pools must be disjoint');
        }
        foreach ($minors as $minor) {
            if (! $minor->minorFactionEligible) {
                throw new \InvalidArgumentException("{$minor->name} is not eligible to be a Minor Faction");
            }
        }
    }
}
