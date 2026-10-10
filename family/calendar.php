<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/weather.php'; // 공휴일
require __DIR__ . '/lib/places.php';

$me = require_login();
check_csrf();
$connected = (bool) setting('icloud_user');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $connected) {
    try {
        if (post('action') === 'sync') {
            caldav_sync();
            flash('iCloud 캘린더를 새로 받았어요.');
        } elseif (in_array(post('action'), ['update', 'delete_one', 'delete_all'], true)) {
            $ev = calendar_event_row((string) post('uid'), (string) post('start_at'));
            if (!$ev) throw new RuntimeException('일정을 찾지 못했어요. 「지금 새로 받기」를 누른 뒤 다시 해 주세요.');
            if (post('action') === 'update') {
                if (trim(post('title')) === '') throw new RuntimeException('제목을 넣어 주세요.');
                caldav_update_event($ev, ['title' => mb_substr(trim(post('title')), 0, 200), 'date' => valid_day(post('date')), 'all_day' => (bool) post('all_day'),
                    'start' => preg_match('/^\d{2}:\d{2}$/', post('start')) ? post('start') : '09:00', 'end' => preg_match('/^\d{2}:\d{2}$/', post('end')) ? post('end') : '',
                    'location' => mb_substr(post('location'), 0, 200), 'note' => mb_substr(post('note'), 0, 500)]);
                flash($ev['recurring'] ? '반복 일정 전체의 제목 · 장소 · 메모를 고쳤어요.' : '일정을 고쳤어요. 아이폰 캘린더에도 곧 바뀌어요.');
            } else {
                caldav_delete_event($ev, post('action') === 'delete_one');
                flash(post('action') === 'delete_one' ? date('n/j', strtotime($ev['start_at'])) . ' 일정만 지웠어요.' : ($ev['recurring'] ? '반복 일정을 모두 지웠어요.' : '일정을 지웠어요.'));
            }
            try { caldav_sync(); } catch (Throwable $e) { /* 고치기는 됐으니 받기는 다음에 */ }
        } elseif (post('action') === 'add' && post('title') !== '') {
            $allDay = (bool) post('all_day');
            caldav_create(post('calendar'), mb_substr(post('title'), 0, 200), valid_day(post('date')),
                $allDay ? null : (post('start') ?: '09:00'), $allDay ? null : (post('end') ?: null), post('location'), post('note'));
            try { caldav_sync(); } catch (Throwable $e) { /* 넣기는 됐으니 받기 실패는 다음에 */ }
            flash('일정을 추가했어요. 아이폰 캘린더에도 곧 보여요.');
        }
        // 보던 달 · 날짜로 돌아가기
        $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $back = basename($ref['path'] ?? '') === 'calendar.php' ? 'calendar.php' . (isset($ref['query']) ? '?' . $ref['query'] : '') : 'calendar.php';
        if (post('action') === 'add' && preg_match('/^\d{4}-\d{2}-\d{2}$/', post('date'))) $back = 'calendar.php?m=' . substr(post('date'), 0, 7) . '&d=' . post('date');
        redirect($back);
    } catch (Throwable $e) {
        $act = post('action');
        $error = ($act === 'add' ? '일정을 넣지 못했어요: ' : ($act === 'sync' ? '' : '일정을 고치지 못했어요: ')) . $e->getMessage();
        $addFailed = $act === 'add';
    }
}

if ($connected) calendar_refresh_if_stale();

// 달력: 달 · 고른 날 · 보기(달력 / 목록)
$ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$view = ($_GET['v'] ?? '') === 'list' ? 'list' : 'cal';
$selDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['d'] ?? '')) && str_starts_with($_GET['d'], $ym) ? $_GET['d'] : ($ym === date('Y-m') ? today() : $ym . '-01');
$first = $ym . '-01';
$last = date('Y-m-t', strtotime($first));
$isNow = $ym === date('Y-m');
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$q = fn(array $p) => 'calendar.php?' . http_build_query(array_filter($p + ['m' => $ym, 'v' => $view === 'list' ? 'list' : null], fn($v) => $v !== null && $v !== ''));

