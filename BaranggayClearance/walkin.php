<?php
// walkin.php  —  Staff Walk-in Application workflow
require_once 'config.php';
requireStaff();
$pageTitle = 'Walk-in Application';

$step = $_GET['step'] ?? '1';
$success = false;
$requestId = 0;
$newUsername = '';
$tempPw = '';

// Load dropdowns
$allTypes = $conn->query("SELECT type_id, type_name FROM clearance_type WHERE is_active=1 ORDER BY type_name")->fetch_all(MYSQLI_ASSOC);
$allResidents = $conn->query("
    SELECT r.resident_id, CONCAT(r.last_name,', ',r.first_name,' ',COALESCE(r.middle_name,'')) AS full_name, r.address, r.contact, u.status AS account_status
    FROM residents r JOIN users u ON r.user_id=u.user_id
    ORDER BY r.last_name, r.first_name
")->fetch_all(MYSQLI_ASSOC);

// ── HANDLE FORM SUBMIT ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode        = $_POST['mode']        ?? 'existing'; // existing | new
    $resident_id = (int)($_POST['resident_id'] ?? 0);
    $type_id     = (int)($_POST['type_id']     ?? 0);
    $purpose     = trim($_POST['purpose']      ?? '');
    $notes       = trim($_POST['notes']        ?? '');
    $handlerId   = currentUserId();
    $errors      = [];

    if (!$type_id)  $errors[] = 'Clearance type is required.';
    if (!$purpose)  $errors[] = 'Purpose is required.';

    if ($mode === 'new') {
        // Create resident on the fly
        $first_name   = trim($_POST['first_name']   ?? '');
        $middle_name  = trim($_POST['middle_name']  ?? '');
        $last_name    = trim($_POST['last_name']    ?? '');
        $address      = trim($_POST['address']      ?? '');
        $contact      = trim($_POST['contact']      ?? '');
        $purok        = trim($_POST['purok']        ?? '');
        $gender       = trim($_POST['gender']       ?? '');
        $birthdate    = trim($_POST['birthdate']    ?? '') ?: null;
        $id_type      = trim($_POST['id_type']      ?? '') ?: null;
        $id_number    = trim($_POST['id_number']    ?? '') ?: null;

        if (!$first_name) $errors[] = 'First name is required.';
        if (!$last_name)  $errors[] = 'Last name is required.';
        if (!$address)    $errors[] = 'Address is required.';

        if (empty($errors)) {
            $full_name   = trim("$first_name " . ($middle_name ? "$middle_name " : '') . $last_name);
            $tempPw      = 'Brgy@' . rand(1000, 9999);
            $hash        = password_hash($tempPw, PASSWORD_DEFAULT);
            $newUsername = generateUsername($conn, $first_name, $last_name);
            $conn->begin_transaction();
            try {
                $hasNameCols = columnExists($conn, 'users', 'first_name');
                if ($hasNameCols) {
                    $su = $conn->prepare("INSERT INTO users (username,password,first_name,middle_name,last_name,full_name,role,status) VALUES (?,?,?,?,?,?,'resident','active')");
                    $su->bind_param('ssssss', $newUsername, $hash, $first_name, $middle_name, $last_name, $full_name);
                } else {
                    $su = $conn->prepare("INSERT INTO users (username,password,full_name,role,status) VALUES (?,?,?,'resident','active')");
                    $su->bind_param('sss', $newUsername, $hash, $full_name);
                }
                $su->execute();
                $userId = $conn->insert_id;
                $su->close();

                $sr = $conn->prepare("INSERT INTO residents (user_id,first_name,middle_name,last_name,birthdate,gender,address,purok,contact,id_type,id_number) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $sr->bind_param('issssssssss', $userId,$first_name,$middle_name,$last_name,$birthdate,$gender,$address,$purok,$contact,$id_type,$id_number);
                $sr->execute();
                $resident_id = $conn->insert_id;
                $sr->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Error creating resident account.';
            }
        }
    } else {
        if (!$resident_id) $errors[] = 'Please select a resident.';
    }

    if (empty($errors) && $resident_id) {
        $today     = date('Y-m-d');
        $requestId = insertRequest($conn, $resident_id, $type_id, $today, $purpose, 'pending', 'walkin', $handlerId, $notes ?: null);
        $hasAudit  = $conn->query("SHOW TABLES LIKE 'request_audit'")->num_rows > 0;
        if ($hasAudit) logAudit($conn, $requestId, 'Walk-in request created by staff', null, 'pending');
        $success = true;
    }
}

include 'layout/header.php';
?>

<div class="mb-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb" style="font-size:.78rem;">
      <li class="breadcrumb-item"><a href="dashboard.php" style="color:var(--crimson);">Dashboard</a></li>
      <li class="breadcrumb-item active">Walk-in Application</li>
    </ol>
  </nav>
</div>

<?php if ($success): ?>
<!-- ── Success ── -->
<div class="card" style="max-width:560px;margin:0 auto;">
  <div style="padding:2.5rem;text-align:center;">
    <div style="width:64px;height:64px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:#16a34a;margin:0 auto 1.25rem;">
      <i class="bi bi-check-lg"></i>
    </div>
    <h2 style="font-family:'DM Serif Display',serif;font-size:1.5rem;margin-bottom:.5rem;">Walk-in Request Created!</h2>
    <p style="color:var(--n500);font-size:.85rem;margin-bottom:1.25rem;">Request <strong>#<?= $requestId ?></strong> has been submitted successfully.</p>

    <?php if ($newUsername): ?>
    <div style="background:var(--n50);border:1.5px dashed var(--n300);border-radius:12px;padding:1rem;text-align:left;margin-bottom:1.25rem;font-size:.83rem;">
      <strong>New Resident Account Created:</strong><br>
      Username: <code style="background:var(--crimson-lt);color:var(--crimson-dk);padding:.1rem .4rem;border-radius:5px;font-weight:700;"><?= htmlspecialchars($newUsername) ?></code><br>
      Temp Password: <code style="background:var(--crimson-lt);color:var(--crimson-dk);padding:.1rem .4rem;border-radius:5px;font-weight:700;"><?= htmlspecialchars($tempPw) ?></code><br>
      <small style="color:#ef4444;"><i class="bi bi-info-circle"></i> Please share these credentials with the resident.</small>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:.75rem;justify-content:center;">
      <a href="requests.php?action=view&id=<?= $requestId ?>" class="btn-c btn-secondary-c"><i class="bi bi-eye"></i> View Request</a>
      <a href="walkin.php" class="btn-c btn-primary-c"><i class="bi bi-plus-lg"></i> New Walk-in</a>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Form ── -->
<div class="row g-3">
  <div class="col-lg-8">
    <form method="POST" action="walkin.php">

      <!-- Step 1: Find or Create Resident -->
      <div class="card mb-3">
        <div class="card-header-c" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
          <h3 style="color:var(--crimson-dk);"><i class="bi bi-person-badge me-2"></i>Step 1 — Resident</h3>
        </div>
        <div style="padding:1.25rem;">
          <div class="mb-3">
            <div style="display:flex;gap:.5rem;margin-bottom:1rem;">
              <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;cursor:pointer;">
                <input type="radio" name="mode" value="existing" id="modeExisting" checked onchange="toggleMode(this.value)"> Search Existing Resident
              </label>
              <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;cursor:pointer;">
                <input type="radio" name="mode" value="new" id="modeNew" onchange="toggleMode(this.value)"> Create New Resident Account
              </label>
            </div>
          </div>

          <!-- Existing Resident Search -->
          <div id="existingSection">
            <label class="form-label">Search Resident <span style="color:var(--crimson)">*</span></label>
            <input type="text" id="residentSearch" class="form-control mb-1" placeholder="Type name to search…" autocomplete="off">
            <div id="residentDrop" style="display:none;background:#fff;border:1.5px solid var(--n200);border-radius:9px;max-height:200px;overflow-y:auto;box-shadow:var(--shadow-md);z-index:99;position:relative;">
            </div>
            <input type="hidden" name="resident_id" id="residentId" value="0">
            <div id="residentInfo" style="margin-top:.5rem;font-size:.82rem;color:var(--crimson-dk);"></div>
          </div>

          <!-- New Resident Form -->
          <div id="newSection" style="display:none;">
            <div class="row g-2 mb-2">
              <div class="col-md-4"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control"></div>
              <div class="col-md-4"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control"></div>
              <div class="col-md-4"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control"></div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-4"><label class="form-label">Birthdate</label><input type="date" name="birthdate" class="form-control"></div>
              <div class="col-md-4"><label class="form-label">Gender</label><select name="gender" class="form-select"><option value="">Select…</option><option>Male</option><option>Female</option><option>Other</option></select></div>
              <div class="col-md-4"><label class="form-label">Contact</label><input type="tel" name="contact" class="form-control"></div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-8"><label class="form-label">Address *</label><input type="text" name="address" class="form-control"></div>
              <div class="col-md-4"><label class="form-label">Purok</label><input type="text" name="purok" class="form-control"></div>
            </div>
            <div class="row g-2">
              <div class="col-md-6"><label class="form-label">ID Type</label><select name="id_type" class="form-select"><option value="">Select…</option><?php foreach (["PhilSys ID","Driver's License","Passport","Voter's ID","SSS ID","GSIS ID","PRC ID","Barangay ID","Other"] as $idt): ?><option><?= $idt ?></option><?php endforeach; ?></select></div>
              <div class="col-md-6"><label class="form-label">ID Number</label><input type="text" name="id_number" class="form-control"></div>
            </div>
            <div class="alert alert-warning mt-2" style="font-size:.77rem;">
              <i class="bi bi-person-plus-fill"></i> A new resident account will be automatically created. Credentials will appear after submission.
            </div>
          </div>
        </div>
      </div>

      <!-- Step 2: Clearance Details -->
      <div class="card mb-3">
        <div class="card-header-c" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
          <h3 style="color:var(--crimson-dk);"><i class="bi bi-file-earmark-text me-2"></i>Step 2 — Clearance Details</h3>
        </div>
        <div style="padding:1.25rem;">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Clearance Type <span style="color:var(--crimson)">*</span></label>
              <select name="type_id" class="form-select" required>
                <option value="">Select type…</option>
                <?php foreach ($allTypes as $t): ?>
                <option value="<?= $t['type_id'] ?>"><?= htmlspecialchars($t['type_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Purpose <span style="color:var(--crimson)">*</span></label>
              <input type="text" name="purpose" class="form-control" placeholder="e.g. Employment, Scholarship…" required>
            </div>
          </div>
          <div>
            <label class="form-label">Notes (Optional)</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes…"></textarea>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;">
        <button type="submit" class="btn-c btn-primary-c" style="padding:.7rem 2rem;font-size:.9rem;">
          <i class="bi bi-send-fill"></i> Submit Walk-in Request
        </button>
      </div>
    </form>
  </div>

  <!-- Right: Quick Stats -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header-c"><h3><i class="bi bi-info-circle" style="color:var(--crimson)"></i> Walk-in Guide</h3></div>
      <div style="padding:1rem 1.25rem;font-size:.82rem;color:var(--n600);">
        <ol style="padding-left:1.25rem;line-height:2;">
          <li>Search for an existing resident by name.</li>
          <li>If not found, select <strong>"Create New Resident"</strong> and fill in their details.</li>
          <li>Select the clearance type and enter the purpose.</li>
          <li>Submit — the request is immediately created as <strong>Walk-in</strong>.</li>
        </ol>
      </div>
    </div>
    <div class="card">
      <div class="card-header-c"><h3><i class="bi bi-clock-history" style="color:var(--crimson)"></i> Today's Walk-ins</h3></div>
      <div style="padding:1rem 1.25rem;">
        <?php
        $hasSource     = columnExists($conn, 'requests', 'source');
        $hasCreatedAt  = columnExists($conn, 'requests', 'created_at');
        $wWhere = $hasSource ? "r.source='walkin'" : '1=1';
        $wOrder = $hasCreatedAt ? 'r.created_at DESC' : 'r.request_id DESC';
        $wDate  = $hasCreatedAt ? 'AND DATE(r.created_at)=CURDATE()' : '';
        $todayWalkins = $conn->query("
            SELECT CONCAT(res.first_name,' ',res.last_name) AS name, ct.type_name, r.status
            FROM requests r
            JOIN residents res ON r.resident_id=res.resident_id
            JOIN clearance_type ct ON r.type_id=ct.type_id
            WHERE $wWhere $wDate
            ORDER BY $wOrder LIMIT 5
        ")->fetch_all(MYSQLI_ASSOC);
        if (empty($todayWalkins)):
        ?>
        <p style="font-size:.8rem;color:var(--n400);">No walk-ins today yet.</p>
        <?php else: ?>
        <?php foreach ($todayWalkins as $w): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.45rem 0;border-bottom:1px solid var(--n100);font-size:.8rem;">
          <div>
            <div style="font-weight:600;"><?= htmlspecialchars($w['name']) ?></div>
            <div style="color:var(--n500);"><?= htmlspecialchars($w['type_name']) ?></div>
          </div>
          <?= statusBadge($w['status']) ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const residents = <?= json_encode(array_map(fn($r)=>['id'=>$r['resident_id'],'name'=>$r['full_name'],'address'=>$r['address'],'contact'=>$r['contact']], $allResidents)) ?>;

function toggleMode(mode) {
  document.getElementById('existingSection').style.display = mode==='existing' ? '' : 'none';
  document.getElementById('newSection').style.display = mode==='new' ? '' : 'none';
  if (mode==='new') document.getElementById('residentId').value = 0;
}

const inp = document.getElementById('residentSearch');
const drop = document.getElementById('residentDrop');
const hidId = document.getElementById('residentId');
const info = document.getElementById('residentInfo');

if (inp) {
  inp.addEventListener('input', () => {
    const q = inp.value.trim().toLowerCase();
    if (!q) { drop.style.display='none'; return; }
    const m = residents.filter(r=>r.name.toLowerCase().includes(q)).slice(0,8);
    if (!m.length) { drop.style.display='none'; return; }
    drop.innerHTML = m.map(r=>`<div data-id="${r.id}" data-addr="${r.address||''}" data-ct="${r.contact||''}" style="padding:.55rem .9rem;cursor:pointer;font-size:.82rem;border-bottom:1px solid var(--n100);">
      <strong>${r.name}</strong><br><small style="color:var(--n500);">${r.address||''} ${r.contact?'· '+r.contact:''}</small>
    </div>`).join('');
    drop.style.display='block';
    drop.querySelectorAll('[data-id]').forEach(el=>{
      el.addEventListener('click',()=>{
        hidId.value=el.dataset.id;
        inp.value=el.querySelector('strong').textContent;
        info.innerHTML=`<i class="bi bi-check-circle-fill" style="color:#16a34a;"></i> <strong>${inp.value}</strong> selected`;
        drop.style.display='none';
      });
    });
  });
  document.addEventListener('click',e=>{if(!drop.contains(e.target)&&e.target!==inp) drop.style.display='none';});
}
</script>

<?php include 'layout/footer.php'; ?>
