<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

/**
 * A definition that shapes the panel of each screen; the controller names the context with within().
 *
 * @template TContext
 */
interface ShapesPanel
{
    /**
     * @param  Panel  $panel  defaults of the catalog config: min_coverage and max_groups
     * @param  TContext|null  $context  the category of the screen, or whatever the controller passed
     */
    public function panel(Panel $panel, mixed $context): Panel;
}
