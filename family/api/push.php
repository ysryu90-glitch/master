<?php
// 이 기기에서 알림 받기 / 끄기 / 테스트 (로그인 필요)
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/push.php';

$me = require_login_api();
check_csrf();
$data = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

switch ($data['action'] ?? $_GET['action'] ?? '') {
    case 'key':
        json_out(['ok' => true, 'key' => vapid_keys()['public']]);
    case 'subscribe':
        $sub = $data['subscription'] ?? [];
        $endpoint = (string) ($sub['endpoint'] ?? '');
        if (!preg_match('#^https://#', $endpoint) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            json_out(['ok' => false, 'error' => '구독 정보가 올바르지 않아요.']);
        }
        db()->prepare('REPLACE INTO push_subscriptions (member_id, endpoint_hash, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$me['id'], hash('sha256', $endpoint), $endpoint, $sub['keys']['p256dh'], $sub['keys']['auth'], substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
        json_out(['ok' => true]);
    case 'unsubscribe':
        db()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND member_id = ?')
            ->execute([hash('sha256', (string) ($data['endpoint'] ?? '')), $me['id']]);
        json_out(['ok' => true]);
    case 'status':
        // 이 기기가 서버에 등록돼 있는지 + 내 기기 목록
        $hash = hash('sha256', (string) ($data['endpoint'] ?? ''));
        $devices = push_devices((int) $me['id']);
        json_out(['ok' => true, 'key' => vapid_keys()['public'], 'known' => in_array($hash, array_column($devices, 'endpoint_hash'), true),
            'this' => $hash, 'devices' => $devices, 'https' => is_https()]);
    case 'remove':
        db()->prepare('DELETE FROM push_subscriptions WHERE id = ? AND member_id = ?')->execute([(int) ($data['id'] ?? 0), $me['id']]);
        json_out(['ok' => true]);
    case 'test':
        $results = push_to_member_detail((int) $me['id'], '🔔 알림 테스트', $me['name'] . '님, 이 기기에서 알림을 잘 받고 있어요. (' . date('H:i:s') . ')', 'settings.php#notify', 'test');
        if (!$results) json_out(['ok' => false, 'error' => '알림 받을 기기가 등록돼 있지 않아요. 먼저 「이 기기에서 알림 받기」를 눌러 주세요.', 'results' => []]);
        $ok = count(array_filter($results, fn($r) => $r['ok']));
        json_out(['ok' => $ok > 0, 'sent' => $ok, 'results' => $results,
            'error' => $ok ? '' : '모든 기기에 보내지 못했어요. 아래 이유를 확인해 주세요.']);
}
json_out(['ok' => false, 'error' => '알 수 없는 요청']);
