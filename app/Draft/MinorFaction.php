<?php

declare(strict_types=1);

namespace App\Draft;

use App\TwilightImperium\Faction;
use App\TwilightImperium\Tile;

final class MinorFaction
{
    public function __construct(
        public readonly Faction $faction,
        public readonly Tile $homeSystem,
    ) {
        if (! $faction->minorFactionEligible) {
            throw new \InvalidArgumentException("{$faction->name} is not eligible to be a Minor Faction");
        }

        if ($faction->homeSystemTileNumber !== $homeSystem->id) {
            throw new \InvalidArgumentException("{$faction->name} does not use home system {$homeSystem->id}");
        }
    }

    public static function fromFaction(Faction $faction): self
    {
        $tiles = Tile::all();
        if (! isset($tiles[$faction->homeSystemTileNumber])) {
            throw new \InvalidArgumentException("Unknown home system for {$faction->name}");
        }

        return new self($faction, $tiles[$faction->homeSystemTileNumber]);
    }

    public static function fromArray(mixed $data): self
    {
        if (! is_array($data) || ! isset($data['name'], $data['tile_id']) || ! is_string($data['name']) || ! is_string($data['tile_id'])) {
            throw new \InvalidArgumentException('Invalid persisted Minor Faction assignment');
        }

        $factions = Faction::all();
        $tiles = Tile::all();
        if (! isset($factions[$data['name']]) || ! isset($tiles[$data['tile_id']])) {
            throw new \InvalidArgumentException('Unknown persisted Minor Faction assignment');
        }

        return new self($factions[$data['name']], $tiles[$data['tile_id']]);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->faction->name,
            'tile_id' => $this->homeSystem->id,
            'render_token' => $this->faction->homesystem(),
        ];
    }

    public function toPersistedArray(): array
    {
        return [
            'name' => $this->faction->name,
            'tile_id' => $this->homeSystem->id,
        ];
    }
}
