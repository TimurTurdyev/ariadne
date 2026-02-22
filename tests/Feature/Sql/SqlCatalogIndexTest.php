<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TimurTurdyev\Ariadne\Facets\DisjunctivePolicy;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetPanel;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Facets\KeyStyle;
use TimurTurdyev\Ariadne\Facets\ValueOrder;
use TimurTurdyev\Ariadne\Query\Constraint;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\Need;
use TimurTurdyev\Ariadne\Query\PriceRange;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Query\Scope;
use TimurTurdyev\Ariadne\Query\Selection;
use TimurTurdyev\Ariadne\Results\Hit;
use TimurTurdyev\Ariadne\Results\IndexResponse;
use TimurTurdyev\Ariadne\Search\SearchCatalog;
use TimurTurdyev\Ariadne\Sql\FacetTable;
use TimurTurdyev\Ariadne\Sql\FlatFacetDictionary;
use TimurTurdyev\Ariadne\Sql\SqlCatalog;
use TimurTurdyev\Ariadne\Sql\SqlCatalogIndex;

/**
 * A cup in three variants of one model and a plate; a hidden bowl and a tray of another section must never show.
 *
 * @return array{0: int, 1: int, 2: int, 3: int} ids of the green cup, the pink cup, the dotted pink cup and the plate
 */
function sql_seed(): array
{
    Schema::create('items', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->unsignedInteger('price');
        $table->unsignedInteger('sale_price')->nullable();
        $table->unsignedInteger('sales');
        $table->unsignedBigInteger('model_id')->nullable();
        $table->boolean('active');
    });

    Schema::create('item_facets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('item_id');
        $table->string('facet', 32);
        $table->string('value', 64);
        $table->string('label', 128);
        $table->unsignedInteger('sort_value');
    });

    Schema::create('item_section', static function (Blueprint $table): void {
        $table->unsignedBigInteger('section_id');
        $table->unsignedBigInteger('item_id');
    });

    $green = sql_item('cup-green', 450, 5, 1, ['color' => ['green'], 'pack_size' => ['6', '12'], 'brand' => ['north']]);
    $pink = sql_item('cup-pink', 500, 10, 1, ['color' => ['pink'], 'pack_size' => ['6'], 'brand' => ['north']]);
    $dots = sql_item('cup-pink-dots', 520, 1, 1, ['color' => ['pink'], 'pack_size' => ['100'], 'brand' => ['north']]);
    // The brand of the plate is not a slug: the dictionary has to leave it out.
    $plate = sql_item('plate', 1200, 3, null, ['color' => ['pink'], 'pack_size' => ['6'], 'brand' => ['Acme'], 'material' => ['paper']]);

    sql_item('bowl', 100, 99, null, ['color' => ['green'], 'pack_size' => ['100']], active: false);
    sql_item('tray', 50, 99, null, ['color' => ['green'], 'pack_size' => ['12']], section: 2);

    // The same value stored twice under another label: counted once, shown under the smaller label.
    DB::table('item_facets')->insert(['item_id' => $plate, 'facet' => 'pack_size', 'value' => '6', 'label' => '6 pcs', 'sort_value' => 6]);

    return [$green, $pink, $dots, $plate];
}

/**
 * @param  array<string, list<string>>  $facets  facet column => values
 */
function sql_item(string $name, int $price, int $sales, ?int $model, array $facets, int $section = 1, bool $active = true): int
{
    $id = DB::table('items')->insertGetId([
        'name' => $name,
        'price' => $price,
        'sales' => $sales,
        'model_id' => $model,
        'active' => $active,
    ]);

    DB::table('item_section')->insert(['section_id' => $section, 'item_id' => $id]);

    // Pink sorts before green by label and after it by value.
    $labels = ['green' => 'Green', 'pink' => 'Dusty pink', 'north' => 'North', 'paper' => 'Paper'];

    foreach ($facets as $facet => $values) {
        foreach ($values as $value) {
            DB::table('item_facets')->insert([
                'item_id' => $id,
                'facet' => $facet,
                'value' => $value,
                'label' => $labels[$value] ?? $value,
                'sort_value' => (int) $value,
            ]);
        }
    }

    return $id;
}

