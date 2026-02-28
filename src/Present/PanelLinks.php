<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Present;

use Closure;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\FacetGroup;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetValueCount;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\PageRequest;
use TimurTurdyev\Ariadne\Query\PriceRange;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\Selection;
use TimurTurdyev\Ariadne\Search\SearchResult;
use TimurTurdyev\Ariadne\Url\QueryStringCodec;

/**
 * The filter panel with ready addresses, so a browser never assembles one itself.
 *
 * @phpstan-type ValueLinks array{value: string, label: string, image: ?string, count: int, selected: bool, disabled: bool, href: string, only: string}
 * @phpstan-type GroupLinks array{facet: string, label: string, operator: 'all'|'any', selected: int, reset: string, operatorHref: ?string, values: list<ValueLinks>}
 * @phpstan-type SortLink array{value: string, label: string, href: string, selected: bool}
 * @phpstan-type Paging array{first: string, prev: ?string, next: ?string, template: string}
 * @phpstan-type PricePanel array{reset: string, input: array{min: string, max: string}, template: string, bounds: array{min: ?float, max: ?float}, selected: array{min: ?float, max: ?float}}
 */
final class PanelLinks
{
    /**
     * @param  (Closure(float): string)|null  $priceInput  a selected bound as the price field shows it; the codec amount by default
     */
    public function __construct(
        private readonly QueryStringCodec $codec,
        private readonly FacetDictionary $dictionary,
        private readonly ?Closure $priceInput = null,
    ) {}

    /**
     * @param  Query|null  $addressed  the query addresses are built from; null - the query of the result, which a panel request has without sort and page
     * @param  array<string, string>  $sorts  slug => label, in display order
     * @return array{facets: list<GroupLinks>, reset: string, sorts: list<SortLink>, paging: Paging, price: PricePanel}
     */
    public function panel(SearchResult $result, LinkBase $base, ?Query $addressed = null, array $sorts = []): array
    {
        $query = $addressed ?? $result->query;
        $unpriced = $query->withPrice(PriceRange::none());

        return [
            'facets' => array_map(fn (FacetGroup $group): array => $this->group($group, $query, $base), $result->panel->groups),
            // Drops the whole selection: text and sort stay.
            'reset' => $this->href($unpriced->withSelection(new Selection), $base),
            'sorts' => $this->sorts($sorts, $query, $base),
            'paging' => $this->paging($result, $query, $base),
            'price' => [
                'reset' => $this->href($unpriced, $base),
                // What the price field shows, in the units of the address; bounds and selected stay in base units.
                'input' => [
                    'min' => $this->priceField($query->price->min),
                    'max' => $this->priceField($query->price->max),
                ],
                'template' => $this->address($this->firstPage($unpriced), $base, price: true),
                'bounds' => [
                    'min' => $result->priceBounds->min,
                    'max' => $result->priceBounds->max,
                ],
                'selected' => [
                    'min' => $result->query->price->min,
                    'max' => $result->query->price->max,
                ],
            ],
        ];
    }

    /**
     * The address of the results for a query, on the first page: after a change the thirtieth may not exist.
     */
    public function href(Query $query, LinkBase $base): string
    {
        return $this->address($this->firstPage($query), $base);
    }

    /**
     * @return GroupLinks
     */
    private function group(FacetGroup $group, Query $query, LinkBase $base): array
    {
        $switched = $this->switched($group, $query);

        return [
            'facet' => $group->facet->value,
            'label' => $group->label,
            'operator' => $group->operator->value,
            'selected' => $group->selectedCount(),
            'reset' => $this->href($query->withoutFacet($group->facet), $base),
            // The same choice with the other operator; null - nothing to switch.
            'operatorHref' => $switched === null ? null : $this->href($switched, $base),
            'values' => array_map(
                fn (FacetValueCount $value): array => $this->value($value, $group, $query, $base),
                $group->values,
            ),
        ];
    }

    /**
     * @return ValueLinks
     */
    private function value(FacetValueCount $value, FacetGroup $group, Query $query, LinkBase $base): array
    {
        return [
            'value' => $value->term->value->value,
            'label' => $value->term->label,
            'image' => $value->term->image,
            'count' => $value->count,
            'selected' => $value->selected,
            // A zero value stays and is dimmed by the client, so the list does not jump on every click.
            'disabled' => $value->isDisabled(),
            'href' => $this->href($this->toggled($value, $group, $query), $base),
            'only' => $this->href($this->only($value, $group, $query), $base),
        ];
    }

