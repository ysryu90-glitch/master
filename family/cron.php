<?php
// NAS 작업 스케줄러가 5~10분마다 부르는 정기 작업 (알림 보내기).
// http://127.0.0.1:8080/family/cron.php?key=(config.php 의 secret)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/push.php';
require __DIR__ . '/lib/readiness.php';
require __DIR__ . '/lib/table.php';
require __DIR__ . '/lib/care.php';
require __DIR__ . '/lib/calendar.php';
require __DIR__ . '/lib/discover.php';

header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals((string) (cfg()['secret'] ?? ''), (string) ($_GET['key'] ?? ''))) {
    echo "forbidden\n";
    exit;
}
calendar_refresh_if_stale();
require_once __DIR__ . '/lib/ledger.php';
if (($fixed = ledger_recurring_fill()) > 0) echo "가계부 고정 지출 {$fixed}건\n";

$now = time();
$today = today();
$log = [];

/** 정한 시각(HH:MM)이 지났고 3시간 안이면 true (NAS가 꺼졌다 켜졌을 때 늦은 알림 방지) */
function due(string $hhmm, int $now): bool
{
    if (!preg_match('/^\d{2}:\d{2}$/', $hhmm)) return false;
    $at = strtotime(date('Y-m-d') . " $hhmm:00");
    return $now >= $at && $now < $at + 3 * 3600;
}

foreach (members('adult') as $m) {
    $id = (int) $m['id'];
    if (!has_push($id)) continue; // 기기를 등록하지 않은 사람은 건너뜀 (등록하면 그날 알림부터 받아요)
    $prefs = notify_prefs($id);

    // 💊 복약 (정각 + 1시간 뒤 한 번 더)
    foreach (medications_of($id) as $med) {
        if ($med['taken_at']) continue;
        if (due($med['time'], $now) && notify_once($id, 'med', $med['id'] . ':' . $today)) {
            $log[] = "$m[name] 약 알림 → " . push_to_member($id, "💊 {$med['name']} 드실 시간이에요", "{$med['time']} · 드시고 '먹었어요'를 눌러 주세요", 'index.php#meds', 'med' . $med['id']);
        }
        $later = date('H:i', strtotime("$today {$med['time']}:00") + 3600);
        if (due($later, $now) && notify_once($id, 'med2', $med['id'] . ':' . $today)) {
            $log[] = "$m[name] 약 재알림 → " . push_to_member($id, "💊 {$med['name']} 아직 안 드셨어요", '잊지 말고 챙겨 드세요', 'index.php#meds', 'med' . $med['id']);
        }
    }

    // ☀️ 아침 요약
    if ($prefs['morning'] && due($prefs['morning'], $now) && notify_once($id, 'morning', $today)) {
        $r = readiness_history($id, 1)[$today] ?? null;
        $events = calendar_events($today, $today);
        $plan = dinner_plan($today);
        $parts = [];
        $parts[] = $r ? sprintf('준비 점수 %.1f (%s)', $r['score'], readiness_level($r['score'])[0]) : '준비 점수는 단축어 실행 후 계산돼요';
        $parts[] = $events ? '일정 ' . count($events) . '개: ' . implode(', ', array_slice(array_map(fn($e) => ($e['all_day'] ? '' : substr($e['start_at'], 11, 5) . ' ') . $e['title'], $events), 0, 3)) : '오늘 일정 없음';
        if ($plan) $parts[] = '저녁 ' . $plan['dish'];
        $log[] = "$m[name] 아침 요약 → " . push_to_member($id, "☀️ 좋은 아침이에요, {$m['name']}님", implode(' · ', $parts), 'index.php', 'morning');
    }

    // 🍲 저녁 출석 묻기 (아직 안 정했을 때만)
    if ($prefs['dinner'] && due($prefs['dinner'], $now) && !isset(attendance($today)[$id]) && notify_once($id, 'dinner', $today)) {
        $plan = dinner_plan($today);
        $log[] = "$m[name] 저녁 묻기 → " . push_to_member($id, '🍲 오늘 저녁 집에서 드세요?', ($plan ? "메뉴: {$plan['dish']} · " : '') . '눌러서 집에서 / 늦어요 / 따로를 알려 주세요', 'index.php', 'dinner');
    }

    // ⚠️ 건강 기록 끊김 (정오에 하루 한 번)
    if ($prefs['stale'] && due('12:00', $now)) {
        $stmt = db()->prepare('SELECT MAX(updated_at) FROM health_days WHERE member_id = ?');
        $stmt->execute([$id]);
        $last = $stmt->fetchColumn();
        if ($last && strtotime($last) < $now - 48 * 3600 && notify_once($id, 'stale', $today)) {
            $log[] = "$m[name] 기록 끊김 → " . push_to_member($id, '⚠️ 건강 기록이 안 들어와요', '마지막 기록: ' . date('n월 j일 H:i', strtotime($last)) . ' · 단축어 자동화를 확인해 주세요', 'shortcut.php', 'stale');
        }
    }

    // 📊 일요일 저녁 주간 리포트
    if ($prefs['weekly'] && date('N') === '7' && due('20:00', $now) && notify_once($id, 'weekly', $today)) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM dinner_outcomes WHERE together = 1 AND day > DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $stmt->execute();
        $log[] = "$m[name] 주간 리포트 → " . push_to_member($id, '📊 이번 주 가족 리포트', '이번 주 함께한 저녁 ' . $stmt->fetchColumn() . '번 · 한 주를 돌아봐요', 'report.php', 'weekly');
    }

    // 🤒 아이 해열제 다시 먹일 수 있는 시각 (최근 3시간 안에 38도 이상일 때만)
    if ($prefs['sick']) {
        foreach (members('child') as $kid) {
            $logs = sick_logs((int) $kid['id'], 24);
            $t = last_temp($logs);
            if (!$t || (float) $t['temp'] < 38 || strtotime($t['at']) < $now - 3 * 3600) continue;
            $hasMed = array_filter($logs, fn($l) => $l['kind'] === 'med');
            if (!$hasMed) continue;
            foreach (fever_next($logs) as $group => $n) {
                if ($n['at'] <= $now && $n['at'] > $now - 3600 && notify_once($id, 'fever', $kid['id'] . ':' . $group . ':' . date('YmdH', $n['at']))) {
                    $log[] = "$m[name] 해열제 → " . push_to_member($id, "🤒 {$kid['name']} {$n['name']} 다시 먹일 수 있어요",
                        "마지막 체온 {$t['temp']}° (" . date('H:i', strtotime($t['at'])) . ') · 지금 체온을 재 보세요', 'sick.php?m=' . $kid['id'], 'fever');
                }
            }
        }
    }
}

// 🧺 나들이 데이터 (하루 한 번, 새벽 5시 이후)
try {
    if ($r = discover_daily()) $log[] = "나들이 데이터 → 축제·행사 {$r['festival']} · 서울 {$r['seoul']} · 새 장소 {$r['new']}" . ($r['errors'] ? ' (오류 ' . count($r['errors']) . ')' : '');
} catch (Throwable $e) {
    $log[] = '나들이 데이터 → 실패: ' . $e->getMessage();
}

db()->exec('DELETE FROM notify_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
set_setting('cron_last_run', date('Y-m-d H:i:s'));
echo "ok " . date('H:i') . "\n" . implode("\n", $log) . "\n";
