<?php
// 준비 점수: 앱(Readiness.swift)과 같은 방식.
// HRV · 안정 시 심박수 · 수면 · 운동 부하 · 야간 호흡수를 최근 30일 개인 기준선과 비교해 0~10점으로 추정한다.
// 애플워치 공식 점수를 가끔 입력하면 그 점수에 맞게 보정한다.

const BASELINE_DAYS = 30;

/** 회원의 최근 `$days`일 건강 기록 (날짜 → 행) */
function health_rows(int $memberId, int $days = 75): array
{
    $stmt = db()->prepare('SELECT * FROM health_days WHERE member_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY day');
    $stmt->execute([$memberId, $days]);
    $rows = [];
    foreach ($stmt as $row) $rows[$row['day']] = $row;
    return $rows;
}

function r_mean(array $values): float
{
    return $values ? array_sum($values) / count($values) : 0.0;
}

function r_z(float $value, array $baseline): float
{
    $avg = r_mean($baseline);
    $var = 0.0;
    foreach ($baseline as $v) $var += ($v - $avg) ** 2;
    $var /= max(count($baseline) - 1, 1);
    // 기록이 너무 일정하면 작은 변화도 크게 보이므로 최소 편차를 둔다.
    $dev = max(sqrt($var), abs($avg) * 0.05, 0.001);
    return max(-2.5, min(2.5, ($value - $avg) / $dev));
}

function r_clamp(float $v): float { return max(0.0, min(100.0, $v)); }

/** `$day`의 값(또는 `$window`일 전까지)과 그 이전 30일 기준선 */
function r_split(array $rows, string $field, string $day, int $window = 0): ?array
{
    $today = null;
    for ($i = 0; $i <= $window; $i++) {
        $d = date('Y-m-d', strtotime("$day -$i day"));
        if (isset($rows[$d][$field]) && $rows[$d][$field] !== null) { $today = [$d, (float) $rows[$d][$field]]; break; }
    }
    if (!$today) return null;
    $start = date('Y-m-d', strtotime("{$today[0]} -" . BASELINE_DAYS . ' day'));
    $baseline = [];
    foreach ($rows as $d => $row) {
        if ($d >= $start && $d < $today[0] && $row[$field] !== null) $baseline[] = (float) $row[$field];
    }
    return ['today' => $today[1], 'baseline' => $baseline];
}

