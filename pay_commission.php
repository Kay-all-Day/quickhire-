<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();
if (!isProvider()) { redirect('dashboard.php'); }

$mode = $_GET['mode'] ?? 'single';
$commission_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
$stmt->execute([getUserId()]);
$provRow = $stmt->fetch();
if (!$provRow) { redirect('dashboard.php'); }

// Fetch commissions
if ($mode === 'all') {
    $stmt = $pdo->prepare("
        SELECT pc.*, s.service_name, s.price AS service_price, u.full_name AS customer_name, b.booking_date, b.address
        FROM provider_commissions pc
        JOIN bookings b ON pc.booking_id = b.booking_id
        JOIN users u ON b.user_id = u.user_id
        LEFT JOIN services s ON b.service_id = s.service_id
        WHERE pc.provider_id = ? AND pc.status = 'owed'
        ORDER BY pc.created_at ASC
    ");
    $stmt->execute([$provRow['provider_id']]);
    $allOwed = $stmt->fetchAll();
    if (empty($allOwed)) {
        $_SESSION['success'] = 'No outstanding commissions!';
        redirect('dashboard.php');
    }
    $totalAmount = array_sum(array_map(fn($c) => $c['amount'], $allOwed));
    $commission = $allOwed[0]; // use first for display
    $commissionIds = array_map(fn($c) => $c['id'], $allOwed);
} else {
    if ($commission_id <= 0) { redirect('dashboard.php'); }
    $stmt = $pdo->prepare("
        SELECT pc.*, s.service_name, s.price AS service_price, u.full_name AS customer_name, b.booking_date, b.address
        FROM provider_commissions pc
        JOIN bookings b ON pc.booking_id = b.booking_id
        JOIN users u ON b.user_id = u.user_id
        LEFT JOIN services s ON b.service_id = s.service_id
        WHERE pc.id = ? AND pc.provider_id = ? AND pc.status = 'owed'
    ");
    $stmt->execute([$commission_id, $provRow['provider_id']]);
    $commission = $stmt->fetch();
    if (!$commission) {
        $_SESSION['errors'] = ['Commission not found or already paid.'];
        redirect('dashboard.php');
    }
    $totalAmount = $commission['amount'];
    $allOwed = [$commission];
    $commissionIds = [$commission['id']];
}

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pay Commission — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .comm-layout { display: grid; grid-template-columns: 1fr 340px; gap: 36px; max-width: 1000px; margin: 0 auto; padding: 48px 36px; align-items: start; }
    .comm-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 14px; padding: 36px; }
    .comm-card h2 { font-family: 'Sora', sans-serif; font-size: 1.5rem; font-weight: 800; letter-spacing: -0.04em; margin-bottom: 6px; }
    .comm-card .subtitle { font-size: 0.88rem; color: var(--sand); margin-bottom: 32px; }
    .method-options { display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px; }
    .method-option { display: flex; align-items: center; gap: 14px; padding: 16px 18px; background: var(--cream); border: 2px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.2s; }
    .method-option:hover { border-color: var(--sand); }
    .method-option.selected { border-color: var(--ember); background: rgba(13,148,136,0.04); }
    .method-option input[type="radio"] { display: none; }
    .method-icon { font-size: 1.4rem; flex-shrink: 0; }
    .method-info h4 { font-family: 'Sora', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 2px; }
    .method-info p { font-size: 0.78rem; color: var(--sand); }
    .method-details { display: none; margin-top: 16px; }
    .comm-sidebar { background: var(--bark); border-radius: 14px; padding: 28px 24px; color: var(--cream); position: sticky; top: calc(var(--header-h) + 24px); }
    .comm-sidebar h3 { font-family: 'Sora', sans-serif; font-size: 1.1rem; font-weight: 800; margin-bottom: 20px; }
    .cs-row { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,0.06); font-size: 0.84rem; color: rgba(255,255,255,0.5); }
    .cs-row:last-of-type { border-bottom: none; }
    .cs-row span:last-child { color: var(--cream); font-weight: 600; }
    .cs-total { margin-top: 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.12); display: flex; justify-content: space-between; align-items: baseline; }
    .cs-total .lbl { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(255,255,255,0.4); }
    .cs-total .amt { font-family: 'Sora', sans-serif; font-size: 1.6rem; font-weight: 800; color: var(--ember); }
    @media (max-width: 900px) { .comm-layout { grid-template-columns: 1fr; padding: 28px 20px; } }
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
    <p class="page-banner-eyebrow">Platform commission</p>
    <h1>Pay Commission</h1>
    <p><?= count($allOwed) > 1 ? 'Combined 10% platform fee for ' . count($allOwed) . ' cash bookings' : '10% platform fee for cash booking #QH-' . str_pad($commission['booking_id'], 4, '0', STR_PAD_LEFT) ?></p>
  </div>

  <div class="comm-layout">
    <div class="comm-card">
      <h2>Payment Method</h2>
      <p class="subtitle">Select how you'd like to pay your commission</p>

      <?php if (!empty($errors)): ?>
        <div style="background:rgba(220,38,38,0.08);color:#991b1b;border:1px solid rgba(220,38,38,0.2);padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="process_commission.php" method="POST">
        <input type="hidden" name="commission_ids" value="<?= htmlspecialchars(implode(',', $commissionIds)) ?>">

        <div class="method-options">
          <label class="method-option" onclick="selectMethod(this, 'mobile_money')">
            <input type="radio" name="payment_method" value="mobile_money" required>
            <span class="method-icon">📱</span>
            <div class="method-info"><h4>Mobile Money</h4><p>MTN MoMo, Vodafone Cash, AirtelTigo Money</p></div>
          </label>
          <label class="method-option" onclick="selectMethod(this, 'card')">
            <input type="radio" name="payment_method" value="card" required>
            <span class="method-icon">💳</span>
            <div class="method-info"><h4>Bank / Debit Card</h4><p>Visa, Mastercard</p></div>
          </label>
        </div>

        <div id="details-mobile_money" class="method-details">
          <div class="form-field">
            <label>Mobile Money Network</label>
            <select name="momo_network"><option value="" disabled selected>Select network…</option><option value="mtn">MTN Mobile Money</option><option value="vodafone">Vodafone Cash</option><option value="airteltigo">AirtelTigo Money</option></select>
          </div>
          <div class="form-field">
            <label>Phone Number</label>
            <input type="tel" name="momo_phone" placeholder="024 XXX XXXX">
          </div>
        </div>

        <div id="details-card" class="method-details">
          <div class="form-field"><label>Card Number</label><input type="text" name="card_number" placeholder="XXXX XXXX XXXX XXXX" maxlength="19"></div>
          <div class="form-row">
            <div class="form-field"><label>Expiry Date</label><input type="text" name="card_expiry" placeholder="MM/YY" maxlength="5"></div>
            <div class="form-field"><label>CVV</label><input type="text" name="card_cvv" placeholder="123" maxlength="3"></div>
          </div>
          <div class="form-field"><label>Cardholder Name</label><input type="text" name="card_name" placeholder="Name on card"></div>
        </div>

        <button type="submit" class="form-submit" style="margin-top:20px;" id="payBtn" disabled>Confirm Payment — GH₵ <?= number_format($totalAmount, 2) ?> →</button>
      </form>
    </div>

    <div class="comm-sidebar">
      <h3>Commission Summary</h3>
      <?php foreach ($allOwed as $i => $item): ?>
      <div class="cs-row"><span>#QH-<?= str_pad($item['booking_id'], 4, '0', STR_PAD_LEFT) ?></span><span>GH₵ <?= number_format($item['amount'], 2) ?></span></div>
      <?php endforeach; ?>
      <?php if (count($allOwed) > 1): ?>
      <div style="font-size:0.72rem;color:rgba(255,255,255,0.3);padding:6px 0;text-align:right;"><?= count($allOwed) ?> commission<?= count($allOwed) !== 1 ? 's' : '' ?></div>
      <?php endif; ?>
      <div class="cs-total">
        <span class="lbl">Total Due</span>
        <span class="amt">GH₵ <?= number_format($totalAmount, 2) ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:0.72rem;color:rgba(255,255,255,0.3);"><span>🔒</span><span>Your payment is secure.</span></div>
    </div>
  </div>

  <footer><p><strong>QuickHire</strong> &mdash; Connecting Ghana, one job at a time. &copy; 2026</p></footer>

  <script>
    function selectMethod(label, method) {
      document.querySelectorAll('.method-option').forEach(o => o.classList.remove('selected'));
      label.classList.add('selected');
      label.querySelector('input[type="radio"]').checked = true;
      document.querySelectorAll('.method-details').forEach(d => d.style.display = 'none');
      var d = document.getElementById('details-' + method);
      if (d) d.style.display = 'block';
      document.getElementById('payBtn').disabled = false;
    }
  </script>
</body>
</html>
