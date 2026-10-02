<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/table.php';
require __DIR__ . '/lib/care.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/places.php';

$me = require_login();
check_csrf();
$today = today();

// 약 먹었어요
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'med_take') {
    $stmt = db()->prepare('SELECT id FROM medications WHERE id = ? AND member_id = ?');
    $stmt->execute([(int) post('med'), $me['id']]);
    if ($stmt->fetchColumn()) {
        db()->prepare('INSERT IGNORE INTO medication_logs (med_id, day, taken_at) VALUES (?, CURDATE(), NOW())')->execute([(int) post('med')]);
    }
    redirect('index.php#meds');
}

// 오늘 저녁 출석 바로 바꾸기
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset(ATTENDANCE[post('status')])) {
    db()->prepare('REPLACE INTO dinner_attendance (day, member_id, status, late_time, updated_at) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$today, $me['id'], post('status'), post('late_time')]);
    redirect('index.php');
}

calendar_refresh_if_stale();
$history = readiness_history((int) $me['id'], 7);
$ready = $history[$today] ?? null;
$rows = health_rows((int) $me['id'], 2);
$todayRow = $rows[$today] ?? null;
$counted = health_days_count((int) $me['id']);

$plan = dinner_plan($today);
$att = attendance($today);
$events = calendar_events($today, $today);
$conflicts = dinner_conflicts($today);

$stmt = db()->prepare('SELECT COALESCE(SUM(i.kcal * i.servings),0) kcal, COALESCE(SUM(i.protein * i.servings),0) protein, COUNT(DISTINCT m.id) meals
    FROM meals m LEFT JOIN meal_items i ON i.meal_id = m.id WHERE m.member_id = ? AND m.day = ?');
$stmt->execute([$me['id'], $today]);
$food = $stmt->fetch();

$others = array_filter(members('adult'), fn($m) => (int) $m['id'] !== (int) $me['id']);
$meds = medications_of((int) $me['id']);

// 건강 기록이 이틀 넘게 안 들어온 사람
$stale = [];
foreach (members('adult') as $a) {
    $stmt = db()->prepare('SELECT MAX(updated_at) FROM health_days WHERE member_id = ?');
    $stmt->execute([$a['id']]);
    $lastAt = $stmt->fetchColumn();
    if ($lastAt && strtotime($lastAt) < time() - 48 * 3600) $stale[] = [$a, $lastAt];
}

// 아이가 최근 24시간 아팠으면
$sickKids = [];
foreach (members('child') as $k) {
    $logs = sick_logs((int) $k['id'], 24);
    if ($logs) $sickKids[] = [$k, last_temp($logs), fever_next($logs)];
}

// 이번 주말 나들이 미리보기 (목~일, 다가오는 첫 쉬는 날 기준)
$outing = null;
if ((int) date('N') >= 4) {
    for ($i = (int) date('G') >= 18 ? 1 : 0; $i <= 3; $i++) {
        $d = date('Y-m-d', strtotime("+$i day"));
        if (!is_day_off($d)) continue;
        $locs = [];
        foreach (locations() as $l) $locs[$l['role']] = $l;
        $home = $locs['home'] ?? default_locations()[0];
        $par = $locs['parents'] ?? default_locations()[2];
        $readyScores = array_filter(array_map(fn($a) => (readiness_history((int) $a['id'], 1)[today()]['score'] ?? null), members('adult')), fn($v) => $v !== null);
        $tiredNow = $readyScores && array_sum($readyScores) / count($readyScores) < 6;
        $recentVisits = [];
        foreach (db()->query("SELECT place_id, MAX(day) d FROM outing_logs WHERE kind = 'visit' AND day > DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY place_id") as $r) {
            $recentVisits[$r['place_id']] = (int) round((strtotime(today()) - strtotime($r['d'])) / 86400);
        }
        $likedIds = db()->query("SELECT place_id FROM outing_logs WHERE kind = 'like'")->fetchAll(PDO::FETCH_COLUMN);
        $ctx = day_context($d, daily_forecast((float) $home['lat'], (float) $home['lon']), daily_forecast((float) $par['lat'], (float) $par['lon']), '', $tiredNow, $recentVisits, $likedIds);
        $outing = ['day' => $d, 'wx' => $ctx['wx'], 'picks' => array_slice(ranked($ctx), 0, 2)];
        break;
    }
}

page_start('오늘', 'today');
?>

<?php foreach ($stale as [$a, $lastAt]): ?>
<section class="card" style="border:1.5px solid var(--orange)">
  <b>⚠️ <?= h($a['name']) ?> 건강 기록이 <?= (int) floor((time() - strtotime($lastAt)) / 86400) ?>일째 안 들어와요</b>
  <p class="small muted" style="margin:4px 0 0">마지막: <?= h(date('n월 j일 H:i', strtotime($lastAt))) ?> · 아이폰 단축어 자동화가 꺼졌는지 확인해 주세요. <a href="shortcut.php">단축어 안내 ›</a></p>
</section>
<?php endforeach; ?>

<?php foreach ($sickKids as [$k, $t, $next]): ?>
<a href="sick.php?m=<?= (int) $k['id'] ?>" style="color:inherit">
<section class="card" style="background:linear-gradient(135deg,rgba(249,115,22,.14),transparent)">
  <div class="card-head"><h2>🤒 <?= h($k['name']) ?> 돌보는 중</h2><span class="more">기록 ›</span></div>
  <?php if ($t): ?><p><b style="font-size:22px"><?= number_format((float) $t['temp'], 1) ?>°</b> <span class="small muted"><?= date('H:i', strtotime($t['at'])) ?> 측정</span></p><?php endif; ?>
  <p class="small"><?php foreach ($next as $n): ?><?= h($n['name']) ?> <b><?= $n['at'] <= time() ? '지금 가능' : date('H:i', $n['at']) . '부터' ?></b> &nbsp; <?php endforeach; ?></p>
</section>
</a>
<?php endforeach; ?>

<?php if ($ready): [$levelName, $levelClass, $levelMsg] = readiness_level($ready['score']); ?>
<section class="readiness <?= $levelClass ?>">
  <div class="label">오늘의 준비 점수<?= !empty($ready['calibrated']) ? ' · 공식 점수로 보정됨' : '' ?></div>
  <div class="score"><?= number_format($ready['score'], 1) ?><small> / 10</small></div>
  <div class="level"><?= h($levelName) ?></div>
  <div class="msg"><?= h($levelMsg) ?></div>
  <div class="components">
    <?php foreach ($ready['components'] as $c): ?>
      <div class="comp">
        <div class="row"><span><?= h($c['title']) ?></span><span><?= $c['score'] < 40 ? '낮음' : ($c['score'] < 70 ? '보통' : '좋음') ?></span></div>
        <div class="bar"><i style="width:<?= (int) $c['score'] ?>%"></i></div>
        <div class="detail"><?= h($c['detail']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<section class="readiness none">
  <div class="label">오늘의 준비 점수</div>
  <?php if ($counted === 0): ?>
    <div class="level">아직 건강 기록이 없어요</div>
    <div class="msg">설정 › 단축어 연결에서 아이폰 단축어를 한 번 만들어 두면 매일 자동으로 들어와요.</div>
    <p style="margin-top:12px"><a class="btn small" href="settings.php#shortcut">단축어 연결하기</a></p>
  <?php elseif (!$todayRow): ?>
    <div class="level">오늘 기록을 기다리는 중</div>
    <div class="msg">아이폰 단축어가 실행되면 바로 계산돼요.</div>
  <?php else: ?>
    <div class="level">기준선을 모으는 중 (<?= min($counted, 7) ?>/7일)</div>
    <div class="msg">평소 상태를 알아야 점수를 낼 수 있어서, 일주일쯤 기록이 쌓이면 점수가 나와요.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>오늘 몸 상태</h2><a class="more" href="health.php">자세히 ›</a></div>
  <div class="grid2">
    <div class="stat"><div class="k">걸음</div><div class="v"><?= num($todayRow['steps'] ?? null) ?></div></div>
    <div class="stat"><div class="k">활동 에너지</div><div class="v"><?= num($todayRow['active_kcal'] ?? null) ?><small>kcal</small></div></div>
    <div class="stat"><div class="k">지난밤 수면</div><div class="v"><?= isset($todayRow['sleep_min']) ? intdiv((int) $todayRow['sleep_min'], 60) . '<small>시간</small> ' . ((int) $todayRow['sleep_min'] % 60) . '<small>분</small>' : '-' ?></div></div>
    <div class="stat"><div class="k">HRV · 안정 심박</div><div class="v"><?= num($todayRow['hrv'] ?? null) ?><small>ms</small> <?= num($todayRow['rhr'] ?? null) ?><small>bpm</small></div></div>
  </div>
  <?php foreach ($others as $o):
      $oh = readiness_history((int) $o['id'], 1)[$today] ?? null; ?>
    <p class="small muted" style="margin:10px 0 0"><?= h($o['emoji'] . ' ' . $o['name']) ?> 오늘 준비 점수: <b><?= $oh ? number_format($oh['score'], 1) . ' · ' . h(readiness_level($oh['score'])[0]) : '아직 없음' ?></b></p>
  <?php endforeach; ?>
</section>

<?php if ($meds): ?>
<section class="card" id="meds">
  <div class="card-head"><h2>💊 오늘 약</h2><a class="more" href="settings.php#meds">관리 ›</a></div>
  <?php foreach ($meds as $med): ?>
    <div class="person">
      <span class="who" style="width:auto;flex:1"><?= h($med['name']) ?> <span class="small muted"><?= h($med['time']) ?> · 최근 7일 <?= medication_streak((int) $med['id']) ?>/7</span></span>
      <?php if ($med['taken_at']): ?>
        <span style="color:var(--accent);font-weight:700">✓ <?= date('H:i', strtotime($med['taken_at'])) ?></span>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="med_take"><input type="hidden" name="med" value="<?= (int) $med['id'] ?>"><button class="btn small primary">먹었어요</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card tonight">
  <div class="card-head"><h2>오늘 저녁 <?= h(dinner_time()) ?></h2><a class="more" href="table.php">식탁 ›</a></div>
  <div class="dish"><?= $plan ? h($plan['dish']) : '<span class="muted" style="font-size:18px">아직 메뉴를 안 정했어요</span>' ?></div>
  <?php foreach ($conflicts as $c): ?><p class="small" style="color:var(--orange)">⚠️ <?= h($c) ?></p><?php endforeach; ?>
  <?php foreach (members() as $m): $a = $att[(int) $m['id']] ?? null; ?>
    <div class="person"><span class="who"><?= h($m['emoji'] . ' ' . $m['name']) ?></span>
      <span class="state"><?= $a ? h(ATTENDANCE[$a['status']][1]) . ($a['late_time'] ? ' (' . h($a['late_time']) . ')' : '') : ($m['role'] === 'child' ? '함께' : '아직 몰라요') ?></span></div>
  <?php endforeach; ?>
  <form method="post" class="btn-row" style="margin-top:10px">
    <?= csrf_field() ?>
    <button class="btn small" name="status" value="home">🏠 집에서</button>
    <button class="btn small" name="status" value="late" onclick="var t=prompt('몇 시쯤 도착해요? (예: 20:30)','20:00'); if(t===null) return false; this.form.late_time.value=t;">🕗 늦어요</button>
    <button class="btn small" name="status" value="out">🙅 따로</button>
    <input type="hidden" name="late_time" value="">
  </form>
</section>

<section class="card">
  <div class="card-head"><h2>오늘 일정</h2><a class="more" href="calendar.php">전체 ›</a></div>
  <?php if (!$events): ?><div class="empty"><?= setting('icloud_user') ? '오늘은 일정이 없어요.' : '설정에서 iCloud 캘린더를 연결하면 여기에 보여요.' ?></div><?php endif; ?>
  <ul class="list">
    <?php foreach ($events as $e): ?>
      <li><span class="dot" style="background:<?= h($e['color']) ?>"></span>
        <span class="time"><?= $e['all_day'] ? '종일' : substr($e['start_at'], 11, 5) ?></span>
        <span class="grow"><span class="title"><?= h($e['title']) ?></span><?= $e['location'] ? '<div class="sub">' . h($e['location']) . '</div>' : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="card">
  <div class="card-head"><h2>오늘 식단</h2><a class="more" href="meals.php">기록하기 ›</a></div>
  <div class="macro"><span>칼로리</span><div class="meter orange"><i style="width:<?= min(100, $food['kcal'] / max(1, $me['kcal_target']) * 100) ?>%"></i></div><span class="n"><?= num($food['kcal']) ?> / <?= num($me['kcal_target']) ?></span></div>
  <div class="macro"><span>단백질</span><div class="meter blue"><i style="width:<?= min(100, $food['protein'] / max(1, $me['protein_target']) * 100) ?>%"></i></div><span class="n"><?= num($food['protein']) ?> / <?= num($me['protein_target']) ?>g</span></div>
  <p class="small muted" style="margin-top:8px"><?= (int) $food['meals'] ?>끼 기록</p>
</section>

<?php if ($outing && $outing['picks']): $wd = ['일', '월', '화', '수', '목', '금', '토']; ?>
<section class="card">
  <div class="card-head"><h2>🧺 <?= date('n/j', strtotime($outing['day'])) ?> (<?= $wd[(int) date('w', strtotime($outing['day']))] ?>) 어디 갈까?</h2><a class="more" href="outing.php">더 보기 ›</a></div>
  <?php if ($outing['wx']): ?><p class="small muted"><?= $outing['wx']['icon'] ?> <?= round($outing['wx']['min']) ?>° / <?= round($outing['wx']['max']) ?>° · 비 <?= (int) $outing['wx']['rain'] ?>%</p><?php endif; ?>
  <?php foreach ($outing['picks'] as $i => $p): ?>
    <div class="person"><span class="who" style="width:auto;flex:1"><?= $i + 1 ?>. <?= h($p['name']) ?>
      <div class="small muted" style="font-weight:500"><?= h(implode(' · ', array_slice($p['why'], 0, 2))) ?></div></span>
      <span class="small muted">약 <?= (int) $p['minutes'] ?>분</span></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>날씨</h2></div>
  <div data-weather='<?= h(json_encode(locations(), JSON_UNESCAPED_UNICODE)) ?>'><div class="empty">날씨를 불러오는 중…</div></div>
</section>

<?php page_end('today');
