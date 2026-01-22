<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
  redirect('/docsys/public/dashboard.php');
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $err = 'Email and password are required.';
  } else {
    $st = db()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || !password_verify($pass, $u['password_hash'])) {
      $err = 'Invalid email or password.';
    } else {
      login_user($u); // your existing helper
      redirect('/docsys/public/dashboard.php');
    }
  }
}

$title = 'Login';
include __DIR__ . '/_layout_top.php';
?>

<section class="hero">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-5">
        <div class="hero-card p-4 shadow-sm">
          <div class="text-center mb-3">
            <div class="fw-bold fs-4">Welcome back</div>
            <div class="text-muted">Log in to track your documents</div>
          </div>

          <?php if ($err): ?>
            <div class="alert alert-danger"><?php echo e($err); ?></div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <?php echo csrf_field(); ?>

            <div>
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" required autofocus>
            </div>

            <div>
              <label class="form-label">Password</label>
              <input class="form-control" type="password" name="password" required>
            </div>

            <button class="btn btn-brand w-100">Login</button>
          </form>

          <div class="text-center small text-muted mt-3">
            No account yet?
            <a href="/docsys/public/register.php">Create one</a>
          </div>
        </div>

        <div class="text-center small-muted mt-3">
          Secure access · SLA-backed tracking · Full transparency
        </div>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
            