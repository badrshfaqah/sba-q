<?php
/** تسجيل الخروج (يتطلب تأكيداً لمنع الخروج القسري عبر روابط خارجية) */
require __DIR__ . '/core/bootstrap.php';

if (!current_user()) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    auth_logout();
    redirect('login.php');
}

$site = setting('site_name', 'منصة متابعة جودة البث والمحتوى الإذاعي');
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الخروج | <?= e($site) ?></title>
<link rel="icon" type="image/png" href="assets/img/SBA_logo.png">
<link rel="stylesheet" href="assets/css/style.css?v=<?= SBA_VERSION ?>">
</head>
<body class="auth-body">
<div class="auth-box">
    <div class="auth-logo">
        <img src="assets/img/SBA_logo.png" alt="الشعار">
        <h1>تأكيد تسجيل الخروج</h1>
        <p class="muted">هل تريد إنهاء الجلسة والخروج من المنصة؟</p>
    </div>
    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary btn-block">نعم، تسجيل الخروج</button>
    </form>
    <div class="auth-footer">
        <a href="index.php" class="btn btn-ghost btn-block">العودة للمنصة</a>
    </div>
</div>
</body>
</html>