// 날짜별로 모으기: iCloud 일정 · 할 일 · 나들이 계획 · 일기
$from = $view === 'list' ? today() : $first;
$to = $view === 'list' ? date('Y-m-d', strtotime('+30 day')) : $last;
$byDay = [];
foreach (calendar_events($from, $to) as $e) {
    $start = max($from, substr($e['start_at'], 0, 10));
    $lastDay = $e['all_day'] ? date('Y-m-d', strtotime($e['end_at']) - 1) : substr($e['end_at'], 0, 10);
    for ($d = $start; $d <= min($lastDay, $to); $d = date('Y-m-d', strtotime("$d +1 day"))) $byDay[$d]['ev'][] = $e;
}
$stmt = db()->prepare('SELECT * FROM todos WHERE due_day BETWEEN ? AND ? AND (done = 0 OR done_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY done, due_time IS NULL, due_time, id');
$stmt->execute([$from, $to]);
foreach ($stmt as $t) $byDay[$t['due_day']]['todo'][] = $t;
$stmt = db()->prepare("SELECT place_id, day, MAX(budget) budget FROM outing_logs WHERE kind = 'plan' AND day BETWEEN ? AND ? GROUP BY place_id, day");
$stmt->execute([$from, $to]);
foreach ($stmt as $r) {
    if (!($pp = place($r['place_id']))) continue;
    // 「이 날 가요」로 iCloud에도 들어간 일정이면 한 번만 (나들이 쪽을 남김)
    $byDay[$r['day']]['plan'][] = $r + ['name' => $pp['name']];
    if (!empty($byDay[$r['day']]['ev'])) {
        $byDay[$r['day']]['ev'] = array_values(array_filter($byDay[$r['day']]['ev'], fn($e) => !str_contains($e['title'], $pp['name'])));
        if (!$byDay[$r['day']]['ev']) unset($byDay[$r['day']]['ev']);
    }
}
$stmt = db()->prepare('SELECT id, day, title, place_name, category FROM diary_entries WHERE day BETWEEN ? AND ? ORDER BY id');
$stmt->execute([$from, $to]);
foreach ($stmt as $r) $byDay[$r['day']]['diary'][] = $r;
ksort($byDay);
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;

$calendars = [];
if ($connected) {
    try { $calendars = caldav_writable(); } catch (Throwable $e) { $error = $error ?: $e->getMessage(); }
}
$writableNames = [];
foreach ($calendars as $c) $writableNames[$c['name']] = true;
$syncError = (string) setting('calendar_error', '');

/** 일정 한 줄 (누르면 고치기) */
function cal_event_li(array $e, string $d, array $writableNames): void
{
    ?>
    <li class="evrow" tabindex="0" role="button" data-ev='<?= h(json_encode(['uid' => $e['uid'], 'start_at' => $e['start_at'], 'title' => $e['title'], 'date' => substr($e['start_at'], 0, 10),
        'all_day' => (int) $e['all_day'], 'start' => substr($e['start_at'], 11, 5), 'end' => substr($e['end_at'], 11, 5), 'location' => $e['location'], 'note' => $e['note'] ?? '',
        'recurring' => (int) ($e['recurring'] ?? 0), 'calendar' => $e['calendar'], 'editable' => ($e['href'] ?? '') !== '' && ($writableNames[$e['calendar']] ?? false)], JSON_UNESCAPED_UNICODE)) ?>'><span class="dot" style="background:<?= h($e['color']) ?>"></span>
      <span class="time"><?= $e['all_day'] ? '종일' : (substr($e['start_at'], 0, 10) === $d ? substr($e['start_at'], 11, 5) : '계속') ?></span>
      <span class="grow"><span class="title"><?= h($e['title']) ?></span>
        <div class="sub"><?= !empty($e['recurring']) ? '🔁 ' : '' ?><?= h($e['calendar']) ?><?= $e['location'] ? ' · ' . h($e['location']) : '' ?><?= !$e['all_day'] && substr($e['end_at'], 0, 10) === $d ? ' · ~' . substr($e['end_at'], 11, 5) : '' ?></div></span></li>
    <?php
}

