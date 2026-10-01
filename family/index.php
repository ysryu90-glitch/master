<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/table.php';

$me = require_login();
check_csrf();
$today = today();

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

page_start('오늘', 'today');
?>

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

<section class="card">
  <div class="card-head"><h2>날씨</h2></div>
  <div data-weather='<?= h(json_encode(locations(), JSON_UNESCAPED_UNICODE)) ?>'><div class="empty">날씨를 불러오는 중…</div></div>
</section>

<?php page_end('today');
