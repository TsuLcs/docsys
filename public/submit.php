<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';
require_role(['client']);

$u = current_user();
$workflow = require __DIR__ . '/../app/config/workflow.php';

$err = '';
$ok  = '';

function valid_upload(array $f): bool {
  if (!isset($f['error']) || is_array($f['error'])) return false;
  if ($f['error'] !== UPLOAD_ERR_OK) return false;
  if ($f['size'] <= 0 || $f['size'] > 15 * 1024 * 1024) return false; // 15MB
  return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $prio  = $_POST['priority'] ?? 'normal';

  if ($title === '') {
    $err = 'Title is required.';
  } elseif (!in_array($prio, ['low','normal','high'], true)) {
    $err = 'Invalid priority.';
  } else {
    try {
      db()->beginTransaction();

      // create document
      $stmt = db()->prepare("
        INSERT INTO documents (client_id, title, description, current_status, priority)
        VALUES (?, ?, ?, 'submitted', ?)
      ");
      $stmt->execute([$u['id'], $title, $desc ?: null, $prio]);
      $doc_id = (int)db()->lastInsertId();

      // status history (first entry)
      $h = db()->prepare("
        INSERT INTO status_history (document_id, changed_by, from_status, to_status, note)
        VALUES (?, ?, NULL, 'submitted', ?)
      ");
      $h->execute([$doc_id, $u['id'], 'Submitted by client']);

      // handle uploads (multiple)
      if (!empty($_FILES['files']) && !empty($_FILES['files']['name'][0])) {
        $count = count($_FILES['files']['name']);

        for ($i=0; $i<$count; $i++) {
          $file = [
            'name' => $_FILES['files']['name'][$i] ?? '',
            'type' => $_FILES['files']['type'][$i] ?? '',
            'tmp_name' => $_FILES['files']['tmp_name'][$i] ?? '',
            'error' => $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $_FILES['files']['size'][$i] ?? 0,
          ];
          if (!valid_upload($file)) continue;

          $orig = $file['name'];
          $mime = $file['type'] ?: 'application/octet-stream';
          $size = (int)$file['size'];

          // safer stored name
          $ext = pathinfo($orig, PATHINFO_EXTENSION);
          $stored = 'doc_' . $doc_id . '_' . bin2hex(random_bytes(8)) . ($ext ? '.' . strtolower($ext) : '');

          $destDir = __DIR__ . '/../storage/uploads';
          if (!is_dir($destDir)) mkdir($destDir, 0775, true);

          $destPath = $destDir . '/' . $stored;
          if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('Failed to move uploaded file.');
          }

          $ins = db()->prepare("
            INSERT INTO document_files (document_id, original_name, stored_name, mime, size_bytes)
            VALUES (?, ?, ?, ?, ?)
          ");
          $ins->execute([$doc_id, $orig, $stored, $mime, $size]);
        }
      }

      db()->commit();
      redirect('/docsys/public/my_documents.php?created=1');

    } catch (Throwable $e) {
      if (db()->inTransaction()) db()->rollBack();
      $err = 'Failed to submit. ' . $e->getMessage();
    }
  }
}

$titlePage = 'New Submission';
include __DIR__ . '/_layout_top.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h4 class="mb-3">New Submission</h4>

        <?php if ($err): ?>
          <div class="alert alert-danger"><?php echo e($err); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input class="form-control" name="title" value="<?php echo e($_POST['title'] ?? ''); ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="4"><?php echo e($_POST['description'] ?? ''); ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Priority</label>
            <select class="form-select" name="priority">
              <option value="low">Low</option>
              <option value="normal" selected>Normal</option>
              <option value="high">High</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Files (optional, up to 15MB each)</label>
            <input class="form-control" type="file" name="files[]" multiple>
          </div>

          <button class="btn btn-primary">Submit</button>
          <a class="btn btn-link" href="/docsys/public/dashboard.php">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_bottom.php'; ?>
