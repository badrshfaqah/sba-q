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
        '1.5.0' => [
            // نماذج التقييم المتعددة + مرفقات الحلقة
            "ALTER TABLE `{prefix}criteria` ADD COLUMN form_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id",
            "ALTER TABLE `{prefix}criteria` ADD KEY idx_crit_form (form_id)",
            "ALTER TABLE `{prefix}programs` ADD COLUMN form_id INT UNSIGNED NULL AFTER presenter",
            "ALTER TABLE `{prefix}episodes` ADD COLUMN form_id INT UNSIGNED NULL AFTER air_time",
            "ALTER TABLE `{prefix}episodes` ADD COLUMN audio_path VARCHAR(255) NULL AFTER form_id",
            "ALTER TABLE `{prefix}episodes` ADD COLUMN ref_link VARCHAR(500) NULL AFTER audio_path",
            "UPDATE `{prefix}criteria` SET form_id = 1 WHERE form_id = 0",
            // زراعة النماذج الأربعة ومعاييرها (جدول eval_forms أنشأه إعادة تنفيذ المخطط)
            function (PDO $pdo, string $prefix) {
                sba_seed_forms($pdo, $prefix);
            },
        ],
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
        foreach ($statements as $stmt) {
            if (is_callable($stmt)) {
                $stmt($pdo, $prefix);
                continue;
            }
            try {
                $pdo->exec(str_replace('{prefix}', $prefix, $stmt));
            } catch (PDOException $e) {
                // تسامح مع "موجود مسبقاً": عمود/فهرس/جدول مكرر (تركيب حديث سبق أن شمل التغيير)
                $code = (int)($e->errorInfo[1] ?? 0);
                if (!in_array($code, [1050, 1060, 1061, 1062, 1091], true)) {
                    throw $e;
                }
            }
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
