<?php
declare(strict_types=1);

session_start();
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

function redirect(string $path): void {
  if (!headers_sent()) {
    header("Location: {$path}", true, 302);
    exit;
  }

  $p = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
  echo '<!doctype html><meta charset="utf-8">';
  echo '<meta http-equiv="refresh" content="0;url='.$p.'">';
  echo '<title>Redirecting…</title>';
  echo '<p>Redirecting to <a href="'.$p.'">'.$p.'</a></p>';
  exit;
}


function flash_set(string $key, string $msg): void {
  $_SESSION['_flash'][$key] = $msg;
}
function flash_get(string $key): ?string {
  $v = $_SESSION['_flash'][$key] ?? null;
  if (isset($_SESSION['_flash'][$key])) unset($_SESSION['_flash'][$key]);
  return $v;
}


function is_logged_in(): bool {
  return !empty($_SESSION['user']);
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}
function login_user(array $u): void {
  // Store only what you need (safe)
  $_SESSION['user'] = [
    'id'    => (int)$u['id'],
    'role'  => $u['role'],
    'name'  => $u['name'],
    'email' => $u['email'],
  ];
}

function logout_user(): void {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"],
      $params["secure"], $params["httponly"]
    );
  }
  session_destroy();
}

function require_login(): void {
  if (!is_logged_in()) redirect('/docsys/public/login.php');
}

function require_role(array $roles): void {
  require_login();
  $u = current_user();
  if (!$u || !in_array($u['role'], $roles, true)) {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
}

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function workflow(): array {
  static $wf = null;
  if ($wf === null) $wf = require __DIR__ . '/config/workflow.php';
  return $wf;
}

function status_badge(string $status): string {
  // Bootstrap contextual colors (simple mapping)
  $map = [
    'submitted'  => 'secondary',
    'received'   => 'info',
    'review'     => 'primary',
    'processing' => 'warning',
    'waiting'    => 'dark',
    'completed'  => 'success',
    'rejected'   => 'danger',
  ];
  $cls = $map[$status] ?? 'secondary';
  $label = workflow()[$status] ?? $status;
  return '<span class="badge text-bg-'.$cls.'">'.e($label).'</span>';
}
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_csrf'];
}

function csrf_field(): string {
  return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">';
}

function require_csrf(): void {
  $t = $_POST['_csrf'] ?? '';
  if (!$t || !hash_equals($_SESSION['_csrf'] ?? '', $t)) {
    http_response_code(400);
    echo "Bad Request (CSRF)";
    exit;
  }
}

function status_badge_class(string $status): string {
  // bootstrap badge colors
  return match($status) {
    'submitted'  => 'bg-secondary',
    'received'   => 'bg-info',
    'review'     => 'bg-primary',
    'processing' => 'bg-warning text-dark',
    'waiting'    => 'bg-dark',
    'completed'  => 'bg-success',
    'rejected'   => 'bg-danger',
    default      => 'bg-secondary',
  };
}
function badge_class_case_state(string $state): string {
  return match($state) {
    'submitted' => 'bg-secondary',
    'received' => 'bg-info',
    'validation' => 'bg-primary',
    'waiting_client' => 'bg-dark',
    'processing' => 'bg-warning text-dark',
    'review' => 'bg-primary',
    'approved' => 'bg-success',
    'released' => 'bg-success',
    'completed' => 'bg-success',
    'rejected' => 'bg-danger',
    default => 'bg-secondary',
  };
}

