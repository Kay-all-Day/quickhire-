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
    $bio               = trim($_POST['bio'] ?? '');
    $service_category  = trim($_POST['service_category'] ?? '');
    $experience_years  = intval($_POST['experience_years'] ?? 0);
    $availability      = trim($_POST['availability'] ?? '');
    $languages         = trim($_POST['languages'] ?? 'English');
    $avg_response      = trim($_POST['avg_response'] ?? '');
    $is_available      = isset($_POST['is_available']) ? 1 : 0;
    $daily_booking_cap = max(0, intval($_POST['daily_booking_cap'] ?? 0));

    if (empty($service_category)) {
        $_SESSION['errors'] = ['Service category is required.'];
        redirect('dashboard.php');
    }

    $stmt = $pdo->prepare("
        UPDATE service_providers
        SET bio = ?, service_category = ?, experience_years = ?, availability = ?,
            languages = ?, avg_response = ?, is_available = ?, daily_booking_cap = ?
        WHERE user_id = ?
    ");
    $stmt->execute([$bio, $service_category, $experience_years, $availability, $languages, $avg_response, $is_available, $daily_booking_cap, $user_id]);

    $_SESSION['success'] = 'Provider profile updated successfully!';
}

redirect('dashboard.php');
