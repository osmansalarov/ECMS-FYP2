<?php
declare(strict_types=1);
/**
 * ECMS shared bootstrap: secure session, idle timeout, CSRF, RBAC, DB helpers,
 * audit logging and page layout. Included by every page.
 */
require_once __DIR__ . '/include/validators.php';
require_once __DIR__ . '/include/connection.php';

const ECMS_SESSION_TIMEOUT = 900;   // 15 minutes idle => automatic logout
const ECMS_MAX_FAILED      = 5;     // failed logins before temporary lock
const ECMS_LOCK_SECONDS    = 900;   // 15 minute lock

/* ---------- Generic error handling (no stack traces to the user) ---------- */
set_exception_handler(function (Throwable $e): void {
    error_log('ECMS error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<p style="font-family:sans-serif">An unexpected error occurred. Please try again or contact the administrator.</p>';
});

/* ---------- Security headers ---------- */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
}
send_security_headers();

/* ---------- Session ---------- */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}
start_secure_session();

require_once __DIR__ . '/include/migrate.php';
if (empty($_SESSION['schema_v4'])) {
    ecms_migrate($conn);          // idempotent FYP2 schema upgrade, once per session
    $_SESSION['schema_v4'] = 1;
}

/** URL path of the application root, e.g. /ECMS_Prototype_FileManagement/ */
function app_url(string $path = ''): string
{
    $root = realpath(__DIR__);
    $doc  = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $base = '';
    if ($root !== false && $doc !== false) {
        $base = substr(str_replace('\\', '/', $root), strlen(str_replace('\\', '/', $doc)));
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ---------- DB helpers (always parameterised) ---------- */
function db_query(mysqli $c, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $c->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    $stmt = db_query($c, $sql, $types, $params);
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function db_one(mysqli $c, string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_all($c, $sql, $types, $params);
    return $rows[0] ?? null;
}

/* ---------- Audit log ---------- */
function log_activity(mysqli $c, ?int $userId, string $action, string $target, string $description): void
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    db_query(
        $c,
        'INSERT INTO activity_logs(user_id, action_type, target_id, description, ip_address) VALUES (?,?,?,?,?)',
        'issss',
        [$userId, $action, $target, $description, $ip]
    )->close();
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Invalid or missing security token. Go back, refresh the page and try again.');
    }
}

/* ---------- Flash messages ---------- */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
    return '<div class="alert alert-' . $type . '" role="alert">' . h($f['msg']) . '</div>';
}

/* ---------- Authentication / RBAC ---------- */
function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], true);
    }
    session_destroy();
}

/** Enforce idle timeout; returns true if the session was expired. */
function enforce_idle_timeout(mysqli $c): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > ECMS_SESSION_TIMEOUT) {
        log_activity($c, (int)$_SESSION['user_id'], 'Session Timeout', 'System', 'Automatic logout after inactivity');
        destroy_session();
        return true;
    }
    $_SESSION['last_activity'] = time();
    return false;
}

/** Require a logged-in user, optionally with a specific role ('Admin' | 'Employee'). */
function require_login(?string $role = null): void
{
    global $conn;
    if (enforce_idle_timeout($conn)) {
        redirect('login.php?msg=timeout');
    }
    if (!isset($_SESSION['user_id'])) {
        redirect('login.php');
    }
    if ($role !== null && ($_SESSION['role_name'] ?? '') !== $role) {
        log_activity($conn, (int)$_SESSION['user_id'], 'Access Denied', $_SERVER['SCRIPT_NAME'] ?? '', 'Attempted to access a ' . $role . '-only resource');
        http_response_code(403);
        exit('403 Forbidden: you do not have permission to access this page.');
    }
}

function is_admin(): bool
{
    return ($_SESSION['role_name'] ?? '') === 'Admin';
}

function home_path(): string
{
    return is_admin() ? 'Private_Dashboard/dashboard.php' : 'private_user/home.php';
}

/* ---------- View helpers ---------- */
function status_badge(string $status): string
{
    $map = ['Pending' => 'badge-warning', 'Complete' => 'badge-success', 'Incomplete' => 'badge-danger'];
    return '<span class="badge ' . ($map[$status] ?? 'badge-secondary') . '">' . h($status) . '</span>';
}

function format_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 2) . ' MB' : round($bytes / 1024, 1) . ' KB';
}

/* ---------- Upload handling shared by upload.php, add_file.php and send_documents.php ---------- */
/**
 * Validate and store an uploaded file under uploads/ with a random server-side name.
 * Returns ['ok'=>bool, 'msg'=>string, 'original', 'stored', 'mime', 'size'].
 */
