<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

/**
 * Requested results page; the page size comes from the application, never from the client.
 */
final readonly class PageRequest
{
    public const DEFAULT_PER_PAGE = 30;

    /**
     * Nobody pages that deep, while ?page=99999999 would cost the engine a huge offset.
     */
    public const MAX_PAGE = 500;

    public int $page;

    public int $perPage;

    public function __construct(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE)
    {
        $this->page = min(max(1, $page), self::MAX_PAGE);
        $this->perPage = $perPage > 0 ? $perPage : self::DEFAULT_PER_PAGE;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function isFirst(): bool
    {
        return $this->page === 1;
    }
}
