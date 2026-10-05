<?php
// 가계부: 지출 · 수입 기록, 카드 결제 문자 읽기, 일기와 연결

/** 항목 [키 => [이름, 아이콘]] (수입은 따로) */
const LEDGER_CATEGORIES = [
    'food' => ['장보기', '🛒'],
    'eatout' => ['외식 · 배달', '🍽'],
    'cafe' => ['카페 · 간식', '☕'],
    'outing' => ['나들이 · 여가', '🧺'],
    'kid' => ['아이', '👧'],
    'living' => ['생활용품', '🧻'],
    'car' => ['교통 · 주유', '🚗'],
    'health' => ['병원 · 약', '💊'],
    'shopping' => ['쇼핑', '🛍'],
    'bill' => ['공과금 · 통신', '🧾'],
    'etc' => ['기타', '📦'],
];
const LEDGER_INCOME = ['income' => ['수입', '💵']];

/** 나들이 · 외출 일기에 자주 붙는 항목 (일기 쓰기 화면에서 먼저 보여 줌) */
const LEDGER_OUTING_CATS = ['outing', 'eatout', 'cafe', 'car', 'shopping', 'etc'];

/** 가게 이름 → 항목 추측 (앞에 있을수록 먼저) */
const LEDGER_GUESS = [
    'eatout' => ['배달의민족', '배민', '요기요', '쿠팡이츠', '맥도날드', '버거킹', '롯데리아', 'KFC', '맘스터치', '김밥', '치킨', '피자', '식당', '분식', '국밥', '갈비', '돈까스', '냉면', '칼국수', '초밥', '스시', '레스토랑', '아웃백', '빕스', '애슐리', '푸드코트', '한식', '중식', '반점', '일식',
        '순대', '국수', '찌개', '감자탕', '해장', '곱창', '삼겹', '고깃집', '숯불', '포차', '횟집', '수산', '짜장', '짬뽕', '떡볶이', '도시락', '샐러드', '샌드위치', '서브웨이', '버거', '쌀국수', '마라', '돈부리', '우동', '라멘', '보쌈', '족발', '닭갈비', '샤브', '뷔페'],
    'cafe' => ['스타벅스', '투썸', '이디야', '메가', '컴포즈', '빽다방', '할리스', '폴바셋', '커피', '카페', '배스킨', '베스킨', '던킨', '파리바게뜨', '파리바게트', '뚜레쥬르', '베이커리', '설빙', '공차', '아이스크림', '편의점', 'GS25', '이마트24', '세븐일레븐', '미니스톱', 'CU', '씨유'],
    'outing' => ['키즈카페', '키즈', '놀이', '박물관', '미술관', '과학관', '동물원', '수목원', '아쿠아', '에버랜드', '롯데월드', '서울랜드', '테마파크', '입장', '체험', '공원', '관광', '레일바이크', '캠핑', '리조트', '호텔', '펜션', 'CGV', '메가박스', '롯데시네마', '영화'],
    'car' => ['주유', '칼텍스', 'GS칼텍스', 'SK에너지', 'S-OIL', '에쓰오일', '현대오일뱅크', '알뜰주유', '충전', '주차', '파킹', '하이패스', '도로공사', '통행료', '택시', '카카오T', '카카오모빌리티', '티머니', '코레일', 'SRT', '버스', '세차'],
    'health' => ['약국', '병원', '의원', '소아과', '소아청소년과', '치과', '한의원', '안과', '이비인후과', '피부과', '검진'],
    'kid' => ['유치원', '어린이집', '학원', '토이저러스', '장난감', '완구', '키즈', '아동', '교보문고', '영풍문고', '서점', '문구'],
    'food' => ['이마트', '홈플러스', '롯데마트', '코스트코', '트레이더스', '하나로', '농협', '마켓컬리', '컬리', 'GS더프레시', '노브랜드', '정육', '마트', '슈퍼', '청과', '반찬', '쿠팡프레시'],
    'living' => ['다이소', '올리브영', '생활', '세탁', '홈쇼핑'],
    'bill' => ['관리비', '한국전력', '한전', '도시가스', '수도', 'KT', 'SKT', 'SK텔레콤', 'LG유플러스', 'LGU+', '통신', '보험', '넷플릭스', '유튜브', '쿠팡와우'],
    'shopping' => ['쿠팡', '네이버페이', '11번가', 'G마켓', '옥션', '무신사', '유니클로', '자라', '백화점', '아울렛', '스마트스토어'],
];

