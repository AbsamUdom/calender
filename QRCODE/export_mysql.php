<?php
require __DIR__ . '/auth.php';
if (isset($_GET['logout'])) {
    logout_user();
    header('Location: login.php');
    exit;
}
require_login();
$path = __DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite';
if (!file_exists($path)) {
    http_response_code(404);
    echo 'SQLite database not found.';
    exit;
}
$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$filename = 'event-export-mysql-' . date('Ymd-His') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
echo "SET time_zone = '+00:00';\n";
echo "START TRANSACTION;\n";
echo "SET NAMES utf8mb4;\n\n";
$tablesStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "DROP TABLE IF EXISTS `{$table}`;\n";
    $cols = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
    $colDefs = [];
    $primaryKeys = [];
    foreach ($cols as $col) {
        $name = $col['name'];
        $type = strtoupper(trim($col['type']));
        $notnull = (int)$col['notnull'] === 1 ? ' NOT NULL' : '';
        $dflt = $col['dflt_value'];
        $default = '';
        if ($dflt !== null) {
            $default = ' DEFAULT ' . $dflt;
        }
        $mysqlType = 'TEXT';
        if (strpos($type, 'INT') !== false) {
            $mysqlType = 'INT';
        } elseif (strpos($type, 'CHAR') !== false || strpos($type, 'CLOB') !== false || strpos($type, 'TEXT') !== false) {
            $mysqlType = 'TEXT';
        } elseif (strpos($type, 'BLOB') !== false) {
            $mysqlType = 'BLOB';
        } elseif (strpos($type, 'REAL') !== false || strpos($type, 'FLOA') !== false || strpos($type, 'DOUB') !== false) {
            $mysqlType = 'DOUBLE';
        } else {
            $mysqlType = 'TEXT';
        }
        if ((int)$col['pk'] === 1 && $mysqlType === 'INT') {
            $colDefs[] = "`{$name}` {$mysqlType} AUTO_INCREMENT" . $notnull . $default;
            $primaryKeys[] = $name;
        } else {
            $colDefs[] = "`{$name}` {$mysqlType}" . $notnull . $default;
            if ((int)$col['pk'] === 1) {
                $primaryKeys[] = $name;
            }
        }
    }
    $pkSql = '';
    if (!empty($primaryKeys)) {
        $pk = array_map(function($k){ return "`".$k."`"; }, $primaryKeys);
        $pkSql = ",\n  PRIMARY KEY (" . implode(', ', $pk) . ")";
    }
    echo "CREATE TABLE `{$table}` (\n  " . implode(",\n  ", $colDefs) . $pkSql . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
    $rows = $pdo->query("SELECT * FROM `{$table}`");
    $rowCount = 0;
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $columns = array_keys($row);
        $values = [];
        foreach ($row as $val) {
            if ($val === null) {
                $values[] = 'NULL';
            } else {
                $values[] = "'" . str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", "\\r", "\\n"], (string)$val) . "'";
            }
        }
        echo "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
        $rowCount++;
    }
    if ($rowCount > 0) echo "\n";
    $idxList = $pdo->query("PRAGMA index_list(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($idxList as $idx) {
        if ((int)$idx['unique'] === 1) {
            $idxName = $idx['name'];
            $idxInfo = $pdo->query("PRAGMA index_info(`{$idxName}`)")->fetchAll(PDO::FETCH_ASSOC);
            $colsIdx = array_map(function($r){ return "`".$r['name']."`"; }, $idxInfo);
            if (!empty($colsIdx)) {
                echo "ALTER TABLE `{$table}` ADD UNIQUE `{$idxName}` (" . implode(', ', $colsIdx) . ");\n";
            }
        }
    }
    echo "\n";
}
echo "COMMIT;\n";
exit;
