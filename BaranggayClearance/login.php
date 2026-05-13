<?php
// login.php  —  Handles POST from index.php login form
require_once 'config.php';

if (!empty($_SESSION['logged_in'])) {
    $role = $_SESSION['user_role'] ?? '';
    header('Location: ' . ($role === 'resident' ? 'resident_dashboard.php' : 'dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, username, password, full_name, role, status FROM users WHERE username = ?"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'suspended') {
                $error = 'Your account has been suspended. Contact the barangay administrator.';
            } elseif ($user['status'] === 'pending') {
                $error = 'Your account is pending admin verification. You will receive an email once approved.';
            } else {
                $_SESSION['logged_in']  = true;
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['user_role']  = $user['role'];

                // If resident, also load resident_id
                if ($user['role'] === 'resident') {
                    $s2 = $conn->prepare("SELECT resident_id FROM residents WHERE user_id = ?");
                    $s2->bind_param('i', $user['user_id']);
                    $s2->execute();
                    $r = $s2->get_result()->fetch_assoc();
                    $s2->close();
                    $_SESSION['resident_id'] = $r['resident_id'] ?? null;
                    header('Location: resident_dashboard.php');
                    exit;
                }

                header('Location: dashboard.php');
                exit;
            }
        } else {
            $error = 'Incorrect username or password.';
        }
    }
}

// Re-show index with error
$loginError = $error;
include 'index.php';
