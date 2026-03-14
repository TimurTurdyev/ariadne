<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support\Shop;

use Collator;
use Illuminate\Support\Str;
use TimurTurdyev\Ariadne\Catalog\Panel;

/**
 * The screen of the test shop computed naively in memory from the shop tables, never from the generation rows.
 *
 * @phpstan-type Doc array{id: int, active: bool, category: int, brand: ?int, colors: list<int>, price: int, sales: int, published: ?string, values: array<string, array<string, true>>}
 * @phpstan-type GroupMeta array{title: string, widget: string, multiple: bool, slot: int, position: ?int, dynamic: bool}
 * @phpstan-type Address array{params: array<string, string>, category: ?list<int>, colors: ?list<int>, brand: ?list<int>, ranked: ?list<int>, pins: ?array<string, string>, perPage: int}
 */
final class ShopOracle
{
    public const REFUSED = ['Цвет каркаса', 'price', '42', 'brand'];

    /** @var array<int, Doc> */
    private array $docs = [];

    /** @var array<string, GroupMeta> */
    private array $groups = [];

    /** @var array<string, list<string>> group => values in panel order */
    private array $ranks = [];

    /**
     * @param  array<string, array{title: string, position: ?int, widget: string, multiple: bool}>|null  $described  groups of describedBy(); null - titles from the terms
     */
    public function __construct(private readonly ?array $described = null)
    {
        $this->load();
    }

    /**
     * @param  Address  $address
     * @return array{total: int, ids: list<int>, bounds: array{0: ?int, 1: ?int}, sort: string, groups: list<array{facet: string, widget: string, selected: int, coverage: ?float, values: list<array{0: string, 1: int, 2: bool, 3: bool}>}>}
     */
    public function screen(array $address, Panel $rules, bool $keepZeros, int $minValues): array
    {
        $scope = array_filter($this->docs, fn (array $doc): bool => $doc['active'] && $this->inScope($doc, $address));
        $selection = $this->selection($address);
        [$min, $max] = $this->price($address['params']);
        $main = array_filter($scope, fn (array $doc): bool => $this->matches($doc, $selection, $min, $max, null));

        $ranked = $address['ranked'];
        $sort = $address['params']['sort'] ?? '';
        $known = ['popular', 'new', 'price-asc', 'price-desc', ...($ranked === null ? [] : ['relevance'])];
        $sort = in_array($sort, $known, true) ? $sort : '';
        $default = $ranked === null ? 'popular' : 'relevance';
        $sort = $sort === '' ? $default : $sort;
        $listing = array_values($main);
        usort($listing, fn (array $a, array $b): int => $this->compare($a, $b, $sort, $ranked));
        $page = max(1, min(500, ctype_digit($address['params']['page'] ?? '') ? (int) $address['params']['page'] : 1));
        $ids = array_map(static fn (array $doc): int => $doc['id'], array_slice($listing, ($page - 1) * $address['perPage'], $address['perPage']));

        $prices = array_map(static fn (array $doc): int => $doc['price'], $scope);
        $bounds = [$prices === [] ? null : min($prices), $prices === [] ? null : max($prices)];

        // Without a selection the main counts serve as the palette, so values a price zeroes are gone, not dimmed.
        $paletteDocs = $selection === [] ? $main : $scope;
        $covered = [];

        foreach ($scope as $doc) {
            foreach (array_keys($doc['values']) as $group) {
                $covered[$group] = ($covered[$group] ?? 0) + 1;
            }
        }

        $total = count($scope);
        $candidates = [];

        foreach ($this->groups as $slug => $meta) {
            $present = isset($selection[$slug]);

            foreach ($paletteDocs as $doc) {
                if (isset($doc['values'][$slug])) {
                    $present = true;

                    break;
                }
            }

            if ($present) {
                $candidates[] = (string) $slug;
            }
        }

        $shown = $this->arranged($candidates, $selection, $rules, $covered, $total);
        $groups = [];

        foreach ($shown as $slug) {
            $choice = $selection[$slug] ?? null;
            $terms = [];

            foreach ($paletteDocs as $doc) {
                foreach (array_keys($doc['values'][$slug] ?? []) as $value) {
                    $terms[$value] = true;
                }
            }

            foreach (array_keys($choice['values'] ?? []) as $value) {
                $terms[$value] = true;
            }

            if ($terms === []) {
                continue;
            }

            $counting = $choice === null ? $main : array_filter($scope, fn (array $doc): bool => $this->matches($doc, $selection, $min, $max, $slug));
            $values = [];

            foreach ($this->ranks[$slug] as $value) {
                if (! isset($terms[$value])) {
                    continue;
                }

                $count = 0;

                foreach ($counting as $doc) {
                    if (isset($doc['values'][$slug][$value])) {
                        $count++;
                    }
                }

                $isSelected = isset($choice['values'][$value]);
                $disabled = $count === 0 && ! $isSelected;

                if ($keepZeros || ! $disabled) {
                    $values[] = [$value, $count, $isSelected, $disabled];
                }
            }

            $selectedCount = count(array_filter($values, static fn (array $value): bool => $value[2]));

            if ($choice === null && $values === []) {
                continue;
            }

            if ($selectedCount === 0 && $this->groups[$slug]['widget'] !== 'toggle' && count($values) < $minValues) {
                continue;
            }

            $groups[] = [
                'facet' => $slug,
                'widget' => $this->groups[$slug]['widget'],
                'selected' => $selectedCount,
                'coverage' => $rules->minCoverage > 0.0 ? ($total > 0 ? ($covered[$slug] ?? 0) / $total : 0.0) : null,
                'values' => $values,
            ];
        }

        if ($rules->max !== null && count($groups) > $rules->max) {
            $head = array_slice($groups, 0, $rules->max);

            foreach (array_slice($groups, $rules->max) as $cut) {
                if ($cut['selected'] > 0) {
                    $head[] = $cut;
                }
            }

            $groups = $head;
        }

        return ['total' => count($main), 'ids' => $ids, 'bounds' => $bounds, 'sort' => $sort, 'groups' => $groups];
    }

