<?php
// users.php  —  Manage Accounts (Admin only)
// Three roles: Admin, Staff (includes barangay officials), Residents
require_once 'config.php';
requireAdmin();
$pageTitle = 'Manage Accounts';

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$tab    = $_GET['tab'] ?? 'staff';   // active tab: admin | staff | residents | guests

require_once 'mailer.php';

// ── VERIFY RESIDENT (normal signup) ──────────────────────────
if ($action === 'verify' && $id > 0) {
    $now = date('Y-m-d H:i:s');
    $uid = currentUserId();
    // Get user info to send email
    $uRow = $conn->query("SELECT * FROM users WHERE user_id=$id AND role='resident'")->fetch_assoc();
    $stmt = $conn->prepare("UPDATE users SET status='active', verified_at=?, verified_by=? WHERE user_id=? AND role='resident'");
    $stmt->bind_param('sii', $now, $uid, $id);
    $stmt->execute(); $stmt->close();
    if ($uRow && !empty($uRow['email'])) {
        // For signup users their password is already set; send generic approval email
        $body = mailTemplateApproved($uRow['full_name'], $uRow['username'], '(your chosen password)');
        sendMail($uRow['email'], $uRow['full_name'], 'Your Barangay Account Has Been Approved', $body);
    }
    setFlash('success', 'Resident account verified and activated.');
    header('Location: users.php?tab=residents'); exit;
}

