<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Billing engine — every path that turns money into access goes through bilApplyPayment().
 * The gateway drivers only ever decide "is this reference genuinely paid"; what that BUYS is
 * defined once, here, so a webhook and an operator clicking Approve cannot drift apart.
 */
if (!defined('ORMS_BILLING')) {
    define('ORMS_BILLING', 1);
    require_once __DIR__ . '/config.php';

if (!function_exists('ormsEnsureSchoolColumns')) {
    function ormsEnsureSchoolColumns(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $c = getDBConnection(true);
            if (!$c) return;
            $probe = @$c->query("SHOW COLUMNS FROM `schools` LIKE 'trial_ends_at'");
            if ($probe && $probe->num_rows === 0) {
                @$c->query("ALTER TABLE `schools` ADD COLUMN `trial_ends_at` DATE DEFAULT NULL AFTER `plan_id`");
            }
            $probeLogo = @$c->query("SHOW COLUMNS FROM `schools` LIKE 'logo'");
            if ($probeLogo && $probeLogo->num_rows === 0) {
                @$c->query("ALTER TABLE `schools` ADD COLUMN `logo` VARCHAR(255) DEFAULT NULL AFTER `code`");
            }
            $probeCur = @$c->query("SHOW COLUMNS FROM `schools` LIKE 'billing_currency'");
            if ($probeCur && $probeCur->num_rows === 0) {
                @$c->query("ALTER TABLE `schools` ADD COLUMN `billing_currency` VARCHAR(10) DEFAULT NULL AFTER `plan_id`");
            }
            $probeInv = @$c->query("SHOW TABLES LIKE 'billing_invoices'");
            if ($probeInv && $probeInv->num_rows > 0) {
                $colCycle = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'cycle'");
                if ($colCycle && $colCycle->num_rows === 0) {
                    $colOld = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'billing_cycle'");
                    if ($colOld && $colOld->num_rows > 0) {
                        @$c->query("ALTER TABLE `billing_invoices` CHANGE `billing_cycle` `cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly'");
                    } else {
                        @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `plan_id`");
                    }
                }
                $colPaid = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'paid_at'");
                if ($colPaid && $colPaid->num_rows === 0) {
                    @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `paid_at` DATETIME DEFAULT NULL AFTER `period_end`");
                }
                $colProof = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'proof'");
                if ($colProof && $colProof->num_rows === 0) {
                    @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `proof` VARCHAR(255) DEFAULT NULL AFTER `paid_at`");
                }
                $colToken = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'token'");
                if ($colToken && $colToken->num_rows === 0) {
                    @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `token` CHAR(48) NOT NULL DEFAULT '' AFTER `invoice_no`");
                }
            }
        } catch (Throwable $e) {}
    }
}
ormsEnsureSchoolColumns();


// ---------------------------------------------------------------- period math

function bilAddMonths(string $ymd, int $n): string {
    [$y, $m, $d] = array_map('intval', explode('-', substr($ymd, 0, 10)));
    $tm = $m + $n;
    $ty = $y + intdiv($tm - 1, 12);
    $tm = (($tm - 1) % 12) + 1;
    if ($tm < 1) { $tm += 12; $ty--; }
    return sprintf('%04d-%02d-%02d', $ty, $tm, min($d, (int) date('t', mktime(0, 0, 0, $tm, 1, $ty))));
}

// a period ENDS the day before the next one starts, so back-to-back periods never share a date
function bilPeriodEnd(string $start, string $cycle): string {
    $next = bilAddMonths($start, $cycle === 'yearly' ? 12 : 1);
    return date('Y-m-d', strtotime($next . ' -1 day'));
}

// ---------------------------------------------------------------- money

// Stripe and most processors speak minor units. Comparing floats for equality is how a 1999.99
// payment silently "matches" a 2000.00 invoice, so every comparison happens in integers.
function bilZeroDecimal(): array {
    return ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
}

function bilMinor(float $amount, string $ccy): int {
    return in_array(strtoupper($ccy), bilZeroDecimal(), true) ? (int) round($amount) : (int) round($amount * 100);
}

