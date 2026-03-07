<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

/**
 * How the panel of one screen is shaped: pinned groups, hidden groups, a cap and a coverage threshold.
 *
 * A group with a selection is never hidden, cut or dropped, or the filter could not be removed.
 * Pinned groups and toggles pass the threshold: they only need to be present.
 */
final readonly class Panel
{
    /**
     * @param  list<string>  $first  these groups go first, in this order
     * @param  list<string>|null  $only  only these groups, in this order; null - all
     * @param  list<string>  $hidden
     * @param  float  $minCoverage  share of the scope documents a group has to cover, 0 to 1
     * @param  bool  $byCoverage  unpinned groups by coverage, not by the default order
     */
    public function __construct(
        public array $first = [],
        public ?array $only = null,
        public array $hidden = [],
        public ?int $max = null,
        public float $minCoverage = 0.0,
        public bool $byCoverage = false,
    ) {}

    public function first(string ...$groups): self
    {
        return new self(array_values($groups), $this->only, $this->hidden, $this->max, $this->minCoverage, $this->byCoverage);
    }

    /**
     * Only these groups, in this order; no arguments shows every group again.
     */
    public function only(string ...$groups): self
    {
        return new self($this->first, $groups === [] ? null : array_values($groups), $this->hidden, $this->max, $this->minCoverage, $this->byCoverage);
    }

    public function hide(string ...$groups): self
    {
        $hidden = array_values(array_unique([...$this->hidden, ...$groups]));

        return new self($this->first, $this->only, $hidden, $this->max, $this->minCoverage, $this->byCoverage);
    }

    /**
     * Takes groups back from hide(): a child category showing what its parent hid.
     */
    public function show(string ...$groups): self
    {
        $hidden = array_values(array_diff($this->hidden, $groups));

        return new self($this->first, $this->only, $hidden, $this->max, $this->minCoverage, $this->byCoverage);
    }

    public function max(?int $groups): self
    {
        return new self($this->first, $this->only, $this->hidden, $groups === null ? null : max(0, $groups), $this->minCoverage, $this->byCoverage);
    }

    public function minCoverage(float $share): self
    {
        return new self($this->first, $this->only, $this->hidden, $this->max, min(1.0, max(0.0, $share)), $this->byCoverage);
    }

    public function byCoverage(bool $on = true): self
    {
        return new self($this->first, $this->only, $this->hidden, $this->max, $this->minCoverage, $on);
    }

    /**
     * Part of the screen cache key: the rules decide which groups the panel holds.
     */
    public function signature(): string
    {
        return hash('xxh3', serialize([$this->first, $this->only, $this->hidden, $this->max, $this->minCoverage, $this->byCoverage]));
    }

    /**
     * Whether the rules read the coverage of groups at all.
     */
    public function needsCoverage(): bool
    {
        return $this->minCoverage > 0.0 || $this->byCoverage;
    }

    /**
     * Whether a group may reach the panel before its values are counted: only, hide and the threshold.
     */
    public function admits(string $slug, bool $selected, ?float $coverage, bool $toggle): bool
    {
        if ($selected) {
            return true;
        }

        if ($this->only !== null && ! in_array($slug, $this->only, true)) {
            return false;
        }

        if (in_array($slug, $this->hidden, true)) {
            return false;
        }

        $pinned = in_array($slug, $this->only ?? $this->first, true);

        return $pinned || $toggle || $coverage === null || $coverage >= $this->minCoverage;
    }

    /**
     * @param  list<array{slug: string, selected: bool, coverage: ?float, toggle: bool}>  $groups  candidates in default order
     * @return list<int> positions in $groups of the groups shown, in the order shown
     *
     * @internal
     */
    public function arrange(array $groups): array
    {
        $kept = [];

        foreach ($groups as $at => $group) {
            if ($this->admits($group['slug'], $group['selected'], $group['coverage'], $group['toggle'])) {
                $kept[] = [$at, $group];
            }
        }

        $pins = array_flip($this->only ?? $this->first);

        usort($kept, function (array $a, array $b) use ($pins): int {
            $pa = $pins[$a[1]['slug']] ?? null;
            $pb = $pins[$b[1]['slug']] ?? null;

            if ($pa !== null || $pb !== null) {
                return ($pa ?? PHP_INT_MAX) <=> ($pb ?? PHP_INT_MAX) ?: $a[0] <=> $b[0];
            }

            if ($this->byCoverage) {
                return ($b[1]['coverage'] ?? -1.0) <=> ($a[1]['coverage'] ?? -1.0) ?: $a[0] <=> $b[0];
            }

            return $a[0] <=> $b[0];
        });

        if ($this->max !== null) {
            $head = array_slice($kept, 0, $this->max);

            foreach (array_slice($kept, $this->max) as $cut) {
                if ($cut[1]['selected']) {
                    $head[] = $cut;
                }
            }

            $kept = $head;
        }

        return array_map(static fn (array $one): int => $one[0], $kept);
    }
}
