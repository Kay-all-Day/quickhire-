<?php
// Returns the booked hours for a provider on a given date.
// Used by the booking form to warn on time conflicts.
// ?provider_id=X&date=YYYY-MM-DD
require_once '../includes/db.php';

header('Content-Type: application/json');

$provider_id = intval($_GET['provider_id'] ?? 0);
$date        = $_GET['date'] ?? '';

if ($provider_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT HOUR(booking_date) AS hour
    FROM bookings
    WHERE provider_id = ?
      AND DATE(booking_date) = ?
      AND status NOT IN ('cancelled')
");
$stmt->execute([$provider_id, $date]);
$hours = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'hour');

echo json_encode(array_values(array_map('intval', $hours)));
