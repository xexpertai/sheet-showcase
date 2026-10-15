<?php

/**
 * Regenerates the (fictional) sample datasets in data/ and the test fixtures
 * in tests/php/fixtures/. Output is deterministic; the files are committed,
 * so you only need this after changing it.
 *
 *   php tools/build-samples.php
 */

declare(strict_types=1);

require __DIR__ . '/XlsxWriter.php';

use SheetShowcase\Tools\XlsxWriter;

$root = dirname(__DIR__);
$data = $root . '/data';
$fx = $root . '/tests/php/fixtures';
@mkdir($data, 0775, true);
@mkdir($fx, 0775, true);

/** @param list<list<string>> $rows */
function csv(array $rows, string $delimiter = ','): string
{
    $out = '';
    foreach ($rows as $row) {
        $out .= implode($delimiter, array_map(static function (string $v) use ($delimiter): string {
            return preg_match('/["\r\n' . preg_quote($delimiter, '/') . ']/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }, $row)) . "\n";
    }

    return $out;
}

// ---------------------------------------------------------------- samples

// 1. Cafe menu (xlsx). Fictional cafe, fictional prices.
$menu = [
    ['Item', 'Category', 'Price', 'Description', 'Diet'],
    ['Café au lait', 'Coffee', 3.2, 'Filter coffee with steamed milk.', 'Vegetarian'],
    ['Espresso', 'Coffee', 2.1, 'Single shot, house blend.', 'Vegan'],
    ['Flat white', 'Coffee', 3.4, 'Double ristretto, velvety milk.', 'Vegetarian'],
    ['Oat cappuccino', 'Coffee', 3.6, 'Cappuccino made with oat milk.', 'Vegan'],
    ['Iced latte', 'Coffee', 3.9, 'Espresso over ice and cold milk.', 'Vegetarian'],
    ['Masala chai', 'Tea', 3.3, 'Spiced black tea simmered with milk.', 'Vegetarian'],
    ['Sencha', 'Tea', 2.8, 'Japanese green tea, loose leaf.', 'Vegan'],
    ['Rooibos', 'Tea', 2.6, 'Caffeine-free red bush tea.', 'Vegan'],
    ['Hot chocolate', 'Drinks', 3.5, 'Dark chocolate and whole milk.', 'Vegetarian'],
    ['Fresh orange juice', 'Drinks', 3.8, 'Squeezed to order.', 'Vegan'],
    ['Lemonade', 'Drinks', 3.0, 'House-made, lightly sparkling.', 'Vegan'],
    ['Butter croissant', 'Bakery', 2.4, 'Baked every morning.', 'Vegetarian'],
    ['Pain au chocolat', 'Bakery', 2.7, 'Flaky pastry, two chocolate batons.', 'Vegetarian'],
    ['Cinnamon bun', 'Bakery', 3.1, 'Cardamom dough, cinnamon sugar.', 'Vegetarian'],
    ['Banana bread', 'Bakery', 2.9, 'Slice of banana and walnut loaf.', 'Vegan'],
    ['Avocado toast', 'Breakfast', 7.5, 'Sourdough, avocado, chilli, lime.', 'Vegan'],
    ['Shakshuka', 'Breakfast', 9.2, 'Eggs baked in spiced tomato sauce.', 'Vegetarian'],
    ['Granola bowl', 'Breakfast', 6.4, 'Yoghurt, granola, seasonal fruit.', 'Vegetarian'],
    ['Full breakfast', 'Breakfast', 11.5, 'Eggs, bacon, beans, mushrooms, toast.', 'Meat'],
    ['Croque monsieur', 'Lunch', 8.9, 'Ham and gruyère toastie with béchamel.', 'Meat'],
    ['Lentil soup', 'Lunch', 6.8, 'Red lentils, cumin, lemon. With bread.', 'Vegan'],
    ['Falafel wrap', 'Lunch', 8.2, 'Falafel, hummus, pickles, salad.', 'Vegan'],
    ['Chicken caesar salad', 'Lunch', 9.6, 'Romaine, parmesan, croutons.', 'Meat'],
    ['Carrot cake', 'Cakes', 4.2, 'Cream cheese frosting.', 'Vegetarian'],
    ['Lemon tart', 'Cakes', 4.4, 'Crisp pastry, sharp lemon curd.', 'Vegetarian'],
    ['Chocolate brownie', 'Cakes', 3.6, 'Gooey centre, sea salt.', 'Vegetarian'],
];
XlsxWriter::write($data . '/cafe-menu.xlsx', $menu, 'Menu');

// 2. Club member directory (csv with ';'). All names are invented.
$first = ['Asha', 'Ben', 'Chiara', 'Dev', 'Elif', 'Femi', 'Greta', 'Hiro', 'Ines', 'Jonas', 'Kavya', 'Liam', 'Mira', 'Noah', 'Olu', 'Priya', 'Quentin', 'Rosa', 'Sami', 'Tara', 'Umar', 'Vera', 'Wen', 'Xavier', 'Yara', 'Zoë', 'Anil', 'Bea', 'Carlos', 'Dalia'];
$last = ['Example', 'Sample', 'Testwood', 'Placeholder', 'Demo', 'Mockford', 'Fakeley', 'Dummett'];
$roles = ['Member', 'Member', 'Member', 'Coach', 'Treasurer', 'Volunteer'];
$teams = ['Juniors', 'Seniors', 'Masters', 'Social'];
$cities = ['Lisbon', 'Porto', 'Coimbra', 'Braga', 'Faro'];
$members = [['Name', 'Role', 'Team', 'City', 'Email', 'Member since']];
foreach ($first as $i => $f) {
    $l = $last[$i % count($last)];
    $members[] = [
        "$f $l",
        $roles[$i % count($roles)],
        $teams[($i * 3) % count($teams)],
        $cities[($i * 7) % count($cities)],
        strtolower(str_replace('ë', 'e', $f)) . '.' . strtolower($l) . '@example.org',
        sprintf('%d-%02d-%02d', 2015 + ($i % 10), 1 + ($i * 5) % 12, 1 + ($i * 11) % 28),
    ];
}
file_put_contents($data . '/club-members.csv', csv($members, ';'));

// 3. Event schedule (csv with dates). Fictional events and venues.
$events = [['Event', 'Date', 'Start', 'Venue', 'Category', 'Price', 'Details']];
$names = [
    ['Spring open day', 'Community', 0], ['Beginners workshop', 'Workshop', 10], ['Night run 5K', 'Sport', 12],
    ['Repair café', 'Community', 0], ['Watercolour basics', 'Workshop', 25], ['Board game evening', 'Social', 3],
    ['River clean-up', 'Volunteering', 0], ['Summer tournament', 'Sport', 15], ['Film under the stars', 'Social', 6],
    ['First aid course', 'Workshop', 30], ['Harvest market', 'Community', 0], ['Charity quiz', 'Social', 5],
    ['Trail run 10K', 'Sport', 18], ['Photography walk', 'Workshop', 12], ['Winter fair', 'Community', 0],
    ['Annual general meeting', 'Community', 0], ['Coding club for kids', 'Workshop', 0], ['New year swim', 'Sport', 4],
];
$venues = ['Main hall', 'Riverside park', 'Room 2', 'Sports ground', 'Library annex'];
foreach ($names as $i => [$n, $cat, $price]) {
    $events[] = [
        $n,
        sprintf('2026-%02d-%02d', 3 + intdiv($i * 10, 18), 1 + ($i * 13) % 28),
        sprintf('%02d:%02d', 9 + ($i * 5) % 12, ($i % 2) * 30),
        $venues[$i % count($venues)],
        $cat,
        $price === 0 ? '0' : (string) $price,
        'https://example.org/events/' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($n)), '-'),
    ];
}
file_put_contents($data . '/event-schedule.csv', csv($events));

file_put_contents($data . '/showcase.json', json_encode([
    'theme' => 'auto',
    'locale' => 'en-GB',
    'sheets' => [
        'cafe-menu' => [
            'title' => 'Café Lumen — Menu',
            'subtitle' => 'Fictional sample data: an invented café with invented prices.',
            'layout' => 'cards',
            'currency' => 'EUR',
            'accent_color' => '#9a3412',
            'columns' => [
                ['name' => 'Item'],
                ['name' => 'Price', 'type' => 'currency'],
                ['name' => 'Category'],
                ['name' => 'Diet'],
                ['name' => 'Description'],
            ],
            'filters' => ['Category', 'Diet'],
        ],
        'club-members' => [
            'title' => 'Riverside Running Club — Members',
            'subtitle' => 'Fictional sample data: every name and email address is made up.',
            'layout' => 'table',
            'filters' => ['Team', 'Role', 'City'],
            'sort_default' => 'Name',
            'columns' => [
                'Name', 'Role', 'Team', 'City', 'Email',
                ['name' => 'Member since', 'label' => 'Joined'],
            ],
        ],
        'event-schedule' => [
            'title' => 'Community Events 2026',
            'subtitle' => 'Fictional sample data: invented events at invented venues.',
            'layout' => 'cards',
            'currency' => 'EUR',
            'accent_color' => '#047857',
            'sort_default' => 'Date',
            'filters' => ['Category', 'Venue'],
            'columns' => [
                ['name' => 'Event'],
                ['name' => 'Date'],
                ['name' => 'Start'],
                ['name' => 'Price', 'type' => 'currency', 'label' => 'Ticket'],
                ['name' => 'Details', 'label' => 'More info'],
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

// ---------------------------------------------------------------- fixtures

$basic = [['Name', 'City', 'Price'], ['Crème brûlée', 'Paris', '4.50'], ['Pierogi', 'Kraków', '6'], ['Tapas', 'Sevilla', '12.25']];
file_put_contents("$fx/delim-comma.csv", csv($basic, ','));
file_put_contents("$fx/delim-semicolon.csv", csv($basic, ';'));
file_put_contents("$fx/delim-tab.csv", csv($basic, "\t"));
file_put_contents("$fx/delim-pipe.csv", csv($basic, '|'));
file_put_contents("$fx/utf8-bom.csv", "\xEF\xBB\xBF" . csv($basic));
file_put_contents("$fx/utf8-nobom.csv", csv($basic));
// Windows-1252 bytes: é = 0xE9, € = 0x80. Semicolons, as Excel writes in many EU locales.
file_put_contents("$fx/cp1252.csv", "Name;Price\r\nCaf\xE9 cr\xE8me;\x80 3,50\r\nTh\xE9;\x80 2,00\r\n");
file_put_contents("$fx/quoted.csv", "Name,Address,Note\r\n"
    . "\"Smith, Jane\",\"12 High St, Springfield\",\"Line one\r\nLine two\"\r\n"
    . "Plain,\"He said \"\"hi\"\"\",\"\"\r\n"
    . "\"Multi\nline\nname\",Somewhere,last\r\n");
file_put_contents("$fx/ragged.csv", "A,B,C\n1,2\n3\n4,5,6\n\n7,8,9,10\n");

$types = [['Count', 'Price', 'Event date', 'Website', 'Contact', 'Category', 'Notes', 'Photo']];
$cats = ['Red', 'Green', 'Blue'];
for ($i = 1; $i <= 24; $i++) {
    $types[] = [
        (string) ($i * 3),
        '€' . number_format($i * 1.25, 2),
        sprintf('2026-%02d-%02d', 1 + $i % 12, 1 + $i),
        "https://example.org/page/$i",
        "person$i@example.org",
        $cats[$i % 3],
        "Free text note number $i",
        "https://images.example.org/photo-$i.jpg",
    ];
}
file_put_contents("$fx/types.csv", csv($types));

// XLSX vs CSV round trip: shared strings, numbers, booleans, gap, dates, inline string, formula.
$roundtrip = [
    ['Name', 'Qty', 'Price', 'Active', 'Notes', 'Since'],
    ['Widget', 3, 4.5, true, 'Shared note', ['date', '2024-02-29']],
    ['Gadget', 10, 12.0, false, null, ['date', '1999-12-31']],
    [['inline', 'Gizmo'], 0, 0.1, true, 'Shared note', ['date', '2026-10-15']],
    ['Doohickey', ['formula', 'B2+B3', 13], -2.75, false, ['inline', 'ünïcödé ✓'], ['date', '1970-01-01']],
];
XlsxWriter::write("$fx/roundtrip.xlsx", $roundtrip);
file_put_contents("$fx/roundtrip.csv", csv([
    ['Name', 'Qty', 'Price', 'Active', 'Notes', 'Since'],
    ['Widget', '3', '4.5', 'TRUE', 'Shared note', '2024-02-29'],
    ['Gadget', '10', '12', 'FALSE', '', '1999-12-31'],
    ['Gizmo', '0', '0.1', 'TRUE', 'Shared note', '2026-10-15'],
    ['Doohickey', '13', '-2.75', 'FALSE', 'ünïcödé ✓', '1970-01-01'],
]));

// XSS payloads. Rendered output must keep all of these as inert text.
$xss = [
    ['Name', 'Website', 'Photo', 'Contact', 'Notes', 'Category'],
    ['<script>alert(1)</script>', 'javascript:alert(1)', 'javascript:alert(2)', 'x@example.org', '</script><script>alert(3)</script>', 'A'],
    ['"><img src=x onerror=alert(1)>', 'https://example.org/ok', 'https://example.org/x.png" onerror="alert(4)', 'bad"@x', '<!-- </script>', 'A'],
    ["'><svg onload=alert(5)>", 'JaVaScRiPt:alert(6)', 'data:image/svg+xml,<svg onload=alert(7)>', 'y@example.org', '{{constructor.constructor(\'alert(8)\')()}}', 'B'],
    ['Normal row', 'https://example.org/', '', 'z@example.org', '=HYPERLINK("http://evil.example","x")', 'B'],
];
file_put_contents("$fx/xss.csv", csv($xss));
file_put_contents("$fx/xss.showcase.json", json_encode([
    'title' => '<script>alert("title")</script>',
    'subtitle' => '</script><img src=x onerror=alert(9)>',
    'filters' => ['Category'],
    'columns' => [
        ['name' => 'Name', 'type' => 'text'],
        ['name' => 'Website', 'type' => 'url'],
        ['name' => 'Photo', 'type' => 'image'],
        ['name' => 'Contact', 'type' => 'email'],
        ['name' => 'Notes', 'type' => 'text'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// A file outside data/ that path traversal must never reach.
file_put_contents("$fx/secret.csv", "Secret\nTOP-SECRET-VALUE\n");

echo "Samples written to data/, fixtures to tests/php/fixtures/\n";
