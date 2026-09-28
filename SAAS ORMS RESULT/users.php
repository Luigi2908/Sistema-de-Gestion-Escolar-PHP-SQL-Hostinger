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
requirePerm('users', 'v');

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'users';
$isPlat  = ormsIsPlatform();   // operator manages every school's logins; a school manages its own
$school  = sid();
$branch  = ormsBranchLock($role);   // branch admin creates inside its own branch only

// users.school_id there yet? one probe per request — a pre-migration install keeps the flat list
function usersHasTenant(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT school_id FROM users LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// tenant leg for every users query. '' = operator, or a db that hasn't migrated yet
function usersScope(bool $isPlat): string {
    return ($isPlat || !usersHasTenant()) ? '' : ' AND school_id = ?';
}

// column probe — profile/tenant columns arrive across several migration steps, and the 360 view
// must render on a db that has only some of them. one probe per column per request.
function usersCol(string $c): bool {
    static $seen = [];
    if (!isset($seen[$c])) {
        try { qVal("SELECT `$c` FROM users LIMIT 1"); $seen[$c] = true; }
        catch (Throwable $e) { $seen[$c] = false; }
    }
    return $seen[$c];
}

// id must resolve INSIDE the caller's school before any update/delete touches it.
// ormsOwns() already whitelists 'users' -> SELECT id FROM users WHERE id = ? AND school_id = ?
function usersReach($rawId, bool $isPlat): int {
    $id = (int)$rawId;
    if ($id <= 0) return 0;
    if ($isPlat || !usersHasTenant()) return $id;
    return ormsOwns('users', $id);
}

// deleting a login cascades to its students/teachers profile row, and marks RESTRICT that.
// count first and hand back the same wording those pages use — '' = safe to delete
function usersDeleteBlockReason($uid, $uname) {
    $uid = (int)$uid;
    try {
        $stu = qOne("SELECT id FROM students WHERE user_id = ?", 'i', $uid);
        if ($stu) {
            $n = (int)qVal("SELECT COUNT(*) FROM marks WHERE student_id = ?", 'i', (int)$stu['id']);
            if ($n) return "Cannot delete $uname — $n marks record(s) belong to this student. Open Students and set the status to Inactive, Passed Out or Transferred instead so the result history stays intact.";
        }

        $tch = qOne("SELECT id FROM teachers WHERE user_id = ?", 'i', $uid);
        if ($tch) {
            $entered = (int)qVal("SELECT COUNT(*) FROM marks WHERE entered_by = ? OR updated_by = ?", 'ii', $uid, $uid);
            $held = (int)qVal(
                "SELECT COUNT(*) FROM marks m
                 JOIN teacher_subjects ts
                   ON ts.section_id = m.section_id AND ts.subject_id = m.subject_id AND ts.academic_year_id = m.academic_year_id
                 WHERE ts.teacher_id = ?", 'i', (int)$tch['id']);
            if ($entered || $held) {
                $bits = [];
                if ($entered) $bits[] = "$entered marks entry(s) recorded by them";
                if ($held)    $bits[] = "$held marks row(s) under subjects they are assigned to";
                return "Cannot delete $uname — " . implode(' and ', $bits) .
                       '. Open Teachers and set their status to Inactive instead so the marks history stays intact.';
            }
        }
    } catch (Throwable $e) {
        return ''; // academic tables not installed yet — nothing to protect
    }
    return '';
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getUsers':
                // unscoped this handed the WHOLE platform's login table out over ajax
                $scope = usersScope($isPlat);
                // profile + tenant columns are optional on an un-migrated db — NULL keeps the row shape
                $sel = "u.id, u.username, u.email, u.role, u.is_active, u.created_at, u.last_login_at, u.must_change_password";
                foreach (['full_name', 'phone', 'profile_image', 'school_id', 'branch_id'] as $c)
                    $sel .= usersCol($c) ? ", u.`$c`" : ", NULL AS `$c`";
                $joins = '';
                if (usersCol('school_id')) { $sel .= ", sc.name AS school_name, sc.code AS school_code"; $joins .= " LEFT JOIN schools sc ON sc.id = u.school_id"; }
                else                       { $sel .= ", NULL AS school_name, NULL AS school_code"; }
                if (usersCol('branch_id')) { $sel .= ", br.name AS branch_name"; $joins .= " LEFT JOIN branches br ON br.id = u.branch_id"; }
                else                       { $sel .= ", NULL AS branch_name"; }

                $rows = qAll("SELECT $sel FROM users u$joins WHERE 1 = 1" . ($scope ? " AND u.school_id = ?" : '') .
                             " ORDER BY u.id DESC", $scope ? 'i' : '', ...($scope ? [$school] : []));

                $users = array_map(function ($row) {
                    $ts = strtotime($row['created_at']);
                    $ll = !empty($row['last_login_at']) ? strtotime($row['last_login_at']) : 0;   // null = never signed in
                    return [
                        'id' => $row['id'],
                        'username' => $row['username'],
                        'full_name' => $row['full_name'],
                        'phone' => $row['phone'],
                        'photo' => $row['profile_image'],
                        'email' => $row['email'],
                        'role' => $row['role'],
                        // the ladder travels with the row so the buttons can hide what the server would refuse
                        'rank' => ormsRoleRank((string) $row['role']),
                        'school_id' => (int) $row['school_id'],
                        'school_name' => $row['school_name'],
                        'school_code' => $row['school_code'],
                        'branch_id' => (int) $row['branch_id'],
                        'branch_name' => $row['branch_name'],
                        'is_active' => (int)$row['is_active'],
                        'must_change_password' => (int)$row['must_change_password'],
                        'created_at' => date('M d, Y', $ts),
                        'created_raw' => date('c', $ts),         // iso — the table sorts/filters on this, not the label
                        'last_login' => $ll ? date('M d, Y H:i', $ll) : 'Never',
                        'last_login_raw' => $ll ? date('c', $ll) : ''
                    ];
                }, $rows);

                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'addUser':
                requireCsrfJson();
                requirePermJson('users', 'a');

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $newRole = isset($_POST['role']) ? trim($_POST['role']) : '';
                // profile: an account with no real name is a row nobody can identify three months later
                $fullName = trim($_POST['full_name'] ?? '');
                $phone    = trim($_POST['phone'] ?? '');

                if (empty($newUsername) || empty($email) || empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (mb_strlen($fullName) > 100) { echo json_encode(['success' => false, 'message' => 'Full name must be 100 characters or less']); exit(); }
                if (mb_strlen($phone) > 20)     { echo json_encode(['success' => false, 'message' => 'Phone must be 20 characters or less']); exit(); }

                // role must exist in roles table — and readRoles() also returns the school-0
                // template set, so the PLATFORM role has to be refused or a school could mint an operator.
                // the test is the operator key, not is_super: 'Admin' carries that flag too, and keying on
                // it meant no tenant — not even the Owner — could ever appoint an Admin.
                $rk = roleByKey($newRole);
                if (!$rk || (!$isPlat && in_array($newRole, ormsPlatformRoles(), true))) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
                    exit();
                }

                // chain of authority: nobody hands out a role stronger than their own. without this an
                // Admin could mint an Owner login it knows the password of and walk straight into billing.
                if (!ormsCanAssignRole($newRole)) {
                    echo json_encode(['success' => false, 'message' => ormsRoleLabel($newRole) . ' is above ' .
                        ormsRoleLabel((string)$role) . ' in the chain of authority — that role can only be granted by ' .
                        ormsRoleLabel($newRole) . ' or the App Owner.']);
                    exit();
                }

                // ---- school allocation. the operator says which school the login belongs to; a school
                // admin can only ever create inside its own, whatever school_id the request carried.
                $newSchool = $school;
                if ($isPlat && usersHasTenant()) {
                    $newSchool = (int) ($_POST['school_id'] ?? 0);
                    if ($newSchool <= 0) { echo json_encode(['success' => false, 'message' => 'Pick the school this login belongs to']); exit(); }
                    if (!qVal("SELECT id FROM schools WHERE id = ?", 'i', $newSchool)) {
                        echo json_encode(['success' => false, 'message' => 'School not found']);
                        exit();
                    }
                }

                // EXACTLY one operator account. A second is how a platform ends up with two people
                // who can each lock the other out and nothing above them to settle it.
                if ($newRole === 'Super Admin' && ormsAppOwnerId() > 0) {
                    echo json_encode(['success' => false, 'message' => 'An App Owner already exists - the platform has exactly one.']);
                    exit();
                }
                // one Owner per school. Handing the school over is a transfer, not a second owner.
                if ($newRole === 'School Owner' && ormsSchoolOwnerId($newSchool) > 0) {
                    echo json_encode(['success' => false, 'message' => 'This school already has an Owner. Transfer ownership instead of adding a second.']);
                    exit();
                }

                // uniqueness is per school now (UNIQUE(school_id, username)) — a global check would
                // stop school B reusing a name school A already took, and the "already exists"
                // reply would confirm accounts in a school the caller cannot see
                $scope = usersScope($isPlat);
                $taken = (int) qVal("SELECT COUNT(*) FROM users WHERE username = ?" . ($scope || $isPlat ? ' AND school_id = ?' : ''),
                                    ($scope || $isPlat) ? 'si' : 's',
                                    ...(($scope || $isPlat) ? [$newUsername, $newSchool] : [$newUsername]));
                if ($taken) {
                    echo json_encode(['success' => false, 'message' => 'Username already exists']);
                    exit();
                }

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $isActive = empty($_POST['is_active']) ? 0 : 1;              // toggle ships value=1 only when on
                $mustChange = empty($_POST['must_change_password']) ? 0 : 1; // admin-set password -> make them replace it
                $createdBy = (int)$user_id;
                // branch allocation: a branch admin's new logins land in its own branch and nothing else
                // is honoured; otherwise the posted branch is accepted only if it belongs to the target
                // school, and the picker's current branch is the fallback. null, never 0 — 0 is not a branch.
                $newBranch = $branch ?: (int) ($_POST['branch_id'] ?? 0);
                if ($newBranch && !qVal("SELECT id FROM branches WHERE id = ? AND school_id = ?", 'ii', $newBranch, $newSchool)) {
                    echo json_encode(['success' => false, 'message' => 'That branch does not belong to the selected school']);
                    exit();
                }
                if (!$newBranch && $newSchool === $school) $newBranch = (int) bid();
                $newBranch = $newBranch ?: null;
                if ($fullName === '') $fullName = $newUsername;      // never store an empty display name
                $newUserId = 0;
                try {
                    // 8 old cols + phone + school_id + branch_id
                    $newUserId = qInsert("INSERT INTO users (username, full_name, email, phone, password, role, is_active, must_change_password, created_by, school_id, branch_id)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                         'ssssssiiiii', $newUsername, $fullName, $email, ($phone === '' ? null : $phone), $hashedPassword, $newRole,
                                         $isActive, $mustChange, $createdBy, $newSchool, $newBranch);
                } catch (Throwable $e) {   // pre-migration schema — no tenant columns yet
                    try {
                        $newUserId = qInsert("INSERT INTO users (username, full_name, email, password, role, is_active, must_change_password, created_by)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                             'sssssiii', $newUsername, $fullName, $email, $hashedPassword, $newRole,
                                             $isActive, $mustChange, $createdBy);
                    } catch (Throwable $e2) {
                        error_log('users.php addUser: ' . $e2->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Failed to add user']);
                        exit();
                    }
                }

                if ($newUserId > 0) {
                    // Log activity
                    logActivity($user_id, $username, 'User Created', "Created user: $newUsername ($newRole)" . ($mustChange ? ' — must change password' : ''), 'user', $newUserId);

                    // Notify admins — same school only, the helper defaults to the caller's tenant
                    try { createNotificationForAdmins('User Created', 'Admin "' . htmlspecialchars($username) . '" created user "' . htmlspecialchars($newUsername) . '" (' . $newRole . ').', 'info', 'users.php'); } catch (Exception $e) {}

                    echo json_encode(['success' => true, 'message' => 'User added successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to add user']);
                }
                exit();

            case 'updateUser':
                requireCsrfJson();
                requirePermJson('users', 'e');

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                // id is resolved against THIS school before anything is written — an id from
                // another school comes back 0 and reads as "not found", never as an edit
                $userId = usersReach($_POST['id'] ?? 0, $isPlat);
                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $newRole = isset($_POST['role']) ? trim($_POST['role']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $fullName = trim($_POST['full_name'] ?? '');
                $phone    = trim($_POST['phone'] ?? '');

                if ($userId <= 0 || empty($newUsername) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid input']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (mb_strlen($fullName) > 100) { echo json_encode(['success' => false, 'message' => 'Full name must be 100 characters or less']); exit(); }
                if (mb_strlen($phone) > 20)     { echo json_encode(['success' => false, 'message' => 'Phone must be 20 characters or less']); exit(); }

                // chain of authority, both directions: never touch an account above you, never hand out
                // a role above you. Without the first half an Admin could simply reset the Owner's password.
                if (($why = ormsOutranks($userId)) !== '') { echo json_encode(['success' => false, 'message' => $why]); exit(); }
                if (!ormsCanAssignRole($newRole)) {
                    echo json_encode(['success' => false, 'message' => ormsRoleLabel($newRole) . ' is above ' .
                        ormsRoleLabel((string)$role) . ' in the chain of authority — that role can only be granted by ' .
                        ormsRoleLabel($newRole) . ' or the App Owner.']);
                    exit();
                }

                // role must exist in roles table — and readRoles() also returns the school-0
                // template set, so the PLATFORM role has to be refused or a school could mint an operator.
                // the test is the operator key, not is_super: 'Admin' carries that flag too, and keying on
                // it meant no tenant — not even the Owner — could ever appoint an Admin.
                $rk = roleByKey($newRole);
                if (!$rk || (!$isPlat && in_array($newRole, ormsPlatformRoles(), true))) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
                    exit();
                }

                // the two accounts that must survive a careless edit
                $prot = ormsProtectedAccount($userId);
                if ($prot === 'app_owner' && ($newRole !== 'Super Admin' || empty($_POST['is_active']))) {
                    echo json_encode(['success' => false, 'message' => 'The App Owner cannot be demoted or deactivated - nothing else can grant the role back.']);
                    exit();
                }
                // Ownership moves by TRANSFER: the operator may reassign it, and the owner may hand the
                // school over themselves. Refusing both would be a dead end on an install that has no
                // operator account yet — nothing left could ever undo the change.
                if ($prot === 'school_owner' && $newRole !== 'School Owner'
                    && !$isPlat && $userId !== (int)$_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'This is the school Owner. Only the owner themselves or the platform operator can move that role.']);
                    exit();
                }

                // inactive = cannot log in. this is how a student deactivated on students.php gets reactivated
                $isActive = empty($_POST['is_active']) ? 0 : 1;
                if ($userId === (int)$_SESSION['user_id']) $isActive = 1;   // never lock yourself out
                $mustChange = empty($_POST['must_change_password']) ? 0 : 1;

                // old row for change detection (role + active state)
                $old       = qOne("SELECT role, is_active FROM users WHERE id = ?", 'i', $userId) ?: [];
                $old_role  = $old['role'] ?? '';
                $oldActive = (int)($old['is_active'] ?? 1);

                // rename collides only inside the same school — the unique is (school_id, username)
                $scope = usersScope($isPlat);
                $taken = (int) qVal("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?" . $scope,
                                    $scope ? 'sii' : 'si',
                                    ...($scope ? [$newUsername, $userId, $school] : [$newUsername, $userId]));
                if ($taken) {
                    echo json_encode(['success' => false, 'message' => 'Username already exists']);
                    exit();
                }

                // ---- allocation. the operator may re-home an account that owns no academic profile
                // row; a student/teacher row carries its OWN school_id and moving the login alone would
                // orphan it. Everyone else keeps their school and can only change the branch.
                $curSchool = (int) (qVal("SELECT school_id FROM users WHERE id = ?", 'i', $userId) ?? 0);
                $newSchool = $curSchool;
                if ($isPlat && usersHasTenant() && (int) ($_POST['school_id'] ?? 0) > 0 && (int) $_POST['school_id'] !== $curSchool) {
                    $want = (int) $_POST['school_id'];
                    if (!qVal("SELECT id FROM schools WHERE id = ?", 'i', $want)) {
                        echo json_encode(['success' => false, 'message' => 'School not found']);
                        exit();
                    }
                    if (ormsProtectedAccount($userId, $curSchool) !== '') {
                        echo json_encode(['success' => false, 'message' => 'An owner account cannot be moved between schools. Transfer ownership first, then move the login.']);
                        exit();
                    }
                    $bound = 0;
                    try {
                        $bound = (int) qVal("SELECT COUNT(*) FROM students WHERE user_id = ?", 'i', $userId)
                               + (int) qVal("SELECT COUNT(*) FROM teachers WHERE user_id = ?", 'i', $userId);
                    } catch (Throwable $e) { $bound = 0; }   // academic tables not installed — nothing to strand
                    if ($bound) {
                        echo json_encode(['success' => false, 'message' => 'This login owns a student or teacher profile in its current school and cannot be moved. Delete that profile first, or create a fresh login in the other school.']);
                        exit();
                    }
                    $newSchool = $want;
                }

                // branch must live inside whichever school the account ends up in. only touched when the
                // form actually carried one — a blank post must never silently un-home an account.
                $touchBranch = usersCol('branch_id') && (isset($_POST['branch_id']) || $branch || $newSchool !== $curSchool);
                $newBranch   = $branch ?: (int) ($_POST['branch_id'] ?? 0);
                // moved schools with no branch named -> land on the new school's main one. NULL would
                // make the account invisible to every branch-fenced count until the next migration sweep.
                if (!$newBranch && $newSchool !== $curSchool) {
                    try { $newBranch = (int) qVal("SELECT id FROM branches WHERE school_id = ? AND is_main = 1 LIMIT 1", 'i', $newSchool); }
                    catch (Throwable $e) { $newBranch = 0; }
                }
                if ($touchBranch && $newBranch && !qVal("SELECT id FROM branches WHERE id = ? AND school_id = ?", 'ii', $newBranch, $newSchool)) {
                    echo json_encode(['success' => false, 'message' => 'That branch does not belong to the selected school']);
                    exit();
                }
                $newBranch = $newBranch ?: null;
                if ($fullName === '') $fullName = $newUsername;

                // the tenant leg rides on the write too — belt and braces behind usersReach().
                // one statement built from whatever this db actually has, instead of two near-copies
                $ok = false;
                try {
                    $set  = 'username = ?, email = ?, role = ?, is_active = ?, must_change_password = ?';
                    $ty   = 'sssii';
                    $vals = [$newUsername, $email, $newRole, $isActive, $mustChange];
                    if (usersCol('full_name'))     { $set .= ', full_name = ?'; $ty .= 's'; $vals[] = $fullName; }
                    if (usersCol('phone'))         { $set .= ', phone = ?';     $ty .= 's'; $vals[] = $phone === '' ? null : $phone; }
                    if ($touchBranch)              { $set .= ', branch_id = ?'; $ty .= 'i'; $vals[] = $newBranch; }
                    if ($newSchool !== $curSchool) { $set .= ', school_id = ?'; $ty .= 'i'; $vals[] = $newSchool; }
                    if (!empty($password)) {
                        $set .= ', password = ?, password_changed_at = NOW()';
                        $ty  .= 's';
                        $vals[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $ty .= 'i'; $vals[] = $userId;
                    if ($scope) { $ty .= 'i'; $vals[] = $school; }
                    qExec("UPDATE users SET $set WHERE id = ?" . $scope, $ty, ...$vals);
                    $ok = true;
                } catch (Throwable $e) {
                    error_log('users.php updateUser: ' . $e->getMessage());
                }
                if ($ok) {
                    // the owner stamp follows the role. Without this, billing.php, the renewal notices
                    // and every ormsSchoolOwnerId() call would still point at the previous holder.
                    // "role === School Owner" is in the condition on purpose: saving the owner unchanged
                    // must still collapse a school that somehow ended up with two of them.
                    if ($old_role !== $newRole || $newRole === 'School Owner') {
                        try {
                            $ownerSchool = (int) (qVal("SELECT school_id FROM users WHERE id = ?", 'i', $userId) ?? 0);
                            if ($ownerSchool > 0 && $newRole === 'School Owner') {
                                // ONE owner per school, so granting the role is a TRANSFER: whoever holds it
                                // steps down to Admin in the same move. Without this a school ends up with two
                                // owners and ormsSchoolOwnerId() simply picks whichever id happens to be lower.
                                $prev = qAll("SELECT id, username FROM users WHERE school_id = ? AND role = 'School Owner' AND id <> ?", 'ii', $ownerSchool, $userId);
                                if ($prev) {
                                    qExec("UPDATE users SET role = 'Admin' WHERE school_id = ? AND role = 'School Owner' AND id <> ?", 'ii', $ownerSchool, $userId);
                                    foreach ($prev as $pv) {
                                        logActivity($user_id, $username, 'Ownership Transferred',
                                            "Ownership moved to $newUsername (#$userId) — {$pv['username']} (#{$pv['id']}) stepped down to Admin", 'user', (int)$pv['id']);
                                        try { createNotification((int)$pv['id'], 'Ownership Transferred', 'You are no longer the owner of this school — your role is now Admin.', 'warning', 'account.php'); } catch (Exception $e) {}
                                        // the outgoing owner is usually the caller — keep their live session honest
                                        if ((int)$pv['id'] === (int)$_SESSION['user_id']) $_SESSION['role'] = 'Admin';
                                    }
                                }
                                qExec("UPDATE schools SET owner_user_id = ? WHERE id = ?", 'ii', $userId, $ownerSchool);
                            } elseif ($ownerSchool > 0 && $old_role === 'School Owner') {
                                qExec("UPDATE schools SET owner_user_id = NULL WHERE id = ? AND owner_user_id = ?", 'ii', $ownerSchool, $userId);
                            }
                        } catch (Throwable $e) { error_log('users.php owner stamp: ' . $e->getMessage()); }
                    }

                    // editing yourself -> keep the live session in step with the flag
                    if ($userId === (int)$_SESSION['user_id']) $_SESSION['must_change_password'] = $mustChange;

                    // Log activity
                    $bits = [];
                    if (!empty($password))        $bits[] = 'password changed';
                    if ($oldActive !== $isActive) $bits[] = $isActive ? 'reactivated' : 'deactivated';
                    if ($mustChange)              $bits[] = 'must change password';
                    $details = "Updated user: $newUsername" . ($bits ? ' (' . implode(', ', $bits) . ')' : '');
                    logActivity($user_id, $username, 'User Updated', $details, 'user', $userId);

                    // Notify user if role changed
                    try {
                        if ($old_role !== $newRole) {
                            createNotification($userId, 'Role Changed', 'Your role has been changed from ' . $old_role . ' to ' . $newRole . '.', 'warning', 'account.php');
                        }
                    } catch (Exception $e) {}

                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update user']);
                }
                exit();

            case 'deleteUser':
                requireCsrfJson();
                requirePermJson('users', 'd');

                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                // another school's id resolves to 0 here and falls out as "Invalid user ID"
                $userId = usersReach($_POST['id'] ?? 0, $isPlat);

                if ($userId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                if ($userId == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                    exit();
                }

                // an account above you in the chain is not yours to remove
                if (($why = ormsOutranks($userId)) !== '') {
                    echo json_encode(['success' => false, 'message' => $why]);
                    exit();
                }

                $prot = ormsProtectedAccount($userId);
                if ($prot !== '') {
                    echo json_encode(['success' => false, 'message' => $prot === 'app_owner'
                        ? 'The App Owner account cannot be deleted - the platform would have no operator.'
                        : 'This is the school Owner. Transfer ownership first, then delete the account.']);
                    exit();
                }

                // Get username before deleting
                $deletedUsername = (string) (qVal("SELECT username FROM users WHERE id = ?", 'i', $userId) ?? '');

                if ($deletedUsername === '') {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }

                // deleting a user cascades to their students/teachers profile — count the marks
                // history first so a RESTRICT fk never surfaces as a raw sql error
                $blocked = usersDeleteBlockReason($userId, $deletedUsername);
                if ($blocked !== '') {
                    echo json_encode(['success' => false, 'message' => $blocked]);
                    exit();
                }

                try {
                    // school leg on the delete too — usersReach() already proved it, this is the backstop
                    $scope = usersScope($isPlat);
                    $done  = qExec("DELETE FROM users WHERE id = ?" . $scope,
                                   $scope ? 'ii' : 'i', ...($scope ? [$userId, $school] : [$userId])) > 0;
                } catch (Throwable $e) {
                    error_log('users.php deleteUser: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Could not delete ' . $deletedUsername . ' — other records still reference this account. Deactivate it instead.']);
                    exit();
                }

                if ($done) {
                    // Log activity
                    logActivity($user_id, $username, 'User Deleted', "Deleted user: $deletedUsername", 'user', $userId);

                    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
                }
                exit();

            // ------------------------------------------------------------ one login, the whole picture
            // Everything an operator or an owner asks about an account in one place: who it is, where it
            // sits in the chain of authority, what its role may actually do, the profile behind it, the
            // school it belongs to, and what it has been doing. Every block is best-effort — a table that
            // isn't installed yet turns into an empty block, never a 500.
            case 'getUser360': {
                $uid = usersReach($_POST['id'] ?? 0, $isPlat);
                if ($uid <= 0) { echo json_encode(['success' => false, 'message' => 'User not found']); exit(); }

                $sel = "u.id, u.username, u.email, u.role, u.is_active, u.created_at";
                foreach (['full_name', 'phone', 'profile_image', 'email_verified', 'must_change_password', 'school_id',
                          'branch_id', 'created_by', 'updated_at', 'last_login_at', 'last_login_ip',
                          'password_changed_at', 'google_id'] as $c)
                    $sel .= usersCol($c) ? ", u.`$c`" : ", NULL AS `$c`";
                $joins = '';
                if (usersCol('school_id')) { $sel .= ", sc.name AS school_name, sc.code AS school_code"; $joins .= " LEFT JOIN schools sc ON sc.id = u.school_id"; }
                else                       { $sel .= ", NULL AS school_name, NULL AS school_code"; }
                if (usersCol('branch_id')) { $sel .= ", br.name AS branch_name, br.code AS branch_code"; $joins .= " LEFT JOIN branches br ON br.id = u.branch_id"; }
                else                       { $sel .= ", NULL AS branch_name, NULL AS branch_code"; }
                if (usersCol('created_by')) { $sel .= ", cb.username AS created_by_name"; $joins .= " LEFT JOIN users cb ON cb.id = u.created_by"; }
                else                        { $sel .= ", NULL AS created_by_name"; }

                $p = qOne("SELECT $sel FROM users u$joins WHERE u.id = ?", 'i', $uid);
                if (!$p) { echo json_encode(['success' => false, 'message' => 'User not found']); exit(); }

                $rkey  = (string) $p['role'];
                $prot  = ormsProtectedAccount($uid, (int) $p['school_id']);
                $p['role_label'] = ormsRoleLabel($rkey);
                $p['role_color'] = (roleByKey($rkey)['color'] ?? '#0074D9');
                $p['rank']       = ormsRoleRank($rkey);
                $p['protected']  = $prot;                                   // '', 'app_owner' or 'school_owner'
                $p['manageable'] = ormsOutranks($uid, $rkey) === '';        // may the viewer act on this row at all
                $out = ['profile' => $p];

                // ---- chain of authority, drawn for THIS school. every rung with a live headcount,
                // so "where does this account sit" is answered without opening three other pages.
                $ladder = [];
                foreach (readRoles() as $r) {
                    // only the operator row is platform-only. is_super is NOT the test — Admin carries
                    // that flag too, and dropping it would leave a hole in the middle of the ladder.
                    if ($r['key'] === 'Super Admin' && (int) $p['school_id'] !== 0) continue;
                    $n = 0;
                    try {
                        $n = (int) qVal("SELECT COUNT(*) FROM users WHERE role = ?" . (usersCol('school_id') ? " AND school_id = ?" : ''),
                                        usersCol('school_id') ? 'si' : 's',
                                        ...(usersCol('school_id') ? [$r['key'], (int) $p['school_id']] : [$r['key']]));
                    } catch (Throwable $e) {}
                    $ladder[] = ['key' => $r['key'], 'label' => $r['label'], 'color' => $r['color'],
                                 'rank' => ormsRoleRank($r['key']), 'people' => $n, 'is_them' => $r['key'] === $rkey];
                }
                usort($ladder, fn($a, $b) => $a['rank'] <=> $b['rank']);
                $out['ladder'] = $ladder;

                // ---- what this role may actually do. the matrix is the only truth; showing it here is
                // how an owner answers "why can they see that page" without opening roles.php
                global $RBAC_PAGES;
                $perms = rbacPermsFor($rkey);
                $rows  = [];
                foreach ($RBAC_PAGES as $pg) {
                    $b = $perms[$pg['key']] ?? ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
                    if (empty($b['v']) && empty($b['a']) && empty($b['e']) && empty($b['d'])) continue;   // denied pages are noise
                    $rows[] = ['label' => $pg['label'], 'icon' => $pg['icon'], 'group' => $pg['group'],
                               'v' => (int)$b['v'], 'a' => (int)$b['a'], 'e' => (int)$b['e'], 'd' => (int)$b['d']];
                }
                $out['perms'] = $rows;

                // ---- the profile row behind the login, whichever kind it is
                try {
                    $out['teacher'] = qOne("SELECT t.employee_no, t.qualification, t.joining_date, t.status,
                                                   (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.teacher_id = t.id) AS assignments
                                            FROM teachers t WHERE t.user_id = ?", 'i', $uid);
                } catch (Throwable $e) { $out['teacher'] = null; }
                try {
                    $out['student'] = qOne("SELECT s.admission_no, s.roll_no, s.gender, s.guardian_name, s.guardian_phone, s.status,
                                                   c.name AS class_name, sec.name AS section_name, ay.name AS year_name
                                            FROM students s
                                            JOIN classes c         ON c.id   = s.class_id
                                            JOIN sections sec      ON sec.id = s.section_id
                                            JOIN academic_years ay ON ay.id  = s.academic_year_id
                                            WHERE s.user_id = ?", 'i', $uid);
                } catch (Throwable $e) { $out['student'] = null; }

                // ---- the school this login belongs to. For the School Owner this IS their profile:
                // the tenant, its plan, what it is running on and when it next has to be paid for.
                $out['school'] = null;
                if ((int) $p['school_id'] > 0) {
                    try {
                        $s = qOne("SELECT s.id, s.name, s.code, s.status, s.email, s.phone, s.address, s.created_at,
                                          p.name AS plan_name, p.max_students, p.max_teachers, p.max_branches,
                                          (SELECT COUNT(*) FROM branches b WHERE b.school_id = s.id) AS branches,
                                          (SELECT COUNT(*) FROM users x    WHERE x.school_id = s.id) AS logins,
                                          (SELECT sub.ends_at FROM school_subscriptions sub WHERE sub.school_id = s.id
                                            ORDER BY sub.ends_at DESC, sub.id DESC LIMIT 1) AS ends_at
                                   FROM schools s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?", 'i', (int) $p['school_id']);
                        if ($s) {
                            foreach (['students' => 'students', 'teachers' => 'teachers'] as $k => $t) {
                                try { $s[$k] = (int) qVal("SELECT COUNT(*) FROM `$t` WHERE school_id = ?", 'i', (int) $p['school_id']); }
                                catch (Throwable $e) { $s[$k] = 0; }
                            }
                            $s['is_owner'] = $prot === 'school_owner';
                            // plan + renewal date are the OWNER's business (billing perm), not every
                            // admin who can open the users page — blank them rather than hide the card
                            if (!$isPlat && !can('billing', 'v')) {
                                $s['plan_name'] = null; $s['ends_at'] = null;
                                $s['max_students'] = $s['max_teachers'] = $s['max_branches'] = null;
                            }
                            $out['school'] = $s;
                        }
                    } catch (Throwable $e) { $out['school'] = null; }
                }

                // ---- what the account has been doing. 30-day login count answers "is this seat used"
                try {
                    $out['activity'] = qAll("SELECT action, details, ip_address, timestamp FROM activity_logs
                                             WHERE user_id = ? ORDER BY id DESC LIMIT 10", 'i', $uid);
                } catch (Throwable $e) { $out['activity'] = []; }
                try {
                    $out['logins_30d'] = (int) qVal("SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND action = 'Login'
                                                     AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)", 'i', $uid);
                } catch (Throwable $e) { $out['logins_30d'] = 0; }
                try {
                    $out['sessions'] = (int) qVal("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND is_active = 1", 'i', $uid);
                } catch (Throwable $e) { $out['sessions'] = null; }   // table not installed -> hide the chip

                echo json_encode(['success' => true] + $out);
                exit();
            }

            // ------------------------------------------------------------ branch picker for the form
            case 'getSchoolBranches': {
                $s = $isPlat ? (int) ($_POST['school_id'] ?? 0) : $school;
                if ($s <= 0) { echo json_encode(['success' => true, 'data' => []]); exit(); }
                if (!$isPlat && $s !== $school) { echo json_encode(['success' => false, 'message' => 'School not found']); exit(); }
                $rows = [];
                try {
                    $rows = qAll("SELECT id, name, code, is_main FROM branches WHERE school_id = ? AND status = 'Active'
                                  ORDER BY is_main DESC, name ASC", 'i', $s);
                } catch (Throwable $e) { $rows = []; }   // pre-migration db — the form just hides the picker
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Throwable $e) {
        error_log("Users.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);   // detail stays in the log
        exit();
    }
}

// If we reach here, render the HTML page
// add/edit dropdowns — all of THIS school's roles (admin must stay assignable). the super row
// belongs to the school-0 template set, so a tenant never gets offered it in the first place
$formRoles = array_values(array_filter(readRoles(),
    fn($r) => !empty($r['key']) && ($isPlat || !in_array($r['key'], ormsPlatformRoles(), true))));
$filterRoles = $formRoles;            // filter dropdown + role chips share the same live list

// key => label/colour for the js renderers. the badge paints itself from the stored colour,
// so a 4th role really does show up on its own — no per-role css needed
$roleMeta = [];
foreach ($formRoles as $r) {
    $roleMeta[$r['key']] = ['label' => $r['label'], 'color' => $r['color'], 'rank' => ormsRoleRank($r['key'])];
}

// the operator allocates a login to a school — a school admin is pinned to its own and never sees this
$schoolList = [];
if ($isPlat && usersHasTenant()) {
    try { $schoolList = qAll("SELECT id, name, code FROM schools ORDER BY name ASC"); } catch (Throwable $e) {}
}
$hasBranches = usersCol('branch_id');
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
    <title>User Management - Dashboard System</title>

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
                    <h1><i class="fas fa-users"></i> User Management</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Users</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Users</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" onclick="loadUsers()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('users', 'a')): ?>
                        <button class="btn btn-success" onclick="openAddModal()">
                            <i class="fas fa-plus"></i> Add User
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section initially-hidden" id="filtersSection">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times-circle"></i> Clear All
                        </button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date From</label>
                            <input type="date" id="filterDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Date To</label>
                            <input type="date" id="filterDateTo" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-user-tag"></i> Role</label>
                            <select id="filterRole" class="filter-input">
                                <option value="">All Roles</option>
                                <?php foreach ($filterRoles as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['key']); ?>"><?php echo htmlspecialchars($r['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Status</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- role chips — counts come from the loaded rows, click filters the table -->
                <div class="chev-pipeline" id="rolePipeline"></div>

                <!-- skeleton while the first fetch runs -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="usersWrap" class="initially-hidden">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div class="table-responsive">
                        <table id="usersTable" class="display chip-table"></table>
                    </div>
                </div>

                <div id="usersEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-user-slash"></i>
                    <h4>No users to show</h4>
                    <p>Create the first account with the Add User button above.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- User Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Username *</label>
                            <input type="text" id="username" name="username" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> Full Name</label>
                            <input type="text" id="fullName" name="full_name" maxlength="100" placeholder="Shown everywhere in the app">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Left empty it falls back to the username &mdash; fill it in and the account stops being an anonymous row.</div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" id="phone" name="phone" maxlength="20" placeholder="+92 300 0000000">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password <span id="passwordHint" class="initially-hidden">(Leave empty to keep current)</span></label>
                            <input type="password" id="password" name="password">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Role *</label>
                            <select id="role" name="role" required>
                                <?php foreach ($formRoles as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['key']); ?>"><?php echo htmlspecialchars($r['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text" id="roleRankHint"></div>
                        </div>

                        <?php if ($isPlat && $schoolList): ?>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> School *</label>
                            <select id="userSchool" name="school_id" required>
                                <option value="">Select school…</option>
                                <?php foreach ($schoolList as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name'] . ' [' . $s['code'] . ']'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> The tenant this login belongs to. An existing account can only be moved while it owns no student or teacher profile.</div>
                        </div>
                        <?php endif; ?>

                        <?php if ($hasBranches && !$branch): ?>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch</label>
                            <select id="userBranch" name="branch_id">
                                <option value="">Main branch</option>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Which campus this account works at. Leave it on the main branch if the school has only one.</div>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Account Active</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="userActive" name="is_active" value="1" class="toggle-input" checked>
                                <label for="userActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Inactive accounts cannot log in — switch this back on to reactivate a student deactivated from Students.</div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Require Password Change</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="userMustChange" name="must_change_password" value="1" class="toggle-input">
                                <label for="userMustChange" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> At next login the user is held on the account page until they set their own password — leave this on for the shared default students start with.</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- User 360 Modal — one login, the whole picture -->
    <div class="modal-overlay" id="user360Modal" role="dialog" aria-modal="true" aria-labelledby="user360Title">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="user360Title"><i class="fas fa-street-view"></i> User 360&deg; View</h3>
                <button class="close-btn" id="btnCloseUser360" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="user360Body"></div>
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
    // Lazy-load export dependencies (PDF/Excel) on first use
    function loadExportDeps(callback) {
        if (window.pdfMake) { callback(); return; }
        var urls = [
            'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'
        ];
        var loaded = 0;
        function loadNext() {
            if (loaded >= urls.length) { callback(); return; }
            var s = document.createElement('script');
            s.src = urls[loaded];
            s.onload = function() { loaded++; loadNext(); };
            document.head.appendChild(s);
        }
        loadNext();
    }
    </script>

    <script>
        let usersTable;
        let isEditMode = false;
        let usersData = [];

        // roles straight from the roles table — labels, colours, badge classes
        const ROLE_META = <?php echo json_encode($roleMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const ROLE_KEYS = Object.keys(ROLE_META);
        let roleFilter = '';                          // single source of truth for the chips + select

        // chain of authority, mirrored client-side purely so the UI doesn't offer what the server refuses
        const MY_ID           = <?php echo (int)$_SESSION['user_id']; ?>;
        const MY_RANK         = <?php echo ormsRoleRank(); ?>;                 // lower number = more authority
        const IS_PLAT         = <?php echo $isPlat ? 'true' : 'false'; ?>;
        const HAS_BRANCH      = <?php echo $hasBranches ? 'true' : 'false'; ?>;
        const MY_SCHOOL       = <?php echo (int) $school; ?>;
        const CAN_EDIT        = <?php echo can('users', 'e') ? 'true' : 'false'; ?>;
        const CAN_DELETE      = <?php echo can('users', 'd') ? 'true' : 'false'; ?>;
        const CAN_IMPERSONATE = <?php echo ($isPlat || in_array($role, ['Admin', 'School Owner'], true)) ? 'true' : 'false'; ?>;
        let USR_COL = {};                             // column title -> index, rebuilt with the table
        let branchCache = {};                         // school id -> branches, one fetch each

        $(document).ready(function() {
            ORMS.dropdown('#filterRole, #filterStatus, #role, #userSchool, #userBranch');
            $(document).on('click', '#rolePipeline .chev-item', function() {
                setRoleFilter($(this).attr('data-role') || '');
            });
            $('#role').on('change', paintRankHint);
            // the branch list belongs to ONE school — swap it whenever the allocation changes
            $('#userSchool').on('change', function() { loadBranchPicker(+this.value || 0, 0); });
            $('#btnCloseUser360').on('click', function() { $('#user360Modal').removeClass('active'); });
            $('#user360Modal').on('click', function(e) { if (e.target === this) $(this).removeClass('active'); });
            $(document).on('keydown.usr360', function(e) {
                if (e.key === 'Escape' && $('#user360Modal').hasClass('active')) $('#user360Modal').removeClass('active');
            });
            renderRolePipeline();
            loadUsers();
        });

        // says out loud where the chosen role sits relative to the person filling the form
        function paintRankHint() {
            var m = ROLE_META[$('#role').val()];
            if (!m) { $('#roleRankHint').html(''); return; }
            var above = !IS_PLAT && Number(m.rank) < MY_RANK;
            $('#roleRankHint').html('<i class="fas fa-' + (above ? 'triangle-exclamation' : 'circle-info') + '"></i> ' +
                (above ? ORMS.esc(m.label) + ' sits above your own role — only that role itself or the App Owner can grant it.'
                       : 'Rank ' + m.rank + ' in the chain of authority.'));
        }

        // active branches of ONE school, cached — the picker opens far more often than branches change
        function loadBranchPicker(school, selected) {
            if (!HAS_BRANCH) return;
            var fill = function(rows) {
                $('#userBranch').html('<option value="">Main branch</option>' + (rows || []).map(function(b) {
                    return '<option value="' + b.id + '"' + (Number(selected) === Number(b.id) ? ' selected' : '') + '>' +
                           ORMS.esc(b.name + (Number(b.is_main) === 1 ? ' (main)' : '')) + '</option>';
                }).join(''));
                ORMS.dropdown.refresh('#userBranch');
            };
            var s = school || MY_SCHOOL;
            if (!s) { fill([]); return; }
            if (branchCache[s]) { fill(branchCache[s]); return; }
            ORMS.post('getSchoolBranches', { school_id: s }).done(function(res) {
                branchCache[s] = res.success ? (res.data || []) : [];
                fill(branchCache[s]);
            }).fail(function() { fill([]); });
        }

        // stored role colour -> inline swatch, luminance picks readable text. hex is validated, never echoed raw
        function roleStyle(hex) {
            var h = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(hex || '')) ? String(hex) : '#0074D9';
            var c = h.length === 4 ? h[1] + h[1] + h[2] + h[2] + h[3] + h[3] : h.slice(1);
            var lum = parseInt(c.slice(0, 2), 16) * .299 + parseInt(c.slice(2, 4), 16) * .587 + parseInt(c.slice(4, 6), 16) * .114;
            return 'background:' + h + ';color:' + (lum > 160 ? '#001f3f' : '#fff');
        }

        function renderRolePipeline() {
            var counts = {};
            ROLE_KEYS.forEach(function(k) { counts[k] = 0; });
            usersData.forEach(function(u) { if (counts[u.role] !== undefined) counts[u.role]++; });

            var html = '<button type="button" class="chev-item' + (roleFilter === '' ? ' active' : '') + '" data-role="">' +
                       '<span class="chev-label"><i class="fas fa-users"></i> All</span>' +
                       '<span class="chev-count">' + usersData.length + '</span></button>';
            ROLE_KEYS.forEach(function(k) {
                html += '<button type="button" class="chev-item' + (roleFilter === k ? ' active' : '') +
                        '" data-role="' + ORMS.esc(k) + '">' +
                        '<span class="chev-label"><i class="fas fa-user-tag"></i> ' + ORMS.esc(ROLE_META[k].label) + '</span>' +
                        '<span class="chev-count">' + counts[k] + '</span></button>';
            });
            $('#rolePipeline').html(html);
        }

        function setRoleFilter(key) {
            roleFilter = key || '';
            var sel = document.getElementById('filterRole');
            if (sel && sel.value !== roleFilter) {
                sel.value = roleFilter;
                ORMS.dropdown.refresh('#filterRole');
            }
            renderRolePipeline();
            applyFilters();
        }

        function loadUsers() {
            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    $('#loadingSkeleton').addClass('initially-hidden');
                    if (response.success) {
                        usersData = response.data || [];
                        $('#filtersSection').removeClass('initially-hidden');
                        renderRolePipeline();
                        if (!usersData.length) {                       // empty result -> message, not a blank table body
                            $('#usersWrap').addClass('initially-hidden');
                            $('#usersEmpty').removeClass('initially-hidden');
                            return;
                        }
                        $('#usersEmpty').addClass('initially-hidden');
                        $('#usersWrap').removeClass('initially-hidden');
                        initializeDataTable(usersData);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load users'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#loadingSkeleton').addClass('initially-hidden');
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        // column set is role-dependent (only the operator gets a School column), so every filter looks
        // its index up by name instead of counting — a hardcoded index searches the wrong column
        // ---- chip cells: 10 flat columns -> 5 grouped ones ----
        var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box, DASH = ORMS.DASH;
        function uv(x) { return (x === null || x === undefined || x === '') ? DASH : ORMS.esc(x); }

        function cellUser(r) {
            var nm = r.full_name && r.full_name !== r.username ? r.full_name : '';
            return K([
                '<span class="drill-who"><span class="drill-avatar drill-avatar-txt">' +
                ORMS.esc(String(nm || r.username || '?').charAt(0).toUpperCase()) + '</span><span>' +
                '<span class="cell-title">' + ORMS.esc(r.username) + '</span>' +
                (nm ? '<span class="tnt-sub">' + ORMS.esc(nm) + '</span>' : '') + '</span></span>',
                R(C('chip-soft-navy', 'fa-envelope', 'Email'), uv(r.email)),
                R(C('chip-soft-navy', 'fa-phone', 'Phone'), uv(r.phone))
            ]);
        }

        function cellAccess(r) {
            var m = ROLE_META[r.role], live = Number(r.is_active) === 1;
            return K([
                R(C('chip-navy', 'fa-user-tag', 'Role'),
                  '<span class="role-badge" style="' + roleStyle(m && m.color) + '">' + ORMS.esc(m ? m.label : (r.role || '—')) + '</span>'),
                R(C('chip-soft-navy', 'fa-ranking-star', 'Rank'), String(r.rank)),
                R(C(live ? 'chip-soft-green' : 'chip-soft-amber', 'fa-toggle-on', 'Status'),
                  '<span class="' + (live ? 'val-pos' : 'val-neg') + '">' + (live ? 'Active' : 'Inactive') + '</span>'),
                Number(r.must_change_password) === 1
                    ? R(C('chip-soft-amber', 'fa-key', 'Password'), '<span class="val-amount">must change</span>') : ''
            ]);
        }

        function cellWhere(r) {
            return K([
                IS_PLAT ? R(C('chip-soft-purple', 'fa-city', 'School'), uv(r.school_name)) : '',
                IS_PLAT && r.school_code ? R(C('chip-soft-purple', 'fa-hashtag', 'Code'), BOX(r.school_code)) : '',
                HAS_BRANCH ? R(C('chip-soft-navy', 'fa-code-branch', 'Branch'), uv(r.branch_name)) : '',
                R(C('chip-soft-navy', 'fa-hashtag', 'User ID'), BOX(r.id))
            ]);
        }

        function cellActivity(r) {
            return K([
                R(C('chip-soft-navy', 'fa-calendar-plus', 'Created'), ORMS.esc(r.created_at)),
                R(C(r.last_login_raw ? 'chip-soft-green' : 'chip-soft-amber', 'fa-right-to-bracket', 'Last login'),
                  r.last_login_raw ? ORMS.esc(r.last_login) : '<span class="val-muted">Never</span>')
            ]);
        }

        function usrBlob(r) {
            return [r.username, r.full_name, r.email, r.phone, r.role, r.school_name, r.school_code,
                    r.branch_name, Number(r.is_active) === 1 ? 'Active' : 'Inactive'].filter(Boolean).join(' ');
        }

        // chip html for display, the real value for sort, a text blob for filter
        function usrCol(title, build, sortField, cls) {
            return { data: null, title: title, className: cls || '', render: function (d, t, r) {
                if (t === 'display') return build(r);
                if (t === 'filter')  return usrBlob(r);
                var v = r[sortField];
                return v === null || v === undefined ? '' : v;
            } };
        }

        // the column set is role-dependent, so every filter looks its index up by name
        function buildUserColumns() {
            var c = [
                usrCol('User', cellUser, 'username'),
                usrCol('Access', cellAccess, 'rank'),
                usrCol('Where', cellWhere, 'school_name'),
                usrCol('Activity', cellActivity, 'created_raw'),
                { data: null, title: 'Actions', orderable: false, className: 'col-actions',
                  render: function (d, t, row) { return t === 'display' ? '<div class="actions-cell">' + userActions(row) + '</div>' : ''; } },
                // hidden, and the only reason it exists: the Role chips and dropdown filter on an
                // exact role key, which a grouped cell can no longer offer
                { data: 'role', title: 'Role', visible: false }
            ];
            USR_COL = {};
            c.forEach(function (x, i) { USR_COL[String(x.title).toLowerCase()] = i; });
            return c;
        }

        // buttons follow the permission matrix AND the chain of authority: an account above you is
        // shown, never actioned — the server refuses it anyway, and a dead button is worse than none
        function userActions(row) {
            var btns = '<button class="action-icon view-icon" title="360° view of ' + ORMS.esc(row.username) +
                       '" onclick="openUser360(' + row.id + ')" aria-haspopup="dialog"><i class="fas fa-street-view"></i></button>';
            var mine = row.id == MY_ID;
            var above = !IS_PLAT && !mine && Number(row.rank) < MY_RANK;   // they outrank me
            if (CAN_IMPERSONATE && !mine && !above) {
                btns += '<button class="action-icon loginas-icon" onclick="location.href=\'impersonate.php?action=start&user_id=' + row.id +
                        '&csrf=' + encodeURIComponent(window.ORMS_CSRF || '') + '\'" title="Login as this user"><i class="fas fa-right-to-bracket"></i></button>';
            }
            if (CAN_EDIT && !above)   btns += '<button class="action-icon edit-icon" onclick=\'editUser(' + JSON.stringify(row) + ')\'><i class="fas fa-edit"></i></button>';
            if (CAN_DELETE && !above && !mine) btns += '<button class="action-icon delete-icon" onclick="deleteUser(' + row.id + ', this)"><i class="fas fa-trash"></i></button>';
            return btns;
        }

        function initializeDataTable(data) {
            if (usersTable) {
                usersTable.destroy();
                $('#usersTable').empty();
            }

            setTimeout(() => {
                // exports must ship TEXT — without the formatter every cell arrives as chip markup
                var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
                usersTable = $('#usersTable').DataTable({
                    data: data,
                    destroy: true,
                    columns: buildUserColumns(),
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: false,       // the chips carry the density; .table-responsive scrolls
                    dom: 'Blfrtip',
                    buttons: [
                        {
                            extend: 'csv',
                            text: '<i class="fas fa-file-csv"></i> CSV',
                            exportOptions: xOpts
                        },
                        {
                            text: '<i class="fas fa-file-pdf"></i> PDF',
                            action: function(e, dt, node, config) {
                                loadExportDeps(function() {
                                    $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config);
                                });
                            },
                            exportOptions: xOpts
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print"></i> Print',
                            exportOptions: xOpts
                        }
                    ],
                    order: []            // the server hands the list back newest-first already
                });

                // Apply filters on change
                $('#filterDateFrom, #filterDateTo').off('change.usr').on('change.usr', function() {
                    applyFilters();
                });
                $('#filterStatus').off('change.usr').on('change.usr', applyFilters);
                $('#filterRole').off('change.usr').on('change.usr', function() {
                    setRoleFilter(this.value);          // keeps the chips in step with the dropdown
                });
                applyFilters();                         // table rebuilt -> re-apply the active chip
            }, 100);
        }
        function applyFilters() {
            if (!usersTable) return;

            // Clear previous custom filters
            $.fn.dataTable.ext.search = [];

            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const role = roleFilter;

            // Date range filter — plain y-m-d string compare on the iso value, no timezone drift
            if (dateFrom || dateTo) {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    const day = String(usersData[dataIndex]?.created_raw || '').slice(0, 10);
                    if (!day) return true;
                    if (dateFrom && day < dateFrom) return false;
                    if (dateTo && day > dateTo) return false;
                    return true;
                });
            }

            // Active / inactive — read the flag off the row data, not the status badge markup
            const st = document.getElementById('filterStatus').value;
            if (st !== '') {
                $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                    return String(usersData[dataIndex]?.is_active ?? 1) === st;
                });
            }

            // Role filter — exact match on the key, never a substring. index comes from the live column map
            if (role) {
                usersTable.column(USR_COL.role).search('^' + role.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', true, false);
            } else {
                usersTable.column(USR_COL.role).search('');
            }

            usersTable.draw();
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterRole').value = '';
            document.getElementById('filterStatus').value = '';
            ORMS.dropdown.refresh('#filterRole, #filterStatus');
            roleFilter = '';
            renderRolePipeline();

            if (usersTable) {
                $.fn.dataTable.ext.search = [];
                usersTable.columns().search('').draw();
            }
        }

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            $('#passwordHint').addClass('initially-hidden');
            document.getElementById('userActive').checked = true;       // new logins start enabled
            document.getElementById('userMustChange').checked = true;   // admin-set password -> replace it on first login
            document.getElementById('password').required = true;
            $('#userSchool').val(IS_PLAT ? '' : MY_SCHOOL);
            ORMS.dropdown.refresh('#role, #userSchool');
            loadBranchPicker(IS_PLAT ? 0 : MY_SCHOOL, 0);
            paintRankHint();
            document.getElementById('userModal').classList.add('active');
        }

        function editUser(user) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
            document.getElementById('userId').value = user.id;
            document.getElementById('username').value = user.username;
            document.getElementById('email').value = user.email;
            document.getElementById('role').value = user.role;
            document.getElementById('password').value = '';
            $('#fullName').val(user.full_name && user.full_name !== user.username ? user.full_name : '');
            $('#phone').val(user.phone || '');
            $('#userSchool').val(user.school_id || '');
            document.getElementById('userActive').checked = Number(user.is_active) === 1;
            document.getElementById('userMustChange').checked = Number(user.must_change_password) === 1;
            $('#passwordHint').removeClass('initially-hidden');
            document.getElementById('password').required = false;
            ORMS.dropdown.refresh('#role, #userSchool');
            loadBranchPicker(Number(user.school_id) || MY_SCHOOL, Number(user.branch_id) || 0);
            paintRankHint();
            document.getElementById('userModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
            document.getElementById('userForm').reset();
            ORMS.dropdown.refresh('#role');
        }

        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const btn = $(this).find('button[type="submit"]');
            ORMS.post(isEditMode ? 'updateUser' : 'addUser', new FormData(this), { btn: btn, busyLabel: 'Saving…' })
                .done(function(res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    closeModal();
                    ORMS.ok(res.message || 'Saved');
                    loadUsers();
                })
                .fail(function(msg) { ORMS.err(msg); });
        });

        function deleteUser(userId, btn) {
            const u = usersData.filter(function(x) { return x.id == userId; })[0];
            ORMS.confirmDelete('Deleting ' + (u ? u.username : 'this user') +
                               ' also removes any teacher or student profile linked to the account.', 'Delete user?')
                .then(function(yes) {
                    if (!yes) return;
                    ORMS.post('deleteUser', { id: userId }, { btn: btn, busyLabel: 'Deleting…' })
                        .done(function(res) {
                            if (!res.success) { ORMS.err(res.message, 'Delete blocked'); return; }
                            ORMS.ok(res.message);
                            loadUsers();
                        })
                        .fail(function(msg) { ORMS.err(msg); });
                });
        }

        // ==================== user 360 ====================
        var esc = ORMS.esc;
        function blank(v) { return v === null || v === undefined || v === ''; }
        function dash(v) { return blank(v) ? '<span class="text-muted">&mdash;</span>' : esc(v); }
        function day(v) { return blank(v) ? '<span class="text-muted">&mdash;</span>' : esc(String(v).slice(0, 16).replace('T', ' ')); }
        function yn(on) { return on ? '<i class="fas fa-circle-check perm-yes"></i>' : '<i class="fas fa-circle-xmark perm-no"></i>'; }

        function item360(label, icon, val) {
            return '<div class="stu360-item"><div class="lbl">' + label + '</div><div class="val"><i class="fas fa-' + icon + '"></i>' +
                   (blank(val) ? '<span class="text-muted">&mdash;</span>' : val) + '</div></div>';
        }
        function sec360(icon, title, inner) {
            return '<div class="stu360-sec"><h4><i class="fas fa-' + icon + '"></i> ' + title + '</h4>' + inner + '</div>';
        }
        function none360(msg) { return '<div class="stu360-none"><i class="fas fa-inbox"></i> ' + msg + '</div>'; }
        function tbl360(heads, rows) {
            return '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
                   heads.map(function(x) { return '<th>' + x + '</th>'; }).join('') +
                   '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
        }

        function openUser360(id) {
            $('#user360Body').html(
                '<div class="skeleton-table">' +
                '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>' +
                '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div></div>' +
                '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>' +
                '</div>');
            $('#user360Modal').addClass('active');
            ORMS.post('getUser360', { id: id }).done(function(res) {
                if (!res.success) { $('#user360Modal').removeClass('active'); ORMS.err(res.message || 'Failed to load user'); return; }
                renderUser360(res);
                setTimeout(function() { $('#btnCloseUser360').trigger('focus'); }, 60);
            }).fail(function(msg) { $('#user360Modal').removeClass('active'); ORMS.err(msg); });
        }

        function renderUser360(d) {
            var p = d.profile, h = '', name = p.full_name || p.username;

            // ---- identity strip
            var protChip = p.protected === 'app_owner'
                ? '<span class="tnt-chip tnt-chip-main"><i class="fas fa-crown"></i> App Owner</span>'
                : (p.protected === 'school_owner' ? '<span class="tnt-chip tnt-chip-main"><i class="fas fa-key"></i> School Owner</span>' : '');
            h += '<div class="stu360-head">' +
                 (p.profile_image ? '<img class="stu360-avatar" src="' + esc(p.profile_image) + '" alt="">'
                                  : '<span class="stu360-avatar stu360-avatar-txt">' + esc(String(name).charAt(0).toUpperCase()) + '</span>') +
                 '<div class="stu360-id"><h4>' + esc(name) + ' ' +
                 '<span class="status-badge ' + (Number(p.is_active) === 1 ? 'status-active' : 'status-inactive') + '">' +
                 (Number(p.is_active) === 1 ? 'Active' : 'Inactive') + '</span> ' + protChip + '</h4>' +
                 '<div class="stu360-chips">' +
                 '<span class="role-badge" style="' + roleStyle(p.role_color) + '">' + esc(p.role_label) + '</span>' +
                 '<span class="subject-chip"><i class="fas fa-at"></i> ' + esc(p.username) + '</span>' +
                 (blank(p.school_name) ? '' : '<span class="subject-chip"><i class="fas fa-city"></i> ' + esc(p.school_name) + '</span>') +
                 (blank(p.branch_name) ? '' : '<span class="subject-chip"><i class="fas fa-code-branch"></i> ' + esc(p.branch_name) + '</span>') +
                 '<span class="subject-chip"><i class="fas fa-ranking-star"></i> Rank ' + esc(p.rank) + '</span>' +
                 '</div></div></div>';

            // ---- kpis
            var kpi = '<div><i class="fas fa-right-to-bracket"></i> Logins (30d) <b>' + (d.logins_30d || 0) + '</b></div>' +
                      '<div><i class="fas fa-clock"></i> Last Login <b>' + (blank(p.last_login_at) ? 'Never' : esc(String(p.last_login_at).slice(0, 16))) + '</b></div>' +
                      '<div><i class="fas fa-calendar-plus"></i> Member Since <b>' + esc(String(p.created_at || '').slice(0, 10)) + '</b></div>';
            if (d.sessions !== null && d.sessions !== undefined) kpi += '<div><i class="fas fa-desktop"></i> Live Sessions <b>' + d.sessions + '</b></div>';
            if (Number(p.must_change_password) === 1) kpi += '<div><i class="fas fa-key"></i> Password <b>Must change</b></div>';
            h += '<div class="stu360-sec"><div class="stat-mini">' + kpi + '</div></div>';

            // ---- account
            h += sec360('address-card', 'Account', '<div class="stu360-grid">' +
                item360('Full Name', 'id-card', dash(p.full_name)) +
                item360('Username', 'user', dash(p.username)) +
                item360('Email', 'envelope', dash(p.email) + (Number(p.email_verified) === 1 ? ' <i class="fas fa-circle-check perm-yes" title="Verified"></i>' : '')) +
                item360('Phone', 'phone', dash(p.phone)) +
                item360('School', 'city', blank(p.school_name) ? '' : esc(p.school_name) + (blank(p.school_code) ? '' : ' [' + esc(p.school_code) + ']')) +
                item360('Branch', 'code-branch', dash(p.branch_name)) +
                item360('Created By', 'user-plus', dash(p.created_by_name)) +
                item360('Created', 'calendar', day(p.created_at)) +
                item360('Last Login', 'clock', blank(p.last_login_at) ? '' : day(p.last_login_at)) +
                item360('Last Login IP', 'network-wired', dash(p.last_login_ip)) +
                item360('Password Changed', 'key', blank(p.password_changed_at) ? '' : day(p.password_changed_at)) +
                item360('Google Linked', 'link', blank(p.google_id) ? 'No' : 'Yes') +
                '</div>');

            // ---- chain of authority: where this account sits, and who else is on each rung
            var rungs = (d.ladder || []).map(function(r, i) {
                return '<div class="u360-rung' + (r.is_them ? ' is-them' : '') + '">' +
                       '<span class="u360-rung-no">' + (i + 1) + '</span>' +
                       '<span class="role-badge" style="' + roleStyle(r.color) + '">' + esc(r.label) + '</span>' +
                       (r.is_them ? '<span class="u360-rung-me"><i class="fas fa-arrow-left"></i> this account</span>' : '') +
                       '<span class="u360-rung-n">' + r.people + (r.people === 1 ? ' person' : ' people') + '</span></div>';
            });
            h += sec360('sitemap', 'Chain of Authority', rungs.length
                ? '<div class="u360-ladder">' + rungs.join('') + '</div>' +
                  '<div class="help-text"><i class="fas fa-info-circle"></i> Authority runs top to bottom. Nobody can edit, delete, impersonate ' +
                  'or hand out a role that sits above their own &mdash; only the App Owner is above every rung.</div>'
                : none360('No roles are defined for this school yet.'));

            // ---- what the role may actually do
            var pr = (d.perms || []).map(function(x) {
                return '<tr><td><i class="fas ' + esc(x.icon) + '"></i> ' + esc(x.label) +
                       '<span class="tnt-sub">' + esc(x.group) + '</span></td>' +
                       '<td>' + yn(x.v) + '</td><td>' + yn(x.a) + '</td><td>' + yn(x.e) + '</td><td>' + yn(x.d) + '</td></tr>';
            });
            h += sec360('user-shield', 'What ' + esc(p.role_label) + ' can do', pr.length
                ? tbl360(['Page', 'View', 'Add', 'Edit', 'Delete'], pr)
                : none360('This role has no page permissions at all.'));

            // ---- the profile row behind the login
            if (d.teacher) {
                h += sec360('chalkboard-user', 'Teacher Profile', '<div class="stu360-grid">' +
                    item360('Employee No', 'id-badge', dash(d.teacher.employee_no)) +
                    item360('Qualification', 'graduation-cap', dash(d.teacher.qualification)) +
                    item360('Joined', 'calendar-day', blank(d.teacher.joining_date) ? '' : esc(String(d.teacher.joining_date).slice(0, 10))) +
                    item360('Assignments', 'list-check', esc(d.teacher.assignments)) +
                    item360('Status', 'toggle-on', dash(d.teacher.status)) + '</div>');
            }
            if (d.student) {
                h += sec360('user-graduate', 'Student Profile', '<div class="stu360-grid">' +
                    item360('Admission No', 'id-card', dash(d.student.admission_no)) +
                    item360('Roll No', 'hashtag', dash(d.student.roll_no)) +
                    item360('Class', 'school', esc(d.student.class_name || '') + ' &ndash; ' + esc(d.student.section_name || '')) +
                    item360('Year', 'calendar', dash(d.student.year_name)) +
                    item360('Guardian', 'user-shield', dash(d.student.guardian_name)) +
                    item360('Guardian Phone', 'phone', dash(d.student.guardian_phone)) +
                    item360('Status', 'toggle-on', dash(d.student.status)) + '</div>');
            }

            // ---- the school. For the Owner this IS the profile: the tenant they are responsible for.
            if (d.school) {
                var s = d.school;
                h += sec360('city', (s.is_owner ? 'School they own' : 'School they belong to'), '<div class="stu360-grid">' +
                    item360('School', 'building-columns', esc(s.name) + ' [' + esc(s.code) + ']') +
                    item360('Status', 'toggle-on', dash(s.status)) +
                    item360('Plan', 'layer-group', dash(s.plan_name)) +
                    item360('Renews / Expires', 'calendar-check', blank(s.ends_at) ? '' : esc(String(s.ends_at).slice(0, 10))) +
                    item360('Branches', 'code-branch', esc(s.branches) + (blank(s.max_branches) ? '' : ' / ' + (Number(s.max_branches) ? esc(s.max_branches) : '∞'))) +
                    item360('Students', 'user-graduate', esc(s.students) + (blank(s.max_students) ? '' : ' / ' + (Number(s.max_students) ? esc(s.max_students) : '∞'))) +
                    item360('Teachers', 'chalkboard-user', esc(s.teachers) + (blank(s.max_teachers) ? '' : ' / ' + (Number(s.max_teachers) ? esc(s.max_teachers) : '∞'))) +
                    item360('Logins', 'users', esc(s.logins)) +
                    item360('School Email', 'envelope', dash(s.email)) +
                    item360('School Phone', 'phone', dash(s.phone)) +
                    item360('Address', 'location-dot', dash(s.address)) +
                    item360('Opened', 'calendar-plus', blank(s.created_at) ? '' : esc(String(s.created_at).slice(0, 10))) +
                    '</div>' + (s.is_owner
                        ? '<div class="help-text"><i class="fas fa-key"></i> This account owns the subscription: it is the one login that can open Billing, and it cannot be deleted or demoted by anyone else.</div>'
                        : ''));
            }

            // ---- what it has been doing
            var acts = (d.activity || []).map(function(a) {
                return '<tr><td>' + esc(a.action) + '</td><td>' + esc(a.details || '') + '</td>' +
                       '<td>' + esc(a.ip_address || '') + '</td><td>' + esc(String(a.timestamp || '').slice(0, 16)) + '</td></tr>';
            });
            h += sec360('history', 'Recent Activity', acts.length
                ? tbl360(['Action', 'Details', 'IP', 'When'], acts)
                : none360('Nothing logged for this account yet.'));

            $('#user360Body').html(h);
        }
    </script>
</body>
</html>
