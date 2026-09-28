<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Billing console. Manual approval — a school pays by bank/JazzCash/WhatsApp and the operator
 * records it here, which mints a NEW period row and un-blocks renew.php.
 * Period rows are APPEND-ONLY: a renewal never rewrites the row it followed.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

// rbac first, platform gate second — a school role could otherwise be granted 'subscriptions' by a bad matrix edit
requirePerm('subscriptions', 'v');

/**
 * PLATFORM ONLY. Every row here is another tenant's money, and Record Payment writes
 * schools.status — one school's admin would be able to unblock (or read) the whole platform.
 * Repeated on every ajax branch: the page gate alone stops nothing that posts straight here.
 */
function subDeny(bool $json): void {
    if (ormsIsPlatform()) return;
    if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'The billing console belongs to the platform operator.']); exit(); }
    header('Location: dashboard.php');
    exit();
}
subDeny(isset($_GET['action']) || isset($_POST['action']));

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'subscriptions';

const SUB_SOON = 14;   // "expiring soon" window, in days

// billing tables land in one migration step — one probe answers for the lot
function subReady(): bool {
    static $ok = null;
    if ($ok === null) {
        if (!ormsHasTenancy()) return $ok = false;
        try { qVal("SELECT id FROM school_subscriptions LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// a stored currency -> a real code. blank/never-billed falls back to the PLATFORM currency,
// never to ormsCurrencyCode()'s USD default — a PKR install would otherwise quote new schools in $
function subCcy($raw): string {
    return trim((string)$raw) === '' ? ormsCurrency()['code'] : ormsCurrencyCode((string)$raw);
}

// money in the ROW's own currency — a stored period or receipt keeps what it was written in
function subMoney(float $v, $code = null): string {
    return ormsMoney($v, subCcy($code));
}

// whole days from today to an iso date. DateTime, so a DST night can never shave one off
function subDays(?string $ymd): ?int {
    if (empty($ymd)) return null;
    try { return (int)(new DateTime(date('Y-m-d')))->diff(new DateTime(substr($ymd, 0, 10)))->format('%r%a'); }
    catch (Throwable $e) { return null; }
}

// request date -> iso, '' when it isn't a real calendar date
function subDate($raw): string {
    $s = trim((string)$raw);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : '';
}

// "+1 month" off the 31st lands in the month AFTER next in php. clamp, so 31 Jan + 1 = 28/29 Feb
function subAddMonths(string $ymd, int $n): string {
    $d   = new DateTime($ymd);
    $day = (int)$d->format('j');
    $e   = (new DateTime($d->format('Y-m-01')))->modify("+$n months");
    $e->setDate((int)$e->format('Y'), (int)$e->format('n'), min($day, (int)$e->format('t')));
    return $e->format('Y-m-d');
}

function subPeriodEnd(string $start, string $cycle, int $days): string {
    if ($cycle === 'custom') return (new DateTime($start))->modify('+' . $days . ' days')->format('Y-m-d');
    return subAddMonths($start, $cycle === 'yearly' ? 12 : 1);
}

/**
 * THE status chip — one definition, and it must agree with ormsSubscriptionState() or the console
 * promises access the gate refuses. Never-billed reads 'active' for exactly that reason: the gate
 * treats "no period row" as never blocked, so a red chip here would be a lie.
 * Returns [state, daysLeft|null].
 */
function subState(array $r): array {
    if ($r['status'] === 'Suspended') return ['suspended', null];   // school-level stop beats any period row
    if ($r['status'] === 'Cancelled') return ['cancelled', null];
    if (empty($r['ends_at']))         return ['active', null];      // never billed -> never blocked
    $d = subDays($r['ends_at']);
    if ($r['sub_status'] === 'Cancelled')                 return ['cancelled', $d];
    if ($r['sub_status'] !== 'Active' || $d < 0)          return ['expired', $d];
    return [$d <= SUB_SOON ? 'expiring' : 'active', $d];
}

// mandatory operator note — extend/change-plan are free-of-charge moves, so the WHY is the audit trail
function subNote($raw): string {
    $s = trim((string)$raw);
    return mb_strlen($s) >= 3 && mb_strlen($s) <= 255 ? $s : '';
}

$ready  = subReady();
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    subDeny(true);
    try {
        if (!$ready) jsonErr('The billing tables are not installed yet — run update_setup.php once, then reload this page.');

        switch ($action) {

            case 'getSubs':
                // one driver: newest period per school by the SAME ordering ormsSubscriptionState() uses,
                // plus a grouped payments derived table. no query ever runs inside a loop
                $rows = qAll(
                    "SELECT s.id, s.name, s.code, s.status, s.trial_ends_at, s.plan_id AS school_plan_id,
                            b.id AS sub_id, b.plan_id, b.starts_at, b.ends_at, b.status AS sub_status,
                            b.amount, b.currency,
                            p.name AS plan_name,
                            COALESCE(m.paid, 0) AS paid, COALESCE(m.pays, 0) AS pays, m.last_paid
                     FROM schools s
                     LEFT JOIN school_subscriptions b ON b.id = (SELECT x.id FROM school_subscriptions x
                                                                 WHERE x.school_id = s.id
                                                                 ORDER BY x.ends_at DESC, x.id DESC LIMIT 1)
                     LEFT JOIN plans p ON p.id = b.plan_id
                     LEFT JOIN (SELECT school_id, SUM(amount) AS paid, COUNT(*) AS pays, MAX(paid_on) AS last_paid
                                FROM subscription_payments GROUP BY school_id) m ON m.school_id = s.id
                     ORDER BY s.name ASC");

                $counts = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'suspended' => 0, 'cancelled' => 0];
                foreach ($rows as &$r) {
                    [$st, $days] = subState($r);
                    // the school's currency is fixed by its newest period row — every write below reuses it,
                    // so SUM(amount) can never end up mixing two currencies into one meaningless total
                    $ccy = subCcy($r['currency'] ?? '');
                    $r['state']     = $st;
                    $r['days']      = $days;
                    $r['ccy']       = $ccy;
                    $r['paid_f']    = subMoney((float)$r['paid'], $ccy);
                    $r['amount_f']  = $r['sub_id'] ? subMoney((float)$r['amount'], $ccy) : '';
                    $r['starts_f']  = $r['starts_at'] ? date('d M Y', strtotime($r['starts_at'])) : '';
                    $r['ends_f']    = $r['ends_at']   ? date('d M Y', strtotime($r['ends_at']))   : '';
                    $r['last_paid_f'] = $r['last_paid'] ? date('d M Y', strtotime($r['last_paid'])) : '';
                    $counts[$st]++;
                }
                unset($r);

                // payments recorded this calendar month, grouped so two currencies can't be added together
                $m0 = date('Y-m-01');
                $m1 = date('Y-m-t');
                $mp = qAll("SELECT currency, SUM(amount) AS t, COUNT(*) AS n FROM subscription_payments
                            WHERE paid_on BETWEEN ? AND ? GROUP BY currency ORDER BY t DESC", 'ss', $m0, $m1);
                $base  = ormsCurrency()['code'];
                $head  = null;
                $mCount = 0;
                foreach ($mp as $g) {
                    $mCount += (int)$g['n'];
                    if ($head === null || ormsCurrencyCode($g['currency']) === $base) $head = $g;
                }

                jsonOk([
                    'data'   => $rows,
                    'counts' => $counts,
                    'kpi'    => [
                        'active'    => $counts['active'],
                        'expiring'  => $counts['expiring'],
                        'expired'   => $counts['expired'],
                        'month'     => $head ? subMoney((float)$head['t'], $head['currency']) : subMoney(0.0),
                        'month_n'   => $mCount,
                        'month_mix' => count($mp) > 1,
                        'month_lbl' => date('F Y'),
                    ],
                ]);

            case 'getHistory':
                requirePermJson('subscriptions', 'v');
                $sid = (int)($_POST['school_id'] ?? 0);
                $sc  = $sid ? qOne("SELECT id, name, code FROM schools WHERE id = ?", 'i', $sid) : null;
                if (!$sc) jsonErr('School not found');

                $periods = qAll("SELECT b.id, b.starts_at, b.ends_at, b.status, b.amount, b.currency, b.notes, b.created_at,
                                        p.name AS plan_name, u.full_name AS by_name
                                 FROM school_subscriptions b
                                 LEFT JOIN plans p ON p.id = b.plan_id
                                 LEFT JOIN users u ON u.id = b.created_by
                                 WHERE b.school_id = ? ORDER BY b.ends_at DESC, b.id DESC", 'i', $sid);
                $pays    = qAll("SELECT y.id, y.amount, y.currency, y.paid_on, y.method, y.reference, y.note,
                                        u.full_name AS by_name
                                 FROM subscription_payments y
                                 LEFT JOIN users u ON u.id = y.recorded_by
                                 WHERE y.school_id = ? ORDER BY y.paid_on DESC, y.id DESC", 'i', $sid);

                foreach ($periods as &$p) {
                    $p['amount_f'] = subMoney((float)$p['amount'], $p['currency']);
                    $p['starts_f'] = date('d M Y', strtotime($p['starts_at']));
                    $p['ends_f']   = date('d M Y', strtotime($p['ends_at']));
                }
                unset($p);
                foreach ($pays as &$y) {
                    $y['amount_f']  = subMoney((float)$y['amount'], $y['currency']);
                    $y['paid_on_f'] = date('d M Y', strtotime($y['paid_on']));
                }
                unset($y);
                jsonOk(['school' => $sc, 'periods' => $periods, 'payments' => $pays]);

            /**
             * THE money action. One transaction, five writes, in this order:
             *   1. subscription_payments  — the receipt, because the money is the fact being recorded
             *   2. school_subscriptions   — a NEW period row (never an update: history is append-only)
             *   3. back-link the receipt to the period it bought
             *   4. retire only the period rows that have actually LAPSED
             *   5. schools.status = 'Active' — this is what un-blocks a school sitting on renew.php
             * Everything is validated BEFORE begin_transaction(): jsonErr() exits, and an exit between
             * begin and commit would abandon the connection mid-write.
             */
            case 'recordPayment':
                requireCsrfJson();
                requirePermJson('subscriptions', 'a');

                $sid = (int)($_POST['school_id'] ?? 0);
                $sc  = $sid ? qOne("SELECT id, name, code, status FROM schools WHERE id = ?", 'i', $sid) : null;
                if (!$sc) jsonErr('School not found');

                $amtRaw = trim((string)($_POST['amount'] ?? ''));
                if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amtRaw)) jsonErr('Amount must be a number with up to 2 decimals');
                $amt = round((float)$amtRaw, 2);
                if ((int)round($amt * 100) <= 0) jsonErr('Amount must be more than zero');   // minor units — never compare floats

                $paidOn = subDate($_POST['paid_on'] ?? '');
                if ($paidOn === '')                  jsonErr('Enter a valid payment date');
                if ($paidOn > date('Y-m-d'))         jsonErr('Payment date cannot be in the future');

                $method = trim((string)($_POST['method'] ?? ''));
                $ref    = trim((string)($_POST['reference'] ?? ''));
                $note   = trim((string)($_POST['note'] ?? ''));
                if (mb_strlen($method) > 40) jsonErr('Method must be 40 characters or less');
                if (mb_strlen($ref) > 100)   jsonErr('Reference must be 100 characters or less');
                if (mb_strlen($note) > 255)  jsonErr('Note must be 255 characters or less');

                $planId = (int)($_POST['plan_id'] ?? 0);
                $plan   = $planId ? qOne("SELECT id, name FROM plans WHERE id = ?", 'i', $planId) : null;
                if ($planId && !$plan) jsonErr('The selected plan no longer exists');
                $planId = $plan ? (int)$plan['id'] : null;

                $cycle = in_array($_POST['cycle'] ?? '', ['monthly', 'yearly', 'custom'], true) ? $_POST['cycle'] : 'monthly';
                $days  = (int)($_POST['days'] ?? 0);
                if ($cycle === 'custom' && ($days < 1 || $days > 3650)) jsonErr('Custom length must be between 1 and 3650 days');

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // lock the school, then its newest period. two operators clicking Record in the same
                    // second would otherwise both extend from the SAME ends_at and sell one period twice
                    qVal("SELECT id FROM schools WHERE id = ? FOR UPDATE", 'i', $sid);
                    $cur = qOne("SELECT id, ends_at, currency FROM school_subscriptions
                                 WHERE school_id = ? ORDER BY ends_at DESC, id DESC LIMIT 1 FOR UPDATE", 'i', $sid);

                    // one currency on the platform, so every NEW period is stamped with it. rows
                    // already written keep their own — history is never re-labelled. never the posted value
                    $ccy   = ormsCurrency()['code'];
                    $today = date('Y-m-d');
                    // the LATER of today and the running end date -> an early renewal EXTENDS, never truncates
                    $start = ($cur && $cur['ends_at'] > $today) ? substr($cur['ends_at'], 0, 10) : $today;
                    $end   = subPeriodEnd($start, $cycle, $days);

                    // 1) the receipt. types: i school, d amount, s ccy, s date, s method, s ref, s note, i by
                    $payId = qInsert("INSERT INTO subscription_payments (school_id, amount, currency, paid_on, method, reference, note, recorded_by)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                     'idsssssi', $sid, $amt, $ccy, $paidOn, $method ?: null, $ref ?: null, $note ?: null, $user_id);

                    // 2) a NEW period row. types: i school, i plan, s start, s end, d amount, s ccy, s notes, i by
                    $subId = qInsert("INSERT INTO school_subscriptions (school_id, plan_id, starts_at, ends_at, status, amount, currency, notes, created_by)
                                      VALUES (?, ?, ?, ?, 'Active', ?, ?, ?, ?)",
                                     'iissdssi', $sid, $planId, $start, $end, $amt, $ccy, $note ?: null, $user_id);

                    // 3) receipt -> the period it bought
                    qExec("UPDATE subscription_payments SET subscription_id = ? WHERE id = ?", 'ii', $subId, $payId);

                    // 4) retire only what has LAPSED. an early renewal leaves the running period Active
                    //    until its own end date, and "ends_at DESC, id DESC" already prefers the new row
                    qExec("UPDATE school_subscriptions SET status = 'Expired'
                           WHERE school_id = ? AND id <> ? AND status = 'Active' AND ends_at < ?",
                          'iis', $sid, $subId, $today);

                    // 5) paid up -> renew.php stops blocking. plan_id follows what was actually billed
                    $planId
                        ? qExec("UPDATE schools SET status = 'Active', plan_id = ? WHERE id = ?", 'ii', $planId, $sid)
                        : qExec("UPDATE schools SET status = 'Active' WHERE id = ?", 'i', $sid);

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                logActivity($user_id, $username, 'Subscription Payment Recorded',
                    "Payment " . subMoney($amt, $ccy) . " for {$sc['name']} ({$sc['code']}) on " . date('d M Y', strtotime($paidOn)) .
                    " via " . ($method ?: 'unspecified') . ($ref !== '' ? " ref $ref" : '') .
                    " — period #$subId " . date('d M Y', strtotime($start)) . " to " . date('d M Y', strtotime($end)) .
                    ", plan: " . ($plan ? $plan['name'] : 'none') . ($note !== '' ? " — $note" : ''),
                    'subscription', $subId);

                jsonOk(['message' => 'Payment recorded — access runs to ' . date('d M Y', strtotime($end))]);

            // free days. same append-only shape as a payment, minus the receipt — and it deliberately
            // does NOT touch schools.status: a suspended school stays suspended until that is lifted
            case 'extendSub':
                requireCsrfJson();
                requirePermJson('subscriptions', 'e');

                $sid = (int)($_POST['school_id'] ?? 0);
                $sc  = $sid ? qOne("SELECT id, name, code, plan_id FROM schools WHERE id = ?", 'i', $sid) : null;
                if (!$sc) jsonErr('School not found');

                $days = (int)($_POST['days'] ?? 0);
                if ($days < 1 || $days > 3650) jsonErr('Extension must be between 1 and 3650 days');
                $note = subNote($_POST['note'] ?? '');
                if ($note === '') jsonErr('A note is required — say why the free days were granted');

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    qVal("SELECT id FROM schools WHERE id = ? FOR UPDATE", 'i', $sid);
                    $cur = qOne("SELECT id, ends_at, currency, plan_id FROM school_subscriptions
                                 WHERE school_id = ? ORDER BY ends_at DESC, id DESC LIMIT 1 FOR UPDATE", 'i', $sid);

                    $ccy    = ormsCurrency()['code'];   // the one platform currency
                    $today  = date('Y-m-d');
                    $start  = ($cur && $cur['ends_at'] > $today) ? substr($cur['ends_at'], 0, 10) : $today;
                    $end    = (new DateTime($start))->modify('+' . $days . ' days')->format('Y-m-d');
                    $planId = $cur ? ($cur['plan_id'] !== null ? (int)$cur['plan_id'] : null)
                                   : ($sc['plan_id'] !== null ? (int)$sc['plan_id'] : null);
                    $zero   = 0.00;
                    $notes  = "Free extension ($days day" . ($days === 1 ? '' : 's') . "): $note";

                    // types: i school, i plan, s start, s end, d amount, s ccy, s notes, i by
                    $subId = qInsert("INSERT INTO school_subscriptions (school_id, plan_id, starts_at, ends_at, status, amount, currency, notes, created_by)
                                      VALUES (?, ?, ?, ?, 'Active', ?, ?, ?, ?)",
                                     'iissdssi', $sid, $planId, $start, $end, $zero, $ccy, $notes, $user_id);

                    qExec("UPDATE school_subscriptions SET status = 'Expired'
                           WHERE school_id = ? AND id <> ? AND status = 'Active' AND ends_at < ?",
                          'iis', $sid, $subId, $today);

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                logActivity($user_id, $username, 'Subscription Extended',
                    "Granted $days free day(s) to {$sc['name']} ({$sc['code']}) — period #$subId " .
                    date('d M Y', strtotime($start)) . " to " . date('d M Y', strtotime($end)) . " — $note",
                    'subscription', $subId);

                jsonOk(['message' => 'Extended — access now runs to ' . date('d M Y', strtotime($end))]);

            // tier change mid-period. the running period is CLONED under the new plan with its end date
            // untouched, so the old row survives as history and the school loses no paid days
            case 'changePlan':
                requireCsrfJson();
                requirePermJson('subscriptions', 'e');

                $sid = (int)($_POST['school_id'] ?? 0);
                $sc  = $sid ? qOne("SELECT id, name, code FROM schools WHERE id = ?", 'i', $sid) : null;
                if (!$sc) jsonErr('School not found');

                $planId = (int)($_POST['plan_id'] ?? 0);
                $plan   = $planId ? qOne("SELECT id, name FROM plans WHERE id = ?", 'i', $planId) : null;
                if (!$plan) jsonErr('Please choose a plan');
                $note = subNote($_POST['note'] ?? '');
                if ($note === '') jsonErr('A note is required — say why the plan changed');

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    qVal("SELECT id FROM schools WHERE id = ? FOR UPDATE", 'i', $sid);
                    $cur = qOne("SELECT id, starts_at, ends_at, currency, amount, status FROM school_subscriptions
                                 WHERE school_id = ? ORDER BY ends_at DESC, id DESC LIMIT 1 FOR UPDATE", 'i', $sid);

                    $today = date('Y-m-d');
                    $subId = 0;
                    // only a RUNNING period is worth cloning — a lapsed one just gets billed on the new plan next time
                    if ($cur && $cur['status'] === 'Active' && $cur['ends_at'] >= $today) {
                        $ccy   = ormsCurrency()['code'];   // the one platform currency
                        $end   = substr($cur['ends_at'], 0, 10);
                        $zero  = 0.00;
                        $notes = "Plan changed to {$plan['name']}: $note";
                        // types: i school, i plan, s start, s end, d amount, s ccy, s notes, i by
                        $subId = qInsert("INSERT INTO school_subscriptions (school_id, plan_id, starts_at, ends_at, status, amount, currency, notes, created_by)
                                          VALUES (?, ?, ?, ?, 'Active', ?, ?, ?, ?)",
                                         'iissdssi', $sid, $planId, $today, $end, $zero, $ccy, $notes, $user_id);
                    }
                    qExec("UPDATE schools SET plan_id = ? WHERE id = ?", 'ii', $planId, $sid);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                logActivity($user_id, $username, 'Subscription Plan Changed',
                    "{$sc['name']} ({$sc['code']}) moved to plan {$plan['name']}" .
                    ($subId ? " — remaining period cloned as #$subId" : ' — no running period, applies from the next payment') .
                    " — $note", 'subscription', $subId ?: $sid);

                jsonOk(['message' => 'Plan changed to ' . $plan['name']]);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('subscriptions.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

// plan picker — inactive tiers included and flagged, so a school already on a retired plan can still be re-billed on it
$plans = [];
if ($ready) {
    try { $plans = qAll("SELECT id, name, price_monthly, price_yearly, is_active FROM plans ORDER BY sort_order ASC, name ASC"); }
    catch (Throwable $e) {}
}
$cur     = ormsCurrency();
$methods = ['Bank Transfer', 'JazzCash', 'EasyPaisa', 'Cash', 'Cheque', 'Card', 'Online', 'Other'];
$canAdd  = can('subscriptions', 'a');
$canEdit = can('subscriptions', 'e');
?>
<!--
  Developed by Mohammad Rameez Imdad (Rameez Scripts)
  WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Subscriptions - Result Management</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-file-invoice-dollar"></i> Subscriptions</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Subscriptions</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if (!$ready): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>Billing is not set up yet</h4>
                    <p>Run <b>update_setup.php</b> once to add the plan and subscription tables, then come back to this page.</p>
                </div>
            </div>
            <?php else: ?>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Schools &amp; Billing</h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-primary" id="btnRefresh"><i class="fas fa-sync"></i> Refresh</button>
                        <a class="btn btn-secondary" href="plans.php"><i class="fas fa-layer-group"></i> Plans</a>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>
                        Billing is <b>manual approval</b> — record the bank / JazzCash / WhatsApp payment here and the school is
                        unblocked immediately. A renewal always writes a <b>new period row</b>, never an edit, so the billing
                        history stays intact. Renew early and the new period starts where the old one ends, so no paid day is lost.
                    </span>
                </div>

                <div class="lte-kpi-grid" id="kpiSkeleton">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-70"></div>
                    </div>
                    <?php endfor; ?>
                </div>

                <div class="lte-kpi-grid initially-hidden" id="subKpi">
                    <div class="small-box bg-success">
                        <div class="inner"><h3 id="kpiActive">&mdash;</h3><p>Active Schools</p></div>
                        <div class="icon"><i class="fas fa-circle-check"></i></div>
                        <a href="#subTable" class="small-box-footer js-state" data-state="active">Paid up <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box bg-warning">
                        <div class="inner"><h3 id="kpiExpiring">&mdash;</h3><p>Expiring in <?php echo SUB_SOON; ?> Days</p></div>
                        <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                        <a href="#subTable" class="small-box-footer js-state" data-state="expiring">Chase these <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box bg-danger">
                        <div class="inner"><h3 id="kpiExpired">&mdash;</h3><p>Expired</p></div>
                        <div class="icon"><i class="fas fa-lock"></i></div>
                        <a href="#subTable" class="small-box-footer js-state" data-state="expired">Locked out <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box bg-navy">
                        <div class="inner"><h3 id="kpiMonth">&mdash;</h3><p id="kpiMonthLbl">Payments This Month</p></div>
                        <div class="icon"><i class="fas fa-hand-holding-dollar"></i></div>
                        <a href="#subTable" class="small-box-footer js-state" data-state="">Everything <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>

                <!-- status chips — counts come off the same fetch, click filters the table -->
                <div class="chev-pipeline" id="statePipeline"></div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnClearFilters"><i class="fas fa-times-circle"></i> Clear</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Plan</label>
                            <select id="filterPlan" class="filter-input">
                                <option value="">All Plans</option>
                                <?php foreach ($plans as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                                <option value="none">No plan set</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="subSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="subWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="subTable" class="display chip-table"></table>
                    </div>
                </div>

                <div id="subEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-city"></i>
                    <h4>No schools to bill</h4>
                    <p>Onboard a school first &mdash; every school on the platform shows up here with its billing state.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div class="modal-overlay" id="payModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-hand-holding-dollar"></i> Record Payment</h3>
                <button type="button" class="close-btn" id="btnClosePay"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="payForm">
                    <input type="hidden" id="paySchool" name="school_id">
                    <div class="bill-target" id="payTarget"></div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Plan</label>
                            <select id="payPlan" name="plan_id">
                                <option value="">&mdash; no plan &mdash;</option>
                                <?php foreach ($plans as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php
                                    echo htmlspecialchars($p['name'] . ((int)$p['is_active'] === 1 ? '' : ' — retired'));
                                ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Sets the school&rsquo;s tier and pre-fills the amount.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-repeat"></i> Billing Cycle *</label>
                            <select id="payCycle" name="cycle">
                                <option value="monthly">Monthly (1 month)</option>
                                <option value="yearly">Yearly (12 months)</option>
                                <option value="custom">Custom (days)</option>
                            </select>
                        </div>
                        <div class="form-group initially-hidden" id="payDaysGroup">
                            <label><i class="fas fa-calendar-day"></i> Days *</label>
                            <input type="number" id="payDays" name="days" min="1" max="3650" value="30">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Amount Received *</label>
                            <input type="text" id="payAmount" name="amount" inputmode="decimal" required placeholder="0.00">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Pre-filled from the plan &mdash; overwrite it if a different amount was agreed.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Currency</label>
                            <div class="bill-lock"><i class="fas fa-lock"></i> <?php echo htmlspecialchars($cur['code'] . ' ' . $cur['symbol'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Set by the platform operator in <a href="settings.php">Site Settings</a>. Payments already recorded keep the currency they were taken in.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="far fa-calendar-check"></i> Paid On *</label>
                            <input type="date" id="payDate" name="paid_on" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building-columns"></i> Method</label>
                            <select id="payMethod" name="method">
                                <option value="">&mdash; not stated &mdash;</option>
                                <?php foreach ($methods as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference</label>
                            <input type="text" id="payRef" name="reference" maxlength="100" placeholder="Transaction / slip number">
                        </div>
                        <div class="form-group bill-span-2">
                            <label><i class="fas fa-note-sticky"></i> Note</label>
                            <input type="text" id="payNote" name="note" maxlength="255" placeholder="Optional — anything the next operator should know">
                        </div>
                    </div>

                    <div class="bill-preview" id="payPreview">
                        <i class="fas fa-calendar-check"></i> <span id="payPreviewText">&mdash;</span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSavePay"><i class="fas fa-save"></i> Record Payment</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelPay"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Extend Modal -->
    <div class="modal-overlay" id="extModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-gift"></i> Extend Access</h3>
                <button type="button" class="close-btn" id="btnCloseExt"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="extForm">
                    <input type="hidden" id="extSchool" name="school_id">
                    <div class="bill-target" id="extTarget"></div>
                    <div class="info-banner mb-24">
                        <i class="fas fa-circle-info"></i>
                        <span>Free days &mdash; no money is recorded. A <b>suspended or cancelled</b> school stays blocked until that is lifted on the school itself.</span>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-plus"></i> Days *</label>
                            <input type="number" id="extDays" name="days" min="1" max="3650" value="7" required>
                        </div>
                        <div class="form-group bill-span-2">
                            <label><i class="fas fa-note-sticky"></i> Reason *</label>
                            <input type="text" id="extNote" name="note" maxlength="255" required placeholder="Why the free days were granted">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Required &mdash; this is the audit trail for giving access away.</div>
                        </div>
                    </div>
                    <div class="bill-preview" id="extPreview">
                        <i class="fas fa-calendar-check"></i> <span id="extPreviewText">&mdash;</span>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveExt"><i class="fas fa-save"></i> Extend</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelExt"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Plan Modal -->
    <div class="modal-overlay" id="planModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-right-left"></i> Change Plan</h3>
                <button type="button" class="close-btn" id="btnClosePlan"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="planForm">
                    <input type="hidden" id="planSchool" name="school_id">
                    <div class="bill-target" id="planTarget"></div>
                    <div class="info-banner mb-24">
                        <i class="fas fa-circle-info"></i>
                        <span>No money moves. The running period is <b>copied</b> onto the new plan with the same end date, so the old row survives as history and no paid day is lost.</span>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> New Plan *</label>
                            <select id="planNew" name="plan_id" required>
                                <option value="">Select plan</option>
                                <?php foreach ($plans as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php
                                    echo htmlspecialchars($p['name'] . ((int)$p['is_active'] === 1 ? '' : ' — retired'));
                                ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group bill-span-2">
                            <label><i class="fas fa-note-sticky"></i> Reason *</label>
                            <input type="text" id="planNote" name="note" maxlength="255" required placeholder="Why the school is moving tier">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Required &mdash; a tier change without a reason is unauditable.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSavePlan"><i class="fas fa-save"></i> Change Plan</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelPlan"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- History Modal -->
    <div class="modal-overlay" id="histModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="histTitle"><i class="fas fa-clock-rotate-left"></i> Billing History</h3>
                <button type="button" class="close-btn" id="btnCloseHist"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="histBody"></div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <script>
    // pdf/excel libs pulled only when an export is actually clicked
    function loadExportDeps(callback) {
        if (window.pdfMake) { callback(); return; }
        var urls = [
            'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        ];
        var loaded = 0;
        (function loadNext() {
            if (loaded >= urls.length) { callback(); return; }
            var s = document.createElement('script');
            s.src = urls[loaded];
            s.onload = function() { loaded++; loadNext(); };
            document.head.appendChild(s);
        })();
    }
    </script>

    <script>
    var esc = ORMS.esc;
    var subTable = null, subData = [], stFilter = '', planFilter = '';
    var READY  = <?= $ready ? 'true' : 'false' ?>;
    var PLANS  = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'],
                                                      'm'  => (float)$p['price_monthly'], 'y' => (float)$p['price_yearly']], $plans)) ?>;
    var CAN    = { a: <?= $canAdd ? 'true' : 'false' ?>, e: <?= $canEdit ? 'true' : 'false' ?> };
    var SOON   = <?= SUB_SOON ?>;

    // chip label + colour, keyed off the state the server computed. one map, used by the pipeline,
    // the table and the modals — so the wording can never drift between them
    var STATES = {
        active:    { label: 'Active',        icon: 'fa-circle-check' },
        expiring:  { label: 'Expiring soon', icon: 'fa-hourglass-half' },
        expired:   { label: 'Expired',       icon: 'fa-lock' },
        suspended: { label: 'Suspended',     icon: 'fa-ban' },
        cancelled: { label: 'Cancelled',     icon: 'fa-circle-xmark' }
    };
    var ORDER = ['active', 'expiring', 'expired', 'suspended', 'cancelled'];

    $(document).ready(function() {
        if (!READY) return;
        ORMS.dropdown('#filterPlan, #payPlan, #payCycle, #payMethod, #planNew');

        loadSubs();

        $('#btnRefresh').on('click', function() { loadSubs(this); });
        $(document).on('click', '#statePipeline .chev-item', function() { setState($(this).attr('data-state') || ''); });
        $(document).on('click', '.js-state', function(e) { e.preventDefault(); setState($(this).attr('data-state') || ''); });
        $('#filterPlan').on('change', function() { planFilter = this.value || ''; if (subTable) subTable.draw(); });
        $('#btnClearFilters').on('click', function() {
            $('#filterPlan').val('');
            ORMS.dropdown.refresh('#filterPlan');
            planFilter = '';
            setState('');
        });

        $('#btnClosePay, #btnCancelPay').on('click', function() { $('#payModal').removeClass('active'); });
        $('#btnCloseExt, #btnCancelExt').on('click', function() { $('#extModal').removeClass('active'); });
        $('#btnClosePlan, #btnCancelPlan').on('click', function() { $('#planModal').removeClass('active'); });
        $('#btnCloseHist').on('click', function() { $('#histModal').removeClass('active'); });
        $('.modal-overlay').on('click', function(e) { if (e.target === this) $(this).removeClass('active'); });

        $('#payCycle').on('change', function() {
            $('#payDaysGroup').toggleClass('initially-hidden', this.value !== 'custom');
            fillAmount();
            drawPreview();
        });
        $('#payPlan').on('change', fillAmount);
        $('#payDays').on('input', drawPreview);
        $('#extDays').on('input', extPreview);

        // one custom filter for the whole page — the chips and the plan select both feed it
        $.fn.dataTable.ext.search.push(function(settings, data, idx) {
            if (settings.nTable.id !== 'subTable') return true;
            var r = settings.aoData[idx] && settings.aoData[idx]._aData;
            if (!r) return true;
            if (stFilter && r.state !== stFilter) return false;
            if (planFilter === 'none') return !r.plan_id;
            if (planFilter && String(r.plan_id) !== planFilter) return false;
            return true;
        });
    });

    function today() { return new Date().toISOString().slice(0, 10); }

    function fmtDate(ymd) {
        if (!ymd) return '';
        var d = new Date(ymd + 'T00:00:00');
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    // mirrors subAddMonths() on the server — clamp, so 31 Jan + 1 month is the last day of Feb
    function addMonths(ymd, n) {
        var p = ymd.split('-'), day = +p[2];
        var t = new Date(Date.UTC(+p[0], +p[1] - 1 + n, 1));
        var last = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate();
        t.setUTCDate(Math.min(day, last));
        return t.toISOString().slice(0, 10);
    }

    function addDays(ymd, n) {
        var t = new Date(ymd + 'T00:00:00Z');
        t.setUTCDate(t.getUTCDate() + n);
        return t.toISOString().slice(0, 10);
    }

    // the later of today and the running end date — same rule the transaction uses
    function startFrom(row) {
        var t = today();
        return (row && row.ends_at && row.ends_at > t) ? row.ends_at : t;
    }

    function stateChip(st, days) {
        var m = STATES[st] || STATES.active;
        var tail = '';
        if (st === 'expiring' && days !== null && days !== undefined) tail = ' · ' + days + 'd';
        if (st === 'expired'  && days !== null && days !== undefined) tail = ' · ' + Math.abs(days) + 'd ago';
        return '<span class="bill-chip bill-st-' + st + '"><i class="fas ' + m.icon + '"></i> ' + m.label + tail + '</span>';
    }

    function loadSubs(btn) {
        ORMS.post('getSubs', {}, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load subscriptions'); return; }
            subData = res.data || [];
            $('#subSkeleton, #kpiSkeleton').addClass('initially-hidden');
            $('#subKpi').removeClass('initially-hidden');
            renderKpi(res.kpi || {});
            renderPipeline(res.counts || {});
            // nothing to show -> icon+message, never a blank table body
            $('#subWrap').toggleClass('initially-hidden', !subData.length);
            $('#subEmpty').toggleClass('initially-hidden', !!subData.length);
            renderTable();
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function renderKpi(k) {
        $('#kpiActive').text(k.active || 0);
        $('#kpiExpiring').text(k.expiring || 0);
        $('#kpiExpired').text(k.expired || 0);
        $('#kpiMonth').text(k.month || '—');
        $('#kpiMonthLbl').text((k.month_lbl || 'This month') + ' · ' + (k.month_n || 0) + ' payment' +
                               ((k.month_n || 0) === 1 ? '' : 's') + (k.month_mix ? ' (mixed currencies)' : ''));
    }

    function renderPipeline(counts) {
        var html = '<button type="button" class="chev-item' + (stFilter === '' ? ' active' : '') + '" data-state="">' +
                   '<span class="chev-label"><i class="fas fa-city"></i> All</span>' +
                   '<span class="chev-count">' + subData.length + '</span></button>';
        ORDER.forEach(function(k) {
            html += '<button type="button" class="chev-item' + (stFilter === k ? ' active' : '') + '" data-state="' + k + '">' +
                    '<span class="chev-label"><i class="fas ' + STATES[k].icon + '"></i> ' + STATES[k].label + '</span>' +
                    '<span class="chev-count">' + (counts[k] || 0) + '</span></button>';
        });
        $('#statePipeline').html(html);
    }

    function setState(k) {
        stFilter = k || '';
        renderPipeline(lastCounts());
        if (subTable) subTable.draw();
    }

    // counts stay in sync with whatever is loaded, without a second round trip
    function lastCounts() {
        var c = { active: 0, expiring: 0, expired: 0, suspended: 0, cancelled: 0 };
        subData.forEach(function(r) { if (c[r.state] !== undefined) c[r.state]++; });
        return c;
    }

    // ---- chip cells: 7 flat columns -> 4 grouped ones. The page filter reads the ROW data
    // (_aData), not a column, so nothing here needs a hidden column. ----
    var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box, DASH = ORMS.DASH;
    function bv(x) { return (x === null || x === undefined || x === '') ? DASH : esc(x); }

    function cellSchoolSub(r) {
        return K([
            '<div class="cell-title"><i class="fas fa-city"></i> ' + esc(r.name) + '</div>',
            R(C('chip-navy', 'fa-hashtag', 'Code'), BOX(r.code)),
            R(C('chip-soft-purple', 'fa-layer-group', 'Plan'),
              r.plan_name ? esc(r.plan_name) : '<span class="val-muted">no plan</span>')
        ]);
    }

    function cellPeriod(r) {
        return K([
            R(C('chip-soft-green', 'fa-flag-checkered', 'Status'), stateChip(r.state, r.days)),
            R(C('chip-soft-navy', 'fa-calendar-plus', 'Starts'), r.starts_f ? esc(r.starts_f) : DASH),
            R(C('chip-soft-tan', 'fa-calendar-check', 'Ends'),
              r.ends_f ? esc(r.ends_f) : '<span class="val-muted">never billed</span>')
        ]);
    }

    function cellMoney(r) {
        var n = Number(r.pays) || 0;
        return K([
            R(C('chip-green', 'fa-hand-holding-dollar', 'Received'), esc(r.paid_f), 'val-amount'),
            R(C('chip-soft-navy', 'fa-receipt', 'Payments'), n ? String(n) : '<span class="val-muted">none</span>'),
            n && r.last_paid_f ? R(C('chip-soft-navy', 'fa-clock-rotate-left', 'Last paid'), esc(r.last_paid_f)) : ''
        ]);
    }

    function subBlob(r) {
        return [r.name, r.code, r.plan_name, r.state, r.starts_f, r.ends_f, r.paid_f].filter(Boolean).join(' ');
    }

    // chip html for display, the real value for sort, a text blob for filter
    function subCol(title, build, sortField, cls) {
        return { data: null, title: title, className: cls || '', render: function (d, t, r) {
            if (t === 'display') return build(r);
            if (t === 'filter')  return subBlob(r);
            var v = r[sortField];
            return v === null || v === undefined ? '' : v;
        } };
    }

    function renderTable() {
        if (subTable) { subTable.destroy(); $('#subTable').empty(); }
        var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
        subTable = $('#subTable').DataTable({
            data: subData,
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            responsive: false,          // the chips carry the density; .table-responsive scrolls
            destroy: true,
            order: [],                  // the server orders the list already
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: xOpts },
                { text: '<i class="fas fa-file-pdf"></i> PDF',
                  action: function(e, dt, node, config) {
                      loadExportDeps(function() { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  },
                  exportOptions: xOpts },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: xOpts }
            ],
            columns: [
                subCol('School', cellSchoolSub, 'name'),
                subCol('Period', cellPeriod, 'ends_at'),
                subCol('Money', cellMoney, 'paid'),
                { data: null, title: 'Actions', orderable: false, className: 'col-actions',
                  render: function(d, t, row) { return t === 'display' ? '<div class="actions-cell">' + rowActions(row) + '</div>' : ''; } }
            ]
        });
    }

    function rowActions(row) {
        var b = '';
        if (CAN.a) b += '<button type="button" class="action-icon view-icon" title="Record payment" onclick="openPay(' + row.id + ')"><i class="fas fa-hand-holding-dollar"></i></button>';
        if (CAN.e) {
            b += '<button type="button" class="action-icon edit-icon" title="Extend (free days)" onclick="openExt(' + row.id + ')"><i class="fas fa-gift"></i></button>';
            b += '<button type="button" class="action-icon view-icon" title="Change plan" onclick="openPlan(' + row.id + ')"><i class="fas fa-right-left"></i></button>';
        }
        b += '<button type="button" class="action-icon view-icon" title="Billing history" onclick="openHist(' + row.id + ', this)"><i class="fas fa-clock-rotate-left"></i></button>';
        return b;
    }

    function rowOf(id) { return subData.filter(function(x) { return x.id == id; })[0] || null; }

    function targetHtml(row) {
        return '<b><i class="fas fa-city"></i> ' + esc(row.name) + '</b> <span class="bill-code">' + esc(row.code) + '</span> ' +
               stateChip(row.state, row.days) +
               (row.ends_f ? '<span class="bill-sub">Access runs to ' + esc(row.ends_f) + '</span>'
                           : '<span class="bill-sub">Never billed</span>');
    }

    // ---------------------------------------------------------- record payment
    function openPay(id) {
        var row = rowOf(id);
        if (!row) return;
        $('#payForm')[0].reset();
        $('#paySchool').val(row.id);
        $('#payTarget').html(targetHtml(row));
        $('#payPlan').val(row.plan_id || row.school_plan_id || '');
        $('#payCycle').val('monthly');
        $('#payDaysGroup').addClass('initially-hidden');
        $('#payDays').val(30);
        $('#payDate').val(today());
        $('#payMethod').val('');

        ORMS.dropdown.refresh('#payPlan, #payCycle, #payMethod');

        fillAmount();
        drawPreview();
        $('#payModal').addClass('active');
    }

    function planOf(id) { return PLANS.filter(function(p) { return p.id == id; })[0] || null; }

    // plan price for the chosen cycle. custom days get no automatic price — that is a negotiated number
    function fillAmount() {
        var p = planOf($('#payPlan').val()), cyc = $('#payCycle').val();
        if (!p || cyc === 'custom') return;
        $('#payAmount').val(ORMS.money(cyc === 'yearly' ? p.y : p.m));
    }

    function drawPreview() {
        var row = rowOf($('#paySchool').val());
        if (!row) return;
        var s = startFrom(row), cyc = $('#payCycle').val();
        var e = cyc === 'custom' ? addDays(s, Math.max(1, parseInt($('#payDays').val(), 10) || 0))
                                 : addMonths(s, cyc === 'yearly' ? 12 : 1);
        var early = row.ends_at && row.ends_at > today();
        $('#payPreviewText').html('Access will run <b>' + esc(fmtDate(s)) + '</b> &rarr; <b>' + esc(fmtDate(e)) + '</b>' +
            (early ? ' <span class="bill-sub">Early renewal — it starts where the current period ends, so no paid day is lost.</span>'
                   : ''));
    }

    var MONEY_RE = /^\d{1,8}(\.\d{1,2})?$/;

    $('#payForm').on('submit', function(e) {
        e.preventDefault();
        var amt = ($('#payAmount').val() || '').trim();
        var cyc = $('#payCycle').val(), days = parseInt($('#payDays').val(), 10) || 0;
        if (!MONEY_RE.test(amt)) { ORMS.err('Amount must be a number with up to 2 decimals'); return; }
        if (Math.round(parseFloat(amt) * 100) <= 0) { ORMS.err('Amount must be more than zero'); return; }
        if (!$('#payDate').val()) { ORMS.err('Enter the payment date'); return; }
        if (cyc === 'custom' && (days < 1 || days > 3650)) { ORMS.err('Custom length must be between 1 and 3650 days'); return; }

        ORMS.post('recordPayment', {
            school_id: $('#paySchool').val(), plan_id: $('#payPlan').val() || 0,
            cycle: cyc, days: days, amount: amt,
            paid_on: $('#payDate').val(), method: $('#payMethod').val(),
            reference: $('#payRef').val(), note: $('#payNote').val()
        }, { btn: '#btnSavePay', busyLabel: 'Recording…', verb: 'Recording payment…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            $('#payModal').removeClass('active');
            ORMS.ok(res.message);
            loadSubs();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    // ---------------------------------------------------------------- extend
    function openExt(id) {
        var row = rowOf(id);
        if (!row) return;
        $('#extForm')[0].reset();
        $('#extSchool').val(row.id);
        $('#extTarget').html(targetHtml(row));
        $('#extDays').val(7);
        extPreview();
        $('#extModal').addClass('active');
        setTimeout(function() { $('#extNote').trigger('focus'); }, 60);
    }

    function extPreview() {
        var row = rowOf($('#extSchool').val());
        if (!row) return;
        var s = startFrom(row), n = Math.max(1, parseInt($('#extDays').val(), 10) || 0);
        $('#extPreviewText').html('Access will run <b>' + esc(fmtDate(s)) + '</b> &rarr; <b>' + esc(fmtDate(addDays(s, n))) + '</b>');
    }

    $('#extForm').on('submit', function(e) {
        e.preventDefault();
        var days = parseInt($('#extDays').val(), 10) || 0, note = ($('#extNote').val() || '').trim();
        if (days < 1 || days > 3650) { ORMS.err('Extension must be between 1 and 3650 days'); return; }
        if (note.length < 3) { ORMS.err('A note is required — say why the free days were granted'); return; }

        ORMS.post('extendSub', { school_id: $('#extSchool').val(), days: days, note: note },
                  { btn: '#btnSaveExt', busyLabel: 'Extending…', verb: 'Extending…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            $('#extModal').removeClass('active');
            ORMS.ok(res.message);
            loadSubs();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    // ----------------------------------------------------------- change plan
    function openPlan(id) {
        var row = rowOf(id);
        if (!row) return;
        $('#planForm')[0].reset();
        $('#planSchool').val(row.id);
        $('#planTarget').html(targetHtml(row));
        $('#planNew').val(row.plan_id || row.school_plan_id || '');
        ORMS.dropdown.refresh('#planNew');
        $('#planModal').addClass('active');
    }

    $('#planForm').on('submit', function(e) {
        e.preventDefault();
        var plan = $('#planNew').val(), note = ($('#planNote').val() || '').trim();
        if (!plan) { ORMS.err('Please choose a plan'); return; }
        if (note.length < 3) { ORMS.err('A note is required — say why the plan changed'); return; }

        ORMS.post('changePlan', { school_id: $('#planSchool').val(), plan_id: plan, note: note },
                  { btn: '#btnSavePlan', busyLabel: 'Changing…', verb: 'Changing plan…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            $('#planModal').removeClass('active');
            ORMS.ok(res.message);
            loadSubs();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    // ---------------------------------------------------------------- history
    function openHist(id, btn) {
        ORMS.post('getHistory', { school_id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            $('#histTitle').html('<i class="fas fa-clock-rotate-left"></i> ' + esc(res.school.name) +
                                 ' <span class="bill-code">' + esc(res.school.code) + '</span>');
            $('#histBody').html(histTables(res.periods || [], res.payments || []));
            $('#histModal').addClass('active');
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function histTables(periods, pays) {
        var h = '<h4 class="bill-h"><i class="fas fa-calendar-days"></i> Billing Periods</h4>';
        if (!periods.length) {
            h += '<div class="orms-empty"><i class="fas fa-calendar-xmark"></i><h4>Never billed</h4><p>Record a payment to start this school&rsquo;s billing history.</p></div>';
        } else {
            h += '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
                 '<th><i class="fas fa-calendar-day"></i> From</th><th><i class="fas fa-calendar-check"></i> To</th>' +
                 '<th><i class="fas fa-layer-group"></i> Plan</th><th><i class="fas fa-coins"></i> Amount</th>' +
                 '<th><i class="fas fa-flag"></i> Status</th><th><i class="fas fa-note-sticky"></i> Note</th>' +
                 '<th><i class="fas fa-user"></i> By</th></tr></thead><tbody>';
            periods.forEach(function(p) {
                h += '<tr><td>' + esc(p.starts_f) + '</td><td>' + esc(p.ends_f) + '</td>' +
                     '<td>' + (p.plan_name ? esc(p.plan_name) : '<span class="text-muted">—</span>') + '</td>' +
                     '<td>' + esc(p.amount_f) + '</td>' +
                     '<td><span class="status-badge ' + (p.status === 'Active' ? 'status-active' : 'status-inactive') + '">' + esc(p.status) + '</span></td>' +
                     '<td>' + (p.notes ? esc(p.notes) : '<span class="text-muted">—</span>') + '</td>' +
                     '<td>' + (p.by_name ? esc(p.by_name) : '<span class="text-muted">—</span>') + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }

        h += '<h4 class="bill-h"><i class="fas fa-receipt"></i> Payments Received</h4>';
        if (!pays.length) {
            h += '<div class="orms-empty"><i class="fas fa-receipt"></i><h4>No payments yet</h4><p>Nothing has been received from this school.</p></div>';
        } else {
            h += '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
                 '<th><i class="fas fa-calendar-day"></i> Paid On</th><th><i class="fas fa-coins"></i> Amount</th>' +
                 '<th><i class="fas fa-building-columns"></i> Method</th><th><i class="fas fa-hashtag"></i> Reference</th>' +
                 '<th><i class="fas fa-note-sticky"></i> Note</th><th><i class="fas fa-user"></i> By</th></tr></thead><tbody>';
            pays.forEach(function(y) {
                h += '<tr><td>' + esc(y.paid_on_f) + '</td><td>' + esc(y.amount_f) + '</td>' +
                     '<td>' + (y.method ? esc(y.method) : '<span class="text-muted">—</span>') + '</td>' +
                     '<td>' + (y.reference ? esc(y.reference) : '<span class="text-muted">—</span>') + '</td>' +
                     '<td>' + (y.note ? esc(y.note) : '<span class="text-muted">—</span>') + '</td>' +
                     '<td>' + (y.by_name ? esc(y.by_name) : '<span class="text-muted">—</span>') + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }
        return h;
    }
    </script>
</body>
</html>
