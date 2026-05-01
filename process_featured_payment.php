<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id     = intval($_POST['request_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $user_id        = getUserId();

    if ($request_id <= 0 || !in_array($payment_method, ['mobile_money', 'card'])) {
        $_SESSION['errors'] = ['Invalid payment request.'];
        redirect('dashboard.php');
    }

    // Verify ownership
    $stmt = $pdo->prepare("
        SELECT fr.*, sp.user_id AS provider_user_id
        FROM featured_requests fr
        JOIN service_providers sp ON fr.provider_id = sp.provider_id
        WHERE fr.id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();

    if (!$request || $request['provider_user_id'] != $user_id) {
        $_SESSION['errors'] = ['You do not have permission for this request.'];
        redirect('dashboard.php');
    }

    if ($request['payment_status'] === 'completed') {
        $_SESSION['success'] = 'Already paid. Awaiting admin approval.';
        redirect('dashboard.php');
    }

    // Validate payment details
    if ($payment_method === 'mobile_money') {
        $network = $_POST['momo_network'] ?? '';
        $phone   = trim($_POST['momo_phone'] ?? '');
        if (empty($network) || empty($phone)) {
            $_SESSION['errors'] = ['Please enter your mobile money details.'];
            redirect("pay_featured.php?request_id=$request_id");
        }
    }

    if ($payment_method === 'card') {
        $card_number = trim($_POST['card_number'] ?? '');
        $card_expiry = trim($_POST['card_expiry'] ?? '');
        $card_cvv    = trim($_POST['card_cvv'] ?? '');
        if (empty($card_number) || empty($card_expiry) || empty($card_cvv)) {
            $_SESSION['errors'] = ['Please enter your card details.'];
            redirect("pay_featured.php?request_id=$request_id");
        }
    }

    // Mark payment as completed (in production, this would go through a payment gateway)
    $stmt = $pdo->prepare("UPDATE featured_requests SET payment_status = 'completed', payment_method = ? WHERE id = ?");
    $stmt->execute([$payment_method, $request_id]);

    // Notify all admins
    require_once 'includes/notifications.php';
    $admins = $pdo->query("SELECT user_id FROM users WHERE user_type = 'admin'")->fetchAll();
    $providerName = getUserName();
    foreach ($admins as $admin) {
        createNotification($pdo, $admin['user_id'], 'featured', 'New Featured Request', $providerName . ' has paid for a featured listing (' . $request['duration_days'] . ' days). Review and approve it.', 'admin.php');
    }

    $_SESSION['success'] = "Payment of GH₵ " . number_format($request['fee'], 2) . " completed! Your featured listing request is now under review. You'll be featured within 24 hours.";
    redirect('dashboard.php');
}

redirect('dashboard.php');
