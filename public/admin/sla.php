<?php
define('DOCSYS', 1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_role(['admin']);

$admin_title = 'SLA Policies';

$types = db()->query("SELECT id, code, name FROM case_types ORDER BY name ASC")->fetchAll();
$type_id = (int)($_GET['type_id'] ?? ($_POST['type_id'] ?? 0));

$states = require __DIR__ . '/../../app/config/case_states.php';

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $act = $_POST['act'] ?? '';

  if ($type_id <= 0) $err = 'Select a case type first.';
  else {
    if ($act === 'save') {
      $state = trim($_POST['state'] ?? '');
      $sla_hours = (int)($_POST['sla_hours'] ?? 0);
      $is_active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;

      if ($state === '' || !isset($states[$state])) $err = 'Invalid state.';
      elseif ($sla_hours < 0 || $sla_hours > 10000) $err = 'SLA hours must be between 0 and 10000.';
      else {
        try {
          $st = db()->prepare("
            INSERT INTO sla_policies (case_type_id, state, sla_hours, is_active)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE sla_hours=VALUES(sla_hours), is_active=VALUES(is_active)
          ");
          $st->execute([$type_id, $state, $sla_hours, $is_active]);
          $ok = 'Saved SLA policy.';
        } catch (Throwable $e) {
          $err = 'Save failed: ' . $e->getMessage();
        }
      }
    }
  }
}

$pol = [];
if ($type_id > 0) {
  $st = db()->prepare("SELECT * FROM sla_policies WHERE case_type_id=? ORDER BY state ASC");
  $st->execute([$type_id]);
  $pol = $st->fetchAll();
}

$polMap = [];
foreach ($pol as $p) $polMap[$p['state']] = $p;

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
            <a class="btn btn-outline-secondary" href="/docsys/public/admin/sla.php">Clear</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($type_id > 0): ?>
    <div class="col-12">
      <div class="card soft-card">
        <div class="card-body">
          <h6 class="mb-2">SLA per State (hours)</h6>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>State</th>
                  <th>Label</th>
                  <th style="width:160px;">SLA (hours)</th>
                  <th style="width:140px;">Active</th>
                  <th class="text-end" style="width:140px;">Save</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($states as $key => $label): ?>
                <?php
                  $row = $polMap[$key] ?? null;
                  $hours = $row ? (int)$row['sla_hours'] : 0;
                  $active = $row ? ((int)$row['is_active']===1) : 0;
                ?>
                <tr>
                  <td class="fw-semibold"><?php echo e($key); ?></td>
                  <td><?php echo e($label); ?></td>
                  <td>
                    <form method="post" class="row g-2">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="type_id" value="<?php echo (int)$type_id; ?>">
                      <input type="hidden" name="act" value="save">
                      <input type="hidden" name="state" value="<?php echo e($key); ?>">
                      <input class="form-control" type="number" name="sla_hours" value="<?php echo (int)$hours; ?>" min="0" max="10000">
                  </td>
                  <td>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $active ? 'checked' : ''; ?>>
                        <label class="form-check-label">Enabled</label>
                      </div>
                  </td>
                  <td class="text-end">
                      <button class="btn btn-sm btn-brand">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="small-muted mt-2">
            Tip: set SLA to 0 and disable if you don’t want SLA in a state (ex: waiting_client).
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="col-12">
      <div class="alert alert-info">Select a case type to manage SLA policies.</div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_layout_bottom.php'; ?>
