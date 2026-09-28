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
requirePerm('fees', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'fees';

$canAdd  = can('fees', 'a');
$canEdit = can('fees', 'e');
$canDel  = can('fees', 'd');
$isWide  = ormsSchoolWide($role);

// ---------------------------------------------------------------- helpers

// post first, query string second — the page and its ajax read the same filters
function feeStr(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $_GET[$k] ?? $d)); }
function feeInt(string $k): int { return (int)($_POST[$k] ?? $_GET[$k] ?? 0); }

// column probe, one per request — an install that hasn't taken the migration keeps the old behaviour
function feeHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

function feeTenant(): bool { return feeHasCol('classes', 'school_id'); }

// sections this viewer may touch — ONE shared definition (ormsSectionScope), cached per year.
// a db without school_id has nothing to join the fence on, so it falls back to the plain read
function feeSections(int $yearId): array {
    static $c = [];
    if (isset($c[$yearId])) return $c[$yearId];
    if (feeTenant())      return $c[$yearId] = ormsSectionScope(null, null, $yearId);
    if (ormsSchoolWide()) return $c[$yearId] = array_map('intval', array_column(qAll("SELECT id FROM sections"), 'id'));
    $tid = ormsTeacherId((int)($_SESSION['user_id'] ?? 0));
    return $c[$yearId] = $tid ? array_map('intval', array_column(qAll(
        "SELECT DISTINCT section_id FROM teacher_subjects WHERE teacher_id = ? AND academic_year_id = ?",
        'ii', $tid, $yearId), 'section_id')) : [];
}

// posted id -> verified row id, 0 when another school owns it
function feeOwns(string $table, $raw): int {
    $id = (int)$raw;
    if ($id <= 0) return 0;
    return feeTenant() ? ormsOwns($table, $id) : $id;
}

// single-student gate. the student row is RESOLVED FIRST for every role — a school-wide reach
// still stops at the school wall, so no role gets to skip the lookup. returns the verified id or 0
function feeStudent($raw, int $yearId): int {
    $id = feeOwns('students', $raw);
    if (!$id) return 0;
    $sec = (int)qVal("SELECT section_id FROM students WHERE id = ?", 'i', $id);
    return ($sec && in_array($sec, feeSections($yearId), true)) ? $id : 0;
}

// classes fence for the outermost FROM -> [sql, types, params]
function feeWhere(string $a = 'c'): array {
    if (!feeTenant()) return ['', '', []];
    $b = feeHasCol('classes', 'branch_id') ? bid() : 0;
    return $b ? [" AND $a.school_id = ? AND $a.branch_id = ?", 'ii', [sid(), $b]]
              : [" AND $a.school_id = ?", 'i', [sid()]];
}

// this school's years only — the shared lookup is school-blind. one id read, never a probe per row
function feeYears(): array {
    $all = ormsYears();
    if (!feeTenant() || !$all) return $all;
    try {
        $mine = array_flip(array_map('intval', array_column(qAll("SELECT id FROM academic_years WHERE school_id = ?", 'i', sid()), 'id')));
        return array_values(array_filter($all, fn($y) => isset($mine[(int)$y['id']])));
    } catch (Throwable $e) { return $all; }
}

function feeCurYear(): ?array {
    $c = ormsCurrentYear();
    return ($c && (!feeTenant() || ormsFindYear((int)$c['id']))) ? $c : null;
}

// yyyy-mm-dd or dd/mm/yyyy or dd-mm-yyyy normalized to yyyy-mm-dd
function feeNormDate(string $d): ?string {
    $d = trim($d);
    if ($d === '') return null;
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $d, $m)) {
        $y = (int)$m[1]; $mth = (int)$m[2]; $day = (int)$m[3];
    } elseif (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $d, $m)) {
        $day = (int)$m[1]; $mth = (int)$m[2]; $y = (int)$m[3];
    } else {
        return null;
    }
    return checkdate($mth, $day, $y) ? sprintf('%04d-%02d-%02d', $y, $mth, $day) : null;
}

function feeIsDate(string $d): bool {
    return feeNormDate($d) !== null;
}

// "1,250.50" or "1.250,50" -> float, null when it is not a positive money value that fits DECIMAL(10,2)
function feeAmount($raw): ?float {
    $s = trim((string)$raw);
    if ($s === '') return null;
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace([',', ' '], '', $s);
    }
    if (!is_numeric($s)) return null;
    $v = round((float)$s, 2);
    return ($v > 0 && $v <= 99999999.99) ? $v : null;
}

// Charge | Payment (accepts Cargo, Cobro, Pago, Abono), anything else is rejected
function feeType(string $raw): string {
    $t = mb_strtolower(trim($raw));
    $map = [
        'charge' => 'Charge', 'cargo' => 'Charge', 'cobro' => 'Charge',
        'payment' => 'Payment', 'pago' => 'Payment', 'abono' => 'Payment'
    ];
    return $map[$t] ?? '';
}

// term must belong to the year it is being filed against — and to this school
function feeTermOk(int $termId, int $yearId): bool {
    if (!$termId) return true;
    if (!feeOwns('exam_terms', $termId)) return false;
    $t = ormsTerm($termId);
    return $t && (int)$t['academic_year_id'] === $yearId;
}

// active students of a class/section for the year, scoped. drives bulk charge + its live count
function feeTargets(int $yearId, int $classId, int $secId, array $scope): array {
    if (($classId <= 0 && $secId <= 0) || !$scope) return [];
    $sql   = "SELECT id FROM students WHERE status = 'Active' AND academic_year_id = ?";
    $types = 'i'; $args = [$yearId];
    if (feeHasCol('students', 'school_id')) { $sql .= " AND school_id = ?"; $types .= 'i'; $args[] = sid(); }
    if ($classId) { $sql .= " AND class_id = ?";   $types .= 'i'; $args[] = $classId; }
    if ($secId)   { $sql .= " AND section_id = ?"; $types .= 'i'; $args[] = $secId; }
    $sql   .= " AND section_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
    $types .= str_repeat('i', count($scope));
    $args   = array_merge($args, $scope);
    return array_map('intval', array_column(qAll($sql, $types, ...$args), 'id'));
}

// the withholding rule in force — this page only reports it, Result Settings owns it
function feeRule(): array {
    return [
        'on'        => ormsWithholdEnabled() ? 1 : 0,
        'threshold' => ormsArrearsThreshold(),
        'threshold_f' => ormsMoney(ormsArrearsThreshold()),
        'message'   => (string)getSetting('withhold_message', 'This result has been withheld by the school. Please contact the school office.')
    ];
}

$curYear = feeCurYear();
$years   = feeYears();
$defYear = $curYear ? (int)$curYear['id'] : ($years ? (int)$years[0]['id'] : 0);

