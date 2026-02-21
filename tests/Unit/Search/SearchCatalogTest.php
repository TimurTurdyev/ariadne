<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TimurTurdyev\SimpleSearch\Facets\DisjunctivePolicy;
use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Facets\FacetOperator;
use TimurTurdyev\SimpleSearch\Facets\FacetValueId;
use TimurTurdyev\SimpleSearch\Query\Constraint;
use TimurTurdyev\SimpleSearch\Query\FacetChoice;
use TimurTurdyev\SimpleSearch\Query\PageRequest;
use TimurTurdyev\SimpleSearch\Query\PriceRange;
use TimurTurdyev\SimpleSearch\Query\PriceScale;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\Scope;
use TimurTurdyev\SimpleSearch\Query\Selection;
use TimurTurdyev\SimpleSearch\Results\Hit;
use TimurTurdyev\SimpleSearch\Results\IndexResponse;
use TimurTurdyev\SimpleSearch\Results\PriceBounds;
use TimurTurdyev\SimpleSearch\Search\SearchCatalog;
use TimurTurdyev\SimpleSearch\Tests\Support\InMemoryFacetDictionary;
use TimurTurdyev\SimpleSearch\Tests\Support\RecordingIndex;

function core_screen_scope(): Scope
{
    return new Scope('section', [Constraint::in('sections', [10])]);
}

function core_screen_index(): RecordingIndex
{
    return new RecordingIndex(new IndexResponse(
        [
            new Hit(1, ['model' => 10, 'price' => 10000], ['price' => 10000.0, 'special' => 3000.0, 'name' => 'Стол']),
            new Hit(2, ['model' => 11, 'price' => 20000], ['price' => 20000.0, 'name' => 'Кресло']),
        ],
        2,
        ['main' => [1 => 2, 2 => 1]],
        new PriceBounds(1000.0, 50000.0),
    ));
}

function core_screen_catalog(RecordingIndex $index, LoggerInterface $log = new NullLogger): SearchCatalog
{
    return new SearchCatalog(
        $index,
        (new InMemoryFacetDictionary)->add('brend', 'Бренд', ['riva' => 'RIVA', 'stil' => 'Стиль']),
        new DisjunctivePolicy,
        cache: new Repository(new ArrayStore),
        log: $log,
        displayPrices: core_screen_display_prices(...),
    );
}

/**
 * @return array{0: LoggerInterface, 1: ArrayObject<int, array{level: mixed, context: array<mixed>}>} a logger and its records
 */
function core_screen_logger(): array
{
    $records = new ArrayObject;

    $logger = new class($records) extends AbstractLogger
    {
        /**
         * @param  ArrayObject<int, array{level: mixed, context: array<mixed>}>  $records
         */
        public function __construct(private readonly ArrayObject $records) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records->append(['level' => $level, 'context' => $context]);
        }
    };

    return [$logger, $records];
}

/**
 * The container the framework runs deferred callbacks with after the response.
 *
 * @return array{0: DeferredCallbackCollection, 1: ArrayObject<int, Throwable>} the deferred callbacks and what reached the error handler
 */
function core_screen_after_response(): array
{
    $reported = new ArrayObject;
    $container = new Container;
    $container->singleton(DeferredCallbackCollection::class);
    $container->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
    {
        /**
         * @param  ArrayObject<int, Throwable>  $reported
         */
        public function __construct(private readonly ArrayObject $reported) {}

        public function report(Throwable $e): void
        {
            $this->reported->append($e);
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });
    Container::setInstance($container);

    return [$container->make(DeferredCallbackCollection::class), $reported];
}

/**
 * @param  ArrayObject<int, array{level: mixed, context: array<mixed>}>  $records
 * @return list<array<mixed>>
 */
function core_screen_warnings(ArrayObject $records): array
{
    $warnings = [];

    foreach ($records as $record) {
        if ($record['level'] === LogLevel::WARNING) {
            $warnings[] = $record['context'];
        }
    }

    return $warnings;
}

