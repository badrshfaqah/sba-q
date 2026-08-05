<?php
/**
 * معالج الأخطاء العام
 *
 * الهدف: ألا تظهر للمستخدم صفحة «خطأ 500» فارغة أبداً.
 * أي خطأ قاتل أو استثناء غير ملتقط يُحوَّل إلى صفحة عربية توضح المشكلة
 * وتقترح الحل، وتُسجَّل التفاصيل في backups/error.log للمراجعة.
 */
if (!defined('SBA')) exit;

/** تسجيل الخطأ في ملف السجل */
function sba_log_error(string $message): void
{
    $dir = SBA_ROOT . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message)
          . ' | ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
    @file_put_contents($dir . '/error.log', $line, FILE_APPEND);
}

/**
 * صفحة خطأ عربية بديلة عن 500
 * $detail يظهر لمدير النظام فقط
 */
function sba_error_page(string $title, string $detail = '', string $hint = ''): void
{
    // إن كان العرض قد بدأ: نعرض بطاقة داخل الصفحة ونغلق المستند بشكل سليم
    if (headers_sent()) {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        echo '<div style="background:#fbe7e7;border:1px solid #f1c1c1;color:#a32424;padding:18px 20px;'
           . 'margin:16px 0;border-radius:12px;font-family:system-ui,Tahoma,Arial;direction:rtl;text-align:right">'
           . '<div style="font-weight:700;font-size:1.05rem;margin-bottom:6px">&#9888;&#65039; ' . $h($title) . '</div>'
           . '<div style="font-size:0.9rem;line-height:1.7">تعذّر إكمال عرض هذا القسم، وبقية المنصة تعمل بشكل طبيعي '
           . 'وبياناتك سليمة. تفاصيل الخطأ محفوظة في <code>backups/error.log</code>.</div>'
           . ($hint ? '<div style="font-size:0.9rem;margin-top:8px"><strong>' . $h($hint) . '</strong></div>' : '')
           . '<div style="margin-top:12px"><a href="index.php" style="display:inline-block;background:#2a78d6;'
           . 'color:#fff;padding:9px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem">'
           . 'العودة للرئيسية</a></div>'
           . '</div>';
        // إغلاق هيكل الصفحة حتى لا تبقى مبتورة
        echo '</main></div></div></body></html>';
        exit;
    }
    http_response_code(200);   // نعرض صفحة مفهومة بدل خطأ الخادم
    header('Content-Type: text/html; charset=utf-8');

    // التفاصيل التقنية لمدير النظام فقط
    $isAdmin = false;
    try {
        $u = function_exists('current_user') ? current_user() : null;
        $isAdmin = $u && ($u['role'] ?? '') === 'admin';
    } catch (Throwable $e) {
        $isAdmin = false;
    }
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>تعذّر عرض الصفحة</title>
<style>
body { font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif; background:#f9f9f7;
       color:#1a1a22; line-height:1.75; margin:0; padding:24px; }
.box { max-width:640px; margin:6vh auto; background:#fcfcfb; border:1px solid rgba(11,11,11,.1);
       border-radius:14px; padding:26px 28px; box-shadow:0 2px 14px rgba(11,11,11,.06); }
h1 { font-size:1.25rem; color:#1C1E4C; margin:0 0 8px; }
p { margin:0 0 12px; color:#52514e; font-size:0.95rem; }
ul { margin:0 0 14px; padding-right:20px; color:#52514e; font-size:0.92rem; }
li { margin-bottom:6px; }
.det { background:#f4f6fa; border:1px solid #e1e0d9; border-radius:8px; padding:10px 12px;
       font-size:0.78rem; direction:ltr; text-align:left; overflow-x:auto; color:#6b6e80;
       white-space:pre-wrap; margin-bottom:14px; }
.btns { display:flex; gap:8px; flex-wrap:wrap; }
.btn { display:inline-block; padding:10px 20px; border-radius:9px; text-decoration:none;
       font-weight:600; font-size:0.9rem; }
.p { background:#2a78d6; color:#fff; }
.g { background:transparent; color:#52514e; border:1px solid #e1e0d9; }
</style>
</head>
<body>
<div class="box">
    <h1>&#9888;&#65039; <?= $esc($title) ?></h1>
    <p>حدث خلل منع عرض هذه الصفحة. بقية أقسام المنصة تعمل بشكل طبيعي، وبياناتك سليمة تماماً.</p>
    <?php if ($hint): ?><p><strong><?= $esc($hint) ?></strong></p><?php endif; ?>
    <ul>
        <li>عد للصفحة الرئيسية وجرّب القسم مرة أخرى</li>
        <li>إن تكرر الخلل، افتح <code>diagnose.php</code> لفحص بيئة التشغيل</li>
        <li>تفاصيل الخطأ محفوظة في <code>backups/error.log</code></li>
    </ul>
    <?php if ($isAdmin && $detail !== ''): ?>
        <div class="det"><?= $esc($detail) ?></div>
    <?php endif; ?>
    <div class="btns">
        <a class="btn p" href="index.php">العودة للرئيسية</a>
        <a class="btn g" href="diagnose.php">فحص النظام</a>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

/* ---------- الاستثناءات غير الملتقطة ---------- */
set_exception_handler(function (Throwable $e) {
    $detail = get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    sba_log_error($detail);
    sba_error_page('تعذّر إتمام العملية', $detail);
});

/* ---------- الأخطاء القاتلة (التي تسبب 500) ---------- */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $detail = $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'];
    sba_log_error($detail);

    // تلميح مخصص لأشهر سبب: ملف لم يكتمل رفعه
    $hint = '';
    if (stripos($e['message'], 'undefined function') !== false) {
        $hint = 'يبدو أن أحد ملفات النظام لم يكتمل رفعه — أعد رفع مجلد core كاملاً عبر FTP.';
    } elseif (stripos($e['message'], 'memory') !== false) {
        $hint = 'نفدت الذاكرة المتاحة — ارفع قيمة memory_limit من إعدادات الاستضافة.';
    } elseif (stripos($e['message'], 'maximum execution time') !== false) {
        $hint = 'تجاوزت العملية المهلة الزمنية المسموحة على الاستضافة.';
    }
    sba_error_page('تعذّر عرض الصفحة', $detail, $hint);
});
