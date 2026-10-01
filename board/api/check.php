<?php
// 설치 점검 페이지: 브라우저에서 /board/api/check.php 를 열어 보세요. (비밀번호는 표시하지 않음)
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$rows = [];
function row(array &$rows, string $name, bool $ok, string $detail): void
{
    $rows[] = [$name, $ok, $detail];
}

row($rows, 'PHP 버전', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
row($rows, 'pdo_mysql 확장', extension_loaded('pdo_mysql'),
    extension_loaded('pdo_mysql') ? '켜져 있음' : 'Web Station › 스크립트 언어 설정 › PHP 프로필 › 확장에서 pdo_mysql 체크');

$configPath = __DIR__ . '/config.php';
$config = null;
if (!is_file($configPath)) {
    row($rows, 'config.php', false, '없음 — scripts/make-board-config.sh 로 만든 파일을 board/api/ 에 올려 주세요');
} else {
    try {
        $config = require $configPath;
        $ok = is_array($config) && !empty($config['db_password']) && !empty($config['token']);
        row($rows, 'config.php', $ok, $ok ? "있음 (DB: {$config['db_name']}, 사용자: {$config['db_user']})" : '내용이 비어 있거나 형식이 달라요');
    } catch (Throwable $e) {
        row($rows, 'config.php', false, '읽기 오류: ' . $e->getMessage());
        $config = null;
    }
}

if (is_array($config) && extension_loaded('pdo_mysql')) {
    $targets = [];
    if (!empty($config['db_socket'])) {
        $targets['소켓 ' . $config['db_socket']] = "mysql:unix_socket={$config['db_socket']};dbname={$config['db_name']};charset=utf8mb4";
    }
    $targets["TCP {$config['db_host']}:{$config['db_port']}"] =
        "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";
    $any = false;
    foreach ($targets as $label => $dsn) {
        try {
            $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            row($rows, "DB 접속 ($label)", true, '성공 · 표 ' . count($tables) . '개' . ($tables ? ': ' . implode(', ', $tables) : ''));
            $any = true;
        } catch (Throwable $e) {
            row($rows, "DB 접속 ($label)", false, $e->getMessage());
        }
    }
    if (!$any) {
        row($rows, '힌트', false, "Access denied → config.php 비밀번호 확인 · Can't connect → MariaDB 10 실행/TCP 확인 · Unknown database → family_board 이름 확인");
    }
}
?>
<!doctype html>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>전광판 설치 점검</title>
<style>
  body { font-family: -apple-system, sans-serif; margin: 24px; background: #f6f7f9; color: #222; }
  table { border-collapse: collapse; background: #fff; width: 100%; max-width: 900px; }
  td { border-bottom: 1px solid #e5e7eb; padding: 10px 12px; vertical-align: top; }
  .ok { color: #0a8f4f; font-weight: 700; }
  .bad { color: #d33; font-weight: 700; }
</style>
<h2>전광판 설치 점검</h2>
<table>
<?php foreach ($rows as [$name, $ok, $detail]): ?>
  <tr>
    <td><?= htmlspecialchars($name) ?></td>
    <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?></td>
    <td><?= htmlspecialchars($detail) ?></td>
  </tr>
<?php endforeach; ?>
</table>
