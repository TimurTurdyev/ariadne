<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use LogicException;

/**
 * The two locks of a catalog: the rebuild lock and the drain lock, from one cache store shared by every server.
 *
 * @internal
 */
final readonly class Locks
{
    /** A drain is a few short transactions; a dead one frees the lock after this. */
    public const DRAIN_SECONDS = 120;

    public function __construct(
        private Factory $caches,
        private ?string $store,
        public int $rebuildSeconds,
    ) {}

    public function rebuild(string $catalog): Lock
    {
        return $this->provider()->lock('ariadne:rebuild:'.$catalog, $this->rebuildSeconds);
    }

    /**
     * @param  int  $seconds  how long the holder may keep it: a rebuild replaying marks needs its own deadline
     */
    public function drain(string $catalog, int $seconds = self::DRAIN_SECONDS): Lock
    {
        return $this->provider()->lock('ariadne:drain:'.$catalog, $seconds);
    }

    public function supportsLocks(): bool
    {
        return $this->caches->store($this->store)->getStore() instanceof LockProvider;
    }

    public function storeName(): ?string
    {
        return $this->store;
    }

    private function provider(): LockProvider
    {
        $store = $this->caches->store($this->store)->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Cache store '.($this->store ?? 'default').' has no locks: set ariadne.sync.lock_store to one that has, such as redis or database.');
        }

        return $store;
    }
}
