<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use LogicException;
use Throwable;
use TimurTurdyev\Ariadne\Catalog\Database\Dictionary;
use TimurTurdyev\Ariadne\Catalog\Database\Index;
use TimurTurdyev\Ariadne\Catalog\Database\RebuildOutcome;
use TimurTurdyev\Ariadne\Catalog\Database\State;
use TimurTurdyev\Ariadne\Catalog\Screen\Applied;
use TimurTurdyev\Ariadne\Catalog\Screen\Group;
use TimurTurdyev\Ariadne\Catalog\Screen\Paging;
use TimurTurdyev\Ariadne\Catalog\Screen\PriceFilter;
use TimurTurdyev\Ariadne\Catalog\Screen\SortLink;
use TimurTurdyev\Ariadne\Catalog\Screen\Value;
use TimurTurdyev\Ariadne\Facets\DisjunctivePolicy;
use TimurTurdyev\Ariadne\Facets\FacetGroup;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetPanel;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueCount;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Facets\ValueOrder;
use TimurTurdyev\Ariadne\Present\LinkBase;
use TimurTurdyev\Ariadne\Present\PanelLinks;
use TimurTurdyev\Ariadne\Query\Constraint;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\Need;
use TimurTurdyev\Ariadne\Query\PageRequest;
use TimurTurdyev\Ariadne\Query\PriceRange;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\Scope;
use TimurTurdyev\Ariadne\Query\Selection;
use TimurTurdyev\Ariadne\Results\Hit;
use TimurTurdyev\Ariadne\Results\PriceBounds;
use TimurTurdyev\Ariadne\Results\ResultPage;
use TimurTurdyev\Ariadne\Search\SearchCatalog;
use TimurTurdyev\Ariadne\Search\SearchResult;
use TimurTurdyev\Ariadne\Url\QueryStringCodec;

/**
 * One declared catalog at work: narrow it, then ask for a screen. Immutable: every narrowing is a copy.
 *
 * @template TModel of Model
 */