    /**
     * @return list<int>
     */
    public function documentIds(): array
    {
        return array_keys($this->docs);
    }

    /**
     * @return array<string, list<string>> group => its known values
     */
    public function vocabulary(): array
    {
        return $this->ranks;
    }

    /**
     * @param  list<string>  $candidates
     * @param  array<string, array{all: bool, values: array<string, true>}>  $selection
     * @param  array<string, int>  $covered
     * @return list<string>
     */
    private function arranged(array $candidates, array $selection, Panel $rules, array $covered, int $total): array
    {
        usort($candidates, function (string $a, string $b) use ($covered): int {
            $ga = $this->groups[$a];
            $gb = $this->groups[$b];

            return $ga['slot'] <=> $gb['slot']
                ?: ($ga['position'] === null) <=> ($gb['position'] === null)
                ?: ($ga['position'] ?? 0) <=> ($gb['position'] ?? 0)
                ?: ($covered[$b] ?? 0) <=> ($covered[$a] ?? 0)
                ?: strcmp($ga['title'], $gb['title'])
                ?: strcmp($a, $b);
        });

        $pins = $rules->only ?? $rules->first;
        $kept = [];

        foreach ($candidates as $at => $slug) {
            $share = $total > 0 ? ($covered[$slug] ?? 0) / $total : 0.0;
            $visible = isset($selection[$slug]) || (
                ($rules->only === null || in_array($slug, $rules->only, true))
                && ! in_array($slug, $rules->hidden, true)
                && (in_array($slug, $pins, true) || $this->groups[$slug]['widget'] === 'toggle' || $rules->minCoverage <= 0.0 || $share >= $rules->minCoverage)
            );

            if ($visible) {
                $kept[] = [$at, $slug];
            }
        }

        $pinAt = array_flip($pins);

        usort($kept, static function (array $a, array $b) use ($pinAt, $rules, $covered): int {
            $pa = $pinAt[$a[1]] ?? null;
            $pb = $pinAt[$b[1]] ?? null;

            if ($pa !== null || $pb !== null) {
                return ($pa ?? PHP_INT_MAX) <=> ($pb ?? PHP_INT_MAX) ?: $a[0] <=> $b[0];
            }

            if ($rules->byCoverage) {
                return ($covered[$b[1]] ?? 0) <=> ($covered[$a[1]] ?? 0) ?: $a[0] <=> $b[0];
            }

            return $a[0] <=> $b[0];
        });

        return array_map(static fn (array $one): string => $one[1], $kept);
    }

