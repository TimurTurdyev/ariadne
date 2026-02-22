<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Facets;

use Collator;

/**
 * Orders and trims the values of a panel group; selected values survive the limit, so they can be removed.
 */
final class ValueOrderPolicy
{
    /**
     * @param  list<FacetValueCount>  $values
     * @return list<FacetValueCount>
     */
    public function apply(array $values, ValueOrder $order = ValueOrder::Natural, ?int $limit = null): array
    {
        $sorted = $this->sort($values, $order);

        return $limit === null ? $sorted : $this->limit($sorted, $limit);
    }

    /**
     * @param  list<FacetValueCount>  $values
     * @return list<FacetValueCount>
     */
    private function sort(array $values, ValueOrder $order): array
    {
        if ($order === ValueOrder::Dictionary) {
            usort($values, static fn (FacetValueCount $a, FacetValueCount $b): int => $a->term->sortOrder <=> $b->term->sortOrder);

            return $values;
        }

        if ($order === ValueOrder::CountDesc) {
            usort($values, static fn (FacetValueCount $a, FacetValueCount $b): int => $b->count <=> $a->count ?: $a->term->sortOrder <=> $b->term->sortOrder);

            return $values;
        }

        $collator = $this->collator($order);

        usort($values, static function (FacetValueCount $a, FacetValueCount $b) use ($collator): int {
            if ($collator !== null) {
                return $collator->compare($a->term->label, $b->term->label) ?: 0;
            }

            return strnatcasecmp($a->term->label, $b->term->label);
        });

        return $values;
    }

    private function collator(ValueOrder $order): ?Collator
    {
        // Without intl the caller compares with strnatcasecmp, which sorts Cyrillic by code points.
        if (! class_exists(Collator::class)) {
            return null;
        }

        $collator = new Collator('ru_RU');

        if ($order === ValueOrder::Natural) {
            $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        }

        return $collator;
    }

    /**
     * @param  list<FacetValueCount>  $values
     * @return list<FacetValueCount>
     */
    private function limit(array $values, int $limit): array
    {
        // Zero means "no list", not "the whole list"; selected values still stay.
        if ($limit <= 0) {
            return array_values(array_filter(
                $values,
                static fn (FacetValueCount $value): bool => $value->selected,
            ));
        }

        if (count($values) <= $limit) {
            return $values;
        }

        $head = array_slice($values, 0, $limit);
        $tail = array_slice($values, $limit);

        foreach ($tail as $value) {
            if ($value->selected) {
                $head[] = $value;
            }
        }

        return $head;
    }
}
