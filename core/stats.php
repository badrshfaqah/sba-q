<?php
/** دوال حساب الجودة والالتزام (مشتركة بين لوحة القيادة والتقارير) */
if (!defined('SBA')) exit;

/** الأوزان الحالية */
function quality_weights(): array
{
    $wt = (float)setting('weight_technical', 50);
    $wc = (float)setting('weight_content', 50);
    $sum = $wt + $wc;
    if ($sum <= 0) { $wt = $wc = 50; $sum = 100; }
    return [$wt / $sum, $wc / $sum];
}

/**
 * درجات الحلقات: متوسط فني + متوسط محتوى + النتيجة النهائية
 * Final Score = (Technical Avg × Weight) + (Content Avg × Weight)
 * كل الدرجات من 10
 */
function episode_scores(?string $from = null, ?string $to = null, int $programId = 0, int $stationId = 0): array
{
    [$wt, $wc] = quality_weights();
    $sql = 'SELECT e.id, e.title, e.air_date, e.air_time, e.program_id,
                   p.name AS program_name, p.station_id, s.name AS station_name,
                   AVG(CASE WHEN ev.type = "technical" THEN ev.score END) AS tavg,
                   AVG(CASE WHEN ev.type = "content"   THEN ev.score END) AS cavg,
                   COUNT(ev.id) AS eval_count
            FROM ' . tbl('episodes') . ' e
            JOIN ' . tbl('programs') . ' p ON p.id = e.program_id
            JOIN ' . tbl('stations') . ' s ON s.id = p.station_id
            LEFT JOIN ' . tbl('evaluations') . ' ev ON ev.episode_id = e.id
            WHERE 1=1';
    $params = [];
    if ($from)      { $sql .= ' AND e.air_date >= ?'; $params[] = $from; }
    if ($to)        { $sql .= ' AND e.air_date <= ?'; $params[] = $to; }
    if ($programId) { $sql .= ' AND e.program_id = ?'; $params[] = $programId; }
    if ($stationId) { $sql .= ' AND p.station_id = ?'; $params[] = $stationId; }
    $sql .= ' GROUP BY e.id ORDER BY e.air_date DESC, e.air_time DESC';

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $t = $r['tavg'] !== null ? (float)$r['tavg'] : null;
        $c = $r['cavg'] !== null ? (float)$r['cavg'] : null;
        if ($t !== null && $c !== null) {
            $r['final'] = $t * $wt + $c * $wc;
        } elseif ($t !== null) {
            $r['final'] = $t;
        } elseif ($c !== null) {
            $r['final'] = $c;
        } else {
            $r['final'] = null;
        }
    }
    return $rows;
}

/** ترتيب البرامج حسب النتيجة النهائية */
function program_rankings(?string $from = null, ?string $to = null): array
{
    $byProgram = [];
    foreach (episode_scores($from, $to) as $r) {
        if ($r['final'] === null) continue;
        $pid = $r['program_id'];
        if (!isset($byProgram[$pid])) {
            $byProgram[$pid] = [
                'program_id' => $pid, 'name' => $r['program_name'],
                'station' => $r['station_name'], 'sum' => 0.0, 'count' => 0,
            ];
        }
        $byProgram[$pid]['sum'] += $r['final'];
        $byProgram[$pid]['count']++;
    }
    foreach ($byProgram as &$p) {
        $p['avg'] = $p['sum'] / $p['count'];
    }
    usort($byProgram, fn($a, $b) => $b['avg'] <=> $a['avg']);
    return array_values($byProgram);
}

