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
requirePerm('students', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'students';

// ============================================
// Helpers
// ============================================

function stuStatuses(): array {
    return ['Active', 'Inactive', 'Passed Out', 'Transferred'];
}

function stuGenders(): array {
    return ['Male', 'Female', 'Other'];
}

function stuBloodGroups(): array {
    return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
}

// statuses that end the enrolment — only these carry leaving details
function stuLeaverStatuses(): array {
    return ['Transferred', 'Passed Out'];
}

// "o positive" / "b -ve" / "AB+" -> canonical group. '' = not a blood group
function stuNormBlood(string $b): string {
    $s = preg_replace('/[^A-Z+\-]/', '', strtoupper($b));                 // drop spaces, dots, slashes
    $s = str_replace(['POSITIVE', 'POS', 'NEGATIVE', 'NEG', 'VE'], ['+', '+', '-', '-', ''], $s);
    return in_array($s, stuBloodGroups(), true) ? $s : '';
}

// tenant cols there yet? one probe per request. 0 = pre-migration (single school), 1 = school_id, 2 = + branch_id
function stuTenant(): int {
    static $v = null;
    if ($v !== null) return $v;
    $v = 0;
    try { qVal("SELECT school_id FROM students LIMIT 1"); qVal("SELECT school_id FROM users LIMIT 1"); $v = 1; }
    catch (Throwable $e) { return $v; }
    try { qVal("SELECT branch_id FROM students LIMIT 1"); $v = 2; } catch (Throwable $e) {}
    return $v;
}

// id off the request -> verified id, 0 = another school's. pre-migration db has no school_id, so the id stands
function stuOwn(string $table, $id): int {
    return stuTenant() ? ormsOwns($table, $id) : (int)$id;
}

// " AND school_id = ?" (+ branch for a pinned branch admin) and its binds — one rail for every
// per-school lookup and uniqueness probe. binds by reference like stuScopeAnd, so it must be
// concatenated INTO the sql string before the types/params are passed on
function stuSchoolAnd(string &$types, array &$params, string $alias = '', bool $branch = false): string {
    if (!stuTenant()) return '';
    $a = $alias !== '' ? $alias . '.' : '';
    $types .= 'i';
    $params[] = sid();
    $lock = ($branch && stuTenant() >= 2) ? ormsBranchLock() : 0;
    if (!$lock) return " AND {$a}school_id = ?";
    $types .= 'i';
    $params[] = $lock;
    return " AND {$a}school_id = ? AND {$a}branch_id = ?";
}

// tenant columns for an INSERT: [cols, placeholders, types, params] — empty strings pre-migration
function stuStamp(?int $branch): array {
    $t = stuTenant();
    if (!$t)      return ['', '', '', []];
    if ($t < 2)   return [', school_id', ', ?', 'i', [sid()]];
    return [', school_id, branch_id', ', ?, ?', 'ii', [sid(), $branch]];
}

// branch a new student lands in — a branch admin is pinned, otherwise the class carries it
function stuBranch(int $classId): ?int {
    if (stuTenant() < 2) return null;
    if ($lock = ormsBranchLock()) return $lock;
    try { $b = qVal("SELECT branch_id FROM classes WHERE id = ?", 'i', $classId); }
    catch (Throwable $e) { $b = null; }
    return $b !== null ? (int)$b : (bid() ?: null);
}

/**
 * Row-level scope — goes into the WHERE clause, never into the ui.
 * Identity decides, NEVER permission bits: the school-wide roles (ormsSchoolWideRoles() —
 * Admin, Principal, Branch Admin) see every student OF THIS SCHOOL, a teacher only the sections
 * they hold for the CURRENT year, anybody else nothing at all. Ticking "Edit" for a role must
 * never widen its rows. Note a Principal has no teachers row on purpose, so the identity gate —
 * not teacher_subjects — is what has to know about them.
 * There is NO unfiltered branch: "school-wide" is the whole of THIS school, never the whole table.
 */
function stuScope(int $userId): array {
    $ten = stuTenant();

    if (ormsSchoolWide()) {
        if (!$ten) return ['sql' => '1 = 1', 'types' => '', 'params' => []];       // pre-migration db — one school only
        $lock = $ten >= 2 ? ormsBranchLock() : 0;
        return $lock
            ? ['sql' => 's.school_id = ? AND s.branch_id = ?', 'types' => 'ii', 'params' => [sid(), $lock]]
            : ['sql' => 's.school_id = ?',                     'types' => 'i',  'params' => [sid()]];
    }

    $tid = ormsTeacherId($userId);
    if ($tid === null) return ['sql' => '0 = 1', 'types' => '', 'params' => []];   // no teacher row -> deny all

    $yr  = (int)(ormsCurrentYear()['id'] ?? 0);
    $mine = 's.section_id IN (SELECT ts.section_id FROM teacher_subjects ts WHERE ts.teacher_id = ? AND ts.academic_year_id = ?)';
    // school first: a teacher_subjects row pointing at a foreign section still can't reach that school's children
    return $ten
        ? ['sql' => 's.school_id = ? AND ' . $mine, 'types' => 'iii', 'params' => [sid(), $tid, $yr]]
        : ['sql' => $mine,                          'types' => 'ii',  'params' => [$tid, $yr]];
}

// glue the scope onto a WHERE that already has a condition — one rail for every student query
function stuScopeAnd(int $userId, string &$types, array &$params): string {
    $sc = stuScope($userId);
    if ($sc['sql'] === '') return '';
    $types .= $sc['types'];
    $params = array_merge($params, $sc['params']);
    return ' AND ' . $sc['sql'];
}

// admission prefix from settings, letters/digits only
function stuPrefix(): string {
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) getSetting('admission_no_prefix', 'STU')));
    return $p !== '' ? substr($p, 0, 10) : 'STU';
}

// year part of the admission no — last 4-digit group of the year name (2025-2026 -> 2026)
function stuYearTag(?array $yr): string {
    if ($yr && preg_match_all('/\d{4}/', (string)($yr['name'] ?? ''), $m) && $m[0]) return end($m[0]);
    return date('Y');
}

// next free sequence for this prefix+year. called INSIDE the txn, duplicate key = retry.
// admission_no is unique PER SCHOOL, so the MAX must be too — a shared max would burn numbers for everyone
function stuNextAdmissionNo(string $prefix, string $tag): string {
    $t = 's';
    $p = [$prefix . '-' . $tag . '-%'];
    $seq = (int) qVal("SELECT MAX(CAST(SUBSTRING_INDEX(admission_no, '-', -1) AS UNSIGNED)) FROM students WHERE admission_no LIKE ?"
                      . stuSchoolAnd($t, $p), $t, ...$p);
    return sprintf('%s-%s-%04d', $prefix, $tag, $seq + 1);
}

