<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/smileid.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ══════ TOGGLE FEATURED ══════
        case 'toggle_featured':
            $pid = intval($_POST['provider_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_featured FROM service_providers WHERE provider_id = ?");
            $stmt->execute([$pid]);
            $current = $stmt->fetch();
            if ($current) {
                $newVal = $current['is_featured'] ? 0 : 1;
                $stmt = $pdo->prepare("UPDATE service_providers SET is_featured = ? WHERE provider_id = ?");
                $stmt->execute([$newVal, $pid]);
                $_SESSION['success'] = $newVal ? 'Provider is now featured.' : 'Provider removed from featured.';
            }
            break;

        // ══════ TOGGLE VERIFIED ══════
        case 'toggle_verified':
            $pid = intval($_POST['provider_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_verified FROM service_providers WHERE provider_id = ?");
            $stmt->execute([$pid]);
            $current = $stmt->fetch();
            if ($current) {
                $newVal = $current['is_verified'] ? 0 : 1;
                $stmt = $pdo->prepare("UPDATE service_providers SET is_verified = ? WHERE provider_id = ?");
                $stmt->execute([$newVal, $pid]);
                $_SESSION['success'] = $newVal ? 'Provider verified.' : 'Verification removed.';
            }
            break;

        // ══════ DELETE USER ══════
        case 'delete_user':
            $uid = intval($_POST['user_id'] ?? 0);
            if ($uid === getUserId()) {
                $_SESSION['errors'] = ['You cannot delete your own admin account.'];
                break;
            }
            // Delete related data first (reviews, payments, bookings, provider)
            $pdo->prepare("DELETE r FROM reviews r JOIN bookings b ON r.booking_id = b.booking_id WHERE b.user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE p FROM payments p JOIN bookings b ON p.booking_id = b.booking_id WHERE b.user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM bookings WHERE user_id = ?")->execute([$uid]);
            // Provider side
            $pdo->prepare("DELETE r FROM reviews r JOIN bookings b ON r.booking_id = b.booking_id JOIN service_providers sp ON b.provider_id = sp.provider_id WHERE sp.user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE p FROM payments p JOIN bookings b ON p.booking_id = b.booking_id JOIN service_providers sp ON b.provider_id = sp.provider_id WHERE sp.user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE b FROM bookings b JOIN service_providers sp ON b.provider_id = sp.provider_id WHERE sp.user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM services WHERE provider_id IN (SELECT provider_id FROM service_providers WHERE user_id = ?)")->execute([$uid]);
            $pdo->prepare("DELETE FROM service_providers WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM reviews WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);
            $_SESSION['success'] = 'User and all related data deleted.';
            break;

        // ══════ UPDATE USER ══════
        case 'update_user':
            $uid       = intval($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $phone     = trim($_POST['phone'] ?? '');
            $user_type = $_POST['user_type'] ?? '';

            if (empty($full_name) || empty($email) || !in_array($user_type, ['customer', 'provider', 'admin', 'both'])) {
                $_SESSION['errors'] = ['Please fill in all fields correctly.'];
                break;
            }
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, user_type = ? WHERE user_id = ?");
            $stmt->execute([$full_name, $email, $phone, $user_type, $uid]);

            // If changed to provider/both but no provider row exists, create one
            if (in_array($user_type, ['provider', 'both'])) {
                $stmt = $pdo->prepare("SELECT provider_id FROM service_providers WHERE user_id = ?");
                $stmt->execute([$uid]);
                if (!$stmt->fetch()) {
                    $pdo->prepare("INSERT INTO service_providers (user_id, service_category) VALUES (?, 'General')")->execute([$uid]);
                }
            }
            $_SESSION['success'] = 'User updated successfully.';
            break;

        // ══════ UPDATE PROVIDER ══════
        case 'update_provider':
            $pid       = intval($_POST['provider_id'] ?? 0);
            $category  = trim($_POST['service_category'] ?? '');
            $exp       = intval($_POST['experience_years'] ?? 0);
            $bio       = trim($_POST['bio'] ?? '');
            $avail     = trim($_POST['availability'] ?? '');
            $langs     = trim($_POST['languages'] ?? '');
            $avgResp   = trim($_POST['avg_response'] ?? '');

            $stmt = $pdo->prepare("UPDATE service_providers SET service_category = ?, experience_years = ?, bio = ?, availability = ?, languages = ?, avg_response = ? WHERE provider_id = ?");
            $stmt->execute([$category, $exp, $bio, $avail, $langs, $avgResp, $pid]);
            $_SESSION['success'] = 'Provider profile updated.';
            break;

        // ══════ UPDATE BOOKING STATUS ══════
        case 'update_booking_status':
            $bid    = intval($_POST['booking_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            if (!in_array($status, ['pending', 'accepted', 'completed', 'cancelled'])) {
                $_SESSION['errors'] = ['Invalid booking status.'];
                break;
            }
            $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE booking_id = ?");
            $stmt->execute([$status, $bid]);
            $_SESSION['success'] = "Booking #$bid updated to $status.";
            break;

        // ══════ DELETE BOOKING ══════
        case 'delete_booking':
            $bid = intval($_POST['booking_id'] ?? 0);
            $pdo->prepare("DELETE FROM reviews WHERE booking_id = ?")->execute([$bid]);
            $pdo->prepare("DELETE FROM payments WHERE booking_id = ?")->execute([$bid]);
            $pdo->prepare("DELETE FROM bookings WHERE booking_id = ?")->execute([$bid]);
            $_SESSION['success'] = "Booking #$bid and related data deleted.";
            break;

        // ══════ UPDATE PAYMENT STATUS ══════
        case 'update_payment':
            $pid    = intval($_POST['payment_id'] ?? 0);
            $status = $_POST['payment_status'] ?? '';
            $method = $_POST['payment_method'] ?? '';

            if (!in_array($status, ['pending', 'completed'])) {
                $_SESSION['errors'] = ['Invalid payment status.'];
                break;
            }
            $stmt = $pdo->prepare("UPDATE payments SET payment_status = ?, payment_method = ? WHERE payment_id = ?");
            $stmt->execute([$status, $method, $pid]);
            $_SESSION['success'] = "Payment updated.";
            break;

        // ══════ RESET USER PASSWORD ══════
        case 'reset_password':
            $uid = intval($_POST['user_id'] ?? 0);
            $newPass = password_hash('password123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$newPass, $uid]);
            $_SESSION['success'] = "Password reset to 'password123'.";
            break;

        // ══════ ADD HOMEPAGE CATEGORY ══════
        case 'add_category':
            $name        = trim($_POST['name'] ?? '');
            $icon        = trim($_POST['icon'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $filter_key  = strtolower(trim($_POST['filter_key'] ?? ''));
            $order       = intval($_POST['display_order'] ?? 0);

            if (empty($name) || empty($icon) || empty($description) || empty($filter_key)) {
                $_SESSION['errors'] = ['All fields are required.'];
                break;
            }
            $stmt = $pdo->prepare("INSERT INTO homepage_categories (name, icon, description, filter_key, display_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $icon, $description, $filter_key, $order]);
            $_SESSION['success'] = "'$name' added to homepage categories.";
            break;

        // ══════ UPDATE HOMEPAGE CATEGORY ══════
        case 'update_category':
            $cid         = intval($_POST['category_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $icon        = trim($_POST['icon'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $filter_key  = strtolower(trim($_POST['filter_key'] ?? ''));
            $order       = intval($_POST['display_order'] ?? 0);

            if (empty($name) || empty($icon) || $cid <= 0) {
                $_SESSION['errors'] = ['Invalid data.'];
                break;
            }
            $stmt = $pdo->prepare("UPDATE homepage_categories SET name = ?, icon = ?, description = ?, filter_key = ?, display_order = ? WHERE id = ?");
            $stmt->execute([$name, $icon, $description, $filter_key, $order, $cid]);
            $_SESSION['success'] = "'$name' updated.";
            break;

        // ══════ TOGGLE CATEGORY VISIBILITY ══════
        case 'toggle_category_visible':
            $cid = intval($_POST['category_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_visible FROM homepage_categories WHERE id = ?");
            $stmt->execute([$cid]);
            $current = $stmt->fetch();
            if ($current) {
                $newVal = $current['is_visible'] ? 0 : 1;
                $pdo->prepare("UPDATE homepage_categories SET is_visible = ? WHERE id = ?")->execute([$newVal, $cid]);
                $_SESSION['success'] = $newVal ? 'Category is now visible on homepage.' : 'Category hidden from homepage.';
            }
            break;

        // ══════ DELETE HOMEPAGE CATEGORY ══════
        case 'delete_category':
            $cid = intval($_POST['category_id'] ?? 0);
            $pdo->prepare("DELETE FROM homepage_categories WHERE id = ?")->execute([$cid]);
            $_SESSION['success'] = 'Category deleted.';
            break;

        // ══════ APPROVE FEATURED REQUEST ══════
        case 'approve_featured':
            $rid = intval($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM featured_requests WHERE id = ?");
            $stmt->execute([$rid]);
            $req = $stmt->fetch();

            if (!$req) {
                $_SESSION['errors'] = ['Request not found.'];
                break;
            }
            if ($req['payment_status'] !== 'completed') {
                $_SESSION['errors'] = ['Cannot approve — payment not completed.'];
                break;
            }

            $now = date('Y-m-d H:i:s');
            $expires = date('Y-m-d H:i:s', strtotime("+{$req['duration_days']} days"));

            // Update request
            $stmt = $pdo->prepare("UPDATE featured_requests SET request_status = 'approved', approved_at = ?, expires_at = ? WHERE id = ?");
            $stmt->execute([$now, $expires, $rid]);

            // Make provider featured
            $stmt = $pdo->prepare("UPDATE service_providers SET is_featured = 1 WHERE provider_id = ?");
            $stmt->execute([$req['provider_id']]);

            $_SESSION['success'] = "Featured request approved! Provider is now featured until " . date('j M Y', strtotime($expires)) . ".";
            break;

        // ══════ REJECT FEATURED REQUEST ══════
        case 'reject_featured':
            $rid = intval($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM featured_requests WHERE id = ?");
            $stmt->execute([$rid]);
            $req = $stmt->fetch();

            if (!$req) {
                $_SESSION['errors'] = ['Request not found.'];
                break;
            }

            $stmt = $pdo->prepare("UPDATE featured_requests SET request_status = 'rejected' WHERE id = ?");
            $stmt->execute([$rid]);

            $_SESSION['success'] = 'Featured request rejected.';
            break;

        // ══════ APPROVE VERIFICATION ══════
        case 'approve_verification':
            $rid = intval($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM verification_requests WHERE id = ?");
            $stmt->execute([$rid]);
            $req = $stmt->fetch();

            if (!$req) {
                $_SESSION['errors'] = ['Verification request not found.'];
                break;
            }

            $now = date('Y-m-d H:i:s');

            // Update request
            $stmt = $pdo->prepare("UPDATE verification_requests SET status = 'approved', reviewed_at = ? WHERE id = ?");
            $stmt->execute([$now, $rid]);

            // Mark provider as verified
            $stmt = $pdo->prepare("UPDATE service_providers SET is_verified = 1 WHERE provider_id = ?");
            $stmt->execute([$req['provider_id']]);

            $_SESSION['success'] = 'Provider verified successfully!';
            break;

        // ══════ REJECT VERIFICATION ══════
        case 'reject_verification':
            $rid = intval($_POST['request_id'] ?? 0);
            $admin_notes = trim($_POST['admin_notes'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM verification_requests WHERE id = ?");
            $stmt->execute([$rid]);
            $req = $stmt->fetch();

            if (!$req) {
                $_SESSION['errors'] = ['Verification request not found.'];
                break;
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE verification_requests SET status = 'rejected', admin_notes = ?, reviewed_at = ? WHERE id = ?");
            $stmt->execute([$admin_notes, $now, $rid]);

            $_SESSION['success'] = 'Verification request rejected.';
            break;

        // ══════ MARK FEEDBACK READ ══════
        case 'mark_feedback_read':
            $fid = intval($_POST['feedback_id'] ?? 0);
            if ($fid > 0) {
                $stmt = $pdo->prepare("UPDATE platform_feedback SET is_read = 1 WHERE id = ?");
                $stmt->execute([$fid]);
                $_SESSION['success'] = 'Feedback marked as read.';
            }
            break;

        // ══════ REPLY TO FEEDBACK ══════
        case 'reply_feedback':
            $fid = intval($_POST['feedback_id'] ?? 0);
            $uid = intval($_POST['user_id'] ?? 0);
            $reply = trim($_POST['admin_reply'] ?? '');
            if ($fid > 0 && !empty($reply)) {
                try {
                    $stmt = $pdo->prepare("UPDATE platform_feedback SET admin_reply = ?, is_read = 1 WHERE id = ?");
                    $stmt->execute([$reply, $fid]);

                    // Notify the user
                    require_once 'includes/notifications.php';
                    createNotification($pdo, $uid, 'support', 'QuickHire Support Response', 'We\'ve responded to your feedback: "' . substr($reply, 0, 80) . '..."', 'dashboard.php');

                    $_SESSION['success'] = 'Reply sent and user notified.';
                } catch (Exception $e) {
                    $_SESSION['errors'] = ['Could not save reply. Please run: ALTER TABLE platform_feedback ADD COLUMN admin_reply TEXT DEFAULT NULL AFTER message;'];
                }
            }
            break;

        // ══════ SMILE ID: TOGGLE ENABLED ══════
        case 'smileid_toggle_enabled':
            smileid_set_config('enabled', smileid_get_config('enabled') === '1' ? '0' : '1');
            $_SESSION['success'] = 'Smile ID integration toggled.';
            redirect('admin_partners.php');

        // ══════ SMILE ID: RE-VERIFY A VERIFICATION REQUEST ══════
        case 'reverify_smileid':
            $rid = intval($_POST['request_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT vr.*, u.full_name
                FROM verification_requests vr
                JOIN service_providers sp ON vr.provider_id = sp.provider_id
                JOIN users u ON sp.user_id = u.user_id
                WHERE vr.id = ?
            ");
            $stmt->execute([$rid]);
            $req = $stmt->fetch();
            if (!$req) {
                $_SESSION['errors'] = ['Verification request not found.'];
                break;
            }
            $r = smileid_verify_id($req['id_type'], $req['id_number'], $req['full_name']);
            $stmt = $pdo->prepare("
                UPDATE verification_requests
                SET smileid_status = ?, smileid_summary = ?, smileid_reference = ?,
                    smileid_response = ?, smileid_checked_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$r['status'], $r['summary'], $r['reference'], $r['response'], $rid]);
            $_SESSION['success'] = 'Re-verified with Smile ID — ' . $r['summary'];
            break;

        // ══════ UPDATE PARTNER NOTES ══════
        case 'update_partner_notes':
            $pid = intval($_POST['partner_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            if ($pid > 0) {
                $pdo->prepare("UPDATE partners SET notes = ? WHERE id = ?")->execute([$notes, $pid]);
                $_SESSION['success'] = 'Partner notes saved.';
            }
            redirect('admin_partners.php');

        default:
            $_SESSION['errors'] = ['Unknown action.'];
    }
}

redirect('admin.php');
