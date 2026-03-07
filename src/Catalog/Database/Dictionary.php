<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Collator;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueId;

/**
 * The dictionary of one screen over one generation: groups at once, values only of the groups it needs.
 *
 * Values come from the values table and only those with rows in the generation, so a value nobody carries
 * is unknown to the address. Their order is baked here and is the same on every database.
 *
 * @internal
 */
final class Dictionary implements FacetDictionary
{
    /** @var array<string, GroupInfo>|null slug => group, declared facets in their order, dynamic groups in the slot of their facet */
    private ?array $groups = null;

    /** @var array<int, string> group id => slug */
    private array $slugs = [];

    /** @var array<string, true> */
    private array $loaded = [];

    /** @var array<string, list<FacetTerm>> */
    private array $terms = [];

    /** @var array<int, FacetTerm> */
    private array $byTerm = [];

    /** @var list<string>|null the panel order the early rules settled; null - the base order */
    private ?array $order = null;

    public function __construct(
        private readonly CatalogSpec $spec,
        private readonly Tables $tables,
        public readonly int $generation,
    ) {}

    public function facets(): array
    {
        return array_map(FacetId::fromString(...), $this->order ?? array_map(strval(...), array_keys($this->groups())));
    }

    public function termsFor(FacetId $facet): array
    {
        $this->preload([$facet->value]);

        return $this->terms[$facet->value] ?? [];
    }

    public function resolve(int $termId): ?FacetTerm
    {
        return $this->byTerm[$termId] ?? null;
    }

    public function termIdsFor(FacetId $facet, array $values): array
    {
        $wanted = [];

        foreach ($values as $value) {
            $wanted[$value->value] = true;
        }

        $ids = [];

        foreach ($this->termsFor($facet) as $term) {
            if (isset($wanted[$term->value->value])) {
                $ids[] = $term->id;
            }
        }

        return $ids;
    }

    public function labelFor(FacetId $facet): ?string
    {
        return $this->groups()[$facet->value]->title ?? null;
    }

    public function isMultiValued(FacetId $facet): bool
    {
        return $this->groups()[$facet->value]->multiple ?? true;
    }

    /**
     * @return array<string, GroupInfo>
     */
    public function groups(): array
    {
        return $this->groups ??= $this->readGroups();
    }

    public function group(string $slug): ?GroupInfo
    {
        return $this->groups()[$slug] ?? null;
    }

    public function groupById(int $id): ?GroupInfo
    {
        $this->groups();
        $slug = $this->slugs[$id] ?? null;

        return $slug === null ? null : ($this->groups[$slug] ?? null);
    }

    /**
     * The groups the panel may show, in the order it shows them; the policy walks facets() in this order.
     *
     * @param  list<string>  $slugs
     */
    public function arrange(array $slugs): void
    {
        $this->order = $slugs;
    }

    /**
     * Values of the groups the address names, in one read: the codec then asks for them one by one.
     *
     * @param  list<int|string>  $params  parameter names of the address
     */
    public function preloadNamed(array $params): void
    {
        $slugs = [];

        foreach ($params as $param) {
            $facet = $this->spec->format->keys()->facet((string) $param);

            if ($facet !== null && isset($this->groups()[$facet->value])) {
                $slugs[] = $facet->value;
            }
        }

        $this->preload($slugs);
    }

