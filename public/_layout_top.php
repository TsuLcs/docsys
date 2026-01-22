<?php if (!defined('DOCSYS')) {
  http_response_code(403);
  exit;
} ?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e($title ?? 'DocSys'); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/docsys/public/assets/brand.css">
</head>

<body class="bg-light">
  <?php
  $navLoggedIn = function_exists('is_logged_in') && is_logged_in();
  $navUser = null;
  $navRole = null;
  if ($navLoggedIn && function_exists('current_user')) {
    $navUser = current_user();
    $navRole = $navUser['role'] ?? null;
  }
  ?>
  <nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container">
      <a class="navbar-brand fw-bold d-flex align-items-center" href="/docsys/public/">
        <span class="brand-dot"></span>
        DocFlow
        <span class="badge badge-brand ms-2">Tracking</span>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-3">

          <?php if (!$navLoggedIn): ?>
            <li class="nav-item"><a class="nav-link" href="/docsys/public/#services">Services</a></li>
            <li class="nav-item"><a class="nav-link" href="/docsys/public/#why">Why DocFlow</a></li>
            <li class="nav-item"><a class="nav-link" href="/docsys/public/#how">How it Works</a></li>
            <li class="nav-item"><a class="btn btn-outline-brand" href="/docsys/public/login.php">Login</a></li>
            <li class="nav-item"><a class="btn btn-brand" href="/docsys/public/register.php">Create Account</a></li>

          <?php else: ?>
            <li class="nav-item">
              <a class="nav-link" href="/docsys/public/">Front page</a>
            </li>

            <li class="nav-item"><a class="nav-link" href="/docsys/public/dashboard.php">Dashboard</a></li>

            <?php if ($navRole === 'client'): ?>
              <li class="nav-item"><a class="nav-link" href="/docsys/public/cases_submit.php">Submit</a></li>
              <li class="nav-item"><a class="nav-link" href="/docsys/public/my_cases.php">My Cases</a></li>
            <?php else: ?>
              <li class="nav-item"><a class="nav-link" href="/docsys/public/staff_queue.php">Queue</a></li>
            <?php endif; ?>

            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?php echo e($navUser['name'] ?? 'Account'); ?>
              </a>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text text-muted small">
                    Role: <?php echo e($navRole ?? '-'); ?>
                  </span></li>
                <li><a class="dropdown-item" href="/docsys/public/">Front page</a></li>
                <li>
                  <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item" href="/docsys/public/logout.php">Logout</a></li>
              </ul>
            </li>

          <?php endif; ?>

        </ul>
      </div>
    </div>
  </nav>

  <?php if ($m = flash_get('error')): ?>
    <div class="alert alert-danger"><?php echo e($m); ?></div>
  <?php endif; ?>

  <?php if ($m = flash_get('success')): ?>
    <div class="alert alert-success"><?php echo e($m); ?></div>
  <?php endif; ?>

  <style>
    /* Landing page responsiveness */
    .landing-hero .display-5 {
      font-size: clamp(1.75rem, 4vw, 3rem);
      line-height: 1.1;
    }

    .landing-hero .lead {
      font-size: clamp(1rem, 1.2vw + 0.9rem, 1.25rem);
    }

    @media (max-width: 575.98px) {
      .landing-hero .hero-card {
        border-radius: 16px;
        /* optional: softer on mobile */
      }

      .proofbar .fs-4 {
        font-size: 1.25rem !important;
      }

      .proofbar .stars {
        letter-spacing: 1px;
      }
    }
  </style>
  <main class="container py-4">