/* عامل الخدمة — منصة متابعة جودة البث والمحتوى الإذاعي */
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(self.clients.claim());
});

/* تمرير الطلبات كما هي (مطلوب لقابلية التثبيت) */
self.addEventListener('fetch', function () {});

/* استقبال الإشعارات */
self.addEventListener('push', function (e) {
    var data = {};
    try { data = e.data ? e.data.json() : {}; } catch (err) { data = { body: e.data ? e.data.text() : '' }; }
    var title = data.title || 'منصة جودة البث';
    e.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: 'assets/img/icon-192.png',
        badge: 'assets/img/icon-192.png',
        dir: 'rtl',
        lang: 'ar',
        tag: data.tag || 'sba-' + Date.now(),
        data: { url: data.url || './index.php' }
    }));
});

/* فتح المنصة عند الضغط على الإشعار */
self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var url = (e.notification.data && e.notification.data.url) || './index.php';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if ('focus' in list[i]) { list[i].navigate(url); return list[i].focus(); }
        }
        return self.clients.openWindow(url);
    }));
});