function core_screen_brand(FacetOperator $operator = FacetOperator::Any): Selection
{
    return new Selection([
        new FacetChoice(FacetId::fromString('brend'), [FacetValueId::fromString('riva')], $operator),
    ]);
}

function core_screen_display_prices(Hit $hit, PriceScale $scale): Hit
{
    $payload = $hit->payload;

    foreach (['price', 'special'] as $field) {
        if (isset($payload[$field]) && is_numeric($payload[$field])) {
            $payload[$field] = $scale->toDisplay((float) $payload[$field]);
        }
    }

    return $hit
        ->withAttributes([...$hit->attributes, 'price' => (int) $scale->toDisplay((float) $hit->int('price'))])
        ->withPayload($payload);
}

it('answers an engine outage with an empty screen and does not cache it', function (): void {
    $index = core_screen_index();
    $catalog = core_screen_catalog($index);

    $index->fail = true;

    $first = $catalog(new Query(core_screen_scope()));

    expect($first->isEmpty())->toBeTrue('an outage answers with an empty screen, not an exception')
        ->and($first->degraded)->toBeTrue()
        ->and($index->calls)->toBe(1);

    $catalog(new Query(core_screen_scope()));

    expect($index->calls)->toBe(2, 'the outage answer did not get into the cache');

    $index->fail = false;

    $recovered = $catalog(new Query(core_screen_scope()));

    expect($recovered->isEmpty())->toBeFalse('once the engine is back the results are live at once');
});

it('keeps the stale screen while the engine is down during a refresh', function (): void {
    [$deferred, $reported] = core_screen_after_response();
    [$log, $records] = core_screen_logger();
    $index = core_screen_index();
    $catalog = core_screen_catalog($index, $log);
    $query = new Query(core_screen_scope());

    try {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $cached = $catalog($query);

        Carbon::setTestNow('2026-11-14 10:06:40');
        $index->fail = true;
        $stale = $catalog($query);
        $deferred->invoke();

        $warnings = core_screen_warnings($records);

        expect($stale)->toEqual($cached, 'the buyer gets the screen built before the outage')
            ->and($index->calls)->toBe(2, 'the refresh ran after the response')
            ->and($reported->getArrayCopy())->toBe([], 'the outage did not reach the error handler')
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0]['error'] ?? null)->toBe('engine down')
            ->and($warnings[0]['key'] ?? '')->toStartWith('simple-search:screen:');

        Carbon::setTestNow('2026-11-14 10:10:00');
        $kept = $catalog($query);

        expect($kept->degraded)->toBeFalse()
            ->and($kept)->toEqual($cached)
            ->and($index->calls)->toBe(2, 'the kept screen is fresh again, the engine is not asked')
            ->and($deferred)->toHaveCount(0);

        $index->fail = false;
        $index->response = new IndexResponse([], 7);

        Carbon::setTestNow('2026-11-14 10:12:00');
        $catalog($query);
        $deferred->invoke();
        $renewed = $catalog($query);

        expect($renewed->page->total)->toBe(7, 'once the engine is back the refresh stores the new screen')
            ->and($index->calls)->toBe(3)
            ->and($reported->getArrayCopy())->toBe([]);
    } finally {
        Carbon::setTestNow();
        Container::setInstance(null);
    }
});

