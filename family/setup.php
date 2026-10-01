<?php
// 처음 한 번: 아빠 · 엄마 계정과 아이 이름 만들기 (계정이 하나라도 있으면 막힘)
require __DIR__ . '/lib/bootstrap.php';

if (db()->query("SELECT COUNT(*) FROM members WHERE role = 'adult'")->fetchColumn()) {
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adults = [
        ['dad', post('dad_name') ?: '아빠', '👨', post('dad_password')],
        ['mom', post('mom_name') ?: '엄마', '👩', post('mom_password')],
    ];
    foreach ($adults as [, $name, , $password]) {
        if (mb_strlen($password) < 8) $error = "{$name} 비밀번호는 8자 이상으로 정해 주세요.";
    }
    if (!$error) {
        $insert = db()->prepare('INSERT INTO members (slug, name, emoji, role, password_hash, shortcut_token, sort) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($adults as $i => [$slug, $name, $emoji, $password]) {
            $insert->execute([$slug, $name, $emoji, 'adult', password_hash($password, PASSWORD_DEFAULT), bin2hex(random_bytes(16)), $i]);
        }
        $kid = post('kid_name');
        if ($kid !== '') {
            $insert->execute(['kid', $kid, '👧', 'child', null, null, 2]);
            db()->prepare('UPDATE members SET kcal_target = 1400, protein_target = 20 WHERE slug = ?')->execute(['kid']);
        }
        $me = db()->query("SELECT id FROM members WHERE slug = 'dad'")->fetchColumn();
        start_session((int) $me);
        flash('계정을 만들었어요. 설정에서 단축어 연결을 이어서 해 주세요.');
        redirect('settings.php');
    }
}

page_start('처음 설정');
?>
<section class="card">
  <h2>우리집 건강 시작하기</h2>
  <p class="muted">부부가 각자 로그인할 계정을 만들어요. 아이는 로그인 없이 식탁 기록에만 쓰여요.</p>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <fieldset>
      <legend>👨 아빠</legend>
      <label>이름<input name="dad_name" value="<?= h(post('dad_name', '아빠')) ?>"></label>
      <label>비밀번호 (8자 이상)<input name="dad_password" type="password" autocomplete="new-password" required minlength="8"></label>
    </fieldset>
    <fieldset>
      <legend>👩 엄마</legend>
      <label>이름<input name="mom_name" value="<?= h(post('mom_name', '엄마')) ?>"></label>
      <label>비밀번호 (8자 이상)<input name="mom_password" type="password" autocomplete="new-password" required minlength="8"></label>
    </fieldset>
    <fieldset>
      <legend>👧 아이</legend>
      <label>이름 (비우면 나중에 설정에서 추가)<input name="kid_name" value="<?= h(post('kid_name', '딸')) ?>"></label>
    </fieldset>
    <button class="btn primary wide">만들고 아빠로 로그인</button>
  </form>
</section>
<?php page_end();