// ── APPROVE GUEST APPLICATION ─────────────────────────────────
if ($action === 'approve_guest' && $id > 0) {
    $ga = $conn->query("SELECT * FROM guest_applications WHERE app_id=$id AND status='pending'")->fetch_assoc();
    if ($ga) {
        $tempPw   = 'Brgy@' . rand(1000, 9999);
        $hash     = password_hash($tempPw, PASSWORD_DEFAULT);
        $username = generateUsername($conn, $ga['first_name'], $ga['last_name']);
        $full_name= $ga['full_name'];
        $now      = date('Y-m-d H:i:s');
        $uid      = currentUserId();

        $conn->begin_transaction();
        try {
            // Create user account (active immediately upon approval)
            $stmt = $conn->prepare(
                "INSERT INTO users (username, password, first_name, middle_name, last_name, full_name, email, role, status, signup_source, signup_guest_app_id, verified_at, verified_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'resident', 'active', 'guest_app', ?, ?, ?)"
            );
            $stmt->bind_param('sssssssisi', $username, $hash,
                $ga['first_name'], $ga['middle_name'], $ga['last_name'], $full_name,
                $ga['email'], $id, $now, $uid);
            $stmt->execute();
            $newUserId = $conn->insert_id;
            $stmt->close();

            // Create resident record
            $stmt = $conn->prepare(
                "INSERT INTO residents (user_id, first_name, middle_name, last_name, address, contact, email, birthdate, civil_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issssssss', $newUserId,
                $ga['first_name'], $ga['middle_name'], $ga['last_name'],
                $ga['address'], $ga['contact'], $ga['email'],
                $ga['birthdate'], $ga['civil_status']);
            $stmt->execute();
            $stmt->close();

            // Mark guest application as approved and link user
            $stmt = $conn->prepare(
                "UPDATE guest_applications SET status='approved', linked_user_id=?, reviewed_by=?, reviewed_at=? WHERE app_id=?"
            );
            $stmt->bind_param('iisi', $newUserId, $uid, $now, $id);
            $stmt->execute(); $stmt->close();

            $conn->commit();

            // Send credentials email
            if (!empty($ga['email'])) {
                $body = mailTemplateApproved($full_name, $username, $tempPw);
                sendMail($ga['email'], $full_name, 'Your Barangay Account Has Been Approved', $body);
            }
            setFlash('success', "Guest application approved. Account created for $full_name. Credentials emailed.");
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('error', 'Approval failed: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'Application not found or already processed.');
    }
    header('Location: users.php?tab=guests'); exit;
}

// ── REJECT GUEST APPLICATION ──────────────────────────────────
if ($action === 'reject_guest' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reject_reason'] ?? '');
    $ga     = $conn->query("SELECT * FROM guest_applications WHERE app_id=$id AND status='pending'")->fetch_assoc();
    if ($ga) {
        $now = date('Y-m-d H:i:s');
        $uid = currentUserId();
        $stmt = $conn->prepare(
            "UPDATE guest_applications SET status='rejected', reject_reason=?, reviewed_by=?, reviewed_at=? WHERE app_id=?"
        );
        $stmt->bind_param('sssi', $reason, $uid, $now, $id);
        $stmt->execute(); $stmt->close();

        if (!empty($ga['email'])) {
            $body = mailTemplateRejected($ga['full_name'], $reason);
            sendMail($ga['email'], $ga['full_name'], 'Update on Your Barangay Clearance Application', $body);
        }
        setFlash('success', 'Application rejected. Rejection email sent.');
    }
    header('Location: users.php?tab=guests'); exit;
}

// ── SUSPEND / ACTIVATE ───────────────────────────────────────
if ($action === 'suspend' && $id > 0) {
    $stmt = $conn->prepare("UPDATE users SET status='suspended' WHERE user_id=?");
    $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
    setFlash('success', 'Account suspended.');
    header("Location: users.php?tab=$tab"); exit;
}
if ($action === 'activate' && $id > 0) {
    $stmt = $conn->prepare("UPDATE users SET status='active' WHERE user_id=?");
    $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
    setFlash('success', 'Account activated.');
    header("Location: users.php?tab=$tab"); exit;
}

// ── DELETE USER ──────────────────────────────────────────────
if ($action === 'delete' && $id > 0) {
    if ($id === currentUserId()) {
        setFlash('error', 'You cannot delete your own account.');
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        setFlash('success', 'Account deleted.');
    }
    header("Location: users.php?tab=$tab"); exit;
}

// ── SAVE STAFF / ADMIN ACCOUNT ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $uid          = (int)($_POST['user_id']      ?? 0);
    $username     = trim($_POST['username']      ?? '');
    $first_name   = trim($_POST['first_name']    ?? '');
    $middle_name  = trim($_POST['middle_name']   ?? '') ?: null;
    $last_name    = trim($_POST['last_name']     ?? '');
    $role         = in_array($_POST['role'] ?? '', ['admin','staff']) ? $_POST['role'] : 'staff';
    $staff_role_id= ($role === 'staff' && !empty($_POST['staff_role_id'])) ? (int)$_POST['staff_role_id'] : null;
    $password     = $_POST['password']  ?? '';
    $password2    = $_POST['password2'] ?? '';
    $errors       = [];

    // Build full_name from parts
    $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : '') . $last_name);

    if (!$username)   $errors[] = 'Username is required.';
    if (!$first_name) $errors[] = 'First name is required.';
    if (!$last_name)  $errors[] = 'Last name is required.';
    if ($uid === 0 && $password === '') $errors[] = 'Password is required for new accounts.';
    if ($password !== '' && $password !== $password2) $errors[] = 'Passwords do not match.';
    if ($password !== '' && strlen($password) < 6)   $errors[] = 'Password must be at least 6 characters.';

    // Check duplicate username (exclude current user on edit)
    if (!empty($username)) {
        $dupStmt = $conn->prepare("SELECT user_id FROM users WHERE username=? AND user_id != ?");
        $dupStmt->bind_param('si', $username, $uid);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) $errors[] = 'Username is already taken.';
        $dupStmt->close();
    }

    if (empty($errors)) {
        $target_tab = $role === 'admin' ? 'admin' : 'staff';

        // Check if new name columns exist (schema compatibility)
        $hasFirstName = columnExists($conn, 'users', 'first_name');

        if ($uid > 0) {
            // UPDATE existing account
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hasFirstName) {
                    $stmt = $conn->prepare("UPDATE users SET username=?,first_name=?,middle_name=?,last_name=?,full_name=?,role=?,staff_role_id=?,password=? WHERE user_id=?");
                    $stmt->bind_param('ssssssssi', $username, $first_name, $middle_name, $last_name, $full_name, $role, $staff_role_id, $hash, $uid);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?,full_name=?,role=?,staff_role_id=?,password=? WHERE user_id=?");
                    $stmt->bind_param('sssisi', $username, $full_name, $role, $staff_role_id, $hash, $uid);
                }
            } else {
                if ($hasFirstName) {
                    $stmt = $conn->prepare("UPDATE users SET username=?,first_name=?,middle_name=?,last_name=?,full_name=?,role=?,staff_role_id=? WHERE user_id=?");
                    $stmt->bind_param('ssssssii', $username, $first_name, $middle_name, $last_name, $full_name, $role, $staff_role_id, $uid);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?,full_name=?,role=?,staff_role_id=? WHERE user_id=?");
                    $stmt->bind_param('sssii', $username, $full_name, $role, $staff_role_id, $uid);
                }
            }
        } else {
            // INSERT new account
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hasFirstName) {
                $stmt = $conn->prepare("INSERT INTO users (username,password,first_name,middle_name,last_name,full_name,role,staff_role_id,status) VALUES (?,?,?,?,?,?,?,?,'active')");
                $stmt->bind_param('sssssssi', $username, $hash, $first_name, $middle_name, $last_name, $full_name, $role, $staff_role_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username,password,full_name,role,staff_role_id,status) VALUES (?,?,?,?,?,'active')");
                $stmt->bind_param('ssssi', $username, $hash, $full_name, $role, $staff_role_id);
            }
        }
        $stmt->execute(); $stmt->close();
        setFlash('success', $uid > 0 ? 'Account updated successfully.' : 'Account created successfully.');
        header("Location: users.php?tab=$target_tab"); exit;
    }
    // Re-display form with errors
    $tab = $role === 'admin' ? 'admin' : 'staff';
}

