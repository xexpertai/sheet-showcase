<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\TestCase;

/** The documented CLI commands work end to end (acceptance criterion 2). */
final class CliTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private static function cli(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', self::ROOT . '/bin/showcase'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::ROOT);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testExportWritesOneFile(): void
    {
        $dir = sys_get_temp_dir() . '/sheet-showcase-tests/cli-' . bin2hex(random_bytes(3));
        $out = $dir . '/cafe-menu.html';
        [$code, $stdout, $stderr] = self::cli(['export', 'data/cafe-menu.xlsx', '-o', $out]);
        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr, 'no warnings');
        self::assertStringContainsString('Wrote', $stdout);
        self::assertSame(['cafe-menu.html'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
    }

    public function testInspectPrintsSchema(): void
    {
        [$code, $stdout] = self::cli(['inspect', 'data/event-schedule.csv', '--json']);
        self::assertSame(0, $code);
        $schema = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Community Events 2026', $schema['title']);
        self::assertSame('date', $schema['sort_default']);
    }

    public function testErrorsAreReportedWithoutStackTraces(): void
    {
        [$code, , $stderr] = self::cli(['export', 'nope.ods']);
        self::assertSame(1, $code);
        self::assertStringStartsWith('Error: ', $stderr);
    }
}
