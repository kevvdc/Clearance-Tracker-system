<?php
// staff_roles.php  —  Manage Staff Roles (Admin only)
require_once 'config.php';
requireAdmin();
$pageTitle = 'Staff Roles';

$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$formErr = '';

// ── SAVE: handle Add or Edit ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $rid  = (int)trim($_POST['role_id']   ?? '0');
    $name = trim($_POST['role_name']      ?? '');
    $desc = trim($_POST['role_desc']      ?? '');

    if ($name === '') {
        $formErr = 'Position name is required.';

    } else {
        // Duplicate name check (exclude self on edit)
        $dup = $conn->prepare(
            "SELECT role_id FROM staff_roles WHERE role_name = ? AND role_id != ?"
        );
        $dup->bind_param('si', $name, $rid);
        $dup->execute();
        $dupFound = $dup->get_result()->num_rows > 0;
        $dup->close();

        if ($dupFound) {
            $formErr = "A role named \"" . htmlspecialchars($name) . "\" already exists.";

        } else {
            if ($rid > 0) {
                // UPDATE existing role
                $stmt = $conn->prepare(
                    "UPDATE staff_roles SET role_name = ?, description = ? WHERE role_id = ?"
                );
                $stmt->bind_param('ssi', $name, $desc, $rid);
                $ok = $stmt->execute();
                $dbErr = $stmt->error;
                $stmt->close();

                if ($ok) {
                    setFlash('success', "Role \"$name\" updated successfully.");
                    header('Location: staff_roles.php');
                    exit;
                } else {
                    $formErr = "Database error while updating: $dbErr";
                }

            } else {
                // INSERT new role
                $stmt = $conn->prepare(
                    "INSERT INTO staff_roles (role_name, description) VALUES (?, ?)"
                );
                $stmt->bind_param('ss', $name, $desc);
                $ok = $stmt->execute();
                $dbErr = $stmt->error;
                $stmt->close();

                if ($ok) {
                    setFlash('success', "Role \"$name\" created successfully.");
                    header('Location: staff_roles.php');
                    exit;
                } else {
                    $formErr = "Database error while inserting: $dbErr";
                }
            }
        }
    }

    // If we reach here, there was an error — keep the user on the form
    // Rebuild $editRow from POST so form values are preserved
    $editRow = ['role_id' => $rid, 'role_name' => $name, 'description' => $desc];
    $action  = $rid > 0 ? 'edit' : 'list';

} else {
    // ── GET: Delete ─────────────────────────────────────────
    if ($action === 'delete' && $id > 0) {
        $inUse = (int)$conn->query(
            "SELECT COUNT(*) AS c FROM users WHERE staff_role_id = $id"
        )->fetch_assoc()['c'];
        if ($inUse > 0) {
            setFlash('error', 'Cannot delete: this role is assigned to ' . $inUse . ' staff member(s).');
        } else {
            $conn->query("DELETE FROM staff_roles WHERE role_id = $id");
            if ($conn->affected_rows > 0) {
                setFlash('success', 'Staff role deleted.');
            } else {
                setFlash('error', 'Role not found or already deleted.');
            }
        }
        header('Location: staff_roles.php');
        exit;
    }

    // ── GET: Toggle active / inactive ───────────────────────
    if ($action === 'toggle' && $id > 0) {
        $conn->query("UPDATE staff_roles SET is_active = NOT is_active WHERE role_id = $id");
        setFlash('success', 'Staff role status updated.');
        header('Location: staff_roles.php');
        exit;
    }

    // ── GET: Load edit row ───────────────────────────────────
    $editRow = null;
    if ($action === 'edit' && $id > 0) {
        $stmt = $conn->prepare("SELECT * FROM staff_roles WHERE role_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $editRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$editRow) {
            setFlash('error', 'Role not found.');
            header('Location: staff_roles.php');
            exit;
        }
    }
}