// real calendar date, y-m-d or d/m/y or d-m-y normalized to y-m-d
function stuNormDate(string $d): ?string {
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

function stuValidDate(string $d): bool {
    return stuNormDate($d) !== null;
}

// dob must be a sane past date — nobody born tomorrow, nobody from 1899
function stuValidDob(string $d): bool {
    $norm = stuNormDate($d);
    if ($norm === null) return false;
    $ts = strtotime($norm);
    return $ts >= strtotime('1900-01-01') && $ts <= strtotime('-2 years');
}

// section really belongs to the class — AND both belong to us. "belongs to the class" alone passes
// cleanly for another school's pair, so the resolvers run first
function stuSectionOk(int $classId, int $sectionId): bool {
    if (!stuOwn('classes', $classId) || !stuOwn('sections', $sectionId)) return false;
    return (bool) qVal("SELECT id FROM sections WHERE id = ? AND class_id = ? LIMIT 1", 'ii', $sectionId, $classId);
}

// a scoped teacher may only PLACE a student in a section they actually hold this year.
// stuSectionOk()/stuOwn() only prove the pair belongs to this school+branch — neither is a
// per-teacher check, so without this an add/edit/promote post could drop a child into any
// section in the school. same predicate stuScope() uses for reads, kept deliberately identical.
function stuSectionInScope(int $sectionId): bool {
    if (ormsSchoolWide()) return true;
    $tid = ormsTeacherId((int)($_SESSION['user_id'] ?? 0));
    if ($tid === null) return false;
    $yr = (int)(ormsCurrentYear()['id'] ?? 0);
    return (bool) qVal("SELECT ts.id FROM teacher_subjects ts
                        WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.academic_year_id = ? LIMIT 1",
                       'iii', $tid, $sectionId, $yr);
}

// mime + getimagesize + 2mb cap + random name. returns relative path
function stuUploadPhoto(array $f): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Photo upload failed. Please try again.');
    if (($f['size'] ?? 0) > 10 * 1024 * 1024)                  throw new RuntimeException('La foto debe ser menor a 10MB');
    if (!is_uploaded_file($f['tmp_name']))                      throw new RuntimeException('Invalid photo upload');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $f['tmp_name']);
    finfo_close($fi);
    if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG or WEBP photos are allowed');

    $sz = @getimagesize($f['tmp_name']);                        // mime can be spoofed, image header cannot
    if (!$sz || !in_array($sz[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('That file is not a real image');
    }

    $rel = stuPhotoDir();
    $dir = __DIR__ . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Upload folder is not writable');

    $name = 'stu_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) throw new RuntimeException('Could not save the photo');
    return $rel . $name;
}

// one folder per school — a directory listing of one tenant never shows another's faces.
// pre-migration installs keep writing to the flat folder
function stuPhotoDir(): string {
    return stuTenant() ? 'uploads/' . sid() . '/profiles/' : 'uploads/profiles/';
}

// only ever unlink inside our own upload tree — shape check + resolved path must stay under uploads/
function stuDropPhoto(?string $p): void {
    if (!$p || !preg_match('#^uploads/(\d+/)?profiles/[A-Za-z0-9_.\-]+$#', $p)) return;
    $base = realpath(__DIR__ . '/uploads');
    $real = realpath(__DIR__ . '/' . $p);
    if ($base && $real && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0 && is_file($real)) @unlink($real);
}

// shared form reader + validator. throws RuntimeException with a friendly message
function stuForm(): array {
    $o = [
        'full_name'        => trim($_POST['full_name'] ?? ''),
        'father_name'      => trim($_POST['father_name'] ?? ''),
        'dob'              => trim($_POST['dob'] ?? ''),
        'gender'           => trim($_POST['gender'] ?? ''),
        'guardian_phone'   => trim($_POST['guardian_phone'] ?? ''),
        'address'          => trim($_POST['address'] ?? ''),
        'roll_no'          => trim($_POST['roll_no'] ?? ''),
        'admission_date'   => trim($_POST['admission_date'] ?? ''),
        'class_id'         => (int)($_POST['class_id'] ?? 0),
        'section_id'       => (int)($_POST['section_id'] ?? 0),
        'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
        'status'           => trim($_POST['status'] ?? 'Active'),
        'mother_name'      => trim($_POST['mother_name'] ?? ''),
        'guardian_name'    => trim($_POST['guardian_name'] ?? ''),
        'guardian_email'   => trim($_POST['guardian_email'] ?? ''),
        'national_id'      => trim($_POST['national_id'] ?? ''),
        'blood_group'      => trim($_POST['blood_group'] ?? ''),
        'previous_school'  => trim($_POST['previous_school'] ?? ''),
        'remarks'          => trim($_POST['remarks'] ?? ''),
        'date_of_leaving'  => trim($_POST['date_of_leaving'] ?? ''),
        'leaving_reason'   => trim($_POST['leaving_reason'] ?? '')
    ];

    if ($o['full_name'] === '')            throw new RuntimeException('Student name is required');
    if (mb_strlen($o['full_name']) > 100)  throw new RuntimeException('Student name is too long (max 100 characters)');
    if ($o['class_id'] <= 0 || $o['section_id'] <= 0) throw new RuntimeException('Class and section are required');
    if (!stuSectionOk($o['class_id'], $o['section_id']))  throw new RuntimeException('That section does not belong to the selected class');
    if (!stuSectionInScope($o['section_id']))            throw new RuntimeException('You can only place a student in a section you are assigned to');
    // year resolved through the tenant rail — another school's year id must never reach the insert
    if (!stuOwn('academic_years', $o['academic_year_id'])
        || !qVal("SELECT id FROM academic_years WHERE id = ?", 'i', $o['academic_year_id'])) {
        throw new RuntimeException('Please pick a valid academic year');
    }
    // Normalize status and gender (bilingual support)
    $stMap = [
        'active' => 'Active', 'activo' => 'Active', 'activa' => 'Active',
        'inactive' => 'Inactive', 'inactivo' => 'Inactive', 'inactiva' => 'Inactive',
        'passed out' => 'Passed Out', 'graduado' => 'Passed Out', 'egresado' => 'Passed Out', 'aprobado' => 'Passed Out',
        'transferred' => 'Transferred', 'transferido' => 'Transferred', 'trasladado' => 'Transferred'
    ];
    $stKey = mb_strtolower($o['status']);
    if (isset($stMap[$stKey])) $o['status'] = $stMap[$stKey];

    $gdMap = [
        'male' => 'Male', 'm' => 'Male', 'masculino' => 'Male', 'hombre' => 'Male', 'varon' => 'Male',
        'female' => 'Female', 'f' => 'Female', 'femenino' => 'Female', 'mujer' => 'Female',
        'other' => 'Other', 'otro' => 'Other', 'otra' => 'Other', 'o' => 'Other'
    ];
    $gdKey = mb_strtolower($o['gender']);
    if (isset($gdMap[$gdKey])) $o['gender'] = $gdMap[$gdKey];

    if ($o['dob'] !== '') {
        $nd = stuNormDate($o['dob']);
        if (!$nd || !stuValidDob($nd)) throw new RuntimeException('La fecha de nacimiento debe ser una fecha pasada válida (AAAA-MM-DD o DD/MM/AAAA)');
        $o['dob'] = $nd;
    }
    if ($o['admission_date'] !== '') {
        $nd = stuNormDate($o['admission_date']);
        if (!$nd) throw new RuntimeException('La fecha de matrícula o admisión no es válida (AAAA-MM-DD o DD/MM/AAAA)');
        $o['admission_date'] = $nd;
    }
    if ($o['gender'] !== '' && !in_array($o['gender'], stuGenders(), true)) throw new RuntimeException('Género no válido');
    if (!in_array($o['status'], stuStatuses(), true))                throw new RuntimeException('Estado no válido');
    if ($o['roll_no'] !== '' && mb_strlen($o['roll_no']) > 20)       throw new RuntimeException('Roll no is too long (max 20)');
    if ($o['guardian_phone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $o['guardian_phone'])) {
        throw new RuntimeException('Guardian phone looks invalid');
    }
    if (mb_strlen($o['address']) > 255)     throw new RuntimeException('Address is too long (max 255)');
    if (mb_strlen($o['father_name']) > 100) throw new RuntimeException("Father's name is too long (max 100)");

    if ($o['guardian_email'] !== '' && !filter_var($o['guardian_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Guardian email is not a valid email address');
    }
    if ($o['blood_group'] !== '' && ($o['blood_group'] = stuNormBlood($o['blood_group'])) === '') {
        throw new RuntimeException('Blood group must be one of ' . implode(', ', stuBloodGroups()));
    }
    if ($o['date_of_leaving'] !== '') {
        $nd = stuNormDate($o['date_of_leaving']);
        if (!$nd) throw new RuntimeException('La fecha de retiro no es válida (AAAA-MM-DD o DD/MM/AAAA)');
        $o['date_of_leaving'] = $nd;
    }

    // caps mirror the column widths — never let the driver silently truncate
    foreach (['mother_name' => 100, 'guardian_name' => 100, 'guardian_email' => 100, 'national_id' => 30,
              'previous_school' => 150, 'leaving_reason' => 255, 'remarks' => 255] as $k => $max) {
        if (mb_strlen($o[$k]) > $max) {
            throw new RuntimeException(ucwords(str_replace('_', ' ', $k)) . " is too long (max $max characters)");
        }
    }

    // leaving details belong to a leaver only — back to Active/Inactive wipes them
    if (!in_array($o['status'], stuLeaverStatuses(), true)) $o['date_of_leaving'] = $o['leaving_reason'] = '';

    // blanks -> null so DATE/ENUM columns stay clean under strict mode
    foreach (['dob', 'gender', 'admission_date', 'roll_no', 'father_name', 'address', 'guardian_phone',
              'mother_name', 'guardian_name', 'guardian_email', 'national_id', 'blood_group',
              'previous_school', 'remarks', 'date_of_leaving', 'leaving_reason'] as $k) {
        if ($o[$k] === '') $o[$k] = null;
    }
    return $o;
}

// slip payload — everything the printable credential slip needs
function stuCredentials(string $adm, string $name, string $plainPwd): array {
    return [
        'school'      => (string) getSetting('result_school_name', getSiteBranding()['site_name']),
        'name'        => $name,
        'admission_no' => $adm,
        'username'    => $adm,
        'password'    => $plainPwd
    ];
}

// ============================================
// AJAX
$action = $_GET['action'] ?? ($_POST['action'] ?? null);
if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($action) {

            // ---------------- roster ----------------
            case 'getStudents': {
                $t = '';
                $p = [];
                $w = ['1 = 1' . stuScopeAnd($user_id, $t, $p)];      // scope binds first, filters after

                foreach (['year' => 's.academic_year_id', 'class' => 's.class_id', 'section' => 's.section_id'] as $k => $col) {
                    $v = (int)($_POST[$k] ?? 0);
                    if ($v > 0) { $w[] = "$col = ?"; $t .= 'i'; $p[] = $v; }
                }
                $st = trim($_POST['status'] ?? '');
                if ($st !== '' && in_array($st, stuStatuses(), true)) { $w[] = 's.status = ?'; $t .= 's'; $p[] = $st; }
                $gd = trim($_POST['gender'] ?? '');
                if ($gd !== '' && in_array($gd, stuGenders(), true)) { $w[] = 's.gender = ?'; $t .= 's'; $p[] = $gd; }

                $rows = qAll(
                    "SELECT s.id, s.user_id, s.admission_no, s.roll_no, s.class_id, s.section_id, s.academic_year_id,
                            s.father_name, s.dob, s.gender, s.guardian_phone, s.address, s.admission_date, s.status,
                            s.mother_name, s.guardian_name, s.guardian_email, s.national_id, s.blood_group,
                            s.previous_school, s.remarks, s.date_of_leaving, s.leaving_reason,
                            u.username, u.full_name, u.profile_image AS photo, u.is_active,
                            c.name AS class_name, sec.name AS section_name, ay.name AS year_name
                     FROM students s
                     JOIN users u          ON u.id   = s.user_id
                     JOIN classes c        ON c.id   = s.class_id
                     JOIN sections sec     ON sec.id = s.section_id
                     JOIN academic_years ay ON ay.id = s.academic_year_id
                     WHERE " . implode(' AND ', $w) . "
                     ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, CAST(s.roll_no AS UNSIGNED) ASC, s.admission_no ASC",
                    $t, ...$p);

                jsonOk(['data' => $rows]);
            }

            case 'getSections': {
                $cid = stuOwn('classes', $_POST['class_id'] ?? 0);   // foreign class -> empty list, no probe value
                jsonOk(['data' => $cid > 0
                    ? qAll("SELECT id, name FROM sections WHERE class_id = ? AND is_active = 1 ORDER BY name ASC", 'i', $cid)
                    : []]);
            }

            case 'getStudent': {
                $id  = (int)($_POST['id'] ?? 0);
                $t   = 'i';
                $p   = [$id];
                $and = stuScopeAnd($user_id, $t, $p);

                $row = qOne(
                    "SELECT s.*, u.username, u.full_name, u.profile_image AS photo, u.is_active, u.email,
                            c.name AS class_name, sec.name AS section_name, ay.name AS year_name,
                            cb.full_name AS created_by_name, ub.full_name AS updated_by_name
                     FROM students s
                     JOIN users u          ON u.id   = s.user_id
                     JOIN classes c        ON c.id   = s.class_id
                     JOIN sections sec     ON sec.id = s.section_id
                     JOIN academic_years ay ON ay.id = s.academic_year_id
                     LEFT JOIN users cb    ON cb.id  = s.created_by
                     LEFT JOIN users ub    ON ub.id  = s.updated_by
                     WHERE s.id = ?" . $and, $t, ...$p);

                if (!$row) jsonErr('Student not found');
                $row['marks_count'] = (int) qVal("SELECT COUNT(*) FROM marks WHERE student_id = ?", 'i', $id);

                // ---- 360 view blocks ----
                // the row scope above already decided WHICH student may be opened. these gates decide
                // WHAT of them this caller may read: a teacher who legitimately sees the child must
                // still not learn the family's fee balance without the fees bit.
                $yid = (int)$row['academic_year_id'];
                $s360 = ['can' => ['results'    => can('results', 'v')    ? 1 : 0,
                                   'attendance' => can('attendance', 'v') ? 1 : 0,
                                   'fees'       => can('fees', 'v')       ? 1 : 0],
                         'results' => [], 'attendance' => [], 'fees' => null, 'subjects' => []];

                // term-wise summaries — read the frozen snapshots, never recompute here
                if ($s360['can']['results']) {
                    try {
                        $s360['results'] = qAll(
                            "SELECT rs.term_id, t.name AS term_name, y.name AS year_name,
                                    rs.total_obtained, rs.total_max, rs.percentage, rs.grade, rs.gpa,
                                    rs.`position`, rs.section_total, rs.result_status, rs.failed_subjects,
                                    COALESCE(rp.is_published, 0) AS is_published
                             FROM result_summaries rs
                             JOIN exam_terms t     ON t.id = rs.term_id
                             JOIN academic_years y ON y.id = rs.academic_year_id
                             LEFT JOIN result_publications rp
                                    ON rp.term_id = rs.term_id AND rp.section_id = rs.section_id
                             WHERE rs.student_id = ?
                             ORDER BY y.start_date DESC, y.id DESC, t.sort_order DESC, t.id DESC",
                            'i', $id);
                    } catch (Throwable $e) { $s360['results'] = []; }   // pre-migration db
                }

                if ($s360['can']['attendance']) {
                    try {
                        $s360['attendance'] = qAll(
                            "SELECT a.term_id, t.name AS term_name, y.name AS year_name,
                                    a.days_present, a.days_total, a.remarks
                             FROM attendance_summary a
                             JOIN exam_terms t     ON t.id = a.term_id
                             JOIN academic_years y ON y.id = a.academic_year_id
                             WHERE a.student_id = ?
                             ORDER BY y.start_date DESC, y.id DESC, t.sort_order DESC", 'i', $id);
                    } catch (Throwable $e) { $s360['attendance'] = []; }
                }

                if ($s360['can']['fees']) {
                    try {
                        $bal = function_exists('ormsFeeBalance') ? ormsFeeBalance($id, $yid ?: null) : 0.0;
                        $s360['fees'] = [
                            // year-scoped, same as the withhold stamp — a lifetime figure beside a
                            // year-scoped hold reason is what confused the result card before
                            'balance'     => $bal,
                            'balance_txt' => function_exists('ormsMoney') ? ormsMoney($bal) : number_format($bal, 2),
                            'hold'        => (int)($row['fee_hold'] ?? 0),
                            'hold_note'   => (string)($row['fee_hold_note'] ?? ''),
                            'rows'        => qAll(
                                "SELECT f.entry_type, f.description, f.amount, f.entry_date, f.reference,
                                        y.name AS year_name
                                 FROM student_fees f
                                 JOIN academic_years y ON y.id = f.academic_year_id
                                 WHERE f.student_id = ?
                                 ORDER BY f.entry_date DESC, f.id DESC LIMIT 100", 'i', $id)];
                        // format once here — the ledger must not reinvent currency handling in js
                        foreach ($s360['fees']['rows'] as &$fr) {
                            $fr['amount_txt'] = function_exists('ormsMoney')
                                ? ormsMoney((float)$fr['amount']) : number_format((float)$fr['amount'], 2);
                        }
                        unset($fr);
                    } catch (Throwable $e) { $s360['fees'] = null; }
                }

                // what this child actually sits: every class subject, with the elective opt-in resolved
                try {
                    $s360['subjects'] = qAll(
                        "SELECT sub.name, sub.code, sub.subject_type, cs.is_optional,
                                cs.total_marks, cs.passing_marks,
                                (cs.include_in_total = 1 AND sub.include_in_total = 1) AS counted,
                                (ss.id IS NOT NULL) AS enrolled
                         FROM class_subjects cs
                         JOIN subjects sub ON sub.id = cs.subject_id
                         LEFT JOIN student_subjects ss ON ss.student_id = ? AND ss.subject_id = cs.subject_id
                                                      AND ss.academic_year_id = ?
                         WHERE cs.class_id = ?
                         ORDER BY cs.sort_order ASC, sub.name ASC",
                        'iii', $id, $yid, (int)$row['class_id']);
                } catch (Throwable $e) { $s360['subjects'] = []; }

                jsonOk(['data' => $row, 's360' => $s360]);
            }

            // ---------------- register (users + students, ONE txn) ----------------
            case 'addStudent': {
                requireCsrfJson();
                requirePermJson('students', 'a');
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Invalid request method');
                ormsQuotaGuard('students');                     // plan seat check, before any work

                $f = stuForm();

                // roll must be free inside this section + year, this school
                $rt = 'iis';
                $rp = [$f['section_id'], $f['academic_year_id'], $f['roll_no']];
                if ($f['roll_no'] !== null && qVal("SELECT id FROM students WHERE section_id = ? AND academic_year_id = ? AND roll_no = ?"
                        . stuSchoolAnd($rt, $rp) . " LIMIT 1", $rt, ...$rp)) {
                    jsonErr('Roll no ' . $f['roll_no'] . ' is already used in this section for the selected year');
                }

                $photo = !empty($_FILES['photo']['name']) ? stuUploadPhoto($_FILES['photo']) : null;

                $yrRow  = qOne("SELECT * FROM academic_years WHERE id = ?", 'i', $f['academic_year_id']);
                $prefix = stuPrefix();
                $tag    = stuYearTag($yrRow);
                $plain  = (string) getSetting('student_default_password', 'student123');
                $hash   = password_hash($plain, PASSWORD_DEFAULT);

                // both rows carry the tenant stamp — the class decides the branch
                [$sc, $sv, $st, $sp] = stuStamp(stuBranch($f['class_id']));

                $conn = getDBConnection();
                $adm  = '';
                for ($try = 1; ; $try++) {
                    $conn->begin_transaction();
                    try {
                        // locked re-check: two admins clicking Add in the same second both clear an
                        // unlocked count, and the tier is oversold by one
                        if (ormsQuotaRoomLocked('students', 1) < 1) { $conn->rollback(); jsonErr(ormsQuotaMessage(ormsQuota('students')), ['quota_full' => true]); }
                        $adm   = stuNextAdmissionNo($prefix, $tag);          // sequence read inside the txn
                        $email = strtolower($adm) . '@example.com';
                        // admin-created logins are pre-verified — students have no mailbox on file
                        // created_by is the session user, never the request
                        $uid   = qInsert(
                            // must_change_password: every student starts on the shared default, force a change at first login
                            "INSERT INTO users (username, full_name, email, phone, password, role, is_active, email_verified, must_change_password, profile_image, created_by$sc)
                             VALUES (?, ?, ?, ?, ?, 'Student', 1, 1, 1, ?, ?$sv)",
                            'ssssssi' . $st, $adm, $f['full_name'], $email, $f['guardian_phone'], $hash, $photo, $user_id, ...$sp);

                        qInsert(
                            "INSERT INTO students (user_id, admission_no, roll_no, class_id, section_id, academic_year_id,
                                                   father_name, dob, gender, guardian_phone, address, admission_date, status,
                                                   mother_name, guardian_name, guardian_email, national_id, blood_group,
                                                   previous_school, remarks, date_of_leaving, leaving_reason, created_by$sc)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?$sv)",
                            'issiiissssssssssssssssi' . $st, $uid, $adm, $f['roll_no'], $f['class_id'], $f['section_id'], $f['academic_year_id'],
                            $f['father_name'], $f['dob'], $f['gender'], $f['guardian_phone'], $f['address'], $f['admission_date'], $f['status'],
                            $f['mother_name'], $f['guardian_name'], $f['guardian_email'], $f['national_id'], $f['blood_group'],
                            $f['previous_school'], $f['remarks'], $f['date_of_leaving'], $f['leaving_reason'], $user_id, ...$sp);

                        $conn->commit();
                        break;
                    } catch (mysqli_sql_exception $e) {
                        $conn->rollback();                                   // no orphan login ever survives
                        if ((int)$e->getCode() === 1062 && $try < 6) continue;  // someone grabbed that number first
                        stuDropPhoto($photo);
                        throw $e;
                    }
                }

                logActivity($user_id, $username, 'Student Registered', "Registered student: {$f['full_name']} ($adm)");
                try {
                    createNotificationForAdmins('Student Registered',
                        'Student "' . htmlspecialchars($f['full_name']) . '" (' . $adm . ') was registered.', 'success', 'students.php');
                } catch (Exception $e) {}

                // login mail goes to the GUARDIAN's mailbox — the student's own users.email is a placeholder
                $mailNote = '';
                try {
                    $gm = (string) ($f['guardian_email'] ?? '');
                    $m  = sendCredentialsEmail($gm, $f['full_name'], 'Student / Parent', $adm, $plain);
                    $mailNote = $m['success'] ? ' Credentials emailed to ' . $gm . '.'
                              : ($gm !== '' ? ' (Email not sent: ' . $m['message'] . ')' : '');
                } catch (Throwable $e) { error_log('students.php cred mail: ' . $e->getMessage()); }

                jsonOk(['message' => 'Student registered successfully.' . $mailNote, 'credentials' => stuCredentials($adm, $f['full_name'], $plain)]);
            }

            // ---------------- edit ----------------
            case 'updateStudent': {
                requireCsrfJson();
                requirePermJson('students', 'e');
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Invalid request method');

                $id = (int)($_POST['id'] ?? 0);
                $t  = 'i';
                $p  = [$id];
                $cur = qOne("SELECT s.id, s.user_id, s.admission_no, u.profile_image FROM students s JOIN users u ON u.id = s.user_id
                             WHERE s.id = ?" . stuScopeAnd($user_id, $t, $p), $t, ...$p);
                if (!$cur) jsonErr('Student not found');

                $f = stuForm();
                $rt = 'iisi';
                $rp = [$f['section_id'], $f['academic_year_id'], $f['roll_no'], $id];
                if ($f['roll_no'] !== null && qVal("SELECT id FROM students WHERE section_id = ? AND academic_year_id = ? AND roll_no = ? AND id <> ?"
                        . stuSchoolAnd($rt, $rp) . " LIMIT 1", $rt, ...$rp)) {
                    jsonErr('Roll no ' . $f['roll_no'] . ' is already used in this section for the selected year');
                }

                $photo = !empty($_FILES['photo']['name']) ? stuUploadPhoto($_FILES['photo']) : null;
                $act   = $f['status'] === 'Active' ? 1 : 0;                  // suspended student cannot log in
                $uid   = (int) $cur['user_id'];

                // branch follows the class — moving a child into another branch's class moves the stamp with
                // them. only when we actually resolved one: writing null would wipe a good stamp
                $bSet = $bT = '';
                $bP   = [];
                if (stuTenant() >= 2 && ($br = stuBranch($f['class_id'])) !== null) {
                    $bSet = ', branch_id = ?';
                    $bT   = 'i';
                    $bP   = [$br];
                }

                // every write re-states the school — an id alone is never authority to touch a row.
                // school only, no branch: branch_id is nullable and a null would silently no-op the write
                $wt  = '';
                $wp  = [];
                $own = stuSchoolAnd($wt, $wp);

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    if ($photo) {
                        qExec("UPDATE users SET full_name = ?, phone = ?, profile_image = ?, is_active = ? WHERE id = ?$own",
                              'sssii' . $wt, $f['full_name'], $f['guardian_phone'], $photo, $act, $uid, ...$wp);
                    } else {
                        qExec("UPDATE users SET full_name = ?, phone = ?, is_active = ? WHERE id = ?$own",
                              'ssii' . $wt, $f['full_name'], $f['guardian_phone'], $act, $uid, ...$wp);
                    }
                    qExec(
                        "UPDATE students SET roll_no = ?, class_id = ?, section_id = ?, academic_year_id = ?, father_name = ?,
                                             mother_name = ?, guardian_name = ?, guardian_email = ?, dob = ?, gender = ?,
                                             guardian_phone = ?, address = ?, national_id = ?, blood_group = ?, previous_school = ?,
                                             admission_date = ?, status = ?, date_of_leaving = ?, leaving_reason = ?, remarks = ?$bSet,
                                             updated_by = ?
                         WHERE id = ?$own",
                        'siiissssssssssssssss' . $bT . 'ii' . $wt,
                        $f['roll_no'], $f['class_id'], $f['section_id'], $f['academic_year_id'], $f['father_name'],
                        $f['mother_name'], $f['guardian_name'], $f['guardian_email'], $f['dob'], $f['gender'],
                        $f['guardian_phone'], $f['address'], $f['national_id'], $f['blood_group'], $f['previous_school'],
                        $f['admission_date'], $f['status'], $f['date_of_leaving'], $f['leaving_reason'], $f['remarks'],
                        ...array_merge($bP, [$user_id, $id], $wp));
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    stuDropPhoto($photo);
                    throw $e;
                }

                if ($photo) stuDropPhoto($cur['profile_image']);              // old file only after the txn stuck
                logActivity($user_id, $username, 'Student Updated', "Updated student: {$f['full_name']} ({$cur['admission_no']})");
                jsonOk(['message' => 'Student updated successfully']);
            }

            // ---------------- reset password ----------------
            case 'resetPassword': {
                requireCsrfJson();
                requirePermJson('students', 'e');

                $id = (int)($_POST['id'] ?? 0);
                $t  = 'i';
                $p  = [$id];
                $row = qOne("SELECT s.id, s.admission_no, s.user_id, u.full_name FROM students s JOIN users u ON u.id = s.user_id
                             WHERE s.id = ?" . stuScopeAnd($user_id, $t, $p), $t, ...$p);
                if (!$row) jsonErr('Student not found');

                $plain = (string) getSetting('student_default_password', 'student123');
                $wt = '';
                $wp = [];
                $own = stuSchoolAnd($wt, $wp);                              // the write re-states the school
                qExec("UPDATE users SET password = ? WHERE id = ?$own", 'si' . $wt,
                      password_hash($plain, PASSWORD_DEFAULT), (int)$row['user_id'], ...$wp);

                logActivity($user_id, $username, 'Student Password Reset', "Reset password for {$row['full_name']} ({$row['admission_no']})");
                jsonOk(['message' => 'Password reset to the default', 'credentials' => stuCredentials($row['admission_no'], $row['full_name'], $plain)]);
            }

            // ---------------- status change (this is the soft delete) ----------------
            case 'setStatus': {
                requireCsrfJson();
                requirePermJson('students', 'e');

                $id = (int)($_POST['id'] ?? 0);
                $st = trim($_POST['status'] ?? '');
                if (!in_array($st, stuStatuses(), true)) jsonErr('Invalid status');

                // leaving details only ride along with a leaver — any other status clears them
                $leaver = in_array($st, stuLeaverStatuses(), true);
                $dol    = $leaver ? trim($_POST['date_of_leaving'] ?? '') : '';
                $lr     = $leaver ? trim($_POST['leaving_reason'] ?? '')  : '';
                if ($dol !== '' && !stuValidDate($dol))  jsonErr('Date of leaving is not a valid date');
                if (mb_strlen($lr) > 255)                jsonErr('Leaving reason is too long (max 255 characters)');
                if ($dol === '') $dol = null;
                if ($lr === '')  $lr  = null;

                $t = 'i';
                $p = [$id];
                $row = qOne("SELECT s.id, s.user_id, s.admission_no, s.status, u.full_name FROM students s JOIN users u ON u.id = s.user_id
                             WHERE s.id = ?" . stuScopeAnd($user_id, $t, $p), $t, ...$p);
                if (!$row) jsonErr('Student not found');

                $wt = '';
                $wp = [];
                $own = stuSchoolAnd($wt, $wp);                              // both writes re-state the school

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    qExec("UPDATE students SET status = ?, date_of_leaving = ?, leaving_reason = ?, updated_by = ? WHERE id = ?$own",
                          'sssii' . $wt, $st, $dol, $lr, $user_id, $id, ...$wp);
                    qExec("UPDATE users SET is_active = ? WHERE id = ?$own", 'ii' . $wt,
                          $st === 'Active' ? 1 : 0, (int)$row['user_id'], ...$wp);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                logActivity($user_id, $username, 'Student Status Changed',
                    "{$row['full_name']} ({$row['admission_no']}): {$row['status']} -> $st" . ($dol ? " (left $dol)" : ''));
                jsonOk(['message' => 'Status changed to ' . $st]);
            }

            // ---------------- hard delete (blocked once marks exist) ----------------
            case 'deleteStudent': {
                requireCsrfJson();
                requirePermJson('students', 'd');

                $id = (int)($_POST['id'] ?? 0);
                $t  = 'i';
                $p  = [$id];
                $row = qOne("SELECT s.id, s.user_id, s.admission_no, u.full_name, u.profile_image FROM students s JOIN users u ON u.id = s.user_id
                             WHERE s.id = ?" . stuScopeAnd($user_id, $t, $p), $t, ...$p);
                if (!$row) jsonErr('Student not found');

                $marks = (int) qVal("SELECT COUNT(*) FROM marks WHERE student_id = ?", 'i', $id);
                if ($marks > 0) {
                    jsonErr("Cannot delete {$row['full_name']} — $marks marks record(s) belong to this student. Set the status to Inactive, Passed Out or Transferred instead so the result history stays intact.", ['marks' => $marks]);
                }

                $wt = '';
                $wp = [];
                $own = stuSchoolAnd($wt, $wp);                              // both deletes re-state the school

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    qExec("DELETE FROM students WHERE id = ?$own", 'i' . $wt, $id, ...$wp);
                    qExec("DELETE FROM users WHERE id = ?$own", 'i' . $wt, (int)$row['user_id'], ...$wp);   // login goes with the record
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                stuDropPhoto($row['profile_image']);
                logActivity($user_id, $username, 'Student Deleted', "Deleted student: {$row['full_name']} ({$row['admission_no']})");
                jsonOk(['message' => 'Student deleted']);
            }

            // ---------------- csv import ----------------
            case 'bulkImportStudents': {
                requireCsrfJson();
                requirePermJson('students', 'a');

                $yid  = stuOwn('academic_years', $_POST['academic_year_id'] ?? 0);
                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($rows) || !$rows)  jsonErr('Nothing to import');
                if (count($rows) > 500)          jsonErr('Please import 500 rows or fewer at a time');
                $yrRow = $yid > 0 ? qOne("SELECT * FROM academic_years WHERE id = ?", 'i', $yid) : null;
                if (!$yrRow)                     jsonErr('Please pick a valid academic year');

                // lookup maps — ONE query each, then O(1) in memory. keyed by lowercase NAME, so they
                // must be scoped: an unscoped map lets the last school's "Grade 5" win and swallow the intake
                $ct = $mt = '';
                $cp = $mp = [];
                $classMap = $sectionMap = $classBranch = [];
                foreach (qAll("SELECT id, name" . (stuTenant() >= 2 ? ", branch_id" : "") . " FROM classes WHERE 1 = 1"
                              . stuSchoolAnd($ct, $cp, '', true), $ct, ...$cp) as $c) {
                    $classMap[mb_strtolower(trim($c['name']))] = (int)$c['id'];
                    $classBranch[(int)$c['id']] = isset($c['branch_id']) ? (int)$c['branch_id'] : null;   // new rows inherit it
                }
                // sections carry no school of their own — the class join IS the tenant check
                foreach (qAll("SELECT sec.id, sec.class_id, sec.name FROM sections sec JOIN classes c ON c.id = sec.class_id
                               WHERE 1 = 1" . stuSchoolAnd($mt, $mp, 'c', true), $mt, ...$mp) as $s) {
                    $sectionMap[$s['class_id'] . '|' . mb_strtolower(trim($s['name']))] = (int)$s['id'];
                }
                // the maps above are school-wide on purpose (name lookup), so a scoped teacher could
                // name ANY section in the school and import into it. resolve their own sections once
                // here, then it's an O(1) isset per row instead of a query per row.
                $allowedSec = null;                       // null = school-wide, no per-section limit
                if (!ormsSchoolWide()) {
                    $tid = ormsTeacherId((int)$user_id);
                    $allowedSec = [];
                    if ($tid !== null) {
                        foreach (qAll("SELECT DISTINCT ts.section_id FROM teacher_subjects ts
                                       WHERE ts.teacher_id = ? AND ts.academic_year_id = ?",
                                      'ii', $tid, $yid) as $r) $allowedSec[(int)$r['section_id']] = 1;
                    }
                }

                $takenAdm = $takenUser = $takenRoll = [];
                $at = $ut = '';
                $ap = $up = [];
                foreach (qAll("SELECT admission_no FROM students WHERE 1 = 1" . stuSchoolAnd($at, $ap), $at, ...$ap) as $r) {
                    $takenAdm[mb_strtolower($r['admission_no'])] = 1;
                }
                foreach (qAll("SELECT username FROM users WHERE 1 = 1" . stuSchoolAnd($ut, $up), $ut, ...$up) as $r) {
                    $takenUser[mb_strtolower($r['username'])] = 1;
                }
                $rt = 'i';
                $rp = [$yid];
                foreach (qAll("SELECT section_id, roll_no FROM students WHERE academic_year_id = ?" . stuSchoolAnd($rt, $rp), $rt, ...$rp) as $r) {
                    if (($r['roll_no'] ?? '') !== '') $takenRoll[$r['section_id'] . '|' . mb_strtolower($r['roll_no'])] = 1;
                }

                $prefix = stuPrefix();
                $tag    = stuYearTag($yrRow);
                $nt = 's';
                $np = [$prefix . '-' . $tag . '-%'];
                $nextNo = (int) qVal("SELECT MAX(CAST(SUBSTRING_INDEX(admission_no, '-', -1) AS UNSIGNED)) FROM students WHERE admission_no LIKE ?"
                                     . stuSchoolAnd($nt, $np), $nt, ...$np);

                $accepted = $errors = [];

                // pass 1 — validate + dedup against the db AND inside the batch, never fail-fast
                foreach ($rows as $i => $r) {
                    $line = $i + 2;                                   // +1 header, +1 human numbering
                    if (!is_array($r)) { $errors[] = "Fila $line: formato incorrecto"; continue; }
                    $get = static fn(int $n): string => trim((string)($r[$n] ?? ''));

                    $name   = $get(0);
                    $father = $get(1);
                    $dob    = $get(2);
                    $gender = $get(3);
                    $phone  = $get(4);
                    $addr   = $get(5);
                    $cName  = mb_strtolower($get(6));
                    $sName  = mb_strtolower($get(7));
                    $roll   = $get(8);
                    $admDt  = $get(9);
                    $adm    = strtoupper($get(10));
                    $mother = $get(11);
                    $gName  = $get(12);
                    $gMail  = $get(13);
                    $nid    = $get(14);
                    $blood  = $get(15);
                    $prevSc = $get(16);
                    $rmk    = $get(17);

                    if ($name === '' && $cName === '' && $sName === '') continue;      // blank trailing line
                    if ($name === '')                       { $errors[] = "Fila $line: el nombre es obligatorio"; continue; }
                    if (!isset($classMap[$cName]))          { $errors[] = "Fila $line: el grado o clase \"" . $get(6) . "\" no fue encontrado"; continue; }
                    $cid = $classMap[$cName];
                    if (!isset($sectionMap[$cid . '|' . $sName])) { $errors[] = "Fila $line: la sección \"" . $get(7) . "\" no fue encontrada en " . $get(6); continue; }
                    $sid = $sectionMap[$cid . '|' . $sName];
                    if ($allowedSec !== null && !isset($allowedSec[$sid])) { $errors[] = "Fila $line: no tienes asignada la sección " . $get(6) . '-' . $get(7); continue; }

                    if ($dob !== '' && !stuValidDob($dob))                   { $errors[] = "Fila $line: la fecha de nacimiento debe ser una fecha pasada válida (AAAA-MM-DD o DD/MM/AAAA)"; continue; }
                    if ($admDt !== '' && !stuValidDate($admDt))              { $errors[] = "Fila $line: la fecha de matrícula debe ser AAAA-MM-DD o DD/MM/AAAA"; continue; }
                    
                    $genderRaw = mb_strtolower(trim($gender));
                    $genderMap = [
                        'male' => 'Male', 'm' => 'Male', 'masculino' => 'Male', 'hombre' => 'Male', 'varon' => 'Male',
                        'female' => 'Female', 'f' => 'Female', 'femenino' => 'Female', 'mujer' => 'Female',
                        'other' => 'Other', 'otro' => 'Other', 'o' => 'Other'
                    ];
                    if ($gender !== '') {
                        if (!isset($genderMap[$genderRaw])) {
                            $errors[] = "Fila $line: el género debe ser Masculino, Femenino u Otro"; continue;
                        }
                        $gender = $genderMap[$genderRaw];
                    }

                    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) { $errors[] = "Fila $line: el teléfono del acudiente no es válido"; continue; }
                    if ($gMail !== '' && !filter_var($gMail, FILTER_VALIDATE_EMAIL)) { $errors[] = "Fila $line: el correo del acudiente \"$gMail\" no es válido"; continue; }
                    if ($blood !== '' && ($blood = stuNormBlood($blood)) === '')     { $errors[] = "Fila $line: el grupo sanguíneo debe ser uno de " . implode(', ', stuBloodGroups()); continue; }

                    if ($roll !== '') {
                        $rk = $sid . '|' . mb_strtolower($roll);
                        if (isset($takenRoll[$rk])) { $errors[] = "Fila $line: el número de lista $roll ya está usado en " . $get(6) . '-' . $get(7); continue; }
                        $takenRoll[$rk] = 1;                                 // reserve inside the batch too
                    }

                    if ($adm !== '') {
                        if (isset($takenAdm[mb_strtolower($adm)]) || isset($takenUser[mb_strtolower($adm)])) {
                            $errors[] = "Fila $line: el número de matrícula $adm ya existe"; continue;
                        }
                    } else {
                        do { $adm = sprintf('%s-%s-%04d', $prefix, $tag, ++$nextNo); }
                        while (isset($takenAdm[mb_strtolower($adm)]) || isset($takenUser[mb_strtolower($adm)]));
                    }
                    $takenAdm[mb_strtolower($adm)] = $takenUser[mb_strtolower($adm)] = 1;

                    $accepted[] = [
                        'adm'    => $adm,
                        'name'   => mb_substr($name, 0, 100),
                        'father' => $father !== '' ? mb_substr($father, 0, 100) : null,
                        'dob'    => $dob !== '' ? stuNormDate($dob) : null,
                        'gender' => $gender !== '' ? $gender : null,
                        'phone'  => $phone !== '' ? $phone : null,
                        'addr'   => $addr !== '' ? mb_substr($addr, 0, 255) : null,
                        'roll'   => $roll !== '' ? mb_substr($roll, 0, 20) : null,
                        'admDt'  => $admDt !== '' ? stuNormDate($admDt) : null,
                        'cid'    => $cid,
                        'sid'    => $sid,
                        'branch' => ormsBranchLock() ?: ($classBranch[$cid] ?? null),
                        'mother' => $mother !== '' ? mb_substr($mother, 0, 100) : null,
                        'gName'  => $gName  !== '' ? mb_substr($gName, 0, 100)  : null,
                        'gMail'  => $gMail  !== '' ? mb_substr($gMail, 0, 100)  : null,
                        'nid'    => $nid    !== '' ? mb_substr($nid, 0, 30)     : null,
                        'blood'  => $blood  !== '' ? $blood                     : null,
                        'prevSc' => $prevSc !== '' ? mb_substr($prevSc, 0, 150) : null,
                        'rmk'    => $rmk    !== '' ? mb_substr($rmk, 0, 255)    : null
                    ];
                }

                $imported = 0;
                // Plan seats decide how much of this batch may land. Capped AFTER validation so the rows
                // that fit still import and the overflow returns as ordinary skips — bulk import is the
                // obvious way to walk straight past a per-add limit, so it meets the same ceiling.
                $room = ormsQuotaRoom('students', count($accepted));
                if ($room < count($accepted)) {
                    $qq = ormsQuota('students');
                    foreach (array_slice($accepted, $room) as $ov) {
                        $errors[] = 'Omitido ' . ($ov['name'] ?? 'fila') . ': límite del plan alcanzado (' . $qq['cap'] . ' ' . strtolower($qq['label']) . 's)';
                    }
                    $accepted = array_slice($accepted, 0, $room);
                }

                if ($accepted) {
                    $hash = password_hash((string) getSetting('student_default_password', 'student123'), PASSWORD_DEFAULT);
                    $ten  = stuTenant();
                    $tCol = $ten ? ($ten >= 2 ? ', school_id, branch_id' : ', school_id') : '';
                    $tVal = $ten ? ($ten >= 2 ? ', ?, ?' : ', ?') : '';
                    $tTyp = $ten ? ($ten >= 2 ? 'ii' : 'i') : '';
                    $tSch = $ten ? sid() : 0;
                    $tBr  = null;                                           // per row — the class carries it
                    $conn = getDBConnection();
                    $conn->begin_transaction();
                    try {
                        // one prepared statement per table, executed in a loop — never a query per row built by hand
                        $uStmt = $conn->prepare("INSERT INTO users (username, full_name, email, phone, password, role, is_active, email_verified, must_change_password, created_by$tCol) VALUES (?, ?, ?, ?, ?, 'Student', 1, 1, 1, ?$tVal)");
                        $sStmt = $conn->prepare("INSERT INTO students (user_id, admission_no, roll_no, class_id, section_id, academic_year_id,
                                                                       father_name, dob, gender, guardian_phone, address, admission_date,
                                                                       mother_name, guardian_name, guardian_email, national_id, blood_group,
                                                                       previous_school, remarks, created_by, status$tCol)
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active'$tVal)");
                        $uAdm = $uName = $uMail = $uPhone = '';
                        $sUid = $sCid = $sSid = 0;
                        $sAdm = $sRoll = $sFather = $sDob = $sGen = $sPhone = $sAddr = $sAdmDt = null;
                        $sMother = $sGName = $sGMail = $sNid = $sBlood = $sPrevSc = $sRmk = null;

                        // bind_param reads its args at execute time, so the tenant binds must go in as REFS too
                        $uArgs = ['sssssi' . $tTyp, &$uAdm, &$uName, &$uMail, &$uPhone, &$hash, &$user_id];
                        $sArgs = ['issiiisssssssssssssi' . $tTyp, &$sUid, &$sAdm, &$sRoll, &$sCid, &$sSid, &$yid,
                                  &$sFather, &$sDob, &$sGen, &$sPhone, &$sAddr, &$sAdmDt,
                                  &$sMother, &$sGName, &$sGMail, &$sNid, &$sBlood, &$sPrevSc, &$sRmk, &$user_id];
                        if ($ten)      { $uArgs[] = &$tSch; $sArgs[] = &$tSch; }
                        if ($ten >= 2) { $uArgs[] = &$tBr;  $sArgs[] = &$tBr;  }
                        call_user_func_array([$uStmt, 'bind_param'], $uArgs);
                        call_user_func_array([$sStmt, 'bind_param'], $sArgs);

                        foreach ($accepted as $a) {
                            $tBr  = $a['branch'];
                            $uAdm = $a['adm']; $uName = $a['name']; $uMail = strtolower($a['adm']) . '@example.com'; $uPhone = $a['phone'];
                            $uStmt->execute();
                            $sUid = $uStmt->insert_id;
                            $sAdm = $a['adm']; $sRoll = $a['roll']; $sCid = $a['cid']; $sSid = $a['sid'];
                            $sFather = $a['father']; $sDob = $a['dob']; $sGen = $a['gender'];
                            $sPhone = $a['phone']; $sAddr = $a['addr']; $sAdmDt = $a['admDt'];
                            $sMother = $a['mother']; $sGName = $a['gName']; $sGMail = $a['gMail']; $sNid = $a['nid'];
                            $sBlood = $a['blood']; $sPrevSc = $a['prevSc']; $sRmk = $a['rmk'];
                            $sStmt->execute();
                            $imported++;
                        }
                        $uStmt->close();
                        $sStmt->close();
                        $conn->commit();
                    } catch (Throwable $e) {
                        $conn->rollback();                                  // all-or-nothing, no half-imported batch
                        error_log('students.php import: ' . $e->getMessage());
                        jsonErr('Import failed — nothing was saved. Please check the file and try again.');
                    }
                }

                $skipped = count($errors);
                logActivity($user_id, $username, 'Students Imported', "Imported $imported student(s), skipped $skipped (year: {$yrRow['name']})");
                jsonOk(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors,
                        'message'  => "$imported imported, $skipped skipped"]);
            }

            // ---------------- promotion ----------------
            case 'promotePreview': {
                requireCsrfJson();
                requirePermJson('students', 'e');
                $cid = stuOwn('classes', $_POST['class_id'] ?? 0);
                $sid = stuOwn('sections', $_POST['section_id'] ?? 0);
                $yid = stuOwn('academic_years', $_POST['academic_year_id'] ?? 0);
                if ($cid <= 0 || $sid <= 0 || $yid <= 0) jsonErr('Pick the source class, section and year first');

                $t = 'iii';
                $p = [$cid, $sid, $yid];
                jsonOk(['data' => qAll(
                    "SELECT s.id, s.admission_no, s.roll_no, u.full_name
                     FROM students s
                     JOIN users u ON u.id = s.user_id
                     WHERE s.class_id = ? AND s.section_id = ? AND s.academic_year_id = ? AND s.status = 'Active'"
                     . stuScopeAnd($user_id, $t, $p) . "
                     ORDER BY CAST(s.roll_no AS UNSIGNED) ASC, s.admission_no ASC",
                    $t, ...$p)]);
            }

            case 'promoteStudents': {
                requireCsrfJson();
                requirePermJson('students', 'e');

                $ids  = json_decode($_POST['ids'] ?? '[]', true);
                // the promotion TARGET is a write destination — resolve it before anything trusts it
                $tCid = stuOwn('classes', $_POST['target_class_id'] ?? 0);
                $tSid = stuOwn('sections', $_POST['target_section_id'] ?? 0);
                $tYid = stuOwn('academic_years', $_POST['target_year_id'] ?? 0);

                if (!is_array($ids) || !$ids)                jsonErr('Select at least one student to promote');
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                if (!$ids)                                   jsonErr('Select at least one student to promote');
                if (count($ids) > 500)                       jsonErr('Promote 500 students or fewer at a time');
                if ($tCid <= 0 || $tSid <= 0 || $tYid <= 0)  jsonErr('Pick the target class, section and year');
                if (!stuSectionOk($tCid, $tSid))             jsonErr('The target section does not belong to the target class');
                if (!stuSectionInScope($tSid))               jsonErr('You can only promote into a section you are assigned to');
                $tYear = qOne("SELECT * FROM academic_years WHERE id = ?", 'i', $tYid);
                if (!$tYear)                                 jsonErr('Pick a valid target academic year');

                $ph   = implode(',', array_fill(0, count($ids), '?'));       // placeholders only — values stay bound
                $t    = str_repeat('i', count($ids));
                $p    = $ids;
                $list = qAll("SELECT s.id, s.roll_no, s.admission_no, s.class_id, s.section_id, s.academic_year_id, u.full_name
                              FROM students s JOIN users u ON u.id = s.user_id
                              WHERE s.id IN ($ph) AND s.status = 'Active'" . stuScopeAnd($user_id, $t, $p), $t, ...$p);
                if (!$list) jsonErr('None of the selected students are active any more');

                // rolls already parked in the target section for that year, this school
                $taken = [];
                $kt = 'ii';
                $kp = [$tSid, $tYid];
                foreach (qAll("SELECT roll_no FROM students WHERE section_id = ? AND academic_year_id = ?" . stuSchoolAnd($kt, $kp), $kt, ...$kp) as $r) {
                    if (($r['roll_no'] ?? '') !== '') $taken[mb_strtolower($r['roll_no'])] = 1;
                }

                $move = $errors = [];
                foreach ($list as $s) {
                    if ($s['class_id'] == $tCid && $s['section_id'] == $tSid && $s['academic_year_id'] == $tYid) {
                        $errors[] = "{$s['full_name']} ({$s['admission_no']}) is already in the target class";
                        continue;
                    }
                    $rk = mb_strtolower((string)$s['roll_no']);
                    if ($rk !== '' && isset($taken[$rk])) {
                        $errors[] = "{$s['full_name']} ({$s['admission_no']}): roll no {$s['roll_no']} is already taken in the target section";
                        continue;
                    }
                    if ($rk !== '') $taken[$rk] = 1;
                    $move[] = (int)$s['id'];
                }
                if (!$move) jsonErr('Nothing to promote — ' . implode('; ', $errors), ['errors' => $errors]);

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // marks rows keep their own class/section/year, so history is untouched by design.
                    // branch rides along with the target class, and the write re-states the school itself
                    $ten  = stuTenant();
                    $pBr  = $ten >= 2 ? stuBranch($tCid) : null;
                    $setB = $pBr !== null;                  // no branch resolved -> leave the stamp alone
                    $pSch = $ten ? sid() : 0;
                    $sid  = 0;
                    $stmt = $conn->prepare("UPDATE students SET class_id = ?, section_id = ?, academic_year_id = ?, updated_by = ?"
                        . ($setB ? ", branch_id = ?" : '') . " WHERE id = ?" . ($ten ? " AND school_id = ?" : ''));
                    // types: 4 i (class..updated_by) [+ i branch] + i id [+ i school]
                    $args = ['iiii' . ($setB ? 'i' : '') . 'i' . ($ten ? 'i' : ''), &$tCid, &$tSid, &$tYid, &$user_id];
                    if ($setB) $args[] = &$pBr;
                    $args[] = &$sid;
                    if ($ten) $args[] = &$pSch;
                    call_user_func_array([$stmt, 'bind_param'], $args);
                    foreach ($move as $sid) $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                $tc = qVal("SELECT name FROM classes WHERE id = ?", 'i', $tCid);
                $ts = qVal("SELECT name FROM sections WHERE id = ?", 'i', $tSid);
                logActivity($user_id, $username, 'Students Promoted',
                    count($move) . " student(s) promoted to $tc-$ts ({$tYear['name']}), " . count($errors) . ' skipped');

                jsonOk(['promoted' => count($move), 'skipped' => count($errors), 'errors' => $errors,
                        'message'  => count($move) . ' promoted, ' . count($errors) . ' skipped']);
            }

            default:
                jsonErr('Invalid action');
        }
    } catch (mysqli_sql_exception $e) {
        // must sit ABOVE RuntimeException — mysqli_sql_exception extends it, and raw sql text never goes to the browser
        error_log('students.php db: ' . $e->getMessage());
        jsonErr((int)$e->getCode() === 1062
            ? 'That record already exists — admission no or roll no is taken'
            : 'Database error — nothing was saved');
    } catch (RuntimeException $e) {
        jsonErr($e->getMessage());                                  // our own validation messages
    } catch (Throwable $e) {
        error_log('students.php error: ' . $e->getMessage());
        jsonErr('Server error. Please try again.');
    }
}

// ============================================
// Page data
// ============================================
$years    = ormsYears();
$curYear  = ormsCurrentYear();
$curYrId  = (int)($curYear['id'] ?? 0);
// class picker is a filter, so it must not advertise another school's classes
$clsT = '';
$clsP = [];
try { $classes = qAll("SELECT id, name FROM classes WHERE is_active = 1" . stuSchoolAnd($clsT, $clsP, '', true)
                      . " ORDER BY sort_order ASC, name ASC", $clsT, ...$clsP); }
catch (Throwable $e) { $classes = []; }
$statuses = stuStatuses();
$defPwd   = (string) getSetting('student_default_password', 'student123');
$canAdd   = can('students', 'a');
$canEdit  = can('students', 'e');
$canDel   = can('students', 'd');
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
    <title>Students - Online Result Management</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="styles.css?v=15.3">
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
                    <h1><i class="fas fa-user-graduate"></i> Students</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Students</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="tab-nav no-print">
                <button type="button" class="tab-btn active" data-tab="roster" onclick="stuTab('roster')">
                    <i class="fas fa-list"></i> Roster
                </button>
                <?php if ($canEdit): ?>
                <button type="button" class="tab-btn" data-tab="promote" onclick="stuTab('promote')">
                    <i class="fas fa-arrow-up-right-dots"></i> Promotion
                </button>
                <?php endif; ?>
            </div>

            <!-- ============ Roster ============ -->
            <div class="tab-pane active no-print" id="tab-roster">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-table"></i> Student Roster</h2>
                        <div class="btn-group-inline">
                            <button class="btn btn-primary" id="btnRefresh" onclick="loadStudents(this)">
                                <i class="fas fa-sync"></i> Refresh
                            </button>
                            <?php if ($canAdd): ?>
                            <button class="btn btn-success" onclick="stuOpenAdd()">
                                <i class="fas fa-user-plus"></i> Register Student
                            </button>
                            <button class="btn btn-secondary" onclick="stuTemplate()">
                                <i class="fas fa-download"></i> Plantilla
                            </button>
                            <button class="btn btn-secondary" id="btnImportStudents" onclick="document.getElementById('studentCsvInput').click()">
                                <i class="fas fa-file-import"></i> Importar CSV
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Filters Section -->
                    <div class="filters-section initially-hidden" id="filtersSection">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Filters</h3>
                            <button class="btn btn-secondary btn-sm" onclick="stuClearFilters()">
                                <i class="fas fa-times-circle"></i> Clear All
                            </button>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                                <select id="filterYear" class="filter-input">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>" <?php echo $y['id'] == $curYrId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($y['name']); ?>
                                    </option>
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
                                <label><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="filterStatus" class="filter-input">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($statuses as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-venus-mars"></i> Gender</label>
                                <select id="filterGender" class="filter-input">
                                    <option value="">All Genders</option>
                                    <?php foreach (stuGenders() as $g): ?>
                                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- plain wrapper carries initially-hidden — .stat-mini sets display:flex later in the sheet -->
                    <div id="rosterStats" class="initially-hidden">
                        <div class="stat-mini">
                            <div><i class="fas fa-users"></i> Total <b id="statTotal">0</b></div>
                            <div><i class="fas fa-user-check"></i> Active <b id="statActive">0</b></div>
                            <div><i class="fas fa-user-slash"></i> Inactive <b id="statInactive">0</b></div>
                            <div><i class="fas fa-user-graduate"></i> Passed Out <b id="statPassed">0</b></div>
                        </div>
                    </div>

                    <div id="importResult" class="initially-hidden"></div>

                    <!-- skeleton while the first fetch runs -->
                    <div id="loadingSkeleton">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            </div>
                            <?php for ($i = 0; $i < 8; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                                <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div id="tableContainer" class="initially-hidden">
                        <div class="table-scroll-hint">
                            <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                        </div>
                        <div class="table-responsive">
                            <table id="studentsTable" class="display chip-table"></table>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <!-- ============ Promotion ============ -->
            <div class="tab-pane no-print" id="tab-promote">
                <div class="data-section">
                    <div class="section-header">
                        <h2><i class="fas fa-arrow-up-right-dots"></i> Promote Students</h2>
                    </div>

                    <div class="info-banner">
                        <i class="fas fa-shield-halved"></i>
                        <span>Promotion only moves the student's current class, section and academic year.
                        Marks history is never touched — every marks row carries its own class, section and year, so old result cards stay exactly as they were.</span>
                    </div>

                    <div class="filters-section mt-20">
                        <div class="filters-header">
                            <h3><i class="fas fa-right-from-bracket"></i> From (source)</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                                <select id="srcYear" class="filter-input">
                                    <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>" <?php echo $y['id'] == $curYrId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($y['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="srcClass" class="filter-input">
                                    <option value="">Select class</option>
                                    <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-layer-group"></i> Section</label>
                                <select id="srcSection" class="filter-input">
                                    <option value="">Select section</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <button class="btn btn-primary btn-block" id="btnPreview" onclick="stuPreview(this)">
                                    <i class="fas fa-eye"></i> Preview Students
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="filters-section mt-20">
                        <div class="filters-header">
                            <h3><i class="fas fa-right-to-bracket"></i> To (target)</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                                <select id="tgtYear" class="filter-input">
                                    <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>" <?php echo $y['id'] == $curYrId ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($y['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-school"></i> Class</label>
                                <select id="tgtClass" class="filter-input">
                                    <option value="">Select class</option>
                                    <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label><i class="fas fa-layer-group"></i> Section</label>
                                <select id="tgtSection" class="filter-input">
                                    <option value="">Select section</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <button class="btn btn-success btn-block" id="btnPromote" onclick="stuPromote(this)">
                                    <i class="fas fa-arrow-up-right-dots"></i> Promote Selected
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="promoteBox" class="mt-24">
                        <div class="orms-empty">
                            <i class="fas fa-users-viewfinder"></i>
                            <h4>No preview yet</h4>
                            <p>Pick a source class and section, then hit Preview Students.</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- credential slip lands here for printing (modals are hidden by the print stylesheet) -->
            <div id="credPrintArea" class="print-only"></div>
        </div>
    </div>

    <!-- ============ Student form modal ============ -->
    <div class="modal-overlay" id="studentModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="studentModalTitle"><i class="fas fa-user-plus"></i> Register Student</h3>
                <button class="close-btn" onclick="stuCloseModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="studentForm" enctype="multipart/form-data">
                    <input type="hidden" id="studentId" name="id">

                    <div class="modal-split">
                    <div class="modal-main">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name *</label>
                            <input type="text" id="fFullName" name="full_name" maxlength="100" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-days"></i> Academic Year *</label>
                            <select id="fYear" name="academic_year_id" required>
                                <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo $y['id'] == $curYrId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($y['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-school"></i> Class *</label>
                            <select id="fClass" name="class_id" required>
                                <option value="">Select class</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Section *</label>
                            <select id="fSection" name="section_id" required>
                                <option value="">Select section</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Roll No</label>
                            <input type="text" id="fRollNo" name="roll_no" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Admission Date</label>
                            <input type="date" id="fAdmDate" name="admission_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-cake-candles"></i> Date of Birth</label>
                            <input type="date" id="fDob" name="dob">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-venus-mars"></i> Gender</label>
                            <select id="fGender" name="gender">
                                <option value="">Not specified</option>
                                <?php foreach (stuGenders() as $g): ?>
                                <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="fStatus" name="status">
                                <?php foreach ($statuses as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filters-header mt-20">
                        <h3><i class="fas fa-people-roof"></i> Family &amp; Contact</h3>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Father's Name</label>
                            <input type="text" id="fFatherName" name="father_name" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-person-dress"></i> Mother's Name</label>
                            <input type="text" id="fMotherName" name="mother_name" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-shield"></i> Guardian Name</label>
                            <input type="text" id="fGuardianName" name="guardian_name" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Guardian Phone</label>
                            <input type="tel" id="fPhone" name="guardian_phone" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Guardian Email</label>
                            <input type="email" id="fGuardianEmail" name="guardian_email" maxlength="100">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Optional — result alerts can be mailed here</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-location-dot"></i> Address</label>
                            <input type="text" id="fAddress" name="address" maxlength="255">
                        </div>
                    </div>

                    <div class="filters-header mt-20">
                        <h3><i class="fas fa-circle-info"></i> Other Details</h3>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-id-card-clip"></i> National ID / B-Form</label>
                            <input type="text" id="fNationalId" name="national_id" maxlength="30">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-droplet"></i> Blood Group</label>
                            <select id="fBloodGroup" name="blood_group">
                                <option value="">Not specified</option>
                                <?php foreach (stuBloodGroups() as $bg): ?>
                                <option value="<?php echo htmlspecialchars($bg); ?>"><?php echo htmlspecialchars($bg); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building-columns"></i> Previous School</label>
                            <input type="text" id="fPrevSchool" name="previous_school" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-note-sticky"></i> Remarks</label>
                            <input type="text" id="fRemarks" name="remarks" maxlength="255">
                        </div>
                    </div>

                    <!-- leavers only — shown when status is Transferred or Passed Out, cleared otherwise -->
                    <div id="leavingBlock" class="initially-hidden">
                        <div class="filters-header mt-20">
                            <h3><i class="fas fa-right-from-bracket"></i> Leaving Details</h3>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-calendar-xmark"></i> Date of Leaving</label>
                                <input type="date" id="fLeaveDate" name="date_of_leaving">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Optional — kept for transfer certificates</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-comment-dots"></i> Leaving Reason</label>
                                <input type="text" id="fLeaveReason" name="leaving_reason" maxlength="255">
                            </div>
                        </div>
                    </div>

                    </div><!-- /modal-main -->

                    <!-- live profile document — mirrors what the card and credential slip will show -->
                    <aside class="modal-side">
                        <div class="stu-pv">
                            <div class="stu-pv-frame">
                                <img id="stuPvPhoto" class="stu-pv-photo initially-hidden" alt="">
                                <div id="stuPvPlaceholder" class="stu-pv-photo stu-pv-ph"><i class="fas fa-user-graduate"></i></div>
                            </div>
                            <div class="stu-pv-name" id="stuPvName">New Student</div>
                            <div class="stu-pv-sub" id="stuPvClass">Class not selected</div>
                            <div class="stu-pv-rows">
                                <div class="stu-pv-row"><span>Admission No</span><b id="stuPvAdm">Auto</b></div>
                                <div class="stu-pv-row"><span>Roll No</span><b id="stuPvRoll">—</b></div>
                                <div class="stu-pv-row"><span>Date of Birth</span><b id="stuPvDob">—</b></div>
                                <div class="stu-pv-row"><span>Guardian</span><b id="stuPvGuardian">—</b></div>
                                <div class="stu-pv-row"><span>Status</span><b id="stuPvStatus">Active</b></div>
                            </div>
                        </div>

                        <div class="form-group mt-20">
                            <label><i class="fas fa-image"></i> Photo</label>
                            <input type="file" id="fPhoto" name="photo" accept="image/jpeg,image/png,image/webp" class="file-input-styled">
                            <div class="help-text"><i class="fas fa-info-circle"></i> JPG, PNG or WEBP — max 2MB</div>
                        </div>

                        <div class="info-banner" id="admissionHint">
                            <i class="fas fa-id-card"></i>
                            <span>The admission no is generated automatically and becomes the student's login username.
                            The default password is <b><?php echo htmlspecialchars($defPwd); ?></b> — a printable credential slip is offered right after saving.</span>
                        </div>
                    </aside>
                    </div><!-- /modal-split -->

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="btnSaveStudent">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="stuCloseModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============ View modal ============ -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-id-card"></i> Student Details</h3>
                <div class="btn-group-inline no-print">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="ORMS.printOnly('#viewBody')"><i class="fas fa-print"></i> Print</button>
                    <button class="close-btn" onclick="stuCloseView()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body" id="viewBody"></div>
        </div>
    </div>

    <!-- ============ Status change modal (this is the soft delete) ============ -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-toggle-on"></i> Change Status</h3>
                <button class="close-btn" onclick="stuCloseStatus()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-banner" id="stWho"></div>
                <div class="form-grid mt-20">
                    <div class="form-group">
                        <label><i class="fas fa-toggle-on"></i> Status</label>
                        <select id="stStatus">
                            <?php foreach ($statuses as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="stLeavingBlock" class="initially-hidden">
                    <div class="filters-header mt-20">
                        <h3><i class="fas fa-right-from-bracket"></i> Leaving Details</h3>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-xmark"></i> Date of Leaving</label>
                            <input type="date" id="stLeaveDate">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Optional — kept for transfer certificates</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-comment-dots"></i> Leaving Reason</label>
                            <input type="text" id="stLeaveReason" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" id="btnSaveStatus" onclick="stuSaveStatus(this)">
                        <i class="fas fa-check"></i> Save
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="stuCloseStatus()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ Credential slip modal ============ -->
    <!-- printable-modal opts this one out of the blanket "hide every overlay" print rule -->
    <div class="modal-overlay printable-modal" id="credModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Credential Slip</h3>
                <button class="close-btn" onclick="stuCloseCred()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div id="credBody"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" onclick="stuPrintCred()">
                        <i class="fas fa-print"></i> Print Slip
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="stuCloseCred()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <input type="file" id="studentCsvInput" accept=".csv,text/csv" class="initially-hidden">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="orms.js?v=2.7"></script>
    <script>window.ORMS_CSRF = '<?php echo csrfToken(); ?>';</script>

    <script>
    // lazy pdf/excel deps — only fetched when someone actually exports
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
        var CAN = { a: <?php echo $canAdd ? 'true' : 'false'; ?>, e: <?php echo $canEdit ? 'true' : 'false'; ?>, d: <?php echo $canDel ? 'true' : 'false'; ?> };
        var DEF_PWD  = <?php echo json_encode($defPwd); ?>;
        // header row order MUST match the importer's $get(0..17) in bulkImportStudents
        var CSV_HEAD_ES = [
            'Nombre Completo', 'Nombre Padre', 'Fecha Nacimiento', 'Genero', 'Telefono Acudiente',
            'Direccion', 'Grado', 'Seccion', 'Numero Lista', 'Fecha Matricula', 'Numero Matricula',
            'Nombre Madre', 'Nombre Acudiente', 'Correo Acudiente', 'Documento Identidad',
            'Grupo Sanguineo', 'Colegio Anterior', 'Observaciones'
        ];
        var CSV_HEAD_EN = [
            'full_name', 'father_name', 'dob', 'gender', 'guardian_phone',
            'address', 'class', 'section', 'roll_no', 'admission_date', 'admission_no',
            'mother_name', 'guardian_name', 'guardian_email', 'national_id',
            'blood_group', 'previous_school', 'remarks'
        ];
        var CSV_HEAD = CSV_HEAD_ES;
        var LEAVER   = ['Transferred', 'Passed Out'];

        var studentsTable = null, studentsData = [], isEditMode = false, previewRows = [], statusForId = 0;

        $(document).ready(function() {
            ORMS.dropdown('#filterYear, #filterClass, #filterSection, #filterStatus, #filterGender');
            ORMS.dropdown('#fGender, #fYear, #fClass, #fSection, #fStatus, #fBloodGroup');
            ORMS.dropdown('#stStatus');
            ORMS.dropdown('#srcYear, #srcClass, #srcSection, #tgtYear, #tgtClass, #tgtSection');

            // class -> section chains
            $('#filterClass').on('change', function() { stuSections(this.value, '#filterSection', '', 'All Sections'); loadStudents(); });
            $('#fClass').on('change',      function() { stuSections(this.value, '#fSection', '', 'Select section'); });
            $('#srcClass').on('change',    function() { stuSections(this.value, '#srcSection', '', 'Select section'); });
            $('#tgtClass').on('change',    function() { stuSections(this.value, '#tgtSection', '', 'Select section'); });

            // leaving details follow the status in both the form and the status modal
            $('#fStatus').on('change',  function() { stuLeavingToggle(this.value, '#leavingBlock',   '#fLeaveDate',  '#fLeaveReason'); });
            $('#stStatus').on('change', function() { stuLeavingToggle(this.value, '#stLeavingBlock', '#stLeaveDate', '#stLeaveReason'); });

            $('#filterYear, #filterSection, #filterStatus, #filterGender').on('change', function() { loadStudents(); });

            loadStudents();
        });

        function stuIsLeaver(st) { return LEAVER.indexOf(st) !== -1; }

        // show for leavers, hide + wipe for everyone else so a revert cannot keep stale dates
        function stuLeavingToggle(st, block, dateSel, reasonSel) {
            var on = stuIsLeaver(st);
            $(block).toggle(on);
            if (!on) { $(dateSel).val(''); $(reasonSel).val(''); }
        }

        // ---------- tabs ----------
        function stuTab(name) {
            $('.tab-btn').removeClass('active').filter('[data-tab="' + name + '"]').addClass('active');
            $('.tab-pane').removeClass('active');
            $('#tab-' + name).addClass('active');
        }

        // ---------- dependent dropdowns ----------
        function stuSections(classId, sel, selected, placeholder) {
            var $s = $(sel);
            $s.html('<option value="">' + ORMS.esc(placeholder || 'Select section') + '</option>');
            if (!classId) { ORMS.dropdown.refresh(sel); return; }
            ORMS.post('getSections', { class_id: classId }).done(function(res) {
                if (!res || !res.success) return;
                var html = '<option value="">' + ORMS.esc(placeholder || 'Select section') + '</option>';
                (res.data || []).forEach(function(r) {
                    html += '<option value="' + r.id + '"' + (String(r.id) === String(selected) ? ' selected' : '') + '>' + ORMS.esc(r.name) + '</option>';
                });
                $s.html(html);
                ORMS.dropdown.refresh(sel);
                if (sel === '#fSection' && typeof stuPvRender === 'function') stuPvRender();  // preview waits on this fetch
            });
        }

        // ---------- roster ----------
        function loadStudents(btn) {
            var f = {
                year:    $('#filterYear').val() || '',
                class:   $('#filterClass').val() || '',
                section: $('#filterSection').val() || '',
                status:  $('#filterStatus').val() || '',
                gender:  $('#filterGender').val() || ''
            };
            ORMS.post('getStudents', f, btn ? { btn: btn, busyLabel: 'Loading…' } : null).done(function(res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Failed to load students'); return; }
                studentsData = res.data || [];
                $('#loadingSkeleton').hide();
                $('#filtersSection, #rosterStats, #tableContainer').show();
                stuStats(studentsData);
                stuBuildTable(studentsData);
            }).fail(function(msg) { ORMS.err(msg); });
        }

        function stuStats(rows) {
            var a = 0, i = 0, p = 0;
            rows.forEach(function(r) {
                if (r.status === 'Active') a++;
                else if (r.status === 'Inactive') i++;
                else if (r.status === 'Passed Out') p++;
            });
            $('#statTotal').text(rows.length);
            $('#statActive').text(a);
            $('#statInactive').text(i);
            $('#statPassed').text(p);
        }


        // ---- chip cells: 8 flat columns -> 5 grouped ones. A student row carries adm no, roll,
        // class, section, year, father, guardian, phone, email, gender, dob, blood group and status;
        // as flat columns they either overflowed or got dropped. ----
        var C = ORMS.chip, R = ORMS.crow, K = ORMS.stack, BOX = ORMS.box, DASH = ORMS.DASH;
        function sv(x) { return (x === null || x === undefined || x === '') ? DASH : ORMS.esc(x); }
        function sday(x) { return (x === null || x === undefined || x === '') ? DASH : ORMS.esc(String(x).slice(0, 10)); }

        function cellStudent(r) {
            var img = r.photo ? '<img src="' + ORMS.esc(r.photo) + '" alt="" class="marks-student-photo">'
                              : '<img src="icon-192.png" alt="" class="marks-student-photo">';
            return K([
                '<div class="marks-student">' + img + '<div><span class="marks-student-name">' + ORMS.esc(r.full_name || '') +
                '</span><span class="marks-student-roll">@' + ORMS.esc(r.username || '') + '</span></div></div>',
                R(C('chip-navy', 'fa-id-card', 'Adm No'), BOX(r.admission_no || '')),
                R(C('chip-soft-navy', 'fa-hashtag', 'Roll'), sv(r.roll_no)),
                R(C('chip-soft-navy', 'fa-venus-mars', 'Gender'), sv(r.gender))
            ]);
        }

        function cellClass(r) {
            return K([
                R(C('chip-soft-purple', 'fa-school', 'Class'), ORMS.esc((r.class_name || '') + ' – ' + (r.section_name || ''))),
                R(C('chip-soft-purple', 'fa-calendar', 'Year'), sv(r.year_name)),
                R(C('chip-soft-navy', 'fa-calendar-plus', 'Admitted'), sday(r.admission_date))
            ]);
        }

        function cellGuardian(r) {
            return K([
                R(C('chip-soft-tan', 'fa-user', 'Father'), sv(r.father_name)),
                R(C('chip-soft-tan', 'fa-user-shield', 'Guardian'), sv(r.guardian_name)),
                R(C('chip-soft-navy', 'fa-phone', 'Phone'), sv(r.guardian_phone)),
                R(C('chip-soft-navy', 'fa-envelope', 'Email'), sv(r.guardian_email))
            ]);
        }

        function cellStuState(r) {
            var live = r.status === 'Active';
            return K([
                R(C(live ? 'chip-soft-green' : 'chip-soft-amber', 'fa-toggle-on', 'Status'),
                  '<span class="' + (live ? 'val-pos' : 'val-neg') + '">' + ORMS.esc(r.status || '') + '</span>'),
                R(C('chip-soft-navy', 'fa-cake-candles', 'DOB'), sday(r.dob)),
                R(C('chip-soft-navy', 'fa-droplet', 'Blood'), sv(r.blood_group))
            ]);
        }

        function stuBlob(r) {
            return [r.full_name, r.username, r.admission_no, r.roll_no, r.class_name, r.section_name, r.year_name,
                    r.father_name, r.guardian_name, r.guardian_phone, r.guardian_email, r.gender, r.status,
                    r.national_id].filter(Boolean).join(' ');
        }

        // chip html for display, the real value for sort, a text blob for filter — the one rule that
        // keeps sorting off the markup and the search box still able to find an admission number
        function stuCol(title, build, sortField, cls) {
            return { data: null, title: title, className: cls || '', render: function (d, t, r) {
                if (t === 'display') return build(r);
                if (t === 'filter')  return stuBlob(r);
                var v = r[sortField];
                return v === null || v === undefined ? '' : v;
            } };
        }


        function stuBuildTable(data) {
            if (studentsTable) { studentsTable.destroy(); $('#studentsTable').empty(); }
            var xOpts = { columns: ':visible:not(.col-actions)', format: ORMS.asText };
            studentsTable = $('#studentsTable').DataTable({
                data: data,
                destroy: true,
                columns: [
                    stuCol('Student', cellStudent, 'admission_no'),
                    stuCol('Class', cellClass, 'class_name'),
                    stuCol('Guardian', cellGuardian, 'father_name'),
                    stuCol('Status', cellStuState, 'status'),
                    { data: null, title: 'Actions', orderable: false, className: 'col-actions', render: function(d, type, row) {
                        if (type !== 'display') return '';
                        var b = '<button class="action-icon" title="View" onclick="stuView(' + row.id + ')"><i class="fas fa-eye"></i></button>';
                        if (CAN.e) {
                            b += '<button class="action-icon edit-icon" title="Edit" onclick="stuEdit(' + row.id + ')"><i class="fas fa-edit"></i></button>';
                            b += '<button class="action-icon" title="Reset password" onclick="stuResetPwd(' + row.id + ', this)"><i class="fas fa-key"></i></button>';
                            b += '<button class="action-icon" title="Change status" onclick="stuStatusChange(' + row.id + ')"><i class="fas fa-toggle-on"></i></button>';
                        }
                        b += '<button class="action-icon" title="Credential slip" onclick="stuSlipFor(' + row.id + ')"><i class="fas fa-receipt"></i></button>';
                        if (CAN.d) {
                            b += '<button class="action-icon delete-icon" title="Delete" onclick="stuDelete(' + row.id + ', this)"><i class="fas fa-trash"></i></button>';
                        }
                        return '<div class="actions-cell">' + b + '</div>';
                    } }
                ],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: false,          // the chips carry the density; .table-responsive scrolls sideways
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Students', exportOptions: xOpts },
                    { text: '<i class="fas fa-file-pdf"></i> PDF',
                      action: function(e, dt, node, config) {
                          loadExportDeps(function() { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                      },
                      title: 'Students', exportOptions: xOpts },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Students', exportOptions: xOpts }
                ],
                order: [],                  // the server already orders by class, section then roll
                language: { emptyTable: 'No students found for these filters' }
            });
        }
        function stuClearFilters() {
            $('#filterYear, #filterClass, #filterStatus, #filterGender').val('');
            $('#filterSection').html('<option value="">All Sections</option>');
            ORMS.dropdown.refresh('#filterYear, #filterClass, #filterSection, #filterStatus, #filterGender');
            loadStudents();
        }

        function stuRow(id) {
            for (var i = 0; i < studentsData.length; i++) if (String(studentsData[i].id) === String(id)) return studentsData[i];
            return null;
        }

        // ---------- add / edit ----------
        function stuOpenAdd() {
            isEditMode = false;
            document.getElementById('studentForm').reset();
            $('#studentId').val('');
            $('#studentModalTitle').html('<i class="fas fa-user-plus"></i> Register Student');
            $('#admissionHint').show();
            $('#fSection').html('<option value="">Select section</option>');
            $('#leavingBlock').hide();                                   // new admissions start Active
            ORMS.dropdown.refresh('#fGender, #fYear, #fClass, #fSection, #fStatus, #fBloodGroup');
            stuPvPhoto(null); stuPvRender();
            $('#studentModal').addClass('active');
        }

        function stuEdit(id) {
            var r = stuRow(id);
            if (!r) return;
            isEditMode = true;
            document.getElementById('studentForm').reset();
            $('#studentModalTitle').html('<i class="fas fa-user-pen"></i> Edit ' + ORMS.esc(r.full_name));
            $('#admissionHint').hide();
            $('#studentId').val(r.id);
            $('#fFullName').val(r.full_name || '');
            $('#fFatherName').val(r.father_name || '');
            $('#fDob').val(r.dob || '');
            $('#fPhone').val(r.guardian_phone || '');
            $('#fRollNo').val(r.roll_no || '');
            $('#fAdmDate').val(r.admission_date || '');
            $('#fAddress').val(r.address || '');
            $('#fMotherName').val(r.mother_name || '');
            $('#fGuardianName').val(r.guardian_name || '');
            $('#fGuardianEmail').val(r.guardian_email || '');
            $('#fNationalId').val(r.national_id || '');
            $('#fPrevSchool').val(r.previous_school || '');
            $('#fRemarks').val(r.remarks || '');
            $('#fLeaveDate').val(r.date_of_leaving || '');
            $('#fLeaveReason').val(r.leaving_reason || '');
            $('#fGender').val(r.gender || '');
            $('#fBloodGroup').val(r.blood_group || '');
            $('#fYear').val(r.academic_year_id);
            $('#fClass').val(r.class_id);
            $('#fStatus').val(r.status);
            $('#leavingBlock').toggle(stuIsLeaver(r.status));
            ORMS.dropdown.refresh('#fGender, #fYear, #fClass, #fStatus, #fBloodGroup');
            stuSections(r.class_id, '#fSection', r.section_id, 'Select section');
            stuPvPhoto(r.profile_image || null); stuPvRender();     // section fills async, render again below
            $('#studentModal').addClass('active');
        }

        function stuCloseModal() {
            $('#studentModal').removeClass('active');
            document.getElementById('studentForm').reset();
            stuPvPhoto(null);
        }

        // ---------- live profile document (right pane) ----------

        // ddText -> the visible option label, since the searchable dropdown hides the real select
        function stuSelText(sel) {
            var o = $(sel).find('option:selected');
            var v = $.trim(o.val() || ''), t = $.trim(o.text() || '');
            return v === '' ? '' : t;
        }

        // 2014-03-14 -> 14 Mar 2014, same shape the result card prints
        var STU_MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        function stuPvDate(v) {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v || ''));
            return m ? (+m[3]) + ' ' + STU_MON[+m[2] - 1] + ' ' + m[1] : '';
        }

        function stuPvRender() {
            var cls = stuSelText('#fClass'), sec = stuSelText('#fSection');
            var dob = $('#fDob').val(), adm = $('#studentId').val() ? (stuRow($('#studentId').val()) || {}).admission_no : '';
            $('#stuPvName').text($.trim($('#fFullName').val()) || 'New Student');
            $('#stuPvClass').text(cls ? (cls + (sec ? ' – ' + sec : '')) : 'Class not selected');
            $('#stuPvAdm').text(adm || 'Auto');
            $('#stuPvRoll').text($.trim($('#fRollNo').val()) || '—');
            $('#stuPvDob').text(stuPvDate(dob) || '—');
            $('#stuPvGuardian').text($.trim($('#fGuardianName').val()) || $.trim($('#fFatherName').val()) || '—');
            $('#stuPvStatus').text(stuSelText('#fStatus') || 'Active');
        }

        // src null -> back to the placeholder silhouette
        function stuPvPhoto(src) {
            $('#stuPvPhoto').toggleClass('initially-hidden', !src).attr('src', src || '');
            $('#stuPvPlaceholder').toggleClass('initially-hidden', !!src);
        }

        $('#studentForm').on('input change', 'input, select', ORMS.debounce(stuPvRender, 80));

        // local preview only — the file still uploads with the form
        $('#fPhoto').on('change', function () {
            var f = this.files && this.files[0];
            if (!f) { stuPvPhoto(null); return; }
            if (f.size > 10 * 1024 * 1024) { ORMS.err('La foto debe ser menor a 10MB'); this.value = ''; stuPvPhoto(null); return; }
            var fr = new FileReader();
            fr.onload = function (e) { stuPvPhoto(e.target.result); };
            fr.readAsDataURL(f);
        });

        document.getElementById('studentModal').addEventListener('click', function(e) { if (e.target === this) stuCloseModal(); });

        document.getElementById('studentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(this);
            var btn = document.getElementById('btnSaveStudent');
            ORMS.post(isEditMode ? 'updateStudent' : 'addStudent', fd, { btn: btn, busyLabel: 'Saving…' })
                .done(function(res) {
                    if (!res || !res.success) { ORMS.err((res && res.message) || 'Save failed'); return; }
                    stuCloseModal();
                    loadStudents();
                    if (res.credentials) { stuShowCred(res.credentials, true); }
                    else ORMS.ok(res.message || 'Saved');
                })
                .fail(function(msg) { ORMS.err(msg); });
        });

        // ---------- view ----------
        // full profile card — every stored field, grouped. blank stays "—" so a gap reads as deliberate
        // ---- student 360: identity rail on the left, everything else behind tabs on the right ----
        // NOTE the s360- prefixes. page tabs are .tab-btn/.tab-pane and ORMS.syncActions() keys the
        // header toolbar off `.tab-pane.active` — reusing those classes inside the modal would make
        // the header swap toolbars every time someone opened a tab in here.
        function s360Pct(a, b) {
            a = parseFloat(a) || 0; b = parseFloat(b) || 0;
            return b > 0 ? (Math.round(a / b * 1000) / 10) + '%' : '—';
        }

        function s360Table(cols, rows, empty) {
            var E = ORMS.esc;
            if (!rows.length) return '<p class="s360-empty"><i class="fas fa-inbox"></i> ' + E(empty) + '</p>';
            var h = '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>';
            cols.forEach(function (c) { h += '<th>' + E(c) + '</th>'; });
            h += '</tr></thead><tbody>';
            rows.forEach(function (cells) {
                h += '<tr>' + cells.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>';
            });
            return h + '</tbody></table></div>';
        }

        function stuView(id) {
            ORMS.post('getStudent', { id: id }).done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Not found'); return; }
                var r = res.data, S = res.s360 || { can: {} }, CAN = S.can || {}, E = ORMS.esc;

                var val = function (v) {
                    return (v === null || v === undefined || String(v).trim() === '') ? '—' : String(v);
                };
                var d = function (v) { return stuPvDate(String(v || '').slice(0, 10)); };
                var card = function (icon, title, rows) {
                    var h = '<section class="sprof-card"><h4><i class="fas ' + icon + '"></i> ' + E(title) + '</h4>';
                    rows.forEach(function (p) {
                        h += '<div class="sprof-row"><span>' + E(p[0]) + '</span><b>' + E(val(p[1])) + '</b></div>';
                    });
                    return h + '</section>';
                };

                var active = String(r.status || '') === 'Active';
                var acct   = String(r.is_active) === '1';
                var results = S.results || [], att = S.attendance || [], subs = S.subjects || [], fees = S.fees;

                // ---------- left rail ----------
                var latest = results.length ? results[0] : null;
                var attP = 0, attT = 0;
                att.forEach(function (a) { attP += parseFloat(a.days_present) || 0; attT += parseFloat(a.days_total) || 0; });

                var kpis = '';
                if (CAN.results) {
                    kpis += '<div class="s360-kpi"><span>Latest Result</span><b>' +
                            (latest ? E(latest.percentage + '% · ' + val(latest.grade)) : '—') + '</b></div>';
                }
                if (CAN.attendance) {
                    kpis += '<div class="s360-kpi"><span>Attendance</span><b>' + E(attT > 0 ? s360Pct(attP, attT) : '—') + '</b></div>';
                }
                if (CAN.fees && fees) {
                    kpis += '<div class="s360-kpi' + (parseFloat(fees.balance) > 0 ? ' is-owing' : '') + '">' +
                            '<span>Fee Balance</span><b>' + E(fees.balance_txt) + '</b></div>';
                }

                var side =
                    '<aside class="s360-side">' +
                      '<img class="s360-photo" alt="" src="' + E(r.photo || 'icon-192.png') + '"' +
                           ' onerror="this.onerror=null;this.src=\'icon-192.png\'">' +
                      '<h3 class="s360-name">' + E(val(r.full_name)) + '</h3>' +
                      '<p class="s360-sub">' + E(val(r.admission_no)) + '</p>' +
                      '<p class="s360-sub">' + E(val(r.class_name) + ' – ' + val(r.section_name)) +
                          ' &middot; Roll ' + E(val(r.roll_no)) + '</p>' +
                      '<div class="s360-badges">' +
                        '<span class="status-badge ' + (active ? 'status-active' : 'status-inactive') + '">' +
                          '<i class="fas ' + (active ? 'fa-circle-check' : 'fa-circle-minus') + '"></i> ' + E(val(r.status)) + '</span>' +
                        '<span class="status-badge ' + (acct ? 'status-active' : 'status-inactive') + '">' +
                          '<i class="fas fa-right-to-bracket"></i> ' + (acct ? 'Login on' : 'Login off') + '</span>' +
                        (fees && fees.hold ? '<span class="status-badge status-inactive" title="' + E(fees.hold_note || '') +
                          '"><i class="fas fa-hand"></i> Fee hold</span>' : '') +
                      '</div>' +
                      (kpis ? '<div class="s360-kpis">' + kpis + '</div>' : '') +
                      '<div class="s360-facts">' +
                        '<div class="sprof-row"><span>Academic Year</span><b>' + E(val(r.year_name)) + '</b></div>' +
                        '<div class="sprof-row"><span>Date of Birth</span><b>' + E(d(r.dob)) + '</b></div>' +
                        '<div class="sprof-row"><span>Gender</span><b>' + E(val(r.gender)) + '</b></div>' +
                        '<div class="sprof-row"><span>Guardian Phone</span><b>' + E(val(r.guardian_phone)) + '</b></div>' +
                        '<div class="sprof-row"><span>Blood Group</span><b>' + E(val(r.blood_group)) + '</b></div>' +
                      '</div>' +
                    '</aside>';

                // ---------- tabs (a tab this role may not read is never rendered) ----------
                var tabs = [['profile', 'fa-user', 'Profile']];
                if (CAN.results)    tabs.push(['results',    'fa-award',        'Results']);
                if (CAN.attendance) tabs.push(['attendance', 'fa-user-check',   'Attendance']);
                if (CAN.fees)       tabs.push(['fees',       'fa-money-bill',   'Fees']);
                tabs.push(['subjects', 'fa-book',              'Subjects']);
                tabs.push(['account',  'fa-clock-rotate-left', 'Account']);

                var nav = '<div class="s360-tabs">';
                tabs.forEach(function (t, i) {
                    nav += '<button type="button" class="s360-tab' + (i === 0 ? ' active' : '') + '" data-p="' + t[0] + '">' +
                           '<i class="fas ' + t[1] + '"></i> ' + E(t[2]) + '</button>';
                });
                nav += '</div>';

                var pane = function (key, body, first) {
                    return '<section class="s360-pane' + (first ? ' active' : '') + '" data-p="' + key + '">' + body + '</section>';
                };

                // profile
                var profile = '<div class="sprof-grid">' +
                    card('fa-user', 'Personal', [
                        ['Full Name', r.full_name], ['Date of Birth', d(r.dob)], ['Gender', r.gender],
                        ['Blood Group', r.blood_group], ['National ID / B-Form', r.national_id], ['Address', r.address]
                    ]) +
                    card('fa-people-roof', 'Guardian & Contact', [
                        ["Father's Name", r.father_name], ["Mother's Name", r.mother_name],
                        ['Guardian Name', r.guardian_name], ['Guardian Phone', r.guardian_phone],
                        ['Guardian Email', r.guardian_email], ['Student Email', r.email]
                    ]) +
                    card('fa-graduation-cap', 'Enrolment', [
                        ['Class', val(r.class_name) + ' – ' + val(r.section_name)], ['Roll No', r.roll_no],
                        ['Admission No', r.admission_no], ['Academic Year', r.year_name],
                        ['Admission Date', d(r.admission_date)], ['Enrolment Status', r.status]
                    ]) +
                    card('fa-clipboard-list', 'Leaving & Notes', [
                        ['Previous School', r.previous_school], ['Date of Leaving', d(r.date_of_leaving)],
                        ['Leaving Reason', r.leaving_reason], ['Remarks', r.remarks]
                    ]) + '</div>';

                // results
                var resBody = '';
                if (CAN.results) {
                    resBody = s360Table(
                        ['Term', 'Year', 'Marks', '%', 'Grade', 'GPA', 'Position', 'Result', 'Card'],
                        results.map(function (x) {
                            var pass = String(x.result_status || '') === 'PASS';
                            return [
                                '<strong>' + E(x.term_name) + '</strong>', E(x.year_name),
                                E(x.total_obtained + ' / ' + x.total_max), E(x.percentage) + '%',
                                '<span class="subject-chip">' + E(val(x.grade)) + '</span>', E(val(x.gpa)),
                                x.position ? E(x.position + (x.section_total ? ' of ' + x.section_total : '')) : '—',
                                '<span class="status-badge ' + (pass ? 'status-active' : 'status-inactive') + '">' +
                                  E(val(x.result_status)) + '</span>' +
                                  (x.failed_subjects ? '<br><small class="text-muted">' + E(x.failed_subjects) + '</small>' : ''),
                                String(x.is_published) === '1'
                                  ? '<a class="btn btn-secondary btn-sm no-print" target="_blank" rel="noopener" href="result_card.php?student_id=' +
                                    encodeURIComponent(r.id) + '&term_id=' + encodeURIComponent(x.term_id) + '">' +
                                    '<i class="fas fa-file-lines"></i> Open</a>'
                                  : '<span class="text-muted">Unpublished</span>'
                            ];
                        }),
                        'No result summary has been generated for this student yet.');
                }

                // attendance
                var attBody = '';
                if (CAN.attendance) {
                    attBody = s360Table(['Term', 'Year', 'Present', 'Total', 'Attendance', 'Remarks'],
                        att.map(function (a) {
                            return ['<strong>' + E(a.term_name) + '</strong>', E(a.year_name),
                                    E(a.days_present), E(a.days_total),
                                    '<strong>' + E(s360Pct(a.days_present, a.days_total)) + '</strong>',
                                    E(val(a.remarks))];
                        }),
                        'No attendance has been recorded for this student yet.');
                    if (att.length) {
                        attBody = '<div class="info-banner info-banner-top"><i class="fas fa-circle-info"></i>' +
                                  '<span>Overall <b>' + E(s360Pct(attP, attT)) + '</b> across ' + att.length +
                                  ' term(s) — ' + E(attP) + ' of ' + E(attT) + ' days.</span></div>' + attBody;
                    }
                }

                // fees
                var feeBody = '';
                if (CAN.fees) {
                    var owing = fees && parseFloat(fees.balance) > 0;
                    feeBody = '<div class="info-banner info-banner-top' + (owing ? ' info-banner-warning' : '') + '">' +
                              '<i class="fas ' + (owing ? 'fa-hand-holding-dollar' : 'fa-circle-check') + '"></i>' +
                              '<span>Balance for <b>' + E(val(r.year_name)) + '</b>: <b>' +
                              E(fees ? fees.balance_txt : '—') + '</b>' +
                              (fees && fees.hold ? ' &middot; <b>Manual fee hold is on</b>' +
                                  (fees.hold_note ? ' — ' + E(fees.hold_note) : '') : '') +
                              '</span></div>' +
                              s360Table(['Date', 'Year', 'Type', 'Description', 'Reference', 'Amount'],
                                (fees && fees.rows ? fees.rows : []).map(function (f) {
                                    var charge = String(f.entry_type) === 'Charge';
                                    return [E(d(f.entry_date)), E(f.year_name),
                                            '<span class="status-badge ' + (charge ? 'status-inactive' : 'status-active') + '">' +
                                              E(f.entry_type) + '</span>',
                                            E(val(f.description)), E(val(f.reference)),
                                            '<strong>' + E(f.amount_txt || f.amount) + '</strong>'];
                                }),
                                'No fee entries for this student yet.');
                }

                // subjects
                var subBody = s360Table(['Subject', 'Code', 'Type', 'Total', 'Passing', 'Counts in Total', 'Taken'],
                    subs.map(function (x) {
                        var opt = String(x.is_optional) === '1', enrolled = String(x.enrolled) === '1';
                        return ['<strong>' + E(x.name) + '</strong>', E(val(x.code)), E(val(x.subject_type)),
                                E(x.total_marks), E(x.passing_marks),
                                String(x.counted) === '1'
                                  ? '<span class="status-badge status-active">Counted</span>'
                                  : '<span class="status-badge status-inactive">Excluded</span>',
                                opt ? (enrolled
                                        ? '<span class="status-badge status-active"><i class="fas fa-check"></i> Enrolled</span>'
                                        : '<span class="status-badge status-inactive">Opted out</span>')
                                    : '<span class="text-muted">Core</span>'];
                    }),
                    'This class has no subjects mapped yet.');

                // account + audit
                var acctBody = '<div class="sprof-grid">' +
                    card('fa-id-badge', 'Login Account', [
                        ['Username', r.username], ['Login', acct ? 'Enabled' : 'Disabled'],
                        ['Student Email', r.email], ['Marks Records', r.marks_count]
                    ]) +
                    card('fa-clock-rotate-left', 'Audit Trail', [
                        ['Registered By', r.created_by_name], ['Registered On', d(r.created_at)],
                        ['Last Updated By', r.updated_by_name], ['Last Updated On', d(r.updated_at)]
                    ]) + '</div>';

                var panes = pane('profile', profile, true);
                if (CAN.results)    panes += pane('results', resBody);
                if (CAN.attendance) panes += pane('attendance', attBody);
                if (CAN.fees)       panes += pane('fees', feeBody);
                panes += pane('subjects', subBody);
                panes += pane('account', acctBody);

                $('#viewBody').html('<div class="s360">' + side +
                                    '<div class="s360-main">' + nav + '<div class="s360-panes">' + panes + '</div></div>' +
                                    '</div>');
                $('#viewModal').addClass('active');
            }).fail(function (msg) { ORMS.err(msg); });
        }

        // 🚨 delegated from #viewBody, NOT document: the modal shell carries
        // onclick="event.stopPropagation()", so no click inside it ever reaches document and a
        // document-level handler here is silently dead. #viewBody sits below that shell, and it is
        // static markup, so one handler survives every re-render of the panes.
        $('#viewBody').on('click', '.s360-tab', function () {
            var k = this.getAttribute('data-p'), $w = $(this).closest('.s360-main');
            $w.find('.s360-tab').removeClass('active');
            $(this).addClass('active');
            $w.find('.s360-pane').removeClass('active').filter('[data-p="' + k + '"]').addClass('active');
        });

        function stuCloseView() { $('#viewModal').removeClass('active'); }
        document.getElementById('viewModal').addEventListener('click', function(e) { if (e.target === this) stuCloseView(); });

        // ---------- credential slip ----------
        function stuCredHtml(c) {
            return '<div class="cred-slip">' +
                '<div class="cred-slip-head"><i class="fas fa-graduation-cap"></i> ' + ORMS.esc(c.school || '') + '</div>' +
                '<div class="cred-row"><span class="cred-label">Student</span><span class="cred-value">' + ORMS.esc(c.name || '') + '</span></div>' +
                '<div class="cred-row"><span class="cred-label">Admission No</span><span class="cred-value">' + ORMS.esc(c.admission_no || '') + '</span></div>' +
                '<div class="cred-row"><span class="cred-label">Username</span><span class="cred-value">' + ORMS.esc(c.username || '') + '</span></div>' +
                '<div class="cred-row"><span class="cred-label">Password</span><span class="cred-value">' + ORMS.esc(c.password || '') + '</span></div>' +
                '<div class="cred-row"><span class="cred-label">Note</span><span class="cred-value">Please change this password after the first login</span></div>' +
                '</div>';
        }

        function stuShowCred(c, saved) {
            var html = stuCredHtml(c);
            $('#credBody').html(html);
            $('#credPrintArea').html(html);
            $('#credModal').addClass('active');
            if (saved) ORMS.ok('Student registered');
        }

        function stuCloseCred() { $('#credModal').removeClass('active'); }
        document.getElementById('credModal').addEventListener('click', function(e) { if (e.target === this) stuCloseCred(); });

        // printOnly hides every sibling up the tree; printable-modal keeps the overlay itself
        // visible. a bare window.print() printed a blank page — the print css hides .modal-overlay
        function stuPrintCred() { ORMS.printOnly('#credBody'); }

        // roster slip — shows the default password, valid until the student changes it
        function stuSlipFor(id) {
            var r = stuRow(id);
            if (!r) return;
            stuShowCred({ school: <?php echo json_encode((string) getSetting('result_school_name', getSiteBranding()['site_name'])); ?>,
                          name: r.full_name, admission_no: r.admission_no, username: r.username, password: DEF_PWD }, false);
        }

        // ---------- reset password ----------
        function stuResetPwd(id, btn) {
            var r = stuRow(id);
            Swal.fire({
                icon: 'question', title: 'Reset password?',
                text: 'The password for ' + (r ? r.full_name : 'this student') + ' will be set back to the default.',
                showCancelButton: true, confirmButtonText: '<i class="fas fa-key"></i> Reset', cancelButtonText: 'Cancel'
            }).then(function(x) {
                if (!x.isConfirmed) return;
                // icon-only row button -> spin the icon itself, no verb label
                ORMS.post('resetPassword', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                    if (!res || !res.success) { ORMS.err((res && res.message) || 'Reset failed'); return; }
                    if (res.credentials) stuShowCred(res.credentials, false);
                    ORMS.ok(res.message || 'Password reset');
                }).fail(function(msg) { ORMS.err(msg); });
            });
        }

        // ---------- status change (soft delete) ----------
        function stuStatusChange(id) {
            var r = stuRow(id);
            if (!r) return;
            statusForId = id;
            $('#stWho').html('<i class="fas fa-user-graduate"></i> <span>' + ORMS.esc(r.full_name) + ' (' + ORMS.esc(r.admission_no) + ')</span>');
            $('#stStatus').val(r.status);
            $('#stLeaveDate').val(r.date_of_leaving || '');
            $('#stLeaveReason').val(r.leaving_reason || '');
            $('#stLeavingBlock').toggle(stuIsLeaver(r.status));
            ORMS.dropdown.refresh('#stStatus');
            $('#statusModal').addClass('active');
        }

        function stuCloseStatus() { $('#statusModal').removeClass('active'); statusForId = 0; }
        document.getElementById('statusModal').addEventListener('click', function(e) { if (e.target === this) stuCloseStatus(); });

        function stuSaveStatus(btn) {
            var r = stuRow(statusForId), st = $('#stStatus').val();
            if (!r || !st) return;
            // leaving fields only travel with a leaver — the server clears them for any other status anyway
            ORMS.post('setStatus', {
                id: statusForId, status: st,
                date_of_leaving: stuIsLeaver(st) ? ($('#stLeaveDate').val() || '') : '',
                leaving_reason:  stuIsLeaver(st) ? ($('#stLeaveReason').val() || '') : ''
            }, { btn: btn, busyLabel: 'Saving…' }).done(function(res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Update failed'); return; }
                stuCloseStatus();
                ORMS.ok(res.message || 'Status changed');
                loadStudents();
            }).fail(function(msg) { ORMS.err(msg); });
        }

        // ---------- hard delete ----------
        function stuDelete(id, btn) {
            var r = stuRow(id);
            ORMS.confirmDelete('Permanently delete ' + (r ? r.full_name : 'this student') + ' and their login? Students with marks cannot be deleted — change the status instead.')
                .then(function(yes) {
                    if (!yes) return;
                    ORMS.post('deleteStudent', { id: id }, { btn: btn, busyLabel: ' ' }).done(function(res) {
                        if (!res || !res.success) { ORMS.err((res && res.message) || 'Delete failed', 'Cannot delete'); return; }
                        ORMS.ok(res.message || 'Student deleted');
                        loadStudents();
                    }).fail(function(msg) { ORMS.err(msg); });
                });
        }

        // ---------- csv template + import ----------
        var STU_SYNONYMS = [
            ['nombre_completo', 'nombre', 'nombres', 'estudiante', 'alumno', 'full_name', 'name'],
            ['nombre_padre', 'padre', 'papa', 'father_name', 'father'],
            ['fecha_nacimiento', 'fecha_de_nacimiento', 'nacimiento', 'dob', 'birth_date'],
            ['genero', 'sexo', 'gender', 'sex'],
            ['telefono_acudiente', 'telefono', 'celular', 'movil', 'celular_acudiente', 'guardian_phone', 'phone'],
            ['direccion', 'domicilio', 'residencia', 'address'],
            ['grado', 'curso', 'clase', 'class', 'grade'],
            ['seccion', 'grupo', 'aula', 'section'],
            ['numero_lista', 'num_lista', 'lista', 'orden', 'roll_no', 'roll'],
            ['fecha_matricula', 'fecha_de_matricula', 'fecha_ingreso', 'admission_date'],
            ['numero_matricula', 'matricula', 'num_matricula', 'codigo', 'codigo_estudiante', 'admission_no'],
            ['nombre_madre', 'madre', 'mama', 'mother_name', 'mother'],
            ['nombre_acudiente', 'acudiente', 'tutor', 'representante', 'guardian_name', 'guardian'],
            ['correo_acudiente', 'correo', 'email', 'email_acudiente', 'guardian_email'],
            ['documento_identidad', 'documento', 'cedula', 'ti', 'tarjeta_identidad', 'dni', 'identificacion', 'national_id'],
            ['grupo_sanguineo', 'tipo_sangre', 'rh', 'sangre', 'blood_group', 'blood'],
            ['colegio_anterior', 'escuela_anterior', 'institucion_anterior', 'previous_school'],
            ['observaciones', 'observacion', 'notas', 'nota', 'remarks']
        ];

        function stuCleanHead(s) {
            return String(s || '').trim().toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9_]/g, '_');
        }

        function stuHeaderMatches(head) {
            if (!head || !head.length) return false;
            var hClean = head.map(stuCleanHead);
            if (hClean.length !== 18) return false;
            var esClean = CSV_HEAD_ES.map(stuCleanHead);
            var enClean = CSV_HEAD_EN.map(stuCleanHead);
            if (hClean.join('|') === esClean.join('|') || hClean.join('|') === enClean.join('|')) return true;
            for (var c = 0; c < 18; c++) {
                if (STU_SYNONYMS[c].indexOf(hClean[c]) === -1) return false;
            }
            return true;
        }

        function stuTemplate() {
            ORMS.downloadCSV('plantilla_importar_estudiantes.csv', [
                CSV_HEAD_ES,
                ['Estudiante Ejemplo', 'Juan Pérez', '2014-05-10', 'Masculino', '300-1234567', 'Calle 10 # 20-30', 'Grado 1', '1-A', '1', '2026-02-01', 'EST-2026-001',
                 'María Gómez', 'Juan Pérez', 'acudiente@ejemplo.com', '1098765432', 'O+', 'Institución Educativa Anterior', 'Estudiante nuevo']
            ]);
            ORMS.ok('Plantilla descargada con éxito');
        }

        document.getElementById('studentCsvInput').addEventListener('change', function() {
            var file = this.files && this.files[0];
            var input = this;
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(ev) {
                input.value = '';
                var rows = ORMS.parseCSV(String(ev.target.result || ''));
                if (!rows.length) { ORMS.err('El archivo seleccionado está vacío'); return; }

                var head = rows[0] || [];
                if (!stuHeaderMatches(head)) {
                    ORMS.err('La fila de encabezados debe coincidir con la plantilla:<br><br><b>' + CSV_HEAD_ES.join(', ') + '</b>', 'Formato CSV incorrecto');
                    return;
                }

                var body = rows.slice(1).filter(function(r) { return (r || []).join('').trim() !== ''; });
                if (!body.length) { ORMS.err('No se encontraron filas con datos debajo del encabezado'); return; }

                var yr = $('#filterYear').val() || $('#fYear').val();
                if (!yr) { ORMS.err('Seleccione un año académico en los filtros primero — los estudiantes importados se registrarán en él'); return; }
                var yrName = $('#filterYear option:selected').text();

                Swal.fire({
                    icon: 'question', title: '¿Importar ' + body.length + ' estudiante(s)?',
                    html: 'Se registrarán en el año académico <b>' + ORMS.esc(yrName) + '</b>.<br>Las filas duplicadas o con errores serán omitidas y reportadas.',
                    showCancelButton: true, confirmButtonText: '<i class="fas fa-file-import"></i> Importar', cancelButtonText: 'Cancelar'
                }).then(function(x) {
                    if (!x.isConfirmed) return;
                    // longest write on the page — the toolbar button carries the spinner
                    ORMS.post('bulkImportStudents', { academic_year_id: yr, rows: JSON.stringify(body) },
                              { btn: '#btnImportStudents', busyLabel: 'Importando…' }).done(function(res) {
                        if (!res || !res.success) { ORMS.err((res && res.message) || 'Error en la importación'); return; }
                        stuImportReport(res);
                        loadStudents();
                        Swal.fire({
                            icon: res.skipped ? 'warning' : 'success',
                            title: res.imported + ' importados, ' + res.skipped + ' omitidos',
                            text: res.skipped ? 'Las filas omitidas se detallan en el cuadro sobre la tabla.' : 'Todos los registros se importaron correctamente.'
                        });
                    }).fail(function(msg) { ORMS.err(msg); });
                });
            };
            reader.readAsText(file);
        });

        function stuImportReport(res) {
            var box = $('#importResult');
            if (!res.errors || !res.errors.length) {
                box.html('<div class="info-banner"><i class="fas fa-circle-check"></i> <span>' + res.imported + ' estudiante(s) importados correctamente sin omisiones.</span></div>').show();
                return;
            }
            var html = '<div class="info-banner info-banner-warning"><i class="fas fa-triangle-exclamation"></i> <span>' +
                res.imported + ' importado(s), ' + res.skipped + ' omitido(s) — las filas mostradas a continuación no se guardaron.</span></div>' +
                '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr><th><i class="fas fa-list-ol"></i> #</th><th><i class="fas fa-circle-exclamation"></i> Motivo</th></tr></thead><tbody>';
            res.errors.forEach(function(e, i) { html += '<tr><td>' + (i + 1) + '</td><td>' + ORMS.esc(e) + '</td></tr>'; });
            html += '</tbody></table></div>';
            box.html(html).show();
        }

        // ---------- promotion ----------
        function stuPreview(btn) {
            var cid = $('#srcClass').val(), sid = $('#srcSection').val(), yid = $('#srcYear').val();
            if (!cid || !sid || !yid) { ORMS.err('Pick the source year, class and section first'); return; }
            ORMS.post('promotePreview', { class_id: cid, section_id: sid, academic_year_id: yid }, { btn: btn, busyLabel: 'Loading…' })
                .done(function(res) {
                    if (!res || !res.success) { ORMS.err((res && res.message) || 'Preview failed'); return; }
                    previewRows = res.data || [];
                    stuRenderPreview();
                }).fail(function(msg) { ORMS.err(msg); });
        }

        function stuRenderPreview() {
            if (!previewRows.length) {
                $('#promoteBox').html('<div class="orms-empty"><i class="fas fa-user-slash"></i><h4>No active students</h4><p>That class, section and year has no active students to promote.</p></div>');
                return;
            }
            var html = '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' +
                '<th><input type="checkbox" id="chkAll" checked onclick="stuToggleAll(this)"></th>' +
                '<th><i class="fas fa-hashtag"></i> Roll</th><th><i class="fas fa-id-card"></i> Admission No</th><th><i class="fas fa-user"></i> Name</th>' +
                '</tr></thead><tbody>';
            previewRows.forEach(function(r) {
                html += '<tr><td><input type="checkbox" class="promo-chk" value="' + r.id + '" checked></td>' +
                        '<td>' + ORMS.esc(r.roll_no || '—') + '</td><td>' + ORMS.esc(r.admission_no) + '</td><td>' + ORMS.esc(r.full_name) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            $('#promoteBox').html(html);
        }

        function stuToggleAll(el) { $('.promo-chk').prop('checked', el.checked); }

        function stuPromote(btn) {
            var ids = $('.promo-chk:checked').map(function() { return this.value; }).get();
            if (!ids.length) { ORMS.err('Preview a section and tick at least one student'); return; }
            var tc = $('#tgtClass').val(), ts = $('#tgtSection').val(), ty = $('#tgtYear').val();
            if (!tc || !ts || !ty) { ORMS.err('Pick the target year, class and section'); return; }

            Swal.fire({
                icon: 'question', title: 'Promote ' + ids.length + ' student(s)?',
                html: 'They move to <b>' + ORMS.esc($('#tgtClass option:selected').text() + ' - ' + $('#tgtSection option:selected').text()) +
                      '</b> (' + ORMS.esc($('#tgtYear option:selected').text()) + ').<br>Marks history stays exactly as it is.',
                showCancelButton: true, confirmButtonText: '<i class="fas fa-arrow-up-right-dots"></i> Promote', cancelButtonText: 'Cancel'
            }).then(function(x) {
                if (!x.isConfirmed) return;
                ORMS.post('promoteStudents', {
                    ids: JSON.stringify(ids), target_class_id: tc, target_section_id: ts, target_year_id: ty
                }, { btn: btn, busyLabel: 'Promoting…' }).done(function(res) {
                    if (!res || !res.success) { ORMS.err((res && res.message) || 'Promotion failed'); return; }
                    var extra = (res.errors && res.errors.length) ? '<br><br>' + res.errors.map(ORMS.esc).join('<br>') : '';
                    Swal.fire({ icon: res.skipped ? 'warning' : 'success',
                                title: res.promoted + ' promoted, ' + res.skipped + ' skipped', html: 'Marks history untouched.' + extra });
                    previewRows = [];
                    $('#promoteBox').html('<div class="orms-empty"><i class="fas fa-circle-check"></i><h4>Promotion done</h4><p>Preview another section to promote more students.</p></div>');
                    loadStudents();
                }).fail(function(msg) { ORMS.err(msg); });
            });
        }
    </script>
</body>
</html>
