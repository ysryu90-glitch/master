<?php
// 장보기: 가족이 같이 쓰는 살 것 목록 (체크하면 그 앞뒤 장보기 결제 메모에 붙음)
require __DIR__ . '/lib/bootstrap.php';

$me = require_login();
check_csrf();
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $id = (int) post('id');
    switch (post('action')) {
        case 'add':
            $added = [];
            foreach (preg_split('/[,，\n]+/u', (string) post('names')) as $name) {
                if (($name = trim($name)) === '') continue;
                $pdo->prepare('INSERT INTO shopping (name, created_by, created_at) VALUES (?, ?, NOW())')->execute([mb_substr($name, 0, 100), $me['id']]);
                $added[] = $name;
            }
            if ($added && post('tell')) {
                require_once __DIR__ . '/lib/todo.php';
                todo_notify(other_adults((int) $me['id']), '🛒 ' . $me['name'] . '님이 장보기에 넣었어요', implode(', ', $added), 'shop');
            }
            redirect('shop.php');
        case 'toggle':
            $pdo->prepare('UPDATE shopping SET done_at = IF(done = 0, NOW(), NULL), done_by = IF(done = 0, ?, NULL), done = 1 - done WHERE id = ?')->execute([$me['id'], $id]);
            $st = $pdo->prepare('SELECT done FROM shopping WHERE id = ?');
            $st->execute([$id]);
            $done = (int) $st->fetchColumn() === 1;
            if ($ajax) json_out(['ok' => true, 'done' => $done]);
            redirect('shop.php');
        case 'delete':
            $pdo->prepare('DELETE FROM shopping WHERE id = ?')->execute([$id]);
            redirect('shop.php');
        case 'clear':
            $pdo->exec('DELETE FROM shopping WHERE done = 1');
            flash('산 것을 목록에서 지웠어요.');
            redirect('shop.php');
    }
    redirect('shop.php');
}

$items = db()->query('SELECT s.* FROM shopping s ORDER BY s.done, s.done_at DESC, s.id DESC')->fetchAll();
$todo = array_values(array_filter($items, fn($s) => !$s['done']));
$done = array_values(array_filter($items, fn($s) => $s['done']));
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
// 자주 사는 것 (최근 장보기 결제 메모 · 지난 목록에서)
$frequent = db()->query("SELECT name FROM shopping WHERE created_at > DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY name ORDER BY COUNT(*) DESC, MAX(id) DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
$frequent = array_values(array_diff($frequent, array_column($todo, 'name')));

page_start('장보기', 'family');
?>
<div class="segmented dcat" style="margin-bottom:14px"><a href="shop.php" class="on">🛒 장보기</a><a href="pack.php">🎒 챙길 것</a></div>
<form method="post" class="tadd" id="quickadd">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <input name="names" placeholder="살 것 추가 · 예: 두부, 우유, 계란" autocomplete="off" enterkeyhint="done" required>
  <button class="tadd-btn" aria-label="추가"><?= nav_icon('plus') ?></button>
  <div class="towner"><label><input type="checkbox" name="tell" value="1"><span>🔔 가족에게 알리기</span></label></div>
</form>
<?php if ($frequent): ?>
  <div class="chips scrollx" style="margin:-4px -16px 14px;padding:0 16px"><span class="small muted" style="flex:none;align-self:center">자주:</span>
    <?php foreach ($frequent as $fn): ?><button type="button" class="chip" onclick="var i=document.querySelector('#quickadd [name=names]');i.value=(i.value?i.value.replace(/[,\s]+$/,'')+', ':'')+this.textContent;i.focus()"><?= h($fn) ?></button><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$items): ?>
  <section class="card tempty"><div class="big">🛒</div><b>살 것이 없어요</b><p class="small muted">위 칸에 쉼표로 여러 개를 한 번에 적을 수 있어요. 오늘 저녁 메뉴의 재료도 여기로 넣을 수 있어요.</p></section>
<?php endif; ?>

<?php if ($todo): ?>
  <h3 class="listhead">살 것 <span><?= count($todo) ?></span></h3>
  <div class="card tlist">
    <?php foreach ($todo as $s): $by = $names[(int) $s['created_by']] ?? null; ?>
      <div class="trow" data-id="<?= (int) $s['id'] ?>">
        <form method="post" action="shop.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="tcheck" aria-label="샀어요"></button></form>
        <div class="tbody"><span class="tt"><?= h($s['name']) ?></span><span class="tm"><?php if ($by && (int) $by['id'] !== (int) $me['id']): ?><span class="by"><?= h($by['name']) ?>님이 적음</span><?php endif; ?></span></div>
        <form method="post" class="tdel"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button aria-label="지우기">✕</button></form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($done): ?>
  <h3 class="listhead">산 것 <span><?= count($done) ?></span></h3>
  <div class="card tlist">
    <?php foreach ($done as $s): $by = $names[(int) $s['done_by']] ?? null; ?>
      <div class="trow done" data-id="<?= (int) $s['id'] ?>">
        <form method="post" action="shop.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="tcheck" aria-label="아직 안 샀어요"></button></form>
        <div class="tbody"><span class="tt"><?= h($s['name']) ?></span>
          <span class="tm"><?php if ($by): ?><span class="by">✓ <?= h($by['name']) ?><?= $s['done_at'] ? ' ' . date('n/j H:i', strtotime($s['done_at'])) : '' ?></span><?php endif; ?></span></div>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" style="margin:0 6px 12px"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn small">산 것 목록에서 지우기</button></form>
<?php endif; ?>
<?php page_end('family');
