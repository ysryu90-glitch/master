<?php
// 알림 › 주간 리포트 (월~일): 한 주 동안 우리 가족이 함께한 것. 일요일 저녁 8시에 알림으로도 알려 준다.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/care.php';
require __DIR__ . '/lib/diary.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/question.php';

$me = require_login();
$monday = date('Y-m-d', strtotime('monday this week', strtotime(valid_day($_GET['w'] ?? null))));
$sunday = date('Y-m-d', strtotime("$monday +6 day"));
$lastDay = min($sunday, today());
$daysElapsed = max(1, (int) round((strtotime($lastDay) - strtotime($monday)) / 86400) + 1);
$weekdays = ['일', '월', '화', '수', '목', '금', '토'];
$range = [$monday, $sunday];
$rangeT = [$monday . ' 00:00:00', $sunday . ' 23:59:59'];
$q = function (string $sql, array $args) { $st = db()->prepare($sql); $st->execute($args); return $st; };
$memberById = [];
foreach (members() as $m) $memberById[(int) $m['id']] = $m;

// 숫자로 보는 한 주
$outcomes = $q('SELECT * FROM dinner_outcomes WHERE day BETWEEN ? AND ? ORDER BY day', $range)->fetchAll();
$together = count(array_filter($outcomes, fn($o) => (int) $o['together'] === 1));
$todoDone = (int) $q('SELECT COUNT(*) FROM todos WHERE done = 1 AND done_at BETWEEN ? AND ?', $rangeT)->fetchColumn();
$stickerN = (int) $q('SELECT COUNT(*) FROM stickers WHERE created_at BETWEEN ? AND ?', $rangeT)->fetchColumn();
$diaries = $q('SELECT id FROM diary_entries WHERE day BETWEEN ? AND ? ORDER BY day', $range)->fetchAll(PDO::FETCH_COLUMN);
$diaries = array_values(array_filter(array_map(fn($id) => diary_entry((int) $id), $diaries)));
$photoN = array_sum(array_map(fn($e) => count($e['photos'] ?? []), $diaries));

// 할 일: 누가 몇 개
$doneBy = $q('SELECT done_by, COUNT(*) n FROM todos WHERE done = 1 AND done_at BETWEEN ? AND ? GROUP BY done_by ORDER BY n DESC', $rangeT)->fetchAll(PDO::FETCH_KEY_PAIR);
$openLate = (int) db()->query('SELECT COUNT(*) FROM todos WHERE done = 0 AND due_day < CURDATE()')->fetchColumn();

// 반복 알림 챙긴 비율
$remTotal = 0;
foreach (reminders_all() as $r) for ($d = $monday; $d <= $lastDay; $d = date('Y-m-d', strtotime("$d +1 day"))) if ($r['days'] === '' || in_array((string) date('w', strtotime($d)), explode(',', $r['days']), true)) $remTotal++;
$remDone = (int) $q('SELECT COUNT(*) FROM medication_logs l JOIN medications m ON m.id = l.med_id WHERE m.active = 1 AND l.day BETWEEN ? AND ?', [$monday, $lastDay])->fetchColumn();

// 아이
$reactions = $q('SELECT * FROM kid_reactions WHERE day BETWEEN ? AND ? ORDER BY day, id', $range)->fetchAll();
$newFoods = array_values(array_unique(array_column(array_filter($reactions, fn($r) => $r['new_food']), 'food')));
$goodFoods = array_values(array_unique(array_column(array_filter($reactions, fn($r) => $r['reaction'] === 'good'), 'food')));
$sickDays = (int) $q('SELECT COUNT(DISTINCT DATE(at)) FROM sick_logs WHERE at BETWEEN ? AND ?', $rangeT)->fetchColumn();
$stickerReasons = $q('SELECT reason FROM stickers WHERE created_at BETWEEN ? AND ? AND reason <> ""', $rangeT)->fetchAll(PDO::FETCH_COLUMN);
$rs = array_count_values($stickerReasons);
arsort($rs);

