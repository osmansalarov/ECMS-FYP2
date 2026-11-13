<?php
declare(strict_types=1);
/** Own-profile page for Admin and Employee: edit name/email, change password; account dates are read-only. */
require_once __DIR__ . '/common.php';
require_login();

$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'profile') {
        $name  = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 120);
        $email = trim((string)($_POST['email'] ?? ''));
        if (mb_strlen($name) < 2) {
            flash_set('danger', 'Please enter your full name (at least 2 characters).');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            flash_set('danger', 'Please enter a valid email address.');
        } elseif (db_one($conn, 'SELECT user_id FROM users WHERE email = ? AND user_id <> ?', 'si', [$email, $uid])) {
            flash_set('danger', 'That email address is already used by another account.');
        } else {
            $old = db_one($conn, 'SELECT full_name, email FROM users WHERE user_id = ?', 'i', [$uid]);
            db_query($conn, 'UPDATE users SET full_name = ?, email = ? WHERE user_id = ?', 'ssi', [$name, $email, $uid])->close();
            $_SESSION['full_name'] = $name;
            $_SESSION['email']     = $email;
            log_activity($conn, $uid, 'Update Profile', 'User: ' . $email, 'Profile updated (was "' . ($old['full_name'] ?? '') . '" / ' . ($old['email'] ?? '') . ')');
            flash_set('success', 'Profile updated.');
        }
    } elseif ($do === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $row = db_one($conn, 'SELECT password_hash FROM users WHERE user_id = ?', 'i', [$uid]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            log_activity($conn, $uid, 'Password Change Failed', 'System', 'Wrong current password');
            flash_set('danger', 'Your current password is incorrect.');
        } elseif ($new !== $confirm) {
            flash_set('danger', 'The new password and its confirmation do not match.');
        } elseif (($err = ecms_password_error($new)) !== null) {
            flash_set('danger', $err);
        } elseif (hash_equals($current, $new)) {
            flash_set('warning', 'The new password must be different from the current one.');
        } else {
            db_query($conn, 'UPDATE users SET password_hash = ? WHERE user_id = ?', 'si', [password_hash($new, PASSWORD_DEFAULT), $uid])->close();
            session_regenerate_id(true);
            log_activity($conn, $uid, 'Change Password', 'System', 'User changed own password');
            flash_set('success', 'Password changed successfully.');
        }
    }
    redirect('profile.php');
}

$me = db_one($conn, 'SELECT u.full_name, u.email, u.status, u.created_at, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?', 'i', [$uid]);
// Newest 'Login' entry is the current session, so the one before it is the previous login.
$prev = db_one($conn, "SELECT created_at FROM activity_logs WHERE user_id = ? AND action_type = 'Login' ORDER BY log_id DESC LIMIT 1 OFFSET 1", 'i', [$uid]);
$docCount = is_admin() ? null : (int)db_one($conn, 'SELECT COUNT(*) c FROM documents WHERE uploaded_by = ?', 'i', [$uid])['c'];

page_header('My Profile');
page_title('My Profile', home_path(), 'Back to Dashboard', 'Manage your account details and password.');
?>
<div class="grid-2">
  <div class="card"><div class="card-head"><h2>Account details</h2></div><div class="card-body">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="do" value="profile">
      <div class="form-group"><label class="fl" for="fn">Full name</label><input class="form-control" id="fn" name="full_name" value="<?= h($me['full_name']) ?>" required minlength="2" maxlength="120"></div>
      <div class="form-group"><label class="fl" for="em">Email</label><input class="form-control" id="em" type="email" name="email" value="<?= h($me['email']) ?>" required maxlength="150"></div>
      <button class="btn btn-primary">Save changes</button>
    </form>
    <hr class="my-4">
    <dl class="meta mb-0">
      <dt>Role</dt><dd><?= h($me['role_name']) ?></dd>
      <dt>Status</dt><dd><span class="badge badge-success"><?= h($me['status']) ?></span></dd>
      <dt>Account created</dt><dd><?= h($me['created_at']) ?></dd>
      <dt>Previous login</dt><dd><?= $prev ? h($prev['created_at']) : '<span class="text-muted">First login</span>' ?></dd>
      <?php if ($docCount !== null): ?><dt>Documents uploaded</dt><dd><?= $docCount ?></dd><?php endif; ?>
    </dl>
  </div></div>
  <div class="card"><div class="card-head"><h2>Change password</h2></div><div class="card-body">
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="do" value="password">
      <div class="form-group"><label class="fl" for="cp">Current password</label><input class="form-control" id="cp" type="password" name="current_password" required maxlength="72"></div>
      <div class="form-group"><label class="fl" for="np">New password</label><input class="form-control" id="np" type="password" name="new_password" required maxlength="72">
        <small class="hint">At least 8 characters, with a letter and a digit.</small></div>
      <div class="form-group"><label class="fl" for="cf">Confirm new password</label><input class="form-control" id="cf" type="password" name="confirm_password" required maxlength="72"></div>
      <button class="btn btn-primary">Update password</button>
    </form>
  </div></div>
  <div class="card mt-3"><div class="card-head"><h2>Sign out</h2></div><div class="card-body">
    <form method="post" action="<?= h(app_url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('logout', 16) ?> Logout</button></form>
  </div></div>
</div>
<?php page_footer();
