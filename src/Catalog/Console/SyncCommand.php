<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TimurTurdyev\Ariadne\Catalog\Catalogs;

/**
 * The minute pass: marks a drain missed, a requested rebuild, the previous generation after its grace.
 */
#[AsCommand(name: 'ariadne:sync')]
final class SyncCommand extends Command
{
    protected $signature = 'ariadne:sync {catalog?* : catalog keys; every declared catalog by default}';

    protected $description = 'Apply pending edits, run a requested rebuild and drop old generations';

    public function handle(Catalogs $catalogs): int
    {
        $names = $this->argument('catalog');
        $names = is_array($names) && $names !== [] ? array_values(array_filter($names, is_string(...))) : $catalogs->names();

        foreach ($names as $name) {
            $catalog = $catalogs->get($name);

            foreach ($catalogs->syncer($name)->sync(static fn () => $catalog->rebuild()) as $done) {
                $this->components->info("{$name}: {$done}");
            }
        }

        return self::SUCCESS;
    }
}