// 오늘의 질문 답 (이번 주)
$answers = $q('SELECT * FROM question_answers WHERE day BETWEEN ? AND ? ORDER BY day, member_id', $range)->fetchAll();
$ansByDay = [];
foreach ($answers as $a) $ansByDay[$a['day']][] = $a;

// 나들이 · 다음 주
$visits = $q("SELECT place_id, day, rating, memo FROM outing_logs WHERE kind = 'visit' AND day BETWEEN ? AND ?", $range)->fetchAll();
$nextMon = date('Y-m-d', strtotime("$monday +7 day"));
$nextSun = date('Y-m-d', strtotime("$monday +13 day"));
$nextEvents = calendar_events($nextMon, $nextSun);
$nextDinners = $q('SELECT day, dish FROM dinner_plans WHERE day BETWEEN ? AND ? ORDER BY day', [$nextMon, $nextSun])->fetchAll();

$isThisWeek = $monday === date('Y-m-d', strtotime('monday this week'));
page_start('주간 리포트', 'alerts');
?>
<div class="daynav">
  <a href="report.php?w=<?= date('Y-m-d', strtotime("$monday -7 day")) ?>" aria-label="지난주">‹</a>
  <span class="label"><?= $isThisWeek ? '이번 주 · ' : '' ?><?= date('n/j', strtotime($monday)) ?> ~ <?= date('n/j', strtotime($sunday)) ?></span>
  <a href="report.php?w=<?= $nextMon ?>" class="<?= $nextMon > today() ? 'disabled' : '' ?>" aria-label="다음 주">›</a>
</div>

<section class="card rhero">
  <div class="small muted"><?= h(family_name()) ?>의 한 주</div>
  <div class="rstats">
    <div><b><?= $together ?></b><span>함께한 저녁</span></div>
    <div><b><?= $todoDone ?></b><span>끝낸 할 일</span></div>
    <div><b><?= $stickerN ?></b><span>칭찬 스티커</span></div>
    <div><b><?= count($diaries) ?></b><span>일기<?= $photoN ? ' · 사진 ' . $photoN : '' ?></span></div>
  </div>
  <?php if ($remTotal): $pct = (int) round(min(1, $remDone / $remTotal) * 100); ?>
    <div class="rrem"><span>⏰ 반복 알림 챙김</span><span class="meter"><i style="width:<?= $pct ?>%"></i></span><b><?= $pct ?>%</b></div>
  <?php endif; ?>
</section>

<?php if ($diaries): ?>
<h3 class="listhead">📔 이번 주 추억</h3>
<div class="rmem">
  <?php foreach ($diaries as $e): ?>
    <a href="diary_view.php?id=<?= (int) $e['id'] ?>" class="rm">
      <?php if (!empty($e['cover'])): ?><img src="diary_photo.php?id=<?= (int) $e['cover'] ?>&t=1" alt="" loading="lazy"><?php else: ?><span class="noimg"><?= DIARY_CATEGORIES[$e['category']][1] ?? '📔' ?></span><?php endif; ?>
      <span class="t"><?= $weekdays[(int) date('w', strtotime($e['day']))] ?> · <?= h(mb_strimwidth($e['title'] ?: $e['place_name'] ?: '일기', 0, 18, '…')) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($ansByDay): ?>
<section class="card">
  <h2>💬 오늘의 질문, 이번 주 대답</h2>
  <?php foreach ($ansByDay as $d => $list): ?>
    <div class="ransq"><div class="small muted"><?= $weekdays[(int) date('w', strtotime($d))] ?> · <?= h(question_of_day($d)) ?></div>
      <?php foreach ($list as $a): $m = $memberById[(int) $a['member_id']] ?? null; ?><p><?= h($m['emoji'] ?? '') ?> <?= h($a['answer']) ?></p><?php endforeach; ?></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php foreach (members('child') as $kid): ?>
