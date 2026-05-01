<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

if (!isProvider()) {
    $_SESSION['errors'] = ['Only providers can manage services.'];
    redirect('dashboard.php');
}

$user_id = getUserId();

// Get provider_id
$stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
$stmt->execute([$user_id]);
$provider = $stmt->fetch();

if (!$provider) {
    $_SESSION['errors'] = ['Provider profile not found.'];
    redirect('dashboard.php');
}

$provider_id = $provider['provider_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add':
            $name  = trim($_POST['service_name'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $price = floatval($_POST['price'] ?? 0);

            if (empty($name) || $price <= 0) {
                $_SESSION['errors'] = ['Service name and price are required.'];
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO services (provider_id, service_name, description, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$provider_id, $name, $desc, $price]);
            $_SESSION['success'] = "'$name' added to your services.";
            break;

        case 'update':
            $service_id = intval($_POST['service_id'] ?? 0);
            $name  = trim($_POST['service_name'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $price = floatval($_POST['price'] ?? 0);

            if ($service_id <= 0 || empty($name) || $price <= 0) {
                $_SESSION['errors'] = ['Invalid data.'];
                break;
            }

            // Verify ownership
            $stmt = $pdo->prepare("SELECT service_id FROM services WHERE service_id = ? AND provider_id = ?");
            $stmt->execute([$service_id, $provider_id]);
            if (!$stmt->fetch()) {
                $_SESSION['errors'] = ['Service not found.'];
                break;
            }

            $stmt = $pdo->prepare("UPDATE services SET service_name = ?, description = ?, price = ? WHERE service_id = ?");
            $stmt->execute([$name, $desc, $price, $service_id]);
            $_SESSION['success'] = "'$name' updated.";
            break;

        case 'delete':
            $service_id = intval($_POST['service_id'] ?? 0);

            // Verify ownership
            $stmt = $pdo->prepare("SELECT service_name FROM services WHERE service_id = ? AND provider_id = ?");
            $stmt->execute([$service_id, $provider_id]);
            $svc = $stmt->fetch();
            if (!$svc) {
                $_SESSION['errors'] = ['Service not found.'];
                break;
            }

            $stmt = $pdo->prepare("DELETE FROM services WHERE service_id = ? AND provider_id = ?");
            $stmt->execute([$service_id, $provider_id]);
            $_SESSION['success'] = "'" . $svc['service_name'] . "' deleted.";
            break;

        default:
            $_SESSION['errors'] = ['Unknown action.'];
    }
}

redirect('dashboard.php');