// ---------------------------------------------------------------- ajax

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        switch ($action) {

            // ---------------- reads ----------------
            case 'getFeeSections': {
                requireCsrfJson();
                requirePermJson('fees', 'v');
                $cid = feeOwns('classes', feeInt('class_id'));
                if (!$cid) jsonOk(['data' => []]);
                $scope = feeSections(feeOwns('academic_years', feeInt('year_id')) ?: $defYear);
                $rows  = qAll("SELECT id, name FROM sections WHERE class_id = ? AND is_active = 1 ORDER BY name ASC", 'i', $cid);
                jsonOk(['data' => array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $scope, true)))]);
            }

            case 'getFeeOverview': {
                requireCsrfJson();
                requirePermJson('fees', 'v');
                if (!ormsHasFees()) jsonErr('The fees tables are not installed yet — run update_setup.php once, then reload this page.');

                $yearId  = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $classId = feeOwns('classes',  feeInt('class_id'));
                $secId   = feeOwns('sections', feeInt('section_id'));
                $status  = mb_strtolower(feeStr('status'));
                $scope   = feeSections($yearId);

                $zero = ['charged' => 0, 'received' => 0, 'outstanding' => 0, 'owing' => 0, 'held' => 0,
                         'charged_f' => ormsMoney(0), 'received_f' => ormsMoney(0), 'outstanding_f' => ormsMoney(0)];
                if (!$yearId || !$scope) jsonOk(['rows' => [], 'kpi' => $zero, 'rule' => feeRule(), 'terms' => $yearId ? ormsTerms($yearId) : []]);

                // one grouped read for the whole roster — charged/paid come pre-summed, never a query per student.
                // the derived table gets its own fence AND the driver gets one: a scoped subquery alone still
                // lets the outer count span every school
                $fw = feeHasCol('student_fees', 'school_id') ? " AND school_id = ?" : "";
                $sw = feeHasCol('students', 'school_id')     ? " AND st.school_id = ?" : "";
                $sql = "SELECT st.id, st.admission_no, st.roll_no, st.fee_hold, st.fee_hold_note,
                               st.guardian_phone, st.guardian_email,
                               u.full_name, u.username, c.name AS class_name, sec.name AS section_name,
                               COALESCE(f.charged, 0) AS charged, COALESCE(f.paid, 0) AS paid
                        FROM students st
                        JOIN users u      ON u.id = st.user_id
                        JOIN classes c    ON c.id = st.class_id
                        JOIN sections sec ON sec.id = st.section_id
                        LEFT JOIN (SELECT student_id,
                                          SUM(CASE WHEN entry_type = 'Charge'  THEN amount ELSE 0 END) AS charged,
                                          SUM(CASE WHEN entry_type = 'Payment' THEN amount ELSE 0 END) AS paid
                                   FROM student_fees WHERE academic_year_id = ?$fw GROUP BY student_id) f ON f.student_id = st.id
                        WHERE st.status = 'Active'$sw AND (st.academic_year_id = ? OR f.student_id IS NOT NULL)";
                // order follows the sql: derived-table year [+ school], then student school, then the outer year
                $types = 'i' . ($fw ? 'i' : '') . ($sw ? 'i' : '') . 'i';
                $args  = array_merge([$yearId], $fw ? [sid()] : [], $sw ? [sid()] : [], [$yearId]);
                if ($classId) { $sql .= " AND st.class_id = ?";   $types .= 'i'; $args[] = $classId; }
                if ($secId)   { $sql .= " AND st.section_id = ?"; $types .= 'i'; $args[] = $secId; }
                $sql   .= " AND st.section_id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
                $types .= str_repeat('i', count($scope));
                $args   = array_merge($args, $scope);
                $sql .= " ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, CAST(st.roll_no AS UNSIGNED) ASC, u.full_name ASC";

                $rule = feeRule();
                $kpi  = $zero;
                $rows = [];
                foreach (qAll($sql, $types, ...$args) as $r) {
                    $ch  = round((float)$r['charged'], 2);
                    $pd  = round((float)$r['paid'], 2);
                    $bal = round($ch - $pd, 2);                       // charges minus payments, per student per year
                    $hold = (int)$r['fee_hold'] === 1;
                    $kpi['charged']  += $ch;
                    $kpi['received'] += $pd;
                    if ($bal > 0) { $kpi['outstanding'] += $bal; $kpi['owing']++; }
                    if ($hold || ($rule['on'] && $bal > $rule['threshold'])) $kpi['held']++;

                    $rows[] = [
                        'id'        => (int)$r['id'],
                        'adm'       => (string)$r['admission_no'],
                        'roll'      => (string)($r['roll_no'] ?? ''),
                        'name'      => (string)($r['full_name'] ?: $r['username']),
                        'cls'       => $r['class_name'] . ' – ' . $r['section_name'],
                        'charged'   => $ch,   'charged_f' => ormsMoney($ch),
                        'paid'      => $pd,   'paid_f'    => ormsMoney($pd),
                        'balance'   => $bal,  'balance_f' => ormsMoney($bal),
                        'hold'      => $hold ? 1 : 0,
                        'hold_note' => (string)($r['fee_hold_note'] ?? ''),
                        'gphone'    => (string)($r['guardian_phone'] ?? ''),
                        'gmail'     => (string)($r['guardian_email'] ?? '')
                    ];
                }
                // status narrows the table only — the kpi strip always reports the whole scope
                if (in_array($status, ['owing', 'cleared', 'hold'], true)) {
                    $rows = array_values(array_filter($rows, fn($r) =>
                        $status === 'hold' ? $r['hold'] === 1 : ($status === 'owing' ? $r['balance'] > 0 : $r['balance'] <= 0)));
                }
                foreach (['charged', 'received', 'outstanding'] as $k) {
                    $kpi[$k] = round($kpi[$k], 2);
                    $kpi[$k . '_f'] = ormsMoney($kpi[$k]);
                }
                jsonOk(['rows' => $rows, 'kpi' => $kpi, 'rule' => $rule, 'terms' => ormsTerms($yearId)]);
            }

            case 'getFeeLedger': {
                requireCsrfJson();
                requirePermJson('fees', 'v');
                if (!ormsHasFees()) jsonErr('The fees tables are not installed yet.');

                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                if (!feeInt('student_id') || !$yearId) jsonErr('Pick a student first');
                $sid = feeStudent(feeInt('student_id'), $yearId);
                if (!$sid) jsonErr('Access denied');

                $st = qOne("SELECT st.id, st.admission_no, st.roll_no, st.fee_hold, st.fee_hold_note,
                                   u.full_name, u.username, c.name AS class_name, sec.name AS section_name
                            FROM students st
                            JOIN users u      ON u.id = st.user_id
                            JOIN classes c    ON c.id = st.class_id
                            JOIN sections sec ON sec.id = st.section_id
                            WHERE st.id = ?", 'i', $sid);
                if (!$st) jsonErr('That student does not exist');

                $run = 0.0; $ch = 0.0; $pd = 0.0; $out = [];
                $lw  = feeHasCol('student_fees', 'school_id') ? " AND f.school_id = ?" : "";
                foreach (qAll("SELECT f.id, f.entry_type, f.description, f.amount, f.entry_date, f.reference, f.note,
                                      f.term_id, t.name AS term_name, uu.full_name AS by_name, f.created_at
                               FROM student_fees f
                               LEFT JOIN exam_terms t ON t.id = f.term_id
                               LEFT JOIN users uu     ON uu.id = f.created_by
                               WHERE f.student_id = ? AND f.academic_year_id = ?$lw
                               ORDER BY (f.entry_date IS NULL), f.entry_date ASC, f.id ASC",
                              'ii' . ($lw ? 'i' : ''), $sid, $yearId, ...($lw ? [sid()] : [])) as $r) {
                    $amt  = round((float)$r['amount'], 2);
                    $isCh = $r['entry_type'] === 'Charge';
                    $run  = round($run + ($isCh ? $amt : -$amt), 2);   // running balance follows the ledger order
                    if ($isCh) { $ch += $amt; } else { $pd += $amt; }
                    $out[] = [
                        'id'      => (int)$r['id'],
                        'type'    => $r['entry_type'],
                        'desc'    => (string)$r['description'],
                        'amount'  => $amt, 'amount_f' => ormsMoney($amt),
                        'date'    => (string)($r['entry_date'] ?? ''),
                        'date_f'  => $r['entry_date'] ? date('d M Y', strtotime($r['entry_date'])) : '',
                        'ref'     => (string)($r['reference'] ?? ''),
                        'note'    => (string)($r['note'] ?? ''),
                        'term_id' => (int)($r['term_id'] ?? 0),
                        'term'    => (string)($r['term_name'] ?? ''),
                        'by'      => (string)($r['by_name'] ?? ''),
                        'run'     => $run, 'run_f' => ormsMoney($run)
                    ];
                }

                $bal  = round($ch - $pd, 2);
                $rule = feeRule();
                $wh   = ormsWithholdCheck($sid, $yearId, $bal);
                jsonOk([
                    'student' => [
                        'id' => (int)$st['id'], 'adm' => (string)$st['admission_no'],
                        'name' => (string)($st['full_name'] ?: $st['username']),
                        'roll' => (string)($st['roll_no'] ?? ''),
                        'cls'  => $st['class_name'] . ' – ' . $st['section_name'],
                        'hold' => (int)$st['fee_hold'], 'hold_note' => (string)($st['fee_hold_note'] ?? '')
                    ],
                    'entries' => $out,
                    'totals'  => ['charged_f' => ormsMoney($ch), 'paid_f' => ormsMoney($pd),
                                  'balance' => $bal, 'balance_f' => ormsMoney($bal)],
                    'withheld' => !empty($wh['withheld']) ? 1 : 0,
                    'reason'   => (string)$wh['reason'],
                    'rule'     => $rule,
                    'terms'    => ormsTerms($yearId)
                ]);
            }

            case 'getBulkTargets': {
                requireCsrfJson();
                requirePermJson('fees', 'v');
                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                jsonOk(['count' => count(feeTargets($yearId, feeOwns('classes', feeInt('class_id')),
                                                    feeOwns('sections', feeInt('section_id')), feeSections($yearId)))]);
            }

            // ---------------- ledger writes ----------------
            case 'saveFeeEntry': {
                requireCsrfJson();
                $id = feeInt('id');
                requirePermJson('fees', $id ? 'e' : 'a');
                if (!ormsHasFees()) jsonErr('The fees tables are not installed yet.');

                $rawStu = feeInt('student_id');
                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $termId = feeInt('term_id');
                $type   = feeType(feeStr('entry_type'));
                $desc   = feeStr('description');
                $amt    = feeAmount(feeStr('amount'));
                $date   = feeStr('entry_date');
                $ref    = feeStr('reference');
                $note   = feeStr('note');

                $ew  = feeHasCol('student_fees', 'school_id') ? " AND school_id = ?" : "";
                $cur = $id ? qOne("SELECT * FROM student_fees WHERE id = ?$ew", 'i' . ($ew ? 'i' : ''), $id, ...($ew ? [sid()] : [])) : null;
                if ($id && !$cur)  jsonErr('That entry no longer exists');
                if ($id) { $rawStu = (int)$cur['student_id']; $yearId = (int)$cur['academic_year_id']; }

                if (!$rawStu || !$yearId)           jsonErr('Pick a student first');
                $sid = feeStudent($rawStu, $yearId);
                if (!$sid)                          jsonErr('Access denied');
                if ($type === '')                   jsonErr('Entry type must be Charge or Payment');
                if ($desc === '')                   jsonErr('Description is required');
                if ($amt === null)                  jsonErr('Amount must be a positive number');
                if ($date !== '' && !feeIsDate($date)) jsonErr('Date must be a real date (YYYY-MM-DD)');
                if (!feeTermOk($termId, $yearId))   jsonErr('That term does not belong to the selected academic year');

                $desc = mb_substr($desc, 0, 150);
                $ref  = $ref  !== '' ? mb_substr($ref, 0, 50)   : null;
                $note = $note !== '' ? mb_substr($note, 0, 255) : null;
                $dt   = $date !== '' ? $date : null;
                $tid  = $termId ?: null;

                if ($id) {
                    qExec("UPDATE student_fees SET term_id = ?, entry_type = ?, description = ?, amount = ?,
                                                   entry_date = ?, reference = ?, note = ? WHERE id = ?",
                          'issdsssi', $tid, $type, $desc, $amt, $dt, $ref, $note, $id);
                } else {
                    // 10 cols: i student, i year, i term, s type, s desc, d amount, s date, s ref, s note, i by [+ i school]
                    $id = qInsert("INSERT INTO student_fees (student_id, academic_year_id, term_id, entry_type, description,
                                                             amount, entry_date, reference, note, created_by" . ($ew ? ", school_id" : "") . ")
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($ew ? ", ?" : "") . ")",
                                  'iiissdsssi' . ($ew ? 'i' : ''),
                                  $sid, $yearId, $tid, $type, $desc, $amt, $dt, $ref, $note, $user_id, ...($ew ? [sid()] : []));
                }
                logActivity($user_id, $username, ($cur ? 'Fee Entry Updated' : ($type === 'Payment' ? 'Fee Payment Recorded' : 'Fee Charge Added')),
                            "student_id={$sid}, {$type} " . ormsMoney($amt) . " — {$desc}", 'student_fees', $id);
                jsonOk(['message' => $cur ? 'Entry updated' : ($type === 'Payment' ? 'Payment recorded' : 'Charge added'), 'id' => $id]);
            }

            case 'deleteFeeEntry': {
                requireCsrfJson();
                requirePermJson('fees', 'd');
                $id  = feeInt('id');
                $dw  = feeHasCol('student_fees', 'school_id') ? " AND school_id = ?" : "";
                $row = $id ? qOne("SELECT * FROM student_fees WHERE id = ?$dw", 'i' . ($dw ? 'i' : ''), $id, ...($dw ? [sid()] : [])) : null;
                if (!$row) jsonErr('That entry no longer exists');
                if (!feeStudent((int)$row['student_id'], (int)$row['academic_year_id'])) jsonErr('Access denied');

                qExec("DELETE FROM student_fees WHERE id = ?", 'i', $id);
                logActivity($user_id, $username, 'Fee Entry Deleted',
                            "student_id={$row['student_id']}, {$row['entry_type']} " . ormsMoney((float)$row['amount']) . " — {$row['description']}",
                            'student_fees', $id);
                jsonOk(['message' => 'Entry deleted']);
            }

            // ---------------- hold ----------------
            case 'toggleFeeHold': {
                requireCsrfJson();
                requirePermJson('fees', 'e');
                if (!ormsHasFees()) jsonErr('The fees tables are not installed yet.');

                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $on     = feeInt('hold') === 1 ? 1 : 0;
                $note   = mb_substr(feeStr('note'), 0, 150);
                if (!feeInt('student_id')) jsonErr('Pick a student first');
                $sid = feeStudent(feeInt('student_id'), $yearId);
                if (!$sid) jsonErr('Access denied');

                $st = qOne("SELECT st.admission_no, u.full_name FROM students st JOIN users u ON u.id = st.user_id WHERE st.id = ?", 'i', $sid);
                if (!$st) jsonErr('That student does not exist');

                qExec("UPDATE students SET fee_hold = ?, fee_hold_note = ? WHERE id = ?", 'isi', $on, $on ? ($note ?: null) : null, $sid);
                logActivity($user_id, $username, $on ? 'Fee Hold Placed' : 'Fee Hold Cleared',
                            "{$st['full_name']} ({$st['admission_no']})" . ($on && $note !== '' ? " — {$note}" : ''), 'students', $sid);
                jsonOk(['message' => $on ? 'Result hold placed' : 'Result hold cleared']);
            }

            // ---------------- bulk charge ----------------
            case 'bulkChargeClass': {
                requireCsrfJson();
                requirePermJson('fees', 'a');
                if (!ormsHasFees()) jsonErr('The fees tables are not installed yet.');

                $yearId  = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $classId = feeOwns('classes',  feeInt('class_id'));
                $secId   = feeOwns('sections', feeInt('section_id'));
                $termId  = feeInt('term_id');
                $desc    = feeStr('description');
                $amt     = feeAmount(feeStr('amount'));
                $date    = feeStr('entry_date');
                $ref     = feeStr('reference');
                $note    = feeStr('note');

                if (!$classId && !$secId)           jsonErr('Pick a class or a section to charge');
                if ($desc === '')                   jsonErr('Description is required');
                if ($amt === null)                  jsonErr('Amount must be a positive number');
                if ($date !== '' && !feeIsDate($date)) jsonErr('Date must be a real date (YYYY-MM-DD)');
                if (!feeTermOk($termId, $yearId))   jsonErr('That term does not belong to the selected academic year');

                $ids = feeTargets($yearId, $classId, $secId, feeSections($yearId));
                if (!$ids) jsonErr('No active students found in that class or section for this year');

                $desc = mb_substr($desc, 0, 150);
                $ref  = $ref  !== '' ? mb_substr($ref, 0, 50)   : null;
                $note = $note !== '' ? mb_substr($note, 0, 255) : null;
                $dt   = $date !== '' ? $date : null;
                $tid  = $termId ?: null;

                // ONE prepared statement, executed per student inside a single transaction
                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    $bw = feeHasCol('student_fees', 'school_id');
                    $stmt = $conn->prepare("INSERT INTO student_fees (student_id, academic_year_id, term_id, entry_type,
                                                                      description, amount, entry_date, reference, note, created_by"
                                            . ($bw ? ", school_id" : "") . ")
                                            VALUES (?, ?, ?, 'Charge', ?, ?, ?, ?, ?, ?" . ($bw ? ", ?" : "") . ")");
                    if (!$stmt) throw new RuntimeException('SQL prepare failed: ' . $conn->error);
                    $sid = 0; $sch = sid();
                    // 9 bound: i student, i year, i term, s desc, d amount, s date, s ref, s note, i by [+ i school]
                    if ($bw) $stmt->bind_param('iiisdsssii', $sid, $yearId, $tid, $desc, $amt, $dt, $ref, $note, $user_id, $sch);
                    else     $stmt->bind_param('iiisdsssi',  $sid, $yearId, $tid, $desc, $amt, $dt, $ref, $note, $user_id);
                    foreach ($ids as $sid) $stmt->execute();          // bind is by reference — $sid drives the loop
                    $stmt->close();
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                $n = count($ids);
                logActivity($user_id, $username, 'Bulk Fee Charge',
                            "{$n} student(s), " . ormsMoney($amt) . " — {$desc} (class_id={$classId}, section_id={$secId})", 'student_fees');
                jsonOk(['message' => $n . ' student(s) charged ' . ormsMoney($amt), 'count' => $n]);
            }

            // ---------------- csv import ----------------
            case 'bulkImportFees': {
                requireCsrfJson();
                requirePermJson('fees', 'a');
                if (!ormsHasFees()) jsonErr('The fees tables are not installed yet.');

                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $rows   = json_decode($_POST['rows'] ?? '[]', true);
                if (!$yearId)                   jsonErr('Pick an academic year first');
                if (!is_array($rows) || !$rows) jsonErr('Nothing to import');
                if (count($rows) > 500)         jsonErr('Please import 500 rows or fewer at a time');

                // one lookup read, then O(1) in memory. the roster read is fenced at the driver —
                // an unfenced "every active student" read would match another school's admission no
                $scope = feeSections($yearId);
                $bw    = feeHasCol('student_fees', 'school_id');
                $iw    = feeHasCol('students', 'school_id') ? " AND school_id = ?" : "";
                $known = [];
                foreach (qAll("SELECT id, admission_no, section_id FROM students WHERE status = 'Active'$iw",
                              $iw ? 'i' : '', ...($iw ? [sid()] : [])) as $s) {
                    if (!in_array((int)$s['section_id'], $scope, true)) continue;
                    $known[mb_strtolower(trim($s['admission_no']))] = (int)$s['id'];
                }

                $ok = $errors = [];
                foreach ($rows as $i => $r) {
                    $line = $i + 2;                                   // +1 header, +1 human numbering
                    if (!is_array($r)) { $errors[] = "Fila $line: formato incorrecto"; continue; }
                    $get = static fn(int $n): string => trim((string)($r[$n] ?? ''));

                    $adm = $get(0); $type = feeType($get(1)); $desc = $get(2);
                    $amt = feeAmount($get(3)); $date = $get(4); $ref = $get(5);

                    if ($adm === '' && $desc === '' && $get(3) === '') continue;        // blank trailing line
                    if (!isset($known[mb_strtolower($adm)])) { $errors[] = "Fila $line: el número de matrícula \"$adm\" no fue encontrado"; continue; }
                    if ($type === '')  { $errors[] = "Fila $line: el tipo debe ser Cargo o Pago"; continue; }
                    if ($desc === '')  { $errors[] = "Fila $line: la descripción es obligatoria"; continue; }
                    if ($amt === null) { $errors[] = "Fila $line: el monto debe ser un número positivo"; continue; }
                    if ($date !== '' && !feeIsDate($date)) { $errors[] = "Fila $line: la fecha debe ser AAAA-MM-DD o DD/MM/AAAA"; continue; }

                    $ok[] = array_merge([$known[mb_strtolower($adm)], $yearId, $type, mb_substr($desc, 0, 150), $amt,
                                         $date !== '' ? feeNormDate($date) : null, $ref !== '' ? mb_substr($ref, 0, 50) : null, $user_id],
                                        $bw ? [sid()] : []);
                }

                $n = count($ok);
                if ($n) {                                             // ONE write for the whole batch
                    // 8 per row: i student, i year, s type, s desc, d amount, s date, s ref, i by [+ i school]
                    $vals  = implode(',', array_fill(0, $n, '(?, ?, ?, ?, ?, ?, ?, ?' . ($bw ? ', ?' : '') . ')'));
                    $types = str_repeat('iissdssi' . ($bw ? 'i' : ''), $n);
                    qExec("INSERT INTO student_fees (student_id, academic_year_id, entry_type, description, amount,
                                                     entry_date, reference, created_by" . ($bw ? ", school_id" : "") . ") VALUES $vals",
                          $types, ...array_merge(...$ok));
                }
                logActivity($user_id, $username, 'Fee Entries Imported',
                            "{$n} imported, " . count($errors) . ' skipped (year_id=' . $yearId . ')', 'student_fees');
                jsonOk(['imported' => $n, 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 100)]);
            }

            // ---------------- fee structures: what each class pays, and the monthly charge run ----------------
            case 'getStructures': {
                requireCsrfJson();
                requirePermJson('fees', 'v');
                [$w, $wt, $wp] = feeWhere('c');
                $rows = qAll("SELECT fs.id, fs.class_id, fs.name, fs.amount, fs.frequency, fs.is_active, c.name AS class_name
                              FROM fee_structures fs JOIN classes c ON c.id = fs.class_id
                              WHERE 1 = 1$w
                              ORDER BY c.sort_order ASC, c.name ASC, fs.name ASC", $wt, ...$wp);
                foreach ($rows as &$r) { $r['amount_f'] = ormsMoney((float)$r['amount']); } unset($r);
                jsonOk(['data' => $rows]);
            }

            case 'saveStructure': {
                requireCsrfJson();
                $raw = feeInt('id');
                requirePermJson('fees', $raw ? 'e' : 'a');
                $cid  = feeOwns('classes', feeInt('class_id'));
                $name = mb_substr(feeStr('name'), 0, 80);
                $amt  = feeAmount($_POST['amount'] ?? '');
                $freq = feeStr('frequency') === 'One-Time' ? 'One-Time' : 'Monthly';
                $on   = !empty($_POST['is_active']) ? 1 : 0;
                if (!$cid)                    jsonErr('Please pick a class');
                if ($name === '')             jsonErr('Fee name is required (e.g. Monthly Tuition)');
                if ($amt === null || $amt <= 0) jsonErr('Amount must be a positive number');

                // fee_structures has no school col — the class join IS the fence, so verify by class
                $id = $raw ? (int)qVal("SELECT fs.id FROM fee_structures fs JOIN classes c ON c.id = fs.class_id
                                        WHERE fs.id = ?" . feeWhere('c')[0], 'i' . feeWhere('c')[1], $raw, ...feeWhere('c')[2]) : 0;
                if ($raw && !$id) jsonErr('Structure not found');
                if (qVal("SELECT id FROM fee_structures WHERE class_id = ? AND name = ? AND id <> ?", 'isi', $cid, $name, $id))
                    jsonErr('"' . $name . '" already exists for that class');

                if ($id) {
                    qExec("UPDATE fee_structures SET class_id = ?, name = ?, amount = ?, frequency = ?, is_active = ? WHERE id = ?",
                          'isdsii', $cid, $name, $amt, $freq, $on, $id);
                } else {
                    $id = qInsert("INSERT INTO fee_structures (class_id, name, amount, frequency, is_active) VALUES (?, ?, ?, ?, ?)",
                                  'isdsi', $cid, $name, $amt, $freq, $on);
                }
                logActivity($user_id, $username, 'Fee Structure Saved', "$name (#$id) — " . ormsMoney($amt) . " $freq");
                jsonOk(['message' => 'Fee structure ' . ($raw ? 'updated' : 'added')]);
            }

            case 'toggleStructure': {
                requireCsrfJson();
                requirePermJson('fees', 'e');
                [$w, $wt, $wp] = feeWhere('c');
                $row = qOne("SELECT fs.id, fs.name, fs.is_active FROM fee_structures fs JOIN classes c ON c.id = fs.class_id
                             WHERE fs.id = ?$w", 'i' . $wt, feeInt('id'), ...$wp);
                if (!$row) jsonErr('Structure not found');
                $new = (int)$row['is_active'] === 1 ? 0 : 1;
                qExec("UPDATE fee_structures SET is_active = ? WHERE id = ?", 'ii', $new, (int)$row['id']);
                jsonOk(['message' => $row['name'] . ($new ? ' activated' : ' deactivated')]);
            }

            case 'deleteStructure': {
                requireCsrfJson();
                requirePermJson('fees', 'd');
                [$w, $wt, $wp] = feeWhere('c');
                $row = qOne("SELECT fs.id, fs.name FROM fee_structures fs JOIN classes c ON c.id = fs.class_id
                             WHERE fs.id = ?$w", 'i' . $wt, feeInt('id'), ...$wp);
                if (!$row) jsonErr('Structure not found');
                // charges already generated keep living in the ledger — only the template goes
                qExec("DELETE FROM fee_structures WHERE id = ?", 'i', (int)$row['id']);
                logActivity($user_id, $username, 'Fee Structure Deleted', $row['name'] . ' (#' . $row['id'] . ')');
                jsonOk(['message' => 'Fee structure deleted — ledger entries it created stay untouched']);
            }

            case 'generateCharges': {
                requireCsrfJson();
                requirePermJson('fees', 'a');
                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $ym     = feeStr('ym');
                if (!$yearId) jsonErr('Pick an academic year first');
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) jsonErr('Pick a month first');
                if ($ym > date('Y-m', strtotime('+1 month')))      jsonErr('Charges can be raised at most one month ahead');

                [$w, $wt, $wp] = feeWhere('c');
                $fss = qAll("SELECT fs.id, fs.class_id, fs.name, fs.amount FROM fee_structures fs
                             JOIN classes c ON c.id = fs.class_id
                             WHERE fs.is_active = 1 AND fs.frequency = 'Monthly'$w", $wt, ...$wp);
                if (!$fss) jsonErr('No active monthly fee structures yet — add one first');

                $label = date('F Y', strtotime($ym . '-01'));
                $edate = $ym . '-01';
                $fw    = feeHasCol('student_fees', 'school_id');
                $sw    = feeHasCol('students', 'school_id');

                $conn = getDBConnection();
                $conn->begin_transaction();
                $added = 0;
                try {
                    foreach ($fss as $fs) {
                        $ref  = 'FS' . (int)$fs['id'] . '-' . $ym;
                        $desc = $fs['name'] . ' — ' . $label;
                        // one set-based insert per structure; the NOT EXISTS makes every re-run a no-op
                        $sql = "INSERT INTO student_fees (student_id, academic_year_id, entry_type, description, amount, entry_date, reference, created_by" . ($fw ? ", school_id" : "") . ")
                                SELECT st.id, ?, 'Charge', ?, ?, ?, ?, ?" . ($fw ? ", st.school_id" : "") . "
                                FROM students st
                                WHERE st.class_id = ? AND st.status = 'Active' AND st.academic_year_id = ?" . ($sw ? " AND st.school_id = ?" : "") . "
                                  AND NOT EXISTS (SELECT 1 FROM student_fees f WHERE f.student_id = st.id AND f.reference = ?)";
                        $t = 'isdssiii' . ($sw ? 'i' : '') . 's';
                        $p = array_merge([$yearId, $desc, (float)$fs['amount'], $edate, $ref, $user_id, (int)$fs['class_id'], $yearId],
                                         $sw ? [sid()] : [], [$ref]);
                        $added += max(0, qExec($sql, $t, ...$p));   // qExec returns the stmt's own affected count
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('fees.php generateCharges: ' . $e->getMessage());
                    jsonErr('Charge run failed — nothing was added.');
                }
                logActivity($user_id, $username, 'Fee Charges Generated', "$label: $added charge(s) across " . count($fss) . ' structure(s)');
                jsonOk(['message' => $added > 0
                    ? "$added charge(s) raised for $label"
                    : "Nothing to add — $label was already charged for every matching student", 'added' => $added]);
            }

            case 'emailFeeReminder': {
                requireCsrfJson();
                requirePermJson('fees', 'a');
                $yearId = feeOwns('academic_years', feeInt('year_id')) ?: $defYear;
                $sid2   = feeStudent(feeInt('student_id'), $yearId);
                if (!$sid2) jsonErr('Student not found');

                $st = qOne("SELECT st.guardian_email, u.full_name, c.name AS class_name, sec.name AS section_name,
                                   COALESCE(SUM(CASE WHEN f.entry_type = 'Charge' THEN f.amount ELSE 0 END), 0) -
                                   COALESCE(SUM(CASE WHEN f.entry_type = 'Payment' THEN f.amount ELSE 0 END), 0) AS bal
                            FROM students st
                            JOIN users u ON u.id = st.user_id
                            JOIN classes c ON c.id = st.class_id
                            JOIN sections sec ON sec.id = st.section_id
                            LEFT JOIN student_fees f ON f.student_id = st.id AND f.academic_year_id = ?
                            WHERE st.id = ? GROUP BY st.id", 'ii', $yearId, $sid2);
                if (!$st) jsonErr('Student not found');
                $gm = trim((string)($st['guardian_email'] ?? ''));
                if ($gm === '' || !filter_var($gm, FILTER_VALIDATE_EMAIL)) jsonErr('No guardian email on file for this student');
                $bal = round((float)$st['bal'], 2);
                if ($bal <= 0) jsonErr('This student has no outstanding balance');

                $branding = getSiteBranding();
                $site = htmlspecialchars($branding['site_name']);
                $html = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
                    . '<div style="background:#001f3f;padding:20px;text-align:center;"><h1 style="color:#fff;margin:0;font-size:22px;">' . $site . '</h1></div>'
                    . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
                    . '<h2 style="color:#333;margin-top:0;">Fee Reminder</h2>'
                    . '<p style="color:#666;">Dear Guardian, this is a gentle reminder that the fee account of <strong>'
                    . htmlspecialchars($st['full_name']) . '</strong> (' . htmlspecialchars($st['class_name'] . ' – ' . $st['section_name']) . ') shows an outstanding balance of</p>'
                    . '<div style="background:#f8f9fa;padding:20px;text-align:center;margin:20px 0;">'
                    . '<span style="font-size:28px;font-weight:bold;color:#001f3f;">' . htmlspecialchars(ormsMoney($bal)) . '</span></div>'
                    . '<p style="color:#666;">Kindly clear it at your earliest convenience. If you have already paid, please disregard this message.</p>'
                    . '</div>'
                    . '<div style="background:#f8f9fa;padding:15px;text-align:center;border:1px solid #e9ecef;border-top:none;">'
                    . '<p style="color:#999;font-size:12px;margin:0;">This is an automated message. Please do not reply.</p>'
                    . '</div></div>';
                $m = sendEmail($gm, 'Fee Reminder - ' . $branding['site_name'], $html);
                if (!$m['success']) jsonErr('Email not sent: ' . $m['message']);
                logActivity($user_id, $username, 'Fee Reminder Sent', $st['full_name'] . ' — ' . ormsMoney($bal) . " emailed to $gm");
                jsonOk(['message' => 'Reminder emailed to ' . $gm]);
            }

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('fees.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');
    }
}

// ---------------------------------------------------------------- page data

[$cw, $ct, $cp] = feeWhere('c');
$classes  = qAll("SELECT c.id, c.name FROM classes c WHERE c.is_active = 1$cw ORDER BY c.sort_order ASC, c.name ASC", $ct, ...$cp);
$terms    = $defYear ? ormsTerms($defYear) : [];
$rule     = feeRule();
$sym      = ormsCurrency()['symbol'];
$ready    = ormsHasFees();
$today    = date('Y-m-d');
$yearName = '';
foreach ($years as $y) if ((int)$y['id'] === $defYear) { $yearName = $y['name']; break; }
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
    <title>Fees &amp; Result Withholding - Online Result Management</title>

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
                    <h1><i class="fas fa-money-bill"></i> Fees</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Fees</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">

                <?php if (!$ready): ?>
                <div class="orms-empty">
                    <i class="fas fa-database"></i>
                    <h4>Fees are not installed yet</h4>
                    <p>Run <b>update_setup.php</b> once to create the fee ledger, then reload this page.</p>
                </div>
                <?php else: ?>

                <div class="tab-nav no-print" id="feeTabs">
                    <button type="button" class="tab-btn active" data-tab="balances"><i class="fas fa-scale-balanced"></i> Student Balances</button>
                    <button type="button" class="tab-btn" data-tab="ledger"><i class="fas fa-receipt"></i> Ledger</button>
                    <button type="button" class="tab-btn" data-tab="structures"><i class="fas fa-list-check"></i> Fee Structures</button>
                </div>

            <!-- ============ TAB 1: BALANCES ============ -->
            <div class="tab-pane active" id="pane-balances">
                <div class="section-header">
                    <h2><i class="fas fa-scale-balanced"></i> Student Balances <span class="myr-sub" id="balLabel"></span></h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-primary" id="btnRefresh" onclick="loadOverview(this)"><i class="fas fa-sync"></i> Refresh</button>
                        <?php if ($canAdd): ?>
                        <button type="button" class="btn btn-success" onclick="openBulk()"><i class="fas fa-layer-group"></i> Bulk Charge</button>
                        <button type="button" class="btn btn-secondary" onclick="feeTemplate()"><i class="fas fa-download"></i> Plantilla</button>
                        <button type="button" class="btn btn-secondary" id="btnImportFees" onclick="document.getElementById('feeCsvInput').click()"><i class="fas fa-file-import"></i> Importar CSV</button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- what is actually withholding results right now. edited in Result Settings, only reported here -->
                <div class="info-banner info-banner-top mb-24" id="ruleBanner">
                    <i class="fas fa-shield-halved"></i>
                    <span>
                        <b>Withholding rule:</b>
                        <span id="ruleText"><?php echo $rule['on']
                            ? 'Arrears withholding is <b>ON</b> — a balance above ' . htmlspecialchars($rule['threshold_f']) . ' hides the card from the family.'
                            : 'Arrears withholding is <b>OFF</b> — only a manual hold below hides a card.'; ?></span>
                        <span id="ruleCount"></span>
                        A hold or an unpaid balance <b>never blocks publishing</b> a section — it only hides that one student&rsquo;s card from the family. Staff always see it, stamped.
                        The flag is frozen onto the card when the section is published, so change a hold <em>before</em> publishing (or unpublish and publish again).
                        <a href="result_settings.php"><i class="fas fa-sliders"></i> Result Settings &rarr; Options</a> owns the switch, the threshold and the message families see.
                    </span>
                </div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear All</button>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                            <select id="filterYear" class="filter-input">
                                <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$y['id'] === $defYear ? ' selected' : ''; ?>><?php echo htmlspecialchars($y['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-school"></i> Class</label>
                            <select id="filterClass" class="filter-input">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Section</label>
                            <select id="filterSection" class="filter-input">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter-circle-dollar"></i> Status</label>
                            <select id="filterStatus" class="filter-input">
                                <option value="">All Students</option>
                                <option value="owing">Owing</option>
                                <option value="cleared">Cleared</option>
                                <option value="hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="lte-kpi-grid" id="kpiSkeleton">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-70"></div>
                    </div>
                    <?php endfor; ?>
                </div>

                <div class="lte-kpi-grid fee-kpi initially-hidden" id="feeKpi">
                    <div class="small-box bg-navy">
                        <div class="inner"><h3 id="kpiCharged">—</h3><p>Total Charged</p></div>
                        <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
                        <a href="#feeTable" class="small-box-footer js-status" data-status="">All students <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box bg-success">
                        <div class="inner"><h3 id="kpiReceived">—</h3><p>Total Received</p></div>
                        <div class="icon"><i class="fas fa-hand-holding-dollar"></i></div>
                        <a href="#feeTable" class="small-box-footer js-status" data-status="cleared">Cleared students <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box bg-warning">
                        <div class="inner"><h3 id="kpiOutstanding">—</h3><p>Outstanding</p></div>
                        <div class="icon"><i class="fas fa-scale-unbalanced"></i></div>
                        <a href="#feeTable" class="small-box-footer js-status" data-status="owing">Who owes <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                    <div class="small-box bg-navy-2">
                        <div class="inner"><h3 id="kpiOwing">—</h3><p>Students Owing</p></div>
                        <div class="icon"><i class="fas fa-user-clock"></i></div>
                        <a href="#feeTable" class="small-box-footer js-status" data-status="owing">Open the list <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>

                <div id="importResult" class="initially-hidden"></div>

                <div id="balSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="balWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="feeTable" class="display table-full-width"></table>
                    </div>
                </div>

                <div id="balEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-inbox"></i>
                    <h4>No students to show</h4>
                    <p>Pick another academic year, class or section above.</p>
                </div>
            </div>

            <!-- ============ TAB 2: LEDGER ============ -->
            <div class="tab-pane" id="pane-ledger">
                <div class="section-header">
                    <h2><i class="fas fa-receipt"></i> Fee Ledger <span class="myr-sub" id="ledLabel"></span></h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-secondary initially-hidden" id="ledBack" onclick="backToBalances()"><i class="fas fa-arrow-left"></i> Back to Balances</button>
                        <?php if ($canAdd): ?>
                        <button type="button" class="btn btn-success initially-hidden" id="ledCharge" onclick="openEntry('Charge', 0)"><i class="fas fa-plus"></i> Add Charge</button>
                        <button type="button" class="btn btn-primary initially-hidden" id="ledPay" onclick="openEntry('Payment', 0)"><i class="fas fa-money-bill-wave"></i> Record Payment</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="ledEmpty" class="orms-empty">
                    <i class="fas fa-receipt"></i>
                    <h4>No ledger open</h4>
                    <p>Open a student&rsquo;s ledger from the Student Balances tab to see every charge and payment.</p>
                </div>

                <div id="ledCard" class="fee-ledger initially-hidden">
                    <div class="stat-mini">
                        <div><i class="fas fa-user-graduate"></i> <b id="ledName">—</b></div>
                        <div><i class="fas fa-hashtag"></i> <span id="ledAdm">—</span></div>
                        <div><i class="fas fa-chalkboard"></i> <span id="ledCls">—</span></div>
                        <div><i class="fas fa-file-invoice-dollar"></i> Charged <b id="ledCharged">—</b></div>
                        <div><i class="fas fa-hand-holding-dollar"></i> Paid <b id="ledPaid">—</b></div>
                        <div><i class="fas fa-scale-balanced"></i> Balance <b id="ledBal" class="fee-balance">—</b></div>
                    </div>

                    <div class="info-banner info-banner-warning mb-24 initially-hidden" id="ledWithheld">
                        <i class="fas fa-lock"></i>
                        <span id="ledWithheldText"></span>
                    </div>

                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="about-table-wrapper">
                        <table class="about-roles-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar-day"></i> Date</th>
                                    <th><i class="fas fa-tag"></i> Type</th>
                                    <th><i class="fas fa-align-left"></i> Description</th>
                                    <th><i class="fas fa-hashtag"></i> Reference</th>
                                    <th><i class="fas fa-coins"></i> Amount</th>
                                    <th><i class="fas fa-scale-balanced"></i> Balance</th>
                                    <th><i class="fas fa-bolt"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody id="ledBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ============ TAB 3: FEE STRUCTURES ============ -->
            <div class="tab-pane" id="pane-structures">
                <div class="section-header">
                    <h2><i class="fas fa-list-check"></i> Fee Structures</h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-primary" id="btnFsRefresh" onclick="loadStructures(this)"><i class="fas fa-sync"></i> Refresh</button>
                        <?php if ($canAdd): ?>
                        <button type="button" class="btn btn-success" onclick="openFs()"><i class="fas fa-plus"></i> Add Structure</button>
                        <button type="button" class="btn btn-secondary" onclick="openGen()"><i class="fas fa-wand-magic-sparkles"></i> Generate Monthly Charges</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>Define <b>what each class pays</b> (e.g. Monthly Tuition), then run <b>Generate Monthly Charges</b> once a month —
                          every active student of that class gets the charge in their ledger. The run is safe to repeat: a month already
                          charged is never charged twice.</span>
                </div>

                <div id="fsEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-list-check"></i>
                    <h4>No fee structures yet</h4>
                    <p><?php echo $canAdd ? 'Use <strong>Add Structure</strong> above — e.g. "Monthly Tuition" for each class.' : 'Ask an admin to define the fee structures.'; ?></p>
                </div>

                <div id="fsWrap" class="about-table-wrapper initially-hidden">
                    <table class="about-roles-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-school"></i> Class</th>
                                <th><i class="fas fa-tag"></i> Fee</th>
                                <th><i class="fas fa-coins"></i> Amount</th>
                                <th><i class="fas fa-arrows-rotate"></i> Frequency</th>
                                <th><i class="fas fa-toggle-on"></i> Status</th>
                                <th><i class="fas fa-bolt"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fsBody"></tbody>
                    </table>
                </div>
            </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Fee Structure Modal -->
    <div class="modal-overlay" id="fsModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="fsTitle"><i class="fas fa-list-check"></i> Add Fee Structure</h3>
                <button type="button" class="close-btn" onclick="closeModal('#fsModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="fsForm">
                    <input type="hidden" id="fsId">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-school"></i> Class *</label>
                            <select id="fsClass"></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Fee Name *</label>
                            <input type="text" id="fsName" maxlength="80" required placeholder="e.g. Monthly Tuition">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Amount *</label>
                            <input type="number" id="fsAmount" min="0.01" step="0.01" required inputmode="decimal">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrows-rotate"></i> Frequency</label>
                            <select id="fsFreq">
                                <option value="Monthly">Monthly — included in the monthly charge run</option>
                                <option value="One-Time">One-Time — charged manually (admission, exam fee)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Active</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="fsActive" class="toggle-input" checked>
                                <label for="fsActive" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveFs"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('#fsModal')"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Generate Charges Modal -->
    <div class="modal-overlay" id="genModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-wand-magic-sparkles"></i> Generate Monthly Charges</h3>
                <button type="button" class="close-btn" onclick="closeModal('#genModal')"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Month</label>
                    <input type="month" id="genMonth" value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m', strtotime('+1 month')); ?>">
                    <div class="help-text"><i class="fas fa-info-circle"></i> Every <b>active Monthly</b> structure raises one charge per active student of its class.
                        A student already charged for this month is skipped — running twice never double-bills.</div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="btnGen" onclick="runGen(this)"><i class="fas fa-wand-magic-sparkles"></i> Generate</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('#genModal')"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Charge / Payment Modal -->
    <div class="modal-overlay" id="entryModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="entryTitle"><i class="fas fa-plus"></i> Add Charge</h3>
                <button type="button" class="close-btn" id="btnCloseEntry"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="entryForm">
                    <input type="hidden" id="eId">
                    <input type="hidden" id="eType">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Description *</label>
                            <input type="text" id="eDesc" maxlength="150" required placeholder="e.g. Term 1 tuition">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Amount (<?php echo htmlspecialchars($sym); ?>) *</label>
                            <input type="number" id="eAmount" min="0.01" step="0.01" required placeholder="0.00">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Always a positive number — Charge or Payment decides the sign.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date</label>
                            <input type="date" id="eDate" value="<?php echo $today; ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-pen"></i> Term</label>
                            <select id="eTerm"><option value="">Not term-specific</option></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference</label>
                            <input type="text" id="eRef" maxlength="50" placeholder="Receipt / voucher no">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Note</label>
                            <input type="text" id="eNote" maxlength="255" placeholder="Optional">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveEntry"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelEntry"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hold Modal -->
    <div class="modal-overlay" id="holdModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Withhold Result</h3>
                <button type="button" class="close-btn" id="btnCloseHold"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-banner info-banner-warning mb-24">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>A hold withholds this student&rsquo;s card from the family <b>whatever the ledger says</b> — even at a zero balance, and even when arrears withholding is switched off. Use it for schools that track fees outside this system. Staff still see the card, stamped.</span>
                </div>
                <form id="holdForm">
                    <input type="hidden" id="hId">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-graduate"></i> Student</label>
                            <input type="text" id="hName" readonly>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Hold this result</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="hOn" value="1" class="toggle-input">
                                <label for="hOn" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Reason shown to staff</label>
                            <input type="text" id="hNote" maxlength="150" placeholder="e.g. Fees cleared at the office only">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveHold"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelHold"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Charge Modal -->
    <div class="modal-overlay" id="bulkModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-layer-group"></i> Charge a Whole Class</h3>
                <button type="button" class="close-btn" id="btnCloseBulk"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-banner mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>One charge is written to every <b>active</b> student of the class (or just one section) for the selected academic year, in a single transaction.</span>
                </div>
                <form id="bulkForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-school"></i> Class *</label>
                            <select id="bClass" required>
                                <option value="">Select class</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Section</label>
                            <select id="bSection"><option value="">Whole class</option></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Description *</label>
                            <input type="text" id="bDesc" maxlength="150" required placeholder="e.g. Term 2 tuition">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Amount per student (<?php echo htmlspecialchars($sym); ?>) *</label>
                            <input type="number" id="bAmount" min="0.01" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Date</label>
                            <input type="date" id="bDate" value="<?php echo $today; ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-pen"></i> Term</label>
                            <select id="bTerm"><option value="">Not term-specific</option></select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference</label>
                            <input type="text" id="bRef" maxlength="50" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Note</label>
                            <input type="text" id="bNote" maxlength="255" placeholder="Optional">
                        </div>
                    </div>
                    <div class="stat-mini">
                        <div><i class="fas fa-users"></i> Students to charge <b id="bCount">0</b></div>
                        <div><i class="fas fa-calculator"></i> Total <b id="bTotal">—</b></div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveBulk"><i class="fas fa-bolt"></i> Charge Students</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelBulk"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Import Preview Modal -->
    <div class="modal-overlay" id="importModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-file-import"></i> Import Preview</h3>
                <button type="button" class="close-btn" id="btnCloseImport"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-banner mb-24" id="impSummary"><i class="fas fa-circle-info"></i> <span></span></div>
                <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                <div class="about-table-wrapper">
                    <table class="about-roles-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-list-ol"></i> #</th>
                                <th><i class="fas fa-id-card"></i> Admission No</th>
                                <th><i class="fas fa-tag"></i> Type</th>
                                <th><i class="fas fa-align-left"></i> Description</th>
                                <th><i class="fas fa-coins"></i> Amount</th>
                                <th><i class="fas fa-calendar-day"></i> Date</th>
                                <th><i class="fas fa-hashtag"></i> Reference</th>
                            </tr>
                        </thead>
                        <tbody id="impBody"></tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="btnDoImport"><i class="fas fa-file-import"></i> Import</button>
                    <button type="button" class="btn btn-secondary" id="btnCancelImport"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <input type="file" id="feeCsvInput" accept=".csv,text/csv" class="initially-hidden">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="orms.js?v=2.6"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>
    <?php if ($ready): ?>
    <script>
    var CAN = { a: <?php echo $canAdd ? 'true' : 'false'; ?>, e: <?php echo $canEdit ? 'true' : 'false'; ?>, d: <?php echo $canDel ? 'true' : 'false'; ?> };
    var SYM = <?php echo json_encode($sym); ?>;
    var FEE_CSV_HEAD_ES = ['numero_matricula', 'tipo', 'descripcion', 'monto', 'fecha', 'referencia'];
    var FEE_CSV_HEAD_EN = ['admission no', 'type', 'description', 'amount', 'date', 'reference'];
    var CSV_HEAD = FEE_CSV_HEAD_ES;
    var rows = [], feeTable = null, TERMS = <?php echo json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $terms)); ?>;
    var CUR = null, LED = null, RULE = <?php echo json_encode($rule); ?>, impRows = [];

    function money(v) { return SYM + ORMS.money(v); }
    function yearId()  { return parseInt($('#filterYear').val() || 0, 10); }
    function vis(sel, on) { $(sel).toggleClass('initially-hidden', !on); }
    function closeModal(sel) { $(sel).removeClass('active'); }

    // pdf/excel libs pulled only when an export is clicked
    function loadExportDeps(cb) {
        if (window.pdfMake) { cb(); return; }
        var urls = ['https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
                    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
                    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'];
        var n = 0;
        (function next() {
            if (n >= urls.length) { cb(); return; }
            var s = document.createElement('script');
            s.src = urls[n];
            s.onload = function () { n++; next(); };
            document.head.appendChild(s);
        })();
    }

    $(document).ready(function () {
        ORMS.dropdown('#filterYear, #filterClass, #filterSection, #filterStatus, #eTerm, #bClass, #bSection, #bTerm');
        $('#filterYear').on('change', function () { loadSections('#filterSection', $('#filterClass').val(), 'All Sections'); loadOverview(); });
        $('#filterClass').on('change', function () { loadSections('#filterSection', $(this).val(), 'All Sections'); });
        $('#filterSection, #filterStatus').on('change', function () { loadOverview(); });
        $(document).on('click', '.js-status', function (e) {
            e.preventDefault();
            $('#filterStatus').val($(this).data('status') || '');
            ORMS.dropdown.refresh('#filterStatus');
            loadOverview();
        });

        $('#feeTabs').on('click', '.tab-btn', function () {
            var t = $(this).data('tab'), $b = $(this);
            ORMS.swap(function () {
                $('#feeTabs .tab-btn').removeClass('active');
                $b.addClass('active');
                $('.tab-pane').removeClass('active');
                $('#pane-' + t).addClass('active');
                if (t === 'balances' && feeTable) { feeTable.columns.adjust(); if (feeTable.responsive) feeTable.responsive.recalc(); }
                if (t === 'structures' && !FS_LOADED) loadStructures();
            });
        });

        $('#btnCloseEntry, #btnCancelEntry').on('click', function () { closeModal('#entryModal'); });
        $('#btnCloseHold,  #btnCancelHold').on('click',  function () { closeModal('#holdModal'); });
        $('#btnCloseBulk,  #btnCancelBulk').on('click',  function () { closeModal('#bulkModal'); });
        $('#btnCloseImport, #btnCancelImport').on('click', function () { closeModal('#importModal'); });
        $('#entryForm').on('submit', saveEntry);
        $('#holdForm').on('submit', saveHold);
        $('#bulkForm').on('submit', saveBulk);
        $('#bClass').on('change', function () { loadSections('#bSection', $(this).val(), 'Whole class', bulkCount); });
        $('#bSection').on('change', bulkCount);
        $('#bAmount').on('input', bulkTotal);
        $('#btnDoImport').on('click', doImport);

        loadSections('#filterSection', '', 'All Sections');
        loadOverview();
    });

    // class -> its sections, reused by the filter bar and the bulk modal
    function loadSections(sel, classId, allLabel, cb) {
        var $s = $(sel).html('<option value="">' + allLabel + '</option>');
        ORMS.dropdown.refresh(sel);
        if (!classId) { if (cb) cb(); return; }
        ORMS.post('getFeeSections', { class_id: classId, year_id: yearId() }).done(function (res) {
            (res.data || []).forEach(function (r) { $s.append($('<option>').val(r.id).text(r.name)); });
            ORMS.dropdown.refresh(sel);
            if (cb) cb();
        }).fail(function () { if (cb) cb(); });
    }

    function clearFilters() {
        $('#filterClass, #filterStatus').val('');
        $('#filterSection').html('<option value="">All Sections</option>');
        ORMS.dropdown.refresh('#filterClass, #filterSection, #filterStatus');
        loadOverview();
    }

    function loadOverview(btn) {
        vis('#feeKpi', false); vis('#kpiSkeleton', true);
        vis('#balSkeleton', true); vis('#balWrap', false); vis('#balEmpty', false);

        ORMS.post('getFeeOverview', {
            year_id: yearId(), class_id: $('#filterClass').val() || 0,
            section_id: $('#filterSection').val() || 0, status: $('#filterStatus').val() || ''
        }, btn ? { btn: btn, busyLabel: 'Loading…' } : {}).done(function (res) {
            vis('#kpiSkeleton', false); vis('#balSkeleton', false);
            if (!res.success) { vis('#balEmpty', true); $('#balEmpty p').text(res.message || 'Could not load balances.'); ORMS.err(res.message); return; }
            rows  = res.rows || [];
            TERMS = res.terms || [];
            RULE  = res.rule || RULE;
            renderRule(res.kpi || {});
            renderKpi(res.kpi || {});
            $('#balLabel').text('— ' + ($('#filterYear option:selected').text() || ''));
            if (!rows.length) { vis('#balEmpty', true); return; }
            vis('#balWrap', true);
            buildTable();
        }).fail(function (msg) {
            vis('#kpiSkeleton', false); vis('#balSkeleton', false);
            vis('#balEmpty', true); $('#balEmpty p').text('Could not reach the server.');
            ORMS.err(msg);
        });
    }

    function renderKpi(k) {
        $('#kpiCharged').text(k.charged_f || money(0));
        $('#kpiReceived').text(k.received_f || money(0));
        $('#kpiOutstanding').text(k.outstanding_f || money(0));
        $('#kpiOwing').text(k.owing || 0);
        vis('#feeKpi', true);
    }

    // the rule panel is a report of Result Settings, never an editor
    function renderRule(k) {
        $('#ruleText').html(RULE.on
            ? 'Arrears withholding is <b>ON</b> — a balance above ' + ORMS.esc(RULE.threshold_f) + ' hides the card from the family.'
            : 'Arrears withholding is <b>OFF</b> — only a manual hold below hides a card.');
        $('#ruleCount').html(' <b>' + (k.held || 0) + '</b> student(s) would be withheld right now. ');
    }

    function balCell(v) {
        return '<span class="fee-balance ' + (v > 0 ? 'fee-owing' : 'fee-clear') + '">' + ORMS.esc(money(v)) + '</span>';
    }

    function buildTable() {
        if (feeTable) { feeTable.destroy(); $('#feeTable').empty(); feeTable = null; }
        feeTable = $('#feeTable').DataTable({
            data: rows,
            destroy: true,
            columns: [
                { data: 'adm', title: '<i class="fas fa-id-card"></i> Admission No', render: function (d) { return ORMS.esc(d); } },
                { data: null,  title: '<i class="fas fa-user-graduate"></i> Student',
                  render: function (d, t, r) {
                      if (t !== 'display') return r.name;
                      return '<strong>' + ORMS.esc(r.name) + '</strong>' + (r.roll ? '<br><small class="text-muted">Roll ' + ORMS.esc(r.roll) + '</small>' : '');
                  } },
                { data: 'cls', title: '<i class="fas fa-chalkboard"></i> Class – Section', render: function (d) { return ORMS.esc(d); } },
                { data: null,  title: '<i class="fas fa-file-invoice-dollar"></i> Charged',
                  render: function (d, t, r) { return t === 'display' ? ORMS.esc(r.charged_f) : r.charged; } },
                { data: null,  title: '<i class="fas fa-hand-holding-dollar"></i> Paid',
                  render: function (d, t, r) { return t === 'display' ? ORMS.esc(r.paid_f) : r.paid; } },
                { data: null,  title: '<i class="fas fa-scale-balanced"></i> Balance',
                  render: function (d, t, r) { return t === 'display' ? balCell(r.balance) : r.balance; } },
                { data: null,  title: '<i class="fas fa-lock"></i> Hold',
                  render: function (d, t, r) {
                      if (t !== 'display') return r.hold;
                      if (!r.hold) return '<span class="text-muted">—</span>';
                      return '<span class="fee-hold"><i class="fas fa-lock"></i> On hold</span>' +
                             (r.hold_note ? '<br><small class="text-muted">' + ORMS.esc(r.hold_note) + '</small>' : '');
                  } },
                { data: null, title: '<i class="fas fa-bolt"></i> Actions', orderable: false,
                  render: function (d, t, r) {
                      var b = '<button class="action-icon" title="Ledger" onclick="openLedger(' + r.id + ')"><i class="fas fa-receipt"></i></button>';
                      if (CAN.a) {
                          b += '<button class="action-icon" title="Add charge" onclick="entryFor(' + r.id + ', \'Charge\')"><i class="fas fa-plus"></i></button>';
                          b += '<button class="action-icon" title="Record payment" onclick="entryFor(' + r.id + ', \'Payment\')"><i class="fas fa-money-bill-wave"></i></button>';
                      }
                      if (CAN.e) b += '<button class="action-icon" title="Hold / release result" onclick="openHold(' + r.id + ')"><i class="fas fa-lock"></i></button>';
                      b += '<button class="action-icon" title="Print challan" onclick="printChallan(' + r.id + ')"><i class="fas fa-file-invoice"></i></button>';
                      if (r.balance > 0) {          // reminders only make sense while something is owed
                          if (r.gphone) b += '<a class="action-icon" title="WhatsApp fee reminder" target="_blank" rel="noopener" href="' + waFeeLink(r) + '"><i class="fa-brands fa-whatsapp"></i></a>';
                          if (CAN.a && r.gmail) b += '<button class="action-icon" title="Email fee reminder" onclick="mailReminder(' + r.id + ', this)"><i class="fas fa-envelope"></i></button>';
                      }
                      return b;
                  } }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Student Fee Balances', exportOptions: { columns: ':not(:last-child)' } },
                { text: '<i class="fas fa-file-pdf"></i> PDF',
                  action: function (e, dt, node, cfg) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, cfg); });
                  },
                  title: 'Student Fee Balances', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Student Fee Balances', exportOptions: { columns: ':not(:last-child)' } }
            ],
            order: [[0, 'asc']],
            language: { emptyTable: 'No students found for these filters' }
        });
    }

    function rowOf(id) {
        for (var i = 0; i < rows.length; i++) if (rows[i].id === id) return rows[i];
        return null;
    }

    // ---------------- ledger ----------------

    function openLedger(id) {
        var r = rowOf(id);
        CUR = r ? { id: r.id, name: r.name } : { id: id, name: '' };
        $('#feeTabs .tab-btn[data-tab="ledger"]').trigger('click');
        loadLedger();
    }

    function backToBalances() { $('#feeTabs .tab-btn[data-tab="balances"]').trigger('click'); }

    function loadLedger() {
        if (!CUR) return;
        ORMS.post('getFeeLedger', { student_id: CUR.id, year_id: yearId() }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Could not load the ledger'); return; }
            LED = res;
            TERMS = res.terms || TERMS;
            renderLedger();
        }).fail(function (msg) { ORMS.err(msg); });
    }

    function renderLedger() {
        var s = LED.student, t = LED.totals;
        CUR = { id: s.id, name: s.name };
        $('#ledName').text(s.name);
        $('#ledAdm').text(s.adm);
        $('#ledCls').text(s.cls);
        $('#ledLabel').text('— ' + s.name + ' (' + s.adm + ')');
        $('#ledCharged').text(t.charged_f);
        $('#ledPaid').text(t.paid_f);
        $('#ledBal').text(t.balance_f).removeClass('fee-owing fee-clear').addClass(t.balance > 0 ? 'fee-owing' : 'fee-clear');

        if (LED.withheld) {
            $('#ledWithheldText').html('<b>This result is withheld.</b> ' + ORMS.esc(LED.reason || '') +
                ' — the family sees the withhold message instead of the card, staff still see it stamped.');
            vis('#ledWithheld', true);
        } else { vis('#ledWithheld', false); }

        var html = '';
        (LED.entries || []).forEach(function (e) {
            var act = '';
            if (CAN.e) act += '<button class="action-icon edit-icon" title="Edit" onclick="editEntry(' + e.id + ')"><i class="fas fa-edit"></i></button>';
            if (CAN.d) act += '<button class="action-icon delete-icon" title="Delete" onclick="delEntry(' + e.id + ', this)"><i class="fas fa-trash"></i></button>';
            html += '<tr>' +
                '<td>' + (e.date_f ? ORMS.esc(e.date_f) : '<span class="text-muted">—</span>') + '</td>' +
                '<td><span class="status-badge ' + (e.type === 'Payment' ? 'status-active' : 'status-current') + '">' + ORMS.esc(e.type) + '</span></td>' +
                '<td>' + ORMS.esc(e.desc) + (e.term ? '<br><small class="text-muted">' + ORMS.esc(e.term) + '</small>' : '') +
                        (e.note ? '<br><small class="text-muted">' + ORMS.esc(e.note) + '</small>' : '') + '</td>' +
                '<td>' + (e.ref ? ORMS.esc(e.ref) : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + (e.type === 'Payment' ? '- ' : '+ ') + ORMS.esc(e.amount_f) + '</td>' +
                '<td>' + balCell(e.run) + '</td>' +
                '<td>' + (act || '<span class="text-muted">—</span>') + '</td></tr>';
        });
        $('#ledBody').html(html || '<tr><td colspan="7"><i class="fas fa-inbox"></i> No charges or payments recorded for this year yet.</td></tr>');
        vis('#ledEmpty', false); vis('#ledCard', true); vis('#ledBack', true);
        vis('#ledCharge', CAN.a); vis('#ledPay', CAN.a);
    }

    // ---------------- charge / payment ----------------

    function fillTerms(sel, val) {
        var $t = $(sel).html('<option value="">Not term-specific</option>');
        TERMS.forEach(function (t) { $t.append($('<option>').val(t.id).text(t.name)); });
        $t.val(val || '');
        ORMS.dropdown.refresh(sel);
    }

    // from the balances table — pick the student, then open the same modal the ledger uses
    function entryFor(id, type) {
        var r = rowOf(id);
        CUR = { id: id, name: r ? r.name : '' };
        openEntry(type, 0);
    }

    function openEntry(type, id) {
        if (!CUR) { ORMS.err('Pick a student first'); return; }
        document.getElementById('entryForm').reset();
        $('#eId').val(id || '');
        $('#eType').val(type);
        $('#eDate').val('<?php echo $today; ?>');
        fillTerms('#eTerm', '');
        $('#entryTitle').html(type === 'Payment'
            ? '<i class="fas fa-money-bill-wave"></i> Record Payment — ' + ORMS.esc(CUR.name)
            : '<i class="fas fa-plus"></i> Add Charge — ' + ORMS.esc(CUR.name));
        $('#entryModal').addClass('active');
    }

    function editEntry(id) {
        var e = null;
        (LED.entries || []).forEach(function (x) { if (x.id === id) e = x; });
        if (!e) return;
        openEntry(e.type, id);
        $('#entryTitle').html('<i class="fas fa-pen-to-square"></i> Edit ' + ORMS.esc(e.type) + ' — ' + ORMS.esc(CUR.name));
        $('#eDesc').val(e.desc);
        $('#eAmount').val(e.amount);
        $('#eDate').val(e.date || '');
        $('#eRef').val(e.ref || '');
        $('#eNote').val(e.note || '');
        fillTerms('#eTerm', e.term_id || '');
    }

    function saveEntry(ev) {
        ev.preventDefault();
        ORMS.post('saveFeeEntry', {
            id: $('#eId').val() || 0, student_id: CUR ? CUR.id : 0, year_id: yearId(),
            entry_type: $('#eType').val(), description: $.trim($('#eDesc').val() || ''),
            amount: $('#eAmount').val(), entry_date: $('#eDate').val() || '',
            term_id: $('#eTerm').val() || 0, reference: $.trim($('#eRef').val() || ''),
            note: $.trim($('#eNote').val() || '')
        }, { btn: '#btnSaveEntry', busyLabel: 'Saving…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Could not save'); return; }
            closeModal('#entryModal');
            ORMS.ok(res.message || 'Saved');
            loadLedger(); loadOverview();
        }).fail(function (msg) { ORMS.err(msg); });
    }

    function delEntry(id, btn) {
        ORMS.confirmDelete('Delete this ledger entry? The balance is recalculated immediately.').then(function (yes) {
            if (!yes) return;
            ORMS.post('deleteFeeEntry', { id: id }, { btn: btn, busyLabel: ' ' }).done(function (res) {
                if (!res.success) { ORMS.err(res.message || 'Could not delete'); return; }
                ORMS.ok(res.message || 'Entry deleted');
                loadLedger(); loadOverview();
            }).fail(function (msg) { ORMS.err(msg); });
        });
    }

    // ---------------- hold ----------------

    function openHold(id) {
        var r = rowOf(id) || (LED && LED.student && LED.student.id === id ? LED.student : null);
        if (!r) return;
        $('#hId').val(id);
        $('#hName').val((r.name || '') + (r.adm ? ' — ' + r.adm : ''));
        $('#hOn').prop('checked', !!r.hold);
        $('#hNote').val(r.hold_note || '');
        $('#holdModal').addClass('active');
    }

    function saveHold(ev) {
        ev.preventDefault();
        ORMS.post('toggleFeeHold', {
            student_id: $('#hId').val(), year_id: yearId(),
            hold: $('#hOn').is(':checked') ? 1 : 0, note: $.trim($('#hNote').val() || '')
        }, { btn: '#btnSaveHold', busyLabel: 'Saving…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Could not save'); return; }
            closeModal('#holdModal');
            ORMS.ok(res.message || 'Saved');
            loadOverview();
            if (CUR && String(CUR.id) === String($('#hId').val())) loadLedger();
        }).fail(function (msg) { ORMS.err(msg); });
    }

    // ---------------- bulk charge ----------------

    function openBulk() {
        document.getElementById('bulkForm').reset();
        $('#bSection').html('<option value="">Whole class</option>');
        fillTerms('#bTerm', '');
        $('#bDate').val('<?php echo $today; ?>');
        ORMS.dropdown.refresh('#bClass, #bSection');
        $('#bCount').text('0'); $('#bTotal').text('—');
        $('#bulkModal').addClass('active');
    }

    function bulkCount() {
        var cid = $('#bClass').val() || 0;
        if (!cid) { $('#bCount').text('0'); bulkTotal(); return; }
        ORMS.post('getBulkTargets', { year_id: yearId(), class_id: cid, section_id: $('#bSection').val() || 0 })
            .done(function (res) { $('#bCount').text(res.success ? (res.count || 0) : 0); bulkTotal(); });
    }

    function bulkTotal() {
        var n = parseInt($('#bCount').text() || 0, 10), a = parseFloat($('#bAmount').val() || 0);
        $('#bTotal').text(n && a > 0 ? money(n * a) : '—');
    }

    function saveBulk(ev) {
        ev.preventDefault();
        var n = parseInt($('#bCount').text() || 0, 10);
        if (!n) { ORMS.err('No active students in that class or section for this year'); return; }
        Swal.fire({
            icon: 'question', title: 'Charge ' + n + ' student(s)?',
            html: 'Each gets <b>' + ORMS.esc(money($('#bAmount').val() || 0)) + '</b> — ' + ORMS.esc($.trim($('#bDesc').val() || '')),
            showCancelButton: true, confirmButtonText: '<i class="fas fa-bolt"></i> Charge', cancelButtonText: 'Cancel'
        }).then(function (x) {
            if (!x.isConfirmed) return;
            ORMS.post('bulkChargeClass', {
                year_id: yearId(), class_id: $('#bClass').val() || 0, section_id: $('#bSection').val() || 0,
                term_id: $('#bTerm').val() || 0, description: $.trim($('#bDesc').val() || ''),
                amount: $('#bAmount').val(), entry_date: $('#bDate').val() || '',
                reference: $.trim($('#bRef').val() || ''), note: $.trim($('#bNote').val() || '')
            }, { btn: '#btnSaveBulk', busyLabel: 'Charging…' }).done(function (res) {
                if (!res.success) { ORMS.err(res.message || 'Bulk charge failed'); return; }
                closeModal('#bulkModal');
                ORMS.ok(res.message);
                loadOverview();
            }).fail(function (msg) { ORMS.err(msg); });
        });
    }

    // ---------------- csv template + import ----------------

    function feeCleanHead(s) {
        return String(s || '').trim().toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9_]/g, '_');
    }

    function feeHeaderMatches(head) {
        if (!head || !head.length) return false;
        var hClean = head.map(feeCleanHead);
        var esClean = FEE_CSV_HEAD_ES.map(feeCleanHead);
        var enClean = FEE_CSV_HEAD_EN.map(feeCleanHead);
        if (hClean.length !== esClean.length) return false;
        return (hClean.join('|') === esClean.join('|')) || (hClean.join('|') === enClean.join('|'));
    }

    function feeTemplate() {
        ORMS.downloadCSV('plantilla_importar_pagos.csv', [
            FEE_CSV_HEAD_ES,
            ['STU-2026-0001', 'Cargo',  'Matrícula Periodo 1', '15000', '<?php echo $today; ?>', 'INV-001'],
            ['STU-2026-0001', 'Pago',   'Abono pensión',        '5000',  '<?php echo $today; ?>', 'RCPT-001']
        ]);
        ORMS.ok('Plantilla descargada con éxito');
    }

    document.getElementById('feeCsvInput').addEventListener('change', function () {
        var file = this.files && this.files[0], input = this;
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (ev) {
            input.value = '';
            var parsed = ORMS.parseCSV(String(ev.target.result || ''));
            if (!parsed.length) { ORMS.err('El archivo seleccionado está vacío'); return; }

            var head = parsed[0] || [];
            if (!feeHeaderMatches(head)) {
                ORMS.err('La fila de encabezados debe coincidir con la plantilla:<br><br><b>' + FEE_CSV_HEAD_ES.join(', ') + '</b>', 'Formato CSV incorrecto');
                return;
            }
            impRows = parsed.slice(1).filter(function (r) { return (r || []).join('').trim() !== ''; });
            if (!impRows.length) { ORMS.err('No se encontraron filas con datos debajo del encabezado'); return; }
            showPreview();
        };
        reader.readAsText(file);
    });

    // preview first — nothing is written until the confirm button below is clicked
    function showPreview() {
        var html = '';
        impRows.slice(0, 100).forEach(function (r, i) {
            html += '<tr><td>' + (i + 2) + '</td>';
            for (var c = 0; c < 6; c++) html += '<td>' + ORMS.esc(r[c] || '') + '</td>';
            html += '</tr>';
        });
        $('#impBody').html(html);
        $('#impSummary span').html('<b>' + impRows.length + '</b> fila(s) listas para <b>' + ORMS.esc($('#filterYear option:selected').text()) + '</b>' +
            (impRows.length > 100 ? ' — las primeras 100 se muestran en la vista previa a continuación.' : '.') +
            ' Los números de matrícula desconocidos y las filas con errores serán omitidas y reportadas.');
        $('#btnDoImport').html('<i class="fas fa-file-import"></i> Importar ' + impRows.length + ' fila(s)');
        $('#importModal').addClass('active');
    }

    function doImport() {
        if (!impRows.length) return;
        ORMS.post('bulkImportFees', { year_id: yearId(), rows: JSON.stringify(impRows) },
                  { btn: '#btnDoImport', busyLabel: 'Importando…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Error en la importación'); return; }
            closeModal('#importModal');
            importReport(res);
            loadOverview();
            Swal.fire({
                icon: res.skipped ? 'warning' : 'success',
                title: res.imported + ' importados, ' + res.skipped + ' omitidos',
                text: res.skipped ? 'Las filas omitidas se detallan en el cuadro sobre la tabla.' : 'Todos los registros se importaron correctamente.'
            });
        }).fail(function (msg) { ORMS.err(msg); });
    }

    function importReport(res) {
        var $box = $('#importResult');
        if (!res.errors || !res.errors.length) {
            $box.html('<div class="info-banner"><i class="fas fa-circle-check"></i> <span>' + res.imported + ' entr(ies) imported, nothing skipped.</span></div>');
            vis('#importResult', true);
            return;
        }
        var html = '<div class="info-banner info-banner-warning"><i class="fas fa-triangle-exclamation"></i> <span>' +
            res.imported + ' imported, ' + res.skipped + ' skipped — the rows below were not saved.</span></div>' +
            '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr><th><i class="fas fa-list-ol"></i> #</th>' +
            '<th><i class="fas fa-circle-exclamation"></i> Reason</th></tr></thead><tbody>';
        res.errors.forEach(function (e, i) { html += '<tr><td>' + (i + 1) + '</td><td>' + ORMS.esc(e) + '</td></tr>'; });
        $box.html(html + '</tbody></table></div>');
        vis('#importResult', true);
    }

    // ==================== fee structures + reminders + challan ====================
    var WA_CC = <?php echo json_encode((string)getSetting('whatsapp_country_code', '92')); ?>;
    var SCHOOL_NAME = <?php
        $chName = '';
        try { $chName = sid() ? (string)qVal("SELECT name FROM schools WHERE id = ?", 'i', sid()) : ''; } catch (Throwable $e) {}
        echo json_encode($chName !== '' ? $chName : getSiteBranding()['site_name']);
    ?>;
    var FS_LOADED = false, FS_ROWS = [];

    function waPhone(p) {
        var d = String(p || '').replace(/\D/g, '');
        if (!d) return '';
        if (d.charAt(0) === '0') d = WA_CC + d.slice(1);
        return d;
    }

    function waFeeLink(r) {
        var msg = 'Dear Guardian, this is ' + SCHOOL_NAME + '. The fee account of ' + r.name + ' (' + r.cls +
                  ') shows an outstanding balance of ' + r.balance_f + '. Kindly clear it at your earliest convenience. Thank you.';
        return 'https://wa.me/' + waPhone(r.gphone) + '?text=' + encodeURIComponent(msg);
    }

    function mailReminder(id, btn) {
        var r = rowOf(id);
        if (!r) return;
        ORMS.post('emailFeeReminder', { student_id: id, year_id: yearId() }, { btn: btn, busyLabel: ' ', verb: 'Sending…' })
            .done(function (res) { res.success ? ORMS.ok(res.message) : ORMS.err(res.message); })
            .fail(function (m) { ORMS.err(m); });
    }

    // challan: its own print document — the slip carries its styles inline, the app page stays untouched
    function printChallan(id) {
        var r = rowOf(id);
        if (!r) return;
        var today = new Date().toISOString().slice(0, 10);
        var h = '<!DOCTYPE html><html><head><title>Fee Challan - ' + ORMS.esc(r.adm) + '</title><style>' +
            'body{font-family:Arial,sans-serif;margin:0;padding:24px;color:#222}' +
            '.ch{max-width:420px;margin:0 auto;border:2px solid #001f3f}' +
            '.ch-head{background:#001f3f;color:#fff;text-align:center;padding:14px}' +
            '.ch-head h2{margin:0;font-size:18px}.ch-head small{opacity:.85}' +
            'table{width:100%;border-collapse:collapse;font-size:13px}' +
            'td,th{padding:7px 12px;border-bottom:1px solid #e5e5e5;text-align:left}' +
            'th{background:#f5f7fa;width:42%;color:#555;font-weight:600}' +
            '.ch-bal{background:#f5f7fa;text-align:center;padding:14px}' +
            '.ch-bal b{font-size:22px;color:#001f3f}' +
            '.ch-foot{padding:10px 12px;font-size:11px;color:#777;text-align:center}' +
            '@media print{body{padding:0}}' +
            '</style></head><body><div class="ch">' +
            '<div class="ch-head"><h2>' + ORMS.esc(SCHOOL_NAME) + '</h2><small>FEE CHALLAN &mdash; ' + today + '</small></div>' +
            '<table>' +
            '<tr><th>Student</th><td>' + ORMS.esc(r.name) + '</td></tr>' +
            '<tr><th>Admission No</th><td>' + ORMS.esc(r.adm) + '</td></tr>' +
            '<tr><th>Class</th><td>' + ORMS.esc(r.cls) + (r.roll ? ' &middot; Roll ' + ORMS.esc(r.roll) : '') + '</td></tr>' +
            '<tr><th>Total Charged</th><td>' + ORMS.esc(r.charged_f) + '</td></tr>' +
            '<tr><th>Total Paid</th><td>' + ORMS.esc(r.paid_f) + '</td></tr>' +
            '</table>' +
            '<div class="ch-bal">Balance Due<br><b>' + ORMS.esc(r.balance_f) + '</b></div>' +
            '<div class="ch-foot">Please pay at the school office and keep the receipt. If already paid, kindly disregard.</div>' +
            '</div><script>window.print();<\/script></body></html>';
        var w = window.open('', '_blank');
        if (!w) { ORMS.err('Allow popups to print the challan'); return; }
        w.document.write(h);
        w.document.close();
    }

    // ---------------- structures crud ----------------
    function loadStructures(btn) {
        ORMS.post('getStructures', {}, { btn: btn, busyLabel: 'Loading…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load structures'); return; }
            FS_LOADED = true;
            FS_ROWS = res.data || [];
            renderFs();
        }).fail(function (m) { ORMS.err(m); });
    }

    function renderFs() {
        vis('#fsEmpty', !FS_ROWS.length);
        vis('#fsWrap', !!FS_ROWS.length);
        var h = '';
        FS_ROWS.forEach(function (r) {
            h += '<tr><td>' + ORMS.esc(r.class_name) + '</td>' +
                 '<td><strong>' + ORMS.esc(r.name) + '</strong></td>' +
                 '<td>' + ORMS.esc(r.amount_f) + '</td>' +
                 '<td><span class="subject-chip"><i class="fas ' + (r.frequency === 'Monthly' ? 'fa-arrows-rotate' : 'fa-1') + '"></i> ' + ORMS.esc(r.frequency) + '</span></td>' +
                 '<td>' + (r.is_active == 1
                     ? '<span class="status-badge status-active"><i class="fas fa-check"></i> Active</span>'
                     : '<span class="status-badge status-inactive"><i class="fas fa-ban"></i> Inactive</span>') + '</td>' +
                 '<td>' +
                 (CAN.e ? '<button class="action-icon" title="Edit" onclick="editFs(' + r.id + ')"><i class="fas fa-edit"></i></button>' +
                          '<button class="action-icon" title="' + (r.is_active == 1 ? 'Deactivate' : 'Activate') + '" onclick="toggleFs(' + r.id + ', this)"><i class="fas fa-toggle-' + (r.is_active == 1 ? 'on' : 'off') + '"></i></button>' : '') +
                 (CAN.d ? '<button class="action-icon delete-icon" title="Delete" onclick="delFs(' + r.id + ', this)"><i class="fas fa-trash"></i></button>' : '') +
                 '</td></tr>';
        });
        $('#fsBody').html(h);
    }

    function fsRow(id) { return FS_ROWS.filter(function (x) { return x.id == id; })[0]; }

    function fsClassOptions() {
        // the balances filter already carries this school's class list — reuse it
        var h = '<option value="">Select Class</option>';
        $('#filterClass option').each(function () { if (this.value) h += '<option value="' + this.value + '">' + $(this).text() + '</option>'; });
        $('#fsClass').html(h);
        ORMS.dropdown.refresh('#fsClass');
    }

    function openFs() {
        $('#fsTitle').html('<i class="fas fa-list-check"></i> Add Fee Structure');
        $('#fsForm')[0].reset();
        $('#fsId').val('');
        $('#fsActive').prop('checked', true);
        fsClassOptions();
        ORMS.dropdown.refresh('#fsFreq');
        $('#fsModal').addClass('active');
    }

    function editFs(id) {
        var r = fsRow(id);
        if (!r) return;
        $('#fsTitle').html('<i class="fas fa-edit"></i> Edit Fee Structure');
        $('#fsId').val(r.id);
        fsClassOptions();
        $('#fsClass').val(r.class_id); ORMS.dropdown.refresh('#fsClass');
        $('#fsName').val(r.name);
        $('#fsAmount').val(r.amount);
        $('#fsFreq').val(r.frequency); ORMS.dropdown.refresh('#fsFreq');
        $('#fsActive').prop('checked', r.is_active == 1);
        $('#fsModal').addClass('active');
    }

    function toggleFs(id, btn) {
        ORMS.post('toggleStructure', { id: id }, { btn: btn, busyLabel: ' ' }).done(function (res) {
            res.success ? (ORMS.ok(res.message), loadStructures()) : ORMS.err(res.message);
        }).fail(function (m) { ORMS.err(m); });
    }

    function delFs(id, btn) {
        var r = fsRow(id);
        ORMS.confirmDelete('Delete "' + (r ? r.name : '') + '"? Charges it already raised stay in the ledgers.').then(function (yes) {
            if (!yes) return;
            ORMS.post('deleteStructure', { id: id }, { btn: btn, busyLabel: ' ' }).done(function (res) {
                res.success ? (ORMS.ok(res.message), loadStructures()) : ORMS.err(res.message);
            }).fail(function (m) { ORMS.err(m); });
        });
    }

    function openGen() {
        if (!FS_LOADED) loadStructures();
        $('#genModal').addClass('active');
    }

    function runGen(btn) {
        var ym = $('#genMonth').val();
        if (!ym) { ORMS.err('Pick a month first'); return; }
        ORMS.post('generateCharges', { ym: ym, year_id: yearId() }, { btn: btn, busyLabel: 'Generating…' }).done(function (res) {
            if (!res.success) { ORMS.err(res.message); return; }
            closeModal('#genModal');
            Swal.fire({ icon: res.added > 0 ? 'success' : 'info', title: res.message });
            loadOverview();
        }).fail(function (m) { ORMS.err(m); });
    }

    $(function () {
        ORMS.dropdown('#fsClass');
        ORMS.dropdown('#fsFreq');
        $('#fsModal, #genModal').on('click', function (e) { if (e.target === this) closeModal(this); });
        $('#fsForm').on('submit', function (e) {
            e.preventDefault();
            ORMS.post('saveStructure', {
                id: $('#fsId').val(), class_id: $('#fsClass').val(), name: $('#fsName').val().trim(),
                amount: $('#fsAmount').val(), frequency: $('#fsFreq').val(),
                is_active: $('#fsActive').is(':checked') ? 1 : 0
            }, { btn: '#btnSaveFs', busyLabel: 'Saving…' }).done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                closeModal('#fsModal');
                ORMS.ok(res.message);
                loadStructures();
            }).fail(function (m) { ORMS.err(m); });
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
