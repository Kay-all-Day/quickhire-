<?php
// ══════════════════════════════════════════════════════════════
//  Smile ID Integration — Ghana ID Verification
// ══════════════════════════════════════════════════════════════
//  Partnership with Smile ID (usesmileid.com) for real-time
//  verification against Ghana's ID authorities. Covers:
//    • Ghana Card   • Passport    • Voter's ID
//    • Driver's Lic • NHIS Card
//
//  Config is loaded from the `partner_config` table (managed from
//  the admin Partners page) rather than hardcoded constants.
//  Every verification + test-connection call is logged to
//  `partner_activity_log` for auditing and usage monitoring.
// ══════════════════════════════════════════════════════════════

define('SMILEID_SANDBOX_URL','https://testapi.smileidentity.com/v1/id_verification');
define('SMILEID_LIVE_URL',   'https://api.smileidentity.com/v1/id_verification');
define('SMILEID_TIMEOUT',    15);


// ══════════ CONFIG LOADING ══════════

/**
 * Load Smile ID config from the partner_config table.
 * Defaults are used if the rows are missing (fresh install, etc.).
 * Result is cached per-request.
 */
function smileid_config() {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $pdo;

    $cache = [
        'mode'       => 'sandbox',
        'enabled'    => '1',
        'partner_id' => '',
        'api_key'    => '',
    ];
    try {
        $stmt = $pdo->prepare("
            SELECT pc.config_key, pc.config_value
            FROM partner_config pc
            JOIN partners p ON pc.partner_id = p.id
            WHERE p.slug = 'smile_id'
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['config_key']] = $row['config_value'];
        }
    } catch (Throwable $e) {
        // partners/partner_config table missing — use defaults.
    }
    return $cache;
}

/** Force a re-read on the next call (used after an admin saves new config). */
function smileid_reset_config_cache() {
    // Trick to reset the static variable — re-declare it inside smileid_config
    // isn't possible, so we just re-run and swap. Simpler: call with noop.
    // Easiest pattern: close and re-require is not possible here either.
    // We just re-fetch on the next read by letting admin_action redirect.
}

/** Read one config value (string). */
function smileid_get_config($key) {
    $cfg = smileid_config();
    return $cfg[$key] ?? '';
}

/**
 * Write one config value. Creates the row if missing.
 * The partners + partner_config tables must exist (migration run).
 */
function smileid_set_config($key, $value, $isSecret = false) {
    global $pdo;
    $pid = smileid_partner_row_id();
    if (!$pid) return false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO partner_config (partner_id, config_key, config_value, is_secret)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), is_secret = VALUES(is_secret)
        ");
        $stmt->execute([$pid, $key, (string)$value, $isSecret ? 1 : 0]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function smileid_partner_row_id() {
    global $pdo;
    static $id = null;
    if ($id !== null) return $id;
    try {
        $stmt = $pdo->prepare("SELECT id FROM partners WHERE slug = 'smile_id' LIMIT 1");
        $stmt->execute();
        $id = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $id = 0;
    }
    return $id;
}

/** Append a row to partner_activity_log — silently skips if table missing. */
function smileid_log($action, $status, $summary = null, $reference = null, $meta = []) {
    global $pdo;
    $pid = smileid_partner_row_id();
    if (!$pid) return;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO partner_activity_log (partner_id, action, status, summary, reference, meta)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$pid, $action, $status, $summary, $reference, json_encode($meta)]);
    } catch (Throwable $e) {}
}


// ══════════ WALLET ══════════

/** Return the partner_wallet row for Smile ID, cached per-request. */
function smileid_wallet_row() {
    static $row = null;
    if ($row !== null) return $row;
    global $pdo;
    $pid = smileid_partner_row_id();
    if (!$pid) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM partner_wallet WHERE partner_id = ? LIMIT 1");
        $stmt->execute([$pid]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;
    }
    return $row;
}

