<?php
// signup.php  —  Normal resident registration; stays pending until admin approves
require_once 'config.php';

if (!empty($_SESSION['logged_in'])) {
    $role = $_SESSION['user_role'] ?? '';
    header('Location: ' . ($role === 'resident' ? 'resident_dashboard.php' : 'dashboard.php'));
    exit;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name']  ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '') ?: null;
    $last_name   = trim($_POST['last_name']   ?? '');
    $username    = trim($_POST['username']    ?? '');
    $email       = trim($_POST['email']       ?? '');
    $password    = $_POST['password']  ?? '';
    $password2   = $_POST['password2'] ?? '';
    $address     = trim($_POST['address']     ?? '');
    $contact     = trim($_POST['contact']     ?? '');
    $birthdate   = trim($_POST['birthdate']   ?? '') ?: null;
    $civil_status= trim($_POST['civil_status']?? '') ?: null;

    if ($first_name === '')    $errors[] = 'First name is required.';
    if ($last_name  === '')    $errors[] = 'Last name is required.';
    if ($username   === '')    $errors[] = 'Username is required.';
    elseif (!preg_match('/^[a-zA-Z0-9_]{4,30}$/', $username))
                               $errors[] = 'Username must be 4–30 characters (letters, numbers, underscores).';
    if ($email      === '')    $errors[] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
                               $errors[] = 'Please enter a valid email address.';
    if ($address    === '')    $errors[] = 'Address is required.';
    if ($contact    === '')    $errors[] = 'Contact number is required.';
    if ($password   === '')    $errors[] = 'Password is required.';
    elseif (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $password2)  $errors[] = 'Passwords do not match.';

    // Duplicate username check
    if (empty($errors)) {
        $dup = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $dup->bind_param('s', $username);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) $errors[] = 'That username is already taken.';
        $dup->close();
    }

    if (empty($errors)) {
        $full_name = buildFullName($first_name, $middle_name ?? '', $last_name);
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO users (username, password, first_name, middle_name, last_name, full_name, email, role, status, signup_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'resident', 'pending', 'signup')"
            );
            $stmt->bind_param('sssssss', $username, $hash, $first_name, $middle_name, $last_name, $full_name, $email);
            $stmt->execute();
            $userId = $conn->insert_id;
            $stmt->close();

            // Create residents row so admin can see their details
            $stmt = $conn->prepare(
                "INSERT INTO residents (user_id, first_name, middle_name, last_name, address, contact, email, birthdate, civil_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issssssss', $userId, $first_name, $middle_name, $last_name, $address, $contact, $email, $birthdate, $civil_status);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = true;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Account — Barangay Clearance System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --crimson:#b91c3c;--crimson-dk:#7b0020;--crimson-lt:#fdf0f3;
  --n900:#0f0f10;--n800:#1a1a1d;--n600:#44444b;--n400:#9696a0;
  --n200:#e2e2e8;--n100:#f2f2f5;--n50:#f9f9fb;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--n100);min-height:100vh;}

.topbar{background:linear-gradient(135deg,#4a0010,var(--crimson));padding:1rem 2rem;display:flex;align-items:center;gap:1rem;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.2);}
.topbar-back{color:rgba(255,255,255,.8);text-decoration:none;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.4rem;transition:color .15s;}
.topbar-back:hover{color:#fff;}
.topbar-title{font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;flex:1;}

.page-wrap{max-width:680px;margin:2rem auto;padding:0 1.25rem 4rem;}

.reg-card{background:#fff;border-radius:16px;border:1.5px solid var(--n200);overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.07);}
.reg-card-head{background:var(--crimson-lt);border-bottom:1.5px solid #fbd0d9;padding:1.25rem 1.75rem;}
.reg-card-head h2{font-family:'DM Serif Display',serif;font-size:1.2rem;color:var(--crimson-dk);margin:0;}
.reg-card-head p{font-size:.78rem;color:var(--n400);margin:.25rem 0 0;}
.reg-card-body{padding:1.75rem;}

.form-label{font-size:.73rem;font-weight:700;color:var(--n600);margin-bottom:.3rem;display:block;letter-spacing:.02em;}
.form-control,.form-select{font-size:.87rem;border:1.5px solid var(--n200);border-radius:9px;padding:.6rem .9rem;font-family:'DM Sans',sans-serif;color:var(--n800);}
.form-control:focus,.form-select:focus{border-color:var(--crimson);box-shadow:0 0 0 3px rgba(185,28,60,.1);outline:none;}
.req{color:var(--crimson);}
.section-divider{font-size:.7rem;font-weight:700;color:var(--n400);text-transform:uppercase;letter-spacing:.1em;margin:1.25rem 0 .75rem;padding-bottom:.4rem;border-bottom:1px solid var(--n200);}

.pw-wrap{position:relative;}
.pw-wrap .form-control{padding-right:2.6rem;}
.pw-toggle{position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--n400);cursor:pointer;font-size:.95rem;padding:0;}

.btn-submit{width:100%;padding:.72rem;background:linear-gradient(135deg,var(--crimson),var(--crimson-dk));border:none;border-radius:10px;color:#fff;font-size:.92rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.55rem;transition:all .18s;box-shadow:0 4px 14px rgba(185,28,60,.3);}
.btn-submit:hover{box-shadow:0 6px 22px rgba(185,28,60,.4);transform:translateY(-1px);}

.already-link{text-align:center;font-size:.75rem;color:var(--n400);margin-top:1rem;}
.already-link a{color:var(--crimson);font-weight:700;text-decoration:none;}

/* Success */
.success-wrap{background:#fff;border-radius:20px;border:2px solid #bbf7d0;box-shadow:0 8px 40px rgba(0,0,0,.1);padding:2.5rem 2rem;text-align:center;margin-top:1rem;}
.success-icon{width:72px;height:72px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#16a34a;margin:0 auto 1.25rem;}
.success-wrap h2{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--n900);margin-bottom:.5rem;}
.success-wrap p{color:var(--n600);font-size:.88rem;line-height:1.7;margin-bottom:1rem;}
.info-box-wait{background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:1rem 1.25rem;font-size:.83rem;color:#92400e;text-align:left;margin-bottom:1.25rem;display:flex;gap:.6rem;align-items:flex-start;}

@media(max-width:540px){
  .topbar{padding:.85rem 1rem;}
  .page-wrap{padding:0 .75rem 3rem;margin-top:1rem;}
  .reg-card-body{padding:1.1rem;}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="index.php" class="topbar-back"><i class="bi bi-arrow-left"></i> Back</a>
  <span class="topbar-title">Create Resident Account</span>
</div>

<div class="page-wrap">

<?php if ($success): ?>
<div class="success-wrap">
  <div class="success-icon"><i class="bi bi-person-check-fill"></i></div>
  <h2>Registration Submitted!</h2>
  <p>Your account has been created and is now waiting for verification by the barangay administrator.</p>
  <div class="info-box-wait">
    <i class="bi bi-envelope-fill" style="font-size:1.1rem;flex-shrink:0;margin-top:1px;"></i>
    <span>You will receive an email confirmation once your account is reviewed and activated. Please check your inbox and spam folder.</span>
  </div>
  <a href="index.php" class="btn-submit" style="text-decoration:none;width:fit-content;margin:0 auto;padding:.72rem 2rem;">
    <i class="bi bi-house-fill"></i> Return to Home
  </a>
</div>

<?php else: ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger border-start border-danger border-4 rounded-3 mb-3" style="font-size:.875rem;">
  <strong><i class="bi bi-exclamation-circle-fill me-1"></i>Please fix the following:</strong>
  <ul class="mb-0 mt-2 ps-3">
    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="reg-card">
  <div class="reg-card-head">
    <h2>Resident Registration</h2>
    <p>Fill in your details. Your account will be activated after admin review.</p>
  </div>
  <div class="reg-card-body">
    <form method="POST" action="signup.php" novalidate>

      <div class="section-divider">Personal Information</div>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label">First Name <span class="req">*</span></label>
          <input type="text" name="first_name" class="form-control" placeholder="Juan"
                 value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Middle Name</label>
          <input type="text" name="middle_name" class="form-control" placeholder="Optional"
                 value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Last Name <span class="req">*</span></label>
          <input type="text" name="last_name" class="form-control" placeholder="Dela Cruz"
                 value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Birthdate</label>
          <input type="date" name="birthdate" class="form-control"
                 value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Civil Status</label>
          <select name="civil_status" class="form-select">
            <option value="">Select…</option>
            <?php foreach (['Single','Married','Widowed','Separated'] as $cs): ?>
            <option value="<?= $cs ?>" <?= ($_POST['civil_status']??'')===$cs?'selected':'' ?>><?= $cs ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="section-divider">Contact & Address</div>
      <div class="row g-3 mb-3">
        <div class="col-12">
          <label class="form-label">Address <span class="req">*</span></label>
          <input type="text" name="address" class="form-control" placeholder="e.g. 123 Rizal Street, Brgy…"
                 value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Contact Number <span class="req">*</span></label>
          <input type="tel" name="contact" class="form-control" placeholder="09XXXXXXXXX"
                 value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email Address <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" placeholder="you@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
      </div>

      <div class="section-divider">Account Credentials</div>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label">Username <span class="req">*</span></label>
          <input type="text" name="username" class="form-control" placeholder="e.g. jdelacruz"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required
                 pattern="[a-zA-Z0-9_]{4,30}" autocomplete="username">
          <div class="form-text" style="font-size:.7rem;">4–30 characters, letters/numbers/underscore</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" name="password" id="pw1" class="form-control" placeholder="Min. 6 characters"
                   required minlength="6" autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw('pw1','ico1')"><i class="bi bi-eye" id="ico1"></i></button>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Confirm Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" name="password2" id="pw2" class="form-control" placeholder="Repeat password"
                   required autocomplete="new-password">
            <button type="button" class="pw-toggle" onclick="togglePw('pw2','ico2')"><i class="bi bi-eye" id="ico2"></i></button>
          </div>
        </div>
      </div>

      <button type="submit" class="btn-submit">
        <i class="bi bi-person-plus-fill"></i> Create Account
      </button>

    </form>

    <p class="already-link">
      Already have an account? <a href="index.php">Sign In</a> &bull;
      No account? <a href="apply.php">Apply as Guest</a>
    </p>
  </div>
</div>
<?php endif; ?>
</div>

<script>
function togglePw(id, iconId) {
  const i = document.getElementById(id), ic = document.getElementById(iconId);
  i.type = i.type === 'password' ? 'text' : 'password';
  ic.className = i.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
</body>
</html>
