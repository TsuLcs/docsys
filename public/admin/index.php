<?php
define('DOCSYS', 1);
require __DIR__ . '/../../app/bootstrap.php';

require_login();
require_role(['admin']); // only admins

// ===== BULK DELETE (selected cases) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
  // If you have CSRF helpers, keep these:
  if (function_exists('require_csrf'))
    require_csrf();

  $ids = $_POST['case_ids'] ?? [];
  if (!is_array($ids))
    $ids = [];

  $caseIds = [];
  foreach ($ids as $v) {
    $i = (int) $v;
    if ($i > 0)
      $caseIds[] = $i;
  }
  $caseIds = array_values(array_unique($caseIds));

  if (!$caseIds) {
    // optional flash
    if (function_exists('flash_set'))
      flash_set('error', 'No cases selected.');
    header('Location: /docsys/public/admin/index.php');
    exit;
  }

  $pdo = db();
  $in = implode(',', array_fill(0, count($caseIds), '?'));

  try {
    $pdo->beginTransaction();

    // Delete children first (avoid FK errors)
    $pdo->prepare("DELETE FROM case_files        WHERE case_id IN ($in)")->execute($caseIds);
    $pdo->prepare("DELETE FROM case_field_values WHERE case_id IN ($in)")->execute($caseIds);
    $pdo->prepare("DELETE FROM case_tasks        WHERE case_id IN ($in)")->execute($caseIds);
    $pdo->prepare("DELETE FROM case_state_timers WHERE case_id IN ($in)")->execute($caseIds);
    $pdo->prepare("DELETE FROM case_events       WHERE case_id IN ($in)")->execute($caseIds);

    // Then cases
    $pdo->prepare("DELETE FROM cases WHERE id IN ($in)")->execute($caseIds);

    $pdo->commit();

    if (function_exists('flash_set'))
      flash_set('success', 'Deleted ' . count($caseIds) . ' case(s).');
    header('Location: /docsys/public/admin/index.php');
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction())
      $pdo->rollBack();
    if (function_exists('flash_set'))
      flash_set('error', 'Delete failed: ' . $e->getMessage());
    header('Location: /docsys/public/admin/index.php');
    exit;
  }
}

$admin_title = 'Dashboard';
include __DIR__ . '/_admin_layout_top.php';

// Quick stats (simple, safe)
$stats = [
  'cases_total' => 0,
  'cases_overdue' => 0,
  'case_types' => 0,
  'sla_policies' => 0,
];

try {
  $stats['cases_total'] = (int) db()->query("SELECT COUNT(*) AS c FROM cases")->fetch()['c'];
  $stats['cases_overdue'] = (int) db()->query("SELECT COUNT(*) AS c FROM cases WHERE sla_due_at IS NOT NULL AND sla_due_at < NOW() AND state NOT IN ('completed','rejected')")->fetch()['c'];
  $stats['case_types'] = (int) db()->query("SELECT COUNT(*) AS c FROM case_types")->fetch()['c'];
  $stats['sla_policies'] = (int) db()->query("SELECT COUNT(*) AS c FROM sla_policies WHERE is_active=1")->fetch()['c'];
} catch (Throwable $e) {
  // ignore stats failure for now
}
$by_state = $by_dept = $by_type = $latest_cases = [];