/** 그날 모아 보기 (일정 · 할 일 · 나들이 · 일기) */
function cal_day_items(string $d, array $items, array $writableNames, array $names, int $meId): void
{
    $any = false;
    if (!empty($items['ev'])) { $any = true; echo '<ul class="list">'; foreach ($items['ev'] as $e) cal_event_li($e, $d, $writableNames); echo '</ul>'; }
    foreach ($items['plan'] ?? [] as $pl) { $any = true; ?>
      <a class="agrow" href="outing.php#d<?= h($d) ?>"><span class="ai2">🧺</span><span class="grow"><b><?= h($pl['name']) ?></b> 나들이</span><span class="chev">›</span></a>
    <?php }
    foreach ($items['todo'] ?? [] as $t) { $any = true; $o = $t['owner_id'] ? ($names[(int) $t['owner_id']] ?? null) : null; ?>
      <div class="trow<?= $t['done'] ? ' done' : '' ?>">
        <form method="post" action="todo.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="tcheck" aria-label="다 했어요"></button></form>
        <a class="tbody" href="todo.php?edit=<?= (int) $t['id'] ?>#form"><span class="tt"><?= h($t['title']) ?></span>
          <span class="tm"><span>할 일<?= $t['due_time'] ? ' · ' . h($t['due_time']) : '' ?></span><?php if (!$t['owner_id']): ?><span class="who both">같이</span><?php elseif ($o && (int) $o['id'] !== $meId): ?><span class="who"><?= h($o['emoji'] . ' ' . $o['name']) ?></span><?php endif; ?></span></a>
      </div>
    <?php }
    foreach ($items['diary'] ?? [] as $dr) { $any = true; ?>
      <a class="agrow" href="diary_view.php?id=<?= (int) $dr['id'] ?>"><span class="ai2">📔</span><span class="grow"><?= h($dr['title'] ?: $dr['place_name'] ?: '일기') ?></span><span class="chev">›</span></a>
    <?php }
    if (!$any) echo '<p class="small muted" style="margin:2px 0 6px">이날은 아무것도 없어요.</p>';
}

page_start('가족 일정', 'family');
?>
<?php if (!$connected): ?>
<a class="card alert" href="settings.php#calendar">
  <span class="ai">📅</span>
  <span class="grow"><b>iCloud 캘린더를 연결해 보세요</b><span class="small muted" style="display:block">아이폰 캘린더의 가족 일정도 여기 달력에 함께 보여요. 지금은 할 일 · 나들이 · 일기만 보여요.</span></span>
  <span class="chev">›</span>
</a>
<?php else: ?>
  <?php if ($error || $syncError): $msg = $error ?: $syncError; $partial = str_starts_with($msg, '일부 캘린더'); $login = str_contains($msg, '로그인 실패'); $addErr = !empty($addFailed); $editErr = str_starts_with($msg, '일정을 고치지'); ?>
    <section class="card alert" style="align-items:flex-start">
      <span class="ai"><?= $partial ? 'ℹ️' : '⚠️' ?></span>
      <span class="grow">
        <b><?= $login ? 'iCloud에 로그인하지 못했어요' : ($editErr ? '일정을 고치지 못했어요' : '') . (!$login && !$editErr ? '' : '') ?><?= !$login && !$editErr ? ($addErr ? '일정을 넣지 못했어요' : ($partial ? '캘린더 몇 개는 받지 못했어요' : '일정을 받지 못했어요')) : '' ?></b>
        <span class="small muted" style="display:block;margin-top:2px"><?= $login ? '설정 › iCloud 캘린더에서 Apple ID와 앱 전용 암호를 다시 넣어 주세요.' : ($editErr ? '「지금 새로 받기」를 누른 뒤 다시 해 보세요. 계속되면 아이폰 캘린더 앱에서 고쳐 주세요.' : ($addErr ? (str_contains($msg, '읽기 전용') ? '다른 캘린더를 골라 다시 눌러 주세요.' : '적은 내용은 아래에 그대로 있어요. 다른 캘린더를 고르거나 잠시 뒤 다시 눌러 주세요.') : ($partial ? '받은 캘린더의 일정은 아래에 보여요. 생일 · 공휴일처럼 구독한 캘린더는 iCloud가 막아 둔 경우가 있어요.' : '잠시 뒤 「지금 새로 받기」를 눌러 주세요. 계속되면 설정에서 캘린더 연결을 다시 해 주세요.'))) ?></span>
        <details class="fold small" style="margin-top:4px"><summary>자세히</summary><span class="small muted" style="word-break:break-all"><?= h($msg) ?></span></details>
        <?php if ($login): ?><a class="btn small" style="margin-top:8px" href="settings.php#calendar">설정으로</a><?php endif; ?>
      </span>
    </section>
  <?php endif; ?>
<?php endif; ?>

<nav class="monthnav">
  <a class="btn small" href="<?= h($q(['m' => $prev])) ?>" aria-label="지난달">‹</a>
  <b><?= (int) substr($ym, 0, 4) ?>년 <?= (int) substr($ym, 5) ?>월<?php if (!$isNow): ?> <a class="chip" style="font-size:12px;vertical-align:middle" href="<?= h($q(['m' => date('Y-m')])) ?>">오늘</a><?php endif; ?></b>
  <a class="btn small" href="<?= h($q(['m' => $next])) ?>" aria-label="다음 달">›</a>
