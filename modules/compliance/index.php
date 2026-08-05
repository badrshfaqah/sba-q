<?php
/** موديول التزام البث: شاشة الألوان + التسجيل بنقطة زمنية + استيراد Excel */
if (!defined('SBA')) exit;
require_once SBA_ROOT . '/core/xlsx_reader.php';

$user = current_user();
$a = $_GET['a'] ?? 'board';
$stations = db()->query('SELECT id, name FROM ' . tbl('stations') . ' WHERE active=1 ORDER BY name')->fetchAll();

/* ---------- حفظ تسجيل نقطة زمنية ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'save') {
    require_can('compliance.edit');
    $date = input('check_date');
    $time = input('check_time');
    $statuses = $_POST['status'] ?? [];
    if ($date === '' || $time === '') {
        flash_set('danger', 'التاريخ والوقت مطلوبان');
        redirect(url('compliance', ['a' => 'record']));
    }
    $checkAt = $date . ' ' . substr($time, 0, 5) . ':00';
    $saved = 0;
    $st = db()->prepare('INSERT INTO ' . tbl('compliance') . ' (station_id, check_at, status, user_id)
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), user_id = VALUES(user_id)');
    foreach ($stations as $s) {
        $v = $statuses[$s['id']] ?? '';
        if ($v !== '0' && $v !== '1') continue; // بدون اختيار = تجاهل
        $st->execute([(int)$s['id'], $checkAt, (int)$v, (int)$user['id']]);
        $saved++;
    }
    audit_log('create', 'compliance', 0, "تسجيل التزام عند $checkAt لعدد $saved إذاعة");
    flash_set('success', "تم حفظ حالة $saved إذاعة عند النقطة الزمنية " . substr($checkAt, 0, 16));
    redirect(url('compliance', ['date' => $date]));
}

/* ---------- استيراد Excel ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'import') {
    require_can('compliance.import');
    $date = input('import_date');
    $mode = input('mode') === 'replace' ? 'replace' : 'append';
    if ($date === '' || empty($_FILES['file']['tmp_name'])) {
        flash_set('danger', 'التاريخ والملف مطلوبان');
        redirect(url('compliance', ['a' => 'import']));
    }
    try {
        $rows = spreadsheet_read($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        if (count($rows) < 2) throw new RuntimeException('الملف فارغ أو لا يحتوي على بيانات');

        // الصف الأول: عناوين (عمود الوقت + أسماء الإذاعات)
        $header = $rows[0];
        $stationMap = []; // فهرس العمود => station_id
        $byName = [];
        foreach ($stations as $s) $byName[trim($s['name'])] = (int)$s['id'];
        $unknown = [];
        foreach ($header as $colIdx => $colName) {
            if ($colIdx === 0) continue;
            $colName = trim($colName);
            if ($colName === '') continue;
            if (isset($byName[$colName])) {
                $stationMap[$colIdx] = $byName[$colName];
            } else {
                $unknown[] = $colName;
            }
        }
        if (!$stationMap) {
            throw new RuntimeException('لم يتم التعرف على أي إذاعة في أعمدة الملف. الأعمدة يجب أن تطابق أسماء الإذاعات المسجلة.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        // وضع الاستبدال: حذف بيانات اليوم للإذاعات الواردة في الملف
        if ($mode === 'replace') {
            $ids = implode(',', array_map('intval', array_values($stationMap)));
            $del = $pdo->prepare('DELETE FROM ' . tbl('compliance') . "
                WHERE station_id IN ($ids) AND check_at >= ? AND check_at <= ?");
            $del->execute([$date . ' 00:00:00', $date . ' 23:59:59']);
        }

        $ins = $pdo->prepare('INSERT ' . ($mode === 'append' ? 'IGNORE ' : '') . 'INTO ' . tbl('compliance') . '
            (station_id, check_at, status, user_id) VALUES (?,?,?,?)
            ' . ($mode === 'replace' ? 'ON DUPLICATE KEY UPDATE status = VALUES(status)' : ''));

        $inserted = 0; $skipped = 0;
        foreach (array_slice($rows, 1) as $row) {
            $time = cell_to_time((string)($row[0] ?? ''));
            if ($time === null) { $skipped++; continue; }
            $checkAt = $date . ' ' . $time . ':00';
            foreach ($stationMap as $colIdx => $stationId) {
                $status = cell_to_compliance((string)($row[$colIdx] ?? ''));
                if ($status === null) continue; // تجاهل الفارغ
                $ins->execute([$stationId, $checkAt, $status, (int)$user['id']]);
                if ($ins->rowCount() > 0) $inserted++; else $skipped++;
            }
        }
        $pdo->commit();

        audit_log('import', 'compliance', 0, "استيراد التزام ليوم $date — إضافة $inserted سجلاً");
        $msg = "تم الاستيراد بنجاح: $inserted سجلاً" . ($skipped ? "، تم تجاهل $skipped (مكرر أو فارغ)" : '');
        if ($unknown) $msg .= '. أعمدة لم يتم التعرف عليها: ' . implode('، ', array_slice($unknown, 0, 5));
        flash_set('success', $msg);
        redirect(url('compliance', ['date' => $date]));
    } catch (Throwable $ex) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        flash_set('danger', 'فشل الاستيراد: ' . safe_error($ex, 'تحقق من صيغة الملف'));
        redirect(url('compliance', ['a' => 'import']));
    }
}

/* ---------- شاشة التسجيل ---------- */
if ($a === 'record') {
    require_can('compliance.edit');
    layout_header('تسجيل التزام البث');
    ?>
    <div class="card card-narrow">
        <div class="card-header"><h2>تسجيل نقطة زمنية جديدة</h2></div>
        <form method="post" action="<?= e(url('compliance', ['a' => 'save'])) ?>">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>التاريخ *</label>
                    <input type="date" name="check_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>الوقت (24 ساعة) *</label>
                    <input type="time" name="check_time" value="<?= date('H:i') ?>" required>
                </div>
            </div>
            <div class="table-wrap"><table class="table compliance-record">
                <thead><tr><th>الإذاعة</th><th>الحالة</th></tr></thead>
                <tbody>
                <?php foreach ($stations as $s): ?>
                <tr>
                    <td><strong><?= e($s['name']) ?></strong></td>
                    <td>
                        <div class="status-toggle">
                            <label class="status-option ok">
                                <input type="radio" name="status[<?= (int)$s['id'] ?>]" value="1"><span>ملتزم</span>
                            </label>
                            <label class="status-option bad">
                                <input type="radio" name="status[<?= (int)$s['id'] ?>]" value="0"><span>غير ملتزم</span>
                            </label>
                            <label class="status-option none">
                                <input type="radio" name="status[<?= (int)$s['id'] ?>]" value="" checked><span>بدون</span>
                            </label>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">حفظ النقطة الزمنية</button>
                <a href="<?= e(url('compliance')) ?>" class="btn btn-ghost">إلغاء</a>
            </div>
        </form>
    </div>
    <?php
    layout_footer();
    return;
}

