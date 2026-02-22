# Ariadne

Faceted filter and search for Laravel 12/13 on PHP 8.3+: a query and its plan, a filter panel with ready links, an address codec in two styles, SQL facets without a search engine and a zero-downtime rebuild of Manticore tables.

I wrote the same filter twice, for two shops, and the second time it was clear which half repeats. That half is here. The package does not know your catalog: no models, no migrations, no field names. You describe the scope, name the fields and bring the engine.

The panel is disjunctive: picking one color does not zero the other colors. A selected value never leaves the panel, so a filter can always be taken off. Every address - a value, a reset, a sort, a page - is built on the server, and one selection always gives one address. An engine outage is not an empty result: the screen comes back marked `degraded` and is not cached.

Two things are left out on purpose. Search cascades: synonyms, typo correction, a retry in the other keyboard layout and a fallback to LIKE differ from shop to shop and stay in the application. The package only gives the bricks: `withPrefix()` on a query, `Manticore\Connection`, and `Text\Layout::toCyrillic('rjv,bytpjy')` for `комбинезон`. And the query compiler: turning a plan into SphinxQL for your tables is the adapter you write behind the `CatalogIndex` port. The only adapter that ships is the SQL one.

## Installation

```bash
composer require timurturdyev/ariadne
```

Laravel finds the provider by itself. The config is published on demand:

```bash
php artisan vendor:publish --tag=ariadne-config
```

- `manticore`: where `Manticore\Connection` connects, from `MANTICORE_HOST`, `MANTICORE_PORT`, `MANTICORE_TIMEOUT` and `MANTICORE_READ_TIMEOUT`.
- `url`: the `style` of the address (`comma` or `brackets`), the `price_unit` and the `sorts` an address accepts.
- `screen`: the `order` of values in a group (`dictionary`, `natural`, `alpha`, `count-desc`); `keep_zeros` keeps a value nothing matches, dimmed; `min_values` is how many values a group without a selection needs to show; `filtered_bounds` makes the price bounds follow the selection, not the whole scope.

Your config is merged with the package one at the top level only, so a section you override needs all of its keys: without `price_unit` the unit becomes 1, without `sorts` the address takes no sort at all.

The provider binds `Manticore\Connection`, `UrlFormat`, `QueryStringCodec` and `DisjunctivePolicy` as singletons, `SearchCatalog` and `PanelLinks` as scoped, each only when the application has not bound the class itself: your own binding wins in any provider order. Two ports are yours to bind. `CatalogIndex` runs a plan, `FacetDictionary` knows the facets and their values.

Two extensions are optional. With `intl` the `natural` and `alpha` orders use a `ru_RU` collator, without it a plain natural comparison. `pdo_mysql` is needed only for Manticore.

## One screen

```php
$scope = new Scope('section', [Constraint::in('sections', [10, 11])]);

$query = $codec->decode($request->query(), $scope, $dictionary);
$screen = $catalog($query);

$screen->page->hits;      // list of Hit: id, attributes, payload
$screen->page->total;
$screen->panel->groups;   // facets with values, counts and states
$screen->priceBounds;     // for the price slider
$screen->degraded;        // true when the engine did not answer
```

`$codec` is the `QueryStringCodec`, `$catalog` the `SearchCatalog`, `$dictionary` your `FacetDictionary`; all come from the container. The page size is the fourth argument of `decode()`, 30 by default, and it is never read from the address.

A scope is the part of a query the buyer does not choose: a label of the screen kind, constraints by field and an optional grouping key (`groupBy: 'model'` asks for one card per model). What a field or a key means is between you and your adapter. A page that loads its panel with a second request asks for the halves separately: `$query->needing(Need::Results)` skips the counts, `Need::Panel` skips the documents.

## Your own engine behind CatalogIndex

Write the adapter and bind it next to a dictionary:

```php
$this->app->scoped(FacetDictionary::class, DbFacetDictionary::class);
$this->app->bind(CatalogIndex::class, EngineCatalogIndex::class);
```

