<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

$schAjax = isset($_GET['action']) || isset($_POST['action'])
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

// rbac view gate
requirePerm('schools', 'v');

// belt and braces — the console is the platform operator's, matrix or no matrix.
// a school admin whose row somehow carries 'schools' still never gets past this line.
if (!ormsIsPlatform()) {
    if ($schAjax) jsonErr('Access denied');
    header('Location: ' . (can('dashboard', 'v') ? 'dashboard.php' : 'account.php'));
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'schools';

// table probe, one per request — a db that hasn't taken the tenant migration must not fatal
function schTable(string $t): bool {
    static $seen = [];
    if (!isset($seen[$t])) {
        try { qVal("SELECT 1 FROM `$t` LIMIT 1"); $seen[$t] = true; }
        catch (Throwable $e) { $seen[$t] = false; }
    }
    return $seen[$t];
}

// column probe — same shape, for the odd column that lands in a later step
function schCol(string $t, string $c): bool {
    static $seen = [];
    $k = "$t.$c";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$c` FROM `$t` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

// the whole console needs the tenant migration — probe both, not just one
function schReady(): bool { return ormsHasTenancy() && schTable('schools') && schTable('branches'); }

// tolerant counter — a table that isn't there yet can't be blocking anything
function schCount(string $sql, string $types = '', ...$p): int {
    try { return (int) qVal($sql, $types, ...$p); } catch (Throwable $e) { return 0; }
}

// index probe — decides whether usernames are still globally unique on this db
function schIndex(string $t, string $idx): bool {
    try {
        return (int) qVal("SELECT COUNT(*) FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?", 'ss', $t, $idx) > 0;
    } catch (Throwable $e) { return false; }
}

// tenant code — upper, trimmed, no punctuation surprises
function schCode(string $raw): string { return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($raw))); }

// readable one-off password. no look-alikes (0/O, 1/l), one of each class so any policy passes
function schPwd(): string {
    $up = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $lo = 'abcdefghijkmnopqrstuvwxyz'; $nu = '23456789'; $sy = '@#$%&*';
    $pool = $up . $lo . $nu;
    $out = $up[random_int(0, 23)] . $lo[random_int(0, 24)] . $nu[random_int(0, 7)] . $sy[random_int(0, 5)];
    for ($i = 0; $i < 7; $i++) $out .= $pool[random_int(0, strlen($pool) - 1)];
    return str_shuffle($out);
}

// [label => n] -> "42 student(s), 3 branch(es)"
function schBlockers(array $counts): string {
    $parts = [];
    foreach ($counts as $label => $n) if ($n > 0) $parts[] = $n . ' ' . $label;
    return implode(', ', $parts);
}

$SCH_STATUSES = ['Trial', 'Active', 'Suspended', 'Cancelled'];

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    try {
        if (!schReady()) jsonErr('The multi-tenant tables are not installed yet — run update_setup.php once, then reload this page.');

        switch ($action) {

            // ------------------------------------------------------------ list
            case 'getSchools':
                // counts are correlated subqueries: the schools table is tiny, and one pass beats five round trips
                $stu = schTable('students') && schCol('students', 'school_id')
                     ? "(SELECT COUNT(*) FROM students st WHERE st.school_id = s.id)" : "0";
                $tch = schTable('teachers') && schCol('teachers', 'school_id')
                     ? "(SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id)" : "0";

                $rows = qAll(
                    "SELECT s.id, s.name, s.code, s.logo, s.address, s.phone, s.email, s.status, s.plan_id,
                            s.trial_ends_at, s.owner_user_id, s.notes, s.created_at,
                            p.name AS plan_name,
                            $stu AS students,
                            $tch AS teachers,
                            (SELECT COUNT(*) FROM branches b WHERE b.school_id = s.id) AS branches,
                            (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id) AS users,
                            (SELECT sub.ends_at FROM school_subscriptions sub WHERE sub.school_id = s.id
                              ORDER BY sub.ends_at DESC, sub.id DESC LIMIT 1) AS ends_at,
                            (SELECT sub.status FROM school_subscriptions sub WHERE sub.school_id = s.id
                              ORDER BY sub.ends_at DESC, sub.id DESC LIMIT 1) AS sub_status,
                            (SELECT ou.id FROM users ou WHERE ou.id = s.owner_user_id AND ou.school_id = s.id AND ou.is_active = 1) AS owner_ok,
                            (SELECT au.id FROM users au WHERE au.school_id = s.id AND au.role = 'Admin' AND au.is_active = 1
                              ORDER BY au.id ASC LIMIT 1) AS admin_id
                     FROM schools s
                     LEFT JOIN plans p ON p.id = s.plan_id
                     ORDER BY s.id ASC");

                // "Enter school" needs ONE user id — the stamped owner if it still holds, else the school's first live Admin
                foreach ($rows as &$r) $r['enter_id'] = (int) ($r['owner_ok'] ?: $r['admin_id']);
                unset($r);
                jsonOk(['data' => $rows, 'today' => date('Y-m-d')]);

            // ------------------------------------------------------------ logo upload
            // The logo used to be a URL box, which meant the operator had to host the image somewhere
            // else first and a dead link printed on every result card. It is a real upload now; the
            // stored value is still just the path, so nothing downstream changed.
            case 'uploadSchoolLogo': {
                requireCsrfJson();
                if (!can('schools', 'a') && !can('schools', 'e')) jsonErr('Access denied');

                [$rel, $dim] = ormsSaveImageUpload($_FILES['logo_file'] ?? null, 'uploads/branding', 'school_logo', 'Logo');

                // replacing a logo: drop the file it replaced, but only when THIS school is the row
                // that holds it (or nobody does — an orphan from an earlier pick in this same form)
                $old = trim((string) ($_POST['old'] ?? ''));
                $sid = (int) ($_POST['id'] ?? 0);
                if ($old !== '' && $old !== $rel) {
                    $cur  = $sid > 0 ? (string) (qVal("SELECT logo FROM schools WHERE id = ?", 'i', $sid) ?? '') : '';
                    $used = (int) qVal("SELECT COUNT(*) FROM schools WHERE logo = ?", 's', $old);
                    if (($sid > 0 && $old === $cur && $used <= 1) || ($sid === 0 && $used === 0)) ormsDropUpload($old);
                }
                jsonOk(['logo' => $rel, 'dim' => $dim, 'message' => 'Logo uploaded (' . $dim . ')']);
            }

            // ------------------------------------------------------------ create / edit
            case 'saveSchool':
                requireCsrfJson();
                $raw = (int) ($_POST['id'] ?? 0);
                requirePermJson('schools', $raw ? 'e' : 'a');     // perm follows what was ASKED for

                $name  = trim($_POST['name'] ?? '');
                $code  = schCode($_POST['code'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $addr  = trim($_POST['address'] ?? '');
                $logo  = trim($_POST['logo'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $plan  = (int) ($_POST['plan_id'] ?? 0);
                $trial = trim($_POST['trial_ends_at'] ?? '');

                if ($name === '')            jsonErr('School name is required');
                if (mb_strlen($name) > 150)  jsonErr('School name must be 150 characters or less');
                if ($code === '')            jsonErr('School code is required (letters, digits, - and _ only)');
                if (strlen($code) < 2 || strlen($code) > 20) jsonErr('School code must be between 2 and 20 characters');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('School email is not a valid address');
                if (mb_strlen($phone) > 30)  jsonErr('Phone must be 30 characters or less');
                if (mb_strlen($addr) > 255)  jsonErr('Address must be 255 characters or less');
                if (mb_strlen($logo) > 255)  jsonErr('Logo path must be 255 characters or less');
                if (mb_strlen($notes) > 255) jsonErr('Notes must be 255 characters or less');
                if ($trial !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trial)) jsonErr('Trial end must be a real date (YYYY-MM-DD)');

                // a stale plan id must die here — the fk is ON DELETE SET NULL and would swallow it silently
                $planName = 'no plan';
                if ($plan > 0) {
                    $p = qOne("SELECT name, price_monthly, price_yearly FROM plans WHERE id = ?", 'i', $plan);
                    if (!$p) jsonErr('The selected plan no longer exists');
                    $planName = $p['name'];
                } else { $p = null; $plan = null; }

                // uniq_school_code — answer before the db does, so the user reads a sentence not a 1062
                if (qVal("SELECT id FROM schools WHERE code = ? AND id <> ?", 'si', $code, $raw))
                    jsonErr('A school with code "' . $code . '" already exists');

                $email = $email === '' ? null : $email;
                $phone = $phone === '' ? null : $phone;
                $addr  = $addr  === '' ? null : $addr;
                $logo  = $logo  === '' ? null : $logo;
                $notes = $notes === '' ? null : $notes;
                $trial = $trial === '' ? null : $trial;

                // ---- edit: profile only. status is its own audited action, never a silent field
                if ($raw) {
                    if (!qVal("SELECT id FROM schools WHERE id = ?", 'i', $raw)) jsonErr('School not found');
                    // types: s name, s code, s email, s phone, s addr, s logo, i plan, s trial, s notes, i id
                    qExec("UPDATE schools SET name = ?, code = ?, email = ?, phone = ?, address = ?, logo = ?,
                                              plan_id = ?, trial_ends_at = ?, notes = ? WHERE id = ?",
                          'ssssssissi', $name, $code, $email, $phone, $addr, $logo, $plan, $trial, $notes, $raw);
                    logActivity($user_id, $username, 'School Updated', "Updated school: $name [$code] (#$raw) — plan: $planName", 'school', $raw);
                    jsonOk(['message' => 'School updated successfully']);
                }

                // ---- create: school + main branch + role matrix + first admin + subscription, all or nothing
                $status = trim($_POST['status'] ?? 'Trial');
                if (!in_array($status, ['Trial', 'Active'], true)) jsonErr('A new school starts as Trial or Active');

                $bName = trim($_POST['branch_name'] ?? '') ?: 'Main Branch';
                $bCode = schCode($_POST['branch_code'] ?? '') ?: 'MAIN';
                if (mb_strlen($bName) > 120) jsonErr('Main branch name must be 120 characters or less');
                if (strlen($bCode) > 20)     jsonErr('Main branch code must be 20 characters or less');

                $aUser = validateUsername($_POST['admin_username'] ?? '');
                $aName = trim($_POST['admin_fullname'] ?? '');
                $aMail = trim($_POST['admin_email'] ?? '');
                $aPass = (string) ($_POST['admin_password'] ?? '');
                if ($aUser === false) jsonErr('Admin username must be 3-50 characters, letters, digits and underscore only');
                if ($aMail === '' || !filter_var($aMail, FILTER_VALIDATE_EMAIL)) jsonErr('A valid admin email is required');
                if ($aName === '') $aName = $aUser;
                if (mb_strlen($aName) > 100) jsonErr('Admin full name must be 100 characters or less');
                $aPass = trim($aPass) === '' ? schPwd() : $aPass;       // blank = generate one and show it once
                if (validatePassword($aPass) === false) jsonErr('Admin password must be at least 6 characters');

                // usernames are unique per school once the migration swapped the key. on a db where it
                // did NOT swap (duplicate rows blocked it), the old global unique is still live — probe it,
                // or the insert dies mid-transaction with a 1062 nobody can read
                if (!schIndex('users', 'uniq_school_username')
                    && (int) qVal("SELECT COUNT(*) FROM users WHERE username = ?", 's', $aUser)) {
                    jsonErr('That admin username is already taken on this database');
                }

                $months = (int) ($_POST['sub_months'] ?? 12);
                if ($months < 1 || $months > 60) jsonErr('Subscription length must be between 1 and 60 months');
                $starts = date('Y-m-d');
                $ends   = date('Y-m-d', strtotime("+$months month"));
                $amount = $p ? round($months >= 12 ? (float)$p['price_yearly'] * ($months / 12) : (float)$p['price_monthly'] * $months, 2) : 0.00;
                $curr   = ormsCurrency()['code'];

                $conn = getDBConnection();
                $conn->begin_transaction();
                $why = '';                               // only OUR diagnosis reaches the user; driver text stays in the log
                try {
                    // 1. the tenant row itself — everything below hangs off this id
                    // types: s name, s code, s email, s phone, s addr, s logo, s status, i plan, s trial, s notes
                    $why  = 'The tenant row could not be written.';
                    $newId = qInsert("INSERT INTO schools (name, code, email, phone, address, logo, status, plan_id, trial_ends_at, notes)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                     'sssssssiss', $name, $code, $email, $phone, $addr, $logo, $status, $plan, $trial, $notes);
                    if ($newId <= 0) throw new RuntimeException('school insert returned no id');

                    // 2. main branch — every student, teacher and class needs one to land in
                    // types: i school, s name, s code, s addr, s phone
                    $why = 'The main branch could not be created.';
                    $bId = qInsert("INSERT INTO branches (school_id, name, code, address, phone, is_main, status)
                                    VALUES (?, ?, ?, ?, ?, 1, 'Active')",
                                   'issss', $newId, $bName, $bCode, $addr, $phone);
                    if ($bId <= 0) throw new RuntimeException('branch insert returned no id');

                    // 3. clone the school-0 template matrix, MINUS Super Admin (the operator is platform-only).
                    //    without this the new school has no permission rows and nobody inside it can do anything —
                    //    so a zero-row clone is a hard failure, not a warning
                    $why = 'The role template at school 0 is empty — run update_setup.php once, then try again.';
                    $cloned = qExec("INSERT INTO roles (school_id, role_key, label, color, sort_order, is_super, hidden_signup, permissions)
                                     SELECT ?, role_key, label, color, sort_order, is_super, hidden_signup, permissions
                                     FROM roles WHERE school_id = 0 AND role_key <> 'Super Admin'", 'i', $newId);
                    if ($cloned < 1) throw new RuntimeException('role clone copied 0 rows');

                    // 4. the first user is the school OWNER — the operator hands over the tenant, including
                    //    its billing. must_change_password = 1: the operator knows this password, the school must not keep it
                    // types: s user, s name, s mail, s hash, i created_by, i school, i branch
                    $why = 'That admin username is already taken.';
                    $aId = qInsert("INSERT INTO users (username, full_name, email, password, role, is_active, must_change_password,
                                                       created_by, school_id, branch_id)
                                    VALUES (?, ?, ?, ?, 'School Owner', 1, 1, ?, ?, ?)",
                                   'ssssiii', $aUser, $aName, $aMail, password_hash($aPass, PASSWORD_DEFAULT), $user_id, $newId, $bId);
                    if ($aId <= 0) throw new RuntimeException('admin insert returned no id');

                    // 5. stamp the owner so "Enter school" has a target that survives new admins being added
                    $why = 'The owner stamp could not be written.';
                    qExec("UPDATE schools SET owner_user_id = ? WHERE id = ?", 'ii', $aId, $newId);

                    // 6. the billing runway. types: i school, i plan, s starts, s ends, d amount, s currency, s notes, i by
                    $why = 'The first subscription term could not be opened.';
                    $subNote = $months . '-month term opened with the school';
                    qInsert("INSERT INTO school_subscriptions (school_id, plan_id, starts_at, ends_at, status, amount, currency, notes, created_by)
                             VALUES (?, ?, ?, ?, 'Active', ?, ?, ?, ?)",
                            'iissdssi', $newId, $plan, $starts, $ends, $amount, $curr, $subNote, $user_id);

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('schools.php create: ' . $e->getMessage());
                    jsonErr('The school was not created — nothing was saved. ' . $why);
                }

                // logged AFTER the commit: a rolled-back attempt must not leave a "created" line in the audit trail
                logActivity($user_id, $username, 'School Created',
                    "Created school: $name [$code] (#$newId) — plan: $planName, status: $status, main branch: $bName [$bCode], " .
                    "admin: $aUser (#$aId), roles cloned: $cloned, subscription to $ends", 'school', $newId);

                jsonOk([
                    'message' => 'School created — the credentials below are shown once',
                    'cred' => ['school' => $name, 'code' => $code, 'branch' => $bName,
                               'username' => $aUser, 'password' => $aPass, 'email' => $aMail, 'ends_at' => $ends]
                ]);

            // ------------------------------------------------------------ status change (always with a reason)
            case 'setSchoolStatus':
                requireCsrfJson();
                requirePermJson('schools', 'e');

                $id     = (int) ($_POST['id'] ?? 0);
                $status = trim($_POST['status'] ?? '');
                $reason = trim($_POST['reason'] ?? '');

                if (!in_array($status, $SCH_STATUSES, true)) jsonErr('Pick one of Trial, Active, Suspended or Cancelled');
                if (mb_strlen($reason) < 5)   jsonErr('Please state why — the reason goes into the audit trail');
                if (mb_strlen($reason) > 255) jsonErr('Reason must be 255 characters or less');

                $row = $id ? qOne("SELECT name, code, status FROM schools WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('School not found');
                if ($row['status'] === $status) jsonErr($row['name'] . ' is already ' . $status);

                qExec("UPDATE schools SET status = ? WHERE id = ?", 'si', $status, $id);
                logActivity($user_id, $username, 'School Status Changed',
                    "{$row['name']} [{$row['code']}] (#$id): {$row['status']} -> $status — reason: $reason", 'school', $id);

                // suspended/cancelled is read straight off schools.status by the subscription gate,
                // so the school is locked out on its very next request — no second table to keep in step
                jsonOk(['message' => $row['name'] . ' is now ' . $status]);

            // ------------------------------------------------------------ hard purge (last resort, typed confirmation)
            case 'purgeSchool':
                requireCsrfJson();
                requirePermJson('schools', 'd');

                $id   = (int) ($_POST['id'] ?? 0);
                $typed = schCode($_POST['confirm_code'] ?? '');
                $row  = $id ? qOne("SELECT name, code, status FROM schools WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('School not found');
                if ($id === 1) jsonErr('School #1 is the original install and can never be purged — cancel it instead');
                if ($row['status'] !== 'Cancelled') jsonErr('Cancel "' . $row['name'] . '" first. A live school is never purged in one step.');
                if ($typed === '' || $typed !== schCode($row['code'])) jsonErr('The code you typed does not match ' . $row['code']);

                // child -> parent, in one transaction. anything that trips an fk rolls the whole thing back,
                // so a half-purged tenant can never exist. tables missing on this db are skipped, not guessed at.
                // cols: [table, sql, column that must exist on it — '' when the tenant fence comes from the join]
                $steps = [
                    ['mark_components',      "DELETE mc FROM mark_components mc JOIN students st ON st.id = mc.student_id WHERE st.school_id = ?", ''],
                    ['attendance_summary',   "DELETE FROM attendance_summary WHERE school_id = ?", 'school_id'],
                    ['marks',                "DELETE FROM marks WHERE school_id = ?", 'school_id'],
                    ['result_summaries',     "DELETE FROM result_summaries WHERE school_id = ?", 'school_id'],
                    ['result_publications',  "DELETE FROM result_publications WHERE school_id = ?", 'school_id'],
                    ['student_fees',         "DELETE FROM student_fees WHERE school_id = ?", 'school_id'],
                    ['teacher_subjects',     "DELETE ts FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id WHERE t.school_id = ?", ''],
                    ['class_subjects',       "DELETE cs FROM class_subjects cs JOIN classes c ON c.id = cs.class_id WHERE c.school_id = ?", ''],
                    ['sections',             "DELETE se FROM sections se JOIN classes c ON c.id = se.class_id WHERE c.school_id = ?", ''],
                    ['students',             "DELETE FROM students WHERE school_id = ?", 'school_id'],
                    ['teachers',             "DELETE FROM teachers WHERE school_id = ?", 'school_id'],
                    ['exam_terms',           "DELETE et FROM exam_terms et JOIN academic_years y ON y.id = et.academic_year_id WHERE y.school_id = ?", ''],
                    ['academic_years',       "DELETE FROM academic_years WHERE school_id = ?", 'school_id'],
                    ['classes',              "DELETE FROM classes WHERE school_id = ?", 'school_id'],
                    ['subjects',             "DELETE FROM subjects WHERE school_id = ?", 'school_id'],
                    ['grading_scheme',       "DELETE g FROM grading_scheme g JOIN grading_sets gs ON gs.id = g.set_id WHERE gs.school_id = ?", 'set_id'],
                    ['grading_sets',         "DELETE FROM grading_sets WHERE school_id = ?", 'school_id'],
                    ['assessment_schemes',   "DELETE FROM assessment_schemes WHERE school_id = ?", 'school_id'],
                    ['notifications',        "DELETE FROM notifications WHERE school_id = ?", 'school_id'],
                    ['activity_logs',        "DELETE FROM activity_logs WHERE school_id = ?", 'school_id'],
                    ['system_settings',      "DELETE FROM system_settings WHERE school_id = ?", 'school_id'],
                    ['roles',                "DELETE FROM roles WHERE school_id = ?", 'school_id'],
                    ['users',                "DELETE FROM users WHERE school_id = ?", 'school_id'],
                ];

                $conn = getDBConnection();
                $conn->begin_transaction();
                $wiped = 0;
                try {
                    foreach ($steps as [$t, $sql, $col]) {
                        if (!schTable($t)) continue;                          // feature never installed on this db
                        if ($col !== '' && !schCol($t, $col)) continue;       // pre-tenant column layout
                        $wiped += qExec($sql, 'i', $id);
                    }
                    // last: the parent. branches, subscriptions and payments go with it on cascade
                    qExec("DELETE FROM schools WHERE id = ?", 'i', $id);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('schools.php purge: ' . $e->getMessage());
                    jsonErr('Purge blocked — nothing was deleted. Something still references this school.');
                }

                logActivity($user_id, $username, 'School Purged',
                    "PURGED school: {$row['name']} [{$row['code']}] (#$id) — $wiped child row(s) removed, tenant row deleted", 'school', $id);
                jsonOk(['message' => $row['name'] . ' purged — ' . $wiped . ' row(s) removed']);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('schools.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

$ready = schReady();

// plan picker + the price labels the create form quotes back
$planList = [];
if ($ready && schTable('plans')) {
    try {
        $planList = qAll("SELECT id, name, price_monthly, price_yearly, max_students, max_teachers, max_branches
                          FROM plans WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    } catch (Throwable $e) {}
}
$curCode = ormsCurrency()['code'];
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
    <title>Schools - Result Management</title>

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
                    <h1><i class="fas fa-city"></i> Schools</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Schools</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">

                <?php if (!$ready): ?>
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>The platform console is not installed yet</h4>
                    <p>Run <b>update_setup.php</b> once to create the schools, branches, plans and subscription tables, then reload this page.</p>
                </div>
                <?php else: ?>

                <div class="section-header">
                    <h2><i class="fas fa-city"></i> Tenants</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" id="btnRefresh"><i class="fas fa-sync"></i> Refresh</button>
                        <?php if (can('schools', 'a')): ?>
                        <button class="btn btn-success" id="btnAddSchool"><i class="fas fa-plus"></i> Add School</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>A school is never deleted from here &mdash; <strong>Cancel</strong> locks it out on its next request while every record stays intact.
                          The purge in the status window is a last resort and asks for the school code in writing.</span>
                </div>

                <div class="stat-mini" id="schStats">
                    <div><i class="fas fa-city"></i> Schools <b id="statAll">0</b></div>
                    <div><i class="fas fa-circle-check"></i> Active <b id="statActive">0</b></div>
                    <div><i class="fas fa-hourglass-half"></i> Trial <b id="statTrial">0</b></div>
                    <div><i class="fas fa-pause-circle"></i> Suspended <b id="statSuspended">0</b></div>
                    <div><i class="fas fa-ban"></i> Cancelled <b id="statCancelled">0</b></div>
                </div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" id="btnClearFilters"><i class="fas fa-times-circle"></i> Clear</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Statuses</option>
                                <?php foreach ($SCH_STATUSES as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Plan</label>
                            <select id="filterPlan" class="filter-input">
                                <option value="">All Plans</option>
                                <?php foreach ($planList as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['name']); ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="schSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 7; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="schWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="schTable" class="display chip-table"></table>
                    </div>
                </div>

                <div id="schEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-city"></i>
                    <h4>No schools yet</h4>
                    <p><?php echo can('schools', 'a')
                        ? 'Use <strong>Add School</strong> above to onboard the first tenant — branch, admin login, role matrix and subscription are created with it.'
                        : 'No tenant has been onboarded on this platform yet.'; ?></p>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($ready): ?>
    <!-- School Modal -->
    <div class="modal-overlay" id="schoolModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="schoolModalTitle"><i class="fas fa-city"></i> Add School</h3>
                <button class="close-btn" id="btnCloseSchool"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="schoolForm">
                    <input type="hidden" id="schoolId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> School Name *</label>
                            <input type="text" id="schoolName" name="name" maxlength="150" required placeholder="e.g. Greenfield Public School">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> School Code *</label>
                            <input type="text" id="schoolCode" name="code" maxlength="20" required placeholder="e.g. GPS01">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Unique across the platform. Letters, digits, <code>-</code> and <code>_</code> only &mdash; stored uppercase.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> School Email</label>
                            <input type="email" id="schoolEmail" name="email" maxlength="100" placeholder="office@school.edu">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" id="schoolPhone" name="phone" maxlength="30" placeholder="+92 300 0000000">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-location-dot"></i> Address</label>
                            <input type="text" id="schoolAddress" name="address" maxlength="255" placeholder="Street, city">
                        </div>
                        <div class="form-group form-group-full">
                            <label><i class="fas fa-image"></i> School Logo</label>
                            <div class="logo-upload-row">
                                <img id="schoolLogoPreview" src="" alt="Logo preview" class="logo-preview-img initially-hidden">
                                <div class="logo-upload-controls">
                                    <div class="logo-upload-controls-inner">
                                        <label for="schoolLogoFile" class="btn btn-primary logo-upload-btn">
                                            <i class="fas fa-upload"></i> Choose Image
                                        </label>
                                        <input type="file" id="schoolLogoFile" accept="image/jpeg,image/png,image/webp" class="initially-hidden">
                                        <span id="schoolLogoName" class="logo-file-name">No logo</span>
                                        <button type="button" class="btn btn-light btn-sm initially-hidden" id="btnSchoolLogoClear"><i class="fas fa-trash"></i> Remove</button>
                                    </div>
                                    <p class="logo-help-text">JPG, PNG or WEBP, under 2MB. Printed on this school&rsquo;s result cards &mdash; leave it empty to use the platform logo.</p>
                                </div>
                            </div>
                            <input type="hidden" id="schoolLogo" name="logo">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Plan</label>
                            <select id="schoolPlan" name="plan_id">
                                <option value="">&mdash; no plan &mdash;</option>
                                <?php foreach ($planList as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"><?php
                                    echo htmlspecialchars($p['name'] . ' — ' . $curCode . ' ' . rtrim(rtrim(number_format((float)$p['price_monthly'], 2), '0'), '.') . '/mo');
                                ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hourglass-end"></i> Trial Ends</label>
                            <input type="date" id="schoolTrial" name="trial_ends_at">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Informational only &mdash; the lockout runs off the subscription end date.</div>
                        </div>
                        <div class="form-group tnt-new-only">
                            <label><i class="fas fa-toggle-on"></i> Opening Status *</label>
                            <select id="schoolStatus" name="status">
                                <option value="Trial">Trial</option>
                                <option value="Active">Active</option>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Suspend and cancel are audited actions taken later, never an opening state.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Notes</label>
                            <input type="text" id="schoolNotes" name="notes" maxlength="255" placeholder="Internal note">
                        </div>
                    </div>

                    <div class="tnt-new-only">
                        <div class="section-header">
                            <h2><i class="fas fa-code-branch"></i> Main Branch</h2>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Branch Name</label>
                                <input type="text" id="branchName" name="branch_name" maxlength="120" placeholder="Main Branch">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Branch Code</label>
                                <input type="text" id="branchCode" name="branch_code" maxlength="20" placeholder="MAIN">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Unique inside this school only &mdash; other schools may reuse it.</div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-user-shield"></i> First Admin</h2>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Username *</label>
                                <input type="text" id="adminUsername" name="admin_username" maxlength="50" placeholder="e.g. gps_admin">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-card"></i> Full Name</label>
                                <input type="text" id="adminFullname" name="admin_fullname" maxlength="100" placeholder="Head of school">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Admin Email *</label>
                                <input type="email" id="adminEmail" name="admin_email" maxlength="100" placeholder="admin@school.edu">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> Password</label>
                                <input type="text" id="adminPassword" name="admin_password" maxlength="100" placeholder="Leave empty to generate one">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Shown once on the slip after saving. The admin is forced to replace it at first login.</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-calendar-days"></i> Subscription Length</label>
                                <select id="subMonths" name="sub_months">
                                    <option value="1">1 month</option>
                                    <option value="3">3 months</option>
                                    <option value="6">6 months</option>
                                    <option value="12" selected>12 months</option>
                                    <option value="24">24 months</option>
                                </select>
                                <div class="help-text"><i class="fas fa-info-circle"></i> Opens the first subscription term on the chosen plan, starting today.</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveSchool"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelSchool"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Status Modal -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="statusModalTitle"><i class="fas fa-toggle-on"></i> Change Status</h3>
                <button class="close-btn" id="btnCloseStatus"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="statusForm">
                    <input type="hidden" id="statusId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> School</label>
                            <input type="text" id="statusSchool" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> New Status *</label>
                            <select id="statusValue" name="status">
                                <?php foreach ($SCH_STATUSES as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> <strong>Suspended</strong> and <strong>Cancelled</strong> lock every account of this school out on its next request. Data is untouched.</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-comment-dots"></i> Reason *</label>
                        <textarea id="statusReason" name="reason" rows="3" maxlength="255" placeholder="Why is this changing? Goes into the audit trail."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveStatus"><i class="fas fa-save"></i> Apply Status</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelStatus"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>

                <?php if (can('schools', 'd')): ?>
                <div class="tnt-danger" id="purgeBox">
                    <h4><i class="fas fa-triangle-exclamation"></i> Danger zone &mdash; permanent purge</h4>
                    <p>Erases every student, teacher, class, mark, result, log and login of <code id="purgeName"></code> and then the tenant row itself.
                       There is no undo and no backup taken. Only a <strong>Cancelled</strong> school can be purged.
                       Type <code id="purgeCode"></code> to unlock the button.</p>
                    <div class="form-group">
                        <label><i class="fas fa-keyboard"></i> Confirm School Code</label>
                        <input type="text" id="purgeInput" maxlength="20" placeholder="Type the school code">
                    </div>
                    <button type="button" class="btn btn-danger" id="btnPurge" disabled><i class="fas fa-trash"></i> Purge Permanently</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Credentials Modal -->
    <div class="modal-overlay" id="credModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> School Credentials</h3>
                <button class="close-btn" id="btnCloseCred"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-banner info-banner-warning mb-24">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>This password is shown <strong>once</strong>. Copy or print it now &mdash; it is stored hashed and cannot be read back.</span>
                </div>
                <div id="credBody"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="btnPrintCred"><i class="fas fa-print"></i> Print</button>
                    <button type="button" class="btn btn-secondary" id="btnDoneCred"><i class="fas fa-check"></i> Done</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <?php if ($ready): ?>
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
    var schTable_ = null, schData = [], TODAY = '';
    var CAN = { a: <?= can('schools', 'a') ? 'true' : 'false' ?>,
                e: <?= can('schools', 'e') ? 'true' : 'false' ?>,
                d: <?= can('schools', 'd') ? 'true' : 'false' ?> };

    $(document).ready(function() {
        ORMS.dropdown('#schoolPlan, #schoolStatus, #subMonths, #statusValue, #filterStatus, #filterPlan');
        loadSchools();

        $('#btnRefresh').on('click', function() { loadSchools(this); });
        $('#btnAddSchool').on('click', openAdd);
        $('#btnCloseSchool, #btnCancelSchool').on('click', function() { close_('#schoolModal'); });
        $('#btnCloseStatus, #btnCancelStatus').on('click', function() { close_('#statusModal'); });
        $('#btnCloseCred, #btnDoneCred').on('click', function() { close_('#credModal'); });
        $('#btnPrintCred').on('click', function() { window.print(); });
        $('#schoolModal, #statusModal, #credModal').on('click', function(e) { if (e.target === this) close_(this); });

        // code is stored uppercase — show it that way while typing so the purge match is obvious
        $('#schoolCode, #branchCode, #purgeInput').on('input', function() { this.value = this.value.toUpperCase(); });

        $('#filterStatus').on('change', function() { colSearch(COL.status, this.value); });
        $('#filterPlan').on('change', function() { colSearch(COL.plan, this.value); });
        $('#btnClearFilters').on('click', function() {
            $('#filterStatus, #filterPlan').val('');
            ORMS.dropdown.refresh('#filterStatus, #filterPlan');
            colSearch(COL.status, ''); colSearch(COL.plan, '');
        });

        // the purge button stays dead until the typed code matches, character for character
        $('#purgeInput').on('input', function() {
            $('#btnPurge').prop('disabled', $(this).val().trim() !== $('#purgeCode').text().trim());
        });
        $('#btnPurge').on('click', purgeSchool);
    });

    function close_(sel) { $(sel).removeClass('active'); }
    function blank(v) { return v === null || v === undefined || v === ''; }

    var COL = {};                    // column title -> index, rebuilt with the table

    function colSearch(idx, val) {
        if (!schTable_ || idx === undefined || idx < 0) return;   // column set is built, not counted
        var v = String(val || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');   // exact match, names may hold regex chars
        schTable_.column(idx).search(val ? '^' + v + '$' : '', true, false).draw();
    }

    function statusChip(s) {
        var m = { Trial: 'hourglass-half', Active: 'circle-check', Suspended: 'pause-circle', Cancelled: 'ban' };
        return '<span class="tnt-chip tnt-chip-' + String(s || '').toLowerCase() + '"><i class="fas fa-' + (m[s] || 'circle') + '"></i> ' + esc(s) + '</span>';
    }

    function countChip(icon, n) { return '<span class="subject-chip"><i class="fas fa-' + icon + '"></i> ' + (n || 0) + '</span>'; }

    // an end date already in the past is the single most important thing on this row — never a plain grey date
    function endsCell(row) {
        if (blank(row.ends_at)) return '<span class="tnt-sub-none"><i class="fas fa-minus"></i> never billed</span>';
        var lapsed = row.ends_at < TODAY || row.sub_status !== 'Active';
        return '<span class="' + (lapsed ? 'tnt-expired' : '') + '"><i class="fas fa-' + (lapsed ? 'circle-exclamation' : 'calendar-check') + '"></i> ' +
               esc(row.ends_at) + '</span>' + (lapsed ? '<span class="tnt-sub">lapsed</span>' : '');
    }

    function rowActions(r) {
        var b = '';
        if (r.enter_id > 0) {
            b += '<a class="action-icon loginas-icon" title="Enter school as its admin" href="impersonate.php?action=start&user_id=' + r.enter_id + '&csrf=' + encodeURIComponent(window.ORMS_CSRF||'') + '">' +
                 '<i class="fas fa-right-to-bracket"></i></a>';
        }
        if (CAN.e) {
            b += '<button class="action-icon edit-icon" title="Edit" onclick="editSchool(' + r.id + ')"><i class="fas fa-edit"></i></button>';
            b += '<button class="action-icon view-icon" title="Change status" onclick="openStatus(' + r.id + ')"><i class="fas fa-toggle-on"></i></button>';
        }
        if (CAN.e && r.status !== 'Cancelled') {
            b += '<button class="action-icon delete-icon" title="Cancel this school" onclick="cancelSchool(' + r.id + ', this)"><i class="fas fa-ban"></i></button>';
        }
        return b || '<span class="text-muted">&mdash;</span>';
    }

    function paintStats() {
        var n = { Trial: 0, Active: 0, Suspended: 0, Cancelled: 0 };
        schData.forEach(function(r) { if (n[r.status] !== undefined) n[r.status]++; });
        $('#statAll').text(schData.length);
        $('#statActive').text(n.Active);
        $('#statTrial').text(n.Trial);
        $('#statSuspended').text(n.Suspended);
        $('#statCancelled').text(n.Cancelled);
    }

    function loadSchools(btn) {
        ORMS.post('getSchools', {}, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load schools'); return; }
            schData = res.data || [];
            TODAY = res.today || '';
            $('#schSkeleton').addClass('initially-hidden');
            // nothing to show -> icon+message, never a blank table body
            $('#schWrap').toggleClass('initially-hidden', !schData.length);
            $('#schEmpty').toggleClass('initially-hidden', !!schData.length);
            paintStats();
            if (schTable_) { schTable_.destroy(); $('#schTable').empty(); }
            var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
            schTable_ = $('#schTable').DataTable({
                data: schData,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: false,          // the chips carry the density; .table-responsive scrolls
                destroy: true,
                order: [],                  // the server hands the list back by id already
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
                columns: buildSchColumns()
            });
            colSearch(COL.status, $('#filterStatus').val());
            colSearch(COL.plan, $('#filterPlan').val());
        }).fail(function(msg) { ORMS.err(msg); });
    }

    // ---- chip cells: 9 flat columns -> 4 grouped ones (+2 hidden, for the exact-value filters) ----
    var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box, DASH = ORMS.DASH;
    function cv(x) { return blank(x) ? DASH : esc(x); }

    function cellSchool(r) {
        return K([
            '<div class="cell-title"><i class="fas fa-city"></i> ' + esc(r.name) + '</div>',
            blank(r.address) ? '' : '<div class="cell-sub">' + esc(r.address) + '</div>',
            R(C('chip-navy', 'fa-hashtag', 'Code'), BOX(r.code)),
            R(C('chip-soft-navy', 'fa-envelope', 'Email'), cv(r.email)),
            R(C('chip-soft-navy', 'fa-phone', 'Phone'), cv(r.phone))
        ]);
    }

    function cellSub(r) {
        return K([
            R(C('chip-soft-green', 'fa-toggle-on', 'Status'), statusChip(r.status)),
            R(C('chip-soft-purple', 'fa-layer-group', 'Plan'), cv(r.plan_name)),
            R(C('chip-soft-tan', 'fa-calendar-check', 'Ends'), endsCell(r)),
            blank(r.trial_ends_at) ? '' : R(C('chip-soft-amber', 'fa-hourglass-half', 'Trial ends'), esc(String(r.trial_ends_at).slice(0, 10)))
        ]);
    }

    function cellSize(r) {
        return K([
            R(C('chip-link', 'fa-code-branch', 'Branches'), String(Number(r.branches) || 0)),
            R(C('chip-link', 'fa-user-graduate', 'Students'), String(Number(r.students) || 0)),
            R(C('chip-link', 'fa-chalkboard-user', 'Teachers'), String(Number(r.teachers) || 0)),
            R(C('chip-link', 'fa-users', 'Logins'), String(Number(r.users) || 0))
        ]);
    }

    // one searchable blob per row, so the search box still reaches a value now inside markup
    function schBlob(r) {
        return [r.name, r.code, r.email, r.phone, r.address, r.status, r.plan_name, r.notes].filter(Boolean).join(' ');
    }

    // chip html for display, the real value for sort, a text blob for filter
    function schCol(title, build, sortField, cls) {
        return { data: null, title: title, className: cls || '', render: function (d, t, r) {
            if (t === 'display') return build(r);
            if (t === 'filter')  return schBlob(r);
            var v = r[sortField];
            return v === null || v === undefined ? '' : v;
        } };
    }

    function buildSchColumns() {
        var c = [
            schCol('School', cellSchool, 'name'),
            schCol('Subscription', cellSub, 'ends_at'),
            schCol('Size', cellSize, 'students'),
            { data: null, title: 'Actions', orderable: false, className: 'col-actions',
              render: function (d, t, r) { return t === 'display' ? '<div class="actions-cell">' + rowActions(r) + '</div>' : ''; } },
            // hidden, and the only reason they exist: the Status and Plan dropdowns filter on an
            // exact value, which a grouped cell can no longer offer
            { data: 'status', title: 'Status', visible: false },
            { data: 'plan_name', title: 'Plan', visible: false, render: function (d) { return blank(d) ? '' : d; } }
        ];
        COL = {};
        c.forEach(function (x, i) { COL[String(x.title).toLowerCase()] = i; });
        return c;
    }

    function rowById(id) { return schData.filter(function(x) { return x.id == id; })[0]; }

    // ---------------------------------------------------------------- add / edit
    // ---- school logo: uploaded, not typed ----
    // The hidden #schoolLogo still carries the path, so saveSchool and editSchool are unchanged —
    // only where the path COMES FROM moved from the operator's keyboard to a real upload.
    function setSchoolLogo(path) {
        var p = blank(path) ? '' : String(path);
        $('#schoolLogo').val(p);
        $('#schoolLogoPreview').attr('src', p || '').toggleClass('initially-hidden', p === '');
        $('#schoolLogoName').text(p === '' ? 'No logo' : p.split('/').pop());
        $('#btnSchoolLogoClear').toggleClass('initially-hidden', p === '');
    }

    $('#schoolLogoFile').on('change', function () {
        var f = this.files && this.files[0];
        if (!f) return;
        var fd = new FormData();
        fd.append('logo_file', f);
        fd.append('old', $('#schoolLogo').val() || '');
        fd.append('id', $('#schoolId').val() || 0);
        ORMS.post('uploadSchoolLogo', fd, { verb: 'Uploading…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message); return; }
            setSchoolLogo(res.logo);
            ORMS.ok(res.message);
        }).fail(function (m) { ORMS.err(m); });
        this.value = '';                                 // picking the same file again still fires change
    });

    // clears the pointer only — the file itself goes when it is replaced, or with the school
    $('#btnSchoolLogoClear').on('click', function () { setSchoolLogo(''); });

    function openAdd() {
        $('#schoolModalTitle').html('<i class="fas fa-city"></i> Add School');
        $('#schoolForm')[0].reset();
        $('#schoolForm').removeClass('tnt-edit');       // create-only blocks come back
        $('#schoolId').val('');
        setSchoolLogo('');
        $('#schoolPlan').val($('#schoolPlan option').eq(1).val() || '');
        $('#schoolStatus').val('Trial');
        $('#subMonths').val('12');
        ORMS.dropdown.refresh('#schoolPlan, #schoolStatus, #subMonths');
        $('#schoolModal').addClass('active');
        setTimeout(function() { $('#schoolName').trigger('focus'); }, 60);
    }

    function editSchool(id) {
        var r = rowById(id);
        if (!r) return;
        $('#schoolModalTitle').html('<i class="fas fa-edit"></i> Edit School');
        $('#schoolForm')[0].reset();
        $('#schoolForm').addClass('tnt-edit');          // branch/admin/subscription belong to creation only
        $('#schoolId').val(r.id);
        $('#schoolName').val(r.name);
        $('#schoolCode').val(r.code);
        $('#schoolEmail').val(blank(r.email) ? '' : r.email);
        $('#schoolPhone').val(blank(r.phone) ? '' : r.phone);
        $('#schoolAddress').val(blank(r.address) ? '' : r.address);
        setSchoolLogo(r.logo);
        $('#schoolNotes').val(blank(r.notes) ? '' : r.notes);
        $('#schoolTrial').val(blank(r.trial_ends_at) ? '' : r.trial_ends_at);
        $('#schoolPlan').val(blank(r.plan_id) ? '' : String(r.plan_id));
        ORMS.dropdown.refresh('#schoolPlan');
        $('#schoolModal').addClass('active');
    }

    $('#schoolForm').on('submit', function(e) {
        e.preventDefault();
        var id = $('#schoolId').val();
        var name = $('#schoolName').val().trim(), code = $('#schoolCode').val().trim();
        if (!name) { ORMS.err('School name is required'); return; }
        if (code.length < 2) { ORMS.err('School code must be at least 2 characters'); return; }

        var data = {
            id: id, name: name, code: code,
            email: $('#schoolEmail').val().trim(), phone: $('#schoolPhone').val().trim(),
            address: $('#schoolAddress').val().trim(), logo: $('#schoolLogo').val().trim(),
            notes: $('#schoolNotes').val().trim(), plan_id: $('#schoolPlan').val() || '',
            trial_ends_at: $('#schoolTrial').val() || ''
        };
        if (!id) {
            var au = $('#adminUsername').val().trim(), am = $('#adminEmail').val().trim();
            if (!au) { ORMS.err('The first admin needs a username'); return; }
            if (!am) { ORMS.err('The first admin needs an email address'); return; }
            data.status = $('#schoolStatus').val() || 'Trial';
            data.branch_name = $('#branchName').val().trim();
            data.branch_code = $('#branchCode').val().trim();
            data.admin_username = au;
            data.admin_fullname = $('#adminFullname').val().trim();
            data.admin_email = am;
            data.admin_password = $('#adminPassword').val();
            data.sub_months = $('#subMonths').val() || '12';
        }

        ORMS.post('saveSchool', data, { btn: '#btnSaveSchool', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            close_('#schoolModal');
            loadSchools();
            if (res.cred) showCred(res.cred); else ORMS.ok(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    });

    // ---------------------------------------------------------------- credentials, once
    function showCred(c) {
        $('#credBody').html(
            '<div class="cred-slip">' +
            '<div class="cred-slip-head"><i class="fas fa-city"></i> ' + esc(c.school || '') + '</div>' +
            '<div class="cred-row"><span class="cred-label">School Code</span><span class="cred-value">' + esc(c.code || '') + '</span></div>' +
            '<div class="cred-row"><span class="cred-label">Main Branch</span><span class="cred-value">' + esc(c.branch || '') + '</span></div>' +
            '<div class="cred-row"><span class="cred-label">Admin Username</span><span class="cred-value">' + esc(c.username || '') + '</span></div>' +
            '<div class="cred-row"><span class="cred-label">Password</span><span class="cred-value">' + esc(c.password || '') + '</span></div>' +
            '<div class="cred-row"><span class="cred-label">Admin Email</span><span class="cred-value">' + esc(c.email || '') + '</span></div>' +
            '<div class="cred-row"><span class="cred-label">Subscription Ends</span><span class="cred-value">' + esc(c.ends_at || '') + '</span></div>' +
            '<div class="cred-row"><span class="cred-label">Note</span><span class="cred-value">Password change is forced at first login</span></div>' +
            '</div>');
        $('#credModal').addClass('active');
        ORMS.ok('School created');
    }

    // ---------------------------------------------------------------- status + purge
    function openStatus(id, preset) {
        var r = rowById(id);
        if (!r) return;
        $('#statusId').val(r.id);
        $('#statusSchool').val(r.name + ' [' + r.code + ']');
        $('#statusValue').val(preset || r.status);
        ORMS.dropdown.refresh('#statusValue');
        $('#statusReason').val('');
        $('#purgeName').text(r.name);
        $('#purgeCode').text(r.code);
        $('#purgeInput').val('');
        $('#btnPurge').prop('disabled', true);
        $('#purgeBox').toggleClass('initially-hidden', r.status !== 'Cancelled' || r.id === 1);
        $('#statusModal').addClass('active');
    }

    // the quick "Cancel" on the row is the same audited action — it just asks for the reason inline
    function cancelSchool(id) {
        var r = rowById(id);
        if (!r) return;
        openStatus(id, 'Cancelled');
        setTimeout(function() { $('#statusReason').trigger('focus'); }, 80);
    }

    $('#statusForm').on('submit', function(e) {
        e.preventDefault();
        var reason = $('#statusReason').val().trim();
        if (reason.length < 5) { ORMS.err('Please state why — the reason goes into the audit trail'); return; }
        ORMS.post('setSchoolStatus', { id: $('#statusId').val(), status: $('#statusValue').val(), reason: reason },
                  { btn: '#btnSaveStatus', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            close_('#statusModal');
            ORMS.ok(res.message);
            loadSchools();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    function purgeSchool() {
        var id = $('#statusId').val(), code = $('#purgeInput').val().trim(), r = rowById(id);
        if (!r || !code) return;
        ORMS.confirmDelete('Purge "' + r.name + '" and every record inside it? There is no undo and no backup.', 'Permanent purge')
            .then(function(yes) {
                if (!yes) return;
                ORMS.post('purgeSchool', { id: id, confirm_code: code }, { btn: '#btnPurge', busyLabel: 'Purging…' }).done(function(res) {
                    if (!res.success) { ORMS.err(res.message, 'Cannot Purge'); return; }
                    close_('#statusModal');
                    ORMS.ok(res.message);
                    loadSchools();
                }).fail(function(msg) { ORMS.err(msg); });
            });
    }
    </script>
    <?php endif; ?>
</body>
</html>
