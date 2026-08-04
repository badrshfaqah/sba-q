<?php
/**
 * موديول متابعة الموظفين
 * المهام = تكليفات التقييم التي يوزعها مدير الجودة على المقيمين
 * يعرض: نسبة الإنجاز، المهام المعلقة والمنجزة، والمتأخرات
 */
if (!defined('SBA')) exit;

$from = input('from', date('Y-m-d', strtotime('-29 days')));
$to   = input('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

/* أداء كل مقيم: تكليفاته مقابل ما نفذه */
$st = db()->prepare('SELECT u.id, u.name, u.role,
        COUNT(a.id) AS total_tasks,
        SUM(CASE WHEN ev.id IS NOT NULL THEN 1 ELSE 0 END) AS done_tasks,
        SUM(CASE WHEN a.type = "technical" THEN 1 ELSE 0 END) AS tech_tasks,
        SUM(CASE WHEN a.type = "content" THEN 1 ELSE 0 END) AS content_tasks,
        AVG(ev.score) AS avg_score,
        MAX(ev.created_at) AS last_eval
    FROM ' . tbl('users') . ' u
    JOIN ' . tbl('episode_evaluators') . ' a ON a.user_id = u.id
    JOIN ' . tbl('episodes') . ' e ON e.id = a.episode_id
    LEFT JOIN ' . tbl('evaluations') . ' ev
           ON ev.episode_id = a.episode_id AND ev.user_id = a.user_id AND ev.type = a.type
    WHERE e.air_date >= ? AND e.air_date <= ?
    GROUP BY u.id
    ORDER BY total_tasks DESC, u.name');
$st->execute([$from, $to]);
$staff = $st->fetchAll();

$grandTotal = $grandDone = 0;
foreach ($staff as $s) {
    $grandTotal += (int)$s['total_tasks'];
    $grandDone  += (int)$s['done_tasks'];
}
$grandPending = $grandTotal - $grandDone;
$grandRate = $grandTotal ? 100.0 * $grandDone / $grandTotal : null;

/* المهام المعلقة المتأخرة (حلقة بُثت ولم تُقيَّم بعد) */
$st = db()->prepare('SELECT a.type, u.name AS user_name, e.title, e.air_date, e.air_time,
        p.name AS program_name, s.name AS station_name,
        DATEDIFF(CURDATE(), e.air_date) AS days_late
    FROM ' . tbl('episode_evaluators') . ' a
    JOIN ' . tbl('users') . ' u ON u.id = a.user_id
    JOIN ' . tbl('episodes') . ' e ON e.id = a.episode_id
    JOIN ' . tbl('programs') . ' p ON p.id = e.program_id
    JOIN ' . tbl('stations') . ' s ON s.id = p.station_id
    LEFT JOIN ' . tbl('evaluations') . ' ev
           ON ev.episode_id = a.episode_id AND ev.user_id = a.user_id AND ev.type = a.type
    WHERE ev.id IS NULL AND e.air_date >= ? AND e.air_date <= ? AND e.air_date <= CURDATE()
    ORDER BY e.air_date ASC
    LIMIT 50');
$st->execute([$from, $to]);
$latePending = $st->fetchAll();

layout_header('متابعة الموظفين');
?>
<div class="card no-print">
    <form method="get" action="index.php" class="report-filter">
        <input type="hidden" name="m" value="followup">
        <div class="form-row" style="grid-template-columns: 1fr 1fr auto; align-items: end;">
            <div class="form-group"><label>من تاريخ (تاريخ بث الحلقة)</label><input type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="form-group"><label>إلى تاريخ</label><input type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="form-group form-group-btn">
                <button type="submit" class="btn btn-primary">تحديث</button>
                <button type="button" class="btn btn-ghost" onclick="window.print()">&#128424;&#65039; طباعة</button>
            </div>
        </div>
    </form>
</div>

<div class="kpi-grid">
    <div class="kpi-card"><div class="kpi-label">إجمالي المهام</div>
        <div class="kpi-value"><?= number_format($grandTotal) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">مهام منجزة</div>
        <div class="kpi-value kpi-good"><?= number_format($grandDone) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">مهام معلقة</div>
        <div class="kpi-value <?= $grandPending > 0 ? 'kpi-bad' : '' ?>"><?= number_format($grandPending) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">نسبة الإنجاز العامة</div>
        <div class="kpi-value <?= $grandRate !== null && $grandRate < 70 ? 'kpi-bad' : 'kpi-good' ?>">
            <?= $grandRate === null ? '—' : fmt_num($grandRate) . '%' ?></div></div>
</div>

<div class="card">
    <div class="card-header"><h2>&#127919; أداء المقيمين (<?= count($staff) ?>)</h2></div>
    <?php if (!$staff): ?>
        <div class="empty-state"><p>لا توجد تكليفات في الفترة المحددة — وزّع المقيمين من شاشة الحلقات</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th>الموظف</th><th>المهام</th><th>منجز</th><th>معلق</th>
            <th style="min-width:160px">نسبة الإنجاز</th>
            <th>فني / محتوى</th><th>متوسط درجاته</th><th>آخر تقييم</th>
        </tr></thead>
        <tbody>
        <?php foreach ($staff as $s):
            $total = (int)$s['total_tasks'];
            $done = (int)$s['done_tasks'];
            $pending = $total - $done;
            $rate = $total ? 100.0 * $done / $total : 0; ?>
        <tr>
            <td><strong><?= e($s['name']) ?></strong><br><small class="muted"><?= e(role_label($s['role'])) ?></small></td>
            <td><?= $total ?></td>
            <td><span class="badge badge-success"><?= $done ?></span></td>
            <td><?= $pending ? '<span class="badge badge-danger">' . $pending . '</span>' : '<span class="badge badge-muted">0</span>' ?></td>
            <td>
                <div class="progress" title="<?= fmt_num($rate) ?>%">
                    <div class="progress-fill <?= $rate >= 100 ? 'full' : ($rate < 50 ? 'low' : '') ?>" style="width:<?= round($rate) ?>%"></div>
                </div>
                <small class="muted"><?= fmt_num($rate, 0) ?>%</small>
            </td>
            <td><?= (int)$s['tech_tasks'] ?> / <?= (int)$s['content_tasks'] ?></td>
            <td><?= fmt_num($s['avg_score']) ?></td>
            <td><?= $s['last_eval'] ? fmt_datetime($s['last_eval']) : '<span class="muted">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2>&#9203; المهام المعلقة (حلقات بُثت ولم تُقيَّم)</h2></div>
    <?php if (!$latePending): ?>
        <div class="empty-state small"><p>&#127881; لا توجد مهام متأخرة — ممتاز!</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>المقيم</th><th>الحلقة</th><th>البرنامج</th><th>الإذاعة</th><th>النوع</th><th>تاريخ البث</th><th>التأخير</th></tr></thead>
        <tbody>
        <?php foreach ($latePending as $lp): $late = max(0, (int)$lp['days_late']); ?>
        <tr>
            <td><strong><?= e($lp['user_name']) ?></strong></td>
            <td><?= e($lp['title']) ?></td>
            <td><?= e($lp['program_name']) ?></td>
            <td><?= e($lp['station_name']) ?></td>
            <td><span class="badge badge-<?= $lp['type'] === 'technical' ? 'info' : 'violet' ?>"><?= eval_type_label($lp['type']) ?></span></td>
            <td><?= fmt_date($lp['air_date']) ?> <?= fmt_time($lp['air_time']) ?></td>
            <td>
                <?php if ($late === 0): ?><span class="badge badge-muted">اليوم</span>
                <?php elseif ($late <= 2): ?><span class="badge badge-warning"><?= $late ?> يوم</span>
                <?php else: ?><span class="badge badge-danger"><?= $late ?> أيام</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php
layout_footer();
