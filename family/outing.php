<?php
// 주말 나들이 추천: 날씨 · 미세먼지 · 연휴 · 가족 일정 · 컨디션 · 최근 다녀온 곳 · 부모님 댁 방문 간격을 함께 따져서
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/discover.php';

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
            $dup = db()->prepare("SELECT id FROM outing_logs WHERE kind = 'plan' AND place_id = ? AND day = ?");
            $dup->execute([$placeId, $day]);
            if ($p && $dup->fetchColumn()) {
                flash('이미 ' . date('n/j', strtotime($day)) . ' ' . $p['name'] . '(으)로 정해 뒀어요.');
            } elseif ($p) {
                db()->prepare("INSERT INTO outing_logs (place_id, day, kind, created_by, created_at) VALUES (?, ?, 'plan', ?, NOW())")->execute([$placeId, $day, $me['id']]);
                $msg = date('n/j', strtotime($day)) . ' ' . $p['name'] . '(으)로 정했어요.';
                if (setting('icloud_user')) {
                    try {
                        $cals = caldav_writable();
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
            // 같은 날 같은 곳 계획이 겹쳐 있으면 함께 취소
            db()->prepare("DELETE l FROM outing_logs l JOIN (SELECT place_id, day FROM outing_logs WHERE id = ? AND kind = 'plan') x
                ON x.place_id = l.place_id AND x.day = l.day WHERE l.kind = 'plan'")->execute([(int) post('id')]);
            break;
        case 'visit':
            if ($p) {
                db()->prepare("INSERT INTO outing_logs (place_id, day, kind, rating, memo, created_by, created_at) VALUES (?, ?, 'visit', ?, ?, ?, NOW())")
                    ->execute([$placeId, $day, max(1, min(5, (int) post('rating'))) ?: null, mb_substr(post('memo'), 0, 300), $me['id']]);
                if (($p['area'] ?? '') === 'pyeongtaek') set_setting('parents_last_visit', $day);
                flash('다녀온 곳을 기록했어요. 한 달 동안은 추천에서 조금 뒤로 보낼게요.');
            }
            break;
        case 'custom_add':
            if (post('name') !== '') {
                $months = array_values(array_filter(array_map('intval', (array) ($_POST['best'] ?? [])), fn($m) => $m >= 1 && $m <= 12));
                db()->prepare('INSERT INTO custom_places (name, type, minutes, area, best, note, tip, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([mb_substr(post('name'), 0, 100), in_array(post('type'), ['in', 'out', 'mix'], true) ? post('type') : 'out',
                        max(5, min(180, (int) post('minutes'))), post('area') === 'pyeongtaek' ? 'pyeongtaek' : 'home', implode(',', $months),
                        mb_substr(post('note'), 0, 200), mb_substr(post('tip'), 0, 200), $me['id']]);
                flash('우리 장소에 추가했어요. 이제 추천 후보에 들어가요.');
            }
            break;
        case 'custom_delete':
            db()->prepare('UPDATE custom_places SET active = 0 WHERE id = ?')->execute([(int) post('id')]);
            break;
        case 'discover':
            try {
                $r = discover_fetch();
                flash("새로 받았어요: 축제·행사 {$r['festival']}개 · 서울 문화행사 {$r['seoul']}개 · 새 장소 {$r['new']}곳" . ($r['errors'] ? ' (오류: ' . implode(' / ', $r['errors']) . ')' : ''));
            } catch (Throwable $e) {
                flash('받기 실패: ' . $e->getMessage());
            }
            break;
        case 'parents':
            set_setting('parents_last_visit', $day);
            flash('부모님 댁 방문을 기록했어요.');
            break;
    }
    redirect('outing.php' . (post('pt') ? '?pt=' . urlencode(post('pt')) : '') . (in_array(post('action'), ['plan', 'like'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', post('day')) ? '#d' . post('day') : ''));
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
$plans = db()->query("SELECT MIN(id) id, place_id, day, MAX(budget) budget FROM outing_logs WHERE kind = 'plan' AND day >= CURDATE() GROUP BY place_id, day ORDER BY day, id")->fetchAll();
// 일기로 쓰지 않은 예전 '다녀옴' 기록과 최근 일기
$visits = db()->query("SELECT l.* FROM outing_logs l WHERE l.kind = 'visit' AND NOT EXISTS (SELECT 1 FROM diary_entries d WHERE d.visit_log_id = l.id) ORDER BY l.day DESC, l.id DESC LIMIT 5")->fetchAll();
$recentDiary = diary_entries(null, 8, 'outing');
$pendingDiary = diary_pending_plans();

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

page_start('나들이 추천', 'family');
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
  .planrow { display: flex; gap: 10px; align-items: baseline; padding: 8px 0; border-top: 1px solid var(--line); }
  .planrow:first-of-type { border-top: none; }
  .planrow .d { white-space: nowrap; font-size: 14px; }
  .planrow .chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .planchip { display: inline-flex; align-items: center; gap: 2px; margin: 0; background: var(--accent-soft); color: var(--accent); border-radius: 999px; padding: 4px 4px 4px 12px; font-size: 14px; font-weight: 700; }
  .daycard .planrow { flex-direction: column; gap: 6px; }
  .planchip > span { white-space: normal; }
  .money { background: var(--blue-soft); color: var(--blue); }
  .planchip button { border: 0; background: none; color: inherit; font-size: 13px; width: 26px; height: 26px; border-radius: 50%; cursor: pointer; }
  .daytabs { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin: 0 -16px 10px; padding: 4px 16px 8px; background: color-mix(in srgb, var(--bg) 92%, transparent); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); position: sticky; top: calc(52px + env(safe-area-inset-top)); z-index: 15; }
  .daytabs::-webkit-scrollbar { display: none; }
  .daytabs a { flex: none; display: flex; flex-direction: column; align-items: center; min-width: 58px; padding: 7px 10px; border-radius: 14px; background: var(--card); box-shadow: var(--shadow); color: var(--text); text-decoration: none; }
  .daytabs a .w { font-size: 12px; color: var(--sub); font-weight: 700; }
  .daytabs a.sun .w { color: var(--red); } .daytabs a.sat .w { color: var(--blue); }
  .daytabs a b { font-size: 15px; }
  .daytabs a .nx { font-size: 10px; color: var(--sub); }
  .daytabs a.on { background: var(--text); }
  .daytabs a.on .w, .daytabs a.on b, .daytabs a.on .nx { color: var(--bg); }
  .on-plan { background: var(--accent-soft); color: var(--accent); cursor: default; }
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

<?php $pendingPlaces = array_filter(array_map(fn($pl) => ($pp = place($pl['place_id'])) ? $pl + ['name' => $pp['name']] : null, $pendingDiary)); ?>
<?php if ($pendingPlaces): ?>
<section class="card todo">
  <div class="card-head"><h2>📔 잘 다녀오셨어요?</h2><span class="small muted">사진 · 별점 남기기</span></div>
  <?php foreach ($pendingPlaces as $pl): ?>
    <a class="todo-row" href="diary_edit.php?cat=outing&place=<?= rawurlencode($pl['place_id']) ?>&day=<?= h($pl['day']) ?>">
      <span class="ic">🧺</span><span class="grow"><b><?= h($pl['name']) ?></b><div class="small muted"><?= date('n/j', strtotime($pl['day'])) ?> (<?= $weekdays[(int) date('w', strtotime($pl['day']))] ?>)</div></span><span class="more" style="white-space:nowrap">일기 쓰기 ›</span>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php
// 정한 나들이: 날짜별로 묶기
$plansByDay = [];
foreach ($plans as $pl) if (($pp = place($pl['place_id']))) $plansByDay[$pl['day']][] = $pl + ['name' => $pp['name']];
$allDays = array_merge($days, $nextWeekend);
?>
<?php $otherPlans = array_diff_key($plansByDay, array_flip($allDays)); if ($otherPlans): ?>
<section class="card">
  <h2>📌 다른 날 정한 나들이</h2>
  <?php foreach ($otherPlans as $pd => $pls): ?>
    <div class="planrow"><b class="d"><?= date('n/j', strtotime($pd)) ?> (<?= $weekdays[(int) date('w', strtotime($pd))] ?>)</b>
      <span class="chips"><?php foreach ($pls as $pl): ?><form method="post" class="planchip" data-confirm="<?= h($pl['name']) ?> 나들이를 취소할까요?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>"><span><?= h($pl['name']) ?></span><button aria-label="취소" title="취소">✕</button></form><?php endforeach; ?></span></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (count($allDays) > 1): ?>
<nav class="daytabs" id="daytabs" aria-label="날짜 고르기">
  <?php foreach ($allDays as $i => $d): $w = (int) date('w', strtotime($d)); ?>
    <a href="#d<?= $d ?>" data-day="<?= $d ?>" class="<?= $w === 0 || isset(HOLIDAYS[$d]) ? 'sun' : ($w === 6 ? 'sat' : '') ?>"><span class="w"><?= $weekdays[$w] ?><?= isset($plansByDay[$d]) ? ' 📌' : '' ?></span><b><?= date('n/j', strtotime($d)) ?></b><?= $i >= count($days) ? '<span class="nx">다음 주</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php $nearShown = []; foreach (array_merge($days, $nextWeekend) as $idx => $d):
    $ctx = day_context($d, $homeWx, $ptWx, $ptDay, $tired, $recent, $liked);
    $list = ranked($ctx, discover_candidates($d, $home, $parents));
    $top = array_slice($list, 0, 3);
    $wx = $ctx['wx'];
    $planB = null;
    if ($top && $top[0]['type'] === 'out' && $wx && $wx['rain'] >= 30) {
        foreach ($list as $c) if ($c['type'] !== 'out' && !in_array($c['id'], array_column($top, 'id'), true)) { $planB = $c; break; }
    }
    $nearTrip = null;
    if (!$ctx['parentsDay'] && !array_filter($top, fn($c) => !empty($c['near']))) {
        foreach ($list as $c) if (!empty($c['near']) && $c['minutes'] <= 110 && !in_array($c['id'], $nearShown, true)) { $nearTrip = $c; break; }
        if ($nearTrip) $nearShown[] = $nearTrip['id']; // 날마다 다른 근교를 보여 줘요
    }
    $air = $wx ? air_grade($wx['pm25'], $wx['pm10']) : null;
    ?>
<section class="card daycard" id="d<?= $d ?>" data-day="<?= $d ?>">
  <h2><?= date('n/j', strtotime($d)) ?> (<?= $weekdays[(int) date('w', strtotime($d))] ?>)<?= isset(HOLIDAYS[$d]) ? '<small>' . h(HOLIDAYS[$d]) . '</small>' : '' ?><?= $ctx['parentsDay'] ? '<small>👵 평택</small>' : '' ?></h2>
  <?php if ($wx): ?>
    <div class="wxline"><span class="ic"><?= $wx['icon'] ?></span><span><b><?= round($wx['min']) ?>° / <?= round($wx['max']) ?>°</b> <?= h($wx['text']) ?> · 비 <?= (int) $wx['rain'] ?>%<?= $air !== null ? ' · 미세먼지 ' . AIR_LABELS[$air] : '' ?></span></div>
  <?php else: ?>
    <p class="small muted">아직 날씨 예보가 없어요 (10일 이후이거나 불러오지 못함). 날씨 없이 추천해요.</p>
  <?php endif; ?>
  <?php if (!empty($plansByDay[$d])): ?>
    <div class="planrow" style="border:0;padding-top:0"><b class="d">📌 가기로 한 곳</b><span class="chips"><?php foreach ($plansByDay[$d] as $pl): ?><form method="post" class="planchip" data-confirm="<?= h($pl['name']) ?> 나들이를 취소할까요?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>"><span><?= h($pl['name']) ?></span><button aria-label="취소" title="취소">✕</button></form><?php endforeach; ?></span></div>
    <p class="small" style="margin:-4px 0 10px"><a href="pack.php">🎒 나들이 준비물 챙기기 ›</a></p>
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
        <?php if (!empty($p['url'])): ?><a class="btn small" href="<?= h($p['url']) ?>" target="_blank" rel="noopener">ℹ️ 안내</a><?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="like"><input type="hidden" name="day" value="<?= $d ?>"><input type="hidden" name="place" value="<?= h($p['id']) ?>"><input type="hidden" name="pt" value="<?= h($ptDay) ?>"><button class="btn small"><?= in_array($p['id'], $liked, true) ? '❤️ 찜됨' : '🤍 찜' ?></button></form>
        <?php if (in_array($p['id'], array_column($plansByDay[$d] ?? [], 'place_id'), true)): ?><span class="btn small on-plan">📌 이 날 가기로 함</span>
        <?php else: ?><form data-busy="나들이로 정하고 가족 캘린더에 넣는 중이에요…" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="place" value="<?= h($p['id']) ?>"><input type="hidden" name="day" value="<?= $d ?>"><input type="hidden" name="pt" value="<?= h($ptDay) ?>"><button class="btn small primary">📌 이 날 가요</button></form><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($planB): ?>
    <div class="planb">☔ 비가 오면 플랜 B: <b><?= h($planB['name']) ?></b> (<?= $typeLabel[$planB['type']] ?> · 약 <?= (int) $planB['minutes'] ?>분) — <?= h($planB['note']) ?></div>
  <?php endif; ?>
  <?php if ($nearTrip): ?>
    <div class="planb">🚗 서울 근교로 간다면: <b><?= h($nearTrip['name']) ?></b> (<?= $typeLabel[$nearTrip['type']] ?> · 약 <?= (int) $nearTrip['minutes'] ?>분) — <?= h($nearTrip['note']) ?>
      <form data-busy="나들이로 정하고 가족 캘린더에 넣는 중이에요…" method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="place" value="<?= h($nearTrip['id']) ?>"><input type="hidden" name="day" value="<?= $d ?>"><input type="hidden" name="pt" value="<?= h($ptDay) ?>"><button class="btn small">📌 이 날 가요</button></form></div>
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

<script>
(function () {
  var tabs = document.querySelectorAll('#daytabs a'), cards = document.querySelectorAll('.daycard');
  if (!tabs.length) return;
  function show(day, scroll) {
    var found = false;
    cards.forEach(function (c) { var on = c.getAttribute('data-day') === day; c.hidden = !on; found = found || on; });
    if (!found) return show(tabs[0].getAttribute('data-day'));
    tabs.forEach(function (t) { var on = t.getAttribute('data-day') === day; t.classList.toggle('on', on); if (on && scroll) t.scrollIntoView({ inline: 'center', block: 'nearest' }); });
    try { sessionStorage.setItem('outingDay', day); } catch (e) {}
  }
  tabs.forEach(function (t) { t.addEventListener('click', function (e) { e.preventDefault(); show(t.getAttribute('data-day'), true); }); });
  var want = (location.hash.match(/^#d(\d{4}-\d{2}-\d{2})$/) || [])[1];
  if (!want) try { want = sessionStorage.getItem('outingDay'); } catch (e) {}
  show(want || tabs[0].getAttribute('data-day'), true);
})();
</script>

<?php $events = upcoming_events($home); $dstatus = setting('discover_status'); ?>
<section class="card">
  <div class="card-head"><h2>🎉 3주 안 축제 · 행사</h2>
    <form data-busy="축제 · 행사 · 새 장소를 받아오는 중이에요 (최대 30초)…" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="discover"><button class="btn small">↻ 새로 받기</button></form></div>
  <?php if (!setting('tourapi_key') && !setting('seoul_key')): ?>
    <p class="small muted">설정 › 🧺 나들이 데이터에 관광공사 · 서울시 인증키를 넣으면 축제 · 행사와 새로 생긴 곳을 매일 받아와 추천에 넣어요.</p>
  <?php elseif (!$events): ?>
    <div class="empty">가까운 축제 · 행사가 아직 없어요.</div>
  <?php endif; ?>
  <ul class="list">
    <?php foreach ($events as $e): ?>
      <li><span class="grow"><span class="title"><?= h($e['title']) ?></span>
        <div class="sub"><?= date('n/j', strtotime($e['start_date'])) ?>~<?= date('n/j', strtotime($e['end_date'])) ?>
          <?= $e['km'] !== null ? ' · 약 ' . est_minutes($e['km']) . '분' : '' ?><?= $e['place'] ? ' · ' . h($e['place']) : ($e['addr'] ? ' · ' . h($e['addr']) : '') ?>
          <?= $e['target'] ? ' · ' . h($e['target']) : '' ?><?= $e['fee'] ? ' · ' . h($e['fee']) : '' ?></div></span>
        <a class="btn small" href="<?= h($e['url'] ?: 'https://search.naver.com/search.naver?query=' . rawurlencode($e['title'])) ?>" target="_blank" rel="noopener">보기</a></li>
    <?php endforeach; ?>
  </ul>
  <?php if (is_array($dstatus)): ?><p class="small muted">마지막으로 받은 시각 <?= h($dstatus['at']) ?> · 출처: 한국관광공사, 서울시</p>
    <?php if (!empty($dstatus['errors'])): ?><details class="fold small"><summary style="color:var(--orange)">⚠️ 일부 정보를 받지 못했어요 (자세히)</summary><p class="small muted" style="margin:4px 0 0;word-break:break-all"><?= h(implode(' / ', $dstatus['errors'])) ?></p></details><?php endif; ?>
  <?php endif; ?>
</section>

<?php $customs = db()->query('SELECT * FROM custom_places WHERE active = 1 ORDER BY id DESC')->fetchAll(); ?>
<section class="card">
  <h2>⭐ 우리 장소</h2>
  <p class="small muted">단골 키즈카페, 아는 공원처럼 우리 가족만 아는 곳을 넣으면 추천 후보에 들어가요.</p>
  <?php foreach ($customs as $c): ?>
    <div class="person"><span class="who" style="width:auto;flex:1"><?= h($c['name']) ?> <span class="small muted"><?= ['in' => '실내', 'out' => '야외', 'mix' => '실내외'][$c['type']] ?? '' ?> · 약 <?= (int) $c['minutes'] ?>분</span></span>
      <form method="post" data-confirm="이 장소를 뺄까요?"><?= csrf_field() ?><input type="hidden" name="action" value="custom_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn small danger">빼기</button></form></div>
  <?php endforeach; ?>
  <details class="fold" style="margin-top:8px"><summary>＋ 장소 추가</summary>
    <form method="post" class="form" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="custom_add">
      <label>이름<input name="name" required placeholder="예: 연신내 ○○ 키즈카페"></label>
      <div class="grid3">
        <label>종류<select name="type"><option value="in">실내</option><option value="out">야외</option><option value="mix">실내외</option></select></label>
        <label>집에서 (분)<input name="minutes" type="number" value="15" min="5" max="180"></label>
        <label>지역<select name="area"><option value="home">집 근처</option><option value="pyeongtaek">평택</option></select></label>
      </div>
      <label>특히 좋은 달 (선택)</label>
      <div class="chips" style="margin-bottom:12px"><?php for ($m = 1; $m <= 12; $m++): ?><label class="chip" style="margin:0"><input type="checkbox" name="best[]" value="<?= $m ?>" style="width:auto;margin:0 4px 0 0;display:inline"><?= $m ?>월</label><?php endfor; ?></div>
      <label>설명<input name="note" placeholder="예: 볼풀이 넓고 부모 카페 있음"></label>
      <label>팁<input name="tip" placeholder="예: 주말 예약 필수"></label>
      <button class="btn primary">추가</button>
    </form>
  </details>
</section>

<section class="card">
  <h2>📔 다녀왔어요 · 나들이 일기 쓰기</h2>
  <form method="get" action="diary_edit.php" class="form">
    <input type="hidden" name="cat" value="outing">
    <label>장소<select name="place">
      <?php foreach ($customs as $c): ?><option value="c<?= (int) $c['id'] ?>">⭐ <?= h($c['name']) ?></option><?php endforeach; ?>
      <?php foreach ($events as $e): ?><option value="<?= h($e['id']) ?>">🎉 <?= h($e['title']) ?></option><?php endforeach; ?>
      <?php foreach (PLACES as $p): ?><option value="<?= h($p['id']) ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
      <option value="_">✏️ 목록에 없는 곳 (직접 적기)</option>
    </select></label>
    <label>날짜<input type="date" name="day" value="<?= today() ?>" max="<?= today() ?>"></label>
    <button class="btn primary">📸 사진 · 별점 남기기</button>
  </form>
  <p class="small muted" style="margin:0 0 8px">남긴 별점은 추천에 반영돼요. 다 같이 좋아한 곳은 한 달 뒤 다시 추천하고, 별로였던 곳은 뒤로 보내요.</p>
  <form method="post" class="form inline" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="parents">
    <label>👵 부모님 댁 다녀온 날<input type="date" name="day" value="<?= today() ?>" max="<?= today() ?>"></label>
    <button class="btn">기록</button>
  </form>
  <?php if ($recentDiary): ?>
    <div class="dphotos" style="margin-top:12px">
      <?php foreach ($recentDiary as $de): ?>
        <a class="dph" href="diary_view.php?id=<?= (int) $de['id'] ?>" title="<?= h($de['place_name'] ?: $de['title']) ?>">
          <?php if ($de['cover']): ?><img src="diary_photo.php?id=<?= $de['cover'] ?>&t=1" alt="" loading="lazy"><?php else: ?><span style="display:flex;height:100%;align-items:center;justify-content:center;font-size:30px">🧺</span><?php endif; ?>
          <span class="rm" style="pointer-events:none"><span><?= date('n/j', strtotime($de['day'])) ?></span></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($visits): ?>
    <ul class="list" style="margin-top:8px">
      <?php foreach ($visits as $v): $vp = place($v['place_id']); ?>
        <li><span class="time"><?= date('n/j', strtotime($v['day'])) ?></span><span class="grow"><span class="title"><?= h($vp['name'] ?? $v['place_id']) ?> <?= $v['rating'] ? str_repeat('⭐', (int) $v['rating']) : '' ?></span><?= $v['memo'] ? '<div class="sub">' . h($v['memo']) . '</div>' : '' ?></span>
          <a class="btn small" href="diary_edit.php?cat=outing&place=<?= rawurlencode($v['place_id']) ?>&day=<?= h($v['day']) ?>">📔 일기로</a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p style="margin:12px 0 0"><a class="btn small" href="diary.php?c=outing">📔 나들이 일기 모아 보기 ›</a></p>
</section>

<p class="small muted">날씨 · 미세먼지는 은평구(부모님 댁 가는 날은 평택) 예보 기준이에요. 이동 시간은 차로 대략적인 값이고, 운영 시간과 예약은 가기 전에 꼭 확인해 주세요.</p>
<?php page_end('family');
