<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;
use TimurTurdyev\Ariadne\Catalog\Facet;
use TimurTurdyev\Ariadne\Catalog\Term;
use TimurTurdyev\Ariadne\Facets\FacetValueId;

/**
 * Turns documents into rows of a generation: one code for the rebuild, the sync and the replay of marks.
 *
 * New groups and values are inserted at once, since rows need their ids. During a rebuild the changes of
 * existing ones wait for the publication; a sync writes them right away, the latest edit wins.
 *
 * @internal
 */
final class Projector
{
    private const BATCH = 500;

    /** @var array<string, array{id: int, producer: ?string}> slug => group as stored */
    private array $groups = [];

    /** @var array<string, array{title: string, widget: string, multiple: bool, position: ?int, producer: ?string}> slug => fields this run wants stored */
    private array $wanted = [];

    /** @var array<string, string> group slug => dynamic facet that owns it in this run */
    private array $owners = [];

    /** @var array<string, array<string, Facet>|null> dynamic facet => its described groups, null without describedBy() */
    private array $described = [];

    /** @var array<string, ?string> group slug => why data may not use it, cached */
    private array $slugProblems = [];

    /** @var array<string, int> "group id|value" => value id */
    private array $valueIds = [];

    /** @var array<string, array{label: string, sort: int, image: ?string}> "group id|value" => as stored */
    private array $stored = [];

    /** @var array<string, array{label: string, sort: int, image: ?string}> "group id|value" => as this run resolved it */
    private array $labels = [];

    private bool $preloaded = false;

    /**
     * @param  bool  $rebuilding  a rebuild reads describedBy() and keeps label changes for the publication
     */
    public function __construct(
        private readonly CatalogSpec $spec,
        private readonly Tables $tables,
        public readonly RebuildReport $report,
        private readonly bool $rebuilding,
    ) {}

    /**
     * Groups of declared facets, and in a rebuild the described ones, get their ids before any document is read.
     */
    public function prepare(): void
    {
        $this->loadGroups();
        $position = 0;

        foreach ($this->spec->facets as $facet) {
            if ($facet->isDeclared()) {
                $this->want($facet->slug, $facet->title, $facet->widget, $facet->multiple, $position, null);
            } elseif ($this->rebuilding && $facet->described !== null) {
                $this->describe($facet);
            }

            $position++;
        }

        $this->insertGroups();

        if ($this->rebuilding) {
            $this->preloadValues();
        }
    }

    /**
     * @param  iterable<Model>  $documents  loaded with the relations of every facet
     * @return list<array{document_id: int, group_id: int, value_id: int, covers: int}>
     */
    public function rows(iterable $documents): array
    {
        /** @var array<int, list<array{0: string, 1: string, 2: Term}>> $found document => [group slug, value slug, term] */
        $found = [];

        foreach ($documents as $document) {
            $key = $document->getKey();
            $id = is_int($key) || is_numeric($key) ? (int) $key : throw new RuntimeException('Catalog documents need integer keys, got '.get_debug_type($key).'.');
            $this->report->documents++;
            $found[$id] = $this->termsOf($document);
        }

        $this->insertGroups();
        $this->resolveValues($found);

        $rows = [];

        foreach ($found as $document => $terms) {
            $values = [];

            foreach ($terms as [$group, $value]) {
                $groupId = $this->groups[$group]['id'] ?? null;
                $valueId = $groupId === null ? null : ($this->valueIds[$groupId.'|'.$value] ?? null);

                if ($groupId !== null && $valueId !== null) {
                    $values[$valueId] = $groupId;
                }
            }

            ksort($values);
            $covered = [];

            foreach ($values as $valueId => $groupId) {
                // The smallest value id of each group carries the bit: SUM(covers) counts documents, not values.
                $rows[] = ['document_id' => $document, 'group_id' => $groupId, 'value_id' => $valueId, 'covers' => isset($covered[$groupId]) ? 0 : 1];
                $covered[$groupId] = true;
            }

            if ($values !== []) {
                $this->report->documentsWithRows++;
            }
        }

        $this->report->rows += count($rows);

        return $rows;
    }

