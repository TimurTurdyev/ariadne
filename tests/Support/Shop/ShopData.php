<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Schema and random data of the test shop, with the dirt real data has: bad slugs, NULLs, hidden products.
 */
final class ShopData
{
    public const SIZES = ['XS', 'S', 'M', 'L', 'XL', '42', '44', 'One size', '!!!'];

    /** Group slugs from data: the last ones are refused for being no slug, a codec parameter, digits, a declared facet. */
    public const GROUPS = [
        'material' => 'Material',
        'season' => 'Season',
        'pattern' => 'Pattern',
        'fit' => 'Fit',
        'rare' => 'Rare',
        'Цвет каркаса' => 'Frame color',
        'price' => 'Price',
        '42' => 'Forty two',
        'brand' => 'Brand again',
    ];

    public static function create(): void
    {
        Schema::create('shop_brands', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->string('name');
        });

        Schema::create('shop_colors', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->string('name');
            $table->string('hex');
        });

        Schema::create('shop_products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedInteger('category_id');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->unsignedInteger('price');
            $table->unsignedInteger('old_price')->nullable();
            $table->unsignedInteger('sales');
            $table->dateTime('published_at')->nullable();
            $table->boolean('active');
            $table->softDeletes();
        });

        Schema::create('shop_product_color', static function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('color_id');
        });

        Schema::create('shop_variants', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('size');
            $table->integer('stock');
        });

        Schema::create('shop_features', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('group');
            $table->string('group_title');
            $table->string('value');
            $table->string('label');
            $table->integer('sort');
        });
    }

    /**
     * The facet tables as ariadne:install writes them for this prefix.
     */
    public static function installFacetTables(string $prefix = 'facet_'): Migration
    {
        $stub = file_get_contents(__DIR__.'/../../../stubs/create_facet_tables.php.stub');

        if ($stub === false) {
            throw new RuntimeException('The migration stub is missing.');
        }

        $path = tempnam(sys_get_temp_dir(), 'ariadne_migration_');

        if ($path === false) {
            throw new RuntimeException('No temporary file for the migration.');
        }

        file_put_contents($path, str_replace(
            ['{{connection}}', '{{catalogs}}', '{{groups}}', '{{values}}', '{{changes}}', '{{rows}}'],
            ['', $prefix.'catalogs', $prefix.'groups', $prefix.'values', $prefix.'changes', $prefix.'rows_'],
            $stub,
        ));
        $migration = require $path;
        unlink($path);

        if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
            throw new RuntimeException('The migration stub does not return a migration.');
        }

        $migration->up();

        return $migration;
    }

    /**
     * @param  int  $products  how many products; a tenth of them hidden
     */
    public static function seed(int $products, int $seed): void
    {
        mt_srand($seed);
        $brands = [];

        foreach (['acme', 'Bolt', 'cobalt', 'delta', 'echo'] as $at => $slug) {
            $brands[] = DB::table('shop_brands')->insertGetId(['slug' => $slug, 'name' => ucfirst($slug).' '.($at % 2 === 0 ? 'Ltd' : 'Inc')]);
        }

        $colors = [];

        foreach (['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue', 'black' => 'Black', 'white' => 'White', 'navy-blue' => 'Navy blue'] as $slug => $name) {
            $colors[] = DB::table('shop_colors')->insertGetId(['slug' => $slug, 'name' => $name, 'hex' => '#'.substr(md5($slug), 0, 6)]);
        }

        for ($i = 1; $i <= $products; $i++) {
            $price = mt_rand(1, 400) * 50;
            $id = DB::table('shop_products')->insertGetId([
                'name' => "Product {$i}",
                'brand_id' => mt_rand(1, 10) === 1 ? null : $brands[mt_rand(0, count($brands) - 1)],
                'category_id' => mt_rand(1, 4),
                'model_id' => mt_rand(1, 3) === 1 ? null : mt_rand(1, (int) ceil($products / 3)),
                'price' => $price,
                'old_price' => mt_rand(1, 4) === 1 ? $price + mt_rand(1, 20) * 50 : null,
                'sales' => mt_rand(0, 6),
                'published_at' => mt_rand(1, 5) === 1 ? null : sprintf('2026-%02d-%02d 12:00:00', mt_rand(1, 12), mt_rand(1, 28)),
                'active' => mt_rand(1, 10) !== 1,
            ]);

            foreach ((array) array_rand(array_flip($colors), mt_rand(1, 3)) as $color) {
                DB::table('shop_product_color')->insert(['product_id' => $id, 'color_id' => $color]);
            }

            for ($v = mt_rand(0, 3); $v > 0; $v--) {
                DB::table('shop_variants')->insert(['product_id' => $id, 'size' => self::SIZES[mt_rand(0, count(self::SIZES) - 1)], 'stock' => mt_rand(-1, 3)]);
            }

            foreach (self::GROUPS as $group => $title) {
                $chance = match ($group) {
                    'rare' => 30,
                    'material', 'season' => 2,
                    default => 4,
                };

                if (mt_rand(1, $chance) !== 1) {
                    continue;
                }

                $value = mt_rand(1, 5);
                DB::table('shop_features')->insert([
                    'product_id' => $id,
                    'group' => $group,
                    'group_title' => $title,
                    'value' => $value === 5 ? 'Value Five' : "v{$value}",
                    'label' => 'Value '.$value,
                    'sort' => $value % 3,
                ]);
            }
        }
    }
}
