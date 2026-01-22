<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_login();

$u = current_user();

// ✅ Your other pages use case_workflow.php, so dashboard should too (fallback included)
$workflowPathA = __DIR__ . '/../app/config/case_workflow.php';
$workflowPathB = __DIR__ . '/../app/config/workflow.php';
$workflow = file_exists($workflowPathA) ? require $workflowPathA : (file_exists($workflowPathB) ? require $workflowPathB : []);

$title = 'Dashboard';
include __DIR__ . '/_layout_top.php';

/**
 * ===== DB helper (tries common patterns) =====
 */
function _docsys_pdo()
{
  if (function_exists('db'))
    return db();            // common helper used in your codebase
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO)
    return $GLOBALS['pdo'];
  if (isset($GLOBALS['PDO']) && $GLOBALS['PDO'] instanceof PDO)
    return $GLOBALS['PDO'];
  return null;
}

/**
 * ===== Safe array getter =====
 */
function _g($arr, $key, $default = null)
{
  if (!is_array($arr))
    return $default;
  return array_key_exists($key, $arr) ? $arr[$key] : $default;
}

/**
 * ===== Workflow helpers =====
 */
function _workflow_keys($workflow)
{
  if (!is_array($workflow))
    return [];
  $keys = [];
  foreach ($workflow as $k => $v) {
    if (is_string($k))
      $keys[] = $k;
    else if (is_array($v) && isset($v['key']))
      $keys[] = (string) $v['key'];
  }
  return $keys;
}

function _stage_index($workflowKeys, $stageKey)
{
  $i = array_search((string) $stageKey, $workflowKeys, true);
  return ($i === false) ? 0 : (int) $i;
}

function _progress_percent($workflowKeys, $stageKey)
{
  $count = max(1, count($workflowKeys));
  $idx = _stage_index($workflowKeys, $stageKey);
  $maxIdx = max(1, $count - 1);
  $pct = (int) round(($idx / $maxIdx) * 100);
  return max(0, min(100, $pct));
}

/**
 * ===== Date formatting =====
 */
function _fmt_dt($dt)
{
  if (!$dt)
    return '-';
  $ts = is_numeric($dt) ? (int) $dt : strtotime((string) $dt);
  if (!$ts)
    return '-';
  return date('Y-m-d H:i', $ts);
}

/**
 * ===== BADGE helper =====
 */
function _badge($text, $cls)
{
  return '<span class="badge ' . $cls . '">' . e($text) . '</span>';
}

/**
 * ===== Confidence formatter =====
 */
function _fmt_conf($v)
{
  if ($v === null || $v === '')
    return '-';
  $s = trim((string) $v);
  if (is_numeric($s))
    return ((int) $s) . '%';
  return $s;
}

$pdo = _docsys_pdo();
$workflowKeys = _workflow_keys($workflow);

$cases = [];
$action_required = [];
$staff_counts = [];

