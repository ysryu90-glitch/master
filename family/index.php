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
require __DIR__ . '/lib/todo.php';
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
$myTodos = todos_due_today((int) $me['id']);
$otherTodos = [];
foreach (db()->query("SELECT owner_id, COUNT(*) n FROM todos WHERE done = 0 AND (due_day IS NULL OR due_day <= CURDATE() + INTERVAL 1 DAY) AND (owner_id IS NULL OR owner_id <> " . (int) $me['id'] . ") GROUP BY owner_id") as $r) $otherTodos[$r['owner_id'] === null ? 'both' : (int) $r['owner_id']] = (int) $r['n'];
$shopLeft = db()->query('SELECT name FROM shopping WHERE done = 0 ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);
$laterTodos = (int) db()->query('SELECT COUNT(*) FROM todos WHERE done = 0 AND (due_day IS NULL OR due_day > CURDATE()) AND (owner_id = ' . (int) $me['id'] . ' OR owner_id IS NULL)')->fetchColumn();
?>
<section class="hello">
  <div class="d"><?= date('n월 j일') ?> <?= ['일', '월', '화', '수', '목', '금', '토'][(int) date('w')] ?>요일<?= isset(HOLIDAYS[$today]) ? ' · ' . h(HOLIDAYS[$today]) : '' ?></div>
  <h1><?= $greet ?>, <?= h($me['name']) ?>님</h1>
  <?php if ($homeLoc): ?><div class="wxmini" data-weather='<?= h(json_encode($homeLoc, JSON_UNESCAPED_UNICODE)) ?>' data-compact><span class="muted small">날씨 불러오는 중…</span></div><?php endif; ?>
</section>

<?php
$attHome = count(array_filter($att, fn($a) => in_array($a['status'], ['home', 'late'], true)));
$budgetPct = $budget ? min(100, $monthSpent / $budget * 100) : null;
?>
<nav class="glance" aria-label="한눈에">
  <a href="todo.php" class="g-todo">
    <span class="gi">✅</span><span class="gk">오늘 할 일</span>
    <b><?= count($myTodos) ?><small>개</small></b>
    <span class="gs"><?= $myTodos ? h(mb_strimwidth($myTodos[0]['title'], 0, 16, '…')) : ($laterTodos ? '다음 할 일 ' . $laterTodos . '개' : '모두 끝냈어요') ?></span>
  </a>
  <a href="ledger.php" class="g-money">
    <span class="gi">💰</span><span class="gk"><?= (int) date('n') ?>월 쓴 돈</span>
    <b><?= won($monthSpent, true) ?></b>
    <?php if ($budgetPct !== null): ?><span class="gbar <?= $budgetPct >= 100 ? 'red' : ($budgetPct >= 80 ? 'orange' : '') ?>"><i style="width:<?= $budgetPct ?>%"></i></span><span class="gs">예산의 <?= round($budgetPct) ?>%</span>
    <?php else: ?><span class="gs">오늘 <?= won($todaySpent, true) ?></span><?php endif; ?>
  </a>
  <a href="shop.php" class="g-shop">
    <span class="gi">🛒</span><span class="gk">장보기</span>
    <b><?= count($shopLeft) ?><small>개</small></b>
    <span class="gs"><?= $shopLeft ? h(mb_strimwidth(implode(', ', $shopLeft), 0, 18, '…')) : '살 것 없음' ?></span>
  </a>
  <a href="table.php" class="g-dinner">
    <span class="gi">🍲</span><span class="gk">오늘 저녁</span>
    <b class="txt"><?= $plan ? h(mb_strimwidth(preg_split('/\s*[·,]\s*/u', $plan['dish'])[0], 0, 12, '…')) : '미정' ?></b>
    <span class="gs"><?= $attHome ? $attHome . '명 집에서' : h(dinner_time()) ?></span>
  </a>
</nav>

<?php foreach ($stale as [$a, $lastAt]): ?>
<a class="card alert" href="shortcut.php">
  <span class="ai">⚠️</span>
  <span class="grow"><b><?= h($a['name']) ?> 건강 기록이 <?= (int) floor((time() - strtotime($lastAt)) / 86400) ?>일째 없어요</b>
  <span class="small muted">마지막 <?= h(date('n/j H:i', strtotime($lastAt))) ?> · 단축어 자동화를 확인해 주세요</span></span>
  <span class="chev">›</span>
</a>
<?php endforeach; ?>

<?php foreach ($sickKids as [$k, $t, $next]): ?>
<a href="sick.php?m=<?= (int) $k['id'] ?>" class="card sickcard">
  <div class="card-head"><h2>🤒 <?= h($k['name']) ?> 돌보는 중</h2><span class="more">기록 ›</span></div>
  <?php if ($t): ?><p><b style="font-size:22px"><?= number_format((float) $t['temp'], 1) ?>°</b> <span class="small muted"><?= date('H:i', strtotime($t['at'])) ?> 측정</span></p><?php endif; ?>
  <div class="medpills"><?php foreach ($next as $n): $ok = $n['at'] <= time(); ?><span class="<?= $ok ? 'ok' : '' ?>"><?= h($n['name']) ?> <b><?= $ok ? '지금 가능' : date('H:i', $n['at']) . '부터' ?></b></span><?php endforeach; ?></div>
</a>
<?php endforeach; ?>

<div class="home-cols">

<section class="card todo" id="meds">
  <div class="card-head"><h2>✅ 오늘 할 일</h2><a class="more" href="todo.php">전체<?= $laterTodos ? ' ' . ($laterTodos + count($myTodos)) : '' ?> ›</a></div>
  <div id="todo" class="hometodos">
  <?php foreach ($myTodos as $t): ?>
    <div class="trow" data-id="<?= (int) $t['id'] ?>">
      <form method="post" action="todo.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="back" value="home"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="tcheck" aria-label="다 했어요"></button></form>
      <a class="tbody" href="todo.php?edit=<?= (int) $t['id'] ?>#form"><span class="tt"><?= h($t['title']) ?></span>
        <span class="tm"><?php if ($t['due_day'] < today()): ?><span class="late"><?= h(todo_day_label($t['due_day'])) ?>까지였어요</span><?php elseif ($t['due_time']): ?><span><?= h($t['due_time']) ?></span><?php endif; ?><?php if (!$t['owner_id']): ?><span class="who both">같이</span><?php endif; ?></span></a>
      <?php if ($t['due_day'] < today()): ?><form method="post" action="todo.php" class="tmove"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="back" value="home"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button name="to" value="today">오늘로</button><button name="to" value="tomorrow">내일로</button></form><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php
    $fam = [];
    foreach (members('adult') as $a) if ((int) $a['id'] !== (int) $me['id'] && !empty($otherTodos[(int) $a['id']])) $fam[] = h($a['name']) . ' 할 일 ' . $otherTodos[(int) $a['id']] . '개';
  ?>
  <?php if ($fam): ?>
    <div class="famline">
      <?php if ($fam): ?><a href="todo.php">👨‍👩‍👧 <?= implode(' · ', $fam) ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
  <form method="post" action="todo.php" class="tadd mini"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="back" value="home"><input type="hidden" name="owner" value="<?= (int) $me['id'] ?>"><input type="hidden" name="day" value="<?= today() ?>">
    <input name="title" placeholder="＋ 오늘 할 일 적기" autocomplete="off" enterkeyhint="done" required></form>
  </div>
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
  <?php if (!$todoMeds && $myAtt && !$pendingDiary && !$toReview && !$myTodos): ?>
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
<?php elseif ($counted > 0): ?>
<section class="readiness none slim">
  <div class="label">오늘의 준비 점수</div>
  <?php if ($counted === 0): ?>
    <div class="level">아직 건강 기록이 없어요</div>
    <div class="msg">아이폰 단축어를 한 번 만들어 두면 매일 자동으로 들어와요.</div>
    <p style="margin:6px 0 0"><a class="small" href="shortcut.php">단축어 연결하기 ›</a></p>
  <?php elseif (!$todayRow): ?>
    <div class="level">오늘 기록을 기다리는 중</div>
    <div class="msg">아이폰 단축어가 실행되면 바로 계산돼요.</div>
  <?php else: ?>
    <div class="level">기준선을 모으는 중 (<?= min($counted, 7) ?>/7일)</div>
    <div class="msg">평소 상태를 알아야 점수를 낼 수 있어서, 일주일쯤 기록이 쌓이면 점수가 나와요.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
// 이번 주: 오늘부터 7일 (일정 · 할 일 · 나들이)
$weekTo = date('Y-m-d', strtotime('+6 day'));
$week = [];
foreach (calendar_events($today, $weekTo) as $e) {
    $sd = max($today, substr($e['start_at'], 0, 10));
    $ed = $e['all_day'] ? date('Y-m-d', strtotime($e['end_at']) - 1) : substr($e['end_at'], 0, 10);
    for ($d = $sd; $d <= min($ed, $weekTo); $d = date('Y-m-d', strtotime("$d +1 day"))) $week[$d]['ev'][] = $e;
}
$st = db()->prepare("SELECT due_day, COUNT(*) n FROM todos WHERE done = 0 AND due_day BETWEEN ? AND ? GROUP BY due_day");
$st->execute([$today, $weekTo]);
foreach ($st as $r) $week[$r['due_day']]['todo'] = (int) $r['n'];
$st = db()->prepare("SELECT DISTINCT day FROM outing_logs WHERE kind = 'plan' AND day BETWEEN ? AND ?");
$st->execute([$today, $weekTo]);
foreach ($st as $r) $week[$r['day']]['plan'] = true;
$wdn = ['일', '월', '화', '수', '목', '금', '토'];
?>
<section class="card weekcard">
  <div class="card-head"><h2>📅 이번 주</h2><a class="more" href="calendar.php">달력 ›</a></div>
  <div class="weekstrip">
    <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("+$i day")); $w = (int) date('w', strtotime($d)); $it = $week[$d] ?? []; ?>
      <a href="calendar.php?m=<?= substr($d, 0, 7) ?>&d=<?= $d ?>" class="<?= $i === 0 ? 'today' : '' ?> <?= $w === 0 || isset(HOLIDAYS[$d]) ? 'sun' : ($w === 6 ? 'sat' : '') ?>">
        <span class="w"><?= $i === 0 ? '오늘' : $wdn[$w] ?></span><b><?= (int) substr($d, 8) ?></b>
        <span class="wd"><?php foreach (array_slice($it['ev'] ?? [], 0, 3) as $e): ?><i style="background:<?= h($e['color']) ?>"></i><?php endforeach; ?><?php if (!empty($it['todo'])): ?><i class="t"></i><?php endif; ?><?php if (!empty($it['plan'])): ?><i class="p"></i><?php endif; ?></span>
      </a>
    <?php endfor; ?>
  </div>
  <?php $todayEv = $week[$today]['ev'] ?? []; $tomorrow = date('Y-m-d', strtotime('+1 day')); $tomEv = $week[$tomorrow]['ev'] ?? []; ?>
  <?php if ($todayEv || $tomEv): ?>
    <ul class="list" style="margin-top:6px">
      <?php foreach ([[$today, '오늘', $todayEv], [$tomorrow, '내일', $tomEv]] as [$dd, $lbl, $evs]): foreach (array_slice($evs, 0, 4) as $e): ?>
        <li><span class="dot" style="background:<?= h($e['color']) ?>"></span>
          <span class="time"><?= $lbl ?><br><?= $e['all_day'] ? '종일' : (substr($e['start_at'], 0, 10) === $dd ? substr($e['start_at'], 11, 5) : '계속') ?></span>
          <span class="grow"><span class="title"><?= h($e['title']) ?></span><?= $e['location'] ? '<div class="sub">' . h($e['location']) . '</div>' : '' ?></span></li>
      <?php endforeach; endforeach; ?>
    </ul>
  <?php elseif (!setting('icloud_user')): ?>
    <p class="small muted" style="margin:8px 0 0"><a href="settings.php#calendar">iCloud 캘린더를 연결</a>하면 가족 일정도 여기에 보여요.</p>
  <?php else: ?>
    <p class="small muted" style="margin:8px 0 0">오늘 · 내일은 일정이 없어요.</p>
  <?php endif; ?>
