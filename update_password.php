<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->execute([getUserId()]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password'])) {
        $_SESSION['errors'] = ['Current password is incorrect.'];
    } elseif (strlen($new) < 6) {
        $_SESSION['errors'] = ['New password must be at least 6 characters.'];
    } elseif ($new !== $confirm) {
        $_SESSION['errors'] = ['New passwords do not match.'];
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->execute([$hashed, getUserId()]);
        $_SESSION['success'] = 'Password updated successfully!';
    }
}
redirect('dashboard.php');
