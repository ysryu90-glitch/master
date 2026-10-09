<?php
// 가계부 「한 번에 가져오기」: 뱅크샐러드 · 카드사 엑셀(xlsx) · CSV 파일을 읽어 가계부 줄로 바꾸기
// zip 확장이 꺼진 NAS에서도 되도록 xlsx(zip)를 직접 풀어서 읽는다.

/** zip 안에서 원하는 파일들만 꺼내기 [이름 => 내용] */
function import_unzip(string $path, callable $want): array
{
    $out = [];
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $name = $z->getNameIndex($i);
                if ($want($name)) $out[$name] = (string) $z->getFromIndex($i);
            }
            $z->close();
            return $out;
        }
    }
    // 직접 풀기: 중앙 디렉터리를 읽고 각 파일을 deflate 해제
    $data = (string) file_get_contents($path);
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('엑셀(xlsx) 파일을 읽지 못했어요.');
    $cd = unpack('vdisk/vcdDisk/vcountDisk/vcount/Vsize/Voffset', substr($data, $eocd + 4, 16));
    $p = $cd['offset'];
    for ($i = 0; $i < $cd['count']; $i++) {
        $h = unpack('Vsig/vver/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($data, $p, 46));
        $name = substr($data, $p + 46, $h['nlen']);
        $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if (!$want($name)) continue;
        $lh = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($data, $h['offset'], 30));
        $raw = substr($data, $h['offset'] + 30 + $lh['nlen'] + $lh['elen'], $h['csize']);
        $out[$name] = $h['method'] === 8 ? (string) @gzinflate($raw) : $raw;
    }
    return $out;
}

/** xlsx → 시트별 표 [[행[칸...]...]...] */
function import_xlsx(string $path): array
{
    $files = import_unzip($path, fn($n) => $n === 'xl/sharedStrings.xml' || $n === 'xl/workbook.xml' || $n === 'xl/styles.xml' || preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n));
    $shared = [];
    if (isset($files['xl/sharedStrings.xml'])) {
        $sx = simplexml_load_string($files['xl/sharedStrings.xml']);
        if ($sx) foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string) $si->t;
            foreach ($si->r as $r) $t .= (string) $r->t;
            $shared[] = $t;
        }
    }
    // 날짜 서식인 칸 (스타일 번호) 찾기
    $dateStyles = [];
    if (isset($files['xl/styles.xml']) && ($st = simplexml_load_string($files['xl/styles.xml']))) {
        $custom = [];
        if (isset($st->numFmts)) foreach ($st->numFmts->numFmt as $f) $custom[(int) $f['numFmtId']] = (string) $f['formatCode'];
        $i = 0;
        if (isset($st->cellXfs)) foreach ($st->cellXfs->xf as $xf) {
            $id = (int) $xf['numFmtId'];
            $code = $custom[$id] ?? '';
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || preg_match('/[yd]|h+:mm/i', preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $code))) $dateStyles[$i] = $id;
            $i++;
        }
    }
    $sheets = [];
    ksort($files, SORT_NATURAL);
    foreach ($files as $name => $xml) {
        if (!str_starts_with($name, 'xl/worksheets/')) continue;
        $sx = simplexml_load_string($xml);
        if (!$sx || !isset($sx->sheetData)) continue;
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64);
                $t = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : (isset($c->is->t) ? (string) $c->is->t : '');
                if ($t === 's') $v = $shared[(int) $v] ?? '';
                elseif ($t === '' && $v !== '' && is_numeric($v) && isset($dateStyles[(int) $c['s']])) $v = ['serial' => (float) $v];
                $cells[$col - 1] = $v;
            }
            if ($cells) { $max = max(array_keys($cells)); $r = array_fill(0, $max + 1, ''); foreach ($cells as $k => $v) $r[$k] = $v; $rows[] = $r; }
        }
        $sheets[] = $rows;
    }
    return $sheets;
}

/** CSV(엑셀에서 저장한 것 포함, EUC-KR도) → 표 */
function import_csv(string $path): array
{
    $text = (string) file_get_contents($path);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'CP949');
    $delim = substr_count(strtok($text, "\n"), "\t") > substr_count(strtok($text, "\n"), ',') ? "\t" : ',';
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) $rows[] = array_map(fn($v) => trim((string) $v), $r);
    fclose($fh);
    return [$rows];
}