    /**
     * Reads whole groups, so the order of their values does not depend on which part of the panel asked first.
     *
     * @param  list<string>  $slugs
     */
    public function preload(array $slugs): void
    {
        $wanted = [];

        foreach ($slugs as $slug) {
            $group = $this->groups()[$slug] ?? null;

            if ($group !== null && ! isset($this->loaded[$slug])) {
                $wanted[$group->id] = $slug;
                $this->loaded[$slug] = true;
            }
        }

        if ($wanted === [] || $this->generation === 0) {
            return;
        }

        $rows = $this->tables->rows($this->spec->name, $this->generation);
        $found = $this->tables->table($this->tables->values().' as v')
            ->whereIn('v.group_id', array_keys($wanted))
            ->whereExists(static function (Builder $exists) use ($rows): void {
                $exists->from($rows.' as r')->whereColumn('r.value_id', 'v.id');
            })
            ->get(['v.id', 'v.group_id', 'v.value', 'v.label', 'v.sort', 'v.image']);

        $byGroup = [];

        foreach ($found as $one) {
            $row = (array) $one;
            $slug = $wanted[self::int($row['group_id'] ?? null)] ?? null;
            $value = self::text($row['value'] ?? null);

            if ($slug !== null && self::isValue($value)) {
                $image = $row['image'] ?? null;
                $byGroup[$slug][] = [self::int($row['id'] ?? null), $value, self::text($row['label'] ?? null), self::int($row['sort'] ?? null), is_string($image) ? $image : null];
            }
        }

        $collator = class_exists(Collator::class) ? new Collator('ru_RU') : null;
        $collator?->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        foreach ($byGroup as $slug => $list) {
            // Ties go by label in PHP: a database collation would order equal sorts differently on each engine.
            usort($list, static fn (array $a, array $b): int => $a[3] <=> $b[3]
                ?: ($collator === null ? strnatcasecmp($a[2], $b[2]) : (int) $collator->compare($a[2], $b[2]))
                ?: strcmp($a[1], $b[1]));
            $facet = FacetId::fromString((string) $slug);

            foreach ($list as $rank => [$id, $value, $label, , $image]) {
                $term = new FacetTerm($id, $facet, FacetValueId::fromString($value), $label, $image, $rank);
                $this->terms[$slug][] = $term;
                $this->byTerm[$id] = $term;
            }
        }
    }

    /**
     * @return array<string, GroupInfo>
     */
    private function readGroups(): array
    {
        if ($this->generation === 0) {
            return [];
        }

        $rows = $this->tables->table($this->tables->groups())
            ->where('catalog', $this->spec->name)
            ->where('active', true)
            ->get(['id', 'slug', 'title', 'position', 'widget', 'multiple', 'producer']);

        $stored = [];

        foreach ($rows as $one) {
            $row = (array) $one;
            $stored[self::text($row['slug'] ?? null)] = $row;
        }

        $groups = [];

        foreach ($this->spec->facets as $slot => $facet) {
            if ($facet->isDeclared()) {
                $row = $stored[$facet->slug] ?? null;

                if ($row !== null) {
                    $groups[$facet->slug] = new GroupInfo(self::int($row['id'] ?? null), $facet->slug, $facet->title, $facet->widget, $facet->multiple, $slot, null, null);
                }

                continue;
            }

            $members = [];

            foreach ($stored as $slug => $row) {
                if (($row['producer'] ?? null) !== $facet->slug || $this->spec->declared((string) $slug) !== null || ! self::isFacet((string) $slug)) {
                    continue;
                }

                $members[] = new GroupInfo(
                    self::int($row['id'] ?? null),
                    (string) $slug,
                    self::text($row['title'] ?? null),
                    self::text($row['widget'] ?? null) ?: 'list',
                    (bool) ($row['multiple'] ?? true),
                    $slot,
                    $facet->slug,
                    is_numeric($row['position'] ?? null) ? (int) $row['position'] : null,
                );
            }

            usort($members, static fn (GroupInfo $a, GroupInfo $b): int => ($a->position === null) <=> ($b->position === null)
                ?: ($a->position ?? 0) <=> ($b->position ?? 0)
                ?: strcmp($a->title, $b->title)
                ?: strcmp($a->slug, $b->slug));

            foreach ($members as $member) {
                $groups[$member->slug] = $member;
            }
        }

        foreach ($groups as $slug => $group) {
            $this->slugs[$group->id] = $slug;
        }

        return $groups;
    }

    private static function isFacet(string $slug): bool
    {
        try {
            return FacetId::fromString($slug)->value === $slug;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function isValue(string $value): bool
    {
        try {
            return FacetValueId::fromString($value)->value === $value;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : (is_bool($value) ? (int) $value : 0);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
