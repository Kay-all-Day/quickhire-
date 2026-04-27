<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/smileid.php';

requireAdmin();

$errors      = $_SESSION['errors'] ?? [];
$success     = $_SESSION['success'] ?? '';
unset($_SESSION['errors'], $_SESSION['success']);

// ══════════ FETCH PARTNERS ══════════
$partnersMissing = false;
try {
    $partners = $pdo->query("SELECT * FROM partners ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $partners = [];
    $partnersMissing = true;
}

// Load full Smile ID detail view
$smileIdPartner = null;
foreach ($partners as $p) {
    if ($p['slug'] === 'smile_id') { $smileIdPartner = $p; break; }
}
$cfg = smileid_config();

// ══════════ USAGE STATS ══════════
$stats = ['today' => null, 'week' => null, 'month' => null, 'all' => null];
$log   = [];
if ($smileIdPartner) {
    $windows = [
        'today' => "DATE(created_at) = CURDATE()",
        'week'  => "YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)",
        'month' => "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
        'all'   => "1=1",
    ];
    foreach ($windows as $k => $where) {
        try {
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) AS cnt FROM partner_activity_log
                WHERE partner_id = ? AND action = 'verify_id' AND $where
                GROUP BY status
            ");
            $stmt->execute([$smileIdPartner['id']]);
            $s = ['verified' => 0, 'failed' => 0, 'error' => 0, 'total' => 0];
            foreach ($stmt->fetchAll() as $row) {
                $s[$row['status']] = (int)$row['cnt'];
                $s['total'] += (int)$row['cnt'];
            }
            $stats[$k] = $s;
        } catch (Throwable $e) {}
    }
    // Recent log (last 20)
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM partner_activity_log
            WHERE partner_id = ? ORDER BY created_at DESC LIMIT 20
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
        $stmt = $pdo->prepare("SELECT * FROM partner_wallet WHERE partner_id = ? LIMIT 1");
        $stmt->execute([$smileIdPartner['id']]);
        $walletRow = $stmt->fetch() ?: null;
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM partner_transactions
            WHERE partner_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$smileIdPartner['id']]);
        $walletTxns = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

