<?php
// 복약 체크 · 아이 아플 때 (체온 · 해열제 간격)

// ───────── 복약 ─────────

function medications_of(int $memberId): array
{
    $stmt = db()->prepare('SELECT m.*, l.taken_at, l.taken_by FROM medications m
        LEFT JOIN medication_logs l ON l.med_id = m.id AND l.day = CURDATE()
        WHERE m.member_id = ? AND m.active = 1 AND (m.days = \'\' OR FIND_IN_SET(?, m.days)) ORDER BY m.time, m.id');
    $stmt->execute([$memberId, (string) date('w')]);
    return $stmt->fetchAll();
}

// ───────── 반복 알림 (약 · 영양제 · 물 · 아이 유산균 …) ─────────

const REMIND_PRESETS = [
    ['💊', '약'], ['💊', '영양제'], ['🧴', '유산균'], ['💧', '물 한 잔'], ['🦷', '치실'], ['🧘', '스트레칭'], ['🐶', '반려동물 밥'], ['🪴', '화분 물 주기'],
];
const WEEKDAYS_KO = ['일', '월', '화', '수', '목', '금', '토'];

/** 오늘 울릴 모든 알림 (가족 모두, 시각 순) */
function reminders_today(): array
{
    $stmt = db()->prepare('SELECT m.*, l.taken_at, l.taken_by FROM medications m
        LEFT JOIN medication_logs l ON l.med_id = m.id AND l.day = CURDATE()
        WHERE m.active = 1 AND (m.days = \'\' OR FIND_IN_SET(?, m.days)) ORDER BY m.time, m.id');
    $stmt->execute([(string) date('w')]);
    return $stmt->fetchAll();
}

function reminders_all(): array
{
    return db()->query('SELECT * FROM medications WHERE active = 1 ORDER BY member_id, time, id')->fetchAll();
}

/** "매일" · "평일" · "주말" · "월 · 수 · 금" */
function remind_days_label(string $days): string
{
    if ($days === '') return '매일';
    $d = array_map('intval', explode(',', $days));
    sort($d);
    if ($d === [1, 2, 3, 4, 5]) return '평일';
    if ($d === [0, 6]) return '주말';
    return implode(' · ', array_map(fn($x) => WEEKDAYS_KO[$x] ?? '', $d));
}

/** 최근 `$days`일 중 먹은 날 수 */
function medication_streak(int $medId, int $days = 7): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM medication_logs WHERE med_id = ? AND day > DATE_SUB(CURDATE(), INTERVAL ? DAY)');
    $stmt->execute([$medId, $days]);
    return (int) $stmt->fetchColumn();
}

// ───────── 해열제 ─────────
// 같은 성분 간격: 아세트아미노펜 4시간, 이부프로펜 계열 6시간. 다른 성분 교차 복용은 직전 해열제와 2시간 이상.
// 하루(24시간) 최대: 아세트아미노펜 5회, 이부프로펜 계열 4회.

const FEVER_MEDS = [
    'acet' => ['아세트아미노펜', '타이레놀 · 챔프(빨강) · 세토펜', 'acet'],
    'ibu' => ['이부프로펜', '부루펜 · 챔프(파랑)', 'ibu'],
    'dexi' => ['덱시부프로펜', '맥시부펜', 'ibu'],
    'other' => ['기타 약', '감기약 · 항생제 등 (해열제 간격 계산 안 함)', null],
];
const FEVER_RULES = [
    'acet' => ['interval' => 4, 'max' => 5, 'name' => '아세트아미노펜'],
    'ibu' => ['interval' => 6, 'max' => 4, 'name' => '이부프로펜 계열'],
];
const CROSS_HOURS = 2;

function sick_logs(int $kidId, int $hours = 48): array
{
    $stmt = db()->prepare('SELECT * FROM sick_logs WHERE member_id = ? AND at > DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY at DESC, id DESC');
    $stmt->execute([$kidId, $hours]);
    return $stmt->fetchAll();
}

/** 성분별 다음 복용 가능 시각과 이유 [group => ['at' => ts, 'why' => ..., 'count' => n]] */
function fever_next(array $logs): array
{
    $now = time();
    $doses = [];
    foreach ($logs as $l) {
        if ($l['kind'] !== 'med' || !isset(FEVER_MEDS[$l['med']]) || FEVER_MEDS[$l['med']][2] === null) continue;
        $doses[] = ['group' => FEVER_MEDS[$l['med']][2], 'at' => strtotime($l['at']), 'label' => FEVER_MEDS[$l['med']][0]];
    }
    $lastAny = $doses ? max(array_column($doses, 'at')) : null;
    $out = [];
    foreach (FEVER_RULES as $group => $rule) {
        $mine = array_values(array_filter($doses, fn($d) => $d['group'] === $group && $d['at'] > $now - 86400));
        usort($mine, fn($a, $b) => $b['at'] <=> $a['at']);
        $next = $now;
        $why = '지금 먹일 수 있어요';
        if ($mine) {
            $byInterval = $mine[0]['at'] + $rule['interval'] * 3600;
            if ($byInterval > $next) { $next = $byInterval; $why = "{$rule['name']}은 {$rule['interval']}시간 간격"; }
        }
        if ($lastAny !== null) {
            $byCross = $lastAny + CROSS_HOURS * 3600;
            if ($byCross > $next) { $next = $byCross; $why = '다른 해열제와 ' . CROSS_HOURS . '시간 간격'; }
        }
        if (count($mine) >= $rule['max']) {
            $oldest = $mine[$rule['max'] - 1]['at'] + 86400;
            if ($oldest > $next) { $next = $oldest; $why = "24시간 최대 {$rule['max']}회"; }
        }
        $out[$group] = ['at' => $next, 'why' => $why, 'count' => count($mine), 'name' => $rule['name'], 'max' => $rule['max']];
    }
    return $out;
}

function last_temp(array $logs): ?array
{
    foreach ($logs as $l) if ($l['kind'] === 'temp' && $l['temp'] !== null) return $l;
    return null;
}

function temp_class(float $t): string
{
    return $t >= 39 ? 'high' : ($t >= 38 ? 'fever' : ($t >= 37.5 ? 'warm' : 'normal'));
}
