<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Validated contents of a `showcase.json` file.
 *
 * Lookup order for a data file `data/menu.xlsx`:
 *   1. `data/menu.showcase.json`
 *   2. `data/showcase.json` — top-level keys are shared defaults, and an
 *      optional `"sheets": {"menu": {...}}` map holds per-sheet overrides.
 *
 * Every field is optional; missing values are inferred from the data.
 */
final class Config
{
    public const THEMES = ['light', 'dark', 'auto'];
    public const LAYOUTS = ['cards', 'table'];

    /**
     * @param list<array{name: string, label: ?string, type: ?string, hidden: bool}> $columns
     * @param list<string>|null $filters
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $subtitle = null,
        public readonly array $columns = [],
        public readonly ?array $filters = null,
        public readonly ?string $sortColumn = null,
        public readonly string $sortDirection = 'asc',
        public readonly ?string $theme = null,
        public readonly ?string $accentColor = null,
        public readonly ?string $layout = null,
        public readonly ?string $currency = null,
        public readonly ?string $locale = null,
    ) {
    }

    public static function forDataFile(string $dataPath): self
    {
        $dir = dirname($dataPath);
        $name = pathinfo($dataPath, PATHINFO_FILENAME);

        $specific = $dir . '/' . $name . '.showcase.json';
        if (is_file($specific)) {
            return self::fromArray(self::readJson($specific));
        }
        $shared = $dir . '/showcase.json';
        if (is_file($shared)) {
            $data = self::readJson($shared);
            $sheets = $data['sheets'] ?? [];
            unset($data['sheets']);
            if (is_array($sheets) && isset($sheets[$name]) && is_array($sheets[$name])) {
                $data = array_replace($data, $sheets[$name]);
            }

            return self::fromArray($data);
        }

        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ShowcaseException(basename($path) . ' is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new ShowcaseException(basename($path) . ' must contain a JSON object.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $columns = [];
        foreach ((array) ($d['columns'] ?? []) as $col) {
            if (is_string($col)) {
                $col = ['name' => $col];
            }
            if (!is_array($col) || !is_string($col['name'] ?? null)) {
                throw new ShowcaseException('Each entry of "columns" needs a "name" (the sheet header).');
            }
            $type = $col['type'] ?? null;
            if ($type !== null && !in_array($type, TypeInferer::TYPES, true)) {
                throw new ShowcaseException(sprintf(
                    'Unknown type "%s" for column "%s". Use one of: %s.',
                    (string) (is_scalar($type) ? $type : gettype($type)),
                    $col['name'],
                    implode(', ', TypeInferer::TYPES)
                ));
            }
            $columns[] = [
                'name' => $col['name'],
                'label' => isset($col['label']) ? (string) $col['label'] : null,
                'type' => $type,
                'hidden' => (bool) ($col['hidden'] ?? false),
            ];
        }

        $filters = null;
        if (array_key_exists('filters', $d)) {
            $filters = array_values(array_map('strval', array_filter((array) $d['filters'], 'is_scalar')));
        }

        [$sortColumn, $sortDir] = self::parseSort($d['sort_default'] ?? null);

        $theme = self::oneOf($d, 'theme', self::THEMES);
        $layout = self::oneOf($d, 'layout', self::LAYOUTS);

        $accent = $d['accent_color'] ?? null;
        if ($accent !== null && !(is_string($accent) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $accent))) {
            throw new ShowcaseException('"accent_color" must be a hex colour like "#1d4ed8".');
        }

        $currency = $d['currency'] ?? null;
        if ($currency !== null && !(is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency))) {
            throw new ShowcaseException('"currency" must be a 3-letter ISO 4217 code like "EUR".');
        }

        $locale = $d['locale'] ?? null;
        if ($locale !== null && !(is_string($locale) && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $locale))) {
            throw new ShowcaseException('"locale" must be a BCP 47 tag like "en-GB".');
        }

        return new self(
            title: isset($d['title']) ? (string) $d['title'] : null,
            subtitle: isset($d['subtitle']) ? (string) $d['subtitle'] : null,
            columns: $columns,
            filters: $filters,
            sortColumn: $sortColumn,
            sortDirection: $sortDir,
            theme: $theme,
            accentColor: $accent,
            layout: $layout,
            currency: $currency !== null ? strtoupper($currency) : null,
            locale: $locale !== null ? str_replace('_', '-', $locale) : null,
        );
    }

    /**
     * Accepts "Name", "-Price" (descending) or {"column": "Price", "direction": "desc"}.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function parseSort(mixed $sort): array
    {
        if (is_string($sort) && $sort !== '') {
            return str_starts_with($sort, '-') ? [substr($sort, 1), 'desc'] : [$sort, 'asc'];
        }
        if (is_array($sort) && is_string($sort['column'] ?? null)) {
            $dir = strtolower((string) ($sort['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            return [$sort['column'], $dir];
        }

        return [null, 'asc'];
    }

    /**
     * @param array<string, mixed> $d
     * @param list<string> $allowed
     */
    private static function oneOf(array $d, string $key, array $allowed): ?string
    {
        if (!isset($d[$key])) {
            return null;
        }
        if (!in_array($d[$key], $allowed, true)) {
            throw new ShowcaseException(sprintf('"%s" must be one of: %s.', $key, implode(', ', $allowed)));
        }

        return $d[$key];
    }
}
