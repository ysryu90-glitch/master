<?php
// 할 일: 내 할 일 · 가족 할 일 (날짜 · 시각 알림 · 반복)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/todo.php';

$me = require_login();
check_csrf();
$who = ($_GET['w'] ?? '') === 'all' ? 'all' : 'me';
$back = 'todo.php' . ($who === 'all' ? '?w=all' : '');
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
                if ($owner && $owner !== (int) $me['id']) {
                    // 다른 사람에게 맡겼으면 알림 (기기 등록돼 있을 때)
                    try {
                        require_once __DIR__ . '/lib/push.php';
                        if (has_push($owner)) push_to_member($owner, '✅ ' . $me['name'] . '님이 할 일을 부탁했어요', $title . ($day ? ' · ' . todo_day_label($day) : ''), 'todo.php', 'todo');
                    } catch (Throwable $e) {}
                }
            }
            redirect(post('back') === 'home' ? 'index.php#todo' : $back);
        case 'toggle':
            $r = todo_toggle($id, (int) $me['id']);
            if ($ajax) json_out(['ok' => (bool) $r, 'done' => $r['now_done'] ?? false, 'next' => isset($r['next']) && $r['next'] ? todo_day_label($r['next']) : null]);
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
$groups = ['지났어요' => [], '오늘' => [], '내일' => [], '이번 주' => [], '나중에' => [], '언젠가' => []];
$doneList = [];
foreach ($list as $t) {
    if ((int) $t['done']) $doneList[] = $t; else $groups[todo_bucket($t)][] = $t;
}
$openCount = count($list) - count($doneList);
$names = [];
foreach (members('adult') as $m) $names[(int) $m['id']] = $m;
$adults = members('adult');
$f = $edit ?? ['id' => 0, 'title' => '', 'note' => '', 'owner_id' => $me['id'], 'due_day' => null, 'due_time' => null, 'repeat_rule' => ''];

function todo_row(array $t, array $names, int $meId, string $who): void
{
    $late = !$t['done'] && $t['due_day'] && $t['due_day'] < today();
    $owner = $t['owner_id'] ? ($names[(int) $t['owner_id']] ?? null) : null;
    ?>
    <div class="trow<?= $t['done'] ? ' done' : '' ?>" data-id="<?= (int) $t['id'] ?>">
      <form method="post" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <button class="tcheck" aria-label="<?= $t['done'] ? '안 한 것으로' : '다 했어요' ?>"></button></form>
      <a class="tbody" href="todo.php?<?= $who === 'all' ? 'w=all&' : '' ?>edit=<?= (int) $t['id'] ?>#form">
        <span class="tt"><?= h($t['title']) ?></span>
        <span class="tm">
          <?php if ($t['due_day']): ?><span class="<?= $late ? 'late' : '' ?>"><?= h(todo_day_label($t['due_day'])) ?><?= $t['due_time'] ? ' ' . h($t['due_time']) : '' ?></span><?php endif; ?>
          <?php if ($t['repeat_rule']): ?><span>🔁 <?= h(TODO_REPEATS[$t['repeat_rule']] ?? '') ?></span><?php endif; ?>
          <?php if (!$t['owner_id']): ?><span class="who both">👨‍👩‍👧 같이</span><?php elseif ($owner && (int) $t['owner_id'] !== $meId): ?><span class="who"><?= h($owner['emoji'] . ' ' . $owner['name']) ?></span><?php endif; ?>
          <?php if ($t['note'] !== ''): ?><span>📝 <?= h(mb_strimwidth($t['note'], 0, 30, '…')) ?></span><?php endif; ?>
        </span>
      </a>
    </div>
    <?php
}

page_start('할 일', 'home');
?>
<form method="post" class="tadd" id="quickadd">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <input type="hidden" name="owner" value="<?= $who === 'all' ? 'both' : (int) $me['id'] ?>">
  <input name="title" placeholder="할 일 추가 · 예: 내일 세탁소 맡기기" autocomplete="off" enterkeyhint="done" required>
  <button class="tadd-btn" aria-label="추가"><?= nav_icon('plus') ?></button>
</form>
<p class="small muted" style="margin:-4px 6px 12px">「오늘 · 내일 · 모레 · 금요일 · 10/15」로 시작하면 그 날짜로 들어가요. 시각 · 반복 · 맡을 사람은 추가한 뒤 눌러서 정해요.</p>

<nav class="segmented ltabs">
  <a class="<?= $who === 'me' ? 'on' : '' ?>" href="todo.php">내 할 일</a>
  <a class="<?= $who === 'all' ? 'on' : '' ?>" href="todo.php?w=all">가족 전체</a>
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
