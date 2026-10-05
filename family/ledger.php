<?php
// 가계부: 한 달 지출 · 수입, 항목별 합계, 예산, 일기와 연결 (카드 결제 문자 자동 입력은 ledger_guide.php)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/diary.php';
require __DIR__ . '/lib/ledger.php';

$me = require_login();
check_csrf();

$ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$cat = isset(LEDGER_CATEGORIES[$_GET['c'] ?? '']) || ($_GET['c'] ?? '') === 'income' ? $_GET['c'] : '';
$back = 'ledger.php?m=' . $ym . ($cat ? '&c=' . $cat : '');

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
            redirect('ledger.php?m=' . substr($day, 0, 7) . ($cat ? '&c=' . $cat : ''));
        case 'recat':
            if (isset(LEDGER_CATEGORIES[post('category')])) {
                $pdo->prepare('UPDATE expenses SET category = ?, checked = 1, updated_at = NOW() WHERE id = ?')->execute([post('category'), $id]);
            }
            redirect($back . '#review');
        case 'check_all':
            $pdo->exec('UPDATE expenses SET checked = 1 WHERE checked = 0');
            redirect($back);
        case 'delete':
            $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
            flash('지웠어요.');
            redirect($back);
        case 'delete_many':
            $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
            if ($ids) $pdo->exec('DELETE FROM expenses WHERE id IN (' . implode(',', $ids) . ')');
            flash($ids ? count($ids) . '건을 지웠어요.' : '지울 기록을 골라 주세요.');
            redirect($back . '#auto');
        case 'budget':
            set_setting('ledger_budget', max(0, (int) preg_replace('/[^\d]/', '', (string) post('budget'))));
            flash(ledger_budget() ? '한 달 예산을 ' . won(ledger_budget(), true) . '으로 정했어요.' : '예산을 껐어요.');
            redirect($back);
    }
    redirect($back);
}

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM expenses WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$sum = expenses_summary($ym);
$list = expenses_month($ym, $cat ?: null);
$review = db()->query('SELECT * FROM expenses WHERE checked = 0 ORDER BY day DESC, id DESC LIMIT 20')->fetchAll();
// 최근 3일 자동 입력 기록 (잘못 들어간 것을 한꺼번에 지우는 곳)
$autoRecent = db()->query("SELECT * FROM expenses WHERE source <> 'manual' AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY id DESC LIMIT 100")->fetchAll();
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

$f = $edit ?? ['id' => 0, 'kind' => 'out', 'amount' => '', 'category' => $cat && $cat !== 'income' ? $cat : '', 'merchant' => '', 'memo' => '',
    'day' => isset($_GET['day']) ? valid_day($_GET['day']) : today(), 'member_id' => $me['id'], 'diary_id' => (int) ($_GET['diary'] ?? 0) ?: null];

page_start('가계부', 'diary');
?>
<nav class="monthnav">
  <a class="btn small" href="ledger.php?m=<?= $prev ?><?= $cat ? '&c=' . $cat : '' ?>" aria-label="지난달">‹</a>
  <b><?= (int) substr($ym, 0, 4) ?>년 <?= (int) substr($ym, 5) ?>월</b>
  <a class="btn small" href="ledger.php?m=<?= $next ?><?= $cat ? '&c=' . $cat : '' ?>" aria-label="다음 달"<?= $isNow ? ' style="visibility:hidden"' : '' ?>>›</a>
</nav>

