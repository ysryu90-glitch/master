<?php
// 아이 › 어록: 일기의 「아이가 한 말」 + 오늘의 질문에 아이가 한 대답을 모아 보기 (해마다)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/question.php';

$me = require_login();
$kid = members('child')[0] ?? null;
$rows = [];
foreach (db()->query("SELECT id, day, title, place_name, kid_said FROM diary_entries WHERE kid_said <> '' ORDER BY day DESC, id DESC") as $r) {
    $rows[] = ['day' => $r['day'], 'text' => trim($r['kid_said'], " \"“”"), 'sub' => $r['title'] ?: $r['place_name'] ?: '일기', 'url' => 'diary_view.php?id=' . (int) $r['id'], 'icon' => '📔'];
}
if ($kid) {
    $st = db()->prepare('SELECT day, answer FROM question_answers WHERE member_id = ? ORDER BY day DESC');
    $st->execute([$kid['id']]);
    foreach ($st as $r) $rows[] = ['day' => $r['day'], 'text' => $r['answer'], 'sub' => '질문: ' . question_of_day($r['day']), 'url' => '', 'icon' => '💬'];
}
usort($rows, fn($a, $b) => strcmp($b['day'], $a['day']));
$byYear = [];
foreach ($rows as $r) $byYear[substr($r['day'], 0, 4)][] = $r;
$name = $kid['name'] ?? '아이';

page_start($name . ' 어록', 'kid');
if (!$rows): ?>
  <section class="card tempty"><div class="big">💬</div><b>아직 모은 말이 없어요</b><p class="small muted">일기를 쓸 때 「<?= h($name) ?>가 한 말」 칸에 적거나, 홈의 「오늘의 질문」에 <?= h($name) ?>의 대답을 적어 주면 여기에 모여요. 나중에 보면 제일 소중한 기록이 돼요.</p><a class="btn primary" href="diary_edit.php?cat=daily">📔 일기 쓰기</a></section>
<?php else: ?>
  <?php $pick = $rows[crc32(today()) % count($rows)]; ?>
  <section class="card qhero">
    <div class="small muted">오늘의 한마디 · <?= date('Y.n.j', strtotime($pick['day'])) ?></div>
    <blockquote>“<?= h($pick['text']) ?>”</blockquote>
    <span class="small muted"><?= h($pick['sub']) ?></span>
  </section>
  <?php foreach ($byYear as $y => $list): ?>
    <h3 class="listhead"><?= h($y) ?>년 <span><?= count($list) ?></span></h3>
    <div class="card rows">
      <?php foreach ($list as $r): $tag = $r['url'] ? 'a' : 'div'; ?>
        <<?= $tag ?> class="row"<?= $r['url'] ? ' href="' . h($r['url']) . '"' : '' ?>><span class="ic"><?= $r['icon'] ?></span>
          <span class="grow"><span class="t" style="white-space:normal">“<?= h($r['text']) ?>”</span><span class="s" style="white-space:normal"><?= date('n월 j일', strtotime($r['day'])) ?> · <?= h($r['sub']) ?></span></span><?= $r['url'] ? '<span class="chev">›</span>' : '' ?></<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif;
page_end('kid');
