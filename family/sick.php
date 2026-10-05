<?php
// 아이 아플 때: 체온 · 해열제 기록과 다음 복용 가능 시각 (부부가 교대로 돌볼 때 함께 봄)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/care.php';

$me = require_login();
check_csrf();
$kids = members('child');
if (!$kids) {
    flash('설정에서 아이를 먼저 추가해 주세요.');
    redirect('settings.php');
}
$kid = $kids[0];
foreach ($kids as $k) if ((int) $k['id'] === (int) ($_GET['m'] ?? $_POST['m'] ?? 0)) $kid = $k;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $at = preg_match('/^\d{2}:\d{2}$/', post('time')) ? date('Y-m-d') . ' ' . post('time') . ':00' : date('Y-m-d H:i:s');
    if (strtotime($at) > time() + 300) $at = date('Y-m-d H:i:s', strtotime($at) - 86400); // 새벽에 '어제 23:30' 입력한 경우
    switch (post('action')) {
        case 'temp':
            $t = (float) post('temp');
            if ($t >= 34 && $t <= 43) {
                db()->prepare("INSERT INTO sick_logs (member_id, at, kind, temp, note, created_by, created_at) VALUES (?, ?, 'temp', ?, ?, ?, NOW())")
                    ->execute([$kid['id'], $at, round($t, 1), mb_substr(post('note'), 0, 200), $me['id']]);
            }
            break;
        case 'med':
            if (isset(FEVER_MEDS[post('med')])) {
                db()->prepare("INSERT INTO sick_logs (member_id, at, kind, med, dose, note, created_by, created_at) VALUES (?, ?, 'med', ?, ?, ?, ?, NOW())")
                    ->execute([$kid['id'], $at, post('med'), mb_substr(post('dose'), 0, 30), mb_substr(post('note'), 0, 200), $me['id']]);
            }
            break;
        case 'note':
            if (post('note') !== '') {
                db()->prepare("INSERT INTO sick_logs (member_id, at, kind, note, created_by, created_at) VALUES (?, ?, 'note', ?, ?, NOW())")
                    ->execute([$kid['id'], $at, mb_substr(post('note'), 0, 200), $me['id']]);
            }
            break;
        case 'delete':
            db()->prepare('DELETE FROM sick_logs WHERE id = ? AND member_id = ?')->execute([(int) post('id'), $kid['id']]);
            break;
    }
    redirect('sick.php?m=' . $kid['id']);
}

$logs = sick_logs((int) $kid['id'], 72);
$recent = array_filter($logs, fn($l) => strtotime($l['at']) > time() - 48 * 3600);
$last = last_temp($logs);
$next = fever_next($logs);
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m['emoji'] . ' ' . $m['name'];

$points = [];
foreach (array_reverse($recent) as $l) {
    if ($l['kind'] === 'temp') $points[] = ['l' => date('H:i', strtotime($l['at'])), 'v' => (float) $l['temp']];
}
$lastDose = null;
foreach ($logs as $l) if ($l['kind'] === 'med') { $lastDose = $l; break; }
// 한 번에 다시 기록: 약마다 지난번 양 그대로
$lastByMed = [];
foreach ($logs as $l) if ($l['kind'] === 'med' && isset(FEVER_MEDS[$l['med']]) && !isset($lastByMed[$l['med']])) $lastByMed[$l['med']] = $l;

page_start('아플 때', 'family');
?>
<style>
  .temp-big { font-size: 46px; font-weight: 800; letter-spacing: -1px; }
  .t-normal { color: var(--accent); } .t-warm { color: #d97706; } .t-fever { color: var(--orange); } .t-high { color: var(--red); }
  .next { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-top: 1px solid var(--line); }
  .next b { font-size: 18px; }
  .next .ok, .next .wait { white-space: nowrap; margin-left: 10px; }
  .next .ok { color: var(--accent); font-weight: 800; }
  .next .wait { color: var(--orange); font-weight: 800; }
  .quickdose { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); }
  .quickdose form { margin: 0; }
  .medpick label { margin: 0 !important; }
  .medpick input { display: none; }
  .medpick span { display: block; background: var(--card-2); border-radius: 12px; padding: 10px; color: var(--text); font-size: 14px; font-weight: 700; }
  .medpick span small { display: block; font-weight: 500; color: var(--sub); font-size: 12px; }
  .medpick input:checked + span { outline: 2px solid var(--accent); background: var(--accent-soft); }
</style>