function sql_item_model(): Model
{
    return new class extends Model
    {
        protected $table = 'items';
    };
}

function sql_table(): FacetTable
{
    return new FacetTable('item_facets', 'item_id', KeyStyle::Snake);
}

function sql_dictionary(LoggerInterface $log = new NullLogger): FlatFacetDictionary
{
    return new FlatFacetDictionary(DB::connection(), sql_table(), ['pack_size' => 'Pack size', 'color' => 'Color'], $log);
}

function sql_index(FlatFacetDictionary $dictionary, string $price = 'items.price'): SqlCatalogIndex
{
    $catalog = new SqlCatalog(
        documents: static fn (): EloquentBuilder => sql_item_model()->newQuery()->where('items.active', true),
        id: 'items.id',
        price: $price,
        fields: [
            'sections' => static fn (QueryBuilder $query, Constraint $constraint) => $query->whereIn(
                'items.id',
                DB::table('item_section')->whereIn('section_id', $constraint->values)->select('item_id'),
            ),
            'id' => 'items.id',
            'promo' => static fn (QueryBuilder $query) => $query->where('items.price', '<', 460)->orWhere('items.sales', '>=', 10),
        ],
        sorts: [
            '' => 'items.sales DESC, items.id',
            'price_asc' => 'items.price, items.id',
            'price_only' => 'items.price',
            'relevance' => static fn (Query $query): string => SqlCatalog::rankedOrder('items.id', sql_ranked($query)).', items.id',
        ],
        groups: ['model' => 'COALESCE(items.model_id, -items.id)'],
    );

    return new SqlCatalogIndex($catalog, sql_table(), $dictionary);
}

/**
 * @return list<int> ids of the ranked constraint, in their ranking
 */
function sql_ranked(Query $query): array
{
    return array_values(array_filter(
        $query->scope->constraint('id')->values ?? [],
        static fn (int|string $id): bool => is_int($id),
    ));
}

function sql_scope(): Scope
{
    return new Scope('section', [Constraint::in('sections', [1])], 'model');
}

function sql_query(FacetChoice ...$choices): Query
{
    return new Query(sql_scope(), new Selection(array_values($choices)));
}

/**
 * @param  list<string>  $values
 */
function sql_choice(string $facet, array $values, FacetOperator $operator = FacetOperator::Any): FacetChoice
{
    return new FacetChoice(FacetId::fromString($facet), array_map(FacetValueId::fromString(...), $values), $operator);
}

/**
 * @return list<int>
 */
function sql_ids(IndexResponse $response): array
{
    return array_map(static fn (Hit $hit): int => $hit->id, $response->hits);
}

/**
 * @return array<string, int> "facet:value" => count, sorted by key
 */
function sql_counts(FlatFacetDictionary $dictionary, IndexResponse $response, string $intent = 'main'): array
{
    $counts = [];

    foreach ($response->countsFor($intent) as $id => $count) {
        $term = $dictionary->resolve($id) ?? throw new LogicException("Term {$id} is not in the dictionary.");

        $counts[$term->facet->value.':'.$term->value->value] = $count;
    }

    ksort($counts, SORT_STRING);

    return $counts;
}

/**
 * @return list<string> "value: label" of the facet terms, in dictionary order
 */
function sql_terms(FlatFacetDictionary $dictionary, string $facet): array
{
    return array_map(
        static fn (FacetTerm $term): string => $term->value->value.': '.$term->label,
        $dictionary->termsFor(FacetId::fromString($facet)),
    );
}

/**
 * @return array<string, list<array{0: string, 1: int, 2: bool}>> facet => [value, count, selected], in panel order
 */
function sql_panel(FacetPanel $panel): array
{
    $shape = [];

    foreach ($panel->groups as $group) {
        foreach ($group->values as $value) {
            $shape[$group->facet->value][] = [$value->term->value->value, $value->count, $value->selected];
        }
    }

    return $shape;
}

/**
 * @return array{0: LoggerInterface, 1: ArrayObject<int, array{level: mixed, context: array<mixed>}>} a logger and its records
 */
function sql_logger(): array
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

