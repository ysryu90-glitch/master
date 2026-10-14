<?php
// 알림 › 반복 알림: 약 · 영양제 · 유산균 · 물 한 잔처럼 매일(또는 정한 요일) 같은 시각에 챙길 것
// 누구 몫인지 고르면 그 사람 폰으로 (아이 몫이면 부부 모두에게) 정한 시각 · 1시간 뒤 한 번 더 알림
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/care.php';

$me = require_login();
check_csrf();
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$members = members();
$names = [];
foreach ($members as $m) $names[(int) $m['id']] = $m;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $id = (int) (post('id') ?: post('med'));
    $st = $pdo->prepare('SELECT * FROM medications WHERE id = ? AND active = 1');
    $st->execute([$id]);
    $rm = $st->fetch() ?: null;
    switch (post('action')) {
        case 'toggle':
        case 'med_take':
            if ($rm) {
                $del = $pdo->prepare('DELETE FROM medication_logs WHERE med_id = ? AND day = CURDATE()');
                $del->execute([$id]);
                $done = $del->rowCount() === 0 || post('action') === 'med_take';
                if ($done) {
                    $pdo->prepare('INSERT IGNORE INTO medication_logs (med_id, day, taken_at, taken_by) VALUES (?, CURDATE(), NOW(), ?)')->execute([$id, $me['id']]);
                    $for = $names[(int) $rm['member_id']] ?? null;
                    if ($for && $for['role'] === 'child') activity($rm['emoji'], $for['name'] . ' ' . $rm['name'] . ' 챙겼어요', 'remind.php');
                }
                if ($ajax) json_out(['ok' => true, 'done' => $done]);
            }
            break;
        case 'save':
            $name = mb_substr(trim(post('name')), 0, 60);
            $time = preg_match('/^\d{2}:\d{2}$/', post('time')) ? post('time') : '';
            $who = (int) post('member_id');
            if ($name === '' || !$time || !isset($names[$who])) { flash('이름 · 시각 · 누구를 정해 주세요.'); break; }
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['days'] ?? [])), fn($d) => $d >= 0 && $d <= 6)));
            sort($days);
            $days = count($days) === 7 || !$days ? '' : implode(',', $days);
            $emoji = mb_substr(trim(post('emoji')), 0, 4) ?: '⏰';
            $note = mb_substr(trim(post('note')), 0, 100);
            if ($rm) {
                $pdo->prepare('UPDATE medications SET name = ?, time = ?, member_id = ?, days = ?, emoji = ?, note = ? WHERE id = ?')->execute([$name, $time, $who, $days, $emoji, $note, $id]);
                flash('알림을 고쳤어요.');
            } else {
                $pdo->prepare('INSERT INTO medications (member_id, name, time, emoji, days, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')->execute([$who, $name, $time, $emoji, $days, $note]);
                flash($emoji . ' ' . remind_days_label($days) . ' ' . $time . '에 알려 드려요.');
            }
            redirect('remind.php');
        case 'delete':
            if ($rm) $pdo->prepare('UPDATE medications SET active = 0 WHERE id = ?')->execute([$id]);
            flash('알림을 뺐어요.');
            redirect('remind.php');
    }
    redirect(post('back') === 'home' ? 'index.php' : 'remind.php');
}

$today = reminders_today();
$all = reminders_all();
$edit = null;
foreach ($all as $r) if ((int) $r['id'] === (int) ($_GET['edit'] ?? 0)) $edit = $r;
$done = count(array_filter($today, fn($r) => $r['taken_at']));
$nowHm = date('H:i');

