<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * SQL built from the definition and wrapped column names, never from a request.
 *
 * @internal
 */
final readonly class Sql implements Expression
{
    public function __construct(private string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
