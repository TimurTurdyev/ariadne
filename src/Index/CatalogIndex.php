<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Index;

use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Results\IndexResponse;

/**
 * The search index as the domain sees it: runs a plan of intents and returns the raw answer.
 */
interface CatalogIndex
{
    /**
     * @throws CatalogIndexUnavailable when the engine cannot be reached; a query error surfaces as is
     */
    public function execute(QueryPlan $plan): IndexResponse;
}
