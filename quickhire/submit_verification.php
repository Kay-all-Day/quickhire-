<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireLogin();

if (!isProvider()) {
    $_SESSION['errors'] = ['Only providers can submit verification.'];
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = getUserId();
    $id_type  = $_POST['id_type'] ?? '';
    $id_number = trim($_POST['id_number'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    // Get provider_id
    $stmt = $pdo->prepare("SELECT provider_id, is_verified FROM service_providers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $provider = $stmt->fetch();

    if (!$provider) {
        $_SESSION['errors'] = ['Provider profile not found.'];
        redirect('dashboard.php');
    }

    if ($provider['is_verified']) {
        $_SESSION['errors'] = ['You are already verified.'];
        redirect('dashboard.php');
    }

    // Check for existing pending request
    $stmt = $pdo->prepare("SELECT id FROM verification_requests WHERE provider_id = ? AND status = 'pending'");
    $stmt->execute([$provider['provider_id']]);
    if ($stmt->fetch()) {
        $_SESSION['errors'] = ['You already have a pending verification request.'];
        redirect('dashboard.php');
    }

    // Validate
    $validTypes = ['ghana_card', 'passport', 'voters_id', 'drivers_license', 'nhis'];
    if (!in_array($id_type, $validTypes)) {
        $_SESSION['errors'] = ['Please select a valid ID type.'];
        redirect('dashboard.php');
    }
    if (empty($id_number)) {
        $_SESSION['errors'] = ['ID number is required.'];
        redirect('dashboard.php');
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/uploads/verification/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $documentPath = null;
    $certPath = null;

    // Handle ID document upload
    if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['id_document'];

        // Validate size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            $_SESSION['errors'] = ['ID document must be under 5MB.'];
            redirect('dashboard.php');
        }

        // Validate type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        if (!in_array($file['type'], $allowedTypes)) {
            $_SESSION['errors'] = ['Invalid file type. Please upload JPG, PNG, or PDF.'];
            redirect('dashboard.php');
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'id_' . $provider['provider_id'] . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $documentPath = 'uploads/verification/' . $filename;
        } else {
            $_SESSION['errors'] = ['Failed to upload ID document. Please try again.'];
            redirect('dashboard.php');
        }
    } else {
        $_SESSION['errors'] = ['Please upload your ID document.'];
        redirect('dashboard.php');
    }

    // Handle certificate upload (optional)
    if (isset($_FILES['certificate']) && $_FILES['certificate']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['certificate'];

        if ($file['size'] <= 5 * 1024 * 1024) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            if (in_array($file['type'], $allowedTypes)) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'cert_' . $provider['provider_id'] . '_' . time() . '.' . $ext;
                $destination = $uploadDir . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $certPath = 'uploads/verification/' . $filename;
                }
            }
        }
    }

    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO verification_requests (provider_id, id_type, id_number, document_path, cert_path, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$provider['provider_id'], $id_type, $id_number, $documentPath, $certPath, $notes]);

    // Notify all admins
    require_once 'includes/notifications.php';
    $admins = $pdo->query("SELECT user_id FROM users WHERE user_type = 'admin'")->fetchAll();
    $providerName = getUserName();
    foreach ($admins as $admin) {
        createNotification($pdo, $admin['user_id'], 'verification', 'New Verification Request', $providerName . ' has submitted documents for verification. Review and approve.', 'admin.php');
    }

    $_SESSION['success'] = 'Verification documents submitted! Our team will review within 48 hours.';
    redirect('dashboard.php');
}

redirect('dashboard.php');
