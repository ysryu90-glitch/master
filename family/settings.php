<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/push.php';
require __DIR__ . '/lib/care.php';

$me = require_login();
check_csrf();
$message = '';
$error = '';

$presets = [
    'eunpyeong' => ['name' => '은평구', 'lat' => 37.6027, 'lon' => 126.9291],
    'sogong' => ['name' => '소공동', 'lat' => 37.5638, 'lon' => 126.9797],
    'pyeongtaek' => ['name' => '평택', 'lat' => 36.9921, 'lon' => 127.1128],
];
$roles = ['home' => '집', 'work' => '회사', 'parents' => '부모님 댁'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    try {
        switch (post('action')) {
            case 'profile':
                $pdo->prepare('UPDATE members SET name = ?, emoji = ?, kcal_target = ?, protein_target = ? WHERE id = ?')
                    ->execute([mb_substr(post('name') ?: $me['name'], 0, 40), mb_substr(post('emoji'), 0, 8), max(800, min(5000, (int) post('kcal'))), max(10, min(300, (int) post('protein'))), $me['id']]);
                $message = '내 정보를 저장했어요.';
                break;
            case 'password':
                if (!password_verify((string) post('current'), (string) $me['password_hash'])) throw new RuntimeException('지금 비밀번호가 맞지 않아요.');
                if (mb_strlen(post('new')) < 8) throw new RuntimeException('새 비밀번호는 8자 이상으로 정해 주세요.');
                $pdo->prepare('UPDATE members SET password_hash = ? WHERE id = ?')->execute([password_hash(post('new'), PASSWORD_DEFAULT), $me['id']]);
                $pdo->prepare('DELETE FROM sessions WHERE member_id = ? AND token_hash <> ?')->execute([$me['id'], hash('sha256', $_COOKIE[SESSION_COOKIE] ?? '')]);
                $message = '비밀번호를 바꿨어요. 다른 기기는 다시 로그인해야 해요.';
                break;
            case 'token':
                $pdo->prepare('UPDATE members SET shortcut_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $me['id']]);
                $message = '새 토큰을 만들었어요. 단축어의 토큰도 바꿔 주세요.';
                break;
            case 'kid':
                $kid = $pdo->query("SELECT id FROM members WHERE role = 'child' ORDER BY id LIMIT 1")->fetchColumn();
                if (post('kid_name') === '') break;
                if ($kid) {
                    $pdo->prepare('UPDATE members SET name = ?, kcal_target = ?, protein_target = ? WHERE id = ?')->execute([mb_substr(post('kid_name'), 0, 40), (int) post('kid_kcal') ?: 1400, (int) post('kid_protein') ?: 20, $kid]);
                } else {
                    $pdo->prepare("INSERT INTO members (slug, name, emoji, role, kcal_target, protein_target, sort) VALUES ('kid', ?, '👧', 'child', 1400, 20, 2)")->execute([mb_substr(post('kid_name'), 0, 40)]);
                }
                $message = '아이 정보를 저장했어요.';
                break;
            case 'icloud':
                set_setting('icloud_user', post('icloud_user'));
                if (post('icloud_password') !== '') set_setting('icloud_password', preg_replace('/\s+/', '', post('icloud_password')));
                set_setting('icloud_calendars', []);
                if (post('icloud_user') === '') {
                    set_setting('icloud_password', '');
                    $message = 'iCloud 연결을 해제했어요.';
                    break;
                }
                $found = caldav_calendars(true);
                $message = 'iCloud에 연결했어요. 캘린더 ' . count($found) . '개를 찾았어요. 아래에서 보여줄 캘린더를 골라 주세요.';
                break;
            case 'calendars':
                set_setting('icloud_selected', array_values(array_filter((array) ($_POST['selected'] ?? []), 'is_string')));
                set_setting('calendar_synced_at', '');
                $message = '보여줄 캘린더를 저장했어요.';
                break;
            case 'notify':
                $time = fn($v) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v : '';
                set_setting('notify_' . $me['id'], [
                    'morning' => post('morning_on') ? $time(post('morning')) : '',
                    'dinner' => post('dinner_on') ? $time(post('dinner')) : '',
                    'stale' => (bool) post('stale'),
                    'weekly' => (bool) post('weekly'),
                    'sick' => (bool) post('sick'),
                ]);
                $message = '알림 설정을 저장했어요.';
                break;
            case 'discover_keys':
                foreach (['tourapi_key', 'seoul_key'] as $k) {
                    if (post($k . '_clear')) set_setting($k, '');
                    elseif (post($k) !== '') set_setting($k, preg_replace('/\s+/', '', post($k)));
                }
                require_once __DIR__ . '/lib/weather.php';
                require_once __DIR__ . '/lib/discover.php';
                $r = discover_fetch();
                $message = "저장하고 받아 왔어요: 축제·행사 {$r['festival']}개 · 서울 문화행사 {$r['seoul']}개 · 새 장소 {$r['new']}곳";
                if ($r['errors']) throw new RuntimeException($message . ' · 오류: ' . implode(' / ', $r['errors']));
                break;
            case 'home':
                $locations = [];
                foreach ($roles as $role => $title) {
                    $p = $presets[post("loc_$role")] ?? null;
                    if ($p) $locations[] = ['role' => $role, 'title' => $title] + $p;
                }
                set_setting('locations', $locations);
                if (preg_match('/^\d{2}:\d{2}$/', post('dinner_time'))) set_setting('dinner_time', post('dinner_time'));
                $message = '저장했어요.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if (!$error) { flash($message); redirect('settings.php'); }
}

$me = member((int) $me['id']);
$kid = db()->query("SELECT * FROM members WHERE role = 'child' ORDER BY id LIMIT 1")->fetch() ?: null;
$base = public_base();
$prefs = notify_prefs((int) $me['id']);
$stmt = db()->prepare('SELECT received_at, body FROM health_raw WHERE member_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$me['id']]);
$last = $stmt->fetch();
$calendars = is_array(setting('icloud_calendars')) ? setting('icloud_calendars') : [];
$selected = setting('icloud_selected', []);
$currentLocations = [];
foreach (locations() as $l) foreach ($presets as $key => $p) if ($p['name'] === $l['name']) $currentLocations[$l['role']] = $key;

page_start('설정');
?>
<?php if ($error): ?><div class="flash" style="background:rgba(220,38,38,.1);color:var(--red)"><?= h($error) ?></div><?php endif; ?>

<nav class="jump" aria-label="설정 바로가기">
  <a href="#profile">🙂 내 정보</a><a href="#notify">🔔 알림</a><a href="#shortcut">📲 단축어</a><a href="#kid">👧 아이</a>
  <a href="#home">🏠 우리집</a><a href="#calendar">📅 캘린더</a><a href="#discover">🧺 나들이 데이터</a><a href="#board">📺 전광판</a>
</nav>

<section class="card" id="profile">
  <h2>🙂 내 정보</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <div class="grid2">
      <label>이름<input name="name" value="<?= h($me['name']) ?>"></label>
      <label>이모지<input name="emoji" value="<?= h($me['emoji']) ?>"></label>
      <label>하루 칼로리 목표<input name="kcal" type="number" inputmode="numeric" value="<?= (int) $me['kcal_target'] ?>"></label>
      <label>단백질 목표 (g)<input name="protein" type="number" inputmode="numeric" value="<?= (int) $me['protein_target'] ?>"></label>
    </div>
    <button class="btn primary">저장</button>
  </form>
  <details style="margin-top:12px"><summary class="small" style="color:var(--blue)">비밀번호 바꾸기</summary>
    <form method="post" class="form" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>지금 비밀번호<input name="current" type="password" autocomplete="current-password"></label>
      <label>새 비밀번호 (8자 이상)<input name="new" type="password" autocomplete="new-password"></label>
      <button class="btn">바꾸기</button>
    </form>
  </details>
</section>

<section class="card" id="notify">
  <h2>🔔 알림</h2>
  <div id="push-box" data-csrf="<?= h(csrf_token()) ?>">
    <ul class="pcheck" id="push-check"></ul>
    <p class="small" id="push-status" style="font-weight:700">확인 중…</p>
    <div class="btn-row">
      <button type="button" class="btn primary" id="push-on">이 기기에서 알림 받기</button>
      <button type="button" class="btn" id="push-test">🔔 테스트 알림</button>
      <button type="button" class="btn hidden" id="push-reset">↻ 알림 다시 연결</button>
    </div>
    <div id="push-results"></div>
    <div id="push-devices"></div>
    <p class="small muted" style="margin-top:8px">아이폰은 사파리 공유 › <b>홈 화면에 추가</b>한 아이콘으로 열어야 알림을 켤 수 있어요 (iOS 16.4 이상). 엄마 · 아빠 휴대폰에서 각각 켜 주세요.</p>
  </div>
  <form method="post" class="form" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="notify">
    <div class="inline"><label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="morning_on" value="1" <?= $prefs['morning'] ? 'checked' : '' ?> style="width:auto;margin:0"> 아침 요약 (준비 점수 · 일정 · 저녁)</label>
      <label><input type="time" name="morning" value="<?= h($prefs['morning'] ?: '07:30') ?>"></label></div>
    <div class="inline"><label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="dinner_on" value="1" <?= $prefs['dinner'] ? 'checked' : '' ?> style="width:auto;margin:0"> "오늘 저녁 집에서 드세요?"</label>
      <label><input type="time" name="dinner" value="<?= h($prefs['dinner'] ?: '16:00') ?>"></label></div>
    <label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="stale" value="1" <?= $prefs['stale'] ? 'checked' : '' ?> style="width:auto;margin:0"> 건강 기록이 이틀 넘게 안 들어오면</label>
    <label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="weekly" value="1" <?= $prefs['weekly'] ? 'checked' : '' ?> style="width:auto;margin:0"> 일요일 저녁 8시 주간 가족 리포트</label>
    <label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="sick" value="1" <?= $prefs['sick'] ? 'checked' : '' ?> style="width:auto;margin:0"> 아이 열날 때 해열제 다시 먹일 수 있는 시각</label>
    <button class="btn primary">저장</button>
  </form>
</section>

<section class="card" id="shortcut">
  <h2>📲 단축어 연결 (건강 기록)</h2>
  <p class="small muted">아이폰 '단축어'가 애플워치·아이폰 건강 기록을 이 사이트로 보내요. 처음 한 번만 만들면 돼요.</p>
  <div class="form">
    <label><?= h($me['name']) ?> 전용 보낼 주소 (단축어에 붙여 넣기)<input readonly value="<?= h($base) ?>/api/health.php?token=<?= h($me['shortcut_token']) ?>" onclick="this.select()"></label>
  </div>
  <div class="btn-row">
    <a class="btn primary" href="shortcut.php">단축어 만드는 방법 보기</a>
    <form method="post" data-confirm="토큰을 새로 만들면 기존 단축어는 다시 설정해야 해요. 계속할까요?"><?= csrf_field() ?><input type="hidden" name="action" value="token"><button class="btn">토큰 새로 만들기</button></form>
  </div>
  <p class="small muted" style="margin-top:10px">마지막으로 받은 기록: <?= $last ? h(date('n월 j일 H:i', strtotime($last['received_at']))) : '아직 없음' ?></p>
  <?php if ($last): ?><details><summary class="small muted">받은 내용 보기 (문제 확인용)</summary><pre class="small" style="white-space:pre-wrap;word-break:break-all"><?= h($last['body']) ?></pre></details><?php endif; ?>
</section>

<section class="card" id="kid">
  <h2>👧 아이</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="kid">
    <div class="grid3">
      <label>이름<input name="kid_name" value="<?= h($kid['name'] ?? '') ?>" placeholder="딸"></label>
      <label>칼로리 목표<input name="kid_kcal" type="number" value="<?= (int) ($kid['kcal_target'] ?? 1400) ?>"></label>
      <label>단백질 (g)<input name="kid_protein" type="number" value="<?= (int) ($kid['protein_target'] ?? 20) ?>"></label>
    </div>
    <button class="btn primary">저장</button>
    <p class="small muted" style="margin-top:8px">만 5세 기준 하루 약 1,400kcal · 단백질 20g (한국인 영양소 섭취기준)</p>
  </form>
</section>

<section class="card" id="home">
  <h2>🏠 우리집</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="home">
    <div class="grid3">
      <?php foreach ($roles as $role => $title): ?>
        <label><?= $title ?><select name="loc_<?= $role ?>">
          <?php foreach ($presets as $key => $p): ?><option value="<?= $key ?>" <?= ($currentLocations[$role] ?? '') === $key ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
        </select></label>
      <?php endforeach; ?>
    </div>
    <label>저녁 시간<input type="time" name="dinner_time" value="<?= h(dinner_time_setting()) ?>"></label>
    <button class="btn primary">저장</button>
  </form>
</section>

<section class="card" id="calendar">
  <h2>📅 iCloud 캘린더</h2>
  <p class="small muted">appleid.apple.com › 로그인 및 보안 › 앱 전용 암호에서 만든 암호를 넣어 주세요. Apple ID 원래 비밀번호는 쓰지 않아요.</p>
  <form data-busy="아이클라우드에 연결하고 캘린더를 찾는 중이에요…" method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="icloud">
    <label>Apple ID (이메일)<input name="icloud_user" type="email" value="<?= h((string) setting('icloud_user', '')) ?>" autocomplete="off"></label>
    <label>앱 전용 암호 <?= setting('icloud_password') ? '(저장됨 · 바꿀 때만 입력)' : '' ?><input name="icloud_password" type="password" placeholder="xxxx-xxxx-xxxx-xxxx" autocomplete="off"></label>
    <button class="btn primary">연결 · 캘린더 찾기</button>
  </form>
  <?php if ($calendars): ?>
    <form data-busy="고른 캘린더를 저장하고 일정을 불러오는 중이에요…" method="post" class="form" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="action" value="calendars">
      <h3>보여줄 캘린더 (아무것도 안 고르면 전부)</h3>
      <?php foreach ($calendars as $c): ?>
        <label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="selected[]" value="<?= h($c['name']) ?>" <?= in_array($c['name'], $selected, true) ? 'checked' : '' ?> style="width:auto;margin:0">
          <span class="dot" style="background:<?= h($c['color']) ?>"></span><?= h($c['name']) ?></label>
      <?php endforeach; ?>
      <button class="btn primary">저장</button>
    </form>
  <?php endif; ?>
</section>

<section class="card" id="discover">
  <h2>🧺 나들이 데이터</h2>
  <p class="small muted">축제 · 행사와 새로 생긴 곳을 매일 새벽에 받아와 주말 나들이 추천에 넣어요. 둘 다 무료 인증키예요.</p>
  <form data-busy="키를 저장하고 축제 · 행사 정보를 받아오는 중이에요 (최대 30초)…" method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="discover_keys">
    <label>한국관광공사 TourAPI 인증키 (공공데이터포털 · Decoding 키) <?= setting('tourapi_key') ? '✓ 저장됨' : '' ?>
      <input name="tourapi_key" type="password" autocomplete="off" placeholder="<?= setting('tourapi_key') ? '바꿀 때만 입력' : '' ?>"></label>
    <label>서울 열린데이터광장 인증키 (문화행사) <?= setting('seoul_key') ? '✓ 저장됨' : '' ?>
      <input name="seoul_key" type="password" autocomplete="off" placeholder="<?= setting('seoul_key') ? '바꿀 때만 입력' : '' ?>"></label>
    <button class="btn primary">저장하고 지금 받기</button>
  </form>
  <?php $ds = setting('discover_status'); if (is_array($ds)): ?>
    <p class="small muted" style="margin-top:8px">마지막: <?= h($ds['at']) ?> · 축제·행사 <?= (int) $ds['festival'] ?> · 서울 <?= (int) $ds['seoul'] ?> · 새 장소 <?= (int) $ds['new'] ?><?= !empty($ds['errors']) ? '<br>⚠️ ' . h(implode(' / ', $ds['errors'])) : '' ?></p>
  <?php endif; ?>
</section>

<section class="card" id="board">
  <h2>📺 전광판</h2>
  <p class="small muted">아이패드 사파리에서 이 주소를 열고 한 번 로그인한 뒤, 공유 › 홈 화면에 추가를 누르세요.</p>
  <div class="form"><label>전광판 주소<input readonly value="<?= h($base) ?>/board/" onclick="this.select()"></label></div>
  <a class="btn" href="board/">전광판 열기</a>
</section>

<section class="card">
  <a class="btn danger" href="logout.php">로그아웃</a>
  <p class="small muted" style="margin-top:8px">모든 기록(건강 · 식단과 사진 · 식탁 · 설정)은 NAS의 MariaDB(family_board)에 저장돼요.</p>
</section>
<?php
function dinner_time_setting(): string { return (string) setting('dinner_time', '18:30'); }
page_end();