function ecms_process_upload(?array $f): array
{
    $fail = static fn(string $m, string $o = ''): array => ['ok' => false, 'msg' => $m, 'original' => $o];
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        return $fail('No file selected, or the upload failed (check the 5 MB limit).');
    }
    $original = ecms_safe_download_name((string)$f['name']);
    [$ok, $msg, $info] = ecms_validate_upload($original, $f['tmp_name'], (int)$f['size']);
    if (!$ok) {
        return $fail($msg, $original);
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return $fail('Server storage is not available.', $original);
    }
    $stored = 'ECMS_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $info['ext'];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        return $fail('Failed to store the uploaded file.', $original);
    }
    return ['ok' => true, 'msg' => 'OK', 'original' => $original, 'stored' => $stored, 'mime' => $info['mime'], 'size' => $info['size']];
}

/* ---------- Human-readable audit log ---------- */
function log_quoted(string $description): string
{
    return preg_match('/"([^"]+)"/', $description, $m) ? $m[1] : '';
}

/** Plain-language sentence for one audit-log row (needs full_name, action_type, target_id, description). */
function log_sentence(array $r): string
{
    $who  = trim((string)($r['full_name'] ?? '')) !== '' ? (string)$r['full_name'] : 'An unknown user';
    $t    = (string)($r['target_id'] ?? '');
    $d    = (string)($r['description'] ?? '');
    $file = log_quoted($d);
    switch ((string)$r['action_type']) {
        case 'Login':                  return $who . ' logged in';
        case 'Logout':                 return $who . ' logged out';
        case 'Login Failed':           return ($r['full_name'] ?? null) ? 'Failed login attempt for ' . $who : 'Failed login attempt for an unknown email';
        case 'Login Blocked':          return 'Login refused: ' . $who . '\'s account is locked or disabled';
        case 'Account Locked':         return $who . '\'s account was locked after repeated failed logins';
        case 'Session Timeout':        return $who . ' was logged out automatically after inactivity';
        case 'Access Denied':
            return ($t !== '' && $t[0] === '/')
                ? $who . ' tried to open the ' . log_page_name($t) . ' and was denied'
                : $who . ' was denied: ' . ($d !== '' ? lcfirst($d) : 'attempt on ' . $t);
        case 'Upload Document':        return $who . ' uploaded ' . ($file ?: 'a document') . ' (' . $t . ', status Pending)';
        case 'Upload Rejected':        return $who . '\'s upload of ' . ($file ?: 'a file') . ' was rejected by validation';
        case 'Add Attachment':         return $who . ' added an additional file to ' . $t;
        case 'Download Document':      return $who . ' downloaded ' . ($file ?: $t);
        case 'Download Shared':        return $who . ' downloaded shared file ' . ($file ?: $t);
        case 'Delete Document':        return $who . ' deleted ' . ($file ?: $t);
        case 'Update Document Status': return $who . ' set ' . $t . ' to ' . (preg_match('/(Complete|Incomplete)/', $d, $m) ? $m[1] : 'a new status');
        case 'Request Documents':      return $who . ' requested additional documents for ' . $t;
        case 'Send Document':          return $who . ' sent ' . ($file ?: 'a document') . ' to ' . (preg_match('/(\d+) recipient/', $d, $m) ? $m[1] . ' recipient(s)' : 'employees');
        case 'Create User':            return $who . ' created an account for ' . $t;
        case 'Change User Status':     return $who . ' changed the status of ' . $t . ' (' . $d . ')';
        case 'Reset Password':         return $who . ' reset the password of ' . $t;
        case 'Change Password':        return $who . ' changed their own password';
        case 'Password Change Failed': return $who . ' entered a wrong current password while changing password';
        case 'Update Profile':         return $who . ' updated their profile';
        case 'Export':                 return $who . ' exported ' . $t;
        default:                       return $d !== '' ? $d : (string)$r['action_type'];
    }
}

/** Human-readable page names for script paths (used in activity logs, dashboard and CSV). */
function log_page_name(string $path): string
{
    static $map = [
        'Private_Dashboard/dashboard.php'        => 'Admin Dashboard',
        'Private_Dashboard/users.php'            => 'User Management',
        'Private_Dashboard/send_documents.php'   => 'Send Documents',
        'Private_Dashboard/verify_documents.php' => 'Review Submitted Documents',
        'Private_Dashboard/activity_logs.php'    => 'Activity Logs',
        'private_user/home.php'                  => 'Employee Dashboard',
        'private_user/inbox.php'                 => 'Inbox',
        'upload.php'                             => 'Upload Document',
        'add_file.php'                           => 'Add Additional File',
        'profile.php'                            => 'My Profile',
        'support.php'                            => 'Support',
        'download.php'                           => 'File Download',
        'delete.php'                             => 'Delete Document',
    ];
    $p = ltrim((string)preg_replace('#^/[^/]+/#', '', $path), '/');   // drop the "/ECMS_Prototype_FileManagement/" prefix
    return $map[$p] ?? ($p !== '' ? $p : 'a restricted page');
}

