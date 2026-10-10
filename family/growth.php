<?php
// 건강 › 성장: 아이 키 · 몸무게를 적고 그래프로 보기 (병원 · 유치원 검진 때 한 번씩)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/days.php';

$me = require_login();
check_csrf();
$kids = members('child');
$kid = null;
foreach ($kids as $k) if ((int) $k['id'] === (int) ($_GET['m'] ?? post('member_id'))) $kid = $k;
$kid = $kid ?? ($kids[0] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kid) {
    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM growth WHERE id = ? AND member_id = ?')->execute([(int) post('id'), $kid['id']]);
        redirect('growth.php?m=' . $kid['id']);
    }
    $num = fn($v) => ($v = (float) str_replace(',', '.', (string) $v)) > 0 ? round($v, 1) : null;
    $h = $num(post('height'));
    $w = $num(post('weight'));
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('day')) ? post('day') : today();
    if (($h && ($h < 40 || $h > 200)) || ($w && ($w < 2 || $w > 120))) {
        flash('키(cm) · 몸무게(kg)를 확인해 주세요.');
    } elseif ($h || $w) {
        db()->prepare('INSERT INTO growth (member_id, day, height, weight, memo, created_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$kid['id'], $day, $h, $w, mb_substr(trim(post('memo')), 0, 100)]);
        flash('기록했어요.');
    }
    redirect('growth.php?m=' . $kid['id']);
}

page_start('성장', 'health');
if (!$kid): ?>
  <section class="card tempty"><div class="big">🌱</div><b>아이가 없어요</b><p class="small muted">설정 › 가족에서 아이를 추가하면 키 · 몸무게를 기록할 수 있어요.</p></section>
<?php page_end('health'); exit; endif;

$rows = growth_rows((int) $kid['id']);
$last = $rows ? end($rows) : null;
$lastH = null; $lastW = null; $prevH = null; $prevW = null;
foreach ($rows as $r) {
    if ($r['height'] !== null) { $prevH = $lastH; $lastH = $r; }
    if ($r['weight'] !== null) { $prevW = $lastW; $lastW = $r; }
}
$bd = member_birthday($kid);
$age = $bd ? age_from((int) $bd['year'], (int) $bd['month'], (int) $bd['day']) : null;
$sinceDays = $last ? (int) ((time() - strtotime($last['day'])) / 86400) : null;
$diff = function (?array $cur, ?array $prev, string $k, string $u) {
    if (!$cur || !$prev) return '';
    $d = round((float) $cur[$k] - (float) $prev[$k], 1);
    $months = max(1, (int) round((strtotime($cur['day']) - strtotime($prev['day'])) / 2629746));
    return ($d >= 0 ? '+' : '') . $d . $u . ' · ' . $months . '개월 동안';
};
?>
<?php if (count($kids) > 1): ?><nav class="chips" style="margin-bottom:10px"><?php foreach ($kids as $k): ?><a class="chip<?= (int) $k['id'] === (int) $kid['id'] ? ' on' : '' ?>" href="growth.php?m=<?= (int) $k['id'] ?>"><?= h($k['emoji'] . ' ' . $k['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>

<section class="card">
  <div class="card-head"><h2><?= h($kid['emoji']) ?> <?= h($kid['name']) ?><?= $age ? ' <span class="small muted">만 ' . $age[0] . '살 · ' . $age[1] . '개월</span>' : '' ?></h2></div>
  <div class="gstats">
    <div><span class="k">키</span><b><?= $lastH ? h($lastH['height']) . '<small>cm</small>' : '–' ?></b><span class="s"><?= h($diff($lastH, $prevH, 'height', 'cm')) ?></span></div>
    <div><span class="k">몸무게</span><b><?= $lastW ? h($lastW['weight']) . '<small>kg</small>' : '–' ?></b><span class="s"><?= h($diff($lastW, $prevW, 'weight', 'kg')) ?></span></div>
  </div>
  <?php if (!$bd): ?><p class="small muted" style="margin:8px 0 0"><a href="anniv.php">기념일에 「<?= h($kid['name']) ?> 생일」</a>을 적으면 나이 · 개월도 보여요.</p><?php endif; ?>
  <?php if ($sinceDays !== null && $sinceDays > 90): ?><p class="small" style="margin:8px 0 0;color:var(--orange)">마지막 기록이 <?= $sinceDays ?>일 전이에요. 오늘 한 번 재 볼까요?</p><?php endif; ?>
</section>

<section class="card" id="add">
  <h2>📏 오늘 재기</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="member_id" value="<?= (int) $kid['id'] ?>">
    <div class="grid2">
      <label>키 (cm)<input name="height" inputmode="decimal" placeholder="<?= $lastH ? h($lastH['height']) : '110.5' ?>"></label>
      <label>몸무게 (kg)<input name="weight" inputmode="decimal" placeholder="<?= $lastW ? h($lastW['weight']) : '18.4' ?>"></label>
    </div>
    <div class="grid2">
      <label>날짜<input type="date" name="day" value="<?= today() ?>"></label>
      <label>메모<input name="memo" maxlength="100" placeholder="예: 유치원 검진"></label>
    </div>
    <button class="btn primary wide">기록</button>
  </form>
</section>

<?php if ($g1 = growth_svg($rows, 'height', 'cm', '#12b76a')): ?><section class="card"><h2>📈 키</h2><?= $g1 ?></section><?php endif; ?>
<?php if ($g2 = growth_svg($rows, 'weight', 'kg', '#f79009')): ?><section class="card"><h2>⚖️ 몸무게</h2><?= $g2 ?></section><?php endif; ?>

<?php if ($rows): ?>
  <h3 class="listhead">기록 <span><?= count($rows) ?></span></h3>
  <div class="card rows">
    <?php foreach (array_reverse($rows) as $r): ?>
      <div class="row"><span class="ic">📏</span>
        <span class="grow"><span class="t"><?= $r['height'] !== null ? h($r['height']) . 'cm' : '' ?><?= $r['height'] !== null && $r['weight'] !== null ? ' · ' : '' ?><?= $r['weight'] !== null ? h($r['weight']) . 'kg' : '' ?></span><span class="s"><?= date('Y.n.j', strtotime($r['day'])) ?><?= $bd ? ' · ' . age_from((int) $bd['year'], (int) $bd['month'], (int) $bd['day'], $r['day'])[1] . '개월' : '' ?><?= $r['memo'] !== '' ? ' · ' . h($r['memo']) : '' ?></span></span>
        <form method="post" class="tdel" data-confirm="이 기록을 지울까요?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="member_id" value="<?= (int) $kid['id'] ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button aria-label="지우기">✕</button></form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_end('health');
