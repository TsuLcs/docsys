<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_csrf();

$u = current_user();

$case_id = (int)($_POST['case_id'] ?? 0);
$body = trim($_POST['body'] ?? '');
$internal = (int)($_POST['internal'] ?? 0);

if ($case_id <= 0 || $body === '') {
  http_response_code(400);
  exit('Bad Request');
}

// load case for permission check
$cq = db()->prepare("SELECT id, client_id FROM cases WHERE id=? LIMIT 1");
$cq->execute([$case_id]);
$c = $cq->fetch();
if (!$c) {
  http_response_code(404);
  exit('Not found');
}

if ($u['role'] === 'client') {
  if ((int)$c['client_id'] !== (int)$u['id']) {
    http_response_code(403);
    exit('403 Forbidden');
  }
  $internal = 0; // clients cannot post internal
} else {
  $internal = $internal ? 1 : 0;
}

// store as audit/event comment (case.php reads these)
add_case_event($case_id, 'comment', (int)$u['id'], [
  'body' => $body,
  'internal' => $internal,
]);

redirect('/docsys/public/case.php?id=' . $case_id);
