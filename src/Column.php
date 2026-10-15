<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * One visible column of a showcase.
 */
final class Column
{
    public function __construct(
        /** Stable, URL-safe identifier (used in the URL hash). */
        public readonly string $key,
        /** Header text as written in the sheet. */
        public readonly string $header,
        /** Label shown to visitors. */
        public readonly string $label,
        public readonly string $type,
        /** Index of the column in the source rows. */
        public readonly int $source,
    ) {
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
