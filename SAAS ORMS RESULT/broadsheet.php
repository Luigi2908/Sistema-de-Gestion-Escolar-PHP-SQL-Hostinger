<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';
require_once 'result_engine.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout())       { header("Location: login.php"); exit(); }

// rbac view gate
requirePerm('broadsheet', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'broadsheet';

// school-wide reach — head of school reads every class, same as admin
$isWide = ormsSchoolWide($role);

// column probe, one per request — an install that hasn't taken the migration keeps the old behaviour
function bsHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

function bsTenant(): bool { return bsHasCol('classes', 'school_id'); }

// sections this user may open — ONE shared definition (ormsSectionScope), cached per year.
// a db without school_id has nothing to join the fence on, so it falls back to the plain read
function bsSections(int $yearId): array {
    static $c = [];
    if (isset($c[$yearId])) return $c[$yearId];
    if (bsTenant())      return $c[$yearId] = ormsSectionScope(null, null, $yearId);
    if (ormsSchoolWide()) return $c[$yearId] = array_map('intval', array_column(qAll("SELECT id FROM sections"), 'id'));
    $tid = ormsTeacherId((int)($_SESSION['user_id'] ?? 0));
    return $c[$yearId] = $tid ? array_map('intval', array_column(qAll(
        "SELECT DISTINCT section_id FROM teacher_subjects WHERE teacher_id = ? AND academic_year_id = ?",
        'ii', $tid, $yearId), 'section_id')) : [];
}

// posted id -> verified row id, 0 when another school owns it
function bsOwns(string $table, $raw): int {
    $id = (int)$raw;
    if ($id <= 0) return 0;
    return bsTenant() ? ormsOwns($table, $id) : $id;
}

// the shared year lookups are still school-blind — one id read fences the picker, never a probe per row
function bsYears(): array {
    $all = ormsYears();
    if (!bsTenant() || !$all) return $all;
    try {
        $mine = array_flip(array_map('intval', array_column(qAll("SELECT id FROM academic_years WHERE school_id = ?", 'i', sid()), 'id')));
        return array_values(array_filter($all, fn($y) => isset($mine[(int)$y['id']])));
    } catch (Throwable $e) { return $all; }
}

// current year, but only when it is ours
function bsCurYear(): ?array {
    $c = ormsCurrentYear();
    return ($c && (!bsTenant() || ormsFindYear((int)$c['id']))) ? $c : null;
}

// classes fence for the outermost FROM -> [sql, types, params]
function bsWhere(string $a = 'c'): array {
    if (!bsTenant()) return ['', '', []];
    $b = bsHasCol('classes', 'branch_id') ? bid() : 0;
    return $b ? [" AND $a.school_id = ? AND $a.branch_id = ?", 'ii', [sid(), $b]]
              : [" AND $a.school_id = ?", 'i', [sid()]];
}

// grading set that grades this class — null when the sets migration hasn't run yet
function bsSetId(int $classId): ?int {
    return function_exists('ormsSetForClass') ? (int)ormsSetForClass($classId) : null;
}

// 100.00 -> 100, 87.50 -> 87.5
function bsNum($v): string { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }

// branded A4 header for the print view — school block + what this sheet is
function bsPrintHead(string $classLabel, string $sectionLabel, string $termName): string {
    $b = ormsResultBranding();
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $logo = trim((string)($b['result_logo'] ?? ''));
    $addr = trim((string)($b['result_school_address'] ?? ''));
    $ph   = trim((string)($b['result_school_phone'] ?? ''));

    $h  = '<div class="bs-print-head">';
    if ($logo !== '') $h .= '<img src="' . $e($logo) . '" alt="">';
    $h .= '<h2>' . $e($b['result_school_name'] ?? '') . '</h2>';
    if ($addr !== '' || $ph !== '') {
        $h .= '<p>' . $e($addr) . ($addr !== '' && $ph !== '' ? ' &middot; ' : '') . $e($ph) . '</p>';
    }
    $h .= '<h3>Class Broadsheet &mdash; ' . $e($termName) . '</h3>';
    $h .= '<p>' . $e($classLabel) . ' &middot; ' . $e($sectionLabel) . ' &middot; Printed ' . $e(date('d M Y')) . '</p>';
    return $h . '</div>';
}

// one pass over the built rows: per-subject spread, overall spread, grade distribution.
// absent stays out of avg/high/low but still counts against the pass rate
function bsStats(array $rows, array $subs, array $bands): array {
    $per = [];
    foreach ($subs as $s) {
        $per[(int)$s['subject_id']] = ['subject_id' => (int)$s['subject_id'], 'name' => $s['name'],
                                       'total' => (float)$s['total_marks'], 'counted' => (int)($s['counted'] ?? 1),
                                       'ent' => 0, 'sat' => 0, 'abs' => 0, 'sum' => 0.0, 'hi' => null, 'lo' => null, 'passed' => 0];
    }
    $ov = ['n' => 0, 'sum' => 0.0, 'hi' => null, 'lo' => null, 'hi_who' => '', 'lo_who' => '',
           'pass' => 0, 'fail' => 0, 'withheld' => 0, 'students' => count($rows)];
    $gr = [];

    foreach ($rows as $r) {
        foreach ($r['subjects'] as $s) {
            $sid = (int)$s['subject_id'];
            if (!isset($per[$sid]) || !(int)$s['entered']) continue;
            $per[$sid]['ent']++;
            if ((int)$s['passed']) $per[$sid]['passed']++;
            if ((int)$s['is_absent']) { $per[$sid]['abs']++; continue; }   // absent never drags the average
            $v = (float)$s['obtained'];
            $per[$sid]['sat']++; $per[$sid]['sum'] += $v;
            $per[$sid]['hi'] = $per[$sid]['hi'] === null ? $v : max($per[$sid]['hi'], $v);
            $per[$sid]['lo'] = $per[$sid]['lo'] === null ? $v : min($per[$sid]['lo'], $v);
        }
        if ((int)$r['withheld']) $ov['withheld']++;
        if ((int)$r['entered_count'] <= 0) continue;                       // nothing entered = out of every average
        $ov['n']++; $ov['sum'] += (float)$r['percentage'];
        $t = (float)$r['total_obtained'];
        if ($ov['hi'] === null || $t > $ov['hi']) { $ov['hi'] = $t; $ov['hi_who'] = $r['name']; }
        if ($ov['lo'] === null || $t < $ov['lo']) { $ov['lo'] = $t; $ov['lo_who'] = $r['name']; }
        $r['result_status'] === 'PASS' ? $ov['pass']++ : $ov['fail']++;
        $g = (string)$r['grade'];
        $gr[$g] = ($gr[$g] ?? 0) + 1;
    }

    $subject = array_values(array_map(fn($p) => [
        'subject_id' => $p['subject_id'], 'name' => $p['name'], 'total' => $p['total'], 'counted' => $p['counted'],
        'entered'    => $p['ent'], 'sat' => $p['sat'], 'absent' => $p['abs'],
        'avg'        => $p['sat'] ? round($p['sum'] / $p['sat'], 2) : null,
        'avg_pct'    => ($p['sat'] && $p['total'] > 0) ? round($p['sum'] / $p['sat'] / $p['total'] * 100, 1) : null,
        'hi'         => $p['hi'], 'lo' => $p['lo'], 'passed' => $p['passed'],
        'pass_pct'   => $p['ent'] ? round($p['passed'] / $p['ent'] * 100, 1) : null
    ], $per));

    // every band of the class's set shows, even the empty ones — a spread with holes reads wrong
    $seen   = [];
    $spread = array_map(function ($b) use ($gr, &$seen) {
        $g = (string)$b['grade']; $seen[$g] = 1;
        return ['grade' => $g, 'color' => ormsHex($b['color'] ?? ''), 'fail' => (int)($b['is_fail'] ?? 0),
                'range' => bsNum($b['min_percent']) . '–' . bsNum($b['max_percent']) . '%', 'n' => (int)($gr[$g] ?? 0)];
    }, $bands);
    foreach ($gr as $g => $n) {                                            // grade off the current set (re-pointed class)
        if (!isset($seen[$g])) $spread[] = ['grade' => (string)$g, 'color' => '', 'fail' => 0, 'range' => '', 'n' => (int)$n];
    }

    return [
        'subject' => $subject,
        'overall' => [
            'students' => $ov['students'], 'counted' => $ov['n'],
            'avg_pct'  => $ov['n'] ? round($ov['sum'] / $ov['n'], 2) : null,
            'hi'       => $ov['hi'], 'hi_who' => $ov['hi_who'], 'lo' => $ov['lo'], 'lo_who' => $ov['lo_who'],
            'pass'     => $ov['pass'], 'fail' => $ov['fail'], 'withheld' => $ov['withheld'],
            'pass_pct' => $ov['n'] ? round($ov['pass'] / $ov['n'] * 100, 1) : null
        ],
        'spread' => $spread
    ];
}

// Handle AJAX requests
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'getTerms':
                requireCsrfJson();
                requirePermJson('broadsheet', 'v');
                $yearId = bsOwns('academic_years', $_POST['year_id'] ?? $_GET['year_id'] ?? 0);
                jsonOk(['data' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'status' => $t['status']],
                                            $yearId ? ormsTerms($yearId) : [])]);

            case 'getSections':
                requireCsrfJson();
                requirePermJson('broadsheet', 'v');
                $termId = bsOwns('exam_terms', $_POST['term_id'] ?? $_GET['term_id'] ?? 0);
                $term   = $termId ? ormsTerm($termId) : null;
                if (!$term) jsonErr('Please choose an exam term');
                $yearId = (int)$term['academic_year_id'];

                $scope = bsSections($yearId);
                if (!$scope) jsonOk(['classes' => [], 'sections' => [], 'term' => $term['name']]);

                // one read — the class list is derived from the same rows, never a second query.
                // fence sits on the driver too, not only inside the section list
                [$w, $wt, $wp] = bsWhere('c');
                // the headcount rides along for the section tabs — sections are a handful of rows,
                // so one correlated subquery beats a second round trip per class
                $sql = "SELECT sec.id, sec.class_id, sec.name, c.name AS class_name,
                               (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status = 'Active') AS students
                        FROM sections sec
                        JOIN classes c ON c.id = sec.class_id
                        WHERE sec.is_active = 1 AND c.is_active = 1$w
                          AND sec.id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
                $types  = $wt . str_repeat('i', count($scope));
                $params = array_merge($wp, $scope);
                $sql .= " ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC";

                $rows = qAll($sql, $types, ...$params);
                $classes = [];
                $sections = array_map(function ($r) use (&$classes) {
                    $cid = (int)$r['class_id'];
                    $classes[$cid] ??= ['id' => $cid, 'name' => $r['class_name']];
                    return ['id' => (int)$r['id'], 'class_id' => $cid, 'name' => $r['name'], 'students' => (int)$r['students']];
                }, $rows);

                jsonOk(['classes' => array_values($classes), 'sections' => $sections, 'term' => $term['name']]);

            case 'getBroadsheet':
                requireCsrfJson();
                requirePermJson('broadsheet', 'v');
                $termId    = bsOwns('exam_terms', $_POST['term_id'] ?? $_GET['term_id'] ?? 0);
                $classId   = bsOwns('classes',    $_POST['class_id'] ?? $_GET['class_id'] ?? 0);
                $sectionId = bsOwns('sections',   $_POST['section_id'] ?? $_GET['section_id'] ?? 0);   // 0 = whole class
                $term      = $termId ? ormsTerm($termId) : null;
                if (!$term) jsonErr('Please choose an exam term');
                $yearId = (int)$term['academic_year_id'];

                if ($sectionId > 0) {
                    if (!in_array($sectionId, bsSections($yearId), true)) jsonErr('You are not assigned to this section');
                    $sec = qOne("SELECT sec.id, sec.name, sec.class_id, c.name AS class_name
                                 FROM sections sec JOIN classes c ON c.id = sec.class_id
                                 WHERE sec.id = ? AND sec.is_active = 1", 'i', $sectionId);
                    if (!$sec) jsonErr('Section not found');
                    $classId   = (int)$sec['class_id'];
                    $className = $sec['class_name'];
                    $secRows   = [['id' => $sectionId, 'name' => $sec['name']]];
                } else {
                    $cls = $classId ? qOne("SELECT id, name FROM classes WHERE id = ? AND is_active = 1", 'i', $classId) : null;
                    if (!$cls) jsonErr('Please choose a class');
                    $className = $cls['name'];
                    $scope     = bsSections($yearId);
                    $secRows   = qAll("SELECT id, name FROM sections WHERE class_id = ? AND is_active = 1 ORDER BY name ASC", 'i', $classId);
                    $secRows   = array_values(array_filter($secRows, fn($s) => in_array((int)$s['id'], $scope, true)));
                }

                // one section in reach = a plain sheet, no section column and no "rank in section" wording
                $multi     = ($sectionId > 0 || count($secRows) <= 1) ? 0 : 1;
                $secLabel  = count($secRows) === 1 ? ('Section ' . $secRows[0]['name']) : 'All sections';
                $branding  = ormsResultBranding();
                $showGrade = ormsCardOn($branding, 'result_show_grade_col');
                $setId     = bsSetId($classId);
                $hasComp   = function_exists('ormsSchemeForClass') ? (ormsSchemeForClass($classId) !== null) : false;

                if (!$secRows) {
                    jsonOk(['rows' => [], 'subjects' => [], 'multi' => $multi, 'class_name' => $className,
                            'section_label' => $secLabel, 'term' => $term['name'], 'show_grade' => $showGrade ? 1 : 0,
                            'has_comp' => 0, 'legend' => '', 'stats' => null,
                            'print_head' => bsPrintHead($className, $secLabel, $term['name'])]);
                }

                // subjects come off the class, so one lookup covers every section of it
                $subsRaw = ormsSectionSubjects((int)$secRows[0]['id']);
                $subs    = array_map(fn($s) => ['id' => (int)$s['subject_id'], 'name' => $s['name'], 'code' => $s['code'],
                                                'total' => (float)$s['total_marks'], 'counted' => (int)$s['counted']], $subsRaw);

                // one engine pass per section — positions stay section ranks, never re-ranked across the class
                $built = [];
                foreach ($secRows as $s) {
                    $sid = (int)$s['id'];
                    $att = function_exists('ormsAttendanceMap') ? ormsAttendanceMap($sid, $termId) : [];
                    foreach (ormsBuildSectionResults($termId, $sid) as $r) {
                        $r['section_id']   = $sid;
                        $r['section_name'] = $s['name'];
                        $r['attendance'] ??= ($att[(int)$r['student_id']] ?? null);   // pre-migration fallback, still one map per section
                        $r['withheld']        = (int)($r['withheld'] ?? 0);
                        $r['withheld_reason'] = (string)($r['withheld_reason'] ?? '');
                        $built[] = $r;
                    }
                }

                $stats = bsStats($built, $subsRaw, ormsBands($setId));

                // wire shape — subject rows collapse to an o(1) cell map keyed by subject id
                $rows = array_map(function ($r) {
                    $cells = [];
                    foreach ($r['subjects'] as $s) {
                        $cells[(int)$s['subject_id']] = [
                            'v'  => (float)$s['obtained'], 'ab' => (int)$s['is_absent'], 'e' => (int)$s['entered'],
                            'g'  => (string)$s['grade'],   'p'  => (int)$s['passed'],    'c' => (int)$s['counted'],
                            't'  => (float)$s['total'],
                            'ca' => isset($s['ca']) && $s['ca'] !== null ? (float)$s['ca'] : null,
                            'ex' => isset($s['exam']) && $s['exam'] !== null ? (float)$s['exam'] : null
                        ];
                    }
                    $a    = $r['attendance'] ?? null;
                    $pres = $a ? (float)($a['present'] ?? 0) : 0.0;
                    $tot  = $a ? (float)($a['total'] ?? 0) : 0.0;
                    return [
                        'student_id'   => (int)$r['student_id'],
                        'roll_no'      => (string)$r['roll_no'],
                        'name'         => (string)$r['name'],
                        'section_id'   => (int)$r['section_id'],
                        'section_name' => (string)$r['section_name'],
                        'cells'        => $cells,
                        'entered'      => (int)$r['entered_count'],
                        'total_obtained' => (float)$r['total_obtained'],
                        'total_max'    => (float)$r['total_max'],
                        'percentage'   => (float)$r['percentage'],
                        'gpa'          => (float)$r['gpa'],
                        'grade'        => (string)$r['grade'],
                        'position'     => $r['position'] === null ? null : (int)$r['position'],
                        'result_status'=> (string)$r['result_status'],
                        'failed'       => (string)$r['failed_subjects'],
                        // nothing recorded prints nothing — never "0 / 0"
                        'att'          => $tot > 0 ? ['p' => $pres, 't' => $tot, 'pct' => round($pres / $tot * 100, 1)] : null,
                        'withheld'     => (int)$r['withheld'],
                        'withheld_reason' => (string)$r['withheld_reason']
                    ];
                }, $built);

                jsonOk([
                    'rows' => $rows, 'subjects' => $subs, 'stats' => $stats,
                    'multi' => $multi, 'class_name' => $className, 'section_label' => $secLabel,
                    'sections' => count($secRows), 'term' => $term['name'],
                    'show_grade' => $showGrade ? 1 : 0, 'has_comp' => $hasComp ? 1 : 0,
                    'legend' => ormsKeyToGrades($setId),
                    'key_note' => (string)getSetting('result_grade_key_note', ''),
                    'print_head' => bsPrintHead($className, $secLabel, $term['name'])
                ]);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('Broadsheet.php error: ' . $e->getMessage());
        jsonErr('Could not complete that request');     // real reason stays in the log, never on the wire
    }
}