it('shows one card per model, picked by the sort and the selection', function (): void {
    [$green, $pink, , $plate] = sql_seed();
    $dictionary = sql_dictionary();
    $index = sql_index($dictionary);

    $page = $index->execute(QueryPlan::forQuery(sql_query()));

    // The best selling variant stands for the cup.
    expect($page->total)->toBe(2)
        ->and(sql_ids($page))->toBe([$pink, $plate]);

    $cheapest = $index->execute(QueryPlan::forQuery(sql_query()->withSort('price_asc')));

    expect(sql_ids($cheapest))->toBe([$green, $plate]);

    $filtered = $index->execute(QueryPlan::forQuery(sql_query(sql_choice('color', ['green']))));

    expect(sql_ids($filtered))->toBe([$green]);

    $counts = sql_counts($dictionary, $page);

    expect($counts['color:green'])->toBe(1)
        ->and($counts['color:pink'])->toBe(2);
});

it('counts the total and the facet values in models, and in documents without a grouping', function (): void {
    [, , $dots] = sql_seed();
    $dictionary = sql_dictionary();
    $index = sql_index($dictionary);

    $models = $index->execute(QueryPlan::forQuery(sql_query()));

    expect($models->total)->toBe(2)
        ->and(sql_counts($dictionary, $models))->toBe([
            'brand:north' => 1,
            'color:green' => 1,
            'color:pink' => 2,
            'material:paper' => 1,
            'pack-size:100' => 1,
            'pack-size:12' => 1,
            'pack-size:6' => 2,
        ]);

    $documents = $index->execute(QueryPlan::forQuery(new Query(sql_scope()->groupedBy(null))));

    expect($documents->total)->toBe(4)
        ->and(sql_counts($dictionary, $documents))->toBe([
            'brand:north' => 3,
            'color:green' => 1,
            'color:pink' => 3,
            'material:paper' => 1,
            'pack-size:100' => 1,
            'pack-size:12' => 1,
            'pack-size:6' => 3,
        ]);

    // The card is the variant that passes the selection, not the best seller of the model.
    $picked = $index->execute(QueryPlan::forQuery(sql_query(sql_choice('pack-size', ['100']))));

    expect($picked->total)->toBe(1)
        ->and(sql_ids($picked))->toBe([$dots]);
});

it('counts a selected facet without its own filter', function (): void {
    sql_seed();
    $dictionary = sql_dictionary();

    $response = sql_index($dictionary)->execute(QueryPlan::forQuery(sql_query(sql_choice('color', ['green']))));

    // Main follows the selection, the what-if drops the color filter, the palette is the whole scope.
    expect(sql_counts($dictionary, $response))->toBe([
        'brand:north' => 1,
        'color:green' => 1,
        'pack-size:12' => 1,
        'pack-size:6' => 1,
    ])
        ->and(sql_counts($dictionary, $response, 'what-if:color'))->toBe(['color:green' => 1, 'color:pink' => 2])
        ->and(sql_counts($dictionary, $response, 'palette'))->toBe([
            'brand:north' => 1,
            'color:green' => 1,
            'color:pink' => 2,
            'material:paper' => 1,
            'pack-size:100' => 1,
            'pack-size:12' => 1,
            'pack-size:6' => 2,
        ]);
});

it('takes price bounds from the selection when asked for filtered bounds, from the scope otherwise', function (): void {
    [, $pink] = sql_seed();
    $index = sql_index(sql_dictionary());

    $query = sql_query(sql_choice('color', ['pink']))->withPrice(new PriceRange(499.5, 600.0));

    $filtered = $index->execute(QueryPlan::forQuery($query, filteredBounds: true));
    $bare = $index->execute(QueryPlan::forQuery($query));

    // Two pink cups fit the range; the bounds never follow the range itself.
    expect($filtered->total)->toBe(1)
        ->and(sql_ids($filtered))->toBe([$pink])
        ->and($filtered->priceBounds->min)->toBe(500.0)
        ->and($filtered->priceBounds->max)->toBe(1200.0)
        ->and($bare->priceBounds->min)->toBe(450.0)
        ->and($bare->priceBounds->max)->toBe(1200.0);
});