    /**
     * Group fields, labels and the active flag as this rebuild resolved them; runs inside the flip transaction.
     */
    public function publish(int $generation): void
    {
        $groups = $this->tables->groups();

        foreach ($this->wanted as $slug => $fields) {
            $id = $this->groups[$slug]['id'] ?? null;

            if ($id !== null) {
                $this->tables->table($groups)->where('id', $id)->update([...$fields, 'active' => true]);
            }
        }

        $this->tables->table($groups)
            ->where('catalog', $this->spec->name)
            ->whereNotIn('slug', array_map(strval(...), array_keys($this->wanted)))
            ->update(['active' => false]);

        $this->writeLabels();

        $rows = $this->tables->rows($this->spec->name, $generation);
        $this->tables->table($this->tables->values())
            ->whereIn('group_id', array_column($this->groups, 'id'))
            ->whereIn('id', static fn (Builder $written): Builder => $written->from($rows)->select('value_id'))
            ->update(['seen' => $generation]);
    }

    /**
     * Labels a sync resolved, written at once: between rebuilds the latest edit wins.
     *
     * @param  list<int>  $values  ids of values the sync wrote rows for
     */
    public function flush(array $values, int $generation): void
    {
        $this->writeLabels();

        foreach (array_chunk(array_values(array_unique($values)), self::BATCH) as $chunk) {
            $this->tables->table($this->tables->values())->whereIn('id', $chunk)->update(['seen' => $generation]);
        }
    }

    /**
     * What a document would be stored as, "group|value" each, without writing anything: the freshness check.
     *
     * @return list<string>
     */
    public function preview(Model $document): array
    {
        $keys = [];

        foreach ($this->termsOf($document) as [$group, $value]) {
            $keys[] = $group.'|'.$value;
        }

        sort($keys);

        return $keys;
    }

    /**
     * Group ids of this catalog, for the rows of a document that is gone.
     *
     * @return array<string, int>
     */
    public function groupIds(): array
    {
        return array_map(static fn (array $group): int => $group['id'], $this->groups);
    }

    /**
     * @return list<array{0: string, 1: string, 2: Term}>
     */
    private function termsOf(Model $document): array
    {
        $terms = [];
        $refused = [];
        $undescribed = [];

        foreach ($this->spec->facets as $facet) {
            foreach ($facet->termsOf($document) as $term) {
                $group = $facet->isDynamic() ? $this->dynamicGroup($facet, $term, $refused, $undescribed) : $facet->slug;

                if ($group === null) {
                    continue;
                }

                $value = $this->valueSlug($group, $term->value);

                if ($value !== null) {
                    $terms[$group.'|'.$value] = [$group, $value, $term];
                }
            }
        }

        foreach ($refused as $group => $reason) {
            $this->report->refuse((string) $group, $reason);
        }

        foreach (array_keys($undescribed) as $group) {
            $this->report->undescribed[(string) $group] = ($this->report->undescribed[(string) $group] ?? 0) + 1;
        }

        return array_values($terms);
    }

    /**
     * The group a term of a dynamic facet goes to, or null when it is refused or not described.
     *
     * @param  array<string, string>  $refused  group => reason, once per document
     * @param  array<string, true>  $undescribed
     */
    private function dynamicGroup(Facet $facet, Term $term, array &$refused, array &$undescribed): ?string
    {
        $slug = $term->group;

        if ($slug === null) {
            $refused['('.$facet->slug.')'] = 'a term of a dynamic facet names no group: call in() on it';

            return null;
        }

        $problem = $this->slugProblems[$slug] ??= $this->spec->slugProblem($slug);
        // A rebuild may hand a group to another producer; a sync keeps the owner the last rebuild stored.
        $owner = $this->owners[$slug] ?? ($this->rebuilding ? null : ($this->groups[$slug]['producer'] ?? null));

        if ($problem === null && $owner !== null && $owner !== $facet->slug) {
            $problem = "taken by dynamic facet \"{$owner}\"";
        }

        if ($problem !== null) {
            $refused[$slug] = $problem;

            return null;
        }

        $described = $this->described[$facet->slug] ?? null;

        if ($described !== null) {
            if (! isset($described[$slug])) {
                $undescribed[$slug] = true;

                return null;
            }

            return $slug;
        }

        if ($this->rebuilding) {
            $title = trim((string) $term->groupTitle);

            if ($title === '') {
                $refused[$slug] = 'has no title: pass one to in() or describe the groups with describedBy()';

                return null;
            }

            $kept = $this->wanted[$slug]['title'] ?? null;
            // The smallest title wins, so the rebuild gives the same title whatever the order of documents.
            $this->want($slug, $kept === null || strcmp($title, $kept) < 0 ? $title : $kept, Facet::LIST, true, null, $facet->slug);
            $this->owners[$slug] = $facet->slug;

            return $slug;
        }

        if (! isset($this->groups[$slug])) {
            $title = trim((string) $term->groupTitle);
            $this->want($slug, $title === '' ? $slug : $title, Facet::LIST, true, null, $facet->slug);
            $this->owners[$slug] = $facet->slug;
        }

        return $slug;
    }

