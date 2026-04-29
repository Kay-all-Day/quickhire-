<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

$provider_id = intval($_GET['id'] ?? 0);
if ($provider_id <= 0) { redirect('categories.php'); }

// Fetch provider info
$stmt = $pdo->prepare("
    SELECT sp.*, u.full_name, u.email, u.phone
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    WHERE sp.provider_id = ?
");
$stmt->execute([$provider_id]);
$p = $stmt->fetch();
if (!$p) { redirect('categories.php'); }

// Fetch services
$stmt = $pdo->prepare("SELECT * FROM services WHERE provider_id = ? ORDER BY price");
$stmt->execute([$provider_id]);
$services = $stmt->fetchAll();

// Fetch reviews (only customer→provider reviews, not provider→customer)
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS reviewer_name
    FROM reviews r 
    JOIN users u ON r.user_id = u.user_id
    JOIN bookings b ON r.booking_id = b.booking_id
    WHERE r.provider_id = ? AND r.user_id = b.user_id
    ORDER BY r.created_at DESC
");
$stmt->execute([$provider_id]);
$reviews = $stmt->fetchAll();

// Count jobs
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE provider_id = ?");
$stmt->execute([$provider_id]);
$jobCount = $stmt->fetch()['total'];

// Helpers
$initials = '';
foreach (explode(' ', $p['full_name']) as $part) $initials .= strtoupper(substr($part, 0, 1));
$firstName = explode(' ', $p['full_name'])[0];
$fullStars = floor($p['rating']);
$stars = str_repeat('★', $fullStars) . str_repeat('☆', 5 - $fullStars);
// Satisfaction rate: blends % of positive reviews (4+) with average rating (customer→provider only)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total, SUM(r.rating >= 4) as positive, AVG(r.rating) as avg_rating 
    FROM reviews r
    JOIN bookings b ON r.booking_id = b.booking_id
    WHERE r.provider_id = ? AND r.user_id = b.user_id
");
$stmt->execute([$provider_id]);
$satData = $stmt->fetch();
if ($satData['total'] > 0) {
    $positiveRate = ($satData['positive'] / $satData['total']) * 100;
    $ratingRate   = ($satData['avg_rating'] / 5) * 100;
    $satisfaction  = round(($positiveRate * 0.6) + ($ratingRate * 0.4)) . '%';
} else {
    $satisfaction = 'N/A';
}
$avgResponse = $p['avg_response'] ?? 'Not set';
$languages = $p['languages'] ?? 'English';

// Availability / slots-left
$todayBooked = 0;
$slotsLeft   = null;
$cap = (int)($p['daily_booking_cap'] ?? 0);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE provider_id = ? AND DATE(booking_date) = CURDATE() AND status != 'cancelled'");
$stmt->execute([$provider_id]);
$todayBooked = (int)$stmt->fetchColumn();
if ($cap > 0) {
    $slotsLeft = max(0, $cap - $todayBooked);
}

// Tags from service names
$tags = array_map(fn($s) => $s['service_name'], $services);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($p['full_name']) ?> — <?= htmlspecialchars($p['service_category']) ?> · QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>a.nav-brand { text-decoration: none; }</style>
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

  <div class="profile-layout">

    <!-- ── Sidebar ── -->
    <aside class="profile-sidebar">
      <div class="profile-card">
        <div class="profile-avatar-lg"><?= $initials ?></div>
        <h2><?= htmlspecialchars($p['full_name']) ?></h2>
        <p class="provider-role"><?= htmlspecialchars($p['service_category']) ?></p>
        <div class="profile-rating-row">
          <span class="stars"><?= $stars ?></span>
          <span><?= number_format($p['rating'], 1) ?> · <?= count($reviews) ?> reviews</span>
        </div>
        <div class="profile-stats-row">
          <div class="profile-stat-box"><span class="num"><?= $jobCount ?></span><span class="lbl">Jobs done</span></div>
          <div class="profile-stat-box"><span class="num"><?= $p['experience_years'] ?>yr</span><span class="lbl">Experience</span></div>
          <div class="profile-stat-box"><span class="num"><?= $satisfaction ?></span><span class="lbl">Satisfaction</span></div>
          <div class="profile-stat-box"><span class="num"><?= htmlspecialchars($avgResponse) ?></span><span class="lbl">Avg response</span></div>
        </div>
        <?php if (!$p['is_verified']): ?>
        <div style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);border-radius:10px;padding:14px;text-align:center;">
          <p style="font-size:0.82rem;color:#c2410c;font-weight:600;">⚠ This provider is not yet verified</p>
          <p style="font-size:0.75rem;color:var(--sand);margin-top:4px;">Unverified providers cannot accept bookings</p>
        </div>
        <?php elseif (!$p['is_available']): ?>
        <div style="background:rgba(156,163,175,0.1);border:1px solid rgba(156,163,175,0.3);border-radius:10px;padding:14px;text-align:center;">
          <p style="font-size:0.82rem;color:var(--warm-mid);font-weight:600;">🔴 Not accepting bookings</p>
          <p style="font-size:0.75rem;color:var(--sand);margin-top:4px;">This provider is currently unavailable</p>
        </div>
        <?php elseif ($slotsLeft !== null && $slotsLeft === 0): ?>
        <div style="background:rgba(249,115,22,0.06);border:1px solid rgba(249,115,22,0.18);border-radius:10px;padding:14px;text-align:center;">
          <p style="font-size:0.82rem;color:#c2410c;font-weight:600;">📅 Fully booked today</p>
          <p style="font-size:0.75rem;color:var(--sand);margin-top:4px;">Check back tomorrow for availability</p>
        </div>
        <?php else: ?>
        <?php if ($slotsLeft !== null): ?>
        <div style="text-align:center;margin-bottom:10px;padding:8px 14px;background:rgba(5,150,105,0.08);border:1px solid rgba(5,150,105,0.2);border-radius:8px;">
          <p style="font-size:0.78rem;font-weight:700;color:#065f46;">🟢 <?= $slotsLeft ?> slot<?= $slotsLeft !== 1 ? 's' : '' ?> left today</p>
        </div>
        <?php endif; ?>
        <?php if (isLoggedIn()): ?>
        <button class="profile-hire-btn" onclick="window.location='booking.php?provider=<?= $provider_id ?>'">Book <?= htmlspecialchars($firstName) ?> →</button>
        <?php else: ?>
        <button class="profile-hire-btn" onclick="window.location='auth.php?redirect_to=<?= urlencode('booking.php?provider=' . $provider_id) ?>'">Book <?= htmlspecialchars($firstName) ?> →</button>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (isLoggedIn() && getUserId() != $p['user_id']): ?>
        <a href="messages.php?with=<?= $p['user_id'] ?>" style="display:block;text-align:center;padding:12px;background:var(--bark);color:#fff;border-radius:10px;font-size:0.82rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;text-decoration:none;margin-top:10px;transition:all 0.2s;">💬 Message <?= htmlspecialchars($firstName) ?></a>
        <?php endif; ?>
      </div>

      <div class="profile-info-card">
        <h4>Details</h4>
        <div class="profile-info-row"><span>Location</span><span>Accra, Ghana</span></div>
        <div class="profile-info-row"><span>Category</span><span><?= htmlspecialchars($p['service_category']) ?></span></div>
        <div class="profile-info-row"><span>Experience</span><span><?= $p['experience_years'] ?> Years</span></div>
        <div class="profile-info-row"><span>Languages</span><span><?= htmlspecialchars($languages) ?></span></div>
        <div class="profile-info-row"><span>Availability</span><span><?= htmlspecialchars($p['availability'] ?? 'Mon – Fri') ?></span></div>
        <?php if ($cap > 0): ?>
        <div class="profile-info-row"><span>Today's slots</span><span style="font-weight:700;color:<?= $slotsLeft > 0 ? '#065f46' : '#c2410c' ?>;"><?= $slotsLeft ?> / <?= $cap ?> remaining</span></div>
        <?php endif; ?>
        <div class="profile-info-row"><span>Member since</span><span><?= date('M Y', strtotime($p['joined_at'])) ?></span></div>
      </div>
    </aside>

    <!-- ── Main Content ── -->
    <main class="profile-main">

      <div class="profile-section">
        <h3>About</h3>
        <p><?= htmlspecialchars($p['bio'] ?? 'No bio provided.') ?></p>
        <?php if (!empty($tags)): ?>
        <div class="service-tags">
          <?php foreach ($tags as $tag): ?>
            <span class="service-tag"><?= htmlspecialchars($tag) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="profile-section">
        <h3>Pricing</h3>
        <?php if (empty($services)): ?>
          <p>No services listed yet.</p>
        <?php else: ?>
        <div class="pricing-grid">
          <?php foreach ($services as $s): ?>
          <div class="pricing-box">
            <div class="price">GH₵ <?= number_format($s['price'], 0) ?></div>
            <div class="price-label"><?= htmlspecialchars($s['service_name']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="profile-section">
        <h3>Customer Reviews</h3>
        <?php if (empty($reviews)): ?>
          <p>No reviews yet.</p>
        <?php else: ?>
        <div class="review-list">
          <?php foreach ($reviews as $r): 
            $rStars = str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']);
          ?>
          <div class="review-item">
            <div class="review-header">
              <span class="reviewer-name"><?= htmlspecialchars($r['reviewer_name']) ?></span>
              <span class="review-stars"><?= $rStars ?></span>
            </div>
            <p class="review-text"><?= htmlspecialchars($r['comment']) ?></p>
            <p class="review-date"><?= date('F Y', strtotime($r['created_at'])) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

</body>
</html>
