<?php
declare(strict_types=1);
/** Activity Logs: searchable, filterable audit trail with quick-filter buttons, CSV export and live summary cards. */
require_once __DIR__ . '/../common.php';
require_login('Admin');

$groups = log_groups();
$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$role   = in_array($_GET['role'] ?? '', ['Admin', 'Employee'], true) ? (string)$_GET['role'] : '';
$ranges = ['1' => 'Last 24 Hours', '7' => 'Last 7 Days', '30' => 'Last 30 Days', '0' => 'All Time'];
$range  = isset($ranges[$_GET['range'] ?? '']) ? (string)$_GET['range'] : '0';
$group  = isset($groups[$_GET['g'] ?? '']) ? (string)$_GET['g'] : '';
$per    = 15;
$page   = max(1, (int)($_GET['page'] ?? 1));

$where = []; $types = ''; $params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(u.full_name LIKE ? OR l.action_type LIKE ? OR l.description LIKE ? OR l.target_id LIKE ?)';
    $types .= 'ssss'; array_push($params, $like, $like, $like, $like);
}
if ($role !== '') { $where[] = 'r.role_name = ?'; $types .= 's'; $params[] = $role; }
if ($range !== '0') { $where[] = 'l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'; $types .= 'i'; $params[] = (int)$range; }
if ($group !== '') {
    $in = implode(',', array_fill(0, count($groups[$group]), '?'));
    $where[] = 'l.action_type IN (' . $in . ')';
    $types .= str_repeat('s', count($groups[$group])); array_push($params, ...$groups[$group]);
}
$from = ' FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id LEFT JOIN roles r ON u.role_id = r.role_id'
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$cols = 'SELECT l.log_id, l.action_type, l.target_id, l.description, l.ip_address, l.created_at, u.full_name, r.role_name';

if (($_GET['export'] ?? '') === '1') {
    $rows = db_all($conn, $cols . $from . ' ORDER BY l.log_id DESC LIMIT 5000', $types, $params);
    log_activity($conn, (int)$_SESSION['user_id'], 'Export', 'Activity log', 'Exported ' . count($rows) . ' log rows');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'User', 'Role', 'Action', 'Target', 'Activity', 'IP', 'Date/Time']);
    $safe = static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    foreach ($rows as $r) {
        fputcsv($out, array_map($safe, ['LOG-' . $r['log_id'], $r['full_name'] ?? 'System / unknown', $r['role_name'] ?? '-', $r['action_type'], log_target((string)$r['target_id']), log_sentence($r), $r['ip_address'], $r['created_at']]));
    }
    exit;
}

