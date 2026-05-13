<?php
// requests.php  —  Clearance Request management (Staff + Admin)
require_once 'config.php';
requireStaff();
$pageTitle = 'Clearance Requests';

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete' && $id > 0 && isAdmin()) {
    $conn->query("DELETE FROM request_audit WHERE request_id=$id");
    $conn->query("DELETE FROM requests WHERE request_id=$id");
    setFlash('success', 'Request deleted.');
    header('Location: requests.php');
    exit;
}

// ── CHANGE STATUS (dedicated action) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    $rid       = (int)($_POST['request_id'] ?? 0);
    $newStatus = trim($_POST['status']      ?? '');
    $notes     = trim($_POST['notes']       ?? '');

    $allowed = ['pending','verified','approved','released','rejected'];
    if ($rid > 0 && in_array($newStatus, $allowed)) {
        $old = $conn->query("SELECT status FROM requests WHERE request_id=$rid")->fetch_assoc();
        $oldStatus = $old['status'] ?? null;

        $handlerId = currentUserId();
        $stmt = $conn->prepare("UPDATE requests SET status=?, notes=?, handled_by=? WHERE request_id=?");
        $stmt->bind_param('ssii', $newStatus, $notes, $handlerId, $rid);
        $stmt->execute();
        $stmt->close();

        logAudit($conn, $rid, "Status changed to $newStatus", $oldStatus, $newStatus, $notes ?: null);
        setFlash('success', 'Status updated to ' . ucfirst($newStatus) . '.');
    } else {
        setFlash('error', 'Invalid status update.');
    }
    $returnTo = $_POST['return_to'] ?? 'list';
    if ($returnTo === 'view') {
        header("Location: requests.php?action=view&id=$rid");
    } else {
        header('Location: requests.php');
    }
    exit;
}

// ── SAVE (edit or create — no status on creation) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_request'])) {
    $rid         = (int)($_POST['request_id']    ?? 0);
    $resident_id = (int)($_POST['resident_id']   ?? 0);
    $type_id     = (int)($_POST['type_id']       ?? 0);
    $date        = trim($_POST['date_requested'] ?? date('Y-m-d'));
    $purpose     = trim($_POST['purpose']        ?? '');
    $source      = trim($_POST['source']         ?? 'online');
    $notes       = trim($_POST['notes']          ?? '');
    $handlerId   = currentUserId();

    if (!$resident_id || !$type_id || !$purpose) {
        setFlash('error', 'Resident, clearance type, and purpose are required.');
    } else {
        if ($rid > 0) {
            // EDIT: update details only — status is NOT touched here
            $old = $conn->query("SELECT status FROM requests WHERE request_id=$rid")->fetch_assoc();
            $currentStatus = $old['status'] ?? 'pending';

            $stmt = $conn->prepare("UPDATE requests SET resident_id=?,type_id=?,date_requested=?,purpose=?,notes=?,handled_by=? WHERE request_id=?");
            $stmt->bind_param('iisssii', $resident_id, $type_id, $date, $purpose, $notes, $handlerId, $rid);
            $stmt->execute();
            $stmt->close();

            logAudit($conn, $rid, 'Request updated', $currentStatus, $currentStatus, $notes ?: null);
            setFlash('success', 'Request updated.');
        } else {
            // CREATE: always starts as pending — no status field needed
            $status = 'pending';
            $stmt = $conn->prepare("INSERT INTO requests (resident_id,type_id,date_requested,purpose,status,source,handled_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('iissssi', $resident_id, $type_id, $date, $purpose, $status, $source, $handlerId);
            $stmt->execute();
            $newId = $conn->insert_id;
            $stmt->close();
            logAudit($conn, $newId, 'Request created by staff', null, $status);
            setFlash('success', 'Request created successfully.');
        }
    }
    header('Location: requests.php');
    exit;
}

// ── FETCH EDIT / VIEW ─────────────────────────────────────────
$editRow      = null;
$viewHistory  = null;
$viewResident = null;

