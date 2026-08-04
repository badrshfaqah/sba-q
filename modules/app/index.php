<?php
/**
 * موديول التطبيق والإشعارات
 * - تعليمات تثبيت المنصة كتطبيق على آيفون وأندرويد
 * - تفعيل/إيقاف إشعارات المهام
 * - للمدير: عدد المفعّلين + إرسال تنبيه عام للجميع
 */
if (!defined('SBA')) exit;
require_once SBA_ROOT . '/core/push.php';

$user = current_user();
$a = $_GET['a'] ?? 'view';

/* حفظ اشتراك إشعارات (JSON من المتصفح) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'subscribe') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    $ok = is_array($data) && push_save_subscription((int)$user['id'], $data);
    if ($ok) audit_log('create', 'push', (int)$user['id'], 'تفعيل إشعارات الجوال');
    echo json_encode(['ok' => $ok]);
    exit;
}

/* إلغاء اشتراك */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'unsubscribe') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    push_remove_subscription((int)$user['id'], (string)($data['endpoint'] ?? ''));
    audit_log('delete', 'push', (int)$user['id'], 'إيقاف إشعارات الجوال');
    echo json_encode(['ok' => true]);
    exit;
}

/* إشعار تجريبي لأجهزتي */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'test') {
    [$sent, $failed] = push_send_to_users([(int)$user['id']],
        'إشعار تجريبي 🔔', 'ممتاز! الإشعارات تعمل على هذا الجهاز', 'index.php?m=app');
    flash_set($sent ? 'success' : 'danger',
        $sent ? "تم إرسال إشعار تجريبي إلى $sent جهاز — يجب أن يصلك الآن"
              : 'لم يتم الإرسال — تأكد من تفعيل الإشعارات على هذا الجهاز أولاً');
    redirect(url('app'));
}

