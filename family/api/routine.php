<?php
// 전광판에서 아이 루틴 누르기 (로그인 + X-CSRF 머리글)
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/sticker.php';

$me = require_login_api();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['ok' => false, 'error' => 'csrf']);
[$on, $complete, $gave] = routine_toggle((int) ($_POST['rid'] ?? 0), (int) $me['id']);
if ($gave) activity('🌟', '루틴을 다 해서 스티커를 받았어요 (전광판)', 'sticker.php');
json_out(['ok' => true, 'checked' => $on, 'complete' => $complete, 'gave' => $gave]);
