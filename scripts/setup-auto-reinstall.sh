#!/bin/zsh
# 매일 자동 재설치 켜기/끄기
#   켜기:  ./scripts/setup-auto-reinstall.sh         (기본 매일 23:30)
#          ./scripts/setup-auto-reinstall.sh 6 10    (매일 06:10)
#   끄기:  ./scripts/setup-auto-reinstall.sh off
#
# Mac이 그 시각에 잠자기 중이었다면 깨어난 직후 한 번 실행돼요.

LABEL="com.healthdashboard.reinstall"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null
if [[ "${1:-}" == "off" ]]; then
  rm -f "$PLIST"
  echo "자동 재설치를 껐어요."
  exit 0
fi

HOUR=${1:-23}
MINUTE=${2:-30}
chmod +x "$REPO/scripts/reinstall.sh"
mkdir -p "$HOME/Library/LaunchAgents"
cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$LABEL</string>
  <key>ProgramArguments</key>
  <array><string>$REPO/scripts/reinstall.sh</string></array>
  <key>StartCalendarInterval</key>
  <dict><key>Hour</key><integer>$HOUR</integer><key>Minute</key><integer>$MINUTE</integer></dict>
  <key>StandardOutPath</key><string>$HOME/Library/Logs/HealthDashboardReinstall.log</string>
  <key>StandardErrorPath</key><string>$HOME/Library/Logs/HealthDashboardReinstall.log</string>
</dict>
</plist>
PLIST
launchctl bootstrap "gui/$(id -u)" "$PLIST"
printf "매일 %02d:%02d에 자동으로 다시 설치해요.\n로그: ~/Library/Logs/HealthDashboardReinstall.log\n" "$HOUR" "$MINUTE"
