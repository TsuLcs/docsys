<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['staff','admin']);

$wf = workflow();
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';

$where = [];
$args = [];

if ($status !== '' && isset($wf[$status])) {
  $where[] = "d.current_status = ?";
  $args[] = $status;
}

if ($priority !== '' && in_array($priority, ['low','normal','high'], true)) {
  $where[] = "d.priority = ?";
  $args[] = $priority;
}

$sql = "
  SELECT d.*, u.name AS client_name, u.email AS client_email
  FROM documents d
  JOIN users u ON u.id = d.client_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY 
  CASE d.priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
  d.created_at DESC
";

$stmt = db()->prepare($sql);
$stmt->execute($args);
$docs = $stmt->fetchAll();

$title = 'Processing Queue';
include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="mb-0">Processing Queue</h4>
    <div class="text-muted">Filter, open a document, update status, set ETA.</div>
  </div>
</div>

<form class="row g-2 mb-3">
  <div class="col-12 col-md-4">
    <select class="form-select" name="status">
      <option value="">All statuses</option>
      <?php foreach ($wf as $k => $label): ?>
        <option value="<?php echo e($k); ?>" <?php echo ($status===$k?'selected':''); ?>>
          <?php echo e($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-3">
    <select class="form-select" name="priority">
      <option value="">All priorities</option>
      <?php foreach (['high','normal','low'] as $p): ?>
        <option value="<?php echo e($p); ?>" <?php echo ($priority===$p?'selected':''); ?>>
          <?php echo ucfirst($p); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-2">
    <button class="btn btn-primary w-100">Filter</button>
  </div>
  <div class="col-12 col-md-3">
    <a class="btn btn-outline-secondary w-100" href="/docsys/public/admin_queue.php">Reset</a>
  </div>
</form>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <?php if (!$docs): ?>
      <div class="p-4 text-muted">No documents match the filter.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:80px;">#</th>
              <th>Title</th>
              <th style="width:170px;">Client</th>
              <th style="width:140px;">Status</th>
              <th style="width:120px;">Priority</th>
              <th style="width:220px;">ETA</th>
              <th style="width:220px;">Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
              <tr style="cursor:pointer" onclick="location.href='/docsys/public/document_admin.php?id=<?php echo (int)$d['id']; ?>'">
                <td><?php echo (int)$d['id']; ?></td>
                <td>
                  <div class="fw-semibold"><?php echo e($d['title']); ?></div>
                  <div class="text-muted small"><?php echo e($d['client_email']); ?></div>
                </td>
                <td><?php echo e($d['client_name']); ?></td>
                <td><?php echo status_badge($d['current_status']); ?></td>
                <td class="text-capitalize"><?php echo e($d['priority']); ?></td>
                <td><?php echo $d['eta_date'] ? e($d['eta_date']) : '<span class="text-muted">-</span>'; ?></td>
                <td><?php echo e($d['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
