<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Manticore;

use Closure;

/**
 * Rebuilds a set of tables aside and switches readers to it without downtime.
 */
interface Publication
{
    /**
     * Name of the table set readers should query now.
     */
    public function live(string $name): string;

    /**
     * @param  non-empty-list<Table>  $tables  the main table first, then its companions
     * @param  Closure(string $target): int  $fill  fills $target (companions at Table::name($target)), returns the document count of the main table
     *
     * @throws PublishRefused the gate failed, readers keep the previous data
     * @throws PublishFailed the switch itself failed
     */
    public function rebuild(string $name, array $tables, Closure $fill): Published;
}
