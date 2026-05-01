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
    $stmtPrice = $pdo->prepare("
        SELECT s.price FROM bookings b
        LEFT JOIN services s ON b.service_id = s.service_id
        WHERE b.booking_id = ?
    ");
    $stmtPrice->execute([$booking_id]);
    $svcPrice    = $stmtPrice->fetch();
    $basePrice   = $svcPrice['price'] ?? $record['amount'];
    $taxedAmount = round($basePrice * 1.20, 2);

    // Pre-fetch provider info (needed for payout row and notifications)
    $stmtProv = $pdo->prepare("
        SELECT b.provider_id, sp.user_id AS provider_user_id
        FROM bookings b JOIN service_providers sp ON b.provider_id = sp.provider_id
        WHERE b.booking_id = ?
    ");
    $stmtProv->execute([$booking_id]);
    $prov = $stmtProv->fetch();

    // Payout breakdown amounts
    $grossAmount      = $taxedAmount;                              // customer pays basePrice * 1.20
    $taxAmount        = round($basePrice * 0.20, 2);               // held for GRA
    $commissionAmount = round($basePrice * 0.10, 2);               // platform fee
    $payoutAmount     = round($basePrice - $commissionAmount, 2);  // provider receives

    if (in_array($payment_method, ['card', 'mobile_money'])) {
        // Atomically update the payment record and release the provider payout
        $payoutRef = 'PAYOUT-' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE payments SET payment_method = ?, payment_status = 'completed', amount = ?
                WHERE payment_id = ?
            ")->execute([$payment_method, $taxedAmount, $payment_id]);

            if ($prov) {
                $pdo->prepare("
                    INSERT INTO provider_payouts
                        (booking_id, provider_id, gross_amount, commission_amount, tax_amount,
                         payout_amount, payment_method, payout_method, payout_reference, status, released_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'platform', ?, 'released', NOW())
                ")->execute([
                    $booking_id, $prov['provider_id'], $grossAmount, $commissionAmount,
                    $taxAmount, $payoutAmount, $payment_method, $payoutRef,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['errors'] = ['Payment processing failed. Please try again.'];
            redirect("make_payment.php?booking_id=$booking_id");
        }
    } else {
        // Cash: plain update — no payout row; commission is owed by the provider
        $pdo->prepare("
            UPDATE payments SET payment_method = ?, payment_status = 'completed', amount = ?
            WHERE payment_id = ?
        ")->execute([$payment_method, $taxedAmount, $payment_id]);
    }

    // Notify provider
    if ($prov) {
        if ($payment_method === 'cash') {
            createNotification($pdo, $prov['provider_user_id'], 'payment', 'Payment Received',
                'You received GH₵ ' . number_format($taxedAmount, 2) . ' for booking #' . $booking_id . '.',
                'dashboard.php');

            // For cash payments, create a commission record the provider owes QuickHire
            $commissionAmount = round($basePrice * 0.10, 2);
            $stmt = $pdo->prepare("INSERT INTO provider_commissions (booking_id, provider_id, amount) VALUES (?, ?, ?)");
            $stmt->execute([$booking_id, $prov['provider_id'], $commissionAmount]);

            createNotification($pdo, $prov['provider_user_id'], 'commission', 'Commission Due',
                'A 10% platform commission of GH₵ ' . number_format($commissionAmount, 2) . ' is due for cash booking #' . $booking_id . '. Pay from your dashboard.',
                'dashboard.php');
        } elseif (in_array($payment_method, ['card', 'mobile_money'])) {
            createNotification($pdo, $prov['provider_user_id'], 'payment', 'Payout Released',
                'Payout of GH₵ ' . number_format($payoutAmount, 2) . ' released for booking #' . $booking_id . '. Platform commission GH₵ ' . number_format($commissionAmount, 2) . ' was deducted automatically.',
                'dashboard.php');
        }
    }

    redirect("receipt.php?booking_id=$booking_id");

} else {
    redirect('dashboard.php');
}
