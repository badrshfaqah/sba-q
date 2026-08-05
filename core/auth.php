<?php
/** المصادقة والصلاحيات */
if (!defined('SBA')) exit;

/** المستخدم الحالي أو null */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM ' . tbl('users') . ' WHERE id = ? AND active = 1');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
            if (!$user) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

/**
 * حد محاولات الدخول الفاشلة (10 محاولات لكل IP خلال 15 دقيقة)
 * يعتمد على سجل العمليات فلا يحتاج جدولاً إضافياً
 */
function auth_is_throttled(): bool
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM ' . tbl('audit_log') . "
            WHERE action = 'login_failed' AND ip = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $st->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
        return (int)$st->fetchColumn() >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

/** محاولة تسجيل الدخول */
function auth_login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT * FROM ' . tbl('users') . ' WHERE username = ? AND active = 1');
    $st->execute([$username]);
    $user = $st->fetch();
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        audit_log('login', 'user', (int)$user['id'], 'تسجيل دخول ناجح');
        return true;
    }
    // تأخير بسيط يبطئ التخمين الآلي
    usleep(300000);
    return false;
}

function auth_logout(): void
{
    if (current_user()) {
        audit_log('logout', 'user', (int)current_user()['id'], 'تسجيل خروج');
    }
    $_SESSION = [];
    session_destroy();
}

/* ---------- الدخول بالنيابة عن عضو (Impersonation) ---------- */

/** هل الجلسة الحالية بالنيابة عن عضو؟ */
function is_impersonating(): bool
{
    return !empty($_SESSION['impersonator_id']);
}

/** بيانات المدير الأصلي أثناء النيابة */
function impersonator(): ?array
{
    if (!is_impersonating()) return null;
    $st = db()->prepare('SELECT id, name FROM ' . tbl('users') . ' WHERE id = ?');
    $st->execute([(int)$_SESSION['impersonator_id']]);
    return $st->fetch() ?: null;
}

/** بدء الدخول بالنيابة (مدير النظام فقط، بدون تسلسل) */
function impersonate_start(int $targetId): bool
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin' || is_impersonating()) return false;
    if ((int)$u['id'] === $targetId) return false;

    $st = db()->prepare('SELECT id, name FROM ' . tbl('users') . ' WHERE id = ? AND active = 1');
    $st->execute([$targetId]);
    $target = $st->fetch();
    if (!$target) return false;

    audit_log('impersonate', 'user', $targetId, 'بدء الدخول بالنيابة عن: ' . $target['name']);
    $_SESSION['impersonator_id'] = (int)$u['id'];
    $_SESSION['uid'] = $targetId;
    return true;
}

/** إنهاء النيابة والعودة لحساب المدير */
function impersonate_end(): void
{
    if (!is_impersonating()) return;
    $memberId = (int)($_SESSION['uid'] ?? 0);
    $_SESSION['uid'] = (int)$_SESSION['impersonator_id'];
    unset($_SESSION['impersonator_id']);
    audit_log('impersonate', 'user', $memberId, 'إنهاء الدخول بالنيابة والعودة لحساب المدير');
}

/** إلزام تسجيل الدخول */
function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

/**
 * فحص صلاحية (Capability)
 *
 * القدرات المتاحة:
 *  dashboard.view  stations.manage  programs.manage  episodes.manage
 *  evaluators.assign  eval.technical  eval.content
 *  compliance.view  compliance.edit  compliance.import
 *  reports.view  users.manage  settings.manage  audit.view
 *  backup.manage  data.viewall
 */
function can(string $cap): bool
{
    $u = current_user();
    if (!$u) return false;

    switch ($u['role']) {
        case 'admin':
            return true;

        case 'manager':
            // مدير الجودة: كل البيانات + توزيع المقيمين، بدون إدارة النظام
            return in_array($cap, [
                'dashboard.view', 'stations.manage', 'programs.manage',
                'episodes.manage', 'evaluators.assign',
                'eval.technical', 'eval.content',
                'compliance.view', 'compliance.edit', 'compliance.import',
                'reports.view', 'data.viewall',
            ], true);

        case 'employee':
            // الموظف يرى فقط ما يخصه حسب صلاحياته المحددة
            $map = [
                'dashboard.view'  => true,
                'eval.technical'  => (bool)$u['perm_technical'],
                'eval.content'    => (bool)$u['perm_content'],
                'compliance.view' => (bool)$u['perm_compliance'],
                'compliance.edit' => (bool)$u['perm_compliance'],
            ];
            return $map[$cap] ?? false;

        case 'viewer':
            // مشاهد: عرض فقط
            return in_array($cap, [
                'dashboard.view', 'reports.view', 'compliance.view', 'data.viewall',
            ], true);
    }
    return false;
}

/** إلزام صلاحية وإلا رفض الوصول */
function require_can(string $cap): void
{
    if (!can($cap)) {
        http_response_code(403);
        require_once SBA_ROOT . '/core/layout.php';
        layout_header('غير مصرح');
        echo '<div class="card"><div class="empty-state">
                <div class="empty-icon">&#128274;</div>
                <h2>غير مصرح لك بالوصول لهذه الصفحة</h2>
                <p><a class="btn btn-primary" href="index.php">العودة للرئيسية</a></p>
              </div></div>';
        layout_footer();
        exit;
    }
}
