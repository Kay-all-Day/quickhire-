<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commissionIdsRaw = $_POST['commission_ids'] ?? '';
    $payment_method   = $_POST['payment_method'] ?? '';

    $commissionIds = array_filter(array_map('intval', explode(',', $commissionIdsRaw)));

    if (empty($commissionIds) || !in_array($payment_method, ['mobile_money', 'card'])) {
        $_SESSION['errors'] = ['Invalid payment request.'];
        redirect('dashboard.php');
    }

    // Validate payment details
    if ($payment_method === 'mobile_money') {
        $network = $_POST['momo_network'] ?? '';
        $phone   = trim($_POST['momo_phone'] ?? '');
        if (empty($network) || empty($phone)) {
            $_SESSION['errors'] = ['Please enter your mobile money details.'];
            redirect("pay_commission.php?mode=all");
        }
    }
    if ($payment_method === 'card') {
        $card_number = trim($_POST['card_number'] ?? '');
        $card_expiry = trim($_POST['card_expiry'] ?? '');
        $card_cvv    = trim($_POST['card_cvv'] ?? '');
        if (empty($card_number) || empty($card_expiry) || empty($card_cvv)) {
            $_SESSION['errors'] = ['Please enter your card details.'];
            redirect("pay_commission.php?mode=all");
        }
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
    $stmt->execute([getUserId()]);
    $provRow = $stmt->fetch();

    $totalPaid = 0;
    $paidIds = [];
    foreach ($commissionIds as $cid) {
        $stmt = $pdo->prepare("SELECT * FROM provider_commissions WHERE id = ? AND provider_id = ? AND status = 'owed'");
        $stmt->execute([$cid, $provRow['provider_id']]);
        $commission = $stmt->fetch();

        if ($commission) {
            $stmt = $pdo->prepare("UPDATE provider_commissions SET status = 'paid', payment_method = ?, paid_at = NOW() WHERE id = ?");
            $stmt->execute([$payment_method, $cid]);
            $totalPaid += $commission['amount'];
            $paidIds[] = $cid;
        }
    }

    if ($totalPaid > 0) {
        // Notify admins
        $admins = $pdo->query("SELECT user_id FROM users WHERE user_type = 'admin'")->fetchAll();
        $providerName = getUserName();
        foreach ($admins as $admin) {
            createNotification($pdo, $admin['user_id'], 'commission', 'Commission Paid', $providerName . ' paid GH₵ ' . number_format($totalPaid, 2) . ' in platform commissions (' . count($paidIds) . ' booking' . (count($paidIds) !== 1 ? 's' : '') . ').', 'admin.php');
        }

        $_SESSION['success'] = 'Commission of GH₵ ' . number_format($totalPaid, 2) . ' paid successfully!';
        // Redirect to receipt of last paid
        redirect("commission_receipt.php?id=" . end($paidIds));
    } else {
        $_SESSION['errors'] = ['No commissions found to pay.'];
        redirect('dashboard.php');
    }
}

redirect('dashboard.php');
