# 우리집 건강 (웹사이트)

시놀로지 NAS(Web Station + PHP 8.2 + MariaDB 10)에서 돌아가는 가족 건강 사이트입니다.
아이폰 앱 없이 사파리로 쓰고, **모든 기록은 MariaDB(family_board)에 저장**됩니다.

| 화면 | 내용 |
|---|---|
| 오늘 | 준비 점수, 오늘 몸 상태, 오늘 저녁·출석, 오늘 일정, 오늘 식단, 날씨 |
| 건강 | 30일 준비 점수·수면·HRV·안정 심박·걸음·활동·체중 그래프, 공식 점수 보정 |
| 식단 | 사람별(아빠·엄마·딸) 끼니 기록. 음식 여러 개, 양(인분), 칼로리·탄단지·나트륨, 사진, 메모 |
| 식탁 | 주간 저녁 메뉴, 누가 함께 먹는지, 저녁 결과, 딸 반응(새 음식 도전), 장보기 |
| 일정 | iCloud 캘린더 보기·추가 (앱 전용 암호) |
| 전광판 | `board/` — 아이패드용 가로 화면 |

건강 데이터는 각자 아이폰의 **단축어**가 `api/health.php`로 보냅니다. 만드는 방법은 사이트의 설정 › 단축어 연결에 있습니다.

## 1. 설치

1. Mac 터미널에서 설정 파일을 만듭니다. DB `board` 계정 비밀번호를 물어봅니다.
   ```
   cd ~/HealthDashboardApp
   git pull
   ./scripts/make-family-config.sh
   ```
2. File Station에서 NAS의 `web` 폴더에 `family` 폴더를 통째로 올립니다. 안에 `config.php`가 있어야 합니다.
3. `http://192.168.68.100:8080/family/check.php`에서 모든 줄이 ✓인지 확인합니다.
   - `curl`, `simplexml`, `mbstring`이 ✗이면 Web Station › 스크립트 언어 설정 › `board-php` › 확장에서 체크합니다.
4. `http://192.168.68.100:8080/family/`를 열면 처음 설정 화면이 나옵니다. 아빠·엄마 비밀번호와 딸 이름을 정합니다.

이전에 올린 `web/board` 폴더(앱용 전광판)는 더 이상 쓰지 않으니 지워도 됩니다.

## 2. 밖에서 도메인으로 접속 (https://mjys0307.synology.me/family/)

1. **인증서**: 제어판 › 외부 액세스 › DDNS에서 `mjys0307.synology.me`를 편집하고, "Let's Encrypt에서 인증서 받기"를 체크합니다.
   - 이 항목이 없으면 제어판 › 보안 › 인증서 › 추가 › Let's Encrypt로 `mjys0307.synology.me` 인증서를 받습니다.
2. **웹 포털**: Web Station › 웹 포털 › 생성 › 웹 서비스 포털로 갑니다.
   - 서비스: `family-board`
   - 포털 유형: **이름 기반**
   - 호스트 이름: `mjys0307.synology.me`
   - 포트: HTTP `80`, HTTPS `443`
3. **인증서 연결**: 제어판 › 보안 › 인증서 › 설정에서 `mjys0307.synology.me` 포털에 1번 인증서를 고릅니다.
4. **공유기 (Deco 앱)**: 고급 › NAT 포워딩 › 포트 포워딩에서 외부 `443` → `192.168.68.100`:`443` (TCP)를 추가합니다.
   - 5000/5001(DSM 관리), 3306(DB), 8080은 열지 않습니다.
5. **확인**: 아이폰 와이파이를 끄고(LTE/5G) `https://mjys0307.synology.me/family/check.php`를 엽니다. '접속 방식'이 HTTPS로 나오면 성공입니다.

로그인은 기기마다 한 번 하면 180일 유지됩니다. 비밀번호를 8번 틀리면 15분 동안 막힙니다.

## 3. 자동 배포 · 알림 · 백업 (NAS 정기 작업)

`scripts/nas-autodeploy.sh` 하나가 작업 스케줄러에서 5~10분마다 돌면서 세 가지를 합니다.

- **배포**: 새 버전이 GitHub에 올라오면 `web/family`에 반영합니다 (`config.php`는 그대로).
- **알림**: `cron.php`를 불러 복약 · 아침 요약 · 저녁 출석 · 기록 끊김 · 주간 리포트 · 해열제 알림을 보냅니다. 8080 포털(`http://127.0.0.1:8080/family`)을 씁니다.
- **백업**: 하루 한 번 DB를 `family-deploy/backups/family_board-날짜.sql.gz`로 저장하고 30일치를 보관합니다.

1. **GitHub 토큰 만들기** (읽기 전용)
   - github.com › 프로필 › Settings › Developer settings › Personal access tokens › **Fine-grained tokens** › Generate new token
   - Repository access: **Only select repositories › ysryu90-glitch/master**
   - Permissions › Repository permissions › **Contents: Read-only**
   - 만든 토큰(github_pat_로 시작)을 복사합니다.
2. **NAS에 폴더 만들기**: File Station에서 `web`이 아닌 공유 폴더(예: `homes/내계정` 또는 `docker`)에 `family-deploy` 폴더를 만듭니다.
3. 그 폴더에 저장소의 `scripts/nas-autodeploy.sh`를 올리고, 토큰 한 줄만 적은 `token.txt`도 올립니다.
4. **작업 스케줄러**: 제어판 › 작업 스케줄러 › 생성 › 예약된 작업 › 사용자 정의 스크립트
   - 일반: 작업 이름 `우리집건강 자동배포`, 사용자 **root**
   - 스케줄: 매일, 첫 실행 00:00, 빈도 **5분마다** (알림 시각이 더 정확해져요), 마지막 실행 23:55
   - 작업 설정 › 실행 명령: `bash /volume1/(폴더 경로)/family-deploy/nas-autodeploy.sh`
5. 만든 작업을 선택하고 **실행**을 한 번 눌러 봅니다. `family-deploy/deploy.log`에 `배포 완료`가 적히면 성공입니다.

## 4. 알림 받기 (각자 아이폰)

1. 사파리에서 사이트를 열고 공유 › **홈 화면에 추가**
2. 홈 화면 아이콘으로 연 뒤 설정 › 🔔 알림 › **이 기기에서 알림 받기** › 허용
3. **테스트 알림**으로 확인 (iOS 16.4 이상, check.php 에서 openssl 확장 ✓ 필요)

## 백업에서 되살리기

phpMyAdmin › family_board › 가져오기에서 `backups/family_board-날짜.sql.gz` 파일을 고르면 그날 상태로 돌아갑니다.

## 나들이 데이터 (축제 · 행사 · 새 장소)

설정 › 🧺 나들이 데이터에 인증키를 넣으면 매일 새벽 5시 이후 한 번 받아와 추천 후보에 섞습니다.

- **한국관광공사 TourAPI** (공공데이터포털): 축제 · 행사(집 35km · 부모님 댁 15km 안), 집 · 부모님 댁 근처에 최근 1년 안에 새로 등록된 관광지 · 문화시설
- **서울 열린데이터광장 문화행사**: 어린이 · 가족 · 누구나 대상, 집 근처 구의 전시 · 공연 · 체험
- **우리 장소**: 나들이 화면 › ⭐ 우리 장소에서 직접 추가

행사 제목 · 대상에 '어린이 · 가족 · 체험 · 동물 · 꽃' 같은 말이 있으면 점수를 더하고, '맥주 · 와인 · 세미나 · 마라톤' 같은 어른 위주 행사는 뺍니다.
