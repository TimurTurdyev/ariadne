<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support;

use LogicException;
use TimurTurdyev\Ariadne\Index\CatalogIndex;
use TimurTurdyev\Ariadne\Index\CatalogIndexUnavailable;
use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Results\IndexResponse;

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
