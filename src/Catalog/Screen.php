<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use JsonSerializable;
use TimurTurdyev\Ariadne\Catalog\Screen\Applied;
use TimurTurdyev\Ariadne\Catalog\Screen\Group;
use TimurTurdyev\Ariadne\Catalog\Screen\Paging;
use TimurTurdyev\Ariadne\Catalog\Screen\PriceFilter;
use TimurTurdyev\Ariadne\Catalog\Screen\SortLink;
use TimurTurdyev\Ariadne\Search\SearchResult;

/**
 * Everything a catalog page renders, every address built on the server: one object for Blade, JSON and Inertia.
 *
 * @template TItem
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Screen implements Arrayable, JsonSerializable
{
    /**
     * @param  Collection<int, TItem>  $items  in the order of the listing
     * @param  list<Group>  $facets
     * @param  list<SortLink>  $sorts  the default order has no parameter and is marked selected
     * @param  list<Applied>  $applied
     * @param  SearchResult  $result  the raw answer, for SEO rules that stay in the application
     * @param  string|null  $redirectTo  the canonical address with the foreign parameters of the request, when the request differs
     */
    public function __construct(
        public Collection $items,
        public int $total,
        public int $page,
        public int $perPage,
        public array $facets,
        public ?PriceFilter $price,
        public array $sorts,
        public array $applied,
        public string $reset,
        public Paging $paging,
        public string $canonical,
        public bool $degraded,
        public SearchResult $result,
        private ?string $redirectTo = null,
    ) {}

    public function pages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Where to send a request whose address is not canonical, such as a default sort spelled out; null when it is.
     */
    public function redirect(): ?string
    {
        return $this->redirectTo;
    }

    /**
     * Laravel's paginator on the addresses of the package, for {{ $screen->paginator()->links() }}.
     *
     * @return LengthAwarePaginator<int, TItem>
     */
    public function paginator(): LengthAwarePaginator
    {
        // The first page carries the whole query, so the paginator only adds its page parameter.
        return new LengthAwarePaginator($this->items, $this->total, $this->perPage, $this->page, [
            'path' => $this->paging->first,
            'pageName' => 'page',
        ]);
    }

    /**
     * The same screen with every item mapped, such as to a card array for JSON.
     *
     * @template TMapped
     *
     * @param  Closure(TItem): TMapped  $map
     * @return self<TMapped>
     */
    public function through(Closure $map): self
    {
        return new self($this->items->map($map), $this->total, $this->page, $this->perPage, $this->facets, $this->price, $this->sorts, $this->applied, $this->reset, $this->paging, $this->canonical, $this->degraded, $this->result, $this->redirectTo);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items->toArray(),
            'total' => $this->total,
            'page' => $this->page,
            'pages' => $this->pages(),
            'perPage' => $this->perPage,
            'facets' => $this->facets,
            'price' => $this->price,
            'sorts' => $this->sorts,
            'applied' => $this->applied,
            'reset' => $this->reset,
            'paging' => $this->paging,
            'canonical' => $this->canonical,
            'degraded' => $this->degraded,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
