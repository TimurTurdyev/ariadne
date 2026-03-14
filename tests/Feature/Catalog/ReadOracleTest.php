<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use TimurTurdyev\Ariadne\Catalog\Catalog;
use TimurTurdyev\Ariadne\Catalog\Catalogs;
use TimurTurdyev\Ariadne\Catalog\Facet;
use TimurTurdyev\Ariadne\Catalog\Panel;
use TimurTurdyev\Ariadne\Catalog\Screen;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopCatalog;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopData;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopOracle;

require_once __DIR__.'/catalog_helpers.php';

/**
 * Random addresses, scopes, rules and settings against a naive model of the screen built from the shop tables.
 */
it('builds every screen as the naive model does', function (string $connection, int $screens): void {
    catalog_use_connection($connection);
    ShopData::create();
    ShopData::installFacetTables();
    ShopData::seed(240, 11);
    $mismatches = [];

    foreach ([null, catalog_described()] as $mode => $described) {
        ShopCatalog::$rules = null;
        ShopCatalog::$described = $described === null ? null : static fn (): array => [
            Facet::named('material', 'Material!')->at(2),
            Facet::named('season', 'Season!')->at(1)->swatches(),
            Facet::named('pattern', 'Pattern!')->single(),
            Facet::named('fit', 'Fit!'),
            Facet::named('extra', 'Extra'),
            Facet::named('price', 'Refused'),
        ];

        $outcome = catalog_catalogs(true, 2)->get('shop')->rebuild();
        expect($outcome->published())->toBeTrue((string) $outcome->message);

        $oracle = new ShopOracle($described);
        mt_srand(1000 + $mode);

        for ($i = 0; $i < $screens; $i++) {
            [$address, $rules, $keepZeros, $minValues] = catalog_random_screen($oracle);
            ShopCatalog::$rules = static fn (): Panel => $rules;
            $catalog = catalog_narrowed(catalog_catalogs($keepZeros, $minValues)->get('shop'), $address);
            $actual = catalog_normalized($catalog->screenFor('/shop', $address['params']));
            $expected = $oracle->screen($address, $rules, $keepZeros, $minValues);

            if ($actual != $expected) {
                $mismatches[] = ['mode' => $mode, 'address' => $address, 'rules' => $rules, 'keepZeros' => $keepZeros, 'minValues' => $minValues, 'expected' => $expected, 'actual' => $actual];
            }
        }
    }

    expect(array_slice($mismatches, 0, 2))->toBe([])
        ->and(count($mismatches))->toBe(0);
})->with(catalog_connections(sqliteScreens: 150, serverScreens: 500))->after(fn () => catalog_drop_all());

/**
 * @return array<string, array{title: string, position: ?int, widget: string, multiple: bool}>
 */
function catalog_described(): array
{
    return [
        'material' => ['title' => 'Material!', 'position' => 2, 'widget' => 'list', 'multiple' => true],
        'season' => ['title' => 'Season!', 'position' => 1, 'widget' => 'swatch', 'multiple' => true],
        'pattern' => ['title' => 'Pattern!', 'position' => null, 'widget' => 'list', 'multiple' => false],
        'fit' => ['title' => 'Fit!', 'position' => null, 'widget' => 'list', 'multiple' => true],
    ];
}

function catalog_catalogs(bool $keepZeros, int $minValues): Catalogs
{
    $config = app(Repository::class);
    $config->set('ariadne.catalogs', ['shop' => ['definition' => ShopCatalog::class, 'keep_zeros' => $keepZeros, 'min_values' => $minValues]]);

    return new Catalogs(app(), $config);
}

/**
 * @return array{0: array{params: array<string, string>, category: ?list<int>, colors: ?list<int>, brand: ?list<int>, ranked: ?list<int>, pins: ?array<string, string>, perPage: int}, 1: Panel, 2: bool, 3: int}
 */
