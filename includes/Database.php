<?php
/**
 * Database.php — PDO Database Wrapper
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Database
{
    private static ?PDO $instance = null;

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
            ]);
        }

        return self::$instance;
    }

    /**
     * Execute a query and return all results.
     */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return a single row.
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an INSERT/UPDATE/DELETE and return affected row count.
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row and return the last insert ID.
     */
    public static function insert(string $table, array $data): int
    {
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
        $columns = implode(', ', array_map(fn($col) => "`$col`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $updates = implode(', ', array_map(fn($col) => "`$col` = VALUES(`$col`)", $updateColumns));

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
        return self::execute($sql, array_values($data));
    }
}
