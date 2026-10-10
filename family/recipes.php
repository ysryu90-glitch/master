<?php
// 가족 › 식단 › 메뉴 보관함: 우리집 저녁 메뉴 · 재료 · 레시피 링크 (식단 「빈 날 채우기」가 여기서 고름)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/table.php';

$me = require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post('id');
    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM recipes WHERE id = ?')->execute([$id]);
        flash('메뉴를 뺐어요.');
        redirect('recipes.php');
    }
    if (post('action') === 'plan') {
        $st = db()->prepare('SELECT * FROM recipes WHERE id = ?');
        $st->execute([$id]);
        $day = valid_day(post('day'));
        if ($r = $st->fetch()) db()->prepare('REPLACE INTO dinner_plans (day, dish, ingredients, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$day, $r['name'], $r['ingredients'], '', $me['id']]);
        flash(day_label($day) . ' 저녁을 「' . ($r['name'] ?? '') . '」(으)로 정했어요.');
        redirect('table.php?day=' . $day . '#plan');
    }
    $name = mb_substr(trim(post('name')), 0, 100);
    $url = trim(post('url'));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
    if ($name !== '') {
        $vals = [$name, mb_substr(trim(post('ingredients')), 0, 500), mb_substr(trim(post('note')), 0, 300), mb_substr($url, 0, 500), post('kid_ok') ? 1 : 0];
        try {
            if ($id) db()->prepare('UPDATE recipes SET name = ?, ingredients = ?, note = ?, url = ?, kid_ok = ?, updated_at = NOW() WHERE id = ?')->execute([...$vals, $id]);
            else db()->prepare('INSERT INTO recipes (name, ingredients, note, url, kid_ok, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')->execute($vals);
            flash('저장했어요.');
        } catch (PDOException $e) {
            flash('같은 이름의 메뉴가 이미 있어요.');
        }
    }
    redirect('recipes.php');
}

$recipes = recipes_all();
$kidName = members('child')[0]['name'] ?? '아이';
$q = trim((string) ($_GET['q'] ?? ''));
$filter = $_GET['f'] ?? '';
$list = array_values(array_filter($recipes, fn($r) => ($q === '' || mb_stripos($r['name'] . ' ' . $r['ingredients'], $q) !== false) && ($filter !== 'kid' || $r['kid_like']) && ($filter !== 'old' || !$r['last_day'] || $r['last_day'] < date('Y-m-d', strtotime('-30 day')))));
usort($list, fn($a, $b) => [(int) $b['times'], $a['name']] <=> [(int) $a['times'], $b['name']]);
$edit = null;
foreach ($recipes as $r) if ((int) $r['id'] === (int) ($_GET['edit'] ?? 0)) $edit = $r;

page_start('메뉴 보관함', 'family', ['back' => 'table.php#plan']);
?>
<form class="searchbar" method="get" style="margin-bottom:10px"><input type="search" name="q" value="<?= h($q) ?>" placeholder="메뉴 · 재료로 찾기 (예: 감자)" aria-label="찾기"><?php if ($filter): ?><input type="hidden" name="f" value="<?= h($filter) ?>"><?php endif; ?></form>
<nav class="chips" style="margin-bottom:12px">
  <a class="chip<?= $filter === '' ? ' on' : '' ?>" href="recipes.php">전체 <?= count($recipes) ?></a>
  <a class="chip<?= $filter === 'kid' ? ' on' : '' ?>" href="recipes.php?f=kid">😋 <?= h($kidName) ?> 최애</a>
  <a class="chip<?= $filter === 'old' ? ' on' : '' ?>" href="recipes.php?f=old">🕰 한 달 넘게 안 먹은</a>
</nav>

<div class="card rows">
  <?php foreach ($list as $r): ?>
    <details class="rcp">
      <summary class="row"><span class="ic"><?= $r['kid_like'] ? '😋' : '🍲' ?></span>
        <span class="grow"><span class="t"><?= h($r['name']) ?></span><span class="s"><?= $r['last_day'] ? '마지막 ' . h(day_label($r['last_day'])) . ' · ' . (int) $r['times'] . '번' : '아직 안 먹어 봄' ?><?= $r['kid_good'] ? ' · ' . h($kidName) . ' 😋 ' . (int) $r['kid_good'] : '' ?></span></span><span class="chev">›</span></summary>
      <div class="rcp-b">
        <?php if ($r['ingredients'] !== ''): ?><p class="small"><b>재료</b> <?= h($r['ingredients']) ?></p><?php endif; ?>
        <?php if ($r['note'] !== ''): ?><p class="small">📝 <?= h($r['note']) ?></p><?php endif; ?>
        <?php if ($r['url'] !== ''): ?><p class="small"><a href="<?= h($r['url']) ?>" target="_blank" rel="noopener">🔗 레시피 보기</a></p><?php endif; ?>
        <div class="btn-row">
          <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <select name="day" style="width:auto;margin:0;padding:6px 10px"><?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("+$i day")); ?><option value="<?= $d ?>"><?= $i === 0 ? '오늘' : ($i === 1 ? '내일' : date('n/j', strtotime($d)) . ' ' . weekday_short($d)) ?></option><?php endfor; ?></select>
            <button class="btn small primary">저녁으로</button></form>
          <a class="btn small" href="recipes.php?edit=<?= (int) $r['id'] ?>#form">✏️ 고치기</a>
        </div>
      </div>
    </details>
  <?php endforeach; ?>
  <?php if (!$list): ?><p class="small muted" style="padding:14px 18px;margin:0">찾는 메뉴가 없어요.</p><?php endif; ?>
</div>

<section class="card" id="form">
  <h2><?= $edit ? '✏️ ' . h($edit['name']) . ' 고치기' : '＋ 메뉴 넣기' ?></h2>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <label>메뉴 (여러 개면 · 로)<input name="name" required maxlength="100" value="<?= h($edit['name'] ?? '') ?>" placeholder="예: 된장찌개 · 계란말이"></label>
    <label>재료 (쉼표로)<input name="ingredients" maxlength="500" value="<?= h($edit['ingredients'] ?? '') ?>" placeholder="예: 두부, 애호박, 감자"></label>
    <label>메모<input name="note" maxlength="300" value="<?= h($edit['note'] ?? '') ?>" placeholder="예: 아이 몫은 고추 넣기 전에 덜기"></label>
    <label>레시피 링크 (유튜브 · 만개의레시피 등)<input name="url" type="url" maxlength="500" value="<?= h($edit['url'] ?? '') ?>" placeholder="https://"></label>
    <label style="display:flex;gap:8px;align-items:center;color:var(--text)"><input type="checkbox" name="kid_ok" value="1" style="width:auto;margin:0" <?= !empty($edit['kid_ok']) ? 'checked' : '' ?>> 😋 <?= h($kidName) ?>도 잘 먹어요 (빈 날 채울 때 먼저 골라요)</label>
    <button class="btn primary wide">저장</button>
  </form>
  <?php if ($edit): ?><form method="post" data-confirm="「<?= h($edit['name']) ?>」을(를) 보관함에서 뺄까요?" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn small danger">🗑 보관함에서 빼기</button></form><?php endif; ?>
</section>
<?php page_end('family');
