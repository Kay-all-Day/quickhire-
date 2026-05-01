<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/smileid.php';

requireAdmin();

$errors      = $_SESSION['errors'] ?? [];
$success     = $_SESSION['success'] ?? '';
unset($_SESSION['errors'], $_SESSION['success']);

$commissionRate = 0.10;

// ══════════ ALL PROVIDERS ══════════
$allProviders = [];
try {
    $allProviders = $pdo->query("
        SELECT sp.*, u.full_name, u.email, u.phone,
               (SELECT COUNT(*) FROM bookings WHERE provider_id = sp.provider_id) as job_count,
               (SELECT COALESCE(SUM(p.amount), 0)
                FROM payments p JOIN bookings b ON p.booking_id = b.booking_id
                WHERE b.provider_id = sp.provider_id AND p.payment_status = 'completed') as total_earned
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        ORDER BY sp.rating DESC
    ")->fetchAll();
} catch (Throwable $e) {}

// ══════════ PROVIDER EARNINGS ══════════
$providerEarnings    = [];
$totalPayoutsReleased  = 0.0;
$totalCommissionEarned = 0.0;
$totalTaxHeld          = 0.0;
try {
    $providerEarnings = $pdo->query("
        SELECT u.full_name, sp.provider_id, sp.service_category, sp.rating, sp.is_featured, sp.is_verified,
               COUNT(b.booking_id) as total_jobs,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount ELSE 0 END),0) as gross_earned,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount*$commissionRate ELSE 0 END),0) as commission_paid,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount*(1-$commissionRate) ELSE 0 END),0) as net_earned
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        LEFT JOIN bookings b ON b.provider_id = sp.provider_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        GROUP BY sp.provider_id
        ORDER BY gross_earned DESC
    ")->fetchAll();

    $payoutCommission    = (float)$pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
    $cashCommissionPaid  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM provider_commissions WHERE status='paid'")->fetchColumn();
    $totalCommissionEarned = round($payoutCommission + $cashCommissionPaid, 2);
    $totalPayoutsReleased  = (float)$pdo->query("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
    $totalTaxHeld          = (float)$pdo->query("SELECT COALESCE(SUM(tax_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
} catch (Throwable $e) {}

// ══════════ FEATURED REQUESTS ══════════
$featuredRequests    = [];
$pendingFeaturedCount = 0;
$featuredCount       = 0;
$featuredRevenue     = 0.0;
try {
    $featuredRequests = $pdo->query("
        SELECT fr.*, u.full_name, sp.service_category, sp.rating
        FROM featured_requests fr
        JOIN service_providers sp ON fr.provider_id = sp.provider_id
        JOIN users u ON sp.user_id = u.user_id
        ORDER BY fr.created_at DESC
    ")->fetchAll();
    foreach ($featuredRequests as $fr) {
        if ($fr['request_status'] === 'pending' && $fr['payment_status'] === 'completed') $pendingFeaturedCount++;
    }
    $featuredCount   = (int)$pdo->query("SELECT COUNT(*) FROM service_providers WHERE is_featured=1")->fetchColumn();
    $featuredRevenue = (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM featured_requests WHERE payment_status='completed'")->fetchColumn();
} catch (Throwable $e) {}

// ══════════ VERIFICATION REQUESTS ══════════
$verificationRequests    = [];
$pendingVerificationCount = 0;
try {
    $verificationRequests = $pdo->query("
        SELECT vr.*, u.full_name, u.email, sp.service_category, sp.rating, sp.experience_years
        FROM verification_requests vr
        JOIN service_providers sp ON vr.provider_id = sp.provider_id
        JOIN users u ON sp.user_id = u.user_id
        ORDER BY vr.created_at DESC
    ")->fetchAll();
    foreach ($verificationRequests as $vr) {
        if ($vr['status'] === 'pending') $pendingVerificationCount++;
    }
} catch (Throwable $e) {}

// ══════════ API PARTNERS ══════════
$partnersMissing = false;
try {
    $partners = $pdo->query("SELECT * FROM partners ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $partners = [];
    $partnersMissing = true;
}

$smileIdPartner = null;
foreach ($partners as $p) {
    if ($p['slug'] === 'smile_id') { $smileIdPartner = $p; break; }
}
$cfg = smileid_config();

// ══════════ SMILE ID USAGE STATS ══════════
$stats = ['today' => null, 'week' => null, 'month' => null, 'all' => null];
$log   = [];
if ($smileIdPartner) {
    $windows = [
        'today' => "DATE(created_at) = CURDATE()",
        'week'  => "YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)",
        'month' => "DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')",
        'all'   => "1=1",
    ];
    foreach ($windows as $k => $where) {
        try {
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) AS cnt FROM partner_activity_log
                WHERE partner_id=? AND action='verify_id' AND $where GROUP BY status
            ");
            $stmt->execute([$smileIdPartner['id']]);
            $s = ['verified'=>0,'failed'=>0,'error'=>0,'total'=>0];
            foreach ($stmt->fetchAll() as $row) {
                $s[$row['status']] = (int)$row['cnt'];
                $s['total'] += (int)$row['cnt'];
            }
            $stats[$k] = $s;
        } catch (Throwable $e) {}
    }
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM partner_activity_log WHERE partner_id=? ORDER BY created_at DESC LIMIT 20
        ");
        $stmt->execute([$smileIdPartner['id']]);
        $log = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

// ══════════ WALLET DATA ══════════
$walletRow  = null;
$walletTxns = [];
if ($smileIdPartner) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM partner_wallet WHERE partner_id=? LIMIT 1");
        $stmt->execute([$smileIdPartner['id']]);
        $walletRow = $stmt->fetch() ?: null;
    } catch (Throwable $e) {}
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM partner_transactions WHERE partner_id=? ORDER BY created_at DESC LIMIT 10
        ");
        $stmt->execute([$smileIdPartner['id']]);
        $walletTxns = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

$statusColors = [
    'active'     => ['bg'=>'#dcfce7','fg'=>'#166534'],
    'paused'     => ['bg'=>'#fef3c7','fg'=>'#92400e'],
    'terminated' => ['bg'=>'#fee2e2','fg'=>'#991b1b'],
];

$idTypeLabels = [
    'ghana_card'      => 'Ghana Card',
    'passport'        => 'Passport',
    'voters_id'       => "Voter's ID",
    'drivers_license' => "Driver's License",
    'nhis'            => 'NHIS Card',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Providers & Partners — QuickHire Admin</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .admin-layout { display: grid; grid-template-columns: 260px 1fr; min-height: calc(100vh - var(--header-h)); }
    .admin-sidebar { background: var(--bark); padding: 36px 0; position: sticky; top: var(--header-h); height: calc(100vh - var(--header-h)); overflow-y: auto; }
    .admin-badge { margin: 0 24px 24px; padding: 12px 16px; background: rgba(196,92,26,0.15); border: 1px solid rgba(196,92,26,0.3); border-radius: 8px; text-align: center; }
    .admin-badge h3 { font-family: 'Sora', sans-serif; font-size: 1.1rem; color: var(--cream); font-weight: 900; }
    .admin-badge p { font-size: 0.72rem; color: var(--ember); font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; }
    .admin-nav { display: flex; flex-direction: column; }
    .admin-nav a { display: flex; align-items: center; gap: 10px; padding: 13px 24px; font-size: 0.83rem; font-weight: 600; color: rgba(245,240,232,0.5); cursor: pointer; transition: all 0.18s; border-left: 3px solid transparent; text-decoration: none; }
    .admin-nav a:hover { color: var(--cream); background: rgba(255,255,255,0.05); }
    .admin-nav a.active { color: var(--cream); background: rgba(196,92,26,0.12); border-left-color: var(--ember); }
    .nav-badge { display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:var(--ember);color:#fff;font-size:0.65rem;font-weight:800;letter-spacing:0.02em;vertical-align:middle; }
    .admin-main { padding: 48px; background: var(--cream); }

    .admin-panel { display: none; }
    .admin-panel.active { display: block; }

    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .stat-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 22px 20px; }
    .stat-card .num { font-family: 'Sora', sans-serif; font-size: 2rem; font-weight: 900; color: var(--bark); letter-spacing: -0.05em; display: block; }
    .stat-card .lbl { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--sand); }
    .stat-card.highlight { background: var(--bark); border-color: var(--bark); }
    .stat-card.highlight .num { color: var(--ember); }
    .stat-card.highlight .lbl { color: rgba(245,240,232,0.5); }

    .section-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 14px; padding: 32px; margin-bottom: 24px; }
    .section-card h3 { font-family: 'Sora', sans-serif; font-size: 1.2rem; font-weight: 900; letter-spacing: -0.03em; margin-bottom: 20px; color: var(--bark); }

    .data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .data-table th { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--sand); padding: 0 14px 12px; text-align: left; border-bottom: 1.5px solid var(--border); }
    .data-table td { padding: 14px; border-bottom: 1px solid var(--border); color: var(--warm-mid); vertical-align: middle; }
    .data-table tr:hover td { background: var(--parchment); }

    .toggle-btn { padding: 4px 12px; border-radius: 4px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; border: none; cursor: pointer; transition: all 0.2s; }
    .toggle-on  { background: var(--ember); color: #fff; }
    .toggle-off { background: var(--parchment); color: var(--sand); border: 1px solid var(--border); }
    .toggle-btn:hover { opacity: 0.85; }

    .partner-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 14px; padding: 0; margin-bottom: 24px; overflow: hidden; }
    .partner-header { padding: 28px 32px; border-bottom: 1.5px solid var(--border); display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; background: linear-gradient(to right, rgba(196,92,26,0.04), transparent); }
    .partner-title h2 { font-family: 'Sora', sans-serif; font-size: 1.6rem; font-weight: 900; letter-spacing: -0.03em; color: var(--bark); margin-bottom: 6px; }
    .partner-title .cat { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--sand); }
    .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; }
    .partner-body { padding: 28px 32px; }
    .partner-section { margin-bottom: 28px; }
    .partner-section:last-child { margin-bottom: 0; }
    .partner-section h3 { font-family: 'Sora', sans-serif; font-size: 1.05rem; font-weight: 800; color: var(--bark); margin-bottom: 14px; letter-spacing: -0.02em; }
    .field-row { display: grid; grid-template-columns: 180px 1fr auto; gap: 16px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
    .field-row:last-child { border-bottom: none; }
    .field-label { font-size: 0.82rem; font-weight: 700; color: var(--warm-mid); }
    .field-value { font-size: 0.9rem; color: var(--bark); }
    .field-value code { background: var(--parchment); padding: 3px 8px; border-radius: 4px; font-size: 0.82rem; }
    .mode-toggle { display: inline-flex; border: 1.5px solid var(--border); border-radius: 8px; overflow: hidden; }
    .mode-toggle button { padding: 8px 16px; font-size: 0.8rem; font-weight: 700; background: #fff; color: var(--sand); border: none; cursor: pointer; letter-spacing: 0.05em; text-transform: uppercase; }
    .mode-toggle button.active { background: var(--bark); color: var(--cream); }
    .mode-toggle form { display: inline; }
    .text-input { padding: 10px 14px; font-size: 0.9rem; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; color: var(--bark); font-family: inherit; min-width: 280px; }
    .btn-primary { padding: 10px 18px; font-size: 0.82rem; font-weight: 700; background: var(--ember); color: #fff; border: none; border-radius: 8px; cursor: pointer; letter-spacing: 0.03em; text-transform: uppercase; text-decoration: none; display: inline-block; }
    .btn-primary:hover { opacity: 0.9; }
    .btn-secondary { padding: 10px 18px; font-size: 0.82rem; font-weight: 700; background: #fff; color: var(--bark); border: 1.5px solid var(--border); border-radius: 8px; cursor: pointer; }
    .btn-secondary:hover { background: var(--parchment); }

    .breakdown-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .breakdown-tile { padding: 16px; border-radius: 10px; border: 1.5px solid var(--border); background: #fff; }
    .breakdown-tile .w { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--sand); margin-bottom: 8px; }
    .breakdown-tile .n { font-family: 'Sora', sans-serif; font-size: 1.6rem; font-weight: 900; color: var(--bark); letter-spacing: -0.03em; }
    .breakdown-tile .parts { font-size: 0.72rem; color: var(--sand); margin-top: 4px; }
    .breakdown-tile.green { border-color: #86efac; }
    .breakdown-tile.green .n { color: #166534; }
    .breakdown-tile.red { border-color: #fca5a5; }
    .breakdown-tile.red .n { color: #991b1b; }

    .log-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .log-table th { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--sand); padding: 0 12px 10px; text-align: left; border-bottom: 1.5px solid var(--border); }
    .log-table td { padding: 12px; border-bottom: 1px solid var(--border); color: var(--warm-mid); vertical-align: middle; }
    .log-mini-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }

    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    .alert-success { background: #dcfce7; color: #166534; border: 1.5px solid #86efac; }
    .alert-error   { background: #fee2e2; color: #991b1b; border: 1.5px solid #fca5a5; }
    .alert-info    { background: #dbeafe; color: #1e40af; border: 1.5px solid #93c5fd; }

    @media (max-width: 1024px) {
      .admin-layout { grid-template-columns: 1fr; }
      .admin-sidebar { position: static; height: auto; display: flex; flex-wrap: wrap; padding: 16px; gap: 4px; }
      .admin-badge { margin: 0 0 8px; width: 100%; }
      .admin-nav { flex-direction: row; flex-wrap: wrap; }
      .admin-nav a { border-left: none; border-bottom: 2px solid transparent; padding: 10px 14px; border-radius: 6px; }
      .admin-main { padding: 32px 24px; }
      .field-row { grid-template-columns: 1fr; gap: 8px; }
      .partner-header { padding: 20px; }
      .partner-body { padding: 20px; }
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
        <p>Providers & Partners</p>
      </div>
      <div class="admin-nav">
        <a href="admin.php">← Back to Admin</a>
        <a onclick="showPanel('providers', this)">🛠 Providers</a>
        <a onclick="showPanel('verification', this)">✅ Verification<?php if ($pendingVerificationCount > 0): ?> <span class="nav-badge" id="badge-verification"><?= $pendingVerificationCount ?></span><?php endif; ?></a>
        <a onclick="showPanel('featured', this)">⭐ Featured</a>
        <a onclick="showPanel('featured-requests', this)">🌟 Requests<?php if ($pendingFeaturedCount > 0): ?> <span class="nav-badge" id="badge-featured"><?= $pendingFeaturedCount ?></span><?php endif; ?></a>
        <a onclick="showPanel('revenue', this)">💰 Revenue</a>
        <a onclick="showPanel('api-partners', this)">🤝 API Partners</a>
      </div>
    </aside>

    <main class="admin-main">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <!-- ════ WELCOME (default) ════ -->
      <div id="welcome" class="admin-panel active">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Providers & Partners</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Select a section from the sidebar to manage providers, verifications, featured listings, or API integrations.</p>
        <div class="stat-grid">
          <div class="stat-card"><span class="num"><?= count($allProviders) ?></span><span class="lbl">Total Providers</span></div>
          <div class="stat-card"><span class="num"><?= count(array_filter($allProviders, fn($p) => $p['is_verified'])) ?></span><span class="lbl">Verified</span></div>
          <div class="stat-card"><span class="num"><?= $featuredCount ?></span><span class="lbl">Featured</span></div>
          <?php if ($pendingVerificationCount > 0): ?>
          <div class="stat-card" style="border-color:var(--ember);"><span class="num" style="color:var(--ember);"><?= $pendingVerificationCount ?></span><span class="lbl">Pending Verification</span></div>
          <?php endif; ?>
          <?php if ($pendingFeaturedCount > 0): ?>
          <div class="stat-card" style="border-color:var(--ember);"><span class="num" style="color:var(--ember);"><?= $pendingFeaturedCount ?></span><span class="lbl">Featured Requests</span></div>
          <?php endif; ?>
          <div class="stat-card highlight"><span class="num" style="font-size:1.3rem;">GH₵ <?= number_format($totalPayoutsReleased, 0) ?></span><span class="lbl">Payouts Released</span></div>
        </div>
      </div>

      <!-- ════ PROVIDERS ════ -->
      <div id="providers" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Provider Management</h2>
        <p style="color:var(--sand);margin-bottom:36px;"><?= count($allProviders) ?> service providers on the platform.</p>

        <div class="stat-grid">
          <div class="stat-card"><span class="num"><?= count($allProviders) ?></span><span class="lbl">Total Providers</span></div>
          <div class="stat-card"><span class="num"><?= count(array_filter($allProviders, fn($p) => $p['is_verified'])) ?></span><span class="lbl">Verified</span></div>
          <div class="stat-card"><span class="num"><?= count(array_filter($allProviders, fn($p) => $p['is_featured'])) ?></span><span class="lbl">Featured</span></div>
          <div class="stat-card highlight"><span class="num" style="font-size:1.3rem;">GH₵ <?= number_format($totalPayoutsReleased, 0) ?></span><span class="lbl">Payouts Released</span></div>
        </div>

        <div class="section-card">
          <table class="data-table">
            <thead>
              <tr><th>Provider</th><th>Category</th><th>Rating</th><th>Jobs</th><th>Earned</th><th>Cap/day</th><th>Verified</th><th>Featured</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($allProviders as $p): ?>
              <tr>
                <td>
                  <div style="font-weight:700;color:var(--bark);"><?= htmlspecialchars($p['full_name']) ?></div>
                  <div style="font-size:0.75rem;color:var(--sand);"><?= htmlspecialchars($p['email']) ?></div>
                </td>
                <td><?= htmlspecialchars($p['service_category']) ?></td>
                <td>★ <?= number_format($p['rating'], 1) ?></td>
                <td><?= $p['job_count'] ?></td>
                <td>GH₵ <?= number_format($p['total_earned'], 0) ?></td>
                <td style="color:var(--sand);font-size:0.82rem;"><?= ($p['daily_booking_cap'] ?? 0) > 0 ? (int)$p['daily_booking_cap'] : '∞' ?></td>
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
              <tr id="edit-provider-<?= $p['provider_id'] ?>" style="display:none;background:var(--parchment);">
                <td colspan="9">
                  <form action="admin_action.php" method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;">
                    <input type="hidden" name="action" value="update_provider">
                    <input type="hidden" name="provider_id" value="<?= $p['provider_id'] ?>">
                    <input type="text"   name="service_category" value="<?= htmlspecialchars($p['service_category']) ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:140px;" placeholder="Category">
                    <input type="number" name="experience_years" value="<?= $p['experience_years'] ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:60px;" placeholder="Yrs" min="0">
                    <input type="text"   name="availability"     value="<?= htmlspecialchars($p['availability'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:120px;" placeholder="Availability">
                    <input type="text"   name="languages"        value="<?= htmlspecialchars($p['languages'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:140px;" placeholder="Languages">
                    <input type="text"   name="avg_response"     value="<?= htmlspecialchars($p['avg_response'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;width:80px;" placeholder="e.g. 2hr">
                    <input type="text"   name="bio"              value="<?= htmlspecialchars($p['bio'] ?? '') ?>" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:4px;font-size:0.85rem;flex:1;min-width:200px;" placeholder="Bio">
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

      <!-- ════ VERIFICATION ════ -->
      <div id="verification" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">Provider Verification</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Review identity documents and approve providers for the verified badge.</p>

        <?php
        $pendingVerifications   = array_filter($verificationRequests, fn($v) => $v['status'] === 'pending');
        $processedVerifications = array_filter($verificationRequests, fn($v) => $v['status'] !== 'pending');
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

            <?php
              $sBadge   = smileid_badge($vr['smileid_status'] ?? 'pending');
              $sChecked = !empty($vr['smileid_checked_at']) ? date('j M Y, g:i A', strtotime($vr['smileid_checked_at'])) : null;
            ?>
            <div style="background:#fff;border:1.5px solid <?= $sBadge['fg'] ?>33;border-left:4px solid <?= $sBadge['fg'] ?>;border-radius:8px;padding:14px;margin-bottom:16px;">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:10px;">
                  <span style="display:inline-flex;align-items:center;gap:6px;background:<?= $sBadge['bg'] ?>;color:<?= $sBadge['fg'] ?>;padding:5px 12px;border-radius:999px;font-size:0.78rem;font-weight:700;">
                    <?= $sBadge['icon'] ?> <?= $sBadge['label'] ?>
                  </span>
                  <span style="font-size:0.72rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--sand);">Smile ID</span>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                  <?php if ($sChecked): ?>
                    <span style="font-size:0.72rem;color:var(--sand);">Checked <?= $sChecked ?></span>
                  <?php endif; ?>
                  <form action="admin_action.php" method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="reverify_smileid">
                    <input type="hidden" name="request_id" value="<?= $vr['id'] ?>">
                    <button type="submit" style="background:#fff;border:1.5px solid var(--border);color:var(--bark);padding:5px 12px;border-radius:6px;font-size:0.72rem;font-weight:700;cursor:pointer;letter-spacing:0.03em;text-transform:uppercase;">↻ Re-verify</button>
                  </form>
                </div>
              </div>
              <?php if (!empty($vr['smileid_summary'])): ?>
                <p style="font-size:0.88rem;color:var(--bark);margin-top:10px;font-weight:500;"><?= htmlspecialchars($vr['smileid_summary']) ?></p>
              <?php endif; ?>
              <?php if (!empty($vr['smileid_reference'])): ?>
                <p style="font-size:0.74rem;color:var(--sand);margin-top:4px;">Smile Job ID: <code style="background:#f3f4f6;padding:1px 6px;border-radius:4px;"><?= htmlspecialchars($vr['smileid_reference']) ?></code></p>
              <?php endif; ?>
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
                <button type="submit" class="toggle-btn toggle-on" style="padding:10px 24px;" onclick="decBadge('badge-verification')">Approve & Verify ✓</button>
              </form>
              <form action="admin_action.php" method="POST" style="display:inline-flex;gap:8px;align-items:flex-end;">
                <input type="hidden" name="action" value="reject_verification">
                <input type="hidden" name="request_id" value="<?= $vr['id'] ?>">
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <label style="font-size:0.68rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--sand);">Rejection Reason</label>
                  <input type="text" name="admin_notes" placeholder="e.g. ID photo is blurry" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:0.82rem;width:250px;">
                </div>
                <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;padding:10px 18px;" onclick="decBadge('badge-verification')">Reject ✗</button>
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
            <thead><tr><th>Provider</th><th>Category</th><th>ID Type</th><th>ID Number</th><th>Documents</th><th>Status</th><th>Date</th></tr></thead>
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

      <!-- ════ FEATURED ════ -->
      <div id="featured" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Featured Providers</h2>
        <p style="color:var(--sand);margin-bottom:36px;">Manage which providers appear on the homepage. Featured listing revenue: <strong style="color:var(--ember);">GH₵ <?= number_format($featuredRevenue, 2) ?></strong></p>

        <div class="section-card">
          <h3>Currently Featured (<?= $featuredCount ?>)</h3>
          <?php $featuredProviders = array_filter($allProviders, fn($p) => $p['is_featured']); ?>
          <?php if (empty($featuredProviders)): ?>
            <p>No featured providers. Toggle "Feature" on any provider below.</p>
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
            <thead><tr><th>Provider</th><th>Category</th><th>Rating</th><th>Jobs</th><th>Verified</th><th>Featured</th></tr></thead>
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
                    <button type="submit" class="toggle-btn <?= $p['is_featured'] ? 'toggle-on' : 'toggle-off' ?>"><?= $p['is_featured'] ? '★ Featured' : 'Feature' ?></button>
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
        $paidPending   = array_filter($featuredRequests, fn($r) => $r['request_status'] === 'pending' && $r['payment_status'] === 'completed');
        $unpaidPending = array_filter($featuredRequests, fn($r) => $r['request_status'] === 'pending' && $r['payment_status'] === 'pending');
        $processed     = array_filter($featuredRequests, fn($r) => in_array($r['request_status'], ['approved','rejected','expired']));
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
                <button type="submit" class="toggle-btn toggle-on" style="padding:8px 18px;" onclick="decBadge('badge-featured')">Approve ✓</button>
              </form>
              <form action="admin_action.php" method="POST" style="display:inline;" onsubmit="return confirm('Reject this request? The provider has already paid.');">
                <input type="hidden" name="action" value="reject_featured">
                <input type="hidden" name="request_id" value="<?= $fr['id'] ?>">
                <button type="submit" class="toggle-btn" style="background:#991b1b;color:#fff;padding:8px 18px;" onclick="decBadge('badge-featured')">Reject ✗</button>
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
            <thead><tr><th>Provider</th><th>Category</th><th>Plan</th><th>Fee</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
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
        <div class="section-card"><p>No featured listing requests yet.</p></div>
        <?php endif; ?>
      </div>

      <!-- ════ REVENUE ════ -->
      <div id="revenue" class="admin-panel">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:36px;">
          <div>
            <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:900;letter-spacing:-0.05em;margin-bottom:6px;">Provider Revenue</h2>
            <p style="color:var(--sand);">Earnings, payouts, and commission breakdown per provider.</p>
          </div>
          <a href="export_csv.php?type=revenue" class="btn-primary" style="white-space:nowrap;display:inline-flex;align-items:center;gap:7px;">
            ↓ Export CSV
          </a>
        </div>

        <div class="stat-grid">
          <div class="stat-card highlight"><span class="num" style="font-size:1.3rem;">GH₵ <?= number_format($totalCommissionEarned, 2) ?></span><span class="lbl">Commission Earned</span></div>
          <div class="stat-card highlight"><span class="num" style="font-size:1.3rem;">GH₵ <?= number_format($totalPayoutsReleased, 2) ?></span><span class="lbl">Payouts Released</span></div>
          <div class="stat-card"><span class="num" style="font-size:1.3rem;">GH₵ <?= number_format($totalTaxHeld, 2) ?></span><span class="lbl">Tax Held (GRA)</span></div>
          <div class="stat-card highlight"><span class="num" style="font-size:1.3rem;">GH₵ <?= number_format($featuredRevenue, 2) ?></span><span class="lbl">Featured Listings</span></div>
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
      </div>

      <!-- ════ API PARTNERS ════ -->
      <div id="api-partners" class="admin-panel">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">API & Integration Partners</h2>
        <p style="color:var(--warm-mid);font-size:0.95rem;margin-bottom:32px;">Manage third-party integrations, monitor usage, and configure credentials.</p>

        <?php if ($partnersMissing): ?>
          <div class="alert alert-error">
            Partners tables not found. Run <code>sql_partners_update.sql</code> in phpMyAdmin to set them up.
          </div>
        <?php elseif (empty($partners)): ?>
          <div class="alert alert-info">No API partners registered yet.</div>
        <?php else: ?>

          <div class="stat-grid">
            <div class="stat-card"><span class="num"><?= count($partners) ?></span><span class="lbl">Total Partners</span></div>
            <div class="stat-card"><span class="num"><?= count(array_filter($partners, fn($p) => $p['status'] === 'active')) ?></span><span class="lbl">Active</span></div>
            <?php if ($walletRow):
              $wBal   = (float)$walletRow['balance'];
              $wThresh = (float)$walletRow['low_balance_threshold'];
              $wLow   = $wBal < $wThresh;
            ?>
            <div class="stat-card" style="<?= $wLow ? 'border-color:#fca5a5;' : '' ?>">
              <span class="num" style="font-size:1.4rem;color:<?= $wLow ? '#991b1b' : '#0d9488' ?>;">GHS <?= number_format($wBal, 2, '.', ',') ?></span>
              <span class="lbl">Smile ID Wallet</span>
            </div>
            <?php endif; ?>
          </div>

          <?php foreach ($partners as $p):
            $isSmile = ($p['slug'] === 'smile_id');
            $sc = $statusColors[$p['status']] ?? $statusColors['active'];
          ?>
          <div class="partner-card">
            <div class="partner-header">
              <div class="partner-title">
                <h2><?= htmlspecialchars($p['name']) ?></h2>
                <div class="cat"><?= htmlspecialchars($p['category']) ?></div>
              </div>
              <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;">
                <?= htmlspecialchars(strtoupper($p['status'])) ?>
              </span>
            </div>

            <div class="partner-body">

              <div class="partner-section">
                <h3>About</h3>
                <p style="color:var(--warm-mid);line-height:1.6;font-size:0.92rem;"><?= htmlspecialchars($p['description'] ?? '') ?></p>
                <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:14px;font-size:0.85rem;color:var(--sand);">
                  <?php if ($p['contact_email']): ?>
                    <span>📧 <?= htmlspecialchars($p['contact_email']) ?></span>
                  <?php endif; ?>
                  <?php if ($p['contact_url']): ?>
                    <span>🔗 <a href="<?= htmlspecialchars($p['contact_url']) ?>" target="_blank" rel="noopener" style="color:var(--ember);"><?= htmlspecialchars($p['contact_url']) ?></a></span>
                  <?php endif; ?>
                  <?php if ($p['started_at']): ?>
                    <span>📅 Since <?= date('j M Y', strtotime($p['started_at'])) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($isSmile): ?>
              <div class="partner-section">
                <h3>Configuration</h3>
                <div class="field-row">
                  <div class="field-label">Integration Enabled</div>
                  <div class="field-value">
                    <?= $cfg['enabled'] === '1' ? '<span style="color:#166534;font-weight:700;">● Active</span>' : '<span style="color:#991b1b;font-weight:700;">● Disabled</span>' ?>
                    <span style="color:var(--sand);font-size:0.82rem;margin-left:10px;">When disabled, new verification requests skip the automated check.</span>
                  </div>
                  <form method="POST" action="admin_action.php">
                    <input type="hidden" name="action" value="smileid_toggle_enabled">
                    <button class="btn-secondary"><?= $cfg['enabled'] === '1' ? 'Disable' : 'Enable' ?></button>
                  </form>
                </div>
              </div>

              <?php if ($walletRow):
                $wBal    = (float)$walletRow['balance'];
                $wCost   = (float)$walletRow['cost_per_check'];
                $wThresh = (float)$walletRow['low_balance_threshold'];
                $wLow    = $wBal < $wThresh;
                $wColor  = $wLow ? '#991b1b' : '#0d9488';
              ?>
              <div class="partner-section">
                <h3>Wallet</h3>
                <?php if ($wLow): ?>
                  <div class="alert" style="background:#fef3c7;color:#92400e;border:1.5px solid #fde68a;margin-bottom:16px;">
                    Wallet balance is below GHS <?= number_format($wThresh, 2) ?>. Top up to avoid service interruption.
                  </div>
                <?php endif; ?>
                <div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;margin-bottom:20px;">
                  <div>
                    <div style="font-size:0.7rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--sand);margin-bottom:4px;">Current Balance</div>
                    <div style="font-family:'Sora',sans-serif;font-size:2.4rem;font-weight:900;letter-spacing:-0.04em;color:<?= $wColor ?>;">
                      <span style="font-size:0.95rem;font-weight:700;vertical-align:middle;">GHS</span> <?= number_format($wBal, 2, '.', ',') ?>
                    </div>
                  </div>
                  <div>
                    <div style="font-size:0.7rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--sand);margin-bottom:4px;">Cost Per Check</div>
                    <div style="font-size:1.1rem;font-weight:700;color:var(--bark);">GHS <?= number_format($wCost, 2) ?></div>
                  </div>
                  <div style="margin-left:auto;">
                    <a href="pay_partner.php?partner_id=<?= (int)$smileIdPartner['id'] ?>" class="btn-primary">Top Up Wallet</a>
                  </div>
                </div>

                <?php if (!empty($walletTxns)):
                  $pillStyle = [
                    'topup'  => ['bg'=>'#dcfce7','fg'=>'#166534'],
                    'charge' => ['bg'=>'#f1f5f9','fg'=>'#475569'],
                    'refund' => ['bg'=>'#fef3c7','fg'=>'#92400e'],
                  ];
                ?>
                <table class="log-table" style="margin-top:8px;">
                  <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Method</th><th>Reference</th></tr></thead>
                  <tbody>
                    <?php foreach ($walletTxns as $txn):
                      $ps = $pillStyle[$txn['type']] ?? $pillStyle['charge'];
                    ?>
                    <tr>
                      <td style="white-space:nowrap;font-size:0.8rem;color:var(--sand);"><?= date('j M, g:ia', strtotime($txn['created_at'])) ?></td>
                      <td><span class="log-mini-badge" style="background:<?= $ps['bg'] ?>;color:<?= $ps['fg'] ?>;"><?= htmlspecialchars($txn['type']) ?></span></td>
                      <td style="font-size:0.88rem;font-weight:600;color:var(--bark);">GHS <?= number_format((float)$txn['amount'], 2, '.', ',') ?></td>
                      <td style="font-size:0.88rem;color:var(--warm-mid);">GHS <?= number_format((float)$txn['balance_after'], 2, '.', ',') ?></td>
                      <td style="font-size:0.82rem;color:var(--sand);"><?= $txn['payment_method'] ? htmlspecialchars(strtoupper($txn['payment_method'])) : '&mdash;' ?></td>
                      <td style="font-size:0.75rem;color:var(--sand);"><?= $txn['reference'] ? '<code style="background:var(--parchment);padding:2px 6px;border-radius:4px;">'.htmlspecialchars($txn['reference']).'</code>' : '&mdash;' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <?php else: ?>
                  <p style="color:var(--sand);font-size:0.9rem;">No wallet transactions yet.</p>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <div class="partner-section">
                <h3>Usage</h3>
                <div class="breakdown-grid">
                  <?php foreach ([['today','Today'],['week','This Week'],['month','This Month'],['all','All Time']] as [$k,$label]):
                    $s = $stats[$k] ?? ['verified'=>0,'failed'=>0,'error'=>0,'total'=>0];
                  ?>
                  <div class="breakdown-tile">
                    <div class="w"><?= $label ?></div>
                    <div class="n"><?= $s['total'] ?></div>
                    <div class="parts">✓ <?= $s['verified'] ?> &nbsp; ✗ <?= $s['failed'] ?> &nbsp; ⚠ <?= $s['error'] ?></div>
                  </div>
                  <?php endforeach; ?>
                  <div class="breakdown-tile green">
                    <div class="w">Pass Rate (Month)</div>
                    <div class="n"><?= ($stats['month']['total'] ?? 0) > 0 ? round(($stats['month']['verified'] / $stats['month']['total']) * 100) . '%' : '—' ?></div>
                    <div class="parts"><?= $stats['month']['verified'] ?? 0 ?> verified / <?= $stats['month']['total'] ?? 0 ?> total</div>
                  </div>
                </div>
              </div>

              <div class="partner-section">
                <h3>Recent Activity</h3>
                <?php if (empty($log)): ?>
                  <p style="color:var(--sand);font-size:0.9rem;">No activity logged yet.</p>
                <?php else: ?>
                <table class="log-table">
                  <thead><tr><th>When</th><th>Action</th><th>Result</th><th>Summary</th><th>Reference</th></tr></thead>
                  <tbody>
                    <?php foreach ($log as $row):
                      $b = smileid_badge($row['status']);
                    ?>
                    <tr>
                      <td style="white-space:nowrap;font-size:0.8rem;color:var(--sand);"><?= date('j M, g:ia', strtotime($row['created_at'])) ?></td>
                      <td style="font-size:0.82rem;"><?= htmlspecialchars($row['action']) ?></td>
                      <td><span class="log-mini-badge" style="background:<?= $b['bg'] ?>;color:<?= $b['fg'] ?>;"><?= $b['icon'] ?> <?= ucfirst($row['status']) ?></span></td>
                      <td style="font-size:0.82rem;max-width:340px;"><?= htmlspecialchars($row['summary'] ?? '') ?></td>
                      <td style="font-size:0.75rem;color:var(--sand);"><?= $row['reference'] ? '<code style="background:var(--parchment);padding:2px 6px;border-radius:4px;">'.htmlspecialchars($row['reference']).'</code>' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <div class="partner-section">
                <h3>Admin Notes</h3>
                <form method="POST" action="admin_action.php">
                  <input type="hidden" name="action" value="update_partner_notes">
                  <input type="hidden" name="partner_id" value="<?= (int)$p['id'] ?>">
                  <textarea name="notes" rows="3" style="width:100%;padding:12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;resize:vertical;" placeholder="Internal notes about this partnership..."><?= htmlspecialchars($p['notes'] ?? '') ?></textarea>
                  <div style="margin-top:10px;"><button class="btn-primary">Save Notes</button></div>
                </form>
              </div>

            </div>
          </div>
          <?php endforeach; ?>

        <?php endif; ?>
      </div>

    </main>
  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

  <script>
    function showPanel(id, el) {
      document.querySelectorAll('.admin-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.admin-nav a').forEach(n => n.classList.remove('active'));
      document.getElementById(id).classList.add('active');
      el.classList.add('active');
    }

    function decBadge(id) {
      const b = document.getElementById(id);
      if (!b) return;
      const n = parseInt(b.textContent, 10) - 1;
      if (n <= 0) b.remove(); else b.textContent = n;
    }

    function toggleEditProvider(id) {
      const row = document.getElementById('edit-provider-' + id);
      row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }

    // Open to the right panel if hash is set (e.g. admin_partners.php#verification)
    (function() {
      const map = {
        'providers': true, 'verification': true, 'featured': true,
        'featured-requests': true, 'revenue': true, 'api-partners': true
      };
      const hash = location.hash.replace('#', '');
      if (hash && map[hash]) {
        const el = document.querySelector(`.admin-nav a[onclick*="'${hash}'"]`);
        if (el) showPanel(hash, el);
      }
    })();
  </script>

</body>
</html>
