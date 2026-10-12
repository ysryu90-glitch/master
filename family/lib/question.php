<?php
// 오늘의 질문: 저녁 식탁에서 나눌 질문 하나 (날마다 바뀜), 가족 각자 답을 남기면 추억으로 쌓임

const QUESTIONS = [
    '오늘 가장 웃겼던 일은 뭐였어요?', '오늘 누구에게 고마웠어요?', '오늘 새로 알게 된 게 있어요?', '오늘 제일 맛있었던 건 뭐예요?',
    '지금 가장 하고 싶은 놀이는?', '내일 꼭 하고 싶은 일 하나는?', '오늘 기분을 날씨로 말하면?', '요즘 제일 좋아하는 노래는?',
    '동물이 될 수 있다면 뭐가 되고 싶어요?', '하늘을 날 수 있다면 어디에 가 보고 싶어요?', '우리 가족을 색깔로 말하면?', '오늘 칭찬해 주고 싶은 사람은?',
    '마법 하나를 쓸 수 있다면?', '어른이 되면 해 보고 싶은 일은?', '가장 기억에 남는 여행은?', '요즘 제일 재밌는 책이나 만화는?',
    '오늘 용감했던 순간이 있었어요?', '우리 집에서 제일 좋아하는 곳은?', '주말에 가족이랑 하고 싶은 건?', '오늘 누구를 도와줬어요?',
    '가장 좋아하는 계절과 그 이유는?', '하루 동안 아빠(엄마)가 된다면 뭘 하고 싶어요?', '제일 좋아하는 간식은?', '오늘 속상했던 일이 있었어요?',
    '세상에서 제일 맛있는 반찬은?', '로봇이 생긴다면 무슨 일을 시키고 싶어요?', '오늘 들은 말 중에 기분 좋았던 말은?', '가장 무서운 건 뭐예요?',
    '가족에게 하고 싶은 말 한마디!', '눈 감으면 떠오르는 행복한 장면은?', '오늘 하루 점수를 매긴다면 몇 점?', '새로 배워 보고 싶은 건?',
    '친구랑 제일 재밌게 놀았던 일은?', '보물을 숨긴다면 어디에 숨길래요?', '공룡이 우리 집에 온다면?', '오늘 제일 많이 웃은 순간은?',
    '케이크를 만든다면 어떤 맛으로?', '생일에 받고 싶은 선물은?', '우리 가족만의 비밀 암호를 만든다면?', '구름 위를 걷는다면 무슨 느낌일까?',
    '오늘 제일 힘들었던 일은?', '내가 제일 잘하는 건?', '아기였을 때 기억나는 거 있어요?', '바다와 산 중에 어디가 좋아요?',
    '투명 인간이 되면 뭘 할래요?', '오늘 고맙다고 말하지 못한 사람이 있어요?', '가장 좋아하는 우리 가족 사진은?', '이번 주말 날씨가 좋으면 어디 갈까?',
    '나만의 가게를 연다면 무엇을 팔까?', '엄마 아빠의 어릴 적이 궁금한 것은?', '오늘 먹은 것 중 다시 먹고 싶은 건?', '내일 아침에 일어나면 제일 먼저 할 일은?',
    '하루가 25시간이라면 1시간을 뭐에 쓸래요?', '좋아하는 숫자와 그 이유는?', '가장 웃긴 표정 지어 보기!', '오늘 내가 착했던 일은?',
    '산타 할아버지에게 편지를 쓴다면?', '우주에 간다면 누구랑 갈래요?', '우리 가족 노래를 만든다면 첫 소절은?', '오늘을 한 단어로 말하면?',
];

function question_of_day(?string $day = null): string
{
    $day = $day ?? today();
    $n = (int) floor(strtotime($day) / 86400);
    return QUESTIONS[$n % count(QUESTIONS)];
}

/** 그날 답 [member_id => row] */
function question_answers(string $day): array
{
    $st = db()->prepare('SELECT * FROM question_answers WHERE day = ?');
    $st->execute([$day]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['member_id']] = $r;
    return $out;
}

function question_save(string $day, int $memberId, string $answer): void
{
    $answer = mb_substr(trim($answer), 0, 300);
    if ($answer === '') {
        db()->prepare('DELETE FROM question_answers WHERE day = ? AND member_id = ?')->execute([$day, $memberId]);
        return;
    }
    db()->prepare('INSERT INTO question_answers (day, member_id, answer, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE answer = VALUES(answer)')->execute([$day, $memberId, $answer]);
}
