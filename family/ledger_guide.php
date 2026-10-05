<?php
// 가계부 › 예산 · 카드 결제 자동으로 받기 (아이폰 단축어 자동화) · 잘못 들어간 자동 기록 지우기
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ledger.php';
$me = require_login();
check_csrf();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch (post('action')) {
        case 'budget':
            set_setting('ledger_budget', max(0, (int) preg_replace('/[^\d]/', '', (string) post('budget'))));
            flash(ledger_budget() ? '한 달 예산을 ' . won(ledger_budget(), true) . '으로 정했어요.' : '예산을 껐어요.');
            redirect('ledger_guide.php');
        case 'delete_many':
            $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
            if ($ids) db()->exec('DELETE FROM expenses WHERE id IN (' . implode(',', $ids) . ')');
            flash($ids ? count($ids) . '건을 지웠어요.' : '지울 기록을 골라 주세요.');
            redirect('ledger_guide.php#auto');
    }
    redirect('ledger_guide.php');
}
$budget = ledger_budget();
// 최근 3일 자동 입력 기록 (잘못 들어간 것을 한꺼번에 지우는 곳)
$autoRecent = db()->query("SELECT * FROM expenses WHERE source <> 'manual' AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY id DESC LIMIT 100")->fetchAll();
$url = public_base() . '/api/expense.php?token=' . $me['shortcut_token'];

function human_ago(string $at): string
{
    $d = time() - strtotime($at);
    return $d < 60 ? '방금' : ($d < 3600 ? floor($d / 60) . '분 전' : ($d < 86400 ? floor($d / 3600) . '시간 전' : floor($d / 86400) . '일 전'));
}

function guide_steps(string $title, array $lines): void
{
    echo '<section class="card"><details class="fold"><summary class="h2">' . h($title) . '</summary><ol class="small steps" style="margin-top:10px">';
    foreach ($lines as $line) echo '<li>' . $line . '</li>';
    echo '</ol></details></section>';
}
page_start('예산 · 자동 입력', 'ledger');
?>
<style>
  .steps { padding-left: 20px; line-height: 1.85; margin: 0; }
  .tag { display: inline-block; background: var(--card-2); border-radius: 6px; padding: 0 6px; font-weight: 700; }
  .var { display: inline-block; background: var(--blue-soft); color: var(--blue); border-radius: 6px; padding: 0 6px; font-weight: 700; }
</style>
<section class="card">
  <h2>💰 한 달 예산</h2>
  <form method="post" class="form inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="budget">
    <label>금액 (비우면 예산 끔)<input name="budget" inputmode="numeric" value="<?= $budget ? number_format($budget) : '' ?>" placeholder="예: 1,500,000"></label>
    <button class="btn primary">저장</button>
  </form>
  <p class="small muted" style="margin:8px 0 0">정해 두면 가계부 위쪽에 남은 돈 · 하루에 쓸 수 있는 돈이 보여요.</p>
</section>

<?php if ($autoRecent): ?>
<section class="card" id="auto">
  <details class="fold">
    <summary>🧹 최근 3일 자동 입력 <?= count($autoRecent) ?>건 · 잘못 들어간 것 지우기</summary>
    <form method="post" data-confirm="고른 기록을 지울까요?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_many">
      <?php foreach ($autoRecent as $x): [$cn, $ci] = ledger_cat($x['category']); ?>
        <label class="autorow"><input type="checkbox" name="ids[]" value="<?= (int) $x['id'] ?>">
          <span class="grow"><b><?= h($x['merchant'] ?: $cn) ?></b><span class="small muted"> · <?= date('n/j', strtotime($x['day'])) ?><?= $x['at_time'] ? ' ' . substr($x['at_time'], 0, 5) : '' ?> · <?= $ci ?> <?= h($cn) ?> · <?= ['sms' => '문자', 'screen' => '화면 캡처', 'wallet' => '애플페이'][$x['source']] ?? $x['source'] ?></span></span>
          <b><?= won((int) $x['amount']) ?></b></label>
      <?php endforeach; ?>
      <div class="btn-row" style="margin-top:10px">
        <button type="button" class="btn small" onclick="this.form.querySelectorAll('input[type=checkbox]').forEach(function(c){c.checked=!c.checked})">전체 선택 / 해제</button>
        <button class="btn small danger">🗑 고른 기록 지우기</button>
      </div>
    </form>
  </details>
