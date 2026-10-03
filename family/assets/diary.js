// 나들이 일기 쓰기: 별점 · 장소 · 사진 줄여서 한 장씩 올리기
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
  sel.addEventListener('change', function () { nameBox.classList.toggle('hidden', sel.value !== '_'); });

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
    statusEl.textContent = queue.length ? '새 사진 ' + queue.length + '장은 저장할 때 올라가요.' : '';
  });

  async function send(data) {
    var res = await fetch(form.action, {
      method: 'POST', body: data, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'X-CSRF': form.dataset.csrf },
    });
    var text = await res.text();
    try { return JSON.parse(text); } catch (e) { return { ok: false, error: '서버 응답을 읽지 못했어요 (' + res.status + ')' }; }
  }

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    saveBtn.disabled = true;
    statusEl.textContent = '저장 중…';
    try {
      var r = await send(new FormData(form));
      if (!r.ok) throw new Error(r.error || '저장하지 못했어요');
      var id = r.id, failed = 0;
      form.querySelector('[name=id]').value = id;
      for (var i = 0; i < queue.length; i++) {
        statusEl.textContent = '사진 올리는 중 ' + (i + 1) + '/' + queue.length + '…';
        var fd = new FormData();
        fd.append('action', 'photo'); fd.append('id', id); fd.append('csrf', form.dataset.csrf);
        fd.append('photo', queue[i].photo); fd.append('thumb', queue[i].thumb);
        var p = await send(fd);
        if (p.ok) queue[i].done = true; else { failed++; statusEl.textContent = p.error; }
      }
      queue = queue.filter(function (q) { if (q.done) q.el.remove(); return !q.done; });
      if (failed) {
        statusEl.textContent = '일기는 저장했지만 사진 ' + failed + '장을 못 올렸어요. 저장을 한 번 더 눌러 주세요.';
        saveBtn.disabled = false;
        return;
      }
      location.href = 'diary_view.php?id=' + id;
    } catch (e) {
      statusEl.textContent = e.message;
      saveBtn.disabled = false;
    }
  });
})();
