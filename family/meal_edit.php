<?php
// 한 끼 기록 · 수정: 음식 여러 개, 양(인분), 영양 정보, 사진, 메모
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/foods.php';

$me = require_login();
check_csrf();

$meal = null;
if ($id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0)) {
    $stmt = db()->prepare('SELECT id, member_id, day, eaten_at, meal_type, memo, photo IS NOT NULL AS has_photo FROM meals WHERE id = ?');
    $stmt->execute([$id]);
    $meal = $stmt->fetch() ?: null;
    if (!$meal) redirect('meals.php');
}
$whoId = (int) ($meal['member_id'] ?? $_GET['m'] ?? $_POST['m'] ?? $me['id']);
$who = member($whoId) ?? $me;
$day = valid_day($meal['day'] ?? $_GET['day'] ?? $_POST['day'] ?? null);
$type = $meal['meal_type'] ?? (isset(MEAL_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : meal_type_for_now());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = json_decode((string) post('items_json'), true) ?: [];
    $clean = [];
    foreach ($items as $it) {
        $name = mb_substr(trim((string) ($it['name'] ?? '')), 0, 100);
        if ($name === '') continue;
        $row = ['name' => $name, 'amount' => mb_substr(trim((string) ($it['amount'] ?? '1인분')), 0, 40) ?: '1인분',
            'servings' => max(0.1, min(20, (float) ($it['servings'] ?? 1)))];
        foreach (['kcal', 'carbs', 'protein', 'fat', 'sodium'] as $k) $row[$k] = max(0, min(99999, (float) ($it[$k] ?? 0)));
        $clean[] = $row;
    }
    $type = isset(MEAL_TYPES[post('meal_type')]) ? post('meal_type') : $type;
    $day = valid_day(post('day'));
    $time = preg_match('/^\d{2}:\d{2}$/', post('time')) ? post('time') . ':00' : null;
    $memo = mb_substr((string) post('memo'), 0, 500);

    $photo = null;
    if (preg_match('#^data:image/jpeg;base64,(.+)$#', (string) post('photo_data'), $m)) {
        $bytes = base64_decode($m[1], true);
        if ($bytes !== false && strlen($bytes) < 4 * 1024 * 1024 && substr($bytes, 0, 2) === "\xFF\xD8") $photo = $bytes;
    }

    if ($clean) {
        $pdo = db();
        $pdo->beginTransaction();
        if ($meal) {
            $pdo->prepare('UPDATE meals SET day = ?, eaten_at = ?, meal_type = ?, memo = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$day, $time, $type, $memo, $meal['id']]);
            $mealId = (int) $meal['id'];
            $pdo->prepare('DELETE FROM meal_items WHERE meal_id = ?')->execute([$mealId]);
        } else {
            $pdo->prepare('INSERT INTO meals (member_id, day, eaten_at, meal_type, memo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
                ->execute([$who['id'], $day, $time, $type, $memo]);
            $mealId = (int) $pdo->lastInsertId();
        }
        if ($photo !== null) {
            $stmt = $pdo->prepare('UPDATE meals SET photo = ? WHERE id = ?');
            $stmt->bindValue(1, $photo, PDO::PARAM_LOB);
            $stmt->bindValue(2, $mealId, PDO::PARAM_INT);
            $stmt->execute();
        } elseif (post('remove_photo')) {
            $pdo->prepare('UPDATE meals SET photo = NULL WHERE id = ?')->execute([$mealId]);
        }
        $insert = $pdo->prepare('INSERT INTO meal_items (meal_id, name, amount, servings, kcal, carbs, protein, fat, sodium, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($clean as $i => $it) {
            $insert->execute([$mealId, $it['name'], $it['amount'], $it['servings'], $it['kcal'], $it['carbs'], $it['protein'], $it['fat'], $it['sodium'], $i]);
            remember_food($it);
        }
        $pdo->commit();
        flash(MEAL_TYPES[$type][0] . ' 기록을 저장했어요.');
        redirect("meals.php?m={$who['id']}&day=$day");
    }
}

$items = $meal ? array_map(fn($i) => [
    'name' => $i['name'], 'amount' => $i['amount'], 'servings' => (float) $i['servings'], 'kcal' => (float) $i['kcal'],
    'carbs' => (float) $i['carbs'], 'protein' => (float) $i['protein'], 'fat' => (float) $i['fat'], 'sodium' => (float) $i['sodium'],
], meal_items((int) $meal['id'])) : [];

$foods = food_choices();
$favorites = array_slice(array_filter($foods, fn($f) => $f['mine']), 0, 12, true);

// 최근 같은 끼니 (그대로 다시 넣기)
$stmt = db()->prepare('SELECT id, day FROM meals WHERE member_id = ? AND meal_type = ? AND id <> ? ORDER BY day DESC, id DESC LIMIT 4');
$stmt->execute([$who['id'], $type, (int) ($meal['id'] ?? 0)]);
$recent = [];
foreach ($stmt->fetchAll() as $r) {
    $its = meal_items((int) $r['id']);
    if (!$its) continue;
    $recent[] = ['label' => date('n/j', strtotime($r['day'])) . ' ' . mb_strimwidth(implode(', ', array_column($its, 'name')), 0, 24, '…'),
        'items' => array_map(fn($i) => ['name' => $i['name'], 'amount' => $i['amount'], 'servings' => (float) $i['servings'], 'kcal' => (float) $i['kcal'],
            'carbs' => (float) $i['carbs'], 'protein' => (float) $i['protein'], 'fat' => (float) $i['fat'], 'sodium' => (float) $i['sodium']], $its)];
}

page_start($meal ? '식단 수정' : '식단 기록', 'meals');
?>
<form method="post" id="meal-form" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($meal['id'] ?? 0) ?>">
  <input type="hidden" name="m" value="<?= (int) $who['id'] ?>">
  <input type="hidden" name="items_json" value="">
  <input type="hidden" name="photo_data" value="">

  <p class="muted small"><?= h($who['emoji'] . ' ' . $who['name']) ?>의 식단</p>
  <div class="segmented">
    <?php foreach (MEAL_TYPES as $key => [$label, $icon]): ?>
      <label><input type="radio" name="meal_type" value="<?= $key ?>" <?= $key === $type ? 'checked' : '' ?>><span><?= $label ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="grid2">
    <label>날짜<input type="date" name="day" value="<?= $day ?>" max="<?= today() ?>"></label>
    <label>시간<input type="time" name="time" value="<?= h(!empty($meal['eaten_at']) ? substr($meal['eaten_at'], 0, 5) : date('H:i')) ?>"></label>
  </div>

  <?php if ($favorites || $recent): ?>
  <section class="card">
    <?php if ($recent): ?>
      <h3>지난 <?= MEAL_TYPES[$type][0] ?> 그대로</h3>
      <div class="chips" style="margin-bottom:10px">
        <?php foreach ($recent as $r): ?><button type="button" class="chip orange" data-copy='<?= h(json_encode($r['items'], JSON_UNESCAPED_UNICODE)) ?>'>↺ <?= h($r['label']) ?></button><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($favorites): ?>
      <h3>우리집 자주 먹는 음식</h3>
      <div class="chips">
        <?php foreach ($favorites as $i => $f): ?><button type="button" class="chip" data-food="<?= $i ?>">+ <?= h($f['name']) ?></button><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <h3>먹은 음식</h3>
  <div id="items"></div>
  <button type="button" class="add-meal" id="add-item" style="margin-bottom:14px">+ 음식 추가</button>

  <section class="card">
    <h3>사진 (선택)</h3>
    <img id="photo-preview" class="photo-preview <?= !empty($meal['has_photo']) ? '' : 'hidden' ?>" src="<?= !empty($meal['has_photo']) ? 'photo.php?id=' . (int) $meal['id'] : '' ?>" alt="">
    <label class="btn" style="display:inline-flex;color:var(--text);font-size:15px">📷 사진 찍기 · 고르기
      <input type="file" accept="image/*" data-resize="photo_data" data-preview="#photo-preview" style="display:none">
    </label>
    <?php if (!empty($meal['has_photo'])): ?><label style="display:inline-flex;gap:6px;align-items:center;margin-left:10px"><input type="checkbox" name="remove_photo" value="1" style="width:auto;display:inline;margin:0"> 사진 지우기</label><?php endif; ?>
  </section>

  <label>메모<textarea name="memo" rows="2" placeholder="예: 회사 구내식당, 반 공기만 먹음"><?= h($meal['memo'] ?? '') ?></textarea></label>

  <div class="sticky-save">
    <div class="sum" id="sum"></div>
    <button class="btn primary">저장</button>
  </div>
</form>

<template id="item-template">
  <div class="item-row">
    <div class="top">
      <div class="suggest">
        <input class="f-name" placeholder="음식 이름 (예: 김치찌개)" autocomplete="off">
        <div class="suggest-list hidden"></div>
      </div>
      <button type="button" class="remove" aria-label="삭제">×</button>
    </div>
    <div class="stepper">
      <button type="button" data-step="-0.5">−</button>
      <input class="f-servings" inputmode="decimal" value="1">
      <button type="button" data-step="0.5">+</button>
      <input class="f-amount amount" placeholder="1인분">
    </div>
    <div class="nut-grid">
      <label>kcal<input class="f-kcal" inputmode="decimal"></label>
      <label>탄수 g<input class="f-carbs" inputmode="decimal"></label>
      <label>단백 g<input class="f-protein" inputmode="decimal"></label>
      <label>지방 g<input class="f-fat" inputmode="decimal"></label>
      <label>나트륨<input class="f-sodium" inputmode="decimal"></label>
    </div>
    <div class="item-total"></div>
  </div>
</template>
<script type="application/json" id="foods-data"><?= json_encode($foods, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script type="application/json" id="items-data"><?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<p class="small muted">영양 정보는 1인분 기준이고, 양(인분)을 곱해 합계를 내요. 기본 목록은 대략값이라 포장지에 적힌 값이 있으면 고쳐서 저장해 주세요. 한 번 저장한 음식은 다음부터 우리집 음식으로 먼저 나와요.</p>
<?php page_end('meals');
