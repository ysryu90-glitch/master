# 가족 전광판 (시놀로지 NAS + 아이패드)

```
남편 아이폰 ─┐  POST /board/api/ingest.php (토큰)
             ├──────────────▶  NAS Web Station (PHP) ──▶ MariaDB 10 (family_board)
아내 아이폰 ─┘                         │
                                       ▼  GET /board/api/state.php
                              아이패드 사파리: http://NAS주소/board/
```

- 기준 환경: DS220+ · DSM 7.2
- NAS의 PHP가 DB에 **NAS 안에서** 접속하므로 3306 포트를 외부로 열 필요가 없습니다.
- DB에 쌓이는 표
  - `member_state`: 사람별 최신 요약. 전광판이 읽습니다.
  - `daily_health`: 날짜별 준비 점수, 걸음, 수면, 물
  - `dinner_plan`: 날짜별 저녁 메뉴
  - `meal_log`: 식단 기록. 최근 7일은 앱과 똑같이 맞춰집니다.

## 1. 패키지 설치 (패키지 센터)

- **MariaDB 10**: 이미 설치되어 있으면 그대로 사용합니다.
- **Web Station**
- **PHP 8.2** (또는 Web Station이 제안하는 최신 PHP)
- (선택) **phpMyAdmin**: 웹에서 DB를 보고 싶을 때만 설치합니다.

## 2. MariaDB 10 확인

1. 메인 메뉴에서 **MariaDB 10** 앱을 엽니다.
2. **포트 3306**과 **TCP/IP 연결 활성화**가 체크되어 있는지 확인합니다.
3. root 비밀번호가 기억나지 않으면 같은 화면의 **비밀번호 재설정**을 누릅니다.

## 3. DB와 전용 계정 만들기

DBeaver로 **NAS 내부 IP**(예: `192.168.0.10`)에 root로 접속한 뒤 아래를 실행합니다.
비밀번호는 원하는 값으로 바꾸세요.

```sql
CREATE DATABASE family_board CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 전광판 PHP가 쓰는 계정 (NAS 안에서만 접속)
CREATE USER 'board'@'localhost' IDENTIFIED BY 'DB비밀번호';
CREATE USER 'board'@'127.0.0.1' IDENTIFIED BY 'DB비밀번호';
GRANT ALL PRIVILEGES ON family_board.* TO 'board'@'localhost';
GRANT ALL PRIVILEGES ON family_board.* TO 'board'@'127.0.0.1';

-- (선택) 집 안 Mac에서 DBeaver로 조회만 할 계정. 192.168.0.% 는 공유기 대역에 맞게 바꾸세요.
CREATE USER 'viewer'@'192.168.0.%' IDENTIFIED BY '조회용비밀번호';
GRANT SELECT ON family_board.* TO 'viewer'@'192.168.0.%';

FLUSH PRIVILEGES;
```

표는 앱이 처음 데이터를 보낼 때 자동으로 만들어집니다.

## 4. Web Station 설정

1. **Web Station › 스크립트 언어 설정 › PHP** 탭에서 PHP 8.2 프로필을 만들거나 편집합니다.
2. **확장**에서 `pdo_mysql`과 `mysqli`를 체크합니다.
3. **Web Station › 웹 서비스 포털**에서 **기본 서버**를 편집합니다.
4. 백엔드는 Nginx로 두고, 서비스(PHP)를 위에서 만든 PHP 8.2 프로필로 선택합니다.
5. 패키지를 설치하면 `web` 공유 폴더가 생깁니다. 이 폴더가 `http://NAS주소/`입니다.

## 5. 전광판 파일 올리기

1. Mac에서 이 저장소의 `board/api/config.sample.php`를 `config.php`로 복사하고 값을 채웁니다.
   - `db_password`: 3단계의 DB 비밀번호
   - `token`: 아무도 못 맞힐 긴 문자열입니다. 터미널에서 `openssl rand -hex 16`으로 만들 수 있습니다. 아이폰 앱에 똑같이 입력합니다.
