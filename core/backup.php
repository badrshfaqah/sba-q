<?php
/**
 * النسخ الاحتياطي والاستعادة بدون mysqldump
 * (يعمل على أي استضافة مشتركة)
 */
if (!defined('SBA')) exit;

/** توليد نص SQL كامل لقاعدة البيانات */
function backup_generate_sql(): string
{
    $pdo = db();
    $sql = "-- منصة متابعة جودة البث والمحتوى الإذاعي\n";
    $sql .= "-- نسخة احتياطية بتاريخ: " . date('Y-m-d H:i') . "\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = [];
    $like = str_replace('_', '\\_', DB_PREFIX) . '%';
    foreach ($pdo->query("SHOW TABLES LIKE '$like'") as $row) {
        $tables[] = array_values($row)[0];
    }

    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        $stmt = $pdo->query("SELECT * FROM `$table`");
        $batch = [];
        $cols = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = '`' . implode('`,`', array_keys($row)) . '`';
            }
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote((string)$v);
            }, array_values($row));
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 200) {
                $sql .= "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if ($batch) {
            $sql .= "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

/** تنفيذ ملف SQL للاستعادة */
function backup_restore_sql(string $sqlContent): int
{
    $pdo = db();
    $count = 0;
    // تقسيم الأوامر: نهاية سطر تنتهي بفاصلة منقوطة
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r\n|\r|\n/', $sqlContent) as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '--') === 0) continue;
        $buffer .= $line . "\n";
        if (substr(rtrim($trim), -1) === ';') {
            $statements[] = $buffer;
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') $statements[] = $buffer;

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $count++;
    }
    return $count;
}
