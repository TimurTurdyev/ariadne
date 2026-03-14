<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use TimurTurdyev\Ariadne\Catalog\Catalog;
use TimurTurdyev\Ariadne\Catalog\Listener;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Brand;
use TimurTurdyev\Ariadne\Tests\Support\Shop\Product;
use TimurTurdyev\Ariadne\Tests\Support\Shop\ShopCatalog;

require_once __DIR__.'/catalog_helpers.php';

/**
 * Session A edits in an open transaction, so its mark gets the smaller id; session B commits its own edit;
 * the rebuild fills; A commits before the replay reads the marks or after the flip. Both edits must show.
 */
it('keeps an edit committed around the replay', function (string $connection, bool $beforeReplay): void {
    $catalog = catalog_writable($connection);
    $b = catalog_second_session($connection);
    $first = Product::query()->where('active', true)->oldest('id')->get();
    $x = $first->get(0) ?? throw new LogicException('The seed has no active product.');
    $y = $first->get(1) ?? throw new LogicException('The seed has one active product.');
    $brandX = Brand::query()->where('id', '!=', $x->brand_id ?? 0)->firstOrFail();
    $brandY = Brand::query()->where('id', '!=', $y->brand_id ?? 0)->firstOrFail();

    DB::connection($connection)->beginTransaction();
    $x->brand_id = $brandX->id;
    $x->save();

    // Session B is the default connection from here on: its edit, the rebuild and the drain run there.
    app(Repository::class)->set('database.default', $b);
    $other = Product::query()->findOrFail($y->id);
    $other->brand_id = $brandY->id;
    $other->save();

    if ($beforeReplay) {
        ShopCatalog::$whileProducing = static function (Product $product) use ($connection, $y): void {
            if ($product->id > $y->id + 20) {
                ShopCatalog::$whileProducing = null;
                DB::connection($connection)->commit();
            }
        };
    }

    $outcome = $catalog->rebuild();
    expect($outcome->published())->toBeTrue((string) $outcome->message);

    if (! $beforeReplay) {
        DB::connection($connection)->commit();
        $catalog->drain();
    }

    expect(DB::table('facet_changes')->count())->toBe(0)
        ->and(catalog_brand_of($catalog, $x->id))->toBe($brandX->slug)
        ->and(catalog_brand_of($catalog, $y->id))->toBe($brandY->slug);

    $live = catalog_rows($catalog);
    expect($catalog->rebuild()->published())->toBeTrue()
        ->and(catalog_rows($catalog))->toBe($live);
})->with(catalog_server_connections())->with(['before the replay' => true, 'after the flip' => false])
    ->skip(catalog_server_connections() === ['no server' => ['']], 'needs ARIADNE_MARIADB or ARIADNE_PGSQL: host:port of a separate instance')
    ->after(fn () => catalog_drop_all());

it('commits the edit when its mark cannot be written', function (string $connection): void {
    $logged = catalog_log_errors();
    $catalog = catalog_writable($connection);
    $b = catalog_second_session($connection);
    $x = Product::query()->where('active', true)->oldest('id')->firstOrFail();
    $brand = Brand::query()->where('id', '!=', $x->brand_id ?? 0)->firstOrFail();

    DB::connection($b)->statement('ALTER TABLE facet_changes RENAME TO facet_changes_off');

    try {
        // Without the savepoint PostgreSQL would answer 25P02 to everything after the failed insert.
        DB::transaction(static function () use ($x, $brand): void {
            $x->brand_id = $brand->id;
            $x->save();
            Product::query()->whereKey($x->id)->update(['sales' => 99]);
        });
    } finally {
        DB::connection($b)->statement('ALTER TABLE facet_changes_off RENAME TO facet_changes');
    }

    $stored = DB::table('shop_products')->where('id', $x->id)->first();
    expect($stored?->brand_id)->toEqual($brand->id)
        ->and($stored?->sales)->toEqual(99)
        ->and(implode("\n", $logged->messages))->toContain('lost the marks');
    unset($catalog);
})->with(catalog_server_connections('pgsql'))
    ->skip(catalog_server_connections('pgsql') === ['no server' => ['']], 'needs ARIADNE_PGSQL: host:port of a separate PostgreSQL instance')
    ->after(fn () => catalog_drop_all());

