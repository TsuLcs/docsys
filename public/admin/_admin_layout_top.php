<?php
if (!defined('DOCSYS'))
  exit;

$u = current_user();
$admin_title = $admin_title ?? 'Admin';

function admin_active($needle)
{
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  return (strpos($uri, $needle) !== false) ? 'active' : '';
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($admin_title); ?> · Admin</title>
  <?php if ($m = flash_get('error')): ?>
    <div class="alert alert-danger"><?php echo e($m); ?></div>
  <?php endif; ?>

  <?php if ($m = flash_get('success')): ?>
    <div class="alert alert-success"><?php echo e($m); ?></div>
  <?php endif; ?>

  <!-- Bootstrap CSS (keep whatever you already use in your normal layout) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/docsys/public/assets/brand.css">
  <style>
    .admin-shell {
      min-height: 100vh;
      background: var(--bg-soft);
    }

    .admin-sidebar {
      width: 280px;
      background: #fff;
      border-right: 1px solid var(--border);
      --bs-offcanvas-width: 280px;
      /* bootstrap offcanvas width */
    }

    /* Desktop behavior (lg and up): sticky sidebar */
    @media (min-width: 992px) {
      .admin-sidebar {
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
      }
    }

    /* Mobile spacing tweaks */
    @media (max-width: 991.98px) {
      .admin-content {
        padding: 16px;
      }

      .admin-topbar {
        border-radius: 14px;
      }
    }


    .admin-brand {
      font-weight: 800;
      letter-spacing: -0.02em;
    }

    .admin-link {
      border-radius: 12px;
      padding: .55rem .75rem;
      color: var(--ink);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: .6rem;
    }

    .admin-link:hover {
      background: var(--brand-50);
      color: var(--brand-600);
    }

    .admin-link.active {
      background: var(--brand-50);
      color: var(--brand-600);
      border: 1px solid rgba(249, 115, 22, .25);
    }

    .admin-link {
      white-space: nowrap;
      /* prevents wrapping */
    }

    .admin-link .admin-ico {
      width: 1.35rem;
      /* fixed icon column */
      flex: 0 0 1.35rem;
      text-align: center;
      line-height: 1;
    }

    .admin-link .admin-text {
      flex: 1 1 auto;
      min-width: 0;
    }


    .admin-content {
      padding: 24px;
    }

    .admin-topbar {
      background: #fff;
      border-bottom: 1px solid var(--border);
      padding: 14px 18px;
      border-radius: 16px;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .35rem .6rem;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--muted);
      font-size: .875rem;
    }

    .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: var(--brand);
      display: inline-block;
    }
  </style>
</head>

<body>
  <div class="admin-shell d-flex">
    <!-- Sidebar -->
    <aside class="admin-sidebar p-3">
      <div class="d-flex align-items-center gap-2 mb-3">
        <span class="dot"></span>
        <a class="admin-brand text-decoration-none text-dark" href="/docsys/public/">DocFlow</a>
        <span class="badge badge-brand ms-auto">Admin</span>
      </div>

      <div class="small-muted mb-3">
        Manage services, requirements, and SLA policies.
      </div>

      <nav class="d-grid gap-2">
        <a class="admin-link <?php echo admin_active('/admin/index.php'); ?>" href="/docsys/public/admin/index.php">🏠
          Dashboard</a>
        <a class="admin-link <?php echo admin_active('/admin/case_types.php'); ?>"
          href="/docsys/public/admin/case_types.php">🧾 Case Types</a>
        <a class="admin-link <?php echo admin_active('/admin/requirements.php'); ?>"
          href="/docsys/public/admin/requirements.php">📎 Requirements</a>
        <a class="admin-link <?php echo admin_active('/admin/fields.php'); ?>" href="/docsys/public/admin/fields.php">🧩
          Fields</a>
        <a class="admin-link <?php echo admin_active('/admin/sla.php'); ?>" href="/docsys/public/admin/sla.php">⏱ SLA
          Policies</a>
        <a class="admin-link <?php echo admin_active('/admin/users.php'); ?>" href="/docsys/public/admin/users.php">👤
          Users</a>
      </nav>

      <hr class="my-4">

      <div class="d-grid gap-2">
        <a class="btn btn-outline-brand" href="/docsys/public/dashboard.php">Back to App</a>
        <a class="btn btn-outline-secondary" href="/docsys/public/">Front page</a>
        <a class="btn btn-outline-danger" href="/docsys/public/logout.php?next=/docsys/public/">Logout</a>
      </div>

      <div class="small text-muted mt-4">
        Logged in as <span class="fw-semibold"><?php echo e($u['name']); ?></span><br>
        Role: <span class="fw-semibold"><?php echo e($u['role']); ?></span>
      </div>
    </aside>

    <!-- Main content -->
    <main class="flex-grow-1 admin-content">
      <div class="admin-topbar d-flex align-items-center justify-content-between mb-4">
        <div>
          <div class="h5 mb-0"><?php echo e($admin_title); ?></div>
          <div class="small-muted">Admin console</div>
        </div>


      </div>