// ── FETCH EDIT ROW ───────────────────────────────────────────
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id=?");
    $stmt->bind_param('i', $id); $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($editRow) {
        $tab = in_array($editRow['role'], ['admin']) ? 'admin' : 'staff';
        // Derive first/middle/last from full_name if new columns absent
        if (empty($editRow['first_name']) && !empty($editRow['full_name'])) {
            $parts = explode(' ', $editRow['full_name'], 3);
            $editRow['first_name']  = $parts[0] ?? '';
            $editRow['middle_name'] = count($parts) === 3 ? $parts[1] : '';
            $editRow['last_name']   = end($parts);
        }
    }
}

// ── SEARCH ───────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$sClause = '';
if ($search) {
    $s = $conn->real_escape_string($search);
    $sClause = " AND (u.full_name LIKE '%$s%' OR u.username LIKE '%$s%')";
}

// ── LOAD DATA ────────────────────────────────────────────────
$admins = $conn->query("
    SELECT u.*, NULL AS staff_role_name, vb.full_name AS verified_by_name
    FROM users u
    LEFT JOIN users vb ON u.verified_by = vb.user_id
    WHERE u.role='admin' $sClause
    ORDER BY u.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

$staffMembers = $conn->query("
    SELECT u.*, sr.role_name AS staff_role_name, vb.full_name AS verified_by_name
    FROM users u
    LEFT JOIN staff_roles sr ON u.staff_role_id = sr.role_id
    LEFT JOIN users vb ON u.verified_by = vb.user_id
    WHERE u.role='staff' $sClause
    ORDER BY sr.role_name ASC, u.full_name ASC
")->fetch_all(MYSQLI_ASSOC);

$residents = $conn->query("
    SELECT u.*, NULL AS staff_role_name, vb.full_name AS verified_by_name
    FROM users u
    LEFT JOIN users vb ON u.verified_by = vb.user_id
    WHERE u.role='resident' $sClause
    ORDER BY FIELD(u.status,'pending','active','suspended'), u.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$allStaffRoles = $conn->query("SELECT * FROM staff_roles WHERE is_active=1 ORDER BY role_name")->fetch_all(MYSQLI_ASSOC);
$pendingCount  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE status='pending' AND role='resident'")->fetch_assoc()['c'];

// Guest applications (no account)
$guestApps = [];
$guestPending = 0;
$gaTableExists = $conn->query("SHOW TABLES LIKE 'guest_applications'")->num_rows > 0;
if ($gaTableExists) {
    $guestApps    = $conn->query("SELECT * FROM guest_applications ORDER BY FIELD(status,'pending','approved','rejected'), created_at DESC")->fetch_all(MYSQLI_ASSOC);
    $guestPending = $conn->query("SELECT COUNT(*) AS c FROM guest_applications WHERE status='pending'")->fetch_assoc()["c"];
}

// Errors from failed POST
$formErrors = isset($errors) ? $errors : [];

include 'layout/header.php';
?>

<!-- ── PAGE HEADER ──────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
  <div>
    <h2 style="font-family:'DM Serif Display',serif;font-size:1.3rem;color:var(--n900);margin:0;">Manage Accounts</h2>
    <p style="font-size:.78rem;color:var(--n500);margin:.2rem 0 0;">Three roles: <strong>Admin</strong>, <strong>Staff</strong> (includes barangay officials), and <strong>Residents</strong>.</p>
  </div>
  <!-- Search -->
  <form method="GET" action="users.php" style="display:flex;gap:.5rem;">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or username…"
           style="padding:.4rem .9rem;border:1.5px solid var(--n200);border-radius:8px;font-size:.8rem;width:210px;">
    <button type="submit" class="btn-c btn-secondary-c">Search</button>
    <?php if ($search): ?>
    <a href="users.php?tab=<?= $tab ?>" class="btn-c btn-secondary-c">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- ── FORM ERRORS ───────────────────────────────────────────── -->
<?php if (!empty($formErrors)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-exclamation-circle-fill me-2"></i>
  <strong>Please fix the following errors:</strong>
  <ul style="margin:.4rem 0 0 1rem;font-size:.82rem;">
    <?php foreach ($formErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── TAB NAVIGATION ───────────────────────────────────────── -->
<div style="display:flex;gap:.35rem;border-bottom:2px solid var(--n200);margin-bottom:1.25rem;">
  <?php
  $tabs = [
      'admin'     => ['icon'=>'shield-fill',       'label'=>'Admin',     'count'=>count($admins)],
      'staff'     => ['icon'=>'person-badge-fill',  'label'=>'Staff',     'count'=>count($staffMembers)],
      'residents' => ['icon'=>'people-fill',         'label'=>'Residents', 'count'=>count($residents), 'alert'=>$pendingCount],
      'guests'    => ['icon'=>'person-plus-fill',  'label'=>'Guest Applications', 'count'=>count($guestApps), 'alert'=>$guestPending],
  ];
  foreach ($tabs as $k => $t):
      $active = $tab === $k;
  ?>
  <a href="users.php?tab=<?= $k ?><?= $search ? '&q='.urlencode($search) : '' ?>"
     style="display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.1rem;font-size:.82rem;font-weight:<?= $active?'700':'500' ?>;
            color:<?= $active?'var(--crimson)':'var(--n500)' ?>;text-decoration:none;
            border-bottom:2px solid <?= $active?'var(--crimson)':'transparent' ?>;margin-bottom:-2px;transition:all .15s;">
    <i class="bi bi-<?= $t['icon'] ?>"></i>
    <?= $t['label'] ?>
    <span style="background:<?= $active?'var(--crimson)':'var(--n200)' ?>;color:<?= $active?'#fff':'var(--n600)' ?>;
                 font-size:.65rem;font-weight:700;padding:.15rem .5rem;border-radius:99px;"><?= $t['count'] ?></span>
    <?php if (!empty($t['alert']) && $t['alert'] > 0): ?>
    <span style="background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:.1rem .45rem;border-radius:99px;">
      <?= $t['alert'] ?> pending
    </span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── MODAL TRIGGER FOR EDIT ────────────────────────────────── -->
<?php if ($editRow && in_array($editRow['role'], ['admin','staff'])): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const m = document.getElementById('staffAdminModal');
  m.querySelector('[name=user_id]').value       = '<?= $editRow['user_id'] ?>';
  m.querySelector('[name=username]').value      = <?= json_encode($editRow['username']) ?>;
  m.querySelector('[name=first_name]').value    = <?= json_encode($editRow['first_name'] ?? '') ?>;
  m.querySelector('[name=middle_name]').value   = <?= json_encode($editRow['middle_name'] ?? '') ?>;
  m.querySelector('[name=last_name]').value     = <?= json_encode($editRow['last_name'] ?? '') ?>;
  m.querySelector('[name=role]').value          = '<?= $editRow['role'] ?>';
  const srField = m.querySelector('[name=staff_role_id]');
  if (srField) srField.value = '<?= $editRow['staff_role_id'] ?? '' ?>';
  toggleStaffRoleField();
  new bootstrap.Modal(m).show();
});
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     TAB: ADMIN
═══════════════════════════════════════════════════════════ -->
<?php if ($tab === 'admin'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">
  <p style="font-size:.8rem;color:var(--n500);margin:0;">Admins have full system access including account management and reports.</p>
  <button class="btn-c btn-primary-c" data-bs-toggle="modal" data-bs-target="#staffAdminModal"
          onclick="resetUserModal(); document.querySelector('#staffAdminModal [name=role]').value='admin'; toggleStaffRoleField();">
    <i class="bi bi-plus-lg"></i> New Admin
  </button>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Name</th><th>Username</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($admins as $u): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($u['full_name']) ?></strong>
            <?php if ($u['user_id'] == currentUserId()): ?>
            <span class="badge bg-secondary" style="font-size:.6rem;">You</span>
            <?php endif; ?>
          </td>
          <td><code style="font-size:.78rem;"><?= htmlspecialchars($u['username']) ?></code></td>
          <td><?= statusPill($u['status']) ?></td>
          <td style="color:var(--n500);font-size:.78rem;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
          <td><?= accountActions($u, $tab) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($admins)): ?>
        <tr><td colspan="5" class="text-center py-4 text-muted">No admin accounts found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB: STAFF
═══════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'staff'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
  <p style="font-size:.8rem;color:var(--n500);margin:0;">Staff members handle clearance requests. Barangay officials (Captain, Secretary, Kagawad, etc.) are managed here.</p>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <!-- Manage Staff Roles button — links to the dedicated Staff Roles page -->
    <a href="staff_roles.php" class="btn-c btn-secondary-c">
      <i class="bi bi-tags-fill"></i> Manage Staff Roles
    </a>
    <button class="btn-c btn-primary-c" data-bs-toggle="modal" data-bs-target="#staffAdminModal"
            onclick="resetUserModal(); document.querySelector('#staffAdminModal [name=role]').value='staff'; toggleStaffRoleField();">
      <i class="bi bi-plus-lg"></i> New Staff Member
    </button>
  </div>
</div>

<div class="card">
  <div class="card-header-c">
    <h3><i class="bi bi-person-badge" style="color:var(--crimson)"></i> Staff Members</h3>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Last Name</th>
          <th>First Name</th>
          <th>Middle Name</th>
          <th>Username</th>
          <th>Barangay Role</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($staffMembers as $u): ?>
        <tr>
          <td><strong><?= htmlspecialchars($u['last_name'] ?? '') ?></strong></td>
          <td><?= htmlspecialchars($u['first_name'] ?? $u['full_name']) ?></td>
          <td style="color:var(--n500);font-size:.78rem;">
            <?= $u['middle_name'] ? htmlspecialchars($u['middle_name']) : '<span style="color:var(--n300);">—</span>' ?>
          </td>
          <td><code style="font-size:.78rem;"><?= htmlspecialchars($u['username']) ?></code></td>
          <td>
            <?php if ($u['staff_role_name']): ?>
            <span style="background:var(--crimson-lt);color:var(--crimson-dk);font-size:.72rem;font-weight:700;padding:.25rem .65rem;border-radius:6px;">
              <?= htmlspecialchars($u['staff_role_name']) ?>
            </span>
            <?php else: ?>
            <span style="color:var(--n400);font-size:.75rem;">— No role assigned</span>
            <?php endif; ?>
          </td>
          <td><?= statusPill($u['status']) ?></td>
          <td><?= accountActions($u, $tab) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($staffMembers)): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">No staff members found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB: RESIDENTS
═══════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'residents'): ?>
<?php if ($pendingCount > 0): ?>
<div style="background:#fef3c7;border:1.5px solid #fcd34d;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.65rem;">
  <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;font-size:1.1rem;"></i>
  <span style="font-size:.82rem;color:#92400e;font-weight:500;">
    <strong><?= $pendingCount ?></strong> resident account<?= $pendingCount > 1 ? 's' : '' ?> waiting for verification.
  </span>
</div>
<?php endif; ?>
<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Name</th><th>Username</th><th>Source</th><th>Status</th><th>Registered</th><th>Verified By</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($residents as $u): ?>
        <tr <?= $u['status']==='pending' ? 'style="background:#fffbeb;"' : '' ?>>
          <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
          <td><code style="font-size:.78rem;"><?= htmlspecialchars($u['username']) ?></code></td>
          <td>
            <?php $src = $u['signup_source'] ?? 'admin'; ?>
            <?php if ($src === 'signup'): ?>
            <span style="background:#e0f2fe;color:#0369a1;font-size:.68rem;font-weight:700;padding:.2rem .55rem;border-radius:6px;"><i class="bi bi-person-plus-fill me-1"></i>Sign-up</span>
            <?php elseif ($src === 'guest_app'): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:.68rem;font-weight:700;padding:.2rem .55rem;border-radius:6px;"><i class="bi bi-file-earmark-fill me-1"></i>Guest App</span>
            <?php else: ?>
            <span style="background:var(--n100);color:var(--n500);font-size:.68rem;font-weight:700;padding:.2rem .55rem;border-radius:6px;">Admin</span>
            <?php endif; ?>
          </td>
          <td><?= statusPill($u['status']) ?></td>
          <td style="color:var(--n500);font-size:.78rem;"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
          <td style="font-size:.78rem;color:var(--n500);">
            <?= $u['verified_by_name'] ? htmlspecialchars($u['verified_by_name']) : '—' ?>
            <?= $u['verified_at'] ? '<br><small>'.date('M j, Y', strtotime($u['verified_at'])).'</small>' : '' ?>
          </td>
          <td>
            <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
              <?php if ($u['status'] === 'pending'): ?>
              <a href="users.php?action=verify&id=<?= $u['user_id'] ?>&tab=residents" class="btn-c btn-success-c" style="font-size:.72rem;"
                 onclick="return confirm('Approve and activate this resident account? A confirmation email will be sent.')">
                <i class="bi bi-check-lg"></i> Approve
              </a>
              <?php endif; ?>
              <?php if ($u['status'] === 'active'): ?>
              <a href="users.php?action=suspend&id=<?= $u['user_id'] ?>&tab=residents" class="btn-c btn-danger-c" style="font-size:.72rem;"
                 onclick="return confirm('Suspend this account?')">
                <i class="bi bi-pause-circle"></i>
              </a>
              <?php elseif ($u['status'] === 'suspended'): ?>
              <a href="users.php?action=activate&id=<?= $u['user_id'] ?>&tab=residents" class="btn-c btn-success-c" style="font-size:.72rem;">
                <i class="bi bi-play-circle"></i>
              </a>
              <?php endif; ?>
              <a href="users.php?action=delete&id=<?= $u['user_id'] ?>&tab=residents" class="btn-c btn-danger-c" style="font-size:.72rem;"
                 onclick="return confirm('Delete this resident account? This cannot be undone.')">
                <i class="bi bi-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($residents)): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">No residents found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB: GUEST APPLICATIONS
═══════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'guests'): ?>
<?php if (!$gaTableExists): ?>
<div class="alert alert-warning">Guest applications table not found. Please run <code>migration.sql</code> first.</div>
<?php else: ?>
<?php if ($guestPending > 0): ?>
<div style="background:#fef3c7;border:1.5px solid #fcd34d;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.65rem;">
  <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;font-size:1.1rem;"></i>
  <span style="font-size:.82rem;color:#92400e;font-weight:500;">
    <strong><?= $guestPending ?></strong> guest application<?= $guestPending > 1 ? 's' : '' ?> pending verification.
  </span>
