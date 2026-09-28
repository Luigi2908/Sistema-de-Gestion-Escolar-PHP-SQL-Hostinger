<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

require_once 'config.php';
require_once 'result_engine.php';

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

// rbac view gate — all 3 roles hold dashboard:v
requirePerm('dashboard', 'v');

$username     = $_SESSION['username'];
$role         = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
$user_id      = (int)$_SESSION['user_id'];
$current_page = 'dashboard';

$isAdmin = ($role === 'Admin');
$isWide  = ormsSchoolWide($role);   // admin + principal reach every row; $isAdmin stays literally Admin
$isPlat  = ormsIsPlatform();
$school  = sid();                   // "school-wide" below means THIS school, never the whole table
$isOwner  = ($role === 'School Owner');   // tenant's top role — admin's reach plus the money
$isBranch = ($role === 'Branch Admin');   // pinned to one campus: bid() is the fence, never a picker

/* -------------------------------------------------------------------
   Tenant scoping for the aggregates. Two rules this page has to obey:
   1. the filter belongs on the OUTERMOST driver. scoping `marks` while the driver is still
      `FROM sections sec` counts every school's expected against only your entered, and
      completion can then never reach 100%.
   2. every fragment that comes back non-empty carries exactly ONE `?`, so the param that
      feeds it must be pushed in the same order the fragment appears in the sql.
   ------------------------------------------------------------------- */

// tenant column there yet? one probe per table/column per request — a pre-migration install
// keeps the old, unscoped maths instead of erroring the whole dashboard out
function dashHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

// " AND <alias>.school_id = ?" or '' when the column isn't there yet
function dashScope(string $table, string $alias): string {
    return dashHasCol($table, 'school_id') ? " AND $alias.school_id = ?" : '';
}

// THE marks tenant predicate — the one that has to read the same in every file that counts
// entered marks (results / result_engine / marks_entry / this file, four copies here)
function dashMarksScope(): string { return dashScope('marks', 'm'); }

// " AND <alias>.branch_id = ?" for a campus-pinned read, '' otherwise. classes carry the branch,
// students/teachers/users inherit it; sections, marks and summaries reach it through their class
function dashBranchAnd(string $table, string $alias, int $branch): string {
    return $branch > 0 && dashHasCol($table, 'branch_id') ? " AND $alias.branch_id = ?" : '';
}

// year the whole page talks about
$year   = ormsCurrentYear();
$yearId = $year ? (int)$year['id'] : 0;

// the term the dashboard reports on — the open one, else the last of the year
function dashActiveTerm(int $yearId): ?array {
    if (!$yearId) return null;
    $open = ormsTerms($yearId, true);
    if ($open) return $open[0];
    $all = ormsTerms($yearId);
    return $all ? end($all) : null;
}

