<?php
// 일기 › 아이 어록: 일기에 남긴 「아이가 한 말」을 모아 보기 (최근 순, 해마다)
require __DIR__ . '/lib/bootstrap.php';

$me = require_login();
$rows = db()->query("SELECT id, day, title, place_name, kid_said FROM diary_entries WHERE kid_said <> '' ORDER BY day DESC, id DESC")->fetchAll();
$kid = members('child')[0] ?? null;
$byYear = [];
foreach ($rows as $r) $byYear[substr($r['day'], 0, 4)][] = $r;

page_start(($kid ? $kid['name'] : '아이') . ' 어록', 'diary');
if (!$rows): ?>
  <section class="card tempty"><div class="big">💬</div><b>아직 모은 말이 없어요</b><p class="small muted">일기를 쓸 때 「<?= h($kid['name'] ?? '아이') ?>가 한 말」 칸에 적으면 여기에 모여요. 나중에 보면 제일 소중한 기록이 돼요.</p><a class="btn primary" href="diary_edit.php?cat=daily">📔 일기 쓰기</a></section>
<?php else: ?>
  <?php $pick = $rows[crc32(today()) % count($rows)]; ?>
  <section class="card qhero">
    <div class="small muted">오늘의 한마디 · <?= date('Y.n.j', strtotime($pick['day'])) ?></div>
    <blockquote>“<?= h(trim($pick['kid_said'], " \"“”")) ?>”</blockquote>
    <a class="small" href="diary_view.php?id=<?= (int) $pick['id'] ?>"><?= h($pick['title'] ?: $pick['place_name'] ?: '그날 일기') ?> ›</a>
  </section>
  <?php foreach ($byYear as $y => $list): ?>
    <h3 class="listhead"><?= h($y) ?>년 <span><?= count($list) ?></span></h3>
    <div class="card rows">
      <?php foreach ($list as $r): ?>
        <a class="row" href="diary_view.php?id=<?= (int) $r['id'] ?>"><span class="ic">💬</span>
          <span class="grow"><span class="t" style="white-space:normal">“<?= h(trim($r['kid_said'], " \"“”")) ?>”</span><span class="s"><?= date('n월 j일', strtotime($r['day'])) ?><?= ($r['title'] ?: $r['place_name']) ? ' · ' . h($r['title'] ?: $r['place_name']) : '' ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif;
page_end('diary');