`execute(QueryPlan $plan): IndexResponse` receives the intents of one screen. Each carries its own `Query` with the right filters already dropped, so the adapter compiles and runs it without guessing:

| Intent | Key | Its query | What to answer |
|---|---|---|---|
| main | `main` | as the buyer asked | hits unless `isAggregateOnly()`, the total, counts of every facet |
| price bounds | `price-bounds` | scope and text; with `filtered_bounds` everything but the price | the lowest and the highest price |
| palette | `palette` | scope and text | counts: the values that exist in the scope at all |
| what-if | `what-if:color` | everything but the choice in `color` | counts of that facet |

Only main always comes. Price bounds come with the panel, the palette and a what-if per selected facet when something is selected. Counts go into `IndexResponse` under the intent key as `term id => count`, in the term ids of your dictionary. Facet and value ids are strict slugs, `[a-z0-9]+(-[a-z0-9]+)*`; an address with an unknown one loses that filter and nothing else.

An unreachable engine is a `CatalogIndexUnavailable`, a broken query surfaces as it is. `Manticore\Connection` behaves so. It reads the first byte of the handshake before connecting, so a dead engine behind a live port does not hold a worker. After a dropped link (`wait_timeout`, an engine restart) it connects again by itself: a statement that never reached the engine is retried once, one cut off in the middle is a `CatalogIndexUnavailable`. Bindings of `query()` and `select()` send ints and bools as numbers and the rest as strings; put floats and lists into the SQL text.

`MANTICORE_TIMEOUT` is the time to connect, empty or 0 means 5 seconds. `MANTICORE_READ_TIMEOUT` is the time to answer, 30 seconds by default: an answer that does not come in time is a `CatalogIndexUnavailable` without a retry, so a hung engine does not hold a worker either. It applies to the Manticore connection only: the package sets `mysqlnd.net_read_timeout` while the connection opens and puts the old value back at once, so the database connection of the application keeps its own. Waiting on the network does not count toward `max_execution_time`, so keep the timeout above your longest legitimate statement. 0 leaves the process setting (86400 seconds by default), and then a long statement is retried once when it runs out: mysqlnd reports that as a dropped link. `MANTICORE_HOST=localhost` connects to `127.0.0.1` over TCP.

## SQL facets on a flat table

No engine at all: facets are counted in your database over one flat table, a row per filter value of a document. There is no migration in the package, the table is yours:

```php
Schema::create('item_facets', static function (Blueprint $table): void {
    $table->foreignId('item_id')->constrained()->cascadeOnDelete();
    $table->string('facet', 64);                         // color, pack-size
    $table->string('value', 96);                         // white, 62
    $table->string('label');                             // White
    $table->unsignedInteger('sort_value')->default(0);   // order inside the group

    $table->primary(['item_id', 'facet', 'value']);
    $table->index(['facet', 'value']);
});
```

The columns `facet`, `value`, `label` and `sort_value` are fixed; the table name and the document column are yours. On MySQL and MariaDB give `facet` and `value` a binary collation (`utf8mb4_bin`). Without it the database merges `Acme` and `acme`, and if the spelling left is not a slug, the term drops out of the dictionary together with its documents.

Then tell the index about your tables:

```php
use Illuminate\Database\Query\Builder;
use Psr\Log\LoggerInterface;

$table = new FacetTable('item_facets', 'item_id');

$this->app->scoped(FlatFacetDictionary::class, static fn ($app): FlatFacetDictionary => new FlatFacetDictionary(
    $app->make('db')->connection(),
    $table,
    ['size' => 'Size', 'color' => 'Color'],   // group titles, in panel order
    $app->make(LoggerInterface::class),
));
$this->app->scoped(FacetDictionary::class, static fn ($app): FacetDictionary => $app->make(FlatFacetDictionary::class));

$this->app->scoped(CatalogIndex::class, static fn ($app): CatalogIndex => new SqlCatalogIndex(
    new SqlCatalog(
        documents: static fn () => Item::query()->where('items.active', true),
        id: 'items.id',
        price: 'items.price',
        fields: [
            'id' => 'items.id',
            'brand' => 'items.brand_id',
            'sections' => static fn (Builder $query, Constraint $constraint) => $query->whereIn(
                'items.id',
                DB::table('item_section')->whereIn('section_id', $constraint->values)->select('item_id'),
            ),
        ],
        sorts: [
            '' => static function (Query $query): string {
                $found = $query->scope->constraint('id');   // on a search screen: ids in ranking order

                return $found !== null && $found->ranked
                    ? SqlCatalog::rankedOrder('items.id', array_map(intval(...), $found->values))
                    : 'items.sales DESC';
            },
            'price-asc' => 'items.price',
        ],
        groups: ['model' => 'COALESCE(items.model_id, -items.id)'],
    ),
    $table,
    $app->make(FlatFacetDictionary::class),
));
```

