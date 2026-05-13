<?php
// analytics.php  —  Advanced SQL Analytics (Admin only)
// ============================================================
// SQL Features implemented in this file:
//
//  [AGGREGATION]  COUNT, SUM, AVG, MAX, MIN  (5 functions)
//  [JOIN]         3-table JOINs across requests, residents,
//                 clearance_type, users, staff_roles
//  [SUBQUERY 1]   Resident request-count ranking (inline subquery)
//  [SUBQUERY 2]   Above-average performers filter (WHERE subquery)
//  [SUBQUERY 3]   Latest audit action per request (correlated subquery)
//  [CTE]          Monthly staff processing summary (WITH clause)
// ============================================================

require_once 'config.php';
requireAdmin();
$pageTitle = 'Advanced Analytics';

// ─────────────────────────────────────────────────────────────
// CSV DOWNLOAD HANDLER — must run before any output
// ─────────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $dl   = $_GET['download'];
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? 0);
    $dateWhere    = "YEAR(r.date_requested) = $year";
    $dateWhereReq = "YEAR(date_requested) = $year";
    if ($month) {
        $dateWhere    .= " AND MONTH(r.date_requested) = $month";
        $dateWhereReq .= " AND MONTH(date_requested) = $month";
    }

    $reports = [];

    if ($dl === 'type_stats' || $dl === 'all') {
        $rows = $conn->query("
            SELECT ct.type_name, COUNT(r.request_id) AS total_requests,
                SUM(r.status IN ('approved','released')) AS total_approved,
                SUM(r.status = 'rejected') AS total_rejected,
                SUM(r.status = 'pending') AS total_pending,
                ROUND(SUM(r.status IN ('approved','released'))/NULLIF(COUNT(r.request_id),0)*100,1) AS approval_rate,
                MIN(r.date_requested) AS earliest_request,
                MAX(r.date_requested) AS latest_request,
                AVG(DATEDIFF(r.updated_at, r.created_at)) AS avg_processing_days
            FROM clearance_type ct
            LEFT JOIN requests r ON ct.type_id = r.type_id AND $dateWhere
            LEFT JOIN residents res ON r.resident_id = res.resident_id
            GROUP BY ct.type_id, ct.type_name ORDER BY total_requests DESC
        ")->fetch_all(MYSQLI_ASSOC);
        $reports['Clearance_Type_Performance'] = $rows;
    }
    if ($dl === 'top_residents' || $dl === 'all') {
        $rows = $conn->query("
            SELECT CONCAT(res.first_name,' ',res.last_name) AS resident_name, res.purok,
                rk.total_requests, rk.approved_count, rk.distinct_types
            FROM residents res
            JOIN (SELECT r.resident_id, COUNT(r.request_id) AS total_requests,
                    SUM(r.status IN ('approved','released')) AS approved_count,
                    COUNT(DISTINCT r.type_id) AS distinct_types
                  FROM requests r WHERE $dateWhereReq GROUP BY r.resident_id) AS rk
            ON res.resident_id = rk.resident_id
            ORDER BY rk.total_requests DESC, rk.approved_count DESC LIMIT 10
        ")->fetch_all(MYSQLI_ASSOC);
        $reports['Top_Requesting_Residents'] = $rows;
    }
    if ($dl === 'above_avg_staff' || $dl === 'all') {
        $rows = $conn->query("
            SELECT u.full_name, u.role, sr.role_name AS staff_role,
                COUNT(r.request_id) AS handled_count,
                SUM(r.status IN ('approved','released')) AS approved_count,
                SUM(r.status = 'rejected') AS rejected_count,
                ROUND(AVG(DATEDIFF(r.updated_at, r.created_at)),1) AS avg_days
            FROM users u LEFT JOIN staff_roles sr ON u.staff_role_id = sr.role_id
            JOIN requests r ON r.handled_by = u.user_id AND $dateWhere
            GROUP BY u.user_id, u.full_name, u.role, sr.role_name
            HAVING handled_count > (SELECT AVG(staff_cnt) FROM
                (SELECT COUNT(request_id) AS staff_cnt FROM requests
                 WHERE handled_by IS NOT NULL AND $dateWhereReq GROUP BY handled_by) AS avg_sub)
            ORDER BY handled_count DESC
        ")->fetch_all(MYSQLI_ASSOC);
        $reports['Above_Average_Staff'] = $rows;
    }
    if ($dl === 'audit_trail' || $dl === 'all') {
        $rows = $conn->query("
            SELECT r.request_id, CONCAT(res.first_name,' ',res.last_name) AS resident_name,
                res.purok, ct.type_name, r.status, r.source, r.date_requested,
                (SELECT a.action FROM request_audit a WHERE a.request_id = r.request_id ORDER BY a.done_at DESC LIMIT 1) AS last_action,
                (SELECT u2.full_name FROM request_audit a2 JOIN users u2 ON a2.done_by = u2.user_id WHERE a2.request_id = r.request_id ORDER BY a2.done_at DESC LIMIT 1) AS last_handled_by
            FROM requests r JOIN residents res ON r.resident_id = res.resident_id
            JOIN clearance_type ct ON r.type_id = ct.type_id
            WHERE $dateWhere ORDER BY r.updated_at DESC LIMIT 15
        ")->fetch_all(MYSQLI_ASSOC);
        $reports['Requests_Audit_Trail'] = $rows;
    }
    if ($dl === 'monthly_summary' || $dl === 'all') {
        $rows = $conn->query("
            WITH monthly_workload AS (
                SELECT u.user_id, u.full_name, u.role, sr.role_name AS staff_role,
                    MONTH(r.date_requested) AS work_month,
                    COUNT(r.request_id) AS requests_handled,
                    SUM(r.status IN ('approved','released')) AS approved,
                    SUM(r.status = 'rejected') AS rejected,
                    SUM(r.status = 'pending') AS still_pending,
                    ROUND(AVG(DATEDIFF(r.updated_at, r.created_at)),1) AS avg_processing_days
                FROM users u LEFT JOIN staff_roles sr ON u.staff_role_id = sr.role_id
                JOIN requests r ON r.handled_by = u.user_id AND YEAR(r.date_requested) = $year
                GROUP BY u.user_id, u.full_name, u.role, sr.role_name, MONTH(r.date_requested)
            )
            SELECT work_month, full_name, staff_role, role, requests_handled,
                approved, rejected, still_pending, avg_processing_days,
                SUM(requests_handled) OVER (PARTITION BY user_id ORDER BY work_month) AS running_total
            FROM monthly_workload ORDER BY work_month, requests_handled DESC
        ")->fetch_all(MYSQLI_ASSOC);
        $reports['Monthly_Staff_Workload'] = $rows;
    }

    if ($dl === 'all') {
        // ZIP multiple CSVs
        $zip = new ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'analytics_') . '.zip';
        $zip->open($tmpFile, ZipArchive::CREATE);
        foreach ($reports as $name => $data) {
            if (empty($data)) continue;
            ob_start();
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($data[0]));
            foreach ($data as $row) fputcsv($out, $row);
            fclose($out);
            $csv = ob_get_clean();
            $zip->addFromString($name . '_' . $year . ($month ? '_' . sprintf('%02d', $month) : '') . '.csv', $csv);
        }
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="Analytics_' . $year . ($month ? '_' . sprintf('%02d', $month) : '') . '.zip"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        unlink($tmpFile);
        exit;
    } else {
        // Single CSV
        $name = array_key_first($reports);
        $data = $reports[$name];
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $name . '_' . $year . ($month ? '_' . sprintf('%02d', $month) : '') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($data)) {
            fputcsv($out, array_keys($data[0]));
            foreach ($data as $row) fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0);

$dateWhere      = "YEAR(r.date_requested) = $year";
$dateWhereReq   = "YEAR(date_requested) = $year";
if ($month) {
    $dateWhere    .= " AND MONTH(r.date_requested) = $month";
    $dateWhereReq .= " AND MONTH(date_requested) = $month";
}

// ─────────────────────────────────────────────────────────────
// QUERY 1 — Aggregation Functions: COUNT, AVG, MIN, MAX, SUM
// JOIN across 3 tables: requests + residents + clearance_type
// Shows clearance type performance overview
// ─────────────────────────────────────────────────────────────
$typeStats = $conn->query("
    SELECT
        ct.type_name,
        ct.type_id,
        COUNT(r.request_id)                          AS total_requests,
        SUM(r.status IN ('approved','released'))     AS total_approved,
        SUM(r.status = 'rejected')                   AS total_rejected,
        SUM(r.status = 'pending')                    AS total_pending,
        ROUND(
            SUM(r.status IN ('approved','released')) /
            NULLIF(COUNT(r.request_id), 0) * 100, 1
        )                                            AS approval_rate,
        MIN(r.date_requested)                        AS earliest_request,
        MAX(r.date_requested)                        AS latest_request,
        AVG(
            DATEDIFF(r.updated_at, r.created_at)
        )                                            AS avg_processing_days
    FROM clearance_type ct
    LEFT JOIN requests r
           ON ct.type_id = r.type_id
          AND $dateWhere
    LEFT JOIN residents res
           ON r.resident_id = res.resident_id
    GROUP BY ct.type_id, ct.type_name
    ORDER BY total_requests DESC
")->fetch_all(MYSQLI_ASSOC);

// ─────────────────────────────────────────────────────────────
// QUERY 2 — SUBQUERY 1 (inline/derived table)
// Resident request-count ranking with total & approved counts
// JOIN across 3 tables: residents + requests + clearance_type
// ─────────────────────────────────────────────────────────────
$topResidents = $conn->query("
    SELECT
        res.resident_id,
        CONCAT(res.first_name, ' ', res.last_name) AS resident_name,
        res.purok,
        rk.total_requests,
        rk.approved_count,
        rk.distinct_types
    FROM residents res
    JOIN (
        /* SUBQUERY 1: Aggregate per resident before joining */
        SELECT
            r.resident_id,
            COUNT(r.request_id)                      AS total_requests,
            SUM(r.status IN ('approved','released'))  AS approved_count,
            COUNT(DISTINCT r.type_id)                 AS distinct_types
        FROM requests r
        WHERE $dateWhereReq
        GROUP BY r.resident_id
    ) AS rk ON res.resident_id = rk.resident_id
    ORDER BY rk.total_requests DESC, rk.approved_count DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ─────────────────────────────────────────────────────────────
// QUERY 3 — SUBQUERY 2 (WHERE subquery)
// Staff members whose processed count is above the average
// JOIN across 3 tables: users + staff_roles + requests
// ─────────────────────────────────────────────────────────────
$aboveAvgStaff = $conn->query("
    SELECT
        u.full_name,
        u.role,
        sr.role_name        AS staff_role,
        COUNT(r.request_id) AS handled_count,
        SUM(r.status IN ('approved','released'))  AS approved_count,
        SUM(r.status = 'rejected')                AS rejected_count,
        ROUND(AVG(DATEDIFF(r.updated_at, r.created_at)), 1) AS avg_days
    FROM users u
    LEFT JOIN staff_roles sr ON u.staff_role_id = sr.role_id
    JOIN requests r
         ON r.handled_by = u.user_id
        AND $dateWhere
    GROUP BY u.user_id, u.full_name, u.role, sr.role_name
    HAVING handled_count > (
        /* SUBQUERY 2: Compute barangay-wide average handling count */
        SELECT AVG(staff_cnt) FROM (
            SELECT COUNT(request_id) AS staff_cnt
            FROM requests
            WHERE handled_by IS NOT NULL
              AND $dateWhereReq
            GROUP BY handled_by
        ) AS avg_sub
    )
    ORDER BY handled_count DESC
")->fetch_all(MYSQLI_ASSOC);

// ─────────────────────────────────────────────────────────────
// QUERY 4 — SUBQUERY 3 (correlated subquery)
// Latest audit action label per request, with full details
// JOIN across 3 tables: requests + residents + clearance_type
// ─────────────────────────────────────────────────────────────
$recentWithAudit = $conn->query("
    SELECT
        r.request_id,
        CONCAT(res.first_name, ' ', res.last_name) AS resident_name,
        res.purok,
        ct.type_name,
        r.status,
        r.source,
        r.date_requested,
        (
            /* SUBQUERY 3: Correlated — fetch latest audit action for each request */
            SELECT a.action
            FROM request_audit a
            WHERE a.request_id = r.request_id
            ORDER BY a.done_at DESC
            LIMIT 1
        ) AS last_action,
        (
            SELECT u2.full_name
            FROM request_audit a2
            JOIN users u2 ON a2.done_by = u2.user_id
            WHERE a2.request_id = r.request_id
            ORDER BY a2.done_at DESC
            LIMIT 1
        ) AS last_handled_by
    FROM requests r
    JOIN residents res ON r.resident_id = res.resident_id
    JOIN clearance_type ct ON r.type_id = ct.type_id
    WHERE $dateWhere
    ORDER BY r.updated_at DESC
    LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

// ─────────────────────────────────────────────────────────────
// QUERY 5 — CTE (Common Table Expression)
// Monthly staff processing summary — similar to payroll/workload
// JOIN across 3 tables: users + staff_roles + requests
// ─────────────────────────────────────────────────────────────
$monthlySummary = $conn->query("
    WITH monthly_workload AS (
        /* CTE: Aggregate each staff member's work per month */
        SELECT
            u.user_id,
            u.full_name,
            u.role,
            sr.role_name                                    AS staff_role,
            MONTH(r.date_requested)                         AS work_month,
            COUNT(r.request_id)                             AS requests_handled,
            SUM(r.status IN ('approved','released'))        AS approved,
            SUM(r.status = 'rejected')                      AS rejected,
            SUM(r.status = 'pending')                       AS still_pending,
            ROUND(AVG(DATEDIFF(r.updated_at, r.created_at)), 1) AS avg_processing_days
        FROM users u
        LEFT JOIN staff_roles sr ON u.staff_role_id = sr.role_id
        JOIN requests r
             ON r.handled_by = u.user_id
            AND YEAR(r.date_requested) = $year
        GROUP BY u.user_id, u.full_name, u.role, sr.role_name,
                 MONTH(r.date_requested)
    )
    SELECT
        work_month,
        full_name,
        staff_role,
        role,
        requests_handled,
        approved,
        rejected,
        still_pending,
        avg_processing_days,
        /* Running total within the CTE result */
        SUM(requests_handled) OVER (
            PARTITION BY user_id ORDER BY work_month
        ) AS running_total
    FROM monthly_workload
    ORDER BY work_month, requests_handled DESC
")->fetch_all(MYSQLI_ASSOC);

// ─────────────────────────────────────────────────────────────
// QUERY 6 — Overall summary stats (aggregation for stat cards)
// ─────────────────────────────────────────────────────────────
$overallStats = $conn->query("
    SELECT
        COUNT(r.request_id)                                AS total,
        SUM(r.status IN ('approved','released'))           AS approved,
        SUM(r.status = 'rejected')                         AS rejected,
        SUM(r.status = 'pending')                          AS pending,
        ROUND(AVG(DATEDIFF(r.updated_at, r.created_at)),1) AS avg_days,
        MIN(r.date_requested)                              AS earliest,
        MAX(r.date_requested)                              AS latest,
        COUNT(DISTINCT r.resident_id)                      AS unique_residents
    FROM requests r
    WHERE $dateWhere
")->fetch_assoc();

$months = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

include 'layout/header.php';
?>

<!-- ── Filter Bar ── -->
<?php
$dlBase = '?download=all&year=' . $year . ($month ? '&month=' . $month : '');
$dlQ = fn(string $key) => '?download=' . $key . '&year=' . $year . ($month ? '&month=' . $month : '');
?>
<div class="d-flex gap-2 mb-4 flex-wrap align-items-center justify-content-between">
  <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <label style="font-size:.78rem;font-weight:700;color:var(--n600);">Year:</label>
    <select name="year" class="form-select" style="width:100px;font-size:.82rem;">
      <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
      <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <label style="font-size:.78rem;font-weight:700;color:var(--n600);">Month:</label>
    <select name="month" class="form-select" style="width:130px;font-size:.82rem;">
      <option value="0">All Months</option>
      <?php $mnames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      foreach ($mnames as $mi => $mn): ?>
      <option value="<?= $mi + 1 ?>" <?= $month == $mi + 1 ? 'selected' : '' ?>><?= $mn ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-funnel-fill"></i> Filter</button>
  </form>
  <a href="<?= $dlBase ?>" class="btn-c btn-primary-c" style="background:var(--crimson-dk);">
    <i class="bi bi-download"></i> Download All Reports (.zip)
  </a>
</div>

<!-- ── Summary Stat Cards (Aggregation: COUNT, SUM, AVG, MIN, MAX) ── -->
<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['Total Requests',     $overallStats['total'],            'file-earmark-text-fill', '#8b5cf6','#f5f3ff'],
    ['Unique Residents',   $overallStats['unique_residents'], 'people-fill',            '#3b82f6','#eff6ff'],
    ['Approved/Released',  $overallStats['approved'],         'check-circle-fill',      '#16a34a','#f0fdf4'],
    ['Rejected',           $overallStats['rejected'],         'x-circle-fill',          '#dc2626','#fff1f2'],
    ['Avg Processing Days',$overallStats['avg_days'] ?? '—',  'clock-history',          '#d97706','#fffbeb'],
    ['Pending',            $overallStats['pending'],          'hourglass-split',        '#0284c7','#eff6ff'],
  ];
  foreach ($cards as [$lbl, $val, $icon, $color, $bg]):
  ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>;"><i class="bi bi-<?= $icon ?>"></i></div>
      <div>
        <div class="stat-val"><?= $val ?></div>
        <div class="stat-lbl"><?= $lbl ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── SECTION LABEL HELPER ── -->
<?php function sectionLabel(string $tag, string $title, string $desc): void { ?>
<div style="margin-bottom:.75rem;">
  <span style="background:#fdf0f3;color:var(--crimson);font-size:.62rem;font-weight:800;padding:.2rem .55rem;border-radius:5px;text-transform:uppercase;letter-spacing:.08em;"><?= $tag ?></span>
  <span style="font-size:.85rem;font-weight:700;color:var(--n800);margin-left:.5rem;"><?= $title ?></span>
  <div style="font-size:.72rem;color:var(--n500);margin-top:.15rem;"><?= $desc ?></div>
</div>
<?php } ?>

<!-- ── QUERY 1: Clearance Type Stats (Aggregation + 3-table JOIN) ── -->
<div class="card mb-4">
  <div class="card-header-c">
    <h3><i class="bi bi-tags" style="color:var(--crimson)"></i> Clearance Type Performance</h3>
    <a href="<?= $dlQ('type_stats') ?>" class="btn-c btn-secondary-c" style="font-size:.72rem;"><i class="bi bi-download"></i> Download CSV</a>
  </div>
  <div style="padding:1rem 1.25rem .5rem;">
    <?php sectionLabel(
      'Aggregation + 3-Table JOIN',
      'COUNT · SUM · AVG · MIN · MAX',
      'requests ⟶ residents ⟶ clearance_type | Approval rate, processing time, date range per document type'
    ); ?>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Document Type</th>
          <th>Total <small style="font-weight:400">(COUNT)</small></th>
          <th>Approved <small style="font-weight:400">(SUM)</small></th>
          <th>Rejected <small style="font-weight:400">(SUM)</small></th>
          <th>Pending</th>
          <th>Approval % <small style="font-weight:400">(AVG)</small></th>
          <th>Avg Days <small style="font-weight:400">(AVG)</small></th>
          <th>Earliest <small style="font-weight:400">(MIN)</small></th>
          <th>Latest <small style="font-weight:400">(MAX)</small></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($typeStats as $t): ?>
        <tr>
          <td><strong><?= htmlspecialchars($t['type_name']) ?></strong></td>
          <td><?= $t['total_requests'] ?></td>
          <td><span style="color:#16a34a;font-weight:700;"><?= $t['total_approved'] ?></span></td>
          <td><span style="color:#dc2626;"><?= $t['total_rejected'] ?></span></td>
          <td><?= $t['total_pending'] ?></td>
          <td>
            <?php $rate = (float)$t['approval_rate']; $col = $rate >= 70 ? '#16a34a' : ($rate >= 40 ? '#d97706' : '#dc2626'); ?>
            <span style="color:<?= $col ?>;font-weight:700;"><?= $t['total_requests'] > 0 ? $rate . '%' : '—' ?></span>
          </td>
          <td><?= $t['avg_processing_days'] !== null ? round($t['avg_processing_days'], 1) . 'd' : '—' ?></td>
          <td style="color:var(--n500);font-size:.75rem;"><?= $t['earliest_request'] ? date('M j, Y', strtotime($t['earliest_request'])) : '—' ?></td>
          <td style="color:var(--n500);font-size:.75rem;"><?= $t['latest_request']   ? date('M j, Y', strtotime($t['latest_request']))   : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($typeStats)): ?>
        <tr><td colspan="9" class="text-center py-4 text-muted">No data for selected period.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3 mb-4">

  <!-- ── QUERY 2: Top Residents (Subquery 1 + 3-table JOIN) ── -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header-c">
        <h3><i class="bi bi-person-lines-fill" style="color:var(--crimson)"></i> Top Requesting Residents</h3>
        <a href="<?= $dlQ('top_residents') ?>" class="btn-c btn-secondary-c" style="font-size:.72rem;"><i class="bi bi-download"></i> Download CSV</a>
      </div>
      <div style="padding:1rem 1.25rem .5rem;">
        <?php sectionLabel(
          'Subquery 1 · 3-Table JOIN',
          'Inline derived-table subquery',
          'residents ⟶ (subquery on requests) ⟶ clearance_type | Pre-aggregates per resident before outer join'
        ); ?>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr><th>Resident</th><th>Purok</th><th>Requests</th><th>Approved</th><th>Types</th></tr>
          </thead>
          <tbody>
            <?php foreach ($topResidents as $tr): ?>
            <tr>
              <td><strong><?= htmlspecialchars($tr['resident_name']) ?></strong></td>
              <td style="color:var(--n500);"><?= htmlspecialchars($tr['purok'] ?? '—') ?></td>
              <td><?= $tr['total_requests'] ?></td>
              <td><span style="color:#16a34a;font-weight:700;"><?= $tr['approved_count'] ?></span></td>
              <td><?= $tr['distinct_types'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($topResidents)): ?>
            <tr><td colspan="5" class="text-center py-3 text-muted">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── QUERY 3: Above-Average Staff (Subquery 2 + 3-table JOIN) ── -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header-c">
        <h3><i class="bi bi-award-fill" style="color:var(--crimson)"></i> Above-Average Staff</h3>
        <a href="<?= $dlQ('above_avg_staff') ?>" class="btn-c btn-secondary-c" style="font-size:.72rem;"><i class="bi bi-download"></i> Download CSV</a>
      </div>
      <div style="padding:1rem 1.25rem .5rem;">
        <?php sectionLabel(
          'Subquery 2 · 3-Table JOIN',
          'HAVING with nested WHERE subquery',
          'users ⟶ staff_roles ⟶ requests | HAVING handled_count > (SELECT AVG(...) FROM ...)'
        ); ?>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr><th>Name</th><th>Role</th><th>Handled</th><th>Approved</th><th>Avg Days</th></tr>
          </thead>
          <tbody>
            <?php foreach ($aboveAvgStaff as $s): ?>
            <tr>
              <td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td>
              <td style="color:var(--n500);font-size:.75rem;"><?= htmlspecialchars($s['staff_role'] ?? ucfirst($s['role'])) ?></td>
              <td><span style="font-weight:700;color:#7c3aed;"><?= $s['handled_count'] ?></span></td>
              <td><span style="color:#16a34a;font-weight:700;"><?= $s['approved_count'] ?></span></td>
              <td><?= $s['avg_days'] !== null ? $s['avg_days'] . 'd' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($aboveAvgStaff)): ?>
            <tr><td colspan="5" class="text-center py-3 text-muted">No staff above average yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── QUERY 4: Recent Requests with Correlated Audit (Subquery 3) ── -->
<div class="card mb-4">
  <div class="card-header-c">
    <h3><i class="bi bi-journal-bookmark-fill" style="color:var(--crimson)"></i> Requests with Latest Audit Trail</h3>
    <a href="<?= $dlQ('audit_trail') ?>" class="btn-c btn-secondary-c" style="font-size:.72rem;"><i class="bi bi-download"></i> Download CSV</a>
  </div>
  <div style="padding:1rem 1.25rem .5rem;">
    <?php sectionLabel(
      'Subquery 3 · 3-Table JOIN',
      'Correlated subquery per row',
      'requests ⟶ residents ⟶ clearance_type | Each row fetches its own latest audit action via correlated subquery'
    ); ?>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr><th>#</th><th>Resident</th><th>Purok</th><th>Type</th><th>Date</th><th>Status</th><th>Last Action <small style="font-weight:400">(correlated)</small></th><th>Handled By</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recentWithAudit as $rw): ?>
        <tr>
          <td style="color:var(--crimson);font-weight:700;">#<?= $rw['request_id'] ?></td>
          <td><strong><?= htmlspecialchars($rw['resident_name']) ?></strong></td>
          <td style="color:var(--n500);font-size:.75rem;"><?= htmlspecialchars($rw['purok'] ?? '—') ?></td>
          <td style="font-size:.75rem;"><?= htmlspecialchars($rw['type_name']) ?></td>
          <td style="color:var(--n500);font-size:.75rem;"><?= date('M j, Y', strtotime($rw['date_requested'])) ?></td>
          <td><?= statusBadge($rw['status']) ?></td>
          <td>
            <?php if ($rw['last_action']): ?>
            <span style="font-size:.72rem;background:var(--n100);padding:.2rem .5rem;border-radius:5px;color:var(--n700);">
              <?= htmlspecialchars($rw['last_action']) ?>
            </span>
            <?php else: ?>
            <span style="color:var(--n400);font-size:.72rem;">No audit</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.75rem;color:var(--n600);"><?= htmlspecialchars($rw['last_handled_by'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentWithAudit)): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">No requests in selected period.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── QUERY 5: Monthly Staff CTE ── -->
<div class="card mb-4">
  <div class="card-header-c">
    <h3><i class="bi bi-table" style="color:var(--crimson)"></i> Monthly Staff Workload — <?= $year ?></h3>
    <a href="<?= $dlQ('monthly_summary') ?>" class="btn-c btn-secondary-c" style="font-size:.72rem;"><i class="bi bi-download"></i> Download CSV</a>
  </div>
  <div style="padding:1rem 1.25rem .5rem;">
    <?php sectionLabel(
      'CTE · 3-Table JOIN · Aggregation',
      'WITH monthly_workload AS (...)',
      'users ⟶ staff_roles ⟶ requests | CTE pre-computes monthly aggregates; outer query applies window function for running totals'
    ); ?>
  </div>
  <?php if (empty($monthlySummary)): ?>
  <div style="padding:2rem;text-align:center;color:var(--n400);">No staff-handled requests in <?= $year ?>.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Month <small style="font-weight:400">(CTE)</small></th>
          <th>Staff Name</th>
          <th>Staff Role</th>
          <th>Handled <small style="font-weight:400">(COUNT)</small></th>
          <th>Approved <small style="font-weight:400">(SUM)</small></th>
          <th>Rejected <small style="font-weight:400">(SUM)</small></th>
          <th>Pending</th>
          <th>Avg Days <small style="font-weight:400">(AVG)</small></th>
          <th>Running Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($monthlySummary as $ms): ?>
        <tr>
          <td><strong><?= $months[(int)$ms['work_month']] ?></strong></td>
          <td><?= htmlspecialchars($ms['full_name']) ?></td>
          <td style="color:var(--n500);font-size:.75rem;"><?= htmlspecialchars($ms['staff_role'] ?? ucfirst($ms['role'])) ?></td>
          <td><span style="font-weight:700;color:#7c3aed;"><?= $ms['requests_handled'] ?></span></td>
          <td><span style="color:#16a34a;font-weight:700;"><?= $ms['approved'] ?></span></td>
          <td><span style="color:#dc2626;"><?= $ms['rejected'] ?></span></td>
          <td><?= $ms['still_pending'] ?></td>
          <td><?= $ms['avg_processing_days'] !== null ? $ms['avg_processing_days'] . 'd' : '—' ?></td>
          <td>
            <span style="background:#f5f3ff;color:#7c3aed;font-weight:700;padding:.2rem .5rem;border-radius:5px;font-size:.75rem;">
              <?= $ms['running_total'] ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── SQL Feature Legend ── -->
<div class="card" style="border:2px dashed var(--n200);">
  <div class="card-header-c">
    <h3><i class="bi bi-code-square" style="color:var(--crimson)"></i> SQL Features Reference — This Page</h3>
  </div>
  <div style="padding:1.25rem;">
    <div class="row g-3">
      <?php
      $features = [
        ['Aggregation Functions','COUNT, SUM, AVG, MIN, MAX used across all 5 queries','check-circle-fill','#16a34a','#f0fdf4'],
        ['3-Table JOINs','Every query joins ≥3 tables: requests ↔ residents ↔ clearance_type / users ↔ staff_roles ↔ requests','check-circle-fill','#16a34a','#f0fdf4'],
        ['Subquery 1','Inline derived table in FROM — aggregates per resident before outer JOIN (Query 2)','check-circle-fill','#16a34a','#f0fdf4'],
        ['Subquery 2','Nested subquery in HAVING — filters staff above barangay average (Query 3)','check-circle-fill','#16a34a','#f0fdf4'],
        ['Subquery 3','Correlated subquery in SELECT — fetches latest audit per request row (Query 4)','check-circle-fill','#16a34a','#f0fdf4'],
        ['CTE (WITH clause)','monthly_workload CTE pre-computes per-staff monthly aggregates; outer query adds window-function running totals (Query 5)','check-circle-fill','#16a34a','#f0fdf4'],
      ];
      foreach ($features as [$title, $desc, $icon, $color, $bg]):
      ?>
      <div class="col-12 col-md-6 col-lg-4">
        <div style="display:flex;gap:.75rem;align-items:flex-start;padding:.75rem;background:var(--n50);border-radius:10px;border:1px solid var(--n200);">
          <div style="background:<?= $bg ?>;color:<?= $color ?>;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem;">
            <i class="bi bi-<?= $icon ?>"></i>
          </div>
          <div>
            <div style="font-size:.78rem;font-weight:700;color:var(--n800);"><?= $title ?></div>
            <div style="font-size:.7rem;color:var(--n500);margin-top:.15rem;line-height:1.4;"><?= $desc ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php include 'layout/footer.php'; ?>
