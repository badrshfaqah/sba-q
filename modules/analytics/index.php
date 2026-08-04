<?php
/**
 * موديول ذكاء الأعمال (BI)
 * يفصل بوضوح بين بُعدين مستقلين:
 *  - جودة الحلقات: تقييم تحريري/فني لمحتوى البرامج (كيف كان ما بُث؟)
 *  - التزام البث: انضباط تشغيلي بهيكل البث عند نقاط زمنية (هل بُث ما هو مخطط له وقتَه؟)
 */
if (!defined('SBA')) exit;

$from = input('from', date('Y-m-d', strtotime('-29 days')));
$to   = input('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

/* ---------- تدرّج أزرق تسلسلي (لوحة موثّقة وآمنة لعمى الألوان) ---------- */
function heat_color(?float $rate): array
{
    // [خلفية، لون نص]
    if ($rate === null) return ['transparent', 'var(--muted)'];
    $steps = [
        [0,   '#cde2fb', '#0b0b0b'],
        [40,  '#9ec5f4', '#0b0b0b'],
        [60,  '#6da7ec', '#0b0b0b'],
        [75,  '#3987e5', '#ffffff'],
        [85,  '#256abf', '#ffffff'],
        [95,  '#184f95', '#ffffff'],
        [100, '#0d366b', '#ffffff'],
    ];
    $pick = $steps[0];
    foreach ($steps as $s) { if ($rate >= $s[0]) $pick = $s; }
    return [$pick[1], $pick[2]];
}

/* ---------- بيانات الالتزام ---------- */
$st = db()->prepare('SELECT DATE(check_at) AS d, DATE_FORMAT(check_at, "%H:%i") AS t,
        c.station_id, s.name AS station_name, c.status
    FROM ' . tbl('compliance') . ' c
    JOIN ' . tbl('stations') . ' s ON s.id = c.station_id
    WHERE check_at >= ? AND check_at <= ?');
$st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
$compRows = $st->fetchAll();

$byDayTime = []; $byStationTime = []; $days = []; $times = []; $stationNames = [];
$totalChecks = 0; $totalOk = 0;
$byTimeAgg = [];
foreach ($compRows as $r) {
    $totalChecks++;
    $totalOk += (int)$r['status'];
    $days[$r['d']] = true;
    $times[$r['t']] = true;
    $stationNames[$r['station_id']] = $r['station_name'];

    $byDayTime[$r['t']][$r['d']]['n'] = ($byDayTime[$r['t']][$r['d']]['n'] ?? 0) + 1;
    $byDayTime[$r['t']][$r['d']]['ok'] = ($byDayTime[$r['t']][$r['d']]['ok'] ?? 0) + (int)$r['status'];

    $byStationTime[$r['station_id']][$r['t']]['n'] = ($byStationTime[$r['station_id']][$r['t']]['n'] ?? 0) + 1;
    $byStationTime[$r['station_id']][$r['t']]['ok'] = ($byStationTime[$r['station_id']][$r['t']]['ok'] ?? 0) + (int)$r['status'];

    $byTimeAgg[$r['t']]['n'] = ($byTimeAgg[$r['t']]['n'] ?? 0) + 1;
    $byTimeAgg[$r['t']]['ok'] = ($byTimeAgg[$r['t']]['ok'] ?? 0) + (int)$r['status'];
}
$days = array_keys($days); sort($days);
$times = array_keys($times); sort($times);
$overallRate = $totalChecks ? 100.0 * $totalOk / $totalChecks : null;

/* أفضل وأسوأ نقطة زمنية */
$bestTime = $worstTime = null;
foreach ($byTimeAgg as $t => $agg) {
    $rate = 100.0 * $agg['ok'] / $agg['n'];
    if ($bestTime === null || $rate > $bestTime[1])  $bestTime = [$t, $rate];
    if ($worstTime === null || $rate < $worstTime[1]) $worstTime = [$t, $rate];
}

/* ---------- بيانات الجودة ---------- */
$scores = episode_scores($from, $to);
$techSum = $techN = $contSum = $contN = 0;
foreach ($scores as $srow) {
    if ($srow['tavg'] !== null) { $techSum += (float)$srow['tavg']; $techN++; }
    if ($srow['cavg'] !== null) { $contSum += (float)$srow['cavg']; $contN++; }
}
$techAvg = $techN ? $techSum / $techN : null;
$contAvg = $contN ? $contSum / $contN : null;

/* توزيع درجات التقييمات (1-10) */
$st = db()->prepare('SELECT ROUND(ev.score) AS bucket, ev.type, COUNT(*) AS n
    FROM ' . tbl('evaluations') . ' ev
    JOIN ' . tbl('episodes') . ' e ON e.id = ev.episode_id
    WHERE e.air_date >= ? AND e.air_date <= ?
    GROUP BY bucket, ev.type');
$st->execute([$from, $to]);
$dist = ['technical' => array_fill(1, 10, 0), 'content' => array_fill(1, 10, 0)];
foreach ($st->fetchAll() as $r) {
    $b = max(1, min(10, (int)$r['bucket']));
    $dist[$r['type']][$b] += (int)$r['n'];
}

/* ---------- التحليل الرباعي: جودة × التزام لكل إذاعة ---------- */
$qualityByStation = [];
foreach (station_rankings($from, $to) as $sr) $qualityByStation[$sr['station_id']] = $sr;
$compByStation = [];
foreach (compliance_by_station($from, $to) as $cr) $compByStation[$cr['id']] = $cr;

$Q_THRESHOLD = 7.0; $C_THRESHOLD = 80.0;
$quadrants = ['star' => [], 'quality_only' => [], 'discipline_only' => [], 'critical' => [], 'nodata' => []];
$allStations = db()->query('SELECT id, name FROM ' . tbl('stations') . ' WHERE active=1 ORDER BY name')->fetchAll();
foreach ($allStations as $s) {
    $q = $qualityByStation[$s['id']]['avg'] ?? null;
    $c = $compByStation[$s['id']]['rate'] ?? null;
    $item = ['name' => $s['name'], 'q' => $q, 'c' => $c];
    if ($q === null || $c === null) { $quadrants['nodata'][] = $item; continue; }
    if ($q >= $Q_THRESHOLD && $c >= $C_THRESHOLD)      $quadrants['star'][] = $item;
    elseif ($q >= $Q_THRESHOLD)                        $quadrants['quality_only'][] = $item;
    elseif ($c >= $C_THRESHOLD)                        $quadrants['discipline_only'][] = $item;
    else                                               $quadrants['critical'][] = $item;
}

layout_header('ذكاء الأعمال');
?>
<div class="card no-print">
    <form method="get" action="index.php" class="report-filter">
        <input type="hidden" name="m" value="analytics">
        <div class="form-row" style="grid-template-columns: 1fr 1fr auto; align-items: end;">
            <div class="form-group"><label>من تاريخ</label><input type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="form-group"><label>إلى تاريخ</label><input type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="form-group form-group-btn"><button type="submit" class="btn btn-primary">تحديث التحليل</button></div>
        </div>
    </form>
</div>

<div class="card bi-explainer">
    <div class="card-header"><h2>&#129504; بُعدان مستقلان لأداء الإذاعة</h2></div>
    <div class="grid-2" style="margin-bottom:0">
        <div class="dim-card dim-quality">
            <h3>&#11088; جودة الحلقات</h3>
            <p><strong>السؤال:</strong> كيف كان مستوى ما بُث؟</p>
            <p>تقييم تحريري وفني لمحتوى البرامج (صوت، أداء، لغة، فكرة) يقوم به مقيّمون معيّنون لكل حلقة.
               مقياس نوعي من 10 — يقيس <strong>الإتقان</strong>.</p>
        </div>
        <div class="dim-card dim-compliance">
            <h3>&#128337; التزام البث</h3>
            <p><strong>السؤال:</strong> هل بُث ما هو مخطط له في وقته؟</p>
            <p>فحص تشغيلي عند نقاط زمنية: هل الإذاعة ملتزمة بهيكل البث المعتمد؟
               مقياس ثنائي (ملتزم/غير ملتزم) — يقيس <strong>الانضباط</strong>.</p>
        </div>
    </div>
    <p class="muted" style="margin-top:10px">
        استقلال البعدين هو جوهر التحليل: إذاعة قد تقدم محتوى ممتازاً لكنها تخالف الجدول، وأخرى منضبطة تماماً
        بمحتوى ضعيف — لذلك يعالجهما النظام بفريقين وشاشتين ومقياسين، ويجمعهما التحليل الرباعي أدناه.
    </p>
</div>

<div class="kpi-grid">
    <div class="kpi-card"><div class="kpi-label">متوسط الجودة الفنية</div>
        <div class="kpi-value"><?= fmt_num($techAvg) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">متوسط جودة المحتوى</div>
        <div class="kpi-value"><?= fmt_num($contAvg) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">معدل الالتزام العام</div>
        <div class="kpi-value <?= $overallRate !== null && $overallRate < 80 ? 'kpi-bad' : 'kpi-good' ?>">
            <?= $overallRate === null ? '—' : fmt_num($overallRate) . '%' ?></div></div>
    <div class="kpi-card"><div class="kpi-label">نقاط الفحص</div>
        <div class="kpi-value"><?= number_format($totalChecks) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">أفضل نقطة زمنية</div>
        <div class="kpi-value kpi-good" style="font-size:1.2rem">
            <?= $bestTime ? e($bestTime[0]) . ' <small>(' . fmt_num($bestTime[1]) . '%)</small>' : '—' ?></div></div>
    <div class="kpi-card"><div class="kpi-label">أضعف نقطة زمنية</div>
        <div class="kpi-value kpi-bad" style="font-size:1.2rem">
            <?= $worstTime ? e($worstTime[0]) . ' <small>(' . fmt_num($worstTime[1]) . '%)</small>' : '—' ?></div></div>
</div>

<div class="card">
    <div class="card-header"><h2>&#127919; التحليل الرباعي: الجودة × الالتزام</h2>
        <span class="muted">العتبات: جودة ≥ <?= $Q_THRESHOLD ?> والتزام ≥ <?= $C_THRESHOLD ?>%</span></div>
    <div class="quadrant-grid">
        <div class="quadrant q-star">
            <h3>&#127775; النجوم <span class="muted">جودة عالية + التزام عالٍ</span></h3>
            <?php if (!$quadrants['star']): ?><p class="muted">لا يوجد</p><?php endif; ?>
            <?php foreach ($quadrants['star'] as $it): ?>
                <div class="q-item"><?= e($it['name']) ?>
                    <span><?= fmt_num($it['q']) ?>/10 • <?= fmt_num($it['c']) ?>%</span></div>
            <?php endforeach; ?>
        </div>
        <div class="quadrant q-quality">
            <h3>&#127917; إبداع بلا انضباط <span class="muted">جودة عالية + التزام منخفض</span></h3>
            <?php if (!$quadrants['quality_only']): ?><p class="muted">لا يوجد</p><?php endif; ?>
            <?php foreach ($quadrants['quality_only'] as $it): ?>
                <div class="q-item"><?= e($it['name']) ?>
                    <span><?= fmt_num($it['q']) ?>/10 • <?= fmt_num($it['c']) ?>%</span></div>
            <?php endforeach; ?>
            <p class="q-hint">المعالجة: ضبط تشغيلي وجداول بث</p>
        </div>
        <div class="quadrant q-discipline">
            <h3>&#9203; انضباط بلا جودة <span class="muted">جودة منخفضة + التزام عالٍ</span></h3>
            <?php if (!$quadrants['discipline_only']): ?><p class="muted">لا يوجد</p><?php endif; ?>
            <?php foreach ($quadrants['discipline_only'] as $it): ?>
                <div class="q-item"><?= e($it['name']) ?>
                    <span><?= fmt_num($it['q']) ?>/10 • <?= fmt_num($it['c']) ?>%</span></div>
            <?php endforeach; ?>
            <p class="q-hint">المعالجة: تطوير محتوى وتدريب مقدمين</p>
        </div>
        <div class="quadrant q-critical">
            <h3>&#128680; تدخل عاجل <span class="muted">جودة منخفضة + التزام منخفض</span></h3>
            <?php if (!$quadrants['critical']): ?><p class="muted">لا يوجد</p><?php endif; ?>
            <?php foreach ($quadrants['critical'] as $it): ?>
                <div class="q-item"><?= e($it['name']) ?>
                    <span><?= fmt_num($it['q']) ?>/10 • <?= fmt_num($it['c']) ?>%</span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($quadrants['nodata']): ?>
    <p class="muted">بدون بيانات كافية للفترة: <?= e(implode('، ', array_column($quadrants['nodata'], 'name'))) ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2>&#128293; الخريطة الحرارية: الالتزام حسب النقطة الزمنية × اليوم</h2></div>
    <?php if (!$times): ?>
        <div class="empty-state small"><p>لا توجد بيانات التزام في الفترة</p></div>
    <?php else: ?>
    <div class="heat-legend">
        <span>منخفض</span>
        <?php foreach (['#cde2fb','#9ec5f4','#6da7ec','#3987e5','#256abf','#184f95','#0d366b'] as $c): ?>
            <i style="background:<?= $c ?>"></i>
        <?php endforeach; ?>
        <span>مرتفع (معدل الالتزام)</span>
    </div>
    <div class="table-wrap"><table class="table heatmap">
        <thead><tr><th class="time-col">الوقت \ اليوم</th>
            <?php foreach ($days as $d): ?><th><?= e(substr($d, 5)) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($times as $t): ?>
        <tr>
            <td class="time-col"><strong><?= e($t) ?></strong></td>
            <?php foreach ($days as $d):
                $cell = $byDayTime[$t][$d] ?? null;
                $rate = $cell ? 100.0 * $cell['ok'] / $cell['n'] : null;
                [$bg, $fg] = heat_color($rate); ?>
            <td class="heat-cell" style="background:<?= $bg ?>;color:<?= $fg ?>"
                title="<?= e($d . ' ' . $t) ?><?= $rate !== null ? ' — ' . fmt_num($rate) . '% من ' . $cell['n'] . ' فحص' : ' — لا فحص' ?>">
                <?= $rate === null ? '' : fmt_num($rate, 0) ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>&#128251; الخريطة الحرارية: الإذاعات × النقاط الزمنية</h2></div>
        <?php if (!$stationNames): ?>
            <div class="empty-state small"><p>لا توجد بيانات</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table heatmap">
            <thead><tr><th>الإذاعة</th>
                <?php foreach ($times as $t): ?><th><?= e($t) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($stationNames as $sid => $sname): ?>
            <tr>
                <td><strong><?= e($sname) ?></strong></td>
                <?php foreach ($times as $t):
                    $cell = $byStationTime[$sid][$t] ?? null;
                    $rate = $cell ? 100.0 * $cell['ok'] / $cell['n'] : null;
                    [$bg, $fg] = heat_color($rate); ?>
                <td class="heat-cell" style="background:<?= $bg ?>;color:<?= $fg ?>"
                    title="<?= e($sname . ' — ' . $t) ?><?= $rate !== null ? ': ' . fmt_num($rate) . '%' : ': لا فحص' ?>">
                    <?= $rate === null ? '' : fmt_num($rate, 0) ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>&#128202; توزيع درجات التقييم (1–10)</h2></div>
        <div class="chart-box"><canvas id="chartDist"></canvas></div>
        <div class="legend" style="margin-top:8px">
            <span class="legend-item"><i class="cell-dot" style="background:#2a78d6"></i> فني</span>
            <span class="legend-item"><i class="cell-dot" style="background:#eb6834"></i> محتوى</span>
        </div>
    </div>
</div>

<script src="assets/js/chart.umd.min.js"></script>
<script>
window.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('chartDist');
    if (!el || !window.Chart) return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: [1,2,3,4,5,6,7,8,9,10],
            datasets: [
                { label: 'فني', data: <?= json_encode(array_values($dist['technical'])) ?>,
                  backgroundColor: '#2a78d6', borderRadius: {topLeft:4, topRight:4}, maxBarThickness: 26 },
                { label: 'محتوى', data: <?= json_encode(array_values($dist['content'])) ?>,
                  backgroundColor: '#eb6834', borderRadius: {topLeft:4, topRight:4}, maxBarThickness: 26 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { rtl: true } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#898781' }, title: { display: true, text: 'الدرجة', color: '#898781' } },
                y: { grid: { color: '#e1e0d9' }, border: { display: false }, ticks: { color: '#898781', precision: 0 } }
            }
        }
    });
});
</script>
<?php
layout_footer();
