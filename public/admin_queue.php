<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['staff','admin']);

$workflow = require __DIR__ . '/../app/config/workflow.php';

$status = trim($_GET['status'] ?? '');
$prio   = trim($_GET['priority'] ?? '');
$q      = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($status !== '' && isset($workflow[$status])) {
  $where[] = "d.current_status = ?";
  $params[] = $status;
}
if ($prio !== '' && in_array($prio, ['low','normal','high'], true)) {
  $where[] = "d.priority = ?";
  $params[] = $prio;
}
if ($q !== '') {
  $where[] = "(d.title LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

$sql = "
  SELECT d.*, u.name AS client_name, u.email AS client_email,
         a.name AS assigned_name, a.role AS assigned_role
  FROM documents d
  JOIN users u ON u.id = d.client_id
  LEFT JOIN users a ON a.id = d.assigned_to
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);

$sql .= " ORDER BY
  (d.current_status='submitted') DESC,
  (d.priority='high') DESC,
  d.created_at DESC
  LIMIT 200
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll();

$title = 'Processing Queue';
include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Processing Queue</h4>
  <a class="btn btn-outline-secondary" href="/docsys/public/admin_queue.php">Clear filters</a>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-12 col-md-3">
    <select class="form-select" name="status">
      <option value="">All statuses</option>
      <?php foreach ($workflow as $k => $label): ?>
        <option value="<?php echo e($k); ?>" <?php echo ($k===$status)?'selected':''; ?>>
          <?php echo e($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12 col-md-2">
    <select class="form-select" name="priority">
      <option value="">All priorities</option>
      <?php foreach (['low','normal','high'] as $p): ?>
        <option value="<?php echo e($p); ?>" <?php echo ($p===$prio)?'selected':''; ?>>
          <?php echo e(ucfirst($p)); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12 col-md-5">
    <input class="form-control" name="q" placeholder="Search title / client name / email" value="<?php echo e($q); ?>">
  </div>

  <div class="col-12 col-md-2 d-grid">
    <button class="btn btn-warning">Filter</button>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:90px;">ID</th>
          <th>Title</th>
          <th style="width:160px;">Status</th>
          <th style="width:120px;">Priority</th>
          <th style="width:220px;">Assigned</th>
          <th style="width:190px;">ETA</th>
          <th style="width:190px;">Created</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$docs): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No results.</td></tr>
        <?php else: ?>
          <?php foreach ($docs as $d): ?>
            <?php
              $label = $workflow[$d['current_status']] ?? $d['current_status'];
              $eta = $d['eta_date'] ? date('Y-m-d H:i', strtotime($d['eta_date'])) : '-';
              $assigned = $d['assigned_name'] ? ($d['assigned_name'].' ('.$d['assigned_role'].')') : '—';
            ?>
            <tr>
              <td class="text-muted">#<?php echo (int)$d['id']; ?></td>
              <td>
                <a class="text-decoration-none fw-semibold" href="/docsys/public/document.php?id=<?php echo (int)$d['id']; ?>">
                  <?php echo e($d['title']); ?>
                </a>
                <div class="text-muted small"><?php echo e($d['client_name']); ?> · <?php echo e($d['client_email']); ?></div>
              </td>
              <td><span class="badge <?php echo e(status_badge_class($d['current_status'])); ?>"><?php echo e($label); ?></span></td>
              <td class="text-capitalize"><?php echo e($d['priority']); ?></td>
              <td><?php echo e($assigned); ?></td>
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
