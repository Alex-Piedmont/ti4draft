<?php

declare(strict_types=1);

namespace App\Draft;

use App\Testing\TestCase;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\Test;

class MinorFactionAssignmentsTest extends TestCase
{
    #[Test]
    public function itAssignsEligibleUnselectedPoolFactionsInPoolAndSpeakerOrder(): void
    {
        $result = $this->resolver()->toArray();

        $this->assertSame(MinorFactionAssignments::STATUS_RESOLVED, $result['status']);
        $this->assertSame([
            ['position' => 0, 'faction' => "Sardakk N'orr", 'home_system' => '13'],
            ['position' => 1, 'faction' => 'The Clan of Saar', 'home_system' => '11'],
            ['position' => 2, 'faction' => 'The Embers of Muaat', 'home_system' => '4'],
        ], $result['assignments']);
    }

    #[Test]
    public function itRemainsPendingUntilFactionAndPositionPicksAreComplete(): void
    {
        $players = $this->players();
        $players['a'] = $players['a']->unpick(PickCategory::POSITION);

        $result = $this->resolver($players)->toArray();

        $this->assertSame(MinorFactionAssignments::STATUS_PENDING, $result['status']);
        $this->assertSame([], $result['assignments']);
    }

    #[Test]
    public function itReportsInvalidWithoutPartialAssignmentsWhenCandidatesAreInsufficient(): void
    {
        $factions = Faction::all();
        $pool = [
            $factions['The Arborec'],
            $factions['The Barony of Letnev'],
            $factions['The Emirates of Hacan'],
            $factions['The Ghosts of Creuss'],
        ];

        $result = $this->resolver(factionPool: $pool)->toArray();

        $this->assertSame(MinorFactionAssignments::STATUS_INVALID, $result['status']);
        $this->assertSame(MinorFactionAssignments::ERROR_INSUFFICIENT_ELIGIBLE_CANDIDATES, $result['error']);
        $this->assertSame([], $result['assignments']);
    }

    #[Test]
    public function disabledModeNeverResolvesAssignments(): void
    {
        $result = $this->resolver(enabled: false)->toArray();

        $this->assertFalse($result['enabled']);
        $this->assertSame(MinorFactionAssignments::STATUS_PENDING, $result['status']);
        $this->assertSame([], $result['assignments']);
        $this->assertArrayNotHasKey('error', $result);
    }

    #[Test]
    public function itNeverAssignsSelectedIneligibleOrOutsidePoolFactionsAndTruncatesExcess(): void
    {
        $factions = Faction::all();
        $pool = [
            $factions['The Federation of Sol'],
            $factions['The Arborec'],
            $factions['The Ghosts of Creuss'],
            $factions['The Winnu'],
            $factions['The Barony of Letnev'],
            $factions['The Clan of Saar'],
            $factions['The Emirates of Hacan'],
            $factions['The Embers of Muaat'],
        ];

        $result = $this->resolver(factionPool: $pool)->toArray();
        $assignedFactions = array_column($result['assignments'], 'faction');

        $this->assertSame(MinorFactionAssignments::STATUS_RESOLVED, $result['status']);
        $this->assertSame([
            'The Federation of Sol',
            'The Winnu',
            'The Clan of Saar',
        ], $assignedFactions);
        $this->assertNotContains('The Ghosts of Creuss', $assignedFactions);
        $this->assertNotContains('The Embers of Muaat', $assignedFactions);
        $this->assertNotContains("Sardakk N'orr", $assignedFactions);
    }

    #[Test]
    public function itRemainsPendingWhenAnyFactionPickIsIncomplete(): void
    {
        $players = $this->players();
        $players['b'] = $players['b']->unpick(PickCategory::FACTION);

        $result = $this->resolver($players)->toArray();

        $this->assertSame([
            'enabled' => true,
            'equidistant_index' => Slice::EQUIDISTANT_INDEX,
            'status' => MinorFactionAssignments::STATUS_PENDING,
            'assignments' => [],
        ], $result);
    }

    #[Test]
    public function resolvedPayloadContainsOneUniqueCompleteRecordPerSpeakerPosition(): void
    {
        $result = $this->resolver()->toArray();
        $assignments = $result['assignments'];

        $this->assertSame([
            'enabled',
            'equidistant_index',
            'status',
            'assignments',
        ], array_keys($result));
        $this->assertSame(Slice::EQUIDISTANT_INDEX, $result['equidistant_index']);
        $this->assertCount(count($this->players()), $assignments);
        $this->assertCount(count($assignments), array_unique(array_column($assignments, 'position')));
        $this->assertCount(count($assignments), array_unique(array_column($assignments, 'faction')));
        $this->assertCount(count($assignments), array_unique(array_column($assignments, 'home_system')));
        foreach ($assignments as $assignment) {
            $this->assertSame(['position', 'faction', 'home_system'], array_keys($assignment));
        }
    }

    private function resolver(
        ?array $players = null,
        ?array $factionPool = null,
        bool $enabled = true,
    ): MinorFactionAssignments {
        return new MinorFactionAssignments(
            $enabled,
            $players ?? $this->players(),
            $factionPool ?? $this->factionPool(),
        );
    }

    /** @return array<Player> */
    private function players(): array
    {
        return [
            'a' => new Player(PlayerId::fromString('a'), 'Alice', pickedPosition: '2', pickedFaction: 'The Arborec'),
            'b' => new Player(PlayerId::fromString('b'), 'Bob', pickedPosition: '0', pickedFaction: 'The Barony of Letnev'),
            'c' => new Player(PlayerId::fromString('c'), 'Carol', pickedPosition: '1', pickedFaction: 'The Emirates of Hacan'),
        ];
    }

    /** @return array<Faction> */
    private function factionPool(): array
    {
        $factions = Faction::all();

        return [
            $factions['The Arborec'],
            $factions['The Barony of Letnev'],
            $factions['The Emirates of Hacan'],
            $factions['The Ghosts of Creuss'],
            $factions["Sardakk N'orr"],
            $factions['The Clan of Saar'],
            $factions['The Embers of Muaat'],
            $factions['The Federation of Sol'],
            $factions['The Winnu'],
        ];
    }
}
