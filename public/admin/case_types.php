<?php
define('DOCSYS', 1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_role(['admin']);

$admin_title = 'Case Types';

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $act = $_POST['act'] ?? '';

  if ($act === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $dept_id = (int)($_POST['dept_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $is_active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;

    if ($dept_id <= 0) $err = 'Department is required.';
    elseif ($code === '' || !preg_match('/^[A-Z0-9\-]{2,30}$/', $code)) $err = 'Code must be 2–30 chars (A-Z, 0-9, dash).';
    elseif ($name === '') $err = 'Name is required.';
    else {
      try {
        if ($id > 0) {
          $st = db()->prepare("UPDATE case_types SET dept_id=?, code=?, name=?, description=?, is_active=? WHERE id=?");
          $st->execute([$dept_id, $code, $name, $desc ?: null, $is_active, $id]);
          $ok = 'Updated case type.';
        } else {
          $st = db()->prepare("INSERT INTO case_types (dept_id, code, name, description, is_active) VALUES (?,?,?,?,?)");
          $st->execute([$dept_id, $code, $name, $desc ?: null, $is_active]);
          $ok = 'Created case type.';
        }
      } catch (Throwable $e) {
        $err = 'Save failed: ' . $e->getMessage();
      }
    }
  }

  if ($act === 'toggle') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    try {
      db()->prepare("UPDATE case_types SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$id]);
      $ok = 'Toggled active status.';
    } catch (Throwable $e) {
      $err = 'Toggle failed: ' . $e->getMessage();
    }
  }
}

$depts = db()->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
$types = db()->query("
  SELECT ct.*, d.name AS dept_name
  FROM case_types ct
  JOIN departments d ON d.id = ct.dept_id
  ORDER BY d.name ASC, ct.name ASC
")->fetchAll();

$edit_id = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($edit_id > 0) {
  foreach ($types as $t) if ((int)$t['id'] === $edit_id) { $edit = $t; break; }
}

include __DIR__ . '/_admin_layout_top.php';
?>

<?php if ($err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?php echo e($ok); ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card soft-card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">All Case Types</h6>
          <span class="badge badge-brand"><?php echo count($types); ?> total</span>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Department</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($types as $t): ?>
                <tr>
                  <td class="fw-semibold"><?php echo e($t['code']); ?></td>
                  <td><?php echo e($t['name']); ?></td>
                  <td class="text-muted"><?php echo e($t['dept_name']); ?></td>
                  <td>
                    <?php if ((int)$t['is_active'] === 1): ?>
                      <span class="badge text-bg-success">Active</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-brand" href="/docsys/public/admin/case_types.php?edit=<?php echo (int)$t['id']; ?>">Edit</a>
                    <form method="post" class="d-inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="act" value="toggle">
                      <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                      <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$types): ?>
                <tr><td colspan="5" class="text-muted">No case types yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card soft-card">
      <div class="card-body">
        <h6 class="mb-3"><?php echo $edit ? 'Edit Case Type' : 'Create Case Type'; ?></h6>

        <form method="post" class="vstack gap-3">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="act" value="save">
          <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

          <div>
            <label class="form-label">Department</label>
            <select class="form-select" name="dept_id" required>
              <option value="">— Select —</option>
              <?php foreach ($depts as $d): ?>
                <option value="<?php echo (int)$d['id']; ?>"
                  <?php echo ((int)($edit['dept_id'] ?? 0) === (int)$d['id']) ? 'selected' : ''; ?>>
                  <?php echo e($d['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="form-label">Code</label>
            <input class="form-control" name="code" placeholder="TAX-ITR" value="<?php echo e($edit['code'] ?? ''); ?>" required>
            <div class="form-text">Uppercase, digits and dash only.</div>
          </div>

          <div>
            <label class="form-label">Name</label>
            <input class="form-control" name="name" value="<?php echo e($edit['name'] ?? ''); ?>" required>
          </div>

          <div>
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3"><?php echo e($edit['description'] ?? ''); ?></textarea>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
              <?php echo ((int)($edit['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>>
            <label class="form-check-label">Active</label>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-brand" type="submit"><?php echo $edit ? 'Save Changes' : 'Create'; ?></button>
            <a class="btn btn-outline-secondary" href="/docsys/public/admin/case_types.php">Clear</a>
          </div>
        </form>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_admin_layout_bottom.php'; ?>
