<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

$u = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Not found'); }

$q = db()->prepare("
  SELECT f.*, c.client_id
  FROM case_files f
  JOIN cases c ON c.id = f.case_id
  WHERE f.id=?
  LIMIT 1
");
$q->execute([$id]);
$f = $q->fetch();
if (!$f) { http_response_code(404); exit('Not found'); }

if ($u['role']==='client' && (int)$f['client_id'] !== (int)$u['id']) {
  http_response_code(403); exit('403 Forbidden');
}

$base = realpath(__DIR__ . '/../storage/uploads');
$path = realpath(__DIR__ . '/../storage/uploads/' . basename($f['stored_name']));
if (!$base || !$path || strncmp($path, $base, strlen($base)) !== 0 || !is_file($path)) {
  http_response_code(404); exit('File missing');
}

header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($f['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
