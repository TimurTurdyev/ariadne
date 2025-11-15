<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Two copies of every table, <name>_a and <name>_b, and a stored pointer to the live one.
 */
final class SwapCopies implements Publication
{
    private readonly PublishGate $gate;

    /**
     * @param  Closure(string $name): ?string  $read  stored live table name, null when nothing is published yet
     * @param  Closure(string $name, string $table): void  $write
     */
    public function __construct(
        private readonly Connection $engine,
        private readonly LockProvider $locks,
        private readonly Closure $read,
        private readonly Closure $write,
        private readonly string $lockPrefix = 'simple-search.rebuild.',
        private readonly int $lockSeconds = 600,
        private readonly int $waitSeconds = 300,
    ) {
        $this->gate = new PublishGate($engine, $lockSeconds);
    }

    public function live(string $name): string
    {
        return ($this->read)($name) ?? $name.'_a';
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
        $current = $this->live($name);
        $next = $current === $name.'_a' ? $name.'_b' : $name.'_a';

        foreach ($tables as $table) {
            $this->engine->execute('DROP TABLE IF EXISTS '.$table->name($next));
            $this->engine->execute($table->create($next));
        }

        $reported = $fill($next);
        $built = $this->gate->check($tables, $next, $current, $reported);
        $this->gate->ensureLockHeld($since, $tables[0]->name($next), $tables[0]->name($current), $built);

        // The previous copy stays: a query started before the switch finishes on it.
        ($this->write)($name, $next);

        return new Published($next, $built);
    }
}
