<?php
/**
 * أداة تشخيص مستقلة — تعمل حتى لو كانت المنصة معطلة تماماً
 *
 * لا تتصل بقاعدة البيانات ولا تعرض أي بيانات حساسة (لا كلمات مرور ولا مفاتيح).
 * الهدف: كشف سبب خطأ 500 أو أي فشل في التشغيل أو التحديث.
 *
 * بعد الانتهاء من التشخيص يُنصح بحذف هذا الملف.
 */

// إظهار الأخطاء في هذه الصفحة فقط
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$root = __DIR__;
$rows = [];   // [الفحص، النتيجة، الحالة: ok|warn|bad، ملاحظة]

function add(&$rows, $name, $value, $status, $note = '')
{
    $rows[] = compact('name', 'value', 'status', 'note');
}

/* ---------- إصدار PHP ---------- */
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
add($rows, 'إصدار PHP', PHP_VERSION, $phpOk ? 'ok' : 'bad',
    $phpOk ? 'مدعوم' : 'المنصة تتطلب 7.4 أو أحدث — غيّر الإصدار من لوحة الاستضافة (PHP Selector)');

add($rows, 'طريقة تشغيل PHP', PHP_SAPI, 'ok',
    stripos(PHP_SAPI, 'fpm') !== false || stripos(PHP_SAPI, 'cgi') !== false
        ? 'توجيهات php_flag في .htaccess غير مدعومة مع هذه الطريقة'
        : '');

/* ---------- الامتدادات ---------- */
$exts = [
    'pdo_mysql' => 'إلزامي — الاتصال بقاعدة البيانات',
    'mbstring'  => 'إلزامي — معالجة النصوص العربية',
    'zip'       => 'إلزامي للتحديث واستيراد Excel',
    'curl'      => 'إلزامي للتحديث والإشعارات',
    'openssl'   => 'مطلوب للإشعارات فقط',
    'json'      => 'إلزامي',
];
foreach ($exts as $ext => $why) {
    $has = extension_loaded($ext);
    $critical = strpos($why, 'إلزامي') === 0;
    add($rows, "امتداد $ext", $has ? 'مفعّل' : 'مفقود',
        $has ? 'ok' : ($critical ? 'bad' : 'warn'), $has ? '' : $why);
}

/* ---------- حدود التنفيذ ---------- */
$mem = ini_get('memory_limit');
$memBytes = (int)$mem * (stripos($mem, 'G') ? 1073741824 : (stripos($mem, 'M') ? 1048576 : 1));
add($rows, 'حد الذاكرة', $mem, ($memBytes >= 134217728 || $memBytes <= 0) ? 'ok' : 'warn',
    $memBytes < 134217728 ? 'يُفضّل 128M أو أكثر للتحديث والنسخ الاحتياطي' : '');

