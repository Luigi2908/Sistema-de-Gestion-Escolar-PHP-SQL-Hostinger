<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

// rbac view gate
requirePerm('branches', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'branches';

// table probe, one per request — a db that hasn't taken the tenant migration must not fatal
function brnTable(string $t): bool {
    static $seen = [];
    if (!isset($seen[$t])) {
        try { qVal("SELECT 1 FROM `$t` LIMIT 1"); $seen[$t] = true; }
        catch (Throwable $e) { $seen[$t] = false; }
    }
    return $seen[$t];
}

// column probe — branch_id lands on classes/teachers/students/users in the same migration step
function brnCol(string $t, string $c): bool {
    static $seen = [];
    $k = "$t.$c";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$c` FROM `$t` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

function brnReady(): bool { return ormsHasTenancy() && brnTable('branches'); }

// posted id -> verified row id, 0 when it belongs to another school. the fence is ALWAYS carried,
// never inferred: uniq_branch_code is (school_id, code), so a bare id is meaningless on its own
function brnOwns($raw): int {
    $id = (int) $raw;
    if ($id <= 0) return 0;
    // the platform operator is the one caller with no tenant of its own — it provisions campuses for
    // every school, so its fence is "the row exists", and each write re-reads the row's school below.
    if (ormsIsPlatform()) return (int) qVal("SELECT id FROM branches WHERE id = ?", 'i', $id);
    return (int) qVal("SELECT id FROM branches WHERE id = ? AND school_id = ?", 'ii', $id, sid());
}

// which school does this branch belong to? every write reads it off the ROW instead of the session:
// sid() is 0 for the operator, and a school posted by a tenant is never trusted in the first place
function brnSchoolOf(int $id): int {
    return (int) qVal("SELECT school_id FROM branches WHERE id = ?", 'i', $id);
}

// tolerant counter — a table that isn't there yet can't be blocking anything
function brnCount(string $sql, string $types = '', ...$p): int {
    try { return (int) qVal($sql, $types, ...$p); } catch (Throwable $e) { return 0; }
}

// how many rows still point at this branch, per label. skips tables that never gained branch_id
function brnUsage(int $id, int $school): array {
    $out = [];
    foreach (['students' => 'student(s)', 'teachers' => 'teacher(s)', 'classes' => 'class(es)', 'users' => 'user account(s)'] as $t => $label) {
        if (!brnTable($t) || !brnCol($t, 'branch_id')) continue;
        // school fence stays on even here — ids are global, the tenant is not
        $out[$label] = brnCount("SELECT COUNT(*) FROM `$t` WHERE branch_id = ? AND school_id = ?", 'ii', $id, $school);
    }
    return $out;
}

// [label => n] -> "42 student(s), 3 class(es)"
function brnBlockers(array $counts): string {
    $parts = [];
    foreach ($counts as $label => $n) if ($n > 0) $parts[] = $n . ' ' . $label;
    return implode(', ', $parts);
}

// branch code — upper, trimmed, no punctuation surprises
function brnCode(string $raw): string { return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($raw))); }

// The operator provisions campuses across every tenant: it has no school of its own, so the school
// is ALLOCATED per branch instead of inherited from the session. A tenant never sees this leg —
// its school_id is the session's, and a posted one is discarded.
$isPlat  = ormsIsPlatform();

// profile columns (city/email/head/opened/capacity/notes) arrive with update_setup.php — probe once
// so a db that hasn't taken the migration still renders and still saves the core fields
$brnProfile = brnTable('branches') && brnCol('branches', 'city');

// a Branch Admin is PINNED: their own branch is the whole page. never a filter they can widen.
$lock    = ormsBranchLock();
$canAdd  = can('branches', 'a') && !$lock;   // one branch is not a place to open more
$canEdit = can('branches', 'e');
$canDel  = can('branches', 'd') && !$lock;

// count chips drill down under each module's OWN view perm — mirrors the server gate
$drill = ['students' => can('students', 'v'), 'teachers' => can('teachers', 'v'),
          'classes'  => can('classes', 'v'),  'users'    => can('users', 'v')];

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    try {
        if (!brnReady()) jsonErr('Branches are not installed yet — run update_setup.php once, then reload this page.');
        $school = sid();
        if (!$school && !$isPlat) jsonErr('This page belongs to a school. Enter one first.');

        switch ($action) {

            // ------------------------------------------------------------ list
            case 'getBranches':
                $stu = brnTable('students') && brnCol('students', 'branch_id')
                     ? "(SELECT COUNT(*) FROM students st WHERE st.branch_id = b.id)" : "0";
                $tch = brnTable('teachers') && brnCol('teachers', 'branch_id')
                     ? "(SELECT COUNT(*) FROM teachers t WHERE t.branch_id = b.id)" : "0";
                $cls = brnTable('classes') && brnCol('classes', 'branch_id')
                     ? "(SELECT COUNT(*) FROM classes c WHERE c.branch_id = b.id)" : "0";
                $usr = brnCol('users', 'branch_id')
                     ? "(SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.school_id = b.school_id)" : "0";

                // the pin is part of the WHERE, not a client-side filter — a branch admin's query
                // can never return a row they are not allowed to see
                // profile columns are optional until update_setup.php runs — NULL keeps the row shape stable
                $prof = [];
                foreach (['city', 'email', 'head_user_id', 'opened_on', 'capacity', 'notes'] as $c)
                    $prof[] = brnCol('branches', $c) ? "b.`$c`" : "NULL AS `$c`";
                $hasHead  = brnCol('branches', 'head_user_id');
                $headSel  = $hasHead ? "hu.full_name AS head_name, hu.username AS head_username, hu.role AS head_role"
                                     : "NULL AS head_name, NULL AS head_username, NULL AS head_role";
                $headJoin = $hasHead ? "LEFT JOIN users hu ON hu.id = b.head_user_id" : "";

                // school allocation: a tenant sees its own school and nothing else. the operator sees
                // every campus it has provisioned, narrowed by the picker when one is chosen.
                $w = ''; $ty = ''; $pm = [];
                if ($isPlat) {
                    $only = (int) ($_POST['school_id'] ?? 0);
                    if ($only > 0) { $w .= " AND b.school_id = ?"; $ty .= 'i'; $pm[] = $only; }
                } else {
                    $w .= " AND b.school_id = ?"; $ty .= 'i'; $pm[] = $school;
                }
                // the pin is part of the WHERE, not a client-side filter — a branch admin's query
                // can never return a row they are not allowed to see
                if ($lock) { $w .= " AND b.id = ?"; $ty .= 'i'; $pm[] = $lock; }

                jsonOk(['data' => qAll(
                    "SELECT b.id, b.school_id, b.name, b.code, b.address, b.phone, b.is_main, b.status, b.created_at,
                            " . implode(', ', $prof) . ", $headSel,
                            sc.name AS school_name, sc.code AS school_code,
                            $stu AS students, $tch AS teachers, $cls AS classes, $usr AS users
                     FROM branches b
                     LEFT JOIN schools sc ON sc.id = b.school_id
                     $headJoin
                     WHERE 1 = 1$w
                     ORDER BY sc.name ASC, b.is_main DESC, b.name ASC",
                    $ty, ...$pm)]);

            // ------------------------------------------------------------ create / edit
            case 'saveBranch':
                requireCsrfJson();
                $raw = (int) ($_POST['id'] ?? 0);
                requirePermJson('branches', $raw ? 'e' : 'a');      // perm follows what was ASKED for
                if ($lock && !$raw)          jsonErr('A branch admin manages one branch and cannot open another');
                if ($lock && $raw !== $lock) jsonErr('Branch not found');

                $id  = $raw ? brnOwns($raw) : 0;
                if ($raw && !$id) jsonErr('Branch not found');      // never let a foreign id fall through to an insert
                $cur = $id ? brnSchoolOf($id) : 0;                  // the school it lives in RIGHT NOW

                // ---- school allocation. only the operator chooses one; a tenant is pinned to its own
                // school and whatever school_id it posts is dropped before it can reach a query.
                if ($isPlat) {
                    $school = (int) ($_POST['school_id'] ?? $cur);
                    if ($school <= 0) jsonErr('Pick the school this branch belongs to');
                    if (!qVal("SELECT id FROM schools WHERE id = ?", 'i', $school)) jsonErr('School not found');
                } else {
                    $school = sid();
                    if ($id && $cur !== $school) jsonErr('Branch not found');
                }
                if (!$raw) ormsQuotaGuard('branches', $school);     // a new campus consumes a plan slot

                $name   = trim($_POST['name'] ?? '');
                $code   = brnCode($_POST['code'] ?? '');
                $addr   = trim($_POST['address'] ?? '');
                $phone  = trim($_POST['phone'] ?? '');
                $status = trim($_POST['status'] ?? 'Active');
                $wantMain = !empty($_POST['is_main']) ? 1 : 0;

                // profile fields — all optional, all ignored on a db that hasn't taken the migration
                $city   = trim($_POST['city'] ?? '');
                $bmail  = trim($_POST['email'] ?? '');
                $headId = (int) ($_POST['head_user_id'] ?? 0);
                $opened = trim($_POST['opened_on'] ?? '');
                $cap    = (int) ($_POST['capacity'] ?? 0);
                $notes  = trim($_POST['notes'] ?? '');

                if ($name === '')           jsonErr('Branch name is required');
                if (mb_strlen($name) > 120) jsonErr('Branch name must be 120 characters or less');
                if ($code === '')           jsonErr('Branch code is required (letters, digits, - and _ only)');
                if (strlen($code) < 2 || strlen($code) > 20) jsonErr('Branch code must be between 2 and 20 characters');
                if (mb_strlen($addr) > 255) jsonErr('Address must be 255 characters or less');
                if (mb_strlen($phone) > 30) jsonErr('Phone must be 30 characters or less');
                if (!in_array($status, ['Active', 'Inactive'], true)) jsonErr('Status must be Active or Inactive');
                if (mb_strlen($city) > 80)   jsonErr('City must be 80 characters or less');
                if (mb_strlen($bmail) > 100) jsonErr('Email must be 100 characters or less');
                if ($bmail !== '' && !filter_var($bmail, FILTER_VALIDATE_EMAIL)) jsonErr('Branch email is not a valid address');
                if ($opened !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $opened)) jsonErr('Opened on must be a date (YYYY-MM-DD)');
                if ($opened > date('Y-m-d')) jsonErr('A branch cannot open in the future');
                if ($cap < 0 || $cap > 100000) jsonErr('Student capacity must be between 0 and 100000 — 0 means no cap');
                if (mb_strlen($notes) > 255) jsonErr('Notes must be 255 characters or less');
                // the in-charge is an account of the SAME school — naming someone from another tenant
                // would put a stranger's name on the branch card and leak that they exist at all
                if ($headId && !qVal("SELECT id FROM users WHERE id = ? AND school_id = ? AND is_active = 1", 'ii', $headId, $school))
                    jsonErr('The branch head must be an active account of this school');

                // ---- a campus only MOVES between schools while it is EMPTY. Its students, teachers and
                // classes each carry their own school_id, so dragging the branch alone would strand every
                // one of them in a tenant that cannot see them.
                if ($id && $cur !== $school) {
                    if ((int) qVal("SELECT is_main FROM branches WHERE id = ?", 'i', $id) === 1)
                        jsonErr('The main branch cannot be handed to another school — make another branch main first, then move this one.');
                    $held = brnBlockers(brnUsage($id, $cur));
                    if ($held !== '')
                        jsonErr('"' . $name . '" still holds ' . $held . ' and cannot be re-allocated. Move those records first, or leave the branch where it is.');
                }

                // uniq_branch_code is (school_id, code) — the probe MUST carry the school or one school's
                // "MAIN" would block every other school from ever using it
                if (qVal("SELECT id FROM branches WHERE school_id = ? AND code = ? AND id <> ?", 'isi', $school, $code, $id))
                    jsonErr('A branch with code "' . $code . '" already exists in this school');

                // fence on where the row IS, not where it is going — a move reads its own old school
                $wasMain = $id ? (int) qVal("SELECT is_main FROM branches WHERE id = ? AND school_id = ?", 'ii', $id, $cur) : 0;
                $total   = (int) qVal("SELECT COUNT(*) FROM branches WHERE school_id = ?", 'i', $school);

                // a branch admin never carries the flag either way — whatever was posted is discarded,
                // so editing their own (main) branch can neither move it nor trip the "always one main" guard
                if ($lock) $wantMain = $wasMain;
                // the very first branch of a school is the main one whether the form said so or not,
                // and a campus arriving in an empty school is that school's first branch
                if ((!$id || $cur !== $school) && $total === 0) $wantMain = 1;
                // exactly one main per school, and it is the school's default — it can never sit Inactive
                if ($wasMain && !$wantMain) jsonErr('A school always has one main branch. Mark another branch as main instead of clearing this one.');
                if ($wantMain && $status !== 'Active') jsonErr('The main branch must stay Active — it is where new records land by default');

                $addr  = $addr  === '' ? null : $addr;
                $phone = $phone === '' ? null : $phone;

                // profile columns land with update_setup.php — build the write from what the db ACTUALLY
                // has, so a page opened before the migration still saves the core fields instead of fataling.
                // column names come from this fixed list, never from the request.
                $xc = []; $xt = ''; $xv = [];
                $put = static function (string $c, $v, string $t) use (&$xc, &$xt, &$xv) {
                    if (!brnCol('branches', $c)) return;
                    $xc[] = $c; $xt .= $t; $xv[] = $v;
                };
                $put('city',         $city   === '' ? null : $city,   's');
                $put('email',        $bmail  === '' ? null : $bmail,  's');
                $put('head_user_id', $headId ?: null,                 'i');
                $put('opened_on',    $opened === '' ? null : $opened, 's');
                $put('capacity',     $cap,                            'i');
                $put('notes',        $notes  === '' ? null : $notes,  's');

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // one main per school: clear the old holder BEFORE setting the new one, and fence
                    // the clear with school_id or a promotion here would demote every other school's main
                    if ($wantMain) qExec("UPDATE branches SET is_main = 0 WHERE school_id = ? AND is_main = 1 AND id <> ?", 'ii', $school, $id);

                    if ($id) {
                        // school_id rides the SET so an operator's re-allocation is one statement; the
                        // WHERE still fences on the school it is LEAVING. types: s,s,s,s,i,s,i + profile + i,i
                        $set = 'name = ?, code = ?, address = ?, phone = ?, is_main = ?, status = ?, school_id = ?';
                        foreach ($xc as $c) $set .= ", `$c` = ?";
                        qExec("UPDATE branches SET $set WHERE id = ? AND school_id = ?",
                              'ssssisi' . $xt . 'ii',
                              $name, $code, $addr, $phone, $wantMain, $status, $school, ...array_merge($xv, [$id, $cur]));
                    } else {
                        // locked re-check before the only insert that consumes a slot
                        if (ormsQuotaRoomLocked('branches', 1, $school) < 1) { $conn->rollback(); jsonErr(ormsQuotaMessage(ormsQuota('branches', $school)), ['quota_full' => true]); }
                        // types: i school, s name, s code, s addr, s phone, i main, s status + profile
                        $cols = array_merge(['school_id', 'name', 'code', 'address', 'phone', 'is_main', 'status'], $xc);
                        $id = qInsert("INSERT INTO branches (`" . implode('`, `', $cols) . "`) VALUES (" .
                                      implode(', ', array_fill(0, count($cols), '?')) . ")",
                                      'issssis' . $xt, $school, $name, $code, $addr, $phone, $wantMain, $status, ...$xv);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('branches.php saveBranch: ' . $e->getMessage());
                    jsonErr('The branch could not be saved — nothing was changed.');
                }

                $tag   = $wantMain ? ' [main]' : '';
                $moved = ($raw && $cur !== $school) ? " — re-allocated from school #$cur to school #$school" : '';
                logActivity($user_id, $username, $raw ? 'Branch Updated' : 'Branch Created',
                    ($raw ? 'Updated' : 'Created') . " branch: $name [$code] (#$id)$tag — $status$moved", 'branch', $id);
                jsonOk(['message' => 'Branch ' . ($raw ? 'updated' : 'added') . ' successfully' . ($moved ? ' and moved to its new school' : '')]);

            // ------------------------------------------------------------ move the main flag
            case 'setMainBranch':
                requireCsrfJson();
                requirePermJson('branches', 'e');
                if ($lock) jsonErr('Only a school admin can move the main branch');

                $id  = brnOwns($_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name, code, is_main, school_id FROM branches WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Branch not found');
                if ((int)$row['is_main'] === 1) jsonErr($row['name'] . ' is already the main branch');
                $school = (int) $row['school_id'];   // the operator carries no school — the ROW names it

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // clear then set, both fenced by school_id — two statements, one invariant
                    qExec("UPDATE branches SET is_main = 0 WHERE school_id = ? AND is_main = 1", 'i', $school);
                    qExec("UPDATE branches SET is_main = 1, status = 'Active' WHERE id = ? AND school_id = ?", 'ii', $id, $school);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('branches.php setMainBranch: ' . $e->getMessage());
                    jsonErr('The main branch could not be moved — nothing was changed.');
                }

                logActivity($user_id, $username, 'Branch Updated', "Main branch moved to: {$row['name']} [{$row['code']}] (#$id)", 'branch', $id);
                jsonOk(['message' => $row['name'] . ' is now the main branch']);

            // ------------------------------------------------------------ active / inactive
            case 'toggleBranch':
                requireCsrfJson();
                requirePermJson('branches', 'e');
                $id = brnOwns($_POST['id'] ?? 0);
                if ($lock && $id !== $lock) jsonErr('Branch not found');

                $row = $id ? qOne("SELECT name, code, status, is_main, school_id FROM branches WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Branch not found');
                $school = (int) $row['school_id'];   // the operator carries no school — the ROW names it

                $new = $row['status'] === 'Active' ? 'Inactive' : 'Active';
                if ($new === 'Inactive' && (int)$row['is_main'] === 1)
                    jsonErr('The main branch must stay Active — make another branch main first');

                qExec("UPDATE branches SET status = ? WHERE id = ? AND school_id = ?", 'sii', $new, $id, $school);
                logActivity($user_id, $username, 'Branch Updated', "Set branch {$row['name']} [{$row['code']}] $new", 'branch', $id);
                jsonOk(['message' => 'Branch marked ' . strtolower($new)]);

            // ------------------------------------------------------------ delete (only while unused)
            case 'deleteBranch':
                requireCsrfJson();
                requirePermJson('branches', 'd');
                if ($lock) jsonErr('A branch admin cannot delete branches');

                $id  = brnOwns($_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name, code, is_main, school_id FROM branches WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Branch not found');
                if ((int)$row['is_main'] === 1) jsonErr('The main branch cannot be deleted — make another branch main first');
                $school = (int) $row['school_id'];   // the operator carries no school — the ROW names it

                // count first — never let an FK error (or a silent orphan) reach the user
                $blocked = brnBlockers(brnUsage($id, $school));
                if ($blocked !== '')
                    jsonErr('"' . $row['name'] . '" cannot be deleted — it still holds ' . $blocked .
                            '. Set it Inactive instead: it disappears from new entries while its history stays intact.');

                qExec("DELETE FROM branches WHERE id = ? AND school_id = ?", 'ii', $id, $school);
                logActivity($user_id, $username, 'Branch Deleted', "Deleted branch: {$row['name']} [{$row['code']}] (#$id)", 'branch', $id);
                jsonOk(['message' => 'Branch deleted successfully']);

            // ------------------------------------------------------------ drill-down: who lives inside this branch
            case 'getBranchList': {
                $id = brnOwns($_POST['id'] ?? 0);
                if (!$id || ($lock && $id !== $lock)) jsonErr('Branch not found');
                $school = brnSchoolOf($id);   // the branch names its school, the session may not have one

                // each list opens under ITS module's view perm — branches alone never unlocks student data
                $kind = $_POST['kind'] ?? '';
                if (!in_array($kind, ['students', 'teachers', 'classes', 'users'], true)) jsonErr('Invalid list');
                requirePermJson($kind, 'v');   // kind doubles as the rbac page key

                $rows = [];
                if ($kind === 'students' && brnTable('students') && brnCol('students', 'branch_id')) {
                    $rows = qAll(
                        "SELECT s.id, s.admission_no, s.roll_no, s.gender, s.guardian_phone, s.status,
                                u.full_name, u.username, u.profile_image AS photo,
                                c.name AS class_name, sec.name AS section_name, ay.name AS year_name
                         FROM students s
                         JOIN users u           ON u.id   = s.user_id
                         JOIN classes c         ON c.id   = s.class_id
                         JOIN sections sec      ON sec.id = s.section_id
                         JOIN academic_years ay ON ay.id  = s.academic_year_id
                         WHERE s.branch_id = ? AND s.school_id = ?
                         ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, CAST(s.roll_no AS UNSIGNED) ASC, s.admission_no ASC",
                        'ii', $id, $school);
                } elseif ($kind === 'teachers' && brnTable('teachers') && brnCol('teachers', 'branch_id')) {
                    $rows = qAll(
                        "SELECT t.id, t.employee_no, t.qualification, t.joining_date, t.status,
                                u.full_name, u.username, u.email, u.phone,
                                (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.teacher_id = t.id) AS assignments
                         FROM teachers t
                         JOIN users u ON u.id = t.user_id
                         WHERE t.branch_id = ? AND t.school_id = ?
                         ORDER BY u.full_name ASC",
                        'ii', $id, $school);
                } elseif ($kind === 'classes' && brnTable('classes') && brnCol('classes', 'branch_id')) {
                    $rows = qAll(
                        "SELECT c.id, c.name, c.is_active,
                                (SELECT COUNT(*) FROM sections x        WHERE x.class_id  = c.id) AS sections,
                                (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) AS subjects,
                                (SELECT COUNT(*) FROM students st      WHERE st.class_id = c.id AND st.status = 'Active') AS students
                         FROM classes c
                         WHERE c.branch_id = ? AND c.school_id = ?
                         ORDER BY c.sort_order ASC, c.name ASC",
                        'ii', $id, $school);
                } elseif ($kind === 'users' && brnCol('users', 'branch_id')) {
                    $rows = qAll(
                        "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at
                         FROM users u
                         WHERE u.branch_id = ? AND u.school_id = ?
                         ORDER BY u.role ASC, u.full_name ASC",
                        'ii', $id, $school);
                }
                jsonOk(['data' => $rows]);
            }

            // ------------------------------------------------------------ who can run a campus
            // the head picker. Staff only — a branch is run by somebody with a job, never by a student
            // account, and the ladder decides that instead of a hardcoded role list.
            case 'getSchoolStaff': {
                $s = $isPlat ? (int) ($_POST['school_id'] ?? 0) : $school;
                if ($s <= 0) jsonErr('Pick a school first');
                if (!$isPlat && $s !== $school) jsonErr('School not found');   // a tenant asks about itself, nobody else
                $floor = ormsRoleRank('Student');
                $rows  = array_values(array_filter(
                    qAll("SELECT id, full_name, username, role FROM users
                          WHERE school_id = ? AND is_active = 1 ORDER BY role ASC, full_name ASC, username ASC", 'i', $s),
                    fn($r) => ormsRoleRank((string) $r['role']) < $floor));
                jsonOk(['data' => $rows]);
            }

            // ------------------------------------------------------------ one student, the whole picture
            case 'getStudent360': {
                requirePermJson('students', 'v');
                $stu360 = ormsFindStudent($_POST['id'] ?? 0);   // school fence + branch pin in one move
                if (!$stu360) jsonErr('Student not found');

                $p = qOne(
                    "SELECT s.*, u.username, u.full_name, u.email, u.profile_image AS photo, u.is_active,
                            u.email_verified, u.created_at AS member_since,
                            c.name AS class_name, sec.name AS section_name, ay.name AS year_name,
                            b.name AS branch_name, b.code AS branch_code,
                            cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                     FROM students s
                     JOIN users u           ON u.id   = s.user_id
                     JOIN classes c         ON c.id   = s.class_id
                     JOIN sections sec      ON sec.id = s.section_id
                     JOIN academic_years ay ON ay.id  = s.academic_year_id
                     LEFT JOIN branches b   ON b.id   = s.branch_id
                     LEFT JOIN users cb     ON cb.id  = s.created_by
                     LEFT JOIN users ub     ON ub.id  = s.updated_by
                     WHERE s.id = ?", 'i', $stu360);
                if (!$p) jsonErr('Student not found');

                $out = ['profile' => $p];
                try { $out['last_login'] = qVal("SELECT MAX(timestamp) FROM activity_logs WHERE user_id = ? AND action = 'Login'", 'i', (int)$p['user_id']); }
                catch (Throwable $e) { $out['last_login'] = null; }   // empty log = "never", not an error

                // each block rides its own module perm — the popup only shows what the viewer could open anyway
                if (can('results', 'v')) {
                    try {
                        $out['results'] = qAll(
                            "SELECT ay.name AS year_name, et.name AS term_name,
                                    rs.total_obtained, rs.total_max, rs.percentage, rs.grade, rs.gpa,
                                    rs.`position`, rs.result_status
                             FROM result_summaries rs
                             JOIN exam_terms et     ON et.id = rs.term_id
                             JOIN academic_years ay ON ay.id = rs.academic_year_id
                             WHERE rs.student_id = ?
                             ORDER BY ay.name ASC, et.sort_order ASC", 'i', $stu360);
                    } catch (Throwable $e) { $out['results'] = []; }
                    try {
                        $out['marks'] = qAll(
                            "SELECT et.name AS term_name, sub.name AS subject_name,
                                    m.marks_obtained, m.total_marks, m.grade, m.is_absent
                             FROM marks m
                             JOIN subjects sub  ON sub.id = m.subject_id
                             JOIN exam_terms et ON et.id  = m.term_id
                             WHERE m.student_id = ? AND m.academic_year_id = ?
                             ORDER BY et.sort_order ASC, sub.name ASC", 'ii', $stu360, (int)$p['academic_year_id']);
                    } catch (Throwable $e) { $out['marks'] = []; }
                }
                if (can('attendance', 'v')) {
                    try {
                        $out['attendance'] = qAll(
                            "SELECT ay.name AS year_name, et.name AS term_name, a.days_present, a.days_total, a.remarks
                             FROM attendance_summary a
                             JOIN exam_terms et     ON et.id = a.term_id
                             JOIN academic_years ay ON ay.id = a.academic_year_id
                             WHERE a.student_id = ?
                             ORDER BY ay.name ASC, et.sort_order ASC", 'i', $stu360);
                    } catch (Throwable $e) { $out['attendance'] = []; }
                }
                if (can('fees', 'v')) {
                    try {
                        $f = qOne("SELECT COALESCE(SUM(CASE WHEN entry_type = 'Charge'  THEN amount END), 0) AS charges,
                                          COALESCE(SUM(CASE WHEN entry_type = 'Payment' THEN amount END), 0) AS payments
                                   FROM student_fees WHERE student_id = ?", 'i', $stu360) ?: ['charges' => 0, 'payments' => 0];
                        $entries = qAll("SELECT entry_date, entry_type, description, amount, reference
                                         FROM student_fees WHERE student_id = ?
                                         ORDER BY entry_date DESC, id DESC LIMIT 10", 'i', $stu360);
                        foreach ($entries as &$fe) { $fe['amount'] = ormsMoney((float)$fe['amount']); } unset($fe);
                        // money math + formatting stay server-side
                        $out['fees'] = [
                            'charges'     => ormsMoney((float)$f['charges']),
                            'payments'    => ormsMoney((float)$f['payments']),
                            'balance'     => ormsMoney((float)$f['charges'] - (float)$f['payments']),
                            'balance_raw' => round((float)$f['charges'] - (float)$f['payments'], 2),
                            'entries'     => $entries,
                        ];
                    } catch (Throwable $e) { $out['fees'] = null; }
                }
                jsonOk($out);
            }

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('branches.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

$ready = brnReady();

// this school's own name for the header line — the operator's platform row is never shown here
$schoolName = '';
if ($ready && sid()) {
    try { $schoolName = (string) qVal("SELECT name FROM schools WHERE id = ?", 'i', sid()); } catch (Throwable $e) {}
}

// the operator's allocation picker — every tenant it has provisioned. a school admin never sees it.
$schoolList = [];
if ($ready && $isPlat) {
    try { $schoolList = qAll("SELECT id, name, code FROM schools ORDER BY name ASC"); } catch (Throwable $e) {}
}
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
    <title>Branches - Result Management</title>

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
                    <h1><i class="fas fa-code-branch"></i> Branches</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Branches</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">

                <?php if (!$ready): ?>
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>Branches are not installed yet</h4>
                    <p>Run <b>update_setup.php</b> once to create the branches table, then come back to this page.</p>
                </div>
                <?php elseif (!sid() && !$isPlat): ?>
                <div class="orms-empty">
                    <i class="fas fa-city"></i>
                    <h4>This page belongs to a school</h4>
                    <p>Branches are managed inside a school. Open <a href="schools.php"><strong>Schools</strong></a> and use <strong>Enter school</strong> first.</p>
                </div>
                <?php else: ?>

                <div class="section-header">
                    <h2><i class="fas fa-code-branch"></i> <?php echo $isPlat ? 'All Schools' : ($schoolName !== '' ? htmlspecialchars($schoolName) : 'Branches'); ?></h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" id="btnRefresh"><i class="fas fa-sync"></i> Refresh</button>
                        <?php if ($canAdd): ?>
                        <button class="btn btn-success" id="btnAddBranch"><i class="fas fa-plus"></i> Add Branch</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($lock): ?>
                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-lock"></i>
                    <span>You are a <strong>branch admin</strong> &mdash; this page shows your own branch only. Its details are yours to correct;
                          opening, deleting and moving the main branch belong to the school admin.</span>
                </div>
                <?php elseif ($isPlat): ?>
                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-tower-broadcast"></i>
                    <span>You are the <strong>App Owner</strong> &mdash; this is every school's campus list. Narrow it with the <strong>School</strong> filter, and
                          allocate a branch with the <strong>School</strong> field in the form. A campus can only be handed to another school while it is
                          <strong>empty</strong>: its students, teachers and classes each carry their own school, so moving the branch alone would strand them.</span>
                </div>
                <?php else: ?>
                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>Exactly one branch is the <strong>main</strong> one &mdash; it is where new records land by default, it always stays Active, and it cannot be deleted.
                          A branch holding students, teachers, classes or logins cannot be deleted either; set it <strong>Inactive</strong> instead.</span>
                </div>
                <?php endif; ?>

                <div class="stat-mini" id="brnStats">
                    <div><i class="fas fa-code-branch"></i> Branches <b id="statAll">0</b></div>
                    <div><i class="fas fa-circle-check"></i> Active <b id="statActive">0</b></div>
                    <?php if ($isPlat): ?><div><i class="fas fa-city"></i> Schools <b id="statSchools">0</b></div><?php endif; ?>
                    <div><i class="fas fa-user-graduate"></i> Students <b id="statStudents">0</b></div>
                    <div><i class="fas fa-chalkboard-user"></i> Teachers <b id="statTeachers">0</b></div>
                    <div><i class="fas fa-chair"></i> Seats used <b id="statSeats">&mdash;</b></div>
                </div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button class="btn btn-secondary btn-sm" id="btnClearFilters"><i class="fas fa-times-circle"></i> Clear</button>
                    </div>
                    <div class="filters-grid">
                        <?php if ($isPlat): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-city"></i> School</label>
                            <select id="filterSchool" class="filter-input">
                                <option value="">All Schools</option>
                                <?php foreach ($schoolList as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name'] . ' [' . $s['code'] . ']'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="filter-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Statuses</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-location-dot"></i> City</label>
                            <select id="filterCity" class="filter-input">
                                <option value="">All Cities</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="brnSkeleton">
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

                <div id="brnWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="brnTable" class="display chip-table"></table>
                    </div>
                </div>

                <div id="brnEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-code-branch"></i>
                    <h4>No branches yet</h4>
                    <p><?php echo $canAdd
                        ? 'Use <strong>Add Branch</strong> above to create the first one — it becomes the main branch automatically.'
                        : 'Ask a school admin to set up the branch list.'; ?></p>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($ready && ($isPlat || sid())): ?>
    <!-- Branch Modal -->
    <div class="modal-overlay" id="branchModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="branchModalTitle"><i class="fas fa-code-branch"></i> Add Branch</h3>
                <button class="close-btn" id="btnCloseBranch"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="branchForm">
                    <input type="hidden" id="branchId" name="id">
                    <div class="form-grid">
                        <?php if ($isPlat): ?>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> School *</label>
                            <select id="branchSchool" name="school_id" required>
                                <option value="">Select school…</option>
                                <?php foreach ($schoolList as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name'] . ' [' . $s['code'] . ']'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> The school this campus belongs to. It can only be changed later while the branch is still empty.</div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Branch Name *</label>
                            <input type="text" id="branchName" name="name" maxlength="120" required placeholder="e.g. City Campus">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Branch Code *</label>
                            <input type="text" id="branchCode" name="code" maxlength="20" required placeholder="e.g. CITY">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Unique inside this school only. Letters, digits, <code>-</code> and <code>_</code> &mdash; stored uppercase.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-location-dot"></i> Address</label>
                            <input type="text" id="branchAddress" name="address" maxlength="255" placeholder="Street, city">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone</label>
                            <input type="text" id="branchPhone" name="phone" maxlength="30" placeholder="+92 300 0000000">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" id="branchCity" name="city" maxlength="80" placeholder="e.g. Lahore">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="branchEmail" name="email" maxlength="100" placeholder="campus@school.edu">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Branch Head</label>
                            <select id="branchHead" name="head_user_id">
                                <option value="">Not set</option>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> The in-charge of this campus &mdash; any active staff account of the same school.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Opened On</label>
                            <input type="date" id="branchOpened" name="opened_on">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-chair"></i> Student Capacity</label>
                            <input type="number" id="branchCapacity" name="capacity" min="0" max="100000" step="1" placeholder="0">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Seats this campus can hold. <strong>0 = no cap</strong>; the plan limit still applies on top of it.</div>
                        </div>
                        <div class="form-group form-group-full">
                            <label><i class="fas fa-note-sticky"></i> Notes</label>
                            <input type="text" id="branchNotes" name="notes" maxlength="255" placeholder="Landmark, timings, anything the office needs">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Active</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="branchActive" name="status" value="Active" class="toggle-input" checked>
                                <label for="branchActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Inactive hides the branch from new entries. Its existing records stay exactly where they are.</div>
                        </div>
                        <?php if (!$lock): ?>
                        <div class="form-group">
                            <label><i class="fas fa-star"></i> Main Branch</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="branchMain" name="is_main" value="1" class="toggle-input">
                                <label for="branchMain" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Turning this on moves the flag off whichever branch holds it now &mdash; a school has exactly one.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveBranch"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelBranch"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Branch Drill-down Modal (students / teachers / classes / logins of one branch) -->
    <div class="modal-overlay" id="drillModal" role="dialog" aria-modal="true" aria-labelledby="drillTitle">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="drillTitle"><i class="fas fa-building"></i> Branch</h3>
                <button class="close-btn" id="btnCloseDrill" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="tab-nav" id="drillTabs" role="tablist"></div>
                <div class="drill-hint" id="drillHint"></div>
                <div id="drillSkeleton" class="initially-hidden">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div id="drillWrap" class="table-responsive initially-hidden">
                    <table id="drillTable" class="display table-full-width"></table>
                </div>
                <div id="drillEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-folder-open"></i>
                    <h4>Nothing here yet</h4>
                    <p id="drillEmptyMsg">This branch has no records in this list.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Student 360 Modal -->
    <div class="modal-overlay overlay-stacked" id="stu360Modal" role="dialog" aria-modal="true" aria-labelledby="stu360Title">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="stu360Title"><i class="fas fa-street-view"></i> Student 360&deg; View</h3>
                <button class="close-btn" id="btnCloseStu360" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="stu360Body"></div>
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

    <?php if ($ready && ($isPlat || sid())): ?>
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
    var brnTable_ = null, brnData = [];
    var CAN = { a: <?= $canAdd  ? 'true' : 'false' ?>,
                e: <?= $canEdit ? 'true' : 'false' ?>,
                d: <?= $canDel  ? 'true' : 'false' ?>,
                main: <?= $lock ? 'false' : 'true' ?> };   // moving the main flag is the school admin's call
    var DRILL = <?= json_encode($drill) ?>;                // which count chips may open
    var IS_PLAT     = <?= $isPlat ? 'true' : 'false' ?>;   // operator: campuses of every school, school picked per branch
    var MY_SCHOOL   = <?= (int) sid() ?>;
    var COL         = {};                                  // column title -> index, rebuilt with the table
    var staffCache  = {};                                  // school id -> head candidates, one fetch each

    $(document).ready(function() {
        ORMS.dropdown('#filterStatus, #filterCity, #filterSchool, #branchSchool, #branchHead');
        loadBranches();

        $('#btnRefresh').on('click', function() { loadBranches(this); });
        $('#btnAddBranch').on('click', openAdd);
        $('#btnCloseBranch, #btnCancelBranch').on('click', function() { close_('#branchModal'); });
        $('#branchModal').on('click', function(e) { if (e.target === this) close_(this); });
        $('#branchCode').on('input', function() { this.value = this.value.toUpperCase(); });

        $('#filterSchool').on('change', function() { loadBranches(); });   // narrows the fetch, not the draw
        $('#filterStatus').on('change', function() { colSearch(COL.status, this.value); });
        $('#filterCity').on('change', function() { colSearch(COL.city, this.value); });
        // the head list belongs to ONE school — re-fetch it whenever the allocation changes
        $('#branchSchool').on('change', function() { loadStaff(+this.value || 0, 0); });
        $('#btnClearFilters').on('click', function() {
            $('#filterStatus, #filterCity, #filterSchool').val('');
            ORMS.dropdown.refresh('#filterStatus, #filterCity, #filterSchool');
            colSearch(COL.status, '');
            colSearch(COL.city, '');
            loadBranches();
        });

        // the main branch is always Active — don't let the form offer a state the server will refuse
        $('#branchMain').on('change', function() {
            if (this.checked) $('#branchActive').prop('checked', true);
        });

        // drill-down: chips + tabs + student rows (delegated — survives table rebuilds and responsive child rows)
        $(document).on('click', '#brnTable .chip-btn', function() { openDrill(+this.dataset.id, this.dataset.kind); });
        $('#drillTabs').on('click', '.tab-btn', function() { switchDrill(this.dataset.kind); });
        $('#btnCloseDrill').on('click', function() { close_('#drillModal'); });
        $('#drillModal').on('click', function(e) { if (e.target === this) close_(this); });
        // bound INSIDE the modal — the .modal div stops click bubbling, so document delegation never fires here
        $('#drillWrap').on('click', '.btn-360', function(e) { e.stopPropagation(); openStudent360(+this.dataset.id); });
        $('#drillWrap').on('click', 'tbody tr', function(e) {
            if ($(e.target).closest('button, a').length) return;                 // buttons own their clicks
            var $tr = $(this).hasClass('child') ? $(this).prev('tr') : $(this);  // responsive detail row -> its data row
            var sid = $tr.data('sid');
            if (sid) openStudent360(+sid);
        });
        $('#btnCloseStu360').on('click', function() { close_('#stu360Modal'); });
        $('#stu360Modal').on('click', function(e) { if (e.target === this) close_(this); });
        // esc peels the top-most layer only
        $(document).on('keydown.brnDrill', function(e) {
            if (e.key !== 'Escape') return;
            if ($('#stu360Modal').hasClass('active')) { close_('#stu360Modal'); return; }
            if ($('#drillModal').hasClass('active')) close_('#drillModal');
        });
    });

    function close_(sel) { $(sel).removeClass('active'); }
    function blank(v) { return v === null || v === undefined || v === ''; }

    function colSearch(idx, val) {
        if (!brnTable_ || idx === undefined || idx < 0) return;   // column set is role-dependent
        var v = String(val || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        brnTable_.column(idx).search(val ? '^' + v + '$' : '', true, false).draw();
    }

    function countChip(icon, n) { return '<span class="subject-chip"><i class="fas fa-' + icon + '"></i> ' + (n || 0) + '</span>'; }

    var DRILL_LABEL = { students: 'students', teachers: 'teachers', classes: 'classes', users: 'login accounts' };

    // a count that holds something AND the viewer may open -> the VALUE itself becomes the button,
    // so a chip row reads "[Students] 35 ↗" instead of spending a whole column on four numbers
    function drillVal(kind, r) {
        var n = Number(r[kind]) || 0;
        if (!n) return '<span class="val-muted">0</span>';
        if (!DRILL[kind]) return String(n);
        var what = 'View ' + n + ' ' + DRILL_LABEL[kind] + ' of ' + esc(r.name);
        return '<button type="button" class="chip-btn" data-id="' + r.id + '" data-kind="' + kind + '"' +
               ' title="' + what + '" aria-label="' + what + '" aria-haspopup="dialog">' + n +
               ' <i class="fas fa-up-right-from-square chip-go"></i></button>';
    }

    function rowActions(r) {
        var b = '';
        if (CAN.e) {
            b += '<button class="action-icon edit-icon" title="Edit" onclick="editBranch(' + r.id + ')"><i class="fas fa-edit"></i></button>';
            if (r.is_main != 1) {
                b += '<button class="action-icon view-icon" title="' + (r.status === 'Active' ? 'Deactivate' : 'Activate') + '" onclick="toggleBranch(' + r.id + ', this)">' +
                     '<i class="fas fa-' + (r.status === 'Active' ? 'toggle-on' : 'toggle-off') + '"></i></button>';
            }
            if (CAN.main && r.is_main != 1) {
                b += '<button class="action-icon view-icon" title="Make this the main branch" onclick="makeMain(' + r.id + ', this)"><i class="fas fa-star"></i></button>';
            }
        }
        if (CAN.d && r.is_main != 1) {
            b += '<button class="action-icon delete-icon" title="Delete" onclick="deleteBranch(' + r.id + ', this)"><i class="fas fa-trash"></i></button>';
        }
        return b || '<span class="text-muted">&mdash;</span>';
    }

    function paintStats() {
        var act = 0, stu = 0, tch = 0, cap = 0, sch = {};
        brnData.forEach(function(r) {
            if (r.status === 'Active') act++;
            stu += Number(r.students) || 0;
            tch += Number(r.teachers) || 0;
            cap += Number(r.capacity) || 0;
            if (r.school_id) sch[r.school_id] = 1;
        });
        $('#statAll').text(brnData.length);
        $('#statActive').text(act);
        $('#statSchools').text(Object.keys(sch).length);
        $('#statStudents').text(stu);
        $('#statTeachers').text(tch);
        // uncapped campuses contribute nothing to the denominator — a dash beats a fake percentage
        $('#statSeats').html(cap ? stu + ' / ' + cap + ' <small>(' + Math.round(stu / cap * 100) + '%)</small>' : '&mdash;');
    }

    // city list is whatever the rows actually hold — no separate table, no stale options
    function paintCityFilter() {
        var cur = $('#filterCity').val() || '';
        var seen = brnData.map(function(r) { return String(r.city || '').trim(); })
                          .filter(function(c, i, a) { return c && a.indexOf(c) === i; }).sort();
        $('#filterCity').html('<option value="">All Cities</option>' + seen.map(function(c) {
            return '<option value="' + esc(c) + '"' + (c === cur ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join(''));
        ORMS.dropdown.refresh('#filterCity');
    }

    // ---- chip cells. 13 flat columns drifted the header away from the body and stacked the action
    // icons vertically; each cell now stacks [label chip] ...... value rows instead. ----
    var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box, DASH = ORMS.DASH;
    function val(x) { return blank(x) ? DASH : esc(x); }

    function cellBranch(r) {
        return K([
            '<div class="cell-title"><i class="fas fa-building"></i> ' + esc(r.name) + '</div>',
            blank(r.address) ? '' : '<div class="cell-sub">' + esc(r.address) + '</div>',
            R(C('chip-navy', 'fa-hashtag', 'Code'), BOX(r.code)),
            R(C('chip-soft-navy', 'fa-location-dot', 'City'), val(r.city)),
            R(C('chip-soft-navy', 'fa-calendar-day', 'Opened'), blank(r.opened_on) ? DASH : esc(String(r.opened_on).slice(0, 10)))
        ]);
    }

    function cellSchool(r) {
        return K([
            '<div class="cell-title"><i class="fas fa-city"></i> ' + esc(r.school_name || '—') + '</div>',
            blank(r.school_code) ? '' : R(C('chip-soft-navy', 'fa-hashtag', 'Code'), BOX(r.school_code))
        ]);
    }

    function cellContact(r) {
        var head = blank(r.head_name) ? r.head_username : r.head_name;
        return K([
            R(C('chip-soft-purple', 'fa-user-tie', 'Head'), blank(head) ? DASH : esc(head)),
            blank(r.head_role) ? '' : R(C('chip-soft-purple', 'fa-user-shield', 'Role'), esc(r.head_role)),
            R(C('chip-soft-navy', 'fa-phone', 'Phone'), val(r.phone)),
            R(C('chip-soft-navy', 'fa-envelope', 'Email'), val(r.email))
        ]);
    }

    // capacity 0 = uncapped: the headcount alone, never a division by zero
    function cellState(r) {
        var cap = Number(r.capacity) || 0, used = Number(r.students) || 0;
        var pct = cap ? Math.round(used / cap * 100) : 0;
        return K([
            R(C(r.is_main == 1 ? 'chip-green' : 'chip-soft-navy', 'fa-star', 'Main'),
              r.is_main == 1 ? '<span class="val-pos">Yes</span>' : DASH),
            R(C(r.status === 'Active' ? 'chip-soft-green' : 'chip-soft-amber', 'fa-toggle-on', 'Status'),
              '<span class="' + (r.status === 'Active' ? 'val-pos' : 'val-neg') + '">' + esc(r.status) + '</span>'),
            R(C('chip-soft-tan', 'fa-chair', 'Seats'), cap ? used + ' / ' + cap : used + ' <span class="val-muted">(no cap)</span>'),
            cap ? R(C('chip-soft-tan', 'fa-percent', 'Used'),
                    '<span class="' + (pct >= 100 ? 'val-neg' : pct >= 85 ? 'val-amount' : 'val-pos') + '">' + pct + '%</span>') : ''
        ]);
    }

    function cellPeople(r) {
        return K([
            R(C('chip-link', 'fa-user-graduate', 'Students'), drillVal('students', r)),
            R(C('chip-link', 'fa-chalkboard-user', 'Teachers'), drillVal('teachers', r)),
            R(C('chip-link', 'fa-school', 'Classes'), drillVal('classes', r)),
            R(C('chip-link', 'fa-users', 'Logins'), drillVal('users', r))
        ]);
    }

    // one searchable blob per row, so the search box still reaches a value that is now inside markup
    function blobOf(r) {
        return [r.name, r.code, r.city, r.address, r.phone, r.email, r.head_name, r.head_username,
                r.head_role, r.school_name, r.school_code, r.status].filter(Boolean).join(' ');
    }

    // THE rule for every grouped column: chip html for display, the real value for sort, a text blob
    // for filter. Miss it and sorting sorts by markup while the search box quietly stops matching.
    function gcol(title, build, sortField, cls) {
        return { data: null, title: title, className: cls || '', render: function (d, t, r) {
            if (t === 'display') return build(r);
            if (t === 'filter')  return blobOf(r);
            var v = r[sortField];
            return v === null || v === undefined ? '' : v;
        } };
    }

    // the column set differs per viewer (only the operator gets a School column), so every filter
    // looks its index up by name — a hardcoded index searches the wrong column the moment one moves
    function buildColumns() {
        var c = [gcol('Branch', cellBranch, 'name')];
        if (IS_PLAT) c.push(gcol('School', cellSchool, 'school_name'));
        c.push(gcol('Contact', cellContact, 'head_name'));
        c.push(gcol('Status & Seats', cellState, 'is_main'));
        c.push(gcol('People', cellPeople, 'students'));
        c.push({ data: null, title: 'Actions', orderable: false, className: 'col-actions',
                 render: function (d, t, r) { return t === 'display' ? '<div class="actions-cell">' + rowActions(r) + '</div>' : ''; } });
        // hidden, and the ONLY reason they exist: the Status and City dropdowns filter on an exact
        // value, which a grouped cell can no longer offer
        c.push({ data: 'status', title: 'Status', visible: false });
        c.push({ data: 'city', title: 'City', visible: false, render: function (d) { return blank(d) ? '' : d; } });
        COL = {};
        c.forEach(function (x, i) { COL[String(x.title).toLowerCase()] = i; });
        return c;
    }

    function loadBranches(btn) {
        // the school filter narrows the FETCH, not the drawn table — an operator can be holding
        // hundreds of campuses and only ever wants one school's on screen
        var q = IS_PLAT ? { school_id: $('#filterSchool').val() || 0 } : {};
        ORMS.post('getBranches', q, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load branches'); return; }
            brnData = res.data || [];
            drillCache = {};   // fresh counts -> stale drill lists
            $('#brnSkeleton').addClass('initially-hidden');
            // nothing to show -> icon+message, never a blank table body
            $('#brnWrap').toggleClass('initially-hidden', !brnData.length);
            $('#brnEmpty').toggleClass('initially-hidden', !!brnData.length);
            paintStats();
            paintCityFilter();
            if (brnTable_) { brnTable_.destroy(); $('#brnTable').empty(); }
            var cols = buildColumns();
            // exports must ship TEXT — without the formatter every cell arrives as chip markup
            var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
            brnTable_ = $('#brnTable').DataTable({
                data: brnData,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: false,          // the chips carry the density; .table-responsive scrolls sideways
                destroy: true,
                order: [[COL['status & seats'], 'desc'], [COL.branch, 'asc']],   // main branch first, then name
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
                columns: cols,
                // a campus that is switched off or out of seats should be visible without reading numbers
                rowCallback: function(row, r) {
                    row.className = row.className.replace(/\brow-(good|bad|wait)\b/g, '').trim();
                    var cap = Number(r.capacity) || 0, used = Number(r.students) || 0;
                    if (r.status !== 'Active' || (cap && used >= cap)) row.className += ' row-bad';
                    else if (cap && used / cap >= .85)                 row.className += ' row-wait';
                }
            });
            colSearch(COL.status, $('#filterStatus').val());
            colSearch(COL.city, $('#filterCity').val());
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function rowById(id) { return brnData.filter(function(x) { return x.id == id; })[0]; }

    // head candidates for ONE school. cached per school — the picker opens far more often than staff change
    function loadStaff(school, selected) {
        var fill = function(rows) {
            $('#branchHead').html('<option value="">Not set</option>' + (rows || []).map(function(u) {
                return '<option value="' + u.id + '"' + (Number(selected) === Number(u.id) ? ' selected' : '') + '>' +
                       esc((u.full_name || u.username) + ' — ' + u.role) + '</option>';
            }).join(''));
            ORMS.dropdown.refresh('#branchHead');
        };
        if (!school) { fill([]); return; }
        if (staffCache[school]) { fill(staffCache[school]); return; }
        ORMS.post('getSchoolStaff', { school_id: school }).done(function(res) {
            staffCache[school] = res.success ? (res.data || []) : [];
            fill(staffCache[school]);
        }).fail(function() { fill([]); });
    }

    function openAdd() {
        $('#branchModalTitle').html('<i class="fas fa-code-branch"></i> Add Branch');
        $('#branchForm')[0].reset();
        $('#branchId').val('');
        $('#branchActive').prop('checked', true);
        $('#branchCapacity').val(0);
        // the operator's context is whatever the School filter is sitting on; a tenant has exactly one
        var s = IS_PLAT ? (+$('#filterSchool').val() || 0) : MY_SCHOOL;
        $('#branchSchool').val(s || '');
        ORMS.dropdown.refresh('#branchSchool');
        // first branch of THAT school is always the main one — the server settles it either way
        $('#branchMain').prop('checked', brnData.filter(function(x) {
            return !IS_PLAT || Number(x.school_id) === s;
        }).length === 0);
        loadStaff(s, 0);
        $('#branchModal').addClass('active');
        setTimeout(function() { $('#branchName').trigger('focus'); }, 60);
    }

    function editBranch(id) {
        var r = rowById(id);
        if (!r) return;
        $('#branchModalTitle').html('<i class="fas fa-edit"></i> Edit Branch');
        $('#branchId').val(r.id);
        $('#branchName').val(r.name);
        $('#branchCode').val(r.code);
        $('#branchAddress').val(blank(r.address) ? '' : r.address);
        $('#branchPhone').val(blank(r.phone) ? '' : r.phone);
        $('#branchCity').val(blank(r.city) ? '' : r.city);
        $('#branchEmail').val(blank(r.email) ? '' : r.email);
        $('#branchOpened').val(blank(r.opened_on) ? '' : String(r.opened_on).slice(0, 10));
        $('#branchCapacity').val(Number(r.capacity) || 0);
        $('#branchNotes').val(blank(r.notes) ? '' : r.notes);
        $('#branchSchool').val(r.school_id || '');
        ORMS.dropdown.refresh('#branchSchool');
        loadStaff(Number(r.school_id) || MY_SCHOOL, Number(r.head_user_id) || 0);
        $('#branchActive').prop('checked', r.status === 'Active');
        $('#branchMain').prop('checked', r.is_main == 1);
        $('#branchModal').addClass('active');
    }

    $('#branchForm').on('submit', function(e) {
        e.preventDefault();
        var name = $('#branchName').val().trim(), code = $('#branchCode').val().trim();
        if (!name) { ORMS.err('Branch name is required'); return; }
        if (code.length < 2) { ORMS.err('Branch code must be at least 2 characters'); return; }

        if (IS_PLAT && !$('#branchSchool').val()) { ORMS.err('Pick the school this branch belongs to'); return; }

        ORMS.post('saveBranch', {
            id: $('#branchId').val(), name: name, code: code,
            school_id: $('#branchSchool').val() || 0,
            address: $('#branchAddress').val().trim(), phone: $('#branchPhone').val().trim(),
            city: $('#branchCity').val().trim(), email: $('#branchEmail').val().trim(),
            head_user_id: $('#branchHead').val() || 0, opened_on: $('#branchOpened').val() || '',
            capacity: $('#branchCapacity').val() || 0, notes: $('#branchNotes').val().trim(),
            status: $('#branchActive').is(':checked') ? 'Active' : 'Inactive',
            is_main: $('#branchMain').is(':checked') ? 1 : 0
        }, { btn: '#btnSaveBranch', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            close_('#branchModal');
            ORMS.ok(res.message);
            loadBranches();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    function toggleBranch(id, btn) {
        ORMS.post('toggleBranch', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
            res.success ? (ORMS.ok(res.message), loadBranches()) : ORMS.err(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function makeMain(id, btn) {
        var r = rowById(id);
        if (!r) return;
        Swal.fire({
            icon: 'question',
            title: 'Move the main branch?',
            text: '"' + r.name + '" becomes the default landing branch for new records, and the branch holding it now loses the flag.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-star"></i> Make main',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel'
        }).then(function(res) {
            if (!res.isConfirmed) return;
            ORMS.post('setMainBranch', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(r2) {
                r2.success ? (ORMS.ok(r2.message), loadBranches()) : ORMS.err(r2.message);
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    function deleteBranch(id, btn) {
        var r = rowById(id);
        ORMS.confirmDelete('Delete branch "' + (r ? r.name : '') + '"? Only an empty branch can be removed.').then(function(yes) {
            if (!yes) return;
            ORMS.post('deleteBranch', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                res.success ? (ORMS.ok(res.message), loadBranches()) : ORMS.err(res.message, 'Cannot Delete');
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    // ==================== branch drill-down ====================
    var drillDT = null, drillCache = {}, drillBranch = null, drillKind = '';
    var DRILL_META = {
        students: { icon: 'user-graduate',   tab: 'Students', hint: 'Click any student row (or the <i class="fas fa-street-view"></i> button) for the full 360&deg; view.' },
        teachers: { icon: 'chalkboard-user', tab: 'Teachers', hint: 'Every teacher whose home branch is this one.' },
        classes:  { icon: 'school',          tab: 'Classes',  hint: 'Classes of this branch with their sections, subjects and active students.' },
        users:    { icon: 'users',           tab: 'Logins',   hint: 'Every login account attached to this branch.' }
    };

    function stuBadge(s) {
        return s === 'Active'
            ? '<span class="status-badge status-active"><i class="fas fa-check"></i> Active</span>'
            : '<span class="status-badge status-inactive"><i class="fas fa-ban"></i> ' + esc(s || 'Inactive') + '</span>';
    }
    function dash(v) { return blank(v) ? '<span class="text-muted">&mdash;</span>' : esc(v); }
    function day(v) { return blank(v) ? '<span class="text-muted">&mdash;</span>' : esc(String(v).slice(0, 10)); }
    function avatarCell(photo, name) {
        return photo
            ? '<img class="drill-avatar" src="' + esc(photo) + '" alt="" loading="lazy">'
            : '<span class="drill-avatar drill-avatar-txt">' + esc((name || '?').charAt(0).toUpperCase()) + '</span>';
    }

    function openDrill(id, kind) {
        drillBranch = rowById(id);
        if (!drillBranch) return;
        // tabs: only the lists this viewer may open, live counts on each
        $('#drillTabs').html(['students', 'teachers', 'classes', 'users'].filter(function(k) { return DRILL[k]; }).map(function(k) {
            return '<button type="button" class="tab-btn" role="tab" data-kind="' + k + '">' +
                   '<i class="fas fa-' + DRILL_META[k].icon + '"></i> ' + DRILL_META[k].tab +
                   ' (' + (Number(drillBranch[k]) || 0) + ')</button>';
        }).join(''));
        $('#drillModal').addClass('active');
        switchDrill(kind);
        setTimeout(function() { $('#btnCloseDrill').trigger('focus'); }, 60);
    }

    function switchDrill(kind) {
        if (!drillBranch || !DRILL[kind]) return;
        drillKind = kind;
        $('#drillTabs .tab-btn').removeClass('active').filter('[data-kind="' + kind + '"]').addClass('active');
        $('#drillTitle').html('<i class="fas fa-building"></i> ' + esc(drillBranch.name) +
            ' <span class="subject-chip"><i class="fas fa-hashtag"></i> ' + esc(drillBranch.code) + '</span> &mdash; ' + DRILL_META[kind].tab);
        $('#drillHint').html('<i class="fas fa-circle-info"></i> <span>' + DRILL_META[kind].hint + '</span>');

        var key = drillBranch.id + ':' + kind;
        if (drillCache[key]) { renderDrill(drillCache[key]); return; }
        $('#drillWrap, #drillEmpty').addClass('initially-hidden');
        $('#drillSkeleton').removeClass('initially-hidden');
        ORMS.post('getBranchList', { id: drillBranch.id, kind: kind }).done(function(res) {
            $('#drillSkeleton').addClass('initially-hidden');
            if (!res.success) { ORMS.err(res.message || 'Failed to load'); return; }
            drillCache[key] = res.data || [];
            renderDrill(drillCache[key]);
        }).fail(function(msg) { $('#drillSkeleton').addClass('initially-hidden'); ORMS.err(msg); });
    }

    function drillCols(kind) {
        if (kind === 'students') return [
            { data: 'admission_no', title: 'Adm No', render: function(d, t) { return t === 'display' ? '<span class="subject-chip"><i class="fas fa-id-card"></i> ' + esc(d) + '</span>' : d; } },
            { data: 'roll_no', title: 'Roll', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'full_name', title: 'Student', render: function(d, t, r) {
                if (t !== 'display') return d;
                return '<span class="drill-who">' + avatarCell(r.photo, d) + '<span>' + esc(d) +
                       '<span class="tnt-sub">@' + esc(r.username) + '</span></span></span>';
            } },
            { data: 'class_name', title: 'Class', render: function(d, t, r) { return t === 'display' ? esc(d) + ' &ndash; ' + esc(r.section_name) : d + ' - ' + r.section_name; } },
            { data: 'year_name', title: 'Year', render: function(d, t) { return t === 'display' ? esc(d) : d; } },
            { data: 'gender', title: 'Gender', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'guardian_phone', title: 'Guardian Phone', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'status', title: 'Status', render: function(d, t) { return t === 'display' ? stuBadge(d) : d; } },
            { data: null, title: '360&deg;', orderable: false, render: function(d, t, r) {
                return '<button type="button" class="action-icon view-icon btn-360" data-id="' + r.id + '"' +
                       ' title="Open 360&deg; view of ' + esc(r.full_name) + '" aria-label="Open 360 degree view of ' + esc(r.full_name) + '" aria-haspopup="dialog">' +
                       '<i class="fas fa-street-view"></i></button>';
            } }
        ];
        if (kind === 'teachers') return [
            { data: 'employee_no', title: 'Emp No', render: function(d, t) { return t === 'display' ? '<span class="subject-chip"><i class="fas fa-id-badge"></i> ' + esc(d) + '</span>' : d; } },
            { data: 'full_name', title: 'Teacher', render: function(d, t, r) {
                if (t !== 'display') return d;
                return '<span class="drill-who"><span class="drill-avatar drill-avatar-txt">' + esc((d || '?').charAt(0).toUpperCase()) + '</span>' +
                       '<span>' + esc(d) + '<span class="tnt-sub">@' + esc(r.username) + '</span></span></span>';
            } },
            { data: 'email', title: 'Email', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'phone', title: 'Phone', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'qualification', title: 'Qualification', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'joining_date', title: 'Joined', render: function(d, t) { return t === 'display' ? day(d) : (d || ''); } },
            { data: 'assignments', title: 'Assignments', render: function(d, t) { return t === 'display' ? countChip('list-check', d) : d; } },
            { data: 'status', title: 'Status', render: function(d, t) { return t === 'display' ? stuBadge(d) : d; } }
        ];
        if (kind === 'classes') return [
            { data: 'name', title: 'Class', render: function(d, t) { return t === 'display' ? '<i class="fas fa-school text-muted"></i> ' + esc(d) : d; } },
            { data: 'sections', title: 'Sections', render: function(d, t) { return t === 'display' ? countChip('table-columns', d) : d; } },
            { data: 'subjects', title: 'Subjects', render: function(d, t) { return t === 'display' ? countChip('book', d) : d; } },
            { data: 'students', title: 'Active Students', render: function(d, t) { return t === 'display' ? countChip('user-graduate', d) : d; } },
            { data: 'is_active', title: 'Status', render: function(d, t) { return t === 'display' ? stuBadge(d == 1 ? 'Active' : 'Inactive') : d; } }
        ];
        return [ // users / logins
            { data: 'username', title: 'Username', render: function(d, t) { return t === 'display' ? '<i class="fas fa-user text-muted"></i> ' + esc(d) : d; } },
            { data: 'full_name', title: 'Full Name', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'role', title: 'Role', render: function(d, t) { return t === 'display' ? '<span class="subject-chip"><i class="fas fa-user-shield"></i> ' + esc(d) + '</span>' : d; } },
            { data: 'email', title: 'Email', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'phone', title: 'Phone', render: function(d, t) { return t === 'display' ? dash(d) : (d || ''); } },
            { data: 'is_active', title: 'Status', render: function(d, t) { return t === 'display' ? stuBadge(d == 1 ? 'Active' : 'Inactive') : d; } },
            { data: 'created_at', title: 'Created', render: function(d, t) { return t === 'display' ? day(d) : (d || ''); } }
        ];
    }

    function renderDrill(rows) {
        var kind = drillKind;
        $('#drillWrap').toggleClass('initially-hidden', !rows.length);
        $('#drillEmpty').toggleClass('initially-hidden', !!rows.length);
        $('#drillEmptyMsg').text('This branch has no ' + DRILL_LABEL[kind] + ' yet.');
        if (drillDT) { drillDT.destroy(); $('#drillTable').empty(); }
        if (!rows.length) { drillDT = null; return; }

        var xTitle = drillBranch.name + ' [' + drillBranch.code + '] - ' + DRILL_META[kind].tab;
        var xOpts = kind === 'students' ? { columns: ':not(:last-child)' } : {};   // students: drop the 360 button col
        drillDT = $('#drillTable').DataTable({
            data: rows,
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            responsive: true,
            destroy: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: xTitle, exportOptions: xOpts },
                { text: '<i class="fas fa-file-pdf"></i> PDF',
                  action: function(e, dt, node, config) {
                      loadExportDeps(function() { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  },
                  title: xTitle, exportOptions: xOpts },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: xTitle, exportOptions: xOpts }
            ],
            columns: drillCols(kind),
            createdRow: function(row, data) {
                if (kind === 'students') {
                    $(row).addClass('drill-row').attr({ 'data-sid': data.id, title: 'Open 360° view of ' + data.full_name });
                }
            }
        });
        // modal was hidden a moment ago -> let responsive re-measure
        setTimeout(function() { if (drillDT) drillDT.columns.adjust().responsive.recalc(); }, 80);
    }

    // ==================== student 360 ====================
    function openStudent360(id) {
        $('#stu360Body').html(
            '<div class="skeleton-table">' +
            '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>' +
            '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-1"></div><div class="skeleton skeleton-table-cell skeleton-flex-2"></div></div>' +
            '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div><div class="skeleton skeleton-table-cell skeleton-flex-1"></div></div>' +
            '</div>');
        $('#stu360Modal').addClass('active');
        ORMS.post('getStudent360', { id: id }).done(function(res) {
            if (!res.success) { close_('#stu360Modal'); ORMS.err(res.message || 'Failed to load student'); return; }
            render360(res);
            setTimeout(function() { $('#btnCloseStu360').trigger('focus'); }, 60);
        }).fail(function(msg) { close_('#stu360Modal'); ORMS.err(msg); });
    }

    function item360(label, icon, val) {
        return '<div class="stu360-item"><div class="lbl">' + label + '</div><div class="val"><i class="fas fa-' + icon + '"></i>' +
               (blank(val) ? '<span class="text-muted">&mdash;</span>' : esc(val)) + '</div></div>';
    }
    function sec360(icon, title, inner) {
        return '<div class="stu360-sec"><h4><i class="fas fa-' + icon + '"></i> ' + title + '</h4>' + inner + '</div>';
    }
    function none360(msg) { return '<div class="stu360-none"><i class="fas fa-inbox"></i> ' + msg + '</div>'; }
    function tbl360(heads, rows) {
        return '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
               heads.map(function(h) { return '<th>' + h + '</th>'; }).join('') +
               '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
    }
    function ageOf(dob) {
        if (blank(dob)) return '';
        var b = new Date(String(dob).slice(0, 10)), n = new Date();
        if (isNaN(b)) return '';
        var a = n.getFullYear() - b.getFullYear() - ((n.getMonth() < b.getMonth() || (n.getMonth() === b.getMonth() && n.getDate() < b.getDate())) ? 1 : 0);
        return a >= 0 && a < 130 ? String(dob).slice(0, 10) + ' (' + a + ' yrs)' : String(dob).slice(0, 10);
    }

    function render360(d) {
        var p = d.profile, h = '';

        // identity strip
        h += '<div class="stu360-head">' +
             (p.photo ? '<img class="stu360-avatar" src="' + esc(p.photo) + '" alt="">'
                      : '<span class="stu360-avatar stu360-avatar-txt">' + esc((p.full_name || '?').charAt(0).toUpperCase()) + '</span>') +
             '<div class="stu360-id"><h4>' + esc(p.full_name) + ' ' + stuBadge(p.status) + '</h4>' +
             '<div class="stu360-chips">' +
             '<span class="subject-chip"><i class="fas fa-id-card"></i> ' + esc(p.admission_no) + '</span>' +
             (blank(p.roll_no) ? '' : '<span class="subject-chip"><i class="fas fa-hashtag"></i> Roll ' + esc(p.roll_no) + '</span>') +
             '<span class="subject-chip"><i class="fas fa-school"></i> ' + esc(p.class_name) + ' &ndash; ' + esc(p.section_name) + '</span>' +
             '<span class="subject-chip"><i class="fas fa-calendar"></i> ' + esc(p.year_name) + '</span>' +
             (blank(p.branch_name) ? '' : '<span class="subject-chip"><i class="fas fa-building"></i> ' + esc(p.branch_name) + '</span>') +
             '</div></div></div>';

        // kpis off whatever blocks came back
        var latest = (d.results || []).length ? d.results[d.results.length - 1] : null;
        var attP = 0, attT = 0;
        (d.attendance || []).forEach(function(a) { attP += Number(a.days_present) || 0; attT += Number(a.days_total) || 0; });
        var kpi = '';
        if (latest) {
            kpi += '<div><i class="fas fa-percent"></i> Latest Result <b>' + Number(latest.percentage).toFixed(1) + '%</b></div>' +
                   '<div><i class="fas fa-award"></i> Grade <b>' + esc(latest.grade || '—') + '</b></div>' +
                   '<div><i class="fas fa-star"></i> GPA <b>' + Number(latest.gpa).toFixed(2) + '</b></div>';
            if (!blank(latest.position)) kpi += '<div><i class="fas fa-ranking-star"></i> Position <b>' + esc(latest.position) + '</b></div>';
        }
        if (attT > 0) kpi += '<div><i class="fas fa-calendar-check"></i> Attendance <b>' + (attP / attT * 100).toFixed(1) + '%</b></div>';
        if (d.fees) kpi += '<div><i class="fas fa-money-bill-wave"></i> Fee Balance <b>' + esc(d.fees.balance) + '</b></div>';
        if (kpi) h += '<div class="stu360-sec"><div class="stat-mini">' + kpi + '</div></div>';

        // profile
        h += sec360('address-card', 'Profile', '<div class="stu360-grid">' +
            item360('Father', 'user', p.father_name) +
            item360('Mother', 'user', p.mother_name) +
            item360('Guardian', 'user-shield', p.guardian_name) +
            item360('Guardian Phone', 'phone', p.guardian_phone) +
            item360('Guardian Email', 'envelope', p.guardian_email) +
            item360('Date of Birth', 'cake-candles', ageOf(p.dob)) +
            item360('Gender', 'venus-mars', p.gender) +
            item360('Blood Group', 'droplet', p.blood_group) +
            item360('National ID', 'id-card-clip', p.national_id) +
            item360('Address', 'location-dot', p.address) +
            item360('Admission Date', 'calendar-plus', blank(p.admission_date) ? '' : String(p.admission_date).slice(0, 10)) +
            item360('Previous School', 'building-columns', p.previous_school) +
            (blank(p.date_of_leaving) ? '' :
                item360('Date of Leaving', 'right-from-bracket', String(p.date_of_leaving).slice(0, 10)) +
                item360('Leaving Reason', 'circle-question', p.leaving_reason)) +
            (blank(p.remarks) ? '' : item360('Remarks', 'note-sticky', p.remarks)) +
            '</div>');

        // results by term
        if (d.results) {
            h += sec360('square-poll-vertical', 'Results by Term', !d.results.length ? none360('No results generated yet.') :
                tbl360(['Year', 'Term', 'Marks', '%', 'Grade', 'GPA', 'Position', 'Result'], d.results.map(function(r) {
                    return '<tr><td>' + esc(r.year_name) + '</td><td>' + esc(r.term_name) + '</td>' +
                           '<td>' + Number(r.total_obtained) + ' / ' + Number(r.total_max) + '</td>' +
                           '<td><b>' + Number(r.percentage).toFixed(1) + '%</b></td>' +
                           '<td>' + esc(r.grade || '—') + '</td><td>' + Number(r.gpa).toFixed(2) + '</td>' +
                           '<td>' + (blank(r.position) ? '—' : esc(r.position)) + '</td>' +
                           '<td>' + (r.result_status === 'PASS'
                                ? '<span class="status-badge status-active"><i class="fas fa-check"></i> PASS</span>'
                                : '<span class="status-badge status-inactive"><i class="fas fa-xmark"></i> FAIL</span>') + '</td></tr>';
                })));
        }

        // subject marks, current year
        if (d.marks) {
            h += sec360('book-open', 'Subject Marks — ' + esc(p.year_name), !d.marks.length ? none360('No marks entered for this year yet.') :
                tbl360(['Term', 'Subject', 'Marks', 'Grade'], d.marks.map(function(m) {
                    return '<tr><td>' + esc(m.term_name) + '</td><td>' + esc(m.subject_name) + '</td>' +
                           '<td>' + (m.is_absent == 1 ? '<span class="status-badge status-inactive"><i class="fas fa-user-slash"></i> Absent</span>'
                                : (blank(m.marks_obtained) ? '—' : Number(m.marks_obtained) + ' / ' + Number(m.total_marks))) + '</td>' +
                           '<td>' + esc(m.grade || '—') + '</td></tr>';
                })));
        }

        // attendance
        if (d.attendance) {
            h += sec360('calendar-check', 'Attendance', !d.attendance.length ? none360('No attendance recorded yet.') :
                tbl360(['Year', 'Term', 'Present', 'Total', '%', 'Remarks'], d.attendance.map(function(a) {
                    var t = Number(a.days_total) || 0;
                    return '<tr><td>' + esc(a.year_name) + '</td><td>' + esc(a.term_name) + '</td>' +
                           '<td>' + Number(a.days_present) + '</td><td>' + t + '</td>' +
                           '<td><b>' + (t > 0 ? (Number(a.days_present) / t * 100).toFixed(1) + '%' : '—') + '</b></td>' +
                           '<td>' + (blank(a.remarks) ? '—' : esc(a.remarks)) + '</td></tr>';
                })));
        }

        // fees
        if (d.fees) {
            var bal = Number(d.fees.balance_raw) || 0;
            var chips = '<div class="stu360-chips mb-24">' +
                '<span class="subject-chip"><i class="fas fa-file-invoice"></i> Charged: ' + esc(d.fees.charges) + '</span>' +
                '<span class="subject-chip chip-money-pos"><i class="fas fa-circle-check"></i> Paid: ' + esc(d.fees.payments) + '</span>' +
                '<span class="subject-chip ' + (bal > 0 ? 'chip-money-neg' : 'chip-money-pos') + '"><i class="fas fa-scale-balanced"></i> Balance: ' + esc(d.fees.balance) + '</span></div>';
            h += sec360('money-bill-wave', 'Fees', chips + (!d.fees.entries.length ? none360('No fee entries yet.') :
                tbl360(['Date', 'Type', 'Description', 'Reference', 'Amount'], d.fees.entries.map(function(f) {
                    return '<tr><td>' + (blank(f.entry_date) ? '—' : esc(String(f.entry_date).slice(0, 10))) + '</td>' +
                           '<td>' + (f.entry_type === 'Payment'
                                ? '<span class="status-badge status-active"><i class="fas fa-arrow-down"></i> Payment</span>'
                                : '<span class="status-badge status-inactive"><i class="fas fa-arrow-up"></i> Charge</span>') + '</td>' +
                           '<td>' + esc(f.description) + '</td><td>' + (blank(f.reference) ? '—' : esc(f.reference)) + '</td>' +
                           '<td><b>' + esc(f.amount) + '</b></td></tr>';
                }))));
        }

        // login & audit
        h += sec360('right-to-bracket', 'Login & Audit', '<div class="stu360-grid">' +
            item360('Username', 'user', p.username) +
            item360('Email', 'envelope', p.email) +
            item360('Login Active', 'toggle-on', p.is_active == 1 ? 'Yes' : 'No') +
            item360('Email Verified', 'circle-check', p.email_verified == 1 ? 'Yes' : 'No') +
            item360('Last Login', 'clock', blank(d.last_login) ? 'Never' : d.last_login) +
            item360('Member Since', 'calendar', blank(p.member_since) ? '' : String(p.member_since).slice(0, 10)) +
            item360('Created By', 'user-pen', p.created_by_name) +
            item360('Updated By', 'user-pen', p.updated_by_name) +
            '</div>');

        $('#stu360Body').html(h);
    }
    </script>
    <?php endif; ?>
</body>
</html>
