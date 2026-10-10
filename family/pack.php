<?php
// 가족 › 장보기 옆 「챙길 것」: 여행 짐 · 나들이 준비물처럼 다시 쓰는 체크리스트 (다 챙기면 「다시 쓰기」로 체크만 풀기)
require __DIR__ . '/lib/bootstrap.php';

$me = require_login();
check_csrf();
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

const PACK_TEMPLATES = [
    '🧺 나들이' => ['물 · 물병', '간식', '물티슈', '손수건', '모자', '선크림', '여벌 옷', '돗자리', '보조배터리'],
    '🧳 1박 여행' => ['칫솔 · 치약', '잠옷', '속옷 · 양말', '여벌 옷', '충전기', '하린 애착 인형', '상비약 · 해열제', '체온계', '물티슈', '세면도구'],
    '🏕 캠핑' => ['텐트', '침낭', '랜턴', '버너 · 가스', '코펠 · 식기', '아이스박스', '모기 기피제', '장작', '두꺼운 옷'],
    '🏊 물놀이' => ['수영복', '래시가드', '수경', '튜브', '아쿠아슈즈', '큰 수건', '비닐봉지', '선크림'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $lid = (int) post('list_id');
    switch (post('action')) {
        case 'new':
            $tpl = post('template');
            $title = mb_substr(trim(post('title')), 0, 40) ?: preg_replace('/^\S+\s/u', '', $tpl);
            $emoji = isset(PACK_TEMPLATES[$tpl]) ? strtok($tpl, ' ') : '🎒';
            if ($title === '') redirect('pack.php');
            $pdo->prepare('INSERT INTO packlists (title, emoji, created_at) VALUES (?, ?, NOW())')->execute([$title, $emoji]);
            $lid = (int) $pdo->lastInsertId();
            foreach (PACK_TEMPLATES[$tpl] ?? [] as $i => $n) $pdo->prepare('INSERT INTO packitems (list_id, name, sort) VALUES (?, ?, ?)')->execute([$lid, $n, $i]);
            redirect('pack.php?l=' . $lid);
        case 'add':
            foreach (preg_split('/[,，\n]+/u', (string) post('names')) as $n) {
                if (($n = trim($n)) !== '') $pdo->prepare('INSERT INTO packitems (list_id, name, sort) VALUES (?, ?, 999)')->execute([$lid, mb_substr($n, 0, 60)]);
            }
            break;
        case 'toggle':
            $pdo->prepare('UPDATE packitems SET done = 1 - done WHERE id = ?')->execute([(int) post('id')]);
            if ($ajax) json_out(['ok' => true]);
            break;
        case 'del':
            $pdo->prepare('DELETE FROM packitems WHERE id = ?')->execute([(int) post('id')]);
            break;
        case 'reset':
            $pdo->prepare('UPDATE packitems SET done = 0 WHERE list_id = ?')->execute([$lid]);
            flash('체크를 모두 풀었어요. 다음에 또 쓰세요.');
            break;
        case 'drop':
            $pdo->prepare('DELETE FROM packitems WHERE list_id = ?')->execute([$lid]);
            $pdo->prepare('DELETE FROM packlists WHERE id = ?')->execute([$lid]);
            redirect('pack.php');
    }
    redirect('pack.php?l=' . $lid);
}

$lists = db()->query('SELECT l.*, (SELECT COUNT(*) FROM packitems i WHERE i.list_id = l.id) n, (SELECT COUNT(*) FROM packitems i WHERE i.list_id = l.id AND i.done = 1) d FROM packlists l ORDER BY l.id DESC')->fetchAll();
$cur = null;
foreach ($lists as $l) if ((int) $l['id'] === (int) ($_GET['l'] ?? 0)) $cur = $l;

page_start('챙길 것', 'family');
?>
<div class="segmented dcat" style="margin-bottom:14px"><a href="shop.php">🛒 장보기</a><a href="pack.php" class="on">🎒 챙길 것</a></div>

<?php if ($cur):
    $st = db()->prepare('SELECT * FROM packitems WHERE list_id = ? ORDER BY done, sort, id');
    $st->execute([$cur['id']]);
    $items = $st->fetchAll();
    $left = array_filter($items, fn($i) => !$i['done']); ?>
  <p style="margin:0 4px 10px"><a href="pack.php" class="small">‹ 모든 목록</a></p>
  <section class="card">
    <div class="card-head"><h2><?= h($cur['emoji']) ?> <?= h($cur['title']) ?></h2><span class="small muted"><?= count($items) - count($left) ?> / <?= count($items) ?></span></div>
    <div class="meter" style="height:6px;margin:0 0 12px"><i style="width:<?= $items ? (count($items) - count($left)) / count($items) * 100 : 0 ?>%"></i></div>
    <form method="post" class="tadd" style="margin:0 0 6px"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="list_id" value="<?= (int) $cur['id'] ?>">
      <input name="names" placeholder="더 넣기 · 쉼표로 여러 개" autocomplete="off" required><button class="tadd-btn" aria-label="추가"><?= nav_icon('plus') ?></button></form>
  </section>
  <div class="card tlist">
    <?php foreach ($items as $i): ?>
      <div class="trow<?= $i['done'] ? ' done' : '' ?>">
        <form method="post" action="pack.php" class="tcheck-f"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="list_id" value="<?= (int) $cur['id'] ?>"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="tcheck" aria-label="챙겼어요"></button></form>
        <div class="tbody"><span class="tt"><?= h($i['name']) ?></span></div>
        <form method="post" class="tdel"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="list_id" value="<?= (int) $cur['id'] ?>"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button aria-label="빼기">✕</button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:8px;margin:0 4px 12px">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="list_id" value="<?= (int) $cur['id'] ?>"><button class="btn small">↺ 체크 풀고 다시 쓰기</button></form>
    <form method="post" data-confirm="이 목록을 지울까요?"><?= csrf_field() ?><input type="hidden" name="action" value="drop"><input type="hidden" name="list_id" value="<?= (int) $cur['id'] ?>"><button class="btn small danger">🗑 목록 지우기</button></form>
  </div>
<?php else: ?>
  <?php if ($lists): ?>
    <div class="card rows">
      <?php foreach ($lists as $l): ?>
        <a class="row" href="pack.php?l=<?= (int) $l['id'] ?>"><span class="ic"><?= h($l['emoji']) ?></span>
          <span class="grow"><span class="t"><?= h($l['title']) ?></span><span class="s"><?= (int) $l['n'] ? ((int) $l['d'] === (int) $l['n'] ? '✓ 다 챙김' : (int) $l['d'] . ' / ' . (int) $l['n'] . ' 챙김') : '비어 있음' ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <section class="card tempty"><div class="big">🎒</div><b>매번 챙기는 것을 목록으로</b><p class="small muted">여행 짐 · 나들이 준비물을 한 번 만들어 두면, 다음엔 「체크 풀고 다시 쓰기」로 그대로 써요. 부부가 같이 체크해서 겹치거나 빠지지 않아요.</p></section>
  <?php endif; ?>
  <section class="card">
    <h2>＋ 새 목록</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="new">
      <div class="segmented" style="flex-wrap:wrap">
        <?php $first = true; foreach (array_keys(PACK_TEMPLATES) as $t): ?><label style="flex:1 0 45%"><input type="radio" name="template" value="<?= h($t) ?>"<?= $first ? ' checked' : '' ?>><span><?= h($t) ?></span></label><?php $first = false; endforeach; ?>
        <label style="flex:1 0 45%"><input type="radio" name="template" value=""><span>✏️ 빈 목록</span></label>
      </div>
      <label>이름 (비우면 고른 것 이름)<input name="title" maxlength="40" placeholder="예: 강릉 1박 여행"></label>
      <button class="btn primary wide">만들기</button>
    </form>
  </section>
<?php endif; ?>
<?php page_end('family');
