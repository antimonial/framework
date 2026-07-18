<?php

declare(strict_types=1);

namespace Antimonial\Database;

use PDOException;
use RuntimeException;

/**
 * Contract implemented by every migration object.
 *
 * A migration file returns an instance of this interface (typically an
 * anonymous class) so the Migrator can run and revert it.
 */
interface Migration
{
    public function up(Connection $db): void;

    public function down(Connection $db): void;
}

/**
 * Runs database migrations from a directory of PHP files.
 *
 * Each migration file is a plain PHP script that returns an anonymous
 * object exposing `up(Connection $db): void` and `down(Connection $db):
 * void`. Files are discovered via glob() on the migrations directory and
 * executed in lexical (filename) order — so prefix filenames with a
 * sortable timestamp, e.g. `2026_07_17_120000_create_users.php`.
 *
 * Applied migrations are tracked in a `migrations` table
 * (`id`, `migration`, `batch`, `ran_at`) that is created automatically if
 * it does not exist. There is deliberately no schema builder / column
 * DSL: each migration runs raw SQL through the Connection, consistent
 * with QueryBuilder being the only abstraction this project provides
 * over SQL.
 *
 * @see Connection
 * @see QueryBuilder
 */
final class Migrator
{
    /**
     * Name of the table that records applied migrations.
     */
    private const TABLE = 'migrations';

    /**
     * @param  Connection  $connection  Database connection
     * @param  string  $migrationsPath  Directory containing migration files
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsPath,
    ) {}

    /**
     * Run all pending migrations.
     *
     * Migrations already recorded in the `migrations` table are skipped.
     * Returns the list of migration filenames that were executed.
     *
     * @return string[] Filenames that were run
     *
     * @throws PDOException If a migration or the bookkeeping query fails
     */
    public function run(): array
    {
        $this->ensureTable();

        $applied = $this->appliedNames();
        $ran = [];
        $batch = 0;

        foreach ($this->discover() as $name) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = $this->load($name);
            $migration->up($this->connection);

            // Each run() that applies at least one migration uses a fresh
            // batch number, so a later rollback() reverts exactly this run.
            if ($ran === []) {
                $batch = $this->nextBatch();
            }

            $this->connection->execute(
                'INSERT INTO '.self::TABLE.' (migration, batch, ran_at) VALUES (?, ?, ?)',
                [$name, $batch, date('Y-m-d H:i:s')]
            );

            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Roll back the most recent batch of migrations.
     *
     * The batch with the highest `batch` number is reverted, in reverse
     * lexical order (so later migrations undo first). Returns the list of
     * migration names that were reverted.
     *
     * @return string[] Names that were reverted
     *
     * @throws PDOException If a rollback or the bookkeeping query fails
     */
    public function rollback(): array
    {
        $this->ensureTable();

        $batch = $this->latestBatch();
        if ($batch === 0) {
            return [];
        }

        /** @var array<object{name: string}> $rows */
        $rows = $this->connection->select(
            'SELECT migration AS name FROM '.self::TABLE.' WHERE batch = ? ORDER BY migration DESC',
            [$batch]
        );

        $reverted = [];
        foreach ($rows as $row) {
            $name = $row->name;
            $migration = $this->load($name);
            $migration->down($this->connection);

            $this->connection->executeWrite(
                'DELETE FROM '.self::TABLE.' WHERE migration = ?',
                [$name]
            );

            $reverted[] = $name;
        }

        return $reverted;
    }

    /**
     * Discover migration files, sorted lexically.
     *
     * @return string[] Migration filenames (basename without .php)
     */
    private function discover(): array
    {
        $pattern = rtrim($this->migrationsPath, '/\\').'/*.php';
        $files = glob($pattern) ?: [];

        $names = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files
        );

        sort($names);

        return $names;
    }

    /**
     * Load a migration file and return its up/down object.
     *
     * @throws RuntimeException If the file does not return a valid object
     */
    private function load(string $name): Migration
    {
        $path = rtrim($this->migrationsPath, '/\\').'/'.$name.'.php';

        if (! is_file($path)) {
            throw new RuntimeException("Migration file not found: {$path}");
        }

        $migration = require $path;

        if (! $migration instanceof Migration) {
            throw new RuntimeException(
                "Migration {$name} must return an object implementing Migration (with up() and down() methods)."
            );
        }

        return $migration;
    }

    /**
     * Create the migrations table if it does not exist.
     */
    private function ensureTable(): void
    {
        $driver = $this->connection->getDriver();
        $autoIncrement = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' ('
            .'id '.$autoIncrement.', '
            .'migration VARCHAR(255) NOT NULL, '
            .'batch INT NOT NULL, '
            .'ran_at DATETIME NOT NULL'
            .')'
        );
    }

    /**
     * Get the set of already-applied migration names.
     *
     * @return string[]
     */
    private function appliedNames(): array
    {
        /** @var array<object{migration: string}> $rows */
        $rows = $this->connection->select('SELECT migration FROM '.self::TABLE);

        return array_map(static fn (object $row): string => $row->migration, $rows);
    }

    /**
     * The next batch number (highest existing batch + 1).
     */
    private function nextBatch(): int
    {
        return $this->latestBatch() + 1;
    }

    /**
     * The highest batch number currently recorded (0 if none).
     */
    private function latestBatch(): int
    {
        /** @var array<object{batch: int}> $rows */
        $rows = $this->connection->select('SELECT MAX(batch) AS batch FROM '.self::TABLE);

        $value = $rows[0]->batch ?? 0;

        return (int) $value;
    }
}
