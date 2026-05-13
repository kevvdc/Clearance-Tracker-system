<?php
// serve_id_image.php  —  Serve uploaded ID images to admin only (bypasses .htaccess deny)
require_once 'config.php';
requireAdmin();

$file = basename($_GET['file'] ?? '');
if (!$file || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $file)) {
    http_response_code(400); exit('Invalid filename.');
}

$path = __DIR__ . '/uploads/id_images/' . $file;
if (!file_exists($path)) {
    http_response_code(404); exit('File not found.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $path);
finfo_close($finfo);

$allowed = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
if (!in_array($mime, $allowed)) {
    http_response_code(403); exit('Not allowed.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
exit;
