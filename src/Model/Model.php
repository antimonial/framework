<?php

declare(strict_types=1);

namespace Antimonial\Model;

use Antimonial\Database\Connection;
use Antimonial\Database\DB;
use Antimonial\Database\QueryBuilder;
use PDOException;
use RuntimeException;

/**
 * Base model class.
 *
 * A thin CRUD wrapper around QueryBuilder. Models map to database
 * tables and provide convenience methods for common operations.
 *
 * This is intentionally NOT an ORM. There is no relation inference,
 * no attribute casting and no change tracking. You see exactly what
 * queries run, and you can always drop down to the QueryBuilder for
 * complex queries.
 *
 * The framework never guesses the table for you: you must declare it
 * explicitly on every model. That is the whole point — you tell the
 * framework what to do instead of it guessing.
 *
 * @example
 *   class User extends Model
 *   {
 *       protected string $table = 'users';
 *       protected bool $timestamps = true;
 *   }
 *
 *   $user = new User();
 *   $user->find(42);
 *   $user->where('active', true)->query()->orderBy('name')->get();
 *
 * @see QueryBuilder
 * @see DB
 */
class Model
{
    /**
     * Database table name.
     *
     * Required. The model throws at construction time if left empty —
     * the framework does not infer table names from class names.
     */
    protected string $table = '';

    /**
     * Primary key column name.
     *
     * Defaults to 'id'. Override it if your table uses a different key.
     */
    protected string $primaryKey = 'id';

    /**
     * Whether to auto-manage created_at/updated_at timestamps.
     */
    protected bool $timestamps = false;

    /**
     * The database connection instance.
     */
    protected ?Connection $db = null;

    /**
     * @param  Connection|null  $db  Optional connection override
     *
     * @throws RuntimeException If $table is not declared on the model
     */
    public function __construct(?Connection $db = null)
    {
        $this->db = $db;

        if ($this->table === '') {
            throw new RuntimeException(
                sprintf(
                    'Model %s must declare a $table property; the framework does not infer table names.',
                    static::class
                )
            );
        }
    }

    /**
     * Get a QueryBuilder scoped to this model's table.
     *
     * This is the main entry point for custom queries.
     *
     * @throws PDOException If the database connection fails
     *
     * @see QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return $this->getConnection()->table($this->table)->setPrimaryKey($this->primaryKey);
    }

    /**
     * Find a row by its primary key.
     *
     * @example $user = (new User())->find(42);
     *
     * @throws PDOException If the query fails
     *
     * @see QueryBuilder::find()
     */
    public function find(mixed $id): ?object
    {
        return $this->query()->find($id);
    }

    /**
     * Get all rows from the table.
     *
     * @return object[]
     *
     * @throws PDOException If the query fails
     */
    public function all(): array
    {
        return $this->query()->get();
    }

    /**
     * Start a WHERE query on this model's table.
     *
     * Convenience wrapper that returns a QueryBuilder for chaining.
     *
     * @example $user->where('active', true)->query()->orderBy('name')->get();
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        return $this->query()->where($column, $operatorOrValue, $value);
    }

    /**
     * Insert a row and return the last inserted ID.
     *
     * @example $id = (new User())->insert(['name' => 'John', 'email' => 'john@...']);
     *
     * @param  array<string, mixed>  $data
     * @return string Last inserted ID
     *
     * @throws PDOException If the insert fails
     *
     * @see QueryBuilder::insert()
     */
    public function insert(array $data): string
    {
        if ($this->timestamps) {
            $this->addTimestamps($data, true);
        }

        return $this->query()->insert($data);
    }

    /**
     * Update a row by its primary key.
     *
     * Example: (new User())->update(42, ['name' => 'Jane']);
     *
     * @param  array<string, mixed>  $data
     * @return int Affected row count
     *
     * @throws PDOException If the update fails
     *
     * @see QueryBuilder::update()
     */
    public function update(mixed $id, array $data): int
    {
        if ($this->timestamps) {
            $this->addTimestamps($data, false);
        }

        return $this->query()
            ->where($this->primaryKey, $id)
            ->update($data);
    }

    /**
     * Delete a row by its primary key.
     *
     * Example: (new User())->delete(42);
     *
     * @return int Affected row count
     *
     * @throws PDOException If the delete fails
     *
     * @see QueryBuilder::delete()
     */
    public function delete(mixed $id): int
    {
        return $this->query()
            ->where($this->primaryKey, $id)
            ->delete();
    }

    /**
     * Get the database connection, creating one if needed.
     *
     * @throws PDOException If the connection fails
     *
     * @see DB::connection()
     */
    protected function getConnection(): Connection
    {
        if ($this->db === null) {
            $this->db = DB::connection();
        }

        return $this->db;
    }

    /**
     * Add timestamp fields to the data array.
     *
     * @param  array<string, mixed>  &$data
     * @param  bool  $isInsert  True for INSERT, false for UPDATE
     */
    private function addTimestamps(array &$data, bool $isInsert): void
    {
        $now = date('Y-m-d H:i:s');

        if ($isInsert) {
            $data['created_at'] = $now;
        }

        $data['updated_at'] = $now;
    }
}
