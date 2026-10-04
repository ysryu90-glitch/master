<?php
// 약: 오늘 먹었는지 체크 · 최근 2주 기록 · 약 추가/빼기 (알림은 정한 시각 + 1시간 뒤 한 번 더)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/care.php';

$me = require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $own = function (int $id) use ($pdo, $me): bool {
        $stmt = $pdo->prepare('SELECT id FROM medications WHERE id = ? AND member_id = ?');
        $stmt->execute([$id, $me['id']]);
        return (bool) $stmt->fetchColumn();
    };
    $id = (int) post('med');
    switch (post('action')) {
        case 'med_take':
            if ($own($id)) $pdo->prepare('INSERT IGNORE INTO medication_logs (med_id, day, taken_at) VALUES (?, CURDATE(), NOW())')->execute([$id]);
            break;
        case 'med_undo':
            if ($own($id)) $pdo->prepare('DELETE FROM medication_logs WHERE med_id = ? AND day = CURDATE()')->execute([$id]);
            flash('오늘 먹은 기록을 취소했어요.');
            break;
        case 'med_add':
            if (post('med_name') !== '' && preg_match('/^\d{2}:\d{2}$/', post('med_time'))) {
                $pdo->prepare('INSERT INTO medications (member_id, name, time, created_at) VALUES (?, ?, ?, NOW())')
                    ->execute([$me['id'], mb_substr(post('med_name'), 0, 60), post('med_time')]);
                flash('약을 추가했어요. 매일 ' . post('med_time') . '에 알려 드려요.');
            } else {
                flash('약 이름과 시각을 넣어 주세요.');
            }
            break;
        case 'med_time':
            if ($own($id) && preg_match('/^\d{2}:\d{2}$/', post('med_time'))) {
                $pdo->prepare('UPDATE medications SET time = ? WHERE id = ?')->execute([post('med_time'), $id]);
                flash('알림 시각을 ' . post('med_time') . '로 바꿨어요.');
            }
            break;
        case 'med_delete':
            if ($own($id)) $pdo->prepare('UPDATE medications SET active = 0 WHERE id = ?')->execute([$id]);
            flash('약을 목록에서 뺐어요.');
            break;
    }
    redirect(post('back') === 'home' ? 'index.php#meds' : 'meds.php');
}

// 최근 14일 먹은 날
$days = [];
for ($i = 13; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
function taken_days(int $medId): array
{
    $stmt = db()->prepare('SELECT day FROM medication_logs WHERE med_id = ? AND day > DATE_SUB(CURDATE(), INTERVAL 14 DAY)');
    $stmt->execute([$medId]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

$myMeds = medications_of((int) $me['id']);
$others = array_filter(members('adult'), fn($m) => (int) $m['id'] !== (int) $me['id']);
page_start('약', 'health');
?>
<section class="card" id="today">
  <div class="card-head"><h2>💊 오늘 내 약</h2><span class="small muted"><?= date('n월 j일') ?></span></div>
  <?php if (!$myMeds): ?><p class="muted">등록한 약이 없어요. 아래에서 추가하면 매일 정한 시각에 알려 드려요.</p><?php endif; ?>
  <?php foreach ($myMeds as $med): $taken = taken_days((int) $med['id']); ?>
    <div class="medrow">
      <div class="top">
        <span class="nm"><?= h($med['name']) ?> <span class="small muted"><?= h($med['time']) ?></span></span>
        <?php if ($med['taken_at']): ?>
          <form method="post" data-confirm="오늘 먹은 기록을 취소할까요?"><?= csrf_field() ?><input type="hidden" name="action" value="med_undo"><input type="hidden" name="med" value="<?= (int) $med['id'] ?>">
            <button class="btn small taken" title="누르면 취소">✓ <?= date('H:i', strtotime($med['taken_at'])) ?> 먹음</button></form>
        <?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="med_take"><input type="hidden" name="med" value="<?= (int) $med['id'] ?>"><button class="btn primary">먹었어요</button></form>
        <?php endif; ?>
      </div>
      <div class="dots" aria-label="최근 14일">
        <?php foreach ($days as $d): ?><i class="<?= isset($taken[$d]) ? 'on' : '' ?><?= $d === today() ? ' today' : '' ?>" title="<?= date('n/j', strtotime($d)) ?>"></i><?php endforeach; ?>
        <span class="small muted">최근 2주 <?= count($taken) ?>/14</span>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<?php foreach ($others as $o): $om = medications_of((int) $o['id']); if (!$om) continue; ?>
<section class="card">
  <h2><?= h($o['emoji'] . ' ' . $o['name']) ?> 약</h2>
  <?php foreach ($om as $med): ?>
    <div class="person"><span class="who" style="width:auto;flex:1"><?= h($med['name']) ?> <span class="small muted"><?= h($med['time']) ?></span></span>
      <span class="small" style="font-weight:700;color:<?= $med['taken_at'] ? 'var(--accent)' : 'var(--dim)' ?>"><?= $med['taken_at'] ? '✓ ' . date('H:i', strtotime($med['taken_at'])) : '아직' ?></span></div>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>⚙︎ 내 약 관리</h2>
  <?php foreach ($myMeds as $med): ?>
    <div class="person" style="gap:8px">
      <span class="who" style="width:auto;flex:1"><?= h($med['name']) ?></span>
      <form method="post" class="inline-time"><?= csrf_field() ?><input type="hidden" name="action" value="med_time"><input type="hidden" name="med" value="<?= (int) $med['id'] ?>">
        <input type="time" name="med_time" value="<?= h($med['time']) ?>" aria-label="알림 시각"><button class="btn small">변경</button></form>
      <form method="post" data-confirm="이 약을 목록에서 뺄까요?"><?= csrf_field() ?><input type="hidden" name="action" value="med_delete"><input type="hidden" name="med" value="<?= (int) $med['id'] ?>"><button class="btn small danger">빼기</button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" class="form inline" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="med_add">
    <label>약 이름<input name="med_name" placeholder="예: 탈모약, 비타민" required></label>
    <label>시각<input name="med_time" type="time" value="21:00" required></label>
    <button class="btn primary">추가</button>
  </form>
  <p class="small muted">정한 시각에 알림이 오고, 1시간 뒤에도 안 먹었으면 한 번 더 알려요. 잘못 눌렀으면 「✓ 먹음」을 한 번 더 누르면 취소돼요.</p>
</section>
<?php page_end('health');
