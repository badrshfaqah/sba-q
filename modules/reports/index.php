<?php
/** موديول التقارير: برنامج / التزام / مستخدمون — مع فلترة زمنية */
if (!defined('SBA')) exit;

$type = input('type', 'program');
if (!in_array($type, ['program', 'compliance', 'users', 'stations'], true)) $type = 'program';

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
                    <option value="stations"   <?= $type === 'stations' ? 'selected' : '' ?>>مقارنة الإذاعات (شامل)</option>
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
            <div class="form-group">
                <label>مقارنة من (اختياري)</label>
                <input type="date" name="from2" value="<?= e(input('from2')) ?>">
            </div>
            <div class="form-group">
                <label>مقارنة إلى</label>
                <input type="date" name="to2" value="<?= e(input('to2')) ?>">
                <small class="muted">اتركهما فارغين للمقارنة التلقائية بالفترة السابقة المكافئة</small>
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

<?php /* ================= تقرير الالتزام (فترات + مقارنة + تلميحات ذكية) ================= */
elseif ($type === 'compliance'):
    /* فترة المقارنة: مخصصة أو تلقائية (الفترة السابقة المكافئة) */
    $from2 = input('from2');
    $to2   = input('to2');
    $autoCompare = !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from2) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to2);
    $periodDays = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    if ($autoCompare) {
        $to2   = date('Y-m-d', strtotime($from . ' -1 day'));
        $from2 = date('Y-m-d', strtotime($to2 . ' -' . ($periodDays - 1) . ' days'));
    }

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

    $byStation  = compliance_by_station($from, $to);
    $byStation2 = [];
    foreach (compliance_by_station($from2, $to2) as $r) $byStation2[$r['id']] = $r;
    $overall  = compliance_rate($from, $to);
    $overall2 = compliance_rate($from2, $to2);

    /* التفكيك حسب النقطة الزمنية (الفترتان) */
    $slotQ = 'SELECT DATE_FORMAT(check_at, "%H:%i") AS slot, COUNT(*) AS total, SUM(status) AS ok
              FROM ' . tbl('compliance') . ' WHERE check_at >= ? AND check_at <= ? GROUP BY slot ORDER BY slot';
    $st = db()->prepare($slotQ); $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $slots = $st->fetchAll();
    $st = db()->prepare($slotQ); $st->execute([$from2 . ' 00:00:00', $to2 . ' 23:59:59']);
    $slots2 = [];
    foreach ($st->fetchAll() as $r) $slots2[$r['slot']] = 100.0 * (int)$r['ok'] / max(1, (int)$r['total']);

    /* حسب يوم الأسبوع */
    $st = db()->prepare('SELECT DAYOFWEEK(check_at) AS dw, COUNT(*) AS total, SUM(status) AS ok
        FROM ' . tbl('compliance') . ' WHERE check_at >= ? AND check_at <= ? GROUP BY dw');
    $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $dowNames = [1 => 'الأحد', 2 => 'الاثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت'];
    $weekdays = [];
    foreach ($st->fetchAll() as $r) {
        $weekdays[] = ['name' => $dowNames[(int)$r['dw']], 'rate' => 100.0 * (int)$r['ok'] / max(1, (int)$r['total']), 'n' => (int)$r['total']];
    }
    usort($weekdays, fn($x, $y) => $x['rate'] <=> $y['rate']);

    /* السلسلة اليومية للفترتين (للرسم المقارن) */
    $dailySeries = function (string $f, string $t) {
        $st = db()->prepare('SELECT DATE(check_at) AS d, COUNT(*) AS total, SUM(status) AS ok
            FROM ' . tbl('compliance') . ' WHERE check_at >= ? AND check_at <= ? GROUP BY d');
        $st->execute([$f . ' 00:00:00', $t . ' 23:59:59']);
        $map = [];
        foreach ($st->fetchAll() as $r) $map[$r['d']] = round(100.0 * (int)$r['ok'] / max(1, (int)$r['total']), 1);
        $out = [];
        for ($d = strtotime($f); $d <= strtotime($t); $d += 86400) {
            $key = date('Y-m-d', $d);
            $out[] = ['date' => $key, 'rate' => $map[$key] ?? null];
        }
        return $out;
    };
    $seriesA = $dailySeries($from, $to);
    $seriesB = $dailySeries($from2, $to2);
    $chartLen = max(count($seriesA), count($seriesB));

    /* ---------- تلميحات ذكية خاصة بالالتزام ---------- */
    $hints = [];
    // أنماط الخلل المتكررة: إذاعة × نقطة زمنية بنسبة فشل عالية
    $st = db()->prepare('SELECT s.name, DATE_FORMAT(c.check_at, "%H:%i") AS slot,
            COUNT(*) AS n, SUM(c.status) AS ok
        FROM ' . tbl('compliance') . ' c JOIN ' . tbl('stations') . ' s ON s.id = c.station_id
        WHERE c.check_at >= ? AND c.check_at <= ?
        GROUP BY c.station_id, slot HAVING n >= 3 AND (SUM(c.status) / COUNT(*)) < 0.5
        ORDER BY (SUM(c.status) / COUNT(*)) ASC LIMIT 5');
    $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    foreach ($st->fetchAll() as $r) {
        $rate = 100.0 * (int)$r['ok'] / (int)$r['n'];
        $hints[] = ['danger', 'نمط خلل متكرر: ' . $r['name'] . ' عند ' . $r['slot'] .
            ' ملتزمة ' . fmt_num($rate) . '% فقط من ' . (int)$r['n'] . ' فحوصات — الخلل منهجي وليس عارضاً'];
    }
    // سلاسل الفشل المتواصلة الحالية
    $st = db()->prepare('SELECT c.station_id, s.name, c.status
        FROM ' . tbl('compliance') . ' c JOIN ' . tbl('stations') . ' s ON s.id = c.station_id
        WHERE c.check_at >= ? AND c.check_at <= ? ORDER BY c.station_id, c.check_at DESC');
    $st->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $streaks = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['station_id'];
        if (!isset($streaks[$sid])) $streaks[$sid] = ['name' => $r['name'], 'streak' => 0, 'stopped' => false];
        if ($streaks[$sid]['stopped']) continue;
        if ((int)$r['status'] === 0) $streaks[$sid]['streak']++;
        else $streaks[$sid]['stopped'] = true;
    }
    foreach ($streaks as $s) {
        if ($s['streak'] >= 3) {
            $hints[] = ['danger', $s['name'] . ': آخر ' . $s['streak'] . ' فحوصات متتالية كلها غير ملتزمة — تحتاج تدخلاً فورياً'];
        }
    }
    // تغيّر الإذاعات بين الفترتين
    foreach ($byStation as $r) {
        $prev = $byStation2[$r['id']]['rate'] ?? null;
        if ($r['rate'] !== null && $prev !== null) {
            $d = $r['rate'] - $prev;
            if ($d <= -10) $hints[] = ['warning', $r['name'] . ' تراجعت ' . fmt_num(abs($d)) . ' نقطة عن فترة المقارنة (' . fmt_num($prev) . '% ← ' . fmt_num($r['rate']) . '%)'];
            elseif ($d >= 10) $hints[] = ['success', $r['name'] . ' تحسنت ' . fmt_num($d) . ' نقطة عن فترة المقارنة (' . fmt_num($prev) . '% ← ' . fmt_num($r['rate']) . '%)'];
        }
    }
    // أفضل وأسوأ يوم أسبوع
    if (count($weekdays) >= 2) {
        $worstD = $weekdays[0]; $bestD = $weekdays[count($weekdays) - 1];
        if ($worstD['rate'] < 75) {
            $hints[] = ['warning', 'يوم ' . $worstD['name'] . ' هو الأضعف أسبوعياً (' . fmt_num($worstD['rate']) . '%) — راجع جدولة الورديات فيه'];
        }
        if ($bestD['rate'] - $worstD['rate'] >= 15) {
            $hints[] = ['info', 'فجوة ' . fmt_num($bestD['rate'] - $worstD['rate']) . ' نقطة بين أفضل الأيام (' . $bestD['name'] . ') وأضعفها (' . $worstD['name'] . ')'];
        }
    }
    if ($overall !== null && $overall2 !== null && !$hints) {
        $hints[] = ['success', 'لا أنماط خلل متكررة في هذه الفترة — الأداء مستقر'];
    }
    $sevOrder = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
    usort($hints, fn($x, $y) => $sevOrder[$x[0]] <=> $sevOrder[$y[0]]);
    $delta = ($overall !== null && $overall2 !== null) ? $overall - $overall2 : null;
?>
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">معدل الفترة الحالية</div>
        <div class="kpi-value <?= $overall !== null && $overall < 80 ? 'kpi-bad' : 'kpi-good' ?>">
            <?= $overall === null ? '—' : fmt_num($overall) . '%' ?>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">فترة المقارنة (<?= e($from2) ?> — <?= e($to2) ?>)</div>
        <div class="kpi-value"><?= $overall2 === null ? '—' : fmt_num($overall2) . '%' ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">التغيّر</div>
        <div class="kpi-value">
            <?php if ($delta === null): ?>—
            <?php elseif ($delta >= 1): ?><span class="trend up">&#9650; +<?= fmt_num($delta) ?></span>
            <?php elseif ($delta <= -1): ?><span class="trend down">&#9660; −<?= fmt_num(abs($delta)) ?></span>
            <?php else: ?><span class="trend flat">مستقر</span><?php endif; ?>
        </div>
    </div>
</div>

<div class="card insights-card">
    <div class="card-header"><h2>&#129302; تلميحات ذكية عن الالتزام</h2></div>
    <?php if (!$hints): ?>
        <div class="empty-state small"><p>لا توجد بيانات كافية للتحليل</p></div>
    <?php else: foreach ($hints as [$sev, $text]): ?>
        <div class="insight insight-<?= $sev ?>">
            <span class="insight-icon"><?= ['danger' => '&#128680;', 'warning' => '&#9888;&#65039;', 'info' => '&#128161;', 'success' => '&#127942;'][$sev] ?></span>
            <span><?= e($text) ?></span>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2>الاتجاه اليومي: الفترة الحالية مقابل فترة المقارنة</h2></div>
    <div class="chart-box"><canvas id="chartCompare"></canvas></div>
    <div class="legend" style="margin-top:8px">
        <span class="legend-item"><i class="cell-dot" style="background:#2a78d6"></i> الحالية (<?= e($from) ?> — <?= e($to) ?>)</span>
        <span class="legend-item"><i class="cell-dot" style="background:#eb6834"></i> المقارنة (<?= e($from2) ?> — <?= e($to2) ?>)</span>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>مقارنة الإذاعات بين الفترتين</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>الإذاعة</th><th>الحالية</th><th>المقارنة</th><th>التغيّر</th></tr></thead>
            <tbody>
            <?php foreach ($byStation as $s):
                $prev = $byStation2[$s['id']]['rate'] ?? null;
                $d = ($s['rate'] !== null && $prev !== null) ? $s['rate'] - $prev : null; ?>
            <tr>
                <td><strong><?= e($s['name']) ?></strong></td>
                <td><?= $s['rate'] === null ? '—' : '<span class="badge ' . ($s['rate'] >= 80 ? 'badge-success' : 'badge-danger') . '">' . fmt_num($s['rate']) . '%</span>' ?></td>
                <td><?= $prev === null ? '—' : fmt_num($prev) . '%' ?></td>
                <td>
                    <?php if ($d === null): ?>—
                    <?php elseif ($d >= 1): ?><span class="trend up">&#9650; <?= fmt_num($d) ?></span>
                    <?php elseif ($d <= -1): ?><span class="trend down">&#9660; <?= fmt_num(abs($d)) ?></span>
                    <?php else: ?><span class="trend flat">&#8212;</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <div class="card-header"><h2>الالتزام حسب النقطة الزمنية</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>الوقت</th><th>الفحوصات</th><th>الحالية</th><th>المقارنة</th><th>التغيّر</th></tr></thead>
            <tbody>
            <?php foreach ($slots as $sl):
                $rate = 100.0 * (int)$sl['ok'] / max(1, (int)$sl['total']);
                $prev = $slots2[$sl['slot']] ?? null;
                $d = $prev !== null ? $rate - $prev : null; ?>
            <tr>
                <td class="time-col"><strong><?= e($sl['slot']) ?></strong></td>
                <td><?= (int)$sl['total'] ?></td>
                <td><span class="badge <?= $rate >= 80 ? 'badge-success' : 'badge-danger' ?>"><?= fmt_num($rate) ?>%</span></td>
                <td><?= $prev === null ? '—' : fmt_num($prev) . '%' ?></td>
                <td>
                    <?php if ($d === null): ?>—
                    <?php elseif ($d >= 1): ?><span class="trend up">&#9650; <?= fmt_num($d) ?></span>
                    <?php elseif ($d <= -1): ?><span class="trend down">&#9660; <?= fmt_num(abs($d)) ?></span>
                    <?php else: ?><span class="trend flat">&#8212;</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
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
        <div class="card-header"><h2>الالتزام حسب يوم الأسبوع</h2></div>
        <?php if (!$weekdays): ?><div class="empty-state small"><p>لا توجد بيانات</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>اليوم</th><th>الفحوصات</th><th>المعدل</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($weekdays) as $wd): ?>
            <tr>
                <td><strong><?= e($wd['name']) ?></strong></td>
                <td><?= (int)$wd['n'] ?></td>
                <td><span class="badge <?= $wd['rate'] >= 80 ? 'badge-success' : 'badge-danger' ?>"><?= fmt_num($wd['rate']) ?>%</span></td>
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
    var el = document.getElementById('chartCompare');
    if (!el || !window.Chart) return;
    var len = <?= (int)$chartLen ?>;
    var labels = Array.from({length: len}, function (_, i) { return 'يوم ' + (i + 1); });
    var a = <?= json_encode(array_map(fn($x) => $x['rate'], $seriesA)) ?>;
    var b = <?= json_encode(array_map(fn($x) => $x['rate'], $seriesB)) ?>;
    var datesA = <?= json_encode(array_map(fn($x) => $x['date'], $seriesA)) ?>;
    var datesB = <?= json_encode(array_map(fn($x) => $x['date'], $seriesB)) ?>;
    new Chart(el, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'الحالية', data: a, borderColor: '#2a78d6', backgroundColor: '#2a78d6',
                  borderWidth: 2, pointRadius: 3, spanGaps: true, tension: 0.25 },
                { label: 'المقارنة', data: b, borderColor: '#eb6834', backgroundColor: '#eb6834',
                  borderWidth: 2, pointRadius: 3, borderDash: [6, 4], spanGaps: true, tension: 0.25 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    rtl: true,
                    callbacks: {
                        title: function (items) {
                            var i = items[0].dataIndex;
                            return items[0].datasetIndex === 0 ? (datesA[i] || '') : (datesB[i] || '');
                        },
                        label: function (ctx) {
                            return (ctx.datasetIndex === 0 ? 'الحالية: ' : 'المقارنة: ') +
                                   (ctx.parsed.y === null ? 'لا بيانات' : ctx.parsed.y + '%');
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#898781', maxTicksLimit: 12 } },
                y: { min: 0, max: 100, grid: { color: '#e1e0d9' }, border: { display: false }, ticks: { color: '#898781' } }
            }
        }
    });
});
</script>

