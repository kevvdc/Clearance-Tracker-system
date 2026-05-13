<?php
// dashboard.php  —  Admin / Staff dashboard
require_once 'config.php';
requireStaff();
$pageTitle = 'Dashboard';

$stats = [
    'residents' => $conn->query("SELECT COUNT(*) AS c FROM residents")->fetch_assoc()['c'],
    'requests'  => $conn->query("SELECT COUNT(*) AS c FROM requests")->fetch_assoc()['c'],
    'pending'   => $conn->query("SELECT COUNT(*) AS c FROM requests WHERE status='pending'")->fetch_assoc()['c'],
    'approved'  => $conn->query("SELECT COUNT(*) AS c FROM requests WHERE status IN ('approved','released')")->fetch_assoc()['c'],
    'pending_accounts' => $conn->query("SELECT COUNT(*) AS c FROM users WHERE status='pending' AND role='resident'")->fetch_assoc()['c'],
];

// Recent requests — enhanced with subquery for audit count per request
$recentReqs = $conn->query("
    SELECT r.request_id, CONCAT(res.first_name,' ',res.last_name) AS resident_name,
           ct.type_name, r.date_requested, r.purpose, r.status, r.source,
           (
               /* Subquery: count audit trail entries per request */
               SELECT COUNT(*) FROM request_audit a WHERE a.request_id = r.request_id
           ) AS audit_count
    FROM requests r
    JOIN residents res ON r.resident_id = res.resident_id
    JOIN clearance_type ct ON r.type_id = ct.type_id
    ORDER BY r.created_at DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Requests by status
$statusDist = $conn->query("
    SELECT status, COUNT(*) AS cnt FROM requests GROUP BY status ORDER BY cnt DESC
")->fetch_all(MYSQLI_ASSOC);

// Recent audit
$auditLog = $conn->query("
    SELECT a.done_at, a.action, a.new_status,
           CONCAT(res.first_name,' ',res.last_name) AS resident_name,
           u.full_name AS done_by_name, u.role AS done_by_role
    FROM request_audit a
    JOIN requests req ON a.request_id = req.request_id
    JOIN residents res ON req.resident_id = res.resident_id
    JOIN users u ON a.done_by = u.user_id
    ORDER BY a.done_at DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

include 'layout/header.php';
?>

<!-- ── Stats Row ── -->
<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['Residents',         $stats['residents'],        'people-fill',        '#3b82f6', '#eff6ff'],
    ['Total Requests',    $stats['requests'],         'file-earmark-text-fill', '#8b5cf6', '#f5f3ff'],
    ['Pending Requests',  $stats['pending'],          'clock-fill',         '#d97706', '#fffbeb'],
    ['Approved/Released', $stats['approved'],         'check-circle-fill',  '#16a34a', '#f0fdf4'],
  ];
  if (isAdmin()) {
    $cards[] = ['Accounts Pending', $stats['pending_accounts'], 'person-check-fill', '#dc2626', '#fff1f2'];
  }
  foreach ($cards as [$lbl, $val, $icon, $color, $bg]):
  ?>
  <div class="col-6 col-xl-<?= isAdmin()?'auto':'3' ?>" style="<?= isAdmin()?'flex:1':'' ?>">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>;">
        <i class="bi bi-<?= $icon ?>"></i>
      </div>
      <div>
        <div class="stat-val"><?= number_format($val) ?></div>
        <div class="stat-lbl"><?= $lbl ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <!-- Recent Requests Table -->
  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-header-c">
        <h3><i class="bi bi-file-earmark-text" style="color:var(--crimson)"></i> Recent Requests</h3>
        <a href="requests.php" class="btn-c btn-secondary-c">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>#</th><th>Resident</th><th>Type</th><th>Purpose</th><th>Date</th><th>Source</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentReqs as $r): ?>
            <tr>
              <td><a href="requests.php?action=view&id=<?= $r['request_id'] ?>" class="text-decoration-none" style="color:var(--crimson);font-weight:700;">#<?= $r['request_id'] ?></a></td>
              <td><?= htmlspecialchars($r['resident_name']) ?></td>
              <td><?= htmlspecialchars($r['type_name']) ?></td>
              <td><?= htmlspecialchars($r['purpose']) ?></td>
              <td><?= date('M j, Y', strtotime($r['date_requested'])) ?></td>
              <td>
                <span class="badge <?= safeSource($r)==='walkin'?'bg-warning text-dark':'bg-info text-white' ?>">
                  <i class="bi bi-<?= safeSource($r)==='walkin'?'person-badge':'wifi' ?>"></i>
                  <?= ucfirst(safeSource($r)) ?>
                </span>
              </td>
              <td><?= statusBadge($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentReqs)): ?>
            <tr><td colspan="7" class="text-center py-4 text-muted">No requests yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Status Distribution -->
  <div class="col-12 col-lg-4">
    <div class="card" style="height:100%;">
      <div class="card-header-c">
        <h3><i class="bi bi-pie-chart-fill" style="color:var(--crimson)"></i> Status Distribution</h3>
      </div>
      <div style="padding:1.25rem;">
        <?php
        $total = array_sum(array_column($statusDist, 'cnt')) ?: 1;
        $colors = ['pending'=>'#d97706','verified'=>'#0284c7','approved'=>'#16a34a','released'=>'#7c3aed','rejected'=>'#dc2626'];
        foreach ($statusDist as $sd):
          $pct = round($sd['cnt'] / $total * 100);
          $col = $colors[$sd['status']] ?? '#9696a0';
        ?>
        <div style="margin-bottom:1rem;">
          <div style="display:flex;justify-content:space-between;margin-bottom:.25rem;font-size:.78rem;">
            <span style="font-weight:600;text-transform:capitalize;"><?= $sd['status'] ?></span>
            <span style="color:var(--n500);"><?= $sd['cnt'] ?> (<?= $pct ?>%)</span>
          </div>
          <div style="background:var(--n100);border-radius:99px;height:8px;overflow:hidden;">
            <div style="background:<?= $col ?>;height:100%;width:<?= $pct ?>%;border-radius:99px;transition:width .5s;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Audit Log -->
<div class="card">
  <div class="card-header-c">
    <h3><i class="bi bi-journal-text" style="color:var(--crimson)"></i> Recent Activity Log</h3>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr><th>Date &amp; Time</th><th>Action</th><th>Resident</th><th>Status</th><th>By</th></tr>
      </thead>
      <tbody>
        <?php foreach ($auditLog as $a): ?>
        <tr>
          <td style="white-space:nowrap;color:var(--n500);"><?= date('M j, g:i A', strtotime($a['done_at'])) ?></td>
          <td><?= htmlspecialchars($a['action']) ?></td>
          <td><?= htmlspecialchars($a['resident_name']) ?></td>
          <td><?= $a['new_status'] ? statusBadge($a['new_status']) : '—' ?></td>
          <td>
            <?= htmlspecialchars($a['done_by_name']) ?>
            <span class="badge bg-secondary" style="font-size:.6rem;"><?= $a['done_by_role'] ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($auditLog)): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No activity recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include 'layout/footer.php'; ?>
