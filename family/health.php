<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';

$me = require_login();
check_csrf();

// 볼 사람 (배우자 기록도 볼 수 있음)
$adults = members('adult');
$viewId = (int) ($_GET['m'] ?? $me['id']);
$view = null;
foreach ($adults as $a) if ((int) $a['id'] === $viewId) $view = $a;
$view ??= $me;
$isMe = (int) $view['id'] === (int) $me['id'];

// 애플워치 공식 준비 점수 입력 (보정용)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isMe) {
    $day = valid_day(post('day'));
    $score = post('score');
    if ($score === '') {
        db()->prepare('DELETE FROM readiness_official WHERE member_id = ? AND day = ?')->execute([$me['id'], $day]);
    } elseif (is_numeric($score) && $score >= 0 && $score <= 10) {
        db()->prepare('REPLACE INTO readiness_official (member_id, day, score) VALUES (?, ?, ?)')->execute([$me['id'], $day, round((float) $score, 1)]);
        flash('공식 점수를 저장했어요. 3개 이상 모이면 점수가 보정돼요.');
    }
    redirect('health.php');
}

$days = 30;
$history = readiness_history((int) $view['id'], $days);
$rows = health_rows((int) $view['id'], $days);

function series(array $rows, string $field, int $days, ?callable $map = null): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $v = $rows[$d][$field] ?? null;
        $out[] = ['l' => date('j', strtotime($d)), 'v' => $v === null ? null : ($map ? $map((float) $v) : (float) $v)];
    }
    return $out;
}
$readinessSeries = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $readinessSeries[] = ['l' => date('j', strtotime($d)), 'v' => isset($history[$d]) ? $history[$d]['score'] : null];
}

function chart(array $config): string
{
    return '<svg class="chart" data-chart=\'' . h(json_encode($config, JSON_UNESCAPED_UNICODE)) . '\'></svg>';
}

function avg_of(array $series): string
{
    $values = array_filter(array_column($series, 'v'), fn($v) => $v !== null);
    return $values ? number_format(array_sum($values) / count($values), 1) : '-';
}

$stmt = db()->prepare('SELECT received_at FROM health_raw WHERE member_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$view['id']]);
$lastReceived = $stmt->fetchColumn();

$stmt = db()->prepare('SELECT day, score FROM readiness_official WHERE member_id = ? ORDER BY day DESC LIMIT 10');
$stmt->execute([$view['id']]);
$official = $stmt->fetchAll();

page_start('컨디션', 'health');
?>
<div class="segmented">
  <?php foreach ($adults as $a): ?>
    <label onclick="location.href='health.php?m=<?= (int) $a['id'] ?>'"><input type="radio" <?= (int) $a['id'] === (int) $view['id'] ? 'checked' : '' ?>><span><?= h($a['emoji'] . ' ' . $a['name']) ?></span></label>
  <?php endforeach; ?>
</div>

<section class="card">
  <div class="card-head"><h2>준비 점수 (30일)</h2><span class="muted small">평균 <?= avg_of($readinessSeries) ?></span></div>
  <?= chart(['type' => 'line', 'points' => $readinessSeries, 'color' => '#10b981', 'min' => 0, 'max' => 10]) ?>
  <p class="legend">HRV · 안정 시 심박 · 수면 · 운동 부하 · 호흡수를 평소(최근 30일)와 비교해 계산해요.</p>
</section>

<section class="card">
  <div class="card-head"><h2>수면</h2><span class="muted small">평균 <?= avg_of(series($rows, 'sleep_min', $days, fn($v) => round($v / 60, 1))) ?>시간</span></div>
  <?= chart(['type' => 'bar', 'points' => series($rows, 'sleep_min', $days, fn($v) => round($v / 60, 1)), 'color' => '#6366f1', 'min' => 0, 'goal' => 7]) ?>
  <?php $t = $rows[today()] ?? null; if ($t && $t['sleep_min']): ?>
    <p class="legend">지난밤: 코어 <?= num($t['core_min']) ?>분 · 깊은 수면 <?= num($t['deep_min']) ?>분 · 렘 <?= num($t['rem_min']) ?>분</p>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h2>심박 변이 (HRV)</h2><span class="muted small">평균 <?= avg_of(series($rows, 'hrv', $days)) ?>ms</span></div>
  <?= chart(['type' => 'line', 'points' => series($rows, 'hrv', $days), 'color' => '#ec4899']) ?>
</section>

<section class="card">
  <div class="card-head"><h2>안정 시 심박수</h2><span class="muted small">평균 <?= avg_of(series($rows, 'rhr', $days)) ?>bpm</span></div>
  <?= chart(['type' => 'line', 'points' => series($rows, 'rhr', $days), 'color' => '#ef4444']) ?>
</section>

<section class="card">
  <div class="card-head"><h2>걸음</h2><span class="muted small">평균 <?= avg_of(series($rows, 'steps', $days)) ?></span></div>
  <?= chart(['type' => 'bar', 'points' => series($rows, 'steps', $days), 'color' => '#f97316', 'min' => 0, 'goal' => 8000]) ?>
</section>

<section class="card">
  <div class="card-head"><h2>활동 에너지 · 운동</h2><span class="muted small">평균 <?= avg_of(series($rows, 'active_kcal', $days)) ?>kcal</span></div>
  <?= chart(['type' => 'bar', 'points' => series($rows, 'active_kcal', $days), 'color' => '#22c55e', 'min' => 0]) ?>
</section>

<?php if (array_filter(array_column($rows, 'weight'))): ?>
<section class="card">
  <div class="card-head"><h2>체중</h2></div>
  <?= chart(['type' => 'line', 'points' => series($rows, 'weight', $days), 'color' => '#0ea5e9']) ?>
</section>
<?php endif; ?>

<?php if ($isMe): ?>
<section class="card">
  <h2>애플워치 공식 점수로 보정</h2>
  <p class="small muted">애플워치에 나온 준비 점수를 가끔 입력하면, 3개부터 이 사이트 점수를 공식 점수에 맞게 보정해요. 비워서 저장하면 그날 값을 지워요.</p>
  <form method="post" class="form inline">
    <?= csrf_field() ?>
    <label>날짜<input type="date" name="day" value="<?= today() ?>" max="<?= today() ?>"></label>
    <label>공식 점수<input type="number" name="score" step="0.1" min="0" max="10" inputmode="decimal" placeholder="예: 7.4"></label>
    <button class="btn primary">저장</button>
  </form>
  <?php if ($official): ?>
    <p class="small muted">최근 입력: <?= h(implode(' · ', array_map(fn($o) => date('n/j', strtotime($o['day'])) . ' ' . $o['score'], $official))) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<p class="small muted">마지막으로 받은 기록: <?= $lastReceived ? h(date('n월 j일 H:i', strtotime($lastReceived))) : '아직 없음' ?></p>

<?php page_end('health');
