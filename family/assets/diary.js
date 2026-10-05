// 일기 쓰기: 일상 · 나들이, 별점 · 장소 · 사진 줄여서 한 장씩 올리기
(function () {
  'use strict';
  var form = document.getElementById('diary-form');
  if (!form) return;
  var statusEl = document.getElementById('diary-status');
  var saveBtn = document.getElementById('diary-save');
  var grid = document.getElementById('photo-grid');
  var queue = []; // { photo, thumb, el }

  // 별점: 고른 별까지 채우기
  form.querySelectorAll('[data-stars]').forEach(function (row) {
    function paint() {
      var checked = row.querySelector('input:checked');
      var v = checked ? +checked.value : 0;
      row.querySelectorAll('label').forEach(function (l) {
        var n = +l.querySelector('input').value;
        l.classList.toggle('on', n > 0 && (row.classList.contains('faces') ? n === v : n <= v));
        if (n === 0) l.classList.toggle('on', v === 0);
      });
    }
    row.addEventListener('change', paint);
    paint();
  });

  // 장소: 직접 적기일 때만 이름 칸
  var sel = document.getElementById('place-select');
  var nameBox = document.getElementById('place-name');
  function category() { var c = form.querySelector('[name=category]:checked'); return c ? c.value : 'daily'; }
  function placeBox() { nameBox.classList.toggle('hidden', category() === 'outing' && sel.value !== '_'); }
  sel.addEventListener('change', placeBox);

  // 일상 ↔ 나들이: 장소 고르기 · 또 가고 싶어요는 나들이만, 안내 글도 바꿈
  form.querySelectorAll('[name=category]').forEach(function (r) {
    r.addEventListener('change', function () {
      var cat = category();
      form.querySelectorAll('[data-only]').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-only') !== cat); });
      form.querySelectorAll('[data-' + cat + ']').forEach(function (el) {
        var text = el.getAttribute('data-' + cat);
        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') el.placeholder = text; else el.textContent = text;
      });
      placeBox();
    });
  });

  // 💰 이날 쓴 돈: 줄 추가 · 금액 쉼표
  var spendNew = document.getElementById('spend-new'), spendMore = document.getElementById('spend-more');
  if (spendNew && spendMore) {
    spendMore.addEventListener('click', function () {
      var row = spendNew.firstElementChild.cloneNode(true);
      row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      spendNew.appendChild(row);
      row.querySelector('input').focus();
    });
    spendNew.addEventListener('input', function (ev) {
      if (ev.target.name !== 'new_amt[]') return;
      var n = ev.target.value.replace(/[^\d]/g, '');
      ev.target.value = n ? Number(n).toLocaleString('ko-KR') : '';
    });
  }

  // 기존 사진 지우기 표시
  grid.addEventListener('change', function (ev) {
    if (ev.target.name === 'remove_photo[]') ev.target.closest('.dph').classList.toggle('removing', ev.target.checked);
  });

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { resolve(img); setTimeout(function () { URL.revokeObjectURL(url); }, 1000); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('사진을 읽지 못했어요')); };
      img.src = url;
    });
  }

  function shrink(img, max, quality) {
    var w = img.naturalWidth, h = img.naturalHeight;
    if (Math.max(w, h) > max) { var s = max / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
    var c = document.createElement('canvas');
    c.width = w; c.height = h;
    c.getContext('2d').drawImage(img, 0, 0, w, h);
    return c.toDataURL('image/jpeg', quality);
  }

  document.getElementById('photo-input').addEventListener('change', async function () {
    var files = Array.prototype.slice.call(this.files || []);
    this.value = '';
    for (var i = 0; i < files.length; i++) {
      statusEl.textContent = '사진 준비 중 ' + (i + 1) + '/' + files.length + '…';
      B().set(100 * i / files.length, '사진 준비 중 ' + (i + 1) + '/' + files.length);
      await new Promise(function (r) { setTimeout(r, 30); }); // 화면이 진행률을 그릴 틈
      try {
        var img = await loadImage(files[i]);
        var item = { photo: shrink(img, 1600, 0.82), thumb: shrink(img, 480, 0.75) };
        var el = document.createElement('div');
        el.className = 'dph new';
        el.innerHTML = '<img alt=""><button type="button" class="rm"><span>✕</span></button>';
        el.querySelector('img').src = item.thumb;
        el.querySelector('button').addEventListener('click', function (it, node) {
          return function () { queue.splice(queue.indexOf(it), 1); node.remove(); };
        }(item, el));
        item.el = el;
        queue.push(item);
        grid.appendChild(el);
      } catch (e) {
        statusEl.textContent = e.message;
      }
    }
    B().done(files.length ? '사진 ' + files.length + '장 준비 완료' : '');
    statusEl.textContent = queue.length ? '새 사진 ' + queue.length + '장은 저장할 때 올라가요.' : '';
  });

  // XHR 로 보내야 올라가는 양(%)을 알 수 있다
  function send(data, onProgress) {
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.setRequestHeader('X-Requested-With', 'fetch');
      xhr.setRequestHeader('X-CSRF', form.dataset.csrf);
      xhr.timeout = 120000;
      if (onProgress && xhr.upload) xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      xhr.onload = function () {
        try { resolve(JSON.parse(xhr.responseText)); } catch (e) { resolve({ ok: false, error: '서버 응답을 읽지 못했어요 (' + xhr.status + ')' }); }
      };
      xhr.onerror = function () { resolve({ ok: false, error: '인터넷 연결이 끊겼어요. 다시 저장을 눌러 주세요.' }); };
      xhr.ontimeout = function () { resolve({ ok: false, error: '응답이 너무 늦어요. 다시 저장을 눌러 주세요.' }); };
      xhr.send(data);
    });
  }
  var B = function () { return window.Busy || { start: function () {}, set: function () {}, message: function () {}, done: function () {} }; };

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    saveBtn.disabled = true;
    saveBtn.classList.add('loading');
    var total = queue.reduce(function (n, q) { return n + q.photo.length + q.thumb.length; }, 0);
    var sent = 0;
    function progress(part, label) { B().set(total ? 4 + 95 * (sent + part) / total : 50, label); }
    statusEl.textContent = '저장 중…';
    B().start('일기를 저장하는 중이에요…', { delay: 0, timeout: 120000 });
    try {
      var r = await send(new FormData(form));
      if (!r.ok) throw new Error(r.error || '저장하지 못했어요');
      var id = r.id, failed = 0;
      form.querySelector('[name=id]').value = id;
      if (queue.length) progress(0, '사진 올리는 중 1/' + queue.length);
      for (var i = 0; i < queue.length; i++) {
        var label = '사진 올리는 중 ' + (i + 1) + '/' + queue.length;
        statusEl.textContent = label + '…';
        var size = queue[i].photo.length + queue[i].thumb.length;
        var fd = new FormData();
        fd.append('action', 'photo'); fd.append('id', id); fd.append('csrf', form.dataset.csrf);
        fd.append('photo', queue[i].photo); fd.append('thumb', queue[i].thumb);
        var p = await send(fd, function (f) { progress(size * f, label); });
        sent += size;
        progress(0, label);
        if (p.ok) queue[i].done = true; else { failed++; statusEl.textContent = p.error; }
      }
      queue = queue.filter(function (q) { if (q.done) q.el.remove(); return !q.done; });
      if (failed) throw new Error('일기는 저장했지만 사진 ' + failed + '장을 못 올렸어요. 저장을 한 번 더 눌러 주세요.');
      B().set(100, '저장했어요! 일기로 이동해요…');
      location.href = 'diary_view.php?id=' + id;
    } catch (e) {
      statusEl.textContent = e.message;
      B().done('⚠️ ' + e.message, true);
      saveBtn.disabled = false;
      saveBtn.classList.remove('loading');
    }
  });
})();
