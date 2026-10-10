<?php
// 가족 › 기념일: 생일 · 결혼기념일 · 챙길 날을 D-day로 (일주일 전 · 하루 전 · 당일 아침에 알림)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/days.php';

$me = require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post('id');
    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM anniversaries WHERE id = ?')->execute([$id]);
        flash('지웠어요.');
        redirect('anniv.php');
    }
    $title = mb_substr(trim(post('title')), 0, 60);
    $date = (string) post('date');
    $kind = isset(ANNIV_KINDS[post('kind')]) ? post('kind') : 'day';
    if ($title === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        flash('이름과 날짜를 적어 주세요.');
        redirect('anniv.php');
    }
    $year = post('noyear') ? null : (int) $m[1];
    $emoji = mb_substr(trim(post('emoji')), 0, 4) ?: ANNIV_KINDS[$kind][1];
    if ($id) {
        db()->prepare('UPDATE anniversaries SET title = ?, emoji = ?, kind = ?, month = ?, day = ?, year = ? WHERE id = ?')->execute([$title, $emoji, $kind, (int) $m[2], (int) $m[3], $year, $id]);
    } else {
        db()->prepare('INSERT INTO anniversaries (title, emoji, kind, month, day, year, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$title, $emoji, $kind, (int) $m[2], (int) $m[3], $year, $me['id']]);
    }
    flash('저장했어요. 일주일 전 · 하루 전 · 당일 아침에 알려 드려요.');
    redirect('anniv.php');
}

$list = anniv_upcoming();
$edit = null;
if (isset($_GET['edit'])) foreach ($list as $a) if ((int) $a['id'] === (int) $_GET['edit']) $edit = $a;
$hasBirthday = (bool) array_filter($list, fn($a) => $a['kind'] === 'birthday');

page_start('기념일', 'family');
?>
<?php if ($list): $n = $list[0]; ?>
  <section class="card ddhero">
    <span class="e"><?= h($n['emoji']) ?></span>
    <div class="grow"><div class="small muted"><?= date('n월 j일', strtotime($n['date'])) ?> (<?= weekday_short($n['date']) ?>)</div><b><?= h($n['label']) ?></b></div>
    <span class="dd<?= $n['dday'] <= 7 ? ' soon' : '' ?>"><?= dday_text($n['dday']) ?></span>
  </section>
<?php else: ?>
  <section class="card tempty"><div class="big">🎂</div><b>챙길 날을 적어 두세요</b><p class="small muted">하린 생일 · 결혼기념일 · 부모님 생신처럼 매년 돌아오는 날을 적으면 홈에 D-day로 보이고, 일주일 전 · 하루 전 · 당일 아침에 가족 모두에게 알려 드려요.</p></section>
<?php endif; ?>

<?php if (count($list) > 1): ?>
  <h3 class="listhead">다가오는 날 <span><?= count($list) ?></span></h3>
  <div class="card rows">
    <?php foreach ($list as $a): ?>
      <a class="row" href="anniv.php?edit=<?= (int) $a['id'] ?>#form"><span class="ic"><?= h($a['emoji']) ?></span>
        <span class="grow"><span class="t"><?= h($a['label']) ?></span><span class="s"><?= date('Y.n.j', strtotime($a['date'])) ?> (<?= weekday_short($a['date']) ?>)</span></span>
        <b class="ddsm<?= $a['dday'] <= 7 ? ' soon' : '' ?>"><?= dday_text($a['dday']) ?></b></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<section class="card" id="form">
  <h2><?= $edit ? '✏️ 고치기' : '＋ 챙길 날 추가' ?></h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="segmented" style="margin-bottom:12px">
      <?php foreach (ANNIV_KINDS as $k => [$kl, $ki]): ?><label><input type="radio" name="kind" value="<?= $k ?>" <?= ($edit['kind'] ?? ($hasBirthday ? 'anniv' : 'birthday')) === $k ? 'checked' : '' ?>><span><?= $ki ?> <?= $kl ?></span></label><?php endforeach; ?>
    </div>
    <label>이름<input name="title" required maxlength="60" value="<?= h($edit['title'] ?? '') ?>" placeholder="예: 하린 생일, 결혼기념일, 할머니 생신"></label>
    <label>날짜 (처음 그날)<input type="date" name="date" required value="<?= $edit ? sprintf('%04d-%02d-%02d', $edit['year'] ?: date('Y'), $edit['month'], $edit['day']) : '' ?>"></label>
    <label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="noyear" value="1" style="width:auto;margin:0" <?= $edit && !$edit['year'] ? 'checked' : '' ?>> 해는 몰라요 (몇 번째인지 안 셈)</label>
    <label>이모지 (비우면 자동)<input name="emoji" maxlength="4" value="<?= h($edit['emoji'] ?? '') ?>" placeholder="🎂"></label>
    <button class="btn primary wide">저장</button>
  </form>
  <?php if ($edit): ?>
    <form method="post" data-confirm="<?= h($edit['title']) ?>을(를) 지울까요?" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn small danger">🗑 지우기</button> <a class="btn small" href="anniv.php">취소</a></form>
  <?php endif; ?>
  <p class="small muted" style="margin:10px 0 0">생일은 「하린 생일」처럼 이름을 넣으면 성장 기록에 나이(개월)도 같이 보여요. 음력 날짜는 올해 양력 날짜로 적어 주세요.</p>
</section>
<?php page_end('family');
