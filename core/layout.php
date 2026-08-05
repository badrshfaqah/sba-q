<?php
/** القالب العام: هيدر + قائمة جانبية + فوتر */
if (!defined('SBA')) exit;

/**
 * أقسام القائمة: [المعرف، الاسم، الأيقونة، الاسم المختصر للشريط السفلي]
 */
function layout_nav_items(): array
{
    $items = [];
    if (can('dashboard.view'))   $items[] = ['dashboard',  'الرئيسية',        '&#127968;', 'الرئيسية'];
    if (can('stations.manage') || can('data.viewall')) $items[] = ['stations', 'الإذاعات', '&#128251;', 'الإذاعات'];
    if (can('programs.manage') || can('data.viewall')) $items[] = ['programs', 'البرامج',  '&#127908;', 'البرامج'];
    if (can('episodes.manage') || can('eval.technical') || can('eval.content'))
                                 $items[] = ['episodes',   'الحلقات والتقييم', '&#127911;', 'الحلقات'];
    if (can('compliance.view'))  $items[] = ['compliance', 'التزام البث',      '&#128337;', 'الالتزام'];
    if (can('reports.view'))     $items[] = ['reports',    'التقارير',         '&#128202;', 'التقارير'];
    if (can('reports.view'))     $items[] = ['analytics',  'ذكاء الأعمال',     '&#128200;', 'التحليل'];
    if (can('evaluators.assign'))$items[] = ['followup',   'متابعة الموظفين',  '&#127919;', 'المتابعة'];
    if (can('users.manage'))     $items[] = ['users',      'المستخدمون',       '&#128101;', 'المستخدمون'];
    if (can('settings.manage'))  $items[] = ['settings',   'الإعدادات',        '&#9881;&#65039;', 'الإعدادات'];
    if (can('audit.view'))       $items[] = ['audit',      'سجل العمليات',     '&#128220;', 'السجل'];
    if (can('backup.manage'))    $items[] = ['backup',     'النسخ الاحتياطي',  '&#128190;', 'النسخ'];
    if (can('update.manage'))    $items[] = ['update',     'تحديث النظام',     '&#128260;', 'التحديث'];
    if (can('dashboard.view'))   $items[] = ['app',        'التطبيق والإشعارات', '&#128241;', 'الإشعارات'];
    return $items;
}

/**
 * أقسام الشريط السفلي: الأكثر استخداماً حسب الدور
 * (تُختار المتاحة بالترتيب، ثم يُكمَّل العدد من بقية الأقسام)
 */
function layout_tab_items(array $items): array
{
    $preferred = ['dashboard', 'episodes', 'compliance', 'reports', 'followup', 'stations'];
    $byMod = [];
    foreach ($items as $it) $byMod[$it[0]] = $it;

    $tabs = [];
    foreach ($preferred as $mod) {
        if (count($tabs) === 4) break;
        if (isset($byMod[$mod])) { $tabs[] = $byMod[$mod]; unset($byMod[$mod]); }
    }
    foreach ($byMod as $it) {
        if (count($tabs) >= 4) break;
        $tabs[] = $it;
    }
    return $tabs;
}

