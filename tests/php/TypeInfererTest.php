<?php

declare(strict_types=1);

namespace SheetShowcase\Tests;

use PHPUnit\Framework\TestCase;
use SheetShowcase\CsvReader;
use SheetShowcase\Showcase;
use SheetShowcase\TypeInferer;

/** Acceptance criterion 5. */
final class TypeInfererTest extends TestCase
{
    public function testDetectsEveryTypeOnTheTypesFixture(): void
    {
        $s = Showcase::fromFile(__DIR__ . '/fixtures/types.csv');
        $types = [];
        foreach ($s->columns as $c) {
            $types[$c->header] = $c->type;
        }
        self::assertSame([
            'Count' => 'number',
            'Price' => 'currency',
            'Event date' => 'date',
            'Website' => 'url',
            'Contact' => 'email',
            'Category' => 'tag',
            'Notes' => 'text',
            'Photo' => 'image',
        ], $types);
        self::assertSame('EUR', $s->currency, 'currency code inferred from the € symbol');
        self::assertSame(['category'], $s->filters, 'tag columns become default filters');
    }

    public function testSampleDatasets(): void
    {
        $members = Showcase::fromFile(__DIR__ . '/../../data/club-members.csv');
        $byHeader = array_column(array_map(static fn ($c) => [$c->header, $c->type], $members->columns), 1, 0);
        self::assertSame('email', $byHeader['Email']);
        self::assertSame('date', $byHeader['Member since']);
        self::assertSame('tag', $byHeader['Team']);
        self::assertSame('text', $byHeader['Name']);

        $events = Showcase::fromFile(__DIR__ . '/../../data/event-schedule.csv');
        $byHeader = array_column(array_map(static fn ($c) => [$c->header, $c->type], $events->columns), 1, 0);
        self::assertSame('date', $byHeader['Date']);
        self::assertSame('url', $byHeader['Details']);
    }

    public function testTagThreshold(): void
    {
        $twelve = [];
        $thirteen = [];
        for ($i = 0; $i < 60; $i++) {
            $twelve[] = 'v' . ($i % 12);
            $thirteen[] = 'v' . ($i % 13);
        }
        self::assertSame('tag', TypeInferer::infer($twelve));
        self::assertSame('text', TypeInferer::infer($thirteen));
        self::assertSame('text', TypeInferer::infer(['Ann', 'Bob', 'Cid']), 'all-unique short lists are not tags');
    }

    public function testOnlyFirst200RowsAreSampled(): void
    {
        $values = array_fill(0, 200, '42');
        $values[] = 'not a number';
        self::assertSame('number', TypeInferer::infer($values));
    }

    public function testEmptyCellsAreIgnored(): void
    {
        self::assertSame('number', TypeInferer::infer(['1', '', '3', '']));
        self::assertSame('text', TypeInferer::infer(['', '']));
    }

    public function testNumberParsing(): void
    {
        self::assertSame(1234.5, TypeInferer::parseNumber('1,234.5'));
        self::assertSame(1234.5, TypeInferer::parseNumber('1.234,5'));
        self::assertSame(3.5, TypeInferer::parseNumber('3,50'));
        self::assertSame(-0.25, TypeInferer::parseNumber('-.25'));
        self::assertSame(1234567.0, TypeInferer::parseNumber('1 234 567'));
        self::assertNull(TypeInferer::parseNumber('12abc'));
        self::assertSame([3.5, 'EUR'], TypeInferer::parseCurrency('€ 3,50'));
        self::assertSame([12.0, 'INR'], TypeInferer::parseCurrency('₹12'));
        self::assertSame([9.99, 'USD'], TypeInferer::parseCurrency('9.99 USD'));
    }

    public function testPriceHeaderTurnsNumbersIntoCurrency(): void
    {
        self::assertSame('currency', TypeInferer::infer(['3.20', '4'], 'Price'));
        self::assertSame('number', TypeInferer::infer(['3.20', '4'], 'Weight'));
    }

    public function testDatesMustBeReal(): void
    {
        self::assertSame('2026-02-28', TypeInferer::parseDate('2026-02-28'));
        self::assertNull(TypeInferer::parseDate('2026-02-30'));
        self::assertSame('2026-02-28 10:30', TypeInferer::parseDate('2026-02-28T10:30'));
    }

    public function testUrlsAreHttpOnly(): void
    {
        self::assertTrue(TypeInferer::isHttpUrl('https://example.org/a?b=c'));
        self::assertTrue(TypeInferer::isHttpUrl('HTTP://EXAMPLE.ORG'));
        self::assertFalse(TypeInferer::isHttpUrl('javascript:alert(1)'));
        self::assertFalse(TypeInferer::isHttpUrl('//example.org'));
        self::assertFalse(TypeInferer::isHttpUrl('ftp://example.org'));
        self::assertFalse(TypeInferer::isHttpUrl('https://exa mple.org'));
        self::assertSame('url', TypeInferer::infer(['https://a.example', 'http://b.example/x']));
    }

    public function testRowsAreTypedForTheJsonPayload(): void
    {
        $rows = (new CsvReader())->parseString("Name,Price,When\nTea,\"3,50\",2026-01-02\nCake,,2026-01-03\n");
        $s = Showcase::fromRows('x', $rows);
        self::assertSame([['Tea', 3.5, '2026-01-02'], ['Cake', null, '2026-01-03']], $s->rows);
    }
}
