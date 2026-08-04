<?php
/**
 * الموجّه الرئيسي - نظام النواة + الموديولات
 * كل ميزة عبارة عن موديول مستقل داخل مجلد modules/
 */
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/layout.php';

require_login();
csrf_check();

/* إنهاء الدخول بالنيابة (متاح للعضو المُتقمَّص كي يعود المدير لحسابه) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stop_impersonation'])) {
    impersonate_end();
    flash_set('success', 'تمت العودة إلى حسابك');
    redirect('index.php');
}

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
    'analytics'  => 'reports.view',
    'followup'   => 'evaluators.assign',
    'users'      => 'users.manage',
    'settings'   => 'settings.manage',
    'audit'      => 'audit.view',
    'backup'     => 'backup.manage',
    'update'     => 'update.manage',
    'app'        => 'dashboard.view',
];

$m = $_GET['m'] ?? 'dashboard';
if (!array_key_exists($m, $modules) || !is_file(__DIR__ . "/modules/$m/index.php")) {
    $m = 'dashboard';
}
if ($modules[$m] !== null) {
    require_can($modules[$m]);
}

require __DIR__ . "/modules/$m/index.php";
