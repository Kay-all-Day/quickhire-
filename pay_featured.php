<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

$request_id = intval($_GET['request_id'] ?? 0);
if ($request_id <= 0) { redirect('dashboard.php'); }

$user_id = getUserId();

// Get provider_id
$stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
$stmt->execute([$user_id]);
$providerRow = $stmt->fetch();
if (!$providerRow) { redirect('dashboard.php'); }

// Get request
$stmt = $pdo->prepare("SELECT * FROM featured_requests WHERE id = ? AND provider_id = ?");
$stmt->execute([$request_id, $providerRow['provider_id']]);
$request = $stmt->fetch();

if (!$request) {
    $_SESSION['errors'] = ['Featured request not found.'];
    redirect('dashboard.php');
}

if ($request['payment_status'] === 'completed') {
    $_SESSION['success'] = 'This request has already been paid. Awaiting admin approval.';
    redirect('dashboard.php');
}

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pay for Featured Listing — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .payment-layout {
      display: grid; grid-template-columns: 1fr 380px; gap: 36px;
      max-width: 1100px; margin: 0 auto; padding: 60px 48px; align-items: start;
    }
    .payment-card {
      background: var(--card-bg); border: 1px solid var(--border);
      border-radius: 12px; padding: 40px; box-shadow: 0 1px 3px rgba(15,23,42,0.06);
    }
    .payment-card h2 {
      font-family: 'Sora', sans-serif; font-size: 1.7rem; font-weight: 800;
      letter-spacing: -0.04em; margin-bottom: 6px;
    }
    .payment-card .subtitle { font-size: 0.88rem; color: var(--sand); margin-bottom: 32px; }
    .method-options { display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px; }
    .method-option {
      display: flex; align-items: center; gap: 14px; padding: 18px 20px;
      background: var(--cream); border: 2px solid var(--border); border-radius: 10px;
      cursor: pointer; transition: all 0.2s;
    }
    .method-option:hover { border-color: var(--sand); }
    .method-option.selected { border-color: var(--ember); background: rgba(13,148,136,0.04); }
    .method-option input[type="radio"] { display: none; }
    .method-icon { font-size: 1.6rem; flex-shrink: 0; }
    .method-info h4 { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 2px; }
    .method-info p { font-size: 0.8rem; color: var(--sand); }

    .summary-sidebar {
      background: linear-gradient(135deg, #0f172a, #1e293b);
      border-radius: 12px; padding: 32px 28px; color: #fff;
      position: sticky; top: calc(var(--header-h) + 24px);
    }
    .summary-sidebar h3 {
      font-family: 'Sora', sans-serif; font-size: 1.15rem; font-weight: 800;
      margin-bottom: 24px;
    }
    .sum-row {
      display: flex; justify-content: space-between; padding: 10px 0;
      border-bottom: 1px solid rgba(255,255,255,0.06); font-size: 0.85rem;
      color: rgba(255,255,255,0.5);
    }
    .sum-row span:last-child { color: #fff; font-weight: 600; }
    .sum-total {
      margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.1);
      display: flex; justify-content: space-between; align-items: baseline;
    }
    .sum-total .lbl { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255,255,255,0.4); }
    .sum-total .amt { font-family: 'Sora', sans-serif; font-size: 1.7rem; font-weight: 800; color: var(--ember); }

    @media (max-width: 900px) { .payment-layout { grid-template-columns: 1fr; padding: 36px 24px; } }
  </style>
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="dashboard.php">Dashboard<?= getNavNotifBadge($pdo) ?></a>
        <a href="logout.php">Logout</a>
      </div>
    </nav>
  </header>

  <div class="page-banner">
    <p class="page-banner-eyebrow">Featured listing</p>
    <h1>Complete Your Payment</h1>
    <p>Pay to get your profile featured on the QuickHire homepage.</p>
  </div>

  <div class="payment-layout">
    <div class="payment-card">
      <h2>Payment Method</h2>
      <p class="subtitle">Select how you'd like to pay for your featured listing</p>

      <?php if (!empty($errors)): ?>
        <div style="background:rgba(244,63,94,0.06);color:#be123c;border:1px solid rgba(244,63,94,0.15);padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="process_featured_payment.php" method="POST" id="payForm">
        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">

        <div class="method-options">
          <label class="method-option" onclick="selectMethod(this, 'mobile_money')">
            <input type="radio" name="payment_method" value="mobile_money" required>
            <span class="method-icon">📱</span>
            <div class="method-info"><h4>Mobile Money</h4><p>MTN MoMo, Vodafone Cash, AirtelTigo</p></div>
          </label>
          <label class="method-option" onclick="selectMethod(this, 'card')">
            <input type="radio" name="payment_method" value="card" required>
            <span class="method-icon">💳</span>
            <div class="method-info"><h4>Bank / Debit Card</h4><p>Visa, Mastercard</p></div>
          </label>
        </div>

        <div id="details-mobile_money" style="display:none;margin-bottom:20px;">
          <div class="form-field">
            <label>Network</label>
            <select name="momo_network"><option value="" disabled selected>Select…</option><option value="mtn">MTN MoMo</option><option value="vodafone">Vodafone Cash</option><option value="airteltigo">AirtelTigo</option></select>
          </div>
          <div class="form-field">
            <label>Phone Number</label>
            <input type="tel" name="momo_phone" placeholder="024 XXX XXXX">
          </div>
        </div>

        <div id="details-card" style="display:none;margin-bottom:20px;">
          <div class="form-field"><label>Card Number</label><input type="text" name="card_number" placeholder="XXXX XXXX XXXX XXXX" maxlength="19"></div>
          <div class="form-row">
            <div class="form-field"><label>Expiry</label><input type="text" name="card_expiry" placeholder="MM/YY" maxlength="5"></div>
            <div class="form-field"><label>CVV</label><input type="text" name="card_cvv" placeholder="123" maxlength="3"></div>
          </div>
          <div class="form-field"><label>Cardholder Name</label><input type="text" name="card_name" placeholder="Name on card"></div>
        </div>

        <button type="submit" class="form-submit" id="payBtn" disabled>
          Pay GH₵ <?= number_format($request['fee'], 2) ?> →
        </button>
      </form>
    </div>

    <div class="summary-sidebar">
      <h3>Featured Listing</h3>
      <div class="sum-row"><span>Plan</span><span><?= $request['duration_days'] ?> days</span></div>
      <div class="sum-row"><span>Duration</span><span><?= $request['duration_days'] ?> days from approval</span></div>
      <div class="sum-row"><span>Benefit</span><span>Homepage placement</span></div>
      <div class="sum-total"><span class="lbl">Total</span><span class="amt">GH₵ <?= number_format($request['fee'], 2) ?></span></div>
      <p style="font-size:0.72rem;color:rgba(255,255,255,0.3);margin-top:20px;line-height:1.5;">After payment, the QuickHire admin will review and approve your listing within 24 hours.</p>
    </div>
  </div>

  <footer>
    <div class="footer-inner">
      <div class="footer-top">
        <div>
          <div class="footer-brand">Quick<span>Hire</span></div>
          <p class="footer-tagline">Connecting Ghana's best service professionals with customers who need them.</p>
        </div>
        
      </div>
      <div class="footer-bottom">
        <span>&copy; 2026 QuickHire. All rights reserved.</span>
        <span>Built in Ghana 🇬🇭</span>
      </div>

  <script>
    function selectMethod(label, method) {
      document.querySelectorAll('.method-option').forEach(o => o.classList.remove('selected'));
      label.classList.add('selected');
      label.querySelector('input[type="radio"]').checked = true;
      document.querySelectorAll('[id^="details-"]').forEach(d => d.style.display = 'none');
      const d = document.getElementById('details-' + method);
      if (d) d.style.display = 'block';
      document.getElementById('payBtn').disabled = false;
    }
  </script>
</body>
</html>