/** ترتيب الإذاعات حسب متوسط الجودة */
function station_rankings(?string $from = null, ?string $to = null): array
{
    $byStation = [];
    foreach (episode_scores($from, $to) as $r) {
        if ($r['final'] === null) continue;
        $sid = $r['station_id'];
        if (!isset($byStation[$sid])) {
            $byStation[$sid] = ['station_id' => $sid, 'name' => $r['station_name'], 'sum' => 0.0, 'count' => 0];
        }
        $byStation[$sid]['sum'] += $r['final'];
        $byStation[$sid]['count']++;
    }
    foreach ($byStation as &$s) {
        $s['avg'] = $s['sum'] / $s['count'];
    }
    usort($byStation, fn($a, $b) => $b['avg'] <=> $a['avg']);
    return array_values($byStation);
}

/** معدل الالتزام العام في فترة (نسبة مئوية) */
function compliance_rate(?string $from = null, ?string $to = null, int $stationId = 0): ?float
{
    $sql = 'SELECT COUNT(*) AS total, SUM(status) AS ok FROM ' . tbl('compliance') . ' WHERE 1=1';
    $params = [];
    if ($from)      { $sql .= ' AND check_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to)        { $sql .= ' AND check_at <= ?'; $params[] = $to . ' 23:59:59'; }
    if ($stationId) { $sql .= ' AND station_id = ?'; $params[] = $stationId; }
    $st = db()->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    if (!$r || (int)$r['total'] === 0) return null;
    return 100.0 * (int)$r['ok'] / (int)$r['total'];
}

/** معدل الالتزام لكل إذاعة في فترة */
function compliance_by_station(?string $from = null, ?string $to = null): array
{
    $sql = 'SELECT s.id, s.name, COUNT(c.id) AS total, COALESCE(SUM(c.status),0) AS ok
            FROM ' . tbl('stations') . ' s
            LEFT JOIN ' . tbl('compliance') . ' c ON c.station_id = s.id';
    $params = [];
    $conds = [];
    if ($from) { $conds[] = 'c.check_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to)   { $conds[] = 'c.check_at <= ?'; $params[] = $to . ' 23:59:59'; }
    if ($conds) $sql .= ' AND ' . implode(' AND ', $conds);
    $sql .= ' WHERE s.active = 1 GROUP BY s.id ORDER BY s.name';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['rate'] = (int)$r['total'] > 0 ? 100.0 * (int)$r['ok'] / (int)$r['total'] : null;
    }
    return $rows;
}

/** معدل الالتزام اليومي لآخر N يوم (للرسم البياني) */
function compliance_daily(int $days = 14): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $sql = 'SELECT DATE(check_at) AS d, COUNT(*) AS total, SUM(status) AS ok
            FROM ' . tbl('compliance') . '
            WHERE check_at >= ?
            GROUP BY DATE(check_at) ORDER BY d';
    $st = db()->prepare($sql);
    $st->execute([$from . ' 00:00:00']);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[$r['d']] = 100.0 * (int)$r['ok'] / max(1, (int)$r['total']);
    }
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['date' => $d, 'rate' => $map[$d] ?? null];
    }
    return $out;
}

/** مهام المستخدم: تقييمات مطلوبة منه (منفذة / غير منفذة) */
function user_tasks(int $userId): array
{
    $sql = 'SELECT a.episode_id, a.type, e.title, e.air_date, e.air_time,
                   p.name AS program_name, s.name AS station_name,
                   ev.id AS eval_id, ev.score, ev.created_at AS eval_at
            FROM ' . tbl('episode_evaluators') . ' a
            JOIN ' . tbl('episodes') . ' e ON e.id = a.episode_id
            JOIN ' . tbl('programs') . ' p ON p.id = e.program_id
            JOIN ' . tbl('stations') . ' s ON s.id = p.station_id
            LEFT JOIN ' . tbl('evaluations') . ' ev
                   ON ev.episode_id = a.episode_id AND ev.user_id = a.user_id AND ev.type = a.type
            WHERE a.user_id = ?
            ORDER BY e.air_date DESC, e.air_time DESC';
    $st = db()->prepare($sql);
    $st->execute([$userId]);
    $pending = [];
    $done = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['eval_id']) $done[] = $r; else $pending[] = $r;
    }
    return ['pending' => $pending, 'done' => $done];
}
