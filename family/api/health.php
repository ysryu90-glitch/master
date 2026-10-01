<?php
// 아이폰 '단축어'가 보내는 건강 기록 받기 (POST JSON, 사람마다 다른 토큰)
// 값에 단위나 쉼표가 붙어 와도("52.3 ms", "7,420") 숫자만 읽는다.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/readiness.php';

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$token = (string) ($data['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $token)) json_out(['ok' => false, 'error' => '토큰이 없어요.']);
$stmt = db()->prepare("SELECT * FROM members WHERE shortcut_token = ? AND role = 'adult'");
$stmt->execute([$token]);
$member = $stmt->fetch();
if (!$member) json_out(['ok' => false, 'error' => '토큰이 맞지 않아요. 설정 화면의 토큰을 확인해 주세요.']);

function number_from($value): ?float
{
    if (is_array($value)) $value = reset($value);
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (float) $value;
    if (preg_match('/-?\d[\d,]*(\.\d+)?/', (string) $value, $m)) return (float) str_replace(',', '', $m[0]);
    return null;
}

// 날짜: yyyy-MM-dd, 2026. 10. 1., 2026/10/01 등. 없거나 이상하면 오늘
$day = today();
if (!empty($data['date']) && preg_match('/(\d{4})\D+(\d{1,2})\D+(\d{1,2})/', (string) $data['date'], $m)) {
    $candidate = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $candidate <= today()) $day = $candidate;
}

$values = [
    'hrv' => number_from($data['hrv'] ?? null),
    'rhr' => number_from($data['rhr'] ?? null),
    'resp' => number_from($data['resp'] ?? null),
    'steps' => number_from($data['steps'] ?? null),
    'active_kcal' => number_from($data['active_kcal'] ?? null),
    'exercise_min' => number_from($data['exercise_min'] ?? null),
    'weight' => number_from($data['weight'] ?? null),
];

// 수면: 코어 · 깊은 · 렘 합계. 단축어 버전에 따라 초/분/시간으로 올 수 있어 합계로 단위를 판단한다.
$sleep = ['core_min' => number_from($data['sleep_core'] ?? null), 'deep_min' => number_from($data['sleep_deep'] ?? null), 'rem_min' => number_from($data['sleep_rem'] ?? null)];
$total = number_from($data['sleep_total'] ?? null);
$sum = array_sum(array_filter($sleep, fn($v) => $v !== null)) ?: ($total ?? 0);
$factor = $sum > 1440 ? 1 / 60 : ($sum > 0 && $sum <= 16 ? 60 : 1);
foreach ($sleep as $k => $v) $values[$k] = $v === null ? null : round($v * $factor);
$values['sleep_min'] = $total !== null ? round($total * $factor)
    : (array_filter($sleep, fn($v) => $v !== null) ? round($sum * $factor) : null);

// 0이나 말이 안 되는 값은 비운다 (예: 아직 기록이 없는 항목)
foreach (['hrv' => [5, 300], 'rhr' => [25, 150], 'resp' => [5, 40], 'weight' => [10, 300], 'sleep_min' => [30, 960]] as $k => [$lo, $hi]) {
    if ($values[$k] !== null && ($values[$k] < $lo || $values[$k] > $hi)) $values[$k] = null;
}

$fields = array_keys($values);
$sql = 'INSERT INTO health_days (member_id, day, ' . implode(', ', $fields) . ', updated_at) VALUES (?, ?, '
    . implode(', ', array_fill(0, count($fields), '?')) . ', NOW()) ON DUPLICATE KEY UPDATE '
    . implode(', ', array_map(fn($f) => "$f = COALESCE(VALUES($f), $f)", $fields)) . ', updated_at = NOW()';
db()->prepare($sql)->execute(array_merge([(int) $member['id'], $day], array_values($values)));

db()->prepare('INSERT INTO health_raw (member_id, received_at, body) VALUES (?, NOW(), ?)')
    ->execute([(int) $member['id'], mb_substr($raw ?: json_encode($_POST, JSON_UNESCAPED_UNICODE), 0, 4000)]);
db()->prepare('DELETE FROM health_raw WHERE member_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM health_raw WHERE member_id = ? ORDER BY id DESC LIMIT 30) t)')
    ->execute([(int) $member['id'], (int) $member['id']]);

$history = readiness_history((int) $member['id'], 1);
$today = $history[today()] ?? null;
$message = $today
    ? sprintf('%s님 오늘 준비 점수 %.1f (%s)', $member['name'], $today['score'], readiness_level($today['score'])[0])
    : sprintf('%s님 건강 기록을 저장했어요. (기준선 %d/7일)', $member['name'], min(7, health_days_count((int) $member['id'])));

json_out(['ok' => true, 'day' => $day, 'message' => $message, 'saved' => array_filter($values, fn($v) => $v !== null)]);