    private function describe(Facet $facet): void
    {
        $described = [];

        foreach (($facet->described ?? static fn (): array => [])() as $group) {
            if (! $group instanceof Facet || ! $group->isNamed()) {
                $this->report->warnings[] = "describedBy() of \"{$facet->slug}\" returned ".get_debug_type($group).', not Facet::named()';

                continue;
            }

            $problem = $this->spec->slugProblem($group->slug)
                ?? (isset($this->owners[$group->slug]) ? "taken by dynamic facet \"{$this->owners[$group->slug]}\"" : null);

            if ($problem !== null) {
                $this->report->refuse($group->slug, $problem);

                continue;
            }

            $described[$group->slug] = $group;
            $this->owners[$group->slug] = $facet->slug;
            $this->want($group->slug, $group->title, $group->widget, $group->multiple, $group->position, $facet->slug);
        }

        $this->described[$facet->slug] = $described;
    }

    private function want(string $slug, string $title, string $widget, bool $multiple, ?int $position, ?string $producer): void
    {
        $this->wanted[$slug] = ['title' => $title, 'widget' => $widget, 'multiple' => $multiple, 'position' => $position, 'producer' => $producer];
    }

    private function loadGroups(): void
    {
        $rows = $this->tables->table($this->tables->groups())
            ->useWritePdo()
            ->where('catalog', $this->spec->name)
            ->get(['id', 'slug', 'producer']);

        foreach ($rows as $row) {
            $values = (array) $row;
            $this->groups[self::text($values['slug'] ?? null)] = [
                'id' => self::int($values['id'] ?? null),
                'producer' => is_string($values['producer'] ?? null) ? $values['producer'] : null,
            ];
        }
    }

    /**
     * Groups this run wants and storage lacks, inserted with their fields; a parallel insert of the same slug is ignored.
     */
    private function insertGroups(): void
    {
        $missing = [];

        foreach ($this->wanted as $slug => $fields) {
            if (! isset($this->groups[$slug])) {
                $missing[] = ['catalog' => $this->spec->name, 'slug' => (string) $slug, ...$fields, 'kind' => 'list', 'active' => true];
            }
        }

        if ($missing === []) {
            return;
        }

        $this->tables->table($this->tables->groups())->insertOrIgnore($missing);
        $this->report->newGroups += count($missing);
        $before = $this->groups;
        $this->groups = [];
        $this->loadGroups();

        foreach ($missing as $group) {
            if (! isset($this->groups[$group['slug']])) {
                throw new RuntimeException("Group [{$group['slug']}] of catalog [{$this->spec->name}] was not stored.");
            }
        }

        $this->groups += $before;

        if (! $this->rebuilding) {
            // A sync has no publication: the fields it wants are stored with the insert, not later.
            foreach ($missing as $group) {
                unset($this->wanted[$group['slug']]);
            }
        }
    }

    private function preloadValues(): void
    {
        $ids = array_column($this->groups, 'id');

        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $this->remember($this->tables->table($this->tables->values())->useWritePdo()->whereIn('group_id', $chunk)->get(['id', 'group_id', 'value', 'label', 'sort', 'image']));
        }

