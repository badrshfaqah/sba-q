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
    return preg_match('#^[\w./-]+$#', $b) ? $b : 'main';
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
    return ['config.php', 'install/installed.lock', 'uploads', 'backups', '.git', '.gitignore'];
}

/**
 * تنفيذ التحديث الكامل. ترجع سطور سجل العملية.
 *  1. نسخة احتياطية تلقائية لقاعدة البيانات في backups/
 *  2. تنزيل ZIP الفرع من GitHub وفك ضغطه
 *  3. نسخ الملفات فوق التركيب الحالي (مع حماية config والرفعات)
 *  4. تشغيل ترقيات قاعدة البيانات
 */
function updater_run(): array
{
    $log = [];
    $root = SBA_ROOT;

    if (!is_writable($root)) {
        throw new RuntimeException('مجلد النظام غير قابل للكتابة — التحديث الذاتي يتطلب صلاحية كتابة');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد Zip غير مفعل على السيرفر');
    }

    // 1) نسخة احتياطية تلقائية
    require_once $root . '/core/backup.php';
    $backupDir = $root . '/backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
    $backupFile = $backupDir . '/pre_update_' . date('Y-m-d_Hi') . '.sql';
    file_put_contents($backupFile, backup_generate_sql());
    $log[] = 'نسخة احتياطية تلقائية: backups/' . basename($backupFile);

    // 2) تنزيل ZIP (عبر API مع رمز الوصول للمستودعات الخاصة، أو الرابط العام)
    if (updater_token() !== '') {
        $zipUrl = 'https://api.github.com/repos/' . updater_repo() . '/zipball/' . rawurlencode(updater_branch());
    } else {
        $zipUrl = 'https://codeload.github.com/' . updater_repo() . '/zip/refs/heads/' . updater_branch();
    }
    $tmpZip = $backupDir . '/update_tmp.zip';
    file_put_contents($tmpZip, updater_http_get($zipUrl, 120, updater_auth_headers()));
    $log[] = 'تم تنزيل حزمة التحديث (' . number_format(filesize($tmpZip) / 1024, 0) . ' KB)';

    // 3) فك الضغط في مجلد مؤقت
    $tmpDir = $backupDir . '/update_tmp';
    updater_rrmdir($tmpDir);
    @mkdir($tmpDir, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        throw new RuntimeException('تعذر فتح حزمة التحديث');
    }
    $zip->extractTo($tmpDir);
    $zip->close();
    @unlink($tmpZip);

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
    $copied = updater_copy_tree($srcRoot, $root, '');
    updater_rrmdir($tmpDir);
    $log[] = "تم تحديث $copied ملفاً من المستودع";

    // 5) ترقية قاعدة البيانات
    require_once $root . '/install/migrations.php';
    foreach (sba_run_migrations(db(), DB_PREFIX, $newVersion) as $line) {
        $log[] = $line;
    }

    $log[] = "اكتمل التحديث إلى الإصدار $newVersion";
    return $log;
}

/** نسخ شجرة ملفات مع تجاوز المسارات المحمية */
function updater_copy_tree(string $src, string $dst, string $rel): int
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
            $count += updater_copy_tree($s, $d, $relPath);
        } else {
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