function layout_header(string $title = ''): void
{
    $user    = current_user();
    $current = $_GET['m'] ?? 'dashboard';
    $site    = setting('site_name', 'منصة متابعة جودة البث والمحتوى الإذاعي');
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=5.0">
<title><?= e($title) ?> | <?= e($site) ?></title>
<link rel="icon" type="image/png" href="assets/img/SBA_logo.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#fcfcfb">
<link rel="apple-touch-icon" href="assets/img/icon-180.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="جودة البث">
<meta name="format-detection" content="telephone=no">
<link rel="stylesheet" href="assets/css/style.css?v=<?= SBA_VERSION ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="assets/img/SBA_logo.png" alt="الشعار">
            <div class="brand-text">
                <strong>جودة البث</strong>
                <span>والمحتوى الإذاعي</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach (layout_nav_items() as [$mod, $label, $icon]): ?>
            <a href="<?= e(url($mod)) ?>" class="nav-item<?= $current === $mod ? ' active' : '' ?>">
                <span class="nav-icon"><?= $icon ?></span>
                <span><?= e($label) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <div class="user-avatar"><?= e(mb_substr($user['name'] ?? '?', 0, 1)) ?></div>
                <div class="user-meta">
                    <strong><?= e($user['name'] ?? '') ?></strong>
                    <span><?= e(role_label($user['role'] ?? '')) ?></span>
                </div>
            </div>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm btn-block">تسجيل الخروج</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="القائمة">&#9776;</button>
            <h1 class="page-title"><?= e($title) ?></h1>
            <?php
            // محصّن ضد نقص ملف core/notifications.php عند رفع غير مكتمل
            $notifs = [];
            if (function_exists('user_notifications')) {
                try { $notifs = user_notifications(); } catch (Throwable $e) { $notifs = []; }
            }
            ?>
            <div class="notif-wrap">
                <button class="notif-bell" id="notifBell" aria-label="التنبيهات">
                    &#128276;
                    <?php if ($notifs): ?><span class="notif-count"><?= count($notifs) ?></span><?php endif; ?>
                </button>
                <div class="notif-panel" id="notifPanel">
                    <div class="notif-title">التنبيهات</div>
                    <?php if (!$notifs): ?>
                        <div class="notif-empty">&#10004;&#65039; لا توجد تنبيهات — كل شيء تحت السيطرة</div>
                    <?php else: foreach ($notifs as [$sev, $text, $link]): ?>
                        <a class="notif-item notif-<?= e($sev) ?>" href="<?= e($link) ?>">
                            <span class="notif-dot"></span>
                            <span><?= e($text) ?></span>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="topbar-clock" id="topbarClock"><?= date('Y-m-d H:i') ?></div>
        </header>
        <?php if (function_exists('is_impersonating') && is_impersonating()): $imp = impersonator(); ?>
        <div class="impersonation-bar">
            <span>&#128373;&#65039; أنت الآن تتصفح بالنيابة عن <strong><?= e($user['name'] ?? '') ?></strong>
                (حسابك الأصلي: <?= e($imp['name'] ?? '') ?>)</span>
            <form method="post" action="index.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="stop_impersonation" value="1">
                <button type="submit" class="btn btn-sm btn-ghost">&#8592; العودة لحسابي</button>
            </form>
        </div>
        <?php endif; ?>
        <main class="content">
        <?php foreach (flash_get() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endforeach; ?>
    <?php
}

function layout_footer(): void
{
    $current = $_GET['m'] ?? 'dashboard';
    // أهم أربعة أقسام في الشريط السفلي، والباقي خلف زر «المزيد»
    $primary = layout_tab_items(layout_nav_items());
    ?>
        </main>
        <footer class="footer">
            منصة متابعة جودة البث والمحتوى الإذاعي — الإصدار <?= SBA_VERSION ?>
        </footer>
    </div>
</div>

<!-- شريط التنقل السفلي (يظهر على الجوال فقط) -->
<nav class="tabbar" id="tabbar">
    <?php foreach ($primary as $it): ?>
    <a href="<?= e(url($it[0])) ?>" class="tab-item<?= $current === $it[0] ? ' active' : '' ?>">
        <span class="tab-icon"><?= $it[2] ?></span>
        <span class="tab-label"><?= e($it[3] ?? $it[1]) ?></span>
    </a>
    <?php endforeach; ?>
    <button type="button" class="tab-item" id="tabMore">
        <span class="tab-icon">&#9776;</span>
        <span class="tab-label">المزيد</span>
    </button>
</nav>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/app.js?v=<?= SBA_VERSION ?>"></script>
</body>
</html>
    <?php
}
