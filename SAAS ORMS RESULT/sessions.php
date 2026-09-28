<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

// rbac view gate
requirePerm('sessions', 'v');

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'sessions';
$current_session_id = session_id();
$isPlat = ormsIsPlatform();   // only the operator sees live sessions across schools
$school = sid();

// user_sessions has no school_id of its own — the tenant leg rides on the owning user row.
// '' when the operator is looking, or on a pre-migration db that has no users.school_id yet
function sessScope(bool $isPlat): string {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT school_id FROM users LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return ($isPlat || !$has) ? '' : ' AND u.school_id = ?';
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getSessions':
                // Clean expired sessions first
                cleanExpiredSessions(SESSION_TIMEOUT);

                // scoped through the owning user — username, role, ip and ua of another school
                // must never reach this list
                $scope = sessScope($isPlat);
                $rows  = qAll("SELECT us.*, u.username, u.role
                    FROM user_sessions us
                    JOIN users u ON us.user_id = u.id
                    WHERE us.last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)" . $scope . "
                    ORDER BY us.last_activity DESC",
                    $scope ? 'ii' : 'i', ...($scope ? [SESSION_TIMEOUT, $school] : [SESSION_TIMEOUT]));

                $sessions = [];
                $unique_users = [];
                $wide_count = 0;   // school-wide roles, not just Admin

                foreach ($rows as $row) {
                    $is_current = ($row['session_id'] === $current_session_id);

                    // Parse user agent for browser
                    $browser = 'Unknown';
                    $ua = $row['user_agent'];
                    if (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
                    elseif (strpos($ua, 'Edg') !== false) $browser = 'Edge';
                    elseif (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
                    elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
                    elseif (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) $browser = 'Opera';

                    // Parse OS
                    $os = 'Unknown';
                    if (strpos($ua, 'Windows') !== false) $os = 'Windows';
                    elseif (strpos($ua, 'Mac') !== false) $os = 'macOS';
                    elseif (strpos($ua, 'Linux') !== false) $os = 'Linux';
                    elseif (strpos($ua, 'Android') !== false) $os = 'Android';
                    elseif (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) $os = 'iOS';

                    $sessions[] = [
                        'id' => $row['id'],
                        'session_id' => $row['session_id'],
                        'user_id' => $row['user_id'],
                        'username' => $row['username'],
                        'role' => $row['role'],
                        'ip_address' => $row['ip_address'],
                        'browser' => $browser,
                        'os' => $os,
                        'last_activity' => date('c', strtotime($row['last_activity'])),
                        'created_at' => date('c', strtotime($row['created_at'])),
                        'is_current' => $is_current
                    ];

                    $unique_users[$row['user_id']] = true;
                    if (ormsSchoolWide($row['role'])) $wide_count++;
                }

                echo json_encode([
                    'success' => true,
                    'data' => $sessions,
                    'stats' => [
                        'total' => count($sessions),
                        'unique_users' => count($unique_users),
                        'school_wide_sessions' => $wide_count
                    ]
                ]);
                exit();

            case 'forceLogout':
                requirePermJson('sessions', 'd'); // terminate = delete perm
                requireCsrfJson();

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $target_session_id = isset($_POST['session_id']) ? $_POST['session_id'] : '';

                if (empty($target_session_id)) {
                    echo json_encode(['success' => false, 'message' => 'Session ID required']);
                    exit();
                }

                if ($target_session_id === $current_session_id) {
                    echo json_encode(['success' => false, 'message' => 'Cannot force-logout your own session']);
                    exit();
                }

                // target must sit inside the same school — same scope as the list, or a guessed
                // session id would let one school terminate another school's users
                $scope  = sessScope($isPlat);
                $target = qOne("SELECT us.user_id, u.username FROM user_sessions us
                                JOIN users u ON us.user_id = u.id
                                WHERE us.session_id = ?" . $scope,
                               $scope ? 'si' : 's', ...($scope ? [$target_session_id, $school] : [$target_session_id]));

                if (!$target) {
                    echo json_encode(['success' => false, 'message' => 'Session not found']);
                    exit();
                }

                // Set force_logout flag
                $stmt = $conn->prepare("UPDATE user_sessions SET force_logout = 1 WHERE session_id = ?");
                $stmt->bind_param("s", $target_session_id);
                $stmt->execute();
                $stmt->close();

                // Log activity
                logActivity($user_id, $username, 'Force Logout', 'Force-logged out user: ' . $target['username']);

                // Notify the target user
                try {
                    createNotification($target['user_id'], 'Session Terminated', 'Your session was terminated by an administrator.', 'danger', 'login.php');
                } catch (Exception $e) {}

                echo json_encode(['success' => true, 'message' => 'User session terminated']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log('sessions.php error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit();
    }
}

// key => label/colour for the js badge renderer — a new role paints itself, no per-role css
$roleMeta = [];
foreach (readRoles() as $r) $roleMeta[$r['key']] = ['label' => $r['label'], 'color' => $r['color']];

// tile tooltip names the roles it counts, straight from the helper
$wideRolesLabel = implode(' + ', array_map(fn($k) => $roleMeta[$k]['label'] ?? $k, ormsSchoolWideRoles()));
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
    <title>Sessions - <?php echo htmlspecialchars(getSiteBranding()['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body class="initially-hidden">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-desktop"></i> Session Management</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>System</span>
                        <span class="breadcrumb-sep">/</span>
                        <span>Sessions</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Stat Cards -->
            <div class="dashboard-grid-3" id="statsContainer">
                <div class="stat-card">
                    <div class="stat-card-icon"><i class="fas fa-desktop"></i></div>
                    <div class="stat-card-value" id="statActiveSessions">-</div>
                    <div class="stat-card-label">Active Sessions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon icon-gradient-success"><i class="fas fa-users"></i></div>
                    <div class="stat-card-value" id="statUniqueUsers">-</div>
                    <div class="stat-card-label">Unique Users Online</div>
                </div>
                <div class="stat-card" title="<?php echo htmlspecialchars($wideRolesLabel); ?>">
                    <div class="stat-card-icon icon-gradient-warning"><i class="fas fa-user-shield"></i></div>
                    <div class="stat-card-value" id="statSchoolWideSessions">-</div>
                    <div class="stat-card-label">School-Wide Sessions</div>
                </div>
            </div>

            <!-- Sessions Table -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-list"></i> Active Sessions</h2>
                    <button class="btn btn-primary" onclick="loadSessions()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnClearSessionFilter"><i class="fas fa-eraser"></i> Clear</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-user-tag"></i> Role</label>
                            <select id="filterSessionRole" class="filter-input">
                                <option value="">All Roles</option>
                                <?php foreach (readRoles() as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['key']); ?>"><?php echo htmlspecialchars($r['label'] ?: $r['key']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-desktop"></i> Device</label>
                            <select id="filterSessionOs" class="filter-input">
                                <option value="">All Devices</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Skeleton Loader -->
                <div id="sessionsSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>
                        <div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>
                    </div>
                </div>

                <div id="sessionsTableContainer" class="initially-hidden">
                    <table id="sessionsTable" class="display table-full-width">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>IP Address</th>
                                <th>Browser / OS</th>
                                <th>Last Active</th>
                                <th>Started</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="orms.js?v=2.5"></script>
    <script>
    window.ORMS_CSRF = '<?= csrfToken() ?>';   // csrf for this page's $.ajax calls
    $(document).ajaxSend(function(e, x){ if (window.ORMS_CSRF) x.setRequestHeader('X-CSRF-Token', window.ORMS_CSRF); });
    let sessionsTable = null, sessionsRaw = [];
    let refreshInterval = null;

    // roles straight from the roles table — label + colour per key
    const ROLE_META = <?php echo json_encode($roleMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    // stored role colour -> swatch, luminance picks readable text. hex validated, never echoed raw
    function roleStyle(hex) {
        var h = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(hex || '')) ? String(hex) : '#0074D9';
        var c = h.length === 4 ? h[1] + h[1] + h[2] + h[2] + h[3] + h[3] : h.slice(1);
        var lum = parseInt(c.slice(0, 2), 16) * .299 + parseInt(c.slice(2, 4), 16) * .587 + parseInt(c.slice(4, 6), 16) * .114;
        return 'background:' + h + ';color:' + (lum > 160 ? '#001f3f' : '#fff');
    }

    function maskIP(ip) {
        var parts = ip.split('.');
        if (parts.length === 4) return parts[0] + '.' + parts[1] + '.***. ***';
        return ip.replace(/:[\da-f]+:[\da-f]+$/i, ':***:***');
    }

    function renderIP(ip) {
        var masked = maskIP(ip);
        var escaped = $('<span>').text(ip).html();
        var escapedMasked = $('<span>').text(masked).html();
        return '<span class="ip-mask-wrap">' +
            '<code class="ip-display" data-full="' + escaped + '" data-masked="' + escapedMasked + '">' + escapedMasked + '</code>' +
            '<button class="ip-toggle-btn" onclick="toggleIP(this)" title="Show/Hide IP"><i class="fas fa-eye"></i></button>' +
            '</span>';
    }

    function toggleIP(btn) {
        var code = btn.previousElementSibling;
        var icon = btn.querySelector('i');
        if (code.textContent === code.getAttribute('data-masked')) {
            code.textContent = code.getAttribute('data-full');
            icon.className = 'fas fa-eye-slash';
        } else {
            code.textContent = code.getAttribute('data-masked');
            icon.className = 'fas fa-eye';
        }
    }

    function timeAgo(dateStr) {
        const seconds = Math.floor((new Date() - new Date(dateStr)) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    function loadSessions() {
        $.ajax({
            url: 'sessions.php?action=getSessions',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update stats
                    $('#statActiveSessions').text(response.stats.total);
                    $('#statUniqueUsers').text(response.stats.unique_users);
                    $('#statSchoolWideSessions').text(response.stats.school_wide_sessions);

                    // raw rows kept alongside the rendered ones — filters read these, never the markup
                    sessionsRaw = response.data || [];
                    var seenOs = {};
                    sessionsRaw.forEach(function(x) { if (x.os) seenOs[x.os] = 1; });
                    var $os = $('#filterSessionOs'), keep = $os.val();
                    $os.html('<option value="">All Devices</option>' + Object.keys(seenOs).sort().map(function(o) {
                        return '<option value="' + $('<span>').text(o).html() + '">' + $('<span>').text(o).html() + '</option>';
                    }).join(''));
                    $os.val(keep || '');
                    if (window.ORMS && ORMS.dropdown) ORMS.dropdown.refresh('#filterSessionOs');

                    // Build table data
                    const tableData = response.data.map(function(s) {
                        const rm = ROLE_META[s.role];
                        const roleBadge = '<span class="role-badge" style="' + roleStyle(rm && rm.color) + '">' +
                                          $('<span>').text(rm ? rm.label : (s.role || '—')).html() + '</span>';

                        const currentBadge = s.is_current
                            ? ' <span class="status-badge status-current">Current</span>'
                            : '';

                        const actionBtn = s.is_current
                            ? '<button class="btn btn-secondary btn-sm" disabled><i class="fas fa-shield-alt"></i> Current</button>'
                            : '<button class="btn btn-danger btn-sm" onclick="forceLogout(\'' + s.session_id + '\', \'' + s.username.replace(/'/g, "\\'") + '\')"><i class="fas fa-power-off"></i> Force Logout</button>';

                        return [
                            '<strong>' + $('<span>').text(s.username).html() + '</strong>' + currentBadge,
                            roleBadge,
                            renderIP(s.ip_address),
                            '<i class="fas fa-globe"></i> ' + s.browser + ' / ' + s.os,
                            '<span data-order="' + s.last_activity + '">' + timeAgo(s.last_activity) + '</span>',
                            '<span data-order="' + s.created_at + '">' + timeAgo(s.created_at) + '</span>',
                            actionBtn
                        ];
                    });

                    // Show table
                    $('#sessionsSkeleton').hide();
                    $('#sessionsTableContainer').show();

                    if (sessionsTable) {
                        sessionsTable.clear().rows.add(tableData).draw();
                    } else {
                        sessionsTable = $('#sessionsTable').DataTable({
                            data: tableData,
                            pageLength: 10,
                            responsive: true,
                            order: [[4, 'asc']],
                            dom: 'Bfrtip',
                            buttons: ['csv', 'pdf', 'print'],
                            language: {
                                emptyTable: 'No active sessions found'
                            }
                        });
                    }
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to load sessions', 'error');
            }
        });
    }

    function forceLogout(sessionId, username) {
        Swal.fire({
            title: 'Force Logout?',
            html: 'Are you sure you want to terminate <strong>' + username + '</strong>\'s session?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Yes, Terminate',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'sessions.php?action=forceLogout',
                    method: 'POST',
                    data: { session_id: sessionId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Terminated!', response.message, 'success');
                            loadSessions();
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Request failed', 'error');
                    }
                });
            }
        });
    }

    // Initialize
    $(document).ready(function() {
        // searchable filters — plain <select> is banned
        if (window.ORMS && ORMS.dropdown) ORMS.dropdown('#filterSessionRole, #filterSessionOs');
        $('#filterSessionRole, #filterSessionOs').on('change', function() { if (sessionsTable) sessionsTable.draw(); });
        $('#btnClearSessionFilter').on('click', function() {
            $('#filterSessionRole, #filterSessionOs').val('');
            if (window.ORMS && ORMS.dropdown) ORMS.dropdown.refresh('#filterSessionRole, #filterSessionOs');
            if (sessionsTable) sessionsTable.draw();
        });
        // role/device come off the raw row by index, so a re-skinned badge can never break the filter
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'sessionsTable') return true;
            var r = sessionsRaw[dataIndex]; if (!r) return true;
            var role = $('#filterSessionRole').val(), os = $('#filterSessionOs').val();
            if (role && String(r.role || '') !== role) return false;
            if (os && String(r.os || '') !== os) return false;
            return true;
        });

        document.body.classList.remove('initially-hidden');
        loadSessions();

        // Auto-refresh every 30 seconds
        refreshInterval = setInterval(loadSessions, 30000);
    });
    </script>
</body>
</html>
