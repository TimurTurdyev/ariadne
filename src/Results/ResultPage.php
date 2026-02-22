<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Results;

/**
 * A results page with what pagination needs.
 */
final readonly class ResultPage
{
    /**
     * @param  list<Hit>  $hits
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $perPage,
        /** Distinct owners behind the rows; null when not counted. */
        public ?int $owners = null,
    ) {}

    public static function empty(int $perPage): self
    {
        return new self([], 0, 1, $perPage);
    }

    public function pages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
