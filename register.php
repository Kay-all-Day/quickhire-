<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $user_type = $_POST['user_type'] ?? 'customer';

    $errors = [];

    if (empty($full_name)) $errors[] = "Full name is required.";
    if (empty($email)) $errors[] = "Email is required.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
    if ($password !== $confirm) $errors[] = "Passwords do not match.";
    if (!in_array($user_type, ['customer', 'provider', 'both'])) $errors[] = "Invalid user type.";

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "An account with this email already exists.";
        }
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone, password, user_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$full_name, $email, $phone, $hashed, $user_type]);

        $user_id = $pdo->lastInsertId();

        if ($user_type === 'provider' || $user_type === 'both') {
            $stmt = $pdo->prepare("INSERT INTO service_providers (user_id, service_category) VALUES (?, 'General')");
            $stmt->execute([$user_id]);
        }

        // Welcome notification
        createNotification($pdo, $user_id, 'system', 'Welcome to QuickHire!', 'Your account is set up. Browse services or complete your profile to get started.', 'dashboard.php');

        $_SESSION['user_id']   = $user_id;
        $_SESSION['full_name'] = $full_name;
        $_SESSION['user_type'] = $user_type;

        // Redirect back to where they were trying to go
        $redirect = $_SESSION['redirect_to'] ?? $_POST['redirect_to'] ?? 'index.php';
        unset($_SESSION['redirect_to']);
        redirect($redirect);
    } else {
        $_SESSION['errors'] = $errors;
        $_SESSION['old_input'] = ['full_name' => $full_name, 'email' => $email, 'phone' => $phone, 'user_type' => $user_type];
        if (!empty($_POST['redirect_to'])) {
            $_SESSION['redirect_to'] = $_POST['redirect_to'];
        }
        redirect('auth.php?tab=register');
    }
} else {
    redirect('auth.php?mode=register');
}