function ledger_cat(string $key): array
{
    return LEDGER_CATEGORIES[$key] ?? LEDGER_INCOME[$key] ?? ['기타', '📦'];
}

function ledger_guess(string $merchant): string
{
    $m = mb_strtolower(preg_replace('/\s+/u', '', $merchant));
    foreach (LEDGER_GUESS as $cat => $words) {
        foreach ($words as $w) if ($w !== '' && mb_stripos($m, mb_strtolower(str_replace(' ', '', $w))) !== false) return $cat;
    }
    return 'etc';
}

/** 12,500 → '12,500원', 68000 → '6.8만원' (짧게) */
function won(int $n, bool $short = false): string
{
    if ($short && abs($n) >= 10000) {
        $v = $n / 10000;
        $txt = number_format($v, abs($v) >= 100 ? 0 : 1);
        if (str_contains($txt, '.')) $txt = rtrim(rtrim($txt, '0'), '.'); // 6.0만 → 6만, 150만은 그대로
        return $txt . '만원';
    }
    return number_format($n) . '원';
}

/**
 * 카드 결제 문자 읽기. 카드사마다 모양이 달라서 금액 · 날짜 · 시간 · 가게를 따로 찾는다.
 * 반환: ['ok' => bool, 'cancel' => bool, 'amount', 'day', 'time', 'merchant', 'card', 'error']
 */
function parse_card_sms(string $text): array
{
    $t = str_replace(["\r", "\u{00A0}"], ["", ' '], $text);
    $t = preg_replace('/\[(Web|웹)발신\]/u', '', $t);
    $cancel = (bool) preg_match('/취소/u', $t);
    if (!$cancel && !preg_match('/승인|사용|결제|일시불|할부|출금/u', $t)) {
        return ['ok' => false, 'error' => '카드 결제 문자가 아닌 것 같아요.'];
    }
    // 금액: '누적' · '잔액' · '한도' 뒤의 숫자는 빼고 처음 나오는 '숫자원'
    $amount = null;
    if (preg_match_all('/(누적|잔액|한도|포인트)?[^\d\n]{0,6}?([\d]{1,3}(?:,\d{3})+|\d+)\s*원/u', $t, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            if ($m[1] !== '') continue;
            $amount = (int) str_replace(',', '', $m[2]);
            break;
        }
    }
    if (!$amount) return ['ok' => false, 'error' => '금액을 찾지 못했어요.'];

    $day = today();
    if (preg_match('/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})(?!\d)/', $t, $dm)) {
        $y = (int) date('Y');
        $cand = sprintf('%04d-%02d-%02d', $y, $dm[1], $dm[2]);
        if (checkdate((int) $dm[1], (int) $dm[2], $y)) {
            if ($cand > today()) $cand = sprintf('%04d-%02d-%02d', $y - 1, $dm[1], $dm[2]); // 1월에 받은 12월 문자
            $day = $cand;
        }
    }
    $time = preg_match('/(?<!\d)([01]?\d|2[0-3]):([0-5]\d)(?!\d)/', $t, $tm) ? sprintf('%02d:%02d', $tm[1], $tm[2]) : null;
    $card = preg_match('/([가-힣A-Za-z]{1,10}(?:카드|체크|Card))/u', $t, $cm) ? $cm[1] : '';

    // 가게 이름: 시간 바로 뒤에 오거나, 날짜 · 시간 다음 줄
    $merchant = '';
    $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $t)), fn($l) => $l !== ''));
    $skip = '/누적|잔액|한도|승인|취소|일시불|할부|카드|님\b|님$|원$|^\d|포인트|체크|\*{2,}|\d{4}\)?$/u';
    if ($time && preg_match('/' . preg_quote($time, '/') . '\s+(.+)$/um', $t, $after)) {
        $cand = trim(preg_replace('/\s*(누적|잔액).*$/u', '', $after[1]));
        if ($cand !== '' && !preg_match('/^\d/u', $cand)) $merchant = $cand;
    }
    if ($merchant === '') {
        foreach ($lines as $i => $l) {
            if ($time && str_contains($l, $time) && isset($lines[$i + 1]) && !preg_match($skip, $lines[$i + 1])) { $merchant = $lines[$i + 1]; break; }
        }
    }
    if ($merchant === '') {
        foreach (array_reverse($lines) as $l) {
            if (!preg_match($skip, $l) && !preg_match('/\d{1,2}[\/.]\d{1,2}|\d{1,2}:\d{2}/u', $l) && mb_strlen($l) >= 2) { $merchant = $l; break; }
        }
    }
    $merchant = mb_substr(trim(preg_replace('/\s{2,}/u', ' ', $merchant)), 0, 100);
    return ['ok' => true, 'cancel' => $cancel, 'amount' => $amount, 'day' => $day, 'time' => $time, 'merchant' => $merchant, 'card' => $card, 'error' => ''];
}

