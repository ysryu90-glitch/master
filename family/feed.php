<?php
// 알림 › 소식: 가족이 한 일 (할 일 · 장보기 · 스티커 · 일기 · 저녁 …)을 시간순으로, 가족에게 한마디 남기기
require __DIR__ . '/lib/bootstrap.php';

$me = require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'say') {
    $text = mb_substr(trim(post('text')), 0, 150);
    if ($text !== '') {
        activity('💬', $text, 'feed.php');
        require_once __DIR__ . '/lib/todo.php';
        todo_notify(other_adults((int) $me['id']), '💬 ' . $me['name'], $text, 'say');
        flash('가족에게 남겼어요.');
    }
    redirect('feed.php');
}

$rows = db()->query('SELECT * FROM activity WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY id DESC LIMIT 200')->fetchAll();
$seen = (int) setting('act_seen_' . $me['id'], 0);
if ($rows) set_setting('act_seen_' . $me['id'], (int) $rows[0]['id']);
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
$byDay = [];
foreach ($rows as $r) $byDay[substr($r['created_at'], 0, 10)][] = $r;
$ago = function (string $at): string {
    $s = time() - strtotime($at);
    if ($s < 60) return '방금';
    if ($s < 3600) return intdiv($s, 60) . '분 전';
    if ($s < 86400 && substr($at, 0, 10) === today()) return intdiv($s, 3600) . '시간 전';
    return date('H:i', strtotime($at));
};

page_start('소식', 'alerts');
?>
<form method="post" class="tadd say"><?= csrf_field() ?><input type="hidden" name="action" value="say">
  <input name="text" maxlength="150" placeholder="가족에게 한마디 · 예: 오늘 30분 늦어요" autocomplete="off" enterkeyhint="send" required>
  <button class="tadd-btn" aria-label="보내기"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
</form>
<p class="small muted" style="margin:-4px 6px 14px">남기면 다른 가족 폰으로 알림이 가요.</p>

<?php if (!$rows): ?>
  <section class="card tempty"><div class="big">🔔</div><b>아직 소식이 없어요</b><p class="small muted">가족이 할 일을 끝내거나, 장보기에 넣거나, 아이에게 스티커를 주면 여기에 차곡차곡 쌓여요. 누가 무엇을 했는지 따로 묻지 않아도 돼요.</p></section>
<?php endif; ?>

<?php foreach ($byDay as $d => $list): ?>
  <h3 class="listhead"><?= h(day_label($d)) ?></h3>
  <div class="card feed">
    <?php foreach ($list as $r): $m = $names[(int) $r['member_id']] ?? null; $mine = $m && (int) $m['id'] === (int) $me['id']; $new = !$mine && (int) $r['id'] > $seen; $say = $r['icon'] === '💬'; ?>
      <?php $tag = $r['url'] !== '' && !$say ? 'a' : 'div'; ?>
      <<?= $tag ?> class="fitem<?= $new ? ' new' : '' ?><?= $say ? ' say' : '' ?>"<?= $tag === 'a' ? ' href="' . h($r['url']) . '"' : '' ?>>
        <span class="fav"><?= h($m['emoji'] ?? '🏠') ?><i><?= h($r['icon']) ?></i></span>
        <span class="grow"><span class="ft"><b><?= h($m['name'] ?? '우리집') ?></b> <?= $say ? '' : h($r['text']) ?></span><?php if ($say): ?><span class="bubble"><?= h($r['text']) ?></span><?php endif; ?></span>
        <span class="fago"><?= h($ago($r['created_at'])) ?></span>
      </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php page_end('alerts');
