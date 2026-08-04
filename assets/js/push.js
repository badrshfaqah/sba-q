/* تفعيل إشعارات الجوال (Web Push) — صفحة التطبيق والإشعارات */
(function () {
    'use strict';

    var statusEl  = document.getElementById('pushStatus');
    var enableBtn = document.getElementById('pushEnable');
    var disableBtn = document.getElementById('pushDisable');
    var testForm  = document.getElementById('pushTestForm');
    var iosHint   = document.getElementById('iosHint');
    if (!statusEl) return;

    var csrf = (document.querySelector('input[name="csrf"]') || {}).value || '';

    function setStatus(cls, msg) {
        statusEl.className = 'alert alert-' + cls;
        statusEl.textContent = msg;
    }

    var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        if (isIOS && !isStandalone) {
            setStatus('info', 'أضف المنصة إلى الشاشة الرئيسية أولاً، ثم افتحها من الأيقونة لتفعيل الإشعارات');
            if (iosHint) iosHint.style.display = '';
        } else if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            setStatus('danger', 'الإشعارات تتطلب رابط https — بعد الرفع على الاستضافة ستعمل تلقائياً');
        } else {
            setStatus('danger', 'هذا المتصفح لا يدعم الإشعارات — جرّب Chrome أو Safari حديثاً');
        }
        return;
    }

    if (!window.SBA_VAPID) {
        setStatus('danger', 'مفاتيح الإشعارات غير مهيأة على السيرفر');
        return;
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var arr = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    function api(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    var swReg = null;

    function refreshUI() {
        swReg.pushManager.getSubscription().then(function (sub) {
            if (sub && Notification.permission === 'granted') {
                setStatus('success', '✅ الإشعارات مفعّلة على هذا الجهاز — ستصلك إشعارات المهام والتنبيهات العامة');
                enableBtn.style.display = 'none';
                disableBtn.style.display = '';
                if (testForm) testForm.style.display = '';
            } else if (Notification.permission === 'denied') {
                setStatus('danger', 'الإشعارات محظورة لهذا الموقع من إعدادات المتصفح — فعّلها من إعدادات الموقع ثم أعد المحاولة');
                enableBtn.style.display = 'none';
                disableBtn.style.display = 'none';
            } else {
                setStatus('info', 'الإشعارات غير مفعّلة على هذا الجهاز بعد');
                enableBtn.style.display = '';
                disableBtn.style.display = 'none';
                if (testForm) testForm.style.display = 'none';
            }
        });
    }

    navigator.serviceWorker.register('sw.js').then(function (reg) {
        swReg = reg;
        refreshUI();
    }).catch(function () {
        setStatus('danger', 'تعذر تسجيل عامل الخدمة — تأكد من الرفع على https');
    });

    enableBtn.addEventListener('click', function () {
        enableBtn.disabled = true;
        setStatus('info', 'جارٍ طلب الإذن...');
        Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') { enableBtn.disabled = false; refreshUI(); return; }
            return swReg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(window.SBA_VAPID)
            }).then(function (sub) {
                return api(window.SBA_PUSH_URLS.subscribe, sub.toJSON());
            }).then(function (res) {
                enableBtn.disabled = false;
                if (res && res.ok) refreshUI();
                else setStatus('danger', 'تعذر حفظ الاشتراك على السيرفر');
            });
        }).catch(function () {
            enableBtn.disabled = false;
            setStatus('danger', 'حدث خطأ أثناء التفعيل — أعد المحاولة');
        });
    });

    disableBtn.addEventListener('click', function () {
        swReg.pushManager.getSubscription().then(function (sub) {
            if (!sub) { refreshUI(); return; }
            var endpoint = sub.endpoint;
            sub.unsubscribe().then(function () {
                return api(window.SBA_PUSH_URLS.unsubscribe, { endpoint: endpoint });
            }).then(refreshUI);
        });
    });
})();