// THE platform currency. One knob, owned by the App Owner in settings.php, shared by the school
// side (ormsMoney) and the billing side alike. billing_currency was a second, independent copy of
// this and the two seeds disagreed out of the box — the row is still kept in step so it cannot
// lie, but nothing reads it any more. ormsCurrencyCode() guarantees a real iso code, so no fallback
function bilCurrency(): string {
    return ormsCurrency()['code'];
}

function bilMoney(float $v, ?string $ccy = null): string {
    return ($ccy ?: bilCurrency()) . ' ' . number_format($v, 2);
}

function bilEnabled(): bool {
    return ormsPlatformMode() && (string) ormsPlatformSetting('billing_enabled', '0') === '1';
}

function bilTestMode(): bool {
    return (string) ormsPlatformSetting('billing_test_mode', '1') === '1';
}

// ---------------------------------------------------------------- gateway registry
/**
 * One entry per driver. `redirect` = the school leaves the site and a webhook confirms; `manual` =
 * the invoice sits Pending until the App Owner approves it. Adding a gateway is this array plus a
 * bilXxxCheckout()/bilXxxVerify() pair — nothing else in the app knows a gateway's name.
 */
function bilGateways(): array {
    return [
        'manual' => [
            'key' => 'manual', 'kind' => 'manual', 'icon' => 'fa-building-columns',
            'label' => (string) ormsPlatformSetting('gw_manual_label', 'Bank Transfer'),
            'enabled' => (string) ormsPlatformSetting('gw_manual_enabled', '1') === '1',
            'ready' => true,   // no credentials to get wrong
            'blurb' => 'Pay by transfer and send the receipt. Activated by hand, usually same day.',
        ],
        'stripe' => [
            'key' => 'stripe', 'kind' => 'redirect', 'icon' => 'fa-credit-card',
            'label' => 'Card (Stripe)',
            'enabled' => (string) ormsPlatformSetting('gw_stripe_enabled', '0') === '1',
            'ready' => trim((string) ormsPlatformSetting('gw_stripe_sk', '')) !== ''
                    && trim((string) ormsPlatformSetting('gw_stripe_webhook_secret', '')) !== '',
            'blurb' => 'Card payment on Stripe Checkout. Access opens the moment Stripe confirms.',
        ],
    ];
}

// what a school may actually pick right now
function bilLiveGateways(): array {
    if (!bilEnabled()) return [];
    return array_filter(bilGateways(), static fn($g) => $g['enabled'] && $g['ready']);
}

function bilGateway(string $key): ?array {
    return bilGateways()[$key] ?? null;
}

// ---------------------------------------------------------------- audit

// every callback lands here BEFORE it is believed. a hit that fails its signature is exactly the
// row worth having later, so this never throws and never depends on the caller being trusted.
function bilLogEvent(string $gateway, string $event, ?string $ref, bool $sigOk, bool $applied, string $msg = '', $payload = null, ?int $invoiceId = null, ?int $school = null): void {
    try {
        $raw = is_string($payload) ? $payload : json_encode($payload);
        if ($raw !== null && strlen($raw) > 60000) $raw = substr($raw, 0, 60000) . '…[truncated]';
        qInsert("INSERT INTO billing_events (invoice_id, school_id, gateway, event, reference, signature_ok, applied, message, payload, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                'iisssiisss', $invoiceId, $school, $gateway, substr($event, 0, 60), $ref !== null ? substr($ref, 0, 191) : null,
                $sigOk ? 1 : 0, $applied ? 1 : 0, substr($msg, 0, 255), $raw, ormsIp());
    } catch (Throwable $e) { error_log('billing event log failed: ' . $e->getMessage()); }
}

// ---------------------------------------------------------------- invoices

