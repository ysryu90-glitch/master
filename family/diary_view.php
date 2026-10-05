<?php
// 일기 한 편 (일상 · 나들이)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/diary.php';
require __DIR__ . '/lib/ledger.php';

$me = require_login();
$e = diary_entry((int) ($_GET['id'] ?? 0));
if (!$e) redirect('diary.php');

$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
$kidFaces = ['😢', '😕', '🙂', '😄', '🤩'];

// 앞뒤 일기
// 같은 종류 안에서 앞뒤 일기
$stmt = db()->prepare('SELECT id FROM diary_entries WHERE category = ? AND (day, id) < (?, ?) ORDER BY day DESC, id DESC LIMIT 1');
$stmt->execute([$e['category'], $e['day'], $e['id']]);
$older = $stmt->fetchColumn();
$stmt = db()->prepare('SELECT id FROM diary_entries WHERE category = ? AND (day, id) > (?, ?) ORDER BY day, id LIMIT 1');
$stmt->execute([$e['category'], $e['day'], $e['id']]);
$newer = $stmt->fetchColumn();

// 같은 곳 다른 날
$same = [];
if ($e['place_id']) {
    $stmt = db()->prepare('SELECT id, day FROM diary_entries WHERE place_id = ? AND id <> ? ORDER BY day DESC LIMIT 5');
    $stmt->execute([$e['place_id'], $e['id']]);
    $same = $stmt->fetchAll();
}
$ago = (int) round((strtotime(today()) - strtotime($e['day'])) / 86400);
$activeShares = count(array_filter(shares_list((int) $e['id']), 'share_active'));
$card = [
    'title' => $e['title'] ?: $e['place_name'], 'place' => $e['title'] ? $e['place_name'] : '', 'date' => day_label($e['day']),
    'weather' => $e['weather'], 'avg' => $e['avg'], 'kid' => trim($e['kid_said'], " \"“”"),
    'photos' => array_map(fn($id) => 'diary_photo.php?id=' . $id, array_slice($e['photos'], 0, 4)),
];

[$catLabel, $catIcon] = DIARY_CATEGORIES[$e['category']] ?? ['일기', '📔'];
page_start($catLabel . ' 일기', 'family');
?>
<p style="margin:0 4px 10px"><a href="diary.php?c=<?= h($e['category']) ?>">‹ <?= h($catLabel) ?> 일기 목록</a></p>

