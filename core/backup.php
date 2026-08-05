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
/**
 * تنفيذ ملف SQL للاستعادة
 * التقسيم يراعي النصوص المقتبسة حتى لا ينكسر أمر يحتوي فاصلة منقوطة داخل قيمة
 */
function backup_restore_sql(string $sqlContent): int
{
    $pdo = db();
    $count = 0;
    $statements = backup_split_sql($sqlContent);

    // محاولة تنفيذ ضمن معاملة (DDL في MySQL لا يتراجع، لكنها تحمي بيانات الإدخال)
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $count++;
    }
    return $count;
}

/** تقسيم نص SQL إلى أوامر مع مراعاة النصوص المقتبسة والتعليقات */
function backup_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $inSingle = false; $inDouble = false; $inBacktick = false; $inComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inComment) {
            if ($ch === "\n") $inComment = false;
            continue;
        }
        if (!$inSingle && !$inDouble && !$inBacktick) {
            // تعليق سطري -- أو #
            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-' && trim($buffer) === '') {
                $inComment = true;
                continue;
            }
            if ($ch === '#' && trim($buffer) === '') {
                $inComment = true;
                continue;
            }
        }

        $buffer .= $ch;

        if ($inSingle) {
            if ($ch === '\\' && $i + 1 < $len) { $buffer .= $sql[++$i]; continue; }
            if ($ch === "'") $inSingle = false;
            continue;
        }
        if ($inDouble) {
            if ($ch === '\\' && $i + 1 < $len) { $buffer .= $sql[++$i]; continue; }
            if ($ch === '"') $inDouble = false;
            continue;
        }
        if ($inBacktick) {
            if ($ch === '`') $inBacktick = false;
            continue;
        }
        if ($ch === "'") { $inSingle = true; continue; }
        if ($ch === '"') { $inDouble = true; continue; }
        if ($ch === '`') { $inBacktick = true; continue; }

        if ($ch === ';') {
            $statements[] = substr($buffer, 0, -1);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') $statements[] = $buffer;
    return $statements;
}

