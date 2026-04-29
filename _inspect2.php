<?php
require 'includes/db.php';
echo "=== provider_payouts ===\n";
try {
    $cols = $pdo->query('DESCRIBE provider_payouts')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'].' | '.$c['Type'].' | Key='.$c['Key'].' | Default='.$c['Default']."\n";
} catch (Throwable $e) { echo 'Error: '.$e->getMessage()."\n"; }
