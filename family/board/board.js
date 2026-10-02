/*
 * 가족 전광판
 * - ../api/board.php 를 15초마다 읽고, 내용(version)이 바뀌었을 때만 다시 그린다.
 * - 날씨는 Open-Meteo에서 15분마다 직접 받는다.
 * 오래된 아이패드 사파리에서도 돌도록 ?. 나 ?? 는 쓰지 않는다.
 */
(function () {
  'use strict';

  var POLL_MS = 15 * 1000;
  var WEATHER_MS = 15 * 60 * 1000;
  var NIGHT_START = 22 * 60 + 30;
  var NIGHT_END = 6 * 60;
  var WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];
  var params = parseQuery();

  var state = { data: null, version: null, weather: {}, error: null, wakeUntil: 0, lastMinute: -1 };

  // ───────── 도우미 ─────────
  function $(id) { return document.getElementById(id); }
  function parseQuery() {
    var out = {};
    window.location.search.replace(/^\?/, '').split('&').forEach(function (p) {
      if (!p) return;
      var kv = p.split('=');
      out[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
    });
    return out;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function hhmm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function dayKey(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n) { var x = new Date(d.getTime()); x.setDate(x.getDate() + n); return x; }
  function isNight(d) { var m = d.getHours() * 60 + d.getMinutes(); return m >= NIGHT_START || m < NIGHT_END; }
  function timeAgo(d) {
    var m = Math.round((Date.now() - d.getTime()) / 60000);
    if (m < 1) return '방금';
    if (m < 60) return m + '분 전';
    var h = Math.round(m / 60);
    return h < 24 ? h + '시간 전' : Math.round(h / 24) + '일 전';
  }
  function fetchJSON(url) {
    return fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  function shortDish(dish) { return String(dish).split(/\s*[·,+&]\s*/)[0]; }

  // ───────── 데이터 ─────────
  function loadData() {
    return fetchJSON('../api/board.php').then(function (data) {
      if (data.ok === false && data.error === 'login') {
        window.location.href = '../login.php?next=' + encodeURIComponent('board/');
        return;
      }
      if (data.ok === false) throw new Error(data.error);
      state.error = null;
      if (data.version === state.version) return;
      var locationsChanged = !state.data || JSON.stringify(state.data.locations) !== JSON.stringify(data.locations);
      state.data = data;
      state.version = data.version;
      if (locationsChanged) loadWeather();
      render();
    }).catch(function (e) {
      state.error = String(e && e.message || e);
      renderStatus();
    });
  }

  function events() {
    return ((state.data && state.data.events) || []).map(function (e) {
      return { title: e.title, start: new Date(e.start), end: new Date(e.end), allDay: e.allDay, color: e.color || '#60a5fa', location: e.location || '' };
    });
  }

  // ───────── 날씨 ─────────
  var CODES = {
    0: ['맑음', '☀️', '🌙'], 1: ['대체로 맑음', '🌤', '🌙'], 2: ['구름 조금', '⛅️', '☁️'], 3: ['흐림', '☁️', '☁️'],
    45: ['안개', '🌫', '🌫'], 48: ['안개', '🌫', '🌫'], 51: ['이슬비', '🌦', '🌧'], 53: ['이슬비', '🌦', '🌧'], 55: ['이슬비', '🌧', '🌧'],
    56: ['어는 비', '🌧', '🌧'], 57: ['어는 비', '🌧', '🌧'], 61: ['약한 비', '🌧', '🌧'], 63: ['비', '🌧', '🌧'], 65: ['강한 비', '🌧', '🌧'],
    66: ['어는 비', '🌧', '🌧'], 67: ['어는 비', '🌧', '🌧'], 71: ['약한 눈', '🌨', '🌨'], 73: ['눈', '❄️', '❄️'], 75: ['많은 눈', '❄️', '❄️'],
    77: ['싸락눈', '🌨', '🌨'], 80: ['소나기', '🌦', '🌧'], 81: ['소나기', '🌧', '🌧'], 82: ['강한 소나기', '⛈', '⛈'],
    85: ['눈 소나기', '🌨', '🌨'], 86: ['눈 소나기', '🌨', '🌨'], 95: ['뇌우', '⛈', '⛈'], 96: ['뇌우', '⛈', '⛈'], 99: ['뇌우', '⛈', '⛈']
  };
  function code(c, isDay) { var e = CODES[c] || ['-', '🌡', '🌡']; return { text: e[0], icon: isDay === 0 ? e[2] : e[1] }; }
  function airGrade(pm10, pm25) {
    var g10 = pm10 == null ? 0 : pm10 <= 30 ? 0 : pm10 <= 80 ? 1 : pm10 <= 150 ? 2 : 3;
    var g25 = pm25 == null ? 0 : pm25 <= 15 ? 0 : pm25 <= 35 ? 1 : pm25 <= 75 ? 2 : 3;
    var g = Math.max(g10, g25);
    return { grade: g, text: ['좋음', '보통', '나쁨', '매우 나쁨'][g] };
  }

  function loadWeather() {
    var locations = (state.data && state.data.locations) || [];
    Promise.all(locations.map(function (loc) {
      var q = 'latitude=' + loc.lat + '&longitude=' + loc.lon + '&timezone=Asia%2FSeoul';
      return Promise.all([
        fetchJSON('https://api.open-meteo.com/v1/forecast?' + q +
          '&current=temperature_2m,apparent_temperature,weather_code,is_day' +
          '&hourly=temperature_2m,weather_code,precipitation_probability,is_day' +
          '&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max&forecast_days=2'),
        fetchJSON('https://air-quality-api.open-meteo.com/v1/air-quality?' + q + '&current=pm10,pm2_5').catch(function () { return null; })
      ]).then(function (r) { state.weather[loc.name] = { f: r[0], a: r[1] }; }).catch(function () {});
    })).then(function () { renderWeather(); renderNotice(new Date()); });
  }

  /// 오늘 남은 시간 중 비 올 확률이 가장 높은 시각
  function rainPeak(f) {
    if (!f || !f.hourly) return null;
    var now = new Date(), best = null;
    for (var i = 0; i < f.hourly.time.length; i++) {
      var t = new Date(f.hourly.time[i]);
      if (dayKey(t) !== dayKey(now) || t.getHours() < now.getHours()) continue;
      var p = f.hourly.precipitation_probability[i];
      if (p != null && (!best || p > best.p)) best = { p: p, h: t.getHours() };
    }
    return best;
  }

  // ───────── 그리기 ─────────
  function render() {
    var now = new Date();
    renderClock(now);
    renderNotice(now);
    renderEvents(now);
    renderDinner(now);
    renderWeather();
    renderShopping();
    renderPeople();
    renderStatus();
    renderNight(now);
  }

  function renderClock(now) {
    $('clock').textContent = hhmm(now);
    $('date').textContent = (now.getMonth() + 1) + '월 ' + now.getDate() + '일 ' + WEEKDAYS[now.getDay()] + '요일';
  }

  function renderNotice(now) {
    var tips = [];
    var locations = (state.data && state.data.locations) || [];
    var coldest = null, rainy = [], dusty = [];
    locations.forEach(function (loc) {
      var w = state.weather[loc.name];
      if (!w) return;
      var peak = rainPeak(w.f);
      if (peak && peak.p >= 50) rainy.push(loc.name + ' ' + peak.h + '시');
      if (w.f.daily) { var min = w.f.daily.temperature_2m_min[0]; if (coldest === null || min < coldest) coldest = min; }
      if (w.a && w.a.current) { var g = airGrade(w.a.current.pm10, w.a.current.pm2_5); if (g.grade >= 2) dusty.push(loc.name); }
    });
    if (rainy.length) tips.push('☂️ 우산 챙기세요 · ' + rainy.join(', '));
    if (coldest !== null && coldest <= 5) tips.push('🧥 아침 ' + Math.round(coldest) + '°, 따뜻하게');
    if (dusty.length) tips.push('😷 미세먼지 나쁨 · ' + dusty.join(', '));
    dinnerConflicts(now).forEach(function (c) { tips.push('🍻 오늘 저녁 ' + c); });
    ((state.data && state.data.sick) || []).forEach(function (k) {
      var next = (k.next || []).map(function (n) {
        var at = new Date(n.at);
        return n.name.replace(' 계열', '') + ' ' + (at <= now ? '지금 가능' : hhmm(at) + '부터');
      }).join(' · ');
      tips.unshift('🤒 ' + k.name + (k.temp != null ? ' ' + k.temp.toFixed(1) + '° (' + hhmm(new Date(k.at)) + ')' : '') + (next ? ' · ' + next : ''));
    });
    ((state.data && state.data.people) || []).forEach(function (p) {
      if (p.stale) tips.push('⚠️ ' + p.name + ' 건강 기록이 이틀째 없어요');
    });

    var box = $('notice');
    if (!tips.length) { box.className = 'notice hidden'; return; }
    box.className = 'notice';
    box.innerHTML = tips.map(function (t) { return '<span>' + esc(t) + '</span>'; }).join('');
  }

  function dinnerConflicts(now) {
    return events().filter(function (e) {
      return !e.allDay && dayKey(e.start) === dayKey(now) && e.start.getHours() >= 17 && e.start.getHours() < 21;
    }).map(function (e) { return hhmm(e.start) + ' ' + e.title; });
  }

  function renderEvents(now) {
    var list = events();
    var today = startOfDay(now);
    var todays = list.filter(function (e) {
      return e.allDay ? startOfDay(e.start) <= today && today < e.end : dayKey(e.start) === dayKey(now);
    });
    $('today-events').innerHTML = todays.length ? todays.slice(0, 7).map(function (e) {
      var cls = 'event';
      if (!e.allDay && e.end < now) cls += ' past';
      else if (!e.allDay && e.start <= now) cls += ' now';
      return '<div class="' + cls + '"><span class="time">' + (e.allDay ? '종일' : hhmm(e.start)) + '</span>' +
        '<span class="bar" style="background:' + esc(e.color) + '"></span><div class="body">' +
        '<div class="title">' + esc(e.title) + '</div>' + (e.location ? '<div class="loc">' + esc(e.location) + '</div>' : '') + '</div></div>';
    }).join('') : '<div class="empty">오늘은 일정이 없어요</div>';

    var later = list.filter(function (e) { return startOfDay(e.start) > today; }).slice(0, 7);
    $('upcoming').innerHTML = later.length ? later.map(function (e) {
      var diff = Math.round((startOfDay(e.start) - today) / 86400000);
      var label = diff === 1 ? '내일' : (e.start.getMonth() + 1) + '/' + e.start.getDate() + ' ' + WEEKDAYS[e.start.getDay()];
      return '<div class="item"><span class="day">' + label + '</span><span class="t">' + (e.allDay ? '종일' : hhmm(e.start)) + '</span>' +
        '<span class="n">' + esc(e.title) + '</span></div>';
    }).join('') : '<div class="empty">다가오는 일정이 없어요</div>';
  }

  function renderDinner(now) {
    var d = state.data;
    if (!d) return;
    var tonight = d.dinners[dayKey(now)];
    $('dinner-time').textContent = d.dinnerTime || '';
    var html = '';
    if (tonight) {
      html += '<div class="dish">' + esc(tonight.dish) + '</div>';
      if (tonight.ingredients && tonight.ingredients.length) html += '<div class="ingredients">재료 · ' + esc(tonight.ingredients.join(', ')) + '</div>';
      if (tonight.note) html += '<div class="ingredients">📝 ' + esc(tonight.note) + '</div>';
    } else {
      html += '<div class="dish none">아직 메뉴를 안 정했어요</div>';
    }
    dinnerConflicts(now).forEach(function (c) { html += '<div class="alert">⚠️ ' + esc(c) + '</div>'; });

    var labels = { home: '집에서', late: '늦게', out: '따로', unknown: '?' };
    html += '<div class="attend">' + (d.attendance || []).map(function (p) {
      var s = p.status in labels ? p.status : 'unknown';
      return '<span class="pill"><span class="e">' + esc(p.emoji) + '</span>' + esc(p.name) +
        '<span class="s ' + s + '">' + labels[s] + (s === 'late' && p.late ? ' ' + esc(p.late) : '') + '</span></span>';
    }).join('') + '</div>';

    if (d.outcome) {
      var o = d.outcome;
      html += '<span class="badge green">' + (o.place === 'out' ? '🍽 외식했어요' : (Number(o.together) ? '🏠 함께 먹었어요' : '따로 먹었어요')) + '</span>';
    }
    if (d.newFoods && d.newFoods.length) html += '<span class="badge violet">⭐ 새 음식 도전 · ' + esc(d.newFoods.join(', ')) + '</span>';
    $('dinner').innerHTML = html;

    var week = '';
    for (var i = 1; i <= 6; i++) {
      var day = addDays(startOfDay(now), i);
      var p = d.dinners[dayKey(day)];
      week += '<div class="row"><span class="d">' + (i === 1 ? '내일' : WEEKDAYS[day.getDay()] + ' ' + day.getDate() + '일') + '</span>' +
        '<span class="m' + (p ? '' : ' none') + '">' + (p ? esc(p.dish) : '—') + '</span></div>';
    }
    $('week').innerHTML = week;
  }

  function renderWeather() {
    var locations = (state.data && state.data.locations) || [];
    var now = new Date();
    var html = '';
    locations.forEach(function (loc, i) {
      var w = state.weather[loc.name];
      if (!w || !w.f.current) return;
      var c = code(w.f.current.weather_code, w.f.current.is_day);
      var peak = rainPeak(w.f);
      var air = w.a && w.a.current ? airGrade(w.a.current.pm10, w.a.current.pm2_5) : null;
      var line = [c.text];
      if (peak && peak.p >= 30) line.push('<span class="rain">비 ' + peak.p + '%</span>');
      if (air) line.push('미세먼지 <span class="air-' + air.grade + '">' + air.text + '</span>');
      html += '<div class="wx"><div class="ic">' + c.icon + '</div><div class="info">' +
        '<div class="name">' + esc(loc.name) + '<span class="role">' + esc(loc.title || '') + '</span></div>' +
        '<div class="line">' + line.join(' · ') + '</div></div>' +
        '<div class="t"><div class="now">' + Math.round(w.f.current.temperature_2m) + '°</div>' +
        '<div class="range">' + Math.round(w.f.daily.temperature_2m_min[0]) + '° / ' + Math.round(w.f.daily.temperature_2m_max[0]) + '°</div></div></div>';

      if (i === 0) {
        // 위쪽 큰 날씨와 3시간 간격 예보는 '집' 기준
        $('now-weather').innerHTML = '<div class="row"><span class="icon">' + c.icon + '</span><span class="temp">' +
          Math.round(w.f.current.temperature_2m) + '°</span></div><div class="desc"><b>' + esc(loc.name) + '</b> · ' + c.text +
          ' · 체감 ' + Math.round(w.f.current.apparent_temperature) + '° · ' + Math.round(w.f.daily.temperature_2m_min[0]) + '° / ' +
          Math.round(w.f.daily.temperature_2m_max[0]) + '°</div>';
        var cells = '', n = 0;
        for (var k = 0; k < w.f.hourly.time.length && n < 6; k++) {
          var t = new Date(w.f.hourly.time[k]);
          if (t <= now || t.getHours() % 3 !== 0) continue;
          var hc = code(w.f.hourly.weather_code[k], w.f.hourly.is_day[k]);
          cells += '<div class="h">' + t.getHours() + '시<span class="e">' + hc.icon + '</span><b>' + Math.round(w.f.hourly.temperature_2m[k]) + '°</b></div>';
          n++;
        }
        html += '<div class="hourly">' + cells + '</div>';
      }
    });
    $('weather').innerHTML = html || '<div class="empty">날씨를 불러오는 중…</div>';
  }

  function renderShopping() {
    var items = (state.data && state.data.shopping) || [];
    $('shop-count').textContent = items.length ? items.length + '개' : '';
    var shown = items.slice(0, 14);
    $('shopping').innerHTML = items.length
      ? shown.map(function (s) { return '<span class="chip">' + esc(s) + '</span>'; }).join('') +
        (items.length > shown.length ? '<span class="chip more">+' + (items.length - shown.length) + '</span>' : '')
      : '<div class="empty">살 것이 없어요</div>';
  }

  function ring(score, key) {
    var colors = { recover: '#f87171', pace: '#fbbf24', ready: '#34d399', go: '#2dd4bf' };
    var color = colors[key] || '#5d6a7e';
    var r = 30, c = 2 * Math.PI * r, frac = score == null ? 0 : Math.max(0, Math.min(1, score / 10));
    return '<svg class="ring" viewBox="0 0 76 76"><circle cx="38" cy="38" r="' + r + '" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="7"/>' +
      '<circle cx="38" cy="38" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="7" stroke-linecap="round" ' +
      'stroke-dasharray="' + (c * frac) + ' ' + c + '" transform="rotate(-90 38 38)"/>' +
      '<text x="38" y="45" text-anchor="middle" font-size="' + (score == null ? 16 : 20) + '">' + (score == null ? '–' : score.toFixed(1)) + '</text></svg>';
  }

  function renderPeople() {
    var people = (state.data && state.data.people) || [];
    $('people').innerHTML = people.map(function (p) {
      var stats = [];
      if (p.steps != null) stats.push('<span>걸음 <b>' + p.steps.toLocaleString() + '</b></span>');
      if (p.sleepMin != null) stats.push('<span>수면 <b>' + Math.floor(p.sleepMin / 60) + '시간 ' + (p.sleepMin % 60) + '분</b></span>');
      if (!stats.length) stats.push('<span>오늘 기록 기다리는 중</span>');
      (p.meds || []).forEach(function (m) {
        stats.push('<span>💊 ' + esc(m.time) + ' <b>' + (m.taken ? '✓' : '아직') + '</b></span>');
      });
      return '<div class="person">' + ring(p.readiness, p.levelKey) + '<div><div class="who">' + esc(p.emoji + ' ' + p.name) +
        (p.level ? '<span class="lv lv-' + esc(p.levelKey) + '">' + esc(p.level) + '</span>' : '') + '</div>' +
        '<div class="stats">' + stats.join('') + '</div></div></div>';
    }).join('');
  }

  function renderStatus() {
    var parts = ((state.data && state.data.people) || []).filter(function (p) { return p.updatedAt; }).map(function (p) {
      return esc(p.name) + ' 건강 기록 ' + timeAgo(new Date(p.updatedAt));
    });
    var text = parts.join(' · ');
    if (state.error) text = '<span class="warn">연결 확인 중 (' + esc(state.error) + ')</span>' + (text ? ' · ' + text : '');
    $('status').innerHTML = text;
  }

  function renderNight(now) {
    var night = isNight(now) && Date.now() > state.wakeUntil && !params.nonight;
    $('night').className = night ? 'night' : 'night hidden';
    if (!night) return;
    $('night-clock').textContent = hhmm(now);
    var target = addDays(startOfDay(now), now.getHours() < 12 ? 0 : 1);
    var first = events().filter(function (e) { return !e.allDay && dayKey(e.start) === dayKey(target); })[0];
    $('night-sub').textContent = first ? (now.getHours() < 12 ? '오늘 ' : '내일 ') + hhmm(first.start) + ' ' + first.title : '';
  }

  // ───────── 화면 유지 · 번인 방지 ─────────
  function requestWakeLock() {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
    navigator.wakeLock.request('screen').catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { requestWakeLock(); loadData(); }
  });
  document.addEventListener('click', function () {
    if (isNight(new Date())) { state.wakeUntil = Date.now() + 60 * 1000; render(); }
  });
  function shiftForBurnIn() {
    var t = 'translate(' + Math.round(Math.random() * 12 - 6) + 'px,' + Math.round(Math.random() * 8 - 4) + 'px)';
    $('board').style.transform = t;
    $('night').style.transform = t;
  }

  // ───────── 시작 ─────────
  function tick() {
    var now = new Date();
    $('clock').textContent = hhmm(now);
    if (isNight(now)) $('night-clock').textContent = hhmm(now);
    if (now.getMinutes() !== state.lastMinute) {
      state.lastMinute = now.getMinutes();
      if (state.data) render(); else renderClock(now);
    }
    if (now.getHours() === 4 && now.getMinutes() === 0 && now.getSeconds() < 2) window.location.reload();
  }

  renderClock(new Date());
  loadData();
  requestWakeLock();
  setInterval(tick, 1000);
  setInterval(loadData, POLL_MS);
  setInterval(loadWeather, WEATHER_MS);
  setInterval(shiftForBurnIn, 5 * 60 * 1000);
})();
