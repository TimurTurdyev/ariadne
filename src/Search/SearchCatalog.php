<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Search;

use Closure;
use Illuminate\Cache\Repository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\DisjunctivePolicy;
use TimurTurdyev\Ariadne\Facets\FacetGroup;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetPanel;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueCount;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Facets\ValueOrder;
use TimurTurdyev\Ariadne\Index\CatalogIndex;
use TimurTurdyev\Ariadne\Index\CatalogIndexUnavailable;
use TimurTurdyev\Ariadne\Query\Constraint;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\Need;
use TimurTurdyev\Ariadne\Query\PageRequest;
use TimurTurdyev\Ariadne\Query\PriceRange;
use TimurTurdyev\Ariadne\Query\PriceScale;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Query\Scope;
use TimurTurdyev\Ariadne\Query\Selection;
use TimurTurdyev\Ariadne\Results\Hit;
use TimurTurdyev\Ariadne\Results\PriceBounds;
use TimurTurdyev\Ariadne\Results\ResultPage;

/**
 * One catalog screen: the user's query in, results with the filter panel out.
 */
final class SearchCatalog
{
    // Fresh for 300 seconds, then served stale while it refreshes, gone after 600.
    private const CACHE_TTL = [300, 600];

    // The screen is cached as a string and read back with these classes alone, whatever the store allows.
    private const SCREEN_CLASSES = [
        SearchResult::class,
        Query::class,
        Scope::class,
        Constraint::class,
        Selection::class,
        FacetChoice::class,
        FacetId::class,
        FacetValueId::class,
        FacetOperator::class,
        PriceRange::class,
        PageRequest::class,
        Need::class,
        ResultPage::class,
        Hit::class,
        FacetPanel::class,
        FacetGroup::class,
        FacetValueCount::class,
        FacetTerm::class,
        PriceBounds::class,
    ];

    /**
     * @param  Repository|null  $cache  null builds every screen anew
     * @param  ValueOrder  $order  order of values inside a panel group
     * @param  bool  $filteredBounds  count price bounds over the selection, not the bare scope
     * @param  (Closure(Hit, PriceScale): Hit)|null  $displayPrices  moves the prices a hit carries to the display scale
     * @param  bool  $paletteOnPrice  with keepZeros, values a price range alone zeroes stay dimmed; costs one more count
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
        private readonly bool $paletteOnPrice = false,
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

        $stored = $this->cache->flexible(
            $key,
            self::CACHE_TTL,
            fn (): string => $this->refreshed($this->cache, $key, $query, $scale),
        );

        return $this->unserialized($stored) ?? $this->screen($query, $scale);
    }

    private function refreshed(Repository $cache, string $key, Query $query, PriceScale $scale): string
    {
        try {
            return serialize($this->screen($query, $scale));
        } catch (CatalogIndexUnavailable $outage) {
            $stale = $this->unserialized($cache->get($key));

            if ($stale === null || $stale->degraded) {
                throw $outage;
            }

            // An outage while refreshing keeps the stale screen; stored with a fresh stamp, it waits out the fresh window.
            $this->log->warning('Search engine unavailable, stale screen kept', [
                'key' => $key,
                'error' => $outage->getMessage(),
            ]);

            return serialize($stale);
        }
    }

    private function unserialized(mixed $stored): ?SearchResult
    {
        if (! is_string($stored)) {
            return null;
        }

        // An entry that does not read back is a miss: the screen is built anew, the buyer sees no error.
        try {
            $result = @unserialize($stored, ['allowed_classes' => self::SCREEN_CLASSES]);
        } catch (Throwable) {
            return null;
        }

        return $result instanceof SearchResult ? $result : null;
    }

    private function screen(Query $query, PriceScale $scale): SearchResult
    {
        $started = microtime(true);

        // The index keeps base prices, while users type bounds in the scale shown on cards.
        $plan = QueryPlan::forQuery(
            $query->withPrice(new PriceRange(
                $scale->toBaseFrom($query->price->min),
                $scale->toBaseTo($query->price->max),
            )),
            $this->filteredBounds,
            // Hidden zeros need no palette: there the flag would only cost a count.
            $this->paletteOnPrice && $this->facets->keepsZeros(),
        );

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
        return 'ariadne:screen:v2:'.hash('xxh3', $signature);
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
