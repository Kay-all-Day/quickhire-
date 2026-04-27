<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $errors = [];

    if (empty($email))    $errors[] = "Email is required.";
    if (empty($password))  $errors[] = "Password is required.";

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT user_id, full_name, email, password, user_type FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_type'] = $user['user_type'];

            // Redirect back to where they were trying to go
            $redirect = $_SESSION['redirect_to'] ?? $_POST['redirect_to'] ?? 'index.php';
            unset($_SESSION['redirect_to']);
            redirect($redirect);
        } else {
            $errors[] = "Invalid email or password.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        if (!empty($_POST['redirect_to'])) {
            $_SESSION['redirect_to'] = $_POST['redirect_to'];
        }
        redirect('auth.php');
    }
} else {
    redirect('auth.php?mode=login');
}