    /**
     * @param  Doc  $doc
     * @param  Address  $address
     */
    private function inScope(array $doc, array $address): bool
    {
        return ($address['category'] === null || in_array($doc['category'], $address['category'], true))
            && ($address['brand'] === null || ($doc['brand'] !== null && in_array($doc['brand'], $address['brand'], true)))
            && ($address['colors'] === null || array_intersect($doc['colors'], $address['colors']) !== [])
            && ($address['ranked'] === null || in_array($doc['id'], $address['ranked'], true));
    }

    /**
     * The choices as the codec reads them: unknown values dropped, a choice with none left dropped, "all" of a single group is "any".
     *
     * @param  Address  $address
     * @return array<string, array{all: bool, values: array<string, true>}>
     */
    private function selection(array $address): array
    {
        $selection = [];

        foreach ($address['params'] as $key => $raw) {
            if (in_array($key, ['q', 'sort', 'page', 'price'], true) || ! isset($this->ranks[$key])) {
                continue;
            }

            $all = str_starts_with($raw, 'all:');
            $values = [];

            foreach (explode(',', $all ? substr($raw, 4) : $raw) as $value) {
                $value = trim($value);

                if (in_array($value, $this->ranks[$key], true)) {
                    $values[$value] = true;
                }
            }

            if ($values !== []) {
                $selection[$key] = ['all' => $all && $this->groups[$key]['multiple'], 'values' => $values];
            }
        }

        foreach ($address['pins'] ?? [] as $group => $value) {
            $selection[$group] = ['all' => false, 'values' => [$value => true]];
        }

        return $selection;
    }

    /**
     * @param  Doc  $doc
     * @param  array<string, array{all: bool, values: array<string, true>}>  $selection
     */
    private function matches(array $doc, array $selection, ?int $min, ?int $max, ?string $without): bool
    {
        return $this->selected($doc, $selection, $without) && ($min === null || $doc['price'] >= $min) && ($max === null || $doc['price'] <= $max);
    }

