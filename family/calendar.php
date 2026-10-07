<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/calendar.php';

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
        redirect('calendar.php');
    } catch (Throwable $e) {
        $act = post('action');
        $error = ($act === 'add' ? '일정을 넣지 못했어요: ' : ($act === 'sync' ? '' : '일정을 고치지 못했어요: ')) . $e->getMessage();
        $addFailed = $act === 'add';
    }
}

if ($connected) calendar_refresh_if_stale();
$from = today();
$to = date('Y-m-d', strtotime('+30 day'));
$events = calendar_events($from, $to);
$byDay = [];
foreach ($events as $e) {
    $start = max($from, substr($e['start_at'], 0, 10));
    $last = $e['all_day'] ? date('Y-m-d', strtotime($e['end_at']) - 1) : substr($e['end_at'], 0, 10);
    for ($d = $start; $d <= min($last, $to); $d = date('Y-m-d', strtotime("$d +1 day"))) $byDay[$d][] = $e;
}
ksort($byDay);
$calendars = [];
if ($connected) {
    try { $calendars = caldav_writable(); } catch (Throwable $e) { $error = $error ?: $e->getMessage(); }
}
$writableNames = [];
foreach ($calendars as $c) $writableNames[$c['name']] = true;
$syncError = (string) setting('calendar_error', '');

page_start('가족 일정', 'calendar');
?>
<?php if (!$connected): ?>
<section class="card">
  <h2>iCloud 캘린더 연결</h2>
  <p class="muted">설정 › iCloud 캘린더에서 Apple ID와 앱 전용 암호를 넣으면, 아이폰 캘린더의 가족 일정을 여기서 보고 추가할 수 있어요.</p>
  <a class="btn primary" href="settings.php#calendar">연결하러 가기</a>
</section>
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

  <section class="card">
    <details class="fold"<?= !empty($addFailed) ? ' open' : '' ?>>
      <summary>＋ 일정 추가</summary>
      <form data-busy="아이클라우드 캘린더에 일정을 넣는 중이에요…" method="post" class="form" style="margin-top:12px">
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
    </details>
  </section>

  <?php if (!$byDay): ?><section class="card"><div class="empty">앞으로 30일 동안 일정이 없어요.</div></section><?php endif; ?>
  <?php foreach ($byDay as $d => $list): ?>
    <section class="card">
      <h3 style="color:var(--text);font-weight:700"><?= h(day_label($d)) ?></h3>
      <ul class="list">
        <?php foreach ($list as $e): ?>
          <li class="evrow" tabindex="0" role="button" data-ev='<?= h(json_encode(['uid' => $e['uid'], 'start_at' => $e['start_at'], 'title' => $e['title'], 'date' => substr($e['start_at'], 0, 10),
              'all_day' => (int) $e['all_day'], 'start' => substr($e['start_at'], 11, 5), 'end' => substr($e['end_at'], 11, 5), 'location' => $e['location'], 'note' => $e['note'] ?? '',
              'recurring' => (int) ($e['recurring'] ?? 0), 'calendar' => $e['calendar'], 'editable' => ($e['href'] ?? '') !== '' && ($writableNames[$e['calendar']] ?? false)], JSON_UNESCAPED_UNICODE)) ?>'><span class="dot" style="background:<?= h($e['color']) ?>"></span>
            <span class="time"><?= $e['all_day'] ? '종일' : (substr($e['start_at'], 0, 10) === $d ? substr($e['start_at'], 11, 5) : '계속') ?></span>
            <span class="grow"><span class="title"><?= h($e['title']) ?></span>
              <div class="sub"><?= !empty($e['recurring']) ? '🔁 ' : '' ?><?= h($e['calendar']) ?><?= $e['location'] ? ' · ' . h($e['location']) : '' ?><?= !$e['all_day'] && substr($e['end_at'], 0, 10) === $d ? ' · ~' . substr($e['end_at'], 11, 5) : '' ?></div></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>

  <form data-busy="아이클라우드 캘린더를 새로 불러오는 중이에요…" method="post" style="text-align:center">
    <?= csrf_field() ?><input type="hidden" name="action" value="sync">
    <button class="btn small">↻ 지금 새로 받기</button>
    <p class="small muted" style="margin-top:6px">일정을 누르면 고치거나 지울 수 있어요 · 마지막으로 받은 시각 <?= h((string) setting('calendar_synced_at', '-')) ?> · 10분마다 자동으로 받아요</p>
  </form>
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
<?php page_end('calendar');
