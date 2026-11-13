<?php
declare(strict_types=1);
/** Employee Inbox: documents sent by administrators. */
require_once __DIR__ . '/../common.php';
require_login('Employee');

$uid = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db_query($conn, 'UPDATE shared_recipients SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', 'i', [$uid])->close();
    flash_set('success', 'All documents marked as read.');
    redirect('private_user/inbox.php');
}
$items = db_all($conn, 'SELECT s.share_id, s.title, s.message, s.original_filename, s.file_size, s.sent_at, r.read_at, u.full_name
                          FROM shared_recipients r JOIN shared_documents s ON s.share_id = r.share_id JOIN users u ON u.user_id = s.sent_by
                         WHERE r.user_id = ? ORDER BY s.sent_at DESC', 'i', [$uid]);
$unread = count(array_filter($items, static fn($i) => $i['read_at'] === null));

ob_start();
if ($unread > 0): ?>
<form method="post" class="d-inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Mark all as read</button></form>
<?php endif;
$actions = (string)ob_get_clean();

page_header('Inbox');
page_title('Inbox', home_path(), 'Back to Dashboard', 'Documents sent to you by the administrators. Unread: ' . $unread . '.', $actions);
?>
<div class="card"><div class="table-responsive"><table class="tbl"><thead><tr><th>Document</th><th>From</th><th>Date</th><th class="text-right">Action</th></tr></thead><tbody>
<?php foreach ($items as $i): ?>
<tr><td><b><?= h($i['title']) ?></b> <?= $i['read_at'] === null ? '<span class="pill alert">New</span>' : '' ?>
  <?php if ($i['message']): ?><br><small><?= nl2br(h($i['message'])) ?></small><?php endif; ?>
  <br><small class="text-muted"><?= h($i['original_filename']) ?> &middot; <?= h(format_size((int)$i['file_size'])) ?></small></td>
<td><?= h($i['full_name']) ?></td><td class="text-nowrap"><?= h(date('d M Y, H:i', strtotime((string)$i['sent_at']))) ?></td>
<td class="text-right"><a class="btn btn-sm btn-primary" href="<?= h(app_url('download.php?share=' . (int)$i['share_id'])) ?>"><?= icon('download', 14) ?> Download</a></td></tr>
<?php endforeach; if (!$items): ?><tr><td colspan="4" class="empty">Your Inbox is empty. Documents sent by an administrator will appear here.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php page_footer();
