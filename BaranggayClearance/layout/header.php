<?php
// layout/header.php  —  Admin/Staff shared sidebar layout
requireLogin();
$cur  = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['user_role'] ?? '';

function navLink(string $file, string $cur, string $icon, string $label, array $roles = []): string {
    global $role;
    if (!empty($roles) && !in_array($role, $roles)) return '';
    // Compare base filename; also check full URL for query-string links
    $fileBase = parse_url($file, PHP_URL_PATH) ?? $file;
    $currentFull = $cur . '?' . ($_SERVER['QUERY_STRING'] ?? '');
    $active = ($fileBase === $cur || str_starts_with($currentFull, $file)) ? 'active' : '';
    return "<a href=\"$file\" class=\"nav-link $active\"><i class=\"bi bi-$icon\"></i><span>$label</span></a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Barangay') ?> — Barangay System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --crimson:    #b91c3c;
  --crimson-dk: #7b0020;
  --crimson-900:#4a0010;
  --crimson-lt: #fdf0f3;
  --gold:       #d97706;
  --n900:#0f0f10; --n800:#1a1a1d; --n700:#2d2d32; --n600:#44444b;
  --n500:#6b6b75; --n400:#9696a0; --n300:#c4c4cc; --n200:#e2e2e8; --n100:#f2f2f5; --n50:#f9f9fb;
  --sidebar-w: 250px;
  --topbar-h:  60px;
  --radius-md: 12px;
  --shadow-sm: 0 1px 4px rgba(0,0,0,.07);
  --shadow-md: 0 4px 16px rgba(0,0,0,.1);
}
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
html { font-size:14px; }
body { font-family:'DM Sans',sans-serif; background:var(--n100); color:var(--n800); min-height:100vh; display:flex; -webkit-font-smoothing:antialiased; }

