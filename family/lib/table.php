<?php
// 가족 식탁 공통: 저녁 계획 · 출석 · 결과

const ATTENDANCE = [
    'home' => ['🏠 집에서 먹어요', '집에서'],
    'late' => ['🕗 늦어요', '늦게'],
    'out' => ['🙅 따로 먹어요', '따로'],
];

function dinner_plan(string $day): ?array
{
    $stmt = db()->prepare('SELECT * FROM dinner_plans WHERE day = ?');
    $stmt->execute([$day]);
    return $stmt->fetch() ?: null;
}

function dinner_plans_between(string $from, string $to): array
{
    $stmt = db()->prepare('SELECT * FROM dinner_plans WHERE day BETWEEN ? AND ?');
    $stmt->execute([$from, $to]);
    $plans = [];
    foreach ($stmt as $row) $plans[$row['day']] = $row;
    return $plans;
}

function attendance(string $day): array
{
    $stmt = db()->prepare('SELECT * FROM dinner_attendance WHERE day = ?');
    $stmt->execute([$day]);
    $rows = [];
    foreach ($stmt as $row) $rows[(int) $row['member_id']] = $row;
    return $rows;
}

function dinner_outcome(string $day): ?array
{
    $stmt = db()->prepare('SELECT * FROM dinner_outcomes WHERE day = ?');
    $stmt->execute([$day]);
    return $stmt->fetch() ?: null;
}

/** 저녁 시간대(17~21시)에 잡힌 다른 일정 (회식 등) */
function dinner_conflicts(string $day): array
{
    $stmt = db()->prepare("SELECT title, start_at FROM calendar_events WHERE all_day = 0 AND start_at BETWEEN ? AND ? ORDER BY start_at");
    $stmt->execute([$day . ' 17:00:00', $day . ' 20:59:59']);
    return array_map(fn($r) => substr($r['start_at'], 11, 5) . ' ' . $r['title'], $stmt->fetchAll());
}

function dinner_time(): string
{
    return (string) setting('dinner_time', '18:30');
}
