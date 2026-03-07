<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use TimurTurdyev\Ariadne\Catalog\Database\Dictionary;
use TimurTurdyev\Ariadne\Catalog\Database\GroupInfo;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Index\CatalogIndex;
use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Results\IndexResponse;

/**
 * The step between the counts of a driver and the panel: only, hide and the threshold drop groups,
 * the rest is put in panel order, and only the kept groups get their values read and linked.
 *
 * One step for every driver, so two drivers give one panel.
 *
 * @internal
 */
final class EarlyRules implements CatalogIndex
{
    private ?Coverage $coverage = null;

    public function __construct(
        private readonly FacetIndex $driver,
        private readonly Dictionary $dictionary,
        private readonly Panel $rules,
    ) {}

    public function execute(QueryPlan $plan): IndexResponse
    {
        $response = $this->driver->execute($plan);
        $query = $plan->query;

        if (! $query->need->wantsPanel()) {
            return $response;
        }

        $key = $plan->paletteKey();
        $palette = $response->countsFor($key);
        $candidates = [];

        foreach (array_keys($palette) as $term) {
            $slug = $this->driver->groupOf($term);

            if ($slug !== null) {
                $candidates[$slug] = true;
            }
        }

        foreach ($query->selection->facets() as $facet) {
            $candidates[$facet->value] = true;
        }

        $groups = [];

        foreach (array_keys($candidates) as $slug) {
            $group = $this->dictionary->group((string) $slug);

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        $byCoverage = $this->rules->byCoverage || array_filter($groups, static fn (GroupInfo $group): bool => $group->producer !== null && $group->position === null) !== [];
        $this->coverage = $byCoverage || $this->rules->minCoverage > 0.0
            ? $this->driver->coverage($query, $this->rules->minCoverage > 0.0)
            : null;

        $shown = $this->arranged($groups, $query->selection->facets());
        $this->dictionary->preload($shown);
        $this->dictionary->arrange($shown);
        $kept = array_flip($shown);
        $counts = $response->counts;
        $counts[$key] = array_filter($palette, fn (int $term): bool => isset($kept[(string) $this->driver->groupOf($term)]), ARRAY_FILTER_USE_KEY);

        return new IndexResponse($response->hits, $response->total, $counts, $response->priceBounds, $response->owners);
    }

    /**
     * What the counts gave about coverage on this screen; null when nothing needed it.
     */
    public function coverage(): ?Coverage
    {
        return $this->coverage;
    }

    /**
     * Default order first: declared facets where declared, groups of a dynamic facet in its place,
     * positioned ones by position, the rest by coverage; then only, hide, the threshold and the pins.
     *
     * @param  list<GroupInfo>  $groups
     * @param  list<FacetId>  $selected
     * @return list<string>
     */
    private function arranged(array $groups, array $selected): array
    {
        $coverage = $this->coverage;

        usort($groups, static fn (GroupInfo $a, GroupInfo $b): int => $a->slot <=> $b->slot
            ?: ($a->position === null) <=> ($b->position === null)
            ?: ($a->position ?? 0) <=> ($b->position ?? 0)
            ?: ($coverage?->documents($b->slug) ?? 0) <=> ($coverage?->documents($a->slug) ?? 0)
            ?: strcmp($a->title, $b->title)
            ?: strcmp($a->slug, $b->slug));

        $chosen = [];

        foreach ($selected as $facet) {
            $chosen[$facet->value] = true;
        }

        $candidates = array_map(static fn (GroupInfo $group): array => [
            'slug' => $group->slug,
            'selected' => isset($chosen[$group->slug]),
            // Without a total the documents order the groups just as well; the threshold then has nothing to cut.
            'coverage' => $coverage === null ? null : ($coverage->share($group->slug) ?? (float) $coverage->documents($group->slug)),
            'toggle' => $group->isToggle(),
        ], $groups);

        // The cap waits for the panel: a group the policy drops for want of values must not take a place.
        $uncapped = $this->rules->max(null);
        $shown = [];

        foreach ($uncapped->arrange($candidates) as $at) {
            $shown[] = $groups[$at]->slug;
        }

        return $shown;
    }
}
