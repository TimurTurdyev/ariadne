<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

use RuntimeException;

/**
 * The rebuilt table did not pass the gate, so nothing was switched: readers keep the previous data.
 */
final class PublishRefused extends RuntimeException
{
    /**
     * @param  string  $table  the rebuilt table that was checked
     * @param  int  $reported  documents the fill said it wrote to the main table
     * @param  int  $built  documents the rebuilt table holds
     * @param  int  $live  documents the live table holds, 0 when there is none
     * @param  string|null  $reason  why, when the counts alone do not say it
     */
    public function __construct(
        public readonly string $table,
        public readonly int $reported,
        public readonly int $built,
        public readonly int $live,
        ?string $reason = null,
    ) {
        parent::__construct(
            "Refused to publish {$table}: ".($reason ?? "the fill reported {$reported} documents, "
                ."the table holds {$built}, the live table holds {$live}").'.',
        );
    }
}
