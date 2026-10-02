#!/bin/bash
# 우리집 건강 · NAS 정기 작업 (시놀로지 작업 스케줄러에서 5~10분마다 root로 실행)
#
# 1. 배포: GitHub에 새 커밋이 있으면 family/ 를 /volume1/web/family 에 반영 (config.php 는 그대로)
# 2. 알림: 사이트의 cron.php 를 불러 약 · 아침 요약 · 저녁 출석 등 알림을 보냄
# 3. 백업: 하루 한 번 DB(family_board)를 backups/ 에 저장하고 30일치만 보관
# 이 스크립트 자신도 새 버전으로 바뀐다.
#
# 같은 폴더에 token.txt (GitHub 읽기 전용 토큰 한 줄)를 두면 된다. 기록은 deploy.log

set -u

REPO="ysryu90-glitch/master"
BRANCH="claude/gallant-darwin-vgnz1c"
TARGET="${TARGET:-/volume1/web/family}"
API="${API:-https://api.github.com}"
SITE_LOCAL="${SITE_LOCAL:-http://127.0.0.1:8080/family}"
KEEP_BACKUPS=30

DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="$DIR/deploy.log"
STATE="$DIR/deployed-sha.txt"
TOKEN_FILE="$DIR/token.txt"
BACKUP_DIR="$DIR/backups"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

if [ -f "$LOG" ] && [ "$(wc -l < "$LOG")" -gt 1000 ]; then
  tail -n 500 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

# config.php 에서 값 읽기 (예: config_value db_password)
config_value() {
  sed -n "s/^[[:space:]]*'$1'[[:space:]]*=>[[:space:]]*'\(.*\)',[[:space:]]*$/\1/p" "$TARGET/config.php" 2>/dev/null | head -1 | sed "s/\\\\'/'/g; s/\\\\\\\\/\\\\/g"
}

deploy() {
  if [ ! -s "$TOKEN_FILE" ]; then
    log "token.txt 가 없어요. GitHub 토큰을 넣어 주세요."
    return 1
  fi
  local token latest work
  token="$(tr -d ' \r\n' < "$TOKEN_FILE")"
  local auth="Authorization: Bearer $token"
  local branch_url
  branch_url="$(echo "$BRANCH" | sed 's#/#%2F#g')"

  latest="$(curl -fsS --max-time 20 -H "$auth" -H "Accept: application/vnd.github.sha" \
    "$API/repos/$REPO/commits/$branch_url" 2>>"$LOG")"
  if ! echo "$latest" | grep -Eq '^[0-9a-f]{40}$'; then
    log "최신 커밋을 확인하지 못했어요 (토큰 · 인터넷 확인): $latest"
    return 1
  fi
  if [ "${FORCE:-0}" != "1" ] && [ -f "$STATE" ] && [ "$(cat "$STATE")" = "$latest" ]; then
    return 0
  fi

  work="$(mktemp -d)"
  if ! curl -fsSL --max-time 120 -H "$auth" -o "$work/src.tar.gz" "$API/repos/$REPO/tarball/$latest" 2>>"$LOG"; then
    log "내려받기 실패 ($latest)"; rm -rf "$work"; return 1
  fi
  mkdir -p "$work/src"
  if ! tar -xzf "$work/src.tar.gz" -C "$work/src" --strip-components=1 || [ ! -f "$work/src/family/index.php" ]; then
    log "압축 풀기 실패 또는 family/ 폴더 없음"; rm -rf "$work"; return 1
  fi

  mkdir -p "$TARGET"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete --exclude 'config.php' "$work/src/family/" "$TARGET/" 2>>"$LOG" || { log "rsync 실패"; rm -rf "$work"; return 1; }
  else
    cp -a "$work/src/family/." "$TARGET/" || { log "복사 실패"; rm -rf "$work"; return 1; }
  fi
  chmod -R a+rX "$TARGET"

  if [ -f "$work/src/scripts/nas-autodeploy.sh" ] && ! cmp -s "$work/src/scripts/nas-autodeploy.sh" "$0"; then
    cp "$work/src/scripts/nas-autodeploy.sh" "$DIR/nas-autodeploy.sh.new" && mv "$DIR/nas-autodeploy.sh.new" "$0"
    log "정기 작업 스크립트도 새 버전으로 바꿨어요"
  fi

  echo "$latest" > "$STATE"
  rm -rf "$work"
  log "배포 완료: ${latest:0:7}"
}

run_cron() {
  local key out
  key="$(config_value secret)"
  if [ -z "$key" ]; then log "알림 작업 건너뜀: $TARGET/config.php 에서 secret 을 읽지 못했어요"; return 1; fi
  out="$(curl -sS --max-time 90 -w '\nHTTP %{http_code}' "$SITE_LOCAL/cron.php?key=$key" 2>&1)"
  if ! echo "$out" | grep -q '^ok '; then
    log "알림 작업 실패 ($SITE_LOCAL/cron.php): $(echo "$out" | tr '\n' ' ' | cut -c1-300)"
    return 1
  fi
  # 실제로 보낸 알림만 기록
  echo "$out" | grep '→' | while read -r line; do log "알림: $line"; done
}

backup() {
  local today file dump user pass name socket
  today="$(date '+%Y%m%d')"
  file="$BACKUP_DIR/family_board-$today.sql.gz"
  [ -s "$file" ] && return 0
  mkdir -p "$BACKUP_DIR"
  dump="$(command -v mysqldump || true)"
  [ -z "$dump" ] && [ -x /usr/local/mariadb10/bin/mysqldump ] && dump=/usr/local/mariadb10/bin/mysqldump
  if [ -z "$dump" ]; then log "백업 실패: mysqldump 를 찾지 못했어요"; return 1; fi
  user="$(config_value db_user)"; pass="$(config_value db_password)"; name="$(config_value db_name)"; socket="$(config_value db_socket)"
  [ -z "$user" ] && return 0
  if MYSQL_PWD="$pass" "$dump" --single-transaction --default-character-set=utf8mb4 -u "$user" \
      ${socket:+--socket="$socket"} "$name" 2>>"$LOG" | gzip > "$file.tmp" && [ -s "$file.tmp" ]; then
    mv "$file.tmp" "$file"
    chmod 600 "$file"
    log "DB 백업 완료: $(basename "$file") ($(du -h "$file" | cut -f1))"
    ls -1t "$BACKUP_DIR"/family_board-*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
  else
    rm -f "$file.tmp"
    log "DB 백업 실패 (config.php 의 DB 계정 확인)"
  fi
}

date '+%Y-%m-%d %H:%M:%S' > "$DIR/last-run.txt"
deploy
run_cron
backup
exit 0