What `SqlCatalog` expects:

- `id`, `price` and the strings in `fields`, `sorts` and `groups` go into SQL as they are: never take them from a request. The table prefix of the connection is not applied to them, unlike the names in `FacetTable`. `id` and `price` are required and written with their table. `price` may be an expression, such as `COALESCE(items.sale_price, items.price)`: the filter bounds go in as numbers.
- `documents` gives the visible documents before any scope or selection. Its rows must carry the id column; its own order is dropped, the order comes from `sorts` alone. Put a top-level OR in brackets yourself, `where(fn ($q) => $q->where(...)->orWhere(...))`, or the filters stick to its last condition only.
- A field is a column for an IN list or a closure with its own condition, which gets the base query builder, not the Eloquent one; its conditions are put in brackets for you. A scope field missing from `fields` is a `LogicException`; an empty constraint matches nothing.
- A sort is an ORDER BY list or a closure of the `Query` that returns one. The adapter adds the id as the last key itself, so with equal sort values the pages and the card of a group do not depend on the query plan.
- A group expression names the card of a row and must never be NULL. The card is the row that comes first under the current sort and selection; the total and the counts are then in cards.

The search text is not run here. Run it wherever you like and pass the ids in ranking order:

```php
$ids = $search->ids((string) $request->query('q'));   // your search: ids by relevance

$scope = new Scope('search', [Constraint::ranked('id', $ids)]);
$query = $codec->decode($request->query(), $scope, $dictionary);
```

`Constraint::ranked()` narrows the scope to those ids, and the `id` field in `fields` is there for it. The default sort of the example keeps their order through `SqlCatalog::rankedOrder()`.

The dictionary reads the table with one query per instance and caches nothing: an edit shows at once. Its term ids mean something only inside that instance, which is why the index and the port share one scoped dictionary. Groups go in the order of the titles, the rest by name; values by `sort_value`, then by label. A facet or a value that is not a slug is skipped with a warning to the logger of the fourth argument. A screen costs 5 queries without a selection and 6 + k with a selection in k facets, the dictionary included.

## Address styles

| | `comma` | `brackets` |
|---|---|---|
| Address | `?color=black,white&price=1000-50000&sort=price-asc` | `?color[]=black&color[]=white&price_min=1000&price_max=50000&sort=price_asc` |
| Facet keys | kebab: `pack-size` | snake: `pack_size` |
| All of the values at once | `material=all:leather,mesh` | no |

`comma` is the compact one. `brackets` is what an HTML form submits, so a filter form works without scripts: `key[]=a`, `key[0]=a` and `key=a` read alike, and a nested array or `sort[]` costs the buyer that parameter instead of a 500. `encode()` escapes the brackets: `color%5B%5D=black`.

`encode()` writes the canonical query string: text, facets by parameter name with values in alphabetical order, price, sort, page; defaults are left out. `price_unit` is the number of base units in one unit typed in the address: with prices kept in cents it is 100, and `price=1000-50000` means 100000 to 5000000. A reversed range `500-100` reads as `100-500`. A bound above `QueryStringCodec::MAX_AMOUNT` (1e12 in address units) or not a number is not read, a fractional one is rounded to two decimals. A sort outside `sorts` reads as the default order.

