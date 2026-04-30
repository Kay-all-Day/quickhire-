<?php
// TEMPORARY DEBUG FILE — remove after Railway deployment is confirmed working
header('Content-Type: text/plain');

echo "=== Apache + PHP OK ===\n\n";

echo "PHP version: " . PHP_VERSION . "\n\n";

echo "=== MySQL Env Vars ===\n";
$vars = ['MYSQLHOST', 'MYSQLDATABASE', 'MYSQLUSER', 'MYSQLPASSWORD', 'MYSQLPORT'];
foreach ($vars as $v) {
    $val = getenv($v);
    if ($v === 'MYSQLPASSWORD') {
        echo "$v = " . ($val !== false ? '(set, ' . strlen($val) . ' chars)' : '(NOT SET)') . "\n";
    } else {
        echo "$v = " . ($val !== false ? $val : '(NOT SET)') . "\n";
    }
}

echo "\n=== DB Connection Test ===\n";
$host   = getenv('MYSQLHOST')     ?: 'localhost';
$dbname = getenv('MYSQLDATABASE') ?: 'quickhire';
$user   = getenv('MYSQLUSER')     ?: 'root';
$pass   = getenv('MYSQLPASSWORD') ?: '';
$port   = getenv('MYSQLPORT')     ?: '3306';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "Connected OK\n";
    $row = $pdo->query("SELECT VERSION() AS v")->fetch();
    echo "MySQL version: " . $row['v'] . "\n";
} catch (PDOException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
