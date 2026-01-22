<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['staff', 'admin']);

$state_labels = require __DIR__ . '/../app/config/case_states.php';
$workflowPathA = __DIR__ . '/../app/config/case_workflow.php';
$workflowPathB = __DIR__ . '/../app/config/workflow.php';
$workflow = file_exists($workflowPathA) ? require $workflowPathA : (file_exists($workflowPathB) ? require $workflowPathB : []);

$dept = (int) ($_GET['dept_id'] ?? 0);
$state = trim($_GET['state'] ?? '');
$prio = trim($_GET['priority'] ?? '');
$q = trim($_GET['q'] ?? '');
$overdue = isset($_GET['overdue']) ? 1 : 0;

$depts = db()->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();

$where = [];
$params = [];

if ($dept > 0) {
    $where[] = "c.dept_id=?";
    $params[] = $dept;
}
if ($state !== '' && isset($state_labels[$state])) {
    $where[] = "c.state=?";
    $params[] = $state;
}
if ($prio !== '' && in_array($prio, ['low', 'normal', 'high'], true)) {
    $where[] = "c.priority=?";
    $params[] = $prio;
}
if ($q !== '') {
    $where[] = "(c.ref_no LIKE ? OR c.title LIKE ? OR cu.name LIKE ? OR cu.email LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($overdue) {
    $where[] = "(c.sla_due_at IS NOT NULL AND c.sla_due_at < NOW() AND c.state NOT IN ('completed','rejected'))";
}


$sql = "
  SELECT c.*, ct.name AS case_type_name, d.name AS dept_name,
         cu.name AS client_name, cu.email AS client_email,
         au.name AS assigned_name
  FROM cases c
  JOIN case_types ct ON ct.id = c.case_type_id
  JOIN departments d ON d.id = c.dept_id
  JOIN users cu ON cu.id = c.client_id
  LEFT JOIN users au ON au.id = c.assigned_to
";
if ($where)
    $sql .= " WHERE " . implode(" AND ", $where);

$sql .= " ORDER BY
  (c.state='submitted') DESC,
  (c.priority='high') DESC,
  c.created_at DESC
  LIMIT 300
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll();

$title = 'Staff Queue';
include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Staff Queue</h4>
    <a class="btn btn-outline-secondary" href="/docsys/public/staff_queue.php">Clear</a>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-12 col-md-3">
        <select class="form-select" name="dept_id">
            <option value="">All departments</option>
            <?php foreach ($depts as $d): ?>
                <option value="<?php echo (int) $d['id']; ?>" <?php echo ((int) $d['id'] === $dept) ? 'selected' : ''; ?>>
                    <?php echo e($d['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12 col-md-3">
        <select class="form-select" name="state">
            <option value="">All states</option>
            <?php foreach ($state_labels as $k => $lab): ?>
                <option value="<?php echo e($k); ?>" <?php echo ($k === $state) ? 'selected' : ''; ?>>
                    <?php echo e($lab); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12 col-md-2">
        <select class="form-select" name="priority">
            <option value="">All priorities</option>
            <?php foreach (['low', 'normal', 'high'] as $p): ?>
                <option value="<?php echo e($p); ?>" <?php echo ($p === $prio) ? 'selected' : ''; ?>>
                    <?php echo e(ucfirst($p)); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12 col-md-3">
        <input class="form-control" name="q" placeholder="Search ref/title/client" value="<?php echo e($q); ?>">
    </div>

    <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-warning">Go</button>
    </div>

    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="overdue" name="overdue" value="1" <?php echo $overdue ? 'checked' : ''; ?>>
            <label class="form-check-label" for="overdue">Overdue (SLA passed)</label>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:170px;">Reference</th>
                    <th>Title</th>
                    <th style="width:220px;">State</th>
                    <th style="width:140px;">Department</th>
                    <th style="width:140px;">Assigned</th>
                    <th style="width:190px;">Projected</th>
                    <th style="width:120px;">Confidence</th>
                    <th style="width:170px;">SLA Due</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$cases): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No results.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cases as $c): ?>
                        <?php
                        $lab = $state_labels[$c['state']] ?? $c['state'];

                        $manual_eta = $c['eta_date'] ? date('Y-m-d H:i', strtotime($c['eta_date'])) : null;

                        $proj_raw = $c['projected_due_at'] ?? null;
                        $conf = $c['projected_confidence'] ?? null;
                        $paused = false;
                        $paused_reason = null;

                        if (empty($proj_raw) || empty($conf)) {
                            $f = forecast_case((int) $c['id'], $workflow);
                            $paused = !empty($f['paused']);
                            $paused_reason = $f['paused_reason'] ?? null;
                            $proj_raw = $f['projected_due_at'] ?? null;
                            $conf = $f['confidence'] ?? null;
                        } else {
                            $paused = ($c['state'] === 'waiting_client');
                        }

                        $projected = $paused ? 'Paused' : ($proj_raw ? date('Y-m-d H:i', strtotime($proj_raw)) : '—');

                        $sla_due = $c['sla_due_at'] ? date('Y-m-d H:i', strtotime($c['sla_due_at'])) : '-';
                        $is_sla_overdue = ($c['sla_due_at'] && strtotime($c['sla_due_at']) < time() && !in_array($c['state'], ['completed', 'rejected'], true));
                        ?>

                        <tr>
                            <td class="text-muted small"><?php echo e($c['ref_no']); ?></td>

                            <td>
                                <a class="text-decoration-none fw-semibold"
                                    href="/docsys/public/case.php?id=<?php echo (int) $c['id']; ?>">
                                    <?php echo e($c['title']); ?>
                                </a>
                                <div class="text-muted small"><?php echo e($c['client_name']); ?> ·
                                    <?php echo e($c['client_email']); ?></div>
                            </td>

                            <td>
                                <span
                                    class="badge <?php echo e(badge_class_case_state($c['state'])); ?>"><?php echo e($lab); ?></span>
                                <span
                                    class="badge bg-light text-dark border text-capitalize ms-1"><?php echo e($c['priority']); ?></span>
                            </td>

                            <td><?php echo e($c['dept_name']); ?></td>
                            <td><?php echo e($c['assigned_name'] ?: '—'); ?></td>

                            <td>
                                <div class="fw-semibold"><?php echo e($projected); ?></div>
                                <?php if ($manual_eta): ?>
                                    <div class="text-muted small">Manual: <?php echo e($manual_eta); ?></div>
                                <?php endif; ?>
                                <?php if ($paused && !empty($paused_reason)): ?>
                                    <div class="text-muted small"><?php echo e($paused_reason); ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!$conf): ?>
                                    —
                                <?php else: ?>
                                    <span
                                        class="badge <?php echo ($conf === 'High') ? 'bg-success' : (($conf === 'Medium') ? 'bg-warning text-dark' : 'bg-secondary'); ?>">
                                        <?php echo e($conf); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php echo e($sla_due); ?>
                                <?php if ($is_sla_overdue): ?>
                                    <span class="badge bg-danger ms-1">Overdue</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>