The codec keeps `q`, `sort`, `page` and the price parameters for itself. `isReserved()` takes a facet slug and tells a slug generator that the address could not read it: in `brackets` `price-min` is taken too, since it becomes `price_min`, and an all-digit slug is taken in both styles. Whatever else is not a known facet is foreign, such as campaign tags and your own flags: `foreign()` returns it and every panel address carries it on. `canonical()` orders incoming parameters the same way, for a redirect to one address per selection; facet values that cannot be a slug are dropped, so `canonical(canonical(x))` equals `canonical(x)`. With the flat table, take the key style of `FacetTable` from the format (`$format->keys()`), so the address and the `facet` column never disagree.

## The panel with ready links

```php
$base = LinkBase::of($request->getPathInfo(), $request->query(), $codec, $dictionary);

$panel = $links->panel($screen, $base, sorts: ['' => 'Popular', 'price-asc' => 'Cheapest first']);
```

`$links` is the `PanelLinks`. The answer is a plain array, for Blade and Inertia alike. Here it is, abridged, for `/catalog/desks?color=black,white&price=1000-&sort=price-asc` with prices kept in cents:

```php
[
    'facets' => [[
        'facet' => 'color', 'label' => 'Color', 'operator' => 'any', 'selected' => 2,
        'reset' => '/catalog/desks?price=1000-&sort=price-asc',
        // The same choice with the other operator; null when there is nothing to switch.
        'operatorHref' => '/catalog/desks?color=all:black,white&price=1000-&sort=price-asc',
        'values' => [[
            'value' => 'white', 'label' => 'White', 'image' => null, 'count' => 5, 'selected' => true, 'disabled' => false,
            'href' => '/catalog/desks?color=black&price=1000-&sort=price-asc',   // the value toggled
            'only' => '/catalog/desks?color=white&price=1000-&sort=price-asc',   // this value alone in its group
        ]],
    ]],
    'reset' => '/catalog/desks?sort=price-asc',   // no facets and no price; text and sort stay
    'sorts' => [['value' => 'price-asc', 'label' => 'Cheapest first', 'href' => '...', 'selected' => true]],
    'paging' => ['first' => '...', 'prev' => null, 'next' => '...', 'template' => '...&sort=price-asc&page={page}'],
    'price' => [
        'reset' => '/catalog/desks?color=black,white&sort=price-asc',
        'input' => ['min' => '1000', 'max' => ''],             // as typed in the address
        'template' => '/catalog/desks?color=black,white&price={min}-{max}&sort=price-asc',
        'bounds' => ['min' => 99000.0, 'max' => 12500000.0],   // base units, like selected
        'selected' => ['min' => 100000.0, 'max' => null],
    ],
]
```

A change of the selection starts paging over, and the first page goes without `page`. On the deepest page an address can reach (`PageRequest::MAX_PAGE`, 500) there is no `next`. `$links->href($query, $base)` is the address of any query, on its first page. For a panel answered to a `Need::Panel` query pass the buyer's query third, or the addresses lose their sort.

`LinkBase` brings the path to one leading slash itself, so `getPathInfo()` can go in as it is. Let foreign parameters into the links by a whitelist, `$base->only('sale', 'all')`, or `_token`, `partial` and any stray parameter ride along in every link; `$base->without('sale')` drops one. The foreign part of a link is at most `LinkBase::MAX_FOREIGN_LENGTH`, 1024 encoded bytes: a parameter that does not fit is left out of the links. A foreign array does not get into `comma` links, and in `brackets` a keyed array (`filter[stock]=1`) comes back as a list, `filter[]=1`.

A landing page keeps part of the selection in its path. Say so, and its addresses stay on that path:

```php
$size = new FacetChoice(FacetId::fromString('size'), [FacetValueId::fromString('62')]);

$query = $codec->decode($request->query(), $scope, $dictionary);
$query = $query->withSelection($query->selection->with($size));

$base = LinkBase::of('/catalog', $request->query(), $codec, $dictionary)
    ->landing('/catalog/size-62', new Selection([$size]));
```

