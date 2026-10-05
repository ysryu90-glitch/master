// 알림을 받아 보여주는 서비스 워커 (홈 화면에 추가한 사이트에서 동작)
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: '우리집', body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || '우리집', {
    body: data.body || '',
    icon: 'assets/icon.png',
    badge: 'assets/icon.png',
    tag: data.tag || undefined,
    data: { url: data.url || './index.php' }
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = new URL((event.notification.data && event.notification.data.url) || './index.php', self.registration.scope).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
        // 열려 있는 앱을 앞으로 가져와서 그 화면으로 이동 (이동이 안 되면 그냥 앞으로만)
        return c.focus().then(function (fc) {
          return fc && 'navigate' in fc ? fc.navigate(url).catch(function () { return fc; }) : fc;
        });
      }
    }
    return self.clients.openWindow ? self.clients.openWindow(url) : null;
  }).catch(function () { return self.clients.openWindow(url); }));
});

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });
