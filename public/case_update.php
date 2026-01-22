<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require_csrf();

$u = current_user();
$action = $_POST['action'] ?? '';
$case_id = (int) ($_POST['case_id'] ?? 0);
if ($case_id <= 0) {
    http_response_code(400);
    exit('Bad Request');
}

$cq = db()->prepare("SELECT * FROM cases WHERE id=? LIMIT 1");
$cq->execute([$case_id]);
$c = $cq->fetch();
if (!$c) {
    http_response_code(404);
    exit('Not found');
}

if ($u['role'] === 'client' && (int) $c['client_id'] !== (int) $u['id']) {
    http_response_code(403);
    exit('403 Forbidden');
}

function norm_files(array $files): array
{
    $out = [];
    if (empty($files['name']))
        return $out;
    $count = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => is_array($files['name']) ? ($files['name'][$i] ?? '') : ($files['name'] ?? ''),
            'type' => is_array($files['type']) ? ($files['type'][$i] ?? '') : ($files['type'] ?? ''),
            'tmp_name' => is_array($files['tmp_name']) ? ($files['tmp_name'][$i] ?? '') : ($files['tmp_name'] ?? ''),
            'error' => is_array($files['error']) ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => is_array($files['size']) ? ($files['size'][$i] ?? 0) : ($files['size'] ?? 0),
        ];
    }
    return $out;
}
function valid_up(array $f): bool
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return false;
    $sz = (int) ($f['size'] ?? 0);
    return $sz > 0 && $sz <= 15 * 1024 * 1024;
}

