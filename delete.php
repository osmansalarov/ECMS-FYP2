<?php
declare(strict_types=1);
/** Delete a document. Admin: any. Employee: own documents that are not yet 'Complete'. POST + CSRF only. */
require_once __DIR__ . '/common.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(home_path());
}
csrf_verify();
$id  = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
$doc = $id ? db_one($conn, 'SELECT document_id, uploaded_by, original_filename, stored_filename, document_status FROM documents WHERE document_id = ?', 'i', [$id]) : null;
$uid = (int)$_SESSION['user_id'];

if (!$doc) {
    flash_set('danger', 'Document not found.');
    redirect(home_path());
}
if (!is_admin() && ((int)$doc['uploaded_by'] !== $uid || $doc['document_status'] === 'Complete')) {
    log_activity($conn, $uid, 'Access Denied', 'DOC-' . $id, 'Attempted an unauthorised delete');
    http_response_code(403);
    exit('Access denied.');
}
$extra = db_all($conn, 'SELECT stored_filename FROM document_attachments WHERE document_id = ?', 'i', [$id]);
db_query($conn, 'DELETE FROM documents WHERE document_id = ?', 'i', [$id])->close(); // verification_records and attachments cascade
foreach ($extra as $x) {
    $xp = __DIR__ . '/uploads/' . basename($x['stored_filename']);
    if (is_file($xp)) {
        unlink($xp);
    }
}
$path = __DIR__ . '/uploads/' . basename($doc['stored_filename']);
if (is_file($path)) {
    unlink($path);
}
log_activity($conn, $uid, 'Delete Document', 'DOC-' . $id, 'Deleted "' . $doc['original_filename'] . '"');
flash_set('success', 'Document deleted.');
redirect(home_path());
