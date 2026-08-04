<?php
/** موديول البرامج */
if (!defined('SBA')) exit;

if (!can('programs.manage') && !can('data.viewall')) { require_can('programs.manage'); }
$canManage = can('programs.manage');
$a = $_GET['a'] ?? 'list';

/* حفظ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $a === 'save') {
    $id        = (int)($_POST['id'] ?? 0);
    $name      = input('name');
    $stationId = (int)($_POST['station_id'] ?? 0);
    $presenter = input('presenter');
    $active    = isset($_POST['active']) ? 1 : 0;
    if ($name === '' || !$stationId) {
        flash_set('danger', 'اسم البرنامج والإذاعة مطلوبان');
        redirect(url('programs', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
    }
    if ($id) {
        $st = db()->prepare('UPDATE ' . tbl('programs') . ' SET name=?, station_id=?, presenter=?, active=? WHERE id=?');
        $st->execute([$name, $stationId, $presenter ?: null, $active, $id]);
        audit_log('update', 'program', $id, 'تعديل برنامج: ' . $name);
        flash_set('success', 'تم تحديث البرنامج');
    } else {
        $st = db()->prepare('INSERT INTO ' . tbl('programs') . ' (name, station_id, presenter, active) VALUES (?,?,?,?)');
        $st->execute([$name, $stationId, $presenter ?: null, $active]);
        audit_log('create', 'program', (int)db()->lastInsertId(), 'إضافة برنامج: ' . $name);
        flash_set('success', 'تمت إضافة البرنامج');
    }
    redirect(url('programs'));
}

/* حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $a === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT name FROM ' . tbl('programs') . ' WHERE id=?');
    $st->execute([$id]);
    if ($name = $st->fetchColumn()) {
        db()->prepare('DELETE FROM ' . tbl('programs') . ' WHERE id=?')->execute([$id]);
        audit_log('delete', 'program', $id, 'حذف برنامج: ' . $name);
        flash_set('success', 'تم حذف البرنامج وحلقاته');
    }
    redirect(url('programs'));
}

$stations = db()->query('SELECT id, name FROM ' . tbl('stations') . ' WHERE active=1 ORDER BY name')->fetchAll();

/* نموذج */
if ($canManage && ($a === 'add' || $a === 'edit')) {
    $program = ['id' => 0, 'name' => '', 'station_id' => 0, 'presenter' => '', 'active' => 1];
    if ($a === 'edit') {
        $st = db()->prepare('SELECT * FROM ' . tbl('programs') . ' WHERE id=?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $program = $st->fetch();
        if (!$program) { flash_set('danger', 'البرنامج غير موجود'); redirect(url('programs')); }
    }
    layout_header($program['id'] ? 'تعديل برنامج' : 'إضافة برنامج');
    ?>
    <div class="card card-narrow">
        <form method="post" action="<?= e(url('programs', ['a' => 'save'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$program['id'] ?>">
            <div class="form-group">
                <label>اسم البرنامج *</label>
                <input type="text" name="name" value="<?= e($program['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>الإذاعة *</label>
                <select name="station_id" required>
                    <option value="">— اختر الإذاعة —</option>
                    <?php foreach ($stations as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$program['station_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>مقدم البرنامج (اختياري)</label>
                <input type="text" name="presenter" value="<?= e($program['presenter']) ?>">
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="active" <?= $program['active'] ? 'checked' : '' ?>> برنامج نشط
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <a href="<?= e(url('programs')) ?>" class="btn btn-ghost">إلغاء</a>
            </div>
        </form>
    </div>
    <?php
    layout_footer();
    return;
}

/* القائمة مع فلتر الإذاعة */
$filterStation = (int)($_GET['station'] ?? 0);
$sql = 'SELECT p.*, s.name AS station_name,
        (SELECT COUNT(*) FROM ' . tbl('episodes') . ' e WHERE e.program_id = p.id) AS episodes_count
        FROM ' . tbl('programs') . ' p JOIN ' . tbl('stations') . ' s ON s.id = p.station_id';
$params = [];
if ($filterStation) { $sql .= ' WHERE p.station_id = ?'; $params[] = $filterStation; }
$sql .= ' ORDER BY s.name, p.name';
$st = db()->prepare($sql);
$st->execute($params);
$programs = $st->fetchAll();

layout_header('البرامج');
?>
<div class="card">
    <div class="card-header">
        <h2>قائمة البرامج (<?= count($programs) ?>)</h2>
        <div class="header-actions">
            <form method="get" action="index.php" class="inline-form">
                <input type="hidden" name="m" value="programs">
                <select name="station" onchange="this.form.submit()">
                    <option value="0">كل الإذاعات</option>
                    <?php foreach ($stations as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $filterStation === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($canManage): ?>
            <a href="<?= e(url('programs', ['a' => 'add'])) ?>" class="btn btn-primary">+ إضافة برنامج</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$programs): ?>
        <div class="empty-state"><p>لا توجد برامج بعد</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>البرنامج</th><th>الإذاعة</th><th>المقدم</th><th>الحلقات</th><th>الحالة</th><?php if ($canManage): ?><th>إجراءات</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($programs as $p): ?>
        <tr>
            <td><strong><?= e($p['name']) ?></strong></td>
            <td><?= e($p['station_name']) ?></td>
            <td><?= e($p['presenter'] ?: '—') ?></td>
            <td><a href="<?= e(url('episodes', ['program' => $p['id']])) ?>"><?= (int)$p['episodes_count'] ?> حلقة</a></td>
            <td><?= $p['active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-muted">موقوف</span>' ?></td>
            <?php if ($canManage): ?>
            <td class="actions-cell">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('programs', ['a' => 'edit', 'id' => $p['id']])) ?>">تعديل</a>
                <form method="post" action="<?= e(url('programs', ['a' => 'delete'])) ?>" class="inline-form"
                      onsubmit="return confirm('حذف البرنامج سيحذف كل حلقاته وتقييماتها. متابعة؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
