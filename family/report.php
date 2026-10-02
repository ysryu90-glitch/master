<?php
// 주간 가족 리포트 (월~일). 일요일 저녁 8시에 알림으로도 알려 준다.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/care.php';

$me = require_login();
$monday = date('Y-m-d', strtotime('monday this week', strtotime(valid_day($_GET['w'] ?? null))));
$sunday = date('Y-m-d', strtotime("$monday +6 day"));
$lastDay = min($sunday, today());
$daysElapsed = max(1, (int) round((strtotime($lastDay) - strtotime($monday)) / 86400) + 1);
$weekdays = ['일', '월', '화', '수', '목', '금', '토'];

// 함께한 저녁
$stmt = db()->prepare('SELECT * FROM dinner_outcomes WHERE day BETWEEN ? AND ? ORDER BY day');
$stmt->execute([$monday, $sunday]);
$outcomes = $stmt->fetchAll();
$together = count(array_filter($outcomes, fn($o) => (int) $o['together'] === 1));
$eatOut = count(array_filter($outcomes, fn($o) => $o['place'] === 'out'));

// 아이
$stmt = db()->prepare('SELECT * FROM kid_reactions WHERE day BETWEEN ? AND ? ORDER BY day, id');
$stmt->execute([$monday, $sunday]);
$reactions = $stmt->fetchAll();
$newFoods = array_values(array_unique(array_column(array_filter($reactions, fn($r) => $r['new_food']), 'food')));
$goodFoods = array_values(array_unique(array_column(array_filter($reactions, fn($r) => $r['reaction'] === 'good'), 'food')));
$stmt = db()->prepare("SELECT COUNT(DISTINCT DATE(at)) FROM sick_logs WHERE at BETWEEN ? AND ?");
$stmt->execute([$monday . ' 00:00:00', $sunday . ' 23:59:59']);
$sickDays = (int) $stmt->fetchColumn();

