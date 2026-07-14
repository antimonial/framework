<?php

declare(strict_types=1);

namespace Antimonial\Database;

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
     *
     * @var \PDO|null
     */
    private ?\PDO $pdo = null;

    /**
     * Connection configuration.
     *
     * @var array{
     *     host: string,
     *     port: int,
     *     database: string,
     *     username: string,
     *     password: string,
     *     charset: string
     * }
     */
    private array $config;

    /**
     * @param array<string, mixed> $config Connection parameters
     */
    public function __construct(array $config)
    {
        $this->config = [
            'host'     => $config['host'] ?? '127.0.0.1',
            'port'     => (int) ($config['port'] ?? 3306),
            'database' => $config['database'] ?? '',
            'username' => $config['username'] ?? 'root',
            'password' => $config['password'] ?? '',
            'charset'  => $config['charset'] ?? 'utf8mb4',
        ];
    }

    /**
     * Establish the PDO connection.
     *
     * @return void
     * @throws \PDOException If the connection fails
     */
    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset'],
        );

        $this->pdo = new \PDO($dsn, $this->config['username'], $this->config['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Get the PDO instance, connecting if necessary.
     *
     * @return \PDO
     * @throws \PDOException If the connection fails
     */
    public function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    // ─── Query Execution ────────────────────────────────────────

    /**
     * Execute a SELECT query and return all rows.
     *
     * @param string $sql      SQL query with ? placeholders
     * @param array  $bindings Parameter values
     * @return object[] Array of stdClass objects
     * @throws \PDOException If the query fails
     */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->execute($sql, $bindings);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Execute an INSERT query.
     *
     * @param string $sql      SQL query with ? placeholders
     * @param array  $bindings Parameter values
     * @return string The last inserted row ID
     * @throws \PDOException If the query fails
     */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->execute($sql, $bindings);
        return $this->getPdo()->lastInsertId();
    }

    /**
     * Execute an UPDATE or DELETE query.
     *
     * @param string $sql      SQL query with ? placeholders
     * @param array  $bindings Parameter values
     * @return int Number of affected rows
     * @throws \PDOException If the query fails
     */
    public function executeWrite(string $sql, array $bindings = []): int
    {
        $stmt = $this->execute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Execute a raw SQL statement with bindings.
     *
     * @param string $sql
     * @param array  $bindings
     * @return \PDOStatement
     * @throws \PDOException If the query fails
     */
    public function execute(string $sql, array $bindings = []): \PDOStatement
    {
        $stmt = $this->getPdo()->prepare($sql);

        foreach ($bindings as $i => $value) {
            if (is_bool($value)) {
                $stmt->bindValue($i + 1, $value, \PDO::PARAM_BOOL);
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
     * @return string
     * @throws \PDOException If the connection is not established
     */
    public function lastInsertId(): string
    {
        return $this->getPdo()->lastInsertId();
    }

    // ─── Transactions ───────────────────────────────────────────

    /**
     * Begin a database transaction.
     *
     * @return void
     * @throws \PDOException If the transaction cannot be started
     */
    public function beginTransaction(): void
    {
        $this->getPdo()->beginTransaction();
    }

    /**
     * Commit the active transaction.
     *
     * @return void
     * @throws \PDOException If the commit fails
     */
    public function commit(): void
    {
        $this->getPdo()->commit();
    }

    /**
     * Roll back the active transaction.
     *
     * @return void
     * @throws \PDOException If the rollback fails
     */
    public function rollBack(): void
    {
        $this->getPdo()->rollBack();
    }

    // ─── Query Builder Factory ──────────────────────────────────

    /**
     * Create a QueryBuilder for the given table.
     *
     * @param string $table Table name
     * @return QueryBuilder
     * @see QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }
}
