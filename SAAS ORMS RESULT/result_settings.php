<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';
require_once 'result_engine.php';   // template registry lives with the card renderers

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$isAjax = isset($_GET['action']) || isset($_POST['action']);

if (!checkSessionTimeout()) {
    if ($isAjax) jsonErr('Session expired. Please log in again.');
    header("Location: login.php");
    exit();
}

// rbac view gate
requirePerm('result_settings', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'result_settings';

// csrf + edit perm — first two lines of every write
function rsGuard(): void {
    requireCsrfJson();
    requirePermJson('result_settings', 'e');
}

// log + json ok in one move
function rsOk(string $act, string $details, array $extra = []): void {
    global $user_id, $username;
    logActivity($user_id, $username, $act, $details);
    jsonOk($extra);
}

// 90.00 -> 90, 89.50 -> 89.5
function rsNum($v): string {
    $s = number_format((float)$v, 2, '.', '');
    return strpos($s, '.') === false ? $s : rtrim(rtrim($s, '0'), '.');
}

// gaps + overlaps across the whole scheme — resolver takes the highest band whose min <= pct,
// so a gap silently widens the band below it instead of erroring
function rsGradingIssues(array $bands): array {
    $out = [];
    if (!$bands) return ['No grade bands defined yet — every result card will print a blank grade.'];

    usort($bands, fn($a, $b) => (float)$a['min_percent'] <=> (float)$b['min_percent']);
    $n = count($bands);

    if ((float)$bands[0]['min_percent'] > 0) {
        $out[] = 'Not covered: 0 to ' . rsNum($bands[0]['min_percent']) . '% — marks below the lowest band "'
               . $bands[0]['grade'] . '" resolve to no grade at all.';
    }

    for ($i = 0; $i < $n - 1; $i++) {
        $hi = (float)$bands[$i]['max_percent'];
        $lo = (float)$bands[$i + 1]['min_percent'];
        if ($hi >= $lo) {
            $out[] = 'Overlap between "' . $bands[$i]['grade'] . '" (up to ' . rsNum($hi) . ') and "'
                   . $bands[$i + 1]['grade'] . '" (from ' . rsNum($lo) . ') — the same mark matches two grades.';
        } elseif ($lo - $hi > 0.01) {
            $out[] = 'Gap between ' . rsNum($hi) . ' and ' . rsNum($lo) . ' — marks in that range fall back to "'
                   . $bands[$i]['grade'] . '".';
        }
    }

    $top = (float)$bands[$n - 1]['max_percent'];
    if ($top < 100) {
        $out[] = 'Top band "' . $bands[$n - 1]['grade'] . '" ends at ' . rsNum($top)
               . '% — anything above that still resolves to it, but 100% is not explicitly covered.';
    }
    // no failing band = the card can never print a fail grade
    if (array_key_exists('is_fail', $bands[0]) && !array_filter($bands, fn($b) => (int)$b['is_fail'] === 1)) {
        $out[] = 'No band is marked as a failing grade — nothing on the scheme counts as a fail.';
    }
    return $out;
}

// migration probes — sets + components arrive with update_setup.php; before that the tabs just say so
function rsHasSets(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (function_exists('ormsHasGradingSets')) return $ok = ormsHasGradingSets();
    try { qVal("SELECT set_id FROM grading_scheme LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; }
    return $ok;
}

function rsHasAsmt(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (function_exists('ormsHasAssessment')) return $ok = ormsHasAssessment();
    try { qVal("SELECT 1 FROM assessment_schemes LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; }
    return $ok;
}

// write gates — a mutation on a missing table would only ever be a confusing 500
function rsNeedSets(): void {
    if (!rsHasSets()) jsonErr('Grading sets are not installed yet. Run update_setup.php once, then reload this page.');
}

function rsNeedAsmt(): void {
    if (!rsHasAsmt()) jsonErr('Assessment schemes are not installed yet. Run update_setup.php once, then reload this page.');
}

// ---- tenant scope. school_id / branch_id land with update_setup.php, so every predicate below
// collapses to nothing on a pre-migration db and the same call site runs on both schemas
function rsMulti(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { qVal("SELECT school_id FROM academic_years LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }        // single-school install, nothing to scope
    return $ok;
}

// predicate + its bind letters + its bind values. always the SAME value, so a query carrying
// the predicate more than once can bind rsT(n)/rsA(n) without worrying about placeholder order
function rsW(string $a = ''): string { return rsMulti() ? ' AND ' . ($a ? $a . '.' : '') . 'school_id = ?' : ''; }
function rsT(int $n = 1): string     { return rsMulti() ? str_repeat('i', $n) : ''; }
function rsA(int $n = 1): array      { return rsMulti() ? array_fill(0, $n, sid()) : []; }

// classes carry a branch too — a branch admin only ever lists or edits their own
function rsClsW(string $a = ''): string { return rsW($a) . (rsMulti() && ormsBranchLock() ? ' AND ' . ($a ? $a . '.' : '') . 'branch_id = ?' : ''); }
function rsClsT(): string { return rsT() . (rsMulti() && ormsBranchLock() ? 'i' : ''); }
function rsClsA(): array  { return array_merge(rsA(), rsMulti() && ormsBranchLock() ? [ormsBranchLock()] : []); }

// existence is not ownership — every id off the wire is resolved against THIS school first
function rsOwn(string $t, $raw): int {
    $id = (int)$raw;
    if ($id <= 0 || !in_array($t, ['academic_years', 'grading_sets', 'assessment_schemes'], true)) return 0;  // fixed list, never from input
    return qVal("SELECT id FROM `$t` WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA()) ? $id : 0;
}

// flagged default -> lowest id, always inside this school. 0 = this school owns no set yet
function rsDefaultSet(): int {
    return (int)(qVal("SELECT id FROM grading_sets WHERE is_default = 1" . rsW() . " ORDER BY id ASC LIMIT 1", rsT(), ...rsA())
              ?: qVal("SELECT id FROM grading_sets WHERE 1" . rsW() . " ORDER BY id ASC LIMIT 1", rsT(), ...rsA()) ?: 0);
}

// requested set, falling back to our default when it is gone, bogus or another school's
function rsPickSet($raw): int {
    return rsOwn('grading_sets', $raw) ?: rsDefaultSet();
}

// sets + band count and where each one is already committed
function rsSets(): array {
    return qAll("SELECT s.id, s.name, s.description, s.is_default, s.is_active,
                        (SELECT COUNT(*) FROM grading_scheme g  WHERE g.set_id = s.id)         AS bands,
                        (SELECT COUNT(*) FROM classes c         WHERE c.grading_set_id = s.id" . rsW('c') . ") AS classes,
                        (SELECT COUNT(*) FROM result_summaries r WHERE r.grading_set_id = s.id" . rsW('r') . ") AS cards
                 FROM grading_sets s WHERE 1" . rsW('s') . "
                 ORDER BY s.is_default DESC, s.name ASC", rsT(3), ...rsA(3));
}

// bands of ONE set — every band query on this page goes through here. the set carries the tenant
function rsBands(int $setId): array {
    return qAll("SELECT g.id, g.set_id, g.grade, g.min_percent, g.max_percent, g.grade_point, g.remarks, g.interpretation, g.sort_order, g.is_fail, g.color
                 FROM grading_scheme g JOIN grading_sets s ON s.id = g.set_id
                 WHERE g.set_id = ?" . rsW('s') . " ORDER BY g.sort_order ASC, g.min_percent DESC", 'i' . rsT(), $setId, ...rsA());
}

// assessment schemes + component count, weight total and usage
function rsSchemes(): array {
    return qAll("SELECT s.id, s.name, s.description, s.is_active,
                        (SELECT COUNT(*) FROM assessment_components c WHERE c.scheme_id = s.id) AS comps,
                        (SELECT COALESCE(SUM(c2.weight_percent), 0) FROM assessment_components c2 WHERE c2.scheme_id = s.id) AS weight,
                        (SELECT COUNT(*) FROM classes cl WHERE cl.assessment_scheme_id = s.id" . rsW('cl') . ") AS classes,
                        (SELECT COUNT(*) FROM marks m   WHERE m.assessment_scheme_id = s.id"  . rsW('m')  . ")  AS marks
                 FROM assessment_schemes s WHERE 1" . rsW('s') . "
                 ORDER BY s.is_active DESC, s.name ASC", rsT(3), ...rsA(3));
}

function rsPickScheme($raw): int {
    return rsOwn('assessment_schemes', $raw)
        ?: (int)(qVal("SELECT id FROM assessment_schemes WHERE 1" . rsW() . " ORDER BY is_active DESC, id ASC LIMIT 1", rsT(), ...rsA()) ?: 0);
}

// components follow their scheme, and the scheme carries the tenant
function rsComps(int $schemeId): array {
    return qAll("SELECT c.id, c.scheme_id, c.name, c.max_marks, c.weight_percent, c.is_exam, c.sort_order
                 FROM assessment_components c JOIN assessment_schemes s ON s.id = c.scheme_id
                 WHERE c.scheme_id = ?" . rsW('s') . " ORDER BY c.sort_order ASC, c.id ASC", 'i' . rsT(), $schemeId, ...rsA());
}

function rsCompWeight(int $schemeId): float {
    return round((float)qVal("SELECT COALESCE(SUM(c.weight_percent), 0) FROM assessment_components c
                              JOIN assessment_schemes s ON s.id = c.scheme_id
                              WHERE c.scheme_id = ?" . rsW('s'), 'i' . rsT(), $schemeId, ...rsA()), 2);
}

// plain-english weight warning — '' when the split lands exactly on 100
function rsWeightWarn(float $sum): string {
    if (abs($sum - 100) < 0.01) return '';
    return $sum < 100
        ? 'Weights add up to ' . rsNum($sum) . '% — a student scoring full marks in every component would only reach ' . rsNum($sum) . ' out of 100.'
        : 'Weights add up to ' . rsNum($sum) . '% — a student scoring full marks in every component would reach ' . rsNum($sum) . ', more than the 100 the result card expects.';
}

// hex colour or null. blank clears the tint and the card falls back to the theme
function rsColor($raw): ?string {
    $v = strtolower(trim((string)$raw));
    return $v !== '' && preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
}

// branding images live per school — uploads/{school}/branding/. pre-migration installs keep the flat folder
function rsBrandDir(): string {
    return rsMulti() ? 'uploads/' . sid() . '/branding/' : 'uploads/branding/';
}

// is this exact file still pointed at by ANOTHER school's branding row? legacy flat uploads
// predate the per-school split, so two rows can share one file
function rsImageShared(string $rel): bool {
    if (!rsMulti() || $rel === '') return false;
    try {
        return (int)qVal("SELECT COUNT(*) FROM system_settings WHERE setting_value = ? AND school_id <> ?
                          AND setting_key IN ('result_logo', 'result_principal_signature', 'site_logo')", 'si', $rel, sid()) > 0;
    } catch (Throwable $e) { return true; }      // cannot tell -> keep the file
}

// unlink a stored branding image, but only a branding image and only inside our own uploads folder.
// legacy flat paths still match so replacing an old logo cleans the old file up — unless another
// school is still printing it
function rsDropImage(string $rel): void {
    if ($rel === '' || strpos($rel, 'uploads/') !== 0 || strpos($rel, 'branding/') === false) return;
    $root = realpath(__DIR__ . '/uploads');
    $abs  = realpath(__DIR__ . '/' . $rel);
    if (!$root || !$abs || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0) return;   // outside uploads -> never touch
    if (strpos($rel, rsBrandDir()) !== 0 && rsImageShared($rel)) return;              // legacy file, still in use
    if (is_file($abs)) @unlink($abs);
}

// one upload path for every branding image — same whitelist, cap and folder.
// returns [relative path, "WxH"]; jsonErr() exits on anything suspicious
function rsSaveImage(?array $f, string $key, string $label): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jsonErr('No image received. Pick a file and try again.');
    if ($f['size'] > 2 * 1024 * 1024) jsonErr($label . ' must be under 2MB');

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) jsonErr('Only JPG, PNG or WEBP images are allowed');

    $dim = @getimagesize($f['tmp_name']);            // renamed script would fail here
    if (!$dim || empty($dim[0]) || empty($dim[1])) jsonErr('That file is not a real image');

    $rdir = rsBrandDir();
    $dir  = __DIR__ . '/' . $rdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) jsonErr('Could not create ' . $rdir . ' — check folder permissions');

    $fname = $key . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];  // never trust the client name
    if (!move_uploaded_file($f['tmp_name'], $dir . $fname)) jsonErr('Upload failed — check folder permissions');

    rsDropImage((string)getSetting($key, ''));       // drop the replaced file
    $rel = $rdir . $fname;
    setSetting($key, $rel);
    return [$rel, $dim[0] . 'x' . $dim[1]];
}

// settings the branding tab owns
$BRAND_KEYS = [
    'result_school_name'     => 150,
    'result_school_address'  => 255,
    'result_school_phone'    => 40,
    'result_footer_note'     => 255,
    'result_signature_left'  => 60,
    'result_signature_right' => 60,
    'result_title'           => 60,
    'result_principal_name'        => 120,
    'result_principal_designation' => 60,
];

// card layout switches the branding tab also owns — every one is read by ormsRenderResultCard()
$CARD_TOGGLES = [
    'result_show_photo'       => 'Student photo',
    'result_show_dob'         => 'Date of birth',
    'result_show_grade_col'   => 'Grade column',
    'result_show_remarks_col' => 'Remarks column',
    'result_show_gpa'         => 'GPA in summary',
    'result_show_position'    => 'Position in summary',
    'result_show_failed_line' => 'Failed-subjects line',
    'result_show_signatures'  => 'Signature lines',
    'result_show_footer'      => 'Footer note',
    'result_show_qr'          => 'Verification QR code',
    'result_show_principal_sign'   => 'Head teacher name + signature',
    'result_show_principal_remark' => 'Head teacher remark',
];

// ---------------------------------------------------------------- ajax router
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    try {
        switch ($action) {

            // ---------------- branding
            case 'saveBranding':
                rsGuard();
                $name = trim($_POST['result_school_name'] ?? '');
                if ($name === '') jsonErr('School name is required — it is the headline on every result card.');
                $vals = [];                                   // validate everything before writing anything
                foreach ($BRAND_KEYS as $k => $max) {
                    $v = trim($_POST[$k] ?? '');
                    if (mb_strlen($v) > $max) jsonErr(str_replace('_', ' ', $k) . " must be {$max} characters or less");
                    $vals[$k] = $v;
                }
                $accent = trim($_POST['result_accent_color'] ?? '');
                if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) jsonErr('Accent colour must be a 6-digit hex like #001f3f');
                $vals['result_accent_color'] = $accent !== '' ? strtolower($accent) : '#001f3f';
                foreach (array_keys($CARD_TOGGLES) as $tk) {                 // checkbox absent = off
                    $vals[$tk] = (isset($_POST[$tk]) && $_POST[$tk] === '1') ? '1' : '0';
                }
                foreach ($vals as $k => $v) setSetting($k, $v);
                rsOk('Result Branding Updated', 'Result card branding saved: ' . $name);
                break;

            case 'uploadLogo':
                rsGuard();
                [$rel, $dim] = rsSaveImage($_FILES['logo_file'] ?? null, 'result_logo', 'Logo');
                rsOk('Result Logo Updated', 'Uploaded result card logo (' . $dim . ')', ['logo' => $rel]);
                break;

            case 'removeLogo':
                rsGuard();
                $old = (string)getSetting('result_logo', '');
                if ($old === '') jsonErr('There is no result logo to remove');
                rsDropImage($old);
                setSetting('result_logo', '');
                rsOk('Result Logo Removed', 'Cleared result card logo — cards fall back to the site logo');
                break;

            case 'uploadPrincipalSign':
                rsGuard();
                [$rel, $dim] = rsSaveImage($_FILES['sign_file'] ?? null, 'result_principal_signature', 'Signature');
                rsOk('Principal Signature Updated', 'Uploaded head teacher signature (' . $dim . ')', ['sign' => $rel]);
                break;

            case 'removePrincipalSign':
                rsGuard();
                $old = (string)getSetting('result_principal_signature', '');
                if ($old === '') jsonErr('There is no head teacher signature to remove');
                rsDropImage($old);
                setSetting('result_principal_signature', '');
                rsOk('Principal Signature Removed', 'Cleared head teacher signature — only the signature line prints');
                break;

            // ---------------- templates
            case 'saveTemplates':
                rsGuard();
                $reg = ormsTemplates();
                $tpl = trim($_POST['result_template'] ?? 'classic');
                if (!isset($reg[$tpl])) jsonErr('Unknown template');

                // validate everything before writing anything. class ids come off the wire, so they
                // are checked against OUR classes — another school's class id must never reach the update
                $rows  = is_array($_POST['class_tpl'] ?? null) ? $_POST['class_tpl'] : [];
                $okCls = array_flip(array_map('intval', array_column(
                    qAll("SELECT id FROM classes WHERE 1" . rsClsW(), rsClsT(), ...rsClsA()), 'id')));
                $clean = [];
                foreach ($rows as $cid => $t) {
                    $cid = (int)$cid; $t = trim((string)$t);
                    if ($cid <= 0) continue;
                    if (!isset($okCls[$cid])) jsonErr('Unknown class in the template list');
                    if ($t !== '' && !isset($reg[$t])) jsonErr('Unknown template picked for a class');
                    $clean[$cid] = $t;                               // '' = follow the default
                }

                setSetting('result_template', $tpl);
                foreach ($clean as $cid => $t) {
                    qExec("UPDATE classes SET result_template = ? WHERE id = ?" . rsClsW(), 'si' . rsClsT(), $t === '' ? null : $t, $cid, ...rsClsA());
                }
                rsOk('Result Templates Updated',
                     'Default: ' . $reg[$tpl]['label'] . ', class overrides: ' . count(array_filter($clean)));
                break;

            // ---------------- academic years
            case 'getYears':
                requirePermJson('result_settings', 'v');
                jsonOk(['data' => qAll(
                    "SELECT y.id, y.name, y.start_date, y.end_date, y.is_current, y.is_locked,
                            (SELECT COUNT(*) FROM exam_terms t WHERE t.academic_year_id = y.id) AS terms,
                            (SELECT COUNT(*) FROM students s WHERE s.academic_year_id = y.id" . rsW('s') . ") AS students,
                            (SELECT COUNT(*) FROM marks m WHERE m.academic_year_id = y.id" . rsW('m') . ") AS marks
                     FROM academic_years y WHERE 1" . rsW('y') . "
                     ORDER BY y.is_current DESC, y.start_date DESC, y.id DESC", rsT(3), ...rsA(3))]);
                break;

            case 'saveYear':
                rsGuard();
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $sd   = trim($_POST['start_date'] ?? '');
                $ed   = trim($_POST['end_date'] ?? '');
                $cur  = !empty($_POST['is_current']) ? 1 : 0;

                if ($name === '') jsonErr('Year name is required (e.g. 2025-2026)');
                if (mb_strlen($name) > 20) jsonErr('Year name must be 20 characters or less');
                if ($sd !== '' && $ed !== '' && $sd > $ed) jsonErr('End date cannot be before the start date');
                if ($id > 0 && !rsOwn('academic_years', $id)) jsonErr('Academic year not found');   // edit -> must be ours
                // names are unique per school, so another school's 2025-2026 is not a clash
                if (qVal("SELECT id FROM academic_years WHERE name = ? AND id <> ?" . rsW(), 'si' . rsT(), $name, $id, ...rsA())) {
                    jsonErr('An academic year named "' . $name . '" already exists');
                }
                // one current year only — never let the app end up with zero
                if (!$cur && $id > 0 && (int)qVal("SELECT is_current FROM academic_years WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA()) === 1) {
                    jsonErr('One year must always stay current. Set another year as current instead.');
                }
                // a locked year is closed — it can never become the current one
                if ($cur && $id > 0 && (int)qVal("SELECT is_locked FROM academic_years WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA()) === 1) {
                    jsonErr('"' . $name . '" is locked. Unlock it before making it the current year.');
                }

                $sdv = $sd !== '' ? $sd : null;
                $edv = $ed !== '' ? $ed : null;
                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    if ($id > 0) {
                        qExec("UPDATE academic_years SET name = ?, start_date = ?, end_date = ? WHERE id = ?" . rsW(),
                              'sssi' . rsT(), $name, $sdv, $edv, $id, ...rsA());
                    } else {
                        $id = qInsert("INSERT INTO academic_years (name, start_date, end_date, is_current" . (rsMulti() ? ', school_id' : '') . ")
                                       VALUES (?, ?, ?, 0" . (rsMulti() ? ', ?' : '') . ")",
                                      'sss' . rsT(), $name, $sdv, $edv, ...rsA());
                    }
                    if ($cur) {                                   // clear ours, then set exactly one — never another school's
                        qExec("UPDATE academic_years SET is_current = 0 WHERE is_current = 1" . rsW(), rsT(), ...rsA());
                        qExec("UPDATE academic_years SET is_current = 1 WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                rsOk('Academic Year Saved', 'Saved academic year: ' . $name . ($cur ? ' (set current)' : ''));
                break;

            case 'setCurrentYear':
                rsGuard();
                $id = (int)($_POST['id'] ?? 0);
                $y  = qOne("SELECT name, is_locked FROM academic_years WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$y) jsonErr('Academic year not found');
                if ((int)$y['is_locked'] === 1)
                    jsonErr('"' . $y['name'] . '" is locked — that year is closed. Unlock it first if you really need it to be the current year.');

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    qExec("UPDATE academic_years SET is_current = 0 WHERE is_current = 1" . rsW(), rsT(), ...rsA());
                    qExec("UPDATE academic_years SET is_current = 1 WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                rsOk('Current Year Changed', 'Set current academic year: ' . $y['name']);
                break;

            // year-end close — the current year is never lockable
            case 'setYearLock':
                rsGuard();
                $id   = (int)($_POST['id'] ?? 0);
                $lock = !empty($_POST['is_locked']) ? 1 : 0;
                $y    = qOne("SELECT name, is_current, is_locked FROM academic_years WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$y) jsonErr('Academic year not found');
                if ($lock && (int)$y['is_current'] === 1)
                    jsonErr('"' . $y['name'] . '" is the current academic year — it cannot be locked. Make another year current first, then lock this one.');
                if ((int)$y['is_locked'] === $lock) jsonOk(['message' => 'Already ' . ($lock ? 'locked' : 'unlocked')]);

                qExec("UPDATE academic_years SET is_locked = ? WHERE id = ?" . rsW(), 'ii' . rsT(), $lock, $id, ...rsA());
                rsOk('Academic Year ' . ($lock ? 'Locked' : 'Unlocked'),
                     ($lock ? 'Locked (year-end close): ' : 'Unlocked: ') . $y['name']);
                break;

            case 'deleteYear':
                rsGuard();
                $id = (int)($_POST['id'] ?? 0);
                $y  = qOne("SELECT name, is_current FROM academic_years WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$y) jsonErr('Academic year not found');
                if ((int)$y['is_current'] === 1) jsonErr('Cannot delete the current academic year. Make another year current first.');

                $t = (int)qVal("SELECT COUNT(*) FROM exam_terms WHERE academic_year_id = ?", 'i', $id);   // terms follow the year
                $s = (int)qVal("SELECT COUNT(*) FROM students  WHERE academic_year_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                $m = (int)qVal("SELECT COUNT(*) FROM marks     WHERE academic_year_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if ($t || $s || $m) {
                    jsonErr('Cannot delete "' . $y['name'] . '" — it still has ' . $t . ' term(s), ' . $s
                          . ' student(s) and ' . $m . ' marks row(s). Remove those first.');
                }
                qExec("DELETE FROM academic_years WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                rsOk('Academic Year Deleted', 'Deleted academic year: ' . $y['name']);
                break;

            // ---------------- exam terms
            case 'getTerms':
                requirePermJson('result_settings', 'v');
                $yid = rsOwn('academic_years', $_POST['year_id'] ?? 0);   // terms follow their year
                if ($yid <= 0) jsonOk(['data' => []]);
                jsonOk(['data' => qAll(
                    "SELECT t.id, t.name, t.sort_order, t.status, t.start_date, t.end_date, t.result_date, t.weightage,
                            (SELECT COUNT(*) FROM marks m WHERE m.term_id = t.id) AS marks,
                            (SELECT COUNT(*) FROM result_publications p WHERE p.term_id = t.id AND p.is_published = 1) AS published
                     FROM exam_terms t
                     WHERE t.academic_year_id = ?
                     ORDER BY t.sort_order ASC, t.id ASC", 'i', $yid)]);
                break;

            case 'saveTerm':
                rsGuard();
                $id     = (int)($_POST['id'] ?? 0);
                $yid    = rsOwn('academic_years', $_POST['year_id'] ?? 0);
                $name   = trim($_POST['name'] ?? '');
                $sort   = (int)($_POST['sort_order'] ?? 0);
                $status = $_POST['status'] ?? 'Upcoming';
                $sd     = trim($_POST['start_date'] ?? '');
                $ed     = trim($_POST['end_date'] ?? '');
                $rd     = trim($_POST['result_date'] ?? '');
                $wRaw   = trim((string)($_POST['weightage'] ?? '0'));

                if ($yid <= 0) jsonErr('Pick a valid academic year first');
                if ($name === '') jsonErr('Term name is required (e.g. First Term)');
                if (mb_strlen($name) > 50) jsonErr('Term name must be 50 characters or less');
                if (!in_array($status, ['Upcoming', 'Open', 'Closed'], true)) jsonErr('Invalid term status');
                // exam window must run forwards
                if ($sd !== '' && $ed !== '' && $sd > $ed) jsonErr('Exam end date cannot be before the start date');
                if ($wRaw === '') $wRaw = '0';
                if (!is_numeric($wRaw)) jsonErr('Weightage must be a number');
                $weight = round((float)$wRaw, 2);
                if ($weight < 0 || $weight > 100) jsonErr('Weightage must be between 0 and 100 percent');
                // names unique per year
                if (qVal("SELECT id FROM exam_terms WHERE academic_year_id = ? AND name = ? AND id <> ?", 'isi', $yid, $name, $id)) {
                    jsonErr('"' . $name . '" already exists in this academic year');
                }

                $sdv = $sd !== '' ? $sd : null;
                $edv = $ed !== '' ? $ed : null;
                $rdv = $rd !== '' ? $rd : null;

                if ($id > 0) {
                    qExec("UPDATE exam_terms SET name = ?, sort_order = ?, status = ?, start_date = ?, end_date = ?, result_date = ?, weightage = ?
                           WHERE id = ? AND academic_year_id = ?",
                          'sissssdii', $name, $sort, $status, $sdv, $edv, $rdv, $weight, $id, $yid);
                } else {
                    $id = qInsert("INSERT INTO exam_terms (academic_year_id, name, sort_order, status, start_date, end_date, result_date, weightage)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                  'isissssd', $yid, $name, $sort, $status, $sdv, $edv, $rdv, $weight);
                }
                rsOk('Exam Term Saved', 'Saved term: ' . $name . ' [' . $status . '] weightage ' . rsNum($weight) . '%');
                break;

            case 'setTermStatus':
                rsGuard();
                $id     = (int)($_POST['id'] ?? 0);
                $status = $_POST['status'] ?? '';
                if (!in_array($status, ['Upcoming', 'Open', 'Closed'], true)) jsonErr('Invalid term status');
                $t = qOne("SELECT t.name, t.status FROM exam_terms t JOIN academic_years y ON y.id = t.academic_year_id
                           WHERE t.id = ?" . rsW('y'), 'i' . rsT(), $id, ...rsA());
                if (!$t) jsonErr('Term not found');
                if ($t['status'] === $status) jsonOk(['message' => 'Already ' . $status]);

                qExec("UPDATE exam_terms SET status = ? WHERE id = ?", 'si', $status, $id);
                rsOk('Term Status Changed', 'Term "' . $t['name'] . '": ' . $t['status'] . ' to ' . $status);
                break;

            case 'deleteTerm':
                rsGuard();
                $id = (int)($_POST['id'] ?? 0);
                $t  = qOne("SELECT t.name FROM exam_terms t JOIN academic_years y ON y.id = t.academic_year_id
                            WHERE t.id = ?" . rsW('y'), 'i' . rsT(), $id, ...rsA());
                if (!$t) jsonErr('Term not found');

                // block delete if marks exist — count first so the message is useful
                $m = (int)qVal("SELECT COUNT(*) FROM marks WHERE term_id = ?", 'i', $id);
                if ($m > 0) {
                    jsonErr('Cannot delete "' . $t['name'] . '" — ' . $m
                          . ' marks row(s) are already entered against it. Delete those marks first.');
                }
                $p = (int)qVal("SELECT COUNT(*) FROM result_publications WHERE term_id = ?", 'i', $id);
                $r = (int)qVal("SELECT COUNT(*) FROM result_summaries    WHERE term_id = ?", 'i', $id);
                if ($p || $r) {
                    jsonErr('Cannot delete "' . $t['name'] . '" — it still has ' . $p . ' publication record(s) and '
                          . $r . ' generated result(s).');
                }
                qExec("DELETE FROM exam_terms WHERE id = ?", 'i', $id);
                rsOk('Exam Term Deleted', 'Deleted term: ' . $t['name']);
                break;

            // ---------------- grading sets + bands
            case 'getGrading':
                requirePermJson('result_settings', 'v');
                if (!rsHasSets()) jsonOk(['migrate' => true, 'sets' => [], 'set_id' => 0, 'data' => [], 'issues' => []]);
                $setId = rsPickSet($_POST['set_id'] ?? 0);
                $bands = $setId ? rsBands($setId) : [];
                jsonOk(['sets' => rsSets(), 'set_id' => $setId, 'data' => $bands, 'issues' => rsGradingIssues($bands)]);
                break;

            case 'saveGradingSet':
                rsGuard();
                rsNeedSets();
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($name === '') jsonErr('Set name is required (e.g. Lower Primary)');
                if (mb_strlen($name) > 60) jsonErr('Set name must be 60 characters or less');
                if (mb_strlen($desc) > 255) jsonErr('Description must be 255 characters or less');
                // set names are unique per school — another school's "Lower Primary" is not a clash
                if (qVal("SELECT id FROM grading_sets WHERE name = ? AND id <> ?" . rsW(), 'si' . rsT(), $name, $id, ...rsA())) {
                    jsonErr('A grading set named "' . $name . '" already exists — set names must be unique.');
                }
                $d = $desc !== '' ? $desc : null;
                if ($id > 0) {
                    if (!rsOwn('grading_sets', $id)) jsonErr('Grading set not found');
                    qExec("UPDATE grading_sets SET name = ?, description = ? WHERE id = ?" . rsW(), 'ssi' . rsT(), $name, $d, $id, ...rsA());
                } else {
                    $id = qInsert("INSERT INTO grading_sets (name, description, is_default, is_active" . (rsMulti() ? ', school_id' : '') . ")
                                   VALUES (?, ?, 0, 1" . (rsMulti() ? ', ?' : '') . ")", 'ss' . rsT(), $name, $d, ...rsA());
                }
                rsOk('Grading Set Saved', 'Saved grading set: ' . $name, ['set_id' => $id]);
                break;

            // nursery from default in two clicks — every band comes across
            case 'cloneGradingSet':
                rsGuard();
                rsNeedSets();
                $src  = (int)($_POST['source_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $from = qOne("SELECT name FROM grading_sets WHERE id = ?" . rsW(), 'i' . rsT(), $src, ...rsA());
                if (!$from) jsonErr('Pick the grading set to copy from');
                if ($name === '') jsonErr('New set name is required');
                if (mb_strlen($name) > 60) jsonErr('Set name must be 60 characters or less');
                if (mb_strlen($desc) > 255) jsonErr('Description must be 255 characters or less');
                if (qVal("SELECT id FROM grading_sets WHERE name = ?" . rsW(), 's' . rsT(), $name, ...rsA())) {
                    jsonErr('A grading set named "' . $name . '" already exists — set names must be unique.');
                }

                $bands = rsBands($src);
                $d     = $desc !== '' ? $desc : 'Copied from ' . $from['name'];
                $conn  = getDBConnection();
                $conn->begin_transaction();
                try {
                    $new = qInsert("INSERT INTO grading_sets (name, description, is_default, is_active" . (rsMulti() ? ', school_id' : '') . ")
                                    VALUES (?, ?, 0, 1" . (rsMulti() ? ', ?' : '') . ")", 'ss' . rsT(), $name, $d, ...rsA());
                    if ($bands) {                                   // one insert, never a row per loop
                        $args = [];
                        foreach ($bands as $b) {
                            array_push($args, $new, $b['grade'], (float)$b['min_percent'], (float)$b['max_percent'], (float)$b['grade_point'],
                                       $b['remarks'], $b['interpretation'], (int)$b['sort_order'], (int)$b['is_fail'], $b['color']);
                        }
                        qExec("INSERT INTO grading_scheme (set_id, grade, min_percent, max_percent, grade_point, remarks, interpretation, sort_order, is_fail, color) VALUES "
                              . implode(', ', array_fill(0, count($bands), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                              str_repeat('isdddssiis', count($bands)), ...$args);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                rsOk('Grading Set Cloned', 'Cloned "' . $from['name'] . '" to "' . $name . '" with ' . count($bands) . ' band(s)', ['set_id' => $new]);
                break;

            case 'setDefaultGradingSet':
                rsGuard();
                rsNeedSets();
                $id = (int)($_POST['id'] ?? 0);
                $g  = qOne("SELECT name, is_default FROM grading_sets WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$g) jsonErr('Grading set not found');
                if ((int)$g['is_default'] === 1) jsonOk(['message' => 'Already the default set']);

                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    // one default per SCHOOL — unsetting the others must never reach another tenant
                    qExec("UPDATE grading_sets SET is_default = 0 WHERE is_default = 1" . rsW(), rsT(), ...rsA());
                    qExec("UPDATE grading_sets SET is_default = 1, is_active = 1 WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                rsOk('Default Grading Set Changed', 'Default grading set: ' . $g['name']);
                break;

            case 'deleteGradingSet':
                rsGuard();
                rsNeedSets();
                $id = (int)($_POST['id'] ?? 0);
                $g  = qOne("SELECT name, is_default FROM grading_sets WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$g) jsonErr('Grading set not found');
                if ((int)$g['is_default'] === 1) {
                    jsonErr('"' . $g['name'] . '" is the default grading set — every class without its own set is graded by it. '
                          . 'Make another set the default first, then delete this one.');
                }
                // "one must remain" counts OUR sets — another school's sets are not a spare
                if ((int)qVal("SELECT COUNT(*) FROM grading_sets WHERE 1" . rsW(), rsT(), ...rsA()) <= 1) jsonErr('At least one grading set must remain.');

                // usage guards count this school's rows only (all branches — a pin is a pin)
                $cls = (int)qVal("SELECT COUNT(*) FROM classes WHERE grading_set_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if ($cls) {
                    jsonErr('Cannot delete "' . $g['name'] . '" — ' . $cls . ' class(es) are pinned to it. '
                          . 'Point those classes at another set under Assessment > Grading & Assessment per Class first.');
                }
                $cards = (int)qVal("SELECT COUNT(*) FROM result_summaries WHERE grading_set_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if ($cards) {
                    jsonErr('Cannot delete "' . $g['name'] . '" — ' . $cards . ' published result(s) were graded with it, '
                          . 'and their grade key is read back from this set.');
                }

                $n    = (int)qVal("SELECT COUNT(*) FROM grading_scheme WHERE set_id = ?", 'i', $id);
                $conn = getDBConnection();
                $conn->begin_transaction();
                try {
                    qExec("DELETE FROM grading_scheme WHERE set_id = ?", 'i', $id);   // bands follow the set
                    qExec("DELETE FROM grading_sets WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                rsOk('Grading Set Deleted', 'Deleted grading set "' . $g['name'] . '" and its ' . $n . ' band(s)');
                break;

            case 'saveGrade':
                rsGuard();
                rsNeedSets();
                $id      = (int)($_POST['id'] ?? 0);
                $setId   = rsPickSet($_POST['set_id'] ?? 0);
                $grade   = strtoupper(trim($_POST['grade'] ?? ''));
                $min     = (float)($_POST['min_percent'] ?? -1);
                $max     = (float)($_POST['max_percent'] ?? -1);
                $point   = (float)($_POST['grade_point'] ?? 0);
                $remarks = trim($_POST['remarks'] ?? '');
                $interp  = trim($_POST['interpretation'] ?? '');
                $sort    = (int)($_POST['sort_order'] ?? 0);
                $isFail  = !empty($_POST['is_fail']) ? 1 : 0;
                $colRaw  = trim((string)($_POST['color'] ?? ''));

                if ($setId <= 0) jsonErr('Create a grading set first — a band has to live in one.');
                if ($grade === '') jsonErr('Grade label is required (e.g. A+)');
                if (mb_strlen($grade) > 5) jsonErr('Grade label must be 5 characters or less');
                if ($min < 0 || $min > 100 || $max < 0 || $max > 100) jsonErr('Min and max must both be between 0 and 100');
                if ($min > $max) jsonErr('Min percent cannot be greater than max percent');
                if ($point < 0 || $point > 10) jsonErr('Grade point must be between 0 and 10');
                if (mb_strlen($remarks) > 100) jsonErr('Remarks must be 100 characters or less');
                if (mb_strlen($interp) > 255) jsonErr('Interpretation must be 255 characters or less');
                if ($colRaw !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colRaw))
                    jsonErr('Grade colour must be a 6-digit hex like #1e8e3e, or blank for the default');

                $setName = (string)qVal("SELECT name FROM grading_sets WHERE id = ?" . rsW(), 'i' . rsT(), $setId, ...rsA());
                // unique + overlap checks live INSIDE the set — nursery "A" never clashes with senior "A"
                if (qVal("SELECT id FROM grading_scheme WHERE set_id = ? AND grade = ? AND id <> ?", 'isi', $setId, $grade, $id)) {
                    jsonErr('Grade "' . $grade . '" already exists in the "' . $setName . '" set');
                }
                // interval intersection: a.min <= b.max AND a.max >= b.min — overlaps are ambiguous, block them
                $clash = qOne("SELECT grade, min_percent, max_percent FROM grading_scheme
                               WHERE set_id = ? AND id <> ? AND min_percent <= ? AND max_percent >= ? LIMIT 1", 'iidd', $setId, $id, $max, $min);
                if ($clash) {
                    jsonErr('Range ' . rsNum($min) . '-' . rsNum($max) . ' overlaps grade "' . $clash['grade']
                          . '" (' . rsNum($clash['min_percent']) . '-' . rsNum($clash['max_percent']) . ') in the "' . $setName . '" set.');
                }

                $rem = $remarks !== '' ? $remarks : null;
                $itp = $interp  !== '' ? $interp  : null;
                $col = rsColor($colRaw);
                if ($id > 0) {
                    qExec("UPDATE grading_scheme SET grade = ?, min_percent = ?, max_percent = ?, grade_point = ?, remarks = ?, interpretation = ?, sort_order = ?, is_fail = ?, color = ?
                           WHERE id = ? AND set_id = ?",
                          'sdddssiisii', $grade, $min, $max, $point, $rem, $itp, $sort, $isFail, $col, $id, $setId);
                } else {
                    $id = qInsert("INSERT INTO grading_scheme (set_id, grade, min_percent, max_percent, grade_point, remarks, interpretation, sort_order, is_fail, color)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                  'isdddssiis', $setId, $grade, $min, $max, $point, $rem, $itp, $sort, $isFail, $col);
                }
                rsOk('Grade Band Saved', 'Set "' . $setName . '": saved grade ' . $grade . ' (' . rsNum($min) . '-' . rsNum($max) . '%)'
                                       . ($isFail ? ' — counts as a fail' : '') . ($col ? ' tint ' . $col : ''));
                break;

            case 'deleteGrade':
                rsGuard();
                rsNeedSets();
                $id = (int)($_POST['id'] ?? 0);
                $g  = qOne("SELECT g.grade, g.set_id FROM grading_scheme g JOIN grading_sets s ON s.id = g.set_id
                            WHERE g.id = ?" . rsW('s'), 'i' . rsT(), $id, ...rsA());
                if (!$g) jsonErr('Grade band not found');
                $setId = (int)$g['set_id'];
                if ((int)qVal("SELECT COUNT(*) FROM grading_scheme WHERE set_id = ?", 'i', $setId) <= 1)
                    jsonErr('At least one grade band must remain in this set. Delete the whole set instead if you no longer need it.');
                qExec("DELETE FROM grading_scheme WHERE id = ?", 'i', $id);
                rsOk('Grade Band Deleted', 'Deleted grade band "' . $g['grade'] . '" from set #' . $setId);
                break;

            // ---------------- assessment schemes + components
            case 'getAssessment':
                requirePermJson('result_settings', 'v');
                if (!rsHasAsmt()) jsonOk(['migrate' => true, 'schemes' => [], 'scheme_id' => 0, 'components' => [], 'weight' => 0, 'warning' => '']);
                $schId = rsPickScheme($_POST['scheme_id'] ?? 0);
                $comps = $schId ? rsComps($schId) : [];
                $sum   = $schId ? rsCompWeight($schId) : 0.0;
                jsonOk(['schemes' => rsSchemes(), 'scheme_id' => $schId, 'components' => $comps,
                        'weight'  => $sum, 'warning' => $comps ? rsWeightWarn($sum) : '']);
                break;

            case 'saveAssessmentScheme':
                rsGuard();
                rsNeedAsmt();
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($name === '') jsonErr('Scheme name is required (e.g. 50 / 50 (CA & Exam))');
                if (mb_strlen($name) > 60) jsonErr('Scheme name must be 60 characters or less');
                if (mb_strlen($desc) > 255) jsonErr('Description must be 255 characters or less');
                // scheme names are unique per school
                if (qVal("SELECT id FROM assessment_schemes WHERE name = ? AND id <> ?" . rsW(), 'si' . rsT(), $name, $id, ...rsA())) {
                    jsonErr('An assessment scheme named "' . $name . '" already exists — scheme names must be unique.');
                }
                $d = $desc !== '' ? $desc : null;
                if ($id > 0) {
                    if (!rsOwn('assessment_schemes', $id)) jsonErr('Assessment scheme not found');
                    qExec("UPDATE assessment_schemes SET name = ?, description = ? WHERE id = ?" . rsW(), 'ssi' . rsT(), $name, $d, $id, ...rsA());
                } else {
                    $id = qInsert("INSERT INTO assessment_schemes (name, description, is_active" . (rsMulti() ? ', school_id' : '') . ")
                                   VALUES (?, ?, 1" . (rsMulti() ? ', ?' : '') . ")", 'ss' . rsT(), $name, $d, ...rsA());
                }
                rsOk('Assessment Scheme Saved', 'Saved assessment scheme: ' . $name, ['scheme_id' => $id]);
                break;

            case 'cloneAssessmentScheme':
                rsGuard();
                rsNeedAsmt();
                $src  = (int)($_POST['source_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $from = qOne("SELECT name FROM assessment_schemes WHERE id = ?" . rsW(), 'i' . rsT(), $src, ...rsA());
                if (!$from) jsonErr('Pick the assessment scheme to copy from');
                if ($name === '') jsonErr('New scheme name is required');
                if (mb_strlen($name) > 60) jsonErr('Scheme name must be 60 characters or less');
                if (mb_strlen($desc) > 255) jsonErr('Description must be 255 characters or less');
                if (qVal("SELECT id FROM assessment_schemes WHERE name = ?" . rsW(), 's' . rsT(), $name, ...rsA())) {
                    jsonErr('An assessment scheme named "' . $name . '" already exists — scheme names must be unique.');
                }

                $comps = rsComps($src);
                $d     = $desc !== '' ? $desc : 'Copied from ' . $from['name'];
                $conn  = getDBConnection();
                $conn->begin_transaction();
                try {
                    $new = qInsert("INSERT INTO assessment_schemes (name, description, is_active" . (rsMulti() ? ', school_id' : '') . ")
                                    VALUES (?, ?, 1" . (rsMulti() ? ', ?' : '') . ")", 'ss' . rsT(), $name, $d, ...rsA());
                    if ($comps) {
                        $args = [];
                        foreach ($comps as $c) {
                            array_push($args, $new, $c['name'], (float)$c['max_marks'], (float)$c['weight_percent'], (int)$c['is_exam'], (int)$c['sort_order']);
                        }
                        qExec("INSERT INTO assessment_components (scheme_id, name, max_marks, weight_percent, is_exam, sort_order) VALUES "
                              . implode(', ', array_fill(0, count($comps), '(?, ?, ?, ?, ?, ?)')),
                              str_repeat('isddii', count($comps)), ...$args);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                rsOk('Assessment Scheme Cloned', 'Cloned "' . $from['name'] . '" to "' . $name . '" with ' . count($comps) . ' component(s)', ['scheme_id' => $new]);
                break;

            case 'setAssessmentSchemeActive':
                rsGuard();
                rsNeedAsmt();
                $id = (int)($_POST['id'] ?? 0);
                $on = !empty($_POST['is_active']) ? 1 : 0;
                $a  = qOne("SELECT name, is_active FROM assessment_schemes WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$a) jsonErr('Assessment scheme not found');
                if ((int)$a['is_active'] === $on) jsonOk(['message' => 'Already ' . ($on ? 'active' : 'inactive')]);
                if (!$on) {
                    $cls = (int)qVal("SELECT COUNT(*) FROM classes WHERE assessment_scheme_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                    if ($cls) jsonErr('"' . $a['name'] . '" is still assigned to ' . $cls . ' class(es) — move them off it before deactivating.');
                }
                qExec("UPDATE assessment_schemes SET is_active = ? WHERE id = ?" . rsW(), 'ii' . rsT(), $on, $id, ...rsA());
                rsOk('Assessment Scheme ' . ($on ? 'Activated' : 'Deactivated'), ($on ? 'Activated: ' : 'Deactivated: ') . $a['name']);
                break;

            case 'deleteAssessmentScheme':
                rsGuard();
                rsNeedAsmt();
                $id = (int)($_POST['id'] ?? 0);
                $a  = qOne("SELECT name FROM assessment_schemes WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if (!$a) jsonErr('Assessment scheme not found');

                $cls = (int)qVal("SELECT COUNT(*) FROM classes WHERE assessment_scheme_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if ($cls) {
                    jsonErr('Cannot delete "' . $a['name'] . '" — ' . $cls . ' class(es) are marked on it. '
                          . 'Set those classes to another scheme (or "no components") first.');
                }
                $mk = (int)qVal("SELECT COUNT(*) FROM marks WHERE assessment_scheme_id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());
                if ($mk) {
                    jsonErr('Cannot delete "' . $a['name'] . '" — ' . $mk . ' marks row(s) were produced by it. '
                          . 'Those results keep it as their snapshot, so the scheme has to stay. Deactivate it instead.');
                }

                $n = (int)qVal("SELECT COUNT(*) FROM assessment_components WHERE scheme_id = ?", 'i', $id);
                qExec("DELETE FROM assessment_schemes WHERE id = ?" . rsW(), 'i' . rsT(), $id, ...rsA());   // components cascade
                rsOk('Assessment Scheme Deleted', 'Deleted assessment scheme "' . $a['name'] . '" and its ' . $n . ' component(s)');
                break;

            case 'saveAssessmentComponent':
                rsGuard();
                rsNeedAsmt();
                $id    = (int)($_POST['id'] ?? 0);
                $schId = (int)($_POST['scheme_id'] ?? 0);
                $name  = trim($_POST['name'] ?? '');
                $mRaw  = trim((string)($_POST['max_marks'] ?? ''));
                $wRaw  = trim((string)($_POST['weight_percent'] ?? ''));
                $isEx  = !empty($_POST['is_exam']) ? 1 : 0;
                $sort  = (int)($_POST['sort_order'] ?? 0);

                $sch = qOne("SELECT name FROM assessment_schemes WHERE id = ?" . rsW(), 'i' . rsT(), $schId, ...rsA());
                if (!$sch) jsonErr('Pick an assessment scheme first');
                if ($name === '') jsonErr('Component name is required (e.g. Class Exercises)');
                if (mb_strlen($name) > 60) jsonErr('Component name must be 60 characters or less');
                if (!is_numeric($mRaw)) jsonErr('Max marks must be a number');
                if (!is_numeric($wRaw)) jsonErr('Weight must be a number');
                $maxM = round((float)$mRaw, 2);
                $wt   = round((float)$wRaw, 2);
                if ($maxM <= 0 || $maxM > 9999) jsonErr('Max marks must be between 0.01 and 9999 — it is the raw entry ceiling for this component');
                if ($wt < 0 || $wt > 100) jsonErr('Weight must be between 0 and 100 percent');
                if (qVal("SELECT id FROM assessment_components WHERE scheme_id = ? AND name = ? AND id <> ?", 'isi', $schId, $name, $id)) {
                    jsonErr('"' . $name . '" already exists in the "' . $sch['name'] . '" scheme');
                }

                if ($id > 0) {
                    qExec("UPDATE assessment_components SET name = ?, max_marks = ?, weight_percent = ?, is_exam = ?, sort_order = ?
                           WHERE id = ? AND scheme_id = ?", 'sddiiii', $name, $maxM, $wt, $isEx, $sort, $id, $schId);
                } else {
                    $id = qInsert("INSERT INTO assessment_components (scheme_id, name, max_marks, weight_percent, is_exam, sort_order) VALUES (?, ?, ?, ?, ?, ?)",
                                  'isddii', $schId, $name, $maxM, $wt, $isEx, $sort);
                }
                $warn = rsWeightWarn(rsCompWeight($schId));
                rsOk('Assessment Component Saved',
                     'Scheme "' . $sch['name'] . '": saved "' . $name . '" (max ' . rsNum($maxM) . ', weight ' . rsNum($wt) . '%'
                     . ($isEx ? ', final exam' : ', continuous assessment') . ')' . ($warn ? ' — ' . $warn : ''),
                     ['warning' => $warn]);
                break;

            case 'deleteAssessmentComponent':
                rsGuard();
                rsNeedAsmt();
                $id = (int)($_POST['id'] ?? 0);
                $c  = qOne("SELECT c.name, c.scheme_id FROM assessment_components c JOIN assessment_schemes s ON s.id = c.scheme_id
                            WHERE c.id = ?" . rsW('s'), 'i' . rsT(), $id, ...rsA());
                if (!$c) jsonErr('Component not found');

                // fk cascades — a silent cascade would wipe entered scores, so refuse instead
                $used = (int)qVal("SELECT COUNT(*) FROM mark_components WHERE component_id = ?", 'i', $id);
                if ($used) {
                    jsonErr('Cannot delete "' . $c['name'] . '" — ' . $used . ' entered score(s) belong to it. '
                          . 'Clear those scores in Marks Entry first.');
                }
                qExec("DELETE FROM assessment_components WHERE id = ?", 'i', $id);
                $warn = rsWeightWarn(rsCompWeight((int)$c['scheme_id']));
                rsOk('Assessment Component Deleted', 'Deleted component "' . $c['name'] . '"' . ($warn ? ' — ' . $warn : ''), ['warning' => $warn]);
                break;

            // per-class grading set + assessment scheme — classes.php writes the same two columns
            case 'saveClassAssignments':
                rsGuard();
                rsNeedSets();
                $gs = is_array($_POST['class_gset'] ?? null)    ? $_POST['class_gset']    : [];
                $as = is_array($_POST['class_ascheme'] ?? null) ? $_POST['class_ascheme'] : [];

                // the allowlists decide which set a child is graded by, so they list OUR sets/schemes
                // and OUR classes only — an id from another school must never survive validation
                $okSet = array_flip(array_map('intval', array_column(qAll("SELECT id FROM grading_sets WHERE 1" . rsW(), rsT(), ...rsA()), 'id')));
                $okSch = array_flip(array_map('intval', array_column(qAll("SELECT id FROM assessment_schemes WHERE 1" . rsW(), rsT(), ...rsA()), 'id')));
                $okCls = array_flip(array_map('intval', array_column(qAll("SELECT id FROM classes WHERE 1" . rsClsW(), rsClsT(), ...rsClsA()), 'id')));

                $clean = [];                                     // validate everything before writing anything
                foreach (array_keys($gs + $as) as $cid) {
                    $cid = (int)$cid;
                    if ($cid <= 0) continue;
                    $g = (int)($gs[$cid] ?? 0);
                    $a = (int)($as[$cid] ?? 0);
                    if (!isset($okCls[$cid]))          jsonErr('Unknown class in the assignment list');
                    if ($g > 0 && !isset($okSet[$g])) jsonErr('Unknown grading set picked for a class');
                    if ($a > 0 && !isset($okSch[$a])) jsonErr('Unknown assessment scheme picked for a class');
                    $clean[$cid] = [$g ?: null, $a ?: null];     // null = inherit default / no components
                }
                foreach ($clean as $cid => [$g, $a]) {
                    qExec("UPDATE classes SET grading_set_id = ?, assessment_scheme_id = ? WHERE id = ?" . rsClsW(),
                          'iii' . rsClsT(), $g, $a, $cid, ...rsClsA());
                }
                $pinned = count(array_filter($clean, fn($v) => $v[0] !== null));
                $scored = count(array_filter($clean, fn($v) => $v[1] !== null));
                rsOk('Class Grading Assignments Saved',
                     count($clean) . ' class(es) saved — ' . $pinned . ' pinned to a grading set, ' . $scored . ' marked on an assessment scheme');
                break;

            // ---------------- options
            case 'saveOptions':
                rsGuard();
                $sp = trim($_POST['student_default_password'] ?? '');
                $tp = trim($_POST['teacher_default_password'] ?? '');
                $pf = strtoupper(trim($_POST['admission_no_prefix'] ?? ''));

                if (strlen($sp) < 6) jsonErr('Student default password must be at least 6 characters');
                if (strlen($tp) < 6) jsonErr('Teacher default password must be at least 6 characters');
                if (!preg_match('/^[A-Z0-9\-]{1,10}$/', $pf)) jsonErr('Admission prefix: 1-10 characters, letters, digits or dash only');

                $keyNote = trim($_POST['result_grade_key_note'] ?? '');
                $wMsg    = trim($_POST['withhold_message'] ?? '');
                $thrRaw  = trim((string)($_POST['arrears_threshold'] ?? '0'));
                if (mb_strlen($keyNote) > 255) jsonErr('Grade key note must be 255 characters or less');
                if (mb_strlen($wMsg) > 255) jsonErr('Withhold message must be 255 characters or less');
                if ($thrRaw === '') $thrRaw = '0';
                if (!is_numeric($thrRaw)) jsonErr('Arrears threshold must be a number');
                $thr = round((float)$thrRaw, 2);
                if ($thr < 0) jsonErr('Arrears threshold cannot be negative — use 0 to withhold on any outstanding balance');

                foreach (['result_show_position', 'result_show_gpa', 'result_show_photo', 'allow_public_signup', 'require_principal_approval',
                          'result_show_attendance', 'result_show_grade_key', 'result_show_ca_columns', 'withhold_on_arrears'] as $k) {
                    setSetting($k, !empty($_POST[$k]) ? '1' : '0');
                }
                setSetting('student_default_password', $sp);
                setSetting('teacher_default_password', $tp);
                setSetting('admission_no_prefix', $pf);
                setSetting('result_grade_key_note', $keyNote);
                setSetting('arrears_threshold', rsNum($thr));
                // blank would leave a parent staring at nothing where the card should be
                setSetting('withhold_message', $wMsg !== '' ? $wMsg : 'This result has been withheld by the school. Please contact the school office.');

                $hold = !empty($_POST['withhold_on_arrears']);
                rsOk('Result Options Updated', 'Saved result card options + account defaults (prefix ' . $pf
                                             . ', principal approval ' . (empty($_POST['require_principal_approval']) ? 'off' : 'on')
                                             . ', withholding ' . ($hold ? 'on above ' . rsNum($thr) : 'off') . ')');
                break;

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('result_settings.php error: ' . $e->getMessage());
        jsonErr('Something went wrong. Please try again.');   // detail stays in the log
    }
    exit();
}

// ---------------------------------------------------------------- page data
// this school's years, newest first — the current one comes out of the same list, no second read
$years     = qAll("SELECT * FROM academic_years WHERE 1" . rsW() . " ORDER BY start_date DESC, id DESC", rsT(), ...rsA());
$curYear   = null;
foreach ($years as $y) if ((int)$y['is_current'] === 1) { $curYear = $y; break; }
$curYearId = $curYear ? (int)$curYear['id'] : 0;
$site      = getSiteBranding();

$brand = [];
foreach (array_keys($BRAND_KEYS) as $k) $brand[$k] = (string)getSetting($k, '');
$logo = (string)getSetting('result_logo', '');
$sign = (string)getSetting('result_principal_signature', '');
$accent = (string)getSetting('result_accent_color', '#001f3f');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#001f3f';
$cardOn = [];
foreach (array_keys($CARD_TOGGLES) as $tk) $cardOn[$tk] = getSetting($tk, '1') === '1';

$opt = [
    'result_show_position'     => getSetting('result_show_position', '1') === '1',
    'result_show_gpa'          => getSetting('result_show_gpa', '1') === '1',
    'result_show_photo'        => getSetting('result_show_photo', '1') === '1',
    'allow_public_signup'      => getSetting('allow_public_signup', '0') === '1',
    'require_principal_approval' => getSetting('require_principal_approval', '1') === '1',
    'student_default_password' => (string)getSetting('student_default_password', 'student123'),
    'teacher_default_password' => (string)getSetting('teacher_default_password', 'teacher123'),
    'admission_no_prefix'      => (string)getSetting('admission_no_prefix', 'STU'),
    'result_show_attendance'   => getSetting('result_show_attendance', '1') === '1',
    'result_show_grade_key'    => getSetting('result_show_grade_key', '1') === '1',
    'result_show_ca_columns'   => getSetting('result_show_ca_columns', '1') === '1',
    'withhold_on_arrears'      => getSetting('withhold_on_arrears', '0') === '1',
    'result_grade_key_note'    => (string)getSetting('result_grade_key_note', 'Grades above show how each band is interpreted.'),
    'withhold_message'         => (string)getSetting('withhold_message', 'This result has been withheld by the school. Please contact the school office.'),
    'arrears_threshold'        => (string)getSetting('arrears_threshold', '0'),
];
$canEdit = can('result_settings', 'e');
$cur     = ormsCurrency();

// grading sets + assessment schemes tabs
$hasSets = rsHasSets();
$hasAsmt = rsHasAsmt();
$gsList  = $hasSets ? qAll("SELECT id, name, is_default FROM grading_sets WHERE is_active = 1" . rsW() . " ORDER BY is_default DESC, name ASC", rsT(), ...rsA()) : [];
$asList  = $hasAsmt ? qAll("SELECT id, name FROM assessment_schemes WHERE is_active = 1" . rsW() . " ORDER BY name ASC", rsT(), ...rsA()) : [];
$asgClasses = $hasSets
    ? qAll("SELECT id, name, grading_set_id, assessment_scheme_id FROM classes WHERE is_active = 1" . rsClsW() . " ORDER BY sort_order ASC, name ASC", rsClsT(), ...rsClsA())
    : [];

// templates tab
$TPLS = ormsTemplates();
$tplDefault = (string)getSetting('result_template', 'classic');
if (!isset($TPLS[$tplDefault])) $tplDefault = 'classic';
$tplClasses = qAll("SELECT id, name, result_template FROM classes WHERE is_active = 1" . rsClsW() . " ORDER BY sort_order ASC, name ASC", rsClsT(), ...rsClsA());

// tiny css sketch per template key — gallery thumbnails
function rsTplThumb(string $k): string {
    $t = [
        'classic' => '<div class="tt-bar"></div><div class="tt-row"></div><div class="tt-row-thin"></div><div class="tt-head"></div>'
                   . '<div class="tt-row"></div><div class="tt-row"></div><div class="tt-split"><div class="tt-row"></div><div class="tt-row"></div><div class="tt-row"></div></div>',
        'compact' => '<div class="tt-bar-o"></div><div class="tt-row-thin"></div><div class="tt-row-thin"></div><div class="tt-row-thin"></div>'
                   . '<div class="tt-row-thin"></div><div class="tt-row-thin"></div><div class="tt-row-thin"></div><div class="tt-row-thin"></div>',
        'formal'  => '<div class="tt-bar"></div><div class="tt-split"><div class="tt-stack"><div class="tt-row"></div><div class="tt-row"></div><div class="tt-row"></div></div>'
                   . '<div class="tt-box"></div></div><div class="tt-head"></div><div class="tt-row"></div><div class="tt-row"></div>',
        'modern'  => '<div class="tt-bar tt-r"></div><div class="tt-row-thin"></div><div class="tt-row"></div><div class="tt-row-thin"></div><div class="tt-row"></div>'
                   . '<div class="tt-split"><div class="tt-box tt-r"></div><div class="tt-box tt-r"></div><div class="tt-box tt-r"></div></div>',
        'elegant' => '<div class="tt-mid"></div><div class="tt-row"></div><div class="tt-row-thin"></div><div class="tt-row"></div><div class="tt-row-thin"></div><div class="tt-row"></div><div class="tt-mid"></div>',
        'board'   => '<div class="tt-bar-o"></div><div class="tt-cellrow"><i></i><i></i><i></i></div><div class="tt-cellrow"><i></i><i></i><i></i></div>'
                   . '<div class="tt-cellrow"><i></i><i></i><i></i></div><div class="tt-cellrow"><i></i><i></i><i></i></div>',
        'report'  => '<div class="tt-bar"></div><div class="tt-head"></div><div class="tt-cols"><i></i><i></i><i></i><i></i></div>'
                   . '<div class="tt-cols"><i></i><i></i><i></i><i></i></div><div class="tt-cols"><i></i><i></i><i></i><i></i></div><div class="tt-cols"><i></i><i></i><i></i><i></i></div>',
        'progress'=> '<div class="tt-bar"></div><div class="tt-cols"><i></i><i></i><i></i></div><div class="tt-cols"><i></i><i></i><i></i></div>'
                   . '<div class="tt-cols"><i></i><i></i><i></i></div><div class="tt-cols"><i></i><i></i><i></i></div><div class="tt-head"></div>',
        // paired cells per term — the full/secured column pairs are the whole point of this one
        'marksheet' => '<div class="tt-bar-o"></div><div class="tt-cellrow tt-pair"><i></i><i></i><i></i><i></i><i></i></div>'
                     . '<div class="tt-cellrow tt-pair"><i></i><i></i><i></i><i></i><i></i></div>'
                     . '<div class="tt-cellrow tt-pair"><i></i><i></i><i></i><i></i><i></i></div>'
                     . '<div class="tt-cellrow tt-pair"><i></i><i></i><i></i><i></i><i></i></div>'
                     . '<div class="tt-head"></div>',
    ];
    $frame = $k === 'formal' || $k === 'board' || $k === 'marksheet' ? ' tt-frame' : ($k === 'elegant' ? ' tt-frame2' : '');
    return '<div class="tplg-thumb' . $frame . '">' . ($t[$k] ?? '') . '</div>';
}

// sample slice through the REAL engine maths — marks: subject_id => [obtained|null, absent]
function rsPvSlice(array $st, array $marks, int $pos): array {
    $subs = [
        ['subject_id' => 1, 'name' => 'English',     'code' => 'ENG',  'total_marks' => 100, 'passing_marks' => 40, 'sort_order' => 1, 'counted' => 1],
        ['subject_id' => 2, 'name' => 'Mathematics', 'code' => 'MATH', 'total_marks' => 100, 'passing_marks' => 40, 'sort_order' => 2, 'counted' => 1],
        ['subject_id' => 3, 'name' => 'Science',     'code' => 'SCI',  'total_marks' => 100, 'passing_marks' => 40, 'sort_order' => 3, 'counted' => 1],
        ['subject_id' => 4, 'name' => 'Urdu',        'code' => 'URD',  'total_marks' => 100, 'passing_marks' => 40, 'sort_order' => 4, 'counted' => 1],
    ];
    $mk = [];
    foreach ($marks as $subId => [$obt, $abs]) {
        $mk[$st['id'] . ':' . $subId] = ['marks_obtained' => $obt, 'total_marks' => 100, 'passing_marks' => 40, 'is_absent' => $abs, 'remarks' => null];
    }
    $row = ormsComputeStudentRow($st, $subs, $mk);
    $row['position'] = $pos; $row['section_total'] = 15;
    return $row;
}

// the four preview cards, rendered by the real skins with saved branding + toggles
function rsTplPreviews(array $site): array {
    $st = ['id' => 0, 'full_name' => 'Student One', 'username' => 'student1', 'father_name' => 'Guardian One',
           'admission_no' => 'STU-2026-0001', 'roll_no' => '1', 'class_name' => 'Class 5', 'section_name' => 'A',
           'dob' => '2014-03-14', 'profile_image' => $site['site_logo'], 'class_id' => 0];
    $s1 = rsPvSlice($st, [1 => [82, 0], 2 => [91, 0], 3 => [28, 0], 4 => [null, 1]], 3); // term 1 — the branding-preview numbers
    $s2 = rsPvSlice($st, [1 => [88, 0], 2 => [85, 0], 3 => [55, 0], 4 => [61, 0]], 2);   // term 2 — recovered

    $res = [
        'student' => $st,
        'term'    => ['id' => 1, 'name' => 'First Term', 'academic_year_id' => 0],
        'year'    => ['name' => '2025-2026'],
        'subjects' => $s1['subjects'],
        'summary'  => array_intersect_key($s1, array_flip(['total_obtained', 'total_max', 'percentage', 'grade', 'gpa', 'result_status', 'failed_subjects'])),
        'position' => 3, 'section_total' => 15,
        'teacher_remarks' => 'Needs steady practice in Science.',
        'class_teacher' => '', 'published' => true,
        'verify_token' => str_repeat('0', 32)   // sample qr — scans to "not verified", honest for demo data
    ];
    $cols = [
        ['term' => ['id' => 1, 'name' => 'First Term'],  'published' => true, 'slice' => $s1],
        ['term' => ['id' => 2, 'name' => 'Second Term'], 'published' => true, 'slice' => $s2],
    ];
    $b = ormsResultBranding();
    $yr = ['terms' => $cols] + ormsMergeTermSlices($cols);
    return [
        'classic'  => ormsCardClassic($res, $b),
        'compact'  => ormsCardClassic($res, $b, ' rc-t-compact'),
        'modern'   => ormsCardClassic($res, $b, ' rc-t-modern'),
        'elegant'  => ormsCardClassic($res, $b, ' rc-t-elegant'),
        'board'    => ormsCardClassic($res, $b, ' rc-t-board'),
        'formal'   => ormsCardFormal($res, $b),
        'report'   => ormsCardReport($res, $b, $yr),
        'progress' => ormsCardProgress($res, $b, $yr),
        'marksheet'=> ormsCardMarksheet($res, $b, $yr),
    ];
}

// tbody placeholder while the first ajax load is in flight — render*() overwrites it
function rsSkeleton(int $cols, int $rows = 5): string {
    $row = '<div class="skeleton-table-row"><div class="skeleton skeleton-table-cell skeleton-flex-2"></div>'
         . str_repeat('<div class="skeleton skeleton-table-cell skeleton-flex-1"></div>', 3) . '</div>';
    return '<tr><td colspan="' . $cols . '"><div class="skeleton-table">' . str_repeat($row, $rows) . '</div></td></tr>';
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
    <title>Result Settings - Online Result Management</title>

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
                    <h1><i class="fas fa-sliders"></i> Result Settings</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Result Settings</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="tab-nav" id="rsTabs">
                    <button type="button" class="tab-btn active" data-tab="branding"><i class="fas fa-school"></i> Branding</button>
                    <button type="button" class="tab-btn" data-tab="terms"><i class="fas fa-calendar-check"></i> Exam Terms</button>
                    <button type="button" class="tab-btn" data-tab="years"><i class="fas fa-calendar-days"></i> Academic Years</button>
                    <button type="button" class="tab-btn" data-tab="grading"><i class="fas fa-award"></i> Grading Scheme</button>
                    <button type="button" class="tab-btn" data-tab="assessment"><i class="fas fa-percent"></i> Assessment</button>
                    <button type="button" class="tab-btn" data-tab="templates"><i class="fas fa-file-invoice"></i> Templates</button>
                    <button type="button" class="tab-btn" data-tab="options"><i class="fas fa-toggle-on"></i> Options</button>
                </div>

                <!-- ============ TAB 1: BRANDING ============ -->
                <div class="tab-pane active" id="pane-branding">
                    <div class="section-header">
                        <h2><i class="fas fa-school"></i> Result Card Header &amp; Footer</h2>
                    </div>
                    <div class="info-banner">
                        <i class="fas fa-circle-info"></i>
                        Everything here prints on the result card. Leave the school name or logo blank and the card falls back to the site branding
                        (<strong><?php echo htmlspecialchars($site['site_name']); ?></strong>) from Settings.
                    </div>

                    <div class="rs-split">

                    <!-- ---------- live preview (left) ---------- -->
                    <div class="rs-preview-pane">
                        <div class="rs-preview-head">
                            <span><i class="fas fa-eye"></i> Live Preview</span>
                            <span class="rs-preview-badge">Sample data</span>
                        </div>
                        <div class="rs-preview-card">
                            <div class="result-card" id="pvCard" style="--rc-accent:<?php echo htmlspecialchars($accent); ?>">
                                <div class="rc-header">
                                    <img class="rc-logo" id="pvLogo" alt=""
                                         <?php if ($logo !== ''): ?>src="<?php echo htmlspecialchars($logo); ?>"<?php else: ?>hidden<?php endif; ?>>
                                    <div class="rc-school">
                                        <h2 class="rc-school-name" id="pvSchool"><?php echo htmlspecialchars($brand['result_school_name'] !== '' ? $brand['result_school_name'] : $site['site_name']); ?></h2>
                                        <p class="rc-school-meta" id="pvMeta"></p>
                                    </div>
                                </div>
                                <span class="rc-title" id="pvTitle"></span>

                                <div class="rc-student">
                                    <img class="rc-photo" id="pvPhoto" src="<?php echo htmlspecialchars($site['site_logo']); ?>" alt="">
                                    <div class="rc-fields">
                                        <div class="rc-field"><span>Student Name</span><span>Student One</span></div>
                                        <div class="rc-field"><span>Father Name</span><span>Guardian One</span></div>
                                        <div class="rc-field"><span>Admission No</span><span>STU-2026-0001</span></div>
                                        <div class="rc-field"><span>Roll No</span><span>1</span></div>
                                        <div class="rc-field"><span>Class</span><span>Class 5 – A</span></div>
                                        <div class="rc-field" id="pvDob"><span>Date of Birth</span><span>14 Mar 2014</span></div>
                                    </div>
                                </div>

                                <table class="rc-table">
                                    <thead><tr>
                                        <th>Subject</th><th>Total</th><th>Obtained</th>
                                        <th class="pv-grade">Grade</th><th class="pv-rem">Remarks</th>
                                    </tr></thead>
                                    <tbody>
                                        <tr><td>English <small>(ENG)</small></td><td>100</td><td>82</td><td class="pv-grade">A</td><td class="pv-rem">Excellent</td></tr>
                                        <tr><td>Mathematics <small>(MATH)</small></td><td>100</td><td>91</td><td class="pv-grade">A+</td><td class="pv-rem">Outstanding</td></tr>
                                        <tr class="rc-fail"><td>Science <small>(SCI)</small></td><td>100</td><td>28</td><td class="pv-grade">F</td><td class="pv-rem">Fail</td></tr>
                                        <tr><td>Urdu <small>(URD)</small></td><td>100</td><td><span class="rc-absent">AB</span></td><td class="pv-grade">F</td><td class="pv-rem">Absent</td></tr>
                                    </tbody>
                                </table>

                                <div class="rc-summary">
                                    <div class="rc-summary-item"><span>Total Marks</span><strong>201 / 400</strong></div>
                                    <div class="rc-summary-item"><span>Percentage</span><strong>50.25%</strong></div>
                                    <div class="rc-summary-item"><span>Grade</span><strong>D</strong></div>
                                    <div class="rc-summary-item" id="pvGpa"><span>GPA</span><strong>1.93</strong></div>
                                    <div class="rc-summary-item" id="pvPos"><span>Position</span><strong>3rd of 15</strong></div>
                                    <div class="rc-summary-item"><span>Result</span><strong><b class="rc-badge rc-badge-fail">FAIL</b></strong></div>
                                </div>

                                <p class="rc-school-meta" id="pvFailed">Failed in: Science, Urdu</p>

                                <p class="rc-school-meta" id="pvPrincipalRem">
                                    <strong>Head Teacher&rsquo;s Remarks:</strong> Steady effort — keep it up next term.
                                </p>

                                <div class="rc-verify" id="pvQr">
                                    <div class="rc-qr" data-qr="<?php echo htmlspecialchars(ormsVerifyUrl(str_repeat('0', 32))); ?>"></div>
                                    <span class="rc-verify-txt">Scan to verify this result card</span>
                                </div>

                                <div class="rc-signatures" id="pvSigns">
                                    <div class="rc-sign" id="pvSigL"></div>
                                    <div class="rc-sign" id="pvSigR">
                                        <img class="rc-sign-img initially-hidden" id="pvSignImg" alt=""
                                             <?php if ($sign !== ''): ?>src="<?php echo htmlspecialchars($sign . '?t=' . time()); ?>"<?php endif; ?>>
                                        <span id="pvSigRLbl"></span><span id="pvSigRWho"></span>
                                    </div>
                                </div>

                                <div class="rc-footer" id="pvFooterRow">
                                    <span id="pvFooter"></span><span>Printed: <?php echo date('d M Y'); ?></span>
                                </div>
                            </div>
                        </div>
                        <p class="rs-preview-note">
                            <i class="fas fa-info-circle"></i> Updates as you type. Sample marks only — real cards use live data.
                        </p>
                    </div>

                    <!-- ---------- settings form (right) ---------- -->
                    <div class="rs-form-pane">
                    <form id="brandingForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-building-columns"></i> School Name *</label>
                                <input type="text" id="bSchoolName" name="result_school_name" maxlength="150" required
                                       value="<?php echo htmlspecialchars($brand['result_school_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone</label>
                                <input type="text" id="bPhone" name="result_school_phone" maxlength="40"
                                       value="<?php echo htmlspecialchars($brand['result_school_phone']); ?>">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-location-dot"></i> Address</label>
                                <input type="text" id="bAddress" name="result_school_address" maxlength="255"
                                       value="<?php echo htmlspecialchars($brand['result_school_address']); ?>">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-note-sticky"></i> Footer Note</label>
                                <input type="text" id="bFooter" name="result_footer_note" maxlength="255"
                                       value="<?php echo htmlspecialchars($brand['result_footer_note']); ?>">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-signature"></i> Left Signature Label</label>
                                <input type="text" id="bSigLeft" name="result_signature_left" maxlength="60"
                                       value="<?php echo htmlspecialchars($brand['result_signature_left']); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Printed under the left signature line (e.g. Class Teacher)</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-signature"></i> Right Signature Label</label>
                                <input type="text" id="bSigRight" name="result_signature_right" maxlength="60"
                                       value="<?php echo htmlspecialchars($brand['result_signature_right']); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Printed under the right signature line (e.g. Principal). The head teacher name below prints under it.</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-heading"></i> Card Title</label>
                                <input type="text" id="bTitle" name="result_title" maxlength="60"
                                       value="<?php echo htmlspecialchars($brand['result_title'] !== '' ? $brand['result_title'] : 'Result Card'); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Shown as "First Term — &lt;title&gt; — 2025-2026"</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-palette"></i> Accent Colour</label>
                                <div class="rs-color-row">
                                    <input type="color" id="bAccent" name="result_accent_color"
                                           value="<?php echo htmlspecialchars($accent); ?>"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <input type="text" id="bAccentHex" maxlength="7" value="<?php echo htmlspecialchars($accent); ?>"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                </div>
                                <div class="help-text"><i class="fas fa-info-circle"></i> Header rule, title bar and table head on the printed card</div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-user-tie"></i> Head Teacher / Principal</h2>
                        </div>
                        <div class="info-banner">
                            <i class="fas fa-circle-info"></i>
                            <span>The head teacher <strong>name prints under the right-hand signature label</strong> on every result card, with the
                            designation on the line below it and the uploaded signature image above the signature rule. Leave the name blank and only
                            the label prints. Upload the signature image in <strong>Head Teacher Signature</strong> below.</span>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Head Teacher Name</label>
                                <input type="text" id="bPrincipalName" name="result_principal_name" maxlength="120"
                                       placeholder="e.g. Ayesha Karim"
                                       value="<?php echo htmlspecialchars($brand['result_principal_name']); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Printed under the right-hand signature label on every card</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-badge"></i> Designation</label>
                                <input type="text" id="bPrincipalDesig" name="result_principal_designation" maxlength="60"
                                       placeholder="e.g. Principal"
                                       value="<?php echo htmlspecialchars($brand['result_principal_designation'] !== '' ? $brand['result_principal_designation'] : 'Principal'); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Shown under the name — skipped when it repeats the signature label</div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-sliders"></i> What Prints on the Card</h2>
                        </div>
                        <div class="rs-toggle-grid">
                            <?php foreach ($CARD_TOGGLES as $tk => $tlabel): ?>
                            <div class="rs-toggle-row">
                                <span><?php echo htmlspecialchars($tlabel); ?></span>
                                <div class="toggle-switch">
                                    <input type="checkbox" class="toggle-input js-card-toggle" id="t_<?php echo htmlspecialchars($tk); ?>"
                                           name="<?php echo htmlspecialchars($tk); ?>" value="1"
                                           <?php echo $cardOn[$tk] ? 'checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="t_<?php echo htmlspecialchars($tk); ?>" class="toggle-label"><span class="toggle-slider"></span></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($canEdit): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="brandingSaveBtn"><i class="fas fa-save"></i> Save Branding</button>
                        </div>
                        <?php endif; ?>
                    </form>

                    <div class="section-header">
                        <h2><i class="fas fa-image"></i> Result Logo</h2>
                    </div>
                    <div class="logo-upload-row">
                        <img id="rsLogoPreview" alt="Result logo preview"
                             class="logo-preview-img<?php echo $logo === '' ? ' initially-hidden' : ''; ?>"
                             <?php if ($logo !== ''): ?>src="<?php echo htmlspecialchars($logo . '?t=' . time()); ?>"<?php endif; ?>>
                        <div class="logo-upload-controls">
                            <div class="logo-upload-controls-inner">
                                <input type="file" id="rsLogoFile" class="file-input-styled" accept="image/jpeg,image/png,image/webp"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-primary" id="rsLogoUpload"><i class="fas fa-upload"></i> Upload</button>
                                <button type="button" class="btn btn-danger<?php echo $logo === '' ? ' initially-hidden' : ''; ?>" id="rsLogoRemove"><i class="fas fa-trash"></i> Remove</button>
                                <?php endif; ?>
                            </div>
                            <p class="logo-help-text"><i class="fas fa-info-circle"></i> JPG, PNG or WEBP &middot; max 2MB &middot; square logos print best. Uploaded to <code><?php echo htmlspecialchars(rsBrandDir()); ?></code>.</p>
                            <p id="rsLogoNone" class="help-text<?php echo $logo === '' ? '' : ' initially-hidden'; ?>">
                                <i class="fas fa-triangle-exclamation"></i> No result logo set — cards use the site logo instead.
                            </p>
                        </div>
                    </div>

                    <div class="section-header">
                        <h2><i class="fas fa-file-signature"></i> Head Teacher Signature</h2>
                    </div>
                    <div class="logo-upload-row">
                        <img id="rsSignPreview" alt="Head teacher signature preview"
                             class="sign-preview-img<?php echo $sign === '' ? ' initially-hidden' : ''; ?>"
                             <?php if ($sign !== ''): ?>src="<?php echo htmlspecialchars($sign . '?t=' . time()); ?>"<?php endif; ?>>
                        <div class="logo-upload-controls">
                            <div class="logo-upload-controls-inner">
                                <input type="file" id="rsSignFile" class="file-input-styled" accept="image/jpeg,image/png,image/webp"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-primary" id="rsSignUpload"><i class="fas fa-upload"></i> Upload</button>
                                <button type="button" class="btn btn-danger<?php echo $sign === '' ? ' initially-hidden' : ''; ?>" id="rsSignRemove"><i class="fas fa-trash"></i> Remove</button>
                                <?php endif; ?>
                            </div>
                            <p class="logo-help-text"><i class="fas fa-info-circle"></i> JPG, PNG or WEBP &middot; max 2MB &middot; a wide transparent PNG scan prints best. Uploaded to <code><?php echo htmlspecialchars(rsBrandDir()); ?></code>.</p>
                            <p id="rsSignNone" class="help-text<?php echo $sign === '' ? '' : ' initially-hidden'; ?>">
                                <i class="fas fa-triangle-exclamation"></i> No signature image set — cards print the signature line and name only.
                            </p>
                        </div>
                    </div>
                    </div><!-- /.rs-form-pane -->
                    </div><!-- /.rs-split -->
                </div>

                <!-- ============ TAB 2: EXAM TERMS ============ -->
                <div class="tab-pane" id="pane-terms">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-check"></i> Exam Terms</h2>
                        <div class="btn-group-inline">
                            <button type="button" class="btn btn-primary" id="termsRefresh"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="termAddBtn"><i class="fas fa-plus"></i> Add Term</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-banner">
                        <i class="fas fa-lock-open"></i>
                        A term must be <strong>Open</strong> before teachers can enter marks. <strong>Closed</strong> locks entry, <strong>Upcoming</strong> hides it from marks entry.
                    </div>
                    <div class="info-banner info-banner-top">
                        <i class="fas fa-percent"></i>
                        <strong>Weightage</strong> is this term's share of a combined annual result. Leave every term at <strong>0</strong> and weighting is simply not used —
                        otherwise the terms in a year should add up to <strong>100%</strong>.
                    </div>

                    <div class="info-banner info-banner-top initially-hidden" id="termWeightNote"></div>

                    <div class="filters-section">
                        <div class="filters-header">
                            <h3><i class="fas fa-filter"></i> Academic Year</h3>
                        </div>
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label><i class="fas fa-calendar-days"></i> Show terms of</label>
                                <select id="termYear" class="filter-input" data-label="Academic Year">
                                    <?php foreach ($years as $y): ?>
                                    <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$y['id'] === $curYearId ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($y['name']) . ((int)$y['is_current'] === 1 ? ' (Current)' : ''); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="about-table-wrapper">
                        <table class="about-roles-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-hashtag"></i> Order</th>
                                    <th><i class="fas fa-tag"></i> Term</th>
                                    <th><i class="fas fa-traffic-light"></i> Status</th>
                                    <th><i class="fas fa-calendar-week"></i> Exam Window</th>
                                    <th><i class="fas fa-bullhorn"></i> Result Date</th>
                                    <th><i class="fas fa-percent"></i> Weightage</th>
                                    <th><i class="fas fa-pen-to-square"></i> Marks</th>
                                    <th><i class="fas fa-gears"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody id="termsBody"><?= rsSkeleton(8) ?></tbody>
                        </table>
                    </div>
                </div>

                <!-- ============ TAB 3: ACADEMIC YEARS ============ -->
                <div class="tab-pane" id="pane-years">
                    <div class="section-header">
                        <h2><i class="fas fa-calendar-days"></i> Academic Years</h2>
                        <div class="btn-group-inline">
                            <button type="button" class="btn btn-primary" id="yearsRefresh"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="yearAddBtn"><i class="fas fa-plus"></i> Add Year</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-banner">
                        <i class="fas fa-star"></i>
                        Exactly one year is <strong>current</strong> at a time — it drives students, marks entry and results everywhere in the app.
                    </div>
                    <div class="info-banner info-banner-top info-banner-warning">
                        <i class="fas fa-lock"></i>
                        <span><strong>Locking is the year-end close.</strong> Lock a year once its results are final and published — it marks the year as finished
                        and takes it out of the running. The <strong>current year can never be locked</strong>: make another year current first.</span>
                    </div>

                    <div class="about-table-wrapper">
                        <table class="about-roles-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-tag"></i> Year</th>
                                    <th><i class="fas fa-play"></i> Start</th>
                                    <th><i class="fas fa-flag-checkered"></i> End</th>
                                    <th><i class="fas fa-star"></i> Current</th>
                                    <th><i class="fas fa-lock"></i> Locked</th>
                                    <th><i class="fas fa-chart-simple"></i> Usage</th>
                                    <th><i class="fas fa-gears"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody id="yearsBody"><?= rsSkeleton(7) ?></tbody>
                        </table>
                    </div>
                </div>

                <!-- ============ TAB 4: GRADING SCHEME (SETS) ============ -->
                <div class="tab-pane" id="pane-grading">
                    <div class="section-header">
                        <h2><i class="fas fa-layer-group"></i> Grading Sets</h2>
                        <div class="btn-group-inline">
                            <button type="button" class="btn btn-primary" id="gradingRefresh"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="gsCloneBtn"><i class="fas fa-clone"></i> Clone This Set</button>
                            <button type="button" class="btn btn-secondary" id="gsAddBtn"><i class="fas fa-plus"></i> New Set</button>
                            <button type="button" class="btn btn-secondary" id="gsRenameBtn"><i class="fas fa-pen"></i> Rename</button>
                            <button type="button" class="btn btn-secondary" id="gsDefaultBtn"><i class="fas fa-star"></i> Make Default</button>
                            <button type="button" class="btn btn-danger" id="gsDelBtn"><i class="fas fa-trash"></i> Delete Set</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$hasSets): ?>
                    <div class="info-banner info-banner-warning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Grading sets are not installed on this database yet. Run <strong>update_setup.php</strong> once, then reload this page.</span>
                    </div>
                    <?php endif; ?>

                    <div class="info-banner">
                        <i class="fas fa-circle-info"></i>
                        <span>A school rarely grades Nursery the way it grades Grade&nbsp;9. Each <strong>grading set</strong> is a complete scheme of its own.
                        A class picks its set under <strong>Assessment &rarr; Grading &amp; Assessment per Class</strong>; a class that picks nothing follows the
                        <strong>default</strong> set. <strong>Clone This Set</strong> copies every band of the set you are looking at &mdash; the two-click way to
                        build "Nursery" out of "Default".</span>
                    </div>

                    <div class="gs-bar" id="gsBar"></div>
                    <div class="info-banner info-banner-top initially-hidden" id="gsNote"></div>

                    <div class="section-header">
                        <h2><i class="fas fa-award"></i> Grade Bands <span class="myr-sub" id="gsCurName"></span></h2>
                        <div class="btn-group-inline">
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="gradeAddBtn"><i class="fas fa-plus"></i> Add Band</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-banner">
                        <i class="fas fa-circle-info"></i>
                        A percentage resolves to the <strong>highest band whose min% is at or below it, within the selected set</strong>. Overlapping bands
                        are rejected; gaps are allowed but flagged below, because they silently widen the band underneath. Bands in one set never clash with
                        bands in another &mdash; "A" may mean 80&ndash;100 in Nursery and 75&ndash;100 in Senior.
                    </div>
                    <div class="info-banner info-banner-top">
                        <i class="fas fa-palette"></i>
                        <strong>Fail</strong> marks the bands that count as a failing grade. <strong>Colour</strong> tints that grade on the result card &mdash;
                        leave it blank and the card uses the theme colour. <strong>Interpretation</strong> is the parent-facing meaning
                        ("Excellent &mdash; has mastered the skill") and <strong>prints in the grade key on the report card</strong>.
                    </div>

                    <div class="info-banner info-banner-top info-banner-warning initially-hidden" id="gradeIssues"></div>

                    <div class="about-table-wrapper">
                        <table class="about-roles-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-hashtag"></i> Order</th>
                                    <th><i class="fas fa-award"></i> Grade</th>
                                    <th><i class="fas fa-percent"></i> Range</th>
                                    <th><i class="fas fa-star-half-stroke"></i> Point</th>
                                    <th><i class="fas fa-circle-xmark"></i> Fail</th>
                                    <th><i class="fas fa-palette"></i> Colour</th>
                                    <th><i class="fas fa-comment-dots"></i> Remarks</th>
                                    <th><i class="fas fa-comments"></i> Interpretation</th>
                                    <th><i class="fas fa-gears"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody id="gradesBody"><?= rsSkeleton(9) ?></tbody>
                        </table>
                    </div>

                    <div class="section-header">
                        <h2><i class="fas fa-vial"></i> Live Grade Preview</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Type a percentage</label>
                            <input type="number" id="pvPct" min="0" max="100" step="0.01" value="85">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Resolved with the same rule the result engine uses, against the selected set</div>
                        </div>
                    </div>
                    <div class="stat-mini" id="pvOut"></div>
                </div>

                <!-- ============ TAB 5: ASSESSMENT (CA / EXAM COMPONENTS) ============ -->
                <div class="tab-pane" id="pane-assessment">
                    <div class="section-header">
                        <h2><i class="fas fa-percent"></i> Assessment Schemes</h2>
                        <div class="btn-group-inline">
                            <button type="button" class="btn btn-primary" id="asRefresh"><i class="fas fa-sync"></i> Refresh</button>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="asCloneBtn"><i class="fas fa-clone"></i> Clone This Scheme</button>
                            <button type="button" class="btn btn-secondary" id="asAddBtn"><i class="fas fa-plus"></i> New Scheme</button>
                            <button type="button" class="btn btn-secondary" id="asRenameBtn"><i class="fas fa-pen"></i> Rename</button>
                            <button type="button" class="btn btn-secondary" id="asActiveBtn"><i class="fas fa-toggle-on"></i> Deactivate</button>
                            <button type="button" class="btn btn-danger" id="asDelBtn"><i class="fas fa-trash"></i> Delete Scheme</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$hasAsmt): ?>
                    <div class="info-banner info-banner-warning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Assessment schemes are not installed on this database yet. Run <strong>update_setup.php</strong> once, then reload this page.</span>
                    </div>
                    <?php endif; ?>

                    <div class="info-banner">
                        <i class="fas fa-circle-info"></i>
                        <span>An <strong>assessment scheme</strong> splits a subject's 100 marks into the pieces the school actually marks &mdash;
                        class exercises, projects, mini exams and the end-of-term exam. Each component has its own <strong>max marks</strong> (what the
                        teacher types) and a <strong>weight</strong> (its share of the subject's 100). Weights adding to 100 give the classic
                        <strong>50 / 50</strong> or <strong>30 / 70</strong> split.</span>
                    </div>
                    <div class="info-banner info-banner-top info-banner-warning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span><strong>A scheme beats the theory / practical split.</strong> Once a class is on an assessment scheme, its subjects are marked
                        component by component and the subject's theory / practical breakdown is ignored for that class.</span>
                    </div>

                    <div class="gs-bar" id="asBar"></div>
                    <div class="info-banner info-banner-top initially-hidden" id="asNote"></div>

                    <div class="section-header">
                        <h2><i class="fas fa-list-check"></i> Components <span class="myr-sub" id="asCurName"></span></h2>
                        <div class="btn-group-inline">
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn btn-success" id="acAddBtn"><i class="fas fa-plus"></i> Add Component</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ac-wrap">
                        <div class="about-table-wrapper">
                            <table class="about-roles-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-hashtag"></i> Order</th>
                                        <th><i class="fas fa-tag"></i> Component</th>
                                        <th><i class="fas fa-pen-to-square"></i> Max Marks</th>
                                        <th><i class="fas fa-percent"></i> Weight</th>
                                        <th><i class="fas fa-flag-checkered"></i> Counts As</th>
                                        <th><i class="fas fa-gears"></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="acBody"><?= rsSkeleton(6, 4) ?></tbody>
                            </table>
                        </div>
                        <div class="ac-sum" id="acSum"></div>
                    </div>

                    <div class="info-banner info-banner-top" id="acExample">
                        <i class="fas fa-calculator"></i>
                        <span>Add components to see a worked example.</span>
                    </div>

                    <div class="section-header">
                        <h2><i class="fas fa-chalkboard"></i> Grading &amp; Assessment per Class</h2>
                    </div>
                    <div class="info-banner">
                        <i class="fas fa-circle-info"></i>
                        <span>One row per active class. <strong>&mdash; inherit default &mdash;</strong> grades the class with the default grading set;
                        <strong>&mdash; no components &mdash;</strong> keeps the class on a single mark box. The same two settings also appear on the
                        <strong>Classes</strong> page &mdash; both write the same two columns, so whichever you use last wins.</span>
                    </div>

                    <?php if ($hasSets && $asgClasses): ?>
                    <form id="classAssignForm">
                        <div class="about-table-wrapper">
                            <table class="about-roles-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-chalkboard"></i> Class</th>
                                        <th><i class="fas fa-layer-group"></i> Grading Set</th>
                                        <th><i class="fas fa-percent"></i> Assessment Scheme</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($asgClasses as $c): ?>
                                    <tr>
                                        <td><i class="fas fa-chalkboard"></i> <strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
                                        <td>
                                            <select class="filter-input js-class-gset" data-label="Grading Set"
                                                    name="class_gset[<?php echo (int)$c['id']; ?>]"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                                <option value="">&mdash; inherit default &mdash;</option>
                                                <?php foreach ($gsList as $g): ?>
                                                <option value="<?php echo (int)$g['id']; ?>"<?php echo (int)($c['grading_set_id'] ?? 0) === (int)$g['id'] ? ' selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($g['name']) . ((int)$g['is_default'] === 1 ? ' (Default)' : ''); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="filter-input js-class-ascheme" data-label="Assessment Scheme"
                                                    name="class_ascheme[<?php echo (int)$c['id']; ?>]"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                                <option value="">&mdash; no components &mdash;</option>
                                                <?php foreach ($asList as $a): ?>
                                                <option value="<?php echo (int)$a['id']; ?>"<?php echo (int)($c['assessment_scheme_id'] ?? 0) === (int)$a['id'] ? ' selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($a['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="classAssignSaveBtn"><i class="fas fa-save"></i> Save Class Assignments</button>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php else: ?>
                    <div class="help-text"><i class="fas fa-info-circle"></i> No active classes yet &mdash; add classes first, then assign their grading set and assessment scheme here.</div>
                    <?php endif; ?>
                </div>

                <!-- ============ TAB 6: TEMPLATES ============ -->
                <div class="tab-pane" id="pane-templates">
                    <div class="section-header">
                        <h2><i class="fas fa-file-invoice"></i> Result Card Templates</h2>
                    </div>
                    <div class="info-banner">
                        <i class="fas fa-circle-info"></i>
                        Pick the default design for every result card, then pin a different one per class below. A card resolves as
                        <strong>class template &rarr; default template</strong>. The <strong>Academic Report</strong> design is term-wise —
                        it prints every published term of the year side by side with a year overall.
                    </div>

                    <div class="rs-split rs-split-wide">

                    <!-- ---------- template preview (left) ---------- -->
                    <div class="rs-preview-pane">
                        <div class="rs-preview-head">
                            <span><i class="fas fa-eye"></i> Template Preview</span>
                            <span class="rs-preview-actions">
                                <button type="button" class="btn btn-secondary btn-sm" id="btnTplPrintPv">
                                    <i class="fas fa-print"></i> Print Preview
                                </button>
                                <span class="rs-preview-badge">Sample data</span>
                            </span>
                        </div>
                        <div class="rs-preview-card" id="tplPvBox">
                            <?php foreach (rsTplPreviews($site) as $tk => $html): ?>
                            <div class="tpl-pv<?php echo $tplDefault === $tk ? ' active' : ''; ?>" data-tpl="<?php echo htmlspecialchars($tk); ?>"><?php echo $html; ?></div>
                            <?php endforeach; ?>
                        </div>
                        <p class="rs-preview-note">
                            <i class="fas fa-info-circle"></i> Click a template card to preview its design. Uses your saved branding, accent and card toggles — sample marks only.
                        </p>
                    </div>

                    <!-- ---------- template settings (right) ---------- -->
                    <div class="rs-form-pane">
                    <form id="templatesForm">
                        <div class="tplg-grid">
                            <?php foreach ($TPLS as $tk => $t): ?>
                            <label class="tplg-card<?php echo $tplDefault === $tk ? ' active' : ''; ?>">
                                <input type="radio" name="result_template" value="<?php echo htmlspecialchars($tk); ?>"<?php echo $tplDefault === $tk ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                <i class="fas fa-circle-check tplg-check"></i>
                                <?php echo rsTplThumb($tk); ?>
                                <div class="tplg-name"><?php echo htmlspecialchars($t['label']); ?><?php if ($t['termwise']): ?><span class="tplg-chip">Term-wise</span><?php endif; ?></div>
                                <div class="tplg-desc"><?php echo htmlspecialchars($t['desc']); ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-chalkboard"></i> Template per Class</h2>
                        </div>
                        <div class="info-banner">
                            <i class="fas fa-circle-info"></i>
                            <strong>Use default</strong> follows the selection above. A specific pick pins that class to one design —
                            single cards and bulk section prints both honour it.
                        </div>

                        <?php if ($tplClasses): ?>
                        <div class="form-grid">
                            <?php foreach ($tplClasses as $c): ?>
                            <div class="form-group">
                                <label><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($c['name']); ?></label>
                                <select class="js-class-tpl" name="class_tpl[<?php echo (int)$c['id']; ?>]"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <option value="">Use default</option>
                                    <?php foreach ($TPLS as $tk => $t): ?>
                                    <option value="<?php echo htmlspecialchars($tk); ?>"<?php echo ($c['result_template'] ?? '') === $tk ? ' selected' : ''; ?>><?php echo htmlspecialchars($t['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="help-text"><i class="fas fa-info-circle"></i> No active classes yet — add classes first, then pin templates here.</div>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="templatesSaveBtn"><i class="fas fa-save"></i> Save Templates</button>
                        </div>
                        <?php endif; ?>
                    </form>
                    </div>

                    </div>
                </div>

                <!-- ============ TAB 7: OPTIONS ============ -->
                <div class="tab-pane" id="pane-options">
                    <div class="section-header">
                        <h2><i class="fas fa-id-card"></i> Result Card Options</h2>
                    </div>

                    <form id="optionsForm">
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-ranking-star"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Show Position</div>
                                    <div class="control-desc">Print the student's rank within the section on the result card.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optPosition" name="result_show_position" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['result_show_position'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optPosition" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optPosition">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-graduation-cap"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Show GPA</div>
                                    <div class="control-desc">Print the grade-point average alongside the overall grade.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optGpa" name="result_show_gpa" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['result_show_gpa'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optGpa" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optGpa">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-image-portrait"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Show Student Photo</div>
                                    <div class="control-desc">Print the student's photo on the result card when one is uploaded.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optPhoto" name="result_show_photo" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['result_show_photo'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optPhoto" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optPhoto">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-file-lines"></i> Report Card Extras</h2>
                        </div>
                        <div class="info-banner">
                            <i class="fas fa-circle-info"></i>
                            <span>These blocks only print when there is something to print &mdash; a student with no attendance recorded for the term simply
                            shows no attendance line, and a class with no assessment scheme shows no CA / Exam columns.</span>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-user-check"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Show Attendance</div>
                                    <div class="control-desc">Print days present out of the days the school was in session for the term.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optAttendance" name="result_show_attendance" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['result_show_attendance'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optAttendance" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optAttendance">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-key"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Show Grade Key</div>
                                    <div class="control-desc">Print the grade interpretation key &mdash; grade, range and what it means &mdash; under the card, so a parent can read "B2".</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optGradeKey" name="result_show_grade_key" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['result_show_grade_key'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optGradeKey" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optGradeKey">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-table-columns"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Show CA &amp; Exam Columns</div>
                                    <div class="control-desc">For classes on an assessment scheme, print the continuous-assessment and exam halves beside the subject total.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optCaCols" name="result_show_ca_columns" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['result_show_ca_columns'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optCaCols" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optCaCols">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-quote-left"></i> Grade Key Note</label>
                                <input type="text" id="optKeyNote" name="result_grade_key_note" maxlength="255"
                                       value="<?php echo htmlspecialchars($opt['result_grade_key_note']); ?>"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                <div class="help-text"><i class="fas fa-info-circle"></i> One sentence printed under the grade key. Leave blank to print the key with no note.</div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-hand"></i> Result Withholding</h2>
                        </div>
                        <div class="info-banner info-banner-warning">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span>Withholding <strong>hides a card from the student and guardian only</strong> &mdash; it never blocks publishing, and Admin
                            and Principal still see the card with a WITHHELD stamp. The <strong>ledger that produces the balance lives in Fees</strong>
                            (charges minus payments per student, per academic year); a per-student manual hold also lives there.</span>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-money-bill"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Withhold Results on Arrears</div>
                                    <div class="control-desc">Do not show a result to a student who still owes the school.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optWithhold" name="withhold_on_arrears" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['withhold_on_arrears'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optWithhold" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optWithhold">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-scale-balanced"></i> Arrears Threshold (<?php echo htmlspecialchars($cur['symbol'] . ' ' . $cur['code']); ?>)</label>
                                <input type="number" id="optThreshold" name="arrears_threshold" min="0" step="0.01"
                                       value="<?php echo htmlspecialchars($opt['arrears_threshold']); ?>"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                <div class="help-text"><i class="fas fa-info-circle"></i> A balance <strong>strictly above</strong> this is withheld. 0 withholds on any outstanding balance.</div>
                            </div>
                            <div class="form-group form-group-full">
                                <label><i class="fas fa-comment-slash"></i> Withhold Message</label>
                                <input type="text" id="optWithholdMsg" name="withhold_message" maxlength="255"
                                       value="<?php echo htmlspecialchars($opt['withhold_message']); ?>"<?php echo $canEdit ? '' : ' disabled'; ?>>
                                <div class="help-text"><i class="fas fa-info-circle"></i> Shown to the parent instead of the card &mdash; keep it polite and point them at the office.</div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-user-check"></i> Result Approval</h2>
                        </div>
                        <div class="info-banner">
                            <i class="fas fa-circle-info"></i>
                            <span>With this on, a section's results must be <strong>approved before they can be published</strong> — students never
                            see an unchecked result. Both the <strong>Principal and an Admin</strong> can approve, so the school can never lock itself
                            out if the head teacher is away. Turn it off and publishing goes straight through.</span>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-user-check"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Require Principal Approval Before Publishing Results</div>
                                    <div class="control-desc">A section must be approved by the Principal (or an Admin) before its results can go live.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optApproval" name="require_principal_approval" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['require_principal_approval'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optApproval" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optApproval">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="section-header">
                            <h2><i class="fas fa-user-gear"></i> Account Defaults</h2>
                        </div>

                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-user-plus"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Allow Public Signup</div>
                                    <div class="control-desc">Off by default — an admin creates every student and teacher account.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="optSignup" name="allow_public_signup" class="toggle-input-large rs-toggle" value="1"<?php echo $opt['allow_public_signup'] ? ' checked' : ''; ?><?php echo $canEdit ? '' : ' disabled'; ?>>
                                    <label for="optSignup" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                                </div>
                                <div class="toggle-status" data-for="optSignup">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> Student Default Password *</label>
                                <input type="text" id="optStudentPwd" name="student_default_password" maxlength="50" required
                                       value="<?php echo htmlspecialchars($opt['student_default_password']); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Given to every newly registered student (min 6 characters)</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> Teacher Default Password *</label>
                                <input type="text" id="optTeacherPwd" name="teacher_default_password" maxlength="50" required
                                       value="<?php echo htmlspecialchars($opt['teacher_default_password']); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Given to every newly created teacher (min 6 characters)</div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-hashtag"></i> Admission No Prefix *</label>
                                <input type="text" id="optPrefix" name="admission_no_prefix" maxlength="10" required
                                       value="<?php echo htmlspecialchars($opt['admission_no_prefix']); ?>">
                                <div class="help-text"><i class="fas fa-info-circle"></i> Admission numbers become <strong>PREFIX-YEAR-0001</strong> (letters, digits or dash)</div>
                            </div>
                        </div>

                        <?php if ($canEdit): ?>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="optionsSaveBtn"><i class="fas fa-save"></i> Save Options</button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Term Modal -->
    <div class="modal-overlay" id="termModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="termModalTitle"><i class="fas fa-calendar-plus"></i> Add Term</h3>
                <button type="button" class="close-btn" data-close="termModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="termForm">
                    <input type="hidden" id="termId" name="id" value="0">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Term Name *</label>
                            <input type="text" id="termName" name="name" maxlength="50" required placeholder="e.g. First Term">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Sort Order</label>
                            <input type="number" id="termSort" name="sort_order" min="0" max="999" step="1" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-traffic-light"></i> Status *</label>
                            <select id="termStatus" name="status" data-label="Status">
                                <option value="Upcoming">Upcoming — hidden from marks entry</option>
                                <option value="Open">Open — teachers can enter marks</option>
                                <option value="Closed">Closed — marks entry locked</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-play"></i> Exam Start Date</label>
                            <input type="date" id="termStart" name="start_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag-checkered"></i> Exam End Date</label>
                            <input type="date" id="termEnd" name="end_date">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Must be on or after the start date.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-bullhorn"></i> Result Date</label>
                            <input type="date" id="termResult" name="result_date">
                            <div class="help-text"><i class="fas fa-info-circle"></i> The day results are announced for this term.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Weightage (%)</label>
                            <input type="number" id="termWeight" name="weightage" min="0" max="100" step="0.01" value="0">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Share of the combined annual result. 0 on every term = weighting unused.</div>
                        </div>
                    </div>
                    <div class="info-banner initially-hidden" id="termFormWeight"></div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="termSaveBtn"><i class="fas fa-save"></i> Save Term</button>
                        <button type="button" class="btn btn-secondary" data-close="termModal"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Year Modal -->
    <div class="modal-overlay" id="yearModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="yearModalTitle"><i class="fas fa-calendar-plus"></i> Add Academic Year</h3>
                <button type="button" class="close-btn" data-close="yearModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="yearForm">
                    <input type="hidden" id="yearId" name="id" value="0">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Year Name *</label>
                            <input type="text" id="yearName" name="name" maxlength="20" required placeholder="e.g. 2025-2026">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-play"></i> Start Date</label>
                            <input type="date" id="yearStart" name="start_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag-checkered"></i> End Date</label>
                            <input type="date" id="yearEnd" name="end_date">
                        </div>
                    </div>
                    <div class="control-group">
                        <div class="control-group-header">
                            <div class="control-icon"><i class="fas fa-star"></i></div>
                            <div class="control-info">
                                <div class="control-title">Set as Current Year</div>
                                <div class="control-desc">Only one year can be current — turning this on clears it from every other year.</div>
                            </div>
                        </div>
                        <div class="control-toggle-wrapper">
                            <div class="toggle-switch-large">
                                <input type="checkbox" id="yearCurrent" name="is_current" class="toggle-input-large rs-toggle" value="1">
                                <label for="yearCurrent" class="toggle-label-large"><span class="toggle-slider-large"></span></label>
                            </div>
                            <div class="toggle-status" data-for="yearCurrent">
                                <span class="status-dot status-disabled"></span>
                                <span class="status-text">Disabled</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="yearSaveBtn"><i class="fas fa-save"></i> Save Year</button>
                        <button type="button" class="btn btn-secondary" data-close="yearModal"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Grade Modal -->
    <div class="modal-overlay" id="gradeModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="gradeModalTitle"><i class="fas fa-plus"></i> Add Grade Band</h3>
                <button type="button" class="close-btn" data-close="gradeModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="gradeForm">
                    <input type="hidden" id="gradeId" name="id" value="0">
                    <input type="hidden" id="gradeSetId" name="set_id" value="0">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-award"></i> Grade *</label>
                            <input type="text" id="gradeLabel" name="grade" maxlength="5" required placeholder="e.g. A+">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-star-half-stroke"></i> Grade Point *</label>
                            <input type="number" id="gradePoint" name="grade_point" min="0" max="10" step="0.1" value="0" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-down-1-9"></i> Min Percent *</label>
                            <input type="number" id="gradeMin" name="min_percent" min="0" max="100" step="0.01" value="0" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-up-9-1"></i> Max Percent *</label>
                            <input type="number" id="gradeMax" name="max_percent" min="0" max="100" step="0.01" value="100" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-comment-dots"></i> Remarks</label>
                            <input type="text" id="gradeRemarks" name="remarks" maxlength="100" placeholder="e.g. Outstanding">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Short word printed in the card's Remarks column.</div>
                        </div>
                        <div class="form-group form-group-full">
                            <label><i class="fas fa-comments"></i> Interpretation</label>
                            <textarea id="gradeInterp" name="interpretation" rows="2" maxlength="255"
                                      placeholder="e.g. Excellent — has mastered the skill"></textarea>
                            <div class="help-text"><i class="fas fa-info-circle"></i> The parent-facing meaning of this grade. <strong>Prints in the grade key on the report card</strong> when Show Grade Key is on.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Sort Order</label>
                            <input type="number" id="gradeSort" name="sort_order" min="0" max="999" step="1" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-palette"></i> Grade Colour</label>
                            <div class="rs-color-row">
                                <input type="color" id="gradeColor" value="#001f3f">
                                <input type="text" id="gradeColorHex" name="color" maxlength="7" placeholder="blank = theme colour">
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Tints this grade on the result card. Clear the hex box to use the theme colour.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-circle-xmark"></i> Counts as a Fail</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="gradeFail" name="is_fail" value="1" class="toggle-input">
                                <label for="gradeFail" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> A student landing in this band is treated as failing.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="gradeSaveBtn"><i class="fas fa-save"></i> Save Band</button>
                        <button type="button" class="btn btn-secondary" data-close="gradeModal"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Grading Set Modal — one form does add / rename / clone -->
    <div class="modal-overlay" id="gsModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="gsModalTitle"><i class="fas fa-layer-group"></i> New Grading Set</h3>
                <button type="button" class="close-btn" data-close="gsModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="gsForm">
                    <input type="hidden" id="gsMode" value="add">
                    <input type="hidden" id="gsId" name="id" value="0">
                    <div class="info-banner initially-hidden" id="gsCloneNote"></div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Set Name *</label>
                            <input type="text" id="gsName" name="name" maxlength="60" required placeholder="e.g. Lower Primary (Descriptive)">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Must be unique &mdash; it is what you pick per class.</div>
                        </div>
                        <div class="form-group form-group-full">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <input type="text" id="gsDesc" name="description" maxlength="255" placeholder="e.g. Descriptive grading for Nursery to Grade 2">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="gsSaveBtn"><i class="fas fa-save"></i> Save Set</button>
                        <button type="button" class="btn btn-secondary" data-close="gsModal"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assessment Scheme Modal — add / rename / clone -->
    <div class="modal-overlay" id="asModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="asModalTitle"><i class="fas fa-percent"></i> New Assessment Scheme</h3>
                <button type="button" class="close-btn" data-close="asModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="asForm">
                    <input type="hidden" id="asMode" value="add">
                    <input type="hidden" id="asId" name="id" value="0">
                    <div class="info-banner initially-hidden" id="asCloneNote"></div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Scheme Name *</label>
                            <input type="text" id="asName" name="name" maxlength="60" required placeholder="e.g. 50 / 50 (CA &amp; Exam)">
                            <div class="help-text"><i class="fas fa-info-circle"></i> Must be unique &mdash; it is what you pick per class.</div>
                        </div>
                        <div class="form-group form-group-full">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <input type="text" id="asDesc" name="description" maxlength="255" placeholder="e.g. Half continuous assessment, half end-of-term exam">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="asSaveBtn"><i class="fas fa-save"></i> Save Scheme</button>
                        <button type="button" class="btn btn-secondary" data-close="asModal"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Component Modal -->
    <div class="modal-overlay" id="acModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="acModalTitle"><i class="fas fa-plus"></i> Add Component</h3>
                <button type="button" class="close-btn" data-close="acModal"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="acForm">
                    <input type="hidden" id="acId" name="id" value="0">
                    <input type="hidden" id="acSchemeId" name="scheme_id" value="0">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Component Name *</label>
                            <input type="text" id="acName" name="name" maxlength="60" required placeholder="e.g. Class Exercises">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Sort Order</label>
                            <input type="number" id="acSort" name="sort_order" min="0" max="999" step="1" value="0">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-pen-to-square"></i> Max Marks *</label>
                            <input type="number" id="acMax" name="max_marks" min="0.01" max="9999" step="0.01" value="20" required>
                            <div class="help-text"><i class="fas fa-info-circle"></i> The raw ceiling a teacher types for this piece of work.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> Weight (%) *</label>
                            <input type="number" id="acWeight" name="weight_percent" min="0" max="100" step="0.01" value="0" required>
                            <div class="help-text"><i class="fas fa-info-circle"></i> Its share of the subject's 100. All components together should add up to 100.</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag-checkered"></i> This is the Final Exam</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="acIsExam" name="is_exam" value="1" class="toggle-input">
                                <label for="acIsExam" class="toggle-label"><span class="toggle-slider"></span></label>
                            </div>
                            <div class="help-text"><i class="fas fa-info-circle"></i> On = the exam half of the split. Off = continuous assessment (CA).</div>
                        </div>
                    </div>
                    <div class="info-banner initially-hidden" id="acFormNote"></div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="acSaveBtn"><i class="fas fa-save"></i> Save Component</button>
                        <button type="button" class="btn btn-secondary" data-close="acModal"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============ Print preview (full-size A4 sheet of the selected template) ============ -->
    <!-- printable-modal opts this one out of the blanket "hide every overlay" print rule -->
    <div class="modal-overlay printable-modal" id="tplPrintModal">
        <div class="modal modal-wide">
            <div class="modal-header no-print">
                <h3><i class="fas fa-print"></i> Print Preview <span class="myr-sub" id="tplPrintName"></span></h3>
                <div class="btn-group-inline">
                    <button type="button" class="btn btn-primary btn-sm" id="btnTplDoPrint"><i class="fas fa-print"></i> Print / Save PDF</button>
                    <button type="button" class="close-btn" data-close="tplPrintModal"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="info-banner no-print">
                    <i class="fas fa-circle-info"></i>
                    <span>Full-size A4 view of the selected design with your saved branding and card options &mdash; sample marks only. What prints is exactly this sheet.</span>
                </div>
                <div class="table-scroll-hint no-print">
                    <i class="fas fa-arrows-alt-h"></i> Swipe across the sheet &mdash; pinch to zoom
                </div>
                <div class="a4-wrap"><div class="a4-sheet" id="tplPrintSheet"></div></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?php echo csrfToken(); ?>';</script>
    <script>
    (function ($) {
        'use strict';

        var CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
        var esc = ORMS.esc;
        var state = { years: [], terms: [], grades: [], sets: [], setId: 0, schemes: [], schemeId: 0, comps: [] };

        // 90.00 -> 90
        function num(v) {
            var n = parseFloat(v);
            if (isNaN(n)) return '0';
            return String(Math.round(n * 100) / 100);
        }
        function dash(v) { return (v === null || v === undefined || v === '') ? '<span class="text-muted">&mdash;</span>' : esc(v); }
        function empty(cols, icon, title, msg) {
            return '<tr><td colspan="' + cols + '"><div class="orms-empty"><i class="fas ' + icon + '"></i>' +
                   '<h4>' + esc(title) + '</h4><p>' + esc(msg) + '</p></div></td></tr>';
        }
        function loadFail(cols) {
            return empty(cols, 'fa-triangle-exclamation', 'Could not load', 'Hit Refresh to try again.');
        }
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // ---------------- tabs
        $('#rsTabs').on('click', '.tab-btn', function () {
            var t = $(this).data('tab'), $btn = $(this);
            ORMS.swap(function () {                       // crossfade, not a snap
                $('#rsTabs .tab-btn').removeClass('active');
                $btn.addClass('active');
                $('.tab-pane').removeClass('active');
                $('#pane-' + t).addClass('active');
            });
        });

        // ---------------- toggles: keep the status pill honest
        function syncToggle(el) {
            var $s = $('.toggle-status[data-for="' + el.id + '"]');
            if (!$s.length) return;
            $s.find('.status-dot').toggleClass('status-enabled', el.checked).toggleClass('status-disabled', !el.checked);
            $s.find('.status-text').text(el.checked ? 'Enabled' : 'Disabled');
        }
        $(document).on('change', '.rs-toggle', function () { syncToggle(this); });
        $('.rs-toggle').each(function () { syncToggle(this); });

        // ---------------- branding + live preview
        // mirrors ormsRenderResultCard() - keep the two in step when either changes
        var SITE_NAME = <?php echo json_encode($site['site_name']); ?>;
        var HAS_SIGN  = <?php echo $sign !== '' ? 'true' : 'false'; ?>;   // head teacher signature uploaded?

        function pvShow(sel, on) { $(sel).toggleClass('initially-hidden', !on); }

        function renderPreview() {
            var name = $.trim($('#bSchoolName').val()) || SITE_NAME;
            $('#pvSchool').text(name);

            var meta = [];
            if ($.trim($('#bAddress').val())) meta.push($.trim($('#bAddress').val()));
            if ($.trim($('#bPhone').val()))   meta.push('Phone: ' + $.trim($('#bPhone').val()));
            $('#pvMeta').html(meta.map(ORMS.esc).join('<br>')).toggleClass('initially-hidden', meta.length === 0);

            var title = $.trim($('#bTitle').val()) || 'Result Card';
            $('#pvTitle').text('First Term — ' + title + ' — 2025-2026');

            $('#pvSigL').text($.trim($('#bSigLeft').val()) || 'Class Teacher');

            // head teacher rides under the right-hand label — scan above the rule, name + designation below it
            var sigR   = $.trim($('#bSigRight').val()) || 'Principal';
            var pName  = $.trim($('#bPrincipalName').val());
            var pDesig = $.trim($('#bPrincipalDesig').val());
            var signOn = $('#t_result_show_principal_sign').is(':checked');
            $('#pvSigRLbl').text(sigR);
            $('#pvSigRWho').html(signOn && pName
                ? '<br><small>' + esc(pName) + '</small>' +
                  (pDesig && pDesig.toLowerCase() !== sigR.toLowerCase() ? '<br><small>' + esc(pDesig) + '</small>' : '')
                : '');                                        // dup designation would just repeat the label

            $('#pvFooter').text($.trim($('#bFooter').val()));

            var hex = $('#bAccent').val();
            if (/^#[0-9a-fA-F]{6}$/.test(hex)) document.getElementById('pvCard').style.setProperty('--rc-accent', hex);

            pvShow('#pvPhoto',     $('#t_result_show_photo').is(':checked'));
            pvShow('#pvDob',       $('#t_result_show_dob').is(':checked'));
            pvShow('.pv-grade',    $('#t_result_show_grade_col').is(':checked'));
            pvShow('.pv-rem',      $('#t_result_show_remarks_col').is(':checked'));
            pvShow('#pvGpa',       $('#t_result_show_gpa').is(':checked'));
            pvShow('#pvPos',       $('#t_result_show_position').is(':checked'));
            pvShow('#pvFailed',    $('#t_result_show_failed_line').is(':checked'));
            pvShow('#pvSigns',     $('#t_result_show_signatures').is(':checked'));
            pvShow('#pvFooterRow', $('#t_result_show_footer').is(':checked'));
            pvShow('#pvQr',        $('#t_result_show_qr').is(':checked'));
            pvShow('#pvSignImg',   signOn && HAS_SIGN);
            pvShow('#pvPrincipalRem', $('#t_result_show_principal_remark').is(':checked'));
        }

        $('#brandingForm').on('input change', 'input', ORMS.debounce(renderPreview, 120));

        // colour picker and hex box drive each other
        $('#bAccent').on('input', function () { $('#bAccentHex').val(this.value); renderPreview(); });
        $('#bAccentHex').on('input', function () {
            var v = $.trim(this.value);
            if (/^#[0-9a-fA-F]{6}$/.test(v)) { $('#bAccent').val(v); renderPreview(); }
        });

        renderPreview();

        $('#brandingForm').on('submit', function (e) {
            e.preventDefault();
            // unchecked boxes never serialize - send an explicit 0 so a toggle can actually be turned off
            var data = $(this).serializeArray();
            $('.js-card-toggle').each(function () {
                if (!this.checked) data.push({ name: this.name, value: '0' });
            });
            ORMS.postOrFail('saveBranding', $.param(data), { btn: '#brandingSaveBtn', busyLabel: 'Saving…' })
                .done(function () { ORMS.ok('Branding saved'); });
        });

        $('#rsLogoUpload').on('click', function () {
            var f = document.getElementById('rsLogoFile').files[0];
            if (!f) { ORMS.err('Pick an image first'); return; }
            if (f.size > 2 * 1024 * 1024) { ORMS.err('Logo must be under 2MB'); return; }
            var fd = new FormData();
            fd.append('logo_file', f);
            ORMS.postOrFail('uploadLogo', fd, { btn: '#rsLogoUpload', busyLabel: 'Uploading…' }).done(function (r) {
                $('#rsLogoPreview').attr('src', r.logo + '?t=' + Date.now()).removeClass('initially-hidden');
                $('#pvLogo').attr('src', r.logo + '?t=' + Date.now()).removeAttr('hidden'); // preview follows
                $('#rsLogoRemove').removeClass('initially-hidden');
                $('#rsLogoNone').addClass('initially-hidden');
                document.getElementById('rsLogoFile').value = '';
                ORMS.ok('Logo updated');
            });
        });

        $('#rsLogoRemove').on('click', function () {
            ORMS.confirmDelete('The result card will fall back to the site logo.', 'Remove result logo?').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('removeLogo', {}, { btn: '#rsLogoRemove', busyLabel: 'Removing…' }).done(function () {
                    $('#rsLogoPreview').removeAttr('src').addClass('initially-hidden');
                    $('#pvLogo').removeAttr('src').attr('hidden', 'hidden'); // preview follows
                    $('#rsLogoRemove').addClass('initially-hidden');
                    $('#rsLogoNone').removeClass('initially-hidden');
                    ORMS.ok('Logo removed');
                });
            });
        });

        $('#rsSignUpload').on('click', function () {
            var f = document.getElementById('rsSignFile').files[0];
            if (!f) { ORMS.err('Pick a signature image first'); return; }
            if (f.size > 2 * 1024 * 1024) { ORMS.err('Signature must be under 2MB'); return; }
            var fd = new FormData();
            fd.append('sign_file', f);
            ORMS.postOrFail('uploadPrincipalSign', fd, { btn: '#rsSignUpload', busyLabel: 'Uploading…' }).done(function (r) {
                var src = r.sign + '?t=' + Date.now();
                $('#rsSignPreview').attr('src', src).removeClass('initially-hidden');
                $('#pvSignImg').attr('src', src);              // preview follows
                HAS_SIGN = true;
                $('#rsSignRemove').removeClass('initially-hidden');
                $('#rsSignNone').addClass('initially-hidden');
                document.getElementById('rsSignFile').value = '';
                renderPreview();
                ORMS.ok('Signature updated');
            });
        });

        $('#rsSignRemove').on('click', function () {
            ORMS.confirmDelete('Cards will print the signature line and name only.', 'Remove head teacher signature?').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('removePrincipalSign', {}, { btn: '#rsSignRemove', busyLabel: 'Removing…' }).done(function () {
                    $('#rsSignPreview').removeAttr('src').addClass('initially-hidden');
                    $('#pvSignImg').removeAttr('src');         // preview follows
                    HAS_SIGN = false;
                    $('#rsSignRemove').addClass('initially-hidden');
                    $('#rsSignNone').removeClass('initially-hidden');
                    renderPreview();
                    ORMS.ok('Signature removed');
                });
            });
        });

        // ---------------- templates
        ORMS.dropdown('.js-class-tpl');

        $(document).on('change', '.tplg-card input', function () {
            $('.tplg-card').removeClass('active');
            $(this).closest('.tplg-card').addClass('active');
            // preview follows the picked design
            $('#tplPvBox .tpl-pv').removeClass('active');
            $('#tplPvBox .tpl-pv[data-tpl="' + this.value + '"]').addClass('active');
        });

        $('#templatesForm').on('submit', function (e) {
            e.preventDefault();
            ORMS.postOrFail('saveTemplates', $(this).serialize(), { btn: '#templatesSaveBtn', busyLabel: 'Saving…' })
                .done(function () { ORMS.ok('Templates saved'); });
        });

        // print preview — the on-screen preview is scaled down inside its pane, so the popup
        // re-hosts the same markup in a real 210mm sheet. clone, never move: the pane keeps its card
        $('#btnTplPrintPv').on('click', function () {
            var $pv = $('#tplPvBox .tpl-pv.active');
            if (!$pv.length) { ORMS.err('Pick a template first'); return; }

            var $name = $('.tplg-card.active .tplg-name').first();
            // the label carries a "Term-wise" chip in some cards — drop it, keep the name
            var label = $name.length ? $.trim($name.clone().find('.tplg-chip').remove().end().text()) : '';
            $('#tplPrintName').text(label ? '— ' + label : '');

            $('#tplPrintSheet').html($pv.html());
            ORMS.qr();                                   // fills any code the clone brought over undrawn
            document.getElementById('tplPrintModal').classList.add('active');
        });

        // prints the sheet alone — printOnly hides every sibling up the tree, header included
        $('#btnTplDoPrint').on('click', function () { ORMS.printOnly('#tplPrintSheet'); });

        // ---------------- academic years
        // in-row toggle — unique id so the label drives its own input
        function rowToggle(eid, on, cls, id, off) {
            return '<div class="toggle-switch">' +
                   '<input type="checkbox" id="' + eid + '" class="toggle-input ' + cls + '" data-id="' + id + '" value="1"' +
                   (on ? ' checked' : '') + (off ? ' disabled' : '') + '>' +
                   '<label for="' + eid + '" class="toggle-label"><span class="toggle-slider"></span></label></div>';
        }

        function renderYears() {
            var rows = state.years.map(function (y) {
                var used = [], cur = +y.is_current === 1, locked = +y.is_locked === 1;
                if (+y.terms)    used.push('<span class="status-badge status-user">' + (+y.terms) + ' terms</span>');
                if (+y.students) used.push('<span class="status-badge status-user">' + (+y.students) + ' students</span>');
                if (+y.marks)    used.push('<span class="status-badge status-user">' + (+y.marks) + ' marks</span>');

                var act = '';
                if (CAN_EDIT) {
                    if (!cur && !locked) {                          // a locked year is closed, never the current one
                        act += '<button type="button" class="action-icon edit-icon js-year-current" data-id="' + (+y.id) + '" title="Make current"><i class="fas fa-star"></i></button>';
                    }
                    act += '<button type="button" class="action-icon edit-icon js-year-edit" data-id="' + (+y.id) + '" title="Edit"><i class="fas fa-edit"></i></button>';
                    act += '<button type="button" class="action-icon delete-icon js-year-del" data-id="' + (+y.id) + '" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                // current year cannot be locked — the toggle is off the table for it
                var lockCell = CAN_EDIT
                    ? rowToggle('yLock_' + (+y.id), locked, 'js-year-lock', +y.id, cur)
                    : (locked ? '<span class="status-badge status-inactive"><i class="fas fa-lock"></i> Locked</span>'
                              : '<span class="text-muted">&mdash;</span>');

                return '<tr>' +
                    '<td><strong>' + esc(y.name) + '</strong>' +
                        (locked ? ' <span class="status-badge status-inactive"><i class="fas fa-lock"></i> Closed</span>' : '') + '</td>' +
                    '<td>' + dash(y.start_date) + '</td>' +
                    '<td>' + dash(y.end_date) + '</td>' +
                    '<td>' + (cur
                        ? '<span class="status-badge status-active"><i class="fas fa-star"></i> Current</span>'
                        : '<span class="text-muted">&mdash;</span>') + '</td>' +
                    '<td>' + lockCell + '</td>' +
                    '<td>' + (used.join(' ') || '<span class="text-muted">Unused</span>') + '</td>' +
                    '<td>' + (act || '<span class="text-muted">&mdash;</span>') + '</td>' +
                    '</tr>';
            });
            $('#yearsBody').html(rows.length ? rows.join('') :
                empty(7, 'fa-calendar-xmark', 'No academic years yet', 'Add a year — every student, term and mark hangs off it.'));
        }

        // rebuild the terms-tab year picker without losing the selection
        function syncYearSelect() {
            var $sel = $('#termYear'), keep = $sel.val();
            var opts = state.years.map(function (y) {
                return '<option value="' + (+y.id) + '">' + esc(y.name) + (+y.is_current === 1 ? ' (Current)' : '') + '</option>';
            }).join('');
            $sel.html(opts);
            var still = state.years.some(function (y) { return String(y.id) === String(keep); });
            if (!still) {
                var cur = state.years.filter(function (y) { return +y.is_current === 1; })[0];
                keep = cur ? String(cur.id) : (state.years[0] ? String(state.years[0].id) : '');
            }
            $sel.val(keep);
            ORMS.dropdown.refresh('#termYear');
            return keep;
        }

        function loadYears(btn) {
            return ORMS.postOrFail('getYears', {}, btn ? { btn: btn, busyLabel: 'Loading…' } : {}).done(function (r) {
                state.years = r.data || [];
                renderYears();
                syncYearSelect();
            }).fail(function () { $('#yearsBody').html(loadFail(7)); });   // never leave the skeleton spinning
        }

        $('#yearsRefresh').on('click', function () { loadYears('#yearsRefresh'); });

        $('#yearAddBtn').on('click', function () {
            document.getElementById('yearForm').reset();
            $('#yearId').val('0');
            $('#yearModalTitle').html('<i class="fas fa-calendar-plus"></i> Add Academic Year');
            $('#yearCurrent').prop('checked', state.years.length === 0).each(function () { syncToggle(this); });
            openModal('yearModal');
        });

        $('#yearsBody').on('click', '.js-year-edit', function () {
            var id = +$(this).data('id');
            var y = state.years.filter(function (v) { return +v.id === id; })[0];
            if (!y) return;
            $('#yearId').val(y.id);
            $('#yearName').val(y.name);
            $('#yearStart').val(y.start_date || '');
            $('#yearEnd').val(y.end_date || '');
            $('#yearCurrent').prop('checked', +y.is_current === 1).each(function () { syncToggle(this); });
            $('#yearModalTitle').html('<i class="fas fa-edit"></i> Edit Academic Year');
            openModal('yearModal');
        });

        $('#yearsBody').on('click', '.js-year-current', function () {
            var $b = $(this);
            ORMS.postOrFail('setCurrentYear', { id: +$b.data('id') }, { btn: $b, busyLabel: ' ' })
                .done(function () { ORMS.ok('Current year updated'); loadYears(); });
        });

        // own wording — confirmDelete's button reads "Delete", which is wrong for a year-end close
        function confirmLock(lock, nm) {
            var text = lock
                ? 'Lock "' + nm + '"? This is the year-end close — do it once the year\'s results are final and published.'
                : 'Unlock "' + nm + '"? The year reopens and can be made current again.';
            if (typeof Swal === 'undefined') return $.Deferred().resolve(window.confirm(text)).promise();
            return Swal.fire({
                icon: 'warning',
                title: lock ? 'Lock academic year?' : 'Unlock academic year?',
                text: text,
                showCancelButton: true,
                confirmButtonColor: '#001f3f',
                confirmButtonText: '<i class="fas fa-' + (lock ? 'lock' : 'lock-open') + '"></i> ' + (lock ? 'Lock year' : 'Unlock year'),
                cancelButtonText: '<i class="fas fa-times"></i> Cancel'
            }).then(function (r) { return !!(r && r.isConfirmed); });
        }

        // year-end close — confirm first, then write; a rejected write snaps the toggle back
        $('#yearsBody').on('change', '.js-year-lock', function () {
            var $t = $(this), id = +$t.data('id'), lock = $t.is(':checked') ? 1 : 0;
            var y = state.years.filter(function (v) { return +v.id === id; })[0], nm = y ? y.name : 'this year';
            confirmLock(lock, nm).then(function (yes) {
                if (!yes) { $t.prop('checked', !lock); return; }
                $t.prop('disabled', true);
                ORMS.postOrFail('setYearLock', { id: id, is_locked: lock })
                    .done(function () { ORMS.ok(lock ? 'Year locked' : 'Year unlocked'); })
                    .always(function () { loadYears(); });          // repaint from what the db actually holds
            });
        });

        $('#yearsBody').on('click', '.js-year-del', function () {
            var $b = $(this), id = +$b.data('id');
            var y = state.years.filter(function (v) { return +v.id === id; })[0];
            ORMS.confirmDelete('Delete academic year "' + (y ? y.name : '') + '"? This cannot be undone.').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('deleteYear', { id: id }, { btn: $b, busyLabel: ' ' })
                    .done(function () { ORMS.ok('Year deleted'); loadYears(); });
            });
        });

        $('#yearForm').on('submit', function (e) {
            e.preventDefault();
            ORMS.postOrFail('saveYear', $(this).serialize(), { btn: '#yearSaveBtn', busyLabel: 'Saving…' }).done(function () {
                closeModal('yearModal');
                ORMS.ok('Year saved');
                loadYears().done(loadTerms);
            });
        });

        // ---------------- exam terms
        var STATUSES = ['Upcoming', 'Open', 'Closed'];

        function statusBadge(s) {
            var cls = s === 'Open' ? 'status-active' : (s === 'Closed' ? 'status-inactive' : 'status-user');
            var ico = s === 'Open' ? 'fa-lock-open' : (s === 'Closed' ? 'fa-lock' : 'fa-hourglass-half');
            return '<span class="status-badge ' + cls + '"><i class="fas ' + ico + '"></i> ' + esc(s) + '</span>';
        }

        // exam window reads as one cell: start -> end, either side may be missing
        function windowCell(sd, ed) {
            if (!sd && !ed) return '<span class="text-muted">&mdash;</span>';
            if (sd && ed) return esc(sd) + ' <span class="text-muted">&rarr;</span> ' + esc(ed);
            return sd ? 'from ' + esc(sd) : 'until ' + esc(ed);
        }

        function weightTotal(skipId, override) {
            var sum = state.terms.reduce(function (a, t) {
                return a + (+t.id === skipId ? 0 : (parseFloat(t.weightage) || 0));
            }, 0) + (override || 0);
            return Math.round(sum * 100) / 100;
        }

        // 0 = weighting unused (the default, always valid), 100 = fully allocated, anything else is a mistake
        function weightState(tot) {
            if (tot === 0) return { ok: true, msg: 'Every term is at 0% — term weighting is not in use, which is the default.' };
            if (Math.abs(tot - 100) < 0.01) return { ok: true, msg: 'The terms add up to 100% — a combined annual result can be weighted correctly.' };
            return { ok: false, msg: 'Weighting only works at 0% (unused) or exactly 100%. ' +
                     (tot < 100 ? (num(Math.round((100 - tot) * 100) / 100) + '% is unallocated.') : (num(Math.round((tot - 100) * 100) / 100) + '% over.')) };
        }

        function renderWeightNote() {
            var $n = $('#termWeightNote');
            if (!state.terms.length) { $n.addClass('initially-hidden').removeClass('info-banner-warning').html(''); return; }
            var tot = weightTotal(), st = weightState(tot);
            $n.removeClass('initially-hidden').toggleClass('info-banner-warning', !st.ok)
              .html('<i class="fas ' + (st.ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i><div>' +
                    '<strong>Weightage total for this year: ' + num(tot) + '%</strong><div>' + esc(st.msg) + '</div></div>');
        }

        function renderTerms() {
            var rows = state.terms.map(function (t) {
                var ctrl;
                if (CAN_EDIT) {
                    ctrl = '<select class="filter-input js-term-status" data-id="' + (+t.id) + '" data-label="Status">' +
                        STATUSES.map(function (s) {
                            return '<option value="' + s + '"' + (s === t.status ? ' selected' : '') + '>' + s + '</option>';
                        }).join('') + '</select>';
                } else {
                    ctrl = statusBadge(t.status);
                }
                var pub = +t.published ? ' <span class="status-badge status-active"><i class="fas fa-bullhorn"></i> Published</span>' : '';
                var w   = parseFloat(t.weightage) || 0;

                var act = '';
                if (CAN_EDIT) {
                    act += '<button type="button" class="action-icon edit-icon js-term-edit" data-id="' + (+t.id) + '" title="Edit"><i class="fas fa-edit"></i></button>';
                    act += '<button type="button" class="action-icon delete-icon js-term-del" data-id="' + (+t.id) + '" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                return '<tr>' +
                    '<td>' + (+t.sort_order) + '</td>' +
                    '<td><strong>' + esc(t.name) + '</strong> ' + statusBadge(t.status) + pub + '</td>' +
                    '<td>' + ctrl + '</td>' +
                    '<td>' + windowCell(t.start_date, t.end_date) + '</td>' +
                    '<td>' + dash(t.result_date) + '</td>' +
                    '<td><span class="status-badge ' + (w > 0 ? 'status-user' : 'status-inactive') + '">' +
                        '<i class="fas fa-percent"></i> ' + num(w) + '</span></td>' +
                    '<td><span class="status-badge ' + (+t.marks ? 'status-user' : 'status-inactive') + '">' +
                        '<i class="fas fa-pen-to-square"></i> ' + (+t.marks) + '</span></td>' +
                    '<td>' + (act || '<span class="text-muted">&mdash;</span>') + '</td>' +
                    '</tr>';
            });
            $('#termsBody').html(rows.length ? rows.join('') :
                empty(8, 'fa-calendar-xmark', 'No terms in this year', 'Add a term, then set it Open so teachers can enter marks.'));
            renderWeightNote();
            if (CAN_EDIT) ORMS.dropdown('.js-term-status');
        }

        function loadTerms(btn) {
            var yid = $('#termYear').val();
            if (!yid) {
                state.terms = [];
                $('#termsBody').html(empty(8, 'fa-calendar-xmark', 'No academic year selected', 'Create an academic year first, then add its terms.'));
                renderWeightNote();
                return $.Deferred().resolve().promise();
            }
            return ORMS.postOrFail('getTerms', { year_id: yid }, btn ? { btn: btn, busyLabel: 'Loading…' } : {}).done(function (r) {
                state.terms = r.data || [];
                renderTerms();
            }).fail(function () { $('#termsBody').html(loadFail(8)); });
        }

        $('#termsRefresh').on('click', function () { loadTerms('#termsRefresh'); });
        $('#termYear').on('change', function () { loadTerms(); });

        // what the year's total becomes with the value being typed
        function termFormWeight() {
            var $n = $('#termFormWeight'), id = +$('#termId').val();
            var tot = weightTotal(id, parseFloat($('#termWeight').val()) || 0), st = weightState(tot);
            $n.removeClass('initially-hidden').toggleClass('info-banner-warning', !st.ok)
              .html('<i class="fas ' + (st.ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i>' +
                    '<div><strong>Year total with this term: ' + num(tot) + '%</strong><div>' + esc(st.msg) + '</div></div>');
        }
        $('#termWeight').on('input', ORMS.debounce(termFormWeight, 120));

        $('#termAddBtn').on('click', function () {
            if (!$('#termYear').val()) { ORMS.err('Create an academic year first'); return; }
            document.getElementById('termForm').reset();
            $('#termId').val('0');
            $('#termSort').val(state.terms.length + 1);
            $('#termWeight').val('0');
            $('#termStatus').val('Upcoming');
            ORMS.dropdown.refresh('#termStatus');
            $('#termModalTitle').html('<i class="fas fa-calendar-plus"></i> Add Term');
            termFormWeight();
            openModal('termModal');
        });

        $('#termsBody').on('click', '.js-term-edit', function () {
            var id = +$(this).data('id');
            var t = state.terms.filter(function (v) { return +v.id === id; })[0];
            if (!t) return;
            $('#termId').val(t.id);
            $('#termName').val(t.name);
            $('#termSort').val(t.sort_order);
            $('#termStart').val(t.start_date || '');
            $('#termEnd').val(t.end_date || '');
            $('#termResult').val(t.result_date || '');
            $('#termWeight').val(num(t.weightage));
            $('#termStatus').val(t.status);
            ORMS.dropdown.refresh('#termStatus');
            $('#termModalTitle').html('<i class="fas fa-edit"></i> Edit Term');
            termFormWeight();
            openModal('termModal');
        });

        // inline status change — the switch that unlocks marks entry
        $('#termsBody').on('change', '.js-term-status', function () {
            var $s = $(this);
            ORMS.postOrFail('setTermStatus', { id: +$s.data('id'), status: $s.val() })
                .done(function () { ORMS.ok('Status updated'); loadTerms(); })
                .fail(function () { loadTerms(); });          // snap back to what the db actually holds
        });

        $('#termsBody').on('click', '.js-term-del', function () {
            var $b = $(this), id = +$b.data('id');
            var t = state.terms.filter(function (v) { return +v.id === id; })[0];
            ORMS.confirmDelete('Delete term "' + (t ? t.name : '') + '"? This cannot be undone.').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('deleteTerm', { id: id }, { btn: $b, busyLabel: ' ' })
                    .done(function () { ORMS.ok('Term deleted'); loadTerms(); });
            });
        });

        $('#termForm').on('submit', function (e) {
            e.preventDefault();
            var sd = $('#termStart').val(), ed = $('#termEnd').val(), w = parseFloat($('#termWeight').val());
            if (sd && ed && sd > ed) { ORMS.err('Exam end date cannot be before the start date'); return; }
            if ($('#termWeight').val() !== '' && (isNaN(w) || w < 0 || w > 100)) { ORMS.err('Weightage must be between 0 and 100 percent'); return; }
            var data = $(this).serialize() + '&year_id=' + encodeURIComponent($('#termYear').val());
            ORMS.postOrFail('saveTerm', data, { btn: '#termSaveBtn', busyLabel: 'Saving…' }).done(function () {
                closeModal('termModal');
                ORMS.ok('Term saved');
                loadTerms();
                loadYears();                                   // term counts on the years tab
            });
        });

        // ---------------- grading sets + bands
        var HEX = /^#[0-9a-fA-F]{6}$/;

        // readable label over the stored band colour — computed, so it can be an inline style
        function onColor(hex) {
            var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
            return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#001f3f' : '#ffffff';
        }

        // grade chip carrying its own band colour
        function gradeChip(g) {
            var c = g.color && HEX.test(g.color) ? g.color : '';
            return c
                ? '<span class="status-badge" style="background:' + esc(c) + ';color:' + onColor(c) + '">' + esc(g.grade) + '</span>'
                : '<span class="status-badge status-user">' + esc(g.grade) + '</span>';
        }

        function curSet() {
            return state.sets.filter(function (v) { return +v.id === +state.setId; })[0] || null;
        }

        // set chips — the whole tab reads from whichever one is active
        function renderSets() {
            if (!state.sets.length) {
                $('#gsBar').html('<span class="text-muted"><i class="fas fa-layer-group"></i> No grading sets yet</span>');
                $('#gsCurName').text('');
                $('#gsNote').addClass('initially-hidden').html('');
                return;
            }
            $('#gsBar').html(state.sets.map(function (v) {
                var on = +v.id === +state.setId, def = +v.is_default === 1;
                return '<button type="button" class="gs-chip' + (on ? ' gs-chip-active' : '') + '" data-id="' + (+v.id) + '">' +
                       '<i class="fas fa-' + (def ? 'star' : 'layer-group') + '"></i> ' + esc(v.name) +
                       ' <span class="status-badge status-user">' + (+v.bands) + '</span>' +
                       (def ? ' <span class="status-badge status-active">Default</span>' : '') + '</button>';
            }).join(''));

            var c = curSet();
            $('#gsCurName').text(c ? '— ' + c.name : '');
            if (!c) { $('#gsNote').addClass('initially-hidden').html(''); return; }

            var bits = [];
            if (+c.is_default === 1) bits.push('This is the <strong>default set</strong> — every class without its own pick is graded by it.');
            if (c.description) bits.push(esc(c.description));
            bits.push('Used by <strong>' + (+c.classes) + '</strong> class(es) and <strong>' + (+c.cards) + '</strong> published result(s).');
            $('#gsNote').html('<i class="fas fa-layer-group"></i><div><strong>' + esc(c.name) + '</strong>' +
                bits.map(function (m) { return '<div>' + m + '</div>'; }).join('') + '</div>').removeClass('initially-hidden');

            $('#gsDefaultBtn').prop('disabled', +c.is_default === 1);
        }

        function renderGrades(issues) {
            var rows = state.grades.map(function (g) {
                var act = '';
                if (CAN_EDIT) {
                    act += '<button type="button" class="action-icon edit-icon js-grade-edit" data-id="' + (+g.id) + '" title="Edit"><i class="fas fa-edit"></i></button>';
                    act += '<button type="button" class="action-icon delete-icon js-grade-del" data-id="' + (+g.id) + '" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                var col = g.color && HEX.test(g.color) ? g.color : '';
                return '<tr>' +
                    '<td>' + (+g.sort_order) + '</td>' +
                    '<td>' + gradeChip(g) + '</td>' +
                    '<td>' + num(g.min_percent) + '% &ndash; ' + num(g.max_percent) + '%</td>' +
                    '<td><strong>' + num(g.grade_point) + '</strong></td>' +
                    '<td>' + (+g.is_fail === 1
                        ? '<span class="status-badge status-inactive"><i class="fas fa-circle-xmark"></i> Fail</span>'
                        : '<span class="status-badge status-active"><i class="fas fa-circle-check"></i> Pass</span>') + '</td>' +
                    '<td>' + (col
                        ? '<span class="status-badge" style="background:' + esc(col) + ';color:' + onColor(col) + '">' + esc(col) + '</span>'
                        : '<span class="text-muted">Theme</span>') + '</td>' +
                    '<td>' + dash(g.remarks) + '</td>' +
                    '<td>' + dash(g.interpretation) + '</td>' +
                    '<td>' + (act || '<span class="text-muted">&mdash;</span>') + '</td>' +
                    '</tr>';
            });
            $('#gradesBody').html(rows.length ? rows.join('') :
                empty(9, 'fa-award', 'No grade bands in this set', 'Add bands covering 0-100%, or clone another set to start from its bands.'));

            var $iss = $('#gradeIssues');
            if (issues && issues.length) {
                // banner is flex — keep heading + list as one child so they stack
                $iss.html('<i class="fas fa-triangle-exclamation"></i><div><strong>Coverage check (' + issues.length + ')</strong>' +
                    issues.map(function (m) { return '<div>&bull; ' + esc(m) + '</div>'; }).join('') + '</div>').removeClass('initially-hidden');
            } else {
                $iss.addClass('initially-hidden').html('');
            }
            preview();
            paintPreviewGrades();
        }

        // sample card on the branding tab picks up the real band colours
        function paintPreviewGrades() {
            var byGrade = {};
            state.grades.forEach(function (g) {
                if (g.color && HEX.test(g.color)) byGrade[String(g.grade).toUpperCase()] = g.color;
            });
            $('#pvCard td.pv-grade').each(function () {
                this.style.color = byGrade[$.trim($(this).text()).toUpperCase()] || '';   // stored colour, runtime value
            });
        }

        // same rule the result engine uses, inside the selected set: highest band whose min <= pct
        function preview() {
            var pct = parseFloat($('#pvPct').val());
            if (isNaN(pct)) { $('#pvOut').html('<div><i class="fas fa-circle-question"></i> Enter a percentage to preview</div>'); return; }
            pct = Math.round(pct * 100) / 100;
            var c = curSet(), setLine = c ? '<div><i class="fas fa-layer-group"></i> Set <b>' + esc(c.name) + '</b></div>' : '';
            var hit = null;
            state.grades.forEach(function (g) {
                if (parseFloat(g.min_percent) <= pct && (!hit || parseFloat(g.min_percent) > parseFloat(hit.min_percent))) hit = g;
            });
            if (!hit) {
                $('#pvOut').html(setLine + '<div><i class="fas fa-ban"></i> ' + num(pct) + '% resolves to <b>no grade</b> — no band starts at or below it</div>');
                return;
            }
            var outOfBand = pct > parseFloat(hit.max_percent);
            $('#pvOut').html(setLine +
                '<div><i class="fas fa-percent"></i> ' + num(pct) + '%</div>' +
                '<div><i class="fas fa-award"></i> Grade ' + gradeChip(hit) + '</div>' +
                '<div><i class="fas fa-star-half-stroke"></i> Point <b>' + num(hit.grade_point) + '</b></div>' +
                '<div><i class="fas fa-' + (+hit.is_fail === 1 ? 'circle-xmark' : 'circle-check') + '"></i> <b>' +
                    (+hit.is_fail === 1 ? 'Counts as a fail' : 'Counts as a pass') + '</b></div>' +
                '<div><i class="fas fa-comment-dots"></i> ' + (hit.remarks ? esc(hit.remarks) : '&mdash;') + '</div>' +
                '<div><i class="fas fa-comments"></i> ' + (hit.interpretation ? esc(hit.interpretation) : '<span class="text-muted">no interpretation — the grade key prints blank</span>') + '</div>' +
                (outOfBand ? '<div><i class="fas fa-triangle-exclamation"></i> Falls in a gap — band ends at ' + num(hit.max_percent) + '%</div>' : '')
            );
        }
        $('#pvPct').on('input', ORMS.debounce(preview, 120));

        function loadGrading(btn, setId) {
            var want = setId === undefined ? state.setId : setId;
            return ORMS.postOrFail('getGrading', { set_id: +want || 0 }, btn ? { btn: btn, busyLabel: 'Loading…' } : {})
                .done(function (r) {
                    state.sets   = r.sets || [];
                    state.setId  = +r.set_id || 0;
                    state.grades = r.data || [];
                    renderSets();
                    renderGrades(r.issues || []);
                    syncAssignOptions();
                }).fail(function () { $('#gradesBody').html(loadFail(9)); });
        }

        $('#gradingRefresh').on('click', function () { loadGrading('#gradingRefresh'); });

        $('#gsBar').on('click', '.gs-chip', function () {
            var id = +$(this).data('id');
            if (id === +state.setId) return;
            loadGrading(null, id);
        });

        // one modal, three jobs — mode decides the action it posts
        function gsOpen(mode) {
            var c = curSet();
            if (mode !== 'add' && !c) { ORMS.err('Pick a grading set first'); return; }
            $('#gsMode').val(mode);
            $('#gsId').val(mode === 'rename' ? c.id : 0);
            $('#gsName').val(mode === 'rename' ? c.name : (mode === 'clone' ? c.name + ' (Copy)' : ''));
            $('#gsDesc').val(mode === 'rename' ? (c.description || '') : '');
            $('#gsCloneNote').toggleClass('initially-hidden', mode !== 'clone')
                .html(mode === 'clone'
                    ? '<i class="fas fa-clone"></i><span>Every one of the <strong>' + (+c.bands) + '</strong> band(s) in <strong>' + esc(c.name) +
                      '</strong> is copied into the new set. Edit them there — the original is never touched.</span>' : '');
            $('#gsModalTitle').html(mode === 'rename' ? '<i class="fas fa-pen"></i> Rename Grading Set'
                                  : (mode === 'clone' ? '<i class="fas fa-clone"></i> Clone Grading Set' : '<i class="fas fa-layer-group"></i> New Grading Set'));
            $('#gsSaveBtn').html('<i class="fas fa-save"></i> ' + (mode === 'clone' ? 'Clone Set' : 'Save Set'));
            openModal('gsModal');
        }

        $('#gsAddBtn').on('click', function () { gsOpen('add'); });
        $('#gsRenameBtn').on('click', function () { gsOpen('rename'); });
        $('#gsCloneBtn').on('click', function () { gsOpen('clone'); });

        $('#gsForm').on('submit', function (e) {
            e.preventDefault();
            var mode = $('#gsMode').val(), c = curSet();
            var data = { name: $.trim($('#gsName').val()), description: $.trim($('#gsDesc').val()) };
            if (!data.name) { ORMS.err('Set name is required'); return; }
            if (mode === 'clone') data.source_id = c ? c.id : 0; else data.id = mode === 'rename' ? $('#gsId').val() : 0;

            ORMS.postOrFail(mode === 'clone' ? 'cloneGradingSet' : 'saveGradingSet', data, { btn: '#gsSaveBtn', busyLabel: mode === 'clone' ? 'Cloning…' : 'Saving…' })
                .done(function (r) {
                    closeModal('gsModal');
                    ORMS.ok(mode === 'clone' ? 'Set cloned' : 'Set saved');
                    loadGrading(null, +r.set_id || state.setId);      // land on what we just created
                });
        });

        $('#gsDefaultBtn').on('click', function () {
            var c = curSet();
            if (!c) { ORMS.err('Pick a grading set first'); return; }
            ORMS.postOrFail('setDefaultGradingSet', { id: c.id }, { btn: '#gsDefaultBtn', busyLabel: 'Saving…' })
                .done(function () { ORMS.ok('Default set updated'); loadGrading(); });
        });

        $('#gsDelBtn').on('click', function () {
            var c = curSet();
            if (!c) { ORMS.err('Pick a grading set first'); return; }
            ORMS.confirmDelete('Delete grading set "' + c.name + '" and all ' + (+c.bands) + ' of its bands? This cannot be undone.',
                               'Delete this grading set?').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('deleteGradingSet', { id: c.id }, { btn: '#gsDelBtn', busyLabel: 'Deleting…' })
                    .done(function () { ORMS.ok('Set deleted'); loadGrading(null, 0); });   // 0 = fall back to the default
            });
        });

        // hex box is the source of truth — blank means "no tint, use the theme"
        $('#gradeColor').on('input', function () { $('#gradeColorHex').val(this.value); });
        $('#gradeColorHex').on('input', function () {
            var v = $.trim(this.value);
            if (HEX.test(v)) $('#gradeColor').val(v);
        });

        $('#gradeAddBtn').on('click', function () {
            if (!state.setId) { ORMS.err('Create a grading set first'); return; }
            document.getElementById('gradeForm').reset();
            $('#gradeId').val('0');
            $('#gradeSetId').val(state.setId);
            $('#gradeSort').val(state.grades.length + 1);
            $('#gradeColorHex').val('');
            $('#gradeColor').val('#001f3f');
            $('#gradeFail').prop('checked', false);
            var c = curSet();
            $('#gradeModalTitle').html('<i class="fas fa-plus"></i> Add Grade Band' + (c ? ' <span class="myr-sub">— ' + esc(c.name) + '</span>' : ''));
            openModal('gradeModal');
        });

        $('#gradesBody').on('click', '.js-grade-edit', function () {
            var id = +$(this).data('id');
            var g = state.grades.filter(function (v) { return +v.id === id; })[0];
            if (!g) return;
            $('#gradeId').val(g.id);
            $('#gradeSetId').val(g.set_id || state.setId);
            $('#gradeLabel').val(g.grade);
            $('#gradeMin').val(num(g.min_percent));
            $('#gradeMax').val(num(g.max_percent));
            $('#gradePoint').val(num(g.grade_point));
            $('#gradeRemarks').val(g.remarks || '');
            $('#gradeInterp').val(g.interpretation || '');
            $('#gradeSort').val(g.sort_order);
            $('#gradeFail').prop('checked', +g.is_fail === 1);
            var col = g.color && HEX.test(g.color) ? g.color : '';
            $('#gradeColorHex').val(col);
            $('#gradeColor').val(col || '#001f3f');
            var c = curSet();
            $('#gradeModalTitle').html('<i class="fas fa-edit"></i> Edit Grade Band' + (c ? ' <span class="myr-sub">— ' + esc(c.name) + '</span>' : ''));
            openModal('gradeModal');
        });

        $('#gradesBody').on('click', '.js-grade-del', function () {
            var $b = $(this), id = +$b.data('id');
            var g = state.grades.filter(function (v) { return +v.id === id; })[0];
            ORMS.confirmDelete('Delete grade band "' + (g ? g.grade : '') + '" from this set? Existing results keep their stored grade.').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('deleteGrade', { id: id }, { btn: $b, busyLabel: ' ' })
                    .done(function () { ORMS.ok('Band deleted'); loadGrading(); });
            });
        });

        $('#gradeForm').on('submit', function (e) {
            e.preventDefault();
            var mn = parseFloat($('#gradeMin').val()), mx = parseFloat($('#gradeMax').val());
            if (isNaN(mn) || isNaN(mx) || mn < 0 || mx > 100) { ORMS.err('Min and max must be between 0 and 100'); return; }
            if (mn > mx) { ORMS.err('Min percent cannot be greater than max percent'); return; }
            var col = $.trim($('#gradeColorHex').val());
            if (col !== '' && !HEX.test(col)) { ORMS.err('Grade colour must be a 6-digit hex like #1e8e3e, or blank for the default'); return; }
            // unchecked boxes never serialize — send the flag explicitly
            var data = $(this).serializeArray();
            if (!$('#gradeFail').is(':checked')) data.push({ name: 'is_fail', value: '0' });
            ORMS.postOrFail('saveGrade', $.param(data), { btn: '#gradeSaveBtn', busyLabel: 'Saving…' }).done(function () {
                closeModal('gradeModal');
                ORMS.ok('Grade band saved');
                loadGrading();
            });
        });

        // ---------------- assessment schemes + components
        function curScheme() {
            return state.schemes.filter(function (v) { return +v.id === +state.schemeId; })[0] || null;
        }

        function renderSchemes() {
            if (!state.schemes.length) {
                $('#asBar').html('<span class="text-muted"><i class="fas fa-percent"></i> No assessment schemes yet</span>');
                $('#asCurName').text('');
                $('#asNote').addClass('initially-hidden').html('');
                return;
            }
            $('#asBar').html(state.schemes.map(function (v) {
                var on = +v.id === +state.schemeId, off = +v.is_active !== 1;
                return '<button type="button" class="gs-chip' + (on ? ' gs-chip-active' : '') + '" data-id="' + (+v.id) + '">' +
                       '<i class="fas fa-' + (off ? 'ban' : 'percent') + '"></i> ' + esc(v.name) +
                       ' <span class="status-badge status-user">' + (+v.comps) + '</span>' +
                       (off ? ' <span class="status-badge status-inactive">Inactive</span>' : '') + '</button>';
            }).join(''));

            var c = curScheme();
            $('#asCurName').text(c ? '— ' + c.name : '');
            $('#asActiveBtn').html(c && +c.is_active !== 1
                ? '<i class="fas fa-toggle-off"></i> Activate' : '<i class="fas fa-toggle-on"></i> Deactivate');
            if (!c) { $('#asNote').addClass('initially-hidden').html(''); return; }

            var bits = [];
            if (c.description) bits.push(esc(c.description));
            bits.push('Used by <strong>' + (+c.classes) + '</strong> class(es); <strong>' + (+c.marks) + '</strong> marks row(s) carry it as their snapshot.');
            $('#asNote').html('<i class="fas fa-percent"></i><div><strong>' + esc(c.name) + '</strong>' +
                bits.map(function (m) { return '<div>' + m + '</div>'; }).join('') + '</div>').removeClass('initially-hidden');
        }

        function weightSum() {
            return Math.round(state.comps.reduce(function (a, c) { return a + (parseFloat(c.weight_percent) || 0); }, 0) * 100) / 100;
        }

        // plain english, same sentence the server sends back on save
        function weightWarn(sum) {
            if (Math.abs(sum - 100) < 0.01) return '';
            return sum < 100
                ? 'Weights add up to ' + num(sum) + '% — a student scoring full marks in every component would only reach ' + num(sum) + ' out of 100.'
                : 'Weights add up to ' + num(sum) + '% — a student scoring full marks in every component would reach ' + num(sum) + ', more than the 100 the result card expects.';
        }

        function renderComps() {
            var sum = weightSum(), bad = Math.abs(sum - 100) >= 0.01;
            var rows = state.comps.map(function (c) {
                var act = '';
                if (CAN_EDIT) {
                    act += '<button type="button" class="action-icon edit-icon js-comp-edit" data-id="' + (+c.id) + '" title="Edit"><i class="fas fa-edit"></i></button>';
                    act += '<button type="button" class="action-icon delete-icon js-comp-del" data-id="' + (+c.id) + '" title="Delete"><i class="fas fa-trash"></i></button>';
                }
                var w = parseFloat(c.weight_percent) || 0;
                return '<tr class="ac-row">' +
                    '<td>' + (+c.sort_order) + '</td>' +
                    '<td><strong>' + esc(c.name) + '</strong></td>' +
                    '<td>' + num(c.max_marks) + '</td>' +
                    '<td class="ac-weight' + (w <= 0 ? ' ac-weight-bad' : '') + '">' + num(w) + '%</td>' +
                    '<td>' + (+c.is_exam === 1
                        ? '<span class="status-badge status-user"><i class="fas fa-flag-checkered"></i> Final Exam</span>'
                        : '<span class="status-badge status-active"><i class="fas fa-list-check"></i> Continuous Assessment</span>') + '</td>' +
                    '<td>' + (act || '<span class="text-muted">&mdash;</span>') + '</td>' +
                    '</tr>';
            });
            $('#acBody').html(rows.length ? rows.join('') :
                empty(6, 'fa-list-check', 'No components in this scheme',
                      'Add the pieces the school marks — class exercises, projects, mini exams, then the end-of-term exam.'));

            var warn = weightWarn(sum);
            $('#acSum').toggleClass('ac-sum-bad', bad && state.comps.length > 0).html(
                !state.comps.length
                    ? '<i class="fas fa-circle-info"></i> No components yet — a class on this scheme would have nothing to mark.'
                    : '<i class="fas fa-' + (bad ? 'triangle-exclamation' : 'circle-check') + '"></i> &Sigma; weights = <strong>' + num(sum) + '%</strong>' +
                      (bad ? ' — ' + esc(warn) : ' — a full-marks student lands on exactly 100.'));
            renderExample();
        }

        // worked example built from the real rows — CA at 80/90/75% of max, the exam at 78%
        function renderExample() {
            if (!state.comps.length) {
                $('#acExample').html('<i class="fas fa-calculator"></i><span>Add components to see a worked example.</span>');
                return;
            }
            var ratios = [0.8, 0.9, 0.75], ca = 0, total = 0, sum = weightSum();
            var parts = state.comps.map(function (c) {
                var mx = parseFloat(c.max_marks) || 0, w = parseFloat(c.weight_percent) || 0;
                var r = +c.is_exam === 1 ? 0.78 : ratios[(ca++) % ratios.length];
                var got = Math.round(mx * r), got2 = Math.round(got * 100) / 100;
                var give = mx > 0 ? Math.round(got2 / mx * w * 100) / 100 : 0;
                total += give;
                return esc(c.name) + ' <strong>' + num(got2) + '/' + num(mx) + '</strong> &rarr; ' + give.toFixed(1);
            });
            total = Math.round(total * 100) / 100;
            $('#acExample').html('<i class="fas fa-calculator"></i><div><strong>Worked example</strong><div>' +
                parts.join(' &nbsp;&middot;&nbsp; ') + '</div><div>&rArr; <strong>' + total.toFixed(1) + ' / ' + num(sum) +
                '</strong> for the subject.</div></div>');
        }

        function loadAssessment(btn, schemeId) {
            var want = schemeId === undefined ? state.schemeId : schemeId;
            return ORMS.postOrFail('getAssessment', { scheme_id: +want || 0 }, btn ? { btn: btn, busyLabel: 'Loading…' } : {})
                .done(function (r) {
                    state.schemes  = r.schemes || [];
                    state.schemeId = +r.scheme_id || 0;
                    state.comps    = r.components || [];
                    renderSchemes();
                    renderComps();
                    syncAssignOptions();
                }).fail(function () { $('#acBody').html(loadFail(6)); });
        }

        $('#asRefresh').on('click', function () { loadAssessment('#asRefresh'); });

        $('#asBar').on('click', '.gs-chip', function () {
            var id = +$(this).data('id');
            if (id === +state.schemeId) return;
            loadAssessment(null, id);
        });

        function asOpen(mode) {
            var c = curScheme();
            if (mode !== 'add' && !c) { ORMS.err('Pick an assessment scheme first'); return; }
            $('#asMode').val(mode);
            $('#asId').val(mode === 'rename' ? c.id : 0);
            $('#asName').val(mode === 'rename' ? c.name : (mode === 'clone' ? c.name + ' (Copy)' : ''));
            $('#asDesc').val(mode === 'rename' ? (c.description || '') : '');
            $('#asCloneNote').toggleClass('initially-hidden', mode !== 'clone')
                .html(mode === 'clone'
                    ? '<i class="fas fa-clone"></i><span>All <strong>' + (+c.comps) + '</strong> component(s) of <strong>' + esc(c.name) +
                      '</strong> are copied into the new scheme, weights included.</span>' : '');
            $('#asModalTitle').html(mode === 'rename' ? '<i class="fas fa-pen"></i> Rename Assessment Scheme'
                                  : (mode === 'clone' ? '<i class="fas fa-clone"></i> Clone Assessment Scheme' : '<i class="fas fa-percent"></i> New Assessment Scheme'));
            $('#asSaveBtn').html('<i class="fas fa-save"></i> ' + (mode === 'clone' ? 'Clone Scheme' : 'Save Scheme'));
            openModal('asModal');
        }

        $('#asAddBtn').on('click', function () { asOpen('add'); });
        $('#asRenameBtn').on('click', function () { asOpen('rename'); });
        $('#asCloneBtn').on('click', function () { asOpen('clone'); });

        $('#asForm').on('submit', function (e) {
            e.preventDefault();
            var mode = $('#asMode').val(), c = curScheme();
            var data = { name: $.trim($('#asName').val()), description: $.trim($('#asDesc').val()) };
            if (!data.name) { ORMS.err('Scheme name is required'); return; }
            if (mode === 'clone') data.source_id = c ? c.id : 0; else data.id = mode === 'rename' ? $('#asId').val() : 0;

            ORMS.postOrFail(mode === 'clone' ? 'cloneAssessmentScheme' : 'saveAssessmentScheme', data,
                            { btn: '#asSaveBtn', busyLabel: mode === 'clone' ? 'Cloning…' : 'Saving…' })
                .done(function (r) {
                    closeModal('asModal');
                    ORMS.ok(mode === 'clone' ? 'Scheme cloned' : 'Scheme saved');
                    loadAssessment(null, +r.scheme_id || state.schemeId);
                });
        });

        $('#asActiveBtn').on('click', function () {
            var c = curScheme();
            if (!c) { ORMS.err('Pick an assessment scheme first'); return; }
            var on = +c.is_active === 1 ? 0 : 1;
            ORMS.postOrFail('setAssessmentSchemeActive', { id: c.id, is_active: on }, { btn: '#asActiveBtn', busyLabel: 'Saving…' })
                .done(function () { ORMS.ok(on ? 'Scheme activated' : 'Scheme deactivated'); loadAssessment(); });
        });

        $('#asDelBtn').on('click', function () {
            var c = curScheme();
            if (!c) { ORMS.err('Pick an assessment scheme first'); return; }
            ORMS.confirmDelete('Delete assessment scheme "' + c.name + '" and its ' + (+c.comps) + ' component(s)? This cannot be undone.',
                               'Delete this assessment scheme?').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('deleteAssessmentScheme', { id: c.id }, { btn: '#asDelBtn', busyLabel: 'Deleting…' })
                    .done(function () { ORMS.ok('Scheme deleted'); loadAssessment(null, 0); });
            });
        });

        // live "what this component is worth" note inside the modal
        function acFormNote() {
            var mx = parseFloat($('#acMax').val()), w = parseFloat($('#acWeight').val()), id = +$('#acId').val();
            if (isNaN(mx) || mx <= 0 || isNaN(w)) { $('#acFormNote').addClass('initially-hidden').html(''); return; }
            var others = state.comps.reduce(function (a, c) { return a + (+c.id === id ? 0 : (parseFloat(c.weight_percent) || 0)); }, 0);
            var sum = Math.round((others + w) * 100) / 100, bad = Math.abs(sum - 100) >= 0.01;
            $('#acFormNote').removeClass('initially-hidden').html(
                '<i class="fas fa-' + (bad ? 'triangle-exclamation' : 'circle-check') + '"></i><span>Full marks here (' + num(mx) + '/' + num(mx) +
                ') give <strong>' + num(w) + '</strong> of the subject\'s 100. With this saved, &Sigma; weights = <strong>' + num(sum) + '%</strong>' +
                (bad ? ' — ' + esc(weightWarn(sum)) : '') + '</span>');
        }
        $('#acMax, #acWeight').on('input', ORMS.debounce(acFormNote, 120));

        $('#acAddBtn').on('click', function () {
            if (!state.schemeId) { ORMS.err('Create an assessment scheme first'); return; }
            document.getElementById('acForm').reset();
            $('#acId').val('0');
            $('#acSchemeId').val(state.schemeId);
            $('#acSort').val(state.comps.length + 1);
            $('#acIsExam').prop('checked', false);
            $('#acModalTitle').html('<i class="fas fa-plus"></i> Add Component');
            acFormNote();
            openModal('acModal');
        });

        $('#acBody').on('click', '.js-comp-edit', function () {
            var id = +$(this).data('id');
            var c = state.comps.filter(function (v) { return +v.id === id; })[0];
            if (!c) return;
            $('#acId').val(c.id);
            $('#acSchemeId').val(c.scheme_id || state.schemeId);
            $('#acName').val(c.name);
            $('#acMax').val(num(c.max_marks));
            $('#acWeight').val(num(c.weight_percent));
            $('#acSort').val(c.sort_order);
            $('#acIsExam').prop('checked', +c.is_exam === 1);
            $('#acModalTitle').html('<i class="fas fa-edit"></i> Edit Component');
            acFormNote();
            openModal('acModal');
        });

        $('#acBody').on('click', '.js-comp-del', function () {
            var $b = $(this), id = +$b.data('id');
            var c = state.comps.filter(function (v) { return +v.id === id; })[0];
            ORMS.confirmDelete('Delete component "' + (c ? c.name : '') + '"? Any scores already entered against it block the delete.').then(function (yes) {
                if (!yes) return;
                ORMS.postOrFail('deleteAssessmentComponent', { id: id }, { btn: $b, busyLabel: ' ' })
                    .done(function (r) {
                        ORMS.ok('Component deleted');
                        if (r && r.warning) ORMS.err(r.warning, 'Check the weights');
                        loadAssessment();
                    });
            });
        });

        $('#acForm').on('submit', function (e) {
            e.preventDefault();
            var mx = parseFloat($('#acMax').val()), w = parseFloat($('#acWeight').val());
            if (isNaN(mx) || mx <= 0) { ORMS.err('Max marks must be greater than 0'); return; }
            if (isNaN(w) || w < 0 || w > 100) { ORMS.err('Weight must be between 0 and 100 percent'); return; }
            // unchecked boxes never serialize — send the flag explicitly
            var data = $(this).serializeArray();
            if (!$('#acIsExam').is(':checked')) data.push({ name: 'is_exam', value: '0' });
            ORMS.postOrFail('saveAssessmentComponent', $.param(data), { btn: '#acSaveBtn', busyLabel: 'Saving…' }).done(function (r) {
                closeModal('acModal');
                ORMS.ok('Component saved');
                if (r && r.warning) ORMS.err(r.warning, 'Weights do not total 100%');   // saved, but never silently
                loadAssessment();
            });
        });

        // set/scheme lists change under the assignment grid — repaint its options, keep the picks
        function syncAssignOptions() {
            if (state.sets.length) {
                var setOpts = '<option value="">— inherit default —</option>' + state.sets.map(function (v) {
                    return '<option value="' + (+v.id) + '">' + esc(v.name) + (+v.is_default === 1 ? ' (Default)' : '') + '</option>';
                }).join('');
                $('.js-class-gset').each(function () {
                    var keep = $(this).val();
                    $(this).html(setOpts).val(keep);
                    if (this.selectedIndex < 0) this.value = '';                 // set went away -> inherit
                });
                ORMS.dropdown.refresh('.js-class-gset');
            }
            if (state.schemes.length) {
                var schOpts = '<option value="">— no components —</option>' + state.schemes.filter(function (v) { return +v.is_active === 1; })
                    .map(function (v) { return '<option value="' + (+v.id) + '">' + esc(v.name) + '</option>'; }).join('');
                $('.js-class-ascheme').each(function () {
                    var keep = $(this).val();
                    $(this).html(schOpts).val(keep);
                    if (this.selectedIndex < 0) this.value = '';
                });
                ORMS.dropdown.refresh('.js-class-ascheme');
            }
        }

        $('#classAssignForm').on('submit', function (e) {
            e.preventDefault();
            ORMS.postOrFail('saveClassAssignments', $(this).serialize(), { btn: '#classAssignSaveBtn', busyLabel: 'Saving…' })
                .done(function () { ORMS.ok('Class assignments saved'); loadGrading(); loadAssessment(); });
        });

        // ---------------- options
        $('#optionsForm').on('submit', function (e) {
            e.preventDefault();
            var pf = $('#optPrefix').val().trim().toUpperCase();
            if (!/^[A-Z0-9-]{1,10}$/.test(pf)) { ORMS.err('Admission prefix: 1-10 characters, letters, digits or dash only'); return; }
            $('#optPrefix').val(pf);
            if ($('#optStudentPwd').val().trim().length < 6 || $('#optTeacherPwd').val().trim().length < 6) {
                ORMS.err('Default passwords must be at least 6 characters');
                return;
            }
            // serialize skips unchecked boxes — send every flag explicitly
            var data = $(this).serializeArray();
            $('#optionsForm .rs-toggle').each(function () {
                if (!this.checked) data.push({ name: this.name, value: '0' });
            });
            ORMS.postOrFail('saveOptions', $.param(data), { btn: '#optionsSaveBtn', busyLabel: 'Saving…' })
                .done(function () { ORMS.ok('Options saved'); });
        });

        // ---------------- modal plumbing
        $(document).on('click', '[data-close]', function () { closeModal($(this).data('close')); });
        $('.modal-overlay').on('click', function (e) { if (e.target === this) this.classList.remove('active'); });

        // ---------------- boot
        $(function () {
            ORMS.dropdown('#termYear');
            ORMS.dropdown('#termStatus');
            ORMS.dropdown('.js-class-gset');
            ORMS.dropdown('.js-class-ascheme');
            loadYears();
            loadTerms();
            loadGrading();
            loadAssessment();
        });
    })(jQuery);
    </script>
</body>
</html>
