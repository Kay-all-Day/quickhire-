<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

$user_id = getUserId();
$user_name = getUserName();
$user_type = getUserType();

$errors = $_SESSION['errors'] ?? [];
$success = $_SESSION['success'] ?? '';
unset($_SESSION['errors'], $_SESSION['success']);

// Fetch user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$initials = '';
foreach (explode(' ', $user['full_name']) as $part) $initials .= strtoupper(substr($part, 0, 1));

// === CUSTOMER BOOKINGS ===
$stmt = $pdo->prepare("
    SELECT b.*, s.service_name, s.price, u.full_name AS provider_name, sp.service_category
    FROM bookings b
    JOIN service_providers sp ON b.provider_id = sp.provider_id
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN services s ON b.service_id = s.service_id
    WHERE b.user_id = ?
    ORDER BY b.booking_date DESC
");
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();

$totalBookings = count($bookings);
$active = 0; $completed = 0;
foreach ($bookings as $b) {
    if ($b['status'] === 'completed') $completed++;
    if (in_array($b['status'], ['pending', 'accepted'])) $active++;
}

// === REVIEWABLE BOOKINGS (customer reviewing providers) ===
$stmt = $pdo->prepare("
    SELECT b.booking_id, s.service_name, u.full_name AS provider_name, b.booking_date, b.provider_id
    FROM bookings b
    JOIN service_providers sp ON b.provider_id = sp.provider_id
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN services s ON b.service_id = s.service_id
    LEFT JOIN reviews r ON r.booking_id = b.booking_id AND r.user_id = ?
    WHERE b.user_id = ? AND b.status = 'completed' AND r.review_id IS NULL
    ORDER BY b.booking_date DESC
");
$stmt->execute([$user_id, $user_id]);
$reviewable = $stmt->fetchAll();

// Count reviews given
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM reviews WHERE user_id = ?");
$stmt->execute([$user_id]);
$reviewsGiven = $stmt->fetch()['total'];

// === REVIEWS RECEIVED ===
// As a customer: reviews left by providers about you (via booking)
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS reviewer_name, s.service_name, 'provider' AS reviewer_type
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    JOIN bookings b ON r.booking_id = b.booking_id
    LEFT JOIN services s ON b.service_id = s.service_id
    WHERE b.user_id = ? AND r.user_id != ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$user_id, $user_id]);
$reviewsAsCustomer = $stmt->fetchAll();

