#!/bin/zsh
# 우리집 건강 사이트 설정 파일(family/config.php) 만들기
# DB 'board' 계정 비밀번호를 물어보고, 보안용 임의 문자열을 새로 만듭니다.

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$REPO/family/config.php"

read -s "password?DB 'board' 계정 비밀번호: "
echo
if [[ -z "$password" ]]; then
  echo "비밀번호가 비어 있어요."
  exit 1
fi
escaped=${password//\\/\\\\}
escaped=${escaped//\'/\\\'}
secret=$(openssl rand -hex 24)

cat > "$CONFIG" <<PHP
<?php
return [
    'db_name'     => 'family_board',
    'db_user'     => 'board',
    'db_password' => '$escaped',
    'db_socket'   => '/run/mysqld/mysqld10.sock',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'secret'      => '$secret',
];
PHP

echo "만들었어요: $CONFIG"
echo "이제 family 폴더를 NAS의 web 폴더에 통째로 올려 주세요."
