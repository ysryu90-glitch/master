#!/bin/zsh
# 전광판 NAS 설정 파일(board/api/config.php) 만들기
# DB 'board' 계정 비밀번호를 물어보고, 아이폰 앱에 넣을 토큰을 새로 만들어 줍니다.

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$REPO/board/api/config.php"

read -s "password?DB 'board' 계정 비밀번호: "
echo
if [[ -z "$password" ]]; then
  echo "비밀번호가 비어 있어요."
  exit 1
fi
escaped=${password//\\/\\\\}
escaped=${escaped//\'/\\\'}
token=$(openssl rand -hex 16)

cat > "$CONFIG" <<PHP
<?php
return [
    'db_name'     => 'family_board',
    'db_user'     => 'board',
    'db_password' => '$escaped',
    'db_socket'   => '/run/mysqld/mysqld10.sock',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'token'       => '$token',
];
PHP

print -n "$token" | pbcopy 2>/dev/null
echo "만들었어요: $CONFIG"
echo ""
echo "아이폰 앱에 넣을 토큰 (클립보드에도 복사됨):"
echo "  $token"
echo "메모해 두세요. 두 아이폰 모두 같은 토큰을 씁니다."