<?php /* ================= مقارنة الإذاعات ================= */
elseif ($type === 'stations'):
    $quality = [];
    foreach (episode_scores($from, $to) as $r) {
        $sid = $r['station_id'];
        $quality[$sid] ??= ['name' => $r['station_name'], 'tsum' => 0, 'tn' => 0, 'csum' => 0, 'cn' => 0, 'fsum' => 0, 'fn' => 0, 'eps' => 0];
        $quality[$sid]['eps']++;
        if ($r['tavg'] !== null)  { $quality[$sid]['tsum'] += $r['tavg']; $quality[$sid]['tn']++; }
        if ($r['cavg'] !== null)  { $quality[$sid]['csum'] += $r['cavg']; $quality[$sid]['cn']++; }
        if ($r['final'] !== null) { $quality[$sid]['fsum'] += $r['final']; $quality[$sid]['fn']++; }
    }
    $compRates = [];
    foreach (compliance_by_station($from, $to) as $r) $compRates[$r['id']] = $r;
    $stationsAll = db()->query('SELECT id, name FROM ' . tbl('stations') . ' WHERE active=1 ORDER BY name')->fetchAll();

    $rows = [];
    foreach ($stationsAll as $s) {
        $q = $quality[$s['id']] ?? null;
        $c = $compRates[$s['id']] ?? null;
        $t = $q && $q['tn'] ? $q['tsum'] / $q['tn'] : null;
        $ct = $q && $q['cn'] ? $q['csum'] / $q['cn'] : null;
        $f = $q && $q['fn'] ? $q['fsum'] / $q['fn'] : null;
        $cr = $c['rate'] ?? null;
        $spiVal = null;
        if ($f !== null || $cr !== null) {
            $spiVal = $f === null ? $cr : ($cr === null ? $f * 10 : ($f * 10) * 0.6 + $cr * 0.4);
        }
        $rows[] = ['name' => $s['name'], 'eps' => $q['eps'] ?? 0, 't' => $t, 'c' => $ct, 'f' => $f,
                   'rate' => $cr, 'checks' => (int)($c['total'] ?? 0), 'spi' => $spiVal];
    }
    usort($rows, fn($x, $y) => ($y['spi'] ?? -1) <=> ($x['spi'] ?? -1));
