<?php
// resident_profile.php  —  Resident self-edit profile
require_once 'config.php';
requireLogin();
if (!isResident()) { header('Location: dashboard.php'); exit; }

$userId     = currentUserId();
$residentId = $_SESSION['resident_id'] ?? null;

if (!$residentId) { setFlash('error','Profile not found.'); header('Location: resident_dashboard.php'); exit; }

// Load
$stmt = $conn->prepare("SELECT r.*, u.username, u.email AS account_email FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.resident_id=?");
$stmt->bind_param('i', $residentId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact      = trim($_POST['contact']      ?? '');
    $email        = trim($_POST['email']        ?? '');
    $address      = trim($_POST['address']      ?? '');
    $purok        = trim($_POST['purok']        ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $id_type      = trim($_POST['id_type']      ?? '');
    $id_number    = trim($_POST['id_number']    ?? '');

    // Password change
    $cur_pw  = $_POST['current_password'] ?? '';
    $new_pw  = $_POST['new_password']     ?? '';
    $new_pw2 = $_POST['new_password2']    ?? '';

    if (!$address) $errors[] = 'Address is required.';

    if ($new_pw !== '') {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id=?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!password_verify($cur_pw, $row['password'])) $errors[] = 'Current password is incorrect.';
        if ($new_pw !== $new_pw2)  $errors[] = 'New passwords do not match.';
        if (strlen($new_pw) < 6)   $errors[] = 'New password must be at least 6 characters.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE residents SET contact=?,email=?,address=?,purok=?,civil_status=?,id_type=?,id_number=? WHERE resident_id=?");
        $stmt->bind_param('sssssssi', $contact,$email,$address,$purok,$civil_status,$id_type,$id_number,$residentId);
        $stmt->execute();
        $stmt->close();

        // Update email on user table
        $stmt = $conn->prepare("UPDATE users SET email=? WHERE user_id=?");
        $stmt->bind_param('si', $email, $userId);
        $stmt->execute();
        $stmt->close();

        if ($new_pw !== '') {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();
        }

        setFlash('success', 'Profile updated successfully!');
        header('Location: resident_profile.php');
        exit;
    }

    // Re-merge for display
    $profile = array_merge($profile, compact('contact','email','address','purok','civil_status','id_type','id_number'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Profile — Barangay Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --crimson:#b91c3c; --crimson-dk:#7b0020; --crimson-lt:#fdf0f3; --n900:#0f0f10; --n800:#1a1a1d; --n600:#44444b; --n400:#9696a0; --n200:#e2e2e8; --n100:#f2f2f5; --n50:#f9f9fb; }
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--n100);min-height:100vh;-webkit-font-smoothing:antialiased;}
.res-topbar{background:linear-gradient(135deg,#4a0010,#b91c3c);padding:0 2rem;height:60px;display:flex;align-items:center;justify-content:space-between;}
.res-brand{font-family:'DM Serif Display',serif;font-size:1.05rem;color:#fff;display:flex;align-items:center;gap:.65rem;}
.res-brand-icon{width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:9px;display:flex;align-items:center;justify-content:center;}
.res-nav a{color:rgba(255,255,255,.75);text-decoration:none;font-size:.8rem;padding:.35rem .75rem;border-radius:7px;transition:all .15s;}
.res-nav a:hover{background:rgba(255,255,255,.15);color:#fff;}
.page-body{max-width:720px;margin:2rem auto;padding:0 1rem;}
.card{background:#fff;border-radius:14px;border:1px solid var(--n200);box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:1.25rem;overflow:hidden;}
.card-head{padding:.9rem 1.25rem;border-bottom:1px solid var(--n200);background:var(--crimson-lt);}
.card-head h3{font-size:.82rem;font-weight:700;color:var(--crimson-dk);margin:0;display:flex;align-items:center;gap:.45rem;}
.card-body-c{padding:1.25rem;}
.form-label{font-size:.75rem;font-weight:700;color:var(--n600);margin-bottom:.3rem;display:block;}
.form-control,.form-select{font-size:.85rem;border:1.5px solid var(--n200);border-radius:9px;width:100%;padding:.6rem .85rem;font-family:'DM Sans',sans-serif;}
.form-control:focus,.form-select:focus{border-color:var(--crimson);box-shadow:0 0 0 3px rgba(185,28,60,.1);outline:none;}
.form-control[readonly]{background:var(--n50);color:var(--n600);}
.error-box{background:#fff1f2;border:1px solid #fecdd3;border-left:4px solid var(--crimson);border-radius:10px;padding:.85rem 1rem;font-size:.82rem;color:var(--crimson-dk);margin-bottom:1rem;}
.alert-success-c{background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;border-radius:10px;padding:.85rem 1rem;font-size:.82rem;color:#15803d;margin-bottom:1rem;}
.btn-save{padding:.7rem 2rem;background:linear-gradient(135deg,var(--crimson),var(--crimson-dk));border:none;border-radius:10px;color:#fff;font-size:.88rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem;transition:all .18s;}
.btn-save:hover{box-shadow:0 6px 18px rgba(185,28,60,.28);transform:translateY(-1px);}
</style>
</head>
<body>
<div class="res-topbar">
  <div class="res-brand"><div class="res-brand-icon"><i class="bi bi-building-fill"></i></div>Barangay Clearance Portal</div>
  <nav class="res-nav" style="display:flex;gap:.35rem;">
    <a href="resident_dashboard.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
    <a href="logout.php"><i class="bi bi-box-arrow-left"></i> Sign Out</a>
  </nav>
</div>

<div class="page-body">
  <h2 style="font-family:'DM Serif Display',serif;font-size:1.4rem;color:var(--n900);margin-bottom:1.25rem;"><i class="bi bi-person-fill" style="color:var(--crimson);"></i> My Profile</h2>

  <?= getFlash() ?>

  <?php if (!empty($errors)): ?>
  <div class="error-box"><strong><i class="bi bi-exclamation-circle-fill"></i> Errors:</strong><ul style="margin:.4rem 0 0 1rem;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="POST" action="resident_profile.php">

    <!-- Fixed / Read-only Info -->
    <div class="card">
      <div class="card-head"><h3><i class="bi bi-lock-fill"></i> Identity (Cannot be changed)</h3></div>
      <div class="card-body-c">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">First Name</label><input type="text" class="form-control" value="<?= htmlspecialchars($profile['first_name']) ?>" readonly></div>
          <div class="col-md-4"><label class="form-label">Middle Name</label><input type="text" class="form-control" value="<?= htmlspecialchars($profile['middle_name']??'') ?>" readonly></div>
          <div class="col-md-4"><label class="form-label">Last Name</label><input type="text" class="form-control" value="<?= htmlspecialchars($profile['last_name']) ?>" readonly></div>
          <div class="col-md-4"><label class="form-label">Birthdate</label><input type="text" class="form-control" value="<?= $profile['birthdate']?date('F j, Y',strtotime($profile['birthdate'])):'—' ?>" readonly></div>
          <div class="col-md-4"><label class="form-label">Gender</label><input type="text" class="form-control" value="<?= htmlspecialchars($profile['gender']??'—') ?>" readonly></div>
          <div class="col-md-4"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= htmlspecialchars($profile['username']) ?>" readonly></div>
        </div>
        <p style="font-size:.72rem;color:var(--n400);margin-top:.6rem;"><i class="bi bi-info-circle"></i> Contact the barangay office to correct identity details.</p>
      </div>
    </div>

    <!-- Editable Info -->
    <div class="card">
      <div class="card-head"><h3><i class="bi bi-pencil-fill"></i> Editable Information</h3></div>
      <div class="card-body-c">
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">Address *</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($profile['address']) ?>" required></div>
          <div class="col-md-4"><label class="form-label">Purok / Zone</label><input type="text" name="purok" class="form-control" value="<?= htmlspecialchars($profile['purok']??'') ?>"></div>
          <div class="col-md-4"><label class="form-label">Contact Number</label><input type="tel" name="contact" class="form-control" value="<?= htmlspecialchars($profile['contact']??'') ?>"></div>
          <div class="col-md-4"><label class="form-label">Email Address</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($profile['email']??$profile['account_email']??'') ?>"></div>
          <div class="col-md-4">
            <label class="form-label">Civil Status</label>
            <select name="civil_status" class="form-select">
              <option value="">Select…</option>
              <?php foreach (['Single','Married','Widowed','Separated'] as $cs): ?>
              <option value="<?= $cs ?>" <?= ($profile['civil_status']??'')===$cs?'selected':'' ?>><?= $cs ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">ID Type</label>
            <select name="id_type" class="form-select">
              <option value="">Select…</option>
              <?php foreach (["PhilSys ID","Driver's License","Passport","Voter's ID","SSS ID","GSIS ID","PRC ID","Barangay ID","Other"] as $idt): ?>
              <option value="<?= $idt ?>" <?= ($profile['id_type']??'')===$idt?'selected':'' ?>><?= $idt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">ID Number</label><input type="text" name="id_number" class="form-control" value="<?= htmlspecialchars($profile['id_number']??'') ?>"></div>
        </div>
      </div>
    </div>

    <!-- Password Change -->
    <div class="card">
      <div class="card-head"><h3><i class="bi bi-key-fill"></i> Change Password (Optional)</h3></div>
      <div class="card-body-c">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" autocomplete="current-password"></div>
          <div class="col-md-4"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="6" autocomplete="new-password"></div>
          <div class="col-md-4"><label class="form-label">Confirm New Password</label><input type="password" name="new_password2" class="form-control" autocomplete="new-password"></div>
        </div>
        <p style="font-size:.73rem;color:var(--n400);margin-top:.5rem;">Leave blank to keep your current password.</p>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:2rem;">
      <button type="submit" class="btn-save"><i class="bi bi-save-fill"></i> Save Changes</button>
    </div>
  </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
