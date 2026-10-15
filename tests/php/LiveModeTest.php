<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Starts `php -S` on public/ and checks real HTTP responses.
 * Acceptance criteria 1 (no warnings), 6 (live XSS markup) and 7 (traversal).
 */
final class LiveModeTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$server = self::start(dirname(__DIR__, 2) . '/data', self::$port);
    }

    public static function tearDownAfterClass(): void
    {
        self::stop(self::$server);
    }

    /**
     * @param-out int $port
     * @return resource
     */
    public static function start(string $dataDir, int &$port)
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        $name = (string) stream_socket_get_name($sock, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($sock);

        $cmd = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public'];
        $env = getenv() + ['SHEET_SHOWCASE_DATA' => $dataDir];
        $env['SHEET_SHOWCASE_DATA'] = $dataDir;
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port);
            if ($c) {
                fclose($c);

                return $proc;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start');
    }

    /** @param resource|null $proc */
    public static function stop($proc): void
    {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * @return array{int, string, list<string>}
     */
    public static function get(int $port, string $path): array
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = (string) file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        /** @var list<string> $headers */
        $headers = $http_response_header ?? [];
        preg_match('~HTTP/\S+\s+(\d+)~', $headers[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), $body, $headers];
    }

    public function testCafeMenuRendersAsCardsWithoutWarnings(): void
    {
        [$status, $body, $headers] = self::get(self::$port, '/?sheet=cafe-menu');
        self::assertSame(200, $status);
        self::assertStringContainsString('<ul class="cards" role="list">', $body);
        self::assertStringContainsString('Café au lait', $body);
        self::assertDoesNotMatchRegularExpression('~(Warning|Notice|Deprecated|Fatal error)\b~', $body);
        $csp = implode("\n", array_filter($headers, static fn ($h) => stripos($h, 'Content-Security-Policy:') === 0));
        self::assertStringContainsString("script-src 'self'", $csp);
    }

    public function testEverySampleRendersWithoutWarnings(): void
    {
        foreach (['cafe-menu', 'club-members', 'event-schedule'] as $name) {
            [$status, $body] = self::get(self::$port, '/?sheet=' . $name);
            self::assertSame(200, $status, $name);
            self::assertDoesNotMatchRegularExpression('~(Warning|Notice|Deprecated|Fatal error)\b~', $body, $name);
        }
    }

    public function testIndexListsSheets(): void
    {
        [$status, $body] = self::get(self::$port, '/');
        self::assertSame(200, $status);
        self::assertStringContainsString('?sheet=club-members', $body);
    }

    public function testAssetsAreServed(): void
    {
        foreach (['/assets/app.css', '/assets/app.js', '/assets/core.js'] as $path) {
            self::assertSame(200, self::get(self::$port, $path)[0], $path);
        }
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return [
            'dot-dot etc/passwd' => ['/?sheet=../../etc/passwd'],
            'encoded slash' => ['/?sheet=..%2Fsecret'],
            'double encoded' => ['/?sheet=..%252Fsecret'],
            'backslash' => ['/?sheet=..%5Csecret'],
            'absolute path' => ['/?sheet=%2Fetc%2Fpasswd'],
            'null byte' => ['/?sheet=cafe-menu%00'],
            'extension' => ['/?sheet=cafe-menu.xlsx'],
            'upper case' => ['/?sheet=CAFE-MENU'],
            'array param' => ['/?sheet[]=cafe-menu'],
            'unknown' => ['/?sheet=does-not-exist'],
            'empty' => ['/?sheet='],
        ];
    }

    #[DataProvider('badNames')]
    public function testPathTraversalAndBadNamesAre404(string $path): void
    {
        [$status, $body] = self::get(self::$port, $path);
        self::assertSame(404, $status);
        self::assertStringContainsString('This page does not exist.', $body);
        self::assertStringNotContainsString('passwd', $body, 'the request is never echoed');
        self::assertStringNotContainsString('secret', strtolower($body));
        self::assertStringNotContainsString('root:', $body);
    }

    public function testLiveModeEscapesXssFixture(): void
    {
        $port = 0;
        $proc = self::start(__DIR__ . '/fixtures', $port);
        try {
            [$status, $body] = self::get($port, '/?sheet=xss');
            self::assertSame(200, $status);
            self::assertSame(2, preg_match_all('~<script\b~i', $body));
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
            self::assertStringNotContainsString('<img src=x', $body);
            // secret.csv sits next to the fixtures but outside a real data/ folder;
            // in live mode it is only reachable by its exact whitelisted name.
            self::assertSame(404, self::get($port, '/?sheet=..%2Ffixtures%2Fsecret')[0]);
        } finally {
            self::stop($proc);
        }
    }
}
