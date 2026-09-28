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
requirePerm('classes', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'classes';

// tolerant counter — a table that doesn't exist yet can't be blocking anything
function clsCount(string $sql, string $types = '', ...$p): int {
    try { return (int)qVal($sql, $types, ...$p); } catch (Throwable $e) { return 0; }
}

// column probe, one per request — an install that hasn't taken the migration keeps the old behaviour
function clsHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

// per-class grading set + assessment scheme are one feature — both columns land together
function clsHasSets(): bool { return clsHasCol('classes', 'grading_set_id') && clsHasCol('classes', 'assessment_scheme_id'); }

// tenant columns all land in one migration step, so one probe answers for the lot
function clsTenant(): bool { return clsHasCol('classes', 'school_id'); }

// school (+ active branch) fence for a classes alias -> [sql, types, params]. empty on a pre-migration db
function clsWhere(string $a = 'c'): array {
    if (!clsTenant()) return ['', '', []];
    $b = clsHasCol('classes', 'branch_id') ? bid() : 0;
    return $b ? [" AND $a.school_id = ? AND $a.branch_id = ?", 'ii', [sid(), $b]]
              : [" AND $a.school_id = ?", 'i', [sid()]];
}

// posted id -> verified row id, 0 when another school owns it. pre-migration has nothing to check against
function clsOwns(string $table, $raw): int {
    $id = (int)$raw;
    if ($id <= 0) return 0;
    return clsTenant() ? ormsOwns($table, $id) : $id;
}

// name of a school-owned lookup row, '' when it is someone else's. table is always our own literal
function clsOwnedName(string $table, int $id): string {
    if ($id <= 0) return '';
    return clsTenant() ? (string)qVal("SELECT name FROM `$table` WHERE id = ? AND school_id = ?", 'ii', $id, sid())
                       : (string)qVal("SELECT name FROM `$table` WHERE id = ?", 'i', $id);
}

// the shared lookups don't fence themselves yet — drop rows belonging to other schools before they hit a picker
function clsOwnedRows(string $table, array $rows): array {
    if (!$rows || !clsHasCol($table, 'school_id')) return $rows;
    try {
        $ok = array_flip(array_map('intval', array_column(qAll("SELECT id FROM `$table` WHERE school_id = ?", 'i', sid()), 'id')));
        return array_values(array_filter($rows, fn($r) => isset($ok[(int)$r['id']])));
    } catch (Throwable $e) { return $rows; }
}

// branch a class belongs to: its own on edit, else the pin / active filter / the user's own
function clsBranch(int $id = 0): ?int {
    if (!clsHasCol('classes', 'branch_id')) return null;
    $b = $id ? (int)qVal("SELECT branch_id FROM classes WHERE id = ?", 'i', $id) : 0;
    $b = $b ?: (bid() ?: (int)($_SESSION['branch_id'] ?? 0));
    return $b ?: null;
}

// [label => n] -> "3 sections, 42 students"
function clsBlockers(array $counts): string {
    $parts = [];
    foreach ($counts as $label => $n) if ($n > 0) $parts[] = $n . ' ' . $label;
    return implode(', ', $parts);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action !== null) {
    try {
        switch ($action) {

            // ---------------------------------------------------------- classes
            case 'getClasses':
                // grading set / scheme columns only exist after the migration — pre-migration keeps the old shape
                $setSel = clsHasSets() ? "c.grading_set_id, c.assessment_scheme_id," : "NULL AS grading_set_id, NULL AS assessment_scheme_id,";
                [$w, $wt, $wp] = clsWhere('c');                      // fence goes on the driver, not just the counts
                $ncj = clsTenant() ? " AND nc.school_id = c.school_id" : "";
                jsonOk(['data' => qAll(
                    "SELECT c.id, c.name, c.sort_order, c.numeric_level, c.next_class_id, c.is_active, c.show_position,
                            $setSel
                            nc.name AS next_class_name,
                            (SELECT COUNT(*) FROM sections s  WHERE s.class_id  = c.id) AS sections,
                            (SELECT COUNT(*) FROM students st WHERE st.class_id = c.id) AS students
                     FROM classes c
                     LEFT JOIN classes nc ON nc.id = c.next_class_id$ncj
                     WHERE 1 = 1$w
                     ORDER BY COALESCE(c.numeric_level, c.sort_order) ASC, c.sort_order ASC, c.name ASC",
                    $wt, ...$wp
                )]);

            case 'saveClass':
                requireCsrfJson();
                $raw = (int)($_POST['id'] ?? 0);
                requirePermJson('classes', $raw ? 'e' : 'a');       // perm follows what was ASKED for
                $id = clsOwns('classes', $raw);
                if ($raw && !$id) jsonErr('Class not found');       // never let a foreign id fall through to an insert

                $name   = trim($_POST['name'] ?? '');
                $sort   = (int)($_POST['sort_order'] ?? 0);
                $lvlRaw = trim($_POST['numeric_level'] ?? '');
                $next   = (int)($_POST['next_class_id'] ?? 0);
                $activ  = !empty($_POST['is_active']) ? 1 : 0;
                $shPos  = !empty($_POST['show_position']) ? 1 : 0;   // junior classes often hide ranks
                $gset   = (int)($_POST['grading_set_id'] ?? 0);      // 0/blank = inherit the school default
                $ascm   = (int)($_POST['assessment_scheme_id'] ?? 0);// 0/blank = single mark box, no components

                if ($name === '')            jsonErr('Class name is required');
                if (mb_strlen($name) > 50)   jsonErr('Class name must be 50 characters or less');
                if ($sort < 0 || $sort > 9999) jsonErr('Sort order must be between 0 and 9999');

                // level is what makes "Class 10" sort after "Class 2" — optional, so blank stays null
                $lvl = null;
                if ($lvlRaw !== '') {
                    if (!ctype_digit($lvlRaw) || (int)$lvlRaw < 1 || (int)$lvlRaw > 99) jsonErr('Level must be a whole number between 1 and 99');
                    $lvl = (int)$lvlRaw;
                }

                // promotion target: never itself, never a ghost row, never another school's class
                if ($next > 0) {
                    if ($id && $next === $id) jsonErr('A class cannot promote into itself');
                    $next = clsOwns('classes', $next);
                    if (!$next) jsonErr('The selected next class no longer exists');
                } else {
                    $next = null;
                }

                // uniq_school_class (school, branch, name) — answer before the db does, per school
                $brn = clsBranch($id);
                $uq  = "SELECT id FROM classes WHERE name = ? AND id <> ?";
                $ut  = 'si'; $up = [$name, $id];
                if (clsTenant()) { $uq .= " AND school_id = ?"; $ut .= 'i'; $up[] = sid(); }
                if (clsHasCol('classes', 'branch_id')) { $uq .= " AND branch_id <=> ?"; $ut .= 'i'; $up[] = $brn; }
                if (qVal($uq, $ut, ...$up))
                    jsonErr('A class named "' . $name . '" already exists');

                // blank = inherit / none. a stale id must die here, the fk would only fire on some installs
                $hasSets = clsHasSets();
                $gsName  = 'inherited default';
                $asName  = 'single mark';
                if ($hasSets) {
                    if ($gset > 0) {
                        $gsName = clsOwnedName('grading_sets', $gset);
                        if ($gsName === '') jsonErr('The selected grading set no longer exists');
                    } else { $gset = null; }
                    if ($ascm > 0) {
                        $asName = clsOwnedName('assessment_schemes', $ascm);
                        if ($asName === '') jsonErr('The selected assessment scheme no longer exists');
                    } else { $ascm = null; }
                }
                $setLog = $hasSets ? " — grading set: $gsName, assessment: $asName" : '';

                if ($id) {
                    // types: s name, i sort, i level, i next, i active, i showpos [, i gset, i scheme], i id
                    qExec("UPDATE classes SET name = ?, sort_order = ?, numeric_level = ?, next_class_id = ?, is_active = ?, show_position = ?"
                          . ($hasSets ? ", grading_set_id = ?, assessment_scheme_id = ?" : "") . " WHERE id = ?",
                          'siiiii' . ($hasSets ? 'ii' : '') . 'i',
                          ...($hasSets ? [$name, $sort, $lvl, $next, $activ, $shPos, $gset, $ascm, $id]
                                       : [$name, $sort, $lvl, $next, $activ, $shPos, $id]));
                    logActivity($user_id, $username, 'Class Updated', "Updated class: $name (#$id)" . ($shPos ? '' : ' [position hidden on cards]') . $setLog);
                    jsonOk(['message' => 'Class updated successfully']);
                }
                // cols/types built side by side so the count can be read off one line each
                $cols = "name, sort_order, numeric_level, next_class_id, is_active, show_position";
                $ph   = "?, ?, ?, ?, ?, ?";
                $t    = 'siiiii';                                    // s name + 5x i
                $args = [$name, $sort, $lvl, $next, $activ, $shPos];
                if ($hasSets)   { $cols .= ", grading_set_id, assessment_scheme_id"; $ph .= ", ?, ?"; $t .= 'ii'; array_push($args, $gset, $ascm); }
                if (clsTenant()) { $cols .= ", school_id"; $ph .= ", ?"; $t .= 'i'; $args[] = sid(); }
                if (clsHasCol('classes', 'branch_id')) { $cols .= ", branch_id"; $ph .= ", ?"; $t .= 'i'; $args[] = $brn; }
                $id = qInsert("INSERT INTO classes ($cols) VALUES ($ph)", $t, ...$args);
                logActivity($user_id, $username, 'Class Created', "Created class: $name (#$id)" . $setLog);
                jsonOk(['message' => 'Class added successfully']);

            case 'toggleClass':
                requireCsrfJson();
                requirePermJson('classes', 'e');
                $id  = clsOwns('classes', $_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name, is_active FROM classes WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Class not found');

                $new = (int)$row['is_active'] === 1 ? 0 : 1;
                qExec("UPDATE classes SET is_active = ? WHERE id = ?", 'ii', $new, $id);
                logActivity($user_id, $username, 'Class Updated', "Set class {$row['name']} " . ($new ? 'active' : 'inactive'));
                jsonOk(['message' => 'Class marked ' . ($new ? 'active' : 'inactive')]);

            case 'deleteClass':
                requireCsrfJson();
                requirePermJson('classes', 'd');
                $id  = clsOwns('classes', $_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT name FROM classes WHERE id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Class not found');

                // count first — never let an FK error (or a silent section cascade) reach the user
                $blocked = clsBlockers([
                    'section(s)'             => clsCount("SELECT COUNT(*) FROM sections WHERE class_id = ?", 'i', $id),
                    'student(s)'             => clsCount("SELECT COUNT(*) FROM students WHERE class_id = ?", 'i', $id),
                    'subject mapping(s)'     => clsCount("SELECT COUNT(*) FROM class_subjects WHERE class_id = ?", 'i', $id),
                    'teacher assignment(s)'  => clsCount("SELECT COUNT(*) FROM teacher_subjects WHERE class_id = ?", 'i', $id),
                    'marks record(s)'        => clsCount("SELECT COUNT(*) FROM marks WHERE class_id = ?", 'i', $id),
                ]);
                if ($blocked !== '')
                    jsonErr('"' . $row['name'] . '" cannot be deleted — it still has ' . $blocked .
                            '. Deactivate the class instead to hide it from new entries while keeping its history.');

                qExec("DELETE FROM classes WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Class Deleted', "Deleted class: {$row['name']} (#$id)");
                jsonOk(['message' => 'Class deleted successfully']);

            // --------------------------------------------------------- sections
            case 'getSections':
                // sections hang off classes, so the classes fence IS the section fence
                [$w, $wt, $wp] = clsWhere('c');
                $tj = clsTenant() ? " AND t.school_id = c.school_id" : "";
                jsonOk(['data' => qAll(
                    "SELECT s.id, s.class_id, s.name, s.capacity, s.room_no, s.class_teacher_id, s.is_active,
                            c.name AS class_name, tu.full_name AS teacher_name, t.employee_no AS teacher_emp,
                            (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS students
                     FROM sections s
                     JOIN classes c      ON c.id  = s.class_id
                     LEFT JOIN teachers t ON t.id = s.class_teacher_id$tj
                     LEFT JOIN users tu   ON tu.id = t.user_id
                     WHERE 1 = 1$w
                     ORDER BY COALESCE(c.numeric_level, c.sort_order) ASC, c.sort_order ASC, c.name ASC, s.name ASC",
                    $wt, ...$wp
                )]);

            case 'saveSection':
                requireCsrfJson();
                $raw = (int)($_POST['id'] ?? 0);
                requirePermJson('classes', $raw ? 'e' : 'a');
                $id = clsOwns('sections', $raw);
                if ($raw && !$id) jsonErr('Section not found');

                $rawCls  = (int)($_POST['class_id'] ?? 0);
                $classId = clsOwns('classes', $rawCls);              // posted class must be ours
                $name    = trim($_POST['name'] ?? '');
                $capRaw  = trim($_POST['capacity'] ?? '');
                $room    = trim($_POST['room_no'] ?? '');
                $teach   = clsOwns('teachers', $_POST['class_teacher_id'] ?? 0);
                $activ   = !empty($_POST['is_active']) ? 1 : 0;

                if (!$rawCls)              jsonErr('Please select a class');
                if ($name === '')          jsonErr('Section name is required');
                if (mb_strlen($name) > 20) jsonErr('Section name must be 20 characters or less');
                if (mb_strlen($room) > 20) jsonErr('Room number must be 20 characters or less');
                $room = $room === '' ? null : $room;

                $cls = $classId ? qOne("SELECT name FROM classes WHERE id = ?", 'i', $classId) : null;
                if (!$cls) jsonErr('Selected class no longer exists');

                // blank = unassigned; the fk is ON DELETE SET NULL so a stale id must be caught here
                $tchName = null;
                if ($teach > 0) {
                    $t = qOne("SELECT t.employee_no, u.full_name FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ?", 'i', $teach);
                    if (!$t) jsonErr('The selected class teacher no longer exists');
                    $tchName = $t['full_name'] . ' (' . $t['employee_no'] . ')';
                } else {
                    $teach = null;
                }
                if (!empty($_POST['class_teacher_id']) && $teach === null) jsonErr('The selected class teacher no longer exists');

                $cap = null;
                if ($capRaw !== '') {
                    if (!ctype_digit($capRaw) || (int)$capRaw < 1) jsonErr('Capacity must be a whole number of 1 or more');
                    $cap = (int)$capRaw;
                }

                // uniq_section (class_id, name)
                if (qVal("SELECT id FROM sections WHERE class_id = ? AND name = ? AND id <> ?", 'isi', $classId, $name, $id))
                    jsonErr('Section "' . $name . '" already exists in ' . $cls['name']);

                // moving a populated section to another class would strand its students
                if ($id) {
                    $cur = qOne("SELECT class_id FROM sections WHERE id = ?", 'i', $id);
                    if (!$cur) jsonErr('Section not found');
                    if ((int)$cur['class_id'] !== $classId) {
                        $n = clsCount("SELECT COUNT(*) FROM students WHERE section_id = ?", 'i', $id);
                        if ($n > 0) jsonErr("This section holds $n student(s) — move them first, then change its class");
                    }
                    // types: i class, s name, i cap, s room, i teacher, i active, i id
                    qExec("UPDATE sections SET class_id = ?, name = ?, capacity = ?, room_no = ?, class_teacher_id = ?, is_active = ? WHERE id = ?",
                          'isisiii', $classId, $name, $cap, $room, $teach, $activ, $id);
                    logActivity($user_id, $username, 'Section Updated',
                        "Updated section: {$cls['name']} - $name (#$id), class teacher: " . ($tchName ?: 'unassigned'));
                    jsonOk(['message' => 'Section updated successfully']);
                }
                $id = qInsert("INSERT INTO sections (class_id, name, capacity, room_no, class_teacher_id, is_active) VALUES (?, ?, ?, ?, ?, ?)",
                              'isisii', $classId, $name, $cap, $room, $teach, $activ);
                logActivity($user_id, $username, 'Section Created',
                    "Created section: {$cls['name']} - $name (#$id), class teacher: " . ($tchName ?: 'unassigned'));
                jsonOk(['message' => 'Section added successfully']);

            case 'toggleSection':
                requireCsrfJson();
                requirePermJson('classes', 'e');
                $id  = clsOwns('sections', $_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT s.name, s.is_active, c.name AS class_name FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Section not found');

                $new = (int)$row['is_active'] === 1 ? 0 : 1;
                qExec("UPDATE sections SET is_active = ? WHERE id = ?", 'ii', $new, $id);
                logActivity($user_id, $username, 'Section Updated', "Set section {$row['class_name']} - {$row['name']} " . ($new ? 'active' : 'inactive'));
                jsonOk(['message' => 'Section marked ' . ($new ? 'active' : 'inactive')]);

            case 'deleteSection':
                requireCsrfJson();
                requirePermJson('classes', 'd');
                $id  = clsOwns('sections', $_POST['id'] ?? 0);
                $row = $id ? qOne("SELECT s.name, c.name AS class_name FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.id = ?", 'i', $id) : null;
                if (!$row) jsonErr('Section not found');

                $label   = $row['class_name'] . ' - ' . $row['name'];
                $blocked = clsBlockers([
                    'student(s)'            => clsCount("SELECT COUNT(*) FROM students WHERE section_id = ?", 'i', $id),
                    'marks record(s)'       => clsCount("SELECT COUNT(*) FROM marks WHERE section_id = ?", 'i', $id),
                    'teacher assignment(s)' => clsCount("SELECT COUNT(*) FROM teacher_subjects WHERE section_id = ?", 'i', $id),
                    'published result(s)'   => clsCount("SELECT COUNT(*) FROM result_publications WHERE section_id = ?", 'i', $id),
                ]);
                if ($blocked !== '')
                    jsonErr('Section "' . $label . '" cannot be deleted — it still has ' . $blocked .
                            '. Deactivate it instead so its history stays intact.');

                qExec("DELETE FROM sections WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Section Deleted', "Deleted section: $label (#$id)");
                jsonOk(['message' => 'Section deleted successfully']);

            // ---------------------------------------------------------- csv import: classes + their sections
            case 'bulkImportClasses': {
                requireCsrfJson();
                requirePermJson('classes', 'a');

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($rows) || !$rows) jsonErr('Nothing to import');
                if (count($rows) > 500)         jsonErr('Please import 500 rows or fewer at a time');

                // existing classes + sections keyed by lowercase name — ONE query each, O(1) per row
                [$w, $wt, $wp] = clsWhere('c');
                $classMap = [];
                foreach (qAll("SELECT c.id, c.name FROM classes c WHERE 1 = 1$w", $wt, ...$wp) as $c) {
                    $classMap[mb_strtolower(trim($c['name']))] = (int)$c['id'];
                }
                [$w2, $wt2, $wp2] = clsWhere('c');
                $secMap = [];
                foreach (qAll("SELECT s.class_id, s.name FROM sections s JOIN classes c ON c.id = s.class_id WHERE 1 = 1$w2", $wt2, ...$wp2) as $s) {
                    $secMap[$s['class_id'] . '|' . mb_strtolower(trim($s['name']))] = 1;
                }

                $accepted = $errors = [];
                $batchNames = [];                                  // in-batch class -> accepted index

                // pass 1 — validate + merge duplicates, never fail-fast
                foreach ($rows as $i => $r) {
                    $line = $i + 2;                                // +1 header, +1 human numbering
                    if (!is_array($r)) { $errors[] = "Fila $line: formato incorrecto"; continue; }
                    $get = static fn(int $n): string => trim((string)($r[$n] ?? ''));

                    $name    = $get(0);
                    $lvlRaw  = $get(1);
                    $sortRaw = $get(2);
                    $secRaw  = $get(3);

                    if ($name === '' && $secRaw === '') continue;  // blank trailing line
                    if ($name === '')          { $errors[] = "Fila $line: el nombre del grado o clase es obligatorio"; continue; }
                    if (mb_strlen($name) > 50) { $errors[] = "Fila $line: el nombre debe tener 50 caracteres o menos"; continue; }

                    $lvl = null;
                    if ($lvlRaw !== '') {
                        if (!ctype_digit($lvlRaw) || (int)$lvlRaw < 1 || (int)$lvlRaw > 99) { $errors[] = "Fila $line: el nivel debe ser un número entero entre 1 y 99"; continue; }
                        $lvl = (int)$lvlRaw;
                    }
                    $sort = 0;
                    if ($sortRaw !== '') {
                        if (!ctype_digit($sortRaw) || (int)$sortRaw > 9999) { $errors[] = "Fila $line: el orden debe estar entre 0 y 9999"; continue; }
                        $sort = (int)$sortRaw;
                    }

                    // sections cell: "A | B | C" — | , ; all accepted, deduped, casing kept
                    $secs = [];
                    $bad  = '';
                    foreach (preg_split('/[|,;]/', $secRaw) ?: [] as $sName) {
                        $sName = trim($sName);
                        if ($sName === '') continue;
                        if (mb_strlen($sName) > 20) { $bad = $sName; break; }
                        $secs[mb_strtolower($sName)] = $sName;
                    }
                    if ($bad !== '') { $errors[] = "Fila $line: la sección \"$bad\" debe tener 20 caracteres o menos"; continue; }

                    $k = mb_strtolower($name);
                    if (isset($batchNames[$k])) {                  // same class twice -> merge its sections
                        $accepted[$batchNames[$k]]['secs'] += $secs;
                        continue;
                    }
                    $batchNames[$k] = count($accepted);
                    $accepted[] = ['name' => $name, 'key' => $k, 'lvl' => $lvl, 'sort' => $sort, 'secs' => $secs];
                }

                $newClasses = $newSections = 0;
                if ($accepted) {
                    $brn  = clsBranch();                           // new classes land in the active/main branch
                    $conn = getDBConnection();
                    $conn->begin_transaction();
                    try {
                        foreach ($accepted as $a) {
                            $cid = $classMap[$a['key']] ?? 0;
                            if (!$cid) {
                                // same column set saveClass writes, minus the optional pickers
                                $cols = "name, sort_order, numeric_level, is_active, show_position";
                                $ph   = "?, ?, ?, 1, 1";
                                $t    = 'sii';
                                $args = [$a['name'], $a['sort'], $a['lvl']];
                                if (clsTenant())                       { $cols .= ", school_id"; $ph .= ", ?"; $t .= 'i'; $args[] = sid(); }
                                if (clsHasCol('classes', 'branch_id')) { $cols .= ", branch_id"; $ph .= ", ?"; $t .= 'i'; $args[] = $brn; }
                                $cid = qInsert("INSERT INTO classes ($cols) VALUES ($ph)", $t, ...$args);
                                $classMap[$a['key']] = $cid;
                                $newClasses++;
                            }
                            foreach ($a['secs'] as $sk => $sName) {
                                if (isset($secMap[$cid . '|' . $sk])) continue;   // already there -> kept as is
                                qInsert("INSERT INTO sections (class_id, name, is_active) VALUES (?, ?, 1)", 'is', $cid, $sName);
                                $secMap[$cid . '|' . $sk] = 1;
                                $newSections++;
                            }
                        }
                        $conn->commit();
                    } catch (Throwable $e) {
                        $conn->rollback();                         // all-or-nothing, no half-imported batch
                        error_log('classes.php import: ' . $e->getMessage());
                        jsonErr('Import failed — nothing was saved. Please check the file and try again.');
                    }
                }

                $skipped = count($errors);
                logActivity($user_id, $username, 'Classes Imported', "Imported $newClasses class(es) + $newSections section(s), skipped $skipped row(s)");
                jsonOk(['imported' => $newClasses + $newSections, 'skipped' => $skipped, 'errors' => $errors,
                        'classes' => $newClasses, 'sections' => $newSections,
                        'message' => "$newClasses grado(s) creados, $newSections sección(es) agregadas, $skipped fila(s) omitidas"]);
            }

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('classes.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
}

// class list for the section modal + filter — level leads, sort_order/name fill in for the unset ones
$classList = [];
try {
    [$w, $wt, $wp] = clsWhere('c');
    $classList = qAll("SELECT c.id, c.name FROM classes c WHERE 1 = 1$w
                       ORDER BY COALESCE(c.numeric_level, c.sort_order) ASC, c.sort_order ASC, c.name ASC", $wt, ...$wp);
} catch (Throwable $e) {}

// class-teacher picker: active staff plus anyone still holding a slot, so editing a section can't silently unassign them.
// the OR needs its own brackets or the tenant fence would only apply to the right-hand side
$teacherList = [];
try {
    $tw = ''; $tt = ''; $tp = [];                                    // subquery param first, it comes first in the sql
    if (clsTenant()) {
        $tw = " AND t.school_id = ?"; $tt = 'i'; $tp = [sid()];
        if (clsHasCol('teachers', 'branch_id') && ($b = bid())) { $tw .= " AND t.branch_id = ?"; $tt .= 'i'; $tp[] = $b; }
    }
    $sub = clsTenant() ? " AND c.school_id = ?" : "";
    $teacherList = qAll(
        "SELECT t.id, t.employee_no, t.status, u.full_name
         FROM teachers t
         JOIN users u ON u.id = t.user_id
         WHERE (t.status = 'Active'
            OR t.id IN (SELECT s.class_teacher_id FROM sections s JOIN classes c ON c.id = s.class_id
                        WHERE s.class_teacher_id IS NOT NULL$sub))$tw
         ORDER BY u.full_name ASC",
        ($sub ? 'i' : '') . $tt, ...array_merge($sub ? [sid()] : [], $tp));
} catch (Throwable $e) {}

// grading sets + assessment schemes for the two per-class pickers. all of them, inactive ones flagged,
// so editing a class can never silently drop a set an admin has since retired
$hasSets   = clsHasSets();
$gsList    = clsOwnedRows('grading_sets',       ($hasSets && function_exists('ormsGradingSets'))      ? ormsGradingSets(false)      : []);
$ascList   = clsOwnedRows('assessment_schemes', ($hasSets && function_exists('ormsAssessmentSchemes'))? ormsAssessmentSchemes(false): []);
$defSetId  = ($hasSets && function_exists('ormsDefaultSetId'))     ? ormsDefaultSetId()          : 0;
$defSetName = 'Default Scheme';
foreach ($gsList as $g) if ((int)$g['id'] === $defSetId) { $defSetName = (string)$g['name']; break; }
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
    <title>Classes &amp; Sections - Result Management</title>

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
                    <h1><i class="fas fa-school"></i> Classes &amp; Sections</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Classes &amp; Sections</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="tab-nav">
                <button type="button" class="tab-btn active" data-tab="tabClasses"><i class="fas fa-school"></i> Classes</button>
                <button type="button" class="tab-btn" data-tab="tabSections"><i class="fas fa-layer-group"></i> Sections</button>
            </div>

            <!-- ============================ Classes ============================ -->
            <div class="tab-pane active" id="tabClasses">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table"></i> Classes</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" id="btnRefreshClasses"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if (can('classes', 'a')): ?>
                            <button class="btn btn-success" id="btnAddClass"><i class="fas fa-plus"></i> Add Class</button>
                            <button class="btn btn-secondary" id="btnClassTemplate"><i class="fas fa-download"></i> Plantilla</button>
                            <button class="btn btn-secondary" id="btnImportClasses"><i class="fas fa-file-import"></i> Importar CSV</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters-section">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button type="button" class="btn btn-secondary btn-sm" id="btnClearClassFilter"><i class="fas fa-eraser"></i> Clear</button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="filterClassStatus" class="filter-input">
                                    <option value="">All Status</option>
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="info-banner info-banner-top mb-24">
                        <i class="fas fa-circle-info"></i>
                        <span>A class can only be deleted while it has no sections, students, subject mappings or marks. Once it is in use, deactivate it instead — history stays intact.</span>
                    </div>

                    <div id="importResult" class="initially-hidden"></div>

                    <div id="classesSkeleton">
                        <div class="skeleton-table">
                            <?php for ($i = 0; $i < 7; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="classesWrap" class="initially-hidden">
                        <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="table-responsive">
                            <table id="classesTable" class="display table-full-width"></table>
                        </div>
                    </div>

                    <div id="classesEmpty" class="orms-empty initially-hidden">
                        <i class="fas fa-school"></i>
                        <h4>No classes yet</h4>
                        <p><?php echo can('classes', 'a') ? 'Use <strong>Add Class</strong> above to create the first one — sections and students hang off it.' : 'Ask an admin to set up the class list.'; ?></p>
                    </div>
                </div>
            </div>

            <!-- ============================ Sections ============================ -->
            <div class="tab-pane" id="tabSections">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table"></i> Sections</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" id="btnRefreshSections"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if (can('classes', 'a')): ?>
                            <button class="btn btn-success" id="btnAddSection"><i class="fas fa-plus"></i> Add Section</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filters-section">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" id="btnClearSectionFilter"><i class="fas fa-times-circle"></i> Clear</button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="filterSectionClass" class="filter-input">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classList as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="filterSectionStatus" class="filter-input">
                                    <option value="">All Status</option>
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="sectionsSkeleton">
                        <div class="skeleton-table">
                            <?php for ($i = 0; $i < 7; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="sectionsWrap" class="initially-hidden">
                        <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="table-responsive">
                            <table id="sectionsTable" class="display table-full-width"></table>
                        </div>
                    </div>

                    <div id="sectionsEmpty" class="orms-empty initially-hidden">
                        <i class="fas fa-layer-group"></i>
                        <h4>No sections yet</h4>
                        <p><?php echo can('classes', 'a') ? 'Create a class first, then use <strong>Add Section</strong> to split it into A, B, C…' : 'Ask an admin to add sections to the classes.'; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Class Modal -->
    <div class="modal-overlay" id="classModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="classModalTitle"><i class="fas fa-school"></i> Add Class</h3>
                <button class="close-btn" id="btnCloseClassModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="classForm">
                    <input type="hidden" id="classId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Class Name *</label>
                            <input type="text" id="className" name="name" maxlength="50" required placeholder="e.g. Class 5">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-list-ol"></i> Level</label>
                            <input type="number" id="classLevel" name="numeric_level" min="1" max="99" placeholder="e.g. 10">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Numeric rank used for ordering, so Class 10 lands after Class 2. Leave empty to fall back to the sort order.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-sort-numeric-down"></i> Sort Order</label>
                            <input type="number" id="classSort" name="sort_order" min="0" max="9999" value="0">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Lower numbers appear first when no level is set.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-right-long"></i> Promotes Into</label>
                            <select id="classNext" name="next_class_id">
                                <option value="">No next class</option>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> The class students move up into next year. Optional.</div>
                        </div>
                        <?php if ($hasSets): ?>
                        <div class="form-group">
                            <label><i class="fas fa-scale-balanced"></i> Grading Set</label>
                            <select id="classGradingSet" name="grading_set_id">
                                <option value="">&mdash; inherit school default &mdash;</option>
                                <?php foreach ($gsList as $g): ?>
                                <option value="<?php echo (int)$g['id']; ?>"><?php
                                    echo htmlspecialchars($g['name'] . (isset($g['is_active']) && !$g['is_active'] ? ' — inactive' : ''));
                                ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Changing the grading set changes how this class&rsquo;s <strong>future</strong> results are graded &mdash; cards already published keep the set they were graded with.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Assessment Scheme</label>
                            <select id="classScheme" name="assessment_scheme_id">
                                <option value="">&mdash; no components (single mark) &mdash;</option>
                                <?php foreach ($ascList as $a): ?>
                                <option value="<?php echo (int)$a['id']; ?>"><?php
                                    echo htmlspecialchars($a['name'] . (isset($a['is_active']) && !$a['is_active'] ? ' — inactive' : ''));
                                ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Splits each subject into CA and exam boxes for <strong>future</strong> marks entry &mdash; marks already saved keep the scheme they were entered under, and a scheme overrides this class&rsquo;s theory/practical split.</div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Active</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="classActive" name="is_active" value="1" class="toggle-input" checked>
                                <label for="classActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-ranking-star"></i> Show Position on Card</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="classShowPos" name="show_position" value="1" class="toggle-input" checked>
                                <label for="classShowPos" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Off = result cards of this class hide rank/position — common for junior classes. Staff screens still see it.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveClass"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelClass"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Section Modal -->
    <div class="modal-overlay" id="sectionModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="sectionModalTitle"><i class="fas fa-layer-group"></i> Add Section</h3>
                <button class="close-btn" id="btnCloseSectionModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="sectionForm">
                    <input type="hidden" id="sectionId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-school"></i> Class *</label>
                            <select id="sectionClassId" name="class_id" required>
                                <option value="">Select class</option>
                                <?php foreach ($classList as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Section Name *</label>
                            <input type="text" id="sectionName" name="name" maxlength="20" required placeholder="e.g. A">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Capacity</label>
                            <input type="number" id="sectionCapacity" name="capacity" min="1" placeholder="Leave empty for no limit">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-door-open"></i> Room No</label>
                            <input type="text" id="sectionRoom" name="room_no" maxlength="20" placeholder="e.g. R-12">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-chalkboard-user"></i> Class Teacher</label>
                            <select id="sectionTeacher" name="class_teacher_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($teacherList as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"><?php
                                    echo htmlspecialchars($t['full_name'] . ' (' . $t['employee_no'] . ')' . ($t['status'] !== 'Active' ? ' — inactive' : ''));
                                ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Signs off this section's result cards. Pick <strong>Unassigned</strong> to clear it.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Active</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="sectionActive" name="is_active" value="1" class="toggle-input" checked>
                                <label for="sectionActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveSection"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelSection"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <input type="file" id="classCsvInput" accept=".csv,text/csv" class="initially-hidden">

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
    var classesTable = null, sectionsTable = null;
    var classesData = [], sectionsData = [];
    var CAN = { e: <?= can('classes', 'e') ? 'true' : 'false' ?>, d: <?= can('classes', 'd') ? 'true' : 'false' ?> };
    // per-class grading rules — id => name maps so the list can label what a class inherits
    var HAS_SETS = <?= $hasSets ? 'true' : 'false' ?>;
    var GSETS    = <?= json_encode(array_column($gsList,  'name', 'id')) ?>;
    var SCHEMES  = <?= json_encode(array_column($ascList, 'name', 'id')) ?>;
    var DEF_SET  = <?= json_encode($defSetName) ?>;

    $(document).ready(function() {
        ORMS.dropdown('#sectionClassId, #filterSectionClass, #filterSectionStatus, #filterClassStatus, #sectionTeacher, #classNext');
        if (HAS_SETS) ORMS.dropdown('#classGradingSet, #classScheme');
        loadClasses();
        loadSections();

        $('.tab-btn').on('click', function() {
            var id = $(this).data('tab'), $btn = $(this);
            ORMS.swap(function () {                       // crossfade, not a snap
                $('.tab-btn').removeClass('active');
                $btn.addClass('active');
                $('.tab-pane').removeClass('active');
                $('#' + id).addClass('active');
                // dt measures 0-width columns while hidden — recalc on reveal
                var t = id === 'tabClasses' ? classesTable : sectionsTable;
                if (t) t.columns.adjust().responsive.recalc();
            });
        });

        $('#btnRefreshClasses').on('click', function() { loadClasses(this); });
        $('#btnRefreshSections').on('click', function() { loadSections(this); });
        $('#btnAddClass').on('click', openAddClass);
        $('#btnAddSection').on('click', openAddSection);
        $('#btnCloseClassModal, #btnCancelClass').on('click', function() { closeModal('#classModal'); });
        $('#btnCloseSectionModal, #btnCancelSection').on('click', function() { closeModal('#sectionModal'); });
        $('#classModal, #sectionModal').on('click', function(e) { if (e.target === this) closeModal(this); });

        $('#filterSectionClass').on('change', function() {
            if (!sectionsTable) return;
            var v = this.value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); // exact match, name may hold regex chars
            sectionsTable.column(0).search(this.value ? '^' + v + '$' : '', true, false).draw();
        });
        $('#btnClearSectionFilter').on('click', function() {
            $('#filterSectionClass').val('');
            $('#filterSectionStatus').val('');
            ORMS.dropdown.refresh('#filterSectionClass, #filterSectionStatus');
            $('#filterSectionClass').trigger('change');
        });

        // status reads is_active off the row data — never off the rendered badge, which is markup
        $('#filterClassStatus').on('change', function() { if (classesTable) classesTable.draw(); });
        $('#filterSectionStatus').on('change', function() { if (sectionsTable) sectionsTable.draw(); });
        $('#btnClearClassFilter').on('click', function() {
            $('#filterClassStatus').val('');
            ORMS.dropdown.refresh('#filterClassStatus');
            if (classesTable) classesTable.draw();
        });

        // one predicate pair, registered once: each only bites on its own table
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id === 'classesTable') {
                var v = $('#filterClassStatus').val();
                if (v !== '' && v !== undefined) return String(classesData[dataIndex]?.is_active ?? 1) === v;
            }
            if (settings.nTable.id === 'sectionsTable') {
                var w = $('#filterSectionStatus').val();
                if (w !== '' && w !== undefined) return String(sectionsData[dataIndex]?.is_active ?? 1) === w;
            }
            return true;
        });
    });

    function blank(v) { return v === null || v === undefined || v === ''; }

    function badge(active) {
        return active == 1
            ? '<span class="status-badge status-active"><i class="fas fa-check"></i> Active</span>'
            : '<span class="status-badge status-inactive"><i class="fas fa-ban"></i> Inactive</span>';
    }

    // null on the class = it inherits, so say what it inherits instead of a bare dash
    function setCell(name, inherited) {
        return name ? '<span class="subject-chip">' + esc(name) + '</span>'
                    : '<span class="text-muted">' + esc(inherited) + '</span>';
    }

    function rowActions(id, active, kind) {
        var b = '';
        if (CAN.e) {
            b += '<button class="action-icon edit-icon" title="Edit" onclick="edit' + kind + '(' + id + ')"><i class="fas fa-edit"></i></button>';
            b += '<button class="action-icon view-icon" title="' + (active == 1 ? 'Deactivate' : 'Activate') + '" onclick="toggle' + kind + '(' + id + ', this)">' +
                 '<i class="fas fa-' + (active == 1 ? 'toggle-on' : 'toggle-off') + '"></i></button>';
        }
        if (CAN.d) b += '<button class="action-icon delete-icon" title="Delete" onclick="delete' + kind + '(' + id + ', this)"><i class="fas fa-trash"></i></button>';
        return b || '<span class="text-muted">—</span>';
    }

    function dtOpts(extra) {
        return $.extend({
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            destroy: true,
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
        }, extra);
    }

    // ------------------------------------------------------------- classes
    function loadClasses(btn) {
        ORMS.post('getClasses', {}, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load classes'); return; }
            classesData = res.data || [];
            $('#classesSkeleton').addClass('initially-hidden');
            // nothing to show -> icon+message, never a blank table body
            $('#classesWrap').toggleClass('initially-hidden', !classesData.length);
            $('#classesEmpty').toggleClass('initially-hidden', !!classesData.length);
            if (classesTable) { classesTable.destroy(); $('#classesTable').empty(); }
            classesTable = $('#classesTable').DataTable(dtOpts({
                data: classesData,
                order: [[1, 'asc'], [0, 'asc']],
                columns: [
                    { data: 'name', title: 'Class', render: function(d) { return '<i class="fas fa-school text-muted"></i> ' + esc(d); } },
                    { data: 'numeric_level', title: 'Level', render: function(d, t, row) {
                        // sort on level, falling back to sort_order so unset classes still land sensibly
                        if (t === 'sort' || t === 'type') return blank(d) ? Number(row.sort_order) || 0 : Number(d);
                        return blank(d) ? '<span class="text-muted">—</span>' : esc(String(d));
                    } },
                    { data: 'sort_order', title: 'Sort Order' },
                    { data: 'next_class_name', title: 'Promotes Into', render: function(d) {
                        return d ? '<span class="subject-chip"><i class="fas fa-arrow-right-long"></i> ' + esc(d) + '</span>'
                                 : '<span class="text-muted">—</span>';
                    } },
                    { data: 'sections', title: 'Sections', render: function(d) { return '<span class="subject-chip"><i class="fas fa-layer-group"></i> ' + d + '</span>'; } },
                    { data: 'students', title: 'Students', render: function(d) { return '<span class="subject-chip"><i class="fas fa-user-graduate"></i> ' + d + '</span>'; } }
                ].concat(HAS_SETS ? [
                    { data: 'grading_set_id', title: 'Grading Set', render: function(d) { return setCell(GSETS[d], DEF_SET + ' (inherited)'); } },
                    { data: 'assessment_scheme_id', title: 'Assessment Scheme', render: function(d) { return setCell(SCHEMES[d], 'Single mark (no components)'); } }
                ] : []).concat([
                    { data: 'is_active', title: 'Status', render: function(d) { return badge(d); } },
                    { data: null, title: 'Actions', orderable: false,
                      render: function(d, t, row) { return rowActions(row.id, row.is_active, 'Class'); } }
                ])
            }));
        }).fail(function(msg) { ORMS.err(msg); });
    }

    // promotion targets = the other classes, rebuilt from live data so a fresh class shows up without a reload
    function fillNextClass(selfId, chosen) {
        var $s = $('#classNext').empty().append($('<option>').val('').text('No next class'));
        classesData.forEach(function(c) {
            if (selfId && c.id == selfId) return;                  // a class can never promote into itself
            $s.append($('<option>').val(c.id).text(c.name));
        });
        $s.val(blank(chosen) ? '' : String(chosen));
        ORMS.dropdown.refresh('#classNext');
    }

    function openAddClass() {
        $('#classModalTitle').html('<i class="fas fa-school"></i> Add Class');
        $('#classForm')[0].reset();
        $('#classId').val('');
        $('#classActive').prop('checked', true);
        $('#classShowPos').prop('checked', true);
        setRules('', '');
        fillNextClass(0, '');
        $('#classModal').addClass('active');
        setTimeout(function() { $('#className').trigger('focus'); }, 60);
    }

    function editClass(id) {
        var c = classesData.filter(function(x) { return x.id == id; })[0];
        if (!c) return;
        $('#classModalTitle').html('<i class="fas fa-edit"></i> Edit Class');
        $('#classId').val(c.id);
        $('#className').val(c.name);
        $('#classLevel').val(blank(c.numeric_level) ? '' : c.numeric_level);
        $('#classSort').val(c.sort_order);
        $('#classActive').prop('checked', c.is_active == 1);
        $('#classShowPos').prop('checked', c.show_position === undefined || c.show_position == 1);
        setRules(c.grading_set_id, c.assessment_scheme_id);
        fillNextClass(c.id, c.next_class_id);
        $('#classModal').addClass('active');
    }

    // blank = inherit the school default / no components — never invent a value the class doesn't hold
    function setRules(gset, scheme) {
        if (!HAS_SETS) return;
        $('#classGradingSet').val(blank(gset) ? '' : String(gset));
        $('#classScheme').val(blank(scheme) ? '' : String(scheme));
        ORMS.dropdown.refresh('#classGradingSet, #classScheme');
    }

    function toggleClass(id, btn) {
        ORMS.post('toggleClass', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
            res.success ? (ORMS.ok(res.message), loadClasses()) : ORMS.err(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function deleteClass(id, btn) {
        var c = classesData.filter(function(x) { return x.id == id; })[0];
        ORMS.confirmDelete('Delete class "' + (c ? c.name : '') + '"? Only unused classes can be removed.').then(function(yes) {
            if (!yes) return;
            ORMS.post('deleteClass', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                res.success ? (ORMS.ok(res.message), loadClasses(), loadSections()) : ORMS.err(res.message, 'Cannot Delete');
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    $('#classForm').on('submit', function(e) {
        e.preventDefault();
        var name = $('#className').val().trim(), sort = parseInt($('#classSort').val(), 10);
        var lvl = $('#classLevel').val().trim(), next = $('#classNext').val() || '';
        if (!name) { ORMS.err('Class name is required'); return; }
        if (isNaN(sort) || sort < 0) { ORMS.err('Sort order must be 0 or more'); return; }
        if (lvl !== '' && (!/^\d+$/.test(lvl) || +lvl < 1 || +lvl > 99)) { ORMS.err('Level must be a whole number between 1 and 99'); return; }

        ORMS.post('saveClass', {
            id: $('#classId').val(), name: name, sort_order: sort,
            numeric_level: lvl, next_class_id: next,
            grading_set_id: HAS_SETS ? ($('#classGradingSet').val() || '') : '',
            assessment_scheme_id: HAS_SETS ? ($('#classScheme').val() || '') : '',
            is_active: $('#classActive').is(':checked') ? 1 : 0,
            show_position: $('#classShowPos').is(':checked') ? 1 : 0
        }, { btn: '#btnSaveClass', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            closeModal('#classModal');
            ORMS.ok(res.message);
            loadClasses();
            loadSections();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    // ------------------------------------------------------------ sections
    function loadSections(btn) {
        ORMS.post('getSections', {}, { btn: btn, busyLabel: 'Loading…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load sections'); return; }
            sectionsData = res.data || [];
            $('#sectionsSkeleton').addClass('initially-hidden');
            // nothing to show -> icon+message, never a blank table body
            $('#sectionsWrap').toggleClass('initially-hidden', !sectionsData.length);
            $('#sectionsEmpty').toggleClass('initially-hidden', !!sectionsData.length);
            if (sectionsTable) { sectionsTable.destroy(); $('#sectionsTable').empty(); }
            sectionsTable = $('#sectionsTable').DataTable(dtOpts({
                data: sectionsData,
                order: [[0, 'asc'], [1, 'asc']],
                columns: [
                    { data: 'class_name', title: 'Class', render: function(d) { return esc(d); } },
                    { data: 'name', title: 'Section', render: function(d) { return '<i class="fas fa-layer-group text-muted"></i> ' + esc(d); } },
                    { data: 'teacher_name', title: 'Class Teacher', render: function(d, t, row) {
                        // name that prints on the result card signature line
                        if (blank(d)) return t === 'display' ? '<span class="text-muted">Unassigned</span>' : '';
                        var txt = d + (row.teacher_emp ? ' (' + row.teacher_emp + ')' : '');
                        return t === 'display' ? '<i class="fas fa-chalkboard-user text-muted"></i> ' + esc(txt) : txt;
                    } },
                    { data: 'room_no', title: 'Room', render: function(d) { return blank(d) ? '<span class="text-muted">—</span>' : esc(d); } },
                    { data: 'capacity', title: 'Capacity', render: function(d) { return blank(d) ? '<span class="text-muted">No limit</span>' : d; } },
                    { data: 'students', title: 'Students', render: function(d) { return '<span class="subject-chip"><i class="fas fa-user-graduate"></i> ' + d + '</span>'; } },
                    { data: 'is_active', title: 'Status', render: function(d) { return badge(d); } },
                    { data: null, title: 'Actions', orderable: false,
                      render: function(d, t, row) { return rowActions(row.id, row.is_active, 'Section'); } }
                ]
            }));
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function openAddSection() {
        $('#sectionModalTitle').html('<i class="fas fa-layer-group"></i> Add Section');
        $('#sectionForm')[0].reset();
        $('#sectionId').val('');
        $('#sectionClassId').val('');
        $('#sectionTeacher').val('');
        $('#sectionActive').prop('checked', true);
        ORMS.dropdown.refresh('#sectionClassId, #sectionTeacher');
        $('#sectionModal').addClass('active');
        setTimeout(function() { $('#sectionName').trigger('focus'); }, 60);
    }

    function editSection(id) {
        var s = sectionsData.filter(function(x) { return x.id == id; })[0];
        if (!s) return;
        $('#sectionModalTitle').html('<i class="fas fa-edit"></i> Edit Section');
        $('#sectionId').val(s.id);
        $('#sectionClassId').val(s.class_id);
        $('#sectionName').val(s.name);
        $('#sectionCapacity').val(blank(s.capacity) ? '' : s.capacity);
        $('#sectionRoom').val(blank(s.room_no) ? '' : s.room_no);
        $('#sectionTeacher').val(blank(s.class_teacher_id) ? '' : String(s.class_teacher_id));
        $('#sectionActive').prop('checked', s.is_active == 1);
        ORMS.dropdown.refresh('#sectionClassId, #sectionTeacher');
        $('#sectionModal').addClass('active');
    }

    function toggleSection(id, btn) {
        ORMS.post('toggleSection', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
            res.success ? (ORMS.ok(res.message), loadSections()) : ORMS.err(res.message);
        }).fail(function(msg) { ORMS.err(msg); });
    }

    function deleteSection(id, btn) {
        var s = sectionsData.filter(function(x) { return x.id == id; })[0];
        ORMS.confirmDelete('Delete section "' + (s ? s.class_name + ' - ' + s.name : '') + '"? Only empty sections can be removed.').then(function(yes) {
            if (!yes) return;
            ORMS.post('deleteSection', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                res.success ? (ORMS.ok(res.message), loadSections(), loadClasses()) : ORMS.err(res.message, 'Cannot Delete');
            }).fail(function(msg) { ORMS.err(msg); });
        });
    }

    $('#sectionForm').on('submit', function(e) {
        e.preventDefault();
        var classId = $('#sectionClassId').val(), name = $('#sectionName').val().trim(), cap = $('#sectionCapacity').val().trim();
        var room = $('#sectionRoom').val().trim(), teacher = $('#sectionTeacher').val() || '';
        if (!classId) { ORMS.err('Please select a class'); return; }
        if (!name) { ORMS.err('Section name is required'); return; }
        if (cap !== '' && (!/^\d+$/.test(cap) || parseInt(cap, 10) < 1)) { ORMS.err('Capacity must be a whole number of 1 or more'); return; }

        ORMS.post('saveSection', {
            id: $('#sectionId').val(), class_id: classId, name: name, capacity: cap,
            room_no: room, class_teacher_id: teacher,
            is_active: $('#sectionActive').is(':checked') ? 1 : 0
        }, { btn: '#btnSaveSection', busyLabel: 'Saving…' }).done(function(res) {
            if (!res.success) { ORMS.err(res.message); return; }
            closeModal('#sectionModal');
            ORMS.ok(res.message);
            loadSections();
            loadClasses();
        }).fail(function(msg) { ORMS.err(msg); });
    });

    function closeModal(sel) { $(sel).removeClass('active'); }

    // ---------- csv template + import ----------
    var CLS_CSV_HEAD_ES = ['grado', 'nivel', 'orden', 'secciones'];
    var CLS_CSV_HEAD_EN = ['class', 'level', 'sort_order', 'sections'];
    var CLS_CSV_HEAD = CLS_CSV_HEAD_ES;

    function clsCleanHead(s) {
        return String(s || '').trim().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9_]/g, '_');
    }

    function clsHeaderMatches(head) {
        if (!head || !head.length) return false;
        var hClean = head.map(clsCleanHead);
        var esClean = CLS_CSV_HEAD_ES.map(clsCleanHead);
        var enClean = CLS_CSV_HEAD_EN.map(clsCleanHead);
        var altClean = ['clase', 'nivel', 'orden', 'secciones'].map(clsCleanHead);
        if (hClean.length !== esClean.length) return false;
        return (hClean.join('|') === esClean.join('|')) || (hClean.join('|') === enClean.join('|')) || (hClean.join('|') === altClean.join('|'));
    }

    $('#btnClassTemplate').on('click', function() {
        ORMS.downloadCSV('plantilla_importar_grados.csv', [
            CLS_CSV_HEAD_ES,
            ['Grado 1', '1', '1', 'A | B'],
            ['Grado 2', '2', '2', 'A | B | C']
        ]);
        ORMS.ok('Plantilla descargada con éxito');
    });

    $('#btnImportClasses').on('click', function() { document.getElementById('classCsvInput').click(); });

    document.getElementById('classCsvInput').addEventListener('change', function() {
        var file = this.files && this.files[0];
        var input = this;
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            input.value = '';
            var rows = ORMS.parseCSV(String(ev.target.result || ''));
            if (!rows.length) { ORMS.err('El archivo seleccionado está vacío'); return; }

            var head = rows[0] || [];
            if (!clsHeaderMatches(head)) {
                ORMS.err('La fila de encabezados debe coincidir con la plantilla:<br><br><b>' + CLS_CSV_HEAD_ES.join(', ') + '</b>', 'Formato CSV incorrecto');
                return;
            }

            var body = rows.slice(1).filter(function(r) { return (r || []).join('').trim() !== ''; });
            if (!body.length) { ORMS.err('No se encontraron filas con datos debajo del encabezado'); return; }

            Swal.fire({
                icon: 'question', title: '¿Importar ' + body.length + ' fila(s)?',
                html: 'Se crearán los grados que no existan y se añadirán las secciones que falten (escríbalas separadas por barra vertical: <b>A | B | C</b>).<br>Las filas duplicadas o con errores serán omitidas y reportadas.',
                showCancelButton: true, confirmButtonText: '<i class="fas fa-file-import"></i> Importar', cancelButtonText: 'Cancelar'
            }).then(function(x) {
                if (!x.isConfirmed) return;
                // longest write on the page — the toolbar button carries the spinner
                ORMS.post('bulkImportClasses', { rows: JSON.stringify(body) },
                          { btn: '#btnImportClasses', busyLabel: 'Importando…' }).done(function(res) {
                    if (!res || !res.success) { ORMS.err((res && res.message) || 'Error en la importación'); return; }
                    clsImportReport(res);
                    loadClasses();
                    loadSections();
                    Swal.fire({
                        icon: res.skipped ? 'warning' : 'success',
                        title: res.message,
                        text: res.skipped ? 'Las filas omitidas se detallan en el cuadro sobre la tabla.' : 'Todos los registros se importaron correctamente.'
                    });
                }).fail(function(msg) { ORMS.err(msg); });
            });
        };
        reader.readAsText(file);
    });

    function clsImportReport(res) {
        var box = $('#importResult');
        if (!res.errors || !res.errors.length) {
            box.html('<div class="info-banner"><i class="fas fa-circle-check"></i> <span>' + ORMS.esc(res.message) + '.</span></div>').show();
            return;
        }
        var html = '<div class="info-banner info-banner-warning"><i class="fas fa-triangle-exclamation"></i> <span>' +
            ORMS.esc(res.message) + ' — las filas mostradas a continuación no se guardaron.</span></div>' +
            '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr><th><i class="fas fa-list-ol"></i> #</th><th><i class="fas fa-circle-exclamation"></i> Motivo</th></tr></thead><tbody>';
        res.errors.forEach(function(e, i) { html += '<tr><td>' + (i + 1) + '</td><td>' + ORMS.esc(e) + '</td></tr>'; });
        html += '</tbody></table></div>';
        box.html(html).show();
    }
    </script>
</body>
</html>
