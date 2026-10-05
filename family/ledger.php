<?php
// 가계부: 한 달 지출 · 수입, 항목별 합계, 예산, 일기와 연결 (카드 결제 문자 자동 입력은 ledger_guide.php)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/diary.php';
require __DIR__ . '/lib/ledger.php';
require __DIR__ . '/lib/weather.php'; // 공휴일 (달력 빨간 날)

$me = require_login();
check_csrf();

$ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$cat = isset(LEDGER_CATEGORIES[$_GET['c'] ?? '']) || ($_GET['c'] ?? '') === 'income' ? $_GET['c'] : '';
$view = in_array($_GET['v'] ?? '', ['cal', 'list', 'stats'], true) ? $_GET['v'] : 'cal';
$selDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['d'] ?? '')) && str_starts_with($_GET['d'], $ym) ? $_GET['d'] : ($ym === date('Y-m') ? today() : '');
$back = 'ledger.php?m=' . $ym . '&v=' . $view . ($cat ? '&c=' . $cat : '') . ($selDay && $view === 'cal' ? '&d=' . $selDay : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $id = (int) post('id');
    switch (post('action')) {
        case 'save':
            $amount = (int) preg_replace('/[^\d]/', '', (string) post('amount'));
            if ($amount <= 0) { flash('금액을 넣어 주세요.'); redirect($back . '#form'); }
            $kind = post('kind') === 'in' ? 'in' : 'out';
            $category = $kind === 'in' ? 'income' : (isset(LEDGER_CATEGORIES[post('category')]) ? post('category') : 'etc');
            $day = valid_day(post('day'));
            $diary = (int) post('diary') ?: null;
            $payer = (int) post('member') ?: null;
            if ($id) {
                $old = $pdo->prepare('SELECT amount FROM expenses WHERE id = ?');
                $old->execute([$id]);
                $sign = ((int) $old->fetchColumn()) < 0 ? -1 : 1; // 취소 기록은 마이너스 그대로
                $pdo->prepare('UPDATE expenses SET day = ?, kind = ?, amount = ?, category = ?, merchant = ?, memo = ?, member_id = ?, diary_id = ?, checked = 1, updated_at = NOW() WHERE id = ?')
                    ->execute([$day, $kind, $sign * $amount, $category, mb_substr(post('merchant'), 0, 100), mb_substr(post('memo'), 0, 200), $payer, $diary, $id]);
                flash('고쳤어요.');
            } else {
                expense_add(['day' => $day, 'time' => $day === today() ? date('H:i') : null, 'kind' => $kind, 'amount' => $amount, 'category' => $category,
                    'merchant' => post('merchant'), 'memo' => post('memo'), 'member_id' => $payer, 'diary_id' => $diary, 'created_by' => (int) $me['id']]);
                flash(ledger_cat($category)[1] . ' ' . won($amount) . ' 기록했어요.');
            }
            redirect('ledger.php?m=' . substr($day, 0, 7) . '&v=' . $view . ($view === 'cal' ? '&d=' . $day : '') . ($cat ? '&c=' . $cat : '') . ($view === 'cal' ? '#day' : ''));
        case 'recat':
            if (isset(LEDGER_CATEGORIES[post('category')])) {
                $pdo->prepare('UPDATE expenses SET category = ?, checked = 1, updated_at = NOW() WHERE id = ?')->execute([post('category'), $id]);
            }
            redirect($back . '&review=1#review');
        case 'check_all':
            $pdo->exec('UPDATE expenses SET checked = 1 WHERE checked = 0');
            redirect($back);
        case 'delete':
            $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
            flash('지웠어요.');
            redirect($back);
    }
    redirect($back);
}

ledger_recurring_fill(); // 고정 지출 (매달 같은 날)
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$sum = expenses_summary($ym);
$list = expenses_month($ym, $cat ?: null);
$review = db()->query('SELECT * FROM expenses WHERE checked = 0 ORDER BY day DESC, id DESC LIMIT 20')->fetchAll();
$budget = ledger_budget();
$isNow = $ym === date('Y-m');
$prev = date('Y-m', strtotime($ym . '-01 -1 month'));
$next = date('Y-m', strtotime($ym . '-01 +1 month'));