// -------------------------------------------
// CLIENT DASHBOARD: fetch "My Active Cases"
// -------------------------------------------
if (($u['role'] ?? '') === 'client') {
  if ($pdo) {

    // ✅ REAL SCHEMA: cases.state (not status)
    $sql = "
      SELECT *
      FROM cases
      WHERE client_id = :uid
        AND state <> 'completed'
      ORDER BY
        (CASE WHEN state = 'waiting_client' THEN 0 ELSE 1 END),
        updated_at DESC
    ";

    try {
      $st = $pdo->prepare($sql);
      $st->execute([':uid' => (int) $u['id']]);
      $cases = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      $cases = [];
      echo '<div class="alert alert-warning mb-3">DB query failed in dashboard.php. Check: cases.client_id / cases.state / cases.updated_at</div>';
    }

    // Open request tasks (optional)
    $openReqCountMap = [];
    $openReqKeysMap = [];

    if (!empty($cases)) {
      $caseIds = array_map(fn($c) => (int) _g($c, 'id', 0), $cases);
      $caseIds = array_values(array_filter($caseIds));

      if (!empty($caseIds)) {
        $in = implode(',', array_fill(0, count($caseIds), '?'));

        $reqSql = "
          SELECT
            case_id,
            COUNT(*) AS open_count,
            GROUP_CONCAT(DISTINCT NULLIF(req_key,'') ORDER BY req_key SEPARATOR ', ') AS req_keys
          FROM case_tasks
          WHERE case_id IN ($in)
            AND task_type IN ('request_file','request_info')
            AND status = 'open'
          GROUP BY case_id
        ";

        try {
          $rst = $pdo->prepare($reqSql);
          $rst->execute($caseIds);
          foreach ($rst->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int) $r['case_id'];
            $openReqCountMap[$cid] = (int) $r['open_count'];
            $openReqKeysMap[$cid] = (string) ($r['req_keys'] ?? '');
          }
        } catch (Throwable $e) {
          $openReqCountMap = [];
          $openReqKeysMap = [];
        }
      }
    }

    // Build UI values
    $now = time();
    foreach ($cases as &$c) {
      $cid = (int) _g($c, 'id', 0);

      $status = (string) (_g($c, 'state', 'submitted'));   // ✅ correct
      $stage = $status;                                // no separate stage column in your schema

      $forecast = forecast_case($cid, $workflow);

      $manualEta = _g($c, 'eta_date', null);
      $projEta = $forecast['projected_due_at'] ?? null;

      $paused = !empty($forecast['paused']);
      $pauseReason = $forecast['paused_reason'] ?? null;

      // Manual ETA (committed) always wins
      $eta = $manualEta ?: $projEta;

      // If forecast is paused and no manual ETA, don't show a date
      if ($paused && !$manualEta) {
        $eta = null;
      }

      // Confidence comes from forecast (High/Medium/Low)
      $conf = $forecast['confidence'] ?? ($forecast['confidence_pct'] ?? null);

      // SLA due from DB
      $slaDue = _g($c, 'sla_due_at', null);

      // Friendly label for UI
      $etaLabel = ($paused && !$manualEta) ? 'Paused' : _fmt_dt($eta);

      $openReq = (int) ($openReqCountMap[$cid] ?? 0);
      $reqKeys = (string) ($openReqKeysMap[$cid] ?? '');

      $isWaiting = ($status === 'waiting_client');
      $isActionRequired = $isWaiting || $openReq > 0;

      $isOverSla = false;
      if ($slaDue) {
        $dueTs = strtotime((string) $slaDue);
        if ($dueTs && $dueTs < $now && $status !== 'completed') {
          if (!$isWaiting)
            $isOverSla = true; // pause SLA while waiting_client
        }
      }

      $c['_ui'] = [
        'ref' => (string) (_g($c, 'ref_no', '#' . $cid)),
        'status' => $status,
        'stage' => $stage,
        'progress_pct' => _progress_percent($workflowKeys, $stage),
        'eta' => $eta,
        'sla_due' => $slaDue,
        'confidence' => $conf,
        'paused' => $paused,
        'eta_label' => $etaLabel,
        'paused_reason' => $pauseReason,
        'open_req' => $openReq,
        'req_keys' => $reqKeys,
        'action_required' => $isActionRequired,
        'waiting' => $isWaiting,
        'over_sla' => $isOverSla,
      ];

      if ($isActionRequired)
        $action_required[] = $c;
    }
    unset($c);
  }
}

