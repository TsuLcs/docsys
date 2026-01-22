<?php
require __DIR__ . '/../app/bootstrap.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
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
      $stmt = db()->prepare("INSERT INTO users (role, name, email, password_hash) VALUES ('client', ?, ?, ?)");
      $stmt->execute([$name, $email, $hash]);
      redirect('/docsys/public/login.php?registered=1');
    } catch (Throwable $e) {
      $err = 'Email already exists (or DB error).';
    }
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Register</title></head>
<body>
  <h1>Register (Client)</h1>

  <?php if ($err): ?>
    <p style="color:red;"><?php echo e($err); ?></p>
  <?php endif; ?>

  <form method="post">
    <div>
      <label>Name</label><br>
      <input name="name" value="<?php echo e($_POST['name'] ?? ''); ?>">
    </div>
    <div>
      <label>Email</label><br>
      <input name="email" value="<?php echo e($_POST['email'] ?? ''); ?>">
    </div>
    <div>
      <label>Password</label><br>
      <input type="password" name="password">
    </div>
    <button type="submit">Create account</button>
  </form>

  <p><a href="/docsys/public/login.php">Login</a></p>
</body>
</html>
