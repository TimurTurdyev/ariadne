<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TimurTurdyev\Ariadne\AriadneServiceProvider;
use TimurTurdyev\Ariadne\Catalog\Catalog;
use TimurTurdyev\Ariadne\Catalog\Catalogs;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Product;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopCatalog;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopData;

/**
 * SQLite always; MariaDB and PostgreSQL when ARIADNE_MARIADB or ARIADNE_PGSQL name a separate instance as host:port.
 * The server tests drop every shop_ and facet_ table of database ariadne_test there: never point them at real data.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function catalog_connections(int $sqliteScreens, int $serverScreens): array
{
    $connections = ['sqlite' => ['testing', $sqliteScreens]];

    if (is_string(getenv('ARIADNE_MARIADB')) && getenv('ARIADNE_MARIADB') !== '') {
        $connections['mariadb'] = ['ariadne_mariadb', $serverScreens];
    }

    if (is_string(getenv('ARIADNE_PGSQL')) && getenv('ARIADNE_PGSQL') !== '') {
        $connections['pgsql'] = ['ariadne_pgsql', $serverScreens];
    }

    return $connections;
}

function catalog_use_connection(string $name): void
{
    $config = app(Repository::class);
    $server = match ($name) {
        'ariadne_mariadb' => ['mariadb', (string) getenv('ARIADNE_MARIADB'), 'root'],
        'ariadne_pgsql' => ['pgsql', (string) getenv('ARIADNE_PGSQL'), 'postgres'],
        default => null,
    };

    if ($server !== null) {
        [$driver, $address, $user] = $server;
        [$host, $port] = explode(':', $address, 2) + [1 => ''];
        $config->set("database.connections.{$name}", [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => 'ariadne_test',
            'username' => $user,
            'password' => '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'search_path' => 'public',
        ]);
    }

    $config->set('database.default', $name);
    DB::purge($name);
    ShopCatalog::$rules = null;
    ShopCatalog::$described = null;
    ShopCatalog::$whileProducing = null;
    catalog_drop_all();
}

function catalog_drop_all(): void
{
    $schema = Schema::connection(null);

    foreach ($schema->getTableListing($schema->getCurrentSchemaListing(), false) as $table) {
        if (str_starts_with($table, 'shop_') || str_starts_with($table, 'facet_')) {
            $schema->drop($table);
        }
    }

    ShopCatalog::$rules = null;
    ShopCatalog::$described = null;
    ShopCatalog::$whileProducing = null;
}

/**
 * @return Catalog<Product>
 */
function catalog_writable(string $connection): Catalog
{
    catalog_use_connection($connection);
    ShopData::create();
    ShopData::installFacetTables();
    ShopData::seed(60, 5);
    app(Repository::class)->set('ariadne.catalogs', ['shop' => ShopCatalog::class]);
    app()->forgetInstance(Catalogs::class);
    // The write listeners register at boot, and only with a catalog declared.
    app()->register(AriadneServiceProvider::class, force: true);
    $catalog = app(Catalogs::class)->for(ShopCatalog::class);
    expect($catalog->rebuild()->published())->toBeTrue();

    return $catalog;
}

/**
 * Every row of the live generation with the label of its value; covers on exactly one row of each document and group.
 *
 * @param  Catalog<Product>  $catalog
 * @return list<string>
 */
function catalog_rows(Catalog $catalog): array
{
    $state = DB::table('facet_catalogs')->where('catalog', 'shop')->first();
    $generation = is_object($state) && is_numeric($state->generation ?? null) ? (int) $state->generation : 0;
    $rows = [];
    $covers = [];

    foreach (DB::table("facet_rows_shop_{$generation}".' as r')->join('facet_values as v', 'v.id', '=', 'r.value_id')->get(['r.*', 'v.label']) as $one) {
        $row = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', (array) $one);
        $rows[] = $row['document_id'].'|'.$row['group_id'].'|'.$row['value_id'].'|'.$row['covers'].'|'.$row['label'];
        $pair = $row['document_id'].'|'.$row['group_id'];
        $covers[$pair] = ($covers[$pair] ?? 0) + (int) $row['covers'];
    }

    expect(array_filter($covers, static fn (int $sum): bool => $sum !== 1))->toBe([]);
    sort($rows);

    return $rows;
}
