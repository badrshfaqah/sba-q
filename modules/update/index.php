<?php
/** موديول التحديث من GitHub (مدير النظام فقط) */
if (!defined('SBA')) exit;
require_once SBA_ROOT . '/core/updater.php';

$a = $_GET['a'] ?? 'view';
$checkResult = null;
$checkError  = null;
$updateLog   = null;

/* فحص التحديثات */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'check') {
    try {
        $remote = updater_remote_version();
        $checkResult = [
            'remote' => $remote,
            'newer'  => version_compare($remote, SBA_VERSION, '>'),
        ];
    } catch (Throwable $e) {
        $checkError = safe_error($e, 'تعذر فحص التحديثات');
    }
}

/* تنفيذ التحديث */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'run') {
    try {
        @set_time_limit(300);
        $updateLog = updater_run();
        $newVersion = trim((string)@file_get_contents(SBA_ROOT . '/VERSION')) ?: 'غير معروف';
        audit_log('update', 'system', 0, 'تحديث النظام من GitHub إلى الإصدار ' . $newVersion);
    } catch (Throwable $e) {
        $checkError = 'فشل التحديث: ' . safe_error($e);
        audit_log('update', 'system', 0, 'فشل تحديث النظام: ' . $e->getMessage());
    }
}

/* إصدار قاعدة البيانات الحالي */
$dbVersion = '1.0.0';
try {
    $st = db()->prepare('SELECT svalue FROM ' . tbl('settings') . " WHERE skey='db_version'");
    $st->execute();
    $dbVersion = (string)($st->fetchColumn() ?: '1.0.0');
} catch (Throwable $e) {}

$oldBackups = [];
$backupDir = SBA_ROOT . '/backups';
if (is_dir($backupDir)) {
    foreach (glob($backupDir . '/pre_update_*.sql') ?: [] as $f) {
        $oldBackups[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($oldBackups, fn($x, $y) => $y['time'] <=> $x['time']);
}

layout_header('تحديث النظام');
?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>&#128260; التحديث من GitHub</h2></div>

        <div class="detail-grid">
            <div><span class="muted">إصدار الملفات الحالي:</span> <strong><?= e(SBA_VERSION) ?></strong></div>
            <div><span class="muted">إصدار قاعدة البيانات:</span> <strong><?= e($dbVersion) ?></strong></div>
            <div><span class="muted">المستودع:</span> <span dir="ltr"><?= e(updater_repo()) ?></span></div>
            <div><span class="muted">الفرع:</span> <span dir="ltr"><?= e(updater_branch()) ?></span></div>
        </div>

        <?php if ($checkError): ?>
            <div class="alert alert-danger"><?= e($checkError) ?></div>
        <?php endif; ?>

        <?php if ($updateLog): ?>
            <div class="alert alert-success">تم تنفيذ التحديث بنجاح — أعد تحميل الصفحة لرؤية الإصدار الجديد.</div>
            <div class="update-log">
                <?php foreach ($updateLog as $line): ?>
                <div class="update-log-line">✓ <?= e($line) ?></div>
                <?php endforeach; ?>
            </div>
            <a class="btn btn-primary" href="<?= e(url('update')) ?>">إعادة تحميل الصفحة</a>
        <?php else: ?>

            <?php if ($checkResult): ?>
                <?php if ($checkResult['newer']): ?>
                    <div class="alert alert-info">
                        يتوفر إصدار جديد: <strong><?= e($checkResult['remote']) ?></strong>
                        (إصدارك الحالي <?= e(SBA_VERSION) ?>)
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        أنت على أحدث إصدار (<?= e($checkResult['remote']) ?>) ✓
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="form-actions">
                <form method="post" action="<?= e(url('update', ['a' => 'check'])) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary">فحص التحديثات</button>
                </form>
                <?php if ($checkResult): ?>
                <form method="post" action="<?= e(url('update', ['a' => 'run'])) ?>" class="inline-form"
                      onsubmit="return confirm('سيتم أخذ نسخة احتياطية تلقائية ثم تحديث ملفات النظام وترقية قاعدة البيانات. متابعة؟')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn <?= $checkResult['newer'] ? 'btn-primary' : 'btn-ghost' ?>">
                        <?= $checkResult['newer'] ? 'تحديث الآن إلى ' . e($checkResult['remote']) : 'إعادة تنزيل الملفات (نفس الإصدار)' ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        $logFile = SBA_ROOT . '/backups/update.log';
        if (is_file($logFile)):
            $logTxt = (string)file_get_contents($logFile);
            $failed = strpos($logTxt, '!!') !== false;
        ?>
        <hr>
        <h3 class="section-title">سجل آخر عملية تحديث
            <?= $failed ? '<span class="badge badge-danger">توقفت بخطأ</span>' : '' ?></h3>
        <pre class="update-raw-log"><?= e($logTxt) ?></pre>
        <p class="muted">هذا السجل يُكتب خطوة بخطوة، فيبيّن أين توقفت العملية حتى لو ظهر خطأ 500.</p>
        <?php endif; ?>

        <hr>
        <h3 class="section-title">كيف يعمل التحديث؟</h3>
        <ol class="update-steps">
            <li>نسخة احتياطية تلقائية لقاعدة البيانات في مجلد <code>backups/</code></li>
            <li>تنزيل أحدث نسخة من المستودع (ZIP) وفك ضغطها</li>
            <li>استبدال ملفات النظام — مع حماية <code>config.php</code> ومجلدي الرفع والنسخ</li>
            <li>إنشاء الجداول الجديدة وتنفيذ ترقيات قاعدة البيانات بالترتيب</li>
        </ol>
    </div>

    <div class="card">
        <div class="card-header"><h2>&#128190; النسخ الاحتياطية قبل التحديث</h2></div>
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
        <p class="muted">للاستعادة: استخدم شاشة «النسخ الاحتياطي» وارفع الملف المطلوب من مجلد backups عبر FTP.</p>
        <?php endif; ?>
    </div>
</div>
<?php
layout_footer();
