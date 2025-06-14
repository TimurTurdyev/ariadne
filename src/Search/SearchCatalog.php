<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Search;

use Closure;
use Illuminate\Cache\Repository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TimurTurdyev\SimpleSearch\Dictionary\FacetDictionary;
use TimurTurdyev\SimpleSearch\Facets\DisjunctivePolicy;
use TimurTurdyev\SimpleSearch\Facets\FacetPanel;
use TimurTurdyev\SimpleSearch\Facets\ValueOrder;
use TimurTurdyev\SimpleSearch\Index\CatalogIndex;
use TimurTurdyev\SimpleSearch\Index\CatalogIndexUnavailable;
use TimurTurdyev\SimpleSearch\Query\PriceRange;
use TimurTurdyev\SimpleSearch\Query\PriceScale;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\QueryPlan;
use TimurTurdyev\SimpleSearch\Results\Hit;
use TimurTurdyev\SimpleSearch\Results\PriceBounds;
use TimurTurdyev\SimpleSearch\Results\ResultPage;

/**
 * One catalog screen: the user's query in, results with the filter panel out.
 */
final class SearchCatalog
{
    // Fresh for 300 seconds, then served stale while it refreshes, gone after 600.
    private const CACHE_TTL = [300, 600];

    /**
     * @param  Repository|null  $cache  null builds every screen anew
     * @param  ValueOrder  $order  order of values inside a panel group
     * @param  bool  $filteredBounds  count price bounds over the selection, not the bare scope
     * @param  (Closure(Hit, PriceScale): Hit)|null  $displayPrices  moves the prices a hit carries to the display scale
     */
    public function __construct(
        private readonly CatalogIndex $index,
        private readonly FacetDictionary $dictionary,
        private readonly DisjunctivePolicy $facets = new DisjunctivePolicy,
        private readonly ?Repository $cache = null,
        private readonly LoggerInterface $log = new NullLogger,
        private readonly ValueOrder $order = ValueOrder::Natural,
        private readonly bool $filteredBounds = false,
        private readonly ?Closure $displayPrices = null,
    ) {}

    /**
     * @param  PriceScale  $scale  multiplier of the shown price; where it comes from stays with the caller
     * @param  list<string>  $cacheKeyParts  everything else the answer depends on, such as region or host
     */
    public function __invoke(
        Query $query,
        PriceScale $scale = new PriceScale,
        array $cacheKeyParts = [],
    ): SearchResult {
        try {
            return $this->cached($query, $scale, $cacheKeyParts);
        } catch (CatalogIndexUnavailable) {
            // Not cached: a blink of the engine must not live in the cache.
            return SearchResult::unavailable($query);
        }
    }

    /**
     * @param  list<string>  $cacheKeyParts
     */
    private function cached(Query $query, PriceScale $scale, array $cacheKeyParts): SearchResult
    {
        if ($this->cache === null) {
            return $this->screen($query, $scale);
        }

        $key = $this->cacheKey($query, $scale, $cacheKeyParts);

        return $this->cache->flexible(
            $key,
            self::CACHE_TTL,
            fn (): SearchResult => $this->refreshed($this->cache, $key, $query, $scale),
        );
    }

    private function refreshed(Repository $cache, string $key, Query $query, PriceScale $scale): SearchResult
    {
        try {
            return $this->screen($query, $scale);
        } catch (CatalogIndexUnavailable $outage) {
            $stale = $cache->get($key);

            if (! $stale instanceof SearchResult || $stale->degraded) {
                throw $outage;
            }

            // An outage while refreshing keeps the stale screen; stored with a fresh stamp, it waits out the fresh window.
            $this->log->warning('Search engine unavailable, stale screen kept', [
                'key' => $key,
                'error' => $outage->getMessage(),
            ]);

            return $stale;
        }
    }

    private function screen(Query $query, PriceScale $scale): SearchResult
    {
        $started = microtime(true);

        // The index keeps base prices, while users type bounds in the scale shown on cards.
        $plan = QueryPlan::forQuery($query->withPrice(new PriceRange(
            $scale->toBaseFrom($query->price->min),
            $scale->toBaseTo($query->price->max),
        )), $this->filteredBounds);

        $response = $this->index->execute($plan);

        $result = new SearchResult(
            $query,
            new ResultPage(
                $this->withDisplayPrices($response->hits, $scale),
                $response->total,
                $query->page->page,
                $query->page->perPage,
                $response->owners,
            ),
            $query->need->wantsPanel()
                ? $this->facets->panel($plan, $response, $this->dictionary, $this->order)
                : new FacetPanel,
            new PriceBounds(
                $scale->toDisplay($response->priceBounds->min),
                $scale->toDisplay($response->priceBounds->max),
            ),
        );

        $this->log->debug('Search screen built', [
            'total' => $result->page->total,
            'groups' => count($result->panel->groups),
            'intents' => count($plan->intents),
            'ms' => round((microtime(true) - $started) * 1000, 1),
        ]);

        return $result;
    }

    /**
     * @param  list<string>  $parts
     */
    private function cacheKey(Query $query, PriceScale $scale, array $parts): string
    {
        // The scale is in the key on its own, so a caller cannot forget it.
        $signature = implode('|', [$query->signature(), $scale->signature(), ...$parts]);

        // The version guards the stored shape: an entry written by other code is never read.
        return 'simple-search:screen:v1:'.hash('xxh3', $signature);
    }

    /**
     * @param  list<Hit>  $hits
     * @return list<Hit>
     */
    private function withDisplayPrices(array $hits, PriceScale $scale): array
    {
        if ($this->displayPrices === null || $scale->isIdentity()) {
            return $hits;
        }

        $scaled = [];

        foreach ($hits as $hit) {
            $scaled[] = ($this->displayPrices)($hit, $scale);
        }

        return $scaled;
    }
}
