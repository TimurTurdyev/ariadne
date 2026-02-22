<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Url;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\PageRequest;
use TimurTurdyev\Ariadne\Query\PriceRange;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\Scope;
use TimurTurdyev\Ariadne\Query\Selection;

/**
 * Translates between a query string and a query; an unknown facet or value is dropped silently and logged.
 */
final class QueryStringCodec
{
    public const PARAM_TEXT = 'q';

    public const PARAM_PRICE = 'price';

    public const PARAM_SORT = 'sort';

    public const PARAM_PAGE = 'page';

    public const OPERATOR_ALL_PREFIX = 'all:';

    public const VALUE_SEPARATOR = ',';

    /** The largest price bound the address may carry, in its own units. */
    public const MAX_AMOUNT = 1.0e12;

    private const RANGE_SEPARATOR = '-';

    private const LIST_SUFFIX = '[]';

    public function __construct(
        private readonly UrlFormat $format,
        private readonly LoggerInterface $log = new NullLogger,
    ) {}

    /**
     * @param  array<int|string, mixed>  $params  parsed query string; a numeric name arrives as an integer key
     */
    public function decode(
        array $params,
        Scope $scope,
        FacetDictionary $dictionary,
        int $perPage = PageRequest::DEFAULT_PER_PAGE,
    ): Query {
        return new Query(
            $scope,
            new Selection($this->decodeFacets($params, $dictionary)),
            $this->decodePrice($params),
            $this->decodeSort($this->stringParam($params, self::PARAM_SORT)),
            new PageRequest($this->decodePage($this->stringParam($params, self::PARAM_PAGE)), $perPage),
            $this->stringParam($params, self::PARAM_TEXT),
        );
    }

    /**
     * The canonical query string: fixed order, defaults left out.
     */
    public function encode(Query $query): string
    {
        return implode('&', $this->pairs($query));
    }

    /**
     * The canonical query string with {min}, {max} and {page} left for the browser to fill.
     */
    public function template(Query $query, bool $price = false, bool $page = false): string
    {
        return implode('&', $this->pairs($query, $price, $page));
    }

    /**
     * Parameters the catalog does not know and carries on as they are, such as campaign tags.
     *
     * @param  array<int|string, mixed>  $params
     * @return array<string, string|list<string>>
     */
    public function foreign(array $params, FacetDictionary $dictionary): array
    {
        $foreign = [];

        foreach ($params as $key => $value) {
            if (! is_string($key) || $this->isOwnParam($key) || $this->isKnownFacet($key, $dictionary)) {
                continue;
            }

            $carried = $this->carried($value);

            if ($carried !== null) {
                $foreign[$key] = $carried;
            }
        }

        return $foreign;
    }

    /**
     * Parameters in the order encode() writes, foreign last; a facet keeps every value that can be a slug, known or not.
     *
     * @param  array<int|string, mixed>  $params
     * @return array<int|string, mixed>
     */
    public function canonical(array $params, FacetDictionary $dictionary): array
    {
        $facets = [];
        $foreign = [];

        foreach ($params as $key => $value) {
            if (is_string($key) && $this->isOwnParam($key)) {
                continue;
            }

            $choice = is_string($key) && $this->isKnownFacet($key, $dictionary) ? $this->rawChoice($value) : null;

            if ($choice === null) {
                $foreign[$key] = $value;

                continue;
            }

            // Rebuilt from the parsed pair, so a value that looks like "all:" cannot become the operator.
            $slugs = $this->slugs($choice[1]);

            if ($slugs !== []) {
                $facets[$key] = $this->choiceValue($choice[0], $slugs);
            }
        }

        ksort($facets, SORT_STRING);

        $out = array_key_exists(self::PARAM_TEXT, $params) ? [self::PARAM_TEXT => $params[self::PARAM_TEXT]] : [];
        $out += $facets;

        foreach ([...$this->format->priceParams(), self::PARAM_SORT, self::PARAM_PAGE] as $key) {
            if (array_key_exists($key, $params)) {
                $out[$key] = $params[$key];
            }
        }

        return $out + $foreign;
    }

    /**
     * A query string from ready parameters of this format, encoded as encode() does.
     *
     * @param  array<int|string, mixed>  $params
     */
    public function queryString(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $carried = $this->carried($value);

            if ($carried !== null) {
                array_push($pairs, ...$this->paramPairs($key, $carried));
            }
        }

