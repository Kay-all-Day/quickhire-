<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

$booking_id = intval($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) { redirect('dashboard.php'); }

// Fetch booking + payment + service details
$stmt = $pdo->prepare("
    SELECT b.*, s.service_name, s.price AS service_price, b.provider_id,
           p.payment_id, p.amount, p.payment_status, p.payment_method,
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

if (!$booking || $booking['payment_status'] !== 'completed') {
    redirect('dashboard.php');
}

// Tax breakdown
$basePrice = $booking['service_price'] ?? ($booking['amount'] / 1.20);
$vat       = round($basePrice * 0.15, 2);
$nhil      = round($basePrice * 0.025, 2);
$getfund   = round($basePrice * 0.025, 2);
$totalAmount = round($basePrice + $vat + $nhil + $getfund, 2);

$payMethodLabels = [
    'mobile_money' => '📱 Mobile Money',
    'card'         => '💳 Bank / Debit Card',
    'cash'         => '💵 Cash on Delivery',
];
$payLabel = $payMethodLabels[$booking['payment_method']] ?? $booking['payment_method'];

// Payment breakdown (card / mobile_money only — no row for cash)
$payoutRow = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM provider_payouts WHERE booking_id = ? LIMIT 1");
    $stmt->execute([$booking_id]);
    $payoutRow = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

// Check if user already reviewed this booking's provider
$stmt = $pdo->prepare("SELECT review_id FROM reviews WHERE booking_id = ? AND user_id = ?");
$stmt->execute([$booking_id, getUserId()]);
$alreadyReviewed = $stmt->fetch();

$feedbackSuccess = $_SESSION['success'] ?? '';
$feedbackErrors  = $_SESSION['errors'] ?? [];
unset($_SESSION['success'], $_SESSION['errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Receipt — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .receipt-wrap {
      max-width: 680px; margin: 0 auto; padding: 60px 24px;
    }
    .receipt-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 40px; position: relative; overflow: hidden;
    }
    .receipt-card::before {
      content: 'PAID'; position: absolute; top: 32px; right: -28px;
      background: #059669; color: #fff; font-size: 0.7rem; font-weight: 800;
      letter-spacing: 0.15em; padding: 6px 40px; transform: rotate(45deg);
    }
    .receipt-header { text-align: center; margin-bottom: 32px; }
    .receipt-header h1 {
      font-family: 'Fraunces', serif; font-size: 1.6rem; font-weight: 900;
      letter-spacing: -0.04em; margin-bottom: 4px;
    }
    .receipt-header p { font-size: 0.85rem; color: var(--sand); }
    .receipt-row {
      display: flex; justify-content: space-between; padding: 10px 0;
      border-bottom: 1px solid var(--border); font-size: 0.88rem;
    }
    .receipt-row:last-of-type { border-bottom: none; }
    .receipt-row span:first-child { color: var(--sand); }
    .receipt-row span:last-child { font-weight: 600; }
    .receipt-divider {
      border: none; border-top: 1.5px dashed var(--border); margin: 20px 0;
    }
    .receipt-total {
      display: flex; justify-content: space-between; align-items: baseline;
      padding-top: 16px; border-top: 2px solid var(--bark);
    }
    .receipt-total .lbl {
      font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em;
      text-transform: uppercase; color: var(--sand);
    }
    .receipt-total .amt {
      font-family: 'Fraunces', serif; font-size: 1.8rem; font-weight: 900;
      color: #059669; letter-spacing: -0.04em;
    }
    .receipt-footer {
      text-align: center; margin-top: 24px; font-size: 0.78rem; color: var(--sand);
    }
    .feedback-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 32px; margin-top: 24px;
    }
    .feedback-card h3 {
      font-family: 'Fraunces', serif; font-size: 1.2rem; font-weight: 800;
      margin-bottom: 4px;
    }
    @media print {
      header, footer, .feedback-card, .print-btn, .back-link { display: none !important; }
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

    <!-- Success banner -->
    <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:16px 20px;border-radius:10px;margin-bottom:24px;text-align:center;">
      <p style="font-size:1.1rem;font-weight:700;">✓ Payment Successful!</p>
      <p style="font-size:0.85rem;margin-top:4px;">Your receipt is below. Thank you for using QuickHire.</p>
    </div>

    <!-- Receipt -->
    <div class="receipt-card">
      <div class="receipt-header">
        <h1>QuickHire Receipt</h1>
        <p>Booking #QH-<?= str_pad($booking['booking_id'], 4, '0', STR_PAD_LEFT) ?></p>
      </div>

      <div class="receipt-row"><span>Service</span><span><?= htmlspecialchars($booking['service_name'] ?? $booking['service_category']) ?></span></div>
      <div class="receipt-row"><span>Provider</span><span><?= htmlspecialchars($booking['provider_name']) ?></span></div>
      <div class="receipt-row"><span>Date</span><span><?= date('j M Y · g:i A', strtotime($booking['booking_date'])) ?></span></div>
      <div class="receipt-row"><span>Location</span><span><?= htmlspecialchars($booking['address']) ?></span></div>
      <div class="receipt-row"><span>Payment Method</span><span><?= $payLabel ?></span></div>

      <hr class="receipt-divider">

      <div class="receipt-row"><span>Subtotal</span><span>GH₵ <?= number_format($basePrice, 2) ?></span></div>
      <div class="receipt-row"><span>VAT (15%)</span><span>GH₵ <?= number_format($vat, 2) ?></span></div>
      <div class="receipt-row"><span>NHIL (2.5%)</span><span>GH₵ <?= number_format($nhil, 2) ?></span></div>
      <div class="receipt-row"><span>GETFund (2.5%)</span><span>GH₵ <?= number_format($getfund, 2) ?></span></div>

      <div class="receipt-total">
        <span class="lbl">Total Paid</span>
        <span class="amt">GH₵ <?= number_format($totalAmount, 2) ?></span>
      </div>

      <?php if ($payoutRow): ?>
      <hr class="receipt-divider">
      <div style="margin-bottom:8px;">
        <p style="font-size:0.7rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--sand);margin-bottom:10px;">Payment Breakdown</p>
        <div class="receipt-row"><span>Gross Collected</span><span>GH₵ <?= number_format($payoutRow['gross_amount'], 2) ?></span></div>
        <div class="receipt-row"><span>Platform Commission (10%)</span><span>GH₵ <?= number_format($payoutRow['commission_amount'], 2) ?></span></div>
        <div class="receipt-row"><span>Tax Held for GRA (20%)</span><span>GH₵ <?= number_format($payoutRow['tax_amount'], 2) ?></span></div>
        <div class="receipt-row" style="font-weight:700;color:var(--ember-dk);"><span>Provider Payout</span><span>GH₵ <?= number_format($payoutRow['payout_amount'], 2) ?></span></div>
      </div>
      <?php endif; ?>

      <div class="receipt-footer">
        <p>QuickHire — Connecting Ghana, one job at a time.</p>
        <p style="margin-top:4px;">Receipt generated <?= date('j M Y · g:i A') ?></p>
      </div>
    </div>

    <div style="display:flex;gap:12px;margin-top:16px;">
      <button onclick="window.print()" class="print-btn" style="background:var(--bark);color:#fff;border:none;padding:12px 24px;border-radius:8px;font-size:0.85rem;font-weight:700;cursor:pointer;">🖨 Print Receipt</button>
      <a href="dashboard.php" class="back-link" style="padding:12px 24px;border:1.5px solid var(--border);border-radius:8px;font-size:0.85rem;font-weight:600;color:var(--bark);text-decoration:none;">← Back to Dashboard</a>
    </div>

    <!-- Rate Your Provider -->
    <?php if (!$alreadyReviewed && $booking['status'] === 'completed'): ?>
    <div class="feedback-card">
      <h3>Rate <?= htmlspecialchars($booking['provider_name']) ?></h3>
      <p style="font-size:0.84rem;color:var(--sand);margin-bottom:20px;">How was the service? Your review helps other customers.</p>
      <form action="submit_review.php" method="POST" novalidate style="display:flex;flex-direction:column;gap:14px;">
        <input type="hidden" name="review_type" value="customer">
        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
        <input type="hidden" name="provider_id" value="<?= $booking['provider_id'] ?? '' ?>">
        <input type="hidden" name="redirect_to" value="receipt.php?booking_id=<?= $booking_id ?>">
        <div class="form-field">
          <label>Rating</label>
          <select name="rating" required>
            <option value="" disabled selected>Rate this provider…</option>
            <option value="5">⭐⭐⭐⭐⭐ — Excellent</option>
            <option value="4">⭐⭐⭐⭐ — Good</option>
            <option value="3">⭐⭐⭐ — Average</option>
            <option value="2">⭐⭐ — Below Average</option>
            <option value="1">⭐ — Poor</option>
          </select>
        </div>
        <div class="form-field">
          <label>Your Review (optional)</label>
          <textarea name="comment" placeholder="Tell others about your experience…" style="min-height:80px;"></textarea>
        </div>
        <button type="submit" class="form-submit" style="max-width:200px;">Submit Review →</button>
      </form>
    </div>
    <?php elseif ($alreadyReviewed): ?>
    <div class="feedback-card">
      <p style="font-size:0.88rem;color:var(--ember);font-weight:600;">✓ You've already reviewed <?= htmlspecialchars($booking['provider_name']) ?> for this booking.</p>
    </div>
    <?php endif; ?>

    <!-- QuickHire Feedback -->
    <div class="feedback-card">
      <h3>How was your experience with QuickHire?</h3>
      <p style="font-size:0.84rem;color:var(--sand);margin-bottom:20px;">Help us improve — takes 30 seconds.</p>

      <?php if (!empty($feedbackSuccess)): ?>
        <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 16px;border-radius:8px;font-size:0.88rem;">
          <p><?= htmlspecialchars($feedbackSuccess) ?></p>
        </div>
      <?php else: ?>
      <form action="submit_feedback.php" method="POST" novalidate style="display:flex;flex-direction:column;gap:14px;">
        <input type="hidden" name="redirect_to" value="receipt.php?booking_id=<?= $booking_id ?>">
        <div class="form-field">
          <label>Rating</label>
          <select name="rating" required>
            <option value="" disabled selected>Rate QuickHire…</option>
            <option value="5">⭐⭐⭐⭐⭐ — Excellent</option>
            <option value="4">⭐⭐⭐⭐ — Good</option>
            <option value="3">⭐⭐⭐ — Average</option>
            <option value="2">⭐⭐ — Below Average</option>
            <option value="1">⭐ — Poor</option>
          </select>
        </div>
        <div class="form-field">
          <label>Category</label>
          <select name="category">
            <option value="general">General</option>
            <option value="usability">Ease of Use</option>
            <option value="features">Features</option>
            <option value="performance">Speed & Performance</option>
            <option value="support">Customer Support</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-field">
          <label>Feedback (optional)</label>
          <textarea name="message" placeholder="What did you like? What could be better?" style="min-height:80px;"></textarea>
        </div>
        <button type="submit" class="form-submit" style="max-width:200px;">Submit Feedback →</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Customer Support -->
    <div style="background:var(--card-bg);border:1.5px solid var(--border);border-radius:14px;padding:24px;margin-top:16px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
        <span style="font-size:1.1rem;">🛟</span>
        <h4 style="font-family:'Sora',sans-serif;font-size:1rem;font-weight:700;">Had an issue?</h4>
      </div>
      <p style="font-size:0.82rem;color:var(--sand);margin-bottom:14px;">Report a problem with your service or provider and our team will look into it.</p>
      <form action="submit_feedback.php" method="POST" novalidate style="display:flex;flex-direction:column;gap:12px;">
        <input type="hidden" name="redirect_to" value="receipt.php?booking_id=<?= $booking_id ?>">
        <input type="hidden" name="rating" value="1">
        <select name="category" style="padding:10px 14px;font-family:'Outfit',sans-serif;font-size:0.88rem;background:var(--cream);border:1.5px solid var(--border);border-radius:8px;color:var(--bark);outline:none;">
          <option value="service_issue">Issue with the service</option>
          <option value="provider_complaint">Issue with the provider</option>
          <option value="payment_issue">Payment problem</option>
          <option value="other">Other</option>
        </select>
        <textarea name="message" placeholder="Describe what went wrong…" required style="padding:10px 14px;font-family:'Outfit',sans-serif;font-size:0.88rem;background:var(--cream);border:1.5px solid var(--border);border-radius:8px;color:var(--bark);resize:vertical;min-height:70px;outline:none;"></textarea>
        <button type="submit" style="max-width:180px;padding:10px 18px;background:var(--bark);color:#fff;border:none;border-radius:8px;font-family:'Outfit',sans-serif;font-size:0.8rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;cursor:pointer;">Report Issue →</button>
      </form>
    </div>

  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

</body>
</html>
