<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidDraftSettingsException;
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
     * @return array<Faction>
     */
    public function handle(): array
    {
        $this->settings->seed->setForFactions();
        $factionsFromSets = $this->gatherFactionsFromSelectedSets();

        if ($this->settings->minorFactionsMode) {
            return $this->generateMinorFactionsPool($factionsFromSets);
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

        return array_slice($gatheredFactions, 0, $this->settings->numberOfFactions);
    }

    /**
     * @param array<string, Faction> $factionsFromSets
     * @return array<Faction>
     */
    private function generateMinorFactionsPool(array $factionsFromSets): array
    {
        $requiredEligibleFactions = count($this->settings->playerNames) * 2;
        $gatheredFactions = [];

        foreach ($this->settings->customFactions as $name) {
            if (! isset($factionsFromSets[$name])) {
                throw InvalidDraftSettingsException::notEnoughFactionsForMinorFactions($requiredEligibleFactions);
            }

            $gatheredFactions[] = $factionsFromSets[$name];
            unset($factionsFromSets[$name]);
        }

        if (count($gatheredFactions) > $this->settings->numberOfFactions) {
            throw InvalidDraftSettingsException::notEnoughFactionsForMinorFactions($requiredEligibleFactions);
        }

        $eligibleCount = count(array_filter(
            $gatheredFactions,
            static fn (Faction $faction): bool => $faction->minorFactionEligible,
        ));
        $eligibleStillRequired = $requiredEligibleFactions - $eligibleCount;

        shuffle($factionsFromSets);
        foreach ($factionsFromSets as $key => $faction) {
            if ($eligibleStillRequired <= 0) {
                break;
            }

            if ($faction->minorFactionEligible) {
                $gatheredFactions[] = $faction;
                unset($factionsFromSets[$key]);
                $eligibleStillRequired--;
            }
        }

        if ($eligibleStillRequired > 0 || count($gatheredFactions) > $this->settings->numberOfFactions) {
            throw InvalidDraftSettingsException::notEnoughFactionsForMinorFactions($requiredEligibleFactions);
        }

        $remainingSlots = $this->settings->numberOfFactions - count($gatheredFactions);
        if ($remainingSlots > count($factionsFromSets)) {
            throw InvalidDraftSettingsException::notEnoughFactionsForMinorFactions($requiredEligibleFactions);
        }

        $gatheredFactions = array_merge(
            $gatheredFactions,
            array_slice($factionsFromSets, 0, $remainingSlots),
        );
        shuffle($gatheredFactions);

        return $gatheredFactions;
    }

    private function gatherFactionsFromSelectedSets(): array
    {
        return array_filter(
            $this->factionData,
            fn (Faction $faction) =>
                in_array($faction->edition, $this->settings->factionSets) ||
                $faction->name == 'The Council Keleres' && $this->settings->includeCouncilKeleresFaction,
        );
    }
}
