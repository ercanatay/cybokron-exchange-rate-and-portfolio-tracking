<?php
/**
 * Cybokron Database Migrator
 *
 * Automated migration runner with versioning, checksum tracking, and transaction safety.
 *
 * Usage:
 *   php database/migrator.php                  # Run pending migrations
 *   php database/migrator.php --status         # Show migration status
 *   php database/migrator.php --dry-run        # Show what would run without executing
 *   php database/migrator.php --mark-applied   # Mark all pending as applied (skip execution)
 *
 * Migration files: database/migrations/NNN_description.sql
 * Rollback files:  database/migrations/NNN_description.down.sql (optional)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Migration must run from CLI.');
}

// Load config only (no helpers/scraper/auth dependencies needed for migrations)
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    die("Configuration file not found: {$configFile}\n");
}
require_once $configFile;

// Create a standalone PDO connection for migrations.
// Uses EMULATE_PREPARES=true and buffered queries to avoid
// "unbuffered queries" errors on shared hosting.
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => true,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);
$migrationsDir = __DIR__ . '/migrations';
$exitCode = 0;

// ─── Parse arguments ────────────────────────────────────────────────────────
$showStatus   = in_array('--status', $argv ?? [], true);
$dryRun       = in_array('--dry-run', $argv ?? [], true);
$markApplied  = in_array('--mark-applied', $argv ?? [], true);

// ─── Ensure schema_migrations table exists ──────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename` VARCHAR(255) NOT NULL UNIQUE,
    `checksum` VARCHAR(32) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `execution_time_ms` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Collect migration files (only .sql, not .down.sql) ─────────────────────
$files = glob($migrationsDir . '/*.sql');
if ($files === false) {
    $files = [];
}

// Filter out .down.sql rollback files
$migrationFiles = [];
foreach ($files as $file) {
    $basename = basename($file);
    if (preg_match('/\.down\.sql$/', $basename)) {
        continue;
    }
    $migrationFiles[$basename] = $file;
}
ksort($migrationFiles, SORT_NATURAL);

// ─── Load applied migrations ────────────────────────────────────────────────
$applied = [];
$stmt = $pdo->query("SELECT filename, checksum FROM schema_migrations ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();
foreach ($rows as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

// ─── Status mode ────────────────────────────────────────────────────────────
if ($showStatus) {
    echo "Migration Status\n";
    echo str_repeat('─', 70) . "\n";
    printf("%-40s %-10s %s\n", 'File', 'Status', 'Checksum');
    echo str_repeat('─', 70) . "\n";

    foreach ($migrationFiles as $basename => $filepath) {
        $checksum = md5_file($filepath);
        if (isset($applied[$basename])) {
            $status = ($applied[$basename] === $checksum) ? 'applied' : 'MODIFIED';
            printf("%-40s %-10s %s\n", $basename, $status, substr($checksum, 0, 8));
        } else {
            printf("%-40s %-10s %s\n", $basename, 'pending', substr($checksum, 0, 8));
        }
    }
    exit(0);
}

// ─── Find pending migrations ────────────────────────────────────────────────
$pending = [];
$warnings = [];

foreach ($migrationFiles as $basename => $filepath) {
    $checksum = md5_file($filepath);

    if (isset($applied[$basename])) {
        if ($applied[$basename] !== $checksum) {
            $warnings[] = "WARNING: {$basename} has been modified after application (checksum mismatch)";
        }
        continue;
    }

    $pending[] = ['filename' => $basename, 'filepath' => $filepath, 'checksum' => $checksum];
}

// ─── Show warnings ──────────────────────────────────────────────────────────
foreach ($warnings as $w) {
    echo "{$w}\n";
}

// ─── Nothing to do? ─────────────────────────────────────────────────────────
if (empty($pending)) {
    echo "No pending migrations.\n";
    exit(0);
}

echo ($dryRun ? "[DRY RUN] " : ($markApplied ? "[MARK APPLIED] " : "")) . count($pending) . " pending migration(s) found.\n";

// ─── Mark-applied mode (register without executing) ─────────────────────────
if ($markApplied) {
    foreach ($pending as $migration) {
        $insertStmt = $pdo->prepare(
            "INSERT IGNORE INTO schema_migrations (filename, checksum, execution_time_ms) VALUES (?, ?, 0)"
        );
        $insertStmt->execute([$migration['filename'], $migration['checksum']]);
        echo "  [MARKED] {$migration['filename']}\n";
    }
    echo "\nAll pending migrations marked as applied (not executed).\n";
    exit(0);
}

// ─── Run migrations ─────────────────────────────────────────────────────────
$appliedCount = 0;

foreach ($pending as $migration) {
    $basename = $migration['filename'];
    $filepath = $migration['filepath'];
    $checksum = $migration['checksum'];

    if ($dryRun) {
        echo "  [PENDING] {$basename}\n";
        continue;
    }

    echo "  Applying {$basename} ... ";

    $sql = file_get_contents($filepath);
    if ($sql === false || trim($sql) === '') {
        echo "SKIP (empty file)\n";
        continue;
    }

    // Remove USE statements (database is already selected via config)
    $sql = preg_replace('/^\s*USE\s+`[^`]+`\s*;\s*$/mi', '', $sql);

    $startTime = hrtime(true);

    try {
        // Note: MySQL DDL statements (CREATE TABLE, ALTER TABLE, etc.) cause
        // implicit commits, so we cannot wrap them in a transaction.
        // We execute statements directly and record success afterward.
        $statements = splitSqlStatements($sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }

        $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Record in schema_migrations
        $insertStmt = $pdo->prepare(
            "INSERT INTO schema_migrations (filename, checksum, execution_time_ms) VALUES (?, ?, ?)"
        );
        $insertStmt->execute([$basename, $checksum, $elapsedMs]);

        echo "OK ({$elapsedMs}ms)\n";
        $appliedCount++;
    } catch (Throwable $e) {
        echo "FAILED\n";
        echo "    Error: {$e->getMessage()}\n";
        $exitCode = 1;
        // Stop on first failure - don't apply subsequent migrations
        break;
    }
}

if (!$dryRun) {
    echo "\nMigration complete: {$appliedCount} applied, "
        . (count($pending) - $appliedCount) . " remaining.\n";
}

exit($exitCode);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Split a SQL string into individual statements.
 * Respects quoted strings and comments.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $char = $sql[$i];

        // Single-line comment: -- or #
        if (($char === '-' && $i + 1 < $len && $sql[$i + 1] === '-')
            || $char === '#') {
            $end = strpos($sql, "\n", $i);
            if ($end === false) {
                break;
            }
            $i = $end + 1;
            continue;
        }

        // Multi-line comment: /* ... */
        if ($char === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) {
                break;
            }
            $i = $end + 2;
            continue;
        }

        // Quoted string
        if ($char === '\'' || $char === '"') {
            $quote = $char;
            $current .= $char;
            $i++;
            while ($i < $len) {
                if ($sql[$i] === '\\') {
                    $current .= $sql[$i] . ($sql[$i + 1] ?? '');
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === $quote) {
                    $current .= $sql[$i];
                    $i++;
                    break;
                }
                $current .= $sql[$i];
                $i++;
            }
            continue;
        }

        // Backtick-quoted identifier
        if ($char === '`') {
            $end = strpos($sql, '`', $i + 1);
            if ($end === false) {
                $current .= substr($sql, $i);
                break;
            }
            $current .= substr($sql, $i, $end - $i + 1);
            $i = $end + 1;
            continue;
        }

        // Statement delimiter
        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $char;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}
