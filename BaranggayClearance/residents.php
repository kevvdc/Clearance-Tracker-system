<?php
// residents.php  —  Resident records (Staff + Admin)
require_once 'config.php';
requireStaff();
$pageTitle = 'Residents';

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── DELETE ──────────────────────────────────────────────────
if ($action === 'delete' && $id > 0 && isAdmin()) {
    $conn->query("DELETE FROM residents WHERE resident_id=$id");
    setFlash('success', 'Resident record deleted.');
    header('Location: residents.php');
    exit;
}

// ── SAVE ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_resident'])) {
    $rid          = (int)($_POST['resident_id']  ?? 0);
    $first_name   = trim($_POST['first_name']    ?? '');
    $middle_name  = trim($_POST['middle_name']   ?? '');
    $last_name    = trim($_POST['last_name']     ?? '');
    $birthdate    = trim($_POST['birthdate']     ?? '') ?: null;
    $gender       = trim($_POST['gender']        ?? '') ?: null;
    $civil_status = trim($_POST['civil_status']  ?? '') ?: null;
    $address      = trim($_POST['address']       ?? '');
    $purok        = trim($_POST['purok']         ?? '') ?: null;
    $contact      = trim($_POST['contact']       ?? '') ?: null;
    $email        = trim($_POST['email']         ?? '') ?: null;
    $years        = (int)($_POST['years_in_brgy']?? 0);
    $id_type      = trim($_POST['id_type']       ?? '') ?: null;
    $id_number    = trim($_POST['id_number']     ?? '') ?: null;

    if (!$first_name || !$last_name || !$address) {
        setFlash('error', 'First name, last name, and address are required.');
    } else {
        if ($rid > 0) {
            $stmt = $conn->prepare("UPDATE residents SET first_name=?,middle_name=?,last_name=?,birthdate=?,gender=?,civil_status=?,address=?,purok=?,contact=?,email=?,years_in_brgy=?,id_type=?,id_number=? WHERE resident_id=?");
            $stmt->bind_param('ssssssssssiisi', $first_name,$middle_name,$last_name,$birthdate,$gender,$civil_status,$address,$purok,$contact,$email,$years,$id_type,$id_number,$rid);
            // Sync full_name (and name parts if columns exist) in linked user row
            $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : '') . $last_name);
            $hasNameCols = columnExists($conn, 'users', 'first_name');
            if ($hasNameCols) {
                $s2 = $conn->prepare("UPDATE users u JOIN residents r ON r.user_id=u.user_id SET u.first_name=?,u.middle_name=?,u.last_name=?,u.full_name=? WHERE r.resident_id=?");
                $s2->bind_param('ssssi', $first_name, $middle_name, $last_name, $full_name, $rid);
            } else {
                $s2 = $conn->prepare("UPDATE users u JOIN residents r ON r.user_id=u.user_id SET u.full_name=? WHERE r.resident_id=?");
                $s2->bind_param('si', $full_name, $rid);
            }
        } else {
            // Create account + resident (staff workflow)
            $full_name   = trim("$first_name " . ($middle_name ? "$middle_name " : '') . $last_name);
            $tempPw      = 'Brgy@' . rand(1000, 9999);
            $hash        = password_hash($tempPw, PASSWORD_DEFAULT);
            $username    = generateUsername($conn, $first_name, $last_name);
            $handlerId   = currentUserId();

            $conn->begin_transaction();
            try {
                $hasNameCols = columnExists($conn, 'users', 'first_name');
                if ($hasNameCols) {
                    $su = $conn->prepare("INSERT INTO users (username,password,first_name,middle_name,last_name,full_name,role,status) VALUES (?,?,?,?,?,?,'resident','active')");
                    $su->bind_param('ssssss', $username, $hash, $first_name, $middle_name, $last_name, $full_name);
                } else {
                    $su = $conn->prepare("INSERT INTO users (username,password,full_name,role,status) VALUES (?,?,?,'resident','active')");
                    $su->bind_param('sss', $username, $hash, $full_name);
                }
                $su->execute();
                $userId = $conn->insert_id;
                $su->close();

                $stmt = $conn->prepare("INSERT INTO residents (user_id,first_name,middle_name,last_name,birthdate,gender,civil_status,address,purok,contact,email,years_in_brgy,id_type,id_number) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('issssssssssisss', $userId,$first_name,$middle_name,$last_name,$birthdate,$gender,$civil_status,$address,$purok,$contact,$email,$years,$id_type,$id_number);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                setFlash('success', "Resident account created. Username: <strong>$username</strong>, Temp Password: <strong>$tempPw</strong>");
                header('Location: residents.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                setFlash('error', 'Error creating resident. Please try again.');
                header('Location: residents.php');
                exit;
            }
        }
        $stmt->execute();
        $stmt->close();
        if (isset($s2)) { $s2->execute(); $s2->close(); }
        setFlash('success', 'Resident record saved.');
    }
    header('Location: residents.php');
    exit;
}

