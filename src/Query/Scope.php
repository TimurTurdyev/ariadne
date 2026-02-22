<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

/**
 * The universe of a query: restrictions that always apply and never take part in the disjunctive exclusion.
 */
final readonly class Scope
{
    /** @var list<Constraint> */
    public array $constraints;

    /**
     * @param  string  $kind  label of the screen kind
     * @param  list<Constraint>  $constraints  one per field, the last one wins
     * @param  string|null  $groupBy  one card per value of this key; the adapter decides which row leads
     */
    public function __construct(
        public string $kind = 'all',
        array $constraints = [],
        public ?string $groupBy = null,
    ) {
        $byField = [];

        foreach ($constraints as $constraint) {
            $byField[$constraint->field] = $constraint;
        }

        ksort($byField, SORT_STRING);

        $this->constraints = array_values($byField);
    }

    /**
     * The same scope with the constraint added; it replaces one on the same field.
     */
    public function with(Constraint $constraint): self
    {
        return new self($this->kind, [...$this->constraints, $constraint], $this->groupBy);
    }

    public function groupedBy(?string $key): self
    {
        return new self($this->kind, $this->constraints, $key);
    }

    public function constraint(string $field): ?Constraint
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint->field === $field) {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * Cache signature; the grouping key is in it, so a grouped screen and a plain one do not share an entry.
     */
    public function signature(): string
    {
        return implode('|', [
            $this->kind,
            implode(';', array_map(
                static fn (Constraint $constraint): string => $constraint->signature(),
                $this->constraints,
            )),
            (string) $this->groupBy,
        ]);
    }
}
