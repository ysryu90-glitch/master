<?php
// 주말 나들이 추천: 날씨 · 미세먼지 · 연휴 · 가족 일정 · 컨디션 · 최근 다녀온 곳 · 부모님 댁 방문 간격을 함께 따져서
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/places.php';

$me = require_login();
check_csrf();
$weekdays = ['일', '월', '화', '수', '목', '금', '토'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $placeId = (string) post('place');
    $day = valid_day(post('day'));
    $p = place($placeId);
    switch (post('action')) {
        case 'like':
            $stmt = db()->prepare("SELECT id FROM outing_logs WHERE place_id = ? AND kind = 'like'");
            $stmt->execute([$placeId]);
            if ($id = $stmt->fetchColumn()) {
                db()->prepare('DELETE FROM outing_logs WHERE id = ?')->execute([$id]);
            } elseif ($p) {
                db()->prepare("INSERT INTO outing_logs (place_id, day, kind, created_by, created_at) VALUES (?, CURDATE(), 'like', ?, NOW())")->execute([$placeId, $me['id']]);
            }
            break;
        case 'plan':
            if ($p) {
                db()->prepare("INSERT INTO outing_logs (place_id, day, kind, created_by, created_at) VALUES (?, ?, 'plan', ?, NOW())")->execute([$placeId, $day, $me['id']]);
                $msg = date('n/j', strtotime($day)) . ' ' . $p['name'] . '(으)로 정했어요.';
                if (setting('icloud_user')) {
                    try {
                        $cals = caldav_selected();
                        if ($cals) {
                            caldav_create($cals[0]['name'], '🧺 나들이: ' . $p['name'], $day, '10:00', '15:00', $p['name'], $p['tip']);
                            $msg .= ' 가족 캘린더에도 넣었어요.';
                        }
                    } catch (Throwable $e) {
                        $msg .= ' (캘린더 추가 실패: ' . $e->getMessage() . ')';
                    }
                }
                flash($msg);
            }
            break;
        case 'cancel':
            db()->prepare("DELETE FROM outing_logs WHERE id = ? AND kind = 'plan'")->execute([(int) post('id')]);
            break;
        case 'visit':
            if ($p) {
                db()->prepare("INSERT INTO outing_logs (place_id, day, kind, rating, memo, created_by, created_at) VALUES (?, ?, 'visit', ?, ?, ?, NOW())")
                    ->execute([$placeId, $day, max(1, min(5, (int) post('rating'))) ?: null, mb_substr(post('memo'), 0, 300), $me['id']]);
                if (($p['area'] ?? '') === 'pyeongtaek') set_setting('parents_last_visit', $day);
                flash('다녀온 곳을 기록했어요. 한 달 동안은 추천에서 조금 뒤로 보낼게요.');
            }
            break;
        case 'parents':
            set_setting('parents_last_visit', $day);
            flash('부모님 댁 방문을 기록했어요.');
            break;
    }
    redirect('outing.php' . (post('pt') ? '?pt=' . urlencode(post('pt')) : ''));
}

calendar_refresh_if_stale();

// 쉬는 날 묶음: 오늘(18시 전)부터 2주 안의 첫 연속 휴일 (토 · 일 · 공휴일)
$days = [];
for ($i = (int) date('G') >= 18 ? 1 : 0; $i < 14; $i++) {
    $d = date('Y-m-d', strtotime("+$i day"));
    if (is_day_off($d)) $days[] = $d;
    elseif ($days) break;
}
// 다음 주말도 같이 (묶음이 하루뿐이거나 일요일 저녁이면)
$nextWeekend = [];
if ($days) {
    $after = date('Y-m-d', strtotime(end($days) . ' +1 day'));
    for ($i = 0; $i < 10 && count($nextWeekend) < 4; $i++) {
        $d = date('Y-m-d', strtotime("$after +$i day"));
        if (is_day_off($d)) $nextWeekend[] = $d;
        elseif ($nextWeekend) break;
    }
}

