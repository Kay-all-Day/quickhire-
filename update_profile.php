<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');

    if (empty($full_name) || empty($email)) {
        $_SESSION['errors'] = ['Name and email are required.'];
        redirect('dashboard.php');
    }

    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
    $stmt->execute([$full_name, $email, $phone, getUserId()]);

    $_SESSION['full_name'] = $full_name;
    $_SESSION['success'] = 'Profile updated successfully!';
}
redirect('dashboard.php');
