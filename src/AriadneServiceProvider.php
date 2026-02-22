<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\DisjunctivePolicy;
use TimurTurdyev\Ariadne\Facets\ValueOrder;
use TimurTurdyev\Ariadne\Index\CatalogIndex;
use TimurTurdyev\Ariadne\Manticore\Connection;
use TimurTurdyev\Ariadne\Present\PanelLinks;
use TimurTurdyev\Ariadne\Search\SearchCatalog;
use TimurTurdyev\Ariadne\Url\QueryStringCodec;
use TimurTurdyev\Ariadne\Url\UrlFormat;

/**
 * Wires the package from its config; a class the application binds itself always wins.
 */
final class AriadneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ariadne.php', 'ariadne');

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
                paletteOnPrice: (bool) ($screen['palette_on_price'] ?? false),
            );
        });

        $this->app->scopedIf(PanelLinks::class, static fn (Container $app): PanelLinks => new PanelLinks(
            $app->make(QueryStringCodec::class),
            $app->make(FacetDictionary::class),
        ));
    }

    public function boot(): void
    {
        // Left as it was, the old file would be ignored and the package would quietly run on defaults.
        if (
            $this->app->runningInConsole()
            && file_exists($this->app->configPath('simple-search.php'))
            && ! file_exists($this->app->configPath('ariadne.php'))
        ) {
            throw new RuntimeException('Rename config/simple-search.php to config/ariadne.php: the package no longer reads the old file.');
        }

        $this->publishes([
            __DIR__.'/../config/ariadne.php' => $this->app->configPath('ariadne.php'),
        ], 'ariadne-config');
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(Container $app, string $section): array
    {
        $settings = $app->make(Repository::class)->get('ariadne.'.$section);

        return is_array($settings) ? array_filter($settings, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }
}
