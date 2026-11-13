<?php
declare(strict_types=1);
/** Retired: "My Activity" was merged into the My Documents page. Kept only as a redirect for old bookmarks. */
require_once __DIR__ . '/../common.php';
require_login('Employee');
redirect('private_user/home.php');