/** 지출 하나 저장. 반환: 새 id (같은 문자가 두 번 오면 null) */
function expense_add(array $e): ?int
{
    $kind = ($e['kind'] ?? 'out') === 'in' ? 'in' : 'out';
    $cat = $kind === 'in' ? 'income' : (isset(LEDGER_CATEGORIES[$e['category'] ?? '']) ? $e['category'] : 'etc');
    try {
        db()->prepare('INSERT INTO expenses (day, at_time, kind, amount, category, merchant, memo, member_id, card, source, checked, diary_id, raw_hash, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$e['day'] ?? today(), $e['time'] ?? null, $kind, (int) $e['amount'], $cat, mb_substr((string) ($e['merchant'] ?? ''), 0, 100),
                mb_substr((string) ($e['memo'] ?? ''), 0, 200), $e['member_id'] ?? null, mb_substr((string) ($e['card'] ?? ''), 0, 40),
                $e['source'] ?? 'manual', isset($e['checked']) ? (int) $e['checked'] : 1, $e['diary_id'] ?? null, $e['raw_hash'] ?? null, $e['created_by'] ?? null]);
    } catch (PDOException $ex) {
        if (str_contains($ex->getMessage(), 'Duplicate')) return null;
        throw $ex;
    }
    return (int) db()->lastInsertId();
}

/** 한 달 지출 목록 (최신순) */
function expenses_month(string $ym, ?string $category = null): array
{
    $sql = "SELECT * FROM expenses WHERE day BETWEEN ? AND LAST_DAY(?)" . ($category ? ' AND category = ?' : '') . ' ORDER BY day DESC, at_time DESC, id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$ym . '-01', $ym . '-01'], $category ? [$category] : []));
    return $stmt->fetchAll();
}

/** 한 달 합계 [out, in, byCat => [cat => sum]] (취소는 마이너스로 들어 있어 그대로 더함) */
function expenses_summary(string $ym): array
{
    $stmt = db()->prepare("SELECT kind, category, SUM(amount) s FROM expenses WHERE day BETWEEN ? AND LAST_DAY(?) GROUP BY kind, category");
    $stmt->execute([$ym . '-01', $ym . '-01']);
    $out = 0; $in = 0; $by = [];
    foreach ($stmt as $r) {
        if ($r['kind'] === 'in') { $in += (int) $r['s']; continue; }
        $out += (int) $r['s'];
        $by[$r['category']] = (int) $r['s'];
    }
    arsort($by);
    return ['out' => $out, 'in' => $in, 'byCat' => $by];
}

/** 그날 지출 (일기 연결용) */
function expenses_of_day(string $day): array
{
    $stmt = db()->prepare("SELECT * FROM expenses WHERE day = ? AND kind = 'out' ORDER BY at_time, id");
    $stmt->execute([$day]);
    return $stmt->fetchAll();
}

function expenses_of_diary(int $diaryId): array
{
    $stmt = db()->prepare('SELECT * FROM expenses WHERE diary_id = ? ORDER BY day, at_time, id');
    $stmt->execute([$diaryId]);
    return $stmt->fetchAll();
}

/** 일기별 지출 합계 [diary_id => 합] */
function diary_spent(array $diaryIds): array
{
    $ids = array_filter(array_map('intval', $diaryIds));
    if (!$ids) return [];
    $out = [];
    foreach (db()->query('SELECT diary_id, SUM(amount) s FROM expenses WHERE diary_id IN (' . implode(',', $ids) . ") AND kind = 'out' GROUP BY diary_id") as $r) {
        $out[(int) $r['diary_id']] = (int) $r['s'];
    }
    return $out;
}

/** 한 달 예산 (0이면 안 씀) */
function ledger_budget(): int
{
    return (int) setting('ledger_budget', 0);
}
