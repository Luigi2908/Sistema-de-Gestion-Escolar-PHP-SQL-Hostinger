<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// login check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

// hard gate — explicit role check, not matrix-based (prevents self-lockout)
if (!canEditRbac($_SESSION['role'] ?? '')) {
    header('Location: dashboard.php');
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'User';
$user_id = $_SESSION['user_id'];
$current_page = 'roles';
$isPlat  = ormsIsPlatform();
$school  = sid();

// ============================================
// AJAX handlers — JSON + exit() before any HTML
// ============================================

// role_keys THIS school owns. readRoles() also hands back the school-0 template set so an
// impersonating operator keeps a matrix, but a school may only see + edit its own rows.
// null = pre-migration schema (no school_id on roles) -> no filtering, single-tenant install
function rolesOwnKeys(int $school): ?array {
    static $keys = false;
    if ($keys === false) {
        try { $keys = array_column(qAll("SELECT role_key FROM roles WHERE school_id = ?", 'i', $school), 'role_key'); }
        catch (Throwable $e) { $keys = null; }
    }
    return $keys;
}

// this role belongs to the caller's school? operator edits whatever school it has stepped into
function rolesOwns(string $key, int $school, bool $isPlat): bool {
    if ($isPlat) return true;
    $own = rolesOwnKeys($school);
    return $own === null ? true : in_array($key, $own, true);
}

// platform console pages a school role must never be able to hand itself
function rolesPlatformPages(): array { return ['schools', 'plans', 'subscriptions']; }

// build full matrix payload for the grid
if (isset($_GET['action']) && $_GET['action'] === 'getMatrix') {
    header('Content-Type: application/json');
    try {
        global $RBAC_PAGES;

        // platform console rows are the operator's only — a school can't even see them to tick
        $platPages = rolesPlatformPages();
        $pages = [];
        foreach ($RBAC_PAGES as $p) {
            if (!$isPlat && in_array($p['key'], $platPages, true)) continue;
            // group falls back so a page can never drop out of the grid
            $pages[] = ['key' => $p['key'], 'label' => $p['label'] ?? $p['key'], 'icon' => $p['icon'] ?? 'fa-file', 'group' => $p['group'] ?? 'Other'];
        }

        $roles = [];
        $perms = [];
        foreach (readRoles() as $r) {
            if (empty($r['key'])) continue;                                    // malformed row -> never render a keyless column
            if (!rolesOwns($r['key'], $school, $isPlat)) continue;   // other schools' matrices stay invisible
            $roles[] = ['key' => $r['key'], 'label' => $r['label'], 'color' => $r['color'], 'is_super' => $r['is_super']];
            // ensure every page key present so grid never has gaps
            $rp = [];
            foreach ($pages as $p) {
                $cell = $r['perms'][$p['key']] ?? [];
                $rp[$p['key']] = [
                    'v' => !empty($cell['v']) ? 1 : 0,
                    'a' => !empty($cell['a']) ? 1 : 0,
                    'e' => !empty($cell['e']) ? 1 : 0,
                    'd' => !empty($cell['d']) ? 1 : 0,
                ];
            }
            $perms[$r['key']] = $rp;
        }

        echo json_encode(['success' => true, 'pages' => $pages, 'roles' => $roles, 'perms' => $perms]);
        exit();
    } catch (Exception $e) {
        error_log("Roles getMatrix error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading matrix']);
        exit();
    }
}

// flip one V/A/E/D cell for a role×page
if (isset($_GET['action']) && $_GET['action'] === 'toggle') {
    requireCsrfJson();          // csrf first, page is already editor-gated above
    header('Content-Type: application/json');
    try {
        $roleKey = trim($_POST['roleKey'] ?? '');
        $pageKey = trim($_POST['pageKey'] ?? '');
        $perm    = trim($_POST['perm'] ?? '');
        $value   = (int)($_POST['value'] ?? 0) === 1 ? 1 : 0;

        // validate perm
        if (!in_array($perm, ['v', 'a', 'e', 'd'], true)) {
            echo json_encode(['success' => false, 'message' => 'Bad permission']);
            exit();
        }

        // validate pageKey against registry
        global $RBAC_PAGES;
        $validPage = false;
        foreach ($RBAC_PAGES as $p) {
            if ($p['key'] === $pageKey) { $validPage = true; break; }
        }
        if (!$validPage) {
            echo json_encode(['success' => false, 'message' => 'Bad page']);
            exit();
        }

        // a school role must never be able to grant itself the platform console
        if (!$isPlat && in_array($pageKey, rolesPlatformPages(), true)) {
            echo json_encode(['success' => false, 'message' => 'Bad page']);
            exit();
        }

        // role must exist
        $r = roleByKey($roleKey);
        if (!$r) {
            echo json_encode(['success' => false, 'message' => 'Bad role']);
            exit();
        }

        // ...and belong to THIS school — the school-0 template set is read-only to a tenant
        if (!rolesOwns($roleKey, $school, $isPlat)) {
            echo json_encode(['success' => false, 'message' => 'Bad role']);
            exit();
        }

        // super rows editable only by an rbac editor (roles.php is already editor-gated)
        if ($r['is_super'] == 1 && !canEditRbac($_SESSION['role'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Locked role']);
            exit();
        }

        // decode current perms, ensure cell exists
        $perms = is_array($r['perms']) ? $r['perms'] : [];
        if (!isset($perms[$pageKey]) || !is_array($perms[$pageKey])) {
            $perms[$pageKey] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        }
        foreach (['v', 'a', 'e', 'd'] as $k) {
            if (!isset($perms[$pageKey][$k])) $perms[$pageKey][$k] = 0;
        }

        // set + implied-view
        $perms[$pageKey][$perm] = $value;
        if ($perm === 'v' && !$value) {
            $perms[$pageKey]['a'] = 0;
            $perms[$pageKey]['e'] = 0;
            $perms[$pageKey]['d'] = 0;
        } elseif ($perm !== 'v' && $value) {
            $perms[$pageKey]['v'] = 1;
        }

        // persist. pk is (school_id, role_key) now — without the school leg one school's
        // toggle rewrites every school's row that happens to share the key
        $json = json_encode($perms);
        $ok = false;
        try {
            qExec("UPDATE roles SET permissions = ? WHERE school_id = ? AND role_key = ?", 'sis', $json, $school, $roleKey);
            $ok = true;
        } catch (Throwable $e) {   // pre-migration schema — role_key was still the whole pk
            try { qExec("UPDATE roles SET permissions = ? WHERE role_key = ?", 'ss', $json, $roleKey); $ok = true; }
            catch (Throwable $e2) { $ok = false; }
        }

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Save failed']);
            exit();
        }

        // bust per-request cache (harmless here, future-proof)
        readRoles(true);

        logActivity($user_id, $username, 'Update Permissions', "role=$roleKey page=$pageKey $perm=$value");

        echo json_encode(['success' => true, 'message' => 'Saved']);
        exit();
    } catch (Exception $e) {
        error_log("Roles toggle error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Save failed']);
        exit();
    }
}

// If we reach here, render the HTML page
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
    <title>Roles &amp; Permissions - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
                    <h1><i class="fas fa-user-shield"></i> Roles &amp; Permissions</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>System</span>
                        <span class="breadcrumb-sep">/</span>
                        <span>Roles &amp; Permissions</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table-cells"></i> Permission Matrix</h2>
                    <button class="btn btn-primary" onclick="loadMatrix()">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>

                <p class="rbac-help">
                    <i class="fas fa-circle-info"></i>
                    Toggle <strong>V</strong>iew &middot; <strong>A</strong>dd &middot; <strong>E</strong>dit &middot; <strong>D</strong>elete per role. Turning off View clears Add/Edit/Delete; turning on any action turns View on. Changes save instantly.
                </p>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Matrix -->
                <div id="matrixContainer" class="initially-hidden">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all roles
                    </div>
                    <div class="rbac-wrap" id="rbacWrap"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <script>
        // current admin can edit rbac — drives super-lock styling
        var CAN_EDIT_RBAC = <?php echo canEditRbac($role) ? 'true' : 'false'; ?>;
        var matrix = { pages: [], roles: [], perms: {} };
        var groupsOrder = <?php echo json_encode($RBAC_GROUPS); ?>;

        function esc(s) {
            return $('<span>').text(s == null ? '' : s).html();
        }

        $(document).ready(function() {
            loadMatrix();
        });

        function loadMatrix() {
            $('#loadingSkeleton').show();
            $('#matrixContainer').hide();
            ORMS.bar.start();                          // reads drive the thin top bar

            fetch('?action=getMatrix', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.success) {
                        matrix.pages = res.pages || [];
                        matrix.roles = res.roles || [];
                        matrix.perms = res.perms || {};
                        renderMatrix();
                        $('#loadingSkeleton').hide();
                        $('#matrixContainer').show();
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed to load matrix' });
                    }
                })
                .catch(function(err) {
                    console.error('Matrix load error:', err);
                    $('#loadingSkeleton').hide();
                    ORMS.err('Could not load the permission matrix.', 'Connection Error');
                })
                .finally(function() { ORMS.bar.done(); });   // bar can never stick
        }

        function renderMatrix() {
            var html = '<table class="rbac-table"><thead><tr>';
            html += '<th class="rbac-pagecol"><i class="fas fa-sitemap"></i> Page</th>';

            matrix.roles.forEach(function(role) {
                html += '<th>'
                    + '<span class="rbac-rolehead" style="background:' + esc(role.color) + '">'
                    + '<i class="fas fa-user-tag"></i> ' + esc(role.label)
                    + (role.is_super == 1 ? ' <i class="fas fa-crown" title="Super role"></i>' : '')
                    + '</span>'
                    + '<div class="rbac-perm-legend">V&middot;A&middot;E&middot;D</div>'
                    + '</th>';
            });
            html += '</tr></thead><tbody>';

            // registry order first, then any group the registry doesn't list (new modules never vanish)
            var groups = groupsOrder.slice();
            matrix.pages.forEach(function(p) { if (groups.indexOf(p.group) === -1) groups.push(p.group); });

            // rows grouped by group
            groups.forEach(function(grp) {
                var pagesInGroup = matrix.pages.filter(function(p) { return p.group === grp; });
                if (!pagesInGroup.length) return;

                html += '<tr class="rbac-grouprow"><td colspan="' + (matrix.roles.length + 1) + '">'
                    + '<i class="fas fa-layer-group"></i> ' + esc(grp) + '</td></tr>';

                pagesInGroup.forEach(function(page) {
                    html += '<tr><td class="rbac-pagecol">' + esc(page.label) + '</td>';
                    matrix.roles.forEach(function(role) {
                        html += '<td><div class="rbac-cell">' + cellDots(role, page.key) + '</div></td>';
                    });
                    html += '</tr>';
                });
            });

            html += '</tbody></table>';
            document.getElementById('rbacWrap').innerHTML = html;
        }

        function cellDots(role, pageKey) {
            var cell = (matrix.perms[role.key] && matrix.perms[role.key][pageKey]) || { v: 0, a: 0, e: 0, d: 0 };
            // super role locked when current admin can't edit rbac
            var locked = (role.is_super == 1 && !CAN_EDIT_RBAC);
            var defs = [['v', 'V'], ['a', 'A'], ['e', 'E'], ['d', 'D']];
            var out = '';
            defs.forEach(function(d) {
                var perm = d[0], lbl = d[1];
                var on = cell[perm] ? ' on' : '';
                var lock = locked ? ' locked' : '';
                out += '<button type="button" class="rbac-dot' + on + lock + '"'
                    + ' data-role="' + esc(role.key) + '" data-page="' + esc(pageKey) + '" data-perm="' + perm + '"'
                    + (locked ? ' disabled' : ' onclick="flipDot(this)"')
                    + ' title="' + lbl + '">' + lbl + '</button>';
            });
            return out;
        }

        function flipDot(btn) {
            var roleKey = btn.getAttribute('data-role');
            var pageKey = btn.getAttribute('data-page');
            var perm = btn.getAttribute('data-perm');

            var cell = matrix.perms[roleKey][pageKey];
            var prev = { v: cell.v, a: cell.a, e: cell.e, d: cell.d };  // snapshot for revert
            var newVal = cell[perm] ? 0 : 1;

            // optimistic flip + mirror implied-view client-side
            cell[perm] = newVal;
            if (perm === 'v' && !newVal) {
                cell.a = 0; cell.e = 0; cell.d = 0;
            } else if (perm !== 'v' && newVal) {
                cell.v = 1;
            }
            repaintRow(roleKey, pageKey);

            // repaint rebuilt the cell — spin the fresh dot, not the discarded one
            var dot = dotNode(roleKey, pageKey, perm) || btn;

            ORMS.post('toggle', { roleKey: roleKey, pageKey: pageKey, perm: perm, value: newVal },
                      { btn: dot, busyLabel: ' ' })
                .done(function(res) {
                    if (res && res.success) return;
                    // server refused, nothing written — put the snapshot back
                    matrix.perms[roleKey][pageKey] = prev;
                    repaintRow(roleKey, pageKey);
                    ORMS.err((res && res.message) || 'Save failed', 'Not saved');
                })
                .fail(function(msg) {
                    // transport died mid-flight, write state unknown — reload truth
                    loadMatrix();
                    ORMS.err(msg || 'Change was not saved.', 'Connection Error');
                });
        }

        function dotNode(roleKey, pageKey, perm) {
            return document.querySelector('.rbac-dot[data-role="' + cssEsc(roleKey) + '"][data-page="' + cssEsc(pageKey) + '"]'
                + (perm ? '[data-perm="' + perm + '"]' : ''));
        }

        // repaint only the affected role×page cell (cheap, keeps scroll)
        function repaintRow(roleKey, pageKey) {
            var role = matrix.roles.find(function(r) { return r.key === roleKey; });
            if (!role) return;
            var btn = dotNode(roleKey, pageKey, '');
            if (!btn) return;
            var cellDiv = btn.closest('.rbac-cell');
            if (cellDiv) cellDiv.innerHTML = cellDots(role, pageKey);
        }

        function cssEsc(s) {
            return String(s).replace(/["\\]/g, '\\$&');
        }
    </script>
</body>
</html>
