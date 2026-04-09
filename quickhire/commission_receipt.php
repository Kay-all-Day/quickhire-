<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

$commission_id = intval($_GET['id'] ?? 0);
if ($commission_id <= 0) { redirect('dashboard.php'); }

$stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
$stmt->execute([getUserId()]);
$provRow = $stmt->fetch();
if (!$provRow) { redirect('dashboard.php'); }

$stmt = $pdo->prepare("
    SELECT pc.*, s.service_name, s.price AS service_price, u.full_name AS customer_name, b.booking_date, b.address
    FROM provider_commissions pc
    JOIN bookings b ON pc.booking_id = b.booking_id
    JOIN users u ON b.user_id = u.user_id
    LEFT JOIN services s ON b.service_id = s.service_id
    WHERE pc.id = ? AND pc.provider_id = ? AND pc.status = 'paid'
");
$stmt->execute([$commission_id, $provRow['provider_id']]);
$commission = $stmt->fetch();

if (!$commission) { redirect('dashboard.php'); }

$payMethodLabels = [
    'mobile_money' => '📱 Mobile Money',
    'card'         => '💳 Bank / Debit Card',
];
$payLabel = $payMethodLabels[$commission['payment_method']] ?? $commission['payment_method'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Commission Receipt — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .receipt-wrap { max-width: 620px; margin: 0 auto; padding: 48px 24px; }
    .receipt-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 36px; position: relative; overflow: hidden;
    }
    .receipt-card::before {
      content: 'PAID'; position: absolute; top: 28px; right: -28px;
      background: #059669; color: #fff; font-size: 0.65rem; font-weight: 800;
      letter-spacing: 0.15em; padding: 5px 38px; transform: rotate(45deg);
    }
    .receipt-header { text-align: center; margin-bottom: 28px; }
    .receipt-header h1 { font-family: 'Sora', sans-serif; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.04em; margin-bottom: 4px; }
    .receipt-header p { font-size: 0.82rem; color: var(--sand); }
    .receipt-row { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: 0.86rem; }
    .receipt-row:last-of-type { border-bottom: none; }
    .receipt-row span:first-child { color: var(--sand); }
    .receipt-row span:last-child { font-weight: 600; }
    .receipt-divider { border: none; border-top: 1.5px dashed var(--border); margin: 18px 0; }
    .receipt-total { display: flex; justify-content: space-between; align-items: baseline; padding-top: 14px; border-top: 2px solid var(--bark); }
    .receipt-total .lbl { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--sand); }
    .receipt-total .amt { font-family: 'Sora', sans-serif; font-size: 1.6rem; font-weight: 800; color: #059669; letter-spacing: -0.04em; }
    .receipt-footer { text-align: center; margin-top: 20px; font-size: 0.75rem; color: var(--sand); }
    @media print {
      header, footer, .print-btn, .back-link { display: none !important; }
      .receipt-wrap { padding: 0; }
    }
  </style>
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <a href="dashboard.php">Dashboard<?= getNavNotifBadge($pdo) ?></a>
        <a href="logout.php">Logout</a>
      </div>
    </nav>
  </header>

  <div class="receipt-wrap">

    <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:14px 20px;border-radius:10px;margin-bottom:20px;text-align:center;">
      <p style="font-size:1.05rem;font-weight:700;">✓ Commission Paid!</p>
      <p style="font-size:0.84rem;margin-top:4px;">Your account is in good standing. Receipt below.</p>
    </div>

    <div class="receipt-card">
      <div class="receipt-header">
        <h1>Commission Receipt</h1>
        <p>Booking #QH-<?= str_pad($commission['booking_id'], 4, '0', STR_PAD_LEFT) ?></p>
      </div>

      <div class="receipt-row"><span>Service</span><span><?= htmlspecialchars($commission['service_name'] ?? 'Service') ?></span></div>
      <div class="receipt-row"><span>Customer</span><span><?= htmlspecialchars($commission['customer_name']) ?></span></div>
      <div class="receipt-row"><span>Booking Date</span><span><?= date('j M Y', strtotime($commission['booking_date'])) ?></span></div>
      <div class="receipt-row"><span>Payment Method</span><span><?= $payLabel ?></span></div>
      <div class="receipt-row"><span>Paid On</span><span><?= date('j M Y · g:i A', strtotime($commission['paid_at'])) ?></span></div>

      <hr class="receipt-divider">

      <div class="receipt-row"><span>Service Price</span><span>GH₵ <?= number_format($commission['service_price'] ?? 0, 2) ?></span></div>
      <div class="receipt-row"><span>Commission Rate</span><span>10%</span></div>

      <div class="receipt-total">
        <span class="lbl">Commission Paid</span>
        <span class="amt">GH₵ <?= number_format($commission['amount'], 2) ?></span>
      </div>

      <div class="receipt-footer">
        <p>QuickHire — Connecting Ghana, one job at a time.</p>
        <p style="margin-top:4px;">Receipt generated <?= date('j M Y · g:i A') ?></p>
      </div>
    </div>

    <div style="display:flex;gap:12px;margin-top:16px;">
      <button onclick="window.print()" class="print-btn" style="background:var(--bark);color:#fff;border:none;padding:10px 22px;border-radius:8px;font-size:0.82rem;font-weight:700;cursor:pointer;">🖨 Print Receipt</button>
      <a href="dashboard.php" class="back-link" style="padding:10px 22px;border:1.5px solid var(--border);border-radius:8px;font-size:0.82rem;font-weight:600;color:var(--bark);text-decoration:none;">← Back to Dashboard</a>
    </div>

  </div>

  <footer><p><strong>QuickHire</strong> &mdash; Connecting Ghana, one job at a time. &copy; 2026</p></footer>

</body>
</html>
