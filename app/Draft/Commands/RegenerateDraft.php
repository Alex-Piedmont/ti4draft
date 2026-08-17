<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Draft;
use App\Draft\Seed;
use App\Shared\Command;

class RegenerateDraft implements Command
{
    public function __construct(
        public Draft $draft,
        public bool $regenerateSlices,
        public bool $regenerateFactions,
        public bool $regenerateOrder,
    ) {

    }

    public function handle(): Draft
    {
        if (count($this->draft->log) > 0) {
            throw new \Exception('Cannot regenerate ongoing draft');
        }

        // Generate every requested replacement before mutating the draft so a
        // later validation failure cannot leave an in-memory partial result.
        $seed = new Seed();
        $settings = $this->draft->settings->withNewSeed($seed);
        $newPlayers = $this->draft->players;
        $newCurrentPlayerId = $this->draft->currentPlayerId;
        $newSlices = $this->draft->slicePool;
        $newFactions = $this->draft->factionPool;

        if ($this->regenerateOrder) {
            $seed->setForPlayerOrder();
            $order = array_keys($this->draft->players);
            shuffle($order);
            $newPlayers = [];
            foreach ($order as $key) {
                $newPlayers[$key] = $this->draft->players[$key];
            }
            $newCurrentPlayerId = \App\Draft\PlayerId::fromString(array_key_first($newPlayers));
        }

        $regenerateCoupledMinorState = $settings->minorFactionsMode && ($this->regenerateSlices || $this->regenerateFactions);
        if ($regenerateCoupledMinorState) {
            $partition = (new GenerateFactionPool($settings))->handle();
            $newSlices = (new GenerateSlicePool($settings, $partition->minors))->handle();
            $newFactions = $partition->draftable;
        } else {
            if ($this->regenerateSlices) {
                $newSlices = (new GenerateSlicePool($settings))->handle();
            }
            if ($this->regenerateFactions) {
                $newFactions = (new GenerateFactionPool($settings))->handle()->draftable;
            }
        }

        $this->draft->settings = $settings;
        $this->draft->players = $newPlayers;
        $this->draft->currentPlayerId = $newCurrentPlayerId;
        $this->draft->slicePool = $newSlices;
        $this->draft->factionPool = $newFactions;

        app()->repository->save($this->draft);

        return $this->draft;
    }
}
