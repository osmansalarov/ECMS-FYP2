<?php
declare(strict_types=1);
/**
 * Document Verification (Review Submitted Documents).
 * Left: queue. Right: Review Details. Decisions: Approve (Complete), Reject (Incomplete),
 * Request more documents (stays Pending, flags "additional document required").
 */
require_once __DIR__ . '/../common.php';
require_login('Admin');

$allowed = ['Pending', 'Complete', 'Incomplete', 'All'];
$status  = in_array($_GET['status'] ?? 'Pending', $allowed, true) ? (string)($_GET['status'] ?? 'Pending') : 'Pending';
$selId   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$self    = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $docId    = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = (string)($_POST['decision'] ?? '');
    $comment  = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 1000);
    $doc      = $docId ? db_one($conn, 'SELECT document_id, uploaded_by FROM documents WHERE document_id = ?', 'i', [$docId]) : null;
    $back     = 'Private_Dashboard/verify_documents.php?status=' . urlencode($status);

    if (!$doc || !in_array($decision, ['Complete', 'Incomplete', 'Pending'], true)) {
        flash_set('danger', 'Invalid verification request.');
    } elseif ((int)$doc['uploaded_by'] === $self) {
        log_activity($conn, $self, 'Access Denied', 'DOC-' . $docId, 'Attempted to verify own document');
        flash_set('danger', 'You cannot verify a document that you uploaded yourself.');
    } elseif ($decision !== 'Complete' && $comment === '') {
        flash_set('warning', $decision === 'Pending' ? 'Describe which additional documents are required.' : 'Please add a reviewer note explaining why the document is incomplete.');
        $back .= '&id=' . (int)$docId;
    } else {
        $newStatus = $decision === 'Pending' ? 'Pending' : $decision;
        $needsMore = $decision === 'Pending' ? 1 : 0;
        $conn->begin_transaction();
        try {
            db_query($conn, 'UPDATE documents SET document_status = ?, needs_more = ? WHERE document_id = ?', 'sii', [$newStatus, $needsMore, $docId])->close();
            db_query($conn, 'INSERT INTO verification_records(document_id, reviewed_by, status_decision, comment) VALUES (?,?,?,?)',
                'iiss', [$docId, $self, $decision, $comment])->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        if ($decision === 'Pending') {
            log_activity($conn, $self, 'Request Documents', 'DOC-' . $docId, 'Requested additional documents: ' . mb_substr($comment, 0, 200));
            flash_set('success', 'DOC-' . $docId . ' stays Pending. The employee was notified to add the requested files.');
        } else {
            log_activity($conn, $self, 'Update Document Status', 'DOC-' . $docId, 'Marked document as ' . $decision);
            flash_set('success', 'DOC-' . $docId . ' marked as ' . $decision . '.');
        }
        $back .= '&id=' . (int)$docId;
    }
    header('Location: ' . app_url($back));
    exit;
}

$sql = 'SELECT d.document_id, d.title, d.category, d.original_filename, d.file_type, d.file_size, d.document_status, d.needs_more, d.uploaded_at, d.uploaded_by, d.download_count,
               u.full_name, u.email,
               (SELECT v.comment FROM verification_records v WHERE v.document_id = d.document_id ORDER BY v.verification_id DESC LIMIT 1) AS last_comment,
               (SELECT COUNT(*) FROM document_attachments a WHERE a.document_id = d.document_id) AS extra_files
          FROM documents d JOIN users u ON d.uploaded_by = u.user_id'
     . ($status !== 'All' ? ' WHERE d.document_status = ?' : '') . ' ORDER BY d.uploaded_at DESC';
$docs = $status !== 'All' ? db_all($conn, $sql, 's', [$status]) : db_all($conn, $sql);

if (($_GET['export'] ?? '') === '1') {
    log_activity($conn, $self, 'Export', 'Verification report', 'Exported verification report (' . $status . ')');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="verification_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Document ID', 'Title', 'Category', 'Filename', 'Uploaded by', 'Email', 'Uploaded at', 'Status', 'Additional required', 'Downloads', 'Last reviewer note']);
    $safe = static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    foreach ($docs as $d) {
        fputcsv($out, array_map($safe, ['DOC-' . $d['document_id'], $d['title'], $d['category'], $d['original_filename'], $d['full_name'], $d['email'],
            $d['uploaded_at'], $d['document_status'], $d['needs_more'] ? 'Yes' : 'No', $d['download_count'], $d['last_comment']]));
    }
    exit;
}

$sel = null;
foreach ($docs as $d) {
    if ((int)$d['document_id'] === $selId) { $sel = $d; break; }
}
if ($sel === null && $docs) {
    $sel = $docs[0];
}
$pendingTotal = (int)db_one($conn, "SELECT COUNT(*) n FROM documents WHERE document_status = 'Pending'")['n'];
$attach = $sel ? db_all($conn, 'SELECT attachment_id, original_filename, file_size, uploaded_at FROM document_attachments WHERE document_id = ? ORDER BY attachment_id', 'i', [(int)$sel['document_id']]) : [];

$actions = '<a class="btn btn-ghost btn-sm" href="?status=' . h($status) . '&amp;export=1">' . icon('download', 16) . ' Export Report</a>';
page_header('Review Submitted Documents');
page_title('Review Submitted Documents', 'Private_Dashboard/dashboard.php', 'Back to Dashboard', 'Review and authorize submitted course documents.', $actions);
?>
<div class="chips mb-3">
<?php foreach (['Pending', 'Complete', 'Incomplete', 'All'] as $f): ?>
  <a class="chip <?= $status === $f ? 'on' : '' ?>" href="?status=<?= h($f) ?>"><?= $f === 'All' ? 'All documents' : h($f) ?></a>
