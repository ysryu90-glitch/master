<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
$stmt = db()->prepare('SELECT photo FROM meals WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$photo = $stmt->fetchColumn();
if (!$photo) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
echo $photo;
