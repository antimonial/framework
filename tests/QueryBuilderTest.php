<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Database\Connection;
use Antimonial\Database\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
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

    public function test_basic_select(): void
    {
        $this->assertSame('SELECT * FROM users', $this->qb()->getSql());
    }

    public function test_select_columns(): void
    {
        $qb = $this->qb()->select('id', 'name', 'email');
        $this->assertSame('SELECT id, name, email FROM users', $qb->getSql());
    }

    public function test_distinct(): void
    {
        $qb = $this->qb()->distinct()->select('role');
        $this->assertSame('SELECT DISTINCT role FROM users', $qb->getSql());
    }

    public function test_where_implicit_equals(): void
    {
        $qb = $this->qb()->where('name', 'John');
        $this->assertSame('SELECT * FROM users WHERE name = ?', $qb->getSql());
    }

    public function test_where_explicit_operator(): void
    {
        $qb = $this->qb()->where('age', '>', 18);
        $this->assertSame('SELECT * FROM users WHERE age > ?', $qb->getSql());
    }

    public function test_or_where(): void
    {
        $qb = $this->qb()->where('name', 'John')->orWhere('age', 25);
        $this->assertSame('SELECT * FROM users WHERE name = ? OR age = ?', $qb->getSql());
    }

    public function test_where_in(): void
    {
        $qb = $this->qb()->whereIn('id', [1, 2, 3]);
        $this->assertSame('SELECT * FROM users WHERE id IN (?, ?, ?)', $qb->getSql());
    }

    public function test_where_not_in(): void
    {
        $qb = $this->qb()->whereNotIn('id', [1, 2]);
        $this->assertSame('SELECT * FROM users WHERE id NOT IN (?, ?)', $qb->getSql());
    }

    public function test_where_null(): void
    {
        $qb = $this->qb()->whereNull('deleted_at');
        $this->assertSame('SELECT * FROM users WHERE deleted_at IS NULL', $qb->getSql());
    }

    public function test_where_not_null(): void
    {
        $qb = $this->qb()->whereNotNull('email');
        $this->assertSame('SELECT * FROM users WHERE email IS NOT NULL', $qb->getSql());
    }

    public function test_where_raw(): void
    {
        $qb = $this->qb()->whereRaw('age > ?', [18]);
        $this->assertSame('SELECT * FROM users WHERE age > ?', $qb->getSql());
    }

    public function test_where_raw_with_or(): void
    {
        $qb = $this->qb()->where('age', '>', 18)->orWhereRaw('role = ?', ['admin']);
        $this->assertSame('SELECT * FROM users WHERE age > ? OR role = ?', $qb->getSql());
    }

    public function test_nested_where_group(): void
    {
        $qb = $this->qb()->where(function (QueryBuilder $q) {
            $q->where('name', 'John')->orWhere('name', 'Jane');
        });
        $this->assertSame('SELECT * FROM users WHERE (name = ? OR name = ?)', $qb->getSql());
    }

    public function test_deep_nested_where_group(): void
    {
        $qb = $this->qb()
            ->where('status', 'active')
            ->where(function (QueryBuilder $q) {
                $q->where('age', '>=', 18)
                    ->where(function (QueryBuilder $qq) {
                        $qq->where('role', 'admin')->orWhere('role', 'mod');
                    });
            });
        $this->assertSame(
            'SELECT * FROM users WHERE status = ? AND (age >= ? AND (role = ? OR role = ?))',
            $qb->getSql()
        );
    }

    public function test_join(): void
    {
        $qb = $this->qb()->join('posts', 'users.id', '=', 'posts.user_id');
        $this->assertSame(
            'SELECT * FROM users INNER JOIN posts ON users.id = posts.user_id',
            $qb->getSql()
        );
    }

    public function test_left_join(): void
    {
        $qb = $this->qb()->leftJoin('posts', 'users.id', '=', 'posts.user_id');
        $this->assertSame(
            'SELECT * FROM users LEFT JOIN posts ON users.id = posts.user_id',
            $qb->getSql()
        );
    }

    public function test_multiple_joins(): void
    {
        $qb = $this->qb()
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->join('comments', 'posts.id', '=', 'comments.post_id');
        $this->assertSame(
            'SELECT * FROM users INNER JOIN posts ON users.id = posts.user_id INNER JOIN comments ON posts.id = comments.post_id',
            $qb->getSql()
        );
    }

    public function test_order_by(): void
    {
        $qb = $this->qb()->orderBy('name');
        $this->assertSame('SELECT * FROM users ORDER BY name ASC', $qb->getSql());
    }

    public function test_order_by_desc(): void
    {
        $qb = $this->qb()->orderBy('name', 'DESC');
        $this->assertSame('SELECT * FROM users ORDER BY name DESC', $qb->getSql());
    }

    public function test_multiple_order_by(): void
    {
        $qb = $this->qb()->orderBy('name')->orderBy('age', 'DESC');
        $this->assertSame('SELECT * FROM users ORDER BY name ASC, age DESC', $qb->getSql());
    }

    public function test_group_by(): void
    {
        $qb = $this->qb()->select('role', 'COUNT(*) AS cnt')->groupBy('role');
        $this->assertSame('SELECT role, COUNT(*) AS cnt FROM users GROUP BY role', $qb->getSql());
    }

    public function test_group_by_having(): void
    {
        $qb = $this->qb()
            ->select('role', 'COUNT(*) AS cnt')
            ->groupBy('role')
            ->having('cnt', '>', 5);
        $this->assertSame(
            'SELECT role, COUNT(*) AS cnt FROM users GROUP BY role HAVING cnt > ?',
            $qb->getSql()
        );
    }

    public function test_limit(): void
    {
        $qb = $this->qb()->limit(10);
        $this->assertSame('SELECT * FROM users LIMIT 10', $qb->getSql());
    }

    public function test_offset(): void
    {
        $qb = $this->qb()->limit(10)->offset(20);
        $this->assertSame('SELECT * FROM users LIMIT 10 OFFSET 20', $qb->getSql());
    }

    public function test_full_query(): void
    {
        $qb = $this->qb()
            ->select('users.id', 'users.name')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->where('users.active', 1)
            ->whereNull('users.deleted_at')
            ->orderBy('users.name')
            ->limit(5)
            ->offset(10);
        $this->assertSame(
            'SELECT users.id, users.name FROM users INNER JOIN posts ON users.id = posts.user_id WHERE users.active = ? AND users.deleted_at IS NULL ORDER BY users.name ASC LIMIT 5 OFFSET 10',
            $qb->getSql()
        );
    }

    public function test_count_sql(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT COUNT(*) AS aggregate FROM users WHERE active = ?', [1])
            ->willReturn([(object) ['aggregate' => 5]]);

        $count = $this->qb()->where('active', 1)->count();
        $this->assertSame(5, $count);
    }

    public function test_count_with_join(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->willReturn([(object) ['aggregate' => 3]]);

        $count = $this->qb()
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->where('posts.status', 'published')
            ->count();
        $this->assertSame(3, $count);
    }

    public function test_exists_returns_true(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->willReturn([(object) ['aggregate' => 1]]);

        $this->assertTrue($this->qb()->where('id', 1)->exists());
    }

    public function test_exists_returns_false(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->willReturn([(object) ['aggregate' => 0]]);

        $this->assertFalse($this->qb()->where('id', 999)->exists());
    }

    public function test_get_executes_and_resets(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM users', [])
            ->willReturn([(object) ['id' => 1]]);

        $results = $this->qb()->get();
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->id);
    }

    public function test_get_executes_where_bindings(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM users WHERE name = ?', ['John'])
            ->willReturn([]);

        $this->qb()->where('name', 'John')->get();
    }

    public function test_first_adds_limit_1(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM users WHERE id = ? LIMIT 1', [42])
            ->willReturn([(object) ['id' => 42, 'name' => 'John']]);

        $user = $this->qb()->where('id', 42)->first();
        $this->assertNotNull($user);
        $this->assertSame('John', $user->name);
    }

    public function test_first_returns_null(): void
    {
        $this->conn->expects($this->once())->method('select')->willReturn([]);

        $this->assertNull($this->qb()->where('id', 999)->first());
    }

    public function test_find_by_primary_key(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM users WHERE id = ? LIMIT 1', [1])
            ->willReturn([(object) ['id' => 1, 'name' => 'Alice']]);

        $user = $this->qb()->find(1);
        $this->assertNotNull($user);
        $this->assertSame('Alice', $user->name);
    }

    public function test_find_returns_null(): void
    {
        $this->conn->expects($this->once())->method('select')->willReturn([]);

        $this->assertNull($this->qb()->find(999));
    }

    public function test_value_returns_single_column(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT name FROM users WHERE id = ? LIMIT 1', [1])
            ->willReturn([(object) ['name' => 'Alice']]);

        $this->assertSame('Alice', $this->qb()->where('id', 1)->value('name'));
    }

    public function test_pluck_key_value(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->willReturn([
                (object) ['id' => 1, 'name' => 'Alice'],
                (object) ['id' => 2, 'name' => 'Bob'],
            ]);

        // pluck(key, valueKey) — key is first param, value is second
        $result = $this->qb()->pluck('name', 'id');
        $this->assertSame(['Alice' => 1, 'Bob' => 2], $result);
    }

    public function test_pluck_simple(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->willReturn([
                (object) ['name' => 'Alice'],
                (object) ['name' => 'Bob'],
            ]);

        // Without valueKey, the entire row is the value
        $result = $this->qb()->pluck('name');
        $this->assertCount(2, $result);
        $this->assertSame('Alice', array_key_first($result));
    }

    public function test_insert_returns_last_id(): void
    {
        $this->conn->expects($this->once())
            ->method('insert')
            ->with(
                'INSERT INTO users (name, email) VALUES (?, ?)',
                ['Alice', 'alice@example.com']
            )
            ->willReturn('42');

        $id = $this->qb()->insert(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->assertSame('42', $id);
    }

    public function test_update_returns_affected_rows(): void
    {
        $this->conn->expects($this->once())
            ->method('executeWrite')
            ->with(
                'UPDATE users SET name = ? WHERE id = ?',
                ['Bob', 1]
            )
            ->willReturn(1);

        $affected = $this->qb()->where('id', 1)->update(['name' => 'Bob']);
        $this->assertSame(1, $affected);
    }

    public function test_delete_returns_affected_rows(): void
    {
        $this->conn->expects($this->once())
            ->method('executeWrite')
            ->with('DELETE FROM users WHERE id = ?', [1])
            ->willReturn(1);

        $affected = $this->qb()->where('id', 1)->delete();
        $this->assertSame(1, $affected);
    }

    public function test_increment(): void
    {
        $this->conn->expects($this->once())
            ->method('executeWrite')
            ->with('UPDATE users SET views = views + 1 WHERE id = ?', [1])
            ->willReturn(1);

        $affected = $this->qb()->where('id', 1)->increment('views');
        $this->assertSame(1, $affected);
    }

    public function test_decrement(): void
    {
        $this->conn->expects($this->once())
            ->method('executeWrite')
            ->with('UPDATE users SET points = points - 5 WHERE id = ?', [1])
            ->willReturn(1);

        $affected = $this->qb()->where('id', 1)->decrement('points', 5);
        $this->assertSame(1, $affected);
    }

    public function test_paginate_returns_pagination_object(): void
    {
        $this->conn->expects($this->exactly(2))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [(object) ['aggregate' => 50]],
                array_fill(0, 10, (object) ['id' => 1]),
            );

        $result = $this->qb()->paginate(10, 2);

        $this->assertCount(10, $result->items);
        $this->assertSame(50, $result->total);
        $this->assertSame(10, $result->perPage);
        $this->assertSame(2, $result->currentPage);
        $this->assertSame(5, $result->totalPages);
    }

    public function test_paginate_on_first_page(): void
    {
        $this->conn->expects($this->exactly(2))->method('select')
            ->willReturnOnConsecutiveCalls(
                [(object) ['aggregate' => 3]],
                [(object) ['id' => 1]],
            );

        $result = $this->qb()->paginate(10, 1);

        $this->assertSame(3, $result->total);
        $this->assertSame(1, $result->totalPages);
        $this->assertSame(1, $result->currentPage);
    }

    public function test_paginate_empty_result(): void
    {
        $this->conn->expects($this->exactly(2))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [(object) ['aggregate' => 0]],
                [],
            );

        $result = $this->qb()->where('status', 'deleted')->paginate(10, 1);

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(1, $result->totalPages);
    }

    public function test_aggregate_sum(): void
    {
        // sum() calls aggregate() which does select(expression)->first()
        // first() adds LIMIT 1
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT SUM(views) FROM users LIMIT 1', [])
            ->willReturn([(object) ['SUM(views)' => 150]]);

        $this->assertSame(150.0, $this->qb()->sum('views'));
    }

    public function test_aggregate_avg(): void
    {
        $this->conn->expects($this->once())->method('select')
            ->with('SELECT AVG(age) FROM users LIMIT 1', [])
            ->willReturn([(object) ['AVG(age)' => 25.5]]);

        $this->assertSame(25.5, $this->qb()->avg('age'));
    }

    public function test_aggregate_min(): void
    {
        $this->conn->expects($this->once())->method('select')
            ->with('SELECT MIN(age) FROM users LIMIT 1', [])
            ->willReturn([(object) ['MIN(age)' => 18]]);

        $this->assertSame(18, $this->qb()->min('age'));
    }

    public function test_aggregate_max(): void
    {
        $this->conn->expects($this->once())->method('select')
            ->with('SELECT MAX(age) FROM users LIMIT 1', [])
            ->willReturn([(object) ['MAX(age)' => 65]]);

        $this->assertSame(65, $this->qb()->max('age'));
    }

    public function test_identifier_whitelist_rejects_invalid_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->qb()->where('id; DROP TABLE users', 1)->getSql();
    }

    public function test_identifier_whitelist_rejects_sql_injection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->qb()->where("name' OR '1'='1", 'test')->getSql();
    }

    public function test_identifier_whitelist_allows_qualified_column(): void
    {
        $qb = $this->qb()->where('users.status', 'active');
        $this->assertSame('SELECT * FROM users WHERE users.status = ?', $qb->getSql());
    }

    public function test_chain_to_sql_does_not_mutate_builder(): void
    {
        $qb = $this->qb()->where('name', 'Alice');
        $sql1 = $qb->getSql();
        $sql2 = $qb->getSql();

        $this->assertSame($sql1, $sql2);
    }

    public function test_sum_with_where(): void
    {
        $this->conn->expects($this->once())
            ->method('select')
            ->with('SELECT SUM(amount) FROM transactions WHERE user_id = ? LIMIT 1', [1])
            ->willReturn([(object) ['SUM(amount)' => 500]]);

        $qb = new QueryBuilder($this->conn, 'transactions');
        $this->assertSame(500.0, $qb->where('user_id', 1)->sum('amount'));
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
