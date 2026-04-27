<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if (empty($query)) {
    echo json_encode(['providers' => [], 'services' => []]);
    exit;
}

$search = "%$query%";

// Search providers
$stmt = $pdo->prepare("
    SELECT sp.provider_id, u.full_name, sp.service_category, sp.rating, sp.bio, sp.is_verified
    FROM service_providers sp
    JOIN users u ON sp.user_id = u.user_id
    WHERE u.full_name LIKE ? OR sp.service_category LIKE ? OR sp.bio LIKE ?
    ORDER BY sp.rating DESC
    LIMIT 10
");
$stmt->execute([$search, $search, $search]);
$providers = $stmt->fetchAll();

// Search services
$stmt = $pdo->prepare("
    SELECT s.service_id, s.service_name, s.description, s.price, 
           u.full_name AS provider_name, sp.provider_id, sp.rating
    FROM services s
    JOIN service_providers sp ON s.provider_id = sp.provider_id
    JOIN users u ON sp.user_id = u.user_id
    WHERE s.service_name LIKE ? OR s.description LIKE ?
    ORDER BY sp.rating DESC
    LIMIT 10
");
$stmt->execute([$search, $search]);
$services = $stmt->fetchAll();

echo json_encode(['providers' => $providers, 'services' => $services]);