// 지난달 같은 날까지 쓴 돈 (이번 달일 때만 비교)
$compare = null;
if ($isNow) {
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE kind = 'out' AND day BETWEEN ? AND ?");
    $stmt->execute([$prev . '-01', date('Y-m-d', min(strtotime($prev . '-' . date('d')), strtotime(date('Y-m-t', strtotime($prev . '-01')))))]);
    $compare = $sum['out'] - (int) $stmt->fetchColumn();
}

$names = [];
foreach (members() as $m) $names[(int) $m['id']] = $m;
$adults = members('adult');
$diaries = diary_entries(null, 60);
$diaryTitle = [];
foreach ($diaries as $d) $diaryTitle[(int) $d['id']] = date('n/j', strtotime($d['day'])) . ' ' . ($d['title'] ?: $d['place_name']);

$byDay = [];
foreach ($list as $x) $byDay[$x['day']][] = $x;

// 달력: 날짜별 지출 · 수입 합계와 일기 있는 날
$dayOut = $dayIn = [];
$stmt = db()->prepare("SELECT day, kind, SUM(amount) s FROM expenses WHERE day BETWEEN ? AND LAST_DAY(?) GROUP BY day, kind");
$stmt->execute([$ym . '-01', $ym . '-01']);
foreach ($stmt as $r) { if ($r['kind'] === 'in') $dayIn[$r['day']] = (int) $r['s']; else $dayOut[$r['day']] = (int) $r['s']; }
$stmt = db()->prepare('SELECT day, MIN(id) id FROM diary_entries WHERE day BETWEEN ? AND LAST_DAY(?) GROUP BY day');
$stmt->execute([$ym . '-01', $ym . '-01']);
$diaryDays = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$maxDay = $dayOut ? max(max($dayOut), 1) : 1;
// 아직 안 온 고정 지출 (달력에 「예정」으로)
$dayPlan = [];
if ($ym >= date('Y-m')) foreach (ledger_recurring() as $r) {
    $pd = ledger_recurring_day($r, $ym);
    if ($pd > today() && $pd >= $r['start_day'] && $r['kind'] === 'out') $dayPlan[$pd][] = $r;
}
$planLeft = array_sum(array_map(fn($rs) => array_sum(array_column($rs, 'amount')), $dayPlan));

// 통계: 최근 6개월 지출 · 낸 사람별
$six = [];
for ($i = 5; $i >= 0; $i--) $six[date('Y-m', strtotime($ym . "-01 -$i month"))] = 0;
$stmt = db()->prepare("SELECT DATE_FORMAT(day, '%Y-%m') m, SUM(amount) s FROM expenses WHERE kind = 'out' AND day BETWEEN ? AND LAST_DAY(?) GROUP BY m");
$stmt->execute([array_key_first($six) . '-01', $ym . '-01']);
foreach ($stmt as $r) if (isset($six[$r['m']])) $six[$r['m']] = (int) $r['s'];
$stmt = db()->prepare("SELECT member_id, SUM(amount) s FROM expenses WHERE kind = 'out' AND day BETWEEN ? AND LAST_DAY(?) GROUP BY member_id ORDER BY s DESC");
$stmt->execute([$ym . '-01', $ym . '-01']);
$byPayer = $stmt->fetchAll();

/** 달력 칸에 들어갈 짧은 금액: 3,800 / 1.3만 / 150만 */
function cell_won(int $n): string
{
    $a = abs($n);
    if ($a < 10000) return number_format($n);
    $v = $n / 10000;
    $t = number_format($v, abs($v) >= 100 ? 0 : 1);
    return (str_contains($t, '.') ? rtrim(rtrim($t, '0'), '.') : $t) . '만';
}
$q = fn(array $p) => 'ledger.php?' . http_build_query(array_filter($p + ['m' => $ym, 'v' => $view, 'c' => $cat], fn($v) => $v !== '' && $v !== null));

