# Barangay Clearance System — Upgrade Guide

## What Changed

### New Files
| File | Purpose |
|------|---------|
| `signup.php` | Normal resident registration with admin approval workflow |
| `mailer.php` | PHPMailer config + email templates |
| `serve_id_image.php` | Secure admin-only endpoint to view uploaded ID images |
| `migration.sql` | DB additions (guest_applications table, new user columns) |
| `baranggay_full.sql` | Complete fresh install (original + migration combined) |
| `PHPMailer/INSTALL.md` | Instructions to install PHPMailer |
| `uploads/id_images/` | Folder for uploaded valid ID images |
| `uploads/id_images/.htaccess` | Blocks direct browser access to uploaded images |

### Modified Files
| File | What Changed |
|------|-------------|
| `index.php` | Two-tab landing: Login/Register + Guest Apply |
| `apply.php` | Rewritten: guest form with ID image upload, saves to `guest_applications`, no auto-account |
| `login.php` | Improved pending/suspended messages |
| `users.php` | Guest Applications tab, approve/reject workflow, email on approval/rejection, Source column for residents, signup approval |
| `layout/header.php` | Responsive sidebar (mobile hamburger), updated pending badge, Guest Applications nav link |
| `layout/footer.php` | Sidebar toggle JS |

---

## Setup Instructions

### 1. Database
**Fresh install:**
```sql
-- Import baranggay_full.sql into your `baranggay` database
```

**Upgrade existing DB:**
```sql
-- Import migration.sql into your existing `baranggay` database
```

### 2. PHPMailer
See `PHPMailer/INSTALL.md`. Then configure `mailer.php`:
```php
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'your@gmail.com');
define('MAIL_PASSWORD', 'your_app_password');
define('MAIL_FROM',     'your@gmail.com');
```
> If PHPMailer is not installed, the system still works — emails are simply skipped (logged to PHP error log). No crashes.

### 3. Uploads Folder Permissions
On Linux/Apache:
```bash
chmod 755 uploads/
chmod 755 uploads/id_images/
```

### 4. Apache .htaccess
Ensure `AllowOverride All` is set in your Apache config so `.htaccess` files in `uploads/id_images/` work.

---

## User Flows

### Guest Application (No Account)
1. User visits `index.php` → clicks **Apply Without Account** tab → goes to `apply.php`
2. Fills: Full Name, Address, Birthdate, Contact, Email, Civil Status, Purpose, Valid ID image
3. Submits → saved to `guest_applications` as **Pending**
4. User sees: "Please wait for an email confirmation..."
5. Admin sees badge in topbar and **Guest Applications** tab in Manage Accounts
6. Admin clicks **View** to see details + ID image
7. Admin clicks **Approve** → account created, credentials emailed via PHPMailer
8. Admin clicks **Reject** → rejection email with optional reason sent

### Normal Sign-Up
1. User visits `index.php` → clicks **Login / Register** tab → clicks **Create Resident Account** → `signup.php`
2. Fills personal info + credentials
3. Submits → `users` row created with `status='pending'`, `signup_source='signup'`
4. Admin sees in **Residents** tab (highlighted yellow, Source badge = "Sign-up")
5. Admin clicks **Approve** → account activated, confirmation email sent

### Admin Approval Email
Sent via `mailer.php → mailTemplateApproved()` containing:
- Username
- Temporary password (for guest apps) or "(your chosen password)" (for sign-ups)

### Rejection Email
Sent via `mailer.php → mailTemplateRejected()` containing optional reason.
