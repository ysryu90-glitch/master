<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/table.php';
require __DIR__ . '/lib/care.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/discover.php';
require __DIR__ . '/lib/ledger.php';
require __DIR__ . '/lib/foods.php';

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

// 다녀온 나들이 중 아직 일기가 없는 것
$pendingDiary = array_values(array_filter(array_map(fn($pl) => ($pp = place($pl['place_id'])) ? $pl + ['name' => $pp['name']] : null, diary_pending_plans())));

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
        $outing = ['day' => $d, 'wx' => $ctx['wx'], 'picks' => array_slice(ranked($ctx, discover_candidates($d, $home, $par)), 0, 2)];
        break;
    }
}

page_start('홈', 'home', ['class' => 'home']);
?>

<?php
$h = (int) date('G');
$greet = $h >= 5 && $h < 11 ? '좋은 아침이에요' : ($h < 17 && $h >= 11 ? '좋은 오후예요' : ($h >= 17 && $h < 22 ? '좋은 저녁이에요' : '편안한 밤 보내세요'));
$myAtt = $att[(int) $me['id']] ?? null;
$todoMeds = array_values(array_filter($meds, fn($m) => !$m['taken_at']));
$doneMeds = count($meds) - count($todoMeds);
$homeLoc = array_values(array_filter(locations(), fn($l) => $l['role'] === 'home'));
$monthSpent = expenses_summary(date('Y-m'))['out'];
$todaySpent = array_sum(array_map(fn($x) => $x['kind'] === 'out' ? (int) $x['amount'] : 0, expenses_of_day($today)));
$budget = ledger_budget();
$toReview = (int) db()->query('SELECT COUNT(*) FROM expenses WHERE checked = 0')->fetchColumn();
?>
<section class="hello">
  <div class="d"><?= date('n월 j일') ?> <?= ['일', '월', '화', '수', '목', '금', '토'][(int) date('w')] ?>요일<?= isset(HOLIDAYS[$today]) ? ' · ' . h(HOLIDAYS[$today]) : '' ?></div>
  <h1><?= $greet ?>, <?= h($me['name']) ?>님</h1>
  <?php if ($homeLoc): ?><div class="wxmini" data-weather='<?= h(json_encode($homeLoc, JSON_UNESCAPED_UNICODE)) ?>' data-compact><span class="muted small">날씨 불러오는 중…</span></div><?php endif; ?>
</section>

<?php foreach ($stale as [$a, $lastAt]): ?>
<section class="card alert">
  <b>⚠️ <?= h($a['name']) ?> 건강 기록이 <?= (int) floor((time() - strtotime($lastAt)) / 86400) ?>일째 안 들어와요</b>
  <p class="small muted" style="margin:4px 0 0">마지막: <?= h(date('n월 j일 H:i', strtotime($lastAt))) ?> · 아이폰 단축어 자동화가 꺼졌는지 확인해 주세요. <a href="shortcut.php">단축어 안내 ›</a></p>
</section>
<?php endforeach; ?>

<?php foreach ($sickKids as [$k, $t, $next]): ?>
<a href="sick.php?m=<?= (int) $k['id'] ?>" class="card sickcard">
  <div class="card-head"><h2>🤒 <?= h($k['name']) ?> 돌보는 중</h2><span class="more">기록 ›</span></div>
  <?php if ($t): ?><p><b style="font-size:22px"><?= number_format((float) $t['temp'], 1) ?>°</b> <span class="small muted"><?= date('H:i', strtotime($t['at'])) ?> 측정</span></p><?php endif; ?>
  <p class="small"><?php foreach ($next as $n): ?><?= h($n['name']) ?> <b><?= $n['at'] <= time() ? '지금 가능' : date('H:i', $n['at']) . '부터' ?></b> &nbsp; <?php endforeach; ?></p>
</a>
<?php endforeach; ?>

<div class="home-cols">

