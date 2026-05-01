<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

requireLogin();

$user_id = getUserId();
$chat_with = intval($_GET['with'] ?? 0);
$booking_id = intval($_GET['booking'] ?? 0);

// Get all conversations (unique users this person has messaged with)
$stmt = $pdo->prepare("
    SELECT u.user_id, u.full_name, 
           (SELECT message FROM messages WHERE (sender_id = ? AND receiver_id = u.user_id) OR (sender_id = u.user_id AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1) as last_message,
           (SELECT created_at FROM messages WHERE (sender_id = ? AND receiver_id = u.user_id) OR (sender_id = u.user_id AND receiver_id = ?) ORDER BY created_at DESC LIMIT 1) as last_time,
           (SELECT COUNT(*) FROM messages WHERE sender_id = u.user_id AND receiver_id = ? AND is_read = 0) as unread
    FROM users u
    WHERE u.user_id != ? AND (
        u.user_id IN (SELECT receiver_id FROM messages WHERE sender_id = ?)
        OR u.user_id IN (SELECT sender_id FROM messages WHERE receiver_id = ?)
    )
    ORDER BY last_time DESC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$conversations = $stmt->fetchAll();

// If chatting with someone, get the messages
$chatMessages = [];
$chatPartner = null;
if ($chat_with > 0) {
    // Get partner info
    $stmt = $pdo->prepare("SELECT user_id, full_name FROM users WHERE user_id = ?");
    $stmt->execute([$chat_with]);
    $chatPartner = $stmt->fetch();

    if ($chatPartner) {
        // Get messages
        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name as sender_name
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$user_id, $chat_with, $chat_with, $user_id]);
        $chatMessages = $stmt->fetchAll();

        // Mark messages as read
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
        $stmt->execute([$chat_with, $user_id]);
    }
}

$initials_fn = function($name) {
    $parts = explode(' ', $name);
    $i = '';
    foreach ($parts as $p) $i .= strtoupper(substr($p, 0, 1));
    return $i;
};

