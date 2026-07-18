<?php

declare(strict_types=1);

namespace Antimonial\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO database connection wrapper.
 *
 * Provides a thin abstraction over PDO with lazy connection,
 * query execution helpers, and transaction management.
 *
 * The connection is NOT established on construction — it connects
 * lazily on the first query. This avoids unnecessary connections
 * when the request doesn't touch the database.
 *
 * @example
 *   $db = new Connection([
 *       'host'     => '127.0.0.1',
 *       'database' => 'myapp',
 *       'username' => 'root',
 *       'password' => '',
 *   ]);
 *   $results = $db->select('SELECT * FROM users WHERE id = ?', [42]);
 */
class Connection
{
    /**
     * The PDO instance (null until connected).
     */
    private ?PDO $pdo = null;

    /**
     * Connection configuration (normalized in the constructor).
     *
     * @var array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string}
     */
    private array $config;

    /**
     * @param  array<string, mixed>  $config  Connection parameters
     */
    public function __construct(array $config)
    {
        $driver = is_string($config['driver'] ?? null) ? (string) $config['driver'] : 'mysql';
        $username = is_string($config['username'] ?? null) ? (string) $config['username'] : 'root';
        $password = is_string($config['password'] ?? null) ? (string) $config['password'] : '';
        $host = is_string($config['host'] ?? null) ? (string) $config['host'] : '127.0.0.1';
        $database = is_string($config['database'] ?? null) ? (string) $config['database'] : '';
        $charset = is_string($config['charset'] ?? null) ? (string) $config['charset'] : 'utf8mb4';
        $port = is_numeric($config['port'] ?? null) ? (int) $config['port'] : 3306;

        $this->config = [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
        ];
    }

    /**
     * Establish the PDO connection.
     *
     * @throws PDOException If the connection fails
     */
    private function connect(): void
    {
        $driver = $this->config['driver'];
        $host = $this->config['host'];
        $port = $this->config['port'];
        $database = $this->config['database'];
        $username = $this->config['username'];
        $password = $this->config['password'];
        $charset = $this->config['charset'];

        $dsn = match ($driver) {
            'sqlite' => 'sqlite:'.($database !== '' ? $database : ':memory:'),
            default => sprintf(
                '%s:host=%s;port=%d;dbname=%s;charset=%s',
                $driver,
                $host,
                $port,
                $database,
                $charset,
            ),
        };

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Get the PDO instance, connecting if necessary.
     *
     * @throws PDOException If the connection fails
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        /** @var PDO $pdo */
        $pdo = $this->pdo;

        return $pdo;
    }

    /**
     * Get the configured database driver name (e.g. 'mysql', 'sqlite').
     *
     * Useful for driver-specific DDL (e.g. auto-increment column syntax).
     */
    public function getDriver(): string
    {
        return $this->config['driver'];
    }

    // ─── Query Execution ────────────────────────────────────────

    /**
     * Execute a SELECT query and return all rows.
     *
     * @param  string  $sql  SQL query with ? placeholders
     * @param  array<int, mixed>  $bindings  Parameter values
     * @return array<object> Array of stdClass objects
     *
     * @throws PDOException If the query fails
     */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->execute($sql, $bindings);

        /** @var array<object> $rows */
        $rows = $stmt->fetchAll() ?: [];

        return $rows;
    }

    /**
     * Execute an INSERT query.
     *
     * @param  string  $sql  SQL query with ? placeholders
     * @param  array<int, mixed>  $bindings  Parameter values
     * @return string The last inserted row ID
     *
     * @throws PDOException If the query fails
     */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->execute($sql, $bindings);
        $id = $this->getPdo()->lastInsertId();

        return is_string($id) ? $id : '';
    }

    /**
     * Execute an UPDATE or DELETE query.
     *
     * @param  string  $sql  SQL query with ? placeholders
     * @param  array<int, mixed>  $bindings  Parameter values
     * @return int Number of affected rows
     *
     * @throws PDOException If the query fails
     */
    public function executeWrite(string $sql, array $bindings = []): int
    {
        $stmt = $this->execute($sql, $bindings);

        return $stmt->rowCount();
    }

    /**
     * Execute a raw SQL statement with bindings.
     *
     * @param  string  $sql  SQL query with ? placeholders
     * @param  array<int, mixed>  $bindings  Parameter values
     * @return PDOStatement The prepared and executed statement
     *
     * @throws PDOException If the query fails
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->getPdo()->prepare($sql);

        foreach ($bindings as $i => $value) {
            if (is_bool($value)) {
                $stmt->bindValue($i + 1, $value, PDO::PARAM_BOOL);
            } else {
                $stmt->bindValue($i + 1, $value);
            }
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * Get the last inserted row ID.
     *
     * @throws PDOException If the connection is not established
     */
    public function lastInsertId(): string
    {
        $id = $this->getPdo()->lastInsertId();

        return is_string($id) ? $id : '';
    }

    // ─── Transactions ───────────────────────────────────────────

    /**
     * Begin a database transaction.
     *
     * @throws PDOException If the transaction cannot be started
     */
    public function beginTransaction(): void
    {
        $this->getPdo()->beginTransaction();
    }

    /**
     * Commit the active transaction.
     *
     * @throws PDOException If the commit fails
     */
    public function commit(): void
    {
        $this->getPdo()->commit();
    }

    /**
     * Roll back the active transaction.
     *
     * @throws PDOException If the rollback fails
     */
    public function rollBack(): void
    {
        $this->getPdo()->rollBack();
    }

    // ─── Query Builder Factory ──────────────────────────────────

    /**
     * Create a QueryBuilder for the given table.
     *
     * @param  string  $table  Table name
     *
     * @see QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }
}
