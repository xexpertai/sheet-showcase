<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Everything needed to render a page: columns, typed rows and settings.
 *
 * Hidden columns are dropped here, so their data never reaches the HTML
 * or the embedded JSON.
 */
final class Showcase
{
    public const PAGE_SIZE = 50;
    public const DEFAULT_ACCENT = '#1d4ed8';
    private const MAX_AUTO_FILTERS = 3;

    /**
     * @param list<Column> $columns
     * @param list<list<string|int|float|null>> $rows Values in column order, typed.
     * @param list<string> $filters Column keys.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $filters,
        public readonly ?string $sortKey,
        public readonly string $sortDir,
        public readonly string $theme,
        public readonly string $accent,
        public readonly string $layout,
        public readonly string $currency,
        public readonly string $locale,
    ) {
    }

    /**
     * Reads a .csv/.xlsx file and its optional showcase.json.
     */
    public static function fromFile(string $path, ?Config $config = null): self
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'csv', 'txt', 'tsv' => (new CsvReader())->read($path),
            'xlsx' => (new XlsxReader())->read($path),
            default => throw new ShowcaseException('Unsupported file type ".' . $ext . '" (use .csv or .xlsx).'),
        };

        return self::fromRows(
            pathinfo($path, PATHINFO_FILENAME),
            $rows,
            $config ?? Config::forDataFile($path)
        );
    }

    /**
     * @param list<list<string>> $rows First row = headers.
     */
    public static function fromRows(string $name, array $rows, Config $config = new Config()): self
    {
        if ($rows === []) {
            throw new ShowcaseException('The sheet is empty.');
        }
        $headers = self::headers(array_shift($rows));
        $count = count($headers);

        // Column order: configured columns first, then the remaining ones.
        $byLower = [];
        foreach ($headers as $i => $h) {
            $byLower[mb_strtolower($h)] ??= $i;
        }
        $plan = [];
        $seen = [];
        foreach ($config->columns as $c) {
            $i = $byLower[mb_strtolower($c['name'])] ?? null;
            if ($i === null) {
                throw new ShowcaseException(sprintf('Column "%s" in showcase.json does not exist in the sheet.', $c['name']));
            }
            $seen[$i] = true;
            $plan[] = ['index' => $i] + $c;
        }
        for ($i = 0; $i < $count; $i++) {
            if (!isset($seen[$i])) {
                $plan[] = ['index' => $i, 'name' => $headers[$i], 'label' => null, 'type' => null, 'hidden' => false];
            }
        }

        $columns = [];
        $usedKeys = [];
        $detectedCurrency = null;
        foreach ($plan as $p) {
            if ($p['hidden']) {
                continue;
            }
            $values = array_column($rows, $p['index']);
            $header = $headers[$p['index']];
            $type = $p['type'] ?? TypeInferer::infer($values, $header);
            if ($type === 'currency') {
                $detectedCurrency ??= TypeInferer::detectCurrency($values);
            }
            $columns[] = new Column(
                self::uniqueKey($header, $usedKeys),
                $header,
                $p['label'] ?? $header,
                $type,
                $p['index'],
            );
        }
        if ($columns === []) {
            throw new ShowcaseException('All columns are hidden.');
        }

        $typed = [];
        foreach ($rows as $row) {
            $out = [];
            foreach ($columns as $col) {
                $out[] = self::typedValue($row[$col->source] ?? '', $col->type);
            }
            $typed[] = $out;
        }

        $filters = self::resolveFilters($config, $columns);
        $sortKey = null;
        if ($config->sortColumn !== null) {
            $sortKey = self::findColumn($columns, $config->sortColumn)?->key
                ?? throw new ShowcaseException(sprintf('sort_default column "%s" is not a visible column.', $config->sortColumn));
        }

        return new self(
            name: $name,
            title: $config->title ?? self::humanise($name),
            subtitle: $config->subtitle ?? '',
            columns: $columns,
            rows: $typed,
            filters: $filters,
            sortKey: $sortKey,
            sortDir: $config->sortDirection,
            theme: $config->theme ?? 'auto',
            accent: Color::normalise($config->accentColor ?? self::DEFAULT_ACCENT),
            layout: $config->layout ?? (count($columns) > 6 ? 'table' : 'cards'),
            currency: $config->currency ?? $detectedCurrency ?? 'USD',
            locale: $config->locale ?? 'en',
        );
    }

    /**
     * Converts a raw cell to the value stored in the JSON payload.
     */
    public static function typedValue(string $raw, string $type): string|int|float|null
    {
        $raw = trim($raw);
        switch ($type) {
            case 'number':
            case 'currency':
                if ($raw === '') {
                    return null;
                }
                $n = TypeInferer::parseNumber($raw) ?? TypeInferer::parseCurrency($raw)[0] ?? null;
                if ($n === null) {
                    return $raw; // keep unparseable text visible, e.g. "on request"
                }

                return floor($n) === $n && abs($n) < PHP_INT_MAX ? (int) $n : $n;
            case 'date':
                return TypeInferer::parseDate($raw) ?? $raw;
            default:
                return $raw;
        }
    }

    public function column(string $key): ?Column
    {
        foreach ($this->columns as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }

        return null;
    }

    /** Column used as the heading of each card. */
    public function titleColumnIndex(): int
    {
        foreach ($this->columns as $i => $c) {
            if ($c->type === 'text') {
                return $i;
            }
        }

        return 0;
    }

    public function imageColumnIndex(): ?int
    {
        foreach ($this->columns as $i => $c) {
            if ($c->type === 'image') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Rows in the default sort order.
     *
     * @return list<list<string|int|float|null>>
     */
    public function sortedRows(): array
    {
        $rows = $this->rows;
        if ($this->sortKey === null) {
            return $rows;
        }
        $idx = 0;
        foreach ($this->columns as $i => $c) {
            if ($c->key === $this->sortKey) {
                $idx = $i;
            }
        }
        $dir = $this->sortDir === 'desc' ? -1 : 1;
        // Stable sort, empty values always last.
        $keyed = array_map(null, $rows, array_keys($rows));
        usort($keyed, static function (array $a, array $b) use ($idx, $dir): int {
            $va = $a[0][$idx];
            $vb = $b[0][$idx];
            $ea = $va === null || $va === '';
            $eb = $vb === null || $vb === '';
            if ($ea || $eb) {
                return ($ea <=> $eb) ?: $a[1] <=> $b[1];
            }
            $cmp = is_string($va) || is_string($vb)
                ? strnatcasecmp(self::fold((string) $va), self::fold((string) $vb))
                : $va <=> $vb;

            return ($cmp * $dir) ?: $a[1] <=> $b[1];
        });

        return array_column($keyed, 0);
    }

    /**
     * Distinct values for a filter column, sorted.
     *
     * @return list<string>
     */
    public function distinctValues(string $key): array
    {
        $idx = null;
        foreach ($this->columns as $i => $c) {
            if ($c->key === $key) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            return [];
        }
        $values = [];
        foreach ($this->rows as $row) {
            $v = (string) ($row[$idx] ?? '');
            if ($v !== '') {
                $values[$v] = true;
            }
        }
        $values = array_map('strval', array_keys($values));
        usort($values, static fn (string $a, string $b): int => strnatcasecmp(self::fold($a), self::fold($b)));

        return $values;
    }

    /**
     * JSON payload consumed by app.js.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'meta' => [
                'name' => $this->name,
                'layout' => $this->layout,
                'currency' => $this->currency,
                'locale' => $this->locale,
                'pageSize' => self::PAGE_SIZE,
                'sort' => ['key' => $this->sortKey, 'dir' => $this->sortDir],
                'filters' => $this->filters,
                'titleColumn' => $this->titleColumnIndex(),
                'imageColumn' => $this->imageColumnIndex(),
            ],
            'columns' => array_map(static fn (Column $c): array => $c->toArray(), $this->columns),
            'rows' => $this->rows,
        ];
    }

    /** Lowercase + strip accents (Café -> cafe), used for sorting/searching. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        } else {
            $s = strtr($s, [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u',
                'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            ]);
        }

        return $s;
    }

    /**
     * @param list<string> $raw
     * @return list<string>
     */
    private static function headers(array $raw): array
    {
        $headers = [];
        $seen = [];
        foreach ($raw as $i => $h) {
            $h = trim((string) preg_replace('/\s+/u', ' ', $h));
            if ($h === '') {
                $h = 'Column ' . ($i + 1);
            }
            $base = $h;
            $n = 2;
            while (isset($seen[mb_strtolower($h)])) {
                $h = $base . ' (' . $n++ . ')';
            }
            $seen[mb_strtolower($h)] = true;
            $headers[] = $h;
        }

        return $headers;
    }

    /**
     * @param array<string, bool> $used
     */
    private static function uniqueKey(string $header, array &$used): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', self::fold($header)), '-');
        if ($key === '' || in_array($key, ['q', 'sort', 'dir', 'view'], true)) {
            $key = 'col' . ($key === '' ? '' : '-' . $key);
        }
        $base = $key;
        $n = 2;
        while (isset($used[$key])) {
            $key = $base . '-' . $n++;
        }
        $used[$key] = true;

        return $key;
    }

    /**
     * @param list<Column> $columns
     */
    private static function findColumn(array $columns, string $name): ?Column
    {
        $needle = mb_strtolower($name);
        foreach ($columns as $c) {
            if (mb_strtolower($c->header) === $needle || $c->key === $needle) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param list<Column> $columns
     * @return list<string>
     */
    private static function resolveFilters(Config $config, array $columns): array
    {
        if ($config->filters === null) {
            $tags = array_filter($columns, static fn (Column $c): bool => $c->type === 'tag');

            return array_slice(array_values(array_map(static fn (Column $c): string => $c->key, $tags)), 0, self::MAX_AUTO_FILTERS);
        }
        $keys = [];
        foreach ($config->filters as $name) {
            $col = self::findColumn($columns, $name)
                ?? throw new ShowcaseException(sprintf('Filter column "%s" is not a visible column.', $name));
            $keys[] = $col->key;
        }

        return array_values(array_unique($keys));
    }

    private static function humanise(string $name): string
    {
        $s = trim((string) preg_replace('/[-_]+/', ' ', $name));

        return $s === '' ? 'Showcase' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