<?php if (count($kids) > 1): ?>
<div class="segmented"><?php foreach ($kids as $k): ?><label onclick="location.href='sick.php?m=<?= (int) $k['id'] ?>'"><input type="radio" <?= $k['id'] === $kid['id'] ? 'checked' : '' ?>><span><?= h($k['emoji'] . ' ' . $k['name']) ?></span></label><?php endforeach; ?></div>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2><?= h($kid['emoji'] . ' ' . $kid['name']) ?> 지금 상태</h2></div>
  <?php if ($last): ?>
    <div class="temp-big t-<?= temp_class((float) $last['temp']) ?>"><?= number_format((float) $last['temp'], 1) ?>°</div>
    <p class="small muted"><?= h(date('n/j H:i', strtotime($last['at']))) ?> 측정 · <?= h($names[(int) $last['created_by']] ?? '') ?></p>
  <?php else: ?>
    <p class="muted">최근 체온 기록이 없어요.</p>
  <?php endif; ?>
  <?php if ($lastDose): ?>
    <p class="small">마지막 약: <b><?= h(FEVER_MEDS[$lastDose['med']][0] ?? $lastDose['med']) ?></b> <?= h($lastDose['dose']) ?> · <?= h(date('n/j H:i', strtotime($lastDose['at']))) ?> (<?= h($names[(int) $lastDose['created_by']] ?? '') ?>)</p>
  <?php endif; ?>
  <?php foreach ($next as $n): $ok = $n['at'] <= time(); ?>
    <div class="next">
      <span><b><?= h($n['name']) ?></b><div class="small muted">24시간 동안 <?= $n['count'] ?>/<?= $n['max'] ?>회 · <?= h($n['why']) ?></div></span>
      <span class="<?= $ok ? 'ok' : 'wait' ?>"><?= $ok ? '지금 가능' : date('H:i', $n['at']) . '부터' ?></span>
    </div>
  <?php endforeach; ?>
  <?php if ($lastByMed): ?>
    <div class="quickdose">
      <?php foreach ($lastByMed as $mk => $l): $grp = FEVER_MEDS[$mk][2]; $wait = $grp !== null && isset($next[$grp]) && $next[$grp]['at'] > time(); ?>
        <form method="post"<?= $wait ? ' data-confirm="' . h(FEVER_MEDS[$mk][0]) . '은 ' . date('H:i', $next[$grp]['at']) . '부터예요. 그래도 지금 먹였다고 기록할까요?"' : '' ?>>
          <?= csrf_field() ?><input type="hidden" name="action" value="med"><input type="hidden" name="m" value="<?= (int) $kid['id'] ?>">
          <input type="hidden" name="med" value="<?= h($mk) ?>"><input type="hidden" name="dose" value="<?= h($l['dose']) ?>">
          <button class="btn <?= $wait ? '' : 'primary' ?> wide">💊 <?= h(FEVER_MEDS[$mk][0]) ?> 방금 먹였어요<?= $l['dose'] !== '' ? ' · ' . h($l['dose']) : '' ?></button>
        </form>
      <?php endforeach; ?>
      <p class="small muted" style="margin:0">지난번과 같은 양으로 지금 시각에 기록해요. 양이 다르면 아래에서 적어 주세요.</p>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>🌡 체온 기록</h2>
  <form method="post" class="form inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="temp"><input type="hidden" name="m" value="<?= (int) $kid['id'] ?>">
    <label>체온 (°C)<input name="temp" type="number" step="0.1" min="34" max="43" inputmode="decimal" placeholder="38.2" required></label>
    <label>시각<input name="time" type="time" value="<?= date('H:i') ?>"></label>
    <button class="btn primary">기록</button>
  </form>
</section>

<section class="card">
  <h2>💊 약 먹인 기록</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="med"><input type="hidden" name="m" value="<?= (int) $kid['id'] ?>">
    <div class="grid2 medpick" style="margin-bottom:12px">
      <?php foreach (FEVER_MEDS as $key => [$label, $examples]): ?>
        <label><input type="radio" name="med" value="<?= $key ?>" <?= $key === 'acet' ? 'checked' : '' ?>><span><?= h($label) ?><small><?= h($examples) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <div class="grid2">
      <label>먹인 양<input name="dose" placeholder="예: 7ml"></label>
      <label>시각<input name="time" type="time" value="<?= date('H:i') ?>"></label>
    </div>
    <label>메모<input name="note" placeholder="예: 반 정도 뱉음"></label>
    <button class="btn primary wide">기록</button>
  </form>
</section>

<?php if (count($points) >= 2): ?>
<section class="card">
  <h2>최근 48시간 체온</h2>
  <svg class="chart" data-chart='<?= h(json_encode(['type' => 'line', 'points' => $points, 'color' => '#f97316', 'min' => 36, 'max' => 40.5, 'goal' => 38], JSON_UNESCAPED_UNICODE)) ?>'></svg>
  <p class="legend">점선: 38°C</p>
</section>
<?php endif; ?>

<section class="card">
  <h2>기록 (48시간)</h2>
  <?php if (!$recent): ?><div class="empty">기록이 없어요.</div><?php endif; ?>
  <ul class="list">
    <?php foreach ($recent as $l): ?>
      <li>
        <span class="time"><?= date('H:i', strtotime($l['at'])) ?></span>
        <span class="grow">
          <span class="title"><?php
            if ($l['kind'] === 'temp') echo '🌡 ' . number_format((float) $l['temp'], 1) . '°C';
            elseif ($l['kind'] === 'med') echo '💊 ' . h(FEVER_MEDS[$l['med']][0] ?? $l['med']) . ($l['dose'] ? ' ' . h($l['dose']) : '');
            else echo '📝 메모';
          ?></span>
          <div class="sub"><?= h(date('n/j', strtotime($l['at']))) ?> · <?= h($names[(int) $l['created_by']] ?? '') ?><?= $l['note'] ? ' · ' . h($l['note']) : '' ?></div>
        </span>
        <form method="post" data-confirm="이 기록을 지울까요?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="m" value="<?= (int) $kid['id'] ?>"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn small danger">삭제</button></form>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<p class="small muted">간격은 일반적인 어린이 해열제 기준(아세트아미노펜 4시간 · 이부프로펜 계열 6시간 · 서로 다른 성분 2시간)이에요. 용량은 아이 체중에 맞춰 약 설명서나 소아과 지시를 따라 주세요. 39°C 이상이 계속되거나, 처지거나, 경련 · 숨쉬기 힘듦 · 탈수 증상이 있으면 바로 병원에 가세요.</p>
<?php page_end('family');