$locs = [];
foreach (locations() as $l) $locs[$l['role']] = $l;
$home = $locs['home'] ?? default_locations()[0];
$parents = $locs['parents'] ?? default_locations()[2];
$homeWx = daily_forecast((float) $home['lat'], (float) $home['lon']);
$ptWx = daily_forecast((float) $parents['lat'], (float) $parents['lon']);

// 컨디션: 오늘 부부 준비 점수 평균
$scores = [];
foreach (members('adult') as $a) {
    $r = readiness_history((int) $a['id'], 1)[today()] ?? null;
    if ($r) $scores[] = $r['score'];
}
$readiness = $scores ? array_sum($scores) / count($scores) : null;
$tired = $readiness !== null && $readiness < 6;

// 최근 다녀온 곳 · 찜 · 정한 곳
$recent = [];
foreach (db()->query("SELECT place_id, MAX(day) d FROM outing_logs WHERE kind = 'visit' AND day > DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY place_id") as $r) {
    $recent[$r['place_id']] = (int) round((strtotime(today()) - strtotime($r['d'])) / 86400);
}
$liked = db()->query("SELECT place_id FROM outing_logs WHERE kind = 'like'")->fetchAll(PDO::FETCH_COLUMN);
$plans = db()->query("SELECT * FROM outing_logs WHERE kind = 'plan' AND day >= CURDATE() ORDER BY day")->fetchAll();
$visits = db()->query("SELECT * FROM outing_logs WHERE kind = 'visit' ORDER BY day DESC, id DESC LIMIT 8")->fetchAll();

// 부모님 댁 마지막 방문 (기록 + 지난 캘린더에서 '평택 · 부모님' 일정)
$parentsLast = (string) setting('parents_last_visit', '');
$stmt = db()->prepare("SELECT MAX(DATE(start_at)) FROM calendar_events WHERE start_at < NOW() AND (title LIKE '%평택%' OR title LIKE '%부모님%' OR title LIKE '%시댁%' OR title LIKE '%친정%')");
$stmt->execute();
$calLast = (string) $stmt->fetchColumn();
if ($calLast && $calLast > $parentsLast) { $parentsLast = $calLast; set_setting('parents_last_visit', $calLast); }
$parentsGap = $parentsLast ? (int) round((strtotime(today()) - strtotime($parentsLast)) / 86400) : null;
$ptDay = valid_day($_GET['pt'] ?? '') === ($_GET['pt'] ?? '') ? $_GET['pt'] : '';

$typeLabel = ['in' => '실내', 'out' => '야외', 'mix' => '실내외'];
$blockLabel = function (array $ds) use ($weekdays): string {
    if (!$ds) return '';
    $names = array_values(array_filter(array_map(fn($d) => HOLIDAYS[$d] ?? '', $ds)));
    $range = date('n/j', strtotime($ds[0])) . ' (' . $weekdays[(int) date('w', strtotime($ds[0]))] . ')'
        . (count($ds) > 1 ? ' ~ ' . date('n/j', strtotime(end($ds))) . ' (' . $weekdays[(int) date('w', strtotime(end($ds)))] . ')' : '');
    return $range . (count($ds) >= 3 ? ' · ' . count($ds) . '일 연휴' : '') . ($names ? ' · ' . implode(', ', array_unique($names)) : '');
};

