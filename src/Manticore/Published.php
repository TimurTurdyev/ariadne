<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Manticore;

/**
 * The table set readers were switched to and the document count of its main table.
 */
final readonly class Published
{
    public function __construct(
        public string $table,
        public int $documents,
    ) {}
}
