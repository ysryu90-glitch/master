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
