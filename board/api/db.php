<?php
// 공통: 설정 읽기, DB 연결, 테이블 자동 생성

date_default_timezone_set('Asia/Seoul');

function board_config(): array
{
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        board_fail(500, 'config.php 가 없어요. config.sample.php 를 복사해 만들어 주세요.');
    }
    return require $path;
}

function board_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function board_db(array $config): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $dsns = [];
    if (!empty($config['db_socket'])) {
        $dsns[] = "mysql:unix_socket={$config['db_socket']};dbname={$config['db_name']};charset=utf8mb4";
    }
    $dsns[] = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";

    $last = null;
    foreach ($dsns as $dsn) {
        try {
            $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], $options);
            $pdo->exec("SET time_zone = '+09:00'");
            board_migrate($pdo);
            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
        }
    }
    board_fail(500, 'DB 접속 실패: ' . ($last ? $last->getMessage() : '알 수 없음'));
}

function board_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS member_state (
            member_id   VARCHAR(16) PRIMARY KEY,
            name        VARCHAR(40) NOT NULL,
            payload     LONGTEXT NOT NULL,
            updated_at  DATETIME NOT NULL
        ) DEFAULT CHARSET = utf8mb4
    SQL);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS daily_health (
            member_id       VARCHAR(16) NOT NULL,
            day             DATE NOT NULL,
            readiness       DECIMAL(4,1) NULL,
            readiness_level VARCHAR(20) NULL,
            steps           INT NULL,
            sleep_hours     DECIMAL(4,1) NULL,
            water_ml        INT NULL,
            updated_at      DATETIME NOT NULL,
            PRIMARY KEY (member_id, day)
        ) DEFAULT CHARSET = utf8mb4
    SQL);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS dinner_plan (
            day          DATE PRIMARY KEY,
            dish         VARCHAR(200) NOT NULL,
            ingredients  TEXT NULL,
            updated_by   VARCHAR(16) NOT NULL,
            updated_at   DATETIME NOT NULL
        ) DEFAULT CHARSET = utf8mb4
    SQL);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS meal_log (
            id         CHAR(36) PRIMARY KEY,
            member_id  VARCHAR(16) NOT NULL,
            eaten_at   DATETIME NOT NULL,
            meal_type  VARCHAR(16) NOT NULL,
            title      VARCHAR(200) NOT NULL,
            calories   INT NOT NULL,
            carbs_g    DECIMAL(6,1) NOT NULL,
            protein_g  DECIMAL(6,1) NOT NULL,
            fat_g      DECIMAL(6,1) NOT NULL,
            sodium_mg  INT NOT NULL,
            items      TEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY member_day (member_id, eaten_at)
        ) DEFAULT CHARSET = utf8mb4
    SQL);
}
