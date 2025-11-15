<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use TimurTurdyev\SimpleSearch\Manticore\AttachShadow;
use TimurTurdyev\SimpleSearch\Manticore\Publication;
use TimurTurdyev\SimpleSearch\Manticore\Published;
use TimurTurdyev\SimpleSearch\Manticore\PublishFailed;
use TimurTurdyev\SimpleSearch\Manticore\PublishRefused;
use TimurTurdyev\SimpleSearch\Manticore\SwapCopies;
use TimurTurdyev\SimpleSearch\Manticore\Table;
use TimurTurdyev\SimpleSearch\Tests\Support\FakeEngine;

/**
 * A main table with one setting ATTACH does not carry over, and its companion.
 *
 * @return non-empty-list<Table>
 */
function engine_set(): array
{
    return [
        new Table(
            ['title' => 'text', 'price' => 'int'],
            ['min_infix_len' => '2', 'rt_mem_limit' => '256M'],
            ['rt_mem_limit', 'optimize_cutoff'],
        ),
        new Table(['title' => 'text'], ['min_infix_len' => '2'], suffix: '_fuzzy'),
    ];
}

/**
 * What an application passes as $fill: $stored documents reach both tables, $reported is what it believes it wrote.
 *
 * @return Closure(string): int
 */
function engine_fill(FakeEngine $engine, int $stored, ?int $reported = null, ?int $companion = null): Closure
{
    return static function (string $target) use ($engine, $stored, $reported, $companion): int {
        $engine->statements[] = "fill {$target}";
        $engine->tables[$target] = $stored;
        $engine->tables[$target.'_fuzzy'] = $companion ?? $stored;

        return $reported ?? $stored;
    };
}

/**
 * @return ArrayObject<string, string> the stored pointer: name => live copy
 */
function engine_pointer(?string $live = null): ArrayObject
{
    return new ArrayObject($live === null ? [] : ['catalog' => $live]);
}

/**
 * Copies with the pointer kept in memory; moving it leaves a mark in the statement log.
 *
 * @param  ArrayObject<string, string>  $pointer
 */
function engine_swap(FakeEngine $engine, ArrayObject $pointer, ?ArrayStore $locks = null, int $lockSeconds = 600): SwapCopies
{
    return new SwapCopies(
        $engine,
        $locks ?? new ArrayStore,
        static fn (string $name): ?string => $pointer[$name] ?? null,
        static function (string $name, string $table) use ($engine, $pointer): void {
            $engine->statements[] = "pointer {$name} = {$table}";
            $pointer[$name] = $table;
        },
        lockSeconds: $lockSeconds,
        waitSeconds: 0,
    );
}

function engine_publication(string $strategy, FakeEngine $engine, int $lockSeconds = 600): Publication
{
    return match ($strategy) {
        'swap copies' => engine_swap($engine, engine_pointer(), lockSeconds: $lockSeconds),
        'attach shadow' => new AttachShadow($engine, new ArrayStore, lockSeconds: $lockSeconds),
        default => throw new LogicException("Unknown strategy {$strategy}."),
    };
}

/**
 * The live tables of engine_set() as an earlier rebuild left them.
 */
function engine_published(FakeEngine $engine, int $documents = 5): void
{
    $engine->tables['catalog'] = $documents;
    $engine->tables['catalog_fuzzy'] = $documents;
}

/**
 * @param  Closure(): Published  $rebuild
 */
function engine_refusal(Closure $rebuild): ?PublishRefused
{
    try {
        $rebuild();
    } catch (PublishRefused $refused) {
        return $refused;
    }

    return null;
}

/**
 * Whether a rebuild of "catalog" is running: another one could not start now.
 */
function engine_rebuild_locked(ArrayStore $locks): bool
{
    $lock = $locks->lock('simple-search.rebuild.catalog', 60);

    if ($lock->get()) {
        $lock->release();

        return false;
    }

    return true;
}

/**
 * @return list<string> statements that switch readers
 */
function engine_switches(FakeEngine $engine): array
{
    return array_values(array_filter(
        $engine->statements,
        static fn (string $sql): bool => str_starts_with($sql, 'ATTACH') || str_starts_with($sql, 'pointer'),
    ));
}

