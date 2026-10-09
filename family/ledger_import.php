<?php
// 가계부 › 한 번에 가져오기: 뱅크샐러드(모든 카드 · 계좌) 또는 카드사 엑셀 · CSV를 올리면 새 결제만 골라 넣기
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/ledger.php';
require __DIR__ . '/lib/import.php';

$me = require_login();
check_csrf();
$adults = members('adult');
$owners = setting('import_card_owner', []);
if (!is_array($owners)) $owners = [];
$preview = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'preview') {
    try {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? '파일이 너무 커요.' : '파일을 골라 주세요.');
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xls') throw new RuntimeException('예전 엑셀(.xls)은 읽지 못해요. 엑셀이나 넘버스에서 「다른 이름으로 저장 › .xlsx 또는 CSV」로 바꿔 올려 주세요.');
        $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 4);
        $sheets = $head === "PK\x03\x04" ? import_xlsx($f['tmp_name']) : import_csv($f['tmp_name']);
        $parsed = import_parse($sheets);
        if (!$parsed['rows']) throw new RuntimeException('가져올 결제가 없어요.');
        foreach ($parsed['rows'] as &$r) $r['dupe'] = import_is_dupe($r);
        unset($r);
        usort($parsed['rows'], fn($a, $b) => [$b['day'], $b['time']] <=> [$a['day'], $a['time']]);
        $preview = $parsed + ['file' => (string) $f['name']];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'import') {
    $rows = json_decode((string) base64_decode((string) post('payload')), true);
    $pick = array_flip(array_map('intval', (array) ($_POST['pick'] ?? [])));
    $cardOwner = array_map('intval', (array) ($_POST['owner'] ?? []));
    $default = (int) post('default_owner');
    // 카드별 낸 사람 기억
    foreach ($cardOwner as $card => $mid) $owners[(string) $card] = $mid;
    set_setting('import_card_owner', $owners);
    $n = $dup = 0;
    $months = $seen = [];
    foreach (is_array($rows) ? $rows : [] as $i => $r) {
        if (!isset($pick[$i])) continue;
        // 같은 파일에 똑같은 결제가 두 번(같은 분 · 같은 금액) 있어도 둘 다 들어가게 순번을 붙임
        $key = 'imp|' . $r['day'] . '|' . $r['time'] . '|' . $r['amount'] . '|' . $r['merchant'] . '|' . $r['card'];
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        $mid = $r['card'] !== '' && isset($cardOwner[$r['card']]) ? $cardOwner[$r['card']] : $default;
        $id = expense_add(['day' => $r['day'], 'time' => $r['time'] ?: null, 'kind' => $r['kind'], 'amount' => (int) $r['amount'], 'category' => $r['category'],
            'merchant' => $r['merchant'], 'memo' => $r['memo'], 'member_id' => $mid ?: null, 'card' => $r['card'], 'source' => 'import', 'checked' => 1,
            'raw_hash' => sha1($key . ($seen[$key] > 1 ? '|' . $seen[$key] : '')), 'created_by' => (int) $me['id']]);
        if ($id) { $n++; $months[substr($r['day'], 0, 7)] = true; } else $dup++;
    }
    set_setting('import_last', ['at' => date('Y-m-d H:i'), 'n' => $n, 'by' => $me['name']]);
    flash($n ? "{$n}건을 가계부에 넣었어요." . ($dup ? " (이미 있던 {$dup}건은 건너뜀)" : '') : '새로 넣은 결제가 없어요.');
    redirect('ledger.php' . ($months ? '?m=' . max(array_keys($months)) . '&v=list' : ''));
}

$last = setting('import_last');
page_start('한 번에 가져오기', 'ledger');
?>
<?php if ($error): ?>
  <section class="card alert" style="align-items:flex-start"><span class="ai">⚠️</span><span class="grow"><b>파일을 읽지 못했어요</b><span class="small muted" style="display:block"><?= h($error) ?></span></span></section>
<?php endif; ?>

