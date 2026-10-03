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
        } elseif (post('action') === 'add' && post('title') !== '') {
            $allDay = (bool) post('all_day');
            caldav_create(post('calendar'), mb_substr(post('title'), 0, 200), valid_day(post('date')),
                $allDay ? null : (post('start') ?: '09:00'), $allDay ? null : (post('end') ?: null), post('location'), post('note'));
            caldav_sync();
            flash('일정을 추가했어요. 아이폰 캘린더에도 곧 보여요.');
        }
        redirect('calendar.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
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
    try { $calendars = caldav_selected(); } catch (Throwable $e) { $error = $error ?: $e->getMessage(); }
}
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
  <?php if ($error || $syncError): ?><section class="card"><p class="error"><?= h($error ?: $syncError) ?></p></section><?php endif; ?>

  <section class="card">
    <details>
      <summary style="font-weight:700">+ 일정 추가</summary>
      <form data-busy="아이클라우드 캘린더에 일정을 넣는 중이에요…" method="post" class="form" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>제목<input name="title" required placeholder="예: 딸 유치원 상담"></label>
        <label>캘린더<select name="calendar"><?php foreach ($calendars as $c): ?><option><?= h($c['name']) ?></option><?php endforeach; ?></select></label>
        <label>날짜<input type="date" name="date" value="<?= today() ?>"></label>
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
      <h3><?= h(day_label($d)) ?></h3>
      <ul class="list">
        <?php foreach ($list as $e): ?>
          <li><span class="dot" style="background:<?= h($e['color']) ?>"></span>
            <span class="time"><?= $e['all_day'] ? '종일' : (substr($e['start_at'], 0, 10) === $d ? substr($e['start_at'], 11, 5) : '계속') ?></span>
            <span class="grow"><span class="title"><?= h($e['title']) ?></span>
              <div class="sub"><?= h($e['calendar']) ?><?= $e['location'] ? ' · ' . h($e['location']) : '' ?><?= !$e['all_day'] && substr($e['end_at'], 0, 10) === $d ? ' · ~' . substr($e['end_at'], 11, 5) : '' ?></div></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>

  <form data-busy="아이클라우드 캘린더를 새로 불러오는 중이에요…" method="post" style="text-align:center">
    <?= csrf_field() ?><input type="hidden" name="action" value="sync">
    <button class="btn small">↻ 지금 새로 받기</button>
    <p class="small muted" style="margin-top:6px">마지막으로 받은 시각: <?= h((string) setting('calendar_synced_at', '-')) ?> · 10분마다 자동으로 받아요</p>
  </form>
<?php endif; ?>
<?php page_end('calendar');
