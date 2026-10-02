<?php
// 주말 나들이 후보 (5살 아이와 가기 좋은 곳). min = 은평구 집에서 차로 대략 몇 분, pmin = 평택 부모님 댁에서.
// type: out 야외 · in 실내 · mix 실내외. best = 특히 좋은 달. 운영 시간 · 예약은 가기 전에 꼭 확인.

const PLACES = [
    ['id' => 'seodaemun_nhm', 'name' => '서대문자연사박물관', 'min' => 15, 'type' => 'in', 'tags' => ['공룡', '박물관'], 'best' => [],
        'note' => '공룡 골격과 자연사 전시, 아이 눈높이 체험이 많아요', 'tip' => '월요일 휴관 · 주말 오전이 덜 붐벼요'],
    ['id' => 'eunpyeong_hanok', 'name' => '은평역사한옥박물관 · 은평한옥마을', 'min' => 15, 'type' => 'mix', 'tags' => ['한옥', '산책', '카페'], 'best' => [4, 5, 9, 10, 11],
        'note' => '한옥마을 산책하고 박물관 체험, 북한산이 보이는 카페', 'tip' => ''],
    ['id' => 'eunpyeong_mall', 'name' => '롯데몰 은평', 'min' => 10, 'type' => 'in', 'tags' => ['쇼핑몰', '키즈카페', '식당'], 'best' => [],
        'note' => '가까운 실내 플랜 B, 키즈카페와 식당이 한곳에', 'tip' => ''],
    ['id' => 'seooreung', 'name' => '서오릉', 'min' => 15, 'type' => 'out', 'tags' => ['숲길', '역사'], 'best' => [4, 5, 10, 11],
        'note' => '평탄한 숲길이라 아이와 천천히 걷기 좋아요', 'tip' => '월요일 휴무'],
    ['id' => 'jingwansa', 'name' => '진관사 계곡', 'min' => 15, 'type' => 'out', 'tags' => ['계곡', '물놀이'], 'best' => [7, 8],
        'note' => '여름엔 얕은 계곡에서 발 담그기 좋아요', 'tip' => '아쿠아슈즈 · 여벌 옷 챙기기', 'water' => true],
    ['id' => 'letsrun_wondang', 'name' => '렛츠런팜 원당 (원당 종마목장)', 'min' => 25, 'type' => 'out', 'tags' => ['말', '넓은 잔디', '무료'], 'best' => [4, 5, 6, 9, 10],
        'note' => '말 구경하고 넓은 잔디밭에서 뛰어놀기', 'tip' => '운영 요일 확인'],
    ['id' => 'starfield_goyang', 'name' => '스타필드 고양', 'min' => 25, 'type' => 'in', 'tags' => ['쇼핑몰', '키즈 놀이', '식당'], 'best' => [],
        'note' => '비 오는 날 하루 종일 실내에서 놀기', 'tip' => '주말 주차 혼잡'],
    ['id' => 'worldcup_park', 'name' => '월드컵공원 · 하늘공원', 'min' => 20, 'type' => 'out', 'tags' => ['억새', '잔디', '자전거'], 'best' => [4, 5, 9, 10, 11],
        'note' => '평화의공원 잔디밭이 넓고, 가을엔 하늘공원 억새', 'tip' => '하늘공원은 계단 · 맹꽁이 전기차'],
    ['id' => 'nanji_hangang', 'name' => '난지한강공원', 'min' => 20, 'type' => 'out', 'tags' => ['한강', '물놀이장', '피크닉'], 'best' => [5, 6, 7, 8, 9],
        'note' => '여름엔 물놀이장, 봄가을엔 그늘막 피크닉', 'tip' => '물놀이장은 여름 시즌만', 'water' => true],
    ['id' => 'seoul_botanic', 'name' => '서울식물원', 'min' => 30, 'type' => 'mix', 'tags' => ['온실', '정원'], 'best' => [],
        'note' => '큰 유리 온실은 비 와도 좋고 겨울에도 따뜻해요', 'tip' => '월요일 휴관(온실)'],
    ['id' => 'aviation_museum', 'name' => '국립항공박물관', 'min' => 30, 'type' => 'in', 'tags' => ['비행기', '체험'], 'best' => [],
        'note' => '진짜 비행기 구경과 조종 체험, 김포공항 옆', 'tip' => '체험은 예약이 필요할 수 있어요'],
    ['id' => 'folk_children', 'name' => '국립민속박물관 어린이박물관', 'min' => 25, 'type' => 'in', 'tags' => ['옛날놀이', '체험', '경복궁'], 'best' => [],
        'note' => '옛날 생활 체험하고 나와서 경복궁 산책', 'tip' => '사전 예약 확인'],
    ['id' => 'children_science', 'name' => '국립어린이과학관', 'min' => 30, 'type' => 'in', 'tags' => ['과학', '체험'], 'best' => [],
        'note' => '유아 눈높이 과학 놀이 공간', 'tip' => '회차별 예약'],
    ['id' => 'national_museum_kids', 'name' => '국립중앙박물관 어린이박물관', 'min' => 35, 'type' => 'in', 'tags' => ['박물관', '체험', '넓은 정원'], 'best' => [],
        'note' => '체험 위주 전시, 거울못 산책까지 하루 코스', 'tip' => '어린이박물관 예약'],
    ['id' => 'dream_forest', 'name' => '북서울꿈의숲', 'min' => 35, 'type' => 'out', 'tags' => ['숲', '놀이터', '단풍'], 'best' => [4, 5, 10, 11],
        'note' => '놀이터가 좋고 가을 단풍이 예뻐요', 'tip' => ''],
    ['id' => 'ilsan_lake', 'name' => '일산 호수공원', 'min' => 35, 'type' => 'out', 'tags' => ['호수', '자전거', '놀이터'], 'best' => [4, 5, 6, 9, 10],
        'note' => '넓은 호수 산책길과 놀이터, 봄 꽃 축제', 'tip' => '자전거 · 킥보드 챙기기'],
    ['id' => 'aquaplanet_ilsan', 'name' => '아쿠아플라넷 일산', 'min' => 35, 'type' => 'in', 'tags' => ['수족관'], 'best' => [],
        'note' => '펭귄과 물고기 구경, 2~3시간 코스', 'tip' => '온라인 예매가 저렴해요'],
    ['id' => 'heyri', 'name' => '파주 헤이리 예술마을', 'min' => 45, 'type' => 'mix', 'tags' => ['체험 공방', '작은 박물관', '카페'], 'best' => [4, 5, 9, 10],
        'note' => '작은 박물관과 체험 공방이 모여 있어 골라 다니기 좋아요', 'tip' => ''],
    ['id' => 'seoul_forest', 'name' => '서울숲', 'min' => 45, 'type' => 'out', 'tags' => ['숲', '꽃사슴', '피크닉'], 'best' => [4, 5, 9, 10],
        'note' => '꽃사슴 구경과 넓은 잔디 피크닉', 'tip' => ''],
    ['id' => 'children_grand_park', 'name' => '서울어린이대공원', 'min' => 50, 'type' => 'out', 'tags' => ['동물', '놀이터', '무료'], 'best' => [4, 5, 6, 9, 10],
        'note' => '작은 동물원과 놀이터, 입장 무료', 'tip' => '안의 서울상상나라(실내)와 묶으면 비 와도 OK'],
    ['id' => 'seoul_sangsang', 'name' => '서울상상나라', 'min' => 50, 'type' => 'in', 'tags' => ['놀이 체험', '키즈'], 'best' => [],
        'note' => '5살에 딱 맞는 놀이형 체험관', 'tip' => '회차 예약'],
    ['id' => 'seoul_zoo', 'name' => '서울대공원 동물원', 'min' => 60, 'type' => 'out', 'tags' => ['동물', '많이 걸음'], 'best' => [4, 5, 6, 9, 10],
        'note' => '동물이 많고 규모가 커요', 'tip' => '유모차 · 킥보드 챙기기', 'long' => true],
    ['id' => 'gwacheon_science', 'name' => '국립과천과학관', 'min' => 60, 'type' => 'in', 'tags' => ['과학', '천체관', '유아체험관'], 'best' => [],
        'note' => '유아체험관이 따로 있어 하루 종일 놀기 좋아요', 'tip' => '유아체험관 예약', 'long' => true],
    ['id' => 'lotte_world', 'name' => '롯데월드 어드벤처', 'min' => 60, 'type' => 'in', 'tags' => ['놀이공원'], 'best' => [],
        'note' => '실내라 날씨 상관없고 유아 놀이기구도 많아요', 'tip' => '입장료 부담 · 오픈 시간에 맞춰 가기', 'long' => true],
    ['id' => 'hangang_sled', 'name' => '한강 눈썰매장 (뚝섬 · 여의도)', 'min' => 40, 'type' => 'out', 'tags' => ['눈썰매'], 'best' => [12, 1, 2],
        'note' => '겨울 시즌에만 열리는 눈썰매장', 'tip' => '운영 기간 확인', 'season_only' => [12, 1, 2]],
    // 평택 부모님 댁 근처
    ['id' => 'pt_farm', 'name' => '평택 농업생태원', 'min' => 80, 'pmin' => 15, 'area' => 'pyeongtaek', 'type' => 'out', 'tags' => ['동물 먹이주기', '체험'], 'best' => [4, 5, 6, 9, 10],
        'note' => '동물 먹이주기와 농촌 체험', 'tip' => ''],
    ['id' => 'pt_sopung', 'name' => '소풍정원 (평택)', 'min' => 85, 'pmin' => 15, 'area' => 'pyeongtaek', 'type' => 'out', 'tags' => ['꽃', '산책'], 'best' => [4, 5, 9, 10],
        'note' => '봄 유채꽃 · 가을 코스모스 산책', 'tip' => ''],
    ['id' => 'pt_lake', 'name' => '평택호 관광단지', 'min' => 90, 'pmin' => 25, 'area' => 'pyeongtaek', 'type' => 'out', 'tags' => ['호수', '놀이터', '산책'], 'best' => [4, 5, 6, 9, 10],
        'note' => '호숫가 산책과 놀이터', 'tip' => ''],
    ['id' => 'pt_baedari', 'name' => '배다리생태공원', 'min' => 85, 'pmin' => 10, 'area' => 'pyeongtaek', 'type' => 'out', 'tags' => ['산책', '생태'], 'best' => [4, 5, 9, 10],
        'note' => '부모님 댁 가까운 가벼운 산책 코스', 'tip' => ''],
    ['id' => 'starfield_anseong', 'name' => '스타필드 안성', 'min' => 85, 'pmin' => 20, 'area' => 'pyeongtaek', 'type' => 'in', 'tags' => ['쇼핑몰', '키즈 놀이'], 'best' => [],
        'note' => '부모님과 함께 가기 좋은 실내 코스', 'tip' => ''],
];

