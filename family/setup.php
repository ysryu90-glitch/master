<?php
// 처음 한 번: 가족 이름 · 보호자 두 명 계정 · 아이 만들기 (계정이 하나라도 있으면 막힘)
require __DIR__ . '/lib/bootstrap.php';

if (db()->query("SELECT COUNT(*) FROM members WHERE role = 'adult'")->fetchColumn()) {
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pick = fn(string $v, array $ok, string $def) => in_array($v, $ok, true) ? $v : $def;
    $adults = [
        ['dad', mb_substr(trim(post('dad_name')), 0, 20) ?: '아빠', $pick(post('dad_emoji'), ['👨', '👩', '🧔', '👱‍♀️'], '👨'), post('dad_password')],
        ['mom', mb_substr(trim(post('mom_name')), 0, 20) ?: '엄마', $pick(post('mom_emoji'), ['👨', '👩', '🧔', '👱‍♀️'], '👩'), post('mom_password')],
    ];
    foreach ($adults as [, $name, , $password]) {
        if (mb_strlen($password) < 8) $error = "{$name} 비밀번호는 8자 이상으로 정해 주세요.";
    }
    if (!$error) {
        $insert = db()->prepare('INSERT INTO members (slug, name, emoji, role, password_hash, shortcut_token, sort) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($adults as $i => [$slug, $name, $emoji, $password]) {
            $insert->execute([$slug, $name, $emoji, 'adult', password_hash($password, PASSWORD_DEFAULT), bin2hex(random_bytes(16)), $i]);
        }
        $kid = mb_substr(trim(post('kid_name')), 0, 20);
        if ($kid !== '') $insert->execute(['kid', $kid, $pick(post('kid_emoji'), ['👧', '👦', '👶'], '👧'), 'child', null, null, 2]);
        if (trim(post('family_name')) !== '') set_setting('family_name', mb_substr(trim(post('family_name')), 0, 20));
        $me = db()->query("SELECT id FROM members WHERE slug = 'dad'")->fetchColumn();
        start_session((int) $me);
        activity('🎉', family_name() . '이(가) 문을 열었어요', 'index.php');
        flash('반가워요! 더보기 › 알림에서 이 폰의 알림을 켜 두면 가족 소식을 바로 받을 수 있어요.');
        redirect('index.php');
    }
}

page_start('처음 설정', '', ['class' => 'bare']);
$emo = fn(string $name, array $list, string $cur) => implode('', array_map(fn($e) => '<label><input type="radio" name="' . $name . '" value="' . $e . '"' . ($e === $cur ? ' checked' : '') . '><span>' . $e . '</span></label>', $list));
?>
<div class="lwrap" style="max-width:480px">
  <div class="lhero">
    <img src="assets/icon.png" alt="" width="84" height="84" class="licon">
    <h1>우리 가족 공간 만들기</h1>
    <p>1분이면 끝나요. 부부가 각자 로그인할 계정을 만들고, 아이는 로그인 없이 기록에만 쓰여요.</p>
  </div>
  <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <section class="card">
      <h2>🏠 가족 이름</h2>
      <label>화면 · 로그인에 보여요<input name="family_name" maxlength="20" value="<?= h(post('family_name', '')) ?>" placeholder="예: 하늘이네, 우리집"></label>
    </section>
    <?php foreach ([['dad', '보호자 1', '아빠', '👨'], ['mom', '보호자 2', '엄마', '👩']] as [$k, $t, $def, $e]): ?>
      <section class="card">
        <h2><?= $e ?> <?= $t ?></h2>
        <div class="emopick"><?= $emo($k . '_emoji', ['👨', '👩', '🧔', '👱‍♀️'], post($k . '_emoji', $e)) ?></div>
        <label>부를 이름<input name="<?= $k ?>_name" maxlength="20" value="<?= h(post($k . '_name', $def)) ?>"></label>
        <label>비밀번호 (8자 이상)<input name="<?= $k ?>_password" type="password" autocomplete="new-password" required minlength="8"></label>
      </section>
    <?php endforeach; ?>
    <section class="card">
      <h2>🧒 아이</h2>
      <div class="emopick"><?= $emo('kid_emoji', ['👧', '👦', '👶'], post('kid_emoji', '👧')) ?></div>
      <label>이름 (비우면 나중에 설정에서 추가)<input name="kid_name" maxlength="20" value="<?= h(post('kid_name', '')) ?>" placeholder="예: 하늘"></label>
    </section>
    <button class="btn primary wide">시작하기</button>
  </form>
</div>
<?php page_end();
