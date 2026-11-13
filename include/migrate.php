<?php
declare(strict_types=1);
/**
 * Idempotent schema upgrade (FYP2 additions). Safe to run repeatedly; runs once per session.
 * Adds: document title/category, "additional documents required" flag, 'Pending' review decision,
 * additional attachments, and admin-to-employee document sharing (Inbox).
 */
function ecms_column_exists(mysqli $c, string $table, string $column): bool
{
    $stmt = $c->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    return (int)$n > 0;
}

function ecms_migrate(mysqli $c): void
{
    if (!ecms_column_exists($c, 'documents', 'title')) {
        $c->query('ALTER TABLE documents ADD COLUMN title VARCHAR(150) NULL AFTER uploaded_by');
    }
    if (!ecms_column_exists($c, 'documents', 'category')) {
        $c->query('ALTER TABLE documents ADD COLUMN category VARCHAR(80) NULL AFTER title');
    }
    if (!ecms_column_exists($c, 'documents', 'needs_more')) {
        $c->query('ALTER TABLE documents ADD COLUMN needs_more TINYINT(1) NOT NULL DEFAULT 0');
    }
    // Backfill: every document gets a title (file name without extension) and a category.
    $c->query("UPDATE documents SET title = IF(LOCATE('.', original_filename) > 0,
        LEFT(original_filename, CHAR_LENGTH(original_filename) - CHAR_LENGTH(SUBSTRING_INDEX(original_filename, '.', -1)) - 1), original_filename)
        WHERE title IS NULL OR title = ''");
    $c->query("UPDATE documents SET category = 'Other' WHERE category IS NULL OR category = ''");
    $res = $c->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'verification_records' AND COLUMN_NAME = 'status_decision'");
    $row = $res ? $res->fetch_row() : null;
    if ($row && stripos((string)$row[0], 'Pending') === false) {
        $c->query("ALTER TABLE verification_records MODIFY status_decision ENUM('Complete','Incomplete','Pending') NOT NULL");
    }
    $c->query('CREATE TABLE IF NOT EXISTS document_attachments (
        attachment_id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        uploaded_by INT NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
    ) ENGINE=InnoDB');
    $c->query('CREATE TABLE IF NOT EXISTS shared_documents (
        share_id INT AUTO_INCREMENT PRIMARY KEY,
        sent_by INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        message TEXT,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL,
        download_count INT NOT NULL DEFAULT 0,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sent_by) REFERENCES users(user_id)
    ) ENGINE=InnoDB');
    $c->query('CREATE TABLE IF NOT EXISTS shared_recipients (
        share_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at DATETIME NULL,
        PRIMARY KEY (share_id, user_id),
        FOREIGN KEY (share_id) REFERENCES shared_documents(share_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB');
    // One-time clean-up of tiny test documents left from development (runs once per database).
    $c->query('CREATE TABLE IF NOT EXISTS ecms_meta (k VARCHAR(50) PRIMARY KEY) ENGINE=InnoDB');
    $c->query("INSERT IGNORE INTO ecms_meta(k) VALUES ('cleanup_v101')");
    if ($c->affected_rows === 1) {
        ecms_remove_broken_documents($c);
    }
}

/** Removes test stubs (files under 1 KB, impossible for a real course document) together with their attachments, reviews and stored files. */
function ecms_remove_broken_documents(mysqli $c): void
{
    $dir = dirname(__DIR__) . '/uploads/';
    $res = $c->query('SELECT document_id, stored_filename FROM documents WHERE file_size < 1024');
    $ids = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $ids[(int)$row['document_id']] = (string)$row['stored_filename'];
    }
    foreach ($ids as $id => $stored) {
        $att = $c->query('SELECT stored_filename FROM document_attachments WHERE document_id = ' . $id);
        while ($att && ($a = $att->fetch_assoc())) {
            @unlink($dir . basename((string)$a['stored_filename']));
        }
        @unlink($dir . basename($stored));
        $c->query('DELETE FROM verification_records WHERE document_id = ' . $id);
        $c->query('DELETE FROM documents WHERE document_id = ' . $id);
    }
}
