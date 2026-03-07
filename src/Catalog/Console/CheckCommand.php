<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Console;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TimurTurdyev\Ariadne\Catalog\Catalogs;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;
use TimurTurdyev\Ariadne\Catalog\Database\Applier;
use TimurTurdyev\Ariadne\Catalog\Database\Journal;
use TimurTurdyev\Ariadne\Catalog\Database\Projector;
use TimurTurdyev\Ariadne\Catalog\Database\RebuildReport;
use TimurTurdyev\Ariadne\Catalog\Database\Sql;
use TimurTurdyev\Ariadne\Catalog\Database\State;
use TimurTurdyev\Ariadne\Catalog\Database\Tables;
use TimurTurdyev\Ariadne\Catalog\InvalidCatalog;
use TimurTurdyev\Ariadne\Query\Need;

/**
 * Every developer mistake and data problem it can find, at once; exits 1 on errors, with --strict on warnings too.
 */
#[AsCommand(name: 'ariadne:check')]
final class CheckCommand extends Command
{
    /** Newest documents projected anew and compared with their stored rows. */
    private const FRESHNESS_SAMPLE = 20;

    /** Older marks mean nobody drains: the schedule or the deferred drain is missing. */
    private const STALE_MARK_SECONDS = 600;

    protected $signature = 'ariadne:check
        {catalog?* : catalog keys; every declared catalog by default}
        {--strict : fail on warnings too}
        {--static : only what needs no database, as on a build server}';

    protected $description = 'Check catalog definitions, the facet tables and the data of the last rebuild';

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(Catalogs $catalogs, CacheFactory $caches): int
    {
        $names = $this->argument('catalog');
        $names = is_array($names) && $names !== [] ? array_values(array_filter($names, is_string(...))) : $catalogs->names();

        if ($names === []) {
            $this->warn('No catalog is declared in config ariadne.catalogs.');
        }

        $connections = [];

        foreach ($names as $name) {
            $this->components->twoColumnDetail("<fg=cyan>{$name}</>");

            try {
                $spec = $catalogs->spec($name);
            } catch (InvalidCatalog $invalid) {
                foreach ($invalid->problems as $problem) {
                    $this->problem($problem);
                }

                continue;
            }

            $connections[(string) $spec->connection] = true;

            foreach ($spec->databaseOnly as $reason) {
                $this->note("the manticore driver of 0.4 will not take it: {$reason}");
            }

            if ($this->option('static') !== true) {
                $this->database($catalogs, $spec);
            }
        }

        if (count($connections) > 1) {
            $this->problem('all catalogs share the facet tables, so their models need one connection');
        }

        if ($this->option('static') !== true) {
            $this->environment($catalogs, $caches);
        }

        $this->newLine();
        $this->line("{$this->errors} errors, {$this->warnings} warnings");

        return $this->errors > 0 || ($this->option('strict') === true && $this->warnings > 0) ? self::FAILURE : self::SUCCESS;
    }

    private function database(Catalogs $catalogs, CatalogSpec $spec): void
    {
        $tables = $catalogs->tables($spec->name);
        $schema = $tables->db->getSchemaBuilder();

        foreach ([$tables->catalogs(), $tables->groups(), $tables->values(), $tables->changes()] as $table) {
            if (! $schema->hasTable($table)) {
                $this->problem("table {$table} is missing: run ariadne:install and migrate");

                return;
            }
        }

        $this->binaryColumns($tables);
        $this->ddlRights($tables);
        $this->queries($spec);
        $state = $tables->state($spec->name);

        if ($state->generation === 0) {
            $this->problem("nothing is published: run ariadne:rebuild {$spec->name}");

            return;
        }

        $this->info("  generation {$state->generation}, {$state->documents} documents, {$state->rows} rows, built ".($state->builtAt === null ? '?' : Carbon::createFromTimestamp($state->builtAt)->toDateTimeString()).($state->buildSeconds === null ? '' : " in {$state->buildSeconds} s"));

        foreach ($state->problems as $problem) {
            $this->problem("the last rebuild left out \"{$problem['facet']}\" ({$problem['documents']}): {$problem['reason']}");
        }

        $lockSeconds = $catalogs->locks()->rebuildSeconds;

        if ($state->buildSeconds !== null && $state->buildSeconds > $lockSeconds / 2) {
            $this->warnAbout("the last rebuild took {$state->buildSeconds} s, more than half of rebuild.lock_seconds ({$lockSeconds})");
        }

        $this->rows($tables, $spec, $state);
        $this->freshness($tables, $spec, $state);
        $this->marks($catalogs->journal($spec->name), $spec, $state);
        $this->generations($tables, $spec);
        $this->screenCache($catalogs, $spec);
    }

