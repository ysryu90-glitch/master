<?php
// 일기 공유: 링크 만들기 · 보내기 · 중지
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/diary.php';

$me = require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post('id');
    switch (post('action')) {
        case 'create':
            $kind = post('kind') === 'album' ? 'album' : 'entry';
            $entryId = (int) post('entry');
            if ($kind === 'entry' && !diary_entry($entryId)) { flash('일기를 찾지 못했어요.'); redirect('diary.php'); }
            $picked = array_map('intval', (array) ($_POST['entries'] ?? []));
            $all = post('album_all') !== '0';
            if ($kind === 'album' && !$all && !$picked) { flash('앨범에 넣을 일기를 하나 이상 골라 주세요.'); redirect('diary_share.php'); }
            $s = share_create([
                'kind' => $kind, 'entry_id' => $kind === 'entry' ? $entryId : null, 'title' => post('title'),
                'album_all' => $all, 'album_category' => post('album_category'), 'entries' => $kind === 'album' && !$all ? $picked : [],
                'show_body' => post('show_body'), 'show_kid' => post('show_kid'), 'show_names' => post('show_names'),
                'days' => (int) post('days'),
            ], (int) $me['id']);
            flash('공유 링크를 만들었어요. 아래 「보내기」를 눌러 카카오톡 등으로 보내 주세요.');
            redirect('diary_share.php?new=' . $s['id'] . ($kind === 'entry' ? '&entry=' . $entryId : ''));
        case 'revoke':
            db()->prepare('UPDATE diary_shares SET revoked = 1 - revoked WHERE id = ?')->execute([$id]);
            break;
        case 'delete':
            db()->prepare('DELETE FROM diary_share_entries WHERE share_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM diary_shares WHERE id = ?')->execute([$id]);
            flash('링크를 지웠어요. 이 링크는 이제 열리지 않아요.');
            break;
    }
    redirect('diary_share.php' . (post('entry') ? '?entry=' . (int) post('entry') : ''));
}

$entry = !empty($_GET['entry']) ? diary_entry((int) $_GET['entry']) : null;
$newId = (int) ($_GET['new'] ?? 0);
$shares = shares_list();
$entries = diary_entries();

page_start('일기 공유', 'family');
?>
<p style="margin:0 4px 10px"><a href="<?= $entry ? 'diary_view.php?id=' . (int) $entry['id'] : 'diary.php' ?>">‹ <?= $entry ? '일기로 돌아가기' : '일기 목록' ?></a></p>

<section class="card">
  <h2>🔗 <?= $entry ? '이 일기 공유하기' : '가족 앨범 공유하기' ?></h2>
  <?php if ($entry): ?>
    <p class="muted" style="margin-top:0">「<?= h($entry['title'] ?: $entry['place_name']) ?>」 (<?= date('n/j', strtotime($entry['day'])) ?>) 한 편만 볼 수 있는 링크예요.
      여러 일기를 모아 보내려면 <a href="diary_share.php">가족 앨범</a>을 만들어 주세요.</p>
  <?php else: ?>
    <p class="muted" style="margin-top:0">할머니 · 할아버지께 링크 하나만 드리면 우리 가족 일기를 앨범처럼 보실 수 있어요. 로그인은 필요 없어요.</p>
  <?php endif; ?>
  <form method="post" class="form" data-busy="공유 링크를 만드는 중이에요…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="kind" value="<?= $entry ? 'entry' : 'album' ?>">
    <?php if ($entry): ?><input type="hidden" name="entry" value="<?= (int) $entry['id'] ?>"><?php endif; ?>
    <?php if (!$entry): ?>
      <label>앨범 이름<input name="title" maxlength="100" placeholder="예: 하린이네 일기장"></label>
      <div class="segmented" style="margin-bottom:12px">
        <label><input type="radio" name="album_all" value="1" checked><span>모든 일기 (새 일기도)</span></label>
        <label><input type="radio" name="album_all" value="0"><span>고른 일기만</span></label>
      </div>
      <label id="album-cat">어떤 일기를 넣을까요?<select name="album_category">
        <option value="">📔 모든 일기 (일상 + 나들이)</option>
        <?php foreach (DIARY_CATEGORIES as $key => [$label, $icon]): ?><option value="<?= $key ?>"><?= $icon ?> <?= $label ?> 일기만</option><?php endforeach; ?>
      </select></label>
      <div id="pick-list" class="pick-list hidden">
        <?php foreach ($entries as $x): ?>
          <label><input type="checkbox" name="entries[]" value="<?= (int) $x['id'] ?>">
            <?php if ($x['cover']): ?><img src="diary_photo.php?id=<?= $x['cover'] ?>&t=1" alt=""><?php else: ?><span class="noimg">🧺</span><?php endif; ?>
            <span><?= DIARY_CATEGORIES[$x['category']][1] ?? '' ?> <?= date('Y.n.j', strtotime($x['day'])) ?><br><b><?= h($x['title'] ?: $x['place_name']) ?></b></span></label>
        <?php endforeach; ?>
        <?php if (!$entries): ?><p class="muted">아직 일기가 없어요.</p><?php endif; ?>
      </div>
    <?php endif; ?>
    <p class="small" style="margin:4px 0 6px;font-weight:700">보여 줄 내용 <span class="muted" style="font-weight:400">· 사진 · 날짜 · 장소 · 평균 별점은 항상 보여요</span></p>
    <label class="dagain" style="margin-top:4px"><input type="checkbox" name="show_body" value="1" checked> ✍️ 일기 글</label>
    <label class="dagain" style="margin-top:4px"><input type="checkbox" name="show_kid" value="1" checked> 👧 아이가 한 말</label>
    <label class="dagain" style="margin-top:4px;margin-bottom:12px"><input type="checkbox" name="show_names" value="1" checked> 👨‍👩‍👧 가족 이름과 각자 별점</label>
    <label>링크 열 수 있는 기간<select name="days">
      <option value="0"><?= $entry ? '계속 (직접 중지할 때까지)' : '계속 (직접 중지할 때까지) — 부모님 앨범에 추천' ?></option>
      <option value="7">7일 뒤 자동으로 닫힘</option>
      <option value="30">30일 뒤 자동으로 닫힘</option>
    </select></label>
    <button class="btn primary wide">🔗 공유 링크 만들기</button>
  </form>
