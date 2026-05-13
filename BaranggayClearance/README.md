# Barangay Clearance System

A PHP + MySQL web application for managing barangay clearance requests.

---

## Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.4+
- XAMPP / WAMP / LAMP stack

---

## Setup Instructions

1. **Import Database**
   - Open phpMyAdmin
   - Create a database named `baranggay`
   - Import `baranggay.sql`

2. **Configure Connection**
   - Edit `config.php` and update `DB_HOST`, `DB_USER`, `DB_PASS` as needed

3. **Seed Default Accounts**
   - Visit `http://localhost/baranggay_fixed/setup.php` in your browser
   - This creates default admin, staff, and resident test accounts
   - **Delete `setup.php` after first run**

4. **Login**
   - Visit `index.php`

---

## Default Credentials (after running setup.php)

| Role            | Username    | Password   |
|-----------------|-------------|------------|
| Admin           | `admin`     | `admin123` |
| Staff (Captain) | `staff1`    | `staff123` |
| Resident        | `resident1` | `res123`   |

---

## Changelog — Staff Role Management Fixes

### 1. Edit Staff Roles — Fixed
- Editing staff roles now works correctly via the dedicated **Staff Roles** page
- Form submissions update existing rows and display success/error feedback properly
- Duplicate role name detection prevents conflicts

### 2. Staff Roles as a Separate Page — Implemented
- `staff_roles.php` is now a standalone page (not a modal inside users.php)
- Accessible from the sidebar under **Admin → Staff Roles**
- Full CRUD: Add, Edit, Activate/Deactivate, Delete roles
- Delete is blocked if the role is currently assigned to any staff member

### 3. "Manage Staff Roles" Button in Staff Tab — Added
- The **Staff** tab in Manage Accounts now shows a **"Manage Staff Roles"** button
- Clicking it redirects directly to `staff_roles.php`

### 4. Standardized Name Fields — Implemented
- `users` table now has `first_name`, `middle_name`, `last_name` columns
  alongside `full_name` (derived, kept for backward compatibility)
- Staff/Admin account forms use separate First Name / Middle Name / Last Name fields
- The Staff Members table displays names in separate columns (Last, First, Middle)
- All inserts/updates across `apply.php`, `walkin.php`, `residents.php`, `setup.php`
  populate the new name columns alongside `full_name`

### 5. Consistency — Applied Across
- `baranggay.sql` — schema updated with new `users` columns
- `config.php` — `buildFullName()` helper added
- `users.php` — modal form updated; edit modal pre-fills all name fields
- `staff_roles.php` — new standalone page
- `setup.php` — seed accounts use new name columns
- `apply.php`, `walkin.php`, `residents.php` — all use new name columns
- `layout/header.php` — Staff Roles added to sidebar nav
