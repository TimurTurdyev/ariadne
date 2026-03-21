<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TimurTurdyev\Ariadne\Catalog\Catalog;
use TimurTurdyev\Ariadne\Catalog\Catalogs;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Brand;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Color;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Feature;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Product;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopCatalog;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopData;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Variant;

require_once __DIR__.'/catalog_helpers.php';

/**
 * Random Eloquent edits with sync on: after a drain the live rows equal those of a fresh rebuild.
 */
it('keeps the live rows equal to a fresh rebuild after random edits', function (string $connection, int $rounds): void {
    $catalog = catalog_writable($connection);
    mt_srand(is_numeric(getenv('ARIADNE_SEED')) ? (int) getenv('ARIADNE_SEED') : 77);

    for ($round = 0; $round < $rounds; $round++) {
        for ($edit = 0; $edit < 8; $edit++) {
            catalog_random_edit($catalog);
        }

        $catalog->drain();
        expect(DB::table('facet_changes')->count())->toBe(0);

        $live = catalog_rows($catalog);
        $outcome = $catalog->rebuild();
        expect($outcome->published())->toBeTrue((string) $outcome->message)
            ->and(catalog_rows($catalog))->toBe($live, "round {$round}: the drained rows differ from a rebuild");
    }
})->with(catalog_connections(sqliteScreens: 25, serverScreens: 25))->after(fn () => catalog_drop_all());

it('replays an edit made while the rebuild fills its table', function (string $connection): void {
    $catalog = catalog_writable($connection);
    $early = Product::query()->oldest('id')->firstOrFail();
    $brand = Brand::query()->where('id', '!=', $early->brand_id ?? 0)->firstOrFail();

    // A later document triggers the edit of one the fill has already projected.
    ShopCatalog::$whileProducing = static function (Product $product) use ($early, $brand): void {
        if ($product->id === $early->id + 30) {
            ShopCatalog::$whileProducing = null;
            $early->brand_id = $brand->id;
            $early->save();
        }
    };

    $outcome = $catalog->rebuild();
    $published = catalog_rows($catalog);

    expect($outcome->published())->toBeTrue((string) $outcome->message)
        ->and($outcome->report->replayed)->toBe(1)
        ->and(DB::table('facet_changes')->count())->toBe(0);

    expect($catalog->rebuild()->published())->toBeTrue()
        ->and(catalog_rows($catalog))->toBe($published);
})->with(catalog_connections(sqliteScreens: 1, serverScreens: 1))->after(fn () => catalog_drop_all());

it('applies a second edit of a document whose mark was already taken', function (string $connection, int $rounds, bool $byRebuild): void {
    $catalog = catalog_writable($connection);
    $product = Product::query()->create(['name' => 'Twice', 'brand_id' => 1, 'category_id' => 1, 'price' => 1000, 'sales' => 0, 'active' => true]);

    // The first mark goes away while this process still counts the document as marked.
    if ($byRebuild) {
        expect($catalog->rebuild()->published())->toBeTrue();
    } else {
        app(Catalogs::class)->syncer('shop')->drain();
    }

    $product->update(['brand_id' => 2]);
    $catalog->drain();
    $live = catalog_rows($catalog);

    expect($catalog->rebuild()->published())->toBeTrue()
        ->and(catalog_rows($catalog))->toBe($live);
})->with(catalog_connections(sqliteScreens: 1, serverScreens: 1))
    ->with(['taken by a rebuild' => true, 'taken by another drain' => false])
    ->after(fn () => catalog_drop_all());

/**
 * @param  Catalog<Product>  $catalog
 */
function catalog_random_edit(Catalog $catalog): void
{
    $products = Product::withTrashed()->get();
    $variants = Variant::query()->get();
    $product = catalog_one($products);
    $variant = catalog_one($variants);

    switch (mt_rand(1, 12)) {
        case 1:
            DB::transaction(static function (): void {
                $created = Product::query()->create(['name' => 'New', 'brand_id' => mt_rand(1, 5), 'category_id' => 1, 'price' => mt_rand(1, 50) * 100, 'sales' => 0, 'active' => true]);
                $created->colors()->attach(mt_rand(1, 6));
                $created->variants()->create(['size' => ShopData::SIZES[mt_rand(0, 8)], 'stock' => 1]);
                // One label per value, as in the seed: two labels of one value differ between rebuilds by design.
                $value = mt_rand(1, 6);
                $created->features()->create(['group' => 'material', 'group_title' => 'Material', 'value' => $value === 5 ? 'Value Five' : 'v'.$value, 'label' => 'Value '.$value, 'sort' => $value % 3]);
            });

            break;
        case 2:
            $product?->update(['old_price' => mt_rand(0, 1) === 1 ? null : 99999, 'brand_id' => mt_rand(0, 3) === 0 ? null : mt_rand(1, 5)]);

            break;
        case 3:
            $variant?->addStock(mt_rand(0, 1) === 1 ? 2 : -2);

            break;
        case 4:
            $product?->colors()->attach(mt_rand(1, 6));

            break;
        case 5:
            $product?->colors()->detach(mt_rand(1, 6));

            break;
        case 6:
            $product?->trashed() ? $product->restore() : $product?->delete();

            break;
        case 7:
            $variant?->update(['product_id' => catalog_one($products)?->id]);

            break;
        case 8:
            // The slug keeps the brand id: two brands under one slug would be one value with two labels.
            $brand = catalog_one(Brand::query()->get());
            $brand?->update(['name' => 'Renamed '.mt_rand(1, 99), 'slug' => 'brand-'.$brand->id.'-'.mt_rand(1, 99)]);

            break;
        case 9:
            // A write Eloquent does not report, then touch() for it.
            $query = Product::query()->where('category_id', mt_rand(1, 4));
            (clone $query)->update(['old_price' => DB::raw('price + 100')]);
            $catalog->touch($query);

            break;
        case 10:
            catalog_one(Feature::query()->get())?->delete();

            break;
        case 11:
            catalog_one(Color::query()->get())?->update(['name' => 'Color '.mt_rand(1, 99)]);

            break;
        default:
            $product?->forceDelete();
    }
}

/**
 * One item picked with mt_rand, so a seed replays the same edits: Collection::random() ignores mt_srand().
 *
 * @template T
 *
 * @param  Collection<int, T>  $items
 * @return T|null
 */
function catalog_one(Collection $items): mixed
{
    return $items->isEmpty() ? null : $items->values()->get(mt_rand(0, $items->count() - 1));
}
