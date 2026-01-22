<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';

$workflow = require __DIR__ . '/../app/config/workflow.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Not found'); }

require_login();
$u = current_user();

$doc = db()->prepare("SELECT d.*, u.name AS client_name, u.email AS client_email
                      FROM documents d
                      JOIN users u ON u.id = d.client_id
                      WHERE d.id = ?
                      LIMIT 1");
$doc->execute([$id]);
$d = $doc->fetch();
if (!$d) { http_response_code(404); exit('Not found'); }

// permission: client only their docs; staff/admin can view all
if ($u['role'] === 'client' && (int)$d['client_id'] !== (int)$u['id']) {
  http_response_code(403); exit('403 Forbidden');
}

// files
$fq = db()->prepare("SELECT * FROM document_files WHERE document_id=? ORDER BY uploaded_at ASC");
$fq->execute([$id]);
$files = $fq->fetchAll();

// history
$hq = db()->prepare("
  SELECT h.*, uu.name AS changer_name, uu.role AS changer_role
  FROM status_history h
  JOIN users uu ON uu.id = h.changed_by
  WHERE h.document_id=?
  ORDER BY h.created_at ASC, h.id ASC
");
$hq->execute([$id]);
$history = $hq->fetchAll();

// comments: clients see only non-internal
if ($u['role'] === 'client') {
  $cq = db()->prepare("
    SELECT c.*, uu.name AS author_name, uu.role AS author_role
    FROM comments c
    JOIN users uu ON uu.id = c.author_id
    WHERE c.document_id=? AND c.is_internal=0
    ORDER BY c.created_at ASC, c.id ASC
  ");
} else {
  $cq = db()->prepare("
    SELECT c.*, uu.name AS author_name, uu.role AS author_role
    FROM comments c
    JOIN users uu ON uu.id = c.author_id
    WHERE c.document_id=?
    ORDER BY c.created_at ASC, c.id ASC
  ");
}
$cq->execute([$id]);
$comments = $cq->fetchAll();

// staff list for assignment (staff/admin only)
$staff = [];
if ($u['role'] !== 'client') {
  $sq = db()->query("SELECT id, name, role FROM users WHERE role IN ('staff','admin') ORDER BY role DESC, name ASC");
  $staff = $sq->fetchAll();
}

$status_label = $workflow[$d['current_status']] ?? $d['current_status'];
$eta_display  = $d['eta_date'] ? date('Y-m-d H:i', strtotime($d['eta_date'])) : '-';

$title = 'Document #' . (int)$d['id'];
include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="mb-1">Document #<?php echo (int)$d['id']; ?> — <?php echo e($d['title']); ?></h4>
    <div class="text-muted small">
      Client: <?php echo e($d['client_name']); ?> (<?php echo e($d['client_email']); ?>) · Created <?php echo e(date('Y-m-d H:i', strtotime($d['created_at']))); ?>
    </div>
  </div>
  <div class="text-end">
    <div class="mb-1">
      <span class="badge <?php echo e(status_badge_class($d['current_status'])); ?>"><?php echo e($status_label); ?></span>
      <span class="badge bg-light text-dark border text-capitalize"><?php echo e($d['priority']); ?></span>
    </div>
    <div class="small text-muted">ETA: <?php echo e($eta_display); ?></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">Description</h5>
        <div class="text-muted"><?php echo nl2br(e($d['description'] ?? '—')); ?></div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">Files</h5>
        <?php if (!$files): ?>
          <div class="text-muted">No files uploaded.</div>
        <?php else: ?>
          <ul class="list-group">
            <?php foreach ($files as $f): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                  <div class="fw-semibold"><?php echo e($f['original_name']); ?></div>
                  <div class="text-muted small">
                    <?php echo e($f['mime']); ?> · <?php echo number_format((int)$f['size_bytes']/1024, 1); ?> KB ·
                    <?php echo e(date('Y-m-d H:i', strtotime($f['uploaded_at']))); ?>
                  </div>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="/docsys/public/file.php?id=<?php echo (int)$f['id']; ?>">Download</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Comments</h5>

        <?php if (!$comments): ?>
          <div class="text-muted mb-3">No comments yet.</div>
        <?php else: ?>
          <div class="vstack gap-2 mb-3">
            <?php foreach ($comments as $c): ?>
              <div class="border rounded p-2 bg-white">
                <div class="d-flex justify-content-between">
                  <div class="fw-semibold">
                    <?php echo e($c['author_name']); ?>
                    <span class="badge bg-light text-dark border ms-1"><?php echo e($c['author_role']); ?></span>
                    <?php if ((int)$c['is_internal'] === 1): ?>
                      <span class="badge bg-danger ms-1">Internal</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted small"><?php echo e(date('Y-m-d H:i', strtotime($c['created_at']))); ?></div>
                </div>
                <div class="mt-1"><?php echo nl2br(e($c['body'])); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="/docsys/public/comment_add.php" class="border rounded p-3 bg-light">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">
          <div class="mb-2">
            <label class="form-label">Add a comment</label>
            <textarea class="form-control" name="body" rows="3" required></textarea>
          </div>

          <?php if ($u['role'] !== 'client'): ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" value="1" id="is_internal" name="is_internal">
              <label class="form-check-label" for="is_internal">Internal (staff only)</label>
            </div>
          <?php endif; ?>

          <button class="btn btn-primary">Post</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">Timeline</h5>
        <?php if (!$history): ?>
          <div class="text-muted">No history yet.</div>
        <?php else: ?>
          <ul class="list-group">
            <?php foreach ($history as $h): ?>
              <?php
                $toLabel = $workflow[$h['to_status']] ?? $h['to_status'];
                $fromLabel = $h['from_status'] ? ($workflow[$h['from_status']] ?? $h['from_status']) : '—';
              ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold">
                      <?php echo e($fromLabel); ?> → <?php echo e($toLabel); ?>
                    </div>
                    <div class="text-muted small">
                      by <?php echo e($h['changer_name']); ?> (<?php echo e($h['changer_role']); ?>)
                      <?php if ($h['eta_date']): ?>
                        · ETA set: <?php echo e(date('Y-m-d H:i', strtotime($h['eta_date']))); ?>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($h['note'])): ?>
                      <div class="small mt-1"><?php echo e($h['note']); ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted small"><?php echo e(date('Y-m-d H:i', strtotime($h['created_at']))); ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($u['role'] !== 'client'): ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Staff Controls</h5>

          <form method="post" action="/docsys/public/status_update.php" class="vstack gap-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="document_id" value="<?php echo (int)$d['id']; ?>">

            <div>
              <label class="form-label">Change status</label>
              <select class="form-select" name="to_status" required>
                <?php foreach ($workflow as $k => $label): ?>
                  <option value="<?php echo e($k); ?>" <?php echo ($k === $d['current_status']) ? 'selected' : ''; ?>>
                    <?php echo e($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">ETA (optional)</label>
              <input class="form-control" type="datetime-local" name="eta_local"
                     value="<?php echo $d['eta_date'] ? e(date('Y-m-d\TH:i', strtotime($d['eta_date']))) : ''; ?>">
              <div class="form-text">Leave empty to keep current ETA.</div>
            </div>

            <div>
              <label class="form-label">Assign to (optional)</label>
              <select class="form-select" name="assigned_to">
                <option value="">— Unassigned —</option>
                <?php foreach ($staff as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$d['assigned_to'] === (int)$s['id']) ? 'selected' : ''; ?>>
                    <?php echo e($s['name']); ?> (<?php echo e($s['role']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Note (optional)</label>
              <input class="form-control" name="note" maxlength="500" placeholder="e.g. Waiting for client confirmation">
            </div>

            <button class="btn btn-warning">Apply update</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
