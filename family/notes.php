<?php
// 더보기 › 가족 수첩: 옷 · 신발 사이즈, 알레르기, 병원 · 유치원 연락처처럼 자주 찾는 정보 (사람별 + 우리집)
require __DIR__ . '/lib/bootstrap.php';

$me = require_login();
check_csrf();

const NOTE_SUGGEST = [
    'child' => ['옷 사이즈', '신발 사이즈', '알레르기', '혈액형', '다니는 소아과', '유치원 · 반', '선생님 연락처', '예방접종 메모'],
    'adult' => ['옷 사이즈', '신발 사이즈', '혈액형', '알레르기 · 복용 약', '회사 연락처'],
    'home' => ['와이파이', '관리사무소', '가까운 응급실', '약국 (주말)', '택배 비밀번호', '자동차 보험'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = post('member_id') === '' ? null : (int) post('member_id');
    if (post('action') === 'delete') {
        db()->prepare('DELETE FROM notes WHERE id = ?')->execute([(int) post('id')]);
    } else {
        $label = mb_substr(trim(post('label')), 0, 40);
        $value = mb_substr(trim(post('value')), 0, 300);
        if ($label !== '') {
            if ($id = (int) post('id')) db()->prepare('UPDATE notes SET label = ?, value = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$label, $value, $me['id'], $id]);
            else db()->prepare('INSERT INTO notes (member_id, label, value, sort, updated_by, updated_at) VALUES (?, ?, ?, 0, ?, NOW())')->execute([$mid, $label, $value, $me['id']]);
        }
    }
    redirect('notes.php#' . ($mid ? 'm' . $mid : 'home'));
}

$rows = db()->query('SELECT * FROM notes ORDER BY sort, id')->fetchAll();
$by = [];
foreach ($rows as $r) $by[$r['member_id'] === null ? 'home' : (int) $r['member_id']][] = $r;
$groups = [];
foreach (members() as $m) $groups[] = ['key' => (int) $m['id'], 'id' => 'm' . $m['id'], 'title' => $m['emoji'] . ' ' . $m['name'], 'role' => $m['role']];
$groups[] = ['key' => 'home', 'id' => 'home', 'title' => '🏠 우리집', 'role' => 'home'];

page_start('가족 수첩', 'more');
?>
<p class="small muted" style="margin:0 6px 12px">옷 · 신발 사이즈, 알레르기, 연락처처럼 「그거 뭐였지?」 하는 것을 적어 두면 가족 누구나 바로 찾아요. 찾기(🔍)에서도 나와요.</p>
<?php foreach ($groups as $g): $list = $by[$g['key']] ?? []; $have = array_column($list, 'label'); ?>
<section class="card" id="<?= $g['id'] ?>">
  <h2><?= h($g['title']) ?></h2>
  <?php if ($list): ?>
    <div class="nlist">
      <?php foreach ($list as $n): ?>
        <details class="nrow">
          <summary><span class="k"><?= h($n['label']) ?></span><span class="v"><?= $n['value'] !== '' ? h($n['value']) : '<span class="muted">비어 있음</span>' ?></span></summary>
          <form method="post" class="form nedit"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><input type="hidden" name="member_id" value="<?= $g['key'] === 'home' ? '' : $g['key'] ?>">
            <div class="grid2"><input name="label" value="<?= h($n['label']) ?>" maxlength="40" aria-label="이름"><input name="value" value="<?= h($n['value']) ?>" maxlength="300" aria-label="내용"></div>
            <div style="display:flex;gap:8px"><button class="btn small primary">저장</button><button class="btn small danger" name="action" value="delete" formnovalidate onclick="return confirm('지울까요?')">지우기</button></div>
          </form>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" class="form nadd"><?= csrf_field() ?><input type="hidden" name="member_id" value="<?= $g['key'] === 'home' ? '' : $g['key'] ?>">
    <?php $sug = array_values(array_diff(NOTE_SUGGEST[$g['role']] ?? [], $have)); if ($sug): ?>
      <div class="chips scrollx" style="margin:0 0 8px"><?php foreach ($sug as $s): ?><button type="button" class="chip" onclick="var f=this.closest('form');f.label.value=this.textContent;f.value.focus()"><?= h($s) ?></button><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="grid2"><input name="label" maxlength="40" placeholder="무엇 (예: 신발)" required aria-label="이름"><input name="value" maxlength="300" placeholder="내용 (예: 170)" aria-label="내용"></div>
    <button class="btn small">＋ 적기</button>
  </form>
</section>
<?php endforeach; ?>
<?php page_end('more');
