<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TimurTurdyev\Ariadne\Catalog\Catalogs;
use TimurTurdyev\Ariadne\Catalog\Database\RebuildOutcome;
use TimurTurdyev\Ariadne\Catalog\Database\RebuildReport;

/**
 * Builds a new generation of each catalog and publishes it; exits 1 when the gate refused, 2 on a failure, 3 when busy.
 */
#[AsCommand(name: 'ariadne:rebuild')]
final class RebuildCommand extends Command
{
    protected $signature = 'ariadne:rebuild
        {catalog?* : catalog keys; every declared catalog by default}
        {--force : publish an empty generation over a non-empty one}
        {--wait=0 : seconds to wait for a rebuild of the same catalog that is running}';

    protected $description = 'Build a new generation of the facet rows and publish it';

    public function handle(Catalogs $catalogs): int
    {
        $names = $this->argument('catalog');
        $names = is_array($names) && $names !== [] ? array_values(array_filter($names, is_string(...))) : $catalogs->names();
        $wait = $this->option('wait');
        $code = self::SUCCESS;

        foreach ($names as $name) {
            $outcome = $catalogs->get($name)->rebuild($this->option('force') === true, is_numeric($wait) ? (int) $wait : 0);
            $this->report($name, $outcome);
            $code = max($code, $outcome->code);
        }

        return $code;
    }

    private function report(string $name, RebuildOutcome $outcome): void
    {
        $report = $outcome->report;

        if ($outcome->published()) {
            $this->components->info(sprintf(
                '%s: generation %d, %d documents with rows of %d, %d rows, %d new groups, %d new values, %d replayed, %.2f s',
                $name,
                $report->generation,
                $report->documentsWithRows,
                $report->documents,
                $report->rows,
                $report->newGroups,
                $report->newValues,
                $report->replayed,
                $report->seconds,
            ));
        } else {
            $this->components->error("{$name}: ".($outcome->message ?? 'not published'));
        }

        $this->problems($report);
    }

    private function problems(RebuildReport $report): void
    {
        foreach ($report->refused as $group => $refused) {
            $this->components->error(sprintf('group "%s" from data is left out of %d documents: %s', $group, $refused['documents'], $refused['reason']));
        }

        foreach ($report->undescribed as $group => $documents) {
            $this->components->warn(sprintf('group "%s" is not in describedBy(): left out of %d documents', $group, $documents));
        }

        foreach ($report->renamed as $group => $renamed) {
            $this->components->warn(sprintf('%s: %d values written as slugs, such as "%s" as "%s"', $group, count($renamed), array_key_first($renamed), reset($renamed)));
        }

        foreach ($report->rejected as $group => $values) {
            $this->components->error(sprintf('%s: %d values cannot become a slug and are left out: "%s"', $group, count($values), implode('", "', array_slice($values, 0, 5))));
        }

        foreach ($report->conflicts as $group => $labels) {
            $this->components->warn(sprintf('%s: one value, several labels, such as "%s"', $group, implode('", "', array_slice($labels, 0, 3))));
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }
    }
}
