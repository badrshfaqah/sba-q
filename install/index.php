<?php
/**
 * معالج التثبيت التلقائي
 * يظهر عند أول تشغيل، وينشئ config.php ويقفل نفسه بعد الانتهاء
 */
define('SBA_INSTALL', true);
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Riyadh');

$root = dirname(__DIR__);
require __DIR__ . '/schema.php';

// إذا كان التثبيت مقفلاً → منع الوصول
if (file_exists(__DIR__ . '/installed.lock') || file_exists($root . '/config.php')) {
    header('Location: ../login.php');
    exit;
}

function ie($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$step  = (int)($_GET['step'] ?? 1);
$error = '';

/* ------------ معالجة الخطوات ------------ */

// الخطوة 2: حفظ بيانات قاعدة البيانات واختبار الاتصال
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $db = [
        'host'   => trim($_POST['db_host'] ?? 'localhost'),
        'name'   => trim($_POST['db_name'] ?? ''),
        'user'   => trim($_POST['db_user'] ?? ''),
        'pass'   => (string)($_POST['db_pass'] ?? ''),
        'prefix' => trim($_POST['db_prefix'] ?? 'sba_'),
    ];
    if ($db['name'] === '' || $db['user'] === '') {
        $error = 'اسم قاعدة البيانات واسم المستخدم مطلوبان';
    } elseif (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $db['prefix'])) {
        $error = 'بادئة الجداول يجب أن تكون أحرفاً إنجليزية وأرقاماً فقط';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $_SESSION['install_db'] = $db;
            header('Location: ?step=3');
            exit;
        } catch (PDOException $e) {
            $error = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
        }
    }
}

// الخطوة 3: إنشاء الجداول وزراعة البيانات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $db = $_SESSION['install_db'] ?? null;
    if (!$db) { header('Location: ?step=2'); exit; }
    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        foreach (sba_schema($db['prefix']) as $sql) {
            $pdo->exec($sql);
        }
        sba_seed($pdo, $db['prefix']);
        header('Location: ?step=4');
        exit;
    } catch (PDOException $e) {
        $error = 'فشل إنشاء الجداول: ' . $e->getMessage();
    }
}

