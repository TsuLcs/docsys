<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
  redirect('/docsys/public/dashboard.php');
}

$err = '';
$name  = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf();

  $name  = trim($_POST['name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($name === '' || $email === '' || $pass === '') {
    $err = 'Fill in all fields.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Invalid email.';
  } elseif (strlen($pass) < 8) {
    $err = 'Password must be at least 8 characters.';
  } else {
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    try {
      $stmt = db()->prepare("
        INSERT INTO users (role, name, email, password_hash)
        VALUES ('client', ?, ?, ?)
      ");
      $stmt->execute([$name, $email, $hash]);

      redirect('/docsys/public/login.php?registered=1');
    } catch (Throwable $e) {
      // If you want to be more precise, check SQLSTATE 23000 for duplicates
      $err = 'Email already exists (or DB error).';
    }
  }
}

$title = 'Register';
include __DIR__ . '/_layout_top.php';
?>

<section class="hero">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-5">
        <div class="hero-card p-4 shadow-sm">
          <div class="text-center mb-3">
            <div class="fw-bold fs-4">Create your account</div>
            <div class="text-muted">Register to submit and track your documents</div>
          </div>

          <?php if ($err): ?>
            <div class="alert alert-danger"><?php echo e($err); ?></div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <?php echo csrf_field(); ?>

            <div>
              <label class="form-label">Name</label>
              <input class="form-control"
                     name="name"
                     value="<?php echo e($name); ?>"
                     required
                     autocomplete="name"
                     autofocus>
            </div>

            <div>
              <label class="form-label">Email</label>
              <input class="form-control"
                     type="email"
                     name="email"
                     value="<?php echo e($email); ?>"
                     required
                     autocomplete="email">
            </div>

            <div>
              <label class="form-label">Password</label>
              <input class="form-control"
                     type="password"
                     name="password"
                     required
                     minlength="8"
                     autocomplete="new-password">
              <div class="form-text">At least 8 characters.</div>
            </div>

            <button class="btn btn-brand w-100">Create account</button>
          </form>

          <div class="text-center small text-muted mt-3">
            Already have an account?
            <a href="/docsys/public/login.php">Login</a>
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