<section class="card todo" id="meds">
  <div class="card-head"><h2>✅ 오늘 할 일</h2></div>
  <?php foreach ($todoMeds as $med): ?>
    <div class="todo-row">
      <span class="ic">💊</span><span class="grow"><b><?= h($med['name']) ?></b> <span class="small muted"><?= h($med['time']) ?></span></span>
      <form method="post" action="meds.php"><?= csrf_field() ?><input type="hidden" name="action" value="med_take"><input type="hidden" name="med" value="<?= (int) $med['id'] ?>"><input type="hidden" name="back" value="home"><button class="btn small primary">먹었어요</button></form>
    </div>
  <?php endforeach; ?>
  <?php if (!$myAtt): ?>
    <div class="todo-row col">
      <span class="grow"><span class="ic">🍲</span> <b>오늘 저녁 집에서 드세요?</b> <span class="small muted"><?= h(dinner_time()) ?></span></span>
      <form method="post" class="btn-row">
        <?= csrf_field() ?>
        <button class="btn small" name="status" value="home">🏠 집에서</button>
        <button class="btn small" name="status" value="late" onclick="var t=prompt('몇 시쯤 도착해요? (예: 20:30)','20:00'); if(t===null) return false; this.form.late_time.value=t;">🕗 늦어요</button>
        <button class="btn small" name="status" value="out">🙅 따로</button>
        <input type="hidden" name="late_time" value="">
      </form>
    </div>
  <?php endif; ?>
  <?php if ($pendingDiary): $pl = $pendingDiary[0]; ?>
    <a class="todo-row" href="diary_edit.php?cat=outing&place=<?= rawurlencode($pl['place_id']) ?>&day=<?= h($pl['day']) ?>">
      <span class="ic">📔</span><span class="grow"><b><?= date('n/j', strtotime($pl['day'])) ?> <?= h($pl['name']) ?></b> 일기 쓰기<div class="small muted">사진 · 별점 남기기</div></span><span class="more">›</span>
    </a>
  <?php endif; ?>
  <?php if ($toReview): ?>
    <a class="todo-row" href="ledger.php?review=1#review">
      <span class="ic">📲</span><span class="grow"><b>카드 기록 <?= $toReview ?>건</b> 항목 확인<div class="small muted">자동으로 들어왔는데 항목을 못 정했어요</div></span><span class="more">›</span>
    </a>
  <?php endif; ?>
  <?php if (!$todoMeds && $myAtt && !$pendingDiary && !$toReview): ?>
    <p class="done">🎉 오늘 할 일을 다 했어요<?= $doneMeds ? ' · 💊 약 ' . $doneMeds . '개 먹음' : '' ?></p>
  <?php elseif ($doneMeds): ?>
    <p class="small muted" style="margin:8px 0 0">💊 오늘 약 <?= $doneMeds ?>개 먹음 · <a href="meds.php">약 기록 ›</a></p>
  <?php endif; ?>
</section>

