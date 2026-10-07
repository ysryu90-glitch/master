<?php
require __DIR__ . '/lib/bootstrap.php';

if (!db()->query("SELECT COUNT(*) FROM members WHERE role = 'adult'")->fetchColumn()) redirect('setup.php');

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
// 같은 사이트 안의 상대 주소만 허용
if (!preg_match('#^[a-z0-9_/]+\.(php|html)(\?[^\s]*)?$|^board/(\?[^\s]*)?$#i', $next) || str_contains($next, '//')) $next = 'index.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (too_many_attempts()) {
        $error = '로그인 시도가 너무 많아요. 15분 뒤에 다시 시도해 주세요.';
    } else {
        $stmt = db()->prepare("SELECT * FROM members WHERE id = ? AND role = 'adult'");
        $stmt->execute([(int) post('member')]);
        $member = $stmt->fetch();
        if ($member && password_verify((string) post('password'), (string) $member['password_hash'])) {
            start_session((int) $member['id']);
            redirect($next);
        }
        record_failed_attempt();
        $error = '비밀번호가 맞지 않아요.';
    }
}

page_start('로그인');
?>
<section class="card login">
  <div class="login-logo"><img src="assets/icon.png" alt="" width="72" height="72" style="border-radius:17px;display:block;margin:0 auto"></div>
  <h2>우리집</h2>
  <p class="small muted" style="margin:-2px 0 18px">우리 가족 할 일 · 일정 · 일기 · 가계부 · 건강</p>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <div class="segmented">
      <?php foreach (members('adult') as $i => $m): ?>
        <label><input type="radio" name="member" value="<?= (int) $m['id'] ?>" <?= ((int) post('member', 0) === (int) $m['id'] || (!post('member') && $i === 0)) ? 'checked' : '' ?>><span><?= h($m['emoji'] . ' ' . $m['name']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <label>비밀번호<input name="password" type="password" autocomplete="current-password" required autofocus></label>
    <button class="btn primary wide">로그인</button>
    <p class="muted small">한 번 로그인하면 이 기기에서 180일 동안 유지돼요.</p>
  </form>
</section>
<?php page_end();