2. Finder에서 **⌘K**를 누르고 `smb://NAS주소`에 접속합니다.
3. `web` 폴더를 열고, 저장소의 `board` 폴더를 통째로 복사합니다.
4. 브라우저에서 `http://NAS주소/board/api/state.php`를 열어 `{"members":{}}`가 보이면 성공입니다.
   - `DB 접속 실패: ...`가 보이면 3단계 계정과 비밀번호, 4단계 `pdo_mysql`을 확인하세요.

## 6. 아이폰 앱 연결 (두 사람 모두)

1. 앱에서 **설정 › 가족 전광판 (NAS)**으로 갑니다.
2. 아래 값을 입력합니다.
   - NAS 주소: 예 `192.168.0.10`
   - 토큰: `config.php`의 `token`과 같은 값
   - 이 아이폰은: 남편 폰은 **아빠**, 아내 폰은 **엄마**
3. **지금 보내기**를 누릅니다. 처음에 '로컬 네트워크' 권한을 물어보면 허용합니다.
4. 이후에는 앱이 새로고침될 때 자동으로 보냅니다(최대 10분에 한 번, 바뀌면 바로).

## 7. 아이패드

1. 사파리에서 `http://NAS주소/board/`를 엽니다.
2. **공유 › 홈 화면에 추가**를 누르고, 홈 화면 아이콘으로 실행하면 전체 화면이 됩니다.
3. **설정 › 디스플레이 및 밝기 › 자동 잠금 › 안 함**으로 바꾸고, 충전기를 꽂아 둡니다.
4. (선택) 다른 앱으로 넘어가지 않게 하려면 **설정 › 손쉬운 사용 › 사용법 유도**를 켭니다.

**화면 동작**
- 아침(5~10시)에는 날씨, 저녁(16~21시)에는 저녁 메뉴가 강조됩니다.
- 22:30~06:00에는 시계만 어둡게 보입니다. 화면을 누르면 1분간 전체 화면이 보입니다.
- 화면 번인을 막으려고 5분마다 위치를 살짝 옮깁니다. 새벽 4시에는 페이지를 새로 읽습니다.
- 주소 뒤에 `?nonight=1`을 붙이면 야간 모드를 끕니다.

## DBeaver 'Connect timed out' 해결

`mjys0307.synology.me:3306`으로 접속하면 **공유기 밖의 공인 IP로 돌아서** 들어가려고 합니다. 이 경로가 막히면 응답 없이 시간 초과가 납니다.

1. **집 안에서는 Server Host를 NAS 내부 IP로** 바꿉니다(예: `192.168.0.10`). 대부분 이것으로 해결됩니다.
2. 그래도 시간 초과가 나면 아래를 확인합니다.
   - MariaDB 10의 **TCP/IP 연결 활성화**
   - **제어판 › 보안 › 방화벽**에서 3306 허용(집 안 대역만)
3. 시간 초과 대신 `Host '192.168.0.x' is not allowed to connect`가 나오면 **연결 자체는 된 것**입니다. root는 NAS 안에서만 접속하게 되어 있으니 3단계의 `viewer` 계정을 쓰거나, 같은 방식으로 관리용 계정을 만드세요.
4. **밖에서 DB에 접속하려고 공유기에서 3306을 여는 건 권하지 않습니다.** 인터넷 전체에서 비밀번호 대입 공격이 들어옵니다. 예전에 열어 둔 적이 있다면 공유기 포트포워딩에서 지우세요.
   - 밖에서도 써야 한다면 패키지 센터의 **Tailscale**(무료 VPN)을 권합니다. 설치하면 회사에서도 집 안처럼 NAS 내부 주소로 접속할 수 있고, 아이폰 앱도 밖에서 바로 전광판으로 보낼 수 있습니다.
