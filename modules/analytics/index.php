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

/* ---------- تدرّج أحمر: كلما اشتدّ الحمرة اشتدّ الخلل (انخفض الالتزام) ---------- */
function heat_color(?float $rate): array
{
    // [خلفية، لون نص] — فاتح = التزام مرتفع، غامق = خلل شديد
    if ($rate === null) return ['transparent', 'var(--muted)'];
    if ($rate >= 95) return ['#fdf3f3', '#7a1f1f'];
    if ($rate >= 85) return ['#f8d7d7', '#7a1f1f'];
    if ($rate >= 75) return ['#f0aeae', '#5c1414'];
    if ($rate >= 60) return ['#e57f7f', '#ffffff'];
    if ($rate >= 45) return ['#d34f4f', '#ffffff'];
    if ($rate >= 30) return ['#b23030', '#ffffff'];
    return ['#7a1f1f', '#ffffff'];
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

/* ---------- الفترة السابقة المكافئة (لاتجاهات التحسن/التراجع) ---------- */
$periodDays = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
$prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($periodDays - 1) . ' days'));

$prevQuality = [];
foreach (station_rankings($prevFrom, $prevTo) as $r) $prevQuality[$r['station_id']] = $r['avg'];
$prevComp = [];
foreach (compliance_by_station($prevFrom, $prevTo) as $r) $prevComp[$r['id']] = $r['rate'];

/* ---------- مؤشر الأداء المركب SPI = جودة 60% + التزام 40% ---------- */
function spi(?float $q, ?float $c): ?float
{
    if ($q === null && $c === null) return null;
    if ($q === null) return $c;
    if ($c === null) return $q * 10;
    return ($q * 10) * 0.6 + $c * 0.4;
}

