<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;
use TimurTurdyev\Ariadne\Catalog\Events\CatalogRebuilt;

/**
 * Builds a new generation next to the live one and publishes it by moving the pointer.
 *
 * Marks left while the table filled are replayed into it under the drain lock right before the flip,
 * so no edit is lost and none blinks back.
 *
 * @internal
 */
final readonly class Rebuilder
{
    /** Readers that took the previous pointer finish within this; then its table goes. */
    public const GRACE_SECONDS = 600;

    private const CHUNK = 500;

    private const INSERT_BATCH = 1000;

    /** A sync holds the drain lock for a few short transactions; longer means something is stuck. */
    private const DRAIN_WAIT = 30;

    public function __construct(
        private CatalogSpec $spec,
        private Tables $tables,
        private Locks $locks,
        private Journal $journal,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  bool  $force  publish an empty generation over a non-empty one
     * @param  int  $wait  seconds to wait for another rebuild of the catalog; 0 gives up at once
     */
    public function rebuild(bool $force = false, int $wait = 0): RebuildOutcome
    {
        $report = new RebuildReport;
        $started = hrtime(true);
        $db = $this->tables->db;

        if ($db->transactionLevel() > 0 && in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return new RebuildOutcome(RebuildOutcome::FAILED, $report, 'A rebuild cannot run inside an open transaction on MySQL or MariaDB: its DDL would commit the transaction.');
        }

        $lock = $this->locks->rebuild($this->spec->name);

        if (! $this->acquire($lock, $wait)) {
            return new RebuildOutcome(RebuildOutcome::BUSY, $report, "Another rebuild of [{$this->spec->name}] is running.");
        }

        $generation = 0;
        $published = false;

        try {
            [$generation, $live, $liveRows] = $this->claim();

            if ($generation === 0) {
                return new RebuildOutcome(RebuildOutcome::BUSY, $report, "Another rebuild of [{$this->spec->name}] holds its flag.");
            }

            $report->generation = $generation;
            $this->tables->create($this->spec->name, $generation);
            $projector = new Projector($this->spec, $this->tables, $report, rebuilding: true);
            $projector->prepare();
            $this->fill($projector, $generation);
            $this->tables->index($this->spec->name, $generation);
            $warning = $this->tables->analyze($this->spec->name, $generation);

            if ($warning !== null) {
                $report->warnings[] = $warning;
            }

            $refusal = $this->gate($generation, $report, $liveRows, $force);

            if ($refusal !== null) {
                return new RebuildOutcome(RebuildOutcome::REFUSED, $report, $refusal);
            }

            $report->seconds = (hrtime(true) - $started) / 1e9;
            $this->flip($projector, $generation, $live, $report);
            $published = true;
            $this->prune($live);
            $report->seconds = (hrtime(true) - $started) / 1e9;
            $this->events->dispatch(new CatalogRebuilt($this->spec->name, $report));

            return new RebuildOutcome(RebuildOutcome::PUBLISHED, $report);
        } catch (Throwable $failure) {
            $this->log->error("Rebuild of catalog [{$this->spec->name}] failed", ['generation' => $generation, 'error' => $failure->getMessage()]);

            return new RebuildOutcome(RebuildOutcome::FAILED, $report, $failure->getMessage());
        } finally {
            if ($generation > 0 && ! $published) {
                $this->dropFlag($generation);
            }

            $lock->release();
        }
    }

    /**
     * Raises the building flag under the drain lock and drops what failed or refused rebuilds left.
     *
     * @return array{0: int, 1: int, 2: int} new generation (0 when another rebuild holds the flag), live generation, its rows
     */
    private function claim(): array
    {
        $this->tables->ensureState($this->spec->name);
        $drain = $this->locks->drain($this->spec->name);

        if (! $this->acquire($drain, self::DRAIN_WAIT)) {
            throw new RuntimeException("The drain lock of [{$this->spec->name}] is held for more than ".self::DRAIN_WAIT.' seconds.');
        }

        try {
            $now = Carbon::now()->getTimestamp();
            $state = $this->tables->state($this->spec->name, write: true);

            if ($state->isBuilding($now)) {
                return [0, $state->generation, $state->rows];
            }

            $generation = max([$state->generation, ...$this->tables->generations($this->spec->name)]) + 1;
            $this->tables->table($this->tables->catalogs())->where('catalog', $this->spec->name)->update([
                'building_generation' => $generation,
                'building_until' => $now + $this->locks->rebuildSeconds,
            ]);
        } finally {
            $drain->release();
        }

        foreach ($this->tables->generations($this->spec->name) as $old) {
            if ($old > $state->generation && $old !== $generation) {
                $this->tables->drop($this->tables->rows($this->spec->name, $old));
            }
        }

        return [$generation, $state->generation, $state->rows];
    }

    /**
     * Every document of the model, scopes applied, read from the write connection: a lagging replica
     * would give the state before an edit whose mark a sync removed before the flag went up.
     */
    private function fill(Projector $projector, int $generation): void
    {
        $table = $this->tables->rows($this->spec->name, $generation);
        $relations = (new Applier($this->spec, $this->tables))->relations();

        $this->readingFromWriter($this->tables->db, function () use ($projector, $table, $relations): void {
            $this->spec->model->newQuery()->with($relations)->chunkById(self::CHUNK, function (Collection $documents) use ($projector, $table): void {
                foreach (array_chunk($projector->rows($documents), self::INSERT_BATCH) as $batch) {
                    $this->tables->table($table)->insert($batch);
                }
            });
        });
    }

    private function gate(int $generation, RebuildReport $report, int $liveRows, bool $force): ?string
    {
        $counted = $this->tables->table($this->tables->rows($this->spec->name, $generation))
            ->selectRaw('COUNT(DISTINCT document_id) AS n')
            ->value('n');

        if (! is_numeric($counted) || (int) $counted !== $report->documentsWithRows) {
            return "Generation {$generation} holds ".(is_numeric($counted) ? (int) $counted : '?')." documents, the fill wrote {$report->documentsWithRows}: not published.";
        }

        if ($report->rows === 0 && $liveRows > 0 && ! $force) {
            return "Generation {$generation} is empty while the live one has {$liveRows} rows: not published; --force publishes it.";
        }

        return null;
    }

    /**
     * Replays every pending mark into the new generation, then moves the pointer in one short transaction.
     */
    private function flip(Projector $projector, int $generation, int $live, RebuildReport $report): void
    {
        $drain = $this->locks->drain($this->spec->name, $this->locks->rebuildSeconds);

        if (! $this->acquire($drain, $this->locks->rebuildSeconds)) {
            throw new RuntimeException("The drain lock of [{$this->spec->name}] was not free before the flip.");
        }

        try {
            $state = $this->tables->state($this->spec->name, write: true);

            if ($state->building !== $generation || ! $state->isBuilding(Carbon::now()->getTimestamp())) {
                throw new RuntimeException("The building flag of generation {$generation} ran out or was taken: rebuild.lock_seconds is shorter than the rebuild.");
            }

            $applier = new Applier($this->spec, $this->tables);
            $marks = [];
            $documents = [];
            $after = 0;
            $filled = [$report->documents, $report->documentsWithRows, $report->rows];

            while (($pending = $this->journal->pending($this->spec->name, $after)) !== []) {
                $batch = array_column($pending, 'document');
                $applier->apply($projector, $generation, $batch);
                array_push($marks, ...array_column($pending, 'id'));
                array_push($documents, ...$batch);
                $after = $pending[count($pending) - 1]['id'];
            }

            $report->replayed = count(array_unique($documents));
            // The replay counts documents the fill counted already: the totals come from the table instead.
            [$report->documents, $report->documentsWithRows, $report->rows] = $filled;

            if ($report->replayed > 0) {
                $this->recount($generation, $report);
            }
            $now = Carbon::now()->getTimestamp();

            $this->tables->db->transaction(function () use ($projector, $generation, $live, $report, $marks, $now): void {
                $projector->publish($generation);
                $this->journal->forget($marks);

                $moved = $this->tables->table($this->tables->catalogs())
                    ->where('catalog', $this->spec->name)
                    ->where('building_generation', $generation)
                    ->update([
                        'generation' => $generation,
                        'previous_generation' => $live > 0 ? $live : null,
                        'flipped_at' => $now,
                        'version' => $this->tables->db->raw('version + 1'),
                        'building_generation' => null,
                        'building_until' => null,
                        'document_total' => $report->documentsWithRows,
                        'row_total' => $report->rows,
                        'problems' => json_encode($report->problems(), JSON_UNESCAPED_UNICODE),
                        'built_at' => $now,
                        'build_seconds' => round($report->seconds, 3),
                    ]);

                if ($moved !== 1) {
                    throw new RuntimeException("The building flag of generation {$generation} was taken before the flip.");
                }
            });
        } finally {
            $drain->release();
        }
    }

    private function recount(int $generation, RebuildReport $report): void
    {
        $row = (array) $this->tables->table($this->tables->rows($this->spec->name, $generation))
            ->selectRaw('COUNT(DISTINCT document_id) AS documents, COUNT(*) AS total')
            ->first();
        $report->documentsWithRows = is_numeric($row['documents'] ?? null) ? (int) $row['documents'] : $report->documentsWithRows;
        $report->rows = is_numeric($row['total'] ?? null) ? (int) $row['total'] : $report->rows;
    }

    /**
     * Generations older than the previous one go at once; the previous one waits out the grace on a sync.
     */
    private function prune(int $previous): void
    {
        foreach ($this->tables->generations($this->spec->name) as $old) {
            if ($old < $previous) {
                $this->tables->drop($this->tables->rows($this->spec->name, $old));
            }
        }
    }

    private function dropFlag(int $generation): void
    {
        try {
            $this->tables->table($this->tables->catalogs())
                ->where('catalog', $this->spec->name)
                ->where('building_generation', $generation)
                ->update(['building_generation' => null, 'building_until' => null]);
        } catch (Throwable $failure) {
            $this->log->warning("The building flag of [{$this->spec->name}] stays until it runs out", ['error' => $failure->getMessage()]);
        }
    }

    private function acquire(Lock $lock, int $wait): bool
    {
        if ($wait <= 0) {
            return (bool) $lock->get();
        }

        try {
            return (bool) $lock->block($wait);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * @param  Closure(): void  $work
     */
    private function readingFromWriter(Connection $db, Closure $work): void
    {
        $db->useWriteConnectionWhenReading(true);

        try {
            $work();
        } finally {
            $db->useWriteConnectionWhenReading(false);
        }
    }
}
