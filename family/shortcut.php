<?php
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$base = public_base();
$url = $base . '/api/health.php?token=' . $me['shortcut_token'];

/** 건강 샘플 찾기 한 묶음 안내 */
function step_card(string $no, string $title, array $lines): void
{
    echo '<section class="card"><h2>' . h($no) . '. ' . h($title) . '</h2><ol class="small steps">';
    foreach ($lines as $line) echo '<li>' . $line . '</li>';
    echo '</ol></section>';
}

page_start('단축어 만들기');
?>
<style>
  .steps { padding-left: 20px; line-height: 1.85; margin: 0; }
  .steps b { color: var(--text); }
  .tag { display: inline-block; background: var(--card-2); border-radius: 6px; padding: 0 6px; font-weight: 700; }
  .var { display: inline-block; background: var(--blue-soft); color: var(--blue); border-radius: 6px; padding: 0 6px; font-weight: 700; }
</style>

<section class="card">
  <h2><?= h($me['emoji'] . ' ' . $me['name']) ?> 전용 주소</h2>
  <div class="form"><label>단축어에 붙여 넣을 주소<input id="url" readonly value="<?= h($url) ?>" onclick="this.select()"></label></div>
  <button class="btn primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('url').value).then(function(){ this.textContent='복사됐어요 ✓'; }.bind(this))">주소 복사</button>
  <p class="small muted" style="margin-top:10px">이 주소에 <?= h($me['name']) ?>님 열쇠(토큰)가 들어 있어요. 다른 사람에게 보내지 마세요.</p>
</section>

<section class="card">
  <h2>전체 모습</h2>
  <p class="small">단축어 하나에 <b>건강 기록 4가지를 찾아서 → 주소로 보내는</b> 동작을 차례로 넣어요. 처음 한 번 15분 정도 걸리고, 아내분 폰은 <b>공유 링크로 복사</b>하면 1분이면 돼요(맨 아래 참고).</p>
  <p class="small muted">아래에서 <span class="tag">회색</span>은 누를 곳, <span class="var">파란색</span>은 변수 이름이에요.</p>
</section>

<?php
step_card('1', '새 단축어 만들기', [
    '<b>단축어</b> 앱을 열고 아래 <span class="tag">단축어</span> 탭 › 오른쪽 위 <span class="tag">+</span>',
    '맨 위 이름(새로운 단축어)을 눌러 <span class="tag">이름 변경</span> › <b>건강 기록 보내기</b>',
]);

step_card('2', '심박 변이 (HRV)', [
    '아래 <span class="tag">동작 검색</span>에 <b>건강 샘플</b> 입력 › <span class="tag">건강 샘플 찾기</span>',
    '추가된 동작에서 <b>유형</b> 옆 파란 글씨를 눌러 <span class="tag">심박 변이</span> 선택 (시작일은 <b>오늘</b> 그대로)',
    '동작 검색에 <b>통계</b> 입력 › <span class="tag">통계 계산</span> 추가 › 파란 글씨가 <b>평균</b>인지 확인',
    '동작 검색에 <b>변수 설정</b> 입력 › <span class="tag">변수 설정</span> 추가 › 변수 이름에 <span class="var">hrv</span> 입력',
]);

step_card('3', '안정 시 심박수', [
    '<span class="tag">건강 샘플 찾기</span> 하나 더 추가 › 유형 <span class="tag">안정 시 심박수</span>',
    '<span class="tag">변수 설정</span> 추가 › 이름 <span class="var">rhr</span>',
]);

step_card('4', '걸음 수', [
    '<span class="tag">건강 샘플 찾기</span> 추가 › 유형 <span class="tag">걸음</span>',
    '동작을 펼쳐(<b>›</b> 또는 <b>자세히 보기</b>) <b>그룹화 기준</b>을 <span class="tag">일</span>로 바꿔요. 아이폰과 워치가 센 걸음이 두 번 더해지지 않아요.',
    '<span class="tag">변수 설정</span> 추가 › 이름 <span class="var">steps</span>',
]);

step_card('5', '활동 에너지', [
    '<span class="tag">건강 샘플 찾기</span> 추가 › 유형 <span class="tag">활동 에너지</span> › 그룹화 기준 <span class="tag">일</span>',
    '<span class="tag">변수 설정</span> 추가 › 이름 <span class="var">active_kcal</span>',
]);

