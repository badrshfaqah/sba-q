<?php
/** موديول التقارير: برنامج / التزام / مستخدمون — مع فلترة زمنية */
if (!defined('SBA')) exit;

$type = input('type', 'program');
if (!in_array($type, ['program', 'compliance', 'users'], true)) $type = 'program';

$from = input('from', date('Y-m-d', strtotime('-29 days')));
$to   = input('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

$group = input('group', 'daily');
if (!in_array($group, ['daily', 'weekly', 'monthly'], true)) $group = 'daily';

$programId = (int)($_GET['program'] ?? 0);
$programs = db()->query('SELECT p.id, p.name, s.name AS station FROM ' . tbl('programs') . ' p
    JOIN ' . tbl('stations') . ' s ON s.id=p.station_id ORDER BY s.name, p.name')->fetchAll();

layout_header('التقارير');
?>
<div class="card no-print">
    <div class="card-header"><h2>مولّد التقارير</h2></div>
    <form method="get" action="index.php" class="report-filter">
        <input type="hidden" name="m" value="reports">
        <div class="form-row">
            <div class="form-group">
                <label>نوع التقرير</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="program"    <?= $type === 'program' ? 'selected' : '' ?>>تقرير برنامج (حلقاته)</option>
                    <option value="compliance" <?= $type === 'compliance' ? 'selected' : '' ?>>تقرير الالتزام</option>
                    <option value="users"      <?= $type === 'users' ? 'selected' : '' ?>>تقرير المستخدمين (المقيمين)</option>
                </select>
            </div>
            <?php if ($type === 'program'): ?>
            <div class="form-group">
                <label>البرنامج</label>
                <select name="program">
                    <option value="0">كل البرامج</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $programId === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= e($p['station'] . ' — ' . $p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label>من تاريخ</label>
                <input type="date" name="from" value="<?= e($from) ?>">
            </div>
            <div class="form-group">
                <label>إلى تاريخ</label>
                <input type="date" name="to" value="<?= e($to) ?>">
            </div>
            <?php if ($type === 'compliance'): ?>
            <div class="form-group">
                <label>التجميع</label>
                <select name="group">
                    <option value="daily"   <?= $group === 'daily' ? 'selected' : '' ?>>يومي</option>
                    <option value="weekly"  <?= $group === 'weekly' ? 'selected' : '' ?>>أسبوعي</option>
                    <option value="monthly" <?= $group === 'monthly' ? 'selected' : '' ?>>شهري</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group form-group-btn">
                <button type="submit" class="btn btn-primary">عرض التقرير</button>
                <button type="button" class="btn btn-ghost" onclick="window.print()">&#128424;&#65039; طباعة</button>
            </div>
        </div>
    </form>
</div>

<div class="print-header">
    <img src="assets/img/SBA_logo.png" alt="" width="46">
    <div>
        <strong>منصة متابعة جودة البث والمحتوى الإذاعي</strong><br>
        <span class="muted">الفترة: <?= e($from) ?> إلى <?= e($to) ?> — أُنشئ في <?= date('Y-m-d H:i') ?></span>
    </div>
</div>

<?php /* ================= تقرير البرنامج ================= */
if ($type === 'program'):
    $rows = episode_scores($from, $to, $programId);
    [$wt, $wc] = quality_weights();
?>
<div class="card">
    <div class="card-header"><h2>تقرير حلقات البرامج (<?= count($rows) ?> حلقة)</h2></div>
    <?php if (!$rows): ?>
        <div class="empty-state"><p>لا توجد حلقات في الفترة المحددة</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th>الحلقة</th><th>البرنامج</th><th>الإذاعة</th><th>تاريخ البث</th>
            <th>فني (<?= round($wt * 100) ?>%)</th><th>محتوى (<?= round($wc * 100) ?>%)</th>
            <th>النهائية</th><th>عدد التقييمات</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['title']) ?></td>
            <td><?= e($r['program_name']) ?></td>
            <td><?= e($r['station_name']) ?></td>
            <td><?= fmt_date($r['air_date']) ?> <?= fmt_time($r['air_time']) ?></td>
            <td><?= fmt_num($r['tavg']) ?></td>
            <td><?= fmt_num($r['cavg']) ?></td>
            <td>
                <?php if ($r['final'] === null): ?>—
                <?php else: ?>
                <span class="badge <?= $r['final'] >= 8 ? 'badge-success' : ($r['final'] >= 6 ? 'badge-warning' : 'badge-danger') ?>">
                    <?= fmt_num($r['final']) ?>
                </span>
                <?php endif; ?>
            </td>
            <td><?= (int)$r['eval_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php /* ================= تقرير الالتزام ================= */
elseif ($type === 'compliance'):
    switch ($group) {
        case 'weekly':  $expr = 'DATE_FORMAT(check_at, "%x-أسبوع %v")'; break;
        case 'monthly': $expr = 'DATE_FORMAT(check_at, "%Y-%m")'; break;
        default:        $expr = 'DATE(check_at)';
    }
    $st = db()->prepare("SELECT $expr AS period, COUNT(*) AS total, SUM(status) AS ok
        FROM " . tbl('compliance') . '
        WHERE check_at >= ? AND check_at <= ?
        GROUP BY period ORDER BY MIN(check_at)');
    $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $periods = $st->fetchAll();
    $byStation = compliance_by_station($from, $to);
    $overall = compliance_rate($from, $to);
?>
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">معدل الالتزام العام للفترة</div>
        <div class="kpi-value <?= $overall !== null && $overall < 80 ? 'kpi-bad' : 'kpi-good' ?>">
            <?= $overall === null ? '—' : fmt_num($overall) . '%' ?>
        </div>
    </div>
</div>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>الالتزام حسب الفترة (<?= $group === 'daily' ? 'يومي' : ($group === 'weekly' ? 'أسبوعي' : 'شهري') ?>)</h2></div>
        <?php if (!$periods): ?><div class="empty-state small"><p>لا توجد بيانات</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>الفترة</th><th>عدد الفحوصات</th><th>ملتزم</th><th>المعدل</th></tr></thead>
            <tbody>
            <?php foreach ($periods as $p): $rate = 100 * (int)$p['ok'] / max(1, (int)$p['total']); ?>
            <tr>
                <td><?= e($p['period']) ?></td>
                <td><?= (int)$p['total'] ?></td>
                <td><?= (int)$p['ok'] ?></td>
                <td><span class="badge <?= $rate >= 80 ? 'badge-success' : 'badge-danger' ?>"><?= fmt_num($rate) ?>%</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><h2>الالتزام حسب الإذاعة</h2></div>
        <table class="table">
            <thead><tr><th>الإذاعة</th><th>الفحوصات</th><th>المعدل</th></tr></thead>
            <tbody>
            <?php foreach ($byStation as $s): ?>
            <tr>
                <td><?= e($s['name']) ?></td>
                <td><?= (int)$s['total'] ?></td>
                <td>
                    <?php if ($s['rate'] === null): ?>—
                    <?php else: ?>
                    <span class="badge <?= $s['rate'] >= 80 ? 'badge-success' : 'badge-danger' ?>"><?= fmt_num($s['rate']) ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* ================= تقرير المستخدمين ================= */
else:
    $st = db()->prepare('SELECT u.id, u.name, u.role,
            COUNT(ev.id) AS eval_count,
            MAX(ev.score) AS max_score,
            MIN(ev.score) AS min_score,
            AVG(ev.score) AS avg_score
        FROM ' . tbl('users') . ' u
        JOIN ' . tbl('evaluations') . ' ev ON ev.user_id = u.id
        JOIN ' . tbl('episodes') . ' e ON e.id = ev.episode_id
        WHERE e.air_date >= ? AND e.air_date <= ?
        GROUP BY u.id ORDER BY eval_count DESC');
    $st->execute([$from, $to]);
    $userRows = $st->fetchAll();
?>
<div class="card">
    <div class="card-header"><h2>تقرير أداء المقيمين (<?= count($userRows) ?>)</h2></div>
    <?php if (!$userRows): ?>
        <div class="empty-state"><p>لا توجد تقييمات في الفترة المحددة</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th>المقيم</th><th>العضوية</th><th>عدد التقييمات</th>
            <th>أعلى تقييم أعطاه</th><th>أقل تقييم أعطاه</th><th>متوسط تقييماته</th>
        </tr></thead>
        <tbody>
        <?php foreach ($userRows as $u): ?>
        <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td><?= e(role_label($u['role'])) ?></td>
            <td><?= (int)$u['eval_count'] ?></td>
            <td><span class="badge badge-success"><?= fmt_num($u['max_score']) ?></span></td>
            <td><span class="badge badge-danger"><?= fmt_num($u['min_score']) ?></span></td>
            <td><?= fmt_num($u['avg_score']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif;

layout_footer();
