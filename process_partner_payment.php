<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/smileid.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin_partners.php');
}

$partner_id     = intval($_POST['partner_id'] ?? 0);
$amount         = (float)($_POST['amount'] ?? 0);
$payment_method = $_POST['payment_method'] ?? '';

if ($partner_id <= 0 || $amount <= 0 || !in_array($payment_method, ['mobile_money', 'card'])) {
    $_SESSION['errors'] = ['Invalid top-up request.'];
    redirect('admin_partners.php');
}

// Validate method-specific fields
if ($payment_method === 'mobile_money') {
    $network = $_POST['momo_network'] ?? '';
    $phone   = trim($_POST['momo_phone'] ?? '');
    if (empty($network) || empty($phone)) {
        $_SESSION['errors'] = ['Please enter your mobile money details.'];
        redirect("pay_partner.php?partner_id=$partner_id");
    }
    $methodLabel  = strtoupper($network) . ' MoMo';
    $methodStored = $network;
} else {
    $card_number = trim($_POST['card_number'] ?? '');
    $card_expiry = trim($_POST['card_expiry'] ?? '');
    $card_cvv    = trim($_POST['card_cvv'] ?? '');
    if (empty($card_number) || empty($card_expiry) || empty($card_cvv)) {
        $_SESSION['errors'] = ['Please enter your card details.'];
        redirect("pay_partner.php?partner_id=$partner_id");
    }
    $methodLabel  = 'Card';
    $methodStored = 'card';
}

// Verify the partner and wallet exist
$stmt = $pdo->prepare("SELECT p.name FROM partners p JOIN partner_wallet w ON w.partner_id = p.id WHERE p.id = ?");
$stmt->execute([$partner_id]);
$partnerRow = $stmt->fetch();
if (!$partnerRow) {
    $_SESSION['errors'] = ['Partner or wallet not found.'];
    redirect('admin_partners.php');
}

// Simulate payment — generate a unique transaction reference
$reference = 'TOPUP-' . strtoupper(bin2hex(random_bytes(6)));

// Credit the wallet
$newBalance = smileid_wallet_topup(
    $amount,
    $methodStored,
    $reference,
    'Admin top-up via ' . $methodLabel
);

if ($newBalance === false) {
    $_SESSION['errors'] = ['Top-up failed — could not update wallet. Please try again.'];
    redirect("pay_partner.php?partner_id=$partner_id");
}

// Log to partner activity
smileid_log(
    'wallet_topup',
    'success',
    'Wallet topped up GHS ' . number_format($amount, 2, '.', ',') . ' via ' . $methodLabel,
    $reference,
    ['method' => $payment_method, 'new_balance' => $newBalance, 'admin' => getUserName()]
);

$_SESSION['success'] = 'Wallet topped up by GHS ' . number_format($amount, 2, '.', ',') . '. New balance: GHS ' . number_format($newBalance, 2, '.', ',') . '.';
redirect('admin_partners.php');
