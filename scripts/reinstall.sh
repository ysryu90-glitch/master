#!/bin/zsh
# 건강 대시보드 자동 재설치 스크립트 (무료 개발자 계정의 7일 기한 연장용)
#
# 하는 일
#   1. 이 앱의 예전 서명 허가증(프로비저닝 프로파일)을 지워 새 7일짜리를 받게 한다.
#   2. 앱을 빌드한다. (Xcode에 로그인된 Apple ID로 자동 서명)
#   3. 같은 와이파이에 있거나 케이블로 연결된 아이폰 모두에 설치한다. 연결 안 된 폰은 건너뛴다.
#
# 사용법:  ./scripts/reinstall.sh
# 로그:    ~/Library/Logs/HealthDashboardReinstall.log (자동 실행일 때)

set -u
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"

REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO" || exit 1
BUILD_DIR="$REPO/build"
APP_PATH="$BUILD_DIR/Build/Products/Debug-iphoneos/HealthDashboard.app"

log() { print -- "[$(date '+%Y-%m-%d %H:%M:%S')] $*" }

# 번들 ID 접두어 (Configs/Local.xcconfig)
PREFIX=$(sed -n 's/^[[:space:]]*BUNDLE_ID_PREFIX[[:space:]]*=[[:space:]]*//p' Configs/Local.xcconfig 2>/dev/null | tr -d '[:space:]')
if [[ -z "$PREFIX" ]]; then
  log "Configs/Local.xcconfig 에 BUNDLE_ID_PREFIX 가 없어요. README의 '서명 설정 파일 만들기'를 먼저 해 주세요."
  exit 1
fi
APP_ID="$PREFIX.healthdashboard"

# 1. 이 앱용 예전 허가증 지우기 (지우지 않으면 남은 기한이 그대로 재사용됨)
for dir in "$HOME/Library/Developer/Xcode/UserData/Provisioning Profiles" "$HOME/Library/MobileDevice/Provisioning Profiles"; do
  [[ -d "$dir" ]] || continue
  for profile in "$dir"/*.mobileprovision(N); do
    name=$(security cms -D -i "$profile" 2>/dev/null | plutil -extract Name raw -o - - 2>/dev/null)
    if [[ "$name" == *"$APP_ID"* ]]; then
      rm -f "$profile" && log "예전 허가증 삭제: $name"
    fi
  done
done

# 2. 빌드
if command -v xcodegen >/dev/null; then
  xcodegen generate --quiet || { log "xcodegen 실패"; exit 1; }
fi
log "빌드 시작"
if ! xcodebuild -project HealthDashboard.xcodeproj -scheme HealthDashboard -configuration Debug \
     -destination 'generic/platform=iOS' -derivedDataPath "$BUILD_DIR" \
     -allowProvisioningUpdates -allowProvisioningDeviceRegistration \
     build -quiet; then
  log "빌드 실패 — Xcode에서 ⌘B로 오류를 확인해 주세요."
  exit 1
fi

# 3. 연결 가능한 아이폰 찾기 (케이블 또는 같은 와이파이)
JSON=$(mktemp -t devices)
xcrun devicectl list devices --json-output "$JSON" >/dev/null 2>&1
count=$(plutil -extract result.devices raw -o - "$JSON" 2>/dev/null || echo 0)
installed=0
for (( i = 0; i < count; i++ )); do
  get() { plutil -extract "result.devices.$i.$1" raw -o - "$JSON" 2>/dev/null }
  [[ "$(get hardwareProperties.deviceType)" == "iPhone" ]] || continue
  [[ "$(get connectionProperties.pairingState)" == "paired" ]] || continue
  udid=$(get hardwareProperties.udid)
  name=$(get deviceProperties.name)
  if xcrun devicectl device install app --device "$udid" "$APP_PATH" >/dev/null 2>&1; then
    log "설치 완료: $name"
    installed=$((installed + 1))
  else
    log "건너뜀(연결 안 됨 · 잠김): $name"
  fi
done
rm -f "$JSON"

log "끝 — ${installed}대에 설치"
[[ $installed -gt 0 ]]
