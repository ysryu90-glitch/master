<?php
// 기념일 · 생일 D-day, 아이 성장 기록

const ANNIV_KINDS = ['birthday' => ['생일', '🎂'], 'anniv' => ['기념일', '💍'], 'day' => ['챙길 날', '🎉']];

/** 다가오는 기념일 (오늘 포함 $days일 안), 가까운 순 */
function anniv_upcoming(int $days = 400, ?string $today = null): array
{
    $today = $today ?? today();
    $t0 = strtotime($today);
    $out = [];
    try { $rows = db()->query('SELECT * FROM anniversaries')->fetchAll(); } catch (Throwable $e) { return []; }
    foreach ($rows as $a) {
        $y = (int) date('Y', $t0);
        foreach ([$y, $y + 1] as $yy) {
            $m = (int) $a['month'];
            $d = (int) $a['day'];
            if ($m === 2 && $d === 29 && !checkdate(2, 29, $yy)) $d = 28;
            $date = sprintf('%04d-%02d-%02d', $yy, $m, $d);
            if ($date < $today) continue;
            $dd = (int) round((strtotime($date) - $t0) / 86400);
            if ($dd > $days) break;
            $nth = $a['year'] ? $yy - (int) $a['year'] : null;
            $out[] = $a + ['date' => $date, 'dday' => $dd, 'nth' => $nth, 'label' => anniv_label($a, $nth)];
            break;
        }
    }
    usort($out, fn($x, $y) => $x['dday'] <=> $y['dday']);
    return $out;
}

function anniv_label(array $a, ?int $nth): string
{
    if (!$nth || $nth < 1) return $a['title'];
    if ($a['kind'] === 'birthday') return $a['title'] . ' (' . $nth . '번째 · 만 ' . $nth . '살)';
    if ($a['kind'] === 'anniv') return $a['title'] . ' ' . $nth . '주년';
    return $a['title'] . ' (' . $nth . '년째)';
}

function dday_text(int $d): string
{
    return $d === 0 ? 'D-DAY' : 'D-' . $d;
}

/** 만 나이 · 개월 (생일 기념일에서) */
function age_from(int $y, int $m, int $d, ?string $on = null): array
{
    $on = $on ?? today();
    $months = ((int) substr($on, 0, 4) - $y) * 12 + (int) substr($on, 5, 2) - $m - ((int) substr($on, 8, 2) < $d ? 1 : 0);
    return [intdiv($months, 12), $months];
}

/** 아이 생일 (기념일에 「생일」로 등록한 것 중 이름이 맞는 것) */
function member_birthday(array $member): ?array
{
    $st = db()->prepare("SELECT * FROM anniversaries WHERE kind = 'birthday' AND year IS NOT NULL AND title LIKE ? ORDER BY id LIMIT 1");
    $st->execute(['%' . $member['name'] . '%']);
    return $st->fetch() ?: null;
}

function growth_rows(int $memberId): array
{
    $st = db()->prepare('SELECT * FROM growth WHERE member_id = ? ORDER BY day, id');
    $st->execute([$memberId]);
    return $st->fetchAll();
}

/** 간단한 꺾은선 (SVG) */
function growth_svg(array $rows, string $key, string $unit, string $color): string
{
    $pts = array_values(array_filter($rows, fn($r) => $r[$key] !== null));
    if (count($pts) < 2) return '';
    $W = 320; $H = 120; $p = 14;
    $t = array_map(fn($r) => strtotime($r['day']), $pts);
    $v = array_map(fn($r) => (float) $r[$key], $pts);
    [$t0, $t1, $v0, $v1] = [min($t), max($t), min($v), max($v)];
    if ($t1 === $t0) $t1 = $t0 + 1;
    $pad = max(0.5, ($v1 - $v0) * 0.15);
    $v0 -= $pad; $v1 += $pad;
    $xy = [];
    foreach ($pts as $i => $r) $xy[] = [round($p + ($t[$i] - $t0) / ($t1 - $t0) * ($W - 2 * $p), 1), round($H - $p - ($v[$i] - $v0) / ($v1 - $v0) * ($H - 2 * $p), 1)];
    $path = 'M' . implode(' L', array_map(fn($q) => $q[0] . ' ' . $q[1], $xy));
    $dots = '';
    foreach ($xy as $i => $q) $dots .= '<circle cx="' . $q[0] . '" cy="' . $q[1] . '" r="3.5" fill="' . $color . '"><title>' . date('Y.n.j', $t[$i]) . ' ' . $v[$i] . $unit . '</title></circle>';
    $last = end($xy);
    return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="gsvg" role="img" aria-label="' . h($key) . ' 변화"><path d="' . $path . '" fill="none" stroke="' . $color . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' . $dots
        . '<text x="' . min($W - 4, $last[0]) . '" y="' . max(12, $last[1] - 8) . '" text-anchor="end" font-size="12" font-weight="700" fill="currentColor">' . end($v) . $unit . '</text></svg>';
}