</section>
<?php endif; ?>

<h3 class="dmonth" style="margin-top:6px">📲 카드 결제 자동으로 받기</h3>

<section class="card">
  <h2>📲 어떻게 들어오나요?</h2>
  <p class="small">카드사 앱을 직접 연결하는 건 개인 사이트로는 할 수 없어서, <b>아이폰 단축어 자동화</b>가 결제 소식을 받아 이 사이트로 보내 주는 방식이에요. 두 가지 중 쓰시는 쪽으로 만들면 돼요 (둘 다 만들어도 돼요).</p>
  <ul class="small" style="padding-left:18px;line-height:1.8;margin:0">
    <li><b>A. 카드 결제 문자</b> — 거의 모든 카드. 카드사의 <b>문자 알림 서비스</b>를 신청해 둬야 해요 (카드사 앱 · 고객센터, 보통 월 300원 · 일부 무료). 카드사 <u>앱 푸시 알림</u>은 단축어가 읽을 수 없어요.</li>
    <li><b>B. 애플페이 (지갑)</b> — 애플페이에 등록한 카드로 결제할 때. 문자 없이도 금액 · 가게가 들어와요.</li>
    <li><b>C. 페이북 같은 앱 알림 → 뒷면 톡톡</b> — 문자 없이 무료. 알림이 오면 알림 센터를 내리고 <b>폰 뒷면을 두 번 톡톡</b> 치면, 화면에 보이는 결제가 한꺼번에 들어가요. (아이폰은 다른 앱 알림을 자동으로 읽게 해 주지 않아서 이 한 동작만 필요해요)</li>
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
guide_steps('C. 페이북 알림 → 뒷면 톡톡 (무료 · 폰만으로)', [
    '<b>단축어</b> 앱 › 아래 <span class="tag">단축어</span> 탭 › 오른쪽 위 <span class="tag">+</span> › 이름을 <b>가계부에 넣기</b>로 바꿔요',
    '<span class="tag">동작 추가</span> › 검색에 <b>스크린샷</b> › <span class="tag">스크린샷 찍기</span>',
    '<span class="tag">동작 추가</span> › 검색에 <b>텍스트 추출</b> › <span class="tag">이미지에서 텍스트 추출</span> (입력이 <span class="var">스크린샷</span>인지 확인)',
    '<span class="tag">URL 콘텐츠 가져오기</span> 추가 › 주소를 붙여 넣고, 주소 맨 끝에 <b>&amp;mode=screen&amp;plain=1</b> 을 이어서 써요',
    '<span class="tag">›</span> 펼쳐서 <b>POST</b> · <b>JSON</b> › 필드 <b>text</b> = 변수 <span class="var">이미지에서 추출한 텍스트</span>',
    '<span class="tag">알림 보기</span> 추가 › 내용에 변수 <span class="var">URL의 콘텐츠</span> → "💰 2건 기록했어요 · 스타벅스 12,500원 …"처럼 알려 줘요',
    '<b>설정</b> 앱 › <span class="tag">손쉬운 사용</span> › <span class="tag">터치</span> › 맨 아래 <span class="tag">뒷면 탭</span> › <span class="tag">이중 탭</span> › <b>가계부에 넣기</b> 선택',
    '<b>쓰는 법:</b> 페이북 알림이 오면 화면 위에서 아래로 쓸어내려 <b>알림 센터</b>를 열고 → 폰 뒷면을 <b>톡톡</b>. 페이북 앱의 <b>이용내역</b> 목록이나 결제 한 건의 <b>이용내역 상세</b> 화면에서 톡톡 해도 돼요 (상세 화면은 그 결제 한 건만 들어가고, 아래쪽 「이번 달 소비 금액 · 남은한도」 같은 요약 금액은 무시해요)',
    '같은 결제는 여러 번 톡톡 해도 한 번만 기록되고, 문자 · 애플페이로 이미 들어온 결제도 건너뛰어요. 캡처 사진은 사진 앱에 저장되지 않아요',
]);
guide_steps('B. 애플페이(지갑) 결제로 받기', [
    '<b>단축어</b> 앱 › <span class="tag">자동화</span> › <span class="tag">+</span> › <span class="tag">지갑</span> (또는 <span class="tag">거래</span>)',
    '카드를 고르고 (판매처 · 카테고리는 <b>모두</b>), <span class="tag">즉시 실행</span> › <span class="tag">다음</span> › <span class="tag">새로운 빈 자동화</span>',
    '<span class="tag">URL 콘텐츠 가져오기</span> 추가 › 주소 붙여 넣기 › <b>POST</b> · <b>JSON</b>',
    '필드 3개: <b>amount</b> = 변수 <span class="var">단축어 입력</span> › <span class="tag">금액</span>, <b>merchant</b> = <span class="var">단축어 입력</span> › <span class="tag">판매처</span>, <b>card</b> = 카드 이름(직접 입력, 예: 현대카드)',
    '<span class="tag">완료</span>',
]);
?>