/** Target column text: page paths become page names, other targets (DOC-4 …) are unchanged. */
function log_target(string $t): string
{
    return ($t !== '' && $t[0] === '/') ? log_page_name($t) : $t;
}

/** Colour tone of an action: ok | info | warn | alert. */
function log_tone(string $action): string
{
    if (in_array($action, ['Login Failed', 'Login Blocked', 'Account Locked', 'Access Denied', 'Upload Rejected', 'Password Change Failed'], true)) {
        return 'alert';
    }
    if (in_array($action, ['Delete Document', 'Session Timeout', 'Reset Password', 'Request Documents'], true)) {
        return 'warn';
    }
    if (in_array($action, ['Login', 'Upload Document', 'Update Document Status', 'Send Document', 'Add Attachment'], true)) {
        return 'ok';
    }
    return 'info';
}

/** Quick-filter groups for the activity log. */
function log_groups(): array
{
    return [
        'Logins'          => ['Login', 'Logout', 'Session Timeout'],
        'Uploads'         => ['Upload Document', 'Upload Rejected', 'Add Attachment'],
        'Downloads'       => ['Download Document', 'Download Shared'],
        'Verification'    => ['Update Document Status', 'Request Documents'],
        'Sharing'         => ['Send Document'],
        'User Management' => ['Create User', 'Change User Status', 'Reset Password', 'Change Password', 'Update Profile'],
        'Security Alerts' => ['Login Failed', 'Login Blocked', 'Account Locked', 'Access Denied', 'Password Change Failed'],
    ];
}

/* ---------- Layout helpers ---------- */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

/** Inline stroke icon (24x24 viewBox). */
function icon(string $name, int $size = 20): string
{
    static $paths = [
        'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'send'     => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'list'     => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'inbox'    => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'help'     => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'check'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'alert'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'cloud'    => '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'filter'   => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    ];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Standard "Back" button. $path is relative to the app root. */
function back_button(string $path = '', string $label = 'Back'): string
{
    // Always "Back": returns to the previous page when the browser came from this site, otherwise to $path (default: own dashboard).
    $path = $path !== '' ? $path : home_path();
    $js = "var r=document.referrer;if(r&&r.indexOf(location.origin)===0&&r!==location.href){history.back();return false;}";
    return '<a class="btn btn-back btn-sm" href="' . h(app_url($path)) . '" onclick="' . h($js) . '">&larr; Back</a>';
}

/** Page title block: breadcrumb, title, optional subtitle, optional Back button and extra action HTML. */
function page_title(string $title, string $backPath = '', string $backLabel = 'Back', string $subtitle = '', string $actionsHtml = ''): void
{
    $onHome = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename(home_path());
    echo '<div class="page-head"><div>';
    if (!$onHome) {
        echo '<nav class="crumbs" aria-label="Breadcrumb"><a href="' . h(app_url(home_path())) . '">Dashboard</a><span>/</span>' . h($title) . '</nav>';
    }
    echo '<h1>' . h($title) . '</h1>' . ($subtitle !== '' ? '<p class="subtitle">' . h($subtitle) . '</p>' : '')
       . '</div><div class="head-actions">' . $actionsHtml . ($backPath !== '' ? back_button($backPath, $backLabel) : '') . '</div></div>';
}

/** Bell number: employee = unread Inbox + documents needing more files; admin = documents awaiting review. */
function bell_count(mysqli $c): int
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (is_admin()) {
        return (int)(db_one($c, "SELECT COUNT(*) n FROM documents WHERE document_status = 'Pending' AND needs_more = 0")['n'] ?? 0);
    }
    $unread = (int)(db_one($c, 'SELECT COUNT(*) n FROM shared_recipients WHERE user_id = ? AND read_at IS NULL', 'i', [$uid])['n'] ?? 0);
    $needs  = (int)(db_one($c, "SELECT COUNT(*) n FROM documents WHERE uploaded_by = ? AND needs_more = 1 AND document_status = 'Pending'", 'i', [$uid])['n'] ?? 0);
    return $unread + $needs;
}