try {
  $by_state = db()->query("
    SELECT state, COUNT(*) AS c
    FROM cases
    GROUP BY state
    ORDER BY c DESC
  ")->fetchAll();

  $by_dept = db()->query("
    SELECT d.name AS dept_name, COUNT(*) AS c
    FROM cases c
    JOIN departments d ON d.id = c.dept_id
    GROUP BY c.dept_id
    ORDER BY c DESC
  ")->fetchAll();

  $by_type = db()->query("
    SELECT ct.name AS type_name, COUNT(*) AS c
    FROM cases c
    JOIN case_types ct ON ct.id = c.case_type_id
    GROUP BY c.case_type_id
    ORDER BY c DESC
  ")->fetchAll();

  $latest_cases = db()->query("
    SELECT c.id, c.ref_no, c.title, c.state, c.priority, c.created_at,
           d.name AS dept_name,
           ct.name AS case_type_name,
           cu.name AS client_name
    FROM cases c
    JOIN departments d ON d.id = c.dept_id
    JOIN case_types ct ON ct.id = c.case_type_id
    JOIN users cu ON cu.id = c.client_id
    ORDER BY c.created_at DESC
    LIMIT 100
  ")->fetchAll();

} catch (Throwable $e) {
  // ignore
}

?>

<div class="col-12">
  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-3">
      <div class="card soft-card">
        <div class="card-body">
          <div class="small-muted">Total Cases</div>
          <div class="fs-3 fw-bold"><?php echo (int) $stats['cases_total']; ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-3">
      <div class="card soft-card">
        <div class="card-body">
          <div class="small-muted">Overdue (SLA)</div>
          <div class="fs-3 fw-bold"><?php echo (int) $stats['cases_overdue']; ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-3">
      <div class="card soft-card">
        <div class="card-body">
          <div class="small-muted">Case Types</div>
          <div class="fs-3 fw-bold"><?php echo (int) $stats['case_types']; ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-3">
      <div class="card soft-card">
        <div class="card-body">
          <div class="small-muted">Active SLA Policies</div>
          <div class="fs-3 fw-bold"><?php echo (int) $stats['sla_policies']; ?></div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card soft-card">
        <div class="card-body">
          <h6 class="mb-2">Quick Navigation</h6>
          <div class="row g-2">
            <div class="col-12 col-lg-6">
              <a class="btn btn-brand w-100" href="/docsys/public/admin/case_types.php">Manage Case Types</a>
            </div>
            <div class="col-12 col-lg-6">
              <a class="btn btn-outline-brand w-100" href="/docsys/public/admin/sla.php">Manage SLA Policies</a>
            </div>
            <div class="col-12 col-lg-6">
              <a class="btn btn-outline-secondary w-100" href="/docsys/public/admin/requirements.php">Manage
                Requirements</a>
            </div>
            <div class="col-12 col-lg-6">
              <a class="btn btn-outline-secondary w-100" href="/docsys/public/admin/fields.php">Manage Fields</a>
            </div>
          </div>
          <div class="small-muted mt-3">
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-12 col-lg-4">
        <div class="card soft-card">
          <div class="card-body">
            <h6 class="mb-2">Cases by State</h6>
            <?php if (!$by_state): ?>
              <div class="text-muted">No data</div>
            <?php else: ?>
              <?php foreach ($by_state as $r): ?>
                <div class="d-flex justify-content-between border-bottom py-1">
                  <div>
                    <?php echo e($r['state']); ?>
                  </div>
                  <div class="fw-semibold">
                    <?php echo (int) $r['c']; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="card soft-card">
          <div class="card-body">
            <h6 class="mb-2">Cases by Department</h6>
            <?php if (!$by_dept): ?>
              <div class="text-muted">No data</div>
            <?php else: ?>
              <?php foreach ($by_dept as $r): ?>
                <div class="d-flex justify-content-between border-bottom py-1">
                  <div>
                    <?php echo e($r['dept_name']); ?>
                  </div>
                  <div class="fw-semibold">
                    <?php echo (int) $r['c']; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="card soft-card">
          <div class="card-body">
            <h6 class="mb-2">Cases by Type</h6>
            <?php if (!$by_type): ?>
              <div class="text-muted">No data</div>
            <?php else: ?>
              <?php foreach ($by_type as $r): ?>
                <div class="d-flex justify-content-between border-bottom py-1">
                  <div>
                    <?php echo e($r['type_name']); ?>
                  </div>
                  <div class="fw-semibold">
                    <?php echo (int) $r['c']; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card soft-card">
          <div class="card-body d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Latest Cases</h6>
            <div class="text-muted small">Select cases → delete in bulk</div>
          </div>

          <form method="post" onsubmit="return confirm('Delete selected cases? This cannot be undone.');">
            <?php if (function_exists('csrf_field'))
              echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_delete">

            <div class="table-responsive">
              <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width:42px;"><input type="checkbox" id="checkAll"></th>
                    <th>Reference</th>
                    <th>Title</th>
                    <th class="d-none d-md-table-cell">State</th>
                    <th class="d-none d-lg-table-cell">Priority</th>
                    <th class="d-none d-lg-table-cell">Department</th>
                    <th class="d-none d-lg-table-cell">Type</th>
                    <th class="d-none d-md-table-cell">Client</th>
                    <th style="width:170px;" class="d-none d-md-table-cell">Created</th>
                    <th style="width:120px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$latest_cases): ?>
                    <tr>
                      <td colspan="10" class="text-center text-muted py-4">No cases found.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($latest_cases as $c): ?>
                      <tr>
                        <td><input class="rowCheck" type="checkbox" name="case_ids[]" value="<?php echo (int) $c['id']; ?>">
                        </td>

                        <td class="text-muted small">
                          <?php echo e($c['ref_no']); ?>
                        </td>

                        <td>
                          <a class="text-decoration-none fw-semibold"
                            href="/docsys/public/case.php?id=<?php echo (int) $c['id']; ?>">
                            <?php echo e($c['title']); ?>
                          </a>

                          <!-- Mobile-only compact info -->
                          <div class="d-md-none small text-muted mt-1">
                            <div><span class="fw-semibold">State:</span> <?php echo e($c['state']); ?></div>
                            <div><span class="fw-semibold">Priority:</span> <?php echo e($c['priority']); ?></div>
                            <div><span class="fw-semibold">Dept:</span> <?php echo e($c['dept_name']); ?></div>
                            <div><span class="fw-semibold">Type:</span> <?php echo e($c['case_type_name']); ?></div>
                            <div><span class="fw-semibold">Client:</span> <?php echo e($c['client_name']); ?></div>
                            <div><span class="fw-semibold">Created:</span>
                              <?php echo e(date('Y-m-d H:i', strtotime($c['created_at']))); ?></div>
                          </div>
                        </td>

                        <td class="d-none d-md-table-cell">
                          <?php echo e($c['state']); ?>
                        </td>

                        <td class="text-capitalize d-none d-lg-table-cell">
                          <?php echo e($c['priority']); ?>
                        </td>

                        <td class="d-none d-lg-table-cell">
                          <?php echo e($c['dept_name']); ?>
                        </td>

                        <td class="d-none d-lg-table-cell">
                          <?php echo e($c['case_type_name']); ?>
                        </td>

                        <td class="d-none d-md-table-cell">
                          <?php echo e($c['client_name']); ?>
                        </td>

                        <td class="text-muted small d-none d-md-table-cell">
                          <?php echo e(date('Y-m-d H:i', strtotime($c['created_at']))); ?>
                        </td>

                        <td class="text-end">
                          <a class="btn btn-sm btn-outline-secondary"
                            href="/docsys/public/case.php?id=<?php echo (int) $c['id']; ?>">Open</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
              <div class="text-muted small">
                Tip: deleting completed cases reduces forecast accuracy (less history). Delete test cases only.
              </div>
              <button id="deleteBtn" class="btn btn-danger" disabled>Delete selected</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      const checkAll = document.getElementById('checkAll');
      const deleteBtn = document.getElementById('deleteBtn');
      const rows = () => Array.from(document.querySelectorAll('.rowCheck'));

      function sync() {
        deleteBtn.disabled = !rows().some(cb => cb.checked);
      }

      checkAll?.addEventListener('change', (e) => {
        rows().forEach(cb => cb.checked = e.target.checked);
        sync();
      });

      document.addEventListener('change', (e) => {
        if (e.target.classList.contains('rowCheck')) sync();
      });

      sync();
    </script>

  </div>
</div>
<?php include __DIR__ . '/_admin_layout_bottom.php'; ?>