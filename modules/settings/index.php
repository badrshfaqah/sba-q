<?php
/** موديول الإعدادات: عام + أوزان التقييم + معايير التقييم */
if (!defined('SBA')) exit;

$a = $_GET['a'] ?? 'view';
require_once SBA_ROOT . '/install/demo.php';

/* تحميل / إزالة البيانات التجريبية */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($a === 'demo_load' || $a === 'demo_remove')) {
    try {
        @set_time_limit(120);
        $demoLog = $a === 'demo_load'
            ? sba_demo_load(db(), DB_PREFIX)
            : sba_demo_remove(db(), DB_PREFIX);
        audit_log($a === 'demo_load' ? 'create' : 'delete', 'demo', 0,
            ($a === 'demo_load' ? 'تحميل' : 'إزالة') . ' البيانات التجريبية');
        flash_set('success', implode(' • ', $demoLog));
    } catch (Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    redirect(url('settings'));
}

/* حفظ الإعدادات العامة */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'save') {
    $siteName = input('site_name');
    $wt = (int)input('weight_technical', 50);
    $wc = (int)input('weight_content', 50);
    $perPage = max(5, min(100, (int)input('per_page', 20)));
    $updateRepo   = input('update_repo');
    $updateBranch = input('update_branch');
    $updateToken  = trim((string)($_POST['update_token'] ?? ''));
    if ($wt + $wc !== 100) {
        flash_set('danger', 'مجموع وزني التقييم الفني والمحتوى يجب أن يساوي 100%');
    } elseif ($updateRepo !== '' && !preg_match('#^[\w.-]+/[\w.-]+$#', $updateRepo)) {
        flash_set('danger', 'صيغة المستودع غير صحيحة — المطلوب: username/repo');
    } else {
        setting_save('site_name', $siteName ?: 'منصة متابعة جودة البث والمحتوى الإذاعي');
        setting_save('weight_technical', $wt);
        setting_save('weight_content', $wc);
        setting_save('per_page', $perPage);
        if ($updateRepo !== '')   setting_save('update_repo', $updateRepo);
        if ($updateBranch !== '') setting_save('update_branch', $updateBranch);
        // الرمز: فارغ = إبقاء الحالي، وكلمة REMOVE = حذفه
        if ($updateToken === 'REMOVE') {
            setting_save('update_token', '');
        } elseif ($updateToken !== '') {
            setting_save('update_token', $updateToken);
        }
        audit_log('update', 'settings', 0, "تحديث الإعدادات (فني $wt% / محتوى $wc%)");
        flash_set('success', 'تم حفظ الإعدادات');
    }
    redirect(url('settings'));
}

/* إضافة معيار */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'criterion_add') {
    $name = input('name');
    $type = input('type') === 'content' ? 'content' : 'technical';
    if ($name !== '') {
        $st = db()->prepare('INSERT INTO ' . tbl('criteria') . ' (type, name, sort) VALUES (?,?,
            (SELECT COALESCE(MAX(c2.sort),0)+1 FROM ' . tbl('criteria') . ' c2 WHERE c2.type=?))');
        $st->execute([$type, $name, $type]);
        audit_log('create', 'criterion', (int)db()->lastInsertId(), 'إضافة معيار: ' . $name);
        flash_set('success', 'تمت إضافة المعيار');
    }
    redirect(url('settings'));
}

/* تفعيل/إيقاف معيار */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'criterion_toggle') {
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE ' . tbl('criteria') . ' SET active = 1 - active WHERE id=?')->execute([$id]);
    audit_log('update', 'criterion', $id, 'تغيير حالة معيار');
    flash_set('success', 'تم تحديث حالة المعيار');
    redirect(url('settings'));
}

$criteria = db()->query('SELECT * FROM ' . tbl('criteria') . ' ORDER BY type, sort, id')->fetchAll();
$wt = (int)setting('weight_technical', 50);
$wc = (int)setting('weight_content', 50);

