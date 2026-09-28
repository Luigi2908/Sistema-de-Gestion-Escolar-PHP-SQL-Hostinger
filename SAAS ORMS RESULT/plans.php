<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Platform plan catalogue. Prices are quoted in the OPERATOR's currency (ormsCurrency()),
 * never a tenant's — a plan is what the platform charges, not what a school collects.
 */
require_once 'billing_engine.php';   // brings config.php with it; plans are priced per currency now

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

// rbac first, platform gate second — a school role could otherwise be granted 'plans' by a bad matrix edit
requirePerm('plans', 'v');

/**
 * PLATFORM ONLY. The plan catalogue prices every tenant on the install, so one school's admin
 * editing it would reprice the whole platform. Repeated on every ajax branch: the page gate
 * alone stops nothing that posts straight to plans.php.
 */
function plnDeny(bool $json): void {
    if (ormsIsPlatform()) return;
    if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'The plan catalogue belongs to the platform operator.']); exit(); }
    header('Location: dashboard.php');
    exit();
}
plnDeny(isset($_GET['action']) || isset($_POST['action']));

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'plans';

// billing tables land in one migration step — one probe answers for the lot
function plnReady(): bool {
    static $ok = null;
    if ($ok === null) {
        if (!ormsHasTenancy()) return $ok = false;
        try { qVal("SELECT id FROM plans LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

// DECIMAL(10,2) money off the request. blank -> 0, never a float compare later
/**
 * Rewrite one plan's extra-currency prices: delete what it had, insert what was posted, in ONE
 * transaction. A currency the operator removed from the form must actually stop existing, or the
 * old number keeps pricing checkouts nobody can see any more.
 */
function plnSavePrices(int $planId, array $extra): void {
    if ($planId <= 0) return;
    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        qExec("DELETE FROM plan_prices WHERE plan_id = ?", 'i', $planId);
        foreach ($extra as $ccy => $pair) {
            qExec("INSERT INTO plan_prices (plan_id, currency, price_monthly, price_yearly) VALUES (?, ?, ?, ?)",
                  'isdd', $planId, $ccy, $pair[0], $pair[1]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('plans.php plnSavePrices: ' . $e->getMessage());   // pre-migration db -> base currency only
    }
}

function plnMoney($raw): ?float {
    $s = trim((string)$raw);
    if ($s === '') return 0.0;
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) return null;
    return round((float)$s, 2);
}

// 0 = unlimited, so the floor is 0 and blank means unlimited too
function plnLimit($raw): ?int {
    $s = trim((string)$raw);
    if ($s === '') return 0;
    if (!ctype_digit($s) || (int)$s > 999999) return null;
    return (int)$s;
}

$ready = plnReady();
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    plnDeny(true);
    try {
        if (!$ready) jsonErr('The billing tables are not installed yet — run update_setup.php once, then reload this page.');

        switch ($action) {

            case 'getPlans': {
                // school count per plan comes off the driver, not a query in a loop
                $rows = qAll(
                    "SELECT p.id, p.name, p.price_monthly, p.price_yearly, p.max_students, p.max_teachers,
                            p.max_branches, p.features, p.is_active, p.sort_order,
                            (SELECT COUNT(*) FROM schools s            WHERE s.plan_id  = p.id) AS schools,
                            (SELECT COUNT(*) FROM school_subscriptions b WHERE b.plan_id = p.id) AS periods
                     FROM plans p
                     ORDER BY p.sort_order ASC, p.name ASC");

                // extra-currency prices in ONE read, keyed by plan — a query per plan would be N+1
                $extra = [];
                try {
                    foreach (qAll("SELECT plan_id, currency, price_monthly, price_yearly FROM plan_prices ORDER BY currency ASC") as $r)
                        $extra[(int) $r['plan_id']][] = ['currency' => $r['currency'],
                                                         'price_monthly' => (float) $r['price_monthly'],
                                                         'price_yearly'  => (float) $r['price_yearly']];
                } catch (Throwable $e) { $extra = []; }   // pre-migration db: base currency only
                foreach ($rows as &$r) $r['prices'] = $extra[(int) $r['id']] ?? [];
                unset($r);

                jsonOk(['data' => $rows, 'base' => bilCurrency()]);
            }

            case 'savePlan':
                requireCsrfJson();
                $id = (int)($_POST['id'] ?? 0);
                requirePermJson('plans', $id ? 'e' : 'a');   // perm follows what was ASKED for
                if ($id && !qVal("SELECT id FROM plans WHERE id = ?", 'i', $id)) jsonErr('Plan not found');

                $name  = trim($_POST['name'] ?? '');
                $mon   = plnMoney($_POST['price_monthly'] ?? '');
                $yr    = plnMoney($_POST['price_yearly'] ?? '');
                $stu   = plnLimit($_POST['max_students'] ?? '');
                $tch   = plnLimit($_POST['max_teachers'] ?? '');
                $brn   = plnLimit($_POST['max_branches'] ?? '');
                $feat  = trim($_POST['features'] ?? '');
                $sort  = (int)($_POST['sort_order'] ?? 0);
                $activ = !empty($_POST['is_active']) ? 1 : 0;

                if ($name === '')             jsonErr('Plan name is required');
                if (mb_strlen($name) > 60)    jsonErr('Plan name must be 60 characters or less');
                if ($mon === null || $yr === null) jsonErr('Prices must be a number with up to 2 decimals');
                if ($stu === null || $tch === null || $brn === null) jsonErr('Limits must be whole numbers — use 0 for unlimited');
                if (mb_strlen($feat) > 1000)  jsonErr('Features must be 1000 characters or less');
                if ($sort < 0 || $sort > 9999) jsonErr('Sort order must be between 0 and 9999');
                $feat = $feat === '' ? null : $feat;

                // ---- extra-currency prices, posted as parallel arrays. Validated in FULL before a
                // single row is written: half-saved pricing is how a customer gets charged a number
                // nobody chose. The base currency never appears here — it lives on the plan itself.
                $pcCur = (array) ($_POST['pc_currency'] ?? []);
                $pcMon = (array) ($_POST['pc_monthly'] ?? []);
                $pcYr  = (array) ($_POST['pc_yearly'] ?? []);
                $base  = bilCurrency();
                $known = ormsCurrencies();
                $extra = [];
                foreach ($pcCur as $i => $cc) {
                    $cc = strtoupper(trim((string) $cc));
                    if ($cc === '') continue;
                    if (!preg_match('/^[A-Z]{3}$/', $cc) || !isset($known[$cc])) jsonErr('"' . $cc . '" is not a currency code we know');
                    if ($cc === $base) jsonErr($base . ' is the platform currency — set that price in the fields above, not as an extra row');
                    if (isset($extra[$cc]))  jsonErr($cc . ' is listed twice — one row per currency');
                    $m = plnMoney($pcMon[$i] ?? '');
                    $y = plnMoney($pcYr[$i] ?? '');
                    if ($m === null || $y === null) jsonErr($cc . ' prices must be a number with up to 2 decimals');
                    $extra[$cc] = [$m, $y];
                }

                // uniq_plan_name — answer before the db does, so the operator gets a sentence not a 1062
                if (qVal("SELECT id FROM plans WHERE name = ? AND id <> ?", 'si', $name, $id))
                    jsonErr('A plan named "' . $name . '" already exists');

                // deactivating a plan schools are still on is fine (it keeps serving them, it just
                // stops being offered) — say so instead of letting it look like a mistake
                $onIt = $id ? (int)qVal("SELECT COUNT(*) FROM schools WHERE plan_id = ?", 'i', $id) : 0;
                $lim  = fn(int $n) => $n === 0 ? 'unlimited' : (string)$n;
                $note = "students: {$lim($stu)}, teachers: {$lim($tch)}, branches: {$lim($brn)}";

                if ($id) {
                    // types: s name, d monthly, d yearly, i students, i teachers, i branches, s features, i active, i sort, i id
                    qExec("UPDATE plans SET name = ?, price_monthly = ?, price_yearly = ?, max_students = ?, max_teachers = ?,
                                            max_branches = ?, features = ?, is_active = ?, sort_order = ? WHERE id = ?",
                          'sddiiisiii', $name, $mon, $yr, $stu, $tch, $brn, $feat, $activ, $sort, $id);
                    logActivity($user_id, $username, 'Plan Updated',
                        "Updated plan: $name (#$id) — " . ormsMoney($mon) . "/mo, " . ormsMoney($yr) . "/yr, $note"
                        . ($activ ? '' : " [inactive, $onIt school(s) keep it]"), 'plan', $id);
                    // only rewrite the price book when the form actually sent one — a page that no
                    // longer renders the editor must not delete what it cannot see
                    if (array_key_exists('pc_currency', $_POST)) plnSavePrices($id, $extra);
                    jsonOk(['message' => 'Plan updated successfully' . (count($extra) ? ' — ' . count($extra) . ' extra currency price(s)' : '')]);
                }
                // types: s name, d monthly, d yearly, i students, i teachers, i branches, s features, i active, i sort
                $id = qInsert("INSERT INTO plans (name, price_monthly, price_yearly, max_students, max_teachers, max_branches, features, is_active, sort_order)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                              'sddiiisii', $name, $mon, $yr, $stu, $tch, $brn, $feat, $activ, $sort);
                logActivity($user_id, $username, 'Plan Created',
                    "Created plan: $name (#$id) — " . ormsMoney($mon) . "/mo, " . ormsMoney($yr) . "/yr, $note", 'plan', $id);
                if (array_key_exists('pc_currency', $_POST)) plnSavePrices($id, $extra);
                jsonOk(['message' => 'Plan added successfully' . (count($extra) ? ' — ' . count($extra) . ' extra currency price(s)' : '')]);

            case 'togglePlan':
                requireCsrfJson();
                requirePermJson('plans', 'e');
                $id  = (int)($_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name, is_active FROM plans WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Plan not found');

                $new  = (int)$row['is_active'] === 1 ? 0 : 1;
                $onIt = (int)qVal("SELECT COUNT(*) FROM schools WHERE plan_id = ?", 'i', $id);
                qExec("UPDATE plans SET is_active = ? WHERE id = ?", 'ii', $new, $id);
                logActivity($user_id, $username, 'Plan Updated',
                    "Set plan {$row['name']} " . ($new ? 'active' : "inactive — $onIt school(s) stay on it"), 'plan', $id);
                jsonOk(['message' => $new ? 'Plan is offered again' : 'Plan deactivated — schools already on it keep it']);

            case 'deletePlan':
                requireCsrfJson();
                requirePermJson('plans', 'd');
                $id  = (int)($_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name FROM plans WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Plan not found');

                // count first — the fks are ON DELETE SET NULL, so a delete would silently orphan
                // every billing period that was sold under this plan instead of erroring
                $sch = (int)qVal("SELECT COUNT(*) FROM schools WHERE plan_id = ?", 'i', $id);
                $per = (int)qVal("SELECT COUNT(*) FROM school_subscriptions WHERE plan_id = ?", 'i', $id);
                if ($sch || $per) {
                    $bits = [];
                    if ($sch) $bits[] = "$sch school(s)";
                    if ($per) $bits[] = "$per billing period(s)";
                    jsonErr('"' . $row['name'] . '" is in use by ' . implode(' and ', $bits) .
                            '. Deactivate it instead — an inactive plan keeps serving the schools already on it and is simply not offered to new ones.');
                }

                qExec("DELETE FROM plans WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Plan Deleted', "Deleted plan: {$row['name']} (#$id)", 'plan', $id);
                jsonOk(['message' => 'Plan deleted successfully']);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('plans.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

$cur = ormsCurrency();
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
    <title>Plans - Result Management</title>

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
                    <h1><i class="fas fa-layer-group"></i> Plans</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Plans</span>
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
                    <h2><i class="fas fa-table"></i> Plan Catalogue</h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-primary" id="btnRefresh"><i class="fas fa-sync"></i> Refresh</button>
                        <?php if (can('plans', 'a')): ?>
                        <button type="button" class="btn btn-success" id="btnAdd"><i class="fas fa-plus"></i> Add Plan</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>
                        A limit of <b>0 means unlimited</b>. Prices are quoted in the platform currency
                        (<b><?php echo htmlspecialchars($cur['code'] . ' ' . $cur['symbol']); ?></b>).
                        A plan that any school is on <b>cannot be deleted</b> &mdash; deactivate it instead: an inactive plan keeps
                        serving the schools already on it and is simply hidden from new signups.
                    </span>
                </div>

                <div id="planSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="planWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="planTable" class="display chip-table"></table>
                    </div>
                </div>

                <div id="planEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-layer-group"></i>
                    <h4>No plans yet</h4>
                    <p><?php echo can('plans', 'a') ? 'Use <strong>Add Plan</strong> above to create the first tier — schools are billed against it.' : 'Ask the platform operator to build the plan catalogue.'; ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Plan Modal -->
    <div class="modal-overlay" id="planModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="planModalTitle"><i class="fas fa-layer-group"></i> Add Plan</h3>
                <button type="button" class="close-btn" id="btnCloseModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="planForm">
                    <input type="hidden" id="planId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Plan Name *</label>
                            <input type="text" id="planName" name="name" maxlength="60" required placeholder="e.g. Standard">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-sort-numeric-down"></i> Sort Order</label>
                            <input type="number" id="planSort" name="sort_order" min="0" max="9999" value="0">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Lower numbers appear first, cheapest tier at the top.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Monthly Price (<?php echo htmlspecialchars($cur['code']); ?>)</label>
                            <input type="text" id="planMonthly" name="price_monthly" inputmode="decimal" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-days"></i> Yearly Price (<?php echo htmlspecialchars($cur['code']); ?>)</label>
                            <input type="text" id="planYearly" name="price_yearly" inputmode="decimal" placeholder="0.00">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Leave a price at 0 to stop offering that cycle.</div>
                        </div>

                        <div class="form-group form-group-full">
                            <label><i class="fas fa-coins"></i> Prices in other currencies</label>
                            <div class="pc-rows" id="pcRows"></div>
                            <button type="button" class="btn btn-light btn-sm" id="btnPcAdd"><i class="fas fa-plus"></i> Add a currency</button>
                            <div class="help-text"><i class="fas fa-info-circle"></i>
                                A school billed in one of these is charged the price you type here &mdash; nothing is ever converted,
                                because an exchange rate that moves overnight would change what a customer owes. A school whose currency
                                has no row cannot check out until one exists. The
                                <strong><?php echo htmlspecialchars($cur['code']); ?></strong> price lives in the two fields above.
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-graduate"></i> Max Students</label>
                            <input type="number" id="planStudents" name="max_students" min="0" max="999999" value="0">
                            <div class="help-text"><i class="fas fa-infinity"></i> 0 = unlimited.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-chalkboard-teacher"></i> Max Teachers</label>
                            <input type="number" id="planTeachers" name="max_teachers" min="0" max="999999" value="0">
                            <div class="help-text"><i class="fas fa-infinity"></i> 0 = unlimited.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Max Branches</label>
                            <input type="number" id="planBranches" name="max_branches" min="0" max="999999" value="1">
                            <div class="help-text"><i class="fas fa-infinity"></i> 0 = unlimited.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Offered to New Schools</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="planActive" name="is_active" value="1" class="toggle-input" checked>
                                <label for="planActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Off = hidden from new signups. Schools already on it keep every feature.</div>
                        </div>
                        <div class="form-group bill-span-2">
                            <label><i class="fas fa-list-check"></i> Features</label>
                            <textarea id="planFeatures" name="features" maxlength="1000" rows="3" placeholder="One per line, or comma separated — e.g. Fees ledger, Attendance, 3 branches"></textarea>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Sales copy only &mdash; the real gate is the three limits above.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSavePlan"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancel"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
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
    var planTable = null, planData = [];
    var READY = <?= $ready ? 'true' : 'false' ?>;
    var SYM   = <?= json_encode($cur['symbol']) ?>;
    var CAN   = { e: <?= can('plans', 'e') ? 'true' : 'false' ?>, d: <?= can('plans', 'd') ? 'true' : 'false' ?> };

    $(document).ready(function() {
        if (!READY) return;
        loadPlans();
        $('#btnRefresh').on('click', function() { loadPlans(this); });
        $('#btnAdd').on('click', openAdd);
        $('#btnCloseModal, #btnCancel').on('click', function() { $('#planModal').removeClass('active'); });
        $('#planModal').on('click', function(e) { if (e.target === this) $(this).removeClass('active'); });
    });

    function money(v) { return SYM + ORMS.money(v); }

    // 0 in a max column = unlimited — say the word, never print a bare 0
    function limit(v) {
        return Number(v) === 0
            ? '<span class="bill-chip bill-unl"><i class="fas fa-infinity"></i> Unlimited</span>'
            : '<span class="subject-chip">' + esc(String(v)) + '</span>';
    }

    function feats(raw) {
        var list = String(raw || '').split(/[\n,]+/).map(function(s) { return s.trim(); }).filter(Boolean);
        if (!list.length) return '<span class="text-muted">&mdash;</span>';
        return '<span class="bill-feats">' + list.map(function(f) {
            return '<span class="subject-chip"><i class="fas fa-check"></i> ' + esc(f) + '</span>';
        }).join('') + '</span>';
    }

    function loadPlans(btn) {
        ORMS.post('getPlans', {}, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load plans'); return; }
            planData = res.data || [];
            $('#planSkeleton').addClass('initially-hidden');
            // nothing to show -> icon+message, never a blank table body
            $('#planWrap').toggleClass('initially-hidden', !planData.length);
            $('#planEmpty').toggleClass('initially-hidden', !!planData.length);
            if (planTable) { planTable.destroy(); $('#planTable').empty(); }
            var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
            planTable = $('#planTable').DataTable({
                data: planData,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                responsive: false,          // the chips carry the density; .table-responsive scrolls
                destroy: true,
                order: [],                  // the server orders by sort_order already
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
                    planCol('Plan', cellPlan, 'sort_order'),
                    planCol('Pricing', cellPricing, 'price_monthly'),
                    planCol('Limits', cellLimits, 'max_students'),
                    { data: 'features', title: 'Features', orderable: false,
                      render: function(d, t) { return t === 'display' ? feats(d) : String(d || ''); } },
                    { data: null, title: 'Actions', orderable: false, className: 'col-actions',
                      render: function(d, t, row) { return t === 'display' ? '<div class="actions-cell">' + rowActions(row) + '</div>' : ''; } }
                ]
            });
        }).fail(function(msg) { ORMS.err(msg); });
    }

    // ---- chip cells: 11 flat columns -> 5 grouped ones ----
    var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box;

    function cellPlan(r) {
        var live = Number(r.is_active) === 1, n = Number(r.schools) || 0;
        return K([
            '<div class="cell-title"><i class="fas fa-layer-group"></i> ' + esc(r.name) + '</div>',
            R(C(live ? 'chip-soft-green' : 'chip-soft-amber', 'fa-toggle-on', 'Status'),
              '<span class="' + (live ? 'val-pos' : 'val-neg') + '">' + (live ? 'Offered' : 'Not offered') + '</span>'),
            R(C('chip-soft-navy', 'fa-arrow-down-1-9', 'Order'), BOX(r.sort_order)),
            R(C('chip-link', 'fa-city', 'Schools on it'), n ? String(n) : '<span class="val-muted">none</span>')
        ]);
    }

    function cellPricing(r) {
        // the plan's own columns are the base currency; every extra one is a row it was given
        var rows = [
            R(C('chip-soft-tan', 'fa-calendar-day', 'Monthly'), money(r.price_monthly), 'val-amount'),
            R(C('chip-soft-tan', 'fa-calendar', 'Yearly'), money(r.price_yearly), 'val-amount')
        ];
        (r.prices || []).forEach(function (x) {
            rows.push(R(C('chip-soft-purple', 'fa-coins', esc(x.currency)),
                        ORMS.money(x.price_monthly) + ' / ' + ORMS.money(x.price_yearly), 'val-amount'));
        });
        return K(rows);
    }

    // 0 means unlimited everywhere in the quota engine — limit() already words it that way
    function cellLimits(r) {
        return K([
            R(C('chip-soft-purple', 'fa-user-graduate', 'Students'), limit(r.max_students)),
            R(C('chip-soft-purple', 'fa-chalkboard-user', 'Teachers'), limit(r.max_teachers)),
            R(C('chip-soft-purple', 'fa-code-branch', 'Branches'), limit(r.max_branches))
        ]);
    }

    function planBlob(r) {
        return [r.name, r.features, Number(r.is_active) === 1 ? 'Offered' : 'Not offered'].filter(Boolean).join(' ');
    }

    // chip html for display, the real value for sort, a text blob for filter
    function planCol(title, build, sortField, cls) {
        return { data: null, title: title, className: cls || '', render: function (d, t, r) {
            if (t === 'display') return build(r);
            if (t === 'filter')  return planBlob(r);
            var v = r[sortField];
            return v === null || v === undefined ? '' : v;
        } };
    }

    function rowActions(row) {
        var b = '';
        if (CAN.e) {
            b += '<button type="button" class="action-icon edit-icon" title="Edit" onclick="editPlan(' + row.id + ')"><i class="fas fa-edit"></i></button>';
            b += '<button type="button" class="action-icon view-icon" title="' + (Number(row.is_active) === 1 ? 'Stop offering' : 'Offer again') +
                 '" onclick="togglePlan(' + row.id + ', this)"><i class="fas fa-' + (Number(row.is_active) === 1 ? 'toggle-on' : 'toggle-off') + '"></i></button>';
        }
        if (CAN.d) b += '<button type="button" class="action-icon delete-icon" title="Delete" onclick="deletePlan(' + row.id + ', this)"><i class="fas fa-trash"></i></button>';
        return b || '<span class="text-muted">&mdash;</span>';
    }

    // ---- other-currency price rows ----
    // Prices are typed, never converted, so each row is just [currency, monthly, yearly].
    var CCY_OPTS = <?php
        $__o = '';
        foreach (ormsCurrencies() as $__c => $__m) {
            if ($__c === $cur['code']) continue;                    // the base currency lives in the fields above
            $__o .= '<option value="' . $__c . '">' . htmlspecialchars($__c . ' — ' . $__m[1], ENT_QUOTES) . '</option>';
        }
        echo json_encode($__o);
    ?>;

    function pcRow(ccy, mon, yr) {
        var $r = $('<div class="pc-row">' +
            '<select class="pc-ccy"><option value="">Currency…</option>' + CCY_OPTS + '</select>' +
            '<input type="text" class="pc-mon" inputmode="decimal" placeholder="Monthly">' +
            '<input type="text" class="pc-yr"  inputmode="decimal" placeholder="Yearly">' +
            '<button type="button" class="action-icon delete-icon pc-del" title="Remove"><i class="fas fa-trash"></i></button>' +
            '</div>');
        $r.find('.pc-ccy').val(ccy || '');
        $r.find('.pc-mon').val(mon === undefined || mon === null ? '' : ORMS.money(mon));
        $r.find('.pc-yr').val(yr === undefined || yr === null ? '' : ORMS.money(yr));
        $('#pcRows').append($r);
    }

    function pcFill(list) {
        $('#pcRows').empty();
        (list || []).forEach(function (x) { pcRow(x.currency, x.price_monthly, x.price_yearly); });
    }

    // what the form actually posts — a row with no currency picked is simply not a row
    function pcCollect() {
        var out = { pc_currency: [], pc_monthly: [], pc_yearly: [] };
        $('#pcRows .pc-row').each(function () {
            var c = ($(this).find('.pc-ccy').val() || '').trim();
            if (!c) return;
            out.pc_currency.push(c);
            out.pc_monthly.push(($(this).find('.pc-mon').val() || '').trim() || '0');
            out.pc_yearly.push(($(this).find('.pc-yr').val() || '').trim() || '0');
        });
        return out;
    }

    $('#btnPcAdd').on('click', function () { pcRow('', '', ''); });
    $('#pcRows').on('click', '.pc-del', function () { $(this).closest('.pc-row').remove(); });

    function openAdd() {
        $('#planModalTitle').html('<i class="fas fa-layer-group"></i> Add Plan');
        $('#planForm')[0].reset();
        $('#planId').val('');
        $('#planSort').val(planData.length);
        $('#planBranches').val(1);
        $('#planActive').prop('checked', true);
        pcFill([]);
        $('#planModal').addClass('active');
        setTimeout(function() { $('#planName').trigger('focus'); }, 60);
    }

    function editPlan(id) {
        var p = planData.filter(function(x) { return x.id == id; })[0];
        if (!p) return;
        $('#planModalTitle').html('<i class="fas fa-edit"></i> Edit Plan');
        pcFill(p.prices);
        $('#planId').val(p.id);
        $('#planName').val(p.name);
        $('#planSort').val(p.sort_order);
        $('#planMonthly').val(ORMS.money(p.price_monthly));
        $('#planYearly').val(ORMS.money(p.price_yearly));
        $('#planStudents').val(p.max_students);
        $('#planTeachers').val(p.max_teachers);
        $('#planBranches').val(p.max_branches);
        $('#planFeatures').val(p.features || '');
        $('#planActive').prop('checked', Number(p.is_active) === 1);
        $('#planModal').addClass('active');
    }

    function togglePlan(id, btn) {
        ORMS.post('togglePlan', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
            res.success ? (ORMS.ok(res.message), loadPlans()) : ORMS.err(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    }

    // swal confirm FIRST, busy state only once the operator has answered
    function deletePlan(id, btn) {
        var p = planData.filter(function(x) { return x.id == id; })[0];
        ORMS.confirmDelete('Delete plan "' + (p ? p.name : '') + '"? Only a plan no school has ever been billed on can be removed.').then(function(yes) {
            if (!yes) return;
            ORMS.post('deletePlan', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                res.success ? (ORMS.ok(res.message), loadPlans()) : ORMS.err(res.message, 'Cannot Delete');
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    var MONEY_RE = /^\d{1,8}(\.\d{1,2})?$/;

    $('#planForm').on('submit', function(e) {
        e.preventDefault();
        var name = $('#planName').val().trim();
        var mon  = ($('#planMonthly').val() || '').trim() || '0';
        var yr   = ($('#planYearly').val()  || '').trim() || '0';
        if (!name) { ORMS.err('Plan name is required'); return; }
        if (!MONEY_RE.test(mon) || !MONEY_RE.test(yr)) { ORMS.err('Prices must be a number with up to 2 decimals'); return; }

        ORMS.post('savePlan', $.extend({
            id: $('#planId').val(), name: name, price_monthly: mon, price_yearly: yr,
            max_students: $('#planStudents').val() || 0,
            max_teachers: $('#planTeachers').val() || 0,
            max_branches: $('#planBranches').val() || 0,
            features: $('#planFeatures').val(),
            sort_order: $('#planSort').val() || 0,
            is_active: $('#planActive').is(':checked') ? 1 : 0
        }, pcCollect()), { btn: '#btnSavePlan', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            $('#planModal').removeClass('active');
            ORMS.ok(res.message);
            loadPlans();
        }).fail(function(msg) { ORMS.err(msg); });
    });
    </script>
</body>
</html>