<?php $hit = setting('expense_last_hit'); ?>
<section class="card" id="lasthit">
  <h2>🔍 마지막으로 받은 요청</h2>
  <?php if (is_array($hit)): ?>
    <p class="small" style="margin:0;line-height:1.8">
      시각 <b><?= h($hit['at']) ?></b> (<?= h(human_ago($hit['at'])) ?>)<br>
      방식 <?= h($hit['method']) ?> · 내용 <?= number_format((int) $hit['bytes']) ?>바이트<?= $hit['type'] ? ' · ' . h($hit['type']) : '' ?><?= $hit['mode'] ? ' · ' . h($hit['mode']) : '' ?><br>
      결과 <b><?= h($hit['result']) ?></b>
    </p>
  <?php else: ?>
    <p class="small muted" style="margin:0">아직 받은 요청이 없어요.</p>
  <?php endif; ?>
  <p class="small muted" style="margin:8px 0 0">단축어에서 「네트워크 연결이 유실」 같은 오류가 나면, 바로 이 화면을 새로고침해 보세요. 시각이 방금으로 바뀌었으면 요청은 서버까지 온 거예요.</p>
  <a class="btn small" href="ledger_guide.php#lasthit" style="margin-top:8px">↻ 새로고침</a>
</section>

<section class="card">
  <h2>🧪 붙여 넣어 시험해 보기</h2>
  <p class="small muted">카드 결제 문자, 또는 페이북 알림 캡처에서 복사한 글자(사진에서 글자를 길게 눌러 복사)를 붙여 넣으면 어떻게 읽히는지 보여 드려요. <b>저장은 되지 않아요.</b></p>
  <div class="segmented" id="try-mode" style="margin-bottom:10px">
    <label><input type="radio" name="trymode" value="" checked><span>💬 카드 문자</span></label>
    <label><input type="radio" name="trymode" value="screen"><span>📱 화면 캡처 글자</span></label>
  </div>
  <div class="form"><label>카드 결제 문자<textarea id="sms" rows="5" placeholder="[Web발신]&#10;신한카드(1234)승인 홍*동 12,500원(일시불)10/05 13:22 스타벅스 누적1,234,560원"></textarea></label></div>
  <button class="btn" type="button" id="try">읽어 보기</button>
  <p class="small" id="tryout" style="margin:10px 0 0;font-weight:700"></p>
</section>
<script>
document.getElementById('try').addEventListener('click', function () {
  var out = document.getElementById('tryout'), btn = this;
  out.textContent = '';
  var mode = (document.querySelector('[name=trymode]:checked') || {}).value || '';
  var p = fetch(<?= json_encode('api/expense.php?dry=1&token=' . $me['shortcut_token']) ?> + (mode ? '&mode=' + mode : ''), {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text: document.getElementById('sms').value })
  }).then(function (r) { return r.json(); }).then(function (r) {
    out.style.color = r.ok ? 'var(--accent)' : 'var(--red)';
    out.style.whiteSpace = 'pre-line';
    out.textContent = r.ok ? '✅ ' + (r.items && r.items.length > 1 ? r.items.length + '건을 찾았어요\n' : '') + r.message : '⚠️ ' + (r.error || r.message);
  });
  (window.Busy ? Busy.track(p, '문자를 읽는 중이에요…', btn) : p).catch(function (e) { out.textContent = '⚠️ ' + e.message; });
});
</script>
<script src="assets/card.js?v=<?= asset_version('assets/card.js') ?>"></script>
<?php page_end('ledger');
