<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['client']);

$u = current_user();
$workflow = require __DIR__ . '/../app/config/workflow.php';

$created = isset($_GET['created']);

$stmt = db()->prepare("
  SELECT d.*
  FROM documents d
  WHERE d.client_id = ?
  ORDER BY d.created_at DESC
  LIMIT 100
");
$stmt->execute([$u['id']]);
$docs = $stmt->fetchAll();

$title = 'My Documents';
include __DIR__ . '/_layout_top.php';
?>

<?php if ($created): ?>
  <div class="alert alert-success">Submitted! Your document is now being processed.</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">My Documents</h4>
  <a class="btn btn-primary" href="/docsys/public/submit.php">New submission</a>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width: 90px;">ID</th>
          <th>Title</th>
          <th style="width: 160px;">Status</th>
          <th style="width: 140px;">Priority</th>
          <th style="width: 190px;">ETA</th>
          <th style="width: 190px;">Created</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$docs): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No documents yet.</td></tr>
        <?php else: ?>
          <?php foreach ($docs as $d): ?>
            <?php
              $label = $workflow[$d['current_status']] ?? $d['current_status'];
              $eta   = $d['eta_date'] ? date('Y-m-d H:i', strtotime($d['eta_date'])) : '-';
            ?>
            <tr>
              <td class="text-muted">#<?php echo (int)$d['id']; ?></td>
              <td>
                <a class="text-decoration-none fw-semibold" href="/docsys/public/document.php?id=<?php echo (int)$d['id']; ?>">
                  <?php echo e($d['title']); ?>
                </a>
                <?php if (!empty($d['description'])): ?>
                  <div class="text-muted small"><?php echo e(mb_strimwidth($d['description'], 0, 120, '…', 'UTF-8')); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?php echo e(status_badge_class($d['current_status'])); ?>">
                  <?php echo e($label); ?>
                </span>
              </td>
              <td class="text-capitalize"><?php echo e($d['priority']); ?></td>
              <td><?php echo e($eta); ?></td>
              <td><?php echo e(date('Y-m-d H:i', strtotime($d['created_at']))); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