it('reads a cached screen back from a store that allows no classes, as Laravel 13 does by default', function (): void {
    [$deferred, $reported] = core_screen_after_response();
    $dictionary = InMemoryFacetDictionary::catalog();
    $response = new IndexResponse(
        [new Hit(1, ['model' => 10, 'price' => 10000], ['name' => 'Стол', 'sizes' => [62, 68]], ['variants' => [['id' => 5, 'price' => 9000.0]], 'family' => 7])],
        1,
        [
            'main' => [$dictionary->termId('brand', 'riva') => 1],
            'palette' => [
                $dictionary->termId('color', 'venge') => 3,
                $dictionary->termId('uphol-material', 'kozha') => 2,
                $dictionary->termId('uphol-material', 'tkan') => 2,
                $dictionary->termId('brand', 'riva') => 2,
            ],
            'what-if:color' => [$dictionary->termId('color', 'venge') => 1],
            'what-if:uphol-material' => [$dictionary->termId('uphol-material', 'kozha') => 1],
        ],
        new PriceBounds(1000.0, 50000.0),
        owners: 1,
    );
    $index = new RecordingIndex($response);
    $catalog = new SearchCatalog(
        $index,
        $dictionary,
        // Serialize values, allow no classes; positional, as stores before Laravel 12.53 take one argument.
        cache: new Repository(new ArrayStore(true, false)),
    );
    $query = (new Query(new Scope('section', [Constraint::in('sections', [10])], 'model'), text: 'desk'))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('color'), [FacetValueId::fromString('venge')]),
            new FacetChoice(
                FacetId::fromString('uphol-material'),
                [FacetValueId::fromString('kozha'), FacetValueId::fromString('tkan')],
                FacetOperator::All,
            ),
        ]))
        ->withPrice(new PriceRange(1000.0, 40000.0))
        ->withSort('price-asc')
        ->withPage(new PageRequest(2, 24));

    $uncached = (new SearchCatalog(new RecordingIndex($response), $dictionary))($query);

    try {
        Carbon::setTestNow('2026-11-16 10:00:00');
        $built = $catalog($query);
        $cached = $catalog($query);

        expect($built)->toEqual($uncached, 'nothing is lost on the way through the cache')
            ->and($cached)->toEqual($uncached)
            ->and($index->calls)->toBe(1, 'the second screen came from the cache')
            ->and($cached->panel->groupFor(FacetId::fromString('uphol-material'))?->operator)->toBe(FacetOperator::All);

        Carbon::setTestNow('2026-11-16 10:06:40');
        $index->fail = true;
        $stale = $catalog($query);
        $deferred->invoke();

        Carbon::setTestNow('2026-11-16 10:08:00');
        $kept = $catalog($query);

        expect($stale)->toEqual($uncached)
            ->and($kept)->toEqual($uncached)
            ->and($index->calls)->toBe(2, 'the refresh met the outage')
            ->and($reported->getArrayCopy())->toBe([], 'the refresh read the stale screen back as well')
            ->and($deferred)->toHaveCount(0, 'the kept screen is fresh again');
    } finally {
        Carbon::setTestNow();
        Container::setInstance(null);
    }
});

it('moves the price filter down to the base scale', function (): void {
    $index = core_screen_index();

    // The corridor opens by half a display step each way: the shown price is rounded.
    core_screen_catalog($index)(
        (new Query(core_screen_scope()))->withPrice(new PriceRange(12200.0, 61000.0)),
        new PriceScale(1.22),
    );

    $executed = $index->lastPlan()->query;

    expect($executed->price->min)->toEqualWithDelta(9999.59, 0.01)
        ->and($executed->price->max)->toEqualWithDelta(50000.41, 0.01)
        ->and($executed->price->min)->toBeLessThan(10000.0, 'the lower bound is let down')
        ->and($executed->price->max)->toBeGreaterThan(50000.0, 'the upper bound is let up');
});

it('finds an item by the price shown to the buyer', function (): void {
    $scale = new PriceScale(1.22);

    // 1001 in the base scale is shown as 1221 (1221.22 rounded).
    $shown = $scale->toDisplay(1001.0);

    expect($shown)->toBe(1221.0)
        ->and($scale->toBaseFrom($shown))->toBeLessThanOrEqual(1001.0)
        ->and($scale->toBaseTo($shown))->toBeGreaterThanOrEqual(1001.0);
});

it('returns prices and bounds in the display scale', function (): void {
    $result = core_screen_catalog(core_screen_index())(new Query(core_screen_scope()), new PriceScale(1.22));

    expect($result->page->hits[0]->attributes['price'])->toBe(12200, 'the hit price is rescaled')
        ->and($result->page->hits[0]->payload['price'])->toBe(12200.0, 'the price in the card data too')
        ->and($result->page->hits[0]->payload['special'])->toBe(3660.0, 'the special price is not forgotten')
        ->and($result->priceBounds->min)->toBe(1220.0)
        ->and($result->priceBounds->max)->toBe(61000.0);
});

