<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TimurTurdyev\Ariadne\Catalog\Catalogs;

/**
 * Runs with "php artisan optimize": a broken definition stops the deploy instead of the first buyer.
 */
#[AsCommand(name: 'ariadne:optimize')]
final class OptimizeCommand extends Command
{
    protected $signature = 'ariadne:optimize';

    protected $description = 'Compile every declared catalog without the database; fails on the first broken one';

    protected $hidden = true;

    public function handle(Catalogs $catalogs): int
    {
        $catalogs->specs();

        return self::SUCCESS;
    }
}
