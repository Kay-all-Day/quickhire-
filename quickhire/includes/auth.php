<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUserType() {
    return $_SESSION['user_type'] ?? null;
}

function isProvider() {
    $type = getUserType();
    return $type === 'provider' || $type === 'both';
}

function isCustomer() {
    $type = getUserType();
    return $type === 'customer' || $type === 'both';
}

function isAdmin() {
    return getUserType() === 'admin';
}

function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

function getUserName() {
    return $_SESSION['full_name'] ?? 'Guest';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: auth.php');
        exit;
    }
}

function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Returns notification badge HTML for the nav bar.
 * Requires $pdo to be available. Returns empty string if not logged in.
 */
function getNavNotifBadge($pdo) {
    if (!isLoggedIn()) return '';
    require_once __DIR__ . '/notifications.php';
    $count = getUnreadCount($pdo, getUserId());
    $msgCount = getUnreadMessages($pdo, getUserId());
    $total = $count + $msgCount;
    if ($total <= 0) return '';
    return '<span style="background:var(--ember);color:#fff;font-size:0.6rem;padding:1px 6px;border-radius:10px;font-weight:700;margin-left:4px;vertical-align:super;">' . $total . '</span>';
}