function bilInvoiceNo(): string {
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ormsPlatformSetting('billing_invoice_prefix', 'INV'))) ?: 'INV';
    // date + a random tail: readable, and it never leaks how many schools have subscribed
    return $prefix . '-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function bilPrice(array $plan, string $cycle): float {
    return round((float) ($cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly']), 2);
}

/**
 * The currency THIS school is billed in. There is exactly ONE currency on the platform now, so
 * every school is billed in it. schools.billing_currency stays on the table as the record of what
 * a school USED to be billed in and is deliberately not read — a tenant cannot set the price of
 * its own invoice. Stored invoice/payment/period rows keep their own currency, so history is never
 * re-labelled; only new documents take this one. $school stays so the call sites don't change.
 */
function bilCurrencyFor(?int $school = null): string {
    return bilCurrency();
}

/**
 * What a plan costs IN a currency. Prices are CHOSEN, never converted: no FX rate is applied
 * anywhere, because a rate that drifts overnight silently changes what a customer is charged.
 * The plan's own columns are its price in the platform currency; plan_prices holds the rest.
 * A currency with no row has NO price — bilCreateInvoice already refuses that with a sentence.
 */
function bilPriceIn(array $plan, string $cycle, string $ccy): float {
    $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
    $ccy   = strtoupper(trim($ccy));
    if ($ccy === '' || $ccy === bilCurrency()) return bilPrice($plan, $cycle);
    try {
        $r = qOne("SELECT price_monthly, price_yearly FROM plan_prices WHERE plan_id = ? AND currency = ?",
                  'is', (int) ($plan['id'] ?? 0), $ccy);
        if ($r) return round((float) $r['price_' . $cycle], 2);
    } catch (Throwable $e) {}       // table not migrated yet -> no override exists
    return 0.0;
}

// every extra-currency price of one plan, for the operator's editor and the tenant's plan cards
function bilPlanPrices(int $planId): array {
    try { return qAll("SELECT currency, price_monthly, price_yearly FROM plan_prices WHERE plan_id = ? ORDER BY currency ASC", 'i', $planId); }
    catch (Throwable $e) { return []; }
}

/**
 * Raise a Pending invoice. Reuses the school's most recent unpaid invoice for the same plan+cycle+
 * gateway instead of stacking a new one every time the page is refreshed — a customer who clicks
 * Pay four times should not owe four invoices.
 */
function bilCreateInvoice(int $school, array $plan, string $cycle, string $gateway, ?int $byUser = null): array {
    $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
    $ccy   = bilCurrencyFor($school);              // the SCHOOL's currency, not the platform's
    $amt   = bilPriceIn($plan, $cycle, $ccy);
    if (bilMinor($amt, $ccy) <= 0) {
        throw new RuntimeException($ccy === bilCurrency()
            ? 'That plan has no price set for this billing cycle.'
            : 'That plan has no ' . $ccy . ' price for this billing cycle. Add one on the Plans page.');
    }

    $open = qOne("SELECT * FROM billing_invoices
                  WHERE school_id = ? AND status = 'Pending' AND plan_id = ? AND cycle = ? AND gateway = ?
                    AND amount = ? AND currency = ?
                  ORDER BY id DESC LIMIT 1",
                 'iissds', $school, (int) $plan['id'], $cycle, $gateway, $amt, $ccy);
    if ($open) return $open;

    $id = qInsert("INSERT INTO billing_invoices (school_id, invoice_no, token, plan_id, cycle, amount, currency, status, gateway, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)",
                  'issisdssi', $school, bilInvoiceNo(), bin2hex(random_bytes(24)), (int) $plan['id'], $cycle, $amt, $ccy, $gateway, $byUser);
    return qOne("SELECT * FROM billing_invoices WHERE id = ?", 'i', $id);
}

function bilInvoiceByToken(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    return qOne("SELECT * FROM billing_invoices WHERE token = ? LIMIT 1", 's', $token);
}

// Pending invoices do not haunt a school forever — anything untouched for 30 days is dead paper.
// Never touches Paid rows, so it can run on any page load without a guard.
function bilExpireStale(?int $school = null): int {
    try {
        return $school
            ? qExec("UPDATE billing_invoices SET status = 'Expired' WHERE school_id = ? AND status = 'Pending' AND created_at < (NOW() - INTERVAL 30 DAY)", 'i', $school)
            : qExec("UPDATE billing_invoices SET status = 'Expired' WHERE status = 'Pending' AND created_at < (NOW() - INTERVAL 30 DAY)");
    } catch (Throwable $e) { return 0; }
}

// ---------------------------------------------------------------- THE money function

/**
 * Turn a confirmed payment into access. Called by the Stripe webhook and by the App Owner approving
 * a bank transfer — one definition, so the two can never buy different things.
 *
 * Idempotent on two levels: the invoice is locked and re-read inside the transaction (a replayed
 * webhook finds it already Paid and returns quietly), and uniq_gw_ref(gateway, gateway_ref) makes it
 * impossible for a second invoice to claim a reference the first one already used.
 *
 * Writes, in this order and all inside one transaction:
 *   1. subscription_payments  — the receipt; the money is the fact being recorded
 *   2. school_subscriptions   — a NEW period row, never an update. History is append-only.
 *   3. back-link receipt -> period
 *   4. retire only the periods that have actually LAPSED
 *   5. schools.status = Active (+ plan_id) — this is what stops renew.php blocking
 *   6. the invoice is stamped Paid last, so a crash mid-way leaves it retryable
 */
function bilApplyPayment(int $invoiceId, string $gateway, ?string $ref, ?float $paidAmount = null, ?string $paidCcy = null, string $method = '', ?int $byUser = null): array {
    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        $inv = qOne("SELECT * FROM billing_invoices WHERE id = ? FOR UPDATE", 'i', $invoiceId);
        if (!$inv) { $conn->rollback(); return ['ok' => false, 'message' => 'Invoice not found']; }

        if ($inv['status'] === 'Paid') {                       // replay — the honest no-op
            $conn->commit();
            return ['ok' => true, 'already' => true, 'invoice' => $inv, 'message' => 'Already applied'];
        }
        if (!in_array($inv['status'], ['Pending', 'Failed'], true)) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Invoice is ' . $inv['status'] . ' and cannot be paid'];
        }

        // the gateway must be the one the invoice was raised for, or a cheap manual invoice could be
        // "confirmed" by a reference borrowed from an entirely different processor
        if ($inv['gateway'] !== $gateway) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Invoice belongs to ' . $inv['gateway'] . ', not ' . $gateway];
        }

        // underpayment never buys a period. an overpayment does — refunding is a human decision
        if ($paidAmount !== null) {
            $ccy = $paidCcy ? strtoupper($paidCcy) : $inv['currency'];
            if ($ccy !== strtoupper($inv['currency'])) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Paid in ' . $ccy . ' but invoiced in ' . $inv['currency']];
            }
            if (bilMinor($paidAmount, $ccy) < bilMinor((float) $inv['amount'], $ccy)) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Paid ' . bilMoney($paidAmount, $ccy) . ' against ' . bilMoney((float) $inv['amount'], $inv['currency'])];
            }
        }

        $school = (int) $inv['school_id'];
        $amt    = (float) $inv['amount'];
        $ccy    = (string) $inv['currency'];
        $planId = $inv['plan_id'] !== null ? (int) $inv['plan_id'] : null;
        $today  = date('Y-m-d');

        // lock the tenant, then its newest period. two confirmations landing in the same second would
        // otherwise both extend from the SAME ends_at and sell one period twice
        qVal("SELECT id FROM schools WHERE id = ? FOR UPDATE", 'i', $school);
        $cur = qOne("SELECT id, ends_at FROM school_subscriptions WHERE school_id = ?
                     ORDER BY ends_at DESC, id DESC LIMIT 1 FOR UPDATE", 'i', $school);

        // the LATER of today and the running end date -> renewing early EXTENDS, never truncates
        $start = ($cur && $cur['ends_at'] > $today) ? substr($cur['ends_at'], 0, 10) : $today;
        $end   = bilPeriodEnd($start, (string) $inv['cycle']);

        $payId = qInsert("INSERT INTO subscription_payments (school_id, amount, currency, paid_on, method, reference, note, recorded_by, invoice_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                         'idsssssii', $school, $amt, $ccy, $today, substr($method ?: $gateway, 0, 40),
                         $ref !== null ? substr($ref, 0, 100) : null, 'Invoice ' . $inv['invoice_no'], $byUser, $invoiceId);

        $subId = qInsert("INSERT INTO school_subscriptions (school_id, plan_id, starts_at, ends_at, status, amount, currency, notes, created_by)
                          VALUES (?, ?, ?, ?, 'Active', ?, ?, ?, ?)",
                         'iissdssi', $school, $planId, $start, $end, $amt, $ccy, 'Invoice ' . $inv['invoice_no'], $byUser);

        qExec("UPDATE subscription_payments SET subscription_id = ? WHERE id = ?", 'ii', $subId, $payId);

        // retire only what has LAPSED — an early renewal leaves the running period Active to its own end
        qExec("UPDATE school_subscriptions SET status = 'Expired'
               WHERE school_id = ? AND id <> ? AND status = 'Active' AND ends_at < ?", 'iis', $school, $subId, $today);

        $planId
            ? qExec("UPDATE schools SET status = 'Active', plan_id = ? WHERE id = ?", 'ii', $planId, $school)
            : qExec("UPDATE schools SET status = 'Active' WHERE id = ?", 'i', $school);

        qExec("UPDATE billing_invoices SET status = 'Paid', gateway_ref = ?, payment_id = ?, subscription_id = ?,
                      period_start = ?, period_end = ?, paid_at = NOW() WHERE id = ?",
              'siissi', $ref !== null ? substr($ref, 0, 191) : null, $payId, $subId, $start, $end, $invoiceId);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('bilApplyPayment failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not apply the payment: ' . $e->getMessage()];
    }

    $fresh = qOne("SELECT * FROM billing_invoices WHERE id = ?", 'i', $invoiceId);
    bilNotifyPaid($fresh);
    return ['ok' => true, 'already' => false, 'invoice' => $fresh,
            'message' => 'Subscription extended to ' . date('d M Y', strtotime($fresh['period_end']))];
}

