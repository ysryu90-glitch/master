<?php
// 일기 사진 (?id=사진번호, &t=1 이면 작은 사진)
require __DIR__ . '/lib/bootstrap.php';
require_login();
$id = (int) ($_GET['id'] ?? 0);
$col = !empty($_GET['t']) ? 'thumb' : 'photo';
$etag = '"d' . $id . $col[0] . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
$stmt = db()->prepare("SELECT $col FROM diary_photos WHERE id = ?");
$stmt->execute([$id]);
$bytes = $stmt->fetchColumn();
if (!$bytes) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=2592000');
header('ETag: ' . $etag);
header('Content-Length: ' . strlen($bytes));
echo $bytes;
