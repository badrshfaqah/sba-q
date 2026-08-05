<?php
/**
 * نظام التحديث من GitHub — بدون git وبدون terminal
 * يعمل على الاستضافات المشتركة عبر تنزيل ZIP الفرع وفك ضغطه ونسخ الملفات
 */
if (!defined('SBA')) exit;

/** المستودع والفرع (قابلة للتعديل من جدول الإعدادات) */
function updater_repo(): string
{
    $repo = trim((string)setting('update_repo', 'badrshfaqah/sba-q'));
    return preg_match('#^[\w.-]+/[\w.-]+$#', $repo) ? $repo : 'badrshfaqah/sba-q';
}

function updater_branch(): string
{
    $b = trim((string)setting('update_branch', 'main'));
    // منع الخروج عن المستودع عبر ../ في اسم الفرع
    if (strpos($b, '..') !== false) return 'main';
    return preg_match('#^[\w.-]+(/[\w.-]+)*$#', $b) ? $b : 'main';
}

/**
 * رمز الوصول GitHub Token (للمستودعات الخاصة)
 * فارغ = مستودع عام بدون مصادقة
 */
function updater_token(): string
{
    return trim((string)setting('update_token', ''));
}

/** جلب محتوى رابط عبر cURL أو file_get_contents مع ترويسات اختيارية */
function updater_http_get(string $url, int $timeout = 60, array $headers = []): string
{
    $headers[] = 'User-Agent: SBA-Quality-Updater';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) curl_close($ch); // مهجورة منذ PHP 8.0
        if ($body === false) {
            throw new RuntimeException('فشل الاتصال: ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('رفض المصادقة (' . $code . ') — تحقق من صلاحية رمز الوصول GitHub Token');
        }
        if ($code === 404) {
            throw new RuntimeException('المستودع أو الملف غير موجود (404) — إن كان المستودع خاصاً فأدخل رمز الوصول في الإعدادات');
        }
        if ($code >= 400) {
            throw new RuntimeException("الخادم البعيد أعاد رمز الخطأ $code");
        }
        return (string)$body;
    }
    // بديل: file_get_contents (يتطلب allow_url_fopen)
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => $timeout,
            'follow_location' => 1,
            'header'          => implode("\r\n", $headers),
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('فشل الاتصال — تأكد من تفعيل cURL أو allow_url_fopen على السيرفر');
    }
    return $body;
}

/** ترويسات المصادقة عند وجود رمز وصول */
function updater_auth_headers(): array
{
    $token = updater_token();
    return $token !== '' ? ['Authorization: Bearer ' . $token] : [];
}

/**
 * أحدث إصدار متاح في المستودع (من ملف VERSION)
 * مع رمز وصول: عبر GitHub API (يدعم المستودعات الخاصة)
 * بدون رمز: عبر الرابط العام raw
 */
function updater_remote_version(): string
{
    if (updater_token() !== '') {
        $url = 'https://api.github.com/repos/' . updater_repo() . '/contents/VERSION?ref=' . rawurlencode(updater_branch());
        $headers = array_merge(updater_auth_headers(), ['Accept: application/vnd.github.raw+json']);
        $v = trim(updater_http_get($url, 20, $headers));
    } else {
        $url = 'https://raw.githubusercontent.com/' . updater_repo() . '/' . updater_branch() . '/VERSION';
        $v = trim(updater_http_get($url, 20));
    }
    if (!preg_match('/^\d+\.\d+\.\d+$/', $v)) {
        throw new RuntimeException('تعذر قراءة رقم الإصدار من المستودع (ملف VERSION)');
    }
    return $v;
}

/** الملفات والمجلدات التي لا يلمسها التحديث أبداً */
function updater_protected(): array
{
    return [
        'config.php', 'install/installed.lock', 'uploads', 'backups', '.git', '.gitignore',
        'docs',   // ملفات العرض التقديمي والتوثيق — للمستودع فقط، لا داعي لنسخها لكل تركيب
    ];
}

/**
 * تنفيذ التحديث الكامل. ترجع سطور سجل العملية.
 *  1. نسخة احتياطية تلقائية لقاعدة البيانات في backups/
 *  2. تنزيل ZIP الفرع من GitHub وفك ضغطه
 *  3. نسخ الملفات فوق التركيب الحالي (مع حماية config والرفعات)
 *  4. تشغيل ترقيات قاعدة البيانات
 */