</nav>
<nav class="segmented ltabs">
  <a class="<?= $view === 'cal' ? 'on' : '' ?>" href="calendar.php?m=<?= h($ym) ?>">달력</a>
  <a class="<?= $view === 'list' ? 'on' : '' ?>" href="calendar.php?v=list">앞으로 30일</a>
</nav>

<?php if ($view === 'cal'):
    $lead = (int) date('w', strtotime($first)); $nDays = (int) date('t', strtotime($first)); ?>
<section class="card lcal ccal">
  <div class="wk"><span class="sun">일</span><span>월</span><span>화</span><span>수</span><span>목</span><span>금</span><span class="sat">토</span></div>
  <div class="grid">
    <?php for ($i = 0; $i < $lead; $i++): ?><span class="blank"></span><?php endfor; ?>
    <?php for ($dn = 1; $dn <= $nDays; $dn++):
        $d = sprintf('%s-%02d', $ym, $dn); $w = ($lead + $dn - 1) % 7; $it = $byDay[$d] ?? [];
        $cls = trim(($d === $selDay ? 'sel ' : '') . ($d === today() ? 'today ' : '') . ($w === 0 || isset(HOLIDAYS[$d]) ? 'sun ' : ($w === 6 ? 'sat ' : ''))); ?>
      <a class="<?= $cls ?>" href="<?= h($q(['d' => $d])) ?>" data-day="<?= $d ?>">
        <span class="n"><?= $dn ?></span>
        <?php if (isset(HOLIDAYS[$d])): ?><span class="hol"><?= h(mb_strimwidth(HOLIDAYS[$d], 0, 8, '')) ?></span><?php endif; ?>
        <?php foreach (array_slice($it['ev'] ?? [], 0, 2) as $e): ?><span class="evl" style="--c:<?= h($e['color']) ?>"><?= h(mb_strimwidth($e['title'], 0, 30, '')) ?></span><?php endforeach; ?>
        <?php foreach (array_slice($it['plan'] ?? [], 0, max(0, 2 - count($it['ev'] ?? []))) as $pl): ?><span class="evl" style="--c:var(--accent)">🧺<?= h(mb_strimwidth($pl['name'], 0, 30, '')) ?></span><?php endforeach; ?>
        <?php $more = count($it['ev'] ?? []) + count($it['plan'] ?? []) - 2; if ($more > 0): ?><span class="evmore">+<?= $more ?></span><?php endif; ?>
        <span class="dots"><?php if (array_filter($it['todo'] ?? [], fn($t) => !$t['done'])): ?><i class="t"></i><?php endif; ?><?php if (!empty($it['diary'])): ?><i class="d"></i><?php endif; ?></span>
      </a>
    <?php endfor; ?>
  </div>
  <div class="legend small muted"><span><i class="t"></i> 할 일</span><span><i class="d"></i> 일기</span><span>🧺 나들이</span></div>
</section>

<section class="card" id="day">
  <?php for ($dn = 1; $dn <= $nDays; $dn++): $d = sprintf('%s-%02d', $ym, $dn); ?>
    <div class="daypane" data-day="<?= $d ?>"<?= $d === $selDay ? '' : ' hidden' ?>>
      <div class="dayhead"><h2><?= h(day_label($d)) ?></h2><?php if (isset(HOLIDAYS[$d])): ?><span class="small" style="color:var(--red);font-weight:700"><?= h(HOLIDAYS[$d]) ?></span><?php endif; ?></div>
      <?php cal_day_items($d, $byDay[$d] ?? [], $writableNames, $names, (int) $me['id']); ?>
      <div class="btn-row" style="margin-top:12px">
        <?php if ($connected && $calendars): ?><button type="button" class="btn small primary" data-add-ev="<?= $d ?>">＋ 일정</button><?php endif; ?>
        <a class="btn small" href="todo.php?add_day=<?= $d ?>#quickadd">＋ 할 일</a>
        <?php if ($d <= today()): ?><a class="btn small" href="diary_edit.php?day=<?= $d ?>">📔 일기</a><?php endif; ?>
      </div>
    </div>
  <?php endfor; ?>
</section>

