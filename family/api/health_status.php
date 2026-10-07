<?php
// 단축어 연결 상태 (로그인한 사람 것): 마지막으로 받은 시각과 항목별로 들어왔는지
require dirname(__DIR__) . '/lib/bootstrap.php';
$me = require_login_api();

$stmt = db()->prepare('SELECT received_at, body FROM health_raw WHERE member_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([(int) $me['id']]);
$last = $stmt->fetch();
$stmt = db()->prepare('SELECT * FROM health_days WHERE member_id = ? AND day = ?');
$stmt->execute([(int) $me['id'], today()]);
$row = $stmt->fetch() ?: [];

$body = $last ? json_decode((string) $last['body'], true) : null;
$sent = is_array($body) ? $body : [];
// 항목별: 단축어가 보냈는지(키) · 값이 저장됐는지
$fields = [
    'hrv' => ['심박 변이', 'hrv', 'ms', 2],
    'rhr' => ['안정 시 심박', 'rhr', 'bpm', 2],
    'steps' => ['걸음', 'steps', '걸음', 3],
    'active_kcal' => ['활동 에너지', 'active_kcal', 'kcal', 3],
    'sleep_total' => ['수면', 'sleep_min', '분', 4],
];
$items = [];
foreach ($fields as $key => [$label, $col, $unit, $step]) {
    $has = array_key_exists($key, $sent) || ($key === 'sleep_total' && (isset($sent['sleep']) || isset($sent['sleep_core'])));
    $val = $row[$col] ?? null;
    $items[] = ['key' => $key, 'label' => $label, 'sent' => $has, 'value' => $val !== null ? (float) $val : null, 'unit' => $unit, 'step' => $step,
        'raw' => $has ? mb_substr(is_array($sent[$key] ?? null) ? json_encode($sent[$key], JSON_UNESCAPED_UNICODE) : (string) ($sent[$key] ?? ''), 0, 40) : ''];
}
$ago = $last ? time() - strtotime($last['received_at']) : null;
json_out(['ok' => true, 'received_at' => $last['received_at'] ?? null, 'ago' => $ago, 'items' => $items, 'json' => (bool) $body]);