it('compares a price that is an expression as a number', function (): void {
    [, $pink, , $plate] = sql_seed();
    DB::table('items')->where('id', $plate)->update(['sale_price' => 480]);

    $index = sql_index(sql_dictionary(), 'COALESCE(items.sale_price, items.price)');

    $both = $index->execute(QueryPlan::forQuery(sql_query()->withPrice(new PriceRange(459.5, 510.5)), filteredBounds: true));
    $from = $index->execute(QueryPlan::forQuery(sql_query()->withPrice(new PriceRange(510.5))));
    $upTo = $index->execute(QueryPlan::forQuery(sql_query()->withPrice(new PriceRange(null, 459.5))));

    expect(sql_ids($both))->toBe([$pink, $plate])
        ->and($both->priceBounds->min)->toBe(450.0)
        ->and($both->priceBounds->max)->toBe(520.0)
        ->and($from->total)->toBe(1)
        ->and($upTo->total)->toBe(1);
});

it('keeps an OR of a field closure inside the visible documents of the scope', function (): void {
    [$green, $pink] = sql_seed();
    $index = sql_index(sql_dictionary());

    $scope = new Scope('promo', [Constraint::in('sections', [1]), Constraint::in('promo', [1])]);

    $found = $index->execute(QueryPlan::forQuery(new Query($scope)));

    // The hidden bowl and the tray of another section sell best, and the OR must not let them in.
    expect($found->total)->toBe(2)
        ->and(sql_ids($found))->toBe([$pink, $green]);
});

it('breaks ties of a sort by the id, on the page and for the card of a group', function (): void {
    [, , , $plate] = sql_seed();

    $first = sql_item('mug-a', 700, 30, 2, []);
    $second = sql_item('mug-b', 700, 20, 2, []);
    sql_item('mug-c', 700, 10, 2, []);

    // The planner walks these instead of sorting, and equal prices come out of them last id first.
    DB::statement('CREATE INDEX items_by_price ON items (price, sales)');
    DB::statement('CREATE INDEX items_by_card ON items (COALESCE(model_id, -id), price, sales)');

    $index = sql_index(sql_dictionary());

    $rows = $index->execute(QueryPlan::forQuery((new Query(new Scope))->withSort('price_only')));
    $cards = $index->execute(QueryPlan::forQuery((new Query(new Scope('all', [], 'model')))->withSort('price_only')));

    expect(array_slice(sql_ids($rows), 4, 2))->toBe([$first, $second])
        ->and(array_slice(sql_ids($cards), -2))->toBe([$first, $plate]);
});

it('keeps the order of a ranked constraint and finds nothing for an empty one', function (): void {
    [$green, $pink, , $plate] = sql_seed();
    $index = sql_index(sql_dictionary());

    $found = new Scope('search', [Constraint::ranked('id', [$plate, $green, $pink])]);

    $rows = $index->execute(QueryPlan::forQuery((new Query($found))->withSort('relevance')));

    expect($rows->total)->toBe(3)
        ->and(sql_ids($rows))->toBe([$plate, $green, $pink]);

    // Grouped, the cup shows the variant that ranks first.
    $cards = $index->execute(QueryPlan::forQuery((new Query($found->groupedBy('model')))->withSort('relevance')));

    expect($cards->total)->toBe(2)
        ->and(sql_ids($cards))->toBe([$plate, $green]);

    $nothing = $index->execute(QueryPlan::forQuery(
        (new Query(new Scope('search', [Constraint::ranked('id', [])])))->withSort('relevance'),
    ));

    expect($nothing->total)->toBe(0)
        ->and($nothing->hits)->toBe([])
        ->and($nothing->countsFor('main'))->toBe([])
        ->and($nothing->priceBounds->isEmpty())->toBeTrue();
});

it('requires every value under the "all" operator', function (): void {
    [$green, $pink, , $plate] = sql_seed();
    $index = sql_index(sql_dictionary());

    $any = $index->execute(QueryPlan::forQuery(sql_query(sql_choice('pack-size', ['6', '12']))));
    $all = $index->execute(QueryPlan::forQuery(sql_query(sql_choice('pack-size', ['6', '12'], FacetOperator::All))));

    // Only the green cup comes in both packs.
    expect($any->total)->toBe(2)
        ->and(sql_ids($any))->toBe([$pink, $plate])
        ->and($all->total)->toBe(1)
        ->and(sql_ids($all))->toBe([$green]);
});

