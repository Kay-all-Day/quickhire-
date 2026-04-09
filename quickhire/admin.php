<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireAdmin();

$errors = $_SESSION['errors'] ?? [];
$success = $_SESSION['success'] ?? '';
unset($_SESSION['errors'], $_SESSION['success']);

// ══════════ STATS ══════════
// Users
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$totalUsers = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type = 'customer'");
$totalCustomers = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type IN ('provider','both')");
$totalProviders = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE user_type = 'admin'");
$totalAdmins = $stmt->fetch()['total'];

// Bookings
$stmt = $pdo->query("SELECT COUNT(*) as total FROM bookings");
$totalBookings = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM bookings WHERE status = 'pending'");
$pendingBookings = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM bookings WHERE status = 'completed'");
$completedBookings = $stmt->fetch()['total'];

// Revenue
$commissionRate = 0.10; // 10% commission

$stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'completed'");
$totalRevenue = $stmt->fetch()['total'];
$platformCommission = $totalRevenue * $commissionRate;

$stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'pending'");
$pendingPayments = $stmt->fetch()['total'];

// Featured providers
$stmt = $pdo->query("SELECT COUNT(*) as total FROM service_providers WHERE is_featured = 1");
$featuredCount = $stmt->fetch()['total'];

// Featured listing revenue
$stmt = $pdo->query("SELECT COALESCE(SUM(fee), 0) as total FROM featured_requests WHERE payment_status = 'completed'");
$featuredRevenue = $stmt->fetch()['total'];

// ══════════ ALL USERS ══════════
// ══════════ HOMEPAGE CATEGORIES ══════════
$stmt = $pdo->query("SELECT * FROM homepage_categories ORDER BY display_order ASC");
$homepageCategories = $stmt->fetchAll();

// ══════════ FEATURED REQUESTS ══════════
$stmt = $pdo->query("
    SELECT fr.*, u.full_name, sp.service_category, sp.rating
    FROM featured_requests fr
    JOIN service_providers sp ON fr.provider_id = sp.provider_id
    JOIN users u ON sp.user_id = u.user_id
    ORDER BY fr.created_at DESC
");
$featuredRequests = $stmt->fetchAll();
$pendingFeaturedCount = 0;
foreach ($featuredRequests as $fr) {
    if ($fr['request_status'] === 'pending' && $fr['payment_status'] === 'completed') $pendingFeaturedCount++;
}

// ══════════ VERIFICATION REQUESTS ══════════
$stmt = $pdo->query("
    SELECT vr.*, u.full_name, u.email, sp.service_category, sp.rating, sp.experience_years
    FROM verification_requests vr
    JOIN service_providers sp ON vr.provider_id = sp.provider_id
    JOIN users u ON sp.user_id = u.user_id
    ORDER BY vr.created_at DESC
");
$verificationRequests = $stmt->fetchAll();
$pendingVerificationCount = 0;
foreach ($verificationRequests as $vr) {
    if ($vr['status'] === 'pending') $pendingVerificationCount++;
}

$stmt = $pdo->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM bookings WHERE user_id = u.user_id) as booking_count
    FROM users u 
    ORDER BY u.created_at DESC
");
$allUsers = $stmt->fetchAll();

// ══════════ ALL PROVIDERS ══════════
$stmt = $pdo->query("
    SELECT sp.*, u.full_name, u.email, u.phone,
           (SELECT COUNT(*) FROM bookings WHERE provider_id = sp.provider_id) as job_count,
           (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN bookings b ON p.booking_id = b.booking_id WHERE b.provider_id = sp.provider_id AND p.payment_status = 'completed') as total_earned
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    ORDER BY sp.rating DESC
");
$allProviders = $stmt->fetchAll();

// ══════════ RECENT BOOKINGS ══════════
$stmt = $pdo->query("
    SELECT b.*, s.service_name, s.price,
           u_cust.full_name AS customer_name,
           u_prov.full_name AS provider_name,
           p.payment_id, p.amount, p.payment_status, p.payment_method
    FROM bookings b
    JOIN users u_cust ON b.user_id = u_cust.user_id
    JOIN service_providers sp ON b.provider_id = sp.provider_id
    JOIN users u_prov ON sp.user_id = u_prov.user_id
    LEFT JOIN services s ON b.service_id = s.service_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    ORDER BY b.booking_date DESC
    LIMIT 20
");
$recentBookings = $stmt->fetchAll();

// ══════════ PROVIDER EARNINGS ══════════
$stmt = $pdo->query("
    SELECT u.full_name, sp.provider_id, sp.service_category, sp.rating, sp.is_featured, sp.is_verified,
           COUNT(b.booking_id) as total_jobs,
           COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) as gross_earned,
           COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount * $commissionRate ELSE 0 END), 0) as commission_paid,
           COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount * (1 - $commissionRate) ELSE 0 END), 0) as net_earned
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN bookings b ON b.provider_id = sp.provider_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    GROUP BY sp.provider_id
    ORDER BY gross_earned DESC
");
$providerEarnings = $stmt->fetchAll();

// Monthly revenue (last 6 months)
$stmt = $pdo->query("
    SELECT DATE_FORMAT(b.booking_date, '%Y-%m') as month,
           COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) as revenue,
           COUNT(b.booking_id) as bookings
    FROM bookings b
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    GROUP BY month
    ORDER BY month DESC
    LIMIT 6
");
$monthlyRevenue = array_reverse($stmt->fetchAll());

// ══════════ ANALYTICS DATA ══════════
// Bookings by status
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM bookings GROUP BY status");
$bookingsByStatus = $stmt->fetchAll();

// Top categories by bookings
$stmt = $pdo->query("
    SELECT sp.service_category, COUNT(b.booking_id) as total_bookings,
           COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) as revenue
    FROM service_providers sp
    LEFT JOIN bookings b ON b.provider_id = sp.provider_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    GROUP BY sp.service_category
    ORDER BY total_bookings DESC
    LIMIT 8
");
$topCategories = $stmt->fetchAll();

// User registrations by month
$stmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as new_users,
           SUM(user_type = 'customer') as customers,
           SUM(user_type IN ('provider','both')) as providers
    FROM users
    GROUP BY month ORDER BY month DESC LIMIT 6
");
$userGrowth = array_reverse($stmt->fetchAll());