    // The operator of the choice wins over the group's: an "all" picked by the buyer survives the next click.
    private function toggled(FacetValueCount $value, FacetGroup $group, Query $query): Query
    {
        $choice = $query->selection->choiceFor($group->facet);
        $values = $choice === null ? [] : $choice->values;
        $id = $value->term->value;

        $next = $choice !== null && $choice->contains($id)
            ? array_values(array_filter($values, static fn (FacetValueId $one): bool => ! $one->equals($id)))
            : [...$values, $id];

        $selection = $next === []
            ? $query->selection->without($group->facet)
            : $query->selection->with(new FacetChoice($group->facet, $next, $choice->operator ?? $group->operator));

        return $query->withSelection($selection);
    }

    private function only(FacetValueCount $value, FacetGroup $group, Query $query): Query
    {
        $choice = $query->selection->choiceFor($group->facet);

        return $query->withSelection($query->selection->with(
            new FacetChoice($group->facet, [$value->term->value], $choice->operator ?? $group->operator),
        ));
    }

    private function switched(FacetGroup $group, Query $query): ?Query
    {
        $choice = $query->selection->choiceFor($group->facet);

        if ($choice === null || ! $this->codec->supportsAll() || ! $this->dictionary->isMultiValued($group->facet)) {
            return null;
        }

        $operator = $choice->operator === FacetOperator::All ? FacetOperator::Any : FacetOperator::All;

        return $query->withSelection($query->selection->with(new FacetChoice($group->facet, $choice->values, $operator)));
    }

    /**
     * @param  array<string, string>  $sorts
     * @return list<SortLink>
     */
    private function sorts(array $sorts, Query $query, LinkBase $base): array
    {
        $links = [];

        foreach ($sorts as $slug => $label) {
            $slug = (string) $slug;

            $links[] = [
                'value' => $slug,
                'label' => $label,
                'href' => $this->href($query->withSort($slug), $base),
                'selected' => $query->sort === $slug,
            ];
        }

        return $links;
    }

    /**
     * @return Paging
     */
    private function paging(SearchResult $result, Query $query, LinkBase $base): array
    {
        $page = $result->page->page;
        $last = max(1, $result->page->pages());
        $at = fn (int $number): string => $this->address($query->withPage(new PageRequest($number, $query->page->perPage)), $base);

        return [
            'first' => $at(1),
            // Past the end, prev leads to the last page rather than to another empty one.
            'prev' => $page > 1 ? $at(min($page - 1, $last)) : null,
            // PageRequest caps the page, so a link past the cap would lead back here.
            'next' => $page < $last && $page < PageRequest::MAX_PAGE ? $at($page + 1) : null,
            'template' => $this->address($query, $base, page: true),
        ];
    }

    private function priceField(?float $bound): string
    {
        if ($bound === null) {
            return '';
        }

        return $this->priceInput === null ? $this->codec->amount($bound) : ($this->priceInput)($bound);
    }

    private function firstPage(Query $query): Query
    {
        return $query->withPage(new PageRequest(1, $query->page->perPage));
    }

    // The address of a query with its page; the flags leave placeholders for the price and the page.
    private function address(Query $query, LinkBase $base, bool $price = false, bool $page = false): string
    {
        [$path, $query] = $this->target($query, $base);

        $search = implode('&', array_filter(
            [$this->codec->template($query, $price, $page), $this->foreignPart($base)],
            static fn (string $part): bool => $part !== '',
        ));

        return $search === '' ? $path : $path.'?'.$search;
    }

    // Every link carries this part, so one long parameter would multiply by the number of links.
    private function foreignPart(LinkBase $base): string
    {
        $part = '';

        foreach ($base->foreign as $name => $value) {
            $pair = $this->codec->queryString([$name => $value]);

            if ($pair === '') {
                continue;
            }

            $next = $part === '' ? $pair : $part.'&'.$pair;

            if (strlen($next) <= LinkBase::MAX_FOREIGN_LENGTH) {
                $part = $next;
            }
        }

        return $part;
    }

    /**
     * A query that keeps every pinned choice stays on the landing path without them; any other goes to the main path.
     *
     * @return array{0: string, 1: Query}
     */
    private function target(Query $query, LinkBase $base): array
    {
        if ($base->landing === null) {
            return [$base->path, $query];
        }

        $selection = $query->selection;

        foreach ($base->pinned->all() as $pinned) {
            $choice = $selection->choiceFor($pinned->facet);

            if ($choice === null || ! $this->sameChoice($choice, $pinned)) {
                return [$base->path, $query];
            }

            $selection = $selection->without($pinned->facet);
        }

        return [$base->landing, $query->withSelection($selection)];
    }

    private function sameChoice(FacetChoice $a, FacetChoice $b): bool
    {
        $left = $a->valueSlugs();
        $right = $b->valueSlugs();

        sort($left, SORT_STRING);
        sort($right, SORT_STRING);

        // "All" over a single value selects what "any" does, so only a combined choice differs.
        return $left === $right && $a->isCombined() === $b->isCombined();
    }
}