<section class="card lsum">
  <div class="k">이번 달 쓴 돈</div>
  <div class="big"><?= won($sum['out']) ?></div>
  <?php if ($sum['in']): ?><div class="small muted">수입 <?= won($sum['in']) ?> · 남은 돈 <b><?= won($sum['in'] - $sum['out']) ?></b></div><?php endif; ?>
  <?php if ($compare !== null && ($compare !== 0)): ?><div class="small" style="margin-top:4px;color:<?= $compare > 0 ? 'var(--orange)' : 'var(--accent)' ?>">지난달 같은 기간보다 <?= won(abs($compare), true) ?> <?= $compare > 0 ? '더 썼어요' : '덜 썼어요' ?></div><?php endif; ?>
  <?php if ($budget): $pct = min(100, $sum['out'] / $budget * 100); $left = $budget - $sum['out'];
      $daysLeft = $isNow ? (int) date('t') - (int) date('j') + 1 : 0; ?>
    <div class="meter <?= $pct >= 100 ? 'red' : ($pct >= 80 ? 'orange' : '') ?>" style="margin-top:12px"><i style="width:<?= $pct ?>%"></i></div>
    <div class="small" style="margin-top:6px;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
      <span>예산 <?= won($budget, true) ?> 중 <?= round($sum['out'] / $budget * 100) ?>%</span>
      <span style="font-weight:700;color:<?= $left < 0 ? 'var(--red)' : 'var(--text)' ?>"><?= $left < 0 ? won(-$left, true) . ' 넘었어요' : '남은 돈 ' . won($left, true) . ($daysLeft ? ' · 하루 ' . won((int) floor($left / $daysLeft), true) : '') ?></span>
    </div>
  <?php endif; ?>
  <?php if ($sum['byCat']): $max = max($sum['byCat']) ?: 1; ?>
    <div class="catbars">
      <?php foreach ($sum['byCat'] as $k => $v): if ($v <= 0) continue; [$cn, $ci] = ledger_cat($k); ?>
        <a class="<?= $cat === $k ? 'on' : '' ?>" href="ledger.php?m=<?= $ym ?><?= $cat === $k ? '' : '&c=' . $k ?>">
          <span class="n"><?= $ci ?> <?= h($cn) ?></span><span class="bar"><i style="width:<?= max(3, $v / $max * 100) ?>%"></i></span><span class="v"><?= won($v, true) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($cat): ?><p class="small" style="margin:8px 0 0"><a href="ledger.php?m=<?= $ym ?>">× 「<?= h(ledger_cat($cat)[0]) ?>」만 보는 중 · 전체 보기</a></p><?php endif; ?>
  <?php endif; ?>
</section>

<section class="card" id="form">
  <h2><?= $edit ? '✏️ 기록 고치기' : '➕ 쓴 돈 적기' ?></h2>
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
    <div class="grid2">
      <label>어디서 · 무엇<input name="merchant" value="<?= h($f['merchant']) ?>" placeholder="예: 이마트, 에버랜드" id="merchant"></label>
      <label>날짜<input type="date" name="day" value="<?= h($f['day']) ?>" max="<?= today() ?>"></label>
    </div>
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
      <a class="btn small" href="<?= h($back) ?>">고치기 취소</a>
      <form method="post" data-confirm="이 기록을 지울까요?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn small danger">🗑 지우기</button></form>
    </div>
  <?php endif; ?>
</section>

<?php if ($review): ?>
<section class="card" id="review">
  <div class="card-head"><h2>📲 자동으로 들어온 기록 확인</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check_all"><button class="btn small">모두 확인</button></form></div>
  <p class="small muted" style="margin-top:-4px">카드 문자로 들어왔는데 항목을 못 정한 기록이에요. 맞는 항목을 눌러 주세요.</p>
  <?php foreach ($review as $x): ?>
    <div class="rv">
      <div><b><?= h($x['merchant'] ?: '결제') ?></b> <span class="small muted"><?= date('n/j', strtotime($x['day'])) ?><?= $x['card'] ? ' · ' . h($x['card']) : '' ?></span> <b style="float:right"><?= won((int) $x['amount']) ?></b></div>
      <form method="post" class="chips" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="recat"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
        <?php foreach (LEDGER_CATEGORIES as $k => [$cn, $ci]): ?><button class="chip" name="category" value="<?= $k ?>"><?= $ci ?> <?= h($cn) ?></button><?php endforeach; ?>
      </form>
      <form method="post" data-confirm="이 기록을 지울까요?" style="margin-top:4px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>"><button class="btn small danger">🗑 결제가 아니에요 (지우기)</button></form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$list): ?>
  <section class="card"><p class="muted" style="margin:0"><?= $cat ? '이 항목의 기록이 없어요.' : '이번 달 기록이 아직 없어요. 위에서 적거나, 카드 결제 문자를 자동으로 받아 보세요.' ?></p></section>