const MONTH_THEMES = [
    1 => '한겨울 · 실내와 눈썰매', 2 => '늦겨울 · 실내 위주', 3 => '이른 봄 · 미세먼지 확인', 4 => '벚꽃 · 꽃구경', 5 => '나들이 최고의 달',
    6 => '초여름 · 그늘 있는 곳', 7 => '한여름 · 물놀이', 8 => '한여름 · 물놀이와 실내', 9 => '초가을 · 피크닉', 10 => '가을 · 억새와 단풍',
    11 => '늦가을 · 단풍', 12 => '겨울 · 실내와 눈썰매',
];

function place(string $id): ?array
{
    foreach (PLACES as $p) if ($p['id'] === $id) return $p;
    return null;
}

/**
 * 하루 추천 점수와 이유
 * $ctx: wx(그날 예보), month, busy(그날 일정 수), parentsDay(평택 가는 날), tired(컨디션 낮음), recent(최근 다녀온 곳 id => 일수), liked(찜 id 목록)
 */
function score_place(array $p, array $ctx): array
{
    $score = 0.0;
    $why = [];
    $minus = [];
    $wx = $ctx['wx'];
    $month = $ctx['month'];
    $area = $p['area'] ?? 'home';
    $minutes = ($ctx['parentsDay'] && $area === 'pyeongtaek') ? ($p['pmin'] ?? $p['min']) : $p['min'];

    if (!empty($p['season_only']) && !in_array($month, $p['season_only'], true)) return ['score' => -99, 'why' => [], 'minus' => ['지금은 운영 안 함'], 'minutes' => $minutes];
    if (!empty($p['water']) && !in_array($month, [6, 7, 8], true)) { $score -= 3; $minus[] = '물놀이 철이 아님'; }

    // 날씨
    if ($wx) {
        $rain = $wx['rain'];
        $snowOrRain = in_array($wx['code'], [61, 63, 65, 66, 67, 71, 73, 75, 77, 80, 81, 82, 85, 86, 95, 96, 99], true);
        $air = air_grade($wx['pm25'], $wx['pm10']);
        if ($rain >= 60 || $snowOrRain) {
            if ($p['type'] === 'in') { $score += 4; $why[] = '비 와도 실내'; }
            elseif ($p['type'] === 'mix') { $score += 1; $why[] = '실내 공간이 있어요'; }
            else { $score -= 5; $minus[] = "비 {$rain}%"; }
        } elseif ($rain >= 30) {
            if ($p['type'] === 'in') { $score += 1.5; $why[] = '혹시 비 와도 OK'; }
            elseif ($p['type'] === 'out') { $score -= 1; $minus[] = "비 {$rain}%"; }
        }
        if ($wx['max'] >= 31) {
            if (!empty($p['water']) && in_array($month, [6, 7, 8], true)) { $score += 3; $why[] = '더운 날 물놀이'; }
            elseif ($p['type'] === 'in') { $score += 1.5; $why[] = '더위 피하기'; }
            elseif ($p['type'] === 'out') { $score -= 2; $minus[] = '낮 ' . round($wx['max']) . '°'; }
        } elseif ($wx['max'] <= 3) {
            if ($p['type'] === 'in') { $score += 1.5; $why[] = '추운 날 실내'; }
            elseif ($p['type'] === 'out' && empty($p['season_only'])) { $score -= 2; $minus[] = '낮 ' . round($wx['max']) . '°로 추움'; }
        }
        if ($air !== null && $air >= 2) {
            if ($p['type'] === 'out') { $score -= 3; $minus[] = '미세먼지 ' . AIR_LABELS[$air]; }
            elseif ($p['type'] === 'in') { $score += 1.5; $why[] = '미세먼지 피하기'; }
        }
        $nice = $rain < 30 && $wx['max'] >= 15 && $wx['max'] <= 27 && ($air === null || $air <= 1);
        if ($nice && $p['type'] === 'out') { $score += 2.5; $why[] = '야외 나가기 좋은 날씨'; }
        elseif ($nice && $p['type'] === 'mix') { $score += 1.5; $why[] = '날씨 좋은 날 산책 겸'; }
        elseif ($nice && $p['type'] === 'in') { $score -= 0.5; }
    }

    // 계절
    if (in_array($month, $p['best'], true)) { $score += 2; $why[] = MONTH_THEMES[$month]; }

    // 거리 · 일정 · 컨디션
    $score -= $minutes / 25;
    if ($minutes <= 20 && !$ctx['parentsDay']) $why[] = "가까워요 (약 {$minutes}분)";
    if ($ctx['busy'] >= 1 && $minutes > 30) { $score -= 2; $minus[] = '그날 다른 일정이 있어 멀어요'; }
    if ($ctx['busy'] >= 1 && $minutes <= 20) { $score += 1; $why[] = '다른 일정 사이에 다녀오기 좋아요'; }
    if ($ctx['tired'] && (!empty($p['long']) || $minutes > 45)) { $score -= 1.5; $minus[] = '컨디션이 낮아 가볍게'; }
    if ($ctx['tired'] && $minutes <= 20) { $score += 0.5; }

    // 평택 가는 날이면 부모님 댁 근처 우선, 아니면 평택 쪽은 빼기
    if ($area === 'pyeongtaek') {
        if ($ctx['parentsDay']) { $score += 5; $why[] = '부모님 댁에서 가까워요 (약 ' . $minutes . '분)'; }
        else { $score -= 6; }
    } elseif ($ctx['parentsDay']) {
        $score -= 3;
    }

    // 최근 다녀온 곳 · 찜
    if (isset($ctx['recent'][$p['id']])) { $score -= 3; $minus[] = $ctx['recent'][$p['id']] . '일 전에 다녀옴'; }
    if (in_array($p['id'], $ctx['liked'], true)) { $score += 1; $why[] = '❤️ 찜한 곳'; }

    return ['score' => $score, 'why' => array_values(array_unique($why)), 'minus' => $minus, 'minutes' => $minutes];
}

function day_context(string $d, array $homeWx, array $ptWx, string $ptDay, bool $tired, array $recent, array $liked): array
{
    $events = calendar_events($d, $d);
    $parentsDay = $d === $ptDay;
    foreach ($events as $e) if (preg_match('/평택|부모님|시댁|친정/u', $e['title'])) $parentsDay = true;
    $busy = count(array_filter($events, fn($e) => !$e['all_day'] && !str_contains($e['title'], '나들이')));
    $wx = $parentsDay ? ($ptWx[$d] ?? $homeWx[$d] ?? null) : ($homeWx[$d] ?? null);
    return ['events' => $events, 'parentsDay' => $parentsDay, 'busy' => $busy, 'wx' => $wx,
        'month' => (int) date('n', strtotime($d)), 'tired' => $tired, 'recent' => $recent, 'liked' => $liked];
}

function ranked(array $ctx): array
{
    $list = [];
    foreach (PLACES as $p) {
        $s = score_place($p, $ctx);
        if ($s['score'] > -50) $list[] = $p + $s;
    }
    usort($list, fn($a, $b) => $b['score'] <=> $a['score']);
    return $list;
}
