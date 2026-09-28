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
requirePerm('attendance', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = (int)$_SESSION['user_id'];
$current_page = 'attendance';

// identity decides reach — admin/principal see every section, everyone else falls to teacher_subjects
$isWide  = ormsSchoolWide($role);
$canAdd  = can('attendance', 'a');
$canEdit = can('attendance', 'e');
$canSave = $canAdd || $canEdit;

// table there yet? one probe per request — an un-migrated db degrades, never fatals
function attReady(): bool {
    static $has = null;
    if ($has !== null) return $has;
    if (function_exists('ormsHasAttendance')) return $has = ormsHasAttendance();
    try { qVal("SELECT 1 FROM attendance_summary LIMIT 1"); $has = true; }
    catch (Throwable $e) { $has = false; }
    return $has;
}

// post first, get as fallback — the ajax funnel drops this page's query string
function attIn(string $k, $d = '') { return $_POST[$k] ?? ($_GET[$k] ?? $d); }

// column probe, one per request — an install that hasn't taken the migration keeps the old behaviour
function attHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

function attTenant(): bool { return attHasCol('classes', 'school_id'); }

// sections this user may touch — ONE shared definition (ormsSectionScope), cached per year.
// a db without school_id has nothing to join the fence on, so it falls back to the plain read
function attSections(int $yearId): array {
    static $c = [];
    if (isset($c[$yearId])) return $c[$yearId];
    if (attTenant())      return $c[$yearId] = ormsSectionScope(null, null, $yearId);
    if (ormsSchoolWide()) return $c[$yearId] = array_map('intval', array_column(qAll("SELECT id FROM sections"), 'id'));
    $tid = ormsTeacherId((int)($_SESSION['user_id'] ?? 0));
    return $c[$yearId] = $tid ? array_map('intval', array_column(qAll(
        "SELECT DISTINCT section_id FROM teacher_subjects WHERE teacher_id = ? AND academic_year_id = ?",
        'ii', $tid, $yearId), 'section_id')) : [];
}

// posted id -> verified row id, 0 when another school owns it
function attOwns(string $table, $raw): int {
    $id = (int)$raw;
    if ($id <= 0) return 0;
    return attTenant() ? ormsOwns($table, $id) : $id;
}

// classes fence for the outermost FROM -> [sql, types, params]
function attWhere(string $a = 'c'): array {
    if (!attTenant()) return ['', '', []];
    $b = attHasCol('classes', 'branch_id') ? bid() : 0;
    return $b ? [" AND $a.school_id = ? AND $a.branch_id = ?", 'ii', [sid(), $b]]
              : [" AND $a.school_id = ?", 'i', [sid()]];
}

// this school's years only — the shared lookup is school-blind. one id read, never a probe per row
function attYears(): array {
    $all = ormsYears();
    if (!attTenant() || !$all) return $all;
    try {
        $mine = array_flip(array_map('intval', array_column(qAll("SELECT id FROM academic_years WHERE school_id = ?", 'i', sid()), 'id')));
        return array_values(array_filter($all, fn($y) => isset($mine[(int)$y['id']])));
    } catch (Throwable $e) { return $all; }
}

function attCurYear(): ?array {
    $c = ormsCurrentYear();
    return ($c && (!attTenant() || ormsFindYear((int)$c['id']))) ? $c : null;
}

// class + section lists for the cascading filters, already narrowed to what this user may record
function attScopeLists(int $yearId): array {
    $scope = attSections($yearId);
    if (!$scope) return ['classes' => [], 'sections' => []];

    [$w, $wt, $wp] = attWhere('c');                          // fence on the driver, not only the section list
    $sql = "SELECT sec.id, sec.class_id, sec.name, c.name AS class_name
            FROM sections sec JOIN classes c ON c.id = sec.class_id
            WHERE sec.is_active = 1 AND c.is_active = 1$w
              AND sec.id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")
            ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC";
    $types  = $wt . str_repeat('i', count($scope));
    $params = array_merge($wp, $scope);

    $classes = $sections = [];
    foreach (qAll($sql, $types, ...$params) as $r) {
        $cid = (int)$r['class_id'];
        $classes[$cid] = ['id' => $cid, 'name' => $r['class_name']];
        $sections[]    = ['id' => (int)$r['id'], 'class_id' => $cid, 'name' => $r['name']];
    }
    return ['classes' => array_values($classes), 'sections' => $sections];
}

function attSection(int $sectionId): ?array {
    [$w, $wt, $wp] = attWhere('c');
    return qOne("SELECT sec.id, sec.name AS section_name, c.id AS class_id, c.name AS class_name
                 FROM sections sec JOIN classes c ON c.id = sec.class_id WHERE sec.id = ?$w",
                'i' . $wt, $sectionId, ...$wp);
}

function attLabel(array $sec): string { return $sec['class_name'] . ' – ' . $sec['section_name']; }

// active roster, numeric roll first and blanks last — same order the score sheet uses
function attRoster(int $sectionId): array {
    $out = [];
    $w   = attHasCol('students', 'school_id') ? " AND st.school_id = ?" : "";
    foreach (qAll("SELECT st.id, st.roll_no, st.admission_no, u.full_name, u.username
                   FROM students st JOIN users u ON u.id = st.user_id
                   WHERE st.section_id = ? AND st.status = 'Active'$w
                   ORDER BY (st.roll_no IS NULL OR st.roll_no = '') ASC,
                            CAST(st.roll_no AS UNSIGNED) ASC, st.roll_no ASC, u.full_name ASC",
                  'i' . ($w ? 'i' : ''), $sectionId, ...($w ? [sid()] : [])) as $r) {
        $out[(int)$r['id']] = [
            'roll' => (string)($r['roll_no'] ?? ''),
            'adm'  => (string)$r['admission_no'],
            'name' => ($r['full_name'] !== null && $r['full_name'] !== '') ? $r['full_name'] : $r['username']
        ];
    }
    return $out;
}

// what is already recorded for this term — one read keyed on the uniq (student, term) index
function attExisting(int $termId, array $studentIds): array {
    if (!$studentIds) return [];
    $ph  = implode(',', array_fill(0, count($studentIds), '?'));
    $out = [];
    $w   = attHasCol('attendance_summary', 'school_id') ? " AND school_id = ?" : "";
    foreach (qAll("SELECT student_id, days_present, days_total, remarks
                   FROM attendance_summary WHERE term_id = ? AND student_id IN ($ph)$w",
                  'i' . str_repeat('i', count($studentIds)) . ($w ? 'i' : ''),
                  $termId, ...array_merge($studentIds, $w ? [sid()] : [])) as $r) {
        $out[(int)$r['student_id']] = ['p' => round((float)$r['days_present'], 1),
                                       't' => round((float)$r['days_total'], 1),
                                       'r' => (string)($r['remarks'] ?? '')];
    }
    return $out;
}

// 60.0 -> "60", 60.5 -> "60.5". nothing prints a pointless trailing zero
function attNum($n) {
    $s = rtrim(rtrim(number_format((float)$n, 1, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

// spreadsheet whitespace incl nbsp. $all strips it everywhere, else just the ends
function attTrim($v, $all = false) {
    $s = (string)$v;
    $r = preg_replace($all ? '/[\s\x{00A0}]+/u' : '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s);
    return $r === null ? trim($s) : $r;
}

// header matching: case, spacing and underscores all ignored
function attNorm($s) {
    return mb_strtolower(preg_replace('/[\s_]+/', ' ', attTrim($s)), 'UTF-8');
}

// one day box: blank -> null, else >= 0 with at most one decimal (half days). false = rejected
function attVal($raw, string $label, string &$err) {
    $s = attTrim($raw, true);
    if ($s === '' || $s === '-' || $s === '–' || $s === '—') return null;
    if (!preg_match('/^\d+(\.\d)?$/', $s)) {
        $err = $label . ' must be 0 or more, with at most one decimal place for half days — "' . attTrim($raw) . '" is not.';
        return false;
    }
    $n = round((float)$s, 1);
    if ($n > 999) { $err = $label . ' of ' . attNum($n) . ' looks too large — 999 or less.'; return false; }
    return $n;
}

// rfc 4180 line: quote only when it matters, "" escapes a quote
function attCsvRow(array $r) {
    return implode(',', array_map(function ($v) {
        $s = (string)$v;
        return preg_match('/[",\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }, $r)) . "\r\n";
}

// formula guard for free text — a leading =, + or @ is neutralised, never dropped
function attCsvSafe($s) {
    $s = (string)$s;
    return ($s !== '' && strpos('=+@', $s[0]) !== false) ? "'" . $s : $s;
}

// rfc 4180 parse, mirrors ORMS.parseCSV: quoted fields, "" escape, embedded commas/newlines, crlf|lf|cr
function attParseCsv($text) {
    $rows = []; $row = []; $val = ''; $inQ = false;
    $s = (string)$text;
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);   // excel bom
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($inQ) {
            if ($c !== '"') { $val .= $c; continue; }
            if (($s[$i + 1] ?? '') === '"') { $val .= '"'; $i++; } else $inQ = false;
        } elseif ($c === '"') {
            $inQ = true;
        } elseif ($c === ',') {
            $row[] = $val; $val = '';
        } elseif ($c === "\n" || $c === "\r") {
            if ($c === "\r" && ($s[$i + 1] ?? '') === "\n") $i++;   // crlf
            $row[] = $val; $rows[] = $row; $row = []; $val = '';
        } else {
            $val .= $c;
        }
    }
    if ($val !== '' || $row) { $row[] = $val; $rows[] = $row; }
    return $rows;
}

// the offline sheet — same shape for template and export, so a round trip always matches
function attSheet(array $sec, array $term, array $roster, array $exist, bool $blank, float $fillTotal): string {
    $out = '# ORMS Attendance Sheet | ' . attLabel($sec) . ' | ' . $term['name'] . ' | Generated ' . date('Y-m-d') . "\r\n"
         . "# Days Present = days the student came. Days in Session = times the school was open this term.\r\n"
         . "# Half days are allowed (one decimal). Days Present can never be more than Days in Session.\r\n"
         . "# Leave Days Present blank to clear a student's attendance for this term.\r\n"
         . "# Do not rename the Admission No column or the two day columns.\r\n";
    $out .= attCsvRow(['Admission No', 'Roll No', 'Student Name', 'Days Present', 'Days in Session', 'Remarks']);

    foreach ($roster as $stu => $st) {
        $a = $blank ? null : ($exist[$stu] ?? null);
        $out .= attCsvRow([
            $st['adm'], $st['roll'], attCsvSafe($st['name']),
            $a ? attNum($a['p']) : '',
            $a ? attNum($a['t']) : ($fillTotal > 0 ? attNum($fillTotal) : ''),
            attCsvSafe($a['r'] ?? '')
        ]);
    }
    return $out;
}

// the prefill for the bulk box — whatever most of the section already carries, else the school setting
function attDefaultDays(array $exist): float {
    $tally = [];
    foreach ($exist as $a) if ($a['t'] > 0) { $k = (string)$a['t']; $tally[$k] = ($tally[$k] ?? 0) + 1; }
    if ($tally) { arsort($tally); return (float)array_key_first($tally); }
    return round((float)(getSetting('attendance_default_days', '0') ?: 0), 1);
}

// everything a save/import needs, resolved once
function attCtx(array $term, array $sec, int $userId, bool $canAdd, bool $canEdit): array {
    $roster = attRoster((int)$sec['id']);
    return ['roster' => $roster,
            'exist'      => attExisting((int)$term['id'], array_keys($roster)),
            'term_id'    => (int)$term['id'],
            'section_id' => (int)$sec['id'],
            'year_id'    => (int)$term['academic_year_id'],
            'user_id'    => $userId,
            'can_add'    => $canAdd,
            'can_edit'   => $canEdit];
}

// validate + write. skip-and-collect, never fail-fast. $dry runs every decision and writes nothing
function attPersist(array $ctx, array $rows, bool $dry = false): array {
    $roster = $ctx['roster']; $exist = $ctx['exist'];
    $out = ['saved' => 0, 'added' => 0, 'updated' => 0, 'cleared' => 0, 'skipped' => 0, 'errors' => []];
    $ops = $seen = [];

    foreach ($rows as $r) {
        $stu = (int)($r['student_id'] ?? 0);
        $at  = attTrim($r['at'] ?? '');                  // "Row 7" for imports, empty for the grid
        $at  = $at === '' ? '' : $at . ' · ';

        if (!isset($roster[$stu])) {
            $out['skipped']++;
            $out['errors'][] = $at . 'Student #' . $stu . ' is not an active student of this section.';
            continue;
        }
        if (isset($seen[$stu])) {
            $out['skipped']++;
            $out['errors'][] = $at . $roster[$stu]['name'] . ' appears twice — only the first row was used.';
            continue;
        }
        $seen[$stu] = 1;

        $who = $at . 'Roll ' . ($roster[$stu]['roll'] !== '' ? $roster[$stu]['roll'] : '#' . $stu) . ' · ' . $roster[$stu]['name'];
        $has = isset($exist[$stu]);

        $e1 = ''; $p = attVal($r['days_present'] ?? '', 'Days present',    $e1);
        $e2 = ''; $t = attVal($r['days_total']   ?? '', 'Days in session', $e2);
        if ($e1 !== '' || $e2 !== '') {
            $out['skipped']++;
            $out['errors'][] = $who . ': ' . ($e1 !== '' ? $e1 : $e2);
            continue;
        }

        $rem = mb_substr(attTrim($r['remarks'] ?? ''), 0, 255);   // varchar(255), chars not bytes

        // nothing recorded -> the row goes, never a 0 / 0 left behind for the card to print
        if ($p === null) {
            if (!$has) continue;                                   // nothing there, nothing to do
            if (!$ctx['can_edit']) {
                $out['skipped']++;
                $out['errors'][] = $who . ': you are not allowed to clear a recorded attendance.';
                continue;
            }
            $ops[] = ['del', $stu]; $out['cleared']++; $out['saved']++;
            continue;
        }
        if ($t === null || $t <= 0) {
            $out['skipped']++;
            $out['errors'][] = $who . ': enter how many days the school was in session before recording attendance.';
            continue;
        }
        if ($p > $t) {
            $out['skipped']++;
            $out['errors'][] = $who . ': present ' . attNum($p) . ' is more than the ' . attNum($t) . ' day(s) the school was in session.';
            continue;
        }
        if (!$has && !$ctx['can_add']) {
            $out['skipped']++;
            $out['errors'][] = $who . ': you are not allowed to add attendance.';
            continue;
        }
        if ($has && !$ctx['can_edit']) {
            $out['skipped']++;
            $out['errors'][] = $who . ': you are not allowed to change a recorded attendance.';
            continue;
        }
        // untouched row -> not a write. the grid posts every student, the counts must stay honest
        if ($has && $exist[$stu]['p'] === $p && $exist[$stu]['t'] === $t && $exist[$stu]['r'] === $rem) continue;

        $ops[] = ['set', $stu, $p, $t, $rem === '' ? null : $rem];
        $has ? $out['updated']++ : $out['added']++;
        $out['saved']++;
    }

    if ($dry || !$ops) return $out;

    $bStu = 0; $bP = 0.0; $bT = 0.0; $bRem = null;
    $bTerm = (int)$ctx['term_id']; $bSec = (int)$ctx['section_id'];
    $bYear = (int)$ctx['year_id']; $bBy = (int)$ctx['user_id'];
    $bSch  = sid();
    $tenant = attHasCol('attendance_summary', 'school_id');

    $conn = getDBConnection();
    $conn->begin_transaction();                          // one txn, two statements, executed in a loop
    try {
        $stmt = $conn->prepare(
            "INSERT INTO attendance_summary (student_id, term_id, section_id, academic_year_id,
                                             days_present, days_total, remarks, updated_by"
             . ($tenant ? ", school_id" : "") . ")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($tenant ? ", ?" : "") . ")
             ON DUPLICATE KEY UPDATE section_id       = VALUES(section_id),
                                     academic_year_id = VALUES(academic_year_id),
                                     days_present     = VALUES(days_present),
                                     days_total       = VALUES(days_total),
                                     remarks          = VALUES(remarks),
                                     updated_by       = VALUES(updated_by)");
        if (!$stmt) throw new RuntimeException($conn->error);
        // 8 cols: i student, i term, i section, i year, d present, d total, s remarks, i updated_by [+ i school]
        if ($tenant) $stmt->bind_param('iiiiddsii', $bStu, $bTerm, $bSec, $bYear, $bP, $bT, $bRem, $bBy, $bSch);
        else         $stmt->bind_param('iiiiddsi',  $bStu, $bTerm, $bSec, $bYear, $bP, $bT, $bRem, $bBy);

        $del = $conn->prepare("DELETE FROM attendance_summary WHERE student_id = ? AND term_id = ?"
                              . ($tenant ? " AND school_id = ?" : ""));
        if (!$del) throw new RuntimeException($conn->error);
        if ($tenant) $del->bind_param('iii', $bStu, $bTerm, $bSch);
        else         $del->bind_param('ii',  $bStu, $bTerm);

        foreach ($ops as $o) {
            $bStu = $o[1];
            if ($o[0] === 'del') { $del->execute(); continue; }
            $bP = $o[2]; $bT = $o[3]; $bRem = $o[4];
            $stmt->execute();
        }
        $stmt->close();
        $del->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('attendance persist: ' . $e->getMessage());
        jsonErr('Save failed — nothing was written. Please try again.');
    }
    return $out;
}

// preview and commit run the SAME path — $commit is the only difference, so they can never disagree
function attImportRun(bool $commit, array $term, array $sec, int $userId, string $username, bool $canAdd, bool $canEdit) {
    $csv = (string)attIn('csv', '');
    if (attTrim($csv) === '')   jsonErr('No attendance sheet was uploaded.');
    if (strlen($csv) > 1048576) jsonErr('That file is too big — import one section at a time.');

    $ctx    = attCtx($term, $sec, $userId, $canAdd, $canEdit);
    $issues = [];

    // header = first non-comment line; keys stay the source line index so errors point at the file
    $head = null; $body = [];
    foreach (attParseCsv($csv) as $i => $row) {
        $f0 = attTrim($row[0] ?? '');
        if ($f0 !== '' && $f0[0] === '#') continue;                 // comment
        if (attTrim(implode('', $row), true) === '') continue;      // blank line
        if ($head === null) { $head = $row; continue; }
        $body[$i] = $row;
    }
    if ($head === null)      jsonErr('That file has no header row.');
    if (!$body)              jsonErr('That file has no data rows under the header.');
    if (count($body) > 3000) jsonErr('Too many rows — import 3000 students or fewer at a time.');

    // header aliases so a hand-typed sheet still lands; the read-only helper columns are ignored
    $ALIAS = [
        'admission no' => 'adm', 'admissionno' => 'adm', 'admission number' => 'adm', 'adm no' => 'adm',
        'days present' => 'p', 'present' => 'p', 'days attended' => 'p', 'attended' => 'p',
        'days in session' => 't', 'days total' => 't', 'total days' => 't', 'days open' => 't',
        'school days' => 't', 'days school open' => 't', 'session days' => 't',
        'remarks' => 'r', 'remark' => 'r', 'note' => 'r', 'notes' => 'r'
    ];
    $SKIP = ['roll no' => 1, 'roll' => 1, 'student name' => 1, 'name' => 1, 'student' => 1,
             'attendance %' => 1, 'attendance' => 1, 'percent' => 1, '%' => 1];

    $col = [];
    foreach ($head as $ci => $h) {
        $n = attNorm($h);
        if ($n === '' || isset($SKIP[$n])) continue;
        if (!isset($ALIAS[$n])) { $issues[] = 'Column "' . attTrim($h) . '" is not part of the attendance sheet — it was ignored.'; continue; }
        $k = $ALIAS[$n];
        if (isset($col[$k])) { $issues[] = 'Column "' . attTrim($h) . '" appears twice — only the first was used.'; continue; }
        $col[$k] = $ci;
    }
    if (!isset($col['adm'])) jsonErr('The header row needs an "Admission No" column — download a fresh attendance sheet and fill that one in.');
    if (!isset($col['p']))   jsonErr('The header row needs a "Days Present" column — download a fresh attendance sheet and fill that one in.');
    if (!isset($col['t']))   jsonErr('The header row needs a "Days in Session" column — download a fresh attendance sheet and fill that one in.');

    // roster keyed by admission no, one query. status kept so "not active" gets its own message
    $byAdm = [];
    $sw    = attHasCol('students', 'school_id') ? " AND school_id = ?" : "";
    foreach (qAll("SELECT id, admission_no, status FROM students WHERE section_id = ?$sw",
                  'i' . ($sw ? 'i' : ''), (int)$sec['id'], ...($sw ? [sid()] : [])) as $r)
        $byAdm[mb_strtolower(attTrim($r['admission_no']))] = $r;

    $rows = []; $dup = []; $miss = []; $skipParse = 0; $matched = 0;
    foreach ($body as $i => $row) {
        $at  = 'Row ' . ($i + 1);
        $adm = attTrim($row[$col['adm']] ?? '');
        if ($adm === '') { $issues[] = $at . ': no admission no — row skipped.'; $skipParse++; continue; }
        $k = mb_strtolower($adm);
        if (isset($dup[$k])) { $issues[] = $at . ': ' . $adm . ' is already in this file — only the first row was used.'; $skipParse++; continue; }
        $dup[$k] = 1;
        $st = $byAdm[$k] ?? null;
        if (!$st)                       { $miss[$k] = [$at, $adm]; $skipParse++; continue; }
        if ($st['status'] !== 'Active') { $issues[] = $at . ': ' . $adm . ' is ' . $st['status'] . ' — only active students can be recorded.'; $skipParse++; continue; }
        $matched++;

        $rows[] = ['student_id'   => (int)$st['id'], 'at' => $at,
                   'days_present' => $row[$col['p']] ?? '',
                   'days_total'   => $row[$col['t']] ?? '',
                   'remarks'      => isset($col['r']) ? ($row[$col['r']] ?? '') : ($ctx['exist'][(int)$st['id']]['r'] ?? '')];
    }

    // one lookup tells "not in this section" apart from "no such admission no".
    // stays inside the school — otherwise the message doubles as a cross-tenant existence oracle
    if ($miss) {
        $vals  = array_values(array_map(function ($m) { return $m[1]; }, $miss));
        $found = [];
        foreach (qAll("SELECT admission_no FROM students WHERE admission_no IN (" . implode(',', array_fill(0, count($vals), '?')) . ")$sw",
                      str_repeat('s', count($vals)) . ($sw ? 'i' : ''),
                      ...array_merge($vals, $sw ? [sid()] : [])) as $r)
            $found[mb_strtolower($r['admission_no'])] = 1;
        foreach ($miss as $k => $m)
            $issues[] = $m[0] . ': ' . (isset($found[$k]) ? $m[1] . ' is not in this section.' : 'admission no ' . $m[1] . ' was not found.');
    }

    $res     = attPersist($ctx, $rows, !$commit);          // dry on preview, same decisions either way
    $issues  = array_merge($issues, $res['errors']);
    $skipped = $skipParse + $res['skipped'];
    $changes = $res['added'] + $res['updated'] + $res['cleared'];
    $shown   = array_slice($issues, 0, 200);
    $more    = max(0, count($issues) - 200);

    if (!$commit) {
        jsonOk(['added' => $res['added'], 'updated' => $res['updated'], 'cleared' => $res['cleared'],
                'skipped' => $skipped, 'students' => $matched, 'changes' => $changes,
                'issues' => $shown, 'more' => $more,
                'message' => $changes . ' change' . ($changes === 1 ? '' : 's') . ' ready, ' . $skipped . ' skipped']);
    }

    // ONE log for the whole import, never one per student
    logActivity($userId, $username, 'Attendance Imported',
        'Attendance sheet import: ' . attLabel($sec) . ', ' . $term['name'] . ' — ' . $res['added'] . ' added, '
        . $res['updated'] . ' updated, ' . $res['cleared'] . ' cleared, ' . $skipped . ' skipped',
        'attendance', (int)$sec['id']);

    jsonOk(['imported' => $res['saved'], 'added' => $res['added'], 'updated' => $res['updated'],
            'cleared' => $res['cleared'], 'skipped' => $skipped, 'errors' => $shown, 'more' => $more,
            'message' => $res['saved'] . ' record' . ($res['saved'] === 1 ? '' : 's') . ' imported'
                       . ($skipped ? ', ' . $skipped . ' skipped' : '')]);
}

// resolve term + section for every scoped call, or die trying
function attResolve(string $role, int $userId) {
    $termId    = attOwns('exam_terms', attIn('term_id', 0));
    $sectionId = attOwns('sections',   attIn('section_id', 0));
    $term      = $termId ? ormsTerm($termId) : null;
    if (!$term)      jsonErr('Please choose an exam term.');
    $sec = $sectionId ? attSection($sectionId) : null;
    if (!$sec)       jsonErr('Please choose a section.');
    if (!in_array($sectionId, attSections((int)$term['academic_year_id']), true))
        jsonErr('You are not assigned to that section.');
    return [$term, $sec];
}

// Handle AJAX requests
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        if (!attReady()) jsonErr('Attendance is not set up yet — run update_setup.php once to add the attendance table.');

        switch ($action) {

            // ---- year -> terms + classes + sections, all already scoped. one call feeds three dropdowns ----
            case 'getScope':
                $yearId = attOwns('academic_years', attIn('year_id', 0));
                if (!$yearId) jsonErr('Please choose an academic year.');
                $lists = attScopeLists($yearId);
                jsonOk(['terms' => array_map(function ($t) {
                            return ['id' => (int)$t['id'], 'name' => $t['name'], 'status' => $t['status']];
                        }, ormsTerms($yearId)),
                        'classes'  => $lists['classes'],
                        'sections' => $lists['sections']]);

            // ---- the grid: roster + what is already recorded, one read each ----
            case 'getGrid':
                [$term, $sec] = attResolve($role, $user_id);
                $roster = attRoster((int)$sec['id']);
                $exist  = attExisting((int)$term['id'], array_keys($roster));

                $rows = [];
                foreach ($roster as $stu => $st) {
                    $a = $exist[$stu] ?? null;
                    $rows[] = ['id' => $stu, 'roll' => $st['roll'], 'adm' => $st['adm'], 'name' => $st['name'],
                               'p' => $a ? attNum($a['p']) : '', 't' => $a ? attNum($a['t']) : '', 'r' => $a['r'] ?? ''];
                }

                jsonOk(['rows'      => $rows,
                        'term'      => ['id' => (int)$term['id'], 'name' => $term['name'], 'status' => $term['status']],
                        'section'   => ['id' => (int)$sec['id'], 'label' => attLabel($sec)],
                        'published' => ormsIsPublished((int)$term['id'], (int)$sec['id']) ? 1 : 0,
                        'bulk'      => attNum(attDefaultDays($exist)),
                        'can_save'  => $canSave ? 1 : 0]);

            // ---- the whole grid saves in one action ----
            case 'saveAttendance':
                requireCsrfJson();                                  // csrf first, then rbac
                requirePermJson('attendance', 'v');
                if (!$canSave) jsonErr('You are not allowed to record attendance.');

                [$term, $sec] = attResolve($role, $user_id);
                $posted = json_decode((string)attIn('rows', '[]'), true);
                if (!is_array($posted)) jsonErr('Nothing to save — the grid sent no rows.');
                if (count($posted) > 3000) jsonErr('Too many rows in one save.');

                $ctx = attCtx($term, $sec, $user_id, $canAdd, $canEdit);
                $res = attPersist($ctx, array_map(function ($r) {
                    return ['student_id'   => (int)($r['id'] ?? 0),
                            'days_present' => $r['p'] ?? '',
                            'days_total'   => $r['t'] ?? '',
                            'remarks'      => $r['r'] ?? ''];
                }, $posted));

                if ($res['saved'] > 0) {
                    logActivity($user_id, $username, 'Attendance Saved',
                        'Attendance: ' . attLabel($sec) . ', ' . $term['name'] . ' — ' . $res['added'] . ' added, '
                        . $res['updated'] . ' updated, ' . $res['cleared'] . ' cleared'
                        . ($res['skipped'] ? ', ' . $res['skipped'] . ' skipped' : ''),
                        'attendance', (int)$sec['id']);
                }

                jsonOk(['saved' => $res['saved'], 'added' => $res['added'], 'updated' => $res['updated'],
                        'cleared' => $res['cleared'], 'skipped' => $res['skipped'],
                        'errors' => array_slice($res['errors'], 0, 200),
                        'message' => $res['saved'] === 0
                                   ? ($res['skipped'] ? 'Nothing saved — ' . $res['skipped'] . ' row(s) need fixing' : 'Nothing changed')
                                   : $res['saved'] . ' record' . ($res['saved'] === 1 ? '' : 's') . ' saved'
                                     . ($res['skipped'] ? ', ' . $res['skipped'] . ' skipped' : '')]);

            // ---- offline round trip: csv out (blank template or filled export), csv back in ----
            case 'getSheet':
                requireCsrfJson();
                requirePermJson('attendance', 'v');

                [$term, $sec] = attResolve($role, $user_id);
                $blank  = (int)attIn('blank', 0) === 1;
                $roster = attRoster((int)$sec['id']);
                if (!$roster) jsonErr('That section has no active students.');
                $exist = attExisting((int)$term['id'], array_keys($roster));

                $slug = function ($s) { return trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$s), '-'); };
                $file = ($blank ? 'Attendance_Template_' : 'Attendance_')
                      . $slug($sec['class_name'] . '-' . $sec['section_name']) . '_' . $slug($term['name']) . '.csv';

                header('Content-Type: text/csv; charset=utf-8');     // replaces the json header set above
                header('Content-Disposition: attachment; filename="' . $file . '"');
                header('Cache-Control: no-store');
                echo "\xEF\xBB\xBF" . attSheet($sec, $term, $roster, $exist, $blank, $blank ? attDefaultDays($exist) : 0.0);
                exit();

            case 'importAttendancePreview':
                requireCsrfJson();
                requirePermJson('attendance', 'v');
                if (!$canSave) jsonErr('You are not allowed to record attendance.');
                [$term, $sec] = attResolve($role, $user_id);
                attImportRun(false, $term, $sec, $user_id, $username, $canAdd, $canEdit);

            case 'importAttendance':
                requireCsrfJson();
                requirePermJson('attendance', 'v');
                if (!$canSave) jsonErr('You are not allowed to record attendance.');
                [$term, $sec] = attResolve($role, $user_id);
                attImportRun(true, $term, $sec, $user_id, $username, $canAdd, $canEdit);

            // ---- daily register: one row per student per DATE, separate from the term totals ----
            case 'getDaily': {
                requireCsrfJson();
                requirePermJson('attendance', 'v');
                $secId = attOwns('sections', attIn('section_id', 0));
                $yid   = attOwns('academic_years', attIn('year_id', 0));
                $date  = (string)attIn('att_date', '');
                $sec   = $secId ? attSection($secId) : null;
                if (!$sec)                                        jsonErr('Please choose a section.');
                if (!$yid)                                        jsonErr('Please choose an academic year.');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))  jsonErr('Please pick a date.');
                if ($date > date('Y-m-d'))                        jsonErr('The register cannot be marked for a future date.');
                if (!in_array($secId, attSections($yid), true))   jsonErr('You are not assigned to that section.');

                $roster = attRoster($secId);
                $mark = [];
                if ($roster) {
                    $ids = array_keys($roster);
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    foreach (qAll("SELECT student_id, status, remarks FROM attendance_daily
                                   WHERE att_date = ? AND student_id IN ($ph)",
                                  's' . str_repeat('i', count($ids)), $date, ...$ids) as $r) {
                        $mark[(int)$r['student_id']] = ['st' => $r['status'], 'r' => (string)($r['remarks'] ?? '')];
                    }
                }
                $rows = [];
                foreach ($roster as $stu => $st) {
                    $m = $mark[$stu] ?? null;
                    $rows[] = ['id' => $stu, 'roll' => $st['roll'], 'name' => $st['name'],
                               'st' => $m['st'] ?? '', 'r' => $m['r'] ?? ''];
                }
                jsonOk(['rows' => $rows, 'label' => attLabel($sec), 'date' => $date, 'can_save' => $canSave ? 1 : 0]);
            }

            case 'saveDaily': {
                requireCsrfJson();
                requirePermJson('attendance', 'v');
                if (!$canSave) jsonErr('You are not allowed to record attendance.');
                $secId = attOwns('sections', attIn('section_id', 0));
                $yid   = attOwns('academic_years', attIn('year_id', 0));
                $date  = (string)attIn('att_date', '');
                $sec   = $secId ? attSection($secId) : null;
                if (!$sec || !$yid)                               jsonErr('Please choose a section.');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))  jsonErr('Please pick a date.');
                if ($date > date('Y-m-d'))                        jsonErr('The register cannot be marked for a future date.');
                if (!in_array($secId, attSections($yid), true))   jsonErr('You are not assigned to that section.');

                $rows = json_decode((string)attIn('rows', '[]'), true);
                if (!is_array($rows) || !$rows) jsonErr('Nothing to save');
                $roster = attRoster($secId);                      // ids outside this roster never reach the table

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    $dSid = 0; $dSt = 'P'; $dRem = null;
                    $st = $conn->prepare("INSERT INTO attendance_daily (student_id, section_id, academic_year_id, att_date, status, remarks, marked_by)
                                          VALUES (?, ?, ?, ?, ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE section_id = VALUES(section_id), status = VALUES(status),
                                                                  remarks = VALUES(remarks), marked_by = VALUES(marked_by)");
                    $st->bind_param("iiisssi", $dSid, $secId, $yid, $date, $dSt, $dRem, $user_id);
                    $saved = 0;
                    foreach ($rows as $r) {
                        $dSid = (int)($r['id'] ?? 0);
                        $dSt  = (string)($r['st'] ?? '');
                        if (!isset($roster[$dSid]) || !in_array($dSt, ['P', 'A', 'L', 'LV'], true)) continue;
                        $rem  = trim((string)($r['r'] ?? ''));
                        $dRem = $rem === '' ? null : mb_substr($rem, 0, 120);
                        $st->execute();
                        $saved++;
                    }
                    $st->close();
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('attendance.php saveDaily: ' . $e->getMessage());
                    jsonErr('The register could not be saved — nothing was changed.');
                }
                logActivity($user_id, $username, 'Attendance Marked', "Daily register: " . attLabel($sec) . " on $date ($saved student(s))");
                jsonOk(['message' => "Register saved — $saved student(s) marked", 'saved' => $saved]);
            }

            case 'getAbsentees': {
                requireCsrfJson();
                requirePermJson('attendance', 'v');
                $yid  = attOwns('academic_years', attIn('year_id', 0));
                $date = (string)attIn('att_date', '');
                if (!$yid)                                       jsonErr('Please choose an academic year.');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonErr('Please pick a date.');
                $scope = attSections($yid);
                if (!$scope) jsonOk(['rows' => []]);

                $ph = implode(',', array_fill(0, count($scope), '?'));
                $rows = qAll(
                    "SELECT ad.student_id AS id, ad.status, ad.remarks, st.roll_no, st.admission_no,
                            st.guardian_phone, st.guardian_email, u.full_name,
                            c.name AS class_name, sec.name AS section_name
                     FROM attendance_daily ad
                     JOIN students st  ON st.id = ad.student_id
                     JOIN users u      ON u.id = st.user_id
                     JOIN classes c    ON c.id = st.class_id
                     JOIN sections sec ON sec.id = ad.section_id
                     WHERE ad.att_date = ? AND ad.status IN ('A', 'L') AND ad.section_id IN ($ph)
                     ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, CAST(st.roll_no AS UNSIGNED) ASC",
                    's' . str_repeat('i', count($scope)), $date, ...$scope);
                jsonOk(['rows' => $rows]);
            }

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('attendance.php error: ' . $e->getMessage());
        jsonErr('Could not complete that request');     // real reason stays in the log, never on the wire
    }
}

$ready   = attReady();
$years   = attYears();
$curYear = attCurYear();
$yearId  = $curYear ? (int)$curYear['id'] : ($years ? (int)$years[0]['id'] : 0);
$terms   = ($ready && $yearId) ? ormsTerms($yearId) : [];
$lists   = ($ready && $yearId) ? attScopeLists($yearId) : ['classes' => [], 'sections' => []];

$openTermId = 0;
foreach ($terms as $t) { if ($t['status'] === 'Open') { $openTermId = (int)$t['id']; break; } }
if (!$openTermId && $terms) $openTermId = (int)$terms[0]['id'];
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
    <title>Attendance - Online Result Management</title>

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
                    <h1><i class="fas fa-user-check"></i> Attendance</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Attendance</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if (!$ready): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>Attendance is not set up yet</h4>
                    <p>Run <b>update_setup.php</b> once to add the attendance table, then come back to this page.</p>
                </div>
            </div>
            <?php elseif (!$years || !$terms): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-calendar-xmark"></i>
                    <h4>No academic year or exam term found</h4>
                    <p>Set a current academic year and at least one exam term in Result Settings before recording attendance.</p>
                </div>
            </div>
            <?php else: ?>

            <div class="tab-nav no-print">
                <button type="button" class="tab-btn active" data-tab="tabDaily"><i class="fas fa-calendar-day"></i> Daily Register</button>
                <button type="button" class="tab-btn" data-tab="tabTerm"><i class="fas fa-file-pen"></i> Term Summary</button>
            </div>

            <!-- ============================ Daily Register ============================ -->
            <div class="tab-pane active" id="tabDaily">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-day"></i> Daily Register</h2>
                        <div class="btn-group-inline no-print">
                            <button type="button" class="btn btn-secondary" id="dailyRefreshBtn" onclick="loadDaily()"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if ($canSave): ?>
                            <button type="button" class="btn btn-success" id="dailyAllBtn" onclick="dailyAll('P')"><i class="fas fa-user-check"></i> All Present</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-banner info-banner-top mb-24 no-print">
                        <i class="fas fa-circle-info"></i>
                        <span>Mark <b>who came today</b>, one row per student: Present, Absent, Late or on Leave. The absentee list below collects
                              every Absent/Late student of the day across your sections, with a one-tap <b>WhatsApp</b> message to the guardian.
                              Term totals for report cards stay on the <b>Term Summary</b> tab.</span>
                    </div>

                    <div class="filters-section no-print">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-day"></i> Date</label>
                                <input type="date" id="dailyDate" class="filter-input" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="dailyClass" class="filter-input">
                                    <option value="">Select Class</option>
                                    <?php foreach ($lists['classes'] as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-layer-group"></i> Section</label>
                                <select id="dailySection" class="filter-input">
                                    <option value="">Select Section</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- one tab per section of the chosen class; the register is per section, so this
                         is the switch a teacher uses every morning -->
                    <div class="tab-nav no-print initially-hidden" id="dailySecTabs" role="tablist"></div>

                    <div id="dailySkeleton" class="initially-hidden">
                        <div class="skeleton-table">
                            <?php for ($i = 0; $i < 8; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="dailyEmpty" class="orms-empty">
                        <i class="fas fa-calendar-day"></i>
                        <h4>No section open</h4>
                        <p>Pick a class and section above to mark today&rsquo;s register<?php echo $isWide ? '' : ', or ask the admin to assign you a section'; ?>.</p>
                    </div>

                    <div id="dailyWrap" class="initially-hidden">
                        <div class="stat-mini" id="dailyStats">
                            <div><i class="fas fa-user-check"></i> Present <b id="dsP">0</b></div>
                            <div><i class="fas fa-user-slash"></i> Absent <b id="dsA">0</b></div>
                            <div><i class="fas fa-clock"></i> Late <b id="dsL">0</b></div>
                            <div><i class="fas fa-envelope-open-text"></i> Leave <b id="dsLV">0</b></div>
                            <div><i class="fas fa-circle-question"></i> Unmarked <b id="dsU">0</b></div>
                        </div>

                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="table-responsive">
                            <table class="att-grid table-full-width" id="dailyGrid">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-hashtag"></i> Roll</th>
                                        <th><i class="fas fa-user"></i> Student</th>
                                        <th><i class="fas fa-clipboard-check"></i> Status</th>
                                        <th><i class="fas fa-comment-dots"></i> Remarks</th>
                                    </tr>
                                </thead>
                                <tbody id="dailyBody"></tbody>
                            </table>
                        </div>

                        <?php if ($canSave): ?>
                        <div class="btn-group-inline no-print">
                            <button type="button" class="btn btn-success" id="dailySaveBtn" onclick="saveDaily(this)"><i class="fas fa-save"></i> Save Register</button>
                            <button type="button" class="btn btn-secondary" onclick="loadDaily()"><i class="fas fa-rotate-left"></i> Discard Changes</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="dailyNone" class="orms-empty initially-hidden">
                        <i class="fas fa-user-slash"></i>
                        <h4>No active students in this section</h4>
                        <p>Admit or activate students in this section before marking the register.</p>
                    </div>
                </div>

                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-bell"></i> Absent &amp; Late — <span id="absDateLabel"></span></h2>
                    </div>
                    <div id="absEmpty" class="orms-empty">
                        <i class="fas fa-champagne-glasses"></i>
                        <h4>Nobody marked absent yet</h4>
                        <p>Absent and Late students of the selected date appear here as registers are saved, with a WhatsApp message ready for each guardian.</p>
                    </div>
                    <div id="absWrap" class="about-table-wrapper initially-hidden">
                        <table class="about-roles-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user"></i> Student</th>
                                    <th><i class="fas fa-school"></i> Class</th>
                                    <th><i class="fas fa-clipboard-check"></i> Status</th>
                                    <th><i class="fas fa-comment-dots"></i> Remarks</th>
                                    <th><i class="fas fa-phone"></i> Guardian</th>
                                    <th><i class="fas fa-paper-plane"></i> Notify</th>
                                </tr>
                            </thead>
                            <tbody id="absBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ============================ Term Summary ============================ -->
            <div class="tab-pane" id="tabTerm">
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-user-check"></i> Term Attendance</h2>
                    <div class="btn-group-inline no-print">
                        <button type="button" class="btn btn-secondary" id="refreshBtn" onclick="loadGrid()"><i class="fas fa-sync"></i> Refresh</button>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24 no-print">
                    <i class="fas fa-circle-info"></i>
                    <span>Attendance is recorded <b>per term</b>, not day by day: how many days each student came, out of the number of times the school was in session. It prints on the report card. Leaving <b>Days Present</b> blank clears that student&rsquo;s record for the term.</span>
                </div>

                <div class="filters-section no-print">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                            <select id="filterYear" class="filter-input">
                                <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$y['id'] === $yearId ? ' selected' : ''; ?>><?php echo htmlspecialchars($y['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-file-pen"></i> Exam Term</label>
                            <select id="filterTerm" class="filter-input">
                                <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"<?php echo (int)$t['id'] === $openTermId ? ' selected' : ''; ?>><?php echo htmlspecialchars($t['name'] . ' (' . $t['status'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-school"></i> Class</label>
                            <select id="filterClass" class="filter-input">
                                <option value="">Select Class</option>
                                <?php foreach ($lists['classes'] as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Section</label>
                            <select id="filterSection" class="filter-input">
                                <option value="">Select Section</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="info-banner info-banner-warning mb-24 initially-hidden no-print" id="pubBanner">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>This term is already <b>published</b> for this section. Attendance was frozen onto the cards at publish time &mdash; editing it here will <b>not</b> change an already-published card until the section is unpublished and published again.</span>
                </div>

                <div id="gridSkeleton" class="initially-hidden">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="gridEmpty" class="orms-empty">
                    <i class="fas fa-user-check"></i>
                    <h4>No section open</h4>
                    <p>Pick a class and section above to record how many days each student came to school this term<?php echo $isWide ? '' : ', or ask the admin to assign you a section'; ?>.</p>
                </div>

                <div id="gridWrap" class="initially-hidden">
                    <div class="stat-mini" id="attStats">
                        <div><i class="fas fa-users"></i> Students <b id="statStudents">0</b></div>
                        <div><i class="fas fa-percent"></i> Average <b id="statAvg">&ndash;</b></div>
                        <div><i class="fas fa-arrow-trend-up"></i> Best <b id="statBest">&ndash;</b></div>
                        <div><i class="fas fa-arrow-trend-down"></i> Lowest <b id="statWorst">&ndash;</b></div>
                        <div><i class="fas fa-circle-question"></i> Unrecorded <b id="statMissing">0</b></div>
                    </div>

                    <div class="att-bulk no-print">
                        <label for="bulkDays"><i class="fas fa-calendar-day"></i> Days the school was in session this term</label>
                        <input type="number" id="bulkDays" class="filter-input att-in" min="0" step="0.5" inputmode="decimal">
                        <button type="button" class="btn btn-secondary" id="bulkBtn" onclick="applyBulk()"><i class="fas fa-wand-magic-sparkles"></i> Apply to All</button>
                        <span class="myr-sub"><i class="fas fa-circle-info"></i> A student who joined mid-term can still be given their own number.</span>
                    </div>

                    <div class="btn-group-inline mb-24 no-print" id="attTools">
                        <button type="button" class="btn btn-secondary btn-sm" id="tplBtn" onclick="sheetDownload(this, 1)"><i class="fas fa-download"></i> Template</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="expBtn" onclick="sheetDownload(this, 0)"><i class="fas fa-file-csv"></i> Export CSV</button>
                        <?php if ($canSave): ?>
                        <button type="button" class="btn btn-secondary btn-sm" id="impBtn" onclick="sheetPick()"><i class="fas fa-file-import"></i> Import CSV</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="ORMS.printOnly('#attPrint')"><i class="fas fa-print"></i> Print</button>
                    </div>

                    <div id="attPrint">
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="table-responsive">
                            <table class="att-grid table-full-width" id="attGrid">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-hashtag"></i> Roll</th>
                                        <th><i class="fas fa-user"></i> Student</th>
                                        <th><i class="fas fa-user-check"></i> Days Present</th>
                                        <th><i class="fas fa-calendar-day"></i> Days in Session</th>
                                        <th><i class="fas fa-percent"></i> Attendance</th>
                                        <th><i class="fas fa-comment-dots"></i> Remarks</th>
                                    </tr>
                                </thead>
                                <tbody id="attBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($canSave): ?>
                    <div class="btn-group-inline no-print">
                        <button type="button" class="btn btn-success" id="saveBtn" onclick="saveGrid(this)"><i class="fas fa-save"></i> Save Attendance</button>
                        <button type="button" class="btn btn-secondary" onclick="loadGrid()"><i class="fas fa-rotate-left"></i> Discard Changes</button>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="gridNone" class="orms-empty initially-hidden">
                    <i class="fas fa-user-slash"></i>
                    <h4>No active students in this section</h4>
                    <p>Admit or activate students in this section before recording attendance.</p>
                </div>
            </div>
            </div><!-- /tabTerm -->

            <!-- sheet download: posts into a hidden frame so the attachment never unloads this page -->
            <form id="sheetForm" method="post" action="attendance.php?action=getSheet" target="sheetFrame" class="initially-hidden">
                <input type="hidden" name="action" value="getSheet">
                <input type="hidden" name="csrf_token">
                <input type="hidden" name="term_id">
                <input type="hidden" name="section_id">
                <input type="hidden" name="blank">
            </form>
            <iframe id="sheetFrame" name="sheetFrame" title="Attendance sheet download" class="initially-hidden"></iframe>
            <input type="file" id="attCsvInput" accept=".csv,text/csv" class="initially-hidden">

            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?php echo csrfToken(); ?>';</script>

    <script>
    var CAN_SAVE = <?php echo $canSave ? 'true' : 'false'; ?>;
    var SECTIONS = <?php echo json_encode($lists['sections'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var GRID = null;                                  // {term, section, published, rows}

    function el(id) { return document.getElementById(id); }
    function esc(s) { return ORMS.esc(s); }
    function num(v) { var s = String(v == null ? '' : v).replace(/[\s ]/g, ''); return /^\d+(\.\d)?$/.test(s) ? parseFloat(s) : null; }
    function pct(p, t) { return (p === null || t === null || t <= 0 || p > t) ? null : Math.round(p / t * 1000) / 10; }
    function fmt(n) { return (Math.round(n * 10) / 10).toFixed(1).replace(/\.0$/, ''); }

    function scope() {
        return { term_id: parseInt($('#filterTerm').val() || 0, 10), section_id: parseInt($('#filterSection').val() || 0, 10) };
    }

    // ---------- filters ----------

    // class -> section chain runs off the embedded list, so switching class needs no round trip
    function fillSections() {
        var cid = parseInt($('#filterClass').val() || 0, 10), keep = $('#filterSection').val();
        var h = '<option value="">Select Section</option>';
        SECTIONS.forEach(function (s) { if (!cid || s.class_id === cid) h += '<option value="' + s.id + '">' + esc(s.name) + '</option>'; });
        $('#filterSection').html(h);
        if (keep) $('#filterSection').val(keep);        // gone from the new list -> browser falls back to ""
        ORMS.dropdown.refresh('#filterSection');
    }

    function loadScope() {
        var y = parseInt($('#filterYear').val() || 0, 10);
        if (!y) return;
        ORMS.post('getScope', { year_id: y }).done(function (res) {
            if (!res || !res.success) { ORMS.err((res && res.message) || 'Could not load that year.'); return; }
            SECTIONS = res.sections || [];
            var open = 0;
            var th = (res.terms || []).map(function (t) {
                if (!open && t.status === 'Open') open = t.id;
                return '<option value="' + t.id + '">' + esc(t.name + ' (' + t.status + ')') + '</option>';
            }).join('');
            $('#filterTerm').html(th).val(open || ((res.terms || [])[0] || {}).id || '');
            ORMS.dropdown.refresh('#filterTerm');

            $('#filterClass').html('<option value="">Select Class</option>' + (res.classes || []).map(function (c) {
                return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
            }).join(''));
            ORMS.dropdown.refresh('#filterClass');
            fillSections();
            closeGrid();
        }).fail(function (m) { ORMS.err(m || 'Connection error'); });
    }

    // ---------- grid ----------

    function closeGrid() {
        GRID = null;
        $('#gridWrap, #gridNone, #gridSkeleton, #pubBanner').addClass('initially-hidden');
        $('#gridEmpty').removeClass('initially-hidden');
    }

    function loadGrid() {
        var s = scope();
        if (!s.term_id || !s.section_id) { closeGrid(); return; }
        $('#gridEmpty, #gridWrap, #gridNone').addClass('initially-hidden');
        $('#gridSkeleton').removeClass('initially-hidden');

        ORMS.post('getGrid', s).done(function (res) {
            $('#gridSkeleton').addClass('initially-hidden');
            if (!res || !res.success) { closeGrid(); ORMS.err((res && res.message) || 'Could not load that section.'); return; }
            GRID = res;
            $('#pubBanner').toggleClass('initially-hidden', !res.published);
            if (!res.rows.length) { $('#gridNone').removeClass('initially-hidden'); return; }
            $('#bulkDays').val(res.bulk && parseFloat(res.bulk) > 0 ? res.bulk : '');
            renderRows(res.rows);
            $('#gridWrap').removeClass('initially-hidden');
        }).fail(function (m) { $('#gridSkeleton').addClass('initially-hidden'); closeGrid(); ORMS.err(m || 'Connection error'); });
    }

    function renderRows(rows) {
        var ro = CAN_SAVE ? '' : ' readonly';
        $('#attBody').html(rows.map(function (r) {
            return '<tr class="att-row" data-id="' + r.id + '">' +
                   '<td>' + esc(r.roll || '—') + '</td>' +
                   '<td>' + esc(r.name) + '<br><small>' + esc(r.adm) + '</small></td>' +
                   '<td><input type="number" class="att-in" data-f="p" min="0" step="0.5" inputmode="decimal" value="' + esc(r.p) + '"' + ro + '></td>' +
                   '<td><input type="number" class="att-in" data-f="t" min="0" step="0.5" inputmode="decimal" value="' + esc(r.t) + '"' + ro + '></td>' +
                   '<td><span class="att-pct">—</span></td>' +
                   '<td><input type="text" class="att-in" data-f="r" maxlength="255" value="' + esc(r.r) + '"' + ro + '></td>' +
                   '</tr>';
        }).join(''));
        repaint();
    }

    // live % + summary as the teacher types — one pass over the rows, no per-cell work
    function repaint() {
        var n = 0, sum = 0, best = null, worst = null, missing = 0;
        $('#attBody tr.att-row').each(function () {
            var $r = $(this);
            var p = num($r.find('[data-f="p"]').val()), t = num($r.find('[data-f="t"]').val());
            var v = pct(p, t);
            var $c = $r.find('.att-pct');
            if (p === null) { $c.text('—'); missing++; return; }
            if (v === null) { $c.text('!'); missing++; return; }
            $c.text(fmt(v) + '%');
            n++; sum += v;
            if (best === null || v > best) best = v;
            if (worst === null || v < worst) worst = v;
        });
        el('statStudents').textContent = $('#attBody tr.att-row').length;
        el('statAvg').textContent    = n ? fmt(sum / n) + '%' : '–';
        el('statBest').textContent   = best === null ? '–' : fmt(best) + '%';
        el('statWorst').textContent  = worst === null ? '–' : fmt(worst) + '%';
        el('statMissing').textContent = missing;
    }

    // one box fills every row, per-student overrides stay possible afterwards
    function applyBulk() {
        if (!GRID || !CAN_SAVE) return;
        var v = num($('#bulkDays').val());
        if (v === null || v <= 0) { ORMS.err('Enter how many days the school was in session this term (0 or more, one decimal for half days).'); return; }
        $('#attBody tr.att-row [data-f="t"]').val(fmt(v));
        repaint();
        ORMS.ok('Days in session set to ' + fmt(v) + ' for every student');
    }

    function collect() {
        return $('#attBody tr.att-row').map(function () {
            var $r = $(this);
            return { id: parseInt($r.data('id'), 10),
                     p: String($r.find('[data-f="p"]').val() || '').trim(),
                     t: String($r.find('[data-f="t"]').val() || '').trim(),
                     r: String($r.find('[data-f="r"]').val() || '').trim() };
        }).get();
    }

    function issueTable(list, more) {
        if (!list || !list.length) return '';
        var h = '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
                '<th><i class="fas fa-list-ol"></i> #</th><th><i class="fas fa-circle-exclamation"></i> Problem</th>' +
                '</tr></thead><tbody>';
        list.forEach(function (m, i) { h += '<tr><td>' + (i + 1) + '</td><td>' + esc(m) + '</td></tr>'; });
        return h + '</tbody></table></div>' +
               (more ? '<p><i class="fas fa-ellipsis"></i> and ' + more + ' more — see the browser console.</p>' : '');
    }

    function counts(r) {
        return '<div class="stat-mini">' +
               (r.students === undefined ? '' : '<div><i class="fas fa-users"></i> Students <b>' + r.students + '</b></div>') +
               '<div><i class="fas fa-plus"></i> Add <b>' + r.added + '</b></div>' +
               '<div><i class="fas fa-pen"></i> Update <b>' + r.updated + '</b></div>' +
               '<div><i class="fas fa-eraser"></i> Clear <b>' + r.cleared + '</b></div>' +
               '<div><i class="fas fa-forward"></i> Skipped <b>' + r.skipped + '</b></div></div>';
    }

    function saveGrid(btn) {
        if (!GRID || !CAN_SAVE) return;
        var s = scope();
        ORMS.post('saveAttendance', { term_id: s.term_id, section_id: s.section_id, rows: JSON.stringify(collect()) },
                  { btn: btn, busyLabel: 'Saving…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Save failed'); return; }
                if (res.errors && res.errors.length) console.warn('Attendance skipped:', res.errors);
                if (!res.skipped) { loadGrid(); ORMS.ok(res.message); return; }   // clean save -> refetch is the truth
                // something bounced: leave what they typed on screen so it can be fixed, don't wipe it
                Swal.fire({ icon: 'warning', width: 640,
                            title: res.saved + ' saved, ' + res.skipped + ' skipped',
                            html: counts(res) + issueTable(res.errors, 0) });
            })
            .fail(function (m) { ORMS.err(m || 'Connection error'); });
    }

    // ---------- offline sheet ----------

    // a download is a read -> thin top bar, never the write overlay
    function sheetDownload(btn, blank) {
        if (!GRID) return;
        var f = el('sheetForm'), s = scope();
        f.csrf_token.value = window.ORMS_CSRF || '';
        f.term_id.value    = s.term_id;
        f.section_id.value = s.section_id;
        f.blank.value      = blank ? 1 : 0;
        ORMS.bar.start();
        ORMS.busy(btn, true, 'Preparing…');
        f.submit();
        // an attachment never fires the frame's load event, so a timer is the only honest release
        setTimeout(function () { ORMS.busy(btn, false); ORMS.bar.done(); }, 1200);
    }

    function sheetPick() { el('attCsvInput').click(); }

    // step 1 — nothing is written, the teacher sees exactly what would change
    function importPreview(text) {
        ORMS.post('importAttendancePreview', $.extend(scope(), { csv: text }), { btn: '#impBtn', busyLabel: 'Checking…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Could not read that attendance sheet.'); return; }
                if (res.issues && res.issues.length) console.warn('Attendance sheet problems:', res.issues);
                if (!res.changes) {
                    Swal.fire({ icon: 'info', title: 'Nothing to change', width: 640,
                                html: counts(res) + issueTable(res.issues, res.more) });
                    return;
                }
                Swal.fire({
                    icon: res.skipped ? 'warning' : 'question',
                    title: res.changes + ' change' + (res.changes === 1 ? '' : 's') + ' ready',
                    html: counts(res) +
                          '<p><i class="fas fa-triangle-exclamation"></i> Anything typed into the grid and not saved will be discarded.</p>' +
                          issueTable(res.issues, res.more),
                    width: 640, showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-file-import"></i> Import',
                    cancelButtonText: '<i class="fas fa-times"></i> Cancel'
                }).then(function (x) { if (x.isConfirmed) importCommit(text); });
            })
            .fail(function (m) { ORMS.err(m || 'Connection error'); });
    }

    // step 2 — the server re-validates everything, the preview result is never trusted back
    function importCommit(text) {
        ORMS.post('importAttendance', $.extend(scope(), { csv: text }), { btn: '#impBtn', busyLabel: 'Importing…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Import failed'); return; }
                if (res.errors && res.errors.length) console.warn('Attendance import skipped:', res.errors);
                loadGrid();
                Swal.fire({ icon: res.skipped ? 'warning' : 'success', width: 640,
                            title: res.imported + ' imported, ' + res.skipped + ' skipped',
                            html: counts(res) + issueTable(res.errors, res.more) });
            })
            .fail(function (m) { ORMS.err(m || 'Connection error'); });
    }

    // ---------- wiring ----------

    $(document).ready(function () {
        ORMS.dropdown('#filterYear');
        ORMS.dropdown('#filterTerm');
        ORMS.dropdown('#filterClass');
        ORMS.dropdown('#filterSection');
        fillSections();

        $('#filterYear').on('change', loadScope);
        $('#filterTerm').on('change', loadGrid);
        $('#filterClass').on('change', function () { fillSections(); closeGrid(); });
        $('#filterSection').on('change', loadGrid);

        $('#attBody').on('input change', '.att-in', repaint);
        $('#bulkDays').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyBulk(); } });

        // the frame stays empty on a real download — only a json error ever renders in there
        $('#sheetFrame').on('load', function () {
            var t = '', r = {};
            try { t = String((this.contentDocument && this.contentDocument.body && this.contentDocument.body.textContent) || '').trim(); } catch (e) {}
            if (t.charAt(0) !== '{') return;
            try { r = JSON.parse(t); } catch (e) {}
            ORMS.err(r.message || 'Could not build the attendance sheet.');
        });

        var csvIn = el('attCsvInput');
        if (csvIn) csvIn.addEventListener('change', function () {
            var input = this, file = input.files && input.files[0];
            if (!file || !GRID) return;
            var reader = new FileReader();
            reader.onerror = function () { input.value = ''; ORMS.err('That file could not be read.'); };
            reader.onload = function (ev) {
                input.value = '';                                    // same file twice must fire again
                var text = String(ev.target.result || '');
                // quick local sanity check only — the server does the authoritative parse
                var data = ORMS.parseCSV(text).filter(function (r) {
                    var f0 = String((r && r[0]) || '').trim();
                    return f0.charAt(0) !== '#' && (r || []).join('').trim() !== '';
                });
                if (data.length < 2) { ORMS.err('That file has no data rows under the header row.'); return; }
                importPreview(text);
            };
            reader.readAsText(file);
        });
    });

    // ==================== daily register ====================
    var WA_CC = <?php echo json_encode((string)getSetting('whatsapp_country_code', '57')); ?>;
    var DAILY = [];            // [{id, roll, name, st, r}]
    var DAILY_CAN = false;

    var DST = { P: ['P', 'Present', 'fa-check'], A: ['A', 'Absent', 'fa-xmark'],
                L: ['L', 'Late', 'fa-clock'], LV: ['LV', 'Leave', 'fa-envelope-open-text'] };

    $(function () {
        ORMS.dropdown('#dailyClass');
        ORMS.dropdown('#dailySection');
        fillDailySections();

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

        $('#dailyClass').on('change', function () { fillDailySections(); closeDaily(); });
        $('#dailySection').on('change', function () { paintDailySecTabs(); loadDaily(); });
        // tabs and the Section dropdown are the same choice — whichever is used, both stay in step
        ORMS.sectionTabs.bind('#dailySecTabs', '#dailySection', function () { loadDaily(); });
        $('#dailyDate').on('change', function () { loadDaily(); });

        $('#dailyBody').on('click', '.att-seg button', function () {
            if (!DAILY_CAN) return;
            var $b = $(this), sid = +$b.closest('tr').attr('data-sid');
            $b.closest('.att-seg').find('button').removeClass('active');
            $b.addClass('active');
            var row = DAILY.filter(function (r) { return r.id === sid; })[0];
            if (row) row.st = $b.attr('data-st');
            dailyCounts();
        });
        $('#dailyBody').on('input', '.daily-rem', function () {
            var sid = +$(this).closest('tr').attr('data-sid');
            var row = DAILY.filter(function (r) { return r.id === sid; })[0];
            if (row) row.r = this.value;
        });

        loadAbsentees();       // today's list greets the page even before a section is picked
    });

    function fillDailySections() {
        var cid = parseInt($('#dailyClass').val() || 0, 10), keep = $('#dailySection').val();
        var h = '<option value="">Select Section</option>';
        SECTIONS.forEach(function (s) { if (!cid || s.class_id === cid) h += '<option value="' + s.id + '">' + esc(s.name) + '</option>'; });
        $('#dailySection').html(h);
        if (keep) $('#dailySection').val(keep);
        ORMS.dropdown.refresh('#dailySection');
        paintDailySecTabs();
    }

    // the register belongs to ONE section, so every section in reach gets a tab — including a lone
    // one, because opening it is otherwise a trip through the dropdown
    function paintDailySecTabs() {
        var cid = parseInt($('#dailyClass').val() || 0, 10);
        ORMS.sectionTabs('#dailySecTabs', '#dailySection',
            SECTIONS.filter(function (s) { return !cid || s.class_id === cid; })
                    .map(function (s) { return { id: s.id, name: s.name }; }),
            { min: 1 });
    }

    function closeDaily() {
        $('#dailyWrap, #dailyNone').addClass('initially-hidden');
        $('#dailyEmpty').removeClass('initially-hidden');
    }

    function loadDaily() {
        var sec = parseInt($('#dailySection').val() || 0, 10);
        var d   = $('#dailyDate').val();
        loadAbsentees();
        if (!sec || !d) { closeDaily(); return; }
        $('#dailyEmpty, #dailyWrap, #dailyNone').addClass('initially-hidden');
        $('#dailySkeleton').removeClass('initially-hidden');
        ORMS.post('getDaily', { section_id: sec, att_date: d, year_id: $('#filterYear').val() }).done(function (res) {
            $('#dailySkeleton').addClass('initially-hidden');
            if (!res.success) { closeDaily(); ORMS.err(res.message || 'Could not load the register'); return; }
            DAILY = res.rows || [];
            DAILY_CAN = !!res.can_save;
            if (!DAILY.length) { $('#dailyNone').removeClass('initially-hidden'); return; }
            $('#dailyWrap').removeClass('initially-hidden');
            renderDaily();
        }).fail(function (m) { $('#dailySkeleton').addClass('initially-hidden'); closeDaily(); ORMS.err(m); });
    }

    function segBtns(row) {
        var h = '<span class="att-seg" role="group" aria-label="Attendance status">';
        Object.keys(DST).forEach(function (k) {
            var m = DST[k];
            h += '<button type="button" data-st="' + k + '" class="' + (row.st === k ? 'active' : '') + '"' +
                 (DAILY_CAN ? '' : ' disabled') + ' title="' + m[1] + '"><i class="fas ' + m[2] + '"></i> ' + m[1] + '</button>';
        });
        return h + '</span>';
    }

    function renderDaily() {
        var h = '';
        DAILY.forEach(function (r) {
            h += '<tr data-sid="' + r.id + '"><td>' + esc(r.roll || '—') + '</td>' +
                 '<td><strong>' + esc(r.name) + '</strong></td>' +
                 '<td>' + segBtns(r) + '</td>' +
                 '<td><input type="text" class="att-in daily-rem" maxlength="120" value="' + esc(r.r || '') + '"' +
                 (DAILY_CAN ? '' : ' disabled') + ' placeholder="—"></td></tr>';
        });
        $('#dailyBody').html(h);
        dailyCounts();
    }

    function dailyCounts() {
        var n = { P: 0, A: 0, L: 0, LV: 0, U: 0 };
        DAILY.forEach(function (r) { r.st && n[r.st] !== undefined ? n[r.st]++ : n.U++; });
        $('#dsP').text(n.P); $('#dsA').text(n.A); $('#dsL').text(n.L); $('#dsLV').text(n.LV); $('#dsU').text(n.U);
    }

    function dailyAll(st) {
        if (!DAILY.length) { ORMS.err('Open a section first'); return; }
        DAILY.forEach(function (r) { r.st = st; });
        renderDaily();
    }

    function saveDaily(btn) {
        var sec = parseInt($('#dailySection').val() || 0, 10);
        var d   = $('#dailyDate').val();
        var rows = DAILY.filter(function (r) { return r.st; }).map(function (r) { return { id: r.id, st: r.st, r: r.r || '' }; });
        if (!rows.length) { ORMS.err('Mark at least one student first'); return; }
        ORMS.post('saveDaily', { section_id: sec, att_date: d, year_id: $('#filterYear').val(), rows: JSON.stringify(rows) },
                  { btn: btn, busyLabel: 'Saving…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message); return; }
            ORMS.ok(res.message);
            loadAbsentees();
        }).fail(function (m) { ORMS.err(m); });
    }

    // guardian phone -> wa.me digits. leading 0 swaps for the country code (system_settings: whatsapp_country_code)
    function waPhone(p) {
        var d = String(p || '').replace(/\D/g, '');
        if (!d) return '';
        var cc = String(WA_CC || '57').replace(/\D/g, '');
        if (d.charAt(0) === '0') d = cc + d.slice(1);
        else if (d.length === 10 && cc && !d.startsWith(cc)) d = cc + d;
        return d;
    }

    function loadAbsentees() {
        var d = $('#dailyDate').val();
        if (!d) return;
        $('#absDateLabel').text(d);
        ORMS.post('getAbsentees', { att_date: d, year_id: $('#filterYear').val() }).done(function (res) {
            if (!res.success) return;
            var rows = res.rows || [];
            $('#absEmpty').toggleClass('initially-hidden', !!rows.length);
            $('#absWrap').toggleClass('initially-hidden', !rows.length);
            var h = '';
            rows.forEach(function (r) {
                var cls = r.class_name + ' – ' + r.section_name;
                var badge = r.status === 'A'
                    ? '<span class="status-badge status-inactive"><i class="fas fa-user-slash"></i> Inasistencia</span>'
                    : '<span class="status-badge status-current"><i class="fas fa-clock"></i> Retardo</span>';
                var ph  = waPhone(r.guardian_phone);
                var estadoTxt = (r.status === 'A' ? 'INASISTENCIA' : 'LLEGADA TARDE / RETARDO');
                var msg = 'Estimado(a) acudiente, cordial saludo de la institución educativa.\n\n' +
                          'Le informamos que el estudiante ' + r.full_name + ' (' + cls +
                          (r.roll_no ? ', N° de lista ' + r.roll_no : '') + ') fue registrado(a) con novedad de ' +
                          estadoTxt + ' el día de hoy (' + d + ').\n\n' +
                          'Si requiere justificar o consultar esta novedad, por favor comuníquese con la institución.';
                h += '<tr><td><strong>' + esc(r.full_name) + '</strong><br><small class="text-muted">' + esc(r.admission_no) + '</small></td>' +
                     '<td>' + esc(cls) + (r.roll_no ? ' &middot; N° ' + esc(r.roll_no) : '') + '</td>' +
                     '<td>' + badge + '</td>' +
                     '<td>' + (r.remarks ? esc(r.remarks) : '<span class="text-muted">&mdash;</span>') + '</td>' +
                     '<td>' + (r.guardian_phone ? esc(r.guardian_phone) : '<span class="text-muted">&mdash;</span>') + '</td>' +
                     '<td>' + (ph
                        ? '<a class="btn btn-success btn-sm" target="_blank" rel="noopener" href="https://api.whatsapp.com/send?phone=' + ph +
                          '&text=' + encodeURIComponent(msg) + '"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>'
                        : '<span class="text-muted">Sin teléfono</span>') + '</td></tr>';
            });
            $('#absBody').html(h);
        });
    }
    </script>
</body>
</html>
