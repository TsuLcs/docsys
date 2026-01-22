<?php
require __DIR__ . '/../app/bootstrap.php';
require_role(['staff','admin']);
require_csrf();

$workflow = require __DIR__ . '/../app/config/workflow.php';

$doc_id = (int)($_POST['document_id'] ?? 0);
$to = trim($_POST['to_status'] ?? '');
$note = trim($_POST['note'] ?? '');
$assigned_to_raw = trim($_POST['assigned_to'] ?? '');
$assigned_to = ($assigned_to_raw === '') ? null : (int)$assigned_to_raw;

// eta_local = "YYYY-MM-DDTHH:MM"
$eta_local = trim($_POST['eta_local'] ?? '');
$eta_dt = null;
if ($eta_local !== '') {
  $ts = strtotime(str_replace('T', ' ', $eta_local));
  if ($ts !== false) $eta_dt = date('Y-m-d H:i:s', $ts);
}

if ($doc_id <= 0 || $to === '' || !isset($workflow[$to])) {
  http_response_code(400); exit('Bad Request');
}

$u = current_user();

$docQ = db()->prepare("SELECT * FROM documents WHERE id=? LIMIT 1");
$docQ->execute([$doc_id]);
$d = $docQ->fetch();
if (!$d) { http_response_code(404); exit('Not found'); }

$from = $d['current_status'];

try {
  db()->beginTransaction();

  // update document
  $sql = "UPDATE documents SET current_status=?, updated_at=NOW()";
  $params = [$to];

  if ($eta_dt !== null) {
    $sql .= ", eta_date=?";
    $params[] = $eta_dt;
  }

  // assignment handling
  if ($assigned_to_raw !== '') {
    $sql .= ", assigned_to=?, assigned_at=NOW()";
    $params[] = $assigned_to;
  } elseif ($assigned_to_raw === '') {
    // if left empty explicitly, unassign
    $sql .= ", assigned_to=NULL, assigned_at=NULL";
  }

  $sql .= " WHERE id=?";
  $params[] = $doc_id;

  $up = db()->prepare($sql);
  $up->execute($params);

  // insert history
  $h = db()->prepare("
    INSERT INTO status_history (document_id, changed_by, from_status, to_status, note, eta_date)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $h->execute([
    $doc_id,
    $u['id'],
    $from,
    $to,
    $note !== '' ? $note : null,
    $eta_dt
  ]);

  db()->commit();
  redirect('/docsys/public/document.php?id=' . $doc_id);

} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  http_response_code(500);
  echo "Update failed: " . $e->getMessage();
  exit;
}
