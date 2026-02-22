<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

/**
 * Role of one query in the plan.
 */
enum IntentKind: string
{
    /** Results, the exact total and counts of inactive facets. */
    case Main = 'main';

    /** Slider bounds without the price filter, or the slider would collapse into its own selection. */
    case PriceBounds = 'price-bounds';

    /** Values that exist in the scope at all: the source of zero counts. */
    case Palette = 'palette';

    /** Counts of an active facet with its own filter removed. */
    case WhatIf = 'what-if';
}
