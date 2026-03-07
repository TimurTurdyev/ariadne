<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

/**
 * How a rebuild ended; the code is the exit code of ariadne:rebuild.
 */
final readonly class RebuildOutcome
{
    public const PUBLISHED = 0;

    /** The gate refused the generation: readers stay on the previous one. */
    public const REFUSED = 1;

    public const FAILED = 2;

    /** Another rebuild of the catalog is running. */
    public const BUSY = 3;

    public function __construct(
        public int $code,
        public RebuildReport $report,
        public ?string $message = null,
    ) {}

    public function published(): bool
    {
        return $this->code === self::PUBLISHED;
    }
}
