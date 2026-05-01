<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id  = intval($_POST['booking_id'] ?? 0);
    $rating      = intval($_POST['rating'] ?? 0);
    $comment     = trim($_POST['comment'] ?? '');
    $review_type = $_POST['review_type'] ?? 'customer';
    $user_id     = getUserId();

    if ($rating < 1 || $rating > 5 || $booking_id <= 0) {
        $_SESSION['errors'] = ['Please fill in all fields correctly.'];
        redirect('dashboard.php');
    }

    // Get booking details to verify and get provider_id
    $stmt = $pdo->prepare("
        SELECT b.*, sp.provider_id, sp.user_id AS provider_user_id
        FROM bookings b
        JOIN service_providers sp ON b.provider_id = sp.provider_id
        WHERE b.booking_id = ? AND b.status = 'completed'
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $_SESSION['errors'] = ['Booking not found or not completed.'];
        redirect('dashboard.php');
    }

    // Verify user is part of this booking
    if ($review_type === 'customer' && $booking['user_id'] != $user_id) {
        $_SESSION['errors'] = ['You can only review bookings you made.'];
        redirect('dashboard.php');
    }
    if ($review_type === 'provider' && $booking['provider_user_id'] != $user_id) {
        $_SESSION['errors'] = ['You can only review bookings assigned to you.'];
        redirect('dashboard.php');
    }

    // Check for duplicate review
    $stmt = $pdo->prepare("SELECT review_id FROM reviews WHERE booking_id = ? AND user_id = ?");
    $stmt->execute([$booking_id, $user_id]);
    if ($stmt->fetch()) {
        $_SESSION['errors'] = ['You have already reviewed this booking.'];
        redirect('dashboard.php');
    }

    // Insert review
    $stmt = $pdo->prepare("
        INSERT INTO reviews (booking_id, user_id, provider_id, rating, comment) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$booking_id, $user_id, $booking['provider_id'], $rating, $comment]);

    // Update provider's average rating (only from customer reviews)
    if ($review_type === 'customer') {
        $stmt = $pdo->prepare("
            UPDATE service_providers 
            SET rating = (
                SELECT AVG(r.rating) FROM reviews r 
                JOIN bookings b ON r.booking_id = b.booking_id
                WHERE r.provider_id = ? AND r.user_id = b.user_id
            )
            WHERE provider_id = ?
        ");
        $stmt->execute([$booking['provider_id'], $booking['provider_id']]);
    }

    $_SESSION['success'] = 'Review submitted successfully!';
}
$redirect = $_POST['redirect_to'] ?? 'dashboard.php';
redirect($redirect);
