<?php
/**
 * EnformaSys Full Database Backup Helper
 *
 * Uses mysqldump when available and falls back cleanly to a pure-PHP mysqli
 * SQL exporter (tables, views, triggers, and UTF-8 row data).
 */

if (!function_exists('backup_find_mysqldump')) {
    function backup_find_mysqldump(): ?string
    {
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:/xampp/mysql/bin/mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }
        return null;
    }
}

if (!function_exists('backup_sql_quote_ident')) {
    function backup_sql_quote_ident(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('backup_sql_format_value')) {
    /**
     * Format a column value safely for INSERT statements.
     *
     * @param mixed $val
     */
    function backup_sql_format_value(mysqli $conn, $val, object $fieldMeta): string
    {
        if ($val === null) {
            return 'NULL';
        }
        $type = (int)($fieldMeta->type ?? 0);
        // Numeric MySQL types: TINY(1), SHORT(2), LONG(3), FLOAT(4), DOUBLE(5),
        // LONGLONG(8), INT24(9), BIT(16)
        $numericTypes = [1, 2, 3, 4, 5, 8, 9];
        if (in_array($type, $numericTypes, true) && is_numeric((string)$val)) {
            return (string)$val;
        }
        // Binary flags (128 = BINARY_FLAG)
        if (((int)($fieldMeta->flags ?? 0) & 128) !== 0 && $type >= 249 && $type <= 252) {
            $strVal = (string)$val;
            return $strVal === '' ? "''" : ('0x' . bin2hex($strVal));
        }
        return "'" . $conn->real_escape_string((string)$val) . "'";
    }
}

if (!function_exists('backup_export_via_mysqldump')) {
    function backup_export_via_mysqldump(string $destPath, array $dbConfig): array
    {
        $bin = backup_find_mysqldump();
        if ($bin === null) {
            return ['success' => false, 'error' => 'mysqldump binary not found.'];
        }

        $host = (string)($dbConfig['host'] ?? '127.0.0.1');
        $user = (string)($dbConfig['user'] ?? 'root');
        $pass = (string)($dbConfig['pass'] ?? '');
        $name = (string)($dbConfig['name'] ?? 'seminary_db');

        $cmd = [
            $bin,
            '--host=' . $host,
            '--user=' . $user,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            '--hex-blob',
            '--default-character-set=utf8mb4',
        ];
        if ($pass !== '') {
            $cmd[] = '--password=' . $pass;
        }
        $cmd[] = $name;

        $outHandle = @fopen($destPath, 'wb');
        if ($outHandle === false) {
            return ['success' => false, 'error' => 'Unable to open destination file for mysqldump output.'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $outHandle,
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            fclose($outHandle);
            return ['success' => false, 'error' => 'Failed to launch mysqldump process.'];
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        fclose($outHandle);

        clearstatcache(true, $destPath);
        $bytes = @is_file($destPath) ? (int)@filesize($destPath) : 0;
        if ($exitCode !== 0 || $bytes < 64) {
            @unlink($destPath);
            return [
                'success' => false,
                'error' => trim((string)$stderr) ?: "mysqldump exited with code {$exitCode}",
            ];
        }

        return ['success' => true, 'bytes' => $bytes, 'error' => ''];
    }
}

if (!function_exists('backup_export_via_php')) {
    function backup_export_via_php(mysqli $conn, string $destPath, string $dbName): array
    {
        $fh = @fopen($destPath, 'wb');
        if ($fh === false) {
            return ['success' => false, 'bytes' => 0, 'error' => 'Unable to create SQL backup file.'];
        }

        $write = static function (string $chunk) use ($fh): void {
            fwrite($fh, $chunk);
        };

        $generatedAt = gmdate('Y-m-d H:i:s') . ' UTC';
        $safeDbName = str_replace(["\r", "\n"], '', $dbName);
        $write("-- EnformaSys Full Database Backup (Pure PHP Exporter)\n");
        $write("-- Database: `{$safeDbName}`\n");
        $write("-- Generated: {$generatedAt}\n\n");
        $write("SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        $write("SET time_zone = \"+00:00\";\n");
        $write("SET NAMES utf8mb4;\n");
        $write("SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = [];
        $views = [];
        $tblRes = $conn->query("SHOW FULL TABLES");
        if ($tblRes) {
            while ($row = $tblRes->fetch_row()) {
                $tblName = (string)($row[0] ?? '');
                $tblType = strtoupper((string)($row[1] ?? 'BASE TABLE'));
                if ($tblName === '') {
                    continue;
                }
                if ($tblType === 'VIEW') {
                    $views[] = $tblName;
                } else {
                    $tables[] = $tblName;
                }
            }
            $tblRes->free();
        }

        // 1. Base Tables: DDL + Data
        foreach ($tables as $table) {
            $qTable = backup_sql_quote_ident($table);
            $createRes = $conn->query("SHOW CREATE TABLE {$qTable}");
            if ($createRes && ($createRow = $createRes->fetch_assoc())) {
                $createSql = (string)($createRow['Create Table'] ?? '');
                $write("-- --------------------------------------------------------\n");
                $write("-- Table structure for {$qTable}\n");
                $write("-- --------------------------------------------------------\n\n");
                $write("DROP TABLE IF EXISTS {$qTable};\n");
                $write($createSql . ";\n\n");
                $createRes->free();
            }

            $dataRes = $conn->query("SELECT * FROM {$qTable}", MYSQLI_USE_RESULT);
            if ($dataRes) {
                $fields = $dataRes->fetch_fields();
                $colList = implode(', ', array_map(static function ($f) {
                    return backup_sql_quote_ident((string)$f->name);
                }, $fields));

                $batch = [];
                $batchSize = 200;
                $wroteHeader = false;

                while ($row = $dataRes->fetch_row()) {
                    if (!$wroteHeader) {
                        $write("-- Dumping data for table {$qTable}\n");
                        $wroteHeader = true;
                    }
                    $vals = [];
                    foreach ($fields as $idx => $meta) {
                        $vals[] = backup_sql_format_value($conn, $row[$idx], $meta);
                    }
                    $batch[] = '(' . implode(', ', $vals) . ')';
                    if (count($batch) >= $batchSize) {
                        $write("INSERT INTO {$qTable} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if (!empty($batch)) {
                    $write("INSERT INTO {$qTable} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                if ($wroteHeader) {
                    $write("\n");
                }
                $dataRes->free();
            }
        }

        // 2. Views
        foreach ($views as $view) {
            $qView = backup_sql_quote_ident($view);
            $viewRes = $conn->query("SHOW CREATE VIEW {$qView}");
            if ($viewRes && ($viewRow = $viewRes->fetch_assoc())) {
                $viewSql = (string)($viewRow['Create View'] ?? '');
                if ($viewSql !== '') {
                    $write("-- View structure for {$qView}\n");
                    $write("DROP VIEW IF EXISTS {$qView};\n");
                    $write($viewSql . ";\n\n");
                }
                $viewRes->free();
            }
        }

        // 3. Triggers
        $trigRes = $conn->query(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT, ACTION_TIMING
             FROM INFORMATION_SCHEMA.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
             ORDER BY EVENT_OBJECT_TABLE ASC, TRIGGER_NAME ASC"
        );
        if ($trigRes && $trigRes->num_rows > 0) {
            $write("-- --------------------------------------------------------\n");
            $write("-- Database Triggers\n");
            $write("-- --------------------------------------------------------\n\n");
            $write("DELIMITER ;;\n");
            while ($trig = $trigRes->fetch_assoc()) {
                $tName = backup_sql_quote_ident((string)$trig['TRIGGER_NAME']);
                $tTable = backup_sql_quote_ident((string)$trig['EVENT_OBJECT_TABLE']);
                $tTiming = (string)$trig['ACTION_TIMING'];
                $tEvent = (string)$trig['EVENT_MANIPULATION'];
                $tStmt = (string)$trig['ACTION_STATEMENT'];
                $write("DROP TRIGGER IF EXISTS {$tName};;\n");
                $write("CREATE TRIGGER {$tName} {$tTiming} {$tEvent} ON {$tTable} FOR EACH ROW {$tStmt};;\n\n");
            }
            $write("DELIMITER ;\n\n");
            $trigRes->free();
        }

        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);

        clearstatcache(true, $destPath);
        $bytes = @is_file($destPath) ? (int)@filesize($destPath) : 0;
        if ($bytes < 32) {
            @unlink($destPath);
            return ['success' => false, 'bytes' => 0, 'error' => 'Generated SQL backup file is empty.'];
        }

        return ['success' => true, 'bytes' => $bytes, 'error' => ''];
    }
}

if (!function_exists('backup_generate_sql_file')) {
    /**
     * Generate a complete .sql backup file at $destPath.
     *
     * @return array{success:bool,method:string,bytes:int,error:string}
     */
    function backup_generate_sql_file(mysqli $conn, string $destPath, array $dbConfig = [], bool $forcePhpFallback = false): array
    {
        $resolvedConfig = [
            'host' => (string)($dbConfig['host'] ?? ($GLOBALS['servername'] ?? '127.0.0.1')),
            'user' => (string)($dbConfig['user'] ?? ($GLOBALS['username'] ?? 'root')),
            'pass' => (string)($dbConfig['pass'] ?? ($GLOBALS['password'] ?? '')),
            'name' => (string)($dbConfig['name'] ?? ($GLOBALS['dbname'] ?? 'seminary_db')),
        ];

        if (!$forcePhpFallback) {
            $dumpRes = backup_export_via_mysqldump($destPath, $resolvedConfig);
            if ($dumpRes['success']) {
                return [
                    'success' => true,
                    'method' => 'mysqldump',
                    'bytes' => (int)$dumpRes['bytes'],
                    'error' => '',
                ];
            }
        }

        $phpRes = backup_export_via_php($conn, $destPath, $resolvedConfig['name']);
        return [
            'success' => (bool)$phpRes['success'],
            'method' => 'php',
            'bytes' => (int)$phpRes['bytes'],
            'error' => (string)$phpRes['error'],
        ];
    }
}

if (!function_exists('backup_get_database_stats')) {
    function backup_get_database_stats(mysqli $conn): array
    {
        $stats = [
            'database' => 'seminary_db',
            'table_count' => 0,
            'view_count' => 0,
            'trigger_count' => 0,
            'size_mb' => 0.0,
            'history_log_count' => 0,
        ];

        $dbRow = @$conn->query("SELECT DATABASE() AS db_name");
        if ($dbRow && ($r = $dbRow->fetch_assoc())) {
            $stats['database'] = (string)($r['db_name'] ?? 'seminary_db');
        }

        $infoRes = @$conn->query(
            "SELECT
                SUM(CASE WHEN TABLE_TYPE = 'BASE TABLE' THEN 1 ELSE 0 END) AS tbl_cnt,
                SUM(CASE WHEN TABLE_TYPE = 'VIEW' THEN 1 ELSE 0 END) AS view_cnt,
                ROUND(COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) / 1024 / 1024, 2) AS size_mb
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()"
        );
        if ($infoRes && ($row = $infoRes->fetch_assoc())) {
            $stats['table_count'] = (int)($row['tbl_cnt'] ?? 0);
            $stats['view_count'] = (int)($row['view_cnt'] ?? 0);
            $stats['size_mb'] = (float)($row['size_mb'] ?? 0.0);
        }

        $trigRes = @$conn->query(
            "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"
        );
        if ($trigRes && ($row = $trigRes->fetch_assoc())) {
            $stats['trigger_count'] = (int)($row['cnt'] ?? 0);
        }

        $hlRes = @$conn->query("SELECT COUNT(*) AS cnt FROM history_logs");
        if ($hlRes && ($row = $hlRes->fetch_assoc())) {
            $stats['history_log_count'] = (int)($row['cnt'] ?? 0);
        }

        return $stats;
    }
}

