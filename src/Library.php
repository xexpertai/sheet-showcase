<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Resolves sheet names (from the URL) to files in the data/ folder.
 *
 * Names must match [a-z0-9_-]+ — no dots, no slashes — so a request can
 * never point outside data/. As defence in depth the resolved real path is
 * also checked to live inside the data directory.
 */
final class Library
{
    public const NAME_RE = '/^[a-z0-9_-]{1,64}$/';
    public const EXTENSIONS = ['xlsx', 'csv'];

    private readonly string $dir;

    public function __construct(string $dataDir)
    {
        $real = realpath($dataDir);
        if ($real === false || !is_dir($real)) {
            throw new ShowcaseException('Data directory not found.');
        }
        $this->dir = $real;
    }

    public static function isValidName(mixed $name): bool
    {
        return is_string($name) && preg_match(self::NAME_RE, $name) === 1;
    }

    /**
     * @return string|null Absolute path of the data file, or null if unknown.
     */
    public function resolve(mixed $name): ?string
    {
        if (!self::isValidName($name)) {
            return null;
        }
        foreach (self::EXTENSIONS as $ext) {
            $candidate = realpath($this->dir . DIRECTORY_SEPARATOR . $name . '.' . $ext);
            if ($candidate !== false && is_file($candidate)
                && str_starts_with($candidate, $this->dir . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string> Names of the sheets available in data/.
     */
    public function names(): array
    {
        $names = [];
        foreach (scandir($this->dir) ?: [] as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (in_array($ext, self::EXTENSIONS, true) && self::isValidName($name)) {
                $names[$name] = true;
            }
        }
        $names = array_map('strval', array_keys($names));
        sort($names);

        return $names;
    }
}
