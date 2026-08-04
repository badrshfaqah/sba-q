<?php
/**
 * ترقيات قاعدة البيانات (Migrations)
 *
 * لإضافة ترقية في إصدار جديد:
 *   1. ارفع رقم الإصدار في ملف VERSION
 *   2. أضف مدخلاً هنا بنفس الرقم يحتوي أوامر SQL
 *      واستخدم {prefix} بدل بادئة الجداول
 *
 * ملاحظات:
 *  - الجداول الجديدة كلياً لا تحتاج ترقية: مخطط schema.php يعاد تنفيذه
 *    دائماً بصيغة CREATE TABLE IF NOT EXISTS قبل الترقيات.
 *  - الترقيات تُنفذ بالترتيب من الإصدار الحالي للقاعدة حتى إصدار الملفات.
 */
if (!defined('SBA_INSTALL') && !defined('SBA')) exit;

function sba_migrations(): array
{
    return [
        '1.1.0' => [
            // إعدادات نظام التحديث للتركيبات القديمة
            "INSERT IGNORE INTO `{prefix}settings` (skey, svalue) VALUES ('update_repo', 'badrshfaqah/sba-q')",
            "INSERT IGNORE INTO `{prefix}settings` (skey, svalue) VALUES ('update_branch', 'main')",
        ],
        '1.2.0' => [
            // رمز الوصول لدعم التحديث من مستودع خاص
            "INSERT IGNORE INTO `{prefix}settings` (skey, svalue) VALUES ('update_token', '')",
        ],
        // '1.3.0' => [
        //     "ALTER TABLE `{prefix}stations` ADD COLUMN city VARCHAR(100) NULL",
        // ],
    ];
}

/**
 * تنفيذ الترقيات المعلقة
 * ترجع مصفوفة سطور سجل بما تم تنفيذه
 */
function sba_run_migrations(PDO $pdo, string $prefix, string $targetVersion): array
{
    $log = [];

    // 1) إعادة تنفيذ المخطط الكامل — ينشئ الجداول الجديدة فقط (IF NOT EXISTS)
    require_once __DIR__ . '/schema.php';
    foreach (sba_schema($prefix) as $sql) {
        $pdo->exec($sql);
    }
    $log[] = 'تم فحص مخطط الجداول وإنشاء الجداول الجديدة إن وجدت';

    // 2) قراءة إصدار القاعدة الحالي
    $st = $pdo->prepare("SELECT svalue FROM `{$prefix}settings` WHERE skey = 'db_version'");
    $st->execute();
    $current = (string)($st->fetchColumn() ?: '1.0.0');

    // 3) تنفيذ الترقيات المرقمة بالترتيب
    $migrations = sba_migrations();
    uksort($migrations, 'version_compare');
    foreach ($migrations as $version => $statements) {
        if (version_compare($version, $current, '<=')) continue;
        if (version_compare($version, $targetVersion, '>')) continue;
        foreach ($statements as $sql) {
            $pdo->exec(str_replace('{prefix}', $prefix, $sql));
        }
        $log[] = "تم تنفيذ ترقية الإصدار $version (" . count($statements) . ' أمراً)';
    }

    // 4) تحديث رقم إصدار القاعدة
    $st = $pdo->prepare("INSERT INTO `{$prefix}settings` (skey, svalue) VALUES ('db_version', ?)
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    $st->execute([$targetVersion]);
    $log[] = "إصدار قاعدة البيانات الآن: $targetVersion";

    return $log;
}