    /**
     * @param  Doc  $doc
     * @param  array<string, array{all: bool, values: array<string, true>}>  $selection
     */
    private function selected(array $doc, array $selection, ?string $without): bool
    {
        foreach ($selection as $group => $choice) {
            if ($group === $without) {
                continue;
            }

            $carried = array_intersect_key($choice['values'], $doc['values'][$group] ?? []);

            if ($choice['all'] ? count($carried) !== count($choice['values']) : $carried === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{0: ?int, 1: ?int}
     */
    private function price(array $params): array
    {
        $raw = $params['price'] ?? '';

        if (! str_contains($raw, '-')) {
            return [null, null];
        }

        [$min, $max] = explode('-', $raw, 2);
        $min = ctype_digit($min) && (int) $min > 0 ? (int) $min : null;
        $max = ctype_digit($max) && (int) $max > 0 ? (int) $max : null;

        return $min !== null && $max !== null && $min > $max ? [$max, $min] : [$min, $max];
    }

    /**
     * @param  Doc  $a
     * @param  Doc  $b
     * @param  list<int>|null  $ranked
     */
    private function compare(array $a, array $b, string $sort, ?array $ranked): int
    {
        $order = match ($sort) {
            'relevance' => array_search($a['id'], $ranked ?? [], true) <=> array_search($b['id'], $ranked ?? [], true),
            'popular' => $b['sales'] <=> $a['sales'],
            // NULLs last in either direction.
            'new' => ($a['published'] === null) <=> ($b['published'] === null) ?: strcmp((string) $b['published'], (string) $a['published']),
            'price-asc' => $a['price'] <=> $b['price'],
            'price-desc' => $b['price'] <=> $a['price'],
            default => 0,
        };

        return $order ?: $a['id'] <=> $b['id'];
    }

    private function load(): void
    {
        $this->groups = [
            'brand' => ['title' => 'Brand', 'widget' => 'list', 'multiple' => false, 'slot' => 0, 'position' => null, 'dynamic' => false],
            'color' => ['title' => 'Color', 'widget' => 'swatch', 'multiple' => true, 'slot' => 1, 'position' => null, 'dynamic' => false],
            'size' => ['title' => 'Size', 'widget' => 'list', 'multiple' => true, 'slot' => 2, 'position' => null, 'dynamic' => false],
            'sale' => ['title' => 'On sale', 'widget' => 'toggle', 'multiple' => false, 'slot' => 4, 'position' => null, 'dynamic' => false],
        ];
        $labels = [];
        $put = static function (string $group, string $value, string $label, int $sort) use (&$labels): void {
            $kept = $labels[$group][$value] ?? null;

            if ($kept === null || [$sort, $label] < [$kept['sort'], $kept['label']]) {
                $labels[$group][$value] = ['label' => $label, 'sort' => $sort];
            }
        };

        foreach (Product::query()->with(['brand', 'colors', 'variants', 'features'])->oldest('id')->get() as $product) {
            $values = [];

            if ($product->brand !== null) {
                $slug = self::slug($product->brand->slug) ?? '';
                self::add($values, 'brand', $slug);
                $put('brand', $slug, $product->brand->name, 0);
            }

            foreach ($product->colors as $color) {
                self::add($values, 'color', $color->slug);
                $put('color', $color->slug, $color->name, 0);
            }

            foreach ($product->variants as $variant) {
                $slug = $variant->stock > 0 ? self::slug($variant->size) : null;

                if ($slug !== null) {
                    self::add($values, 'size', $slug);
                    $put('size', $slug, $variant->size, 0);
                }
            }

            foreach ($product->features as $feature) {
                $group = $feature->group;
                $slug = self::slug($feature->value);

                if ($slug === null || in_array($group, self::REFUSED, true) || ($this->described !== null && ! isset($this->described[$group]))) {
                    continue;
                }

                $described = $this->described[$group] ?? null;
                $this->groups[$group] ??= [
                    'title' => $described['title'] ?? $feature->group_title,
                    'widget' => $described['widget'] ?? 'list',
                    'multiple' => $described['multiple'] ?? true,
                    'slot' => 3,
                    'position' => $described['position'] ?? null,
                    'dynamic' => true,
                ];
                self::add($values, $group, $slug);
                $put($group, $slug, $feature->label, $feature->sort);
            }

            if ($product->old_price !== null && $product->old_price > $product->price) {
                self::add($values, 'sale', '1');
                $put('sale', '1', 'On sale', 0);
            }

            $this->docs[$product->id] = [
                'id' => $product->id,
                'active' => $product->active,
                'category' => $product->category_id,
                'brand' => $product->brand_id,
                'colors' => array_values($product->colors->map(static fn (Color $color): int => $color->id)->all()),
                'price' => $product->price,
                'sales' => $product->sales,
                'published' => $product->published_at,
                'values' => $values,
            ];
        }

        $collator = new Collator('ru_RU');
        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        foreach ($labels as $group => $values) {
            uksort($values, static fn (string $a, string $b): int => $values[$a]['sort'] <=> $values[$b]['sort']
                ?: (int) $collator->compare($values[$a]['label'], $values[$b]['label'])
                ?: strcmp($a, $b));
            $this->ranks[$group] = array_map(strval(...), array_keys($values));
        }

        $this->groups = array_intersect_key($this->groups, $this->ranks);
    }

    /**
     * @param  array<string, array<string, true>>  $values
     */
    private static function add(array &$values, string $group, string $value): void
    {
        $values[$group][$value] = true;
    }

    private static function slug(string $value): ?string
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value) === 1) {
            return $value;
        }

        $slug = Str::slug($value);

        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 ? $slug : null;
    }
}
