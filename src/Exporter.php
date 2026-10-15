<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Writes a single self-contained HTML file (CSS, JS and data inlined).
 */
final class Exporter
{
    public function __construct(private readonly Renderer $renderer = new Renderer())
    {
    }

    /**
     * @return int Bytes written.
     */
    public function export(string $dataPath, string $outPath, ?Config $config = null): int
    {
        $html = $this->renderer->render(Showcase::fromFile($dataPath, $config), inline: true);

        $dir = dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ShowcaseException('Cannot create output directory: ' . $dir);
        }
        // Write atomically so a half-written file is never served.
        $tmp = $outPath . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $html) === false || !rename($tmp, $outPath)) {
            @unlink($tmp);
            throw new ShowcaseException('Cannot write ' . $outPath);
        }

        return strlen($html);
    }
}
