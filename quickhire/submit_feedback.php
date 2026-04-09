<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = getUserId();
    $rating   = intval($_POST['rating'] ?? 0);
    $category = $_POST['category'] ?? 'general';
    $message  = trim($_POST['message'] ?? '');

    $errors = [];

    if ($rating < 1 || $rating > 5) $errors[] = "Please select a rating.";
    if (!in_array($category, ['general', 'usability', 'features', 'performance', 'support', 'service_issue', 'provider_complaint', 'payment_issue', 'other'])) {
        $category = 'general';
    }
    if (empty($message)) $message = '(No comment)';

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO platform_feedback (user_id, rating, category, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $rating, $category, $message]);

        $_SESSION['success'] = "Thank you for your feedback! It helps us improve QuickHire.";
    } else {
        $_SESSION['errors'] = $errors;
    }
}

$redirect = $_POST['redirect_to'] ?? 'dashboard.php';
redirect($redirect);
