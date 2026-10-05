<?php
// 가족 일기 목록: 일상 · 나들이를 달마다 모아 보기
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/ledger.php';

$me = require_login();
$years = array_map('intval', db()->query('SELECT DISTINCT YEAR(day) y FROM diary_entries ORDER BY y DESC')->fetchAll(PDO::FETCH_COLUMN));
$year = (int) ($_GET['y'] ?? 0);
if ($year && !in_array($year, $years, true)) $year = 0;
$cat = isset(DIARY_CATEGORIES[$_GET['c'] ?? '']) ? $_GET['c'] : '';
$entries = diary_entries($year ?: null, 500, $cat ?: null);
$spentBy = diary_spent(array_column($entries, 'id'));
$q = fn(array $p) => 'diary.php' . (($p = array_filter($p + ['c' => $cat, 'y' => $year ?: ''])) ? '?' . http_build_query($p) : '');
$catCount = [];
foreach (db()->query('SELECT category, COUNT(*) n FROM diary_entries GROUP BY category') as $r) $catCount[$r['category']] = (int) $r['n'];

// 숫자로 보는 기록
$photoCount = array_sum(array_map(fn($e) => count($e['photos']), $entries));
$outings = array_filter($entries, fn($e) => $e['category'] === 'outing');
$places = count(array_unique(array_map(fn($e) => $e['place_id'] ?: $e['place_name'], $outings)));
$rated = array_filter($outings, fn($e) => $e['avg'] !== null);
usort($rated, fn($a, $b) => [$b['avg'], $b['day']] <=> [$a['avg'], $a['day']]);
$best = $rated[0] ?? null;

