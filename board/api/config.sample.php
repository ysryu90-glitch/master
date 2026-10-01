<?php
// 이 파일을 같은 폴더에 config.php 로 복사한 뒤 값을 채워 주세요.
// (config.php 는 git에 올라가지 않습니다)
return [
    // MariaDB 10 접속 정보 — NAS 안에서 접속하므로 외부 포트를 열 필요가 없습니다.
    'db_name'     => 'family_board',
    'db_user'     => 'board',
    'db_password' => '여기에-DB-비밀번호',
    // DSM 7 MariaDB 10 의 소켓 경로. 접속이 안 되면 TCP(127.0.0.1:3306)로 자동 재시도합니다.
    'db_socket'   => '/run/mysqld/mysqld10.sock',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,

    // 아이폰 앱 설정의 '전광판 토큰'과 똑같이 적어 주세요. (영문·숫자 20자 이상 권장)
    'token'       => '여기에-긴-임의의-문자열',
];
