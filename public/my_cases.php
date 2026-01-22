<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['client']);

$workflow = require __DIR__ . '/../app/config/case_workflow.php';
$u = current_user();
$state_labels = require __DIR__ . '/../app/config/case_states.php';

$created = isset($_GET['created']);

$stmt = db()->prepare("
  SELECT c.*, ct.name AS case_type_name, ct.code AS case_type_code, d.name AS dept_name,
    (SELECT COUNT(*) FROM case_tasks t WHERE t.case_id=c.id AND t.status='open') AS open_tasks
  FROM cases c
  JOIN case_types ct ON ct.id = c.case_type_id
  JOIN departments d ON d.id = c.dept_id
  WHERE c.client_id=?
  ORDER BY c.created_at DESC
  LIMIT 200
");
$stmt->execute([$u['id']]);
$cases = $stmt->fetchAll();

// compute missing required req slots per case (cheap-ish)
$missingMap = [];
if ($cases) {
  $caseIds = array_map(fn($x)=> (int)$x['id'], $cases);
  $in = implode(',', array_fill(0, count($caseIds), '?'));

  // required requirements per case_type
  $reqQ = db()->prepare("
    SELECT c.id AS case_id, r.req_key
    FROM cases c
    JOIN case_type_requirements r ON r.case_type_id = c.case_type_id AND r.is_required=1
    WHERE c.id IN ($in)
  ");
  $reqQ->execute($caseIds);
  $required = [];
  while ($row = $reqQ->fetch()) {
    $required[(int)$row['case_id']][] = $row['req_key'];
  }

  // uploaded req_keys per case
  $upQ = db()->prepare("
    SELECT case_id, req_key
    FROM case_files
    WHERE case_id IN ($in) AND req_key IS NOT NULL
    GROUP BY case_id, req_key
  ");
  $upQ->execute($caseIds);
  $uploaded = [];
  while ($row = $upQ->fetch()) {
    $uploaded[(int)$row['case_id']][] = $row['req_key'];
  }

  foreach ($caseIds as $cid) {
    $reqs = $required[$cid] ?? [];
    $ups = $uploaded[$cid] ?? [];
    $missing = array_values(array_diff($reqs, $ups));
    $missingMap[$cid] = $missing;
  }
}

$title = 'My Cases';
include __DIR__ . '/_layout_top.php';
?>

<?php if ($created): ?>
  <div class="alert alert-success">Case submitted! You can track it below.</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">My Cases</h4>
  <a class="btn btn-primary" href="/docsys/public/cases_submit.php">New Case</a>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:130px;">Reference</th>
          <th>Title</th>
          <th style="width:170px;">State</th>
          <th style="width:180px;">Type</th>
          <th style="width:140px;">Open Tasks</th>
          <th style="width:220px;">Missing Required</th>
          <th style="width:170px;">ETA</th>
          <th style="width:170px;">Projected</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$cases): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No cases yet.</td></tr>
        <?php else: ?>
          <?php foreach ($cases as $c): ?>
            <?php
              $label = $state_labels[$c['state']] ?? $c['state'];
              $eta = $c['eta_date'] ? date('Y-m-d H:i', strtotime($c['eta_date'])) : '-';
              $missing = $missingMap[(int)$c['id']] ?? [];
              $forecast = forecast_case((int)$c['id'], $workflow);
              $proj = (!empty($forecast['projected_due_at'])) ? date('Y-m-d H:i', strtotime($forecast['projected_due_at'])) : '—';
              if (!empty($forecast['paused'])) $proj = 'Paused';
            ?>
            <tr>
              <td class="text-muted small"><?php echo e($c['ref_no']); ?></td>
              <td>
                <a class="text-decoration-none fw-semibold" href="/docsys/public/case.php?id=<?php echo (int)$c['id']; ?>">
                  <?php echo e($c['title']); ?>
                </a>
                <div class="text-muted small"><?php echo e($c['dept_name']); ?> · Created <?php echo e(date('Y-m-d', strtotime($c['created_at']))); ?></div>
              </td>
              <td>
                <span class="badge <?php echo e(badge_class_case_state($c['state'])); ?>"><?php echo e($label); ?></span>
                <span class="badge bg-light text-dark border text-capitalize ms-1"><?php echo e($c['priority']); ?></span>
              </td>
              <td class="small"><?php echo e($c['case_type_name']); ?><div class="text-muted"><?php echo e($c['case_type_code']); ?></div></td>
              <td>
                <?php if ((int)$c['open_tasks'] > 0): ?>
                  <span class="badge bg-warning text-dark"><?php echo (int)$c['open_tasks']; ?></span>
                <?php else: ?>
                  <span class="text-muted">0</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if (!$missing): ?>
                  <span class="badge bg-success">Complete</span>
                <?php else: ?>
                  <span class="badge bg-danger"><?php echo count($missing); ?> missing</span>
                <?php endif; ?>
              </td>
              <td><?php echo e($eta); ?></td>
              <td class="small">
                <span class="fw-semibold"><?php echo e($proj); ?></span>
                <div class="text-muted"><?php echo e($forecast['confidence'] ?? ''); ?></div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
