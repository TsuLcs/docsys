<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['client']);

$u = current_user();
$states = require __DIR__ . '/../app/config/case_states.php';

$err = '';
$selected_type_id = (int) ($_GET['type_id'] ?? ($_POST['type_id'] ?? 0));

// Fetch active case types + dept
$types = db()->query("
  SELECT ct.id, ct.code, ct.name, ct.description, d.name AS dept_name, d.id AS dept_id
  FROM case_types ct
  JOIN departments d ON d.id = ct.dept_id
  WHERE ct.is_active=1
  ORDER BY d.name ASC, ct.name ASC
")->fetchAll();

$fields = [];
$reqs = [];
$type_row = null;

if ($selected_type_id > 0) {
    $st = db()->prepare("
    SELECT ct.*, d.name AS dept_name, d.id AS dept_id
    FROM case_types ct
    JOIN departments d ON d.id = ct.dept_id
    WHERE ct.id=? AND ct.is_active=1
    LIMIT 1
  ");
    $st->execute([$selected_type_id]);
    $type_row = $st->fetch();

    if ($type_row) {
        $f = db()->prepare("SELECT * FROM case_type_fields WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
        $f->execute([$selected_type_id]);
        $fields = $f->fetchAll();

        $r = db()->prepare("SELECT * FROM case_type_requirements WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
        $r->execute([$selected_type_id]);
        $reqs = $r->fetchAll();
    }
}
function req_slot_files(string $slot): array
{
    // For input name="reqfile[<slot>][]" PHP stores as:
    // $_FILES['reqfile']['name'][<slot>][0..n]
    if (empty($_FILES['reqfile']) || empty($_FILES['reqfile']['name'][$slot]))
        return [];

    $names = $_FILES['reqfile']['name'][$slot];
    $types = $_FILES['reqfile']['type'][$slot] ?? [];
    $tmps = $_FILES['reqfile']['tmp_name'][$slot] ?? [];
    $errs = $_FILES['reqfile']['error'][$slot] ?? [];
    $sizes = $_FILES['reqfile']['size'][$slot] ?? [];

    $out = [];
    $count = is_array($names) ? count($names) : 0;

    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => $names[$i] ?? '',
            'type' => $types[$i] ?? '',
            'tmp_name' => $tmps[$i] ?? '',
            'error' => $errs[$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $sizes[$i] ?? 0,
        ];
    }
    return $out;
}

function valid_upload_case(array $f): bool
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return false;
    $sz = (int) ($f['size'] ?? 0);
    if ($sz <= 0 || $sz > 15 * 1024 * 1024)
        return false; // 15MB each
    return true;
}

// Create case on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $type_id = (int) ($_POST['type_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $prio = $_POST['priority'] ?? 'normal';

    if ($type_id <= 0)
        $err = 'Please select a case type.';
    elseif ($title === '')
        $err = 'Title is required.';
    elseif (!in_array($prio, ['low', 'normal', 'high'], true))
        $err = 'Invalid priority.';
    else {
        // reload type/fields/reqs for validation
        $st = db()->prepare("
      SELECT ct.*, d.name AS dept_name, d.id AS dept_id
      FROM case_types ct
      JOIN departments d ON d.id = ct.dept_id
      WHERE ct.id=? AND ct.is_active=1
      LIMIT 1
    ");
        $st->execute([$type_id]);
        $type_row = $st->fetch();

        if (!$type_row) {
            $err = 'Case type not found.';
        } else {
            $f = db()->prepare("SELECT * FROM case_type_fields WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
            $f->execute([$type_id]);
            $fields = $f->fetchAll();

            $r = db()->prepare("SELECT * FROM case_type_requirements WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
            $r->execute([$type_id]);
            $reqs = $r->fetchAll();

            // validate required fields
            $field_values = [];
            foreach ($fields as $fld) {
                $key = $fld['field_key'];
                $val = trim((string) ($_POST['field'][$key] ?? ''));
                if ((int) $fld['is_required'] === 1 && $val === '') {
                    $err = "Missing required field: {$fld['label']}";
                    break;
                }
                $field_values[$key] = $val;
            }

            // validate required files presence (by requirement slot)
            if (!$err) {
                foreach ($reqs as $req) {
                    if ((int) $req['is_required'] !== 1)
                        continue;
                    $slot = $req['req_key'];

                    $slotFiles = req_slot_files($slot); // ✅ MISSING LINE (this was the bug)

                    $hasOneOk = false;
                    foreach ($slotFiles as $sf) {
                        if (valid_upload_case($sf)) {
                            $hasOneOk = true;
                            break;
                        }
                    }
                    if (!$hasOneOk) {
                        $err = "Missing required file: {$req['label']}";
                        break;
                    }
                }
            }

            if (!$err) {
                try {
                    db()->beginTransaction();

                    // generate ref_no (safe and unique-ish): DEPTCODE-YYYYMMDD-000123
                    $date = date('Ymd');
                    $prefix = $type_row['code'] . '-' . $date . '-';
                    $seqRow = db()->prepare("SELECT COUNT(*) AS c FROM cases WHERE ref_no LIKE ?");
                    $seqRow->execute([$prefix . '%']);
                    $seq = (int) $seqRow->fetch()['c'] + 1;
                    $ref_no = $prefix . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

                    $ins = db()->prepare("
            INSERT INTO cases (ref_no, client_id, case_type_id, dept_id, title, description, state, priority)
            VALUES (?, ?, ?, ?, ?, ?, 'submitted', ?)
          ");
                    $ins->execute([
                        $ref_no,
                        $u['id'],
                        $type_row['id'],
                        $type_row['dept_id'],
                        $title,
                        $desc ?: null,
                        $prio
                    ]);
                    $case_id = (int) db()->lastInsertId();

                    // start SLA timer for initial state
                    start_timer_and_set_sla($case_id, (int)$type_row['id'], 'submitted');
                    add_case_event($case_id, 'sla_timer_started', $u['id'], ['state' => 'submitted']);

                    // field values
                    if ($field_values) {
                        $fv = db()->prepare("INSERT INTO case_field_values (case_id, field_key, value_text) VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)");
                        foreach ($field_values as $k => $v) {
                            if ($v === '')
                                continue;
                            $fv->execute([$case_id, $k, $v]);
                        }
                    }

                    // files: requirement slots (versioning per req_key)
                    $uploadDir = __DIR__ . '/../storage/uploads';
                    if (!is_dir($uploadDir))
                        mkdir($uploadDir, 0775, true);

                    $fileIns = db()->prepare("
                        INSERT INTO case_files (case_id, req_key, original_name, stored_name, mime, size_bytes, version_no, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($reqs as $req) {
                        $slot = $req['req_key'];
                        $slotFiles = req_slot_files($slot);
                        foreach ($slotFiles as $sf) {
                            if (!valid_upload_case($sf))
                                continue;

                            // version_no increments per (case_id, req_key)
                            $vq = db()->prepare("SELECT COALESCE(MAX(version_no),0) AS v FROM case_files WHERE case_id=? AND req_key=?");
                            $vq->execute([$case_id, $slot]);
                            $ver = (int) $vq->fetch()['v'] + 1;

                            $orig = $sf['name'];
                            $ext = pathinfo($orig, PATHINFO_EXTENSION);
                            $stored = 'case_' . $case_id . '_' . $slot . '_v' . $ver . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . strtolower($ext) : '');
                            $dest = $uploadDir . '/' . $stored;

                            if (!move_uploaded_file($sf['tmp_name'], $dest)) {
                                throw new Exception("Failed to store uploaded file ({$orig}).");
                            }

                            $mime = $sf['type'] ?: 'application/octet-stream';
                            $size = (int) $sf['size'];

                            $fileIns->execute([$case_id, $slot, $orig, $stored, $mime, $size, $ver, $u['id']]);
                        }
                    }

                    // event log
                    add_case_event($case_id, 'created', $u['id'], [
                        'ref_no' => $ref_no,
                        'case_type' => $type_row['code'],
                        'priority' => $prio
                    ]);

                    db()->commit();
                    redirect('/docsys/public/my_cases.php?created=1');

                } catch (Throwable $e) {
                    if (db()->inTransaction())
                        db()->rollBack();
                    $err = "Submit failed: " . $e->getMessage();
                }
            }
        }
    }
}

$title = 'New Case';
include __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">New Case Submission</h4>
    <a class="btn btn-outline-secondary" href="/docsys/public/my_cases.php">My Cases</a>
</div>

<?php if ($err): ?>
    <div class="alert alert-danger"><?php echo e($err); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-8">
                <label class="form-label">Case Type</label>
                <select class="form-select" name="type_id" onchange="this.form.submit()">
                    <option value="">— Select a service —</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo ((int) $t['id'] === $selected_type_id) ? 'selected' : ''; ?>>
                            <?php echo e($t['dept_name'] . ' · ' . $t['name'] . ' (' . $t['code'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <div class="text-muted small">Pick a type to load required fields/files.</div>
            </div>
        </form>
    </div>
</div>

<?php if ($type_row): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="mb-3">
                <div class="fw-semibold"><?php echo e($type_row['name']); ?></div>
                <div class="text-muted small"><?php echo e($type_row['dept_name']); ?> · <?php echo e($type_row['code']); ?>
                </div>
                <?php if (!empty($type_row['description'])): ?>
                    <div class="text-muted mt-1"><?php echo e($type_row['description']); ?></div>
                <?php endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="type_id" value="<?php echo (int) $type_row['id']; ?>">

                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <label class="form-label">Title</label>
                        <input class="form-control" name="title" required value="<?php echo e($_POST['title'] ?? ''); ?>">
                        <div class="form-text">Example: “ITR filing for 2025”</div>
                    </div>

                    <div class="col-12 col-lg-3">
                        <label class="form-label">Priority</label>
                        <select class="form-select" name="priority">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                        </select>
                    </div>

                    <div class="col-12 col-lg-3">
                        <label class="form-label">Department</label>
                        <input class="form-control" value="<?php echo e($type_row['dept_name']); ?>" disabled>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description (optional)</label>
                        <textarea class="form-control" name="description"
                            rows="3"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <?php if ($fields): ?>
                        <div class="col-12">
                            <hr>
                            <h6 class="mb-2">Required Information</h6>
                            <div class="row g-3">
                                <?php foreach ($fields as $fld): ?>
                                    <?php
                                    $k = $fld['field_key'];
                                    $val = $_POST['field'][$k] ?? '';
                                    $req = ((int) $fld['is_required'] === 1);
                                    $type = $fld['field_type'];
                                    $opts = $fld['options_json'] ? json_decode($fld['options_json'], true) : null;
                                    ?>
                                    <div class="col-12 col-lg-6">
                                        <label class="form-label">
                                            <?php echo e($fld['label']); ?>
                                            <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?>
                                        </label>

                                        <?php if ($type === 'textarea'): ?>
                                            <textarea class="form-control" name="field[<?php echo e($k); ?>]" rows="3" <?php echo $req ? 'required' : ''; ?>><?php echo e($val); ?></textarea>
                                        <?php elseif ($type === 'select' && is_array($opts)): ?>
                                            <select class="form-select" name="field[<?php echo e($k); ?>]" <?php echo $req ? 'required' : ''; ?>>
                                                <option value="">— Select —</option>
                                                <?php foreach ($opts as $o): ?>
                                                    <option value="<?php echo e((string) $o); ?>" <?php echo ((string) $o === (string) $val) ? 'selected' : ''; ?>>
                                                        <?php echo e((string) $o); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input class="form-control" name="field[<?php echo e($k); ?>]"
                                                value="<?php echo e($val); ?>" <?php echo $req ? 'required' : ''; ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="col-12">
                        <hr>
                        <h6 class="mb-2">Attachments</h6>
                        <div class="text-muted small mb-3">Upload files per requirement slot. You can upload multiple files
                            per slot.</div>

                        <?php if (!$reqs): ?>
                            <div class="alert alert-warning">No requirements defined for this case type yet.</div>
                        <?php else: ?>
                            <div class="vstack gap-3">
                                <?php foreach ($reqs as $req): ?>
                                    <div class="border rounded p-3 bg-light">
                                        <div class="fw-semibold">
                                            <?php echo e($req['label']); ?>
                                            <?php if ((int) $req['is_required'] === 1): ?><span
                                                    class="text-danger">*</span><?php endif; ?>
                                        </div>
                                        <div class="text-muted small mb-2">Slot key: <?php echo e($req['req_key']); ?></div>
                                        <input class="form-control" type="file" name="reqfile[<?php echo e($req['req_key']); ?>][]"
                                            multiple>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary">Submit Case</button>
                        <a class="btn btn-outline-secondary" href="/docsys/public/my_cases.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">Select a case type above to begin.</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_bottom.php'; ?>