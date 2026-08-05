<?php
/** موديول لوحة القيادة + مهامي */
if (!defined('SBA')) exit;

$user = current_user();
$isEvaluator = can('eval.technical') || can('eval.content');
$showAnalytics = can('data.viewall');

$from30 = date('Y-m-d', strtotime('-29 days'));
$today  = date('Y-m-d');

layout_header('الرئيسية');

/* ---------- مهامي (للمقيمين) ---------- */
if ($isEvaluator) {
    $tasks = user_tasks((int)$user['id']);
    // الموظف يرى فقط النوع الذي يملك صلاحيته
    $filterTasks = function (array $list) {
        return array_values(array_filter($list, function ($t) {
            return ($t['type'] === 'technical' && can('eval.technical'))
                || ($t['type'] === 'content' && can('eval.content'));
        }));
    };
    $pending = $filterTasks($tasks['pending']);
    $done    = $filterTasks($tasks['done']);

    /* ترتيب المعلقة: الأقدم بثاً أولاً (الأكثر تأخراً في الأعلى) */
    usort($pending, fn($x, $y) => strcmp($x['air_date'], $y['air_date']));
    $today = date('Y-m-d');
    $lateDays = fn($t) => max(0, (int)((strtotime($today) - strtotime($t['air_date'])) / 86400));
    $overdue = count(array_filter($pending, fn($t) => $lateDays($t) >= 3));
    $myAvg = null;
    if ($done) {
        $sum = 0;
        foreach ($done as $d) $sum += (float)$d['score'];
        $myAvg = $sum / count($done);
    }
    $myTotal = count($pending) + count($done);
    $myRate = $myTotal ? 100 * count($done) / $myTotal : null;
    ?>
    <?php if ($myTotal > 0): /* لا تُعرض المؤشرات الشخصية لمن لا مهام له */ ?>
    <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-label">مهام معلقة</div>
            <div class="kpi-value <?= $pending ? 'kpi-bad' : 'kpi-good' ?>"><?= count($pending) ?></div></div>
        <div class="kpi-card"><div class="kpi-label">متأخرة (3 أيام فأكثر)</div>
            <div class="kpi-value <?= $overdue ? 'kpi-bad' : 'kpi-good' ?>"><?= $overdue ?></div></div>
        <div class="kpi-card"><div class="kpi-label">منجزة</div>
            <div class="kpi-value kpi-good"><?= count($done) ?></div></div>
        <div class="kpi-card"><div class="kpi-label">نسبة إنجازي</div>
            <div class="kpi-value"><?= $myRate === null ? '—' : fmt_num($myRate, 0) . '%' ?></div></div>
        <div class="kpi-card"><div class="kpi-label">متوسط درجاتي</div>
            <div class="kpi-value"><?= fmt_num($myAvg) ?></div></div>
    </div>
    <?php endif; ?>
    <?php if ($myTotal > 0 || !$showAnalytics): ?>
    <div class="card">
        <div class="card-header">
            <h2>&#128203; مهامي</h2>
            <div class="tabs" data-tabs>
                <button class="tab active" data-tab="pending">غير منفذة (<?= count($pending) ?>)</button>
                <button class="tab" data-tab="done">منفذة (<?= count($done) ?>)</button>
            </div>
        </div>
        <div class="tab-panel" data-panel="pending">
            <?php if (!$pending): ?>
                <div class="empty-state small"><p>&#127881; لا توجد مهام معلقة — أحسنت!</p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>الحلقة</th><th>البرنامج</th><th>الإذاعة</th><th>تاريخ البث</th><th>نوع التقييم</th><th>التأخير</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pending as $t): $late = $lateDays($t); ?>
                <tr class="<?= $late >= 3 ? 'row-late' : '' ?>">
                    <td><?= e($t['title']) ?></td>
                    <td><?= e($t['program_name']) ?></td>
                    <td><?= e($t['station_name']) ?></td>
                    <td><?= fmt_date($t['air_date']) ?> <?= fmt_time($t['air_time']) ?></td>
                    <td><span class="badge badge-<?= $t['type'] === 'technical' ? 'info' : 'violet' ?>"><?= eval_type_label($t['type']) ?></span></td>
                    <td>
                        <?php if (strtotime($t['air_date']) > time()): ?>
                            <span class="badge badge-muted">لم تُبث بعد</span>
                        <?php elseif ($late === 0): ?>
                            <span class="badge badge-success">اليوم</span>
                        <?php elseif ($late < 3): ?>
                            <span class="badge badge-warning"><?= $late ?> يوم</span>
                        <?php else: ?>
                            <span class="badge badge-danger">متأخرة <?= $late ?> أيام</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-primary btn-sm" href="<?= e(url('quality', ['a' => 'form', 'episode' => $t['episode_id'], 'type' => $t['type']])) ?>">تقييم الآن</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <div class="tab-panel hidden" data-panel="done">
            <?php if (!$done): ?>
                <div class="empty-state small"><p>لا توجد مهام منفذة بعد</p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>الحلقة</th><th>البرنامج</th><th>نوع التقييم</th><th>درجتي</th><th>تاريخ التنفيذ</th></tr></thead>
                <tbody>
                <?php foreach ($done as $t): ?>
                <tr>
                    <td><?= e($t['title']) ?></td>
                    <td><?= e($t['program_name']) ?></td>
                    <td><span class="badge badge-<?= $t['type'] === 'technical' ? 'info' : 'violet' ?>"><?= eval_type_label($t['type']) ?></span></td>
                    <td><strong><?= fmt_num($t['score']) ?></strong> / 10</td>
                    <td><?= fmt_datetime($t['eval_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php
}

/* ---------- شاشة الالتزام للموظف المختص ---------- */
if (!$showAnalytics && can('compliance.edit')) {
    echo '<div class="card"><div class="card-header"><h2>&#128337; التزام البث</h2></div>
          <p>يمكنك تسجيل التزام الإذاعات من شاشة الالتزام.</p>
          <a class="btn btn-primary" href="' . e(url('compliance')) . '">فتح شاشة الالتزام ←</a></div>';
}

/* ---------- التحليلات (مدير النظام / مدير الجودة / مشاهد) ---------- */
if ($showAnalytics):
    $compRate  = compliance_rate($from30, $today);
    $stationsRank = station_rankings($from30, $today);
    $programsRank = program_rankings($from30, $today);
    $daily = compliance_daily(14);

    $avgQuality = null;
    if ($stationsRank) {
        $sum = 0; $n = 0;
        foreach ($stationsRank as $s) { $sum += $s['avg'] * $s['count']; $n += $s['count']; }
        $avgQuality = $n ? $sum / $n : null;
    }
    $counts = [
        'stations' => (int)db()->query('SELECT COUNT(*) FROM ' . tbl('stations') . ' WHERE active=1')->fetchColumn(),
        'programs' => (int)db()->query('SELECT COUNT(*) FROM ' . tbl('programs') . ' WHERE active=1')->fetchColumn(),
        'episodes' => (int)db()->query('SELECT COUNT(*) FROM ' . tbl('episodes'))->fetchColumn(),
    ];
    $best  = array_slice($programsRank, 0, 5);
    $worst = array_slice(array_reverse($programsRank), 0, 5);
?>
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">معدل الالتزام (آخر 30 يوماً)</div>
            <div class="kpi-value <?= $compRate !== null && $compRate < 80 ? 'kpi-bad' : 'kpi-good' ?>">
                <?= $compRate === null ? '—' : fmt_num($compRate) . '%' ?>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">متوسط الجودة (من 10)</div>
            <div class="kpi-value"><?= $avgQuality === null ? '—' : fmt_num($avgQuality) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">إذاعات نشطة</div>
            <div class="kpi-value"><?= $counts['stations'] ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">برامج نشطة</div>
            <div class="kpi-value"><?= $counts['programs'] ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">إجمالي الحلقات</div>
            <div class="kpi-value"><?= $counts['episodes'] ?></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2>ترتيب الإذاعات حسب الجودة</h2></div>
            <?php if ($stationsRank): ?>
                <div class="chart-box"><canvas id="chartStations"></canvas></div>
            <?php else: ?>
                <div class="empty-state small"><p>لا توجد تقييمات في آخر 30 يوماً</p></div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><h2>معدل الالتزام اليومي (آخر 14 يوماً)</h2></div>
            <div class="chart-box"><canvas id="chartCompliance"></canvas></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2>&#127942; أفضل البرامج</h2></div>
            <?php if (!$best): ?><div class="empty-state small"><p>لا توجد بيانات</p></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>#</th><th>البرنامج</th><th>الإذاعة</th><th>المتوسط</th></tr></thead>
                <tbody>
                <?php foreach ($best as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['station']) ?></td>
                    <td><span class="badge badge-success"><?= fmt_num($p['avg']) ?> / 10</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header"><h2>&#9888;&#65039; برامج تحتاج تحسيناً</h2></div>
            <?php if (!$worst): ?><div class="empty-state small"><p>لا توجد بيانات</p></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>#</th><th>البرنامج</th><th>الإذاعة</th><th>المتوسط</th></tr></thead>
                <tbody>
                <?php foreach ($worst as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['station']) ?></td>
                    <td><span class="badge <?= $p['avg'] < 6 ? 'badge-danger' : 'badge-warning' ?>"><?= fmt_num($p['avg']) ?> / 10</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="assets/js/chart.umd.min.js"></script>
    <script>
    window.addEventListener('DOMContentLoaded', function () {
        var stations = <?= json_encode([
            'labels' => array_column($stationsRank, 'name'),
            'data'   => array_map(fn($s) => round($s['avg'], 2), $stationsRank),
        ], JSON_UNESCAPED_UNICODE) ?>;
        var daily = <?= json_encode([
            'labels' => array_map(fn($d) => substr($d['date'], 5), $daily),
            'data'   => array_map(fn($d) => $d['rate'] === null ? null : round($d['rate'], 1), $daily),
        ], JSON_UNESCAPED_UNICODE) ?>;
        if (window.sbaCharts) {
            sbaCharts.stationsBar('chartStations', stations);
            sbaCharts.complianceLine('chartCompliance', daily);
        }
    });
    </script>
<?php endif;

layout_footer();
