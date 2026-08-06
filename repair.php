<?php
/**
 * أداة الإصلاح المستقلة
 *
 * تعيد جلب جميع ملفات النظام من المستودع واستبدالها — وهي **مستقلة تماماً**
 * لا تعتمد على core/updater.php ولا على أي ملف قد يكون ناقصاً، لذلك تعمل
 * حتى لو تعطّل نظام التحديث نفسه بسبب رفع FTP غير مكتمل.
 *
 * الحماية: يجب أن تكون مسجّلاً كمدير نظام.
 * بعد الإصلاح يُنصح بحذف هذا الملف (وهو غير ضار إن بقي).
 */

$ROOT = __DIR__;

/* ---------- التحقق من الهوية ---------- */
$isAdmin = false;
$authNote = '';
if (is_file($ROOT . '/config.php')) {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('SBA_SESSION');
        @session_start();
    }
    try {
        define('SBA', true);
        require_once $ROOT . '/config.php';
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (!empty($_SESSION['uid'])) {
            $st = $pdo->prepare('SELECT role FROM ' . DB_PREFIX . 'users WHERE id = ? AND active = 1');
            $st->execute([(int)$_SESSION['uid']]);
            $isAdmin = $st->fetchColumn() === 'admin';
            if (!$isAdmin) $authNote = 'هذه الأداة لمدير النظام فقط.';
        } else {
            $authNote = 'سجّل الدخول كمدير نظام أولاً ثم أعد فتح هذه الصفحة.';
        }
    } catch (Throwable $e) {
        $authNote = 'تعذر التحقق من الهوية — تأكد من صحة config.php.';
    }
} else {
    $authNote = 'المنصة غير مثبّتة بعد.';
}