/** كتابة خطوة في سجل التحديث (يبقى حتى لو مات الطلب بخطأ 500) */
function updater_log(string $line): void
{
    $file = SBA_ROOT . '/backups/update.log';
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

/**
 * تنزيل ملف إلى القرص مباشرة (بلا تحميله كاملاً في الذاكرة)
 * يقلل استهلاك الذاكرة على الاستضافات المحدودة
 */
function updater_download(string $url, string $dest): void
{
    $headers = updater_auth_headers();
    $headers[] = 'User-Agent: SBA-Quality-Updater';

    if (function_exists('curl_init')) {
        $fh = fopen($dest, 'wb');
        if (!$fh) throw new RuntimeException('تعذر إنشاء ملف مؤقت في مجلد backups — تحقق من صلاحيات الكتابة');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        fclose($fh);
        if ($ok === false || $code >= 400) {
            @unlink($dest);
            throw new RuntimeException($code >= 400
                ? "الخادم البعيد أعاد رمز الخطأ $code — تحقق من اسم المستودع والفرع"
                : 'فشل تنزيل الحزمة: ' . $err);
        }
        return;
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 180, 'follow_location' => 1, 'header' => implode("\r\n", $headers),
    ]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) throw new RuntimeException('تعذر الاتصال بالمستودع — فعّل cURL أو allow_url_fopen');
    $out = fopen($dest, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
}

function updater_run(): array
{
    $log = [];
    $root = SBA_ROOT;

    // سجل جديد لكل عملية + التقاط أي خطأ قاتل يوقف الطلب
    if (!is_dir($root . '/backups')) @mkdir($root . '/backups', 0755, true);
    @file_put_contents($root . '/backups/update.log',
        '=== بدء التحديث ' . date('Y-m-d H:i:s') . ' — PHP ' . PHP_VERSION
        . ' | ذاكرة ' . ini_get('memory_limit') . ' | مهلة ' . ini_get('max_execution_time') . "s ===\n");
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            updater_log('!! توقف بخطأ قاتل: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
        }
    });

    if (!is_writable($root)) {
        throw new RuntimeException('مجلد النظام غير قابل للكتابة — التحديث الذاتي يتطلب صلاحية كتابة');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد Zip غير مفعل على السيرفر');
    }
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
        throw new RuntimeException('لا يمكن الاتصال بالإنترنت — امتداد cURL معطّل و allow_url_fopen مغلق');
    }

    // 1) نسخة احتياطية تلقائية
    updater_log('1) توليد النسخة الاحتياطية...');
    require_once $root . '/core/backup.php';
    $backupDir = $root . '/backups';
    $backupFile = $backupDir . '/pre_update_' . date('Y-m-d_Hi') . '.sql';
    file_put_contents($backupFile, backup_generate_sql());
    $log[] = 'نسخة احتياطية تلقائية: backups/' . basename($backupFile);
    updater_log('   ✓ ' . basename($backupFile) . ' (' . number_format(filesize($backupFile) / 1024, 0) . ' KB)');

    // 2) تنزيل ZIP (عبر API مع رمز الوصول للمستودعات الخاصة، أو الرابط العام)
    if (updater_token() !== '') {
        $zipUrl = 'https://api.github.com/repos/' . updater_repo() . '/zipball/' . rawurlencode(updater_branch());
    } else {
        $zipUrl = 'https://codeload.github.com/' . updater_repo() . '/zip/refs/heads/' . updater_branch();
    }
    updater_log('2) تنزيل الحزمة من المستودع...');
    $tmpZip = $backupDir . '/update_tmp.zip';
    updater_download($zipUrl, $tmpZip);
    $log[] = 'تم تنزيل حزمة التحديث (' . number_format(filesize($tmpZip) / 1024, 0) . ' KB)';
    updater_log('   ✓ ' . number_format(filesize($tmpZip) / 1024, 0) . ' KB');

    // 3) فك الضغط في مجلد مؤقت (مع تخطي مجلد docs الثقيل)
    updater_log('3) فك ضغط الحزمة...');
    $tmpDir = $backupDir . '/update_tmp';
    updater_rrmdir($tmpDir);
    @mkdir($tmpDir, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        throw new RuntimeException('تعذر فتح حزمة التحديث');
    }
    $wanted = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        if (strpos($name, '/docs/') !== false) continue;   // العرض التقديمي لا يُنسخ للتركيبات
        $wanted[] = $name;
    }
    $zip->extractTo($tmpDir, $wanted);
    $zip->close();
    @unlink($tmpZip);
    updater_log('   ✓ ' . count($wanted) . ' عنصراً');

    // GitHub يضع المحتوى داخل مجلد باسم repo-branch
    $entries = array_values(array_diff(scandir($tmpDir), ['.', '..']));
    $srcRoot = count($entries) === 1 && is_dir($tmpDir . '/' . $entries[0])
        ? $tmpDir . '/' . $entries[0]
        : $tmpDir;

    if (!is_file($srcRoot . '/VERSION') || !is_file($srcRoot . '/index.php')) {
        updater_rrmdir($tmpDir);
        throw new RuntimeException('محتوى الحزمة غير صالح — لم يتم العثور على ملفات النظام');
    }
    $newVersion = trim((string)file_get_contents($srcRoot . '/VERSION'));

    // 4) نسخ الملفات فوق التركيب الحالي
    updater_log('4) نسخ الملفات (الإصدار الجديد: ' . $newVersion . ')...');
    $missing = [];
    $copied = updater_copy_tree($srcRoot, $root, '', $missing);
    updater_rrmdir($tmpDir);
    $log[] = "تم تحديث $copied ملفاً من المستودع";
    updater_log('   ✓ ' . $copied . ' ملفاً');

    // تقرير عن الملفات التي كانت ناقصة أو مبتورة (يكشف رفعاً غير مكتمل)
    $broken = array_values(array_filter($missing, function ($m) {
        return strpos($m, '(مفقود)') !== false || strpos($m, '(فارغ)') !== false;
    }));
    if ($broken) {
        $log[] = 'استُعيد ' . count($broken) . ' ملفاً كان مفقوداً أو فارغاً: '
               . implode('، ', array_slice($broken, 0, 6))
               . (count($broken) > 6 ? ' وغيرها' : '');
        updater_log('   ! ملفات كانت ناقصة: ' . implode(' | ', $broken));
    }

    // 5) ترقية قاعدة البيانات
    updater_log('5) ترقية قاعدة البيانات...');
    require_once $root . '/install/migrations.php';
    foreach (sba_run_migrations(db(), DB_PREFIX, $newVersion) as $line) {
        $log[] = $line;
        updater_log('   ' . $line);
    }

    $log[] = "اكتمل التحديث إلى الإصدار $newVersion";
    updater_log('=== اكتمل التحديث بنجاح إلى ' . $newVersion . ' ===');
    return $log;
}

/**
 * نسخ شجرة ملفات مع تجاوز المسارات المحمية
 * $missing يُملأ بأسماء الملفات التي كانت مفقودة أو فارغة أو مختلفة الحجم
 */
function updater_copy_tree(string $src, string $dst, string $rel, array &$missing = []): int
{
    $count = 0;
    $protected = updater_protected();
    foreach (array_diff(scandir($src), ['.', '..']) as $item) {
        $relPath = $rel === '' ? $item : $rel . '/' . $item;
        if (in_array($relPath, $protected, true)) continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!is_dir($d)) @mkdir($d, 0755, true);
            $count += updater_copy_tree($s, $d, $relPath, $missing);
        } else {
            // رصد الملفات الناقصة أو المبتورة قبل استبدالها
            if (!is_file($d)) {
                $missing[] = $relPath . ' (مفقود)';
            } elseif (filesize($d) === 0 && filesize($s) > 0) {
                $missing[] = $relPath . ' (فارغ)';
            } elseif (filesize($d) !== filesize($s)) {
                $missing[] = $relPath . ' (مختلف)';
            }
            if (copy($s, $d)) $count++;
        }
    }
    return $count;
}

/** حذف مجلد بمحتوياته */
function updater_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? updater_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
