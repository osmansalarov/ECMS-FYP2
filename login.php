<?php
declare(strict_types=1);
/** ECMS login page + authentication (password_verify, lockout, session fixation protection). */
require_once __DIR__ . '/common.php';

if (isset($_SESSION['user_id']) && !enforce_idle_timeout($conn)) {
    redirect(home_path());
}

$error = '';
$info  = '';
if (($_GET['msg'] ?? '') === 'timeout') {
    $info = 'You were logged out automatically after inactivity. Please log in again.';
} elseif (($_GET['msg'] ?? '') === 'loggedout') {
    $info = 'You have been logged out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $generic  = 'Invalid email or password, or the account is locked/disabled.';

    $user = db_one($conn,
        'SELECT u.user_id, u.full_name, u.email, u.password_hash, u.status, u.failed_attempts,
                (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked, r.role_name
           FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.email = ? LIMIT 1',
        's', [$email]);

    // Always run password_verify to keep response time similar for unknown users.
    $hash  = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinva';
    $valid = password_verify($password, $hash);

    if (!$user) {
        log_activity($conn, null, 'Login Failed', 'System', 'Unknown email attempted login');
        $error = $generic;
    } elseif ((int)$user['is_locked'] === 1 || $user['status'] !== 'Active') {
        log_activity($conn, (int)$user['user_id'], 'Login Blocked', 'System', 'Login attempt on locked or disabled account');
        $error = $generic;
    } elseif (!$valid) {
        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= ECMS_MAX_FAILED) {
            db_query($conn, 'UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE user_id = ?',
                'ii', [ECMS_LOCK_SECONDS, (int)$user['user_id']])->close();
            log_activity($conn, (int)$user['user_id'], 'Account Locked', 'System', 'Locked after ' . ECMS_MAX_FAILED . ' failed logins');
        } else {
            db_query($conn, 'UPDATE users SET failed_attempts = ? WHERE user_id = ?', 'ii', [$attempts, (int)$user['user_id']])->close();
            log_activity($conn, (int)$user['user_id'], 'Login Failed', 'System', 'Incorrect password (attempt ' . $attempts . ')');
        }
        $error = $generic;
    } else {
        db_query($conn, 'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?', 'i', [(int)$user['user_id']])->close();
        session_regenerate_id(true);               // prevent session fixation
        $_SESSION['user_id']       = (int)$user['user_id'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['role_name']     = $user['role_name'];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf']);                  // new token for the new session
        log_activity($conn, (int)$user['user_id'], 'Login', 'System', $user['role_name'] . ' logged in');
        redirect(home_path());
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ECMS Login</title>
<link rel="stylesheet" href="<?= h(app_url('css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= h(app_url('css/ecms.css')) ?>"></head>
<body><div class="login-wrap"><div class="card login-card"><div class="card-body p-4">
<img src="<?= h(app_url('css/uniten_logo.jpg')) ?>" alt="Universiti Tenaga Nasional">
<h4 class="text-center font-weight-bold" style="color:#0b4b8f">Secure eCourseFile Management System</h4>
<p class="text-center text-muted mb-4">Authorized users only</p>
<?php if ($info): ?><div class="alert alert-info"><?= h($info) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="POST" action="login.php" autocomplete="off">
  <?= csrf_field() ?>
  <div class="form-group"><label for="email">Email</label>
    <input class="form-control" id="email" type="email" name="email" required maxlength="150" autofocus></div>
  <div class="form-group"><label for="password">Password</label>
    <input class="form-control" id="password" type="password" name="password" required maxlength="72"></div>
  <button class="btn btn-primary btn-block btn-lg">Login</button>
</form>
<p class="text-center text-muted small mt-3 mb-0">&copy; Universiti Tenaga Nasional &middot; FYP2 prototype</p>
</div></div></div></body></html>
