<?php
// resident_dashboard.php  —  Resident self-service portal
require_once 'config.php';
requireLogin();
if (!isResident()) { header('Location: dashboard.php'); exit; }

$pageTitle   = 'My Dashboard';
$userId      = currentUserId();
$residentId  = $_SESSION['resident_id'] ?? null;

// Load resident profile
$profile = null;
if ($residentId) {
    $stmt = $conn->prepare("SELECT r.*, u.username, u.status AS account_status, u.created_at AS account_created FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.resident_id=?");
    $stmt->bind_param('i', $residentId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Load requests
$requests = [];
if ($residentId) {
    $requests = $conn->query("
        SELECT r.request_id, ct.type_name, r.date_requested, r.purpose, r.status, r.source, r.created_at
        FROM requests r
        JOIN clearance_type ct ON r.type_id=ct.type_id
        WHERE r.resident_id=$residentId
        ORDER BY r.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Clearance Portal — Barangay System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --crimson:#b91c3c; --crimson-dk:#7b0020; --crimson-lt:#fdf0f3; --n900:#0f0f10; --n800:#1a1a1d; --n600:#44444b; --n400:#9696a0; --n200:#e2e2e8; --n100:#f2f2f5; --n50:#f9f9fb; }
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'DM Sans',sans-serif; background:var(--n100); min-height:100vh; -webkit-font-smoothing:antialiased; }

/* ── TOPBAR ── */
.res-topbar { background:linear-gradient(135deg,#4a0010,#b91c3c); padding:0 2rem; height:60px; display:flex; align-items:center; justify-content:space-between; }
.res-brand { font-family:'DM Serif Display',serif; font-size:1.05rem; color:#fff; display:flex; align-items:center; gap:.65rem; }
.res-brand-icon { width:36px; height:36px; background:rgba(255,255,255,.15); border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1rem; }
.res-nav { display:flex; align-items:center; gap:.5rem; }
.res-nav a { color:rgba(255,255,255,.75); text-decoration:none; font-size:.8rem; padding:.35rem .75rem; border-radius:7px; transition:all .15s; }
.res-nav a:hover, .res-nav a.active { background:rgba(255,255,255,.15); color:#fff; }
.res-nav a.btn-logout { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); }

/* ── LAYOUT ── */
.res-body { max-width:960px; margin:0 auto; padding:2rem 1rem; }

/* ── HERO BANNER ── */
.res-hero { background:linear-gradient(135deg,var(--crimson-dk),var(--crimson)); border-radius:16px; padding:1.75rem 2rem; color:#fff; margin-bottom:1.75rem; display:flex; align-items:center; gap:1.5rem; }
.res-hero-avatar { width:60px; height:60px; background:rgba(255,255,255,.2); border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:1.6rem; flex-shrink:0; }
.res-hero h1 { font-family:'DM Serif Display',serif; font-size:1.5rem; color:#fff; margin-bottom:.15rem; }
.res-hero p { font-size:.8rem; color:rgba(255,255,255,.65); }
.res-hero .acct-badge { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.2); border-radius:99px; padding:.2rem .65rem; font-size:.7rem; font-weight:600; color:rgba(255,255,255,.9); display:inline-flex; align-items:center; gap:.35rem; margin-top:.4rem; }

/* ── CARDS ── */
.card { background:#fff; border-radius:14px; border:1px solid var(--n200); box-shadow:0 1px 4px rgba(0,0,0,.06); }
.card-head { padding:.9rem 1.25rem; border-bottom:1px solid var(--n200); display:flex; align-items:center; justify-content:space-between; }
.card-head h3 { font-size:.85rem; font-weight:700; color:var(--n800); margin:0; display:flex; align-items:center; gap:.45rem; }

/* ── STAT ── */
.stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:1rem; margin-bottom:1.75rem; }
.stat-card { background:#fff; border-radius:12px; border:1px solid var(--n200); padding:1rem 1.25rem; }
.stat-val { font-size:1.7rem; font-weight:800; color:var(--n900); }
.stat-lbl { font-size:.7rem; color:var(--n500); }

/* ── TABLE ── */
.table { font-size:.82rem; margin:0; }
.table thead th { background:var(--n50); font-size:.7rem; font-weight:700; color:var(--n500); text-transform:uppercase; letter-spacing:.06em; border-bottom:2px solid var(--n200); padding:.6rem .9rem; }
.table tbody td { padding:.6rem .9rem; vertical-align:middle; border-bottom:1px solid var(--n100); }
.table tbody tr:last-child td { border-bottom:none; }
.table tbody tr:hover td { background:var(--n50); }

/* ── BADGE ── */
.badge { font-size:.68rem; font-weight:700; padding:.28rem .6rem; border-radius:6px; }

/* ── BTNS ── */
.btn-c { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .9rem; border-radius:8px; font-size:.8rem; font-weight:700; font-family:'DM Sans',sans-serif; cursor:pointer; border:none; transition:all .15s; text-decoration:none; }
.btn-primary-c { background:var(--crimson); color:#fff; }
.btn-primary-c:hover { background:var(--crimson-dk); color:#fff; }
.btn-secondary-c { background:var(--n100); color:var(--n700); border:1px solid var(--n200); }

/* ── ALERT ── */
.pending-alert { background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:10px; padding:1rem 1.25rem; font-size:.83rem; color:#92400e; margin-bottom:1.5rem; }
.form-label { font-size:.73rem; font-weight:700; color:var(--n600); margin-bottom:.3rem; display:block; }
.form-control, .form-select { font-size:.84rem; border:1.5px solid var(--n200); border-radius:9px; width:100%; padding:.55rem .85rem; font-family:'DM Sans',sans-serif; }
.form-control:focus, .form-select:focus { border-color:var(--crimson); box-shadow:0 0 0 3px rgba(185,28,60,.1); outline:none; }
</style>
</head>
<body>

<!-- ── Topbar ── -->
<div class="res-topbar">
  <div class="res-brand">
    <div class="res-brand-icon"><i class="bi bi-building-fill"></i></div>
    Barangay Clearance Portal
  </div>
  <nav class="res-nav">
    <a href="resident_dashboard.php" class="active"><i class="bi bi-house-fill"></i> Home</a>
    <a href="resident_apply.php"><i class="bi bi-file-earmark-plus"></i> Apply</a>
    <a href="resident_profile.php"><i class="bi bi-person-fill"></i> Profile</a>
    <a href="logout.php" class="btn-logout"><i class="bi bi-box-arrow-left"></i> Sign Out</a>
  </nav>
</div>

<div class="res-body">

  <?= getFlash() ?>

  <?php if ($profile && $profile['account_status'] === 'pending'): ?>
  <div class="pending-alert">
    <i class="bi bi-clock-fill me-1"></i>
    <strong>Account Pending Verification.</strong> Your account is awaiting admin approval. Your clearance request has been received and will be processed. You will be notified once your account is verified.
  </div>
  <?php endif; ?>

  <!-- Hero Banner -->
  <div class="res-hero">
    <div class="res-hero-avatar"><i class="bi bi-person-fill"></i></div>
    <div>
      <h1>Welcome, <?= htmlspecialchars($profile['first_name'] ?? $_SESSION['user_name']) ?>!</h1>
      <p>Track your clearance requests and manage your barangay records here.</p>
      <?php if ($profile): ?>
      <span class="acct-badge">
        <i class="bi bi-at"></i> <?= htmlspecialchars($profile['username']) ?>
      </span>
      <span class="acct-badge" style="margin-left:.35rem;">
        <i class="bi bi-house"></i> <?= htmlspecialchars($profile['address']) ?>
      </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <?php
  $counts = ['total'=>0,'pending'=>0,'approved'=>0,'released'=>0];
  foreach ($requests as $r) {
    $counts['total']++;
    if ($r['status']==='pending' || $r['status']==='verified') $counts['pending']++;
    if ($r['status']==='approved') $counts['approved']++;
    if ($r['status']==='released') $counts['released']++;
  }
  ?>
  <div class="stat-row">
    <?php foreach ([
      ['Total Requests',    $counts['total'],    'file-earmark-text','#7c3aed','#f5f3ff'],
      ['In Progress',       $counts['pending'],  'clock',            '#d97706','#fffbeb'],
      ['Approved',          $counts['approved'], 'check-circle',     '#16a34a','#f0fdf4'],
      ['Released',          $counts['released'], 'box-seam',         '#0284c7','#eff6ff'],
    ] as [$lbl,$val,$icon,$col,$bg]): ?>
    <div class="stat-card">
      <div style="width:36px;height:36px;background:<?= $bg ?>;color:<?= $col ?>;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:.6rem;">
        <i class="bi bi-<?= $icon ?>"></i>
      </div>
      <div class="stat-val"><?= $val ?></div>
      <div class="stat-lbl"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <!-- Requests Table -->
    <div class="col-12 col-md-8">
      <div class="card">
        <div class="card-head">
          <h3><i class="bi bi-file-earmark-text" style="color:var(--crimson)"></i> My Clearance Requests</h3>
          <a href="resident_apply.php" class="btn-c btn-primary-c" style="font-size:.75rem;">
            <i class="bi bi-plus-lg"></i> New Request
          </a>
        </div>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr><th>#</th><th>Type</th><th>Purpose</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $r): ?>
              <tr>
                <td><strong style="color:var(--crimson);">#<?= $r['request_id'] ?></strong></td>
                <td><?= htmlspecialchars($r['type_name']) ?></td>
                <td><?= htmlspecialchars($r['purpose']) ?></td>
                <td style="white-space:nowrap;color:var(--n500);"><?= date('M j, Y', strtotime($r['date_requested'])) ?></td>
                <td>
                  <?php
                  $s = $r['status'];
                  $bmap = ['pending'=>'bg-warning text-dark','verified'=>'bg-info text-white','approved'=>'bg-success text-white','released'=>'bg-primary text-white','rejected'=>'bg-danger text-white'];
                  $imap = ['pending'=>'clock','verified'=>'patch-check','approved'=>'check-circle','released'=>'box-seam','rejected'=>'x-circle'];
                  echo '<span class="badge '.($bmap[$s]??'bg-secondary').'"><i class="bi bi-'.($imap[$s]??'question-circle').' me-1"></i>'.ucfirst($s).'</span>';
                  ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($requests)): ?>
              <tr><td colspan="5" class="text-center py-4" style="color:var(--n400);">No requests yet. <a href="resident_apply.php" style="color:var(--crimson);">Apply now</a>.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Quick Info / Profile -->
    <div class="col-12 col-md-4">
      <div class="card">
        <div class="card-head">
          <h3><i class="bi bi-person-fill" style="color:var(--crimson)"></i> My Profile</h3>
          <a href="resident_profile.php" class="btn-c btn-secondary-c" style="font-size:.72rem;"><i class="bi bi-pencil"></i> Edit</a>
        </div>
        <div style="padding:1rem 1.25rem;font-size:.83rem;">
          <?php if ($profile): ?>
          <?php $fields = [
            ['bi-person','Name',    $profile['last_name'].', '.$profile['first_name'].' '.($profile['middle_name']??'')],
            ['bi-geo-alt','Address',$profile['address'].($profile['purok']?' — '.$profile['purok']:'')],
            ['bi-telephone','Contact',$profile['contact']??'—'],
            ['bi-calendar','Birthdate',$profile['birthdate']?date('M j, Y',strtotime($profile['birthdate'])):'—'],
          ];
          foreach ($fields as [$icon,$label,$val]): ?>
          <div style="display:flex;gap:.65rem;padding:.5rem 0;border-bottom:1px solid var(--n100);">
            <i class="bi <?= $icon ?>" style="color:var(--crimson);margin-top:.1rem;flex-shrink:0;"></i>
            <div>
              <div style="font-size:.68rem;color:var(--n400);font-weight:700;text-transform:uppercase;letter-spacing:.04em;"><?= $label ?></div>
              <div style="color:var(--n800);font-weight:500;"><?= htmlspecialchars($val) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <p style="color:var(--n400);">Profile not found. Contact the barangay office.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Status Guide -->
      <div class="card mt-3">
        <div class="card-head"><h3><i class="bi bi-info-circle" style="color:var(--crimson)"></i> Status Guide</h3></div>
        <div style="padding:.75rem 1.25rem;font-size:.78rem;">
          <?php foreach ([
            ['clock','bg-warning text-dark','Pending','Your request was received.'],
            ['patch-check','bg-info text-white','Verified','Documents verified by staff.'],
            ['check-circle','bg-success text-white','Approved','Approved for release.'],
            ['box-seam','bg-primary text-white','Released','Clearance is ready for pickup.'],
            ['x-circle','bg-danger text-white','Rejected','Request was not approved.'],
          ] as [$icon,$cls,$lbl,$desc]): ?>
          <div style="display:flex;align-items:flex-start;gap:.6rem;margin-bottom:.65rem;">
            <span class="badge <?= $cls ?>" style="flex-shrink:0;"><i class="bi bi-<?= $icon ?>"></i> <?= $lbl ?></span>
            <span style="color:var(--n600);"><?= $desc ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
