<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

if (!isProvider()) {
    $_SESSION['errors'] = ['Only providers can request featured listings.'];
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan = intval($_POST['plan'] ?? 0);
    $user_id = getUserId();

    // Get provider_id
    $stmt = $pdo->prepare("SELECT provider_id, is_featured FROM service_providers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $provider = $stmt->fetch();

    if (!$provider) {
        $_SESSION['errors'] = ['Provider profile not found.'];
        redirect('dashboard.php');
    }

    if ($provider['is_featured']) {
        $_SESSION['errors'] = ['You are already featured.'];
        redirect('dashboard.php');
    }

    // Check for existing pending request
    $stmt = $pdo->prepare("SELECT id FROM featured_requests WHERE provider_id = ? AND request_status = 'pending'");
    $stmt->execute([$provider['provider_id']]);
    if ($stmt->fetch()) {
        $_SESSION['errors'] = ['You already have a pending featured request.'];
        redirect('dashboard.php');
    }

    // Plan pricing
    $pricing = [
        7  => 50.00,
        30 => 150.00,
        90 => 350.00,
    ];

    if (!isset($pricing[$plan])) {
        $_SESSION['errors'] = ['Invalid plan selected.'];
        redirect('dashboard.php');
    }

    $fee = $pricing[$plan];

    // Create the request
    $stmt = $pdo->prepare("INSERT INTO featured_requests (provider_id, duration_days, fee) VALUES (?, ?, ?)");
    $stmt->execute([$provider['provider_id'], $plan, $fee]);
    $request_id = $pdo->lastInsertId();

    // Redirect to payment page
    redirect("pay_featured.php?request_id=$request_id");
}

redirect('dashboard.php');
