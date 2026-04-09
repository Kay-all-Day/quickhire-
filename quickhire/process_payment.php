<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_id     = intval($_POST['payment_id'] ?? 0);
    $booking_id     = intval($_POST['booking_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $user_id        = getUserId();

    // Validate
    if ($payment_id <= 0 || $booking_id <= 0) {
        $_SESSION['errors'] = ['Invalid payment request.'];
        redirect('dashboard.php');
    }

    if (!in_array($payment_method, ['mobile_money', 'card', 'cash'])) {
        $_SESSION['errors'] = ['Please select a payment method.'];
        redirect("make_payment.php?booking_id=$booking_id");
    }

    // Verify this booking belongs to the user and is completed
    $stmt = $pdo->prepare("
        SELECT b.booking_id, b.status, b.user_id, p.payment_id, p.payment_status, p.amount
        FROM bookings b
        JOIN payments p ON p.booking_id = b.booking_id
        WHERE b.booking_id = ? AND p.payment_id = ?
    ");
    $stmt->execute([$booking_id, $payment_id]);
    $record = $stmt->fetch();

    if (!$record || $record['user_id'] != $user_id) {
        $_SESSION['errors'] = ['You do not have permission to make this payment.'];
        redirect('dashboard.php');
    }

    if ($record['status'] !== 'completed') {
        $_SESSION['errors'] = ['The service must be completed before payment.'];
        redirect('dashboard.php');
    }

    if ($record['payment_status'] === 'completed') {
        $_SESSION['errors'] = ['This booking has already been paid for.'];
        redirect('dashboard.php');
    }

    // Mobile money validation
    if ($payment_method === 'mobile_money') {
        $network = $_POST['momo_network'] ?? '';
        $phone   = trim($_POST['momo_phone'] ?? '');
        if (empty($network) || empty($phone)) {
            $_SESSION['errors'] = ['Please enter your mobile money details.'];
            redirect("make_payment.php?booking_id=$booking_id");
        }
    }

    // Card validation (basic)
    if ($payment_method === 'card') {
        $card_number = trim($_POST['card_number'] ?? '');
        $card_expiry = trim($_POST['card_expiry'] ?? '');
        $card_cvv    = trim($_POST['card_cvv'] ?? '');
        if (empty($card_number) || empty($card_expiry) || empty($card_cvv)) {
            $_SESSION['errors'] = ['Please enter your card details.'];
            redirect("make_payment.php?booking_id=$booking_id");
        }
    }

    // Process payment — recalculate with taxes
    // Get base service price
    $stmtPrice = $pdo->prepare("
        SELECT s.price FROM bookings b 
        LEFT JOIN services s ON b.service_id = s.service_id 
        WHERE b.booking_id = ?
    ");
    $stmtPrice->execute([$booking_id]);
    $svcPrice = $stmtPrice->fetch();
    $basePrice = $svcPrice['price'] ?? $record['amount'];
    $taxedAmount = round($basePrice * 1.20, 2);

    $stmt = $pdo->prepare("
        UPDATE payments SET payment_method = ?, payment_status = 'completed', amount = ? WHERE payment_id = ?
    ");
    $stmt->execute([$payment_method, $taxedAmount, $payment_id]);

    // Notify provider of payment received
    $stmt = $pdo->prepare("
        SELECT b.provider_id, sp.user_id AS provider_user_id 
        FROM bookings b JOIN service_providers sp ON b.provider_id = sp.provider_id 
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$booking_id]);
    $prov = $stmt->fetch();
    if ($prov) {
        createNotification($pdo, $prov['provider_user_id'], 'payment', 'Payment Received', 'You received GH₵ ' . number_format($taxedAmount, 2) . ' for booking #' . $booking_id . '.', 'dashboard.php');

        // For cash payments, create a commission record the provider owes QuickHire
        if ($payment_method === 'cash') {
            $commissionAmount = round($basePrice * 0.10, 2);
            $stmt = $pdo->prepare("INSERT INTO provider_commissions (booking_id, provider_id, amount) VALUES (?, ?, ?)");
            $stmt->execute([$booking_id, $prov['provider_id'], $commissionAmount]);

            createNotification($pdo, $prov['provider_user_id'], 'commission', 'Commission Due', 'A 10% platform commission of GH₵ ' . number_format($commissionAmount, 2) . ' is due for cash booking #' . $booking_id . '. Pay from your dashboard.', 'dashboard.php');
        }
    }

    redirect("receipt.php?booking_id=$booking_id");

} else {
    redirect('dashboard.php');
}
