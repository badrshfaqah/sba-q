<?php
/** دوال مساعدة عامة */
if (!defined('SBA')) exit;

/** تهريب HTML */
function e($str): string
{
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

/** إعادة توجيه */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** رابط موديول داخلي */
function url(string $module, array $params = []): string
{
    $q = ['m' => $module] + $params;
    return 'index.php?' . http_build_query($q);
}

/** رسائل فلاش */
function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** رمز CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // النماذج العادية عبر حقل csrf، وطلبات JSON عبر ترويسة X-CSRF
        $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
        if (!hash_equals(csrf_token(), $token)) {
            http_response_code(403);
            die('طلب غير صالح (CSRF). أعد المحاولة.');
        }
    }
}

/** كاش الإعدادات (مع إمكانية تحديث مفتاح بعد الحفظ) */
function settings_cache(?string $setKey = null, ?string $setValue = null): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM ' . tbl('settings')) as $row) {
                $cache[$row['skey']] = $row['svalue'];
            }
        } catch (Throwable $e) {
            // الجداول غير جاهزة بعد
        }
    }
    if ($setKey !== null) {
        $cache[$setKey] = $setValue;
    }
    return $cache;
}

/** قراءة إعداد من قاعدة البيانات */
function setting(string $key, $default = null)
{
    $cache = settings_cache();
    return $cache[$key] ?? $default;
}

function setting_save(string $key, $value): void
{
    $st = db()->prepare('INSERT INTO ' . tbl('settings') . ' (skey, svalue) VALUES (?,?)
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, (string)$value]);
    settings_cache($key, (string)$value);
}

/** تنسيق التاريخ الميلادي والوقت 24 ساعة */
function fmt_date(?string $d): string
{
    if (!$d) return '—';
    return date('Y-m-d', strtotime($d));
}

function fmt_time(?string $t): string
{
    if (!$t) return '—';
    return date('H:i', strtotime($t));
}

function fmt_datetime(?string $dt): string
{
    if (!$dt) return '—';
    return date('Y-m-d H:i', strtotime($dt));
}

/** تنسيق رقم عشري */
function fmt_num($n, int $dec = 1): string
{
    if ($n === null || $n === '') return '—';
    return number_format((float)$n, $dec);
}

/** تسمية الأدوار */
function role_label(string $role): string
{
    $map = [
        'admin'    => 'مدير النظام',
        'manager'  => 'مدير الجودة',
        'employee' => 'موظف',
        'viewer'   => 'مشاهد',
    ];
    return $map[$role] ?? $role;
}

/** تسمية نوع التقييم */
function eval_type_label(string $type): string
{
    return $type === 'technical' ? 'فني' : 'محتوى';
}

/** قراءة قيمة POST/GET بأمان */
function input(string $key, $default = '')
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}

/**
 * رسالة خطأ آمنة للعرض
 * رسائلنا الخاصة (RuntimeException) تُعرض كما هي لأنها مكتوبة للمستخدم،
 * أما أخطاء قاعدة البيانات فتُسجَّل ولا يُعرض منها تفاصيل المخطط
 */
function safe_error(Throwable $e, string $fallback = 'حدث خطأ أثناء تنفيذ العملية'): string
{
    if ($e instanceof PDOException) {
        audit_log('error', 'system', 0, 'خطأ قاعدة بيانات: ' . mb_substr($e->getMessage(), 0, 300));
        return $fallback . ' — تم تسجيل التفاصيل في سجل العمليات';
    }
    return $e->getMessage() ?: $fallback;
}

/** ترقيم الصفحات */
function paginate(int $total, int $perPage, int $page): array
{
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    return [$offset, $pages, $page];
}

function paginate_links(int $pages, int $current, string $module, array $params = []): string
{
    if ($pages <= 1) return '';
    $html = '<div class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        if ($pages > 9 && $i > 2 && $i < $pages - 1 && abs($i - $current) > 2) {
            if ($i === 3 || $i === $pages - 2) $html .= '<span class="page-dots">…</span>';
            continue;
        }
        $cls  = $i === $current ? 'page-link active' : 'page-link';
        $html .= '<a class="' . $cls . '" href="' . e(url($module, $params + ['p' => $i])) . '">' . $i . '</a>';
    }
    return $html . '</div>';
}
