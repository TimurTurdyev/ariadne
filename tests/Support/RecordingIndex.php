<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Tests\Support;

use LogicException;
use TimurTurdyev\SimpleSearch\Index\CatalogIndex;
use TimurTurdyev\SimpleSearch\Index\CatalogIndexUnavailable;
use TimurTurdyev\SimpleSearch\Query\QueryPlan;
use TimurTurdyev\SimpleSearch\Results\IndexResponse;

/**
 * Index double: answers with a canned response and remembers how it was called.
 */
final class RecordingIndex implements CatalogIndex
{
    public int $calls = 0;

    /** Set to make the engine unreachable. */
    public bool $fail = false;

    private ?QueryPlan $lastPlan = null;

    public function __construct(
        public IndexResponse $response = new IndexResponse([], 0),
    ) {}

    public function execute(QueryPlan $plan): IndexResponse
    {
        $this->calls++;
        $this->lastPlan = $plan;

        if ($this->fail) {
            throw new CatalogIndexUnavailable('engine down');
        }

        return $this->response;
    }

    public function lastPlan(): QueryPlan
    {
        return $this->lastPlan ?? throw new LogicException('The index has not been called yet.');
    }
}
