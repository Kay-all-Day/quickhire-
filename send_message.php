<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sender_id   = getUserId();
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $booking_id  = intval($_POST['booking_id'] ?? 0) ?: null;
    $message     = trim($_POST['message'] ?? '');

    if ($receiver_id <= 0 || empty($message)) {
        redirect('messages.php');
    }

    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, booking_id, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$sender_id, $receiver_id, $booking_id, $message]);

    // Create notification for receiver
    $senderName = getUserName();
    createNotification($pdo, $receiver_id, 'message', 'New message from ' . $senderName, substr($message, 0, 100), "messages.php?with=$sender_id");

    redirect("messages.php?with=$receiver_id" . ($booking_id ? "&booking=$booking_id" : ''));
}

redirect('messages.php');
