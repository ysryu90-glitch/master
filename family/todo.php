<?php
// 할 일: 내 할 일 · 가족 할 일 (날짜 · 시각 알림 · 반복)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/todo.php';

$me = require_login();
check_csrf();
$adults = members('adult');
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
// 보기: 전체(기본) · 나 · 다른 사람 · 같이
$w = (string) ($_GET['w'] ?? 'all');
$who = in_array($w, ['all', 'me', 'both'], true) || (ctype_digit($w) && isset($names[(int) $w])) ? $w : 'all';
if ($who === (string) $me['id']) $who = 'me';
$back = 'todo.php' . ($who !== 'all' ? '?w=' . $who : '');
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post('id');
    switch (post('action')) {
        case 'add':
        case 'save':
            [$title, $parsedDay] = todo_parse((string) post('title'));
            if ($title === '') { flash('할 일을 적어 주세요.'); redirect($back); }
            $day = post('action') === 'add' && $parsedDay ? $parsedDay : (post('day') !== '' ? valid_day(post('day')) : $parsedDay);
            $time = preg_match('/^\d{2}:\d{2}$/', post('time')) ? post('time') : null;
            $owner = post('owner') === 'both' ? null : ((int) post('owner') ?: (int) $me['id']);
            $repeat = isset(TODO_REPEATS[post('repeat')]) ? post('repeat') : '';
            if ($repeat && !$day) $day = today();
            if ($id) {
                db()->prepare('UPDATE todos SET title = ?, note = ?, owner_id = ?, due_day = ?, due_time = ?, repeat_rule = ? WHERE id = ?')
                    ->execute([mb_substr($title, 0, 200), mb_substr(post('note'), 0, 500), $owner, $day, $time, $repeat, $id]);
                flash('고쳤어요.');
            } else {
                db()->prepare('INSERT INTO todos (title, note, owner_id, due_day, due_time, repeat_rule, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([mb_substr($title, 0, 200), mb_substr(post('note'), 0, 500), $owner, $day, $time, $repeat, $me['id']]);
                // 가족에게 알림: 맡긴 사람에게 · 「같이」면 다른 사람 모두에게
                $when = $day ? ' · ' . todo_day_label($day) . ($time ? ' ' . $time : '') : '';
                if ($owner && $owner !== (int) $me['id']) todo_notify([$owner], '✅ ' . $me['name'] . '님이 부탁했어요', $title . $when);
                elseif (!$owner) todo_notify(other_adults((int) $me['id']), '👨‍👩‍👧 같이 할 일이 생겼어요', $title . $when . ' · ' . $me['name'] . '님이 적음');
                flash(($owner && $owner !== (int) $me['id'] ? ($names[$owner]['name'] ?? '') . '에게 부탁했어요' : (!$owner ? '같이 할 일로 넣었어요' : '넣었어요')) . ($day ? ' · ' . todo_day_label($day) : ''));
            }
            if (post('back') === 'cal' && $day) redirect('calendar.php?m=' . substr($day, 0, 7) . '&d=' . $day);
            redirect(post('back') === 'home' ? 'index.php#todo' : $back);
        case 'toggle':
            $r = todo_toggle($id, (int) $me['id']);
            if ($r && $r['now_done'] && (int) $r['created_by'] !== (int) $me['id']) {
                todo_notify([(int) $r['created_by']], '👏 ' . $me['name'] . '님이 다 했어요', $r['title'], 'tododone');
            }
            if ($ajax) json_out(['ok' => (bool) $r, 'done' => $r['now_done'] ?? false, 'next' => isset($r['next']) && $r['next'] ? todo_day_label($r['next']) : null]);
            redirect(post('back') === 'home' ? 'index.php#todo' : $back);
        case 'move':
            $to = post('to') === 'tomorrow' ? date('Y-m-d', strtotime('+1 day')) : today();
            db()->prepare('UPDATE todos SET due_day = ? WHERE id = ?')->execute([$to, $id]);
            if ($ajax) json_out(['ok' => true]);
            redirect(post('back') === 'home' ? 'index.php#todo' : $back);
        case 'delete':
            db()->prepare('DELETE FROM todos WHERE id = ?')->execute([$id]);
            flash('지웠어요.');
            redirect($back);
        case 'clear_done':
            db()->exec('DELETE FROM todos WHERE done = 1');
            redirect($back);
    }
    redirect($back);
}

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM todos WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$list = todos_open((int) $me['id'], $who);
$counts = [];
foreach (db()->query('SELECT owner_id, COUNT(*) n FROM todos WHERE done = 0 GROUP BY owner_id') as $r) $counts[$r['owner_id'] === null ? 'both' : (int) $r['owner_id']] = (int) $r['n'];
$groups = ['지났어요' => [], '오늘' => [], '내일' => [], '이번 주' => [], '나중에' => [], '언젠가' => []];
$doneList = [];
foreach ($list as $t) {
    if ((int) $t['done']) $doneList[] = $t; else $groups[todo_bucket($t)][] = $t;
}
$openCount = count($list) - count($doneList);
$f = $edit ?? ['id' => 0, 'title' => '', 'note' => '', 'owner_id' => $me['id'], 'due_day' => null, 'due_time' => null, 'repeat_rule' => ''];

