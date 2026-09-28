<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

require_once 'config.php';

// schema AND the installer access gate both live there — nothing here can run without it
if (!is_file(__DIR__ . '/update_setup.php')) {
    exit('<link rel="stylesheet" href="styles.css?v=15.2"><div class="setup-wrapper"><div class="setup-container">'
       . '<h2>Setup unavailable</h2><p class="subtitle">update_setup.php is missing — it holds the schema and the installer gate.</p></div></div>');
}
require_once __DIR__ . '/update_setup.php';

// gate FIRST, before a single byte of db work: an anonymous visitor only gets through while
// the system is genuinely unbuilt (no users table, or no Admin row yet)
[$allowed] = installerAllowed();
if (!$allowed) installerLock();

$isAdmin   = installerAdmin();
$wantReset = (($_POST['action'] ?? $_GET['action'] ?? '') === 'reset');
$posted    = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$csrfOk    = $posted && validateCSRFToken((string)($_POST['csrf_token'] ?? ''));

// reset drops everything -> admin-only ALWAYS, even mid-bootstrap, and only over a csrf-signed POST
if ($wantReset && !$isAdmin) {
    installerLock('Administrator Required', 'Dropping and rebuilding the database is restricted to signed-in administrators.');
}

// installer only: install (default) or the destructive reset. schema lives in update_setup.php
$action     = $wantReset ? 'reset' : 'install';
$confirmed  = $wantReset && $csrfOk;                // the POST token IS the confirmation
$staleToken = $wantReset && $posted && !$csrfOk;    // expired session -> re-confirm, never run

// one log line in the setup shell
function setupLog(string $type, string $msg): void
{
    $cls  = $type === 'error' ? 'log-error' : ($type === 'info' ? 'log-info' : 'log-success');
    $icon = $type === 'error' ? 'fa-times-circle' : ($type === 'info' ? 'fa-info-circle' : 'fa-check-circle');
    echo '<div class="log-item ' . $cls . '"><i class="fas ' . $icon . '"></i> ' . $msg . '</div>';
}

