<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

$user_id = getUserId();

// Mark all as read if requested
if (isset($_GET['mark_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
    header("Location: notifications.php");
    exit;
}

// Get notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

$unreadCount = getUnreadCount($pdo, $user_id);
$msgCount = getUnreadMessages($pdo, $user_id);

$icons = [
    'booking'       => '📅',
    'payment'       => '💳',
    'review'        => '⭐',
    'message'       => '💬',
    'verification'  => '✅',
    'featured'      => '🌟',
    'loyalty'       => '🎁',
    'referral'      => '🤝',
    'system'        => '🔔',
    'booking_accepted' => '✓',
    'booking_completed' => '🏁',
    'booking_declined' => '✗',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notifications — QuickHire</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <a href="messages.php" style="position:relative;">Messages<?php if ($msgCount > 0): ?><span style="position:absolute;top:-4px;right:-8px;background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 5px;border-radius:10px;font-weight:700;"><?= $msgCount ?></span><?php endif; ?></a>
        <a href="notifications.php" style="position:relative;">🔔<?php if ($unreadCount > 0): ?><span style="position:absolute;top:-4px;right:-8px;background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 5px;border-radius:10px;font-weight:700;"><?= $unreadCount ?></span><?php endif; ?></a>
        <a href="dashboard.php">Dashboard<?= getNavNotifBadge($pdo) ?></a>
        <a href="logout.php">Logout</a>
      </div>
    </nav>
  </header>

  <div style="max-width:700px;margin:40px auto;padding:0 24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <div>
        <h2 style="font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-0.03em;">Notifications</h2>
        <?php if ($unreadCount > 0): ?>
          <p style="font-size:0.85rem;color:var(--sand);"><?= $unreadCount ?> unread</p>
        <?php endif; ?>
      </div>
      <?php if ($unreadCount > 0): ?>
        <a href="notifications.php?mark_read=1" style="font-size:0.82rem;color:var(--ember);font-weight:600;text-decoration:none;">Mark all as read</a>
      <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
      <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:48px;text-align:center;">
        <span style="font-size:2.5rem;display:block;margin-bottom:12px;">🔔</span>
        <p style="font-weight:700;color:var(--bark);">No notifications yet</p>
        <p style="font-size:0.85rem;color:var(--sand);margin-top:4px;">You'll be notified about bookings, messages, and more.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($notifications as $n): ?>
          <a href="<?= htmlspecialchars($n['link'] ?? '#') ?>" style="display:flex;gap:14px;padding:16px 20px;background:<?= $n['is_read'] ? 'var(--card-bg)' : 'rgba(13,148,136,0.04)' ?>;border:1px solid <?= $n['is_read'] ? 'var(--border)' : 'rgba(13,148,136,0.15)' ?>;border-radius:10px;text-decoration:none;color:inherit;transition:all 0.2s;<?= !$n['is_read'] ? 'border-left:3px solid var(--ember);' : '' ?>">
            <span style="font-size:1.4rem;flex-shrink:0;"><?= $icons[$n['type']] ?? '🔔' ?></span>
            <div style="flex:1;min-width:0;">
              <p style="font-weight:700;font-size:0.9rem;color:var(--bark);margin-bottom:2px;"><?= htmlspecialchars($n['title']) ?></p>
              <p style="font-size:0.82rem;color:var(--warm-mid);line-height:1.5;"><?= htmlspecialchars($n['message']) ?></p>
              <p style="font-size:0.72rem;color:var(--sand);margin-top:6px;"><?= date('j M Y, g:i A', strtotime($n['created_at'])) ?></p>
            </div>
            <?php if (!$n['is_read']): ?>
              <span style="width:8px;height:8px;border-radius:50%;background:var(--ember);flex-shrink:0;margin-top:6px;"></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

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
</body>
</html>