it('skips counts for a results-only request and documents for a panel-only one', function (): void {
    [, $pink, , $plate] = sql_seed();
    $dictionary = sql_dictionary();
    $index = sql_index($dictionary);

    DB::enableQueryLog();

    $results = $index->execute(QueryPlan::forQuery(sql_query()->needing(Need::Results)));

    // The total and the page: neither counts nor the dictionary behind them.
    expect(DB::getQueryLog())->toHaveCount(2)
        ->and(sql_ids($results))->toBe([$pink, $plate])
        ->and($results->total)->toBe(2)
        ->and($results->countsFor('main'))->toBe([])
        ->and($results->priceBounds->isEmpty())->toBeTrue();

    DB::flushQueryLog();

    $panel = $index->execute(QueryPlan::forQuery(sql_query()->needing(Need::Panel)));

    // The total, the counts with the dictionary they are keyed by, the bounds: no page.
    expect(DB::getQueryLog())->toHaveCount(4)
        ->and($panel->hits)->toBe([])
        ->and($panel->total)->toBe(2)
        ->and(sql_counts($dictionary, $panel)['color:pink'])->toBe(2)
        ->and($panel->priceBounds->min)->toBe(450.0);
});

it('orders dictionary groups by titles and values by sort and label, and skips a value that is not a slug', function (): void {
    sql_seed();
    [$log, $records] = sql_logger();
    $dictionary = sql_dictionary($log);

    expect(array_map(strval(...), $dictionary->facets()))->toBe(['pack-size', 'color', 'brand', 'material'])
        // By sort_value: as text 100 would come first.
        ->and(sql_terms($dictionary, 'pack-size'))->toBe(['6: 6', '12: 12', '100: 100'])
        // Equal sort_value, so by label.
        ->and(sql_terms($dictionary, 'color'))->toBe(['pink: Dusty pink', 'green: Green'])
        ->and(sql_terms($dictionary, 'brand'))->toBe(['north: North'])
        ->and($dictionary->labelFor(FacetId::fromString('pack-size')))->toBe('Pack size')
        ->and($dictionary->labelFor(FacetId::fromString('brand')))->toBe('brand');

    expect($dictionary->idOf('pack_size', '12'))->toBe(2)
        ->and($dictionary->resolve(2)?->value->value)->toBe('12')
        ->and($dictionary->idOf('brand', 'Acme'))->toBeNull();

    $logged = $records->getArrayCopy();

    expect($logged)->toHaveCount(1)
        ->and($logged[0]['level'])->toBe(LogLevel::WARNING)
        ->and($logged[0]['context'])->toMatchArray(['facet' => 'brand', 'value' => 'Acme']);
});

it('hides zeros and single-value groups on the screen but keeps a selected value with a zero count', function (): void {
    [$green] = sql_seed();
    $dictionary = sql_dictionary();

    $catalog = new SearchCatalog(
        sql_index($dictionary),
        $dictionary,
        new DisjunctivePolicy(keepZeros: false, minValues: 2),
        order: ValueOrder::Dictionary,
        filteredBounds: true,
    );

    $screen = $catalog(sql_query(sql_choice('color', ['green'])));

    // Nothing green comes in packs of 100 or is made of paper, and the brand has one value left.
    expect($screen->page->total)->toBe(1)
        ->and($screen->page->hits[0]->id)->toBe($green)
        ->and(sql_panel($screen->panel))->toBe([
            'pack-size' => [['6', 1, false], ['12', 1, false]],
            'color' => [['pink', 2, false], ['green', 1, true]],
        ]);

    $empty = $catalog(sql_query(sql_choice('color', ['green']), sql_choice('pack-size', ['100'])));

    // No green cup comes in a pack of 100, yet both selected values stay to be unticked.
    expect($empty->page->total)->toBe(0)
        ->and(sql_panel($empty->panel))->toBe([
            'pack-size' => [['6', 1, false], ['12', 1, false], ['100', 0, true]],
            'color' => [['pink', 1, false], ['green', 0, true]],
        ]);
});