/** 엑셀 날짜 숫자 → 'Y-m-d H:i' */
function import_serial(float $s): string
{
    $ts = (int) round(($s - 25569) * 86400);
    return gmdate('Y-m-d H:i', $ts);
}

/** 머리글로 칸 찾기 */
const IMPORT_HEADERS = [
    'date' => ['날짜', '일자', '거래일', '거래일자', '이용일', '이용일자', '승인일', '승인일자', '사용일', '이용일시', '거래일시', '승인일시', '결제일'],
    'time' => ['시간', '거래시간', '이용시간', '승인시간'],
    'amount' => ['금액', '이용금액', '결제금액', '승인금액', '거래금액', '사용금액', '출금액', '지출'],
    'merchant' => ['내용', '가맹점', '가맹점명', '이용처', '사용처', '이용가맹점', '적요', '거래처', '상호', '이용하신곳'],
    'type' => ['타입', '구분', '거래구분', '유형', '입출금'],
    'cat' => ['대분류', '카테고리', '분류', '업종'],
    'sub' => ['소분류'],
    'card' => ['결제수단', '카드', '카드명', '이용카드', '카드종류', '자산'],
    'memo' => ['메모', '비고'],
    'status' => ['상태', '승인상태', '취소여부', '취소구분'],
];

/** 표에서 가계부 줄 뽑기 → ['rows' => [...], 'source' => '뱅크샐러드'|'엑셀', 'skipped' => n] */
function import_parse(array $sheets): array
{
    $best = null;
    foreach ($sheets as $rows) {
        foreach (array_slice($rows, 0, 15, true) as $ri => $row) {
            $map = [];
            foreach ($row as $ci => $cell) {
                $h = preg_replace('/\s+/u', '', is_array($cell) ? '' : (string) $cell);
                foreach (IMPORT_HEADERS as $k => $names) if (!isset($map[$k]) && in_array($h, $names, true)) { $map[$k] = $ci; break; }
            }
            if (isset($map['date'], $map['amount']) && (!$best || count($map) > count($best['map']))) $best = ['rows' => $rows, 'header' => $ri, 'map' => $map];
        }
    }
    if (!$best) throw new RuntimeException('날짜 · 금액 칸이 있는 표를 찾지 못했어요. 뱅크샐러드나 카드사에서 내려받은 엑셀 · CSV인지 확인해 주세요.');
    $m = $best['map'];
    $isBanksalad = isset($m['sub'], $m['type'], $m['card']);
    $out = [];
    $skipped = 0;
    foreach (array_slice($best['rows'], $best['header'] + 1) as $row) {
        $get = fn($k) => isset($m[$k]) ? ($row[$m[$k]] ?? '') : '';
        $dv = $get('date');
        $tv = $get('time');
        if (is_array($dv)) { $dt = import_serial($dv['serial']); $day = substr($dt, 0, 10); $time = substr($dt, 11, 5) !== '00:00' ? substr($dt, 11, 5) : ''; }
        elseif (preg_match('/(\d{4})\D{1,3}(\d{1,2})\D{1,3}(\d{1,2})(?:\D+(\d{1,2}):(\d{2}))?/', (string) $dv, $mm)) { $day = sprintf('%04d-%02d-%02d', $mm[1], $mm[2], $mm[3]); $time = isset($mm[4]) ? sprintf('%02d:%02d', $mm[4], $mm[5]) : ''; }
        elseif (preg_match('/^(\d{2})\D(\d{1,2})\D(\d{1,2})/', (string) $dv, $mm)) { $day = sprintf('20%02d-%02d-%02d', $mm[1], $mm[2], $mm[3]); $time = ''; }
        else { if (trim((string) $dv) !== '') $skipped++; continue; }
        if (!checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) { $skipped++; continue; }
        if (is_array($tv)) { $mins = (int) round(($tv['serial'] - floor($tv['serial'])) * 1440) % 1440; $time = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60); }
        elseif (preg_match('/(\d{1,2}):(\d{2})/', (string) $tv, $tm)) $time = sprintf('%02d:%02d', $tm[1], $tm[2]);
        $rawAmt = is_array($get('amount')) ? '' : (string) $get('amount');
        $num = (float) str_replace([',', '원', ' '], '', $rawAmt);
        if (!$num) { $skipped++; continue; }
        $type = (string) $get('type');
        $status = (string) $get('status');
        if (preg_match('/이체/u', $type)) { $skipped++; continue; } // 내 계좌끼리 옮긴 돈은 빼기
        $kind = 'out';
        if (preg_match('/수입|입금/u', $type)) $kind = 'in';
        elseif ($isBanksalad && $type === '' && $num > 0) $kind = 'in';
        $cancel = (bool) preg_match('/취소/u', $status . $type);
        $amount = (int) round(abs($num));
        // 취소 · 환불은 마이너스 (뱅크샐러드는 지출이 -금액, 지출인데 +면 환불)
        if ($cancel || ($kind === 'out' && (($isBanksalad && $num > 0) || (!$isBanksalad && $num < 0)))) $amount = -$amount;
        $merchant = trim(preg_replace('/\s+/u', ' ', (string) $get('merchant')));
        $out[] = [
            'day' => $day, 'time' => $time, 'kind' => $kind, 'amount' => $amount, 'merchant' => mb_substr($merchant, 0, 100),
            'card' => mb_substr(trim((string) $get('card')), 0, 40), 'memo' => mb_substr(trim((string) $get('memo')), 0, 200),
            'category' => $kind === 'in' ? 'income' : import_category(trim((string) $get('cat')), trim((string) $get('sub')), $merchant),
        ];
    }
    // 취소 · 환불은 원래 결제와 같은 항목으로 (같은 가게 · 같은 금액)
    foreach ($out as $i => $r) {
        if ($r['amount'] >= 0) continue;
        foreach ($out as $o) if ($o['amount'] === -$r['amount'] && $o['merchant'] === $r['merchant']) { $out[$i]['category'] = $o['category']; break; }
    }
    return ['rows' => $out, 'source' => $isBanksalad ? '뱅크샐러드' : '엑셀 · CSV', 'skipped' => $skipped];
}

