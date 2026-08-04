<?php
/** موديول تقييم الجودة: نموذج التقييم بالمعايير + منع التكرار */
if (!defined('SBA')) exit;

$user = current_user();
$a = $_GET['a'] ?? 'form';

$episodeId = (int)($_GET['episode'] ?? $_POST['episode_id'] ?? 0);
$type      = ($_GET['type'] ?? $_POST['type'] ?? '') === 'content' ? 'content' : 'technical';

/* فحص الصلاحية حسب النوع */
require_can($type === 'technical' ? 'eval.technical' : 'eval.content');

/* جلب الحلقة */
$st = db()->prepare('SELECT e.*, p.name AS program_name, s.name AS station_name
    FROM ' . tbl('episodes') . ' e
    JOIN ' . tbl('programs') . ' p ON p.id=e.program_id
    JOIN ' . tbl('stations') . ' s ON s.id=p.station_id WHERE e.id=?');
$st->execute([$episodeId]);
$episode = $st->fetch();
if (!$episode) {
    flash_set('danger', 'الحلقة غير موجودة');
    redirect(url('dashboard'));
}

/* التأكد من أن المستخدم معيّن على هذه الحلقة بهذا النوع (المدراء مستثنون) */
$isAssigned = false;
$st = db()->prepare('SELECT COUNT(*) FROM ' . tbl('episode_evaluators') . '
    WHERE episode_id=? AND user_id=? AND type=?');
$st->execute([$episodeId, (int)$user['id'], $type]);
$isAssigned = (int)$st->fetchColumn() > 0;
if (!$isAssigned && !can('data.viewall')) {
    flash_set('danger', 'لست معيّناً لتقييم هذه الحلقة (' . eval_type_label($type) . ')');
    redirect(url('dashboard'));
}

/* منع التكرار: هل قدّم تقييمه مسبقاً؟ */
$st = db()->prepare('SELECT * FROM ' . tbl('evaluations') . '
    WHERE episode_id=? AND user_id=? AND type=?');
$st->execute([$episodeId, (int)$user['id'], $type]);
$existing = $st->fetch();

/* المعايير */
$st = db()->prepare('SELECT * FROM ' . tbl('criteria') . ' WHERE type=? AND active=1 ORDER BY sort, id');
$st->execute([$type]);
$criteria = $st->fetchAll();

/* حفظ التقييم */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'save') {
    if ($existing) {
        flash_set('danger', 'قمت بتقييم هذه الحلقة مسبقاً — لا يمكن التكرار');
        redirect(url('episodes', ['a' => 'view', 'id' => $episodeId]));
    }
    $scores = $_POST['scores'] ?? [];
    $notes  = input('notes');
    $items = [];
    foreach ($criteria as $c) {
        $v = (int)($scores[$c['id']] ?? 0);
        if ($v < 1 || $v > 10) {
            flash_set('danger', 'يرجى إدخال درجة من 1 إلى 10 لكل معيار');
            redirect(url('quality', ['a' => 'form', 'episode' => $episodeId, 'type' => $type]));
        }
        $items[(int)$c['id']] = $v;
    }
    if (!$items) {
        flash_set('danger', 'لا توجد معايير تقييم فعالة — راجع مدير النظام');
        redirect(url('dashboard'));
    }
    $avg = array_sum($items) / count($items);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO ' . tbl('evaluations') . '
            (episode_id, user_id, type, score, notes) VALUES (?,?,?,?,?)');
        $st->execute([$episodeId, (int)$user['id'], $type, round($avg, 2), $notes ?: null]);
        $evalId = (int)$pdo->lastInsertId();
        $sti = $pdo->prepare('INSERT INTO ' . tbl('evaluation_items') . '
            (evaluation_id, criterion_id, score) VALUES (?,?,?)');
        foreach ($items as $cid => $v) $sti->execute([$evalId, $cid, $v]);
        $pdo->commit();
        audit_log('evaluate', 'episode', $episodeId,
            'تقييم ' . eval_type_label($type) . ' للحلقة: ' . $episode['title'] . ' — الدرجة ' . round($avg, 2));
        flash_set('success', 'تم حفظ تقييمك بنجاح — الدرجة: ' . fmt_num($avg) . ' / 10');
    } catch (PDOException $e) {
        $pdo->rollBack();
        flash_set('danger', $e->getCode() === '23000'
            ? 'قمت بتقييم هذه الحلقة مسبقاً — لا يمكن التكرار'
            : 'حدث خطأ أثناء الحفظ');
    }
    redirect(url('dashboard'));
}

layout_header('تقييم ' . eval_type_label($type));
?>
<div class="card card-narrow">
    <div class="card-header">
        <h2>تقييم <?= eval_type_label($type) ?>: <?= e($episode['title']) ?></h2>
    </div>
    <div class="detail-grid">
        <div><span class="muted">البرنامج:</span> <?= e($episode['program_name']) ?></div>
        <div><span class="muted">الإذاعة:</span> <?= e($episode['station_name']) ?></div>
        <div><span class="muted">البث:</span> <?= fmt_date($episode['air_date']) ?> <?= fmt_time($episode['air_time']) ?></div>
    </div>

    <?php if ($existing): ?>
        <div class="alert alert-info">
            قمت بتقييم هذه الحلقة مسبقاً بتاريخ <?= fmt_datetime($existing['created_at']) ?>
            — درجتك: <strong><?= fmt_num($existing['score']) ?> / 10</strong>. لا يمكن تكرار التقييم.
        </div>
        <a class="btn btn-ghost" href="<?= e(url('dashboard')) ?>">← العودة للرئيسية</a>
    <?php else: ?>
    <form method="post" action="<?= e(url('quality', ['a' => 'save'])) ?>" class="eval-form">
        <?= csrf_field() ?>
        <input type="hidden" name="episode_id" value="<?= $episodeId ?>">
        <input type="hidden" name="type" value="<?= e($type) ?>">

        <?php foreach ($criteria as $c): ?>
        <div class="eval-criterion">
            <label><?= e($c['name']) ?></label>
            <div class="score-picker" data-score-picker>
                <?php for ($i = 1; $i <= 10; $i++): ?>
                <label class="score-option">
                    <input type="radio" name="scores[<?= (int)$c['id'] ?>]" value="<?= $i ?>" required>
                    <span><?= $i ?></span>
                </label>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="form-group">
            <label>ملاحظات (اختياري)</label>
            <textarea name="notes" rows="3" placeholder="ملاحظاتك على الحلقة..."></textarea>
        </div>

        <div class="eval-live">متوسط تقييمك الحالي: <strong id="liveAvg">—</strong> / 10</div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">حفظ التقييم النهائي</button>
            <a href="<?= e(url('dashboard')) ?>" class="btn btn-ghost">إلغاء</a>
        </div>
        <p class="muted">تنبيه: لا يمكن تعديل التقييم بعد حفظه.</p>
    </form>
    <?php endif; ?>
</div>
<?php
layout_footer();
