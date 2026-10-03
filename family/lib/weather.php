<?php
// 서버에서 받는 날씨 · 미세먼지 예보 (Open-Meteo, 키 필요 없음). 1시간 동안 DB에 보관.

const WEATHER_CODES = [
    0 => ['맑음', '☀️'], 1 => ['대체로 맑음', '🌤'], 2 => ['구름 조금', '⛅️'], 3 => ['흐림', '☁️'],
    45 => ['안개', '🌫'], 48 => ['안개', '🌫'], 51 => ['이슬비', '🌦'], 53 => ['이슬비', '🌦'], 55 => ['이슬비', '🌧'],
    56 => ['어는 비', '🌧'], 57 => ['어는 비', '🌧'], 61 => ['약한 비', '🌧'], 63 => ['비', '🌧'], 65 => ['강한 비', '🌧'],
    66 => ['어는 비', '🌧'], 67 => ['어는 비', '🌧'], 71 => ['약한 눈', '🌨'], 73 => ['눈', '❄️'], 75 => ['많은 눈', '❄️'], 77 => ['싸락눈', '🌨'],
    80 => ['소나기', '🌦'], 81 => ['소나기', '🌧'], 82 => ['강한 소나기', '⛈'], 85 => ['눈 소나기', '🌨'], 86 => ['눈 소나기', '🌨'],
    95 => ['뇌우', '⛈'], 96 => ['뇌우', '⛈'], 99 => ['뇌우', '⛈'],
];

function http_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * 날짜별 예보 [Y-m-d => [code, text, icon, max, min, rain, pm25, pm10]] (최대 10일, 미세먼지는 약 5일)
 */
function daily_forecast(float $lat, float $lon): array
{
    $key = 'wx_' . md5("$lat,$lon");
    $cached = setting($key);
    if (is_array($cached) && ($cached['at'] ?? 0) > time() - 3600) return $cached['days'];

    $q = "latitude=$lat&longitude=$lon&timezone=Asia%2FSeoul";
    $f = http_json("https://api.open-meteo.com/v1/forecast?$q&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max&forecast_days=10");
    if (!$f) {
        // 실패하면 10분 뒤에 다시 시도 (그동안 페이지가 매번 기다리지 않게)
        $old = is_array($cached) ? ($cached['days'] ?? []) : [];
        set_setting($key, ['at' => time() - 3000, 'days' => $old]);
        return $old;
    }
    $a = http_json("https://air-quality-api.open-meteo.com/v1/air-quality?$q&hourly=pm10,pm2_5&forecast_days=5");

    // 미세먼지는 낮 시간(9~18시) 평균
    $air = [];
    if ($a && isset($a['hourly']['time'])) {
        foreach ($a['hourly']['time'] as $i => $t) {
            $h = (int) substr($t, 11, 2);
            if ($h < 9 || $h > 18) continue;
            $d = substr($t, 0, 10);
            if ($a['hourly']['pm2_5'][$i] !== null) $air[$d]['pm25'][] = $a['hourly']['pm2_5'][$i];
            if ($a['hourly']['pm10'][$i] !== null) $air[$d]['pm10'][] = $a['hourly']['pm10'][$i];
        }
    }
    $days = [];
    foreach ($f['daily']['time'] as $i => $d) {
        $code = (int) $f['daily']['weather_code'][$i];
        [$text, $icon] = WEATHER_CODES[$code] ?? ['-', '🌡'];
        $days[$d] = [
            'code' => $code, 'text' => $text, 'icon' => $icon,
            'max' => (float) $f['daily']['temperature_2m_max'][$i],
            'min' => (float) $f['daily']['temperature_2m_min'][$i],
            'rain' => (int) ($f['daily']['precipitation_probability_max'][$i] ?? 0),
            'pm25' => isset($air[$d]['pm25']) ? array_sum($air[$d]['pm25']) / count($air[$d]['pm25']) : null,
            'pm10' => isset($air[$d]['pm10']) ? array_sum($air[$d]['pm10']) / count($air[$d]['pm10']) : null,
        ];
    }
    set_setting($key, ['at' => time(), 'days' => $days]);
    return $days;
}

/** 미세먼지 등급 0 좋음 · 1 보통 · 2 나쁨 · 3 매우 나쁨 */
function air_grade(?float $pm25, ?float $pm10): ?int
{
    if ($pm25 === null && $pm10 === null) return null;
    $g25 = $pm25 === null ? 0 : ($pm25 <= 15 ? 0 : ($pm25 <= 35 ? 1 : ($pm25 <= 75 ? 2 : 3)));
    $g10 = $pm10 === null ? 0 : ($pm10 <= 30 ? 0 : ($pm10 <= 80 ? 1 : ($pm10 <= 150 ? 2 : 3)));
    return max($g25, $g10);
}

const AIR_LABELS = ['좋음', '보통', '나쁨', '매우 나쁨'];

/** 공휴일 (대체공휴일 포함) */
const HOLIDAYS = [
    '2026-01-01' => '신정', '2026-02-16' => '설 연휴', '2026-02-17' => '설날', '2026-02-18' => '설 연휴', '2026-03-02' => '삼일절 대체',
    '2026-05-05' => '어린이날', '2026-05-25' => '부처님오신날 대체', '2026-06-03' => '지방선거', '2026-08-17' => '광복절 대체',
    '2026-09-24' => '추석 연휴', '2026-09-25' => '추석', '2026-09-26' => '추석 연휴', '2026-10-03' => '개천절', '2026-10-05' => '개천절 대체',
    '2026-10-09' => '한글날', '2026-12-25' => '성탄절',
    '2027-01-01' => '신정', '2027-02-06' => '설 연휴', '2027-02-07' => '설날', '2027-02-08' => '설 연휴', '2027-02-09' => '설 대체',
    '2027-03-01' => '삼일절', '2027-05-05' => '어린이날', '2027-05-13' => '부처님오신날', '2027-08-16' => '광복절 대체',
    '2027-09-14' => '추석 연휴', '2027-09-15' => '추석', '2027-09-16' => '추석 연휴', '2027-10-04' => '개천절 대체',
    '2027-10-11' => '한글날 대체', '2027-12-27' => '성탄절 대체',
];

function is_day_off(string $day): bool
{
    return (int) date('N', strtotime($day)) >= 6 || isset(HOLIDAYS[$day]);
}