// -------------------------------------------
// STAFF DASHBOARD: queue stats
// -------------------------------------------
else {
  if ($pdo) {
    // ✅ REAL SCHEMA: state (not status)
    try {
      $st = $pdo->query("
        SELECT state, COUNT(*) AS c
        FROM cases
        WHERE state <> 'completed'
        GROUP BY state
        ORDER BY c DESC
      ");
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $staff_counts[(string) $r['state']] = (int) $r['c'];
      }
    } catch (Throwable $e) {
      $staff_counts = [];
    }
  }
}
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <div>
          <h4 class="mb-1">Dashboard</h4>
          <div class="text-muted">Welcome back, <?php echo e($u['name']); ?>.</div>
        </div>

        <?php if (($u['role'] ?? '') === 'client'): ?>
          <div class="d-flex gap-2">
            <a class="btn btn-brand" href="/docsys/public/cases_submit.php">New case</a>
            <a class="btn btn-outline-brand" href="/docsys/public/my_cases.php">View all</a>
          </div>
        <?php else: ?>
          <div class="d-flex gap-2">
            <a class="btn btn-warning" href="/docsys/public/staff_queue.php">Open queue</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (($u['role'] ?? '') === 'client'): ?>

    <!-- Action Required (top) -->
    <div class="col-12">
      <div class="card shadow-sm border-warning">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h5 class="mb-0">Action Required</h5>
            <span class="text-muted small">Cases paused until you respond</span>
          </div>

          <?php if (empty($action_required)): ?>
            <div class="text-muted mt-3">No actions needed right now.</div>
          <?php else: ?>
            <div class="list-group list-group-flush mt-3">
              <?php foreach ($action_required as $c):
                $ui = $c['_ui']; ?>
                <!-- ✅ your real case page is case.php -->
                <a class="list-group-item list-group-item-action d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2"
                  href="/docsys/public/case.php?id=<?php echo (int) _g($c, 'id', 0); ?>">
                  <div>
                    <div class="fw-semibold">
                      <?php echo e($ui['ref']); ?>
                      <?php echo _badge('Action required', 'text-bg-warning'); ?>
                      <?php if ($ui['waiting']): ?>
                        <?php echo _badge('Paused — waiting on you', 'text-bg-secondary'); ?>
                      <?php endif; ?>
                    </div>
                    <div class="text-muted small">
                      <?php
                      $needed = [];
                      if ($ui['open_req'] > 0)
                        $needed[] = $ui['open_req'] . ' item(s) requested';
                      if (!empty($ui['req_keys']))
                        $needed[] = 'Slots: ' . $ui['req_keys'];
                      echo e(!empty($needed) ? implode(' • ', $needed) : 'Requested info/files');
                      ?>
                    </div>
                  </div>

                  <div class="text-md-end">
                    <div class="small text-muted">Projected completion</div>
                    <div class="fw-semibold"><?php echo e($ui['eta_label']); ?></div>
                    <?php if (!empty($ui['paused_reason'])): ?>
                      <div class="text-muted small"><?php echo e($ui['paused_reason']); ?></div>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- My Active Cases -->
    <div class="col-12">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h5 class="mb-0">My Active Cases</h5>
        <div class="text-muted small"><?php echo (int) count($cases); ?> active</div>
      </div>

      <?php if (empty($cases)): ?>
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="text-muted">No active cases yet.</div>
            <div class="mt-3">
              <a class="btn btn-brand" href="/docsys/public/cases_submit.php">Create your first case</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($cases as $c):
            $ui = $c['_ui']; ?>
            <div class="col-12 col-lg-6">
              <div class="card shadow-sm h-100">
                <div class="card-body">
                  <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                      <div class="fw-semibold fs-5"><?php echo e($ui['ref']); ?></div>
                      <div class="mt-1 d-flex flex-wrap gap-2">
                        <?php
                        $statusCls = 'text-bg-light';
                        if ($ui['status'] === 'waiting_client')
                          $statusCls = 'text-bg-secondary';
                        else if ($ui['status'] === 'processing')
                          $statusCls = 'text-bg-primary';
                        else if ($ui['status'] === 'review')
                          $statusCls = 'text-bg-info';
                        else if ($ui['status'] === 'validation')
                          $statusCls = 'text-bg-warning';
                        ?>
                        <?php echo _badge($ui['status'], $statusCls); ?>

                        <?php if ($ui['action_required']): ?>
                          <?php echo _badge('Action required', 'text-bg-warning'); ?>
                        <?php endif; ?>

                        <?php if ($ui['over_sla']): ?>
                          <?php echo _badge('Over SLA', 'text-bg-danger'); ?>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($ui['req_keys'])): ?>
                        <div class="text-muted small mt-2">
                          Missing slots: <span class="fw-semibold"><?php echo e($ui['req_keys']); ?></span>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="text-end">
                      <div class="small text-muted">Projected completion</div>
                      <div class="fw-semibold">
                        <?php
                        if (!empty($ui['paused']) && empty(_g($c, 'eta_date', null))) {
                          echo e('Paused');
                        } else {
                          echo e(_fmt_dt($ui['eta']));
                        }
                        ?>
                      </div>
                      <div class="small text-muted mt-2">Confidence</div>
                      <div class="fw-semibold"><?php echo e(_fmt_conf($ui['confidence'])); ?></div>
                    </div>
                  </div>

                  <div class="mt-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                      <span>Progress</span>
                      <span><?php echo (int) $ui['progress_pct']; ?>%</span>
                    </div>
                    <div class="progress" role="progressbar" aria-valuenow="<?php echo (int) $ui['progress_pct']; ?>"
                      aria-valuemin="0" aria-valuemax="100">
                      <div class="progress-bar" style="width: <?php echo (int) $ui['progress_pct']; ?>%"></div>
                    </div>

                    <div class="d-flex justify-content-between small text-muted mt-2">
                      <span>Current stage</span>
                      <span class="fw-semibold"><?php echo e($ui['stage']); ?></span>
                    </div>

                    <div class="d-flex justify-content-between small text-muted mt-1">
                      <span>SLA due</span>
                      <span class="fw-semibold"><?php echo e(_fmt_dt($ui['sla_due'])); ?></span>
                    </div>
                  </div>

                  <div class="mt-3 d-flex gap-2">
                    <!-- ✅ your real case page is case.php -->
                    <a class="btn btn-outline-brand"
                      href="/docsys/public/case.php?id=<?php echo (int) _g($c, 'id', 0); ?>">Open
                      case</a>

                    <?php if ($ui['action_required']): ?>
                      <a class="btn btn-brand"
                        href="/docsys/public/case.php?id=<?php echo (int) _g($c, 'id', 0); ?>#client-upload">Upload requested
                        items</a>
                    <?php endif; ?>
                  </div>

                  <div class="text-muted small mt-3">
                    Basis: projected completion is calculated from remaining stage averages (excluding waiting time).
                    Last updated: <?php echo e(_fmt_dt(_g($c, 'updated_at', null))); ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <!-- STAFF VIEW -->
    <div class="col-12 col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-1">Processing overview</h5>
          <div class="text-muted mb-3">Active cases by state (quick glance)</div>

          <?php if (empty($staff_counts)): ?>
            <div class="text-muted">No stats yet (or query needs schema adjustment).</div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($staff_counts as $state => $cnt): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div class="fw-semibold"><?php echo e($state); ?></div>
                  <span class="badge text-bg-dark"><?php echo (int) $cnt; ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="mt-3">
            <a class="btn btn-warning" href="/docsys/public/staff_queue.php">Open queue</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Admin tools</h5>

          <?php if (($u['role'] ?? '') === 'admin'): ?>
            <div class="d-grid gap-2">
              <a class="btn btn-brand" href="/docsys/public/admin/index.php">Open Admin Dashboard</a>
              <a class="btn btn-outline-brand" href="/docsys/public/admin/case_types.php">Case Types</a>
              <a class="btn btn-outline-secondary" href="/docsys/public/admin/requirements.php">Requirements</a>
              <a class="btn btn-outline-secondary" href="/docsys/public/admin/fields.php">Fields</a>
              <a class="btn btn-outline-brand" href="/docsys/public/admin/sla.php">SLA Policies</a>
              <a class="btn btn-outline-secondary" href="/docsys/public/admin/users.php">Users</a>
            </div>
          <?php else: ?>
            <div class="text-muted">
              Only admins can access Admin Tools. You can still process work from the staff queue.
            </div>
            <div class="mt-3">
              <a class="btn btn-warning w-100" href="/docsys/public/staff_queue.php">Open Staff Queue</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>