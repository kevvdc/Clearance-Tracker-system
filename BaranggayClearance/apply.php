<?php
// apply.php  —  Guest clearance application (no account required)
//               Saves to guest_applications, stores ID image, stays pending.
require_once 'config.php';

// Already logged in as resident → go to resident portal
if (!empty($_SESSION['logged_in']) && isResident()) {
    header('Location: resident_apply.php');
    exit;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Collect fields ────────────────────────────────────────
    $first_name   = trim($_POST['first_name']   ?? '');
    $middle_name  = trim($_POST['middle_name']  ?? '') ?: null;
    $last_name    = trim($_POST['last_name']    ?? '');
    $address      = trim($_POST['address']      ?? '');
    $birthdate    = trim($_POST['birthdate']    ?? '') ?: null;
    $contact      = trim($_POST['contact']      ?? '');
    $email        = trim($_POST['email']        ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '') ?: null;
    $purpose      = trim($_POST['purpose']      ?? '');

    // ── Validate text fields ──────────────────────────────────
    if ($first_name === '') $errors[] = 'First name is required.';
    if ($last_name  === '') $errors[] = 'Last name is required.';
    if ($address    === '') $errors[] = 'Address is required.';
    if ($contact    === '') $errors[] = 'Contact number is required.';
    if ($email      === '') $errors[] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($purpose    === '') $errors[] = 'Purpose of clearance is required.';

    // ── ID Image upload validation ────────────────────────────
    $id_image_filename = null;
    if (empty($_FILES['id_image']['name'])) {
        $errors[] = 'Valid ID image is required.';
    } elseif ($_FILES['id_image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload error (code: ' . $_FILES['id_image']['error'] . '). Please try again.';
    } else {
        $allowed = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5 MB
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $_FILES['id_image']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $errors[] = 'Only JPG, PNG, GIF, or WEBP images are accepted for the ID.';
        } elseif ($_FILES['id_image']['size'] > $maxSize) {
            $errors[] = 'ID image must be under 5 MB.';
        } else {
            $ext = match($mime) {
                'image/jpeg','image/jpg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                default      => 'jpg'
            };
            $uploadDir = __DIR__ . '/uploads/id_images/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $id_image_filename = 'id_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $uploadDir . $id_image_filename)) {
                $errors[] = 'Could not save the uploaded image. Please try again.';
                $id_image_filename = null;
            }
        }
    }

    // ── Save to guest_applications table ──────────────────────
    if (empty($errors)) {
        $full_name = buildFullName($first_name, $middle_name ?? '', $last_name);
        $stmt = $conn->prepare(
            "INSERT INTO guest_applications
               (full_name, first_name, middle_name, last_name,
                address, birthdate, contact, email, civil_status,
                purpose, id_image, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->bind_param('sssssssssss',
            $full_name, $first_name, $middle_name, $last_name,
            $address, $birthdate, $contact, $email, $civil_status,
            $purpose, $id_image_filename
        );
        if ($stmt->execute()) {
            $success = true;
        } else {
            $errors[] = 'Database error. Please try again later.';
            // Clean up uploaded image on DB failure
            if ($id_image_filename && file_exists(__DIR__ . '/uploads/id_images/' . $id_image_filename)) {
                unlink(__DIR__ . '/uploads/id_images/' . $id_image_filename);
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Apply for Barangay Clearance — No Account Needed</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --crimson:#b91c3c;--crimson-dk:#7b0020;--crimson-lt:#fdf0f3;
  --n900:#0f0f10;--n800:#1a1a1d;--n600:#44444b;--n500:#6b6b75;--n400:#9696a0;
  --n300:#c4c4cc;--n200:#e2e2e8;--n100:#f2f2f5;--n50:#f9f9fb;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--n100);min-height:100vh;color:var(--n800);}

.topbar{background:linear-gradient(135deg,#4a0010,var(--crimson));padding:1rem 2rem;display:flex;align-items:center;gap:1rem;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.2);}
.topbar-back{color:rgba(255,255,255,.8);text-decoration:none;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.4rem;transition:color .15s;}
.topbar-back:hover{color:#fff;}
.topbar-title{font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;flex:1;}

.page-wrap{max-width:840px;margin:2rem auto;padding:0 1.25rem 4rem;}

.section-card{background:#fff;border-radius:14px;border:1.5px solid var(--n200);margin-bottom:1.5rem;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05);}
.section-head{background:var(--crimson-lt);border-bottom:1.5px solid #fbd0d9;padding:.85rem 1.5rem;display:flex;align-items:center;gap:.6rem;}
.section-head i{color:var(--crimson);font-size:1rem;}
.section-head h2{font-family:'DM Serif Display',serif;font-size:.95rem;color:var(--crimson-dk);margin:0;}
.section-body{padding:1.5rem;}

.form-label{font-size:.73rem;font-weight:700;color:var(--n600);margin-bottom:.3rem;display:block;letter-spacing:.02em;}
.form-control,.form-select{font-size:.87rem;border:1.5px solid var(--n200);border-radius:9px;padding:.6rem .9rem;font-family:'DM Sans',sans-serif;color:var(--n800);}
.form-control:focus,.form-select:focus{border-color:var(--crimson);box-shadow:0 0 0 3px rgba(185,28,60,.1);outline:none;}
.req{color:var(--crimson);}

.upload-zone{border:2px dashed var(--n300);border-radius:12px;padding:1.75rem 1.5rem;text-align:center;cursor:pointer;transition:all .18s;background:var(--n50);user-select:none;}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--crimson);background:var(--crimson-lt);}
.upload-zone .uz-icon{font-size:2rem;color:var(--n400);display:block;margin-bottom:.5rem;}
.upload-zone .uz-label{font-size:.88rem;font-weight:700;color:var(--n600);margin-bottom:.2rem;}
.upload-zone .uz-sub{font-size:.75rem;color:var(--n400);}
#id_preview{max-width:100%;max-height:200px;border-radius:9px;margin-top:.75rem;display:none;border:2px solid var(--n200);}
#id_image{display:none;}

.btn-submit{padding:.72rem 2.25rem;background:linear-gradient(135deg,var(--crimson),var(--crimson-dk));border:none;border-radius:10px;color:#fff;font-size:.9rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:.55rem;transition:all .18s;box-shadow:0 4px 14px rgba(185,28,60,.3);}
.btn-submit:hover{box-shadow:0 6px 22px rgba(185,28,60,.4);transform:translateY(-1px);}

.info-note{background:var(--n50);border:1.5px solid var(--n200);border-radius:10px;padding:.8rem 1rem;font-size:.78rem;color:var(--n600);display:flex;gap:.55rem;align-items:flex-start;}
.info-note i{color:var(--crimson);flex-shrink:0;margin-top:1px;}

/* Success state */
.success-wrap{max-width:520px;margin:3rem auto;background:#fff;border-radius:20px;border:2px solid #bbf7d0;box-shadow:0 8px 40px rgba(0,0,0,.1);padding:2.5rem 2rem;text-align:center;}
.success-icon{width:72px;height:72px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#16a34a;margin:0 auto 1.25rem;}
.success-wrap h2{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--n900);margin-bottom:.5rem;}
.success-wrap p{color:var(--n600);font-size:.88rem;line-height:1.7;margin-bottom:1rem;}
.info-box-wait{background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:1rem 1.25rem;font-size:.83rem;color:#92400e;text-align:left;margin-bottom:1.25rem;display:flex;gap:.6rem;align-items:flex-start;}

@media(max-width:640px){
  .topbar{padding:.85rem 1rem;}
  .page-wrap{padding:0 .75rem 3rem;margin-top:1rem;}
  .section-body{padding:1rem;}
  .btn-submit{width:100%;justify-content:center;}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="index.php" class="topbar-back"><i class="bi bi-arrow-left"></i> Back</a>
  <span class="topbar-title">Apply for Barangay Clearance</span>
</div>

<div class="page-wrap">

<?php if ($success): ?>

<div class="success-wrap">
  <div class="success-icon"><i class="bi bi-send-check-fill"></i></div>
  <h2>Application Submitted!</h2>
  <p>Your clearance application has been received and is pending review by the barangay administrator.</p>
  <div class="info-box-wait">
    <i class="bi bi-envelope-fill" style="font-size:1.1rem;flex-shrink:0;margin-top:1px;"></i>
    <span>Please wait for an email confirmation once the admin verifies your information and your account has been generated. Check your inbox and spam folder at the email you provided.</span>
  </div>
  <a href="index.php" class="btn-submit" style="text-decoration:none;width:fit-content;margin:0 auto;">
    <i class="bi bi-house-fill"></i> Return to Home
  </a>
</div>

<?php else: ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger border-start border-danger border-4 rounded-3 mb-3" style="font-size:.875rem;">
  <strong><i class="bi bi-exclamation-circle-fill me-1"></i>Please fix the following:</strong>
  <ul class="mb-0 mt-2 ps-3">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="POST" action="apply.php" enctype="multipart/form-data" novalidate>

  <!-- Personal Information -->
  <div class="section-card">
    <div class="section-head">
      <i class="bi bi-person-fill"></i>
      <h2>Personal Information</h2>
    </div>
    <div class="section-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">First Name <span class="req">*</span></label>
          <input type="text" name="first_name" class="form-control" placeholder="e.g. Juan"
                 value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Middle Name</label>
          <input type="text" name="middle_name" class="form-control" placeholder="Optional"
                 value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Last Name <span class="req">*</span></label>
          <input type="text" name="last_name" class="form-control" placeholder="e.g. Dela Cruz"
                 value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Birthdate</label>
          <input type="date" name="birthdate" class="form-control"
                 value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Civil Status</label>
          <select name="civil_status" class="form-select">
            <option value="">Select…</option>
            <?php foreach (['Single','Married','Widowed','Separated'] as $cs): ?>
            <option value="<?= $cs ?>" <?= ($_POST['civil_status']??'')===$cs?'selected':'' ?>><?= $cs ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Contact Number <span class="req">*</span></label>
          <input type="tel" name="contact" class="form-control" placeholder="09XXXXXXXXX"
                 value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label">Address <span class="req">*</span></label>
          <input type="text" name="address" class="form-control"
                 placeholder="e.g. 123 Rizal Street, Barangay San Jose…"
                 value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Email Address <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" placeholder="you@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Purpose of Clearance <span class="req">*</span></label>
          <input type="text" name="purpose" class="form-control"
                 placeholder="e.g. Employment, Scholarship, Travel…"
                 value="<?= htmlspecialchars($_POST['purpose'] ?? '') ?>" required>
        </div>
      </div>
    </div>
  </div>

  <!-- Valid ID Upload -->
  <div class="section-card">
    <div class="section-head">
      <i class="bi bi-card-image"></i>
      <h2>Valid ID Image <span class="req">*</span></h2>
    </div>
    <div class="section-body">
      <div class="upload-zone" id="uploadZone" onclick="document.getElementById('id_image').click()">
        <span class="uz-icon"><i class="bi bi-cloud-upload"></i></span>
        <div class="uz-label">Click or drag &amp; drop your Valid ID here</div>
        <div class="uz-sub">JPG, PNG, GIF or WEBP &bull; Maximum 5 MB</div>
        <img id="id_preview" src="" alt="ID Preview">
      </div>
      <input type="file" name="id_image" id="id_image" accept="image/*" required
             onchange="previewId(this)">
      <div class="info-note mt-3">
        <i class="bi bi-shield-lock-fill"></i>
        <span>Your ID image is stored securely and is only accessible to authorized barangay staff for identity verification purposes.</span>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="index.php" class="btn btn-outline-secondary rounded-3" style="font-size:.85rem;">
      <i class="bi bi-arrow-left me-1"></i> Back
    </a>
    <button type="submit" class="btn-submit">
      <i class="bi bi-send-fill"></i> Submit Application
    </button>
  </div>

</form>
<?php endif; ?>
</div>

<script>
function previewId(input) {
  const preview = document.getElementById('id_preview');
  const zone    = document.getElementById('uploadZone');
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      preview.src = e.target.result;
      preview.style.display = 'block';
      zone.querySelector('.uz-icon i').className = 'bi bi-check-circle-fill text-success';
      zone.querySelector('.uz-label').textContent = input.files[0].name;
      zone.querySelector('.uz-sub').textContent   = (input.files[0].size / 1024).toFixed(0) + ' KB';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// Drag-and-drop
const zone = document.getElementById('uploadZone');
if (zone) {
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('dragover');
    const input = document.getElementById('id_image');
    const dt = e.dataTransfer;
    // Use DataTransfer to set files
    if (dt.files.length) {
      // Create a new FileList-compatible object via a temporary input
      input.files = dt.files;
      previewId(input);
    }
  });
}
</script>
</body>
</html>
