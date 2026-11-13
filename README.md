# ECMS – Secure eCourseFile Management System

Role-based course file management with a verification workflow.
Universiti Tenaga Nasional (UNITEN) · Final Year Project 2 · Osman Salarov (CS01084469) · Supervisor: Ts. Dr. Noor Akma binti Abu Bakar

> **Project status.** Version 1.0.0 was designed, built and tested locally in an offline environment, so everything was done on a single machine. The system is complete and ready for submission, and it will continue to be improved.

## Features
**Administrator:** dashboard overview, user management (create, reset password, enable/disable, unlock), *Review Submitted Documents* (Approve, Reject, Keep Pending with a request for additional files, CSV export), Send Documents to employees, Activity Logs (quick filters, plain-language entries, CSV export, peak activity / security alerts / storage), profile.

**Employee:** dashboard with document status, Upload Course Document (title, category, drag and drop), add additional files when requested, Inbox for documents sent by the administrator, profile (edit name/e-mail, change password).

## Security controls
Password hashing (bcrypt) · parameterised SQL · RBAC with audit log on violations · HttpOnly/SameSite sessions · 15-minute idle timeout · login lockout (5 failures, 15 min) with admin unlock · CSRF tokens · upload validation (extension, MIME, signature, structure, 5 MB) · random stored file names, `uploads/` denied by Apache, downloads only through permission-checked PHP · output encoding and CSP headers · CSV formula-injection protection.

## Setup (MAMP)
1. Copy this folder to `htdocs/ECMS_Prototype_FileManagement`.
2. Start Apache and MySQL, then in phpMyAdmin import `sql/ecms_prototype.sql`.
3. Copy `.env.example` to `.env` and set your MySQL credentials.
4. Make `uploads/` writable (`chmod 775 uploads`).
5. Open `http://localhost:8888/ECMS_Prototype_FileManagement/login.php`.

Demo accounts (seed data, change before any real use): `admin@ecms.local / admin123` · `employee@ecms.local / employee123`.
An existing database is upgraded automatically on first request (`include/migrate.php`).

## Tests
- Syntax: `find . -name '*.php' -exec php -l {} \;`
- Unit: `php tests/unit_tests.php`
- Black-box (running server + imported DB): `bash tests/security_tests.sh http://localhost:8888/ECMS_Prototype_FileManagement`

## Structure
`Private_Dashboard/` admin pages · `private_user/` employee pages · `include/` validators, migration, upload JS · `css/` styles · `sql/` schema and seed · `tests/` test scripts · `uploads/` stored files (not versioned).

## Known limits
5 MB per file (the original mockups showed 50 MB) · no e-mail notifications (in-system notifications only) · single-server deployment.

Licensed under the MIT License.