</section>

<section class="card tonight">
  <div class="card-head"><h2>🍲 오늘 저녁 <span class="small muted"><?= h(dinner_time()) ?></span></h2><a class="more" href="table.php">저녁 ›</a></div>
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
  <div class="card-head"><h2>❤️ 오늘 건강</h2><a class="more" href="health.php">건강 ›</a></div>
  <?php if ($counted > 0): ?>
  <div class="minis">
    <div><span class="k">걸음</span><b><?= num($todayRow['steps'] ?? null) ?></b></div>
    <div><span class="k">수면</span><b><?= isset($todayRow['sleep_min']) ? intdiv((int) $todayRow['sleep_min'], 60) . '<small>h</small>' . ((int) $todayRow['sleep_min'] % 60) . '<small>m</small>' : '-' ?></b></div>
    <div><span class="k">HRV</span><b><?= num($todayRow['hrv'] ?? null) ?><small>ms</small></b></div>
    <div><span class="k">심박</span><b><?= num($todayRow['rhr'] ?? null) ?><small>bpm</small></b></div>
  </div>
  <?php else: ?>
    <a class="mealline" href="shortcut.php"><span>⌚️ 건강 기록 연결하기</span><span class="grow"></span><span class="small muted">수면 · 걸음 ›</span></a>
  <?php endif; ?>
  <a class="mealline" href="meals.php">
    <span>🍚 식단 <?= (int) $food['meals'] ?>끼</span>
    <span class="meter orange"><i style="width:<?= min(100, $food['kcal'] / max(1, $me['kcal_target']) * 100) ?>%"></i></span>
    <span class="small muted"><?= num($food['kcal']) ?> / <?= num($me['kcal_target']) ?> kcal ›</span>
  </a>
  <?php foreach ($others as $o): $oh = readiness_history((int) $o['id'], 1)[$today] ?? null; if (!$oh && !health_days_count((int) $o['id'])) continue; ?>
    <p class="small muted" style="margin:10px 0 0"><?= h($o['emoji'] . ' ' . $o['name']) ?> 준비 점수: <b><?= $oh ? number_format($oh['score'], 1) . ' · ' . h(readiness_level($oh['score'])[0]) : '아직 없음' ?></b></p>
  <?php endforeach; ?>
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
      <a href="todo.php"><span class="ic">✅</span><b>할 일</b><span>나 · 가족에게</span></a>
      <a href="shop.php"><span class="ic">🛒</span><b>장보기</b><span>살 것 적기</span></a>
    </div>
  </div>
</div>
<?php page_end('home');
