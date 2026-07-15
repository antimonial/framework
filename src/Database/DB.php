<?php

declare(strict_types=1);

namespace Antimonial\Database;

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
     *
     * @var Connection|null
     */
    private static ?Connection $connection = null;

    /**
     * Get or create the database connection.
     *
     * Reads config from 'database.connections.{default}' on first call.
     *
     * @param array<string, mixed>|null $config Optional config override
     * @return Connection
     * @throws PDOException If the connection fails
     * @see Connection::__construct()
     */
    public static function connection(?array $config = null): Connection
    {
        if (self::$connection !== null && $config === null) {
            return self::$connection;
        }

        if ($config === null) {
            $default = Config::get('database.default', 'mysql');
            $config = Config::get("database.connections.{$default}", []);
        }

        self::$connection = new Connection($config);
        return self::$connection;
    }

    /**
     * Create a QueryBuilder for the given table.
     *
     * @param string $table
     * @return QueryBuilder
     * @see QueryBuilder
     */
    public static function table(string $table): QueryBuilder
    {
        return self::connection()->table($table);
    }

    /**
     * Execute a raw SELECT query.
     *
     * @param string $sql
     * @param array  $bindings
     * @return object[]
     * @throws PDOException If the query fails
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    /**
     * Get the underlying Connection instance.
     *
     * @return Connection
     * @see Connection
     */
    public static function getConnection(): Connection
    {
        return self::connection();
    }

    /**
     * Begin a database transaction.
     *
     * @return void
     * @throws PDOException If the transaction cannot be started
     */
    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    /**
     * Commit the active transaction.
     *
     * @return void
     * @throws PDOException If the commit fails
     */
    public static function commit(): void
    {
        self::connection()->commit();
    }

    /**
     * Roll back the active transaction.
     *
     * @return void
     * @throws PDOException If the rollback fails
     */
    public static function rollBack(): void
    {
        self::connection()->rollBack();
    }

    /**
     * Create a Raw SQL expression.
     *
     * @param string $expression
     * @return Raw
     * @see Raw
     * @see QueryBuilder::where()
     */
    public static function raw(string $expression): Raw
    {
        return new Raw($expression);
    }
}
