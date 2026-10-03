<?php
// 나들이 일기: 다녀온 날의 사진 · 가족별 별점 · 글

const DIARY_PHOTO_MAX = 3 * 1024 * 1024;  // 사진 한 장 (휴대폰에서 1600px로 줄여서 올림)
const DIARY_THUMB_MAX = 400 * 1024;
const DIARY_PHOTOS_PER_ENTRY = 30;

function diary_entry(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM diary_entries WHERE id = ?');
    $stmt->execute([$id]);
    $e = $stmt->fetch();
    return $e ? diary_decorate([$e])[0] : null;
}

/** 일기 목록 (최신순). 연도를 주면 그해만 */
function diary_entries(?int $year = null, int $limit = 500): array
{
    $sql = 'SELECT * FROM diary_entries' . ($year ? ' WHERE YEAR(day) = ?' : '') . ' ORDER BY day DESC, id DESC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($year ? [$year] : []);
    return diary_decorate($stmt->fetchAll());
}

/** 별점 · 사진 수 · 대표 사진을 붙여 줌 */
function diary_decorate(array $entries): array
{
    if (!$entries) return [];
    $ids = array_map(fn($e) => (int) $e['id'], $entries);
    $in = implode(',', $ids);
    $ratings = [];
    foreach (db()->query("SELECT entry_id, member_id, stars FROM diary_ratings WHERE entry_id IN ($in)") as $r) {
        $ratings[(int) $r['entry_id']][(int) $r['member_id']] = (int) $r['stars'];
    }
    $photos = [];
    foreach (db()->query("SELECT id, entry_id FROM diary_photos WHERE entry_id IN ($in) ORDER BY sort, id") as $p) {
        $photos[(int) $p['entry_id']][] = (int) $p['id'];
    }
    foreach ($entries as &$e) {
        $id = (int) $e['id'];
        $e['ratings'] = $ratings[$id] ?? [];
        $e['avg'] = $e['ratings'] ? array_sum($e['ratings']) / count($e['ratings']) : null;
        $e['photos'] = $photos[$id] ?? [];
        $e['cover'] = $e['photos'][0] ?? null;
    }
    return $entries;
}

/** ★★★★☆ 처럼 (반 별 없이 반올림) */
function stars_text(?float $avg): string
{
    if ($avg === null) return '';
    $n = max(0, min(5, (int) round($avg)));
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}

/** 일기에 맞춰 나들이 '다녀옴' 기록을 만들거나 고침 (추천에서 최근 다녀온 곳 · 별점 반영) */
function diary_sync_visit(int $entryId): void
{
    $e = diary_entry($entryId);
    if (!$e) return;
    $rating = $e['avg'] !== null ? (int) round($e['avg']) : null;
    $memo = mb_substr($e['title'] ?: strip_tags((string) $e['body']), 0, 300);
    if ($e['place_id']) {
        if ($e['visit_log_id']) {
            db()->prepare("UPDATE outing_logs SET place_id = ?, day = ?, rating = ?, memo = ? WHERE id = ? AND kind = 'visit'")
                ->execute([$e['place_id'], $e['day'], $rating, $memo, $e['visit_log_id']]);
        } else {
            // 예전에 '다녀왔어요'로만 남긴 기록이 있으면 그것을 이어서 씀
            $stmt = db()->prepare("SELECT l.id FROM outing_logs l WHERE l.kind = 'visit' AND l.place_id = ? AND l.day = ?
                AND NOT EXISTS (SELECT 1 FROM diary_entries d WHERE d.visit_log_id = l.id) LIMIT 1");
            $stmt->execute([$e['place_id'], $e['day']]);
            if ($old = (int) $stmt->fetchColumn()) {
                db()->prepare('UPDATE diary_entries SET visit_log_id = ? WHERE id = ?')->execute([$old, $entryId]);
                db()->prepare('UPDATE outing_logs SET rating = ?, memo = ? WHERE id = ?')->execute([$rating, $memo, $old]);
                return;
            }
            db()->prepare("INSERT INTO outing_logs (place_id, day, kind, rating, memo, created_by, created_at) VALUES (?, ?, 'visit', ?, ?, ?, NOW())")
                ->execute([$e['place_id'], $e['day'], $rating, $memo, $e['created_by']]);
            db()->prepare('UPDATE diary_entries SET visit_log_id = ? WHERE id = ?')->execute([(int) db()->lastInsertId(), $entryId]);
        }
        $p = function_exists('place') ? place($e['place_id']) : null;
        if ($p && ($p['area'] ?? '') === 'pyeongtaek' && $e['day'] > (string) setting('parents_last_visit', '')) {
            set_setting('parents_last_visit', $e['day']);
        }
    } elseif ($e['visit_log_id']) {
        db()->prepare("DELETE FROM outing_logs WHERE id = ? AND kind = 'visit'")->execute([$e['visit_log_id']]);
        db()->prepare('UPDATE diary_entries SET visit_log_id = NULL WHERE id = ?')->execute([$entryId]);
    }
}

function diary_delete(int $id): void
{
    $e = diary_entry($id);
    if (!$e) return;
    if ($e['visit_log_id']) db()->prepare("DELETE FROM outing_logs WHERE id = ? AND kind = 'visit'")->execute([$e['visit_log_id']]);
    db()->prepare('DELETE FROM diary_photos WHERE entry_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM diary_ratings WHERE entry_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM diary_share_entries WHERE entry_id = ?')->execute([$id]);
    db()->prepare("DELETE FROM diary_shares WHERE kind = 'entry' AND entry_id = ?")->execute([$id]);
    db()->prepare('DELETE FROM diary_entries WHERE id = ?')->execute([$id]);
}

/** data:image/jpeg;base64,... → JPEG 바이트 (아니면 null) */
function jpeg_from_data_url(string $data, int $max): ?string
{
    if (!preg_match('#^data:image/jpeg;base64,(.+)$#s', $data, $m)) return null;
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || strlen($bytes) > $max || substr($bytes, 0, 2) !== "\xFF\xD8") return null;
    return $bytes;
}

