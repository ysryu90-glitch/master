<?php
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
page_start('단축어 만들기');
?>
<section class="card">
  <h2>준비물</h2>
  <div class="form">
    <label>보낼 주소 (복사해 두세요)<input readonly value="<?= h($base) ?>/api/health.php" onclick="this.select()"></label>
    <label><?= h($me['name']) ?>의 토큰<input readonly value="<?= h($me['shortcut_token']) ?>" onclick="this.select()"></label>
  </div>
  <p class="small muted">아내분은 아내분 계정으로 로그인한 뒤 이 화면을 열면 아내분 토큰이 보여요. 토큰이 다르면 기록이 섞이지 않아요.</p>
</section>

<section class="card">
  <h2>1. 단축어 만들기</h2>
  <ol class="small" style="padding-left:18px;line-height:1.8">
    <li>아이폰 <b>단축어</b> 앱 › 오른쪽 위 <b>+</b> › 이름을 <b>건강 기록 보내기</b>로 바꿔요.</li>
    <li>아래 표의 항목마다 <b>건강 샘플 찾기</b> 동작을 추가하고 조건을 맞춰요. 각 동작 바로 아래에 표에 적힌 동작을 붙여요.</li>
  </ol>
  <table class="small" style="width:100%;border-collapse:collapse">
    <tr style="text-align:left;color:var(--sub)"><th>보낼 이름</th><th>건강 샘플 찾기 조건</th><th>이어서</th></tr>
    <tr><td><b>hrv</b></td><td>심박 변이 · 시작일 <i>지난 1일</i></td><td>통계 계산: 평균</td></tr>
    <tr><td><b>rhr</b></td><td>안정 시 심박수 · 정렬 <i>시작일 최신순</i> · 제한 1</td><td>-</td></tr>
    <tr><td><b>resp</b></td><td>호흡수 · 시작일 <i>지난 1일</i></td><td>통계 계산: 평균</td></tr>
    <tr><td><b>steps</b></td><td>걸음 · 시작일 <i>오늘</i> · 그룹화 <i>일</i></td><td>-</td></tr>
    <tr><td><b>active_kcal</b></td><td>활동 에너지 · 시작일 <i>오늘</i> · 그룹화 <i>일</i></td><td>-</td></tr>
    <tr><td><b>exercise_min</b></td><td>운동하기 시간 · 시작일 <i>오늘</i> · 그룹화 <i>일</i></td><td>-</td></tr>
    <tr><td><b>sleep_core</b></td><td>수면 분석 · 값 <i>코어</i> · 시작일 <i>지난 1일</i></td><td>세부 사항 가져오기: 지속 시간 → 통계 계산: 합계</td></tr>
    <tr><td><b>sleep_deep</b></td><td>수면 분석 · 값 <i>깊은 수면</i> · 시작일 <i>지난 1일</i></td><td>(위와 같음)</td></tr>
    <tr><td><b>sleep_rem</b></td><td>수면 분석 · 값 <i>렘</i> · 시작일 <i>지난 1일</i></td><td>(위와 같음)</td></tr>
    <tr><td><b>weight</b> (선택)</td><td>체중 · 정렬 <i>최신순</i> · 제한 1</td><td>-</td></tr>
  </table>
  <p class="small muted" style="margin-top:8px">'그룹화: 일'을 쓰면 아이폰과 애플워치가 같이 센 걸음이 두 번 더해지지 않아요. 각 결과를 길게 눌러 <b>이름 변경</b>으로 hrv, rhr… 처럼 이름을 붙여 두면 3단계가 쉬워요.</p>
</section>

<section class="card">
  <h2>2. 날짜</h2>
  <p class="small"><b>날짜 형식 지정</b> 동작 추가 › 날짜: <i>현재 날짜</i> › 날짜 형식 <i>사용자 지정</i> › <code>yyyy-MM-dd</code></p>
</section>

<section class="card">
  <h2>3. 보내기</h2>
  <ol class="small" style="padding-left:18px;line-height:1.8">
    <li><b>URL 콘텐츠 가져오기</b> 동작 추가 › URL에 위의 <b>보낼 주소</b> 붙여 넣기</li>
    <li>펼치기(›) › 방법 <b>POST</b> › 요청 본문 <b>JSON</b></li>
    <li><b>새로운 필드 추가</b>로 아래 항목을 하나씩 넣어요.
      <ul>
        <li><code>token</code> (텍스트): 위의 토큰</li>
        <li><code>date</code> (텍스트): 2단계의 <i>형식 지정된 날짜</i></li>
        <li><code>hrv</code>, <code>rhr</code>, <code>resp</code>, <code>steps</code>, <code>active_kcal</code>, <code>exercise_min</code>, <code>sleep_core</code>, <code>sleep_deep</code>, <code>sleep_rem</code>, <code>weight</code> (숫자): 1단계의 각 결과</li>
      </ul>
    </li>
    <li>(선택) <b>사전 값 가져오기</b> › 키 <code>message</code> › <b>알림 표시</b>를 붙이면 "오늘 준비 점수 7.6" 같은 알림이 떠요.</li>
  </ol>
  <p class="small muted">아래쪽 ▶ 버튼으로 한 번 실행해 보세요. 처음엔 건강 데이터 접근을 물어보면 <b>모두 허용</b>을 눌러요. 설정 화면의 '받은 내용 보기'에서 들어온 값을 확인할 수 있어요.</p>
</section>

<section class="card">
  <h2>4. 자동으로 실행</h2>
  <p class="small">아이폰이 잠겨 있으면 단축어가 건강 데이터를 읽지 못해요. 그래서 <b>자주 여는 앱을 열 때</b> 실행되게 하는 게 가장 확실해요.</p>
  <ol class="small" style="padding-left:18px;line-height:1.8">
    <li>단축어 앱 › 아래 <b>자동화</b> 탭 › <b>+</b> › <b>앱</b></li>
    <li>앱 선택: 카카오톡처럼 매일 여는 앱 › <b>열릴 때</b> 체크</li>
    <li><b>즉시 실행</b> 선택 › 다음 › <b>건강 기록 보내기</b></li>
  </ol>
  <p class="small muted">하루에 여러 번 실행돼도 같은 날 기록은 최신 값으로 덮어써서 괜찮아요. 아침에 한 번, 저녁에 한 번 이상 실행되면 충분해요.</p>
</section>
<a class="btn" href="settings.php#shortcut">← 설정으로</a>
<?php page_end();