// most recently published term — every "latest published" figure hangs off this
function dashLatestPublishedTerm(): ?array {
    try {
        $sc = dashScope('result_publications', 'rp');
        return qOne("SELECT t.id, t.name, MAX(rp.published_at) AS published_at
                     FROM result_publications rp
                     JOIN exam_terms t ON t.id = rp.term_id
                     WHERE rp.is_published = 1" . $sc . "
                     GROUP BY t.id, t.name
                     ORDER BY published_at DESC, t.id DESC
                     LIMIT 1", $sc ? 'i' : '', ...($sc ? [sid()] : []));
    } catch (Throwable $e) { return null; }
}

// expected-marks subselects for a `sections sec` row — ONE definition, both the admin and principal
// aggregates read it. core subjects x active students + each student's own electives.
// no tenant leg of its own on purpose: every subselect hangs off `sec`, and `sec` is joined to a
// school-filtered `classes` at both call sites, so the scope arrives through the driver
function dashExpectSql(): array {
    return ormsHasElectives()
        ? ["(SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = sec.class_id AND cs.is_optional = 0)",
           "(SELECT COUNT(*) FROM student_subjects ss
               JOIN students st3       ON st3.id = ss.student_id AND st3.section_id = sec.id AND st3.status = 'Active'
               JOIN class_subjects cs3 ON cs3.class_id = st3.class_id AND cs3.subject_id = ss.subject_id AND cs3.is_optional = 1
             WHERE ss.academic_year_id = ?)"]
        : ["(SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = sec.class_id)", "0"];
}

// approval cols migrated yet? one probe per request — a stale install still gets the rest of the page
function dashHasApproval(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT approval_status FROM result_publications LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// money still owed + how full the classrooms were. probe-gated, so a stale install just skips the tiles.
// arrears are netted PER STUDENT and credits are dropped — one family in credit must never hide another's debt
function dashOwedAttendance(int $yearId, int $termId): array {
    $out = ['fees' => null, 'attendance' => null];
    $s   = sid();
    if (ormsHasFees()) {
        try {
            // the netting runs INSIDE the derived table, so the tenant leg has to go in there too —
            // filtering the outer t.bal would still net another school's rows into the balance
            $whr = []; $ty = ''; $pv = [];
            if ($yearId)                                  { $whr[] = 'sf.academic_year_id = ?'; $ty .= 'i'; $pv[] = $yearId; }
            if (dashHasCol('student_fees', 'school_id'))  { $whr[] = 'sf.school_id = ?';        $ty .= 'i'; $pv[] = $s; }
            $sql = "SELECT COALESCE(SUM(t.bal), 0) AS owed, COUNT(*) AS students
                    FROM (SELECT sf.student_id, SUM(CASE WHEN sf.entry_type = 'Payment' THEN -sf.amount ELSE sf.amount END) AS bal
                            FROM student_fees sf" . ($whr ? " WHERE " . implode(' AND ', $whr) : '') . "
                           GROUP BY sf.student_id) t
                    WHERE t.bal > 0";
            $r   = $pv ? qOne($sql, $ty, ...$pv) : qOne($sql);
            $owe = round((float)($r['owed'] ?? 0), 2);
            $out['fees'] = ['owed' => $owe, 'money' => ormsMoney($owe), 'students' => (int)($r['students'] ?? 0)];
        } catch (Throwable $e) { $out['fees'] = null; }
    }
    if ($termId && ormsHasAttendance()) {
        try {
            $sc = dashScope('attendance_summary', 'a');
            $r  = qOne("SELECT COALESCE(SUM(a.days_present), 0) AS p, COALESCE(SUM(a.days_total), 0) AS t, COUNT(*) AS n
                        FROM attendance_summary a WHERE a.term_id = ?" . $sc,
                       $sc ? 'ii' : 'i', ...($sc ? [$termId, $s] : [$termId]));
            $p = (float)($r['p'] ?? 0); $t = (float)($r['t'] ?? 0);
            $out['attendance'] = ['pct' => $t > 0 ? round($p / $t * 100, 1) : 0.0, 'students' => (int)($r['n'] ?? 0)];
        } catch (Throwable $e) { $out['attendance'] = null; }
    }
    return $out;
}


// the "today" strip every school-side view shares: the register, money in, the next exam, timetable
// fill and unread alerts — ONE call, every leg probe-gated and best-effort, so a table that is not
// installed yet returns null and its tile simply does not render. $branch > 0 fences it to one campus
function dashOps(int $yearId, int $termId, int $branch, int $userId): array {
    $s = sid(); $today = date('Y-m-d');
    $out = ['today' => $today, 'attendance' => null, 'fees' => null, 'exam' => null, 'timetable' => null, 'unread' => 0];
    $scC = dashScope('classes', 'c');
    $brC = dashBranchAnd('classes', 'c', $branch);
    $fp  = []; if ($scC) $fp[] = $s; if ($brC) $fp[] = $branch;     // sections-through-classes fence, reused below
    $ft  = str_repeat('i', count($fp));
    $secTotal = 0;
    try { $secTotal = (int) qVal("SELECT COUNT(*) FROM sections sec JOIN classes c ON c.id = sec.class_id WHERE sec.is_active = 1" . $scC . $brC, $ft, ...$fp); }
    catch (Throwable $e) {}

    // today's register — P/A/L/LV rows written so far, and how many sections have been marked at all
    if (dashHasCol('attendance_daily', 'att_date')) {
        try {
            $r = qOne("SELECT COUNT(*) AS marked, COALESCE(SUM(ad.status = 'P'), 0) AS present,
                              COALESCE(SUM(ad.status = 'A'), 0) AS absent, COUNT(DISTINCT ad.section_id) AS secs
                       FROM attendance_daily ad
                       JOIN sections sec ON sec.id = ad.section_id
                       JOIN classes  c   ON c.id   = sec.class_id
                       WHERE ad.att_date = ?" . $scC . $brC, 's' . $ft, $today, ...$fp);
            $m = (int)($r['marked'] ?? 0);
            $out['attendance'] = ['marked' => $m, 'present' => (int)($r['present'] ?? 0), 'absent' => (int)($r['absent'] ?? 0),
                                  'pct' => $m > 0 ? round((int)$r['present'] / $m * 100, 1) : 0,
                                  'secs' => (int)($r['secs'] ?? 0), 'secs_total' => $secTotal];
        } catch (Throwable $e) {}
    }

    // money in — payments today and this calendar month. student_fees has no campus column, so a
    // branch read walks through the student row
    if (ormsHasFees()) {
        try {
            $scF = dashScope('student_fees', 'sf');
            $brJ = $branch > 0 && dashHasCol('students', 'branch_id') ? " JOIN students stb ON stb.id = sf.student_id AND stb.branch_id = ?" : '';
            $ym  = date('Y-m'); $from = $ym . '-01';
            // sql order: the three CASE ?s in the select list, THEN the join's campus, then the WHERE
            $p = [$ym, $today, $ym]; $t = 'sss';
            if ($brJ) { $p[] = $branch; $t .= 'i'; }
            $p[] = $from; $t .= 's';
            if ($scF) { $p[] = $s; $t .= 'i'; }
            $r = qOne("SELECT COALESCE(SUM(CASE WHEN DATE_FORMAT(sf.entry_date, '%Y-%m') = ? THEN sf.amount END), 0) AS month_amt,
                              COALESCE(SUM(CASE WHEN sf.entry_date = ? THEN sf.amount END), 0)                    AS today_amt,
                              COALESCE(SUM(DATE_FORMAT(sf.entry_date, '%Y-%m') = ?), 0)                           AS month_n
                       FROM student_fees sf" . $brJ . "
                       WHERE sf.entry_type = 'Payment' AND sf.entry_date >= ?" . $scF, $t, ...$p);
            $out['fees'] = ['month' => (float)$r['month_amt'], 'month_f' => ormsMoney((float)$r['month_amt']),
                            'today' => (float)$r['today_amt'], 'today_f' => ormsMoney((float)$r['today_amt']),
                            'month_n' => (int)$r['month_n'], 'month_name' => date('M Y')];
        } catch (Throwable $e) {}
    }

    // the next exam on the date sheet + how many sit inside the coming week
    if (dashHasCol('exam_schedule', 'exam_date')) {
        try {
            $p = [$today]; $t = 's'; if ($scC) { $p[] = $s; $t .= 'i'; } if ($brC) { $p[] = $branch; $t .= 'i'; }
            $r = qOne("SELECT es.exam_date, es.start_time, es.room, su.name AS subject, c.name AS class_name, tm.name AS term
                       FROM exam_schedule es
                       JOIN classes    c  ON c.id  = es.class_id
                       JOIN subjects   su ON su.id = es.subject_id
                       JOIN exam_terms tm ON tm.id = es.term_id
                       WHERE es.exam_date >= ?" . $scC . $brC . "
                       ORDER BY es.exam_date ASC, es.start_time ASC LIMIT 1", $t, ...$p);
            $wk = (int) qVal("SELECT COUNT(*) FROM exam_schedule es JOIN classes c ON c.id = es.class_id
                              WHERE es.exam_date BETWEEN ? AND ?" . $scC . $brC, 's' . $t, $today, date('Y-m-d', strtotime('+7 days')), ...array_slice($p, 1));
            $out['exam'] = ['week' => $wk] + ($r ? [
                'subject' => $r['subject'], 'class' => $r['class_name'], 'term' => $r['term'], 'room' => (string)$r['room'],
                'date' => $r['exam_date'], 'on' => date('d M', strtotime($r['exam_date'])),
                'time' => $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : '',
                'days' => (int) floor((strtotime($r['exam_date']) - strtotime($today)) / 86400)] : []);
        } catch (Throwable $e) {}
    }

    // timetable fill — sections that have at least one period on the grid
    if (dashHasCol('timetable_slots', 'period_no')) {
        try {
            $n = (int) qVal("SELECT COUNT(DISTINCT ts.section_id) FROM timetable_slots ts
                             JOIN sections sec ON sec.id = ts.section_id JOIN classes c ON c.id = sec.class_id
                             WHERE 1 = 1" . $scC . $brC, $ft, ...$fp);
            $out['timetable'] = ['filled' => $n, 'total' => $secTotal, 'pct' => $secTotal > 0 ? round($n / $secTotal * 100) : 0];
        } catch (Throwable $e) {}
    }

    try { $out['unread'] = (int) qVal("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", 'i', $userId); }
    catch (Throwable $e) {}
    return $out;
}

// every campus of this school with its headcounts — one query, correlated counts, never a query per branch
function dashBranches(): array {
    if (!dashHasCol('branches', 'school_id')) return [];
    $st  = dashHasCol('students', 'branch_id') ? "(SELECT COUNT(*) FROM students st WHERE st.branch_id = b.id AND st.status = 'Active')" : "0";
    $te  = dashHasCol('teachers', 'branch_id') ? "(SELECT COUNT(*) FROM teachers te WHERE te.branch_id = b.id AND te.status = 'Active')" : "0";
    $cl  = dashHasCol('classes',  'branch_id') ? "(SELECT COUNT(*) FROM classes cl WHERE cl.branch_id = b.id AND cl.is_active = 1)" : "0";
    $cap = dashHasCol('branches', 'capacity') ? "b.capacity" : "0";
    $cty = dashHasCol('branches', 'city') ? "b.city" : "''";
    try {
        return array_map(fn($b) => [
            'id' => (int)$b['id'], 'name' => $b['name'], 'code' => $b['code'], 'city' => (string)$b['city'],
            'main' => (int)$b['is_main'], 'status' => $b['status'], 'capacity' => (int)$b['capacity'],
            'students' => (int)$b['students'], 'teachers' => (int)$b['teachers'], 'classes' => (int)$b['classes'],
            'fill' => (int)$b['capacity'] > 0 ? round((int)$b['students'] / (int)$b['capacity'] * 100) : null,
        ], qAll("SELECT b.id, b.name, b.code, b.is_main, b.status, $cap AS capacity, $cty AS city,
                        $st AS students, $te AS teachers, $cl AS classes
                 FROM branches b WHERE b.school_id = ? ORDER BY b.is_main DESC, b.name ASC", 'i', sid()));
    } catch (Throwable $e) { return []; }
}

// the owner's money card: plan, period, seats, the last few invoices. reads the SAME resolvers
// billing.php uses (ormsSubscriptionState / ormsQuotaAll / bilCurrencyFor) so the two never disagree
function dashSub(): ?array {
    $s = sid();
    if ($s <= 0) return null;
    try {
        if (is_file(__DIR__ . '/billing_engine.php')) require_once __DIR__ . '/billing_engine.php';
        if (function_exists('bilExpireStale')) { try { bilExpireStale($s); } catch (Throwable $e) {} }
        $state = ormsSubscriptionState($s);
        $days  = !empty($state['ends_at']) ? (int) floor((strtotime($state['ends_at']) - strtotime(date('Y-m-d'))) / 86400) : null;
        $sc    = qOne("SELECT s.name, s.code, s.status, s.trial_ends_at, p.name AS plan_name
                       FROM schools s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?", 'i', $s) ?: [];
        $inv = []; $pendN = 0; $pendAmt = 0.0;
        try {
            $inv = qAll("SELECT i.invoice_no, i.cycle, i.amount, i.currency, i.status, i.gateway, i.period_start, i.period_end, i.created_at, p.name AS plan_name
                         FROM billing_invoices i LEFT JOIN plans p ON p.id = i.plan_id
                         WHERE i.school_id = ? ORDER BY i.id DESC LIMIT 5", 'i', $s);
            $pd = qOne("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM billing_invoices WHERE school_id = ? AND status = 'Pending'", 'i', $s);
            $pendN = (int)($pd['n'] ?? 0); $pendAmt = (float)($pd['amt'] ?? 0);
        } catch (Throwable $e) {}
        $ccy = function_exists('bilCurrencyFor') ? bilCurrencyFor($s) : ormsCurrency()['code'];
        return [
            'mode' => ormsPlatformMode() ? 1 : 0, 'plan' => (string)($sc['plan_name'] ?? ''), 'school' => (string)($sc['name'] ?? ''),
            'code' => (string)($sc['code'] ?? ''), 'school_status' => (string)($sc['status'] ?? ''),
            'status' => $state['status'], 'ends_at' => $state['ends_at'], 'days' => $days, 'currency' => $ccy,
            'quotas' => array_values(ormsQuotaAll($s)), 'invoices' => $inv, 'pend_n' => $pendN, 'pend_amt' => $pendAmt,
            'billing_on' => function_exists('bilEnabled') ? (bilEnabled() ? 1 : 0) : 0,
        ];
    } catch (Throwable $e) { return null; }
}

// the term both charts follow: posted id whitelisted against this year's terms, default = the most
// recently published term, else the active one. never trusted straight off the request
function dashChartTerm(): array {
    $yearId = (int)$GLOBALS['yearId'];
    $terms  = $yearId ? ormsTerms($yearId) : [];
    $ctId   = (int)($_POST['term_id'] ?? $_GET['term_id'] ?? 0);
    if (!in_array($ctId, array_map('intval', array_column($terms, 'id')), true)) {
        $pub  = dashLatestPublishedTerm();
        $ctId = $pub ? (int)$pub['id'] : (int)$GLOBALS['activeTermId'];
    }
    $name = '';
    foreach ($terms as $t) if ((int)$t['id'] === $ctId) $name = $t['name'];
    return [$ctId, $name, $terms];
}

// entry progress per section for one term — the same aggregate the wide views run, plus the approval
// state. classes is the driver's join, so the school (+campus) filter goes on the outer WHERE
function dashSectionProgress(int $termId, int $branch = 0): array {
    if (!$termId) return [];
    $yearId  = (int)$GLOBALS['yearId'];
    $school  = sid();
    $hasEl   = ormsHasElectives();
    $hasAppr = dashHasApproval();
    [$subsSel, $elSel] = dashExpectSql();
    $scM = dashMarksScope();
    $scCl = dashScope('classes', 'c');
    $brCl = dashBranchAnd('classes', 'c', $branch);
    $apprSel = $hasAppr ? "COALESCE(rp.approval_status, 'Draft')" : "'Draft'";
    $sql = "SELECT c.name AS class_name, sec.name AS section_name, sec.id AS section_id,
                   (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status = 'Active') AS stu,
                   $subsSel AS subs, $elSel AS elect,
                   (SELECT COUNT(*) FROM marks m
                      JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                      JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                    WHERE m.section_id = sec.id AND m.term_id = ?" . $scM . "
                      AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . ") AS ent,
                   COALESCE(rp.is_published, 0) AS is_published,
                   $apprSel AS approval
            FROM sections sec
            JOIN classes c ON c.id = sec.class_id
            LEFT JOIN result_publications rp ON rp.section_id = sec.id AND rp.term_id = ?
            WHERE sec.is_active = 1" . $scCl . $brCl . "
            ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC";
    // sql order: elective year -> marks term -> marks school -> rp term -> driver school -> campus
    $p = [];
    if ($hasEl) $p[] = $yearId;
    $p[] = $termId;
    if ($scM) $p[] = $school;
    $p[] = $termId;
    if ($scCl) $p[] = $school;
    if ($brCl) $p[] = $branch;
    $rows = qAll($sql, str_repeat('i', count($p)), ...$p);
    return array_values(array_filter(array_map(function ($r) {
        $exp = (int)$r['stu'] * (int)$r['subs'] + (int)$r['elect'];
        $ent = min((int)$r['ent'], $exp);   // stale marks can never push past 100%
        return ['section_id' => (int)$r['section_id'], 'label' => $r['class_name'] . ' – ' . $r['section_name'],
                'entered' => $ent, 'expected' => $exp, 'pct' => $exp > 0 ? round($ent / $exp * 100) : 0,
                'published' => (int)$r['is_published'] === 1 ? 1 : 0, 'approval' => (string)$r['approval']];
    }, $rows), fn($r) => $r['expected'] > 0));
}

// the school-side aggregate every wide dashboard reads — admin, owner, and the branch admin fenced to
// one campus. ONE definition so three views can never disagree on a figure. every subselect is its own
// SELECT, so each carries its own tenant leg and, when $branch > 0, its own campus leg right after it.
// subjects stay school-wide: they have no campus. params are pushed in the exact order the ?s appear.
function dashSchoolPayload(int $ctId, string $ctName, array $terms, int $branch = 0): array {
    $school       = sid();
    $yearId       = (int)$GLOBALS['yearId'];
    $activeTermId = (int)$GLOBALS['activeTermId'];
    $pub   = dashLatestPublishedTerm();
    $pubId = $pub ? (int)$pub['id'] : 0;
    $hasBr = $branch > 0 && dashHasCol('classes', 'branch_id');

    // ---- headline counters, ONE round trip
    $f  = ['students' => dashScope('students', 'st'), 'teachers' => dashScope('teachers', 'te'),
           'classes'  => dashScope('classes', 'cl'),  'sections' => dashScope('classes', 'c2'),
           'subjects' => dashScope('subjects', 'su'), 'subjects_all' => dashScope('subjects', 'sa'),
           'published' => dashScope('result_publications', 'rp')];
    $b  = ['students' => dashBranchAnd('students', 'st', $branch), 'teachers' => dashBranchAnd('teachers', 'te', $branch),
           'classes'  => dashBranchAnd('classes', 'cl', $branch),  'sections' => dashBranchAnd('classes', 'c2', $branch)];
    $pubJoin = $hasBr ? " JOIN sections s5 ON s5.id = rp.section_id JOIN classes c5 ON c5.id = s5.class_id" : '';
    $bP      = $hasBr ? " AND c5.branch_id = ?" : '';
    $cSql = "SELECT
                 (SELECT COUNT(*) FROM students st WHERE st.status = 'Active'{$f['students']}{$b['students']})  AS students,
                 (SELECT COUNT(*) FROM teachers te WHERE te.status = 'Active'{$f['teachers']}{$b['teachers']})  AS teachers,
                 (SELECT COUNT(*) FROM classes  cl WHERE cl.is_active = 1{$f['classes']}{$b['classes']})        AS classes,
                 (SELECT COUNT(*) FROM sections sec
                    JOIN classes c2 ON c2.id = sec.class_id
                   WHERE sec.is_active = 1{$f['sections']}{$b['sections']})                                     AS sections,
                 (SELECT COUNT(*) FROM subjects su WHERE su.is_active = 1{$f['subjects']})                      AS subjects,
                 (SELECT COUNT(*) FROM subjects sa WHERE 1 = 1{$f['subjects_all']})                             AS subjects_all,
                 (SELECT COUNT(*) FROM result_publications rp{$pubJoin}
                   WHERE rp.term_id = ? AND rp.is_published = 1{$f['published']}{$bP})                          AS published";
    $cp = [];
    foreach (['students', 'teachers', 'classes', 'sections'] as $k) { if ($f[$k]) $cp[] = $school; if ($b[$k]) $cp[] = $branch; }
    foreach (['subjects', 'subjects_all'] as $k) if ($f[$k]) $cp[] = $school;
    $cp[] = $activeTermId;
    if ($f['published']) $cp[] = $school;
    if ($bP) $cp[] = $branch;
    $c = qOne($cSql, str_repeat('i', count($cp)), ...$cp) ?: [];

    // ---- term-wide completion as ONE aggregate, clamped per section
    $hasEl = ormsHasElectives();
    [$subsSel, $elSel] = dashExpectSql();
    $scM = dashMarksScope();
    $scC = dashScope('classes', 'c');
    $brC = dashBranchAnd('classes', 'c', $branch);
    $compSql = "SELECT COALESCE(SUM(t.stu * t.subs + t.elect), 0)                  AS expected,
                       COALESCE(SUM(LEAST(t.ent, t.stu * t.subs + t.elect)), 0)    AS entered
                FROM (SELECT
                        (SELECT COUNT(*) FROM students st
                          WHERE st.section_id = sec.id AND st.status = 'Active')      AS stu,
                        $subsSel                                                      AS subs,
                        $elSel                                                        AS elect,
                        (SELECT COUNT(*) FROM marks m
                           JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                           JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                         WHERE m.section_id = sec.id AND m.term_id = ?" . $scM . "
                           AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . ")     AS ent
                      FROM sections sec
                      JOIN classes c ON c.id = sec.class_id
                      WHERE sec.is_active = 1" . $scC . $brC . ") t";
    $cmp = [];
    if ($hasEl) $cmp[] = $yearId;
    $cmp[] = $activeTermId;
    if ($scM) $cmp[] = $school;
    if ($scC) $cmp[] = $school;
    if ($brC) $cmp[] = $branch;
    $comp = qOne($compSql, str_repeat('i', count($cmp)), ...$cmp) ?: ['entered' => 0, 'expected' => 0];
    $entered  = (int)$comp['entered'];
    $expected = (int)$comp['expected'];

    // ---- pass rate of the latest published term. summaries reach the campus through their section's class
    $scRs   = dashScope('result_summaries', 'rs');
    $rsJoin = $hasBr ? " JOIN sections s6 ON s6.id = rs.section_id JOIN classes c6 ON c6.id = s6.class_id" : '';
    $bRs    = $hasBr ? " AND c6.branch_id = ?" : '';
    $rsT    = 'i' . ($scRs ? 'i' : '') . ($bRs ? 'i' : '');
    $rsP    = static fn(int $term) => array_merge([$term], $scRs ? [$school] : [], $bRs ? [$branch] : []);
    $rate = $pubId ? qOne("SELECT COUNT(*) AS total, SUM(rs.result_status = 'PASS') AS passed
                           FROM result_summaries rs
                           JOIN result_publications rp
                             ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1" . $rsJoin . "
                           WHERE rs.term_id = ?" . $scRs . $bRs, $rsT, ...$rsP($pubId)) : null;
    $rateTot = $rate ? (int)$rate['total'] : 0;
    $ratePas = $rate ? (int)$rate['passed'] : 0;

    // ---- bar chart: pass % per class of the picked term, one GROUP BY
    $bcT = 'i' . ($scC ? 'i' : '') . ($brC ? 'i' : '');
    $bcP = array_merge([$ctId], $scC ? [$school] : [], $brC ? [$branch] : []);
    $byClass = $ctId ? qAll("SELECT c.name AS label, COUNT(*) AS total, SUM(rs.result_status = 'PASS') AS passed
                              FROM result_summaries rs
                              JOIN sections sec ON sec.id = rs.section_id
                              JOIN classes  c   ON c.id   = sec.class_id
                              JOIN result_publications rp
                                ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1
                              WHERE rs.term_id = ?" . $scC . $brC . "
                              GROUP BY c.id, c.name, c.sort_order
                              ORDER BY c.sort_order ASC, c.name ASC", $bcT, ...$bcP) : [];

    // ---- doughnut: grade spread of the picked term, folded to ONE row per letter first
    $grades = $ctId ? qAll("SELECT rs.grade AS label, COUNT(*) AS n
                            FROM result_summaries rs
                            JOIN result_publications rp
                              ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1" . $rsJoin . "
                            LEFT JOIN (SELECT grade, MAX(min_percent) AS min_percent
                                         FROM grading_scheme GROUP BY grade) gs ON gs.grade = rs.grade
                            WHERE rs.term_id = ?" . $scRs . $bRs . "
                            GROUP BY rs.grade, gs.min_percent
                            ORDER BY gs.min_percent DESC, rs.grade ASC", $rsT, ...$rsP($ctId)) : [];

    // ---- this month's intake per kpi (the caption under each box), ONE round trip.
    //      try/catch keeps a pre-migration install rendering flat boxes instead of an error
    $delta = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'subjects' => 0, 'published' => 0];
    try {
        $from = date('Y-m-01 00:00:00');
        $arm  = ['students' => [dashScope('students', 'sp1'), dashBranchAnd('students', 'sp1', $branch)],
                 'teachers' => [dashScope('teachers', 'sp2'), dashBranchAnd('teachers', 'sp2', $branch)],
                 'classes'  => [dashScope('classes', 'sp3'),  dashBranchAnd('classes', 'sp3', $branch)],
                 'subjects' => [dashScope('subjects', 'sp4'), ''],
                 'published' => [dashScope('result_publications', 'sp5'), '']];
        $gt = ''; $gp = [];
        foreach ($arm as [$sc, $br]) {                       // date first, school second, campus third, per arm
            $gt .= 's'; $gp[] = $from;
            if ($sc) { $gt .= 'i'; $gp[] = $school; }
            if ($br) { $gt .= 'i'; $gp[] = $branch; }
        }
        $g = qAll("SELECT 'students' AS k, COUNT(*) AS n FROM students sp1 WHERE sp1.created_at >= ?{$arm['students'][0]}{$arm['students'][1]}
                   UNION ALL SELECT 'teachers', COUNT(*) FROM teachers sp2 WHERE sp2.created_at >= ?{$arm['teachers'][0]}{$arm['teachers'][1]}
                   UNION ALL SELECT 'classes', COUNT(*) FROM classes sp3 WHERE sp3.created_at >= ?{$arm['classes'][0]}{$arm['classes'][1]}
                   UNION ALL SELECT 'subjects', COUNT(*) FROM subjects sp4 WHERE sp4.created_at >= ?{$arm['subjects'][0]}
                   UNION ALL SELECT 'published', COUNT(*) FROM result_publications sp5 WHERE sp5.is_published = 1 AND sp5.published_at >= ?{$arm['published'][0]}",
                  $gt, ...$gp);
        foreach ($g as $row) if (isset($delta[$row['k']])) $delta[$row['k']] = (int)$row['n'];
    } catch (Throwable $e) {}

    return [
        'kpi' => [
            'students'     => (int)($c['students'] ?? 0),
            'teachers'     => (int)($c['teachers'] ?? 0),
            'classes'      => (int)($c['classes'] ?? 0),
            'sections'     => (int)($c['sections'] ?? 0),
            'subjects'     => (int)($c['subjects'] ?? 0),
            'subjects_all' => (int)($c['subjects_all'] ?? 0),
            'published'    => (int)($c['published'] ?? 0)
        ],
        'completion' => ['entered' => $entered, 'expected' => $expected,
                         'pct' => $expected > 0 ? round($entered / $expected * 100, 1) : 0],
        'pass_rate'  => ['total' => $rateTot, 'passed' => $ratePas,
                         'pct' => $rateTot > 0 ? round($ratePas / $rateTot * 100, 1) : 0],
        'pub_term'   => $pub ? $pub['name'] : '',
        'chart_term' => $ctId,
        'chart_name' => $ctName,
        'terms'      => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $terms),
        'by_class'   => array_map(fn($r) => ['label' => $r['label'],
                            'pct' => (int)$r['total'] > 0 ? round((int)$r['passed'] / (int)$r['total'] * 100, 1) : 0,
                            'total' => (int)$r['total']], $byClass),
        'grades'     => array_map(fn($r) => ['label' => $r['label'] ?: '—', 'n' => (int)$r['n']], $grades),
        'money'      => dashOwedAttendance($yearId, $activeTermId),
        'delta'      => $delta
    ];
}

$activeTerm   = dashActiveTerm($yearId);
$activeTermId = $activeTerm ? (int)$activeTerm['id'] : 0;

// Handle AJAX requests
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        switch ($action) {

            // ---------------------------------------------------------- admin
            case 'adminStats':
                if (!$isAdmin) jsonErr('Access denied');   // school-wide kpis are admin-only — a crud bit must never open them
                [$ctId, $ctName, $terms] = dashChartTerm();
                jsonOk(dashSchoolPayload($ctId, $ctName, $terms) + [
                    'ops'      => dashOps($yearId, $activeTermId, 0, $user_id),
                    'sections' => dashSectionProgress($activeTermId),
                ]);

            // ---------------------------------------------------------- school owner
            // admin's whole aggregate PLUS the money the owner is actually responsible for — plan, period,
            // seats, invoices — and every campus side by side. same payload builder, never a second maths
            case 'ownerStats':
                if (!$isOwner) jsonErr('Access denied');
                [$ctId, $ctName, $terms] = dashChartTerm();
                jsonOk(dashSchoolPayload($ctId, $ctName, $terms) + [
                    'ops'      => dashOps($yearId, $activeTermId, 0, $user_id),
                    'sections' => dashSectionProgress($activeTermId),
                    'sub'      => dashSub(),
                    'branches' => dashBranches(),
                ]);

            // ---------------------------------------------------------- branch admin
            // the admin aggregate fenced to ONE campus. bid() is the session pin, never a posted id
            case 'branchStats':
                if (!$isBranch || bid() <= 0) jsonErr('Access denied');
                [$ctId, $ctName, $terms] = dashChartTerm();
                $brName = '';
                try { $brName = (string) qVal("SELECT name FROM branches WHERE id = ?", 'i', bid()); } catch (Throwable $e) {}
                jsonOk(dashSchoolPayload($ctId, $ctName, $terms, bid()) + [
                    'ops'      => dashOps($yearId, $activeTermId, bid(), $user_id),
                    'sections' => dashSectionProgress($activeTermId, bid()),
                    'branch'   => ['id' => bid(), 'name' => $brName],
                ]);

            // ------------------------------------------------------ principal
            // school-wide oversight. name ends in "stats" on purpose — the client treats it as a READ
            case 'principalStats':
                if (!ormsSchoolWide($role)) jsonErr('Access denied');   // reach is identity, never a crud bit

                $hasEl   = ormsHasElectives();
                $hasAppr = dashHasApproval();
                [$subsSel, $elSel] = dashExpectSql();

                $scRp = dashScope('result_publications', 'rp');
                $scRs = dashScope('result_summaries', 'rs');
                $scCl = dashScope('classes', 'c');

                // frozen figures report the active term, else the last term that actually has published rows
                $pubTerm = ($activeTermId && qVal("SELECT 1 FROM result_publications rp WHERE rp.term_id = ? AND rp.is_published = 1" . $scRp . " LIMIT 1",
                                                  $scRp ? 'ii' : 'i', ...($scRp ? [$activeTermId, $school] : [$activeTermId])))
                         ? $activeTerm : dashLatestPublishedTerm();
                $pubId   = $pubTerm ? (int)$pubTerm['id'] : 0;

                // every headline counter in ONE round trip — same story as the admin view, each
                // subselect is scoped on its own and sections reaches the tenant through its class
                $pf = ['students' => dashScope('students', 'st'), 'teachers' => dashScope('teachers', 'te'),
                       'classes'  => dashScope('classes', 'cl'),  'sections' => dashScope('classes', 'c2'),
                       'published' => dashScope('result_publications', 'rp1'), 'pending' => dashScope('result_publications', 'rp2')];
                $pendSel = $hasAppr
                    ? "(SELECT COUNT(*) FROM result_publications rp2 WHERE rp2.term_id = ? AND rp2.approval_status = 'Pending'{$pf['pending']})"
                    : "0";
                $cSql = "SELECT
                           (SELECT COUNT(*) FROM students st WHERE st.status = 'Active'{$pf['students']}) AS students,
                           (SELECT COUNT(*) FROM teachers te WHERE te.status = 'Active'{$pf['teachers']}) AS teachers,
                           (SELECT COUNT(*) FROM classes  cl WHERE cl.is_active = 1{$pf['classes']})      AS classes,
                           (SELECT COUNT(*) FROM sections sec
                              JOIN classes c2 ON c2.id = sec.class_id
                             WHERE sec.is_active = 1{$pf['sections']})                                    AS sections,
                           (SELECT COUNT(*) FROM result_publications rp1
                             WHERE rp1.term_id = ? AND rp1.is_published = 1{$pf['published']})            AS published,
                           $pendSel                                                                       AS pending";
                $cp = [];
                foreach (['students', 'teachers', 'classes', 'sections'] as $k) if ($pf[$k]) $cp[] = $school;
                $cp[] = $activeTermId;                                        // rp1.term_id = ?
                if ($pf['published']) $cp[] = $school;
                if ($hasAppr) { $cp[] = $activeTermId; if ($pf['pending']) $cp[] = $school; }
                $c = qOne($cSql, str_repeat('i', count($cp)), ...$cp) ?: [];

                // pass rate + average of the frozen snapshot — published rows only, never recomputed live
                $rate = $pubId ? qOne("SELECT COUNT(*) AS total, SUM(rs.result_status = 'PASS') AS passed,
                                              ROUND(AVG(rs.percentage), 2) AS avg_pct
                                       FROM result_summaries rs
                                       JOIN result_publications rp
                                         ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1
                                       WHERE rs.term_id = ?" . $scRs,
                                      $scRs ? 'ii' : 'i', ...($scRs ? [$pubId, $school] : [$pubId])) : null;
                $rateTot = $rate ? (int)$rate['total'] : 0;
                $ratePas = $rate ? (int)$rate['passed'] : 0;

                // class vs class — average % and pass % side by side, one GROUP BY
                $byClass = $pubId ? qAll("SELECT c.name AS label, COUNT(*) AS total,
                                                 SUM(rs.result_status = 'PASS') AS passed,
                                                 ROUND(AVG(rs.percentage), 2) AS avg_pct
                                          FROM result_summaries rs
                                          JOIN sections sec ON sec.id = rs.section_id
                                          JOIN classes  c   ON c.id   = sec.class_id
                                          JOIN result_publications rp
                                            ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1
                                          WHERE rs.term_id = ?" . $scCl . "
                                          GROUP BY c.id, c.name, c.sort_order
                                          ORDER BY c.sort_order ASC, c.name ASC",
                                         $scCl ? 'ii' : 'i', ...($scCl ? [$pubId, $school] : [$pubId])) : [];

                // entry progress per section — the shared aggregate, school-wide completion is the sum of its rows
                $sections = dashSectionProgress($activeTermId);
                $entered  = array_sum(array_column($sections, 'entered'));
                $expected = array_sum(array_column($sections, 'expected'));

                // what is sitting on the head's desk right now
                $queue = ($hasAppr && $activeTermId) ? qAll(
                    "SELECT c.name AS class_name, sec.name AS section_name, rp.submitted_at,
                            u.full_name, u.username,
                            (SELECT COUNT(*) FROM result_summaries rs
                              WHERE rs.section_id = rp.section_id AND rs.term_id = rp.term_id) AS students
                     FROM result_publications rp
                     JOIN sections sec ON sec.id = rp.section_id
                     JOIN classes  c   ON c.id   = sec.class_id
                     LEFT JOIN users u ON u.id = rp.submitted_by
                     WHERE rp.term_id = ? AND rp.approval_status = 'Pending'" . $scCl . "
                     ORDER BY rp.submitted_at ASC, c.sort_order ASC, sec.name ASC
                     LIMIT 10", $scCl ? 'ii' : 'i', ...($scCl ? [$activeTermId, $school] : [$activeTermId])) : [];

                // best of the whole school for the reported term
                $top = $pubId ? qAll("SELECT u.full_name, u.username, c.name AS class_name, sec.name AS section_name,
                                             rs.percentage, rs.grade, rs.`position`, rs.result_status
                                      FROM result_summaries rs
                                      JOIN students st  ON st.id  = rs.student_id
                                      JOIN users u      ON u.id   = st.user_id
                                      JOIN sections sec ON sec.id = rs.section_id
                                      JOIN classes  c   ON c.id   = sec.class_id
                                      JOIN result_publications rp
                                        ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1
                                      WHERE rs.term_id = ?" . $scCl . "
                                      ORDER BY rs.percentage DESC, rs.total_obtained DESC
                                      LIMIT 10", $scCl ? 'ii' : 'i', ...($scCl ? [$pubId, $school] : [$pubId])) : [];

                jsonOk([
                    'approval' => $hasAppr ? 1 : 0,
                    'kpi' => [
                        'students'  => (int)($c['students'] ?? 0),
                        'teachers'  => (int)($c['teachers'] ?? 0),
                        'classes'   => (int)($c['classes'] ?? 0),
                        'sections'  => (int)($c['sections'] ?? 0),
                        'published' => (int)($c['published'] ?? 0),
                        'pending'   => (int)($c['pending'] ?? 0)
                    ],
                    'completion' => [
                        'entered'  => $entered,
                        'expected' => $expected,
                        'pct'      => $expected > 0 ? round($entered / $expected * 100, 1) : 0
                    ],
                    'pass_rate' => [
                        'total'  => $rateTot,
                        'passed' => $ratePas,
                        'pct'    => $rateTot > 0 ? round($ratePas / $rateTot * 100, 1) : 0
                    ],
                    'avg_pct'  => $rate ? (float)$rate['avg_pct'] : 0,
                    'money'    => dashOwedAttendance($yearId, $activeTermId),
                    'ops'      => dashOps($yearId, $activeTermId, 0, $user_id),
                    'pub_term' => $pubTerm ? $pubTerm['name'] : '',
                    'by_class' => array_map(fn($r) => [
                        'label' => $r['label'],
                        'avg'   => (float)$r['avg_pct'],
                        'pct'   => (int)$r['total'] > 0 ? round((int)$r['passed'] / (int)$r['total'] * 100, 1) : 0,
                        'total' => (int)$r['total']
                    ], $byClass),
                    'sections' => $sections,
                    'queue'    => array_map(fn($r) => [
                        'label' => $r['class_name'] . ' – ' . $r['section_name'],
                        'by'    => $r['full_name'] ?: ($r['username'] ?: '—'),
                        'total' => (int)$r['students'],
                        'on'    => $r['submitted_at'] ? date('d M Y', strtotime($r['submitted_at'])) : '—',
                        'days'  => $r['submitted_at'] ? (int)floor((time() - strtotime($r['submitted_at'])) / 86400) : 0
                    ], $queue),
                    'top' => array_map(fn($r) => [
                        'name'     => $r['full_name'] ?: $r['username'],
                        'section'  => $r['class_name'] . ' – ' . $r['section_name'],
                        'percent'  => (float)$r['percentage'],
                        'grade'    => $r['grade'] ?: '—',
                        'position' => $r['position'] !== null ? ormsOrdinal((int)$r['position']) : '—',
                        'status'   => $r['result_status']
                    ], $top)
                ]);

            // -------------------------------------------------------- teacher
            case 'teacherStats':
                // resolved from the session — the client never names a teacher. every query below
                // is driven by teacher_subjects for THIS teacher id, so the tenant arrives with it
                $tid = ormsTeacherId($user_id);
                if ($tid && !ormsFindTeacher($tid)) $tid = null;   // profile row must be in this school
                if (!$tid || !$yearId) jsonOk(['cards' => [], 'kpi' => null, 'published' => [], 'linked' => $tid ? 1 : 0]);

                $cards = ormsTeacherAssignments($tid, $yearId);

                // counts for EVERY assignment in one aggregate, keyed section:subject.
                // an optional subject expects only its enrolled students, never the whole roster
                $hasEl = ormsHasElectives();
                $optSel = $hasEl
                    ? "(SELECT COALESCE(MAX(cs.is_optional), 0) FROM class_subjects cs
                          JOIN sections s2 ON s2.id = ts.section_id AND cs.class_id = s2.class_id
                        WHERE cs.subject_id = ts.subject_id)"
                    : "0";
                $enrSel = $hasEl
                    ? "(SELECT COUNT(*) FROM student_subjects ss
                          JOIN students st4 ON st4.id = ss.student_id AND st4.section_id = ts.section_id AND st4.status = 'Active'
                        WHERE ss.subject_id = ts.subject_id AND ss.academic_year_id = ts.academic_year_id)"
                    : "0";
                $scM = dashMarksScope();
                $agg = [];
                // sql order: marks term -> marks school -> rp term -> teacher -> year
                $ap = [$activeTermId];
                if ($scM) $ap[] = $school;
                array_push($ap, $activeTermId, $tid, $yearId);
                foreach (qAll("SELECT ts.section_id, ts.subject_id,
                                      (SELECT COUNT(*) FROM students st
                                        WHERE st.section_id = ts.section_id AND st.status = 'Active') AS students,
                                      $optSel                                                        AS is_opt,
                                      $enrSel                                                        AS enrolled,
                                      (SELECT COUNT(*) FROM marks m
                                         JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                                         JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                                       WHERE m.section_id = ts.section_id AND m.subject_id = ts.subject_id
                                         AND m.term_id = ?" . $scM . "
                                         AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . ")      AS entered,
                                      COALESCE(rp.is_published, 0)                                   AS is_published
                               FROM teacher_subjects ts
                               LEFT JOIN result_publications rp
                                 ON rp.section_id = ts.section_id AND rp.term_id = ?
                               WHERE ts.teacher_id = ? AND ts.academic_year_id = ?",
                              str_repeat('i', count($ap)), ...$ap) as $a) {
                    $agg[(int)$a['section_id'] . ':' . (int)$a['subject_id']] = $a;
                }

                $secIds = []; $subIds = []; $pending = 0;
                $out = array_map(function ($c) use ($agg, &$secIds, &$subIds, &$pending) {
                    $k        = (int)$c['section_id'] . ':' . (int)$c['subject_id'];
                    $students = (int)($agg[$k]['is_opt'] ?? 0) === 1
                              ? (int)($agg[$k]['enrolled'] ?? 0)          // elective -> its enrolment
                              : (int)($agg[$k]['students'] ?? 0);
                    $entered  = min((int)($agg[$k]['entered'] ?? 0), $students);
                    $secIds[(int)$c['section_id']] = 1;
                    $subIds[(int)$c['subject_id']] = 1;
                    $pending += max(0, $students - $entered);
                    return [
                        'section_id' => (int)$c['section_id'],
                        'subject_id' => (int)$c['subject_id'],
                        'label'      => $c['class_name'] . ' – ' . $c['section_name'],
                        'subject'    => $c['subject_name'],
                        'total'      => (float)($c['total_marks'] ?? 100),
                        'students'   => $students,
                        'entered'    => $entered,
                        'expected'   => $students,
                        'pct'        => $students > 0 ? round($entered / $students * 100) : 0,
                        'published'  => (int)($agg[$k]['is_published'] ?? 0)
                    ];
                }, $cards);

                // students reachable through teacher_subjects only — scoping in the WHERE clause
                $myStudents = (int)qVal("SELECT COUNT(DISTINCT st.id)
                                         FROM students st
                                         JOIN teacher_subjects ts ON ts.section_id = st.section_id
                                         WHERE ts.teacher_id = ? AND ts.academic_year_id = ? AND st.status = 'Active'",
                                        'ii', $tid, $yearId);

                // published sections this teacher owns — again scoped in SQL
                $published = qAll("SELECT c.name AS class_name, sec.name AS section_name, t.name AS term_name,
                                          rp.published_at,
                                          (SELECT COUNT(*) FROM result_summaries rs
                                            WHERE rs.section_id = rp.section_id AND rs.term_id = rp.term_id) AS students,
                                          (SELECT COUNT(*) FROM result_summaries rs
                                            WHERE rs.section_id = rp.section_id AND rs.term_id = rp.term_id
                                              AND rs.result_status = 'PASS')                                 AS passed
                                   FROM result_publications rp
                                   JOIN sections   sec ON sec.id = rp.section_id
                                   JOIN classes    c   ON c.id   = sec.class_id
                                   JOIN exam_terms t   ON t.id   = rp.term_id
                                   WHERE rp.is_published = 1
                                     AND rp.section_id IN (SELECT ts.section_id FROM teacher_subjects ts
                                                           WHERE ts.teacher_id = ? AND ts.academic_year_id = ?)
                                   ORDER BY rp.published_at DESC, c.name ASC, sec.name ASC
                                   LIMIT 10", 'ii', $tid, $yearId);

                // today's periods, the registers still unmarked today, and the next exams of their classes.
                // day_of_week follows ISO (1 = Monday), the same axis the timetable grid saves
                $dow = (int) date('N'); $today = date('Y-m-d');
                $todayRows = []; $attDue = []; $exams = []; $unread = 0;
                if (dashHasCol('timetable_slots', 'period_no')) {
                    try {
                        $todayRows = array_map(fn($r) => ['p' => (int)$r['period_no'], 'from' => substr((string)$r['start_time'], 0, 5),
                                                          'to' => substr((string)$r['end_time'], 0, 5), 'subject' => $r['subject'],
                                                          'label' => $r['class_name'] . ' – ' . $r['section_name']],
                            qAll("SELECT ts.period_no, ts.start_time, ts.end_time, su.name AS subject, c.name AS class_name, sec.name AS section_name
                                  FROM timetable_slots ts
                                  JOIN sections sec ON sec.id = ts.section_id
                                  JOIN classes  c   ON c.id   = sec.class_id
                                  JOIN subjects su  ON su.id  = ts.subject_id
                                  WHERE ts.teacher_id = ? AND ts.day_of_week = ?
                                  ORDER BY ts.period_no ASC", 'ii', $tid, $dow));
                    } catch (Throwable $e) {}
                }
                if (dashHasCol('attendance_daily', 'att_date') && can('attendance', 'a')) {
                    try {
                        $attDue = array_map(fn($r) => ['section_id' => (int)$r['id'], 'label' => $r['class_name'] . ' – ' . $r['section_name']],
                            qAll("SELECT DISTINCT sec.id, c.name AS class_name, sec.name AS section_name, c.sort_order
                                  FROM teacher_subjects ts
                                  JOIN sections sec ON sec.id = ts.section_id
                                  JOIN classes  c   ON c.id   = sec.class_id
                                  WHERE ts.teacher_id = ? AND ts.academic_year_id = ? AND sec.is_active = 1
                                    AND NOT EXISTS (SELECT 1 FROM attendance_daily ad WHERE ad.section_id = sec.id AND ad.att_date = ?)
                                  ORDER BY c.sort_order ASC, sec.name ASC", 'iis', $tid, $yearId, $today));
                    } catch (Throwable $e) {}
                }
                if (dashHasCol('exam_schedule', 'exam_date')) {
                    try {
                        $exams = array_map(fn($r) => ['on' => date('d M', strtotime($r['exam_date'])), 'date' => $r['exam_date'],
                                                      'time' => $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : '',
                                                      'subject' => $r['subject'], 'class' => $r['class_name'], 'room' => (string)$r['room'],
                                                      'days' => (int) floor((strtotime($r['exam_date']) - strtotime($today)) / 86400)],
                            qAll("SELECT es.exam_date, es.start_time, es.room, su.name AS subject, c.name AS class_name
                                  FROM exam_schedule es
                                  JOIN classes  c  ON c.id  = es.class_id
                                  JOIN subjects su ON su.id = es.subject_id
                                  WHERE es.exam_date >= ?
                                    AND es.class_id IN (SELECT s2.class_id FROM teacher_subjects ts2 JOIN sections s2 ON s2.id = ts2.section_id
                                                        WHERE ts2.teacher_id = ? AND ts2.academic_year_id = ?)
                                  ORDER BY es.exam_date ASC, es.start_time ASC LIMIT 6", 'sii', $today, $tid, $yearId));
                    } catch (Throwable $e) {}
                }
                try { $unread = (int) qVal("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", 'i', $user_id); } catch (Throwable $e) {}

                jsonOk([
                    'linked' => 1,
                    'today'  => $todayRows,
                    'att_due' => $attDue,
                    'exams'  => $exams,
                    'unread' => $unread,
                    'cards'  => $out,
                    'kpi'    => [
                        'sections' => count($secIds),
                        'subjects' => count($subIds),
                        'students' => $myStudents,
                        'pending'  => $pending
                    ],
                    'published' => array_map(fn($p) => [
                        'label'  => $p['class_name'] . ' – ' . $p['section_name'],
                        'term'   => $p['term_name'],
                        'on'     => $p['published_at'] ? date('d M Y', strtotime($p['published_at'])) : '—',
                        'passed' => (int)$p['passed'],
                        'total'  => (int)$p['students']
                    ], $published)
                ]);

            // -------------------------------------------------------- student
            case 'studentStats':
                // session-resolved; a tampered id can never reach here. still resolved against the
                // school, so a profile row that moved tenants stops feeding this student's card
                $sid = ormsStudentId($user_id);
                if ($sid && !ormsFindStudent($sid)) $sid = null;
                if (!$sid) jsonOk(['rows' => [], 'linked' => 0]);

                // published rows ONLY — this one read feeds the KPI row and the trend chart.
                // this copy of the entered predicate has never carried ormsElectiveSql() — it counts
                // how many subjects the card shows, not completion, so leave that difference alone
                $scM  = dashMarksScope();
                $scRs = dashScope('result_summaries', 'rs');
                $rp   = [];
                if ($scM) $rp[] = $school;
                $rp[] = $sid;
                if ($scRs) $rp[] = $school;
                $rows = qAll("SELECT rs.term_id, rs.percentage, rs.grade, rs.gpa, rs.`position`, rs.result_status,
                                     rs.total_obtained, rs.total_max, t.name AS term_name, y.name AS year_name,
                                     rp.published_at,
                                     (SELECT COUNT(*) FROM result_summaries r2
                                       WHERE r2.section_id = rs.section_id AND r2.term_id = rs.term_id) AS class_size,
                                     (SELECT COUNT(*) FROM marks m
                                       WHERE m.student_id = rs.student_id AND m.term_id = rs.term_id" . $scM . "
                                         AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1))         AS subjects
                              FROM result_summaries rs
                              JOIN result_publications rp
                                ON rp.section_id = rs.section_id AND rp.term_id = rs.term_id AND rp.is_published = 1
                              JOIN exam_terms t     ON t.id = rs.term_id
                              JOIN academic_years y ON y.id = rs.academic_year_id
                              WHERE rs.student_id = ?" . $scRs . "
                              ORDER BY y.start_date ASC, t.sort_order ASC, t.id ASC",
                             str_repeat('i', count($rp)), ...$rp);

                $data = array_map(fn($r) => [
                    'term'       => $r['term_name'],
                    'year'       => $r['year_name'],
                    'percent'    => (float)$r['percentage'],
                    'grade'      => $r['grade'] ?: '—',
                    'gpa'        => (float)$r['gpa'],
                    'status'     => $r['result_status'],
                    'subjects'   => (int)$r['subjects'],
                    'obtained'   => (float)$r['total_obtained'],
                    'max'        => (float)$r['total_max'],
                    'position'   => $r['position'] !== null
                                    ? ormsOrdinal((int)$r['position']) . ' of ' . (int)$r['class_size'] : '—',
                    'on'         => $r['published_at'] ? date('d M Y', strtotime($r['published_at'])) : '—',
                    'ts'         => $r['published_at'] ? strtotime($r['published_at']) : 0
                ], $rows);

                // newest publish wins the KPI row; the chart keeps chronological order
                $latest = null;
                foreach ($data as $d) if ($latest === null || $d['ts'] >= $latest['ts']) $latest = $d;

                // the rest of the student's day: this year's register, the fee position, today's periods,
                // the next exam, unread alerts. all best-effort — an uninstalled table drops its tile
                $stu = qOne("SELECT s.section_id, s.class_id, s.fee_hold FROM students s WHERE s.id = ?", 'i', $sid) ?: [];
                $secId = (int)($stu['section_id'] ?? 0); $clsId = (int)($stu['class_id'] ?? 0);
                $att = null; $fee = null; $todayRows = []; $nextExam = null; $unread = 0;
                $today = date('Y-m-d'); $dow = (int) date('N');
                if ($yearId && dashHasCol('attendance_daily', 'att_date')) {
                    try {
                        $r = qOne("SELECT COUNT(*) AS n, COALESCE(SUM(status = 'P'), 0) AS p, COALESCE(SUM(status = 'A'), 0) AS a,
                                          COALESCE(SUM(status = 'L'), 0) AS l, COALESCE(SUM(status = 'LV'), 0) AS lv
                                   FROM attendance_daily WHERE student_id = ? AND academic_year_id = ?", 'ii', $sid, $yearId);
                        $n = (int)($r['n'] ?? 0);
                        $att = ['n' => $n, 'p' => (int)$r['p'], 'a' => (int)$r['a'], 'l' => (int)$r['l'], 'lv' => (int)$r['lv'],
                                'pct' => $n > 0 ? round((int)$r['p'] / $n * 100, 1) : 0];
                    } catch (Throwable $e) {}
                }
                if ($yearId && ormsHasFees()) {
                    try {
                        $bal = ormsFeeBalance($sid, $yearId);
                        $fee = ['balance' => $bal, 'balance_f' => ormsMoney(abs($bal)), 'owes' => $bal > 0 ? 1 : 0, 'hold' => (int)($stu['fee_hold'] ?? 0)];
                    } catch (Throwable $e) {}
                }
                if ($secId && dashHasCol('timetable_slots', 'period_no')) {
                    try {
                        $todayRows = array_map(fn($r) => ['p' => (int)$r['period_no'], 'from' => substr((string)$r['start_time'], 0, 5),
                                                          'to' => substr((string)$r['end_time'], 0, 5), 'subject' => $r['subject'], 'teacher' => (string)$r['teacher']],
                            qAll("SELECT ts.period_no, ts.start_time, ts.end_time, su.name AS subject, u.full_name AS teacher
                                  FROM timetable_slots ts
                                  JOIN subjects su ON su.id = ts.subject_id
                                  LEFT JOIN teachers t ON t.id = ts.teacher_id
                                  LEFT JOIN users u    ON u.id = t.user_id
                                  WHERE ts.section_id = ? AND ts.day_of_week = ?
                                  ORDER BY ts.period_no ASC", 'ii', $secId, $dow));
                    } catch (Throwable $e) {}
                }
                if ($clsId && dashHasCol('exam_schedule', 'exam_date')) {
                    try {
                        $r = qOne("SELECT es.exam_date, es.start_time, es.room, su.name AS subject, tm.name AS term
                                   FROM exam_schedule es JOIN subjects su ON su.id = es.subject_id JOIN exam_terms tm ON tm.id = es.term_id
                                   WHERE es.class_id = ? AND es.exam_date >= ? ORDER BY es.exam_date ASC, es.start_time ASC LIMIT 1", 'is', $clsId, $today);
                        if ($r) $nextExam = ['subject' => $r['subject'], 'term' => $r['term'], 'room' => (string)$r['room'], 'date' => $r['exam_date'],
                                             'on' => date('d M', strtotime($r['exam_date'])), 'time' => $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : '',
                                             'days' => (int) floor((strtotime($r['exam_date']) - strtotime($today)) / 86400)];
                    } catch (Throwable $e) {}
                }
                try { $unread = (int) qVal("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", 'i', $user_id); } catch (Throwable $e) {}

                jsonOk(['linked' => 1, 'rows' => $data, 'latest' => $latest,
                        'att' => $att, 'fee' => $fee, 'today' => $todayRows, 'next_exam' => $nextExam, 'unread' => $unread]);

            // ------------------------------------------------------- activity
            // ------------------------------------------------------------ the whole app, one call
            // The App Owner used to land on the 'basic' fallback: every query on this page is
            // school-scoped and the operator holds no school, so the dashboard was empty for the one
            // person who needs to see everything. This is the platform view — tenants, money,
            // subscription health, the SaaS switches and the box it runs on, in ONE round trip.
            // Every block is best-effort: a table that isn't installed yet returns zeros, never a 500.
            case 'platformStats': {
                if (!$isPlat) jsonErr('The platform overview belongs to the App Owner.');
                $pv = function (string $sql, string $types = '', ...$p) {
                    try { return qVal($sql, $types, ...$p); } catch (Throwable $e) { return null; }
                };
                $today = date('Y-m-d');
                $out   = ['today' => $today];

                // ---- tenants
                $out['totals'] = [
                    'schools'  => (int) ($pv("SELECT COUNT(*) FROM schools") ?? 0),
                    'branches' => (int) ($pv("SELECT COUNT(*) FROM branches") ?? 0),
                    'students' => (int) ($pv("SELECT COUNT(*) FROM students WHERE status NOT IN ('Passed Out','Transferred')") ?? 0),
                    'teachers' => (int) ($pv("SELECT COUNT(*) FROM teachers WHERE status = 'Active'") ?? 0),
                    'users'    => (int) ($pv("SELECT COUNT(*) FROM users WHERE school_id > 0") ?? 0),
                    'new30'    => (int) ($pv("SELECT COUNT(*) FROM schools WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)") ?? 0),
                ];
                $byStatus = [];
                try { foreach (qAll("SELECT status, COUNT(*) n FROM schools GROUP BY status") as $r) $byStatus[$r['status']] = (int) $r['n']; }
                catch (Throwable $e) {}
                $out['by_status'] = $byStatus;

                // ---- every tenant with its newest period, in ONE pass. A query per school would be
                //      N+1 on the page the operator opens most.
                $rows = [];
                try {
                    $rows = qAll(
                        "SELECT s.id, s.name, s.code, s.status, s.trial_ends_at, s.created_at,
                                p.name AS plan_name, x.ends_at,
                                (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status NOT IN ('Passed Out','Transferred')) AS students,
                                (SELECT COUNT(*) FROM teachers t  WHERE t.school_id  = s.id AND t.status = 'Active') AS teachers,
                                (SELECT COUNT(*) FROM branches b  WHERE b.school_id  = s.id) AS branches,
                                (SELECT COUNT(*) FROM users u     WHERE u.school_id  = s.id) AS users
                         FROM schools s
                         LEFT JOIN plans p ON p.id = s.plan_id
                         LEFT JOIN (SELECT school_id, MAX(ends_at) AS ends_at FROM school_subscriptions GROUP BY school_id) x
                                ON x.school_id = s.id
                         ORDER BY s.id ASC");
                } catch (Throwable $e) { $rows = []; }

                $watch = ['expired' => 0, 'soon' => 0, 'ok' => 0, 'never' => 0];
                $need  = [];
                foreach ($rows as $r) {
                    // the date that actually governs this school: its last paid period, else the trial
                    $ends = $r['ends_at'] ?: ($r['trial_ends_at'] ?: null);
                    $days = $ends ? (int) floor((strtotime($ends) - strtotime($today)) / 86400) : null;
                    $dead = in_array($r['status'], ['Suspended', 'Cancelled'], true);
                    $bucket = $dead ? 'expired' : ($ends === null ? 'never' : ($days < 0 ? 'expired' : ($days <= 30 ? 'soon' : 'ok')));
                    $watch[$bucket]++;
                    if ($bucket !== 'ok') {
                        $need[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code'],
                                   'status' => $r['status'], 'plan_name' => $r['plan_name'],
                                   'ends_at' => $ends, 'days' => $days, 'bucket' => $bucket,
                                   'students' => (int) $r['students'], 'teachers' => (int) $r['teachers'],
                                   'branches' => (int) $r['branches'], 'users' => (int) $r['users']];
                    }
                }
                // worst first: expired, then whichever runs out soonest
                usort($need, static function ($a, $b) {
                    $rank = ['expired' => 0, 'never' => 1, 'soon' => 2];
                    return [$rank[$a['bucket']], $a['days'] ?? 9999] <=> [$rank[$b['bucket']], $b['days'] ?? 9999];
                });
                $out['watch'] = $watch;
                $out['need']  = array_slice($need, 0, 12);

                // ---- money. Guarded: an install that never took the billing migration shows nothing.
                $ccy  = ormsCurrency()['code'];
                $pend = null;
                try { $pend = qOne("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) amt FROM billing_invoices WHERE status = 'Pending'"); }
                catch (Throwable $e) { $pend = null; }
                $out['money'] = [
                    'currency' => $ccy,
                    'month'    => (float) ($pv("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments WHERE paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')") ?? 0),
                    'lifetime' => (float) ($pv("SELECT COALESCE(SUM(amount), 0) FROM subscription_payments") ?? 0),
                    'pend_n'   => (int) ($pend['n'] ?? 0),
                    'pend_amt' => (float) ($pend['amt'] ?? 0),
                    'billing'  => $pend !== null,
                ];

                // ---- the switches. This is the "is the platform actually live" panel: each row is
                //      [ok, label, why it matters, where to fix it].
                $g  = static fn(string $k, string $d = '0') => (string) ormsPlatformSetting($k, $d);
                $sk = trim($g('gw_stripe_sk', '')) !== '' && trim($g('gw_stripe_webhook_secret', '')) !== '';
                $xp = ormsAutoExpireCfg();
                // [ok, label, why it matters, where to fix it, page key]. The page key is carried so
                // the button can be dropped when the viewer cannot actually open that page — a "fix
                // it here" link that bounces straight back to this dashboard is worse than no link.
                $flags = [
                    [ormsPlatformMode(), 'SaaS mode is on', 'plan limits and the subscription gate are inert while it is off', 'settings.php', 'settings'],
                    [$g('billing_enabled') === '1', 'Self-serve checkout is on', 'schools can pay for their own renewal', 'gateways.php', 'gateways'],
                    [$g('billing_test_mode') !== '1', 'Live mode (not test)', 'test mode keeps the wording but takes no real money', 'gateways.php', 'gateways'],
                    [$g('gw_manual_enabled', '1') === '1' || $sk, 'At least one payment method is ready', 'bank transfer, or Stripe with both keys', 'gateways.php', 'gateways'],
                    [$xp['on'], 'Automatic expiry is on', 'without it a lapsed period still reads Active everywhere', 'gateways.php', 'gateways'],
                    [$g('smtp_enabled') === '1', 'SMTP email is enabled', 'credentials, invoices and reminders all need it', 'smtp_setup.php', 'smtp_setup'],
                    [$g('maintenance_mode') !== '1', 'Maintenance mode is off', 'everyone except an Admin is locked out while it is on', 'settings.php', 'settings'],
                    [trim($g('vapid_public_key', '')) !== '', 'Web push keys are set', 'browser notifications stay silent without them', 'settings.php', 'settings'],
                ];
                foreach ($flags as &$fl) { $fl[] = can($fl[4], 'v') ? 1 : 0; }   // may THIS viewer open it
                unset($fl);
                $out['flags'] = $flags;

                // ---- the box it runs on
                $errKb = @is_file(__DIR__ . '/error_log.txt') ? (int) round(@filesize(__DIR__ . '/error_log.txt') / 1024) : 0;
                $upMb  = 0;
                try {   // bounded walk: a runaway uploads folder must not hang the dashboard
                    $n = 0;
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/uploads', FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $f) { if ($f->isFile()) { $upMb += $f->getSize(); } if (++$n > 5000) break; }
                    $upMb = round($upMb / 1048576, 1);
                } catch (Throwable $e) { $upMb = null; }
                $out['system'] = [
                    'php'      => PHP_VERSION,
                    'db'       => (string) ($pv("SELECT DATABASE()") ?? ''),
                    'tables'   => (int) ($pv("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()") ?? 0),
                    'db_mb'    => (float) ($pv("SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()") ?? 0),
                    'uploads_mb' => $upMb,
                    'error_kb' => $errKb,
                    'sweep'    => $xp['last'] !== '' ? $xp['last'] : null,
                    'backup'   => $pv("SELECT MAX(timestamp) FROM activity_logs WHERE action = 'Backup Downloaded'"),
                    'logins24' => (int) ($pv("SELECT COUNT(*) FROM activity_logs WHERE action = 'Login' AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") ?? 0),
                    'fails24'  => (int) ($pv("SELECT COUNT(*) FROM login_attempts WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)") ?? 0),
                ];

                // ---- six months of collections + the plan mix, for the two platform charts
                $months = [];
                for ($i = 5; $i >= 0; $i--) $months[] = date('Y-m', strtotime("first day of -$i month"));
                $rev = array_fill_keys($months, 0.0);
                try {
                    foreach (qAll("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS m, COALESCE(SUM(amount), 0) AS amt
                                   FROM subscription_payments WHERE paid_on >= ? GROUP BY DATE_FORMAT(paid_on, '%Y-%m')", 's', $months[0] . '-01') as $r)
                        if (isset($rev[$r['m']])) $rev[$r['m']] = (float)$r['amt'];
                } catch (Throwable $e) {}
                $out['rev6'] = ['labels' => array_map(fn($m) => date('M y', strtotime($m . '-01')), $months), 'data' => array_values($rev)];
                $byPlan = [];
                try { foreach (qAll("SELECT COALESCE(p.name, 'No plan') AS plan, COUNT(*) AS n FROM schools s LEFT JOIN plans p ON p.id = s.plan_id GROUP BY p.id, p.name ORDER BY n DESC") as $r) $byPlan[] = ['label' => $r['plan'], 'n' => (int)$r['n']]; }
                catch (Throwable $e) {}
                $out['by_plan'] = $byPlan;

                jsonOk($out);
            }

            case 'recentActivity':
                // school-wide roles read this SCHOOL's trail, everyone else only their own.
                // read here rather than through getActivityLogs() — that helper has no tenant leg,
                // so a head teacher would get the platform's audit trail on the dashboard
                $scL = dashScope('activity_logs', 'al');
                $lp  = [];
                if (!$isWide) $lp[] = $user_id;
                if ($scL && !$isPlat) $lp[] = $school;
                $logs = qAll("SELECT al.* FROM activity_logs al WHERE 1 = 1"
                             . ($isWide ? '' : " AND al.user_id = ?")
                             . ($scL && !$isPlat ? $scL : '')
                             . " ORDER BY al.timestamp DESC LIMIT 8",
                             str_repeat('i', count($lp)), ...$lp);
                jsonOk(['data' => $logs]);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('Dashboard error: ' . $e->getMessage());
        jsonErr('Could not load dashboard data');
    }
}

// which dashboard to render — role first, linked profile as the safety net.
// school-wide roles skip the profile lookups, so a head who also owns a teacher row keeps the wide view.
// resolved after the ajax router so a data call never pays for these lookups
$teacherId = $isWide ? null : ormsTeacherId($user_id);
$studentId = ($isWide || $teacherId) ? null : ormsStudentId($user_id);

// the operator first: every other view is school-scoped, and the App Owner holds no school, so it
// used to fall through to 'basic' — an empty page for the one person who needs to see everything
if      ($isPlat)               $view = 'platform';
elseif  ($isOwner)              $view = 'owner';      // the tenant's top role used to fall through to 'basic'
elseif  ($isAdmin)              $view = 'admin';
elseif  ($role === 'Principal') $view = 'principal';
elseif  ($isBranch && bid())    $view = 'branch';     // pinned campus — same aggregate, one fence
elseif  ($teacherId)           $view = 'teacher';
elseif  ($studentId)           $view = 'student';
elseif  ($role === 'Teacher')  $view = 'teacher';   // profile not linked yet -> friendly empty state
elseif  ($role === 'Student')  $view = 'student';
else                           $view = 'basic';

// which aggregate the page pulls — the three wide views share one markup body and one loader
$statsAction = ['owner' => 'ownerStats', 'branch' => 'branchStats'][$view] ?? 'adminStats';

// ---- AdminLTE builders. small-box = the KPI row, info-box = the secondary strip, lte-card = every panel.
// the footer is a real link only when THIS role can open the page — a "More info" that bounces back here is worse than none
function sbox(string $id, string $label, string $icon, string $color, string $key = '', string $href = '', string $cap = ''): string {
    $foot = $key !== '' && can($key, 'v') ? '<a class="small-box-footer" href="' . $href . '">More info <i class="fas fa-arrow-circle-right"></i></a>' : '';
    return '<div class="small-box ' . $color . '"><div class="inner"><h3 id="' . $id . '">&mdash;</h3><p>' . $label . '</p>'
         . '<small class="sb-cap" id="' . $id . 'Cap">' . $cap . '</small></div>'
         . '<div class="icon"><i class="fas ' . $icon . '"></i></div>' . $foot . '</div>';
}
function ibox(string $id, string $label, string $icon, string $color = 'bg-navy', bool $bar = false, string $href = ''): string {
    $tag = $href !== '' ? 'a' : 'div';
    return '<' . $tag . ' class="info-box"' . ($href !== '' ? ' href="' . $href . '"' : '') . ' id="' . $id . 'Box">'
         . '<span class="info-box-icon ' . $color . '"><i class="fas ' . $icon . '"></i></span>'
         . '<div class="info-box-content"><span class="info-box-text">' . $label . '</span>'
         . '<span class="info-box-number" id="' . $id . '">&mdash;</span>'
         . ($bar ? '<div class="info-box-progress"><span id="' . $id . 'Bar"></span></div>' : '')
         . '<span class="info-box-desc" id="' . $id . 'Desc"></span></div></' . $tag . '>';
}
function cardOpen(string $icon, string $title, string $subId = '', string $tools = ''): string {
    return '<div class="lte-card"><div class="lte-card-header"><h3 class="lte-card-title"><i class="fas ' . $icon . '"></i> ' . $title
         . ($subId !== '' ? ' <span class="lte-card-sub" id="' . $subId . '"></span>' : '') . '</h3>'
         . '<div class="lte-card-tools">' . $tools . '<button type="button" onclick="toggleLteCard(this)" title="Collapse"><i class="fas fa-minus"></i></button></div></div>'
         . '<div class="lte-card-body">';
}
function cardClose(): string { return '</div></div>'; }
function moreLink(string $key, string $href, string $label = 'View all'): string {
    return can($key, 'v') ? '<a class="lte-more" href="' . $href . '">' . $label . '</a>' : '';
}
function skelKpis(int $n, string $id = 'kpiSkeleton'): string {
    $h = '<div class="lte-kpi-grid" id="' . $id . '">';
    for ($i = 0; $i < $n; $i++) $h .= '<div class="small-box bg-navy-4"><div class="inner"><div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div><div class="skeleton skeleton-text skeleton-w-70"></div></div></div>';
    return $h . '</div>';
}
function skelTable(int $rows, int $cols, string $id): string {
    $h = '<div id="' . $id . '"><div class="skeleton-table">';
    for ($r = 0; $r < $rows; $r++) { $h .= '<div class="skeleton-table-row">'; for ($c = 0; $c < $cols; $c++) $h .= '<div class="skeleton skeleton-table-cell skeleton-flex-' . ($c === 0 ? 2 : 1) . '"></div>'; $h .= '</div>'; }
    return $h . '</div></div>';
}
function skelChart(string $id): string { return '<div class="skeleton skeleton-chart" id="' . $id . '"></div>'; }
function skelRows(int $n, string $id): string {
    $h = '<div id="' . $id . '">';
    for ($i = 0; $i < $n; $i++) $h .= '<div class="da-row"><div class="skeleton skeleton-avatar"></div><div class="skeleton skeleton-text skeleton-w-70"></div></div>';
    return $h . '</div>';
}
// quick-action tile, only when the role can actually open the page
function qtile(string $key, string $href, string $icon, string $hue, string $label, string $perm = 'v'): string {
    return can($key, $perm) ? '<a class="dq-tile" href="' . $href . '"><span class="dq-ic ' . $hue . '"><i class="fas ' . $icon . '"></i></span>' . $label . '</a>' : '';
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
    <title>Dashboard - Online Result Management</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body class="dash-page">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-chart-line"></i> Dashboard</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Overview</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

<?php
// ---- setup health: the first-day checklist nobody should have to memorise (wide views only)
$health = []; $hBad = 0;
if (in_array($view, ['admin', 'owner', 'branch'], true)) {
    $hCount = function (string $sql, string $types = '', ...$p): int {
        try { return (int) qVal($sql, $types, ...$p); } catch (Throwable $e) { return 0; }
    };
    $hTen = dashHasCol('classes', 'school_id');
    $hW = $hTen ? " AND school_id = ?" : "";
    $hA = $hTen ? [sid()] : [];
    $hT = $hTen ? 'i' : '';
    try {
        $hCY = ormsCurrentYear();
        $hOpen = false;
        if ($hCY) foreach (ormsTerms((int)$hCY['id']) as $ht) if ($ht['status'] === 'Open') { $hOpen = true; break; }
        $health = [
            [getSetting('smtp_enabled', '0') === '1', 'SMTP email is enabled', 'credential, reminder and password emails need it', 'smtp_setup.php', 'smtp_setup'],
            [(bool)$hCY, 'A current academic year is set', 'everything hangs off the year', 'result_settings.php', 'result_settings'],
            [$hOpen, 'An exam term is Open', 'marks entry stays locked until a term opens', 'result_settings.php', 'result_settings'],
            [$hCount("SELECT COUNT(*) FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.is_active = 1$hW", $hT, ...$hA) > 0,
             'Classes & sections exist', 'students need somewhere to sit', 'classes.php', 'classes'],
            [$hCount("SELECT COUNT(*) FROM class_subjects cs JOIN classes c ON c.id = cs.class_id WHERE 1 = 1$hW", $hT, ...$hA) > 0,
             'Subjects are mapped to classes', 'the marks grid is built from this mapping', 'subjects.php', 'subjects'],
            [$hCount("SELECT COUNT(*) FROM students WHERE status = 'Active'$hW", $hT, ...$hA) > 0,
             'Active students are enrolled', 'register or import the roster', 'students.php', 'students'],
            [$hCY && $hCount("SELECT COUNT(*) FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id WHERE ts.academic_year_id = ?" . ($hTen ? " AND t.school_id = ?" : ""), 'i' . $hT, (int)$hCY['id'], ...$hA) > 0,
             'Teachers are assigned subjects', 'assignments scope marks entry and attendance', 'teachers.php', 'teachers'],
            [$hCount("SELECT COUNT(*) FROM fee_structures fs JOIN classes c ON c.id = fs.class_id WHERE fs.is_active = 1$hW", $hT, ...$hA) > 0,
             'Fee structures are defined', 'the monthly charge run needs them', 'fees.php', 'fees'],
            [$hCount("SELECT COUNT(*) FROM timetable_slots ts JOIN sections s ON s.id = ts.section_id JOIN classes c ON c.id = s.class_id WHERE 1 = 1$hW", $hT, ...$hA) > 0,
             'A class timetable is filled', 'periods per section, with teacher clash checks', 'timetable.php', 'timetable'],
        ];
    } catch (Throwable $e) { $health = []; }
    $hBad = count(array_filter($health, static fn($h) => !$h[0]));
}
$termSub = $activeTerm ? htmlspecialchars($activeTerm['name']) : '';

// the school body every wide view shares — admin, owner (below its money row) and branch (fenced)
$schoolBody = function () use ($health, $hBad, $termSub, $year, $view) {
    if ($health && $hBad > 0): ?>
            <?php echo cardOpen('fa-heart-pulse', 'Setup Health', 'healthSub'); ?>
                <div class="health-list">
                    <?php foreach ($health as $h): ?>
                    <div class="health-row <?php echo $h[0] ? 'health-ok' : 'health-bad'; ?>">
                        <i class="fas <?php echo $h[0] ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                        <span class="health-label"><?php echo htmlspecialchars($h[1]); ?></span>
                        <span class="health-why"><?php echo htmlspecialchars($h[2]); ?></span>
                        <?php if (!$h[0] && can($h[4], 'v')): ?><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($h[3]); ?>"><i class="fas fa-wrench"></i> Fix</a><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php echo cardClose(); ?>
            <script>document.getElementById('healthSub').textContent = '<?php echo (count($health) - $hBad) . ' of ' . count($health); ?> ready';</script>
    <?php endif; ?>

            <?php echo skelKpis(4); ?>
            <div class="lte-kpi-grid initially-hidden" id="kpiGrid">
                <?php echo sbox('kpiStudents',  'Active Students',    'fa-user-graduate',  'bg-navy',    'students', 'students.php');
                      echo sbox('kpiTeachers',  'Active Teachers',    'fa-chalkboard-user','bg-info',    'teachers', 'teachers.php');
                      echo sbox('kpiClasses',   'Classes',            'fa-school',         'bg-success', 'classes',  'classes.php');
                      echo sbox('kpiPublished', 'Published Sections', 'fa-bullhorn',       'bg-warning', 'results',  'results.php', $termSub); ?>
            </div>

            <div class="ib-strip">
                <?php echo ibox('ibMarks',    'Marks Entry ' . ($termSub ? '· ' . $termSub : ''), 'fa-pen-to-square',  'bg-navy',    true,  can('marks_entry', 'v') ? 'marks_entry.php' : '');
                      echo ibox('ibPass',     'Pass Rate',               'fa-award',           'bg-success', true,  can('results', 'v') ? 'results.php' : '');
                      echo ibox('ibAtt',      'Attendance Today',        'fa-user-check',      'bg-info',    true,  can('attendance', 'v') ? 'attendance.php' : '');
                      echo ibox('ibFees',     'Fees Collected',          'fa-money-bill-wave', 'bg-success', false, can('fees', 'v') ? 'fees.php' : '');
                      echo ibox('ibOwed',     'Outstanding Fees',        'fa-triangle-exclamation', 'bg-warning', false, can('fees', 'v') ? 'fees.php' : '');
                      echo ibox('ibExam',     'Next Exam',               'fa-calendar-check',  'bg-info',    false, can('timetable', 'v') ? 'timetable.php' : '');
                      echo ibox('ibTT',       'Timetable Filled',        'fa-calendar-days',   'bg-navy',    true,  can('timetable', 'v') ? 'timetable.php' : '');
                      echo ibox('ibSubjects', 'Subjects',                'fa-book',            'bg-navy',    false, can('subjects', 'v') ? 'subjects.php' : ''); ?>
            </div>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-chart-column', 'Pass % per Class', '', '<select id="passTermSel" class="lte-term" onchange="pickTerm(this.value)"></select>'); ?>
                    <?php echo skelChart('passSkeleton'); ?>
                    <div class="chart-container initially-hidden" id="passWrap"><canvas id="chartPass"></canvas></div>
                    <div class="initially-hidden" id="passEmpty"></div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-chart-pie', 'Grade Distribution', '', '<select id="gradeTermSel" class="lte-term" onchange="pickTerm(this.value)"></select>'); ?>
                    <?php echo skelChart('gradeSkeleton'); ?>
                    <div class="dc-donut initially-hidden" id="gradeWrap">
                        <div class="dc-donut-c"><canvas id="chartGrades"></canvas></div>
                        <div class="dc-legend" id="gradeLegend"></div>
                    </div>
                    <div class="initially-hidden" id="gradeEmpty"></div>
                <?php echo cardClose(); ?>
            </div>

            <?php echo cardOpen('fa-list-check', 'Marks Entry by Section', 'secTerm', moreLink('marks_entry', 'marks_entry.php', 'Open marks entry')); ?>
                <?php echo skelChart('secSkeleton'); ?>
                <div class="initially-hidden" id="secWrap"></div>
                <div class="initially-hidden" id="secEmpty"></div>
            <?php echo cardClose(); ?>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-bolt', 'Quick Actions'); ?>
                    <div class="dq-grid">
                        <?php echo qtile('students', 'students.php', 'fa-user-plus', 'dqi-1', 'Add Student', 'a')
                                 . qtile('teachers', 'teachers.php', 'fa-user-tie', 'dqi-2', 'Add Teacher', 'a')
                                 . qtile('marks_entry', 'marks_entry.php', 'fa-pen-to-square', 'dqi-3', 'Marks Entry')
                                 . qtile('attendance', 'attendance.php', 'fa-user-check', 'dqi-5', 'Daily Register')
                                 . qtile('results', 'results.php', 'fa-award', 'dqi-4', 'Results')
                                 . qtile('broadsheet', 'broadsheet.php', 'fa-table-cells', 'dqi-5', 'Broadsheet')
                                 . qtile('fees', 'fees.php', 'fa-money-bill', 'dqi-6', 'Fees')
                                 . qtile('timetable', 'timetable.php', 'fa-calendar-days', 'dqi-1', 'Timetable')
                                 . ($view === 'owner' ? qtile('billing', 'billing.php', 'fa-receipt', 'dqi-4', 'Subscription') : '')
                                 . qtile('users', 'users.php', 'fa-users', 'dqi-2', 'Users'); ?>
                    </div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-clock-rotate-left', 'Recent Activities', '', moreLink('logs', 'logs.php')); ?>
                    <?php echo skelRows(4, 'actSkeleton'); ?>
                    <div class="da-list initially-hidden" id="actList"></div>
                    <div class="initially-hidden" id="actEmpty"></div>
                <?php echo cardClose(); ?>
            </div>
<?php };
?>

<?php if ($view === 'admin'): ?>
            <!-- ===================== ADMIN ===================== -->
            <?php $schoolBody(); ?>
<?php endif; ?>

<?php if ($view === 'owner'): ?>
            <!-- ===================== SCHOOL OWNER — the school + the subscription it runs on ===================== -->
            <?php echo skelKpis(4, 'subSkeleton'); ?>
            <div class="lte-kpi-grid initially-hidden" id="subGrid">
                <?php echo sbox('subPlan',  'Current Plan',      'fa-layer-group',         'bg-navy',    'billing', 'billing.php');
                      echo sbox('subDays',  'Subscription',      'fa-calendar-check',      'bg-success', 'billing', 'billing.php');
                      echo sbox('subSeats', 'Student Seats',     'fa-user-graduate',       'bg-info',    'billing', 'billing.php');
                      echo sbox('subInv',   'Pending Invoices',  'fa-file-invoice-dollar', 'bg-warning', 'billing', 'billing.php'); ?>
            </div>

            <?php $schoolBody(); ?>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-code-branch', 'Campuses', 'brSub', moreLink('branches', 'branches.php', 'Manage')); ?>
                    <div class="br-list" id="brList"></div>
                    <div class="initially-hidden" id="brEmpty"></div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-receipt', 'Recent Invoices', 'invSub', moreLink('billing', 'billing.php', 'Billing')); ?>
                    <div class="about-table-wrapper initially-hidden" id="invWrap">
                        <table class="about-roles-table">
                            <thead><tr>
                                <th><i class="fas fa-hashtag"></i> Invoice</th>
                                <th><i class="fas fa-layer-group"></i> Plan</th>
                                <th><i class="fas fa-money-bill"></i> Amount</th>
                                <th><i class="fas fa-circle-check"></i> Status</th>
                                <th><i class="fas fa-calendar"></i> Period</th>
                            </tr></thead>
                            <tbody id="invBody"></tbody>
                        </table>
                    </div>
                    <div class="initially-hidden" id="invEmpty"></div>
                <?php echo cardClose(); ?>
            </div>
<?php endif; ?>

<?php if ($view === 'branch'): ?>
            <!-- ===================== BRANCH ADMIN — one campus, same maths ===================== -->
            <?php echo cardOpen('fa-code-branch', 'Campus', 'brName', moreLink('attendance', 'attendance.php', 'Daily register')); ?>
                <p class="text-muted"><i class="fas fa-lock"></i> Every figure on this page is fenced to your campus — students, teachers and classes assigned to it, and the marks, results and register of those sections.</p>
            <?php echo cardClose(); ?>
            <?php $schoolBody(); ?>
<?php endif; ?>

<?php if ($view === 'principal'): ?>
            <!-- ===================== PRINCIPAL ===================== -->
            <?php echo skelKpis(4); ?>
            <div class="lte-kpi-grid initially-hidden" id="kpiGrid">
                <?php echo sbox('pkStudents',  'Active Students',       'fa-user-graduate',   'bg-navy',    'students', 'students.php');
                      echo sbox('pkTeachers',  'Active Teachers',       'fa-chalkboard-user', 'bg-info',    'teachers', 'teachers.php');
                      echo sbox('pkPublished', 'Published / Sections',  'fa-bullhorn',        'bg-success', 'results',  'results.php', $termSub);
                      echo sbox('pkPending',   'Awaiting Approval',     'fa-hourglass-half',  'bg-warning', 'results',  'results.php', $termSub); ?>
            </div>

            <div class="ib-strip">
                <?php echo ibox('ibClasses',    'Classes',                  'fa-school',          'bg-navy',    false, can('classes', 'v') ? 'classes.php' : '');
                      echo ibox('ibCompletion', 'Marks Entry ' . ($termSub ? '· ' . $termSub : ''), 'fa-pen-to-square', 'bg-navy', true, can('marks_entry', 'v') ? 'marks_entry.php' : '');
                      echo ibox('ibPassRate',   'Pass Rate',                'fa-award',           'bg-success', true,  can('results', 'v') ? 'results.php' : '');
                      echo ibox('ibAvg',        'School Average',           'fa-percent',         'bg-info',    false, can('broadsheet', 'v') ? 'broadsheet.php' : '');
                      echo ibox('ibAtt',        'Attendance Today',         'fa-user-check',      'bg-info',    true,  can('attendance', 'v') ? 'attendance.php' : '');
                      echo ibox('ibOwed',       'Outstanding Fees',         'fa-triangle-exclamation', 'bg-warning', false, can('fees', 'v') ? 'fees.php' : '');
                      echo ibox('ibExam',       'Next Exam',                'fa-calendar-check',  'bg-info',    false, can('timetable', 'v') ? 'timetable.php' : '');
                      echo ibox('ibTT',         'Timetable Filled',         'fa-calendar-days',   'bg-navy',    true,  can('timetable', 'v') ? 'timetable.php' : ''); ?>
            </div>

            <?php echo cardOpen('fa-chart-column', 'Class Comparison', 'cmpTerm'); ?>
                <?php echo skelChart('cmpSkeleton'); ?>
                <div class="chart-container initially-hidden" id="cmpWrap"><canvas id="chartCompare"></canvas></div>
                <div class="initially-hidden" id="cmpEmpty"></div>
            <?php echo cardClose(); ?>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-stamp', 'Approval Queue', 'qTerm', moreLink('results', 'results.php', 'Open results')); ?>
                    <?php echo skelTable(4, 4, 'qSkeleton'); ?>
                    <div class="about-table-wrapper initially-hidden" id="qWrap">
                        <table class="about-roles-table">
                            <thead><tr>
                                <th><i class="fas fa-school"></i> Class – Section</th>
                                <th><i class="fas fa-users"></i> Students</th>
                                <th><i class="fas fa-user-pen"></i> Submitted By</th>
                                <th><i class="fas fa-clock"></i> Waiting</th>
                            </tr></thead>
                            <tbody id="qBody"></tbody>
                        </table>
                    </div>
                    <div class="initially-hidden" id="qEmpty"></div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-list-check', 'Marks Entry by Section', 'secTerm', moreLink('marks_entry', 'marks_entry.php', 'Open marks entry')); ?>
                    <?php echo skelChart('secSkeleton'); ?>
                    <div class="initially-hidden" id="secWrap"></div>
                    <div class="initially-hidden" id="secEmpty"></div>
                <?php echo cardClose(); ?>
            </div>

            <?php echo cardOpen('fa-trophy', 'Top Performers', 'topTerm', moreLink('broadsheet', 'broadsheet.php', 'Broadsheet')); ?>
                <?php echo skelTable(5, 5, 'topSkeleton'); ?>
                <div class="about-table-wrapper initially-hidden" id="topWrap">
                    <table class="about-roles-table">
                        <thead><tr>
                            <th><i class="fas fa-user-graduate"></i> Student</th>
                            <th><i class="fas fa-school"></i> Class – Section</th>
                            <th><i class="fas fa-percent"></i> Percentage</th>
                            <th><i class="fas fa-star"></i> Grade</th>
                            <th><i class="fas fa-ranking-star"></i> Position</th>
                        </tr></thead>
                        <tbody id="topBody"></tbody>
                    </table>
                </div>
                <div class="initially-hidden" id="topEmpty"></div>
            <?php echo cardClose(); ?>
<?php endif; ?>

<?php if ($view === 'teacher'): ?>
            <!-- ===================== TEACHER ===================== -->
            <?php echo skelKpis(4); ?>
            <div class="lte-kpi-grid initially-hidden" id="kpiGrid">
                <?php echo sbox('tkSections', 'My Sections',      'fa-layer-group',    'bg-navy',    'results',     'results.php');
                      echo sbox('tkSubjects', 'My Subjects',      'fa-book',           'bg-info',    'subjects',    'subjects.php');
                      echo sbox('tkStudents', 'Students I Teach', 'fa-user-graduate',  'bg-success', 'students',    'students.php');
                      echo sbox('tkPending',  'Pending Entries',  'fa-hourglass-half', 'bg-warning', 'marks_entry', 'marks_entry.php', $termSub); ?>
            </div>

            <div class="ib-strip">
                <?php echo ibox('ibToday',  'Periods Today',        'fa-calendar-day',   'bg-navy',    false, can('timetable', 'v') ? 'timetable.php' : '');
                      echo ibox('ibAttDue', 'Registers Due Today',  'fa-user-check',     'bg-warning', false, can('attendance', 'v') ? 'attendance.php' : '');
                      echo ibox('ibExamT',  'Next Exam',            'fa-calendar-check', 'bg-info',    false, can('timetable', 'v') ? 'timetable.php' : '');
                      echo ibox('ibUnread', 'Unread Alerts',        'fa-bell',           'bg-success', false); ?>
            </div>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-calendar-day', "Today's Timetable", 'ttDay', moreLink('timetable', 'timetable.php', 'Full timetable')); ?>
                    <div class="tt-list initially-hidden" id="ttToday"></div>
                    <div class="initially-hidden" id="ttEmpty"></div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-user-check', 'Registers to Mark Today', '', moreLink('attendance', 'attendance.php', 'Daily register')); ?>
                    <div class="tt-list initially-hidden" id="attDue"></div>
                    <div class="initially-hidden" id="attDueEmpty"></div>
                <?php echo cardClose(); ?>
            </div>

            <?php echo cardOpen('fa-clipboard-list', 'My Assignments', 'asgTerm', moreLink('marks_entry', 'marks_entry.php', 'Marks entry')); ?>
                <div class="assign-grid" id="asgSkeleton">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                        <div class="skeleton skeleton-text skeleton-w-50 skeleton-mb-sm"></div>
                        <div class="skeleton skeleton-text skeleton-w-70"></div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="assign-grid initially-hidden" id="asgGrid"></div>
                <div class="initially-hidden" id="asgEmpty"></div>
            <?php echo cardClose(); ?>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-calendar-check', 'Upcoming Exams — My Classes', '', moreLink('timetable', 'timetable.php', 'Date sheet')); ?>
                    <div class="about-table-wrapper initially-hidden" id="exWrap">
                        <table class="about-roles-table">
                            <thead><tr>
                                <th><i class="fas fa-calendar"></i> Date</th>
                                <th><i class="fas fa-book"></i> Subject</th>
                                <th><i class="fas fa-school"></i> Class</th>
                                <th><i class="fas fa-door-open"></i> Room</th>
                            </tr></thead>
                            <tbody id="exBody"></tbody>
                        </table>
                    </div>
                    <div class="initially-hidden" id="exEmpty"></div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-bullhorn', 'Published Results — My Sections'); ?>
                    <?php echo skelTable(4, 5, 'tpubSkeleton'); ?>
                    <div class="about-table-wrapper initially-hidden" id="tpubWrap">
                        <table class="about-roles-table">
                            <thead><tr>
                                <th><i class="fas fa-school"></i> Class – Section</th>
                                <th><i class="fas fa-calendar-check"></i> Term</th>
                                <th><i class="fas fa-users"></i> Students</th>
                                <th><i class="fas fa-award"></i> Passed</th>
                                <th><i class="fas fa-clock"></i> Published</th>
                            </tr></thead>
                            <tbody id="tpubBody"></tbody>
                        </table>
                    </div>
                    <div class="initially-hidden" id="tpubEmpty"></div>
                <?php echo cardClose(); ?>
            </div>
<?php endif; ?>

<?php if ($view === 'student'): ?>
            <!-- ===================== STUDENT / PARENT ===================== -->
            <?php echo skelKpis(4); ?>
            <div class="lte-kpi-grid initially-hidden" id="kpiGrid">
                <?php echo sbox('skPercent',  'Latest Percentage', 'fa-percent',      'bg-navy',    'my_results', 'my_results.php');
                      echo sbox('skGrade',    'Grade',             'fa-star',         'bg-info',    'my_results', 'my_results.php');
                      echo sbox('skPosition', 'Position',          'fa-ranking-star', 'bg-success', 'my_results', 'my_results.php');
                      echo sbox('skSubjects', 'Subjects',          'fa-book-open',    'bg-warning', 'my_results', 'my_results.php'); ?>
            </div>

            <div class="ib-strip">
                <?php echo ibox('ibAttS',    'Attendance ' . ($year ? '· ' . htmlspecialchars($year['name']) : ''), 'fa-user-check', 'bg-info', true);
                      echo ibox('ibFeeS',    'Fee Balance',   'fa-money-bill',     'bg-success', false);
                      echo ibox('ibExamS',   'Next Exam',     'fa-calendar-check', 'bg-navy',    false);
                      echo ibox('ibUnreadS', 'Unread Alerts', 'fa-bell',           'bg-warning', false); ?>
            </div>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-chart-line', 'My Percentage Across Terms', '', moreLink('my_results', 'my_results.php', 'My Results')); ?>
                    <?php echo skelChart('trendSkeleton'); ?>
                    <div class="chart-container initially-hidden" id="trendWrap"><canvas id="chartTrend"></canvas></div>
                    <div class="initially-hidden" id="trendEmpty"></div>
                <?php echo cardClose(); ?>

                <?php echo cardOpen('fa-calendar-day', "Today's Timetable", 'ttDayS'); ?>
                    <div class="tt-list initially-hidden" id="ttTodayS"></div>
                    <div class="initially-hidden" id="ttEmptyS"></div>
                <?php echo cardClose(); ?>
            </div>

            <?php echo cardOpen('fa-file-circle-check', 'My Published Results'); ?>
                <?php echo skelTable(3, 5, 'myPubSkeleton'); ?>
                <div class="about-table-wrapper initially-hidden" id="myPubWrap">
                    <table class="about-roles-table">
                        <thead><tr>
                            <th><i class="fas fa-calendar-check"></i> Term</th>
                            <th><i class="fas fa-calendar-days"></i> Session</th>
                            <th><i class="fas fa-percent"></i> Percentage</th>
                            <th><i class="fas fa-star"></i> Grade</th>
                            <th><i class="fas fa-circle-check"></i> Result</th>
                        </tr></thead>
                        <tbody id="myPubBody"></tbody>
                    </table>
                </div>
                <div class="initially-hidden" id="myPubEmpty"></div>
            <?php echo cardClose(); ?>
<?php endif; ?>

<?php if ($view === 'platform'): ?>
            <!-- ===================== APP OWNER — the whole platform ===================== -->
            <?php echo cardOpen('fa-heart-pulse', 'Platform Status', 'pfFlagsSub', '<button type="button" class="btn btn-secondary btn-sm" onclick="loadPlatform(this)"><i class="fas fa-sync"></i> Refresh</button>'); ?>
                <div class="health-list" id="pfFlags"></div>
            <?php echo cardClose(); ?>

            <?php echo skelKpis(4, 'pfSkeleton'); ?>
            <div class="lte-kpi-grid initially-hidden" id="pfKpi">
                <?php echo sbox('pfSchools',  'Schools',  'fa-city',            'bg-navy',    'schools',  'schools.php');
                      echo sbox('pfStudents', 'Students', 'fa-user-graduate',   'bg-info');
                      echo sbox('pfTeachers', 'Teachers', 'fa-chalkboard-user', 'bg-success');
                      echo sbox('pfUsers',    'Logins',   'fa-users',           'bg-navy',    'users',    'users.php'); ?>
            </div>
            <div class="lte-kpi-grid initially-hidden" id="pfMoney">
                <?php echo sbox('pfMonth',   'Collected This Month',       'fa-sack-dollar',         'bg-success', 'subscriptions', 'subscriptions.php');
                      echo sbox('pfPending', 'Outstanding Invoices',       'fa-file-invoice-dollar', 'bg-warning', 'subscriptions', 'subscriptions.php');
                      echo sbox('pfOk',      'Subscriptions in Good Standing', 'fa-circle-check',   'bg-success', 'schools',       'schools.php');
                      echo sbox('pfSoon',    'Expiring Within 30 Days',    'fa-hourglass-half',      'bg-danger',  'schools',       'schools.php'); ?>
            </div>

            <div class="dashboard-grid-2">
                <?php echo cardOpen('fa-chart-column', 'Collections — Last 6 Months', 'pfCcy'); ?>
                    <div class="chart-container" id="pfRevWrap"><canvas id="chartRev"></canvas></div>
                    <div class="initially-hidden" id="pfRevEmpty"></div>
                <?php echo cardClose(); ?>
                <?php echo cardOpen('fa-chart-pie', 'Schools by Plan', '', moreLink('plans', 'plans.php', 'Plans')); ?>
                    <div class="dc-donut" id="pfPlanWrap">
                        <div class="dc-donut-c"><canvas id="chartPlan"></canvas></div>
                        <div class="dc-legend" id="planLegend"></div>
                    </div>
                    <div class="initially-hidden" id="pfPlanEmpty"></div>
                <?php echo cardClose(); ?>
            </div>

            <div class="ib-strip">
                <?php echo ibox('pfWOk',      'Healthy Subscriptions',  'fa-circle-check',   'bg-success', false, can('schools', 'v') ? 'schools.php' : '');
                      echo ibox('pfWSoon',    'Expiring Within 30 Days','fa-hourglass-half', 'bg-warning', false, can('schools', 'v') ? 'schools.php' : '');
                      echo ibox('pfWExpired', 'Expired / Stopped',      'fa-circle-xmark',   'bg-danger',  false, can('schools', 'v') ? 'schools.php' : '');
                      echo ibox('pfWNever',   'Never Billed',           'fa-question',       'bg-navy',    false, can('subscriptions', 'v') ? 'subscriptions.php' : ''); ?>
            </div>

            <?php echo cardOpen('fa-triangle-exclamation', 'Needs Attention', '', moreLink('schools', 'schools.php', 'All schools')); ?>
                <div class="table-responsive">
                    <table class="about-roles-table" id="pfNeed"><tbody></tbody></table>
                </div>
            <?php echo cardClose(); ?>

            <?php echo cardOpen('fa-server', 'System', '', moreLink('backup', 'backup.php', 'Backup')); ?>
                <div class="stu360-grid" id="pfSystem"></div>
            <?php echo cardClose(); ?>
<?php endif; ?>

<?php if ($view === 'basic'): ?>
            <!-- ===================== FALLBACK ===================== -->
            <?php echo cardOpen('fa-circle-info', 'Welcome'); ?>
                <div class="orms-empty">
                    <i class="fas fa-id-badge"></i>
                    <h4>Welcome, <?php echo htmlspecialchars($username); ?></h4>
                    <p>Your account is signed in as <strong><?php echo htmlspecialchars($role); ?></strong> but no student or teacher profile is linked to it yet. Ask an administrator to complete your profile.</p>
                </div>
            <?php echo cardClose(); ?>
<?php endif; ?>

            <!-- Recent Activity — the wide views carry their own copy inside the bottom row -->
<?php if (!in_array($view, ['admin', 'owner', 'branch'], true)): ?>
            <?php echo cardOpen('fa-clock-rotate-left', 'Recent Activities', '', moreLink('logs', 'logs.php')); ?>
                <?php echo skelRows(5, 'actSkeleton'); ?>
                <div class="da-list initially-hidden" id="actList"></div>
                <div class="initially-hidden" id="actEmpty"></div>
            <?php echo cardClose(); ?>
<?php endif; ?>

            <button type="button" class="dash-fab" onclick="dashTop()" title="Back to top"><i class="fas fa-rocket"></i></button>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <script>
    var VIEW     = '<?php echo $view; ?>';
    var STATS    = '<?php echo $statsAction; ?>';   // adminStats / ownerStats / branchStats — one loader, three fences
    var IS_WIDE  = <?php echo $isWide ? 'true' : 'false'; ?>;   // school-wide reach -> activity shows the User column
    var TERM     = <?php echo json_encode($activeTerm ? $activeTerm['name'] : ''); ?>;
    var YEAR     = <?php echo json_encode($year ? $year['name'] : ''); ?>;
    var charts   = {};

    var esc = window.ORMS && ORMS.esc ? ORMS.esc : function (s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    function el(id) { return document.getElementById(id); }
    function show(id, on) { var n = el(id); if (n) n.classList.toggle('initially-hidden', !on); }

    // collapse / expand a card
    function toggleLteCard(btn) {
        var card = btn.closest('.lte-card') || btn.closest('.dc-card');
        var collapsed = card.classList.toggle('collapsed');
        var icon = btn.querySelector('i');
        if (icon) icon.className = 'fas fa-' + (collapsed ? 'plus' : 'minus');
        btn.title = collapsed ? 'Expand' : 'Collapse';
    }

    function emptyBox(id, icon, title, text) {
        var n = el(id);
        if (!n) return;
        n.innerHTML = '<div class="orms-empty"><i class="fas ' + icon + '"></i><h4>' + esc(title) + '</h4><p>' + esc(text) + '</p></div>';
        show(id, true);
    }

    function state(pct, published) {
        if (published) return 'is-published';
        return pct >= 100 ? 'is-complete' : (pct > 0 ? 'is-partial' : 'is-none');
    }

    // the only inline style on this page — a runtime-computed bar width
    function bar(pct, st) {
        return '<div class="completion-wrap ' + st + '"><div class="completion-bar">' +
               '<span class="completion-fill ' + st + '" style="width:' + pct + '%"></span></div>' +
               '<span class="completion-pct ' + st + '">' + pct + '%</span></div>';
    }

    // charts follow the user's palette instead of hardcoded hexes
    function cssVar(name, fb) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v || '').trim() || fb;
    }

    function draw(id, cfg) {
        if (!window.Chart || !el(id)) return;
        if (charts[id]) charts[id].destroy();
        Chart.defaults.color = cssVar('--text-secondary', '#555');
        Chart.defaults.font.family = 'inherit';
        charts[id] = new Chart(el(id), cfg);
    }

    function pctAxis() {
        return { y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + '%'; } },
                      grid: { color: cssVar('--border-color', '#e0e0e0') } },
                 x: { grid: { display: false } } };
    }

    // dashboard accent ramp — same 5 hues the kpi tiles and sparklines share
    var HUE  = ['#6366f1', '#10b981', '#a855f7', '#f59e0b', '#14b8a6'];
    var GRAD = ['#3b82f6', '#22c55e', '#1f2937', '#f5b301', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316'];
    var FONT = '"Segoe UI", system-ui, -apple-system, sans-serif';

    function fade(hex, a) {
        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }

    // axis-less 6-month line behind each kpi number
    function spark(canvasId, data, color) {
        if (!el(canvasId) || !data || !data.length) return;
        draw(canvasId, {
            type: 'line',
            data: { labels: data.map(function (_, i) { return i; }),
                    datasets: [{ data: data, borderColor: color, backgroundColor: fade(color, 0.15),
                                 borderWidth: 2, fill: true, tension: 0.42, pointRadius: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, events: [],
                       scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
                       plugins: { legend: { display: false }, tooltip: { enabled: false } } }
        });
    }

    // ring gauge — the % itself is a positioned span, so no canvas text plugin needed
    function gauge(canvasId, pct, color) {
        if (!el(canvasId)) return;
        pct = Math.max(0, Math.min(100, Number(pct) || 0));
        draw(canvasId, {
            type: 'doughnut',
            data: { datasets: [{ data: [pct, 100 - pct], borderWidth: 0,
                                 backgroundColor: [color, cssVar('--border-light', '#eee')] }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '76%', rotation: -90,
                       events: [], plugins: { legend: { display: false }, tooltip: { enabled: false } } }
        });
    }

    // value printed above each bar
    var barLabel = {
        id: 'barLabel',
        afterDatasetsDraw: function (c) {
            var ctx = c.ctx, ds = c.getDatasetMeta(0);
            ctx.save();
            ctx.fillStyle = cssVar('--text-primary', '#333');
            ctx.font = '700 12px ' + FONT;
            ctx.textAlign = 'center';
            ds.data.forEach(function (b, i) { ctx.fillText(c.data.datasets[0].data[i] + '%', b.x, b.y - 9); });
            ctx.restore();
        }
    };

    // share printed inside each arc — skipped when the slice is too thin to hold it
    var arcPct = {
        id: 'arcPct',
        afterDatasetsDraw: function (c) {
            var d = c.data.datasets[0].data, tot = d.reduce(function (a, b) { return a + b; }, 0) || 1;
            var ctx = c.ctx;
            ctx.save();
            ctx.fillStyle = '#fff'; ctx.font = '700 12px ' + FONT;
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            c.getDatasetMeta(0).data.forEach(function (arc, i) {
                var pct = Math.round(d[i] / tot * 100);
                if (pct < 5) return;
                var pos = arc.tooltipPosition();
                ctx.fillText(pct + '%', pos.x, pos.y);
            });
            ctx.restore();
        }
    };

    function dashTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); }

    // ---------------------------------------------------------------- shared setters
    var TERM_ID = 0;                                     // term both charts are showing

    function setTxt(id, v)  { var n = el(id); if (n) n.textContent = (v === null || v === undefined) ? '—' : v; }
    function setHtml(id, h) { var n = el(id); if (n) n.innerHTML = h; }
    function cap(id, v)     { setTxt(id + 'Cap', v || ''); }
    // info-box: number, caption, optional bar %. a null reading hides the whole box
    function ib(id, val, desc, pct) {
        var n = el(id); if (!n) return;
        var box = el(id + 'Box'); if (box) box.hidden = false;
        n.textContent = val;
        setTxt(id + 'Desc', desc || '');
        var b = el(id + 'Bar'); if (b && pct !== undefined) b.style.width = Math.max(0, Math.min(100, Number(pct) || 0)) + '%';
    }
    function ibHide(id) { var box = el(id + 'Box'); if (box) box.hidden = true; }
    // swap a small-box tone at runtime (subscription: green while alive, red once it lapsed)
    function tone(id, cls) { var n = el(id); if (!n) return; var box = n.closest('.small-box'); if (box) box.className = 'small-box ' + cls; }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }

    // the "today" strip shared by admin / owner / branch / principal
    function renderOps(r) {
        var o = r.ops || {}, m = r.money || {};
        if (o.attendance) {
            var a = o.attendance;
            ib('ibAtt', a.marked > 0 ? a.pct + '%' : 'Not marked yet',
               a.marked > 0 ? a.present + ' present · ' + a.absent + ' absent · ' + a.secs + ' / ' + a.secs_total + ' sections' : a.secs_total + ' section(s) waiting for today\'s register',
               a.marked > 0 ? a.pct : 0);
        } else ibHide('ibAtt');
        if (o.fees) ib('ibFees', o.fees.month_f, o.fees.month_name + ' · ' + plural(o.fees.month_n, 'payment') + (o.fees.today > 0 ? ' · today ' + o.fees.today_f : ''));
        else ibHide('ibFees');
        if (m.fees) ib('ibOwed', m.fees.students > 0 ? m.fees.money : 'All clear', (m.fees.students > 0 ? plural(m.fees.students, 'student') + ' owing' : 'No outstanding fees') + (YEAR ? ' · ' + YEAR : ''));
        else ibHide('ibOwed');
        if (o.exam && o.exam.subject) ib('ibExam', o.exam.subject + ' · ' + o.exam.on, o.exam.class + (o.exam.time ? ' · ' + o.exam.time : '') + (o.exam.days === 0 ? ' · today' : ' · in ' + plural(o.exam.days, 'day')) + (o.exam.week ? ' · ' + o.exam.week + ' this week' : ''));
        else if (o.exam) ib('ibExam', 'None scheduled', 'the date sheet has no upcoming exam');
        else ibHide('ibExam');
        if (o.timetable) ib('ibTT', o.timetable.filled + ' / ' + o.timetable.total, o.timetable.pct + '% of sections have periods', o.timetable.pct);
        else ibHide('ibTT');
    }

    // ---------------------------------------------------------------- admin / owner / branch
    function schoolSkeletonsOff() {
        show('kpiSkeleton', false); show('passSkeleton', false); show('gradeSkeleton', false);
        show('secSkeleton', false); show('subSkeleton', false);
    }

    function loadSchool(termId) {
        ORMS.post(STATS, termId ? { term_id: termId } : {}).done(function (r) {
            schoolSkeletonsOff();
            if (!r || !r.success) {
                emptyBox('passEmpty', 'fa-triangle-exclamation', 'Could not load dashboard data',
                         (r && r.message) || 'Run setup.php if the academic tables are not installed yet.');
                return;
            }
            TERM_ID = r.chart_term;
            renderKpis(r);
            renderStrip(r);
            renderOps(r);
            fillTerms(r);
            renderPass(r);
            renderGrades(r);
            renderSections(r);
            if (r.sub !== undefined) renderSub(r);
            if (r.branches)          renderBranches(r.branches);
            if (r.branch)            setTxt('brName', r.branch.name);
        }).fail(function () {
            schoolSkeletonsOff();
            emptyBox('passEmpty', 'fa-triangle-exclamation', 'Connection error', 'Please refresh the page and try again.');
        });
    }

    // term picker on either chart card drives both — one request, one term
    function pickTerm(v) {
        var id = parseInt(v, 10) || 0;
        if (!id || id === TERM_ID) return;
        show('passWrap', false); show('passEmpty', false); show('passSkeleton', true);
        show('gradeWrap', false); show('gradeEmpty', false); show('gradeSkeleton', true);
        loadSchool(id);
    }

    function fillTerms(r) {
        var html = (r.terms || []).map(function (t) {
            return '<option value="' + t.id + '"' + (t.id === r.chart_term ? ' selected' : '') + '>' + esc(t.name) + '</option>';
        }).join('') || '<option value="0">No exam term</option>';
        ['passTermSel', 'gradeTermSel'].forEach(function (id) {
            var n = el(id);
            if (!n) return;
            n.innerHTML = html;
            if (window.ORMS && ORMS.dropdown) ORMS.dropdown.refresh('#' + id);
        });
    }

    function renderKpis(r) {
        var k = r.kpi, dl = r.delta || {};
        setTxt('kpiStudents',  k.students);  cap('kpiStudents',  dl.students > 0 ? '+' + dl.students + ' this month' : 'No new admissions this month');
        setTxt('kpiTeachers',  k.teachers);  cap('kpiTeachers',  dl.teachers > 0 ? '+' + dl.teachers + ' this month' : 'No new staff this month');
        setTxt('kpiClasses',   k.classes);   cap('kpiClasses',   plural(k.sections, 'section'));
        setTxt('kpiPublished', k.published + ' / ' + k.sections); cap('kpiPublished', TERM || 'No exam term');
        show('kpiGrid', true);
    }

    // marks entry + pass rate + subjects on the strip
    function renderStrip(r) {
        var cp = Math.round(r.completion.pct);
        ib('ibMarks', cp + '%', r.completion.entered + ' / ' + r.completion.expected + ' entered · ' + Math.max(0, r.completion.expected - r.completion.entered) + ' pending', cp);
        var pr = r.pass_rate, has = pr.total > 0;
        ib('ibPass', has ? pr.pct + '%' : '—', has ? pr.passed + ' / ' + pr.total + ' passed' + (r.pub_term ? ' · ' + r.pub_term : '') : 'Nothing published yet', has ? pr.pct : 0);
        var k = r.kpi;
        ib('ibSubjects', k.subjects, k.subjects === k.subjects_all ? 'All active' : k.subjects + ' active of ' + k.subjects_all);
    }

    // pass % per class of the picked term
    function renderPass(r) {
        var d = r.by_class || [];
        show('passEmpty', false);
        if (!d.length) {
            show('passWrap', false);
            emptyBox('passEmpty', 'fa-chart-column', 'No published results yet',
                     'Publish a section from the Results page and the pass rate per class shows up here.');
            return;
        }
        show('passWrap', true);
        draw('chartPass', {
            type: 'bar',
            data: { labels: d.map(function (b) { return b.label; }),
                    datasets: [{ label: 'Pass %', data: d.map(function (b) { return b.pct; }),
                                 backgroundColor: cssVar('--navy-accent', '#0074D9'), hoverBackgroundColor: cssVar('--navy-primary', '#001f3f'),
                                 borderRadius: 0, maxBarThickness: 58 }] },
            options: {
                responsive: true, maintainAspectRatio: false,
                layout: { padding: { top: 18 } },
                scales: {
                    y: { beginAtZero: true, max: 100, border: { display: false },
                         ticks: { stepSize: 20, callback: function (v) { return v + '%'; } },
                         grid: { color: cssVar('--border-light', '#f0f0f0') } },
                    x: { border: { display: false }, grid: { display: false } }
                },
                plugins: { legend: { display: false },
                           tooltip: { callbacks: { label: function (c) {
                               return c.parsed.y + '% passed of ' + d[c.dataIndex].total + ' students'; } } } }
            },
            plugins: [barLabel]
        });
    }

    // doughnut + its own legend list, so each grade shows share and headcount side by side
    function renderGrades(r) {
        var d = r.grades || [];
        show('gradeEmpty', false);
        if (!d.length) {
            show('gradeWrap', false);
            emptyBox('gradeEmpty', 'fa-chart-pie', 'No grades to chart',
                     'Grade distribution builds from the published result summaries of the selected term.');
            return;
        }
        show('gradeWrap', true);
        var tot = d.reduce(function (a, g) { return a + g.n; }, 0) || 1;
        draw('chartGrades', {
            type: 'doughnut',
            data: { labels: d.map(function (g) { return g.label; }),
                    datasets: [{ data: d.map(function (g) { return g.n; }), borderWidth: 0,
                                 backgroundColor: d.map(function (_, i) { return GRAD[i % GRAD.length]; }) }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
                       plugins: { legend: { display: false },
                                  tooltip: { callbacks: { label: function (c) {
                                      return c.label + ': ' + c.parsed + ' student(s)'; } } } } },
            plugins: [arcPct]
        });
        el('gradeLegend').innerHTML = d.map(function (g, i) {
            return '<div class="dl-row"><span class="dl-dot" style="background:' + GRAD[i % GRAD.length] + '"></span>' +
                   '<span class="dl-name">' + esc(g.label) + ' <em>(' + Math.round(g.n / tot * 100) + '%)</em></span>' +
                   '<span class="dl-n">' + plural(g.n, 'Student') + '</span></div>';
        }).join('');
    }

    // who still owes marks — sorted worst-first so the top of the list is the call to make
    function renderSections(r) {
        var d = (r.sections || []).slice().sort(function (a, b) { return a.pct - b.pct; });
        setTxt('secTerm', TERM || '');
        if (!d.length) {
            emptyBox('secEmpty', 'fa-list-check', 'Nothing to track',
                     'Map subjects to a class and add students, then entry progress shows here.');
            return;
        }
        el('secWrap').innerHTML = d.map(function (s) {
            var st = state(s.pct, s.published);
            var tag = s.published
                ? ' <span class="status-badge status-active"><i class="fas fa-bullhorn"></i> Published</span>'
                : (s.approval === 'Pending'
                    ? ' <span class="action-badge action-badge-warning"><i class="fas fa-hourglass-half"></i> Pending</span>' : '');
            return '<div class="dash-prog">'
                 +   '<div class="dash-prog-top">'
                 +     '<span>' + esc(s.label) + tag + '</span>'
                 +     '<b>' + s.entered + ' / ' + s.expected + '</b>'
                 +   '</div>'
                 +   bar(s.pct, st)
                 + '</div>';
        }).join('');
        show('secWrap', true);
    }

    // ---- owner: the subscription row + invoices
    function renderSub(r) {
        var sub = r.sub;
        show('subGrid', true);
        if (!sub) {
            setTxt('subPlan', '—'); cap('subPlan', 'billing not installed');
            setTxt('subDays', '—'); setTxt('subSeats', r.kpi.students); cap('subSeats', 'no limit'); setTxt('subInv', 0);
            emptyBox('invEmpty', 'fa-receipt', 'No billing yet', 'Invoices appear here once the platform starts billing this school.');
            return;
        }
        setTxt('subPlan', sub.plan || 'No plan'); cap('subPlan', sub.school + (sub.code ? ' · ' + sub.code : ''));
        var d = sub.days;
        if (!sub.mode) {
            setTxt('subDays', 'Unlimited'); cap('subDays', 'SaaS mode is off — no expiry'); tone('subDays', 'bg-success');
        } else if (sub.status === 'active') {
            setTxt('subDays', d === null ? 'Active' : plural(d, 'day') + ' left');
            cap('subDays', sub.ends_at ? 'Renews ' + sub.ends_at.slice(0, 10) : 'No end date');
            tone('subDays', d !== null && d <= 30 ? 'bg-warning' : 'bg-success');
        } else {
            setTxt('subDays', sub.status.charAt(0).toUpperCase() + sub.status.slice(1));
            cap('subDays', d !== null && d < 0 ? Math.abs(d) + ' days overdue — renew now' : 'renew to restore access');
            tone('subDays', 'bg-danger');
        }
        var q = (sub.quotas || []).filter(function (x) { return x.kind === 'students'; })[0];
        if (q && q.enforced && !q.unlimited) {
            setTxt('subSeats', q.used + ' / ' + q.cap); cap('subSeats', plural(q.left, 'seat') + ' left on ' + (q.plan || 'the plan'));
            tone('subSeats', q.left <= 0 ? 'bg-danger' : (q.used / q.cap >= 0.85 ? 'bg-warning' : 'bg-info'));
        } else {
            setTxt('subSeats', r.kpi.students); cap('subSeats', 'no seat limit on ' + (sub.plan || 'this plan'));
        }
        setTxt('subInv', sub.pend_n); cap('subInv', sub.pend_n > 0 ? sub.currency + ' ' + ORMS.money(sub.pend_amt) + ' awaiting payment' : 'nothing due');
        tone('subInv', sub.pend_n > 0 ? 'bg-warning' : 'bg-success');

        setTxt('invSub', sub.currency || '');
        var inv = sub.invoices || [];
        if (!inv.length) { emptyBox('invEmpty', 'fa-receipt', 'No invoices yet', sub.billing_on ? 'Renew from the Subscription page when the period is about to end.' : 'Self-serve checkout is off — the platform raises invoices for this school.'); return; }
        el('invBody').innerHTML = inv.map(function (i) {
            var cls = i.status === 'Paid' ? 'status-badge status-active' : (i.status === 'Pending' ? 'action-badge action-badge-warning' : 'status-badge status-inactive');
            return '<tr><td><b>' + esc(i.invoice_no) + '</b><br><small class="text-muted">' + esc(i.gateway || '') + ' · ' + esc(i.cycle || '') + '</small></td>' +
                   '<td>' + esc(i.plan_name || '—') + '</td>' +
                   '<td>' + esc(i.currency) + ' ' + ORMS.money(i.amount) + '</td>' +
                   '<td><span class="' + cls + '">' + esc(i.status) + '</span></td>' +
                   '<td>' + esc(String(i.period_start || '').slice(0, 10)) + ' → ' + esc(String(i.period_end || '').slice(0, 10)) + '</td></tr>';
        }).join('');
        show('invWrap', true);
    }

    // ---- owner: every campus as an info-box with its fill bar
    function renderBranches(list) {
        setTxt('brSub', plural(list.length, 'campus', 'campuses'));
        if (!list.length) { emptyBox('brEmpty', 'fa-code-branch', 'No campuses yet', 'Add a branch and its students, teachers and classes line up here.'); return; }
        el('brList').innerHTML = list.map(function (b) {
            var fill = b.fill === null ? null : Math.min(100, b.fill);
            var toneCls = fill === null ? 'bg-navy' : (fill >= 100 ? 'bg-danger' : (fill >= 85 ? 'bg-warning' : 'bg-info'));
            return '<div class="info-box"><span class="info-box-icon ' + toneCls + '"><i class="fas ' + (b.main ? 'fa-building-flag' : 'fa-building') + '"></i></span>' +
                   '<div class="info-box-content"><span class="info-box-text">' + esc(b.name) + (b.main ? ' · main' : '') + (b.city ? ' · ' + esc(b.city) : '') + '</span>' +
                   '<span class="info-box-number">' + b.students + ' <small>students' + (b.capacity ? ' of ' + b.capacity : '') + '</small></span>' +
                   (fill !== null ? '<div class="info-box-progress"><span style="width:' + fill + '%"></span></div>' : '') +
                   '<span class="info-box-desc">' + plural(b.teachers, 'teacher') + ' · ' + plural(b.classes, 'class', 'classes') + (b.status !== 'Active' ? ' · ' + esc(b.status) : '') + '</span></div></div>';
        }).join('');
    }

    // best of the published term — principal view
    function renderTop(r) {
        var d = r.top || [];
        setTxt('topTerm', r.pub_term || '');
        if (!d.length) {
            emptyBox('topEmpty', 'fa-trophy', 'No ranking yet', 'Positions are computed when a section is published.');
            return;
        }
        el('topBody').innerHTML = d.map(function (t) {
            var badge = t.status === 'PASS' ? 'rc-badge-pass' : 'rc-badge-fail';
            return '<tr><td>' + esc(t.name) + '</td><td>' + esc(t.section) + '</td>' +
                   '<td><strong>' + t.percent.toFixed(2) + '%</strong></td>' +
                   '<td><span class="rc-badge ' + badge + '">' + esc(t.grade) + '</span></td>' +
                   '<td>' + esc(t.position) + '</td></tr>';
        }).join('');
        show('topWrap', true);
    }

    // ------------------------------------------------------------ principal
    function principalSkeletonsOff() {
        show('kpiSkeleton', false); show('cmpSkeleton', false);
        show('secSkeleton', false); show('qSkeleton', false); show('topSkeleton', false);
    }

    function loadPrincipal() {
        ORMS.post('principalStats', {}).done(function (r) {
            principalSkeletonsOff();
            if (!r || !r.success) {
                emptyBox('cmpEmpty', 'fa-triangle-exclamation', 'Could not load dashboard data',
                         (r && r.message) || 'Please refresh the page and try again.');
                return;
            }
            setTxt('pkStudents', r.kpi.students);
            setTxt('pkTeachers', r.kpi.teachers);
            setTxt('pkPublished', r.kpi.published + ' / ' + r.kpi.sections);
            setTxt('pkPending', r.kpi.pending); cap('pkPending', r.kpi.pending > 0 ? 'sections waiting for your sign-off' : (TERM || ''));
            show('kpiGrid', true);

            var cp = Math.round(r.completion.pct);
            ib('ibClasses', r.kpi.classes, plural(r.kpi.sections, 'section'));
            ib('ibCompletion', cp + '%', r.completion.entered + ' / ' + r.completion.expected + ' entered', cp);
            var has = r.pass_rate.total > 0, pub = r.pub_term || '';
            ib('ibPassRate', has ? r.pass_rate.pct + '%' : '—', has ? r.pass_rate.passed + ' / ' + r.pass_rate.total + ' passed' + (pub ? ' · ' + pub : '') : 'Nothing published yet', has ? r.pass_rate.pct : 0);
            ib('ibAvg', has ? Number(r.avg_pct).toFixed(2) + '%' : '—', has ? 'average of every published card' + (pub ? ' · ' + pub : '') : 'Nothing published yet');
            setTxt('cmpTerm', pub);
            setTxt('qTerm', TERM || '');

            renderOps(r);
            renderCompare(r);
            renderSections(r);
            renderQueue(r);
            renderTop(r);
        }).fail(function () {
            principalSkeletonsOff();
            emptyBox('cmpEmpty', 'fa-triangle-exclamation', 'Connection error', 'Please refresh the page and try again.');
        });
    }

    // class vs class — average sits next to pass %, so a weak class can't hide behind a good pass rate
    function renderCompare(r) {
        var d = r.by_class || [];
        if (!d.length) {
            emptyBox('cmpEmpty', 'fa-chart-column', 'No published results yet',
                     'Approve and publish a section and every class lines up here for comparison.');
            return;
        }
        show('cmpWrap', true);
        draw('chartCompare', {
            type: 'bar',
            data: {
                labels: d.map(function (b) { return b.label; }),
                datasets: [
                    { label: 'Average %', data: d.map(function (b) { return b.avg; }),
                      backgroundColor: cssVar('--navy-accent', '#0074D9'), borderRadius: 0, maxBarThickness: 34 },
                    { label: 'Pass %', data: d.map(function (b) { return b.pct; }),
                      backgroundColor: cssVar('--success', '#34a853'), borderRadius: 0, maxBarThickness: 34 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, scales: pctAxis(),
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 14, padding: 12 } },
                    tooltip: { callbacks: { afterBody: function (items) {
                        return d[items[0].dataIndex].total + ' published result(s)';
                    } } }
                }
            }
        });
    }

    // sections sitting on the head's desk — oldest first, that is the one to open
    function renderQueue(r) {
        var d = r.queue || [];
        if (!r.approval) {
            emptyBox('qEmpty', 'fa-database', 'Approval workflow not installed',
                     'Run update_setup.php once and submitted sections start queueing here.');
            return;
        }
        if (!d.length) {
            emptyBox('qEmpty', 'fa-circle-check', 'Nothing waiting', 'No section is pending your approval for this term.');
            return;
        }
        el('qBody').innerHTML = d.map(function (q) {
            var wait = q.days > 0 ? plural(q.days, 'day') : 'Today';
            return '<tr><td>' + esc(q.label) + '</td><td>' + q.total + '</td>' +
                   '<td>' + esc(q.by) + '</td>' +
                   '<td><span class="action-badge action-badge-warning">' + esc(wait) + '</span> ' +
                   '<span class="text-muted">' + esc(q.on) + '</span></td></tr>';
        }).join('');
        show('qWrap', true);
    }

    // -------------------------------------------------------------- shared "today" lists
    function ttRow(x, tail) {
        return '<div class="tt-row"><span class="tt-p">P' + x.p + '</span>' +
               '<span class="tt-time">' + esc(x.from || '') + (x.to ? ' – ' + esc(x.to) : '') + '</span>' +
               '<b>' + esc(x.subject) + '</b><em>' + esc(tail || '') + '</em></div>';
    }
    var DAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    // -------------------------------------------------------------- teacher
    function loadTeacher() {
        ORMS.post('teacherStats', {}).done(function (r) {
            show('kpiSkeleton', false);
            show('asgSkeleton', false);
            show('tpubSkeleton', false);
            setTxt('asgTerm', TERM || '');
            setTxt('ttDay', DAYS[new Date().getDay() || 7]);

            if (!r || !r.success) {
                emptyBox('asgEmpty', 'fa-triangle-exclamation', 'Could not load your assignments',
                         (r && r.message) || 'Please refresh the page and try again.');
                return;
            }
            if (!r.linked) {
                emptyBox('asgEmpty', 'fa-user-slash', 'No teacher profile linked',
                         'Your login is not linked to a teacher profile yet. Ask an admin to create one and assign your subjects.');
                emptyBox('tpubEmpty', 'fa-bullhorn', 'Nothing published', 'Published results appear once a profile is linked.');
                emptyBox('ttEmpty', 'fa-calendar-day', 'No timetable', 'Your periods appear once a profile is linked.');
                emptyBox('attDueEmpty', 'fa-user-check', 'Nothing to mark', 'Registers appear once a profile is linked.');
                emptyBox('exEmpty', 'fa-calendar-check', 'No exams', 'The date sheet of your classes appears once a profile is linked.');
                return;
            }

            setTxt('tkSections', r.kpi.sections);
            setTxt('tkSubjects', r.kpi.subjects);
            setTxt('tkStudents', r.kpi.students);
            setTxt('tkPending', r.kpi.pending); cap('tkPending', r.kpi.pending > 0 ? 'cells still empty' : (TERM || ''));
            show('kpiGrid', true);

            // the strip
            var today = r.today || [], due = r.att_due || [], ex = r.exams || [];
            ib('ibToday', today.length, today.length ? 'P' + today[0].p + ' at ' + today[0].from + ' first' : 'no periods on the grid today');
            ib('ibAttDue', due.length, due.length ? due.map(function (d) { return d.label; }).join(', ') : 'every register is marked');
            if (ex.length) ib('ibExamT', ex[0].subject + ' · ' + ex[0].on, ex[0].class + (ex[0].days === 0 ? ' · today' : ' · in ' + plural(ex[0].days, 'day')));
            else ib('ibExamT', 'None scheduled', 'no upcoming exam for your classes');
            ib('ibUnread', r.unread || 0, r.unread ? 'open the bell to read them' : 'you are up to date');

            if (today.length) { el('ttToday').innerHTML = today.map(function (x) { return ttRow(x, x.label); }).join(''); show('ttToday', true); }
            else emptyBox('ttEmpty', 'fa-mug-hot', 'No periods today', 'Nothing on the timetable for you today.');

            if (due.length) {
                el('attDue').innerHTML = due.map(function (d) {
                    return '<div class="tt-row"><span class="tt-p"><i class="fas fa-user-check"></i></span><b>' + esc(d.label) + '</b>' +
                           '<em><a class="btn btn-primary btn-sm" href="attendance.php"><i class="fas fa-pen"></i> Mark now</a></em></div>';
                }).join('');
                show('attDue', true);
            } else emptyBox('attDueEmpty', 'fa-circle-check', 'All marked', 'Every register of your sections is done for today.');

            if (ex.length) {
                el('exBody').innerHTML = ex.map(function (e) {
                    return '<tr><td><b>' + esc(e.on) + '</b>' + (e.time ? ' <small class="text-muted">' + esc(e.time) + '</small>' : '') + '</td>' +
                           '<td>' + esc(e.subject) + '</td><td>' + esc(e.class) + '</td><td>' + esc(e.room || '—') + '</td></tr>';
                }).join('');
                show('exWrap', true);
            } else emptyBox('exEmpty', 'fa-calendar-check', 'No upcoming exams', 'The date sheet has nothing scheduled for your classes.');

            if (r.cards.length) {
                el('asgGrid').innerHTML = r.cards.map(function (c) {
                    var st = state(c.pct, c.published);
                    return '<div class="assign-card">' +
                        '<div class="assign-card-head"><span><i class="fas fa-chalkboard"></i> ' + esc(c.label) + '</span>' +
                        '<span class="subject-chip"><i class="fas fa-book"></i> ' + esc(c.subject) + '</span></div>' +
                        '<div class="assign-card-meta">' +
                        '<span><i class="fas fa-users"></i>' + c.students + ' students</span>' +
                        '<span><i class="fas fa-pen"></i>' + c.entered + ' / ' + c.expected + ' entered</span>' +
                        (c.published ? '<span><i class="fas fa-lock"></i>Published</span>' : '') +
                        '</div>' + bar(c.pct, st) +
                        '<div class="assign-card-actions">' +
                        '<a class="btn btn-primary" href="marks_entry.php"><i class="fas fa-pen-to-square"></i> Enter Marks</a>' +
                        '</div></div>';
                }).join('');
                show('asgGrid', true);
            } else {
                emptyBox('asgEmpty', 'fa-clipboard-question', 'Nothing assigned yet',
                         'No class-section-subject is assigned to you for this academic year.');
            }

            if (r.published.length) {
                el('tpubBody').innerHTML = r.published.map(function (p) {
                    return '<tr><td>' + esc(p.label) + '</td><td>' + esc(p.term) + '</td>' +
                           '<td>' + p.total + '</td><td>' + p.passed + '</td><td>' + esc(p.on) + '</td></tr>';
                }).join('');
                show('tpubWrap', true);
            } else {
                emptyBox('tpubEmpty', 'fa-bullhorn', 'No published results yet',
                         'Once an admin publishes one of your sections it is listed here.');
            }
        }).fail(function () {
            show('kpiSkeleton', false); show('asgSkeleton', false); show('tpubSkeleton', false);
            emptyBox('asgEmpty', 'fa-triangle-exclamation', 'Connection error', 'Please refresh the page and try again.');
        });
    }

    // -------------------------------------------------------------- student
    function loadStudent() {
        ORMS.post('studentStats', {}).done(function (r) {
            show('kpiSkeleton', false);
            show('trendSkeleton', false);
            show('myPubSkeleton', false);
            setTxt('ttDayS', DAYS[new Date().getDay() || 7]);

            if (!r || !r.success) {
                emptyBox('trendEmpty', 'fa-triangle-exclamation', 'Could not load your results',
                         (r && r.message) || 'Please refresh the page and try again.');
                return;
            }

            // the day strip renders even before the first result is published
            if (r.att) ib('ibAttS', r.att.n > 0 ? r.att.pct + '%' : 'No register yet', r.att.n > 0 ? r.att.p + ' present · ' + r.att.a + ' absent · ' + r.att.l + ' late · ' + r.att.lv + ' leave' : 'nothing marked for you this year', r.att.n > 0 ? r.att.pct : 0);
            else ibHide('ibAttS');
            if (r.fee) { ib('ibFeeS', r.fee.owes ? r.fee.balance_f : 'Clear', r.fee.hold ? 'result card on hold — contact the office' : (r.fee.owes ? 'due — please pay at the office' : (r.fee.balance < 0 ? r.fee.balance_f + ' in credit' : 'nothing outstanding')));
                       var fb = el('ibFeeS').closest('.info-box').querySelector('.info-box-icon'); if (fb) fb.className = 'info-box-icon ' + (r.fee.owes || r.fee.hold ? 'bg-danger' : 'bg-success'); }
            else ibHide('ibFeeS');
            if (r.next_exam) ib('ibExamS', r.next_exam.subject + ' · ' + r.next_exam.on, (r.next_exam.time ? r.next_exam.time + ' · ' : '') + (r.next_exam.room ? r.next_exam.room + ' · ' : '') + (r.next_exam.days === 0 ? 'today' : 'in ' + plural(r.next_exam.days, 'day')));
            else ib('ibExamS', 'None scheduled', 'no upcoming exam on the date sheet');
            ib('ibUnreadS', r.unread || 0, r.unread ? 'open the bell to read them' : 'you are up to date');
            var td = r.today || [];
            if (td.length) { el('ttTodayS').innerHTML = td.map(function (x) { return ttRow(x, x.teacher); }).join(''); show('ttTodayS', true); }
            else emptyBox('ttEmptyS', 'fa-mug-hot', 'No periods today', 'Nothing on your section\'s timetable today.');

            if (!r.rows.length) {
                show('kpiGrid', true);
                ['skPercent', 'skGrade', 'skPosition', 'skSubjects'].forEach(function (id) { setTxt(id, '—'); });
                cap('skPercent', 'no result published yet');
                emptyBox('trendEmpty', 'fa-hourglass-half', 'No results published yet',
                         'Your result card appears here as soon as your class result is published. You will also get a notification.');
                emptyBox('myPubEmpty', 'fa-file-circle-question', 'Nothing to show',
                         r.linked ? 'No published term result on your record yet.'
                                  : 'Your login is not linked to a student profile yet. Please contact the office.');
                return;
            }

            var L = r.latest;
            setTxt('skPercent', L.percent.toFixed(2) + '%'); cap('skPercent', L.term ? L.term + ' · ' + L.year : '');
            setTxt('skGrade', L.grade); cap('skGrade', L.status === 'PASS' ? 'Passed' : 'Failed');
            setTxt('skPosition', L.position); cap('skPosition', 'in your section');
            setTxt('skSubjects', L.subjects); cap('skSubjects', L.obtained + ' / ' + L.max + ' marks');
            show('kpiGrid', true);

            // one point is not a trend — show the reading instead of a flat line
            if (r.rows.length >= 2) {
                show('trendWrap', true);
                draw('chartTrend', {
                    type: 'line',
                    data: { labels: r.rows.map(function (x) { return x.term; }),
                            datasets: [{ label: 'Percentage', data: r.rows.map(function (x) { return x.percent; }),
                                         borderColor: cssVar('--navy-accent', '#0074D9'),
                                         backgroundColor: fade(cssVar('--navy-accent', '#0074D9'), 0.12),
                                         fill: true, tension: 0.32, pointRadius: 5, pointHoverRadius: 7,
                                         pointBackgroundColor: cssVar('--navy-accent', '#0074D9') }] },
                    options: { responsive: true, maintainAspectRatio: false, scales: pctAxis(),
                               interaction: { mode: 'index', intersect: false },
                               plugins: { legend: { display: false },
                                          tooltip: { callbacks: { label: function (c) {
                                              var x = r.rows[c.dataIndex];
                                              return x.percent + '%  ·  Grade ' + x.grade + '  ·  ' + x.status; } } } } }
                });
            } else {
                emptyBox('trendEmpty', 'fa-chart-line', 'One result so far',
                         'You scored ' + L.percent.toFixed(2) + '% in ' + L.term + '. The trend chart appears once a second term is published.');
            }

            el('myPubBody').innerHTML = r.rows.slice().reverse().map(function (x) {
                var badge = x.status === 'PASS' ? 'rc-badge-pass' : 'rc-badge-fail';
                return '<tr><td>' + esc(x.term) + '</td><td>' + esc(x.year) + '</td>' +
                       '<td><strong>' + x.percent.toFixed(2) + '%</strong></td>' +
                       '<td>' + esc(x.grade) + '</td>' +
                       '<td><span class="rc-badge ' + badge + '">' + esc(x.status) + '</span></td></tr>';
            }).join('');
            show('myPubWrap', true);
        }).fail(function () {
            show('kpiSkeleton', false); show('trendSkeleton', false); show('myPubSkeleton', false);
            emptyBox('trendEmpty', 'fa-triangle-exclamation', 'Connection error', 'Please refresh the page and try again.');
        });
    }

    // ------------------------------------------------------------- activity
    function ago(ts) {
        var d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d)) return String(ts || '');
        var s = (Date.now() - d.getTime()) / 1000;
        if (s < 90) return 'Just now';
        if (s < 3600) return Math.round(s / 60) + ' min ago';
        if (s < 86400) { var h = Math.round(s / 3600); return h + (h === 1 ? ' hour ago' : ' hours ago'); }
        if (s < 604800) { var y = Math.round(s / 86400); return y + (y === 1 ? ' day ago' : ' days ago'); }
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    // icon + tint picked off the action word, so the trail scans at a glance
    function actIcon(a) {
        var s = String(a || '').toLowerCase();
        if (/publish/.test(s))             return ['fa-bullhorn', 'dai-5'];
        if (/mark|grade|result/.test(s))   return ['fa-pen-to-square', 'dai-1'];
        if (/student/.test(s))             return ['fa-user-graduate', 'dai-2'];
        if (/teacher|staff/.test(s))       return ['fa-chalkboard-user', 'dai-3'];
        if (/delete|remove/.test(s))       return ['fa-trash', 'dai-6'];
        if (/login|logout|session/.test(s)) return ['fa-right-to-bracket', 'dai-4'];
        if (/fee|payment|invoice/.test(s)) return ['fa-money-bill', 'dai-4'];
        return ['fa-circle-info', 'dai-4'];
    }

    function loadActivity() {
        ORMS.post('recentActivity', {}).done(function (r) {
            show('actSkeleton', false);
            if (!r || !r.success || !r.data.length) {
                emptyBox('actEmpty', 'fa-inbox', 'No recent activity', 'Actions you take in the system are logged here.');
                return;
            }
            el('actList').innerHTML = r.data.slice(0, 6).map(function (g) {
                var ic = actIcon(g.action + ' ' + (g.details || ''));
                var who = IS_WIDE && g.username ? esc(g.username) + ' · ' : '';
                return '<div class="da-row">' +
                       '<span class="da-ic ' + ic[1] + '"><i class="fas ' + ic[0] + '"></i></span>' +
                       '<div class="da-txt"><b>' + esc(g.details || g.action) + '</b>' +
                       '<small>' + who + esc(g.action) + '</small></div>' +
                       '<span class="da-time">' + esc(ago(g.timestamp)) + '</span></div>';
            }).join('');
            show('actList', true);
        }).fail(function () {
            show('actSkeleton', false);
            emptyBox('actEmpty', 'fa-triangle-exclamation', 'Could not load activity', 'Please refresh the page and try again.');
        });
    }

    // ==================== app owner: the whole platform ====================
    function pfItem(label, icon, val) {
        return '<div class="stu360-item"><div class="lbl">' + label + '</div><div class="val">' +
               '<i class="fas ' + icon + '"></i>' + (val === null || val === undefined || val === '' ?
               '<span class="text-muted">&mdash;</span>' : val) + '</div></div>';
    }
    function pfWhen(v) { return v ? ORMS.esc(String(v).slice(0, 16).replace('T', ' ')) : '<span class="text-muted">never</span>'; }

    function loadPlatform(btn) {
        ORMS.post('platformStats', {}, { btn: btn, busyLabel: 'Loading…' }).done(function (d) {
            show('pfSkeleton', false);
            if (!d.success) { ORMS.err(d.message || 'Could not load the platform overview'); return; }

            // ---- the switches. Amber, not red: none of these is broken, they are choices — but an
            // operator should never have to open four pages to find out what is actually live.
            var bad = 0;
            $('#pfFlags').html((d.flags || []).map(function (f) {
                if (!f[0]) bad++;
                return '<div class="health-row ' + (f[0] ? 'health-ok' : 'health-bad') + '">' +
                       '<i class="fas ' + (f[0] ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i>' +
                       '<span class="health-label">' + ORMS.esc(f[1]) + '</span>' +
                       '<span class="health-why">' + ORMS.esc(f[2]) + '</span>' +
                       (f[0] ? '' : (f[5]
                            ? '<a class="btn btn-secondary btn-sm" href="' + ORMS.esc(f[3]) + '"><i class="fas fa-wrench"></i> Open</a>'
                            : '<span class="health-where"><i class="fas fa-lock"></i> ' + ORMS.esc(f[3]) + '</span>')) +
                       '</div>';
            }).join(''));
            setTxt('pfFlagsSub', ((d.flags || []).length - bad) + ' of ' + (d.flags || []).length + ' on');

            // ---- tenants
            var t = d.totals || {}, st = d.by_status || {};
            var mix = Object.keys(st).map(function (k) { return st[k] + ' ' + k.toLowerCase(); }).join(' · ');
            setTxt('pfSchools', t.schools || 0);  cap('pfSchools', mix || '');
            setTxt('pfStudents', t.students || 0); cap('pfStudents', plural(t.new30 || 0, 'new school') + ' in 30 days');
            setTxt('pfTeachers', t.teachers || 0); cap('pfTeachers', plural(t.branches || 0, 'branch', 'branches'));
            setTxt('pfUsers', t.users || 0);       cap('pfUsers', 'across every school');
            show('pfKpi', true);

            // ---- money, only when the billing tables are actually installed
            var m = d.money || {}, w = d.watch || {};
            if (m.billing) {
                setTxt('pfMonth', m.currency + ' ' + ORMS.money(m.month));      cap('pfMonth', 'lifetime ' + m.currency + ' ' + ORMS.money(m.lifetime));
                setTxt('pfPending', m.currency + ' ' + ORMS.money(m.pend_amt)); cap('pfPending', plural(m.pend_n || 0, 'pending invoice'));
                setTxt('pfOk', w.ok || 0);     cap('pfOk', 'inside their paid period');
                setTxt('pfSoon', w.soon || 0); cap('pfSoon', 'renewal conversations due');
                show('pfMoney', true);
            } else show('pfMoney', false);

            // ---- six months of collections
            var rv = d.rev6 || { labels: [], data: [] };
            setTxt('pfCcy', m.currency || '');
            if (rv.data.some(function (x) { return x > 0; })) {
                show('pfRevWrap', true); show('pfRevEmpty', false);
                draw('chartRev', {
                    type: 'bar',
                    data: { labels: rv.labels, datasets: [{ label: 'Collected', data: rv.data,
                            backgroundColor: cssVar('--success', '#34a853'), borderRadius: 0, maxBarThickness: 46 }] },
                    options: { responsive: true, maintainAspectRatio: false,
                               scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return m.currency + ' ' + ORMS.money(v, 0); } } }, x: { grid: { display: false } } },
                               plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return m.currency + ' ' + ORMS.money(c.parsed.y); } } } } }
                });
            } else { show('pfRevWrap', false); emptyBox('pfRevEmpty', 'fa-sack-dollar', 'No collections yet', 'Subscription payments land here month by month once schools start paying.'); }

            // ---- the plan mix
            var bp = d.by_plan || [];
            if (bp.length) {
                show('pfPlanWrap', true); show('pfPlanEmpty', false);
                var tot = bp.reduce(function (a, g) { return a + g.n; }, 0) || 1;
                draw('chartPlan', {
                    type: 'doughnut',
                    data: { labels: bp.map(function (g) { return g.label; }),
                            datasets: [{ data: bp.map(function (g) { return g.n; }), borderWidth: 0,
                                         backgroundColor: bp.map(function (_, i) { return GRAD[i % GRAD.length]; }) }] },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
                               plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.label + ': ' + plural(c.parsed, 'school'); } } } } },
                    plugins: [arcPct]
                });
                el('planLegend').innerHTML = bp.map(function (g, i) {
                    return '<div class="dl-row"><span class="dl-dot" style="background:' + GRAD[i % GRAD.length] + '"></span>' +
                           '<span class="dl-name">' + esc(g.label) + ' <em>(' + Math.round(g.n / tot * 100) + '%)</em></span>' +
                           '<span class="dl-n">' + plural(g.n, 'School') + '</span></div>';
                }).join('');
            } else { show('pfPlanWrap', false); emptyBox('pfPlanEmpty', 'fa-layer-group', 'No schools yet', 'Add a school and its plan shows up here.'); }

            // ---- who needs a conversation, worst first
            ib('pfWOk', w.ok || 0, 'inside their paid period');
            ib('pfWSoon', w.soon || 0, 'renewal conversations due');
            ib('pfWExpired', w.expired || 0, 'suspended, cancelled or lapsed');
            ib('pfWNever', w.never || 0, 'no period on record yet');

            var need = d.need || [];
            $('#pfNeed').html(need.length
                ? '<thead><tr><th>School</th><th>Status</th><th>Plan</th><th>Ends</th><th>Size</th></tr></thead><tbody>' +
                  need.map(function (r) {
                      var tn = r.bucket === 'expired' ? 'val-neg' : (r.bucket === 'soon' ? 'val-amount' : 'val-muted');
                      var when = r.ends_at
                          ? ORMS.esc(String(r.ends_at).slice(0, 10)) + ' <span class="' + tn + '">(' +
                            (r.days === null ? '' : (r.days < 0 ? Math.abs(r.days) + 'd ago' : r.days + 'd left')) + ')</span>'
                          : '<span class="val-muted">never billed</span>';
                      return '<tr><td><b>' + ORMS.esc(r.name) + '</b><span class="tnt-sub">' + ORMS.esc(r.code) + '</span></td>' +
                             '<td><span class="' + tn + '">' + ORMS.esc(r.status) + '</span></td>' +
                             '<td>' + (r.plan_name ? ORMS.esc(r.plan_name) : '<span class="text-muted">&mdash;</span>') + '</td>' +
                             '<td>' + when + '</td>' +
                             '<td>' + r.students + ' students · ' + r.teachers + ' teachers · ' + r.branches + ' branches</td></tr>';
                  }).join('') + '</tbody>'
                : '<tbody><tr><td class="text-muted"><i class="fas fa-circle-check"></i> Every school is inside its subscription. Nothing needs you right now.</td></tr></tbody>');

            // ---- the box it runs on
            var s = d.system || {};
            $('#pfSystem').html(
                pfItem('PHP', 'fa-code', ORMS.esc(s.php)) +
                pfItem('Database', 'fa-database', ORMS.esc(s.db) + ' · ' + s.tables + ' tables') +
                pfItem('Database size', 'fa-hard-drive', s.db_mb + ' MB') +
                pfItem('Uploads', 'fa-folder-open', s.uploads_mb === null ? null : s.uploads_mb + ' MB') +
                pfItem('Error log', 'fa-bug', (s.error_kb || 0) + ' KB') +
                pfItem('Last backup', 'fa-download', pfWhen(s.backup)) +
                pfItem('Last expiry sweep', 'fa-hourglass-half', pfWhen(s.sweep)) +
                pfItem('Logins (24h)', 'fa-right-to-bracket', String(s.logins24 || 0)) +
                pfItem('Failed sign-ins (24h)', 'fa-shield-halved', String(s.fails24 || 0)));
        }).fail(function (msg) { show('pfSkeleton', false); ORMS.err(msg); });
    }

    function loadAll() {
        if (VIEW === 'platform')                                        loadPlatform();
        else if (VIEW === 'admin' || VIEW === 'owner' || VIEW === 'branch') loadSchool(TERM_ID);
        else if (VIEW === 'principal')                                  loadPrincipal();
        else if (VIEW === 'teacher')                                    loadTeacher();
        else if (VIEW === 'student')                                    loadStudent();
        loadActivity();
    }

    $(document).ready(loadAll);
    </script>

    <?php if (file_exists(__DIR__ . '/welcome_tour.php')) include 'welcome_tour.php'; ?>
</body>
</html>
