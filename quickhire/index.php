<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Fetch featured providers
$stmt = $pdo->query("
    SELECT sp.provider_id, u.full_name, sp.service_category, sp.rating, sp.bio, 
           sp.is_verified, sp.is_featured, sp.experience_years
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    WHERE sp.is_featured = 1 AND sp.is_verified = 1
    ORDER BY sp.rating DESC
    LIMIT 6
");
$featured = $stmt->fetchAll();

// Fetch homepage categories
$stmt = $pdo->query("SELECT * FROM homepage_categories WHERE is_visible = 1 ORDER BY display_order ASC");
$homepageCategories = $stmt->fetchAll();

function getJobCount($pdo, $provider_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE provider_id = ?");
    $stmt->execute([$provider_id]);
    return $stmt->fetch()['total'];
}

function getInitials($name) {
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $p) $initials .= strtoupper(substr($p, 0, 1));
    return $initials;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QuickHire — Find Service Professionals Fast</title>
  <link rel="stylesheet" href="style.css">
  <style>a.nav-brand { text-decoration: none; }</style>
</head>
<body>

  <!-- ── Header ── -->
  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <?php if (isLoggedIn()): ?>
          <?php if (isAdmin()): ?><a href="admin.php">Admin</a><?php endif; ?>
          <a href="dashboard.php">Dashboard<?= getNavNotifBadge($pdo) ?></a>
          <a href="logout.php">Logout</a>
        <?php else: ?>
          <a href="auth.php">Login</a>
          <a href="auth.php?tab=register" class="cta">Register</a>
        <?php endif; ?>
      </div>
    </nav>
  </header>

  <!-- ── Hero ── -->
  <section class="hero">
    <div class="hero-text">
      <p class="hero-eyebrow">The service marketplace</p>
      <h1>Find &amp; Hire <em>Professionals</em> in Minutes</h1>
      <p class="hero-sub">Search for plumbers, tutors, technicians and more — trusted, vetted, ready to help.</p>
    </div>
    <div class="hero-search">
      <p class="hero-search-label">What do you need done?</p>
      <form action="categories.php" method="GET" class="search-box">
        <input type="text" name="q" placeholder="e.g. Plumber, Math Tutor, Electrician…" aria-label="Search services">
        <button type="submit">Find Professionals →</button>
      </form>
    </div>
  </section>

  <!-- ── Category strip ── -->
  <div class="divider-strip" aria-hidden="true">
    <span class="hi">Popular →</span>
    <span>Plumbing</span>
    <span>Electrical</span>
    <span>Tutoring</span>
    <span>Cleaning</span>
    <span>Carpentry</span>
    <span>Landscaping</span>
    <span>Painting</span>
    <span>Security</span>
    <span>Catering</span>
  </div>

  <!-- ── Popular Services (from database) ── -->
  <section id="services">
    <div class="section">
      <div class="section-header">
        <h2>Popular Services</h2>
        <span class="count"><?= count($homepageCategories) ?> categories</span>
      </div>
      <div class="cards">
        <?php foreach ($homepageCategories as $cat): ?>
        <div class="card">
          <span class="card-icon"><?= htmlspecialchars($cat['icon']) ?></span>
          <h3><?= htmlspecialchars($cat['name']) ?></h3>
          <p><?= htmlspecialchars($cat['description']) ?></p>
          <button class="btn btn-primary" type="button" onclick="window.location='categories.php?service=<?= urlencode($cat['filter_key']) ?>'">View Providers</button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── Featured Service Providers (from database) ── -->
  <section id="featured">
    <div class="section">
      <div class="section-header">
        <h2>Featured Providers</h2>
        <span class="count">Hand-picked</span>
      </div>
      <div class="featured-grid">
        <?php foreach ($featured as $p): 
          $initials = getInitials($p['full_name']);
          $jobs = getJobCount($pdo, $p['provider_id']);
          $firstName = explode(' ', $p['full_name'])[0];
        ?>
        <div class="featured-card">
          <div class="featured-badge">⭐ Featured</div>
          <div class="featured-avatar"><?= $initials ?></div>
          <h3><?= htmlspecialchars($p['full_name']) ?></h3>
          <p class="provider-role"><?= htmlspecialchars($p['service_category']) ?></p>
          <p class="featured-bio"><?= htmlspecialchars($p['bio'] ?? '') ?></p>
          <div class="featured-stats">
            <div class="stat"><span class="stat-num"><?= number_format($p['rating'], 1) ?></span><span class="stat-label">Rating</span></div>
            <div class="stat"><span class="stat-num"><?= $jobs ?></span><span class="stat-label">Jobs</span></div>
            <div class="stat"><span class="stat-num"><?= $p['experience_years'] ?>yr</span><span class="stat-label">Experience</span></div>
          </div>
          <button class="btn btn-accent" type="button" onclick="window.location='provider.php?id=<?= $p['provider_id'] ?>'">Hire <?= htmlspecialchars($firstName) ?></button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ── How It Works ── -->
  <section class="how-it-works">
    <h2>How QuickHire Works</h2>
    <p class="section-sub">Three simple steps to get the help you need.</p>
    <div class="steps-grid">
      <div class="step-card">
        <div class="step-num">1</div>
        <h3>Search & Browse</h3>
        <p>Find verified professionals by category. Compare services and pricing at a glance.</p>
      </div>
      <div class="step-card">
        <div class="step-num">2</div>
        <h3>Book Instantly</h3>
        <p>Pick your date, time, and service. Your provider is notified immediately and confirms your booking.</p>
      </div>
      <div class="step-card">
        <div class="step-num">3</div>
        <h3>Pay & Review</h3>
        <p>Pay securely via Mobile Money, card, or cash after the job is done. Leave a review to help the community.</p>
      </div>
    </div>
  </section>

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
    </div>
  </footer>

</body>
</html>
