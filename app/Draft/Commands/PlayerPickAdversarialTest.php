<?php

declare(strict_types=1);

namespace App\Draft\Commands;

use App\Draft\Exceptions\InvalidPickException;
use App\Draft\Pick;
use App\Draft\PickCategory;
use App\Testing\TestCase;
use App\Testing\UsesTestDraft;
use App\TwilightImperium\Faction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PlayerPickAdversarialTest extends TestCase
{
    use UsesTestDraft;

    #[Test]
    public function itRejectsAGloballyKnownFactionThatIsAbsentFromThePersistedPoolWithoutMutation(): void
    {
        $poolNames = array_map(static fn (Faction $faction): string => $faction->name, $this->testDraft->factionPool);
        $unavailableFaction = current(array_filter(
            Faction::all(),
            static fn (Faction $faction): bool => ! in_array($faction->name, $poolNames, true),
        ));
        $this->assertInstanceOf(Faction::class, $unavailableFaction, 'The fixture must leave a catalog faction outside the pool');

        $before = $this->testDraft->toArray(true);

        try {
            (new PlayerPick(
                $this->testDraft,
                new Pick($this->testDraft->currentPlayerId, PickCategory::FACTION, $unavailableFaction->name),
            ))->handle();
            $this->fail('Expected a globally known but unavailable faction to be rejected');
        } catch (InvalidPickException $exception) {
            $this->assertSame(
                "Faction is not available in this draft: {$unavailableFaction->name}",
                $exception->getMessage(),
            );
        }

        $this->assertSame($before, $this->testDraft->toArray(true));
        $this->reloadDraft();
        $this->assertSame($before, $this->testDraft->toArray(true));
    }

    public static function unsupportedFactionRepresentations(): iterable
    {
        yield 'case differs' => [static fn (string $name): string => strtolower($name)];
        yield 'leading space' => [static fn (string $name): string => ' ' . $name];
        yield 'trailing space' => [static fn (string $name): string => $name . ' '];
    }

    #[Test]
    #[DataProvider('unsupportedFactionRepresentations')]
    public function itRejectsInPoolFactionNamesThatDoNotMatchExactIdentity(callable $mutateName): void
    {
        $exactName = $this->testDraft->factionPool[0]->name;
        $unsupportedName = $mutateName($exactName);

        $this->expectException(InvalidPickException::class);
        $this->expectExceptionMessage("Faction is not available in this draft: {$unsupportedName}");

        (new PlayerPick(
            $this->testDraft,
            new Pick($this->testDraft->currentPlayerId, PickCategory::FACTION, $unsupportedName),
        ))->handle();
    }

    public static function nonFactionPicks(): iterable
    {
        yield 'slice' => [PickCategory::SLICE, '1'];
        yield 'speaker position' => [PickCategory::POSITION, '1'];
    }

    #[Test]
    #[DataProvider('nonFactionPicks')]
    public function itLeavesValidNonFactionPickBehaviorUnchanged(PickCategory $category, string $value): void
    {
        $playerId = $this->testDraft->currentPlayerId;

        (new PlayerPick($this->testDraft, new Pick($playerId, $category, $value)))->handle();

        $this->reloadDraft();
        $this->assertSame($value, $this->testDraft->playerById($playerId)->getPick($category));
    }
}
