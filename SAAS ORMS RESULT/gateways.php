<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * App Owner's money console — gateway credentials, every school's invoices, and the raw callback
 * log. Credentials are written to the PLATFORM settings row (school 0) through ormsPlatformSet(),
 * never through setSetting(), so a tenant can never end up owning a key.
 */
require_once 'billing_engine.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

requirePerm('gateways', 'v');

/**
 * PLATFORM ONLY, re-checked on every branch. These keys decide where every school's money lands,
 * so the page gate alone is not enough — anything that posts straight at gateways.php meets this.
 */
function gwDeny(bool $json): void {
    if (ormsIsPlatform()) return;
    if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Payment gateways belong to the platform operator.']); exit(); }
    header('Location: dashboard.php');
    exit();
}
gwDeny(isset($_GET['action']) || isset($_POST['action']));

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = (int) $_SESSION['user_id'];
$current_page = 'gateways';

const GW_MASK = '••••••••';

function gwReady(): bool {
    static $ok = null;
    if ($ok === null) { try { qVal("SELECT id FROM billing_invoices LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

// never ship a live secret to a browser. show only enough to recognise which key is loaded.
function gwMask(string $v): string {
    return $v === '' ? '' : (GW_MASK . substr($v, -4));
}

// a posted value that still looks like the mask means "unchanged", so an operator can save the form
// without retyping every secret — and a blank field genuinely clears the key.
function gwSecret(string $posted, string $current): string {
    return strpos($posted, GW_MASK) === 0 ? $current : trim($posted);
}

$ready = gwReady();

if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'];

    try {
        switch ($action) {

            case 'getGateways': {
                requirePermJson('gateways', 'v');
                $sk = (string) ormsPlatformSetting('gw_stripe_sk', '');
                $wh = (string) ormsPlatformSetting('gw_stripe_webhook_secret', '');
                jsonOk(['cfg' => [
                    'billing_enabled'        => (string) ormsPlatformSetting('billing_enabled', '0'),
                    'billing_currency'       => bilCurrency(),
                    'billing_test_mode'      => (string) ormsPlatformSetting('billing_test_mode', '1'),
                    'billing_invoice_prefix' => (string) ormsPlatformSetting('billing_invoice_prefix', 'INV'),
                    'gw_manual_enabled'      => (string) ormsPlatformSetting('gw_manual_enabled', '1'),
                    'gw_manual_label'        => (string) ormsPlatformSetting('gw_manual_label', 'Bank Transfer'),
                    'gw_manual_instructions' => (string) ormsPlatformSetting('gw_manual_instructions', ''),
                    'gw_stripe_enabled'      => (string) ormsPlatformSetting('gw_stripe_enabled', '0'),
                    'gw_stripe_pk'           => (string) ormsPlatformSetting('gw_stripe_pk', ''),
                    'gw_stripe_sk'           => gwMask($sk),
                    'gw_stripe_webhook_secret' => gwMask($wh),
                    'billing_autoexpire'       => (string) ormsPlatformSetting('billing_autoexpire', '1'),
                    'billing_expire_subs'      => (string) ormsPlatformSetting('billing_expire_subs', '1'),
                    'billing_invoice_ttl_days' => (string) ormsPlatformSetting('billing_invoice_ttl_days', '30'),
                    'billing_suspend_days'     => (string) ormsPlatformSetting('billing_suspend_days', '0'),
                ], 'webhook_url' => bilBaseUrl() . '/payment_webhook.php?gw=stripe',
                   'expiry' => ormsAutoExpireCfg(),
                   'status' => array_values(bilGateways())]);
            }

            case 'saveGateways': {
                requireCsrfJson();
                requirePermJson('gateways', 'e');

                $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['billing_invoice_prefix'] ?? 'INV'));
                if ($prefix === '' || strlen($prefix) > 8) jsonErr('Invoice prefix must be 1-8 letters or digits');

                $sk = gwSecret((string) ($_POST['gw_stripe_sk'] ?? ''), (string) ormsPlatformSetting('gw_stripe_sk', ''));
                $wh = gwSecret((string) ($_POST['gw_stripe_webhook_secret'] ?? ''), (string) ormsPlatformSetting('gw_stripe_webhook_secret', ''));

                // enabling a redirect gateway with no keys would hand schools a Pay button that dies
                // on click — refuse it here instead of at the customer's checkout
                $stripeOn = ((string) ($_POST['gw_stripe_enabled'] ?? '0')) === '1';
                if ($stripeOn && ($sk === '' || $wh === '')) jsonErr('Stripe needs both a secret key and a webhook signing secret before it can be enabled');

                $set = [
                    'billing_enabled'          => ((string) ($_POST['billing_enabled'] ?? '0')) === '1' ? '1' : '0',
                    'billing_test_mode'        => ((string) ($_POST['billing_test_mode'] ?? '0')) === '1' ? '1' : '0',
                    'billing_invoice_prefix'   => strtoupper($prefix),
                    'gw_manual_enabled'        => ((string) ($_POST['gw_manual_enabled'] ?? '0')) === '1' ? '1' : '0',
                    'gw_manual_label'          => mb_substr(trim((string) ($_POST['gw_manual_label'] ?? 'Bank Transfer')), 0, 40),
                    'gw_manual_instructions'   => mb_substr((string) ($_POST['gw_manual_instructions'] ?? ''), 0, 2000),
                    'gw_stripe_enabled'        => $stripeOn ? '1' : '0',
                    'gw_stripe_pk'             => mb_substr(trim((string) ($_POST['gw_stripe_pk'] ?? '')), 0, 191),
                    'gw_stripe_sk'             => $sk,
                    'gw_stripe_webhook_secret' => $wh,
                    // automatic expiry. suspend_days 0 = never, and that stays the default: no live
                    // install should start switching schools off because of a deploy.
                    'billing_autoexpire'       => ((string) ($_POST['billing_autoexpire'] ?? '0')) === '1' ? '1' : '0',
                    'billing_expire_subs'      => ((string) ($_POST['billing_expire_subs'] ?? '0')) === '1' ? '1' : '0',
                    'billing_invoice_ttl_days' => (string) max(1, min(365, (int) ($_POST['billing_invoice_ttl_days'] ?? 30))),
                    'billing_suspend_days'     => (string) max(0, min(365, (int) ($_POST['billing_suspend_days'] ?? 0))),
                ];
                foreach ($set as $k => $v) ormsPlatformSet($k, (string) $v);

                // the values themselves are secrets — the log records THAT they changed, never what to
                logActivity($user_id, $username, 'Gateways Updated',
                    'Billing ' . ($set['billing_enabled'] === '1' ? 'on' : 'off') . ', currency ' . bilCurrency() .
                    ', manual ' . ($set['gw_manual_enabled'] === '1' ? 'on' : 'off') .
                    ', stripe ' . ($set['gw_stripe_enabled'] === '1' ? 'on' : 'off'), 'system_settings', null, 0);

                jsonOk(['message' => 'Payment settings saved']);
            }

            // ------------------------------------------------------------ expiry sweep, on demand
            // the same function the hourly tick calls, so "Run now" can never disagree with what the
            // schedule does. Manual runs ignore the master switch on purpose: the operator asked.
            case 'runAutoExpire': {
                requireCsrfJson();
                requirePermJson('gateways', 'e');
                $r = ormsAutoExpire(true);
                jsonOk(['result' => $r, 'message' => 'Expiry sweep finished — ' . $r['invoices'] . ' invoice(s), ' .
                        $r['subscriptions'] . ' subscription(s), ' . $r['schools'] . ' school(s) suspended']);
            }

            case 'getInvoices': {
                requirePermJson('gateways', 'v');
                bilExpireStale();
                $st = (string) ($_GET['status'] ?? '');
                $ok = ['Pending', 'Paid', 'Failed', 'Cancelled', 'Expired'];
                $where = in_array($st, $ok, true) ? " WHERE i.status = ?" : "";
                $rows = $where
                    ? qAll("SELECT i.*, s.name AS school_name, s.code AS school_code, p.name AS plan_name
                            FROM billing_invoices i JOIN schools s ON s.id = i.school_id
                            LEFT JOIN plans p ON p.id = i.plan_id $where ORDER BY i.id DESC LIMIT 300", 's', $st)
                    : qAll("SELECT i.*, s.name AS school_name, s.code AS school_code, p.name AS plan_name
                            FROM billing_invoices i JOIN schools s ON s.id = i.school_id
                            LEFT JOIN plans p ON p.id = i.plan_id ORDER BY i.id DESC LIMIT 300");

                $k = qOne("SELECT
                             SUM(status = 'Pending') AS pending,
                             SUM(status = 'Paid')    AS paid,
                             COALESCE(SUM(CASE WHEN status = 'Paid' THEN amount ELSE 0 END), 0) AS collected
                           FROM billing_invoices");
                jsonOk(['invoices' => $rows, 'kpi' => $k, 'currency' => bilCurrency()]);
            }

            // the manual gateway's activation. Runs the SAME bilApplyPayment() a webhook does, so a
            // bank transfer and a card buy exactly the same period by exactly the same rules.
            case 'approveInvoice': {
                requireCsrfJson();
                requirePermJson('gateways', 'e');

                $id  = (int) ($_POST['invoice_id'] ?? 0);
                $ref = trim((string) ($_POST['reference'] ?? ''));
                $inv = $id ? qOne("SELECT * FROM billing_invoices WHERE id = ?", 'i', $id) : null;
                if (!$inv) jsonErr('Invoice not found');
                if ($inv['gateway'] !== 'manual') jsonErr('Only a manual invoice is approved by hand — ' . $inv['gateway'] . ' confirms itself.');
                if ($ref === '') $ref = 'MANUAL-' . $inv['invoice_no'];

                $res = bilApplyPayment($id, 'manual', $ref, (float) $inv['amount'], (string) $inv['currency'], 'manual', $user_id);
                bilLogEvent('manual', 'operator.approve', $ref, true, !empty($res['ok']), $res['message'] ?? '', null, $id, (int) $inv['school_id']);
                if (empty($res['ok'])) jsonErr($res['message'] ?? 'Could not apply');

                logActivity($user_id, $username, 'Invoice Approved', $inv['invoice_no'] . ' ref ' . $ref, 'billing_invoices', $id, (int) $inv['school_id']);
                jsonOk(['message' => $res['message']]);
            }

            case 'failInvoice': {
                requireCsrfJson();
                requirePermJson('gateways', 'e');
                $id  = (int) ($_POST['invoice_id'] ?? 0);
                $inv = $id ? qOne("SELECT * FROM billing_invoices WHERE id = ?", 'i', $id) : null;
                if (!$inv) jsonErr('Invoice not found');
                if ($inv['status'] === 'Paid') jsonErr('A paid invoice cannot be marked failed — record a refund instead');

                qExec("UPDATE billing_invoices SET status = 'Failed' WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Invoice Rejected', $inv['invoice_no'], 'billing_invoices', $id, (int) $inv['school_id']);
                jsonOk(['message' => 'Invoice marked failed']);
            }

            case 'getEvents': {
                requirePermJson('gateways', 'v');
                jsonOk(['events' => qAll("SELECT id, invoice_id, school_id, gateway, event, reference, signature_ok, applied, message, ip, created_at
                                          FROM billing_events ORDER BY id DESC LIMIT 200")]);
            }

            default: jsonErr('Unknown action');
        }
    } catch (Throwable $e) {
        error_log('gateways.php: ' . $e->getMessage());
        jsonErr('Something went wrong: ' . $e->getMessage());
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Payment Gateways - Result Management</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body class="page-gateways">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-credit-card"></i> Payment Gateways</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Payment Gateways</span>
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

            <div class="tab-nav" id="gwTabs">
                <button class="tab-btn active" data-pane="gwPaneCfg"><i class="fas fa-sliders"></i> Gateways</button>
                <button class="tab-btn" data-pane="gwPaneInv"><i class="fas fa-file-invoice"></i> Invoices</button>
                <button class="tab-btn" data-pane="gwPaneLog"><i class="fas fa-list-check"></i> Callback Log</button>
            </div>

            <!-- config -->
            <div class="tab-pane active" id="gwPaneCfg">

                <!-- what is actually live right now, read off the saved settings -->
                <div class="gw-live" id="gwLive"></div>

                <div class="settings-mega-card mb-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy"><i class="fas fa-cart-shopping"></i></div>
                        <div class="settings-card-head-text">
                            <h3 class="settings-card-title">Checkout</h3>
                            <p class="settings-card-subtitle">What a school can do when its subscription comes up for renewal</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="gw-switch toggle-row">
                            <div class="gw-switch-text">
                                <label for="gwBillingOn"><i class="fas fa-store"></i> Self-serve checkout</label>
                                <p>Off means schools cannot pay themselves &mdash; every renewal is recorded by hand from the Subscriptions page.</p>
                            </div>
                            <input type="checkbox" class="toggle" id="gwBillingOn">
                        </div>
                        <div class="gw-switch toggle-row">
                            <div class="gw-switch-text">
                                <label for="gwTest"><i class="fas fa-flask"></i> Test mode</label>
                                <p>Keeps the wording and the invoice trail identical while you pay with a gateway&rsquo;s test keys. Turn it off the day you go live.</p>
                            </div>
                            <input type="checkbox" class="toggle" id="gwTest">
                        </div>
                        <div class="form-grid form-grid-2col">
                            <div class="form-group">
                                <label for="gwCcy"><i class="fas fa-coins"></i> Billing currency</label>
                                <input type="text" id="gwCcy" readonly>
                                <div class="help-text"><i class="fas fa-lock"></i> One currency for the whole platform. Change it in <a href="settings.php">Site Settings</a> &mdash; every invoice and every gateway charge is raised in it.</div>
                            </div>
                            <div class="form-group">
                                <label for="gwPrefix"><i class="fas fa-hashtag"></i> Invoice prefix</label>
                                <input type="text" id="gwPrefix" maxlength="8" placeholder="INV">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Leads every invoice number, e.g. <code>INV-000124</code>.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-mega-card mb-24" id="gwCardManual">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-success"><i class="fas fa-building-columns"></i></div>
                        <div class="settings-card-head-text">
                            <h3 class="settings-card-title">Bank Transfer <span class="gw-pill" id="gwPillManual"></span></h3>
                            <p class="settings-card-subtitle">The school transfers and sends a reference; you approve it from the Invoices tab</p>
                        </div>
                        <div class="gw-card-switch toggle-row">
                            <label for="gwManualOn">Enabled</label>
                            <input type="checkbox" class="toggle" id="gwManualOn">
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="form-group">
                            <label for="gwManualLabel"><i class="fas fa-tag"></i> Label shown to schools</label>
                            <input type="text" id="gwManualLabel" maxlength="40" placeholder="Bank Transfer">
                        </div>
                        <div class="form-group">
                            <label for="gwManualHelp"><i class="fas fa-align-left"></i> Payment instructions</label>
                            <textarea id="gwManualHelp" rows="6" maxlength="2000" placeholder="Account title, IBAN, branch — anything the school needs in order to pay you."></textarea>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Shown verbatim on the school&rsquo;s renewal page, and repeated on the invoice.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-mega-card mb-24" id="gwCardStripe">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-blue"><i class="fas fa-credit-card"></i></div>
                        <div class="settings-card-head-text">
                            <h3 class="settings-card-title">Card &mdash; Stripe <span class="gw-pill" id="gwPillStripe"></span></h3>
                            <p class="settings-card-subtitle">Stripe Checkout. Access opens the moment Stripe&rsquo;s webhook confirms the payment</p>
                        </div>
                        <div class="gw-card-switch toggle-row">
                            <label for="gwStripeOn">Enabled</label>
                            <input type="checkbox" class="toggle" id="gwStripeOn">
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="form-grid form-grid-2col">
                            <div class="form-group">
                                <label for="gwStripePk"><i class="fas fa-key"></i> Publishable key</label>
                                <input type="text" id="gwStripePk" maxlength="191" placeholder="pk_live_…">
                            </div>
                            <div class="form-group">
                                <label for="gwStripeSk"><i class="fas fa-lock"></i> Secret key <span class="gw-keychip" id="gwChipSk"></span></label>
                                <div class="gw-secret">
                                    <input type="text" id="gwStripeSk" maxlength="191" placeholder="sk_live_…" autocomplete="off" spellcheck="false">
                                    <button type="button" class="btn btn-light btn-sm gw-replace" data-target="gwStripeSk"><i class="fas fa-pen"></i> Replace</button>
                                </div>
                                <div class="help-text"><i class="fas fa-shield-halved"></i> Only the last 4 characters ever leave the server. Leave it as it is to keep the key you already saved.</div>
                            </div>
                            <div class="form-group form-group-full">
                                <label for="gwStripeWh"><i class="fas fa-signature"></i> Webhook signing secret <span class="gw-keychip" id="gwChipWh"></span></label>
                                <div class="gw-secret">
                                    <input type="text" id="gwStripeWh" maxlength="191" placeholder="whsec_…" autocomplete="off" spellcheck="false">
                                    <button type="button" class="btn btn-light btn-sm gw-replace" data-target="gwStripeWh"><i class="fas fa-pen"></i> Replace</button>
                                </div>
                            </div>
                        </div>

                        <div class="gw-hook">
                            <div class="gw-hook-text">
                                <span class="gw-hook-label"><i class="fas fa-link"></i> Send Stripe&rsquo;s webhook here</span>
                                <code id="gwHook"></code>
                            </div>
                            <button type="button" class="btn btn-light btn-sm" id="btnGwCopyHook"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                        <p class="bil-hint"><i class="fas fa-circle-info"></i> Subscribe that endpoint to <b>checkout.session.completed</b>.
                            Access is granted by that call alone &mdash; never by the browser coming back from Stripe.</p>
                    </div>
                </div>

                <div class="settings-mega-card mb-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-warning"><i class="fas fa-hourglass-half"></i></div>
                        <div class="settings-card-head-text">
                            <h3 class="settings-card-title">Automatic expiry <span class="gw-pill" id="gwPillExpiry"></span></h3>
                            <p class="settings-card-subtitle">Keeps what is stored in step with the calendar &mdash; without it a lapsed period still reads &ldquo;Active&rdquo; in every list</p>
                        </div>
                        <div class="gw-card-switch toggle-row">
                            <label for="gwExpireOn">Enabled</label>
                            <input type="checkbox" class="toggle" id="gwExpireOn">
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="gw-switch toggle-row">
                            <div class="gw-switch-text">
                                <label for="gwExpireSubs"><i class="fas fa-file-contract"></i> Close out lapsed subscription periods</label>
                                <p>Marks a period <b>Expired</b> once its end date has passed. The school is already blocked either way &mdash; this is what makes the Subscriptions list tell the truth.</p>
                            </div>
                            <input type="checkbox" class="toggle" id="gwExpireSubs">
                        </div>
                        <div class="form-grid form-grid-2col">
                            <div class="form-group">
                                <label for="gwInvTtl"><i class="fas fa-file-invoice"></i> Expire unpaid invoices after</label>
                                <input type="number" id="gwInvTtl" min="1" max="365" step="1" placeholder="30">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Days. A pending invoice nobody paid stops cluttering the list &mdash; the school can always raise a new one.</div>
                            </div>
                            <div class="form-group">
                                <label for="gwSuspendDays"><i class="fas fa-ban"></i> Suspend a school after</label>
                                <input type="number" id="gwSuspendDays" min="0" max="365" step="1" placeholder="0">
                                <div class="help-text"><i class="fas fa-triangle-exclamation"></i> Days past the end of its last paid period. <b>0 means never</b> &mdash; the school stays blocked at the gate but its status is left for you to change by hand.</div>
                            </div>
                        </div>
                        <div class="gw-hook">
                            <div class="gw-hook-text">
                                <span class="gw-hook-label"><i class="fas fa-clock-rotate-left"></i> Last sweep</span>
                                <code id="gwExpireLast">never</code>
                            </div>
                            <button type="button" class="btn btn-light btn-sm" id="btnGwRunExpiry"><i class="fas fa-play"></i> Run now</button>
                        </div>
                        <p class="bil-hint"><i class="fas fa-circle-info"></i> There is no cron here: the sweep rides the first signed-in request of each hour,
                            so it costs nothing when nobody is using the system. <b>Run now</b> does exactly the same work, immediately.</p>
                    </div>
                </div>

                <!-- sticky save bar: appears the moment anything on this page differs from what is saved -->
                <div class="gw-savebar" id="gwSaveBar" hidden>
                    <span class="gw-savebar-note"><i class="fas fa-circle-exclamation"></i> You have unsaved payment settings</span>
                    <div class="btn-group-inline">
                        <button class="btn btn-light" id="btnGwDiscard"><i class="fas fa-rotate-left"></i> Discard</button>
                        <button class="btn btn-primary" id="btnGwSave"><i class="fas fa-save"></i> Save payment settings</button>
                    </div>
                </div>
            </div>

            <!-- invoices -->
            <div class="tab-pane" id="gwPaneInv">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-file-invoice"></i> Invoices</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-light" id="btnGwInvRefresh"><i class="fas fa-rotate"></i> Refresh</button>
                        </div>
                    </div>
                    <div id="gwKpi" class="bil-quotas"></div>
                    <div class="table-responsive">
                        <table id="gwInvTable" class="display responsive nowrap" width="100%">
                            <thead><tr>
                                <th>Invoice</th><th>School</th><th>Plan</th><th>Amount</th>
                                <th>Method</th><th>Status</th><th>Reference</th><th>Raised</th><th>Action</th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- callback log -->
            <div class="tab-pane" id="gwPaneLog">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-list-check"></i> Callback Log</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-light" id="btnGwLogRefresh"><i class="fas fa-rotate"></i> Refresh</button>
                        </div>
                    </div>
                    <p class="bil-hint">Every gateway callback, signed or not. A run of <b>signature failed</b> rows means the webhook secret does not match the one in Stripe.</p>
                    <div class="table-responsive">
                        <table id="gwLogTable" class="display responsive nowrap" width="100%">
                            <thead><tr><th>When</th><th>Gateway</th><th>Event</th><th>Reference</th><th>Signature</th><th>Applied</th><th>Message</th><th>IP</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
        var invDt = null, logDt = null, CCY = <?= json_encode(bilCurrency()) ?>;
        var canEdit = <?= can('gateways', 'e') ? 'true' : 'false' ?>;

        function dtRow(t, el) { var $tr = $(el).closest('tr'); return t.row($tr.hasClass('child') ? $tr.prev('tr') : $tr); }
        function chip(st) {
            var m = { Paid: 'ok', Pending: 'warn', Failed: 'bad', Cancelled: 'mut', Expired: 'mut' };
            return '<span class="bil-chip bil-' + (m[st] || 'mut') + '">' + ORMS.esc(st) + '</span>';
        }

        $('#gwTabs').on('click', '.tab-btn', function () {
            var pane = $(this).data('pane');
            $('#gwTabs .tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.tab-pane').removeClass('active');
            $('#' + pane).addClass('active');
            if (pane === 'gwPaneInv') loadInvoices();
            if (pane === 'gwPaneLog') loadEvents();
        });

        // ---------- gateway settings: live status, dirty bar, key handling ----------
        var GW_MASK  = '••••••••';   // must match GW_MASK in the php
        var gwClean  = '';        // snapshot of what is SAVED, so the save bar only shows a real change
        var gwLoaded = null;      // the cfg the server last sent, for the "you are clearing a key" guard

        // one place reads the whole form — the save call, the dirty check and the pills all use it
        function gwForm() {
            return {
                billing_enabled: $('#gwBillingOn').is(':checked') ? 1 : 0,
                billing_test_mode: $('#gwTest').is(':checked') ? 1 : 0,
                billing_invoice_prefix: String($('#gwPrefix').val() || '').toUpperCase(),
                gw_manual_enabled: $('#gwManualOn').is(':checked') ? 1 : 0,
                gw_manual_label: $('#gwManualLabel').val(),
                gw_manual_instructions: $('#gwManualHelp').val(),
                gw_stripe_enabled: $('#gwStripeOn').is(':checked') ? 1 : 0,
                gw_stripe_pk: $('#gwStripePk').val(),
                gw_stripe_sk: $('#gwStripeSk').val(),
                gw_stripe_webhook_secret: $('#gwStripeWh').val(),
                billing_autoexpire: $('#gwExpireOn').is(':checked') ? 1 : 0,
                billing_expire_subs: $('#gwExpireSubs').is(':checked') ? 1 : 0,
                billing_invoice_ttl_days: $('#gwInvTtl').val(),
                billing_suspend_days: $('#gwSuspendDays').val()
            };
        }
        function gwSnap()  { return JSON.stringify(gwForm()); }
        function gwDirty() { return gwSnap() !== gwClean; }
        function gwHas(sel) { return String($(sel).val() || '').trim() !== ''; }

        // a value that still starts with the mask is the key already on the server, untouched
        function gwKeyChip(sel, chipSel) {
            var v = String($(sel).val() || '').trim(), saved = v.indexOf(GW_MASK) === 0;
            $(chipSel).attr('class', 'gw-keychip ' + (v === '' ? 'gw-key-none' : (saved ? 'gw-key-set' : 'gw-key-new')))
                      .html(v === '' ? '<i class="fas fa-circle-xmark"></i> not set'
                                     : (saved ? '<i class="fas fa-circle-check"></i> saved' : '<i class="fas fa-pen"></i> new'));
        }

        // the pill answers "would a school see this method right now" BEFORE anything is saved
        function gwPill(on, ready, sel) {
            var billing = $('#gwBillingOn').is(':checked'), test = $('#gwTest').is(':checked'), c, i, t;
            if (!on)           { c = 'gw-off';  i = 'fa-circle-minus';         t = 'Disabled'; }
            else if (!ready)   { c = 'gw-warn'; i = 'fa-triangle-exclamation'; t = 'Needs keys'; }
            else if (!billing) { c = 'gw-warn'; i = 'fa-pause';                t = 'Checkout is off'; }
            else if (test)     { c = 'gw-test'; i = 'fa-flask';                t = 'Test mode'; }
            else               { c = 'gw-live'; i = 'fa-circle-check';         t = 'Live'; }
            $(sel).attr('class', 'gw-pill ' + c).html('<i class="fas ' + i + '"></i> ' + t);
        }

        function gwStat(kind, icon, label, val) {
            return '<div class="gw-stat gw-' + kind + '"><i class="fas ' + icon + '"></i>' +
                   '<div><span>' + label + '</span><b>' + ORMS.esc(val) + '</b></div></div>';
        }

        function gwPaint() {
            var mOn = $('#gwManualOn').is(':checked'), sOn = $('#gwStripeOn').is(':checked');
            var sReady = gwHas('#gwStripeSk') && gwHas('#gwStripeWh');
            gwPill(mOn, true, '#gwPillManual');
            gwPill(sOn, sReady, '#gwPillStripe');
            $('#gwCardManual').toggleClass('gw-card-off', !mOn);
            $('#gwCardStripe').toggleClass('gw-card-off', !sOn);
            gwKeyChip('#gwStripeSk', '#gwChipSk');
            gwKeyChip('#gwStripeWh', '#gwChipWh');

            var billing = $('#gwBillingOn').is(':checked'), test = $('#gwTest').is(':checked');
            var live = (mOn ? 1 : 0) + (sOn && sReady ? 1 : 0);
            $('#gwLive').html(
                gwStat(billing ? 'ok' : 'off', 'fa-store', 'Self-serve checkout', billing ? 'On' : 'Off') +
                gwStat(test ? 'test' : (billing ? 'ok' : 'mut'), test ? 'fa-flask' : 'fa-tower-broadcast', 'Mode', test ? 'Test' : 'Live') +
                gwStat('mut', 'fa-coins', 'Currency', String($('#gwCcy').val() || '—').toUpperCase()) +
                gwStat(billing && live ? 'ok' : 'warn', 'fa-credit-card', 'Methods a school can pick',
                       billing ? (live + ' of 2') : 'none — checkout is off'));

            var xOn = $('#gwExpireOn').is(':checked');
            var susp = parseInt($('#gwSuspendDays').val() || 0, 10) || 0;
            $('#gwPillExpiry')
                .attr('class', 'gw-pill ' + (!xOn ? 'gw-off' : (susp > 0 ? 'gw-warn' : 'gw-live')))
                .html(!xOn ? '<i class="fas fa-circle-minus"></i> Off'
                           : (susp > 0 ? '<i class="fas fa-ban"></i> Suspends after ' + susp + 'd'
                                       : '<i class="fas fa-circle-check"></i> On'));

            $('#gwSaveBar').prop('hidden', !gwDirty());
        }

        function loadCfg() {
            ORMS.post('getGateways', {}).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                var c = res.cfg;
                gwLoaded = c;
                $('#gwBillingOn').prop('checked', c.billing_enabled === '1');
                $('#gwTest').prop('checked', c.billing_test_mode === '1');
                $('#gwCcy').val(c.billing_currency);
                $('#gwPrefix').val(c.billing_invoice_prefix);
                $('#gwManualOn').prop('checked', c.gw_manual_enabled === '1');
                $('#gwManualLabel').val(c.gw_manual_label);
                $('#gwManualHelp').val(c.gw_manual_instructions);
                $('#gwStripeOn').prop('checked', c.gw_stripe_enabled === '1');
                $('#gwStripePk').val(c.gw_stripe_pk);
                $('#gwStripeSk').val(c.gw_stripe_sk);
                $('#gwStripeWh').val(c.gw_stripe_webhook_secret);
                $('#gwHook').text(res.webhook_url);
                $('#gwExpireOn').prop('checked', c.billing_autoexpire === '1');
                $('#gwExpireSubs').prop('checked', c.billing_expire_subs === '1');
                $('#gwInvTtl').val(c.billing_invoice_ttl_days);
                $('#gwSuspendDays').val(c.billing_suspend_days);
                $('#gwExpireLast').text((res.expiry && res.expiry.last) ? res.expiry.last : 'never');
                gwClean = gwSnap();          // this IS the saved state
                gwPaint();
            }).fail(function (m) { ORMS.err(m); });
        }

        // any edit anywhere in the pane repaints the pills and the save bar
        $('#gwPaneCfg').on('input change', 'input, textarea', gwPaint);

        // "Replace" empties a saved key so a new one can be typed. Saving it still empty CLEARS the
        // key on the server, which is why the save below asks first.
        $('#gwPaneCfg').on('click', '.gw-replace', function () {
            var t = '#' + this.getAttribute('data-target');
            $(t).val('').trigger('focus');
            gwPaint();
        });

        $('#btnGwCopyHook').on('click', function () {
            var url = $('#gwHook').text();
            var done = function () { ORMS.ok('Webhook URL copied'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, function () { ORMS.err('Could not copy — select the URL and copy it by hand'); });
                return;
            }
            var ta = document.createElement('textarea');          // http origins have no clipboard api
            ta.value = url; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { ORMS.err('Could not copy — select the URL and copy it by hand'); }
            document.body.removeChild(ta);
        });

        $('#btnGwDiscard').on('click', function () { loadCfg(); });

        // the sweep can suspend schools, so it asks before it runs by hand
        $('#btnGwRunExpiry').on('click', function () {
            var susp = parseInt($('#gwSuspendDays').val() || 0, 10) || 0;
            Swal.fire({
                icon: 'question',
                title: 'Run the expiry sweep now?',
                html: 'Unpaid invoices past their window and periods past their end date are closed out.' +
                      (susp > 0 ? '<br><br><b>Schools more than ' + susp + ' day(s) past their last paid period will be suspended.</b>'
                                : '<br><br>No school will be suspended &mdash; that setting is off.') +
                      '<br><br><small>Unsaved changes on this page are not used; the sweep reads what is saved.</small>',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-play"></i> Run now',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                ORMS.post('runAutoExpire', {}, { btn: '#btnGwRunExpiry', busyLabel: 'Running…' }).done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    $('#gwExpireLast').text((res.result && res.result.at) || 'just now');
                }).fail(function (m) { ORMS.err(m); });
            });
        });

        // a key that was saved and is now blank is a DELETE — never let that happen by accident
        function gwCleared() {
            var out = [];
            if (!gwLoaded) return out;
            if (String(gwLoaded.gw_stripe_sk || '') !== '' && !gwHas('#gwStripeSk')) out.push('Stripe secret key');
            if (String(gwLoaded.gw_stripe_webhook_secret || '') !== '' && !gwHas('#gwStripeWh')) out.push('Stripe webhook signing secret');
            return out;
        }

        function gwSave() {
            ORMS.post('saveGateways', gwForm(), { btn: '#btnGwSave', busyLabel: 'Saving…' }).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                ORMS.ok(res.message);
                loadCfg();
            }).fail(function (m) { ORMS.err(m); });
        }

        $('#btnGwSave').on('click', function () {
            var gone = gwCleared();
            if (!gone.length) { gwSave(); return; }
            Swal.fire({
                icon: 'warning',
                title: 'Remove ' + (gone.length === 1 ? 'this key' : 'these keys') + '?',
                html: '<b>' + gone.join('</b><br><b>') + '</b><br><br>Saving now deletes ' +
                      (gone.length === 1 ? 'it' : 'them') + ' from the server. Card checkout stops working until a new key is entered.',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-trash"></i> Remove and save',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel'
            }).then(function (r) { if (r.isConfirmed) gwSave(); });
        });

        function loadInvoices() {
            ORMS.post('getInvoices', {}).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                CCY = res.currency;
                var k = res.kpi || {};
                $('#gwKpi').html(
                    '<div class="bil-meter bil-warn"><div class="bil-meter-top"><span>Pending</span><b>' + (+k.pending || 0) + '</b></div></div>' +
                    '<div class="bil-meter bil-ok"><div class="bil-meter-top"><span>Paid</span><b>' + (+k.paid || 0) + '</b></div></div>' +
                    '<div class="bil-meter bil-ok"><div class="bil-meter-top"><span>Collected</span><b>' + CCY + ' ' + ORMS.money(k.collected || 0, 2) + '</b></div></div>');

                var rows = (res.invoices || []).map(function (i) {
                    var act = '';
                    if (canEdit && i.status === 'Pending' && i.gateway === 'manual') {
                        act += '<button class="btn btn-sm btn-primary gw-ok" data-id="' + i.id + '"><i class="fas fa-check"></i></button> ';
                    }
                    if (canEdit && i.status !== 'Paid') {
                        act += '<button class="btn btn-sm btn-danger gw-no" data-id="' + i.id + '"><i class="fas fa-ban"></i></button>';
                    }
                    return [
                        ORMS.esc(i.invoice_no),
                        ORMS.esc(i.school_name) + ' <small>(' + ORMS.esc(i.school_code) + ')</small>',
                        ORMS.esc(i.plan_name || '—') + ' <small>' + ORMS.esc(i.cycle) + '</small>',
                        ORMS.esc(i.currency) + ' ' + ORMS.money(i.amount, 2),
                        ORMS.esc(i.gateway), chip(i.status),
                        ORMS.esc(i.gateway_ref || i.proof || '—'),
                        new Date(i.created_at).toLocaleDateString(),
                        act || '—'
                    ];
                });
                if (invDt) { invDt.clear().rows.add(rows).draw(); return; }
                invDt = $('#gwInvTable').DataTable({ data: rows, pageLength: 10, responsive: true, order: [], language: { emptyTable: 'No invoices yet' } });
            }).fail(function (m) { ORMS.err(m); });
        }

        $('#gwInvTable').on('click', '.gw-ok', function () {
            var id = $(this).data('id');
            Swal.fire({
                title: 'Approve this transfer?', input: 'text', inputLabel: 'Bank reference (optional)',
                showCancelButton: true, confirmButtonText: 'Approve & activate', confirmButtonColor: '#0074D9'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                ORMS.post('approveInvoice', { invoice_id: id, reference: r.value || '' }).done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    loadInvoices();
                }).fail(function (m) { ORMS.err(m); });
            });
        });

        $('#gwInvTable').on('click', '.gw-no', function () {
            var id = $(this).data('id');
            ORMS.confirmDelete('Mark this invoice failed?', 'Reject invoice').then(function (r) {
                if (!r.isConfirmed) return;
                ORMS.post('failInvoice', { invoice_id: id }).done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    loadInvoices();
                }).fail(function (m) { ORMS.err(m); });
            });
        });

        function loadEvents() {
            ORMS.post('getEvents', {}).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                var rows = (res.events || []).map(function (e) {
                    return [
                        new Date(e.created_at).toLocaleString(), ORMS.esc(e.gateway), ORMS.esc(e.event),
                        ORMS.esc(e.reference || '—'),
                        +e.signature_ok ? '<span class="bil-chip bil-ok">ok</span>' : '<span class="bil-chip bil-bad">failed</span>',
                        +e.applied ? '<span class="bil-chip bil-ok">yes</span>' : '<span class="bil-chip bil-mut">no</span>',
                        ORMS.esc(e.message || ''), ORMS.esc(e.ip || '')
                    ];
                });
                if (logDt) { logDt.clear().rows.add(rows).draw(); return; }
                logDt = $('#gwLogTable').DataTable({ data: rows, pageLength: 10, responsive: true, order: [], language: { emptyTable: 'No callbacks yet' } });
            }).fail(function (m) { ORMS.err(m); });
        }

        $('#btnGwInvRefresh').on('click', loadInvoices);
        $('#btnGwLogRefresh').on('click', loadEvents);
        loadCfg();
    });
    </script>
</body>
</html>
