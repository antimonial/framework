<?php

declare(strict_types=1);

namespace Antimonial\Database;

use InvalidArgumentException;
use PDOException;

/**
 * Fluent SQL query builder.
 *
 * Builds SELECT, INSERT, UPDATE, and DELETE queries by accumulating
 * clauses in arrays, then compiling them to SQL at execution time.
 *
 * This is the largest class in the framework (~230 lines) because
 * SQL has many moving parts. But the pattern is simple:
 *  1. Chain methods to accumulate state
 *  2. Call a terminal method (get, insert, update, delete)
 *  3. SQL is compiled and executed via PDO
 *
 * Named bindings use positional ? placeholders for simplicity.
 *
 * Security: column/table identifiers passed to where(), orWhere(),
 * join(), orderBy(), groupBy(), having() and increment()/decrement()
 * are validated against a strict whitelist. Column lists in select()
 * and aggregate expressions (sum/avg/min/max) are trusted and must
 * only contain developer-controlled values.
 *
 * Note: a builder instance is single-use — terminal methods (get,
 * insert, update, delete, count, ...) reset its state, so chain a
 * fresh builder for each query.
 *
 * @example
 *   DB::table('users')
 *       ->select('name', 'email')
 *       ->where('age', '>=', 18)
 *       ->orderBy('name')
 *       ->limit(10)
 *       ->get();
 *
 * @see Connection::table()
 * @see DB::table()
 */
class QueryBuilder
{
    /**
     * @var Connection The database connection
     */
    private Connection $connection;

    /**
     * @var string The table name
     */
    private string $table;

    /**
     * @var string[] Columns to select
     */
    private array $select = ['*'];

    /**
     * @var bool Whether SELECT DISTINCT is enabled
     */
    private bool $distinct = false;

    /**
     * WHERE clauses.
     *
     * Each entry: ['logic' => 'AND'|'OR', 'sql' => string, 'bindings' => array]
     *
     * @var array<int, array{logic: string, sql: string, bindings: array}>
     */
    private array $wheres = [];

    /**
     * JOIN clauses.
     *
     * Each entry: ['type' => string, 'table' => string, 'sql' => string]
     *
     * @var array<int, array{type: string, table: string, sql: string}>
     */
    private array $joins = [];

    /**
     * @var string[] GROUP BY columns
     */
    private array $groups = [];

    /**
     * HAVING clauses.
     *
     * Each entry: ['sql' => string, 'bindings' => array]
     *
     * @var array<int, array{sql: string, bindings: array}>
     */
    private array $havings = [];

    /**
     * ORDER BY clauses.
     *
     * Each entry: ['column' => string, 'direction' => 'ASC'|'DESC']
     *
     * @var array<int, array{column: string, direction: string}>
     */
    private array $orders = [];

    /**
     * @var int|null Maximum number of rows to return
     */
    private ?int $limit = null;

    /**
     * @var int|null Number of rows to skip
     */
    private ?int $offset = null;

    /**
     * @var string Primary key column name for find()
     */
    private string $primaryKey = 'id';

    /**
     * @param Connection $connection Database connection
     * @param string     $table      Table name
     */
    public function __construct(Connection $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $this->assertIdentifier($table);
    }

    // ─── Chainable Query Builders ───────────────────────────────

    /**
     * Set the columns to select.
     *
     * @example ->select('name', 'email')
     *
     * @param string ...$columns Column names
     * @return $this
     */
    public function select(string ...$columns): static
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * Add DISTINCT to the SELECT clause.
     *
     * @return $this
     */
    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Set the primary key column used by find().
     *
     * @param string $key
     * @return $this
     */
    public function setPrimaryKey(string $key): static
    {
        $this->primaryKey = $key;
        return $this;
    }

