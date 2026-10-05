<?php
// 가계부를 엑셀에서 열 수 있는 CSV로 내려받기 (?m=YYYY-MM 한 달, ?y=YYYY 한 해)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ledger.php';
require_login();

if (preg_match('/^\d{4}$/', (string) ($_GET['y'] ?? ''))) {
    [$from, $to, $label] = [$_GET['y'] . '-01-01', $_GET['y'] . '-12-31', $_GET['y'] . '년'];
} else {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
    [$from, $to, $label] = [$ym . '-01', date('Y-m-t', strtotime($ym . '-01')), (int) substr($ym, 0, 4) . '년 ' . (int) substr($ym, 5) . '월'];
}
$stmt = db()->prepare('SELECT * FROM expenses WHERE day BETWEEN ? AND ? ORDER BY day, at_time, id');
$stmt->execute([$from, $to]);
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m['name'];
$src = ['manual' => '직접', 'sms' => '카드 문자', 'screen' => '화면 캡처', 'wallet' => '애플페이', 'fixed' => '고정'];

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('가계부 ' . $label . '.csv'));
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // 엑셀이 한글을 제대로 읽게
fputcsv($out, ['날짜', '시각', '구분', '항목', '어디서', '금액', '메모', '낸 사람', '카드', '들어온 방법'], ',', '"', '');
foreach ($stmt as $x) {
    fputcsv($out, [$x['day'], $x['at_time'] ? substr($x['at_time'], 0, 5) : '', $x['kind'] === 'in' ? '수입' : '지출', ledger_cat($x['category'])[0],
        $x['merchant'], (int) $x['amount'], $x['memo'], $names[(int) $x['member_id']] ?? '', $x['card'], $src[$x['source']] ?? $x['source']], ',', '"', '');
}
fclose($out);
