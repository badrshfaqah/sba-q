<?php
/** موديول الحلقات: إدارة + تعيين المقيمين + عرض النتائج */
if (!defined('SBA')) exit;

$canManage = can('episodes.manage');
$isEvaluator = can('eval.technical') || can('eval.content');
if (!$canManage && !$isEvaluator && !can('data.viewall')) { require_can('episodes.manage'); }

$a = $_GET['a'] ?? 'list';
$user = current_user();

/* حفظ حلقة + تعيين المقيمين */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $a === 'save') {
    $id        = (int)($_POST['id'] ?? 0);
    $programId = (int)($_POST['program_id'] ?? 0);
    $title     = input('title');
    $airDate   = input('air_date');
    $airTime   = input('air_time');
    $formId    = (int)($_POST['form_id'] ?? 0) ?: null;   // فارغ = وراثة نموذج البرنامج
    $refLink   = input('ref_link');
    $notes     = input('notes');
    $techEvals    = array_map('intval', $_POST['tech_evaluators'] ?? []);
    $contentEvals = array_map('intval', $_POST['content_evaluators'] ?? []);

    if ($title === '' || !$programId || $airDate === '' || $airTime === '') {
        flash_set('danger', 'العنوان والبرنامج وتاريخ ووقت البث حقول مطلوبة');
        redirect(url('episodes', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
    }

    /* رفع المقطع الصوتي (اختياري) */
    $audioPath = null;
    $removeAudio = isset($_POST['remove_audio']);
    if (!empty($_FILES['audio']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'webm'];
        if (!in_array($ext, $allowed, true)) {
            flash_set('danger', 'صيغة الملف الصوتي غير مدعومة. المسموح: ' . implode('، ', $allowed));
            redirect(url('episodes', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
        }
        if ($_FILES['audio']['size'] > 80 * 1024 * 1024) {
            flash_set('danger', 'حجم الملف الصوتي يتجاوز الحد (80MB)');
            redirect(url('episodes', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
        }
        $dir = SBA_ROOT . '/uploads/audio';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = 'ep_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dir . '/' . $fname)) {
            flash_set('danger', 'تعذر حفظ الملف الصوتي — تحقق من صلاحيات مجلد uploads');
            redirect(url('episodes', $id ? ['a' => 'edit', 'id' => $id] : ['a' => 'add']));
        }
        $audioPath = 'uploads/audio/' . $fname;
    }

    $pdo = db();
    if ($id) {
        // إدارة الملف القديم عند الاستبدال أو الحذف
        $old = $pdo->prepare('SELECT audio_path FROM ' . tbl('episodes') . ' WHERE id=?');
        $old->execute([$id]);
        $oldAudio = (string)$old->fetchColumn();
        if (($audioPath || $removeAudio) && $oldAudio && is_file(SBA_ROOT . '/' . $oldAudio)) {
            @unlink(SBA_ROOT . '/' . $oldAudio);
        }
        $newAudio = $audioPath ?: ($removeAudio ? null : ($oldAudio ?: null));
        $st = $pdo->prepare('UPDATE ' . tbl('episodes') . '
            SET program_id=?, title=?, air_date=?, air_time=?, form_id=?, audio_path=?, ref_link=?, notes=? WHERE id=?');
        $st->execute([$programId, $title, $airDate, $airTime, $formId, $newAudio, $refLink ?: null, $notes ?: null, $id]);
        audit_log('update', 'episode', $id, 'تعديل حلقة: ' . $title);
        flash_set('success', 'تم تحديث الحلقة');
    } else {
        $st = $pdo->prepare('INSERT INTO ' . tbl('episodes') . '
            (program_id, title, air_date, air_time, form_id, audio_path, ref_link, notes) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$programId, $title, $airDate, $airTime, $formId, $audioPath, $refLink ?: null, $notes ?: null]);
        $id = (int)$pdo->lastInsertId();
        audit_log('create', 'episode', $id, 'إضافة حلقة: ' . $title);
        flash_set('success', 'تمت إضافة الحلقة وتعيين المقيمين');
    }

    /* مزامنة تعيين المقيمين (مع الإبقاء على من لديه تقييم منفذ) */
    if (can('evaluators.assign')) {
        $newlyAssigned = [];
        $sync = function (string $type, array $userIds) use ($pdo, $id, &$newlyAssigned) {
            $existing = $pdo->prepare('SELECT user_id FROM ' . tbl('episode_evaluators') . ' WHERE episode_id=? AND type=?');
            $existing->execute([$id, $type]);
            $current = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

            $toAdd    = array_diff($userIds, $current);
            $toRemove = array_diff($current, $userIds);

            $ins = $pdo->prepare('INSERT IGNORE INTO ' . tbl('episode_evaluators') . ' (episode_id, user_id, type) VALUES (?,?,?)');
            foreach ($toAdd as $uid) {
                $ins->execute([$id, $uid, $type]);
                if ($ins->rowCount() > 0) $newlyAssigned[] = (int)$uid;
            }

            if ($toRemove) {
                // لا نحذف مقيّماً قدّم تقييمه فعلاً
                $del = $pdo->prepare('DELETE FROM ' . tbl('episode_evaluators') . '
                    WHERE episode_id=? AND type=? AND user_id=?
                    AND NOT EXISTS (SELECT 1 FROM ' . tbl('evaluations') . ' ev
                                    WHERE ev.episode_id=? AND ev.user_id=? AND ev.type=?)');
                foreach ($toRemove as $uid) $del->execute([$id, $type, $uid, $id, $uid, $type]);
            }
        };
        $sync('technical', $techEvals);
        $sync('content', $contentEvals);

        /* إشعار فوري للمقيمين المعينين حديثاً */
        if ($newlyAssigned) {
            require_once SBA_ROOT . '/core/push.php';
            try {
                push_send_to_users(array_unique($newlyAssigned),
                    'مهمة تقييم جديدة 📋',
                    'أُسندت إليك مهمة تقييم للحلقة: ' . $title,
                    'index.php');
            } catch (Throwable $e) {
                // فشل الإشعار لا يوقف الحفظ
            }
        }
    }
    redirect(url('episodes', ['a' => 'view', 'id' => $id]));
}

/* حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && $a === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT title, audio_path FROM ' . tbl('episodes') . ' WHERE id=?');
    $st->execute([$id]);
    if ($row = $st->fetch()) {
        if ($row['audio_path'] && is_file(SBA_ROOT . '/' . $row['audio_path'])) {
            @unlink(SBA_ROOT . '/' . $row['audio_path']);
        }
        db()->prepare('DELETE FROM ' . tbl('episodes') . ' WHERE id=?')->execute([$id]);
        audit_log('delete', 'episode', $id, 'حذف حلقة: ' . $row['title']);
        flash_set('success', 'تم حذف الحلقة');
    }
    redirect(url('episodes'));
}

/* نموذج إضافة/تعديل */
if ($canManage && ($a === 'add' || $a === 'edit')) {
    $episode = ['id' => 0, 'program_id' => (int)($_GET['program'] ?? 0), 'title' => '',
                'air_date' => date('Y-m-d'), 'air_time' => date('H:00'),
                'form_id' => null, 'audio_path' => null, 'ref_link' => '', 'notes' => ''];
    $assigned = ['technical' => [], 'content' => []];
    if ($a === 'edit') {
        $st = db()->prepare('SELECT * FROM ' . tbl('episodes') . ' WHERE id=?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $episode = $st->fetch();
        if (!$episode) { flash_set('danger', 'الحلقة غير موجودة'); redirect(url('episodes')); }
        $st = db()->prepare('SELECT user_id, type FROM ' . tbl('episode_evaluators') . ' WHERE episode_id=?');
        $st->execute([$episode['id']]);
        foreach ($st->fetchAll() as $r) $assigned[$r['type']][] = (int)$r['user_id'];
    }
    $programs = db()->query('SELECT p.id, p.name, s.name AS station FROM ' . tbl('programs') . ' p
        JOIN ' . tbl('stations') . ' s ON s.id=p.station_id WHERE p.active=1 ORDER BY s.name, p.name')->fetchAll();
    $techUsers = db()->query('SELECT id, name FROM ' . tbl('users') . '
        WHERE active=1 AND (role IN ("admin","manager") OR perm_technical=1) ORDER BY name')->fetchAll();
    $contentUsers = db()->query('SELECT id, name FROM ' . tbl('users') . '
        WHERE active=1 AND (role IN ("admin","manager") OR perm_content=1) ORDER BY name')->fetchAll();
    $forms = db()->query('SELECT id, name FROM ' . tbl('eval_forms') . ' WHERE active=1 ORDER BY id')->fetchAll();

    layout_header($episode['id'] ? 'تعديل حلقة' : 'إضافة حلقة');
    ?>
    <div class="card card-narrow">
        <form method="post" action="<?= e(url('episodes', ['a' => 'save'])) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$episode['id'] ?>">
            <div class="form-group">
                <label>البرنامج *</label>
                <select name="program_id" required>
                    <option value="">— اختر البرنامج —</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$episode['program_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= e($p['station'] . ' — ' . $p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>عنوان الحلقة *</label>
                <input type="text" name="title" value="<?= e($episode['title']) ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>تاريخ البث *</label>
                    <input type="date" name="air_date" value="<?= e($episode['air_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label>وقت البث (24 ساعة) *</label>
                    <input type="time" name="air_time" value="<?= e(substr($episode['air_time'], 0, 5)) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>نموذج التقييم</label>
                <select name="form_id">
                    <option value="0">— وراثة نموذج البرنامج (أو القياسي) —</option>
                    <?php foreach ($forms as $f): ?>
                    <option value="<?= (int)$f['id'] ?>" <?= (int)($episode['form_id'] ?? 0) === (int)$f['id'] ? 'selected' : '' ?>>
                        <?= e($f['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">اختر النموذج المناسب لطبيعة الحلقة (حواري مع ضيف، بث مباشر، مسجل...)</small>
            </div>
            <div class="form-group">
                <label>المقطع الصوتي للحلقة (اختياري — mp3 / m4a / wav)</label>
                <?php if (!empty($episode['audio_path']) && is_file(SBA_ROOT . '/' . $episode['audio_path'])): ?>
                <div class="media-box">
                    <audio controls preload="none" src="<?= e($episode['audio_path']) ?>"></audio>
                    <label class="checkbox-label"><input type="checkbox" name="remove_audio"> حذف المقطع الحالي</label>
                </div>
                <?php endif; ?>
                <input type="file" name="audio" accept=".mp3,.m4a,.aac,.wav,.ogg,.oga,.webm,audio/*">
                <small class="muted">يستمع له المقيّم أثناء التقييم مباشرة من المنصة (حتى 80MB)</small>
            </div>
            <div class="form-group">
                <label>رابط أو مرجع الحلقة (اختياري)</label>
                <input type="text" name="ref_link" value="<?= e($episode['ref_link'] ?? '') ?>"
                       placeholder="رابط خارجي https://... أو عنوان الحلقة في النظام الداخلي">
            </div>
            <div class="form-group">
                <label>ملاحظات</label>
                <textarea name="notes" rows="2"><?= e($episode['notes']) ?></textarea>
            </div>

            <?php if (can('evaluators.assign')): ?>
            <hr>
            <h3 class="section-title">تعيين المقيمين</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>المقيمون الفنيون (اختيار متعدد)</label>
                    <div class="checklist">
                        <?php foreach ($techUsers as $u): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="tech_evaluators[]" value="<?= (int)$u['id'] ?>"
                                <?= in_array((int)$u['id'], $assigned['technical'], true) ? 'checked' : '' ?>>
                            <?= e($u['name']) ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$techUsers): ?><p class="muted">لا يوجد مستخدمون بصلاحية التقييم الفني</p><?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>مقيمو المحتوى (اختيار متعدد)</label>
                    <div class="checklist">
                        <?php foreach ($contentUsers as $u): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="content_evaluators[]" value="<?= (int)$u['id'] ?>"
                                <?= in_array((int)$u['id'], $assigned['content'], true) ? 'checked' : '' ?>>
                            <?= e($u['name']) ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if (!$contentUsers): ?><p class="muted">لا يوجد مستخدمون بصلاحية تقييم المحتوى</p><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <a href="<?= e(url('episodes')) ?>" class="btn btn-ghost">إلغاء</a>
            </div>
        </form>
    </div>
    <?php
    layout_footer();
    return;
}

/* عرض حلقة مع نتائجها */
if ($a === 'view') {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT e.*, p.name AS program_name, s.name AS station_name
        FROM ' . tbl('episodes') . ' e
        JOIN ' . tbl('programs') . ' p ON p.id=e.program_id
        JOIN ' . tbl('stations') . ' s ON s.id=p.station_id WHERE e.id=?');
    $st->execute([$id]);
    $episode = $st->fetch();
    if (!$episode) { flash_set('danger', 'الحلقة غير موجودة'); redirect(url('episodes')); }

    // المقيّم يرى الحلقة فقط إن كان معيّناً عليها
    if (!$canManage && !can('data.viewall')) {
        $st = db()->prepare('SELECT COUNT(*) FROM ' . tbl('episode_evaluators') . ' WHERE episode_id=? AND user_id=?');
        $st->execute([$id, (int)$user['id']]);
        if (!(int)$st->fetchColumn()) { require_can('episodes.manage'); }
    }

    $st = db()->prepare('SELECT ev.*, u.name AS user_name FROM ' . tbl('evaluations') . ' ev
        JOIN ' . tbl('users') . ' u ON u.id=ev.user_id WHERE ev.episode_id=? ORDER BY ev.type, ev.created_at');
    $st->execute([$id]);
    $evals = $st->fetchAll();

    // الموظف يرى تقييماته فقط
    if (!can('data.viewall')) {
        $evals = array_values(array_filter($evals, fn($ev) => (int)$ev['user_id'] === (int)$user['id']));
    }

    [$wt, $wc] = quality_weights();
    $scores = episode_scores(null, null, 0, 0);
    $mine = null;
    foreach ($scores as $srow) { if ((int)$srow['id'] === $id) { $mine = $srow; break; } }

    $st = db()->prepare('SELECT a.type, u.name FROM ' . tbl('episode_evaluators') . ' a
        JOIN ' . tbl('users') . ' u ON u.id=a.user_id WHERE a.episode_id=? ORDER BY a.type, u.name');
    $st->execute([$id]);
    $assignedList = $st->fetchAll();

    layout_header('تفاصيل الحلقة');
    ?>
    <div class="card">
        <div class="card-header">
            <h2><?= e($episode['title']) ?></h2>
            <div class="header-actions">
                <?php if ($canManage): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('episodes', ['a' => 'edit', 'id' => $id])) ?>">تعديل الحلقة</a>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('episodes')) ?>">← عودة للقائمة</a>
            </div>
        </div>
        <div class="detail-grid">
            <div><span class="muted">البرنامج:</span> <?= e($episode['program_name']) ?></div>
            <div><span class="muted">الإذاعة:</span> <?= e($episode['station_name']) ?></div>
            <div><span class="muted">تاريخ البث:</span> <?= fmt_date($episode['air_date']) ?></div>
            <div><span class="muted">وقت البث:</span> <?= fmt_time($episode['air_time']) ?></div>
        </div>
        <?php if (!empty($episode['audio_path']) && is_file(SBA_ROOT . '/' . $episode['audio_path'])): ?>
        <div class="media-box">
            <label>&#127911; المقطع الصوتي:</label>
            <audio controls preload="none" src="<?= e($episode['audio_path']) ?>"></audio>
        </div>
        <?php endif; ?>
        <?php if (!empty($episode['ref_link'])): ?>
        <div class="media-box">
            <label>&#128279; المرجع:</label>
            <?php if (preg_match('#^https?://#i', $episode['ref_link'])): ?>
                <a href="<?= e($episode['ref_link']) ?>" target="_blank" rel="noopener" dir="ltr"><?= e($episode['ref_link']) ?></a>
            <?php else: ?>
                <span><?= e($episode['ref_link']) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($episode['notes']): ?><p class="muted"><?= e($episode['notes']) ?></p><?php endif; ?>
    </div>

    <?php if (can('data.viewall') && $mine): ?>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">المتوسط الفني (وزن <?= round($wt * 100) ?>%)</div>
            <div class="kpi-value"><?= fmt_num($mine['tavg']) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">متوسط المحتوى (وزن <?= round($wc * 100) ?>%)</div>
            <div class="kpi-value"><?= fmt_num($mine['cavg']) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">النتيجة النهائية</div>
            <div class="kpi-value <?= $mine['final'] !== null && $mine['final'] < 6 ? 'kpi-bad' : 'kpi-good' ?>"><?= fmt_num($mine['final']) ?> / 10</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2>المقيمون المعينون</h2></div>
            <?php if (!$assignedList): ?><div class="empty-state small"><p>لم يتم تعيين مقيمين</p></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>المقيم</th><th>النوع</th></tr></thead>
                <tbody>
                <?php foreach ($assignedList as $al): ?>
                <tr>
                    <td><?= e($al['name']) ?></td>
                    <td><span class="badge badge-<?= $al['type'] === 'technical' ? 'info' : 'violet' ?>"><?= eval_type_label($al['type']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><h2>التقييمات المقدمة</h2></div>
            <?php if (!$evals): ?><div class="empty-state small"><p>لا توجد تقييمات بعد</p></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>المقيم</th><th>النوع</th><th>الدرجة</th><th>التاريخ</th></tr></thead>
                <tbody>
                <?php foreach ($evals as $ev): ?>
                <tr>
                    <td><?= e($ev['user_name']) ?></td>
                    <td><span class="badge badge-<?= $ev['type'] === 'technical' ? 'info' : 'violet' ?>"><?= eval_type_label($ev['type']) ?></span></td>
                    <td><strong><?= fmt_num($ev['score']) ?></strong> / 10</td>
                    <td><?= fmt_datetime($ev['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
    layout_footer();
    return;
}

/* قائمة الحلقات */
$filterProgram = (int)($_GET['program'] ?? 0);
$perPage = (int)setting('per_page', 20);
$page = (int)($_GET['p'] ?? 1);

if ($canManage || can('data.viewall')) {
    $where = ' WHERE 1=1'; $params = [];
    if ($filterProgram) { $where .= ' AND e.program_id=?'; $params[] = $filterProgram; }
    $countSt = db()->prepare('SELECT COUNT(*) FROM ' . tbl('episodes') . ' e' . $where);
    $countSt->execute($params);
    [$offset, $pages, $page] = paginate((int)$countSt->fetchColumn(), $perPage, $page);

    $st = db()->prepare('SELECT e.*, p.name AS program_name, s.name AS station_name,
            (SELECT COUNT(*) FROM ' . tbl('episode_evaluators') . ' a WHERE a.episode_id=e.id) AS assigned,
            (SELECT COUNT(*) FROM ' . tbl('evaluations') . ' ev WHERE ev.episode_id=e.id) AS evaluated
        FROM ' . tbl('episodes') . ' e
        JOIN ' . tbl('programs') . ' p ON p.id=e.program_id
        JOIN ' . tbl('stations') . ' s ON s.id=p.station_id' . $where . '
        ORDER BY e.air_date DESC, e.air_time DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $st->execute($params);
    $episodes = $st->fetchAll();
} else {
    // المقيم يرى فقط الحلقات المعينة له
    $st = db()->prepare('SELECT DISTINCT e.*, p.name AS program_name, s.name AS station_name, 0 AS assigned, 0 AS evaluated
        FROM ' . tbl('episodes') . ' e
        JOIN ' . tbl('programs') . ' p ON p.id=e.program_id
        JOIN ' . tbl('stations') . ' s ON s.id=p.station_id
        JOIN ' . tbl('episode_evaluators') . ' a ON a.episode_id=e.id AND a.user_id=?
        ORDER BY e.air_date DESC, e.air_time DESC');
    $st->execute([(int)$user['id']]);
    $episodes = $st->fetchAll();
    $pages = 1;
}

$programsList = db()->query('SELECT id, name FROM ' . tbl('programs') . ' ORDER BY name')->fetchAll();

layout_header('الحلقات');
?>
<div class="card">
    <div class="card-header">
        <h2>الحلقات</h2>
        <div class="header-actions">
            <?php if ($canManage || can('data.viewall')): ?>
            <form method="get" action="index.php" class="inline-form">
                <input type="hidden" name="m" value="episodes">
                <select name="program" onchange="this.form.submit()">
                    <option value="0">كل البرامج</option>
                    <?php foreach ($programsList as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $filterProgram === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <a href="<?= e(url('episodes', ['a' => 'add'])) ?>" class="btn btn-primary">+ إضافة حلقة</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$episodes): ?>
        <div class="empty-state"><p>لا توجد حلقات</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>الحلقة</th><th>البرنامج</th><th>الإذاعة</th><th>البث</th>
            <?php if ($canManage || can('data.viewall')): ?><th>التقييمات</th><?php endif; ?><th></th></tr></thead>
        <tbody>
        <?php foreach ($episodes as $ep): ?>
        <tr>
            <td><strong><?= e($ep['title']) ?></strong></td>
            <td><?= e($ep['program_name']) ?></td>
            <td><?= e($ep['station_name']) ?></td>
            <td><?= fmt_date($ep['air_date']) ?> <span class="muted"><?= fmt_time($ep['air_time']) ?></span></td>
            <?php if ($canManage || can('data.viewall')): ?>
            <td><?= (int)$ep['evaluated'] ?> / <?= (int)$ep['assigned'] ?></td>
            <?php endif; ?>
            <td class="actions-cell">
                <a class="btn btn-sm btn-ghost" href="<?= e(url('episodes', ['a' => 'view', 'id' => $ep['id']])) ?>">عرض</a>
                <?php if ($canManage): ?>
                <form method="post" action="<?= e(url('episodes', ['a' => 'delete'])) ?>" class="inline-form"
                      onsubmit="return confirm('حذف الحلقة سيحذف تقييماتها. متابعة؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger-ghost">حذف</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= paginate_links($pages ?? 1, $page, 'episodes', $filterProgram ? ['program' => $filterProgram] : []) ?>
    <?php endif; ?>
</div>
<?php
layout_footer();
