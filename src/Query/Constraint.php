<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

/**
 * A scope restriction on one field; an empty list matches nothing.
 */
final readonly class Constraint
{
    /**
     * @param  list<int|string>  $values
     */
    private function __construct(
        public string $field,
        public array $values,
        public bool $ranked,
    ) {}

    /**
     * Any of the values, stored unique and ascending.
     *
     * @param  list<int|string>  $values
     */
    public static function in(string $field, array $values): self
    {
        $values = array_values(array_unique($values));

        // One string in the list and everything compares as strings: the order stays total.
        sort($values, array_filter($values, is_string(...)) === [] ? SORT_NUMERIC : SORT_STRING);

        return new self($field, $values, false);
    }

    /**
     * Any of the ids, kept in the given order: the ranking of an outside search.
     *
     * @param  list<int>  $ids
     */
    public static function ranked(string $field, array $ids): self
    {
        return new self($field, $ids, true);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function signature(): string
    {
        // Encoded, so a comma inside a value cannot pass for two values.
        $values = array_map(static fn (int|string $value): string => rawurlencode((string) $value), $this->values);

        return $this->field.($this->ranked ? ':ranked:' : ':in:').implode(',', $values);
    }
}
