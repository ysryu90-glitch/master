/* 우리집 건강 — 화면 공통 스크립트 */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function round(v, d) { var p = Math.pow(10, d || 0); return Math.round((+v || 0) * p) / p; }

  // ───────── 진행 표시: 위쪽 막대 + 안내 상자 + 누른 버튼 돌기 ─────────
  // 홈 화면 앱(아이폰)에는 브라우저 로딩 표시가 없어서, 누르면 바로 '진행 중'이 보이게 한다.
  var Busy = window.Busy = (function () {
    var bar = document.createElement('div');
    bar.id = 'busy-bar';
    bar.innerHTML = '<i></i>';
    var box = document.createElement('div');
    box.id = 'busy-box';
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    box.innerHTML = '<div class="row"><span class="spin"></span><span class="msg"></span><span class="pct"></span></div>'
      + '<div class="meter"><i></i></div><div class="hint"></div>';
    document.body.appendChild(bar);
    document.body.appendChild(box);
    var fill = bar.firstChild, msgEl = box.querySelector('.msg'), pctEl = box.querySelector('.pct'),
      meter = box.querySelector('.meter'), meterFill = meter.firstChild, hint = box.querySelector('.hint');
    var pct = 0, trickle = null, timers = [], active = false, determinate = false, hideTimer = null;

    function clearTimers() { timers.forEach(clearTimeout); timers = []; clearInterval(trickle); trickle = null; }
    function width(p) { pct = Math.max(pct, Math.min(p, 99.5)); fill.style.width = pct + '%'; }
    function showBox(message) {
      msgEl.textContent = message;
      box.classList.add('on');
    }
    function start(message, opts) {
      opts = opts || {};
      clearTimers(); clearTimeout(hideTimer);
      active = true; determinate = false; pct = 0;
      bar.className = 'on';
      fill.style.transition = 'none'; fill.style.width = '0%';
      void fill.offsetWidth;
      fill.style.transition = '';
      width(8);
      // 정해진 진행률을 모를 때는 천천히 차오르게 (90%에서 멈춤)
      trickle = setInterval(function () { if (!determinate) width(pct + (90 - pct) * 0.06); }, 250);
      meter.classList.remove('on'); pctEl.textContent = ''; hint.textContent = '';
      box.classList.remove('on', 'slow');
      var text = message || '처리하는 중이에요…';
      timers.push(setTimeout(function () { showBox(text); }, opts.delay == null ? 500 : opts.delay));
      timers.push(setTimeout(function () { hint.textContent = '조금 오래 걸리고 있어요. 그대로 기다려 주세요.'; }, 8000));
      timers.push(setTimeout(function () {
        box.classList.add('slow');
        hint.innerHTML = '응답이 너무 늦어요. 인터넷 연결을 확인하고 <button type="button" class="btn small">새로고침</button> 해 주세요.';
        hint.querySelector('button').onclick = function () { location.reload(); };
      }, opts.timeout || 45000));
    }
    function set(p, message) {
      if (!active) start(message, { delay: 0 });
      determinate = true;
      p = Math.max(0, Math.min(100, p));
      fill.style.width = p + '%'; pct = p;
      meter.classList.add('on'); meterFill.style.width = p + '%';
      pctEl.textContent = Math.round(p) + '%';
      if (message) showBox(message);
    }
    function message(text) { if (active) showBox(text); }
    function done(finalMessage, isError) {
      clearTimers();
      active = false;
      fill.style.width = '100%';
      hideTimer = setTimeout(function () { bar.className = ''; fill.style.width = '0%'; pct = 0; }, 350);
      if (finalMessage) {
        msgEl.textContent = finalMessage; pctEl.textContent = ''; hint.textContent = '';
        meter.classList.remove('on');
        box.classList.add('on', 'end'); box.classList.toggle('err', !!isError);
        setTimeout(function () { box.classList.remove('on', 'end', 'err'); }, isError ? 5000 : 2200);
      } else {
        box.classList.remove('on', 'slow');
      }
      $all('.btn.loading').forEach(function (b) { b.classList.remove('loading'); b.disabled = false; });
    }
    /** 버튼을 돌게 하고 promise 가 끝나면 원래대로 */
    function track(promise, message, button) {
      if (button) { button.classList.add('loading'); button.disabled = true; }
      start(message, { delay: 300 });
      return promise.then(function (v) { done(); return v; }, function (e) { done('⚠️ ' + (e && e.message || e), true); throw e; });
    }
    return { start: start, set: set, message: message, done: done, track: track };
  })();

  // 페이지마다 오래 걸리는 화면 안내
  var PAGE_MSG = {
    'outing.php': '날씨 · 일정 · 축제를 살펴서 나들이 추천을 만드는 중이에요…',
    'index.php': '오늘 화면을 불러오는 중이에요…',
    'calendar.php': '일정을 불러오는 중이에요…',
    'report.php': '이번 주 리포트를 만드는 중이에요…'
  };
  function pageOf(url) { var m = /([a-z_]+\.php)/.exec(url.pathname); return m ? m[1] : (/\/$/.test(url.pathname) ? 'index.php' : ''); }

  // 폼 보내기: 다른 스크립트가 막지 않았으면 진행 표시 + 두 번 누름 방지
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || form.hasAttribute('data-no-busy')) return;
    if (form.getAttribute('data-sending') === '1') { e.preventDefault(); return; }
    form.setAttribute('data-sending', '1');
    var btn = e.submitter || form.querySelector('button:not([type=button]), input[type=submit]');
    if (btn && btn.classList) btn.classList.add('loading');
    setTimeout(function () { if (btn) btn.disabled = true; }, 0); // 값이 먼저 실려 가도록 한 박자 뒤에
    var msg = (btn && btn.getAttribute && btn.getAttribute('data-busy')) || form.getAttribute('data-busy');
    if (!msg && (form.method || '').toLowerCase() === 'get') msg = PAGE_MSG[pageOf(new URL(form.action, location.href))];
    Busy.start(msg || '저장하는 중이에요…');
  });

  // 같은 사이트 안의 링크 이동
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-no-busy')) return;
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
    Busy.start(a.getAttribute('data-busy') || PAGE_MSG[pageOf(url)] || '불러오는 중이에요…', { delay: 700 });
  });

  // 뒤로 가기로 돌아왔을 때 (사파리가 예전 화면을 그대로 보여 줄 때) 원래대로
  window.addEventListener('pageshow', function () {
    Busy.done();
    $all('form[data-sending]').forEach(function (f) { f.removeAttribute('data-sending'); });
    $all('.btn.loading, button.loading').forEach(function (b) { b.classList.remove('loading'); b.disabled = false; });
  });

  // ───────── 삭제 등 확인 ─────────
  $all('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // ───────── 사진: 올리기 전에 줄여서 DB에 저장 (긴 변 1280px, JPEG) ─────────
  $all('input[type=file][data-resize]').forEach(function (input) {
    var target = document.getElementsByName(input.getAttribute('data-resize'))[0];
    var preview = $(input.getAttribute('data-preview'));
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function () {
        var img = new Image();
        img.onload = function () {
          var max = 1280, w = img.width, h = img.height;
          if (Math.max(w, h) > max) { var s = max / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
          var canvas = document.createElement('canvas');
          canvas.width = w; canvas.height = h;
          canvas.getContext('2d').drawImage(img, 0, 0, w, h);
          var data = canvas.toDataURL('image/jpeg', 0.78);
          target.value = data;
          if (preview) { preview.src = data; preview.classList.remove('hidden'); }
        };
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  });

  // ───────── 날씨 (Open-Meteo, 키 필요 없음) ─────────
  var CODES = {
    0: ['맑음', '☀️', '🌙'], 1: ['대체로 맑음', '🌤', '🌙'], 2: ['구름 조금', '⛅️', '☁️'], 3: ['흐림', '☁️', '☁️'],
    45: ['안개', '🌫', '🌫'], 48: ['안개', '🌫', '🌫'], 51: ['이슬비', '🌦', '🌧'], 53: ['이슬비', '🌦', '🌧'], 55: ['이슬비', '🌧', '🌧'],
    61: ['약한 비', '🌧', '🌧'], 63: ['비', '🌧', '🌧'], 65: ['강한 비', '🌧', '🌧'], 66: ['어는 비', '🌧', '🌧'], 67: ['어는 비', '🌧', '🌧'],
    71: ['약한 눈', '🌨', '🌨'], 73: ['눈', '❄️', '❄️'], 75: ['많은 눈', '❄️', '❄️'], 77: ['싸락눈', '🌨', '🌨'],
    80: ['소나기', '🌦', '🌧'], 81: ['소나기', '🌧', '🌧'], 82: ['강한 소나기', '⛈', '⛈'], 85: ['눈 소나기', '🌨', '🌨'], 86: ['눈 소나기', '🌨', '🌨'],
    95: ['뇌우', '⛈', '⛈'], 96: ['뇌우', '⛈', '⛈'], 99: ['뇌우', '⛈', '⛈']
  };
  function airGrade(pm10, pm25) {
    var g10 = pm10 <= 30 ? 0 : pm10 <= 80 ? 1 : pm10 <= 150 ? 2 : 3;
    var g25 = pm25 <= 15 ? 0 : pm25 <= 35 ? 1 : pm25 <= 75 ? 2 : 3;
    return ['좋음', '보통', '나쁨', '매우 나쁨'][Math.max(g10, g25)];
  }
  $all('[data-weather]').forEach(function (box) {
    var locations = JSON.parse(box.getAttribute('data-weather'));
    Promise.all(locations.map(function (loc) {
      var q = 'latitude=' + loc.lat + '&longitude=' + loc.lon + '&timezone=Asia%2FSeoul';
      return Promise.all([
        fetch('https://api.open-meteo.com/v1/forecast?' + q + '&current=temperature_2m,weather_code,is_day' +
          '&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max&forecast_days=1').then(function (r) { return r.json(); }),
        fetch('https://air-quality-api.open-meteo.com/v1/air-quality?' + q + '&current=pm10,pm2_5').then(function (r) { return r.json(); }).catch(function () { return null; })
      ]).then(function (res) { return { loc: loc, f: res[0], a: res[1] }; }).catch(function () { return null; });
    })).then(function (rows) {
      var tips = [];
      box.innerHTML = rows.filter(Boolean).map(function (row) {
        var c = row.f.current, d = row.f.daily;
        var code = CODES[c.weather_code] || ['-', '🌡', '🌡'];
        var rain = d.precipitation_probability_max[0];
        var air = row.a && row.a.current ? airGrade(row.a.current.pm10, row.a.current.pm2_5) : null;
        if (rain >= 50) tips.push('☂️ ' + row.loc.name + ' 비 ' + rain + '%');
        if (air === '나쁨' || air === '매우 나쁨') tips.push('😷 ' + row.loc.name + ' 미세먼지 ' + air);
        return '<div class="wx"><div class="ic">' + (c.is_day ? code[1] : code[2]) + '</div>' +
          '<div class="grow"><b>' + esc(row.loc.name) + '</b> <span class="muted small">' + esc(row.loc.title) + '</span>' +
          '<div class="small muted">' + code[0] + ' · 비 ' + rain + '%' + (air ? ' · 미세먼지 ' + air : '') + '</div></div>' +
          '<div><div class="t">' + Math.round(c.temperature_2m) + '°</div>' +
          '<div class="r">' + Math.round(d.temperature_2m_min[0]) + '° / ' + Math.round(d.temperature_2m_max[0]) + '°</div></div></div>';
      }).join('') + (tips.length ? '<div class="advice">' + esc(tips.join(' · ')) + '</div>' : '');
      if (!rows.filter(Boolean).length) box.innerHTML = '<div class="empty">날씨를 불러오지 못했어요.</div>';
    });
  });

  // ───────── 간단한 SVG 차트 ─────────
  // <svg class="chart" data-chart='{"type":"line","points":[{"l":"월","v":7.2}],"color":"#16a34a","min":0,"max":10,"goal":8000}'>
  $all('[data-chart]').forEach(function (svg) {
    var cfg = JSON.parse(svg.getAttribute('data-chart'));
    var pts = cfg.points || [];
    var W = 320, H = 150, L = 28, R = 8, T = 10, B = 22;
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    var values = pts.map(function (p) { return p.v; }).filter(function (v) { return v !== null; });
    if (!values.length) { svg.outerHTML = '<div class="empty">아직 기록이 없어요.</div>'; return; }
    var min = cfg.min != null ? cfg.min : Math.min.apply(null, values);
    var max = cfg.max != null ? cfg.max : Math.max.apply(null, values.concat(cfg.goal || []));
    if (max === min) { max += 1; min -= 1; }
    var x = function (i) { return L + (pts.length === 1 ? (W - L - R) / 2 : i * (W - L - R) / (pts.length - 1)); };
    var y = function (v) { return T + (1 - (v - min) / (max - min)) * (H - T - B); };
    var color = cfg.color || '#16a34a';
    var out = '';
    [min, (min + max) / 2, max].forEach(function (g) {
      out += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(g) + '" y2="' + y(g) + '" stroke="currentColor" opacity="0.08"/>' +
        '<text x="' + (L - 4) + '" y="' + (y(g) + 3) + '" text-anchor="end">' + round(g, max - min < 10 ? 1 : 0) + '</text>';
    });
    if (cfg.goal) out += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(cfg.goal) + '" y2="' + y(cfg.goal) + '" stroke="' + color + '" stroke-dasharray="4 3" opacity="0.6"/>';
    var step = Math.max(1, Math.ceil(pts.length / 8));
    pts.forEach(function (p, i) {
      if (i % step === 0 || i === pts.length - 1) out += '<text x="' + x(i) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(p.l) + '</text>';
    });
    if (cfg.type === 'bar') {
      var bw = Math.max(4, (W - L - R) / pts.length * 0.6);
      pts.forEach(function (p, i) {
        if (p.v === null) return;
        out += '<rect x="' + (x(i) - bw / 2) + '" y="' + y(Math.max(p.v, min)) + '" width="' + bw + '" height="' + (y(min) - y(Math.max(p.v, min))) + '" rx="3" fill="' + color + '" opacity="' + (cfg.goal && p.v >= cfg.goal ? 1 : 0.7) + '"/>';
      });
    } else {
      var path = '', started = false;
      pts.forEach(function (p, i) {
        if (p.v === null) { started = false; return; }
        path += (started ? 'L' : 'M') + x(i) + ' ' + y(p.v) + ' ';
        started = true;
      });
      out += '<path d="' + path + '" fill="none" stroke="' + color + '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
      pts.forEach(function (p, i) {
        if (p.v !== null) out += '<circle cx="' + x(i) + '" cy="' + y(p.v) + '" r="3" fill="' + color + '"/>';
      });
    }
    svg.innerHTML = out;
  });

  // ───────── 알림 (웹 푸시) ─────────
  var pushBox = $('#push-box');
  if (pushBox) setupPush(pushBox);

  function setupPush(box) {
    var status = $('#push-status', box);
    var csrf = box.getAttribute('data-csrf');
    function say(text) { status.textContent = text; }
    function api(body) {
      return fetch('api/push.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
        body: JSON.stringify(body)
      }).then(function (r) { return r.json(); });
    }
    function keyBytes(b64) {
      var pad = '='.repeat((4 - b64.length % 4) % 4);
      var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
      var out = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
      return out;
    }
    var ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
    var standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      say(ios && !standalone
        ? '아이폰은 사파리 공유 버튼 › 홈 화면에 추가 › 그 아이콘으로 이 화면을 열어야 알림을 켤 수 있어요. (iOS 16.4 이상)'
        : '이 브라우저는 알림을 지원하지 않아요.');
      $all('button', box).forEach(function (b) { b.disabled = true; });
      return;
    }
    navigator.serviceWorker.register('sw.js').then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        say(sub && Notification.permission === 'granted' ? '✅ 이 기기에서 알림을 받고 있어요.' : '이 기기는 아직 알림이 꺼져 있어요.');
      });
    }).catch(function (e) { say('알림 준비 실패: ' + e.message); });

    $('#push-on', box).addEventListener('click', function () {
      var btn = this;
      say('알림 허용을 요청하는 중…');
      Busy.track(Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') throw new Error('알림이 허용되지 않았어요. 설정 앱 › 알림에서 허용해 주세요.');
        return Promise.all([navigator.serviceWorker.ready, api({ action: 'key' })]);
      }).then(function (r) {
        return r[0].pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(r[1].key) });
      }).then(function (sub) {
        return api({ action: 'subscribe', subscription: sub.toJSON() });
      }).then(function (res) {
        if (!res.ok) throw new Error(res.error);
        say('✅ 알림을 켰어요. 테스트 알림을 보내 보세요.');
      }), '이 기기를 알림 받을 곳으로 등록하는 중이에요…', btn).catch(function (e) { say('⚠️ ' + e.message); });
    });

    $('#push-test', box).addEventListener('click', function () {
      say('테스트 알림을 보내는 중…');
      Busy.track(api({ action: 'test' }), '테스트 알림을 보내는 중이에요…', this).then(function (res) {
        say(res.ok ? '🔔 테스트 알림을 보냈어요. 잠시 후 도착해요.' : '⚠️ ' + res.error);
      }, function (e) { say('⚠️ 보내지 못했어요: ' + e.message); });
    });
  }

  // ───────── 식단 입력 화면 ─────────
  var mealForm = $('#meal-form');
  if (mealForm) setupMealForm(mealForm);

  function setupMealForm(form) {
    var foods = JSON.parse($('#foods-data').textContent);
    var initial = JSON.parse($('#items-data').textContent);
    var list = $('#items');
    var template = $('#item-template');

    function addItem(item) {
      var node = template.content.firstElementChild.cloneNode(true);
      list.appendChild(node);
      fill(node, item || {});
      bind(node);
      update();
      return node;
    }

    function fill(node, item) {
      $('.f-name', node).value = item.name || '';
      $('.f-amount', node).value = item.amount || '1인분';
      $('.f-servings', node).value = item.servings != null ? item.servings : 1;
      ['kcal', 'carbs', 'protein', 'fat', 'sodium'].forEach(function (k) {
        $('.f-' + k, node).value = item[k] != null && item[k] !== '' ? round(item[k], 1) : '';
      });
    }

    function read(node) {
      var item = {
        name: $('.f-name', node).value.trim(),
        amount: $('.f-amount', node).value.trim() || '1인분',
        servings: parseFloat($('.f-servings', node).value) || 1
      };
      ['kcal', 'carbs', 'protein', 'fat', 'sodium'].forEach(function (k) { item[k] = parseFloat($('.f-' + k, node).value) || 0; });
      return item;
    }

    function bind(node) {
      var name = $('.f-name', node);
      var box = $('.suggest-list', node);
      name.addEventListener('input', function () {
        var q = name.value.trim().replace(/\s/g, '');
        if (!q) { box.classList.add('hidden'); return; }
        var hits = foods.filter(function (f) { return f.name.replace(/\s/g, '').indexOf(q) >= 0; }).slice(0, 8);
        if (!hits.length) { box.classList.add('hidden'); return; }
        box.innerHTML = hits.map(function (f, i) {
          return '<button type="button" data-i="' + foods.indexOf(f) + '">' + esc(f.name) +
            '<small>' + esc(f.amount) + ' · ' + round(f.kcal) + 'kcal · 탄 ' + round(f.carbs) + ' 단 ' + round(f.protein) + ' 지 ' + round(f.fat) +
            (f.mine ? ' · 우리집' : '') + '</small></button>';
        }).join('');
        box.classList.remove('hidden');
      });
      box.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var f = foods[+btn.getAttribute('data-i')];
        var servings = $('.f-servings', node).value;
        fill(node, f);
        $('.f-servings', node).value = servings || 1;
        box.classList.add('hidden');
        update();
      });
      name.addEventListener('blur', function () { setTimeout(function () { box.classList.add('hidden'); }, 200); });
      $all('input', node).forEach(function (input) { input.addEventListener('input', update); });
      $('.remove', node).addEventListener('click', function () { node.remove(); update(); });
      $all('[data-step]', node).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var input = $('.f-servings', node);
          var v = Math.max(0.25, round((parseFloat(input.value) || 1) + parseFloat(btn.getAttribute('data-step')), 2));
          input.value = v;
          update();
        });
      });
    }

    function update() {
      var total = { kcal: 0, carbs: 0, protein: 0, fat: 0, sodium: 0 };
      $all('.item-row', list).forEach(function (node) {
        var it = read(node);
        Object.keys(total).forEach(function (k) { total[k] += it[k] * it.servings; });
        $('.item-total', node).textContent = it.servings !== 1
          ? '× ' + it.servings + ' = ' + round(it.kcal * it.servings) + 'kcal · 단백질 ' + round(it.protein * it.servings, 1) + 'g'
          : '';
      });
      $('#sum').innerHTML = '<b>' + round(total.kcal) + 'kcal</b><br>탄 ' + round(total.carbs) + ' · 단 ' + round(total.protein) +
        ' · 지 ' + round(total.fat) + ' · 나트륨 ' + round(total.sodium) + 'mg';
    }

    $('#add-item').addEventListener('click', function () { $('.f-name', addItem()).focus(); });
    $all('[data-food]').forEach(function (chip) {
      chip.addEventListener('click', function () { addItem(foods[+chip.getAttribute('data-food')]); });
    });
    $all('[data-copy]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        JSON.parse(chip.getAttribute('data-copy')).forEach(function (it) { addItem(it); });
      });
    });

    form.addEventListener('submit', function (e) {
      var items = $all('.item-row', list).map(read).filter(function (it) { return it.name; });
      if (!items.length) { e.preventDefault(); alert('음식을 하나 이상 입력해 주세요.'); return; }
      $('[name=items_json]').value = JSON.stringify(items);
    });

    if (initial.length) initial.forEach(addItem); else addItem();
  }
})();
