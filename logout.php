<?php
/** تسجيل الخروج */
require __DIR__ . '/core/bootstrap.php';
auth_logout();
redirect('login.php');