<?php if ($ready): [$levelName, $levelClass, $levelMsg] = readiness_level($ready['score']); ?>
<section class="readiness <?= $levelClass ?>">
  <div class="label">오늘의 준비 점수<?= !empty($ready['calibrated']) ? ' · 공식 점수로 보정됨' : '' ?></div>
  <div class="score"><?= number_format($ready['score'], 1) ?><small> / 10</small></div>
  <div class="level"><?= h($levelName) ?></div>
  <div class="msg"><?= h($levelMsg) ?></div>
  <details class="why">
    <summary>점수 근거 보기</summary>
    <div class="components">
      <?php foreach ($ready['components'] as $c): ?>
        <div class="comp">
          <div class="row"><span><?= h($c['title']) ?></span><span><?= $c['score'] < 40 ? '낮음' : ($c['score'] < 70 ? '보통' : '좋음') ?></span></div>
          <div class="bar"><i style="width:<?= (int) $c['score'] ?>%"></i></div>
          <div class="detail"><?= h($c['detail']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
</section>
<?php else: ?>
<section class="readiness none<?= $counted > 0 ? " slim" : "" ?>">
  <div class="label">오늘의 준비 점수</div>
  <?php if ($counted === 0): ?>
    <div class="level">아직 건강 기록이 없어요</div>
    <div class="msg">아이폰 단축어를 한 번 만들어 두면 매일 자동으로 들어와요.</div>
    <p style="margin-top:12px"><a class="btn small" href="shortcut.php">단축어 연결하기</a></p>
  <?php elseif (!$todayRow): ?>
    <div class="level">오늘 기록을 기다리는 중</div>
    <div class="msg">아이폰 단축어가 실행되면 바로 계산돼요.</div>
  <?php else: ?>
    <div class="level">기준선을 모으는 중 (<?= min($counted, 7) ?>/7일)</div>
    <div class="msg">평소 상태를 알아야 점수를 낼 수 있어서, 일주일쯤 기록이 쌓이면 점수가 나와요.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card tonight">
  <div class="card-head"><h2>🍲 오늘 저녁 <span class="small muted"><?= h(dinner_time()) ?></span></h2><a class="more" href="table.php">식탁 ›</a></div>
  <div class="dish"><?= $plan ? h($plan['dish']) : '<span class="muted" style="font-size:17px">아직 메뉴를 안 정했어요 · <a href="table.php">정하기</a></span>' ?></div>
  <?php foreach ($conflicts as $c): ?><p class="small" style="color:var(--orange)">⚠️ <?= h($c) ?></p><?php endforeach; ?>
  <div class="attend">
    <?php foreach (members() as $m): $a = $att[(int) $m['id']] ?? null; ?>
      <span class="chip <?= $a ? 'st-' . h($a['status']) : '' ?>"><?= h($m['emoji'] . ' ' . $m['name']) ?> · <?= $a ? h(ATTENDANCE[$a['status']][1]) . ($a['late_time'] ? ' ' . h($a['late_time']) : '') : ($m['role'] === 'child' ? '함께' : '?') ?></span>
    <?php endforeach; ?>
  </div>
  <?php if ($myAtt): ?>
    <form method="post" class="btn-row" style="margin-top:10px">
      <?= csrf_field() ?>
      <span class="small muted" style="align-self:center">내 답 바꾸기</span>
      <button class="btn small" name="status" value="home">🏠</button>
      <button class="btn small" name="status" value="late" onclick="var t=prompt('몇 시쯤 도착해요? (예: 20:30)','20:00'); if(t===null) return false; this.form.late_time.value=t;">🕗</button>
      <button class="btn small" name="status" value="out">🙅</button>
      <input type="hidden" name="late_time" value="">
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h2>📅 오늘 일정</h2><a class="more" href="calendar.php">전체 ›</a></div>
  <?php if (!$events): ?><div class="empty"><?= setting('icloud_user') ? '오늘은 일정이 없어요.' : '<a href="settings.php#calendar">iCloud 캘린더를 연결</a>하면 여기에 보여요.' ?></div><?php endif; ?>
  <ul class="list">
    <?php foreach ($events as $e): ?>
      <li><span class="dot" style="background:<?= h($e['color']) ?>"></span>
        <span class="time"><?= $e['all_day'] ? '종일' : substr($e['start_at'], 11, 5) ?></span>
        <span class="grow"><span class="title"><?= h($e['title']) ?></span><?= $e['location'] ? '<div class="sub">' . h($e['location']) . '</div>' : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="card">
  <div class="card-head"><h2>❤️ 오늘 건강</h2><a class="more" href="health.php">건강 ›</a></div>
  <div class="minis">
    <div><span class="k">걸음</span><b><?= num($todayRow['steps'] ?? null) ?></b></div>
    <div><span class="k">수면</span><b><?= isset($todayRow['sleep_min']) ? intdiv((int) $todayRow['sleep_min'], 60) . '<small>h</small>' . ((int) $todayRow['sleep_min'] % 60) . '<small>m</small>' : '-' ?></b></div>
    <div><span class="k">HRV</span><b><?= num($todayRow['hrv'] ?? null) ?><small>ms</small></b></div>
    <div><span class="k">심박</span><b><?= num($todayRow['rhr'] ?? null) ?><small>bpm</small></b></div>
  </div>
  <a class="mealline" href="meals.php">
    <span>🍚 식단 <?= (int) $food['meals'] ?>끼</span>
    <span class="meter orange"><i style="width:<?= min(100, $food['kcal'] / max(1, $me['kcal_target']) * 100) ?>%"></i></span>
    <span class="small muted"><?= num($food['kcal']) ?> / <?= num($me['kcal_target']) ?> kcal ›</span>
  </a>
  <?php foreach ($others as $o): $oh = readiness_history((int) $o['id'], 1)[$today] ?? null; ?>
    <p class="small muted" style="margin:10px 0 0"><?= h($o['emoji'] . ' ' . $o['name']) ?> 준비 점수: <b><?= $oh ? number_format($oh['score'], 1) . ' · ' . h(readiness_level($oh['score'])[0]) : '아직 없음' ?></b></p>
  <?php endforeach; ?>
</section>

<section class="card homeledger">
  <div class="card-head"><h2>💰 이번 달 가계부</h2><a class="more" href="ledger.php">달력 ›</a></div>
  <div class="hl-row">
    <a href="ledger.php" class="hl-sum"><span class="k">이번 달 쓴 돈</span><b><?= won($monthSpent) ?></b>
      <span class="small muted">오늘 <?= $todaySpent ? won($todaySpent) : '0원' ?></span></a>
    <a class="btn primary" href="ledger.php?add=1#form">＋ 적기</a>
  </div>
  <?php if ($budget): $left = $budget - $monthSpent; ?>
    <div class="meter <?= $monthSpent >= $budget ? 'red' : ($monthSpent >= $budget * 0.8 ? 'orange' : '') ?>" style="height:8px;margin-top:12px"><i style="width:<?= min(100, $monthSpent / $budget * 100) ?>%"></i></div>
    <p class="small muted" style="margin:6px 0 0">예산 <?= won($budget, true) ?> · <?= $left >= 0 ? '남은 돈 <b style="color:var(--text)">' . won($left, true) . '</b>' : '<b style="color:var(--red)">' . won(-$left, true) . ' 넘었어요</b>' ?></p>
  <?php endif; ?>
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

</div>

<?php $kid0 = members('child')[0] ?? null; ?>
<button type="button" class="lfab" data-open-sheet="quick" aria-label="빠른 기록">＋</button>
<div class="sheet" id="quick" hidden role="dialog" aria-modal="true" aria-labelledby="quick-title">
  <div class="panel">
    <div class="sheet-head"><h2 id="quick-title">무엇을 적을까요?</h2><button type="button" class="x" data-sheet-close aria-label="닫기">✕</button></div>
    <div class="quickgrid">
      <a href="ledger.php?add=1#form"><span class="ic">💰</span><b>쓴 돈</b><span>가계부에 적기</span></a>
      <a href="meal_edit.php?m=<?= (int) $me['id'] ?>"><span class="ic">🍚</span><b>내 식단</b><span><?= h(MEAL_TYPES[meal_type_for_now()][0]) ?> 기록</span></a>
      <?php if ($kid0): ?><a href="meal_edit.php?m=<?= (int) $kid0['id'] ?>"><span class="ic"><?= h($kid0['emoji']) ?></span><b><?= h($kid0['name']) ?> 식단</b><span>먹은 것 기록</span></a><?php endif; ?>
      <a href="diary_edit.php?cat=daily"><span class="ic">📔</span><b>일기</b><span>사진 · 한 줄</span></a>
      <?php if ($kid0): ?><a href="sick.php?m=<?= (int) $kid0['id'] ?>"><span class="ic">🌡</span><b>체온 · 해열제</b><span><?= h($kid0['name']) ?> 아플 때</span></a><?php endif; ?>
      <a href="table.php"><span class="ic">🛒</span><b>장보기</b><span>살 것 적기</span></a>
    </div>
  </div>
</div>
<?php page_end('home');
