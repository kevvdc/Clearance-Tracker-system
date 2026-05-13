<?php
// index.php — Public landing page
require_once 'config.php';

// Already logged in? Redirect by role
if (!empty($_SESSION['logged_in'])) {
    $role = $_SESSION['user_role'] ?? '';
    header('Location: ' . ($role === 'resident' ? 'resident_dashboard.php' : 'dashboard.php'));
    exit;
}

$loginError = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Barangay Clearance System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --crimson:#b91c3c; --crimson-dk:#7b0020; --crimson-lt:#fdf0f3;
  --gold:#d97706; --gold-lt:#fef3c7;
  --n900:#0f0f10; --n800:#1a1a1d; --n600:#44444b; --n400:#9696a0; --n200:#e2e2e8; --n50:#f9f9fb;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--n50);min-height:100vh;-webkit-font-smoothing:antialiased;}

/* ── HERO ── */
.hero{
  background:linear-gradient(145deg,#4a0010 0%,#7b0020 40%,#b91c3c 100%);
  min-height:100vh;display:flex;flex-direction:column;position:relative;overflow:hidden;
}
.hero::before{
  content:'';position:absolute;left:-120px;top:15%;width:420px;height:420px;border-radius:50%;
  background:radial-gradient(circle,transparent 38%,rgba(255,255,255,.18) 40%,transparent 44%),
             radial-gradient(circle,transparent 55%,rgba(255,255,255,.14) 57%,transparent 61%),
             radial-gradient(circle,transparent 70%,rgba(255,255,255,.10) 72%,transparent 76%);
  filter:blur(10px);pointer-events:none;
}
.hero::after{
  content:'';position:absolute;left:-40px;bottom:5%;width:280px;height:280px;border-radius:50%;
  background:radial-gradient(circle,transparent 35%,rgba(255,255,255,.16) 37%,transparent 42%),
             radial-gradient(circle,transparent 60%,rgba(255,255,255,.12) 62%,transparent 66%);
  filter:blur(8px);pointer-events:none;
}

.hero-nav{
  padding:1.25rem 2.5rem;display:flex;align-items:center;justify-content:space-between;
  border-bottom:1px solid rgba(255,255,255,.08);position:relative;z-index:1;
}
.brand{display:flex;align-items:center;gap:.75rem;color:#fff;text-decoration:none;}
.brand-icon{width:42px;height:42px;background:rgba(255,255,255,.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;}
.brand-name{font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;}

.hero-body{
  flex:1;display:grid;grid-template-columns:1fr 500px;gap:3rem;align-items:center;
  padding:3rem 2.5rem;max-width:1180px;margin:0 auto;width:100%;position:relative;z-index:1;
}
.hero-left{color:#fff;}
.hero-eyebrow{font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.55);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
.hero-eyebrow::before{content:'';display:block;width:24px;height:2px;background:var(--gold);border-radius:99px;}
.hero-title{font-family:'DM Serif Display',serif;font-size:3.2rem;line-height:1.12;color:#fff;margin-bottom:1.25rem;}
.hero-title em{color:#fca5a5;font-style:normal;}
.hero-sub{font-size:.95rem;color:rgba(255,255,255,.65);line-height:1.75;max-width:460px;margin-bottom:2rem;}
.hero-badges{display:flex;gap:.6rem;flex-wrap:wrap;}
.hbadge{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.8);font-size:.72rem;font-weight:600;padding:.3rem .8rem;border-radius:99px;display:flex;align-items:center;gap:.4rem;}

/* ── TWO-PANEL CARD ── */
.entry-card{
  background:#fff;border-radius:20px;padding:0;
  box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;
}
.entry-tabs{display:grid;grid-template-columns:1fr 1fr;}
.entry-tab-btn{
  padding:.9rem 1rem;border:none;background:var(--n50);color:var(--n600);
  font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;
  border-bottom:2px solid var(--n200);transition:all .18s;display:flex;align-items:center;justify-content:center;gap:.45rem;
}
.entry-tab-btn.active{background:#fff;color:var(--crimson);border-bottom-color:var(--crimson);}
.entry-tab-btn:first-child{border-radius:20px 0 0 0;}
.entry-tab-btn:last-child{border-radius:0 20px 0 0;}

.entry-panel{padding:1.75rem 2rem;}
.entry-panel.hidden{display:none;}

.entry-panel h2{font-family:'DM Serif Display',serif;font-size:1.4rem;color:var(--n900);margin-bottom:.25rem;}
.entry-panel .subtitle{font-size:.78rem;color:var(--n400);margin-bottom:1.5rem;}

.form-label{font-size:.75rem;font-weight:700;color:var(--n600);margin-bottom:.3rem;display:block;letter-spacing:.02em;}
.form-control,.form-select{width:100%;padding:.6rem .85rem;border:1.5px solid var(--n200);border-radius:10px;font-size:.87rem;color:var(--n800);font-family:'DM Sans',sans-serif;transition:border-color .15s,box-shadow .15s;}
.form-control:focus,.form-select:focus{border-color:var(--crimson);box-shadow:0 0 0 3px rgba(185,28,60,.12);outline:none;}
.mb-f{margin-bottom:.85rem;}

.btn-primary-c{width:100%;padding:.7rem;background:linear-gradient(135deg,var(--crimson),var(--crimson-dk));border:none;border-radius:10px;color:#fff;font-size:.88rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:.5rem;}
.btn-primary-c:hover{box-shadow:0 6px 22px rgba(185,28,60,.3);transform:translateY(-1px);}

.btn-outline-c{width:100%;padding:.68rem;background:transparent;border:2px solid var(--crimson);border-radius:10px;color:var(--crimson);font-size:.88rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none;}
.btn-outline-c:hover{background:var(--crimson-lt);color:var(--crimson);}

.btn-gold-c{width:100%;padding:.68rem;background:linear-gradient(135deg,#d97706,#b45309);border:none;border-radius:10px;color:#fff;font-size:.88rem;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none;}
.btn-gold-c:hover{box-shadow:0 6px 22px rgba(217,119,6,.3);transform:translateY(-1px);}

.divider{display:flex;align-items:center;gap:.75rem;margin:1.1rem 0;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--n200);}
.divider span{font-size:.72rem;color:var(--n400);font-weight:600;white-space:nowrap;}

.error-box{background:#fff1f2;border:1px solid #fecdd3;border-left:4px solid var(--crimson);border-radius:10px;padding:.7rem 1rem;font-size:.8rem;color:var(--crimson-dk);font-weight:500;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}

.pw-wrap{position:relative;}
.pw-wrap .form-control{padding-right:2.8rem;}
.pw-toggle{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--n400);cursor:pointer;font-size:.95rem;padding:0;}

.guest-info{background:var(--gold-lt);border:1.5px solid #fde68a;border-radius:12px;padding:1.1rem;margin-bottom:1.1rem;}
.guest-info .gi-title{font-size:.78rem;font-weight:700;color:#92400e;margin-bottom:.4rem;display:flex;align-items:center;gap:.4rem;}
.guest-info .gi-text{font-size:.76rem;color:#78350f;line-height:1.55;}
.guest-steps{list-style:none;padding:0;margin:.5rem 0 0;}
.guest-steps li{font-size:.75rem;color:#78350f;display:flex;align-items:flex-start;gap:.4rem;margin-bottom:.3rem;}
.guest-steps li i{color:#d97706;flex-shrink:0;margin-top:1px;}

.footer-note{text-align:center;font-size:.7rem;color:var(--n400);margin-top:1.1rem;}
.footer-note a{color:var(--crimson);}

@media(max-width:860px){.hero-body{grid-template-columns:1fr;}.hero-left{display:none;}.hero-body{padding:2rem 1.25rem;}.hero-nav{padding:1rem 1.25rem;}}
@media(max-width:480px){.entry-tabs{grid-template-columns:1fr;}.entry-tab-btn:first-child{border-radius:0;}.entry-tab-btn:last-child{border-radius:0;}.entry-panel{padding:1.25rem;}}
</style>
</head>
<body>
<div class="hero">
  <nav class="hero-nav">
    <a href="index.php" class="brand">
      <div class="brand-icon"><i class="bi bi-building-fill"></i></div>
      <span class="brand-name">Barangay Clearance System</span>
    </a>
  </nav>

  <div class="hero-body">
    <!-- Left: Branding -->
    <div class="hero-left">
      <div class="hero-eyebrow"><span>Official Digital Service</span></div>
      <h1 class="hero-title">Fast, Secure<br><em>Barangay</em><br>Clearances</h1>
      <p class="hero-sub">Apply for barangay clearances and certificates online. Walk-in applicants are also accommodated through our staff portal.</p>
      <div class="hero-badges">
        <span class="hbadge"><i class="bi bi-shield-check-fill"></i> Secure &amp; Verified</span>
        <span class="hbadge"><i class="bi bi-people-fill"></i> Role-Based Access</span>
        <span class="hbadge"><i class="bi bi-clock-fill"></i> Real-Time Tracking</span>
        <span class="hbadge"><i class="bi bi-file-earmark-check-fill"></i> Digital Records</span>
      </div>
    </div>

    <!-- Right: Entry Card -->
    <div class="entry-card">
      <!-- Tab switcher -->
      <div class="entry-tabs">
        <button class="entry-tab-btn active" onclick="showTab('login')" id="tab-login">
          <i class="bi bi-box-arrow-in-right"></i> Login / Register
        </button>
        <button class="entry-tab-btn" onclick="showTab('guest')" id="tab-guest">
          <i class="bi bi-file-earmark-plus"></i> Apply Without Account
        </button>
      </div>

      <!-- ── LOGIN PANEL ── -->
      <div class="entry-panel" id="panel-login">
        <h2>Welcome Back</h2>
        <p class="subtitle">Sign in to your resident or staff account</p>

        <?php if (!empty($loginError)): ?>
        <div class="error-box"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="on">
          <div class="mb-f">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" placeholder="Enter username"
                   autocomplete="username" required autofocus>
          </div>
          <div class="mb-f">
            <label class="form-label">Password</label>
            <div class="pw-wrap">
              <input type="password" id="pw" name="password" class="form-control" placeholder="Enter password"
                     autocomplete="current-password" required>
              <button type="button" class="pw-toggle" onclick="togglePw()"><i class="bi bi-eye" id="pwIcon"></i></button>
            </div>
          </div>
          <button type="submit" class="btn-primary-c">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
          </button>
        </form>

        <div class="divider"><span>New to the system?</span></div>
        <a href="signup.php" class="btn-outline-c">
          <i class="bi bi-person-plus-fill"></i> Create Resident Account
        </a>

        <p class="footer-note">
          Staff &amp; Admin accounts are created by the administrator.<br>
          <a href="apply.php">Apply as guest</a> if you don't have an account.
        </p>
      </div>

      <!-- ── GUEST APPLY PANEL ── -->
      <div class="entry-panel hidden" id="panel-guest">
        <h2>Apply Without Account</h2>
        <p class="subtitle">Submit a clearance request — no sign-up needed</p>

        <div class="guest-info">
          <div class="gi-title"><i class="bi bi-info-circle-fill"></i> How it works</div>
          <ul class="guest-steps">
            <li><i class="bi bi-1-circle-fill"></i>Fill out your personal details and upload a valid ID image.</li>
            <li><i class="bi bi-2-circle-fill"></i>Your request is saved as <strong>Pending Verification</strong>.</li>
            <li><i class="bi bi-3-circle-fill"></i>Admin reviews your application and ID.</li>
            <li><i class="bi bi-4-circle-fill"></i>Upon approval, your account is created and credentials are emailed to you.</li>
          </ul>
        </div>

        <a href="apply.php" class="btn-gold-c">
          <i class="bi bi-file-earmark-arrow-up-fill"></i> Start Guest Application
        </a>

        <div class="divider"><span>Already have an account?</span></div>
        <button class="btn-outline-c" onclick="showTab('login')">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function showTab(tab) {
  document.getElementById('panel-login').classList.toggle('hidden', tab !== 'login');
  document.getElementById('panel-guest').classList.toggle('hidden', tab !== 'guest');
  document.getElementById('tab-login').classList.toggle('active', tab === 'login');
  document.getElementById('tab-guest').classList.toggle('active', tab === 'guest');
}
function togglePw() {
  const i = document.getElementById('pw'), ic = document.getElementById('pwIcon');
  i.type = i.type === 'password' ? 'text' : 'password';
  ic.className = i.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}
// Auto-show guest tab if redirected from apply-without-account link
if (window.location.hash === '#guest') showTab('guest');
</script>
</body>
</html>
