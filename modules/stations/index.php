<?php
/** موديول الإذاعات */
if (!defined('SBA')) exit;

if (!can('stations.manage') && !can('data.viewall')) { require_can('stations.manage'); }
$canManage = can('stations.manage');
$a = $_GET['a'] ?? 'list';

/* حفظ (إضافة/تعديل) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $a === 'save') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = input('name');
    $freq = input('frequency');
    $active = isset($_POST['active']) ? 1 : 0;
    if ($name === '') {
        flash_set('danger', 'اسم الإذاعة مطلوب');
        redirect(url('stations', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
    }
    try {
        if ($id) {
            $st = db()->prepare('UPDATE ' . tbl('stations') . ' SET name=?, frequency=?, active=? WHERE id=?');
            $st->execute([$name, $freq ?: null, $active, $id]);
            audit_log('update', 'station', $id, 'تعديل إذاعة: ' . $name);
            flash_set('success', 'تم تحديث الإذاعة بنجاح');
        } else {
            $st = db()->prepare('INSERT INTO ' . tbl('stations') . ' (name, frequency, active) VALUES (?,?,?)');
            $st->execute([$name, $freq ?: null, $active]);
            audit_log('create', 'station', (int)db()->lastInsertId(), 'إضافة إذاعة: ' . $name);
            flash_set('success', 'تمت إضافة الإذاعة بنجاح');
        }
    } catch (PDOException $e) {
        flash_set('danger', $e->getCode() === '23000' ? 'يوجد إذاعة بنفس الاسم مسبقاً' : 'حدث خطأ أثناء الحفظ');
    }
    redirect(url('stations'));
}

/* حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $a === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT name FROM ' . tbl('stations') . ' WHERE id=?');
    $st->execute([$id]);
    if ($name = $st->fetchColumn()) {
        db()->prepare('DELETE FROM ' . tbl('stations') . ' WHERE id=?')->execute([$id]);
        audit_log('delete', 'station', $id, 'حذف إذاعة: ' . $name);
        flash_set('success', 'تم حذف الإذاعة وكل بياناتها المرتبطة');
    }
    redirect(url('stations'));
}

/* نموذج إضافة/تعديل */
if ($canManage && ($a === 'add' || $a === 'edit')) {
    $station = ['id' => 0, 'name' => '', 'frequency' => '', 'active' => 1];
    if ($a === 'edit') {
        $st = db()->prepare('SELECT * FROM ' . tbl('stations') . ' WHERE id=?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $station = $st->fetch();
        if (!$station) { flash_set('danger', 'الإذاعة غير موجودة'); redirect(url('stations')); }
    }
    layout_header($station['id'] ? 'تعديل إذاعة' : 'إضافة إذاعة');
    ?>
    <div class="card card-narrow">
        <form method="post" action="<?= e(url('stations', ['a' => 'save'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$station['id'] ?>">
            <div class="form-group">
                <label>اسم الإذاعة *</label>
                <input type="text" name="name" value="<?= e($station['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>التردد (اختياري)</label>
                <input type="text" name="frequency" value="<?= e($station['frequency']) ?>" placeholder="مثال: FM 98.5">
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="active" <?= $station['active'] ? 'checked' : '' ?>> إذاعة نشطة
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <a href="<?= e(url('stations')) ?>" class="btn btn-ghost">إلغاء</a>
            </div>
        </form>
    </div>
    <?php
    layout_footer();
    return;
}

/* القائمة */
$stations = db()->query('SELECT s.*,
        (SELECT COUNT(*) FROM ' . tbl('programs') . ' p WHERE p.station_id = s.id) AS programs_count
    FROM ' . tbl('stations') . ' s ORDER BY s.name')->fetchAll();

layout_header('الإذاعات');
?>
<div class="card">
    <div class="card-header">
        <h2>قائمة الإذاعات (<?= count($stations) ?>)</h2>
        <?php if ($canManage): ?>
        <a href="<?= e(url('stations', ['a' => 'add'])) ?>" class="btn btn-primary">+ إضافة إذاعة</a>
        <?php endif; ?>
    </div>
    <?php if (!$stations): ?>
        <div class="empty-state"><p>لا توجد إذاعات بعد</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>الإذاعة</th><th>التردد</th><th>البرامج</th><th>الحالة</th><th>تاريخ الإضافة</th><?php if ($canManage): ?><th>إجراءات</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($stations as $s): ?>
        <tr>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td dir="ltr"><?= e($s['frequency'] ?: '—') ?></td>
            <td><?= (int)$s['programs_count'] ?></td>
            <td><?= $s['active'] ? '<span class="badge badge-success">نشطة</span>' : '<span class="badge badge-muted">موقوفة</span>' ?></td>
            <td><?= fmt_date($s['created_at']) ?></td>
            <?php if ($canManage): ?>
            <td class="actions-cell">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('stations', ['a' => 'edit', 'id' => $s['id']])) ?>">تعديل</a>
                <form method="post" action="<?= e(url('stations', ['a' => 'delete'])) ?>" class="inline-form"
                      onsubmit="return confirm('حذف الإذاعة سيحذف كل برامجها وحلقاتها وبيانات التزامها. متابعة؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger-ghost">حذف</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php
layout_footer();