<?php else: /* 앞으로 30일 목록 */ ?>
  <?php if (!$byDay): ?><section class="card tempty"><div class="big">📅</div><b>앞으로 30일 동안 아무것도 없어요</b></section><?php endif; ?>
  <?php foreach ($byDay as $d => $it): ?>
    <h3 class="listhead"><?= h(day_label($d)) ?><?= isset(HOLIDAYS[$d]) ? ' <span style="color:var(--red)">' . h(HOLIDAYS[$d]) . '</span>' : '' ?></h3>
    <section class="card daylist"><?php cal_day_items($d, $it, $writableNames, $names, (int) $me['id']); ?></section>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($connected): ?>
  <form data-busy="아이클라우드 캘린더를 새로 불러오는 중이에요…" method="post" style="text-align:center;margin-top:6px">
    <?= csrf_field() ?><input type="hidden" name="action" value="sync">
    <button class="btn small">↻ 지금 새로 받기</button>
    <p class="small muted" style="margin-top:6px">일정을 누르면 고치거나 지울 수 있어요 · 마지막으로 받은 시각 <?= h((string) setting('calendar_synced_at', '-')) ?></p>
  </form>
  <?php if ($calendars): ?><button type="button" class="lfab" data-add-ev="<?= h($selDay >= today() ? $selDay : today()) ?>" aria-label="일정 추가">＋</button><?php endif; ?>

  <div class="sheet" id="addsheet"<?= !empty($addFailed) ? '' : ' hidden' ?> role="dialog" aria-modal="true" aria-labelledby="add-title">
    <div class="panel">
      <div class="sheet-head"><h2 id="add-title">iCloud 일정 추가</h2><button type="button" class="x" data-sheet-close aria-label="닫기">✕</button></div>
      <form data-busy="아이클라우드 캘린더에 일정을 넣는 중이에요…" method="post" class="form" >
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>제목<input name="title" required placeholder="예: 딸 유치원 상담" value="<?= h(!empty($addFailed) ? post('title') : '') ?>"></label>
        <label>캘린더<select name="calendar"><?php $lastCal = (string) setting('calendar_last_add', ''); foreach ($calendars as $c): ?><option<?= $c['name'] === $lastCal ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></label>
        <label>날짜<input type="date" name="date" value="<?= h(!empty($addFailed) ? valid_day(post('date')) : today()) ?>"></label>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="all_day" value="1" style="width:auto;margin:0" onchange="document.getElementById('times').classList.toggle('hidden', this.checked)"> 하루 종일</label>
        <div class="grid2" id="times">
          <label>시작<input type="time" name="start" value="09:00"></label>
          <label>끝<input type="time" name="end" value="10:00"></label>
        </div>
        <label>장소<input name="location"></label>
        <label>메모<input name="note"></label>
        <button class="btn primary wide">iCloud 캘린더에 추가</button>
      </form>
    </div>
  </div>
<?php endif; ?>
<div class="sheet" id="evsheet" hidden role="dialog" aria-modal="true" aria-labelledby="ev-title">
  <div class="panel">
    <div class="sheet-head"><h2 id="ev-title">일정 고치기</h2><button type="button" class="x" data-sheet-close aria-label="닫기">✕</button></div>
    <p class="small muted" id="ev-ro" hidden style="margin-top:-4px">이 캘린더는 iCloud에서 읽기 전용이라 여기서 고칠 수 없어요. 아이폰 캘린더 앱에서 고쳐 주세요.</p>
    <form method="post" class="form" id="ev-form" data-busy="iCloud 캘린더 일정을 고치는 중이에요…">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="uid"><input type="hidden" name="start_at">
      <label>제목<input name="title" required></label>
      <p class="small" id="ev-rec" hidden style="margin:-4px 0 12px;color:var(--orange)">🔁 반복 일정이에요. 제목 · 장소 · 메모는 반복 전체가 바뀌고, 날짜 · 시간은 아이폰 캘린더 앱에서 바꿔 주세요.</p>
      <div id="ev-when">
        <label>날짜<input type="date" name="date"></label>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="all_day" value="1" style="width:auto;margin:0" onchange="document.getElementById('ev-times').classList.toggle('hidden', this.checked)"> 하루 종일</label>
        <div class="grid2" id="ev-times"><label>시작<input type="time" name="start"></label><label>끝<input type="time" name="end"></label></div>
      </div>
      <label>장소<input name="location"></label>
      <label>메모<input name="note"></label>
      <button class="btn primary wide">고친 내용 저장</button>
    </form>
    <div class="btn-row" id="ev-del" style="margin-top:12px">
      <form method="post" data-confirm="이 날 일정만 지울까요?" id="ev-del-one" hidden><?= csrf_field() ?><input type="hidden" name="action" value="delete_one"><input type="hidden" name="uid"><input type="hidden" name="start_at"><button class="btn small danger">🗑 이 날만 지우기</button></form>
      <form method="post" data-confirm="이 일정을 iCloud에서 지울까요? 아이폰 캘린더에서도 지워져요." id="ev-del-all"><?= csrf_field() ?><input type="hidden" name="action" value="delete_all"><input type="hidden" name="uid"><input type="hidden" name="start_at"><button class="btn small danger" id="ev-del-all-btn">🗑 지우기</button></form>
    </div>
  </div>
