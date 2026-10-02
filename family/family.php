<?php
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
page_start('가족', 'family');
$links = [
    ['outing.php', '🧺', '주말 나들이', '날씨 · 연휴 · 일정 · 컨디션을 따져 어디 갈지 추천'],
    ['sick.php', '🤒', '아플 때', '체온 · 해열제 기록과 다음 복용 가능 시각'],
    ['report.php', '📊', '주간 가족 리포트', '함께한 저녁, 딸 새 음식, 수면 · 걸음, 약 챙김'],
    ['settings.php#meds', '💊', '내 약', '복약 시간과 알림'],
    ['settings.php#notify', '🔔', '알림', '이 기기에서 알림 받기 · 알림 종류'],
    ['board/', '📺', '전광판', '아이패드용 가족 전광판'],
    ['settings.php', '⚙︎', '설정', '내 정보 · 단축어 · iCloud 캘린더 · 우리집'],
];
?>
<section class="card">
  <ul class="list">
    <?php foreach ($links as [$href, $icon, $title, $sub]): ?>
      <li><a href="<?= h($href) ?>" style="display:flex;gap:12px;align-items:center;color:inherit;width:100%">
        <span style="font-size:28px;width:36px;text-align:center"><?= $icon ?></span>
        <span class="grow"><span class="title"><?= h($title) ?></span><div class="sub"><?= h($sub) ?></div></span><span class="muted">›</span></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php page_end('family');