<article class="card dentry">
  <?php if ($e['photos']): ?>
    <div class="dgallery" id="gallery">
      <?php foreach ($e['photos'] as $pid): ?>
        <a href="diary_photo.php?id=<?= $pid ?>" data-full><img src="diary_photo.php?id=<?= $pid ?>&t=1" data-src="diary_photo.php?id=<?= $pid ?>" alt="" loading="lazy"></a>
      <?php endforeach; ?>
    </div>
    <?php if (count($e['photos']) > 1): ?><p class="small muted" style="text-align:center;margin:6px 0 0">← 옆으로 넘겨 보세요 · <?= count($e['photos']) ?>장 →</p><?php endif; ?>
  <?php endif; ?>

  <div class="dhead">
    <div class="ddate"><span class="dbadge <?= h($e['category']) ?>"><?= $catIcon ?> <?= h($catLabel) ?></span> <?= h(day_label($e['day'])) ?><?= $ago >= 365 ? ' · ' . floor($ago / 365) . '년 전' : '' ?><?= $e['weather'] ? ' · ' . h($e['weather']) : '' ?></div>
    <h2 class="dtitle"><?= h($e['title'] ?: $e['place_name']) ?></h2>
    <?php if ($e['title'] && $e['place_name']): ?><div class="dplace">📍 <?= h($e['place_name']) ?></div><?php endif; ?>
    <?php if ($e['avg'] !== null): ?><div class="davg"><span class="st"><?= stars_text($e['avg']) ?></span> <?= number_format($e['avg'], 1) ?><?= $e['again'] ? ' · <span class="again">💛 또 가고 싶어요</span>' : '' ?></div>
    <?php elseif ($e['again']): ?><div class="davg"><span class="again">💛 또 가고 싶어요</span></div><?php endif; ?>
  </div>

  <?php if ($e['ratings']): ?>
    <div class="drates">
      <?php foreach ($e['ratings'] as $mid => $s): $m = $names[$mid] ?? null; if (!$m) continue; ?>
        <span class="chip"><?= h($m['emoji'] . ' ' . $m['name']) ?> <?= $m['role'] === 'child' ? $kidFaces[$s - 1] : str_repeat('★', $s) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($e['kid_said']): ?><blockquote class="kidsaid">👧 “<?= h(trim($e['kid_said'], " \"“”")) ?>”</blockquote><?php endif; ?>
  <?php if (trim((string) $e['body']) !== ''): ?><div class="dbody"><?= nl2br(h($e['body'])) ?></div><?php endif; ?>
  <?php $spent = expenses_of_diary((int) $e['id']); if ($spent): ?>
    <div class="money">
      <?php $byCat = []; foreach ($spent as $x) $byCat[$x['category']] = ($byCat[$x['category']] ?? 0) + (int) $x['amount']; arsort($byCat); ?>
      <?php foreach ($byCat as $k => $v): [$cn, $ci] = ledger_cat($k); ?><div class="row"><span><?= $ci ?> <?= h($cn) ?></span><span><?= won($v) ?></span></div><?php endforeach; ?>
      <div class="row tot"><span>💰 이날 쓴 돈</span><span><?= won(array_sum($byCat)) ?></span></div>
    </div>
  <?php endif; ?>

  <div class="acts" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
    <a class="btn small primary" href="diary_share.php?entry=<?= (int) $e['id'] ?>">🔗 가족에게 공유<?= $activeShares ? ' (' . $activeShares . ')' : '' ?></a>
    <button type="button" class="btn small" data-card='<?= h(json_encode($card, JSON_UNESCAPED_UNICODE)) ?>'>🖼 사진 카드</button>
    <a class="btn small" href="diary_edit.php?id=<?= (int) $e['id'] ?>">✏️ 고치기 · 사진 추가</a>
    <?php if (!$spent): ?><a class="btn small" href="diary_edit.php?id=<?= (int) $e['id'] ?>#spend">💰 쓴 돈 적기</a><?php endif; ?>
    <?php if ($e['place_name']): ?><a class="btn small" href="https://map.naver.com/p/search/<?= rawurlencode($e['place_name']) ?>" target="_blank" rel="noopener">🗺 지도</a><?php endif; ?>
  </div>
  <?php if ($same): ?>
    <p class="small muted" style="margin:12px 0 0">이곳에 또 간 날: <?php foreach ($same as $i => $s): ?><?= $i ? ' · ' : '' ?><a href="diary_view.php?id=<?= (int) $s['id'] ?>"><?= date('Y.n.j', strtotime($s['day'])) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
</article>

<div style="display:flex;justify-content:space-between;margin:4px 4px 16px">
  <?php if ($newer): ?><a class="btn small" href="diary_view.php?id=<?= (int) $newer ?>">‹ 다음 <?= h($catLabel) ?></a><?php else: ?><span></span><?php endif; ?>
  <?php if ($older): ?><a class="btn small" href="diary_view.php?id=<?= (int) $older ?>">이전 <?= h($catLabel) ?> ›</a><?php endif; ?>
</div>

<div id="lightbox" class="lightbox hidden" role="dialog" aria-label="사진 크게 보기"><img alt=""><button type="button" aria-label="닫기">✕</button></div>
<script>
(function () {
  var lb = document.getElementById('lightbox');
  if (!lb) return;
  var img = lb.querySelector('img');
  document.querySelectorAll('[data-full]').forEach(function (a) {
    a.addEventListener('click', function (ev) { ev.preventDefault(); img.src = a.href; lb.classList.remove('hidden'); });
  });
  lb.addEventListener('click', function () { lb.classList.add('hidden'); img.removeAttribute('src'); });
})();
</script>
<script src="assets/card.js?v=<?= asset_version('assets/card.js') ?>"></script>
<?php page_end('family');