function diary_add_photo(int $entryId, string $photo, string $thumb, int $by): int
{
    $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM diary_photos WHERE entry_id = ' . $entryId)->fetchColumn();
    $stmt = db()->prepare('INSERT INTO diary_photos (entry_id, sort, photo, thumb, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->bindValue(1, $entryId, PDO::PARAM_INT);
    $stmt->bindValue(2, $sort, PDO::PARAM_INT);
    $stmt->bindValue(3, $photo, PDO::PARAM_LOB);
    $stmt->bindValue(4, $thumb, PDO::PARAM_LOB);
    $stmt->bindValue(5, $by, PDO::PARAM_INT);
    $stmt->execute();
    return (int) db()->lastInsertId();
}

/** 장소별 가족 평균 별점 (추천 점수에 씀) [place_id => [avg, 다녀온 횟수, 또 가고 싶다 횟수]] */
function place_ratings(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = db()->query('SELECT e.place_id, AVG(x.a) a, COUNT(*) n, SUM(e.again) g FROM diary_entries e
            LEFT JOIN (SELECT entry_id, AVG(stars) a FROM diary_ratings GROUP BY entry_id) x ON x.entry_id = e.id
            WHERE e.place_id IS NOT NULL GROUP BY e.place_id');
        foreach ($rows as $r) $cache[$r['place_id']] = [$r['a'] === null ? null : (float) $r['a'], (int) $r['n'], (int) $r['g']];
    } catch (Throwable $e) {
        // 일기 표가 아직 없을 때
    }
    return $cache;
}

/** 그날 날씨 한 줄 (예보 캐시에 있으면) */
function diary_weather_for(string $day): string
{
    if (!function_exists('daily_forecast')) return '';
    $home = locations()['home'] ?? null;
    if (!$home) return '';
    $wx = daily_forecast((float) $home['lat'], (float) $home['lon'])[$day] ?? null;
    return $wx ? $wx['icon'] . ' ' . $wx['text'] . ' ' . round($wx['max']) . '°/' . round($wx['min']) . '°' : '';
}

/** 계획했던 나들이 중 날짜가 지났는데 일기가 없는 것 (최근 2주) */
function diary_pending_plans(): array
{
    return db()->query("SELECT l.* FROM outing_logs l
        WHERE l.kind = 'plan' AND l.day < CURDATE() AND l.day >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
          AND NOT EXISTS (SELECT 1 FROM diary_entries e WHERE e.place_id = l.place_id AND e.day = l.day)
        ORDER BY l.day DESC")->fetchAll();
}

// ───────── 공유 링크 ─────────

function share_url(array $share): string
{
    return public_base() . '/s.php?t=' . $share['token'];
}

function share_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    $stmt = db()->prepare('SELECT * FROM diary_shares WHERE token = ?');
    $stmt->execute([$token]);
    $s = $stmt->fetch();
    return $s ?: null;
}

/** 지금 열 수 있는 공유인지 (중지 · 만료 확인) */
function share_active(array $share): bool
{
    return !$share['revoked'] && (!$share['expires_at'] || strtotime($share['expires_at']) > time());
}

/** 이 공유로 볼 수 있는 일기 id 목록 */
function share_entry_ids(array $share): array
{
    if ($share['kind'] === 'entry') return $share['entry_id'] ? [(int) $share['entry_id']] : [];
    if ($share['album_all']) return array_map('intval', db()->query('SELECT id FROM diary_entries ORDER BY day DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN));
    $stmt = db()->prepare('SELECT e.id FROM diary_share_entries s JOIN diary_entries e ON e.id = s.entry_id WHERE s.share_id = ? ORDER BY e.day DESC, e.id DESC');
    $stmt->execute([$share['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function share_create(array $o, int $by): array
{
    $token = bin2hex(random_bytes(16));
    $expires = (int) ($o['days'] ?? 0) > 0 ? date('Y-m-d H:i:s', strtotime('+' . (int) $o['days'] . ' day')) : null;
    db()->prepare('INSERT INTO diary_shares (token, kind, entry_id, title, album_all, show_body, show_kid, show_names, expires_at, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$token, $o['kind'], $o['entry_id'] ?? null, mb_substr((string) ($o['title'] ?? ''), 0, 100), !empty($o['album_all']) ? 1 : 0,
            !empty($o['show_body']) ? 1 : 0, !empty($o['show_kid']) ? 1 : 0, !empty($o['show_names']) ? 1 : 0, $expires, $by]);
    $id = (int) db()->lastInsertId();
    foreach ($o['entries'] ?? [] as $eid) {
        db()->prepare('INSERT IGNORE INTO diary_share_entries (share_id, entry_id) VALUES (?, ?)')->execute([$id, (int) $eid]);
    }
    return share_by_token($token);
}

/** 공유 목록 (일기 하나로 좁힐 수 있음) */
function shares_list(?int $entryId = null): array
{
    $sql = 'SELECT s.*, e.title e_title, e.place_name e_place, e.day e_day FROM diary_shares s LEFT JOIN diary_entries e ON e.id = s.entry_id';
    $sql .= $entryId ? ' WHERE s.kind = \'entry\' AND s.entry_id = ' . $entryId : '';
    return db()->query($sql . ' ORDER BY s.revoked, s.id DESC')->fetchAll();
}
