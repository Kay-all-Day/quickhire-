<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

requireAdmin();

$type = $_GET['type'] ?? '';
$allowed = ['revenue', 'providers', 'analytics'];
if (!in_array($type, $allowed, true)) {
    http_response_code(400);
    exit('Invalid export type.');
}

$commissionRate = 0.10;
$date = date('Y-m-d');

if ($type === 'revenue') {

    $rows = $pdo->query("
        SELECT u.full_name, sp.service_category, sp.is_verified, sp.is_featured,
               COUNT(b.booking_id) AS total_jobs,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount ELSE 0 END), 0)                          AS gross_earned,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount * $commissionRate ELSE 0 END), 0)         AS commission_paid,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount * (1 - $commissionRate) ELSE 0 END), 0)  AS net_payout
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        LEFT JOIN bookings b ON b.provider_id = sp.provider_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        GROUP BY sp.provider_id
        ORDER BY gross_earned DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalsCommission = (float)$pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn()
                      + (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM provider_commissions WHERE status='paid'")->fetchColumn();
    $totalsPayouts    = (float)$pdo->query("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
    $totalsTax        = (float)$pdo->query("SELECT COALESCE(SUM(tax_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
    $totalsFeatured   = (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM featured_requests WHERE payment_status='completed'")->fetchColumn();

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=quickhire_revenue_{$date}.csv");

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($out, ['QuickHire — Revenue & Earnings Report', "Generated: $date"]);
    fputcsv($out, []);

    // Platform summary
    fputcsv($out, ['PLATFORM SUMMARY']);
    fputcsv($out, ['Commission Earned (GHS)', number_format($totalsCommission, 2)]);
    fputcsv($out, ['Payouts Released (GHS)',  number_format($totalsPayouts, 2)]);
    fputcsv($out, ['Tax Held for GRA (GHS)',  number_format($totalsTax, 2)]);
    fputcsv($out, ['Featured Listing Revenue (GHS)', number_format($totalsFeatured, 2)]);
    fputcsv($out, []);

    // Per-provider table
    fputcsv($out, ['PROVIDER EARNINGS BREAKDOWN']);
    fputcsv($out, ['Provider', 'Category', 'Total Jobs', 'Gross Earned (GHS)', "Commission ({$commissionRate}%) (GHS)", 'Net Payout (GHS)', 'Verified', 'Featured']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['full_name'],
            $r['service_category'],
            $r['total_jobs'],
            number_format((float)$r['gross_earned'],   2),
            number_format((float)$r['commission_paid'], 2),
            number_format((float)$r['net_payout'],      2),
            $r['is_verified'] ? 'Yes' : 'No',
            $r['is_featured'] ? 'Yes' : 'No',
        ]);
    }

    fclose($out);

} elseif ($type === 'providers') {

    $rows = $pdo->query("
        SELECT sp.provider_id, u.full_name, u.email, u.phone,
               sp.service_category, sp.rating, sp.experience_years,
               sp.is_verified, sp.is_featured,
               (SELECT COUNT(*) FROM bookings WHERE provider_id = sp.provider_id) AS job_count,
               (SELECT COALESCE(SUM(p.amount), 0)
                FROM payments p JOIN bookings b ON p.booking_id = b.booking_id
                WHERE b.provider_id = sp.provider_id AND p.payment_status = 'completed') AS total_earned
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        ORDER BY sp.rating DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=quickhire_providers_{$date}.csv");

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['QuickHire — Provider Oversight Report', "Generated: $date"]);
    fputcsv($out, []);
    fputcsv($out, ['ID', 'Full Name', 'Email', 'Phone', 'Category', 'Rating', 'Experience (yrs)', 'Total Jobs', 'Total Earned (GHS)', 'Verified', 'Featured']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['provider_id'],
            $r['full_name'],
            $r['email'],
            $r['phone'],
            $r['service_category'],
            $r['rating'],
            $r['experience_years'],
            $r['job_count'],
            number_format((float)$r['total_earned'], 2),
            $r['is_verified'] ? 'Yes' : 'No',
            $r['is_featured'] ? 'Yes' : 'No',
        ]);
    }

    fclose($out);

} elseif ($type === 'analytics') {

    $commissionRate = 0.10;

    // KPI
    $totalBookings     = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    $completedBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='completed'")->fetchColumn();
    $conversionRate    = $totalBookings > 0 ? round($completedBookings / $totalBookings * 100, 1) : 0;
    $avgBookingValue   = (float)$pdo->query("SELECT ROUND(AVG(amount),2) FROM payments WHERE payment_status='completed'")->fetchColumn();
    $reviewStats       = $pdo->query("SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM reviews")->fetch(PDO::FETCH_ASSOC);

    // Bookings by status
    $bookingsByStatus = $pdo->query("SELECT status, COUNT(*) AS count FROM bookings GROUP BY status ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Top categories
    $topCategories = $pdo->query("
        SELECT sp.service_category, COUNT(b.booking_id) AS total_bookings,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount ELSE 0 END), 0) AS revenue
        FROM service_providers sp
        LEFT JOIN bookings b ON b.provider_id = sp.provider_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        GROUP BY sp.service_category
        ORDER BY total_bookings DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // User growth (last 6 months)
    $userGrowth = array_reverse($pdo->query("
        SELECT DATE_FORMAT(created_at,'%Y-%m') AS month, COUNT(*) AS new_users,
               SUM(user_type='customer') AS customers,
               SUM(user_type IN ('provider','both')) AS providers
        FROM users
        GROUP BY month ORDER BY month DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC));

    // Monthly revenue (last 6 months)
    $monthlyRevenue = array_reverse($pdo->query("
        SELECT DATE_FORMAT(b.booking_date,'%Y-%m') AS month,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount ELSE 0 END), 0) AS revenue,
               COUNT(b.booking_id) AS bookings
        FROM bookings b
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        GROUP BY month ORDER BY month DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC));

    // Peak booking hours
    $peakHours = $pdo->query("SELECT HOUR(booking_date) AS hour, COUNT(*) AS count FROM bookings GROUP BY hour ORDER BY hour")->fetchAll(PDO::FETCH_ASSOC);

    // Top providers by revenue
    $topProviders = $pdo->query("
        SELECT u.full_name, sp.service_category, sp.rating,
               COUNT(b.booking_id) AS jobs,
               COALESCE(SUM(CASE WHEN p.payment_status='completed' THEN p.amount ELSE 0 END), 0) AS revenue
        FROM service_providers sp
        JOIN users u ON sp.user_id = u.user_id
        LEFT JOIN bookings b ON b.provider_id = sp.provider_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        GROUP BY sp.provider_id
        ORDER BY revenue DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=quickhire_analytics_{$date}.csv");

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['QuickHire — Business Analytics Report', "Generated: $date"]);
    fputcsv($out, []);

    // KPI Summary
    fputcsv($out, ['KPI SUMMARY']);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Total Bookings',         $totalBookings]);
    fputcsv($out, ['Completed Bookings',     $completedBookings]);
    fputcsv($out, ['Completion Rate (%)',    $conversionRate]);
    fputcsv($out, ['Avg Booking Value (GHS)', number_format($avgBookingValue, 2)]);
    fputcsv($out, ['Avg Platform Rating',    $reviewStats['avg_rating'] ?? 'N/A']);
    fputcsv($out, ['Total Reviews',          $reviewStats['total'] ?? 0]);
    fputcsv($out, []);

    // Bookings by status
    fputcsv($out, ['BOOKINGS BY STATUS']);
    fputcsv($out, ['Status', 'Count']);
    foreach ($bookingsByStatus as $r) {
        fputcsv($out, [ucfirst($r['status']), $r['count']]);
    }
    fputcsv($out, []);

    // Top categories
    fputcsv($out, ['TOP CATEGORIES']);
    fputcsv($out, ['Category', 'Total Bookings', 'Revenue (GHS)']);
    foreach ($topCategories as $r) {
        fputcsv($out, [$r['service_category'], $r['total_bookings'], number_format((float)$r['revenue'], 2)]);
    }
    fputcsv($out, []);

    // Monthly revenue
    fputcsv($out, ['MONTHLY REVENUE (LAST 6 MONTHS)']);
    fputcsv($out, ['Month', 'Revenue (GHS)', 'Bookings']);
    foreach ($monthlyRevenue as $r) {
        fputcsv($out, [date('M Y', strtotime($r['month'] . '-01')), number_format((float)$r['revenue'], 2), $r['bookings']]);
    }
    fputcsv($out, []);

    // User growth
    fputcsv($out, ['USER GROWTH (LAST 6 MONTHS)']);
    fputcsv($out, ['Month', 'New Users', 'Customers', 'Providers']);
    foreach ($userGrowth as $r) {
        fputcsv($out, [date('M Y', strtotime($r['month'] . '-01')), $r['new_users'], $r['customers'], $r['providers']]);
    }
    fputcsv($out, []);

    // Peak hours
    fputcsv($out, ['PEAK BOOKING HOURS']);
    fputcsv($out, ['Hour', 'Bookings']);
    foreach ($peakHours as $r) {
        fputcsv($out, [$r['hour'] . ':00 - ' . ($r['hour'] + 1) . ':00', $r['count']]);
    }
    fputcsv($out, []);

    // Top providers
    fputcsv($out, ['TOP PROVIDERS BY REVENUE']);
    fputcsv($out, ['Provider', 'Category', 'Rating', 'Jobs', 'Revenue (GHS)']);
    foreach ($topProviders as $r) {
        fputcsv($out, [$r['full_name'], $r['service_category'], $r['rating'], $r['jobs'], number_format((float)$r['revenue'], 2)]);
    }

    fclose($out);
}