// 사람별
$people = [];
foreach (members('adult') as $a) {
    $hist = array_filter(readiness_history((int) $a['id'], 21), fn($r) => $r['day'] >= $monday && $r['day'] <= $sunday);
    $stmt = db()->prepare('SELECT * FROM health_days WHERE member_id = ? AND day BETWEEN ? AND ?');
    $stmt->execute([$a['id'], $monday, $sunday]);
    $rows = $stmt->fetchAll();
    $avg = function (string $f) use ($rows) { $v = array_filter(array_column($rows, $f), fn($x) => $x !== null); return $v ? array_sum($v) / count($v) : null; };
    $stmt = db()->prepare('SELECT m.day, SUM(i.kcal * i.servings) kcal, SUM(i.protein * i.servings) protein FROM meals m JOIN meal_items i ON i.meal_id = m.id
        WHERE m.member_id = ? AND m.day BETWEEN ? AND ? GROUP BY m.day');
    $stmt->execute([$a['id'], $monday, $sunday]);
    $mealDays = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT COUNT(*) FROM medication_logs l JOIN medications m ON m.id = l.med_id WHERE m.member_id = ? AND l.day BETWEEN ? AND ?');
    $stmt->execute([$a['id'], $monday, $sunday]);
    $medTaken = (int) $stmt->fetchColumn();
    $medCount = count(medications_of((int) $a['id']));
    $best = null;
    foreach ($hist as $r) if (!$best || $r['score'] > $best['score']) $best = $r;
    $people[] = [
        'm' => $a,
        'readiness' => $hist ? array_sum(array_column($hist, 'score')) / count($hist) : null,
        'best' => $best,
        'sleep' => $avg('sleep_min'),
        'steps' => $avg('steps'),
        'mealDays' => count($mealDays),
        'kcal' => $mealDays ? array_sum(array_column($mealDays, 'kcal')) / count($mealDays) : null,
        'protein' => $mealDays ? array_sum(array_column($mealDays, 'protein')) / count($mealDays) : null,
        'med' => $medCount ? [$medTaken, $medCount * $daysElapsed] : null,
    ];
}

// 나들이
$stmt = db()->prepare("SELECT place_id, day, rating, memo FROM outing_logs WHERE kind = 'visit' AND day BETWEEN ? AND ?");
$stmt->execute([$monday, $sunday]);
$visits = $stmt->fetchAll();

// 다음 주
$nextMon = date('Y-m-d', strtotime("$monday +7 day"));
$nextSun = date('Y-m-d', strtotime("$monday +13 day"));
$nextEvents = calendar_events($nextMon, $nextSun);
$stmt = db()->prepare('SELECT day, dish FROM dinner_plans WHERE day BETWEEN ? AND ? ORDER BY day');
$stmt->execute([$nextMon, $nextSun]);
$nextDinners = $stmt->fetchAll();

require __DIR__ . '/lib/places.php';
$placeNames = [];
foreach ($visits as $v) $placeNames[$v['place_id']] = place($v['place_id'])['name'] ?? $v['place_id'];

page_start('주간 리포트', 'family');
?>
<div class="daynav">
  <a href="report.php?w=<?= date('Y-m-d', strtotime("$monday -7 day")) ?>">‹</a>
  <span class="label"><?= date('n/j', strtotime($monday)) ?> (월) ~ <?= date('n/j', strtotime($sunday)) ?> (일)</span>
  <a href="report.php?w=<?= $nextMon ?>" class="<?= $nextMon > today() ? 'disabled' : '' ?>">›</a>
</div>

<section class="card tonight">
  <h2>🍲 함께한 저녁</h2>
  <div class="dish"><?= $together ?>번 <span class="muted" style="font-size:16px">/ <?= $daysElapsed ?>일<?= $eatOut ? ' · 외식 ' . $eatOut . '번' : '' ?></span></div>
  <?php if ($outcomes): ?>
    <div class="chips"><?php foreach ($outcomes as $o): ?><span class="chip <?= $o['together'] ? '' : 'orange' ?>"><?= $weekdays[(int) date('w', strtotime($o['day']))] ?> <?= h($o['dish'] ?: ($o['place'] === 'out' ? '외식' : '저녁')) ?></span><?php endforeach; ?></div>
  <?php else: ?><p class="small muted">식탁 탭에서 '함께 먹었어요'를 누르면 여기에 쌓여요.</p><?php endif; ?>
</section>

<?php foreach (members('child') as $kid): ?>
<section class="card">
  <h2><?= h($kid['emoji'] . ' ' . $kid['name']) ?></h2>
  <p>⭐ 새 음식 도전: <b><?= $newFoods ? h(implode(', ', $newFoods)) : '없음' ?></b></p>
  <p>😋 잘 먹은 음식: <b><?= $goodFoods ? h(implode(', ', array_slice($goodFoods, 0, 8))) : '-' ?></b></p>
  <?php if ($sickDays): ?><p>🤒 아팠던 날: <b><?= $sickDays ?>일</b></p><?php endif; ?>
</section>
<?php endforeach; ?>

<?php foreach ($people as $p): ?>
<section class="card">
  <h2><?= h($p['m']['emoji'] . ' ' . $p['m']['name']) ?></h2>
  <div class="grid2">
    <div class="stat"><div class="k">평균 준비 점수</div><div class="v"><?= $p['readiness'] !== null ? number_format($p['readiness'], 1) : '-' ?></div>
      <?php if ($p['best']): ?><div class="d">최고 <?= $weekdays[(int) date('w', strtotime($p['best']['day']))] ?>요일 <?= number_format($p['best']['score'], 1) ?></div><?php endif; ?></div>
    <div class="stat"><div class="k">평균 수면</div><div class="v"><?= $p['sleep'] !== null ? intdiv((int) $p['sleep'], 60) . '<small>시간</small> ' . ((int) $p['sleep'] % 60) . '<small>분</small>' : '-' ?></div></div>
    <div class="stat"><div class="k">평균 걸음</div><div class="v"><?= num($p['steps']) ?></div></div>
    <div class="stat"><div class="k">식단 기록</div><div class="v"><?= $p['mealDays'] ?><small>일</small></div>
      <?php if ($p['kcal'] !== null): ?><div class="d">하루 <?= num($p['kcal']) ?>kcal · 단백질 <?= num($p['protein']) ?>g</div><?php endif; ?></div>
  </div>
  <?php if ($p['med']): ?><p class="small" style="margin-top:10px">💊 약 챙김 <b><?= $p['med'][0] ?>/<?= $p['med'][1] ?></b><?= $p['med'][0] >= $p['med'][1] ? ' 👏' : '' ?></p><?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($visits): ?>
<section class="card">
  <h2>🧺 다녀온 곳</h2>
  <?php foreach ($visits as $v): ?><p><?= h($placeNames[$v['place_id']] ?? $v['place_id']) ?> <?= $v['rating'] ? str_repeat('⭐', (int) $v['rating']) : '' ?> <span class="small muted"><?= h($v['memo']) ?></span></p><?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>📅 다음 주</h2>
  <?php if (!$nextEvents && !$nextDinners): ?><div class="empty">아직 잡힌 일정이 없어요.</div><?php endif; ?>
  <ul class="list">
    <?php foreach (array_slice($nextEvents, 0, 8) as $e): ?>
      <li><span class="dot" style="background:<?= h($e['color']) ?>"></span><span class="time"><?= $weekdays[(int) date('w', strtotime($e['start_at']))] ?> <?= $e['all_day'] ? '' : substr($e['start_at'], 11, 5) ?></span><span class="grow title"><?= h($e['title']) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($nextDinners): ?><p class="small muted" style="margin-top:8px">저녁 계획: <?= h(implode(' · ', array_map(fn($d) => $weekdays[(int) date('w', strtotime($d['day']))] . ' ' . $d['dish'], $nextDinners))) ?></p><?php endif; ?>
</section>
<?php page_end('family');
