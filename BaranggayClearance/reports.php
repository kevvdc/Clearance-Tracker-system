<?php
// reports.php  —  Admin reports & analytics
require_once 'config.php';
requireAdmin();
$pageTitle = 'Reports & Analytics';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0);

$dateWhere      = "YEAR(date_requested)=$year";
$dateWhereAlias = "YEAR(r.date_requested)=$year";
if ($month) {
    $dateWhere      .= " AND MONTH(date_requested)=$month";
    $dateWhereAlias .= " AND MONTH(r.date_requested)=$month";
}

// Check which columns exist (old vs new schema)
$cols = $conn->query("SHOW COLUMNS FROM requests")->fetch_all(MYSQLI_ASSOC);
$colNames = array_column($cols, 'Field');
$hasSource = in_array('source', $colNames);

// Summary — no alias needed here
$summary = $conn->query("SELECT status, COUNT(*) AS cnt FROM requests WHERE $dateWhere GROUP BY status")->fetch_all(MYSQLI_ASSOC);

// Enhanced: 3-table JOIN (clearance_type + requests + residents)
// Aggregation: COUNT, SUM, AVG, MIN, MAX
$byType = $conn->query("
    SELECT ct.type_name, COUNT(r.request_id) AS total,
           SUM(r.status='approved')  AS approved,
           SUM(r.status='released')  AS released,
           SUM(r.status='rejected')  AS rejected,
           ROUND(AVG(DATEDIFF(r.updated_at, r.created_at)), 1) AS avg_days,
           MIN(r.date_requested)     AS first_request,
           MAX(r.date_requested)     AS last_request,
           COUNT(DISTINCT r.resident_id) AS unique_residents
    FROM clearance_type ct
    LEFT JOIN requests r
           ON ct.type_id = r.type_id AND ($dateWhereAlias)
    LEFT JOIN residents res
           ON r.resident_id = res.resident_id
    GROUP BY ct.type_id, ct.type_name ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

$monthly = $conn->query("
    SELECT MONTH(date_requested) AS mon, COUNT(*) AS total,
           SUM(status='approved') AS approved,
           SUM(status='released') AS released
    FROM requests WHERE YEAR(date_requested)=$year
    GROUP BY MONTH(date_requested) ORDER BY mon
")->fetch_all(MYSQLI_ASSOC);

// Only query source if the column exists
if ($hasSource) {
    $sources = $conn->query("SELECT source, COUNT(*) AS cnt FROM requests WHERE $dateWhere GROUP BY source")->fetch_all(MYSQLI_ASSOC);
} else {
    $sources = [['source' => 'online', 'cnt' => $conn->query("SELECT COUNT(*) AS c FROM requests WHERE $dateWhere")->fetch_assoc()['c']]];
}

include 'layout/header.php';
?>

<div class="d-flex gap-2 mb-4 flex-wrap">
  <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <label style="font-size:.78rem;font-weight:700;color:var(--n600);">Year:</label>
    <select name="year" class="form-select" style="width:100px;font-size:.82rem;">
      <?php for ($y=date('Y'); $y>=2023; $y--): ?>
      <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <label style="font-size:.78rem;font-weight:700;color:var(--n600);">Month:</label>
    <select name="month" class="form-select" style="width:130px;font-size:.82rem;">
      <option value="0">All Months</option>
      <?php $months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      foreach ($months as $mi=>$mn): ?>
      <option value="<?= $mi+1 ?>" <?= $month==$mi+1?'selected':'' ?>><?= $mn ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-c btn-primary-c">Filter</button>
  </form>
</div>

<!-- Status Summary Cards -->
<div class="row g-3 mb-4">
  <?php
  $total = array_sum(array_column($summary, 'cnt'));
  $statusMap = ['pending'=>['clock','#d97706','#fffbeb'],'verified'=>['patch-check','#0284c7','#eff6ff'],'approved'=>['check-circle','#16a34a','#f0fdf4'],'released'=>['box-seam','#7c3aed','#f5f3ff'],'rejected'=>['x-circle','#dc2626','#fff1f2']];
  foreach ($summary as $s):
    [$icon,$color,$bg] = $statusMap[$s['status']] ?? ['question-circle','#9696a0','#f9f9fb'];
  ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>;"><i class="bi bi-<?= $icon ?>"></i></div>
      <div><div class="stat-val"><?= $s['cnt'] ?></div><div class="stat-lbl"><?= ucfirst($s['status']) ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="bi bi-stack"></i></div>
      <div><div class="stat-val"><?= $total ?></div><div class="stat-lbl">Total Requests</div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- By Type -->
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header-c"><h3><i class="bi bi-tags" style="color:var(--crimson)"></i> Requests by Clearance Type</h3><small style="color:var(--n500);font-size:.68rem;">3-Table JOIN · COUNT · SUM · AVG · MIN · MAX</small></div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Type</th><th>Total</th><th>Approved</th><th>Released</th><th>Rejected</th><th>Avg Days</th><th>Residents</th></tr></thead>
          <tbody>
            <?php foreach ($byType as $b): ?>
            <tr>
              <td><strong><?= htmlspecialchars($b['type_name']) ?></strong></td>
              <td><?= $b['total'] ?></td>
              <td><span style="color:#16a34a;font-weight:700;"><?= $b['approved'] ?></span></td>
              <td><?= $b['released'] ?></td>
              <td><span style="color:#dc2626;"><?= $b['rejected'] ?></span></td>
              <td style="color:var(--n500);"><?= $b['avg_days'] !== null ? $b['avg_days'].'d' : '—' ?></td>
              <td><?= $b['unique_residents'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Sources & Monthly Trend -->
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header-c"><h3><i class="bi bi-wifi" style="color:var(--crimson)"></i> Request Sources</h3></div>
      <div style="padding:1.25rem;">
        <?php
        $srcTotal = array_sum(array_column($sources,'cnt')) ?: 1;
        foreach ($sources as $s):
          $pct = round($s['cnt']/$srcTotal*100);
          $col = $s['source']==='online'?'#0284c7':'#d97706';
        ?>
        <div style="margin-bottom:.85rem;">
          <div style="display:flex;justify-content:space-between;margin-bottom:.2rem;font-size:.78rem;">
            <span style="font-weight:600;text-transform:capitalize;"><?= $s['source'] ?></span>
            <span style="color:var(--n500);"><?= $s['cnt'] ?> (<?= $pct ?>%)</span>
          </div>
          <div style="background:var(--n100);border-radius:99px;height:8px;overflow:hidden;">
            <div style="background:<?= $col ?>;height:100%;width:<?= $pct ?>%;border-radius:99px;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header-c"><h3><i class="bi bi-calendar3" style="color:var(--crimson)"></i> Monthly Trend (<?= $year ?>)</h3></div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Month</th><th>Total</th><th>Approved</th><th>Released</th></tr></thead>
          <tbody>
            <?php
            $mnames=['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            foreach ($monthly as $m): ?>
            <tr>
              <td><?= $mnames[$m['mon']] ?></td>
              <td><?= $m['total'] ?></td>
              <td><?= $m['approved'] ?></td>
              <td><?= $m['released'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include 'layout/footer.php'; ?>