/* ---------- شاشة الاستيراد ---------- */
if ($a === 'import') {
    require_can('compliance.import');
    layout_header('استيراد الالتزام من Excel');
    ?>
    <div class="card card-narrow">
        <div class="card-header"><h2>استيراد ملف Excel / CSV</h2></div>
        <div class="alert alert-info">
            صيغة الملف المطلوبة: العمود الأول <strong>الوقت</strong> (مثل 14:30)،
            وبقية الأعمدة بأسماء الإذاعات كما هي مسجلة في النظام،
            والقيم: <strong>ملتزم</strong> أو <strong>غير ملتزم</strong> (أو 1/0).
            الخلايا الفارغة يتم تجاهلها تلقائياً.
        </div>
        <form method="post" action="<?= e(url('compliance', ['a' => 'import'])) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>تاريخ البيانات *</label>
                <input type="date" name="import_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>الملف (xlsx أو csv) *</label>
                <input type="file" name="file" accept=".xlsx,.csv" required>
            </div>
            <div class="form-group">
                <label>طريقة الاستيراد</label>
                <label class="checkbox-label"><input type="radio" name="mode" value="append" checked>
                    إضافة — تجاهل السجلات المكررة والإبقاء على الموجود</label>
                <label class="checkbox-label"><input type="radio" name="mode" value="replace">
                    استبدال — حذف بيانات اليوم للإذاعات الواردة بالملف ثم الإدخال</label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">بدء الاستيراد</button>
                <a href="<?= e(url('compliance')) ?>" class="btn btn-ghost">إلغاء</a>
            </div>
        </form>
    </div>
    <?php
    layout_footer();
    return;
}