?>
<div class="card">
    <div class="card-header"><h2>مقارنة الإذاعات الشاملة</h2>
        <span class="muted">المؤشر المركب = جودة نهائية 60% + التزام 40%</span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th>#</th><th>الإذاعة</th><th>الحلقات</th><th>فني</th><th>محتوى</th><th>الجودة النهائية</th>
            <th>فحوصات الالتزام</th><th>معدل الالتزام</th><th>المؤشر المركب</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td><?= (int)$r['eps'] ?></td>
            <td><?= fmt_num($r['t']) ?></td>
            <td><?= fmt_num($r['c']) ?></td>
            <td><?= $r['f'] === null ? '—' : '<span class="badge ' . ($r['f'] >= 8 ? 'badge-success' : ($r['f'] >= 6 ? 'badge-warning' : 'badge-danger')) . '">' . fmt_num($r['f']) . '</span>' ?></td>
            <td><?= $r['checks'] ?></td>
            <td><?= $r['rate'] === null ? '—' : '<span class="badge ' . ($r['rate'] >= 80 ? 'badge-success' : 'badge-danger') . '">' . fmt_num($r['rate']) . '%</span>' ?></td>
            <td><strong><?= fmt_num($r['spi']) ?></strong> <span class="muted">/ 100</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php /* ================= تقرير المستخدمين ================= */
