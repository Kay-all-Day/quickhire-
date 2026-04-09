<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Fetch all providers grouped by category
$stmt = $pdo->query("
    SELECT sp.provider_id, u.full_name, sp.service_category, sp.rating, sp.bio, sp.is_verified
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    ORDER BY sp.rating DESC
");
$allProviders = $stmt->fetchAll();

// Get job counts
$jobStmt = $pdo->query("
    SELECT provider_id, COUNT(*) as total FROM bookings GROUP BY provider_id
");
$jobCounts = [];
while ($row = $jobStmt->fetch()) {
    $jobCounts[$row['provider_id']] = $row['total'];
}

// Group by category
$categories = [];
foreach ($allProviders as $p) {
    $cat = strtolower(str_replace([' ', '&'], ['', ''], $p['service_category']));
    if (!isset($categories[$cat])) {
        $categories[$cat] = ['label' => $p['service_category'], 'providers' => []];
    }
    $categories[$cat]['providers'][] = $p;
}

// Icons for categories
$catIcons = [
    'plumbing' => '🔧', 'electrical' => '⚡', 'tutoring' => '📚', 'cleaning' => '🧹',
    'technical' => '💻', 'technicalsupport' => '💻', 'interiordesign' => '🎨', 
    'carpentry' => '🪚', 'catering' => '🎉', 'cateringevents' => '🎉',
    'security' => '🔒', 'events' => '🎉', 'landscaping' => '🌿', 'painting' => '🖌️',
];

function getInitials($name) {
    $parts = explode(' ', $name);
    $i = '';
    foreach ($parts as $p) $i .= strtoupper(substr($p, 0, 1));
    return $i;
}

function starDisplay($rating) {
    $full = floor($rating);
    $half = ($rating - $full) >= 0.5 ? 1 : 0;
    return str_repeat('★', $full) . ($half ? '☆' : '') . str_repeat('☆', 5 - $full - $half);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Service Categories — QuickHire</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand" style="text-decoration:none;">Quick<span>Hire</span></a>
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
    <p class="page-banner-eyebrow">Browse by category</p>
    <h1 id="banner-title">All Service Categories</h1>
    <p id="banner-sub">Find trusted professionals across every type of service — home, education, tech and more.</p>
  </div>

  <div class="cat-filter-bar">
    <button data-filter="all" class="active">All</button>
    <?php foreach ($categories as $key => $cat): ?>
      <button data-filter="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($cat['label']) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <?php foreach ($categories as $key => $cat): ?>
    <div class="cat-section" data-category="<?= htmlspecialchars($key) ?>">
      <div class="cat-section-title">
        <span class="cat-icon"><?= $catIcons[$key] ?? '📦' ?></span>
        <h2><?= htmlspecialchars($cat['label']) ?></h2>
        <span class="cat-count"><?= count($cat['providers']) ?> provider<?= count($cat['providers']) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="provider-list">
        <?php foreach ($cat['providers'] as $p): 
          $initials = getInitials($p['full_name']);
          $jobs = $jobCounts[$p['provider_id']] ?? 0;
          $stars = starDisplay($p['rating']);
        ?>
        <a href="provider.php?id=<?= $p['provider_id'] ?>" class="provider-list-card" <?= !$p['is_verified'] ? 'style="opacity:0.7;"' : '' ?>>
          <div class="plc-avatar"><?= $initials ?></div>
          <div class="plc-info">
            <h4><?= htmlspecialchars($p['full_name']) ?> <?= $p['is_verified'] ? '<span style="color:var(--ember);font-size:0.8rem;" title="Verified">✓</span>' : '' ?></h4>
            <p class="plc-role"><?= htmlspecialchars($p['service_category']) ?></p>
            <div class="plc-meta">
              <span class="plc-stars"><?= $stars ?></span>
              <span><?= number_format($p['rating'], 1) ?> · <?= $jobs ?> jobs</span>
              <?php if (!$p['is_verified']): ?>
                <span style="color:#c2410c;font-weight:600;">· Not verified</span>
              <?php endif; ?>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

  <script>
    const sections   = document.querySelectorAll('.cat-section');
    const filterBtns = document.querySelectorAll('.cat-filter-bar button');
    const bannerTitle = document.getElementById('banner-title');
    const bannerSub   = document.getElementById('banner-sub');

    function applyFilter(filter) {
      sections.forEach(sec => {
        const match = filter === 'all' || sec.dataset.category === filter;
        sec.style.display = match ? 'block' : 'none';
      });
      filterBtns.forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === filter);
      });
      if (filter === 'all') {
        bannerTitle.textContent = 'All Service Categories';
        bannerSub.textContent = 'Find trusted professionals across every type of service — home, education, tech and more.';
      }
    }

    // Check URL params
    const params = new URLSearchParams(window.location.search);
    const serviceParam = params.get('service') || params.get('q') || params.get('view') || 'all';
    applyFilter(serviceParam.toLowerCase().replace(/[\s&]/g, ''));

    filterBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const f = btn.dataset.filter;
        history.pushState({}, '', f === 'all' ? 'categories.php' : `categories.php?service=${f}`);
        applyFilter(f);
      });
    });
  </script>

</body>
</html>
