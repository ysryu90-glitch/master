<?php
// 카드 결제를 가계부에 자동으로 넣기 (아이폰 단축어가 보냄, 사람마다 다른 토큰 = 건강 단축어와 같은 토큰)
//  - 카드 결제 문자:     {"text": "신한카드 승인 12,500원 10/05 13:22 스타벅스 ..."}
//  - 화면 캡처 글자:     ?mode=screen  {"text": "(알림 센터 · 페이북 이용내역을 캡처해서 뽑은 글자)"}  → 여러 건을 한 번에
//  - 애플페이(지갑):     {"amount": "₩12,500", "merchant": "스타벅스", "card": "현대카드"}
//  ?plain=1 이면 단축어 '알림 보기'에 바로 쓸 수 있게 글자만 돌려줌. ?dry=1 이면 읽기만 하고 저장 안 함.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/ledger.php';

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
if (!$data && $raw !== '') $data = ['text' => $raw]; // 본문에 글자만 그대로 보낸 경우
$opt = fn(string $k) => !empty($data[$k]) || !empty($_GET[$k]);
$plain = $opt('plain');
$screen = ($data['mode'] ?? $_GET['mode'] ?? '') === 'screen';

function reply(array $r, bool $plain): void
{
    if ($plain) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $r['message'] ?? ($r['error'] ?? '');
        exit;
    }
    json_out($r);
}

$bearer = preg_match('/Bearer\s+([0-9a-f]{32})/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $bm) ? $bm[1] : '';
$token = trim((string) ($_GET['token'] ?? $data['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? $bearer));
if (!preg_match('/^[0-9a-f]{32}$/', $token)) reply(['ok' => false, 'error' => '토큰이 없어요.', 'message' => '⚠️ 토큰이 없어요'], $plain);
$stmt = db()->prepare("SELECT * FROM members WHERE shortcut_token = ? AND role = 'adult'");
$stmt->execute([$token]);
$member = $stmt->fetch();
if (!$member) reply(['ok' => false, 'error' => '토큰이 맞지 않아요.', 'message' => '⚠️ 토큰이 맞지 않아요'], $plain);

$text = trim((string) ($data['text'] ?? $data['message'] ?? ''));

// 읽은 결제 목록
if ($text !== '') {
    if ($screen) {
        $items = parse_card_blocks($text);
        if (!$items) reply(['ok' => true, 'skipped' => true, 'message' => '화면에서 결제를 찾지 못했어요. 결제 알림이 보이게 한 뒤 다시 해 주세요.'], $plain);
        $source = 'screen';
    } else {
        $p = parse_card_sms($text);
        if (!$p['ok']) reply(['ok' => $opt('dry') ? false : true, 'skipped' => true, 'error' => $p['error'], 'message' => '건너뜀: ' . $p['error']], $plain);
        $items = [$p + ['hash' => sha1($member['id'] . '|' . preg_replace('/\s+/u', '', $text))]];
        $source = 'sms';
    }
} else {
    $amount = (int) preg_replace('/[^\d]/', '', explode('.', (string) ($data['amount'] ?? ''))[0]);
    if ($amount <= 0) reply(['ok' => false, 'error' => '금액이 없어요.', 'message' => '⚠️ 금액이 없어요'], $plain);
    $merchant = mb_substr(trim((string) ($data['merchant'] ?? '')), 0, 100);
    $items = [['amount' => $amount, 'merchant' => $merchant, 'card' => (string) ($data['card'] ?? ''), 'day' => today(), 'time' => date('H:i'), 'cancel' => false,
        'hash' => sha1($member['id'] . '|w|' . $amount . '|' . $merchant . '|' . date('Y-m-d H:i'))]];
    $source = 'wallet';
}

$line = function (array $p): string {
    [$cn, $ci] = ledger_cat(ledger_guess($p['merchant']));
    return $ci . ' ' . ($p['merchant'] ?: '결제') . ' ' . won($p['amount']) . ($p['cancel'] ? ' 취소' : '');
};

if ($opt('dry')) {
    $first = $items[0];
    reply(['ok' => true, 'dry' => true, 'parsed' => $first, 'items' => $items,
        'message' => implode("\n", array_map(fn($p) => $line($p) . ' · ' . $p['day'] . ($p['time'] ? ' ' . $p['time'] : '') . ' → ' . ledger_cat(ledger_guess($p['merchant']))[0], $items))], $plain);
}

$saved = [];
$dupes = 0;
foreach ($items as $p) {
    $amount = $p['amount'];
    $category = ledger_guess($p['merchant']);
    $memo = '';
    if ($p['cancel']) {
        // 취소: 같은 가게 · 같은 금액의 최근 지출 항목을 따라가고 마이너스로 기록
        $stmt = db()->prepare("SELECT category FROM expenses WHERE kind = 'out' AND amount = ? AND merchant = ? AND day >= DATE_SUB(?, INTERVAL 30 DAY) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$amount, $p['merchant'], $p['day']]);
        $category = $stmt->fetchColumn() ?: $category;
        $amount = -$amount;
        $memo = '결제 취소';
    }
    $hash = $p['hash'] ?? sha1($member['id'] . '|s|' . $p['day'] . '|' . $amount . '|' . preg_replace('/\s+/u', '', $p['merchant']) . '|' . ($p['time'] ?? ''));
    if ($source === 'screen') {
        // 문자 · 애플페이로 이미 들어온 같은 결제면 건너뜀 (같은 날 · 같은 금액 · 시간 5분 안 또는 같은 가게)
        $stmt = db()->prepare('SELECT id FROM expenses WHERE day = ? AND amount = ? AND (merchant = ?' . ($p['time'] ? ' OR ABS(TIME_TO_SEC(TIMEDIFF(at_time, ?))) <= 300' : '') . ') LIMIT 1');
        $stmt->execute(array_merge([$p['day'], $amount, $p['merchant']], $p['time'] ? [$p['time'] . ':00'] : []));
        if ($stmt->fetchColumn()) { $dupes++; continue; }
    }
    $id = expense_add([
        'day' => $p['day'], 'time' => $p['time'], 'amount' => $amount, 'category' => $category, 'merchant' => $p['merchant'], 'memo' => $memo,
        'member_id' => (int) $member['id'], 'card' => $p['card'] ?? '', 'source' => $source, 'checked' => $category === 'etc' ? 0 : 1,
        'raw_hash' => $hash, 'created_by' => (int) $member['id'],
    ]);
    if ($id === null) { $dupes++; continue; }
    $saved[] = $line($p) . ' → ' . ledger_cat($category)[0] . ($category === 'etc' ? ' (항목 확인 필요)' : '');
}

if (!$saved) reply(['ok' => true, 'duplicate' => true, 'message' => '이미 기록된 결제예요' . ($dupes > 1 ? " ({$dupes}건)" : '')], $plain);
$msg = count($saved) === 1 ? '💰 ' . $saved[0] : '💰 ' . count($saved) . '건 기록했어요' . "\n" . implode("\n", $saved);
if ($dupes) $msg .= "\n(이미 있던 {$dupes}건은 건너뜀)";
reply(['ok' => true, 'saved' => count($saved), 'skipped' => $dupes, 'message' => $msg], $plain);