it('leaves the selected range in the user scale', function (): void {
    $query = (new Query(core_screen_scope()))->withPrice(new PriceRange(12200.0, 61000.0));

    $result = core_screen_catalog(core_screen_index())($query, new PriceScale(1.22));

    expect($result->query->price->min)->toBe(12200.0, 'the answer carries the numbers the user typed')
        ->and($result->query->price->max)->toBe(61000.0);
});

it('does not share the cache between screens of different scales', function (): void {
    $index = core_screen_index();
    $catalog = core_screen_catalog($index);
    $query = new Query(core_screen_scope());

    $base = $catalog($query);
    $catalog($query);
    $regional = $catalog($query, new PriceScale(1.22));

    expect($index->calls)->toBe(2, 'the repeat is served from the cache, another scale is not')
        ->and($base->page->hits[0]->attributes['price'])->toBe(10000)
        ->and($regional->page->hits[0]->attributes['price'])->toBe(12200);

    $catalog($query, new PriceScale(0.00001));
    $catalog($query, new PriceScale(0.00004));

    expect($index->calls)->toBe(4, 'scales that differ past the fourth decimal are different screens');
});

it('drops a price bound that is not a finite number', function (): void {
    $index = core_screen_index();

    $result = core_screen_catalog($index)(
        (new Query(core_screen_scope()))->withPrice(new PriceRange(INF, 5000.0)),
    );

    expect($result->query->price->min)->toBeNull()
        ->and($result->query->price->max)->toBe(5000.0)
        ->and($index->lastPlan()->query->price->min)->toBeNull()
        ->and((new PriceRange(NAN, INF))->isEmpty())->toBeTrue();
});

it('keeps a cache entry per page, selection, sort, scope and caller part', function (): void {
    $index = core_screen_index();
    $catalog = core_screen_catalog($index);
    $query = (new Query(core_screen_scope()))->withText('desk');

    $catalog($query, cacheKeyParts: ['north']);
    $catalog($query, cacheKeyParts: ['north']);

    expect($index->calls)->toBe(1, 'the repeat is served from the cache');

    $others = [
        'page' => [$query->withPage(new PageRequest(2)), ['north']],
        'selection' => [$query->withSelection(core_screen_brand()), ['north']],
        'sort' => [$query->withSort('price-asc'), ['north']],
        'scope' => [new Query(new Scope('section', [Constraint::in('sections', [11])]), text: 'desk'), ['north']],
        'caller part' => [$query, ['south']],
    ];

    foreach ($others as $what => [$other, $parts]) {
        $before = $index->calls;

        $catalog($other, cacheKeyParts: $parts);

        expect($index->calls)->toBe($before + 1, "another {$what} is another screen");
    }
});

it('does not serve the prefix retry of a text to the same text typed with the mark', function (): void {
    $index = core_screen_index();
    $catalog = core_screen_catalog($index);
    $query = new Query(new Scope('search'));

    $catalog($query->withText('desk')->withPrefix());
    $typed = $catalog($query->withText('desk|prefix'));

    expect($index->calls)->toBe(2)
        ->and($typed->query->prefix)->toBeFalse()
        ->and($typed->query->text)->toBe('desk|prefix');
});

it('does not serve a screen cached for the all operator to a plain choice of the same value', function (): void {
    $index = core_screen_index();
    $catalog = core_screen_catalog($index);
    $query = new Query(core_screen_scope());

    $catalog($query->withSelection(core_screen_brand(FacetOperator::All)));
    $plain = $catalog($query->withSelection(core_screen_brand()));

    expect($index->calls)->toBe(2)
        ->and($plain->panel->groupFor(FacetId::fromString('brend'))?->operator)->toBe(FacetOperator::Any);
});