</div>
<script>
(function () {
  // 날짜를 누르면 새로 불러오지 않고 그날 모아 보기
  var panel = document.getElementById('day');
  document.querySelectorAll('.ccal .grid a').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var day = a.getAttribute('data-day'), pane = panel && panel.querySelector('.daypane[data-day="' + day + '"]');
      if (!pane) return;
      e.preventDefault(); e.stopImmediatePropagation();
      document.querySelectorAll('.ccal .grid a.sel').forEach(function (x) { x.classList.remove('sel'); });
      a.classList.add('sel');
      panel.querySelectorAll('.daypane').forEach(function (p) { p.hidden = p !== pane; });
      var fab = document.querySelector('.lfab[data-add-ev]'); if (fab) fab.setAttribute('data-add-ev', day);
      try { history.replaceState(null, '', a.getAttribute('href')); } catch (x) {}
      var r = panel.getBoundingClientRect();
      if (r.top > window.innerHeight - 160) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, true);
  });
  // 달력을 옆으로 밀면 지난달 · 다음 달
  var cal = document.querySelector('.ccal'), sx = 0, sy = 0;
  if (cal) {
    cal.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    cal.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
      if (Math.abs(dx) < 70 || Math.abs(dy) > Math.abs(dx) * 0.6) return;
      var link = document.querySelector('.monthnav a[aria-label="' + (dx > 0 ? '지난달' : '다음 달') + '"]'); if (link) link.click();
    }, { passive: true });
  }
  // 일정 추가 창 (그날 날짜로)
  var addSheet = document.getElementById('addsheet');
  document.querySelectorAll('[data-add-ev]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!addSheet) return;
      var f = addSheet.querySelector('form'); f.elements['date'].value = b.getAttribute('data-add-ev');
      addSheet.hidden = false; document.body.classList.add('noscroll');
      setTimeout(function () { f.elements['title'].focus(); }, 60);
    });
  });
})();
(function () {
  var sheet = document.getElementById('evsheet');
  function open(ev) {
    var f = document.getElementById('ev-form');
    ['uid', 'start_at', 'title', 'date', 'start', 'end', 'location', 'note'].forEach(function (k) { if (f.elements[k]) f.elements[k].value = ev[k] || ''; });
    f.elements['all_day'].checked = !!ev.all_day;
    document.getElementById('ev-times').classList.toggle('hidden', !!ev.all_day);
    document.getElementById('ev-when').hidden = !!ev.recurring;
    document.getElementById('ev-rec').hidden = !ev.recurring;
    document.getElementById('ev-del-one').hidden = !ev.recurring;
    document.getElementById('ev-del-all-btn').textContent = ev.recurring ? '🗑 반복 전체 지우기' : '🗑 지우기';
    ['ev-del-one', 'ev-del-all'].forEach(function (id) { var g = document.getElementById(id); g.elements['uid'].value = ev.uid; g.elements['start_at'].value = ev.start_at; });
    f.hidden = !ev.editable; document.getElementById('ev-del').hidden = !ev.editable; document.getElementById('ev-ro').hidden = !!ev.editable;
    document.getElementById('ev-title').textContent = ev.editable ? '일정 고치기' : ev.title;
    sheet.hidden = false; document.body.classList.add('noscroll');
  }
  document.querySelectorAll('.evrow').forEach(function (li) {
    li.addEventListener('click', function () { open(JSON.parse(li.getAttribute('data-ev'))); });
    li.addEventListener('keydown', function (e) { if (e.key === 'Enter') open(JSON.parse(li.getAttribute('data-ev'))); });
  });
})();
</script>
<?php page_end('family');
