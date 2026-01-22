<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_login();

$u = current_user();
$state_labels = require __DIR__ . '/../app/config/case_states.php';
$workflow = require __DIR__ . '/../app/config/case_workflow.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  exit('Not found');
}

// Load case + type + dept + assigned
$q = db()->prepare("
  SELECT c.*, ct.name AS case_type_name, ct.code AS case_type_code, d.name AS dept_name,
         cu.name AS client_name, cu.email AS client_email,
         au.name AS assigned_name, au.role AS assigned_role
  FROM cases c
  JOIN case_types ct ON ct.id = c.case_type_id
  JOIN departments d ON d.id = c.dept_id
  JOIN users cu ON cu.id = c.client_id
  LEFT JOIN users au ON au.id = c.assigned_to
  WHERE c.id=?
  LIMIT 1
");
$q->execute([$id]);
$c = $q->fetch();
$forecast = forecast_case((int) $c['id'], $workflow);

// Option A (recommended): always show stored values if present, otherwise fallback to live forecast
$projected_due_at = $c['projected_due_at'] ?? null;
$projected_conf = $c['projected_confidence'] ?? null;
$projected_calc = $c['projected_calc_at'] ?? null;

if (!$projected_due_at || !$projected_conf) {
  // fallback to live forecast (useful if DB wasn’t recalculated yet)
  if (!empty($forecast['paused'])) {
    $projected_due_at = null;
    $projected_conf = $forecast['confidence'] ?? 'Low';
  } else {
    $projected_due_at = $forecast['projected_due_at'] ?? null;
    $projected_conf = $forecast['confidence'] ?? null;
  }
}

if (!$c) {
  http_response_code(404);
  exit('Not found');
}

if ($u['role'] === 'client' && (int) $c['client_id'] !== (int) $u['id']) {
  http_response_code(403);
  exit('403 Forbidden');
}

// requirements + uploaded overview
$reqQ = db()->prepare("SELECT * FROM case_type_requirements WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
$reqQ->execute([(int) $c['case_type_id']]);
$reqs = $reqQ->fetchAll();

$filesQ = db()->prepare("
  SELECT * FROM case_files
  WHERE case_id=?
  ORDER BY req_key ASC, version_no DESC, uploaded_at DESC
");
$filesQ->execute([$id]);
$allFiles = $filesQ->fetchAll();

// group files by req_key
$filesByKey = [];
foreach ($allFiles as $f) {
  $k = $f['req_key'] ?? '__other__';
  $filesByKey[$k][] = $f;
}

// dynamic fields + values
$fieldsQ = db()->prepare("SELECT * FROM case_type_fields WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
$fieldsQ->execute([(int) $c['case_type_id']]);
$fields = $fieldsQ->fetchAll();

$valsQ = db()->prepare("SELECT field_key, value_text FROM case_field_values WHERE case_id=?");
$valsQ->execute([$id]);
$vals = [];
foreach ($valsQ->fetchAll() as $v)
  $vals[$v['field_key']] = $v['value_text'];

// tasks
if ($u['role'] === 'client') {
  // clients see open tasks + done tasks that are not internal by design (we keep tasks always visible)
  $tQ = db()->prepare("SELECT t.*, uu.name AS creator_name FROM case_tasks t JOIN users uu ON uu.id=t.created_by WHERE t.case_id=? ORDER BY t.status ASC, t.created_at DESC");
} else {
  $tQ = db()->prepare("SELECT t.*, uu.name AS creator_name FROM case_tasks t JOIN users uu ON uu.id=t.created_by WHERE t.case_id=? ORDER BY t.status ASC, t.created_at DESC");
}
$tQ->execute([$id]);
$tasks = $tQ->fetchAll();

// events
$eQ = db()->prepare("
  SELECT e.*, uu.name AS actor_name, uu.role AS actor_role
  FROM case_events e
  JOIN users uu ON uu.id = e.actor_id
  WHERE e.case_id=?
  ORDER BY e.created_at ASC, e.id ASC
");
$eQ->execute([$id]);
$events = $eQ->fetchAll();

// staff list for assignment + task assignment
$staff = [];
if ($u['role'] !== 'client') {
  $staff = db()->query("SELECT id, name, role FROM users WHERE role IN ('staff','admin') ORDER BY role DESC, name ASC")->fetchAll();
}

$stateLabel = $state_labels[$c['state']] ?? $c['state'];
$eta = $c['eta_date'] ? date('Y-m-d H:i', strtotime($c['eta_date'])) : '-';
// SLA: time in current state
$timerQ = db()->prepare("SELECT entered_at FROM case_state_timers WHERE case_id=? AND exited_at IS NULL ORDER BY id DESC LIMIT 1");
$timerQ->execute([$id]);
$openTimer = $timerQ->fetch();

$time_in_state = '-';
if ($openTimer && !empty($openTimer['entered_at'])) {
  $secs = max(0, time() - strtotime($openTimer['entered_at']));
  $hrs = (int) floor($secs / 3600);
  $mins = (int) floor(($secs % 3600) / 60);
  $time_in_state = $hrs . 'h ' . $mins . 'm';
}

$sla_due = $c['sla_due_at'] ? date('Y-m-d H:i', strtotime($c['sla_due_at'])) : '-';
$is_sla_overdue = ($c['sla_due_at'] && strtotime($c['sla_due_at']) < time() && !in_array($c['state'], ['completed', 'rejected'], true));

$title = $c['ref_no'];
include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-start justify-content-between mb-3">
  <div>
    <h4 class="mb-1"><?php echo e($c['ref_no']); ?> — <?php echo e($c['title']); ?></h4>
    <div class="text-muted small">
      Type: <?php echo e($c['case_type_name']); ?> (<?php echo e($c['case_type_code']); ?>) · Dept:
      <?php echo e($c['dept_name']); ?><br>
      Client: <?php echo e($c['client_name']); ?> (<?php echo e($c['client_email']); ?>) · Created
      <?php echo e(date('Y-m-d H:i', strtotime($c['created_at']))); ?>
    </div>
  </div>
  <div class="text-end">
    <div class="mb-1">
      <span class="badge <?php echo e(badge_class_case_state($c['state'])); ?>"><?php echo e($stateLabel); ?></span>
      <span class="badge bg-light text-dark border text-capitalize ms-1"><?php echo e($c['priority']); ?></span>
    </div>
    <div class="small text-muted">ETA: <?php echo e($eta); ?></div>
    <div class="small text-muted">
      Projected completion: <?php echo e($projected_due_at ? date('Y-m-d H:i', strtotime($projected_due_at)) : '—'); ?>
    </div>
    <div class="small text-muted">
      Confidence: <?php echo e($projected_conf ?: '—'); ?>
    </div>

    <?php if (!empty($forecast['paused'])): ?>
      <div class="small text-muted">
        <span class="badge bg-secondary">Paused</span>
        <?php echo e($forecast['paused_reason'] ?? 'Waiting on client'); ?>
      </div>
    <?php endif; ?>
    <div class="small text-muted">Assigned:
      <?php echo e($c['assigned_name'] ? ($c['assigned_name'] . ' (' . $c['assigned_role'] . ')') : '—'); ?></div>
    <div class="small text-muted">
      SLA Due: <?php echo e($sla_due); ?>
      <?php if ($is_sla_overdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
    </div>
    <div class="small text-muted">Time in state: <?php echo e($time_in_state); ?></div>

  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-7">

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">Description</h5>
        <div class="text-muted"><?php echo nl2br(e($c['description'] ?? '—')); ?></div>
      </div>
    </div>

    <?php if ($fields): ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h5 class="card-title">Submitted Information</h5>
          <div class="row g-2">
            <?php foreach ($fields as $f): ?>
              <?php $k = $f['field_key']; ?>
              <div class="col-12 col-md-6">
                <div class="border rounded p-2 bg-light">
                  <div class="text-muted small"><?php echo e($f['label']); ?></div>
                  <div class="fw-semibold"><?php echo e($vals[$k] ?? '—'); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">Requirements Checklist</h5>

        <?php if (!$reqs): ?>
          <div class="text-muted">No requirements configured for this case type.</div>
        <?php else: ?>
          <div class="vstack gap-2">
            <?php foreach ($reqs as $r): ?>
              <?php
              $slot = $r['req_key'];
              $uploads = $filesByKey[$slot] ?? [];
              $ok = !empty($uploads);
              ?>
              <div class="border rounded p-3 <?php echo $ok ? 'bg-white' : 'bg-light'; ?>">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold">
                      <?php echo e($r['label']); ?>
                      <?php if ((int) $r['is_required'] === 1): ?><span class="text-danger">*</span><?php endif; ?>
                    </div>
                    <div class="text-muted small">Slot: <?php echo e($slot); ?></div>
                  </div>
                  <div>
                    <?php if ($ok): ?>
                      <span class="badge bg-success">Uploaded (<?php echo count($uploads); ?>)</span>
                    <?php else: ?>
                      <span class="badge bg-danger">Missing</span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($uploads): ?>
                  <div class="mt-2">
                    <div class="text-muted small mb-1">Latest versions:</div>
                    <ul class="list-group">
                      <?php foreach (array_slice($uploads, 0, 3) as $f): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                          <div>
                            <div class="fw-semibold"><?php echo e($f['original_name']); ?></div>
                            <div class="text-muted small">v<?php echo (int) $f['version_no']; ?> ·
                              <?php echo e(date('Y-m-d H:i', strtotime($f['uploaded_at']))); ?></div>
                          </div>
                          <a class="btn btn-sm btn-outline-primary"
                            href="/docsys/public/case_file.php?id=<?php echo (int) $f['id']; ?>">Download</a>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                    <?php if (count($uploads) > 3): ?>
                      <div class="text-muted small mt-1">+<?php echo count($uploads) - 3; ?> older versions</div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($u['role'] === 'client'): ?>
                  <form class="mt-2" method="post" enctype="multipart/form-data" action="/docsys/public/case_update.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="upload_req">
                    <input type="hidden" name="case_id" value="<?php echo (int) $c['id']; ?>">
                    <input type="hidden" name="req_key" value="<?php echo e($slot); ?>">
                    <div class="d-flex gap-2">
                      <input class="form-control" type="file" name="files[]" multiple>
                      <button class="btn btn-outline-secondary">Upload</button>
                    </div>
                    <div class="form-text">If staff requested missing docs, upload them here. New uploads create new versions.
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title">Tasks / Requests</h5>
        <?php if (!$tasks): ?>
          <div class="text-muted">No tasks yet.</div>
        <?php else: ?>
          <div class="vstack gap-2">
            <?php foreach ($tasks as $t): ?>
              <div class="border rounded p-2 bg-white">
                <div class="d-flex justify-content-between">
                  <div class="fw-semibold">
                    <?php echo e($t['title']); ?>
                    <?php if ($t['status'] === 'open'): ?>
                      <span class="badge bg-warning text-dark ms-1">Open</span>
                    <?php elseif ($t['status'] === 'done'): ?>
                      <span class="badge bg-success ms-1">Done</span>
                    <?php else: ?>
                      <span class="badge bg-secondary ms-1">Cancelled</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted small"><?php echo e(date('Y-m-d H:i', strtotime($t['created_at']))); ?></div>
                </div>
                <div class="text-muted small">Type: <?php echo e($t['task_type']); ?> · Created by
                  <?php echo e($t['creator_name']); ?></div>
                <?php if (!empty($t['details'])): ?>
                  <div class="mt-1"><?php echo nl2br(e($t['details'])); ?></div>
                <?php endif; ?>
                <?php if ($t['req_key']): ?>
                  <div class="text-muted small mt-1">Related slot: <?php echo e($t['req_key']); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($u['role'] !== 'client'): ?>
          <hr>
          <form method="post" action="/docsys/public/case_update.php" class="row g-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="case_id" value="<?php echo (int) $c['id']; ?>">

            <div class="col-12">
              <label class="form-label">Create a task / request</label>
              <input class="form-control" name="title" placeholder="e.g., Upload latest bank statements" required>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Task type</label>
              <select class="form-select" name="task_type">
                <option value="request_file">Request file</option>
                <option value="request_info">Request info</option>
                <option value="approval">Approval</option>
                <option value="general" selected>General</option>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Related requirement slot (optional)</label>
              <select class="form-select" name="req_key">
                <option value="">— None —</option>
                <?php foreach ($reqs as $r): ?>
                  <option value="<?php echo e($r['req_key']); ?>"><?php echo e($r['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Assign to (optional)</label>
              <select class="form-select" name="assigned_to">
                <option value="">— None —</option>
                <?php foreach ($staff as $s): ?>
                  <option value="<?php echo (int) $s['id']; ?>"><?php echo e($s['name'] . ' (' . $s['role'] . ')'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Details (optional)</label>
              <textarea class="form-control" name="details" rows="2"></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
              <button class="btn btn-outline-primary">Create task</button>
              <div class="text-muted small align-self-center">Tip: “Request file” + slot helps clients know exactly where
                to upload.</div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Comments</h5>

        <?php
        // comments: reuse your old comments table? we’ll use your existing comments table but map by document_id isn't possible.
        // So for now: we keep comments inside tasks OR use case_events.
        // Minimal: use a dedicated case comment endpoint using case_events meta.
        ?>

        <form method="post" action="/docsys/public/case_comment_add.php" class="border rounded p-3 bg-light">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="case_id" value="<?php echo (int) $c['id']; ?>">
          <div class="mb-2">
            <label class="form-label">Add a comment</label>
            <textarea class="form-control" name="body" rows="3" required></textarea>
          </div>

          <?php if ($u['role'] !== 'client'): ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" value="1" id="internal" name="internal">
              <label class="form-check-label" for="internal">Internal</label>
            </div>
          <?php endif; ?>

          <button class="btn btn-primary">Post</button>
        </form>

        <div class="mt-3">
          <div class="text-muted small mb-2">Recent comments (from audit log):</div>
          <?php
          $commentEvents = array_values(array_filter($events, fn($e) => $e['event_type'] === 'comment'));
          $commentEvents = array_reverse($commentEvents);
          $commentEvents = array_slice($commentEvents, 0, 10);
          ?>
          <?php if (!$commentEvents): ?>
            <div class="text-muted">No comments yet.</div>
          <?php else: ?>
            <div class="vstack gap-2">
              <?php foreach ($commentEvents as $e): ?>
                <?php $meta = $e['meta_json'] ? json_decode($e['meta_json'], true) : []; ?>
                <?php if ($u['role'] === 'client' && !empty($meta['internal']))
                  continue; ?>
                <div class="border rounded p-2 bg-white">
                  <div class="d-flex justify-content-between">
                    <div class="fw-semibold"><?php echo e($e['actor_name']); ?> <span
                        class="badge bg-light text-dark border"><?php echo e($e['actor_role']); ?></span>
                      <?php if (!empty($meta['internal'])): ?><span
                          class="badge bg-danger ms-1">Internal</span><?php endif; ?>
                    </div>
                    <div class="text-muted small"><?php echo e(date('Y-m-d H:i', strtotime($e['created_at']))); ?></div>
                  </div>
                  <div class="mt-1"><?php echo nl2br(e((string) ($meta['body'] ?? ''))); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </div>

  <div class="col-12 col-lg-5">

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5 class="card-title"><?php echo ($u['role'] === 'client') ? 'Case Updates' : 'Timeline / Audit'; ?></h5>

        <?php if (!$events): ?>
          <div class="text-muted">No events.</div>
        <?php else: ?>

          <?php if ($u['role'] === 'client'): ?>

            <?php
            // Client-visible events only (hide technical noise)
            $visible = [];
            foreach ($events as $e) {
              $meta = $e['meta_json'] ? json_decode($e['meta_json'], true) : [];

              // Hide internal comments from clients
              if ($e['event_type'] === 'comment' && !empty($meta['internal']))
                continue;

              // Hide technical/system events
              if (in_array($e['event_type'], ['sla_timer_started', 'assignment_set'], true))
                continue;

              // Hide task_done for clients unless you want them to see it
              // if ($e['event_type'] === 'task_done') continue;
        
              $visible[] = [$e, $meta];
            }

            // helper label builder
            function client_event_label($e, $meta, $state_labels)
            {
              $t = $e['event_type'];
              if ($t === 'created')
                return 'Case submitted';
              if ($t === 'state_changed') {
                $from = $meta['from'] ?? '';
                $to = $meta['to'] ?? '';
                $fromLab = $state_labels[$from] ?? $from;
                $toLab = $state_labels[$to] ?? $to;
                return "Status updated: {$fromLab} → {$toLab}";
              }
              if ($t === 'task_created') {
                $tt = $meta['task_type'] ?? '';
                if ($tt === 'request_file')
                  return 'Files requested';
                if ($tt === 'request_info')
                  return 'Information requested';
                return 'Task added';
              }
              if ($t === 'file_uploaded')
                return 'File uploaded';
              if ($t === 'eta_set')
                return 'ETA updated';
              if ($t === 'comment')
                return 'Comment';
              return $t; // fallback
            }

            function client_event_detail($e, $meta)
            {
              $t = $e['event_type'];
              if ($t === 'task_created') {
                $title = $meta['title'] ?? '';
                $slot = $meta['req_key'] ?? '';
                if ($title && $slot)
                  return $title . " (slot: {$slot})";
                if ($title)
                  return $title;
              }
              if ($t === 'file_uploaded') {
                $slot = $meta['req_key'] ?? '';
                $count = $meta['count'] ?? null;
                if ($slot && $count !== null)
                  return "Uploaded to {$slot} ({$count} file(s))";
                if ($slot)
                  return "Uploaded to {$slot}";
              }
              if ($t === 'comment') {
                return (string) ($meta['body'] ?? '');
              }
              if ($t === 'eta_set') {
                return !empty($meta['eta']) ? "New ETA: {$meta['eta']}" : null;
              }
              return null;
            }
            ?>

            <ul class="list-group">
              <?php foreach ($visible as [$e, $meta]): ?>
                <li class="list-group-item">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?php echo e(client_event_label($e, $meta, $state_labels)); ?></div>
                      <div class="text-muted small">by <?php echo e($e['actor_name']); ?></div>
                      <?php $detail = client_event_detail($e, $meta); ?>
                      <?php if ($detail): ?>
                        <div class="text-muted small mt-1"><?php echo nl2br(e($detail)); ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="text-muted small"><?php echo e(date('Y-m-d H:i', strtotime($e['created_at']))); ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>

          <?php else: ?>

            <!-- staff/admin keeps full audit -->
            <ul class="list-group">
              <?php foreach ($events as $e): ?>
                <?php $meta = $e['meta_json'] ? json_decode($e['meta_json'], true) : []; ?>
                <li class="list-group-item">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?php echo e($e['event_type']); ?></div>
                      <div class="text-muted small">by <?php echo e($e['actor_name']); ?> (<?php echo e($e['actor_role']); ?>)
                      </div>
                      <?php if ($meta): ?>
                        <div class="text-muted small mt-1"><?php echo e(json_encode($meta, JSON_UNESCAPED_UNICODE)); ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="text-muted small"><?php echo e(date('Y-m-d H:i', strtotime($e['created_at']))); ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>

          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>


    <?php if ($u['role'] !== 'client'): ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Staff Controls</h5>

          <?php
          $allowed = $workflow[$c['state']] ?? [];
          ?>

          <form method="post" action="/docsys/public/case_update.php" class="vstack gap-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="transition">
            <input type="hidden" name="case_id" value="<?php echo (int) $c['id']; ?>">

            <div>
              <label class="form-label">Transition</label>
              <select class="form-select" name="to_state" required>
                <option value="<?php echo e($c['state']); ?>">— Keep (<?php echo e($stateLabel); ?>) —</option>
                <?php foreach ($allowed as $to): ?>
                  <option value="<?php echo e($to); ?>"><?php echo e($state_labels[$to] ?? $to); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Only allowed moves are listed.</div>
            </div>

            <div>
              <label class="form-label">ETA (optional)</label>
              <input class="form-control" type="datetime-local" name="eta_local"
                value="<?php echo $c['eta_date'] ? e(date('Y-m-d\TH:i', strtotime($c['eta_date']))) : ''; ?>">
            </div>

            <div>
              <label class="form-label">Assign case to</label>
              <select class="form-select" name="assigned_to">
                <option value="">— Unassigned —</option>
                <?php foreach ($staff as $s): ?>
                  <option value="<?php echo (int) $s['id']; ?>" <?php echo ((int) $c['assigned_to'] === (int) $s['id']) ? 'selected' : ''; ?>>
                    <?php echo e($s['name'] . ' (' . $s['role'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Note (optional)</label>
              <input class="form-control" name="note" maxlength="500"
                placeholder="e.g., Missing 2307, requested from client">
            </div>

            <button class="btn btn-warning">Apply</button>
          </form>

          <hr>

          <form method="post" action="/docsys/public/case_update.php" class="vstack gap-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="task_done">
            <input type="hidden" name="case_id" value="<?php echo (int) $c['id']; ?>">
            <label class="form-label">Mark task as done</label>
            <select class="form-select" name="task_id" required>
              <option value="">— Select open task —</option>
              <?php foreach ($tasks as $t): ?>
                <?php if ($t['status'] !== 'open')
                  continue; ?>
                <option value="<?php echo (int) $t['id']; ?>"><?php echo e($t['title']); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-success">Mark Done</button>
          </form>

        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>