page_start('주말 나들이', 'family');
?>
<style>
  .wxline { display: flex; align-items: center; gap: 10px; margin: 4px 0 10px; }
  .wxline .ic { font-size: 30px; }
  .wxline b { font-size: 18px; }
  .pick { border-top: 1px solid var(--line); padding: 12px 0; }
  .pick:first-of-type { border-top: none; }
  .pick .top { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
  .pick .nm { font-size: 17px; font-weight: 800; }
  .pick .rank { color: var(--orange); font-weight: 800; margin-right: 6px; }
  .pick .meta { font-size: 13px; color: var(--sub); white-space: nowrap; }
  .pick .note { font-size: 14px; margin: 4px 0 6px; }
  .why { background: var(--accent-soft); color: var(--accent); }
  .minus { background: var(--orange-soft); color: var(--orange); }
  .tag { font-size: 12px; padding: 3px 8px; border-radius: 999px; display: inline-block; margin: 0 4px 4px 0; font-weight: 700; }
  .pick .acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
  .pick .acts form { margin: 0; }
  .planb { background: var(--blue-soft); border-radius: 12px; padding: 10px 12px; font-size: 14px; margin-top: 6px; }
  .daycard h2 small { font-size: 13px; color: var(--sub); font-weight: 600; margin-left: 6px; }
</style>

<section class="card tonight">
  <h2>🧺 <?= h($blockLabel($days)) ?></h2>
  <p class="small"><?= h(MONTH_THEMES[(int) date('n', strtotime($days[0] ?? today()))]) ?>
    <?= $readiness !== null ? ' · 오늘 부부 컨디션 ' . number_format($readiness, 1) . ($tired ? ' (가볍게 다녀오기 추천)' : '') : '' ?></p>
  <?php if ($parentsGap === null || $parentsGap >= 21): ?>
    <p class="small" style="margin-top:6px">👵 평택 부모님 댁에 <?= $parentsGap === null ? '다녀온 기록이 없어요' : "마지막으로 다녀온 지 {$parentsGap}일 됐어요" ?>. 아래에서 '이 날 부모님 댁 가요'를 누르면 평택 근처 코스로 추천해요.</p>
  <?php else: ?>
    <p class="small muted" style="margin-top:6px">👵 부모님 댁 마지막 방문: <?= $parentsGap ?>일 전</p>
  <?php endif; ?>
</section>

<?php if ($plans): ?>
<section class="card">
  <h2>📌 정한 나들이</h2>
  <?php foreach ($plans as $pl): $pp = place($pl['place_id']); if (!$pp) continue; ?>
    <div class="person"><span class="who" style="width:auto;flex:1"><?= date('n/j', strtotime($pl['day'])) ?> (<?= $weekdays[(int) date('w', strtotime($pl['day']))] ?>) · <?= h($pp['name']) ?></span>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>"><button class="btn small danger">취소</button></form></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php foreach (array_merge($days, $nextWeekend) as $idx => $d):
    $ctx = day_context($d, $homeWx, $ptWx, $ptDay, $tired, $recent, $liked);
    $list = ranked($ctx);
    $top = array_slice($list, 0, 3);
    $wx = $ctx['wx'];
    $planB = null;
    if ($top && $top[0]['type'] === 'out' && $wx && $wx['rain'] >= 30) {
        foreach ($list as $c) if ($c['type'] !== 'out' && !in_array($c['id'], array_column($top, 'id'), true)) { $planB = $c; break; }
    }
    $air = $wx ? air_grade($wx['pm25'], $wx['pm10']) : null;
    if ($idx === count($days) && $nextWeekend): ?>
      <h3 style="margin:18px 4px 8px">다음 주말 미리 보기 · <?= h($blockLabel($nextWeekend)) ?></h3>
    <?php endif; ?>
<section class="card daycard">
  <h2><?= date('n/j', strtotime($d)) ?> (<?= $weekdays[(int) date('w', strtotime($d))] ?>)<?= isset(HOLIDAYS[$d]) ? '<small>' . h(HOLIDAYS[$d]) . '</small>' : '' ?><?= $ctx['parentsDay'] ? '<small>👵 평택</small>' : '' ?></h2>
  <?php if ($wx): ?>
    <div class="wxline"><span class="ic"><?= $wx['icon'] ?></span><span><b><?= round($wx['min']) ?>° / <?= round($wx['max']) ?>°</b> <?= h($wx['text']) ?> · 비 <?= (int) $wx['rain'] ?>%<?= $air !== null ? ' · 미세먼지 ' . AIR_LABELS[$air] : '' ?></span></div>
  <?php else: ?>
    <p class="small muted">아직 날씨 예보가 없어요 (10일 이후이거나 불러오지 못함). 날씨 없이 추천해요.</p>
  <?php endif; ?>
  <?php if ($ctx['events']): ?>
    <p class="small">📅 <?= h(implode(' · ', array_map(fn($e) => ($e['all_day'] ? '' : substr($e['start_at'], 11, 5) . ' ') . $e['title'], $ctx['events']))) ?></p>
  <?php endif; ?>

  <?php foreach ($top as $i => $p): ?>
    <div class="pick">
      <div class="top"><span class="nm"><span class="rank"><?= $i + 1 ?></span><?= h($p['name']) ?></span><span class="meta"><?= $typeLabel[$p['type']] ?> · 약 <?= (int) $p['minutes'] ?>분</span></div>
      <div class="note"><?= h($p['note']) ?></div>
      <div><?php foreach ($p['why'] as $w): ?><span class="tag why"><?= h($w) ?></span><?php endforeach; ?><?php foreach ($p['minus'] as $w): ?><span class="tag minus"><?= h($w) ?></span><?php endforeach; ?></div>
      <?php if ($p['tip']): ?><div class="small muted">💡 <?= h($p['tip']) ?></div><?php endif; ?>
      <div class="acts">
        <a class="btn small" href="https://map.naver.com/p/search/<?= rawurlencode($p['name']) ?>" target="_blank" rel="noopener">🗺 지도</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="like"><input type="hidden" name="place" value="<?= h($p['id']) ?>"><input type="hidden" name="pt" value="<?= h($ptDay) ?>"><button class="btn small"><?= in_array($p['id'], $liked, true) ? '❤️ 찜됨' : '🤍 찜' ?></button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="place" value="<?= h($p['id']) ?>"><input type="hidden" name="day" value="<?= $d ?>"><input type="hidden" name="pt" value="<?= h($ptDay) ?>"><button class="btn small primary">📌 이 날 가요</button></form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($planB): ?>
    <div class="planb">☔ 비가 오면 플랜 B: <b><?= h($planB['name']) ?></b> (<?= $typeLabel[$planB['type']] ?> · 약 <?= (int) $planB['minutes'] ?>분) — <?= h($planB['note']) ?></div>
  <?php endif; ?>
  <div style="margin-top:10px">
    <?php if ($ctx['parentsDay'] && $ptDay === $d): ?>
      <a class="btn small" href="outing.php">평택 말고 집 근처로 보기</a>
    <?php elseif (!$ctx['parentsDay']): ?>
      <a class="btn small" href="outing.php?pt=<?= $d ?>">👵 이 날 부모님 댁 가요</a>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>✅ 다녀온 곳 기록</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="visit">
    <label>장소<select name="place"><?php foreach (PLACES as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></label>
    <div class="grid2">
      <label>날짜<input type="date" name="day" value="<?= today() ?>" max="<?= today() ?>"></label>
      <label>별점<select name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('⭐', $i) ?></option><?php endfor; ?></select></label>
    </div>
    <label>메모<input name="memo" placeholder="예: 딸이 공룡 보고 엄청 좋아함, 주차 30분 대기"></label>
    <button class="btn primary">기록</button>
  </form>
  <form method="post" class="form inline" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="parents">
    <label>👵 부모님 댁 다녀온 날<input type="date" name="day" value="<?= today() ?>" max="<?= today() ?>"></label>
    <button class="btn">기록</button>
  </form>
  <?php if ($visits): ?>
    <ul class="list" style="margin-top:8px">
      <?php foreach ($visits as $v): $vp = place($v['place_id']); ?>
        <li><span class="time"><?= date('n/j', strtotime($v['day'])) ?></span><span class="grow"><span class="title"><?= h($vp['name'] ?? $v['place_id']) ?> <?= $v['rating'] ? str_repeat('⭐', (int) $v['rating']) : '' ?></span><?= $v['memo'] ? '<div class="sub">' . h($v['memo']) . '</div>' : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<p class="small muted">날씨 · 미세먼지는 은평구(부모님 댁 가는 날은 평택) 예보 기준이에요. 이동 시간은 차로 대략적인 값이고, 운영 시간과 예약은 가기 전에 꼭 확인해 주세요.</p>
<?php page_end('family');