function add_case_event(int $case_id, string $type, int $actor_id, array $meta = []): void {
  $stmt = db()->prepare("INSERT INTO case_events (case_id, event_type, actor_id, meta_json) VALUES (?, ?, ?, ?)");
  $stmt->execute([$case_id, $type, $actor_id, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
}
function sla_hours_for(int $case_type_id, string $state): ?int {
  $q = db()->prepare("SELECT sla_hours FROM sla_policies WHERE case_type_id=? AND state=? AND is_active=1 LIMIT 1");
  $q->execute([$case_type_id, $state]);
  $row = $q->fetch();
  return $row ? (int)$row['sla_hours'] : null;
}

function sla_due_at_for(int $case_type_id, string $state, string $entered_at): ?string {
  // Calendar-hours SLA (simple, works everywhere).
  // Later we can swap to business-day calc.
  $h = sla_hours_for($case_type_id, $state);
  if (!$h || $h <= 0) return null;

  $ts = strtotime($entered_at);
  if ($ts === false) return null;

  return date('Y-m-d H:i:s', $ts + ($h * 3600));
}

function close_open_timer(int $case_id): void {
  // Close the currently open timer row (if any)
  $q = db()->prepare("SELECT id, entered_at FROM case_state_timers WHERE case_id=? AND exited_at IS NULL ORDER BY id DESC LIMIT 1");
  $q->execute([$case_id]);
  $t = $q->fetch();
  if (!$t) return;

  $entered_ts = strtotime($t['entered_at']);
  $now_ts = time();
  $spent = ($entered_ts !== false) ? max(0, $now_ts - $entered_ts) : null;

  $up = db()->prepare("UPDATE case_state_timers SET exited_at=NOW(), seconds_spent=? WHERE id=?");
  $up->execute([$spent, (int)$t['id']]);
}

function start_timer_and_set_sla(int $case_id, int $case_type_id, string $state): void {
  // Start a new timer for this state
  $ins = db()->prepare("INSERT INTO case_state_timers (case_id, state, entered_at) VALUES (?, ?, NOW())");
  $ins->execute([$case_id, $state]);

  // Update cases.sla_due_at for this state (unless waiting_client)
  if ($state === 'waiting_client') {
    $up = db()->prepare("UPDATE cases SET sla_due_at=NULL WHERE id=?");
    $up->execute([$case_id]);
    return;
  }

  // entered_at = NOW() from DB; compute due from current time
  $entered_at = date('Y-m-d H:i:s');
  $due = sla_due_at_for($case_type_id, $state, $entered_at);

  $up = db()->prepare("UPDATE cases SET sla_due_at=? WHERE id=?");
  $up->execute([$due, $case_id]);
}

/**
 * Find shortest path of states from current -> completed using workflow adjacency.
 */
function wf_shortest_path(array $workflow, string $from, string $to = 'completed'): array {
  if ($from === $to) return [$from];

  $q = [[$from]];
  $seen = [$from => true];

  while ($q) {
    $path = array_shift($q);
    $last = end($path);

    foreach (($workflow[$last] ?? []) as $nxt) {
      if (isset($seen[$nxt])) continue;
      $seen[$nxt] = true;

      $p2 = $path;
      $p2[] = $nxt;

      if ($nxt === $to) return $p2;
      $q[] = $p2;
    }
  }
  // fallback: no path found
  return [$from];
}

/**
 * Pull historical avg seconds + sample size per state for a case_type.
 * Uses case_state_timers for COMPLETED cases only.
 */
function hist_state_stats(int $case_type_id): array {
  $sql = "
    SELECT t.state,
           AVG(TIMESTAMPDIFF(SECOND, t.entered_at, t.exited_at)) AS avg_secs,
           COUNT(*) AS n
    FROM case_state_timers t
    JOIN cases c ON c.id = t.case_id
    WHERE c.case_type_id = ?
      AND c.state = 'completed'
      AND t.exited_at IS NOT NULL
      AND t.entered_at IS NOT NULL
    GROUP BY t.state
  ";
  $st = db()->prepare($sql);
  $st->execute([$case_type_id]);

  $out = [];
  while ($r = $st->fetch()) {
    $out[$r['state']] = [
      'avg_secs' => (int)round((float)$r['avg_secs']),
      'n' => (int)$r['n'],
    ];
  }
  return $out;
}

/**
 * Fallback duration per state if not enough history.
 * Priority: SLA policy hours (if you want to wire it in later) -> default 24h.
 */
function fallback_state_secs(string $state): int {
  // simple default for v1
  return 24 * 3600;
}

function fmt_duration(int $secs): string {
  $secs = max(0, $secs);
  $d = (int)floor($secs / 86400);
  $h = (int)floor(($secs % 86400) / 3600);
  $m = (int)floor(($secs % 3600) / 60);

  if ($d > 0) return "{$d}d {$h}h";
  if ($h > 0) return "{$h}h {$m}m";
  return "{$m}m";
}

/**
 * Returns forecast array:
 * - projected_due_at (datetime string)
 * - confidence (High/Medium/Low)
 * - paused (bool) + paused_reason
 * - breakdown: list of [state, secs, source, n]
 */
function forecast_case(int $case_id, array $workflow): array {
  // load case
  $cQ = db()->prepare("SELECT id, case_type_id, state FROM cases WHERE id=? LIMIT 1");
  $cQ->execute([$case_id]);
  $c = $cQ->fetch();
  if (!$c) return ['error' => 'case_not_found'];

  $case_type_id = (int)$c['case_type_id'];
  $cur_state = (string)$c['state'];

  // If waiting on client, pause forecast
  $openReqQ = db()->prepare("
    SELECT COUNT(*) AS n
    FROM case_tasks
    WHERE case_id=? AND status='open' AND task_type IN ('request_file','request_info')
  ");
  $openReqQ->execute([$case_id]);
  $openReq = (int)($openReqQ->fetch()['n'] ?? 0);

  if ($cur_state === 'waiting_client' && $openReq > 0) {
    return [
      'paused' => true,
      'paused_reason' => 'Waiting for client response / uploads.',
      'confidence' => 'Low',
      'projected_due_at' => null,
      'breakdown' => [],
    ];
  }

  // open timer -> elapsed in current state
  $timerQ = db()->prepare("
    SELECT entered_at
    FROM case_state_timers
    WHERE case_id=? AND exited_at IS NULL
    ORDER BY id DESC LIMIT 1
  ");
  $timerQ->execute([$case_id]);
  $openTimer = $timerQ->fetch();
  $elapsed = 0;
  if ($openTimer && !empty($openTimer['entered_at'])) {
    $elapsed = max(0, time() - strtotime($openTimer['entered_at']));
  }

  // path current -> completed
  $path = wf_shortest_path($workflow, $cur_state, 'completed');

  // historical stats per state
  $stats = hist_state_stats($case_type_id);

  // build remaining list INCLUDING current state as "remaining time"
  $breakdown = [];
  $covered = 0;
  $totalStages = 0;
  $sumSecs = 0;
  $sumN = 0;

  foreach ($path as $i => $st) {
    if ($st === 'completed' && $cur_state !== 'completed') {
      // completed is terminal; no duration needed
      continue;
    }

    $totalStages++;

    $avg = $stats[$st]['avg_secs'] ?? 0;
    $n   = $stats[$st]['n'] ?? 0;

    $useSecs = 0;
    $source = 'fallback';
    if ($avg > 0 && $n >= 2) { // require at least 2 samples
      $useSecs = $avg;
      $source = 'history';
      $covered++;
      $sumN += $n;
    } else {
      $useSecs = fallback_state_secs($st);
    }

    // current state: subtract time already spent (never below 0)
    if ($i === 0 && $elapsed > 0) {
      $useSecs = max(0, $useSecs - $elapsed);
    }

    $sumSecs += $useSecs;

    $breakdown[] = [
      'state' => $st,
      'secs' => $useSecs,
      'source' => $source,
      'n' => $n,
    ];
  }

  $coverage = ($totalStages > 0) ? ($covered / $totalStages) : 0;
  $confidence = 'Low';
  if ($coverage >= 0.75 && $sumN >= 10) $confidence = 'High';
  elseif ($coverage >= 0.40) $confidence = 'Medium';

  $projected = date('Y-m-d H:i:s', time() + $sumSecs);

  return [
    'paused' => false,
    'paused_reason' => null,
    'confidence' => $confidence,
    'projected_due_at' => $projected,
    'breakdown' => $breakdown,
  ];
}

/**
 * Recalculate + store forecast in cases table.
 */
function recalc_and_store_case_forecast(int $case_id, array $workflow): void {
  $f = forecast_case($case_id, $workflow);

  $due = $f['projected_due_at'] ?? null;
  $conf = $f['confidence'] ?? null;

  $reason = null;
  if (!empty($f['paused'])) {
    $reason = $f['paused_reason'] ?? 'Paused';
  } else {
    // short reason
    $parts = [];
    foreach (($f['breakdown'] ?? []) as $b) {
      $parts[] = $b['state'] . ':' . fmt_duration((int)$b['secs']);
      if (count($parts) >= 6) break;
    }
    $reason = $parts ? implode(' | ', $parts) : null;
  }

  $up = db()->prepare("
    UPDATE cases
    SET projected_due_at=?,
        projected_confidence=?,
        projected_reason=?,
        projected_calc_at=NOW()
    WHERE id=?
  ");
  $up->execute([$due, $conf, $reason, $case_id]);
}
