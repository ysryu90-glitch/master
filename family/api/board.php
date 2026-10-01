<?php
// 전광판(board/)이 1분마다 읽는 가족 요약. board.js가 읽는 형식에 맞춰 사람별로 묶어 보낸다.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/readiness.php';
require dirname(__DIR__) . '/lib/calendar.php';
require dirname(__DIR__) . '/lib/table.php';

require_login_api();
calendar_refresh_if_stale();

$today = today();
$iso = fn(string $datetime) => date('c', strtotime($datetime));

$events = array_map(fn($e) => [
    'title' => $e['title'], 'start' => $iso($e['start_at']), 'end' => $iso($e['end_at']),
    'allDay' => (bool) $e['all_day'], 'color' => $e['color'],
], calendar_events($today, date('Y-m-d', strtotime('+7 day'))));

$dinners = array_values(array_map(fn($p) => [
    'date' => $p['day'], 'dish' => $p['dish'],
    'ingredients' => array_values(array_filter(array_map('trim', preg_split('/[,，]/u', $p['ingredients'])))),
], dinner_plans_between($today, date('Y-m-d', strtotime('+6 day')))));

$open = db()->query('SELECT name FROM shopping WHERE done = 0 ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);
$att = attendance($today);
$attendance = [];
foreach (members() as $m) {
    $a = $att[(int) $m['id']] ?? null;
    $attendance[] = ['name' => $m['name'], 'emoji' => $m['emoji'],
        'status' => $a ? ATTENDANCE[$a['status']][1] . ($a['late_time'] ? ' ' . $a['late_time'] : '') : ($m['role'] === 'child' ? '함께' : '?')];
}
$stmt = db()->prepare("SELECT food FROM kid_reactions WHERE new_food = 1 AND day > DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 3");
$stmt->execute();
$newFoods = $stmt->fetchAll(PDO::FETCH_COLUMN);

$out = [];
foreach (members('adult') as $m) {
    $rows = health_rows((int) $m['id'], 2);
    $row = $rows[$today] ?? null;
    $ready = readiness_history((int) $m['id'], 1)[$today] ?? null;
    $updated = db()->prepare('SELECT MAX(updated_at) FROM health_days WHERE member_id = ?');
    $updated->execute([$m['id']]);
    $out[$m['slug']] = [
        'member' => $m['slug'],
        'name' => $m['name'],
        'emoji' => $m['emoji'],
        'updatedAt' => ($last = $updated->fetchColumn()) ? $iso((string) $last) : null,
        'dinnerTime' => dinner_time(),
        'events' => $events,
        'dinners' => $dinners,
        'shopping' => ['remaining' => count($open), 'items' => array_slice($open, 0, 12)],
        'health' => [
            'readiness' => $ready ? $ready['score'] : null,
            'readinessLevel' => $ready ? readiness_level($ready['score'])[0] : null,
            'steps' => isset($row['steps']) ? (int) $row['steps'] : null,
            'sleepHours' => isset($row['sleep_min']) ? round($row['sleep_min'] / 60, 1) : null,
        ],
        'locations' => locations(),
        'attendance' => $attendance,
        'kidNote' => $newFoods ? '⭐ 이번 주 새 음식 도전: ' . implode(', ', $newFoods) : '',
    ];
}
json_out(['ok' => true, 'members' => (object) $out]);
