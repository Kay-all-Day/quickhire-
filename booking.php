<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Must be logged in to book
if (!isLoggedIn()) {
    $_SESSION['redirect_to'] = 'booking.php' . (!empty($_GET['provider']) ? '?provider=' . intval($_GET['provider']) : '');
    $_SESSION['errors'] = ['Please log in or register to book a service.'];
    redirect('auth.php');
}

$errors = $_SESSION['errors'] ?? [];
$success = $_SESSION['success'] ?? '';
unset($_SESSION['errors'], $_SESSION['success']);

$selected_provider = intval($_GET['provider'] ?? 0);

// Fetch providers for dropdown (only verified)
$stmt = $pdo->query("
    SELECT sp.provider_id, u.full_name, sp.service_category, sp.rating
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    WHERE sp.is_verified = 1
    ORDER BY u.full_name
");
$providers = $stmt->fetchAll();

// Fetch services (only from verified providers)
$stmt = $pdo->query("
    SELECT s.service_id, s.service_name, s.price, s.provider_id
    FROM services s
    JOIN service_providers sp ON s.provider_id = sp.provider_id
    WHERE sp.is_verified = 1
    ORDER BY s.service_name
");
$services = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book a Service — QuickHire</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <?php if (isLoggedIn()): ?>
          <a href="dashboard.php">Dashboard<?= getNavNotifBadge($pdo) ?></a>
          <a href="logout.php">Logout</a>
        <?php else: ?>
          <a href="auth.php">Login</a>
          <a href="auth.php" class="cta">Register</a>
        <?php endif; ?>
      </div>
    </nav>
  </header>

  <div class="page-banner">
    <p class="page-banner-eyebrow">Schedule a service</p>
    <h1>Book a Professional</h1>
    <p>Choose your service, pick a time, and we'll confirm your booking instantly.</p>
  </div>

  <div class="booking-layout">

    <!-- ── Booking Form ── -->
    <div class="booking-form-card">
      <h2>Booking Details</h2>
      <p class="subtitle">Fill in the details below to schedule your service</p>

      <?php if (!empty($errors)): ?>
        <div style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <p><?= htmlspecialchars($success) ?></p>
          <p style="margin-top:8px;"><a href="dashboard.php" style="color:#166534;font-weight:600;">← Go to Dashboard</a></p>
        </div>
      <?php else: ?>

      <form action="process_booking.php" method="POST" novalidate>

        <div class="form-field">
          <label>Service Provider</label>
          <select name="provider_id" id="providerSelect" onchange="filterServices(); updateSummary(); loadBookedSlots();" required>
            <option value="" disabled <?= !$selected_provider ? 'selected' : '' ?>>Choose a provider…</option>
            <?php foreach ($providers as $p): ?>
              <option value="<?= $p['provider_id'] ?>" data-name="<?= htmlspecialchars($p['full_name']) ?>" data-category="<?= htmlspecialchars($p['service_category']) ?>" data-rating="<?= $p['rating'] ?>" <?= $p['provider_id'] == $selected_provider ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['full_name']) ?> — <?= htmlspecialchars($p['service_category']) ?> (<?= number_format($p['rating'], 1) ?> ★)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-field">
          <label>Service</label>
          <select name="service_id" id="serviceSelect" onchange="updateSummary()" required>
            <option value="" disabled selected>Select a service…</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-field">
            <label>Preferred Date</label>
            <input type="date" name="booking_date" id="bookingDate" required min="<?= date('Y-m-d') ?>" onchange="updateSummary(); loadBookedSlots();">
          </div>
          <div class="form-field">
            <label>Preferred Time</label>
            <input type="time" name="booking_time" id="bookingTime" required onchange="updateSummary(); checkTimeConflict();">
            <div id="slot-hint" style="margin-top:6px;font-size:0.78rem;display:none;"></div>
          </div>
        </div>

        <div class="form-field">
          <label>Service Address</label>
          <input type="text" name="address" placeholder="House number, street, area, city" required>
        </div>

        <div class="form-field">
          <label>Describe the job (optional)</label>
          <textarea name="notes" placeholder="Any extra details to help the provider prepare…"></textarea>
        </div>

        <button type="submit" class="form-submit">Confirm Booking →</button>

      </form>

      <?php endif; ?>
    </div>

    <!-- ── Booking Summary ── -->
    <div class="booking-summary-card">
      <h3>Booking Summary</h3>

      <div class="summary-row"><span>Service</span><span id="sum-service">—</span></div>
      <div class="summary-row"><span>Provider</span><span id="sum-provider">TBC</span></div>
      <div class="summary-row"><span>Date</span><span id="sum-date">TBC</span></div>
      <div class="summary-row"><span>Time</span><span id="sum-time">TBC</span></div>
      <div class="summary-row"><span>Location</span><span>Accra</span></div>

      <div id="tax-breakdown" style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(245,240,232,0.08);display:none;">
        <div class="summary-row"><span>Subtotal</span><span id="sum-subtotal">GH₵ —</span></div>
        <div class="summary-row"><span>VAT (15%)</span><span id="sum-vat">GH₵ —</span></div>
        <div class="summary-row"><span>NHIL (2.5%)</span><span id="sum-nhil">GH₵ —</span></div>
        <div class="summary-row"><span>GETFund (2.5%)</span><span id="sum-getfund">GH₵ —</span></div>
      </div>

      <div class="summary-total">
        <span class="label">Est. Total</span>
        <span class="amount" id="sum-price">GH₵ —</span>
      </div>

      <p style="font-size:0.75rem;color:rgba(245,240,232,0.35);margin-top:20px;line-height:1.6;">
        Prices include VAT (15%), NHIL (2.5%) &amp; GETFund Levy (2.5%). No payment taken until service is complete.
      </p>
    </div>

  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

  <script>
    const allServices = <?= json_encode($services) ?>;
    const providerSelect = document.getElementById('providerSelect');
    const serviceSelect = document.getElementById('serviceSelect');

    function filterServices() {
      const pid = parseInt(providerSelect.value);
      serviceSelect.innerHTML = '<option value="" disabled selected>Select a service…</option>';
      allServices.filter(s => s.provider_id == pid).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.service_id;
        opt.dataset.price = s.price;
        opt.textContent = s.service_name + ' — GH₵ ' + parseFloat(s.price).toFixed(2);
        serviceSelect.appendChild(opt);
      });
    }

    function updateSummary() {
      const pOpt = providerSelect.selectedOptions[0];
      const sOpt = serviceSelect.selectedOptions[0];
      const date = document.getElementById('bookingDate').value;
      const time = document.getElementById('bookingTime').value;

      if (pOpt && pOpt.value) {
        document.getElementById('sum-provider').textContent = pOpt.dataset.name || pOpt.textContent.split(' — ')[0];
      }
      if (sOpt && sOpt.value) {
        document.getElementById('sum-service').textContent = sOpt.textContent.split(' — ')[0];
        const base = parseFloat(sOpt.dataset.price);
        const vat     = base * 0.15;
        const nhil    = base * 0.025;
        const getfund = base * 0.025;
        const total   = base + vat + nhil + getfund;
        document.getElementById('sum-subtotal').textContent = 'GH₵ ' + base.toFixed(2);
        document.getElementById('sum-vat').textContent      = 'GH₵ ' + vat.toFixed(2);
        document.getElementById('sum-nhil').textContent     = 'GH₵ ' + nhil.toFixed(2);
        document.getElementById('sum-getfund').textContent  = 'GH₵ ' + getfund.toFixed(2);
        document.getElementById('sum-price').textContent    = 'GH₵ ' + total.toFixed(2);
        document.getElementById('tax-breakdown').style.display = 'block';
      }
      if (date) document.getElementById('sum-date').textContent = date;
      if (time) document.getElementById('sum-time').textContent = time;
    }

    if (providerSelect.value) filterServices();

    let takenHours = [];

    async function loadBookedSlots() {
      const pid  = parseInt(providerSelect.value);
      const date = document.getElementById('bookingDate').value;
      const hint = document.getElementById('slot-hint');
      takenHours = [];
      hint.style.display = 'none';
      if (!pid || !date) return;
      try {
        const res  = await fetch(`api/booked_slots.php?provider_id=${pid}&date=${encodeURIComponent(date)}`);
        takenHours = await res.json();
      } catch (_) {}
      if (takenHours.length > 0) {
        const labels = takenHours.map(h => {
          const ampm = h >= 12 ? 'PM' : 'AM';
          const h12  = h % 12 || 12;
          return `${h12}:00 ${ampm}`;
        });
        hint.innerHTML = `<span style="color:#c2410c;">⚠ Already booked: ${labels.join(', ')}</span>`;
        hint.style.display = 'block';
      }
      checkTimeConflict();
    }

    function checkTimeConflict() {
      const timeInput = document.getElementById('bookingTime');
      const hint      = document.getElementById('slot-hint');
      const val = timeInput.value;
      if (!val || takenHours.length === 0) {
        timeInput.style.borderColor = '';
        return;
      }
      const hour = parseInt(val.split(':')[0], 10);
      if (takenHours.includes(hour)) {
        timeInput.style.borderColor = '#ef4444';
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const h12  = hour % 12 || 12;
        hint.innerHTML = `<span style="color:#c2410c;">⚠ ${h12}:00 ${ampm} is already booked — pick a different time.</span>`;
        hint.style.display = 'block';
      } else {
        timeInput.style.borderColor = '#22c55e';
      }
    }
  </script>

</body>
</html>
