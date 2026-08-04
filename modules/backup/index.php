<?php
/** موديول النسخ الاحتياطي والاستعادة */
if (!defined('SBA')) exit;
require_once SBA_ROOT . '/core/backup.php';

$a = $_GET['a'] ?? 'view';

/* تنزيل نسخة احتياطية */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'download') {
    $sql = backup_generate_sql();
    audit_log('backup', 'database', 0, 'تنزيل نسخة احتياطية كاملة');
    $filename = 'sba_backup_' . date('Y-m-d_Hi') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

/* استعادة */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'restore') {
    if (empty($_FILES['file']['tmp_name'])) {
        flash_set('danger', 'يرجى اختيار ملف النسخة الاحتياطية (.sql)');
        redirect(url('backup'));
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        flash_set('danger', 'الملف يجب أن يكون بصيغة .sql');
        redirect(url('backup'));
    }
    try {
        $content = file_get_contents($_FILES['file']['tmp_name']);
        $count = backup_restore_sql($content);
        audit_log('restore', 'database', 0, "استعادة نسخة احتياطية ($count أمراً)");
        flash_set('success', "تمت الاستعادة بنجاح — تم تنفيذ $count أمراً");
    } catch (Throwable $e) {
        flash_set('danger', 'فشلت الاستعادة: ' . $e->getMessage());
    }
    redirect(url('backup'));
}

/* إحصائيات الجداول */
$tables = [];
$like = str_replace('_', '\\_', DB_PREFIX) . '%';
foreach (db()->query("SHOW TABLES LIKE '$like'") as $row) {
    $t = array_values($row)[0];
    $count = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $tables[] = ['name' => $t, 'rows' => $count];
}

layout_header('النسخ الاحتياطي');
?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>&#8681; تنزيل نسخة احتياطية</h2></div>
        <p>يتم توليد ملف SQL كامل يشمل بنية الجداول وكل البيانات، ويمكن استعادته من هذه الشاشة أو عبر phpMyAdmin.</p>
        <form method="post" action="<?= e(url('backup', ['a' => 'download'])) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">تنزيل النسخة الاحتياطية الآن</button>
        </form>
        <hr>
        <h3 class="section-title">محتوى قاعدة البيانات</h3>
        <table class="table">
            <thead><tr><th>الجدول</th><th>عدد الصفوف</th></tr></thead>
            <tbody>
            <?php foreach ($tables as $t): ?>
            <tr><td dir="ltr"><?= e($t['name']) ?></td><td><?= number_format($t['rows']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-header"><h2>&#8682; استعادة نسخة احتياطية</h2></div>
        <div class="alert alert-danger">
            <strong>تحذير:</strong> الاستعادة تحذف البيانات الحالية وتستبدلها بمحتوى الملف بالكامل.
            يُنصح بتنزيل نسخة احتياطية حديثة قبل الاستعادة.
        </div>
        <form method="post" action="<?= e(url('backup', ['a' => 'restore'])) ?>" enctype="multipart/form-data"
              onsubmit="return confirm('هل أنت متأكد؟ سيتم استبدال جميع البيانات الحالية بمحتوى النسخة الاحتياطية.')">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>ملف النسخة الاحتياطية (.sql)</label>
                <input type="file" name="file" accept=".sql" required>
            </div>
            <button type="submit" class="btn btn-danger">استعادة النسخة الاحتياطية</button>
        </form>
    </div>
</div>
<?php
layout_footer();