it('lets a deadlock on the mark reach the user without a partial write', function (string $connection): void {
    $catalog = catalog_writable($connection);
    $x = Product::query()->where('active', true)->oldest('id')->firstOrFail();
    $brand = Brand::query()->where('id', '!=', $x->brand_id ?? 0)->firstOrFail();
    [$host, $port] = explode(':', (string) getenv('ARIADNE_MARIADB'), 2);
    $b = new mysqli($host, 'root', '', 'ariadne_test', (int) $port);

    // B is the heavier transaction, so InnoDB picks A as the victim.
    $b->begin_transaction();
    $b->query('UPDATE shop_variants SET stock = stock + 1');
    $b->query("SELECT id FROM facet_changes WHERE catalog = 'shop' AND id > 0 FOR UPDATE");

    DB::beginTransaction();
    DB::table('shop_products')->where('id', $x->id)->lockForUpdate()->first();
    $b->query('SELECT id FROM shop_products WHERE id = '.$x->id.' FOR UPDATE', MYSQLI_ASYNC);
    $thrown = null;

    try {
        $x->brand_id = $brand->id;
        $x->save();
    } catch (QueryException $deadlock) {
        $thrown = $deadlock;
    }

    while (DB::transactionLevel() > 0) {
        try {
            DB::rollBack();
        } catch (Throwable) {
            break;
        }
    }

    $b->reap_async_query();
    $b->rollback();
    $b->close();

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and((string) $thrown?->getCode())->toBe('40001')
        ->and(DB::table('shop_products')->where('id', $x->id)->value('brand_id'))->toEqual($x->getOriginal('brand_id'))
        ->and(DB::table('facet_changes')->count())->toBe(0);
    unset($catalog);
})->with(catalog_server_connections('mariadb'))
    ->skip(catalog_server_connections('mariadb') === ['no server' => ['']], 'needs ARIADNE_MARIADB: host:port of a separate MariaDB instance')
    ->after(fn () => catalog_drop_all());

/**
 * @param  'mariadb'|'pgsql'|null  $driver
 * @return array<string, array{0: string}>
 */
function catalog_server_connections(?string $driver = null): array
{
    $servers = [];

    foreach (catalog_connections(1, 1) as $label => [$connection]) {
        if ($connection !== 'testing' && ($driver === null || $connection === 'ariadne_'.$driver)) {
            $servers[$label] = [$connection];
        }
    }

    // Pest refuses an empty dataset: a placeholder row that skip() then skips.
    return $servers === [] ? ['no server' => ['']] : $servers;
}

/**
 * A second session on the same database, as another process would have.
 */
function catalog_second_session(string $connection): string
{
    $config = app(Repository::class);
    $settings = $config->get("database.connections.{$connection}");
    $config->set("database.connections.{$connection}_b", $settings);

    return "{$connection}_b";
}

/**
 * @param  Catalog<Product>  $catalog
 */
function catalog_brand_of(Catalog $catalog, int $document): ?string
{
    $generation = DB::table('facet_catalogs')->where('catalog', 'shop')->value('generation');
    $value = DB::table('facet_rows_shop_'.(is_numeric($generation) ? (int) $generation : 0).' as r')
        ->join('facet_groups as g', 'g.id', '=', 'r.group_id')
        ->join('facet_values as v', 'v.id', '=', 'r.value_id')
        ->where('r.document_id', $document)
        ->where('g.slug', 'brand')
        ->value('v.value');

    return is_string($value) ? $value : null;
}

/**
 * @return object{messages: list<string>}
 */
function catalog_log_errors(): object
{
    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (is_string($level) ? $level : 'log').': '.$message;
        }
    };

    app()->instance(LoggerInterface::class, $logger);
    app()->forgetInstance(Listener::class);

    return $logger;
}
