<?php
// setup.php  —  Run ONCE after importing baranggay.sql
// Creates default admin, staff, and resident accounts
require_once 'config.php';

$inserted = [];

// ── Seed staff_roles if table exists ─────────────────────────
$defaultRoles = [
    ['Barangay Captain',   'Head of the barangay'],
    ['Barangay Secretary', 'Administrative officer of the barangay'],
    ['Barangay Treasurer', 'Fiscal officer of the barangay'],
    ['Kagawad',            'Elected council member'],
    ['SK Chairman',        'Sangguniang Kabataan chairman'],
    ['Barangay Clerk',     'General administrative staff'],
];
if (staffRolesTableExists($conn)) {
    foreach ($defaultRoles as [$rname, $rdesc]) {
        $s = $conn->prepare("SELECT role_id FROM staff_roles WHERE role_name=?");
        $s->bind_param('s', $rname); $s->execute();
        if ($s->get_result()->num_rows === 0) {
            $si = $conn->prepare("INSERT INTO staff_roles (role_name,description) VALUES (?,?)");
            $si->bind_param('ss', $rname, $rdesc); $si->execute(); $si->close();
        }
        $s->close();
    }
}

// Get Captain role id for sample staff
$captainId = null;
if (staffRolesTableExists($conn)) {
    $r = $conn->query("SELECT role_id FROM staff_roles WHERE role_name='Barangay Captain' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) $captainId = (int)$row['role_id'];
}

// ── Check new name columns ────────────────────────────────────
$hasNameCols = columnExists($conn, 'users', 'first_name');

// ── Default accounts ──────────────────────────────────────────
$accounts = [
    // [username, password, first, middle, last, role, status, staff_role_id]
    ['admin',     'admin123', 'System',  null,    'Administrator', 'admin',    'active', null],
    ['staff1',    'staff123', 'Maria',   'Reyes', 'Santos',        'staff',    'active', $captainId],
    ['resident1', 'res123',   'Juan',    null,    'Dela Cruz',     'resident', 'active', null],
];

foreach ($accounts as [$username, $plainPw, $first, $middle, $last, $role, $status, $staffRoleId]) {
    $hash      = password_hash($plainPw, PASSWORD_DEFAULT);
    $full_name = trim("$first " . ($middle ? "$middle " : '') . $last);

    $s = $conn->prepare("SELECT user_id FROM users WHERE username=?");
    $s->bind_param('s', $username); $s->execute();
    if ($s->get_result()->num_rows > 0) {
        $inserted[] = "⚠ <code>$username</code> already exists — skipped.";
        $s->close(); continue;
    }
    $s->close();

    if ($hasNameCols) {
        $s = $conn->prepare("INSERT INTO users (username,password,first_name,middle_name,last_name,full_name,role,staff_role_id,status) VALUES (?,?,?,?,?,?,?,?,?)");
        $s->bind_param('sssssssss', $username, $hash, $first, $middle, $last, $full_name, $role, $staffRoleId, $status);
    } else {
        $s = $conn->prepare("INSERT INTO users (username,password,full_name,role,staff_role_id,status) VALUES (?,?,?,?,?,?)");
        $s->bind_param('ssssis', $username, $hash, $full_name, $role, $staffRoleId, $status);
    }
    $s->execute();
    $userId = $conn->insert_id;
    $s->close();

    if ($role === 'resident') {
        $s2 = $conn->prepare("INSERT INTO residents (user_id,first_name,middle_name,last_name,address,contact) VALUES (?,?,?,?,?,?)");
        $addr = 'Purok 1, Zone A';
        $ct   = '09171234567';
        $s2->bind_param('isssss', $userId, $first, $middle, $last, $addr, $ct);
        $s2->execute(); $s2->close();
    }

    $inserted[] = "✅ Created <code>$username</code> (role: <strong>$role</strong>"
                . ($role === 'staff' && $captainId ? ' — Barangay Captain' : '') . ')';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup — Barangay System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:'DM Sans',sans-serif;background:#f9f9fb;padding:2rem;}
.setup-card{background:#fff;border-radius:16px;border:1px solid #e2e2e8;max-width:560px;margin:0 auto;padding:2.5rem;}
code{background:#fdf0f3;color:#7b0020;padding:.1rem .4rem;border-radius:5px;font-weight:600;}
</style>
</head>
<body>
<div class="setup-card">
  <h1 style="font-size:1.4rem;margin-bottom:.5rem;">⚙️ Barangay System Setup</h1>
  <p style="color:#9696a0;font-size:.85rem;margin-bottom:1.5rem;">
    Seeds default accounts and staff roles. Run once after importing the SQL file.
  </p>

  <?php foreach ($inserted as $msg): ?>
  <div style="padding:.6rem .9rem;background:#f9f9fb;border:1px solid #e2e2e8;border-radius:8px;margin-bottom:.5rem;font-size:.85rem;">
    <?= $msg ?>
  </div>
  <?php endforeach; ?>

  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-top:1.25rem;font-size:.82rem;color:#92400e;">
    <strong>⚠ Default Credentials:</strong><br>
    Admin: <code>admin</code> / <code>admin123</code><br>
    Staff (Brgy. Captain): <code>staff1</code> / <code>staff123</code><br>
    Resident: <code>resident1</code> / <code>res123</code><br><br>
    <strong>Delete or secure this file after setup!</strong>
  </div>

  <div style="margin-top:1.5rem;">
    <a href="index.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.25rem;background:#b91c3c;color:#fff;border-radius:9px;text-decoration:none;font-weight:700;font-size:.85rem;">
      → Go to Login
    </a>
  </div>
</div>
</body>
</html>
