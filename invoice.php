<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

$booking_id = intval($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) { redirect('dashboard.php'); }

$stmt = $pdo->prepare("
    SELECT b.*, s.service_name, s.price AS service_price,
           p.payment_id, p.amount, p.payment_status, p.payment_method, p.created_at AS paid_at,
           u_prov.full_name AS provider_name, sp.service_category,
           u_cust.full_name AS customer_name, u_cust.email AS customer_email, u_cust.phone AS customer_phone
    FROM bookings b
    JOIN service_providers sp ON b.provider_id = sp.provider_id
    JOIN users u_prov ON sp.user_id = u_prov.user_id
    JOIN users u_cust ON b.user_id = u_cust.user_id
    LEFT JOIN services s ON b.service_id = s.service_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->execute([$booking_id, getUserId()]);
$booking = $stmt->fetch();

if (!$booking || $booking['payment_status'] !== 'completed') {
    redirect('dashboard.php');
}

$basePrice = $booking['service_price'] ?? ($booking['amount'] / 1.20);
$vat       = round($basePrice * 0.15, 2);
$nhil      = round($basePrice * 0.025, 2);
$getfund   = round($basePrice * 0.025, 2);
$totalAmount = round($basePrice + $vat + $nhil + $getfund, 2);

$invoiceNo = 'QH-INV-' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
$invoiceDate = date('j F Y', strtotime($booking['paid_at'] ?? $booking['booking_date']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice <?= $invoiceNo ?> — QuickHire</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Fraunces:wght@700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --bark: #1a2b3c; --teal: #0d9488; --sand: #7a8a9a;
      --border: #e2e8f0; --page-bg: #f8fafb;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Outfit', sans-serif; color: var(--bark); background: #fff; }

    .invoice-page { max-width: 800px; margin: 0 auto; padding: 48px 40px; }

    /* Header */
    .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 48px; }
    .inv-brand h1 { font-family: 'Fraunces', serif; font-size: 1.6rem; font-weight: 900; letter-spacing: -0.04em; }
    .inv-brand h1 span { color: var(--teal); }
    .inv-brand p { font-size: 0.78rem; color: var(--sand); margin-top: 2px; }
    .inv-meta { text-align: right; }
    .inv-meta h2 { font-family: 'Fraunces', serif; font-size: 1.8rem; font-weight: 900; color: var(--teal); letter-spacing: -0.04em; }
    .inv-meta p { font-size: 0.82rem; color: var(--sand); margin-top: 4px; }

    /* Info blocks */
    .inv-info { display: flex; justify-content: space-between; margin-bottom: 40px; gap: 24px; }
    .inv-info-block h4 { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--sand); margin-bottom: 8px; }
    .inv-info-block p { font-size: 0.88rem; line-height: 1.6; }

    /* Table */
    .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 32px; }
    .inv-table thead th {
      text-align: left; padding: 12px 16px; font-size: 0.72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.08em; color: var(--sand);
      border-bottom: 2px solid var(--bark); background: var(--page-bg);
    }
    .inv-table thead th:last-child { text-align: right; }
    .inv-table tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 0.88rem; }
    .inv-table tbody td:last-child { text-align: right; font-weight: 600; }

    /* Totals */
    .inv-totals { display: flex; justify-content: flex-end; }
    .inv-totals-box { width: 300px; }
    .inv-totals-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.88rem; }
    .inv-totals-row.sep { border-top: 1.5px solid var(--border); padding-top: 12px; margin-top: 4px; }
    .inv-totals-row.total {
      border-top: 2px solid var(--bark); padding-top: 14px; margin-top: 8px;
      font-size: 1.1rem; font-weight: 700;
    }
    .inv-totals-row.total span:last-child { color: var(--teal); font-family: 'Fraunces', serif; font-size: 1.3rem; }

    /* Footer */
    .inv-footer { margin-top: 60px; padding-top: 24px; border-top: 1px solid var(--border); text-align: center; }
    .inv-footer p { font-size: 0.78rem; color: var(--sand); line-height: 1.6; }

    /* Paid stamp */
    .inv-stamp {
      position: fixed; top: 50%; right: 80px; transform: rotate(-25deg) translateY(-50%);
      font-family: 'Fraunces', serif; font-size: 4rem; font-weight: 900;
      color: rgba(5, 150, 105, 0.08); letter-spacing: 0.1em;
      pointer-events: none; z-index: 0;
    }

    /* Print controls */
    .inv-actions { text-align: center; margin-bottom: 32px; }
    .inv-actions button, .inv-actions a {
      display: inline-block; padding: 12px 28px; border-radius: 8px;
      font-size: 0.88rem; font-weight: 600; cursor: pointer; text-decoration: none;
      transition: opacity 0.2s; margin: 0 6px;
    }
    .btn-print { background: var(--bark); color: #fff; border: none; }
    .btn-back { background: transparent; color: var(--sand); border: 1.5px solid var(--border); }
    .btn-print:hover, .btn-back:hover { opacity: 0.8; }

    @media print {
      .inv-actions { display: none !important; }
      .inv-stamp { color: rgba(5, 150, 105, 0.06); }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .invoice-page { padding: 0; }
    }
  </style>
</head>
<body>

<div class="inv-stamp">PAID</div>

<div class="invoice-page">

  <div class="inv-actions">
    <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
    <a class="btn-back" href="receipt.php?booking_id=<?= $booking_id ?>">← Back to Receipt</a>
  </div>

  <div class="inv-header">
    <div class="inv-brand">
      <h1>Quick<span>Hire</span></h1>
      <p>Service Marketplace — Ghana</p>
      <p>quickhire.infinityfreeapp.com</p>
    </div>
    <div class="inv-meta">
      <h2>INVOICE</h2>
      <p><strong><?= $invoiceNo ?></strong></p>
      <p>Date: <?= $invoiceDate ?></p>
    </div>
  </div>

  <div class="inv-info">
    <div class="inv-info-block">
      <h4>Billed To</h4>
      <p><strong><?= htmlspecialchars($booking['customer_name']) ?></strong></p>
      <p><?= htmlspecialchars($booking['customer_email']) ?></p>
      <?php if ($booking['customer_phone']): ?>
        <p><?= htmlspecialchars($booking['customer_phone']) ?></p>
      <?php endif; ?>
      <p><?= htmlspecialchars($booking['address']) ?></p>
    </div>
    <div class="inv-info-block" style="text-align:right;">
      <h4>Service Provider</h4>
      <p><strong><?= htmlspecialchars($booking['provider_name']) ?></strong></p>
      <p><?= htmlspecialchars($booking['service_category']) ?></p>
    </div>
  </div>

  <table class="inv-table">
    <thead>
      <tr>
        <th>Description</th>
        <th>Booking Date</th>
        <th>Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <strong><?= htmlspecialchars($booking['service_name'] ?? $booking['service_category']) ?></strong>
          <br><span style="font-size:0.8rem;color:var(--sand);">Booking #QH-<?= str_pad($booking['booking_id'], 4, '0', STR_PAD_LEFT) ?></span>
        </td>
        <td><?= date('j M Y · g:i A', strtotime($booking['booking_date'])) ?></td>
        <td>GH₵ <?= number_format($basePrice, 2) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="inv-totals">
    <div class="inv-totals-box">
      <div class="inv-totals-row"><span>Subtotal</span><span>GH₵ <?= number_format($basePrice, 2) ?></span></div>
      <div class="inv-totals-row sep"><span>VAT (15%)</span><span>GH₵ <?= number_format($vat, 2) ?></span></div>
      <div class="inv-totals-row"><span>NHIL (2.5%)</span><span>GH₵ <?= number_format($nhil, 2) ?></span></div>
      <div class="inv-totals-row"><span>GETFund (2.5%)</span><span>GH₵ <?= number_format($getfund, 2) ?></span></div>
      <div class="inv-totals-row total"><span>Total Paid</span><span>GH₵ <?= number_format($totalAmount, 2) ?></span></div>
    </div>
  </div>

  <div class="inv-footer">
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time.</p>
    <p>This invoice was generated automatically. Tax computed under Ghana Revenue Authority Act 1151 (15% VAT + 2.5% NHIL + 2.5% GETFund).</p>
    <p>Thank you for your business.</p>
  </div>

</div>

</body>
</html>