// As a provider: reviews left by customers about you
$reviewsAsProvider = [];
if (isProvider()) {
    $stmt = $pdo->prepare("
        SELECT r.*, u.full_name AS reviewer_name, s.service_name, 'customer' AS reviewer_type
        FROM reviews r
        JOIN users u ON r.user_id = u.user_id
        JOIN bookings b ON r.booking_id = b.booking_id
        LEFT JOIN services s ON b.service_id = s.service_id
        WHERE r.provider_id = (SELECT provider_id FROM service_providers WHERE user_id = ?)
          AND r.user_id = b.user_id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $reviewsAsProvider = $stmt->fetchAll();
}

// Greeting (will be updated by JavaScript to use client time)
$greeting = 'Welcome';
$firstName = explode(' ', $user['full_name'])[0];

// === PROVIDER-SPECIFIC ===
$providerBookings = [];
$providerStats = ['pending' => 0, 'accepted' => 0, 'completed' => 0, 'total' => 0];
$providerReviewable = [];
$provider_id = null;

if (isProvider()) {
    $stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $providerRow = $stmt->fetch();

    if ($providerRow) {
        $provider_id = $providerRow['provider_id'];

        // Provider incoming bookings
        $stmt = $pdo->prepare("
            SELECT b.*, s.service_name, s.price, u.full_name AS customer_name, u.phone AS customer_phone, u.email AS customer_email
            FROM bookings b
            JOIN users u ON b.user_id = u.user_id
            LEFT JOIN services s ON b.service_id = s.service_id
            WHERE b.provider_id = ?
            ORDER BY b.booking_date DESC
        ");
        $stmt->execute([$provider_id]);
        $providerBookings = $stmt->fetchAll();

        foreach ($providerBookings as $pb) {
            $providerStats['total']++;
            if (isset($providerStats[$pb['status']])) $providerStats[$pb['status']]++;
        }

        // Provider reviewable: completed bookings where provider hasn't reviewed the customer
        $stmt = $pdo->prepare("
            SELECT b.booking_id, s.service_name, u.full_name AS customer_name, b.booking_date, b.user_id AS customer_id
            FROM bookings b
            JOIN users u ON b.user_id = u.user_id
            LEFT JOIN services s ON b.service_id = s.service_id
            LEFT JOIN reviews r ON r.booking_id = b.booking_id AND r.user_id = ?
            WHERE b.provider_id = ? AND b.status = 'completed' AND r.review_id IS NULL
            ORDER BY b.booking_date DESC
        ");
        $stmt->execute([$user_id, $provider_id]);
        $providerReviewable = $stmt->fetchAll();

        // Provider commissions owed
        $commissions = [];
        $owedCommissions = [];
        $totalOwed = 0;
        try {
            $stmt = $pdo->prepare("
                SELECT pc.*, s.service_name, u.full_name AS customer_name
                FROM provider_commissions pc
                JOIN bookings b ON pc.booking_id = b.booking_id
                JOIN users u ON b.user_id = u.user_id
                LEFT JOIN services s ON b.service_id = s.service_id
                WHERE pc.provider_id = ?
                ORDER BY pc.created_at DESC
            ");
            $stmt->execute([$provider_id]);
            $commissions = $stmt->fetchAll();
            $owedCommissions = array_filter($commissions, fn($c) => $c['status'] === 'owed');
            $totalOwed = array_sum(array_map(fn($c) => $c['amount'], $owedCommissions));
        } catch (Exception $e) {
            // Table may not exist yet
        }

        // Provider payouts (card / mobile_money earnings)
        $payoutStats    = ['all_time' => 0.0, 'month' => 0.0, 'week' => 0.0];
        $providerPayouts = [];
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE provider_id=? AND status='released'");
            $stmt->execute([$provider_id]);
            $payoutStats['all_time'] = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE provider_id=? AND status='released' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
            $stmt->execute([$provider_id]);
            $payoutStats['month'] = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE provider_id=? AND status='released' AND YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)");
            $stmt->execute([$provider_id]);
            $payoutStats['week'] = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT pp.*, s.service_name, u.full_name AS customer_name
                FROM provider_payouts pp
                JOIN bookings b ON pp.booking_id = b.booking_id
                JOIN users u ON b.user_id = u.user_id
                LEFT JOIN services s ON b.service_id = s.service_id
                WHERE pp.provider_id = ? AND pp.status = 'released'
                ORDER BY pp.created_at DESC
                LIMIT 30
            ");
            $stmt->execute([$provider_id]);
            $providerPayouts = $stmt->fetchAll();
        } catch (Exception $e) {}
    }
}

// === USER FEEDBACK / REPORTS ===
$myFeedback = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM platform_feedback WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $myFeedback = $stmt->fetchAll();
} catch (Exception $e) {
    // Table may not exist yet
}

// === FEATURED REQUEST STATUS (for providers) ===
$featuredRequest = null;
$providerInfo = null;
if (isProvider() && isset($provider_id)) {
    $stmt = $pdo->prepare("SELECT * FROM featured_requests WHERE provider_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$provider_id]);
    $featuredRequest = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM service_providers WHERE provider_id = ?");
    $stmt->execute([$provider_id]);
    $providerInfo = $stmt->fetch();

    // Get provider's services for management
    $stmt = $pdo->prepare("SELECT * FROM services WHERE provider_id = ? ORDER BY price ASC");
    $stmt->execute([$provider_id]);
    $myServices = $stmt->fetchAll();

    // Get latest verification request
    $stmt = $pdo->prepare("SELECT * FROM verification_requests WHERE provider_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$provider_id]);
    $verificationRequest = $stmt->fetch();
}

// === COMBINED OVERVIEW STATS FOR PROVIDERS ===
$overviewTotal = $totalBookings;
$overviewActive = $active;
$overviewCompleted = $completed;
$overviewReviews = $reviewsGiven;

if (isProvider()) {
    $overviewTotal += $providerStats['total'];
    $overviewActive += $providerStats['pending'] + $providerStats['accepted'];
    $overviewCompleted += $providerStats['completed'];
}

// === PAYMENTS ===
$stmt = $pdo->prepare("
    SELECT p.*, b.booking_date, b.address, s.service_name, 
           u_cust.full_name AS customer_name, u_prov.full_name AS provider_name
    FROM payments p
    JOIN bookings b ON p.booking_id = b.booking_id
    LEFT JOIN services s ON b.service_id = s.service_id
    JOIN users u_cust ON b.user_id = u_cust.user_id
    JOIN service_providers sp ON b.provider_id = sp.provider_id
    JOIN users u_prov ON sp.user_id = u_prov.user_id
    WHERE b.user_id = ? OR sp.user_id = ?
    ORDER BY p.payment_id DESC
");
$stmt->execute([$user_id, $user_id]);
$payments = $stmt->fetchAll();

// === UNPAID COMPLETED BOOKINGS (for "Pay Now" button) ===
$unpaidBookings = [];
$stmt = $pdo->prepare("
    SELECT p.payment_id, p.amount, p.payment_status, b.booking_id
    FROM payments p
    JOIN bookings b ON p.booking_id = b.booking_id
    WHERE b.user_id = ? AND b.status = 'completed' AND p.payment_status = 'pending'
");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch()) {
    $unpaidBookings[$row['booking_id']] = $row;
}

// Notification/message counts for header
$notifCount = getUnreadCount($pdo, $user_id);
$msgCount = getUnreadMessages($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>
    /* Password toggle */
    .pw-wrap { position: relative; }
    .pw-wrap input { width: 100%; padding-right: 44px; }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; font-size: 1.1rem;
      color: var(--sand); padding: 4px; line-height: 1;
    }
    .pw-toggle:hover { color: var(--bark); }

    /* Booking maps */
    .booking-map {
      width: 100%;
      height: 180px;
      border-radius: 10px;
      border: 1px solid var(--border);
      margin: 12px 0 16px;
      z-index: 1;
    }
    .booking-map-label {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--sand);
      margin-bottom: 6px;
    }

    /* Receipt card */
    .receipt-card {
      background: #fff; border: 1.5px solid var(--border); border-radius: 12px;
      padding: 32px; max-width: 500px; margin-bottom: 20px;
    }
    .receipt-header { text-align: center; margin-bottom: 24px; border-bottom: 2px dashed var(--border); padding-bottom: 20px; }
    .receipt-header h3 { font-family: 'Fraunces', serif; font-size: 1.4rem; font-weight: 900; letter-spacing: -0.03em; }
    .receipt-header p { font-size: 0.8rem; color: var(--sand); }
    .receipt-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.88rem; border-bottom: 1px solid var(--border); }
    .receipt-row:last-child { border-bottom: none; }
    .receipt-row span:first-child { color: var(--sand); font-weight: 600; }
    .receipt-total { display: flex; justify-content: space-between; padding: 14px 0; margin-top: 12px; border-top: 2px solid var(--bark); font-weight: 700; font-size: 1.1rem; }
    .receipt-total span:last-child { font-family: 'Fraunces', serif; font-size: 1.4rem; color: var(--ember); }
    .receipt-footer { text-align: center; margin-top: 20px; padding-top: 16px; border-top: 2px dashed var(--border); font-size: 0.78rem; color: var(--sand); }
    .print-btn {
      display: inline-block; padding: 10px 24px; background: var(--bark); color: var(--cream);
      border: none; border-radius: 5px; font-size: 0.78rem; font-weight: 700;
      letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; margin-top: 12px;
    }
    .print-btn:hover { background: #3d2e18; }

    @media print {
      header, .dash-sidebar, .dash-nav-item, .print-btn, .alert { display: none !important; }
      .dash-layout { grid-template-columns: 1fr !important; }
      .dash-main { padding: 0 !important; }
      .dash-panel { display: none !important; }
      .dash-panel.printing { display: block !important; }
    }
  </style>
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <?php if (isAdmin()): ?><a href="admin.php" style="color:var(--ember);">Admin</a><?php endif; ?>
        <a href="messages.php" style="position:relative;">Messages<?php if ($msgCount > 0): ?><span style="position:absolute;top:-4px;right:-8px;background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 5px;border-radius:10px;font-weight:700;"><?= $msgCount ?></span><?php endif; ?></a>
        <a href="notifications.php" style="position:relative;">🔔<?php if ($notifCount > 0): ?><span style="position:absolute;top:-4px;right:-8px;background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 5px;border-radius:10px;font-weight:700;"><?= $notifCount ?></span><?php endif; ?></a>
        <a href="logout.php">Logout</a>
      </div>
    </nav>
  </header>

  <div class="dash-layout">

    <aside class="dash-sidebar">
      <div class="dash-user">
        <div class="dash-avatar"><?= $initials ?></div>
        <div class="dash-user-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="dash-user-email"><?= htmlspecialchars($user['email']) ?></div>
      </div>

      <a class="dash-nav-item active" id="nav-overview" onclick="showPanel('overview', this)">
        <span class="dash-nav-icon">🏠</span> Overview
      </a>
      <?php if (isCustomer()): ?>
      <a class="dash-nav-item" id="nav-bookings" onclick="showPanel('bookings', this)">
        <span class="dash-nav-icon">📅</span> My Bookings
      </a>
      <?php endif; ?>
      <a class="dash-nav-item" id="nav-tracking" onclick="showPanel('tracking', this)">
        <span class="dash-nav-icon">📍</span> Track Service
      </a>
      <?php if (isProvider()): ?>
      <a class="dash-nav-item" id="nav-reviews" onclick="showPanel('reviews', this)">
        <span class="dash-nav-icon">⭐</span> Customer Reviews
      </a>
      <?php endif; ?>
      <a class="dash-nav-item" id="nav-payments" onclick="showPanel('payments', this)">
        <span class="dash-nav-icon">💳</span> <?= isProvider() ? 'Payments & Earnings' : 'Payments' ?>
      </a>
      <?php if (isProvider()): ?>
      <a class="dash-nav-item" id="nav-provider-bookings" onclick="showPanel('provider-bookings', this)">
        <span class="dash-nav-icon">📋</span> Manage Bookings
      </a>
      <a class="dash-nav-item" id="nav-get-featured" onclick="showPanel('get-featured', this)">
        <span class="dash-nav-icon">🌟</span> Get Featured
      </a>
      <a class="dash-nav-item" id="nav-get-verified" onclick="showPanel('get-verified', this)">
        <span class="dash-nav-icon">✅</span> Get Verified
      </a>
      <?php endif; ?>
      <a class="dash-nav-item" id="nav-support" onclick="showPanel('support', this); clearSupportBadge();">
        <span class="dash-nav-icon">🛟</span> Support<?php
          $issueCategories = ['service_issue', 'provider_complaint', 'payment_issue', 'support'];
          $unreadIssueReplies = 0;
          foreach ($myFeedback as $f) {
              if (in_array($f['category'], $issueCategories) && !empty($f['admin_reply']) && !$f['is_read']) $unreadIssueReplies++;
          }
          if ($unreadIssueReplies > 0): ?> <span id="support-badge" style="background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 6px;border-radius:10px;font-weight:700;margin-left:4px;"><?= $unreadIssueReplies ?></span><?php endif; ?>
      </a>
      <a class="dash-nav-item" id="nav-profile" onclick="showPanel('profile', this)">
        <span class="dash-nav-icon">👤</span> My Profile
      </a>
    </aside>

    <main class="dash-main">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:24px;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($success)): ?>
        <div class="alert alert-success" style="margin-bottom:24px;">
          <p><?= htmlspecialchars($success) ?></p>
        </div>
      <?php endif; ?>

      <div id="overview" class="dash-panel active">
        <h2 class="dash-panel-title"><span id="greeting-text"><?= $greeting ?></span>, <?= htmlspecialchars($firstName) ?> 👋</h2>
        <p class="dash-panel-sub">Here's a summary of your QuickHire activity.</p>

        <div class="dash-stats">
          <div class="dash-stat"><span class="num"><?= $overviewTotal ?></span><span class="lbl">Total Bookings</span></div>
          <div class="dash-stat"><span class="num"><?= $overviewActive ?></span><span class="lbl">Active Service</span></div>
          <div class="dash-stat"><span class="num"><?= $overviewCompleted ?></span><span class="lbl">Completed</span></div>
          <div class="dash-stat"><span class="num"><?= $overviewReviews ?></span><span class="lbl">Reviews Given</span></div>
        </div>

        <?php if (isProvider() && $providerStats['pending'] > 0): ?>
        <div class="profile-section" style="border-left:3px solid var(--ember);">
          <h3>⏳ <?= $providerStats['pending'] ?> Pending Request<?= $providerStats['pending'] > 1 ? 's' : '' ?></h3>
          <p>You have incoming bookings waiting for your response. <a href="#" onclick="showPanel('provider-bookings', document.getElementById('nav-provider-bookings'));return false;" style="color:var(--ember);font-weight:700;">Manage Bookings →</a></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($unpaidBookings)): ?>
        <div class="profile-section" style="border-left:3px solid #d97706;">
          <h3>💳 <?= count($unpaidBookings) ?> Unpaid Booking<?= count($unpaidBookings) > 1 ? 's' : '' ?></h3>
          <p>You have completed services awaiting payment. <a href="#" onclick="showPanel('bookings', document.getElementById('nav-bookings'));return false;" style="color:var(--ember);font-weight:700;">View Bookings →</a></p>
        </div>
        <?php endif; ?>

        <?php if (isProvider() && $providerInfo && !$providerInfo['is_verified']): ?>
        <div class="profile-section" style="border-left:3px solid #f43f5e;">
          <h3>⚠️ Your Profile is Not Verified</h3>
          <p>You cannot receive bookings until your identity is verified. Submit your documents now to start getting customers. <a href="#" onclick="showPanel('get-verified', document.getElementById('nav-get-verified'));return false;" style="color:var(--ember);font-weight:700;">Get Verified →</a></p>
        </div>
        <?php endif; ?>

        <div class="profile-section">
          <h3>Recent Activity</h3>
          <?php
          // Merge customer + provider bookings for overview
          $recentAll = $bookings;
          if (isProvider()) {
              foreach ($providerBookings as $pb) {
                  $pb['_is_provider'] = true;
                  $recentAll[] = $pb;
              }
              usort($recentAll, fn($a, $b) => strtotime($b['booking_date']) - strtotime($a['booking_date']));
          }
          if (empty($recentAll)): ?>
            <p>No bookings yet. <a href="categories.php" style="color:var(--ember);">Browse services →</a></p>
          <?php else: ?>
          <table class="bookings-table">
            <thead>
              <tr><th>Role</th><th>Service</th><th>With</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($recentAll, 0, 5) as $b): ?>
              <tr>
                <td><?= isset($b['_is_provider']) ? '<span style="color:var(--ember);font-weight:600;">Provider</span>' : 'Customer' ?></td>
                <td><?= htmlspecialchars($b['service_name'] ?? $b['service_category'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars(isset($b['_is_provider']) ? ($b['customer_name'] ?? '') : ($b['provider_name'] ?? '')) ?></td>
                <td><?= date('j M Y', strtotime($b['booking_date'])) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <div id="bookings" class="dash-panel">
        <h2 class="dash-panel-title">My Bookings</h2>
        <p class="dash-panel-sub">All current and past service bookings.</p>

        <div class="profile-section">
          <h3>All Bookings</h3>
          <?php if (empty($bookings)): ?>
            <p>No bookings yet.</p>
          <?php else: ?>
          <table class="bookings-table">
            <thead>
              <tr><th>Service</th><th>Provider</th><th>Date</th><th>Price</th><th>Status</th><th>Payment</th></tr>
            </thead>
            <tbody>
              <?php foreach ($bookings as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['service_name'] ?? $b['service_category']) ?></td>
                <td><?= htmlspecialchars($b['provider_name']) ?></td>
                <td><?= date('j M Y', strtotime($b['booking_date'])) ?></td>
                <td>GH₵ <?= number_format($b['price'] ?? 0, 0) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                <td>
                  <?php if (isset($unpaidBookings[$b['booking_id']])): ?>
                    <a href="make_payment.php?booking_id=<?= $b['booking_id'] ?>" style="background:var(--ember);color:#fff;padding:5px 14px;border-radius:4px;font-size:0.75rem;font-weight:700;text-decoration:none;letter-spacing:0.05em;text-transform:uppercase;">Pay Now</a>
                  <?php elseif ($b['status'] === 'completed'): ?>
                    <span class="status-badge status-completed">Paid</span>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <div id="tracking" class="dash-panel">
        <h2 class="dash-panel-title">Track Your Service</h2>
        <p class="dash-panel-sub">Live status of your active service requests.</p>

        <?php 
        // Customer bookings that are active
        $activeBookings = array_filter($bookings, fn($b) => in_array($b['status'], ['pending', 'accepted']));
        // Provider bookings that are active (persist until marked complete)
        $activeProviderBookings = [];
        if (isProvider()) {
            $activeProviderBookings = array_filter($providerBookings, fn($b) => in_array($b['status'], ['pending', 'accepted']));
        }
        $hasActive = !empty($activeBookings) || !empty($activeProviderBookings);
        if (!$hasActive): ?>
          <p>No active bookings to track.</p>
        <?php else: ?>

        <?php if (!empty($activeBookings)): ?>
        <h3 style="font-size:1rem;font-weight:700;margin-bottom:12px;">Your Bookings (as Customer)</h3>
        <?php foreach ($activeBookings as $b): ?>
        <div class="tracking-card">
          <h4>Booking #QH-<?= date('Y', strtotime($b['booking_date'])) ?>-<?= str_pad($b['booking_id'], 4, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($b['service_name'] ?? $b['service_category']) ?> · <?= htmlspecialchars($b['provider_name']) ?></h4>
          <div class="timeline">
            <div class="tl-step done">
              <div class="tl-dot">✓</div>
              <div class="tl-content"><div class="tl-label">Booking Submitted</div><div class="tl-time"><?= date('j M Y', strtotime($b['booking_date'])) ?></div></div>
            </div>
            <div class="tl-step <?= $b['status'] === 'accepted' ? 'done' : ($b['status'] === 'pending' ? 'current' : '') ?>">
              <div class="tl-dot"><?= $b['status'] === 'accepted' ? '✓' : '' ?></div>
              <div class="tl-content"><div class="tl-label">Provider <?= $b['status'] === 'accepted' ? 'Accepted' : 'Review' ?></div><div class="tl-time"><?= $b['status'] === 'pending' ? 'Waiting for provider' : 'Confirmed' ?></div></div>
            </div>
            <div class="tl-step <?= $b['status'] === 'accepted' ? 'current' : '' ?>">
              <div class="tl-dot"></div>
              <div class="tl-content"><div class="tl-label">Service In Progress</div><div class="tl-time">Pending</div></div>
            </div>
            <div class="tl-step">
              <div class="tl-dot"></div>
              <div class="tl-content"><div class="tl-label">Completed</div><div class="tl-time">Pending</div></div>
            </div>
          </div>
          <p class="booking-map-label" style="margin-top:16px;">📍 Service Location: <?= htmlspecialchars($b['address'] ?? 'Not set') ?></p>
          <div class="booking-map" id="map-track-<?= $b['booking_id'] ?>" data-address="<?= htmlspecialchars($b['address'] ?? 'Accra, Ghana') ?>"></div>
        </div>
        <?php endforeach; endif; ?>

        <?php if (!empty($activeProviderBookings)): ?>
        <h3 style="font-size:1rem;font-weight:700;margin:24px 0 12px;">Your Jobs (as Provider)</h3>
        <?php foreach ($activeProviderBookings as $b): ?>
        <div class="tracking-card">
          <h4>Job #QH-<?= date('Y', strtotime($b['booking_date'])) ?>-<?= str_pad($b['booking_id'], 4, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($b['service_name'] ?? $b['service_category'] ?? 'Service') ?> · <?= htmlspecialchars($b['customer_name']) ?></h4>
          <div class="timeline">
            <div class="tl-step done">
              <div class="tl-dot">✓</div>
              <div class="tl-content"><div class="tl-label">Booking Received</div><div class="tl-time"><?= date('j M Y', strtotime($b['booking_date'])) ?></div></div>
            </div>
            <div class="tl-step <?= $b['status'] === 'accepted' ? 'done' : ($b['status'] === 'pending' ? 'current' : '') ?>">
              <div class="tl-dot"><?= $b['status'] === 'accepted' ? '✓' : '' ?></div>
              <div class="tl-content"><div class="tl-label"><?= $b['status'] === 'accepted' ? 'You Accepted' : 'Awaiting Your Response' ?></div><div class="tl-time"><?= $b['status'] === 'pending' ? 'Accept or decline in Manage Bookings' : 'Confirmed' ?></div></div>
            </div>
            <div class="tl-step <?= $b['status'] === 'accepted' ? 'current' : '' ?>">
              <div class="tl-dot"></div>
              <div class="tl-content"><div class="tl-label">Service In Progress</div><div class="tl-time"><?= $b['status'] === 'accepted' ? 'Mark complete when done' : 'Pending' ?></div></div>
            </div>
            <div class="tl-step">
              <div class="tl-dot"></div>
              <div class="tl-content"><div class="tl-label">Completed</div><div class="tl-time">Pending</div></div>
            </div>
          </div>
          <p class="booking-map-label" style="margin-top:16px;">📍 Service Location: <?= htmlspecialchars($b['address'] ?? 'Not set') ?></p>
          <div class="booking-map" id="map-ptrack-<?= $b['booking_id'] ?>" data-address="<?= htmlspecialchars($b['address'] ?? 'Accra, Ghana') ?>"></div>
          <?php if ($b['status'] === 'accepted'): ?>
          <a href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($b['address'] ?? 'Accra, Ghana') ?>" target="_blank" style="display:inline-block;margin-top:10px;padding:10px 20px;background:var(--ember);color:#fff;border-radius:8px;font-size:0.8rem;font-weight:700;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;">🧭 Get Directions →</a>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>

        <?php endif; ?>
      </div>

      <?php if (isProvider()): ?>
      <div id="reviews" class="dash-panel">
        <h2 class="dash-panel-title">Customer Reviews</h2>

        <?php if (!empty($reviewsAsProvider)): ?>
        <div class="profile-section" style="margin-top:24px;">
          <h3>Reviews About You</h3>
          <p style="font-size:0.82rem;color:var(--sand);margin-bottom:16px;">Reviews from customers about your services. These are visible on your public profile.</p>
          <div class="review-list">
            <?php foreach ($reviewsAsProvider as $rv):
              $rvStars = str_repeat('★', $rv['rating']) . str_repeat('☆', 5 - $rv['rating']);
            ?>
            <div class="review-item" style="padding:16px 0;border-bottom:1px solid var(--border);">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="font-weight:700;font-size:0.92rem;"><?= htmlspecialchars($rv['reviewer_name']) ?> <span style="font-size:0.75rem;color:var(--sand);font-weight:400;">(Customer)</span></span>
                <span style="color:var(--ember);font-size:0.9rem;"><?= $rvStars ?></span>
              </div>
              <?php if (!empty($rv['service_name'])): ?>
                <p style="font-size:0.78rem;color:var(--sand);margin-bottom:6px;">Service: <?= htmlspecialchars($rv['service_name']) ?></p>
              <?php endif; ?>
              <p style="font-size:0.88rem;line-height:1.5;"><?= htmlspecialchars($rv['comment']) ?></p>
              <p style="font-size:0.75rem;color:var(--sand);margin-top:6px;"><?= date('j M Y', strtotime($rv['created_at'])) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="profile-section" style="margin-top:24px;">
          <h3>Reviews About You</h3>
          <p>No one has reviewed you yet.</p>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div id="payments" class="dash-panel">
        <h2 class="dash-panel-title"><?= isProvider() ? 'Payments & Earnings' : 'Payments & Receipts' ?></h2>
        <p class="dash-panel-sub"><?= isProvider() ? 'Your payout history, commission summary, and payment receipts.' : 'View your payment history and download receipts.' ?></p>

        <?php if (isProvider()): ?>
        <!-- Earnings strip (provider only) -->
        <div class="dash-stats" style="margin-bottom:24px;">
          <div class="dash-stat">
            <span class="num" style="font-size:1.2rem;color:var(--ember);">GH&#x20B5; <?= number_format($payoutStats['all_time'], 2) ?></span>
            <span class="lbl">Total Earned (All Time)</span>
          </div>
          <div class="dash-stat">
            <span class="num" style="font-size:1.2rem;color:var(--ember);">GH&#x20B5; <?= number_format($payoutStats['month'], 2) ?></span>
            <span class="lbl">This Month</span>
          </div>
          <div class="dash-stat">
            <span class="num" style="font-size:1.2rem;color:var(--ember);">GH&#x20B5; <?= number_format($payoutStats['week'], 2) ?></span>
            <span class="lbl">This Week</span>
          </div>
        </div>

        <?php if (!empty($providerPayouts)): ?>
        <div class="profile-section">
          <h3>Payout History (Card &amp; Mobile Money)</h3>
          <table class="bookings-table">
            <thead>
              <tr>
                <th>Date</th><th>Booking #</th><th>Service</th><th>Customer</th>
                <th>Gross</th><th>Commission</th><th>Tax</th><th>Payout</th><th>Method</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($providerPayouts as $pp): ?>
              <tr>
                <td style="white-space:nowrap;font-size:0.8rem;"><?= date('j M Y', strtotime($pp['created_at'])) ?></td>
                <td>#QH-<?= str_pad($pp['booking_id'], 4, '0', STR_PAD_LEFT) ?></td>
                <td><?= htmlspecialchars($pp['service_name'] ?? 'Service') ?></td>
                <td><?= htmlspecialchars($pp['customer_name']) ?></td>
                <td>GH&#x20B5; <?= number_format($pp['gross_amount'], 2) ?></td>
                <td>GH&#x20B5; <?= number_format($pp['commission_amount'], 2) ?></td>
                <td>GH&#x20B5; <?= number_format($pp['tax_amount'], 2) ?></td>
                <td style="font-weight:700;color:var(--ember-dk);">GH&#x20B5; <?= number_format($pp['payout_amount'], 2) ?></td>
                <td><?= ucwords(str_replace('_', ' ', $pp['payment_method'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="profile-section">
          <p style="color:var(--sand);font-size:0.9rem;">No payouts yet. Earnings appear here automatically when customers pay via card or Mobile Money.</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($payments)): ?>
          <div class="profile-section"><p>No payments yet.</p></div>
        <?php else: ?>

        <div class="profile-section">
          <h3>Payment History</h3>
          <table class="bookings-table">
            <thead>
              <tr><th>Receipt #</th><th>Service</th><th>Customer</th><th>Provider</th><th>Amount</th><th>Method</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $p): ?>
              <tr>
                <td>QH-R<?= str_pad($p['payment_id'], 4, '0', STR_PAD_LEFT) ?></td>
                <td><?= htmlspecialchars($p['service_name'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($p['customer_name']) ?></td>
                <td><?= htmlspecialchars($p['provider_name']) ?></td>
                <td>GH₵ <?= number_format($p['amount'], 2) ?></td>
                <td><?= ucwords(str_replace('_', ' ', $p['payment_method'])) ?></td>
                <td><span class="status-badge status-<?= $p['payment_status'] ?>"><?= ucfirst($p['payment_status']) ?></span></td>
                <td>
                  <button class="print-btn" onclick="showReceipt(<?= $p['payment_id'] ?>)" style="padding:4px 10px;font-size:0.7rem;">View Receipt</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php foreach ($payments as $p): ?>
        <div id="receipt-<?= $p['payment_id'] ?>" class="profile-section" style="display:none;">
          <div class="receipt-card">
            <div class="receipt-header">
              <h3>QuickHire</h3>
              <p>Payment Receipt</p>
              <p style="font-weight:700;margin-top:8px;">Receipt # QH-R<?= str_pad($p['payment_id'], 4, '0', STR_PAD_LEFT) ?></p>
            </div>
            <div class="receipt-row"><span>Date</span><span><?= date('j M Y', strtotime($p['booking_date'])) ?></span></div>
            <div class="receipt-row"><span>Service</span><span><?= htmlspecialchars($p['service_name'] ?? 'N/A') ?></span></div>
            <div class="receipt-row"><span>Customer</span><span><?= htmlspecialchars($p['customer_name']) ?></span></div>
            <div class="receipt-row"><span>Provider</span><span><?= htmlspecialchars($p['provider_name']) ?></span></div>
            <div class="receipt-row"><span>Location</span><span><?= htmlspecialchars($p['address']) ?></span></div>
            <div class="receipt-row"><span>Payment Method</span><span><?= ucwords(str_replace('_', ' ', $p['payment_method'])) ?></span></div>
            <div class="receipt-row"><span>Payment Status</span><span><?= ucfirst($p['payment_status']) ?></span></div>
            <div class="receipt-total"><span>Total</span><span>GH₵ <?= number_format($p['amount'], 2) ?></span></div>
            <div class="receipt-footer">
              <p>Thank you for using QuickHire!</p>
              <p>Connecting Ghana, one job at a time.</p>
              <button class="print-btn" onclick="printReceipt(<?= $p['payment_id'] ?>)">Print Receipt</button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

        <?php if (isProvider() && !empty($commissions)): ?>
        <div class="profile-section" style="margin-top:20px;">
          <h3>Platform Commission</h3>
          <?php if ($totalOwed > 0): ?>
          <div style="background:rgba(249,115,22,0.06);border:1px solid rgba(249,115,22,0.15);border-radius:10px;padding:16px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
              <p style="font-size:0.88rem;color:#c2410c;font-weight:700;">Outstanding: GH₵ <?= number_format($totalOwed, 2) ?></p>
              <p style="font-size:0.82rem;color:var(--sand);margin-top:2px;"><?= count($owedCommissions) ?> unpaid commission<?= count($owedCommissions) !== 1 ? 's' : '' ?> from cash payments</p>
            </div>
            <a href="pay_commission.php?mode=all" style="display:inline-block;padding:10px 24px;background:var(--ember);color:#fff;border-radius:8px;font-size:0.8rem;font-weight:700;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;">Pay All - GH₵ <?= number_format($totalOwed, 2) ?> →</a>
          </div>
          <?php endif; ?>

          <table class="bookings-table">
            <thead><tr><th>Booking</th><th>Customer</th><th>Service</th><th>Commission</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($commissions as $c): ?>
              <tr>
                <td>#QH-<?= str_pad($c['booking_id'], 4, '0', STR_PAD_LEFT) ?></td>
                <td><?= htmlspecialchars($c['customer_name']) ?></td>
                <td><?= htmlspecialchars($c['service_name'] ?? 'Service') ?></td>
                <td style="font-weight:700;">GH₵ <?= number_format($c['amount'], 2) ?></td>
                <td>
                  <?php if ($c['status'] === 'paid'): ?>
                    <span class="status-badge status-completed">Paid</span>
                    <a href="commission_receipt.php?id=<?= $c['id'] ?>" style="color:var(--ember);font-size:0.72rem;font-weight:600;text-decoration:none;margin-left:6px;">🧾 Receipt</a>
                  <?php else: ?>
                    <span class="status-badge status-pending">Owed</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php if (isProvider()): ?>
      <div id="provider-bookings" class="dash-panel">
        <h2 class="dash-panel-title">Manage Bookings</h2>
        <p class="dash-panel-sub">Review and respond to incoming service requests.</p>

        <div class="dash-stats">
          <div class="dash-stat"><span class="num"><?= $providerStats['total'] ?></span><span class="lbl">Total Requests</span></div>
          <div class="dash-stat"><span class="num"><?= $providerStats['pending'] ?></span><span class="lbl">Pending</span></div>
          <div class="dash-stat"><span class="num"><?= $providerStats['accepted'] ?></span><span class="lbl">Accepted</span></div>
          <div class="dash-stat"><span class="num"><?= $providerStats['completed'] ?></span><span class="lbl">Completed</span></div>
        </div>

        <?php if (empty($providerBookings)): ?>
          <div class="profile-section"><p>No booking requests yet.</p></div>
        <?php else: ?>
          <?php $pendingBookings = array_filter($providerBookings, fn($b) => $b['status'] === 'pending'); ?>
          <?php $otherBookings = array_filter($providerBookings, fn($b) => $b['status'] !== 'pending'); ?>

          <?php if (!empty($pendingBookings)): ?>
          <div class="profile-section">
            <h3>⏳ Pending Requests</h3>
            <?php foreach ($pendingBookings as $pb): ?>
            <div class="tracking-card">
              <h4><?= htmlspecialchars($pb['service_name'] ?? 'Service Request') ?> - <?= htmlspecialchars($pb['customer_name']) ?></h4>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0;font-size:0.88rem;">
                <div><span style="color:var(--sand);font-weight:600;">Date:</span> <?= date('j M Y', strtotime($pb['booking_date'])) ?></div>
                <div><span style="color:var(--sand);font-weight:600;">Time:</span> <?= date('g:i A', strtotime($pb['booking_date'])) ?></div>
                <div><span style="color:var(--sand);font-weight:600;">Location:</span> <?= htmlspecialchars($pb['address']) ?></div>
                <div><span style="color:var(--sand);font-weight:600;">Phone:</span> <?= htmlspecialchars($pb['customer_phone'] ?? 'N/A') ?></div>
                <?php if (!empty($pb['price'])): ?>
                <div><span style="color:var(--sand);font-weight:600;">Price:</span> GH₵ <?= number_format($pb['price'], 2) ?></div>
                <?php endif; ?>
              </div>
              <?php if (!empty($pb['notes'])): ?>
                <p style="font-size:0.85rem;color:var(--warm-mid);margin-bottom:16px;"><strong>Notes:</strong> <?= htmlspecialchars($pb['notes']) ?></p>
              <?php endif; ?>
              <p class="booking-map-label">📍 Customer Location</p>
              <div class="booking-map" id="map-pending-<?= $pb['booking_id'] ?>" data-address="<?= htmlspecialchars($pb['address']) ?>"></div>
              <div style="display:flex;gap:10px;">
                <form action="update_booking.php" method="POST" style="display:inline;">
                  <input type="hidden" name="booking_id" value="<?= $pb['booking_id'] ?>">
                  <input type="hidden" name="action" value="accept">
                  <button type="submit" class="form-submit" style="max-width:160px;background:var(--ember);">Accept ✓</button>
                </form>
                <form action="update_booking.php" method="POST" style="display:inline;">
                  <input type="hidden" name="booking_id" value="<?= $pb['booking_id'] ?>">
                  <input type="hidden" name="action" value="decline">
                  <button type="submit" class="form-submit" style="max-width:160px;background:var(--warm-mid);">Decline ✗</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($otherBookings)): ?>
          <div class="profile-section">
            <h3>All Bookings</h3>
            <table class="bookings-table">
              <thead><tr><th>Customer</th><th>Service</th><th>Date</th><th>Price</th><th>Status</th><th>Contact</th><th>Location</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($otherBookings as $pb): ?>
                <tr>
                  <td><?= htmlspecialchars($pb['customer_name']) ?></td>
                  <td><?= htmlspecialchars($pb['service_name'] ?? 'N/A') ?></td>
                  <td><?= date('j M Y', strtotime($pb['booking_date'])) ?></td>
                  <td>GH₵ <?= number_format($pb['price'] ?? 0, 0) ?></td>
                  <td><span class="status-badge status-<?= $pb['status'] ?>"><?= ucfirst($pb['status']) ?></span></td>
                  <td>
                    <?php if ($pb['status'] === 'accepted' || $pb['status'] === 'completed'): ?>
                      <a href="tel:<?= htmlspecialchars($pb['customer_phone'] ?? '') ?>" style="color:var(--ember);font-weight:600;font-size:0.82rem;text-decoration:none;">📞 <?= htmlspecialchars($pb['customer_phone'] ?? 'N/A') ?></a>
                    <?php else: ?>
                      <span style="color:var(--sand);font-size:0.78rem;">Accept to view</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button onclick="toggleMapRow('map-row-<?= $pb['booking_id'] ?>', '<?= htmlspecialchars($pb['address'], ENT_QUOTES) ?>')" style="background:var(--cream);border:1.5px solid var(--border);padding:4px 10px;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;color:var(--ember);">📍 Map</button>
                  </td>
                  <td>
                    <?php if ($pb['status'] === 'accepted'): ?>
                    <form action="update_booking.php" method="POST" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= $pb['booking_id'] ?>">
                      <input type="hidden" name="action" value="complete">
                      <button type="submit" style="background:var(--bark);color:var(--cream);border:none;padding:5px 12px;border-radius:4px;font-size:0.75rem;font-weight:700;cursor:pointer;letter-spacing:0.05em;text-transform:uppercase;">Mark Complete</button>
                    </form>
                    <?php else: ?> - <?php endif; ?>
                  </td>
                </tr>
                <tr id="map-row-<?= $pb['booking_id'] ?>" style="display:none;">
                  <td colspan="8" style="padding:0 14px 14px;">
                    <div style="font-size:0.82rem;color:var(--warm-mid);margin-bottom:6px;">📍 <?= htmlspecialchars($pb['address']) ?></div>
                    <div class="booking-map" id="map-other-<?= $pb['booking_id'] ?>" data-address="<?= htmlspecialchars($pb['address']) ?>"></div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div id="get-featured" class="dash-panel">
        <h2 class="dash-panel-title">Get Featured on QuickHire</h2>
        <p class="dash-panel-sub">Boost your profile to the homepage and get more customers.</p>

        <?php if ($providerInfo && $providerInfo['is_featured']): ?>
          <div class="profile-section" style="border-left:3px solid var(--ember);text-align:center;padding:48px 32px;">
            <span style="font-size:3rem;display:block;margin-bottom:16px;">⭐</span>
            <h3 style="font-size:1.4rem;margin-bottom:8px;">You're Featured!</h3>
            <p>Your profile is currently displayed on the QuickHire homepage. Customers can find you easily.</p>
            <?php if ($featuredRequest && $featuredRequest['expires_at']): ?>
              <p style="margin-top:12px;font-weight:600;color:var(--ember);">Expires: <?= date('j M Y', strtotime($featuredRequest['expires_at'])) ?></p>
            <?php endif; ?>
          </div>

        <?php elseif ($featuredRequest && $featuredRequest['request_status'] === 'pending' && $featuredRequest['payment_status'] === 'completed'): ?>
          <div class="profile-section" style="border-left:3px solid var(--hot);text-align:center;padding:48px 32px;">
            <span style="font-size:3rem;display:block;margin-bottom:16px;">⏳</span>
            <h3 style="font-size:1.4rem;margin-bottom:8px;">Request Under Review</h3>
            <p>Your featured listing request has been submitted and paid. The QuickHire admin team will review and approve it shortly.</p>
            <p style="margin-top:12px;font-size:0.85rem;color:var(--sand);">Submitted: <?= date('j M Y, g:i A', strtotime($featuredRequest['created_at'])) ?></p>
          </div>

        <?php elseif ($featuredRequest && $featuredRequest['request_status'] === 'pending' && $featuredRequest['payment_status'] === 'pending'): ?>
          <div class="profile-section" style="border-left:3px solid var(--hot);text-align:center;padding:48px 32px;">
            <span style="font-size:3rem;display:block;margin-bottom:16px;">💳</span>
            <h3 style="font-size:1.4rem;margin-bottom:8px;">Complete Your Payment</h3>
            <p>Your request is waiting for payment. Complete it to get featured.</p>
            <p style="margin:16px 0;font-family:'Sora',sans-serif;font-size:1.8rem;font-weight:800;color:var(--ember);">GH₵ <?= number_format($featuredRequest['fee'], 2) ?></p>
            <a href="pay_featured.php?request_id=<?= $featuredRequest['id'] ?>" class="btn btn-accent" style="max-width:300px;margin:0 auto;display:block;">Pay Now →</a>
          </div>

        <?php elseif ($featuredRequest && $featuredRequest['request_status'] === 'rejected'): ?>
          <div class="profile-section" style="margin-bottom:24px;">
            <div style="background:rgba(244,63,94,0.06);border:1px solid rgba(244,63,94,0.15);border-radius:10px;padding:16px;font-size:0.88rem;color:#be123c;margin-bottom:20px;">
              Your previous featured request was not approved. You can submit a new one below.
            </div>
          </div>
          <?php // Fall through to show the form ?>

        <?php endif; ?>

        <?php if (!($providerInfo && $providerInfo['is_featured']) && !($featuredRequest && $featuredRequest['request_status'] === 'pending')): ?>
          <div class="profile-section">
            <h3>Why Get Featured?</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin:20px 0 32px;">
              <div style="background:var(--cream);border-radius:10px;padding:24px;text-align:center;">
                <span style="font-size:2rem;display:block;margin-bottom:8px;">📈</span>
                <p style="font-weight:700;color:var(--bark);margin-bottom:4px;">More Visibility</p>
                <p style="font-size:0.82rem;color:var(--sand);">Your profile appears on the homepage</p>
              </div>
              <div style="background:var(--cream);border-radius:10px;padding:24px;text-align:center;">
                <span style="font-size:2rem;display:block;margin-bottom:8px;">👥</span>
                <p style="font-weight:700;color:var(--bark);margin-bottom:4px;">More Customers</p>
                <p style="font-size:0.82rem;color:var(--sand);">Featured providers get 5x more bookings</p>
              </div>
              <div style="background:var(--cream);border-radius:10px;padding:24px;text-align:center;">
                <span style="font-size:2rem;display:block;margin-bottom:8px;">⭐</span>
                <p style="font-weight:700;color:var(--bark);margin-bottom:4px;">Trust Badge</p>
                <p style="font-size:0.82rem;color:var(--sand);">Stand out with a featured badge</p>
              </div>
            </div>
          </div>

          <div class="profile-section">
            <h3>Choose a Plan</h3>
            <form action="request_featured.php" method="POST">
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin:20px 0;">

                <label style="background:var(--card-bg);border:2px solid var(--border);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:all 0.25s;" class="plan-option" onclick="selectPlan(this)">
                  <input type="radio" name="plan" value="7" style="display:none;">
                  <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Starter</p>
                  <p style="font-family:'Sora',sans-serif;font-size:2rem;font-weight:800;color:var(--bark);letter-spacing:-0.04em;">GH₵ 50</p>
                  <p style="font-size:0.82rem;color:var(--warm-mid);margin-top:4px;">7 days featured</p>
                </label>

                <label style="background:var(--card-bg);border:2px solid var(--ember);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:all 0.25s;position:relative;" class="plan-option selected" onclick="selectPlan(this)">
                  <input type="radio" name="plan" value="30" checked style="display:none;">
                  <span style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--ember);color:#fff;padding:3px 12px;border-radius:20px;font-size:0.65rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;">Best Value</span>
                  <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Standard</p>
                  <p style="font-family:'Sora',sans-serif;font-size:2rem;font-weight:800;color:var(--bark);letter-spacing:-0.04em;">GH₵ 150</p>
                  <p style="font-size:0.82rem;color:var(--warm-mid);margin-top:4px;">30 days featured</p>
                </label>

                <label style="background:var(--card-bg);border:2px solid var(--border);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:all 0.25s;" class="plan-option" onclick="selectPlan(this)">
                  <input type="radio" name="plan" value="90" style="display:none;">
                  <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Premium</p>
                  <p style="font-family:'Sora',sans-serif;font-size:2rem;font-weight:800;color:var(--bark);letter-spacing:-0.04em;">GH₵ 350</p>
                  <p style="font-size:0.82rem;color:var(--warm-mid);margin-top:4px;">90 days featured</p>
                </label>

              </div>
              <button type="submit" class="form-submit" style="max-width:300px;">Request Featured Listing →</button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <div id="get-verified" class="dash-panel">
        <h2 class="dash-panel-title">Get Verified</h2>
        <p class="dash-panel-sub">Earn a trust badge by verifying your identity and credentials.</p>

        <?php if ($providerInfo && $providerInfo['is_verified']): ?>
          <div class="profile-section" style="border-left:3px solid var(--ember);text-align:center;padding:48px 32px;">
            <span style="font-size:3rem;display:block;margin-bottom:16px;">✅</span>
            <h3 style="font-size:1.4rem;margin-bottom:8px;">You're Verified!</h3>
            <p>Your identity and credentials have been confirmed. Customers see a verified badge on your profile, building trust and credibility.</p>
          </div>

        <?php elseif (isset($verificationRequest) && $verificationRequest && $verificationRequest['status'] === 'pending'): ?>
          <div class="profile-section" style="border-left:3px solid var(--hot);text-align:center;padding:48px 32px;">
            <span style="font-size:3rem;display:block;margin-bottom:16px;">⏳</span>
            <h3 style="font-size:1.4rem;margin-bottom:8px;">Verification Under Review</h3>
            <p>Your documents have been submitted. Our team will review and verify your profile within 48 hours.</p>
            <p style="margin-top:12px;font-size:0.85rem;color:var(--sand);">Submitted: <?= date('j M Y, g:i A', strtotime($verificationRequest['created_at'])) ?></p>
          </div>

        <?php else: ?>
          <?php if (isset($verificationRequest) && $verificationRequest && $verificationRequest['status'] === 'rejected'): ?>
            <div style="background:rgba(244,63,94,0.06);border:1px solid rgba(244,63,94,0.15);border-radius:10px;padding:16px;font-size:0.88rem;color:#be123c;margin-bottom:24px;">
              <p style="font-weight:700;margin-bottom:4px;">Previous application was not approved</p>
              <?php if (!empty($verificationRequest['admin_notes'])): ?>
                <p>Reason: <?= htmlspecialchars($verificationRequest['admin_notes']) ?></p>
              <?php endif; ?>
              <p style="margin-top:4px;">You can submit a new application below.</p>
            </div>
          <?php endif; ?>

          <div class="profile-section">
            <h3>Why Get Verified?</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin:20px 0 32px;">
              <div style="background:var(--cream);border-radius:10px;padding:24px;text-align:center;">
                <span style="font-size:2rem;display:block;margin-bottom:8px;">🛡️</span>
                <p style="font-weight:700;color:var(--bark);margin-bottom:4px;">Build Trust</p>
                <p style="font-size:0.82rem;color:var(--sand);">Customers prefer verified providers</p>
              </div>
              <div style="background:var(--cream);border-radius:10px;padding:24px;text-align:center;">
                <span style="font-size:2rem;display:block;margin-bottom:8px;">📈</span>
                <p style="font-weight:700;color:var(--bark);margin-bottom:4px;">Higher Ranking</p>
                <p style="font-size:0.82rem;color:var(--sand);">Verified profiles appear higher in search</p>
              </div>
              <div style="background:var(--cream);border-radius:10px;padding:24px;text-align:center;">
                <span style="font-size:2rem;display:block;margin-bottom:8px;">✅</span>
                <p style="font-weight:700;color:var(--bark);margin-bottom:4px;">Trust Badge</p>
                <p style="font-size:0.82rem;color:var(--sand);">Green verified badge on your profile</p>
              </div>
            </div>
          </div>

          <div class="profile-section">
            <h3>Submit Verification Documents</h3>
            <p style="font-size:0.85rem;color:var(--sand);margin-bottom:24px;">Upload a valid ID and any professional certifications. All documents are reviewed by our team and kept confidential.</p>
            <form action="submit_verification.php" method="POST" enctype="multipart/form-data">

              <div class="profile-edit-grid">
                <div class="form-field">
                  <label>ID Type</label>
                  <select name="id_type" required>
                    <option value="" disabled selected>Select ID type…</option>
                    <option value="ghana_card">Ghana Card</option>
                    <option value="passport">Passport</option>
                    <option value="voters_id">Voter's ID</option>
                    <option value="drivers_license">Driver's License</option>
                    <option value="nhis">NHIS Card</option>
                  </select>
                </div>
                <div class="form-field">
                  <label>ID Number</label>
                  <input type="text" name="id_number" placeholder="e.g. GHA-XXXXXXXXX-X" required>
                </div>
              </div>

              <div class="form-field">
                <label>Upload ID Document (Photo/Scan)</label>
                <input type="file" name="id_document" accept="image/*,.pdf" required style="padding:12px;background:var(--cream);border:1.5px dashed var(--border);border-radius:8px;cursor:pointer;">
                <p style="font-size:0.75rem;color:var(--sand);margin-top:4px;">Accepted: JPG, PNG, PDF. Max 5MB.</p>
              </div>

              <div class="form-field">
                <label>Professional Certificate (Optional)</label>
                <input type="file" name="certificate" accept="image/*,.pdf" style="padding:12px;background:var(--cream);border:1.5px dashed var(--border);border-radius:8px;cursor:pointer;">
                <p style="font-size:0.75rem;color:var(--sand);margin-top:4px;">Trade certificate, license, diploma, etc.</p>
              </div>

              <div class="form-field">
                <label>Additional Notes (Optional)</label>
                <textarea name="notes" rows="3" placeholder="Any additional information to support your verification…"></textarea>
              </div>

              <button type="submit" class="form-submit" style="max-width:300px;">Submit for Verification →</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div id="support" class="dash-panel">
        <h2 class="dash-panel-title">Support</h2>

        <div class="profile-section">
          <h3>Contact QuickHire</h3>
          <p style="font-size:0.85rem;color:var(--sand);margin-bottom:16px;">Have a question, issue, or suggestion? Let us know.</p>
          <form action="submit_feedback.php" method="POST" novalidate style="display:flex;flex-direction:column;gap:14px;max-width:500px;">
            <input type="hidden" name="redirect_to" value="dashboard.php">
            <div class="form-field">
              <label>What is this about?</label>
              <select name="category">
                <option value="general">General Question</option>
                <option value="service_issue">Issue with a Service</option>
                <option value="provider_complaint">Issue with a Provider</option>
                <option value="payment_issue">Payment Problem</option>
                <option value="features">Feature Request</option>
                <option value="support">Account Help</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-field">
              <label>How would you rate your experience?</label>
              <select name="rating">
                <option value="5">⭐⭐⭐⭐⭐ - Excellent</option>
                <option value="4">⭐⭐⭐⭐ - Good</option>
                <option value="3" selected>⭐⭐⭐ - Average</option>
                <option value="2">⭐⭐ - Below Average</option>
                <option value="1">⭐ - Poor</option>
              </select>
            </div>
            <div class="form-field">
              <label>Describe your issue or message</label>
              <textarea name="message" placeholder="Tell us what you need help with…" required style="min-height:100px;"></textarea>
            </div>
            <button type="submit" class="form-submit" style="max-width:200px;">Send Message →</button>
          </form>
        </div>

        <?php
          $issueCategories = ['service_issue', 'provider_complaint', 'payment_issue', 'support'];
          $myIssues = array_filter($myFeedback, fn($f) => in_array($f['category'], $issueCategories));
          $myRatings = array_filter($myFeedback, fn($f) => !in_array($f['category'], $issueCategories));
        ?>

        <?php if (!empty($myIssues)): ?>
        <div class="profile-section" style="margin-top:20px;">
          <h3>My Support Tickets</h3>
          <?php foreach ($myIssues as $mf): ?>
          <div style="background:var(--cream);border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
              <span style="font-size:0.72rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;padding:2px 8px;border-radius:8px;background:rgba(249,115,22,0.1);color:#c2410c;"><?= ucfirst(str_replace('_', ' ', $mf['category'])) ?></span>
              <span style="font-size:0.75rem;color:var(--sand);"><?= date('j M Y', strtotime($mf['created_at'])) ?></span>
            </div>
            <p style="font-size:0.88rem;color:var(--bark);line-height:1.6;margin-bottom:8px;"><?= htmlspecialchars($mf['message']) ?></p>
            <?php if (!empty($mf['admin_reply'])): ?>
            <div style="background:rgba(13,148,136,0.04);border:1px solid rgba(13,148,136,0.12);border-radius:8px;padding:12px 14px;margin-top:10px;">
              <p style="font-size:0.68rem;font-weight:700;color:var(--ember);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">QuickHire Response</p>
              <p style="font-size:0.85rem;color:var(--bark);line-height:1.6;"><?= htmlspecialchars($mf['admin_reply']) ?></p>
            </div>
            <?php else: ?>
            <p style="font-size:0.78rem;color:#c2410c;font-style:italic;margin-top:6px;">⏳ Awaiting response from QuickHire</p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>

      <div id="profile" class="dash-panel">
        <h2 class="dash-panel-title">My Profile</h2>
        <p class="dash-panel-sub">Update your contact details and account preferences.</p>

        <div class="profile-section">
          <h3>Personal Information</h3>
          <form action="update_profile.php" method="POST" novalidate>
            <div class="profile-edit-grid">
              <div class="form-field">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
              </div>
              <div class="form-field">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
              </div>
              <div class="form-field">
                <label>Phone Number</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required>
              </div>
            </div>
            <button type="submit" class="form-submit" style="max-width:220px;margin-top:8px;">Save Changes →</button>
          </form>
        </div>

        <?php if (isProvider() && $providerInfo): ?>
        <div class="profile-section">
          <h3>Provider Profile</h3>
          <p style="font-size:0.85rem;color:var(--sand);margin-bottom:20px;">This information appears on your public provider page.</p>
          <form action="update_provider_profile.php" method="POST" novalidate>
            <div class="form-field">
              <label>Bio / About Me</label>
              <textarea name="bio" rows="4" style="min-height:120px;" placeholder="Tell customers about yourself, your experience, and what makes you stand out…"><?= htmlspecialchars($providerInfo['bio'] ?? '') ?></textarea>
            </div>
            <div class="profile-edit-grid">
              <div class="form-field">
                <label>Service Category</label>
                <input type="text" name="service_category" value="<?= htmlspecialchars($providerInfo['service_category'] ?? '') ?>" placeholder="e.g. Carpentry, Plumbing">
              </div>
              <div class="form-field">
                <label>Years of Experience</label>
                <input type="number" name="experience_years" value="<?= $providerInfo['experience_years'] ?? 0 ?>" min="0" max="50">
              </div>
              <div class="form-field">
                <label>Availability</label>
                <input type="text" name="availability" value="<?= htmlspecialchars($providerInfo['availability'] ?? '') ?>" placeholder="e.g. Mon - Fri, All week">
              </div>
              <div class="form-field">
                <label>Languages</label>
                <input type="text" name="languages" value="<?= htmlspecialchars($providerInfo['languages'] ?? 'English') ?>" placeholder="e.g. English, Twi, Ga">
              </div>
              <div class="form-field">
                <label>Avg Response Time</label>
                <input type="text" name="avg_response" value="<?= htmlspecialchars($providerInfo['avg_response'] ?? '') ?>" placeholder="e.g. 2hr, 30min, Same day">
              </div>
            </div>
            <button type="submit" class="form-submit" style="max-width:220px;margin-top:8px;">Update Provider Profile →</button>
          </form>
        </div>

        <div class="profile-section">
          <h3>My Services & Pricing</h3>
          <p style="font-size:0.85rem;color:var(--sand);margin-bottom:20px;">Add, edit, or remove the services you offer. These appear on your public profile.</p>

          <form action="manage_services.php" method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:24px;padding:20px;background:var(--cream);border-radius:10px;">
            <input type="hidden" name="action" value="add">
            <div class="form-field" style="margin-bottom:0;flex:1;min-width:180px;">
              <label>Service Name</label>
              <input type="text" name="service_name" placeholder="e.g. Full Room Design" required>
            </div>
            <div class="form-field" style="margin-bottom:0;flex:2;min-width:200px;">
              <label>Description</label>
              <input type="text" name="description" placeholder="Brief description of the service">
            </div>
            <div class="form-field" style="margin-bottom:0;width:120px;">
              <label>Price (GH₵)</label>
              <input type="number" name="price" placeholder="0.00" step="0.01" min="0" required>
            </div>
            <button type="submit" style="background:var(--ember);color:#fff;border:none;padding:13px 20px;border-radius:8px;font-weight:700;font-size:0.82rem;cursor:pointer;letter-spacing:0.04em;text-transform:uppercase;white-space:nowrap;">+ Add Service</button>
          </form>

          <?php if (!empty($myServices)): ?>
          <table class="bookings-table">
            <thead>
              <tr><th>Service</th><th>Description</th><th>Price</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($myServices as $svc): ?>
              <tr>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($svc['service_name']) ?></td>
                <td style="font-size:0.82rem;color:var(--warm-mid);"><?= htmlspecialchars($svc['description'] ?? '') ?></td>
                <td style="font-weight:700;">GH₵ <?= number_format($svc['price'], 2) ?></td>
                <td style="white-space:nowrap;">
                  <button class="toggle-btn toggle-off" onclick="toggleEditService(<?= $svc['service_id'] ?>)" style="font-size:0.7rem;">Edit</button>
                  <form action="manage_services.php" method="POST" style="display:inline;" onsubmit="return confirm('Delete this service?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="service_id" value="<?= $svc['service_id'] ?>">
                    <button type="submit" style="background:#991b1b;color:#fff;border:none;padding:4px 10px;border-radius:4px;font-size:0.7rem;font-weight:700;cursor:pointer;">Delete</button>
                  </form>
                </td>
              </tr>
              <tr id="edit-svc-<?= $svc['service_id'] ?>" style="display:none;background:var(--cream);">
                <td colspan="4">
                  <form action="manage_services.php" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="service_id" value="<?= $svc['service_id'] ?>">
                    <input type="text" name="service_name" value="<?= htmlspecialchars($svc['service_name']) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;width:150px;">
                    <input type="text" name="description" value="<?= htmlspecialchars($svc['description'] ?? '') ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;flex:1;min-width:180px;">
                    <input type="number" name="price" value="<?= $svc['price'] ?>" step="0.01" min="0" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;width:100px;">
                    <button type="submit" style="background:var(--ember);color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:0.78rem;font-weight:700;cursor:pointer;">Save</button>
                    <button type="button" onclick="toggleEditService(<?= $svc['service_id'] ?>)" style="background:var(--cream);border:1.5px solid var(--border);padding:8px 16px;border-radius:6px;font-size:0.78rem;font-weight:700;cursor:pointer;">Cancel</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <p style="color:var(--sand);font-size:0.88rem;">No services added yet. Add your first service above - it'll appear on your public profile.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="profile-section">
          <h3>Change Password</h3>
          <form action="update_password.php" method="POST" novalidate>
            <div class="profile-edit-grid">
              <div class="form-field">
                <label>Current Password</label>
                <div class="pw-wrap">
                  <input type="password" name="current_password" placeholder="Current password">
                  <button type="button" class="pw-toggle" onclick="togglePw(this)">👁</button>
                </div>
              </div>
              <div class="form-field">
                <label>New Password</label>
                <div class="pw-wrap">
                  <input type="password" name="new_password" placeholder="New password">
                  <button type="button" class="pw-toggle" onclick="togglePw(this)">👁</button>
                </div>
              </div>
              <div class="form-field full-width">
                <label>Confirm New Password</label>
                <div class="pw-wrap">
                  <input type="password" name="confirm_password" placeholder="Confirm new password">
                  <button type="button" class="pw-toggle" onclick="togglePw(this)">👁</button>
                </div>
              </div>
            </div>
            <button type="submit" class="form-submit" style="max-width:220px;margin-top:8px;">Update Password →</button>
          </form>
        </div>
      </div>

    </main>
  </div>

  <footer>
    <p><strong>QuickHire</strong> - Connecting Ghana, one job at a time. © 2026</p>
  </footer>

  <script>
    // Panel switching
    function clearSupportBadge() {
      var badge = document.getElementById('support-badge');
      if (badge) badge.style.display = 'none';
    }

    function showPanel(id, el) {
      document.querySelectorAll('.dash-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.dash-nav-item').forEach(n => n.classList.remove('active'));
      document.getElementById(id).classList.add('active');
      el.classList.add('active');
    }

    // Password eye toggle
    function togglePw(btn) {
      const input = btn.parentElement.querySelector('input');
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
      } else {
        input.type = 'password';
        btn.textContent = '👁';
      }
    }

    // Service edit toggle
    function toggleEditService(id) {
      const row = document.getElementById('edit-svc-' + id);
      row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }

    // Receipt show/hide
    function showReceipt(id) {
      document.querySelectorAll('[id^="receipt-"]').forEach(r => r.style.display = 'none');
      document.getElementById('receipt-' + id).style.display = 'block';
      document.getElementById('receipt-' + id).scrollIntoView({ behavior: 'smooth' });
    }

    // Print receipt
    function printReceipt(id) {
      document.querySelectorAll('.dash-panel').forEach(p => p.classList.remove('printing'));
      document.getElementById('payments').classList.add('printing');
      document.querySelectorAll('[id^="receipt-"]').forEach(r => r.style.display = 'none');
      document.getElementById('receipt-' + id).style.display = 'block';
      window.print();
    }

    // Featured plan selection
    function selectPlan(label) {
      document.querySelectorAll('.plan-option').forEach(o => {
        o.style.borderColor = 'var(--border)';
        o.classList.remove('selected');
      });
      label.style.borderColor = 'var(--ember)';
      label.classList.add('selected');
      label.querySelector('input[type="radio"]').checked = true;
    }
  </script>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    // Store initialized maps to avoid duplicates
    const initializedMaps = {};

    // Geocode address and show map
    function initMap(mapId, address) {
      if (initializedMaps[mapId]) return;

      const mapEl = document.getElementById(mapId);
      if (!mapEl || mapEl.offsetParent === null) return; // not visible

      // Default to Accra center
      const defaultLat = 5.6037;
      const defaultLng = -0.1870;

      const map = L.map(mapId).setView([defaultLat, defaultLng], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 18,
      }).addTo(map);

      initializedMaps[mapId] = map;

      // Geocode the address using Nominatim
      const query = encodeURIComponent(address + ', Ghana');
      fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + query + '&limit=1')
        .then(res => res.json())
        .then(data => {
          if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);
            map.setView([lat, lng], 15);
            L.marker([lat, lng]).addTo(map)
              .bindPopup('<strong>📍 Customer Location</strong><br>' + address)
              .openPopup();
          } else {
            // Fallback: show Accra with a note
            L.marker([defaultLat, defaultLng]).addTo(map)
              .bindPopup('<strong>📍 Address:</strong> ' + address + '<br><em style="color:#94a3b8;">Exact location not found - showing Accra center</em>')
              .openPopup();
          }
        })
        .catch(() => {
          L.marker([defaultLat, defaultLng]).addTo(map)
            .bindPopup('<strong>📍 ' + address + '</strong>')
            .openPopup();
        });
    }

    // Toggle map row in table and init map
    function toggleMapRow(rowId, address) {
      const row = document.getElementById(rowId);
      if (row.style.display === 'none') {
        row.style.display = 'table-row';
        // Find the map div inside this row
        const mapDiv = row.querySelector('.booking-map');
        if (mapDiv) {
          setTimeout(() => initMap(mapDiv.id, address), 100);
        }
      } else {
        row.style.display = 'none';
      }
    }

    // Initialize all visible maps on the Manage Bookings panel
    function initVisibleMaps() {
      document.querySelectorAll('.booking-map').forEach(el => {
        if (el.offsetParent !== null && el.dataset.address) {
          initMap(el.id, el.dataset.address);
        }
      });
    }

    // Re-init maps when switching to the provider-bookings panel
    const origShowPanel = showPanel;
    showPanel = function(id, el) {
      origShowPanel(id, el);
      if (id === 'provider-bookings' || id === 'tracking') {
        setTimeout(initVisibleMaps, 200);
      }
    };

    // Init maps on page load if already on the panel
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(initVisibleMaps, 300);
    });

    // Set greeting based on user's local time
    (function() {
      var h = new Date().getHours();
      var g = h < 12 ? 'Good morning' : (h < 17 ? 'Good afternoon' : 'Good evening');
      document.getElementById('greeting-text').textContent = g;
    })();
  </script>

</body>
</html>