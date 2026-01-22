<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();

$u = current_user();
$file_id = (int)($_GET['id'] ?? 0);
if ($file_id <= 0) { http_response_code(404); exit('Not found'); }

$stmt = db()->prepare("
  SELECT f.*, d.client_id
  FROM document_files f
  JOIN documents d ON d.id = f.document_id
  WHERE f.id = ?
  LIMIT 1
");
$stmt->execute([$file_id]);
$f = $stmt->fetch();
if (!$f) { http_response_code(404); exit('Not found'); }

if ($u['role'] === 'client' && (int)$f['client_id'] !== (int)$u['id']) {
  http_response_code(403); exit('403 Forbidden');
}

$path = realpath(__DIR__ . '/../storage/uploads/' . basename($f['stored_name']));
$base = realpath(__DIR__ . '/../storage/uploads');

if (!$path || !$base || strncmp($path, $base, strlen($base)) !== 0 || !is_file($path)) {
  http_response_code(404); exit('File missing');
}

header('Content-Description: File Transfer');
header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($f['original_name']) . '"');
header('Content-Length: ' . filesize($path));

readfile($path);
exit;