function page_header(string $title): void
{
    global $conn;
    $admin   = is_admin();
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $name    = (string)($_SESSION['full_name'] ?? '');
    $unread  = (int)(db_one($conn, 'SELECT COUNT(*) n FROM shared_recipients WHERE user_id = ? AND read_at IS NULL', 'i', [(int)$_SESSION['user_id']])['n'] ?? 0);
    $menu = $admin
        ? [['Private_Dashboard/dashboard.php', 'Dashboard', 'grid', 0], ['Private_Dashboard/verify_documents.php', 'Verification', 'shield', 0],
           ['Private_Dashboard/send_documents.php', 'Send Documents', 'send', 0], ['private_user/inbox.php', 'Inbox', 'inbox', $unread],
           ['Private_Dashboard/users.php', 'User Management', 'users', 0], ['Private_Dashboard/activity_logs.php', 'Activity Logs', 'list', 0]]
        : [['private_user/home.php', 'Dashboard', 'grid', 0], ['upload.php', 'Upload Document', 'upload', 0],
           ['private_user/inbox.php', 'Inbox', 'inbox', $unread]];
    $bell     = bell_count($conn);
    $bellHref = $admin ? 'Private_Dashboard/verify_documents.php?status=Pending' : 'private_user/home.php';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . ' | ECMS</title><link rel="stylesheet" href="' . h(app_url('css/bootstrap.min.css')) . '">'
       . '<link rel="stylesheet" href="' . h(app_url('css/ecms.css')) . '?v=7"></head><body class="ecms">'
       . '<header class="topbar"><a class="brand" href="' . h(app_url(home_path())) . '"><span class="logo-box"><img src="' . h(app_url('css/uniten_logo.jpg')) . '" alt="UNITEN"></span>'
       . '<span class="brand-text">ECMS Academic Portal</span></a><div class="top-actions">'
       . '<a class="icon-btn" href="' . h(app_url($bellHref)) . '" title="' . ($admin ? 'Documents awaiting review' : 'Notifications') . '">' . icon('bell')
       . ($bell > 0 ? '<span class="dot">' . ($bell > 9 ? '9+' : $bell) . '</span>' : '') . '</a>'
       . '<span class="avatar" title="' . h($name) . '">' . h(initials($name)) . '</span></div></header>'
       . '<aside class="sidebar"><div class="who"><span class="avatar lg">' . h(initials($name)) . '</span><div><b>' . h($name) . '</b>'
       . '<small>Role: ' . ($admin ? 'Administrator' : 'Employee') . '</small></div></div><nav class="menu">';
    foreach ($menu as [$path, $label, $ico, $badge]) {
        $active = basename($path) === $current || ($current === 'add_file.php' && basename($path) === 'home.php');
        echo '<a class="' . ($active ? 'active' : '') . '" href="' . h(app_url($path)) . '">' . icon($ico) . '<span>' . h($label) . '</span>'
           . ($badge > 0 ? '<em class="count">' . (int)$badge . '</em>' : '') . '</a>';
    }
    echo '</nav><div class="menu bottom"><a class="' . ($current === 'profile.php' ? 'active' : '') . '" href="' . h(app_url('profile.php')) . '">' . icon('user') . '<span>My Profile</span></a>'
       . '<a class="' . ($current === 'support.php' ? 'active' : '') . '" href="' . h(app_url('support.php')) . '">' . icon('help') . '<span>Support</span></a></div></aside>'
       . '<main class="content">' . flash_render();
}

function page_footer(): void
{
    $ms  = ECMS_SESSION_TIMEOUT * 1000;
    $url = h(app_url('login.php?msg=timeout'));
    echo '</main><script>(function(){var t;function r(){clearTimeout(t);t=setTimeout(function(){location.href="' . $url . '";},' . $ms . ');}'
       . '["click","keydown","mousemove","touchstart"].forEach(function(e){document.addEventListener(e,r,{passive:true});});r();})();'
       . '(function(){document.querySelectorAll(".table-responsive").forEach(function(w){var t=w.querySelector("table");if(!t||w.scrollWidth<=w.clientWidth+1)return;'
       . 'var b=document.createElement("div");b.className="scroll-top";var i=document.createElement("div");i.style.width=t.scrollWidth+"px";i.style.height="1px";b.appendChild(i);'
       . 'w.parentNode.insertBefore(b,w);var s=0;b.addEventListener("scroll",function(){if(s){s=0;return;}s=1;w.scrollLeft=b.scrollLeft;});'
       . 'w.addEventListener("scroll",function(){if(s){s=0;return;}s=1;b.scrollLeft=w.scrollLeft;});});})();</script></body></html>';
}