    /**
     * Add a WHERE clause.
     *
     * Supports two forms:
     *  - where('column', value)        -> column = value
     *  - where('column', '>', value)   -> column > value
     *
     * @example ->where('age', '>=', 18)
     * @example ->where('active', true)
     *
     * @param string        $column
     * @param mixed         $operatorOrValue Operator (e.g. '=', '>', 'LIKE') or value when using shorthand
     * @param mixed         $value           Value when using operator form
     * @return $this
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        $column = $this->assertIdentifier($column);

        if ($value === null && !$this->isOperator($operatorOrValue)) {
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        }

        $placeholder = $this->addBinding($value);
        $this->wheres[] = [
            'logic'    => 'AND',
            'sql'      => "{$column} {$operatorOrValue} {$placeholder}",
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Add an OR WHERE clause.
     *
     * @example ->where('active', true)->orWhere('role', 'admin')
     *
     * @param string        $column
     * @param mixed         $operatorOrValue
     * @param mixed         $value
     * @return $this
     */
    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        $column = $this->assertIdentifier($column);

        if ($value === null && !$this->isOperator($operatorOrValue)) {
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        }

        $placeholder = $this->addBinding($value);
        $this->wheres[] = [
            'logic'    => 'OR',
            'sql'      => "{$column} {$operatorOrValue} {$placeholder}",
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Add a WHERE IN clause.
     *
     * @example ->whereIn('id', [1, 2, 3])
     *
     * @param string $column
     * @param array  $values
     * @return $this
     */
    public function whereIn(string $column, array $values): static
    {
        $column = $this->assertIdentifier($column);

        if (empty($values)) {
            $this->wheres[] = ['logic' => 'AND', 'sql' => '1 = 0', 'bindings' => []];
            return $this;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($values as $value) {
            $placeholders[] = $this->addBinding($value);
            $bindings[] = $value;
        }

        $this->wheres[] = [
            'logic'    => 'AND',
            'sql'      => "{$column} IN (" . implode(', ', $placeholders) . ")",
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * @param string $column
     * @param array  $values
     * @return $this
     */
    public function whereNotIn(string $column, array $values): static
    {
        $column = $this->assertIdentifier($column);

        if (empty($values)) {
            return $this;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($values as $value) {
            $placeholders[] = $this->addBinding($value);
            $bindings[] = $value;
        }

        $this->wheres[] = [
            'logic'    => 'AND',
            'sql'      => "{$column} NOT IN (" . implode(', ', $placeholders) . ")",
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NULL clause.
     *
     * @example ->whereNull('deleted_at')
     *
     * @param string $column
     * @return $this
     */
    public function whereNull(string $column): static
    {
        $column = $this->assertIdentifier($column);
        $this->wheres[] = ['logic' => 'AND', 'sql' => "{$column} IS NULL", 'bindings' => []];
        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL clause.
     *
     * @param string $column
     * @return $this
     */
    public function whereNotNull(string $column): static
    {
        $column = $this->assertIdentifier($column);
        $this->wheres[] = ['logic' => 'AND', 'sql' => "{$column} IS NOT NULL", 'bindings' => []];
        return $this;
    }

    /**
     * Add a raw WHERE clause.
     *
     * @example ->whereRaw('age > ? OR role = ?', [18, 'admin'])
     *
     * @param string $sql      Raw SQL fragment (e.g. 'age > ? OR role = ?')
     * @param array  $bindings Values for ? placeholders
     * @return $this
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = ['logic' => 'AND', 'sql' => $sql, 'bindings' => $bindings];
        return $this;
    }

    /**
     * Add a JOIN clause.
     *
     * @example ->join('posts', 'users.id', '=', 'posts.user_id')
     *
     * @param string $table Join table name
     * @param string $col1  Left column
     * @param string $op    Operator (=, <, >, etc.)
     * @param string $col2  Right column
     * @param string $type  Join type (INNER, LEFT, RIGHT)
     * @return $this
     */
    public function join(string $table, string $col1, string $op, string $col2, string $type = 'INNER'): static
    {
        $table = $this->assertIdentifier($table);
        $col1 = $this->assertIdentifier($col1);
        $col2 = $this->assertIdentifier($col2);

        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper($op), $allowedOps, true)) {
            throw new InvalidArgumentException("Invalid JOIN operator: {$op}");
        }

        $this->joins[] = [
            'type'  => strtoupper($type),
            'table' => $table,
            'sql'   => "{$type} JOIN {$table} ON {$col1} {$op} {$col2}",
        ];
        return $this;
    }

    /**
     * Shorthand for a LEFT JOIN.
     *
     * @example ->leftJoin('posts', 'users.id', '=', 'posts.user_id')
     *
     * @param string $table
     * @param string $col1
     * @param string $op
     * @param string $col2
     * @return $this
     */
    public function leftJoin(string $table, string $col1, string $op, string $col2): static
    {
        return $this->join($table, $col1, $op, $col2, 'LEFT');
    }

    /**
     * Add a GROUP BY clause.
     *
     * @param string ...$columns
     * @return $this
     */
    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
        }
        $this->groups = array_merge($this->groups, $columns);
        return $this;
    }

    /**
     * Add a HAVING clause.
     *
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @return $this
     */
    public function having(string $column, string $operator, mixed $value): static
    {
        $column = $this->assertIdentifier($column);

        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper($operator), $allowedOps, true)) {
            throw new InvalidArgumentException("Invalid HAVING operator: {$operator}");
        }

        $placeholder = $this->addBinding($value);
        $this->havings[] = [
            'sql'      => "{$column} {$operator} {$placeholder}",
            'bindings' => [$value],
        ];
        return $this;
    }

    /**
     * Add an ORDER BY clause.
     *
     * @example ->orderBy('name', 'DESC')
     *
     * @param string $column
     * @param string $direction 'ASC' or 'DESC'
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $column = $this->assertIdentifier($column);
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    /**
     * Set the LIMIT clause.
     *
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the OFFSET clause.
     *
     * @param int $offset
     * @return $this
     */
    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    // ─── Terminal Methods (Execute SQL) ─────────────────────────

    /**
     * Execute the query and return all results.
     *
     * @return object[] Array of stdClass objects
     * @throws PDOException If the query fails
     */
    public function get(): array
    {
        $sql = $this->compileSelect();
        $results = $this->connection->select($sql, $this->getWhereBindings());
        $this->reset();
        return $results;
    }

    /**
     * Get the first result row, or null if none found.
     *
     * @return object|null
     * @throws PDOException If the query fails
     */
    public function first(): ?object
    {
        $this->limit = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * Find a row by its primary key.
     *
     * @example $user = DB::table('users')->find(42);
     *
     * @param mixed $id
     * @return object|null
     * @throws PDOException If the query fails
     * @see Model::find()
     */
    public function find(mixed $id): ?object
    {
        return $this->where($this->primaryKey, $id)->first();
    }

    /**
     * Get a single column value from the first result.
     *
     * @example $email = DB::table('users')->where('id', 1)->value('email');
     *
     * @param string $column
     * @return mixed
     * @throws PDOException If the query fails
     */
    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        return $row->$column ?? null;
    }

    /**
     * Get a key-value array from the results.
     *
     * @example $names = DB::table('users')->pluck('id', 'name');
     *
     * @param string      $key      Column to use as array key
     * @param string|null $valueKey Column to use as array value (null = entire row)
     * @return array
     * @throws PDOException If the query fails
     */
    public function pluck(string $key, ?string $valueKey = null): array
    {
        $results = $this->get();
        $plucked = [];

        foreach ($results as $row) {
            $k = $row->$key ?? null;
            if ($k === null) {
                continue;
            }
            $plucked[$k] = $valueKey !== null ? ($row->$valueKey ?? null) : $row;
        }

        return $plucked;
    }

    /**
     * Count the number of rows matching the query.
     *
     * @return int
     * @throws PDOException If the query fails
     */
    public function count(): int
    {
        $originalSelect = $this->select;
        $this->select = ['COUNT(*) AS aggregate'];
        $sql = $this->compileSelect();
        $this->select = $originalSelect;

        $result = $this->connection->select($sql, $this->getWhereBindings());
        $this->reset();

        return (int) ($result[0]->aggregate ?? 0);
    }

    /**
     * Check if any rows match the query.
     *
     * @return bool
     * @throws PDOException If the query fails
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Calculate the sum of a column.
     *
     * @param string $column
     * @return float
     * @throws PDOException If the query fails
     */
    public function sum(string $column): float
    {
        return (float) $this->aggregate("SUM({$column})");
    }

    /**
     * Calculate the average of a column.
     *
     * @param string $column
     * @return float
     * @throws PDOException If the query fails
     */
    public function avg(string $column): float
    {
        return (float) $this->aggregate("AVG({$column})");
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column
     * @return mixed
     * @throws PDOException If the query fails
     */
    public function min(string $column): mixed
    {
        return $this->aggregate("MIN({$column})");
    }

    /**
     * Get the maximum value of a column.
     *
     * @param string $column
     * @return mixed
     * @throws PDOException If the query fails
     */
    public function max(string $column): mixed
    {
        return $this->aggregate("MAX({$column})");
    }

    /**
     * Run a single-column aggregate query and return its value.
     *
     * @param string $expression Aggregate expression (e.g. 'SUM(amount)')
     * @return mixed The aggregate value, or null if no rows matched
     */
    private function aggregate(string $expression): mixed
    {
        $row = $this->select($expression)->first();
        return $row === null ? null : array_values((array) $row)[0] ?? null;
    }

    // ─── Write Methods ──────────────────────────────────────────

    /**
     * Insert a row and return the last inserted ID.
     *
     * @example DB::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return string Last inserted ID
     * @throws PDOException If the insert fails
     * @see Model::insert()
     */
    public function insert(array $data): string
    {
        $columns = array_keys($data);
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
        }
        $placeholders = [];
        $bindings = [];

        foreach ($data as $value) {
            $placeholders[] = $this->addBinding($value);
            $bindings[] = $value;
        }

        $columnList = implode(', ', $columns);
        $valueList = implode(', ', $placeholders);

        $sql = "INSERT INTO {$this->table} ({$columnList}) VALUES ({$valueList})";
        $id = $this->connection->insert($sql, $bindings);
        $this->reset();

        return $id;
    }

    /**
     * Update rows and return the number of affected rows.
     *
     * @example DB::table('users')->where('id', 42)->update(['name' => 'Jane']);
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return int Affected row count
     * @throws PDOException If the update fails
     * @see Model::update()
     */
    public function update(array $data): int
    {
        $setParts = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $column = $this->assertIdentifier($column);
            $placeholder = $this->addBinding($value);
            $setParts[] = "{$column} = {$placeholder}";
            $bindings[] = $value;
        }

        $setClause = implode(', ', $setParts);
        $whereClause = $this->compileWheres();
        $sql = "UPDATE {$this->table} SET {$setClause}" . ($whereClause !== '' ? " WHERE {$whereClause}" : '');

        $bindings = array_merge($bindings, $this->getWhereBindings());
        $affected = $this->connection->executeWrite($sql, $bindings);
        $this->reset();

        return $affected;
    }

    /**
     * Delete rows and return the number of affected rows.
     *
     * @example DB::table('users')->where('id', 42)->delete();
     *
     * @return int Affected row count
     * @throws PDOException If the delete fails
     * @see Model::delete()
     */
    public function delete(): int
    {
        $whereClause = $this->compileWheres();
        $sql = "DELETE FROM {$this->table}" . ($whereClause !== '' ? " WHERE {$whereClause}" : '');

        $bindings = $this->getWhereBindings();
        $affected = $this->connection->executeWrite($sql, $bindings);
        $this->reset();

        return $affected;
    }

    /**
     * Increment a column's value.
     *
     * @example DB::table('users')->where('id', 42)->increment('views');
     *
     * @param string $column
     * @param int    $amount
     * @return int Affected row count
     * @throws PDOException If the update fails
     */
    public function increment(string $column, int $amount = 1): int
    {
        $column = $this->assertIdentifier($column);
        return $this->update([$column => new Raw("{$column} + {$amount}")]);
    }

    /**
     * Decrement a column's value.
     *
     * @param string $column
     * @param int    $amount
     * @return int Affected row count
     * @throws PDOException If the update fails
     */
    public function decrement(string $column, int $amount = 1): int
    {
        $column = $this->assertIdentifier($column);
        return $this->update([$column => new Raw("{$column} - {$amount}")]);
    }

    // ─── SQL Inspection ──────────────────────────────────────────

    /**
     * Compile and return the SELECT SQL without executing it.
     *
     * Useful for debugging and logging.
     *
     * @return string The compiled SELECT SQL query
     * @see getSql()
     */
    public function toSql(): string
    {
        return $this->compileSelect();
    }

    /**
     * Alias of toSql() for backwards compatibility.
     *
     * @return string
     * @see toSql()
     */
    public function getSql(): string
    {
        return $this->toSql();
    }

    // ─── SQL Compilation (Private) ──────────────────────────────

    /**
     * Compile the accumulated clauses into a SELECT SQL string.
     *
     * @return string
     */
    private function compileSelect(): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $columns = implode(', ', $this->select);
        $sql = "SELECT {$distinct}{$columns} FROM {$this->table}";

        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= ' ' . $join['sql'];
            }
        }

        $whereClause = $this->compileWheres();
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if (!empty($this->havings)) {
            $havingParts = array_column($this->havings, 'sql');
            $sql .= ' HAVING ' . implode(' AND ', $havingParts);
        }

        if (!empty($this->orders)) {
            $orderParts = array_map(
                fn(array $o) => "{$o['column']} {$o['direction']}",
                $this->orders
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Compile all WHERE clauses into a single SQL string.
     *
     * @return string
     */
    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $i => $where) {
            if ($i === 0) {
                $parts[] = $where['sql'];
            } else {
                $parts[] = "{$where['logic']} {$where['sql']}";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Collect all bindings from WHERE clauses.
     *
     * @return array<int, mixed>
     */
    private function getWhereBindings(): array
    {
        $bindings = [];
        foreach ($this->wheres as $where) {
            foreach ($where['bindings'] as $value) {
                if ($value instanceof Raw) {
                    continue;
                }
                $bindings[] = $value;
            }
        }
        return $bindings;
    }

    /**
     * Add a value to the bindings array and return its placeholder.
     *
     * @param mixed $value
     * @return string The ? placeholder (or raw expression string)
     */
    private function addBinding(mixed $value): string
    {
        if ($value instanceof Raw) {
            return (string) $value;
        }

        return '?';
    }

    /**
     * Validate a SQL identifier (table or column name).
     *
     * Identifiers cannot be bound as query parameters (see the PHP
     * manual entry for PDO::prepare), so when they originate from
     * untrusted input they must be validated against a strict
     * whitelist to prevent SQL injection.
     *
     * Allowed forms: a simple name (`user`) or a qualified name
     * with a single dot (`user.id`).
     *
     * @param string $name
     * @return string The validated identifier
     * @throws InvalidArgumentException If the identifier is invalid
     */
    private function assertIdentifier(string $name): string
    {
        if ($name === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier: {$name}");
        }

        return $name;
    }

    private function isOperator(mixed $value): bool
    {
        return in_array(
            strtoupper((string) $value),
            ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS', 'IS NOT'],
            true
        );
    }

    /**
     * Reset the builder state after execution.
     *
     * @return void
     */
    private function reset(): void
    {
        $this->select = ['*'];
        $this->distinct = false;
        $this->wheres = [];
        $this->joins = [];
        $this->groups = [];
        $this->havings = [];
        $this->orders = [];
        $this->limit = null;
        $this->offset = null;
    }
}
