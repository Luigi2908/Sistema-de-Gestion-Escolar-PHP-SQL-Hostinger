<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Gateway webhook — the ONLY thing allowed to turn a redirect payment into access.
 * Unauthenticated by design: the signature IS the authentication. Nothing here trusts a query
 * string, and the raw body is read before anything else can touch it or the HMAC will not match.
 */
$GLOBALS['ORMS_RAW_BODY'] = file_get_contents('php://input');
require_once 'billing_engine.php';

header('Content-Type: application/json');

$gw  = preg_replace('/[^a-z_]/', '', strtolower($_GET['gw'] ?? 'stripe'));
$raw = (string) $GLOBALS['ORMS_RAW_BODY'];

// answer once, in one shape, and never leak why a signature failed to whoever is probing
$reply = static function (int $code, string $msg, bool $ok = false): void {
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(405, 'POST only');
if (!bilEnabled())                          $reply(503, 'Billing is disabled');
if ($raw === '')                            $reply(400, 'Empty body');

if ($gw !== 'stripe') {
    bilLogEvent($gw ?: 'unknown', 'unsupported', null, false, false, 'No webhook driver', $raw);
    $reply(400, 'Unsupported gateway');
}

// ---- stripe ------------------------------------------------------------
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$chk = bilStripeVerify($raw, $sig);
if (!$chk['ok']) {
    bilLogEvent('stripe', 'signature.failed', null, false, false, $chk['message'], $raw);
    $reply(400, 'Invalid signature');
}

$event = json_decode($raw, true);
$type  = (string) ($event['type'] ?? '');
$obj   = $event['data']['object'] ?? [];

// only a completed, actually-paid session buys anything. an unpaid session is a real event and
// worth recording, but it must never reach bilApplyPayment()
if ($type !== 'checkout.session.completed' || ($obj['payment_status'] ?? '') !== 'paid') {
    bilLogEvent('stripe', $type ?: 'unknown', $obj['id'] ?? null, true, false, 'Ignored — not a paid checkout session', $raw);
    $reply(200, 'Ignored', true);
}

// the invoice is found by the token WE minted, not by anything the payer could choose
$token = (string) ($obj['client_reference_id'] ?? '');
$inv   = bilInvoiceByToken($token);
if (!$inv) {
    bilLogEvent('stripe', $type, $obj['id'] ?? null, true, false, 'No invoice for client_reference_id', $raw);
    $reply(200, 'Unknown invoice', true);   // 200: retrying will not conjure the invoice
}

$ccy    = strtoupper((string) ($obj['currency'] ?? $inv['currency']));
$minor  = (int) ($obj['amount_total'] ?? 0);
$amount = in_array($ccy, bilZeroDecimal(), true) ? (float) $minor : $minor / 100;

$res = bilApplyPayment((int) $inv['id'], 'stripe', (string) ($obj['id'] ?? ''), $amount, $ccy, 'stripe');
bilLogEvent('stripe', $type, $obj['id'] ?? null, true, !empty($res['ok']), $res['message'] ?? '', $raw, (int) $inv['id'], (int) $inv['school_id']);

// a genuine failure returns 500 so Stripe retries; an already-applied replay is a success
$reply(!empty($res['ok']) ? 200 : 500, $res['message'] ?? 'Error', !empty($res['ok']));
