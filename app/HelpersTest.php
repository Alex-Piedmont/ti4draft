<?php

declare(strict_types=1);

namespace App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalProcessEnvironment = [];

    /** @var array<string, mixed> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        foreach (['HELPERS_TEST_VALUE', 'URL', 'RAILWAY_PUBLIC_DOMAIN', 'VERSION'] as $key) {
            $this->originalProcessEnvironment[$key] = getenv($key);
            if (array_key_exists($key, $_ENV)) {
                $this->originalEnvironment[$key] = $_ENV[$key];
            }
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalProcessEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }

            if (array_key_exists($key, $this->originalEnvironment)) {
                $_ENV[$key] = $this->originalEnvironment[$key];
            } else {
                unset($_ENV[$key]);
            }
        }
    }

    #[Test]
    public function envPrefersNonEmptyEnvironmentThenProcessThenDefault(): void
    {
        putenv('HELPERS_TEST_VALUE=process');
        $_ENV['HELPERS_TEST_VALUE'] = 'environment';
        self::assertSame('environment', env('HELPERS_TEST_VALUE', 'default'));

        $_ENV['HELPERS_TEST_VALUE'] = '';
        self::assertSame('process', env('HELPERS_TEST_VALUE', 'default'));

        putenv('HELPERS_TEST_VALUE');
        self::assertSame('default', env('HELPERS_TEST_VALUE', 'default'));
    }

    #[Test]
    public function envTreatsFalseAndEmptySourcesAsAbsent(): void
    {
        $_ENV['HELPERS_TEST_VALUE'] = false;
        putenv('HELPERS_TEST_VALUE=process');
        self::assertSame('process', env('HELPERS_TEST_VALUE', 'default'));

        putenv('HELPERS_TEST_VALUE=');
        self::assertSame('default', env('HELPERS_TEST_VALUE', 'default'));
    }

    public static function urlCases(): iterable
    {
        yield ['https://example.com', '', 'https://example.com/'];
        yield ['https://example.com/', '/', 'https://example.com/'];
        yield ['https://example.com///', '//api/draft/123//', 'https://example.com/api/draft/123'];
    }

    #[Test]
    #[DataProvider('urlCases')]
    public function urlNormalizesBaseAndRoute(string $base, string $route, string $expected): void
    {
        $_ENV['URL'] = $base;
        self::assertSame($expected, url($route));
    }

    #[Test]
    public function urlUsesAValidBareRailwayDomainWhenExplicitUrlIsAbsent(): void
    {
        putenv('RAILWAY_PUBLIC_DOMAIN=example.up.railway.app');
        self::assertSame('https://example.up.railway.app/d/123', url('/d/123/'));
    }

    #[Test]
    public function explicitUrlTakesPrecedenceOverRailwayDomain(): void
    {
        $_ENV['URL'] = 'https://draft.example';
        putenv('RAILWAY_PUBLIC_DOMAIN=ignored.up.railway.app');
        self::assertSame('https://draft.example/d/123', url('d/123'));
    }

    #[Test]
    #[DataProvider('invalidRailwayDomains')]
    public function publicUrlRejectsMalformedRailwayDomains(string $domain): void
    {
        putenv("RAILWAY_PUBLIC_DOMAIN={$domain}");
        self::assertNull(public_url());
    }

    public static function invalidRailwayDomains(): iterable
    {
        yield 'scheme' => ['https://example.up.railway.app'];
        yield 'path' => ['example.up.railway.app/path'];
        yield 'query' => ['example.up.railway.app?x=1'];
        yield 'fragment' => ['example.up.railway.app#x'];
    }

    #[Test]
    public function errorTemplateReadsVersionFromTheProcessEnvironment(): void
    {
        putenv('URL=https://example.com');
        putenv('VERSION=release-123');
        $error = 'Expected test error';

        ob_start();
        require dirname(__DIR__) . '/templates/error.php';
        $output = (string) ob_get_clean();

        self::assertStringContainsString('css/style.css?v=release-123', $output);
    }

    #[Test]
    #[DataProvider('invalidBootstrapEnvironment')]
    public function bootstrapFailuresSetHttpStatusFiveHundred(array $environment): void
    {
        $projectRoot = dirname(__DIR__);
        $fixtureRoot = sys_get_temp_dir() . '/ti4draft-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixtureRoot . '/bootstrap', 0700, true));
        self::assertTrue(copy($projectRoot . '/bootstrap/boot.php', $fixtureRoot . '/bootstrap/boot.php'));
        self::assertTrue(symlink($projectRoot . '/vendor', $fixtureRoot . '/vendor'));
        $script = <<<'PHP'
register_shutdown_function(static function (): void {
    echo "\nSTATUS=" . http_response_code();
});
require $argv[1];
PHP;
        $command = array_merge(
            [$this->phpBinary(), '-r', $script, $fixtureRoot . '/bootstrap/boot.php'],
            [],
        );
        $currentEnvironment = getenv();
        self::assertIsArray($currentEnvironment);
        $processEnvironment = array_merge($currentEnvironment, $environment);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixtureRoot, $processEnvironment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($fixtureRoot . '/bootstrap/boot.php');
        rmdir($fixtureRoot . '/bootstrap');
        unlink($fixtureRoot . '/vendor');
        rmdir($fixtureRoot);

        self::assertStringContainsString('STATUS=500', $output . $errors);
    }

    #[Test]
    public function bootstrapAcceptsConfigurationSuppliedOnlyAsProcessVariables(): void
    {
        $result = $this->runBootstrap([
            'URL' => '',
            'RAILWAY_PUBLIC_DOMAIN' => 'example.up.railway.app',
            'STORAGE' => 'local',
            'STORAGE_PATH' => sys_get_temp_dir(),
        ]);

        self::assertStringContainsString('STATUS=200', $result);
    }

    #[Test]
    public function bootstrapRejectsExistingNonWritableLocalStorage(): void
    {
        $storagePath = sys_get_temp_dir() . '/ti4draft-readonly-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($storagePath, 0500));

        try {
            $result = $this->runBootstrap([
                'URL' => 'https://example.com',
                'RAILWAY_PUBLIC_DOMAIN' => '',
                'STORAGE' => 'local',
                'STORAGE_PATH' => $storagePath,
            ]);

            self::assertStringContainsString('STATUS=500', $result);
        } finally {
            chmod($storagePath, 0700);
            rmdir($storagePath);
        }
    }

    public static function invalidBootstrapEnvironment(): iterable
    {
        yield 'missing public URL' => [[
            'URL' => '',
            'RAILWAY_PUBLIC_DOMAIN' => '',
            'STORAGE' => 'local',
            'STORAGE_PATH' => sys_get_temp_dir(),
        ]];
        yield 'Railway domain includes a scheme' => [[
            'URL' => '',
            'RAILWAY_PUBLIC_DOMAIN' => 'https://example.up.railway.app',
            'STORAGE' => 'local',
            'STORAGE_PATH' => sys_get_temp_dir(),
        ]];
        yield 'Railway domain includes a path' => [[
            'URL' => '',
            'RAILWAY_PUBLIC_DOMAIN' => 'example.up.railway.app/draft',
            'STORAGE' => 'local',
            'STORAGE_PATH' => sys_get_temp_dir(),
        ]];
        yield 'unusable local storage' => [[
            'URL' => 'https://example.com',
            'RAILWAY_PUBLIC_DOMAIN' => '',
            'STORAGE' => 'local',
            'STORAGE_PATH' => '/path/that/does/not/exist',
        ]];
    }

    /** @param array<string, string> $environment */
    private function runBootstrap(array $environment): string
    {
        $projectRoot = dirname(__DIR__);
        $fixtureRoot = sys_get_temp_dir() . '/ti4draft-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixtureRoot . '/bootstrap', 0700, true));
        self::assertTrue(copy($projectRoot . '/bootstrap/boot.php', $fixtureRoot . '/bootstrap/boot.php'));
        self::assertTrue(symlink($projectRoot . '/vendor', $fixtureRoot . '/vendor'));
        $script = <<<'PHP'
register_shutdown_function(static function (): void {
    $status = http_response_code();
    echo "\nSTATUS=" . ($status === false ? 200 : $status);
});
require $argv[1];
PHP;
        $command = [$this->phpBinary(), '-r', $script, $fixtureRoot . '/bootstrap/boot.php'];
        $currentEnvironment = getenv();
        self::assertIsArray($currentEnvironment);
        $processEnvironment = array_merge($currentEnvironment, $environment);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixtureRoot, $processEnvironment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($fixtureRoot . '/bootstrap/boot.php');
        rmdir($fixtureRoot . '/bootstrap');
        unlink($fixtureRoot . '/vendor');
        rmdir($fixtureRoot);

        return $output . $errors;
    }

    private function phpBinary(): string
    {
        return PHP_BINARY;
    }
}
