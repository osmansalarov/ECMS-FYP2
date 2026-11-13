<?php
declare(strict_types=1);
/** Send Documents: an administrator sends a validated file with a message to all or selected employees (shows in their Inbox). */
require_once __DIR__ . '/../common.php';
require_login('Admin');

$self = (int)$_SESSION['user_id'];
// Everyone who can receive a document: all active users (employees and administrators).
$people    = db_all($conn, "SELECT u.user_id, u.full_name, u.email, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.status = 'Active' ORDER BY r.role_name, u.full_name");
$employees = array_values(array_filter($people, static fn($p) => $p['role_name'] === 'Employee'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 2000);
    $all     = ($_POST['audience'] ?? 'all') === 'all';
    $valid   = array_map('intval', array_column($people, 'user_id'));
    $unknown = [];
    if ($all) {
        $picked = array_map('intval', array_column($employees, 'user_id'));
    } else {
        $picked = array_values(array_intersect($valid, array_map('intval', (array)($_POST['recipients'] ?? []))));
        // Typed recipients: names or e-mail addresses separated by comma, semicolon or new line.
        foreach (preg_split('/[,;\r\n]+/', (string)($_POST['typed'] ?? '')) ?: [] as $tok) {
            $tok = mb_strtolower(trim($tok));
            if ($tok === '') { continue; }
            $hit = null;
            foreach ($people as $p) {
                if (mb_strtolower($p['email']) === $tok || mb_strtolower($p['full_name']) === $tok) { $hit = (int)$p['user_id']; break; }
            }
            if ($hit === null) { $unknown[] = $tok; } else { $picked[] = $hit; }
        }
        $picked = array_values(array_unique($picked));
    }

    if (mb_strlen($title) < 3) {
        flash_set('danger', 'Please enter a title (at least 3 characters).');
    } elseif ($unknown) {
        flash_set('danger', 'Unknown recipient(s): ' . implode(', ', array_map(static fn($t) => mb_substr($t, 0, 60), $unknown)) . '. Use the exact name or e-mail of an active user.');
    } elseif (!$picked) {
        flash_set('danger', $all ? 'There are no active employees to send to.' : 'Select at least one recipient.');
    } else {
        $res = ecms_process_upload($_FILES['myfile'] ?? null);
        if (!$res['ok']) {
            if ($res['original'] !== '') {
                log_activity($conn, $self, 'Upload Rejected', 'System', 'Rejected "' . substr($res['original'], 0, 100) . '": ' . $res['msg']);
            }
            flash_set('danger', $res['msg']);
        } else {
            $conn->begin_transaction();
            try {
                $stmt = db_query($conn, 'INSERT INTO shared_documents(sent_by, title, message, original_filename, stored_filename, file_type, file_size) VALUES (?,?,?,?,?,?,?)',
                    'isssssi', [$self, $title, $message, $res['original'], $res['stored'], $res['mime'], $res['size']]);
                $shareId = (int)$conn->insert_id;
                $stmt->close();
                foreach ($picked as $uid) {
                    db_query($conn, 'INSERT INTO shared_recipients(share_id, user_id) VALUES (?,?)', 'ii', [$shareId, (int)$uid])->close();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            log_activity($conn, $self, 'Send Document', 'SHARE-' . $shareId, 'Sent "' . $res['original'] . '" to ' . count($picked) . ' recipient(s)');
            flash_set('success', 'Document sent to ' . count($picked) . ' recipient(s). It now appears in their Inbox.');
        }
    }
    redirect('Private_Dashboard/send_documents.php');
}

$sent = db_all($conn, 'SELECT s.share_id, s.title, s.original_filename, s.sent_at, s.download_count,
                              (SELECT COUNT(*) FROM shared_recipients r WHERE r.share_id = s.share_id) AS recip_count,
                              (SELECT COUNT(*) FROM shared_recipients r WHERE r.share_id = s.share_id AND r.read_at IS NOT NULL) AS read_count
                         FROM shared_documents s ORDER BY s.sent_at DESC LIMIT 20');
page_header('Send Documents');
page_title('Send Documents', 'Private_Dashboard/dashboard.php', 'Back to Dashboard', 'Send course material or announcements with a file to employees or administrators. They receive it in their Inbox.');
?>
<div class="grid-main">
  <div class="card"><div class="card-head"><h2><?= icon('send', 18) ?> New document</h2></div><div class="card-body">
    <form method="POST" enctype="multipart/form-data" action="send_documents.php">
      <?= csrf_field() ?><input type="hidden" name="MAX_FILE_SIZE" value="<?= ECMS_MAX_UPLOAD_BYTES ?>">
      <div class="form-group"><label class="fl" for="title">Title</label><input class="form-control" id="title" name="title" required minlength="3" maxlength="150" placeholder="e.g., Semester 2 Course File Template"></div>
      <div class="form-group"><label class="fl" for="message">Message (optional)</label><textarea class="form-control" id="message" name="message" rows="3" maxlength="2000"></textarea></div>
      <div class="form-group"><span class="fl">Recipients</span>
        <div class="custom-control custom-radio"><input type="radio" class="custom-control-input" id="aud-all" name="audience" value="all" checked><label class="custom-control-label" for="aud-all">All active employees (<?= count($employees) ?>)</label></div>
        <div class="custom-control custom-radio mb-2"><input type="radio" class="custom-control-input" id="aud-sel" name="audience" value="selected"><label class="custom-control-label" for="aud-sel">Selected employees</label></div>
        <div id="selpanel" style="display:none">
          <input class="form-control mb-2" id="rsearch" type="search" placeholder="Search the list by name, e-mail or role" aria-label="Search recipients">
          <div class="recip-list" id="rlist"><?php foreach ($people as $e): ?>
            <label data-s="<?= h(mb_strtolower($e['full_name'] . ' ' . $e['email'] . ' ' . $e['role_name'])) ?>"><input type="checkbox" name="recipients[]" value="<?= (int)$e['user_id'] ?>">
              <span><b><?= h($e['full_name']) ?></b> &middot; <?= h($e['email']) ?> <span class="pill <?= $e['role_name'] === 'Admin' ? 'info' : 'grey' ?>"><?= h($e['role_name']) ?></span><?= (int)$e['user_id'] === $self ? ' (you)' : '' ?></span></label>
          <?php endforeach; ?></div>
          <label class="fl mt-2" for="typed">Or type names / e-mail addresses (separate with commas or new lines)</label>
          <textarea class="form-control" id="typed" name="typed" rows="2" placeholder="employee@ecms.local, ECMS Admin"></textarea>
          <small class="hint">Employees and administrators can be chosen. Unknown names are rejected.</small>
        </div></div>
      <div class="form-group"><span class="fl">File attachment</span>
        <label class="dropzone" id="dz"><?= icon('cloud', 40) ?><b>Drag and drop file or click to browse</b><span>PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, ODT &middot; max 5 MB</span>
          <input type="file" name="myfile" required accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.odt"><span class="picked"></span></label></div>
      <div class="text-right"><button class="btn btn-primary btn-lg-cta">Send</button></div>
    </form></div></div>
  <div class="card"><div class="card-head"><h2>Recently sent</h2></div><div class="card-body p-0">
    <?php foreach ($sent as $s): ?>
      <div style="padding:16px 24px;border-bottom:1px solid #edf1f7"><b><?= h($s['title']) ?></b><br><small class="text-muted"><?= h($s['original_filename']) ?></small><br>
        <small><?= h(date('d M Y, H:i', strtotime((string)$s['sent_at']))) ?> &middot; <?= (int)$s['recip_count'] ?> recipient(s) &middot; <?= (int)$s['read_count'] ?> opened &middot; <?= (int)$s['download_count'] ?> download(s)</small></div>
    <?php endforeach; if (!$sent): ?><div class="empty">Nothing sent yet.</div><?php endif; ?></div></div>
</div>
<script>(function(){var all=document.getElementById('aud-all'),sel=document.getElementById('aud-sel'),p=document.getElementById('selpanel');
function t(){p.style.display=sel.checked?'block':'none';}all.addEventListener('change',t);sel.addEventListener('change',t);t();
document.getElementById('rsearch').addEventListener('input',function(){var q=this.value.toLowerCase();document.querySelectorAll('#rlist label').forEach(function(l){l.style.display=l.getAttribute('data-s').indexOf(q)>-1?'flex':'none';});});})();</script>
<?php require __DIR__ . '/../include/dropzone_js.php'; page_footer();
