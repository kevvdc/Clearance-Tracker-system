<?php
// ============================================================
// mailer.php  —  PHPMailer Configuration
// Place PHPMailer library in PHPMailer/src/ directory
// Download from: https://github.com/PHPMailer/PHPMailer
// ============================================================

// ── PHPMailer autoload ───────────────────────────────────────
// If using Composer: require_once 'vendor/autoload.php';
// If manual install:
$phpmailerSrc = __DIR__ . '/PHPMailer/src/';
if (!file_exists($phpmailerSrc . 'PHPMailer.php')) {
    // PHPMailer not installed — log and return null from sendMail()
    define('MAILER_UNAVAILABLE', true);
} else {
    define('MAILER_UNAVAILABLE', false);
    require_once $phpmailerSrc . 'PHPMailer.php';
    require_once $phpmailerSrc . 'SMTP.php';
    require_once $phpmailerSrc . 'Exception.php';
}

// ── SMTP Settings — Update these for your mail provider ──────
define('MAIL_HOST',       'smtp.gmail.com');      // e.g. smtp.gmail.com
define('MAIL_PORT',       587);                    // 587 for TLS, 465 for SSL
define('MAIL_USERNAME',   'kevindelacruz1526@gmail.com');       // Your email address
define('MAIL_PASSWORD',   'chbq goif znsb dcrw');    // Gmail App Password
define('MAIL_FROM',       'kevindelacruz1526@gmail.com');       // Sender address
define('MAIL_FROM_NAME',  'Barangay Clearance System');
define('MAIL_ENCRYPTION', 'tls');                  // 'tls' or 'ssl'

/**
 * Send an email via PHPMailer.
 * Returns true on success, false or error string on failure.
 * Returns 'unavailable' if PHPMailer is not installed (silently skips).
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool|string {
    if (MAILER_UNAVAILABLE) {
        error_log("PHPMailer not available. Would have sent to: $toEmail | Subject: $subject");
        return 'unavailable';
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Server
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl'
                            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Email templates
 */
function mailTemplateApproved(string $name, string $username, string $tempPw): string {
    return '
<div style="font-family:DM Sans,sans-serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e2e8;">
  <div style="background:linear-gradient(135deg,#4a0010,#b91c3c);padding:28px 32px;">
    <h1 style="color:#fff;margin:0;font-size:1.3rem;">Barangay Clearance System</h1>
    <p style="color:rgba(255,255,255,.7);margin:4px 0 0;font-size:.85rem;">Account Approved</p>
  </div>
  <div style="padding:28px 32px;">
    <p style="font-size:.95rem;color:#1a1a1d;margin-bottom:1rem;">Hi <strong>' . htmlspecialchars($name) . '</strong>,</p>
    <p style="color:#44444b;font-size:.9rem;line-height:1.6;">Your account has been <strong style="color:#15803d;">approved and activated</strong> by the barangay administrator. You can now log in using the credentials below.</p>
    <div style="background:#f9f9fb;border:2px dashed #d1d5db;border-radius:10px;padding:18px 22px;margin:20px 0;">
      <p style="margin:0 0 8px;font-size:.8rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Your Login Credentials</p>
      <p style="margin:4px 0;font-size:.9rem;color:#1a1a1d;"><strong>Username:</strong> <code style="background:#fdf0f3;color:#7b0020;padding:2px 8px;border-radius:5px;">' . htmlspecialchars($username) . '</code></p>
      <p style="margin:4px 0;font-size:.9rem;color:#1a1a1d;"><strong>Temporary Password:</strong> <code style="background:#fdf0f3;color:#7b0020;padding:2px 8px;border-radius:5px;">' . htmlspecialchars($tempPw) . '</code></p>
    </div>
    <p style="color:#ef4444;font-size:.8rem;">⚠ Please change your password after logging in for security.</p>
    <p style="margin-top:20px;font-size:.85rem;color:#9696a0;">If you have any questions, contact your barangay office.</p>
  </div>
</div>';
}

function mailTemplateRejected(string $name, string $reason = ''): string {
    $reasonHtml = $reason
        ? '<p style="color:#44444b;font-size:.9rem;line-height:1.6;"><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>'
        : '';
    return '
<div style="font-family:DM Sans,sans-serif;max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e2e8;">
  <div style="background:linear-gradient(135deg,#4a0010,#b91c3c);padding:28px 32px;">
    <h1 style="color:#fff;margin:0;font-size:1.3rem;">Barangay Clearance System</h1>
    <p style="color:rgba(255,255,255,.7);margin:4px 0 0;font-size:.85rem;">Application Update</p>
  </div>
  <div style="padding:28px 32px;">
    <p style="font-size:.95rem;color:#1a1a1d;margin-bottom:1rem;">Hi <strong>' . htmlspecialchars($name) . '</strong>,</p>
    <p style="color:#44444b;font-size:.9rem;line-height:1.6;">Unfortunately, your barangay clearance application has been <strong style="color:#b91c1c;">rejected</strong> by the administrator.</p>
    ' . $reasonHtml . '
    <p style="color:#44444b;font-size:.9rem;line-height:1.6;margin-top:12px;">You may visit the barangay office for clarification or to resubmit your application with the correct information.</p>
    <p style="margin-top:20px;font-size:.85rem;color:#9696a0;">Thank you for using the Barangay Clearance System.</p>
  </div>
</div>';
}