// tell the people who care. best effort — a mail or notification failure must never undo a payment
function bilNotifyPaid(?array $inv): void {
    if (!$inv) return;
    try {
        $school = qOne("SELECT id, name, code, email FROM schools WHERE id = ?", 'i', (int) $inv['school_id']);
        $line   = 'Payment received for ' . ($school['name'] ?? 'school') . ' — ' . bilMoney((float) $inv['amount'], $inv['currency'])
                . ' (' . $inv['invoice_no'] . '). Access runs to ' . date('d M Y', strtotime($inv['period_end'])) . '.';

        $owner = ormsSchoolOwnerId((int) $inv['school_id']);
        if ($owner) createNotification($owner, 'Subscription renewed', $line, 'success', 'billing.php');

        $appOwner = ormsAppOwnerId();
        if ($appOwner) createNotification($appOwner, 'Payment received', $line, 'success', 'gateways.php');

        logActivity($inv['created_by'] ?? null, 'billing', 'Subscription Paid', $line, 'billing_invoices', (int) $inv['id'], (int) $inv['school_id']);
    } catch (Throwable $e) { error_log('bilNotifyPaid: ' . $e->getMessage()); }
}

// ---------------------------------------------------------------- http

// small POST helper. curl when it exists, stream context otherwise, so this works on a stock XAMPP
// and on shared hosting with curl disabled.
function bilHttpPost(string $url, $body, array $headers = [], int $timeout = 25): array {
    $payload = is_array($body) ? http_build_query($body) : (string) $body;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['ok' => $res !== false && $code >= 200 && $code < 300, 'code' => $code, 'body' => (string) $res, 'error' => $err];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload,
        'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $res  = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int) $m[1]; }
    return ['ok' => $res !== false && $code >= 200 && $code < 300, 'code' => $code, 'body' => (string) $res, 'error' => $res === false ? 'request failed' : ''];
}

function bilBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

// ---------------------------------------------------------------- driver: stripe

function bilStripeCheckout(array $inv, array $plan): array {
    $sk = trim((string) ormsPlatformSetting('gw_stripe_sk', ''));
    if ($sk === '') return ['ok' => false, 'message' => 'Stripe is not configured'];

    $base = bilBaseUrl();
    $form = [
        'mode' => 'payment',
        'success_url' => $base . '/payment_return.php?t=' . $inv['token'] . '&r=ok',
        'cancel_url'  => $base . '/payment_return.php?t=' . $inv['token'] . '&r=cancel',
        'client_reference_id' => $inv['token'],
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => strtolower($inv['currency']),
        'line_items[0][price_data][unit_amount]' => bilMinor((float) $inv['amount'], $inv['currency']),
        'line_items[0][price_data][product_data][name]' => ($plan['name'] ?? 'Subscription') . ' — ' . $inv['cycle'],
        'metadata[invoice_no]' => $inv['invoice_no'],
        'metadata[invoice_id]' => $inv['id'],
        'metadata[school_id]'  => $inv['school_id'],
    ];
    $res = bilHttpPost('https://api.stripe.com/v1/checkout/sessions', $form, [
        'Authorization: Bearer ' . $sk,
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    $json = json_decode($res['body'], true);
    if (!$res['ok'] || empty($json['url'])) {
        $msg = $json['error']['message'] ?? ('Stripe refused the request (HTTP ' . $res['code'] . ')');
        bilLogEvent('stripe', 'session.create.failed', $inv['invoice_no'], true, false, $msg, $res['body'], (int) $inv['id'], (int) $inv['school_id']);
        return ['ok' => false, 'message' => $msg];
    }
    // claim the invoice for this session up front: uniq_gw_ref then makes it structurally impossible
    // for a second invoice to be settled by the same session id
    qExec("UPDATE billing_invoices SET gateway_ref = ? WHERE id = ? AND gateway_ref IS NULL", 'si', $json['id'], (int) $inv['id']);
    bilLogEvent('stripe', 'session.created', $json['id'], true, false, '', null, (int) $inv['id'], (int) $inv['school_id']);
    return ['ok' => true, 'redirect' => $json['url'], 'ref' => $json['id']];
}

/**
 * Verify a Stripe webhook. The signature covers "timestamp.rawbody" — reading php://input AFTER any
 * framework has touched it, or re-encoding the JSON, breaks the hash, so the raw string is passed in.
 * hash_equals, never ==, and a timestamp tolerance so a captured hit cannot be replayed next week.
 */
function bilStripeVerify(string $raw, string $sigHeader, int $tolerance = 300): array {
    $secret = trim((string) ormsPlatformSetting('gw_stripe_webhook_secret', ''));
    if ($secret === '') return ['ok' => false, 'message' => 'No webhook secret configured'];

    $t = null; $v1 = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't')  $t = $kv[1];
        if ($kv[0] === 'v1') $v1[] = $kv[1];
    }
    if ($t === null || !$v1) return ['ok' => false, 'message' => 'Malformed signature header'];
    if (abs(time() - (int) $t) > $tolerance) return ['ok' => false, 'message' => 'Signature timestamp outside tolerance'];

    $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);
    foreach ($v1 as $candidate) if (hash_equals($expected, $candidate)) return ['ok' => true];
    return ['ok' => false, 'message' => 'Signature mismatch'];
}

// ---------------------------------------------------------------- dispatch

// hand an invoice to its gateway. manual has nowhere to send anyone — that is not a failure.
function bilStartCheckout(array $inv, array $plan): array {
    switch ($inv['gateway']) {
        case 'stripe': return bilStripeCheckout($inv, $plan);
        case 'manual': return ['ok' => true, 'redirect' => null];
        default:       return ['ok' => false, 'message' => 'Unknown gateway'];
    }
}

}
