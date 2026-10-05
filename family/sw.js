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

// NAS에 닿지 않을 때(인터넷 끊김 · NAS 꺼짐) 하얀 빈 화면 대신 안내 화면
self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET' || req.mode !== 'navigate') return;
  event.respondWith(fetch(req).catch(function () {
    var html = '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
      + '<title>연결할 수 없어요 · 우리집</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Apple SD Gothic Neo",sans-serif;background:#f5f6f8;color:#15181d;padding:24px;box-sizing:border-box;text-align:center}'
      + '@media(prefers-color-scheme:dark){body{background:#0f1216;color:#f1f3f6}}.c{max-width:340px}img{width:72px;height:72px;border-radius:17px}h1{font-size:20px;margin:16px 0 8px}p{color:#6b7280;font-size:15px;line-height:1.6;margin:0 0 20px}'
      + 'button{font:inherit;font-weight:700;font-size:16px;border:0;border-radius:14px;background:#16a34a;color:#fff;padding:13px 28px}</style></head><body><div class="c">'
      + '<img src="assets/icon.png" alt=""><h1>우리집 NAS에 연결할 수 없어요</h1><p>와이파이나 데이터가 켜져 있는지 확인해 주세요.<br>계속 안 되면 NAS가 켜져 있는지 봐 주세요.</p>'
      + '<button onclick="location.reload()">다시 시도</button></div></body></html>';
    return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});
