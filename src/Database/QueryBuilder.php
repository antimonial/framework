<?php

declare(strict_types=1);

namespace Antimonial\Database;

use Closure;
use InvalidArgumentException;
use PDOException;

/**
 * Fluent SQL query builder.
 *
 * Builds SELECT, INSERT, UPDATE, and DELETE queries by accumulating
 * clauses in arrays, then compiling them to SQL at execution time.
 *
 * This is the largest class in the framework (~1,050 lines) because
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
     * @var array<int, array{logic: string, sql: string, bindings: array<int, mixed>}>
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
     * @var array<int, array{sql: string, bindings: array<int, mixed>}>
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
     * SQL comparison operators accepted by join() and having().
     *
     * @var list<string>
     */
    private const ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    /**
     * @param  Connection  $connection  Database connection
     * @param  string  $table  Table name
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
     * @param  string  ...$columns  Column names
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
     * Supports three forms:
     *  - where('column', value)              -> column = value
     *  - where('column', '>', value)         -> column > value
     *  - where(function ($q) { ... })        -> nested grouped conditions (AND)
     *
     * @example ->where('age', '>=', 18)
     * @example ->where('active', true)
     * @example ->where(function ($q) { $q->whereNull('assignee_id')->orWhere('priority', 'high'); })
     *
     * @param  string|Closure  $column  Column name or Closure for grouped conditions
     * @param  mixed  $operatorOrValue  Operator or value when using shorthand
     * @param  mixed  $value  Value when using operator form
     * @return $this
     */
    public function where(string|Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if ($column instanceof Closure) {
            $sub = new QueryBuilder($this->connection, $this->table);
            $column($sub);
            $sql = $sub->compileWheres();
            if ($sql !== '') {
                $this->wheres[] = [
                    'logic' => 'AND',
                    'sql' => '('.$sql.')',
                    'bindings' => $sub->getWhereBindings(),
                ];
            }

            return $this;
        }

        $column = $this->assertIdentifier($column);

        if ($value === null && ! $this->isOperator($operatorOrValue)) {
            $value = $operatorOrValue;
            /** @var string $operatorOrValue */
            $operatorOrValue = '=';
        }

        if (is_array($value)) {
            throw new InvalidArgumentException(
                'where() binds exactly one value; pass a scalar, or use whereIn()/whereNotIn()/whereBetween() for multiple values.'
            );
        }

        /** @var string $operatorOrValue */
        $operatorOrValue = is_string($operatorOrValue) ? $operatorOrValue : '';
        $placeholder = $this->addBinding($value);
        $this->wheres[] = [
            'logic' => 'AND',
            'sql' => "{$column} {$operatorOrValue} {$placeholder}",
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Add an OR WHERE clause.
     *
     * Supports two forms:
     *  - orWhere('column', value)             -> OR column = value
     *  - orWhere(function ($q) { ... })       -> nested grouped conditions (OR)
     *
     * @example ->where('active', true)->orWhere('role', 'admin')
     * @example ->where('name', 'foo')->orWhere(function ($q) { $q->where('age', 18)->where('active', true); })
     *
     * @param  string|Closure  $column  Column name or Closure for grouped conditions
     * @return $this
     */
    public function orWhere(string|Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if ($column instanceof Closure) {
            $sub = new QueryBuilder($this->connection, $this->table);
            $column($sub);
            $sql = $sub->compileWheres();
            if ($sql !== '') {
                $this->wheres[] = [
                    'logic' => 'OR',
                    'sql' => '('.$sql.')',
                    'bindings' => $sub->getWhereBindings(),
                ];
            }

            return $this;
        }

        $column = $this->assertIdentifier($column);

        if ($value === null && ! $this->isOperator($operatorOrValue)) {
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        }

        if (is_array($value)) {
            throw new InvalidArgumentException(
                'orWhere() binds exactly one value; pass a scalar, or use whereIn()/whereNotIn()/whereBetween() for multiple values.'
            );
        }

        /** @var string $operatorOrValue */
        $operatorOrValue = is_string($operatorOrValue) ? $operatorOrValue : '';
        $placeholder = $this->addBinding($value);
        $this->wheres[] = [
            'logic' => 'OR',
            'sql' => "{$column} ".$operatorOrValue." {$placeholder}",
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Add a WHERE IN clause.
     *
     * @example ->whereIn('id', [1, 2, 3])
     *
     * @param  array<int, mixed>  $values
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
            'logic' => 'AND',
            'sql' => "{$column} IN (".implode(', ', $placeholders).')',
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT IN clause.
     *
     * @param  array<int, mixed>  $values
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
            'logic' => 'AND',
            'sql' => "{$column} NOT IN (".implode(', ', $placeholders).')',
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause.
     *
     * @example ->whereBetween('age', 18, 65)
     *
     * @param  mixed  $min  Lower bound
     * @param  mixed  $max  Upper bound
     * @return $this
     */
    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $column = $this->assertIdentifier($column);
        $minPlaceholder = $this->addBinding($min);
        $maxPlaceholder = $this->addBinding($max);

        $this->wheres[] = [
            'logic' => 'AND',
            'sql' => "{$column} BETWEEN {$minPlaceholder} AND {$maxPlaceholder}",
            'bindings' => [$min, $max],
        ];

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause.
     *
     * @example ->whereNotBetween('age', 18, 65)
     *
     * @param  mixed  $min  Lower bound
     * @param  mixed  $max  Upper bound
     * @return $this
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $column = $this->assertIdentifier($column);
        $minPlaceholder = $this->addBinding($min);
        $maxPlaceholder = $this->addBinding($max);

        $this->wheres[] = [
            'logic' => 'AND',
            'sql' => "{$column} NOT BETWEEN {$minPlaceholder} AND {$maxPlaceholder}",
            'bindings' => [$min, $max],
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NULL clause.
     *
     * @example ->whereNull('deleted_at')
     *
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
     * @param  string  $sql  Raw SQL fragment (e.g. 'age > ? OR role = ?')
     * @param  array<int, mixed>  $bindings  Values for ? placeholders
     * @return $this
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = ['logic' => 'AND', 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    /**
     * Add a raw WHERE clause with OR logic.
     *
     * @example $query->orWhereRaw('age > ? OR role = ?', [18, 'admin']);
     *
     * @param  string  $sql  Raw SQL fragment (e.g. 'age > ? OR role = ?')
     * @param  array<int, mixed>  $bindings  Values for ? placeholders
     * @return $this
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = ['logic' => 'OR', 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    /**
     * Add a JOIN clause.
     *
     * @example ->join('posts', 'users.id', '=', 'posts.user_id')
     *
     * @param  string  $table  Join table name
     * @param  string  $col1  Left column
     * @param  string  $op  Operator (=, <, >, etc.)
     * @param  string  $col2  Right column
     * @param  string  $type  Join type (INNER, LEFT, RIGHT)
     * @return $this
     */
    public function join(string $table, string $col1, string $op, string $col2, string $type = 'INNER'): static
    {
        $table = $this->assertIdentifier($table);
        $col1 = $this->assertIdentifier($col1);
        $col2 = $this->assertIdentifier($col2);

        if (! in_array(strtoupper($op), self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid JOIN operator: {$op}");
        }

        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'sql' => "{$type} JOIN {$table} ON {$col1} {$op} {$col2}",
        ];

        return $this;
    }

    /**
     * Shorthand for a LEFT JOIN.
     *
     * @example ->leftJoin('posts', 'users.id', '=', 'posts.user_id')
     *
     * @return $this
     */
    public function leftJoin(string $table, string $col1, string $op, string $col2): static
    {
        return $this->join($table, $col1, $op, $col2, 'LEFT');
    }

    /**
     * Add a GROUP BY clause.
     *
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
     * @return $this
     */
    public function having(string $column, string $operator, mixed $value): static
    {
        $column = $this->assertIdentifier($column);

        if (! in_array(strtoupper($operator), self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid HAVING operator: {$operator}");
        }

        $placeholder = $this->addBinding($value);
        $this->havings[] = [
            'sql' => "{$column} {$operator} {$placeholder}",
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Add an ORDER BY clause.
     *
     * @example ->orderBy('name', 'DESC')
     *
     * @param  string  $direction  'ASC' or 'DESC'
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
     * @return array<object>
     *
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
     * Paginate query results.
     *
     * Clones the builder before counting so the current state is preserved
     * for the actual data query.
     *
     * Returns a stdClass with: items, total, perPage, currentPage, totalPages.
     *
     * @example
     *   $result = DB::table('posts')
     *       ->where('status', 'published')
     *       ->orderBy('created_at', 'DESC')
     *       ->paginate(10, $page);
     *
     * @param  int  $perPage  Results per page
     * @param  int  $page  Current page number (1-based)
     * @return object{items: array<object>, total: int, perPage: int, currentPage: int, totalPages: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): object
    {
        $page = max(1, $page);

        $total = (clone $this)->count();

        $offset = ($page - 1) * $perPage;
        $items = $this->limit($perPage)->offset($offset)->get();

        return (object) [
            'items' => $items,
            'total' => $total,
            'perPage' => $perPage,
            'currentPage' => $page,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Get the first result row, or null if none found.
     *
     * @return ?object First row or null
     *
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
     * @param  mixed  $id  Primary key value
     * @return ?object Found row or null
     *
     * @throws PDOException If the query fails
     *
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
     * @param  string  $column  Column name
     * @return mixed Column value, or null if no row matched
     *
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
     * @param  string  $key  Column to use as array key
     * @param  string|null  $valueKey  Column to use as array value (null = entire row)
     * @return array<int|string, mixed>
     *
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
            /** @var int|string $k */
            $plucked[$k] = $valueKey !== null ? ($row->$valueKey ?? null) : $row;
        }

        return $plucked;
    }

    /**
     * Count the number of rows matching the query.
     *
     * @return int Row count
     *
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

        /** @var object{aggregate?: mixed} $first */
        $first = $result[0];
        $aggregate = $first->aggregate ?? 0;

        return is_numeric($aggregate) ? (int) $aggregate : 0;
    }

    /**
     * Check if any rows match the query.
     *
     * @return bool True if at least one row matches
     *
     * @throws PDOException If the query fails
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Calculate the sum of a column.
     *
     * @param  string  $column  Column name
     * @return float Sum value
     *
     * @throws PDOException If the query fails
     */
    public function sum(string $column): float
    {
        $value = $this->aggregate("SUM({$column})");

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Calculate the average of a column.
     *
     * @param  string  $column  Column name
     * @return float Average value
     *
     * @throws PDOException If the query fails
     */
    public function avg(string $column): float
    {
        $value = $this->aggregate("AVG({$column})");

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Get the minimum value of a column.
     *
     * @param  string  $column  Column name
     * @return mixed Minimum value, or null if no rows matched
     *
     * @throws PDOException If the query fails
     */
    public function min(string $column): mixed
    {
        return $this->aggregate("MIN({$column})");
    }

    /**
     * Get the maximum value of a column.
     *
     * @param  string  $column  Column name
     * @return mixed Maximum value, or null if no rows matched
     *
     * @throws PDOException If the query fails
     */
    public function max(string $column): mixed
    {
        return $this->aggregate("MAX({$column})");
    }

    /**
     * Run a single-column aggregate query and return its value.
     *
     * @param  string  $expression  Aggregate expression (e.g. 'SUM(amount)')
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
     * @param  array<string, mixed>  $data  Column => value pairs
     * @return string Last inserted ID
     *
     * @throws PDOException If the insert fails
     *
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
        $filteredBindings = array_values(array_filter($bindings, fn ($v) => ! $v instanceof Raw));
        $id = $this->connection->insert($sql, $filteredBindings);
        $this->reset();

        return $id;
    }

    /**
     * Update rows and return the number of affected rows.
     *
     * @example DB::table('users')->where('id', 42)->update(['name' => 'Jane']);
     *
     * @param  array<string, mixed>  $data  Column => value pairs
     * @return int Affected row count
     *
     * @throws PDOException If the update fails
     *
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
        $sql = "UPDATE {$this->table} SET {$setClause}".($whereClause !== '' ? " WHERE {$whereClause}" : '');

        $bindings = array_merge($bindings, $this->getWhereBindings());
        $bindings = array_values(array_filter($bindings, fn ($v) => ! $v instanceof Raw));
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
     *
     * @throws PDOException If the delete fails
     *
     * @see Model::delete()
     */
    public function delete(): int
    {
        $whereClause = $this->compileWheres();
        $sql = "DELETE FROM {$this->table}".($whereClause !== '' ? " WHERE {$whereClause}" : '');

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
     * @param  string  $column  Column name
     * @param  int  $amount  Amount to increment by
     * @return int Affected row count
     *
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
     * @param  string  $column  Column name
     * @param  int  $amount  Amount to decrement by
     * @return int Affected row count
     *
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
     */
    public function getSql(): string
    {
        return $this->compileSelect();
    }

    // ─── SQL Compilation (Private) ──────────────────────────────

    /**
     * Compile the accumulated clauses into a SELECT SQL string.
     *
     * @return string Compiled SELECT SQL
     */
    private function compileSelect(): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $columns = implode(', ', $this->select);
        $sql = "SELECT {$distinct}{$columns} FROM {$this->table}";

        if (! empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= ' '.$join['sql'];
            }
        }

        $whereClause = $this->compileWheres();
        if ($whereClause !== '') {
            $sql .= ' WHERE '.$whereClause;
        }

        if (! empty($this->groups)) {
            $sql .= ' GROUP BY '.implode(', ', $this->groups);
        }

        if (! empty($this->havings)) {
            $havingParts = array_column($this->havings, 'sql');
            $sql .= ' HAVING '.implode(' AND ', $havingParts);
        }

        if (! empty($this->orders)) {
            $orderParts = array_map(
                fn (array $o) => "{$o['column']} {$o['direction']}",
                $this->orders
            );
            $sql .= ' ORDER BY '.implode(', ', $orderParts);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT '.$this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET '.$this->offset;
        }

        return $sql;
    }

    /**
     * Compile all WHERE clauses into a single SQL string.
     *
     * @return string Compiled WHERE SQL (empty string if no clauses)
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
     * @return string The validated identifier
     *
     * @throws InvalidArgumentException If the identifier is invalid
     */
    private function assertIdentifier(string $name): string
    {
        if ($name === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier: {$name}");
        }

        return $name;
    }

    /**
     * Check if a value is a recognized SQL comparison operator.
     *
     * Used by where()/orWhere() to decide whether the second argument
     * is an operator (e.g. '>') or a bare value for an implicit '='.
     *
     * @param  mixed  $value  Value to check
     * @return bool True if the value is a known SQL operator
     */
    private function isOperator(mixed $value): bool
    {
        $normalized = is_string($value) ? strtoupper($value) : '';

        return in_array($normalized, self::ALLOWED_OPERATORS, true);
    }

    /**
     * Reset the builder state after execution.
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
