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
requirePerm('timetable', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'timetable';

$canEdit = can('timetable', 'e') || can('timetable', 'a');

// table probe — a db that hasn't taken the migration must not fatal
function ttReady(): bool {
    static $ok = null;
    if ($ok === null) {
        try { qVal("SELECT 1 FROM timetable_slots LIMIT 1"); qVal("SELECT 1 FROM exam_schedule LIMIT 1"); $ok = true; }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function ttHasCol(string $t, string $c): bool {
    static $seen = [];
    $k = "$t.$c";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$c` FROM `$t` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

function ttTenant(): bool { return ttHasCol('classes', 'school_id'); }

function ttOwns(string $table, $raw): int {
    $id = (int)$raw;
    if ($id <= 0) return 0;
    return ttTenant() ? ormsOwns($table, $id) : $id;
}

// classes fence for the outermost FROM
function ttWhere(string $a = 'c'): array {
    if (!ttTenant()) return ['', '', []];
    $b = ttHasCol('classes', 'branch_id') ? bid() : 0;
    return $b ? [" AND $a.school_id = ? AND $a.branch_id = ?", 'ii', [sid(), $b]]
              : [" AND $a.school_id = ?", 'i', [sid()]];
}

// 🚨 THE membership rule of this page: a period or an exam paper may only name a subject the class
// actually studies. ttOwns() proves a subject belongs to the SCHOOL — it says nothing about whether
// THIS class studies it, and the two are not the same fence. Without this, a posted id could put
// Class 5's Chemistry on Class 4's grid, or date a paper no Class 4 student sits.
// id => name for the subjects mapped to one class, memoised per request.
function ttClassSubjects(int $classId): array {
    static $seen = [];
    if ($classId <= 0) return [];
    if (!isset($seen[$classId])) {
        $out = [];
        try {
            foreach (qAll("SELECT sub.id, sub.name FROM class_subjects cs
                           JOIN subjects sub ON sub.id = cs.subject_id
                           WHERE cs.class_id = ?", 'i', $classId) as $r) $out[(int)$r['id']] = $r['name'];
        } catch (Throwable $e) { $out = []; }
        $seen[$classId] = $out;
    }
    return $seen[$classId];
}

// the class a (already tenant-resolved) section belongs to
function ttSectionClass(int $sectionId): int {
    try { return (int) qVal("SELECT class_id FROM sections WHERE id = ?", 'i', $sectionId); }
    catch (Throwable $e) { return 0; }
}

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    try {
        if (!ttReady()) jsonErr('The timetable tables are not installed yet — run update_setup.php once, then reload this page.');

        switch ($action) {

            // ------------------------------------------------------------ lists that drive both tabs
            case 'getTTMeta': {
                requireCsrfJson();
                requirePermJson('timetable', 'v');
                [$w, $wt, $wp] = ttWhere('c');
                $classes = qAll("SELECT c.id, c.name FROM classes c WHERE c.is_active = 1$w
                                 ORDER BY COALESCE(c.numeric_level, c.sort_order) ASC, c.sort_order ASC, c.name ASC", $wt, ...$wp);
                [$w2, $wt2, $wp2] = ttWhere('c');
                $sections = qAll("SELECT s.id, s.class_id, s.name FROM sections s JOIN classes c ON c.id = s.class_id
                                  WHERE s.is_active = 1 AND c.is_active = 1$w2 ORDER BY s.name ASC", $wt2, ...$wp2);
                // subjects per class off the marks config — the timetable can only teach what the class studies
                [$w3, $wt3, $wp3] = ttWhere('c');
                $subs = qAll("SELECT cs.class_id, sub.id, sub.name FROM class_subjects cs
                              JOIN subjects sub ON sub.id = cs.subject_id
                              JOIN classes c    ON c.id = cs.class_id
                              WHERE sub.is_active = 1$w3 ORDER BY cs.sort_order ASC, sub.name ASC", $wt3, ...$wp3);
                $subjects = [];
                foreach ($subs as $s) $subjects[(int)$s['class_id']][] = ['id' => (int)$s['id'], 'name' => $s['name']];

                $tw = ttHasCol('teachers', 'school_id') ? " AND t.school_id = ?" : "";
                $teachers = qAll("SELECT t.id, u.full_name, t.employee_no FROM teachers t JOIN users u ON u.id = t.user_id
                                  WHERE t.status = 'Active'$tw ORDER BY u.full_name ASC",
                                 $tw ? 'i' : '', ...($tw ? [sid()] : []));

                $terms = [];
                $y = ormsCurrentYear();
                if ($y) $terms = ormsTerms((int)$y['id']);
                jsonOk(['classes' => $classes, 'sections' => $sections, 'subjects' => $subjects,
                        'teachers' => $teachers, 'terms' => $terms]);
            }

            // ------------------------------------------------------------ class timetable
            case 'getTimetable': {
                requireCsrfJson();
                requirePermJson('timetable', 'v');
                $sec = ttOwns('sections', $_POST['section_id'] ?? 0);
                if (!$sec) jsonErr('Please choose a section.');
                // `off` marks a period whose subject has since left the class's subject list — the cell is
                // shown flagged rather than hidden, so the user can see what the next save will drop
                $studies = ttClassSubjects(ttSectionClass($sec));
                jsonOk(['section_id' => $sec, 'data' => array_map(fn($r) => $r + ['off' => isset($studies[(int)$r['subject_id']]) ? 0 : 1], qAll(
                    "SELECT ts.day_of_week AS d, ts.period_no AS p, ts.subject_id, ts.teacher_id,
                            ts.start_time, ts.end_time, sub.name AS subject_name, u.full_name AS teacher_name
                     FROM timetable_slots ts
                     LEFT JOIN subjects sub ON sub.id = ts.subject_id
                     LEFT JOIN teachers t   ON t.id = ts.teacher_id
                     LEFT JOIN users u      ON u.id = t.user_id
                     WHERE ts.section_id = ?
                     ORDER BY ts.day_of_week ASC, ts.period_no ASC", 'i', $sec))]);
            }

            case 'saveTimetable': {
                requireCsrfJson();
                requirePermJson('timetable', 'e');
                $sec = ttOwns('sections', $_POST['section_id'] ?? 0);
                if (!$sec) jsonErr('Please choose a section.');
                $slots = json_decode((string)($_POST['slots'] ?? '[]'), true);
                if (!is_array($slots)) jsonErr('Nothing to save');

                // the grid belongs to ONE section, and that section's class decides what may be taught in it
                $studies = ttClassSubjects(ttSectionClass($sec));
                $dropped = [];

                // validate + collect, never fail-fast on shape
                $clean = [];
                foreach ($slots as $s) {
                    $d = (int)($s['d'] ?? 0); $p = (int)($s['p'] ?? 0);
                    $sub = (int)($s['subject_id'] ?? 0) ?: null;
                    $tch = (int)($s['teacher_id'] ?? 0) ?: null;
                    if ($d < 1 || $d > 7 || $p < 1 || $p > 12 || !$sub) continue;   // empty cell = no row
                    $tm = static fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : null;
                    if ($sub && !ttOwns('subjects', $sub)) continue;                 // another school's id drops silently
                    // this school's subject, but not one THIS class studies -> drop the cell and say so.
                    // dropping beats refusing the whole grid: un-mapping a subject would otherwise
                    // leave the section's timetable permanently unsaveable
                    if ($sub && !isset($studies[$sub])) { $dropped[$sub] = true; continue; }
                    if ($tch && !ttOwns('teachers', $tch)) $tch = null;
                    $clean["$d|$p"] = ['d' => $d, 'p' => $p, 'sub' => $sub, 'tch' => $tch,
                                       'st' => $tm($s['start'] ?? ''), 'en' => $tm($s['end'] ?? '')];
                }

                // a teacher can't be in two rooms at once — check against every OTHER section.
                // ONE round trip for the whole grid: a row-constructor IN over every staffed cell,
                // never a select per period (a 6x6 grid was 36 queries a save)
                $clashes = [];
                $staffed = array_values(array_filter($clean, fn($s) => $s['tch']));
                if ($staffed) {
                    // the lookup is fenced to THIS school — a clash message must never name another
                    // tenant's class. it is deliberately NOT fenced by campus: a teacher booked in two
                    // campuses at the same period is still in two rooms at once, so it must still block
                    $sw = ttTenant() ? " AND c.school_id = ?" : "";
                    $t  = 'i' . ($sw ? 'i' : '') . str_repeat('iii', count($staffed));
                    $p  = array_merge([$sec], $sw ? [sid()] : []);
                    foreach ($staffed as $s) array_push($p, $s['tch'], $s['d'], $s['p']);
                    $hits = qAll("SELECT ts.day_of_week, ts.period_no, c.name AS class_name, x.name AS section_name, c.branch_id
                                  FROM timetable_slots ts
                                  JOIN sections x ON x.id = ts.section_id
                                  JOIN classes c  ON c.id = x.class_id
                                  WHERE ts.section_id <> ?" . $sw . "
                                    AND (ts.teacher_id, ts.day_of_week, ts.period_no) IN (" . implode(',', array_fill(0, count($staffed), '(?,?,?)')) . ")
                                  ORDER BY ts.day_of_week ASC, ts.period_no ASC", $t, ...$p);
                    $lock = ormsBranchLock();
                    foreach ($hits as $h) {
                        // a campus admin is told the period is taken without being shown a campus they cannot reach
                        $where = (!$lock || (int)$h['branch_id'] === $lock)
                               ? $h['class_name'] . ' – ' . $h['section_name'] : 'another campus';
                        $clashes[] = "Day {$h['day_of_week']} period {$h['period_no']}: the teacher is already in {$where}";
                    }
                }
                if ($clashes) jsonErr("Teacher clash — nothing was saved:\n" . implode("\n", array_slice($clashes, 0, 6)));

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // the grid saves as a whole: wipe the section, write what the editor holds now
                    qExec("DELETE FROM timetable_slots WHERE section_id = ?", 'i', $sec);
                    if ($clean) {
                        $d = $p = 0; $sub = $tch = $st = $en = null;
                        $ins = $conn->prepare("INSERT INTO timetable_slots (section_id, day_of_week, period_no, subject_id, teacher_id, start_time, end_time)
                                               VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $ins->bind_param("iiiiiss", $sec, $d, $p, $sub, $tch, $st, $en);
                        foreach ($clean as $s) { [$d, $p, $sub, $tch, $st, $en] = [$s['d'], $s['p'], $s['sub'], $s['tch'], $s['st'], $s['en']]; $ins->execute(); }
                        $ins->close();
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('timetable.php saveTimetable: ' . $e->getMessage());
                    jsonErr('The timetable could not be saved — nothing was changed.');
                }
                // name the dropped subjects — they are by definition NOT in $studies, so the label
                // comes from the subject table itself, in one read
                $note = '';
                if ($dropped) {
                    $ids   = array_keys($dropped);
                    $names = array_column(qAll("SELECT name FROM subjects WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
                                               str_repeat('i', count($ids)), ...$ids), 'name');
                    $note  = ' — ' . count($dropped) . ' cell(s) dropped: ' . implode(', ', $names ?: array_map(fn($i) => '#' . $i, $ids))
                           . (count($dropped) === 1 ? ' is' : ' are') . " not on this class's subject list";
                }
                logActivity($user_id, $username, 'Timetable Saved', "Section #$sec: " . count($clean) . ' slot(s)' . ($dropped ? ', ' . count($dropped) . ' off-list subject(s) dropped' : ''));
                jsonOk(['message' => 'Timetable saved — ' . count($clean) . ' period(s) set' . $note, 'dropped' => count($dropped)]);
            }

            // ------------------------------------------------------------ exam schedule
            case 'getExams': {
                requireCsrfJson();
                requirePermJson('timetable', 'v');
                $term = ttOwns('exam_terms', $_POST['term_id'] ?? 0);
                $cls  = ttOwns('classes', $_POST['class_id'] ?? 0);
                if (!$term || !$cls) jsonErr('Please choose a term and a class.');
                // one row per subject the class studies, schedule joined in — unscheduled rows come back blank
                jsonOk(['data' => qAll(
                    "SELECT sub.id AS subject_id, sub.name AS subject_name,
                            es.exam_date, es.start_time, es.end_time, es.room
                     FROM class_subjects cs
                     JOIN subjects sub ON sub.id = cs.subject_id
                     LEFT JOIN exam_schedule es ON es.term_id = ? AND es.class_id = cs.class_id AND es.subject_id = sub.id
                     WHERE cs.class_id = ?
                     ORDER BY es.exam_date ASC, cs.sort_order ASC, sub.name ASC", 'ii', $term, $cls)]);
            }

            case 'saveExams': {
                requireCsrfJson();
                requirePermJson('timetable', 'e');
                $term = ttOwns('exam_terms', $_POST['term_id'] ?? 0);
                $cls  = ttOwns('classes', $_POST['class_id'] ?? 0);
                if (!$term || !$cls) jsonErr('Please choose a term and a class.');
                $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
                if (!is_array($rows) || !$rows) jsonErr('Nothing to save');

                // only papers for subjects THIS class studies. getExams builds its rows from
                // class_subjects, so a paper dated for anything else is invisible on this page yet still
                // surfaces as "next exam" on every dashboard — a ghost nobody can clear from the ui
                $studies = ttClassSubjects($cls);
                $skipped = 0;

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    $sub = 0; $dt = $st = $en = $rm = null;
                    $ins = $conn->prepare("INSERT INTO exam_schedule (term_id, class_id, subject_id, exam_date, start_time, end_time, room)
                                           VALUES (?, ?, ?, ?, ?, ?, ?)
                                           ON DUPLICATE KEY UPDATE exam_date = VALUES(exam_date), start_time = VALUES(start_time),
                                                                   end_time = VALUES(end_time), room = VALUES(room)");
                    $ins->bind_param("iiissss", $term, $cls, $sub, $dt, $st, $en, $rm);
                    $del = $conn->prepare("DELETE FROM exam_schedule WHERE term_id = ? AND class_id = ? AND subject_id = ?");
                    $delSub = 0;
                    $del->bind_param("iii", $term, $cls, $delSub);
                    $set = 0;
                    $tm = static fn($v) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : null;
                    foreach ($rows as $r) {
                        $sub = ttOwns('subjects', $r['subject_id'] ?? 0);
                        if (!$sub || !isset($studies[$sub])) { $skipped++; continue; }
                        $d = (string)($r['date'] ?? '');
                        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $delSub = $sub; $del->execute(); continue; }   // blank date clears the paper
                        $dt = $d;
                        $st = $tm($r['start'] ?? '');
                        $en = $tm($r['end'] ?? '');
                        $rm = mb_substr(trim((string)($r['room'] ?? '')), 0, 40) ?: null;
                        $ins->execute();
                        $set++;
                    }
                    $ins->close();
                    $del->close();
                    // sweep any paper left over from before this class stopped studying a subject —
                    // it can never be seen or cleared from the date sheet, so the save clears it here
                    $ghosts = $studies
                        ? qExec("DELETE FROM exam_schedule WHERE term_id = ? AND class_id = ? AND subject_id NOT IN ("
                                . implode(',', array_fill(0, count($studies), '?')) . ")",
                                'ii' . str_repeat('i', count($studies)), $term, $cls, ...array_keys($studies))
                        : qExec("DELETE FROM exam_schedule WHERE term_id = ? AND class_id = ?", 'ii', $term, $cls);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('timetable.php saveExams: ' . $e->getMessage());
                    jsonErr('The exam schedule could not be saved — nothing was changed.');
                }
                logActivity($user_id, $username, 'Exam Schedule Saved',
                    "Term #$term class #$cls: $set paper(s) dated" . ($skipped ? ", $skipped off-list skipped" : '') . ($ghosts ? ", $ghosts stale paper(s) cleared" : ''));
                jsonOk(['message' => "Exam schedule saved — $set paper(s) dated"
                    . ($skipped ? " · $skipped row(s) skipped (not on this class's subject list)" : '')
                    . ($ghosts ? " · $ghosts stale paper(s) cleared" : ''), 'skipped' => $skipped, 'cleared' => $ghosts]);
            }

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('timetable.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

$ready = ttReady();
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
    <title>Timetable - Result Management</title>

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
                    <h1><i class="fas fa-calendar-days"></i> Timetable</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Timetable</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if (!$ready): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>The timetable is not installed yet</h4>
                    <p>Run <b>update_setup.php</b> once to create the timetable tables, then come back to this page.</p>
                </div>
            </div>
            <?php else: ?>

            <div class="tab-nav no-print">
                <button type="button" class="tab-btn active" data-tab="tabTT"><i class="fas fa-table-cells"></i> Class Timetable</button>
                <button type="button" class="tab-btn" data-tab="tabExam"><i class="fas fa-file-pen"></i> Exam Schedule</button>
            </div>

            <!-- ============================ Class Timetable ============================ -->
            <div class="tab-pane active" id="tabTT">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table-cells"></i> Class Timetable</h2>
                        <div class="btn-group-inline no-print">
                            <button type="button" class="btn btn-primary" onclick="loadTT()"><i class="fas fa-sync"></i> Refresh</button>
                            <button type="button" class="btn btn-secondary" onclick="ORMS.printOnly('#ttPrint')"><i class="fas fa-print"></i> Print</button>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="btnSaveTT" onclick="saveTT(this)"><i class="fas fa-save"></i> Save Timetable</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-banner info-banner-top mb-24 no-print">
                        <i class="fas fa-circle-info"></i>
                        <span><?php echo $canEdit
                            ? 'Pick a section, then <b>click any cell</b> to set its subject, teacher and time. Saving checks that no teacher is booked into two sections at the same period.'
                            : 'Pick a section to see its weekly period plan.'; ?></span>
                    </div>

                    <div class="filters-section no-print">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="ttClass" class="filter-input"><option value="">Select Class</option></select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-layer-group"></i> Section</label>
                                <select id="ttSection" class="filter-input"><option value="">Select Section</option></select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-list-ol"></i> Periods per day</label>
                                <select id="ttPeriods" class="filter-input">
                                    <option>4</option><option>5</option><option>6</option><option value="7">7</option>
                                    <option selected>8</option><option>9</option><option>10</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- one tab per section of the chosen class; the grid is per section, so this is
                         the switch that gets used all day -->
                    <div class="tab-nav no-print initially-hidden" id="ttSecTabs" role="tablist"></div>

                    <div id="ttEmpty" class="orms-empty">
                        <i class="fas fa-table-cells"></i>
                        <h4>No section open</h4>
                        <p>Pick a class and section above to open its weekly timetable.</p>
                    </div>

                    <div id="ttWrap" class="initially-hidden">
                        <div id="ttPrint">
                            <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all days</div>
                            <div class="table-responsive">
                                <table class="att-grid table-full-width tt-table" id="ttGrid"></table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================ Exam Schedule ============================ -->
            <div class="tab-pane" id="tabExam">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-file-pen"></i> Exam Schedule (Date Sheet)</h2>
                        <div class="btn-group-inline no-print">
                            <button type="button" class="btn btn-primary" onclick="loadExams()"><i class="fas fa-sync"></i> Refresh</button>
                            <button type="button" class="btn btn-secondary" onclick="ORMS.printOnly('#exPrint')"><i class="fas fa-print"></i> Print</button>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="btnSaveEx" onclick="saveExams(this)"><i class="fas fa-save"></i> Save Schedule</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-banner info-banner-top mb-24 no-print">
                        <i class="fas fa-circle-info"></i>
                        <span>One row per subject the class studies. <?php echo $canEdit
                            ? 'Set the date (and optionally time and room) for each paper — clearing a date removes that paper from the sheet.'
                            : 'The dated papers make up the printable date sheet.'; ?></span>
                    </div>

                    <div class="filters-section no-print">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-file-pen"></i> Exam Term</label>
                                <select id="exTerm" class="filter-input"><option value="">Select Term</option></select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="exClass" class="filter-input"><option value="">Select Class</option></select>
                            </div>
                        </div>
                    </div>

                    <div id="exEmpty" class="orms-empty">
                        <i class="fas fa-file-pen"></i>
                        <h4>No date sheet open</h4>
                        <p>Pick an exam term and a class above to plan its papers.</p>
                    </div>

                    <div id="exWrap" class="initially-hidden">
                        <div id="exPrint">
                            <div class="table-responsive">
                                <table class="att-grid table-full-width" id="exGrid">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-book"></i> Subject</th>
                                            <th><i class="fas fa-calendar-day"></i> Date</th>
                                            <th><i class="fas fa-clock"></i> Start</th>
                                            <th><i class="fas fa-clock"></i> End</th>
                                            <th><i class="fas fa-door-open"></i> Room</th>
                                        </tr>
                                    </thead>
                                    <tbody id="exBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <?php if ($ready): ?>
    <!-- Slot Editor Modal -->
    <div class="modal-overlay" id="slotModal" role="dialog" aria-modal="true">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="slotTitle"><i class="fas fa-table-cells"></i> Set Period</h3>
                <button type="button" class="close-btn" onclick="closeM('#slotModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-book"></i> Subject</label>
                        <select id="slotSubject"><option value="">— free period —</option></select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-chalkboard-user"></i> Teacher</label>
                        <select id="slotTeacher"><option value="">— unassigned —</option></select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Start Time</label>
                        <input type="time" id="slotStart">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> End Time</label>
                        <input type="time" id="slotEnd">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" onclick="applySlot()"><i class="fas fa-check"></i> Set Period</button>
                    <button type="button" class="btn btn-secondary" onclick="clearSlot()"><i class="fas fa-eraser"></i> Clear Cell</button>
                    <button type="button" class="btn btn-secondary" onclick="closeM('#slotModal')"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>

    <?php if ($ready): ?>
    <script>
    var esc = ORMS.esc;
    var CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    var META = { classes: [], sections: [], subjects: {}, teachers: [], terms: [] };
    var DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var TT = {};              // "d|p" -> {subject_id, teacher_id, start, end, subject_name, teacher_name}
    // 🚨 the editor holds ONE section's grid and the save WIPES that section before rewriting it, so the
    // grid in memory and the section in the dropdown must never be allowed to drift apart. TT_SEC is the
    // section TT was actually loaded for; a save whose target no longer matches it is refused instead of
    // copying one section's timetable over another's
    var TT_SEC = 0;
    var EX = [];              // exam rows
    var EX_KEY = '';          // "term|class" the rows in EX belong to — same guard for the date sheet
    var CUR_CELL = null;      // {d, p} being edited

    $(document).ready(function () {
        ['#ttClass', '#ttSection', '#ttPeriods', '#exTerm', '#exClass', '#slotSubject', '#slotTeacher'].forEach(function (s) { ORMS.dropdown(s); });

        $('.tab-nav').on('click', '.tab-btn', function () {
            var t = this.getAttribute('data-tab');
            // section tabs wear the same .tab-btn look but drive a FILTER, not a pane. Without this
            // guard a section click cleared every .tab-pane's active class and blanked the page.
            if (!t) return;
            $('.tab-nav .tab-btn[data-tab]').removeClass('active');
            $(this).addClass('active');
            $('.tab-pane').removeClass('active');
            $('#' + t).addClass('active');
        });

        $('#ttClass').on('change', function () { resetTT(); fillSections(); });
        $('#ttSection').on('change', function () { paintSecTabs(); loadTT(); });
        // tabs and the Section dropdown are the same choice — whichever is used, both stay in step
        ORMS.sectionTabs.bind('#ttSecTabs', '#ttSection', function () { loadTT(); });
        $('#ttPeriods').on('change', function () { if (!$('#ttWrap').hasClass('initially-hidden')) renderTT(); });
        $('#exTerm, #exClass').on('change', loadExams);
        $('#slotModal').on('click', function (e) { if (e.target === this) closeM(this); });

        // cell click -> editor (edit mode only)
        $('#ttGrid').on('click', 'td.tt-cell', function () {
            if (!CAN_EDIT) return;
            CUR_CELL = { d: +this.getAttribute('data-d'), p: +this.getAttribute('data-p') };
            var cur = TT[CUR_CELL.d + '|' + CUR_CELL.p] || {};
            $('#slotTitle').html('<i class="fas fa-table-cells"></i> ' + DAYS[CUR_CELL.d - 1] + ' — Period ' + CUR_CELL.p);
            fillSlotSubjects();
            $('#slotSubject').val(cur.subject_id || ''); ORMS.dropdown.refresh('#slotSubject');
            $('#slotTeacher').val(cur.teacher_id || ''); ORMS.dropdown.refresh('#slotTeacher');
            $('#slotStart').val(cur.start || '');
            $('#slotEnd').val(cur.end || '');
            $('#slotModal').addClass('active');
        });

        loadMeta();
    });

    function closeM(sel) { $(sel).removeClass('active'); }

    // drop whatever grid is in memory and hide it — called before every load and on a class change,
    // so a failed or pending fetch can never leave the previous section's cells on screen
    function resetTT() {
        TT = {}; TT_SEC = 0; CUR_CELL = null;
        $('#ttGrid').empty();
        $('#ttWrap').addClass('initially-hidden');
        $('#ttEmpty').removeClass('initially-hidden');
    }

    function loadMeta() {
        ORMS.post('getTTMeta', {}).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load'); return; }
            META = res;
            var co = '<option value="">Select Class</option>';
            (META.classes || []).forEach(function (c) { co += '<option value="' + c.id + '">' + esc(c.name) + '</option>'; });
            $('#ttClass').html(co); ORMS.dropdown.refresh('#ttClass');
            $('#exClass').html(co); ORMS.dropdown.refresh('#exClass');
            var to = '<option value="">Select Term</option>';
            (META.terms || []).forEach(function (t) { to += '<option value="' + t.id + '">' + esc(t.name + ' (' + t.status + ')') + '</option>'; });
            $('#exTerm').html(to); ORMS.dropdown.refresh('#exTerm');
            var th = '<option value="">— unassigned —</option>';
            (META.teachers || []).forEach(function (t) { th += '<option value="' + t.id + '">' + esc(t.full_name + ' (' + t.employee_no + ')') + '</option>'; });
            $('#slotTeacher').html(th); ORMS.dropdown.refresh('#slotTeacher');
            fillSections();
        }).fail(function (m) { ORMS.err(m); });
    }

    function fillSections() {
        var cid = parseInt($('#ttClass').val() || 0, 10);
        var h = '<option value="">Select Section</option>';
        (META.sections || []).forEach(function (s) { if (s.class_id == cid) h += '<option value="' + s.id + '">' + esc(s.name) + '</option>'; });
        $('#ttSection').html(h);
        ORMS.dropdown.refresh('#ttSection');
        paintSecTabs();
    }

    // the grid belongs to ONE section, so every section of the class gets a tab — including a lone
    // one, because opening it is otherwise a trip through the dropdown
    function paintSecTabs() {
        var cid = parseInt($('#ttClass').val() || 0, 10);
        ORMS.sectionTabs('#ttSecTabs', '#ttSection',
            (META.sections || []).filter(function (s) { return s.class_id == cid; })
                                 .map(function (s) { return { id: s.id, name: s.name }; }),
            { min: 1 });
    }

    function fillSlotSubjects() {
        var cid = parseInt($('#ttClass').val() || 0, 10);
        var h = '<option value="">— free period —</option>';
        ((META.subjects || {})[cid] || []).forEach(function (s) { h += '<option value="' + s.id + '">' + esc(s.name) + '</option>'; });
        $('#slotSubject').html(h);
        ORMS.dropdown.refresh('#slotSubject');
    }

    // ---------------- class timetable ----------------
    function loadTT() {
        var sec = parseInt($('#ttSection').val() || 0, 10);
        resetTT();                                   // clear FIRST — never show section A while B loads
        if (!sec) return;
        ORMS.post('getTimetable', { section_id: sec }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load'); return; }
            // the user may have moved on while this was in flight — a late reply must not paint
            // itself over whatever section is selected now
            if (parseInt($('#ttSection').val() || 0, 10) !== sec) return;
            TT = {};
            (res.data || []).forEach(function (s) {
                TT[s.d + '|' + s.p] = { subject_id: s.subject_id, teacher_id: s.teacher_id,
                                        start: s.start_time || '', end: s.end_time || '',
                                        subject_name: s.subject_name || '', teacher_name: s.teacher_name || '',
                                        off: s.off ? 1 : 0 };
            });
            TT_SEC = sec;
            $('#ttEmpty').addClass('initially-hidden');
            $('#ttWrap').removeClass('initially-hidden');
            renderTT();
        }).fail(function (m) { ORMS.err(m); });
    }

    function cellHtml(d, p) {
        var s = TT[d + '|' + p];
        if (!s || !s.subject_id) return '<span class="text-muted">&mdash;</span>';
        // a subject that has since left this class's list — shown flagged, and the next save drops it
        var h = '<strong>' + esc(s.subject_name) + '</strong>';
        if (s.off) h += ' <i class="fas fa-triangle-exclamation tt-off" title="Not on this class\'s subject list — this cell is dropped on the next save"></i>';
        if (s.teacher_name) h += '<br><small class="text-muted"><i class="fas fa-chalkboard-user"></i> ' + esc(s.teacher_name) + '</small>';
        if (s.start) h += '<br><small class="text-muted"><i class="fas fa-clock"></i> ' + esc(s.start) + (s.end ? '–' + esc(s.end) : '') + '</small>';
        return h;
    }

    function renderTT() {
        var np = parseInt($('#ttPeriods').val() || 8, 10);
        var h = '<thead><tr><th><i class="fas fa-list-ol"></i> Period</th>';
        DAYS.forEach(function (d) { h += '<th>' + d + '</th>'; });
        h += '</tr></thead><tbody>';
        for (var p = 1; p <= np; p++) {
            h += '<tr><th>Period ' + p + '</th>';
            for (var d = 1; d <= DAYS.length; d++) {
                h += '<td class="tt-cell' + (CAN_EDIT ? ' tt-edit' : '') + '" data-d="' + d + '" data-p="' + p + '"' +
                     (CAN_EDIT ? ' title="Click to set this period" role="button" tabindex="0"' : '') + '>' + cellHtml(d, p) + '</td>';
            }
            h += '</tr>';
        }
        $('#ttGrid').html(h + '</tbody>');
    }

    function applySlot() {
        if (!CUR_CELL) return;
        var sid = parseInt($('#slotSubject').val() || 0, 10);
        var opt = function (sel) { return $(sel + ' option:selected').text(); };
        TT[CUR_CELL.d + '|' + CUR_CELL.p] = sid ? {
            subject_id: sid, teacher_id: parseInt($('#slotTeacher').val() || 0, 10) || null,
            start: $('#slotStart').val() || '', end: $('#slotEnd').val() || '',
            subject_name: opt('#slotSubject'),
            teacher_name: parseInt($('#slotTeacher').val() || 0, 10) ? opt('#slotTeacher').replace(/\s*\([^)]*\)$/, '') : ''
        } : undefined;
        if (!sid) delete TT[CUR_CELL.d + '|' + CUR_CELL.p];
        closeM('#slotModal');
        renderTT();
    }

    function clearSlot() {
        if (CUR_CELL) delete TT[CUR_CELL.d + '|' + CUR_CELL.p];
        closeM('#slotModal');
        renderTT();
    }

    function saveTT(btn) {
        var sec = parseInt($('#ttSection').val() || 0, 10);
        if (!sec) { ORMS.err('Pick a section first'); return; }
        // the grid on screen belongs to TT_SEC. saving it against a different section would wipe that
        // section and write this one's periods into it
        if (sec !== TT_SEC) { ORMS.err('This grid belongs to another section — reopening it now.', 'Not saved'); loadTT(); return; }
        var slots = [];
        Object.keys(TT).forEach(function (k) {
            var s = TT[k], dp = k.split('|');
            slots.push({ d: +dp[0], p: +dp[1], subject_id: s.subject_id, teacher_id: s.teacher_id || 0, start: s.start, end: s.end });
        });
        ORMS.post('saveTimetable', { section_id: sec, slots: JSON.stringify(slots) }, { btn: btn, busyLabel: 'Saving…' })
            .done(function (res) {
                if (!res.success) { ORMS.err(res.message, 'Not saved'); return; }
                ORMS.ok(res.message);
                loadTT();
            }).fail(function (m) { ORMS.err(m); });
    }

    // ---------------- exam schedule ----------------
    function loadExams() {
        var term = parseInt($('#exTerm').val() || 0, 10), cls = parseInt($('#exClass').val() || 0, 10);
        EX = []; EX_KEY = ''; $('#exBody').empty();
        $('#exWrap').addClass('initially-hidden'); $('#exEmpty').removeClass('initially-hidden');
        if (!term || !cls) return;
        ORMS.post('getExams', { term_id: term, class_id: cls }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load'); return; }
            if (parseInt($('#exTerm').val() || 0, 10) !== term || parseInt($('#exClass').val() || 0, 10) !== cls) return;
            EX = res.data || [];
            EX_KEY = term + '|' + cls;
            $('#exEmpty').addClass('initially-hidden');
            $('#exWrap').removeClass('initially-hidden');
            var h = '';
            EX.forEach(function (r, i) {
                h += '<tr data-i="' + i + '"><td><strong>' + esc(r.subject_name) + '</strong></td>' +
                     '<td><input type="date" class="att-in ex-date" value="' + esc(r.exam_date || '') + '"' + (CAN_EDIT ? '' : ' disabled') + '></td>' +
                     '<td><input type="time" class="att-in ex-start" value="' + esc(r.start_time || '') + '"' + (CAN_EDIT ? '' : ' disabled') + '></td>' +
                     '<td><input type="time" class="att-in ex-end" value="' + esc(r.end_time || '') + '"' + (CAN_EDIT ? '' : ' disabled') + '></td>' +
                     '<td><input type="text" class="att-in ex-room" maxlength="40" value="' + esc(r.room || '') + '"' + (CAN_EDIT ? '' : ' disabled') + ' placeholder="—"></td></tr>';
            });
            $('#exBody').html(h);
        }).fail(function (m) { ORMS.err(m); });
    }

    function saveExams(btn) {
        var term = parseInt($('#exTerm').val() || 0, 10), cls = parseInt($('#exClass').val() || 0, 10);
        if (!term || !cls) { ORMS.err('Pick a term and class first'); return; }
        // the rows on screen were built for EX_KEY's term and class — posting them under a different
        // pair would date another class's papers
        if (EX_KEY !== term + '|' + cls) { ORMS.err('This date sheet belongs to another term or class — reopening it now.', 'Not saved'); loadExams(); return; }
        var rows = [];
        $('#exBody tr').each(function () {
            var i = +this.getAttribute('data-i');
            rows.push({ subject_id: EX[i].subject_id,
                        date: $(this).find('.ex-date').val(), start: $(this).find('.ex-start').val(),
                        end: $(this).find('.ex-end').val(), room: $(this).find('.ex-room').val() });
        });
        ORMS.post('saveExams', { term_id: term, class_id: cls, rows: JSON.stringify(rows) }, { btn: btn, busyLabel: 'Saving…' })
            .done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                ORMS.ok(res.message);
                loadExams();
            }).fail(function (m) { ORMS.err(m); });
    }
    </script>
    <?php endif; ?>
</body>
</html>