/** 뱅크샐러드 · 카드사 분류 → 우리 항목 (모르면 가게 이름으로) */
function import_category(string $cat, string $sub, string $merchant): string
{
    $s = $cat . ' ' . $sub;
    $map = [
        'cafe' => '카페|간식|디저트|베이커리|커피', 'food' => '마트|편의점|장보기|식료품|슈퍼', 'eatout' => '식비|외식|음식|배달|술|유흥|식당',
        'car' => '교통|자동차|주유|주차|택시|대중교통', 'shopping' => '쇼핑|뷰티|미용|패션|의류|온라인',
        'bill' => '주거|통신|공과금|관리비|보험|세금', 'health' => '의료|건강|병원|약국', 'outing' => '여행|숙박|문화|여가|레저|공연|스포츠',
        'kid' => '육아|교육|아이|학원|유치원', 'living' => '생활|잡화|가전|가구',
    ];
    foreach ($map as $k => $re) if ($s !== ' ' && preg_match('/' . $re . '/u', $s)) return $k;
    return ledger_guess($merchant);
}

/** 이미 가계부에 있는 결제인지 (같은 날 · 같은 금액 · (가게 비슷 또는 시각 10분 안)) */
function import_is_dupe(array $r): bool
{
    $stmt = db()->prepare('SELECT merchant, at_time FROM expenses WHERE day = ? AND amount = ? AND kind = ?');
    $stmt->execute([$r['day'], $r['amount'], $r['kind']]);
    foreach ($stmt as $x) {
        $a = preg_replace('/[\s()（）㈜주식회사]/u', '', mb_strtolower((string) $x['merchant']));
        $b = preg_replace('/[\s()（）㈜주식회사]/u', '', mb_strtolower($r['merchant']));
        if ($a === '' || $b === '' || str_contains($a, mb_substr($b, 0, 4)) || str_contains($b, mb_substr($a, 0, 4))) return true;
        if ($r['time'] && $x['at_time'] && abs(strtotime('2000-01-01 ' . $r['time']) - strtotime('2000-01-01 ' . $x['at_time'])) <= 600) return true;
    }
    return false;
}
