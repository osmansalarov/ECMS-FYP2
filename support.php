<?php
declare(strict_types=1);
/** Support page: who to contact and the main rules of the system (values come from real configuration). */
require_once __DIR__ . '/common.php';
require_login();
$admins = db_all($conn, "SELECT u.full_name, u.email FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'Admin' AND u.status = 'Active' ORDER BY u.user_id");
page_header('Support');
page_title('Support', home_path(), 'Back to ' . (is_admin() ? 'Dashboard' : 'Dashboard'), 'Need help with ECMS? Contact an administrator.');
?>
<div class="grid-2">
  <div class="card"><div class="card-head"><h2>Administrators</h2></div><div class="card-body">
    <?php foreach ($admins as $a): ?><p class="mb-2"><b><?= h($a['full_name']) ?></b><br><a href="mailto:<?= h($a['email']) ?>"><?= h($a['email']) ?></a></p><?php endforeach; ?>
    <?php if (!$admins): ?><p class="text-muted mb-0">No active administrator account found.</p><?php endif; ?></div></div>
  <div class="card"><div class="card-head"><h2>Good to know</h2></div><div class="card-body"><ul class="mb-0 pl-3">
    <li>Allowed files: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, ODT, up to 5 MB.</li>
    <li>You are logged out automatically after <?= (int)(ECMS_SESSION_TIMEOUT / 60) ?> minutes of inactivity.</li>
    <li>After <?= (int)ECMS_MAX_FAILED ?> wrong passwords an account is locked for <?= (int)(ECMS_LOCK_SECONDS / 60) ?> minutes.</li>
    <li>Passwords need at least 8 characters, with a letter and a digit.</li></ul></div></div>
</div>
<?php page_footer();