page_start('반복 알림', 'alerts');
?>
<?php if ($today): ?>
<section class="card">
  <div class="card-head"><h2>⏰ 오늘 챙길 것</h2><span class="small muted"><?= $done ?> / <?= count($today) ?></span></div>
  <div class="meter" style="height:6px;margin:-2px 0 6px"><i style="width:<?= count($today) ? $done / count($today) * 100 : 0 ?>%"></i></div>
  <div class="tlist flat">
    <?php foreach ($today as $r): $for = $names[(int) $r['member_id']] ?? null; $by = $names[(int) $r['taken_by']] ?? null; $late = !$r['taken_at'] && $r['time'] < $nowHm; ?>
      <div class="trow<?= $r['taken_at'] ? ' done' : '' ?>">
        <form method="post" action="remind.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="tcheck" aria-label="챙겼어요"></button></form>
        <a class="tbody" href="remind.php?edit=<?= (int) $r['id'] ?>"><span class="tt"><?= h($r['emoji']) ?> <?= h($r['name']) ?></span>
          <span class="tm"><span class="<?= $late ? 'late' : '' ?>"><?= h($r['time']) ?></span><?php if ($for): ?><span class="who"><?= h($for['emoji'] . ' ' . $for['name']) ?></span><?php endif; ?><?php if ($r['taken_at']): ?><span>✓ <?= $by ? h($by['name']) . ' ' : '' ?><?= date('H:i', strtotime($r['taken_at'])) ?></span><?php endif; ?><?= $r['note'] !== '' ? '<span>' . h($r['note']) . '</span>' : '' ?></span></a>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($all): ?>
  <h3 class="listhead">모든 알림 <span><?= count($all) ?></span></h3>
  <div class="card rows">
    <?php foreach ($all as $r): $for = $names[(int) $r['member_id']] ?? null; ?>
      <a class="row" href="remind.php?edit=<?= (int) $r['id'] ?>"><span class="ic"><?= h($r['emoji']) ?></span>
        <span class="grow"><span class="t"><?= h($r['name']) ?></span><span class="s"><?= h(remind_days_label($r['days'])) ?> <?= h($r['time']) ?><?= $for ? ' · ' . h($for['emoji'] . ' ' . $for['name']) : '' ?></span></span><span class="chev">›</span></a>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <section class="card tempty"><div class="big">⏰</div><b>매일 챙길 것을 알림으로</b><p class="small muted">약 · 영양제 · 아이 유산균 · 물 한 잔처럼 매일(또는 정한 요일) 같은 시각에 챙길 것을 적어 두면, 그 시각에 폰으로 알려 드려요. 1시간 뒤에도 안 챙겼으면 한 번 더 알려요.</p></section>
<?php endif; ?>

<button type="button" class="lfab" data-open-sheet="editor" aria-label="알림 만들기">＋</button>
<div class="sheet" id="editor"<?= $edit ? '' : ' hidden' ?> role="dialog" aria-modal="true" aria-labelledby="editor-title">
  <div class="panel">
    <div class="sheet-head"><h2 id="editor-title"><?= $edit ? '알림 고치기' : '새 알림' ?></h2><?php if ($edit): ?><a class="x" href="remind.php" aria-label="닫기">✕</a><?php else: ?><button type="button" class="x" data-sheet-close aria-label="닫기">✕</button><?php endif; ?></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <?php if (!$edit): ?>
      <div class="chips scrollx" style="margin:0 0 10px"><?php foreach (REMIND_PRESETS as [$e, $n]): ?><button type="button" class="chip" onclick="var f=this.form;f.emoji.value='<?= $e ?>';f.name.value='<?= h($n) ?>';f.name.focus()"><?= $e ?> <?= h($n) ?></button><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="grid2" style="grid-template-columns:70px 1fr">
      <label>이모지<input name="emoji" maxlength="4" value="<?= h($edit['emoji'] ?? '💊') ?>"></label>
      <label>무엇을<input name="name" required maxlength="60" value="<?= h($edit['name'] ?? '') ?>" placeholder="예: 비타민, 아이 유산균"></label>
    </div>
    <div class="grid2">
      <label>몇 시에<input type="time" name="time" required value="<?= h($edit['time'] ?? '21:00') ?>"></label>
      <label>누구 몫<select name="member_id"><?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) ($edit['member_id'] ?? $me['id']) === (int) $m['id'] ? ' selected' : '' ?>><?= h($m['emoji'] . ' ' . $m['name']) ?><?= $m['role'] === 'child' ? ' (부부 모두에게 알림)' : '' ?></option><?php endforeach; ?></select></label>
    </div>
    <span class="small muted" style="display:block;margin:0 0 6px;font-weight:600">요일 (안 고르면 매일)</span>
    <div class="daypick">
      <?php $sel = ($edit['days'] ?? '') === '' ? [] : array_map('intval', explode(',', $edit['days'])); foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
        <label><input type="checkbox" name="days[]" value="<?= $d ?>"<?= in_array($d, $sel, true) ? ' checked' : '' ?>><span class="<?= $d === 0 ? 'sun' : ($d === 6 ? 'sat' : '') ?>"><?= WEEKDAYS_KO[$d] ?></span></label>
      <?php endforeach; ?>
    </div>
    <label>메모 (선택)<input name="note" maxlength="100" value="<?= h($edit['note'] ?? '') ?>" placeholder="예: 식후, 2알"></label>
    <button class="btn primary wide">저장</button>
  </form>
  <?php if ($edit): ?>
    <form method="post" data-confirm="이 알림을 뺄까요?" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn small danger">🗑 알림 빼기</button> <a class="btn small" href="remind.php">취소</a></form>
  <?php endif; ?>
  <p class="small muted" style="margin:10px 0 0">알림은 이 사이트를 홈 화면에 추가하고 <a href="settings.php#notify">알림을 켠 폰</a>으로 와요.</p>

  </div>
</div>
<?php page_end('alerts');
