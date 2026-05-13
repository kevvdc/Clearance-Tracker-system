<?php
// ============================================================
// config.php  —  Database, Session, RBAC Helpers
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'baranggay');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#c00;">
        <strong>Database Connection Failed:</strong> ' . $conn->connect_error . '
        <br><small>Ensure XAMPP MySQL is running and the database exists.</small>
    </div>');
}
$conn->set_charset('utf8mb4');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth Helpers ──────────────────────────────────────────
function requireLogin(): void {
    if (empty($_SESSION['logged_in'])) {
        header('Location: index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: dashboard.php');
        exit;
    }
}

function requireStaff(): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'])) {
        header('Location: resident_dashboard.php');
        exit;
    }
}

function isAdmin(): bool   { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function isStaff(): bool   { return in_array($_SESSION['user_role'] ?? '', ['admin','staff']); }
function isResident(): bool{ return ($_SESSION['user_role'] ?? '') === 'resident'; }

function currentUserId(): int  { return (int)($_SESSION['user_id'] ?? 0); }
function currentRole(): string { return $_SESSION['user_role'] ?? ''; }

// ── Flash Messages ────────────────────────────────────────
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash(): string {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = match($f['type']) {
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        default   => 'alert-warning',
    };
    return '<div class="alert ' . $cls . ' alert-dismissible fade show mb-3" role="alert">'
         . '<i class="bi bi-' . ($f['type']==='success'?'check-circle':'exclamation-circle') . '-fill me-2"></i>'
         . htmlspecialchars($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── Audit Trail Helper ─────────────────────────────────────
function logAudit(mysqli $conn, int $requestId, string $action, ?string $oldStatus, ?string $newStatus, ?string $notes = null): void {
    $userId = currentUserId();
    $stmt = $conn->prepare(
        "INSERT INTO request_audit (request_id, action, old_status, new_status, notes, done_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issssi', $requestId, $action, $oldStatus, $newStatus, $notes, $userId);
    $stmt->execute();
    $stmt->close();
}

// ── Name Helper ──────────────────────────────────────────────
// Builds a full_name string from separate name parts.
function buildFullName(string $first, ?string $middle, string $last): string {
    return trim("$first " . ($middle ? "$middle " : '') . $last);
}

// ── Username Generator ────────────────────────────────────
function generateUsername(mysqli $conn, string $firstName, string $lastName): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName))
          . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
    $base = substr($base, 0, 12);
    $candidate = $base;
    $i = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) return $candidate;
        $candidate = $base . $i;
        $i++;
    }
}

// ── Schema Compatibility Helpers ──────────────────────────
// Detects whether new columns exist so old DBs don't crash.
function columnExists(mysqli $conn, string $table, string $col): bool {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $r && $r->num_rows > 0;
}

// Safe query for clearance_type — works with old and new schema
function getClearanceTypes(mysqli $conn): array {
    $hasActive = columnExists($conn, 'clearance_type', 'is_active');
    $hasDesc   = columnExists($conn, 'clearance_type', 'description');
    $cols = 'type_id, type_name' . ($hasDesc ? ', description' : ", '' AS description");
    $where = $hasActive ? ' WHERE is_active=1' : '';
    return $conn->query("SELECT $cols FROM clearance_type$where ORDER BY type_name")->fetch_all(MYSQLI_ASSOC);
}

// Safe INSERT for requests — omits new columns if they don't exist
function insertRequest(mysqli $conn, int $residentId, int $typeId, string $date, string $purpose, string $status, string $source, ?int $handledBy, ?string $notes = null): int {
    $hasSource  = columnExists($conn, 'requests', 'source');
    $hasHandler = columnExists($conn, 'requests', 'handled_by');
    $hasNotes   = columnExists($conn, 'requests', 'notes');

    $cols   = 'resident_id, type_id, date_requested, purpose, status';
    $bind   = 'iisss';
    $vals   = [$residentId, $typeId, $date, $purpose, $status];
    $marks  = '?, ?, ?, ?, ?';

    if ($hasSource)  { $cols .= ', source';     $bind .= 's'; $vals[] = $source;     $marks .= ', ?'; }
    if ($hasHandler) { $cols .= ', handled_by'; $bind .= 'i'; $vals[] = $handledBy;  $marks .= ', ?'; }
    if ($hasNotes)   { $cols .= ', notes';      $bind .= 's'; $vals[] = $notes ?? ''; $marks .= ', ?'; }

    $stmt = $conn->prepare("INSERT INTO requests ($cols) VALUES ($marks)");
    $stmt->bind_param($bind, ...$vals);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

// Safe SELECT source column
function safeSource(array $row): string {
    return $row['source'] ?? 'online';
}

// Check if staff_roles table exists (new schema)
function staffRolesTableExists(mysqli $conn): bool {
    $r = $conn->query("SHOW TABLES LIKE 'staff_roles'");
    return $r && $r->num_rows > 0;
}

// Get all active staff roles (for dropdowns)
function getStaffRoles(mysqli $conn): array {
    if (!staffRolesTableExists($conn)) return [];
    return $conn->query("SELECT * FROM staff_roles WHERE is_active=1 ORDER BY role_name")->fetch_all(MYSQLI_ASSOC);
}

// ── Status Badge ──────────────────────────────────────────
function statusBadge(string $status): string {
    $s = strtolower($status);
    $map = [
        'pending'  => ['bg-warning text-dark', 'clock'],
        'verified' => ['bg-info text-white',   'patch-check'],
        'approved' => ['bg-success text-white', 'check-circle'],
        'released' => ['bg-primary text-white', 'box-seam'],
        'rejected' => ['bg-danger text-white',  'x-circle'],
    ];
    [$cls, $icon] = $map[$s] ?? ['bg-secondary text-white', 'question-circle'];
    return "<span class=\"badge $cls\"><i class=\"bi bi-$icon me-1\"></i>" . ucfirst($s) . "</span>";
}
?>