/* ── SIDEBAR ── */
#sidebar {
  width:var(--sidebar-w); min-height:100vh;
  background:linear-gradient(175deg,var(--crimson-900) 0%,var(--crimson-dk) 55%,var(--crimson) 100%);
  position:fixed; top:0; left:0; bottom:0; z-index:300;
  display:flex; flex-direction:column;
  box-shadow:4px 0 24px rgba(0,0,0,.15);
  overflow-y:auto; overflow-x:hidden;
}
.sb-brand { padding:1.5rem 1.25rem 1rem; border-bottom:1px solid rgba(255,255,255,.08); }
.sb-brand-icon { width:40px; height:40px; background:rgba(255,255,255,.15); border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; color:#fff; margin-bottom:.65rem; }
.sb-brand-name { font-family:'DM Serif Display',serif; font-size:1rem; color:#fff; line-height:1.2; }
.sb-brand-sub  { font-size:.65rem; color:rgba(255,255,255,.45); text-transform:uppercase; letter-spacing:.06em; margin-top:.1rem; }

.sb-section { padding:.75rem 1rem .2rem; }
.sb-section-label { font-size:.62rem; font-weight:700; color:rgba(255,255,255,.35); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.4rem; padding:0 .25rem; }

.nav-link {
  display:flex; align-items:center; gap:.65rem;
  padding:.55rem .75rem; border-radius:9px; margin-bottom:.15rem;
  color:rgba(255,255,255,.65); text-decoration:none; font-size:.82rem; font-weight:500;
  transition:all .18s; white-space:nowrap;
}
.nav-link:hover  { background:rgba(255,255,255,.1); color:#fff; }
.nav-link.active { background:rgba(255,255,255,.18); color:#fff; font-weight:700; }
.nav-link i { font-size:1rem; flex-shrink:0; width:18px; text-align:center; }

.sb-user { margin-top:auto; padding:1rem; border-top:1px solid rgba(255,255,255,.08); }
.sb-user-inner { background:rgba(255,255,255,.09); border-radius:11px; padding:.75rem; display:flex; align-items:center; gap:.65rem; }
.sb-avatar { width:34px; height:34px; background:rgba(255,255,255,.2); border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1rem; color:#fff; flex-shrink:0; }
.sb-user-name { font-size:.78rem; font-weight:600; color:#fff; line-height:1.2; }
.sb-user-role { font-size:.65rem; color:rgba(255,255,255,.45); text-transform:capitalize; }
.sb-logout { color:rgba(255,255,255,.5); text-decoration:none; font-size:.78rem; display:flex; align-items:center; gap:.35rem; margin-top:.5rem; padding:.25rem .25rem; transition:color .15s; }
.sb-logout:hover { color:#fca5a5; }

/* ── MAIN ── */
#main-content { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }
.topbar { height:var(--topbar-h); background:#fff; border-bottom:1px solid var(--n200); display:flex; align-items:center; padding:0 1.75rem; position:sticky; top:0; z-index:200; box-shadow:var(--shadow-sm); gap:1rem; }
.topbar-title { font-family:'DM Serif Display',serif; font-size:1.2rem; color:var(--n900); flex:1; }
.page-body { padding:1.75rem; flex:1; }

/* ── CARDS & TABLES ── */
.card { background:#fff; border-radius:var(--radius-md); border:1px solid var(--n200); box-shadow:var(--shadow-sm); }
.card-header-c { padding:.9rem 1.25rem; border-bottom:1px solid var(--n200); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.card-header-c h3 { font-size:.85rem; font-weight:700; color:var(--n800); margin:0; display:flex; align-items:center; gap:.5rem; }
.table { font-size:.82rem; margin:0; }
.table thead th { background:var(--n50); font-size:.7rem; font-weight:700; color:var(--n500); text-transform:uppercase; letter-spacing:.06em; border-bottom:2px solid var(--n200); padding:.65rem .9rem; }
.table tbody td { padding:.65rem .9rem; vertical-align:middle; border-bottom:1px solid var(--n100); }
.table tbody tr:last-child td { border-bottom:none; }
.table tbody tr:hover td { background:var(--n50); }

/* ── BADGES ── */
.badge { font-size:.68rem; font-weight:700; padding:.3rem .65rem; border-radius:6px; }

/* ── STAT CARDS ── */
.stat-card { background:#fff; border-radius:var(--radius-md); border:1px solid var(--n200); padding:1.25rem; display:flex; align-items:center; gap:1rem; }
.stat-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.stat-val { font-size:1.6rem; font-weight:800; color:var(--n900); line-height:1; }
.stat-lbl { font-size:.72rem; color:var(--n500); margin-top:.15rem; }

/* ── BUTTONS ── */
.btn-c  { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem .9rem; border-radius:8px; font-size:.78rem; font-weight:700; font-family:'DM Sans',sans-serif; cursor:pointer; border:none; transition:all .15s; text-decoration:none; }
.btn-primary-c { background:var(--crimson); color:#fff; }
.btn-primary-c:hover { background:var(--crimson-dk); color:#fff; box-shadow:0 4px 12px rgba(185,28,60,.25); }
.btn-secondary-c { background:var(--n100); color:var(--n700); border:1px solid var(--n200); }
.btn-secondary-c:hover { background:var(--n200); color:var(--n800); }
.btn-danger-c { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
.btn-danger-c:hover { background:#fecaca; }
.btn-success-c { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
.btn-success-c:hover { background:#bbf7d0; }

/* ── FORM ── */
.form-label { font-size:.73rem; font-weight:700; color:var(--n600); margin-bottom:.3rem; display:block; letter-spacing:.02em; }
.form-control, .form-select { font-size:.84rem; border:1.5px solid var(--n200); border-radius:9px; }
.form-control:focus, .form-select:focus { border-color:var(--crimson); box-shadow:0 0 0 3px rgba(185,28,60,.1); }

/* ── SEARCH ── */
.search-wrap { position:relative; }
.search-wrap input { padding-left:2.2rem; font-size:.82rem; }
.search-wrap i { position:absolute; left:.7rem; top:50%; transform:translateY(-50%); color:var(--n400); }

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width:4px; } ::-webkit-scrollbar-thumb { background:var(--n300); border-radius:99px; }

@media(max-width:768px){
  #sidebar{transform:translateX(-100%);transition:transform .25s ease;}
  #sidebar.open{transform:translateX(0);}
  #main-content{margin-left:0;}
  .topbar{padding:0 1rem;}
  .page-body{padding:1rem;}
  .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .table{min-width:600px;}
  .stat-card{flex-direction:column;align-items:flex-start;gap:.5rem;}
  .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:299;}
  .sidebar-overlay.open{display:block;}
  .topbar-hamburger{display:flex!important;}
}
.topbar-hamburger{display:none;background:none;border:none;color:var(--n600);font-size:1.2rem;cursor:pointer;padding:.25rem;}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<nav id="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-icon"><i class="bi bi-building-fill"></i></div>
    <div class="sb-brand-name">Barangay Clearance</div>
    <div class="sb-brand-sub">Management System</div>
  </div>

  <div class="sb-section">
    <div class="sb-section-label">Main</div>
    <?= navLink('dashboard.php', $cur, 'speedometer2', 'Dashboard') ?>
    <?= navLink('requests.php',  $cur, 'file-earmark-text', 'Clearance Requests') ?>
    <?= navLink('residents.php', $cur, 'people', 'Residents') ?>
  </div>

  <?php if (isStaff()): ?>
  <div class="sb-section">
    <div class="sb-section-label">Staff</div>
    <?= navLink('walkin.php',    $cur, 'person-badge', 'Walk-in Application', ['admin','staff']) ?>
  </div>
  <?php endif; ?>

  <?php if (isAdmin()): ?>
  <div class="sb-section">
    <div class="sb-section-label">Admin</div>
    <?= navLink('users.php',       $cur, 'shield-person',   'Manage Accounts',  ['admin']) ?>
    <?= navLink('users.php?tab=guests', $cur, 'person-plus-fill', 'Guest Applications', ['admin']) ?>
    <?= navLink('staff_roles.php', $cur, 'tags-fill',        'Staff Roles',      ['admin']) ?>
    <?= navLink('clearance_types.php', $cur, 'tags',         'Clearance Types',  ['admin']) ?>
    <?= navLink('analytics.php',   $cur, 'graph-up-arrow',   'Advanced Analytics',['admin']) ?>
  </div>
  <?php endif; ?>

  <div class="sb-user">
    <div class="sb-user-inner">
      <div class="sb-avatar"><i class="bi bi-person-fill"></i></div>
      <div>
        <div class="sb-user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
        <div class="sb-user-role"><?= htmlspecialchars($_SESSION['user_role'] ?? '') ?></div>
      </div>
    </div>
    <a href="logout.php" class="sb-logout"><i class="bi bi-box-arrow-left"></i> Sign Out</a>
  </div>
</nav>

<!-- ── MAIN ── -->
<div id="sidebar-overlay" class="sidebar-overlay" onclick="closeSidebar()"></div>
<div id="main-content">
  <div class="topbar">
    <button class="topbar-hamburger" id="hamburger" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
    <span class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></span>
    <?php if (isAdmin()): ?>
      <?php
      $pending = $conn->query("SELECT COUNT(*) AS c FROM users WHERE status='pending' AND role='resident'")->fetch_assoc()['c'];
      $gaTableCheck = $conn->query("SHOW TABLES LIKE 'guest_applications'")->num_rows > 0;
      $guestPendingHdr = $gaTableCheck ? $conn->query("SELECT COUNT(*) AS c FROM guest_applications WHERE status='pending'")->fetch_assoc()['c'] : 0;
      $totalPending = $pending + $guestPendingHdr;
      if ($totalPending > 0):
      ?>
      <a href="users.php?tab=<?= $guestPendingHdr > 0 ? 'guests' : 'residents' ?>" class="btn-c btn-danger-c" style="font-size:.72rem;">
        <i class="bi bi-bell-fill"></i> <?= $totalPending ?> Pending
      </a>
      <?php endif; ?>
    <?php endif; ?>
    <span style="font-size:.75rem;color:var(--n400);"><?= date('M j, Y') ?></span>
  </div>
  <div class="page-body">
    <?= getFlash() ?>