</div>
<?php endif; ?>
<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Full Name</th>
          <th>Contact</th>
          <th>Email</th>
          <th>Purpose</th>
          <th>Status</th>
          <th>Submitted</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($guestApps as $ga): ?>
        <tr <?= $ga['status']==='pending' ? 'style="background:#fffbeb;"' : '' ?>>
          <td style="font-size:.75rem;color:var(--n400);">#<?= $ga['app_id'] ?></td>
          <td>
            <strong><?= htmlspecialchars($ga['full_name']) ?></strong>
            <?php if ($ga['birthdate']): ?>
            <br><small style="color:var(--n400);"><?= date('M j, Y', strtotime($ga['birthdate'])) ?></small>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;"><?= htmlspecialchars($ga['contact']) ?></td>
          <td style="font-size:.8rem;"><?= htmlspecialchars($ga['email']) ?></td>
          <td style="font-size:.8rem;max-width:140px;"><?= htmlspecialchars($ga['purpose']) ?></td>
          <td>
            <?php if ($ga['status']==='pending'): ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>
            <?php elseif ($ga['status']==='approved'): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>
            <?php else: ?>
            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejected</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.75rem;color:var(--n500);"><?= date('M j, Y', strtotime($ga['created_at'])) ?></td>
          <td>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
              <!-- View Details -->
              <button class="btn-c btn-secondary-c" style="font-size:.7rem;padding:.3rem .6rem;"
                      onclick="viewGuest(<?= htmlspecialchars(json_encode($ga)) ?>)"
                      data-bs-toggle="modal" data-bs-target="#guestDetailModal">
                <i class="bi bi-eye"></i> View
              </button>
              <?php if ($ga['status']==='pending'): ?>
              <!-- Approve -->
              <a href="users.php?action=approve_guest&id=<?= $ga['app_id'] ?>"
                 class="btn-c btn-success-c" style="font-size:.7rem;padding:.3rem .6rem;"
                 onclick="return confirm('Approve this application? An account will be created and credentials emailed.')">
                <i class="bi bi-check-lg"></i> Approve
              </a>
              <!-- Reject -->
              <button class="btn-c btn-danger-c" style="font-size:.7rem;padding:.3rem .6rem;"
                      onclick="openReject(<?= $ga['app_id'] ?>, '<?= htmlspecialchars($ga['full_name'], ENT_QUOTES) ?>')"
                      data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-lg"></i> Reject
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($guestApps)): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">No guest applications yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Guest Detail Modal -->
<div class="modal fade" id="guestDetailModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
        <h5 class="modal-title" style="font-family:'DM Serif Display',serif;color:var(--crimson-dk);">
          <i class="bi bi-person-plus-fill me-2"></i>Guest Application Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="guestDetailBody" style="font-size:.88rem;">
        <!-- Filled by JS -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:#fff1f2;border-bottom:1px solid #fecdd3;">
        <h5 class="modal-title" style="color:#b91c1c;"><i class="bi bi-x-circle-fill me-2"></i>Reject Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="rejectForm">
        <div class="modal-body">
          <p style="font-size:.85rem;color:var(--n600);">Rejecting application for: <strong id="rejectName"></strong></p>
          <div class="mb-3">
            <label class="form-label">Rejection Reason <small style="color:var(--n400);">(optional — will be emailed)</small></label>
            <textarea name="reject_reason" class="form-control" rows="3" placeholder="e.g. Incomplete information, blurry ID image…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-c btn-danger-c"><i class="bi bi-x-circle"></i> Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function viewGuest(ga) {
  const imgHtml = ga.id_image
    ? `<div class="mt-3"><strong style="font-size:.75rem;color:var(--n500);text-transform:uppercase;letter-spacing:.06em;">Uploaded Valid ID</strong><br>
       <a href="serve_id_image.php?file=${encodeURIComponent(ga.id_image)}" target="_blank">
         <img src="serve_id_image.php?file=${encodeURIComponent(ga.id_image)}" style="max-width:100%;max-height:320px;border-radius:9px;margin-top:.5rem;border:2px solid var(--n200);" alt="Valid ID">
       </a>
       <div style="font-size:.72rem;color:var(--n400);margin-top:.25rem;">Click image to open full size</div></div>`
    : '<div class="text-muted mt-2" style="font-size:.82rem;">No ID image uploaded.</div>';

  const rows = [
    ['Full Name', ga.full_name],
    ['Address', ga.address],
    ['Birthdate', ga.birthdate || '—'],
    ['Civil Status', ga.civil_status || '—'],
    ['Contact', ga.contact],
    ['Email', ga.email],
    ['Purpose', ga.purpose],
    ['Status', ga.status.toUpperCase()],
    ['Submitted', ga.created_at],
  ];

  let table = '<table class="table table-sm" style="font-size:.85rem;"><tbody>';
  rows.forEach(([k,v]) => {
    table += `<tr><td style="font-weight:700;color:var(--n600);width:35%;">${k}</td><td>${v}</td></tr>`;
  });
  table += '</tbody></table>';

  if (ga.status === 'rejected' && ga.reject_reason) {
    table += `<div class="alert alert-danger py-2" style="font-size:.82rem;"><strong>Rejection Reason:</strong> ${ga.reject_reason}</div>`;
  }

  document.getElementById('guestDetailBody').innerHTML = table + imgHtml;
}

