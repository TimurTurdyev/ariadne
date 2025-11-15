<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

use RuntimeException;
use Throwable;

/**
 * The switch to the rebuilt data failed halfway and is not rolled back.
 */
final class PublishFailed extends RuntimeException
{
    /**
     * @param  string  $table  the live table whose switch failed
     * @param  bool  $switched  its ATTACH went through, so it already serves the rebuilt data
     * @param  bool  $partly  tables of the set before it were switched
     */
    public function __construct(
        public readonly string $table,
        public readonly bool $switched,
        Throwable $previous,
        bool $partly = false,
    ) {
        $state = $switched
            ? "{$table} serves the rebuilt data, but finishing its switch failed"
            : "Switching {$table} to the rebuilt data failed: it keeps the previous data, "
                .'or is left empty if the attach broke off halfway';

        $set = $partly
            ? ' The tables of the set switched before it serve the rebuilt data; the next rebuild brings the set together.'
            : '';

        parent::__construct("{$state}: {$previous->getMessage()}{$set}", previous: $previous);
    }
}
