<?php
/** القالب العام: هيدر + قائمة جانبية + فوتر */
if (!defined('SBA')) exit;

function layout_nav_items(): array
{
    $items = [];
    if (can('dashboard.view'))   $items[] = ['dashboard',  'الرئيسية',        '&#127968;'];
    if (can('stations.manage') || can('data.viewall')) $items[] = ['stations', 'الإذاعات', '&#128251;'];
    if (can('programs.manage') || can('data.viewall')) $items[] = ['programs', 'البرامج',  '&#127908;'];
    if (can('episodes.manage') || can('eval.technical') || can('eval.content'))
                                 $items[] = ['episodes',   'الحلقات والتقييم', '&#127911;'];
    if (can('compliance.view'))  $items[] = ['compliance', 'التزام البث',      '&#128337;'];
    if (can('reports.view'))     $items[] = ['reports',    'التقارير',         '&#128202;'];
    if (can('reports.view'))     $items[] = ['analytics',  'ذكاء الأعمال',     '&#128200;'];
    if (can('evaluators.assign'))$items[] = ['followup',   'متابعة الموظفين',  '&#127919;'];
    if (can('users.manage'))     $items[] = ['users',      'المستخدمون',       '&#128101;'];
    if (can('settings.manage'))  $items[] = ['settings',   'الإعدادات',        '&#9881;&#65039;'];
    if (can('audit.view'))       $items[] = ['audit',      'سجل العمليات',     '&#128220;'];
    if (can('backup.manage'))    $items[] = ['backup',     'النسخ الاحتياطي',  '&#128190;'];
    if (can('update.manage'))    $items[] = ['update',     'تحديث النظام',     '&#128260;'];
    return $items;
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> | <?= e($site) ?></title>
<link rel="icon" type="image/png" href="assets/img/SBA_logo.png">
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
            <a href="logout.php" class="btn btn-ghost btn-sm btn-block">تسجيل الخروج</a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="القائمة">&#9776;</button>
            <h1 class="page-title"><?= e($title) ?></h1>
            <div class="topbar-clock" id="topbarClock"><?= date('Y-m-d H:i') ?></div>
        </header>
        <?php if (is_impersonating()): $imp = impersonator(); ?>
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
    ?>
        </main>
        <footer class="footer">
            منصة متابعة جودة البث والمحتوى الإذاعي — الإصدار <?= SBA_VERSION ?>
        </footer>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/app.js?v=<?= SBA_VERSION ?>"></script>
</body>
</html>
    <?php
}