if ($action === 'edit' && $id > 0) {
    $stmt = $conn->prepare("SELECT * FROM requests WHERE request_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($action === 'view' && $id > 0) {
    $stmt = $conn->prepare("
        SELECT r.*, ct.type_name, CONCAT(res.first_name,' ',res.last_name) AS resident_name,
               res.address, res.contact, res.resident_id AS res_id, u_h.full_name AS handler_name
        FROM requests r
        JOIN clearance_type ct ON r.type_id=ct.type_id
        JOIN residents res ON r.resident_id=res.resident_id
        LEFT JOIN users u_h ON r.handled_by=u_h.user_id
        WHERE r.request_id=?
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $viewResident = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT a.*, u.full_name AS actor_name, u.role AS actor_role
        FROM request_audit a
        JOIN users u ON a.done_by=u.user_id
        WHERE a.request_id=?
        ORDER BY a.done_at DESC
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $viewHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ── LIST ─────────────────────────────────────────────────────
$search    = trim($_GET['q']            ?? '');
$statusF   = trim($_GET['status_f']     ?? '');
$residentF = (int)($_GET['resident_id'] ?? 0);
$conditions = [];

if ($search) {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(CONCAT(res.first_name,' ',res.last_name) LIKE '%$s%' OR r.purpose LIKE '%$s%' OR ct.type_name LIKE '%$s%')";
}
if ($statusF)   $conditions[] = "r.status='".$conn->real_escape_string($statusF)."'";
if ($residentF) $conditions[] = "r.resident_id=$residentF";

$where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';

$requests = $conn->query("
    SELECT r.request_id, CONCAT(res.first_name,' ',res.last_name) AS resident_name,
           res.resident_id, ct.type_name, r.date_requested, r.purpose, r.status, r.source, r.created_at
    FROM requests r
    JOIN residents res ON r.resident_id=res.resident_id
    JOIN clearance_type ct ON r.type_id=ct.type_id
    $where
    ORDER BY r.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$allResidents = $conn->query("
    SELECT r.resident_id, CONCAT(r.last_name,', ',r.first_name) AS full_name
    FROM residents r JOIN users u ON r.user_id=u.user_id
    WHERE u.status='active'
    ORDER BY r.last_name, r.first_name
")->fetch_all(MYSQLI_ASSOC);

$allTypes = $conn->query("SELECT type_id, type_name FROM clearance_type WHERE is_active=1 ORDER BY type_name")->fetch_all(MYSQLI_ASSOC);

include 'layout/header.php';
?>

<?php if ($action === 'view' && $viewResident): ?>
<!-- ── DETAIL VIEW ── -->
<div class="mb-3">
  <a href="requests.php" class="btn-c btn-secondary-c"><i class="bi bi-arrow-left"></i> Back to List</a>
</div>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card" style="height:100%;">
      <div class="card-header-c">
        <h3><i class="bi bi-file-earmark-text" style="color:var(--crimson)"></i> Request #<?= $id ?> Details</h3>
        <?= statusBadge($viewResident['status']) ?>
      </div>
      <div style="padding:1.25rem;">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse;">
          <?php
          $rows = [
            'Resident'       => htmlspecialchars($viewResident['resident_name']),
            'Type'           => htmlspecialchars($viewResident['type_name']),
            'Purpose'        => htmlspecialchars($viewResident['purpose']),
            'Date Requested' => date('F j, Y', strtotime($viewResident['date_requested'])),
            'Source'         => '<span class="badge '.(safeSource($viewResident)==='walkin'?'bg-warning text-dark':'bg-info text-white').'">'.ucfirst(safeSource($viewResident)).'</span>',
            'Handled By'     => htmlspecialchars($viewResident['handler_name'] ?? '—'),
            'Notes'          => htmlspecialchars($viewResident['notes'] ?? '—'),
          ];
          foreach ($rows as $lbl => $val):
          ?>
          <tr style="border-bottom:1px solid var(--n100);">
            <td style="padding:.5rem .25rem;font-weight:700;color:var(--n600);width:40%;"><?= $lbl ?></td>
            <td style="padding:.5rem .25rem;"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>

        <div style="margin-top:1.25rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
          <a href="requests.php?action=edit&id=<?= $id ?>" class="btn-c btn-primary-c">
            <i class="bi bi-pencil"></i> Edit Details
          </a>
          <button type="button" class="btn-c btn-secondary-c"
                  onclick="openStatusModal(<?= $id ?>, '<?= $viewResident['status'] ?>', '<?= htmlspecialchars(addslashes($viewResident['notes'] ?? ''), ENT_QUOTES) ?>')">
            <i class="bi bi-arrow-repeat"></i> Change Status
          </button>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header-c">
        <h3><i class="bi bi-journal-text" style="color:var(--crimson)"></i> Audit Trail</h3>
      </div>
      <div style="padding:1rem 1.25rem;">
        <?php if (empty($viewHistory)): ?>
        <p class="text-muted" style="font-size:.82rem;">No history recorded.</p>
        <?php else: ?>
        <div style="position:relative;padding-left:1.5rem;">
          <div style="position:absolute;left:.35rem;top:0;bottom:0;width:2px;background:var(--n200);"></div>
          <?php foreach ($viewHistory as $h): ?>
          <div style="position:relative;margin-bottom:1rem;">
            <div style="position:absolute;left:-1.15rem;top:.25rem;width:10px;height:10px;border-radius:50%;background:var(--crimson);border:2px solid #fff;box-shadow:0 0 0 2px var(--crimson);"></div>
            <div style="font-size:.78rem;color:var(--n500);"><?= date('M j, Y g:i A', strtotime($h['done_at'])) ?></div>
            <div style="font-size:.85rem;font-weight:600;margin:.1rem 0;"><?= htmlspecialchars($h['action']) ?></div>
            <?php if ($h['new_status']): ?><div><?= statusBadge($h['new_status']) ?></div><?php endif; ?>
            <?php if ($h['notes']): ?><div style="font-size:.78rem;color:var(--n600);margin-top:.2rem;"><?= htmlspecialchars($h['notes']) ?></div><?php endif; ?>
            <div style="font-size:.72rem;color:var(--n400);margin-top:.15rem;">by <?= htmlspecialchars($h['actor_name']) ?> <span class="badge bg-secondary" style="font-size:.6rem;"><?= $h['actor_role'] ?></span></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif ($action === 'edit' && $editRow): ?>
<!-- ── EDIT VIEW: details only, no status ── -->
<div class="mb-3">
  <a href="requests.php?action=view&id=<?= $id ?>" class="btn-c btn-secondary-c"><i class="bi bi-arrow-left"></i> Back to Request</a>
</div>
<div style="max-width:680px;">
  <div class="card">
    <div class="card-header-c" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
      <h3 style="color:var(--crimson-dk);">
        <i class="bi bi-pencil-square me-2"></i>Edit Request #<?= $id ?>
      </h3>
      <?= statusBadge($editRow['status']) ?>
    </div>
    <div style="padding:1.5rem;">
      <div class="alert alert-info" style="font-size:.8rem;margin-bottom:1.25rem;padding:.6rem .9rem;">
        <i class="bi bi-info-circle me-1"></i>
        This form edits request details only. To change the status, use the
        <strong>Change Status</strong> button on the <a href="requests.php?action=view&id=<?= $id ?>" style="color:var(--crimson);">request view page</a>.
      </div>

      <form method="POST" action="requests.php">
        <input type="hidden" name="save_request" value="1">
        <input type="hidden" name="request_id" value="<?= $id ?>">
        <input type="hidden" name="resident_id" value="<?= $editRow['resident_id'] ?>">

        <?php
        $resName = $conn->query("SELECT CONCAT(first_name,' ',last_name) AS n FROM residents WHERE resident_id=".(int)$editRow['resident_id'])->fetch_assoc()['n'] ?? '—';
        ?>
        <div class="mb-3">
          <label class="form-label">Resident</label>
          <div style="padding:.5rem .75rem;background:var(--n50);border:1.5px solid var(--n200);border-radius:8px;font-size:.85rem;color:var(--n700);">
            <i class="bi bi-person-fill" style="color:var(--crimson);margin-right:.4rem;"></i><?= htmlspecialchars($resName) ?>
            <small style="color:var(--n400);margin-left:.5rem;">(cannot be changed)</small>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">Clearance Type <span style="color:var(--crimson)">*</span></label>
            <select name="type_id" class="form-select" required>
              <?php foreach ($allTypes as $t): ?>
              <option value="<?= $t['type_id'] ?>" <?= $t['type_id']==$editRow['type_id']?'selected':'' ?>><?= htmlspecialchars($t['type_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Date Requested</label>
            <input type="date" name="date_requested" class="form-control" value="<?= htmlspecialchars($editRow['date_requested']) ?>">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Purpose <span style="color:var(--crimson)">*</span></label>
          <input type="text" name="purpose" class="form-control" value="<?= htmlspecialchars($editRow['purpose']) ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Notes <span style="font-size:.72rem;color:var(--n400);">(optional)</span></label>
          <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($editRow['notes'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
          <a href="requests.php?action=view&id=<?= $id ?>" class="btn-c btn-secondary-c">Cancel</a>
          <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-save"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── LIST ── -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
  <?php foreach ([''=>'All','pending'=>'Pending','verified'=>'Verified','approved'=>'Approved','released'=>'Released','rejected'=>'Rejected'] as $sv=>$sl): ?>
  <a href="requests.php?status_f=<?= $sv ?>&q=<?= urlencode($search) ?>" class="btn-c <?= $statusF===$sv?'btn-primary-c':'btn-secondary-c' ?>" style="font-size:.72rem;"><?= $sl ?></a>
  <?php endforeach; ?>

  <form method="GET" style="margin-left:auto;display:flex;gap:.5rem;">
    <input type="hidden" name="status_f" value="<?= htmlspecialchars($statusF) ?>">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search resident, purpose…" style="padding:.4rem .8rem;border:1.5px solid var(--n200);border-radius:8px;font-size:.82rem;width:200px;">
    <button type="submit" class="btn-c btn-secondary-c">Search</button>
  </form>

  <button class="btn-c btn-primary-c" data-bs-toggle="modal" data-bs-target="#requestModal">
    <i class="bi bi-plus-lg"></i> New Request
  </button>
</div>

<div class="card">
  <div class="card-header-c">
    <h3><i class="bi bi-file-earmark-text" style="color:var(--crimson)"></i> Clearance Requests
      <span class="badge bg-secondary" style="font-size:.65rem;"><?= count($requests) ?></span>
    </h3>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr><th>#</th><th>Resident</th><th>Type</th><th>Purpose</th><th>Date</th><th>Source</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr>
          <td><strong style="color:var(--crimson);">#<?= $r['request_id'] ?></strong></td>
          <td>
            <a href="residents.php?q=<?= urlencode($r['resident_name']) ?>" class="text-decoration-none" style="font-weight:600;">
              <?= htmlspecialchars($r['resident_name']) ?>
            </a>
          </td>
          <td><?= htmlspecialchars($r['type_name']) ?></td>
          <td><?= htmlspecialchars($r['purpose']) ?></td>
          <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($r['date_requested'])) ?></td>
          <td>
            <span class="badge <?= safeSource($r)==='walkin'?'bg-warning text-dark':'bg-info text-white' ?>">
              <i class="bi bi-<?= safeSource($r)==='walkin'?'person-badge':'wifi' ?>"></i> <?= ucfirst(safeSource($r)) ?>
            </span>
          </td>
          <td><?= statusBadge($r['status']) ?></td>
          <td>
            <div style="display:flex;gap:.35rem;">
              <a href="requests.php?action=view&id=<?= $r['request_id'] ?>" class="btn-c btn-secondary-c" title="View"><i class="bi bi-eye"></i></a>
              <a href="requests.php?action=edit&id=<?= $r['request_id'] ?>" class="btn-c btn-secondary-c" title="Edit Details"><i class="bi bi-pencil"></i></a>
              <button type="button" class="btn-c btn-secondary-c" title="Change Status"
                      onclick="openStatusModal(<?= $r['request_id'] ?>, '<?= $r['status'] ?>', '')">
                <i class="bi bi-arrow-repeat"></i>
              </button>
              <?php if (isAdmin()): ?>
              <a href="requests.php?action=delete&id=<?= $r['request_id'] ?>" class="btn-c btn-danger-c" onclick="return confirm('Delete this request?')" title="Delete"><i class="bi bi-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
        <tr><td colspan="8" class="text-center py-4 text-muted">No requests found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── NEW REQUEST Modal (no status — always starts pending) ── -->
<div class="modal fade" id="requestModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
        <h5 class="modal-title" style="font-family:'DM Serif Display',serif;color:var(--crimson-dk);">
          <i class="bi bi-file-earmark-plus me-2"></i>New Clearance Request
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="requests.php">
        <input type="hidden" name="save_request" value="1">
        <input type="hidden" name="request_id" value="0">
        <input type="hidden" name="resident_id" id="residentIdInput" value="<?= (int)($_GET['resident_id']??0) ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Resident <span style="color:var(--crimson)">*</span></label>
            <input type="text" id="residentSearchInput" class="form-control" placeholder="Type name to search…" autocomplete="off">
            <div id="residentDropdown" style="display:none;background:#fff;border:1.5px solid var(--n200);border-radius:9px;margin-top:.25rem;max-height:200px;overflow-y:auto;z-index:9999;position:relative;box-shadow:var(--shadow-md);"></div>
            <div id="residentSelected" style="margin-top:.4rem;font-size:.8rem;color:var(--crimson-dk);font-weight:600;"></div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Clearance Type <span style="color:var(--crimson)">*</span></label>
              <select name="type_id" class="form-select" required>
                <option value="">Select…</option>
                <?php foreach ($allTypes as $t): ?>
                <option value="<?= $t['type_id'] ?>"><?= htmlspecialchars($t['type_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Date Requested</label>
              <input type="date" name="date_requested" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-8">
              <label class="form-label">Purpose <span style="color:var(--crimson)">*</span></label>
              <input type="text" name="purpose" class="form-control" placeholder="e.g. Employment, Scholarship…" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Source</label>
              <select name="source" class="form-select">
                <option value="online">Online</option>
                <option value="walkin">Walk-in</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes <span style="font-size:.72rem;color:var(--n400);">(optional)</span></label>
            <input type="text" name="notes" class="form-control" placeholder="Optional notes…">
          </div>
          <div style="font-size:.78rem;color:var(--n500);background:var(--n50);border-radius:8px;padding:.55rem .8rem;">
            <i class="bi bi-info-circle me-1"></i>
            New requests are automatically set to <strong>Pending</strong>. You can change the status after creation.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-save"></i> Create Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── CHANGE STATUS Modal (dedicated) ── -->
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
        <h5 class="modal-title" style="font-family:'DM Serif Display',serif;color:var(--crimson-dk);">
          <i class="bi bi-arrow-repeat me-2"></i>Change Status
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="requests.php">
        <input type="hidden" name="change_status" value="1">
        <input type="hidden" name="request_id" id="statusRequestId" value="">
        <input type="hidden" name="return_to" id="statusReturnTo" value="list">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">New Status</label>
            <select name="status" id="statusSelect" class="form-select">
              <?php foreach (['pending'=>'Pending','verified'=>'Verified','approved'=>'Approved','released'=>'Released','rejected'=>'Rejected'] as $sv=>$sl): ?>
              <option value="<?= $sv ?>"><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Notes <span style="font-size:.72rem;color:var(--n400);">(optional)</span></label>
            <input type="text" name="notes" id="statusNotes" class="form-control" placeholder="Reason or remarks…">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-check-lg"></i> Update Status</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const residents = <?= json_encode(array_map(fn($r)=>['id'=>$r['resident_id'],'name'=>$r['full_name']], $allResidents)) ?>;
const inp      = document.getElementById('residentSearchInput');
const drop     = document.getElementById('residentDropdown');
const hidInput = document.getElementById('residentIdInput');
const selLabel = document.getElementById('residentSelected');

const preId = <?= (int)($_GET['resident_id'] ?? 0) ?>;
if (preId && inp) {
  const pre = residents.find(r=>r.id==preId);
  if (pre) { inp.value=pre.name; selLabel.textContent='Selected: '+pre.name; }
}

if (inp) {
  inp.addEventListener('input', () => {
    const q = inp.value.trim().toLowerCase();
    if (!q) { drop.style.display='none'; return; }
    const matches = residents.filter(r=>r.name.toLowerCase().includes(q)).slice(0,8);
    if (!matches.length) { drop.style.display='none'; return; }
    drop.innerHTML = matches.map(r=>`<div data-id="${r.id}" style="padding:.55rem .9rem;cursor:pointer;font-size:.83rem;border-bottom:1px solid var(--n100);">${r.name}</div>`).join('');
    drop.style.display='block';
    drop.querySelectorAll('[data-id]').forEach(el=>{
      el.addEventListener('mouseenter',()=>el.style.background='var(--n50)');
      el.addEventListener('mouseleave',()=>el.style.background='');
      el.addEventListener('click',()=>{
        hidInput.value=el.dataset.id;
        inp.value=el.textContent;
        selLabel.textContent='Selected: '+el.textContent;
        drop.style.display='none';
      });
    });
  });
  document.addEventListener('click',e=>{if(!drop.contains(e.target)&&e.target!==inp) drop.style.display='none';});
}

function openStatusModal(requestId, currentStatus, currentNotes) {
  document.getElementById('statusRequestId').value = requestId;
  document.getElementById('statusSelect').value    = currentStatus;
  document.getElementById('statusNotes').value     = currentNotes || '';
  const isView = window.location.search.includes('action=view');
  document.getElementById('statusReturnTo').value  = isView ? 'view' : 'list';
  new bootstrap.Modal(document.getElementById('statusModal')).show();
}
</script>

<?php include 'layout/footer.php'; ?>