$years   = bsYears();
$curYear = bsCurYear();
$yearId  = $curYear ? (int)$curYear['id'] : ($years ? (int)$years[0]['id'] : 0);
$terms   = $yearId ? ormsTerms($yearId) : [];
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
    <title>Broadsheet - Online Result Management</title>

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
                    <h1><i class="fas fa-table-cells"></i> Broadsheet</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Broadsheet</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header no-print">
                    <h2><i class="fas fa-table-cells"></i> Class Summary <span class="myr-sub" id="bsLabel"></span></h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-secondary" onclick="loadSheet()"><i class="fas fa-sync"></i> Refresh</button>
                        <button type="button" class="btn btn-primary initially-hidden" id="bsPrintBtn" onclick="printSheet()"><i class="fas fa-print"></i> Print Broadsheet</button>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24 no-print">
                    <i class="fas fa-circle-info"></i>
                    <span>Every student of the class on one sheet &mdash; marks per subject, totals, grade, position, attendance and result. <b>Position is a rank inside the section</b>, so a whole-class sheet lists each section&rsquo;s own 1st, 2nd, 3rd. Averages, highest and lowest ignore absentees; the pass rate counts them as a fail.</span>
                </div>

                <div class="filters-section no-print">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                            <select id="bsYear" class="filter-input">
                                <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo (int)$y['id'] === $yearId ? 'selected' : ''; ?>><?php echo htmlspecialchars($y['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-file-pen"></i> Exam Term</label>
                            <select id="bsTerm" class="filter-input">
                                <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name'] . ' (' . $t['status'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-graduation-cap"></i> Class</label>
                            <select id="bsClass" class="filter-input"></select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-users-rectangle"></i> Section</label>
                            <select id="bsSection" class="filter-input"></select>
                        </div>
                        <div class="filter-group initially-hidden" id="bsCompGroup">
                            <label><i class="fas fa-layer-group"></i> Assessment Split</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="bsComp" class="toggle-input" value="1">
                                <label for="bsComp" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-circle-info"></i> Expand each subject into CA / Exam / Total.</div>
                        </div>
                    </div>
                </div>

                <!-- one tab per section of the chosen class; hidden when the class has only one -->
                <div class="tab-nav no-print initially-hidden" id="bsSecTabs" role="tablist"></div>

                <div id="bsSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="bsEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-table-cells"></i>
                    <h4>Nothing to show</h4>
                    <p>Pick a term, class and section above<?php echo $isWide ? '' : ', or ask the admin to assign you a section'; ?>.</p>
                </div>

                <div id="bsWrap" class="bs-wrap initially-hidden">
                    <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all subjects</div>
                    <div class="table-responsive">
                        <table id="bsTable" class="display table-full-width bs-table"></table>
                    </div>
                </div>

                <div id="bsFoot" class="bs-foot initially-hidden"></div>

                <div id="bsLegendWrap" class="bs-legend initially-hidden"></div>
            </div>
        </div>
    </div>

    <!-- a4 landscape print target: hidden on screen, the only thing that prints -->
    <div id="bsPrintArea" class="print-only bs-print"></div>

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
    var IS_WIDE = <?php echo $isWide ? 'true' : 'false'; ?>;
    var SECTIONS = [], DATA = null, bsTable = null;

    // pdf/excel libs pulled only when an export is clicked
    function loadExportDeps(callback) {
        if (window.pdfMake) { callback(); return; }
        var urls = ['https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
                    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
                    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'];
        var loaded = 0;
        (function loadNext() {
            if (loaded >= urls.length) { callback(); return; }
            var s = document.createElement('script');
            s.src = urls[loaded];
            s.onload = function () { loaded++; loadNext(); };
            document.head.appendChild(s);
        })();
    }

    function termId()    { return parseInt($('#bsTerm').val() || 0, 10); }
    function classId()   { return parseInt($('#bsClass').val() || 0, 10); }
    function sectionId() { return parseInt($('#bsSection').val() || 0, 10); }
    function compOn()    { return !!(DATA && DATA.has_comp && $('#bsComp').is(':checked')); }

    // 100.00 -> 100, 87.50 -> 87.5
    function num(v) {
        if (v === null || v === undefined || v === '') return '';
        var n = Math.round(parseFloat(v) * 100) / 100;
        return isNaN(n) ? '' : String(n);
    }
    function dash() { return '<span class="text-muted">&mdash;</span>'; }

    $(document).ready(function () {
        ORMS.dropdown('#bsYear');
        ORMS.dropdown('#bsTerm');
        ORMS.dropdown('#bsClass');
        ORMS.dropdown('#bsSection');
        $('#bsYear').on('change', loadTerms);
        $('#bsTerm').on('change', loadFilters);
        $('#bsClass').on('change', function () { fillSections(); loadSheet(); });
        $('#bsSection').on('change', function () { paintSectionTabs(); loadSheet(); });
        // tabs and the Section dropdown are the same choice — whichever is used, both stay in step
        ORMS.sectionTabs.bind('#bsSecTabs', '#bsSection', function () { loadSheet(); });
        $('#bsComp').on('change', render);
        loadFilters();
    });

    function loadTerms() {
        ORMS.post('getTerms', { year_id: $('#bsYear').val() }).done(function (res) {
            var $t = $('#bsTerm').empty();
            (res.data || []).forEach(function (t) {
                $t.append($('<option>').val(t.id).text(t.name + ' (' + t.status + ')'));
            });
            ORMS.dropdown.refresh('#bsTerm');
            loadFilters();
        }).fail(function (msg) { ORMS.err(msg); });
    }

    // classes + sections in one read; the class change then filters in memory, no round trip
    function loadFilters() {
        if (!termId()) { showEmpty('Create an exam term in Result Settings first.'); return; }
        ORMS.post('getSections', { term_id: termId() }).done(function (res) {
            if (!res.success) { showEmpty(res.message || 'Could not load classes'); return; }
            SECTIONS = res.sections || [];
            var keep = classId(), $c = $('#bsClass').empty();
            (res.classes || []).forEach(function (c) { $c.append($('<option>').val(c.id).text(c.name)); });
            if (keep && $c.find('option[value="' + keep + '"]').length) $c.val(keep);
            ORMS.dropdown.refresh('#bsClass');
            fillSections();
            if (!classId()) { showEmpty(IS_WIDE ? 'No active classes found.' : 'You are not assigned to any section this year.'); return; }
            loadSheet();
        }).fail(function (msg) { showEmpty('Could not reach the server.'); ORMS.err(msg); });
    }

    function fillSections() {
        var cid = classId(), keep = sectionId(), $s = $('#bsSection').empty();
        $s.append($('<option>').val(0).text('All sections'));
        SECTIONS.filter(function (x) { return x.class_id === cid; })
                .forEach(function (x) { $s.append($('<option>').val(x.id).text('Section ' + x.name)); });
        if (keep && $s.find('option[value="' + keep + '"]').length) $s.val(keep);
        ORMS.dropdown.refresh('#bsSection');
        paintSectionTabs();
    }

    // Section tabs. Switching REFETCHES that section instead of hiding rows, because position is a
    // rank inside the section and the averages, highest/lowest and pass rate under the sheet are the
    // selection's — a tab that only filtered rows would leave whole-class statistics sitting under a
    // single section's marks. One tab per section of this class, plus "All sections".
    function paintSectionTabs() {
        var cid = classId();
        ORMS.sectionTabs('#bsSecTabs', '#bsSection',
            SECTIONS.filter(function (x) { return x.class_id === cid; })
                    .map(function (x) { return { id: x.id, name: 'Section ' + x.name, students: x.students }; }),
            { all: 'All sections', allValue: 0 });
    }

    function loadSheet() {
        if (!termId() || !classId()) { showEmpty('Pick a term and a class to build the sheet.'); return; }
        $('#bsSkeleton').show();
        $('#bsWrap, #bsFoot, #bsLegendWrap, #bsEmpty, #bsPrintBtn').addClass('initially-hidden').hide();

        ORMS.post('getBroadsheet', { term_id: termId(), class_id: classId(), section_id: sectionId() })
            .done(function (res) {
                if (!res.success) { showEmpty(res.message || 'Could not build the broadsheet'); ORMS.err(res.message || 'Could not build the broadsheet'); return; }
                DATA = res;
                $('#bsLabel').text('— ' + res.class_name + ' · ' + res.section_label + ' · ' + res.term);
                $('#bsCompGroup').toggleClass('initially-hidden', !res.has_comp).toggle(!!res.has_comp);
                if (!res.has_comp) $('#bsComp').prop('checked', false);
                if (!(res.rows || []).length) { showEmpty('No active students in this class for the selected term.'); return; }
                render();
            })
            .fail(function (msg) { showEmpty('Could not reach the server.'); ORMS.err(msg); });
    }

    function showEmpty(msg) {
        DATA = null;
        if (bsTable) { bsTable.destroy(); $('#bsTable').empty(); bsTable = null; }
        $('#bsSkeleton').hide();
        $('#bsWrap, #bsFoot, #bsLegendWrap, #bsPrintBtn').addClass('initially-hidden').hide();
        $('#bsLabel').text('');
        $('#bsEmpty p').text(msg);
        $('#bsEmpty').removeClass('initially-hidden').show();
    }

    // ---- column model: one definition drives the datatable AND the print sheet ----
    function colDefs() {
        var C = [];
        var add = function (title, cls, disp, raw, cell) {
            C.push({ title: title, cls: cls || '', disp: disp, raw: raw || disp, cell: cell || null });
        };

        add('Roll', '', function (r) { return ORMS.esc(r.roll_no || '—'); }, function (r) { return r.roll_no || ''; });
        add('Student', '', function (r) {
            var h = '<strong>' + ORMS.esc(r.name) + '</strong>';
            if (r.withheld) h += ' <span class="rc-withheld" title="' + ORMS.esc(r.withheld_reason || 'Result withheld') +
                                 '"><i class="fas fa-hand"></i> Withheld</span>';
            return h;
        }, function (r) { return r.name; });
        if (DATA.multi) add('Section', '', function (r) { return ORMS.esc(r.section_name); }, function (r) { return r.section_name; });

        var expand = compOn();
        DATA.subjects.forEach(function (s) {
            if (expand) {
                add(s.name + ' CA',    'bs-sub', part(s, 'ca'), rawPart(s, 'ca'), tint(s));
                add(s.name + ' Exam',  'bs-sub', part(s, 'ex'), rawPart(s, 'ex'), tint(s));
                add(s.name + ' Total', 'bs-tot', mark(s),       rawPart(s, 'v'),  tint(s));
            } else {
                add(s.name + ' /' + num(s.total) + (s.counted ? '' : ' *'), '', mark(s), rawPart(s, 'v'), tint(s));
            }
        });

        add('Total', 'bs-tot', function (r) { return num(r.total_obtained) + ' / ' + num(r.total_max); },
                               function (r) { return r.total_obtained; });
        add('%',     'bs-tot', function (r) { return num(r.percentage) + '%'; }, function (r) { return r.percentage; });
        add('GPA',   '',       function (r) { return num(r.gpa); },              function (r) { return r.gpa; });
        add('Grade', '',       function (r) { return ORMS.esc(r.grade || '—'); },function (r) { return r.grade || ''; });
        add(DATA.multi ? 'Position (in section)' : 'Position', '',
            function (r) { return r.position === null ? dash() : r.position; },
            function (r) { return r.position === null ? 9999 : r.position; });
        add('Attendance', '', function (r) {
            if (!r.att) return dash();
            return num(r.att.p) + ' / ' + num(r.att.t) + ' <span class="rc-att-pct">(' + num(r.att.pct) + '%)</span>';
        }, function (r) { return r.att ? r.att.pct : -1; });
        add('Result', '', function (r) {
            var h = '<span class="status-badge ' + (r.result_status === 'PASS' ? 'status-active' : 'status-inactive') + '">' +
                    ORMS.esc(r.result_status) + '</span>';
            if (r.result_status !== 'PASS' && r.failed) h += '<br><small class="text-muted">' + ORMS.esc(r.failed) + '</small>';
            return h;
        }, function (r) { return r.result_status; });

        return C;
    }

    // subject mark + the grade letter under it when the card setting is on
    function mark(s) {
        return function (r) {
            var c = r.cells[s.id];
            if (!c || !c.e) return dash();
            if (c.ab) return '<span class="bs-abs">AB</span>';
            return num(c.v) + (DATA.show_grade && c.g ? '<br><small>' + ORMS.esc(c.g) + '</small>' : '');
        };
    }

    // one component bucket — ca / exam. blank when the class has no scheme on this mark
    function part(s, key) {
        return function (r) {
            var c = r.cells[s.id];
            if (!c || !c.e) return dash();
            if (c.ab) return '<span class="bs-abs">AB</span>';
            return (c[key] === null || c[key] === undefined) ? dash() : num(c[key]);
        };
    }

    function rawPart(s, key) {
        return function (r) {
            var c = r.cells[s.id];
            if (!c || !c.e) return -1;
            if (c.ab) return 0;
            var v = key === 'v' ? c.v : c[key];
            return (v === null || v === undefined) ? -1 : v;
        };
    }

    // absent cell vs failed cell — two different reads, two different tints
    function tint(s) {
        return function (r) {
            var c = r.cells[s.id];
            if (!c || !c.e) return '';
            if (c.ab) return 'bs-abs';
            return (!c.p && c.c) ? 'bs-fail' : '';
        };
    }

    // ---- render ----
    function render() {
        if (!DATA) return;
        var C = colDefs();

        $('#bsSkeleton').hide();
        $('#bsWrap, #bsFoot, #bsLegendWrap, #bsPrintBtn').removeClass('initially-hidden').show();
        $('#bsEmpty').addClass('initially-hidden').hide();

        // container is visible before dt measures, otherwise every column comes out 0 wide
        if (bsTable) { bsTable.destroy(); $('#bsTable').empty(); bsTable = null; }
        var title = 'Broadsheet - ' + DATA.class_name + ' ' + DATA.section_label + ' - ' + DATA.term;
        bsTable = $('#bsTable').DataTable({
            data: DATA.rows,
            destroy: true,
            columns: C.map(function (c) {
                return {
                    title: c.title, className: c.cls, data: null,
                    render: function (d, t, row) { return t === 'display' ? c.disp(row) : c.raw(row); },
                    createdCell: c.cell ? function (td, cd, row) {
                        var k = c.cell(row);
                        if (k) td.className = (td.className ? td.className + ' ' : '') + k;
                    } : undefined
                };
            }),
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: title },
                { text: '<i class="fas fa-file-pdf"></i> PDF', title: title,
                  action: function (e, dt, node, config) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: title }
            ],
            order: DATA.multi ? [[2, 'asc'], [C.length - 3, 'asc']] : [[C.length - 3, 'asc']]
        });

        $('#bsFoot').html(statsHtml(false));
        $('#bsLegendWrap').html(legendHtml(false));
    }

    // .section-header / .stat-mini / .table-responsive are on the app's print blacklist, so the
    // paper variant uses plain headings + a metric table instead. same data, one source
    function head(pr, icon, text) {
        return pr ? '<h4><i class="fas ' + icon + '"></i> ' + text + '</h4>'
                  : '<div class="section-header"><h2><i class="fas ' + icon + '"></i> ' + text + '</h2></div>';
    }
    function tbl(pr, inner) { return pr ? inner : '<div class="table-responsive">' + inner + '</div>'; }

    // per-subject spread + the class-level numbers, straight off the server pass
    function statsHtml(pr) {
        var st = DATA && DATA.stats;
        if (!st) return '';
        var o = st.overall, h = '', kpi = [
            ['fa-users',        'Students',          o.students],
            ['fa-percent',      'Class Average',     o.avg_pct === null ? '—' : num(o.avg_pct) + '%'],
            ['fa-arrow-up',     'Highest Aggregate', (o.hi === null ? '—' : num(o.hi)), o.hi_who],
            ['fa-arrow-down',   'Lowest Aggregate',  (o.lo === null ? '—' : num(o.lo)), o.lo_who],
            ['fa-circle-check', 'Passed',            o.pass],
            ['fa-circle-xmark', 'Failed',            o.fail],
            ['fa-chart-pie',    'Pass Rate',         o.pass_pct === null ? '—' : num(o.pass_pct) + '%']
        ];
        if (o.withheld) kpi.push(['fa-hand', 'Withheld', o.withheld]);

        h += head(pr, 'fa-chart-simple', 'Class Statistics');
        if (pr) {
            h += '<table class="bs-stat"><tbody>';
            kpi.forEach(function (k) {
                h += '<tr class="bs-stat-row"><td><i class="fas ' + k[0] + '"></i> ' + k[1] + '</td><td><b>' + k[2] + '</b>' +
                     (k[3] ? ' <small>' + ORMS.esc(k[3]) + '</small>' : '') + '</td></tr>';
            });
            h += '</tbody></table>';
        } else {
            h += '<div class="stat-mini">';
            kpi.forEach(function (k) {
                h += '<div><i class="fas ' + k[0] + '"></i> ' + k[1] + ' <b>' + k[2] + '</b>' +
                     (k[3] ? ' <small>' + ORMS.esc(k[3]) + '</small>' : '') + '</div>';
            });
            h += '</div>';
        }

        var sub = '<table class="bs-stat">' +
             '<thead><tr><th><i class="fas fa-book"></i> Subject</th><th><i class="fas fa-pen"></i> Entered</th>' +
             '<th><i class="fas fa-equals"></i> Average</th><th><i class="fas fa-arrow-up"></i> Highest</th>' +
             '<th><i class="fas fa-arrow-down"></i> Lowest</th><th><i class="fas fa-circle-check"></i> Passed</th>' +
             '<th><i class="fas fa-percent"></i> Pass %</th></tr></thead><tbody>';
        st.subject.forEach(function (s) {
            sub += '<tr class="bs-stat-row">' +
                 '<td><strong>' + ORMS.esc(s.name) + '</strong> <small>/' + num(s.total) + '</small>' +
                    (s.counted ? '' : ' <small class="text-muted">(not counted)</small>') + '</td>' +
                 '<td>' + s.entered + (s.absent ? ' <small class="text-muted">(' + s.absent + ' AB)</small>' : '') + '</td>' +
                 '<td>' + (s.avg === null ? '—' : num(s.avg) + (s.avg_pct === null ? '' : ' <small>(' + num(s.avg_pct) + '%)</small>')) + '</td>' +
                 '<td>' + (s.hi === null ? '—' : num(s.hi)) + '</td>' +
                 '<td>' + (s.lo === null ? '—' : num(s.lo)) + '</td>' +
                 '<td>' + s.passed + '</td>' +
                 '<td>' + (s.pass_pct === null ? '—' : num(s.pass_pct) + '%') + '</td></tr>';
        });
        h += tbl(pr, sub + '</tbody></table>');

        var spr = '<table class="bs-stat">' +
             '<thead><tr><th><i class="fas fa-award"></i> Grade</th><th><i class="fas fa-ruler"></i> Range</th>' +
             '<th><i class="fas fa-users"></i> Students</th></tr></thead><tbody>';
        st.spread.forEach(function (g) {
            spr += '<tr class="bs-stat-row' + (g.fail ? ' bs-fail' : '') + '">' +
                 '<td><strong' + (g.color ? ' style="color:' + ORMS.esc(g.color) + '"' : '') + '>' + ORMS.esc(g.grade) + '</strong></td>' +
                 '<td>' + ORMS.esc(g.range || '—') + '</td><td>' + g.n + '</td></tr>';
        });
        h += head(pr, 'fa-layer-group', 'Grade Distribution') + tbl(pr, spr + '</tbody></table>');
        return h;
    }

    function legendHtml(pr) {
        if (!DATA || !DATA.legend) return '';
        return head(pr, 'fa-key', 'Grade Interpretation') + DATA.legend +
               (DATA.key_note ? '<p class="rc-key-note">' + ORMS.esc(DATA.key_note) + '</p>' : '');
    }

    // ---- a4 landscape print: same column model, plain table, branded head ----
    function sheetHtml() {
        var C = colDefs(), h = '<table class="bs-table"><thead><tr>';
        C.forEach(function (c) { h += '<th>' + ORMS.esc(c.title) + '</th>'; });
        h += '</tr></thead><tbody>';
        DATA.rows.forEach(function (r) {
            h += '<tr>';
            C.forEach(function (c) {
                var k = c.cell ? c.cell(r) : '';
                h += '<td class="' + (c.cls + (k ? ' ' + k : '')).trim() + '">' + c.disp(r) + '</td>';
            });
            h += '</tr>';
        });
        return h + '</tbody></table>';
    }

    function printSheet() {
        if (!DATA || !(DATA.rows || []).length) { ORMS.err('Build a broadsheet first'); return; }
        $('#bsPrintArea').html(DATA.print_head + sheetHtml() +
                               '<div class="bs-foot">' + statsHtml(true) + '</div>' +
                               '<div class="bs-legend">' + legendHtml(true) + '</div>');
        ORMS.printOnly('#bsPrintArea');
    }
    </script>
</body>
</html>
