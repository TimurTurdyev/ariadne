<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Url;

/**
 * The two public shapes of a catalog query string.
 */
enum UrlStyle: string
{
    /** ?color=a,b&material=all:x,y&price=1000-50000 */
    case Comma = 'comma';

    /** ?color[]=a&color[]=b&price_min=1000&price_max=50000, as an HTML form submits it */
    case Brackets = 'brackets';
}
