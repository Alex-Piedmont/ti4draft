<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Shared\Command;
use App\Testing\DispatcherSpy;
use App\Testing\FakesCommands;
use App\Testing\RequestHandlerTestCase;
use App\Testing\UsesTestDraft;
use PHPUnit\Framework\Attributes\Test;

class HandleRegenerateDraftRequestAdversarialTest extends RequestHandlerTestCase
{
    use FakesCommands;
    use UsesTestDraft;

    protected string $requestHandlerClass = HandleRegenerateDraftRequest::class;

    #[Test]
    public function unexpectedRegenerationExceptionsPropagateInsteadOfBecoming400Responses(): void
    {
        app()->spy = new class extends DispatcherSpy {
            public function handle(Command $command): mixed
            {
                throw new \RuntimeException('unexpected regeneration failure');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected regeneration failure');

        $this->handleRequest([
            'id' => $this->testDraft->id,
            'slices' => 'true',
            'factions' => 'false',
            'order' => 'false',
            'admin' => $this->testDraft->secrets->adminSecret,
        ]);
    }
}