$statusColors = [
    'active'     => ['bg' => '#dcfce7', 'fg' => '#166534'],
    'paused'     => ['bg' => '#fef3c7', 'fg' => '#92400e'],
    'terminated' => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Partners — QuickHire Admin</title>
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
    .admin-main { padding: 48px; background: var(--cream); }
    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .stat-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 22px 20px; }
    .stat-card .num { font-family: 'Sora', sans-serif; font-size: 2rem; font-weight: 900; color: var(--bark); letter-spacing: -0.05em; display: block; }
    .stat-card .lbl { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--sand); }
    .stat-card.highlight { background: var(--bark); border-color: var(--bark); }
    .stat-card.highlight .num { color: var(--ember); }
    .stat-card.highlight .lbl { color: rgba(245,240,232,0.5); }

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
    .text-input:focus { outline: 2px solid var(--ember); outline-offset: -1px; }
    .btn-primary { padding: 10px 18px; font-size: 0.82rem; font-weight: 700; background: var(--ember); color: #fff; border: none; border-radius: 8px; cursor: pointer; letter-spacing: 0.03em; text-transform: uppercase; }
    .btn-primary:hover { opacity: 0.9; }
    .btn-secondary { padding: 10px 18px; font-size: 0.82rem; font-weight: 700; background: #fff; color: var(--bark); border: 1.5px solid var(--border); border-radius: 8px; cursor: pointer; letter-spacing: 0.03em; text-transform: uppercase; }
    .btn-secondary:hover { background: var(--parchment); }
    .btn-danger { padding: 10px 18px; font-size: 0.82rem; font-weight: 700; background: #fee2e2; color: #991b1b; border: 1.5px solid #fecaca; border-radius: 8px; cursor: pointer; letter-spacing: 0.03em; text-transform: uppercase; }

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
    .alert-error { background: #fee2e2; color: #991b1b; border: 1.5px solid #fca5a5; }
    .alert-info { background: #dbeafe; color: #1e40af; border: 1.5px solid #93c5fd; }

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
        <p>Admin Panel</p>
      </div>
      <div class="admin-nav">
        <a href="admin.php">📊 Overview</a>
        <a href="admin.php">👥 Users</a>
        <a href="admin.php">🛠 Providers</a>
        <a href="admin.php">📅 Bookings</a>
        <a href="admin.php">💰 Revenue</a>
        <a href="admin.php">📈 Analytics</a>
        <a href="admin.php">⭐ Featured</a>
        <a href="admin.php">✅ Verification</a>
        <a href="admin.php">📦 Categories</a>
        <a href="admin.php">📝 Feedback</a>
        <a href="admin_partners.php" class="active">🤝 Partners</a>
      </div>
    </aside>

    <main class="admin-main">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <div style="margin-bottom:32px;">
        <h2 style="font-family:'Sora',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-0.04em;margin-bottom:6px;">Partnership Management</h2>
        <p style="color:var(--warm-mid);font-size:0.95rem;">Manage third-party integrations, monitor usage, and configure credentials.</p>
      </div>

      <?php if ($partnersMissing): ?>
        <div class="alert alert-error">
          Partners tables not found in database. Run <code>sql_partners_update.sql</code> in phpMyAdmin to set them up.
        </div>
      <?php elseif (empty($partners)): ?>
        <div class="alert alert-info">No partners registered yet.</div>
      <?php else: ?>

        <!-- Summary stats -->
        <div class="stat-grid">
          <div class="stat-card">
            <span class="num"><?= count($partners) ?></span>
            <span class="lbl">Total Partners</span>
          </div>
          <div class="stat-card">
            <span class="num"><?= count(array_filter($partners, fn($p) => $p['status'] === 'active')) ?></span>
            <span class="lbl">Active</span>
          </div>
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

        <?php foreach ($partners as $p): ?>
          <?php
            $isSmile = ($p['slug'] === 'smile_id');
            $sc = $statusColors[$p['status']] ?? $statusColors['active'];
          ?>
          <div class="partner-card">

            <!-- Header -->
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

              <!-- About -->
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
                <!-- Configuration -->
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

                <!-- Wallet -->
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
                      <a href="pay_partner.php?partner_id=<?= (int)$smileIdPartner['id'] ?>" class="btn-primary" style="text-decoration:none;display:inline-block;">Top Up Wallet</a>
                    </div>
                  </div>

                  <?php if (!empty($walletTxns)): ?>
                    <?php
                      $pillStyle = [
                        'topup'  => ['bg' => '#dcfce7', 'fg' => '#166534'],
                        'charge' => ['bg' => '#f1f5f9', 'fg' => '#475569'],
                        'refund' => ['bg' => '#fef3c7', 'fg' => '#92400e'],
                      ];
                    ?>
                    <table class="log-table" style="margin-top:8px;">
                      <thead><tr>
                        <th>Date</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Method</th><th>Reference</th>
                      </tr></thead>
                      <tbody>
                        <?php foreach ($walletTxns as $txn):
                          $ps = $pillStyle[$txn['type']] ?? $pillStyle['charge'];
                        ?>
                          <tr>
                            <td style="white-space:nowrap;font-size:0.8rem;color:var(--sand);"><?= date('j M, g:ia', strtotime($txn['created_at'])) ?></td>
                            <td>
                              <span class="log-mini-badge" style="background:<?= $ps['bg'] ?>;color:<?= $ps['fg'] ?>;">
                                <?= htmlspecialchars($txn['type']) ?>
                              </span>
                            </td>
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

                <!-- Usage -->
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

                <!-- Activity log -->
                <div class="partner-section">
                  <h3>Recent Activity</h3>
                  <?php if (empty($log)): ?>
                    <p style="color:var(--sand);font-size:0.9rem;">No activity logged yet. Activity will appear here as providers submit ID verifications.</p>
                  <?php else: ?>
                    <table class="log-table">
                      <thead><tr>
                        <th>When</th><th>Action</th><th>Result</th><th>Summary</th><th>Reference</th>
                      </tr></thead>
                      <tbody>
                        <?php foreach ($log as $row):
                          $b = smileid_badge($row['status']);
                        ?>
                          <tr>
                            <td style="white-space:nowrap;font-size:0.8rem;color:var(--sand);"><?= date('j M, g:ia', strtotime($row['created_at'])) ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['action']) ?></td>
                            <td>
                              <span class="log-mini-badge" style="background:<?= $b['bg'] ?>;color:<?= $b['fg'] ?>;">
                                <?= $b['icon'] ?> <?= ucfirst($row['status']) ?>
                              </span>
                            </td>
                            <td style="font-size:0.82rem;max-width:340px;"><?= htmlspecialchars($row['summary'] ?? '') ?></td>
                            <td style="font-size:0.75rem;color:var(--sand);"><?= $row['reference'] ? '<code style="background:var(--parchment);padding:2px 6px;border-radius:4px;">'.htmlspecialchars($row['reference']).'</code>' : '—' ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <!-- Notes (editable) -->
              <div class="partner-section">
                <h3>Admin Notes</h3>
                <form method="POST" action="admin_action.php">
                  <input type="hidden" name="action" value="update_partner_notes">
                  <input type="hidden" name="partner_id" value="<?= (int)$p['id'] ?>">
                  <textarea name="notes" rows="3" style="width:100%;padding:12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:0.9rem;resize:vertical;" placeholder="Internal notes about this partnership..."><?= htmlspecialchars($p['notes'] ?? '') ?></textarea>
                  <div style="margin-top:10px;">
                    <button class="btn-primary">Save Notes</button>
                  </div>
                </form>
              </div>

            </div>
          </div>
        <?php endforeach; ?>

      <?php endif; ?>

    </main>
  </div>

</body>
</html>
