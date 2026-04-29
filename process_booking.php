<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id      = getUserId();
    $provider_id  = intval($_POST['provider_id'] ?? 0);
    $service_id   = intval($_POST['service_id'] ?? 0);
    $booking_date = $_POST['booking_date'] ?? '';
    $booking_time = $_POST['booking_time'] ?? '';
    $address      = trim($_POST['address'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    $errors = [];

    if ($provider_id <= 0)   $errors[] = "Please select a provider.";
    if ($service_id <= 0)    $errors[] = "Please select a service.";
    if (empty($booking_date)) $errors[] = "Please choose a date.";
    if (empty($booking_time)) $errors[] = "Please choose a time.";
    if (empty($address))      $errors[] = "Please enter your address.";

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT is_verified, is_available, daily_booking_cap FROM service_providers WHERE provider_id = ?");
        $stmt->execute([$provider_id]);
        $prov = $stmt->fetch();

        if (!$prov || !$prov['is_verified']) {
            $errors[] = "This provider is not yet verified and cannot accept bookings.";
        } elseif (!$prov['is_available']) {
            $errors[] = "This provider is currently not accepting new bookings.";
        } else {
            $datetime = $booking_date . ' ' . $booking_time . ':00';

            // Daily cap check
            $cap = (int)$prov['daily_booking_cap'];
            if ($cap > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM bookings
                    WHERE provider_id = ? AND DATE(booking_date) = ? AND status != 'cancelled'
                ");
                $stmt->execute([$provider_id, $booking_date]);
                $todayCount = (int)$stmt->fetchColumn();
                if ($todayCount >= $cap) {
                    $errors[] = "This provider is fully booked for " . date('j M Y', strtotime($booking_date)) . ". Please choose another date.";
                }
            }

            // Time-slot conflict check
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM bookings
                    WHERE provider_id = ?
                      AND DATE(booking_date) = ?
                      AND HOUR(booking_date) = HOUR(?)
                      AND status != 'cancelled'
                ");
                $stmt->execute([$provider_id, $booking_date, $datetime]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = "That time slot (" . date('g:i A', strtotime($datetime)) . ") is already booked. Please choose a different time.";
                }
            }
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO bookings (user_id, provider_id, service_id, booking_date, address, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$user_id, $provider_id, $service_id, $datetime, $address, $notes]);

        $booking_id = $pdo->lastInsertId();

        // Get service price and create a pending payment
        $stmt = $pdo->prepare("SELECT price FROM services WHERE service_id = ?");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch();

        if ($service) {
            // Ghana taxes (VAT Act 2025, effective Jan 2026)
            $basePrice = $service['price'];
            $vat       = round($basePrice * 0.15, 2);    // 15% VAT
            $nhil      = round($basePrice * 0.025, 2);   // 2.5% NHIL
            $getfund   = round($basePrice * 0.025, 2);   // 2.5% GETFund Levy
            $totalAmount = $basePrice + $vat + $nhil + $getfund;

            $stmt = $pdo->prepare("
                INSERT INTO payments (booking_id, amount, payment_method, payment_status)
                VALUES (?, ?, 'mobile_money', 'pending')
            ");
            $stmt->execute([$booking_id, $totalAmount]);
        }

        $_SESSION['success'] = "Booking confirmed! Your booking ID is #$booking_id.";

        // Notify the provider
        $stmt2 = $pdo->prepare("SELECT user_id FROM service_providers WHERE provider_id = ?");
        $stmt2->execute([$provider_id]);
        $provUser = $stmt2->fetch();
        if ($provUser) {
            createNotification($pdo, $provUser['user_id'], 'booking', 'New Booking Request', getUserName() . ' has booked your service. Check your Manage Bookings tab.', 'dashboard.php');
        }

        redirect('booking.php?success=1');
    } else {
        $_SESSION['errors'] = $errors;
        redirect('booking.php');
    }
} else {
    redirect('booking.php');
}
