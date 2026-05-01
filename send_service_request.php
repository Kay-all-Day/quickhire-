<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sender_id        = getUserId();
    $provider_user_id = intval($_POST['provider_user_id'] ?? 0);
    $provider_id      = intval($_POST['provider_id'] ?? 0);
    $service_id       = intval($_POST['service_id'] ?? 0);
    $message          = trim($_POST['message'] ?? '');

    if ($provider_user_id <= 0 || empty($message)) {
        $_SESSION['errors'] = ['Please describe your request.'];
        redirect("provider.php?id=$provider_id");
    }

    // Build the message with service context
    $fullMessage = $message;
    if ($service_id > 0) {
        $stmt = $pdo->prepare("SELECT service_name, price FROM services WHERE service_id = ?");
        $stmt->execute([$service_id]);
        $svc = $stmt->fetch();
        if ($svc) {
            $fullMessage = "📋 Service Request: " . $svc['service_name'] . " (GH₵" . number_format($svc['price'], 2) . ")\n\n" . $message;
        }
    } else {
        $fullMessage = "📋 General Inquiry\n\n" . $message;
    }

    // Send the message
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$sender_id, $provider_user_id, $fullMessage]);

    // Notify the provider
    $senderName = getUserName();
    createNotification($pdo, $provider_user_id, 'message', 'New Service Request from ' . $senderName, substr($message, 0, 100), "messages.php?with=$sender_id");

    redirect("provider.php?id=$provider_id&sent=1");
}

redirect('categories.php');
