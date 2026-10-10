<?php
// 찾기: 일기 · 할 일 · 일정 · 수첩 · 장보기 · 식단을 한 번에
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/todo.php';

$me = require_login();
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 40);
$like = '%' . addcslashes($q, '%_\\') . '%';
$res = [];
if (mb_strlen($q) >= 1) {
    $run = function (string $sql, int $n) use ($like) {
        $stmt = db()->prepare($sql);
        $stmt->execute(array_fill(0, $n, $like));
        return $stmt->fetchAll();
    };
    $res['diary'] = $run('SELECT id, day, title, place_name, category, body, kid_said FROM diary_entries WHERE title LIKE ? OR place_name LIKE ? OR body LIKE ? OR kid_said LIKE ? ORDER BY day DESC LIMIT 20', 4);
    $res['todo'] = $run('SELECT * FROM todos WHERE title LIKE ? OR note LIKE ? ORDER BY done, due_day IS NULL, due_day DESC LIMIT 20', 2);
    // 반복 일정은 다가오는 한 번만 (없으면 가장 최근 한 번)
    $evs = $run('SELECT * FROM calendar_events WHERE title LIKE ? OR location LIKE ? OR note LIKE ? ORDER BY start_at LIMIT 300', 3);
    $pick = [];
    foreach ($evs as $e) {
        $k = strtok($e['uid'], '#');
        $up = $e['start_at'] >= today() . ' 00:00:00';
        if (!isset($pick[$k]) || (!$pick[$k]['_up'] && ($up || $e['start_at'] > $pick[$k]['start_at']))) $pick[$k] = $e + ['_up' => $up];
    }
    usort($pick, fn($a, $b) => [!$a['_up'], $a['_up'] ? $a['start_at'] : -strtotime($a['start_at'])] <=> [!$b['_up'], $b['_up'] ? $b['start_at'] : -strtotime($b['start_at'])]);
    $res['event'] = array_slice($pick, 0, 20);
    $res['note'] = $run('SELECT * FROM notes WHERE label LIKE ? OR value LIKE ? ORDER BY id LIMIT 10', 2);
    $res['shop'] = $run('SELECT * FROM shopping WHERE name LIKE ? ORDER BY done, id DESC LIMIT 10', 1);
    $res['meal'] = $run('SELECT m.id, m.day, m.meal_type, m.member_id, GROUP_CONCAT(i.name SEPARATOR ", ") foods FROM meals m JOIN meal_items i ON i.meal_id = m.id
        WHERE i.name LIKE ? GROUP BY m.id ORDER BY m.day DESC LIMIT 10', 1);
}
$total = array_sum(array_map('count', $res));
$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
$hl = function (string $text) use ($q): string {
    $t = h($text);
    return $q === '' ? $t : preg_replace('/' . preg_quote(h($q), '/') . '/iu', '<mark>$0</mark>', $t);
};
$snip = function (string $text) use ($q): string {
    $text = preg_replace('/\s+/u', ' ', $text);
    $pos = $q !== '' ? mb_stripos($text, $q) : false;
    $start = $pos === false ? 0 : max(0, $pos - 12);
    return ($start > 0 ? '…' : '') . mb_substr($text, $start, 46) . (mb_strlen($text) > $start + 46 ? '…' : '');
};

page_start('찾기', 'more', ['back' => 'index.php']);
?>
<form method="get" class="lsearch" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="일기 · 할 일 · 일정 · 수첩 · 장보기 · 식단" enterkeyhint="search" autofocus aria-label="찾기">
  <?php if ($q !== ''): ?><a class="x" href="search.php" aria-label="지우기">✕</a><?php endif; ?>
</form>

<?php if ($q === ''): ?>
  <section class="card tempty"><div class="big">🔍</div><b>무엇이든 찾아보세요</b><p class="small muted">「서울숲」 「치과」 「코스트코」 「하린」처럼 적으면 일기 · 할 일 · 일정 · 수첩 · 장보기 · 식단에서 한꺼번에 찾아요.</p></section>
<?php elseif (!$total): ?>
  <section class="card tempty"><div class="big">🤔</div><b>「<?= h($q) ?>」 찾은 것이 없어요</b></section>
<?php else: ?>
  <p class="small muted" style="margin:0 6px 4px">「<?= h($q) ?>」 <?= $total ?>개</p>

  <?php if ($res['diary']): ?>
    <h3 class="listhead">📔 일기 <span><?= count($res['diary']) ?></span></h3>
    <div class="card rows">
      <?php foreach ($res['diary'] as $d): $text = trim($d['kid_said'] . ' ' . $d['body']); ?>
        <a class="row" href="diary_view.php?id=<?= (int) $d['id'] ?>"><span class="ic"><?= $d['category'] === 'outing' ? '🧺' : '🏠' ?></span>
          <span class="grow"><span class="t"><?= $hl($d['title'] ?: $d['place_name'] ?: '일기') ?></span><span class="s"><?= date('Y.n.j', strtotime($d['day'])) ?><?= $text !== '' ? ' · ' . $hl($snip($text)) : '' ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($res['todo']): ?>
    <h3 class="listhead">✅ 할 일 <span><?= count($res['todo']) ?></span></h3>
    <div class="card rows">
      <?php foreach ($res['todo'] as $t): $o = $t['owner_id'] ? ($names[(int) $t['owner_id']] ?? null) : null; ?>
        <a class="row" href="todo.php?edit=<?= (int) $t['id'] ?>#form"><span class="ic"><?= $t['done'] ? '☑️' : '⬜️' ?></span>
          <span class="grow"><span class="t"<?= $t['done'] ? ' style="color:var(--dim);text-decoration:line-through"' : '' ?>><?= $hl($t['title']) ?></span><span class="s"><?= $t['due_day'] ? h(todo_day_label($t['due_day'])) : '날짜 없음' ?> · <?= $o ? h($o['emoji'] . ' ' . $o['name']) : '👨‍👩‍👧 같이' ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($res['event']): ?>
    <h3 class="listhead">📅 일정 <span><?= count($res['event']) ?></span></h3>
    <div class="card rows">
      <?php foreach ($res['event'] as $e): $d = substr($e['start_at'], 0, 10); ?>
        <a class="row" href="calendar.php?m=<?= substr($d, 0, 7) ?>&d=<?= $d ?>"><span class="ic" style="color:<?= h($e['color']) ?>">●</span>
          <span class="grow"><span class="t"><?= $hl($e['title']) ?></span><span class="s"><?= !empty($e['recurring']) ? '🔁 ' : '' ?><?= h(day_label($d)) ?><?= $e['all_day'] ? ' · 종일' : ' ' . substr($e['start_at'], 11, 5) ?><?= $e['location'] ? ' · ' . $hl($e['location']) : '' ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($res['note']): ?>
    <h3 class="listhead">📒 가족 수첩 <span><?= count($res['note']) ?></span></h3>
    <div class="card rows">
      <?php foreach ($res['note'] as $n): $nm = $n['member_id'] ? ($names[(int) $n['member_id']] ?? null) : null; ?>
        <a class="row" href="notes.php#<?= $n['member_id'] ? 'm' . (int) $n['member_id'] : 'home' ?>"><span class="ic"><?= $nm ? h($nm['emoji']) : '🏠' ?></span>
          <span class="grow"><span class="t"><?= $hl($n['label']) ?></span><span class="s"><?= $hl($n['value']) ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($res['shop']): ?>
    <h3 class="listhead">🛒 장보기 <span><?= count($res['shop']) ?></span></h3>
    <div class="card rows">
      <?php foreach ($res['shop'] as $s): ?>
        <a class="row" href="shop.php"><span class="ic"><?= $s['done'] ? '☑️' : '⬜️' ?></span><span class="grow"><span class="t"><?= $hl($s['name']) ?></span><span class="s"><?= $s['done'] ? '샀어요' : '살 것' ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($res['meal']): ?>
    <h3 class="listhead">🍚 식단 <span><?= count($res['meal']) ?></span></h3>
    <div class="card rows">
      <?php foreach ($res['meal'] as $m): $who = $names[(int) $m['member_id']] ?? null; ?>
        <a class="row" href="meals.php?m=<?= (int) $m['member_id'] ?>&day=<?= h($m['day']) ?>"><span class="ic"><?= $who ? h($who['emoji']) : '🍚' ?></span>
          <span class="grow"><span class="t"><?= $hl(mb_strimwidth($m['foods'], 0, 40, '…')) ?></span><span class="s"><?= date('Y.n.j', strtotime($m['day'])) ?> · <?= h(MEAL_TYPES[$m['meal_type']][0] ?? '') ?></span></span><span class="chev">›</span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php page_end('more');