it('fills the spare copy under a lock and moves the pointer only after the gate', function (): void {
    $engine = new FakeEngine;
    $pointer = engine_pointer();
    $locks = new ArrayStore;
    $fill = engine_fill($engine, 3);
    $lockedWhileFilling = false;

    $published = engine_swap($engine, $pointer, $locks)->rebuild(
        'catalog',
        engine_set(),
        function (string $target) use ($fill, $locks, &$lockedWhileFilling): int {
            $lockedWhileFilling = engine_rebuild_locked($locks);

            return $fill($target);
        },
    );

    // Nothing is published yet, so "catalog_a" is live by name only and "catalog_b" is the spare.
    expect($engine->statements)->toBe([
        'DROP TABLE IF EXISTS catalog_b',
        "CREATE TABLE catalog_b (title text, price int) min_infix_len='2' rt_mem_limit='256M'",
        'DROP TABLE IF EXISTS catalog_b_fuzzy',
        "CREATE TABLE catalog_b_fuzzy (title text) min_infix_len='2'",
        'fill catalog_b',
        'SELECT COUNT(*) FROM catalog_b',
        'SHOW TABLES',
        'SELECT COUNT(*) FROM catalog_b_fuzzy',
        'pointer catalog = catalog_b',
    ])
        ->and($published->table)->toBe('catalog_b')
        ->and($published->documents)->toBe(3)
        ->and($lockedWhileFilling)->toBeTrue()
        ->and(engine_rebuild_locked($locks))->toBeFalse();
});

it('leaves the pointer in place when the fill fails or the gate refuses', function (): void {
    $engine = new FakeEngine;
    $engine->tables['catalog_b'] = 5;
    $engine->tables['catalog_b_fuzzy'] = 5;
    $pointer = engine_pointer('catalog_b');
    $swap = engine_swap($engine, $pointer);

    expect(fn () => $swap->rebuild('catalog', engine_set(), function (): int {
        throw new DomainException('the source is down');
    }))->toThrow(DomainException::class, 'the source is down');

    expect(fn () => $swap->rebuild('catalog', engine_set(), engine_fill($engine, stored: 2, reported: 3)))
        ->toThrow(PublishRefused::class);

    expect($swap->live('catalog'))->toBe('catalog_b')
        ->and($engine->statements)->not->toContain('pointer catalog = catalog_a');
});

it('sends the next rebuild to the other copy and leaves the live one alone', function (): void {
    $engine = new FakeEngine;
    $swap = engine_swap($engine, engine_pointer());

    $before = $swap->live('catalog');
    $first = $swap->rebuild('catalog', engine_set(), engine_fill($engine, 3));
    $engine->statements = [];
    $second = $swap->rebuild('catalog', engine_set(), engine_fill($engine, 4));

    expect([$before, $first->table, $second->table, $swap->live('catalog')])
        ->toBe(['catalog_a', 'catalog_b', 'catalog_a', 'catalog_a'])
        ->and($engine->statements)->toBe([
            'DROP TABLE IF EXISTS catalog_a',
            "CREATE TABLE catalog_a (title text, price int) min_infix_len='2' rt_mem_limit='256M'",
            'DROP TABLE IF EXISTS catalog_a_fuzzy',
            "CREATE TABLE catalog_a_fuzzy (title text) min_infix_len='2'",
            'fill catalog_a',
            'SELECT COUNT(*) FROM catalog_a',
            'SHOW TABLES',
            'SELECT COUNT(*) FROM catalog_b',
            'SELECT COUNT(*) FROM catalog_a_fuzzy',
            'SELECT COUNT(*) FROM catalog_b_fuzzy',
            'pointer catalog = catalog_a',
        ]);
});

