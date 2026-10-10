<?php
// 칭찬 스티커판 상태

const STICKER_REASONS = ['🪥 양치 잘함', '🥦 골고루 먹음', '🧸 정리 정돈', '😴 혼자 잠', '🙏 인사 잘함', '🤝 양보', '📚 책 읽기', '👗 혼자 옷 입기'];

/** ['count' => 붙인 수, 'goal' => 목표, 'reward' => 선물, 'done' => 지금까지 받은 횟수] */
function sticker_state(int $memberId): array
{
    $g = (setting('sticker_goals', []) ?: [])[$memberId] ?? [];
    $st = db()->prepare('SELECT COUNT(*) FROM stickers WHERE member_id = ? AND used = 0');
    $st->execute([$memberId]);
    return ['count' => (int) $st->fetchColumn(), 'goal' => (int) ($g['goal'] ?? 10), 'reward' => (string) ($g['reward'] ?? ''), 'done' => (int) ($g['done'] ?? 0)];
}

// ───────── 아이 루틴 (아침 · 잘 때) ─────────

const ROUTINE_SLOTS = ['am' => ['아침 루틴', '🌞'], 'pm' => ['잘 준비', '🌙']];
const ROUTINE_DEFAULTS = [
    'am' => [['🪥', '양치'], ['🧼', '세수'], ['👗', '옷 입기'], ['🎒', '가방 챙기기']],
    'pm' => [['🛁', '씻기'], ['🪥', '양치'], ['🧸', '장난감 정리'], ['📚', '책 읽기']],
];

/** 지금 보여 줄 루틴 (오후 3시 전은 아침) */
function routine_slot_now(): string
{
    return (int) date('G') < 15 ? 'am' : 'pm';
}

/** 아이 루틴 목록 (처음이면 기본 루틴을 만들어 줌) */
function routines_of(int $memberId): array
{
    $st = db()->prepare('SELECT * FROM routines WHERE member_id = ? ORDER BY slot, sort, id');
    $st->execute([$memberId]);
    $rows = $st->fetchAll();
    if (!$rows && !setting('routine_seeded_' . $memberId)) {
        $ins = db()->prepare('INSERT INTO routines (member_id, slot, title, emoji, sort) VALUES (?, ?, ?, ?, ?)');
        foreach (ROUTINE_DEFAULTS as $slot => $items) foreach ($items as $i => [$e, $t]) $ins->execute([$memberId, $slot, $t, $e, $i]);
        set_setting('routine_seeded_' . $memberId, 1);
        return routines_of($memberId);
    }
    return $rows;
}

/** 오늘 체크한 루틴 id → true */
function routine_checked(int $memberId, string $day): array
{
    $st = db()->prepare('SELECT c.routine_id FROM routine_checks c JOIN routines r ON r.id = c.routine_id WHERE r.member_id = ? AND c.day = ?');
    $st->execute([$memberId, $day]);
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** 체크 켜고 끄기 → 그 시간대를 다 했으면 스티커 한 장 (하루 한 번). [checked, slot 완료 여부, 스티커 줬는지] */
function routine_toggle(int $routineId, ?int $by = null): array
{
    $st = db()->prepare('SELECT * FROM routines WHERE id = ?');
    $st->execute([$routineId]);
    $r = $st->fetch();
    if (!$r) return [false, false, false];
    $day = today();
    $del = db()->prepare('DELETE FROM routine_checks WHERE routine_id = ? AND day = ?');
    $del->execute([$routineId, $day]);
    $checked = $del->rowCount() === 0;
    if ($checked) db()->prepare('INSERT INTO routine_checks (routine_id, day, checked_at) VALUES (?, ?, NOW())')->execute([$routineId, $day]);
    $mid = (int) $r['member_id'];
    $all = array_filter(routines_of($mid), fn($x) => $x['slot'] === $r['slot']);
    $done = routine_checked($mid, $day);
    $complete = $all && !array_filter($all, fn($x) => !isset($done[(int) $x['id']]));
    $gave = false;
    if ($complete) {
        $reason = ROUTINE_SLOTS[$r['slot']][1] . ' ' . ROUTINE_SLOTS[$r['slot']][0] . ' 끝';
        $q = db()->prepare('SELECT COUNT(*) FROM stickers WHERE member_id = ? AND reason = ? AND DATE(created_at) = ?');
        $q->execute([$mid, $reason, $day]);
        if (!(int) $q->fetchColumn()) {
            db()->prepare('INSERT INTO stickers (member_id, reason, created_by, created_at) VALUES (?, ?, ?, NOW())')->execute([$mid, $reason, $by]);
            $gave = true;
        }
    }
    return [$checked, $complete, $gave];
}
