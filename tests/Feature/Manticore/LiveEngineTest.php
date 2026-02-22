<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use PHPUnit\Framework\Assert;
use TimurTurdyev\Ariadne\Index\CatalogIndexUnavailable;
use TimurTurdyev\Ariadne\Manticore\AttachShadow;
use TimurTurdyev\Ariadne\Manticore\Connection;
use TimurTurdyev\Ariadne\Manticore\SwapCopies;
use TimurTurdyev\Ariadne\Manticore\Table;
use TimurTurdyev\Ariadne\Manticore\Writer;

function engine_live_address(): ?string
{
    $address = getenv('ARIADNE_MANTICORE');

    return is_string($address) && $address !== '' ? $address : null;
}

function engine_live_connection(?int $readTimeout = null): Connection
{
    $parts = explode(':', engine_live_address() ?? '', 2);

    return Connection::fromConfig(['host' => $parts[0], 'port' => $parts[1] ?? null, 'read_timeout' => $readTimeout]);
}

/**
 * @return list<string> the test tables the engine has now, sorted
 */
function engine_live_tables(Connection $engine): array
{
    $tables = [];

    foreach ($engine->select('SHOW TABLES') as $row) {
        $table = reset($row);

        if (is_string($table) && str_starts_with($table, 'ariadne_it_')) {
            $tables[] = $table;
        }
    }

    sort($tables);

    return $tables;
}

function engine_live_cleanup(Connection $engine): void
{
    foreach (engine_live_tables($engine) as $table) {
        $engine->execute('DROP TABLE IF EXISTS '.$table);
    }
}

/**
 * Runs a case on an engine without test tables and removes the ones it leaves.
 *
 * @param  Closure(Connection): void  $case
 */
function engine_live_case(Closure $case): void
{
    $engine = engine_live_connection();
    engine_live_cleanup($engine);

    try {
        $case($engine);
    } finally {
        engine_live_cleanup($engine);
    }
}

/**
 * @return list<array<string, string>> rows with every value as text: drivers disagree on numbers
 */
function engine_live_select(Connection $engine, string $sql): array
{
    $rows = [];

    foreach ($engine->select($sql) as $row) {
        $rows[] = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row);
    }

    return $rows;
}

function engine_live_count(Connection $engine, string $table): int
{
    $row = engine_live_select($engine, 'SELECT COUNT(*) FROM '.$table)[0] ?? [];

    return (int) reset($row);
}

/**
 * @return list<string> how the table in the engine differs from the schema
 */
function engine_live_drift(Connection $engine, Table $table, string $name): array
{
    $created = engine_live_select($engine, 'SHOW CREATE TABLE '.$name)[0]['Create Table'] ?? '';

    return $table->drift($engine->select('DESCRIBE '.$name), $created);
}

/**
 * A main table and its companion.
 *
 * @return non-empty-list<Table>
 */
function engine_live_set(): array
{
    return [
        new Table(['title' => 'text', 'price' => 'int'], ['min_infix_len' => '2']),
        new Table(['title' => 'text'], ['min_infix_len' => '2'], suffix: '_fuzzy'),
    ];
}

/**
 * What an application passes as $fill: the same documents for every table of the set.
 *
 * @param  non-empty-list<Table>  $tables
 * @return Closure(string): int
 */
function engine_live_fill(Connection $engine, array $tables, int $documents): Closure
{
    return static function (string $target) use ($engine, $tables, $documents): int {
        $rows = [];

        for ($id = 1; $id <= $documents; $id++) {
            $rows[] = ['id' => $id, 'title' => "sofa {$id}", 'price' => $id * 100, 'brand' => 7];
        }

        foreach ($tables as $table) {
            (new Writer($engine))->insert($table->name($target), ['id', ...array_keys($table->columns)], $rows);
        }

        return $documents;
    };
}

/**
 * @return ArrayObject<string, string> the stored pointer: name => live copy
 */
function engine_live_pointer(): ArrayObject
{
    return new ArrayObject([]);
}

