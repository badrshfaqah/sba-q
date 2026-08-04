<?php
/** موديول سجل العمليات Audit Log */
if (!defined('SBA')) exit;

$perPage = 30;
$page = (int)($_GET['p'] ?? 1);
$filterUser   = (int)($_GET['user'] ?? 0);
$filterAction = input('action_f');

$where = ' WHERE 1=1';
$params = [];
if ($filterUser)   { $where .= ' AND a.user_id = ?'; $params[] = $filterUser; }
if ($filterAction) { $where .= ' AND a.action = ?'; $params[] = $filterAction; }

$countSt = db()->prepare('SELECT COUNT(*) FROM ' . tbl('audit_log') . ' a' . $where);
$countSt->execute($params);
[$offset, $pages, $page] = paginate((int)$countSt->fetchColumn(), $perPage, $page);

$st = db()->prepare('SELECT a.*, u.name AS user_name FROM ' . tbl('audit_log') . ' a
    LEFT JOIN ' . tbl('users') . ' u ON u.id = a.user_id' . $where . '
    ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$st->execute($params);
$logs = $st->fetchAll();

$users = db()->query('SELECT id, name FROM ' . tbl('users') . ' ORDER BY name')->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM ' . tbl('audit_log') . ' ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

layout_header('سجل العمليات');
?>
<div class="card">
    <div class="card-header">
        <h2>سجل العمليات</h2>
        <form method="get" action="index.php" class="inline-form">
            <input type="hidden" name="m" value="audit">
            <select name="user" onchange="this.form.submit()">
                <option value="0">كل المستخدمين</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="action_f" onchange="this.form.submit()">
                <option value="">كل العمليات</option>
                <?php foreach ($actions as $act): ?>
                <option value="<?= e($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= e(audit_action_label($act)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if (!$logs): ?>
        <div class="empty-state"><p>لا توجد سجلات</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>#</th><th>التاريخ والوقت</th><th>المستخدم</th><th>العملية</th><th>التفاصيل</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td><?= (int)$log['id'] ?></td>
            <td><?= fmt_datetime($log['created_at']) ?></td>
            <td><?= e($log['user_name'] ?: 'النظام') ?></td>
            <td><span class="badge badge-info"><?= e(audit_action_label($log['action'])) ?></span></td>
            <td><?= e($log['details']) ?></td>
            <td dir="ltr" class="muted"><?= e($log['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= paginate_links($pages, $page, 'audit', array_filter(['user' => $filterUser, 'action_f' => $filterAction])) ?>
    <?php endif; ?>
</div>
<?php
layout_footer();