// ── Load all roles for the table ────────────────────────────
$staffRoles = $conn->query("
    SELECT sr.*,
           (SELECT COUNT(*) FROM users WHERE staff_role_id = sr.role_id) AS assigned_count
    FROM staff_roles sr
    ORDER BY sr.role_name ASC
")->fetch_all(MYSQLI_ASSOC);

$isEditing = ($action === 'edit' && isset($editRow) && $editRow !== null);

include 'layout/header.php';
?>

<!-- ── BREADCRUMB ────────────────────────────────────────────── -->
<nav aria-label="breadcrumb" style="margin-bottom:1rem;">
  <ol class="breadcrumb" style="font-size:.78rem;margin:0;">
    <li class="breadcrumb-item"><a href="users.php?tab=staff" style="color:var(--crimson);">Manage Accounts</a></li>
    <li class="breadcrumb-item active">Staff Roles</li>
  </ol>
</nav>

<!-- ── PAGE HEADER ───────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
  <div>
    <h2 style="font-family:'DM Serif Display',serif;font-size:1.3rem;color:var(--n900);margin:0;">
      <i class="bi bi-tags-fill" style="color:var(--crimson);margin-right:.4rem;"></i>Staff Roles
    </h2>
    <p style="font-size:.78rem;color:var(--n500);margin:.25rem 0 0;">
      Define barangay positions (e.g. Captain, Secretary, Kagawad) that can be assigned to staff accounts.
    </p>
  </div>
  <a href="users.php?tab=staff" class="btn-c btn-secondary-c">
    <i class="bi bi-arrow-left"></i> Back to Manage Accounts
  </a>
</div>

<!-- ── ADD / EDIT FORM CARD ──────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
  <div class="card-header-c">
    <h3 style="margin:0;">
      <i class="bi bi-<?= $isEditing ? 'pencil-square' : 'plus-circle-fill' ?>"
         style="color:var(--crimson)"></i>
      <?= $isEditing
          ? 'Editing: <em style="color:var(--crimson);">' . htmlspecialchars($editRow['role_name']) . '</em>'
          : 'Add New Role' ?>
    </h3>
    <?php if ($isEditing): ?>
    <a href="staff_roles.php" class="btn-c btn-secondary-c" style="font-size:.72rem;">
      <i class="bi bi-x-lg"></i> Cancel Edit
    </a>
    <?php endif; ?>
  </div>

  <div style="padding:1.25rem;">

    <?php if ($formErr): ?>
    <div class="alert alert-danger" role="alert" style="font-size:.82rem;margin-bottom:1rem;">
      <i class="bi bi-exclamation-circle-fill me-2"></i><?= htmlspecialchars($formErr) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="staff_roles.php">
      <input type="hidden" name="role_id" value="<?= (int)($editRow['role_id'] ?? 0) ?>">

      <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:180px;">
          <label class="form-label">
            Position Name <span style="color:var(--crimson);">*</span>
          </label>
          <input
            type="text"
            name="role_name"
            class="form-control"
            required
            autofocus
            value="<?= htmlspecialchars($editRow['role_name'] ?? '') ?>"
            placeholder="e.g. Barangay Captain"
          >
        </div>
        <div style="flex:2;min-width:220px;">
          <label class="form-label">Description <span style="font-size:.68rem;color:var(--n400);">(optional)</span></label>
          <input
            type="text"
            name="role_desc"
            class="form-control"
            value="<?= htmlspecialchars($editRow['description'] ?? '') ?>"
            placeholder="Brief description of this position"
          >
        </div>
        <div>
          <button type="submit" class="btn-c btn-primary-c">
            <i class="bi bi-<?= $isEditing ? 'save' : 'plus-lg' ?>"></i>
            <?= $isEditing ? 'Update Role' : 'Add Role' ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── ROLES TABLE ────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header-c">
    <h3>
      <i class="bi bi-list-ul" style="color:var(--crimson)"></i>
      All Roles
      <span class="badge bg-secondary" style="font-size:.65rem;margin-left:.3rem;">
        <?= count($staffRoles) ?>
      </span>
    </h3>
  </div>

  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Position Name</th>
          <th>Description</th>
          <th>Assigned Staff</th>
          <th>Status</th>
          <th>Created</th>
          <th style="width:220px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($staffRoles)): ?>
        <tr>
          <td colspan="6" class="text-center py-5 text-muted">
            <i class="bi bi-tags" style="font-size:2rem;opacity:.25;display:block;margin-bottom:.5rem;"></i>
            No roles yet. Use the form above to add the first one.
          </td>
        </tr>
        <?php endif; ?>

        <?php foreach ($staffRoles as $sr): ?>
        <?php $isCurrentEdit = ($isEditing && (int)$editRow['role_id'] === (int)$sr['role_id']); ?>
        <tr style="<?= $isCurrentEdit ? 'background:var(--crimson-lt);' : '' ?>">
          <td>
            <strong style="font-size:.84rem;"><?= htmlspecialchars($sr['role_name']) ?></strong>
            <?php if ($isCurrentEdit): ?>
            <span class="badge bg-warning text-dark" style="font-size:.6rem;margin-left:.3rem;">Editing</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--n500);font-size:.78rem;">
            <?= $sr['description']
                ? htmlspecialchars($sr['description'])
                : '<span style="color:var(--n300);">—</span>' ?>
          </td>
          <td style="font-size:.78rem;">
            <?php if ($sr['assigned_count'] > 0): ?>
            <a href="users.php?tab=staff" style="color:var(--crimson);text-decoration:none;font-weight:600;">
              <?= $sr['assigned_count'] ?> member<?= $sr['assigned_count'] > 1 ? 's' : '' ?>
            </a>
            <?php else: ?>
            <span style="color:var(--n300);">None</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $sr['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $sr['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td style="color:var(--n400);font-size:.75rem;">
            <?= date('M j, Y', strtotime($sr['created_at'])) ?>
          </td>
          <td>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;">

              <!-- Edit button: GET request to same page with action=edit&id=X -->
              <a href="staff_roles.php?action=edit&id=<?= $sr['role_id'] ?>"
                 class="btn-c <?= $isCurrentEdit ? 'btn-primary-c' : 'btn-secondary-c' ?>"
                 style="font-size:.72rem;padding:.3rem .65rem;">
                <i class="bi bi-pencil"></i> <?= $isCurrentEdit ? 'Editing…' : 'Edit' ?>
              </a>

              <!-- Toggle active/inactive -->
              <a href="staff_roles.php?action=toggle&id=<?= $sr['role_id'] ?>"
                 class="btn-c btn-secondary-c"
                 style="font-size:.72rem;padding:.3rem .65rem;"
                 onclick="return confirm('<?= $sr['is_active'] ? 'Deactivate' : 'Activate' ?> the role &quot;<?= htmlspecialchars(addslashes($sr['role_name'])) ?>&quot;?')">
                <i class="bi bi-<?= $sr['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
              </a>

              <!-- Delete (blocked if in use) -->
              <?php if ($sr['assigned_count'] == 0): ?>
              <a href="staff_roles.php?action=delete&id=<?= $sr['role_id'] ?>"
                 class="btn-c btn-danger-c"
                 style="font-size:.72rem;padding:.3rem .65rem;"
                 onclick="return confirm('Permanently delete &quot;<?= htmlspecialchars(addslashes($sr['role_name'])) ?>&quot;? This cannot be undone.')">
                <i class="bi bi-trash"></i>
              </a>
              <?php else: ?>
              <span class="btn-c btn-secondary-c"
                    style="font-size:.72rem;padding:.3rem .65rem;opacity:.35;cursor:not-allowed;"
                    title="Cannot delete — role is assigned to <?= $sr['assigned_count'] ?> staff member(s)">
                <i class="bi bi-trash"></i>
              </span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include 'layout/footer.php'; ?>
