<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/days.php';
require_once __DIR__ . '/lib/diary.php';
require_once __DIR__ . '/lib/sticker.php';
require __DIR__ . '/lib/question.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/table.php';
require __DIR__ . '/lib/care.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/discover.php';
require __DIR__ . '/lib/todo.php';

$me = require_login();
check_csrf();
$today = today();

// 오늘의 질문 답 (아이 몫은 부모가 대신)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'answer') {
    $who = (int) post('member_id') ?: (int) $me['id'];
    if (member($who)) {
        $had = question_answers($today)[$who] ?? null;
        question_save($today, $who, (string) post('answer'));
        if (!$had && trim(post('answer')) !== '') activity('💭', (member($who)['name'] ?? '') . ': 「' . mb_strimwidth(trim(post('answer')), 0, 60, '…') . '」 (오늘의 질문)', 'index.php#question');
    }
    redirect('index.php#question');
}

// 오늘 저녁 출석 바로 바꾸기
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset(ATTENDANCE[post('status')])) {
    db()->prepare('REPLACE INTO dinner_attendance (day, member_id, status, late_time, updated_at) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$today, $me['id'], post('status'), post('late_time')]);
    redirect('index.php');
}

calendar_refresh_if_stale();

$plan = dinner_plan($today);
$att = attendance($today);
$events = calendar_events($today, $today);
$conflicts = dinner_conflicts($today);


$others = array_filter(members('adult'), fn($m) => (int) $m['id'] !== (int) $me['id']);
// 오늘 반복 알림: 내 몫 + 아이 몫 (가족 모두 같이 챙김)
$kidIds = array_map(fn($k) => (int) $k['id'], members('child'));
$meds = array_values(array_filter(reminders_today(), fn($r) => (int) $r['member_id'] === (int) $me['id'] || in_array((int) $r['member_id'], $kidIds, true)));

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
        $tiredNow = false;
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
// 오늘 일정: 지금 이후 첫 일정 (종일 일정은 맨 앞)
$nextEv = null;
foreach ($events as $ev) if ($ev['all_day'] || strtotime($ev['end_at']) > time()) { $nextEv = $ev; break; }
?>
<nav class="glance" aria-label="한눈에">
  <a href="todo.php" class="g-todo">
    <span class="gi">✅</span><span class="gk">오늘 할 일</span>
    <b><?= count($myTodos) ?><small>개</small></b>
    <span class="gs"><?= $myTodos ? h(mb_strimwidth($myTodos[0]['title'], 0, 16, '…')) : ($laterTodos ? '다음 할 일 ' . $laterTodos . '개' : '모두 끝냈어요') ?></span>
  </a>
  <a href="calendar.php" class="g-cal">
    <span class="gi">📅</span><span class="gk">오늘 일정</span>
    <b><?= count($events) ?><small>개</small></b>
    <span class="gs"><?= $nextEv ? ($nextEv['all_day'] ? '' : date('G:i', strtotime($nextEv['start_at'])) . ' ') . h(mb_strimwidth($nextEv['title'], 0, 14, '…')) : ($events ? '모두 지났어요' : '일정 없음') ?></span>
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


<?php foreach ($sickKids as [$k, $t, $next]): ?>
<a href="sick.php?m=<?= (int) $k['id'] ?>" class="card sickcard">
  <div class="card-head"><h2>🤒 <?= h($k['name']) ?> 돌보는 중</h2><span class="more">기록 ›</span></div>
  <?php if ($t): ?><p><b style="font-size:22px"><?= number_format((float) $t['temp'], 1) ?>°</b> <span class="small muted"><?= date('H:i', strtotime($t['at'])) ?> 측정</span></p><?php endif; ?>
  <div class="medpills"><?php foreach ($next as $n): $ok = $n['at'] <= time(); ?><span class="<?= $ok ? 'ok' : '' ?>"><?= h($n['name']) ?> <b><?= $ok ? '지금 가능' : date('H:i', $n['at']) . '부터' ?></b></span><?php endforeach; ?></div>
</a>
<?php endforeach; ?>

<div class="home-cols">

<section class="card todo" id="meds">
  <div class="card-head"><h2>✅ 오늘</h2><a class="more" href="todo.php">전체<?= $laterTodos ? ' ' . ($laterTodos + count($myTodos)) : '' ?> ›</a></div>
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
  <?php if ($todoMeds): ?><div class="hometodos rems">
  <?php foreach ($todoMeds as $med): $for = $med['member_id'] != $me['id'] ? member((int) $med['member_id']) : null; ?>
    <div class="trow">
      <form method="post" action="remind.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $med['id'] ?>"><button class="tcheck" aria-label="챙겼어요"></button></form>
      <a class="tbody" href="remind.php"><span class="tt"><?= h($med['emoji']) ?> <?= $for ? h($for['name']) . ' ' : '' ?><?= h($med['name']) ?></span><span class="tm"><span class="<?= $med['time'] < date('H:i') ? 'late' : '' ?>"><?= h($med['time']) ?></span><?= $med['note'] !== '' ? '<span>' . h($med['note']) . '</span>' : '' ?></span></a>
    </div>
  <?php endforeach; ?>
  </div><?php endif; ?>
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
  <?php
  // 아이 루틴 (아침 6~10시 · 저녁 5시 반~9시 반, 다 하기 전까지)
  $kidR = members('child')[0] ?? null; $mNow = (int) date('G') * 60 + (int) date('i');
  if ($kidR && (($mNow >= 360 && $mNow < 600) || ($mNow >= 1050 && $mNow < 1290))):
      $rSlot = routine_slot_now(); $rItems = array_filter(routines_of((int) $kidR['id']), fn($r) => $r['slot'] === $rSlot); $rDone = routine_checked((int) $kidR['id'], $today);
      $rLeft = array_filter($rItems, fn($r) => !isset($rDone[(int) $r['id']]));
      if ($rItems && $rLeft): ?>
    <a class="todo-row" href="sticker.php?m=<?= (int) $kidR['id'] ?>&slot=<?= $rSlot ?>">
      <span class="ic"><?= ROUTINE_SLOTS[$rSlot][1] ?></span><span class="grow"><b><?= h($kidR['name']) ?> <?= ROUTINE_SLOTS[$rSlot][0] ?></b> <?= count($rItems) - count($rLeft) ?>/<?= count($rItems) ?><div class="small muted">남은 것: <?= h(implode(' · ', array_map(fn($r) => $r['emoji'] . $r['title'], array_slice($rLeft, 0, 3)))) ?></div></span><span class="more">›</span>
    </a>
  <?php endif; endif; ?>
  <?php if (!$todoMeds && $myAtt && !$pendingDiary && !$myTodos): ?>
    <p class="done">🎉 오늘 할 일을 다 했어요<?= $doneMeds ? ' · ⏰ 알림 ' . $doneMeds . '개 챙김' : '' ?></p>
  <?php elseif ($doneMeds): ?>
    <p class="small muted" style="margin:8px 0 0">⏰ 오늘 알림 <?= $doneMeds ?>개 챙김 · <a href="remind.php">반복 알림 ›</a></p>
  <?php endif; ?>
</section>

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
  <?php foreach (array_slice(array_filter(anniv_upcoming(30), fn($a) => $a['dday'] <= 30), 0, 2) as $a): ?>
    <a class="ddline" href="anniv.php" style="margin:0 0 10px"><span style="font-size:20px"><?= h($a['emoji']) ?></span><span class="grow"><b><?= h($a['label']) ?></b> <span class="small muted"><?= date('n/j', strtotime($a['date'])) ?> (<?= weekday_short($a['date']) ?>)</span></span><b class="ddsm<?= $a['dday'] <= 7 ? ' soon' : '' ?>"><?= dday_text($a['dday']) ?></b></a>
  <?php endforeach; ?>
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


<?php $q = question_of_day(); $qa = question_answers($today); $qMembers = members(); ?>
<section class="card qcard" id="question">
  <div class="card-head"><h2>💬 오늘의 질문</h2><span class="small muted">저녁 식탁에서</span></div>
  <p class="qtext"><?= h($q) ?></p>
  <div class="qans">
    <?php foreach ($qMembers as $m): $a = $qa[(int) $m['id']] ?? null; $canEdit = (int) $m['id'] === (int) $me['id'] || $m['role'] === 'child'; ?>
      <div class="qrow<?= $a ? ' has' : '' ?>">
        <span class="qav"><?= h($m['emoji']) ?></span>
        <?php if ($canEdit): ?>
          <form method="post" class="qform"><?= csrf_field() ?><input type="hidden" name="action" value="answer"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
            <input name="answer" value="<?= h($a['answer'] ?? '') ?>" maxlength="300" placeholder="<?= h($m['name']) ?><?= (int) $m['id'] === (int) $me['id'] ? '의 답' : '가 한 말 적어 주기' ?>" autocomplete="off" enterkeyhint="done" aria-label="<?= h($m['name']) ?> 답"></form>
        <?php else: ?>
          <span class="qa"><?= $a ? h($a['answer']) : '<span class="muted">' . h($m['name']) . ' 아직</span>' ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php
// 가족 소식 (다른 가족이 한 일, 최근 2일)
$st = db()->prepare('SELECT * FROM activity WHERE (member_id IS NULL OR member_id <> ?) AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY id DESC LIMIT 3');
$st->execute([$me['id']]);
$news = $st->fetchAll();
$unseen = activity_unseen((int) $me['id']);
?>
<?php if ($news): ?>
<section class="card">
  <div class="card-head"><h2>🔔 가족 소식<?= $unseen ? ' <span class="badge">' . $unseen . '</span>' : '' ?></h2><a class="more" href="feed.php">전체 ›</a></div>
  <div class="feed mini">
    <?php foreach ($news as $r): $m = member((int) $r['member_id']); ?>
      <a class="fitem" href="<?= h($r['url'] ?: 'feed.php') ?>"><span class="fav"><?= h($m['emoji'] ?? '🏠') ?><i><?= h($r['icon']) ?></i></span>
        <span class="grow"><span class="ft"><b><?= h($m['name'] ?? '') ?></b> <?= h($r['text']) ?></span></span><span class="fago"><?= date('H:i', strtotime($r['created_at'])) ?></span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php
// 📸 추억: 몇 년 전 이맘때(앞뒤 3일) 일기, 없으면 두 달 넘은 사진 일기 하나 (하루 동안 같은 것)
$memId = db()->query("SELECT id FROM diary_entries WHERE YEAR(day) < YEAR(CURDATE())
    AND ABS(DATEDIFF(DATE_ADD(day, INTERVAL (YEAR(CURDATE()) - YEAR(day)) YEAR), CURDATE())) <= 3 ORDER BY day DESC LIMIT 1")->fetchColumn();
$memLabel = $memId ? null : '지난 추억';
if (!$memId) $memId = db()->query('SELECT e.id FROM diary_entries e WHERE e.day < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND EXISTS (SELECT 1 FROM diary_photos p WHERE p.entry_id = e.id) ORDER BY RAND(' . crc32($today) . ') LIMIT 1')->fetchColumn();
$mem = $memId ? diary_entry((int) $memId) : null;
?>
<?php if ($mem): $ago = (int) date('Y') - (int) substr($mem['day'], 0, 4); ?>
<a class="card memory" href="diary_view.php?id=<?= (int) $mem['id'] ?>">
  <?php if ($mem['cover']): ?><img src="diary_photo.php?id=<?= $mem['cover'] ?>&t=1" alt="" loading="lazy"><?php endif; ?>
  <span><span class="small muted">📸 <?= $memLabel ?: $ago . '년 전 이맘때' ?> · <?= date('Y.n.j', strtotime($mem['day'])) ?></span><br><b><?= h($mem['title'] ?: $mem['place_name'] ?: '일기') ?></b><?php if ($mem['kid_said']): ?><br><span class="small">👧 “<?= h(mb_strimwidth(trim($mem['kid_said'], " \"“”"), 0, 40, '…')) ?>”</span><?php endif; ?></span>
</a>
<?php endif; ?>



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
      <a href="table.php#plan"><span class="ic">🍲</span><b>저녁 메뉴</b><span>이번 주 식단 정하기</span></a>
      <a href="diary_edit.php?cat=daily"><span class="ic">📔</span><b>일기</b><span>사진 · 한 줄</span></a>
      <?php if ($kid0): ?><a href="sick.php?m=<?= (int) $kid0['id'] ?>"><span class="ic">🌡</span><b>체온 · 해열제</b><span><?= h($kid0['name']) ?> 아플 때</span></a><?php endif; ?>
      <a href="todo.php"><span class="ic">✅</span><b>할 일</b><span>나 · 가족에게</span></a>
      <?php if ($kid0): ?><a href="sticker.php?m=<?= (int) $kid0['id'] ?>"><span class="ic">⭐</span><b>칭찬 스티커</b><span><?= h($kid0['name']) ?> 잘한 일</span></a><?php endif; ?>
      <a href="shop.php"><span class="ic">🛒</span><b>장보기</b><span>살 것 적기</span></a>
    </div>
  </div>
</div>
<?php page_end('home');
