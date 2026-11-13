-- ECMS Prototype Database (final)
-- Project: Development of a Secure eCourseFile Management System (ECMS)
-- Student: Osman Salarov, CS01084469
-- Import in phpMyAdmin (Import tab) or: mysql -u root < sql/ecms_prototype.sql

CREATE DATABASE IF NOT EXISTS ecms_prototype CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE ecms_prototype;

DROP TABLE IF EXISTS shared_recipients;
DROP TABLE IF EXISTS shared_documents;
DROP TABLE IF EXISTS document_attachments;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS verification_records;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

CREATE TABLE roles (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(30) NOT NULL UNIQUE,
  description VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('Active','Disabled') NOT NULL DEFAULT 'Active',
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE TABLE documents (
  document_id INT AUTO_INCREMENT PRIMARY KEY,
  uploaded_by INT NOT NULL,
  title VARCHAR(150) NULL,
  category VARCHAR(80) NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL,
  file_type VARCHAR(100) NOT NULL,
  file_size INT NOT NULL,
  document_status ENUM('Pending','Complete','Incomplete') NOT NULL DEFAULT 'Pending',
  download_count INT NOT NULL DEFAULT 0,
  needs_more TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (uploaded_by) REFERENCES users(user_id),
  INDEX idx_documents_status (document_status)
) ENGINE=InnoDB;

CREATE TABLE verification_records (
  verification_id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  reviewed_by INT NOT NULL,
  status_decision ENUM('Complete','Incomplete','Pending') NOT NULL,
  comment TEXT,
  reviewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE document_attachments (
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
) ENGINE=InnoDB;

CREATE TABLE shared_documents (
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
) ENGINE=InnoDB;

CREATE TABLE shared_recipients (
  share_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at DATETIME NULL,
  PRIMARY KEY (share_id, user_id),
  FOREIGN KEY (share_id) REFERENCES shared_documents(share_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action_type VARCHAR(80) NOT NULL,
  target_id VARCHAR(255),
  description TEXT,
  ip_address VARCHAR(45),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_logs_action (action_type)
) ENGINE=InnoDB;

INSERT INTO roles(role_name, description) VALUES
('Admin', 'Manages users, reviews documents, updates verification status, and views activity logs'),
('Employee', 'Uploads course-file documents, views document status, and downloads own documents');

-- Demo accounts (bcrypt). admin123 / employee123 -- change before any real use.
INSERT INTO users(role_id, full_name, email, password_hash, status) VALUES
(1, 'ECMS Admin', 'admin@ecms.local', '$2y$12$qj5dZaU.w42bw71n3rFT3.CzlpGXf6QHbrtiStBD0iqc3IA/WNvzK', 'Active'),
(2, 'ECMS Employee', 'employee@ecms.local', '$2y$12$/gFJQ7BSovAWHthrB0LZ4u/GeX92BOCetQajlvIRZQRXVCH6pxCom', 'Active');

INSERT INTO activity_logs(user_id, action_type, target_id, description, ip_address) VALUES
(1, 'System Setup', 'DB', 'Initial ECMS database created', '127.0.0.1');