While the query keeps the pinned choice as it is, paging, sorting and other facets stay on the landing path and leave the pinned facet out: `/catalog/size-62?page=2`, `/catalog/size-62?color=white`. Once the pinned choice changes, a value taken off or another one added, the address goes to the main path with the whole selection: `/catalog?size=62,68`. A landing page that pins nothing does not apply, and its addresses go to the main path.

## Screen cache

The provider binds `SearchCatalog` without a cache. To get one, bind the class yourself and pass a cache repository. Your binding replaces the one of the provider as a whole, so pass the logger, the value order and the price bounds rule from the config too:

```php
use Psr\Log\LoggerInterface;
use TimurTurdyev\Ariadne\Facets\ValueOrder;

$this->app->scoped(SearchCatalog::class, static fn ($app): SearchCatalog => new SearchCatalog(
    $app->make(CatalogIndex::class),
    $app->make(FacetDictionary::class),
    $app->make(DisjunctivePolicy::class),
    cache: $app->make('cache')->store(),
    log: $app->make(LoggerInterface::class),
    order: ValueOrder::from(config('ariadne.screen.order')),
    filteredBounds: (bool) config('ariadne.screen.filtered_bounds'),
));

$screen = $catalog($query, new PriceScale(1.22), ['region:'.$region->id]);
```

A screen is fresh for 300 seconds, then served stale while it refreshes, and gone after 600. The key is the signature of the query, the price scale and the parts you pass: put there everything else the answer depends on, a region or a host, or one buyer gets the screen of another. A `degraded` screen is never cached. If the engine is down when a stale screen refreshes, the old one stays: it counts as fresh for another 300 seconds, the logger gets a warning and the error handler gets nothing, so while the engine stays down the stale screen keeps living. With a scale other than 1 the package rescales the filter bounds and the slider; the prices inside hits are yours, through the `displayPrices` closure.

The dictionary is not a part of the key. A renamed value, a new one or a new order reaches cached screens with a delay of up to ten minutes. If that hurts, pass a generation of your dictionary among the key parts, any string that changes when the dictionary does, or leave the screen uncached.

## Rebuilding Manticore tables without downtime

The application gives the documents; the package writes them in batches into a table aside, checks the count and switches the readers.

```php
$tables = [new Table(['title' => 'text', 'price' => 'int', 'sections' => 'multi'], ['min_infix_len' => '2'])];

$publication = new SwapCopies(
    $engine,   // Manticore\Connection
    $locks,    // a LockProvider, such as Cache::store()->getStore()
    read: static fn (string $name): ?string => $pointers->get($name),
    write: static function (string $name, string $table) use ($pointers): void {
        $pointers->put($name, $table);
    },
);

// $documents: rows like ['id' => 1, 'title' => 'Desk', 'price' => 150000, 'sections' => [10, 11]]
$published = $publication->rebuild('items', $tables, static fn (string $target): int => (new Writer($engine))
    ->insert($target, ['id', 'title', 'price', 'sections'], $documents));

$publication->live('items');   // the table readers query now: items_a or items_b
```

A `Table` with a `suffix` is a companion: tables of one set are published together, and the fill writes each of them at `$table->name($target)`. Write table names in lower case, the engine lower-cases them anyway.

`Writer` writes batches of 1000. A string column needs a string: a number there is stored as an empty string. NAN and INF are a `LogicException`, a NUL byte is stripped from strings. An integer above uint32 in an `int` column wraps around silently, that is the engine.

Both strategies pass the same gate before the switch: the new main table must hold exactly as many documents as the fill reported, and an empty table of the set never replaces one that has documents. A failed gate is a `PublishRefused`, an exception from the fill flies as it is; either way readers keep the previous data, and the table that was being built stays for a look until the next rebuild drops it.

