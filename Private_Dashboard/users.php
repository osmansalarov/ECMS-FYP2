<?php
declare(strict_types=1);
/** Admin user management: create accounts, enable/disable, reset password. All queries parameterised. */
require_once __DIR__ . '/../common.php';
require_login('Admin');

$self = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'create') {
        $name  = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 120);
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = (string)($_POST['role'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        $err = null;
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $err = 'Enter a valid name and email address.';
        } elseif (!in_array($role, ['Admin', 'Employee'], true)) {
            $err = 'Invalid role.';
        } elseif ($pass !== $pass2) {
            $err = 'The password and its confirmation do not match.';
        } elseif (($err = ecms_password_error($pass)) === null && db_one($conn, 'SELECT user_id FROM users WHERE email = ?', 's', [$email])) {
            $err = 'That email address is already registered.';
        }
        if ($err === null) {
            db_query($conn, 'INSERT INTO users(role_id, full_name, email, password_hash, status) SELECT role_id, ?, ?, ?, \'Active\' FROM roles WHERE role_name = ?',
                'ssss', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role])->close();
            log_activity($conn, $self, 'Create User', $email, 'Created ' . $role . ' account');
            flash_set('success', 'Account created for ' . $email . '.');
        } else {
            flash_set('danger', $err);
        }
    } elseif ($do === 'toggle' || $do === 'reset' || $do === 'unlock') {
        $uid = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $target = $uid ? db_one($conn, 'SELECT user_id, email, status FROM users WHERE user_id = ?', 'i', [$uid]) : null;
        if (!$target) {
            flash_set('danger', 'User not found.');
        } elseif ($do === 'unlock') {
            db_query($conn, 'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?', 'i', [(int)$target['user_id']])->close();
            log_activity($conn, $self, 'Change User Status', $target['email'], 'Unlocked account');
            flash_set('success', $target['email'] . ' has been unlocked.');
        } elseif ($do === 'toggle') {
            if ((int)$target['user_id'] === $self) {
                flash_set('danger', 'You cannot disable your own account.');
            } else {
                $new = $target['status'] === 'Active' ? 'Disabled' : 'Active';
                db_query($conn, 'UPDATE users SET status = ?, failed_attempts = 0, locked_until = NULL WHERE user_id = ?', 'si', [$new, (int)$target['user_id']])->close();
                log_activity($conn, $self, 'Change User Status', $target['email'], 'Set account to ' . $new);
                flash_set('success', $target['email'] . ' is now ' . $new . '.');
            }
        } else {
            $pass = (string)($_POST['new_password'] ?? '');
            $err  = $pass !== (string)($_POST['new_password2'] ?? '') ? 'The new password and its confirmation do not match.' : ecms_password_error($pass);
            if ($err === null) {
                db_query($conn, 'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE user_id = ?',
                    'si', [password_hash($pass, PASSWORD_DEFAULT), (int)$target['user_id']])->close();
                log_activity($conn, $self, 'Reset Password', $target['email'], 'Password reset by admin');
                flash_set('success', 'Password reset for ' . $target['email'] . '.');
            } else {
                flash_set('danger', $err);
            }
        }
    }
    redirect('Private_Dashboard/users.php');
}

$users = db_all($conn, 'SELECT u.user_id, u.full_name, u.email, u.status, u.created_at, r.role_name,
                               (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked
                          FROM users u JOIN roles r ON u.role_id = r.role_id ORDER BY u.user_id');
page_header('User Management');
?>
<?php page_title('User Management', 'Private_Dashboard/dashboard.php', 'Back to Dashboard', 'Create accounts, disable or unlock users and reset passwords.'); ?>
<div class="card mb-4"><div class="card-head"><h2>Create account</h2></div><div class="card-body">
<form method="post" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="do" value="create">
  <div class="form-row">
    <div class="col-md-4 mb-3"><label class="fl" for="cn">Full name</label><input class="form-control" id="cn" name="full_name" required maxlength="120"></div>
    <div class="col-md-5 mb-3"><label class="fl" for="ce">Email</label><input class="form-control" id="ce" type="email" name="email" required maxlength="150"></div>
    <div class="col-md-3 mb-3"><label class="fl" for="cr">Role</label><select class="form-control" id="cr" name="role"><option>Employee</option><option>Admin</option></select></div>
  </div>
  <div class="form-row align-items-end">
    <div class="col-md-4 mb-2"><label class="fl" for="cp1">Password</label><input class="form-control" id="cp1" type="password" name="password" required maxlength="72"></div>
    <div class="col-md-4 mb-2"><label class="fl" for="cp2">Confirm password</label><input class="form-control" id="cp2" type="password" name="password2" required maxlength="72"></div>
    <div class="col-md-4 mb-2"><button class="btn btn-primary btn-block">Create account</button></div>
  </div>
  <small class="hint">At least 8 characters, with a letter and a digit. Both fields must match.</small>
</form></div></div>
<div class="card"><div class="table-responsive"><table class="tbl"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
<tr><td><?= (int)$u['user_id'] ?></td><td><?= h($u['full_name']) ?></td><td><?= h($u['email']) ?></td><td><?= h($u['role_name']) ?></td>
<td><?= h($u['status']) ?><?= (int)$u['is_locked'] === 1 ? ' <span class="badge badge-warning">Locked</span>' : '' ?></td>
<td>
<?php if ((int)$u['is_locked'] === 1): ?>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="unlock"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
  <button class="btn btn-sm btn-success">Unlock</button></form>
<?php endif; ?>
<?php if ((int)$u['user_id'] !== $self): ?>
<form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
  <button class="btn btn-sm btn-<?= $u['status'] === 'Active' ? 'warning' : 'success' ?>"><?= $u['status'] === 'Active' ? 'Disable' : 'Enable' ?></button></form>
<?php endif; ?>
<details class="d-inline-block align-middle"><summary class="btn btn-sm btn-ghost">Reset password</summary>
  <form method="post" autocomplete="off" class="mt-2" style="min-width:220px"><?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
    <input type="password" name="new_password" placeholder="New password" required maxlength="72" class="form-control form-control-sm mb-1">
    <input type="password" name="new_password2" placeholder="Confirm new password" required maxlength="72" class="form-control form-control-sm mb-1">
    <button class="btn btn-sm btn-primary">Save password</button></form></details>
</td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php page_footer();
