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
}
