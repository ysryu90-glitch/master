<?php
// 일기 (일상 · 나들이) 쓰기 · 고치기 (사진은 휴대폰에서 줄인 뒤 한 장씩 올림)
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/places.php';
require __DIR__ . '/lib/ledger.php';

$me = require_login();
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? ''))) {
        if ($ajax) json_out(['ok' => false, 'error' => '페이지가 오래됐어요. 새로고침한 뒤 다시 해 주세요.']);
        check_csrf();
    }
    $id = (int) post('id');

    if (post('action') === 'photo') {
        $entry = diary_entry($id);
        if (!$entry) json_out(['ok' => false, 'error' => '일기를 찾지 못했어요']);
        if (count($entry['photos']) >= DIARY_PHOTOS_PER_ENTRY) json_out(['ok' => false, 'error' => '사진은 한 일기에 ' . DIARY_PHOTOS_PER_ENTRY . '장까지 넣을 수 있어요']);
        $photo = jpeg_from_data_url((string) post('photo'), DIARY_PHOTO_MAX);
        $thumb = jpeg_from_data_url((string) post('thumb'), DIARY_THUMB_MAX);
        if (!$photo || !$thumb) json_out(['ok' => false, 'error' => '사진 형식이 맞지 않거나 너무 커요']);
        try {
            json_out(['ok' => true, 'id' => diary_add_photo($id, $photo, $thumb, (int) $me['id'])]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '사진 저장 실패: ' . $e->getMessage()]);
        }
    }

    if (post('action') === 'delete') {
        diary_delete($id);
        flash('일기를 지웠어요.');
        redirect('diary.php');
    }

    // 저장
    $day = valid_day(post('day'));
    if ($day > today()) $day = today();
    $category = diary_category(post('category'));
    $placeId = $category === 'outing' ? (string) post('place') : '';
    $placeName = mb_substr(post('place_name'), 0, 100);
    if ($placeId === '_' || $placeId === '') {
        $placeId = null;
    } else {
        $p = place($placeId);
        if ($p) $placeName = $p['name']; else $placeId = null;
    }
    $title = mb_substr(post('title'), 0, 100);
    if ($placeName === '' && $title === '' && post('body') === '') {
        $err = $category === 'outing' ? '장소나 제목 중 하나는 적어 주세요.' : '제목이나 내용 중 하나는 적어 주세요.';
        if ($ajax) json_out(['ok' => false, 'error' => $err]);
        flash($err);
        redirect('diary_edit.php' . ($id ? '?id=' . $id : ''));
    }
    $weather = mb_substr(post('weather'), 0, 60);
    if ($weather === '') $weather = diary_weather_for($day);
    $fields = [$category, $day, $placeId, $placeName, $title, mb_substr(post('body'), 0, 5000), mb_substr(post('kid_said'), 0, 300), $weather, $category === 'outing' && post('again') ? 1 : 0];

    $pdo = db();
    $pdo->beginTransaction();
    if ($id && diary_entry($id)) {
        $pdo->prepare('UPDATE diary_entries SET category = ?, day = ?, place_id = ?, place_name = ?, title = ?, body = ?, kid_said = ?, weather = ?, again = ?, updated_at = NOW() WHERE id = ?')
            ->execute([...$fields, $id]);
    } else {
        $pdo->prepare('INSERT INTO diary_entries (category, day, place_id, place_name, title, body, kid_said, weather, again, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([...$fields, $me['id']]);
        $id = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('DELETE FROM diary_ratings WHERE entry_id = ?')->execute([$id]);
    $valid = array_column(members(), 'id');
    foreach ((array) ($_POST['stars'] ?? []) as $memberId => $stars) {
        $stars = (int) $stars;
        if ($stars >= 1 && $stars <= 5 && in_array((int) $memberId, array_map('intval', $valid), true)) {
            $pdo->prepare('INSERT INTO diary_ratings (entry_id, member_id, stars) VALUES (?, ?, ?)')->execute([$id, (int) $memberId, $stars]);
        }
    }
    foreach ((array) ($_POST['remove_photo'] ?? []) as $photoId) {
        $pdo->prepare('DELETE FROM diary_photos WHERE id = ? AND entry_id = ?')->execute([(int) $photoId, $id]);
    }
    if ($cover = (int) post('cover')) {
        $pdo->prepare('UPDATE diary_photos SET sort = 0 WHERE id = ? AND entry_id = ?')->execute([$cover, $id]);
        $pdo->prepare('UPDATE diary_photos SET sort = GREATEST(sort, 1) WHERE id <> ? AND entry_id = ?')->execute([$cover, $id]);
    }
    // 💰 이날 쓴 돈: 고른 지출만 이 일기에 연결, 새로 적은 줄은 가계부에 추가
    $link = array_map('intval', (array) ($_POST['link_exp'] ?? []));
    $pdo->prepare('UPDATE expenses SET diary_id = NULL WHERE diary_id = ?' . ($link ? ' AND id NOT IN (' . implode(',', $link) . ')' : ''))->execute([$id]);
    if ($link) $pdo->prepare('UPDATE expenses SET diary_id = ? WHERE id IN (' . implode(',', $link) . ') AND (diary_id IS NULL OR diary_id = ?)')->execute([$id, $id]);
    foreach ((array) ($_POST['new_amt'] ?? []) as $i => $amt) {
        $amt = (int) preg_replace('/[^\d]/', '', (string) $amt);
        if ($amt <= 0) continue;
        expense_add(['day' => $day, 'amount' => $amt, 'category' => (string) ($_POST['new_cat'][$i] ?? 'etc'), 'merchant' => mb_substr((string) ($_POST['new_memo'][$i] ?? ''), 0, 100) ?: $placeName,
            'member_id' => (int) $me['id'], 'diary_id' => $id, 'created_by' => (int) $me['id']]);
    }
    $pdo->commit();
    diary_sync_visit($id);

    if ($ajax) json_out(['ok' => true, 'id' => $id]);
    flash('일기를 저장했어요.');
    redirect('diary_view.php?id=' . $id);
}

// 화면
$entry = null;
if (!empty($_GET['id'])) {
    $entry = diary_entry((int) $_GET['id']);
    if (!$entry) redirect('diary.php');
}
$e = $entry ?? [
    'category' => isset($_GET['cat']) ? diary_category($_GET['cat']) : (!empty($_GET['place']) ? 'outing' : 'daily'),
    'id' => 0, 'day' => valid_day($_GET['day'] ?? null), 'place_id' => (string) ($_GET['place'] ?? ''), 'place_name' => '',
    'title' => '', 'body' => '', 'kid_said' => '', 'weather' => '', 'again' => 0, 'ratings' => [], 'photos' => [],
];
if (!$entry && $e['day'] > today()) $e['day'] = today();
if (!$entry) $e['weather'] = diary_weather_for($e['day']);

// 장소 고르기: 최근 계획 → 우리 장소 → 축제 · 새 장소 → 기본 후보
$choices = [];
foreach (db()->query("SELECT DISTINCT place_id FROM outing_logs WHERE kind IN ('plan', 'visit', 'like') AND day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY day DESC") as $r) {
    if ($p = place($r['place_id'])) $choices['최근 계획 · 찜'][$r['place_id']] = $p['name'];
}
foreach (db()->query('SELECT id, name FROM custom_places WHERE active = 1 ORDER BY name') as $c) $choices['⭐ 우리 장소']['c' . $c['id']] = $c['name'];
foreach (db()->query("SELECT id, title FROM outing_events WHERE end_date IS NULL OR end_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) ORDER BY start_date DESC LIMIT 80") as $ev) {
    $choices['🎉 축제 · 행사 · 새 장소'][$ev['id']] = $ev['title'];
}
foreach (PLACES as $p) $choices['추천 장소'][$p['id']] = $p['name'];
$known = $e['place_id'] && place($e['place_id']);
if ($e['place_id'] && !$known) $e['place_id'] = '';
$freeText = !$e['place_id'];
$cat = $e['category'];
$isOuting = $cat === 'outing';
// 종류에 따라 바뀌는 글 [일상, 나들이]
$t = fn(string $daily, string $outing) => ' data-daily="' . h($daily) . '" data-outing="' . h($outing) . '"';
$tt = fn(string $daily, string $outing) => h($isOuting ? $outing : $daily);

$kidFaces = ['😢', '😕', '🙂', '😄', '🤩'];
page_start($entry ? '일기 고치기' : '일기 쓰기', 'family', ['back' => $entry ? 'diary_view.php?id=' . (int) $entry['id'] : 'diary.php']);
?>
<form id="diary-form" method="post" class="form" action="diary_edit.php" data-csrf="<?= csrf_token() ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">

  <div class="segmented" id="cat-seg" style="margin:0 0 12px">
    <?php foreach (DIARY_CATEGORIES as $key => [$label, $icon]): ?>
      <label><input type="radio" name="category" value="<?= $key ?>" <?= $key === $cat ? 'checked' : '' ?>><span><?= $icon ?> <?= $label ?></span></label>
    <?php endforeach; ?>
  </div>

  <section class="card">
    <h2>📸 사진</h2>
    <div class="dphotos" id="photo-grid">
      <?php foreach ($e['photos'] as $i => $pid): ?>
        <div class="dph" data-id="<?= $pid ?>">
          <img src="diary_photo.php?id=<?= $pid ?>&t=1" alt="" loading="lazy">
          <label class="cover" title="대표 사진"><input type="radio" name="cover" value="<?= $pid ?>" <?= $i === 0 ? 'checked' : '' ?>><span>대표</span></label>
          <label class="rm" title="지우기"><input type="checkbox" name="remove_photo[]" value="<?= $pid ?>"><span>✕</span></label>
        </div>
      <?php endforeach; ?>
    </div>
    <label class="photo-add">📷 사진 추가 <span>여러 장 한꺼번에 골라도 돼요</span>
      <input type="file" id="photo-input" accept="image/*" multiple style="display:none">
    </label>
    <p class="small muted" style="margin:8px 0 0">휴대폰에서 알맞은 크기로 줄여서 올려요. 첫 번째 사진이 대표 사진이 돼요.</p>
  </section>

  <section class="card">
    <h2>✍️ 오늘의 이야기</h2>
    <label>일기<textarea name="body" rows="7" placeholder="<?= $tt('오늘 있었던 일, 웃겼던 일, 기억하고 싶은 순간…', '무엇을 했는지, 뭐가 좋았는지, 다음에 갈 때 챙길 것…') ?>"<?= $t('오늘 있었던 일, 웃겼던 일, 기억하고 싶은 순간…', '무엇을 했는지, 뭐가 좋았는지, 다음에 갈 때 챙길 것…') ?>><?= h($e['body']) ?></textarea></label>
    <label>👧 아이가 한 말<input name="kid_said" value="<?= h($e['kid_said']) ?>" maxlength="300" placeholder="<?= $tt('예: "나 이제 혼자 할 수 있어!"', '예: "사슴 또 보러 오자!"') ?>"<?= $t('예: "나 이제 혼자 할 수 있어!"', '예: "사슴 또 보러 오자!"') ?>></label>
  </section>

  <section class="card">
    <h2<?= $t('📅 언제 있었던 일이에요?', '📍 어디 다녀왔어요?') ?>><?= $tt('📅 언제 있었던 일이에요?', '📍 어디 다녀왔어요?') ?></h2>
    <div class="grid2">
      <label>날짜<input type="date" name="day" value="<?= h($e['day']) ?>" max="<?= today() ?>" required></label>
      <label>날씨<input name="weather" value="<?= h($e['weather']) ?>" placeholder="예: ☀️ 맑음 22°/12°"></label>
    </div>
    <label data-only="outing" class="<?= $isOuting ? '' : 'hidden' ?>">장소<select name="place" id="place-select">
      <option value="_" <?= $freeText ? 'selected' : '' ?>>✏️ 직접 적기</option>
      <?php foreach ($choices as $group => $opts): ?>
        <optgroup label="<?= h($group) ?>">
          <?php foreach ($opts as $pid => $name): ?><option value="<?= h($pid) ?>" <?= $pid === $e['place_id'] ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select></label>
    <label id="place-name" class="<?= $freeText || !$isOuting ? '' : 'hidden' ?>"><span<?= $t('장소 (선택)', '장소 이름') ?>><?= $tt('장소 (선택)', '장소 이름') ?></span><input name="place_name" value="<?= h($freeText ? $e['place_name'] : '') ?>" placeholder="<?= $tt('예: 우리 집, 유치원, 동네 놀이터', '예: 동네 놀이터, 할머니 댁 뒷산') ?>"<?= $t('예: 우리 집, 유치원, 동네 놀이터', '예: 동네 놀이터, 할머니 댁 뒷산') ?>></label>
    <label>제목<input name="title" value="<?= h($e['title']) ?>" maxlength="100" placeholder="<?= $tt('예: 처음으로 혼자 양치한 날', '예: 처음 본 꽃사슴!') ?>"<?= $t('예: 처음으로 혼자 양치한 날', '예: 처음 본 꽃사슴!') ?>></label>
  </section>

  <section class="card">
    <h2<?= $t('😊 오늘 하루 별점', '⭐ 가족 별점') ?>><?= $tt('😊 오늘 하루 별점', '⭐ 가족 별점') ?></h2>
    <?php foreach (members() as $m): $isKid = $m['role'] === 'child'; $cur = (int) ($e['ratings'][(int) $m['id']] ?? 0); ?>
      <div class="rate-row">
        <span class="who"><?= h($m['emoji'] . ' ' . $m['name']) ?></span>
        <span class="stars<?= $isKid ? ' faces' : '' ?>" data-stars>
          <?php for ($s = 1; $s <= 5; $s++): ?>
            <label><input type="radio" name="stars[<?= (int) $m['id'] ?>]" value="<?= $s ?>" <?= $cur === $s ? 'checked' : '' ?>><span><?= $isKid ? $kidFaces[$s - 1] : '★' ?></span></label>
          <?php endfor; ?>
          <label class="none"><input type="radio" name="stars[<?= (int) $m['id'] ?>]" value="0" <?= $cur === 0 ? 'checked' : '' ?>><span>안 함</span></label>
        </span>
      </div>
    <?php endforeach; ?>
    <label class="dagain<?= $isOuting ? '' : ' hidden' ?>" data-only="outing"><input type="checkbox" name="again" value="1" <?= $e['again'] ? 'checked' : '' ?>> 💛 또 가고 싶어요 (나들이 추천에 다시 올려요)</label>
  </section>

  <?php
  $dayExp = expenses_of_day($e['day']);
  $catOrder = array_unique(array_merge(LEDGER_OUTING_CATS, array_keys(LEDGER_CATEGORIES)));
  ?>
  <section class="card" id="spend">
    <div class="card-head"><h2>💰 이날 쓴 돈</h2><a class="more" href="ledger.php?m=<?= substr($e['day'], 0, 7) ?>">가계부 ›</a></div>
    <?php if ($dayExp): ?>
      <p class="small muted" style="margin-top:-4px">가계부에 있는 <?= date('n/j', strtotime($e['day'])) ?> 기록이에요. 이 일기에 넣을 것을 골라 주세요.</p>
      <?php foreach ($dayExp as $x): [$cn, $ci] = ledger_cat($x['category']); $other = $x['diary_id'] && (int) $x['diary_id'] !== (int) $e['id'];
          $checked = (int) $x['diary_id'] === (int) $e['id'] && $e['id'] || (!$e['id'] && !$x['diary_id'] && in_array($x['category'], LEDGER_OUTING_CATS, true) && $cat === 'outing'); ?>
        <div class="spentrow">
          <label><input type="checkbox" name="link_exp[]" value="<?= (int) $x['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= $other ? 'disabled' : '' ?>> <?= $ci ?> <?= h($x['merchant'] ?: $x['memo'] ?: $cn) ?><?= $other ? ' <span class="small muted">(다른 일기)</span>' : '' ?></label>
          <b><?= won((int) $x['amount']) ?></b>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="small muted" style="margin-top:-4px">이날 가계부 기록이 없어요. 아래에 적으면 가계부에도 같이 들어가요.</p>
    <?php endif; ?>
    <div id="spend-new">
      <div class="addspend">
        <select name="new_cat[]" aria-label="항목"><?php foreach ($catOrder as $k): [$cn, $ci] = ledger_cat($k); ?><option value="<?= $k ?>"><?= $ci ?> <?= h($cn) ?></option><?php endforeach; ?></select>
        <input name="new_amt[]" inputmode="numeric" placeholder="금액" aria-label="금액">
        <input name="new_memo[]" placeholder="어디서 (예: 입장료, 점심)" aria-label="어디서" style="grid-column:1 / -1">
      </div>
    </div>
    <button type="button" class="btn small" id="spend-more" style="margin-top:8px">＋ 한 줄 더</button>
  </section>

  <p id="diary-status" class="small" style="margin:0 4px 10px"></p>
  <div class="sticky-save"><button class="btn primary wide" id="diary-save">💾 저장</button></div>
</form>

<?php if ($entry): ?>
<form method="post" action="diary_edit.php" onsubmit="return confirm('이 일기와 사진을 모두 지울까요?')" style="margin:16px 4px">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
  <button class="btn small danger">🗑 일기 지우기</button>
</form>
<?php endif; ?>
<script src="assets/diary.js?v=<?= asset_version('assets/diary.js') ?>"></script>
<?php page_end('family');
