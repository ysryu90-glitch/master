<?php
// 일기 공유 링크 (로그인 없이 보기 전용)
//   s.php?t=코드           일기 한 편 또는 앨범
//   s.php?t=코드&e=번호     앨범 안의 일기 한 편
//   s.php?t=코드&p=사진번호  사진 (&th=1 작은 사진)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/diary.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$share = share_by_token((string) ($_GET['t'] ?? ''));
$ok = $share && share_active($share);
$allowed = $ok ? share_entry_ids($share) : [];

// 사진
if (isset($_GET['p'])) {
    if (!$ok) { http_response_code(404); exit; }
    $col = !empty($_GET['th']) ? 'thumb' : 'photo';
    $stmt = db()->prepare("SELECT entry_id, $col FROM diary_photos WHERE id = ?");
    $stmt->execute([(int) $_GET['p']]);
    $row = $stmt->fetch();
    if (!$row || !in_array((int) $row['entry_id'], $allowed, true)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . strlen($row[$col]));
    echo $row[$col];
    exit;
}

$self = 's.php?t=' . ($share['token'] ?? '');
$photoUrl = fn(int $id, bool $thumb = false) => $self . '&p=' . $id . ($thumb ? '&th=1' : '');
$kidFaces = ['😢', '😕', '🙂', '😄', '🤩'];

function share_head(string $title, string $desc = '', string $image = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta property="og:type" content="article">
<meta property="og:title" content="<?= h($title) ?>">
<?php if ($desc): ?><meta property="og:description" content="<?= h($desc) ?>"><meta name="description" content="<?= h($desc) ?>"><?php endif; ?>
<?php if ($image): ?><meta property="og:image" content="<?= h($image) ?>"><?php endif; ?>
<meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f1216" media="(prefers-color-scheme: dark)">
<link rel="icon" href="assets/icon.png">
<link rel="apple-touch-icon" href="assets/icon.png">
<link rel="stylesheet" href="assets/app.css?v=<?= asset_version('assets/app.css') ?>">
<title><?= h($title) ?></title>
</head>
<body class="shared">
<main class="page">
<?php
}

function share_foot(): void
{
    echo '<p class="small muted" style="text-align:center;margin:24px 0 8px">📔 우리 가족 일기 · 보기 전용 링크</p></main>'
        . '<script src="assets/app.js?v=' . asset_version('assets/app.js') . '"></script>'
        . '<script src="assets/card.js?v=' . asset_version('assets/card.js') . '"></script></body></html>';
}

if (!$ok) {
    share_head('우리 가족 일기');
    echo '<section class="card" style="text-align:center;padding:40px 20px"><div style="font-size:48px">🔒</div><h2>볼 수 없는 링크예요</h2>'
        . '<p class="muted">링크가 만료되었거나 공유가 중지됐어요.<br>보내 준 가족에게 새 링크를 부탁해 주세요.</p></section>';
    share_foot();
    exit;
}

// 가족이 아닌 사람이 열었을 때만 열람 수 세기
if (!current_member()) {
    db()->prepare('UPDATE diary_shares SET views = views + 1, last_view = NOW() WHERE id = ?')->execute([$share['id']]);
}

$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
$base = rtrim(public_base(), '/') . '/';
$isAlbum = $share['kind'] === 'album';
$entryId = $isAlbum ? (int) ($_GET['e'] ?? 0) : (int) ($allowed[0] ?? 0);

