<?php
define('DOCSYS', 1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_role(['admin']);

$admin_title = 'Fields';

$types = db()->query("SELECT id, code, name FROM case_types ORDER BY name ASC")->fetchAll();
$type_id = (int)($_GET['type_id'] ?? ($_POST['type_id'] ?? 0));

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $act = $_POST['act'] ?? '';

  if ($type_id <= 0) $err = 'Select a case type first.';
  else {
    if ($act === 'add') {
      $field_key = strtolower(trim($_POST['field_key'] ?? ''));
      $label = trim($_POST['label'] ?? '');
      $field_type = trim($_POST['field_type'] ?? 'text');
      $is_required = (int)($_POST['is_required'] ?? 0) ? 1 : 0;
      $sort_order = (int)($_POST['sort_order'] ?? 0);
      $options = trim($_POST['options'] ?? '');

      if ($field_key === '' || !preg_match('/^[a-z0-9_]{2,40}$/', $field_key)) $err = 'Field key must be 2–40 chars (a-z, 0-9, underscore).';
      elseif ($label === '') $err = 'Label is required.';
      elseif (!in_array($field_type, ['text','textarea','select'], true)) $err = 'Invalid field type.';
      else {
        $options_json = null;
        if ($field_type === 'select') {
          $arr = array_values(array_filter(array_map('trim', explode("\n", $options))));
          if (!$arr) $err = 'Select fields must have options (one per line).';
          else $options_json = json_encode($arr, JSON_UNESCAPED_UNICODE);
        }

        if (!$err) {
          try {
            $st = db()->prepare("
              INSERT INTO case_type_fields (case_type_id, field_key, label, field_type, options_json, is_required, sort_order)
              VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
                label=VALUES(label), field_type=VALUES(field_type), options_json=VALUES(options_json),
                is_required=VALUES(is_required), sort_order=VALUES(sort_order)
            ");
            $st->execute([$type_id, $field_key, $label, $field_type, $options_json, $is_required, $sort_order]);
            $ok = 'Saved field.';
          } catch (Throwable $e) {
            $err = 'Save failed: ' . $e->getMessage();
          }
        }
      }
    }

    if ($act === 'delete') {
      $fid = (int)($_POST['fid'] ?? 0);
      try {
        db()->prepare("DELETE FROM case_type_fields WHERE id=? AND case_type_id=?")->execute([$fid, $type_id]);
        $ok = 'Deleted field.';
      } catch (Throwable $e) {
        $err = 'Delete failed: ' . $e->getMessage();
      }
    }
  }
}

$fields = [];
if ($type_id > 0) {
  $st = db()->prepare("SELECT * FROM case_type_fields WHERE case_type_id=? ORDER BY sort_order ASC, id ASC");
  $st->execute([$type_id]);
  $fields = $st->fetchAll();
}

include __DIR__ . '/_admin_layout_top.php';
?>

<?php if ($err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?php echo e($ok); ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12">
    <div class="card soft-card">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-12 col-lg-6">
            <label class="form-label">Case Type</label>
            <select class="form-select" name="type_id" onchange="this.form.submit()">
              <option value="">— Select —</option>
              <?php foreach ($types as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$t['id']===$type_id)?'selected':''; ?>>
                  <?php echo e($t['name'] . ' (' . $t['code'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-lg-6 text-lg-end">
            <a class="btn btn-outline-secondary" href="/docsys/public/admin/fields.php">Clear</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($type_id > 0): ?>
    <div class="col-12 col-lg-7">
      <div class="card soft-card">
        <div class="card-body">
          <h6 class="mb-2">Fields</h6>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Key</th>
                  <th>Label</th>
                  <th>Type</th>
                  <th>Required</th>
                  <th>Sort</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($fields as $f): ?>
                  <tr>
                    <td class="fw-semibold"><?php echo e($f['field_key']); ?></td>
                    <td><?php echo e($f['label']); ?></td>
                    <td class="text-muted"><?php echo e($f['field_type']); ?></td>
                    <td><?php echo ((int)$f['is_required']===1) ? '<span class="badge text-bg-danger">Yes</span>' : '<span class="badge text-bg-secondary">No</span>'; ?></td>
                    <td><?php echo (int)$f['sort_order']; ?></td>
                    <td class="text-end">
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this field?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="type_id" value="<?php echo (int)$type_id; ?>">
                        <input type="hidden" name="act" value="delete">
                        <input type="hidden" name="fid" value="<?php echo (int)$f['id']; ?>">
                        <button class="btn btn-sm btn-outline-secondary">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$fields): ?>
                  <tr><td colspan="6" class="text-muted">No fields yet.</td></tr>
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
          <h6 class="mb-3">Add / Update Field</h6>
          <form method="post" class="vstack gap-3">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="type_id" value="<?php echo (int)$type_id; ?>">
            <input type="hidden" name="act" value="add">

            <div>
              <label class="form-label">Field Key</label>
              <input class="form-control" name="field_key" placeholder="tin" required>
            </div>

            <div>
              <label class="form-label">Label</label>
              <input class="form-control" name="label" placeholder="TIN" required>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Type</label>
                <select class="form-select" name="field_type">
                  <option value="text">Text</option>
                  <option value="textarea">Textarea</option>
                  <option value="select">Select</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label">Sort Order</label>
                <input class="form-control" name="sort_order" type="number" value="10">
              </div>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_required" value="1" checked>
              <label class="form-check-label">Required</label>
            </div>

            <div>
              <label class="form-label">Options (for Select)</label>
              <textarea class="form-control" name="options" rows="4" placeholder="Option 1&#10;Option 2"></textarea>
              <div class="form-text">One option per line (only used when type=select).</div>
            </div>

            <button class="btn btn-brand">Save</button>
          </form>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="col-12">
      <div class="alert alert-info">Select a case type to manage fields.</div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_layout_bottom.php'; ?>
