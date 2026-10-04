<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/foods.php';

$me = require_login();
check_csrf();

$all = members();
$whoId = (int) ($_GET['m'] ?? $_POST['m'] ?? $me['id']);
$who = null;
foreach ($all as $m) if ((int) $m['id'] === $whoId) $who = $m;
$who ??= $me;
$day = valid_day($_GET['day'] ?? $_POST['day'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mealId = (int) post('meal');
    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM meal_items WHERE meal_id = ?')->execute([$mealId]);
        db()->prepare('DELETE FROM meals WHERE id = ?')->execute([$mealId]);
        flash('기록을 지웠어요.');
    } elseif (post('action') === 'again') {
        // 같은 음식을 오늘 같은 끼니로 다시 기록 (사진 제외)
        $stmt = db()->prepare('SELECT * FROM meals WHERE id = ?');
        $stmt->execute([$mealId]);
        if ($meal = $stmt->fetch()) {
            db()->prepare('INSERT INTO meals (member_id, day, eaten_at, meal_type, created_at, updated_at) VALUES (?, CURDATE(), CURTIME(), ?, NOW(), NOW())')
                ->execute([$meal['member_id'], $meal['meal_type']]);
            $newId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO meal_items (meal_id, name, amount, servings, kcal, carbs, protein, fat, sodium, sort)
                SELECT ?, name, amount, servings, kcal, carbs, protein, fat, sodium, sort FROM meal_items WHERE meal_id = ?')->execute([$newId, $mealId]);
            flash('오늘 ' . MEAL_TYPES[$meal['meal_type']][0] . '으로 다시 기록했어요.');
            $day = today();
        }
    }
    redirect("meals.php?m={$who['id']}&day=$day");
}

$stmt = db()->prepare('SELECT id, day, eaten_at, meal_type, memo, photo IS NOT NULL AS has_photo FROM meals WHERE member_id = ? AND day = ? ORDER BY eaten_at, id');
$stmt->execute([$who['id'], $day]);
$meals = $stmt->fetchAll();
$byType = [];
$dayTotal = ['kcal' => 0, 'carbs' => 0, 'protein' => 0, 'fat' => 0, 'sodium' => 0];
foreach ($meals as &$meal) {
    $meal['items'] = meal_items((int) $meal['id']);
    $meal['total'] = items_total($meal['items']);
    foreach ($dayTotal as $k => $_) $dayTotal[$k] += $meal['total'][$k];
    $byType[$meal['meal_type']][] = $meal;
}
unset($meal);

// 최근 7일 칼로리
$stmt = db()->prepare('SELECT m.day, SUM(i.kcal * i.servings) kcal, SUM(i.protein * i.servings) protein FROM meals m JOIN meal_items i ON i.meal_id = m.id
    WHERE m.member_id = ? AND m.day > DATE_SUB(?, INTERVAL 7 DAY) AND m.day <= ? GROUP BY m.day');
$stmt->execute([$who['id'], $day, $day]);
$week = $stmt->fetchAll(PDO::FETCH_UNIQUE);
$weekPoints = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("$day -$i day"));
    $weekPoints[] = ['l' => weekday_short($d), 'v' => isset($week[$d]) ? round((float) $week[$d]['kcal']) : null];
}

$prev = date('Y-m-d', strtotime("$day -1 day"));
$next = date('Y-m-d', strtotime("$day +1 day"));
$base = "meals.php?m={$who['id']}";
$carbsTarget = round($who['kcal_target'] * 0.55 / 4);
$fatTarget = round($who['kcal_target'] * 0.25 / 9);

page_start('식단 기록', 'meals');
?>
<div class="segmented">
  <?php foreach ($all as $m): ?>
    <label onclick="location.href='meals.php?m=<?= (int) $m['id'] ?>&day=<?= $day ?>'"><input type="radio" <?= (int) $m['id'] === (int) $who['id'] ? 'checked' : '' ?>><span><?= h($m['emoji'] . ' ' . $m['name']) ?></span></label>
  <?php endforeach; ?>
</div>

<div class="daynav">
  <a href="<?= $base ?>&day=<?= $prev ?>">‹</a>
  <span class="label"><?= h(day_label($day)) ?></span>
  <a href="<?= $base ?>&day=<?= $next ?>" class="<?= $day >= today() ? 'disabled' : '' ?>">›</a>
</div>

