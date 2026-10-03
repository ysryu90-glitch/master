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

const SCHEMA_VERSION = 5;
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
        // 나들이 일기: 다녀온 날의 글 · 가족별 별점 · 사진
        "CREATE TABLE IF NOT EXISTS diary_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        // 나들이 일기 공유 링크 (로그인 없이 보기 전용)
        "CREATE TABLE IF NOT EXISTS diary_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(32) NOT NULL UNIQUE,
            kind VARCHAR(6) NOT NULL,
            entry_id INT NULL,
            title VARCHAR(100) NOT NULL DEFAULT '',
            album_all TINYINT NOT NULL DEFAULT 1,
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
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql . ' DEFAULT CHARSET = utf8mb4');
    }
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

const TABS = [
    'today' => ['index.php', '오늘', '🏠'],
    'health' => ['health.php', '건강', '❤️'],
    'meals' => ['meals.php', '식단', '🍚'],
    'table' => ['table.php', '식탁', '🍲'],
    'calendar' => ['calendar.php', '일정', '📅'],
    'family' => ['family.php', '가족', '👨‍👩‍👧'],
];

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
    $flash = $_COOKIE['flash'] ?? '';
    if ($flash) setcookie('flash', '', ['expires' => 1, 'path' => '/']);
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="우리집 건강">
<meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f1216" media="(prefers-color-scheme: dark)">
<link rel="icon" href="assets/icon.png">
<link rel="apple-touch-icon" href="assets/icon.png">
<link rel="manifest" href="manifest.json">
<link rel="stylesheet" href="assets/app.css?v=<?= asset_version('assets/app.css') ?>">
<title><?= h($title) ?> · 우리집 건강</title>
</head>
<body class="<?= $tab ? 'with-tabs' : '' ?>">
<header class="topbar">
  <div class="topbar-title"><?= h($title) ?></div>
  <?php if ($me): ?>
  <a class="topbar-me" href="settings.php" aria-label="설정"><?= h($me['emoji']) ?> <span><?= h($me['name']) ?></span> ⚙︎</a>
  <?php endif; ?>
</header>
<main class="page">
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<?php
}

function page_end(string $tab = ''): void
{
    if ($tab) {
        echo '</main><nav class="tabbar">';
        foreach (TABS as $key => [$href, $label, $icon]) {
            echo '<a href="' . $href . '" class="' . ($key === $tab ? 'on' : '') . '"><span class="i">' . $icon . '</span>' . $label . '</a>';
        }
        echo '</nav>';
    } else {
        echo '</main>';
    }
    echo '<script src="assets/app.js?v=' . asset_version('assets/app.js') . '"></script></body></html>';
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
