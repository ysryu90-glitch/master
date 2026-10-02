<?php
// 나들이 후보를 매일 새로: 축제 · 행사 (한국관광공사 TourAPI · 서울 문화행사) + 새로 등록된 장소 + 우리 장소
// 인증키는 설정 › 🧺 나들이 데이터에 넣는다 (DB settings: tourapi_key, seoul_key).

// 아이와 가기 좋은 행사인지 판단하는 단어
const KID_GOOD = ['어린이', '아이', '유아', '키즈', '가족', '체험', '동물', '곤충', '공룡', '과학', '놀이', '인형', '그림책', '동화',
    '꽃', '빛', '불꽃', '눈썰매', '썰매', '물놀이', '숲', '농장', '딸기', '고구마', '만들기', '인형극', '마술', '버블', '레고', '캐릭터', '피크닉'];
const KID_BAD = ['맥주', '와인', '막걸리', '주류', '소주', '양조', 'EDM', '클럽', '성인', '19세', '마라톤', '학술', '세미나', '포럼',
    '컨퍼런스', '심포지엄', '트레일러닝', '취업', '채용', '창업', '투자'];
const INDOOR_WORDS = ['전시', '박물관', '미술관', '과학관', '실내', '공연', '극장', '홀', '센터', '도서관', '갤러리', '뮤지컬', '연극', '콘서트', '아쿠아리움'];
const NEAR_GU = ['은평구', '서대문구', '마포구', '종로구', '중구', '용산구', '강서구', '성북구', '영등포구', '양천구'];

function km_between(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** 직선거리 → 차로 대략 몇 분 (도심 기준) */
function est_minutes(float $km): int
{
    return (int) round(8 + $km * 2.2);
}

/** [점수(-3~+2), 좋은 이유 단어들, 걸리는 단어] */
function kid_fit(string $text): array
{
    $good = array_values(array_filter(KID_GOOD, fn($w) => mb_stripos($text, $w) !== false));
    $bad = array_values(array_filter(KID_BAD, fn($w) => mb_stripos($text, $w) !== false));
    $score = min(2.0, count($good) * 0.7) - ($bad ? 3 : 0);
    return [$score, array_slice($good, 0, 3), $bad];
}

function guess_type(string $text): string
{
    foreach (INDOOR_WORDS as $w) if (mb_stripos($text, $w) !== false) return 'in';
    return 'out';
}

function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$status, $body === false ? '' : (string) $body, $error];
}

/** 공공데이터포털 오류 응답(XML)에서 사람이 읽을 메시지 */
function api_error_message(string $body): string
{
    foreach (['returnAuthMsg', 'resultMsg', 'errMsg', 'MESSAGE'] as $tag) {
        if (preg_match("#<$tag>(.*?)</$tag>#s", $body, $m)) return trim($m[1]);
    }
    return mb_substr(trim(strip_tags($body)), 0, 120);
}

/** TourAPI 호출 (KorService2 → 안 되면 KorService1). 반환: 항목 목록 */
function tourapi(string $op, array $params): array
{
    $key = trim((string) setting('tourapi_key', ''));
    if ($key === '') throw new RuntimeException('TourAPI 인증키가 없어요.');
    $keyParam = str_contains($key, '%') ? $key : rawurlencode($key);
    $last = '';
    foreach (['KorService2' => '2', 'KorService1' => '1'] as $svc => $suffix) {
        $url = setting('tourapi_base', 'https://apis.data.go.kr/B551011') . "/$svc/$op$suffix?serviceKey=$keyParam&MobileOS=ETC&MobileApp=FamilyHealth&_type=json&"
            . http_build_query($params);
        [$status, $body, $error] = http_get($url);
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['response']['body'])) {
            $items = $data['response']['body']['items']['item'] ?? [];
            if (!is_array($items)) return [];
            return isset($items['contentid']) ? [$items] : $items;
        }
        $msg = $error ?: ("HTTP $status " . api_error_message($body));
        if ($last === '' || $status !== 404) $last = $msg; // 인증키 오류 같은 첫 의미 있는 메시지를 남긴다
    }
    throw new RuntimeException('TourAPI: ' . trim($last) . (str_contains($last, 'SERVICE_KEY') ? ' (인증키 확인 · 승인 후 1시간쯤 걸릴 수 있어요)' : ''));
}

