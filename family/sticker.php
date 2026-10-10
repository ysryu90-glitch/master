<?php
// 가족 › 칭찬 스티커: 잘한 일에 스티커 한 장, 목표만큼 모으면 약속한 선물 (전광판에도 보임)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sticker.php';

$me = require_login();
check_csrf();
$kids = members('child');
$kid = null;
foreach ($kids as $k) if ((int) $k['id'] === (int) ($_GET['m'] ?? post('member_id'))) $kid = $k;
$kid = $kid ?? ($kids[0] ?? null);
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kid) {
    $kidId = (int) $kid['id'];
    switch (post('action')) {
        case 'add':
            $reason = mb_substr(trim(post('reason')), 0, 60);
            db()->prepare('INSERT INTO stickers (member_id, reason, created_by, created_at) VALUES (?, ?, ?, NOW())')->execute([$kidId, $reason, $me['id']]);
            $st = sticker_state($kidId);
            if ($st['count'] >= $st['goal']) flash('🎉 ' . $kid['name'] . ' 스티커 ' . $st['goal'] . '개를 다 모았어요! 「' . ($st['reward'] ?: '선물') . '」 약속을 지켜 주세요.');
            else flash('⭐ ' . ($reason !== '' ? '「' . $reason . '」 ' : '') . '스티커를 붙였어요. ' . ($st['goal'] - $st['count']) . '개 남았어요.');
            break;
        case 'undo':
            db()->prepare('DELETE FROM stickers WHERE id = ? AND member_id = ? AND used = 0')->execute([(int) post('id'), $kidId]);
            break;
        case 'redeem':
            db()->prepare('UPDATE stickers SET used = 1 WHERE member_id = ? AND used = 0')->execute([$kidId]);
            $goals = setting('sticker_goals', []);
            $g = $goals[$kidId] ?? [];
            $g['done'] = (int) ($g['done'] ?? 0) + 1;
            $goals[$kidId] = $g;
            set_setting('sticker_goals', $goals);
            flash('🎁 선물을 받았어요! 새 판을 시작해요.');
            break;
        case 'goal':
            $goals = setting('sticker_goals', []);
            $goals[$kidId] = ['goal' => max(3, min(50, (int) post('goal'))), 'reward' => mb_substr(trim(post('reward')), 0, 40), 'done' => (int) ($goals[$kidId]['done'] ?? 0)];
            set_setting('sticker_goals', $goals);
            flash('목표를 바꿨어요.');
            break;
    }
    redirect('sticker.php?m=' . $kidId);
}

page_start('칭찬 스티커', 'family');
if (!$kid): ?>
  <section class="card tempty"><div class="big">⭐</div><b>아이가 없어요</b><p class="small muted">설정에서 아이를 추가해 주세요.</p></section>
<?php page_end('family'); exit; endif;

$st = sticker_state((int) $kid['id']);
$recent = db()->prepare('SELECT * FROM stickers WHERE member_id = ? AND used = 0 ORDER BY id DESC');
$recent->execute([$kid['id']]);
$recent = $recent->fetchAll();
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
$full = $st['count'] >= $st['goal'];
?>
<?php if (count($kids) > 1): ?><nav class="chips" style="margin-bottom:10px"><?php foreach ($kids as $k): ?><a class="chip<?= (int) $k['id'] === (int) $kid['id'] ? ' on' : '' ?>" href="sticker.php?m=<?= (int) $k['id'] ?>"><?= h($k['emoji'] . ' ' . $k['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>

<section class="card stboard">
  <div class="card-head"><h2><?= h($kid['emoji']) ?> <?= h($kid['name']) ?> 스티커판</h2><span class="small muted"><?= $st['count'] ?> / <?= $st['goal'] ?></span></div>
  <div class="stgrid" style="--n:<?= $st['goal'] <= 10 ? min(5, $st['goal']) : ($st['goal'] <= 12 ? 6 : 7) ?>">
    <?php for ($i = 0; $i < $st['goal']; $i++): ?><span class="<?= $i < $st['count'] ? 'on' : '' ?>"><?= $i < $st['count'] ? '⭐' : $i + 1 ?></span><?php endfor; ?>
  </div>
  <p class="streward">🎁 다 모으면: <b><?= h($st['reward'] ?: '약속한 선물') ?></b><?= $st['done'] ? ' <span class="small muted">· 지금까지 ' . $st['done'] . '번 받았어요</span>' : '' ?></p>
  <?php if ($full): ?>
    <form method="post" data-confirm="선물을 줬나요? 스티커판을 비우고 새로 시작해요."><?= csrf_field() ?><input type="hidden" name="action" value="redeem"><input type="hidden" name="member_id" value="<?= (int) $kid['id'] ?>"><button class="btn primary wide">🎉 다 모았어요! 선물 주고 새 판 시작</button></form>
  <?php else: ?>
    <form method="post" class="stadd"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="member_id" value="<?= (int) $kid['id'] ?>">
      <div class="chips scrollx" style="margin:0 0 10px">
        <?php foreach (STICKER_REASONS as $r): ?><button type="button" class="chip" onclick="this.form.reason.value=this.textContent.replace(/^\S+\s/,'')"><?= h($r) ?></button><?php endforeach; ?>
      </div>
      <div class="tadd" style="margin:0"><input name="reason" placeholder="무엇을 잘했나요? (비워도 돼요)" maxlength="60" autocomplete="off"><button class="tadd-btn" aria-label="스티커 붙이기">⭐</button></div>
    </form>
  <?php endif; ?>
</section>

<?php if ($recent): ?>
  <h3 class="listhead">이번 판에 받은 스티커 <span><?= count($recent) ?></span></h3>
  <div class="card rows">
    <?php foreach ($recent as $r): $by = $names[(int) $r['created_by']] ?? null; ?>
      <div class="row"><span class="ic">⭐</span>
        <span class="grow"><span class="t"><?= h($r['reason'] ?: '칭찬해요') ?></span><span class="s"><?= h(day_label(substr($r['created_at'], 0, 10))) ?> <?= substr($r['created_at'], 11, 5) ?><?= $by ? ' · ' . h($by['name']) : '' ?></span></span>
        <form method="post" class="tdel" data-confirm="이 스티커를 뗄까요?"><?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="member_id" value="<?= (int) $kid['id'] ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button aria-label="떼기">✕</button></form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<details class="card fold">
  <summary><h2>⚙︎ 목표 · 선물 바꾸기</h2></summary>
  <form method="post" class="form" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="goal"><input type="hidden" name="member_id" value="<?= (int) $kid['id'] ?>">
    <div class="grid2">
      <label>몇 개 모으면<input type="number" name="goal" min="3" max="50" value="<?= $st['goal'] ?>"></label>
      <label>선물<input name="reward" maxlength="40" value="<?= h($st['reward']) ?>" placeholder="예: 키즈카페, 아이스크림"></label>
    </div>
    <button class="btn primary wide">저장</button>
  </form>
  <p class="small muted" style="margin:10px 0 0">5살에게는 10개 안팎이 알맞아요. 전광판(아이패드)에도 스티커판이 보여서 아이가 직접 볼 수 있어요.</p>
</details>
<?php page_end('family');
