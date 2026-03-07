<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One filter of the panel: its slug, its title and the values a document carries, declared together.
 *
 * Facet::dynamic() turns one producer into many groups, each term names its own;
 * Facet::named() describes such a group in describedBy() and has no producer.
 */
final readonly class Facet
{
    public const LIST = 'list';

    public const SWATCH = 'swatch';

    public const TOGGLE = 'toggle';

    public const DECLARED = 'declared';

    public const DYNAMIC = 'dynamic';

    public const NAMED = 'named';

    /** The one value of a toggle in the address: ?sale=1. */
    public const TOGGLE_VALUE = '1';

    /**
     * @param  'declared'|'dynamic'|'named'  $kind
     * @param  (Closure(Model): mixed)|null  $values  null for a named group
     * @param  array<string, (Closure(mixed): mixed)|null>  $relations  relations the producer reads, with their constraints
     * @param  (Closure(): iterable<mixed>)|null  $described  groups of a dynamic facet as the application describes them
     * @param  int|null  $position  place of a named group among the groups of its dynamic facet; null orders it by coverage
     */
    private function __construct(
        public string $kind,
        public string $slug,
        public string $title,
        public ?Closure $values,
        public string $widget = self::LIST,
        public bool $multiple = true,
        public array $relations = [],
        public ?Closure $described = null,
        public ?int $position = null,
    ) {}

    /**
     * @template T of Model
     *
     * @param  Closure(T): iterable<mixed>  $values  value => label pairs or Term objects of one document; [] when it has none
     */
    public static function make(string $slug, string $title, Closure $values): self
    {
        /** @var Closure(Model): mixed $values */
        return new self(self::DECLARED, $slug, $title, $values);
    }

    /**
     * A single checkbox: documents the predicate holds for carry the value 1.
     *
     * @template T of Model
     *
     * @param  Closure(T): bool  $when
     */
    public static function toggle(string $slug, string $label, Closure $when): self
    {
        /** @var Closure(Model): mixed $when */
        return new self(self::DECLARED, $slug, $label, $when, self::TOGGLE, false);
    }

    /**
     * Many panel groups from one producer: every term names its group with Term::in().
     *
     * @template T of Model
     *
     * @param  string  $name  name of the producer; it is not a slug of the address
     * @param  Closure(T): iterable<mixed>  $values  Term objects of one document
     */
    public static function dynamic(string $name, Closure $values): self
    {
        /** @var Closure(Model): mixed $values */
        return new self(self::DYNAMIC, $name, '', $values);
    }

    /**
     * One group of a dynamic facet as describedBy() returns it.
     */
    public static function named(string $slug, string $title): self
    {
        return new self(self::NAMED, $slug, $title, null);
    }

    /**
     * Relations the producer reads, as for Eloquent with(); rebuilds load them and edits of them mark documents.
     *
     * @param  string|array<int|string, string|(Closure(mixed): mixed)>  ...$relations
     */
    public function with(string|array ...$relations): self
    {
        $merged = $this->relations;

        foreach ($relations as $relation) {
            foreach (is_array($relation) ? $relation : [$relation] as $name => $constraint) {
                if (is_int($name) && is_string($constraint)) {
                    $merged[$constraint] ??= null;
                } elseif (is_string($name) && $constraint instanceof Closure) {
                    $merged[$name] = $constraint;
                }
            }
        }

        return $this->copy(relations: $merged);
    }

    /**
     * A document carries at most one value: the panel offers no "all of" switch.
     */
    public function single(): self
    {
        return $this->copy(multiple: false);
    }

    public function swatches(): self
    {
        return $this->copy(widget: self::SWATCH);
    }

    /**
     * The place of a named group among the groups of its dynamic facet.
     */
    public function at(?int $position): self
    {
        return $this->copy(position: $position, positioned: true);
    }

    /**
     * Titles, order and widgets of the groups of a dynamic facet; read at a rebuild only.
     *
     * @param  Closure(): iterable<mixed>  $groups  Facet::named() for each group
     */
    public function describedBy(Closure $groups): self
    {
        return $this->copy(described: $groups);
    }

    public function isDeclared(): bool
    {
        return $this->kind === self::DECLARED;
    }

    public function isDynamic(): bool
    {
        return $this->kind === self::DYNAMIC;
    }

    public function isNamed(): bool
    {
        return $this->kind === self::NAMED;
    }

    public function isToggle(): bool
    {
        return $this->widget === self::TOGGLE;
    }

    /**
     * The terms of one document as the producer gives them; anything that is not a term or a pair is skipped.
     *
     * @return list<Term>
     */
    public function termsOf(Model $document): array
    {
        if ($this->values === null) {
            return [];
        }

        $produced = ($this->values)($document);

        if ($this->isToggle()) {
            return $produced === true ? [Term::of(self::TOGGLE_VALUE, $this->title)] : [];
        }

        $terms = [];

        foreach (is_iterable($produced) ? $produced : [] as $value => $label) {
            if ($label instanceof Term) {
                $terms[] = $label;
            } elseif ($this->isDeclared() && (is_string($label) || is_int($label)) && (is_string($value) || is_int($value))) {
                $terms[] = Term::of($value, (string) $label);
            }
        }

        return $terms;
    }

    /**
     * @param  array<string, (Closure(mixed): mixed)|null>|null  $relations
     * @param  (Closure(): iterable<mixed>)|null  $described
     */
    private function copy(
        ?string $widget = null,
        ?bool $multiple = null,
        ?array $relations = null,
        ?Closure $described = null,
        ?int $position = null,
        bool $positioned = false,
    ): self {
        return new self(
            $this->kind,
            $this->slug,
            $this->title,
            $this->values,
            $widget ?? $this->widget,
            $multiple ?? $this->multiple,
            $relations ?? $this->relations,
            $described ?? $this->described,
            $positioned ? $position : $this->position,
        );
    }
}
