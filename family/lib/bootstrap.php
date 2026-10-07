<?php
// 우리집 건강 · 공통: 설정, DB, 로그인, 화면 틀
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');

// 밖에서 도메인으로 http 접속하면 https로 바꾼다 (집 안 IP · 8080 접속은 그대로)
if (PHP_SAPI !== 'cli' && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https'
    && preg_match('/^[a-z0-9.-]+\.(synology\.me|[a-z]{2,})$/i', (string) ($_SERVER['HTTP_HOST'] ?? ''))
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

const SCHEMA_VERSION = 13;
const SESSION_COOKIE = 'fam_sid';
const SESSION_DAYS = 180;

// ───────── 설정 · DB ─────────

function cfg(): array
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            fatal_page('config.php 가 없어요', 'Mac에서 scripts/make-family-config.sh 를 실행해 만든 family/config.php 를 NAS에 올려 주세요.');
        }
        $config = require $path;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $dsns = [];
    if (!empty($c['db_socket'])) {
        $dsns[] = "mysql:unix_socket={$c['db_socket']};dbname={$c['db_name']};charset=utf8mb4";
    }
    $dsns[] = "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4";
    $error = null;
    foreach ($dsns as $dsn) {
        try {
            $pdo = new PDO($dsn, $c['db_user'], $c['db_password'], $options);
            $pdo->exec("SET time_zone = '+09:00'");
            migrate($pdo);
            return $pdo;
        } catch (PDOException $e) {
            $error = $e;
        }
    }
    fatal_page('DB에 접속하지 못했어요', $error ? $error->getMessage() : '');
}

