<?php
declare(strict_types=1);
/**
 * Authorised download. Counted and logged.
 *  ?id=N     submitted document : Admin = any, Employee = own only
 *  ?att=N    additional file    : Admin = any, Employee = own document only
 *  ?share=N  shared document    : Admin = any, Employee = listed recipients only (marks it as read)
 */
require_once __DIR__ . '/common.php';
require_login();

$uid  = (int)$_SESSION['user_id'];
$file = null;   // [original, stored, log action, target, counter closure]

if (isset($_GET['share'])) {
    $sid = filter_var($_GET['share'], FILTER_VALIDATE_INT);
    $row = $sid ? db_one($conn, 'SELECT share_id, original_filename, stored_filename FROM shared_documents WHERE share_id = ?', 'i', [$sid]) : null;
    if (!$row) { http_response_code(404); exit('Document not found.'); }
    $isRecipient = $sid && db_one($conn, 'SELECT 1 x FROM shared_recipients WHERE share_id = ? AND user_id = ?', 'ii', [$sid, $uid]);
    if (!is_admin() && !$isRecipient) {
        log_activity($conn, $uid, 'Access Denied', 'SHARE-' . $sid, 'Attempted to download a document not sent to this user');
        http_response_code(403);
        exit('Access denied.');
    }
    $file = [$row['original_filename'], $row['stored_filename'], 'Download Shared', 'SHARE-' . $sid, static function (mysqli $c) use ($sid, $uid): void {
        db_query($c, 'UPDATE shared_documents SET download_count = download_count + 1 WHERE share_id = ?', 'i', [$sid])->close();
        db_query($c, 'UPDATE shared_recipients SET read_at = COALESCE(read_at, NOW()) WHERE share_id = ? AND user_id = ?', 'ii', [$sid, $uid])->close();
    }];
} elseif (isset($_GET['att'])) {
    $aid = filter_var($_GET['att'], FILTER_VALIDATE_INT);
    $row = $aid ? db_one($conn, 'SELECT a.attachment_id, a.document_id, a.original_filename, a.stored_filename, d.uploaded_by FROM document_attachments a JOIN documents d ON d.document_id = a.document_id WHERE a.attachment_id = ?', 'i', [$aid]) : null;
    if (!$row) { http_response_code(404); exit('File not found.'); }
    if (!is_admin() && (int)$row['uploaded_by'] !== $uid) {
        log_activity($conn, $uid, 'Access Denied', 'DOC-' . $row['document_id'], 'Attempted to download another user\'s additional file');
        http_response_code(403);
        exit('Access denied.');
    }
    $file = [$row['original_filename'], $row['stored_filename'], 'Download Document', 'DOC-' . $row['document_id'], static function (mysqli $c): void {}];
} else {
    $id  = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $doc = $id ? db_one($conn, 'SELECT document_id, uploaded_by, original_filename, stored_filename FROM documents WHERE document_id = ?', 'i', [$id]) : null;
    if (!$doc) { http_response_code(404); exit('Document not found.'); }
    if (!is_admin() && (int)$doc['uploaded_by'] !== $uid) {
        log_activity($conn, $uid, 'Access Denied', 'DOC-' . $id, 'Attempted to download another user\'s document');
        http_response_code(403);
        exit('Access denied.');
    }
    $file = [$doc['original_filename'], $doc['stored_filename'], 'Download Document', 'DOC-' . $id, static function (mysqli $c) use ($id): void {
        db_query($c, 'UPDATE documents SET download_count = download_count + 1 WHERE document_id = ?', 'i', [$id])->close();
    }];
}

[$original, $stored, $action, $target, $count] = $file;
$path = __DIR__ . '/uploads/' . basename($stored);
if (!is_file($path)) {
    http_response_code(404);
    exit('Stored file not found.');
}
$count($conn);
log_activity($conn, $uid, $action, $target, 'Downloaded "' . $original . '"');

$name = ecms_safe_download_name($original);
session_write_close();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
