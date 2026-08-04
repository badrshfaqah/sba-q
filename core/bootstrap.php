<?php
/**
 * نواة النظام - التحميل الأساسي
 * منصة متابعة جودة البث والمحتوى الإذاعي
 */
define('SBA', true);
define('SBA_ROOT', dirname(__DIR__));
// رقم الإصدار يُقرأ من ملف VERSION (يتحدث تلقائياً مع كل تحديث)
define('SBA_VERSION', trim((string)@file_get_contents(SBA_ROOT . '/VERSION')) ?: '1.0.0');

// إعدادات الجلسة الآمنة
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SBA_SESSION');
    session_start();
}

date_default_timezone_set('Asia/Riyadh');
mb_internal_encoding('UTF-8');

// إذا لم يتم التثبيت بعد → التحويل لمعالج التثبيت
if (!file_exists(SBA_ROOT . '/config.php')) {
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($self, '/install/') === false) {
        header('Location: install/');
        exit;
    }
    return; // المثبت لا يحتاج بقية النواة
}

require_once SBA_ROOT . '/config.php';
require_once SBA_ROOT . '/core/helpers.php';
require_once SBA_ROOT . '/core/auth.php';
require_once SBA_ROOT . '/core/audit.php';
require_once SBA_ROOT . '/core/stats.php';
require_once SBA_ROOT . '/core/notifications.php';

/**
 * اتصال قاعدة البيانات (PDO Singleton)
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die('تعذر الاتصال بقاعدة البيانات. تحقق من ملف config.php');
        }
    }
    return $pdo;
}

/** اسم جدول مع البادئة */
function tbl(string $name): string
{
    return DB_PREFIX . $name;
}
