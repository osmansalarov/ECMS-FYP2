<?php
declare(strict_types=1);
/**
 * Employee adds an ADDITIONAL file to one of their own documents when the reviewer asked for more
 * (document is Pending with needs_more = 1). Existing files can never be replaced or removed here.
 */
require_once __DIR__ . '/common.php';
require_login('Employee');

$uid = (int)$_SESSION['user_id'];
$id  = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT);
$doc = $id ? db_one($conn, 'SELECT document_id, uploaded_by, title, original_filename, document_status, needs_more FROM documents WHERE document_id = ?', 'i', [$id]) : null;

if (!$doc || (int)$doc['uploaded_by'] !== $uid) {
    if ($doc) {
        log_activity($conn, $uid, 'Access Denied', 'DOC-' . $id, 'Attempted to add a file to another user\'s document');
    }
    http_response_code($doc ? 403 : 404);
    exit($doc ? 'Access denied.' : 'Document not found.');
}
$open = (int)$doc['needs_more'] === 1 && $doc['document_status'] === 'Pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$open) {
        flash_set('warning', 'No additional documents are currently requested for this document.');
        redirect('add_file.php?id=' . (int)$id);
    }
    $res = ecms_process_upload($_FILES['myfile'] ?? null);
    if (!$res['ok']) {
        if ($res['original'] !== '') {
            log_activity($conn, $uid, 'Upload Rejected', 'DOC-' . $id, 'Rejected "' . substr($res['original'], 0, 100) . '": ' . $res['msg']);
        }
        flash_set('danger', $res['msg']);
        redirect('add_file.php?id=' . (int)$id);
    }
    db_query($conn, 'INSERT INTO document_attachments(document_id, uploaded_by, original_filename, stored_filename, file_type, file_size) VALUES (?,?,?,?,?,?)',
        'iisssi', [$id, $uid, $res['original'], $res['stored'], $res['mime'], $res['size']])->close();
    log_activity($conn, $uid, 'Add Attachment', 'DOC-' . $id, 'Added "' . $res['original'] . '" as an additional file');
    flash_set('success', 'Additional file added. The reviewer will check it.');
    redirect('add_file.php?id=' . (int)$id);
}

$last  = db_one($conn, 'SELECT comment FROM verification_records WHERE document_id = ? ORDER BY verification_id DESC LIMIT 1', 'i', [$id]);
$files = db_all($conn, 'SELECT attachment_id, original_filename, file_size, uploaded_at FROM document_attachments WHERE document_id = ? ORDER BY attachment_id', 'i', [$id]);

page_header('Add Additional File');
page_title('Add Additional File', home_path(), 'Back to Dashboard', (string)($doc['title'] ?: $doc['original_filename']));
?>
<div class="grid-main">
  <div>
    <?php if ($open): ?>
    <div class="banner warn"><div><b>The reviewer asked for more documents.</b><?php if (!empty($last['comment'])): ?><br><?= h($last['comment']) ?><?php endif; ?></div></div>
    <div class="card"><div class="card-head"><h2>Add a file</h2></div><div class="card-body">
      <form method="POST" enctype="multipart/form-data" action="add_file.php?id=<?= (int)$id ?>">
        <?= csrf_field() ?><input type="hidden" name="MAX_FILE_SIZE" value="<?= ECMS_MAX_UPLOAD_BYTES ?>">
        <label class="dropzone" id="dz"><?= icon('cloud', 40) ?><b>Drag and drop file or click to browse</b><span>PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, ODT &middot; max 5 MB</span>
          <input type="file" name="myfile" required accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.odt"><span class="picked"></span></label>
        <div class="text-right mt-3"><button class="btn btn-primary btn-lg-cta">Add file</button></div>
      </form></div></div>
    <?php else: ?>
    <div class="banner info"><div>No additional documents are requested for this document right now.</div></div>
    <?php endif; ?>
  </div>
  <div class="card"><div class="card-head"><h2>Files on this document</h2></div><div class="card-body">
    <p class="hint">Existing files cannot be removed or replaced; you can only add more.</p>
    <ul class="list-unstyled mb-0">
      <li class="mb-2"><?= icon('file', 16) ?> <?= h($doc['original_filename']) ?> <span class="pill grey">Original</span>
        <a class="ml-2" href="<?= h(app_url('download.php?id=' . (int)$id)) ?>">Download</a></li>
      <?php foreach ($files as $f): ?>
      <li class="mb-2"><?= icon('file', 16) ?> <?= h($f['original_filename']) ?> <span class="pill info">Added</span>
        <a class="ml-2" href="<?= h(app_url('download.php?att=' . (int)$f['attachment_id'])) ?>">Download</a></li>
      <?php endforeach; ?>
    </ul></div></div>
</div>
<?php require __DIR__ . '/include/dropzone_js.php'; page_footer();