// 몇 년 전 오늘 (앞뒤 3일)
$memories = db()->query("SELECT id FROM diary_entries WHERE YEAR(day) < YEAR(CURDATE())
    AND ABS(DATEDIFF(DATE_ADD(day, INTERVAL (YEAR(CURDATE()) - YEAR(day)) YEAR), CURDATE())) <= 3 ORDER BY day DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$memories = array_filter(array_map(fn($id) => diary_entry((int) $id), $memories));

$pending = diary_pending_plans();

$byMonth = [];
foreach ($entries as $e) $byMonth[substr($e['day'], 0, 7)][] = $e;

page_start('일기', 'family');
?>
<?php foreach ($pending as $pl): $pp = place($pl['place_id']); if (!$pp) continue; ?>
  <section class="card" style="display:flex;gap:12px;align-items:center">
    <span style="font-size:28px">📌</span>
    <span class="grow"><b><?= date('n/j', strtotime($pl['day'])) ?> <?= h($pp['name']) ?></b><div class="small muted">다녀오셨나요? 사진이랑 별점을 남겨 두세요.</div></span>
    <a class="btn small primary" href="diary_edit.php?cat=outing&place=<?= rawurlencode($pl['place_id']) ?>&day=<?= h($pl['day']) ?>">일기 쓰기</a>
  </section>
<?php endforeach; ?>

<?php foreach ($memories as $mem): ?>
  <a class="card memory" href="diary_view.php?id=<?= (int) $mem['id'] ?>">
    <?php if ($mem['cover']): ?><img src="diary_photo.php?id=<?= $mem['cover'] ?>&t=1" alt="" loading="lazy"><?php endif; ?>
    <span><span class="small muted">🕰 <?= date('Y') - (int) substr($mem['day'], 0, 4) ?>년 전 이맘때</span><br><b><?= h($mem['title'] ?: $mem['place_name']) ?></b></span>
  </a>
<?php endforeach; ?>

<section class="card dtop">
  <div class="dtop-row">
    <nav class="dtabs">
      <a class="<?= $cat ? '' : 'on' ?>" href="<?= h($q(['c' => ''])) ?>">전체 <small><?= array_sum($catCount) ?></small></a>
      <?php foreach (DIARY_CATEGORIES as $key => [$label, $icon]): ?>
        <a class="<?= $cat === $key ? 'on' : '' ?>" href="<?= h($q(['c' => $key])) ?>"><?= $icon ?> <?= $label ?> <small><?= $catCount[$key] ?? 0 ?></small></a>
      <?php endforeach; ?>
    </nav>
    <a class="btn small icon" href="diary_share.php" aria-label="공유" title="공유">🔗</a>
    <a class="btn primary small" href="diary_edit.php?cat=<?= $cat ?: 'daily' ?>">✍️ 쓰기</a>
  </div>
  <?php if (count($years) > 1): ?>
    <div class="dyears">
      <a class="<?= $year ? '' : 'on' ?>" href="<?= h($q(['y' => ''])) ?>">모든 해</a>
      <?php foreach ($years as $y): ?><a class="<?= $y === $year ? 'on' : '' ?>" href="<?= h($q(['y' => $y])) ?>"><?= $y ?></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($entries): ?>
    <p class="dsum"><?= count($entries) ?>편<?php if ($cat === 'daily'): ?> · <?= count(array_unique(array_column($entries, 'day'))) ?>일 기록<?php elseif ($outings): ?> · 나들이 <?= count($outings) ?>번<?= $places > 1 ? ' (' . $places . '곳)' : '' ?><?php endif; ?> · 사진 <?= $photoCount ?>장<?php if ($best): ?>
      · 🏆 <a href="diary_view.php?id=<?= (int) $best['id'] ?>"><?= h($best['place_name'] ?: $best['title']) ?></a> <span class="st"><?= stars_text($best['avg']) ?></span><?php endif; ?></p>
  <?php else: ?>
    <p class="dsum"><?= $cat === 'outing' ? '아직 나들이 일기가 없어요. 사진 몇 장과 별점만 남겨도 좋은 추억이 되고, 별점은 나들이 추천에도 반영돼요.' : '아직 일기가 없어요. 사진 한 장과 한 줄이면 충분해요.' ?></p>
  <?php endif; ?>
</section>

<?php foreach ($byMonth as $ym => $list): ?>
  <h3 class="dmonth"><?= (int) substr($ym, 0, 4) ?>년 <?= (int) substr($ym, 5, 2) ?>월 <span class="muted">· <?= count($list) ?>편</span></h3>
  <div class="dlist">
    <?php foreach ($list as $e): ?>
      <a class="dcard" href="diary_view.php?id=<?= (int) $e['id'] ?>">
        <?php if ($e['cover']): ?>
          <div class="cover"><img src="diary_photo.php?id=<?= $e['cover'] ?>&t=1" alt="" loading="lazy"><?php if (count($e['photos']) > 1): ?><span class="cnt">📷 <?= count($e['photos']) ?></span><?php endif; ?></div>
        <?php else: ?>
          <div class="cover none"><?= DIARY_CATEGORIES[$e['category']][1] ?? '📔' ?></div>
        <?php endif; ?>
        <div class="txt">
          <div class="small muted"><?php if (!$cat): ?><span class="dbadge <?= h($e['category']) ?>"><?= implode(' ', array_reverse(DIARY_CATEGORIES[$e['category']] ?? ['일기', '📔'])) ?></span> <?php endif; ?><?= date('n/j', strtotime($e['day'])) ?> (<?= weekday_short($e['day']) ?>)<?= $e['weather'] ? ' · ' . h($e['weather']) : '' ?></div>
          <div class="t"><?= h($e['title'] ?: $e['place_name']) ?></div>
          <?php if ($e['title'] && $e['place_name']): ?><div class="small muted">📍 <?= h($e['place_name']) ?></div><?php endif; ?>
          <?php if (($e['avg'] !== null || $e['again'] || isset($spentBy[(int) $e['id']]))): ?><div class="st"><?= stars_text($e['avg']) ?><?= $e['again'] ? ' 💛' : '' ?><?php if (isset($spentBy[(int) $e['id']])): ?> <span class="spent">💰 <?= won($spentBy[(int) $e['id']], true) ?></span><?php endif; ?></div><?php endif; ?>
          <?php if ($e['kid_said']): ?><div class="small kid">👧 “<?= h(mb_strimwidth(trim($e['kid_said'], " \"“”"), 0, 60, '…')) ?>”</div>
          <?php elseif (trim((string) $e['body']) !== ''): ?><div class="small muted"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', $e['body']), 0, 70, '…')) ?></div><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php page_end('family');