/* ---------- إعدادات المستودع ---------- */
$repo = 'badrshfaqah/sba-q';
$branch = 'main';
$token = '';
if ($isAdmin && isset($pdo)) {
    try {
        foreach ($pdo->query('SELECT skey, svalue FROM ' . DB_PREFIX . "settings
            WHERE skey IN ('update_repo','update_branch','update_token')") as $r) {
            if ($r['skey'] === 'update_repo'   && $r['svalue'] !== '') $repo   = $r['svalue'];
            if ($r['skey'] === 'update_branch' && $r['svalue'] !== '') $branch = $r['svalue'];
            if ($r['skey'] === 'update_token') $token = (string)$r['svalue'];
        }
    } catch (Throwable $e) { /* القيم الافتراضية */ }
}

/* ---------- أدوات مستقلة ---------- */
function rp_download(string $url, string $dest, string $token): array
{
    $headers = ['User-Agent: SBA-Repair'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

    if (function_exists('curl_init')) {
        $fh = @fopen($dest, 'wb');
        if (!$fh) return [false, 'تعذر إنشاء ملف مؤقت — تحقق من صلاحية الكتابة على مجلد backups'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        fclose($fh);
        if ($ok === false) return [false, 'فشل الاتصال: ' . $err];
        if ($code >= 400)  return [false, "الخادم البعيد أعاد الرمز $code — تحقق من اسم المستودع والفرع"];
        return [true, ''];
    }
    if (!ini_get('allow_url_fopen')) return [false, 'cURL معطّل و allow_url_fopen مغلق — لا يمكن التنزيل'];
    $ctx = stream_context_create(['http' => ['timeout' => 180, 'follow_location' => 1,
        'header' => implode("\r\n", $headers)]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) return [false, 'تعذر الاتصال بالمستودع'];
    $out = @fopen($dest, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in); fclose($out);
    return [true, ''];
}

function rp_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $i) {
        $p = $dir . '/' . $i;
        is_dir($p) ? rp_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** نسخ شجرة الملفات مع حماية الإعدادات والبيانات، وتسجيل ما كان ناقصاً */
function rp_copy(string $src, string $dst, string $rel, array &$restored, array &$failed): int
{
    $protected = ['config.php', 'install/installed.lock', 'uploads', 'backups', '.git', '.gitignore', 'docs'];
    $n = 0;
    foreach (array_diff(scandir($src), ['.', '..']) as $item) {
        $relPath = $rel === '' ? $item : $rel . '/' . $item;
        if (in_array($relPath, $protected, true)) continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!is_dir($d)) @mkdir($d, 0755, true);
            $n += rp_copy($s, $d, $relPath, $restored, $failed);
        } else {
            $was = !is_file($d) ? 'مفقود' : (filesize($d) === 0 ? 'فارغ' : '');
            if (@copy($s, $d)) {
                $n++;
                if ($was !== '') $restored[] = $relPath . ' (' . $was . ')';
            } else {
                $failed[] = $relPath;
            }
        }
    }
    return $n;
}

/* ---------- تنفيذ الإصلاح ---------- */
$log = [];
$error = '';
$done = false;
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');
    try {
        if (!class_exists('ZipArchive')) throw new RuntimeException('امتداد Zip غير مفعّل على الاستضافة');
        if (!is_writable($ROOT)) throw new RuntimeException('مجلد المنصة غير قابل للكتابة — اضبط الصلاحية على 755');

        $tmpDirBase = $ROOT . '/backups';
        if (!is_dir($tmpDirBase)) @mkdir($tmpDirBase, 0755, true);
        if (!is_writable($tmpDirBase)) $tmpDirBase = sys_get_temp_dir();

        $url = $token !== ''
            ? 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode($branch)
            : 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . $branch;

        $zipPath = $tmpDirBase . '/repair_tmp.zip';
        [$ok, $err] = rp_download($url, $zipPath, $token);
        if (!$ok) throw new RuntimeException($err);
        $log[] = 'تم تنزيل الحزمة (' . number_format(filesize($zipPath) / 1024, 0) . ' KB)';

        $tmpDir = $tmpDirBase . '/repair_tmp';
        rp_rrmdir($tmpDir);
        @mkdir($tmpDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('تعذر فتح الحزمة المنزّلة');
        $wanted = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = $zip->getNameIndex($i);
            if ($nm !== false && strpos($nm, '/docs/') === false) $wanted[] = $nm;
        }
        $zip->extractTo($tmpDir, $wanted);
        $zip->close();
        @unlink($zipPath);
        $log[] = 'تم فك ضغط ' . count($wanted) . ' عنصراً';

        $entries = array_values(array_diff(scandir($tmpDir), ['.', '..']));
        $srcRoot = (count($entries) === 1 && is_dir($tmpDir . '/' . $entries[0]))
            ? $tmpDir . '/' . $entries[0] : $tmpDir;
        if (!is_file($srcRoot . '/index.php')) throw new RuntimeException('محتوى الحزمة غير صالح');

        $restored = []; $failed = [];
        $count = rp_copy($srcRoot, $ROOT, '', $restored, $failed);
        rp_rrmdir($tmpDir);

        $log[] = "تم استبدال $count ملفاً";
        if ($restored) {
            $log[] = 'ملفات كانت مفقودة أو فارغة واستُعيدت (' . count($restored) . '): '
                   . implode('، ', array_slice($restored, 0, 12))
                   . (count($restored) > 12 ? ' وغيرها' : '');
        } else {
            $log[] = 'كل الملفات كانت موجودة وسليمة الحجم — تم تحديثها لأحدث نسخة';
        }
        if ($failed) {
            $log[] = 'تعذّرت كتابة ' . count($failed) . ' ملفاً (صلاحيات): ' . implode('، ', array_slice($failed, 0, 6));
        }
        $newVer = trim((string)@file_get_contents($ROOT . '/VERSION'));
        $log[] = 'الإصدار الحالي: ' . ($newVer ?: 'غير معروف');
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---------- فحص حالة ملفات النواة ---------- */
$coreFiles = [
    'core/bootstrap.php', 'core/helpers.php', 'core/auth.php', 'core/audit.php',
    'core/stats.php', 'core/layout.php', 'core/updater.php', 'core/notifications.php',
    'core/push.php', 'core/backup.php', 'core/errors.php', 'core/xlsx_reader.php',
    'index.php', 'login.php', 'VERSION',
];
$fileRows = [];
foreach ($coreFiles as $f) {
    $p = $ROOT . '/' . $f;
    $size = is_file($p) ? (int)filesize($p) : -1;
    $fileRows[] = ['name' => $f, 'size' => $size,
        'state' => $size < 0 ? 'مفقود' : ($size === 0 ? 'فارغ' : 'سليم')];
}
$brokenCount = count(array_filter($fileRows, fn($r) => $r['state'] !== 'سليم'));
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>إصلاح ملفات المنصة</title>
<style>
:root { --navy:#1C1E4C; --good:#0ca30c; --bad:#c0392b; --line:#e1e0d9; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif; background:#f9f9f7;
       color:#1a1a22; line-height:1.75; padding:20px; }
.wrap { max-width:820px; margin:0 auto; }
h1 { font-size:1.35rem; color:var(--navy); margin-bottom:6px; }
.sub { color:#6b6e80; font-size:0.9rem; margin-bottom:20px; }
.card { background:#fcfcfb; border:1px solid rgba(11,11,11,.1); border-radius:12px;
        padding:18px 20px; margin-bottom:16px; }
h2 { font-size:1rem; color:var(--navy); margin-bottom:10px; }
p { font-size:0.92rem; color:#52514e; margin-bottom:10px; }
ul { padding-right:20px; font-size:0.9rem; color:#52514e; }
li { margin-bottom:5px; }
table { width:100%; border-collapse:collapse; font-size:0.86rem; }
th { text-align:right; font-size:0.78rem; color:#6b6e80; padding:7px; border-bottom:2px solid var(--line); }
td { padding:7px; border-bottom:1px solid var(--line); }
.badge { display:inline-block; padding:1px 10px; border-radius:999px; font-size:0.74rem; font-weight:700; }
.b-ok { background:#e6f4e6; color:#006300; }
.b-bad { background:#fbe7e7; color:#a32424; }
.btn { display:inline-block; padding:11px 22px; border-radius:9px; border:0; cursor:pointer;
       font-family:inherit; font-weight:700; font-size:0.95rem; text-decoration:none; }
.p { background:var(--navy); color:#fff; }
.g { background:transparent; color:#52514e; border:1px solid var(--line); }
.alert { padding:12px 16px; border-radius:9px; margin-bottom:14px; font-size:0.92rem; }
.a-bad { background:#fbe7e7; color:#a32424; border:1px solid #f1c1c1; }
.a-ok { background:#e6f4e6; color:#006300; border:1px solid #bfe3bf; }
.a-info { background:#e5eefb; color:#1c5cab; border:1px solid #b7d3f6; }
.logline { padding:4px 0; font-size:0.9rem; color:#006300; }
code { background:#f4f6fa; border:1px solid var(--line); border-radius:4px; padding:1px 6px;
       font-size:0.85em; direction:ltr; display:inline-block; }
</style>
</head>
<body>
<div class="wrap">
    <h1>&#128295; إصلاح ملفات المنصة</h1>
    <p class="sub">أداة مستقلة تعيد جلب كل ملفات النظام من المستودع — تعمل حتى لو تعطّل نظام التحديث نفسه</p>

    <?php if (!$isAdmin): ?>
        <div class="alert a-bad"><?= htmlspecialchars($authNote, ENT_QUOTES, 'UTF-8') ?></div>
        <a class="btn p" href="login.php">تسجيل الدخول</a>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert a-bad"><strong>فشل الإصلاح:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="alert a-ok"><strong>تم الإصلاح بنجاح.</strong> جميع ملفات النظام الآن مطابقة لأحدث نسخة في المستودع.</div>
            <div class="card">
                <?php foreach ($log as $l): ?>
                    <div class="logline">✓ <?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
            <div class="card">
                <h2>الخطوة التالية</h2>
                <p>افتح المنصة ثم <strong>«تحديث النظام» ← «ترقية قاعدة البيانات فقط»</strong> لإكمال أي ترقيات معلقة.</p>
                <a class="btn p" href="index.php?m=update">الذهاب لصفحة التحديث</a>
            </div>
        <?php else: ?>

            <div class="card">
                <h2>حالة ملفات النواة</h2>
                <?php if ($brokenCount): ?>
                    <div class="alert a-bad">
                        يوجد <strong><?= $brokenCount ?></strong> ملفاً مفقوداً أو فارغاً — هذا سبب الخلل.
                        اضغط زر الإصلاح أدناه.
                    </div>
                <?php else: ?>
                    <div class="alert a-info">كل ملفات النواة موجودة وغير فارغة. يمكنك تشغيل الإصلاح للتأكد من مطابقتها لأحدث نسخة.</div>
                <?php endif; ?>
                <table>
                    <thead><tr><th>الملف</th><th style="width:22%">الحجم</th><th style="width:20%">الحالة</th></tr></thead>
                    <tbody>
                    <?php foreach ($fileRows as $r): ?>
                    <tr>
                        <td dir="ltr"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $r['size'] < 0 ? '—' : number_format($r['size']) . ' بايت' ?></td>
                        <td><span class="badge <?= $r['state'] === 'سليم' ? 'b-ok' : 'b-bad' ?>"><?= $r['state'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>تشغيل الإصلاح</h2>
                <p>سيتم تنزيل كل ملفات النظام من <code><?= htmlspecialchars($repo . ' / ' . $branch, ENT_QUOTES, 'UTF-8') ?></code>
                   واستبدال الحالية بها.</p>
                <ul>
                    <li><strong>لن تُمس</strong> بياناتك ولا <code>config.php</code> ولا مجلدا الرفع والنسخ الاحتياطي</li>
                    <li>سيخبرك التقرير بأسماء الملفات التي كانت ناقصة فعلاً</li>
                    <li>قد تستغرق العملية دقيقة — لا تغلق الصفحة</li>
                </ul>
                <form method="post" onsubmit="this.querySelector('button').textContent='جارٍ الإصلاح، انتظر...'; this.querySelector('button').disabled=true;">
                    <input type="hidden" name="confirm" value="yes">
                    <button type="submit" class="btn p">&#8635; إصلاح جميع الملفات الآن</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>بعد الانتهاء</h2>
            <p>يُنصح بحذف <code>repair.php</code> و <code>diagnose.php</code> من مجلد المنصة.</p>
            <a class="btn g" href="index.php">العودة للمنصة</a>
            <a class="btn g" href="diagnose.php">فحص النظام</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
