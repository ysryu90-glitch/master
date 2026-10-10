<?php
// 칭찬 스티커판 상태

const STICKER_REASONS = ['🪥 양치 잘함', '🥦 골고루 먹음', '🧸 정리 정돈', '😴 혼자 잠', '🙏 인사 잘함', '🤝 양보', '📚 책 읽기', '👗 혼자 옷 입기'];

/** ['count' => 붙인 수, 'goal' => 목표, 'reward' => 선물, 'done' => 지금까지 받은 횟수] */
function sticker_state(int $memberId): array
{
    $g = (setting('sticker_goals', []) ?: [])[$memberId] ?? [];
    $st = db()->prepare('SELECT COUNT(*) FROM stickers WHERE member_id = ? AND used = 0');
    $st->execute([$memberId]);
    return ['count' => (int) $st->fetchColumn(), 'goal' => (int) ($g['goal'] ?? 10), 'reward' => (string) ($g['reward'] ?? ''), 'done' => (int) ($g['done'] ?? 0)];
}
