<?php
declare(strict_types=1);
/** Employee upload page (Upload Course Document). Every upload starts as 'Pending'. Admins use Send Documents instead. */
require_once __DIR__ . '/common.php';
require_login();
if (is_admin()) {
    redirect('Private_Dashboard/send_documents.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $uid      = (int)$_SESSION['user_id'];
    $title    = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    $category = (string)($_POST['category'] ?? '');

    if (mb_strlen($title) < 3) {
        flash_set('danger', 'Please enter a document title (at least 3 characters).');
        redirect('upload.php');
    }
    if (!in_array($category, ECMS_CATEGORIES, true)) {
        flash_set('danger', 'Please select a course category.');
        redirect('upload.php');
    }
    $res = ecms_process_upload($_FILES['myfile'] ?? null);
    if (!$res['ok']) {
        if ($res['original'] !== '') {
            log_activity($conn, $uid, 'Upload Rejected', 'System', 'Rejected "' . substr($res['original'], 0, 100) . '": ' . $res['msg']);
        }
        flash_set('danger', $res['msg']);
        redirect('upload.php');
    }
    $stmt = db_query($conn,
        "INSERT INTO documents(uploaded_by, title, category, original_filename, stored_filename, file_type, file_size, document_status) VALUES (?,?,?,?,?,?,?,'Pending')",
        'isssssi', [$uid, $title, $category, $res['original'], $res['stored'], $res['mime'], $res['size']]);
    $docId = (int)$conn->insert_id;
    $stmt->close();
    log_activity($conn, $uid, 'Upload Document', 'DOC-' . $docId, 'Uploaded "' . $res['original'] . '"; status set to Pending');
    flash_set('success', 'Document uploaded successfully. Status: Pending verification.');
    redirect(home_path());
}

page_header('Upload Course Document');
page_title('Upload Course Document', home_path(), 'Back to Dashboard');
?>
<div class="grid-main">
  <div class="card"><div class="card-head"><h2><?= icon('file', 18) ?> Document Details</h2></div><div class="card-body">
    <form method="POST" enctype="multipart/form-data" action="upload.php">
      <?= csrf_field() ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= ECMS_MAX_UPLOAD_BYTES ?>">
      <div class="form-group"><label class="fl" for="title">Document title</label>
        <input class="form-control" id="title" name="title" maxlength="150" required minlength="3" placeholder="e.g., Computer Science 101 Lecture Notes"></div>
      <div class="form-group"><label class="fl" for="category">Course category</label>
        <select class="form-control" id="category" name="category" required><option value="">Select a category</option>
          <?php foreach (ECMS_CATEGORIES as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><span class="fl">File attachment</span>
        <label class="dropzone" id="dz"><?= icon('cloud', 40) ?><b>Drag and drop file or click to browse</b><span>Click here to upload your course materials</span>
          <input type="file" name="myfile" required accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.odt"><span class="picked"></span></label></div>
      <div class="d-flex align-items-center justify-content-between flex-wrap mt-4">
        <span><span class="pill grey">Pending</span> <span class="hint">Status will be set upon submission</span></span>
        <button class="btn btn-primary btn-lg-cta">Upload</button></div>
    </form></div></div>
  <div>
    <div class="card mb-3"><div class="card-head"><h2 style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">Specifications</h2></div><div class="card-body">
      <ul class="spec"><li><?= icon('check', 20) ?><div><b>Allowed file types</b><br><small class="text-muted">PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, ODT</small></div></li>
      <li><?= icon('check', 20) ?><div><b>Maximum file size</b><br><small class="text-muted">5 MB per document</small></div></li></ul></div></div>
    <div class="note"><h3><?= icon('shield', 20) ?> Security Note</h3>
      <p class="mb-0 small">Every file is checked before it is stored: extension, content type, file signature and document structure. Files are stored under a random name and can only be downloaded by you and the administrators.</p></div>
  </div>
</div>
<?php require __DIR__ . '/include/dropzone_js.php'; page_footer();