        return implode('&', $pairs);
    }

    public function param(FacetId $facet): string
    {
        return $this->format->keys()->name($facet);
    }

    /**
     * A base amount as the address writes it: in price units, without trailing zeros.
     */
    public function amount(float $base): string
    {
        return rtrim(rtrim(number_format($base / $this->format->priceUnit, 2, '.', ''), '0'), '.');
    }

    /**
     * Whether a facet with this slug could not be read from the address, for slug generators.
     */
    public function isReserved(string $slug): bool
    {
        // PHP parses an all-digit name into an integer key, and integer keys are never read.
        if (ctype_digit($slug)) {
            return true;
        }

        try {
            $name = $this->param(FacetId::fromString($slug));
        } catch (InvalidArgumentException) {
            $name = $slug;
        }

        return $this->isOwnParam($name);
    }

    /**
     * Whether the address can say "all of the values at once".
     */
    public function supportsAll(): bool
    {
        return $this->format->supportsAll();
    }

    /**
     * @return list<string>
     */
    private function pairs(Query $query, bool $price = false, bool $page = false): array
    {
        $pairs = [];

        if ($query->text !== null) {
            $pairs[] = $this->pair(self::PARAM_TEXT, $query->text);
        }

        // The address goes by parameter names, and a name may sort differently from its slug.
        $choices = $query->selection->all();
        usort($choices, fn (FacetChoice $a, FacetChoice $b): int => strcmp($this->param($a->facet), $this->param($b->facet)));

        foreach ($choices as $choice) {
            $slugs = $choice->valueSlugs();
            sort($slugs, SORT_STRING);
            $operator = $choice->isCombined() ? FacetOperator::All : FacetOperator::Any;

            array_push($pairs, ...$this->paramPairs($this->param($choice->facet), $this->choiceValue($operator, $slugs)));
        }

        $bounds = $price
            ? $this->priceValues('{min}', '{max}')
            : $this->priceValues($this->encodeAmount($query->price->min), $this->encodeAmount($query->price->max));

        foreach ($bounds as $name => $value) {
            $pairs[] = $price ? $name.'='.$value : $this->pair($name, $value);
        }

        if ($query->sort !== '') {
            $pairs[] = $this->pair(self::PARAM_SORT, $query->sort);
        }

        if ($page) {
            $pairs[] = self::PARAM_PAGE.'={page}';
        } elseif (! $query->page->isFirst()) {
            $pairs[] = $this->pair(self::PARAM_PAGE, (string) $query->page->page);
        }

        return $pairs;
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @return list<FacetChoice>
     */
    private function decodeFacets(array $params, FacetDictionary $dictionary): array
    {
        $choices = [];

        foreach ($params as $key => $raw) {
            if (! is_string($key) || $this->isOwnParam($key)) {
                continue;
            }

            $read = $this->rawChoice($raw);

            if ($read === null) {
                continue;
            }

            $choice = $this->decodeChoice($key, $read[0], $read[1], $dictionary);

            if ($choice !== null) {
                $choices[] = $choice;
            }
        }

        return $choices;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function decodeChoice(string $key, FacetOperator $operator, array $slugs, FacetDictionary $dictionary): ?FacetChoice
    {
        $facet = $this->format->keys()->facet($key);

        if ($facet === null) {
            $this->logDropped('facet key is not a slug', ['key' => $key]);

            return null;
        }

        $known = $this->knownValues($facet, $dictionary);

        if ($known === []) {
            $this->logDropped('facet is unknown to the dictionary', ['facet' => $facet->value]);

            return null;
        }

        // "All" finds nothing where a document carries one value, so it reads as a plain union.
        if ($operator === FacetOperator::All && ! $dictionary->isMultiValued($facet)) {
            $this->log->debug('All operator normalized to any: facet is single-valued', ['facet' => $facet->value]);
            $operator = FacetOperator::Any;
        }

        $values = [];

        foreach ($slugs as $slug) {
            $slug = trim($slug);

            if ($slug === '') {
                continue;
            }

            if (! isset($known[$slug])) {
                $this->logDropped('facet value is unknown', ['facet' => $facet->value, 'value' => $slug]);

                continue;
            }

            $values[] = $known[$slug];
        }

        if ($values === []) {
            return null;
        }

        return new FacetChoice($facet, $values, $operator);
    }

    /**
     * Comma reads "all:a,b"; brackets read key[]=a, key[0]=a and key=a alike.
     *
     * @return array{0: FacetOperator, 1: list<string>}|null null - the style does not read such a value
     */
    private function rawChoice(mixed $raw): ?array
    {
        if ($this->format->style === UrlStyle::Brackets) {
            $slugs = $this->strings($raw);

            return $slugs === null ? null : [FacetOperator::Any, $slugs];
        }

        if (! is_string($raw)) {
            return null;
        }

        $operator = str_starts_with($raw, self::OPERATOR_ALL_PREFIX) ? FacetOperator::All : FacetOperator::Any;
        $body = $operator === FacetOperator::All ? substr($raw, strlen(self::OPERATOR_ALL_PREFIX)) : $raw;

        return [$operator, explode(self::VALUE_SEPARATOR, $body)];
    }

    /**
     * @param  list<string>  $slugs
     * @return string|list<string>
     */
    private function choiceValue(FacetOperator $operator, array $slugs): string|array
    {
        if ($this->format->style === UrlStyle::Brackets) {
            return $slugs;
        }

        $prefix = $operator === FacetOperator::All ? self::OPERATOR_ALL_PREFIX : '';

        return $prefix.implode(self::VALUE_SEPARATOR, $slugs);
    }

    /**
     * @param  list<string>  $raw
     * @return list<string> distinct slugs in alphabetical order
     */
    private function slugs(array $raw): array
    {
        $slugs = [];

        foreach ($raw as $one) {
            try {
                $slugs[] = FacetValueId::fromString($one)->value;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @return array<string, FacetValueId>
     */
    private function knownValues(FacetId $facet, FacetDictionary $dictionary): array
    {
        $known = [];

        foreach ($dictionary->termsFor($facet) as $term) {
            $known[$term->value->value] = $term->value;
        }

        return $known;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private function decodePrice(array $params): PriceRange
    {
        if ($this->format->style === UrlStyle::Brackets) {
            [$min, $max] = $this->format->priceParams();

            return new PriceRange(
                $this->decodeAmount($this->stringParam($params, $min) ?? ''),
                $this->decodeAmount($this->stringParam($params, $max) ?? ''),
            );
        }

        $raw = $this->stringParam($params, self::PARAM_PRICE);

        if ($raw === null || ! str_contains($raw, self::RANGE_SEPARATOR)) {
            return PriceRange::none();
        }

        [$min, $max] = explode(self::RANGE_SEPARATOR, $raw, 2);

        return new PriceRange($this->decodeAmount($min), $this->decodeAmount($max));
    }

    private function decodeAmount(string $raw): ?float
    {
        $raw = trim($raw);

        if (! is_numeric($raw)) {
            return null;
        }

        // Two decimals, as amount() writes them: a finer bound would change on the next link.
        $amount = round((float) $raw, 2);

        return is_finite($amount) && $amount <= self::MAX_AMOUNT ? $amount * $this->format->priceUnit : null;
    }

    private function encodeAmount(?float $amount): string
    {
        return $amount === null ? '' : $this->amount($amount);
    }

    /**
     * @return array<string, string> an empty bound is left out
     */
    private function priceValues(string $min, string $max): array
    {
        if ($this->format->style === UrlStyle::Brackets) {
            [$lower, $upper] = $this->format->priceParams();

            return array_filter([$lower => $min, $upper => $max], static fn (string $bound): bool => $bound !== '');
        }

        return $min === '' && $max === '' ? [] : [self::PARAM_PRICE => $min.self::RANGE_SEPARATOR.$max];
    }

    private function decodeSort(?string $raw): string
    {
        return $raw !== null && in_array($raw, $this->format->sorts, true) ? $raw : '';
    }

    private function decodePage(?string $raw): int
    {
        return $raw !== null && ctype_digit($raw) ? (int) $raw : 1;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private function stringParam(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function isOwnParam(string $name): bool
    {
        return in_array($name, [self::PARAM_TEXT, ...$this->format->priceParams(), self::PARAM_SORT, self::PARAM_PAGE], true);
    }

    private function isKnownFacet(string $key, FacetDictionary $dictionary): bool
    {
        $facet = $this->format->keys()->facet($key);

        return $facet !== null && $dictionary->termsFor($facet) !== [];
    }

    /**
     * @return list<string>|null null - neither a string nor an array; a nested array is skipped
     */
    private function strings(mixed $raw): ?array
    {
        if (is_string($raw)) {
            return [$raw];
        }

        return is_array($raw) ? array_values(array_filter($raw, is_string(...))) : null;
    }

    /**
     * @return string|list<string>|null null - not carried; comma has no lists, so an array there is dropped
     */
    private function carried(mixed $value): string|array|null
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        if ($this->format->style !== UrlStyle::Brackets) {
            return null;
        }

        $strings = $this->strings($value);

        return $strings === null || $strings === [] ? null : $strings;
    }

    /**
     * @param  string|list<string>  $value
     * @return list<string>
     */
    private function paramPairs(string $key, string|array $value): array
    {
        if (is_string($value)) {
            return [$this->pair($key, $value)];
        }

        return array_map(fn (string $one): string => $this->pair($key.self::LIST_SUFFIX, $one), $value);
    }

    private function pair(string $key, string $value): string
    {
        // Comma and colon are legal in a query string and carry the format, so they stay readable.
        $encoded = str_replace(['%2C', '%3A'], [self::VALUE_SEPARATOR, ':'], rawurlencode($value));

        return rawurlencode($key).'='.$encoded;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logDropped(string $reason, array $context): void
    {
        $this->log->debug('Query parameter dropped: '.$reason, $context);
    }
}