// Peak booking hours
$stmt = $pdo->query("
    SELECT HOUR(booking_date) as hour, COUNT(*) as count
    FROM bookings
    GROUP BY hour ORDER BY hour
");
$peakHours = $stmt->fetchAll();

// Average rating
$stmt = $pdo->query("SELECT ROUND(AVG(rating), 1) as avg_rating, COUNT(*) as total FROM reviews");
$reviewStats = $stmt->fetch();

// Top providers by revenue
$stmt = $pdo->query("
    SELECT u.full_name, sp.service_category, sp.rating,
           COUNT(b.booking_id) as jobs,
           COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) as revenue
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN bookings b ON b.provider_id = sp.provider_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    GROUP BY sp.provider_id
    ORDER BY revenue DESC
    LIMIT 5
");
$topProvidersByRevenue = $stmt->fetchAll();

// Conversion rate: bookings completed / total
$conversionRate = $totalBookings > 0 ? round(($completedBookings / $totalBookings) * 100, 1) : 0;

// Average booking value
$stmt = $pdo->query("SELECT ROUND(AVG(amount), 2) as avg_value FROM payments WHERE payment_status = 'completed'");
$avgBookingValue = $stmt->fetch()['avg_value'] ?? 0;

// ══════════ PLATFORM FEEDBACK ══════════
$feedbackList = [];
$pendingFeedbackCount = 0;
$avgFeedbackRating = 0;
try {
    $stmt = $pdo->query("
        SELECT f.*, u.full_name 
        FROM platform_feedback f 
        JOIN users u ON f.user_id = u.user_id 
        ORDER BY f.created_at DESC
    ");
    $feedbackList = $stmt->fetchAll();
    foreach ($feedbackList as $fb) { if (!$fb['is_read']) $pendingFeedbackCount++; }

    $stmt = $pdo->query("SELECT ROUND(AVG(rating), 1) as avg FROM platform_feedback");
    $avgFeedbackRating = $stmt->fetch()['avg'] ?? 0;
} catch (Exception $e) {
    // Table may not exist yet — run sql_feedback_table.sql
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .admin-layout { display: grid; grid-template-columns: 260px 1fr; min-height: calc(100vh - var(--header-h)); }
    .admin-sidebar {
      background: var(--bark); padding: 36px 0; position: sticky;
      top: var(--header-h); height: calc(100vh - var(--header-h)); overflow-y: auto;
    }
    .admin-badge {
      margin: 0 24px 24px; padding: 12px 16px; background: rgba(196,92,26,0.15);
      border: 1px solid rgba(196,92,26,0.3); border-radius: 8px;
      text-align: center;
    }
    .admin-badge h3 { font-family: 'Sora', sans-serif; font-size: 1.1rem; color: var(--cream); font-weight: 900; }
    .admin-badge p { font-size: 0.72rem; color: var(--ember); font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; }
    .admin-nav { display: flex; flex-direction: column; }
    .admin-nav a {
      display: flex; align-items: center; gap: 10px; padding: 13px 24px;
      font-size: 0.83rem; font-weight: 600; color: rgba(245,240,232,0.5);
      cursor: pointer; transition: all 0.18s; border-left: 3px solid transparent; text-decoration: none;
    }
    .admin-nav a:hover { color: var(--cream); background: rgba(255,255,255,0.05); }
    .admin-nav a.active { color: var(--cream); background: rgba(196,92,26,0.12); border-left-color: var(--ember); }
    .admin-main { padding: 48px; background: var(--cream); }

    .admin-panel { display: none; }
    .admin-panel.active { display: block; }

    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 40px; }
    .stat-card {
      background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 22px 20px;
    }
    .stat-card .num {
      font-family: 'Sora', sans-serif; font-size: 2rem; font-weight: 900;
      color: var(--bark); letter-spacing: -0.05em; display: block;
    }
    .stat-card .lbl {
      font-size: 0.72rem; font-weight: 700; letter-spacing: 0.1em;
      text-transform: uppercase; color: var(--sand);
    }
    .stat-card.highlight { background: var(--bark); border-color: var(--bark); }
    .stat-card.highlight .num { color: var(--ember); }
    .stat-card.highlight .lbl { color: rgba(245,240,232,0.5); }

    .data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .data-table th {
      font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--sand); padding: 0 14px 12px; text-align: left; border-bottom: 1.5px solid var(--border);
    }
    .data-table td { padding: 14px; border-bottom: 1px solid var(--border); color: var(--warm-mid); vertical-align: middle; }
    .data-table tr:hover td { background: var(--parchment); }

    .toggle-btn {
      padding: 4px 12px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;
      letter-spacing: 0.05em; text-transform: uppercase; border: none; cursor: pointer; transition: all 0.2s;
    }
    .toggle-on { background: var(--ember); color: #fff; }
    .toggle-off { background: var(--parchment); color: var(--sand); border: 1px solid var(--border); }
    .toggle-btn:hover { opacity: 0.85; }

    .rev-bar-chart { display: flex; align-items: flex-end; gap: 12px; height: 200px; margin: 24px 0; padding: 0 10px; }
    .rev-bar {
      flex: 1; background: var(--ember); border-radius: 6px 6px 0 0; position: relative;
      display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
      min-width: 60px; transition: height 0.3s;
    }
    .rev-bar-label {
      position: absolute; bottom: -24px; font-size: 0.7rem; font-weight: 700;
      color: var(--sand); letter-spacing: 0.05em; white-space: nowrap;
    }
    .rev-bar-value {
      position: absolute; top: -22px; font-size: 0.72rem; font-weight: 700;
      color: var(--bark); white-space: nowrap;
    }

    .section-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 32px; margin-bottom: 24px;
    }
    .section-card h3 {
      font-family: 'Sora', sans-serif; font-size: 1.2rem; font-weight: 900;
      letter-spacing: -0.03em; margin-bottom: 20px; color: var(--bark);
    }

    @media (max-width: 1024px) {
      .admin-layout { grid-template-columns: 1fr; }
      .admin-sidebar {
        position: static; height: auto; display: flex; flex-wrap: wrap;
        padding: 16px; gap: 4px;
      }
      .admin-badge { margin: 0 0 8px; width: 100%; }
      .admin-nav { flex-direction: row; flex-wrap: wrap; }
      .admin-nav a { border-left: none; border-bottom: 2px solid transparent; padding: 10px 14px; border-radius: 6px; }
      .admin-nav a.active { border-left: none; border-bottom-color: var(--ember); }
      .admin-main { padding: 32px 24px; }
    }
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

  <div class="admin-layout">

    <aside class="admin-sidebar">
      <div class="admin-badge">
        <h3>QuickHire</h3>
        <p>Admin Panel</p>
      </div>
      <div class="admin-nav">
        <a class="active" onclick="showAdmin('overview', this)">📊 Overview</a>
        <a onclick="showAdmin('users', this)">👥 Users</a>
        <a onclick="showAdmin('providers', this)">🛠 Providers</a>
        <a onclick="showAdmin('bookings-admin', this)">📅 Bookings</a>
        <a onclick="showAdmin('revenue', this)">💰 Revenue</a>
        <a onclick="showAdmin('analytics', this)">📈 Analytics</a>
        <a onclick="showAdmin('featured', this)">⭐ Featured</a>
        <a onclick="showAdmin('featured-requests', this)">🌟 Requests<?= $pendingFeaturedCount > 0 ? " ($pendingFeaturedCount)" : '' ?></a>
        <a onclick="showAdmin('verification-admin', this)">✅ Verification<?= $pendingVerificationCount > 0 ? " ($pendingVerificationCount)" : '' ?></a>
        <a onclick="showAdmin('categories-admin', this)">📦 Categories</a>
        <a onclick="showAdmin('feedback-admin', this)">📝 Feedback<?= $pendingFeedbackCount > 0 ? " ($pendingFeedbackCount)" : '' ?></a>
      </div>
    </aside>

    <main class="admin-main">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:24px;"><?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if (!empty($success)): ?>
        <div class="alert alert-success" style="margin-bottom:24px;"><p><?= htmlspecialchars($success) ?></p></div>
      <?php endif; ?>

      <!-- ════ OVERVIEW ════ -->
      <div id="overview" class="admin-panel active">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Admin Dashboard</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Platform overview and key metrics.</p>

        <div class="stat-grid">
          <div class="stat-card"><span class="num"><?= $totalUsers ?></span><span class="lbl">Total Users</span></div>
          <div class="stat-card"><span class="num"><?= $totalCustomers ?></span><span class="lbl">Customers</span></div>
          <div class="stat-card"><span class="num"><?= $totalProviders ?></span><span class="lbl">Providers</span></div>
          <div class="stat-card"><span class="num"><?= $totalBookings ?></span><span class="lbl">Total Bookings</span></div>
          <div class="stat-card"><span class="num"><?= $pendingBookings ?></span><span class="lbl">Pending</span></div>
          <div class="stat-card"><span class="num"><?= $completedBookings ?></span><span class="lbl">Completed</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($totalRevenue, 0) ?></span><span class="lbl">Total Revenue</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($platformCommission, 0) ?></span><span class="lbl">Commission (<?= $commissionRate * 100 ?>%)</span></div>
          <div class="stat-card"><span class="num"><?= $featuredCount ?></span><span class="lbl">Featured Providers</span></div>
          <div class="stat-card"><span class="num">GH₵ <?= number_format($pendingPayments, 0) ?></span><span class="lbl">Pending Payments</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($featuredRevenue, 0) ?></span><span class="lbl">Featured Listing Revenue</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($platformCommission + $featuredRevenue, 0) ?></span><span class="lbl">Total QuickHire Income</span></div>
        </div>

        <?php if (!empty($monthlyRevenue)): ?>
        <div class="section-card">
          <h3>Monthly Revenue</h3>
          <div class="rev-bar-chart">
            <?php
            $maxRev = max(array_column($monthlyRevenue, 'revenue'));
            if ($maxRev == 0) $maxRev = 1;
            foreach ($monthlyRevenue as $m):
              $height = ($m['revenue'] / $maxRev) * 160 + 10;
            ?>
            <div class="rev-bar" style="height:<?= $height ?>px;">
              <span class="rev-bar-value">GH₵ <?= number_format($m['revenue'], 0) ?></span>
              <span class="rev-bar-label"><?= date('M', strtotime($m['month'] . '-01')) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ════ USERS ════ -->
      <div id="users" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">User Management</h2>
        <p style="color:var(--sand);margin-bottom:36px;"><?= $totalUsers ?> registered users. Click a row to edit.</p>

        <div class="section-card">
          <table class="data-table">
            <thead>
              <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Bookings</th><th>Joined</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($allUsers as $u): ?>
              <tr>
                <td>#<?= $u['user_id'] ?></td>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                <td><span class="status-badge status-<?= $u['user_type'] === 'admin' ? 'accepted' : ($u['user_type'] === 'provider' ? 'pending' : 'completed') ?>"><?= ucfirst($u['user_type']) ?></span></td>
                <td><?= $u['booking_count'] ?></td>
                <td><?= date('j M Y', strtotime($u['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                  <button class="toggle-btn toggle-off" onclick="toggleEditUser(<?= $u['user_id'] ?>)">Edit</button>
                  <form action="admin_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Reset password to password123?');">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                    <button type="submit" class="toggle-btn toggle-off" style="font-size:0.65rem;">Reset PW</button>
                  </form>
                  <?php if ($u['user_id'] !== getUserId()): ?>
                  <form action="admin_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Delete <?= htmlspecialchars($u['full_name']) ?>? This removes ALL their data.');">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                    <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;font-size:0.65rem;">Delete</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <!-- Edit row (hidden) -->
              <tr id="edit-user-<?= $u['user_id'] ?>" style="display:none;background:var(--parchment);">
                <td colspan="8">
                  <form action="admin_action.php" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                    <input type="text" name="full_name" value="<?= htmlspecialchars($u['full_name']) ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:150px;" placeholder="Name">
                    <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:180px;" placeholder="Email">
                    <input type="tel" name="phone" value="<?= htmlspecialchars($u['phone'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:130px;" placeholder="Phone">
                    <select name="user_type" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;">
                      <option value="customer" <?= $u['user_type'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                      <option value="provider" <?= $u['user_type'] === 'provider' ? 'selected' : '' ?>>Provider</option>
                      <option value="both" <?= $u['user_type'] === 'both' ? 'selected' : '' ?>>Both</option>
                      <option value="admin" <?= $u['user_type'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <button type="submit" class="toggle-btn toggle-on">Save</button>
                    <button type="button" class="toggle-btn toggle-off" onclick="toggleEditUser(<?= $u['user_id'] ?>)">Cancel</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ════ PROVIDERS ════ -->
      <div id="providers" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Provider Management</h2>
        <p style="color:var(--sand);margin-bottom:36px;"><?= count($allProviders) ?> service providers.</p>

        <div class="section-card">
          <table class="data-table">
            <thead>
              <tr><th>Provider</th><th>Category</th><th>Rating</th><th>Jobs</th><th>Earned</th><th>Verified</th><th>Featured</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($allProviders as $p): ?>
              <tr>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($p['full_name']) ?></td>
                <td><?= htmlspecialchars($p['service_category']) ?></td>
                <td>★ <?= number_format($p['rating'], 1) ?></td>
                <td><?= $p['job_count'] ?></td>
                <td>GH₵ <?= number_format($p['total_earned'], 0) ?></td>
                <td>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_verified">
                    <input type="hidden" name="provider_id" value="<?= $p['provider_id'] ?>">
                    <button type="submit" class="toggle-btn <?= $p['is_verified'] ? 'toggle-on' : 'toggle-off' ?>"><?= $p['is_verified'] ? '✓ Verified' : 'Verify' ?></button>
                  </form>
                </td>
                <td>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_featured">
                    <input type="hidden" name="provider_id" value="<?= $p['provider_id'] ?>">
                    <button type="submit" class="toggle-btn <?= $p['is_featured'] ? 'toggle-on' : 'toggle-off' ?>"><?= $p['is_featured'] ? '★ Featured' : 'Feature' ?></button>
                  </form>
                </td>
                <td>
                  <button class="toggle-btn toggle-off" onclick="toggleEditProvider(<?= $p['provider_id'] ?>)">Edit</button>
                </td>
              </tr>
              <!-- Edit row -->
              <tr id="edit-provider-<?= $p['provider_id'] ?>" style="display:none;background:var(--parchment);">
                <td colspan="8">
                  <form action="admin_action.php" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;">
                    <input type="hidden" name="action" value="update_provider">
                    <input type="hidden" name="provider_id" value="<?= $p['provider_id'] ?>">
                    <input type="text" name="service_category" value="<?= htmlspecialchars($p['service_category']) ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:140px;" placeholder="Category">
                    <input type="number" name="experience_years" value="<?= $p['experience_years'] ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:60px;" placeholder="Yrs" min="0">
                    <input type="text" name="availability" value="<?= htmlspecialchars($p['availability'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:120px;" placeholder="Availability">
                    <input type="text" name="languages" value="<?= htmlspecialchars($p['languages'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:140px;" placeholder="Languages">
                    <input type="text" name="avg_response" value="<?= htmlspecialchars($p['avg_response'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:80px;" placeholder="e.g. 2hr">
                    <input type="text" name="bio" value="<?= htmlspecialchars($p['bio'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;flex:1;min-width:200px;" placeholder="Bio">
                    <button type="submit" class="toggle-btn toggle-on">Save</button>
                    <button type="button" class="toggle-btn toggle-off" onclick="toggleEditProvider(<?= $p['provider_id'] ?>)">Cancel</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ════ BOOKINGS ════ -->
      <div id="bookings-admin" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">All Bookings</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Recent platform activity.</p>

        <div class="section-card">
          <table class="data-table">
            <thead>
              <tr><th>ID</th><th>Customer</th><th>Provider</th><th>Service</th><th>Date</th><th>Amount</th><th>Booking</th><th>Payment</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentBookings as $b): ?>
              <tr>
                <td>#<?= $b['booking_id'] ?></td>
                <td><?= htmlspecialchars($b['customer_name']) ?></td>
                <td><?= htmlspecialchars($b['provider_name']) ?></td>
                <td><?= htmlspecialchars($b['service_name'] ?? '—') ?></td>
                <td><?= date('j M Y', strtotime($b['booking_date'])) ?></td>
                <td>GH₵ <?= number_format($b['amount'] ?? $b['price'] ?? 0, 0) ?></td>
                <td>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="update_booking_status">
                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                    <select name="status" onchange="this.form.submit()" style="padding:4px 6px;border:1.5px solid var(--border);border-radius:4px;font-size:0.75rem;font-weight:700;background:var(--cream);">
                      <?php foreach (['pending','accepted','completed','cancelled'] as $s): ?>
                        <option value="<?= $s ?>" <?= $b['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td>
                  <?php if (!empty($b['payment_id'])): ?>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="update_payment">
                    <input type="hidden" name="payment_id" value="<?= $b['payment_id'] ?>">
                    <input type="hidden" name="payment_method" value="<?= $b['payment_method'] ?? 'mobile_money' ?>">
                    <select name="payment_status" onchange="this.form.submit()" style="padding:4px 6px;border:1.5px solid var(--border);border-radius:4px;font-size:0.75rem;font-weight:700;background:var(--cream);">
                      <option value="pending" <?= ($b['payment_status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="completed" <?= ($b['payment_status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                  </form>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <form action="admin_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Delete booking #<?= $b['booking_id'] ?>?');">
                    <input type="hidden" name="action" value="delete_booking">
                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                    <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;font-size:0.65rem;">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ════ REVENUE ════ -->
      <div id="revenue" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Revenue & Earnings</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Commission rate: <?= $commissionRate * 100 ?>% per transaction.</p>

        <div class="stat-grid">
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($totalRevenue, 2) ?></span><span class="lbl">Gross Revenue</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($platformCommission, 2) ?></span><span class="lbl">QuickHire Commission</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($featuredRevenue, 2) ?></span><span class="lbl">Featured Listings</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($platformCommission + $featuredRevenue, 2) ?></span><span class="lbl">Total QuickHire Income</span></div>
          <div class="stat-card"><span class="num">GH₵ <?= number_format($totalRevenue - $platformCommission, 2) ?></span><span class="lbl">Provider Payouts</span></div>
          <div class="stat-card"><span class="num">GH₵ <?= number_format($pendingPayments, 2) ?></span><span class="lbl">Pending Collection</span></div>
        </div>

        <div class="section-card">
          <h3>Provider Earnings Breakdown</h3>
          <table class="data-table">
            <thead>
              <tr><th>Provider</th><th>Category</th><th>Jobs</th><th>Gross Earned</th><th>Commission (<?= $commissionRate * 100 ?>%)</th><th>Net Payout</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($providerEarnings as $pe): ?>
              <tr>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($pe['full_name']) ?></td>
                <td><?= htmlspecialchars($pe['service_category']) ?></td>
                <td><?= $pe['total_jobs'] ?></td>
                <td>GH₵ <?= number_format($pe['gross_earned'], 2) ?></td>
                <td style="color:var(--ember);font-weight:600;">GH₵ <?= number_format($pe['commission_paid'], 2) ?></td>
                <td style="font-weight:700;">GH₵ <?= number_format($pe['net_earned'], 2) ?></td>
                <td>
                  <?php if ($pe['is_featured']): ?><span class="status-badge status-pending">★ Featured</span><?php endif; ?>
                  <?php if ($pe['is_verified']): ?><span class="status-badge status-accepted">✓ Verified</span><?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="section-card">
          <h3>Revenue Model</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;font-size:0.9rem;color:var(--warm-mid);">
            <div style="background:var(--parchment);padding:20px;border-radius:8px;">
              <p style="font-weight:700;color:var(--bark);margin-bottom:8px;">Transaction Commission</p>
              <p>QuickHire takes <?= $commissionRate * 100 ?>% of every completed payment as a service fee.</p>
              <p style="margin-top:8px;font-family:'Sora',sans-serif;font-size:1.3rem;font-weight:900;color:var(--ember);">GH₵ <?= number_format($platformCommission, 2) ?></p>
            </div>
            <div style="background:var(--parchment);padding:20px;border-radius:8px;">
              <p style="font-weight:700;color:var(--bark);margin-bottom:8px;">Featured Listings</p>
              <p><?= $featuredCount ?> providers currently featured. Revenue from listing fees adds directly to QuickHire income.</p>
              <p style="margin-top:8px;font-family:'Sora',sans-serif;font-size:1.3rem;font-weight:800;color:var(--ember);">GH₵ <?= number_format($featuredRevenue, 2) ?></p>
            </div>
          </div>
        </div>
      </div>

      <!-- ════ ANALYTICS ════ -->
      <div id="analytics" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">Business Analytics</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Data-driven insights to help you make better business decisions.</p>

        <!-- KPI Cards -->
        <div class="stat-grid">
          <div class="stat-card highlight"><span class="num"><?= $conversionRate ?>%</span><span class="lbl">Completion Rate</span></div>
          <div class="stat-card highlight"><span class="num">GH₵ <?= number_format($avgBookingValue, 0) ?></span><span class="lbl">Avg Booking Value</span></div>
          <div class="stat-card"><span class="num"><?= $reviewStats['avg_rating'] ?? '0.0' ?></span><span class="lbl">Avg Platform Rating</span></div>
          <div class="stat-card"><span class="num"><?= $reviewStats['total'] ?? 0 ?></span><span class="lbl">Total Reviews</span></div>
        </div>

        <!-- Bookings by Status (Donut Chart) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
          <div class="section-card">
            <h3>Bookings by Status</h3>
            <?php
            $statusColors = ['pending' => '#f97316', 'accepted' => '#0d9488', 'completed' => '#0f172a', 'cancelled' => '#f43f5e'];
            $statusLabels = ['pending' => 'Pending', 'accepted' => 'Accepted', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
            $totalB = array_sum(array_column($bookingsByStatus, 'count'));
            ?>
            <div style="display:flex;align-items:center;gap:32px;margin-top:16px;">
              <svg viewBox="0 0 120 120" width="140" height="140" style="flex-shrink:0;">
                <?php
                $offset = 0;
                $radius = 45;
                $circumference = 2 * M_PI * $radius;
                if ($totalB > 0):
                  foreach ($bookingsByStatus as $bs):
                    $pct = $bs['count'] / $totalB;
                    $dashLen = $pct * $circumference;
                    $dashGap = $circumference - $dashLen;
                    $color = $statusColors[$bs['status']] ?? '#94a3b8';
                ?>
                <circle cx="60" cy="60" r="<?= $radius ?>" fill="none" stroke="<?= $color ?>" stroke-width="20"
                  stroke-dasharray="<?= $dashLen ?> <?= $dashGap ?>" stroke-dashoffset="<?= -$offset ?>"
                  transform="rotate(-90 60 60)" style="transition:all 0.5s;"/>
                <?php
                    $offset += $dashLen;
                  endforeach;
                endif;
                ?>
                <text x="60" y="56" text-anchor="middle" style="font-family:'Sora',sans-serif;font-size:22px;font-weight:800;fill:var(--bark);"><?= $totalB ?></text>
                <text x="60" y="72" text-anchor="middle" style="font-size:9px;fill:var(--sand);font-weight:600;text-transform:uppercase;letter-spacing:0.1em;">Bookings</text>
              </svg>
              <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($bookingsByStatus as $bs): ?>
                <div style="display:flex;align-items:center;gap:8px;">
                  <span style="width:10px;height:10px;border-radius:3px;background:<?= $statusColors[$bs['status']] ?? '#94a3b8' ?>;flex-shrink:0;"></span>
                  <span style="font-size:0.82rem;color:var(--warm-mid);min-width:80px;"><?= $statusLabels[$bs['status']] ?? ucfirst($bs['status']) ?></span>
                  <span style="font-weight:700;font-size:0.88rem;color:var(--bark);"><?= $bs['count'] ?></span>
                  <span style="font-size:0.72rem;color:var(--sand);">(<?= $totalB > 0 ? round($bs['count'] / $totalB * 100) : 0 ?>%)</span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Top Categories -->
          <div class="section-card">
            <h3>Top Categories</h3>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px;">
              <?php
              $maxCatBookings = !empty($topCategories) ? max(array_column($topCategories, 'total_bookings')) : 1;
              if ($maxCatBookings == 0) $maxCatBookings = 1;
              foreach ($topCategories as $tc):
                $barWidth = ($tc['total_bookings'] / $maxCatBookings) * 100;
              ?>
              <div>
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                  <span style="font-size:0.82rem;font-weight:600;color:var(--bark);"><?= htmlspecialchars($tc['service_category']) ?></span>
                  <span style="font-size:0.78rem;color:var(--sand);"><?= $tc['total_bookings'] ?> bookings · GH₵ <?= number_format($tc['revenue'], 0) ?></span>
                </div>
                <div style="height:8px;background:var(--cream);border-radius:4px;overflow:hidden;">
                  <div style="height:100%;width:<?= $barWidth ?>%;background:linear-gradient(90deg, var(--ember), #06b6d4);border-radius:4px;transition:width 0.5s;"></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- User Growth Chart -->
        <div class="section-card" style="margin-bottom:24px;">
          <h3>User Growth (Last 6 Months)</h3>
          <?php if (!empty($userGrowth)): ?>
          <div style="display:flex;align-items:flex-end;gap:16px;height:180px;margin:24px 10px 30px;padding-bottom:4px;border-bottom:2px solid var(--border);">
            <?php
            $maxUsers = max(array_column($userGrowth, 'new_users'));
            if ($maxUsers == 0) $maxUsers = 1;
            foreach ($userGrowth as $ug):
              $totalH = ($ug['new_users'] / $maxUsers) * 150 + 10;
              $custH = $ug['new_users'] > 0 ? ($ug['customers'] / $ug['new_users']) * $totalH : 0;
              $provH = $totalH - $custH;
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;position:relative;">
              <span style="position:absolute;top:-20px;font-size:0.72rem;font-weight:700;color:var(--bark);"><?= $ug['new_users'] ?></span>
              <div style="width:100%;display:flex;flex-direction:column;gap:2px;">
                <div style="height:<?= $provH ?>px;background:var(--ember);border-radius:4px 4px 0 0;min-height:2px;"></div>
                <div style="height:<?= $custH ?>px;background:#06b6d4;border-radius:0 0 4px 4px;min-height:2px;"></div>
              </div>
              <span style="position:absolute;bottom:-22px;font-size:0.68rem;font-weight:700;color:var(--sand);"><?= date('M', strtotime($ug['month'] . '-01')) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:20px;justify-content:center;margin-top:12px;">
            <div style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:#06b6d4;"></span><span style="font-size:0.75rem;color:var(--sand);font-weight:600;">Customers</span></div>
            <div style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:var(--ember);"></span><span style="font-size:0.75rem;color:var(--sand);font-weight:600;">Providers</span></div>
          </div>
          <?php else: ?>
            <p style="color:var(--sand);margin-top:12px;">Not enough data yet.</p>
          <?php endif; ?>
        </div>

        <!-- Peak Hours & Top Providers -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

          <!-- Peak Booking Hours -->
          <div class="section-card">
            <h3>Peak Booking Hours</h3>
            <?php if (!empty($peakHours)): ?>
            <div style="display:flex;align-items:flex-end;gap:3px;height:120px;margin:20px 0 28px;padding-bottom:4px;border-bottom:1px solid var(--border);">
              <?php
              $maxHour = max(array_column($peakHours, 'count'));
              if ($maxHour == 0) $maxHour = 1;
              // Fill 24 hours
              $hourData = array_fill(0, 24, 0);
              foreach ($peakHours as $ph) $hourData[$ph['hour']] = $ph['count'];
              for ($h = 6; $h <= 22; $h++):
                $hVal = $hourData[$h];
                $barH = ($hVal / $maxHour) * 100 + 4;
                $isHot = $hVal == $maxHour && $hVal > 0;
              ?>
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;position:relative;">
                <?php if ($hVal > 0): ?>
                  <span style="position:absolute;top:-16px;font-size:0.6rem;font-weight:700;color:var(--bark);"><?= $hVal ?></span>
                <?php endif; ?>
                <div style="width:100%;height:<?= $barH ?>px;background:<?= $isHot ? 'var(--ember)' : 'rgba(13,148,136,0.3)' ?>;border-radius:3px 3px 0 0;transition:height 0.3s;"></div>
                <?php if ($h % 3 == 0): ?>
                  <span style="position:absolute;bottom:-18px;font-size:0.58rem;color:var(--sand);font-weight:600;"><?= $h ?>:00</span>
                <?php endif; ?>
              </div>
              <?php endfor; ?>
            </div>
            <p style="font-size:0.78rem;color:var(--sand);text-align:center;">Bookings tend to peak around
              <?php
              $peakH = 0; $peakV = 0;
              foreach ($peakHours as $ph) { if ($ph['count'] > $peakV) { $peakV = $ph['count']; $peakH = $ph['hour']; } }
              echo $peakH . ':00 - ' . ($peakH + 1) . ':00';
              ?>
            </p>
            <?php else: ?>
              <p style="color:var(--sand);margin-top:12px;">Not enough data yet.</p>
            <?php endif; ?>
          </div>

          <!-- Top Providers by Revenue -->
          <div class="section-card">
            <h3>Top Providers by Revenue</h3>
            <?php if (!empty($topProvidersByRevenue)): ?>
            <div style="margin-top:16px;">
              <?php foreach ($topProvidersByRevenue as $i => $tp): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:10px 0;<?= $i < count($topProvidersByRevenue) - 1 ? 'border-bottom:1px solid var(--border);' : '' ?>">
                <span style="width:26px;height:26px;border-radius:50%;background:<?= $i === 0 ? 'var(--ember)' : 'var(--cream)' ?>;color:<?= $i === 0 ? '#fff' : 'var(--bark)' ?>;font-size:0.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $i + 1 ?></span>
                <div style="flex:1;min-width:0;">
                  <p style="font-weight:700;font-size:0.88rem;color:var(--bark);"><?= htmlspecialchars($tp['full_name']) ?></p>
                  <p style="font-size:0.75rem;color:var(--sand);"><?= htmlspecialchars($tp['service_category']) ?> · ★ <?= number_format($tp['rating'], 1) ?> · <?= $tp['jobs'] ?> jobs</p>
                </div>
                <span style="font-family:'Sora',sans-serif;font-weight:800;font-size:0.95rem;color:var(--ember);">GH₵ <?= number_format($tp['revenue'], 0) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
              <p style="color:var(--sand);margin-top:12px;">No revenue data yet.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Revenue Trend -->
        <div class="section-card">
          <h3>Monthly Revenue Trend</h3>
          <?php if (!empty($monthlyRevenue)): ?>
          <div style="position:relative;margin:24px 10px 30px;">
            <!-- Y-axis labels -->
            <?php
            $maxMRev = max(array_column($monthlyRevenue, 'revenue'));
            if ($maxMRev == 0) $maxMRev = 1;
            ?>
            <div style="display:flex;align-items:flex-end;gap:12px;height:200px;padding-bottom:4px;border-bottom:2px solid var(--border);border-left:2px solid var(--border);">
              <?php foreach ($monthlyRevenue as $mi => $m):
                $barH = ($m['revenue'] / $maxMRev) * 170 + 10;
              ?>
              <div style="flex:1;display:flex;flex-direction:column;align-items:center;position:relative;">
                <span style="position:absolute;top:-22px;font-size:0.7rem;font-weight:700;color:var(--bark);">GH₵ <?= number_format($m['revenue'], 0) ?></span>
                <div style="width:80%;height:<?= $barH ?>px;background:linear-gradient(180deg, var(--ember), rgba(13,148,136,0.4));border-radius:6px 6px 0 0;position:relative;transition:height 0.5s;">
                  <span style="position:absolute;top:8px;left:50%;transform:translateX(-50%);font-size:0.65rem;color:#fff;font-weight:700;"><?= $m['bookings'] ?> jobs</span>
                </div>
                <span style="position:absolute;bottom:-22px;font-size:0.72rem;font-weight:700;color:var(--sand);"><?= date('M Y', strtotime($m['month'] . '-01')) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
            <p style="color:var(--sand);margin-top:12px;">Not enough data yet.</p>
          <?php endif; ?>
        </div>

        <!-- Business Insights -->
        <div class="section-card">
          <h3>Business Insights</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
            <div style="background:var(--cream);border-radius:10px;padding:20px;">
              <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Completion Rate</p>
              <p style="font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;color:<?= $conversionRate >= 70 ? 'var(--ember)' : '#f43f5e' ?>;"><?= $conversionRate ?>%</p>
              <p style="font-size:0.78rem;color:var(--warm-mid);margin-top:4px;"><?= $conversionRate >= 70 ? 'Healthy — most bookings get completed.' : 'Consider following up on pending bookings.' ?></p>
            </div>
            <div style="background:var(--cream);border-radius:10px;padding:20px;">
              <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Average Booking Value</p>
              <p style="font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;color:var(--ember);">GH₵ <?= number_format($avgBookingValue, 2) ?></p>
              <p style="font-size:0.78rem;color:var(--warm-mid);margin-top:4px;">Revenue per completed transaction.</p>
            </div>
            <div style="background:var(--cream);border-radius:10px;padding:20px;">
              <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Platform Rating</p>
              <p style="font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;color:var(--ember);">★ <?= $reviewStats['avg_rating'] ?? '0.0' ?> / 5</p>
              <p style="font-size:0.78rem;color:var(--warm-mid);margin-top:4px;">Based on <?= $reviewStats['total'] ?? 0 ?> reviews.</p>
            </div>
            <div style="background:var(--cream);border-radius:10px;padding:20px;">
              <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:8px;">Revenue per User</p>
              <p style="font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;color:var(--ember);">GH₵ <?= $totalUsers > 0 ? number_format($totalRevenue / $totalUsers, 2) : '0.00' ?></p>
              <p style="font-size:0.78rem;color:var(--warm-mid);margin-top:4px;">Average lifetime value across all users.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ════ FEATURED ════ -->
      <div id="featured" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Featured Providers</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Manage which providers appear on the homepage.</p>

        <div class="section-card">
          <h3>Currently Featured (<?= $featuredCount ?>)</h3>
          <?php $featuredProviders = array_filter($allProviders, fn($p) => $p['is_featured']); ?>
          <?php if (empty($featuredProviders)): ?>
            <p>No featured providers. Use the toggle below to feature providers.</p>
          <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px;">
            <?php foreach ($featuredProviders as $fp):
              $fpInitials = '';
              foreach (explode(' ', $fp['full_name']) as $part) $fpInitials .= strtoupper(substr($part, 0, 1));
            ?>
            <div style="background:var(--parchment);border:1px solid var(--border);border-radius:10px;padding:20px;display:flex;gap:14px;align-items:center;">
              <div style="width:46px;height:46px;border-radius:50%;background:var(--ember);color:#fff;font-family:'Sora',sans-serif;font-weight:700;font-size:0.95rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $fpInitials ?></div>
              <div>
                <p style="font-weight:700;color:var(--bark);"><?= htmlspecialchars($fp['full_name']) ?></p>
                <p style="font-size:0.8rem;color:var(--ember);font-weight:600;"><?= htmlspecialchars($fp['service_category']) ?> · ★ <?= number_format($fp['rating'], 1) ?></p>
              </div>
              <form action="admin_action.php" method="POST" style="margin-left:auto;">
                <input type="hidden" name="action" value="toggle_featured">
                <input type="hidden" name="provider_id" value="<?= $fp['provider_id'] ?>">
                <button type="submit" class="toggle-btn toggle-on">Remove ✗</button>
              </form>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <div class="section-card">
          <h3>All Providers</h3>
          <p style="color:var(--sand);font-size:0.88rem;margin-bottom:16px;">Click "Feature" to add a provider to the homepage.</p>
          <table class="data-table">
            <thead>
              <tr><th>Provider</th><th>Category</th><th>Rating</th><th>Jobs</th><th>Verified</th><th>Featured</th></tr>
            </thead>
            <tbody>
              <?php foreach ($allProviders as $p): ?>
              <tr>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($p['full_name']) ?></td>
                <td><?= htmlspecialchars($p['service_category']) ?></td>
                <td>★ <?= number_format($p['rating'], 1) ?></td>
                <td><?= $p['job_count'] ?></td>
                <td><?= $p['is_verified'] ? '✓' : '—' ?></td>
                <td>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_featured">
                    <input type="hidden" name="provider_id" value="<?= $p['provider_id'] ?>">
                    <button type="submit" class="toggle-btn <?= $p['is_featured'] ? 'toggle-on' : 'toggle-off' ?>">
                      <?= $p['is_featured'] ? '★ Featured' : 'Feature' ?>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ════ FEATURED REQUESTS ════ -->
      <div id="featured-requests" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">Featured Listing Requests</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Providers pay to be featured on the homepage. Review and approve their requests.</p>

        <?php
        $paidPending = array_filter($featuredRequests, fn($r) => $r['request_status'] === 'pending' && $r['payment_status'] === 'completed');
        $unpaidPending = array_filter($featuredRequests, fn($r) => $r['request_status'] === 'pending' && $r['payment_status'] === 'pending');
        $processed = array_filter($featuredRequests, fn($r) => in_array($r['request_status'], ['approved', 'rejected', 'expired']));
        ?>

        <?php if (!empty($paidPending)): ?>
        <div class="section-card" style="border-left:3px solid var(--ember);">
          <h3>⏳ Awaiting Approval (<?= count($paidPending) ?> paid)</h3>
          <?php foreach ($paidPending as $fr): ?>
          <div style="background:var(--cream);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
            <div>
              <p style="font-weight:700;color:var(--bark);"><?= htmlspecialchars($fr['full_name']) ?></p>
              <p style="font-size:0.82rem;color:var(--ember);font-weight:600;"><?= htmlspecialchars($fr['service_category']) ?> · ★ <?= number_format($fr['rating'], 1) ?></p>
              <p style="font-size:0.8rem;color:var(--sand);margin-top:4px;"><?= $fr['duration_days'] ?> days · GH₵ <?= number_format($fr['fee'], 2) ?> · Paid via <?= ucwords(str_replace('_', ' ', $fr['payment_method'] ?? 'N/A')) ?></p>
              <p style="font-size:0.75rem;color:var(--sand);">Requested: <?= date('j M Y, g:i A', strtotime($fr['created_at'])) ?></p>
            </div>
            <div style="display:flex;gap:8px;">
              <form action="admin_action.php" method="POST" style="display:inline;">
                <input type="hidden" name="action" value="approve_featured">
                <input type="hidden" name="request_id" value="<?= $fr['id'] ?>">
                <button type="submit" class="toggle-btn toggle-on" style="padding:8px 18px;">Approve ✓</button>
              </form>
              <form action="admin_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Reject this request? The provider has already paid.');">
                <input type="hidden" name="action" value="reject_featured">
                <input type="hidden" name="request_id" value="<?= $fr['id'] ?>">
                <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;padding:8px 18px;">Reject ✗</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($unpaidPending)): ?>
        <div class="section-card">
          <h3>💳 Awaiting Payment (<?= count($unpaidPending) ?>)</h3>
          <?php foreach ($unpaidPending as $fr): ?>
          <div style="background:var(--cream);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
            <div>
              <p style="font-weight:600;"><?= htmlspecialchars($fr['full_name']) ?> — <?= htmlspecialchars($fr['service_category']) ?></p>
              <p style="font-size:0.8rem;color:var(--sand);"><?= $fr['duration_days'] ?> days · GH₵ <?= number_format($fr['fee'], 2) ?> · Not yet paid</p>
            </div>
            <span class="status-badge status-pending">Unpaid</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($processed)): ?>
        <div class="section-card">
          <h3>History</h3>
          <table class="data-table">
            <thead>
              <tr><th>Provider</th><th>Category</th><th>Plan</th><th>Fee</th><th>Payment</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($processed as $fr): ?>
              <tr>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($fr['full_name']) ?></td>
                <td><?= htmlspecialchars($fr['service_category']) ?></td>
                <td><?= $fr['duration_days'] ?> days</td>
                <td>GH₵ <?= number_format($fr['fee'], 2) ?></td>
                <td><span class="status-badge status-<?= $fr['payment_status'] ?>"><?= ucfirst($fr['payment_status']) ?></span></td>
                <td><span class="status-badge status-<?= $fr['request_status'] === 'approved' ? 'accepted' : ($fr['request_status'] === 'rejected' ? 'cancelled' : 'pending') ?>"><?= ucfirst($fr['request_status']) ?></span></td>
                <td><?= date('j M Y', strtotime($fr['created_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if (empty($featuredRequests)): ?>
        <div class="section-card"><p>No featured listing requests yet. Providers can request from their dashboard.</p></div>
        <?php endif; ?>
      </div>

      <!-- ════ VERIFICATION ════ -->
      <div id="verification-admin" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">Provider Verification</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Review identity documents and approve providers for the verified badge.</p>

        <?php
        $pendingVerifications = array_filter($verificationRequests, fn($v) => $v['status'] === 'pending');
        $processedVerifications = array_filter($verificationRequests, fn($v) => $v['status'] !== 'pending');
        $idTypeLabels = ['ghana_card' => 'Ghana Card', 'passport' => 'Passport', 'voters_id' => "Voter's ID", 'drivers_license' => "Driver's License", 'nhis' => 'NHIS Card'];
        ?>

        <?php if (!empty($pendingVerifications)): ?>
        <div class="section-card" style="border-left:3px solid var(--ember);">
          <h3>⏳ Pending Review (<?= count($pendingVerifications) ?>)</h3>
          <?php foreach ($pendingVerifications as $vr): ?>
          <div style="background:var(--cream);border:1px solid var(--border);border-radius:10px;padding:24px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:16px;">
              <div>
                <p style="font-weight:700;color:var(--bark);font-size:1.05rem;"><?= htmlspecialchars($vr['full_name']) ?></p>
                <p style="font-size:0.82rem;color:var(--ember);font-weight:600;"><?= htmlspecialchars($vr['service_category']) ?> · ★ <?= number_format($vr['rating'], 1) ?> · <?= $vr['experience_years'] ?> yrs</p>
                <p style="font-size:0.78rem;color:var(--sand);margin-top:4px;">Submitted: <?= date('j M Y, g:i A', strtotime($vr['created_at'])) ?></p>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
              <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:14px;">
                <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:6px;">ID Document</p>
                <p style="font-weight:600;color:var(--bark);"><?= $idTypeLabels[$vr['id_type']] ?? $vr['id_type'] ?></p>
                <p style="font-size:0.85rem;color:var(--warm-mid);">Number: <?= htmlspecialchars($vr['id_number']) ?></p>
                <?php if (!empty($vr['document_path'])): ?>
                  <a href="<?= htmlspecialchars($vr['document_path']) ?>" target="_blank" style="display:inline-block;margin-top:8px;color:var(--ember);font-size:0.82rem;font-weight:600;text-decoration:none;">View Document →</a>
                <?php endif; ?>
              </div>
              <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:14px;">
                <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:6px;">Certificate</p>
                <?php if (!empty($vr['cert_path'])): ?>
                  <p style="font-weight:600;color:var(--bark);">Uploaded</p>
                  <a href="<?= htmlspecialchars($vr['cert_path']) ?>" target="_blank" style="display:inline-block;margin-top:8px;color:var(--ember);font-size:0.82rem;font-weight:600;text-decoration:none;">View Certificate →</a>
                <?php else: ?>
                  <p style="color:var(--sand);">Not provided</p>
                <?php endif; ?>
              </div>
            </div>

            <?php if (!empty($vr['notes'])): ?>
              <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:16px;">
                <p style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);margin-bottom:4px;">Provider Notes</p>
                <p style="font-size:0.85rem;color:var(--warm-mid);"><?= htmlspecialchars($vr['notes']) ?></p>
              </div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
              <form action="admin_action.php" method="POST" style="display:inline;">
                <input type="hidden" name="action" value="approve_verification">
                <input type="hidden" name="request_id" value="<?= $vr['id'] ?>">
                <button type="submit" class="toggle-btn toggle-on" style="padding:10px 24px;">Approve & Verify ✓</button>
              </form>
              <form action="admin_action.php" method="POST" style="display:inline-flex;gap:8px;align-items:flex-end;">
                <input type="hidden" name="action" value="reject_verification">
                <input type="hidden" name="request_id" value="<?= $vr['id'] ?>">
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <label style="font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--sand);">Rejection Reason</label>
                  <input type="text" name="admin_notes" placeholder="e.g. ID photo is blurry" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.82rem;width:250px;">
                </div>
                <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;padding:10px 18px;">Reject ✗</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($processedVerifications)): ?>
        <div class="section-card">
          <h3>History</h3>
          <table class="data-table">
            <thead>
              <tr><th>Provider</th><th>Category</th><th>ID Type</th><th>ID Number</th><th>Documents</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($processedVerifications as $vr): ?>
              <tr>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($vr['full_name']) ?></td>
                <td><?= htmlspecialchars($vr['service_category']) ?></td>
                <td><?= $idTypeLabels[$vr['id_type']] ?? $vr['id_type'] ?></td>
                <td style="font-size:0.82rem;"><?= htmlspecialchars($vr['id_number']) ?></td>
                <td>
                  <?php if (!empty($vr['document_path'])): ?><a href="<?= htmlspecialchars($vr['document_path']) ?>" target="_blank" style="color:var(--ember);font-size:0.78rem;font-weight:600;text-decoration:none;">ID</a><?php endif; ?>
                  <?php if (!empty($vr['cert_path'])): ?> · <a href="<?= htmlspecialchars($vr['cert_path']) ?>" target="_blank" style="color:var(--ember);font-size:0.78rem;font-weight:600;text-decoration:none;">Cert</a><?php endif; ?>
                </td>
                <td><span class="status-badge status-<?= $vr['status'] === 'approved' ? 'accepted' : 'cancelled' ?>"><?= ucfirst($vr['status']) ?></span></td>
                <td><?= date('j M Y', strtotime($vr['created_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if (empty($verificationRequests)): ?>
        <div class="section-card"><p>No verification requests yet.</p></div>
        <?php endif; ?>
      </div>

      <!-- ════ CATEGORIES ════ -->
      <div id="categories-admin" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">Homepage Categories</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Manage the Popular Services cards on the homepage. Drag the order number to rearrange.</p>

        <!-- Add new category -->
        <div class="section-card">
          <h3>Add New Category</h3>
          <form action="admin_action.php" method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="add_category">
            <div style="display:flex;flex-direction:column;gap:4px;">
              <label style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);">Icon</label>
              <input type="text" name="icon" placeholder="🔧" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:1.2rem;width:60px;text-align:center;" required>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
              <label style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);">Name</label>
              <input type="text" name="name" placeholder="e.g. Carpentry" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.88rem;width:150px;" required>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:200px;">
              <label style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);">Description</label>
              <input type="text" name="description" placeholder="Short description for the card" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.88rem;" required>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
              <label style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);">Filter Key</label>
              <input type="text" name="filter_key" placeholder="e.g. carpentry" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.88rem;width:130px;" required>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
              <label style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);">Order</label>
              <input type="number" name="display_order" value="<?= count($homepageCategories) + 1 ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:0.88rem;width:60px;" min="1">
            </div>
            <button type="submit" class="toggle-btn toggle-on" style="padding:9px 18px;">Add Category</button>
          </form>
        </div>

        <!-- Current categories -->
        <div class="section-card">
          <h3>Current Homepage Categories (<?= count($homepageCategories) ?>)</h3>
          <?php if (empty($homepageCategories)): ?>
            <p>No categories added yet. Add one above.</p>
          <?php else: ?>
          <table class="data-table">
            <thead>
              <tr><th>Order</th><th>Icon</th><th>Name</th><th>Description</th><th>Filter Key</th><th>Visible</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($homepageCategories as $cat): ?>
              <tr>
                <td style="font-weight:700;">#<?= $cat['display_order'] ?></td>
                <td style="font-size:1.5rem;"><?= htmlspecialchars($cat['icon']) ?></td>
                <td style="font-weight:600;color:var(--bark);"><?= htmlspecialchars($cat['name']) ?></td>
                <td style="font-size:0.82rem;color:var(--warm-mid);max-width:250px;"><?= htmlspecialchars($cat['description']) ?></td>
                <td><code style="background:var(--cream);padding:2px 8px;border-radius:4px;font-size:0.78rem;"><?= htmlspecialchars($cat['filter_key']) ?></code></td>
                <td>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_category_visible">
                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="toggle-btn <?= $cat['is_visible'] ? 'toggle-on' : 'toggle-off' ?>">
                      <?= $cat['is_visible'] ? '✓ Visible' : 'Hidden' ?>
                    </button>
                  </form>
                </td>
                <td style="white-space:nowrap;">
                  <button class="toggle-btn toggle-off" onclick="toggleEditCat(<?= $cat['id'] ?>)">Edit</button>
                  <form action="admin_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Delete this category?');">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;font-size:0.65rem;">Delete</button>
                  </form>
                </td>
              </tr>
              <!-- Edit row -->
              <tr id="edit-cat-<?= $cat['id'] ?>" style="display:none;background:var(--parchment);">
                <td colspan="7">
                  <form action="admin_action.php" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                    <input type="text" name="icon" value="<?= htmlspecialchars($cat['icon']) ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:1.2rem;width:50px;text-align:center;">
                    <input type="text" name="name" value="<?= htmlspecialchars($cat['name']) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;width:130px;" placeholder="Name">
                    <input type="text" name="description" value="<?= htmlspecialchars($cat['description']) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;flex:1;min-width:200px;" placeholder="Description">
                    <input type="text" name="filter_key" value="<?= htmlspecialchars($cat['filter_key']) ?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;width:110px;" placeholder="Filter key">
                    <input type="number" name="display_order" value="<?= $cat['display_order'] ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:0.85rem;width:55px;" min="1">
                    <button type="submit" class="toggle-btn toggle-on">Save</button>
                    <button type="button" class="toggle-btn toggle-off" onclick="toggleEditCat(<?= $cat['id'] ?>)">Cancel</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>

        <!-- Preview -->
        <div class="section-card">
          <h3>Homepage Preview</h3>
          <p style="color:var(--sand);font-size:0.88rem;margin-bottom:20px;">This is how the Popular Services section looks to visitors.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
            <?php foreach (array_filter($homepageCategories, fn($c) => $c['is_visible']) as $cat): ?>
            <div style="background:var(--cream);border:1px solid var(--border);border-radius:10px;padding:24px 20px;">
              <span style="font-size:2rem;display:block;margin-bottom:10px;"><?= htmlspecialchars($cat['icon']) ?></span>
              <p style="font-weight:700;color:var(--bark);margin-bottom:4px;"><?= htmlspecialchars($cat['name']) ?></p>
              <p style="font-size:0.82rem;color:var(--warm-mid);"><?= htmlspecialchars($cat['description']) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ════ PLATFORM FEEDBACK ════ -->
      <div id="feedback-admin" class="admin-panel">
        <h2 class="dash-panel-title">Platform Feedback & Support</h2>
        <p class="dash-panel-sub">What users are saying about QuickHire. Average rating: <strong style="color:var(--ember);">★ <?= $avgFeedbackRating ?: 'N/A' ?></strong></p>

        <?php if (empty($feedbackList)): ?>
          <div class="profile-section"><p>No feedback submitted yet.</p></div>
        <?php else: ?>
        <div class="profile-section">
          <?php foreach ($feedbackList as $fb):
            $fbStars = str_repeat('★', $fb['rating']) . str_repeat('☆', 5 - $fb['rating']);
            $isSupport = in_array($fb['category'], ['service_issue', 'provider_complaint', 'payment_issue']);
          ?>
          <div style="background:var(--card-bg);border:1.5px solid <?= $isSupport ? 'rgba(249,115,22,0.3)' : 'var(--border)' ?>;border-radius:12px;padding:20px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
              <div>
                <span style="font-weight:700;font-size:0.92rem;"><?= htmlspecialchars($fb['full_name']) ?></span>
                <span style="font-size:0.72rem;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;<?= $isSupport ? 'background:rgba(249,115,22,0.1);color:#c2410c;' : 'background:rgba(13,148,136,0.08);color:var(--ember);' ?>"><?= ucfirst(str_replace('_', ' ', $fb['category'])) ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:10px;">
                <span style="color:var(--ember);font-size:0.9rem;"><?= $fbStars ?></span>
                <span style="font-size:0.75rem;color:var(--sand);"><?= date('j M Y', strtotime($fb['created_at'])) ?></span>
              </div>
            </div>
            <p style="font-size:0.88rem;color:var(--warm-mid);line-height:1.6;margin-bottom:12px;"><?= htmlspecialchars($fb['message']) ?></p>

            <?php if (!empty($fb['admin_reply'])): ?>
              <div style="background:rgba(13,148,136,0.04);border:1px solid rgba(13,148,136,0.12);border-radius:8px;padding:12px 14px;margin-bottom:10px;">
                <p style="font-size:0.72rem;font-weight:700;color:var(--ember);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">QuickHire Response</p>
                <p style="font-size:0.85rem;color:var(--bark);line-height:1.6;"><?= htmlspecialchars($fb['admin_reply']) ?></p>
              </div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;align-items:center;">
              <?php if (!$fb['is_read']): ?>
              <form action="admin_action.php" method="POST" style="display:inline;">
                <input type="hidden" name="action" value="mark_feedback_read">
                <input type="hidden" name="feedback_id" value="<?= $fb['id'] ?>">
                <button type="submit" style="background:none;border:1.5px solid var(--border);color:var(--warm-mid);cursor:pointer;font-size:0.75rem;font-weight:700;padding:6px 14px;border-radius:6px;">Mark Read</button>
              </form>
              <?php endif; ?>
              <button onclick="document.getElementById('reply-<?= $fb['id'] ?>').style.display=document.getElementById('reply-<?= $fb['id'] ?>').style.display==='none'?'block':'none'" style="background:none;border:1.5px solid var(--ember);color:var(--ember);cursor:pointer;font-size:0.75rem;font-weight:700;padding:6px 14px;border-radius:6px;"><?= empty($fb['admin_reply']) ? '💬 Reply' : '✏️ Edit Reply' ?></button>
            </div>

            <div id="reply-<?= $fb['id'] ?>" style="display:none;margin-top:12px;">
              <form action="admin_action.php" method="POST" style="display:flex;gap:10px;align-items:flex-end;">
                <input type="hidden" name="action" value="reply_feedback">
                <input type="hidden" name="feedback_id" value="<?= $fb['id'] ?>">
                <input type="hidden" name="user_id" value="<?= $fb['user_id'] ?>">
                <textarea name="admin_reply" placeholder="Write your response to the user…" required style="flex:1;padding:10px 14px;font-family:'Outfit',sans-serif;font-size:0.88rem;background:var(--cream);border:1.5px solid var(--border);border-radius:8px;color:var(--bark);resize:vertical;min-height:60px;outline:none;"><?= htmlspecialchars($fb['admin_reply'] ?? '') ?></textarea>
                <button type="submit" style="padding:10px 18px;background:var(--ember);color:#fff;border:none;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;white-space:nowrap;">Send Reply →</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </main>
  </div>

  <footer>
    <p><strong>QuickHire</strong> &mdash; Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

  <script>
    function showAdmin(id, el) {
      document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.admin-nav a').forEach(n => n.classList.remove('active'));
      document.getElementById(id).classList.add('active');
      el.classList.add('active');
    }

    function toggleEditUser(id) {
      const row = document.getElementById('edit-user-' + id);
      row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }

    function toggleEditProvider(id) {
      const row = document.getElementById('edit-provider-' + id);
      row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }

    function toggleEditCat(id) {
      const row = document.getElementById('edit-cat-' + id);
      row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
  </script>

</body>
</html>