/* تنبيه عام للجميع (المدير ومدير الجودة) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'broadcast') {
    require_can('evaluators.assign');
    $title = mb_substr(input('title'), 0, 150);
    $body  = mb_substr(input('body'), 0, 500);
    if ($title === '' || $body === '') {
        flash_set('danger', 'العنوان ونص التنبيه مطلوبان');
        redirect(url('app'));
    }
    $st = db()->prepare('INSERT INTO ' . tbl('announcements') . ' (title, body, created_by) VALUES (?,?,?)');
    $st->execute([$title, $body, (int)$user['id']]);
    [$sent, $failed] = push_send_all('📢 ' . $title, $body, 'index.php?m=app');
    audit_log('create', 'announcement', (int)db()->lastInsertId(), "تنبيه عام: $title (أُرسل لـ$sent جهاز)");
    flash_set('success', "تم نشر التنبيه العام وإرساله إشعاراً إلى $sent جهاز" . ($failed ? " (تعذر $failed)" : ''));
    redirect(url('app'));
}

$vapidPublic = '';
$pushReady = true;
try {
    $vapidPublic = push_vapid_public();
} catch (Throwable $e) {
    $pushReady = false;
}
$stats = push_stats();
$isManager = can('evaluators.assign');
$announcements = db()->query('SELECT a.*, u.name AS author FROM ' . tbl('announcements') . ' a
    LEFT JOIN ' . tbl('users') . ' u ON u.id = a.created_by
    ORDER BY a.id DESC LIMIT 10')->fetchAll();

layout_header('التطبيق والإشعارات');
?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>&#128276; إشعارات المهام على جهازك</h2></div>
        <p class="muted">
            عند تفعيلها يصلك <strong>إشعار فوري</strong> على جهازك — حتى والتطبيق مغلق —
            عندما يعيّن لك مدير الجودة مهمة تقييم جديدة، أو يُنشر تنبيه عام.
        </p>
        <div id="pushStatus" class="alert alert-info">جارٍ فحص حالة الإشعارات على هذا الجهاز...</div>
        <div class="form-actions">
            <button id="pushEnable" class="btn btn-primary" style="display:none">&#128276; تفعيل الإشعارات على هذا الجهاز</button>
            <button id="pushDisable" class="btn btn-danger-ghost" style="display:none">إيقاف الإشعارات</button>
            <form method="post" action="<?= e(url('app', ['a' => 'test'])) ?>" class="inline-form" id="pushTestForm" style="display:none">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost">إرسال إشعار تجريبي</button>
            </form>
        </div>
        <div id="iosHint" class="alert alert-info" style="display:none">
            &#63743; على الآيفون: الإشعارات تعمل فقط بعد إضافة المنصة إلى الشاشة الرئيسية
            (الخطوات في البطاقة المجاورة) ثم فتحها <strong>من أيقونتها</strong> وتفعيل الإشعارات من هنا.
        </div>
        <?php if (!$pushReady): ?>
        <div class="alert alert-danger">تعذر تهيئة مفاتيح الإشعارات على السيرفر — تحقق من امتداد OpenSSL</div>
        <?php endif; ?>

        <?php if ($isManager): ?>
        <hr>
        <div class="detail-grid">
            <div><span class="muted">موظفون مفعّلون للإشعارات:</span>
                <strong><?= $stats['users'] ?></strong></div>
            <div><span class="muted">إجمالي الأجهزة المشتركة:</span>
                <strong><?= $stats['devices'] ?></strong></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>&#128241; إضافة المنصة كتطبيق</h2></div>
        <details class="form-details" open>
            <summary><strong>&#63743; على الآيفون (iPhone / iPad)</strong></summary>
            <ol class="update-steps">
                <li>افتح المنصة في متصفح <strong>Safari</strong></li>
                <li>اضغط زر المشاركة <strong>&#10548;</strong> أسفل الشاشة</li>
                <li>اختر <strong>«إضافة إلى الشاشة الرئيسية»</strong> (Add to Home Screen)</li>
                <li>اضغط <strong>إضافة</strong> — ستظهر أيقونة المنصة كتطبيق</li>
                <li>افتح التطبيق <strong>من الأيقونة</strong> وسجّل الدخول، ثم فعّل الإشعارات من هذه الصفحة</li>
            </ol>
            <p class="muted">ملاحظة: إشعارات الآيفون تتطلب iOS 16.4 أو أحدث، وتعمل فقط عند الفتح من أيقونة الشاشة الرئيسية.</p>
        </details>
        <details class="form-details">
            <summary><strong>&#129302; على الأندرويد</strong></summary>
            <ol class="update-steps">
                <li>افتح المنصة في متصفح <strong>Chrome</strong></li>
                <li>اضغط قائمة النقاط الثلاث <strong>&#8942;</strong> أعلى الشاشة</li>
                <li>اختر <strong>«إضافة إلى الشاشة الرئيسية»</strong> أو <strong>«تثبيت التطبيق»</strong></li>
                <li>أكّد التثبيت — ستظهر الأيقونة مع بقية التطبيقات</li>
                <li>افتح التطبيق وفعّل الإشعارات من هذه الصفحة</li>
            </ol>
        </details>
        <details class="form-details">
            <summary><strong>&#128421;&#65039; على الكمبيوتر</strong></summary>
            <ol class="update-steps">
                <li>في Chrome أو Edge: اضغط أيقونة التثبيت <strong>&#8681;</strong> في شريط العنوان</li>
                <li>أو من القائمة: <strong>«تثبيت منصة جودة البث»</strong></li>
            </ol>
        </details>
        <p class="muted">&#128274; يتطلب التثبيت والإشعارات أن يكون الموقع مرفوعاً على رابط <strong dir="ltr">https</strong>.</p>
    </div>
</div>

<?php if ($isManager): ?>
<div class="card">
    <div class="card-header"><h2>&#128226; إرسال تنبيه عام للجميع</h2></div>
    <p class="muted">يظهر في جرس التنبيهات لكل المستخدمين، ويصل <strong>إشعاراً فورياً</strong>
       لكل من فعّل الإشعارات (<?= $stats['users'] ?> موظف على <?= $stats['devices'] ?> جهاز).</p>
    <form method="post" action="<?= e(url('app', ['a' => 'broadcast'])) ?>">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>عنوان التنبيه *</label>
            <input type="text" name="title" maxlength="150" required placeholder="مثال: اجتماع فريق الجودة غداً">
        </div>
        <div class="form-group">
            <label>نص التنبيه *</label>
            <textarea name="body" rows="2" maxlength="500" required placeholder="التفاصيل المختصرة..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">نشر وإرسال الإشعار للجميع</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>&#128226; آخر التنبيهات العامة</h2></div>
    <?php if (!$announcements): ?>
        <div class="empty-state small"><p>لا توجد تنبيهات عامة بعد</p></div>
    <?php else: ?>
        <?php foreach ($announcements as $an): ?>
        <div class="announcement">
            <div class="announcement-head">
                <strong><?= e($an['title']) ?></strong>
                <span class="muted"><?= fmt_datetime($an['created_at']) ?> — <?= e($an['author'] ?: 'النظام') ?></span>
            </div>
            <p><?= e($an['body']) ?></p>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
window.SBA_VAPID = <?= json_encode($vapidPublic) ?>;
window.SBA_PUSH_URLS = {
    subscribe: <?= json_encode(url('app', ['a' => 'subscribe'])) ?>,
    unsubscribe: <?= json_encode(url('app', ['a' => 'unsubscribe'])) ?>
};
</script>
<script src="assets/js/push.js?v=<?= SBA_VERSION ?>"></script>
<?php
layout_footer();
