#!/bin/bash
# 우리집 건강 사이트 자동 배포 (시놀로지 NAS 작업 스케줄러에서 10분마다 실행)
#
# GitHub에 새 커밋이 있으면 내려받아 family/ 폴더를 /volume1/web/family 에 맞춰 넣는다.
# - family/config.php (DB 비밀번호)는 건드리지 않는다.
# - 이 스크립트 자신도 새 버전으로 바꾼다.
#
# 같은 폴더에 token.txt (GitHub 읽기 전용 토큰 한 줄)를 두면 된다.
# 기록: 같은 폴더의 deploy.log

set -u

REPO="ysryu90-glitch/master"
BRANCH="claude/gallant-darwin-vgnz1c"
TARGET="${TARGET:-/volume1/web/family}"
API="${API:-https://api.github.com}"

DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="$DIR/deploy.log"
STATE="$DIR/deployed-sha.txt"
TOKEN_FILE="$DIR/token.txt"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

# 기록 파일이 너무 커지지 않게 (마지막 500줄만)
if [ -f "$LOG" ] && [ "$(wc -l < "$LOG")" -gt 1000 ]; then
  tail -n 500 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

if [ ! -s "$TOKEN_FILE" ]; then
  log "token.txt 가 없어요. GitHub 토큰을 넣어 주세요."
  exit 1
fi
TOKEN="$(tr -d ' \r\n' < "$TOKEN_FILE")"
AUTH="Authorization: Bearer $TOKEN"
BRANCH_URL="$(echo "$BRANCH" | sed 's#/#%2F#g')"

# 1. 최신 커밋 확인 (바뀐 게 없으면 끝)
LATEST="$(curl -fsS --max-time 20 -H "$AUTH" -H "Accept: application/vnd.github.sha" \
  "$API/repos/$REPO/commits/$BRANCH_URL" 2>>"$LOG")"
if ! echo "$LATEST" | grep -Eq '^[0-9a-f]{40}$'; then
  log "최신 커밋을 확인하지 못했어요 (토큰 · 인터넷 확인): $LATEST"
  exit 1
fi
if [ "${FORCE:-0}" != "1" ] && [ -f "$STATE" ] && [ "$(cat "$STATE")" = "$LATEST" ]; then
  exit 0
fi

# 2. 내려받아 풀기
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
if ! curl -fsSL --max-time 120 -H "$AUTH" -o "$WORK/src.tar.gz" "$API/repos/$REPO/tarball/$LATEST" 2>>"$LOG"; then
  log "내려받기 실패 ($LATEST)"
  exit 1
fi
mkdir -p "$WORK/src"
if ! tar -xzf "$WORK/src.tar.gz" -C "$WORK/src" --strip-components=1; then
  log "압축 풀기 실패"
  exit 1
fi
if [ ! -f "$WORK/src/family/index.php" ]; then
  log "받은 파일에 family/ 폴더가 없어요"
  exit 1
fi

# 3. 사이트에 반영 (config.php 는 지키고, 저장소에서 지운 파일은 사이트에서도 지움)
mkdir -p "$TARGET"
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete --exclude 'config.php' "$WORK/src/family/" "$TARGET/" 2>>"$LOG" || { log "rsync 실패"; exit 1; }
else
  cp -a "$WORK/src/family/." "$TARGET/" || { log "복사 실패"; exit 1; }
fi
# 웹 서버(http)가 읽을 수 있게
chmod -R a+rX "$TARGET"

# 4. 이 스크립트도 새 버전으로
if [ -f "$WORK/src/scripts/nas-autodeploy.sh" ] && ! cmp -s "$WORK/src/scripts/nas-autodeploy.sh" "$0"; then
  cp "$WORK/src/scripts/nas-autodeploy.sh" "$DIR/nas-autodeploy.sh.new" && mv "$DIR/nas-autodeploy.sh.new" "$0"
  log "배포 스크립트도 새 버전으로 바꿨어요"
fi

echo "$LATEST" > "$STATE"
log "배포 완료: ${LATEST:0:7}"