/* ---------- الشاشة الرئيسية: جدول الألوان ---------- */
$date = input('date', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$st = db()->prepare('SELECT station_id, DATE_FORMAT(check_at, "%H:%i") AS t, status
    FROM ' . tbl('compliance') . ' WHERE check_at >= ? AND check_at <= ? ORDER BY check_at');
$st->execute([$date . ' 00:00:00', $date . ' 23:59:59']);
$grid = []; $times = [];
foreach ($st->fetchAll() as $r) {
    $grid[$r['t']][(int)$r['station_id']] = (int)$r['status'];
    $times[$r['t']] = true;
}
$times = array_keys($times);
sort($times);

$dayRate = compliance_rate($date, $date);

layout_header('التزام البث');
?>
<div class="card">
    <div class="card-header">
        <h2>شاشة الالتزام — <?= e($date) ?><?= $dayRate !== null ? ' <span class="badge ' . ($dayRate < 80 ? 'badge-danger' : 'badge-success') . '">المعدل ' . fmt_num($dayRate) . '%</span>' : '' ?></h2>
        <div class="header-actions">
            <form method="get" action="index.php" class="inline-form">
                <input type="hidden" name="m" value="compliance">
                <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
            </form>
            <?php if (can('compliance.edit')): ?>
            <a href="<?= e(url('compliance', ['a' => 'record'])) ?>" class="btn btn-primary">+ تسجيل نقطة زمنية</a>
            <?php endif; ?>
            <?php if (can('compliance.import')): ?>
            <a href="<?= e(url('compliance', ['a' => 'import'])) ?>" class="btn btn-ghost">&#8682; استيراد Excel</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$times): ?>
        <div class="empty-state"><p>لا توجد بيانات التزام لهذا اليوم</p></div>
    <?php else: ?>
    <div class="legend">
        <span class="legend-item"><i class="cell-dot ok"></i> ملتزم</span>
        <span class="legend-item"><i class="cell-dot bad"></i> غير ملتزم</span>
        <span class="legend-item"><i class="cell-dot none"></i> لا يوجد فحص</span>
    </div>
    <div class="table-wrap"><table class="table compliance-grid">
        <thead>
            <tr>
                <th class="time-col">الوقت</th>
                <?php foreach ($stations as $s): ?><th><?= e($s['name']) ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($times as $t): ?>
            <tr>
                <td class="time-col"><strong><?= e($t) ?></strong></td>
                <?php foreach ($stations as $s):
                    $v = $grid[$t][(int)$s['id']] ?? null; ?>
                <td class="comp-cell <?= $v === 1 ? 'ok' : ($v === 0 ? 'bad' : 'none') ?>"
                    title="<?= e($s['name']) ?> — <?= e($t) ?>: <?= $v === 1 ? 'ملتزم' : ($v === 0 ? 'غير ملتزم' : 'لا يوجد فحص') ?>">
                    <?= $v === 1 ? '✓' : ($v === 0 ? '✗' : '') ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php
layout_footer();
