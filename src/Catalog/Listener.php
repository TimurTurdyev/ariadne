<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks the documents an Eloquent write touches, in the transaction of the write, and drains them after the response.
 *
 * The map of model classes to catalogs is built on the first write event, so booting and read-only requests
 * never compile a definition; a broken one is skipped with an error in the log and never breaks a save.
 *
 * @phpstan-type Watch array{catalog: string, kind: 'root'|'child'|'owner'|'pivot', path: string, parentPath: string, column: string, parentColumn: string, events: list<string>}
 */
final class Listener
{
    /** One edit of a relation touching more documents asks for a rebuild instead of marking them all. */
    public const FAN_OUT = 2000;

    public const EVENTS = ['created', 'saved', 'updated', 'deleting', 'deleted', 'restored', 'forceDeleted'];

    /** @var array<string, list<Watch>>|null class => what its writes mark */
    private ?array $map = null;

    /** @var array<string, array<int, true>> catalog => documents marked in this request */
    private array $marked = [];

    /** @var array<string, true> catalogs with a drain waiting for the end of the request */
    private array $deferred = [];

    private int $paused = 0;

    public function __construct(
        private readonly Catalogs $catalogs,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if ($this->paused > 0 || ! $model instanceof Model) {
            return;
        }

        $name = substr($event, 9, (int) strpos($event, ':') - 9);

        foreach ($this->map()[$model::class] ?? [] as $watch) {
            if (! in_array($name, $watch['events'], true)) {
                continue;
            }

            try {
                $documents = $this->documents($watch, $model);
            } catch (Throwable $failure) {
                $this->log->error("Catalog [{$watch['catalog']}] could not find the documents of a write of ".$model::class, ['error' => $failure->getMessage()]);

                continue;
            }

            if ($documents === null) {
                $this->catalogs->syncer($watch['catalog'])->requestRebuild();
            } elseif ($documents !== []) {
                $this->mark($watch['catalog'], $documents);
            }
        }
    }

