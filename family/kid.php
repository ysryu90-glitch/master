<?php
// 일기 › 아이: 아이 기록을 한 화면에 (칭찬 스티커 바로 주기 · 루틴 · 성장 · 아플 때 · 어록)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sticker.php';
require __DIR__ . '/lib/days.php';
require __DIR__ . '/lib/care.php';
require __DIR__ . '/lib/question.php';

$me = require_login();
$kids = members('child');
$kid = null;
foreach ($kids as $k) if ((int) $k['id'] === (int) ($_GET['m'] ?? 0)) $kid = $k;
$kid = $kid ?? ($kids[0] ?? null);

page_start($kid ? $kid['name'] : '아이', 'diary');
if (!$kid): ?>
  <section class="card tempty"><div class="big">🧒</div><b>아이를 추가해 주세요</b><p class="small muted">설정 › 아이에서 이름을 적으면 칭찬 스티커 · 루틴 · 성장 · 아플 때 기록을 여기에서 한눈에 볼 수 있어요.</p><a class="btn primary" href="settings.php#kid">아이 추가</a></section>
<?php page_end('diary'); exit; endif;

$kidId = (int) $kid['id'];
$bd = member_birthday($kid);
$age = $bd ? age_from((int) $bd['year'], (int) $bd['month'], (int) $bd['day']) : null;
$nextBd = null;
foreach (anniv_upcoming() as $a) if ($bd && (int) $a['id'] === (int) $bd['id']) $nextBd = $a;
$st = sticker_state($kidId);
$slot = routine_slot_now();
$rItems = array_values(array_filter(routines_of($kidId), fn($r) => $r['slot'] === $slot));
$rDone = routine_checked($kidId, today());
$rN = count(array_filter($rItems, fn($r) => isset($rDone[(int) $r['id']])));
$g = growth_rows($kidId);
$lastH = null; $lastW = null;
foreach ($g as $r) { if ($r['height'] !== null) $lastH = $r; if ($r['weight'] !== null) $lastW = $r; }
$logs = sick_logs($kidId, 24);
$temp = $logs ? last_temp($logs) : null;
$qSt = db()->prepare("SELECT kid_said t, day FROM diary_entries WHERE kid_said <> '' UNION ALL SELECT answer t, day FROM question_answers WHERE member_id = ? ORDER BY day DESC LIMIT 1");
$qSt->execute([$kidId]);
$quote = $qSt->fetch();
$qN = (int) db()->query("SELECT COUNT(*) FROM diary_entries WHERE kid_said <> ''")->fetchColumn();
$qa = question_answers(today())[$kidId] ?? null;
?>
<?php if (count($kids) > 1): ?><nav class="chips" style="margin-bottom:10px"><?php foreach ($kids as $k): ?><a class="chip<?= (int) $k['id'] === $kidId ? ' on' : '' ?>" href="kid.php?m=<?= (int) $k['id'] ?>"><?= h($k['emoji'] . ' ' . $k['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>

<section class="card kidhero">
  <span class="kav"><?= h($kid['emoji']) ?></span>
  <div class="grow">
    <h2 style="margin:0"><?= h($kid['name']) ?></h2>
    <div class="small muted"><?= $age ? '만 ' . $age[0] . '살 · ' . $age[1] . '개월' : '<a href="anniv.php">생일을 적으면 나이가 보여요</a>' ?><?= $nextBd ? ' · 🎂 생일 ' . ($nextBd['dday'] ? 'D-' . $nextBd['dday'] : '오늘!') : '' ?></div>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>⭐ 칭찬 스티커 <span class="small muted"><?= $st['count'] ?>/<?= $st['goal'] ?></span></h2><a class="more" href="sticker.php?m=<?= $kidId ?>">스티커판 ›</a></div>
  <div class="minibar"><i style="width:<?= min(100, $st['count'] / max(1, $st['goal']) * 100) ?>%"></i></div>
  <p class="small muted" style="margin:6px 0 10px">🎁 <?= h($st['reward'] ?: '다 모으면 선물') ?><?= $st['count'] >= $st['goal'] ? ' · 다 모았어요!' : ' · ' . ($st['goal'] - $st['count']) . '개 남음' ?></p>
  <?php if ($st['count'] < $st['goal']): ?>
  <div class="praise">
    <?php foreach (array_slice(STICKER_REASONS, 0, 6) as $r): ?>
      <form method="post" action="sticker.php"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="member_id" value="<?= $kidId ?>"><input type="hidden" name="back" value="kid"><input type="hidden" name="reason" value="<?= h(preg_replace('/^\S+\s/u', '', $r)) ?>"><button class="pbtn"><?= h($r) ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<div class="kidgrid">
  <a class="ktile" href="sticker.php?m=<?= $kidId ?>&slot=<?= $slot ?>">
    <span class="ki"><?= ROUTINE_SLOTS[$slot][1] ?></span><span class="kk"><?= ROUTINE_SLOTS[$slot][0] ?></span>
    <b><?= $rN ?><small>/<?= count($rItems) ?></small></b><span class="ks"><?= $rItems && $rN === count($rItems) ? '다 했어요 🎉' : '눌러서 체크' ?></span>
  </a>
  <a class="ktile" href="growth.php?m=<?= $kidId ?>">
    <span class="ki">🌱</span><span class="kk">성장</span>
    <b><?= $lastH ? h($lastH['height']) . '<small>cm</small>' : '–' ?></b><span class="ks"><?= $lastW ? h($lastW['weight']) . 'kg · ' . date('n/j', strtotime($lastW['day'])) : '키 · 몸무게 적기' ?></span>
  </a>
  <a class="ktile<?= $logs ? ' warn' : '' ?>" href="sick.php?m=<?= $kidId ?>">
    <span class="ki"><?= $logs ? '🤒' : '💚' ?></span><span class="kk">아플 때</span>
    <b class="txt"><?= $temp ? number_format((float) $temp['temp'], 1) . '°' : ($logs ? '기록 중' : '건강해요') ?></b><span class="ks"><?= $temp ? date('H:i', strtotime($temp['at'])) . ' 측정' : '체온 · 해열제 기록' ?></span>
  </a>
  <a class="ktile" href="quotes.php">
    <span class="ki">💬</span><span class="kk">어록</span>
    <b class="txt quote"><?= $quote ? '“' . h(mb_strimwidth(trim($quote['t'], " \"“”"), 0, 22, '…')) . '”' : '아직 없어요' ?></b><span class="ks"><?= $qN ? $qN . '개 모음' : '일기에 한 말 적기' ?></span>
  </a>
</div>

<section class="card">
  <div class="card-head"><h2>💭 오늘의 질문</h2><a class="more" href="index.php#question">홈에서 답하기 ›</a></div>
  <p style="margin:0;font-weight:700"><?= h(question_of_day()) ?></p>
  <p class="small <?= $qa ? '' : 'muted' ?>" style="margin:6px 0 0"><?= $qa ? h($kid['emoji'] . ' ' . $qa['answer']) : '저녁 먹으며 물어보고 홈에서 ' . h($kid['name']) . '의 대답을 적어 주세요.' ?></p>
</section>
<?php page_end('diary');