// الخطوة 4: إنشاء المدير الأول والجهة الأولى ثم كتابة config وقفل التثبيت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4) {
    $db = $_SESSION['install_db'] ?? null;
    if (!$db) { header('Location: ?step=2'); exit; }

    $adminName = trim($_POST['admin_name'] ?? '');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $station   = trim($_POST['station_name'] ?? '');

    if ($adminName === '' || $adminUser === '' || $station === '') {
        $error = 'جميع الحقول مطلوبة';
    } elseif (mb_strlen($adminPass) < 8) {
        $error = 'كلمة المرور يجب ألا تقل عن 8 أحرف';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $p = $db['prefix'];

            $st = $pdo->prepare("INSERT INTO `{$p}users` (name, username, password, role) VALUES (?,?,?, 'admin')");
            $st->execute([$adminName, $adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

            $st = $pdo->prepare("INSERT INTO `{$p}stations` (name) VALUES (?)");
            $st->execute([$station]);

            // تحميل البيانات التجريبية إن طُلبت
            if (!empty($_POST['load_demo'])) {
                require_once __DIR__ . '/demo.php';
                try { sba_demo_load($pdo, $p); } catch (Throwable $e) { /* لا نوقف التثبيت */ }
            }

            // كتابة ملف الإعدادات
            $config = "<?php\n"
                . "// ملف إعدادات النظام - تم إنشاؤه تلقائياً بواسطة معالج التثبيت\n"
                . "// بتاريخ: " . date('Y-m-d H:i') . "\n"
                . "if (!defined('SBA')) exit;\n"
                . "define('DB_HOST', " . var_export($db['host'], true) . ");\n"
                . "define('DB_NAME', " . var_export($db['name'], true) . ");\n"
                . "define('DB_USER', " . var_export($db['user'], true) . ");\n"
                . "define('DB_PASS', " . var_export($db['pass'], true) . ");\n"
                . "define('DB_PREFIX', " . var_export($db['prefix'], true) . ");\n";

            if (file_put_contents($root . '/config.php', $config) === false) {
                throw new RuntimeException('تعذر كتابة ملف config.php — تأكد من صلاحيات الكتابة على المجلد');
            }
            // قفل التثبيت
            file_put_contents(__DIR__ . '/installed.lock', date('Y-m-d H:i'));
            unset($_SESSION['install_db']);

            header('Location: ?step=5');
            exit;
        } catch (Throwable $e) {
            $error = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

/* ------------ فحوصات السيرفر ------------ */
function server_checks(string $root): array
{
    return [
        ['إصدار PHP ‏7.4 أو أحدث', version_compare(PHP_VERSION, '7.4.0', '>='), 'الإصدار الحالي: ' . PHP_VERSION],
        ['امتداد PDO MySQL', extension_loaded('pdo_mysql'), 'مطلوب للاتصال بقاعدة البيانات'],
        ['امتداد mbstring', extension_loaded('mbstring'), 'مطلوب لدعم اللغة العربية'],
        ['امتداد Zip', extension_loaded('zip'), 'مطلوب لاستيراد ملفات Excel'],
        ['صلاحية الكتابة على مجلد النظام', is_writable($root), 'لإنشاء ملف config.php'],
        ['صلاحية الكتابة على مجلد install', is_writable(__DIR__), 'لإنشاء ملف قفل التثبيت'],
    ];
}

$checksOk = true;
$checks = server_checks($root);
foreach ($checks as $c) { if (!$c[1]) $checksOk = false; }
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>معالج تثبيت المنصة</title>
<link rel="icon" type="image/png" href="../assets/img/SBA_logo.png">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="auth-body">
<div class="install-box">
    <div class="auth-logo">
        <img src="../assets/img/SBA_logo.png" alt="الشعار">
        <h1>معالج تثبيت المنصة</h1>
        <p class="muted">منصة متابعة جودة البث والمحتوى الإذاعي</p>
    </div>

    <div class="install-steps">
        <?php
        $labels = ['فحص السيرفر', 'قاعدة البيانات', 'إنشاء الجداول', 'المدير والجهة', 'اكتمل'];
        foreach ($labels as $i => $label):
            $n = $i + 1;
            $cls = $n < $step ? 'done' : ($n === $step ? 'current' : '');
        ?>
        <div class="install-step <?= $cls ?>">
            <span class="step-num"><?= $n < $step ? '✓' : $n ?></span>
            <span class="step-label"><?= ie($label) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= ie($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <h2>فحص متطلبات السيرفر</h2>
        <table class="table">
            <?php foreach ($checks as $c): ?>
            <tr>
                <td><?= ie($c[0]) ?><br><small class="muted"><?= ie($c[2]) ?></small></td>
                <td style="width:80px;text-align:center">
                    <?= $c[1] ? '<span class="badge badge-success">متوفر</span>' : '<span class="badge badge-danger">مفقود</span>' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($checksOk): ?>
            <a href="?step=2" class="btn btn-primary btn-block">متابعة التثبيت ←</a>
        <?php else: ?>
            <div class="alert alert-danger">يرجى معالجة المتطلبات المفقودة ثم <a href="?step=1">إعادة الفحص</a></div>
        <?php endif; ?>

    <?php elseif ($step === 2): $d = $_SESSION['install_db'] ?? []; ?>
        <h2>بيانات قاعدة البيانات MySQL</h2>
        <form method="post" action="?step=2">
            <div class="form-group">
                <label>عنوان السيرفر (Host)</label>
                <input type="text" name="db_host" value="<?= ie($d['host'] ?? 'localhost') ?>" required>
            </div>
            <div class="form-group">
                <label>اسم قاعدة البيانات</label>
                <input type="text" name="db_name" value="<?= ie($d['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="db_user" value="<?= ie($d['user'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="db_pass" value="">
            </div>
            <div class="form-group">
                <label>بادئة الجداول</label>
                <input type="text" name="db_prefix" value="<?= ie($d['prefix'] ?? 'sba_') ?>" dir="ltr">
                <small class="muted">تسمح بتركيب النظام بجانب أنظمة أخرى في نفس القاعدة</small>
            </div>
            <button type="submit" class="btn btn-primary btn-block">اختبار الاتصال والمتابعة ←</button>
        </form>

    <?php elseif ($step === 3): ?>
        <?php if (empty($_SESSION['install_db'])) { header('Location: ?step=2'); exit; } ?>
        <h2>إنشاء جداول قاعدة البيانات</h2>
        <p>سيتم إنشاء جداول النظام وزراعة البيانات الافتراضية (معايير التقييم والإعدادات).</p>
        <form method="post" action="?step=3">
            <button type="submit" class="btn btn-primary btn-block">إنشاء الجداول الآن ←</button>
        </form>

    <?php elseif ($step === 4): ?>
        <?php if (empty($_SESSION['install_db'])) { header('Location: ?step=2'); exit; } ?>
        <h2>حساب مدير النظام والجهة الأولى</h2>
        <form method="post" action="?step=4">
            <div class="form-group">
                <label>الاسم الكامل للمدير</label>
                <input type="text" name="admin_name" value="<?= ie($_POST['admin_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="admin_user" value="<?= ie($_POST['admin_user'] ?? '') ?>" required dir="ltr">
            </div>
            <div class="form-group">
                <label>كلمة المرور (8 أحرف على الأقل)</label>
                <input type="password" name="admin_pass" required minlength="8">
            </div>
            <hr>
            <div class="form-group">
                <label>اسم أول جهة (إذاعة)</label>
                <input type="text" name="station_name" value="<?= ie($_POST['station_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer">
                    <input type="checkbox" name="load_demo" value="1">
                    تحميل بيانات تجريبية للتعرف على المنصة (يمكن إزالتها لاحقاً من الإعدادات)
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block">إنهاء التثبيت ←</button>
        </form>

    <?php elseif ($step === 5): ?>
        <div class="empty-state">
            <div class="empty-icon" style="font-size:52px">&#127881;</div>
            <h2>تم التثبيت بنجاح</h2>
            <p>تم إنشاء ملف الإعدادات وقفل معالج التثبيت.</p>
            <a href="../login.php" class="btn btn-primary btn-block">الانتقال لتسجيل الدخول ←</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