$maxTime = (int)ini_get('max_execution_time');
$canSetTime = function_exists('set_time_limit') && !in_array('set_time_limit', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
add($rows, 'أقصى زمن تنفيذ', $maxTime === 0 ? 'بلا حد' : $maxTime . ' ثانية',
    ($maxTime === 0 || $maxTime >= 120 || $canSetTime) ? 'ok' : 'bad',
    ($maxTime > 0 && $maxTime < 120 && !$canSetTime)
        ? 'قصير جداً والدالة set_time_limit معطّلة — التحديث سيتوقف في منتصفه ويظهر خطأ 500'
        : ($canSetTime ? 'يمكن تمديده برمجياً عند التحديث' : ''));

add($rows, 'set_time_limit', $canSetTime ? 'متاحة' : 'معطّلة', $canSetTime ? 'ok' : 'warn',
    $canSetTime ? '' : 'قد يتوقف التحديث بسبب المهلة');

add($rows, 'حجم الرفع الأقصى', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'), 'ok',
    'يحدد أقصى حجم للمقطع الصوتي وملف الاستعادة');

/* ---------- الدوال المعطّلة ---------- */
$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$needed = ['curl_exec', 'curl_init', 'file_get_contents', 'file_put_contents', 'fopen', 'unlink', 'mkdir', 'copy'];
$blocked = array_values(array_intersect($needed, $disabled));
add($rows, 'دوال ضرورية معطّلة', $blocked ? implode('، ', $blocked) : 'لا شيء',
    $blocked ? 'bad' : 'ok', $blocked ? 'اطلب من الاستضافة تفعيلها' : '');

add($rows, 'allow_url_fopen', ini_get('allow_url_fopen') ? 'مفعّل' : 'معطّل',
    (ini_get('allow_url_fopen') || extension_loaded('curl')) ? 'ok' : 'bad',
    'أحدهما مطلوب (هو أو cURL) لتنزيل التحديثات');

/* ---------- صلاحيات الكتابة ---------- */
foreach (['.' => 'مجلد المنصة', 'uploads' => 'مجلد الرفع', 'backups' => 'مجلد النسخ الاحتياطي'] as $dir => $label) {
    $p = $root . '/' . $dir;
    if (!is_dir($p)) {
        add($rows, "كتابة: $label", 'المجلد غير موجود', 'warn', 'سيُنشأ تلقائياً عند الحاجة');
        continue;
    }
    $w = is_writable($p);
    add($rows, "كتابة: $label", $w ? 'قابل للكتابة' : 'غير قابل للكتابة',
        $w ? 'ok' : ($dir === '.' ? 'bad' : 'warn'),
        $w ? '' : 'اضبط الصلاحية على 755 (أو 775) من File Manager');
}

/* ---------- ملفات النظام ---------- */
$configExists = is_file($root . '/config.php');
add($rows, 'ملف config.php', $configExists ? 'موجود' : 'غير موجود',
    $configExists ? 'ok' : 'warn', $configExists ? '' : 'المنصة لم تُثبَّت بعد — افتح الرابط الرئيسي');

$version = trim((string)@file_get_contents($root . '/VERSION'));
add($rows, 'إصدار الملفات', $version ?: 'غير معروف', $version ? 'ok' : 'warn');

foreach (['.htaccess', 'uploads/.htaccess', 'core/.htaccess', 'backups/.htaccess'] as $ht) {
    if (!is_file($root . '/' . $ht)) continue;
    // يُحسب التوجيه خطراً فقط إن كان خارج IfModule (غير محمي)
    $content = (string)@file_get_contents($root . '/' . $ht);
    $risky = [];
    $depth = 0;
    foreach (explode("\n", $content) as $ln) {
        $t = trim($ln);
        if ($t === '' || $t[0] === '#') continue;
        if (stripos($t, '<IfModule') === 0)  { $depth++; continue; }
        if (stripos($t, '</IfModule') === 0) { $depth--; continue; }
        if ($depth === 0 && preg_match('/^(php_flag|php_value|Options|Require|Order|Deny|Allow)\b/i', $t)) {
            $risky[] = $t;
        }
    }
    add($rows, "ملف $ht", $risky ? 'يحتوي توجيهات غير محمية' : 'سليم',
        $risky ? 'bad' : 'ok',
        $risky ? 'قد تسبب خطأ 500 — احذف الملف أو احذف هذه الأسطر: ' . implode(' | ', $risky) : '');
}

/* ---------- سجل أخطاء التحديث ---------- */
$logFile = $root . '/backups/update.log';
$updateLog = is_file($logFile) ? (string)file_get_contents($logFile) : '';

/* ---------- اختبار الاتصال بـ GitHub ---------- */
$netTest = null;
if (isset($_GET['net'])) {
    $url = 'https://raw.githubusercontent.com/badrshfaqah/sba-q/main/VERSION';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'SBA-Diagnose']);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netTest = $body !== false && $code === 200
            ? ['ok', 'نجح — أحدث إصدار متاح: ' . trim((string)$body)]
            : ['bad', 'فشل (رمز ' . $code . ') ' . $err . ' — قد يكون الاتصال الخارجي محجوباً'];
    } else {
        $netTest = ['bad', 'امتداد cURL غير مفعّل'];
    }
}

