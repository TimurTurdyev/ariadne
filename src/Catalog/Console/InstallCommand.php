<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\MigrationCreator;
use Illuminate\Filesystem\Filesystem;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TimurTurdyev\Ariadne\Catalog\Catalogs;
use TimurTurdyev\Ariadne\Catalog\InvalidCatalog;

/**
 * Writes the config, the migration of the facet tables and a definition into the application.
 */
#[AsCommand(name: 'ariadne:install')]
final class InstallCommand extends Command
{
    protected $signature = 'ariadne:install
        {--catalog= : key of a new catalog: it names its tables}
        {--model= : model class the new catalog lists}
        {--upgrade : write only the migrations of later releases}';

    protected $description = 'Write the Ariadne config, the facet tables migration and a catalog definition';

    public function handle(Filesystem $files, Catalogs $catalogs, DatabaseManager $db, Repository $config): int
    {
        $catalog = $this->option('catalog');
        $model = $this->option('model');
        $catalog = is_string($catalog) ? $catalog : null;

        if ($catalog !== null && preg_match('/^[a-z][a-z0-9_]{0,23}$/', $catalog) !== 1) {
            $this->components->error('--catalog names tables: a letter, then up to 23 lowercase letters, digits and _.');

            return self::FAILURE;
        }

        $instance = is_string($model) && is_subclass_of($model, Model::class) ? new $model : null;

        if ($catalog !== null && $instance === null) {
            $this->components->error('--catalog needs --model with the class of an Eloquent model.');

            return self::FAILURE;
        }

        $definition = $catalog !== null ? $this->definitionClass($instance) : null;

        $this->config($files, $catalog, $definition);

        $connection = $instance !== null ? $instance->getConnectionName() : $this->connection($catalogs);

        if ($this->option('upgrade') === true) {
            $this->components->info('No migrations of later releases yet: the tables of this release are current.');
        } elseif (! $this->migration($files, $catalogs->prefix(), $connection, $db, $config)) {
            return self::FAILURE;
        }

        if ($catalog !== null && $definition !== null) {
            $this->definition($files, $catalog, $instance, $definition);
        }

        $this->components->bulletList([
            'php artisan migrate',
            is_string($catalog) ? "php artisan ariadne:rebuild {$catalog}" : 'php artisan ariadne:rebuild',
            'php artisan ariadne:check',
            'schedule: ariadne:sync every minute, ariadne:rebuild nightly (see the README)',
        ]);

        return self::SUCCESS;
    }

    private function config(Filesystem $files, ?string $catalog, ?string $definition): void
    {
        $line = $catalog === null || $definition === null
            ? null
            : "'{$catalog}' => ['definition' => \\{$definition}::class, 'min_coverage' => 0.05],";
        $path = $this->laravel->configPath('ariadne.php');

        if ($files->exists($path)) {
            if ($line !== null) {
                $this->components->info('config/ariadne.php exists; add the catalog to its "catalogs" section:');
                $this->line('    '.$line);
            }

            return;
        }

        $config = $files->get(__DIR__.'/../../../config/ariadne.php');

        if ($line !== null) {
            $config = str_replace("'catalogs' => [\n    ],", "'catalogs' => [\n        {$line}\n    ],", $config);
        }

        $files->put($path, $config);
        $this->components->info('Config written: config/ariadne.php.');
    }

    private function migration(Filesystem $files, string $prefix, ?string $connection, DatabaseManager $db, Repository $config): bool
    {
        $name = 'create_'.$prefix.'tables';
        $directory = $this->laravel->databasePath('migrations');

        if ($files->glob($directory.'/*_'.$name.'.php') !== []) {
            $this->components->error("A migration *_{$name}.php exists already: the facet tables are installed.");

            return false;
        }

        $refusal = $this->installedInDatabase($db, $config, $connection, $name, $prefix);

        if ($refusal !== null) {
            $this->components->error($refusal);

            return false;
        }

        $creator = $this->laravel->make('migration.creator');

        if (! $creator instanceof MigrationCreator) {
            throw new LogicException('The migration.creator binding is not a migration creator.');
        }

        $path = $creator->create($name, $directory);
        $stub = $files->get(__DIR__.'/../../../stubs/create_facet_tables.php.stub');
        $files->put($path, str_replace(
            ['{{connection}}', '{{catalogs}}', '{{groups}}', '{{values}}', '{{changes}}', '{{rows}}'],
            [
                $connection === null ? '' : "\n    protected \$connection = '{$connection}';\n",
                $prefix.'catalogs',
                $prefix.'groups',
                $prefix.'values',
                $prefix.'changes',
                $prefix.'rows_',
            ],
            $stub,
        ));

        $this->components->info('Migration written: '.basename($path).'.');

        return true;
    }

    /**
     * Why the tables count as installed by the database, or null; a database that cannot be reached is not asked.
     */
    private function installedInDatabase(DatabaseManager $db, Repository $config, ?string $connection, string $name, string $prefix): ?string
    {
        try {
            $schema = $db->connection($connection)->getSchemaBuilder();

            if ($schema->hasTable($prefix.'catalogs')) {
                return "Table {$prefix}catalogs exists already: the facet tables are installed.";
            }

            $migrations = $config->get('database.migrations.table', 'migrations');
            $table = is_array($migrations) ? ($migrations['table'] ?? 'migrations') : $migrations;
            $table = is_string($table) ? $table : 'migrations';

            if ($schema->hasTable($table) && $db->connection($connection)->table($table)->where('migration', 'like', '%_'.$name)->exists()) {
                return "The migrations table records *_{$name} although its file is gone (schema:dump --prune?): the facet tables are installed.";
            }
        } catch (Throwable $unreachable) {
            $this->components->warn('The database was not asked whether the tables exist: '.$unreachable->getMessage());
        }

        return null;
    }

    private function connection(Catalogs $catalogs): ?string
    {
        try {
            $specs = $catalogs->specs();
        } catch (InvalidCatalog) {
            return null;
        }

        return $specs === [] ? null : $specs[0]->connection;
    }

    private function definitionClass(Model $model): string
    {
        return rtrim($this->laravel->getNamespace(), '\\').'\\Search\\'.class_basename($model).'Catalog';
    }

    private function definition(Filesystem $files, string $catalog, Model $model, string $definition): void
    {
        $namespace = substr($definition, 0, (int) strrpos($definition, '\\'));
        $class = class_basename($definition);
        // The application namespace maps to app/, as in every Laravel skeleton.
        $relative = str_replace('\\', '/', substr($definition, strlen(rtrim($this->laravel->getNamespace(), '\\')) + 1));
        $path = $this->laravel->basePath('app/'.$relative.'.php');

        if ($files->exists($path)) {
            $this->components->warn("{$definition} exists already: left as it is.");

            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, str_replace(
            ['{{namespace}}', '{{model}}', '{{modelClass}}', '{{class}}', '{{catalog}}'],
            [$namespace, $model::class, class_basename($model), $class, $catalog],
            $files->get(__DIR__.'/../../../stubs/catalog-definition.php.stub'),
        ));

        $this->components->info("Definition written: {$definition}. Describe its facets, then rebuild.");
    }
}