<section class="card">
  <div class="card-head"><h2><?= num($dayTotal['kcal']) ?> <span class="muted small">/ <?= num($who['kcal_target']) ?>kcal</span></h2>
    <span class="muted small">남은 <?= num(max(0, $who['kcal_target'] - $dayTotal['kcal'])) ?>kcal</span></div>
  <div class="meter orange"><i style="width:<?= min(100, $dayTotal['kcal'] / max(1, $who['kcal_target']) * 100) ?>%"></i></div>
  <div class="macro"><span>탄수화물</span><div class="meter"><i style="width:<?= min(100, $dayTotal['carbs'] / max(1, $carbsTarget) * 100) ?>%"></i></div><span class="n"><?= num($dayTotal['carbs']) ?> / <?= $carbsTarget ?>g</span></div>
  <div class="macro"><span>단백질</span><div class="meter blue"><i style="width:<?= min(100, $dayTotal['protein'] / max(1, $who['protein_target']) * 100) ?>%"></i></div><span class="n"><?= num($dayTotal['protein']) ?> / <?= (int) $who['protein_target'] ?>g</span></div>
  <div class="macro"><span>지방</span><div class="meter orange"><i style="width:<?= min(100, $dayTotal['fat'] / max(1, $fatTarget) * 100) ?>%"></i></div><span class="n"><?= num($dayTotal['fat']) ?> / <?= $fatTarget ?>g</span></div>
  <div class="macro"><span>나트륨</span><div class="meter <?= $dayTotal['sodium'] > 2000 ? 'orange' : '' ?>"><i style="width:<?= min(100, $dayTotal['sodium'] / 2000 * 100) ?>%"></i></div><span class="n"><?= num($dayTotal['sodium']) ?> / 2000mg</span></div>
</section>

<?php foreach (MEAL_TYPES as $type => [$label, $icon]):
    $list = $byType[$type] ?? [];
    $sum = array_sum(array_map(fn($m) => $m['total']['kcal'], $list));
    if (!$list && in_array($type, ['snack', 'late'], true)) continue; ?>
<section class="card meal-section">
  <div class="meal-head"><span class="t"><?= $icon ?> <?= $label ?></span><?php if ($list): ?><span class="kcal"><?= num($sum) ?>kcal</span><?php endif; ?></div>
  <?php foreach ($list as $meal): ?>
    <div class="meal-entry">
      <?php if ($meal['has_photo']): ?><a href="meal_edit.php?id=<?= (int) $meal['id'] ?>"><img src="photo.php?id=<?= (int) $meal['id'] ?>" alt="" loading="lazy"></a><?php endif; ?>
      <div class="grow">
        <a href="meal_edit.php?id=<?= (int) $meal['id'] ?>" style="color:inherit">
          <div class="names"><?= h(implode(', ', array_map(fn($i) => $i['name'] . ((float) $i['servings'] != 1 ? ' ×' . rtrim(rtrim((string) $i['servings'], '0'), '.') : ''), $meal['items']))) ?></div>
          <div class="nut"><?= $meal['eaten_at'] ? substr($meal['eaten_at'], 0, 5) . ' · ' : '' ?><?= num($meal['total']['kcal']) ?>kcal · 탄 <?= num($meal['total']['carbs']) ?> · 단 <?= num($meal['total']['protein']) ?> · 지 <?= num($meal['total']['fat']) ?></div>
          <?php if ($meal['memo']): ?><div class="nut">📝 <?= h($meal['memo']) ?></div><?php endif; ?>
        </a>
        <div class="btn-row" style="margin-top:6px">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="meal" value="<?= (int) $meal['id'] ?>"><input type="hidden" name="m" value="<?= (int) $who['id'] ?>"><input type="hidden" name="day" value="<?= $day ?>">
            <button class="btn small" name="action" value="again">↺ 오늘 또 먹기</button></form>
          <form method="post" data-confirm="이 기록을 지울까요?"><?= csrf_field() ?><input type="hidden" name="meal" value="<?= (int) $meal['id'] ?>"><input type="hidden" name="m" value="<?= (int) $who['id'] ?>"><input type="hidden" name="day" value="<?= $day ?>">
            <button class="btn small danger" name="action" value="delete">삭제</button></form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <a href="meal_edit.php?m=<?= (int) $who['id'] ?>&day=<?= $day ?>&type=<?= $type ?>"><button class="add-meal" type="button">+ <?= $label ?> 기록</button></a>
</section>
<?php endforeach; ?>
<div class="btn-row" style="margin-bottom:14px">
  <a class="btn small" href="meal_edit.php?m=<?= (int) $who['id'] ?>&day=<?= $day ?>&type=snack">🍪 간식 추가</a>
  <a class="btn small" href="meal_edit.php?m=<?= (int) $who['id'] ?>&day=<?= $day ?>&type=late">🌙 야식 추가</a>
</div>

<section class="card">
  <div class="card-head"><h2>최근 7일 칼로리</h2></div>
  <svg class="chart" data-chart='<?= h(json_encode(['type' => 'bar', 'points' => $weekPoints, 'color' => '#f97316', 'min' => 0, 'goal' => (int) $who['kcal_target']], JSON_UNESCAPED_UNICODE)) ?>'></svg>
</section>
<?php page_end('meals');
