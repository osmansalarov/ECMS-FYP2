<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}
if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}
csrf_verify();
if (isset($_SESSION['user_id'])) {
    log_activity($conn, (int)$_SESSION['user_id'], 'Logout', 'System', ($_SESSION['role_name'] ?? '') . ' logged out');
}
destroy_session();
redirect('login.php?msg=loggedout');
