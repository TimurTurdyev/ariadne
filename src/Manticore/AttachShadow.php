<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use LogicException;
use Throwable;

/**
 * A constant table name and a shadow attached to it; atomic for readers only on success and within one table.
 */
final class AttachShadow implements Publication
{
    private readonly PublishGate $gate;

    public function __construct(
        private readonly Connection $engine,
        private readonly LockProvider $locks,
        private readonly string $shadow = '_build',
        private readonly string $lockPrefix = 'simple-search.rebuild.',
        private readonly int $lockSeconds = 3600,
        private readonly int $waitSeconds = 0,
    ) {
        $this->gate = new PublishGate($engine, $lockSeconds);
    }

    public function live(string $name): string
    {
        return $name;
    }

    public function rebuild(string $name, array $tables, Closure $fill): Published
    {
        $lock = $this->locks->lock($this->lockPrefix.$name, $this->lockSeconds);
        $lock->block($this->waitSeconds);
        $since = hrtime(true);

        try {
            return $this->publish($name, $tables, $fill, $since);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  non-empty-list<Table>  $tables
     * @param  Closure(string $target): int  $fill
     */
    private function publish(string $name, array $tables, Closure $fill, int|float $since): Published
    {
        $target = $name.$this->shadow;

        // An empty shadow suffix, or one that a companion suffix repeats.
        if (array_intersect($this->names($tables, $name), $this->names($tables, $target)) !== []) {
            throw new LogicException("The shadow of {$name} would be built over a live table: pick another shadow suffix.");
        }

        foreach ($tables as $table) {
            $this->engine->execute('DROP TABLE IF EXISTS '.$table->name($target));
            $this->engine->execute($table->create($target));
        }

        $reported = $fill($target);
        $built = $this->gate->check($tables, $target, $name, $reported);

        // Without the flush ATTACH holds readers for longer; all go first to keep the set split short.
        foreach ($tables as $table) {
            $this->engine->execute('FLUSH RAMCHUNK '.$table->name($target));
        }

        $this->gate->ensureLockHeld($since, $tables[0]->name($target), $tables[0]->name($name), $built);

        foreach ($tables as $index => $table) {
            $this->attach($table, $target, $name, $index > 0);
        }

        return new Published($name, $built);
    }

    /**
     * @throws PublishFailed
     */
    private function attach(Table $table, string $target, string $name, bool $partly): void
    {
        $shadow = $table->name($target);
        $live = $table->name($name);
        $switched = false;

        try {
            $this->engine->execute($table->create($name, ifNotExists: true));

            // A truncated target takes the columns and text settings of the source.
            $this->engine->execute("ATTACH TABLE {$shadow} TO TABLE {$live} WITH TRUNCATE");
            $switched = true;
            $this->engine->execute('DROP TABLE IF EXISTS '.$shadow);

            // ATTACH leaves the target's own mutable settings, so ALTER only what differs.
            if ($table->alterMutable($name) !== []) {
                foreach ($table->alterMutable($name, $this->created($live)) as $statement) {
                    $this->engine->execute($statement);
                }
            }
        } catch (Throwable $e) {
            throw new PublishFailed($live, $switched, $e, $partly);
        }
    }

    private function created(string $table): string
    {
        $created = $this->engine->select('SHOW CREATE TABLE '.$table)[0]['Create Table'] ?? '';

        return is_string($created) ? $created : '';
    }

    /**
     * @param  non-empty-list<Table>  $tables
     * @return list<string>
     */
    private function names(array $tables, string $base): array
    {
        return array_map(static fn (Table $table): string => $table->name($base), $tables);
    }
}