// ───────── 일기 한 편 ─────────
if ($entryId) {
    $e = in_array($entryId, $allowed, true) ? diary_entry($entryId) : null;
    if (!$e) { header('Location: ' . $self); exit; }
    $title = $e['title'] ?: $e['place_name'];
    $desc = date('Y년 n월 j일', strtotime($e['day'])) . ($e['place_name'] ? ' · ' . $e['place_name'] : '') . ($e['avg'] !== null ? ' · ' . stars_text($e['avg']) : '');
    share_head('📔 ' . $title, $desc, $e['cover'] ? $base . $photoUrl($e['cover']) : '');

    // 앨범 안에서 앞뒤 일기
    $pos = array_search($entryId, $allowed, true);
    $newer = $isAlbum && $pos > 0 ? $allowed[$pos - 1] : null;
    $older = $isAlbum && $pos !== false && $pos < count($allowed) - 1 ? $allowed[$pos + 1] : null;
    $card = [
        'title' => $title, 'place' => $e['title'] ? $e['place_name'] : '', 'date' => day_label($e['day']), 'weather' => $e['weather'],
        'avg' => $e['avg'], 'kid' => $share['show_kid'] ? trim($e['kid_said'], " \"“”") : '',
        'photos' => array_map(fn($id) => $photoUrl($id), array_slice($e['photos'], 0, 4)),
    ];
    ?>
<?php if ($isAlbum): ?><p style="margin:0 4px 10px"><a href="<?= h($self) ?>">‹ <?= h($share['title'] ?: '우리 가족 앨범') ?></a></p><?php endif; ?>
<article class="card dentry">
  <?php if ($e['photos']): ?>
    <div class="dgallery">
      <?php foreach ($e['photos'] as $pid): ?><a href="<?= h($photoUrl($pid)) ?>" data-full><img src="<?= h($photoUrl($pid, true)) ?>" alt="" loading="lazy"></a><?php endforeach; ?>
    </div>
    <?php if (count($e['photos']) > 1): ?><p class="small muted" style="text-align:center;margin:6px 0 0">← 옆으로 넘겨 보세요 · <?= count($e['photos']) ?>장 →</p><?php endif; ?>
  <?php endif; ?>
  <div class="dhead">
    <div class="ddate"><?php if (isset(DIARY_CATEGORIES[$e['category']])): ?><span class="dbadge <?= h($e['category']) ?>"><?= DIARY_CATEGORIES[$e['category']][1] ?> <?= DIARY_CATEGORIES[$e['category']][0] ?></span> <?php endif; ?><?= date('Y년 n월 j일', strtotime($e['day'])) ?> (<?= weekday_short($e['day']) ?>)<?= $e['weather'] ? ' · ' . h($e['weather']) : '' ?></div>
    <h2 class="dtitle"><?= h($title) ?></h2>
    <?php if ($e['title'] && $e['place_name']): ?><div class="dplace">📍 <?= h($e['place_name']) ?></div><?php endif; ?>
    <?php if ($e['avg'] !== null): ?><div class="davg"><span class="st"><?= stars_text($e['avg']) ?></span> <?= number_format($e['avg'], 1) ?><?= $e['again'] ? ' · <span class="again">💛 또 가고 싶어요</span>' : '' ?></div><?php endif; ?>
  </div>
  <?php if ($share['show_names'] && $e['ratings']): ?>
    <div class="drates">
      <?php foreach ($e['ratings'] as $mid => $s): $m = $names[$mid] ?? null; if (!$m) continue; ?>
        <span class="chip"><?= h($m['emoji'] . ' ' . $m['name']) ?> <?= $m['role'] === 'child' ? $kidFaces[$s - 1] : str_repeat('★', $s) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($share['show_kid'] && $e['kid_said']): ?><blockquote class="kidsaid">👧 “<?= h(trim($e['kid_said'], " \"“”")) ?>”</blockquote><?php endif; ?>
  <?php if ($share['show_body'] && trim((string) $e['body']) !== ''): ?><div class="dbody"><?= nl2br(h($e['body'])) ?></div><?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
    <?php if ($e['photos']): ?><button type="button" class="btn small" data-card='<?= h(json_encode($card, JSON_UNESCAPED_UNICODE)) ?>'>🖼 사진 카드로 저장</button><?php endif; ?>
  </div>
</article>
<?php if ($newer || $older): ?>
<div style="display:flex;justify-content:space-between;margin:4px 4px 16px">
  <?php if ($newer): ?><a class="btn small" href="<?= h($self . '&e=' . $newer) ?>">‹ 다음 일기</a><?php else: ?><span></span><?php endif; ?>
  <?php if ($older): ?><a class="btn small" href="<?= h($self . '&e=' . $older) ?>">이전 일기 ›</a><?php endif; ?>
</div>
<?php endif; ?>
<div id="lightbox" class="lightbox hidden" role="dialog" aria-label="사진 크게 보기"><img alt=""><button type="button" aria-label="닫기">✕</button></div>
<script>
(function () {
  var lb = document.getElementById('lightbox'), img = lb.querySelector('img');
  document.querySelectorAll('[data-full]').forEach(function (a) {
    a.addEventListener('click', function (ev) { ev.preventDefault(); img.src = a.href; lb.classList.remove('hidden'); });
  });
  lb.addEventListener('click', function () { lb.classList.add('hidden'); img.removeAttribute('src'); });
})();
</script>
<?php
    share_foot();
    exit;
}