$workflow = require __DIR__ . '/../app/config/case_workflow.php';
$state_labels = require __DIR__ . '/../app/config/case_states.php';
try {
    if ($action === 'upload_req') {
        // client uploads to requirement slot (versioning)
        $req_key = trim($_POST['req_key'] ?? '');
        if ($req_key === '')
            throw new Exception('Missing req_key.');

        $files = norm_files($_FILES['files'] ?? []);
        if (!$files)
            throw new Exception('No files selected.');

        $uploadDir = __DIR__ . '/../storage/uploads';
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0775, true);

        db()->beginTransaction();

        $ins = db()->prepare("
      INSERT INTO case_files (case_id, req_key, original_name, stored_name, mime, size_bytes, version_no, uploaded_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

        $uploaded_count = 0;
        foreach ($files as $f) {
            if (!valid_up($f))
                continue;

            $vQ = db()->prepare("SELECT COALESCE(MAX(version_no),0) AS v FROM case_files WHERE case_id=? AND req_key=?");
            $vQ->execute([$case_id, $req_key]);
            $ver = (int) $vQ->fetch()['v'] + 1;

            $orig = $f['name'];
            $ext = pathinfo($orig, PATHINFO_EXTENSION);
            $stored = 'case_' . $case_id . '_' . $req_key . '_v' . $ver . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . strtolower($ext) : '');
            $dest = $uploadDir . '/' . $stored;

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                throw new Exception('Failed to store upload: ' . $orig);
            }

            $ins->execute([$case_id, $req_key, $orig, $stored, ($f['type'] ?: 'application/octet-stream'), (int) $f['size'], $ver, $u['id']]);
            $uploaded_count++;
        }

        add_case_event($case_id, 'file_uploaded', $u['id'], [
            'req_key' => $req_key,
            'count' => $uploaded_count
        ]);

        // Optional: if case is waiting_client and client uploaded something, move to validation automatically?
        // Real-world behavior: keep it waiting until staff confirms. We'll just log the event.

        db()->commit();
        recalc_and_store_case_forecast((int) $case_id, $workflow);
        redirect('/docsys/public/case.php?id=' . $case_id);

    } elseif ($action === 'create_task') {
        require_role(['staff', 'admin']);

        $title = trim($_POST['title'] ?? '');
        $task_type = $_POST['task_type'] ?? 'general';
        $details = trim($_POST['details'] ?? '');
        $req_key = trim($_POST['req_key'] ?? '');
        $assigned_to_raw = trim($_POST['assigned_to'] ?? '');
        $assigned_to = ($assigned_to_raw === '') ? null : (int) $assigned_to_raw;

        if ($title === '')
            throw new Exception('Task title required.');
        if (!in_array($task_type, ['request_info', 'request_file', 'approval', 'general'], true))
            throw new Exception('Invalid task type.');

        $ins = db()->prepare("
      INSERT INTO case_tasks (case_id, task_type, title, details, req_key, status, created_by, assigned_to)
      VALUES (?, ?, ?, ?, ?, 'open', ?, ?)
    ");
        $ins->execute([$case_id, $task_type, $title, ($details ?: null), ($req_key ?: null), $u['id'], $assigned_to]);

        add_case_event($case_id, 'task_created', $u['id'], [
            'task_type' => $task_type,
            'title' => $title,
            'req_key' => $req_key ?: null
        ]);

        // common real-world rule: when requesting info/files, put case into waiting_client
        if (in_array($task_type, ['request_info', 'request_file'], true) && $c['state'] !== 'waiting_client') {
            $to = 'waiting_client';
            if (in_array($to, $workflow[$c['state']] ?? [], true)) {
                $up = db()->prepare("UPDATE cases SET state=?, updated_at=NOW() WHERE id=?");
                $up->execute([$to, $case_id]);

                // SLA timers for automatic state change (after DB update succeeds)
                close_open_timer((int) $case_id);
                start_timer_and_set_sla((int) $case_id, (int) $c['case_type_id'], $to);
                add_case_event($case_id, 'sla_timer_started', $u['id'], ['state' => $to]);
                add_case_event($case_id, 'state_changed', $u['id'], ['from' => $c['state'], 'to' => $to, 'reason' => 'task_request']);
            }
        }

        recalc_and_store_case_forecast((int) $case_id, $workflow);
        redirect('/docsys/public/case.php?id=' . $case_id);

    } elseif ($action === 'task_done') {
        require_role(['staff', 'admin']);
        $task_id = (int) ($_POST['task_id'] ?? 0);
        if ($task_id <= 0)
            throw new Exception('Select task.');

        $up = db()->prepare("UPDATE case_tasks SET status='done', done_at=NOW() WHERE id=? AND case_id=? AND status='open'");
        $up->execute([$task_id, $case_id]);

        add_case_event($case_id, 'task_done', $u['id'], ['task_id' => $task_id]);
        recalc_and_store_case_forecast((int) $case_id, $workflow);
        redirect('/docsys/public/case.php?id=' . $case_id);

    } elseif ($action === 'transition') {
        require_role(['staff', 'admin']);

        $to = trim($_POST['to_state'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $assigned_to_raw = trim($_POST['assigned_to'] ?? '');
        $assigned_to = ($assigned_to_raw === '') ? null : (int) $assigned_to_raw;

        $eta_local = trim($_POST['eta_local'] ?? '');
        $eta_dt = null;
        if ($eta_local !== '') {
            $eta_dt = null;
            if ($eta_local !== '') {
                $dt = DateTime::createFromFormat('Y-m-d\TH:i', $eta_local, new DateTimeZone('Asia/Manila'));
                if ($dt) {
                    // store as local PH time (DATETIME)
                    $eta_dt = $dt->format('Y-m-d H:i:s');
                }
            }
        }

        $from = $c['state'];
        if ($to !== '' && $to !== $from) {
            if (!isset($state_labels[$to]))
                throw new Exception('Invalid target state.');
            if (!in_array($to, $workflow[$from] ?? [], true))
                throw new Exception('Transition not allowed.');
        }

        db()->beginTransaction();
        // SLA timers: if changing state, close the open timer first
        if ($to !== '' && $to !== $from) {
            close_open_timer((int) $case_id);
        }


        $sql = "UPDATE cases SET updated_at=NOW()";
        $params = [];

        if ($to !== '' && $to !== $from) {
            $sql .= ", state=?";
            $params[] = $to;
        }
        if ($eta_dt !== null) {
            $sql .= ", eta_date=?";
            $params[] = $eta_dt;
        }
        // assignment always applied based on selection
        if ($assigned_to_raw !== '') {
            $sql .= ", assigned_to=?";
            $params[] = $assigned_to;
        } else {
            $sql .= ", assigned_to=NULL";
        }

        $sql .= " WHERE id=?";
        $params[] = $case_id;

        $up = db()->prepare($sql);
        $up->execute($params);

        // SLA timers: start new timer + compute sla_due_at for the new state
        if ($to !== '' && $to !== $from) {
            start_timer_and_set_sla((int) $case_id, (int) $c['case_type_id'], $to);
            add_case_event($case_id, 'sla_timer_started', $u['id'], ['state' => $to]);
        }

        if ($to !== '' && $to !== $from) {
            add_case_event($case_id, 'state_changed', $u['id'], [
                'from' => $from,
                'to' => $to,
                'note' => ($note ?: null)
            ]);
        }
        if ($eta_dt !== null) {
            add_case_event($case_id, 'eta_set', $u['id'], ['eta' => $eta_dt]);
        }
        add_case_event($case_id, 'assignment_set', $u['id'], ['assigned_to' => $assigned_to]);

        db()->commit();
        recalc_and_store_case_forecast((int) $case_id, $workflow);
        redirect('/docsys/public/case.php?id=' . $case_id);

    } else {
        http_response_code(400);
        echo "Unknown action";
        exit;
    }
} catch (Throwable $e) {
    if (db()->inTransaction())
        db()->rollBack();
    http_response_code(500);
    echo "Error: " . $e->getMessage();
    exit;
}
