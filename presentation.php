<?php
/** صفحة تعريف النظام - عامة بدون تسجيل دخول */
require __DIR__ . '/core/bootstrap.php';

$stats = ['stations' => 0, 'programs' => 0, 'episodes' => 0, 'evaluations' => 0, 'compliance' => 0];
try {
    $pdo = db();
    $stats['stations']    = (int)$pdo->query('SELECT COUNT(*) FROM ' . tbl('stations'))->fetchColumn();
    $stats['programs']    = (int)$pdo->query('SELECT COUNT(*) FROM ' . tbl('programs'))->fetchColumn();
    $stats['episodes']    = (int)$pdo->query('SELECT COUNT(*) FROM ' . tbl('episodes'))->fetchColumn();
    $stats['evaluations'] = (int)$pdo->query('SELECT COUNT(*) FROM ' . tbl('evaluations'))->fetchColumn();
    $stats['compliance']  = (int)$pdo->query('SELECT COUNT(*) FROM ' . tbl('compliance'))->fetchColumn();
} catch (Throwable $e) { /* قبل التثبيت */ }

$features = [
    ['&#11088;', 'تقييم الجودة',   'تقييم فني وتقييم محتوى لكل حلقة، بعدة مقيّمين ومعايير قابلة للتخصيص، مع منع تكرار التقييم واحتساب النتيجة النهائية بأوزان مرنة.'],
    ['&#128337;', 'التزام البث',   'متابعة التزام الإذاعات بهيكل البث عند نقاط زمنية محددة، مع شاشة ألوان واضحة (أخضر/أحمر) واستيراد مباشر من ملفات Excel.'],
    ['&#128202;', 'تقارير ذكية',  'تقارير يومية وأسبوعية وشهرية مع فلترة بالتاريخ: تقرير البرنامج، تقرير الالتزام، وتقرير أداء المقيّمين.'],
    ['&#128200;', 'لوحة قيادة',   'معدل الالتزام، متوسط الجودة، ترتيب الإذاعات، وأفضل وأسوأ البرامج في رسوم بيانية واضحة.'],
    ['&#128101;', 'صلاحيات دقيقة','أربع عضويات: مدير نظام، مدير جودة، موظف بصلاحيات محددة، ومشاهد للعرض فقط — كلٌ يرى ما يخصه فقط.'],
    ['&#128737;&#65039;', 'حماية وموثوقية', 'سجل عمليات كامل Audit Log، نسخ احتياطي واستعادة بدون أدوات خارجية، وحماية CSRF وتشفير كلمات المرور.'],
    ['&#9889;', 'خفيف وسريع',     'PHP أصلي بدون أطر عمل ثقيلة، يعمل على أي استضافة عادية ويُرفع عبر FTP داخل مجلد فرعي دون تعارض.'],
    ['&#128241;', 'متجاوب بالكامل','واجهة عربية RTL تعمل بسلاسة على الجوال والكمبيوتر، بتاريخ ميلادي ووقت 24 ساعة.'],
];
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تعريف النظام | منصة متابعة جودة البث والمحتوى الإذاعي</title>
<link rel="icon" type="image/png" href="assets/img/SBA_logo.png">
<link rel="stylesheet" href="assets/css/style.css?v=1">
</head>
<body class="presentation-body">
<header class="pres-hero">
    <img src="assets/img/SBA_logo.png" alt="الشعار" class="pres-logo">
    <h1>منصة متابعة جودة البث والمحتوى الإذاعي</h1>
    <p>منصة داخلية احترافية لإدارة جودة الإذاعات: تقييم البرامج فنياً ومحتوىً، متابعة الالتزام بهيكل البث،
       تحليل الأداء، وإصدار تقارير ورسوم بيانية تدعم اتخاذ القرار المبني على البيانات.</p>
    <a href="login.php" class="btn btn-primary">تسجيل الدخول للمنصة</a>
</header>

<?php if (array_sum($stats) > 0): ?>
<section class="pres-section">
    <h2>إحصائيات المنصة</h2>
    <div class="stat-grid">
        <div class="stat-tile"><div class="stat-value"><?= number_format($stats['stations']) ?></div><div class="stat-label">إذاعة</div></div>
        <div class="stat-tile"><div class="stat-value"><?= number_format($stats['programs']) ?></div><div class="stat-label">برنامج</div></div>
        <div class="stat-tile"><div class="stat-value"><?= number_format($stats['episodes']) ?></div><div class="stat-label">حلقة</div></div>
        <div class="stat-tile"><div class="stat-value"><?= number_format($stats['evaluations']) ?></div><div class="stat-label">تقييم جودة</div></div>
        <div class="stat-tile"><div class="stat-value"><?= number_format($stats['compliance']) ?></div><div class="stat-label">فحص التزام</div></div>
    </div>
</section>
<?php endif; ?>

<section class="pres-section">
    <h2>مميزات النظام</h2>
    <div class="feature-grid">
        <?php foreach ($features as [$icon, $title, $desc]): ?>
        <div class="feature-card">
            <div class="feature-icon"><?= $icon ?></div>
            <h3><?= e($title) ?></h3>
            <p><?= e($desc) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="pres-section">
    <h2>لمحة عن الشاشات</h2>
    <div class="shot-grid">
        <div class="shot-card">
            <div class="shot-mock">
                <div class="shot-bar"></div>
                <div class="shot-body">
                    <div class="mock-tile g"></div><div class="mock-tile b"></div><div class="mock-tile o"></div>
                    <div class="mock-chart">
                        <span style="height:70%"></span><span style="height:45%"></span><span style="height:85%"></span>
                        <span style="height:55%"></span><span style="height:65%"></span>
                    </div>
                </div>
            </div>
            <h3>لوحة القيادة</h3>
            <p>مؤشرات الالتزام والجودة وترتيب الإذاعات في شاشة واحدة.</p>
        </div>
        <div class="shot-card">
            <div class="shot-mock">
                <div class="shot-bar"></div>
                <div class="shot-body">
                    <div class="mock-row"><i class="dot green"></i><i class="dot green"></i><i class="dot red"></i><i class="dot green"></i></div>
                    <div class="mock-row"><i class="dot green"></i><i class="dot red"></i><i class="dot green"></i><i class="dot green"></i></div>
                    <div class="mock-row"><i class="dot red"></i><i class="dot green"></i><i class="dot green"></i><i class="dot red"></i></div>
                </div>
            </div>
            <h3>شاشة الالتزام</h3>
            <p>جدول زمني بالألوان: أخضر ملتزم، أحمر غير ملتزم.</p>
        </div>
        <div class="shot-card">
            <div class="shot-mock">
                <div class="shot-bar"></div>
                <div class="shot-body">
                    <div class="mock-line w80"></div>
                    <div class="mock-stars">&#9733;&#9733;&#9733;&#9733;&#9734;</div>
                    <div class="mock-line w60"></div>
                    <div class="mock-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="mock-line w70"></div>
                </div>
            </div>
            <h3>تقييم الحلقات</h3>
            <p>معايير فنية ومحتوى بدرجات من 1 إلى 10 لكل مقيّم.</p>
        </div>
    </div>
</section>

<footer class="pres-footer">
    <img src="assets/img/SBA_logo.png" alt="الشعار" width="40">
    <p>منصة متابعة جودة البث والمحتوى الإذاعي — نظام حوكمة إعلامي قابل للتوسع</p>
    <a href="login.php">تسجيل الدخول</a>
</footer>
</body>
</html>
