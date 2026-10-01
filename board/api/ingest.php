<?php
// 아이폰 앱이 보내는 요약 저장 (POST, 헤더 X-Board-Token)
require __DIR__ . '/db.php';

$config = board_config();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    board_fail(405, 'POST만 받아요.');
}
$token = $_SERVER['HTTP_X_BOARD_TOKEN'] ?? '';
if (empty($config['token']) || !hash_equals((string) $config['token'], $token)) {
    board_fail(401, '토큰이 맞지 않아요.');
}

$raw = file_get_contents('php://input');
if (strlen($raw) > 2 * 1024 * 1024) {
    board_fail(413, '데이터가 너무 커요.');
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    board_fail(400, 'JSON 형식이 아니에요.');
}
$member = (string) ($data['member'] ?? '');
if (!preg_match('/^[a-z]{1,16}$/', $member)) {
    board_fail(400, 'member 값이 올바르지 않아요.');
}

$pdo = board_db($config);
$now = date('Y-m-d H:i:s');
$pdo->beginTransaction();

// 1. 전광판용 최신 요약 (식단 상세는 전광판에 필요 없으니 빼고 저장)
$state = $data;
unset($state['meals']);
$pdo->prepare('REPLACE INTO member_state (member_id, name, payload, updated_at) VALUES (?, ?, ?, ?)')
    ->execute([$member, (string) ($data['name'] ?? $member), json_encode($state, JSON_UNESCAPED_UNICODE), $now]);

// 2. 하루 건강 요약 (날마다 한 줄, 같은 날은 덮어쓰기)
if (!empty($data['health']) && is_array($data['health'])) {
    $h = $data['health'];
    $pdo->prepare('REPLACE INTO daily_health
        (member_id, day, readiness, readiness_level, steps, sleep_hours, water_ml, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $member, $h['day'] ?? date('Y-m-d'),
            $h['readiness'] ?? null, $h['readinessLevel'] ?? null,
            isset($h['steps']) ? (int) $h['steps'] : null,
            $h['sleepHours'] ?? null,
            isset($h['water']) ? (int) $h['water'] : null,
            $now,
        ]);
}

// 3. 저녁 계획
foreach (($data['dinners'] ?? []) as $dinner) {
    if (empty($dinner['date']) || empty($dinner['dish'])) continue;
    $pdo->prepare('REPLACE INTO dinner_plan (day, dish, ingredients, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$dinner['date'], $dinner['dish'], implode(', ', $dinner['ingredients'] ?? []), $member, $now]);
}

// 4. 식단 기록: 보낸 기간(mealsSince 이후)은 앱 기록과 똑같이 맞춘다 (앱에서 지운 기록은 DB에서도 삭제)
if (isset($data['meals']) && is_array($data['meals']) && !empty($data['mealsSince'])) {
    $since = date('Y-m-d H:i:s', strtotime($data['mealsSince']));
    $ids = [];
    $upsert = $pdo->prepare('REPLACE INTO meal_log
        (id, member_id, eaten_at, meal_type, title, calories, carbs_g, protein_g, fat_g, sodium_mg, items, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($data['meals'] as $meal) {
        if (empty($meal['id']) || !preg_match('/^[0-9A-Fa-f-]{36}$/', $meal['id'])) continue;
        $ids[] = $meal['id'];
        $upsert->execute([
            $meal['id'], $member, date('Y-m-d H:i:s', strtotime($meal['eatenAt'])),
            $meal['type'], mb_substr($meal['title'], 0, 200),
            (int) $meal['calories'], $meal['carbs'], $meal['protein'], $meal['fat'], (int) $meal['sodium'],
            implode(', ', $meal['items'] ?? []), $now,
        ]);
    }
    $sql = 'DELETE FROM meal_log WHERE member_id = ? AND eaten_at >= ?';
    $params = [$member, $since];
    if ($ids) {
        $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    }
    $pdo->prepare($sql)->execute($params);
}

$pdo->commit();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'savedAt' => $now], JSON_UNESCAPED_UNICODE);
