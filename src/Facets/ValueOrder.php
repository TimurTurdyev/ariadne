<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Facets;

/**
 * Order of values inside a panel group.
 */
enum ValueOrder: string
{
    case Dictionary = 'dictionary';

    /** Numbers compare as numbers: 16, 18, 160. */
    case Natural = 'natural';

    case Alpha = 'alpha';
    case CountDesc = 'count-desc';
}
