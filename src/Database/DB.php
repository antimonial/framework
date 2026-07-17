<?php

declare(strict_types=1);

namespace Antimonial\Database;

use Antimonial\Core\Config;
use PDOException;

/**
 * Static database access facade.
 *
 * Provides a convenient shorthand for database operations.
 * NOT a magic facade — just static methods that delegate to
 * a shared Connection instance.
 *
 * @example
 *   DB::table('users')->where('active', true)->get();
 *   DB::beginTransaction();
 *   DB::table('users')->insert(['name' => 'John']);
 *   DB::commit();
 *
 * @see Connection
 * @see QueryBuilder
 */
class DB
{
    /**
     * Shared connection instance.
     */
    private static ?Connection $connection = null;

    /**
     * Get or create the database connection.
     *
     * Reads config from 'database.connections.{default}' on first call.
     *
     * @param  array<string, mixed>|null  $config  Optional config override
     * @return Connection The connection instance
     *
     * @throws PDOException If the connection fails
     *
     * @see Connection::__construct()
     */
    public static function connection(?array $config = null): Connection
    {
        if (self::$connection !== null && $config === null) {
            return self::$connection;
        }

        if ($config === null) {
            /** @var mixed $defaultRaw */
            $defaultRaw = Config::get('database.default', 'mysql');
            $default = is_string($defaultRaw) ? $defaultRaw : 'mysql';
            $dbConfig = Config::get("database.connections.{$default}", []);
            $config = is_array($dbConfig) ? $dbConfig : [];
        }

        /** @var array<string, mixed> $config */
        self::$connection = new Connection($config);

        return self::$connection;
    }

    /**
     * Create a QueryBuilder for the given table.
     *
     * @param  string  $table  Table name
     * @return QueryBuilder A new query builder instance
     *
     * @see QueryBuilder
     */
    public static function table(string $table): QueryBuilder
    {
        return self::connection()->table($table);
    }

    /**
     * Execute a raw SELECT query.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<object>
     *
     * @throws PDOException If the query fails
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    /**
     * Get the underlying Connection instance.
     *
     * @return Connection The connection instance
     *
     * @see Connection
     */
    public static function getConnection(): Connection
    {
        return self::connection();
    }

    /**
     * Begin a database transaction.
     *
     * @throws PDOException If the transaction cannot be started
     */
    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    /**
     * Commit the active transaction.
     *
     * @throws PDOException If the commit fails
     */
    public static function commit(): void
    {
        self::connection()->commit();
    }

    /**
     * Roll back the active transaction.
     *
     * @throws PDOException If the rollback fails
     */
    public static function rollBack(): void
    {
        self::connection()->rollBack();
    }

    /**
     * Create a Raw SQL expression.
     *
     * @see Raw
     * @see QueryBuilder::where()
     */
    public static function raw(string $expression): Raw
    {
        return new Raw($expression);
    }
}