<section class="card">
  <div class="card-head"><h2><?= h($kid['emoji']) ?> <?= h($kid['name']) ?>의 한 주</h2><a class="more" href="sticker.php?m=<?= (int) $kid['id'] ?>">스티커판 ›</a></div>
  <?php if ($rs): ?><div class="chips" style="margin-bottom:10px"><?php foreach (array_slice($rs, 0, 6, true) as $r => $c): ?><span class="chip">⭐ <?= h($r) ?><?= $c > 1 ? ' ×' . $c : '' ?></span><?php endforeach; ?></div><?php endif; ?>
  <p>🥦 새 음식 도전: <b><?= $newFoods ? h(implode(', ', $newFoods)) : '없음' ?></b></p>
  <p>😋 잘 먹은 음식: <b><?= $goodFoods ? h(implode(', ', array_slice($goodFoods, 0, 8))) : '-' ?></b></p>
  <?php if ($sickDays): ?><p>🤒 아팠던 날: <b><?= $sickDays ?>일</b></p><?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($doneBy || $openLate): ?>
<section class="card">
  <div class="card-head"><h2>✅ 누가 얼마나 했을까</h2><a class="more" href="todo.php">할 일 ›</a></div>
  <?php if ($doneBy): $maxN = max($doneBy); ?>
    <?php foreach ($doneBy as $mid => $n): $m = $memberById[(int) $mid] ?? null; ?>
      <div class="catbars"><a href="todo.php"><span class="n"><?= $m ? h($m['emoji'] . ' ' . $m['name']) : '누군가' ?></span><span class="bar"><i style="width:<?= max(6, $n / $maxN * 100) ?>%;background:var(--accent)"></i></span><span class="v"><?= (int) $n ?>개</span></a></div>
    <?php endforeach; ?>
  <?php else: ?><p class="small muted">이번 주에 끝낸 할 일이 아직 없어요.</p><?php endif; ?>
  <?php if ($openLate): ?><p class="small" style="margin:10px 0 0;color:var(--red)">밀린 할 일 <?= $openLate ?>개가 있어요.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($outcomes): ?>
<section class="card">
  <h2>🍲 이번 주 저녁</h2>
  <div class="chips"><?php foreach ($outcomes as $o): ?><span class="chip <?= $o['together'] ? '' : 'orange' ?>"><?= $weekdays[(int) date('w', strtotime($o['day']))] ?> <?= h($o['dish'] ?: ($o['place'] === 'out' ? '외식' : '저녁')) ?></span><?php endforeach; ?></div>
</section>
<?php endif; ?>

<?php if ($visits): ?>
<section class="card">
  <h2>🧺 다녀온 곳</h2>
  <?php foreach ($visits as $v): ?><p><?= h(place($v['place_id'])['name'] ?? $v['place_id']) ?> <?= $v['rating'] ? str_repeat('⭐', (int) $v['rating']) : '' ?> <span class="small muted"><?= h($v['memo']) ?></span></p><?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>📅 다음 주 미리 보기</h2>
  <?php if (!$nextEvents && !$nextDinners): ?><p class="small muted">아직 잡힌 일정이 없어요.</p><?php endif; ?>
  <ul class="list">
    <?php foreach (array_slice($nextEvents, 0, 8) as $e): ?>
      <li><span class="dot" style="background:<?= h($e['color']) ?>"></span><span class="time"><?= $weekdays[(int) date('w', strtotime($e['start_at']))] ?> <?= $e['all_day'] ? '' : substr($e['start_at'], 11, 5) ?></span><span class="grow title"><?= h($e['title']) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($nextDinners): ?><p class="small muted" style="margin-top:8px">저녁 계획: <?= h(implode(' · ', array_map(fn($d) => $weekdays[(int) date('w', strtotime($d['day']))] . ' ' . $d['dish'], $nextDinners))) ?></p><?php endif; ?>
</section>
<?php page_end('alerts');