    /**
     * Marks documents by hand: writes Eloquent does not report, such as a query builder update.
     *
     * @param  list<int>  $documents
     */
    public function mark(string $catalog, array $documents): void
    {
        $new = [];

        foreach ($documents as $document) {
            if (! isset($this->marked[$catalog][$document])) {
                $this->marked[$catalog][$document] = true;
                $new[] = $document;
            }
        }

        if ($new === []) {
            return;
        }

        $this->write($catalog, $new);
        $this->deferDrain($catalog);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function withoutSync(Closure $work): mixed
    {
        $this->paused++;

        try {
            return $work();
        } finally {
            $this->paused--;
        }
    }

    /**
     * Documents this request marked; taking them resets the count, so a later edit marks again.
     *
     * @return list<int>
     */
    public function take(string $catalog): array
    {
        $documents = array_keys($this->marked[$catalog] ?? []);
        unset($this->marked[$catalog], $this->deferred[$catalog]);

        return $documents;
    }

    /**
     * A rolled back transaction took its marks with it: the same edit retried must mark again.
     */
    public function rolledBack(): void
    {
        $this->marked = [];
    }

    /**
     * @param  Watch  $watch
     * @return list<int>|null documents to mark; null when there are too many and a rebuild is cheaper
     */
    private function documents(array $watch, Model $model): ?array
    {
        $spec = $this->catalogs->spec($watch['catalog']);
        $root = $spec->model;

        if ($watch['kind'] === 'root') {
            $key = $model->getKey();

            return is_numeric($key) ? [(int) $key] : [];
        }

        $query = $root->newQueryWithoutScopes();

        if ($watch['kind'] === 'owner') {
            $key = $model->getKey();
            $query->whereHas($watch['path'], static function (Builder $related) use ($key): void {
                $related->withoutGlobalScopes();
                $related->whereKey($key);
            });

            return $this->limited($query, $root);
        }

        $values = array_values(array_unique(array_filter(
            [$model->getAttribute($watch['column']), $model->getOriginal($watch['column'])],
            static fn (mixed $value): bool => is_int($value) || is_string($value),
        )));

        if ($values === []) {
            return [];
        }

        if ($watch['parentPath'] === '') {
            if ($watch['parentColumn'] === $root->getKeyName()) {
                return array_values(array_map(intval(...), array_filter($values, is_numeric(...))));
            }

            $query->whereIn($root->qualifyColumn($watch['parentColumn']), $values);

            return $this->limited($query, $root);
        }

        $column = $watch['parentColumn'];
        $query->whereHas($watch['parentPath'], static function (Builder $parent) use ($column, $values): void {
            $parent->withoutGlobalScopes();
            $parent->whereIn($parent->getModel()->qualifyColumn($column), $values);
        });

        return $this->limited($query, $root);
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<int>|null
     */
    private function limited(Builder $query, Model $root): ?array
    {
        $keys = $query->limit(self::FAN_OUT + 1)->pluck($root->getQualifiedKeyName())->all();

        if (count($keys) > self::FAN_OUT) {
            return null;
        }

        return array_values(array_map(intval(...), array_filter($keys, is_numeric(...))));
    }

    /**
     * The mark goes in the transaction of the edit inside its own savepoint: a failed insert rolls back only itself.
     *
     * @param  list<int>  $documents
     */
    private function write(string $catalog, array $documents): void
    {
        $journal = $this->catalogs->journal($catalog);
        $db = $this->catalogs->tables($catalog)->db;
        $now = Carbon::now()->getTimestamp();

        if ($db->transactionLevel() === 0) {
            try {
                $journal->mark($catalog, $documents, $now);
            } catch (Throwable $failure) {
                $this->lost($catalog, $documents, $failure);
            }

            return;
        }

        $db->beginTransaction();

        try {
            $journal->mark($catalog, $documents, $now);
            $db->commit();
        } catch (Throwable $failure) {
            try {
                $db->rollBack();
            } catch (Throwable) {
                // After a deadlock InnoDB has rolled back the whole transaction: the edit is gone, so is the savepoint.
                $this->forgetMarked($catalog, $documents);

                throw $failure;
            }

            $this->lost($catalog, $documents, $failure);
        }
    }

    /**
     * @param  list<int>  $documents
     */
    private function lost(string $catalog, array $documents, Throwable $failure): void
    {
        // Not counted as marked: a later edit of the same request tries again.
        $this->forgetMarked($catalog, $documents);
        $this->log->error("Catalog [{$catalog}] lost the marks of an edit until the nightly rebuild", ['documents' => $documents, 'error' => $failure->getMessage()]);
    }

    /**
     * @param  list<int>  $documents
     */
    private function forgetMarked(string $catalog, array $documents): void
    {
        foreach ($documents as $document) {
            unset($this->marked[$catalog][$document]);
        }
    }

    /**
     * Applying on save would project a half-written document: the drain waits for the end of the request.
     */
    private function deferDrain(string $catalog): void
    {
        if (isset($this->deferred[$catalog]) || ! function_exists('defer')) {
            return;
        }

        $this->deferred[$catalog] = true;

        defer(function () use ($catalog): void {
            $own = $this->take($catalog);
            $this->catalogs->syncer($catalog)->drain($own);
        }, 'ariadne:drain:'.$catalog)->always();
    }

    /**
     * @return array<string, list<Watch>>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];

        foreach ($this->catalogs->names() as $name) {
            try {
                $spec = $this->catalogs->spec($name);
            } catch (Throwable $invalid) {
                $this->log->error("Catalog [{$name}] does not sync: its definition does not compile", ['error' => $invalid->getMessage()]);

                continue;
            }

            $map[$spec->model::class][] = ['catalog' => $name, 'kind' => 'root', 'path' => '', 'parentPath' => '', 'column' => '', 'parentColumn' => '', 'events' => ['saved', 'updated', 'deleted', 'restored', 'forceDeleted']];

            foreach ($this->watches($spec) as $class => $watches) {
                foreach ($watches as $watch) {
                    $map[$class][] = $watch;
                }
            }
        }

        return $this->map = $map;
    }

    /**
     * What edits of the models along each with() path mark; relations the layer cannot follow are left to touch().
     *
     * @return array<string, list<Watch>>
     */
    private function watches(CatalogSpec $spec): array
    {
        $watches = [];
        $seen = [];

        foreach (array_keys($spec->relations) as $path) {
            $current = $spec->model;
            $walked = [];

            foreach (explode('.', $path) as $name) {
                $parentPath = implode('.', $walked);
                $walked[] = $name;
                $here = implode('.', $walked);
                $relation = self::relation($current, $name);

                if ($relation === null) {
                    break;
                }

                $related = $relation->getRelated();

                if (! isset($seen[$here])) {
                    $seen[$here] = true;

                    foreach (self::watchesOf($spec->name, $relation, $here, $parentPath) as $class => $watch) {
                        $watches[$class][] = $watch;
                    }
                }

                $current = $related;
            }
        }

        return $watches;
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     * @return array<string, Watch>
     */
    private static function watchesOf(string $catalog, Relation $relation, string $path, string $parentPath): array
    {
        $related = $relation->getRelated()::class;

        if ($relation instanceof MorphTo || $relation instanceof MorphOneOrMany) {
            return [];
        }

        if ($relation instanceof BelongsTo) {
            return [$related => ['catalog' => $catalog, 'kind' => 'owner', 'path' => $path, 'parentPath' => $parentPath, 'column' => '', 'parentColumn' => '', 'events' => ['updated', 'deleting']]];
        }

        if ($relation instanceof HasOneOrMany) {
            return [$related => ['catalog' => $catalog, 'kind' => 'child', 'path' => $path, 'parentPath' => $parentPath, 'column' => $relation->getForeignKeyName(), 'parentColumn' => $relation->getLocalKeyName(), 'events' => ['created', 'updated', 'deleted']]];
        }

        if ($relation instanceof BelongsToMany) {
            $watches = [$related => ['catalog' => $catalog, 'kind' => 'owner', 'path' => $path, 'parentPath' => $parentPath, 'column' => '', 'parentColumn' => '', 'events' => ['updated', 'deleting']]];
            $pivot = $relation->getPivotClass();

            // Laravel reports attach and detach only through a pivot class of the application.
            if ($pivot !== Pivot::class) {
                $watches[$pivot] = ['catalog' => $catalog, 'kind' => 'pivot', 'path' => $path, 'parentPath' => $parentPath, 'column' => $relation->getForeignPivotKeyName(), 'parentColumn' => $relation->getParentKeyName(), 'events' => ['created', 'updated', 'deleted']];
            }

            return $watches;
        }

        return [];
    }

    /**
     * @return Relation<Model, Model, mixed>|null
     */
    private static function relation(Model $model, string $name): ?Relation
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        try {
            $relation = $model->{$name}();
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }
}