// ───────── 앨범 ─────────
$entries = [];
foreach (array_chunk($allowed, 200) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));
    $entries = array_merge($entries, diary_decorate(db()->query("SELECT * FROM diary_entries WHERE id IN ($in) ORDER BY day DESC, id DESC")->fetchAll()));
}
$albumTitle = $share['title'] ?: (['daily' => '우리 가족 일상 앨범', 'outing' => '우리 가족 나들이 앨범'][$share['album_category']] ?? '우리 가족 일기 앨범');
$cover = null;
foreach ($entries as $x) if ($x['cover']) { $cover = $x['cover']; break; }
$photoCount = array_sum(array_map(fn($x) => count($x['photos']), $entries));
share_head('📔 ' . $albumTitle, '일기 ' . count($entries) . '편 · 사진 ' . $photoCount . '장', $cover ? $base . $photoUrl($cover) : '');
$byMonth = [];
foreach ($entries as $x) $byMonth[substr($x['day'], 0, 7)][] = $x;
?>
<section class="card share-hero">
  <div style="font-size:40px">📔</div>
  <h1><?= h($albumTitle) ?></h1>
  <p class="muted" style="margin:4px 0 0">일기 <?= count($entries) ?>편 · 사진 <?= $photoCount ?>장<?= $share['album_all'] ? ' · 새 일기가 생기면 여기에 저절로 더해져요' : '' ?></p>
</section>
<?php if (!$entries): ?><section class="card"><p class="muted">아직 일기가 없어요.</p></section><?php endif; ?>
<?php foreach ($byMonth as $ym => $list): ?>
  <h3 class="dmonth"><?= (int) substr($ym, 0, 4) ?>년 <?= (int) substr($ym, 5, 2) ?>월</h3>
  <div class="dlist">
    <?php foreach ($list as $x): ?>
      <a class="dcard" href="<?= h($self . '&e=' . $x['id']) ?>">
        <?php if ($x['cover']): ?>
          <div class="cover"><img src="<?= h($photoUrl($x['cover'], true)) ?>" alt="" loading="lazy"><?php if (count($x['photos']) > 1): ?><span class="cnt">📷 <?= count($x['photos']) ?></span><?php endif; ?></div>
        <?php else: ?><div class="cover none">🧺</div><?php endif; ?>
        <div class="txt">
          <div class="small muted"><?= date('n/j', strtotime($x['day'])) ?> (<?= weekday_short($x['day']) ?>)<?= $x['weather'] ? ' · ' . h($x['weather']) : '' ?></div>
          <div class="t"><?= h($x['title'] ?: $x['place_name']) ?></div>
          <?php if ($x['title'] && $x['place_name']): ?><div class="small muted">📍 <?= h($x['place_name']) ?></div><?php endif; ?>
          <?php if ($x['avg'] !== null): ?><div class="st"><?= stars_text($x['avg']) ?></div><?php endif; ?>
          <?php if ($share['show_kid'] && $x['kid_said']): ?><div class="small kid">👧 “<?= h(mb_strimwidth(trim($x['kid_said'], " \"“”"), 0, 60, '…')) ?>”</div><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php share_foot();
