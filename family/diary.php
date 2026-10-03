<?php
// 나들이 일기 목록: 달마다 모아 보는 우리 가족 나들이 기록
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/places.php';

$me = require_login();
$years = array_map('intval', db()->query('SELECT DISTINCT YEAR(day) y FROM diary_entries ORDER BY y DESC')->fetchAll(PDO::FETCH_COLUMN));
$year = (int) ($_GET['y'] ?? 0);
if ($year && !in_array($year, $years, true)) $year = 0;
$entries = diary_entries($year ?: null);

// 숫자로 보는 기록
$photoCount = array_sum(array_map(fn($e) => count($e['photos']), $entries));
$places = count(array_unique(array_map(fn($e) => $e['place_id'] ?: $e['place_name'], $entries)));
$rated = array_filter($entries, fn($e) => $e['avg'] !== null);
usort($rated, fn($a, $b) => [$b['avg'], $b['day']] <=> [$a['avg'], $a['day']]);
$best = $rated[0] ?? null;

// 몇 년 전 오늘 (앞뒤 3일)
$memories = db()->query("SELECT id FROM diary_entries WHERE YEAR(day) < YEAR(CURDATE())
    AND ABS(DATEDIFF(DATE_ADD(day, INTERVAL (YEAR(CURDATE()) - YEAR(day)) YEAR), CURDATE())) <= 3 ORDER BY day DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$memories = array_filter(array_map(fn($id) => diary_entry((int) $id), $memories));

$pending = diary_pending_plans();

$byMonth = [];
foreach ($entries as $e) $byMonth[substr($e['day'], 0, 7)][] = $e;

page_start('나들이 일기', 'family');
?>
<?php foreach ($pending as $pl): $pp = place($pl['place_id']); if (!$pp) continue; ?>
  <section class="card" style="display:flex;gap:12px;align-items:center">
    <span style="font-size:28px">📌</span>
    <span class="grow"><b><?= date('n/j', strtotime($pl['day'])) ?> <?= h($pp['name']) ?></b><div class="small muted">다녀오셨나요? 사진이랑 별점을 남겨 두세요.</div></span>
    <a class="btn small primary" href="diary_edit.php?place=<?= rawurlencode($pl['place_id']) ?>&day=<?= h($pl['day']) ?>">일기 쓰기</a>
  </section>
<?php endforeach; ?>

<?php foreach ($memories as $mem): ?>
  <a class="card memory" href="diary_view.php?id=<?= (int) $mem['id'] ?>">
    <?php if ($mem['cover']): ?><img src="diary_photo.php?id=<?= $mem['cover'] ?>&t=1" alt="" loading="lazy"><?php endif; ?>
    <span><span class="small muted">🕰 <?= date('Y') - (int) substr($mem['day'], 0, 4) ?>년 전 이맘때</span><br><b><?= h($mem['title'] ?: $mem['place_name']) ?></b></span>
  </a>
<?php endforeach; ?>

<section class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
    <h2 style="margin:0">📔 <?= $year ? $year . '년' : '모든' ?> 나들이</h2>
    <a class="btn primary small" href="diary_edit.php">✍️ 일기 쓰기</a>
  </div>
  <?php if (count($years) > 1): ?>
    <div class="chips" style="margin-top:10px">
      <a class="chip<?= $year ? '' : ' on' ?>" href="diary.php">전체</a>
      <?php foreach ($years as $y): ?><a class="chip<?= $y === $year ? ' on' : '' ?>" href="diary.php?y=<?= $y ?>"><?= $y ?></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($entries): ?>
    <div class="dstats">
      <div><b><?= count($entries) ?></b><span>번 나들이</span></div>
      <div><b><?= $places ?></b><span>곳</span></div>
      <div><b><?= $photoCount ?></b><span>장 사진</span></div>
    </div>
    <?php if ($best): ?><p class="small muted" style="margin:8px 0 0">🏆 최고 별점: <a href="diary_view.php?id=<?= (int) $best['id'] ?>"><?= h($best['place_name'] ?: $best['title']) ?></a> <span style="color:#f59e0b"><?= stars_text($best['avg']) ?></span></p><?php endif; ?>
  <?php else: ?>
    <p class="muted" style="margin:12px 0 0">아직 일기가 없어요. 나들이 다녀온 날 사진 몇 장과 별점만 남겨도 나중에 좋은 추억이 돼요.<br>
    남긴 별점은 나들이 추천에도 반영돼요 (좋았던 곳은 다시 추천, 별로였던 곳은 뒤로).</p>
  <?php endif; ?>
</section>

<?php foreach ($byMonth as $ym => $list): ?>
  <h3 class="dmonth"><?= (int) substr($ym, 0, 4) ?>년 <?= (int) substr($ym, 5, 2) ?>월 <span class="muted">· <?= count($list) ?>번</span></h3>
  <div class="dlist">
    <?php foreach ($list as $e): ?>
      <a class="dcard" href="diary_view.php?id=<?= (int) $e['id'] ?>">
        <?php if ($e['cover']): ?>
          <div class="cover"><img src="diary_photo.php?id=<?= $e['cover'] ?>&t=1" alt="" loading="lazy"><?php if (count($e['photos']) > 1): ?><span class="cnt">📷 <?= count($e['photos']) ?></span><?php endif; ?></div>
        <?php else: ?>
          <div class="cover none">🧺</div>
        <?php endif; ?>
        <div class="txt">
          <div class="small muted"><?= date('n/j', strtotime($e['day'])) ?> (<?= weekday_short($e['day']) ?>)<?= $e['weather'] ? ' · ' . h($e['weather']) : '' ?></div>
          <div class="t"><?= h($e['title'] ?: $e['place_name']) ?></div>
          <?php if ($e['title'] && $e['place_name']): ?><div class="small muted">📍 <?= h($e['place_name']) ?></div><?php endif; ?>
          <?php if ($e['avg'] !== null || $e['again']): ?><div class="st"><?= stars_text($e['avg']) ?><?= $e['again'] ? ' 💛' : '' ?></div><?php endif; ?>
          <?php if ($e['kid_said']): ?><div class="small kid">👧 “<?= h(mb_strimwidth(trim($e['kid_said'], " \"“”"), 0, 60, '…')) ?>”</div>
          <?php elseif (trim((string) $e['body']) !== ''): ?><div class="small muted"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', $e['body']), 0, 70, '…')) ?></div><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php page_end('family');
