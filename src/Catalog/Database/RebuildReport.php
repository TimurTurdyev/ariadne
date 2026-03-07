<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

/**
 * What a rebuild or a sync wrote and what it refused, so a bad slug from data is named instead of lost.
 */
final class RebuildReport
{
    public int $generation = 0;

    /** Documents the producers saw. */
    public int $documents = 0;

    /** Documents that got at least one row: the gate compares the table with it. */
    public int $documentsWithRows = 0;

    public int $rows = 0;

    public int $newGroups = 0;

    public int $newValues = 0;

    /** Pending marks replayed into the new generation before the pointer moved. */
    public int $replayed = 0;

    public float $seconds = 0.0;

    /** @var array<string, array{reason: string, documents: int}> group slug from data => why it is left out */
    public array $refused = [];

    /** @var array<string, array<string, string>> group => given value => slug written */
    public array $renamed = [];

    /** @var array<string, list<string>> group => values that cannot become a slug */
    public array $rejected = [];

    /** @var array<string, int> group slug a dynamic facet yields but describedBy() does not list => documents */
    public array $undescribed = [];

    /** @var array<string, list<string>> group => labels that differ for one value slug; the smallest sort and label is kept */
    public array $conflicts = [];

    /** @var list<string> */
    public array $warnings = [];

    public function refuse(string $group, string $reason): void
    {
        $this->refused[$group] ??= ['reason' => $reason, 'documents' => 0];
        $this->refused[$group]['documents']++;
    }

    public function rename(string $group, string $given, string $slug): void
    {
        $this->renamed[$group][$given] = $slug;
    }

    public function reject(string $group, string $value): void
    {
        if (! in_array($value, $this->rejected[$group] ?? [], true)) {
            $this->rejected[$group][] = $value;
        }
    }

    public function conflict(string $group, string $label): void
    {
        if (! in_array($label, $this->conflicts[$group] ?? [], true)) {
            $this->conflicts[$group][] = $label;
        }
    }

    /**
     * What check shows and fails on until the next rebuild.
     *
     * @return list<array{facet: string, reason: string, documents: int}>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->refused as $group => $refused) {
            $problems[] = ['facet' => (string) $group, 'reason' => $refused['reason'], 'documents' => $refused['documents']];
        }

        foreach ($this->rejected as $group => $values) {
            $problems[] = ['facet' => (string) $group, 'reason' => 'values that cannot become a slug: "'.implode('", "', array_slice($values, 0, 5)).'"', 'documents' => count($values)];
        }

        return $problems;
    }
}