else:
    $st = db()->prepare('SELECT u.id, u.name, u.role,
            COUNT(ev.id) AS eval_count,
            MAX(ev.score) AS max_score,
            MIN(ev.score) AS min_score,
            AVG(ev.score) AS avg_score,
            AVG(TIMESTAMPDIFF(HOUR, CONCAT(e.air_date, " ", e.air_time), ev.created_at)) AS avg_delay_h,
            SUM(CASE WHEN ev.created_at > DATE_ADD(CONCAT(e.air_date, " ", e.air_time), INTERVAL 3 DAY) THEN 1 ELSE 0 END) AS late_count
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
            <th>متوسط سرعة الإنجاز</th><th>تقييمات متأخرة (+3 أيام)</th>
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
            <td>
                <?php $h = $u['avg_delay_h'] !== null ? max(0, (float)$u['avg_delay_h']) : null;
                if ($h === null): ?>—
                <?php elseif ($h < 24): ?><span class="badge badge-success">خلال <?= fmt_num($h, 0) ?> ساعة</span>
                <?php elseif ($h < 72): ?><span class="badge badge-warning"><?= fmt_num($h / 24, 1) ?> يوم</span>
                <?php else: ?><span class="badge badge-danger"><?= fmt_num($h / 24, 1) ?> يوم</span>
                <?php endif; ?>
            </td>
            <td><?= (int)$u['late_count'] ? '<span class="badge badge-danger">' . (int)$u['late_count'] . '</span>' : '<span class="badge badge-success">0</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif;

layout_footer();