function todo_row(array $t, array $names, int $meId, string $who): void
{
    $late = !$t['done'] && $t['due_day'] && $t['due_day'] < today();
    $owner = $t['owner_id'] ? ($names[(int) $t['owner_id']] ?? null) : null;
    ?>
    <div class="trow<?= $t['done'] ? ' done' : '' ?>" data-id="<?= (int) $t['id'] ?>">
      <form method="post" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <button class="tcheck" aria-label="<?= $t['done'] ? '안 한 것으로' : '다 했어요' ?>"></button></form>
      <a class="tbody" href="todo.php?<?= $who !== 'all' ? 'w=' . $who . '&' : '' ?>edit=<?= (int) $t['id'] ?>#form">
        <span class="tt"><?= h($t['title']) ?></span>
        <span class="tm">
          <?php if ($t['due_day']): ?><span class="<?= $late ? 'late' : '' ?>"><?= h(todo_day_label($t['due_day'])) ?><?= $t['due_time'] ? ' ' . h($t['due_time']) : '' ?></span><?php endif; ?>
          <?php if ($t['repeat_rule']): ?><span>🔁 <?= h(TODO_REPEATS[$t['repeat_rule']] ?? '') ?></span><?php endif; ?>
          <?php if (!$t['owner_id']): ?><span class="who both">👨‍👩‍👧 같이</span><?php elseif ($owner): ?><span class="who<?= (int) $t['owner_id'] === $meId ? ' me' : '' ?>"><?= h($owner['emoji'] . ' ' . ((int) $t['owner_id'] === $meId ? '나' : $owner['name'])) ?></span><?php endif; ?>
          <?php if ($t['done'] && $t['done_by'] && isset($names[(int) $t['done_by']])): ?><span class="by">✓ <?= h($names[(int) $t['done_by']]['name']) ?> <?= h(date('H:i', strtotime($t['done_at']))) ?></span><?php elseif ($t['created_by'] && (int) $t['created_by'] !== $meId && isset($names[(int) $t['created_by']])): ?><span class="by"><?= h($names[(int) $t['created_by']]['name']) ?>님이 적음</span><?php endif; ?>
          <?php if ($t['note'] !== ''): ?><span>📝 <?= h(mb_strimwidth($t['note'], 0, 30, '…')) ?></span><?php endif; ?>
        </span>
      </a>
      <?php if ($late): ?>
        <form method="post" class="tmove"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button name="to" value="today">오늘로</button><button name="to" value="tomorrow">내일로</button></form>
      <?php endif; ?>
    </div>
    <?php
}

page_start('할 일', 'home');
?>
<form method="post" class="tadd" id="quickadd">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <?php $addDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['add_day'] ?? '')) ? $_GET['add_day'] : ''; ?>
  <?php if ($addDay): ?><input type="hidden" name="day" value="<?= h($addDay) ?>"><input type="hidden" name="back" value="cal"><?php endif; ?>
  <input name="title"<?= $addDay ? ' autofocus' : '' ?> placeholder="<?= $addDay ? h(todo_day_label($addDay)) . ' 할 일 추가' : '할 일 추가 · 예: 내일 세탁소 맡기기' ?>" autocomplete="off" enterkeyhint="done" required>
  <button class="tadd-btn" aria-label="추가"><?= nav_icon('plus') ?></button>
  <div class="towner" role="radiogroup" aria-label="누가">
    <?php $defOwner = $who === 'both' ? 'both' : (ctype_digit($who) ? $who : (string) $me['id']); ?>
    <?php foreach ($adults as $a): ?><label><input type="radio" name="owner" value="<?= (int) $a['id'] ?>"<?= $defOwner === (string) $a['id'] ? ' checked' : '' ?>><span><?= h($a['emoji']) ?> <?= (int) $a['id'] === (int) $me['id'] ? '나' : h($a['name']) ?></span></label><?php endforeach; ?>
    <label><input type="radio" name="owner" value="both"<?= $defOwner === 'both' ? ' checked' : '' ?>><span>👨‍👩‍👧 같이</span></label>
  </div>
</form>
<p class="small muted" style="margin:-2px 6px 14px">「내일 · 금요일 · 10/15」로 시작하면 그 날짜로 · 엄마에게 맡기면 엄마 폰으로 알림이 가요.</p>

