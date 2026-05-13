<?php
// resident_apply.php  —  Apply for clearance (logged-in resident)
require_once 'config.php';
requireLogin();
if (!isResident()) { header('Location: dashboard.php'); exit; }

$userId     = currentUserId();
$residentId = $_SESSION['resident_id'] ?? null;

// Load profile for pre-fill
$profile = null;
if ($residentId) {
    $stmt = $conn->prepare("SELECT * FROM residents WHERE resident_id=?");
    $stmt->bind_param('i', $residentId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$types = getClearanceTypes($conn);
$errors = [];
$success = false;
$requestId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_id = (int)($_POST['type_id'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');

    if (!$type_id) $errors[] = 'Please select a clearance type.';
    if (!$purpose) $errors[] = 'Purpose is required.';
    if (!$residentId) $errors[] = 'Your resident profile was not found. Contact the barangay office.';

    if (empty($errors)) {
        $today     = date('Y-m-d');
        $requestId = insertRequest($conn, $residentId, $type_id, $today, $purpose, 'pending', 'online', $userId);
        $hasAudit  = $conn->query("SHOW TABLES LIKE 'request_audit'")->num_rows > 0;
        if ($hasAudit) logAudit($conn, $requestId, 'Request submitted online by resident', null, 'pending');
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Apply for Clearance — Barangay Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --crimson:#b91c3c; --crimson-dk:#7b0020; --crimson-lt:#fdf0f3; --n900:#0f0f10; --n800:#1a1a1d; --n600:#44444b; --n400:#9696a0; --n200:#e2e2e8; --n100:#f2f2f5; --n50:#f9f9fb; }
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'DM Sans',sans-serif; background:var(--n100); min-height:100vh; -webkit-font-smoothing:antialiased; }
.res-topbar { background:linear-gradient(135deg,#4a0010,#b91c3c); padding:0 2rem; height:60px; display:flex; align-items:center; justify-content:space-between; }
.res-brand { font-family:'DM Serif Display',serif; font-size:1.05rem; color:#fff; display:flex; align-items:center; gap:.65rem; }
.res-brand-icon { width:36px; height:36px; background:rgba(255,255,255,.15); border-radius:9px; display:flex; align-items:center; justify-content:center; }
.res-nav a { color:rgba(255,255,255,.75); text-decoration:none; font-size:.8rem; padding:.35rem .75rem; border-radius:7px; transition:all .15s; }
.res-nav a:hover { background:rgba(255,255,255,.15); color:#fff; }
.page-body { max-width:760px; margin:2rem auto; padding:0 1rem; }
.card { background:#fff; border-radius:14px; border:1px solid var(--n200); box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:1.25rem; }
.card-head { padding:.9rem 1.25rem; border-bottom:1px solid var(--n200); background:var(--crimson-lt); border-radius:14px 14px 0 0; }
.card-head h3 { font-size:.82rem; font-weight:700; color:var(--crimson-dk); margin:0; display:flex; align-items:center; gap:.45rem; }
.card-body-c { padding:1.25rem; }
.form-label { font-size:.75rem; font-weight:700; color:var(--n600); margin-bottom:.3rem; display:block; }
.form-control, .form-select { font-size:.85rem; border:1.5px solid var(--n200); border-radius:9px; width:100%; padding:.6rem .85rem; font-family:'DM Sans',sans-serif; background:#fff; }
.form-control:focus, .form-select:focus { border-color:var(--crimson); box-shadow:0 0 0 3px rgba(185,28,60,.1); outline:none; }
.form-control:read-only { background:var(--n50); color:var(--n600); cursor:not-allowed; }
.mb-f { margin-bottom:.9rem; }
.btn-submit { padding:.75rem 2.5rem; background:linear-gradient(135deg,var(--crimson),var(--crimson-dk)); border:none; border-radius:11px; color:#fff; font-size:.9rem; font-weight:700; font-family:'DM Sans',sans-serif; cursor:pointer; display:flex; align-items:center; gap:.5rem; transition:all .18s; }
.btn-submit:hover { box-shadow:0 6px 20px rgba(185,28,60,.3); transform:translateY(-1px); }
.error-box { background:#fff1f2; border:1px solid #fecdd3; border-left:4px solid var(--crimson); border-radius:10px; padding:.85rem 1rem; font-size:.82rem; color:var(--crimson-dk); margin-bottom:1rem; }
.type-card { border:2px solid var(--n200); border-radius:11px; padding:.85rem 1rem; cursor:pointer; transition:all .18s; margin-bottom:.6rem; display:flex; align-items:center; gap:.75rem; }
.type-card:hover { border-color:var(--crimson); background:var(--crimson-lt); }
.type-card.selected { border-color:var(--crimson); background:var(--crimson-lt); }
.type-card input { display:none; }
.type-icon { width:36px; height:36px; background:var(--crimson-lt); border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1rem; color:var(--crimson); flex-shrink:0; }
.type-card.selected .type-icon { background:var(--crimson); color:#fff; }
.success-card { background:#fff; border-radius:18px; border:1px solid #bbf7d0; text-align:center; padding:3rem 2rem; }
.success-icon { width:68px; height:68px; background:#dcfce7; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.8rem; color:#16a34a; margin:0 auto 1.25rem; }
</style>
</head>
<body>
<div class="res-topbar">
  <div class="res-brand">
    <div class="res-brand-icon"><i class="bi bi-building-fill"></i></div>
    Barangay Clearance Portal
  </div>
  <nav class="res-nav" style="display:flex;gap:.35rem;">
    <a href="resident_dashboard.php"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
    <a href="logout.php"><i class="bi bi-box-arrow-left"></i> Sign Out</a>
  </nav>
</div>

<div class="page-body">
  <?php if ($success): ?>
  <div class="success-card">
    <div class="success-icon"><i class="bi bi-check-lg"></i></div>
    <h2 style="font-family:'DM Serif Display',serif;font-size:1.6rem;margin-bottom:.5rem;">Request Submitted!</h2>
    <p style="color:var(--n500);font-size:.88rem;margin-bottom:1.5rem;">Your clearance request <strong>#<?= $requestId ?></strong> has been received and is now being processed by our staff.</p>
    <div style="display:flex;gap:.75rem;justify-content:center;">
      <a href="resident_dashboard.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.5rem;background:var(--n100);border:1px solid var(--n200);border-radius:9px;color:var(--n800);text-decoration:none;font-weight:700;font-size:.85rem;">
        <i class="bi bi-house"></i> Dashboard
      </a>
      <a href="resident_apply.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.5rem;background:var(--crimson);border:none;border-radius:9px;color:#fff;text-decoration:none;font-weight:700;font-size:.85rem;">
        <i class="bi bi-plus-lg"></i> Another Request
      </a>
    </div>
  </div>

  <?php else: ?>
  <h2 style="font-family:'DM Serif Display',serif;font-size:1.4rem;color:var(--n900);margin-bottom:1.25rem;">Apply for Clearance</h2>

  <?php if (!empty($errors)): ?>
  <div class="error-box"><strong><i class="bi bi-exclamation-circle-fill"></i> Please fix:</strong>
    <ul style="margin:.4rem 0 0 1rem;padding:0;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <form method="POST" action="resident_apply.php">
    <!-- Pre-filled Info (read-only) -->
    <div class="card">
      <div class="card-head"><h3><i class="bi bi-person-check-fill"></i> Your Information (Pre-filled)</h3></div>
      <div class="card-body-c">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars(($profile['first_name']??'').' '.($profile['middle_name']??'').' '.($profile['last_name']??'')) ?>" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Address</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['address']??'') ?>" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Contact</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['contact']??'—') ?>" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Purok / Zone</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['purok']??'—') ?>" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Civil Status</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['civil_status']??'—') ?>" readonly>
          </div>
        </div>
        <p style="font-size:.73rem;color:var(--n400);margin-top:.65rem;"><i class="bi bi-info-circle"></i> Need to update your info? <a href="resident_profile.php" style="color:var(--crimson);">Edit your profile</a>.</p>
      </div>
    </div>

    <!-- Clearance Type Selection -->
    <div class="card">
      <div class="card-head"><h3><i class="bi bi-tags-fill"></i> Select Clearance Type</h3></div>
      <div class="card-body-c">
        <?php foreach ($types as $t): ?>
        <label class="type-card <?= ($_POST['type_id']??'')==$t['type_id']?'selected':'' ?>">
          <input type="radio" name="type_id" value="<?= $t['type_id'] ?>" <?= ($_POST['type_id']??'')==$t['type_id']?'checked':'' ?> required>
          <div class="type-icon"><i class="bi bi-file-earmark-check-fill"></i></div>
          <div>
            <div style="font-weight:700;font-size:.88rem;color:var(--n900);"><?= htmlspecialchars($t['type_name']) ?></div>
            <?php if ($t['description']): ?>
            <div style="font-size:.75rem;color:var(--n500);"><?= htmlspecialchars($t['description']) ?></div>
            <?php endif; ?>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Purpose -->
    <div class="card">
      <div class="card-head"><h3><i class="bi bi-chat-left-text-fill"></i> Purpose of Request</h3></div>
      <div class="card-body-c">
        <label class="form-label">Purpose <span style="color:var(--crimson)">*</span></label>
        <input type="text" name="purpose" class="form-control" placeholder="e.g. Employment, Scholarship, Bank Loan, School Requirement…" value="<?= htmlspecialchars($_POST['purpose']??'') ?>" required>
        <p style="font-size:.73rem;color:var(--n400);margin-top:.45rem;">Be specific about the purpose so the staff can process your request accurately.</p>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:2rem;">
      <button type="submit" class="btn-submit">
        <i class="bi bi-send-fill"></i> Submit Request
      </button>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
// Type card toggle highlight
document.querySelectorAll('.type-card').forEach(card => {
  card.addEventListener('click', () => {
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    card.querySelector('input').checked = true;
  });
});
</script>
</body>
</html>
