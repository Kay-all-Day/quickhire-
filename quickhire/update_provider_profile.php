<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

if (!isProvider()) {
    $_SESSION['errors'] = ['Only providers can update this profile.'];
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id          = getUserId();
    $bio              = trim($_POST['bio'] ?? '');
    $service_category = trim($_POST['service_category'] ?? '');
    $experience_years = intval($_POST['experience_years'] ?? 0);
    $availability     = trim($_POST['availability'] ?? '');
    $languages        = trim($_POST['languages'] ?? 'English');
    $avg_response     = trim($_POST['avg_response'] ?? '');

    if (empty($service_category)) {
        $_SESSION['errors'] = ['Service category is required.'];
        redirect('dashboard.php');
    }

    $stmt = $pdo->prepare("
        UPDATE service_providers 
        SET bio = ?, service_category = ?, experience_years = ?, availability = ?, languages = ?, avg_response = ?
        WHERE user_id = ?
    ");
    $stmt->execute([$bio, $service_category, $experience_years, $availability, $languages, $avg_response, $user_id]);

    $_SESSION['success'] = 'Provider profile updated successfully!';
}

redirect('dashboard.php');
