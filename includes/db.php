<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Works on both XAMPP (local) and Railway (production)
$host     = getenv('MYSQLHOST')     ?: 'localhost';
$dbname   = getenv('MYSQLDATABASE') ?: 'quickhire';
$username = getenv('MYSQLUSER')     ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: '';
$port     = getenv('MYSQLPORT')     ?: '3306';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}