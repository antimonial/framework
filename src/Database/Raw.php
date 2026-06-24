<?php

declare(strict_types=1);

namespace Antimonial\Database;

/**
 * Represents a raw SQL expression.
 *
 * Used to bypass parameterized binding when you need to inject
 * raw SQL fragments (e.g. DB::raw('NOW()'), DB::raw('SUM(amount)')).
 *
 * The expression is inserted verbatim into the SQL query — use
 * with caution to avoid SQL injection.
 *
 * @example $sql = DB::raw('NOW()');
 *
 * @see DB::raw()
 * @see QueryBuilder::addBinding()
 */
class Raw
{
    /**
     * @param string $expression Raw SQL expression
     */
    public function __construct(
        public readonly string $expression,
    ) {}

    /**
     * Return the raw expression as a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->expression;
    }
}
