#!/usr/bin/env bash
# ECMS black-box security and workflow tests (run AFTER importing sql/ecms_prototype.sql and starting MAMP/XAMPP).
# Usage: bash tests/security_tests.sh [BASE_URL]   e.g. http://localhost:8888/ECMS_Prototype_FileManagement
# The script creates a throw-away employee (sectest_*@ecms.local) for the lockout and IDOR tests,
# so the demo employee account is never locked. The throw-away account is disabled at the end.
BASE="${1:-http://localhost:8888/ECMS_Prototype_FileManagement}"
T=$(mktemp -d); pass=0; fail=0; RND=$RANDOM
ok(){ echo "[PASS] $1"; pass=$((pass+1)); }
no(){ echo "[FAIL] $1"; fail=$((fail+1)); }
code(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }
tokfrom(){ grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
token(){ curl -s -c "$1" -b "$1" "$BASE/login.php" | tokfrom; }
login(){ local t; t=$(token "$1"); curl -s -o /dev/null -c "$1" -b "$1" -d "csrf=$t" --data-urlencode "email=$2" --data-urlencode "password=$3" "$BASE/login.php"; }
page(){ curl -s -b "$1" -c "$1" "$BASE/$2"; }

printf '%%PDF-1.4\n1 0 obj<<>>endobj\n%%%%EOF' > "$T/ok.pdf"
printf '<?php echo 1; ?>' > "$T/shell.php"; cp "$T/shell.php" "$T/evil.pdf"

echo "== Anonymous access =="
for p in Private_Dashboard/dashboard.php Private_Dashboard/users.php Private_Dashboard/verify_documents.php Private_Dashboard/activity_logs.php \
         Private_Dashboard/send_documents.php private_user/home.php private_user/inbox.php profile.php upload.php add_file.php?id=1 \
         download.php?id=1 download.php?att=1 download.php?share=1; do
  [ "$(code "$BASE/$p")" = 302 ] && ok "anonymous -> $p redirects to login" || no "anonymous -> $p"
done
[ "$(code "$BASE/uploads/sample.pdf")" != 200 ] && ok "direct /uploads access blocked" || no "direct /uploads"
[ "$(code "$BASE/.env")" != 200 ] && ok ".env not downloadable" || no ".env exposed"
[ "$(code "$BASE/sql/ecms_prototype.sql")" != 200 ] && ok "SQL dump not downloadable" || no "SQL dump exposed"
[ "$(code "$BASE/include/migrate.php")" != 200 ] && ok "include/ not web accessible" || no "include/ exposed"

echo "== Login attacks =="
login "$T/sqli" "admin@ecms.local' OR '1'='1" "x"
[ "$(code -b "$T/sqli" "$BASE/Private_Dashboard/dashboard.php")" = 302 ] && ok "SQLi login bypass fails" || no "SQLi login"
login "$T/sqli2" "' OR 1=1 -- " "' OR 1=1 -- "
[ "$(code -b "$T/sqli2" "$BASE/Private_Dashboard/dashboard.php")" = 302 ] && ok "SQLi (comment style) fails" || no "SQLi comment"
[ "$(code -d 'email=admin@ecms.local&password=admin123' "$BASE/login.php")" = 403 ] && ok "login POST without CSRF token -> 403" || no "login CSRF"

echo "== Admin =="
login "$T/adm" "admin@ecms.local" "admin123"
[ "$(code -b "$T/adm" "$BASE/Private_Dashboard/dashboard.php")" = 200 ] && ok "admin logs in" || no "admin login"
for p in users.php verify_documents.php activity_logs.php send_documents.php; do
  [ "$(code -b "$T/adm" "$BASE/Private_Dashboard/$p")" = 200 ] && ok "admin opens $p" || no "admin $p"
done
page "$T/adm" profile.php | grep -q "Documents uploaded" && no "admin profile must not show 'Documents uploaded'" || ok "admin profile hides 'Documents uploaded'"
[ "$(code -b "$T/adm" "$BASE/private_user/home.php")" = 403 ] && ok "RBAC: admin blocked from employee dashboard" || no "RBAC admin->employee"

echo "== Create throw-away employee =="
AT=$(page "$T/adm" Private_Dashboard/users.php | tokfrom)
curl -s -o /dev/null -b "$T/adm" -c "$T/adm" -d "csrf=$AT&do=create&full_name=Sec Test&role=Employee" --data-urlencode "email=sectest_$RND@ecms.local" -d "password=Test12345&password2=Mismatch99" "$BASE/Private_Dashboard/users.php"
page "$T/adm" Private_Dashboard/users.php | grep -q "sectest_$RND@" && no "mismatched password confirmation created an account" || ok "create user with mismatched password refused"
AT=$(page "$T/adm" Private_Dashboard/users.php | tokfrom)
curl -s -o /dev/null -b "$T/adm" -c "$T/adm" -d "csrf=$AT&do=create&full_name=Sec Test&role=Employee" --data-urlencode "email=sectest_$RND@ecms.local" -d "password=Test12345&password2=Test12345" "$BASE/Private_Dashboard/users.php"
page "$T/adm" Private_Dashboard/users.php | grep -q "sectest_$RND@" && ok "admin creates employee with confirmed password" || no "create user"
EMAIL="sectest_$RND@ecms.local"

echo "== Employee =="
login "$T/emp" "employee@ecms.local" "employee123"
[ "$(code -b "$T/emp" "$BASE/private_user/home.php")" = 200 ] && ok "employee logs in" || no "employee login"
for p in private_user/inbox.php profile.php support.php upload.php; do
  [ "$(code -b "$T/emp" "$BASE/$p")" = 200 ] && ok "employee opens $p" || no "employee $p"
done
for p in dashboard.php users.php activity_logs.php verify_documents.php send_documents.php; do
  [ "$(code -b "$T/emp" "$BASE/Private_Dashboard/$p")" = 403 ] && ok "RBAC: employee blocked from $p" || no "RBAC employee $p"
done
page "$T/emp" profile.php | grep -q "Documents uploaded" && ok "employee profile shows 'Documents uploaded'" || no "employee profile row"

ET=$(page "$T/emp" upload.php | tokfrom)
up(){ curl -s -b "$T/emp" -c "$T/emp" -L -F "csrf=$ET" -F "title=${2:-Test document}" -F "category=${3:-Other}" -F "myfile=@$1" "$BASE/upload.php"; }
up "$T/ok.pdf" | grep -q "uploaded successfully" && ok "valid PDF with title/category accepted (Pending)" || no "valid upload"
up "$T/shell.php" | grep -qi "Unsupported file extension\|blocked extension" && ok ".php upload rejected" || no ".php upload"
up "$T/evil.pdf" | grep -qi "does not match\|Invalid file signature" && ok "PHP renamed to .pdf rejected" || no "evil.pdf"
up "$T/ok.pdf" "" | grep -qi "document title" && ok "upload without title rejected" || no "title required"
up "$T/ok.pdf" "Bad cat" "NotACategory" | grep -qi "select a course category" && ok "upload with unknown category rejected" || no "category validation"
[ "$(code -b "$T/emp" -F "title=x" -F "category=Other" -F "myfile=@$T/ok.pdf" "$BASE/upload.php")" = 403 ] && ok "upload without CSRF token -> 403" || no "upload CSRF"

echo "== Profile =="
PT=$(page "$T/emp" profile.php | tokfrom)
curl -s -b "$T/emp" -c "$T/emp" -L -d "csrf=$PT&do=profile&full_name=ECMS Employee&email=admin@ecms.local" "$BASE/profile.php" | grep -qi "already used" && ok "profile: duplicate email refused" || no "profile duplicate email"
PT=$(page "$T/emp" profile.php | tokfrom)
curl -s -b "$T/emp" -c "$T/emp" -L -d "csrf=$PT&do=profile&full_name=ECMS Employee&email=bad" "$BASE/profile.php" | grep -qi "valid email" && ok "profile: invalid email refused" || no "profile invalid email"
PT=$(page "$T/emp" profile.php | tokfrom)
curl -s -b "$T/emp" -c "$T/emp" -L -d "csrf=$PT&do=password&current_password=employee123&new_password=Newpass123&confirm_password=Other12345" "$BASE/profile.php" | grep -qi "do not match" && ok "password change: mismatch refused" || no "password mismatch"
PT=$(page "$T/emp" profile.php | tokfrom)
curl -s -b "$T/emp" -c "$T/emp" -L -d "csrf=$PT&do=password&current_password=wrongcurrent1&new_password=Newpass123&confirm_password=Newpass123" "$BASE/profile.php" | grep -qi "current password is incorrect" && ok "password change: wrong current password refused" || no "wrong current password"

echo "== Send Documents / Inbox / additional documents =="
ST=$(page "$T/adm" Private_Dashboard/send_documents.php | tokfrom)
curl -s -b "$T/adm" -c "$T/adm" -L -F "csrf=$ST" -F "title=Sec test share" -F "message=hello" -F "audience=all" -F "myfile=@$T/ok.pdf" "$BASE/Private_Dashboard/send_documents.php" | grep -q "sent to" && ok "admin sends a document to all employees" || no "send documents"
SID=$(page "$T/emp" private_user/inbox.php | grep -o 'download.php?share=[0-9]*' | head -1 | sed 's/.*=//')
[ -n "$SID" ] && ok "employee sees the document in Inbox" || no "inbox item"
[ "$(code -b "$T/emp" "$BASE/download.php?share=$SID")" = 200 ] && ok "employee downloads shared document" || no "share download"
DOC=$(page "$T/adm" "Private_Dashboard/verify_documents.php?status=Pending" | grep -o '#DOC-[0-9]*' | sed 's/#DOC-//' | sort -n | tail -1)
VT=$(page "$T/adm" "Private_Dashboard/verify_documents.php?status=Pending&id=$DOC" | tokfrom)
curl -s -b "$T/adm" -c "$T/adm" -L -d "csrf=$VT&document_id=$DOC&decision=Incomplete&comment=" "$BASE/Private_Dashboard/verify_documents.php?status=Pending&id=$DOC" | grep -qi "reviewer note" && ok "Reject without a note refused" || no "reject needs note"
VT=$(page "$T/adm" "Private_Dashboard/verify_documents.php?status=Pending&id=$DOC" | tokfrom)
curl -s -b "$T/adm" -c "$T/adm" -L -d "csrf=$VT&document_id=$DOC&decision=Pending" --data-urlencode "comment=Please add the signed outline" "$BASE/Private_Dashboard/verify_documents.php?status=Pending&id=$DOC" | grep -q "stays Pending" && ok "Keep Pending with a request note works" || no "keep pending"
page "$T/emp" private_user/home.php | grep -q "Additional documents required" && ok "employee is notified on the dashboard" || no "employee notification"
AF=$(page "$T/emp" "add_file.php?id=$DOC" | tokfrom)
curl -s -b "$T/emp" -c "$T/emp" -L -F "csrf=$AF" -F "myfile=@$T/evil.pdf" "$BASE/add_file.php?id=$DOC" | grep -qi "does not match\|signature" && ok "additional file is validated like any upload" || no "attachment validation"
curl -s -b "$T/emp" -c "$T/emp" -L -F "csrf=$AF" -F "myfile=@$T/ok.pdf" "$BASE/add_file.php?id=$DOC" | grep -q "Additional file added" && ok "employee adds an additional file" || no "add additional file"
VT=$(page "$T/adm" "Private_Dashboard/verify_documents.php?status=Pending&id=$DOC" | tokfrom)
curl -s -b "$T/adm" -c "$T/adm" -L -d "csrf=$VT&document_id=$DOC&decision=Complete" "$BASE/Private_Dashboard/verify_documents.php?status=Pending&id=$DOC" | grep -q "marked as Complete" && ok "admin approves; request is closed" || no "approve"
page "$T/emp" "add_file.php?id=$DOC" | grep -qi "No additional documents are requested" && ok "add-file page closes after the decision" || no "request closed"
curl -s -b "$T/adm" "$BASE/Private_Dashboard/verify_documents.php?status=All&export=1" | head -1 | grep -q "Document ID" && ok "Verification report exports as CSV" || no "verification export"

echo "== Audit log =="
page "$T/adm" Private_Dashboard/activity_logs.php | grep -q "uploaded" && ok "upload recorded in audit log (plain language)" || no "log upload"
page "$T/adm" "Private_Dashboard/activity_logs.php?g=Security+Alerts" | grep -q "Access Denied\|Login Failed" && ok "Security Alerts quick filter lists denied/failed events" || no "log alerts filter"
curl -s -b "$T/adm" "$BASE/Private_Dashboard/activity_logs.php?export=1" | head -1 | grep -q "Log ID" && ok "Activity log exports as CSV" || no "log export"

echo "== IDOR / lockout with throw-away employee =="
login "$T/b" "$EMAIL" "Test12345"
[ "$(code -b "$T/b" "$BASE/download.php?id=$DOC")" = 403 ] && ok "employee B cannot download employee A's document" || no "IDOR document"
[ "$(code -b "$T/b" "$BASE/add_file.php?id=$DOC")" = 403 ] && ok "employee B cannot open add-file for A's document" || no "IDOR add_file"
[ "$(code -b "$T/b" "$BASE/download.php?att=1")" != 200 ] && ok "employee B cannot download others' additional files" || no "IDOR attachment"
for i in 1 2 3 4 5; do login "$T/bf" "$EMAIL" "wrong$i"; done
login "$T/bf2" "$EMAIL" "Test12345"
[ "$(code -b "$T/bf2" "$BASE/private_user/home.php")" = 302 ] && ok "account locked after 5 failed logins (correct password refused)" || no "lockout"
UT=$(page "$T/adm" Private_Dashboard/users.php | tokfrom)
UID_B=$(page "$T/adm" Private_Dashboard/users.php | grep -B1 -A8 "$EMAIL" | grep -o 'name="user_id" value="[0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$T/adm" -c "$T/adm" -d "csrf=$UT&do=unlock&user_id=$UID_B" "$BASE/Private_Dashboard/users.php"
login "$T/bf3" "$EMAIL" "Test12345"
[ "$(code -b "$T/bf3" "$BASE/private_user/home.php")" = 200 ] && ok "admin Unlock restores access" || no "unlock"
UT=$(page "$T/adm" Private_Dashboard/users.php | tokfrom)
curl -s -o /dev/null -b "$T/adm" -c "$T/adm" -d "csrf=$UT&do=toggle&user_id=$UID_B" "$BASE/Private_Dashboard/users.php"   # disable the throw-away account

echo; echo "Passed: $pass  Failed: $fail"; rm -rf "$T"; [ $fail -eq 0 ]
