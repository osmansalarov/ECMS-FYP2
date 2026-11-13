<?php
declare(strict_types=1);
/** DB-free unit tests for upload/password validators.  Run: php tests/unit_tests.php */
require_once __DIR__ . '/../include/validators.php';

$pass = 0; $fail = 0;
function check(string $name, bool $cond): void {
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    $cond ? $pass++ : $fail++;
}
function tmpfile_with(string $bytes): string {
    $p = tempnam(sys_get_temp_dir(), 'ecms');
    file_put_contents($p, $bytes);
    return $p;
}
function make_docx(): string {
    $p = tempnam(sys_get_temp_dir(), 'ecmsz') . '.zip';
    $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<Types/>'); $z->addFromString('word/document.xml', '<w/>'); $z->close();
    return $p;
}

$pdf = tmpfile_with("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
[$ok] = ecms_validate_upload('report.pdf', $pdf, filesize($pdf));
check('TC-U01 valid PDF accepted', $ok);

[$ok] = ecms_validate_upload('shell.php', $pdf, filesize($pdf));
check('TC-U02 .php extension rejected', !$ok);

[$ok] = ecms_validate_upload('shell.php.pdf', $pdf, filesize($pdf));
check('TC-U03 double extension shell.php.pdf rejected', !$ok);

$fake = tmpfile_with("<?php system(\$_GET['c']); ?>");
[$ok] = ecms_validate_upload('evil.pdf', $fake, filesize($fake));
check('TC-U04 PHP code renamed to .pdf rejected (MIME/signature)', !$ok);

$exe = tmpfile_with("MZ\x90\x00" . str_repeat("A", 100));
[$ok] = ecms_validate_upload('setup.docx', $exe, filesize($exe));
check('TC-U05 EXE renamed to .docx rejected', !$ok);

[$ok] = ecms_validate_upload('big.pdf', $pdf, ECMS_MAX_UPLOAD_BYTES + 1);
check('TC-U06 file over 5 MB rejected', !$ok);

$empty = tmpfile_with('');
[$ok] = ecms_validate_upload('empty.pdf', $empty, 0);
check('TC-U07 empty file rejected', !$ok);

$docx = make_docx();
[$ok, $msg] = ecms_validate_upload('lecture.docx', $docx, filesize($docx));
check('TC-U08 valid DOCX accepted (' . $msg . ')', $ok);

$badzip = tmpfile_with("PK\x03\x04" . str_repeat("\0", 64));
[$ok] = ecms_validate_upload('fake.docx', $badzip, filesize($badzip));
check('TC-U09 fake DOCX (bad zip structure) rejected', !$ok);

[$ok] = ecms_validate_upload('notes.txt', $pdf, filesize($pdf));
check('TC-U10 .txt extension rejected', !$ok);

check('TC-U11 short password rejected', ecms_password_error('abc12') !== null);
check('TC-U12 password without digit rejected', ecms_password_error('abcdefgh') !== null);
check('TC-U13 strong password accepted', ecms_password_error('Secure123') === null);
check('TC-U14 download name strips path traversal', ecms_safe_download_name('../../etc/passwd') === 'passwd');
check('TC-U15 download name strips quotes/CRLF', strpbrk(ecms_safe_download_name("a\"b\r\nc.pdf"), "\"\r\n") === false);

echo PHP_EOL . "Passed: $pass  Failed: $fail" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
