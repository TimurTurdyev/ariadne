<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

use Illuminate\Support\Str;
use TimurTurdyev\Ariadne\Facets\FacetId;

/**
 * The whole user intent: where to look, what is selected, in which order and on which page.
 */
final readonly class Query
{
    public const MAX_TEXT_LENGTH = 128;

    /** Search text, trimmed and capped; empty means none. */
    public ?string $text;

    /**
     * @param  string  $sort  application slug the engine adapter maps to fields; '' is the default order
     */
    public function __construct(
        public Scope $scope,
        public Selection $selection = new Selection,
        public PriceRange $price = new PriceRange,
        public string $sort = '',
        public PageRequest $page = new PageRequest,
        ?string $text = null,
        public Need $need = Need::Everything,
        public bool $prefix = false,
    ) {
        // Trimmed as the TrimStrings middleware does, and again after the cut, so the text reads back the same.
        $normalized = $text === null ? null : Str::trim($text);

        if ($normalized !== null && mb_strlen($normalized) > self::MAX_TEXT_LENGTH) {
            $normalized = Str::trim(mb_substr($normalized, 0, self::MAX_TEXT_LENGTH));
        }

        $this->text = $normalized === '' ? null : $normalized;
    }

    public function withSelection(Selection $selection): self
    {
        return new self($this->scope, $selection, $this->price, $this->sort, $this->page, $this->text, $this->need, $this->prefix);
    }

    public function withPrice(PriceRange $price): self
    {
        return new self($this->scope, $this->selection, $price, $this->sort, $this->page, $this->text, $this->need, $this->prefix);
    }

    public function withSort(string $sort): self
    {
        return new self($this->scope, $this->selection, $this->price, $sort, $this->page, $this->text, $this->need, $this->prefix);
    }

    public function withPage(PageRequest $page): self
    {
        return new self($this->scope, $this->selection, $this->price, $this->sort, $page, $this->text, $this->need, $this->prefix);
    }

    public function withText(?string $text): self
    {
        return new self($this->scope, $this->selection, $this->price, $this->sort, $this->page, $text, $this->need, $this->prefix);
    }

    /**
     * The same query with the last word matched as a prefix: a retry after an empty exact search.
     */
    public function withPrefix(): self
    {
        return new self($this->scope, $this->selection, $this->price, $this->sort, $this->page, $this->text, $this->need, true);
    }

    /**
     * The query without the facet's own filter: the base of what-if counts.
     */
    public function withoutFacet(FacetId $facet): self
    {
        return $this->withSelection($this->selection->without($facet));
    }

    /**
     * Scope and text only, so the palette and the price bounds are not cut by the selection.
     */
    public function structureOnly(): self
    {
        return new self($this->scope, new Selection, PriceRange::none(), $this->sort, $this->page, $this->text, $this->need, $this->prefix);
    }

    /**
     * A panel request drops page and sort: they do not change counts and would split one cache entry.
     */
    public function needing(Need $need): self
    {
        $page = $need === Need::Panel ? new PageRequest : $this->page;
        $sort = $need === Need::Panel ? '' : $this->sort;

        return new self($this->scope, $this->selection, $this->price, $sort, $page, $this->text, $need, $this->prefix);
    }

    /**
     * Cache signature; region, host or currency are the caller's to add to the key.
     */
    public function signature(): string
    {
        $facets = [];

        foreach ($this->selection->all() as $choice) {
            // The operator as stored: a cached screen carries it into the panel and its links.
            $facets[] = $choice->facet->value.':'.$choice->operator->value.':'.implode(',', $choice->valueSlugs());
        }

        return implode('|', [
            $this->need->value,
            $this->scope->signature(),
            implode(';', $facets),
            $this->price->min.'-'.$this->price->max,
            $this->sort,
            $this->page->page.'x'.$this->page->perPage,
            $this->prefix ? 'prefix' : 'exact',
            // Encoded, so the text cannot pass for another part of the signature.
            rawurlencode((string) $this->text),
        ]);
    }

    public function hasFacets(): bool
    {
        return ! $this->selection->isEmpty();
    }
}
