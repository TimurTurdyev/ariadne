<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use Psr\Log\LoggerInterface;
use TimurTurdyev\Ariadne\Catalog\Database\Journal;
use TimurTurdyev\Ariadne\Catalog\Database\Locks;
use TimurTurdyev\Ariadne\Catalog\Database\Rebuilder;
use TimurTurdyev\Ariadne\Catalog\Database\Syncer;
use TimurTurdyev\Ariadne\Catalog\Database\Tables;

/**
 * The catalogs of config ariadne.catalogs, each compiled on first use and kept for the process.
 */
final class Catalogs
{
    /** @var array<string, CatalogSpec> */
    private array $compiled = [];

    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
    ) {}

    /**
     * The catalog of a definition class, typed by its model: for(ProductCatalog::class) lists Products.
     *
     * @template T of Model
     *
     * @param  class-string<CatalogDefinition<T>>  $definition
     * @return Catalog<T>
     */
    public function for(string $definition): Catalog
    {
        $spec = $this->spec($definition);

        /** @var Catalog<T> */
        return new Catalog($spec, $spec->model, $this);
    }

    /**
     * @return Catalog<Model>
     */
    public function get(string $name): Catalog
    {
        $spec = $this->spec($name);

        return new Catalog($spec, $spec->model, $this);
    }

    /**
     * Marks the documents of a query in every catalog of its model.
     *
     * @param  Builder<covariant Model>|Relation<covariant Model, covariant Model, mixed>  $documents
     */
    public function touch(Builder|Relation $documents): void
    {
        $model = ($documents instanceof Relation ? $documents->getQuery() : $documents)->getModel();

        foreach ($this->names() as $name) {
            $spec = $this->spec($name);

            if ($spec->model instanceof $model && $model instanceof $spec->model) {
                $this->get($name)->touch($documents);
            }
        }
    }

    /**
     * Runs work without marking anything: imports and seeders, followed by a rebuild.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function withoutSync(Closure $work): mixed
    {
        return $this->listener()->withoutSync($work);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->declared());
    }

    /**
     * @param  string  $name  a catalog key or its definition class
     *
     * @throws InvalidCatalog
     */
    public function spec(string $name): CatalogSpec
    {
        $declared = $this->declared();
        $name = $this->resolve($name, $declared);

        return $this->compiled[$name] ??= CatalogSpec::compile(
            $name,
            $declared[$name],
            $this->prefix(),
            $this->locale(),
            $this->app,
        );
    }

    /**
     * Every declared catalog, compiled: the static checks of optimize and install.
     *
     * @return list<CatalogSpec>
     *
     * @throws InvalidCatalog
     */
    public function specs(): array
    {
        $specs = [];
        $problems = [];

        foreach ($this->names() as $name) {
            try {
                $specs[] = $this->spec($name);
            } catch (InvalidCatalog $invalid) {
                foreach ($invalid->problems as $problem) {
                    $problems[] = "{$name}: {$problem}";
                }
            }
        }

        if ($problems !== []) {
            throw new InvalidCatalog(implode(', ', $this->names()), $problems);
        }

        $connections = array_unique(array_map(static fn (CatalogSpec $spec): string => (string) $spec->connection, $specs));

        if (count($connections) > 1) {
            throw new InvalidCatalog(implode(', ', $this->names()), ['all catalogs share the facet tables, so their models need one connection; found: '.implode(', ', array_map(static fn (string $one): string => $one === '' ? 'default' : $one, $connections))]);
        }

        return $specs;
    }

    /**
     * @internal
     */
    public function tables(string $name): Tables
    {
        $db = $this->app->make(DatabaseManager::class);

        return new Tables($db->connection($this->spec($name)->connection), $this->prefix());
    }

    /**
     * @internal
     */
    public function locks(): Locks
    {
        $store = $this->config->get('ariadne.sync.lock_store');
        $seconds = $this->config->get('ariadne.rebuild.lock_seconds', 3600);

        return new Locks($this->app->make(CacheFactory::class), is_string($store) ? $store : null, is_int($seconds) && $seconds > 0 ? $seconds : 3600);
    }

    /**
     * @internal
     */
    public function journal(string $name): Journal
    {
        return new Journal($this->tables($name));
    }

    /**
     * @internal
     */
    public function syncer(string $name): Syncer
    {
        return new Syncer($this->spec($name), $this->tables($name), $this->locks(), $this->journal($name), $this->app->make(Dispatcher::class), $this->logger());
    }

    /**
     * @internal
     */
    public function rebuilder(string $name): Rebuilder
    {
        return new Rebuilder($this->spec($name), $this->tables($name), $this->locks(), $this->journal($name), $this->app->make(Dispatcher::class), $this->logger());
    }

    /**
     * @internal
     */
    public function listener(): Listener
    {
        return $this->app->make(Listener::class);
    }

    /**
     * @internal
     */
    public function logger(): LoggerInterface
    {
        return $this->app->make(LoggerInterface::class);
    }

    /**
     * @internal
     */
    public function cacheStore(string $store): CacheRepository
    {
        $repository = $this->app->make(CacheFactory::class)->store($store);

        if (! $repository instanceof CacheRepository) {
            throw new LogicException("Cache store [{$store}] does not give an Illuminate repository.");
        }

        return $repository;
    }

    public function prefix(): string
    {
        $prefix = $this->config->get('ariadne.tables.prefix', 'facet_');

        return is_string($prefix) ? $prefix : 'facet_';
    }

    /**
     * @return array<string, mixed>
     */
    private function declared(): array
    {
        $declared = $this->config->get('ariadne.catalogs', []);

        return is_array($declared) ? array_filter($declared, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param  array<string, mixed>  $declared
     */
    private function resolve(string $name, array $declared): string
    {
        if (isset($declared[$name])) {
            return $name;
        }

        $found = [];

        foreach ($declared as $key => $entry) {
            $class = is_array($entry) ? ($entry['definition'] ?? null) : $entry;

            if ($class === $name) {
                $found[] = $key;
            }
        }

        if (count($found) > 1) {
            throw new InvalidCatalog(implode(', ', $found), ["{$name} is declared under several keys, so it cannot name one catalog"]);
        }

        if ($found === []) {
            throw new LogicException("Catalog [{$name}] is not declared in config ariadne.catalogs; declared: ".(implode(', ', array_keys($declared)) ?: 'none').'.');
        }

        return $found[0];
    }

    private function locale(): string
    {
        $locale = $this->config->get('app.locale', 'en');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}
