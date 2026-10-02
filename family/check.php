<?php
// 설치 점검 (로그인 없이 열림 · 비밀번호는 표시하지 않음)
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$rows = [];
$add = function (string $name, bool $ok, string $detail) use (&$rows) { $rows[] = [$name, $ok, $detail]; };

$add('PHP 버전', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION . (version_compare(PHP_VERSION, '8.0', '>=') ? '' : ' — PHP 8 이상이 필요해요'));
foreach (['pdo_mysql' => 'DB 연결', 'curl' => 'iCloud 캘린더 · 알림 · 날씨', 'simplexml' => 'iCloud 캘린더', 'mbstring' => '한글 처리', 'openssl' => '알림'] as $ext => $why) {
    $add("확장: $ext", extension_loaded($ext), extension_loaded($ext) ? '켜져 있음' : "꺼져 있음 ({$why}에 필요) — Web Station › 스크립트 언어 설정 › PHP 프로필 › 확장에서 체크");
}
$path = __DIR__ . '/config.php';
$config = is_file($path) ? require $path : null;
$add('config.php', is_array($config), is_array($config) ? '있음' : '없음 — scripts/make-family-config.sh 로 만든 파일을 family/ 에 올려 주세요');
if (is_array($config) && extension_loaded('pdo_mysql')) {
    try {
        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";
        if (!empty($config['db_socket'])) {
            try { $pdo = new PDO("mysql:unix_socket={$config['db_socket']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_password']); }
            catch (Throwable $e) { $pdo = new PDO($dsn, $config['db_user'], $config['db_password']); }
        } else {
            $pdo = new PDO($dsn, $config['db_user'], $config['db_password']);
        }
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $add('DB 접속', true, '성공 · 표 ' . count($tables) . '개');
    } catch (Throwable $e) {
        $add('DB 접속', false, $e->getMessage());
    }
}
$add('접속 방식', true, (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'HTTPS (암호화됨)' : 'HTTP — 밖에서 접속할 때는 https:// 주소를 써 주세요');
?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>설치 점검</title>
<style>body{font-family:-apple-system,sans-serif;margin:20px;background:#f6f7f9}table{border-collapse:collapse;background:#fff;width:100%;max-width:820px}td{border-bottom:1px solid #e5e7eb;padding:10px}.ok{color:#0a8f4f;font-weight:700}.bad{color:#d33;font-weight:700}</style>
<h2>우리집 건강 · 설치 점검</h2><table>
<?php foreach ($rows as [$n, $ok, $d]): ?><tr><td><?= htmlspecialchars($n) ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?></td><td><?= htmlspecialchars($d) ?></td></tr><?php endforeach; ?>
</table><p><a href="index.php">사이트로 가기 →</a></p>