    private function binaryColumns(Tables $tables): void
    {
        if (! in_array($tables->db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ([[$tables->groups(), 'slug'], [$tables->values(), 'value']] as [$table, $column]) {
            $found = $tables->db->selectOne('SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            $collation = is_object($found) && isset($found->c) && is_string($found->c) ? $found->c : '';

            if (! str_ends_with($collation, '_bin')) {
                $this->problem("{$table}.{$column} compares with {$collation}: 'Acme' and 'acme' would be one value; it must be utf8mb4_bin");
            }
        }
    }

    private function ddlRights(Tables $tables): void
    {
        $probe = $tables->prefix.'rows_check_probe_'.getmypid();

        try {
            $tables->db->statement('CREATE TABLE '.$tables->quote($probe).' (id INTEGER NOT NULL)');
            $tables->db->statement('DROP TABLE '.$tables->quote($probe));
        } catch (Throwable $denied) {
            $this->problem('the connection cannot create and drop tables, which every rebuild does: '.$denied->getMessage());
        }
    }

    /**
     * Every sort, field, card and the price run once, so a misspelled column fails here and not on a buyer.
     */
    private function queries(CatalogSpec $spec): void
    {
        $model = $spec->model;
        $documents = static fn (): Builder => $spec->definition->documents()->toBase()->limit(1);
        $checks = [];

        foreach ($spec->sorts as $sort) {
            if ($sort->relevance) {
                continue;
            }

            $order = $sort->columns === null ? $sort->orderBy : implode(', ', array_map(static fn (array $column): string => $model->qualifyColumn($column[0]).' '.$column[1], $sort->columns));
            // The builder writes a direction after the expression; the key takes it, as in the index.
            $order .= ', '.$model->qualifyColumn($model->getKeyName());
            $checks["sort \"{$sort->slug}\""] = static fn (): mixed => $documents()->orderBy(new Sql($order))->get();
        }

        foreach ($spec->fields as $name => $field) {
            $checks["field \"{$name}\""] = $field['kind'] === 'belongs-to-many'
                ? static fn (): mixed => $documents()->whereIn($model->qualifyColumn($model->getKeyName()), static fn (Builder $pivot): Builder => $pivot->from((string) ($field['pivot'] ?? ''))->whereIn((string) ($field['related'] ?? ''), [0])->select((string) ($field['foreign'] ?? '')))->get()
                : static fn (): mixed => $documents()->whereIn($model->qualifyColumn($field['column']), [0])->get();
        }

        if ($spec->priceColumn !== null) {
            $checks['#[Price]'] = static fn (): mixed => $documents()->select($model->qualifyColumn((string) $spec->priceColumn))->get();
        }

        if ($spec->cardColumn !== null) {
            $checks['#[OneCardPer]'] = static fn (): mixed => $documents()->select($model->qualifyColumn((string) $spec->cardColumn))->get();
        }

        foreach ($checks as $what => $check) {
            try {
                $check();
            } catch (Throwable $broken) {
                $this->problem("{$what} does not run: ".$broken->getMessage());
            }
        }
    }

    /**
     * Groups and rows of the live generation against the definition.
     */
    private function rows(Tables $tables, CatalogSpec $spec, State $state): void
    {
        $rows = $tables->rows($spec->name, $state->generation);
        $counts = [];

        foreach ($tables->table($rows)->select(['group_id', new Sql('COUNT(*) AS n')])->groupBy('group_id')->get() as $one) {
            $row = (array) $one;
            $counts[is_numeric($row['group_id'] ?? null) ? (int) $row['group_id'] : 0] = is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;
        }

        $dynamic = [];

        foreach ($spec->facets as $facet) {
            if ($facet->isDynamic()) {
                $dynamic[$facet->slug] = true;
            }
        }

        foreach ($tables->table($tables->groups())->where('catalog', $spec->name)->get(['id', 'slug', 'producer', 'multiple']) as $one) {
            $group = (array) $one;
            $id = is_numeric($group['id'] ?? null) ? (int) $group['id'] : 0;
            $slug = is_string($group['slug'] ?? null) ? $group['slug'] : '';
            $producer = is_string($group['producer'] ?? null) ? $group['producer'] : null;
            $declared = $spec->declared($slug);

            if ($declared !== null && ($counts[$id] ?? 0) === 0) {
                $this->warnAbout("facet \"{$slug}\" has no rows: no document carries a value of it");
            }

            if ($declared === null && ($counts[$id] ?? 0) > 0 && ($producer === null || ! isset($dynamic[$producer]))) {
                $this->warnAbout("group \"{$slug}\" has rows but no facet of the definition makes it: a rebuild drops them");
            }

            if (! (bool) ($group['multiple'] ?? true) && ($counts[$id] ?? 0) > 0) {
                $twice = $tables->table($rows)->where('group_id', $id)->groupBy('document_id')->havingRaw('COUNT(*) > 1')->limit(1)->value('document_id');

                if (is_scalar($twice)) {
                    $this->problem("group \"{$slug}\" is single(), yet document {$twice} carries two of its values");
                }
            }
        }

        $model = $spec->model;
        $without = $spec->definition->documents()->toBase()
            ->whereNotIn($model->qualifyColumn($model->getKeyName()), static fn (Builder $stored): Builder => $stored->from($rows)->select('document_id'))
            ->count();

        if ($without > 0) {
            $this->note("{$without} visible documents carry no facet value at all");
        }
    }

    /**
     * The newest documents projected anew: a write that bypassed the events shows up as a difference.
     */
    private function freshness(Tables $tables, CatalogSpec $spec, State $state): void
    {
        $model = $spec->model;
        $projector = new Projector($spec, $tables, new RebuildReport, rebuilding: false);
        $queries = 0;
        $tables->db->listen(static function () use (&$queries): void {
            $queries++;
        });

        $query = $model->newQuery();
        $query->with((new Applier($spec, $tables))->relations());
        $query->orderByDesc($model->getKeyName());
        $query->limit(self::FRESHNESS_SAMPLE);
        $documents = $query->get();
        $loaded = $queries;
        $stale = [];

        foreach ($documents as $document) {
            $key = $document->getKey();
            $id = is_numeric($key) ? (int) $key : 0;
            $expected = $projector->preview($document);
            $stored = [];

            foreach ($tables->table($tables->rows($spec->name, $state->generation).' as r')
                ->join($tables->groups().' as g', 'g.id', '=', 'r.group_id')
                ->join($tables->values().' as v', 'v.id', '=', 'r.value_id')
                ->where('r.document_id', $id)
                ->get(['g.slug', 'v.value']) as $one) {
                $row = (array) $one;
                $stored[] = (is_string($row['slug'] ?? null) ? $row['slug'] : '').'|'.(is_string($row['value'] ?? null) ? $row['value'] : '');
            }

            sort($stored);

            if ($expected !== $stored) {
                $stale[] = $id;
            }
        }

        $perDocument = $documents->count() > 0 ? ($queries - $loaded - count($documents)) / $documents->count() : 0;

        if ($perDocument >= 1) {
            $this->warnAbout(sprintf('producers run %.1f queries per document: name what they read in ->with()', $perDocument));
        }

        if ($stale !== []) {
            $this->problem('stored rows differ from the producers for documents '.implode(', ', array_slice($stale, 0, 10)).': an edit bypassed the events; touch() it or rebuild');
        }
    }

    private function marks(Journal $journal, CatalogSpec $spec, State $state): void
    {
        $stats = $journal->stats($spec->name);
        $now = Carbon::now()->getTimestamp();

        if ($stats['count'] > 0) {
            $this->note("{$stats['count']} edits wait for a sync");
        }

        if (! $state->isBuilding($now) && $stats['oldest'] !== null && $now - $stats['oldest'] > self::STALE_MARK_SECONDS) {
            $this->problem('the oldest pending edit is '.($now - $stats['oldest']).' s old: schedule ariadne:sync every minute');
        }

        if ($state->rebuildRequestedAt !== null) {
            $this->note('a rebuild is requested and waits for ariadne:sync');
        }
    }

    private function generations(Tables $tables, CatalogSpec $spec): void
    {
        $generations = $tables->generations($spec->name);
        $this->note(count($generations).' generation tables: '.implode(', ', $generations));
    }

    private function screenCache(Catalogs $catalogs, CatalogSpec $spec): void
    {
        if ($spec->cache === null) {
            return;
        }

        try {
            $catalog = $catalogs->get($spec->name);
            $first = $catalog->screenFor('/', [], Need::Everything);
            $second = $catalog->screenFor('/', [], Need::Everything);

            $keys = static fn (Model $item): mixed => $item->getKey();

            if ($first->total !== $second->total || $first->items->map($keys)->all() !== $second->items->map($keys)->all()) {
                $this->problem("a screen read back from cache store \"{$spec->cache}\" differs from the one built");
            }
        } catch (Throwable $broken) {
            $this->problem("the screen cache does not round trip: {$broken->getMessage()}");
        }
    }

    private function environment(Catalogs $catalogs, CacheFactory $caches): void
    {
        $locks = $catalogs->locks();

        if (! $locks->supportsLocks()) {
            $this->problem('cache store '.($locks->storeName() ?? 'default').' has no locks: set ariadne.sync.lock_store');
        } else {
            $store = $caches->store($locks->storeName())->getStore();

            if ($store instanceof ArrayStore || $store instanceof FileStore) {
                $this->warnAbout('locks live in a '.class_basename($store).': with several servers two rebuilds can run at once; use redis, memcached or database');
            }
        }

        if ($this->laravel->bound(HttpKernel::class)) {
            $kernel = $this->laravel->make(HttpKernel::class);

            if ($kernel instanceof Kernel && class_exists(InvokeDeferredCallbacks::class) && ! $kernel->hasMiddleware(InvokeDeferredCallbacks::class)) {
                $this->warnAbout('InvokeDeferredCallbacks is not in the HTTP middleware: edits wait for ariadne:sync instead of draining after the response');
            }
        }

        foreach ($catalogs->names() as $name) {
            try {
                $db = $catalogs->tables($name)->db;
            } catch (Throwable) {
                continue;
            }

            if ($db->getDriverName() === 'sqlite') {
                $mode = $db->selectOne('PRAGMA journal_mode');
                $value = is_object($mode) ? (array_values((array) $mode)[0] ?? '') : '';
                $journal = is_scalar($value) ? strtolower((string) $value) : '';

                if ($journal !== 'wal') {
                    $this->warnAbout("SQLite runs in {$journal} mode: a rebuild blocks readers; switch the database to WAL");
                }
            }

            break;
        }
    }

    private function problem(string $message): void
    {
        $this->errors++;
        $this->line("  <fg=red>error</> {$message}");
    }

    private function warnAbout(string $message): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>warning</> {$message}");
    }

    private function note(string $message): void
    {
        $this->line("  <fg=gray>note</> {$message}");
    }
}