function openReject(id, name) {
  document.getElementById('rejectForm').action = `users.php?action=reject_guest&id=${id}`;
  document.getElementById('rejectName').textContent = name;
}
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ── STAFF / ADMIN ACCOUNT MODAL ──────────────────────────── -->
<div class="modal fade" id="staffAdminModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
        <h5 class="modal-title" style="font-family:'DM Serif Display',serif;color:var(--crimson-dk);">
          <i class="bi bi-shield-person me-2"></i>
          <span id="modalTitle">Staff / Admin Account</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="users.php">
        <div class="modal-body">
          <input type="hidden" name="save_user" value="1">
          <input type="hidden" name="user_id" value="0">

          <!-- Name fields: separate first / middle / last -->
          <div class="mb-3">
            <label class="form-label">First Name <span style="color:var(--crimson)">*</span></label>
            <input type="text" name="first_name" class="form-control" required
                   placeholder="e.g. Maria">
          </div>
          <div class="mb-3">
            <label class="form-label">Middle Name <span style="font-size:.7rem;color:var(--n400);">(optional)</span></label>
            <input type="text" name="middle_name" class="form-control"
                   placeholder="e.g. Reyes">
          </div>
          <div class="mb-3">
            <label class="form-label">Last Name <span style="color:var(--crimson)">*</span></label>
            <input type="text" name="last_name" class="form-control" required
                   placeholder="e.g. Santos">
          </div>

          <div class="mb-3">
            <label class="form-label">Username <span style="color:var(--crimson)">*</span></label>
            <input type="text" name="username" class="form-control" required
                   placeholder="e.g. msantos">
          </div>
          <div class="mb-3">
            <label class="form-label">System Role <span style="color:var(--crimson)">*</span></label>
            <select name="role" class="form-select" onchange="toggleStaffRoleField(); updateModalTitle();">
              <option value="staff">Staff (includes Barangay Officials)</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="mb-3" id="staffRoleField">
            <label class="form-label">Barangay Position / Role</label>
            <select name="staff_role_id" class="form-select">
              <option value="">— None / General Staff —</option>
              <?php foreach ($allStaffRoles as $sr): ?>
              <option value="<?= $sr['role_id'] ?>"><?= htmlspecialchars($sr['role_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Assign a barangay position if this staff member is an official.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Password <span id="pwRequired" style="color:var(--crimson)">*</span></label>
            <input type="password" name="password" class="form-control" minlength="6"
                   placeholder="Min. 6 characters">
            <div class="form-text" id="pwHint">Leave blank when editing to keep the current password.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="password2" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-save"></i> Save Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleStaffRoleField() {
  const role  = document.querySelector('#staffAdminModal [name=role]').value;
  const field = document.getElementById('staffRoleField');
  if (field) field.style.display = role === 'staff' ? 'block' : 'none';
}

function updateModalTitle() {
  const role  = document.querySelector('#staffAdminModal [name=role]').value;
  const title = document.getElementById('modalTitle');
  const uid   = document.querySelector('#staffAdminModal [name=user_id]').value;
  const isNew = uid === '0' || uid === '';
  title.textContent = (isNew ? 'New ' : 'Edit ') + (role === 'admin' ? 'Admin' : 'Staff') + ' Account';
}

function resetUserModal() {
  const m = document.getElementById('staffAdminModal');
  m.querySelector('[name=user_id]').value     = '0';
  m.querySelector('[name=username]').value    = '';
  m.querySelector('[name=first_name]').value  = '';
  m.querySelector('[name=middle_name]').value = '';
  m.querySelector('[name=last_name]').value   = '';
  m.querySelector('[name=role]').value        = 'staff';
  const sr = m.querySelector('[name=staff_role_id]');
  if (sr) sr.value = '';
  m.querySelector('[name=password]').value    = '';
  m.querySelector('[name=password2]').value   = '';
  const pwHint = document.getElementById('pwHint');
  if (pwHint) pwHint.style.display = 'none';
  updateModalTitle();
}

document.addEventListener('DOMContentLoaded', () => {
  toggleStaffRoleField();
  // Show password hint only when editing
  const modal = document.getElementById('staffAdminModal');
  modal.addEventListener('show.bs.modal', () => {
    const isEdit = modal.querySelector('[name=user_id]').value !== '0';
    const pwHint = document.getElementById('pwHint');
    if (pwHint) pwHint.style.display = isEdit ? 'block' : 'none';
    updateModalTitle();
  });
});
</script>

<?php
function statusPill(string $status): string {
    $map = [
        'active'    => ['bg-success','check-circle','Active'],
        'pending'   => ['bg-warning text-dark','clock','Pending'],
        'suspended' => ['bg-danger','x-circle','Suspended'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['bg-secondary','dash',$status];
    return "<span class=\"badge $cls\"><i class=\"bi bi-$icon me-1\"></i>$label</span>";
}

function accountActions(array $u, string $tab): string {
    $html = '<div style="display:flex;gap:.3rem;flex-wrap:wrap;">';
    if (in_array($u['role'], ['admin','staff'])) {
        $html .= "<a href=\"users.php?action=edit&id={$u['user_id']}&tab=$tab\" class=\"btn-c btn-secondary-c\" style=\"font-size:.72rem;padding:.3rem .65rem;\"><i class=\"bi bi-pencil\"></i> Edit</a>";
    }
    if ($u['status'] === 'active' && $u['user_id'] != currentUserId()) {
        $html .= "<a href=\"users.php?action=suspend&id={$u['user_id']}&tab=$tab\" class=\"btn-c btn-danger-c\" style=\"font-size:.72rem;padding:.3rem .65rem;\" onclick=\"return confirm('Suspend this account?')\"><i class=\"bi bi-pause-circle\"></i></a>";
    } elseif ($u['status'] === 'suspended') {
        $html .= "<a href=\"users.php?action=activate&id={$u['user_id']}&tab=$tab\" class=\"btn-c btn-success-c\" style=\"font-size:.72rem;padding:.3rem .65rem;\"><i class=\"bi bi-play-circle\"></i></a>";
    }
    if ($u['user_id'] != currentUserId()) {
        $html .= "<a href=\"users.php?action=delete&id={$u['user_id']}&tab=$tab\" class=\"btn-c btn-danger-c\" style=\"font-size:.72rem;padding:.3rem .65rem;\" onclick=\"return confirm('Delete this account? This cannot be undone.')\"><i class=\"bi bi-trash\"></i></a>";
    }
    $html .= '</div>';
    return $html;
}
?>

<?php include 'layout/footer.php'; ?>
