<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/smileid.php';

requireAdmin();

$partner_id = intval($_GET['partner_id'] ?? 0);
if ($partner_id <= 0) { redirect('admin_partners.php'); }

$stmt = $pdo->prepare("SELECT * FROM partners WHERE id = ?");
$stmt->execute([$partner_id]);
$partner = $stmt->fetch();
if (!$partner) {
    $_SESSION['errors'] = ['Partner not found.'];
    redirect('admin_partners.php');
}

$stmt = $pdo->prepare("SELECT * FROM partner_wallet WHERE partner_id = ? LIMIT 1");
$stmt->execute([$partner_id]);
$wallet = $stmt->fetch();
if (!$wallet) {
    $_SESSION['errors'] = ['Wallet not configured for this partner.'];
    redirect('admin_partners.php');
}

$currentBalance = (float)$wallet['balance'];
$costPerCheck   = (float)$wallet['cost_per_check'];
$threshold      = (float)$wallet['low_balance_threshold'];
$isLow          = $currentBalance < $threshold;

$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Top Up Partner Wallet — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .comm-layout { display: grid; grid-template-columns: 1fr 340px; gap: 36px; max-width: 1000px; margin: 0 auto; padding: 48px 36px; align-items: start; }
    .comm-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 14px; padding: 36px; }
    .comm-card h2 { font-family: 'Sora', sans-serif; font-size: 1.5rem; font-weight: 800; letter-spacing: -0.04em; margin-bottom: 6px; }
    .comm-card .subtitle { font-size: 0.88rem; color: var(--sand); margin-bottom: 32px; }
    .quick-amounts { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .quick-amt-btn { padding: 10px 20px; font-size: 0.85rem; font-weight: 700; background: var(--cream); border: 1.5px solid var(--border); border-radius: 8px; cursor: pointer; color: var(--bark); transition: all 0.18s; font-family: inherit; }
    .quick-amt-btn:hover { border-color: var(--ember); color: var(--ember); }
    .quick-amt-btn.active { background: rgba(13,148,136,0.08); border-color: var(--ember); color: var(--ember); }
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
    .alert-warn { background: #fef3c7; color: #92400e; border: 1.5px solid #fde68a; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.88rem; font-weight: 600; }
    @media (max-width: 900px) { .comm-layout { grid-template-columns: 1fr; padding: 28px 20px; } }
  </style>
</head>
<body>
  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <a href="admin.php" style="color:var(--ember);">Admin</a>
        <a href="logout.php">Logout</a>
      </div>
    </nav>
  </header>

  <div class="page-banner">
    <p class="page-banner-eyebrow">Partner Wallet</p>
    <h1>Top Up <?= htmlspecialchars($partner['name']) ?> Wallet</h1>
    <p>Add prepaid credit so verification calls can continue uninterrupted</p>
  </div>

  <div class="comm-layout">
    <div class="comm-card">
      <h2>Top-Up Amount &amp; Payment</h2>
      <p class="subtitle">Select an amount and payment method to credit the wallet</p>

      <?php if ($isLow): ?>
        <div class="alert-warn">Wallet balance is below GHS <?= number_format($threshold, 2) ?>. Top up to avoid verification failures.</div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div style="background:rgba(220,38,38,0.08);color:#991b1b;border:1px solid rgba(220,38,38,0.2);padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="process_partner_payment.php" method="POST" id="topupForm">
        <input type="hidden" name="partner_id" value="<?= (int)$partner_id ?>">

        <div class="form-field" style="margin-bottom:16px;">
          <label style="font-size:0.82rem;font-weight:700;color:var(--warm-mid);display:block;margin-bottom:8px;">Quick Select</label>
          <div class="quick-amounts">
            <button type="button" class="quick-amt-btn" onclick="setAmount(100, this)">GHS 100</button>
            <button type="button" class="quick-amt-btn" onclick="setAmount(500, this)">GHS 500</button>
            <button type="button" class="quick-amt-btn" onclick="setAmount(1000, this)">GHS 1,000</button>
            <button type="button" class="quick-amt-btn" onclick="setAmount(2000, this)">GHS 2,000</button>
          </div>
        </div>

        <div class="form-field" style="margin-bottom:28px;">
          <label>Amount (GHS)</label>
          <input type="number" name="amount" id="amountInput" placeholder="Enter amount" min="1" step="0.01" required style="width:100%;padding:12px 14px;font-size:1rem;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;" oninput="syncAmount()">
        </div>

        <div class="method-options">
          <label class="method-option" onclick="selectMethod(this, 'mobile_money')">
            <input type="radio" name="payment_method" value="mobile_money" required>
            <span class="method-icon">&#128241;</span>
            <div class="method-info"><h4>Mobile Money</h4><p>MTN MoMo, Vodafone Cash, AirtelTigo Money</p></div>
          </label>
          <label class="method-option" onclick="selectMethod(this, 'card')">
            <input type="radio" name="payment_method" value="card" required>
            <span class="method-icon">&#128179;</span>
            <div class="method-info"><h4>Bank / Debit Card</h4><p>Visa, Mastercard</p></div>
          </label>
        </div>

        <div id="details-mobile_money" class="method-details">
          <div class="form-field">
            <label>Mobile Money Network</label>
            <select name="momo_network"><option value="" disabled selected>Select network&hellip;</option><option value="mtn">MTN Mobile Money</option><option value="vodafone">Vodafone Cash</option><option value="airteltigo">AirtelTigo Money</option></select>
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

        <button type="submit" class="form-submit" style="margin-top:20px;" id="payBtn" disabled>
          Confirm Top-Up <span id="btnAmt"></span> &rarr;
        </button>
      </form>
    </div>

    <div class="comm-sidebar">
      <h3>Wallet Summary</h3>
      <div class="cs-row"><span>Partner</span><span><?= htmlspecialchars($partner['name']) ?></span></div>
      <div class="cs-row"><span>Current Balance</span><span>GHS <?= number_format($currentBalance, 2) ?></span></div>
      <div class="cs-row"><span>Per Verification</span><span>GHS <?= number_format($costPerCheck, 2) ?></span></div>
      <div class="cs-row"><span>Checks Remaining</span><span><?= $costPerCheck > 0 ? number_format(floor($currentBalance / $costPerCheck)) : '&mdash;' ?></span></div>
      <div class="cs-total">
        <span class="lbl">Top-Up Amount</span>
        <span class="amt" id="sidebarAmt">GHS &mdash;</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:16px;font-size:0.72rem;color:rgba(255,255,255,0.3);">
        <span>&#128274;</span><span>Payment is simulated in sandbox mode.</span>
      </div>
    </div>
  </div>

  <footer><p><strong>QuickHire</strong> &mdash; Connecting Ghana, one job at a time. &copy; 2026</p></footer>

  <script>
    var chosenAmount = 0;

    function setAmount(val, btn) {
      chosenAmount = val;
      document.getElementById('amountInput').value = val;
      document.querySelectorAll('.quick-amt-btn').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      updateDisplay();
    }

    function syncAmount() {
      chosenAmount = parseFloat(document.getElementById('amountInput').value) || 0;
      document.querySelectorAll('.quick-amt-btn').forEach(function(b) { b.classList.remove('active'); });
      updateDisplay();
    }

    function updateDisplay() {
      var methodSelected = document.querySelector('input[name="payment_method"]:checked');
      document.getElementById('payBtn').disabled = !(chosenAmount > 0 && methodSelected);
      document.getElementById('btnAmt').textContent = chosenAmount > 0 ? '— GHS ' + chosenAmount.toLocaleString('en-GH', {minimumFractionDigits:2,maximumFractionDigits:2}) : '';
      document.getElementById('sidebarAmt').textContent = chosenAmount > 0 ? 'GHS ' + chosenAmount.toLocaleString('en-GH', {minimumFractionDigits:2,maximumFractionDigits:2}) : 'GHS —';
    }

    function selectMethod(label, method) {
      document.querySelectorAll('.method-option').forEach(function(o) { o.classList.remove('selected'); });
      label.classList.add('selected');
      label.querySelector('input[type="radio"]').checked = true;
      document.querySelectorAll('.method-details').forEach(function(d) { d.style.display = 'none'; });
      var d = document.getElementById('details-' + method);
      if (d) d.style.display = 'block';
      updateDisplay();
    }
  </script>
</body>
</html>
