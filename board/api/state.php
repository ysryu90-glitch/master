<?php
// 전광판이 읽는 가족별 최신 요약 (GET)
require __DIR__ . '/db.php';

$pdo = board_db(board_config());
$members = [];
foreach ($pdo->query('SELECT member_id, payload FROM member_state') as $row) {
    $payload = json_decode($row['payload'], true);
    if (is_array($payload)) {
        $members[$row['member_id']] = $payload;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'members' => (object) $members], JSON_UNESCAPED_UNICODE);