step_card('6', '지난밤 수면', [
    '<span class="tag">건강 샘플 찾기</span> 추가 › 유형 <span class="tag">수면 분석</span>',
    '시작일 <b>오늘</b>을 눌러 <span class="tag">지난</span> › <b>1 일</b>로 바꿔요 (어젯밤에 잠든 것도 포함되게)',
    '<span class="tag">필터 추가</span> › <b>값</b> › <b>이(가) 아님</b> › <span class="tag">깨어 있음</span>',
    '한 번 더 <span class="tag">필터 추가</span> › <b>값</b> › <b>이(가) 아님</b> › <span class="tag">침대에 있음</span> (조건이 <b>모두</b> 일치로 되어 있는지 확인)',
    '동작 검색에 <b>세부 사항</b> 입력 › <span class="tag">건강 샘플의 세부 사항 가져오기</span> › 파란 글씨를 <span class="tag">지속 시간</span>으로',
    '<span class="tag">통계 계산</span> 추가 › <b>합계</b>로 바꿔요',
    '<span class="tag">변수 설정</span> 추가 › 이름 <span class="var">sleep_total</span>',
]);

step_card('7', '사이트로 보내기', [
    '동작 검색에 <b>URL</b> 입력 › <span class="tag">URL 콘텐츠 가져오기</span>',
    '파란 <b>URL</b> 글씨를 눌러 위에서 복사한 <b>주소를 붙여 넣기</b>',
    '동작의 <b>›</b> 를 눌러 펼치고 › 방법 <span class="tag">POST</span> › 요청 본문 <span class="tag">JSON</span>',
    '<span class="tag">새로운 필드 추가</span> › <span class="tag">텍스트</span> › 키에 <b>hrv</b>, 값 칸을 눌러 키보드 위 <span class="tag">변수 선택</span> › <span class="var">hrv</span>',
    '같은 방법으로 <b>rhr</b> ← <span class="var">rhr</span>, <b>steps</b> ← <span class="var">steps</span>, <b>active_kcal</b> ← <span class="var">active_kcal</span>, <b>sleep_total</b> ← <span class="var">sleep_total</span> 추가',
    '(선택) <span class="tag">사전 값 가져오기</span> 추가 › 키 <b>message</b> › 이어서 <span class="tag">알림 표시</span>를 넣으면 "오늘 준비 점수 7.6" 알림이 떠요',
]);

step_card('8', '한 번 실행해 보기', [
    '오른쪽 아래 <span class="tag">▶</span> 를 눌러요',
    '건강 데이터 접근을 물어보면 <span class="tag">모두 허용</span> › 사이트로 보낼지 물어보면 <span class="tag">항상 허용</span>',
    '사이트 <b>설정</b> › 단축어 연결의 <b>마지막으로 받은 기록</b>에 방금 시각이 나오면 성공이에요. <b>받은 내용 보기</b>를 눌러 값이 들어왔는지도 볼 수 있어요.',
]);

step_card('9', '자동으로 실행', [
    '단축어 앱 아래 <span class="tag">자동화</span> 탭 › 오른쪽 위 <span class="tag">+</span> › <span class="tag">앱</span>',
    '<span class="tag">선택</span> › 매일 여는 앱(예: <b>카카오톡</b>) › <b>열릴 때</b> 체크',
    '<span class="tag">즉시 실행</span> 선택 (실행 시 알림은 꺼도 돼요) › <span class="tag">다음</span> › <b>건강 기록 보내기</b>',
    '아이폰이 잠겨 있으면 건강 데이터를 읽지 못해서, <b>앱을 열 때</b>로 하는 게 가장 확실해요. 하루에 여러 번 실행돼도 그날 기록이 최신 값으로 바뀔 뿐이에요.',
]);
?>

<section class="card">
  <h2>💑 아내분 폰은 공유로 1분 만에</h2>
  <ol class="small steps">
    <li>남편분 폰 단축어 앱에서 <b>건강 기록 보내기</b>를 길게 눌러 <span class="tag">공유</span> › <span class="tag">iCloud 링크 복사</span></li>
    <li>카카오톡으로 아내분께 링크를 보내요 › 아내분이 열어서 <span class="tag">단축어 추가</span></li>
    <li>아내분이 이 사이트에 <b>엄마 계정</b>으로 로그인 › 설정 › 단축어 연결에서 <b>엄마 전용 주소</b>를 복사</li>
    <li>아내분 폰의 단축어를 열어 <b>URL 콘텐츠 가져오기</b>의 주소만 <b>엄마 전용 주소로 바꿔 붙여 넣기</b> (중요! 안 바꾸면 아빠 기록으로 들어가요)</li>
    <li>8번(실행해 보기)과 9번(자동화)을 아내분 폰에서도 해요. 자동화는 공유로 복사되지 않아요.</li>
  </ol>
  <p class="small muted">아내분이 애플워치가 없으면 HRV · 안정 시 심박 · 수면은 비어 있고 걸음 · 활동 에너지만 들어와요. 워치를 사면 그대로 다 들어와요.</p>
</section>
<a class="btn" href="settings.php#shortcut">← 설정으로</a>
<?php page_end();
