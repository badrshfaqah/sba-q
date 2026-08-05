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
        flash_set('danger', safe_error($e));
    }
    redirect(url('settings'));
}

/* حفظ الإعدادات العامة */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'save') {
    $siteName = input('site_name');
    $wt = (int)input('weight_technical', 50);
    $wc = (int)input('weight_content', 50);
    $perPage = max(5, min(100, (int)input('per_page', 20)));
    if ($wt + $wc !== 100) {
        flash_set('danger', 'مجموع وزني التقييم الفني والمحتوى يجب أن يساوي 100%');
    } else {
        setting_save('site_name', $siteName ?: 'منصة متابعة جودة البث والمحتوى الإذاعي');
        setting_save('weight_technical', $wt);
        setting_save('weight_content', $wc);
        setting_save('per_page', $perPage);
        audit_log('update', 'settings', 0, "تحديث الإعدادات (فني $wt% / محتوى $wc%)");
        flash_set('success', 'تم حفظ الإعدادات');
    }
    redirect(url('settings'));
}

/* إضافة معيار */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'criterion_add') {
    $name = input('name');
    $type = input('type') === 'content' ? 'content' : 'technical';
    $formId = max(1, (int)($_POST['form_id'] ?? 1));
    if ($name !== '') {
        $st = db()->prepare('INSERT INTO ' . tbl('criteria') . ' (form_id, type, name, sort) VALUES (?,?,?,
            (SELECT COALESCE(MAX(c2.sort),0)+1 FROM ' . tbl('criteria') . ' c2 WHERE c2.type=? AND c2.form_id=?))');
        $st->execute([$formId, $type, $name, $type, $formId]);
        audit_log('create', 'criterion', (int)db()->lastInsertId(), 'إضافة معيار: ' . $name);
        flash_set('success', 'تمت إضافة المعيار');
    }
    redirect(url('settings'));
}

/* إضافة نموذج تقييم جديد */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'form_add') {
    $name = input('name');
    if ($name !== '') {
        try {
            $st = db()->prepare('INSERT INTO ' . tbl('eval_forms') . ' (name, description) VALUES (?,?)');
            $st->execute([$name, input('description') ?: null]);
            audit_log('create', 'eval_form', (int)db()->lastInsertId(), 'إضافة نموذج تقييم: ' . $name);
            flash_set('success', 'تمت إضافة النموذج — أضف الآن معاييره الفنية ومعايير المحتوى');
        } catch (PDOException $e) {
            flash_set('danger', 'يوجد نموذج بنفس الاسم');
        }
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

$criteria = db()->query('SELECT c.*, f.name AS form_name FROM ' . tbl('criteria') . ' c
    LEFT JOIN ' . tbl('eval_forms') . ' f ON f.id = c.form_id
    ORDER BY c.form_id, c.type, c.sort, c.id')->fetchAll();
$evalForms = db()->query('SELECT * FROM ' . tbl('eval_forms') . ' ORDER BY id')->fetchAll();
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
            <?php if (can('update.manage')): ?>
            <hr>
            <p class="muted">إعدادات الاتصال بمستودع GitHub انتقلت إلى
               <a href="<?= e(url('update')) ?>">صفحة تحديث النظام</a> لتكون مع أدوات التحديث في مكان واحد.</p>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>نماذج التقييم ومعاييرها</h2></div>
        <p class="muted">لكل طبيعة برنامج نموذج بمعاييره الخاصة (فني + محتوى). يُختار النموذج عند إنشاء
           البرنامج أو الحلقة، فيظهر للمقيّم المعايير المناسبة تلقائياً.</p>

        <form method="post" action="<?= e(url('settings', ['a' => 'form_add'])) ?>" class="inline-add">
            <?= csrf_field() ?>
            <input type="text" name="name" placeholder="اسم نموذج جديد (مثال: برنامج رياضي)" required>
            <button type="submit" class="btn btn-primary btn-sm">+ نموذج</button>
        </form>

        <form method="post" action="<?= e(url('settings', ['a' => 'criterion_add'])) ?>" class="inline-add">
            <?= csrf_field() ?>
            <select name="form_id">
                <?php foreach ($evalForms as $f): ?>
                <option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type">
                <option value="technical">فني</option>
                <option value="content">محتوى</option>
            </select>
            <input type="text" name="name" placeholder="اسم المعيار الجديد" required>
            <button type="submit" class="btn btn-primary btn-sm">+ معيار</button>
        </form>

        <?php
        $byForm = [];
        foreach ($criteria as $c) $byForm[$c['form_id']][] = $c;
        foreach ($evalForms as $f): $list = $byForm[$f['id']] ?? []; ?>
        <details class="form-details" <?= (int)$f['id'] === 1 ? 'open' : '' ?>>
            <summary><strong><?= e($f['name']) ?></strong>
                <span class="muted">(<?= count($list) ?> معياراً)</span></summary>
            <table class="table">
                <thead><tr><th>المعيار</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): ?>
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
        </details>
        <?php endforeach; ?>
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
