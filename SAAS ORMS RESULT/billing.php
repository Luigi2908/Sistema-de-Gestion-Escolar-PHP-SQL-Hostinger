<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * The School Owner's money page — this school's plan, what it is actually using against the plan's
 * limits, and self-serve renewal. Deliberately separate from fees.php: that is money a school
 * collects from parents, this is money the school pays the platform.
 */
require_once 'billing_engine.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

requirePerm('billing', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = (int) $_SESSION['user_id'];
$current_page = 'billing';
$school_id    = sid();

// billing tables land in one migration step — one probe answers for the lot
function bilReady(): bool {
    static $ok = null;
    if ($ok === null) {
        if (function_exists('ormsEnsureSchoolColumns')) ormsEnsureSchoolColumns();
        try { qVal("SELECT id FROM billing_invoices LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// A tenant page: the platform operator has gateways.php instead. Repeated on every ajax branch
// because the page gate alone stops nothing that posts straight at billing.php.
function bilDeny(bool $json): void {
    if (sid() > 0) return;
    if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Pick a school first — the operator manages billing from Payment Gateways.']); exit(); }
    header('Location: gateways.php');
    exit();
}

$ready = bilReady();

if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'];
    bilDeny(true);
    if (!$ready) jsonErr('Billing tables are missing — run update_setup.php once.');

    try {
        switch ($action) {

            // ---- read: everything the page needs, in one round trip
            case 'getBilling': {
                requirePermJson('billing', 'v');
                bilExpireStale($school_id);

                $school = qOne("SELECT s.id, s.name, s.code, s.status, s.trial_ends_at, s.plan_id, p.name AS plan_name
                                FROM schools s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?", 'i', $school_id);
                $state  = ormsSubscriptionState($school_id);
                $days   = !empty($state['ends_at']) ? (int) floor((strtotime($state['ends_at']) - strtotime(date('Y-m-d'))) / 86400) : null;

                // priced in THIS school's currency. A plan with no price in it is still listed, with
                // its prices at 0 — the checkout refuses it by name instead of charging the wrong money.
                $bccy  = bilCurrencyFor($school_id);
                $plans = qAll("SELECT id, name, price_monthly, price_yearly, max_students, max_teachers, max_branches, features
                               FROM plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
                foreach ($plans as &$pp) {
                    $pp['price_monthly'] = bilPriceIn($pp, 'monthly', $bccy);
                    $pp['price_yearly']  = bilPriceIn($pp, 'yearly', $bccy);
                    $pp['priced']        = ($pp['price_monthly'] > 0 || $pp['price_yearly'] > 0) ? 1 : 0;
                }
                unset($pp);

                $invoices = qAll("SELECT i.id, i.invoice_no, i.cycle, i.amount, i.currency, i.status, i.gateway,
                                         i.period_start, i.period_end, i.paid_at, i.created_at, i.proof, p.name AS plan_name
                                  FROM billing_invoices i LEFT JOIN plans p ON p.id = i.plan_id
                                  WHERE i.school_id = ? ORDER BY i.id DESC LIMIT 100", 'i', $school_id);

                $gws = [];
                foreach (bilLiveGateways() as $g) $gws[] = ['key' => $g['key'], 'label' => $g['label'], 'icon' => $g['icon'], 'kind' => $g['kind'], 'blurb' => $g['blurb']];

                jsonOk([
                    'school'   => $school,
                    'state'    => $state + ['days' => $days],
                    'quotas'   => array_values(ormsQuotaAll($school_id)),
                    'plans'    => $plans,
                    'invoices' => $invoices,
                    'gateways' => $gws,
                    'currency' => bilCurrencyFor($school_id),
                    'billing_on' => bilEnabled(),
                    'test_mode'  => bilTestMode(),
                    'manual_help' => (string) ormsPlatformSetting('gw_manual_instructions', ''),
                    'whatsapp'   => preg_replace('/\D+/', '', (string) getSetting('platform_whatsapp', '')),
                ]);
            }

            // ---- write: raise (or reuse) an invoice and hand it to its gateway
            case 'startCheckout': {
                requireCsrfJson();
                requirePermJson('billing', 'a');
                if (!bilEnabled()) jsonErr('Self-serve checkout is switched off. Please contact the platform operator.');

                $planId = (int) ($_POST['plan_id'] ?? 0);
                $cycle  = ($_POST['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
                $gwKey  = preg_replace('/[^a-z_]/', '', strtolower((string) ($_POST['gateway'] ?? '')));

                $plan = $planId ? qOne("SELECT * FROM plans WHERE id = ? AND is_active = 1", 'i', $planId) : null;
                if (!$plan) jsonErr('Pick a plan that is still available');

                $live = bilLiveGateways();
                if (!isset($live[$gwKey])) jsonErr('That payment method is not available right now');

                // downgrading below what the school already uses would leave it instantly over its
                // own limit, so it is refused here rather than discovered on the next Add click
                foreach (ormsQuotaKinds() as $meta) {
                    $cap = (int) ($plan[$meta['col']] ?? 0);
                    if ($cap <= 0) continue;                                  // unlimited on the target plan
                    try { $used = (int) qVal($meta['sql'], 'i', $school_id); } catch (Throwable $e) { continue; }
                    if ($used > $cap) jsonErr(sprintf('This school already has %d %s and the %s plan allows %d. Pick a larger plan.',
                        $used, strtolower($meta['label']) . 's', $plan['name'], $cap));
                }

                $inv = bilCreateInvoice($school_id, $plan, $cycle, $gwKey, $user_id);
                $res = bilStartCheckout($inv, $plan);
                if (empty($res['ok'])) jsonErr($res['message'] ?? 'Could not start the payment');

                logActivity($user_id, $username, 'Checkout Started',
                    'Invoice ' . $inv['invoice_no'] . ' — ' . $plan['name'] . ' ' . $cycle . ' via ' . $gwKey,
                    'billing_invoices', (int) $inv['id'], $school_id);

                jsonOk(['invoice' => $inv, 'redirect' => $res['redirect'] ?? null,
                        'manual' => $inv['gateway'] === 'manual',
                        'message' => $inv['gateway'] === 'manual' ? 'Invoice ' . $inv['invoice_no'] . ' raised' : 'Redirecting to payment…']);
            }

            // ---- write: "I have transferred it" — a claim, never an activation
            case 'submitProof': {
                requireCsrfJson();
                requirePermJson('billing', 'a');

                $id  = (int) ($_POST['invoice_id'] ?? 0);
                $ref = trim((string) ($_POST['reference'] ?? ''));
                if ($ref === '' || mb_strlen($ref) > 200) jsonErr('Enter the transfer reference (up to 200 characters)');

                $inv = qOne("SELECT * FROM billing_invoices WHERE id = ? AND school_id = ?", 'ii', $id, $school_id);
                if (!$inv)                          jsonErr('Invoice not found');
                if ($inv['status'] !== 'Pending')   jsonErr('That invoice is already ' . strtolower($inv['status']));

                qExec("UPDATE billing_invoices SET proof = ? WHERE id = ?", 'si', mb_substr($ref, 0, 255), $id);

                $owner = ormsAppOwnerId();
                if ($owner) createNotification($owner, 'Transfer reported',
                    'Invoice ' . $inv['invoice_no'] . ' — ' . bilMoney((float) $inv['amount'], $inv['currency']) . ' — reference: ' . $ref,
                    'info', 'gateways.php');

                logActivity($user_id, $username, 'Transfer Reported', 'Invoice ' . $inv['invoice_no'] . ' ref ' . $ref, 'billing_invoices', $id, $school_id);
                jsonOk(['message' => 'Reference sent. Your access opens as soon as it is confirmed.']);
            }

            case 'cancelInvoice': {
                requireCsrfJson();
                requirePermJson('billing', 'd');

                $id  = (int) ($_POST['invoice_id'] ?? 0);
                $inv = qOne("SELECT * FROM billing_invoices WHERE id = ? AND school_id = ?", 'ii', $id, $school_id);
                if (!$inv)                        jsonErr('Invoice not found');
                if ($inv['status'] !== 'Pending') jsonErr('Only a pending invoice can be cancelled');

                qExec("UPDATE billing_invoices SET status = 'Cancelled' WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Invoice Cancelled', $inv['invoice_no'], 'billing_invoices', $id, $school_id);
                jsonOk(['message' => 'Invoice cancelled']);
            }

            default: jsonErr('Unknown action');
        }
    } catch (Throwable $e) {
        error_log('billing.php: ' . $e->getMessage());
        jsonErr('Something went wrong: ' . $e->getMessage());
    }
    exit();
}

bilDeny(false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Subscription - Result Management</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body class="page-billing">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-receipt"></i> Subscription</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Subscription</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if (!$ready): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>Billing is not set up yet</h4>
                    <p>Run <b>update_setup.php</b> once to add the invoice tables, then come back to this page.</p>
                </div>
            </div>
            <?php else: ?>

            <div class="data-section" id="bilStatusWrap">
                <div class="section-header">
                    <h2><i class="fas fa-circle-info"></i> Current Plan</h2>
                </div>
                <div id="bilStatus" class="bil-status"></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-gauge-high"></i> Plan Usage</h2>
                </div>
                <p class="bil-hint">What this school is using against the limits of its plan. Adding beyond a limit is blocked until the plan is upgraded.</p>
                <div id="bilQuotas" class="bil-quotas"></div>
            </div>

            <div class="data-section" id="bilPlansWrap">
                <div class="section-header">
                    <h2><i class="fas fa-layer-group"></i> Plans</h2>
                    <div class="btn-group-inline">
                        <div class="bil-cycle" id="bilCycle">
                            <button type="button" class="bil-cyc active" data-cycle="monthly"><i class="fas fa-calendar-day"></i> Monthly</button>
                            <button type="button" class="bil-cyc" data-cycle="yearly"><i class="fas fa-calendar-check"></i> Yearly</button>
                        </div>
                    </div>
                </div>
                <div id="bilPlans" class="bil-plans"></div>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-file-invoice"></i> Invoices</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-light" id="btnBilRefresh"><i class="fas fa-rotate"></i> Refresh</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="bilTable" class="display responsive nowrap" width="100%">
                        <thead><tr>
                            <th>Invoice</th><th>Plan</th><th>Cycle</th><th>Amount</th>
                            <th>Method</th><th>Status</th><th>Period</th><th>Raised</th><th>Action</th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- pay modal -->
    <div class="modal-overlay" id="bilPayModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-credit-card"></i> <span id="bilPayTitle">Checkout</span></h3>
                <button type="button" class="modal-close" data-close="bilPayModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="bil-sum" id="bilPaySummary"></div>
                <div class="form-group">
                    <label><i class="fas fa-wallet"></i> Payment method</label>
                    <div id="bilGwList" class="bil-gws"></div>
                </div>
                <div class="bil-manual" id="bilManualHelp" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-close="bilPayModal"><i class="fas fa-times"></i> Close</button>
                <button type="button" class="btn btn-primary" id="btnBilPay"><i class="fas fa-arrow-right"></i> Continue</button>
            </div>
        </div>
    </div>

    <!-- transfer reference modal -->
    <div class="modal-overlay" id="bilProofModal">
        <div class="modal modal-sm" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-paper-plane"></i> Report a transfer</h3>
                <button type="button" class="modal-close" data-close="bilProofModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bilProofId">
                <div class="form-group">
                    <label for="bilProofRef"><i class="fas fa-hashtag"></i> Transfer reference</label>
                    <input type="text" id="bilProofRef" maxlength="200" placeholder="Bank reference / transaction id">
                </div>
                <p class="bil-hint">Access opens once the platform confirms the transfer. Sending a reference does not activate it by itself.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-close="bilProofModal"><i class="fas fa-times"></i> Cancel</button>
                <button type="button" class="btn btn-primary" id="btnBilProof"><i class="fas fa-paper-plane"></i> Send</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>
    <script>
    $(function () {
        var DATA = null, CYCLE = 'monthly', PICK = null, dt = null;
        var canPay = <?= can('billing', 'a') ? 'true' : 'false' ?>;
        var canDel = <?= can('billing', 'd') ? 'true' : 'false' ?>;

        // one shared row lookup: with responsive on, a phone collapses the action column into a
        // child <tr> that carries no row data, and closest('tr') alone then returns undefined
        function dtRow(t, el) {
            var $tr = $(el).closest('tr');
            return t.row($tr.hasClass('child') ? $tr.prev('tr') : $tr);
        }

        function money(v, c) { return (c || DATA.currency) + ' ' + ORMS.money(v, 2); }

        function chip(st) {
            var m = { Paid: 'ok', Pending: 'warn', Failed: 'bad', Cancelled: 'mut', Expired: 'mut' };
            return '<span class="bil-chip bil-' + (m[st] || 'mut') + '">' + ORMS.esc(st) + '</span>';
        }

        function renderStatus() {
            var s = DATA.school || {}, st = DATA.state || {}, d = st.days;
            var tone = st.status === 'active' ? 'ok' : (st.status === 'expired' ? 'bad' : 'warn');
            if (st.status === 'active' && d !== null && d <= 14) tone = 'warn';
            var till = st.ends_at ? new Date(st.ends_at).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
            var left = (d === null || d === undefined) ? '' :
                '<span class="bil-days">' + (d < 0 ? Math.abs(d) + ' days overdue' : d + ' days left') + '</span>';

            $('#bilStatus').html(
                '<div class="bil-card bil-' + tone + '">' +
                    '<div class="bil-card-main">' +
                        '<span class="bil-plan-name"><i class="fas fa-layer-group"></i> ' + ORMS.esc(s.plan_name || 'No plan') + '</span>' +
                        '<span class="bil-chip bil-' + tone + '">' + ORMS.esc((s.status || '').toString()) + '</span>' +
                    '</div>' +
                    '<div class="bil-card-meta">' +
                        '<span><i class="far fa-calendar"></i> Valid to ' + ORMS.esc(till) + '</span>' + left +
                        '<span><i class="fas fa-city"></i> ' + ORMS.esc(s.name || '') + ' (' + ORMS.esc(s.code || '') + ')</span>' +
                    '</div>' +
                    (DATA.billing_on ? '' : '<p class="bil-hint"><i class="fas fa-circle-info"></i> Online payment is switched off. Contact the platform operator to renew.</p>') +
                    (DATA.test_mode && DATA.billing_on ? '<p class="bil-hint"><i class="fas fa-flask"></i> Gateways are in test mode.</p>' : '') +
                '</div>');
        }

        function renderQuotas() {
            var h = (DATA.quotas || []).map(function (q) {
                if (!q.enforced) return '';
                var pct = q.unlimited ? 0 : Math.min(100, Math.round((q.used / Math.max(1, q.cap)) * 100));
                var tone = q.unlimited ? 'ok' : (pct >= 100 ? 'bad' : (pct >= 80 ? 'warn' : 'ok'));
                return '<div class="bil-meter bil-' + tone + '">' +
                    '<div class="bil-meter-top"><span>' + ORMS.esc(q.label) + 's</span>' +
                        '<b>' + q.used + (q.unlimited ? ' <span class="bil-inf">/ unlimited</span>' : ' / ' + q.cap) + '</b></div>' +
                    '<div class="bil-bar"><i style="width:' + (q.unlimited ? 4 : pct) + '%"></i></div>' +
                    (q.unlimited ? '' : '<span class="bil-left">' + q.left + ' remaining</span>') +
                '</div>';
            }).join('');
            $('#bilQuotas').html(h || '<p class="bil-hint">Plan limits are not enforced on this install.</p>');
        }

        function renderPlans() {
            var cur = (DATA.school || {}).plan_id;
            var h = (DATA.plans || []).map(function (p) {
                var price = CYCLE === 'yearly' ? p.price_yearly : p.price_monthly;
                var lim = [
                    (+p.max_students ? p.max_students : 'Unlimited') + ' students',
                    (+p.max_teachers ? p.max_teachers : 'Unlimited') + ' teachers',
                    (+p.max_branches ? p.max_branches : 'Unlimited') + ' branches'
                ];
                var isCur = String(cur) === String(p.id);
                return '<div class="bil-plan' + (isCur ? ' is-current' : '') + '">' +
                    '<h4>' + ORMS.esc(p.name) + (isCur ? ' <span class="bil-chip bil-ok">Current</span>' : '') + '</h4>' +
                    '<div class="bil-price">' + money(price) + '<small>/' + (CYCLE === 'yearly' ? 'year' : 'month') + '</small></div>' +
                    '<ul class="bil-lims">' + lim.map(function (l) { return '<li><i class="fas fa-check"></i> ' + ORMS.esc(l) + '</li>'; }).join('') + '</ul>' +
                    (p.features ? '<p class="bil-feat">' + ORMS.esc(p.features) + '</p>' : '') +
                    (canPay && +price > 0
                        ? '<button class="btn btn-primary bil-pick" data-id="' + p.id + '"><i class="fas fa-credit-card"></i> ' + (isCur ? 'Renew' : 'Choose') + '</button>'
                        : '<button class="btn btn-light" disabled><i class="fas fa-lock"></i> ' + (+price > 0 ? 'No permission' : 'Not for sale') + '</button>') +
                '</div>';
            }).join('');
            $('#bilPlans').html(h || '<p class="bil-hint">No plans are on sale right now.</p>');
        }

        function renderInvoices() {
            var rows = (DATA.invoices || []).map(function (i) {
                var period = (i.period_start && i.period_end)
                    ? new Date(i.period_start).toLocaleDateString() + ' → ' + new Date(i.period_end).toLocaleDateString() : '—';
                var act = '';
                if (i.status === 'Pending') {
                    if (canPay && i.gateway === 'manual') act += '<button class="btn btn-sm btn-primary bil-proof" data-id="' + i.id + '"><i class="fas fa-paper-plane"></i></button> ';
                    if (canDel) act += '<button class="btn btn-sm btn-danger bil-cancel" data-id="' + i.id + '"><i class="fas fa-times"></i></button>';
                }
                return [
                    ORMS.esc(i.invoice_no), ORMS.esc(i.plan_name || '—'), ORMS.esc(i.cycle),
                    money(i.amount, i.currency), ORMS.esc(i.gateway), chip(i.status), ORMS.esc(period),
                    new Date(i.created_at).toLocaleDateString(), act || '—'
                ];
            });
            if (dt) { dt.clear().rows.add(rows).draw(); return; }
            dt = $('#bilTable').DataTable({
                data: rows, pageLength: 10, responsive: true, order: [],
                language: { emptyTable: 'No invoices yet' }
            });
        }

        function load() {
            ORMS.post('getBilling', {}).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                DATA = res;
                renderStatus(); renderQuotas(); renderPlans(); renderInvoices();
            }).fail(function (m) { ORMS.err(m); });
        }

        $('#bilCycle').on('click', '.bil-cyc', function () {
            CYCLE = $(this).data('cycle');
            $('#bilCycle .bil-cyc').removeClass('active');
            $(this).addClass('active');
            renderPlans();
        });

        $('#bilPlans').on('click', '.bil-pick', function () {
            var id = String($(this).data('id'));
            PICK = (DATA.plans || []).filter(function (p) { return String(p.id) === id; })[0];
            if (!PICK) return;
            var price = CYCLE === 'yearly' ? PICK.price_yearly : PICK.price_monthly;

            $('#bilPayTitle').text(PICK.name + ' — ' + CYCLE);
            $('#bilPaySummary').html('<span>' + ORMS.esc(PICK.name) + ' · ' + CYCLE + '</span><b>' + money(price) + '</b>');

            var gws = DATA.gateways || [];
            $('#bilGwList').html(gws.length
                ? gws.map(function (g, ix) {
                    return '<label class="bil-gw"><input type="radio" name="bilGw" value="' + ORMS.esc(g.key) + '"' + (ix === 0 ? ' checked' : '') + '>' +
                        '<span class="bil-gw-body"><i class="fas ' + ORMS.esc(g.icon) + '"></i><b>' + ORMS.esc(g.label) + '</b>' +
                        '<small>' + ORMS.esc(g.blurb) + '</small></span></label>';
                  }).join('')
                : '<p class="bil-hint">No payment method is available. Contact the platform operator.</p>');
            $('#btnBilPay').prop('disabled', !gws.length);
            syncManual();
            $('#bilPayModal').addClass('active');
        });

        function syncManual() {
            var k = $('input[name=bilGw]:checked').val();
            var show = k === 'manual' && DATA.manual_help;
            $('#bilManualHelp').prop('hidden', !show).text(show ? DATA.manual_help : '');
        }
        $('#bilGwList').on('change', 'input[name=bilGw]', syncManual);

        $('#btnBilPay').on('click', function () {
            var gw = $('input[name=bilGw]:checked').val();
            if (!PICK || !gw) { ORMS.err('Pick a payment method'); return; }
            ORMS.post('startCheckout', { plan_id: PICK.id, cycle: CYCLE, gateway: gw },
                      { btn: '#btnBilPay', busyLabel: 'Starting…' }).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                if (res.redirect) { window.location.href = res.redirect; return; }
                $('#bilPayModal').removeClass('active');
                ORMS.ok(res.message);
                load();
            }).fail(function (m) { ORMS.err(m); });
        });

        $('#bilTable').on('click', '.bil-proof', function () {
            $('#bilProofId').val($(this).data('id'));
            $('#bilProofRef').val('');
            $('#bilProofModal').addClass('active');
        });

        $('#btnBilProof').on('click', function () {
            ORMS.post('submitProof', { invoice_id: $('#bilProofId').val(), reference: $('#bilProofRef').val() },
                      { btn: '#btnBilProof', busyLabel: 'Sending…' }).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                $('#bilProofModal').removeClass('active');
                ORMS.ok(res.message);
                load();
            }).fail(function (m) { ORMS.err(m); });
        });

        $('#bilTable').on('click', '.bil-cancel', function () {
            var id = $(this).data('id');
            ORMS.confirmDelete('Cancel this pending invoice?', 'Cancel invoice').then(function (r) {
                if (!r.isConfirmed) return;
                ORMS.post('cancelInvoice', { invoice_id: id }).done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    load();
                }).fail(function (m) { ORMS.err(m); });
            });
        });

        $('#btnBilRefresh').on('click', load);
        $('[data-close]').on('click', function () { $('#' + $(this).data('close')).removeClass('active'); });
        load();
    });
    </script>
</body>
</html>