function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT NOT NULL) DEFAULT CHARSET = utf8mb4');
    $version = (int) ($pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn() ?: 0);
    if ($version >= SCHEMA_VERSION) return;

    $tables = [
        // 가족 구성원 (아이는 로그인 없이 기록 대상만)
        "CREATE TABLE IF NOT EXISTS members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(16) NOT NULL UNIQUE,
            name VARCHAR(40) NOT NULL,
            emoji VARCHAR(16) NOT NULL DEFAULT '',
            role VARCHAR(8) NOT NULL DEFAULT 'adult',
            password_hash VARCHAR(255) NULL,
            shortcut_token CHAR(32) NULL,
            kcal_target INT NOT NULL DEFAULT 2000,
            protein_target INT NOT NULL DEFAULT 60,
            sort INT NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) PRIMARY KEY,
            member_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            user_agent VARCHAR(200) NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            at DATETIME NOT NULL,
            KEY ip_at (ip, at)
        )",
        // 단축어로 받는 하루 건강 기록
        "CREATE TABLE IF NOT EXISTS health_days (
            member_id INT NOT NULL,
            day DATE NOT NULL,
            hrv DECIMAL(6,1) NULL,
            rhr DECIMAL(5,1) NULL,
            resp DECIMAL(4,1) NULL,
            sleep_min INT NULL,
            core_min INT NULL,
            deep_min INT NULL,
            rem_min INT NULL,
            steps INT NULL,
            active_kcal INT NULL,
            exercise_min INT NULL,
            weight DECIMAL(5,1) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (member_id, day)
        )",
        "CREATE TABLE IF NOT EXISTS health_raw (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            received_at DATETIME NOT NULL,
            body TEXT NOT NULL,
            KEY member_time (member_id, received_at)
        )",
        // 애플워치 공식 준비 점수 (보정용)
        "CREATE TABLE IF NOT EXISTS readiness_official (
            member_id INT NOT NULL,
            day DATE NOT NULL,
            score DECIMAL(3,1) NOT NULL,
            PRIMARY KEY (member_id, day)
        )",
        // 식단
        "CREATE TABLE IF NOT EXISTS meals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            day DATE NOT NULL,
            eaten_at TIME NULL,
            meal_type VARCHAR(12) NOT NULL,
            memo VARCHAR(500) NOT NULL DEFAULT '',
            photo MEDIUMBLOB NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY member_day (member_id, day)
        )",
        "CREATE TABLE IF NOT EXISTS meal_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meal_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            amount VARCHAR(40) NOT NULL DEFAULT '1인분',
            servings DECIMAL(4,2) NOT NULL DEFAULT 1,
            kcal DECIMAL(7,1) NOT NULL DEFAULT 0,
            carbs DECIMAL(6,1) NOT NULL DEFAULT 0,
            protein DECIMAL(6,1) NOT NULL DEFAULT 0,
            fat DECIMAL(6,1) NOT NULL DEFAULT 0,
            sodium DECIMAL(7,1) NOT NULL DEFAULT 0,
            sort INT NOT NULL DEFAULT 0,
            KEY meal (meal_id)
        )",
        // 우리집 음식 목록 (한 번 넣은 음식은 영양 정보와 함께 기억)
        "CREATE TABLE IF NOT EXISTS foods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            amount VARCHAR(40) NOT NULL DEFAULT '1인분',
            kcal DECIMAL(7,1) NOT NULL DEFAULT 0,
            carbs DECIMAL(6,1) NOT NULL DEFAULT 0,
            protein DECIMAL(6,1) NOT NULL DEFAULT 0,
            fat DECIMAL(6,1) NOT NULL DEFAULT 0,
            sodium DECIMAL(7,1) NOT NULL DEFAULT 0,
            uses INT NOT NULL DEFAULT 0,
            last_used DATETIME NULL
        )",
        // 가족 식탁
        "CREATE TABLE IF NOT EXISTS dinner_plans (
            day DATE PRIMARY KEY,
            dish VARCHAR(200) NOT NULL,
            ingredients VARCHAR(500) NOT NULL DEFAULT '',
            note VARCHAR(300) NOT NULL DEFAULT '',
            updated_by INT NULL,
            updated_at DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS dinner_attendance (
            day DATE NOT NULL,
            member_id INT NOT NULL,
            status VARCHAR(8) NOT NULL,
            late_time VARCHAR(5) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (day, member_id)
        )",
        "CREATE TABLE IF NOT EXISTS dinner_outcomes (
            day DATE PRIMARY KEY,
            together TINYINT NOT NULL DEFAULT 1,
            place VARCHAR(8) NOT NULL DEFAULT 'home',
            dish VARCHAR(200) NOT NULL DEFAULT '',
            note VARCHAR(300) NOT NULL DEFAULT '',
            updated_by INT NULL,
            updated_at DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS kid_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day DATE NOT NULL,
            member_id INT NOT NULL,
            food VARCHAR(100) NOT NULL,
            reaction VARCHAR(8) NOT NULL,
            new_food TINYINT NOT NULL DEFAULT 0,
            note VARCHAR(200) NOT NULL DEFAULT '',
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY kid_day (member_id, day)
        )",
        "CREATE TABLE IF NOT EXISTS shopping (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            done TINYINT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            done_at DATETIME NULL
        )",
        // iCloud 캘린더 일정 사본 (원본은 iCloud)
        "CREATE TABLE IF NOT EXISTS calendar_events (
            uid VARCHAR(255) NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            all_day TINYINT NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL,
            location VARCHAR(300) NOT NULL DEFAULT '',
            calendar VARCHAR(100) NOT NULL DEFAULT '',
            color VARCHAR(9) NOT NULL DEFAULT '#4da3ff',
            fetched_at DATETIME NOT NULL,
            PRIMARY KEY (uid, start_at)
        )",
        // 알림 (웹 푸시)
        "CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL UNIQUE,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(200) NOT NULL,
            auth VARCHAR(100) NOT NULL,
            user_agent VARCHAR(200) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            last_ok DATETIME NULL
        )",
        "CREATE TABLE IF NOT EXISTS notify_log (
            member_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            ref VARCHAR(60) NOT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (member_id, kind, ref)
        )",
        // 복약
        "CREATE TABLE IF NOT EXISTS medications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            name VARCHAR(60) NOT NULL,
            time CHAR(5) NOT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS medication_logs (
            med_id INT NOT NULL,
            day DATE NOT NULL,
            taken_at DATETIME NOT NULL,
            PRIMARY KEY (med_id, day)
        )",
        // 아이 아플 때 (체온 · 해열제)
        "CREATE TABLE IF NOT EXISTS sick_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            at DATETIME NOT NULL,
            kind VARCHAR(8) NOT NULL,
            temp DECIMAL(3,1) NULL,
            med VARCHAR(12) NULL,
            dose VARCHAR(30) NOT NULL DEFAULT '',
            note VARCHAR(200) NOT NULL DEFAULT '',
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY member_at (member_id, at)
        )",
        // 주말 나들이 (찜 · 계획 · 다녀옴)
        "CREATE TABLE IF NOT EXISTS outing_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            place_id VARCHAR(40) NOT NULL,
            day DATE NOT NULL,
            kind VARCHAR(8) NOT NULL,
            rating TINYINT NULL,
            memo VARCHAR(300) NOT NULL DEFAULT '',
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY place (place_id)
        )",
        // 나들이: 매일 받아오는 축제 · 행사 · 새 장소 (TourAPI · 서울 문화행사)
        "CREATE TABLE IF NOT EXISTS outing_events (
            id VARCHAR(60) PRIMARY KEY,
            source VARCHAR(10) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            title VARCHAR(200) NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            place VARCHAR(200) NOT NULL DEFAULT '',
            addr VARCHAR(200) NOT NULL DEFAULT '',
            lat DECIMAL(9,6) NULL,
            lon DECIMAL(9,6) NULL,
            target VARCHAR(200) NOT NULL DEFAULT '',
            fee VARCHAR(100) NOT NULL DEFAULT '',
            category VARCHAR(60) NOT NULL DEFAULT '',
            image VARCHAR(300) NOT NULL DEFAULT '',
            url VARCHAR(300) NOT NULL DEFAULT '',
            created DATE NULL,
            fetched_at DATETIME NOT NULL,
            KEY dates (end_date, start_date)
        )",
        // 가족이 직접 넣은 장소
        "CREATE TABLE IF NOT EXISTS custom_places (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(4) NOT NULL DEFAULT 'out',
            minutes INT NOT NULL DEFAULT 30,
            area VARCHAR(12) NOT NULL DEFAULT 'home',
            best VARCHAR(40) NOT NULL DEFAULT '',
            note VARCHAR(200) NOT NULL DEFAULT '',
            tip VARCHAR(200) NOT NULL DEFAULT '',
            active TINYINT NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL
        )",
        // 가족 일기 (일상 · 나들이): 글 · 가족별 별점 · 사진
        "CREATE TABLE IF NOT EXISTS diary_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(8) NOT NULL DEFAULT 'outing',
            day DATE NOT NULL,
            place_id VARCHAR(60) NULL,
            place_name VARCHAR(100) NOT NULL DEFAULT '',
            title VARCHAR(100) NOT NULL DEFAULT '',
            body TEXT NULL,
            kid_said VARCHAR(300) NOT NULL DEFAULT '',
            weather VARCHAR(60) NOT NULL DEFAULT '',
            again TINYINT NOT NULL DEFAULT 0,
            visit_log_id INT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY day (day),
            KEY place (place_id)
        )",
        "CREATE TABLE IF NOT EXISTS diary_ratings (
            entry_id INT NOT NULL,
            member_id INT NOT NULL,
            stars TINYINT NOT NULL,
            PRIMARY KEY (entry_id, member_id)
        )",
        "CREATE TABLE IF NOT EXISTS diary_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            photo MEDIUMBLOB NOT NULL,
            thumb MEDIUMBLOB NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY entry (entry_id, sort)
        )",
        // 일기 공유 링크 (로그인 없이 보기 전용)
        "CREATE TABLE IF NOT EXISTS diary_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(32) NOT NULL UNIQUE,
            kind VARCHAR(6) NOT NULL,
            entry_id INT NULL,
            title VARCHAR(100) NOT NULL DEFAULT '',
            album_all TINYINT NOT NULL DEFAULT 1,
            album_category VARCHAR(8) NOT NULL DEFAULT '',
            show_body TINYINT NOT NULL DEFAULT 1,
            show_kid TINYINT NOT NULL DEFAULT 1,
            show_names TINYINT NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            revoked TINYINT NOT NULL DEFAULT 0,
            views INT NOT NULL DEFAULT 0,
            last_view DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS diary_share_entries (
            share_id INT NOT NULL,
            entry_id INT NOT NULL,
            PRIMARY KEY (share_id, entry_id)
        )",
        // 가계부: 지출 · 수입 (직접 입력 · 카드 문자 · 애플페이 자동 입력), 일기와 연결 가능
        "CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day DATE NOT NULL,
            at_time TIME NULL,
            kind VARCHAR(3) NOT NULL DEFAULT 'out',
            amount INT NOT NULL,
            category VARCHAR(12) NOT NULL DEFAULT 'etc',
            merchant VARCHAR(100) NOT NULL DEFAULT '',
            memo VARCHAR(200) NOT NULL DEFAULT '',
            member_id INT NULL,
            card VARCHAR(40) NOT NULL DEFAULT '',
            source VARCHAR(8) NOT NULL DEFAULT 'manual',
            checked TINYINT NOT NULL DEFAULT 1,
            diary_id INT NULL,
            raw_hash CHAR(40) NULL UNIQUE,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY day (day),
            KEY diary (diary_id)
        )",
        // 할 일 (사람별 · 가족 같이, 날짜 · 시각 · 반복)
        "CREATE TABLE IF NOT EXISTS todos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            owner_id INT NULL,
            due_day DATE NULL,
            due_time CHAR(5) NULL,
            repeat_rule VARCHAR(8) NOT NULL DEFAULT '',
            done TINYINT NOT NULL DEFAULT 0,
            done_at DATETIME NULL,
            done_by INT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            KEY open_due (done, due_day)
        )",
        // 가계부 고정 지출 · 수입 (매달 같은 날 저절로 들어감: 통신비, 보험, 유치원비, 월급 …)
        "CREATE TABLE IF NOT EXISTS ledger_recurring (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dom TINYINT NOT NULL,
            kind VARCHAR(3) NOT NULL DEFAULT 'out',
            amount INT NOT NULL,
            category VARCHAR(12) NOT NULL DEFAULT 'etc',
            merchant VARCHAR(100) NOT NULL DEFAULT '',
            member_id INT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            start_day DATE NOT NULL,
            last_ym CHAR(7) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        )",
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql . ' DEFAULT CHARSET = utf8mb4');
    }
    // 예전 표에 새 칸 더하기
    $pdo->exec("ALTER TABLE diary_entries ADD COLUMN IF NOT EXISTS category VARCHAR(8) NOT NULL DEFAULT 'outing' AFTER id");
    $pdo->exec("ALTER TABLE diary_shares ADD COLUMN IF NOT EXISTS album_category VARCHAR(8) NOT NULL DEFAULT '' AFTER album_all");
    // 알림 기기별 마지막 보내기 결과 (알림이 안 올 때 이유를 보여 주려고)
    $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS last_try DATETIME NULL');
    $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS last_status INT NULL');
    $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS last_error VARCHAR(300) NOT NULL DEFAULT ''");
    // 나들이 예산 · 장보기 목록이 어느 결제에 들어갔는지
    $pdo->exec('ALTER TABLE outing_logs ADD COLUMN IF NOT EXISTS budget INT NULL');
    $pdo->exec('ALTER TABLE shopping ADD COLUMN IF NOT EXISTS expense_id INT NULL');
    $pdo->exec('ALTER TABLE shopping ADD COLUMN IF NOT EXISTS done_by INT NULL');
    // iCloud 일정 고치기 · 지우기에 필요한 것 (일정 파일 주소 · 반복 여부 · 원래 회차 · 메모)
    $pdo->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS href VARCHAR(500) NOT NULL DEFAULT ''");
    $pdo->exec('ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS recurring TINYINT NOT NULL DEFAULT 0');
    $pdo->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS occ VARCHAR(30) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS note VARCHAR(500) NOT NULL DEFAULT ''");
    $pdo->prepare("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)")->execute([(string) SCHEMA_VERSION]);
}

