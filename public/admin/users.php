<?php
define('DOCSYS', 1);
require __DIR__ . '/../../app/bootstrap.php';
require_login();
require_role(['admin']);

$admin_title = 'Users';

$err = '';
$ok  = '';

function pw_hash(string $plain): string {
  return password_hash($plain, PASSWORD_DEFAULT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();
  $act = $_POST['act'] ?? '';

  // Create user
  if ($act === 'create') {
    $role  = $_POST['role'] ?? 'client';
    $name  = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if (!in_array($role, ['client','staff','admin'], true)) $err = 'Invalid role.';
    elseif ($name === '') $err = 'Name is required.';
    elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Valid email is required.';
    elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    else {
      try {
        // unique email check
        $q = db()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $q->execute([$email]);
        if ($q->fetch()) {
          $err = 'Email already exists.';
        } else {
          $st = db()->prepare("INSERT INTO users (role, name, email, password_hash) VALUES (?,?,?,?)");
          $st->execute([$role, $name, $email, pw_hash($pass)]);
          $ok = 'User created.';
        }
      } catch (Throwable $e) {
        $err = 'Create failed: ' . $e->getMessage();
      }
    }
  }

  // Update role
  if ($act === 'role') {
    $uid  = (int)($_POST['id'] ?? 0);
    $role = $_POST['role'] ?? 'client';

    if ($uid <= 0) $err = 'Invalid user.';
    elseif (!in_array($role, ['client','staff','admin'], true)) $err = 'Invalid role.';
    else {
      try {
        // prevent demoting yourself out of admin by accident (safety)
        $me = current_user();
        if ((int)$me['id'] === $uid && $me['role'] === 'admin' && $role !== 'admin') {
          $err = 'You cannot remove your own admin role.';
        } else {
          $st = db()->prepare("UPDATE users SET role=? WHERE id=?");
          $st->execute([$role, $uid]);
          $ok = 'Role updated.';
        }
      } catch (Throwable $e) {
        $err = 'Role update failed: ' . $e->getMessage();
      }
    }
  }

  // Reset password
  if ($act === 'reset_pw') {
    $uid  = (int)($_POST['id'] ?? 0);
    $pass = (string)($_POST['password'] ?? '');

    if ($uid <= 0) $err = 'Invalid user.';
    elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    else {
      try {
        $st = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $st->execute([pw_hash($pass), $uid]);
        $ok = 'Password reset.';
      } catch (Throwable $e) {
        $err = 'Password reset failed: ' . $e->getMessage();
      }
    }
  }

  // Delete user
  if ($act === 'delete') {
    $uid = (int)($_POST['id'] ?? 0);
    if ($uid <= 0) $err = 'Invalid user.';
    else {
      try {
        $me = current_user();
        if ((int)$me['id'] === $uid) {
          $err = 'You cannot delete your own account.';
        } else {
          // Optional safety: block deleting admins
          $q = db()->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
          $q->execute([$uid]);
          $row = $q->fetch();
          if ($row && $row['role'] === 'admin') {
            $err = 'Deleting admin accounts is blocked (safety). Change role first.';
          } else {
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            $ok = 'User deleted.';
          }
        }
      } catch (Throwable $e) {
        $err = 'Delete failed: ' . $e->getMessage();
      }
    }
  }
}

// filters
$q = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(name LIKE ? OR email LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}
if ($roleFilter !== '' && in_array($roleFilter, ['client','staff','admin'], true)) {
  $where[] = "role = ?";
  $params[] = $roleFilter;
}

$sql = "SELECT id, role, name, email, created_at FROM users";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY id DESC LIMIT 500";

$st = db()->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

include __DIR__ . '/_admin_layout_top.php';
?>

<?php if ($err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-success"><?php echo e($ok); ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card soft-card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Users</h6>
          <span class="badge badge-brand"><?php echo count($users); ?> shown</span>
        </div>

        <form class="row g-2 mb-3" method="get">
          <div class="col-12 col-md-6">
            <input class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Search name/email">
          </div>
          <div class="col-12 col-md-3">
            <select class="form-select" name="role">
              <option value="">All roles</option>
              <option value="client" <?php echo $roleFilter==='client'?'selected':''; ?>>Client</option>
              <option value="staff" <?php echo $roleFilter==='staff'?'selected':''; ?>>Staff</option>
              <option value="admin" <?php echo $roleFilter==='admin'?'selected':''; ?>>Admin</option>
            </select>
          </div>
          <div class="col-12 col-md-3 d-grid">
            <button class="btn btn-outline-brand">Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>User</th>
                <th>Role</th>
                <th>Created</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $urow): ?>
                <tr>
                  <td><?php echo (int)$urow['id']; ?></td>
                  <td>
                    <div class="fw-semibold"><?php echo e($urow['name']); ?></div>
                    <div class="text-muted small"><?php echo e($urow['email']); ?></div>
                  </td>
                  <td>
                    <?php
                      $badge = 'text-bg-secondary';
                      if ($urow['role'] === 'admin') $badge = 'text-bg-danger';
                      elseif ($urow['role'] === 'staff') $badge = 'text-bg-warning';
                      elseif ($urow['role'] === 'client') $badge = 'text-bg-info';
                    ?>
                    <span class="badge <?php echo $badge; ?>"><?php echo e($urow['role']); ?></span>
                  </td>
                  <td class="text-muted small"><?php echo e($urow['created_at']); ?></td>
                  <td class="text-end">
                    <!-- role change -->
                    <form method="post" class="d-inline">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="act" value="role">
                      <input type="hidden" name="id" value="<?php echo (int)$urow['id']; ?>">
                      <select name="role" class="form-select form-select-sm d-inline-block" style="width: 120px;">
                        <option value="client" <?php echo $urow['role']==='client'?'selected':''; ?>>client</option>
                        <option value="staff" <?php echo $urow['role']==='staff'?'selected':''; ?>>staff</option>
                        <option value="admin" <?php echo $urow['role']==='admin'?'selected':''; ?>>admin</option>
                      </select>
                      <button class="btn btn-sm btn-outline-brand">Save</button>
                    </form>

                    <!-- reset password -->
                    <button class="btn btn-sm btn-outline-secondary" type="button"
                      data-bs-toggle="collapse" data-bs-target="#pw<?php echo (int)$urow['id']; ?>">
                      Reset PW
                    </button>

                    <!-- delete -->
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this user?');">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$urow['id']; ?>">
                      <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>

                    <div class="collapse mt-2" id="pw<?php echo (int)$urow['id']; ?>">
                      <form method="post" class="d-flex gap-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="reset_pw">
                        <input type="hidden" name="id" value="<?php echo (int)$urow['id']; ?>">
                        <input class="form-control form-control-sm" name="password" placeholder="New password (min 8)">
                        <button class="btn btn-sm btn-brand">Set</button>
                      </form>
                    </div>

                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$users): ?>
                <tr><td colspan="5" class="text-muted">No users found.</td></tr>
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
        <h6 class="mb-3">Create User</h6>

        <form method="post" class="vstack gap-3">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="act" value="create">

          <div>
            <label class="form-label">Role</label>
            <select class="form-select" name="role">
              <option value="client">client</option>
              <option value="staff">staff</option>
              <option value="admin">admin</option>
            </select>
          </div>

          <div>
            <label class="form-label">Name</label>
            <input class="form-control" name="name" required>
          </div>

          <div>
            <label class="form-label">Email</label>
            <input class="form-control" name="email" type="email" required>
          </div>

          <div>
            <label class="form-label">Password</label>
            <input class="form-control" name="password" type="password" minlength="8" required>
            <div class="form-text">Minimum 8 characters.</div>
          </div>

          <button class="btn btn-brand" type="submit">Create User</button>
        </form>

        <div class="small-muted mt-3">
          Safety rules:
          <ul class="mb-0">
            <li>You cannot delete your own account.</li>
            <li>Deleting admin accounts is blocked (change role first).</li>
          </ul>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_admin_layout_bottom.php'; ?>
