<?php
/**
 * التنبيهات الذكية — تُحسب لحظياً حسب دور المستخدم وصلاحياته
 * كل تنبيه: [الخطورة danger|warning|info, النص, الرابط]
 */
if (!defined('SBA')) exit;

function user_notifications(): array
{
    $u = current_user();
    if (!$u) return [];
    $list = [];
    $today = date('Y-m-d');

    try {
        /* التنبيهات العامة (آخر 7 أيام) */
        $st = db()->prepare('SELECT title FROM ' . tbl('announcements') . '
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 3');
        $st->execute();
        foreach ($st->fetchAll() as $an) {
            $list[] = ['info', '📢 ' . $an['title'], url('app')];
        }

        /* للمقيّم: مهامه المتأخرة ومهام اليوم */
        if (can('eval.technical') || can('eval.content')) {
            $types = [];
            if (can('eval.technical')) $types[] = 'technical';
            if (can('eval.content'))   $types[] = 'content';
            $in = "'" . implode("','", $types) . "'";
            $st = db()->prepare('SELECT
                    SUM(CASE WHEN e.air_date < ? THEN 1 ELSE 0 END) AS overdue,
                    SUM(CASE WHEN e.air_date = ? THEN 1 ELSE 0 END) AS due_today
                FROM ' . tbl('episode_evaluators') . ' a
                JOIN ' . tbl('episodes') . ' e ON e.id = a.episode_id
                LEFT JOIN ' . tbl('evaluations') . " ev
                       ON ev.episode_id = a.episode_id AND ev.user_id = a.user_id AND ev.type = a.type
                WHERE a.user_id = ? AND ev.id IS NULL AND a.type IN ($in)");
            $st->execute([$today, $today, (int)$u['id']]);
            $r = $st->fetch();
            if ((int)($r['overdue'] ?? 0) > 0) {
                $list[] = ['danger', 'لديك ' . (int)$r['overdue'] . ' مهمة تقييم متأخرة — ابدأ بالأقدم', url('dashboard')];
            }
            if ((int)($r['due_today'] ?? 0) > 0) {
                $list[] = ['info', 'لديك ' . (int)$r['due_today'] . ' مهمة تقييم لحلقات اليوم', url('dashboard')];
            }
        }

        /* لمسجّل الالتزام: تذكير إن لم تُسجَّل فحوصات اليوم */
        if (can('compliance.edit')) {
            $st = db()->prepare('SELECT COUNT(*) FROM ' . tbl('compliance') . ' WHERE check_at >= ? AND check_at <= ?');
            $st->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
            if ((int)$st->fetchColumn() === 0) {
                $list[] = ['warning', 'لم تُسجَّل أي فحوصات التزام لهذا اليوم بعد', url('compliance', ['a' => 'record'])];
            }
        }

        /* للإدارة: مؤشرات اليوم والمتأخرات العامة */
        if (can('data.viewall')) {
            $todayRate = compliance_rate($today, $today);
            if ($todayRate !== null && $todayRate < 80) {
                $list[] = ['danger', 'معدل التزام اليوم منخفض: ' . fmt_num($todayRate) . '%', url('compliance', ['date' => $today])];
            }
            if (can('evaluators.assign')) {
                $st = db()->query('SELECT COUNT(*) FROM ' . tbl('episode_evaluators') . ' a
                    JOIN ' . tbl('episodes') . ' e ON e.id = a.episode_id
                    LEFT JOIN ' . tbl('evaluations') . ' ev
                           ON ev.episode_id = a.episode_id AND ev.user_id = a.user_id AND ev.type = a.type
                    WHERE ev.id IS NULL AND e.air_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)');
                $n = (int)$st->fetchColumn();
                if ($n > 0) {
                    $list[] = ['warning', "$n مهمة تقييم متأخرة أكثر من 3 أيام لدى الفريق", url('followup')];
                }
            }
        }
    } catch (Throwable $e) {
        // لا تُفشل الصفحة بسبب التنبيهات
    }
    return $list;
}
