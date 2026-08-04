<?php
/** موديول المستخدمين (مدير النظام فقط) */
if (!defined('SBA')) exit;

$a = $_GET['a'] ?? 'list';
$me = current_user();

/* حفظ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'save') {
    $id       = (int)($_POST['id'] ?? 0);
    $name     = input('name');
    $username = input('username');
    $password = (string)($_POST['password'] ?? '');
    $role     = input('role');
    $active   = isset($_POST['active']) ? 1 : 0;
    $pt = isset($_POST['perm_technical']) ? 1 : 0;
    $pc = isset($_POST['perm_content']) ? 1 : 0;
    $pk = isset($_POST['perm_compliance']) ? 1 : 0;

    if (!in_array($role, ['admin', 'manager', 'employee', 'viewer'], true)) $role = 'employee';
    if ($role !== 'employee') { $pt = $pc = $pk = 0; }

    if ($name === '' || $username === '') {
        flash_set('danger', 'الاسم واسم المستخدم مطلوبان');
        redirect(url('users', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
    }
    if (!$id && mb_strlen($password) < 8) {
        flash_set('danger', 'كلمة المرور مطلوبة (8 أحرف على الأقل)');
        redirect(url('users', ['a' => 'add']));
    }
    if ($password !== '' && mb_strlen($password) < 8) {
        flash_set('danger', 'كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف');
        redirect(url('users', ['a' => 'edit', 'id' => $id]));
    }
    // منع المدير من إلغاء تفعيل نفسه أو تخفيض دوره
    if ($id === (int)$me['id'] && ($role !== 'admin' || !$active)) {
        flash_set('danger', 'لا يمكنك تعديل دورك أو إيقاف حسابك بنفسك');
        redirect(url('users'));
    }
    try {
        if ($id) {
            $sql = 'UPDATE ' . tbl('users') . ' SET name=?, username=?, role=?, active=?,
                    perm_technical=?, perm_content=?, perm_compliance=?';
            $params = [$name, $username, $role, $active, $pt, $pc, $pk];
            if ($password !== '') {
                $sql .= ', password=?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id=?';
            $params[] = $id;
            db()->prepare($sql)->execute($params);
            audit_log('update', 'user', $id, 'تعديل مستخدم: ' . $name);
            flash_set('success', 'تم تحديث المستخدم');
        } else {
            $st = db()->prepare('INSERT INTO ' . tbl('users') . '
                (name, username, password, role, active, perm_technical, perm_content, perm_compliance)
                VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, $active, $pt, $pc, $pk]);
            audit_log('create', 'user', (int)db()->lastInsertId(), 'إضافة مستخدم: ' . $name);
            flash_set('success', 'تمت إضافة المستخدم');
        }
    } catch (PDOException $e) {
        flash_set('danger', $e->getCode() === '23000' ? 'اسم المستخدم مستخدم مسبقاً' : 'حدث خطأ أثناء الحفظ');
    }
    redirect(url('users'));
}

/* الدخول بالنيابة عن عضو */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'impersonate') {
    $id = (int)($_POST['id'] ?? 0);
    if (impersonate_start($id)) {
        redirect('index.php');
    }
    flash_set('danger', 'تعذر الدخول بالنيابة — تأكد أن الحساب نشط وأنك لست في جلسة نيابة حالياً');
    redirect(url('users'));
}

/* حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)$me['id']) {
        flash_set('danger', 'لا يمكنك حذف حسابك بنفسك');
        redirect(url('users'));
    }
    $st = db()->prepare('SELECT name FROM ' . tbl('users') . ' WHERE id=?');
    $st->execute([$id]);
    if ($name = $st->fetchColumn()) {
        db()->prepare('DELETE FROM ' . tbl('users') . ' WHERE id=?')->execute([$id]);
        audit_log('delete', 'user', $id, 'حذف مستخدم: ' . $name);
        flash_set('success', 'تم حذف المستخدم');
    }
    redirect(url('users'));
}

/* نموذج */
if ($a === 'add' || $a === 'edit') {
    $u = ['id' => 0, 'name' => '', 'username' => '', 'role' => 'employee', 'active' => 1,
          'perm_technical' => 0, 'perm_content' => 0, 'perm_compliance' => 0];
    if ($a === 'edit') {
        $st = db()->prepare('SELECT * FROM ' . tbl('users') . ' WHERE id=?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $u = $st->fetch();
        if (!$u) { flash_set('danger', 'المستخدم غير موجود'); redirect(url('users')); }
    }
    layout_header($u['id'] ? 'تعديل مستخدم' : 'إضافة مستخدم');
    ?>
    <div class="card card-narrow">
        <form method="post" action="<?= e(url('users', ['a' => 'save'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <div class="form-group">
                <label>الاسم الكامل *</label>
                <input type="text" name="name" value="<?= e($u['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>اسم المستخدم *</label>
                <input type="text" name="username" value="<?= e($u['username']) ?>" required dir="ltr">
            </div>
            <div class="form-group">
                <label>كلمة المرور <?= $u['id'] ? '(اتركها فارغة للإبقاء على الحالية)' : '*' ?></label>
                <input type="password" name="password" <?= $u['id'] ? '' : 'required' ?> minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label>العضوية *</label>
                <select name="role" id="roleSelect" required>
                    <?php foreach (['admin', 'manager', 'employee', 'viewer'] as $r): ?>
                    <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">
                    مدير النظام: صلاحيات كاملة • مدير الجودة: كل البيانات وتوزيع المقيمين •
                    موظف: صلاحيات محددة أدناه • مشاهد: عرض فقط
                </small>
            </div>
            <div class="form-group" id="employeePerms" style="<?= $u['role'] === 'employee' ? '' : 'display:none' ?>">
                <label>صلاحيات الموظف</label>
                <label class="checkbox-label"><input type="checkbox" name="perm_technical" <?= $u['perm_technical'] ? 'checked' : '' ?>> تقييم فني</label>
                <label class="checkbox-label"><input type="checkbox" name="perm_content" <?= $u['perm_content'] ? 'checked' : '' ?>> تقييم محتوى</label>
                <label class="checkbox-label"><input type="checkbox" name="perm_compliance" <?= $u['perm_compliance'] ? 'checked' : '' ?>> تقييم التزام البث</label>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" name="active" <?= $u['active'] ? 'checked' : '' ?>> حساب نشط</label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <a href="<?= e(url('users')) ?>" class="btn btn-ghost">إلغاء</a>
            </div>
        </form>
    </div>
    <script>
    document.getElementById('roleSelect').addEventListener('change', function () {
        document.getElementById('employeePerms').style.display = this.value === 'employee' ? '' : 'none';
    });
    </script>
    <?php
    layout_footer();
    return;
}

/* القائمة */
$users = db()->query('SELECT * FROM ' . tbl('users') . ' ORDER BY role, name')->fetchAll();
layout_header('المستخدمون');
?>
<div class="card">
    <div class="card-header">
        <h2>قائمة المستخدمين (<?= count($users) ?>)</h2>
        <a href="<?= e(url('users', ['a' => 'add'])) ?>" class="btn btn-primary">+ إضافة مستخدم</a>
    </div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>الاسم</th><th>اسم المستخدم</th><th>العضوية</th><th>الصلاحيات</th><th>الحالة</th><th>إجراءات</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td dir="ltr"><?= e($u['username']) ?></td>
            <td><span class="badge badge-info"><?= e(role_label($u['role'])) ?></span></td>
            <td>
                <?php if ($u['role'] === 'employee'):
                    $perms = [];
                    if ($u['perm_technical'])  $perms[] = 'فني';
                    if ($u['perm_content'])    $perms[] = 'محتوى';
                    if ($u['perm_compliance']) $perms[] = 'التزام';
                    echo $perms ? e(implode(' • ', $perms)) : '<span class="muted">بدون</span>';
                else: ?>
                    <span class="muted">حسب العضوية</span>
                <?php endif; ?>
            </td>
            <td><?= $u['active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-muted">موقوف</span>' ?></td>
            <td class="actions-cell">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('users', ['a' => 'edit', 'id' => $u['id']])) ?>">تعديل</a>
                <?php if ((int)$u['id'] !== (int)$me['id'] && $u['active'] && !is_impersonating()): ?>
                <form method="post" action="<?= e(url('users', ['a' => 'impersonate'])) ?>" class="inline-form"
                      onsubmit="return confirm('ستدخل بالنيابة عن <?= e($u['name']) ?> وترى النظام كما يراه تماماً. متابعة؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost">&#128373;&#65039; دخول بالنيابة</button>
                </form>
                <?php endif; ?>
                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                <form method="post" action="<?= e(url('users', ['a' => 'delete'])) ?>" class="inline-form"
                      onsubmit="return confirm('حذف المستخدم سيحذف تقييماته أيضاً. متابعة؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger-ghost">حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php
layout_footer();