<?php if ($preview): $new = array_filter($preview['rows'], fn($r) => !$r['dupe']); $cards = array_values(array_unique(array_filter(array_column($preview['rows'], 'card')))); ?>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <input type="hidden" name="payload" value="<?= h(base64_encode(json_encode($preview['rows'], JSON_UNESCAPED_UNICODE))) ?>">
    <section class="card">
      <div class="card-head"><h2>📥 <?= h($preview['source']) ?> 미리 보기</h2></div>
      <p class="small muted" style="margin-top:-6px"><?= h($preview['file']) ?> · 모두 <?= count($preview['rows']) ?>건 · <b style="color:var(--accent-ink)">새로 넣을 것 <?= count($new) ?>건</b> · 이미 있는 것 <?= count($preview['rows']) - count($new) ?>건<?= $preview['skipped'] ? ' · 이체 등 뺀 것 ' . $preview['skipped'] . '건' : '' ?></p>
      <?php if ($cards): ?>
        <h3 style="margin-top:12px">카드별 낸 사람 <span class="small muted" style="font-weight:500">(다음부터 기억해요)</span></h3>
        <?php foreach ($cards as $c): ?>
          <div class="row-between" style="align-items:center"><span class="small"><?= h($c) ?></span>
            <select name="owner[<?= h($c) ?>]" style="width:auto;margin:0;padding:6px 10px">
              <?php foreach ($adults as $a): ?><option value="<?= (int) $a['id'] ?>"<?= ($owners[$c] ?? (int) $me['id']) === (int) $a['id'] ? ' selected' : '' ?>><?= h($a['emoji'] . ' ' . $a['name']) ?></option><?php endforeach; ?>
              <option value="0"<?= ($owners[$c] ?? -1) === 0 ? ' selected' : '' ?>>같이 · 모름</option>
            </select></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <input type="hidden" name="default_owner" value="<?= (int) $me['id'] ?>">
    </section>
    <div class="chips impchips" style="margin:0 2px 8px"><button type="button" class="chip" onclick="document.querySelectorAll('.imp input[name=\'pick[]\']').forEach(function(c){c.checked=!c.closest('.imp').classList.contains('dupe')})">새 것만</button><button type="button" class="chip" onclick="document.querySelectorAll('.imp input[name=\'pick[]\']').forEach(function(c){c.checked=false})">모두 끄기</button></div>
    <div class="card lrows">
      <?php foreach ($preview['rows'] as $i => $r): [$cn, $ci] = ledger_cat($r['category']); ?>
        <label class="lrow imp<?= $r['dupe'] ? ' dupe' : '' ?><?= (int) $r['amount'] < 0 ? ' cancel' : '' ?>">
          <input type="checkbox" name="pick[]" value="<?= $i ?>"<?= $r['dupe'] ? '' : ' checked' ?>>
          <span class="ic"><?= $ci ?></span>
          <span class="grow"><span class="t"><?= h($r['merchant'] ?: $cn) ?></span>
            <span class="s"><?= date('n/j', strtotime($r['day'])) ?><?= $r['time'] ? ' ' . h($r['time']) : '' ?> · <?= h($cn) ?><?= $r['card'] ? ' · ' . h(mb_strimwidth($r['card'], 0, 16, '…')) : '' ?><?= $r['dupe'] ? ' · <b>이미 있음</b>' : '' ?></span></span>
          <span class="amt <?= $r['kind'] === 'in' ? 'in' : '' ?>"><?= $r['kind'] === 'in' ? '+' : '' ?><?= won((int) $r['amount']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="sticky-save"><button class="btn primary wide">고른 것 가계부에 넣기</button></div>
  </form>
<?php else: ?>
  <section class="card">
    <h2>📥 카드 여러 개를 한 번에</h2>
    <p class="small">카드마다 알림을 따로 연결할 필요 없이, <b>뱅크샐러드</b>(마이데이터로 모든 카드 · 계좌를 저절로 모아 줌)에서 내보낸 엑셀 파일 하나를 올리면 <b>모든 카드 결제가 한 번에</b> 들어와요.</p>
    <ul class="small" style="padding-left:18px;line-height:1.8;margin:0 0 12px">
      <li>이미 가계부에 있는 결제(문자 · 캡처 · 직접 적은 것)는 <b>빼고 새 것만</b></li>
      <li>뱅크샐러드 분류대로 항목을 나눠 주고, 이체는 빼요</li>
      <li>카드사 홈페이지에서 받은 이용내역 엑셀(xlsx) · CSV도 돼요</li>
    </ul>
    <form method="post" enctype="multipart/form-data" class="form" data-busy="파일을 읽는 중이에요…">
      <?= csrf_field() ?><input type="hidden" name="action" value="preview">
      <label class="photo-add" style="margin:0">📄 파일 고르기 <span id="fname">뱅크샐러드 엑셀(.xlsx) · CSV</span>
        <input type="file" name="file" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required style="display:none" onchange="document.getElementById('fname').textContent=this.files[0]?this.files[0].name:'';this.form.querySelector('button').disabled=!this.files[0]"></label>
      <button class="btn primary wide" style="margin-top:12px" disabled>읽어 보기 (아직 안 넣어요)</button>
    </form>
    <?php if (is_array($last)): ?><p class="small muted" style="margin:10px 0 0">마지막으로 가져온 때: <?= h($last['at']) ?> · <?= (int) $last['n'] ?>건 (<?= h($last['by']) ?>)</p><?php endif; ?>
  </section>

  <section class="card">
    <h2>뱅크샐러드에서 내보내기</h2>
    <ol class="small" style="padding-left:20px;line-height:1.85;margin:0">
      <li>뱅크샐러드 앱에 카드 3개를 연결해 두세요 (처음 한 번, 마이데이터 연결)</li>
      <li>뱅크샐러드 › <b>가계부</b> 화면의 메뉴에서 <b>엑셀 내보내기</b> (앱 버전에 따라 「내보내기 · 엑셀로 받기」 이름일 수 있어요)</li>
      <li>받은 파일(메일 첨부 또는 파일 앱)을 이 화면의 <b>파일 고르기</b>로 올리기</li>
      <li>미리 보기에서 새 결제만 확인하고 <b>넣기</b> — 한 달에 한두 번이면 충분해요</li>
    </ol>
    <p class="small muted" style="margin:8px 0 0">파일은 읽기만 하고 NAS에 따로 남기지 않아요. 기간이 겹쳐도 같은 결제는 두 번 들어가지 않아요.</p>
  </section>
<?php endif; ?>
<?php page_end('ledger');