$counts = ['ok' => 0, 'warn' => 0, 'bad' => 0];
foreach ($rows as $r) $counts[$r['status']]++;
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تشخيص المنصة</title>
<style>
:root { --navy:#1C1E4C; --green:#0ca30c; --warn:#c98500; --bad:#c0392b; --line:#e1e0d9; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif; background:#f9f9f7;
       color:#1a1a22; line-height:1.7; padding:20px; }
.wrap { max-width:900px; margin:0 auto; }
h1 { font-size:1.4rem; color:var(--navy); margin-bottom:6px; }
.sub { color:#6b6e80; font-size:0.9rem; margin-bottom:20px; }
.summary { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
.pill { padding:8px 18px; border-radius:999px; font-weight:700; font-size:0.9rem; }
.p-ok { background:#e6f4e6; color:#006300; }
.p-warn { background:#fdf3dd; color:#8a5b00; }
.p-bad { background:#fbe7e7; color:#a32424; }
.card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px 18px; margin-bottom:16px; }
table { width:100%; border-collapse:collapse; font-size:0.9rem; }
th { text-align:right; font-size:0.8rem; color:#6b6e80; padding:8px; border-bottom:2px solid var(--line); }
td { padding:9px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
.badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:0.75rem; font-weight:700; white-space:nowrap; }
.b-ok { background:#e6f4e6; color:#006300; }
.b-warn { background:#fdf3dd; color:#8a5b00; }
.b-bad { background:#fbe7e7; color:#a32424; }
.note { color:#6b6e80; font-size:0.82rem; }
pre { background:#f4f6fa; border:1px solid var(--line); border-radius:8px; padding:12px;
      overflow-x:auto; font-size:0.8rem; direction:ltr; text-align:left; white-space:pre-wrap; }
.btn { display:inline-block; background:var(--navy); color:#fff; padding:9px 20px; border-radius:8px;
       text-decoration:none; font-weight:600; font-size:0.9rem; }
.warnbox { background:#fdf3dd; border:1px solid #f3ddae; border-radius:8px; padding:12px 16px;
           color:#8a5b00; font-size:0.88rem; margin-bottom:16px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>&#128295; تشخيص منصة جودة البث</h1>
    <p class="sub">تقرير بيئة التشغيل — لا يتصل بقاعدة البيانات ولا يعرض أي بيانات حساسة</p>

    <div class="summary">
        <span class="pill p-ok">سليم: <?= $counts['ok'] ?></span>
        <?php if ($counts['warn']): ?><span class="pill p-warn">تحذير: <?= $counts['warn'] ?></span><?php endif; ?>
        <?php if ($counts['bad']): ?><span class="pill p-bad">مشكلة: <?= $counts['bad'] ?></span><?php endif; ?>
    </div>

    <?php if ($counts['bad']): ?>
    <div class="warnbox">
        <strong>يوجد <?= $counts['bad'] ?> فحص فاشل بالأحمر أدناه.</strong>
        هذه هي الأسباب المرجّحة للمشكلة — ابدأ بمعالجتها.
    </div>
    <?php endif; ?>

    <div class="card">
        <table>
            <thead><tr><th style="width:30%">الفحص</th><th style="width:22%">النتيجة</th><th>الحالة والملاحظة</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td dir="auto"><?= htmlspecialchars($r['value'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <span class="badge b-<?= $r['status'] ?>">
                        <?= ['ok' => 'سليم', 'warn' => 'تحذير', 'bad' => 'مشكلة'][$r['status']] ?>
                    </span>
                    <?php if ($r['note']): ?>
                        <div class="note"><?= htmlspecialchars($r['note'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 style="font-size:1rem;color:var(--navy);margin-bottom:10px">اختبار الاتصال بـ GitHub</h2>
        <?php if ($netTest): ?>
            <span class="badge b-<?= $netTest[0] ?>"><?= $netTest[0] === 'ok' ? 'نجح' : 'فشل' ?></span>
            <div class="note"><?= htmlspecialchars($netTest[1], ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <p class="note" style="margin-bottom:10px">يتحقق من قدرة السيرفر على الوصول للمستودع (مطلوب للتحديث الذاتي).</p>
            <a class="btn" href="?net=1">تشغيل الاختبار</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="font-size:1rem;color:var(--navy);margin-bottom:10px">سجل آخر عملية تحديث</h2>
        <?php if ($updateLog === ''): ?>
            <p class="note">لا يوجد سجل بعد. اضغط «تحديث الآن» في المنصة ثم أعد تحميل هذه الصفحة —
               سيظهر هنا آخر ما نُفّذ قبل توقف العملية، وهو ما يحدد سبب الخطأ بدقة.</p>
        <?php else: ?>
            <pre><?= htmlspecialchars($updateLog, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="font-size:1rem;color:var(--navy);margin-bottom:10px">بعد الانتهاء</h2>
        <p class="note">احذف الملف <code dir="ltr">diagnose.php</code> من مجلد المنصة بعد حل المشكلة.</p>
    </div>
</div>
</body>
</html>
