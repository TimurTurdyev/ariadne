<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Index;

use RuntimeException;

/**
 * The engine could not be reached; a query error is a bug and surfaces as is.
 */
final class CatalogIndexUnavailable extends RuntimeException {}
