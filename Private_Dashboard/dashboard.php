<?php
declare(strict_types=1);
/** Admin Dashboard Overview: live counts, recent activity and the review queue (all data from the database). */
require_once __DIR__ . '/../common.php';
require_login('Admin');

$counts = ['Pending' => 0, 'Complete' => 0, 'Incomplete' => 0];
foreach (db_all($conn, 'SELECT document_status s, COUNT(*) c FROM documents GROUP BY document_status') as $r) {
    $counts[$r['s']] = (int)$r['c'];
}
$totalDocs  = array_sum($counts);
$totalUsers = (int)db_one($conn, 'SELECT COUNT(*) c FROM users')['c'];
$logs = db_all($conn, 'SELECT l.action_type, l.target_id, l.description, l.created_at, u.full_name
                         FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id ORDER BY l.log_id DESC LIMIT 6');
$queue = db_all($conn, "SELECT d.document_id, COALESCE(NULLIF(d.title,''), d.original_filename) AS name, d.category, d.needs_more, u.full_name,
                                (SELECT COUNT(*) FROM document_attachments a WHERE a.document_id = d.document_id) AS extra_files
                          FROM documents d JOIN users u ON d.uploaded_by = u.user_id
                         WHERE d.document_status = 'Pending' ORDER BY d.uploaded_at ASC LIMIT 5");

page_header('Admin Dashboard');
page_title('Admin Dashboard Overview', '', '', 'System activity and document verification status.');
$cards = [
  ['Total Users',     $totalUsers,           'users',  'users.php',                               ''],
  ['Total Documents', $totalDocs,            'file',   'verify_documents.php?status=All',         ''],
  ['Pending Review',  $counts['Pending'],    'clock',  'verify_documents.php?status=Pending',     'warn'],
  ['Completed',       $counts['Complete'],   'check',  'verify_documents.php?status=Complete',    'ok'],
  ['Incomplete',      $counts['Incomplete'], 'alert',  'verify_documents.php?status=Incomplete',  'bad'],
];
?>
<div class="stats">
<?php foreach ($cards as [$label, $n, $ico, $href, $cls]): ?>
  <a class="stat <?= h($cls) ?>" href="<?= h($href) ?>"><div class="top"><span class="lbl"><?= h($label) ?></span><?= icon($ico, 22) ?></div><div class="num"><?= (int)$n ?></div></a>
<?php endforeach; ?>
</div>

<div class="grid-2">
  <div class="card"><div class="card-head"><h2>Recent Activity Log</h2><a class="link-sm" href="activity_logs.php">View All</a></div>
    <div class="table-responsive"><table class="tbl"><thead><tr><th>Activity</th><th>When</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($logs as $r): $tone = log_tone($r['action_type']); ?>
      <tr><td><?= h(log_sentence($r)) ?></td><td class="text-nowrap"><small><?= h(date('d M, H:i', strtotime((string)$r['created_at']))) ?></small></td>
          <td><span class="pill <?= h($tone) ?>"><?= $tone === 'alert' ? 'Alert' : ($tone === 'ok' ? 'Success' : ($tone === 'warn' ? 'Notice' : 'Info')) ?></span></td></tr>
    <?php endforeach; if (!$logs): ?><tr><td colspan="3" class="empty">No activity recorded yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>

  <div class="card"><div class="card-head"><h2>Pending Documents Review</h2><a class="link-sm" href="verify_documents.php?status=Pending">Open queue</a></div>
    <div class="table-responsive"><table class="tbl"><thead><tr><th>ID</th><th>Document</th><th>Uploaded by</th><th></th></tr></thead><tbody>
    <?php foreach ($queue as $r): ?>
      <tr><td class="text-nowrap">#DOC-<?= (int)$r['document_id'] ?></td>
          <td><?= h($r['name']) ?><?= (int)$r['needs_more'] === 1 ? '<br><small class="text-warning">Awaiting additional files</small>' : ((int)$r['extra_files'] > 0 ? '<br><small class="text-success">Files added &ndash; review</small>' : '') ?></td>
          <td><?= h($r['full_name']) ?></td>
          <td class="text-right"><a class="btn btn-sm btn-primary" href="verify_documents.php?status=Pending&amp;id=<?= (int)$r['document_id'] ?>">Review</a></td></tr>
    <?php endforeach; if (!$queue): ?><tr><td colspan="4" class="empty">Nothing is waiting for review.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="foot-row"><span>Showing <?= count($queue) ?> of <?= (int)$counts['Pending'] ?> pending</span></div></div>
</div>
<?php page_footer();
