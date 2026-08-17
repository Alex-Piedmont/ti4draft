<?php

declare(strict_types=1);

namespace App\Http\RequestHandlers;

use App\Testing\RequestHandlerTestCase;
use PHPUnit\Framework\Attributes\Test;

class HandleViewFormRequestTest extends RequestHandlerTestCase
{

    protected string $requestHandlerClass = HandleViewFormRequest::class;

    #[Test]
    public function itIsConfiguredAsRouteHandler(): void
    {
        $this->assertIsConfiguredAsHandlerForRoute('/');
    }

    #[Test]
    public function itReturnsTheForm(): void
    {
        $response = $this->handleRequest();

        $this->assertResponseHtml($response);
        $this->assertResponseOk($response);
    }

    #[Test]
    public function itRendersMinorFactionsControlsAndGuidance(): void
    {
        $body = $this->handleRequest()->getBody();

        $this->assertStringContainsString('name="minor_factions_on"', $body);
        $this->assertStringContainsString('Minor Factions', $body);
        $this->assertStringContainsString('requested faction count is the number of draftable choices', $body);
        $this->assertStringContainsString('one eligible unused faction for every generated slice', $body);
        $this->assertStringContainsString('face up and counts toward that slice', $body);
    }

    #[Test]
    public function itRendersTheMinorFactionToggleUncheckedWithTheReplacementRule(): void
    {
        $body = $this->handleRequest()->getBody();

        $matched = preg_match('/<input[^>]*id="minor_factions_toggle"[^>]*>/', $body, $toggle);
        $this->assertSame(1, $matched);
        $this->assertStringNotContainsString('checked', $toggle[0]);
        $this->assertStringContainsString(
            'replaces one blue equidistant system in each slice with the home system of an unselected faction that was available in this draft',
            $body,
        );
    }
}