// reset: children first so the list reads right even with fk checks off
function dropAll(mysqli $conn, ?callable $log = null): void
{
    $say = $log ?: static function () {};
    // children -> parents. this list had silently fallen 6 tables behind update_setup.php, so a
    // "reset" left orphans behind; the sweep at the bottom makes the next drift visible instead.
    $tables = [
        'attendance_daily', 'timetable_slots', 'exam_schedule', 'fee_structures',
        'mark_components', 'attendance_summary', 'student_fees', 'assessment_components',
        'student_subjects', 'result_summaries', 'result_publications', 'marks', 'teacher_subjects', 'students', 'teachers',
        'class_subjects', 'sections', 'subjects', 'classes', 'exam_terms', 'academic_years',
        'grading_scheme', 'grading_sets', 'assessment_schemes',
        'push_subscriptions', 'notifications', 'user_sessions', 'login_attempts', 'remember_tokens',
        'password_resets', 'email_verifications', 'activity_logs', 'system_settings', 'roles', 'users',
        'subscription_payments', 'school_subscriptions', 'branches', 'schools', 'plans'
    ];
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("DROP TABLE IF EXISTS `" . implode('`, `', $tables) . "`");

    // anything left is a table this list has never heard of — drop it too, and say so
    $left = [];
    if ($r = $conn->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")) {
        while ($row = $r->fetch_row()) $left[] = (string)$row[0];
    }
    if ($left) {
        $conn->query("DROP TABLE IF EXISTS `" . implode('`, `', $left) . "`");
        $say('info', count($left) . ' extra table(s) dropped, missing from dropAll(): ' . implode(', ', $left));
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $say('success', count($tables) . ' tables dropped — rebuilding from scratch');
}

/**
 * Demo dataset — generic names only, check-then-insert so a re-run never duplicates.
 * Seeds a full First Term for Class 5-A (marks + summaries + published) so cards demo instantly,
 * plus the school-ops layer: fee structures + this month charged/part-paid, daily registers,
 * a clash-free weekly timetable per section and the First Term date sheet.
 */
function seedDemoData(mysqli $conn, ?callable $log = null): void
{
    $say = $log ?: static function () {};
    $conn->begin_transaction();

    try {
        // prepared read of a single scalar
        $pick = static function (string $sql, string $types, array $args) use ($conn) {
            $st = $conn->prepare($sql);
            $st->bind_param($types, ...$args);
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
            return $row ? $row[0] : null;
        };
        // select -> insert if missing -> id
        $ensure = static function (string $sel, string $selT, array $selA, string $ins, string $insT, array $insA) use ($conn, $pick) {
            $id = $pick($sel, $selT, $selA);
            if ($id !== null) return (int) $id;
            $st = $conn->prepare($ins);
            $st->bind_param($insT, ...$insA);
            $st->execute();
            $st->close();
            return (int) $conn->insert_id;
        };

        $now = date('Y-m-d H:i:s');

        // year + terms
        $year = $ensure(
            "SELECT id FROM academic_years WHERE name = ?", "s", ['2025-2026'],
            "INSERT INTO academic_years (name, start_date, end_date, is_current) VALUES (?, ?, ?, 1)", "sss",
            ['2025-2026', '2025-04-01', '2026-03-31']
        );
        if (!$conn->query("SELECT id FROM academic_years WHERE is_current = 1")->num_rows) {
            $st = $conn->prepare("UPDATE academic_years SET is_current = 1 WHERE id = ?");
            $st->bind_param("i", $year); $st->execute(); $st->close();
        }

        $terms = [];
        foreach ([['First Term', 'Open', 1, 30.0], ['Mid Term', 'Upcoming', 2, 30.0], ['Final Term', 'Upcoming', 3, 40.0]] as [$tn, $ts, $so, $tw]) {
            $terms[$tn] = $ensure(
                "SELECT id FROM exam_terms WHERE academic_year_id = ? AND name = ?", "is", [$year, $tn],
                "INSERT INTO exam_terms (academic_year_id, name, sort_order, status, weightage) VALUES (?, ?, ?, ?, ?)", "isisd",
                [$year, $tn, $so, $ts, $tw]
            );
            // pre-weightage installs: fill only where still unset, an admin's own weighting is never overwritten
            $st = $conn->prepare("UPDATE exam_terms SET weightage = ? WHERE id = ? AND weightage = 0");
            $st->bind_param("di", $tw, $terms[$tn]); $st->execute(); $st->close();
        }
        $termId = $terms['First Term'];
        // demo entry needs first term open — never reopen a term that already holds marks
        $st = $conn->prepare("UPDATE exam_terms SET status = 'Open' WHERE id = ? AND status <> 'Open' AND NOT EXISTS (SELECT 1 FROM marks WHERE term_id = ?)");
        $st->bind_param("ii", $termId, $termId); $st->execute(); $st->close();
        $say('success', 'Academic year <strong>2025-2026</strong> + 3 weighted exam terms ready (30/30/40, First Term open)');

        // classes 4-5, sections A/B each
        $cls = $sec = [];
        foreach ([['Class 4', 1], ['Class 5', 2]] as [$cn, $cso]) {
            $cid = $ensure(
                "SELECT id FROM classes WHERE name = ?", "s", [$cn],
                "INSERT INTO classes (name, sort_order, is_active) VALUES (?, ?, 1)", "si", [$cn, $cso]
            );
            $cls[$cn] = $cid;
            foreach (['A', 'B'] as $sn) {
                $sec[$cn . '-' . $sn] = $ensure(
                    "SELECT id FROM sections WHERE class_id = ? AND name = ?", "is", [$cid, $sn],
                    "INSERT INTO sections (class_id, name, capacity, is_active) VALUES (?, ?, 40, 1)", "is", [$cid, $sn]
                );
            }
        }
        $c5 = $cls['Class 5']; $secA = $sec['Class 5-A']; $secB = $sec['Class 5-B'];
        $say('success', '2 classes (Class 4, Class 5) with sections A &amp; B ready');

        // subjects + per-class marks config @ 100/33
        $subjectList = [['English', 'ENG'], ['Mathematics', 'MATH'], ['Science', 'SCI'], ['Urdu', 'URD'], ['Islamiat', 'ISL'], ['Computer', 'CMP']];
        $subs = [];
        foreach ($subjectList as [$sn, $sc]) {
            $subs[$sn] = $ensure(
                "SELECT id FROM subjects WHERE code = ?", "s", [$sc],
                "INSERT INTO subjects (name, code, is_active) VALUES (?, ?, 1)", "ss", [$sn, $sc]
            );
        }
        $tm = 100.0; $pm = 33.0; $cid = $sid = $so = 0;
        $csIns = $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id, total_marks, passing_marks, sort_order) VALUES (?, ?, ?, ?, ?)");
        $csIns->bind_param("iiddi", $cid, $sid, $tm, $pm, $so);
        foreach ($cls as $cid) {
            foreach ($subjectList as $i => [$sn,]) { $sid = $subs[$sn]; $so = $i + 1; $csIns->execute(); }
        }
        $csIns->close();
        // Computer is the demo elective — per-student enrolment drives who takes it
        $cmpId = $subs['Computer'];
        $st = $conn->prepare("UPDATE class_subjects SET is_optional = 1 WHERE subject_id = ?");
        $st->bind_param("i", $cmpId); $st->execute(); $st->close();
        $say('success', '6 subjects mapped to both classes at 100 total / 33 passing (Computer optional)');

        // logins already present -> id map (one hit, no query-in-loop)
        $have = [];
        $rs = $conn->query("SELECT id, username FROM users");
        while ($rs && $r = $rs->fetch_assoc()) { $have[$r['username']] = (int) $r['id']; }

        // phone is optional: staff logins carry one so the Users page and the 360 view have a real
        // profile to show on a fresh install, students do not — a student's number is the guardian's
        // and it lives on the student row, not on the login.
        $mkUser = static function (string $u, string $hash, string $mail, string $role, string $name, string $phone = '') use ($conn, &$have) {
            if (isset($have[$u])) return $have[$u];
            $on = 1;
            $st = $conn->prepare("INSERT INTO users (username, password, email, role, full_name, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ph = $phone === '' ? null : $phone;
            $st->bind_param("ssssssi", $u, $hash, $mail, $role, $name, $ph, $on);
            $st->execute();
            $st->close();
            $newId = (int) $conn->insert_id;
            // 🚨 users.school_id defaults to 0 (platform) while every tenant table defaults to 1, and
            // applyUpdates' "assign existing accounts to school 1" backfill runs BEFORE this seed —
            // so on a fresh install it matches nothing. Without this stamp the demo admin logs in on
            // school 0 and sees an entirely empty app: 0 students, 0 terms, 0 of everything.
            if (@$conn->query("SHOW COLUMNS FROM users LIKE 'school_id'")->num_rows) {
                $up = $conn->prepare("UPDATE users SET school_id = 1 WHERE id = ? AND school_id = 0");
                $up->bind_param("i", $newId); $up->execute(); $up->close();
            }
            return $have[$u] = $newId;
        };

        // demo phone numbers, ONE map — the $mkUser calls and the backfill below both read it, so a
        // number can never drift between "what a fresh install gets" and "what an old one is repaired to"
        $demoPhone = ['admin' => '03001000001', 'appowner' => '03001000002', 'owner' => '03001000003',
                      'branchadmin' => '03001000004', 'principal' => '03001000005',
                      'teacher1' => '03001000006', 'teacher2' => '03001000007'];

        // hash once per password, not per row
        $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
        $headHash  = password_hash('principal123', PASSWORD_DEFAULT);
        $teachHash = password_hash('teacher123', PASSWORD_DEFAULT);
        $stuHash   = password_hash('student123', PASSWORD_DEFAULT);

        $adminName = 'System Administrator';
        $adminId   = $mkUser('admin', $adminHash, 'admin@example.com', 'Admin', $adminName, $demoPhone['admin']);
        // older template installs left admin on the User role / without a name
        $st = $conn->prepare("UPDATE users SET role = 'Admin', full_name = ? WHERE id = ? AND (role <> 'Admin' OR full_name IS NULL OR full_name = '')");
        $st->bind_param("si", $adminName, $adminId); $st->execute(); $st->close();

        // ---- one demo login per role, so every permission path can be walked on a fresh install ----
        // App Owner: the platform operator. school_id 0 is what makes it one — $mkUser stamps every
        // new account into school 1, so this is the one account that has to be moved back out.
        $ownerHash  = password_hash('appowner123', PASSWORD_DEFAULT);
        $schOwnHash = password_hash('owner123', PASSWORD_DEFAULT);
        $brAdmHash  = password_hash('branch123', PASSWORD_DEFAULT);

        $appOwnerId = $mkUser('appowner', $ownerHash, 'appowner@example.com', 'Super Admin', 'App Owner', $demoPhone['appowner']);
        if ($appOwnerId > 0 && ($st = @$conn->prepare("UPDATE users SET school_id = 0, branch_id = NULL WHERE id = ?"))) {
            $st->bind_param("i", $appOwnerId); $st->execute(); $st->close();
        }
        $say('success', 'App Owner seeded (appowner / appowner123) — platform operator, lives outside every school');

        // School Owner: the tenant's own top role. Holds Admin's whole matrix plus Subscription.
        $schoolOwnerId = $mkUser('owner', $schOwnHash, 'owner@example.com', 'School Owner', 'School Owner', $demoPhone['owner']);
        $say('success', 'School Owner seeded (owner / owner123) — owns this school\'s plan and invoices');

        // the owner stamp. applyUpdates()' backfill runs BEFORE any user exists on a fresh install,
        // so it matches nothing and the stamp has to happen here or the school ends up ownerless.
        // Only claims the stamp when nobody who actually holds the role is already pointed at, so a
        // deliberate ownership transfer survives a re-run. applyUpdates() has usually put the oldest
        // Admin here by now — that pointer is exactly what this corrects.
        if ($schoolOwnerId > 0 && ($st = @$conn->prepare(
                "UPDATE schools s LEFT JOIN users u ON u.id = s.owner_user_id AND u.role = 'School Owner'
                 SET s.owner_user_id = ? WHERE u.id IS NULL"))) {
            $st->bind_param("i", $schoolOwnerId); $st->execute(); $st->close();
        }

        // Branch Admin: pinned to ONE campus by ormsBranchLock(), so it needs a branch or it sees nothing
        $branchAdminId = $mkUser('branchadmin', $brAdmHash, 'branchadmin@example.com', 'Branch Admin', 'Branch Administrator', $demoPhone['branchadmin']);
        if ($branchAdminId > 0) {
            $bMain = 0;
            if ($r = @$conn->query("SELECT id FROM branches WHERE school_id = 1 ORDER BY is_main DESC, id ASC LIMIT 1")) {
                $row = $r->fetch_row();
                $bMain = $row ? (int) $row[0] : 0;
            }
            if ($bMain > 0 && ($st = @$conn->prepare("UPDATE users SET branch_id = ? WHERE id = ?"))) {
                $st->bind_param("ii", $bMain, $branchAdminId); $st->execute(); $st->close();
            }
        }
        $say('success', 'Branch Admin seeded (branchadmin / branch123) — pinned to the main branch');

        // head teacher — NO teachers row on purpose: reach comes from the role, not an assignment
        $mkUser('principal', $headHash, 'principal@example.com', 'Principal', 'Head Teacher', $demoPhone['principal']);
        $say('success', 'Principal account seeded (principal / principal123)');

        // ---- branch profiles. A campus card that is all dashes teaches nobody what the page is for,
        // so the seeded main branch is filled in and a SECOND, deliberately empty campus is added:
        // it is what makes the seat chips, the "make main" move, the school re-allocation and the
        // "a branch holding records cannot be deleted" rule all visible on a fresh install.
        // Guarded on the migration having landed, and every field is filled only where it is still
        // empty — a school that has edited its own branch keeps those edits on a re-run.
        if (($bc = @$conn->query("SHOW COLUMNS FROM branches LIKE 'capacity'")) && $bc->num_rows) {
            $hMain = $have['principal'] ?? null;      // main campus runs on the head teacher
            $hCity = $have['branchadmin'] ?? null;    // second campus on the branch admin
            if ($st = @$conn->prepare(
                    "UPDATE branches
                        SET address      = COALESCE(NULLIF(address, ''), 'Street 1, Demo City'),
                            city         = COALESCE(NULLIF(city, ''), 'Demo City'),
                            phone        = COALESCE(NULLIF(phone, ''), '03001000010'),
                            email        = COALESCE(NULLIF(email, ''), 'main@example.com'),
                            head_user_id = COALESCE(head_user_id, ?),
                            opened_on    = COALESCE(opened_on, '2015-04-01'),
                            capacity     = IF(capacity > 0, capacity, 500),
                            notes        = COALESCE(NULLIF(notes, ''), 'Head office — admissions and accounts')
                      WHERE school_id = 1 AND is_main = 1")) {
                $st->bind_param("i", $hMain); $st->execute(); $st->close();
            }
            // uniq_branch_code (school_id, code) makes this a no-op on every later run
            if ($st = @$conn->prepare(
                    "INSERT IGNORE INTO branches
                        (school_id, name, code, address, city, phone, email, head_user_id, opened_on, capacity, notes, is_main, status)
                     VALUES (1, 'City Campus', 'CITY', 'Street 22, Demo City', 'Demo City', '03001000011',
                             'city@example.com', ?, '2022-08-15', 120, 'Second campus — primary wing only', 0, 'Active')")) {
                $st->bind_param("i", $hCity); $st->execute(); $st->close();
            }
            $say('success', 'Branch profiles seeded — main campus filled in (head, city, 500 seats) + an empty "City Campus" to demo seats, moves and deletes');
        }

        // teachers
        $uid = 0; $emp = $qual = $jdate = $tstat = '';
        $tIns = $conn->prepare("INSERT IGNORE INTO teachers (user_id, employee_no, qualification, joining_date, status) VALUES (?, ?, ?, ?, ?)");
        $tIns->bind_param("issss", $uid, $emp, $qual, $jdate, $tstat);
        foreach ([['teacher1', 'EMP-001', 'Teacher One', 'M.Sc Mathematics', $demoPhone['teacher1']],
                  ['teacher2', 'EMP-002', 'Teacher Two', 'M.A English', $demoPhone['teacher2']]] as [$un, $eno, $nm, $ql, $ph]) {
            $uid = $mkUser($un, $teachHash, $un . '@example.com', 'Teacher', $nm, $ph);
            $emp = $eno; $qual = $ql; $jdate = '2025-04-01'; $tstat = 'Active';
            $tIns->execute();
        }
        $tIns->close();

        // $mkUser only writes a phone on the INSERT, so an install that already had these logins never
        // gains one. Fill where it is still empty — a real number typed by a school is never overwritten.
        if ($st = @$conn->prepare("UPDATE users SET phone = ? WHERE username = ? AND (phone IS NULL OR phone = '')")) {
            foreach ($demoPhone as $un => $ph) { $st->bind_param("ss", $ph, $un); $st->execute(); }
            $st->close();
        }
        $tid = [];
        $rs = $conn->query("SELECT id, employee_no FROM teachers");
        while ($rs && $r = $rs->fetch_assoc()) { $tid[$r['employee_no']] = (int) $r['id']; }

        // subject assignments: t1 = math+science 5-A, t2 = english 5-A and 5-B
        $ateach = $asec = $asub = 0;
        $aIns = $conn->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, class_id, section_id, subject_id, academic_year_id) VALUES (?, ?, ?, ?, ?)");
        $aIns->bind_param("iiiii", $ateach, $c5, $asec, $asub, $year);
        foreach ([['EMP-001', $secA, 'Mathematics'], ['EMP-001', $secA, 'Science'], ['EMP-002', $secA, 'English'], ['EMP-002', $secB, 'English']] as [$eno, $sc2, $sn]) {
            $ateach = $tid[$eno]; $asec = $sc2; $asub = $subs[$sn]; $aIns->execute();
        }
        $aIns->close();
        $say('success', '2 teachers seeded (EMP-001, EMP-002) with 4 subject assignments');

        // 35 students, EVERY section holds a roster: 1-15 -> 5-A, 16-25 -> 5-B, 26-30 -> 4-A, 31-35 -> 4-B.
        // roll restarts per section
        $words = ['One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen',
                  'Sixteen','Seventeen','Eighteen','Nineteen','Twenty','Twenty One','Twenty Two','Twenty Three','Twenty Four','Twenty Five',
                  'Twenty Six','Twenty Seven','Twenty Eight','Twenty Nine','Thirty','Thirty One','Thirty Two','Thirty Three','Thirty Four','Thirty Five'];
        // n -> [class, section, roll] — the one placement map every seed block below shares
        $c4 = $cls['Class 4'];
        $secOf = static fn(int $n): array => $n <= 15 ? [$c5, $secA, (string)$n]
               : ($n <= 25 ? [$c5, $secB, (string)($n - 15)]
               : ($n <= 30 ? [$c4, $sec['Class 4-A'], (string)($n - 25)]
               :             [$c4, $sec['Class 4-B'], (string)($n - 30)]));
        $sUid = $sCls = $sSec = 0;
        $sAdm = $sRoll = $sFather = $sDob = $sGen = $sPhone = $sAddr = '';
        $sAdmDate = '2025-04-05'; $sStatus = 'Active';
        $sIns = $conn->prepare("INSERT IGNORE INTO students (user_id, admission_no, roll_no, class_id, section_id, academic_year_id, father_name, dob, gender, guardian_phone, address, admission_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // roll_no is VARCHAR(20) -> s, not i
        $sIns->bind_param("issiiisssssss", $sUid, $sAdm, $sRoll, $sCls, $sSec, $year, $sFather, $sDob, $sGen, $sPhone, $sAddr, $sAdmDate, $sStatus);
        for ($n = 1; $n <= 35; $n++) {
            [$sCls, $sSec, $sRoll] = $secOf($n);
            $sAdm   = sprintf('STU-2026-%04d', $n);
            $sUid   = $mkUser($sAdm, $stuHash, strtolower($sAdm) . '@example.com', 'Student', 'Student ' . $words[$n - 1]);
            $sFather = 'Guardian ' . $words[$n - 1];
            $sDob   = date('Y-m-d', strtotime('2014-01-01 +' . (($n * 11) % 360) . ' days'));
            $sGen   = $n % 2 ? 'Male' : 'Female';
            $sPhone = sprintf('0300-%07d', 1000000 + $n);
            $sAddr  = 'House ' . $n . ', Demo Town';
            $sIns->execute();
        }
        $sIns->close();
        $stuId = [];
        $rs = $conn->query("SELECT id, admission_no FROM students");
        while ($rs && $r = $rs->fetch_assoc()) { $stuId[$r['admission_no']] = (int) $r['id']; }
        $say('success', '35 students registered (15 in 5-A, 10 in 5-B, 5 in 4-A, 5 in 4-B) — logins STU-2026-0001 … STU-2026-0035');

        // elective enrolment: all of 5-A takes Computer (their term is already marked + published),
        // only 6 of 10 in 5-B — the rest demo a skipped elective in the marks grid and on the card.
        // Class 4 takes it wholesale so its marks grids run full width
        $ssSid = $ssCls = 0;
        $ssIns = $conn->prepare("INSERT IGNORE INTO student_subjects (student_id, class_id, subject_id, academic_year_id, created_by) VALUES (?, ?, ?, ?, ?)");
        $ssIns->bind_param("iiiii", $ssSid, $ssCls, $cmpId, $year, $adminId);
        foreach (array_merge(range(1, 21), range(26, 35)) as $n) {
            $adm2 = sprintf('STU-2026-%04d', $n);
            if (!isset($stuId[$adm2])) continue;                 // ignored duplicate -> nothing to enrol
            [$ssCls, ,] = $secOf($n);
            $ssSid = $stuId[$adm2];
            $ssIns->execute();
        }
        $ssIns->close();
        $say('success', 'Computer enrolments seeded — 15/15 in 5-A, 6/10 in 5-B (4 opted out), 10/10 in Class 4');

        // grade bands straight from the scheme, sorted desc so gaps resolve upward
        $bands = [];
        $rs = $conn->query("SELECT grade, min_percent, max_percent, grade_point FROM grading_scheme ORDER BY min_percent DESC");
        while ($rs && $r = $rs->fetch_assoc()) { $bands[] = $r; }
        if (!$bands) {
            $bands = [['grade' => 'A+', 'min_percent' => 90, 'grade_point' => 4.0], ['grade' => 'A', 'min_percent' => 80, 'grade_point' => 3.7],
                      ['grade' => 'B', 'min_percent' => 70, 'grade_point' => 3.0], ['grade' => 'C', 'min_percent' => 60, 'grade_point' => 2.3],
                      ['grade' => 'D', 'min_percent' => 50, 'grade_point' => 1.7], ['grade' => 'E', 'min_percent' => 40, 'grade_point' => 1.0],
                      ['grade' => 'F', 'min_percent' => 0, 'grade_point' => 0.0]];
        }
        $band = static function (float $pct) use ($bands) {
            foreach ($bands as $b) { if ($pct >= (float) $b['min_percent']) return $b; }
            return end($bands);
        };

        // per-student ability + per-subject difficulty -> believable spread (A+ down to F), deterministic
        $ability = [66, 92, 46, 78, 85, 34, 75, 63, 88, 52, 81, 40, 75, 69, 58];
        $diff    = [3, -2, 6, -5, 1, -3];

        // First Term marks for EVERY section, so all 35 students carry a real result: 5-A keeps the
        // hand-tuned ability curve above (its numbers are what the card/broadsheet demos were built
        // on), 5-B and Class 4 come off a deterministic formula — same input, same marks, every run.
        $abilityOf = static fn(int $n): int => $n <= 15 ? $ability[$n - 1] : 35 + (($n * 37) % 60);

        // who actually takes the optional subject. A card, a grid and a total all count only the
        // subjects a student is enrolled in, so the seeded summary has to agree with the engine —
        // marking a non-enrolled student in Computer would invent a 6th subject on their card.
        $takesOptional = [];
        if ($st = $conn->prepare("SELECT student_id FROM student_subjects WHERE subject_id = ? AND academic_year_id = ?")) {
            $st->bind_param("ii", $cmpId, $year);
            $st->execute();
            $rs = $st->get_result();
            while ($rs && $r = $rs->fetch_row()) $takesOptional[(int) $r[0]] = 1;
            $st->close();
        }

        $mSid = $mCls = $mSec = $mSub = $mAbs = 0; $mObt = null; $mGrade = '';
        $mIns = $conn->prepare("INSERT IGNORE INTO marks (student_id, class_id, section_id, subject_id, term_id, academic_year_id, marks_obtained, total_marks, passing_marks, is_absent, grade, entered_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // passing_marks is the snapshot — a later class_subjects edit must not re-judge a published card
        $mIns->bind_param("iiiiiidddisii", $mSid, $mCls, $mSec, $mSub, $termId, $year, $mObt, $tm, $pm, $mAbs, $mGrade, $adminId, $adminId);

        $sums = [];       // section id => rows, because position is a rank INSIDE the section
        $secClass = [];   // section id => class id, for the publication rows below
        for ($n = 1; $n <= 35; $n++) {
            $adm3 = sprintf('STU-2026-%04d', $n);
            if (!isset($stuId[$adm3])) continue;               // ignored duplicate -> nothing to mark
            [$mCls, $mSec, ] = $secOf($n);
            $secClass[$mSec] = $mCls;
            $mSid = $stuId[$adm3];
            $abil = $abilityOf($n);
            $obt = 0.0; $pts = 0.0; $take = 0; $failed = [];
            foreach ($subjectList as $j => [$sn,]) {
                $mSub = $subs[$sn];
                if ($mSub === $cmpId && empty($takesOptional[$mSid])) continue;   // optional: enrolled only
                $isAbs = ($n === 8 && $j === 3);                                  // the one AB cell, 5-A urdu
                $score = max(30, min(98, $abil + $diff[$j]));                     // 30-98
                $mAbs  = $isAbs ? 1 : 0;
                $mObt  = $isAbs ? null : (float) $score;
                $b     = $band($isAbs ? 0.0 : round($score / $tm * 100, 2));
                $mGrade = $b['grade'];
                $mIns->execute();
                $obt += $isAbs ? 0 : $score;
                $pts += (float) $b['grade_point'];
                $take++;
                if ($isAbs || $score < $pm) $failed[] = $sn;
            }
            $sums[$mSec][] = ['sid' => $mSid, 'obt' => $obt, 'pts' => $pts, 'take' => $take, 'failed' => $failed];
        }
        $mIns->close();
        // count what's actually there — INSERT IGNORE reports 0 affected on a re-run
        $mCount = (int)$pick("SELECT COUNT(*) FROM marks WHERE term_id = ?", "i", [$termId]);
        $say('success', $mCount . ' First Term marks across all four sections (optional Computer only for the students enrolled in it, 1 absent)');

        $rSid = $rSec = $rPos = 0; $rObt = $rMax = $rPct = $rGpa = 0.0; $rGrade = $rStat = $rFail = $rTok = '';
        $rIns = $conn->prepare("INSERT IGNORE INTO result_summaries (student_id, term_id, section_id, academic_year_id, total_obtained, total_max, percentage, grade, gpa, `position`, result_status, failed_subjects, verify_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $rIns->bind_param("iiiidddsdisss", $rSid, $termId, $rSec, $year, $rObt, $rMax, $rPct, $rGrade, $rGpa, $rPos, $rStat, $rFail, $rTok);

        $pub = 1;
        $pCls = $pSec = 0;
        $pIns = $conn->prepare("INSERT IGNORE INTO result_publications (term_id, class_id, section_id, academic_year_id, is_published, published_by, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $pIns->bind_param("iiiiiis", $termId, $pCls, $pSec, $year, $pub, $adminId, $now);

        $sumCount = 0;
        foreach ($sums as $secKey => $rows) {
            // positions: rank by total desc INSIDE this section, ties share, next skips (1,1,3)
            $ranked = $rows;
            usort($ranked, static fn($a, $b) => $b['obt'] <=> $a['obt']);
            $pos = []; $rank = 0; $seen = 0; $prev = null;
            foreach ($ranked as $r) {
                $seen++;
                if ($prev === null || $r['obt'] < $prev) { $rank = $seen; $prev = $r['obt']; }
                $pos[$r['sid']] = $rank;
            }
            $rSec = (int) $secKey;
            foreach ($rows as $r) {
                $rSid   = $r['sid'];
                $rObt   = (float) $r['obt'];
                // only the subjects this student actually takes — a skipped elective must not
                // count 100 marks they were never offered against them
                $rMax   = (float) ($r['take'] * $tm);
                $rPct   = $rMax > 0 ? round($r['obt'] / $rMax * 100, 2) : 0.0;
                $rGrade = $band($rPct)['grade'];
                $rGpa   = $r['take'] > 0 ? round($r['pts'] / $r['take'], 2) : 0.0;
                $rPos   = $pos[$r['sid']];
                $rStat  = $r['failed'] ? 'FAIL' : 'PASS';
                $rFail  = implode(', ', $r['failed']);
                $rTok   = bin2hex(random_bytes(16));   // qr verify token
                $rIns->execute();
                $sumCount++;
            }
            // every section is published, so a student login of ANY class sees a card straight away
            $pSec = (int) $secKey;
            $pCls = (int) ($secClass[$secKey] ?? 0);
            $pIns->execute();
        }
        $rIns->close();
        $pIns->close();
        $say('success', $sumCount . ' result summaries computed (totals, %, grade, GPA, position ranked inside each section) — all four sections <strong>published</strong>');

        // attendance: First Term for EVERY student in EVERY section — deterministic spread, 64-90 of 90.
        // uniq_att (student, term) makes the re-run a no-op, an admin's own entries stay untouched
        $aSid = $aSec = 0; $aPres = 0.0; $aTotal = 90.0;
        $aIns = $conn->prepare("INSERT IGNORE INTO attendance_summary (student_id, term_id, section_id, academic_year_id, days_present, days_total, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $aIns->bind_param("iiiiddi", $aSid, $termId, $aSec, $year, $aPres, $aTotal, $adminId);
        for ($n = 1; $n <= 35; $n++) {
            $adm2 = sprintf('STU-2026-%04d', $n);
            if (!isset($stuId[$adm2])) continue;
            [, $aSec,] = $secOf($n);
            $aSid  = $stuId[$adm2];
            $aPres = (float) (90 - (($n * 7) % 27));             // 64-90 present -> 71%-100%
            $aIns->execute();
        }
        $aIns->close();
        $aCount = (int)$pick("SELECT COUNT(*) FROM attendance_summary WHERE term_id = ?", "i", [$termId]);
        $say('success', $aCount . ' First Term attendance summaries — every section of both classes covered');

        // ---- school ops demo: fee structures + this month billed, daily registers, timetable, date sheet ----
        // every block INSERT IGNOREs or NOT EXISTSes its way in — a re-run adds nothing, an admin's edits stay
        $opsOk = true;
        foreach (['fee_structures', 'attendance_daily', 'timetable_slots', 'exam_schedule'] as $t) {
            if (!@$conn->query("SHOW TABLES LIKE '$t'")->num_rows) { $opsOk = false; break; }   // pre-ops schema
        }
        if ($opsOk) {
            $sfSchool = (bool)@$conn->query("SHOW COLUMNS FROM student_fees LIKE 'school_id'")->num_rows;

            // fee structures: monthly tuition per class
            $fsCls = 0; $fsAmt = 0.0; $fsName = 'Monthly Tuition';
            $fsIns = $conn->prepare("INSERT IGNORE INTO fee_structures (class_id, name, amount, frequency, is_active) VALUES (?, ?, ?, 'Monthly', 1)");
            $fsIns->bind_param("isd", $fsCls, $fsName, $fsAmt);
            foreach ([['Class 4', 1200.0], ['Class 5', 1500.0]] as [$cn, $amt]) { $fsCls = $cls[$cn]; $fsAmt = $amt; $fsIns->execute(); }
            $fsIns->close();

            // this month's tuition already raised — same reference scheme the live charge run uses
            $ym = date('Y-m'); $mLabel = date('F Y');
            $chN = 0;
            $rs = $conn->query("SELECT id, class_id, name, amount FROM fee_structures
                                WHERE frequency = 'Monthly' AND is_active = 1 AND class_id IN ({$cls['Class 4']}, {$cls['Class 5']})");
            while ($rs && $fs = $rs->fetch_assoc()) {
                $ref  = 'FS' . (int)$fs['id'] . '-' . $ym;
                $desc = $fs['name'] . ' — ' . $mLabel;
                $amt2 = (float)$fs['amount'];
                $ed   = $ym . '-01';
                $fcid = (int)$fs['class_id'];
                $st = $conn->prepare("INSERT INTO student_fees (student_id, academic_year_id, entry_type, description, amount, entry_date, reference, created_by" . ($sfSchool ? ", school_id" : "") . ")
                                      SELECT st.id, ?, 'Charge', ?, ?, ?, ?, ?" . ($sfSchool ? ", st.school_id" : "") . "
                                      FROM students st
                                      WHERE st.class_id = ? AND st.status = 'Active' AND st.academic_year_id = ?
                                        AND NOT EXISTS (SELECT 1 FROM student_fees f WHERE f.student_id = st.id AND f.reference = ?)");
                $st->bind_param("isdssiiis", $year, $desc, $amt2, $ed, $ref, $adminId, $fcid, $year, $ref);
                $st->execute();
                $chN += $st->affected_rows;
                $st->close();
            }

            // a spread of families has already paid -> live balances for the reminder/withholding demos
            $payN = 0;
            $pSid = 0; $pAmt = 0.0; $pRef = ''; $pDate = $ym . '-05'; $pDesc = 'Tuition payment — ' . $mLabel;
            $pIns2 = $conn->prepare("INSERT INTO student_fees (student_id, academic_year_id, entry_type, description, amount, entry_date, reference, created_by" . ($sfSchool ? ", school_id" : "") . ")
                                     VALUES (?, ?, 'Payment', ?, ?, ?, ?, ?" . ($sfSchool ? ", 1" : "") . ")");
            $pIns2->bind_param("iisdssi", $pSid, $year, $pDesc, $pAmt, $pDate, $pRef, $adminId);
            foreach (array_merge(range(1, 8), range(26, 28)) as $n) {
                $adm2 = sprintf('STU-2026-%04d', $n);
                if (!isset($stuId[$adm2])) continue;
                $pRef = 'FSPAY-' . $ym . '-' . $n;
                if ($pick("SELECT id FROM student_fees WHERE reference = ? LIMIT 1", "s", [$pRef]) !== null) continue;
                $pSid = $stuId[$adm2];
                $pAmt = $n <= 25 ? 1500.0 : 1200.0;        // class 5 vs class 4 tuition
                $pIns2->execute();
                $payN++;
            }
            $pIns2->close();
            $say('success', "Fee structures ready — $chN tuition charge(s) for $mLabel, $payN payment(s) recorded");

            // daily registers: the last 5 school days (Sundays skipped), every section marked
            $days = [];
            $d0 = new DateTime('today');
            while (count($days) < 5) { if ($d0->format('w') !== '0') $days[] = $d0->format('Y-m-d'); $d0->modify('-1 day'); }
            $adSid = $adSec = 0; $adDate = ''; $adSt = 'P'; $adRem = null;
            $adIns = $conn->prepare("INSERT IGNORE INTO attendance_daily (student_id, section_id, academic_year_id, att_date, status, remarks, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $adIns->bind_param("iiisssi", $adSid, $adSec, $year, $adDate, $adSt, $adRem, $adminId);
            $adN = 0;
            foreach ($days as $k => $day2) {
                $adDate = $day2;
                for ($n = 1; $n <= 35; $n++) {
                    $adm2 = sprintf('STU-2026-%04d', $n);
                    if (!isset($stuId[$adm2])) continue;
                    [, $adSec,] = $secOf($n);
                    $adSid = $stuId[$adm2];
                    $m = ($n * 3 + $k * 5) % 19;           // deterministic sprinkle: ~1 absent, 1 late, 1 leave per day
                    $adSt  = $m === 0 ? 'A' : ($m === 1 ? 'L' : ($m === 2 ? 'LV' : 'P'));
                    $adRem = $adSt === 'LV' ? 'Family event' : null;
                    $adIns->execute();
                    if ($adIns->affected_rows > 0) $adN++;
                }
            }
            $adIns->close();
            $say('success', "$adN daily register entries over " . count($days) . ' school days — every section, absentees included');

            // weekly timetable: 6 days x 6 periods per section. per-section rotation offsets differ,
            // so a teacher shared across sections can never land in two rooms at the same period
            $tsMap = [];
            $rs = $conn->query("SELECT teacher_id, section_id, subject_id FROM teacher_subjects WHERE academic_year_id = " . (int)$year);
            while ($rs && $r2 = $rs->fetch_assoc()) $tsMap[$r2['section_id'] . '|' . $r2['subject_id']] = (int)$r2['teacher_id'];
            $subIds = array_values($subs);
            $ttSec = $ttD = $ttP = 0; $ttSub = $ttTch = null; $ttSt = $ttEn = '';
            $ttIns = $conn->prepare("INSERT IGNORE INTO timetable_slots (section_id, day_of_week, period_no, subject_id, teacher_id, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ttIns->bind_param("iiiiiss", $ttSec, $ttD, $ttP, $ttSub, $ttTch, $ttSt, $ttEn);
            $ttN = 0;
            foreach (['Class 5-A', 'Class 5-B', 'Class 4-A', 'Class 4-B'] as $off => $sk) {
                $ttSec = $sec[$sk];
                for ($ttD = 1; $ttD <= 6; $ttD++) {
                    for ($ttP = 1; $ttP <= 6; $ttP++) {
                        $ttSub = $subIds[($ttD + $ttP + $off) % 6];
                        $ttTch = $tsMap[$ttSec . '|' . $ttSub] ?? null;
                        $ttSt  = sprintf('%02d:%02d', 8 + intdiv(($ttP - 1) * 40, 60), (($ttP - 1) * 40) % 60);
                        $ttEn  = sprintf('%02d:%02d', 8 + intdiv($ttP * 40, 60), ($ttP * 40) % 60);
                        $ttIns->execute();
                        if ($ttIns->affected_rows > 0) $ttN++;
                    }
                }
            }
            $ttIns->close();
            $say('success', "$ttN timetable period(s) filled — 6 days × 6 periods for all 4 sections, 08:00–12:00");

            // first term date sheet: six papers across one exam week (Mon 15 Sep onward), both classes
            $exCls = $exSub = 0; $exDate = ''; $exRoom = '';
            $exIns = $conn->prepare("INSERT IGNORE INTO exam_schedule (term_id, class_id, subject_id, exam_date, start_time, end_time, room) VALUES (?, ?, ?, ?, '09:00', '11:00', ?)");
            $exIns->bind_param("iiiss", $termId, $exCls, $exSub, $exDate, $exRoom);
            $exN = 0;
            $base = new DateTime('2025-09-15');            // monday of the demo exam week
            foreach ([[$cls['Class 4'], 'Hall 1'], [$cls['Class 5'], 'Hall 2']] as [$ecid, $room2]) {
                $exCls = $ecid; $exRoom = $room2;
                foreach ($subIds as $i2 => $sid2) {
                    $exSub  = $sid2;
                    $exDate = (clone $base)->modify('+' . $i2 . ' day')->format('Y-m-d');
                    $exIns->execute();
                    if ($exIns->affected_rows > 0) $exN++;
                }
            }
            $exIns->close();
            $say('success', "$exN First Term paper(s) dated — one exam week per class, 09:00–11:00");
        }

        // tenant sweep — seeded rows land on the school's main branch NOW, not on the next update run
        foreach (['classes', 'teachers', 'students', 'users'] as $t) {
            if (@$conn->query("SHOW COLUMNS FROM `$t` LIKE 'branch_id'")->num_rows) {
                $conn->query("UPDATE `$t` x JOIN branches b ON b.school_id = x.school_id AND b.is_main = 1
                              SET x.branch_id = b.id WHERE x.branch_id IS NULL");
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $say('error', 'Demo seed rolled back: ' . htmlspecialchars($e->getMessage()));
    }
}

// the whole install funnel: db -> schema -> demo data
function runInstall(bool $reset): bool
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $log = 'setupLog';

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        $conn->set_charset('utf8mb4');
        $db = str_replace('`', '', DB_NAME);
        $conn->query("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db($db);
    } catch (Throwable $e) {
        setupLog('error', 'Connection failed: ' . htmlspecialchars($e->getMessage()));
        return false;
    }
    setupLog('success', 'Database "<strong>' . htmlspecialchars(DB_NAME) . '</strong>" ready &amp; connected');

    // display_errors is off — a throw here would be a blank page, so surface it in the log
    try {
        if ($reset) dropAll($conn, $log);

        // schema source of truth — this file owns none of it (loaded at the top with the gate)
        if (!function_exists('applyUpdates')) {
            setupLog('error', 'applyUpdates() not found in update_setup.php');
            return false;
        }

        // schema phase: report OFF so createTable/addColumnIfMissing return false and log the exact
        // table/column that failed, instead of throwing and collapsing into one generic line
        mysqli_report(MYSQLI_REPORT_OFF);
        applyUpdates($conn, $log);

        // seed phase: throws back on — seedDemoData relies on them to roll its transaction back
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        seedDemoData($conn, $log);
    } catch (Throwable $e) {
        setupLog('error', 'Build failed: ' . htmlspecialchars($e->getMessage()));
        return false;
    }

    logActivity((int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['username'] ?? 'installer'),
        $reset ? 'Database Reset' : 'Database Install',
        $reset ? 'setup.php dropped every table and rebuilt a fresh demo system'
               : 'setup.php built/repaired the schema and seeded demo data');

    setupLog('success', '<strong>' . ($reset ? 'Reset complete — fresh system built.' : 'Setup completed successfully!') . '</strong>');
    return true;
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
    <title>Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-database"></i> Database Setup</h2>
        <p class="subtitle">Online Result Management System &mdash; installer (schema is owned by <code>update_setup.php</code>)</p>
        <hr>

        <?php if ($action === 'reset' && !$confirmed): ?>

            <div class="warning-message">
                <i class="fas fa-triangle-exclamation"></i> <strong>Danger zone — this cannot be undone.</strong>
                Reset DROPS every table in <strong><?php echo htmlspecialchars(DB_NAME); ?></strong>.
                All users, students, teachers, marks, results and settings are permanently deleted, then a fresh demo system is built.
            </div>
            <?php if ($staleToken): ?>
            <div class="log-item log-error mt-20">
                <i class="fas fa-times-circle"></i> Security token expired — nothing was dropped. Confirm again below.
            </div>
            <?php endif; ?>

            <!-- csrf-signed POST is the real control; the confirm() is only a UX speed bump -->
            <form method="post" action="setup.php"
                  onsubmit="return confirm('This DROPS every table and deletes ALL data in <?php echo htmlspecialchars(DB_NAME, ENT_QUOTES); ?>. Continue?');">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>">
                <button type="submit" class="btn btn-danger mt-20">
                    <i class="fas fa-trash-can"></i> Yes — Drop Everything &amp; Rebuild
                </button>
            </form>
            <a href="setup.php" class="btn btn-secondary mt-20"><i class="fas fa-arrow-left"></i> Cancel</a>

        <?php else: ?>

            <?php $ok = runInstall($action === 'reset'); ?>

            <?php if ($ok): ?>
            <div class="credentials-box">
                <strong><i class="fas fa-key"></i> Demo Login Credentials — one per role:</strong><br><br>
                <strong>App Owner:</strong> appowner / appowner123 &nbsp;<em>(platform: schools, plans, gateways)</em><br>
                <strong>School Owner:</strong> owner / owner123 &nbsp;<em>(this school + its subscription)</em><br>
                <strong>Admin:</strong> admin / admin123<br>
                <strong>Principal:</strong> principal / principal123<br>
                <strong>Branch Admin:</strong> branchadmin / branch123<br>
                <strong>Teacher:</strong> teacher1 / teacher123 &nbsp;·&nbsp; teacher2 / teacher123<br>
                <strong>Student:</strong> STU-2026-0001 … STU-2026-0035 / student123<br><br>
                <strong><i class="fas fa-code-branch"></i> Two campuses are seeded:</strong>
                <em>Main Branch</em> (500 seats, all 35 students, head teacher in charge) and an empty
                <em>City Campus</em> &mdash; the empty one is there so the seat meter, the main-branch move,
                the school re-allocation and the &ldquo;a branch holding records cannot be deleted&rdquo; rule
                can all be tried without touching real data.<br><br>
                <em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Please change these passwords after first login!</em>
            </div>
            <?php endif; ?>

            <a href="login.php" class="btn"><i class="fas fa-sign-in-alt"></i> Go to Login Page</a>

            <div class="log-item log-info mt-30">
                <i class="fas fa-circle-info"></i> Updating a <strong>live</strong> database is not done here — there is no update action on this page.
                Run <a href="update_setup.php">update_setup.php</a> after any deploy: it adds only what is missing and never touches existing data.
            </div>

            <?php if ($isAdmin): // reset entry point exists for signed-in admins only ?>
            <div class="warning-message mt-20">
                <i class="fas fa-triangle-exclamation"></i> <strong>Danger zone.</strong>
                Reset wipes every table and rebuilds a fresh demo system. Never run it on a live database.
            </div>
            <a href="?action=reset" class="btn btn-danger mt-20"><i class="fas fa-rotate-left"></i> Reset &amp; Rebuild Database</a>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    </div>

    <script>
    // Theme Toggle for Setup Page
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();
    </script>
</body>
</html>