/** 하루 준비 점수 (보정 전). 수면이나 HRV를 포함해 요소가 2개 이상일 때만 */
function readiness_raw(array $rows, string $day): ?array
{
    $components = [];

    if (($hrv = r_split($rows, 'hrv', $day)) && count($hrv['baseline']) >= 5) {
        $z = r_z($hrv['today'], $hrv['baseline']);
        $components[] = ['kind' => 'hrv', 'title' => '심박 변이 (HRV)', 'score' => r_clamp(65 + $z * 20), 'weight' => 0.30,
            'detail' => sprintf('오늘 %dms · 평소 %dms', round($hrv['today']), round(r_mean($hrv['baseline'])))];
    }

    if (($rhr = r_split($rows, 'rhr', $day)) && count($rhr['baseline']) >= 5) {
        $z = r_z($rhr['today'], $rhr['baseline']);
        $components[] = ['kind' => 'rhr', 'title' => '안정 시 심박수', 'score' => r_clamp(65 - $z * 20), 'weight' => 0.20,
            'detail' => sprintf('오늘 %dBPM · 평소 %dBPM', round($rhr['today']), round(r_mean($rhr['baseline'])))];
    }

    // 수면: 4시간 → 0점, 8시간 이상 → 100점. 깊은+렘 수면이 25% 미만이면 감점
    $sleep = $rows[$day]['sleep_min'] ?? null;
    if ($sleep !== null && $sleep > 0) {
        $hours = $sleep / 60;
        $score = r_clamp(($hours - 4) / 4 * 100);
        $deep = (int) ($rows[$day]['deep_min'] ?? 0);
        $rem = (int) ($rows[$day]['rem_min'] ?? 0);
        if ($deep + $rem > 0 && ($deep + $rem) / $sleep < 0.25) $score -= 10;
        $components[] = ['kind' => 'sleep', 'title' => '수면', 'score' => r_clamp($score), 'weight' => 0.30,
            'detail' => sprintf('지난밤 %d시간 %d분', intdiv((int) $sleep, 60), (int) $sleep % 60)];
    }

    // 운동 부하: 최근 7일 평균 활동 에너지 ÷ 4주 평균
    $acute = $chronic = [];
    $last = null;
    foreach ($rows as $d => $row) {
        if ($d >= $day || $row['active_kcal'] === null) continue;
        $v = (float) $row['active_kcal'];
        if ($d >= date('Y-m-d', strtotime("$day -7 day"))) $acute[] = $v;
        if ($d >= date('Y-m-d', strtotime("$day -28 day"))) $chronic[] = $v;
        $last = $v;
    }
    if (count($acute) >= 3 && count($chronic) >= 10 && r_mean($chronic) > 0) {
        $ratio = r_mean($acute) / r_mean($chronic);
        $score = $ratio < 0.8 ? 90 : ($ratio < 1.3 ? 80 : ($ratio < 1.5 ? 55 : 30));
        if ($last !== null && $last > r_mean($chronic) * 1.8) $score -= 15;
        $components[] = ['kind' => 'load', 'title' => '운동 부하', 'score' => r_clamp($score), 'weight' => 0.10,
            'detail' => sprintf('최근 7일 %dkcal/일 · 4주 평균 %dkcal/일', round(r_mean($acute)), round(r_mean($chronic)))];
    }

    // 야간 호흡수: 평소보다 높으면 감점
    if (($resp = r_split($rows, 'resp', $day, 1)) && count($resp['baseline']) >= 5) {
        $dev = $resp['today'] - r_mean($resp['baseline']);
        $score = 100 - ($dev > 2 ? 30 : ($dev > 1 ? 15 : 0));
        $components[] = ['kind' => 'vitals', 'title' => '호흡수', 'score' => (float) $score, 'weight' => 0.10,
            'detail' => $dev > 1 ? '호흡수 평소보다 높음' : '호흡수 평소 수준'];
    }

    $kinds = array_column($components, 'kind');
    if (count($components) < 2 || (!in_array('sleep', $kinds, true) && !in_array('hrv', $kinds, true))) return null;

    $weight = array_sum(array_column($components, 'weight'));
    $weighted = 0.0;
    foreach ($components as $c) $weighted += $c['score'] * $c['weight'];
    $score = round($weighted / $weight) / 10;
    return ['day' => $day, 'score' => max(0.0, min(10.0, $score)), 'raw' => $score, 'components' => $components];
}

/** 최근 `$days`일 준비 점수 (공식 점수로 보정) */
function readiness_history(int $memberId, int $days = 30): array
{
    $rows = health_rows($memberId, $days + BASELINE_DAYS + 5);
    $history = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i day"));
        if ($r = readiness_raw($rows, $day)) $history[$day] = $r;
    }

    $stmt = db()->prepare('SELECT day, score FROM readiness_official WHERE member_id = ?');
    $stmt->execute([$memberId]);
    $official = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $pairs = [];
    foreach ($history as $day => $r) {
        if (isset($official[$day])) $pairs[] = [$r['raw'], (float) $official[$day]];
    }
    if (count($pairs) >= 3) {
        $appMean = r_mean(array_column($pairs, 0));
        $offMean = r_mean(array_column($pairs, 1));
        $slope = 1.0;
        if (count($pairs) >= 5) {
            $cov = $var = 0.0;
            foreach ($pairs as [$a, $o]) { $cov += ($a - $appMean) * ($o - $offMean); $var += ($a - $appMean) ** 2; }
            if ($var > 0.01) $slope = min(1.4, max(0.6, $cov / $var));
        }
        $intercept = $offMean - $slope * $appMean;
        foreach ($history as $day => $r) {
            $history[$day]['score'] = max(0.0, min(10.0, round(($slope * $r['raw'] + $intercept) * 10) / 10));
            $history[$day]['calibrated'] = true;
        }
    }
    return $history;
}

/** 기준선을 모으는 중인지 (기록된 날 수) */
function health_days_count(int $memberId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM health_days WHERE member_id = ? AND (hrv IS NOT NULL OR sleep_min IS NOT NULL)');
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}
