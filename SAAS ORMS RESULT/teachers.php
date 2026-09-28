<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// Check if user is logged in & session timeout (JSON response for AJAX, redirect for HTML)
$isAjaxReq = isset($_GET['action']) || isset($_POST['action'])
    || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if (!isset($_SESSION['user_id']) || !checkSessionTimeout()) {
    if ($isAjaxReq) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Tu sesión ha expirado o no estás autenticado. Por favor inicia sesión nuevamente.',
            'auth_required' => true
        ]);
        exit();
    }
    header("Location: login.php");
    exit();
}

// rbac view gate
requirePerm('teachers', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'teachers';

// same fallback avatar the sidebar uses
$tchAvatar = "https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg";

// ---------------------------------------------------------------- tenant rails
// every read and write on this page is school-scoped. "school-wide" means the whole of THIS
// school — there is no unfiltered branch, and a Branch Admin is pinned one level deeper.

// tenant cols there yet? one probe per request. 0 = pre-migration (single school), 1 = school_id, 2 = + branch_id
function tchTenant(): int {
    static $v = null;
    if ($v !== null) return $v;
    $v = 0;
    try { qVal("SELECT school_id FROM teachers LIMIT 1"); qVal("SELECT school_id FROM users LIMIT 1"); $v = 1; }
    catch (Throwable $e) { return $v; }
    try { qVal("SELECT branch_id FROM teachers LIMIT 1"); $v = 2; } catch (Throwable $e) {}
    return $v;
}

// id off the request -> verified id, 0 = another school's. pre-migration db has no school_id, so the id stands
function tchOwn(string $table, $id): int {
    return tchTenant() ? ormsOwns($table, $id) : (int)$id;
}

// " AND school_id = ?" (+ branch for a pinned branch admin) and its binds. binds by reference,
// so it must be concatenated INTO the sql string before the types/params are passed on
function tchSchoolAnd(string &$types, array &$params, string $alias = '', bool $branch = false): string {
    if (!tchTenant()) return '';
    $a = $alias !== '' ? $alias . '.' : '';
    $types .= 'i';
    $params[] = sid();
    $lock = ($branch && tchTenant() >= 2) ? ormsBranchLock() : 0;
    if (!$lock) return " AND {$a}school_id = ?";
    $types .= 'i';
    $params[] = $lock;
    return " AND {$a}school_id = ? AND {$a}branch_id = ?";
}

// tenant columns for an INSERT: [cols, placeholders, types, params] — empty strings pre-migration
function tchStamp(): array {
    $t = tchTenant();
    if (!$t)    return ['', '', '', []];
    if ($t < 2) return [', school_id', ', ?', 'i', [sid()]];
    return [', school_id, branch_id', ', ?, ?', 'ii', [sid(), tchNewBranch()]];
}

// branch a new teacher lands in — a branch admin is pinned, else the active filter, else the main branch
function tchNewBranch(): ?int {
    if (tchTenant() < 2) return null;
    if ($b = bid()) return $b;
    try { $m = qVal("SELECT id FROM branches WHERE school_id = ? ORDER BY is_main DESC, id ASC LIMIT 1", 'i', sid()); }
    catch (Throwable $e) { $m = null; }
    return $m !== null ? (int)$m : null;
}

// ---------------------------------------------------------------- local helpers

// photo upload — mime + real-image check, random name, jpg/png/webp only, 2MB cap
function tchSavePhoto(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'path' => null]; // none sent
    if ($f['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => 'Photo upload failed (code ' . (int)$f['error'] . ')'];
    if (($f['size'] ?? 0) > 2 * 1024 * 1024) return ['ok' => false, 'msg' => 'Photo must be smaller than 2MB'];

    $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $f['tmp_name']);
    finfo_close($fi);
    if (!isset($ok[$mime])) return ['ok' => false, 'msg' => 'Only JPG, PNG or WEBP photos are allowed'];

    $img = @getimagesize($f['tmp_name']); // mime header is spoofable — this proves it really decodes
    if (!$img || !in_array($img[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ['ok' => false, 'msg' => 'That file is not a valid image'];
    }

    $rel = tchPhotoDir();
    $dir = __DIR__ . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return ['ok' => false, 'msg' => 'Upload folder is not writable'];

    $name = 'tch_' . bin2hex(random_bytes(16)) . '.' . $ok[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return ['ok' => false, 'msg' => 'Could not save the photo'];

    return ['ok' => true, 'path' => $rel . $name];
}

// one folder per school — a directory listing of one tenant never shows another's faces.
// pre-migration installs keep writing to the flat folder
function tchPhotoDir(): string {
    return tchTenant() ? 'uploads/' . sid() . '/profiles/' : 'uploads/profiles/';
}

// only ever unlink inside our own upload tree — shape check + resolved path must stay under uploads/
function tchDropPhoto(?string $p): void {
    if (!$p || !preg_match('#^uploads/(\d+/)?profiles/[A-Za-z0-9_.\-]+$#', $p)) return;
    $base = realpath(__DIR__ . '/uploads');
    $real = realpath(__DIR__ . '/' . $p);
    if ($base && $real && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0 && is_file($real)) @unlink($real);
}

// '' -> null, bad date -> false. Accepts YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY
function tchDate(string $d) {
    $d = trim($d);
    if ($d === '') return null;
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $d, $m)) {
        $y = (int)$m[1]; $mth = (int)$m[2]; $day = (int)$m[3];
    } elseif (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $d, $m)) {
        $day = (int)$m[1]; $mth = (int)$m[2]; $y = (int)$m[3];
    } else {
        return false;
    }
    return checkdate($mth, $day, $y) ? sprintf('%04d-%02d-%02d', $y, $mth, $day) : false;
}

// employee_no doubles as the login, so keep it username-safe
function tchClean(string $s, int $max): string {
    return mb_substr(trim($s), 0, $max);
}

// ---------------------------------------------------------------- ajax
$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $year   = ormsCurrentYear();
        $yearId = $year ? (int)$year['id'] : 0;
        $actor  = (int)$_SESSION['user_id'];   // audit stamp — session only, never the post

        switch ($action) {

            // ---------------- list + current-year load chips
            case 'getTeachers':
                // roster is OUR roster — unscoped this returned the whole platform's staff
                $rt = '';
                $rp = [];
                $rows = qAll(
                    "SELECT t.id, t.user_id, t.employee_no, t.qualification, t.designation, t.national_id,
                            t.joining_date, t.leaving_date, t.emergency_contact, t.remarks, t.status, t.created_at,
                            u.username, u.full_name, u.email, u.phone, u.profile_image
                     FROM teachers t
                     JOIN users u ON u.id = t.user_id
                     WHERE 1 = 1" . tchSchoolAnd($rt, $rp, 't', true) . "
                     ORDER BY t.id DESC", $rt, ...$rp);

                // one extra query for the whole year, grouped in memory — never n+1 per teacher
                $load = [];
                if ($yearId) {
                    $lt = 'i';
                    $lp = [$yearId];
                    foreach (qAll(
                        "SELECT ts.teacher_id, ts.class_id, ts.subject_id, c.name AS class_name, sec.name AS section_name,
                                sub.name AS subject_name
                         FROM teacher_subjects ts
                         JOIN teachers t   ON t.id   = ts.teacher_id
                         JOIN classes  c   ON c.id   = ts.class_id
                         JOIN sections sec ON sec.id = ts.section_id
                         JOIN subjects sub ON sub.id = ts.subject_id
                         WHERE ts.academic_year_id = ?" . tchSchoolAnd($lt, $lp, 't', true) . "
                         ORDER BY COALESCE(c.numeric_level, c.sort_order) ASC, c.sort_order ASC,
                                  c.name ASC, sec.name ASC, sub.name ASC", $lt, ...$lp) as $a) {
                        $load[$a['teacher_id']][] = [
                            'class_id'   => (int)$a['class_id'],
                            'subject_id' => (int)$a['subject_id'],
                            'label'      => $a['class_name'] . ' – ' . $a['section_name'] . ' · ' . $a['subject_name']
                        ];
                    }
                }

                $out = [];
                foreach ($rows as $r) {
                    $mine = $load[$r['id']] ?? [];
                    $out[] = [
                        'id'            => (int)$r['id'],
                        'user_id'       => (int)$r['user_id'],
                        'username'      => $r['username'],
                        'full_name'     => $r['full_name'] ?: $r['username'],
                        'employee_no'   => $r['employee_no'],
                        'email'         => $r['email'],
                        'phone'         => $r['phone'],
                        'qualification' => $r['qualification'],
                        'designation'   => $r['designation'],
                        'national_id'   => $r['national_id'],
                        'emergency_contact' => $r['emergency_contact'],
                        'remarks'       => $r['remarks'],
                        'joining_date'  => $r['joining_date'],
                        'joining_txt'   => $r['joining_date'] ? date('d M Y', strtotime($r['joining_date'])) : '',
                        'leaving_date'  => $r['leaving_date'],
                        'leaving_txt'   => $r['leaving_date'] ? date('d M Y', strtotime($r['leaving_date'])) : '',
                        'status'        => $r['status'],
                        'photo'         => $r['profile_image'],
                        'chips'         => array_column($mine, 'label'),
                        'class_ids'     => array_values(array_unique(array_column($mine, 'class_id'))),
                        'subject_ids'   => array_values(array_unique(array_column($mine, 'subject_id')))
                    ];
                }
                jsonOk(['data' => $out, 'year' => $year['name'] ?? '']);

            // ---------------- sections + subjects of one class (assign modal chain)
            case 'getClassMeta':
                $classId = tchOwn('classes', $_GET['class_id'] ?? 0);   // foreign class -> same message, no probe value
                if ($classId <= 0) jsonErr('Please choose a class first');
                jsonOk([
                    'sections' => qAll("SELECT id, name FROM sections WHERE class_id = ? AND is_active = 1 ORDER BY name ASC", 'i', $classId),
                    'subjects' => qAll(
                        "SELECT s.id, s.name, s.code
                         FROM class_subjects cs
                         JOIN subjects s ON s.id = cs.subject_id
                         WHERE cs.class_id = ? AND s.is_active = 1
                         ORDER BY cs.sort_order ASC, s.name ASC", 'i', $classId)
                ]);

            // ---------------- one teacher's assignments for a year
            case 'getAssignments':
                $tid = tchOwn('teachers', $_GET['teacher_id'] ?? 0);
                $yid = tchOwn('academic_years', $_GET['year_id'] ?? $yearId);
                if ($tid <= 0 || $yid <= 0) jsonErr('Teacher and academic year are required');
                jsonOk(['data' => ormsTeacherAssignments($tid, $yid)]);

            // ---------------- create: users + teachers in ONE transaction
            case 'addTeacher':
                requireCsrfJson();
                requirePermJson('teachers', 'a');
                ormsQuotaGuard('teachers');                     // plan seat check, before any work

                $fullName = tchClean($_POST['full_name'] ?? '', 100);
                $empNo    = tchClean($_POST['employee_no'] ?? '', 30);
                $uname    = tchClean($_POST['username'] ?? '', 50);
                $email    = tchClean($_POST['email'] ?? '', 100);
                $phone    = tchClean($_POST['phone'] ?? '', 20);
                $qual     = tchClean($_POST['qualification'] ?? '', 150);
                $desig    = tchClean($_POST['designation'] ?? '', 100);
                $nid      = tchClean($_POST['national_id'] ?? '', 30);
                $emerg    = tchClean($_POST['emergency_contact'] ?? '', 20);
                $remarks  = tchClean($_POST['remarks'] ?? '', 255);
                $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
                $join     = tchDate($_POST['joining_date'] ?? '');
                // leaving date belongs to an inactive teacher only — going Active wipes it
                $leave    = $status === 'Inactive' ? tchDate($_POST['leaving_date'] ?? '') : null;

                if ($fullName === '' || $empNo === '') jsonErr('Full name and employee number are required');
                if ($join === false)  jsonErr('Joining date is not a valid date');
                if ($leave === false) jsonErr('Leaving date is not a valid date');
                if ($leave && $join && $leave < $join) jsonErr('Leaving date cannot be before the joining date');
                if ($uname === '') $uname = $empNo;                       // employee_no doubles as the login
                if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $uname)) jsonErr('Username must be 3-50 chars: letters, numbers, _ . - only');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Invalid email format');

                // both uniques checked up front so the user gets a friendly message, not a 1062.
                // PER SCHOOL — unscoped this is both a work-stopper and an oracle for other schools' logins
                $nt = 's';
                $np = [$uname];
                if (qVal("SELECT id FROM users WHERE username = ?" . tchSchoolAnd($nt, $np), $nt, ...$np)) {
                    jsonErr('Username "' . $uname . '" is already taken');
                }
                $et = 's';
                $ep = [$empNo];
                if (qVal("SELECT id FROM teachers WHERE employee_no = ?" . tchSchoolAnd($et, $ep), $et, ...$ep)) {
                    jsonErr('Employee number "' . $empNo . '" already exists');
                }

                $up = tchSavePhoto($_FILES['photo'] ?? []);
                if (!$up['ok']) jsonErr($up['msg']);
                $photo = $up['path'];

                $pwd  = (string)getSetting('teacher_default_password', 'teacher123');
                $hash = password_hash($pwd, PASSWORD_DEFAULT);

                // both rows carry the tenant stamp — a teacher with no school belongs to everyone
                [$tc, $tv, $tt, $tp] = tchStamp();

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // locked re-check — an unlocked count lets two simultaneous adds oversell the tier
                    if (ormsQuotaRoomLocked('teachers', 1) < 1) { $conn->rollback(); jsonErr(ormsQuotaMessage(ormsQuota('teachers')), ['quota_full' => true]); }
                    // admin-created logins are pre-verified, otherwise verification would lock them out
                    $newUid = qInsert(
                        // must_change_password: teacher logins start on the shared default too
                        "INSERT INTO users (username, password, email, role, is_active, email_verified, must_change_password, full_name, phone, profile_image$tc)
                         VALUES (?, ?, ?, 'Teacher', 1, 1, 1, ?, ?, ?$tv)",
                        'ssssss' . $tt, $uname, $hash, $email, $fullName, $phone, $photo, ...$tp);

                    // types: i user, 9x s (emp..status), i created_by, + tenant stamp
                    qInsert("INSERT INTO teachers (user_id, employee_no, qualification, designation, national_id,
                                                   joining_date, leaving_date, emergency_contact, remarks, status, created_by$tc)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?$tv)",
                        'isssssssssi' . $tt, $newUid, $empNo, $qual, $desig, $nid, $join, $leave, $emerg, $remarks, $status, $actor, ...$tp);

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();                                     // no orphan login left behind
                    tchDropPhoto($photo);
                    error_log('teachers.php addTeacher: ' . $e->getMessage());
                    jsonErr('Could not create the teacher. Username or employee number may already be in use.');
                }

                logActivity($user_id, $username, 'Teacher Created', "Created teacher: $fullName ($empNo), login $uname");
                try { createNotificationForAdmins('Teacher Added', 'Teacher "' . $fullName . '" (' . $empNo . ') was added.', 'info', 'teachers.php'); } catch (Exception $e) {}

                // credentials ride SMTP when a real mailbox was given — the add never fails on mail
                $mailNote = '';
                try {
                    $m = sendCredentialsEmail($email, $fullName, 'Teacher', $uname, $pwd);
                    $mailNote = $m['success'] ? ' Credentials emailed to ' . $email . '.'
                              : ($email !== '' ? ' (Email not sent: ' . $m['message'] . ')' : '');
                } catch (Throwable $e) { error_log('teachers.php cred mail: ' . $e->getMessage()); }
                jsonOk(['message' => 'Teacher added. Login: ' . $uname . ' / ' . $pwd . $mailNote]);

            // ---------------- edit: both tables in ONE transaction, password untouched
            case 'updateTeacher':
                requireCsrfJson();
                requirePermJson('teachers', 'e');

                $tid = tchOwn('teachers', $_POST['id'] ?? 0);            // another school's teacher -> "not found"
                if ($tid <= 0) jsonErr('Invalid teacher');

                $ct = 'i';
                $cp = [$tid];
                $cur = qOne("SELECT t.id, t.user_id, t.employee_no, u.username, u.profile_image
                             FROM teachers t JOIN users u ON u.id = t.user_id
                             WHERE t.id = ?" . tchSchoolAnd($ct, $cp, 't', true), $ct, ...$cp);
                if (!$cur) jsonErr('Teacher not found');

                $fullName = tchClean($_POST['full_name'] ?? '', 100);
                $empNo    = tchClean($_POST['employee_no'] ?? '', 30);
                $uname    = tchClean($_POST['username'] ?? '', 50);
                $email    = tchClean($_POST['email'] ?? '', 100);
                $phone    = tchClean($_POST['phone'] ?? '', 20);
                $qual     = tchClean($_POST['qualification'] ?? '', 150);
                $desig    = tchClean($_POST['designation'] ?? '', 100);
                $nid      = tchClean($_POST['national_id'] ?? '', 30);
                $emerg    = tchClean($_POST['emergency_contact'] ?? '', 20);
                $remarks  = tchClean($_POST['remarks'] ?? '', 255);
                $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
                $join     = tchDate($_POST['joining_date'] ?? '');
                // back to Active -> the leaving date is cleared, not kept
                $leave    = $status === 'Inactive' ? tchDate($_POST['leaving_date'] ?? '') : null;

                if ($fullName === '' || $empNo === '') jsonErr('Full name and employee number are required');
                if ($join === false)  jsonErr('Joining date is not a valid date');
                if ($leave === false) jsonErr('Leaving date is not a valid date');
                if ($leave && $join && $leave < $join) jsonErr('Leaving date cannot be before the joining date');
                if ($uname === '') $uname = $cur['username'];
                if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $uname)) jsonErr('Username must be 3-50 chars: letters, numbers, _ . - only');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Invalid email format');

                // uniqueness is per school, same as the add path
                $uid = (int)$cur['user_id'];
                $nt = 'si';
                $np = [$uname, $uid];
                if (qVal("SELECT id FROM users WHERE username = ? AND id != ?" . tchSchoolAnd($nt, $np), $nt, ...$np)) {
                    jsonErr('Username "' . $uname . '" is already taken');
                }
                $et = 'si';
                $ep = [$empNo, $tid];
                if (qVal("SELECT id FROM teachers WHERE employee_no = ? AND id != ?" . tchSchoolAnd($et, $ep), $et, ...$ep)) {
                    jsonErr('Employee number "' . $empNo . '" already exists');
                }

                $up = tchSavePhoto($_FILES['photo'] ?? []);
                if (!$up['ok']) jsonErr($up['msg']);
                $photo = $up['path'];
                $old   = $cur['profile_image'];

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // status drives the login flag too — an inactive teacher shouldn't keep an active account
                    $active = $status === 'Active' ? 1 : 0;
                    // every write re-states the tenant — an id alone is never authority to update a row.
                    // school only, no branch: branch_id is nullable and a null would silently no-op the write
                    $wt  = '';
                    $wp  = [];
                    $own = tchSchoolAnd($wt, $wp);
                    if ($photo) {
                        qExec("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, profile_image = ?, is_active = ? WHERE id = ?$own",
                            'sssssii' . $wt, $uname, $fullName, $email, $phone, $photo, $active, $uid, ...$wp);
                    } else {
                        qExec("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, is_active = ? WHERE id = ?$own",
                            'ssssii' . $wt, $uname, $fullName, $email, $phone, $active, $uid, ...$wp);
                    }
                    // types: 9x s (emp..status), i updated_by, i id, + tenant tail
                    qExec("UPDATE teachers SET employee_no = ?, qualification = ?, designation = ?, national_id = ?,
                                  joining_date = ?, leaving_date = ?, emergency_contact = ?, remarks = ?, status = ?,
                                  updated_by = ? WHERE id = ?$own",
                        'sssssssssii' . $wt, $empNo, $qual, $desig, $nid, $join, $leave, $emerg, $remarks, $status, $actor, $tid, ...$wp);

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();                                     // both tables stay in step
                    tchDropPhoto($photo);
                    error_log('teachers.php updateTeacher: ' . $e->getMessage());
                    jsonErr('Could not update the teacher. Username or employee number may already be in use.');
                }

                if ($photo) tchDropPhoto($old);                            // committed — safe to drop the old file
                logActivity($user_id, $username, 'Teacher Updated', "Updated teacher: $fullName ($empNo) [$status]");
                jsonOk(['message' => 'Teacher updated successfully']);

            // ---------------- reset to the configured default password
            case 'resetTeacherPassword':
                requireCsrfJson();
                requirePermJson('teachers', 'e');

                $tid = tchOwn('teachers', $_POST['id'] ?? 0);
                $rt  = 'i';
                $rp  = [$tid];
                $t = $tid > 0 ? qOne("SELECT t.user_id, t.employee_no, u.username, u.full_name
                                      FROM teachers t JOIN users u ON u.id = t.user_id
                                      WHERE t.id = ?" . tchSchoolAnd($rt, $rp, 't', true), $rt, ...$rp) : null;
                if (!$t) jsonErr('Teacher not found');

                $pwd = (string)getSetting('teacher_default_password', 'teacher123');
                $wt  = '';
                $wp  = [];
                $own = tchSchoolAnd($wt, $wp);
                qExec("UPDATE users SET password = ? WHERE id = ?$own", 'si' . $wt,
                      password_hash($pwd, PASSWORD_DEFAULT), (int)$t['user_id'], ...$wp);

                logActivity($user_id, $username, 'Teacher Password Reset', "Reset password for {$t['full_name']} ({$t['employee_no']}), login {$t['username']}");
                try { createNotification((int)$t['user_id'], 'Password Reset', 'An administrator reset your password to the default. Please change it after logging in.', 'warning', 'account.php'); } catch (Exception $e) {}
                jsonOk(['message' => 'Password reset. Login: ' . $t['username'] . ' / ' . $pwd]);

            // ---------------- delete: blocked while marks history depends on them
            case 'deleteTeacher':
                requireCsrfJson();
                requirePermJson('teachers', 'd');

                $tid = tchOwn('teachers', $_POST['id'] ?? 0);
                $dt  = 'i';
                $dp  = [$tid];
                $t = $tid > 0 ? qOne("SELECT t.id, t.user_id, t.employee_no, u.full_name, u.profile_image
                                      FROM teachers t JOIN users u ON u.id = t.user_id
                                      WHERE t.id = ?" . tchSchoolAnd($dt, $dp, 't', true), $dt, ...$dp) : null;
                if (!$t) jsonErr('Teacher not found');

                $uid = (int)$t['user_id'];
                if ($uid === (int)$_SESSION['user_id']) jsonErr('You cannot delete your own account');

                // marks they typed (entered_by/updated_by hold the login user id)
                $entered = (int)qVal("SELECT COUNT(*) FROM marks WHERE entered_by = ? OR updated_by = ?", 'ii', $uid, $uid);

                // marks already recorded against any section+subject they hold
                $held = (int)qVal(
                    "SELECT COUNT(*) FROM marks m
                     JOIN teacher_subjects ts
                       ON ts.section_id = m.section_id AND ts.subject_id = m.subject_id AND ts.academic_year_id = m.academic_year_id
                     WHERE ts.teacher_id = ?", 'i', $tid);

                if ($entered || $held) {
                    $bits = [];
                    if ($entered) $bits[] = "$entered marks entry(s) recorded by them";
                    if ($held)    $bits[] = "$held marks row(s) under subjects they are assigned to";
                    jsonErr('Cannot delete ' . $t['full_name'] . ' — ' . implode(' and ', $bits) .
                            '. Set their status to Inactive instead so the marks history stays intact.');
                }

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    $wt  = '';
                    $wp  = [];
                    $own = tchSchoolAnd($wt, $wp);
                    qExec("DELETE FROM users WHERE id = ?$own", 'i' . $wt, $uid, ...$wp);   // cascades to teachers + teacher_subjects
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('teachers.php deleteTeacher: ' . $e->getMessage());
                    jsonErr('Could not delete the teacher — related records still reference them.');
                }

                tchDropPhoto($t['profile_image']);
                logActivity($user_id, $username, 'Teacher Deleted', "Deleted teacher: {$t['full_name']} ({$t['employee_no']}) and their login");
                jsonOk(['message' => 'Teacher deleted successfully']);

            // ---------------- csv import: validate + dedup, one txn, skip-and-report
            case 'bulkImportTeachers':
                requireCsrfJson();
                requirePermJson('teachers', 'a');

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($rows) || !$rows) jsonErr('Nothing to import');
                if (count($rows) > 500)         jsonErr('Please import 500 rows or fewer at a time');

                // taken maps — ONE query each, then O(1) per row. per school, like addTeacher
                $takenUser = $takenEmp = [];
                $ut = ''; $up = [];
                foreach (qAll("SELECT username FROM users WHERE 1 = 1" . tchSchoolAnd($ut, $up), $ut, ...$up) as $r) {
                    $takenUser[mb_strtolower($r['username'])] = 1;
                }
                $et = ''; $ep = [];
                foreach (qAll("SELECT employee_no FROM teachers WHERE 1 = 1" . tchSchoolAnd($et, $ep), $et, ...$ep) as $r) {
                    $takenEmp[mb_strtolower($r['employee_no'])] = 1;
                }

                $accepted = $errors = [];

                // pass 1 — validate + dedup against the db AND inside the batch, never fail-fast
                foreach ($rows as $i => $r) {
                    $line = $i + 2;                                   // +1 header, +1 human numbering
                    if (!is_array($r)) { $errors[] = "Fila $line: formato incorrecto"; continue; }
                    $get = static fn(int $n): string => trim((string)($r[$n] ?? ''));

                    $name  = tchClean($get(0), 100);
                    $emp   = tchClean($get(1), 30);
                    $uname = tchClean($get(2), 50);
                    $email = tchClean($get(3), 100);
                    $phone = tchClean($get(4), 20);
                    $qual  = tchClean($get(5), 150);
                    $desig = tchClean($get(6), 100);
                    $nid   = tchClean($get(7), 30);
                    $join  = $get(8);
                    $emerg = tchClean($get(9), 20);
                    $rmk   = tchClean($get(10), 255);

                    if ($name === '' && $emp === '') continue;        // blank trailing line
                    if ($name === '') { $errors[] = "Fila $line: el nombre completo es obligatorio"; continue; }
                    if ($emp  === '') { $errors[] = "Fila $line: el número de empleado o documento es obligatorio"; continue; }
                    if ($uname === '') $uname = $emp;                 // employee_no doubles as the login
                    if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $uname)) { $errors[] = "Fila $line: el usuario \"$uname\" debe tener entre 3 y 50 caracteres (letras, números, _ . -)"; continue; }
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Fila $line: el correo \"$email\" no es válido"; continue; }
                    $join = tchDate($join);
                    if ($join === false) { $errors[] = "Fila $line: la fecha de ingreso debe ser AAAA-MM-DD o DD/MM/AAAA"; continue; }

                    if (isset($takenEmp[mb_strtolower($emp)]))    { $errors[] = "Fila $line: el número de empleado \"$emp\" ya existe"; continue; }
                    if (isset($takenUser[mb_strtolower($uname)])) { $errors[] = "Fila $line: el usuario \"$uname\" ya está en uso"; continue; }
                    $takenEmp[mb_strtolower($emp)] = $takenUser[mb_strtolower($uname)] = 1;   // reserve inside the batch too

                    $accepted[] = ['name' => $name, 'emp' => $emp, 'uname' => $uname, 'email' => $email, 'phone' => $phone,
                                   'qual' => $qual, 'desig' => $desig, 'nid' => $nid, 'join' => $join, 'emerg' => $emerg, 'rmk' => $rmk];
                }

                $imported = 0;
                // Plan seats decide how much of this batch may land. Capped AFTER validation so the rows
                // that fit still import and the overflow returns as ordinary skips — bulk import is the
                // obvious way to walk straight past a per-add limit, so it meets the same ceiling.
                $room = ormsQuotaRoom('teachers', count($accepted));
                if ($room < count($accepted)) {
                    $qq = ormsQuota('teachers');
                    foreach (array_slice($accepted, $room) as $ov) {
                        $errors[] = 'Omitido ' . ($ov['name'] ?? 'fila') . ': límite del plan alcanzado (' . $qq['cap'] . ' ' . strtolower($qq['label']) . 's)';
                    }
                    $accepted = array_slice($accepted, 0, $room);
                }

                if ($accepted) {
                    $pwd  = (string)getSetting('teacher_default_password', 'teacher123');
                    $hash = password_hash($pwd, PASSWORD_DEFAULT);
                    [$tc, $tv, $tt, $tp] = tchStamp();               // both rows carry the tenant stamp

                    $conn = getDBConnection();
                    $conn->begin_transaction();
                    try {
                        // one prepared statement per table, executed in a loop — never a query per row
                        $uStmt = $conn->prepare("INSERT INTO users (username, password, email, role, is_active, email_verified, must_change_password, full_name, phone, profile_image$tc)
                                                 VALUES (?, ?, ?, 'Teacher', 1, 1, 1, ?, ?, NULL$tv)");
                        $sStmt = $conn->prepare("INSERT INTO teachers (user_id, employee_no, qualification, designation, national_id,
                                                                       joining_date, leaving_date, emergency_contact, remarks, status, created_by$tc)
                                                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 'Active', ?$tv)");
                        $uU = $uE = $uN = $uP = '';
                        $sUid = 0; $sEmp = $sQual = $sDesig = $sNid = $sEmerg = $sRmk = ''; $sJoin = null;
                        // bind_param reads its args at execute time, so the tenant binds go in as refs too
                        $uArgs = ['sssss' . $tt, &$uU, &$hash, &$uE, &$uN, &$uP];
                        $sArgs = ['isssssssi' . $tt, &$sUid, &$sEmp, &$sQual, &$sDesig, &$sNid, &$sJoin, &$sEmerg, &$sRmk, &$actor];
                        foreach ($tp as $k => $v) { $uArgs[] = &$tp[$k]; $sArgs[] = &$tp[$k]; }
                        call_user_func_array([$uStmt, 'bind_param'], $uArgs);
                        call_user_func_array([$sStmt, 'bind_param'], $sArgs);

                        foreach ($accepted as $a) {
                            $uU = $a['uname']; $uE = $a['email']; $uN = $a['name']; $uP = $a['phone'];
                            $uStmt->execute();
                            $sUid = $uStmt->insert_id;
                            $sEmp = $a['emp']; $sQual = $a['qual']; $sDesig = $a['desig']; $sNid = $a['nid'];
                            $sJoin = $a['join']; $sEmerg = $a['emerg']; $sRmk = $a['rmk'];
                            $sStmt->execute();
                            $imported++;
                        }
                        $uStmt->close();
                        $sStmt->close();
                        $conn->commit();
                    } catch (Throwable $e) {
                        $conn->rollback();                            // all-or-nothing, no half-imported batch
                        error_log('teachers.php import: ' . $e->getMessage());
                        jsonErr('Import failed — nothing was saved. Please check the file and try again.');
                    }
                }

                $skipped = count($errors);
                logActivity($user_id, $username, 'Teachers Imported', "Imported $imported teacher(s), skipped $skipped");
                jsonOk(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors,
                        'message'  => "$imported imported, $skipped skipped"]);

            // ---------------- assign subjects: skip-and-report clashes, insert the rest in one txn
            case 'assignSubjects':
                requireCsrfJson();
                requirePermJson('teachers', 'e');

                // EVERY id is resolved through the tenant rail FIRST. the checks below ("section belongs
                // to the class", "subject is taught in that class") all pass cleanly for another school's
                // own data, so on their own they would happily link our teacher to their section — and
                // every scope rail in the app trusts a teacher_subjects row afterwards
                $tid       = tchOwn('teachers', $_POST['teacher_id'] ?? 0);
                $yid       = tchOwn('academic_years', $_POST['year_id'] ?? 0);
                $classId   = tchOwn('classes', $_POST['class_id'] ?? 0);
                $sectionId = tchOwn('sections', $_POST['section_id'] ?? 0);
                $wanted    = array_values(array_unique(array_map('intval', (array)($_POST['subject_ids'] ?? []))));
                $wanted    = array_values(array_filter($wanted, fn($v) => $v > 0 && tchOwn('subjects', $v) > 0));

                if ($tid <= 0 || $yid <= 0 || $classId <= 0 || $sectionId <= 0) jsonErr('Teacher, year, class and section are all required');
                if (!$wanted) jsonErr('Pick at least one subject');

                $wt = 'i';
                $wp = [$tid];
                $who = qOne("SELECT t.id, u.full_name FROM teachers t JOIN users u ON u.id = t.user_id
                             WHERE t.id = ?" . tchSchoolAnd($wt, $wp, 't', true), $wt, ...$wp);
                if (!$who) jsonErr('Teacher not found');
                if (!qVal("SELECT id FROM academic_years WHERE id = ?", 'i', $yid)) jsonErr('Academic year not found');
                if (!qVal("SELECT id FROM sections WHERE id = ? AND class_id = ?", 'ii', $sectionId, $classId)) jsonErr('That section does not belong to the chosen class');

                // only subjects actually taught in this class are assignable
                $valid = [];
                foreach (qAll("SELECT cs.subject_id, s.name FROM class_subjects cs JOIN subjects s ON s.id = cs.subject_id WHERE cs.class_id = ?", 'i', $classId) as $r) {
                    $valid[(int)$r['subject_id']] = $r['name'];
                }

                // one query for everything already taken in this section+year -> o(1) clash lookup
                $taken = [];
                $kt = 'ii';
                $kp = [$yid, $sectionId];
                foreach (qAll(
                    "SELECT ts.subject_id, ts.teacher_id, u.full_name
                     FROM teacher_subjects ts
                     JOIN teachers t ON t.id = ts.teacher_id
                     JOIN users u    ON u.id = t.user_id
                     WHERE ts.academic_year_id = ? AND ts.section_id = ?" . tchSchoolAnd($kt, $kp, 't', true), $kt, ...$kp) as $r) {
                    $taken[(int)$r['subject_id']] = ['teacher_id' => (int)$r['teacher_id'], 'name' => $r['full_name']];
                }

                $accept = []; $skipped = [];
                foreach ($wanted as $sid) {
                    if (!isset($valid[$sid])) { $skipped[] = 'Subject #' . $sid . ' is not taught in this class'; continue; }
                    if (isset($taken[$sid])) {
                        $skipped[] = $taken[$sid]['teacher_id'] === $tid
                            ? $valid[$sid] . ' — already assigned to this teacher'
                            : $valid[$sid] . ' — already held by ' . $taken[$sid]['name'];
                        continue;
                    }
                    $accept[] = $sid;
                }

                if (!$accept) jsonErr('Nothing assigned. ' . implode(' · ', $skipped), ['skipped' => $skipped]);

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // one multi-row insert, values still bound. 6 ints per row incl. the assigned_by stamp
                    $params = [];
                    foreach ($accept as $sid) array_push($params, $tid, $classId, $sectionId, $sid, $yid, $actor);
                    qExec("INSERT INTO teacher_subjects (teacher_id, class_id, section_id, subject_id, academic_year_id, assigned_by) VALUES "
                        . implode(',', array_fill(0, count($accept), '(?,?,?,?,?,?)')),
                        str_repeat('iiiiii', count($accept)), ...$params);

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();                                     // all-or-nothing for the accepted batch
                    error_log('teachers.php assignSubjects: ' . $e->getMessage());
                    jsonErr('Could not save the assignments — another admin may have just taken one of these subjects.');
                }

                $names = array_map(fn($s) => $valid[$s], $accept);
                logActivity($user_id, $username, 'Subjects Assigned',
                    "Assigned " . implode(', ', $names) . " to {$who['full_name']} (section #$sectionId, year #$yid)");
                jsonOk([
                    'message' => count($accept) . ' subject(s) assigned' . ($skipped ? ', ' . count($skipped) . ' skipped' : ''),
                    'skipped' => $skipped
                ]);

            // ---------------- remove one assignment, blocked once marks exist for it
            case 'removeAssignment':
                requireCsrfJson();
                requirePermJson('teachers', 'd');

                // teacher_subjects has no school of its own — the teachers join IS the tenant check
                $aid = tchOwn('teacher_subjects', $_POST['id'] ?? 0);
                $at  = 'i';
                $ap  = [$aid];
                $a = $aid > 0 ? qOne(
                    "SELECT ts.*, u.full_name, c.name AS class_name, sec.name AS section_name, sub.name AS subject_name
                     FROM teacher_subjects ts
                     JOIN teachers t   ON t.id   = ts.teacher_id
                     JOIN users u      ON u.id   = t.user_id
                     JOIN classes c    ON c.id   = ts.class_id
                     JOIN sections sec ON sec.id = ts.section_id
                     JOIN subjects sub ON sub.id = ts.subject_id
                     WHERE ts.id = ?" . tchSchoolAnd($at, $ap, 't', true), $at, ...$ap) : null;
                if (!$a) jsonErr('Assignment not found');

                $n = (int)qVal("SELECT COUNT(*) FROM marks WHERE section_id = ? AND subject_id = ? AND academic_year_id = ?",
                    'iii', (int)$a['section_id'], (int)$a['subject_id'], (int)$a['academic_year_id']);
                if ($n) {
                    jsonErr("Cannot remove {$a['subject_name']} for {$a['class_name']} – {$a['section_name']} — $n marks row(s) already exist for it. "
                          . 'Clear those marks first, or leave the assignment in place.');
                }

                $dSql = "DELETE FROM teacher_subjects WHERE id = ?";
                $dt   = 'i';
                $dp   = [$aid];
                if (tchTenant()) {                                        // the row's owner is its teacher
                    $dSql .= " AND teacher_id IN (SELECT id FROM teachers WHERE school_id = ?)";
                    $dt   .= 'i';
                    $dp[]  = sid();
                }
                qExec($dSql, $dt, ...$dp);
                logActivity($user_id, $username, 'Assignment Removed',
                    "Removed {$a['subject_name']} ({$a['class_name']} – {$a['section_name']}) from {$a['full_name']}");
                jsonOk(['message' => 'Assignment removed']);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('teachers.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

// ---------------------------------------------------------------- page data
$tchYears = ormsYears();
$tchYear  = ormsCurrentYear();
// academic tables may not exist before setup runs — page must still render
$clsT = '';
$clsP = [];
try { $tchClasses = qAll("SELECT id, name FROM classes WHERE is_active = 1" . tchSchoolAnd($clsT, $clsP, '', true) . "
                          ORDER BY COALESCE(numeric_level, sort_order) ASC, sort_order ASC, name ASC", $clsT, ...$clsP); }
catch (Throwable $e) { $tchClasses = []; }
$subT = ''; $subP = [];
try { $tchSubjects = qAll("SELECT id, name FROM subjects WHERE is_active = 1" . tchSchoolAnd($subT, $subP, '', true) . "
                          ORDER BY name ASC", $subT, ...$subP); }
catch (Throwable $e) { $tchSubjects = []; }
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
    <title>Teachers - Online Result Management System</title>

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
                    <h1><i class="fas fa-chalkboard-teacher"></i> Teachers</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Teachers</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-table"></i> Teacher Profiles</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" id="btnRefresh" onclick="loadTeachers()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <?php if (can('teachers', 'a')): ?>
                        <button class="btn btn-success" onclick="openAddTeacher()">
                            <i class="fas fa-user-plus"></i> Add Teacher
                        </button>
                        <button class="btn btn-secondary" onclick="tchTemplate()">
                            <i class="fas fa-download"></i> Plantilla
                        </button>
                        <button class="btn btn-secondary" id="btnImportTeachers" onclick="document.getElementById('teacherCsvInput').click()">
                            <i class="fas fa-file-import"></i> Importar CSV
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-mini initially-hidden" id="statMini">
                    <div><i class="fas fa-chalkboard-teacher"></i> Total <b id="statTotal">0</b></div>
                    <div><i class="fas fa-circle-check"></i> Active <b id="statActive">0</b></div>
                    <div><i class="fas fa-circle-pause"></i> Inactive <b id="statInactive">0</b></div>
                    <div><i class="fas fa-book-open"></i> Assignments <b id="statAssign">0</b></div>
                    <div><i class="fas fa-calendar-days"></i> Year <b id="statYear">—</b></div>
                </div>

                <div id="importResult" class="initially-hidden"></div>

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
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Statuses</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-chalkboard"></i> Teaches Class</label>
                            <select id="filterClass" class="filter-input">
                                <option value="">All Classes</option>
                                <?php foreach ($tchClasses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-book"></i> Teaches Subject</label>
                            <select id="filterSubject" class="filter-input">
                                <option value="">All Subjects</option>
                                <?php foreach ($tchSubjects as $sb): ?>
                                <option value="<?php echo (int)$sb['id']; ?>"><?php echo htmlspecialchars($sb['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-clipboard-list"></i> Teaching Load</label>
                            <select id="filterLoad" class="filter-input">
                                <option value="">Any Load</option>
                                <option value="yes">Has assignments</option>
                                <option value="no">No assignments</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Skeleton (first load) -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" class="initially-hidden">
                    <div class="table-scroll-hint">
                        <i class="fas fa-circle-plus"></i> Tap a row to expand it &mdash; email, phone, qualification, joining date and HR details live there
                    </div>
                    <div class="table-responsive">
                        <table id="teachersTable" class="display chip-table"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Teacher Modal -->
    <div class="modal-overlay" id="teacherModal">
        <div class="modal modal-lg" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="tchModalTitle"><i class="fas fa-user-plus"></i> Add Teacher</h3>
                <button class="close-btn" onclick="closeTeacherModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="tchForm" enctype="multipart/form-data">
                    <input type="hidden" id="tchId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name *</label>
                            <input type="text" id="tchFullName" name="full_name" maxlength="100" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-id-badge"></i> Employee No *</label>
                            <input type="text" id="tchEmpNo" name="employee_no" maxlength="30" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-at"></i> Login Username <span class="help-text">(blank = employee no)</span></label>
                            <input type="text" id="tchUsername" name="username" maxlength="50">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="tchEmail" name="email" maxlength="100">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" id="tchPhone" name="phone" maxlength="20">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-truck-medical"></i> Emergency Contact</label>
                            <input type="text" id="tchEmergency" name="emergency_contact" maxlength="20" placeholder="Who to call in an emergency">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Designation</label>
                            <input type="text" id="tchDesig" name="designation" maxlength="100" placeholder="e.g. Senior Teacher">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-graduation-cap"></i> Qualification</label>
                            <input type="text" id="tchQual" name="qualification" maxlength="150">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> National ID / CNIC</label>
                            <input type="text" id="tchNid" name="national_id" maxlength="30">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Joining Date</label>
                            <input type="date" id="tchJoin" name="joining_date">
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status *</label>
                            <select id="tchStatus" name="status" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>

                        <!-- only meaningful once the teacher is inactive -->
                        <div class="form-group initially-hidden" id="tchLeaveGroup">
                            <label><i class="fas fa-calendar-xmark"></i> Leaving Date</label>
                            <input type="date" id="tchLeave" name="leaving_date">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Optional &middot; cleared automatically if the teacher goes Active again.</div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Remarks</label>
                            <textarea id="tchRemarks" name="remarks" maxlength="255" rows="2" placeholder="Internal note about this teacher"></textarea>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-image"></i> Profile Photo</label>
                            <input type="file" id="tchPhoto" name="photo" accept="image/jpeg,image/png,image/webp" class="file-input-styled">
                            <div class="help-text"><i class="fas fa-info-circle"></i> JPG, PNG or WEBP &middot; max 2MB &middot; leave empty to keep the current photo</div>
                        </div>
                    </div>

                    <div class="help-text" id="tchPwdHint">
                        <i class="fas fa-key"></i> New teachers get the default password from Result Settings and can log in immediately.
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="tchSaveBtn"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" onclick="closeTeacherModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Subjects Modal -->
    <div class="modal-overlay" id="assignModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-book-open"></i> Assign Subjects &mdash; <span id="asgName">Teacher</span></h3>
                <button class="close-btn" onclick="closeAssignModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <?php if (!$tchYears): ?>
                <div class="orms-empty">
                    <i class="fas fa-calendar-xmark"></i>
                    <h4>No academic year yet</h4>
                    <p>Create an academic year in Result Settings before assigning subjects.</p>
                </div>
                <?php else: ?>
                <form id="asgForm">
                    <input type="hidden" id="asgTeacherId" name="teacher_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-days"></i> Academic Year *</label>
                            <select id="asgYear" name="year_id" required>
                                <?php foreach ($tchYears as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>"<?php echo ((int)$y['is_current'] === 1 ? ' selected' : ''); ?>>
                                    <?php echo htmlspecialchars($y['name']); ?><?php echo ((int)$y['is_current'] === 1 ? ' (current)' : ''); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-chalkboard"></i> Class *</label>
                            <select id="asgClass" name="class_id" required>
                                <option value="">Select class</option>
                                <?php foreach ($tchClasses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Section *</label>
                            <select id="asgSection" name="section_id" required>
                                <option value="">Select class first</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-book"></i> Subjects *</label>
                            <select id="asgSubjects" name="subject_ids[]" multiple required></select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Only subjects taught in the selected class are listed. One teacher per subject per section.</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="asgSaveBtn"><i class="fas fa-plus"></i> Assign Selected</button>
                        <button type="button" class="btn btn-secondary" onclick="closeAssignModal()"><i class="fas fa-times"></i> Close</button>
                    </div>
                </form>

                <div class="section-header">
                    <h2><i class="fas fa-clipboard-list"></i> Current Load <span class="myr-sub" id="asgCount"></span></h2>
                </div>
                <div id="asgCurrent"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <input type="file" id="teacherCsvInput" accept=".csv,text/csv" class="initially-hidden">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="orms.js?v=2.6"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <script>
    // lazy pdf/excel deps on first export
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
        const AVATAR   = <?php echo json_encode($tchAvatar); ?>;
        const CUR_YEAR = <?php echo (int)($tchYear['id'] ?? 0); ?>;
        const esc = ORMS.esc;

        let tchTable = null, tchData = [], tchEdit = false, asgTeacher = null, asgTable = null;

        $(document).ready(function() {
            ORMS.dropdown('#filterStatus, #filterClass, #filterSubject, #filterLoad, #tchStatus, #asgYear, #asgClass, #asgSection');
            ORMS.multiselect('#asgSubjects');
            loadTeachers();
        });

        // ---------------------------------------------------------------- list

        function loadTeachers() {
            ORMS.post('getTeachers', {}, { method: 'GET' })
                .done(function(res) {
                    if (!res.success) { ORMS.err(res.message || 'Failed to load teachers'); return; }
                    tchData = res.data || [];
                    // class toggle, not .show() — .stat-mini is flex and jquery would force display:block
                    $('#loadingSkeleton').addClass('initially-hidden');
                    $('#tableContainer, #filtersSection, #statMini').removeClass('initially-hidden');
                    paintStats(res.year || '');
                    buildTable(tchData);
                })
                .fail(function(msg) { ORMS.err(msg || 'Could not reach the server'); });
        }

        function paintStats(yearName) {
            var active = 0, load = 0;
            tchData.forEach(function(t) {
                if (t.status === 'Active') active++;
                load += (t.chips || []).length;
            });
            $('#statTotal').text(tchData.length);
            $('#statActive').text(active);
            $('#statInactive').text(tchData.length - active);
            $('#statAssign').text(load);
            $('#statYear').text(yearName || '—');
        }

        function personCell(row) {
            return '<div class="marks-student">'
                 +   '<img class="marks-student-photo" src="' + esc(row.photo || AVATAR) + '" alt="">'
                 +   '<span>'
                 +     '<span class="marks-student-name">' + esc(row.full_name) + '</span>'
                 +     '<span class="marks-student-roll">' + esc(row.username) + '</span>'
                 +   '</span>'
                 + '</div>';
        }

        function hrText(row) {
            return [row.national_id, row.emergency_contact, row.leaving_txt ? 'Left ' + row.leaving_txt : '', row.remarks]
                .filter(Boolean).join(' · ');
        }

        function loadCell(row) {
            var chips = row.chips || [];
            if (!chips.length) return '<span class="text-muted">No subjects assigned</span>';
            return '<div class="chip-wrap">' + chips.map(function(c) {
                return '<span class="subject-chip"><i class="fas fa-book"></i> ' + esc(c) + '</span>';
            }).join('') + '</div>';
        }

        // ---- chip cells ----
        var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box, DASH = ORMS.DASH;
        function tv(x) { return (x === null || x === undefined || x === '') ? DASH : esc(x); }

        function cellTeacher(r) {
            return K([
                personCell(r),
                R(C('chip-navy', 'fa-id-badge', 'Emp No'), BOX(r.employee_no || '—')),
                R(C('chip-soft-navy', 'fa-user-tie', 'Designation'), tv(r.designation))
            ]);
        }

        function cellTchContact(r) {
            return K([
                R(C('chip-soft-navy', 'fa-envelope', 'Email'), tv(r.email)),
                R(C('chip-soft-navy', 'fa-phone', 'Phone'), tv(r.phone)),
                R(C('chip-soft-tan', 'fa-truck-medical', 'Emergency'), tv(r.emergency_contact))
            ]);
        }

        function cellTchJob(r) {
            return K([
                R(C('chip-soft-purple', 'fa-graduation-cap', 'Qualification'), tv(r.qualification)),
                R(C('chip-soft-purple', 'fa-calendar-day', 'Joined'), tv(r.joining_txt)),
                R(C('chip-soft-navy', 'fa-id-card', 'National ID'), tv(r.national_id)),
                r.leaving_txt ? R(C('chip-soft-amber', 'fa-calendar-xmark', 'Left'), esc(r.leaving_txt)) : ''
            ]);
        }

        function cellTchState(r) {
            var live = r.status === 'Active';
            return K([
                R(C(live ? 'chip-soft-green' : 'chip-soft-amber', 'fa-toggle-on', 'Status'),
                  '<span class="' + (live ? 'val-pos' : 'val-neg') + '">' + esc(r.status || '') + '</span>'),
                R(C('chip-soft-navy', 'fa-list-check', 'Subjects'), String((r.chips || []).length)),
                r.remarks ? R(C('chip-soft-navy', 'fa-note-sticky', 'Remarks'), esc(r.remarks)) : ''
            ]);
        }

        function tchBlob(r) {
            return [r.full_name, r.username, r.employee_no, r.designation, r.email, r.phone, r.qualification,
                    r.joining_txt, r.national_id, r.status, hrText(r)].concat(r.chips || []).filter(Boolean).join(' ');
        }

        // chip html for display, the real value for sort, a text blob for filter
        function tchCol(title, build, sortField, cls) {
            return { data: null, title: title, className: cls || '', render: function (d, t, r) {
                if (t === 'display') return build(r);
                if (t === 'filter')  return tchBlob(r);
                var v = r[sortField];
                return v === null || v === undefined ? '' : v;
            } };
        }

        function buildTable(data) {
            if (tchTable) { tchTable.destroy(); $('#teachersTable').empty(); }

            setTimeout(function() {
                var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
                tchTable = $('#teachersTable').DataTable({
                    data: data,
                    destroy: true,
                    // 12 flat columns, five of them parked in the responsive expand row where nobody
                    // ever looked. Grouped chips put the whole record on screen instead.
                    columns: [
                        tchCol('Teacher', cellTeacher, 'full_name'),
                        tchCol('Contact', cellTchContact, 'email'),
                        tchCol('Employment', cellTchJob, 'joining_date'),
                        { data: null, title: 'Teaching Load', orderable: false, render: function(d, t, row) {
                            return t === 'display' ? loadCell(row) : (row.chips || []).join(' ');
                        } },
                        tchCol('Status', cellTchState, 'status'),
                        { data: null, title: 'Actions', orderable: false, className: 'col-actions', render: function(d, t, row) {
                            if (t !== 'display') return '';
                            var btns = '';
                            <?php if (can('teachers', 'e')): ?>
                            btns += '<button class="action-icon view-icon" title="Assign subjects" onclick="openAssign(' + row.id + ')"><i class="fas fa-book-open"></i></button>';
                            btns += '<button class="action-icon edit-icon" title="Edit" onclick="editTeacher(' + row.id + ')"><i class="fas fa-edit"></i></button>';
                            btns += '<button class="action-icon loginas-icon" title="Reset password" onclick="resetPwd(' + row.id + ', this)"><i class="fas fa-key"></i></button>';
                            <?php endif; ?>
                            <?php if (can('teachers', 'd')): ?>
                            btns += '<button class="action-icon delete-icon" title="Delete" onclick="deleteTeacher(' + row.id + ', this)"><i class="fas fa-trash"></i></button>';
                            <?php endif; ?>
                            return '<div class="actions-cell">' + (btns || '<span class="text-muted">—</span>') + '</div>';
                        } }
                    ],
                    pageLength: 10,
                    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                    responsive: false,      // nothing hides any more; the wrapper scrolls instead
                    dom: 'Blfrtip',
                    buttons: [
                        { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Teachers', exportOptions: xOpts },
                        { text: '<i class="fas fa-file-pdf"></i> PDF',
                          action: function(e, dt, node, config) {
                              loadExportDeps(function() {
                                  $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config);
                              });
                          },
                          title: 'Teachers', exportOptions: xOpts },
                        { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Teachers', exportOptions: xOpts }
                    ],
                    order: [],                 // the server already orders the list
                    language: { emptyTable: 'No teachers yet — add the first one to get started' }
                });

                $('#filterStatus, #filterClass, #filterSubject, #filterLoad').off('change.tch').on('change.tch', applyFilters);
            }, 100);
        }

        // filters run off the source rows, never off rendered html
        function applyFilters() {
            if (!tchTable) return;
            $.fn.dataTable.ext.search = [];

            var status = $('#filterStatus').val(), cls = parseInt($('#filterClass').val(), 10),
                subj = parseInt($('#filterSubject').val(), 10), load = $('#filterLoad').val();

            if (status || cls || subj || load) {
                $.fn.dataTable.ext.search.push(function(settings, rowData, dataIndex) {
                    var r = tchData[dataIndex];
                    if (!r) return true;
                    if (status && r.status !== status) return false;
                    if (cls && (r.class_ids || []).indexOf(cls) === -1) return false;
                    if (subj && (r.subject_ids || []).indexOf(subj) === -1) return false;
                    if (load === 'yes' && !(r.chips || []).length) return false;
                    if (load === 'no'  &&  (r.chips || []).length) return false;
                    return true;
                });
            }
            tchTable.draw();
        }

        function clearFilters() {
            $('#filterStatus, #filterClass, #filterSubject, #filterLoad').val('').trigger('change');
            ORMS.dropdown.refresh('#filterStatus, #filterClass, #filterSubject, #filterLoad');
            $.fn.dataTable.ext.search = [];
            if (tchTable) tchTable.draw();
        }

        function rowById(id) { return tchData.filter(function(t) { return t.id === id; })[0] || null; }

        // ---------------------------------------------------------------- teacher modal

        // leaving date only belongs to an inactive teacher — going Active empties it
        function tchLeaveSync() {
            var off = $('#tchStatus').val() === 'Inactive';
            $('#tchLeaveGroup').toggleClass('initially-hidden', !off);
            if (!off) $('#tchLeave').val('');
        }
        $(document).on('change', '#tchStatus', tchLeaveSync);

        function openAddTeacher() {
            tchEdit = false;
            $('#tchModalTitle').html('<i class="fas fa-user-plus"></i> Add Teacher');
            document.getElementById('tchForm').reset();
            $('#tchId').val('');
            $('#tchStatus').val('Active').trigger('change');
            $('#tchPwdHint').removeClass('initially-hidden');
            $('#teacherModal').addClass('active');
        }

        function editTeacher(id) {
            var t = rowById(id);
            if (!t) return;
            tchEdit = true;
            $('#tchModalTitle').html('<i class="fas fa-edit"></i> Edit Teacher');
            document.getElementById('tchForm').reset();
            $('#tchId').val(t.id);
            $('#tchFullName').val(t.full_name);
            $('#tchEmpNo').val(t.employee_no);
            $('#tchUsername').val(t.username);
            $('#tchEmail').val(t.email || '');
            $('#tchPhone').val(t.phone || '');
            $('#tchEmergency').val(t.emergency_contact || '');
            $('#tchDesig').val(t.designation || '');
            $('#tchQual').val(t.qualification || '');
            $('#tchNid').val(t.national_id || '');
            $('#tchRemarks').val(t.remarks || '');
            $('#tchJoin').val(t.joining_date || '');
            $('#tchStatus').val(t.status).trigger('change');   // repaints dd + toggles the leaving field
            $('#tchLeave').val(t.status === 'Inactive' ? (t.leaving_date || '') : '');
            $('#tchPwdHint').addClass('initially-hidden'); // password is never touched from this form
            $('#teacherModal').addClass('active');
        }

        function closeTeacherModal() {
            $('#teacherModal').removeClass('active');
            document.getElementById('tchForm').reset();
        }

        document.getElementById('teacherModal').addEventListener('click', function(e) {
            if (e.target === this) closeTeacherModal();
        });

        document.getElementById('tchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('tchSaveBtn');
            ORMS.post(tchEdit ? 'updateTeacher' : 'addTeacher', new FormData(this), { btn: btn, busyLabel: 'Saving…' })
                .done(function(res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    closeTeacherModal();
                    ORMS.ok(res.message || 'Saved');
                    loadTeachers();
                })
                .fail(function(msg) { ORMS.err(msg); });
        });

        function resetPwd(id, btn) {
            var t = rowById(id);
            if (!t) return;
            Swal.fire({
                icon: 'question',
                title: 'Reset password?',
                text: t.full_name + ' will get the default teacher password from Result Settings.',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-key"></i> Reset',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel'
            }).then(function(r) {
                if (!r.isConfirmed) return;
                ORMS.post('resetTeacherPassword', { id: id }, { btn: btn, busyLabel: 'Resetting…' })
                    .done(function(res) { res.success ? ORMS.ok(res.message) : ORMS.err(res.message); })
                    .fail(function(msg) { ORMS.err(msg); });
            });
        }

        function deleteTeacher(id, btn) {
            var t = rowById(id);
            if (!t) return;
            ORMS.confirmDelete('Deleting ' + t.full_name + ' also removes their login and every subject assignment.', 'Delete teacher?')
                .then(function(yes) {
                    if (!yes) return;
                    ORMS.post('deleteTeacher', { id: id }, { btn: btn, busyLabel: 'Deleting…' })
                        .done(function(res) {
                            if (!res.success) { ORMS.err(res.message, 'Delete blocked'); return; }
                            ORMS.ok(res.message);
                            loadTeachers();
                        })
                        .fail(function(msg) { ORMS.err(msg); });
                });
        }

        // ---------------------------------------------------------------- assign subjects

        function openAssign(id) {
            var t = rowById(id);
            if (!t) return;
            asgTeacher = t;
            $('#asgName').text(t.full_name);
            if (!document.getElementById('asgForm')) { $('#assignModal').addClass('active'); return; }

            $('#asgTeacherId').val(t.id);
            if (CUR_YEAR) $('#asgYear').val(String(CUR_YEAR));
            $('#asgClass').val('');
            $('#asgSection').empty().append('<option value="">Select class first</option>');
            $('#asgSubjects').empty();
            ORMS.dropdown.refresh('#asgYear, #asgClass, #asgSection');
            ORMS.multiselect.refresh('#asgSubjects');
            $('#assignModal').addClass('active');
            loadAssignments();
        }

        function closeAssignModal() {
            $('#assignModal').removeClass('active');
            asgTeacher = null;
        }

        document.getElementById('assignModal').addEventListener('click', function(e) {
            if (e.target === this) closeAssignModal();
        });

        // class -> sections + class subjects (one round trip)
        $(document).on('change', '#asgClass', function() {
            var classId = parseInt(this.value, 10) || 0;
            $('#asgSection').empty().append('<option value="">' + (classId ? 'Select section' : 'Select class first') + '</option>');
            $('#asgSubjects').empty();
            ORMS.dropdown.refresh('#asgSection');
            ORMS.multiselect.refresh('#asgSubjects');
            if (!classId) return;

            ORMS.post('getClassMeta', { class_id: classId }, { method: 'GET' })
                .done(function(res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    var $sec = $('#asgSection'), $sub = $('#asgSubjects');
                    (res.sections || []).forEach(function(s) {
                        $sec.append($('<option>').val(s.id).text(s.name));
                    });
                    if (!(res.sections || []).length) $sec.append($('<option>').val('').text('No sections in this class'));
                    (res.subjects || []).forEach(function(s) {
                        $sub.append($('<option>').val(s.id).text(s.name + (s.code ? ' (' + s.code + ')' : '')));
                    });
                    ORMS.dropdown.refresh('#asgSection');       // repopulated by ajax -> must repaint
                    ORMS.multiselect.refresh('#asgSubjects');
                })
                .fail(function(msg) { ORMS.err(msg); });
        });

        $(document).on('change', '#asgYear', function() { loadAssignments(); });

        function loadAssignments() {
            if (!asgTeacher) return;
            var yearId = parseInt($('#asgYear').val(), 10) || CUR_YEAR;
            // kill the instance before its <table> is wiped, else dt keeps a handle on detached nodes
            if (asgTable) { asgTable.destroy(); asgTable = null; }
            $('#asgCurrent').html('<div class="skeleton skeleton-text skeleton-w-80"></div>');
            $('#asgCount').text('');

            ORMS.post('getAssignments', { teacher_id: asgTeacher.id, year_id: yearId }, { method: 'GET' })
                .done(function(res) {
                    if (!res.success) { $('#asgCurrent').html(''); ORMS.err(res.message); return; }
                    paintAssignments(res.data || []);
                })
                .fail(function(msg) { $('#asgCurrent').html(''); ORMS.err(msg); });
        }

        // one searchable/sortable row per assignment. remove stays an inline onclick on purpose —
        // the modal shell swallows click bubbling, so a document-delegated handler would never fire
        function paintAssignments(rows) {
            if (asgTable) { asgTable.destroy(); asgTable = null; }

            var secs = {};
            rows.forEach(function(r) { secs[r.class_name + ' – ' + r.section_name] = 1; });
            $('#asgCount').text(rows.length
                ? '— ' + rows.length + ' subject(s) across ' + Object.keys(secs).length + ' section(s)'
                : '');

            if (!rows.length) {
                $('#asgCurrent').html('<div class="orms-empty"><i class="fas fa-book"></i>'
                    + '<h4>No subjects assigned yet</h4><p>Pick a class, section and subjects above to build this teacher\'s load.</p></div>');
                return;
            }

            $('#asgCurrent').html('<div class="table-responsive">'
                + '<table id="asgTable" class="display table-full-width"></table></div>');

            asgTable = $('#asgTable').DataTable({
                data: rows,
                destroy: true,
                columns: [
                    { data: null, title: 'Class – Section', render: function(d, t, row) {
                        var txt = row.class_name + ' – ' + row.section_name;
                        return t === 'display' ? '<i class="fas fa-chalkboard text-muted"></i> <strong>' + esc(txt) + '</strong>' : txt;
                    } },
                    { data: 'subject_name', title: 'Subject', render: function(d) {
                        return '<i class="fas fa-book text-muted"></i> ' + esc(d);
                    } },
                    { data: 'subject_code', title: 'Code', render: function(d) {
                        return d ? '<span class="subject-chip">' + esc(d) + '</span>' : '<span class="text-muted">—</span>';
                    } },
                    { data: 'total_marks', title: 'Total', render: function(d) {
                        return d ? esc(ORMS.money(d, 0)) : '<span class="text-muted">—</span>';
                    } },
                    { data: 'passing_marks', title: 'Passing', render: function(d) {
                        return d ? esc(ORMS.money(d, 0)) : '<span class="text-muted">—</span>';
                    } },
                    { data: null, title: 'Actions', orderable: false, render: function(d, t, row) {
                        return '<button class="action-icon delete-icon" title="Remove assignment"'
                             + ' onclick="removeAssignment(' + row.id + ', this)"><i class="fas fa-trash"></i></button>';
                    } }
                ],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                responsive: true,
                dom: 'lfrtip',
                order: [[0, 'asc'], [1, 'asc']]
            });
            asgTable.columns.adjust();      // built inside a modal that just opened
        }

        document.getElementById('assignModal').addEventListener('submit', function(e) {
            if (e.target.id !== 'asgForm') return;
            e.preventDefault();
            var btn = document.getElementById('asgSaveBtn');
            ORMS.post('assignSubjects', $('#asgForm').serialize(), { btn: btn, busyLabel: 'Assigning…' })
                .done(function(res) {
                    var skipped = res.skipped || [];
                    if (!res.success) { ORMS.err(res.message, 'Nothing assigned'); return; }
                    if (skipped.length) {
                        Swal.fire({
                            icon: 'warning',
                            title: res.message,
                            html: '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr><th>Skipped</th></tr></thead><tbody>'
                                + skipped.map(function(s) { return '<tr><td>' + esc(s) + '</td></tr>'; }).join('')
                                + '</tbody></table></div>'
                        });
                    } else {
                        ORMS.ok(res.message);
                    }
                    $('#asgSubjects').val([]).trigger('change');
                    ORMS.multiselect.refresh('#asgSubjects');
                    loadAssignments();
                    loadTeachers();
                })
                .fail(function(msg) { ORMS.err(msg); });
        });

        function removeAssignment(id, chip) {
            ORMS.confirmDelete('This subject will no longer be taught by this teacher in that section.', 'Remove assignment?')
                .then(function(yes) {
                    if (!yes) return;
                    // confirm first, then the row's own busy state
                    ORMS.post('removeAssignment', { id: id }, { btn: chip, busyLabel: ' ' })
                        .done(function(res) {
                            if (!res.success) { ORMS.err(res.message, 'Removal blocked'); return; }
                            ORMS.ok(res.message);
                            loadAssignments();
                            loadTeachers();
                        })
                        .fail(function(msg) { ORMS.err(msg); });
                });
        }

        // ---------- csv template + import ----------
        var TCH_CSV_HEAD_ES = [
            'nombre_completo', 'numero_empleado', 'usuario', 'correo', 'telefono',
            'titulo_academico', 'cargo', 'documento_identidad', 'fecha_ingreso',
            'contacto_emergencia', 'observaciones'
        ];
        var TCH_CSV_HEAD_EN = [
            'full_name', 'employee_no', 'username', 'email', 'phone',
            'qualification', 'designation', 'national_id', 'joining_date',
            'emergency_contact', 'remarks'
        ];

        function tchCleanHead(s) {
            return String(s || '').trim().toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9_]/g, '_');
        }

        function tchHeaderMatches(head) {
            if (!head || !head.length) return false;
            var hClean = head.map(tchCleanHead);
            var esClean = TCH_CSV_HEAD_ES.map(tchCleanHead);
            var enClean = TCH_CSV_HEAD_EN.map(tchCleanHead);
            if (hClean.length !== esClean.length) return false;
            return (hClean.join('|') === esClean.join('|')) || (hClean.join('|') === enClean.join('|'));
        }

        function tchTemplate() {
            ORMS.downloadCSV('plantilla_importar_docentes.csv', [
                TCH_CSV_HEAD_ES,
                ['Profesor Ejemplo', 'EMP-001', '', 'profesor.uno@ejemplo.com', '300-1234567', 'Licenciado en Educación',
                 'Docente Titular', '1020304050', '2026-02-01', '300-7654321', 'Docente sede principal']
            ]);
            ORMS.ok('Plantilla descargada con éxito');
        }

        document.getElementById('teacherCsvInput').addEventListener('change', function() {
            var file = this.files && this.files[0];
            var input = this;
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(ev) {
                input.value = '';
                var rows = ORMS.parseCSV(String(ev.target.result || ''));
                if (!rows.length) { ORMS.err('El archivo seleccionado está vacío'); return; }

                var head = rows[0] || [];
                if (!tchHeaderMatches(head)) {
                    ORMS.err('La fila de encabezados debe coincidir con la plantilla:<br><br><b>' + TCH_CSV_HEAD_ES.join(', ') + '</b>', 'Formato CSV incorrecto');
                    return;
                }

                var body = rows.slice(1).filter(function(r) { return (r || []).join('').trim() !== ''; });
                if (!body.length) { ORMS.err('No se encontraron filas con datos debajo del encabezado'); return; }

                Swal.fire({
                    icon: 'question', title: '¿Importar ' + body.length + ' docente(s)?',
                    html: 'Cada docente recibirá una cuenta de acceso (usuario = número de empleado si se deja vacío) con la contraseña predeterminada.<br>Las filas duplicadas o con errores serán omitidas y reportadas.',
                    showCancelButton: true, confirmButtonText: '<i class="fas fa-file-import"></i> Importar', cancelButtonText: 'Cancelar'
                }).then(function(x) {
                    if (!x.isConfirmed) return;
                    // longest write on the page — the toolbar button carries the spinner
                    ORMS.post('bulkImportTeachers', { rows: JSON.stringify(body) },
                              { btn: '#btnImportTeachers', busyLabel: 'Importando…' }).done(function(res) {
                        if (!res || !res.success) { ORMS.err((res && res.message) || 'Error en la importación'); return; }
                        tchImportReport(res);
                        loadTeachers();
                        Swal.fire({
                            icon: res.skipped ? 'warning' : 'success',
                            title: res.imported + ' importados, ' + res.skipped + ' omitidos',
                            text: res.skipped ? 'Las filas omitidas se detallan en el cuadro sobre la tabla.' : 'Todos los registros se importaron correctamente.'
                        });
                    }).fail(function(msg) { ORMS.err(msg); });
                });
            };
            reader.readAsText(file);
        });

        function tchImportReport(res) {
            var box = $('#importResult');
            if (!res.errors || !res.errors.length) {
                box.html('<div class="info-banner"><i class="fas fa-circle-check"></i> <span>' + res.imported + ' docente(s) importados correctamente sin omisiones.</span></div>').show();
                return;
            }
            var html = '<div class="info-banner info-banner-warning"><i class="fas fa-triangle-exclamation"></i> <span>' +
                res.imported + ' importado(s), ' + res.skipped + ' omitido(s) — las filas mostradas a continuación no se guardaron.</span></div>' +
                '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr><th><i class="fas fa-list-ol"></i> #</th><th><i class="fas fa-circle-exclamation"></i> Motivo</th></tr></thead><tbody>';
            res.errors.forEach(function(e, i) { html += '<tr><td>' + (i + 1) + '</td><td>' + ORMS.esc(e) + '</td></tr>'; });
            html += '</tbody></table></div>';
            box.html(html).show();
        }
    </script>
</body>
</html>