$total = (int)db_one($conn, 'SELECT COUNT(*) n' . $from, $types, $params)['n'];
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$rows  = db_all($conn, $cols . $from . ' ORDER BY l.log_id DESC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$per, ($page - 1) * $per]));

$peak   = db_one($conn, 'SELECT HOUR(created_at) h, COUNT(*) c FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY h ORDER BY c DESC LIMIT 1');
$alerts = (int)db_one($conn, "SELECT COUNT(*) n FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND action_type IN ('Login Failed','Login Blocked','Account Locked','Access Denied','Password Change Failed')")['n'];
$bytes  = (int)db_one($conn, 'SELECT (SELECT COALESCE(SUM(file_size),0) FROM documents) + (SELECT COALESCE(SUM(file_size),0) FROM document_attachments) + (SELECT COALESCE(SUM(file_size),0) FROM shared_documents) AS b')['b'];

$base = ['q' => $q, 'role' => $role, 'range' => $range, 'g' => $group];
$url  = static fn(array $over = []): string => '?' . http_build_query(array_filter(array_merge($base, $over), static fn($v) => $v !== '' && $v !== null));

$actions = '<a class="btn btn-ghost btn-sm" href="' . h($url(['export' => '1'])) . '">' . icon('download', 16) . ' Export CSV</a>';
page_header('Activity Logs');
page_title('Activity Logs', 'Private_Dashboard/dashboard.php', 'Back to Dashboard', 'Every login, upload, download and administrative action is recorded here.', $actions);
?>
<div class="chips mb-3">
  <a class="chip <?= $group === '' ? 'on' : '' ?>" href="<?= h($url(['g' => ''])) ?>">All activity</a>
  <?php foreach ($groups as $name => $acts): ?>
    <a class="chip <?= $name === 'Security Alerts' ? 'alert' : '' ?> <?= $group === $name ? 'on' : '' ?>" href="<?= h($url(['g' => $name, 'page' => 1])) ?>"><?= h($name) ?></a>
  <?php endforeach; ?>
</div>

<form method="get" class="card mb-3"><div class="card-body"><div class="form-row align-items-end">
  <input type="hidden" name="g" value="<?= h($group) ?>">
  <div class="col-md-5 mb-2"><label class="fl" for="q">Search user or action</label><input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="e.g. ECMS Admin, upload, locked"></div>
  <div class="col-md-2 mb-2"><label class="fl" for="role">Filter by role</label><select class="form-control" id="role" name="role">
    <option value="">All Roles</option><?php foreach (['Admin', 'Employee'] as $ro): ?><option <?= $role === $ro ? 'selected' : '' ?>><?= $ro ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 mb-2"><label class="fl" for="range">Date range</label><select class="form-control" id="range" name="range">
    <?php foreach ($ranges as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $range === (string)$k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 mb-2"><button class="btn btn-primary btn-block"><?= icon('filter', 16) ?> Apply Filters</button></div>
</div></div></form>

<div class="card mb-4"><div class="card-head"><h2>Recent System Activities</h2><span class="hint"><?= $total ?> matching entr<?= $total === 1 ? 'y' : 'ies' ?></span></div>
<div class="table-responsive"><table class="tbl"><thead><tr><th>Log ID</th><th>User</th><th>Role</th><th>Action</th><th>Target</th><th>Activity</th><th class="text-nowrap">Date/Time</th></tr></thead><tbody>
<?php foreach ($rows as $r): $tone = log_tone($r['action_type']); $nm = $r['full_name'] ?? 'System'; ?>
<tr><td class="text-nowrap"><small>#LOG-<?= (int)$r['log_id'] ?></small></td>
<td class="text-nowrap"><span class="avatar-sm <?= $tone === 'alert' ? 'red' : '' ?>"><?= h(initials($nm)) ?></span><?= h($nm) ?></td>
<td><?= $r['role_name'] ? '<span class="pill ' . ($r['role_name'] === 'Admin' ? 'info' : 'grey') . '">' . h($r['role_name']) . '</span>' : '<span class="hint">-</span>' ?></td>
<td><span class="pill <?= h($tone) ?>"><?= h($r['action_type']) ?></span></td>
<td><small><?= h(log_target((string)$r['target_id'])) ?></small></td>
<td><?= h(log_sentence($r)) ?><br><small class="text-muted">IP <?= h($r['ip_address'] ?: '-') ?></small></td>
<td class="text-nowrap"><small><?= h(date('d M Y', strtotime((string)$r['created_at']))) ?><br><?= h(date('H:i:s', strtotime((string)$r['created_at']))) ?></small></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">No activity matches these filters.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="foot-row"><span>Showing <?= $total ? (($page - 1) * $per + 1) : 0 ?> to <?= min($total, $page * $per) ?> of <?= $total ?> entries</span>
  <div class="pager">
    <a class="<?= $page <= 1 ? 'off' : '' ?>" href="<?= h($url(['page' => max(1, $page - 1)])) ?>">&lsaquo;</a>
    <?php $shown = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn($p) => $p >= 1 && $p <= $pages)); sort($shown); $prev = 0;
    foreach ($shown as $p): if ($p - $prev > 1): ?><span class="off">&hellip;</span><?php endif; $prev = $p; ?>
      <a class="<?= $p === $page ? 'on' : '' ?>" href="<?= h($url(['page' => $p])) ?>"><?= $p ?></a>
    <?php endforeach; ?>
    <a class="<?= $page >= $pages ? 'off' : '' ?>" href="<?= h($url(['page' => min($pages, $page + 1)])) ?>">&rsaquo;</a>
  </div></div></div>

<div class="stats">
  <div class="stat"><div class="top"><span class="lbl">Peak activity (last 7 days)</span><?= icon('activity', 22) ?></div>
    <div class="num" style="font-size:1.4rem"><?= $peak ? sprintf('%02d:00 - %02d:00', (int)$peak['h'], ((int)$peak['h'] + 1) % 24) : 'No data' ?></div><small class="text-muted"><?= $peak ? (int)$peak['c'] . ' events in the busiest hour' : 'No events in the last 7 days' ?></small></div>
  <div class="stat <?= $alerts > 0 ? 'bad' : 'ok' ?>"><div class="top"><span class="lbl">Security alerts (last 24 h)</span><?= icon('alert', 22) ?></div>
    <div class="num"><?= $alerts ?></div><small class="text-muted">Failed or blocked logins, lockouts, denied access</small></div>
  <div class="stat"><div class="top"><span class="lbl">Storage used</span><?= icon('database', 22) ?></div>
    <div class="num" style="font-size:1.4rem"><?= h(format_size($bytes)) ?></div><small class="text-muted">Stored documents, attachments and shared files</small></div>
</div>
<?php page_footer();
