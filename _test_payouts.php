<?php
require 'includes/db.php';

// ── helpers ──────────────────────────────────────────────────────────────────
function ok($label)   { echo "[PASS] $label\n"; }
function fail($label) { echo "[FAIL] $label\n"; }
function check($cond, $label) { $cond ? ok($label) : fail($label); }

// ── pick a provider and customer ─────────────────────────────────────────────
$provider = $pdo->query("SELECT sp.provider_id, sp.user_id FROM service_providers sp LIMIT 1")->fetch();
$customer = $pdo->query("SELECT user_id FROM users WHERE user_type='customer' LIMIT 1")->fetch();
$service  = $pdo->query("SELECT service_id, price FROM services WHERE provider_id={$provider['provider_id']} LIMIT 1")->fetch();

if (!$provider || !$customer || !$service) {
    die("Not enough seed data to run test.\n");
}

$provId  = $provider['provider_id'];
$custId  = $customer['user_id'];
$svcId   = $service['service_id'];
$price   = (float)$service['price'];

$gross      = round($price * 1.20, 2);
$tax        = round($price * 0.20, 2);
$commission = round($price * 0.10, 2);
$payout     = round($price - $commission, 2);

echo "Base price: GHS $price  |  Gross: $gross  |  Commission: $commission  |  Tax: $tax  |  Payout: $payout\n";
check(abs(($payout + $commission + $tax) - $gross) < 0.01, "payout+commission+tax == gross");

// ── simulate a card booking ───────────────────────────────────────────────────
$pdo->exec("INSERT INTO bookings (user_id, provider_id, service_id, booking_date, address, status) VALUES ($custId, $provId, $svcId, NOW(), 'Test Street, Accra', 'completed')");
$cardBookingId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO payments (booking_id, amount, payment_status) VALUES ($cardBookingId, $price, 'pending')");
$cardPaymentId = (int)$pdo->lastInsertId();

$payoutRef = 'PAYOUT-' . strtoupper(bin2hex(random_bytes(4)));
$pdo->beginTransaction();
$pdo->prepare("UPDATE payments SET payment_method='card', payment_status='completed', amount=? WHERE payment_id=?")->execute([$gross, $cardPaymentId]);
$pdo->prepare("INSERT INTO provider_payouts (booking_id,provider_id,gross_amount,commission_amount,tax_amount,payout_amount,payment_method,payout_method,payout_reference,status,released_at) VALUES (?,?,?,?,?,?,'card','platform',?,'released',NOW())")->execute([$cardBookingId,$provId,$gross,$commission,$tax,$payout,$payoutRef]);
$pdo->commit();

$row = $pdo->query("SELECT * FROM provider_payouts WHERE booking_id=$cardBookingId")->fetch();
check($row !== false, "Card: provider_payouts row exists");
check(abs((float)$row['gross_amount']      - $gross)      < 0.01, "Card: gross_amount correct");
check(abs((float)$row['commission_amount'] - $commission) < 0.01, "Card: commission_amount correct");
check(abs((float)$row['tax_amount']        - $tax)        < 0.01, "Card: tax_amount correct");
check(abs((float)$row['payout_amount']     - $payout)     < 0.01, "Card: payout_amount correct");
check($row['status'] === 'released',                               "Card: status=released");

// ── simulate a MoMo booking ───────────────────────────────────────────────────
$pdo->exec("INSERT INTO bookings (user_id, provider_id, service_id, booking_date, address, status) VALUES ($custId, $provId, $svcId, NOW(), 'Test Lane, Kumasi', 'completed')");
$momoBookingId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO payments (booking_id, amount, payment_status) VALUES ($momoBookingId, $price, 'pending')");
$momoPaymentId = (int)$pdo->lastInsertId();

$payoutRef2 = 'PAYOUT-' . strtoupper(bin2hex(random_bytes(4)));
$pdo->beginTransaction();
$pdo->prepare("UPDATE payments SET payment_method='mobile_money', payment_status='completed', amount=? WHERE payment_id=?")->execute([$gross, $momoPaymentId]);
$pdo->prepare("INSERT INTO provider_payouts (booking_id,provider_id,gross_amount,commission_amount,tax_amount,payout_amount,payment_method,payout_method,payout_reference,status,released_at) VALUES (?,?,?,?,?,?,'mobile_money','platform',?,'released',NOW())")->execute([$momoBookingId,$provId,$gross,$commission,$tax,$payout,$payoutRef2]);
$pdo->commit();

$row2 = $pdo->query("SELECT * FROM provider_payouts WHERE booking_id=$momoBookingId")->fetch();
check($row2 !== false,                        "MoMo: provider_payouts row exists");
check($row2['payment_method'] === 'mobile_money', "MoMo: payment_method recorded");

// ── simulate a cash booking ───────────────────────────────────────────────────
$pdo->exec("INSERT INTO bookings (user_id, provider_id, service_id, booking_date, address, status) VALUES ($custId, $provId, $svcId, NOW(), 'Cash Road, Takoradi', 'completed')");
$cashBookingId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO payments (booking_id, amount, payment_status) VALUES ($cashBookingId, $price, 'pending')");
$cashPaymentId = (int)$pdo->lastInsertId();
$cashComm = round($price * 0.10, 2);

$pdo->prepare("UPDATE payments SET payment_method='cash', payment_status='completed', amount=? WHERE payment_id=?")->execute([$gross, $cashPaymentId]);
$pdo->prepare("INSERT INTO provider_commissions (booking_id, provider_id, amount) VALUES (?,?,?)")->execute([$cashBookingId,$provId,$cashComm]);

$noPayoutRow = $pdo->query("SELECT * FROM provider_payouts WHERE booking_id=$cashBookingId")->fetch();
$commRow     = $pdo->query("SELECT * FROM provider_commissions WHERE booking_id=$cashBookingId")->fetch();
check($noPayoutRow === false, "Cash: no provider_payouts row");
check($commRow !== false,     "Cash: provider_commissions row exists");
check(abs((float)$commRow['amount'] - $cashComm) < 0.01, "Cash: commission amount correct");

// ── admin totals check ────────────────────────────────────────────────────────
$totalReleased = (float)$pdo->query("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
$totalCommCard = (float)$pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();
$totalCommCash = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM provider_commissions WHERE status='paid'")->fetchColumn();
$totalTax      = (float)$pdo->query("SELECT COALESCE(SUM(tax_amount),0) FROM provider_payouts WHERE status='released'")->fetchColumn();

echo "\nAdmin totals after test:\n";
echo "  Payouts Released : GHS " . number_format($totalReleased, 2) . "\n";
echo "  Commission Earned: GHS " . number_format($totalCommCard + $totalCommCash, 2) . "  (card+momo: $totalCommCard  |  cash paid: $totalCommCash)\n";
echo "  Tax Held (GRA)   : GHS " . number_format($totalTax, 2) . "\n";

// provider earnings tab check
$provEarnings = (float)$pdo->prepare("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE provider_id=? AND status='released'")->execute([$provId]) ? $pdo->query("SELECT COALESCE(SUM(payout_amount),0) FROM provider_payouts WHERE provider_id=$provId AND status='released'")->fetchColumn() : 0;
echo "  Provider $provId earnings: GHS " . number_format((float)$provEarnings, 2) . "\n";

echo "\nDone.\n";
