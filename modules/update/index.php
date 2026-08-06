<?php
/**
 * موديول تحديث النظام (مدير النظام فقط)
 * يجمع في صفحة واحدة: إعدادات الاتصال بـ GitHub + فحص التحديثات + التنفيذ + السجل
 */
if (!defined('SBA')) exit;

/* تحميل دفاعي: إن نقص ملف بسبب رفع غير مكتمل نعرض رسالة واضحة بدل خطأ 500 */
$updaterFile = SBA_ROOT . '/core/updater.php';
$updaterReady = is_file($updaterFile);
if ($updaterReady) {
    require_once $updaterFile;
    $updaterReady = function_exists('updater_repo') && function_exists('updater_run');
}

/** بديل آمن لدالة عرض الأخطاء إن كان core/helpers.php قديماً */
if (!function_exists('safe_error')) {
    function safe_error(Throwable $e, string $fallback = 'حدث خطأ أثناء تنفيذ العملية'): string
    {
        return $e instanceof PDOException ? $fallback : ($e->getMessage() ?: $fallback);
    }
}

$a = $_GET['a'] ?? 'view';
$checkResult = null;
$checkError  = null;
$updateLog   = null;
$repaired    = false;

/* ---------- حفظ إعدادات الاتصال بالمستودع ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'save_repo') {
    $repo   = input('update_repo');
    $branch = input('update_branch');
    $token  = trim((string)($_POST['update_token'] ?? ''));
    if ($repo !== '' && !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        flash_set('danger', 'صيغة المستودع غير صحيحة — المطلوب: username/repo');
    } else {
        if ($repo !== '')   setting_save('update_repo', $repo);
        if ($branch !== '') setting_save('update_branch', $branch);
        if ($token === 'REMOVE')      setting_save('update_token', '');
        elseif ($token !== '')        setting_save('update_token', $token);
        audit_log('update', 'settings', 0, 'تحديث إعدادات الاتصال بالمستودع');
        flash_set('success', 'تم حفظ إعدادات المستودع');
    }
    redirect(url('update'));
}

/* ---------- فحص التحديثات ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'check' && $updaterReady) {
    try {
        $remote = updater_remote_version();
        $checkResult = ['remote' => $remote, 'newer' => version_compare($remote, SBA_VERSION, '>')];
    } catch (Throwable $e) {
        $checkError = safe_error($e, 'تعذر فحص التحديثات');
    }
}

/* ---------- تنفيذ التحديث ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'run' && $updaterReady) {
    try {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');
        $updateLog = updater_run();
        $newVersion = trim((string)@file_get_contents(SBA_ROOT . '/VERSION')) ?: 'غير معروف';
        audit_log('update', 'system', 0, 'تحديث النظام من GitHub إلى الإصدار ' . $newVersion);
    } catch (Throwable $e) {
        $checkError = 'فشل التحديث: ' . safe_error($e);
        audit_log('update', 'system', 0, 'فشل تحديث النظام');
    }
}

/* ---------- إعادة رفع كل الملفات (إصلاح رفع غير مكتمل) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'repair' && $updaterReady) {
    try {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');
        $updateLog = updater_run();
        $repaired = true;
        audit_log('update', 'system', 0, 'إعادة رفع جميع ملفات النظام من المستودع');
    } catch (Throwable $e) {
        $checkError = 'فشلت إعادة الرفع: ' . safe_error($e);
    }
}

/* ---------- ترقية قاعدة البيانات فقط (بعد الرفع اليدوي) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'migrate') {
    try {
        @set_time_limit(120);
        require_once SBA_ROOT . '/install/migrations.php';
        $updateLog = sba_run_migrations(db(), DB_PREFIX, SBA_VERSION);
        audit_log('update', 'system', 0, 'ترقية قاعدة البيانات يدوياً إلى ' . SBA_VERSION);
    } catch (Throwable $e) {
        $checkError = 'فشلت الترقية: ' . safe_error($e);
    }
}

/* ---------- بيانات العرض (كلها محصّنة) ---------- */
$dbVersion = '1.0.0';
try {
    $st = db()->prepare('SELECT svalue FROM ' . tbl('settings') . " WHERE skey='db_version'");
    $st->execute();
    $dbVersion = (string)($st->fetchColumn() ?: '1.0.0');
} catch (Throwable $e) { /* الجدول غير جاهز */ }

$repoName   = $updaterReady ? updater_repo()   : (string)setting('update_repo', 'badrshfaqah/sba-q');
$branchName = $updaterReady ? updater_branch() : (string)setting('update_branch', 'main');
$hasToken   = trim((string)setting('update_token', '')) !== '';