layout_header('الإعدادات');
?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>الإعدادات العامة</h2></div>
        <form method="post" action="<?= e(url('settings', ['a' => 'save'])) ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>اسم المنصة</label>
                <input type="text" name="site_name" value="<?= e(setting('site_name', '')) ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>وزن التقييم الفني (%)</label>
                    <input type="number" name="weight_technical" value="<?= $wt ?>" min="0" max="100" required>
                </div>
                <div class="form-group">
                    <label>وزن تقييم المحتوى (%)</label>
                    <input type="number" name="weight_content" value="<?= $wc ?>" min="0" max="100" required>
                </div>
            </div>
            <p class="muted">النتيجة النهائية = (المتوسط الفني × الوزن) + (متوسط المحتوى × الوزن). المجموع يجب أن يساوي 100%.</p>
            <div class="form-group">
                <label>عدد الصفوف في الصفحة</label>
                <input type="number" name="per_page" value="<?= (int)setting('per_page', 20) ?>" min="5" max="100">
            </div>
            <hr>
            <h3 class="section-title">إعدادات التحديث من GitHub</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>المستودع (username/repo)</label>
                    <input type="text" name="update_repo" value="<?= e(setting('update_repo', 'badrshfaqah/sba-q')) ?>" dir="ltr">
                </div>
                <div class="form-group">
                    <label>الفرع</label>
                    <input type="text" name="update_branch" value="<?= e(setting('update_branch', 'main')) ?>" dir="ltr">
                </div>
            </div>
            <div class="form-group">
                <label>
                    رمز الوصول GitHub Token
                    <?= trim((string)setting('update_token', '')) !== ''
                        ? '<span class="badge badge-success">مسجّل ✓</span>'
                        : '<span class="badge badge-muted">غير مسجّل — مطلوب فقط للمستودع الخاص</span>' ?>
                </label>
                <input type="password" name="update_token" value="" dir="ltr" autocomplete="new-password"
                       placeholder="اتركه فارغاً للإبقاء على الحالي — أو اكتب REMOVE لحذفه">
                <small class="muted">
                    مطلوب فقط إذا كان المستودع خاصاً (Private). أنشئ رمزاً من نوع
                    Fine-grained بصلاحية قراءة Contents فقط على هذا المستودع تحديداً.
                </small>
            </div>
            <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>معايير التقييم</h2></div>
        <form method="post" action="<?= e(url('settings', ['a' => 'criterion_add'])) ?>" class="inline-add">
            <?= csrf_field() ?>
            <select name="type">
                <option value="technical">فني</option>
                <option value="content">محتوى</option>
            </select>
            <input type="text" name="name" placeholder="اسم المعيار الجديد" required>
            <button type="submit" class="btn btn-primary btn-sm">إضافة</button>
        </form>
        <table class="table">
            <thead><tr><th>المعيار</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($criteria as $c): ?>
            <tr>
                <td><?= e($c['name']) ?></td>
                <td><span class="badge badge-<?= $c['type'] === 'technical' ? 'info' : 'violet' ?>"><?= eval_type_label($c['type']) ?></span></td>
                <td><?= $c['active'] ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-muted">موقوف</span>' ?></td>
                <td>
                    <form method="post" action="<?= e(url('settings', ['a' => 'criterion_toggle'])) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost"><?= $c['active'] ? 'إيقاف' : 'تفعيل' ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">إيقاف المعيار يخفيه من التقييمات الجديدة فقط، ولا يؤثر على التقييمات السابقة.</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>&#129514; البيانات التجريبية</h2>
        <?= sba_demo_loaded(db(), DB_PREFIX)
            ? '<span class="badge badge-warning">محمّلة حالياً</span>'
            : '<span class="badge badge-muted">غير محمّلة</span>' ?>
    </div>
    <p class="muted">
        محتوى تجريبي كامل للتعرف على المنصة: 5 إذاعات بملامح أداء مختلفة، 12 برنامجاً، عشرات الحلقات
        بتقييمات منفذة ومعلقة، وموظفون مصنّفون (كلمة مرورهم: <code dir="ltr"><?= e(SBA_DEMO_PASSWORD) ?></code>)،
        وفحوصات التزام لأسبوعين. النظام يسجّل كل صف يزرعه، فالإزالة تحذف المحتوى التجريبي وحده
        <strong>دون المساس بأي بيانات حقيقية</strong>.
    </p>
    <div class="form-actions">
        <?php if (!sba_demo_loaded(db(), DB_PREFIX)): ?>
        <form method="post" action="<?= e(url('settings', ['a' => 'demo_load'])) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">تحميل البيانات التجريبية</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= e(url('settings', ['a' => 'demo_remove'])) ?>" class="inline-form"
              onsubmit="return confirm('سيتم حذف كل المحتوى التجريبي (الإذاعات والبرامج والحلقات والموظفين التجريبيين وبياناتهم). بياناتك الحقيقية لن تُمس. متابعة؟')">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger">إزالة البيانات التجريبية</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php
layout_footer();