$notifCount = getUnreadCount($pdo, $user_id);
$msgCount = getUnreadMessages($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Messages — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .msg-layout { display: grid; grid-template-columns: 300px 1fr; min-height: calc(100vh - var(--header-h) - 80px); max-width: 1200px; margin: 0 auto; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-top: 24px; margin-bottom: 24px; }
    .msg-sidebar { background: var(--card-bg); border-right: 1px solid var(--border); overflow-y: auto; }
    .msg-sidebar-header { padding: 20px; border-bottom: 1px solid var(--border); }
    .msg-sidebar-header h2 { font-family: 'Sora', sans-serif; font-size: 1.2rem; font-weight: 800; }
    .msg-conv { display: flex; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.15s; text-decoration: none; color: inherit; }
    .msg-conv:hover { background: var(--cream); }
    .msg-conv.active { background: rgba(13,148,136,0.06); border-left: 3px solid var(--ember); }
    .msg-conv-avatar { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, var(--ember), #06b6d4); color: #fff; font-family: 'Sora', sans-serif; font-weight: 700; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .msg-conv-info { flex: 1; min-width: 0; }
    .msg-conv-name { font-weight: 700; font-size: 0.88rem; margin-bottom: 2px; display: flex; justify-content: space-between; }
    .msg-conv-preview { font-size: 0.78rem; color: var(--sand); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .msg-conv-time { font-size: 0.68rem; color: var(--sand); }
    .msg-conv-unread { background: var(--ember); color: #fff; font-size: 0.65rem; font-weight: 700; padding: 2px 7px; border-radius: 10px; }

    .msg-chat { display: flex; flex-direction: column; background: var(--cream); }
    .msg-chat-header { padding: 16px 24px; background: var(--card-bg); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
    .msg-chat-header h3 { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; }
    .msg-chat-body { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 12px; max-height: 500px; }
    .msg-bubble { max-width: 70%; padding: 12px 16px; border-radius: 14px; font-size: 0.88rem; line-height: 1.55; animation: fadeUp 0.2s ease-out; }
    .msg-bubble.sent { background: var(--ember); color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; }
    .msg-bubble.received { background: var(--card-bg); color: var(--bark); align-self: flex-start; border-bottom-left-radius: 4px; border: 1px solid var(--border); }
    .msg-bubble-time { font-size: 0.68rem; color: var(--sand); margin-top: 4px; }
    .msg-bubble.sent .msg-bubble-time { color: rgba(255,255,255,0.6); text-align: right; }

    .msg-chat-input { padding: 16px 24px; background: var(--card-bg); border-top: 1px solid var(--border); }
    .msg-chat-input form { display: flex; gap: 10px; }
    .msg-chat-input input { flex: 1; padding: 12px 16px; border: 1.5px solid var(--border); border-radius: 10px; font-family: 'Outfit', sans-serif; font-size: 0.9rem; outline: none; transition: border-color 0.2s; }
    .msg-chat-input input:focus { border-color: var(--ember); }
    .msg-chat-input button { padding: 12px 24px; background: var(--ember); color: #fff; border: none; border-radius: 10px; font-weight: 700; font-size: 0.82rem; cursor: pointer; letter-spacing: 0.04em; text-transform: uppercase; transition: all 0.2s; }
    .msg-chat-input button:hover { background: var(--ember-dk); }

    .msg-empty { display: flex; align-items: center; justify-content: center; flex-direction: column; height: 100%; color: var(--sand); text-align: center; padding: 40px; }
    .msg-empty span { font-size: 3rem; margin-bottom: 16px; }

    @media (max-width: 768px) { .msg-layout { grid-template-columns: 1fr; } .msg-sidebar { max-height: 200px; } }
  </style>
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <a href="messages.php" style="position:relative;">Messages<?php if ($msgCount > 0): ?><span style="position:absolute;top:-4px;right:-8px;background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 5px;border-radius:10px;font-weight:700;"><?= $msgCount ?></span><?php endif; ?></a>
        <a href="notifications.php" style="position:relative;">🔔<?php if ($notifCount > 0): ?><span style="position:absolute;top:-4px;right:-8px;background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 5px;border-radius:10px;font-weight:700;"><?= $notifCount ?></span><?php endif; ?></a>
        <a href="dashboard.php">Dashboard<?= getNavNotifBadge($pdo) ?></a>
        <a href="logout.php">Logout</a>
      </div>
    </nav>
  </header>

  <div style="max-width:1200px;margin:0 auto;padding:0 24px;">
    <div class="msg-layout">

      <!-- Sidebar: conversations -->
      <div class="msg-sidebar">
        <div class="msg-sidebar-header">
          <h2>Messages</h2>
        </div>
        <?php if (empty($conversations) && !$chatPartner): ?>
          <div style="padding:30px 20px;text-align:center;color:var(--sand);font-size:0.88rem;">
            <p>No conversations yet.</p>
            <p style="margin-top:8px;font-size:0.82rem;">Messages will appear here when you communicate with a provider or customer.</p>
          </div>
        <?php endif; ?>
        <?php foreach ($conversations as $c): ?>
          <a href="messages.php?with=<?= $c['user_id'] ?>" class="msg-conv <?= $chat_with == $c['user_id'] ? 'active' : '' ?>">
            <div class="msg-conv-avatar"><?= $initials_fn($c['full_name']) ?></div>
            <div class="msg-conv-info">
              <div class="msg-conv-name">
                <span><?= htmlspecialchars($c['full_name']) ?></span>
                <?php if ($c['unread'] > 0): ?><span class="msg-conv-unread"><?= $c['unread'] ?></span><?php endif; ?>
              </div>
              <div class="msg-conv-preview"><?= htmlspecialchars(substr($c['last_message'] ?? '', 0, 50)) ?></div>
              <?php if ($c['last_time']): ?>
                <div class="msg-conv-time"><?= date('j M, g:i A', strtotime($c['last_time'])) ?></div>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Chat area -->
      <div class="msg-chat">
        <?php if ($chatPartner): ?>
          <div class="msg-chat-header">
            <div class="msg-conv-avatar"><?= $initials_fn($chatPartner['full_name']) ?></div>
            <h3><?= htmlspecialchars($chatPartner['full_name']) ?></h3>
          </div>
          <div class="msg-chat-body" id="chatBody">
            <?php if (empty($chatMessages)): ?>
              <div class="msg-empty">
                <span>💬</span>
                <p>Start the conversation with <?= htmlspecialchars(explode(' ', $chatPartner['full_name'])[0]) ?></p>
              </div>
            <?php endif; ?>
            <?php foreach ($chatMessages as $m): ?>
              <div class="msg-bubble <?= $m['sender_id'] == $user_id ? 'sent' : 'received' ?>">
                <?= htmlspecialchars($m['message']) ?>
                <div class="msg-bubble-time"><?= date('j M, g:i A', strtotime($m['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="msg-chat-input">
            <form action="send_message.php" method="POST">
              <input type="hidden" name="receiver_id" value="<?= $chatPartner['user_id'] ?>">
              <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
              <input type="text" name="message" placeholder="Type a message…" required autocomplete="off">
              <button type="submit">Send</button>
            </form>
          </div>
        <?php else: ?>
          <div class="msg-empty">
            <span>💬</span>
            <p style="font-weight:700;font-size:1.1rem;color:var(--bark);margin-bottom:8px;">Your Messages</p>
            <p>Select a conversation or start a new one from a provider's profile.</p>
          </div>
        <?php endif; ?>
      </div>

    </div>
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

  <script>
    // Auto-scroll to bottom of chat
    const chatBody = document.getElementById('chatBody');
    if (chatBody) chatBody.scrollTop = chatBody.scrollHeight;
  </script>
</body>
</html>