$oldBackups = [];
try {
    $backupDir = SBA_ROOT . '/backups';
    if (is_dir($backupDir)) {
        foreach ((array)glob($backupDir . '/pre_update_*.sql') as $f) {
            if (!is_file($f)) continue;
            $oldBackups[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
        }
        usort($oldBackups, function ($x, $y) { return $y['time'] - $x['time']; });
        $oldBackups = array_slice($oldBackups, 0, 8);
    }
} catch (Throwable $e) { /* تجاهل */ }

$logTxt = '';
try {
    $logFile = SBA_ROOT . '/backups/update.log';
    if (is_file($logFile)) $logTxt = (string)file_get_contents($logFile);
} catch (Throwable $e) { /* تجاهل */ }

$dbBehind = version_compare($dbVersion, SBA_VERSION, '<');

layout_header('تحديث النظام');
?>

<?php if (!$updaterReady): ?>
<div class="card">
    <div class="alert alert-danger">
        <strong>ملف نظام التحديث غير مكتمل</strong> —
        الملف <code dir="ltr">core/updater.php</code>
        <?= is_file(SBA_ROOT . '/core/updater.php')
            ? 'موجود لكنه فارغ أو مبتور (حجمه ' . number_format((int)filesize(SBA_ROOT . '/core/updater.php')) . ' بايت)'
            : 'غير موجود' ?>،
        وغالباً السبب رفع FTP لم يكتمل.
    </div>
    <p>لا داعي لـ FTP — استخدم <strong>أداة الإصلاح المستقلة</strong>، وهي لا تعتمد على هذا الملف
       فتعمل رغم تعطّله. ستجلب كل ملفات النظام من المستودع وتخبرك بما كان ناقصاً.</p>
    <a class="btn btn-primary" href="repair.php">&#128295; فتح أداة الإصلاح</a>
</div>
<?php endif; ?>

<?php if ($checkError): ?>
    <div class="alert alert-danger"><?= e($checkError) ?></div>
<?php endif; ?>

<?php if ($updateLog): ?>
<div class="card">
    <div class="alert alert-success">
        <?= $repaired
            ? 'تمت إعادة رفع جميع الملفات بنجاح — أي ملف كان ناقصاً أو مبتوراً استُبدل بنسخته الصحيحة.'
            : 'تمت العملية بنجاح — أعد تحميل الصفحة لرؤية الإصدار الجديد.' ?>
    </div>
    <div class="update-log">
        <?php foreach ($updateLog as $line): ?>
        <div class="update-log-line">✓ <?= e($line) ?></div>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-primary" href="<?= e(url('update')) ?>">إعادة تحميل الصفحة</a>
</div>
<?php endif; ?>

<!-- حالة الإصدارات -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">إصدار الملفات</div>
        <div class="kpi-value"><?= e(SBA_VERSION) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">إصدار قاعدة البيانات</div>
        <div class="kpi-value <?= $dbBehind ? 'kpi-bad' : '' ?>"><?= e($dbVersion) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">المستودع</div>
        <div class="kpi-value" style="font-size:0.95rem" dir="ltr"><?= e($repoName) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">الفرع</div>
        <div class="kpi-value" style="font-size:0.95rem" dir="ltr"><?= e($branchName) ?></div>
    </div>
</div>

<?php if ($dbBehind): ?>
<div class="alert alert-info">
    <strong>قاعدة البيانات أقدم من الملفات</strong> — الملفات على <?= e(SBA_VERSION) ?>
    وقاعدة البيانات على <?= e($dbVersion) ?>. اضغط «ترقية قاعدة البيانات فقط» أدناه.
</div>
<?php endif; ?>

<div class="grid-2">
    <!-- التحديث -->
    <div class="card">
        <div class="card-header"><h2>&#128260; التحديث من المستودع</h2></div>

        <?php if ($checkResult): ?>
            <?php if ($checkResult['newer']): ?>
                <div class="alert alert-info">
                    يتوفر إصدار جديد: <strong><?= e($checkResult['remote']) ?></strong>
                    (إصدارك الحالي <?= e(SBA_VERSION) ?>)
                </div>
            <?php else: ?>
                <div class="alert alert-success">أنت على أحدث إصدار (<?= e($checkResult['remote']) ?>) ✓</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="form-actions">
            <form method="post" action="<?= e(url('update', ['a' => 'check'])) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary" <?= $updaterReady ? '' : 'disabled' ?>>فحص التحديثات</button>
            </form>
            <?php if ($checkResult): ?>
            <form method="post" action="<?= e(url('update', ['a' => 'run'])) ?>" class="inline-form"
                  data-confirm="سيتم أخذ نسخة احتياطية تلقائية ثم تحديث ملفات النظام وترقية قاعدة البيانات. متابعة؟">
                <?= csrf_field() ?>
                <button type="submit" class="btn <?= $checkResult['newer'] ? 'btn-primary' : 'btn-ghost' ?>">
                    <?= $checkResult['newer'] ? 'تحديث الآن إلى ' . e($checkResult['remote']) : 'إعادة تنزيل الملفات' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <hr>
        <h3 class="section-title">&#128295; إصلاح: إعادة رفع جميع الملفات</h3>
        <p class="muted">يعيد جلب <strong>كل</strong> ملفات النظام من المستودع واستبدالها — حتى لو كان
           الإصدار نفسه. استخدمه إذا ظهرت أخطاء غريبة أو تعطّلت صفحة، فغالباً السبب ملف لم يكتمل رفعه
           عبر FTP. سيخبرك التقرير بأسماء الملفات التي كانت ناقصة فعلاً.</p>
        <p class="muted">&#128274; آمن تماماً: <code>config.php</code> ومجلدا الرفع والنسخ الاحتياطي لا تُمس،
           وتُؤخذ نسخة احتياطية من قاعدة البيانات قبل البدء.</p>
        <form method="post" action="<?= e(url('update', ['a' => 'repair'])) ?>" class="inline-form"
              data-confirm="سيتم تنزيل جميع ملفات النظام من المستودع واستبدال الحالية بها. بياناتك وإعداداتك لن تُمس. متابعة؟">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger-ghost" <?= $updaterReady ? '' : 'disabled' ?>>
                &#8635; إعادة رفع جميع الملفات
            </button>
        </form>

        <hr>
        <h3 class="section-title">بعد الرفع اليدوي عبر FTP</h3>
        <p class="muted">رفع الملفات يدوياً لا ينشئ الجداول الجديدة — اضغط هنا لتنفيذ ترقيات
           قاعدة البيانات المعلقة فقط، بلا تنزيل ولا استبدال ملفات.</p>
        <form method="post" action="<?= e(url('update', ['a' => 'migrate'])) ?>" class="inline-form"
              data-confirm="سيتم تنفيذ ترقيات قاعدة البيانات المعلقة فقط. متابعة؟">
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= $dbBehind ? 'btn-primary' : 'btn-ghost' ?>">
                ترقية قاعدة البيانات فقط
            </button>
        </form>

        <hr>
        <h3 class="section-title">كيف يعمل التحديث؟</h3>
        <ol class="update-steps">
            <li>نسخة احتياطية تلقائية لقاعدة البيانات في <code>backups/</code></li>
            <li>تنزيل أحدث نسخة من المستودع وفك ضغطها</li>
            <li>استبدال ملفات النظام — مع حماية <code>config.php</code> ومجلدي الرفع والنسخ</li>
            <li>إنشاء الجداول الجديدة وتنفيذ ترقيات قاعدة البيانات</li>
        </ol>
    </div>

    <!-- إعدادات الاتصال بالمستودع (كانت في صفحة الإعدادات) -->
    <div class="card">
        <div class="card-header"><h2>&#9881;&#65039; إعدادات الاتصال بالمستودع</h2></div>
        <form method="post" action="<?= e(url('update', ['a' => 'save_repo'])) ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>المستودع (username/repo)</label>
                <input type="text" name="update_repo" value="<?= e($repoName) ?>" dir="ltr">
            </div>
            <div class="form-group">
                <label>الفرع</label>
                <input type="text" name="update_branch" value="<?= e($branchName) ?>" dir="ltr">
            </div>
            <div class="form-group">
                <label>
                    رمز الوصول GitHub Token
                    <?= $hasToken
                        ? '<span class="badge badge-success">مسجّل ✓</span>'
                        : '<span class="badge badge-muted">غير مسجّل — للمستودع الخاص فقط</span>' ?>
                </label>
                <input type="password" name="update_token" value="" dir="ltr" autocomplete="new-password"
                       placeholder="اتركه فارغاً للإبقاء على الحالي — أو اكتب REMOVE لحذفه">
                <small class="muted">
                    مطلوب فقط إذا كان المستودع خاصاً (Private). أنشئ رمزاً من نوع Fine-grained
                    بصلاحية قراءة Contents على هذا المستودع تحديداً.
                </small>
            </div>
            <button type="submit" class="btn btn-primary">حفظ إعدادات المستودع</button>
        </form>

        <hr>
        <h3 class="section-title">&#128190; النسخ الاحتياطية قبل التحديث</h3>
        <?php if (!$oldBackups): ?>
            <div class="empty-state small"><p>لا توجد نسخ بعد — تُنشأ تلقائياً مع كل تحديث</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>الملف</th><th>الحجم</th><th>التاريخ</th></tr></thead>
            <tbody>
            <?php foreach ($oldBackups as $b): ?>
            <tr>
                <td dir="ltr"><?= e($b['name']) ?></td>
                <td><?= number_format($b['size'] / 1024, 0) ?> KB</td>
                <td><?= date('Y-m-d H:i', $b['time']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">للاستعادة: شاشة «النسخ الاحتياطي» وارفع الملف من مجلد backups عبر FTP.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($logTxt !== ''): ?>
<div class="card">
    <div class="card-header">
        <h2>سجل آخر عملية تحديث</h2>
        <?= strpos($logTxt, '!!') !== false ? '<span class="badge badge-danger">توقفت بخطأ</span>' : '' ?>
    </div>
    <pre class="update-raw-log"><?= e($logTxt) ?></pre>
    <p class="muted">يُكتب خطوة بخطوة، فيبيّن أين توقفت العملية بالضبط.</p>
</div>
<?php endif; ?>

<?php
layout_footer();
