<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Database\Connection;
use Antimonial\Database\QueryBuilder;
use Antimonial\Model\Model;
use PHPUnit\Framework\TestCase;

class ModelTest_User extends Model {}
class ModelTest_BlogPost extends Model {}
class ModelTest_CustomTable extends Model
{
    protected string $table = 'explicit_table';
}
class ModelTest_WithTimestamps extends Model
{
    protected bool $timestamps = true;
}

final class ModelTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);

        // Stub table() to return a real QueryBuilder using the mock connection
        $this->conn->method('table')->willReturnCallback(
            fn (string $table) => new QueryBuilder($this->conn, $table)
        );
    }

    public function test_table_name_auto_guess(): void
    {
        $model = new ModelTest_User($this->conn);
        $table = (new \ReflectionProperty($model, 'table'))->getValue($model);

        $this->assertStringEndsWith('_users', $table);
        $this->assertStringContainsString('model_test', $table);
    }

    public function test_multi_word_table_name(): void
    {
        $model = new ModelTest_BlogPost($this->conn);
        $table = (new \ReflectionProperty($model, 'table'))->getValue($model);

        $this->assertStringEndsWith('_posts', $table);
        $this->assertStringContainsString('blog', $table);
    }

    public function test_explicit_table_name(): void
    {
        $model = new ModelTest_CustomTable($this->conn);
        $this->assertSame('explicit_table', (new \ReflectionProperty($model, 'table'))->getValue($model));
    }

    public function test_find_by_primary_key(): void
    {
        $model = new ModelTest_User($this->conn);

        $this->conn->expects($this->once())
            ->method('select')
            ->with($this->stringContains('WHERE id = ? LIMIT 1'), [1])
            ->willReturn([(object) ['id' => 1, 'name' => 'Alice']]);

        $result = $model->find(1);
        $this->assertNotNull($result);
        $this->assertSame('Alice', $result->name);
    }

    public function test_find_returns_null(): void
    {
        $model = new ModelTest_User($this->conn);

        $this->conn->expects($this->once())
            ->method('select')
            ->willReturn([]);

        $this->assertNull($model->find(999));
    }

    public function test_all_returns_all_rows(): void
    {
        $model = new ModelTest_User($this->conn);

        $this->conn->expects($this->once())
            ->method('select')
            ->with($this->stringContains('SELECT'), [])
            ->willReturn([(object) ['id' => 1], (object) ['id' => 2]]);

        $this->assertCount(2, $model->all());
    }

    public function test_insert_adds_timestamps_when_enabled(): void
    {
        $model = new ModelTest_WithTimestamps($this->conn);

        $this->conn->expects($this->once())
            ->method('insert')
            ->with(
                $this->callback(fn (string $sql) => str_contains($sql, 'created_at')),
                $this->anything(),
            )
            ->willReturn('1');

        $model->insert(['name' => 'Alice']);
    }

    public function test_update_adds_updated_at_when_enabled(): void
    {
        $model = new ModelTest_WithTimestamps($this->conn);

        $this->conn->expects($this->once())
            ->method('executeWrite')
            ->with(
                $this->callback(fn (string $sql) => str_contains($sql, 'updated_at')),
                $this->anything(),
            )
            ->willReturn(1);

        $model->update(1, ['name' => 'Bob']);
    }

    public function test_delete_returns_affected_count(): void
    {
        $model = new ModelTest_User($this->conn);

        $this->conn->expects($this->once())
            ->method('executeWrite')
            ->with($this->stringContains('DELETE FROM'), [1])
            ->willReturn(1);

        $this->assertSame(1, $model->delete(1));
    }

    public function test_where_returns_query_builder(): void
    {
        $model = new ModelTest_User($this->conn);

        $qb = $model->where('status', 'active');
        $this->assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function test_query_returns_query_builder(): void
    {
        $model = new ModelTest_User($this->conn);

        $qb = $model->query();
        $this->assertInstanceOf(QueryBuilder::class, $qb);
        $this->assertStringContainsString('SELECT', $qb->toSql());
    }
}
