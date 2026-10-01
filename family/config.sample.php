<?php
// scripts/make-family-config.sh 가 이 형식으로 config.php 를 만들어 줍니다. (config.php 는 git에 올라가지 않음)
return [
    'db_name'     => 'family_board',
    'db_user'     => 'board',
    'db_password' => '여기에-DB-비밀번호',
    'db_socket'   => '/run/mysqld/mysqld10.sock',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'secret'      => '여기에-긴-임의의-문자열',
];