function ymd(?string $v): ?string
{
    if (!$v || !preg_match('/(\d{4})-?(\d{2})-?(\d{2})/', $v, $m)) return null;
    return "$m[1]-$m[2]-$m[3]";
}

function save_event(array $e): void
{
    db()->prepare('REPLACE INTO outing_events (id, source, kind, title, start_date, end_date, place, addr, lat, lon, target, fee, category, image, url, created, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$e['id'], $e['source'], $e['kind'], mb_substr($e['title'], 0, 200), $e['start'] ?? null, $e['end'] ?? null,
            mb_substr($e['place'] ?? '', 0, 200), mb_substr($e['addr'] ?? '', 0, 200), $e['lat'] ?? null, $e['lon'] ?? null,
            mb_substr($e['target'] ?? '', 0, 200), mb_substr($e['fee'] ?? '', 0, 100), mb_substr($e['category'] ?? '', 0, 60),
            mb_substr($e['image'] ?? '', 0, 300), mb_substr($e['url'] ?? '', 0, 300), $e['created'] ?? null]);
}

/** 하루 한 번: 축제 · 행사 · 새 장소를 받아 DB에 저장. 반환: 결과 요약 */
function discover_fetch(): array
{
    $locs = [];
    foreach (locations() as $l) $locs[$l['role']] = $l;
    $home = $locs['home'] ?? default_locations()[0];
    $parents = $locs['parents'] ?? default_locations()[2];
    $today = today();
    $horizon = date('Y-m-d', strtotime('+21 day'));
    $near = function (?float $lat, ?float $lon) use ($home, $parents): bool {
        if ($lat === null || $lon === null) return false;
        return km_between($home['lat'], $home['lon'], $lat, $lon) <= 35 || km_between($parents['lat'], $parents['lon'], $lat, $lon) <= 15;
    };
    $result = ['festival' => 0, 'new' => 0, 'seoul' => 0, 'errors' => []];

    // 1) TourAPI 축제 · 행사 (전국에서 받아 거리로 거름)
    if (setting('tourapi_key')) {
        try {
            $items = tourapi('searchFestival', ['numOfRows' => 1000, 'pageNo' => 1, 'arrange' => 'A',
                'eventStartDate' => date('Ymd', strtotime('-60 day'))]);
            foreach ($items as $it) {
                $start = ymd($it['eventstartdate'] ?? null);
                $end = ymd($it['eventenddate'] ?? null) ?? $start;
                $lat = isset($it['mapy']) && $it['mapy'] !== '' ? (float) $it['mapy'] : null;
                $lon = isset($it['mapx']) && $it['mapx'] !== '' ? (float) $it['mapx'] : null;
                if (!$start || $end < $today || $start > $horizon || !$near($lat, $lon)) continue;
                save_event(['id' => 'tour:' . $it['contentid'], 'source' => 'tour', 'kind' => 'event', 'title' => $it['title'] ?? '',
                    'start' => $start, 'end' => $end, 'addr' => $it['addr1'] ?? '', 'lat' => $lat, 'lon' => $lon,
                    'image' => $it['firstimage'] ?? '', 'category' => '축제', 'created' => ymd($it['createdtime'] ?? null)]);
                $result['festival']++;
            }
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        // 2) 집 · 부모님 댁 근처에 최근 1년 안에 새로 등록된 관광지 · 문화시설
        foreach ([[$home, 20000], [$parents, 10000]] as [$loc, $radius]) {
            foreach ([12, 14] as $type) {
                try {
                    $items = tourapi('locationBasedList', ['numOfRows' => 100, 'pageNo' => 1, 'arrange' => 'R', 'contentTypeId' => $type,
                        'mapX' => $loc['lon'], 'mapY' => $loc['lat'], 'radius' => $radius]);
                } catch (Throwable $e) {
                    $result['errors'][] = $e->getMessage();
                    break 2;
                }
                foreach ($items as $it) {
                    $created = ymd($it['createdtime'] ?? null);
                    if (!$created || $created < date('Y-m-d', strtotime('-365 day'))) continue;
                    save_event(['id' => 'tour:' . $it['contentid'], 'source' => 'tour', 'kind' => 'new', 'title' => $it['title'] ?? '',
                        'addr' => $it['addr1'] ?? '', 'lat' => (float) $it['mapy'], 'lon' => (float) $it['mapx'],
                        'image' => $it['firstimage'] ?? '', 'category' => $type === 14 ? '문화시설' : '관광지', 'created' => $created]);
                    $result['new']++;
                }
            }
        }
    }

    // 3) 서울 문화행사 (어린이 · 가족 · 누구나 대상, 집에서 가까운 것)
    if ($key = trim((string) setting('seoul_key', ''))) {
        for ($page = 0; $page < 3; $page++) {
            $from = $page * 1000 + 1;
            [$status, $body, $error] = http_get(setting('seoul_base', 'http://openapi.seoul.go.kr:8088') . '/' . rawurlencode($key) . "/json/culturalEventInfo/$from/" . ($from + 999) . '/');
            $data = json_decode($body, true);
            $rows = $data['culturalEventInfo']['row'] ?? null;
            if (!is_array($rows)) {
                $msg = $data['RESULT']['MESSAGE'] ?? $data['culturalEventInfo']['RESULT']['MESSAGE'] ?? ($error ?: api_error_message($body));
                if ($page === 0) $result['errors'][] = '서울 문화행사: ' . $msg;
                break;
            }
            foreach ($rows as $r) {
                $start = ymd($r['STRTDATE'] ?? null);
                $end = ymd($r['END_DATE'] ?? null) ?? $start;
                if (!$start || $end < $today || $start > $horizon) continue;
                $lat = is_numeric($r['LAT'] ?? null) ? (float) $r['LAT'] : null;
                $lon = is_numeric($r['LOT'] ?? null) ? (float) $r['LOT'] : null;
                if ($lat !== null && $lat > 100) [$lat, $lon] = [$lon, $lat]; // 위도 · 경도가 바뀌어 들어온 경우
                $target = (string) ($r['USE_TRGT'] ?? '');
                $text = ($r['TITLE'] ?? '') . ' ' . $target . ' ' . ($r['CODENAME'] ?? '') . ' ' . ($r['THEMECODE'] ?? '');
                $forKids = preg_match('/어린이|유아|가족|누구나|전체|제한 ?없/u', $target) || kid_fit($text)[0] > 0;
                $isNear = $lat !== null ? $near($lat, $lon) : in_array($r['GUNAME'] ?? '', NEAR_GU, true);
                if (!$forKids || !$isNear) continue;
                save_event(['id' => 'seoul:' . md5(($r['TITLE'] ?? '') . $start . ($r['PLACE'] ?? '')), 'source' => 'seoul', 'kind' => 'event',
                    'title' => $r['TITLE'] ?? '', 'start' => $start, 'end' => $end, 'place' => $r['PLACE'] ?? '', 'addr' => $r['GUNAME'] ?? '',
                    'lat' => $lat, 'lon' => $lon, 'target' => $target, 'fee' => ($r['IS_FREE'] ?? '') === '무료' ? '무료' : mb_substr((string) ($r['USE_FEE'] ?? ''), 0, 60),
                    'category' => $r['CODENAME'] ?? '', 'image' => $r['MAIN_IMG'] ?? '', 'url' => $r['ORG_LINK'] ?? ($r['HMPG_ADDR'] ?? '')]);
                $result['seoul']++;
            }
            if (count($rows) < 1000) break;
        }
    }

    db()->exec("DELETE FROM outing_events WHERE (kind = 'event' AND end_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY))
        OR (kind = 'new' AND fetched_at < DATE_SUB(NOW(), INTERVAL 30 DAY))");
    set_setting('discover_status', ['at' => date('Y-m-d H:i:s')] + $result);
    set_setting('discover_day', $today);
    return $result;
}

/** 그날 갈 수 있는 동적 후보 (축제 · 행사 · 새 장소 · 우리 장소) — PLACES 와 같은 모양 */
function discover_candidates(string $day, array $home, array $parents): array
{
    $out = [];
    $stmt = db()->prepare("SELECT * FROM outing_events WHERE (kind = 'event' AND start_date <= ? AND end_date >= ?) OR kind = 'new'");
    $stmt->execute([$day, $day]);
    foreach ($stmt->fetchAll() as $e) {
        $lat = $e['lat'] !== null ? (float) $e['lat'] : null;
        $lon = $e['lon'] !== null ? (float) $e['lon'] : null;
        $kmHome = $lat !== null ? km_between($home['lat'], $home['lon'], $lat, $lon) : 15.0;
        $kmPar = $lat !== null ? km_between($parents['lat'], $parents['lon'], $lat, $lon) : 99.0;
        $area = $kmHome > 40 && $kmPar <= 15 ? 'pyeongtaek' : 'home';
        $minutes = est_minutes($kmHome);
        if ($area === 'home' && $minutes > 80) continue;
        $text = $e['title'] . ' ' . $e['place'] . ' ' . $e['target'] . ' ' . $e['category'];
        [$fit, $good, $bad] = kid_fit($text);
        $where = trim($e['place'] ?: $e['addr']);
        $note = implode(' · ', array_filter([
            $e['kind'] === 'event' ? date('n/j', strtotime($e['start_date'])) . '~' . date('n/j', strtotime($e['end_date'])) : '',
            $where, $e['target'] ? '대상: ' . $e['target'] : '', $e['fee'],
        ]));
        $out[] = [
            'id' => $e['id'], 'name' => $e['title'], 'min' => $minutes, 'pmin' => est_minutes($kmPar), 'area' => $area,
            'type' => guess_type($text), 'tags' => $good, 'best' => [], 'note' => $note,
            'tip' => $e['kind'] === 'event' ? '행사 시간 · 예약은 주최 측 안내 확인' : '최근 관광 정보에 새로 등록된 곳이에요',
            'kind' => $e['kind'], 'start' => $e['start_date'], 'end' => $e['end_date'], 'fit' => $fit, 'bad' => $bad,
            'url' => $e['url'], 'source' => $e['source'],
        ];
    }
    foreach (db()->query('SELECT * FROM custom_places WHERE active = 1') as $c) {
        $out[] = [
            'id' => 'c' . $c['id'], 'name' => $c['name'], 'min' => (int) $c['minutes'], 'pmin' => (int) $c['minutes'], 'area' => $c['area'],
            'type' => $c['type'], 'tags' => [], 'best' => array_map('intval', array_filter(explode(',', $c['best']))),
            'note' => $c['note'], 'tip' => $c['tip'], 'kind' => 'custom',
        ];
    }
    return $out;
}

/** 다가오는 축제 · 행사 목록 (가까운 순) */
function upcoming_events(array $home, int $days = 21, int $limit = 12): array
{
    $stmt = db()->prepare("SELECT * FROM outing_events WHERE kind = 'event' AND end_date >= CURDATE() AND start_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    $rows = [];
    foreach ($stmt->fetchAll() as $e) {
        [$fit] = kid_fit($e['title'] . ' ' . $e['target'] . ' ' . $e['category']);
        if ($fit < 0) continue;
        $e['km'] = $e['lat'] !== null ? km_between($home['lat'], $home['lon'], (float) $e['lat'], (float) $e['lon']) : null;
        $e['fit'] = $fit;
        $rows[] = $e;
    }
    // 아이와 즐길 거리가 있는 행사 먼저, 그다음 가까운 순
    usort($rows, fn($a, $b) => [$b['fit'] > 0, $a['km'] ?? 99] <=> [$a['fit'] > 0, $b['km'] ?? 99]);
    return array_slice($rows, 0, $limit);
}

/** 하루 한 번 (새벽 5시 이후) 자동으로 받기 — cron.php 에서 호출 */
function discover_daily(): ?array
{
    if ((int) date('G') < 5 || setting('discover_day') === today()) return null;
    if (!setting('tourapi_key') && !setting('seoul_key')) return null;
    return discover_fetch();
}
