<?php
// Show errors on screen (remove this in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database connection for QuickHire
$host     = 'localhost';
$dbname   = 'quickhire';
$username = 'root';
$password = '';  // ← Put your MySQL root password here

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
