<?php
// 애플워치 · 아이폰 건강 기록을 자동으로 보내는 단축어 만들기 (단계별 + 실시간 연결 확인)
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$url = public_base() . '/api/health.php?token=' . $me['shortcut_token'];

/** 한 단계: 접었다 펴는 카드 + 「다 했어요」 */
function sc_step(int $no, string $title, string $sub, array $lines, string $extra = ''): void
{
    echo '<details class="card scstep" id="s' . $no . '" data-step="' . $no . '"><summary><span class="no">' . $no . '</span><span class="grow"><b>' . h($title) . '</b><span class="small muted">' . h($sub) . '</span></span><span class="ok">✓</span></summary>';
    echo '<ol class="small steps">';
    foreach ($lines as $line) echo '<li>' . $line . '</li>';
    echo '</ol>' . $extra . '<button type="button" class="btn small primary scdone">다 했어요 · 다음 단계</button></details>';
}

page_start('건강 자동 연결', 'health', ['back' => 'health.php']);
?>
<style>
  .steps { padding-left: 20px; line-height: 1.85; margin: 10px 0 12px; }
  .steps b { color: var(--text); }
  .tag { display: inline-block; background: var(--card-2); border-radius: 6px; padding: 0 6px; font-weight: 700; }
  .var { display: inline-block; background: var(--blue-soft); color: var(--blue); border-radius: 6px; padding: 0 6px; font-weight: 700; }
  .scstep { padding: 0; overflow: hidden; }
  .scstep > summary { list-style: none; display: flex; align-items: center; gap: 12px; padding: 16px 18px; cursor: pointer; }
  .scstep > summary::-webkit-details-marker { display: none; }
  .scstep > summary b { display: block; font-size: 16px; }
  .scstep .no { width: 30px; height: 30px; border-radius: 50%; background: var(--card-2); display: flex; align-items: center; justify-content: center; font-weight: 800; flex: none; }
  .scstep .ok { color: var(--accent); font-weight: 900; font-size: 18px; visibility: hidden; }
  .scstep.done .no { background: var(--accent); color: #fff; }
  .scstep.done .ok { visibility: visible; }
  .scstep[open] { box-shadow: inset 0 0 0 1.5px var(--accent), var(--shadow); }
  .scstep > :not(summary) { margin-left: 18px; margin-right: 18px; }
  .scstep > .btn { margin-bottom: 16px; }
  .keys { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
  .keys button { border: 0; font: inherit; font-size: 13px; font-weight: 700; padding: 6px 10px; border-radius: 9px; background: var(--blue-soft); color: var(--blue); cursor: pointer; }
  .status .items { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 10px; }
  .status .it { background: var(--card-2); border-radius: 12px; padding: 9px 11px; font-size: 13px; }
  .status .it b { display: block; font-size: 15px; }
  .status .it.ok b { color: var(--accent-ink); }
  .status .it.no b { color: var(--dim); }
  .status .head { display: flex; align-items: center; gap: 10px; }
  .status .pulse { width: 10px; height: 10px; border-radius: 50%; background: var(--dim); flex: none; }
  .status.live .pulse { background: var(--accent); animation: pulse 1.6s infinite; }
  @keyframes pulse { 50% { opacity: 0.3; } }
  .scprog { height: 6px; border-radius: 3px; background: var(--card-2); overflow: hidden; margin: 6px 0 14px; }
  .scprog i { display: block; height: 100%; background: var(--accent); width: 0; transition: width 0.3s; }
</style>

<section class="card status" id="status">
  <div class="head"><span class="pulse"></span><b class="grow" id="st-title">연결 상태를 확인하는 중…</b></div>
  <p class="small muted" id="st-sub" style="margin:4px 0 0">단축어를 ▶ 실행하면 몇 초 안에 여기 표시돼요. 이 화면은 열어 둔 채로 해 보세요.</p>
  <div class="items" id="st-items"></div>
</section>

<section class="card">
  <h2>📋 <?= h($me['name']) ?> 전용 주소</h2>
  <div class="form"><label>단축어 5단계에 붙여 넣을 주소<input id="url" readonly value="<?= h($url) ?>" onclick="this.select()"></label></div>
  <button class="btn primary" type="button" data-copy-url="<?= h($url) ?>">📋 주소 복사</button>
  <p class="small muted" style="margin:10px 0 0">주소에 <?= h($me['name']) ?>님 열쇠가 들어 있어요. 다른 사람에게 보내지 마세요.</p>
</section>

<h3 class="listhead">만드는 순서 <span id="sc-count"></span></h3>
<div class="scprog"><i id="sc-bar"></i></div>
<p class="small muted" style="margin:-6px 6px 12px">아이폰 <b>단축어</b> 앱에서 해요. <span class="tag">회색</span>은 누를 곳, <span class="var">파란색</span>은 이름이에요. 처음 한 번 10분쯤 걸려요.</p>

<?php
sc_step(1, '새 단축어 만들기', '이름: 건강 기록 보내기', [
    '<b>단축어</b> 앱 › 아래 <span class="tag">단축어</span> 탭 › 오른쪽 위 <span class="tag">+</span>',
    '맨 위 이름을 눌러 <span class="tag">이름 변경</span> › <b>건강 기록 보내기</b>',
]);
sc_step(2, '심박 변이 · 안정 시 심박', '애플워치가 재는 값', [
    '아래 <span class="tag">동작 검색</span>에 <b>건강 샘플</b> › <span class="tag">건강 샘플 찾기</span> › 유형을 <span class="tag">심박 변이</span>로',
    '<b>통계</b> 검색 › <span class="tag">통계 계산</span> 추가 (평균 그대로)',
    '<b>변수 설정</b> 검색 › <span class="tag">변수 설정</span> 추가 › 이름 <span class="var">hrv</span>',
    '다시 <span class="tag">건강 샘플 찾기</span> › 유형 <span class="tag">안정 시 심박수</span> › <span class="tag">변수 설정</span> 이름 <span class="var">rhr</span>',
]);
sc_step(3, '걸음 · 활동 에너지', '아이폰만 있어도 들어와요', [
    '<span class="tag">건강 샘플 찾기</span> › 유형 <span class="tag">걸음</span> › 동작을 펼쳐(<b>›</b>) <b>그룹화 기준</b>을 <span class="tag">일</span>로',
    '<span class="tag">변수 설정</span> 이름 <span class="var">steps</span>',
    '<span class="tag">건강 샘플 찾기</span> › 유형 <span class="tag">활동 에너지</span> › 그룹화 기준 <span class="tag">일</span> › <span class="tag">변수 설정</span> 이름 <span class="var">active_kcal</span>',
]);
sc_step(4, '지난밤 수면', '워치를 차고 자면 들어와요', [
    '<span class="tag">건강 샘플 찾기</span> › 유형 <span class="tag">수면 분석</span> › 시작일 <b>오늘</b>을 눌러 <span class="tag">지난</span> <b>1 일</b>로',
    '<span class="tag">필터 추가</span> › <b>값</b> · <b>이(가) 아님</b> · <span class="tag">깨어 있음</span>, 한 번 더 <span class="tag">필터 추가</span> › <span class="tag">침대에 있음</span>도 빼기',
    '<b>세부 사항</b> 검색 › <span class="tag">건강 샘플의 세부 사항 가져오기</span> › 파란 글씨를 <span class="tag">지속 시간</span>으로',
    '<span class="tag">통계 계산</span> › <b>합계</b>로 › <span class="tag">변수 설정</span> 이름 <span class="var">sleep_total</span>',
]);
sc_step(5, '사이트로 보내기', '주소 붙여 넣고 5칸 채우기', [
    '<b>URL</b> 검색 › <span class="tag">URL 콘텐츠 가져오기</span> › 파란 <b>URL</b>을 눌러 위에서 복사한 <b>주소 붙여 넣기</b>',
    '동작의 <b>›</b> 를 눌러 펼치고 › 방법 <span class="tag">POST</span> › 요청 본문 <span class="tag">JSON</span>',
    '<span class="tag">새로운 필드 추가</span> › <span class="tag">텍스트</span> › 키에 아래 이름을 (눌러서 복사 · 붙여 넣기), 값 칸을 누르고 키보드 위 <span class="tag">변수 선택</span> › 같은 이름의 파란 변수',
    '<b>hrv · rhr · steps · active_kcal · sleep_total</b> 다섯 칸을 만들어요',
    '(선택) <span class="tag">사전 값 가져오기</span> › 키 <b>message</b> › <span class="tag">알림 보기</span> 를 이어 붙이면 실행할 때 "오늘 준비 점수 7.6" 알림이 떠요',
], '<div class="keys">' . implode('', array_map(fn($k) => '<button type="button" data-key="' . $k . '">' . $k . '</button>', ['hrv', 'rhr', 'steps', 'active_kcal', 'sleep_total'])) . '</div>');
sc_step(6, '실행해 보고 · 자동으로', '맨 위 연결 상태가 초록이면 끝', [
    '단축어 오른쪽 아래 <span class="tag">▶</span> › 건강 데이터는 <span class="tag">모두 허용</span>, 사이트로 보내기는 <span class="tag">항상 허용</span>',
    '이 화면 맨 위 <b>연결 상태</b>에 ✓가 뜨면 성공. ✗ 항목은 옆에 적힌 단계를 다시 봐 주세요',
    '자동으로: 단축어 앱 <span class="tag">자동화</span> 탭 › <span class="tag">+</span> › <span class="tag">앱</span> › 매일 여는 앱(예: <b>카카오톡</b>) › <b>열릴 때</b> › <span class="tag">즉시 실행</span> › <b>건강 기록 보내기</b>',
    '아이폰이 잠겨 있으면 건강 기록을 못 읽어서, <b>앱을 열 때</b>가 가장 확실해요. 하루에 여러 번 돌아도 그날 값이 새로 바뀔 뿐이에요',
]);
?>

<section class="card">
  <h2>💑 엄마 폰은 공유로 1분</h2>
  <ol class="small steps" style="margin-top:0">
    <li>아빠 폰 단축어 앱에서 <b>건강 기록 보내기</b>를 길게 눌러 <span class="tag">공유</span> › <span class="tag">iCloud 링크 복사</span> › 카톡으로 보내기</li>
    <li>엄마 폰에서 링크 열기 › <span class="tag">단축어 추가</span></li>
    <li>엄마 폰에서 이 사이트에 <b>엄마로 로그인</b> › 이 화면에서 <b>엄마 전용 주소</b> 복사</li>
    <li>엄마 폰 단축어의 <b>URL 콘텐츠 가져오기</b> 주소를 <b>엄마 주소로 바꿔 붙여 넣기</b> (안 바꾸면 아빠 기록으로 들어가요)</li>
    <li>6단계(실행 · 자동화)를 엄마 폰에서도 해요. 자동화는 공유로 복사되지 않아요</li>
  </ol>
  <p class="small muted" style="margin:0">애플워치가 없으면 심박 변이 · 안정 심박 · 수면은 비어 있고 걸음 · 활동 에너지만 들어와요. 그래도 괜찮아요.</p>
</section>

<script>
(function () {
  // 단계 체크 (이 기기에 기억)
  var steps = [].slice.call(document.querySelectorAll('.scstep'));
  function get(n) { try { return localStorage.getItem('sc_step_' + n) === '1'; } catch (e) { return false; } }
  function set(n, v) { try { localStorage.setItem('sc_step_' + n, v ? '1' : '0'); } catch (e) {} }
  function refresh(openNext) {
    var done = 0, next = null;
    steps.forEach(function (s) { var d = get(s.dataset.step); s.classList.toggle('done', d); if (d) done++; else if (!next) next = s; });
    document.getElementById('sc-bar').style.width = (done / steps.length * 100) + '%';
    document.getElementById('sc-count').textContent = done + ' / ' + steps.length;
    if (openNext && next) { steps.forEach(function (s) { s.open = s === next; }); next.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  steps.forEach(function (s) {
    s.querySelector('.scdone').addEventListener('click', function () { set(s.dataset.step, true); refresh(true); });
    s.querySelector('summary').addEventListener('dblclick', function () { set(s.dataset.step, !get(s.dataset.step)); refresh(false); });
  });
  refresh(false);
  var first = steps.filter(function (s) { return !get(s.dataset.step); })[0];
  if (first) first.open = true;
  // 키 이름 복사
  document.querySelectorAll('[data-key]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-key');
      (navigator.clipboard ? navigator.clipboard.writeText(k) : Promise.reject()).then(function () { b.textContent = k + ' ✓'; setTimeout(function () { b.textContent = k; }, 1200); }).catch(function () { prompt('복사해서 쓰세요', k); });
    });
  });
  // 연결 상태: 5초마다 (3분 동안)
  var box = document.getElementById('status'), tries = 0;
  function ago(s) { return s < 60 ? '방금' : s < 3600 ? Math.floor(s / 60) + '분 전' : s < 86400 ? Math.floor(s / 3600) + '시간 전' : Math.floor(s / 86400) + '일 전'; }
  function fmt(v, u) { if (v == null) return ''; if (u === '분') return Math.floor(v / 60) + '시간 ' + (v % 60) + '분'; return Number(v).toLocaleString('ko-KR') + (u === '걸음' ? '' : ' ' + u); }
  function poll() {
    fetch('api/health_status.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      var t = document.getElementById('st-title'), sub = document.getElementById('st-sub'), list = document.getElementById('st-items');
      if (!j.received_at) { t.textContent = '아직 한 번도 받지 못했어요'; box.classList.remove('live'); }
      else {
        var fresh = j.ago < 600;
        box.classList.toggle('live', fresh);
        t.textContent = (fresh ? '✅ 받았어요 · ' : '마지막으로 받은 때 · ') + ago(j.ago);
        sub.textContent = fresh ? '아래 ✓ 항목이 들어왔어요. ✗ 항목은 적힌 단계를 다시 봐 주세요.' : '단축어를 ▶ 실행하면 몇 초 안에 바뀌어요.';
      }
      list.innerHTML = j.items.map(function (it) {
        var ok = it.value != null;
        return '<div class="it ' + (ok ? 'ok' : 'no') + '">' + it.label + '<b>' + (ok ? '✓ ' + fmt(it.value, it.unit) : (it.sent ? '△ 빈 값' : '✗ 안 옴')) + '</b>' +
          (ok ? '' : '<span class="small muted">' + (it.sent ? '워치 기록이 없거나 ' : '') + it.step + '단계 확인</span>') + '</div>';
      }).join('');
    }).catch(function () {});
    if (++tries < 36 && !document.hidden) setTimeout(poll, 5000);
  }
  poll();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { tries = 0; poll(); } });
})();
</script>
<?php page_end('health');
