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
    case 'test':
        $sent = push_to_member((int) $me['id'], '🔔 알림 테스트', $me['name'] . '님, 이 기기에서 알림을 잘 받고 있어요.', 'settings.php#notify', 'test');
        json_out($sent ? ['ok' => true, 'sent' => $sent] : ['ok' => false, 'error' => '보낼 기기가 없거나 보내기에 실패했어요.']);
}
json_out(['ok' => false, 'error' => '알 수 없는 요청']);
