<?php

declare(strict_types=1);

use TimurTurdyev\SimpleSearch\Manticore\Writer;
use TimurTurdyev\SimpleSearch\Tests\Support\FakeEngine;

/**
 * @return Generator<int, array<string, int|string>>
 */
function engine_writer_stream(int $count): Generator
{
    for ($id = 1; $id <= $count; $id++) {
        yield ['id' => $id, 'title' => "item {$id}"];
    }
}

it('escapes quotes and backslashes in a string literal', function (string $value, string $literal): void {
    expect(Writer::literal($value))->toBe($literal);
})->with([
    'a quote' => ["it's", "'it\\'s'"],
    'a backslash' => ['a\b', "'a\\\\b'"],
    // The backslash goes first, or the one added for the quote would be doubled.
    'a backslash before a quote' => ["\\'", "'\\\\\\''"],
    // The engine cuts a string at NUL.
    'a NUL byte' => ["a\0b", "'ab'"],
]);

it('writes a list as a tuple of integers and numbers as they are', function (): void {
    expect(Writer::literal([30, 10, 20]))->toBe('(30,10,20)')
        ->and(Writer::literal([]))->toBe('()')
        ->and(Writer::literal(42))->toBe('42')
        ->and(Writer::literal(-7))->toBe('-7')
        ->and(Writer::literal(1.5))->toBe('1.5');
});

it('refuses a number the engine would store as 0', function (float $value): void {
    expect(fn () => Writer::literal($value))->toThrow(LogicException::class);
})->with(['NAN' => NAN, 'INF' => INF, '-INF' => -INF]);

it('splits the stream into statements of the chunk size and counts the documents', function (): void {
    $engine = new FakeEngine;
    $writer = new Writer($engine);

    $written = $writer->insert('catalog', ['id', 'title'], engine_writer_stream(5), 2);
    $nothing = $writer->insert('catalog', ['id', 'title'], engine_writer_stream(0), 2);

    expect([$written, $nothing])->toBe([5, 0])
        ->and($engine->statements)->toBe([
            "INSERT INTO catalog (id, title) VALUES (1, 'item 1'), (2, 'item 2')",
            "INSERT INTO catalog (id, title) VALUES (3, 'item 3'), (4, 'item 4')",
            "INSERT INTO catalog (id, title) VALUES (5, 'item 5')",
        ]);
});

it('orders the values by the column list, not by the document', function (): void {
    $engine = new FakeEngine;

    // Zero, an empty string and an empty list are values, not gaps.
    (new Writer($engine))->replace('catalog', ['id', 'title', 'code', 'sections', 'price', 'width'], [
        ['width' => 0.75, 'price' => 0, 'unlisted' => 'skipped', 'sections' => [], 'code' => '', 'title' => "it's", 'id' => 7],
    ]);

    expect($engine->statements)->toBe([
        "REPLACE INTO catalog (id, title, code, sections, price, width) VALUES (7, 'it\\'s', '', (), 0, 0.75)",
    ]);
});

it('refuses a document that has no value for a column', function (): void {
    $engine = new FakeEngine;

    expect(fn () => (new Writer($engine))->insert('catalog', ['id', 'title'], [['id' => 1]]))
        ->toThrow(LogicException::class, 'title')
        ->and($engine->statements)->toBe([]);
});