it('builds a shadow under a lock, flushes the set and attaches it one table at a time', function (): void {
    $engine = new FakeEngine;
    engine_published($engine);
    $locks = new ArrayStore;
    $fill = engine_fill($engine, 3);
    $lockedWhileFilling = false;

    $published = (new AttachShadow($engine, $locks))->rebuild(
        'catalog',
        engine_set(),
        function (string $target) use ($fill, $locks, &$lockedWhileFilling): int {
            $lockedWhileFilling = engine_rebuild_locked($locks);

            return $fill($target);
        },
    );

    expect($engine->statements)->toBe([
        'DROP TABLE IF EXISTS catalog_build',
        "CREATE TABLE catalog_build (title text, price int) min_infix_len='2' rt_mem_limit='256M'",
        'DROP TABLE IF EXISTS catalog_build_fuzzy',
        "CREATE TABLE catalog_build_fuzzy (title text) min_infix_len='2'",
        'fill catalog_build',
        'SELECT COUNT(*) FROM catalog_build',
        'SHOW TABLES',
        'SELECT COUNT(*) FROM catalog',
        'SELECT COUNT(*) FROM catalog_build_fuzzy',
        'SELECT COUNT(*) FROM catalog_fuzzy',
        'FLUSH RAMCHUNK catalog_build',
        'FLUSH RAMCHUNK catalog_build_fuzzy',
        // The live table takes the schema of the shadow.
        "CREATE TABLE IF NOT EXISTS catalog (title text, price int) min_infix_len='2' rt_mem_limit='256M'",
        'ATTACH TABLE catalog_build TO TABLE catalog WITH TRUNCATE',
        'DROP TABLE IF EXISTS catalog_build',
        'SHOW CREATE TABLE catalog',
        "ALTER TABLE catalog rt_mem_limit='256M'",
        "CREATE TABLE IF NOT EXISTS catalog_fuzzy (title text) min_infix_len='2'",
        'ATTACH TABLE catalog_build_fuzzy TO TABLE catalog_fuzzy WITH TRUNCATE',
        'DROP TABLE IF EXISTS catalog_build_fuzzy',
    ])
        ->and($published->table)->toBe('catalog')
        ->and($published->documents)->toBe(3)
        ->and($lockedWhileFilling)->toBeTrue()
        ->and(engine_rebuild_locked($locks))->toBeFalse();
});

it('sends no ALTER when the live table already has the mutable setting', function (): void {
    $engine = new FakeEngine;
    engine_published($engine);
    $engine->created['catalog'] = "CREATE TABLE catalog (\nid bigint,\ntitle text,\nprice integer\n) min_infix_len='2' rt_mem_limit='268435456'";

    (new AttachShadow($engine, new ArrayStore))->rebuild('catalog', engine_set(), engine_fill($engine, 3));

    expect($engine->statements)->toContain('SHOW CREATE TABLE catalog')
        ->and(array_filter($engine->statements, static fn (string $sql): bool => str_starts_with($sql, 'ALTER')))->toBe([]);
});

it('refuses a shadow that would be built over a live table', function (string $shadow): void {
    $engine = new FakeEngine;
    engine_published($engine);
    // The companion of engine_set() lives at catalog_fuzzy.
    $attach = new AttachShadow($engine, new ArrayStore, $shadow);

    expect(fn () => $attach->rebuild('catalog', engine_set(), engine_fill($engine, 3)))->toThrow(LogicException::class)
        ->and($engine->statements)->toBe([]);
})->with(['', '_fuzzy']);

it('tells which table failed to switch and whether it serves the new data', function (string $failOn, string $table, bool $switched, bool $partly): void {
    $engine = new FakeEngine;
    engine_published($engine);
    $engine->failOn($failOn);

    $failure = null;

    try {
        (new AttachShadow($engine, new ArrayStore))->rebuild('catalog', engine_set(), engine_fill($engine, 3));
    } catch (PublishFailed $caught) {
        $failure = $caught;
    }

    expect($failure?->getPrevious())->toBeInstanceOf(PDOException::class)
        ->and([$failure?->table, $failure?->switched])->toBe([$table, $switched])
        ->and(str_contains($failure?->getMessage() ?? '', 'switched before it'))->toBe($partly)
        ->and(str_starts_with(array_slice($engine->statements, -1)[0] ?? '', $failOn))->toBeTrue();
})->with([
    'the attach of the main table' => ['ATTACH TABLE catalog_build TO', 'catalog', false, false],
    'the ALTER after the attach' => ['ALTER TABLE catalog', 'catalog', true, false],
    'the attach of the companion' => ['ATTACH TABLE catalog_build_fuzzy', 'catalog_fuzzy', false, true],
]);

