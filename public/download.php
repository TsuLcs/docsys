<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_login();

$u = current_user();
$file_id = (int)($_GET['file_id'] ?? 0);
if ($file_id <= 0) { http_response_code(400); exit('Bad request'); }

$file = db()->prepare("
  SELECT f.*, d.client_id
  FROM document_files f
  JOIN documents d ON d.id = f.document_id
  WHERE f.id = ?
  LIMIT 1
");
$file->execute([$file_id]);
$f = $file->fetch();
if (!$f) { http_response_code(404); exit('Not found'); }

$allowed = false;
if ($u['role'] === 'admin' || $u['role'] === 'staff') $allowed = true;
if ($u['role'] === 'client' && (int)$f['client_id'] === (int)$u['id']) $allowed = true;

if (!$allowed) { http_response_code(403); exit('Forbidden'); }

$path = __DIR__ . '/../storage/uploads/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('File missing'); }

header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . basename($f['original_name']) . '"');
readfile($path);
exit;
