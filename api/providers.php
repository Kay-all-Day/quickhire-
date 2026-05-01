<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

$category = trim($_GET['category'] ?? '');

if (!empty($category)) {
    $stmt = $pdo->prepare("
        SELECT sp.provider_id, u.full_name, sp.service_category, sp.experience_years, 
               sp.bio, sp.rating, sp.availability, sp.is_verified, sp.is_featured
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        WHERE sp.service_category LIKE ?
        ORDER BY sp.is_featured DESC, sp.rating DESC
    ");
    $stmt->execute(["%$category%"]);
} else {
    $stmt = $pdo->query("
        SELECT sp.provider_id, u.full_name, sp.service_category, sp.experience_years, 
               sp.bio, sp.rating, sp.availability, sp.is_verified, sp.is_featured
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        ORDER BY sp.is_featured DESC, sp.rating DESC
    ");
}

$providers = $stmt->fetchAll();
echo json_encode($providers);
