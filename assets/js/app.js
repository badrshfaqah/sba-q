/* منصة متابعة جودة البث — سلوكيات الواجهة */
(function () {
    'use strict';

    /* تسجيل عامل الخدمة (يجعل المنصة قابلة للتثبيت كتطبيق) */
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(function () {});
    }

    /* قائمة الجوال */
    var toggle = document.getElementById('menuToggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (toggle && sidebar && overlay) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }

    /* ساعة الشريط العلوي (24 ساعة) */
    var clock = document.getElementById('topbarClock');
    if (clock) {
        setInterval(function () {
            var d = new Date();
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            clock.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }, 30000);
    }

    /* تأكيد النماذج الحساسة عبر data-confirm (بدل تضمين النص في السمة onsubmit) */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
        });
    });

    /* التنبيهات */
    var bell = document.getElementById('notifBell');
    var panel = document.getElementById('notifPanel');
    if (bell && panel) {
        bell.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target)) panel.classList.remove('open');
        });
    }

    /* التبويبات */
    document.querySelectorAll('[data-tabs]').forEach(function (tabs) {
        var container = tabs.closest('.card') || document;
        tabs.querySelectorAll('.tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabs.querySelectorAll('.tab').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                container.querySelectorAll('.tab-panel').forEach(function (p) {
                    p.classList.toggle('hidden', p.getAttribute('data-panel') !== btn.getAttribute('data-tab'));
                });
            });
        });
    });

    /* المتوسط الحي في نموذج التقييم */
    var evalForm = document.querySelector('.eval-form');
    var liveAvg = document.getElementById('liveAvg');
    if (evalForm && liveAvg) {
        evalForm.addEventListener('change', function () {
            var groups = {};
            evalForm.querySelectorAll('input[type="radio"][name^="scores"]').forEach(function (r) {
                if (r.checked) groups[r.name] = parseInt(r.value, 10);
            });
            var vals = Object.keys(groups).map(function (k) { return groups[k]; });
            liveAvg.textContent = vals.length
                ? (vals.reduce(function (a, b) { return a + b; }, 0) / vals.length).toFixed(1)
                : '—';
        });
    }

    /* ---------- الرسوم البيانية (Chart.js) ----------
       الألوان من لوحة موثّقة ومختبَرة لسلامة تمييز الألوان */
    var palette = {
        primary: '#2a78d6',
        primaryLight: '#cde2fb',
        good: '#0ca30c',
        bad: '#d03b3b',
        ink2: '#52514e',
        muted: '#898781',
        grid: '#e1e0d9'
    };

    function baseOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    rtl: true,
                    titleFont: { family: 'system-ui, Tahoma' },
                    bodyFont: { family: 'system-ui, Tahoma' }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: palette.muted, font: { family: 'system-ui, Tahoma' } }
                },
                y: {
                    grid: { color: palette.grid },
                    border: { display: false },
                    ticks: { color: palette.muted }
                }
            }
        };
    }

    window.sbaCharts = {
        /* أعمدة: ترتيب الإذاعات حسب الجودة (من 10) */
        stationsBar: function (canvasId, data) {
            var el = document.getElementById(canvasId);
            if (!el || !window.Chart || !data.labels.length) return;
            var opts = baseOptions();
            opts.scales.y.min = 0;
            opts.scales.y.max = 10;
            opts.plugins.tooltip.callbacks = {
                label: function (ctx) { return 'متوسط الجودة: ' + ctx.parsed.y + ' / 10'; }
            };
            new Chart(el, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        backgroundColor: palette.primary,
                        borderRadius: { topLeft: 4, topRight: 4 },
                        maxBarThickness: 42
                    }]
                },
                options: opts
            });
        },

        /* خط: معدل الالتزام اليومي (%) */
        complianceLine: function (canvasId, data) {
            var el = document.getElementById(canvasId);
            if (!el || !window.Chart) return;
            var opts = baseOptions();
            opts.scales.y.min = 0;
            opts.scales.y.max = 100;
            opts.plugins.tooltip.callbacks = {
                label: function (ctx) {
                    return ctx.parsed.y === null ? 'لا توجد بيانات' : 'معدل الالتزام: ' + ctx.parsed.y + '%';
                }
            };
            new Chart(el, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        borderColor: palette.good,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: palette.good,
                        backgroundColor: 'rgba(12, 163, 12, 0.08)',
                        fill: true,
                        spanGaps: true,
                        tension: 0.25
                    }]
                },
                options: opts
            });
        }
    };
})();
