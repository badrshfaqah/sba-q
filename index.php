<?php
/**
 * الموجّه الرئيسي - نظام النواة + الموديولات
 * كل ميزة عبارة عن موديول مستقل داخل مجلد modules/
 */
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/layout.php';

require_login();
csrf_check();

/**
 * سجل الموديولات: [ المفتاح => الصلاحية المطلوبة ]
 * لإضافة ميزة جديدة: أنشئ مجلداً في modules/ وأضف سطراً هنا
 */
$modules = [
    'dashboard'  => 'dashboard.view',
    'stations'   => null,   // فحص داخلي (إدارة أو عرض)
    'programs'   => null,
    'episodes'   => null,
    'quality'    => null,
    'compliance' => 'compliance.view',
    'reports'    => 'reports.view',
    'users'      => 'users.manage',
    'settings'   => 'settings.manage',
    'audit'      => 'audit.view',
    'backup'     => 'backup.manage',
    'update'     => 'update.manage',
];

$m = $_GET['m'] ?? 'dashboard';
if (!array_key_exists($m, $modules) || !is_file(__DIR__ . "/modules/$m/index.php")) {
    $m = 'dashboard';
}
if ($modules[$m] !== null) {
    require_can($modules[$m]);
}

require __DIR__ . "/modules/$m/index.php";
