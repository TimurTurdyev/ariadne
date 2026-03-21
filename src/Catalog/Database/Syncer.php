<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;
use TimurTurdyev\Ariadne\Catalog\Events\CatalogSynced;

/**
 * Applies the marks of edited documents to the live generation; a failure leaves them for the next pass.
 *
 * @internal
 */
final readonly class Syncer
{
    /** A backlog this large that stopped growing, or this old, is cheaper to rebuild than to drain. */
    public const CATCH_UP_MARKS = 10000;

    public const CATCH_UP_QUIET = 120;

    public const CATCH_UP_OLDEST = 1800;

    public function __construct(
        private CatalogSpec $spec,
        private Tables $tables,
        private Locks $locks,
        private Journal $journal,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  list<int>  $own  documents of this request: the only ones applied while a rebuild holds the catalog
     * @param  int  $wait  seconds to wait for the drain lock; 0 leaves the marks to whoever holds it
     * @return int documents applied
     */
    public function drain(array $own = [], int $wait = 0): int
    {
        $lock = $this->locks->drain($this->spec->name);

        if (! $this->acquire($lock, $wait)) {
            return 0;
        }

        try {
            return $this->drainLocked($own);
        } catch (Throwable $failure) {
            $this->log->warning("Sync of catalog [{$this->spec->name}] failed, its marks wait for the next pass", ['error' => $failure->getMessage()]);

            return 0;
        } finally {
            $lock->release();
        }
    }

    /**
     * Asks the next sync for a rebuild instead of thousands of marks.
     */
    public function requestRebuild(): void
    {
        $this->tables->ensureState($this->spec->name);
        $this->tables->table($this->tables->catalogs())
            ->where('catalog', $this->spec->name)
            ->whereNull('rebuild_requested_at')
            ->update(['rebuild_requested_at' => Carbon::now()->getTimestamp()]);
    }

    /**
     * One pass of ariadne:sync: a stale flag, the previous generation after its grace, a requested rebuild or the marks.
     *
     * @param  Closure(): RebuildOutcome  $rebuild
     * @return list<string> what was done, for the command output
     */
    public function sync(Closure $rebuild): array
    {
        $done = [];
        $now = Carbon::now()->getTimestamp();
        $state = $this->tables->state($this->spec->name, write: true);

        if ($state->building !== null && ! $state->isBuilding($now)) {
            $this->tables->table($this->tables->catalogs())
                ->where('catalog', $this->spec->name)
                ->where('building_generation', $state->building)
                ->where('building_until', '<=', $now)
                ->update(['building_generation' => null, 'building_until' => null]);
            $done[] = "dropped the flag of generation {$state->building}, which ran out";
            $state = $this->tables->state($this->spec->name, write: true);
        }

        if ($state->previous !== null && $state->flippedAt !== null && $now - $state->flippedAt >= Rebuilder::GRACE_SECONDS) {
            if ($this->tables->drop($this->tables->rows($this->spec->name, $state->previous))) {
                $this->tables->table($this->tables->catalogs())
                    ->where('catalog', $this->spec->name)
                    ->where('previous_generation', $state->previous)
                    ->update(['previous_generation' => null]);
                $done[] = "dropped generation {$state->previous}";
            }
        }

        if ($state->isBuilding($now)) {
            return [...$done, 'a rebuild is running: marks wait for its replay'];
        }

        if ($state->rebuildRequestedAt !== null) {
            $outcome = $rebuild();

            if ($outcome->published()) {
                $this->tables->table($this->tables->catalogs())
                    ->where('catalog', $this->spec->name)
                    ->where('rebuild_requested_at', '<=', $state->rebuildRequestedAt)
                    ->update(['rebuild_requested_at' => null]);
            }

            return [...$done, 'requested rebuild: '.($outcome->published() ? "generation {$outcome->report->generation}" : (string) $outcome->message)];
        }

        $applied = $this->drain();

        return $applied > 0 ? [...$done, "applied {$applied} documents"] : $done;
    }

    /**
     * @param  list<int>  $own
     */
    private function drainLocked(array $own): int
    {
        $state = $this->tables->state($this->spec->name, write: true);

        if ($state->generation === 0) {
            return 0;
        }

        $now = Carbon::now()->getTimestamp();
        $projector = new Projector($this->spec, $this->tables, new RebuildReport, rebuilding: false);
        $projector->prepare();
        $applier = new Applier($this->spec, $this->tables);

        // The rebuild replays every mark before its flip; here only this request's documents, so its own edit shows.
        if ($state->isBuilding($now)) {
            if ($own === []) {
                return 0;
            }

            $projector->flush($applier->apply($projector, $state->generation, $own), $state->generation);
            $this->bumpVersion();
            $this->events->dispatch(new CatalogSynced($this->spec->name, $own));

            return count($own);
        }

        $stats = $this->journal->stats($this->spec->name);

        if ($stats['count'] > self::CATCH_UP_MARKS && (
            ($stats['newest'] !== null && $now - $stats['newest'] > self::CATCH_UP_QUIET)
            || ($stats['oldest'] !== null && $now - $stats['oldest'] > self::CATCH_UP_OLDEST)
        )) {
            $this->requestRebuild();

            return 0;
        }

        $applied = [];

        // Documents of this request go in even when another process took their marks already.
        if ($own !== []) {
            $projector->flush($applier->apply($projector, $state->generation, $own), $state->generation);
            $this->bumpVersion();
            array_push($applied, ...$own);
        }

        while (($pending = $this->journal->pending($this->spec->name)) !== []) {
            $documents = array_values(array_unique(array_column($pending, 'document')));
            $projector->flush($applier->apply($projector, $state->generation, $documents), $state->generation);
            $this->journal->forget(array_column($pending, 'id'));
            $this->bumpVersion();
            array_push($applied, ...$documents);
        }

        $applied = array_values(array_unique($applied));

        if ($applied !== []) {
            $this->events->dispatch(new CatalogSynced($this->spec->name, $applied));
        }

        return count($applied);
    }

    private function bumpVersion(): void
    {
        $this->tables->table($this->tables->catalogs())
            ->where('catalog', $this->spec->name)
            ->update(['version' => $this->tables->db->raw('version + 1')]);
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
}