A lock keeps two rebuilds of one name apart: `SwapCopies` waits for the running one up to `waitSeconds` (300), `AttachShadow` gives up at once, and both end in a `LockTimeoutException`. The lock lives for `lockSeconds`, 600 and 3600 by default: give it more than your longest rebuild takes. If a fill takes longer anyway, there is no switch but a `PublishRefused` saying the lock expired, since by then another process may hold it. `lockSeconds: 0` is a lock without expiry and without that check.

`SwapCopies` keeps two copies, `items_a` and `items_b`, and a pointer you store wherever you like. A rebuild fills the spare copy and moves the pointer. The previous copy is left alone, so a query started before the switch finishes on it.

`AttachShadow($engine, $locks)` is for a table several applications read by a constant name, where a shared pointer does not reach them all. It builds `items_build` and runs `ATTACH TABLE items_build TO TABLE items WITH TRUNCATE`. Mind what ATTACH is:

- It needs an engine that attaches one RT table to another.
- The truncated target takes the columns and the text settings of the source. So a change of the schema is published by the same switch: readers go from the old table to the new one with no gap, and nothing has to be dropped.
- The target keeps its own value of 14 settings (seen on 29.0.2): `expand_keywords`, `rt_mem_limit`, `optimize_cutoff`, `regexp_filter`, `access_plain_attrs`, `access_blob_attrs`, `access_doclists`, `access_hitlists`, `access_dict`, `read_buffer_docs`, `read_buffer_hits`, `preopen`, `diskchunk_flush_write_timeout`, `diskchunk_flush_search_timeout`. Name such a setting in the third argument of `Table`, and after the attach it is set with ALTER when the value differs; otherwise a new value never reaches the live table. To remove such a setting, keep the key in the settings and in the third argument with its default value, such as `expand_keywords='0'`.
- `regexp_filter` and changing settings do not mix: on 29.0.2 any ALTER of a setting takes the filter off the table, for new documents too. `regexp_filter` itself only goes away with the table.
- It empties the target first and nothing rolls that back. A failure on the switch is a `PublishFailed`: if `$e->switched`, the table `$e->table` already serves the new data; if not, it holds the previous data, or nothing when ATTACH broke off in the middle.
- The switch is atomic only within one table: a set of several tables is switched one by one. Tables before the failed one are already on the new data, and the next rebuild brings the set together.
- A write that reaches the live table while the shadow is being filled is gone after the switch. Hold such writes until the rebuild ends, or run them again after it.
- ATTACH goes through a table under FREEZE and deletes the files promised to a backup. Keep `manticore-backup` and rebuilds apart in time.

If the engine went down in the middle of a fill, CREATE of a new table may run into "directory is not empty". Remove the table folder in `data_dir` by hand then, the package does not.

`Table::drift()` tells how a live table differs from the schema, by what `DESCRIBE` and `SHOW CREATE TABLE` print: a check for a deploy, not a step of the rebuild. A setting the schema does not have is an `unexpected setting`, and sizes such as `256M` are compared in bytes. The engine does not print everything and prints some things its own way, and the package does not work around these:

- a default value is not printed (`min_infix_len='0'`, `html_strip='0'`, `dict='keywords'`, `min_word_len='1'`), so such a setting in the schema shows up as `setting ...: expected`; `index_exact_words='1'` without a morphology is dropped by the engine;
- `stopwords`, `wordforms` and `exceptions` are printed as lists, `stopwords_list`, `wordforms_list` and `exceptions_list`, so drift reports both `expected` and `unexpected setting`;
- `preopen='1'` is printed as `true`, and `index_field_lengths='1'` adds columns named `<field>_len`.

Declare reserved column names (`order`, `limit`, `facet` and others) in backticks: ``'`order`' => 'int'``. Drift compares them without the backticks.

## Development

```bash
composer test
composer analyse
composer lint
```

Feature tests run on SQLite in memory, the Manticore tests on a double of the engine. The same writes and publications run on a real engine when you point at one:

```bash
ARIADNE_MANTICORE=127.0.0.1:9306 vendor/bin/pest tests/Feature/Manticore
```

Without the variable these cases are skipped. They drop every table whose name starts with `ariadne_it_`, before and after each case.

MIT license.
