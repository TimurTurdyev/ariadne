<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

/**
 * One order of the listing, declared once: the address, the SQL and the label all come from here.
 *
 * Rows where a sort column is NULL go last in either direction, on every database; nullsFirst() turns that over.
 */
final readonly class Sort
{
    public const RELEVANCE = 'relevance';

    /**
     * @param  list<array{0: string, 1: 'asc'|'desc'}>|null  $columns  null when the order is raw SQL
     */
    private function __construct(
        public string $slug,
        public string $label,
        public string $orderBy,
        public ?array $columns,
        public bool $nullsFirst = false,
        public bool $relevance = false,
    ) {}

    /**
     * @param  string  $orderBy  columns of the model like "sales_count desc, price"; anything else is raw SQL for the database driver only
     */
    public static function make(string $slug, string $label, string $orderBy): self
    {
        return new self($slug, $label, $orderBy, self::columns($orderBy));
    }

    /**
     * The order of an outside search: the default on screens narrowed with ranked(), hidden on the rest.
     */
    public static function relevance(string $label): self
    {
        return new self(self::RELEVANCE, $label, '', [], relevance: true);
    }

    public function nullsFirst(): self
    {
        return new self($this->slug, $this->label, $this->orderBy, $this->columns, true, $this->relevance);
    }

    public function isRaw(): bool
    {
        return $this->columns === null;
    }

    /**
     * @return list<array{0: string, 1: 'asc'|'desc'}>|null
     */
    private static function columns(string $orderBy): ?array
    {
        $columns = [];

        foreach (explode(',', $orderBy) as $part) {
            if (preg_match('/^\s*([a-z_][a-z0-9_]*)(?:\s+(asc|desc))?\s*$/i', $part, $match) !== 1) {
                return null;
            }

            $columns[] = [$match[1], strtolower($match[2] ?? '') === 'desc' ? 'desc' : 'asc'];
        }

        return $columns;
    }
}
