<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $action     = $_POST['action'] ?? '';
    $user_id    = getUserId();

    if ($booking_id <= 0 || !in_array($action, ['accept', 'decline', 'complete'])) {
        $_SESSION['errors'] = ['Invalid request.'];
        redirect('dashboard.php');
    }

    // Verify this user is the provider for this booking
    $stmt = $pdo->prepare("
        SELECT b.booking_id, b.status, b.user_id AS customer_id, sp.user_id AS provider_user_id
        FROM bookings b
        JOIN service_providers sp ON b.provider_id = sp.provider_id
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking || $booking['provider_user_id'] != $user_id) {
        $_SESSION['errors'] = ['You do not have permission to update this booking.'];
        redirect('dashboard.php');
    }

    // Map action to status
    $statusMap = [
        'accept'   => 'accepted',
        'decline'  => 'cancelled',
        'complete' => 'completed',
    ];

    // Validate status transitions
    $allowed = [
        'pending'  => ['accept', 'decline'],
        'accepted' => ['complete'],
    ];

    $currentStatus = $booking['status'];
    if (!isset($allowed[$currentStatus]) || !in_array($action, $allowed[$currentStatus])) {
        $_SESSION['errors'] = ['This booking cannot be ' . $statusMap[$action] . ' in its current state.'];
        redirect('dashboard.php');
    }

    // Update status
    $newStatus = $statusMap[$action];
    $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE booking_id = ?");
    $stmt->execute([$newStatus, $booking_id]);

    // Notify customer
    $providerName = getUserName();
    $notifTypes = [
        'accepted'  => ['booking_accepted', 'Booking Accepted', "$providerName has accepted your booking #$booking_id. Your service is confirmed!"],
        'cancelled' => ['booking_declined', 'Booking Declined', "$providerName has declined your booking #$booking_id."],
        'completed' => ['booking_completed', 'Service Completed', "$providerName has completed your service. You can now make payment and leave a review."],
    ];
    $nt = $notifTypes[$newStatus];
    createNotification($pdo, $booking['customer_id'], $nt[0], $nt[1], $nt[2], 'dashboard.php');

    $messages = [
        'accepted'  => 'Booking accepted! The customer has been notified.',
        'cancelled' => 'Booking declined.',
        'completed' => 'Booking marked as completed. The customer can now make payment and leave a review.',
    ];

    $_SESSION['success'] = $messages[$newStatus];
}

redirect('dashboard.php');
