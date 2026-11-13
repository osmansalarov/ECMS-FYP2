<?php
declare(strict_types=1);
/**
 * ECMS pure validation helpers (no database, no session) so they can be unit tested.
 */

const ECMS_MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

/** Course-file categories offered on the upload form. */
const ECMS_CATEGORIES = ['Course Outline', 'Lecture Notes', 'Assessment', 'Examination Paper', 'Student Results', 'Teaching Material', 'Other'];

/** Allowed extensions => acceptable detected MIME types (finfo). */
function ecms_allowed_types(): array
{
    return [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    ];
}

/** Check magic bytes (first 8 bytes) against the claimed extension. */
function ecms_valid_signature(string $ext, string $head): bool
{
    if ($ext === 'pdf') {
        return strncmp($head, '%PDF', 4) === 0;
    }
    if (in_array($ext, ['docx', 'xlsx', 'pptx', 'odt'], true)) {
        return strncmp($head, "PK\x03\x04", 4) === 0;
    }
    if (in_array($ext, ['doc', 'xls', 'ppt'], true)) {
        return strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0;
    }
    return false;
}

/** For ZIP-based formats, confirm the archive really contains the expected Office/ODF part. */
function ecms_valid_zip_structure(string $ext, string $path): bool
{
    if (!in_array($ext, ['docx', 'xlsx', 'pptx', 'odt'], true)) {
        return true;
    }
    if (!class_exists('ZipArchive')) {
        return true; // signature check already applied; structure check unavailable
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $ok = false;
    if ($ext === 'odt') {
        $ok = $zip->locateName('mimetype') !== false;
    } else {
        $dir = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$ext];
        $ok = $zip->locateName('[Content_Types].xml') !== false;
        if ($ok) {
            $found = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (strncmp((string)$zip->getNameIndex($i), $dir, strlen($dir)) === 0) {
                    $found = true;
                    break;
                }
            }
            $ok = $found;
        }
    }
    $zip->close();
    return $ok;
}

/**
 * Validate an uploaded file. Returns [bool ok, string message, array info].
 * info = ['ext'=>..., 'mime'=>..., 'size'=>...] when ok.
 */
function ecms_validate_upload(string $originalName, string $tmpPath, int $size): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ecms_allowed_types();

    if ($originalName === '' || strlen($originalName) > 200) {
        return [false, 'Invalid file name.', []];
    }
    // Block double extensions such as shell.php.pdf
    if (preg_match('/\.(php\d?|phtml|phar|exe|js|sh|bat|cmd|html?|htaccess)(\.|$)/i', $originalName)) {
        return [false, 'File name contains a blocked extension.', []];
    }
    if (!isset($allowed[$ext])) {
        return [false, 'Unsupported file extension. Allowed: ' . implode(', ', array_keys($allowed)) . '.', []];
    }
    if ($size <= 0) {
        return [false, 'File is empty.', []];
    }
    if ($size > ECMS_MAX_UPLOAD_BYTES) {
        return [false, 'File too large. Maximum size is 5 MB.', []];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpPath);
    if (!in_array($mime, $allowed[$ext], true)) {
        return [false, 'File content type does not match its extension.', []];
    }
    $head = (string)file_get_contents($tmpPath, false, null, 0, 8);
    if (!ecms_valid_signature($ext, $head)) {
        return [false, 'Invalid file signature. File content does not match its extension.', []];
    }
    if (!ecms_valid_zip_structure($ext, $tmpPath)) {
        return [false, 'Document structure is invalid or corrupted.', []];
    }
    return [true, 'OK', ['ext' => $ext, 'mime' => $mime, 'size' => $size]];
}

/** Password policy: min 8 chars, at least one letter and one digit. Returns error string or null. */
function ecms_password_error(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one digit.';
    }
    if (strlen($password) > 72) {
        return 'Password must be at most 72 characters.';
    }
    return null;
}

/** Make a safe download filename (no path, quotes or control chars). */
function ecms_safe_download_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name) ?? '';
    return $name === '' ? 'document' : $name;
}