<?php endif; ?>
<?php foreach ($byDay as $day => $rows): $dayOut = array_sum(array_map(fn($x) => $x['kind'] === 'out' ? (int) $x['amount'] : 0, $rows)); ?>
  <h3 class="lday"><span><?= date('n월 j일', strtotime($day)) ?> (<?= weekday_short($day) ?>)<?= $day === today() ? ' · 오늘' : '' ?></span><span><?= $dayOut ? '-' . won($dayOut) : '' ?></span></h3>
  <div class="card lrows">
    <?php foreach ($rows as $x): [$cn, $ci] = ledger_cat($x['category']); $payer = $names[(int) $x['member_id']] ?? null; ?>
      <a class="lrow<?= (int) $x['amount'] < 0 ? ' cancel' : '' ?>" href="ledger.php?m=<?= $ym ?>&edit=<?= (int) $x['id'] ?><?= $cat ? '&c=' . $cat : '' ?>#form">
        <span class="ic"><?= $ci ?></span>
        <span class="grow">
          <span class="t"><?= h($x['merchant'] ?: $x['memo'] ?: $cn) ?></span>
          <span class="s"><?= h($cn) ?><?= $x['at_time'] ? ' · ' . substr($x['at_time'], 0, 5) : '' ?><?= $payer ? ' · ' . h($payer['emoji']) : '' ?><?= $x['source'] !== 'manual' ? ' · 📲' . ($x['card'] ? ' ' . h($x['card']) : '') : '' ?><?= $x['memo'] && $x['merchant'] ? ' · ' . h($x['memo']) : '' ?><?= $x['diary_id'] && isset($diaryTitle[(int) $x['diary_id']]) ? ' · 📔 ' . h($diaryTitle[(int) $x['diary_id']]) : '' ?></span>
        </span>
        <span class="amt <?= $x['kind'] === 'in' ? 'in' : '' ?>"><?= $x['kind'] === 'in' ? '+' : '' ?><?= won((int) $x['amount']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php if ($autoRecent): ?>
<section class="card" id="auto">
  <details class="fold">
    <summary>📲 최근 3일 자동 입력 기록 <?= count($autoRecent) ?>건 · 잘못 들어간 것 지우기</summary>
    <form method="post" data-confirm="고른 기록을 지울까요?">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_many">
      <?php foreach ($autoRecent as $x): [$cn, $ci] = ledger_cat($x['category']); ?>
        <label class="autorow"><input type="checkbox" name="ids[]" value="<?= (int) $x['id'] ?>">
          <span class="grow"><b><?= h($x['merchant'] ?: $cn) ?></b><span class="small muted"> · <?= date('n/j', strtotime($x['day'])) ?><?= $x['at_time'] ? ' ' . substr($x['at_time'], 0, 5) : '' ?> · <?= $ci ?> <?= h($cn) ?> · <?= ['sms' => '문자', 'screen' => '화면 캡처', 'wallet' => '애플페이'][$x['source']] ?? $x['source'] ?></span></span>
          <b><?= won((int) $x['amount']) ?></b></label>
      <?php endforeach; ?>
      <div class="btn-row" style="margin-top:10px">
        <button type="button" class="btn small" onclick="this.form.querySelectorAll('input[type=checkbox]').forEach(function(c){c.checked=!c.checked})">전체 선택 / 해제</button>
        <button class="btn small danger">🗑 고른 기록 지우기</button>
      </div>
    </form>
  </details>
</section>
<?php endif; ?>

<section class="card">
  <h2>⚙︎ 가계부 설정</h2>
  <a class="btn" href="ledger_guide.php">📲 카드 결제 자동으로 받기</a>
  <form method="post" class="form inline" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="budget">
    <label>한 달 예산 (0이면 끔)<input name="budget" inputmode="numeric" value="<?= $budget ? number_format($budget) : '' ?>" placeholder="예: 1,500,000"></label>
    <button class="btn">저장</button>
  </form>
</section>

<script>
(function () {
  // 금액에 쉼표 넣기 · 지출/수입 바꾸기 · 가게 이름으로 항목 추측
  var amt = document.querySelector('.amount');
  if (amt) amt.addEventListener('input', function () {
    var n = amt.value.replace(/[^\d]/g, '');
    amt.value = n ? Number(n).toLocaleString('ko-KR') : '';
  });
  var pick = document.getElementById('catpick');
  document.querySelectorAll('#kind-seg input').forEach(function (r) {
    r.addEventListener('change', function () { pick.hidden = r.value === 'in' && r.checked; });
  });
  var guess = <?= json_encode(LEDGER_GUESS, JSON_UNESCAPED_UNICODE) ?>;
  var merchant = document.getElementById('merchant');
  if (merchant) merchant.addEventListener('change', function () {
    if (pick.querySelector('input:checked')) return;
    var v = merchant.value.replace(/\s/g, '').toLowerCase();
    for (var k in guess) for (var i = 0; i < guess[k].length; i++) {
      if (v.indexOf(guess[k][i].replace(/\s/g, '').toLowerCase()) >= 0) { var el = pick.querySelector('input[value=' + k + ']'); if (el) el.checked = true; return; }
    }
  });
})();
</script>
<?php page_end('diary');
