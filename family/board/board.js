/*
 * 가족 전광판
 * - ../api/board.php : 우리집 건강 사이트(DB)의 일정 · 저녁 · 장보기 · 건강 요약 (로그인 필요)
 * - 날씨는 Open-Meteo에서 직접 받아온다 (키 필요 없음)
 * 오래된 아이패드 사파리(iOS 12)에서도 돌도록 옵셔널 체이닝(?.)이나 ?? 는 쓰지 않는다.
 */
(function () {
  'use strict';

  var BOARD_VERSION = 1;
  var DATA_REFRESH_MS = 60 * 1000;
  var WEATHER_REFRESH_MS = 15 * 60 * 1000;
  var STALE_HOURS = 6;
  var NIGHT_START = 22 * 60 + 30; // 22:30
  var NIGHT_END = 6 * 60;         // 06:00

  var params = parseQuery();
  var MEMBERS = (params.members || 'dad,mom').split(',');

  var DEFAULT_LOCATIONS = [
    { role: 'home', title: '집', name: '은평구', lat: 37.6027, lon: 126.9291 },
    { role: 'work', title: '회사', name: '소공동', lat: 37.5638, lon: 126.9797 },
    { role: 'parents', title: '부모님 댁', name: '평택', lat: 36.9921, lon: 127.1128 }
  ];

  var state = {
    members: {},      // id → 앱이 올린 요약
    weather: {},      // 지역 이름 → 날씨
    locations: DEFAULT_LOCATIONS,
    wakeUntil: 0
  };

  // ───────── 유틸 ─────────

  function $(id) { return document.getElementById(id); }

  function parseQuery() {
    var result = {};
    var query = window.location.search.replace(/^\?/, '');
    if (!query) return result;
    query.split('&').forEach(function (pair) {
      var parts = pair.split('=');
      result[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1] || '');
    });
    return result;
  }

  function escapeHTML(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  var WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];

  function dayKey(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
  }

  function addDays(date, days) {
    var d = new Date(date.getTime());
    d.setDate(d.getDate() + days);
    return d;
  }

  function hhmm(date) { return pad(date.getHours()) + ':' + pad(date.getMinutes()); }

  function minutesOfDay(date) { return date.getHours() * 60 + date.getMinutes(); }

  function isNight(now) {
    var m = minutesOfDay(now);
    return m >= NIGHT_START || m < NIGHT_END;
  }

  function timeAgo(date) {
    var minutes = Math.round((Date.now() - date.getTime()) / 60000);
    if (minutes < 1) return '방금';
    if (minutes < 60) return minutes + '분 전';
    var hours = Math.round(minutes / 60);
    if (hours < 24) return hours + '시간 전';
    return Math.round(hours / 24) + '일 전';
  }

  function fetchJSON(url) {
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    return fetch(url + sep + 't=' + Date.now(), { cache: 'no-store' }).then(function (response) {
      if (!response.ok) throw new Error(response.status);
      return response.json();
    });
  }

  // ───────── 데이터 (아이폰 → NAS) ─────────

  /// 사이트의 api/board.php 가 DB에서 가족 요약을 만들어 돌려준다.
  function loadMembers() {
    return fetchJSON('../api/board.php').then(function (data) {
      if (data.ok === false && data.error === 'login') {
        window.location.href = '../login.php?next=' + encodeURIComponent('board/');
        return;
      }
      if (data.ok === false) throw new Error(data.error);
      var members = data.members || {};
      Object.keys(members).forEach(function (id) {
        var member = members[id];
        member.updatedAtDate = new Date(member.updatedAt);
        state.members[id] = member;
        if (MEMBERS.indexOf(id) < 0) MEMBERS.push(id);
      });
      state.apiError = null;
      var latest = latestMember();
      if (latest && latest.locations && latest.locations.length) {
        var changed = JSON.stringify(latest.locations) !== JSON.stringify(state.locations);
        state.locations = latest.locations;
        if (changed) loadWeather();
      }
    }).catch(function (error) {
      state.apiError = String(error && error.message || error);
    });
  }

  function memberList() {
    return MEMBERS.map(function (id) { return state.members[id]; }).filter(Boolean);
  }

  function latestMember() {
    var list = memberList();
    list.sort(function (a, b) { return b.updatedAtDate - a.updatedAtDate; });
    return list[0];
  }

  /// 두 폰이 올린 일정을 합치고 겹치는 것은 하나로
  function mergedEvents() {
    var seen = {};
    var events = [];
    memberList().forEach(function (member) {
      (member.events || []).forEach(function (event) {
        var key = event.title + '|' + event.start;
        if (seen[key]) return;
        seen[key] = true;
        events.push({
          title: event.title,
          start: new Date(event.start),
          end: new Date(event.end),
          allDay: !!event.allDay,
          color: event.color || '#4da3ff'
        });
      });
    });
    events.sort(function (a, b) {
      if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;
      return a.start - b.start;
    });
    return events;
  }

  /// 날짜별 저녁 계획 (최근에 올린 폰 기준)
  function mergedDinners() {
    var byDate = {};
    var list = memberList();
    list.sort(function (a, b) { return a.updatedAtDate - b.updatedAtDate; });
    list.forEach(function (member) {
      (member.dinners || []).forEach(function (dinner) { byDate[dinner.date] = dinner; });
    });
    return byDate;
  }

  // ───────── 날씨 (Open-Meteo) ─────────

  var WEATHER_CODES = {
    0: ['맑음', '☀️', '🌙'], 1: ['대체로 맑음', '🌤', '🌙'], 2: ['구름 조금', '⛅️', '☁️'], 3: ['흐림', '☁️', '☁️'],
    45: ['안개', '🌫', '🌫'], 48: ['안개', '🌫', '🌫'],
    51: ['이슬비', '🌦', '🌧'], 53: ['이슬비', '🌦', '🌧'], 55: ['이슬비', '🌧', '🌧'],
    56: ['어는 비', '🌧', '🌧'], 57: ['어는 비', '🌧', '🌧'],
    61: ['약한 비', '🌧', '🌧'], 63: ['비', '🌧', '🌧'], 65: ['강한 비', '🌧', '🌧'],
    66: ['어는 비', '🌧', '🌧'], 67: ['어는 비', '🌧', '🌧'],
    71: ['약한 눈', '🌨', '🌨'], 73: ['눈', '❄️', '❄️'], 75: ['많은 눈', '❄️', '❄️'], 77: ['싸락눈', '🌨', '🌨'],
    80: ['소나기', '🌦', '🌧'], 81: ['소나기', '🌧', '🌧'], 82: ['강한 소나기', '⛈', '⛈'],
    85: ['눈 소나기', '🌨', '🌨'], 86: ['눈 소나기', '🌨', '🌨'],
    95: ['뇌우', '⛈', '⛈'], 96: ['뇌우 · 우박', '⛈', '⛈'], 99: ['뇌우 · 우박', '⛈', '⛈']
  };

  function describeCode(code, isDay) {
    var entry = WEATHER_CODES[code] || ['-', '🌡', '🌡'];
    return { text: entry[0], icon: isDay === 0 ? entry[2] : entry[1] };
  }

  /// 미세먼지 등급 (환경부 기준, PM10과 PM2.5 중 나쁜 쪽)
  function airGrade(pm10, pm25) {
    function grade10(v) { return v <= 30 ? 0 : v <= 80 ? 1 : v <= 150 ? 2 : 3; }
    function grade25(v) { return v <= 15 ? 0 : v <= 35 ? 1 : v <= 75 ? 2 : 3; }
    if (pm10 == null && pm25 == null) return null;
    var g = Math.max(pm10 == null ? 0 : grade10(pm10), pm25 == null ? 0 : grade25(pm25));
    return [
      { text: '좋음', cls: 'air-good' }, { text: '보통', cls: 'air-normal' },
      { text: '나쁨', cls: 'air-bad' }, { text: '매우 나쁨', cls: 'air-worst' }
    ][g];
  }

  function loadWeather() {
    return Promise.all(state.locations.map(function (loc) {
      var base = 'latitude=' + loc.lat + '&longitude=' + loc.lon + '&timezone=Asia%2FSeoul';
      var forecast = fetchJSON('https://api.open-meteo.com/v1/forecast?' + base +
        '&current=temperature_2m,apparent_temperature,weather_code,is_day' +
        '&hourly=temperature_2m,weather_code,precipitation_probability,is_day' +
        '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max' +
        '&forecast_days=2');
      var air = fetchJSON('https://air-quality-api.open-meteo.com/v1/air-quality?' + base +
        '&current=pm10,pm2_5').catch(function () { return null; });
      return Promise.all([forecast, air]).then(function (results) {
        state.weather[loc.name] = { forecast: results[0], air: results[1], loadedAt: new Date() };
      }).catch(function () { /* 다음에 다시 */ });
    })).then(render);
  }

  /// 오늘 남은 시간 중 비 올 확률이 가장 높은 시각
  function rainPeak(forecast) {
    var hourly = forecast.hourly;
    if (!hourly) return null;
    var now = new Date();
    var todayKey = dayKey(now);
    var best = null;
    for (var i = 0; i < hourly.time.length; i++) {
      var time = new Date(hourly.time[i]);
      if (dayKey(time) !== todayKey || time.getHours() < now.getHours()) continue;
      var p = hourly.precipitation_probability[i];
      if (p != null && (!best || p > best.p)) best = { p: p, hour: time.getHours() };
    }
    return best;
  }

  // ───────── 그리기 ─────────

  function render() {
    var now = new Date();
    renderPeriod(now);
    renderHeader(now);
    renderAdvice(now);
    renderSchedule(now);
    renderDinner(now);
    renderWeather(now);
    renderHealth();
    renderStatus();
    renderNight(now);
  }

  function renderPeriod(now) {
    var h = now.getHours();
    var period = h >= 5 && h < 10 ? 'morning' : h >= 16 && h < 21 ? 'evening' : 'day';
    document.body.className = period;
  }

  function renderHeader(now) {
    $('date').textContent = (now.getMonth() + 1) + '월 ' + now.getDate() + '일 ' + WEEKDAYS[now.getDay()] + '요일';
    var h = now.getHours();
    var greeting = h < 5 ? '늦은 밤이에요 🌙' : h < 10 ? '좋은 아침이에요 ☀️' : h < 14 ? '맛있는 점심 드세요 🍚'
      : h < 18 ? '오후도 힘내요 💪' : h < 21 ? '오늘도 수고했어요 🏡' : '푹 쉬어요 🌙';
    $('greeting').textContent = greeting;
    $('clock').textContent = hhmm(now);

    var home = state.locations[0];
    var weather = home && state.weather[home.name];
    if (weather && weather.forecast.current) {
      var cur = weather.forecast.current;
      var info = describeCode(cur.weather_code, cur.is_day);
      $('now-weather').innerHTML =
        '<span class="icon">' + info.icon + '</span><span class="temp">' + Math.round(cur.temperature_2m) + '°</span>' +
        '<div class="desc">' + escapeHTML(home.name) + ' · ' + info.text + ' · 체감 ' + Math.round(cur.apparent_temperature) + '°</div>';
    }
  }

  function renderAdvice(now) {
    var tips = [];
    var rainy = [];
    var coldest = null;
    var worstAir = null;
    state.locations.forEach(function (loc) {
      var weather = state.weather[loc.name];
      if (!weather) return;
      var peak = rainPeak(weather.forecast);
      if (peak && peak.p >= 50) rainy.push(loc.name + ' ' + peak.hour + '시 ' + peak.p + '%');
      var daily = weather.forecast.daily;
      if (daily && daily.temperature_2m_min) {
        var min = daily.temperature_2m_min[0];
        if (coldest === null || min < coldest) coldest = min;
      }
      if (weather.air && weather.air.current) {
        var grade = airGrade(weather.air.current.pm10, weather.air.current.pm2_5);
        if (grade && (grade.text === '나쁨' || grade.text === '매우 나쁨')) worstAir = loc.name + ' 미세먼지 ' + grade.text;
      }
    });
    if (rainy.length) tips.push('☂️ 우산 챙기세요 (' + rainy.join(', ') + ')');
    if (coldest !== null && coldest <= 5) tips.push('🧥 아침 ' + Math.round(coldest) + '°, 따뜻하게 입혀요');
    if (worstAir) tips.push('😷 ' + worstAir + ', 마스크');

    var conflicts = dinnerConflicts(now);
    if (conflicts.length && now.getHours() < 21) tips.push('🍻 오늘 저녁 ' + conflicts[0]);

    var advice = $('advice');
    if (!tips.length) {
      advice.className = 'advice hidden';
      return;
    }
    advice.className = 'advice';
    advice.innerHTML = tips.map(function (t) { return '<span>' + escapeHTML(t) + '</span>'; }).join('');
  }

  function dinnerConflicts(now) {
    var todayKey = dayKey(now);
    return mergedEvents().filter(function (e) {
      if (e.allDay || dayKey(e.start) !== todayKey) return false;
      var h = e.start.getHours();
      return h >= 17 && h < 21;
    }).map(function (e) { return hhmm(e.start) + ' ' + e.title; });
  }

  function renderSchedule(now) {
    var events = mergedEvents();
    var container = $('schedule');
    if (!memberList().length) {
      container.innerHTML = '<div class="empty">아직 정보가 없어요.</div>';
      return;
    }
    var today = startOfDay(now);
    var html = '';
    var rows = 0;
    var maxRows = 13;
    for (var offset = 0; offset < 7 && rows < maxRows; offset++) {
      var day = addDays(today, offset);
      var key = dayKey(day);
      var dayEvents = events.filter(function (e) {
        if (e.allDay) return startOfDay(e.start) <= day && day < e.end;
        return dayKey(e.start) === key;
      });
      if (offset > 0 && !dayEvents.length) continue;

      var label = offset === 0 ? '오늘' : offset === 1 ? '내일' : (day.getMonth() + 1) + '/' + day.getDate() + ' (' + WEEKDAYS[day.getDay()] + ')';
      html += '<div class="day-group' + (offset === 0 ? ' today' : '') + '">';
      html += '<div class="day-label' + (offset > 1 ? ' later' : '') + '">' + label + '</div>';
      if (!dayEvents.length) html += '<div class="empty">오늘은 일정이 없어요</div>';
      dayEvents.forEach(function (e) {
        if (rows >= maxRows) return;
        rows++;
        var cls = 'event';
        if (offset === 0 && !e.allDay) {
          if (e.end < now) cls += ' past';
          else if (e.start <= now) cls += ' now';
        }
        html += '<div class="' + cls + '"><span class="bar" style="background:' + escapeHTML(e.color) + '"></span>' +
          '<span class="time">' + (e.allDay ? '종일' : hhmm(e.start)) + '</span>' +
          '<span class="title">' + escapeHTML(e.title) + '</span></div>';
      });
      html += '</div>';
    }
    container.innerHTML = html;
  }

  function renderDinner(now) {
    var dinners = mergedDinners();
    var today = startOfDay(now);
    var tonight = dinners[dayKey(today)];
    var latest = latestMember();
    var dinnerTime = latest && latest.dinnerTime ? latest.dinnerTime : '18:30';
    var conflicts = dinnerConflicts(now);

    var html;
    if (tonight) {
      html = '<div class="tonight"><div class="label">오늘 저녁 ' + escapeHTML(dinnerTime) + '</div>' +
        '<div class="dish">' + escapeHTML(tonight.dish) + '</div>';
      if (tonight.ingredients && tonight.ingredients.length) {
        html += '<div class="meta">재료: ' + escapeHTML(tonight.ingredients.slice(0, 6).join(', ')) + '</div>';
      }
    } else {
      html = '<div class="tonight none"><div class="label">오늘 저녁 ' + escapeHTML(dinnerTime) + '</div>' +
        '<div class="dish">아직 메뉴를 안 정했어요</div>';
    }
    if (conflicts.length) html += '<div class="conflict">⚠️ ' + escapeHTML(conflicts.join(', ')) + '</div>';
    // 누가 함께 먹는지 · 아이 반응
    var family = latest && latest.attendance;
    if (family && family.length) {
      html += '<div class="attend">' + family.map(function (p) {
        return '<span class="who">' + escapeHTML(p.emoji + ' ' + p.name) + ' <b>' + escapeHTML(p.status) + '</b></span>';
      }).join('') + '</div>';
    }
    if (latest && latest.kidNote) html += '<div class="meta">' + escapeHTML(latest.kidNote) + '</div>';
    html += '</div>';
    $('tonight').innerHTML = html;

    var week = '';
    for (var i = 0; i < 7; i++) {
      var day = addDays(today, i);
      var plan = dinners[dayKey(day)];
      week += '<div class="wd' + (i === 0 ? ' today' : '') + '"><div class="d">' + (i === 0 ? '오늘' : WEEKDAYS[day.getDay()]) + '</div>' +
        '<div class="m' + (plan ? '' : ' none') + '">' + (plan ? escapeHTML(shortDish(plan.dish)) : '·') + '</div></div>';
    }
    $('week-dinners').innerHTML = week;

    var shopping = latest && latest.shopping;
    if (shopping && shopping.remaining > 0) {
      $('shopping').innerHTML = '<div class="head">🛒 장보기 <span class="count">' + shopping.remaining + '개</span></div>' +
        shopping.items.slice(0, 10).map(function (item) { return '<span class="chip">' + escapeHTML(item) + '</span>'; }).join('');
    } else if (shopping) {
      $('shopping').innerHTML = '<div class="head">🛒 장보기 목록이 비어 있어요</div>';
    } else {
      $('shopping').innerHTML = '';
    }
  }

  /// 주간 칸에는 첫 메뉴만 (예: '된장찌개 · 계란말이' → '된장찌개')
  function shortDish(dish) {
    return String(dish).split(/[·,+&]/)[0].trim();
  }

  function renderWeather(now) {
    var html = '';
    state.locations.forEach(function (loc, index) {
      var weather = state.weather[loc.name];
      if (!weather || !weather.forecast.current) return;
      var f = weather.forecast;
      var info = describeCode(f.current.weather_code, f.current.is_day);
      var daily = f.daily;
      var peak = rainPeak(f);
      var grade = weather.air && weather.air.current ? airGrade(weather.air.current.pm10, weather.air.current.pm2_5) : null;

      var parts = ['<span class="seg">' + info.text + '</span>'];
      if (peak && peak.p >= 30) parts.push('<span class="seg rain">비 ' + peak.p + '% ' + peak.hour + '시</span>');
      if (grade) parts.push('<span class="seg">미세먼지 <span class="' + grade.cls + '">' + grade.text + '</span></span>');
      var line = parts.join(' · ');

      html += '<div class="place"><div class="icon">' + info.icon + '</div><div class="info">' +
        '<div class="name">' + escapeHTML(loc.name) + '<span class="role">' + escapeHTML(loc.title || '') + '</span></div>' +
        '<div class="line">' + line + '</div></div>' +
        '<div class="temps"><div class="now">' + Math.round(f.current.temperature_2m) + '°</div>' +
        (daily ? '<div class="range">' + Math.round(daily.temperature_2m_min[0]) + '° / ' + Math.round(daily.temperature_2m_max[0]) + '°</div>' : '') +
        '</div></div>';

      if (index === 0) html += hourlyStrip(f, now);
    });
    $('weather').innerHTML = html || '<div class="empty">날씨를 불러오지 못했어요. 인터넷 연결을 확인해 주세요.</div>';
  }

  /// 집 기준 3시간 간격 예보 6칸
  function hourlyStrip(forecast, now) {
    var hourly = forecast.hourly;
    if (!hourly) return '';
    var cells = '';
    var count = 0;
    for (var i = 0; i < hourly.time.length && count < 6; i++) {
      var time = new Date(hourly.time[i]);
      if (time <= now || time.getHours() % 3 !== 0) continue;
      var info = describeCode(hourly.weather_code[i], hourly.is_day ? hourly.is_day[i] : 1);
      cells += '<div class="h">' + time.getHours() + '시<span class="e">' + info.icon + '</span>' +
        '<span class="t">' + Math.round(hourly.temperature_2m[i]) + '°</span></div>';
      count++;
    }
    return '<div class="hourly">' + cells + '</div>';
  }

  function renderHealth() {
    var html = '';
    memberList().forEach(function (member) {
      var h = member.health;
      if (!h) return;
      var stats = [];
      if (h.readiness != null) stats.push('준비 <em>' + h.readiness.toFixed(1) + '</em>' + (h.readinessLevel ? ' ' + escapeHTML(h.readinessLevel) : ''));
      if (h.steps != null) stats.push('걸음 <em>' + Math.round(h.steps).toLocaleString() + '</em>');
      if (h.sleepHours != null) stats.push('수면 <em>' + h.sleepHours.toFixed(1) + '</em>시간');
      if (h.water != null) stats.push('물 <em>' + Math.round(h.water / 100) / 10 + '</em>L');
      if (!stats.length) return;
      html += '<div class="person"><b>' + escapeHTML((member.emoji || '') + ' ' + member.name) + '</b>' +
        stats.map(function (s) { return '<span class="stat">' + s + '</span>'; }).join('') + '</div>';
    });
    $('health').innerHTML = html;
  }

  function renderStatus() {
    var parts = memberList().filter(function (member) { return member.updatedAt; }).map(function (member) {
      var hours = (Date.now() - member.updatedAtDate.getTime()) / 3600000;
      var text = escapeHTML(member.name) + ' 건강 기록 ' + timeAgo(member.updatedAtDate);
      return hours > STALE_HOURS ? '<span class="stale">' + text + '</span>' : text;
    });
    var text = parts.length ? parts.join(' · ') : '건강 기록 대기 중';
    if (state.apiError) text = '<span class="stale">NAS 연결 오류 (' + escapeHTML(state.apiError) + ')</span> · ' + text;
    $('status').innerHTML = text;
  }

  function renderNight(now) {
    var night = isNight(now) && Date.now() > state.wakeUntil && !params.nonight;
    $('night').className = night ? 'night' : 'night hidden';
    if (!night) return;
    $('night-clock').textContent = hhmm(now);

    var tomorrow = addDays(startOfDay(now), now.getHours() < 12 ? 0 : 1);
    var first = mergedEvents().filter(function (e) {
      return !e.allDay && dayKey(e.start) === dayKey(tomorrow);
    })[0];
    $('night-sub').textContent = first
      ? (now.getHours() < 12 ? '오늘 ' : '내일 ') + hhmm(first.start) + ' ' + first.title
      : '';
  }

  // ───────── 화면 유지 · 번인 방지 ─────────

  var wakeLock = null;
  function requestWakeLock() {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
    navigator.wakeLock.request('screen').then(function (lock) { wakeLock = lock; }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      requestWakeLock();
      loadMembers().then(render);
    }
  });

  /// 같은 자리에 계속 같은 글자가 있지 않게 5분마다 살짝 옮긴다.
  function shiftForBurnIn() {
    var x = Math.round(Math.random() * 12 - 6);
    var y = Math.round(Math.random() * 8 - 4);
    var transform = 'translate(' + x + 'px,' + y + 'px)';
    $('board').style.transform = transform;
    $('night').style.transform = transform;
  }

  // 밤에 화면을 누르면 1분간 전체 화면을 보여준다.
  document.addEventListener('click', function () {
    if (isNight(new Date())) {
      state.wakeUntil = Date.now() + 60 * 1000;
      render();
    }
  });

  // ───────── 시작 ─────────

  function tick() {
    var now = new Date();
    $('clock').textContent = hhmm(now);
    if (isNight(now)) $('night-clock').textContent = hhmm(now);
    if (now.getSeconds() < 2) render(); // 매분 전체 갱신 (지난 일정 흐리게 등)
    // 새벽 4시에 페이지를 새로 읽어 전광판 업데이트를 반영한다.
    if (now.getHours() === 4 && now.getMinutes() === 0 && now.getSeconds() < 2) window.location.reload();
  }

  render();
  loadMembers().then(render);
  loadWeather();
  requestWakeLock();

  setInterval(tick, 1000);
  setInterval(function () { loadMembers().then(render); }, DATA_REFRESH_MS);
  setInterval(loadWeather, WEATHER_REFRESH_MS);
  setInterval(shiftForBurnIn, 5 * 60 * 1000);

  window.FAMILY_BOARD_VERSION = BOARD_VERSION;
})();