function catalog_random_screen(ShopOracle $oracle): array
{
    $vocabulary = $oracle->vocabulary();
    $groups = array_map(strval(...), array_keys($vocabulary));
    $params = [];

    foreach (catalog_pick([...$groups, 'zzz', 'rare'], 3) as $group) {
        $values = catalog_pick([...($vocabulary[$group] ?? []), 'nope'], 3) ?: ['nope'];
        $params[$group] = (mt_rand(1, 5) === 1 ? 'all:' : '').implode(',', $values);
    }

    if (mt_rand(1, 10) <= 3) {
        $params['price'] = match (mt_rand(1, 3)) {
            1 => mt_rand(1, 200) * 50 .'-'.mt_rand(1, 400) * 50,
            2 => mt_rand(1, 200) * 50 .'-',
            default => '-'.mt_rand(1, 400) * 50,
        };
    }

    if (mt_rand(1, 10) <= 4) {
        $params['sort'] = ['popular', 'new', 'price-asc', 'price-desc', 'relevance', 'bogus'][mt_rand(0, 5)];
    }

    if (mt_rand(1, 10) <= 3) {
        $params['page'] = (string) mt_rand(1, 4);
    }

    if (mt_rand(1, 10) === 1) {
        $params['q'] = 'cup';
    }

    if (mt_rand(1, 5) === 1) {
        $params['utm_source'] = 'mail';
    }

    $ids = $oracle->documentIds();
    $ranked = null;

    if (mt_rand(1, 5) === 1) {
        $ranked = catalog_pick([...$ids, 9999], 40);
    }

    $address = [
        'params' => $params,
        'category' => mt_rand(1, 10) <= 3 ? catalog_pick([1, 2, 3, 4], 2) : null,
        'colors' => mt_rand(1, 7) === 1 ? catalog_pick([1, 2, 3, 4, 5, 6], 2) : null,
        'brand' => mt_rand(1, 10) === 1 ? catalog_pick([1, 2, 3, 4, 5], 2) : null,
        'ranked' => $ranked,
        'pins' => mt_rand(1, 10) === 1 && isset($vocabulary['brand']) ? ['brand' => $vocabulary['brand'][mt_rand(0, count($vocabulary['brand']) - 1)]] : null,
        'perPage' => [5, 12, 24][mt_rand(0, 2)],
    ];

    $rules = new Panel;

    if (mt_rand(1, 5) === 1) {
        $rules = $rules->only(...catalog_pick([...$groups, 'nothing'], 4));
    }

    if (mt_rand(1, 5) === 1) {
        $rules = $rules->first(...catalog_pick($groups, 3));
    }

    if (mt_rand(1, 5) === 1) {
        $rules = $rules->hide(...catalog_pick($groups, 3));
    }

    if (mt_rand(1, 5) === 1) {
        $rules = $rules->max(mt_rand(1, 5));
    }

    if (mt_rand(1, 3) === 1) {
        $rules = $rules->minCoverage(mt_rand(5, 60) / 100);
    }

    if (mt_rand(1, 5) === 1) {
        $rules = $rules->byCoverage();
    }

    return [$address, $rules, mt_rand(0, 1) === 1, mt_rand(1, 3)];
}

/**
 * @template T
 *
 * @param  list<T>  $from
 * @return list<T>
 */
function catalog_pick(array $from, int $max): array
{
    shuffle($from);

    return array_slice($from, 0, mt_rand(0, min($max, count($from))));
}

/**
 * @param  Catalog<Model>  $catalog
 * @param  array{params: array<string, string>, category: ?list<int>, colors: ?list<int>, brand: ?list<int>, ranked: ?list<int>, pins: ?array<string, string>, perPage: int}  $address
 * @return Catalog<Model>
 */
function catalog_narrowed(Catalog $catalog, array $address): Catalog
{
    $catalog = $catalog->perPage($address['perPage'])
        ->where('category_id', $address['category'])
        ->where('colors', $address['colors'])
        ->where('brand', $address['brand']);

    if ($address['ranked'] !== null) {
        $catalog = $catalog->ranked($address['ranked']);
    }

    return $address['pins'] === null ? $catalog : $catalog->landingOf('/shop', $address['pins']);
}

/**
 * @param  Screen<Model>  $screen
 * @return array{total: int, ids: list<int>, bounds: array{0: ?int, 1: ?int}, sort: string, groups: list<array{facet: string, widget: string, selected: int, coverage: ?float, values: list<array{0: string, 1: int, 2: bool, 3: bool}>}>}
 */
function catalog_normalized(Screen $screen): array
{
    $selected = '';

    foreach ($screen->sorts as $sort) {
        if ($sort->selected) {
            $selected = $sort->value;
        }
    }

    $groups = [];

    foreach ($screen->facets as $group) {
        $groups[] = [
            'facet' => $group->facet,
            'widget' => $group->widget,
            'selected' => $group->selected,
            'coverage' => $group->coverage,
            'values' => array_map(static fn ($value): array => [$value->value, $value->count, $value->selected, $value->disabled], $group->values),
        ];
    }

    $bound = static fn (?float $value): ?int => $value === null ? null : (int) round($value);

    return [
        'total' => $screen->total,
        'ids' => array_values($screen->items->map(static fn (Model $item): int => is_numeric($item->getKey()) ? (int) $item->getKey() : 0)->all()),
        'bounds' => [$bound($screen->price?->bounds['min']), $bound($screen->price?->bounds['max'])],
        'sort' => $selected,
        'groups' => $groups,
    ];
}
