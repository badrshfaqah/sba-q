<?php
/**
 * البيانات التجريبية — تحميل وإزالة نظيفة
 *
 * عند التحميل يسجّل النظام هويات كل الصفوف التي أنشأها في إعداد demo_ids،
 * وعند الإزالة يحذف تلك الصفوف فقط — أي بيانات حقيقية أدخلتها بنفسك لا تُمس،
 * حتى لو تشابهت الأسماء (الصفوف الموجودة مسبقاً لا تُسجَّل ضمن التجريبي).
 */
if (!defined('SBA_INSTALL') && !defined('SBA')) exit;

const SBA_DEMO_PASSWORD = 'Demo@12345';

/** هل البيانات التجريبية محمّلة؟ */
function sba_demo_loaded(PDO $pdo, string $p): bool
{
    $st = $pdo->prepare("SELECT svalue FROM `{$p}settings` WHERE skey = 'demo_loaded'");
    $st->execute();
    return (string)$st->fetchColumn() === '1';
}

/** تحميل البيانات التجريبية. ترجع سطور سجل */
function sba_demo_load(PDO $pdo, string $p): array
{
    if (sba_demo_loaded($pdo, $p)) {
        throw new RuntimeException('البيانات التجريبية محمّلة مسبقاً — أزلها أولاً قبل إعادة التحميل');
    }

    $ids = ['stations' => [], 'users' => [], 'programs' => [], 'episodes' => [], 'compliance' => []];
    $log = [];
    mt_srand(2026);

    /* ---- الإذاعات: ملامح أداء مختلفة لملء التحليل الرباعي ---- */
    // [الاسم، التردد، متوسط الجودة المستهدف، نسبة الالتزام المستهدفة]
    $stationDefs = [
        ['إذاعة الرياض',        'FM 94.0', 8.6, 0.65],
        ['إذاعة جدة',           'FM 96.2', 7.2, 0.82],
        ['إذاعة القرآن الكريم', 'FM 98.1', 9.2, 0.97],
        ['إذاعة نداء الإسلام',  'FM 91.6', 5.6, 0.93],
        ['الإذاعة الإنجليزية',  'FM 88.0', 4.6, 0.55],
    ];
    $stations = [];
    $ins = $pdo->prepare("INSERT INTO `{$p}stations` (name, frequency, active) VALUES (?,?,1)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    foreach ($stationDefs as [$name, $freq, $q, $c]) {
        $ins->execute([$name, $freq]);
        $id = (int)$pdo->lastInsertId();
        if ($ins->rowCount() === 1) $ids['stations'][] = $id; // جديد فعلاً — يُسجَّل للإزالة
        $stations[$name] = ['id' => $id, 'q' => $q, 'c' => $c];
    }
    $log[] = 'إذاعات تجريبية: ' . count($ids['stations']) . ' جديدة';

    /* ---- الموظفون مصنّفين ---- */
    $pass = password_hash(SBA_DEMO_PASSWORD, PASSWORD_DEFAULT);
    $userDefs = [
        ['سارة الجودة',    'sara.quality',  'manager',  0, 0, 0],
        ['عبدالله الشمري', 'abdullah.tech', 'employee', 1, 0, 0],
        ['خالد العتيبي',   'khaled.tech',   'employee', 1, 0, 0],
        ['نورة القحطاني',  'noura.content', 'employee', 0, 1, 0],
        ['سلطان الحربي',   'sultan.content','employee', 0, 1, 0],
        ['ريم الدوسري',    'reem.both',     'employee', 1, 1, 0],
        ['فهد المطيري',    'fahad.comp',    'employee', 0, 0, 1],
        ['عمر المشاهد',    'omar.viewer',   'viewer',   0, 0, 0],
    ];
    $users = [];
    $ins = $pdo->prepare("INSERT INTO `{$p}users`
        (name, username, password, role, perm_technical, perm_content, perm_compliance, active)
        VALUES (?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    foreach ($userDefs as [$name, $u, $role, $t, $c, $k]) {
        $ins->execute([$name, $u, $pass, $role, $t, $c, $k]);
        $id = (int)$pdo->lastInsertId();
        if ($ins->rowCount() === 1) $ids['users'][] = $id;
        $users[$u] = $id;
    }
    $log[] = 'موظفون تجريبيون: ' . count($ids['users']) . ' جدد (كلمة المرور: ' . SBA_DEMO_PASSWORD . ')';

    $techPool    = [$users['abdullah.tech'], $users['khaled.tech'], $users['reem.both']];
    $contentPool = [$users['noura.content'], $users['sultan.content'], $users['reem.both']];

    /* ---- البرامج ---- */
    $programDefs = [
        'إذاعة الرياض'        => [['مجلس الرياض', 'تركي الماضي'], ['أمسيات العاصمة', 'هند السالم'], ['صباح العاصمة', 'بدر الحمد']],
        'إذاعة جدة'           => [['صباح جدة', 'أحمد باجابر'], ['البحر والناس', 'لمى فقيه']],
        'إذاعة القرآن الكريم' => [['تلاوات الفجر', 'ناصر القطامي'], ['تفسير وبيان', 'صالح المغامسي']],
        'إذاعة نداء الإسلام'  => [['فتاوى على الهواء', 'عبدالله المصلح'], ['هدي النبي', 'خالد الجليل']],
        'الإذاعة الإنجليزية'  => [['Morning Mix', 'Sarah Ahmed'], ['Saudi Talks', 'Omar Khan']],
    ];
    $programs = [];
    $check = $pdo->prepare("SELECT id FROM `{$p}programs` WHERE station_id = ? AND name = ?");
    $ins   = $pdo->prepare("INSERT INTO `{$p}programs` (station_id, name, presenter, active) VALUES (?,?,?,1)");
    foreach ($programDefs as $stName => $list) {
        foreach ($list as [$pName, $presenter]) {
            $sid = $stations[$stName]['id'];
            $check->execute([$sid, $pName]);
            $pid = (int)$check->fetchColumn();
            if (!$pid) {
                $ins->execute([$sid, $pName, $presenter]);
                $pid = (int)$pdo->lastInsertId();
                $ids['programs'][] = $pid;
            }
            $programs[] = ['id' => $pid, 'station' => $stName];
        }
    }
    $log[] = 'برامج تجريبية: ' . count($ids['programs']) . ' جديدة';

    /* ---- الحلقات + التكليفات + التقييمات ---- */
    $criteria = ['technical' => [], 'content' => []];
    foreach ($pdo->query("SELECT id, type FROM `{$p}criteria` WHERE active = 1") as $r) {
        $criteria[$r['type']][] = (int)$r['id'];
    }
    $insEp   = $pdo->prepare("INSERT INTO `{$p}episodes` (program_id, title, air_date, air_time) VALUES (?,?,?,?)");
    $insAss  = $pdo->prepare("INSERT IGNORE INTO `{$p}episode_evaluators` (episode_id, user_id, type) VALUES (?,?,?)");
    $insEval = $pdo->prepare("INSERT IGNORE INTO `{$p}evaluations` (episode_id, user_id, type, score, created_at) VALUES (?,?,?,?,?)");
    $insItem = $pdo->prepare("INSERT INTO `{$p}evaluation_items` (evaluation_id, criterion_id, score) VALUES (?,?,?)");

    $evalCount = 0; $pendingCount = 0;
    foreach ($programs as $prog) {
        $profile = $stations[$prog['station']];
        $numEps = mt_rand(3, 4);
        for ($e = 1; $e <= $numEps; $e++) {
            $daysAgo = mt_rand(0, 20);
            $date = date('Y-m-d', strtotime("-$daysAgo days"));
            $hour = [6, 8, 10, 14, 17, 20][mt_rand(0, 5)];
            $insEp->execute([$prog['id'], "حلقة $e", $date, sprintf('%02d:00', $hour)]);
            $epId = (int)$pdo->lastInsertId();
            $ids['episodes'][] = $epId;

            foreach (['technical' => $techPool, 'content' => $contentPool] as $type => $pool) {
                $shuffled = $pool;
                shuffle($shuffled);
                foreach (array_slice($shuffled, 0, mt_rand(1, 2)) as $uid) {
                    $insAss->execute([$epId, $uid, $type]);
                    $doneProb = $daysAgo >= 3 ? 0.85 : 0.4;
                    if (mt_rand(0, 100) / 100 > $doneProb) { $pendingCount++; continue; }
                    $items = [];
                    foreach ($criteria[$type] as $cid) {
                        $items[$cid] = max(1, min(10, (int)round($profile['q'] + mt_rand(-15, 15) / 10)));
                    }
                    if (!$items) continue;
                    $avg = round(array_sum($items) / count($items), 2);
                    $when = date('Y-m-d H:i:s', strtotime("$date +" . mt_rand(4, 48) . ' hours'));
                    $insEval->execute([$epId, $uid, $type, $avg, $when]);
                    $evalId = (int)$pdo->lastInsertId();
                    if ($evalId) {
                        foreach ($items as $cid => $sc) $insItem->execute([$evalId, $cid, $sc]);
                        $evalCount++;
                    }
                }
            }
        }
    }
    $log[] = 'حلقات: ' . count($ids['episodes']) . " — تقييمات منفذة: $evalCount — مهام معلقة: $pendingCount";

    /* ---- الالتزام: 14 يوماً × 6 نقاط زمنية (مع ضعف مقصود عند 18:00) ---- */
    $insComp = $pdo->prepare("INSERT IGNORE INTO `{$p}compliance` (station_id, check_at, status, user_id) VALUES (?,?,?,?)");
    $compUser = $users['fahad.comp'];
    for ($d = 13; $d >= 0; $d--) {
        $date = date('Y-m-d', strtotime("-$d days"));
        foreach (['06:00', '08:00', '12:00', '15:00', '18:00', '21:00'] as $t) {
            foreach ($stations as $prof) {
                $rate = $prof['c'] + ($t === '18:00' ? -0.15 : ($t === '06:00' ? 0.03 : 0));
                $insComp->execute([$prof['id'], "$date $t:00", (mt_rand(0, 1000) / 1000) < $rate ? 1 : 0, $compUser]);
                if ($insComp->rowCount() > 0) $ids['compliance'][] = (int)$pdo->lastInsertId();
            }
        }
    }
    $log[] = 'فحوصات التزام: ' . count($ids['compliance']);

    /* ---- حفظ الهويات وحالة التحميل ---- */
    $save = $pdo->prepare("INSERT INTO `{$p}settings` (skey, svalue) VALUES (?,?)
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    $save->execute(['demo_ids', json_encode($ids)]);
    $save->execute(['demo_loaded', '1']);
    $log[] = 'تم تسجيل هويات كل الصفوف التجريبية — الإزالة ستحذفها وحدها دون المساس ببياناتك';

    return $log;
}

/** إزالة البيانات التجريبية (الصفوف المسجلة فقط) */
function sba_demo_remove(PDO $pdo, string $p): array
{
    $st = $pdo->prepare("SELECT svalue FROM `{$p}settings` WHERE skey = 'demo_ids'");
    $st->execute();
    $ids = json_decode((string)$st->fetchColumn(), true);
    if (!$ids || !is_array($ids)) {
        throw new RuntimeException('لا توجد بيانات تجريبية مسجلة للإزالة');
    }
    $log = [];
    $del = function (string $table, array $list) use ($pdo, $p): int {
        if (!$list) return 0;
        $in = implode(',', array_map('intval', $list));
        return $pdo->exec("DELETE FROM `{$p}{$table}` WHERE id IN ($in)");
    };
    // الترتيب مهم: الحلقات ثم البرامج ثم الإذاعات (الحذف يتسلسل للتقييمات والالتزام تلقائياً)
    $log[] = 'حُذفت حلقات: ' . $del('episodes', $ids['episodes'] ?? []);
    $log[] = 'حُذفت برامج: ' . $del('programs', $ids['programs'] ?? []);
    $log[] = 'حُذفت إذاعات: ' . $del('stations', $ids['stations'] ?? []);
    $log[] = 'حُذفت فحوصات التزام: ' . $del('compliance', $ids['compliance'] ?? []);
    $log[] = 'حُذف موظفون تجريبيون: ' . $del('users', $ids['users'] ?? []);

    $pdo->exec("DELETE FROM `{$p}settings` WHERE skey = 'demo_ids'");
    $save = $pdo->prepare("INSERT INTO `{$p}settings` (skey, svalue) VALUES ('demo_loaded','0')
        ON DUPLICATE KEY UPDATE svalue = '0'");
    $save->execute();
    $log[] = 'اكتملت الإزالة — بياناتك الحقيقية لم تُمس';
    return $log;
}