// ── FETCH EDIT ───────────────────────────────────────────────
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $stmt = $conn->prepare("SELECT r.*, u.username, u.status AS account_status FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.resident_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── LIST ─────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where  = '';
if ($search) {
    $s = $conn->real_escape_string($search);
    $where = "WHERE CONCAT(r.first_name,' ',r.last_name) LIKE '%$s%' OR r.contact LIKE '%$s%' OR r.address LIKE '%$s%'";
}

$residents = $conn->query("
    SELECT r.*, u.username, u.status AS account_status,
           (SELECT COUNT(*) FROM requests rq WHERE rq.resident_id=r.resident_id) AS request_count
    FROM residents r
    JOIN users u ON r.user_id=u.user_id
    $where
    ORDER BY r.last_name, r.first_name
")->fetch_all(MYSQLI_ASSOC);

include 'layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET" action="residents.php" style="display:flex;gap:.5rem;">
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, contact…" style="padding:.4rem .8rem .4rem 2.1rem;border:1.5px solid var(--n200);border-radius:8px;font-size:.82rem;width:220px;">
    </div>
    <button type="submit" class="btn-c btn-secondary-c">Search</button>
    <?php if ($search): ?><a href="residents.php" class="btn-c btn-secondary-c">Clear</a><?php endif; ?>
  </form>
  <button class="btn-c btn-primary-c" data-bs-toggle="modal" data-bs-target="#residentModal">
    <i class="bi bi-person-plus-fill"></i> Add Resident (Create Account)
  </button>
</div>

<?php if ($editRow): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const m = document.getElementById('residentModal');
  const fields = <?= json_encode($editRow) ?>;
  ['resident_id','first_name','middle_name','last_name','birthdate','gender','civil_status','address','purok','contact','email','years_in_brgy','id_type','id_number'].forEach(k => {
    const el = m.querySelector('[name='+k+']');
    if (el && fields[k] != null) el.value = fields[k];
  });
  new bootstrap.Modal(m).show();
});
</script>
<?php endif; ?>

<div class="card">
  <div class="card-header-c">
    <h3><i class="bi bi-people-fill" style="color:var(--crimson)"></i> Resident Records
      <span class="badge bg-secondary" style="font-size:.65rem;"><?= count($residents) ?></span>
    </h3>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr><th>#</th><th>Full Name</th><th>Address / Purok</th><th>Contact</th><th>Account</th><th>Requests</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($residents as $r): ?>
        <tr>
          <td><?= $r['resident_id'] ?></td>
          <td>
            <strong><?= htmlspecialchars($r['last_name'].', '.$r['first_name'].' '.($r['middle_name']??'')) ?></strong><br>
            <small style="color:var(--n400);">@<?= htmlspecialchars($r['username']) ?></small>
          </td>
          <td>
            <?= htmlspecialchars($r['address']) ?>
            <?= $r['purok'] ? '<br><small style="color:var(--n500);">'.htmlspecialchars($r['purok']).'</small>' : '' ?>
          </td>
          <td><?= htmlspecialchars($r['contact'] ?? '—') ?></td>
          <td>
            <?php $sc=['active'=>['bg-success','Active'],'pending'=>['bg-warning text-dark','Pending'],'suspended'=>['bg-danger','Suspended']][$r['account_status']]??['bg-secondary','Unknown']; ?>
            <span class="badge <?= $sc[0] ?>"><?= $sc[1] ?></span>
          </td>
          <td>
            <a href="requests.php?resident_id=<?= $r['resident_id'] ?>" class="badge bg-primary text-decoration-none">
              <?= $r['request_count'] ?> request<?= $r['request_count']!=1?'s':'' ?>
            </a>
          </td>
          <td>
            <div style="display:flex;gap:.35rem;">
              <a href="residents.php?action=edit&id=<?= $r['resident_id'] ?>" class="btn-c btn-secondary-c"><i class="bi bi-pencil"></i></a>
              <a href="requests.php?action=add&resident_id=<?= $r['resident_id'] ?>" class="btn-c btn-primary-c" title="New Request"><i class="bi bi-file-earmark-plus"></i></a>
              <?php if (isAdmin()): ?>
              <a href="residents.php?action=delete&id=<?= $r['resident_id'] ?>" class="btn-c btn-danger-c" onclick="return confirm('Delete this resident and all their data?')"><i class="bi bi-trash"></i></a>
              <?php endif; ?>
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="residentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
        <h5 class="modal-title" style="font-family:'DM Serif Display',serif;color:var(--crimson-dk);">
          <i class="bi bi-person-plus me-2"></i>Resident Profile
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="residents.php">
        <input type="hidden" name="save_resident" value="1">
        <input type="hidden" name="resident_id" value="0">
        <div class="modal-body">
          <div class="alert alert-info" style="font-size:.78rem;" id="newAccountNote">
            <i class="bi bi-info-circle-fill me-1"></i>
            A system account will automatically be created for this resident. Credentials will be shown after saving.
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">First Name *</label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Middle Name</label>
              <input type="text" name="middle_name" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Last Name *</label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Birthdate</label>
              <input type="date" name="birthdate" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="">Select…</option>
                <option>Male</option><option>Female</option><option>Other</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Civil Status</label>
              <select name="civil_status" class="form-select">
                <option value="">Select…</option>
                <option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option>
              </select>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-8">
              <label class="form-label">Address *</label>
              <input type="text" name="address" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Purok / Zone</label>
              <input type="text" name="purok" class="form-control">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Contact</label>
              <input type="tel" name="contact" class="form-control">
            </div>
            <div class="col-md-5">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Years in Brgy</label>
              <input type="number" name="years_in_brgy" class="form-control" min="0" max="100">
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">ID Type</label>
              <select name="id_type" class="form-select">
                <option value="">Select…</option>
                <?php foreach (["PhilSys ID","Driver's License","Passport","Voter's ID","SSS ID","GSIS ID","PRC ID","Barangay ID","Other"] as $idt): ?>
                <option><?= $idt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">ID Number</label>
              <input type="text" name="id_number" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-save"></i> Save Resident</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Hide new account note when editing
document.querySelector('[name=resident_id]')?.addEventListener('change', function() {
  document.getElementById('newAccountNote').style.display = this.value > 0 ? 'none' : '';
});
</script>

<?php include 'layout/footer.php'; ?>