function setting(string $key, $default = null)
{
    $stmt = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if ($value === false) return $default;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

function set_setting(string $key, $value): void
{
    db()->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

// ───────── 도우미 ─────────

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function today(): string { return date('Y-m-d'); }

function valid_day(?string $day): string
{
    return ($day && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && strtotime($day)) ? $day : today();
}

function day_label(string $day): string
{
    $weekdays = ['일', '월', '화', '수', '목', '금', '토'];
    $ts = strtotime($day);
    $diff = (int) round((strtotime(today()) - $ts) / 86400);
    $base = date('n월 j일', $ts) . ' (' . $weekdays[(int) date('w', $ts)] . ')';
    if ($diff === 0) return '오늘 · ' . $base;
    if ($diff === 1) return '어제 · ' . $base;
    if ($diff === -1) return '내일 · ' . $base;
    return $base;
}

function weekday_short(string $day): string
{
    return ['일', '월', '화', '수', '목', '금', '토'][(int) date('w', strtotime($day))];
}

function num($value, int $decimals = 0): string
{
    return $value === null || $value === '' ? '-' : number_format((float) $value, $decimals);
}

function post(string $key, $default = '')
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function json_out(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function fatal_page(string $title, string $detail): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:-apple-system,sans-serif;padding:24px;line-height:1.6">'
        . '<h2>' . h($title) . '</h2><p>' . h($detail) . '</p></body>';
    exit;
}

// ───────── 구성원 ─────────

function members(?string $role = null): array
{
    $sql = 'SELECT * FROM members' . ($role ? ' WHERE role = ?' : '') . ' ORDER BY sort, id';
    $stmt = db()->prepare($sql);
    $stmt->execute($role ? [$role] : []);
    return $stmt->fetchAll();
}

function member(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ───────── 로그인 ─────────

function current_member(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $stmt = db()->prepare('SELECT m.*, s.token_hash FROM sessions s JOIN members m ON m.id = s.member_id
        WHERE s.token_hash = ? AND s.expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if ($row) {
        db()->prepare('UPDATE sessions SET last_seen = NOW() WHERE token_hash = ?')->execute([$row['token_hash']]);
        $cached = $row;
    }
    return $cached;
}

function require_login(): array
{
    if (!db()->query("SELECT COUNT(*) FROM members WHERE role = 'adult'")->fetchColumn()) {
        redirect('setup.php');
    }
    $me = current_member();
    if (!$me) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        redirect('login.php?next=' . urlencode($next));
    }
    return $me;
}

function require_login_api(): array
{
    $me = current_member();
    if (!$me) json_out(['ok' => false, 'error' => 'login']);
    return $me;
}

function start_session(int $memberId): void
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO sessions (token_hash, member_id, created_at, expires_at, last_seen, user_agent)
        VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . SESSION_DAYS . ' DAY), NOW(), ?)')
        ->execute([hash('sha256', $token), $memberId, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    setcookie(SESSION_COOKIE, $token, [
        'expires' => time() + SESSION_DAYS * 86400,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    db()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
}

function end_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token) db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    setcookie(SESSION_COOKIE, '', ['expires' => 1, 'path' => '/']);
}

function too_many_attempts(): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $stmt->execute([client_ip()]);
    return (int) $stmt->fetchColumn() >= 8;
}

function record_failed_attempt(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, NOW())')->execute([client_ip()]);
    db()->exec('DELETE FROM login_attempts WHERE at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

// 폼 위조 방지: 세션마다 고정된 값
function csrf_token(): string
{
    $token = $_COOKIE[SESSION_COOKIE] ?? 'anon';
    return hash_hmac('sha256', 'csrf', $token . (cfg()['secret'] ?? ''));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function check_csrf(): void
{
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), $sent)) {
        fatal_page('요청이 만료됐어요', '페이지를 새로고침한 뒤 다시 시도해 주세요.');
    }
}

// ───────── 화면 틀 ─────────

/**
 * 메뉴: 5개 묶음 (아래 탭 · 컴퓨터에서는 왼쪽 메뉴) + 묶음 안의 작은 탭
 * [이름, 아이콘, 첫 화면, [[화면, 이름, 아이콘], ...]]
 */
const NAV = [
    'home' => ['홈', '🏠', 'index.php', []],
    'family' => ['가족', '👨‍👩‍👧', 'todo.php', [['todo.php', '할 일', '✅'], ['calendar.php', '일정', '📅'], ['shop.php', '장보기', '🛒'], ['table.php', '오늘 저녁', '🍲']]],
    'diary' => ['일기', '📔', 'diary.php', [['diary.php', '일기', '📔'], ['outing.php', '나들이 추천', '🧺']]],
    'ledger' => ['가계부', '💰', 'ledger.php', [['ledger.php', '가계부', '💰'], ['ledger_guide.php', '가계부 설정', '⚙︎']]],
    'health' => ['건강', '❤️', 'health.php', [['health.php', '컨디션', '❤️'], ['meals.php', '식단', '🍚'], ['meds.php', '약', '💊'], ['sick.php', '아플 때', '🤒'], ['report.php', '리포트', '📊']]],
    'more' => ['더보기', '☰', 'more.php', [['more.php', '더보기', '☰'], ['settings.php', '설정', '⚙︎']]],
];

/** 아래 탭에 보이는 묶음 (더보기는 오른쪽 위 ☰ 버튼) */
const TABBAR = ['home', 'family', 'diary', 'ledger', 'health'];

/** 화면 → [묶음, 작은 탭 화면] (일기 쓰기 화면은 '일기' 탭에 속하는 식) */
const NAV_PAGES = [
    'index.php' => ['home', 'index.php'],
    'todo.php' => ['family', 'todo.php'], 'shop.php' => ['family', 'shop.php'],
    'table.php' => ['family', 'table.php'], 'meals.php' => ['health', 'meals.php'], 'meal_edit.php' => ['health', 'meals.php'],
    'diary.php' => ['diary', 'diary.php'], 'diary_view.php' => ['diary', 'diary.php'], 'diary_edit.php' => ['diary', 'diary.php'],
    'outing.php' => ['diary', 'outing.php'], 'diary_share.php' => ['diary', 'diary.php'],
    'ledger.php' => ['ledger', 'ledger.php'], 'ledger_guide.php' => ['ledger', 'ledger_guide.php'],
    'health.php' => ['health', 'health.php'], 'meds.php' => ['health', 'meds.php'], 'sick.php' => ['health', 'sick.php'], 'report.php' => ['health', 'report.php'],
    'more.php' => ['more', 'more.php'], 'family.php' => ['more', 'more.php'], 'calendar.php' => ['family', 'calendar.php'],
    'settings.php' => ['more', 'settings.php'], 'search.php' => ['more', 'more.php'], 'shortcut.php' => ['more', 'settings.php'],
];

/** 메뉴 아이콘 (선 아이콘, 고른 탭은 채움) */
function nav_icon(string $key, bool $on = false): string
{
    $paths = [
        'home' => $on ? '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z" fill="currentColor"/>'
                      : '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
        'family' => $on ? '<circle cx="9" cy="7.5" r="3.2" fill="currentColor"/><path d="M2.8 20c0-3.6 2.8-6.3 6.2-6.3s6.2 2.7 6.2 6.3z" fill="currentColor"/><circle cx="17.2" cy="9" r="2.5"/><path d="M17.2 14c2.4 0 4.3 2 4.3 4.7"/>'
                        : '<circle cx="9" cy="7.5" r="3.2"/><path d="M2.8 20c0-3.6 2.8-6.3 6.2-6.3s6.2 2.7 6.2 6.3"/><circle cx="17.2" cy="9" r="2.5"/><path d="M17.2 14c2.4 0 4.3 2 4.3 4.7"/>',
        'meal' => '<path d="M7 3v8M4.5 3v5a2.5 2.5 0 0 0 5 0V3M7 11v10M17 21V3c-2.2 1.2-3.5 3.6-3.5 7v3H17"/>',
        'diary' => $on ? '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5z" fill="currentColor"/><path d="M5 19.5A1.5 1.5 0 0 0 6.5 21H19v-3"/>'
                       : '<path d="M5 19.5V4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5zm0 0A1.5 1.5 0 0 0 6.5 21H19v-3M9 7.5h6"/>',
        'ledger' => $on ? '<rect x="3" y="6" width="18" height="14" rx="3" fill="currentColor"/><path d="M6 6V5a2 2 0 0 1 2-2h9" /><circle cx="16.5" cy="13" r="1.5" fill="var(--card)" stroke="none"/>'
                        : '<rect x="3" y="6" width="18" height="14" rx="3"/><path d="M6 6V5a2 2 0 0 1 2-2h9M16 13h.01"/><path d="M21 10h-4a3 3 0 0 0 0 6h4"/>',
        'health' => $on ? '<path d="M12 20s-7.5-4.5-7.5-10A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7.5 3c0 5.5-7.5 10-7.5 10z" fill="currentColor"/>'
                        : '<path d="M12 20s-7.5-4.5-7.5-10A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 7.5 3c0 5.5-7.5 10-7.5 10z"/><path d="M3 12h4l2-3 3 6 2-3h7"/>',
        'more' => '<circle cx="5" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="19" cy="12" r="1.6" fill="currentColor"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'back' => '<path d="M15 5l-7 7 7 7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$key] ?? '') . '</svg>';
}

function nav_current(): array
{
    return NAV_PAGES[basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')] ?? ['', ''];
}

/** 이 사이트의 주소 (예: https://mjys0307.synology.me/family) */
function site_base(): string
{
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

/** 밖에서도 되는 주소 (https 도메인으로 한 번이라도 접속했으면 그 주소) */
function public_base(): string
{
    return (string) setting('public_base', site_base());
}

function page_start(string $title, string $tab = '', array $options = []): void
{
    $me = current_member();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    // https 도메인으로 접속했으면 그 주소를 기억 (단축어 · 알림 주소에 사용)
    if (is_https() && !preg_match('/^\d+\.\d+\.\d+\.\d+/', (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        $base = preg_replace('#/(api|board)$#', '', site_base());
        if (setting('public_base') !== $base) set_setting('public_base', $base);
    }
    ob_start('page_polish');
    $flash = $_COOKIE['flash'] ?? '';
    if ($flash) setcookie('flash', '', ['expires' => 1, 'path' => '/']);
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="우리집">
<meta name="theme-color" content="#f2f4f6" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#101114" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon.png?v=<?= asset_version('assets/favicon.png') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon.png?v=<?= asset_version('assets/icon.png') ?>">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<link rel="stylesheet" href="assets/app.css?v=<?= asset_version('assets/app.css') ?>">
<title><?= h($title) ?> · 우리집</title>
</head>
<?php [$group, $sub] = nav_current(); $nav = $me && $group !== ''; $back = $options['back'] ?? ''; $GLOBALS['page_back'] = $back; ?>
<body class="<?= $nav ? ($back ? 'with-nav focus' : 'with-tabs with-nav') : '' ?>">
<?php if ($nav): ?>
<aside class="sidenav" aria-label="메뉴">
  <a class="brand" href="index.php"><img src="assets/icon.png" alt="" width="28" height="28"> 우리집</a>
  <a class="g sidesearch" href="search.php"><span class="i"><?= nav_icon('search') ?></span>찾기</a>
  <?php foreach (NAV as $key => [$label, $icon, $href, $items]): ?>
    <a class="g<?= $key === $group ? ' on' : '' ?>" href="<?= $href ?>"><span class="i"><?= nav_icon($key, $key === $group) ?></span><?= $label ?></a>
    <?php if (count($items) > 1): ?><div class="subs"><?php foreach ($items as [$ih, $il, $ii]): ?><a class="<?= $ih === $sub ? 'on' : '' ?>" href="<?= $ih ?>"><?= $il ?></a><?php endforeach; ?></div><?php endif; ?>
  <?php endforeach; ?>
  <a class="me" href="settings.php"><?= h($me['emoji'] . ' ' . $me['name']) ?> · 설정</a>
</aside>
<?php endif; ?>
<div class="shell">
<header class="topbar">
  <div class="topbar-title"><?php if ($back): ?><a class="back" href="<?= h($back) ?>" aria-label="뒤로" data-back onclick="if (document.referrer.indexOf(location.host) > 0 && history.length > 1) { history.back(); return false; }"><?= nav_icon('back') ?></a><?php endif; ?><?= h($title) ?></div>
  <?php if ($me): ?>
  <span class="topbar-r"><a class="topbar-search" href="search.php" aria-label="찾기"><?= nav_icon('search') ?></a>
  <a class="topbar-me<?= $group === 'more' ? ' on' : '' ?>" href="more.php" aria-label="더보기 · 설정"><span class="av"><?= h($me['emoji']) ?></span><span class="nm"><?= h($me['name']) ?></span><?= nav_icon('menu') ?></a></span>
  <?php endif; ?>
</header>
<?php if ($nav && !$back && count(NAV[$group][3]) > 1): ?>
<nav class="subnav" aria-label="<?= h(NAV[$group][0]) ?> 메뉴">
  <?php foreach (NAV[$group][3] as [$ih, $il, $ii]): ?><a class="<?= $ih === $sub ? 'on' : '' ?>" href="<?= $ih ?>"><?= $il ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="page<?= $options['class'] ?? '' ? ' ' . h($options['class']) : '' ?>">
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<?php
}

function page_end(string $tab = ''): void
{
    [$group] = nav_current();
    echo '</main></div>';
    if (current_member() && $group !== '' && empty($GLOBALS['page_back'])) {
        echo '<nav class="tabbar" aria-label="메뉴">';
        // 확인할 것이 있으면 탭에 빨간 점 (가계부: 항목을 못 정한 자동 기록)
        $dots = [];
        try { $dots['ledger'] = (int) db()->query('SELECT COUNT(*) FROM expenses WHERE checked = 0')->fetchColumn() > 0; } catch (Throwable $e) {}
        foreach (TABBAR as $key) {
            [$label, $icon] = NAV[$key];
            $href = $key === 'ledger' && !empty($dots['ledger']) ? 'ledger.php?review=1#review' : NAV[$key][2];
            $icon = nav_icon($key, $key === $group) . (!empty($dots[$key]) ? '<i class="dot"></i>' : '');
            echo '<a href="' . $href . '" class="' . ($key === $group ? 'on' : '') . '"' . ($key === $group ? ' aria-current="page"' : '') . '><span class="i">' . $icon . '</span>' . $label . '</a>';
        }
        echo '</nav>';
    }
    echo '<script src="assets/app.js?v=' . asset_version('assets/app.js') . '"></script></body></html>';
}

/** 카드 제목 앞 이모지를 둥근 배지에 담기 (<h2>☕ 제목 → 배지 + 제목) */
function page_polish(string $html): string
{
    $emoji = '(?:[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{2190}-\x{21FF}\x{25A0}-\x{25FF}\x{2934}\x{2935}\x{3297}\x{3299}](?:\x{FE0F}|\x{200D}[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]|[\x{1F3FB}-\x{1F3FF}])*)';
    $out = preg_replace('/(<h2(?:\s[^>]*)?>)\s*(' . $emoji . '+)\s*/u', '$1<span class="hic">$2</span>', $html);
    return $out ?? $html;
}

/** 파일이 바뀌면 주소도 바뀌게 해서 아이폰이 예전 파일을 쓰지 않게 */
function asset_version(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    return is_file($file) ? (string) filemtime($file) : '1';
}

function flash(string $message): void
{
    setcookie('flash', $message, ['expires' => time() + 30, 'path' => '/', 'samesite' => 'Lax']);
}

const MEAL_TYPES = [
    'breakfast' => ['아침', '🌅'],
    'lunch' => ['점심', '☀️'],
    'dinner' => ['저녁', '🌇'],
    'snack' => ['간식', '🍪'],
    'late' => ['야식', '🌙'],
];

function readiness_level(float $score): array
{
    if ($score < 4) return ['회복 필요', 'recover', '몸이 회복을 원하고 있어요. 가벼운 산책이나 스트레칭 정도로 쉬어 가세요.'];
    if ($score < 6) return ['페이스 조절', 'pace', '무리하지 말고 평소보다 가볍게 움직여 보세요.'];
    if ($score < 8) return ['준비 완료', 'ready', '평소처럼 활동하기 좋은 상태예요.'];
    return ['최상의 컨디션', 'go', '강도 높은 운동에 도전해도 좋은 날이에요.'];
}

function default_locations(): array
{
    return [
        ['role' => 'home', 'title' => '집', 'name' => '은평구', 'lat' => 37.6027, 'lon' => 126.9291],
        ['role' => 'work', 'title' => '회사', 'name' => '소공동', 'lat' => 37.5638, 'lon' => 126.9797],
        ['role' => 'parents', 'title' => '부모님 댁', 'name' => '평택', 'lat' => 36.9921, 'lon' => 127.1128],
    ];
}

function locations(): array
{
    $saved = setting('locations');
    return is_array($saved) && $saved ? $saved : default_locations();
}
