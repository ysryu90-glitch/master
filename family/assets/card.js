// 나들이 일기 공유: 링크 보내기 · 복사, 사진 카드 만들기
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
      var url = btn.getAttribute('data-share-url'), title = btn.getAttribute('data-share-title') || '나들이 일기';
      if (navigator.share) {
        navigator.share({ title: '📔 ' + title, text: '우리 가족 나들이 일기예요 📔 ' + title, url: url }).catch(function () {});
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
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { resolve(null); };
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

  async function makeCard(d) {
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#fffaf3'; ctx.fillRect(0, 0, W, H);

    // 사진 (1~4장)
    var imgs = (await Promise.all((d.photos || []).slice(0, 4).map(loadImg))).filter(Boolean);
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
    ctx.fillText('📔 우리 가족 나들이 일기', W - 56, H - 36);
    return new Promise(function (resolve) { c.toBlob(resolve, 'image/jpeg', 0.9); });
  }

  function showCard(blob, d) {
    var url = URL.createObjectURL(blob);
    var name = '나들이-' + (d.title || '일기').replace(/[\\/:*?"<>|\s]+/g, '_').slice(0, 30) + '.jpg';
    var file = null;
    try { file = new File([blob], name, { type: 'image/jpeg' }); } catch (e) {}
    var canShare = !!(file && navigator.canShare && navigator.canShare({ files: [file] }));
    var m = document.createElement('div');
    m.className = 'cardmodal';
    m.innerHTML = '<div class="inner"><img alt="나들이 카드"><p class="small">' +
      (canShare ? '「보내기 · 저장」을 눌러 카카오톡으로 보내거나 「이미지 저장」을 고르세요.' : '사진을 길게 눌러 저장하거나 보내 주세요.') +
      '</p><div class="acts">' + (canShare ? '<button type="button" class="btn primary" data-act="share">📤 보내기 · 저장</button>' : '') +
      '<a class="btn" data-act="dl">⬇️ 내려받기</a><button type="button" class="btn" data-act="close">닫기</button></div></div>';
    m.querySelector('img').src = url;
    var dl = m.querySelector('[data-act=dl]');
    dl.href = url; dl.download = name;
    m.addEventListener('click', function (ev) {
      var act = ev.target.getAttribute && ev.target.getAttribute('data-act');
      if (act === 'share') navigator.share({ files: [file], title: d.title || '나들이 일기' }).catch(function () {});
      if (act === 'close' || ev.target === m) { m.remove(); URL.revokeObjectURL(url); }
    });
    document.body.appendChild(m);
  }

  document.querySelectorAll('[data-card]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var d;
      try { d = JSON.parse(btn.getAttribute('data-card')); } catch (e) { return; }
      btn.classList.add('loading'); btn.disabled = true;
      busy().start('사진 카드를 만드는 중이에요…', { delay: 0 });
      try {
        var blob = await makeCard(d);
        if (!blob) throw new Error('카드를 만들지 못했어요');
        busy().done();
        showCard(blob, d);
      } catch (e) {
        busy().done('⚠️ ' + e.message, true);
      }
      btn.classList.remove('loading'); btn.disabled = false;
    });
  });
})();
