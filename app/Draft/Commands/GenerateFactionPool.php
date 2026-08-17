<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidDraftSettingsException;
use App\Draft\FactionPartition;
use App\Draft\Settings;
use App\Shared\Command;
use App\TwilightImperium\Faction;

/**
 * Generates a pool of draftable factions based on settings
 */
class GenerateFactionPool implements Command
{
    private readonly array $factionData;

    public function __construct(
        private readonly Settings $settings,
    ) {
        $this->factionData = Faction::all();
    }

    /**
     * @return FactionPartition
     */
    public function handle(): FactionPartition
    {
        $this->settings->seed->setForFactions();
        $factionsFromSets = $this->gatherFactionsFromSelectedSets();
        $this->validatePinnedFactions($factionsFromSets);

        if ($this->settings->minorFactionsMode) {
            return $this->generateMinorFactionsPartition($factionsFromSets);
        }

        $gatheredFactions = [];

        if (! empty($this->settings->customFactions)) {
            foreach ($this->settings->customFactions as $f) {
                $gatheredFactions[] = $factionsFromSets[$f];
                // take out the selected faction, so it doesn't get re-drawn in the next part
                unset($factionsFromSets[$f]);
            }

            $factionsStillToGather = $this->settings->numberOfFactions - count($gatheredFactions);
            if ($factionsStillToGather > 0) {
                shuffle($factionsFromSets);
                $gatheredFactions = array_merge($gatheredFactions, array_slice($factionsFromSets, 0, $factionsStillToGather));
            }
        } else {
            $gatheredFactions = $factionsFromSets;
        }

        shuffle($gatheredFactions);

        return new FactionPartition(array_slice($gatheredFactions, 0, $this->settings->numberOfFactions));
    }

    /**
     * @param array<string, Faction> $factionsFromSets
     * @return array<Faction>
     */
    private function generateMinorFactionsPartition(array $factionsFromSets): FactionPartition
    {
        $requiredMinors = $this->settings->numberOfSlices;
        $requiredTotal = $this->settings->numberOfFactions + $requiredMinors;
        if (count($factionsFromSets) < $requiredTotal) {
            throw InvalidDraftSettingsException::notEnoughEnabledFactionsForMinorFactions(
                $requiredTotal,
                count($factionsFromSets),
            );
        }

        $draftable = [];
        foreach ($this->settings->customFactions as $name) {
            $draftable[] = $factionsFromSets[$name];
            unset($factionsFromSets[$name]);
        }

        $eligible = array_values(array_filter(
            $factionsFromSets,
            static fn (Faction $faction): bool => $faction->minorFactionEligible,
        ));
        if (count($eligible) < $requiredMinors) {
            throw InvalidDraftSettingsException::notEnoughFactionsForMinorFactions($requiredMinors);
        }

        shuffle($eligible);
        $minors = array_slice($eligible, 0, $requiredMinors);
        foreach ($minors as $minor) {
            unset($factionsFromSets[$minor->name]);
        }

        $remaining = array_values($factionsFromSets);
        shuffle($remaining);
        $draftable = array_merge(
            $draftable,
            array_slice($remaining, 0, $this->settings->numberOfFactions - count($draftable)),
        );
        shuffle($draftable);
        shuffle($minors);

        return new FactionPartition($draftable, $minors);
    }

    /** @param array<string, Faction> $enabledFactions */
    private function validatePinnedFactions(array $enabledFactions): void
    {
        if (count($this->settings->customFactions) !== count(array_unique($this->settings->customFactions))) {
            throw InvalidDraftSettingsException::invalidCustomFaction('duplicates are not allowed');
        }
        if (count($this->settings->customFactions) > $this->settings->numberOfFactions) {
            throw InvalidDraftSettingsException::invalidCustomFaction('more factions were pinned than requested');
        }
        foreach ($this->settings->customFactions as $name) {
            if (! isset($enabledFactions[$name])) {
                throw InvalidDraftSettingsException::invalidCustomFaction("{$name} is unknown or disabled");
            }
        }
    }

    private function gatherFactionsFromSelectedSets(): array
    {
        return array_filter($this->factionData, function (Faction $faction): bool {
            if ($faction->name === 'The Council Keleres') {
                return $this->settings->includeCouncilKeleresFaction && (
                    in_array(\App\TwilightImperium\Edition::PROPHECY_OF_KINGS, $this->settings->factionSets, true) ||
                    in_array(\App\TwilightImperium\Edition::THUNDERS_EDGE, $this->settings->factionSets, true)
                );
            }

            return in_array($faction->edition, $this->settings->factionSets, true);
        });
    }
}
