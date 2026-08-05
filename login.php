<?php
/** صفحة تسجيل الدخول */
require __DIR__ . '/core/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (auth_is_throttled()) {
        $error = 'تم تجاوز عدد محاولات الدخول المسموحة — انتظر 15 دقيقة ثم أعد المحاولة';
    } elseif ($username === '' || $password === '') {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
    } elseif (auth_login($username, $password)) {
        redirect('index.php');
    } else {
        audit_log('login_failed', 'user', 0, 'محاولة دخول فاشلة: ' . $username);
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
    }
}
$site = setting('site_name', 'منصة متابعة جودة البث والمحتوى الإذاعي');
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول | <?= e($site) ?></title>
<link rel="icon" type="image/png" href="assets/img/SBA_logo.png">
<link rel="stylesheet" href="assets/css/style.css?v=<?= SBA_VERSION ?>">
</head>
<body class="auth-body">
<div class="auth-box">
    <div class="auth-logo">
        <img src="assets/img/SBA_logo.png" alt="الشعار">
        <h1><?= e($site) ?></h1>
        <p class="muted">تسجيل الدخول للمنصة</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>اسم المستخدم</label>
            <input type="text" name="username" required autofocus dir="ltr"
                   value="<?= e($_POST['username'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>كلمة المرور</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">دخول</button>
    </form>

    <div class="auth-footer">
        <a href="presentation.php" class="btn btn-ghost btn-block">&#128214; تعرّف على النظام</a>
    </div>
</div>
</body>
</html>
