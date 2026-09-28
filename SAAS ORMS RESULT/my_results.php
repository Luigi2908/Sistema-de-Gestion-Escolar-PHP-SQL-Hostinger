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
requirePerm('my_results', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'my_results';

$ownStudentId = ormsStudentId($user_id);   // null = viewer is not a student
$isStudent    = $ownStudentId !== null;
$isSchoolWide = ormsSchoolWide($role);     // admin + principal — reach every student

// 100.00 -> 100, 87.50 -> 87.5
function myrNum($v): string {
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}

// who this page is about — decided here on EVERY hit, the query string is never trusted
// returns [studentId, allowedYearIds (null = no year limit), denyReason]
function myrResolve(?int $ownId, int $userId, string $role, int $reqId): array {
    // a student is pinned to their own row. a foreign id is tampering, not a filter
    if ($ownId !== null) {
        return ($reqId > 0 && $reqId !== $ownId) ? [0, null, 'You can only open your own results.'] : [$ownId, null, ''];
    }
    if ($reqId <= 0) return [0, null, '']; // staff landed with nobody picked yet

    // resolved through the tenant layer FIRST — another school's child reads exactly like a bad id
    $ownedId = ormsOwnStudent($reqId);
    $st = $ownedId ? qOne("SELECT id, section_id FROM students WHERE id = ?", 'i', $ownedId) : null;
    if (!$st) return [0, null, 'That student does not exist.'];
    // school-wide = any student of THIS school; the resolve above already pinned a branch admin to their branch
    if (ormsSchoolWide($role)) return [(int)$st['id'], null, ''];

    // teacher: only the years they held the section THIS STUDENT was actually in that year.
    // matching on the student's LIVE section instead leaked: sections are reused across years, so a
    // teacher who held section B in 2023 got a 2024-promoted student's 2023 card even though that
    // student sat in section A back then. the subquery is ormsResolveResultSection()'s chain
    // (summary -> marks -> live row) applied set-wise, one (year, section) pair per year.
    $tid = ormsTeacherId($userId);
    $years = $tid ? array_map('intval', array_column(
        qAll("SELECT DISTINCT p.academic_year_id
              FROM (SELECT academic_year_id, section_id FROM result_summaries WHERE student_id = ?
                    UNION SELECT academic_year_id, section_id FROM marks WHERE student_id = ?
                    UNION SELECT academic_year_id, section_id FROM students WHERE id = ?) p
              JOIN teacher_subjects ts ON ts.section_id = p.section_id
                                      AND ts.academic_year_id = p.academic_year_id
              WHERE ts.teacher_id = ?",
             'iiii', $ownedId, $ownedId, $ownedId, $tid), 'academic_year_id')) : [];

    return $years ? [(int)$st['id'], $years, ''] : [0, null, "You are not assigned to this student's section."];
}

// every term of the years this student actually has a record in — ONE query, newest first.
// the rp join IS ormsIsPublished() applied set-wise, so publication is never a per-term round trip.
// each term follows the section it was frozen against (rs.section_id) — $sectionId is only the fallback,
// otherwise a promotion would flip every past term to "unpublished" and zero the "of N" pool
function myrTerms(int $studentId, int $sectionId, int $ownYearId, ?array $limitYears): array {
    $years = array_map('intval', array_column(
        qAll("SELECT DISTINCT academic_year_id FROM result_summaries WHERE student_id = ?", 'i', $studentId), 'academic_year_id'));
    if ($ownYearId) $years[] = $ownYearId;
    if ($limitYears !== null) $years = array_intersect($years, $limitYears);   // teacher scope
    $years = array_values(array_unique($years));
    if (!$years) return [];

    $in = implode(',', array_fill(0, count($years), '?'));   // placeholders only — no value ever touches the sql
    return qAll(
        "SELECT t.id AS term_id, t.name AS term_name, t.status AS term_status,
                t.academic_year_id, y.name AS year_name,
                COALESCE(rp.is_published, 0) AS is_published, rp.published_at,
                rs.total_obtained, rs.total_max, rs.percentage, rs.grade, rs.gpa,
                rs.`position`, rs.result_status, rs.failed_subjects,
                (SELECT COUNT(*) FROM result_summaries rs2
                  WHERE rs2.term_id = t.id AND rs2.section_id = COALESCE(rs.section_id, ?)) AS section_total
         FROM exam_terms t
         JOIN academic_years y ON y.id = t.academic_year_id
         LEFT JOIN result_summaries    rs ON rs.term_id = t.id AND rs.student_id = ?
         LEFT JOIN result_publications rp ON rp.term_id = t.id AND rp.section_id = COALESCE(rs.section_id, ?)
         WHERE t.academic_year_id IN ($in)
         ORDER BY y.start_date DESC, y.id DESC, t.sort_order DESC, t.id DESC",
        str_repeat('i', 3 + count($years)), $sectionId, $studentId, $sectionId, ...$years);
}

// live, never the frozen is_withheld column — clearing the debt must restore the card without a
// republish. balance is per academic year, so one check per year covers all its terms
function myrWithheldMap(int $studentId, array $terms): array {
    try {
        $out = []; $byYear = [];
        foreach ($terms as $t) {
            $yid = (int)($t['academic_year_id'] ?? 0);
            $byYear[$yid] ??= ormsWithholdCheck($studentId, $yid ?: null);
            if (!empty($byYear[$yid]['withheld'])) $out[(int)$t['term_id']] = (string)$byYear[$yid]['reason'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

// staff copy of a withheld card — it still prints, stamped. reason + balance are school-wide only,
// a teacher gets the stamp without the money detail
// $yearId scopes the balance to the SAME year the reason was computed for — without it the stamp
// printed a lifetime figure next to a year-scoped reason ("owes 5,000 · Balance 47,000")
function myrStamp(string $reason, int $studentId, bool $wide, int $yearId = 0): string {
    $bits = [];
    if ($wide && $reason !== '') $bits[] = $reason;
    if ($wide)                   $bits[] = 'Balance ' . ormsMoney(ormsFeeBalance($studentId, $yearId ?: null));
    return '<div class="rc-withheld"><i class="fas fa-stamp"></i> <b>WITHHELD</b> — hidden from the family'
         . ($bits ? ' &middot; ' . htmlspecialchars(implode(' · ', $bits), ENT_QUOTES, 'UTF-8') : '') . '</div>';
}

// post first — ajax posts to the bare path, so the ?student_id= of the page url never reaches the handler.
// either way myrResolve() re-gates the id on every hit, so neither source is trusted
$reqStudentId = (int)($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
list($studentId, $allowYears, $deny) = myrResolve($ownStudentId, $user_id, $role, $reqStudentId);

// Handle AJAX requests
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'getMyResults':
                requirePermJson('my_results', 'v');
                if ($deny !== '') {
                    logActivity($user_id, $username, 'My Results Denied', "student_id={$reqStudentId} — {$deny}");
                    jsonErr($deny);
                }
                if (!$studentId) jsonErr('Choose a student to view.');

                $st = qOne("SELECT section_id, academic_year_id FROM students WHERE id = ?", 'i', $studentId);
                if (!$st) jsonErr('That student does not exist.');
                $sectionId = (int)$st['section_id'];   // live seat — fallback only, each term carries its own frozen section

                $terms = myrTerms($studentId, $sectionId, (int)$st['academic_year_id'], $allowYears);

                // the family = the student's own login. staff (school-wide or the section's teacher) keep full sight
                $staffView = !$isStudent;
                $whMap     = myrWithheldMap($studentId, $terms);

                // unpublished terms carry NO marks in the payload — nothing to leak client-side.
                // a withheld term is the same to the family: flagged, but stripped of every number
                $rows = []; $chart = []; $latestId = 0; $latest = null; $pubN = 0; $whHit = null;
                foreach ($terms as $t) {
                    $tid  = (int)$t['term_id'];
                    $pub  = (int)$t['is_published'] === 1 && $t['percentage'] !== null;
                    $wh   = isset($whMap[$tid]);
                    $show = $pub && !($wh && !$staffView);
                    if ($pub && $wh && !$staffView && !$whHit) $whHit = $t;   // newest first -> first hit is the newest
                    $row = ['term_id' => $tid, 'term' => $t['term_name'], 'year' => $t['year_name'],
                            'status' => $t['term_status'], 'published' => $show ? 1 : 0,
                            'withheld' => $wh ? 1 : 0, 'wh_reason' => $isSchoolWide ? (string)($whMap[$tid] ?? '') : ''];

                    if ($show) {
                        $pos = $t['position'] !== null ? (int)$t['position'] : 0;
                        // *_sort keys carry the raw value so the table sorts by number/date, not by the printed string
                        $row += [
                            'marks'      => myrNum($t['total_obtained']) . ' / ' . myrNum($t['total_max']),
                            'marks_sort' => (float)$t['total_obtained'],
                            'percentage' => (float)$t['percentage'],
                            'grade'      => (string)($t['grade'] ?: '-'),
                            'gpa'        => (float)$t['gpa'],
                            'position'   => $pos ? ormsOrdinal($pos) . ' of ' . (int)$t['section_total'] : '—',
                            'pos_sort'   => $pos,
                            'result'     => $t['result_status'],
                            'failed'     => (string)$t['failed_subjects'],
                            'published_at' => $t['published_at'] ? date('d M Y', strtotime($t['published_at'])) : '',
                            'published_sort' => (string)($t['published_at'] ?? '')
                        ];
                        $chart[] = ['label' => $t['term_name'], 'year' => $t['year_name'], 'pct' => (float)$t['percentage']];
                        if (!$latestId) { $latestId = $tid; $latest = $row; }
                        $pubN++;
                    }
                    $rows[] = $row;
                }

                // subject breakdown of the newest published term only — the engine runs ONCE, never per term
                $detail = null; $cardHtml = '';
                $latestSec = $latestId ? (ormsResolveResultSection($studentId, $latestId) ?? $sectionId) : 0;
                if ($latestId && ormsIsPublished($latestId, $latestSec)) {
                    $res = ormsStudentResult($studentId, $latestId);
                    if ($res) {
                        $cardHtml = ormsRenderResultCard($res);   // same $res the breakdown uses — no second lookup
                        if (isset($whMap[$latestId]))             // staff-only path — the family never reaches a withheld card
                            $cardHtml = myrStamp($whMap[$latestId], $studentId, $isSchoolWide,
                                                 (int)($res['term']['academic_year_id'] ?? 0)) . $cardHtml;
                        $detail = [
                            'term' => $res['term']['name'], 'year' => $res['year']['name'] ?? '',
                            'subjects' => array_map(fn($s) => [
                                'name'     => $s['name'] . ($s['code'] ? ' (' . $s['code'] . ')' : ''),
                                'marks'    => $s['is_absent'] ? 'AB' : myrNum($s['obtained']) . ' / ' . myrNum($s['total']),
                                'percent'  => $s['is_absent'] ? '—' : number_format((float)$s['percent'], 2) . '%',
                                'grade'    => $s['grade'],
                                'passed'   => (int)$s['passed'],
                                'absent'   => (int)$s['is_absent']
                            ], $res['subjects'])
                        ];
                    }
                }

                jsonOk([
                    'terms'  => $rows,
                    'chart'  => array_reverse($chart),   // oldest -> newest reads left to right
                    'detail' => $detail,
                    'card'   => $cardHtml,               // latest published term, rendered by the class template
                    // the family gets the school's wording instead of the card it may not see
                    'withhold' => $whHit ? [
                        'msg'  => (string)getSetting('withhold_message', 'This result has been withheld by the school. Please contact the school office.'),
                        'term' => $whHit['term_name'] . ' (' . $whHit['year_name'] . ')'
                    ] : null,
                    'kpi'    => [
                        'published' => $pubN,
                        'term_id'   => $latestId,
                        'percent'   => $latest ? number_format($latest['percentage'], 2) . '%' : '—',
                        'grade'     => $latest['grade'] ?? '—',
                        'position'  => $latest['position'] ?? '—',
                        'result'    => $latest['result'] ?? '',
                        'subjects'  => $detail ? count($detail['subjects']) : 0
                    ]
                ]);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('my_results.php error: ' . $e->getMessage());
        jsonErr('Could not load results');   // real cause stays in the log, never on the wire
    }
}

// ---- page render path
if ($deny !== '') {
    http_response_code(403);
    logActivity($user_id, $username, 'My Results Denied', "student_id={$reqStudentId} — {$deny}");
}

// header strip data — one read, only when a target survived the gate
$stu = $studentId ? qOne(
    "SELECT st.id, st.admission_no, st.roll_no, st.status,
            u.full_name, u.username, u.profile_image,
            c.name AS class_name, sec.name AS section_name,
            y.name AS year_name, y.is_current
     FROM students st
     JOIN users u          ON u.id = st.user_id
     JOIN classes c        ON c.id = st.class_id
     JOIN sections sec     ON sec.id = st.section_id
     JOIN academic_years y ON y.id = st.academic_year_id
     WHERE st.id = ?", 'i', $studentId) : null;

// staff picker — scoped in the WHERE clause, never filtered in the browser
$pick = [];
if (!$isStudent && $deny === '') {
    // school-wide means the whole of THIS school — never the whole table. a branch admin is pinned further
    $pkS = ormsSchoolSql('students', 'st');  $pkB = ormsBranchSql('students', 'st');
    $pkT = ($pkS ? 'i' : '') . ($pkB ? 'i' : '');
    $pkA = array_merge($pkS ? [sid()] : [], $pkB ? [ormsBranchLock()] : []);
    $pick = $isSchoolWide
        ? qAll("SELECT st.id, st.admission_no, u.full_name, u.username, c.name AS cn, sec.name AS sn
                FROM students st JOIN users u ON u.id = st.user_id
                JOIN classes c ON c.id = st.class_id JOIN sections sec ON sec.id = st.section_id
                WHERE st.status = 'Active'" . $pkS . $pkB . "
                ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, CAST(st.roll_no AS UNSIGNED) ASC, u.full_name ASC", $pkT, ...$pkA)
        : (($tid = ormsTeacherId($user_id))     // live section only — promoted student falls off
            ? qAll("SELECT DISTINCT st.id, st.admission_no, u.full_name, u.username, c.name AS cn, sec.name AS sn
                    FROM teacher_subjects ts
                    JOIN students st   ON st.section_id = ts.section_id AND st.status = 'Active'
                    JOIN users u       ON u.id = st.user_id
                    JOIN classes c     ON c.id = st.class_id
                    JOIN sections sec  ON sec.id = st.section_id
                    WHERE ts.teacher_id = ?
                    ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, CAST(st.roll_no AS UNSIGNED) ASC, u.full_name ASC", 'i', $tid)
            : []);
}

$who = $stu ? ($stu['full_name'] ?: $stu['username']) : '';
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
    <title><?php echo $isStudent ? 'My Results' : 'Student Results'; ?> - Result Management</title>

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
                    <h1><i class="fas fa-file-lines"></i> <?php echo $isStudent ? 'My Results' : 'Student Results'; ?></h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span><?php echo $isStudent ? 'My Results' : 'Student Results'; ?></span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if ($deny !== ''): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-ban"></i>
                    <h4>Access denied</h4>
                    <p><?php echo htmlspecialchars($deny); ?></p>
                </div>
                <?php if (!$isStudent): ?>
                <div class="section-header">
                    <div class="btn-group-inline">
                        <a class="btn btn-secondary" href="my_results.php"><i class="fas fa-arrow-left"></i> Pick another student</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>

                <!-- one compact bar: who + picker. replaces the old picker card + details card -->
                <div class="data-section myr-hero no-print">
                    <div class="myr-bar">
                        <?php if ($stu): ?>
                        <div class="myr-id">
                            <?php if (($stu['profile_image'] ?? '') !== ''): ?>
                            <img class="myr-avatar" src="<?php echo htmlspecialchars($stu['profile_image']); ?>" alt="">
                            <?php else: ?>
                            <span class="myr-avatar myr-avatar-txt"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($who, 0, 1))); ?></span>
                            <?php endif; ?>
                            <div class="myr-id-text">
                                <h2><?php echo htmlspecialchars($who); ?></h2>
                                <div class="myr-chips">
                                    <span class="myr-chip"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($stu['admission_no']); ?></span>
                                    <span class="myr-chip"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($stu['class_name'] . ' – ' . $stu['section_name']); ?></span>
                                    <span class="myr-chip"><i class="fas fa-list-ol"></i> Roll <?php echo htmlspecialchars(trim((string)$stu['roll_no']) !== '' ? $stu['roll_no'] : '—'); ?></span>
                                    <span class="myr-chip"><i class="fas fa-calendar-days"></i> <?php echo htmlspecialchars($stu['year_name']); ?><?php echo (int)$stu['is_current'] === 1 ? ' <b>current</b>' : ''; ?></span>
                                    <span class="myr-chip <?php echo $stu['status'] === 'Active' ? 'myr-chip-ok' : 'myr-chip-off'; ?>">
                                        <i class="fas fa-circle-<?php echo $stu['status'] === 'Active' ? 'check' : 'minus'; ?>"></i> <?php echo htmlspecialchars($stu['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="myr-id myr-id-empty">
                            <span class="myr-avatar myr-avatar-txt"><i class="fas fa-user-graduate"></i></span>
                            <div class="myr-id-text">
                                <h2><?php echo $isStudent ? 'Student record unavailable' : 'No student selected'; ?></h2>
                                <p><?php echo $isStudent
                                    ? 'Your student profile could not be loaded. Please contact the school office.'
                                    : 'Pick a student to see their published results.'; ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$isStudent): ?>
                        <div class="myr-pick">
                            <label for="pickStudent"><i class="fas fa-user-graduate"></i> Student</label>
                            <select id="pickStudent" data-search-ph="Search name, admission no or class…">
                                <option value="">— Select a student —</option>
                                <?php foreach ($pick as $p): $pname = $p['full_name'] ?: $p['username']; ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo (int)$p['id'] === $studentId ? 'selected' : ''; ?>
                                    data-av="<?php echo htmlspecialchars(mb_strtoupper(mb_substr($pname, 0, 1))); ?>"
                                    data-sub="<?php echo htmlspecialchars($p['admission_no'] . ' · ' . $p['cn'] . ' – ' . $p['sn']); ?>"><?php echo htmlspecialchars($pname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($stu): ?>

                <div class="lte-kpi-grid myr-kpi initially-hidden no-print" id="kpiGrid">
                    <div class="small-box small-box-sm bg-navy">
                        <div class="inner"><h3 id="kpiPct">—</h3><p>Latest Percentage</p></div>
                        <div class="icon"><i class="fas fa-percent"></i></div>
                        <a href="#resultsList" class="small-box-footer js-go-tab" data-tab="card">View result card <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box small-box-sm bg-info">
                        <div class="inner"><h3 id="kpiGrade">—</h3><p>Latest Grade</p></div>
                        <div class="icon"><i class="fas fa-award"></i></div>
                        <a href="#resultsList" class="small-box-footer js-go-tab" data-tab="card">View result card <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box small-box-sm bg-success">
                        <div class="inner"><h3 id="kpiPos">—</h3><p>Position in Section</p></div>
                        <div class="icon"><i class="fas fa-ranking-star"></i></div>
                        <a href="#resultsList" class="small-box-footer js-go-tab" data-tab="card">View result card <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box small-box-sm bg-warning">
                        <div class="inner"><h3 id="kpiTerms">0</h3><p>Published Terms</p></div>
                        <div class="icon"><i class="fas fa-file-circle-check"></i></div>
                        <a href="#resultsList" class="small-box-footer js-go-tab" data-tab="results">See all terms <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>

                <div class="data-section" id="resultsList">
                    <div class="tab-nav no-print" id="myrTabs">
                        <button type="button" class="tab-btn active" data-tab="results"><i class="fas fa-list-check"></i> All Results</button>
                        <button type="button" class="tab-btn" data-tab="card"><i class="fas fa-file-lines"></i> Result Card</button>
                        <button type="button" class="tab-btn" data-tab="subjects"><i class="fas fa-book-open"></i> Subjects</button>
                        <button type="button" class="tab-btn" data-tab="trend"><i class="fas fa-chart-line"></i> Performance</button>
                    </div>

                <!-- ============ TAB 1: ALL RESULTS ============ -->
                <div class="tab-pane active" id="pane-results">
                    <div class="section-header">
                        <h2><i class="fas fa-list-check"></i> Published Results</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" id="btnRefresh" onclick="loadResults()"><i class="fas fa-sync"></i> Refresh</button>
                        </div>
                    </div>

                    <div id="listSkeleton">
                        <div class="skeleton-table">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="listEmpty" class="orms-empty initially-hidden">
                        <i class="fas fa-hourglass-half"></i>
                        <h4>No results published yet</h4>
                        <p><?php echo $isStudent
                            ? 'Your result card appears here the moment the school publishes a term. You will also get a notification.'
                            : 'This student&rsquo;s result card appears here once the term is published from the Results page.'; ?></p>
                    </div>

                    <div id="listWrap" class="initially-hidden">
                        <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="table-responsive">
                            <table id="listTable" class="display table-full-width"></table>
                        </div>
                    </div>
                </div>

                <!-- ============ TAB 2: RESULT CARD (A4) ============ -->
                <div class="tab-pane" id="pane-card">
                    <div class="section-header">
                        <h2><i class="fas fa-file-lines"></i> Result Card <span class="myr-sub" id="cardLabel"></span></h2>
                        <div class="btn-group-inline">
                            <a class="btn btn-secondary initially-hidden" id="cardOpen" href="#"><i class="fas fa-up-right-from-square"></i> Open Full Page</a>
                            <button type="button" class="btn btn-primary initially-hidden" id="cardPrint" onclick="ORMS.printOnly('#cardWrap')"><i class="fas fa-print"></i> Print / Save PDF</button>
                        </div>
                    </div>
                    <div id="cardSkeleton"><div class="skeleton skeleton-chart"></div></div>
                    <div id="cardWithheld" class="rc-withheld initially-hidden"></div>
                    <div id="cardEmpty" class="orms-empty initially-hidden">
                        <i class="fas fa-file-circle-xmark"></i>
                        <h4>No result card yet</h4>
                        <p>The printable A4 card appears here as soon as a term is published.</p>
                    </div>
                    <div id="cardWrap" class="initially-hidden">
                        <div class="table-scroll-hint no-print">
                            <i class="fas fa-arrows-alt-h"></i> Swipe across the card &mdash; pinch to zoom
                        </div>
                        <div class="a4-wrap"><div class="a4-sheet" id="a4Sheet"></div></div>
                    </div>
                </div>

                <!-- ============ TAB 3: SUBJECTS ============ -->
                <div class="tab-pane" id="pane-subjects">
                    <div class="section-header">
                        <h2><i class="fas fa-book-open"></i> Subject Breakdown <span class="myr-sub" id="subjLabel"></span></h2>
                    </div>
                    <div id="subjEmpty" class="orms-empty initially-hidden">
                        <i class="fas fa-book"></i>
                        <h4>No subject breakdown yet</h4>
                        <p>Subject-wise marks of the most recent published term show here.</p>
                    </div>
                    <div id="subjWrap" class="initially-hidden">
                        <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="about-roles-table rc-table" id="subjTable">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-book"></i> Subject</th>
                                        <th><i class="fas fa-calculator"></i> Obtained / Total</th>
                                        <th><i class="fas fa-percent"></i> Percentage</th>
                                        <th><i class="fas fa-award"></i> Grade</th>
                                    </tr>
                                </thead>
                                <tbody id="subjBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============ TAB 4: PERFORMANCE ============ -->
                <div class="tab-pane" id="pane-trend">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-line"></i> Performance Across Terms</h2>
                    </div>
                    <div id="chartSkeleton"><div class="skeleton skeleton-chart"></div></div>
                    <div id="chartNote" class="info-banner initially-hidden">
                        <i class="fas fa-circle-info"></i>
                        <span id="chartNoteText"></span>
                    </div>
                    <div id="chartWrap" class="chart-container initially-hidden">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

                </div><!-- /tabbed data-section -->

                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <script>
    // lazy pdf deps — only fetched when someone actually exports
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
    var STUDENT_ID = <?php echo (int)$studentId; ?>, trendChart = null, listTable = null;
    var LIST_TITLE = <?php echo json_encode(($who !== '' ? $who . ' — ' : '') . 'Results', JSON_UNESCAPED_UNICODE); ?>;

    function cssVar(n, fb) {
        var v = getComputedStyle(document.body).getPropertyValue(n);
        return (v || '').trim() || fb;
    }

    // class toggle, never .show() — jq would force display:block and flatten the kpi grid / banner flex
    function vis(sel, on) { $(sel).toggleClass('initially-hidden', !on).css('display', ''); }

    // the empty card doubles as the error card — defaults captured once so a failed load
    // can never leave its copy stuck on the next successful refresh
    var EMPTY_H4 = $('#listEmpty h4').text(), EMPTY_P = $('#listEmpty p').text(), EMPTY_ICON = 'fa-hourglass-half';
    function emptyState(h4, p, icon) {
        $('#listEmpty h4').text(h4 || EMPTY_H4);
        $('#listEmpty p').text(p || EMPTY_P);
        $('#listEmpty i').attr('class', 'fas ' + (icon || EMPTY_ICON));
    }

    $(document).ready(function () {
        <?php if (!$isStudent && $deny === ''): ?>
        ORMS.dropdown('#pickStudent');
        $('#pickStudent').on('change', function () {           // server re-gates the new id, the url alone proves nothing
            var v = $(this).val();
            window.location.href = v ? 'my_results.php?student_id=' + encodeURIComponent(v) : 'my_results.php';
        });
        <?php endif; ?>

        // tabs — dt columns and the chart canvas measure to 0 inside a hidden pane, so re-measure on show
        $('#myrTabs').on('click', '.tab-btn', function () {
            var t = $(this).data('tab'), $btn = $(this);
            ORMS.swap(function () {                       // one view transition -> panes crossfade
                $('#myrTabs .tab-btn').removeClass('active');
                $btn.addClass('active');
                $('#resultsList .tab-pane').removeClass('active');
                $('#pane-' + t).addClass('active');
                if (t === 'results' && listTable) {
                    listTable.columns.adjust();
                    if (listTable.responsive) listTable.responsive.recalc();
                }
                if (t === 'trend' && trendChart) trendChart.resize();
            });
        });

        // kpi footers jump to the tab that actually answers them
        $(document).on('click', '.js-go-tab', function () {
            $('#myrTabs .tab-btn[data-tab="' + $(this).data('tab') + '"]').trigger('click');
        });

        if (STUDENT_ID) loadResults();
    });

    function loadResults() {
        vis('#listSkeleton', true); vis('#chartSkeleton', true); vis('#cardSkeleton', true);
        ['#listWrap', '#listEmpty', '#chartWrap', '#chartNote', '#kpiGrid',
         '#subjWrap', '#subjEmpty', '#cardWrap', '#cardEmpty', '#cardOpen', '#cardPrint', '#cardWithheld'].forEach(function (s) { vis(s, false); });

        ORMS.post('getMyResults', { student_id: STUDENT_ID }, { btn: '#btnRefresh', busyLabel: 'Loading…' })
            .done(function (res) {
                vis('#listSkeleton', false); vis('#chartSkeleton', false); vis('#cardSkeleton', false);
                if (!res.success) {
                    emptyState('Could not load results', res.message || 'Please try again.', 'fa-triangle-exclamation');
                    vis('#listEmpty', true); vis('#cardEmpty', true); vis('#subjEmpty', true);
                    ORMS.err(res.message); return;
                }
                renderList(res.terms || []);
                renderKpi(res.kpi || {});
                renderChart(res.chart || []);
                renderSubjects(res.detail);
                renderCard(res.card, res.detail, res.kpi || {}, res.withhold);
            })
            .fail(function (msg) {
                vis('#listSkeleton', false); vis('#chartSkeleton', false); vis('#cardSkeleton', false);
                vis('#cardEmpty', true); vis('#subjEmpty', true);
                emptyState('Connection problem', 'Could not reach the server. Please refresh.', 'fa-plug-circle-xmark');
                vis('#listEmpty', true);
                ORMS.err(msg);
            });
    }

    var DASH = '<span class="text-muted">—</span>';

    // pending terms carry no marks in the payload — every cell of theirs is a dash, there is nothing to reveal
    function renderList(terms) {
        var pub = terms.filter(function (t) { return t.published; });
        emptyState();                               // clear any error copy left by a previous attempt
        if (!terms.length) { vis('#listEmpty', true); return; }
        if (!pub.length) vis('#listEmpty', true);   // pending terms still listed below, nothing goes silently missing

        vis('#listWrap', true);                     // dt sizes its columns on a visible table
        if (listTable) { listTable.destroy(); $('#listTable').empty(); listTable = null; }

        listTable = $('#listTable').DataTable({
            data: terms,
            destroy: true,
            columns: [
                { data: 'term', title: '<i class="fas fa-file-pen"></i> Exam Term',
                  render: function (d, t) { return t === 'display' ? '<strong>' + ORMS.esc(d) + '</strong>' : d; } },
                { data: 'year', title: '<i class="fas fa-calendar-days"></i> Year', render: function (d) { return ORMS.esc(d); } },
                { data: null, title: '<i class="fas fa-calculator"></i> Marks',
                  render: function (d, t, row) {
                      if (!row.published) return t === 'display' ? DASH : -1;
                      return t === 'display' ? ORMS.esc(row.marks) : row.marks_sort;   // sorts on obtained, not "448 / 600"
                  } },
                { data: null, title: '<i class="fas fa-percent"></i> Percentage',
                  render: function (d, t, row) {
                      if (!row.published) return t === 'display' ? DASH : -1;
                      return t === 'display' ? '<strong>' + Number(row.percentage).toFixed(2) + '%</strong>' : row.percentage;
                  } },
                { data: null, title: '<i class="fas fa-award"></i> Grade',
                  render: function (d, t, row) { return row.published ? ORMS.esc(row.grade) : (t === 'display' ? DASH : ''); } },
                { data: null, title: '<i class="fas fa-ranking-star"></i> Position',
                  render: function (d, t, row) {
                      if (!row.published) return t === 'display' ? DASH : 0;
                      return t === 'display' ? ORMS.esc(row.position) : row.pos_sort;  // sorts on rank, not "3rd of 15"
                  } },
                { data: null, title: '<i class="fas fa-circle-check"></i> Result',
                  render: function (d, t, row) {
                      if (!row.published) {
                          if (row.withheld) {           // held back, not pending — say which one it is
                              return t === 'display'
                                  ? '<span class="status-badge status-inactive"><i class="fas fa-lock"></i> Withheld</span>'
                                  : 'Withheld';
                          }
                          return t === 'display'
                              ? '<span class="status-badge status-inactive"><i class="fas fa-clock"></i> Not published yet</span>'
                              : 'Pending';
                      }
                      if (t !== 'display') return row.result;
                      // staff copy of a withheld card — the numbers stay, the stamp rides along
                      var wh = row.withheld ? '<br><span class="fee-hold"><i class="fas fa-lock"></i> Withheld' +
                               (row.wh_reason ? ' — ' + ORMS.esc(row.wh_reason) : '') + '</span>' : '';
                      return '<b class="rc-badge ' + (row.result === 'PASS' ? 'rc-badge-pass' : 'rc-badge-fail') + '">' +
                             ORMS.esc(row.result) + '</b>' + wh;
                  } },
                { data: null, title: '<i class="fas fa-calendar-check"></i> Published On',
                  render: function (d, t, row) {
                      if (!row.published || !row.published_at) return t === 'display' ? DASH : '';
                      return t === 'display' ? ORMS.esc(row.published_at) : row.published_sort;   // sorts on the raw timestamp
                  } },
                { data: null, title: '<i class="fas fa-up-right-from-square"></i> Action', orderable: false,
                  render: function (d, t, row) {
                      if (!row.published) return DASH;
                      return '<a class="btn btn-primary btn-sm" href="result_card.php?student_id=' + STUDENT_ID + '&term_id=' + row.term_id + '">' +
                             '<i class="fas fa-file-lines"></i> View Result</a>';
                  } }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: LIST_TITLE, exportOptions: { columns: ':not(:last-child)' } },
                { text: '<i class="fas fa-file-pdf"></i> PDF',
                  action: function (e, dt, node, config) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  },
                  title: LIST_TITLE, exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: LIST_TITLE, exportOptions: { columns: ':not(:last-child)' } }
            ],
            order: [],   // server already returns newest year/term first
            language: { emptyTable: 'No results to show' }
        });
    }

    function renderKpi(k) {
        if (!k.published) return;
        $('#kpiPct').text(k.percent);
        $('#kpiGrade').text(k.grade);
        $('#kpiPos').text(k.position);
        $('#kpiTerms').text(k.published);
        vis('#kpiGrid', true);
    }

    // one point is noise, not a trend — a note replaces the chart below 2 published terms
    function renderChart(series) {
        if (trendChart) { trendChart.destroy(); trendChart = null; }
        if (series.length < 2) {
            $('#chartNoteText').text(series.length === 1
                ? 'The trend chart appears once a second term is published — one result is not a trend yet.'
                : 'No published results to chart yet.');
            vis('#chartNote', true);
            return;
        }
        var accent = cssVar('--navy-accent', '#0074D9'),
            tick   = cssVar('--text-secondary', '#555'),
            grid   = cssVar('--border-color', '#e0e0e0');

        trendChart = new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: series.map(function (p) { return p.label + ' (' + p.year + ')'; }),
                datasets: [{
                    label: 'Percentage', data: series.map(function (p) { return p.pct; }),
                    borderColor: accent, backgroundColor: 'rgba(0, 116, 217, 0.12)',
                    fill: true, tension: 0.3, borderWidth: 3,
                    pointRadius: 5, pointHoverRadius: 7, pointBackgroundColor: accent
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return c.parsed.y.toFixed(2) + '%'; } } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { color: tick, callback: function (v) { return v + '%'; } }, grid: { color: grid } },
                    x: { ticks: { color: tick }, grid: { display: false } }
                }
            }
        });
        vis('#chartWrap', true);
    }

    function renderSubjects(d) {
        if (!d || !d.subjects || !d.subjects.length) { vis('#subjEmpty', true); return; }
        var html = '';
        d.subjects.forEach(function (s) {
            html += '<tr' + (s.passed ? '' : ' class="rc-fail"') + '>' +
                    '<td>' + ORMS.esc(s.name) + '</td>' +
                    '<td>' + (s.absent ? '<span class="rc-absent">AB</span>' : ORMS.esc(s.marks)) + '</td>' +
                    '<td>' + ORMS.esc(s.percent) + '</td>' +
                    '<td>' + ORMS.esc(s.grade) + '</td></tr>';
        });
        $('#subjBody').html(html);
        $('#subjLabel').text(termLabel(d));
        vis('#subjWrap', true);
    }

    function termLabel(d) { return d ? '— ' + d.term + (d.year ? ' (' + d.year + ')' : '') : ''; }

    // the sheet holds markup rendered by the class's own template server-side,
    // so what shows on screen is byte-identical to what the printer gets
    function renderCard(html, d, k, wh) {
        if (wh) {                                    // withheld for the family — school's wording, no numbers
            $('#cardWithheld').html('<i class="fas fa-lock"></i><h3>Result Withheld</h3><p>' + ORMS.esc(wh.msg) +
                '</p><p class="rc-key-note">' + ORMS.esc(wh.term) + '</p>');
            vis('#cardWithheld', true);
        }
        if (!html) { if (!wh) vis('#cardEmpty', true); return; }
        $('#a4Sheet').html(html);
        ORMS.qr();                                   // fill the card's verify code
        $('#cardLabel').text(termLabel(d));
        if (k && k.term_id) {
            $('#cardOpen').attr('href', 'result_card.php?student_id=' + STUDENT_ID + '&term_id=' + k.term_id);
            vis('#cardOpen', true);
        }
        vis('#cardPrint', true);
        vis('#cardWrap', true);
    }
    </script>
</body>
</html>
