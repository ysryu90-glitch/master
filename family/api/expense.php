<?php
// 카드 결제를 가계부에 자동으로 넣기 (아이폰 단축어 자동화가 보냄, 사람마다 다른 토큰 = 건강 단축어와 같은 토큰)
//  - 카드 결제 문자:  {"text": "신한카드 승인 12,500원 10/05 13:22 스타벅스 ..."}
//  - 애플페이(지갑):  {"amount": "₩12,500", "merchant": "스타벅스", "card": "현대카드"}
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/ledger.php';

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
if (!$data && $raw !== '') $data = ['text' => $raw]; // 본문에 문자만 그대로 보낸 경우

$bearer = preg_match('/Bearer\s+([0-9a-f]{32})/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $bm) ? $bm[1] : '';
$token = trim((string) ($_GET['token'] ?? $data['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? $bearer));
if (!preg_match('/^[0-9a-f]{32}$/', $token)) json_out(['ok' => false, 'error' => '토큰이 없어요.', 'message' => '⚠️ 토큰이 없어요']);
$stmt = db()->prepare("SELECT * FROM members WHERE shortcut_token = ? AND role = 'adult'");
$stmt->execute([$token]);
$member = $stmt->fetch();
if (!$member) json_out(['ok' => false, 'error' => '토큰이 맞지 않아요.', 'message' => '⚠️ 토큰이 맞지 않아요']);

$text = trim((string) ($data['text'] ?? $data['message'] ?? ''));
if (!empty($data['dry']) || !empty($_GET['dry'])) {
    // 시험: 읽기만 하고 저장하지 않음 (가계부 › 카드 자동 입력 안내 화면의 '시험해 보기')
    $p = parse_card_sms($text);
    if (!$p['ok']) json_out(['ok' => false, 'error' => $p['error']]);
    [$cn, $ci] = ledger_cat(ledger_guess($p['merchant']));
    json_out(['ok' => true, 'dry' => true, 'parsed' => $p, 'message' => $ci . ' ' . ($p['merchant'] ?: '(가게 이름 못 찾음)') . ' · ' . won($p['amount']) . ($p['cancel'] ? ' 취소' : '') . ' · ' . $p['day'] . ($p['time'] ? ' ' . $p['time'] : '') . ' → ' . $cn]);
}
if ($text !== '') {
    $p = parse_card_sms($text);
    if (!$p['ok']) json_out(['ok' => true, 'skipped' => true, 'message' => '건너뜀: ' . $p['error']]);
    $source = 'sms';
    $hash = sha1($member['id'] . '|' . preg_replace('/\s+/u', '', $text));
} else {
    $amount = (int) preg_replace('/[^\d]/', '', explode('.', (string) ($data['amount'] ?? ''))[0]);
    if ($amount <= 0) json_out(['ok' => false, 'error' => '금액이 없어요.', 'message' => '⚠️ 금액이 없어요']);
    $p = ['amount' => $amount, 'merchant' => mb_substr(trim((string) ($data['merchant'] ?? '')), 0, 100), 'card' => (string) ($data['card'] ?? ''),
        'day' => today(), 'time' => date('H:i'), 'cancel' => false];
    $source = 'wallet';
    $hash = sha1($member['id'] . '|w|' . $amount . '|' . $p['merchant'] . '|' . date('Y-m-d H:i'));
}

$category = ledger_guess($p['merchant']);
$amount = $p['amount'];
$memo = '';
if ($p['cancel']) {
    // 취소: 같은 가게 · 같은 금액의 최근 지출 항목을 따라가고 마이너스로 기록
    $stmt = db()->prepare("SELECT category FROM expenses WHERE kind = 'out' AND amount = ? AND merchant = ? AND day >= DATE_SUB(?, INTERVAL 30 DAY) ORDER BY id DESC LIMIT 1");
    $stmt->execute([$amount, $p['merchant'], $p['day']]);
    $category = $stmt->fetchColumn() ?: $category;
    $amount = -$amount;
    $memo = '결제 취소';
}
$id = expense_add([
    'day' => $p['day'], 'time' => $p['time'], 'amount' => $amount, 'category' => $category, 'merchant' => $p['merchant'], 'memo' => $memo,
    'member_id' => (int) $member['id'], 'card' => $p['card'], 'source' => $source, 'checked' => $category === 'etc' ? 0 : 1,
    'raw_hash' => $hash, 'created_by' => (int) $member['id'],
]);
if ($id === null) json_out(['ok' => true, 'duplicate' => true, 'message' => '이미 기록된 결제예요']);

[$catName, $catIcon] = ledger_cat($category);
json_out(['ok' => true, 'id' => $id,
    'message' => $catIcon . ' ' . ($p['merchant'] ?: '결제') . ' ' . won(abs($amount)) . ($p['cancel'] ? ' 취소' : '') . ' → ' . $catName . ($category === 'etc' ? ' (가계부에서 항목을 골라 주세요)' : '')]);