<?php endforeach; ?>
</div>
<div class="grid-main">
  <div class="card"><div class="card-head"><h2><?= $status === 'All' ? 'All Documents' : h($status) . ' Documents' ?></h2><span class="pill info"><?= count($docs) ?> shown &middot; <?= $pendingTotal ?> pending</span></div>
  <div class="table-responsive"><table class="tbl"><thead><tr><th>ID</th><th>Uploaded by</th><th>Document</th><th>Date</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($docs as $d): $on = $sel && (int)$sel['document_id'] === (int)$d['document_id']; $href = '?status=' . h($status) . '&amp;id=' . (int)$d['document_id']; ?>
    <tr class="<?= $on ? 'sel' : '' ?>"><td><a href="<?= $href ?>">#DOC-<?= (int)$d['document_id'] ?></a></td>
      <td><b><?= h($d['full_name']) ?></b><br><small><?= h($d['email']) ?></small></td>
      <td><a href="<?= $href ?>"><?= h($d['title'] ?: $d['original_filename']) ?></a><br><small><?= h($d['category'] ?: $d['original_filename']) ?><?= (int)$d['extra_files'] > 0 ? ' &middot; +' . (int)$d['extra_files'] . ' file(s)' : '' ?></small></td>
      <td class="text-nowrap"><small><?= h(date('d M Y', strtotime((string)$d['uploaded_at']))) ?></small></td>
      <td><?= status_badge($d['document_status']) ?><?= (int)$d['needs_more'] === 1 ? '<br><small class="text-warning">More files requested</small>' : '' ?></td></tr>
  <?php endforeach; if (!$docs): ?><tr><td colspan="5" class="empty">No documents in this view.</td></tr><?php endif; ?>
  </tbody></table></div></div>

  <div>
  <?php if ($sel): $mine = (int)$sel['uploaded_by'] === $self; ?>
    <div class="card mb-3"><div class="card-head"><h2>Review Details</h2><span class="hint">#DOC-<?= (int)$sel['document_id'] ?></span></div><div class="card-body">
      <span class="fl">Verification status</span>
      <div class="status-box mb-3"><?= $sel['document_status'] === 'Pending' ? icon('clock', 16) . ' In Review' : status_badge($sel['document_status']) ?><?= (int)$sel['needs_more'] === 1 ? '<br><small class="text-warning">Additional document required</small>' : '' ?></div>
      <?php if ($sel['last_comment']): ?><p class="small"><b>Last note:</b> <?= h($sel['last_comment']) ?></p><?php endif; ?>
      <?php if ($mine): ?>
        <p class="hint">This is your own upload. Another administrator must verify it.</p>
      <?php else: ?>
      <form method="post" action="?status=<?= h($status) ?>&amp;id=<?= (int)$sel['document_id'] ?>">
        <?= csrf_field() ?><input type="hidden" name="document_id" value="<?= (int)$sel['document_id'] ?>">
        <label class="fl" for="comment">Reviewer notes</label>
        <textarea id="comment" name="comment" rows="4" maxlength="1000" class="form-control mb-3" placeholder="Comments on accuracy, required edits or which additional documents are needed..."></textarea>
        <div class="d-flex" style="gap:8px"><button name="decision" value="Incomplete" class="btn btn-danger flex-fill">Reject</button>
          <button name="decision" value="Complete" class="btn btn-success flex-fill">Approve</button></div>
        <button name="decision" value="Pending" class="btn btn-warning btn-block mt-2">Keep Pending &ndash; request more documents</button>
        <p class="hint mt-2 mb-0">Reject and Keep Pending require a note. The employee sees your note.</p>
      </form>
      <?php endif; ?></div></div>

    <div class="card"><div class="card-head"><h2>Document Meta</h2></div><div class="card-body">
      <dl class="meta mb-3">
        <dt>Title</dt><dd><?= h($sel['title'] ?: '-') ?></dd>
        <dt>Category</dt><dd><?= h($sel['category'] ?: '-') ?></dd>
        <dt>File format</dt><dd><?= h(strtoupper(pathinfo($sel['original_filename'], PATHINFO_EXTENSION))) ?> <small class="text-muted">(<?= h($sel['file_type']) ?>)</small></dd>
        <dt>Size</dt><dd><?= h(format_size((int)$sel['file_size'])) ?></dd>
        <dt>Uploaded</dt><dd><?= h($sel['uploaded_at']) ?> &middot; <?= (int)$sel['download_count'] ?> download(s)</dd>
      </dl>
      <a class="btn btn-primary btn-sm" href="<?= h(app_url('download.php?id=' . (int)$sel['document_id'])) ?>"><?= icon('download', 14) ?> <?= h($sel['original_filename']) ?></a>
      <?php foreach ($attach as $a): ?><br><a class="btn btn-ghost btn-sm mt-2" href="<?= h(app_url('download.php?att=' . (int)$a['attachment_id'])) ?>"><?= icon('file', 14) ?> <?= h($a['original_filename']) ?> <span class="pill info">Added</span></a><?php endforeach; ?>
    </div></div>
  <?php else: ?><div class="card"><div class="card-body empty">Select a document to review.</div></div><?php endif; ?>
  </div>
</div>
<?php page_footer();
