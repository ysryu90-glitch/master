<?php
// 할 일: 내 것 · 가족(같이) · 날짜와 시각 · 매일 / 매주 / 매달 반복

const TODO_REPEATS = ['' => '반복 안 함', 'daily' => '매일', 'weekly' => '매주', 'monthly' => '매달'];

/** 「내일 세탁소」 「금요일 택배 반품」 처럼 앞에 날짜 말이 있으면 날짜로 바꾸기 → [제목, 날짜|null] */
function todo_parse(string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $words = ['오늘' => 0, '내일' => 1, '모레' => 2];
    foreach ($words as $w => $n) {
        if (preg_match('/^' . $w . '\s+(.+)$/u', $text, $m)) return [$m[1], date('Y-m-d', strtotime("+$n day"))];
    }
    $days = ['일' => 0, '월' => 1, '화' => 2, '수' => 3, '목' => 4, '금' => 5, '토' => 6];
    if (preg_match('/^(다음\s*주\s*)?([일월화수목금토])요일\s+(.+)$/u', $text, $m)) {
        $target = $days[$m[2]];
        $diff = ($target - (int) date('w') + 7) % 7;
        if ($m[1] !== '') $diff += 7; // 「다음 주 금요일」
        return [$m[3], date('Y-m-d', strtotime("+$diff day"))];
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\s+(.+)$/u', $text, $m) && checkdate((int) $m[1], (int) $m[2], (int) date('Y'))) {
        $d = sprintf('%s-%02d-%02d', date('Y'), $m[1], $m[2]);
        if ($d < date('Y-m-d', strtotime('-30 day'))) $d = sprintf('%d-%02d-%02d', (int) date('Y') + 1, $m[1], $m[2]);
        return [$m[3], $d];
    }
    return [$text, null];
}

/** 보이는 할 일 (안 한 것 전부 + 최근 2일 안에 한 것) — $who: 'me' | 'all' */
function todos_open(int $meId, string $who = 'me'): array
{
    $sql = 'SELECT * FROM todos WHERE (done = 0 OR done_at > DATE_SUB(NOW(), INTERVAL 2 DAY))'
        . ($who === 'me' ? ' AND (owner_id = ? OR owner_id IS NULL)' : '')
        . ' ORDER BY done, due_day IS NULL, due_day, due_time IS NULL, due_time, id';
    $stmt = db()->prepare($sql);
    $stmt->execute($who === 'me' ? [$meId] : []);
    return $stmt->fetchAll();
}

/** 오늘까지 해야 하는 내 할 일 (홈 화면용) */
function todos_due_today(int $meId): array
{
    $stmt = db()->prepare('SELECT * FROM todos WHERE done = 0 AND due_day IS NOT NULL AND due_day <= CURDATE() AND (owner_id = ? OR owner_id IS NULL) ORDER BY due_day, due_time IS NULL, due_time, id');
    $stmt->execute([$meId]);
    return $stmt->fetchAll();
}

/** 묶음 이름: 지났어요 · 오늘 · 내일 · 이번 주 · 나중에 · 날짜 없음 */
function todo_bucket(array $t): string
{
    if (!$t['due_day']) return '언젠가';
    $today = today();
    if ($t['due_day'] < $today) return '지났어요';
    if ($t['due_day'] === $today) return '오늘';
    if ($t['due_day'] === date('Y-m-d', strtotime('+1 day'))) return '내일';
    if ($t['due_day'] <= date('Y-m-d', strtotime('+6 day'))) return '이번 주';
    return '나중에';
}

/** 날짜를 짧게: 오늘 · 내일 · 10/9 (금) */
function todo_day_label(?string $day): string
{
    if (!$day) return '';
    if ($day === today()) return '오늘';
    if ($day === date('Y-m-d', strtotime('+1 day'))) return '내일';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return '어제';
    return date('n/j', strtotime($day)) . ' (' . weekday_short($day) . ')';
}

/** 다 했다고 표시 · 되돌리기. 반복이면 다음 날짜로 새로 만듦 */
function todo_toggle(int $id, int $meId): ?array
{
    $stmt = db()->prepare('SELECT * FROM todos WHERE id = ?');
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t) return null;
    if ((int) $t['done']) {
        db()->prepare('UPDATE todos SET done = 0, done_at = NULL, done_by = NULL WHERE id = ?')->execute([$id]);
        return $t + ['now_done' => false];
    }
    db()->prepare('UPDATE todos SET done = 1, done_at = NOW(), done_by = ? WHERE id = ?')->execute([$meId, $id]);
    $next = null;
    if ($t['repeat_rule'] !== '' && isset(TODO_REPEATS[$t['repeat_rule']])) {
        $base = $t['due_day'] ?: today();
        $step = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$t['repeat_rule']];
        $next = date('Y-m-d', strtotime($base . ' ' . $step));
        while ($next < today()) $next = date('Y-m-d', strtotime($next . ' ' . $step)); // 오래 밀린 반복은 오늘 이후로
        db()->prepare('INSERT INTO todos (title, note, owner_id, due_day, due_time, repeat_rule, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$t['title'], $t['note'], $t['owner_id'], $next, $t['due_time'], $t['repeat_rule'], $t['created_by']]);
    }
    return $t + ['now_done' => true, 'next' => $next];
}