it('writes documents and reads them back unchanged', function (): void {
    engine_live_case(function (Connection $engine): void {
        $name = 'ariadne_it_writer';
        $table = new Table([
            'title' => 'text', 'code' => 'string attribute', 'price' => 'bigint', 'sections' => 'multi', 'width' => 'float',
        ]);
        $engine->execute($table->create($name));

        $written = (new Writer($engine))->insert($name, ['id', 'title', 'code', 'price', 'sections', 'width'], [
            ['id' => 1, 'title' => "it's a sofa", 'code' => 'a\b', 'price' => 150000, 'sections' => [10, 20], 'width' => 0.75],
            ['id' => 2, 'title' => 'back\slash', 'code' => "\\'", 'price' => 9000000000, 'sections' => [], 'width' => 2.0],
            ['id' => 3, 'title' => 'plain chair', 'code' => 'c-3', 'price' => 0, 'sections' => [30], 'width' => 1.5],
        ], 2);

        $rows = engine_live_select($engine, "SELECT id, title, code, price, sections FROM {$name} ORDER BY id ASC");
        $widths = engine_live_select($engine, "SELECT width FROM {$name} ORDER BY id ASC");

        expect($written)->toBe(3)
            ->and($rows)->toBe([
                ['id' => '1', 'title' => "it's a sofa", 'code' => 'a\b', 'price' => '150000', 'sections' => '10,20'],
                ['id' => '2', 'title' => 'back\slash', 'code' => "\\'", 'price' => '9000000000', 'sections' => ''],
                ['id' => '3', 'title' => 'plain chair', 'code' => 'c-3', 'price' => '0', 'sections' => '30'],
            ])
            ->and(array_map(static fn (array $row): float => (float) reset($row), $widths))->toBe([0.75, 2.0, 1.5])
            ->and(engine_live_drift($engine, $table, $name))->toBe([]);
    });
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('publishes into the copies a -> b -> a', function (): void {
    engine_live_case(function (Connection $engine): void {
        $name = 'ariadne_it_swap';
        $tables = engine_live_set();
        $pointer = engine_live_pointer();
        $swap = new SwapCopies(
            $engine,
            new ArrayStore,
            static fn (string $name): ?string => $pointer[$name] ?? null,
            static function (string $name, string $table) use ($pointer): void {
                $pointer[$name] = $table;
            },
            waitSeconds: 0,
        );

        $before = $swap->live($name);
        $first = $swap->rebuild($name, $tables, engine_live_fill($engine, $tables, 2));
        $second = $swap->rebuild($name, $tables, engine_live_fill($engine, $tables, 3));

        expect([$before, $first->table, $second->table, $swap->live($name)])
            ->toBe([$name.'_a', $name.'_b', $name.'_a', $name.'_a'])
            ->and([$first->documents, $second->documents])->toBe([2, 3])
            ->and(engine_live_count($engine, $name.'_a'))->toBe(3)
            ->and(engine_live_count($engine, $name.'_a_fuzzy'))->toBe(3)
            // The previous copy stays for the queries that started on it.
            ->and(engine_live_count($engine, $name.'_b'))->toBe(2);
    });
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('attaches a rebuilt shadow to the live table and leaves no shadow behind', function (): void {
    engine_live_case(function (Connection $engine): void {
        $name = 'ariadne_it_attach';
        $tables = engine_live_set();
        $attach = new AttachShadow($engine, new ArrayStore);

        $first = $attach->rebuild($name, $tables, engine_live_fill($engine, $tables, 5));

        expect([$first->table, $first->documents])->toBe([$name, 5])
            ->and(engine_live_count($engine, $name))->toBe(5)
            ->and(engine_live_drift($engine, $tables[0], $name))->toBe([]);

        // Fewer documents than before: the attach has to replace the old ones, not add to them.
        $second = $attach->rebuild($name, $tables, engine_live_fill($engine, $tables, 2));

        expect($second->documents)->toBe(2)
            ->and(engine_live_count($engine, $name))->toBe(2)
            ->and(engine_live_count($engine, $name.'_fuzzy'))->toBe(2)
            ->and(engine_live_tables($engine))->toBe([$name, $name.'_fuzzy'])
            ->and(engine_live_select($engine, "SELECT id FROM {$name} WHERE MATCH('sofa') ORDER BY id ASC"))
            ->toBe([['id' => '1'], ['id' => '2']]);
    });
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('switches the live table to another schema together with the data', function (): void {
    engine_live_case(function (Connection $engine): void {
        $name = 'ariadne_it_schema';
        $attach = new AttachShadow($engine, new ArrayStore);
        $old = [new Table(
            ['title' => 'text', 'price' => 'int'],
            ['morphology' => 'stem_en', 'min_infix_len' => '2', 'expand_keywords' => 'exact'],
            ['expand_keywords'],
        )];
        // A column in the middle, another type, another text setting and another mutable one.
        $new = [new Table(
            ['title' => 'text', 'brand' => 'int', 'price' => 'bigint'],
            ['morphology' => 'stem_en', 'min_infix_len' => '3', 'expand_keywords' => 'star'],
            ['expand_keywords'],
        )];

        $attach->rebuild($name, $old, engine_live_fill($engine, $old, 2));
        $attach->rebuild($name, $new, engine_live_fill($engine, $new, 3));

        expect(engine_live_select($engine, "SELECT id, brand, price FROM {$name} ORDER BY id ASC"))
            ->toBe([
                ['id' => '1', 'brand' => '7', 'price' => '100'],
                ['id' => '2', 'brand' => '7', 'price' => '200'],
                ['id' => '3', 'brand' => '7', 'price' => '300'],
            ])
            ->and(engine_live_drift($engine, $new[0], $name))->toBe([])
            ->and(engine_live_tables($engine))->toBe([$name]);

        // And back: the column goes away with the rebuild that no longer writes it.
        $attach->rebuild($name, $old, engine_live_fill($engine, $old, 2));

        expect(engine_live_drift($engine, $old[0], $name))->toBe([])
            ->and(engine_live_select($engine, "SELECT id FROM {$name} WHERE MATCH('sofas') ORDER BY id ASC"))
            ->toBe([['id' => '1'], ['id' => '2']]);
    });
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('keeps a regexp filter with backslashes and reports it once the schema drops it', function (): void {
    engine_live_case(function (Connection $engine): void {
        $name = 'ariadne_it_filter';
        $attach = new AttachShadow($engine, new ArrayStore);
        $filtered = [new Table(['title' => 'text', 'price' => 'int'], ['regexp_filter' => '(\\d+)x(\\d+) => \\1 x \\2'])];
        $plain = [new Table(['title' => 'text', 'price' => 'int'])];
        $fill = static fn (Table $table): Closure => static function (string $target) use ($engine, $table): int {
            return (new Writer($engine))->insert($table->name($target), ['id', 'title', 'price'], [
                ['id' => 1, 'title' => 'table 120x60', 'price' => 100],
            ]);
        };

        $attach->rebuild($name, $filtered, $fill($filtered[0]));

        expect(engine_live_select($engine, "SELECT id FROM {$name} WHERE MATCH('120 x 60')"))->toBe([['id' => '1']])
            ->and(engine_live_drift($engine, $filtered[0], $name))->toBe([]);

        // ATTACH leaves the filter on the live table, so drift has to show it.
        $attach->rebuild($name, $plain, $fill($plain[0]));

        expect(engine_live_drift($engine, $plain[0], $name))
            ->toBe(["unexpected setting regexp_filter='(\\\\d+)x(\\\\d+) => \\\\1 x \\\\2'"]);
    });
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('binds integers as numbers', function (): void {
    engine_live_case(function (Connection $engine): void {
        $name = 'ariadne_it_bind';
        $table = new Table(['title' => 'text', 'price' => 'int']);
        $engine->execute($table->create($name));
        (new Writer($engine))->insert($name, ['id', 'title', 'price'], [
            ['id' => 1, 'title' => 'sofa', 'price' => 100],
            ['id' => 2, 'title' => 'sofa', 'price' => 3000],
            ['id' => 3, 'title' => 'sofa', 'price' => 5000],
            ['id' => 4, 'title' => 'chair', 'price' => 5000],
        ]);

        $rows = $engine->select("SELECT id FROM {$name} WHERE price > ? AND MATCH(?) ORDER BY id ASC LIMIT ?", [200, 'sofa', 5]);

        expect(array_map(static fn (array $row): string => is_scalar($row['id']) ? (string) $row['id'] : '', $rows))->toBe(['2', '3']);
    });
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('opens the connection again after the engine closed it', function (): void {
    $engine = engine_live_connection();
    $engine->execute('SET wait_timeout=1');
    sleep(2);

    expect(engine_live_select($engine, 'SELECT 1 AS one'))->toBe([['one' => '1']]);
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');

it('gives up on an answer past the read timeout without a retry and keeps the process setting', function (): void {
    $engine = engine_live_connection(readTimeout: 1);
    $setting = ini_get('mysqlnd.net_read_timeout');

    try {
        $engine->select('DEBUG SLEEP 0');
    } catch (PDOException $e) {
        Assert::markTestSkipped('the engine does not know DEBUG SLEEP: '.$e->getMessage());
    }

    $started = microtime(true);

    expect(fn () => $engine->select('DEBUG SLEEP 2'))->toThrow(CatalogIndexUnavailable::class)
        ->and(microtime(true) - $started)->toBeLessThan(1.8)
        ->and(ini_get('mysqlnd.net_read_timeout'))->toBe($setting)
        ->and(engine_live_select($engine, 'SELECT 1 AS one'))->toBe([['one' => '1']]);
})->skip(engine_live_address() === null, 'ARIADNE_MANTICORE is not set');