final readonly class Catalog
{
    /** Fresh for 300 seconds, then served stale while it refreshes, gone after 600, as in SearchCatalog. */
    private const CACHE_TTL = [300, 600];

    private const SCREEN_CLASSES = [
        SearchResult::class, Query::class, Scope::class, Constraint::class, Selection::class, FacetChoice::class,
        FacetId::class, FacetValueId::class, FacetOperator::class, PriceRange::class, PageRequest::class, Need::class,
        ResultPage::class, Hit::class, FacetPanel::class, FacetGroup::class, FacetValueCount::class, FacetTerm::class,
        PriceBounds::class,
    ];

    /**
     * @param  TModel  $model  the model of the spec, carried to type the screens
     * @param  list<Constraint>  $constraints
     * @param  list<int>|null  $ranked
     * @param  array{path: string, pins: Selection}|null  $landing
     * @param  mixed  $context  what the panel rules of the definition receive, a category as a rule
     */
    public function __construct(
        public CatalogSpec $spec,
        private Model $model,
        private Catalogs $catalogs,
        private array $constraints = [],
        private ?array $ranked = null,
        private ?array $landing = null,
        private ?int $perPage = null,
        private mixed $context = null,
    ) {}

    /**
     * Only documents whose field has one of the values: a category page, a brand page.
     *
     * @param  list<int|string>|int|string|null  $values  null - no restriction, [] - nothing
     * @return self<TModel>
     */
    public function where(string $field, array|int|string|null $values): self
    {
        if (! isset($this->spec->fields[$field])) {
            throw new LogicException("Field [{$field}] is not declared in #[Fields] of ".$this->spec->definition::class.'; declared: '.(implode(', ', array_keys($this->spec->fields)) ?: 'none').'.');
        }

        if ($values === null) {
            return $this;
        }

        $constraints = [...$this->constraints, Constraint::in($field, is_array($values) ? $values : [$values])];

        return new self($this->spec, $this->model, $this->catalogs, $constraints, $this->ranked, $this->landing, $this->perPage, $this->context);
    }

    /**
     * Only these documents, in this order by default: the ids of the application's own search.
     *
     * @param  list<int>  $ids
     * @return self<TModel>
     */
    public function ranked(array $ids): self
    {
        return new self($this->spec, $this->model, $this->catalogs, $this->constraints, array_map(intval(...), $ids), $this->landing, $this->perPage, $this->context);
    }

    /**
     * This request is a landing page of the listing at $listingPath that pins some values in its own path.
     *
     * @param  array<string, string|list<string>>  $pins  facet slug => value slugs
     * @return self<TModel>
     */
    public function landingOf(string $listingPath, array $pins): self
    {
        $choices = [];

        foreach ($pins as $facet => $values) {
            try {
                $choices[] = new FacetChoice(FacetId::fromString((string) $facet), array_map(FacetValueId::fromString(...), is_array($values) ? $values : [$values]));
            } catch (InvalidArgumentException $invalid) {
                throw new LogicException("Landing pins a value of [{$facet}] that is not a slug: {$invalid->getMessage()}", previous: $invalid);
            }
        }

        return new self($this->spec, $this->model, $this->catalogs, $this->constraints, $this->ranked, ['path' => $listingPath, 'pins' => new Selection($choices)], $this->perPage, $this->context);
    }

    /**
     * The screen belongs to this context, usually the category model: panel() of the definition gets it.
     *
     * @return self<TModel>
     */
    public function within(mixed $context): self
    {
        return new self($this->spec, $this->model, $this->catalogs, $this->constraints, $this->ranked, $this->landing, $this->perPage, $context);
    }

    /**
     * @return self<TModel>
     */
    public function perPage(int $perPage): self
    {
        if ($perPage < 1 || $perPage > 500) {
            throw new LogicException("perPage must be between 1 and 500, {$perPage} given.");
        }

        return new self($this->spec, $this->model, $this->catalogs, $this->constraints, $this->ranked, $this->landing, $perPage, $this->context);
    }

    /**
     * @param  Need  $need  Need::Results for "show more", Need::Panel for the panel alone
     * @return Screen<TModel>
     */
    public function screen(Request $request, Need $need = Need::Everything): Screen
    {
        return $this->screenFor($request->getPathInfo(), $request->query->all(), $need, $request->getRequestUri());
    }

    /**
     * @param  array<int|string, mixed>  $params  the parsed query string
     * @param  string|null  $requestUri  the address as requested, for redirect(); null - no redirect
     * @return Screen<TModel>
     */
    public function screenFor(string $path, array $params, Need $need = Need::Everything, ?string $requestUri = null): Screen
    {
        $tables = $this->catalogs->tables($this->spec->name);
        $state = $tables->state($this->spec->name);
        $dictionary = new Dictionary($this->spec, $tables, $state->generation);
        $dictionary->preloadNamed(array_keys($params));
        $codec = new QueryStringCodec($this->spec->format, $this->catalogs->logger());
        $rules = $need->wantsPanel() ? $this->rules() : new Panel;
        $query = $this->query($codec, $dictionary, $params);
        $asked = $need === Need::Everything ? $query : $query->needing($need);
        [$result, $coverage] = $this->result($asked, $dictionary, $rules, $state);
        $base = LinkBase::of($this->landing['path'] ?? $path, $params, $codec, $dictionary);

        if ($this->landing !== null) {
            $base = $base->landing($path, $this->landing['pins']);
        }

        $links = new PanelLinks($codec, $dictionary);
        $screen = $this->present($links, $codec, $dictionary, $query, $result, $coverage, $base->only(...$this->spec->carry), $rules, $need);

        if ($requestUri === null) {
            return $screen;
        }

        $wanted = $this->address($links, $query, $result, $base);

        if (self::sameAddress($requestUri, $wanted)) {
            return $screen;
        }

        return new Screen($screen->items, $screen->total, $screen->page, $screen->perPage, $screen->facets, $screen->price, $screen->sorts, $screen->applied, $screen->reset, $screen->paging, $screen->canonical, $screen->degraded, $screen->result, $wanted);
    }

    /**
     * The rules the definition gives this screen, from the config defaults.
     */
    public function rules(): Panel
    {
        $rules = new Panel(max: $this->spec->maxGroups, minCoverage: $this->spec->minCoverage);
        $definition = $this->spec->definition;

        return $definition instanceof ShapesPanel ? $definition->panel($rules, $this->context) : $rules;
    }

    /**
     * Marks documents Eloquent did not report: a query builder update, a pivot without its class, raw SQL.
     *
     * @param  int|list<int>|Builder<covariant Model>|Relation<covariant Model, covariant Model, mixed>  $documents
     */
    public function touch(int|array|Builder|Relation $documents): void
    {
        $listener = $this->catalogs->listener();

        if (is_int($documents) || is_array($documents)) {
            $listener->mark($this->spec->name, array_map(intval(...), is_int($documents) ? [$documents] : $documents));

            return;
        }

        $query = $documents instanceof Relation ? $documents->getQuery() : $documents;

        if (! $query->getModel() instanceof $this->spec->model) {
            throw new LogicException('touch() of ['.$this->spec->name.'] takes a query of '.$this->spec->model::class.', got one of '.$query->getModel()::class.'.');
        }

        $key = $this->model->getKeyName();
        $qualified = $this->model->qualifyColumn($key);
        $query->toBase()->select($qualified)->chunkById(1000, function (SupportCollection $rows) use ($listener, $key): void {
            $ids = [];

            foreach ($rows as $row) {
                $id = ((array) $row)[$key] ?? null;

                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }

            $listener->mark($this->spec->name, $ids);
        }, $qualified, $key);
    }

    /**
     * Asks for a rebuild on the next sync: after an edit that touches everything, such as a renamed filter.
     */
    public function touchAll(): void
    {
        $this->catalogs->syncer($this->spec->name)->requestRebuild();
    }

    /**
     * Applies pending edits now instead of after the response; waits up to 10 seconds for the drain lock.
     *
     * @return int documents applied
     */
    public function drain(): int
    {
        $own = $this->catalogs->listener()->take($this->spec->name);

        return $this->catalogs->syncer($this->spec->name)->drain($own, 10);
    }

    public function rebuild(bool $force = false, int $wait = 0): RebuildOutcome
    {
        $outcome = $this->catalogs->rebuilder($this->spec->name)->rebuild($force, $wait);
        // The replay took the marks of this process: a later edit of the same document must mark it again.
        $this->catalogs->listener()->take($this->spec->name);

        return $outcome;
    }

    /**
     * The buyer's query as the address states it, canonical: the default order is no sort at all.
     *
     * @param  array<int|string, mixed>  $params
     */
    private function query(QueryStringCodec $codec, Dictionary $dictionary, array $params): Query
    {
        $constraints = $this->constraints;

        if ($this->ranked !== null) {
            $constraints[] = Constraint::ranked('id', $this->ranked);
        }

        $scope = new Scope($this->ranked === null ? 'catalog' : 'search', $constraints, $this->spec->cardColumn === null ? null : 'card');
        $query = $codec->decode($params, $scope, $dictionary, $this->perPage ?? $this->spec->perPage);

        if ($this->landing !== null) {
            $selection = $query->selection;

            foreach ($this->landing['pins']->all() as $pin) {
                $selection = $selection->with($pin);
            }

            $query = $query->withSelection($selection);
        }

        // The layer runs no text search: without ranked() a q would only stick to every link.
        if ($this->ranked === null) {
            $query = $query->withText(null);
        }

        if ($this->spec->priceColumn === null) {
            $query = $query->withPrice(PriceRange::none());
        }

        if ($query->sort === $this->defaultSort() || ($this->ranked === null && $query->sort === Sort::RELEVANCE)) {
            $query = $query->withSort('');
        }

        return $query;
    }

    private function defaultSort(): string
    {
        return $this->ranked !== null ? Sort::RELEVANCE : $this->spec->defaultSort;
    }

    /**
     * The answer of the index with the panel in order; a cached one keeps hits without rows.
     *
     * @return array{0: SearchResult, 1: ?Coverage}
     */
    private function result(Query $query, Dictionary $dictionary, Panel $rules, State $state): array
    {
        $cache = $this->cache();
        $index = new Index($this->spec, $this->catalogs->tables($this->spec->name), $dictionary, rows: $cache === null);
        $early = new EarlyRules($index, $dictionary, $rules);
        $search = new SearchCatalog(
            $early,
            $dictionary,
            new DisjunctivePolicy(keepZeros: $this->spec->keepZeros, minValues: 1),
            log: $this->catalogs->logger(),
            order: ValueOrder::Dictionary,
            filteredBounds: $this->spec->filteredBounds,
        );

        if ($cache === null) {
            return [$search($query), $early->coverage()];
        }

        $key = 'ariadne:catalog:v1:'.$this->spec->name.':'.hash('xxh3', implode('|', [
            $query->signature(),
            'generation:'.$state->generation,
            'version:'.$state->version,
            'rules:'.$rules->signature(),
        ]));
        $fresh = null;
        $stored = $cache->flexible($key, self::CACHE_TTL, function () use ($search, $query, $early, &$fresh): string {
            $fresh = [$search($query), $early->coverage()];

            return serialize([
                'result' => self::withoutRows($fresh[0]),
                'covered' => $fresh[1]?->covered,
                'total' => $fresh[1]?->total,
            ]);
        });

        if (is_array($fresh)) {
            return $fresh;
        }

        return $this->unserialized($stored) ?? [$search($query), $early->coverage()];
    }

    /**
     * @return array{0: SearchResult, 1: ?Coverage}|null
     */
    private function unserialized(mixed $stored): ?array
    {
        if (! is_string($stored)) {
            return null;
        }

        try {
            $payload = @unserialize($stored, ['allowed_classes' => self::SCREEN_CLASSES]);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ! ($payload['result'] ?? null) instanceof SearchResult) {
            return null;
        }

        $covered = [];

        foreach (is_array($payload['covered'] ?? null) ? $payload['covered'] : [] as $group => $documents) {
            if (is_string($group) && is_int($documents)) {
                $covered[$group] = $documents;
            }
        }

        $total = $payload['total'] ?? null;
        $coverage = is_array($payload['covered'] ?? null) ? new Coverage($covered, is_int($total) ? $total : null) : null;

        return [$payload['result'], $coverage];
    }

    private static function withoutRows(SearchResult $result): SearchResult
    {
        $page = $result->page;
        $hits = array_map(static fn (Hit $hit): Hit => new Hit($hit->id), $page->hits);

        return new SearchResult($result->query, new ResultPage($hits, $page->total, $page->page, $page->perPage, $page->owners), $result->panel, $result->priceBounds, $result->degraded);
    }

    private function cache(): ?Repository
    {
        return $this->spec->cache === null ? null : $this->catalogs->cacheStore($this->spec->cache);
    }

    /**
     * @return Screen<TModel>
     */
    private function present(PanelLinks $links, QueryStringCodec $codec, Dictionary $dictionary, Query $query, SearchResult $result, ?Coverage $coverage, LinkBase $base, Panel $rules, Need $need): Screen
    {
        $groups = $this->shownGroups($result, $dictionary, $rules, $need);
        $panel = $links->panel(
            new SearchResult($result->query, $result->page, new FacetPanel($groups), $result->priceBounds, $result->degraded),
            $base,
            $query,
        );

        $facets = [];
        $applied = [];

        foreach ($panel['facets'] as $at => $group) {
            $info = $dictionary->group($group['facet']);
            $values = [];

            foreach ($group['values'] as $value) {
                $values[] = new Value($value['value'], $value['label'], $value['image'], $value['count'], $value['selected'], $value['disabled'], $value['href'], $value['only']);

                if ($value['selected']) {
                    $applied[] = new Applied($group['facet'], $group['label'], $value['label'], $value['href']);
                }
            }

            $facets[] = new Group(
                $group['facet'],
                $codec->param($groups[$at]->facet),
                $group['label'],
                $info->widget ?? Facet::LIST,
                $info->multiple ?? true,
                $group['operator'],
                $group['selected'],
                $group['reset'],
                $group['operatorHref'],
                $coverage?->share($group['facet']),
                $values,
            );
        }

        $price = null;

        if ($this->spec->priceColumn !== null) {
            $slider = $panel['price'];
            $price = new PriceFilter($slider['bounds'], $slider['selected'], $slider['input'], $slider['template'], $slider['reset']);

            if (! $query->price->isEmpty()) {
                $applied[] = new Applied('price', '', $slider['input']['min'].'-'.$slider['input']['max'], $slider['reset']);
            }
        }

        $paging = new Paging($panel['paging']['first'], $panel['paging']['prev'], $panel['paging']['next'], $panel['paging']['template']);
        $page = $result->page->page;

        return new Screen(
            items: $need->wantsDocuments() ? $this->items($result) : $this->model->newCollection(),
            total: $result->page->total,
            page: $page,
            perPage: $result->page->perPage,
            facets: $facets,
            price: $price,
            sorts: $this->sorts($links, $query, $base),
            applied: $applied,
            reset: $panel['reset'],
            paging: $paging,
            canonical: $page === 1 ? $paging->first : str_replace('{page}', (string) $page, $paging->template),
            degraded: $result->degraded,
            result: $result,
        );
    }

    /**
     * Groups of the policy in their order, without those short of min_values, then cut to max; a selected one always stays.
     *
     * @return list<FacetGroup>
     */
    private function shownGroups(SearchResult $result, Dictionary $dictionary, Panel $rules, Need $need): array
    {
        if (! $need->wantsPanel()) {
            return [];
        }

        $kept = [];

        foreach ($result->panel->groups as $group) {
            $info = $dictionary->group($group->facet->value);
            $selected = $group->selectedCount() > 0;

            if ($info === null || (! $selected && ! $info->isToggle() && count($group->values) < $this->spec->minValues)) {
                continue;
            }

            $kept[] = $group;
        }

        if ($rules->max === null || count($kept) <= $rules->max) {
            return $kept;
        }

        $head = array_slice($kept, 0, $rules->max);

        foreach (array_slice($kept, $rules->max) as $cut) {
            if ($cut->selectedCount() > 0) {
                $head[] = $cut;
            }
        }

        return $head;
    }

    /**
     * @return list<SortLink>
     */
    private function sorts(PanelLinks $links, Query $query, LinkBase $base): array
    {
        $default = $this->defaultSort();
        $current = $query->sort === '' ? $default : $query->sort;
        $sorts = [];

        foreach ($this->spec->sorts as $sort) {
            if ($sort->relevance && $this->ranked === null) {
                continue;
            }

            $sorts[] = new SortLink(
                $sort->slug,
                $sort->label,
                $links->href($query->withSort($sort->slug === $default ? '' : $sort->slug), $base),
                $current === $sort->slug,
            );
        }

        return $sorts;
    }

    /**
     * One address in substance: the same path, the same parameters in any order, the values of a group in any order,
     * page 1 the same as none. A paginator puts the page after the foreign parameters, a filter form sends values
     * in panel order: neither is worth a redirect, the canonical link tells search engines the rest.
     */
    private static function sameAddress(string $requested, string $canonical): bool
    {
        $read = static function (string $address): array {
            $path = rawurldecode((string) parse_url($address, PHP_URL_PATH));
            parse_str((string) parse_url($address, PHP_URL_QUERY), $params);

            if (($params['page'] ?? null) === '1') {
                unset($params['page']);
            }

            foreach ($params as $name => $value) {
                $params[$name] = self::valueSet($value);
            }

            ksort($params);

            return [$path, $params];
        };

        return $read($requested) === $read($canonical);
    }

    /**
     * A parameter value as an ordered set: key[]=b&key[]=a and key=b,a read like key=a,b; the "all:" mark stays.
     */
    private static function valueSet(mixed $value): mixed
    {
        if (is_array($value)) {
            $values = array_values(array_unique(array_map(static fn (mixed $one): string => is_scalar($one) ? (string) $one : '', $value)));
            sort($values, SORT_STRING);

            return $values;
        }

        if (! is_string($value) || ! str_contains($value, QueryStringCodec::VALUE_SEPARATOR)) {
            return $value;
        }

        $all = str_starts_with($value, QueryStringCodec::OPERATOR_ALL_PREFIX);
        $values = array_values(array_unique(explode(QueryStringCodec::VALUE_SEPARATOR, $all ? substr($value, strlen(QueryStringCodec::OPERATOR_ALL_PREFIX)) : $value)));
        sort($values, SORT_STRING);

        return ($all ? QueryStringCodec::OPERATOR_ALL_PREFIX : '').implode(QueryStringCodec::VALUE_SEPARATOR, $values);
    }

    /**
     * The canonical address of this page with every foreign parameter of the request kept.
     */
    private function address(PanelLinks $links, Query $query, SearchResult $result, LinkBase $base): string
    {
        $paging = $links->panel(new SearchResult($result->query, $result->page, new FacetPanel, $result->priceBounds, $result->degraded), $base, $query)['paging'];

        return $result->page->page === 1 ? $paging['first'] : str_replace('{page}', (string) $result->page->page, $paging['template']);
    }

    /**
     * Models from the rows the index read, or one read by keys when the screen came from the cache; missing ones drop out.
     *
     * @return Collection<int, TModel>
     */
    private function items(SearchResult $result): Collection
    {
        $model = $this->model;
        $hits = $result->page->hits;

        if ($hits === []) {
            return $model->newCollection();
        }

        $documents = $this->spec->definition->documents();

        if ($hits[0]->attributes !== []) {
            $items = [];

            foreach ($hits as $hit) {
                $items[] = $model->newFromBuilder($hit->attributes);
            }

            // The relations documents() loads for cards, on models made from the rows the index read.
            return $model->newCollection($documents->eagerLoadRelations($items));
        }

        $found = [];

        foreach ($documents->whereKey(array_map(static fn (Hit $hit): int => $hit->id, $hits))->get() as $item) {
            $key = $item->getKey();

            if (is_int($key) || is_string($key)) {
                $found[$key] = $item;
            }
        }

        $items = [];

        foreach ($hits as $hit) {
            if (isset($found[$hit->id])) {
                $items[] = $found[$hit->id];
            }
        }

        return $model->newCollection($items);
    }
}
