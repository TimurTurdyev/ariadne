<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Url;

use InvalidArgumentException;
use TimurTurdyev\SimpleSearch\Facets\KeyStyle;

/**
 * How an application writes its catalog query string: style, price unit and accepted sort slugs.
 */
final readonly class UrlFormat
{
    /**
     * @param  int  $priceUnit  base units in one unit typed in the address: 100 when prices are kept in cents
     * @param  list<string>  $sorts  sort slugs besides ''
     */
    public function __construct(
        public UrlStyle $style = UrlStyle::Comma,
        public int $priceUnit = 1,
        public array $sorts = [],
    ) {
        if ($priceUnit < 1) {
            throw new InvalidArgumentException("Price unit must be a positive integer, {$priceUnit} given.");
        }
    }

    /**
     * @param  array<string, mixed>  $config  style, price_unit, sorts
     */
    public static function fromConfig(array $config): self
    {
        $style = $config['style'] ?? null;
        $unit = $config['price_unit'] ?? null;
        $sorts = $config['sorts'] ?? null;

        return new self(
            // A misspelled style fails loudly: falling back would silently change every address.
            is_string($style) ? UrlStyle::from($style) : UrlStyle::Comma,
            is_numeric($unit) ? (int) $unit : 1,
            is_array($sorts) ? array_values(array_filter($sorts, is_string(...))) : [],
        );
    }

    /**
     * How a facet slug is spelled as a parameter name.
     */
    public function keys(): KeyStyle
    {
        return match ($this->style) {
            UrlStyle::Comma => KeyStyle::Kebab,
            UrlStyle::Brackets => KeyStyle::Snake,
        };
    }

    /**
     * Names of the price parameters: one "min-max" pair, or the lower and the upper bound.
     *
     * @return list<string>
     */
    public function priceParams(): array
    {
        return match ($this->style) {
            UrlStyle::Comma => [QueryStringCodec::PARAM_PRICE],
            UrlStyle::Brackets => ['price_min', 'price_max'],
        };
    }

    /**
     * Whether the address can say "all of the values at once".
     */
    public function supportsAll(): bool
    {
        return $this->style === UrlStyle::Comma;
    }
}
