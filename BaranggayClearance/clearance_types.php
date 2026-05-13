<?php
// clearance_types.php  —  Manage clearance types (Admin only)
require_once 'config.php';
requireAdmin();
$pageTitle = 'Clearance Types';

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($action==='toggle' && $id>0) {
    if (columnExists($conn, 'clearance_type', 'is_active')) {
        $conn->query("UPDATE clearance_type SET is_active = NOT is_active WHERE type_id=$id");
        setFlash('success','Type status updated.');
    } else {
        setFlash('error','Active/inactive toggle not available with current database schema.');
    }
    header('Location: clearance_types.php'); exit;
}
if ($action==='delete' && $id>0) {
    $conn->query("DELETE FROM clearance_type WHERE type_id=$id");
    setFlash('success','Type deleted.'); header('Location: clearance_types.php'); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_type'])) {
    $tid  = (int)($_POST['type_id']     ?? 0);
    $name = trim($_POST['type_name']    ?? '');
    $desc = trim($_POST['description']  ?? '');
    $hasDesc   = columnExists($conn, 'clearance_type', 'description');
    if (!$name) { setFlash('error','Name is required.'); }
    else {
        if ($tid>0) {
            if ($hasDesc) { $s=$conn->prepare("UPDATE clearance_type SET type_name=?,description=? WHERE type_id=?"); $s->bind_param('ssi',$name,$desc,$tid); }
            else          { $s=$conn->prepare("UPDATE clearance_type SET type_name=? WHERE type_id=?"); $s->bind_param('si',$name,$tid); }
        } else {
            if ($hasDesc) { $s=$conn->prepare("INSERT INTO clearance_type (type_name,description) VALUES (?,?)"); $s->bind_param('ss',$name,$desc); }
            else          { $s=$conn->prepare("INSERT INTO clearance_type (type_name) VALUES (?)"); $s->bind_param('s',$name); }
        }
        $s->execute(); $s->close(); setFlash('success','Type saved.');
    }
    header('Location: clearance_types.php'); exit;
}
$editRow = null;
if ($action==='edit' && $id>0) {
    $s=$conn->prepare("SELECT * FROM clearance_type WHERE type_id=?"); $s->bind_param('i',$id); $s->execute();
    $editRow=$s->get_result()->fetch_assoc(); $s->close();
}
$hasActive = columnExists($conn, 'clearance_type', 'is_active');
$types = $conn->query("SELECT *, (SELECT COUNT(*) FROM requests WHERE type_id=clearance_type.type_id) AS req_count FROM clearance_type ORDER BY type_name")->fetch_all(MYSQLI_ASSOC);
include 'layout/header.php';
?>
<?php if ($editRow): ?>
<script>document.addEventListener('DOMContentLoaded',()=>{const m=document.getElementById('typeModal');m.querySelector('[name=type_id]').value='<?=$editRow['type_id']?>';m.querySelector('[name=type_name]').value='<?=htmlspecialchars($editRow['type_name'])?>';m.querySelector('[name=description]').value='<?=htmlspecialchars($editRow['description']??'')?>';new bootstrap.Modal(m).show();});</script>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 style="font-size:.9rem;color:var(--n700);margin:0;">Clearance Types</h3>
  <button class="btn-c btn-primary-c" data-bs-toggle="modal" data-bs-target="#typeModal"><i class="bi bi-plus-lg"></i> Add Type</button>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Type</th><th>Description</th><th>Requests</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($types as $t): ?>
        <tr>
          <td><strong><?=htmlspecialchars($t['type_name'])?></strong></td>
          <td style="font-size:.78rem;color:var(--n500);"><?=htmlspecialchars($t['description']??'—')?></td>
          <td><span class="badge bg-secondary"><?=$t['req_count']?></span></td>
          <td><?php if($hasActive): ?><?=$t['is_active']?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>'?><?php else: ?><span class="badge bg-success">Active</span><?php endif; ?></td>
          <td><div style="display:flex;gap:.35rem;">
            <a href="clearance_types.php?action=edit&id=<?=$t['type_id']?>" class="btn-c btn-secondary-c"><i class="bi bi-pencil"></i></a>
            <?php if($hasActive): ?>
            <a href="clearance_types.php?action=toggle&id=<?=$t['type_id']?>" class="btn-c btn-secondary-c" title="<?=$t['is_active']?'Deactivate':'Activate'?>">
              <i class="bi bi-<?=$t['is_active']?'eye-slash':'eye'?>"></i>
            </a>
            <?php endif; ?>
            <a href="clearance_types.php?action=delete&id=<?=$t['type_id']?>" class="btn-c btn-danger-c" onclick="return confirm('Delete this type?')"><i class="bi bi-trash"></i></a>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal fade" id="typeModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:var(--crimson-lt);border-bottom:1px solid #fbd0d9;">
      <h5 class="modal-title" style="font-family:'DM Serif Display',serif;color:var(--crimson-dk);">Clearance Type</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" action="clearance_types.php">
      <div class="modal-body">
        <input type="hidden" name="save_type" value="1"><input type="hidden" name="type_id" value="0">
        <div class="mb-3"><label class="form-label">Type Name *</label><input type="text" name="type_name" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-c btn-secondary-c" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn-c btn-primary-c"><i class="bi bi-save"></i> Save</button>
      </div>
    </form>
  </div></div>
</div>
<?php include 'layout/footer.php'; ?>