        $this->preloaded = true;
    }

    /**
     * @param  array<int, list<array{0: string, 1: string, 2: Term}>>  $found
     */
    private function resolveValues(array $found): void
    {
        $missing = [];

        foreach ($found as $terms) {
            foreach ($terms as [$group, $value, $term]) {
                $groupId = $this->groups[$group]['id'] ?? null;

                if ($groupId === null) {
                    continue;
                }

                $key = $groupId.'|'.$value;
                $this->resolveLabel($group, $key, $term);

                if (! isset($this->valueIds[$key])) {
                    $missing[$key] = ['group_id' => $groupId, 'value' => $value];
                }
            }
        }

        if (! $this->preloaded) {
            $this->lookup(array_values($missing));
        }

        $new = [];

        foreach ($missing as $key => $value) {
            if (! isset($this->valueIds[$key])) {
                $new[] = [...$value, ...$this->labels[$key]];
            }
        }

        foreach (array_chunk($new, self::BATCH) as $batch) {
            $this->tables->table($this->tables->values())->insertOrIgnore($batch);
        }

        if ($new !== []) {
            $this->report->newValues += count($new);
            $this->lookup($new);
        }
    }

    /**
     * @param  list<array{group_id: int, value: string}>  $values
     */
    private function lookup(array $values): void
    {
        $byGroup = [];

        foreach ($values as $value) {
            $byGroup[$value['group_id']][] = $value['value'];
        }

        foreach ($byGroup as $group => $slugs) {
            foreach (array_chunk($slugs, self::BATCH) as $chunk) {
                $this->remember($this->tables->table($this->tables->values())
                    ->useWritePdo()
                    ->where('group_id', $group)
                    ->whereIn('value', $chunk)
                    ->get(['id', 'group_id', 'value', 'label', 'sort', 'image']));
            }
        }
    }

    /**
     * @param  iterable<mixed>  $rows
     */
    private function remember(iterable $rows): void
    {
        foreach ($rows as $row) {
            $values = (array) $row;
            $key = self::int($values['group_id'] ?? null).'|'.self::text($values['value'] ?? null);
            $this->valueIds[$key] = self::int($values['id'] ?? null);
            $this->stored[$key] = [
                'label' => self::text($values['label'] ?? null),
                'sort' => self::int($values['sort'] ?? null),
                'image' => is_string($values['image'] ?? null) ? $values['image'] : null,
            ];
        }
    }

    /**
     * Within a run the smallest sort, then the smallest label wins: the same result whatever the order of documents.
     */
    private function resolveLabel(string $group, string $key, Term $term): void
    {
        $label = ['label' => $term->label, 'sort' => $term->sort, 'image' => $term->image];
        $kept = $this->labels[$key] ?? null;

        if ($kept === null) {
            $this->labels[$key] = $label;

            return;
        }

        if ($kept['label'] !== $term->label) {
            $this->report->conflict($group, $term->label);
        }

        if ([$term->sort, $term->label] < [$kept['sort'], $kept['label']]) {
            $this->labels[$key] = $label;
        }
    }

    private function writeLabels(): void
    {
        foreach ($this->labels as $key => $label) {
            $stored = $this->stored[$key] ?? null;
            $id = $this->valueIds[$key] ?? null;

            if ($id !== null && $stored !== null && $stored !== $label) {
                $this->tables->table($this->tables->values())->where('id', $id)->update($label);
                $this->stored[$key] = $label;
            }
        }
    }

    private function valueSlug(string $group, string $value): ?string
    {
        if (self::isSlug($value)) {
            return $value;
        }

        $slug = rtrim(Str::limit(Str::slug($value, '-', $this->spec->slugLanguage), FacetValueId::MAX_LENGTH, ''), '-');

        if (self::isSlug($slug)) {
            $this->report->rename($group, $value, $slug);

            return $slug;
        }

        $this->report->reject($group, $value);

        return null;
    }

    private static function isSlug(string $value): bool
    {
        try {
            return FacetValueId::fromString($value)->value === $value;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
