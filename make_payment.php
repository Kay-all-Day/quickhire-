<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

$booking_id = intval($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) { redirect('dashboard.php'); }

// Fetch booking + payment details
$stmt = $pdo->prepare("
    SELECT b.*, s.service_name, s.price, p.payment_id, p.amount, p.payment_status, p.payment_method,
           u_prov.full_name AS provider_name, sp.service_category
    FROM bookings b
    JOIN service_providers sp ON b.provider_id = sp.provider_id
    JOIN users u_prov ON sp.user_id = u_prov.user_id
    LEFT JOIN services s ON b.service_id = s.service_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->execute([$booking_id, getUserId()]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['errors'] = ['Booking not found.'];
    redirect('dashboard.php');
}

if ($booking['status'] !== 'completed') {
    $_SESSION['errors'] = ['This booking is not yet completed.'];
    redirect('dashboard.php');
}

if ($booking['payment_status'] === 'completed') {
    $_SESSION['errors'] = ['This booking has already been paid for.'];
    redirect('dashboard.php');
}

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);

// Calculate Ghana tax breakdown from base service price
$basePrice = $booking['price'] ?? $booking['amount']; // service price before tax
$vat       = round($basePrice * 0.15, 2);
$nhil      = round($basePrice * 0.025, 2);
$getfund   = round($basePrice * 0.025, 2);
$totalTax  = $vat + $nhil + $getfund;
$totalAmount = round($basePrice + $totalTax, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Make Payment — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .payment-layout {
      display: grid; grid-template-columns: 1fr 380px; gap: 36px;
      max-width: 1100px; margin: 0 auto; padding: 60px 48px; align-items: start;
    }
    .payment-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 40px;
    }
    .payment-card h2 {
      font-family: 'Fraunces', serif; font-size: 1.8rem; font-weight: 900;
      letter-spacing: -0.05em; margin-bottom: 6px;
    }
    .payment-card .subtitle { font-size: 0.88rem; color: var(--sand); margin-bottom: 36px; }

    .method-options { display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px; }
    .method-option {
      display: flex; align-items: center; gap: 14px; padding: 18px 20px;
      background: var(--cream); border: 2px solid var(--border); border-radius: 10px;
      cursor: pointer; transition: all 0.2s;
    }
    .method-option:hover { border-color: var(--sand); }
    .method-option.selected { border-color: var(--ember); background: rgba(196,92,26,0.06); }
    .method-option input[type="radio"] { display: none; }
    .method-icon { font-size: 1.6rem; flex-shrink: 0; }
    .method-info h4 { font-family: 'Fraunces', serif; font-size: 1rem; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 2px; }
    .method-info p { font-size: 0.8rem; color: var(--sand); }
    .method-details { display: none; margin-top: 16px; }
    .method-option.selected .method-details { display: block; }

    .summary-sidebar {
      background: var(--bark); border-radius: 14px; padding: 32px 28px;
      color: var(--cream); position: sticky; top: calc(var(--header-h) + 24px);
    }
    .summary-sidebar h3 {
      font-family: 'Fraunces', serif; font-size: 1.2rem; font-weight: 900;
      letter-spacing: -0.03em; margin-bottom: 24px;
    }
    .sum-row {
      display: flex; justify-content: space-between; padding: 10px 0;
      border-bottom: 1px solid rgba(245,240,232,0.08); font-size: 0.85rem;
      color: rgba(245,240,232,0.6);
    }
    .sum-row:last-of-type { border-bottom: none; }
    .sum-row span:last-child { color: var(--cream); font-weight: 600; }
    .sum-total {
      margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(245,240,232,0.15);
      display: flex; justify-content: space-between; align-items: baseline;
    }
    .sum-total .lbl { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(245,240,232,0.45); }
    .sum-total .amt { font-family: 'Fraunces', serif; font-size: 1.8rem; font-weight: 900; color: var(--ember); letter-spacing: -0.04em; }

    .secure-note { display: flex; align-items: center; gap: 8px; margin-top: 20px; font-size: 0.75rem; color: rgba(245,240,232,0.35); }

    @media (max-width: 900px) {
      .payment-layout { grid-template-columns: 1fr; padding: 36px 24px; }
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

  <div class="page-banner">
    <p class="page-banner-eyebrow">Complete your payment</p>
    <h1>Pay for Your Service</h1>
    <p>Choose a payment method to complete your booking with <?= htmlspecialchars($booking['provider_name']) ?>.</p>
  </div>

  <div class="payment-layout">

    <div class="payment-card">
      <h2>Payment Method</h2>
      <p class="subtitle">Select how you'd like to pay</p>

      <?php if (!empty($errors)): ?>
        <div style="background:rgba(220,38,38,0.08);color:#991b1b;border:1px solid rgba(220,38,38,0.2);padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="process_payment.php" method="POST" id="paymentForm">
        <input type="hidden" name="payment_id" value="<?= $booking['payment_id'] ?>">
        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

        <div class="method-options">

          <!-- Mobile Money -->
          <label class="method-option" onclick="selectMethod(this, 'mobile_money')">
            <input type="radio" name="payment_method" value="mobile_money" required>
            <span class="method-icon">📱</span>
            <div class="method-info">
              <h4>Mobile Money</h4>
              <p>MTN MoMo, Vodafone Cash, AirtelTigo Money</p>
            </div>
          </label>

          <!-- Bank Card -->
          <label class="method-option" onclick="selectMethod(this, 'card')">
            <input type="radio" name="payment_method" value="card" required>
            <span class="method-icon">💳</span>
            <div class="method-info">
              <h4>Bank / Debit Card</h4>
              <p>Visa, Mastercard</p>
            </div>
          </label>

          <!-- Cash -->
          <label class="method-option" onclick="selectMethod(this, 'cash')">
            <input type="radio" name="payment_method" value="cash" required>
            <span class="method-icon">💵</span>
            <div class="method-info">
              <h4>Cash on Delivery</h4>
              <p>Pay the provider directly after service</p>
            </div>
          </label>

        </div>

        <!-- Mobile Money details -->
        <div id="details-mobile_money" class="method-details" style="display:none;">
          <div class="form-field">
            <label>Mobile Money Network</label>
            <select name="momo_network">
              <option value="" disabled selected>Select network…</option>
              <option value="mtn">MTN Mobile Money</option>
              <option value="vodafone">Vodafone Cash</option>
              <option value="airteltigo">AirtelTigo Money</option>
            </select>
          </div>
          <div class="form-field">
            <label>Phone Number</label>
            <input type="tel" name="momo_phone" placeholder="024 XXX XXXX">
          </div>
        </div>

        <!-- Card details -->
        <div id="details-card" class="method-details" style="display:none;">
          <div class="form-field">
            <label>Card Number</label>
            <input type="text" name="card_number" placeholder="XXXX XXXX XXXX XXXX" maxlength="19">
          </div>
          <div class="form-row">
            <div class="form-field">
              <label>Expiry Date</label>
              <input type="text" name="card_expiry" placeholder="MM/YY" maxlength="5">
            </div>
            <div class="form-field">
              <label>CVV</label>
              <input type="text" name="card_cvv" placeholder="123" maxlength="3">
            </div>
          </div>
          <div class="form-field">
            <label>Cardholder Name</label>
            <input type="text" name="card_name" placeholder="Name on card">
          </div>
        </div>

        <!-- Cash details -->
        <div id="details-cash" class="method-details" style="display:none;">
          <div style="background:var(--parchment);border:1px solid var(--border);border-radius:8px;padding:16px;font-size:0.88rem;color:var(--warm-mid);">
            <p><strong>Cash payment</strong> — You'll pay the provider directly when the service is delivered. Please have the exact amount ready.</p>
            <p style="margin-top:8px;font-weight:600;">Amount due: GH₵ <?= number_format($totalAmount, 2) ?></p>
            <p style="margin-top:10px;font-size:0.82rem;color:var(--sand);padding-top:10px;border-top:1px solid var(--border);">
              💡 <strong>Note:</strong> For cash payments, a 10% platform commission (GH₵ <?= number_format($basePrice * 0.10, 2) ?>) will be invoiced to the service provider by QuickHire.
            </p>
          </div>
        </div>

        <button type="submit" class="form-submit" style="margin-top:24px;" id="payBtn" disabled>
          Confirm Payment — GH₵ <?= number_format($totalAmount, 2) ?> →
        </button>
      </form>
    </div>

    <!-- Sidebar summary -->
    <div class="summary-sidebar">
      <h3>Payment Summary</h3>
      <div class="sum-row"><span>Service</span><span><?= htmlspecialchars($booking['service_name'] ?? $booking['service_category']) ?></span></div>
      <div class="sum-row"><span>Provider</span><span><?= htmlspecialchars($booking['provider_name']) ?></span></div>
      <div class="sum-row"><span>Date</span><span><?= date('j M Y', strtotime($booking['booking_date'])) ?></span></div>
      <div class="sum-row"><span>Location</span><span><?= htmlspecialchars($booking['address']) ?></span></div>
      <div class="sum-row"><span>Booking #</span><span>QH-<?= str_pad($booking['booking_id'], 4, '0', STR_PAD_LEFT) ?></span></div>

      <div style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(245,240,232,0.08);">
        <div class="sum-row"><span>Subtotal</span><span>GH₵ <?= number_format($basePrice, 2) ?></span></div>
        <div class="sum-row"><span>VAT (15%)</span><span>GH₵ <?= number_format($vat, 2) ?></span></div>
        <div class="sum-row"><span>NHIL (2.5%)</span><span>GH₵ <?= number_format($nhil, 2) ?></span></div>
        <div class="sum-row"><span>GETFund (2.5%)</span><span>GH₵ <?= number_format($getfund, 2) ?></span></div>
      </div>

      <div class="sum-total">
        <span class="lbl">Total</span>
        <span class="amt">GH₵ <?= number_format($totalAmount, 2) ?></span>
      </div>

      <div class="secure-note">
        <span>🔒</span>
        <span>Your payment is secure. We do not store card details.</span>
      </div>
    </div>

  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

  <script>
    function selectMethod(label, method) {
      document.querySelectorAll('.method-option').forEach(o => o.classList.remove('selected'));
      label.classList.add('selected');
      label.querySelector('input[type="radio"]').checked = true;

      document.querySelectorAll('.method-details').forEach(d => d.style.display = 'none');
      const details = document.getElementById('details-' + method);
      if (details) details.style.display = 'block';

      document.getElementById('payBtn').disabled = false;
    }
  </script>

</body>
</html>
