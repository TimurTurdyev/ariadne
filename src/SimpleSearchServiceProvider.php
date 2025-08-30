<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use TimurTurdyev\SimpleSearch\Dictionary\FacetDictionary;
use TimurTurdyev\SimpleSearch\Facets\DisjunctivePolicy;
use TimurTurdyev\SimpleSearch\Facets\ValueOrder;
use TimurTurdyev\SimpleSearch\Index\CatalogIndex;
use TimurTurdyev\SimpleSearch\Manticore\Connection;
use TimurTurdyev\SimpleSearch\Present\PanelLinks;
use TimurTurdyev\SimpleSearch\Search\SearchCatalog;
use TimurTurdyev\SimpleSearch\Url\QueryStringCodec;
use TimurTurdyev\SimpleSearch\Url\UrlFormat;

/**
 * Wires the package from its config; a class the application binds itself always wins.
 */
final class SimpleSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/simple-search.php', 'simple-search');

        $this->app->singletonIf(Connection::class, static fn (Container $app): Connection => Connection::fromConfig(
            self::settings($app, 'manticore'),
        ));

        $this->app->singletonIf(UrlFormat::class, static fn (Container $app): UrlFormat => UrlFormat::fromConfig(
            self::settings($app, 'url'),
        ));

        $this->app->singletonIf(QueryStringCodec::class, static fn (Container $app): QueryStringCodec => new QueryStringCodec(
            $app->make(UrlFormat::class),
            $app->make(LoggerInterface::class),
        ));

        $this->app->singletonIf(DisjunctivePolicy::class, static function (Container $app): DisjunctivePolicy {
            $screen = self::settings($app, 'screen');
            $minValues = $screen['min_values'] ?? null;

            return new DisjunctivePolicy(
                keepZeros: (bool) ($screen['keep_zeros'] ?? true),
                minValues: is_numeric($minValues) ? (int) $minValues : 1,
            );
        });

        // Scoped: both hold the dictionary, and a worker must not live with the one of its first request.
        $this->app->scopedIf(SearchCatalog::class, static function (Container $app): SearchCatalog {
            $screen = self::settings($app, 'screen');
            $order = $screen['order'] ?? null;

            return new SearchCatalog(
                $app->make(CatalogIndex::class),
                $app->make(FacetDictionary::class),
                $app->make(DisjunctivePolicy::class),
                log: $app->make(LoggerInterface::class),
                // A misspelled order fails loudly: falling back would silently reorder every panel.
                order: is_string($order) ? ValueOrder::from($order) : ValueOrder::Natural,
                filteredBounds: (bool) ($screen['filtered_bounds'] ?? false),
            );
        });

        $this->app->scopedIf(PanelLinks::class, static fn (Container $app): PanelLinks => new PanelLinks(
            $app->make(QueryStringCodec::class),
            $app->make(FacetDictionary::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/simple-search.php' => $this->app->configPath('simple-search.php'),
        ], 'simple-search-config');
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(Container $app, string $section): array
    {
        $settings = $app->make(Repository::class)->get('simple-search.'.$section);

        return is_array($settings) ? array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }
}
