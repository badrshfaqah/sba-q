<?php
/** سجل العمليات Audit Log */
if (!defined('SBA')) exit;

function audit_log(string $action, string $entity = '', int $entityId = 0, string $details = ''): void
{
    try {
        $uid = !empty($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
        // أثناء الدخول بالنيابة: توثيق المدير الحقيقي منفذ العملية
        if (!empty($_SESSION['impersonator_id'])) {
            $details = '[بالنيابة — نفّذها المدير #' . (int)$_SESSION['impersonator_id'] . '] ' . $details;
        }
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $st  = db()->prepare('INSERT INTO ' . tbl('audit_log') . '
            (user_id, action, entity, entity_id, details, ip, created_at)
            VALUES (?,?,?,?,?,?,NOW())');
        $st->execute([$uid, $action, $entity, $entityId, mb_substr($details, 0, 500), $ip]);
    } catch (Throwable $e) {
        // لا نوقف النظام بسبب فشل التسجيل
    }
}

/** تسميات العمليات للعرض */
function audit_action_label(string $action): string
{
    $map = [
        'login'   => 'تسجيل دخول',
        'logout'  => 'تسجيل خروج',
        'create'  => 'إضافة',
        'update'  => 'تعديل',
        'delete'  => 'حذف',
        'import'  => 'استيراد',
        'backup'  => 'نسخ احتياطي',
        'restore' => 'استعادة',
        'evaluate'=> 'تقييم',
        'impersonate' => 'دخول بالنيابة',
    ];
    return $map[$action] ?? $action;
}