/* ---------- صرامة المقيمين: انحراف كل مقيم عن زملائه على الحلقات المشتركة ---------- */
$st = db()->prepare('SELECT ev.episode_id, ev.type, ev.user_id, ev.score, u.name
    FROM ' . tbl('evaluations') . ' ev
    JOIN ' . tbl('users') . ' u ON u.id = ev.user_id
    JOIN ' . tbl('episodes') . ' e ON e.id = ev.episode_id
    WHERE e.air_date >= ? AND e.air_date <= ?');
$st->execute([$from, $to]);
$groups = [];
foreach ($st->fetchAll() as $r) {
    $groups[$r['episode_id'] . '|' . $r['type']][] = $r;
}
$bias = []; // user_id => ['name', 'sum', 'n']
foreach ($groups as $g) {
    if (count($g) < 2) continue;
    $total = array_sum(array_column($g, 'score'));
    foreach ($g as $r) {
        $othersAvg = ($total - $r['score']) / (count($g) - 1);
        $uid = (int)$r['user_id'];
        $bias[$uid] ??= ['name' => $r['name'], 'sum' => 0.0, 'n' => 0];
        $bias[$uid]['sum'] += (float)$r['score'] - $othersAvg;
        $bias[$uid]['n']++;
    }
}
foreach ($bias as &$b) $b['avg'] = $b['sum'] / $b['n'];
unset($b);
uasort($bias, fn($x, $y) => abs($y['avg']) <=> abs($x['avg']));

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

/* ---------- ترتيب المؤشر المركب مع الاتجاه ---------- */
$spiRows = [];
foreach ($allStations as $s) {
    $q = $qualityByStation[$s['id']]['avg'] ?? null;
    $c = $compByStation[$s['id']]['rate'] ?? null;
    $now = spi($q, $c);
    if ($now === null) continue;
    $prev = spi($prevQuality[$s['id']] ?? null, $prevComp[$s['id']] ?? null);
    $spiRows[] = ['name' => $s['name'], 'spi' => $now, 'delta' => $prev !== null ? $now - $prev : null];
}
usort($spiRows, fn($x, $y) => $y['spi'] <=> $x['spi']);

/* ---------- متوسط فني/محتوى لكل إذاعة (لكشف الفجوات) ---------- */
$gapByStation = [];
foreach ($scores as $srow) {
    $sid = $srow['station_id'];
    $gapByStation[$sid] ??= ['name' => $srow['station_name'], 'tsum' => 0, 'tn' => 0, 'csum' => 0, 'cn' => 0];
    if ($srow['tavg'] !== null) { $gapByStation[$sid]['tsum'] += $srow['tavg']; $gapByStation[$sid]['tn']++; }
    if ($srow['cavg'] !== null) { $gapByStation[$sid]['csum'] += $srow['cavg']; $gapByStation[$sid]['cn']++; }
}

/* ---------- المهام المتأخرة (3 أيام فأكثر) ---------- */
$st = db()->prepare('SELECT COUNT(*) FROM ' . tbl('episode_evaluators') . ' a
    JOIN ' . tbl('episodes') . ' e ON e.id = a.episode_id
    LEFT JOIN ' . tbl('evaluations') . ' ev
           ON ev.episode_id = a.episode_id AND ev.user_id = a.user_id AND ev.type = a.type
    WHERE ev.id IS NULL AND e.air_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
      AND e.air_date >= ? AND e.air_date <= ?');
$st->execute([$from, $to]);
$overdueCount = (int)$st->fetchColumn();

/* ---------- محرك الرؤى التلقائية ---------- */
$insights = []; // [severity: danger|warning|info|success, text]
if ($worstTime && $worstTime[1] < 75) {
    $insights[] = ['danger', 'النقطة الزمنية ' . $worstTime[0] . ' هي الأضعف التزاماً (' . fmt_num($worstTime[1]) . '%) — راجع تشغيل هذه الفترة تحديداً'];
}
foreach ($allStations as $s) {
    $sid = $s['id'];
    $c = $compByStation[$sid]['rate'] ?? null;
    $pc = $prevComp[$sid] ?? null;
    if ($c !== null && $pc !== null) {
        $d = $c - $pc;
        if ($d <= -10) $insights[] = ['danger', 'التزام ' . $s['name'] . ' تراجع من ' . fmt_num($pc) . '% إلى ' . fmt_num($c) . '% مقارنة بالفترة السابقة'];
        elseif ($d >= 10) $insights[] = ['success', 'التزام ' . $s['name'] . ' تحسّن من ' . fmt_num($pc) . '% إلى ' . fmt_num($c) . '% مقارنة بالفترة السابقة'];
    }
    $q = $qualityByStation[$sid]['avg'] ?? null;
    $pq = $prevQuality[$sid] ?? null;
    if ($q !== null && $pq !== null) {
        $d = $q - $pq;
        if ($d <= -1.0) $insights[] = ['warning', 'جودة ' . $s['name'] . ' انخفضت ' . fmt_num(abs($d)) . ' نقطة عن الفترة السابقة (' . fmt_num($pq) . ' ← ' . fmt_num($q) . ')'];
        elseif ($d >= 1.0) $insights[] = ['success', 'جودة ' . $s['name'] . ' ارتفعت ' . fmt_num($d) . ' نقطة عن الفترة السابقة'];
    }
}
foreach ($gapByStation as $g) {
    if ($g['tn'] && $g['cn']) {
        $t = $g['tsum'] / $g['tn']; $c = $g['csum'] / $g['cn'];
        $gap = $t - $c;
        if (abs($gap) >= 1.5) {
            $insights[] = ['info', 'فجوة واضحة في ' . $g['name'] . ': ' .
                ($gap > 0 ? 'الفني (' . fmt_num($t) . ') يتفوق على المحتوى (' . fmt_num($c) . ') — المشكلة تحريرية لا هندسية'
                          : 'المحتوى (' . fmt_num($c) . ') يتفوق على الفني (' . fmt_num($t) . ') — المشكلة هندسية لا تحريرية')];
        }
    }
}
foreach ($bias as $b) {
    if ($b['n'] >= 3 && abs($b['avg']) >= 1.0) {
        $insights[] = ['warning', 'المقيم ' . $b['name'] . ' ' .
            ($b['avg'] > 0 ? 'أكثر تساهلاً' : 'أكثر تشدداً') . ' من زملائه بمتوسط ' .
            fmt_num(abs($b['avg'])) . ' نقطة على نفس الحلقات (' . $b['n'] . ' مقارنة)'];
    }
}
if ($overdueCount > 0) {
    $insights[] = ['warning', "هناك $overdueCount مهمة تقييم متأخرة أكثر من 3 أيام — راجع شاشة متابعة الموظفين"];
}
if ($quadrants['critical']) {
    $insights[] = ['danger', 'إذاعات في ربع التدخل العاجل: ' . implode('، ', array_column($quadrants['critical'], 'name'))];
}
if ($spiRows) {
    $insights[] = ['success', $spiRows[0]['name'] . ' تتصدر مؤشر الأداء المركب (' . fmt_num($spiRows[0]['spi']) . ' من 100)'];
}
$sevOrder = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
usort($insights, fn($x, $y) => $sevOrder[$x[0]] <=> $sevOrder[$y[0]]);

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

<div class="card insights-card">
    <div class="card-header"><h2>&#129302; الرؤى التلقائية</h2>
        <span class="muted">مقارنةً بالفترة السابقة المكافئة (<?= e($prevFrom) ?> — <?= e($prevTo) ?>)</span></div>
    <?php if (!$insights): ?>
        <div class="empty-state small"><p>لا توجد رؤى بعد — تتولد تلقائياً كلما زادت البيانات</p></div>
    <?php else: ?>
        <?php foreach ($insights as [$sev, $text]): ?>
        <div class="insight insight-<?= $sev ?>">
            <span class="insight-icon"><?= ['danger' => '&#128680;', 'warning' => '&#9888;&#65039;', 'info' => '&#128161;', 'success' => '&#127942;'][$sev] ?></span>
            <span><?= e($text) ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>&#129351; مؤشر الأداء المركب (SPI)</h2>
            <span class="muted">جودة 60% + التزام 40%</span></div>
        <?php if (!$spiRows): ?><div class="empty-state small"><p>لا توجد بيانات كافية</p></div>
        <?php else: foreach ($spiRows as $i => $r): ?>
        <div class="spi-row">
            <div class="spi-head">
                <strong><?= ($i === 0 ? '&#129351; ' : ($i === 1 ? '&#129352; ' : ($i === 2 ? '&#129353; ' : ''))) . e($r['name']) ?></strong>
                <span class="spi-val">
                    <?= fmt_num($r['spi']) ?>
                    <?php if ($r['delta'] !== null): ?>
                        <?php if ($r['delta'] >= 1): ?><span class="trend up">&#9650; <?= fmt_num($r['delta']) ?></span>
                        <?php elseif ($r['delta'] <= -1): ?><span class="trend down">&#9660; <?= fmt_num(abs($r['delta'])) ?></span>
                        <?php else: ?><span class="trend flat">&#8212;</span><?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="progress"><div class="progress-fill <?= $r['spi'] >= 80 ? 'full' : ($r['spi'] < 60 ? 'low' : '') ?>"
                 style="width:<?= round($r['spi']) ?>%"></div></div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>&#9878;&#65039; عدالة التقييم (صرامة المقيمين)</h2></div>
        <p class="muted">انحراف كل مقيم عن متوسط زملائه على الحلقات المشتركة نفسها —
           موجب = أكثر تساهلاً، سالب = أكثر تشدداً.</p>
        <?php if (!$bias): ?><div class="empty-state small"><p>يتطلب مقيّمَين فأكثر على نفس الحلقة</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>المقيم</th><th>الانحراف</th><th>المقارنات</th><th>القراءة</th></tr></thead>
            <tbody>
            <?php foreach ($bias as $b): ?>
            <tr>
                <td><strong><?= e($b['name']) ?></strong></td>
                <td dir="ltr" style="text-align:right"><?= ($b['avg'] >= 0 ? '+' : '−') . fmt_num(abs($b['avg']), 2) ?></td>
                <td><?= (int)$b['n'] ?></td>
                <td>
                    <?php if (abs($b['avg']) < 0.5): ?><span class="badge badge-success">متوازن</span>
                    <?php elseif ($b['avg'] >= 1): ?><span class="badge badge-warning">متساهل</span>
                    <?php elseif ($b['avg'] <= -1): ?><span class="badge badge-warning">متشدد</span>
                    <?php else: ?><span class="badge badge-muted">طبيعي</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
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
        <span>التزام مرتفع</span>
        <?php foreach (['#fdf3f3','#f8d7d7','#f0aeae','#e57f7f','#d34f4f','#b23030','#7a1f1f'] as $c): ?>
            <i style="background:<?= $c ?>"></i>
        <?php endforeach; ?>
        <span>خلل شديد</span>
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
