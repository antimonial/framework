<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Database\Connection;
use Antimonial\Database\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for QueryBuilder BETWEEN support and the removal of
 * unsafe operators from isOperator().
 *
 * Before the fix, where('x', 'BETWEEN', [1, 2]) produced broken SQL
 * (a single ? for a multi-value operator) and failed at execution time.
 * BETWEEN is now rejected by where() and handled by dedicated
 * whereBetween()/whereNotBetween() methods.
 */
final class QueryBuilderBetweenTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
    }

    private function qb(): QueryBuilder
    {
        return new QueryBuilder($this->conn, 'users');
    }

    public function test_where_between_is_no_longer_a_recognized_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 'BETWEEN' is no longer in isOperator(), so where() treats the
        // second argument as a bare value for an implicit '='. The value
        // is an array, which assertIdentifier()/addBinding cannot accept,
        // surfacing a clear error at build time rather than broken SQL.
        $this->qb()->where('age', 'BETWEEN', [18, 65]);
    }

    public function test_where_in_is_no_longer_a_recognized_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->qb()->where('status', 'IN', [1, 2, 3]);
    }

    public function test_where_between_produces_correct_sql_and_bindings(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM users WHERE age BETWEEN ? AND ?', [18, 65])
            ->willReturn([]);

        $this->qb()->whereBetween('age', 18, 65)->get();
    }

    public function test_where_not_between_produces_correct_sql_and_bindings(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM users WHERE age NOT BETWEEN ? AND ?', [18, 65])
            ->willReturn([]);

        $this->qb()->whereNotBetween('age', 18, 65)->get();
    }

    public function test_where_between_combines_with_other_clauses(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with(
                'SELECT * FROM users WHERE active = ? AND age BETWEEN ? AND ?',
                [1, 18, 65]
            )
            ->willReturn([]);

        $this->qb()
            ->where('active', 1)
            ->whereBetween('age', 18, 65)
            ->get();
    }

    public function test_where_between_validates_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->qb()->whereBetween('bad-column!', 1, 2);
    }
}
