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
