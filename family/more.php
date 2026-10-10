<?php
// 더보기: 자주 안 쓰는 화면과 설정을 한곳에
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$groups = [
    '가족' => [
        ['search.php', '🔍', '찾기', '일기 · 할 일 · 일정 · 장보기를 한 번에'],
        ['notes.php', '📒', '가족 수첩', '사이즈 · 알레르기 · 연락처 · 와이파이'],
        ['board/', '📺', '전광판', '아이패드에 띄우는 가족 화면'],
        ['diary_share.php', '🔗', '일기 공유', '할머니 · 할아버지께 보내는 링크'],
    ],
    '설정' => [
        ['settings.php#profile', '🙂', '내 정보', '이름 · 목표 · 비밀번호'],
        ['settings.php#notify', '🔔', '알림', '이 기기에서 알림 받기 · 알림 종류'],
        ['shortcut.php', '📲', '단축어 연결', '아이폰 건강 기록 자동으로 보내기'],
        ['settings.php#kid', '👧', '아이', '이름 · 하루 목표 칼로리'],
        ['settings.php#home', '🏠', '우리집', '저녁 시간 · 위치'],
        ['settings.php#calendar', '📅', 'iCloud 캘린더', '연결 · 볼 캘린더 고르기'],
        ['settings.php#discover', '🧺', '나들이 데이터', '축제 · 행사 받아오기 키'],
    ],
    '도움' => [
        ['check.php', '🩺', '설치 점검', 'DB · 정기 작업 · 알림 서버 상태'],
        ['logout.php', '🚪', '로그아웃', ''],
    ],
];
page_start('더보기', 'more');
?>
<section class="card me-card">
  <span class="av"><?= h($me['emoji']) ?></span>
  <span class="grow"><b><?= h($me['name']) ?></b><span class="small muted">우리집 · 로그인 중</span></span>
  <a class="btn small" href="settings.php#profile">내 정보</a>
</section>
<?php foreach ($groups as $title => $links): ?>
<h3 class="listhead"><?= h($title) ?></h3>
<div class="card rows">
  <?php foreach ($links as [$href, $icon, $name, $sub]): ?>
    <a class="row" href="<?= h($href) ?>"<?= $href === 'logout.php' ? ' data-no-busy' : '' ?>>
      <span class="ic"><?= $icon ?></span>
      <span class="grow"><span class="t"><?= h($name) ?></span><?php if ($sub): ?><span class="s"><?= h($sub) ?></span><?php endif; ?></span>
      <span class="chev">›</span>
    </a>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<p class="small muted" style="margin:16px 4px 0">모든 기록(건강 · 식단 · 일기와 사진 · 식탁 · 설정)은 NAS의 MariaDB(family_board)에 저장돼요.</p>
<?php page_end('more');
