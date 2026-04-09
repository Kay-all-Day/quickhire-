<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $booking_id  = intval($data['booking_id'] ?? 0);
    $provider_id = intval($data['provider_id'] ?? 0);
    $rating      = intval($data['rating'] ?? 0);
    $comment     = trim($data['comment'] ?? '');
    $user_id     = getUserId();

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'error' => 'Rating must be 1-5']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO reviews (booking_id, user_id, provider_id, rating, comment)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$booking_id, $user_id, $provider_id, $rating, $comment]);

    // Update provider's average rating
    $stmt = $pdo->prepare("
        UPDATE service_providers 
        SET rating = (SELECT AVG(rating) FROM reviews WHERE provider_id = ?)
        WHERE provider_id = ?
    ");
    $stmt->execute([$provider_id, $provider_id]);

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'POST required']);
}
