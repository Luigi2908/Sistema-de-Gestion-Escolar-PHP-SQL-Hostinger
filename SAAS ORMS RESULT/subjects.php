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
requirePerm('subjects', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'subjects';

// tolerant counter — a table that doesn't exist yet can't be blocking anything
function subCount(string $sql, string $types = '', ...$p): int {
    try { return (int)qVal($sql, $types, ...$p); } catch (Throwable $e) { return 0; }
}

// [label => n] -> "4 class mapping(s), 96 marks record(s)"
function subBlockers(array $counts): string {
    $parts = [];
    foreach ($counts as $label => $n) if ($n > 0) $parts[] = $n . ' ' . $label;
    return implode(', ', $parts);
}

// column probe, one per request — an install that hasn't taken the migration keeps the old behaviour
function subHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

// tenant columns all land in one migration step, so one probe answers for the lot
function subTenant(): bool { return subHasCol('subjects', 'school_id'); }

// school fence for an alias -> [sql, types, params]. empty on a pre-migration db
function subWhere(string $a = 's'): array {
    return subTenant() ? [" AND $a.school_id = ?", 'i', [sid()]] : ['', '', []];
}

// classes fence, branch included — sections/subject maps hang off classes, never off school_id of their own
function subClassWhere(string $a = 'c'): array {
    if (!subTenant()) return ['', '', []];
    $b = subHasCol('classes', 'branch_id') ? bid() : 0;
    return $b ? [" AND $a.school_id = ? AND $a.branch_id = ?", 'ii', [sid(), $b]]
              : [" AND $a.school_id = ?", 'i', [sid()]];
}

// posted id -> verified row id, 0 when another school owns it. pre-migration has nothing to check against
function subOwns(string $table, $raw): int {
    $id = (int)$raw;
    if ($id <= 0) return 0;
    return subTenant() ? ormsOwns($table, $id) : $id;
}

// current year, but only if it is OURS — the shared lookup is still school-blind
function subYear(): ?array {
    $cy = ormsCurrentYear();
    if (!$cy) return null;
    return (!subTenant() || ormsFindYear((int)$cy['id'])) ? $cy : null;
}

// total/passing rules live here so add + edit can never drift apart
function subMarksCheck($totalRaw, $passRaw): array {
    if (!is_numeric($totalRaw) || !is_numeric($passRaw)) return [0, 0, 'Total and passing marks must be numbers'];
    $total = round((float)$totalRaw, 2);
    $pass  = round((float)$passRaw, 2);
    if ($total <= 0)      return [0, 0, 'Total marks must be greater than 0'];
    if ($total > 9999.99) return [0, 0, 'Total marks cannot exceed 9999.99'];
    if ($pass <= 0)       return [0, 0, 'Passing marks must be greater than 0'];
    if ($pass >= $total)  return [0, 0, 'Passing marks must be less than total marks'];
    return [$total, $pass, ''];
}

// theory + practical split. both set -> must add up to total. either blank -> not split (both stored null).
// returns [theory, practical, err, droppedHalf]
function subSplitCheck(float $total, $theoryRaw, $pracRaw): array {
    $t = is_string($theoryRaw) ? trim($theoryRaw) : $theoryRaw;
    $p = is_string($pracRaw)   ? trim($pracRaw)   : $pracRaw;
    $tSet = ($t !== '' && $t !== null);
    $pSet = ($p !== '' && $p !== null);
    if (!$tSet || !$pSet) return [null, null, '', ($tSet || $pSet)];   // half a split is no split

    if (!is_numeric($t) || !is_numeric($p)) return [null, null, 'Theory and practical marks must be numbers', false];
    $t = round((float)$t, 2);
    $p = round((float)$p, 2);
    if ($t < 0 || $p < 0)              return [null, null, 'Theory and practical marks cannot be negative', false];
    if ($t > 9999.99 || $p > 9999.99)  return [null, null, 'Theory and practical marks cannot exceed 9999.99', false];
    $sum = round($t + $p, 2);
    if (abs($sum - $total) > 0.01) {
        return [null, null, "Theory + Practical must equal the total marks — $t + $p = $sum, but the total is $total. "
                          . 'Fix the split, or clear both boxes to leave the subject un-split.', false];
    }
    return [$t, $p, '', false];
}

// one wording for the "you filled only half a split" case
const SUB_SPLIT_DROPPED = ' — only one side of the theory/practical split was filled, so the split was cleared (both boxes are needed).';

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    try {
        switch ($action) {

            // --------------------------------------------------------- subjects
            case 'getSubjects':
                // the mapping count rides the same fence as the driver, or a branch admin counts other branches
                [$cw, $ct, $cp] = subClassWhere('c');
                [$w,  $wt, $wp] = subWhere('s');
                $cj = $cw !== '' ? " JOIN classes c ON c.id = cs.class_id" : "";
                jsonOk(['data' => qAll(
                    "SELECT s.id, s.name, s.code, s.short_name, s.subject_type, s.include_in_total, s.is_active,
                            (SELECT COUNT(*) FROM class_subjects cs$cj WHERE cs.subject_id = s.id$cw) AS classes
                     FROM subjects s
                     WHERE 1 = 1$w
                     ORDER BY s.name ASC",
                    $ct . $wt, ...array_merge($cp, $wp)
                )]);

            case 'saveSubject':
                requireCsrfJson();
                $raw = (int)($_POST['id'] ?? 0);
                requirePermJson('subjects', $raw ? 'e' : 'a');      // perm follows what was ASKED for
                $id = subOwns('subjects', $raw);
                if ($raw && !$id) jsonErr('Subject not found');     // a foreign id must never become an insert

                $name  = trim($_POST['name'] ?? '');
                $code  = strtoupper(trim($_POST['code'] ?? ''));   // codes are always uppercase
                $short = trim($_POST['short_name'] ?? '');
                $stype = $_POST['subject_type'] ?? 'Core';
                $inTot = !empty($_POST['include_in_total']) ? 1 : 0;
                $activ = !empty($_POST['is_active']) ? 1 : 0;

                if ($name === '')                       jsonErr('Subject name is required');
                if (mb_strlen($name) > 100)             jsonErr('Subject name must be 100 characters or less');
                if ($code === '')                       jsonErr('Subject code is required');
                if (mb_strlen($code) > 20)              jsonErr('Subject code must be 20 characters or less');
                if (!preg_match('/^[A-Z0-9\-_]+$/', $code)) jsonErr('Subject code may use letters, numbers, hyphen and underscore only');
                if (mb_strlen($short) > 20)             jsonErr('Short name must be 20 characters or less');
                if (!in_array($stype, ['Core', 'Elective', 'Optional'], true)) jsonErr('Subject type must be Core, Elective or Optional');

                // uniq_school_subject — per school, or school B could never register its own code
                $uw = subTenant() ? " AND school_id = ?" : "";
                $ut = 'si' . ($uw ? 'i' : '');
                $ua = $uw ? [sid()] : [];
                if (qVal("SELECT id FROM subjects WHERE code = ? AND id <> ?$uw", $ut, $code, $id, ...$ua))
                    jsonErr('Subject code "' . $code . '" is already used by another subject');
                if (qVal("SELECT id FROM subjects WHERE name = ? AND id <> ?$uw", $ut, $name, $id, ...$ua))
                    jsonErr('A subject named "' . $name . '" already exists');

                $shortV = $short !== '' ? $short : null;            // blank -> null, card falls back to the code
                $tail   = " [$stype" . ($inTot ? '' : ', excluded from total') . ']';

                if ($id) {
                    qExec("UPDATE subjects SET name = ?, code = ?, short_name = ?, subject_type = ?, include_in_total = ?, is_active = ? WHERE id = ?",
                          'ssssiii', $name, $code, $shortV, $stype, $inTot, $activ, $id);
                    logActivity($user_id, $username, 'Subject Updated', "Updated subject: $name ($code)$tail");
                    jsonOk(['message' => 'Subject updated successfully']);
                }
                // 6 cols: s name, s code, s short, s type, i in_total, i active [+ i school]
                $sw = subTenant() ? ", school_id" : "";
                $id = qInsert("INSERT INTO subjects (name, code, short_name, subject_type, include_in_total, is_active$sw)
                               VALUES (?, ?, ?, ?, ?, ?" . ($sw ? ", ?" : "") . ")",
                              'ssssii' . ($sw ? 'i' : ''), $name, $code, $shortV, $stype, $inTot, $activ, ...($sw ? [sid()] : []));
                logActivity($user_id, $username, 'Subject Created', "Created subject: $name ($code)$tail #$id");
                jsonOk(['message' => 'Subject added successfully']);

            case 'toggleSubject':
                requireCsrfJson();
                requirePermJson('subjects', 'e');
                $id  = subOwns('subjects', $_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name, is_active FROM subjects WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Subject not found');

                $new = (int)$row['is_active'] === 1 ? 0 : 1;
                qExec("UPDATE subjects SET is_active = ? WHERE id = ?", 'ii', $new, $id);
                logActivity($user_id, $username, 'Subject Updated', "Set subject {$row['name']} " . ($new ? 'active' : 'inactive'));
                jsonOk(['message' => 'Subject marked ' . ($new ? 'active' : 'inactive')]);

            case 'deleteSubject':
                requireCsrfJson();
                requirePermJson('subjects', 'd');
                $id  = subOwns('subjects', $_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name FROM subjects WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Subject not found');

                // count first — an FK error must never surface raw
                $blocked = subBlockers([
                    'class mapping(s)'      => subCount("SELECT COUNT(*) FROM class_subjects WHERE subject_id = ?", 'i', $id),
                    'marks record(s)'       => subCount("SELECT COUNT(*) FROM marks WHERE subject_id = ?", 'i', $id),
                    'teacher assignment(s)' => subCount("SELECT COUNT(*) FROM teacher_subjects WHERE subject_id = ?", 'i', $id),
                ]);
                if ($blocked !== '')
                    jsonErr('"' . $row['name'] . '" cannot be deleted — it still has ' . $blocked .
                            '. Deactivate the subject instead to keep it out of new entries without losing history.');

                qExec("DELETE FROM subjects WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Subject Deleted', "Deleted subject: {$row['name']} (#$id)");
                jsonOk(['message' => 'Subject deleted successfully']);

            // ------------------------------------------- per-class marks config
            case 'getClassSubjects':
                $classId = subOwns('classes', $_POST['class_id'] ?? ($_GET['class_id'] ?? 0));
                if (!$classId) jsonErr('Please select a class');

                $cls = qOne("SELECT id, name FROM classes WHERE id = ?", 'i', $classId);
                if (!$cls) jsonErr('Class not found');

                // assigned rows + how many marks already reference each mapping (blocks removal)
                $rows = qAll(
                    "SELECT cs.id, cs.subject_id, cs.total_marks, cs.passing_marks, cs.sort_order,
                            cs.theory_marks, cs.practical_marks, cs.include_in_total, cs.is_optional,
                            s.name AS subject_name, s.code AS subject_code, s.short_name, s.subject_type,
                            s.include_in_total AS subject_in_total, s.is_active,
                            (SELECT COUNT(*) FROM marks m WHERE m.class_id = cs.class_id AND m.subject_id = cs.subject_id) AS marks_count
                     FROM class_subjects cs
                     JOIN subjects s ON s.id = cs.subject_id
                     WHERE cs.class_id = ?
                     ORDER BY cs.sort_order ASC, s.name ASC", 'i', $classId);

                // only active, not-yet-mapped subjects OF THIS SCHOOL can be added — the picker is a tenant boundary too
                $aw = subTenant() ? " AND school_id = ?" : "";
                $avail = qAll(
                    "SELECT id, name, code, subject_type, include_in_total FROM subjects
                     WHERE is_active = 1$aw AND id NOT IN (SELECT subject_id FROM class_subjects WHERE class_id = ?)
                     ORDER BY name ASC",
                    ($aw ? 'i' : '') . 'i', ...array_merge($aw ? [sid()] : [], [$classId]));

                jsonOk(['data' => $rows, 'available' => $avail, 'class' => $cls]);

            case 'assignSubject':
                requireCsrfJson();
                requirePermJson('subjects', 'a');

                $classId   = subOwns('classes',  $_POST['class_id'] ?? 0);
                $subjectId = subOwns('subjects', $_POST['subject_id'] ?? 0);
                $sort      = (int)($_POST['sort_order'] ?? 0);
                if (!$classId || !$subjectId) jsonErr('Class and subject are both required');
                if ($sort < 0 || $sort > 9999) jsonErr('Sort order must be between 0 and 9999');

                [$total, $pass, $err] = subMarksCheck($_POST['total_marks'] ?? '', $_POST['passing_marks'] ?? '');
                if ($err !== '') jsonErr($err);

                [$theory, $prac, $serr, $dropped] = subSplitCheck($total, $_POST['theory_marks'] ?? '', $_POST['practical_marks'] ?? '');
                if ($serr !== '') jsonErr($serr);
                $inTot = !empty($_POST['include_in_total']) ? 1 : 0;
                $isOpt = !empty($_POST['is_optional']) ? 1 : 0;

                $cls = qOne("SELECT name FROM classes WHERE id = ?", 'i', $classId);
                $sub = qOne("SELECT name, code, is_active FROM subjects WHERE id = ?", 'i', $subjectId);
                if (!$cls) jsonErr('Class not found');
                if (!$sub) jsonErr('Subject not found');
                if ((int)$sub['is_active'] !== 1) jsonErr('"' . $sub['name'] . '" is inactive — activate it before mapping it to a class');

                // uniq_class_subject (class_id, subject_id)
                if (qVal("SELECT id FROM class_subjects WHERE class_id = ? AND subject_id = ?", 'ii', $classId, $subjectId))
                    jsonErr($sub['name'] . ' is already assigned to ' . $cls['name']);

                qInsert("INSERT INTO class_subjects (class_id, subject_id, total_marks, passing_marks, sort_order, theory_marks, practical_marks, include_in_total, is_optional)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        'iiddiddii', $classId, $subjectId, $total, $pass, $sort, $theory, $prac, $inTot, $isOpt);
                logActivity($user_id, $username, 'Class Subject Assigned',
                            "Assigned {$sub['name']} to {$cls['name']} @ $total/$pass"
                            . ($theory !== null ? " (theory $theory + practical $prac)" : '')
                            . ($inTot ? '' : ' [excluded from total]') . ($isOpt ? ' [optional]' : ''));
                jsonOk(['message' => $sub['name'] . ' assigned to ' . $cls['name'] . ($dropped ? SUB_SPLIT_DROPPED : '')]);

            case 'updateClassSubject':
                requireCsrfJson();
                requirePermJson('subjects', 'e');

                $csId = subOwns('class_subjects', $_POST['id'] ?? 0);   // resolved through its class
                $row  = $csId ? qOne(
                    "SELECT cs.class_id, cs.subject_id, cs.is_optional, c.name AS class_name, s.name AS subject_name
                     FROM class_subjects cs
                     JOIN classes c  ON c.id = cs.class_id
                     JOIN subjects s ON s.id = cs.subject_id
                     WHERE cs.id = ?", 'i', $csId) : null;
                if (!$row) jsonErr('Assignment not found');

                [$total, $pass, $err] = subMarksCheck($_POST['total_marks'] ?? '', $_POST['passing_marks'] ?? '');
                if ($err !== '') jsonErr($err);

                [$theory, $prac, $serr, $dropped] = subSplitCheck($total, $_POST['theory_marks'] ?? '', $_POST['practical_marks'] ?? '');
                if ($serr !== '') jsonErr($serr);
                $inTot = !empty($_POST['include_in_total']) ? 1 : 0;
                $isOpt = !empty($_POST['is_optional']) ? 1 : 0;

                // marks rows keep the total they were saved with — config edits never rewrite history
                qExec("UPDATE class_subjects SET total_marks = ?, passing_marks = ?, theory_marks = ?, practical_marks = ?, include_in_total = ?, is_optional = ? WHERE id = ?",
                      'ddddiii', $total, $pass, $theory, $prac, $inTot, $isOpt, $csId);

                // flipping core -> optional must not change anyone's result: enrol the whole roster
                // (plus anyone holding marks in it), then the school un-enrols the skippers via "Students"
                $autoEnrolled = 0;
                if ($isOpt === 1 && (int)$row['is_optional'] === 0 && ormsHasElectives()) {
                    $cy = subYear();                                 // never enrol against another school's year
                    if ($cy) {
                        $autoEnrolled += qExec("INSERT IGNORE INTO student_subjects (student_id, class_id, subject_id, academic_year_id, created_by)
                                                SELECT st.id, st.class_id, ?, ?, ? FROM students st
                                                WHERE st.class_id = ? AND st.status = 'Active'",
                                               'iiii', (int)$row['subject_id'], (int)$cy['id'], $user_id, (int)$row['class_id']);
                    }
                    qExec("INSERT IGNORE INTO student_subjects (student_id, class_id, subject_id, academic_year_id, created_by)
                           SELECT DISTINCT m.student_id, m.class_id, m.subject_id, m.academic_year_id, ? FROM marks m
                           WHERE m.class_id = ? AND m.subject_id = ?",
                          'iii', $user_id, (int)$row['class_id'], (int)$row['subject_id']);
                }

                logActivity($user_id, $username, 'Class Subject Updated',
                            "{$row['class_name']} / {$row['subject_name']} set to $total total, $pass passing"
                            . ($theory !== null ? " (theory $theory + practical $prac)" : ', no theory/practical split')
                            . ($inTot ? '' : ' [excluded from total]') . ($isOpt ? ' [optional]' : ''));

                $prior = subCount("SELECT COUNT(*) FROM marks WHERE class_id = ? AND subject_id = ?", 'ii', (int)$row['class_id'], (int)$row['subject_id']);
                jsonOk(['message' => 'Marks configuration saved' .
                        ($prior > 0 ? " — $prior already-entered mark(s) keep their original total" : '') .
                        ($autoEnrolled > 0 ? ". Every current student was auto-enrolled — open \"Students\" to un-enrol the ones who skip it" : '') .
                        ($dropped ? SUB_SPLIT_DROPPED : '')]);

            // ------------------------------------------- elective enrolment (optional subjects, current year)
            case 'getElectiveStudents':
                $csId = subOwns('class_subjects', $_POST['id'] ?? 0);   // resolved through its class
                $row  = $csId ? qOne(
                    "SELECT cs.class_id, cs.subject_id, cs.is_optional, c.name AS class_name, s.name AS subject_name
                     FROM class_subjects cs JOIN classes c ON c.id = cs.class_id JOIN subjects s ON s.id = cs.subject_id
                     WHERE cs.id = ?", 'i', $csId) : null;
                if (!$row) jsonErr('Assignment not found');
                if ((int)$row['is_optional'] !== 1) jsonErr('This subject is not optional — every student of the class takes it');
                $cy = subYear();
                if (!$cy) jsonErr('Set a current academic year in Result Settings first');
                $cyId = (int)$cy['id'];

                $rw = subHasCol('students', 'school_id') ? " AND st.school_id = ?" : "";
                $students = qAll(
                    "SELECT st.id, st.roll_no, st.admission_no, u.full_name, u.username, sec.name AS section_name,
                            EXISTS(SELECT 1 FROM student_subjects ss
                                    WHERE ss.student_id = st.id AND ss.subject_id = ? AND ss.academic_year_id = ?) AS enrolled,
                            (SELECT COUNT(*) FROM marks m
                              WHERE m.student_id = st.id AND m.subject_id = ? AND m.academic_year_id = ?) AS marks_count
                     FROM students st
                     JOIN users u      ON u.id = st.user_id
                     JOIN sections sec ON sec.id = st.section_id
                     WHERE st.class_id = ? AND st.status = 'Active'$rw
                     ORDER BY sec.name ASC, CAST(st.roll_no AS UNSIGNED) ASC, st.roll_no ASC, u.full_name ASC",
                    'iiiii' . ($rw ? 'i' : ''), (int)$row['subject_id'], $cyId, (int)$row['subject_id'], $cyId,
                    (int)$row['class_id'], ...($rw ? [sid()] : []));

                jsonOk(['data' => $students, 'year' => $cy['name'],
                        'class' => $row['class_name'], 'subject' => $row['subject_name']]);

            case 'saveElectiveStudents':
                requireCsrfJson();
                requirePermJson('subjects', 'e');

                $csId = subOwns('class_subjects', $_POST['id'] ?? 0);   // resolved through its class
                $ids  = json_decode($_POST['student_ids'] ?? '[]', true);
                if (!is_array($ids)) jsonErr('Bad student list');
                $row = $csId ? qOne(
                    "SELECT cs.class_id, cs.subject_id, cs.is_optional, c.name AS class_name, s.name AS subject_name
                     FROM class_subjects cs JOIN classes c ON c.id = cs.class_id JOIN subjects s ON s.id = cs.subject_id
                     WHERE cs.id = ?", 'i', $csId) : null;
                if (!$row) jsonErr('Assignment not found');
                if ((int)$row['is_optional'] !== 1) jsonErr('This subject is not optional');
                $cy = subYear();
                if (!$cy) jsonErr('Set a current academic year first');
                $cyId = (int)$cy['id'];
                $subjectId = (int)$row['subject_id'];
                $classId   = (int)$row['class_id'];

                // roster bounds the whole edit — a posted id outside this class (or school) is silently dropped
                $rw = subHasCol('students', 'school_id') ? " AND school_id = ?" : "";
                $rosterSet = array_flip(array_map('intval', array_column(
                    qAll("SELECT id FROM students WHERE class_id = ? AND status = 'Active'$rw",
                         'i' . ($rw ? 'i' : ''), $classId, ...($rw ? [sid()] : [])), 'id')));
                $want = [];
                foreach ($ids as $i) { $i = (int)$i; if (isset($rosterSet[$i])) $want[$i] = 1; }
                $have = array_flip(array_map('intval', array_column(
                    qAll("SELECT ss.student_id FROM student_subjects ss
                          JOIN students st ON st.id = ss.student_id AND st.class_id = ? AND st.status = 'Active'
                          WHERE ss.subject_id = ? AND ss.academic_year_id = ?", 'iii', $classId, $subjectId, $cyId), 'student_id')));

                $added = $removed = 0; $blocked = [];
                foreach (array_keys(array_diff_key($want, $have)) as $sid) {
                    $added += qExec("INSERT IGNORE INTO student_subjects (student_id, class_id, subject_id, academic_year_id, created_by)
                                     VALUES (?, ?, ?, ?, ?)", 'iiiii', $sid, $classId, $subjectId, $cyId, $user_id);
                }
                foreach (array_keys(array_diff_key($have, $want)) as $sid) {
                    // marks already entered this year -> un-enrolling would hide a real score. skip-and-collect
                    if (subCount("SELECT COUNT(*) FROM marks WHERE student_id = ? AND subject_id = ? AND academic_year_id = ?",
                                 'iii', $sid, $subjectId, $cyId) > 0) {
                        $blocked[] = (string)(qVal("SELECT u.full_name FROM students st JOIN users u ON u.id = st.user_id WHERE st.id = ?", 'i', $sid) ?? ('#' . $sid));
                        continue;
                    }
                    $removed += qExec("DELETE FROM student_subjects WHERE student_id = ? AND subject_id = ? AND academic_year_id = ?",
                                      'iii', $sid, $subjectId, $cyId);
                }

                logActivity($user_id, $username, 'Elective Enrolment Updated',
                            "{$row['class_name']} / {$row['subject_name']} ({$cy['name']}) — $added enrolled, $removed removed"
                            . ($blocked ? ', blocked: ' . implode(', ', $blocked) : ''));
                jsonOk(['message' => "$added student(s) enrolled, $removed removed"
                        . ($blocked ? '. Kept (marks already entered): ' . implode(', ', $blocked) : '')]);

            case 'removeClassSubject':
                requireCsrfJson();
                requirePermJson('subjects', 'd');

                $csId = subOwns('class_subjects', $_POST['id'] ?? 0);   // resolved through its class
                $row  = $csId ? qOne(
                    "SELECT cs.class_id, cs.subject_id, c.name AS class_name, s.name AS subject_name
                     FROM class_subjects cs
                     JOIN classes c  ON c.id = cs.class_id
                     JOIN subjects s ON s.id = cs.subject_id
                     WHERE cs.id = ?", 'i', $csId) : null;
                if (!$row) jsonErr('Assignment not found');

                $classId   = (int)$row['class_id'];
                $subjectId = (int)$row['subject_id'];

                $marks = subCount("SELECT COUNT(*) FROM marks WHERE class_id = ? AND subject_id = ?", 'ii', $classId, $subjectId);
                if ($marks > 0)
                    jsonErr("{$row['subject_name']} cannot be removed from {$row['class_name']} — $marks marks record(s) already exist for it. " .
                            'Deactivate the subject or leave it mapped so those results stay readable.');

                $assigned = subCount("SELECT COUNT(*) FROM teacher_subjects WHERE class_id = ? AND subject_id = ?", 'ii', $classId, $subjectId);
                if ($assigned > 0)
                    jsonErr("{$row['subject_name']} is still assigned to $assigned teacher(s) in {$row['class_name']} — clear those assignments first.");

                qExec("DELETE FROM class_subjects WHERE id = ?", 'i', $csId);
                logActivity($user_id, $username, 'Class Subject Removed',
                            "Removed {$row['subject_name']} from {$row['class_name']}");
                jsonOk(['message' => $row['subject_name'] . ' removed from ' . $row['class_name']]);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('subjects.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

$classList = [];
try {
    [$w, $wt, $wp] = subClassWhere('c');
    $classList = qAll("SELECT c.id, c.name FROM classes c WHERE c.is_active = 1$w ORDER BY c.sort_order ASC, c.name ASC", $wt, ...$wp);
} catch (Throwable $e) {}
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
    <title>Subjects - Result Management</title>

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
                    <h1><i class="fas fa-book"></i> Subjects</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Subjects</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="tab-nav">
                <button type="button" class="tab-btn active" data-tab="tabSubjects"><i class="fas fa-book"></i> Subjects</button>
                <button type="button" class="tab-btn" data-tab="tabAssign"><i class="fas fa-diagram-project"></i> Assign to Class</button>
            </div>

            <!-- ============================ Subjects ============================ -->
            <div class="tab-pane active" id="tabSubjects">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table"></i> Subject List</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" id="btnRefreshSubjects"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if (can('subjects', 'a')): ?>
                            <button class="btn btn-success" id="btnAddSubject"><i class="fas fa-plus"></i> Add Subject</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters-section">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button type="button" class="btn btn-secondary btn-sm" id="btnClearSubjectFilter"><i class="fas fa-eraser"></i> Clear</button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-layer-group"></i> Subject Type</label>
                                <select id="filterSubjectType" class="filter-input">
                                    <option value="">All Types</option>
                                    <option value="Core">Core</option>
                                    <option value="Elective">Elective</option>
                                    <option value="Optional">Optional</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-calculator"></i> Counts in Total</label>
                                <select id="filterSubjectTotal" class="filter-input">
                                    <option value="">All Subjects</option>
                                    <option value="1">Counted</option>
                                    <option value="0">Not counted</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="filterSubjectStatus" class="filter-input">
                                    <option value="">All Status</option>
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="info-banner info-banner-top">
                        <i class="fas fa-circle-info"></i>
                        <span>Subject codes are stored in uppercase and must be unique. The <b>short name</b> is what narrow result-card columns print instead of the full name. A subject in use by any class, teacher or marks record cannot be deleted — deactivate it instead.</span>
                    </div>

                    <div class="info-banner info-banner-top info-banner-warning mb-24">
                        <i class="fas fa-calculator"></i>
                        <span><b>Include in Total, off:</b> the subject is still graded and still printed on the result card — it is only left out of the <b>total, percentage, GPA and pass/fail</b>.</span>
                    </div>

                    <div id="subjectsSkeleton">
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

                    <div id="subjectsWrap" class="initially-hidden">
                        <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="table-responsive">
                            <table id="subjectsTable" class="display table-full-width"></table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================= Assign to Class ========================= -->
            <div class="tab-pane" id="tabAssign">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-sliders"></i> Per-Class Marks Configuration</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" id="btnRefreshAssign"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if (can('subjects', 'a')): ?>
                            <button class="btn btn-success" id="btnAddAssign"><i class="fas fa-plus"></i> Add Subject to Class</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-banner info-banner-top">
                        <i class="fas fa-clock-rotate-left"></i>
                        <span><b>Marks already entered keep their original total.</b> Each marks record stores the total it was saved with, so changing <em>Total Marks</em> here only affects entries made from now on — past results and printed cards never change.</span>
                    </div>

                    <div class="info-banner info-banner-top info-banner-warning">
                        <i class="fas fa-calculator"></i>
                        <span><b>Include in Total, off:</b> the subject is still graded and still printed on the result card — it is only left out of the <b>total, percentage, GPA and pass/fail</b>.</span>
                    </div>

                    <div class="info-banner info-banner-top mb-24">
                        <i class="fas fa-scale-balanced"></i>
                        <span><b>Theory + Practical</b> must add up to <em>Total Marks</em>. Leave either box blank for a subject that is not split. <b>Optional</b> marks a subject a student may choose to skip.</span>
                    </div>

                    <div class="filters-section">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Choose Class</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="assignClass" class="filter-input">
                                    <option value="">Select a class</option>
                                    <?php foreach ($classList as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="assignBody">
                        <div class="orms-empty">
                            <i class="fas fa-diagram-project"></i>
                            <h4>Pick a class to begin</h4>
                            <p>Select a class above to view and edit its subjects, total marks and passing marks.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Subject Modal -->
    <div class="modal-overlay" id="subjectModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="subjectModalTitle"><i class="fas fa-book"></i> Add Subject</h3>
                <button class="close-btn" id="btnCloseSubjectModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="subjectForm">
                    <input type="hidden" id="subjectId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Subject Name *</label>
                            <input type="text" id="subjectName" name="name" maxlength="100" required placeholder="e.g. Mathematics">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Subject Code *</label>
                            <input type="text" id="subjectCode" name="code" maxlength="20" required placeholder="e.g. MATH">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Unique, uppercase. Letters, numbers, hyphen and underscore only.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-compress"></i> Short Name</label>
                            <input type="text" id="subjectShort" name="short_name" maxlength="20" placeholder="e.g. Maths">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Printed in narrow result-card columns. Blank falls back to the code.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Subject Type *</label>
                            <select id="subjectType" name="subject_type" data-label="Subject Type">
                                <option value="Core">Core — taken by every student</option>
                                <option value="Elective">Elective — chosen from a group</option>
                                <option value="Optional">Optional — may be skipped</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calculator"></i> Include in Total</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="subjectInTotal" name="include_in_total" value="1" class="toggle-input" checked>
                                <label for="subjectInTotal" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-triangle-exclamation"></i> Off = still graded and still printed, but left out of total, percentage, GPA and pass/fail.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Active</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="subjectActive" name="is_active" value="1" class="toggle-input" checked>
                                <label for="subjectActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveSubject"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelSubject"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Subject Modal -->
    <div class="modal-overlay" id="assignModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-diagram-project"></i> Add Subject to Class</h3>
                <button class="close-btn" id="btnCloseAssignModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="assignForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-school"></i> Class</label>
                            <input type="text" id="assignClassName" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-book"></i> Subject *</label>
                            <select id="assignSubjectId" name="subject_id" required>
                                <option value="">Select subject</option>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Only active subjects not yet mapped to this class are listed.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-bullseye"></i> Total Marks *</label>
                            <input type="number" id="assignTotal" name="total_marks" step="0.01" min="0.01" value="100" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-double"></i> Passing Marks *</label>
                            <input type="number" id="assignPassing" name="passing_marks" step="0.01" min="0.01" value="33" required>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Must be greater than 0 and less than total marks.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-book-open"></i> Theory Marks</label>
                            <input type="number" id="assignTheory" name="theory_marks" step="0.01" min="0" placeholder="Leave blank if not split">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Theory + Practical must equal the total marks.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flask"></i> Practical Marks</label>
                            <input type="number" id="assignPractical" name="practical_marks" step="0.01" min="0" placeholder="Leave blank if not split">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Blank on either side means the subject is not split.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calculator"></i> Include in Total</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="assignInTotal" name="include_in_total" value="1" class="toggle-input" checked>
                                <label for="assignInTotal" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-triangle-exclamation"></i> Off = graded and printed, but out of total, percentage, GPA and pass/fail.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-circle-half-stroke"></i> Optional Subject</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="assignOptional" name="is_optional" value="1" class="toggle-input">
                                <label for="assignOptional" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> A student in this class may skip an optional subject.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-sort-numeric-down"></i> Sort Order</label>
                            <input type="number" id="assignSort" name="sort_order" min="0" max="9999" value="0">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveAssign"><i class="fas fa-save"></i> Assign</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelAssign"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Elective Enrolment Modal -->
    <div class="modal-overlay" id="electModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="electTitle"><i class="fas fa-user-check"></i> Elective Students</h3>
                <button class="close-btn" id="btnCloseElect"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-banner info-banner-top">
                    <i class="fas fa-circle-half-stroke"></i>
                    <span id="electInfo">Tick the students who take this elective. Unticked students are skipped in marks entry and on the result card.</span>
                </div>
                <div id="electBody"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="btnSaveElect"><i class="fas fa-save"></i> Save Enrolment</button>
                    <button type="button" class="btn btn-secondary" id="btnCancelElect"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
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
    var subjectsTable = null, subjectsData = [], csRows = [], csAvailable = [], csClass = null;
    var CAN = {
        a: <?= can('subjects', 'a') ? 'true' : 'false' ?>,
        e: <?= can('subjects', 'e') ? 'true' : 'false' ?>,
        d: <?= can('subjects', 'd') ? 'true' : 'false' ?>
    };

    $(document).ready(function() {
        ORMS.dropdown('#assignClass, #assignSubjectId, #subjectType, #filterSubjectType, #filterSubjectTotal, #filterSubjectStatus');
        loadSubjects();

        $('.tab-btn').on('click', function() {
            var id = $(this).data('tab'), $btn = $(this);
            ORMS.swap(function () {                       // crossfade, not a snap
                $('.tab-btn').removeClass('active');
                $btn.addClass('active');
                $('.tab-pane').removeClass('active');
                $('#' + id).addClass('active');
                // dt measures 0-width columns while hidden — recalc on reveal
                if (id === 'tabSubjects' && subjectsTable) subjectsTable.columns.adjust().responsive.recalc();
            });
        });

        $('#btnRefreshSubjects').on('click', function() { loadSubjects(this); });
        $('#btnAddSubject').on('click', openAddSubject);
        $('#btnCloseSubjectModal, #btnCancelSubject').on('click', function() { closeModal('#subjectModal'); });
        $('#btnCloseAssignModal, #btnCancelAssign').on('click', function() { closeModal('#assignModal'); });
        $('#subjectModal, #assignModal').on('click', function(e) { if (e.target === this) closeModal(this); });

        $('#assignClass').on('change', function() { loadClassSubjects(); });

        // type / counted / status all read the row data, never the rendered badge markup
        $('#filterSubjectType, #filterSubjectTotal, #filterSubjectStatus').on('change', function() {
            if (subjectsTable) subjectsTable.draw();
        });
        $('#btnClearSubjectFilter').on('click', function() {
            $('#filterSubjectType, #filterSubjectTotal, #filterSubjectStatus').val('');
            ORMS.dropdown.refresh('#filterSubjectType, #filterSubjectTotal, #filterSubjectStatus');
            if (subjectsTable) subjectsTable.draw();
        });
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'subjectsTable') return true;
            var r = subjectsData[dataIndex]; if (!r) return true;
            var t = $('#filterSubjectType').val(), c = $('#filterSubjectTotal').val(), a = $('#filterSubjectStatus').val();
            if (t && String(r.subject_type || '') !== t) return false;
            if (c !== '' && c !== undefined && String(r.include_in_total ?? 1) !== c) return false;
            if (a !== '' && a !== undefined && String(r.is_active ?? 1) !== a) return false;
            return true;
        });
        $('#btnRefreshAssign').on('click', function() { loadClassSubjects(this); });
        $('#btnAddAssign').on('click', openAssignModal);

        // codes are uppercase everywhere — mirror the server rule as you type
        $('#subjectCode').on('input', function() { this.value = this.value.toUpperCase(); });
    });

    function badge(active) {
        return active == 1
            ? '<span class="status-badge status-active"><i class="fas fa-check"></i> Active</span>'
            : '<span class="status-badge status-inactive"><i class="fas fa-ban"></i> Inactive</span>';
    }

    // core / elective / optional
    var TYPE_ICON = { Core: 'fa-star', Elective: 'fa-shuffle', Optional: 'fa-circle-half-stroke' };
    function typeBadge(t) {
        var v = t || 'Core';
        return '<span class="status-badge ' + (v === 'Core' ? 'status-user' : 'status-current') + '">' +
               '<i class="fas ' + (TYPE_ICON[v] || 'fa-star') + '"></i> ' + esc(v) + '</span>';
    }

    // counted vs graded-but-not-counted
    function totalBadge(on) {
        return on == 1
            ? '<span class="status-badge status-active"><i class="fas fa-calculator"></i> Counted</span>'
            : '<span class="status-badge status-inactive"><i class="fas fa-calculator"></i> Not counted</span>';
    }

    function dash() { return '<span class="text-muted">—</span>'; }

    function closeModal(sel) { $(sel).removeClass('active'); }

    // ------------------------------------------------------------- subjects
    function loadSubjects(btn) {
        ORMS.post('getSubjects', {}, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load subjects'); return; }
            subjectsData = res.data || [];
            $('#subjectsSkeleton').addClass('initially-hidden');
            $('#subjectsWrap').removeClass('initially-hidden');
            if (subjectsTable) { subjectsTable.destroy(); $('#subjectsTable').empty(); }
            subjectsTable = $('#subjectsTable').DataTable({
                data: subjectsData,
                destroy: true,
                order: [[0, 'asc']],
                columns: [
                    { data: 'name', title: 'Subject', render: function(d) { return '<i class="fas fa-book text-muted"></i> ' + esc(d); } },
                    { data: 'short_name', title: 'Short', render: function(d) { return d ? '<span class="subject-chip"><i class="fas fa-compress"></i> ' + esc(d) + '</span>' : dash(); } },
                    { data: 'code', title: 'Code', render: function(d) { return '<span class="subject-chip"><i class="fas fa-hashtag"></i> ' + esc(d) + '</span>'; } },
                    { data: 'subject_type', title: 'Type', render: function(d) { return typeBadge(d); } },
                    { data: 'include_in_total', title: 'In Total', render: function(d) { return totalBadge(d); } },
                    { data: 'classes', title: 'Mapped Classes', render: function(d) { return '<span class="subject-chip"><i class="fas fa-school"></i> ' + d + '</span>'; } },
                    { data: 'is_active', title: 'Status', render: function(d) { return badge(d); } },
                    { data: null, title: 'Actions', orderable: false, render: function(d, t, row) {
                        var b = '';
                        if (CAN.e) {
                            b += '<button class="action-icon edit-icon" title="Edit" onclick="editSubject(' + row.id + ')"><i class="fas fa-edit"></i></button>';
                            b += '<button class="action-icon view-icon" title="' + (row.is_active == 1 ? 'Deactivate' : 'Activate') + '" onclick="toggleSubject(' + row.id + ', this)">' +
                                 '<i class="fas fa-' + (row.is_active == 1 ? 'toggle-on' : 'toggle-off') + '"></i></button>';
                        }
                        if (CAN.d) b += '<button class="action-icon delete-icon" title="Delete" onclick="deleteSubject(' + row.id + ', this)"><i class="fas fa-trash"></i></button>';
                        return b || '<span class="text-muted">—</span>';
                    } }
                ],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', exportOptions: { columns: ':not(:last-child)' } },
                    { text: '<i class="fas fa-file-pdf"></i> PDF',
                      action: function(e, dt, node, config) {
                          loadExportDeps(function() { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                      },
                      exportOptions: { columns: ':not(:last-child)' } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: ':not(:last-child)' } }
                ]
            });
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function openAddSubject() {
        $('#subjectModalTitle').html('<i class="fas fa-book"></i> Add Subject');
        $('#subjectForm')[0].reset();
        $('#subjectId').val('');
        $('#subjectType').val('Core');
        ORMS.dropdown.refresh('#subjectType');
        $('#subjectInTotal').prop('checked', true);
        $('#subjectActive').prop('checked', true);
        $('#subjectModal').addClass('active');
        setTimeout(function() { $('#subjectName').trigger('focus'); }, 60);
    }

    function editSubject(id) {
        var s = subjectsData.filter(function(x) { return x.id == id; })[0];
        if (!s) return;
        $('#subjectModalTitle').html('<i class="fas fa-edit"></i> Edit Subject');
        $('#subjectId').val(s.id);
        $('#subjectName').val(s.name);
        $('#subjectCode').val(s.code);
        $('#subjectShort').val(s.short_name || '');
        $('#subjectType').val(s.subject_type || 'Core');
        ORMS.dropdown.refresh('#subjectType');
        $('#subjectInTotal').prop('checked', s.include_in_total == 1);
        $('#subjectActive').prop('checked', s.is_active == 1);
        $('#subjectModal').addClass('active');
    }

    function toggleSubject(id, btn) {
        ORMS.post('toggleSubject', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
            res.success ? (ORMS.ok(res.message), loadSubjects()) : ORMS.err(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function deleteSubject(id, btn) {
        var s = subjectsData.filter(function(x) { return x.id == id; })[0];
        ORMS.confirmDelete('Delete subject "' + (s ? s.name : '') + '"? Only unused subjects can be removed.').then(function(yes) {
            if (!yes) return;
            ORMS.post('deleteSubject', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                res.success ? (ORMS.ok(res.message), loadSubjects(), loadClassSubjects()) : ORMS.err(res.message, 'Cannot Delete');
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    $('#subjectForm').on('submit', function(e) {
        e.preventDefault();
        var name = $('#subjectName').val().trim(), code = $('#subjectCode').val().trim().toUpperCase();
        if (!name) { ORMS.err('Subject name is required'); return; }
        if (!code) { ORMS.err('Subject code is required'); return; }
        if (!/^[A-Z0-9\-_]+$/.test(code)) { ORMS.err('Subject code may use letters, numbers, hyphen and underscore only'); return; }

        ORMS.post('saveSubject', {
            id: $('#subjectId').val(), name: name, code: code,
            short_name: $('#subjectShort').val().trim(),
            subject_type: $('#subjectType').val() || 'Core',
            include_in_total: $('#subjectInTotal').is(':checked') ? 1 : 0,
            is_active: $('#subjectActive').is(':checked') ? 1 : 0
        }, { btn: '#btnSaveSubject', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            closeModal('#subjectModal');
            ORMS.ok(res.message);
            loadSubjects();
            loadClassSubjects();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    // -------------------------------------------- per-class marks config tab
    function assignEmpty(icon, title, text) {
        return '<div class="orms-empty"><i class="fas ' + icon + '"></i><h4>' + esc(title) + '</h4><p>' + esc(text) + '</p></div>';
    }

    function loadClassSubjects(btn) {
        var classId = $('#assignClass').val();
        if (!classId) {
            csRows = []; csAvailable = []; csClass = null;
            $('#assignBody').html(assignEmpty('fa-diagram-project', 'Pick a class to begin',
                'Select a class above to view and edit its subjects, total marks and passing marks.'));
            return;
        }
        ORMS.post('getClassSubjects', { class_id: classId }, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            csRows = res.data || [];
            csAvailable = res.available || [];
            csClass = res.class || null;
            renderClassSubjects();
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function renderClassSubjects() {
        if (!csRows.length) {
            $('#assignBody').html(assignEmpty('fa-book-open', 'No subjects mapped yet',
                (csClass ? csClass.name : 'This class') + ' has no subjects. Use "Add Subject to Class" to map the first one.'));
            return;
        }

        var withMarks = csRows.filter(function(r) { return parseInt(r.marks_count, 10) > 0; }).length;
        var notCounted = csRows.filter(function(r) { return r.include_in_total != 1; }).length;
        var h = '<div class="stat-mini">' +
                '<div><i class="fas fa-school"></i> Class <b>' + esc(csClass ? csClass.name : '') + '</b></div>' +
                '<div><i class="fas fa-book"></i> Subjects <b>' + csRows.length + '</b></div>' +
                '<div><i class="fas fa-lock"></i> With entered marks <b>' + withMarks + '</b></div>' +
                '<div><i class="fas fa-calculator"></i> Out of total <b>' + notCounted + '</b></div>' +
                '</div>';

        h += '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
             '<th><i class="fas fa-book"></i> Subject</th>' +
             '<th><i class="fas fa-hashtag"></i> Code</th>' +
             '<th><i class="fas fa-bullseye"></i> Total Marks</th>' +
             '<th><i class="fas fa-check-double"></i> Passing Marks</th>' +
             '<th><i class="fas fa-book-open"></i> Theory</th>' +
             '<th><i class="fas fa-flask"></i> Practical</th>' +
             '<th><i class="fas fa-calculator"></i> In Total</th>' +
             '<th><i class="fas fa-circle-half-stroke"></i> Optional</th>' +
             '<th><i class="fas fa-pen-to-square"></i> Marks Entered</th>' +
             '<th><i class="fas fa-gears"></i> Actions</th>' +
             '</tr></thead><tbody>';

        csRows.forEach(function(r) {
            var used = parseInt(r.marks_count, 10) > 0;
            var dis  = CAN.e ? '' : ' disabled';
            h += '<tr data-cs="' + r.id + '">' +
                 '<td>' + esc(r.subject_name) +
                    (r.short_name ? ' <span class="subject-chip">' + esc(r.short_name) + '</span>' : '') +
                    (r.is_active == 1 ? '' : ' <span class="status-badge status-inactive">Inactive</span>') +
                    (r.subject_in_total == 1 ? '' : ' <span class="status-badge status-inactive"><i class="fas fa-calculator"></i> Subject not counted</span>') + '</td>' +
                 '<td><span class="subject-chip">' + esc(r.subject_code) + '</span> ' + typeBadge(r.subject_type) + '</td>' +
                 '<td><input type="number" class="mark-input js-total" step="0.01" min="0.01" value="' + esc(r.total_marks) + '"' + dis + '></td>' +
                 '<td><input type="number" class="mark-input js-pass" step="0.01" min="0.01" value="' + esc(r.passing_marks) + '"' + dis + '></td>' +
                 '<td><input type="number" class="mark-input js-theory" step="0.01" min="0" placeholder="—" value="' + esc(r.theory_marks == null ? '' : r.theory_marks) + '"' + dis + '></td>' +
                 '<td><input type="number" class="mark-input js-prac" step="0.01" min="0" placeholder="—" value="' + esc(r.practical_marks == null ? '' : r.practical_marks) + '"' + dis + '></td>' +
                 '<td>' + rowToggle('cit', r.id, r.include_in_total == 1, 'js-cit') + '</td>' +
                 '<td>' + rowToggle('opt', r.id, r.is_optional == 1, 'js-opt') + '</td>' +
                 '<td>' + (used
                        ? '<span class="subject-chip"><i class="fas fa-lock"></i> ' + r.marks_count + '</span>'
                        : dash()) + '</td>' +
                 '<td>' + rowConfigActions(r, used) + '</td>' +
                 '</tr>';
        });

        h += '</tbody></table></div>';
        $('#assignBody').html(h);

        $('#assignBody').off('click', '.js-save-cs').on('click', '.js-save-cs', function() { saveClassSubject($(this).data('cs'), this); });
        $('#assignBody').off('click', '.js-del-cs').on('click', '.js-del-cs', function() { removeClassSubject($(this).data('cs'), this); });
        $('#assignBody').off('click', '.js-elect-cs').on('click', '.js-elect-cs', function() { openElective($(this).data('cs'), this); });
    }

    // in-row toggle — id must be unique so the label can drive its own input
    function rowToggle(prefix, id, on, cls) {
        var eid = prefix + '_' + id;
        return '<div class="toggle-switch">' +
               '<input type="checkbox" id="' + eid + '" class="toggle-input ' + cls + '" value="1"' +
               (on ? ' checked' : '') + (CAN.e ? '' : ' disabled') + '>' +
               '<label for="' + eid + '" class="toggle-label"><span class="toggle-slider"></span></label></div>';
    }

    function rowConfigActions(r, used) {
        var b = '';
        if (CAN.e) b += '<button class="btn btn-primary btn-sm js-save-cs" data-cs="' + r.id + '"><i class="fas fa-save"></i> Save</button> ';
        if (CAN.e && r.is_optional == 1) b += '<button class="btn btn-secondary btn-sm js-elect-cs" data-cs="' + r.id + '" title="Choose which students take this elective"><i class="fas fa-user-check"></i> Students</button> ';
        if (CAN.d) b += '<button class="btn btn-danger btn-sm js-del-cs" data-cs="' + r.id + '"' + (used ? ' title="Marks already entered"' : '') + '><i class="fas fa-trash"></i> Remove</button>';
        return b || '<span class="text-muted">—</span>';
    }

    // same rule as the server so a bad value never leaves the browser
    function marksCheck(total, pass) {
        if (isNaN(total) || isNaN(pass)) return 'Total and passing marks must be numbers';
        if (total <= 0) return 'Total marks must be greater than 0';
        if (pass <= 0) return 'Passing marks must be greater than 0';
        if (pass >= total) return 'Passing marks must be less than total marks';
        return '';
    }

    // mirrors subSplitCheck() — both set must sum to total, either blank means not split
    function splitCheck(total, theoryRaw, pracRaw) {
        var t = $.trim(String(theoryRaw == null ? '' : theoryRaw));
        var p = $.trim(String(pracRaw == null ? '' : pracRaw));
        if (t === '' || p === '') return '';
        var tv = parseFloat(t), pv = parseFloat(p);
        if (isNaN(tv) || isNaN(pv)) return 'Theory and practical marks must be numbers';
        if (tv < 0 || pv < 0) return 'Theory and practical marks cannot be negative';
        var sum = Math.round((tv + pv) * 100) / 100;
        if (Math.abs(sum - total) > 0.01) {
            return 'Theory + Practical must equal the total marks — ' + tv + ' + ' + pv + ' = ' + sum +
                   ', but the total is ' + total + '. Fix the split, or clear both boxes to leave the subject un-split.';
        }
        return '';
    }

    function saveClassSubject(csId, btn) {
        var $row   = $('tr[data-cs="' + csId + '"]');
        var total  = parseFloat($row.find('.js-total').val());
        var pass   = parseFloat($row.find('.js-pass').val());
        var theory = $row.find('.js-theory').val();
        var prac   = $row.find('.js-prac').val();
        $row.find('.mark-input').removeClass('invalid');

        var err = marksCheck(total, pass);
        if (err) { $row.find('.js-total, .js-pass').addClass('invalid'); ORMS.err(err); return; }
        err = splitCheck(total, theory, prac);
        if (err) { $row.find('.js-theory, .js-prac').addClass('invalid'); ORMS.err(err, 'Split does not add up'); return; }

        ORMS.post('updateClassSubject', {
            id: csId, total_marks: total, passing_marks: pass,
            theory_marks: theory, practical_marks: prac,
            include_in_total: $row.find('.js-cit').is(':checked') ? 1 : 0,
            is_optional: $row.find('.js-opt').is(':checked') ? 1 : 0
        }, { btn: btn, busyLabel: 'Saving…' }).done(function(res) {
            res.success ? (ORMS.ok(res.message), loadClassSubjects()) : ORMS.err(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function removeClassSubject(csId, btn) {
        var r = csRows.filter(function(x) { return x.id == csId; })[0];
        ORMS.confirmDelete('Remove "' + (r ? r.subject_name : '') + '" from ' + (csClass ? csClass.name : 'this class') +
                           '? Only subjects with no entered marks can be removed.', 'Remove subject?').then(function(yes) {
            if (!yes) return;
            ORMS.post('removeClassSubject', { id: csId }, { btn: btn, busyLabel: 'Removing…' }).done(function(res) {
                res.success ? (ORMS.ok(res.message), loadClassSubjects(), loadSubjects()) : ORMS.err(res.message, 'Cannot Remove');
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    function openAssignModal() {
        var classId = $('#assignClass').val();
        if (!classId) { ORMS.err('Select a class first'); return; }
        if (!csAvailable.length) { ORMS.err('Every active subject is already mapped to this class'); return; }

        $('#assignClassName').val(csClass ? csClass.name : '');
        var opts = '<option value="">Select subject</option>';
        csAvailable.forEach(function(s) {
            opts += '<option value="' + s.id + '">' + esc(s.name) + ' (' + esc(s.code) + ')' +
                    (s.include_in_total == 1 ? '' : ' — not counted in total') + '</option>';
        });
        $('#assignSubjectId').html(opts).val('');
        ORMS.dropdown.refresh('#assignSubjectId');

        $('#assignTotal').val(100);
        $('#assignPassing').val(33);
        $('#assignTheory').val('');
        $('#assignPractical').val('');
        $('#assignInTotal').prop('checked', true);
        $('#assignOptional').prop('checked', false);
        $('#assignSort').val(csRows.length);
        $('#assignModal').addClass('active');
    }

    // picking a subject seeds the class row from the subject's own defaults
    $('#assignSubjectId').on('change', function() {
        var v = $(this).val();
        var s = csAvailable.filter(function(x) { return x.id == v; })[0];
        if (!s) return;
        $('#assignInTotal').prop('checked', s.include_in_total == 1);
        $('#assignOptional').prop('checked', s.subject_type === 'Optional');
    });

    // ------------------------------------------------ elective enrolment modal
    var electCs = 0;

    function openElective(csId, btn) {
        ORMS.post('getElectiveStudents', { id: csId }, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            electCs = csId;
            $('#electTitle').html('<i class="fas fa-user-check"></i> ' + esc(res.subject) + ' — ' + esc(res.class) + ' · ' + esc(res.year));
            var rows = res.data || [], bySec = {};
            rows.forEach(function(s) { (bySec[s.section_name] = bySec[s.section_name] || []).push(s); });
            var h = '';
            Object.keys(bySec).sort().forEach(function(sec) {
                var kids = bySec[sec], on = kids.filter(function(s) { return s.enrolled == 1; }).length;
                h += '<div class="stat-mini"><div><i class="fas fa-layer-group"></i> Section <b>' + esc(sec) + '</b></div>' +
                     '<div><i class="fas fa-user-check"></i> Enrolled <b>' + on + '/' + kids.length + '</b></div></div>';
                h += '<div class="elect-grid">';
                kids.forEach(function(s) {
                    var locked = parseInt(s.marks_count, 10) > 0;   // marks entered -> can tick, never untick
                    h += '<label class="elect-item' + (locked ? ' elect-locked' : '') + '"' +
                         (locked ? ' title="Marks already entered this year — cannot be un-enrolled"' : '') + '>' +
                         '<input type="checkbox" class="js-elect-stu" value="' + s.id + '"' +
                         (s.enrolled == 1 ? ' checked' : '') + (locked ? ' data-locked="1"' : '') + '> ' +
                         '<span><b>' + esc(s.full_name || s.username) + '</b>' +
                         '<small>Roll ' + esc(s.roll_no || '—') + ' · ' + esc(s.admission_no) + '</small></span>' +
                         (locked ? ' <i class="fas fa-lock text-muted"></i>' : '') + '</label>';
                });
                h += '</div>';
            });
            $('#electBody').html(h || '<div class="orms-empty"><i class="fas fa-user-slash"></i><h4>No active students</h4><p>This class has no active students to enrol.</p></div>');
            $('#electModal').addClass('active');
        }).fail(function(msg) { ORMS.err(msg); });
    }

    // a locked box snaps back on — un-enrolling a marked student is refused server-side anyway
    $(document).on('change', '.js-elect-stu[data-locked="1"]', function() {
        if (!this.checked) { this.checked = true; ORMS.err('Marks are already entered for this student this year — clear them first.', 'Cannot un-enrol'); }
    });

    $('#btnSaveElect').on('click', function() {
        var ids = $('#electBody .js-elect-stu:checked').map(function() { return parseInt(this.value, 10); }).get();
        ORMS.post('saveElectiveStudents', { id: electCs, student_ids: JSON.stringify(ids) },
                  { btn: this, busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            closeModal('#electModal');
            ORMS.ok(res.message);
            loadClassSubjects();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    $('#btnCloseElect, #btnCancelElect').on('click', function() { closeModal('#electModal'); });
    $('#electModal').on('click', function(e) { if (e.target === this) closeModal(this); });

    $('#assignForm').on('submit', function(e) {
        e.preventDefault();
        var subjectId = $('#assignSubjectId').val();
        var total = parseFloat($('#assignTotal').val()), pass = parseFloat($('#assignPassing').val());
        var theory = $('#assignTheory').val(), prac = $('#assignPractical').val();
        if (!subjectId) { ORMS.err('Please select a subject'); return; }
        var err = marksCheck(total, pass);
        if (err) { ORMS.err(err); return; }
        err = splitCheck(total, theory, prac);
        if (err) { ORMS.err(err, 'Split does not add up'); return; }

        ORMS.post('assignSubject', {
            class_id: $('#assignClass').val(), subject_id: subjectId,
            total_marks: total, passing_marks: pass, sort_order: $('#assignSort').val(),
            theory_marks: theory, practical_marks: prac,
            include_in_total: $('#assignInTotal').is(':checked') ? 1 : 0,
            is_optional: $('#assignOptional').is(':checked') ? 1 : 0
        }, { btn: '#btnSaveAssign', busyLabel: 'Assigning…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            closeModal('#assignModal');
            ORMS.ok(res.message);
            loadClassSubjects();
            loadSubjects();
        }).fail(function(msg) { ORMS.err(msg); });
    });
    </script>
</body>
</html>
