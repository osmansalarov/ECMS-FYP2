<?php
declare(strict_types=1);
/** Employee Dashboard: summary cards, notices, and the "My Document Status" table. */
require_once __DIR__ . '/../common.php';
require_login('Employee');

$uid  = (int)$_SESSION['user_id'];
$docs = db_all($conn,
    'SELECT d.document_id, d.title, d.category, d.original_filename, d.document_status, d.uploaded_at, d.needs_more,
            (SELECT COUNT(*) FROM document_attachments a WHERE a.document_id = d.document_id) AS extra_files,
            (SELECT v.comment FROM verification_records v WHERE v.document_id = d.document_id ORDER BY v.verification_id DESC LIMIT 1) AS last_comment
       FROM documents d WHERE d.uploaded_by = ? ORDER BY d.uploaded_at DESC',
    'i', [$uid]);
$counts = ['Pending' => 0, 'Complete' => 0, 'Incomplete' => 0];
foreach ($docs as $d) {
    $counts[$d['document_status']]++;
}
$unread = (int)db_one($conn, 'SELECT COUNT(*) n FROM shared_recipients WHERE user_id = ? AND read_at IS NULL', 'i', [$uid])['n'];

page_header('Employee Dashboard');
page_title('Employee Dashboard', '', '', 'Welcome back, ' . $_SESSION['full_name'] . '. Here is your document summary.',
    '<a class="btn btn-success btn-lg-cta" href="' . h(app_url('upload.php')) . '">' . icon('plus', 18) . ' Upload New Document</a>');

foreach ($docs as $d) {
    if ((int)$d['needs_more'] === 1 && $d['document_status'] === 'Pending'): ?>
<div class="banner warn"><div><b>Additional documents required</b> for &ldquo;<?= h($d['title'] ?: $d['original_filename']) ?>&rdquo;.
  <?php if ($d['last_comment']): ?><br><small><?= h($d['last_comment']) ?></small><?php endif; ?></div>
  <a class="btn btn-warning btn-sm" href="<?= h(app_url('add_file.php?id=' . (int)$d['document_id'])) ?>">Add file</a></div>
<?php endif; }
if ($unread > 0): ?>
<div class="banner info"><div><b><?= $unread ?> unread document<?= $unread > 1 ? 's' : '' ?></b> in your Inbox.</div>
  <a class="btn btn-primary btn-sm" href="inbox.php">Open Inbox</a></div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="top"><span class="lbl">My Uploads</span><?= icon('cloud', 22) ?></div><div class="num"><?= count($docs) ?></div></div>
  <div class="stat warn"><div class="top"><span class="lbl">Pending</span><?= icon('clock', 22) ?></div><div class="num"><?= $counts['Pending'] ?></div></div>
  <div class="stat ok"><div class="top"><span class="lbl">Complete</span><?= icon('check', 22) ?></div><div class="num"><?= $counts['Complete'] ?></div></div>
  <div class="stat bad"><div class="top"><span class="lbl">Incomplete</span><?= icon('alert', 22) ?></div><div class="num"><?= $counts['Incomplete'] ?></div></div>
</div>

<div class="card"><div class="card-head"><h2>My Document Status</h2></div>
<div class="table-responsive"><table class="tbl"><thead><tr><th>Document</th><th>Category</th><th>Date uploaded</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
<?php foreach ($docs as $r): ?>
<tr><td><b><?= h($r['title'] ?: $r['original_filename']) ?></b><br><small><?= h($r['original_filename']) ?><?= (int)$r['extra_files'] > 0 ? ' &middot; +' . (int)$r['extra_files'] . ' additional file(s)' : '' ?></small></td>
<td><?= h($r['category'] ?: '-') ?></td>
<td class="text-nowrap"><?= h(date('d M Y', strtotime((string)$r['uploaded_at']))) ?></td>
<td><?= status_badge($r['document_status']) ?>
  <?php if ((int)$r['needs_more'] === 1 && $r['document_status'] === 'Pending'): ?><br><small class="text-warning">Additional document required</small><?php endif; ?>
  <?php if ($r['last_comment']): ?><br><small><?= h($r['last_comment']) ?></small><?php endif; ?></td>
<td class="text-right text-nowrap">
  <a class="btn btn-sm btn-ghost" href="<?= h(app_url('download.php?id=' . (int)$r['document_id'])) ?>">Download</a>
  <?php if ((int)$r['needs_more'] === 1 && $r['document_status'] === 'Pending'): ?>
    <a class="btn btn-sm btn-warning" href="<?= h(app_url('add_file.php?id=' . (int)$r['document_id'])) ?>">Add file</a>
  <?php endif; ?>
  <?php if ($r['document_status'] !== 'Complete'): ?>
  <form method="post" action="<?= h(app_url('delete.php')) ?>" class="d-inline" onsubmit="return confirm('Delete this document?')">
    <?= csrf_field() ?><input type="hidden" name="document_id" value="<?= (int)$r['document_id'] ?>"><button class="btn btn-sm btn-ghost text-danger">Delete</button></form>
  <?php endif; ?></td></tr>
<?php endforeach; if (!$docs): ?><tr><td colspan="5" class="empty">You have not uploaded any documents yet. Use &ldquo;Upload New Document&rdquo;.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php page_footer();