<nav class="chips scrollx tfilter" style="margin:0 -16px 6px;padding:0 16px">
  <a class="chip<?= $who === 'all' ? ' on' : '' ?>" href="todo.php">전체 <small><?= array_sum($counts) ?></small></a>
  <a class="chip<?= $who === 'me' ? ' on' : '' ?>" href="todo.php?w=me">내 것 <small><?= ($counts[(int) $me['id']] ?? 0) + ($counts['both'] ?? 0) ?></small></a>
  <?php foreach ($adults as $a): if ((int) $a['id'] === (int) $me['id']) continue; ?>
    <a class="chip<?= $who === (string) $a['id'] ? ' on' : '' ?>" href="todo.php?w=<?= (int) $a['id'] ?>"><?= h($a['emoji'] . ' ' . $a['name']) ?> <small><?= $counts[(int) $a['id']] ?? 0 ?></small></a>
  <?php endforeach; ?>
  <a class="chip<?= $who === 'both' ? ' on' : '' ?>" href="todo.php?w=both">👨‍👩‍👧 같이 <small><?= $counts['both'] ?? 0 ?></small></a>
</nav>

<?php if (!$openCount && !$doneList): ?>
  <section class="card tempty"><div class="big">✅</div><b>할 일이 없어요</b><p class="small muted">위 칸에 적으면 바로 들어가요. 정한 시각에는 알림도 보내 드려요.</p></section>
<?php elseif (!$openCount): ?>
  <section class="card tempty"><div class="big">🎉</div><b>다 했어요!</b></section>
<?php endif; ?>

<?php foreach ($groups as $label => $items): if (!$items) continue; ?>
  <h3 class="listhead<?= $label === '지났어요' ? ' red' : '' ?>"><?= h($label) ?> <span><?= count($items) ?></span></h3>
  <div class="card tlist"><?php foreach ($items as $t) todo_row($t, $names, (int) $me['id'], $who); ?></div>
<?php endforeach; ?>

<?php if ($doneList): ?>
  <details class="tdone">
    <summary class="listhead">최근 다 한 일 <span><?= count($doneList) ?></span></summary>
    <div class="card tlist"><?php foreach ($doneList as $t) todo_row($t, $names, (int) $me['id'], $who); ?></div>
    <form method="post" style="margin:0 6px 12px" data-confirm="다 한 일을 모두 지울까요?"><?= csrf_field() ?><input type="hidden" name="action" value="clear_done"><button class="btn small">다 한 일 지우기</button></form>
  </details>
<?php endif; ?>

<div class="sheet" id="form"<?= $edit ? '' : ' hidden' ?> role="dialog" aria-modal="true" aria-labelledby="form-title">
  <div class="panel">
    <div class="sheet-head"><h2 id="form-title"><?= $edit ? '할 일 고치기' : '할 일 추가' ?></h2><a class="x" href="<?= h($back) ?>" aria-label="닫기">✕</a></div>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
      <label>할 일<input name="title" value="<?= h($f['title']) ?>" required></label>
      <div class="grid2">
        <label>날짜<input type="date" name="day" value="<?= h($f['due_day'] ?? '') ?>"></label>
        <label>시각 (알림)<input type="time" name="time" value="<?= h($f['due_time'] ?? '') ?>"></label>
      </div>
      <div class="chips" style="margin:-4px 0 12px">
        <?php foreach (['오늘' => today(), '내일' => date('Y-m-d', strtotime('+1 day')), '이번 주말' => date('Y-m-d', strtotime('saturday')), '다음 주' => date('Y-m-d', strtotime('next monday')), '날짜 없음' => ''] as $lbl => $dv): ?>
          <button type="button" class="chip" onclick="this.form.day.value='<?= $dv ?>'"><?= $lbl ?></button>
        <?php endforeach; ?>
      </div>
      <label>누가<select name="owner">
        <?php foreach ($adults as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $f['owner_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= h($a['emoji'] . ' ' . $a['name']) ?><?= (int) $a['id'] === (int) $me['id'] ? ' (나)' : '' ?></option><?php endforeach; ?>
        <option value="both"<?= !$f['owner_id'] ? ' selected' : '' ?>>👨‍👩‍👧 같이 (누구든)</option>
      </select></label>
      <label>반복<select name="repeat"><?php foreach (TODO_REPEATS as $k => $lbl): ?><option value="<?= $k ?>"<?= $f['repeat_rule'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></label>
      <label>메모<input name="note" value="<?= h($f['note']) ?>" placeholder="예: 영수증 챙기기"></label>
      <button class="btn primary wide">저장</button>
    </form>
    <?php if ($edit): ?>
      <form method="post" data-confirm="이 할 일을 지울까요?" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn small danger">🗑 지우기</button></form>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var sheet = document.getElementById('form');
  sheet.addEventListener('click', function (e) { if (e.target === sheet) location.href = <?= json_encode($back) ?>; });
})();
</script>
<?php page_end('home');
