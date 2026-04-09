<?php
// Notification helper — include this wherever you need to create or count notifications

function createNotification($pdo, $user_id, $type, $title, $message, $link = null) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $type, $title, $message, $link]);
}

function getUnreadCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetch()['total'];
}

function getUnreadMessages($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetch()['total'];
}
