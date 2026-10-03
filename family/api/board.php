<?php
// 전광판(board/)이 15초마다 읽는 가족 요약. version 이 같으면 화면을 다시 그리지 않는다.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/readiness.php';
require dirname(__DIR__) . '/lib/calendar.php';
require dirname(__DIR__) . '/lib/table.php';
require dirname(__DIR__) . '/lib/care.php';

require_login_api();
calendar_refresh_if_stale(300);

$today = today();
$iso = fn(string $datetime) => date('c', strtotime($datetime));

$events = array_map(fn($e) => [
    'title' => $e['title'], 'start' => $iso($e['start_at']), 'end' => $iso($e['end_at']),
    'allDay' => (bool) $e['all_day'], 'color' => $e['color'], 'location' => $e['location'],
], calendar_events($today, date('Y-m-d', strtotime('+7 day'))));

$dinners = [];
foreach (dinner_plans_between($today, date('Y-m-d', strtotime('+6 day'))) as $p) {
    $dinners[$p['day']] = ['dish' => $p['dish'], 'note' => $p['note'],
        'ingredients' => array_values(array_filter(array_map('trim', preg_split('/[,，]/u', $p['ingredients']))))];
}

$att = attendance($today);
$attendance = [];
foreach (members() as $m) {
    $a = $att[(int) $m['id']] ?? null;
    $attendance[] = ['name' => $m['name'], 'emoji' => $m['emoji'],
        'status' => $a ? $a['status'] : ($m['role'] === 'child' ? 'home' : 'unknown'),
        'late' => $a['late_time'] ?? ''];
}

$newFoods = db()->query("SELECT DISTINCT food FROM kid_reactions WHERE new_food = 1 AND day > DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY food LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$shopping = db()->query('SELECT name FROM shopping WHERE done = 0 ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);

$people = [];
foreach (members('adult') as $m) {
    $row = health_rows((int) $m['id'], 2)[$today] ?? null;
    $ready = readiness_history((int) $m['id'], 1)[$today] ?? null;
    $level = $ready ? readiness_level($ready['score']) : null;
    $meds = array_map(fn($x) => ['name' => $x['name'], 'time' => $x['time'], 'taken' => (bool) $x['taken_at']], medications_of((int) $m['id']));
    $stmt = db()->prepare('SELECT MAX(updated_at) FROM health_days WHERE member_id = ?');
    $stmt->execute([$m['id']]);
    $lastHealth = $stmt->fetchColumn();
    $people[] = [
        'meds' => $meds,
        'stale' => $lastHealth && strtotime($lastHealth) < time() - 48 * 3600,
        'name' => $m['name'], 'emoji' => $m['emoji'],
        'readiness' => $ready ? $ready['score'] : null,
        'level' => $level[0] ?? null, 'levelKey' => $level[1] ?? null,
        'steps' => isset($row['steps']) ? (int) $row['steps'] : null,
        'sleepMin' => isset($row['sleep_min']) ? (int) $row['sleep_min'] : null,
        'updatedAt' => $row ? $iso($row['updated_at']) : null,
    ];
}

$sick = [];
foreach (members('child') as $kid) {
    $logs = sick_logs((int) $kid['id'], 24);
    if (!$logs) continue;
    $t = last_temp($logs);
    $sick[] = [
        'name' => $kid['name'], 'emoji' => $kid['emoji'],
        'temp' => $t ? (float) $t['temp'] : null, 'at' => $t ? $iso($t['at']) : null,
        'next' => array_values(array_map(fn($n) => ['name' => $n['name'], 'at' => date('c', $n['at'])], fever_next($logs))),
    ];
}

// 📔 나들이 일기 사진 (하루 동안은 같은 순서, 화면에서 1분마다 넘김)
$memories = array_map(fn($r) => ['photo' => 'diary_photo.php?id=' . $r['id'], 'title' => $r['title'] ?: $r['place_name'], 'day' => $r['day']],
    db()->query('SELECT p.id, e.title, e.place_name, e.day FROM diary_photos p JOIN diary_entries e ON e.id = p.entry_id
        ORDER BY RAND(' . crc32($today) . ') LIMIT 24')->fetchAll());

$data = [
    'memories' => $memories,
    'sick' => $sick,
    'dinnerTime' => dinner_time(),
    'events' => $events,
    'dinners' => (object) $dinners,
    'attendance' => $attendance,
    'outcome' => dinner_outcome($today),
    'newFoods' => $newFoods,
    'shopping' => $shopping,
    'people' => $people,
    'locations' => locations(),
];
json_out(['ok' => true, 'version' => md5(json_encode($data, JSON_UNESCAPED_UNICODE))] + $data);
