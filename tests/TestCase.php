<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TimurTurdyev\Ariadne\AriadneServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [AriadneServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('database.default', 'testing');
        $config->set('cache.default', 'array');
    }
}
