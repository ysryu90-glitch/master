<?php
// 가계부 › 카드 결제 자동으로 받기 (아이폰 단축어 자동화)
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$url = public_base() . '/api/expense.php?token=' . $me['shortcut_token'];

function guide_steps(string $title, array $lines): void
{
    echo '<section class="card"><h2>' . h($title) . '</h2><ol class="small steps">';
    foreach ($lines as $line) echo '<li>' . $line . '</li>';
    echo '</ol></section>';
}
page_start('카드 자동 입력', 'diary');
?>
<style>
  .steps { padding-left: 20px; line-height: 1.85; margin: 0; }
  .tag { display: inline-block; background: var(--card-2); border-radius: 6px; padding: 0 6px; font-weight: 700; }
  .var { display: inline-block; background: var(--blue-soft); color: var(--blue); border-radius: 6px; padding: 0 6px; font-weight: 700; }
</style>
<p style="margin:0 4px 10px"><a href="ledger.php">‹ 가계부</a></p>

<section class="card">
  <h2>📲 어떻게 들어오나요?</h2>
  <p class="small">카드사 앱을 직접 연결하는 건 개인 사이트로는 할 수 없어서, <b>아이폰 단축어 자동화</b>가 결제 소식을 받아 이 사이트로 보내 주는 방식이에요. 두 가지 중 쓰시는 쪽으로 만들면 돼요 (둘 다 만들어도 돼요).</p>
  <ul class="small" style="padding-left:18px;line-height:1.8;margin:0">
    <li><b>A. 카드 결제 문자</b> — 거의 모든 카드. 카드사의 <b>문자 알림 서비스</b>를 신청해 둬야 해요 (카드사 앱 · 고객센터, 보통 월 300원 · 일부 무료). 카드사 <u>앱 푸시 알림</u>은 단축어가 읽을 수 없어요.</li>
    <li><b>B. 애플페이 (지갑)</b> — 애플페이에 등록한 카드로 결제할 때. 문자 없이도 금액 · 가게가 들어와요.</li>
  </ul>
  <p class="small muted" style="margin:8px 0 0">들어온 기록은 가게 이름으로 항목(카페 · 장보기 · 주유 …)을 자동으로 정하고, 못 정하면 가계부 위쪽에 「확인」으로 떠요. 같은 문자가 두 번 와도 한 번만 기록되고, <b>결제 취소 문자</b>는 마이너스로 들어가요.</p>
</section>

<section class="card">
  <h2><?= h($me['emoji'] . ' ' . $me['name']) ?> 전용 주소</h2>
  <div class="form"><label>단축어에 붙여 넣을 주소<input id="url" readonly value="<?= h($url) ?>" onclick="this.select()"></label></div>
  <button class="btn primary" type="button" data-copy-url="<?= h($url) ?>">📋 주소 복사</button>
  <p class="small muted" style="margin-top:10px">건강 단축어와 같은 열쇠예요. 엄마 · 아빠 폰에서 각자 로그인해서 각자 주소를 쓰면 「낸 사람」이 자동으로 정해져요.</p>
</section>

<?php
guide_steps('A. 카드 결제 문자로 받기', [
    '<b>단축어</b> 앱 › 아래 <span class="tag">자동화</span> 탭 › 오른쪽 위 <span class="tag">+</span> (처음이면 <span class="tag">새로운 자동화</span>)',
    '목록에서 <span class="tag">메시지</span> 선택',
    '<span class="tag">메시지에 포함된 내용</span>에 <b>승인</b> 입력 (취소 문자도 받으려면 같은 방법으로 <b>취소</b> 자동화를 하나 더 만들어요). 발신자는 비워 두거나 카드사 번호를 골라요',
    '<span class="tag">즉시 실행</span>을 고르고 <span class="tag">다음</span> › <span class="tag">새로운 빈 자동화</span>',
    '<span class="tag">동작 추가</span> › 검색에 <b>URL</b> › <span class="tag">URL 콘텐츠 가져오기</span>',
    '파란 <span class="var">URL</span>을 눌러 위에서 복사한 주소를 붙여 넣기',
    '동작 왼쪽 <span class="tag">›</span>를 눌러 펼치고: <span class="tag">방법</span> = <b>POST</b>, <span class="tag">요청 본문</span> = <b>JSON</b>',
    '<span class="tag">새로운 필드 추가</span> › <span class="tag">텍스트</span> › 키에 <b>text</b>, 값 칸을 누르고 키보드 위 변수에서 <span class="var">단축어 입력</span>(또는 <span class="var">메시지</span>)을 고른 뒤, 그 변수를 한 번 더 눌러 <span class="tag">내용</span>으로 바꿔요',
    '(선택) 동작 추가 › <span class="tag">알림 보기</span> › 내용에 변수 <span class="var">URL의 콘텐츠</span> → 결제할 때마다 "☕ 스타벅스 12,500원 → 카페"처럼 알려 줘요',
    '오른쪽 위 <span class="tag">완료</span>. 이제 카드를 쓰면 몇 초 안에 가계부에 들어와요',
]);
guide_steps('B. 애플페이(지갑) 결제로 받기', [
    '<b>단축어</b> 앱 › <span class="tag">자동화</span> › <span class="tag">+</span> › <span class="tag">지갑</span> (또는 <span class="tag">거래</span>)',
    '카드를 고르고 (판매처 · 카테고리는 <b>모두</b>), <span class="tag">즉시 실행</span> › <span class="tag">다음</span> › <span class="tag">새로운 빈 자동화</span>',
    '<span class="tag">URL 콘텐츠 가져오기</span> 추가 › 주소 붙여 넣기 › <b>POST</b> · <b>JSON</b>',
    '필드 3개: <b>amount</b> = 변수 <span class="var">단축어 입력</span> › <span class="tag">금액</span>, <b>merchant</b> = <span class="var">단축어 입력</span> › <span class="tag">판매처</span>, <b>card</b> = 카드 이름(직접 입력, 예: 현대카드)',
    '<span class="tag">완료</span>',
]);
?>

<section class="card">
  <h2>🧪 문자 붙여 넣어 시험해 보기</h2>
  <p class="small muted">받은 카드 결제 문자를 그대로 붙여 넣으면 어떻게 읽히는지 보여 드려요. <b>저장은 되지 않아요.</b></p>
  <div class="form"><label>카드 결제 문자<textarea id="sms" rows="5" placeholder="[Web발신]&#10;신한카드(1234)승인 홍*동 12,500원(일시불)10/05 13:22 스타벅스 누적1,234,560원"></textarea></label></div>
  <button class="btn" type="button" id="try">읽어 보기</button>
  <p class="small" id="tryout" style="margin:10px 0 0;font-weight:700"></p>
</section>
<script>
document.getElementById('try').addEventListener('click', function () {
  var out = document.getElementById('tryout'), btn = this;
  out.textContent = '';
  var p = fetch(<?= json_encode('api/expense.php?dry=1&token=' . $me['shortcut_token']) ?>, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text: document.getElementById('sms').value })
  }).then(function (r) { return r.json(); }).then(function (r) {
    out.style.color = r.ok ? 'var(--accent)' : 'var(--red)';
    out.textContent = r.ok ? '✅ ' + r.message : '⚠️ ' + r.error;
  });
  (window.Busy ? Busy.track(p, '문자를 읽는 중이에요…', btn) : p).catch(function (e) { out.textContent = '⚠️ ' + e.message; });
});
</script>
<script src="assets/card.js?v=<?= asset_version('assets/card.js') ?>"></script>
<?php page_end('diary');