it('lets a failed flush through as it is: nothing is switched yet', function (): void {
    $engine = new FakeEngine;
    engine_published($engine);
    $engine->failOn('FLUSH RAMCHUNK catalog_build_fuzzy');

    expect(fn () => (new AttachShadow($engine, new ArrayStore))->rebuild('catalog', engine_set(), engine_fill($engine, 3)))
        ->toThrow(PDOException::class)
        ->and(engine_switches($engine))->toBe([]);
});

it('refuses a table that holds another number of documents than the fill reported', function (string $strategy): void {
    $engine = new FakeEngine;
    $publication = engine_publication($strategy, $engine);
    $live = $publication->live('catalog');
    $engine->tables[$live] = 5;

    $refused = engine_refusal(
        fn (): Published => $publication->rebuild('catalog', engine_set(), engine_fill($engine, stored: 2, reported: 3)),
    );

    // The gate is the last thing that happened: nothing was switched.
    expect([$refused?->reported, $refused?->built, $refused?->live])->toBe([3, 2, 5])
        ->and(array_slice($engine->statements, -1))->toBe(['SELECT COUNT(*) FROM '.$live]);
})->with(['swap copies', 'attach shadow']);

it('refuses to replace live documents with an empty table', function (string $strategy): void {
    $engine = new FakeEngine;
    $publication = engine_publication($strategy, $engine);
    $live = $publication->live('catalog');
    $engine->tables[$live] = 5;

    $refused = engine_refusal(
        fn (): Published => $publication->rebuild('catalog', engine_set(), engine_fill($engine, 0)),
    );

    expect([$refused?->reported, $refused?->built, $refused?->live])->toBe([0, 0, 5])
        ->and(array_slice($engine->statements, -1))->toBe(['SELECT COUNT(*) FROM '.$live]);
})->with(['swap copies', 'attach shadow']);

it('refuses an empty companion over a live one with documents', function (string $strategy): void {
    $engine = new FakeEngine;
    $publication = engine_publication($strategy, $engine);
    $live = $publication->live('catalog');
    $engine->tables[$live] = 5;
    $engine->tables[$live.'_fuzzy'] = 5;

    $refused = engine_refusal(
        fn (): Published => $publication->rebuild('catalog', engine_set(), engine_fill($engine, 6, companion: 0)),
    );

    expect([$refused?->built, $refused?->live])->toBe([0, 5])
        ->and($refused?->table)->toEndWith('_fuzzy')
        ->and(engine_switches($engine))->toBe([]);
})->with(['swap copies', 'attach shadow']);

it('finds the live table whatever case its name is given in', function (string $strategy): void {
    $engine = new FakeEngine;
    $publication = engine_publication($strategy, $engine);
    $live = $publication->live('Catalog');
    $engine->tables[strtolower($live)] = 3;

    $refused = engine_refusal(
        fn (): Published => $publication->rebuild('Catalog', engine_set(), engine_fill($engine, 0)),
    );

    expect($refused?->live)->toBe(3)
        ->and(engine_switches($engine))->toBe([]);
})->with(['swap copies', 'attach shadow']);

it('refuses to switch once the fill has outlived the lock', function (string $strategy): void {
    $engine = new FakeEngine;
    $fill = engine_fill($engine, 3);

    $refused = engine_refusal(
        fn (): Published => engine_publication($strategy, $engine, lockSeconds: 1)->rebuild(
            'catalog',
            engine_set(),
            static function (string $target) use ($fill): int {
                usleep(1_050_000);

                return $fill($target);
            },
        ),
    );

    expect($refused?->getMessage())->toContain('lock')
        ->and($refused?->built)->toBe(3)
        ->and(engine_switches($engine))->toBe([]);
})->with(['swap copies', 'attach shadow']);

it('publishes an empty table when there is no live one to lose', function (string $strategy): void {
    $engine = new FakeEngine;

    $published = engine_publication($strategy, $engine)->rebuild('catalog', engine_set(), engine_fill($engine, 0));

    expect($published->documents)->toBe(0)
        ->and($engine->statements)->not->toContain('SELECT COUNT(*) FROM catalog')
        ->and($engine->statements)->not->toContain('SELECT COUNT(*) FROM catalog_a');
})->with(['swap copies', 'attach shadow']);