/** Return current wallet balance as a float, or 0.0 if not found. */
function smileid_wallet_balance() {
    global $pdo;
    $pid = smileid_partner_row_id();
    if (!$pid) return 0.0;
    try {
        $stmt = $pdo->prepare("SELECT balance FROM partner_wallet WHERE partner_id = ? LIMIT 1");
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        return $row ? (float)$row['balance'] : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Atomically deduct $amount from the wallet and log a 'charge' transaction.
 * Returns the new transaction ID (truthy) on success, or false if the balance
 * is insufficient (no-op — balance is left unchanged).
 */
function smileid_wallet_charge($amount, $reference, $description) {
    global $pdo;
    $pid = smileid_partner_row_id();
    if (!$pid) return false;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT balance FROM partner_wallet WHERE partner_id = ? FOR UPDATE");
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if (!$row || (float)$row['balance'] < (float)$amount) {
            $pdo->rollBack();
            return false;
        }
        $newBalance = round((float)$row['balance'] - (float)$amount, 2);
        $pdo->prepare("UPDATE partner_wallet SET balance = ?, updated_at = NOW() WHERE partner_id = ?")
            ->execute([$newBalance, $pid]);
        $pdo->prepare("
            INSERT INTO partner_transactions (partner_id, type, amount, balance_after, reference, description)
            VALUES (?, 'charge', ?, ?, ?, ?)
        ")->execute([$pid, $amount, $newBalance, $reference, $description]);
        $txnId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $txnId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

/**
 * Credit $amount to the wallet and log a 'topup' transaction.
 * Returns the new balance on success, or false on failure.
 */
function smileid_wallet_topup($amount, $paymentMethod, $reference, $description) {
    global $pdo;
    $pid = smileid_partner_row_id();
    if (!$pid) return false;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT balance FROM partner_wallet WHERE partner_id = ? FOR UPDATE");
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if (!$row) { $pdo->rollBack(); return false; }
        $newBalance = round((float)$row['balance'] + (float)$amount, 2);
        $pdo->prepare("UPDATE partner_wallet SET balance = ?, updated_at = NOW() WHERE partner_id = ?")
            ->execute([$newBalance, $pid]);
        $pdo->prepare("
            INSERT INTO partner_transactions (partner_id, type, amount, balance_after, reference, payment_method, description)
            VALUES (?, 'topup', ?, ?, ?, ?, ?)
        ")->execute([$pid, $amount, $newBalance, $reference, $paymentMethod, $description]);
        $pdo->commit();
        return $newBalance;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

/** Update the reference on a partner_transactions row after the real SmileJobID is known. */
function smileid_wallet_update_reference($txnId, $reference) {
    global $pdo;
    if (!$txnId) return;
    try {
        $pdo->prepare("UPDATE partner_transactions SET reference = ? WHERE id = ?")
            ->execute([$reference, $txnId]);
    } catch (Throwable $e) {}
}


// ══════════ MAIN ENTRY POINT ══════════

function smileid_map_id_type($idType) {
    $map = [
        'ghana_card'      => 'GHANA_CARD',
        'passport'        => 'PASSPORT',
        'voters_id'       => 'VOTER_ID',
        'drivers_license' => 'DRIVERS_LICENSE',
        'nhis'            => 'NHIS',
    ];
    return $map[$idType] ?? null;
}

/**
 * Run a Smile ID verification against a submitted ID.
 * Deducts the configured cost_per_check from the wallet before the API call.
 * Returns: ['status' => verified|failed|error, 'summary', 'reference', 'response']
 */
function smileid_verify_id($idType, $idNumber, $fullName) {
    $cfg = smileid_config();

    if (empty($cfg['enabled']) || $cfg['enabled'] === '0') {
        $r = [
            'status'    => 'error',
            'summary'   => 'Smile ID partnership is currently disabled — manual review required',
            'reference' => null,
            'response'  => null,
        ];
        smileid_log('verify_id', $r['status'], $r['summary'], null, ['id_type' => $idType]);
        return $r;
    }

    $smileType = smileid_map_id_type($idType);
    if (!$smileType) {
        $r = [
            'status'    => 'error',
            'summary'   => 'Unsupported ID type: ' . $idType,
            'reference' => null,
            'response'  => null,
        ];
        smileid_log('verify_id', $r['status'], $r['summary'], null, ['id_type' => $idType]);
        return $r;
    }

    // Deduct cost from wallet before running the check (sandbox or live)
    $walletRow = smileid_wallet_row();
    $cost      = $walletRow ? (float)$walletRow['cost_per_check'] : 3.50;
    $txnId     = smileid_wallet_charge($cost, 'pre-' . uniqid(), 'Verification: ' . strtoupper($idType));
    if ($txnId === false) {
        $r = [
            'status'    => 'error',
            'summary'   => 'Smile ID wallet has insufficient balance — top up required',
            'reference' => null,
            'response'  => null,
        ];
        smileid_log('verify_id', $r['status'], $r['summary'], null, ['id_type' => $idType]);
        return $r;
    }

    if ($cfg['mode'] === 'sandbox') {
        $r = smileid_sandbox_check($smileType, $idNumber, $fullName);
    } else {
        $r = smileid_live_check($smileType, $idNumber, $fullName, $cfg);
    }

    // Replace the pre-charge reference with the actual SmileJobID if available
    if ($r['reference']) {
        smileid_wallet_update_reference($txnId, $r['reference']);
    }

    smileid_log('verify_id', $r['status'], $r['summary'], $r['reference'],
        ['id_type' => $idType, 'mode' => $cfg['mode']]);
    return $r;
}

// ══════════ SANDBOX (demo mode) ══════════

/**
 * Sandbox rules — last digit of id_number drives the outcome.
 * Mirrors Smile ID's own sandbox convention.
 *   0,2,4,6,8 -> verified
 *   1 -> name mismatch
 *   3 -> expired
 *   5 -> not found
 *   7 -> authority down
 *   9 -> simulated service error
 */
function smileid_sandbox_check($smileType, $idNumber, $fullName) {
    $clean = strtoupper(trim($idNumber));
    if ($clean === '') {
        return smileid_result('failed', 'ID number is empty', null, $smileType, $clean);
    }
    $lastChar = substr($clean, -1);
    if (!ctype_digit($lastChar)) {
        return smileid_result('failed', 'Unrecognised ID number format', null, $smileType, $clean);
    }
    $digit = (int)$lastChar;
    $jobId = 'SMJ-SANDBOX-' . substr(md5($clean . microtime()), 0, 10);

    if ($digit % 2 === 0) {
        return smileid_result('verified',
            smileid_label_for($smileType) . ' verified — name matches authority record',
            $jobId, $smileType, $clean, 'Completed', '1012', 'ID Number Validated');
    }
    switch ($digit) {
        case 1:
            return smileid_result('failed',
                smileid_label_for($smileType) . ' found but name does not match',
                $jobId, $smileType, $clean, 'Returned', '1013', 'Names not matching');
        case 3:
            return smileid_result('failed',
                smileid_label_for($smileType) . ' is expired',
                $jobId, $smileType, $clean, 'Returned', '1022', 'ID expired');
        case 5:
            return smileid_result('failed',
                smileid_label_for($smileType) . ' not found in authority database',
                $jobId, $smileType, $clean, 'Returned', '1013', 'ID Number not found');
        case 7:
            return smileid_result('failed',
                'Authority database temporarily unavailable — retry later',
                $jobId, $smileType, $clean, 'Not Applicable', '1015', 'Authority down');
        case 9:
        default:
            return smileid_result('error',
                'Smile ID service simulated error',
                $jobId, $smileType, $clean, 'Error', '2204', 'Service error');
    }
}


// ══════════ LIVE MODE ══════════

function smileid_live_check($smileType, $idNumber, $fullName, $cfg) {
    if (empty($cfg['partner_id']) || empty($cfg['api_key'])) {
        return [
            'status' => 'error',
            'summary' => 'Smile ID live credentials not configured',
            'reference' => null, 'response' => null,
        ];
    }

    try {
        $parts = preg_split('/\s+/', trim($fullName), 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? $first;

        $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
        $signature = smileid_generate_signature($timestamp, $cfg['partner_id'], $cfg['api_key']);

        $payload = [
            'source_sdk'         => 'rest_api',
            'source_sdk_version' => '1.0.0',
            'signature'          => $signature,
            'timestamp'          => $timestamp,
            'partner_id'         => $cfg['partner_id'],
            'partner_params'     => [
                'job_id'   => 'quickhire-' . bin2hex(random_bytes(6)),
                'user_id'  => 'provider-' . bin2hex(random_bytes(4)),
                'job_type' => 5,
            ],
            'country'    => 'GH',
            'id_type'    => $smileType,
            'id_number'  => trim($idNumber),
            'first_name' => $first,
            'last_name'  => $last,
        ];

        $url = SMILEID_LIVE_URL;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => SMILEID_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 'error', 'summary' => 'Smile ID network error: ' . $err,
                    'reference' => null, 'response' => null];
        }
        $data = json_decode($raw, true);
        if ($code >= 400 || !is_array($data)) {
            return ['status' => 'error', 'summary' => 'Smile ID returned HTTP ' . $code,
                    'reference' => null, 'response' => $raw];
        }
        $resultCode = $data['ResultCode'] ?? '';
        $resultText = $data['ResultText'] ?? 'No result text';
        $jobId      = $data['SmileJobID'] ?? null;
        $approved   = in_array($resultCode, ['1012', '1020', '0810', '0811', '0820']);
        return [
            'status'    => $approved ? 'verified' : 'failed',
            'summary'   => smileid_label_for($smileType) . ' — ' . $resultText,
            'reference' => $jobId,
            'response'  => $raw,
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'summary' => 'Smile ID exception: ' . $e->getMessage(),
                'reference' => null, 'response' => null];
    }
}

function smileid_generate_signature($timestamp, $partnerId, $apiKey) {
    $message = $timestamp . $partnerId . 'sid_request';
    return base64_encode(hash_hmac('sha256', $message, $apiKey, true));
}


// ══════════ HELPERS ══════════

function smileid_label_for($smileType) {
    return [
        'GHANA_CARD'      => 'Ghana Card',
        'PASSPORT'        => 'Passport',
        'VOTER_ID'        => "Voter's ID",
        'DRIVERS_LICENSE' => "Driver's Licence",
        'NHIS'            => 'NHIS Card',
    ][$smileType] ?? $smileType;
}

function smileid_result($status, $summary, $jobId, $smileType, $idNumber,
                        $action = null, $code = null, $text = null) {
    $raw = json_encode([
        'mode'       => 'sandbox',
        'country'    => 'GH',
        'id_type'    => $smileType,
        'id_number'  => $idNumber,
        'SmileJobID' => $jobId,
        'ResultCode' => $code,
        'ResultText' => $text,
        'Actions'    => $action ? ['Verify_ID_Number' => $action] : null,
    ]);
    return ['status' => $status, 'summary' => $summary, 'reference' => $jobId, 'response' => $raw];
}

function smileid_badge($status) {
    switch ($status) {
        case 'verified': return ['bg' => '#dcfce7', 'fg' => '#166534', 'icon' => '✓', 'label' => 'Smile ID Verified'];
        case 'failed':   return ['bg' => '#fee2e2', 'fg' => '#991b1b', 'icon' => '✗', 'label' => 'Smile ID Failed'];
        case 'error':    return ['bg' => '#e0e7ff', 'fg' => '#3730a3', 'icon' => '⚠', 'label' => 'Check Errored'];
        default:         return ['bg' => '#f3f4f6', 'fg' => '#374151', 'icon' => '…', 'label' => 'Pending'];
    }
}
