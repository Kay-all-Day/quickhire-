<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

$provider_id = intval($_GET['provider_id'] ?? 0);

if ($provider_id > 0) {
    $stmt = $pdo->prepare("
        SELECT service_id, service_name, description, price
        FROM services
        WHERE provider_id = ?
        ORDER BY price ASC
    ");
    $stmt->execute([$provider_id]);
} else {
    $stmt = $pdo->query("
        SELECT s.service_id, s.service_name, s.description, s.price,
               u.full_name AS provider_name, sp.provider_id
        FROM services s
        JOIN service_providers sp ON s.provider_id = sp.provider_id
        JOIN users u ON sp.user_id = u.user_id
        ORDER BY sp.rating DESC
    ");
}

$services = $stmt->fetchAll();
echo json_encode($services);