</section>

<?php
$list = $entry ? array_values(array_filter($shares, fn($s) => $s['kind'] === 'entry' && (int) $s['entry_id'] === (int) $entry['id'])) : $shares;
?>
<section class="card">
  <h2>📤 <?= $entry ? '이 일기의 공유 링크' : '만든 공유 링크' ?></h2>
  <?php if (!$list): ?><p class="muted">아직 만든 링크가 없어요.</p><?php endif; ?>
  <?php foreach ($list as $s):
      $active = share_active($s);
      $name = $s['kind'] === 'album' ? '📚 ' . ($s['title'] ?: '우리 가족 앨범') . ($s['album_all'] ? ' (' . ($s['album_category'] ? DIARY_CATEGORIES[$s['album_category']][0] . ' 일기 전부' : '모든 일기') . ')' : ' (고른 일기)') : '📔 ' . ($s['e_title'] ?: $s['e_place'] ?: '일기') . ($s['e_day'] ? ' · ' . date('n/j', strtotime($s['e_day'])) : '');
      $state = $s['revoked'] ? '⏸ 중지됨' : (!$active ? '⌛ 기간 끝남' : ($s['expires_at'] ? '✅ ' . date('n/j', strtotime($s['expires_at'])) . '까지' : '✅ 공유 중'));
      $hidden = array_filter([$s['show_body'] ? '' : '일기 글', $s['show_kid'] ? '' : '아이 말', $s['show_names'] ? '' : '이름']);
  ?>
    <div class="share-row<?= (int) $s['id'] === $newId ? ' new' : '' ?><?= $active ? '' : ' off' ?>">
      <div class="top"><b><?= h($name) ?></b><span class="small"><?= $state ?></span></div>
      <div class="small muted">열어 본 횟수 <?= (int) $s['views'] ?>번<?= $s['last_view'] ? ' · 마지막 ' . date('n/j H:i', strtotime($s['last_view'])) : '' ?><?= $hidden ? ' · 숨김: ' . h(implode(', ', $hidden)) : '' ?></div>
      <?php if ($active): ?>
        <input class="share-url" value="<?= h(share_url($s)) ?>" readonly onclick="this.select()">
      <?php endif; ?>
      <div class="acts">
        <?php if ($active): ?>
          <button type="button" class="btn small primary" data-share-url="<?= h(share_url($s)) ?>" data-share-title="<?= h(trim(preg_replace('/^\S+\s/u', '', $name))) ?>">📤 보내기</button>
          <button type="button" class="btn small" data-copy-url="<?= h(share_url($s)) ?>">📋 복사</button>
          <a class="btn small" href="<?= h(share_url($s)) ?>" target="_blank" rel="noopener">👀 미리 보기</a>
        <?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><?php if ($entry): ?><input type="hidden" name="entry" value="<?= (int) $entry['id'] ?>"><?php endif; ?>
          <button class="btn small"><?= $s['revoked'] ? '▶️ 다시 공유' : '⏸ 공유 중지' ?></button></form>
        <form method="post" data-confirm="이 링크를 지울까요? 받은 사람은 더 이상 볼 수 없어요."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><?php if ($entry): ?><input type="hidden" name="entry" value="<?= (int) $entry['id'] ?>"><?php endif; ?>
          <button class="btn small danger">지우기</button></form>
      </div>
    </div>
  <?php endforeach; ?>
  <p class="small muted" style="margin:10px 0 0">링크를 아는 사람은 누구나 볼 수 있어요. 받은 가족에게만 보내 주시고, 필요 없으면 「공유 중지」를 눌러 주세요. 사진에는 촬영 위치 정보가 남아 있지 않아요.</p>
</section>
<script>
(function () {
  var pick = document.getElementById('pick-list');
  if (pick) document.querySelectorAll('[name=album_all]').forEach(function (r) {
    r.addEventListener('change', function () {
      pick.classList.toggle('hidden', r.value !== '0' || !r.checked);
      document.getElementById('album-cat').classList.toggle('hidden', r.value === '0' && r.checked);
    });
  });
})();
</script>
<script src="assets/card.js?v=<?= asset_version('assets/card.js') ?>"></script>
<?php page_end('family');
