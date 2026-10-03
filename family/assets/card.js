// 일기 공유: 링크 보내기 · 복사, 사진 카드 만들기
(function () {
  'use strict';
  var busy = function () { return window.Busy || { start: function () {}, done: function () {}, set: function () {} }; };

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, text.length);
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) {}
      ta.remove();
      ok ? resolve() : reject(new Error('복사하지 못했어요. 주소를 길게 눌러 복사해 주세요.'));
    });
  }

  // ───────── 링크 보내기 · 복사 ─────────
  document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      copyText(btn.getAttribute('data-copy-url')).then(function () { busy().done('📋 링크를 복사했어요. 카카오톡에 붙여 넣으세요.'); },
        function (e) { busy().done('⚠️ ' + e.message, true); });
    });
  });
  document.querySelectorAll('[data-share-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-share-url'), title = btn.getAttribute('data-share-title') || '우리 가족 일기';
      if (navigator.share) {
        btn.classList.add('loading');
        navigator.share({ title: '📔 ' + title, text: '우리 가족 일기예요 📔 ' + title, url: url }).catch(function (e) {
          if (e && e.name === 'AbortError') return;
          return copyText(url).then(function () { busy().done('📋 보내기 창이 안 열려서 링크를 복사했어요. 카카오톡에 붙여 넣으세요.'); });
        }).then(function () { btn.classList.remove('loading'); }, function () { btn.classList.remove('loading'); });
      } else {
        copyText(url).then(function () { busy().done('📋 이 기기에서는 바로 보내기가 안 돼서 링크를 복사했어요.'); },
          function (e) { busy().done('⚠️ ' + e.message, true); });
      }
    });
  });

  // ───────── 사진 카드 (1080 × 1350) ─────────
  var W = 1080, H = 1350, FONT = '-apple-system, "Apple SD Gothic Neo", "Noto Sans KR", "Malgun Gothic", sans-serif';

  function loadImg(src) {
    return new Promise(function (resolve) {
      var img = new Image(), t = setTimeout(function () { resolve(null); }, 20000); // 20초 넘으면 그 사진은 빼고
      img.onload = function () { clearTimeout(t); resolve(img); };
      img.onerror = function () { clearTimeout(t); resolve(null); };
      img.src = src;
    });
  }
  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function cover(ctx, img, x, y, w, h) {
    var s = Math.max(w / img.naturalWidth, h / img.naturalHeight);
    var sw = w / s, sh = h / s;
    ctx.drawImage(img, (img.naturalWidth - sw) / 2, (img.naturalHeight - sh) / 2, sw, sh, x, y, w, h);
  }
  // 한글은 띄어쓰기가 없어도 글자 단위로 줄바꿈
  function lines(ctx, text, maxW, maxLines) {
    var out = [], cur = '';
    Array.from(text).forEach(function (ch) {
      if (ctx.measureText(cur + ch).width > maxW && cur) { out.push(cur); cur = ch.trim() ? ch : ''; } else cur += ch;
    });
    if (cur) out.push(cur);
    if (out.length > maxLines) {
      out = out.slice(0, maxLines);
      var last = out[maxLines - 1];
      while (last && ctx.measureText(last + '…').width > maxW) last = last.slice(0, -1);
      out[maxLines - 1] = last + '…';
    }
    return out;
  }

  async function makeCard(d, onProgress) {
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#fffaf3'; ctx.fillRect(0, 0, W, H);

    // 사진 (1~4장)
    var srcs = (d.photos || []).slice(0, 4), loaded = 0;
    var imgs = (await Promise.all(srcs.map(function (src) {
      return loadImg(src).then(function (img) { loaded++; if (onProgress) onProgress(loaded, srcs.length); return img; });
    }))).filter(Boolean);
    if (srcs.length && !imgs.length) throw new Error('사진을 불러오지 못했어요. 인터넷 연결을 확인해 주세요.');
    var px = 48, py = 48, pw = W - 96, ph = 800, g = 12;
    var boxes = [];
    if (imgs.length === 1) boxes = [[px, py, pw, ph]];
    else if (imgs.length === 2) boxes = [[px, py, (pw - g) / 2, ph], [px + (pw + g) / 2, py, (pw - g) / 2, ph]];
    else if (imgs.length === 3) {
      var bw = (pw - g) * 0.62, sw = pw - g - bw, sh = (ph - g) / 2;
      boxes = [[px, py, bw, ph], [px + bw + g, py, sw, sh], [px + bw + g, py + sh + g, sw, sh]];
    } else if (imgs.length === 4) {
      var hw = (pw - g) / 2, hh = (ph - g) / 2;
      boxes = [[px, py, hw, hh], [px + hw + g, py, hw, hh], [px, py + hh + g, hw, hh], [px + hw + g, py + hh + g, hw, hh]];
    }
    ctx.save();
    roundRect(ctx, px, py, pw, ph, 40); ctx.clip();
    ctx.fillStyle = '#f1e7da'; ctx.fillRect(px, py, pw, ph);
    imgs.forEach(function (img, i) { cover(ctx, img, boxes[i][0], boxes[i][1], boxes[i][2], boxes[i][3]); });
    ctx.restore();
    if (!imgs.length) { ctx.font = '160px ' + FONT; ctx.textAlign = 'center'; ctx.fillText('🧺', W / 2, py + ph / 2 + 50); ctx.textAlign = 'left'; }

    // 글
    var x = 64, y = py + ph + 70, maxW = W - 128;
    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = '#8a7f73'; ctx.font = '500 32px ' + FONT;
    ctx.fillText(lines(ctx, [d.date, d.weather].filter(Boolean).join(' · '), maxW, 1)[0] || '', x, y);
    y += 74;
    ctx.fillStyle = '#1f1a14'; ctx.font = '800 60px ' + FONT;
    lines(ctx, d.title || '', maxW, d.kid ? 1 : 2).forEach(function (l) { ctx.fillText(l, x, y); y += 72; });
    y -= 14;
    var meta = [];
    if (d.place) meta.push('📍 ' + d.place);
    ctx.font = '500 34px ' + FONT; ctx.fillStyle = '#6b6158';
    if (meta.length) { ctx.fillText(lines(ctx, meta.join(''), maxW, 1)[0], x, y + 10); y += 58; }
    if (d.avg != null) {
      var n = Math.round(d.avg);
      ctx.font = '700 44px ' + FONT; ctx.fillStyle = '#f59e0b';
      var stars = '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(0, 5 - n);
      ctx.fillText(stars, x, y + 18);
      var sw2 = ctx.measureText(stars).width;
      ctx.fillStyle = '#6b6158'; ctx.font = '700 36px ' + FONT; ctx.fillText(Number(d.avg).toFixed(1), x + sw2 + 18, y + 16);
      y += 70;
    }
    if (d.kid && y < H - 110) {
      ctx.font = '700 36px ' + FONT;
      var kl = lines(ctx, '👧 “' + d.kid + '”', maxW - 56, 2);
      var bh = kl.length * 50 + 34;
      if (y + bh > H - 70) kl = kl.slice(0, 1), bh = 84;
      ctx.fillStyle = 'rgba(219, 39, 119, 0.10)'; roundRect(ctx, x - 8, y - 8, maxW + 16, bh, 26); ctx.fill();
      ctx.fillStyle = '#be185d';
      kl.forEach(function (l, i) { ctx.fillText(l, x + 22, y + 46 + i * 50); });
    }
    ctx.font = '600 26px ' + FONT; ctx.fillStyle = '#b3a798'; ctx.textAlign = 'right';
    ctx.fillText('📔 우리 가족 일기', W - 56, H - 36);
    var dataUrl = c.toDataURL('image/jpeg', 0.9);
    return new Promise(function (resolve) {
      c.toBlob(function (blob) {
        if (!blob) { // 오래된 사파리: 데이터 주소에서 만들기
          var bin = atob(dataUrl.split(',')[1]), arr = new Uint8Array(bin.length);
          for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
          blob = new Blob([arr], { type: 'image/jpeg' });
        }
        resolve({ blob: blob, dataUrl: dataUrl });
      }, 'image/jpeg', 0.9);
    });
  }

  var IOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  function shareError(e) {
    if (e && e.name === 'AbortError') return null; // 사용자가 공유 창을 닫음
    return '보내기를 열지 못했어요' + (e && e.message ? ' (' + e.message + ')' : '') + '. 사진을 길게 눌러 저장하거나 보내 주세요.';
  }

  function showCard(blob, dataUrl, d) {
    var name = '일기-' + (d.title || '일기').replace(/[\\/:*?"<>|\s]+/g, '_').slice(0, 30) + '.jpg';
    var file = null;
    try { file = new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }); } catch (e) {}
    var canShare = false;
    try { canShare = !!(file && navigator.canShare && navigator.canShare({ files: [file] })); } catch (e) {}
    var m = document.createElement('div');
    m.className = 'cardmodal';
    m.innerHTML = '<div class="inner"><img alt="일기 카드"><div class="acts"></div><p class="small muted cardhint"></p><p class="small cardmsg"></p></div>';
    // 데이터 주소로 보여 주면 아이폰에서 길게 눌러 「사진 앱에 저장」이 잘 돼요
    m.querySelector('img').src = dataUrl;
    var acts = m.querySelector('.acts'), hint = m.querySelector('.cardhint'), msg = m.querySelector('.cardmsg');
    function button(label, cls, onClick) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'btn ' + cls; b.textContent = label;
      b.addEventListener('click', function (ev) { ev.stopPropagation(); onClick(b); });
      acts.appendChild(b);
      return b;
    }
    if (canShare) {
      button(IOS ? '📤 보내기 · 사진에 저장' : '📤 보내기', 'primary', function (b) {
        b.classList.add('loading'); msg.textContent = '';
        navigator.share({ files: [file] }).then(function () {
          msg.textContent = '✅ 완료했어요.';
        }, function (e) {
          var text = shareError(e);
          if (text) msg.textContent = '⚠️ ' + text;
        }).then(function () { b.classList.remove('loading'); });
      });
    }
    if (!IOS) {
      // 컴퓨터 · 안드로이드: 파일로 내려받기
      button('⬇️ 내려받기', canShare ? '' : 'primary', function () {
        var a = document.createElement('a');
        a.href = dataUrl; a.download = name; a.setAttribute('data-no-busy', '');
        document.body.appendChild(a); a.click(); a.remove();
        msg.textContent = '✅ 내려받기를 시작했어요.';
      });
    }
    button('닫기', '', close);
    hint.textContent = IOS
      ? (canShare ? '공유 창에서 「이미지 저장」을 누르면 사진 앱에, 카카오톡을 누르면 바로 보낼 수 있어요. ' : '') + '사진을 길게 눌러 「사진 앱에 저장」해도 돼요.'
      : '사진에서 마우스 오른쪽 버튼 › 「이미지를 다른 이름으로 저장」도 돼요.';
    function close() { m.remove(); document.removeEventListener('keydown', esc); }
    function esc(ev) { if (ev.key === 'Escape') close(); }
    m.addEventListener('click', function (ev) { if (ev.target === m) close(); });
    document.addEventListener('keydown', esc);
    document.body.appendChild(m);
  }

  document.querySelectorAll('[data-card]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (btn.disabled) return;
      var d;
      try { d = JSON.parse(btn.getAttribute('data-card')); } catch (e) { return; }
      btn.classList.add('loading'); btn.disabled = true;
      var B = busy();
      B.start('사진 카드를 만드는 중이에요…', { delay: 0, timeout: 60000 });
      B.set(5, '사진 불러오는 중…');
      try {
        var r = await makeCard(d, function (done, total) { B.set(5 + 75 * done / total, '사진 불러오는 중 ' + done + '/' + total); });
        if (!r) throw new Error('카드를 만들지 못했어요');
        B.set(100, '카드 완성!');
        setTimeout(function () { B.done(); }, 300);
        showCard(r.blob, r.dataUrl, d);
      } catch (e) {
        B.done('⚠️ ' + (e.message || '카드를 만들지 못했어요'), true);
      }
      btn.classList.remove('loading'); btn.disabled = false;
    });
  });
})();