// 자주 쓰는 가게 (적을 때 고르면 항목도 같이)
$recentMerchants = db()->query("SELECT merchant, SUBSTRING_INDEX(GROUP_CONCAT(category ORDER BY id DESC), ',', 1) cat FROM expenses
    WHERE merchant <> '' AND kind = 'out' AND day > DATE_SUB(CURDATE(), INTERVAL 120 DAY) GROUP BY merchant ORDER BY COUNT(*) DESC, MAX(id) DESC LIMIT 40")->fetchAll(PDO::FETCH_KEY_PAIR);
$f = $edit ?? ['id' => 0, 'kind' => 'out', 'amount' => '', 'category' => $cat && $cat !== 'income' ? $cat : '', 'merchant' => '', 'memo' => '',
    'day' => isset($_GET['day']) ? valid_day($_GET['day']) : ($selDay && $selDay <= today() ? $selDay : today()), 'member_id' => $me['id'], 'diary_id' => (int) ($_GET['diary'] ?? 0) ?: null];

page_start('가계부', 'ledger');
?>
<nav class="monthnav">
  <a class="btn small" href="<?= h($q(['m' => $prev, 'd' => null])) ?>" aria-label="지난달">‹</a>
  <b><?= (int) substr($ym, 0, 4) ?>년 <?= (int) substr($ym, 5) ?>월</b>
  <a class="btn small" href="<?= h($q(['m' => $next, 'd' => null])) ?>" aria-label="다음 달"<?= $isNow ? ' style="visibility:hidden"' : '' ?>>›</a>
</nav>

<section class="card lsum2">
  <div class="cols">
    <div><span class="k">수입</span><b class="in"><?= $sum['in'] ? '+' . won($sum['in']) : '0원' ?></b></div>
    <div><span class="k">지출</span><b class="out"><?= $sum['out'] ? '-' . won($sum['out']) : '0원' ?></b></div>
    <div><span class="k">합계</span><b><?= won($sum['in'] - $sum['out']) ?></b></div>
  </div>
  <?php if ($budget): $pct = min(100, $sum['out'] / $budget * 100); $left = $budget - $sum['out'];
      $daysLeft = $isNow ? (int) date('t') - (int) date('j') + 1 : 0; ?>
    <div class="meter <?= $pct >= 100 ? 'red' : ($pct >= 80 ? 'orange' : '') ?>" style="margin-top:10px;height:8px"><i style="width:<?= $pct ?>%"></i></div>
    <div class="small" style="margin-top:5px;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
      <span class="muted">예산 <?= won($budget, true) ?> 중 <?= round($sum['out'] / $budget * 100) ?>%<?= $planLeft ? ' · 고정 예정 ' . won($planLeft, true) : '' ?></span>
      <span style="font-weight:700;color:<?= $left < 0 ? 'var(--red)' : 'var(--text)' ?>"><?= $left < 0 ? won(-$left, true) . ' 넘었어요' : '남은 돈 ' . won($left, true) . ($daysLeft ? ' · 하루 ' . won((int) max(0, floor(($left - $planLeft) / $daysLeft)), true) : '') ?></span>
    </div>
  <?php endif; ?>
</section>

<?php if ($review): ?>
<section class="card review" id="review">
  <details class="fold"<?= isset($_GET['review']) ? ' open' : '' ?>>
  <summary>📲 항목을 골라 주세요 <span class="badge"><?= count($review) ?></span></summary>
  <p class="small muted" style="margin:6px 0 0">자동으로 들어왔는데 항목을 못 정한 기록이에요. 결제가 아니면 지워 주세요.</p>
  <?php foreach ($review as $x): ?>
    <div class="rv">
      <div><b><?= h($x['merchant'] ?: '결제') ?></b> <span class="small muted"><?= date('n/j', strtotime($x['day'])) ?><?= $x['card'] ? ' · ' . h($x['card']) : '' ?></span> <b style="float:right"><?= won((int) $x['amount']) ?></b></div>
      <form method="post" class="chips" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="recat"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
        <?php foreach (LEDGER_CATEGORIES as $k => [$cn, $ci]): ?><button class="chip" name="category" value="<?= $k ?>"><?= $ci ?> <?= h($cn) ?></button><?php endforeach; ?>
      </form>
      <form method="post" data-confirm="이 기록을 지울까요?" style="margin-top:4px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>"><button class="btn small danger">🗑 결제가 아니에요 (지우기)</button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="check_all"><button class="btn small">모두 이대로 둘게요</button></form>
  </details>
</section>
<?php endif; ?>

<nav class="segmented ltabs">
  <a class="<?= $view === 'cal' ? 'on' : '' ?>" href="<?= h($q(['v' => 'cal', 'c' => ''])) ?>">📅 달력</a>
  <a class="<?= $view === 'list' ? 'on' : '' ?>" href="<?= h($q(['v' => 'list'])) ?>">📋 내역</a>
  <a class="<?= $view === 'stats' ? 'on' : '' ?>" href="<?= h($q(['v' => 'stats', 'c' => ''])) ?>">📊 통계</a>
</nav>

<?php if ($view === 'cal'):
    $first = strtotime($ym . '-01');
    $lead = (int) date('w', $first);
    $nDays = (int) date('t', $first); ?>
<section class="card lcal">
  <div class="wk"><span class="sun">일</span><span>월</span><span>화</span><span>수</span><span>목</span><span>금</span><span class="sat">토</span></div>
  <div class="grid">
    <?php for ($i = 0; $i < $lead; $i++): ?><span class="blank"></span><?php endfor; ?>
    <?php for ($dn = 1; $dn <= $nDays; $dn++):
        $d = sprintf('%s-%02d', $ym, $dn);
        $w = ($lead + $dn - 1) % 7;
        $o = $dayOut[$d] ?? 0; $in = $dayIn[$d] ?? 0;
        $heat = $o > 0 ? min(0.28, 0.06 + 0.22 * $o / $maxDay) : 0;
        $cls = trim(($d === $selDay ? 'sel ' : '') . ($d === today() ? 'today ' : '') . ($d > today() ? 'future ' : '') . ($w === 0 || isset(HOLIDAYS[$d]) ? 'sun ' : ($w === 6 ? 'sat ' : ''))); ?>
      <a class="<?= $cls ?>" href="<?= h($q(['d' => $d])) ?>#day"<?= $heat ? ' style="--heat:' . round($heat, 3) . '"' : '' ?>>
        <span class="n"><?= $dn ?><?= isset($diaryDays[$d]) ? '<i class="dd" title="일기 쓴 날"></i>' : '' ?></span>
        <span class="amts"><?php if (!empty($dayPlan[$d])): ?><span class="pplan">-<?= cell_won(array_sum(array_column($dayPlan[$d], 'amount'))) ?></span><?php endif; ?><?php if ($in): ?><span class="pin">+<?= cell_won($in) ?></span><?php endif; ?><?php if ($o): ?><span class="pout">-<?= cell_won($o) ?></span><?php endif; ?></span>
      </a>
    <?php endfor; ?>
  </div>
</section>

<section class="card" id="day">
  <?php if ($selDay):
      $rows = $byDay[$selDay] ?? [];
      $sOut = $dayOut[$selDay] ?? 0; $sIn = $dayIn[$selDay] ?? 0; ?>
    <div class="dayhead">
      <h2><?= date('n월 j일', strtotime($selDay)) ?> (<?= weekday_short($selDay) ?>)<?= $selDay === today() ? ' <span class="small muted">오늘</span>' : '' ?></h2>
      <span class="tot"><?= $sIn ? '<b class="in">+' . won($sIn) . '</b>' : '' ?><?= $sOut ? '<b class="out">-' . won($sOut) . '</b>' : '' ?></span>
    </div>
    <?php if ($rows): ?>
      <div class="lrows flat">
        <?php foreach ($rows as $x): [$cn, $ci] = ledger_cat($x['category']); $payer = $names[(int) $x['member_id']] ?? null; ?>
          <a class="lrow<?= (int) $x['amount'] < 0 ? ' cancel' : '' ?>" href="<?= h($q(['d' => $selDay, 'edit' => $x['id']])) ?>#form">
            <span class="ic"><?= $ci ?></span>
            <span class="grow">
              <span class="t"><?= h($x['merchant'] ?: $x['memo'] ?: $cn) ?></span>
              <span class="s"><?= h($cn) ?><?= $x['at_time'] ? ' · ' . substr($x['at_time'], 0, 5) : '' ?><?= $payer ? ' · ' . h($payer['emoji']) : '' ?><?= ['manual' => '', 'fixed' => ' · 🔁'][$x['source']] ?? ' · 📲' ?><?= $x['memo'] && $x['merchant'] ? ' · ' . h($x['memo']) : '' ?><?= $x['diary_id'] ? ' · 📔' : '' ?></span>
            </span>
            <span class="amt <?= $x['kind'] === 'in' ? 'in' : '' ?>"><?= $x['kind'] === 'in' ? '+' : '' ?><?= won((int) $x['amount']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php elseif (empty($dayPlan[$selDay])): ?>
      <p class="small muted" style="margin:0">이날 기록이 없어요.</p>
    <?php endif; ?>
    <?php foreach ($dayPlan[$selDay] ?? [] as $r): [$cn, $ci] = ledger_cat($r['category']); ?>
      <a class="lrow plan" href="ledger_guide.php#fixed"><span class="ic"><?= $ci ?></span><span class="grow"><span class="t"><?= h($r['merchant']) ?></span><span class="s">🔁 고정 · 이날 들어갈 예정</span></span><span class="amt"><?= won((int) $r['amount']) ?></span></a>
    <?php endforeach; ?>
    <div class="btn-row" style="margin-top:10px">
      <?php if ($selDay <= today()): ?><a class="btn small primary" href="<?= h($q(['d' => $selDay, 'add' => 1])) ?>#form" data-sheet="<?= h($selDay) ?>">＋ 이날 쓴 돈 적기</a><?php endif; ?>
      <?php if (isset($diaryDays[$selDay])): ?><a class="btn small" href="diary_view.php?id=<?= (int) $diaryDays[$selDay] ?>">📔 이날 일기</a>
      <?php elseif ($selDay <= today()): ?><a class="btn small" href="diary_edit.php?day=<?= h($selDay) ?>">📔 일기 쓰기</a><?php endif; ?>
    </div>
  <?php else: ?>
    <p class="small muted" style="margin:0">날짜를 누르면 그날 쓴 돈이 보여요.</p>
  <?php endif; ?>
</section>

<?php elseif ($view === 'list'): ?>
<?php if ($sum['byCat']): ?>
  <div class="chips scrollx" style="margin:0 -16px 10px;padding:0 16px">
    <a class="chip<?= $cat ? '' : ' on' ?>" href="<?= h($q(['c' => ''])) ?>">전체</a>
    <?php foreach ($sum['byCat'] as $k => $v): if ($v <= 0) continue; [$cn, $ci] = ledger_cat($k); ?>
      <a class="chip<?= $cat === $k ? ' on' : '' ?>" href="<?= h($q(['c' => $k])) ?>"><?= $ci ?> <?= h($cn) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if (!$list): ?>
  <section class="card"><p class="muted" style="margin:0"><?= $cat ? '이 항목의 기록이 없어요.' : '이번 달 기록이 아직 없어요. ＋를 눌러 적거나, 카드 결제를 자동으로 받아 보세요.' ?></p></section>
<?php endif; ?>
<?php foreach ($byDay as $day => $rows): $dOut = array_sum(array_map(fn($x) => $x['kind'] === 'out' ? (int) $x['amount'] : 0, $rows)); ?>
  <h3 class="lday"><span><?= date('n월 j일', strtotime($day)) ?> (<?= weekday_short($day) ?>)<?= $day === today() ? ' · 오늘' : '' ?></span><span><?= $dOut ? '-' . won($dOut) : '' ?></span></h3>
  <div class="card lrows">
    <?php foreach ($rows as $x): [$cn, $ci] = ledger_cat($x['category']); $payer = $names[(int) $x['member_id']] ?? null; ?>
      <a class="lrow<?= (int) $x['amount'] < 0 ? ' cancel' : '' ?>" href="<?= h($q(['edit' => $x['id']])) ?>#form">
        <span class="ic"><?= $ci ?></span>
        <span class="grow">
          <span class="t"><?= h($x['merchant'] ?: $x['memo'] ?: $cn) ?></span>
          <span class="s"><?= h($cn) ?><?= $x['at_time'] ? ' · ' . substr($x['at_time'], 0, 5) : '' ?><?= $payer ? ' · ' . h($payer['emoji']) : '' ?><?= $x['source'] === 'fixed' ? ' · 🔁 고정' : ($x['source'] !== 'manual' ? ' · 📲' . ($x['card'] ? ' ' . h($x['card']) : '') : '') ?><?= $x['memo'] && $x['merchant'] ? ' · ' . h($x['memo']) : '' ?><?= $x['diary_id'] && isset($diaryTitle[(int) $x['diary_id']]) ? ' · 📔 ' . h($diaryTitle[(int) $x['diary_id']]) : '' ?></span>
        </span>
        <span class="amt <?= $x['kind'] === 'in' ? 'in' : '' ?>"><?= $x['kind'] === 'in' ? '+' : '' ?><?= won((int) $x['amount']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php else: /* 통계 */ ?>
<section class="card">
  <h2>항목별 지출</h2>
  <?php if (!$sum['byCat']): ?><p class="small muted" style="margin:0">이번 달 지출이 없어요.</p><?php endif; ?>
  <?php if ($sum['byCat']): $max = max($sum['byCat']) ?: 1; $tot = max(1, $sum['out']); ?>
    <div class="catbars">
      <?php foreach ($sum['byCat'] as $k => $v): if ($v <= 0) continue; [$cn, $ci] = ledger_cat($k); ?>
        <a href="<?= h($q(['v' => 'list', 'c' => $k])) ?>">
          <span class="n"><?= $ci ?> <?= h($cn) ?></span><span class="bar"><i style="width:<?= max(3, $v / $max * 100) ?>%"></i></span><span class="v"><?= won($v, true) ?> <small class="muted"><?= round($v / $tot * 100) ?>%</small></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($compare !== null && $compare !== 0): ?><p class="small" style="margin:12px 0 0;color:<?= $compare > 0 ? 'var(--orange)' : 'var(--accent)' ?>">지난달 같은 기간보다 <?= won(abs($compare), true) ?> <?= $compare > 0 ? '더 썼어요' : '덜 썼어요' ?></p><?php endif; ?>
</section>

<section class="card">
  <h2>최근 6개월 지출</h2>
  <?php $smax = max(1, max($six)); ?>
  <div class="mbars">
    <?php foreach ($six as $m => $v): ?>
      <a class="<?= $m === $ym ? 'on' : '' ?>" href="<?= h($q(['m' => $m])) ?>">
        <span class="v"><?= $v ? cell_won($v) : '' ?></span>
        <span class="b"><i style="height:<?= $v ? max(4, round($v / $smax * 100)) : 0 ?>%"></i></span>
        <span class="l"><?= (int) substr($m, 5) ?>월</span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($byPayer): ?>
<section class="card">
  <h2>낸 사람별</h2>
  <?php foreach ($byPayer as $r): $m = $names[(int) $r['member_id']] ?? null; ?>
    <div class="row-between"><span><?= $m ? h($m['emoji'] . ' ' . $m['name']) : '같이 · 모름' ?></span><b><?= won((int) $r['s']) ?></b></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<section class="card">
  <h2>📥 엑셀로 내려받기</h2>
  <div class="btn-row">
    <a class="btn small" href="ledger_export.php?m=<?= $ym ?>" data-no-busy><?= (int) substr($ym, 5) ?>월 내역</a>
    <a class="btn small" href="ledger_export.php?y=<?= substr($ym, 0, 4) ?>" data-no-busy><?= substr($ym, 0, 4) ?>년 전체</a>
  </div>
</section>
<?php endif; ?>

<div class="sheet" id="form"<?= $edit || isset($_GET['add']) ? '' : ' hidden' ?> role="dialog" aria-modal="true" aria-labelledby="form-title">
  <div class="panel">
  <div class="sheet-head"><h2 id="form-title"><?= $edit ? '기록 고치기' : '쓴 돈 · 들어온 돈 적기' ?></h2><a class="x" href="<?= h($back) ?>" data-sheet-close aria-label="닫기">✕</a></div>
  <form method="post" class="form" data-busy="저장하는 중이에요…">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
    <div class="segmented" id="kind-seg">
      <label><input type="radio" name="kind" value="out" <?= $f['kind'] !== 'in' ? 'checked' : '' ?>><span>💸 지출</span></label>
      <label><input type="radio" name="kind" value="in" <?= $f['kind'] === 'in' ? 'checked' : '' ?>><span>💵 수입</span></label>
    </div>
    <label>금액<input name="amount" inputmode="numeric" class="amount" value="<?= $f['amount'] !== '' ? number_format(abs((int) $f['amount'])) : '' ?>" placeholder="0" required autocomplete="off"></label>
    <div class="catpick" id="catpick"<?= $f['kind'] === 'in' ? ' hidden' : '' ?>>
      <?php foreach (LEDGER_CATEGORIES as $k => [$cn, $ci]): ?>
        <label><input type="radio" name="category" value="<?= $k ?>" <?= $f['category'] === $k ? 'checked' : '' ?>><span><?= $ci ?> <?= h($cn) ?></span></label>
      <?php endforeach; ?>
    </div>
    <datalist id="merchants"><?php foreach ($recentMerchants as $mn => $mc): ?><option value="<?= h($mn) ?>"><?php endforeach; ?></datalist>
    <div class="grid2">
      <label>어디서 · 무엇<input name="merchant" value="<?= h($f['merchant']) ?>" placeholder="예: 이마트, 에버랜드" id="merchant" list="merchants" autocomplete="off"></label>
      <label>날짜<input type="date" name="day" value="<?= h($f['day']) ?>" max="<?= today() ?>"></label>
    </div>
    <?php if ($recentMerchants && !$edit): ?>
      <div class="chips scrollx recentm" style="margin:-4px -18px 10px;padding:0 18px"><span class="small muted" style="flex:none;align-self:center">자주:</span>
        <?php foreach (array_slice($recentMerchants, 0, 10, true) as $mn => $mc): ?><button type="button" class="chip" data-m="<?= h($mn) ?>" data-c="<?= h($mc) ?>"><?= ledger_cat($mc)[1] ?> <?= h(mb_strimwidth($mn, 0, 18, '…')) ?></button><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <details class="fold"<?= $edit || $f['diary_id'] || $f['memo'] ? ' open' : '' ?>>
      <summary>메모 · 낸 사람 · 일기 연결</summary>
      <label>메모<input name="memo" value="<?= h($f['memo']) ?>" placeholder="예: 하린 겨울 점퍼"></label>
      <div class="grid2">
        <label>낸 사람<select name="member"><?php foreach ($adults as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['member_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= h($a['emoji'] . ' ' . $a['name']) ?></option><?php endforeach; ?><option value="0" <?= !$f['member_id'] ? 'selected' : '' ?>>같이 · 모름</option></select></label>
        <label>📔 일기<select name="diary"><option value="0">연결 안 함</option><?php foreach ($diaryTitle as $did => $dt): ?><option value="<?= $did ?>" <?= (int) $f['diary_id'] === $did ? 'selected' : '' ?>><?= h($dt) ?></option><?php endforeach; ?></select></label>
      </div>
    </details>
    <div class="btn-row" style="margin-top:6px">
      <button class="btn primary wide"><?= $edit ? '고친 내용 저장' : '기록' ?></button>
    </div>
  </form>
  <?php if ($edit): ?>
    <div class="btn-row" style="margin-top:8px">
      <form method="post" data-confirm="이 기록을 지울까요?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn small danger">🗑 지우기</button></form>
    </div>
  <?php endif; ?>
  </div>
</div>
<a class="lfab" href="<?= h($q(['d' => $selDay ?: null, 'add' => 1])) ?>#form" data-sheet="<?= h($selDay && $selDay <= today() ? $selDay : today()) ?>" aria-label="쓴 돈 적기">＋</a>


<script>
(function () {
  // 금액에 쉼표 넣기 · 지출/수입 바꾸기 · 가게 이름으로 항목 추측
  var sheet = document.getElementById('form');
  var editing = <?= $edit ? 'true' : 'false' ?>;
  function openSheet(day) {
    if (!editing && day) { var d = sheet.querySelector('[name=day]'); if (d) d.value = day; }
    sheet.hidden = false; document.body.classList.add('noscroll');
    setTimeout(function () { var a = sheet.querySelector('.amount'); if (a && !a.value) a.focus(); }, 50);
  }
  function closeSheet() { sheet.hidden = true; document.body.classList.remove('noscroll'); }
  if (!sheet.hidden) document.body.classList.add('noscroll');
  document.querySelectorAll('[data-sheet]').forEach(function (a) {
    if (editing) return; // 고치는 중이면 주소로 이동 (새 기록 창으로)
    a.addEventListener('click', function (e) { e.preventDefault(); e.stopImmediatePropagation(); openSheet(a.getAttribute('data-sheet')); }, true);
  });
  sheet.addEventListener('click', function (e) {
    if (e.target === sheet) { e.preventDefault(); editing ? location.href = <?= json_encode($back) ?> : closeSheet(); }
  });
  sheet.querySelector('[data-sheet-close]').addEventListener('click', function (e) {
    if (!editing) { e.preventDefault(); e.stopImmediatePropagation(); closeSheet(); }
  }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) (editing ? location.href = <?= json_encode($back) ?> : closeSheet()); });
  var amt = document.querySelector('.amount');
  if (amt) amt.addEventListener('input', function () {
    var n = amt.value.replace(/[^\d]/g, '');
    amt.value = n ? Number(n).toLocaleString('ko-KR') : '';
  });
  var pick = document.getElementById('catpick');
  document.querySelectorAll('#kind-seg input').forEach(function (r) {
    r.addEventListener('change', function () { pick.hidden = r.value === 'in' && r.checked; });
  });
  var recent = <?= json_encode($recentMerchants, JSON_UNESCAPED_UNICODE) ?>;
  function setCat(k) { var el = pick.querySelector('input[value="' + k + '"]'); if (el) el.checked = true; }
  document.querySelectorAll('.recentm .chip').forEach(function (c) {
    c.addEventListener('click', function () { merchant.value = c.getAttribute('data-m'); setCat(c.getAttribute('data-c')); });
  });
  var guess = <?= json_encode(LEDGER_GUESS, JSON_UNESCAPED_UNICODE) ?>;
  var merchant = document.getElementById('merchant');
  if (merchant) merchant.addEventListener('change', function () {
    if (recent[merchant.value]) return setCat(recent[merchant.value]);
    if (pick.querySelector('input:checked')) return;
    var v = merchant.value.replace(/\s/g, '').toLowerCase();
    for (var k in guess) for (var i = 0; i < guess[k].length; i++) {
      if (v.indexOf(guess[k][i].replace(/\s/g, '').toLowerCase()) >= 0) { var el = pick.querySelector('input[value=' + k + ']'); if (el) el.checked = true; return; }
    }
  });
})();
</script>
<?php page_end('ledger');
