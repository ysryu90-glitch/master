<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/table.php';
require __DIR__ . '/lib/calendar.php';

$me = require_login();
check_csrf();
$day = valid_day($_GET['day'] ?? $_POST['day'] ?? null);
$back = "table.php?day=$day";

const REACTIONS = ['good' => ['😋', '잘 먹음'], 'some' => ['😐', '조금'], 'no' => ['🙅', '안 먹음']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    switch (post('action')) {
        case 'plan':
            if (post('dish') === '') {
                $pdo->prepare('DELETE FROM dinner_plans WHERE day = ?')->execute([$day]);
            } else {
                $pdo->prepare('REPLACE INTO dinner_plans (day, dish, ingredients, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$day, mb_substr(post('dish'), 0, 200), mb_substr(post('ingredients'), 0, 500), mb_substr(post('note'), 0, 300), $me['id']]);
                recipe_remember(mb_substr(post('dish'), 0, 100), (string) post('ingredients'));
                if (post('add_shopping') && post('ingredients') !== '') {
                    foreach (preg_split('/[,，\n]+/u', post('ingredients')) as $name) {
                        if (($name = trim($name)) !== '') $pdo->prepare('INSERT INTO shopping (name, created_by, created_at) VALUES (?, ?, NOW())')->execute([mb_substr($name, 0, 100), $me['id']]);
                    }
                }
                flash('저녁 메뉴를 저장했어요.');
            }
            break;
        case 'autofill':
            $n = week_autofill(today(), date('Y-m-d', strtotime('+6 day')), (int) $me['id']);
            flash($n ? "빈 {$n}일을 메뉴 보관함에서 채웠어요. 마음에 안 들면 그 날을 눌러 바꾸세요." : '채울 빈 날이 없어요.');
            $back = 'table.php#plan';
            break;
        case 'week_shop':
            $items = week_ingredients(today(), date('Y-m-d', strtotime('+6 day')));
            foreach ($items as $name) $pdo->prepare('INSERT INTO shopping (name, created_by, created_at) VALUES (?, ?, NOW())')->execute([mb_substr($name, 0, 100), $me['id']]);
            flash($items ? '이번 주 재료 ' . count($items) . '가지를 장보기에 넣었어요.' : '새로 넣을 재료가 없어요 (이미 장보기에 있어요).');
            $back = 'table.php#plan';
            break;
        case 'attend':
            if (isset(ATTENDANCE[post('status')]) && member((int) post('member'))) {
                $pdo->prepare('REPLACE INTO dinner_attendance (day, member_id, status, late_time, updated_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$day, (int) post('member'), post('status'), substr(post('late_time'), 0, 5)]);
            }
            break;
        case 'outcome':
            $place = in_array(post('place'), ['home', 'out', 'apart'], true) ? post('place') : 'home';
            $dish = mb_substr(post('dish') ?: (dinner_plan($day)['dish'] ?? ''), 0, 200);
            $pdo->prepare('REPLACE INTO dinner_outcomes (day, together, place, dish, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$day, $place === 'apart' ? 0 : 1, $place, $dish, mb_substr(post('note'), 0, 300), $me['id']]);
            flash('저녁 결과를 기록했어요.');
            break;
        case 'kid':
            if (isset(REACTIONS[post('reaction')]) && post('food') !== '') {
                $pdo->prepare('INSERT INTO kid_reactions (day, member_id, food, reaction, new_food, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$day, (int) post('member'), mb_substr(post('food'), 0, 100), post('reaction'), post('new_food') ? 1 : 0, mb_substr(post('note'), 0, 200), $me['id']]);
            }
            break;
        case 'kid_delete':
            $pdo->prepare('DELETE FROM kid_reactions WHERE id = ?')->execute([(int) post('id')]);
            break;
        case 'shop_add':
            foreach (preg_split('/[,，\n]+/u', post('names')) as $name) {
                if (($name = trim($name)) !== '') $pdo->prepare('INSERT INTO shopping (name, created_by, created_at) VALUES (?, ?, NOW())')->execute([mb_substr($name, 0, 100), $me['id']]);
            }
            $back .= '#shopping';
            break;
        case 'shop_toggle':
            $pdo->prepare('UPDATE shopping SET done_at = IF(done = 0, NOW(), NULL), done = 1 - done WHERE id = ?')->execute([(int) post('id')]);
            $back .= '#shopping';
            break;
        case 'shop_clear':
            $pdo->exec('DELETE FROM shopping WHERE done = 1');
            $back .= '#shopping';
            break;
    }
    redirect($back);
}

calendar_refresh_if_stale();
$today = today();
$weekStart = $today;
$plans = dinner_plans_between($weekStart, date('Y-m-d', strtotime("$weekStart +6 day")));
$plan = dinner_plan($day);
$att = attendance($day);
$outcome = dinner_outcome($day);
$conflicts = dinner_conflicts($day);
$kids = members('child');

$stmt = db()->prepare('SELECT * FROM kid_reactions WHERE day = ? ORDER BY id');
$stmt->execute([$day]);
$reactionsToday = $stmt->fetchAll();

// 아이가 잘 먹는 음식 / 도전 중인 음식 (최근 90일)
$favorites = db()->query("SELECT food, COUNT(*) n FROM kid_reactions WHERE reaction = 'good' AND day > DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY food ORDER BY n DESC LIMIT 8")->fetchAll();
$challenges = db()->query("SELECT food, COUNT(*) tries, SUM(reaction = 'good') good, MAX(day) last FROM kid_reactions
    WHERE day > DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY food HAVING SUM(reaction <> 'good') > 0 ORDER BY last DESC LIMIT 8")->fetchAll();

$together = (int) db()->query("SELECT COUNT(*) FROM dinner_outcomes WHERE together = 1 AND day > DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$shopping = db()->query('SELECT s.* FROM shopping s ORDER BY s.done, s.id DESC')->fetchAll();

page_start('식단', 'family');
$recipes = recipes_all();
$weekIngr = week_ingredients($today, date('Y-m-d', strtotime('+6 day')));
$emptyDays = 7 - count($plans);
?>
<section class="card" id="plan">
  <div class="card-head"><h2>🗓 이번 주 저녁</h2><a class="more" href="recipes.php">메뉴 보관함 <?= count($recipes) ?> ›</a></div>
  <div class="wplan">
    <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$weekStart +$i day")); $p = $plans[$d] ?? null; ?>
      <a class="wp<?= $d === $day ? ' sel' : '' ?>" href="table.php?day=<?= $d ?>#tonight"><span class="d<?= in_array((int) date('w', strtotime($d)), [0, 6], true) ? ' we' : '' ?>"><?= $i === 0 ? '오늘' : ($i === 1 ? '내일' : weekday_short($d)) ?></span><span class="m<?= $p ? '' : ' none' ?>"><?= $p ? h($p['dish']) : '+ 정하기' ?></span></a>
    <?php endfor; ?>
  </div>
  <div class="btn-row" style="margin-top:12px">
    <?php if ($emptyDays > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="autofill"><button class="btn small primary">✨ 빈 <?= $emptyDays ?>일 채우기</button></form><?php endif; ?>
    <?php if ($weekIngr): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="week_shop"><button class="btn small">🛒 재료 <?= count($weekIngr) ?>가지 장보기에</button></form><?php endif; ?>
  </div>
  <?php if ($weekIngr): ?><p class="small muted" style="margin:8px 0 0">재료: <?= h(mb_strimwidth(implode(', ', $weekIngr), 0, 120, '…')) ?></p><?php endif; ?>
</section>

<section class="card tonight" id="tonight">
  <div class="card-head"><h2><?= h(day_label($day)) ?> 저녁 <?= h(dinner_time()) ?></h2></div>
  <div class="dish"><?= $plan ? h($plan['dish']) : '<span class="muted" style="font-size:18px">메뉴를 정해 볼까요?</span>' ?></div>
  <?php if ($plan && $plan['ingredients']): ?><p class="small muted">재료: <?= h($plan['ingredients']) ?></p><?php endif; ?>
  <?php if ($plan && $plan['note']): ?><p class="small muted">📝 <?= h($plan['note']) ?></p><?php endif; ?>
  <?php foreach ($conflicts as $c): ?><p class="small" style="color:var(--orange)">⚠️ <?= h($c) ?></p><?php endforeach; ?>
  <?php usort($recipes, fn($a, $b) => [$b['kid_like'], (int) $b['times']] <=> [$a['kid_like'], (int) $a['times']]); ?>
  <details class="fold" style="margin-top:8px"<?= $plan ? '' : ' open' ?>>
    <summary><?= $plan ? '메뉴 바꾸기' : '메뉴 정하기' ?></summary>
    <form method="post" class="form" style="margin-top:10px">
      <?php if ($recipes): ?>
        <div class="chips scrollx" style="margin:0 -16px 4px;padding:0 16px"><span class="small muted" style="flex:none;align-self:center">보관함:</span><?php foreach (array_slice($recipes, 0, 20) as $rc): ?><button type="button" class="chip" data-ing="<?= h($rc['ingredients']) ?>" onclick="this.form.dish.value=this.dataset.name;this.form.ingredients.value=this.dataset.ing" data-name="<?= h($rc['name']) ?>"><?= $rc['kid_like'] ? '😋 ' : '' ?><?= h($rc['name']) ?></button><?php endforeach; ?></div>
      <?php endif; ?>
      <?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="day" value="<?= $day ?>">
      <label>메뉴 (여러 개면 · 로 구분)<input name="dish" value="<?= h($plan['dish'] ?? '') ?>" placeholder="예: 된장찌개 · 계란말이"></label>
      <label>재료 (쉼표로 구분)<input name="ingredients" value="<?= h($plan['ingredients'] ?? '') ?>" placeholder="예: 두부, 애호박, 계란"></label>
      <label>메모<input name="note" value="<?= h($plan['note'] ?? '') ?>" placeholder="예: 딸 몫은 고추 넣기 전에 덜기"></label>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="add_shopping" value="1" style="width:auto;margin:0"> 재료를 장보기 목록에 추가</label>
      <div class="btn-row"><button class="btn primary">저장</button><?php if ($plan): ?><button class="btn danger" name="dish" value="" onclick="this.form.dish.value=''">메뉴 지우기</button><?php endif; ?></div>
    </form>
  </details>
</section>

<section class="card">
  <h2>누가 함께 먹어요?</h2>
  <?php foreach (members() as $m): $a = $att[(int) $m['id']] ?? null; ?>
    <div class="person">
      <span class="who"><?= h($m['emoji'] . ' ' . $m['name']) ?></span>
      <form method="post" class="chips" style="flex:1;justify-content:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="attend"><input type="hidden" name="day" value="<?= $day ?>">
        <input type="hidden" name="member" value="<?= (int) $m['id'] ?>"><input type="hidden" name="late_time" value="">
        <?php foreach (ATTENDANCE as $key => [$long, $short]): ?>
          <button class="chip <?= $a && $a['status'] === $key ? 'on' : '' ?>" name="status" value="<?= $key ?>"
            <?= $key === 'late' ? 'onclick="var t=prompt(\'몇 시쯤이에요? (예: 20:30)\',\'20:00\'); if(t===null) return false; this.form.late_time.value=t;"' : '' ?>><?= h($short) ?><?= $key === 'late' && $a && $a['status'] === 'late' && $a['late_time'] ? ' ' . h($a['late_time']) : '' ?></button>
        <?php endforeach; ?>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<?php
// 저녁 시간 30분 전부터 (지난 날은 항상) 기록 칸을 펼쳐 둠
$afterDinner = $day < today() || ($day === today() && time() >= strtotime(today() . ' ' . dinner_time()) - 1800);
?>
<section class="card">
  <h2>저녁 어땠어요?</h2>
  <?php if ($outcome): ?>
    <p><b><?= $outcome['place'] === 'out' ? '🍽 외식' : ($outcome['together'] ? '🏠 함께 먹었어요' : '🙅 따로 먹었어요') ?></b> <?= $outcome['dish'] ? '· ' . h($outcome['dish']) : '' ?></p>
    <?php if ($outcome['note']): ?><p class="small muted">📝 <?= h($outcome['note']) ?></p><?php endif; ?>
  <?php endif; ?>
  <details class="fold"<?= $afterDinner && !$outcome ? ' open' : '' ?>>
  <summary><?= $outcome ? '✏️ 고치기' : ($afterDinner ? '기록하기' : '저녁 먹고 나서 기록해요 (' . h(dinner_time()) . ')') ?></summary>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="outcome"><input type="hidden" name="day" value="<?= $day ?>">
    <label>먹은 메뉴<input name="dish" value="<?= h($outcome['dish'] ?? $plan['dish'] ?? '') ?>" placeholder="계획과 다르면 고쳐 주세요"></label>
    <label>메모<input name="note" value="<?= h($outcome['note'] ?? '') ?>" placeholder="예: 딸이 처음으로 김치를 먹었어요"></label>
    <div class="btn-row">
      <button class="btn primary" name="place" value="home">🏠 함께 먹었어요</button>
      <button class="btn orange" name="place" value="out">🍽 외식했어요</button>
      <button class="btn" name="place" value="apart">따로 먹었어요</button>
    </div>
  </form>
  </details>
  <p class="small muted">최근 7일 함께한 저녁 <b><?= $together ?>번</b></p>
</section>

<?php foreach ($kids as $kid): ?>
<section class="card">
  <h2><?= h($kid['emoji'] . ' ' . $kid['name']) ?> 반응</h2>
  <?php foreach (array_filter($reactionsToday, fn($r) => (int) $r['member_id'] === (int) $kid['id']) as $r): ?>
    <div class="check">
      <span style="font-size:22px"><?= REACTIONS[$r['reaction']][0] ?></span>
      <span class="name"><?= h($r['food']) ?><?= $r['new_food'] ? ' <span class="chip blue" style="padding:2px 8px;font-size:12px">⭐ 새 음식</span>' : '' ?><?= $r['note'] ? '<div class="small muted">' . h($r['note']) . '</div>' : '' ?></span>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="kid_delete"><input type="hidden" name="day" value="<?= $day ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn small danger">삭제</button></form>
    </div>
  <?php endforeach; ?>
  <details class="fold"<?= $afterDinner ? ' open' : '' ?>>
  <summary>먹은 음식 반응 남기기</summary>
  <form method="post" class="form" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="kid"><input type="hidden" name="day" value="<?= $day ?>"><input type="hidden" name="member" value="<?= (int) $kid['id'] ?>">
    <label>음식<input name="food" value="<?= h($plan ? preg_split('/\s*[·,]\s*/u', $plan['dish'])[0] : '') ?>" placeholder="예: 브로콜리"></label>
    <label>메모<input name="note" placeholder="예: 케첩이랑 먹으니 잘 먹음"></label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="new_food" value="1" style="width:auto;margin:0"> 처음 먹어 보는 음식 ⭐</label>
    <div class="btn-row">
      <?php foreach (REACTIONS as $key => [$icon, $label]): ?><button class="btn" name="reaction" value="<?= $key ?>"><?= $icon ?> <?= $label ?></button><?php endforeach; ?>
    </div>
  </form>
  </details>
  <?php if ($favorites): ?><h3 style="margin-top:12px">😋 잘 먹는 음식</h3><div class="chips"><?php foreach ($favorites as $f): ?><span class="chip"><?= h($f['food']) ?> <?= (int) $f['n'] ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if ($challenges): ?><h3 style="margin-top:12px">💪 도전 중인 음식</h3><div class="chips"><?php foreach ($challenges as $c): ?><span class="chip orange"><?= h($c['food']) ?> <?= (int) $c['tries'] ?>번째<?= $c['good'] ? ' · 성공 ' . (int) $c['good'] : '' ?></span><?php endforeach; ?></div>
    <p class="small muted" style="margin-top:6px">아이들은 새 음식을 8~15번쯤 만나야 익숙해진대요. 조리법을 바꿔 가며 계속 도전해 봐요.</p><?php endif; ?>
</section>
<?php endforeach; ?>

<?php $shopLeft = array_values(array_filter($shopping, fn($x) => !$x['done'])); ?>
<a class="card shoplink" id="shopping" href="shop.php">
  <span class="ic">🛒</span>
  <span class="grow"><b>장보기 <?= count($shopLeft) ?>개 남음</b><span class="small muted"><?= $shopLeft ? h(mb_strimwidth(implode(', ', array_column($shopLeft, 'name')), 0, 40, '…')) : '살 것이 없어요' ?></span></span>
  <span class="chev">›</span>
</a>
<?php page_end('family');
