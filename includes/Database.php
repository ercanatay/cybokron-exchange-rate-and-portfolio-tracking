<?php
/**
 * Database.php — PDO Database Wrapper
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Database
{
    private static ?PDO $instance = null;
    /** @var array<string, PDOStatement> */
    private static array $statementCache = [];
    private const STATEMENT_CACHE_MAX = 100;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => defined('DB_PERSISTENT') ? (bool) DB_PERSISTENT : false,
            ]);

            self::applySessionTimezone(self::$instance);
        }

        return self::$instance;
    }

    /**
     * Keep MySQL session timezone aligned with app timezone so CURRENT_TIMESTAMP/NOW()
     * produce consistent values across PHP-generated and DB-generated datetimes.
     */
    private static function applySessionTimezone(PDO $pdo): void
    {
        if (!defined('APP_TIMEZONE')) {
            return;
        }

        $appTimezone = trim((string) APP_TIMEZONE);
        if ($appTimezone === '') {
            return;
        }

        try {
            $timezone = new DateTimeZone($appTimezone);
            $offset = (new DateTimeImmutable('now', $timezone))->format('P');
            $stmt = $pdo->prepare('SET time_zone = ?');
            $stmt->execute([$offset]);
        } catch (Throwable $e) {
            // Best effort only; keep DB connection usable even if timezone fails.
        }
    }

    /**
     * Execute callback in a transaction.
     */
    public static function runInTransaction(callable $callback)
    {
        $pdo = self::getInstance();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Execute a query and return all results.
     */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return a single row.
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an INSERT/UPDATE/DELETE and return affected row count.
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row and return the last insert ID.
     */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        self::assertIdentifiers(array_keys($data));

        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        self::execute($sql, array_values($data));

        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * Update rows and return affected count.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifier($table);
        self::assertIdentifiers(array_keys($data));

        $set = implode(', ', array_map(fn($col) => "`$col` = ?", array_keys($data)));
        $sql = "UPDATE `$table` SET $set WHERE $where";
        $params = array_merge(array_values($data), $whereParams);

        return self::execute($sql, $params);
    }

    /**
     * Upsert (INSERT ... ON DUPLICATE KEY UPDATE).
     */
    public static function upsert(string $table, array $data, array $updateColumns): int
    {
        self::assertIdentifier($table);
        self::assertIdentifiers(array_keys($data));
        self::assertIdentifiers($updateColumns);

        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $updates = implode(', ', array_map(fn($col) => "`$col` = VALUES(`$col`)", $updateColumns));

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
        return self::execute($sql, array_values($data));
    }

    /**
     * Prepare and memoize statement for the current request.
     */
    private static function prepare(string $sql): PDOStatement
    {
        if (!isset(self::$statementCache[$sql])) {
            // Evict oldest entries when cache exceeds max size
            if (count(self::$statementCache) >= self::STATEMENT_CACHE_MAX) {
                $evictCount = (int) (self::STATEMENT_CACHE_MAX * 0.25);
                self::$statementCache = array_slice(self::$statementCache, $evictCount, null, true);
            }
            self::$statementCache[$sql] = self::getInstance()->prepare($sql);
        }

        return self::$statementCache[$sql];
    }

    /**
     * Validate SQL identifier names.
     */
    private static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
    }

    /**
     * Validate multiple SQL identifiers.
     */
    private static function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            self::assertIdentifier((string) $identifier);
        }
    }
}
