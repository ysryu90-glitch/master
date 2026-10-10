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

// ───────── 식단 계획 · 메뉴 보관함 ─────────

/** 처음 쓸 때 넣어 주는 집밥 메뉴 (아이와 같이 먹기 좋은 것 위주) */
const RECIPE_STARTERS = [
    ['된장찌개 · 계란말이', '두부, 애호박, 감자, 양파, 된장, 계란', 1],
    ['카레라이스', '감자, 당근, 양파, 돼지고기, 카레 가루', 1],
    ['소고기 미역국', '미역, 소고기 국거리, 국간장', 1],
    ['불고기', '소고기 불고기용, 양파, 당근, 대파', 1],
    ['김치볶음밥 · 계란후라이', '김치, 햄, 계란, 대파', 0],
    ['오므라이스', '계란, 양파, 당근, 햄, 케첩', 1],
    ['닭볶음탕 (덜 맵게)', '닭 볶음탕용, 감자, 당근, 양파', 0],
    ['생선구이 · 두부조림', '고등어, 두부, 간장', 1],
    ['잡채', '당면, 시금치, 당근, 양파, 돼지고기, 어묵', 1],
    ['소고기무국', '무, 소고기 국거리, 대파', 1],
    ['짜장밥', '짜장 가루, 감자, 양파, 돼지고기, 애호박', 1],
    ['떡국', '떡국 떡, 소고기, 계란, 김', 1],
    ['감자조림 · 시금치나물', '감자, 시금치, 간장, 참기름', 0],
    ['돈가스', '돈가스, 양배추', 1],
    ['콩나물국 · 제육볶음', '콩나물, 돼지고기 앞다리, 양파, 고추장', 0],
    ['토마토 스파게티', '스파게티 면, 토마토 소스, 양파, 다진 고기', 1],
];

function recipes_seed(): void
{
    if (setting('recipes_seeded')) return;
    $ins = db()->prepare('INSERT IGNORE INTO recipes (name, ingredients, kid_ok, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    foreach (RECIPE_STARTERS as [$n, $i, $k]) $ins->execute([$n, $i, $k]);
    set_setting('recipes_seeded', 1);
}

/** 메뉴 보관함 + 마지막으로 먹은 날 · 먹은 횟수 · 아이가 잘 먹었는지 */
function recipes_all(): array
{
    recipes_seed();
    $rows = db()->query("SELECT r.*, (SELECT MAX(p.day) FROM dinner_plans p WHERE p.dish = r.name AND p.day <= CURDATE()) last_day,
        (SELECT COUNT(*) FROM dinner_plans p WHERE p.dish = r.name AND p.day <= CURDATE()) times FROM recipes r ORDER BY r.name")->fetchAll();
    $good = db()->query("SELECT food, COUNT(*) n FROM kid_reactions WHERE reaction = 'good' GROUP BY food")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as &$r) {
        $r['kid_good'] = 0;
        foreach (preg_split('/\s*[·,]\s*/u', $r['name']) as $part) $r['kid_good'] += (int) ($good[$part] ?? 0);
        $r['kid_like'] = $r['kid_ok'] || $r['kid_good'] > 0;
    }
    return $rows;
}

/** 저녁 메뉴를 정하면 보관함에도 (재료는 새로 적은 것으로) */
function recipe_remember(string $name, string $ingredients): void
{
    if ($name === '') return;
    db()->prepare('INSERT INTO recipes (name, ingredients, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE ingredients = IF(VALUES(ingredients) <> \'\', VALUES(ingredients), ingredients), updated_at = NOW()')->execute([mb_substr($name, 0, 100), mb_substr($ingredients, 0, 500)]);
}

/** 빈 날을 보관함 메뉴로 채우기: 10일 안에 먹은 · 정한 메뉴는 빼고, 아이가 잘 먹는 것 · 오래 안 먹은 것 먼저 */
function week_autofill(string $from, string $to, int $by): int
{
    $plans = dinner_plans_between(date('Y-m-d', strtotime("$from -10 day")), date('Y-m-d', strtotime("$to +10 day")));
    $recipes = recipes_all();
    $n = 0;
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if (isset($plans[$d])) continue;
        $near = [];
        foreach ($plans as $pd => $p) if (abs(strtotime($pd) - strtotime($d)) <= 10 * 86400) $near[$p['dish']] = true;
        $cands = array_values(array_filter($recipes, fn($r) => !isset($near[$r['name']])));
        if (!$cands) continue;
        usort($cands, function ($a, $b) use ($d) {
            $sa = ($a['kid_like'] ? 30 : 0) + min(60, $a['last_day'] ? (int) ((time() - strtotime($a['last_day'])) / 86400) : 45) + (crc32($d . $a['name']) % 25);
            $sb = ($b['kid_like'] ? 30 : 0) + min(60, $b['last_day'] ? (int) ((time() - strtotime($b['last_day'])) / 86400) : 45) + (crc32($d . $b['name']) % 25);
            return $sb <=> $sa;
        });
        $r = $cands[0];
        db()->prepare('INSERT IGNORE INTO dinner_plans (day, dish, ingredients, note, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$d, $r['name'], $r['ingredients'], '', $by]);
        $plans[$d] = ['dish' => $r['name']];
        $n++;
    }
    return $n;
}

/** 이 기간 메뉴 재료 (장보기에 아직 안 산 것으로 있는 건 빼고) */
function week_ingredients(string $from, string $to): array
{
    $have = array_map(fn($s) => mb_strtolower(trim($s)), db()->query('SELECT name FROM shopping WHERE done = 0')->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    foreach (dinner_plans_between($from, $to) as $p) {
        foreach (preg_split('/[,，\n]+/u', (string) $p['ingredients']) as $i) {
            $i = trim($i);
            if ($i === '' || in_array(mb_strtolower($i), $have, true)) continue;
            $out[mb_strtolower($i)] = $i;
        }
    }
    return array_values($out);
}
