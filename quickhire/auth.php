<?php
require_once 'includes/auth.php';

if (isLoggedIn()) { redirect('dashboard.php'); }

$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old_input'] ?? [];
$success = $_SESSION['success'] ?? '';
// Capture redirect destination (from GET param or session)
$redirect_to = $_GET['redirect_to'] ?? $_SESSION['redirect_to'] ?? '';
unset($_SESSION['errors'], $_SESSION['old_input'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login / Register — QuickHire</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .pw-wrap { position: relative; }
    .pw-wrap input { width: 100%; padding-right: 44px; }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; font-size: 1.1rem;
      color: var(--sand); padding: 4px; line-height: 1;
    }
    .pw-toggle:hover { color: var(--bark); }
  </style>
</head>
<body>

  <header>
    <nav>
      <a href="index.php" class="nav-brand">Quick<span>Hire</span></a>
      <div class="nav-links">
        <a href="categories.php">Services</a>
        <a href="auth.php">Login</a>
        <a href="auth.php?tab=register" class="cta">Register</a>
      </div>
    </nav>
  </header>

  <div class="auth-page-wrap">
    <div class="auth-tabs-wrap">

      <?php if (!empty($errors)): ?>
        <div style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:0.88rem;">
          <p><?= htmlspecialchars($success) ?></p>
        </div>
      <?php endif; ?>

      <div class="auth-tabs">
        <button class="auth-tab active" onclick="switchTab('login', this)">Login</button>
        <button class="auth-tab" onclick="switchTab('register', this)">Register</button>
      </div>

      <!-- Login Panel -->
      <div id="login" class="auth-panel active">
        <div class="auth-card">
          <h2>Welcome back</h2>
          <p class="subtitle">Sign in to your QuickHire account</p>
          <form action="login.php" method="POST" novalidate>
            <?php if (!empty($redirect_to)): ?>
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">
            <?php endif; ?>
            <div class="form-group">
              <input type="email" name="email" placeholder="Email address" required autocomplete="email">
              <div class="pw-wrap">
                <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="button" class="pw-toggle" onclick="togglePw(this)">👁</button>
              </div>
            </div>
            <button type="submit" class="form-submit">Login →</button>
          </form>
          <p style="text-align:center;margin-top:20px;font-size:0.84rem;color:var(--sand);">
            Don't have an account? <a href="#" onclick="switchTab('register', document.querySelectorAll('.auth-tab')[1])" style="color:var(--ember);font-weight:700;">Register here</a>
          </p>
        </div>
      </div>

      <!-- Register Panel -->
      <div id="register" class="auth-panel">
        <div class="auth-card">
          <h2>Create account</h2>
          <p class="subtitle">Join thousands of providers and customers</p>
          <form action="register.php" method="POST" novalidate>
            <?php if (!empty($redirect_to)): ?>
              <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">
            <?php endif; ?>
            <div class="form-group">
              <input type="text" name="full_name" placeholder="Full name" required autocomplete="name" value="<?= htmlspecialchars($old['full_name'] ?? '') ?>">
              <input type="email" name="email" placeholder="Email address" required autocomplete="email" value="<?= htmlspecialchars($old['email'] ?? '') ?>">
              <input type="tel" name="phone" placeholder="Phone number" required value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
              <div class="pw-wrap">
                <input type="password" name="password" placeholder="Create password" required autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePw(this)">👁</button>
              </div>
              <div class="pw-wrap">
                <input type="password" name="confirm_password" placeholder="Confirm password" required autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePw(this)">👁</button>
              </div>
              <select name="user_type">
                <option value="" disabled <?= empty($old['user_type']) ? 'selected' : '' ?>>I am a…</option>
                <option value="customer" <?= ($old['user_type'] ?? '') === 'customer' ? 'selected' : '' ?>>Customer</option>
                <option value="provider" <?= ($old['user_type'] ?? '') === 'provider' ? 'selected' : '' ?>>Service Provider</option>
                <option value="both" <?= ($old['user_type'] ?? '') === 'both' ? 'selected' : '' ?>>Both (Customer & Provider)</option>
              </select>
            </div>
            <button type="submit" class="form-submit">Create Account →</button>
          </form>
          <p style="text-align:center;margin-top:20px;font-size:0.84rem;color:var(--sand);">
            Already have an account? <a href="#" onclick="switchTab('login', document.querySelectorAll('.auth-tab')[0])" style="color:var(--ember);font-weight:700;">Login here</a>
          </p>
        </div>
      </div>

    </div>
  </div>

  <footer>
    <p><strong>QuickHire</strong> — Connecting Ghana, one job at a time. &copy; 2026</p>
  </footer>

  <script>
    function switchTab(panelId, btn) {
      document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.auth-tab').forEach(b => b.classList.remove('active'));
      document.getElementById(panelId).classList.add('active');
      btn.classList.add('active');
    }

    var tab = new URLSearchParams(window.location.search).get('tab') || 'login';
    var tabIndex = tab === 'register' ? 1 : 0;
    switchTab(tab, document.querySelectorAll('.auth-tab')[tabIndex]);

    function togglePw(btn) {
      const input = btn.parentElement.querySelector('input');
      if (input.type === 'password') { input.type = 'text'; btn.textContent = '🙈'; }
      else { input.type = 'password'; btn.textContent = '👁'; }
    }
  </script>

</body>
</html>
