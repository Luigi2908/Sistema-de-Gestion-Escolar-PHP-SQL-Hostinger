<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';
require_once 'result_engine.php';

// maintenance still wins — only a signed-in admin gets past it
if (isMaintenanceMode()) {
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'Admin') { header("Location: dashboard.php"); exit(); }
    header("Location: maintenance.php");
    exit();
}

// signed in already -> dashboard. the public counter below is for visitors only.
// a scanned qr (?verify=) stays on this page even for signed-in staff
if (isset($_SESSION['user_id']) && !isset($_GET['verify'])) { header("Location: dashboard.php"); exit(); }

const ORMS_RL_KEY  = '__result_lookup__';   // sentinel username -> reuses login_attempts, keyed by ip
const ORMS_RL_MAX  = 8;                     // lookups allowed
const ORMS_RL_WIN  = 10;                    // ...per this many minutes
// ONE line for every miss. wrong dob / unknown roll / unpublished / inactive must look identical
const ORMS_LOOKUP_FAIL = 'No published result found for the details entered. Please check and try again.';

// ---------------------------------------------------------------- throttle

function homeIp(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

// hits in the window — db is the real counter, session mirror keeps a fresh install throttled too
function homeHits(): int {
    $cut = time() - ORMS_RL_WIN * 60;
    $mem = array_values(array_filter((array)($_SESSION['orms_lookup_hits'] ?? []), fn($t) => (int)$t > $cut));
    $_SESSION['orms_lookup_hits'] = $mem;
    $n = count($mem);
    try {
        $win = ORMS_RL_WIN;
        $db  = (int)qVal("SELECT COUNT(*) FROM login_attempts
                          WHERE username = ? AND ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                         'ssi', ORMS_RL_KEY, homeIp(), $win);
        if ($db > $n) $n = $db;
    } catch (Throwable $e) { /* table missing -> session count stands */ }
    return $n;
}

// one attempt burned, success or failure — guessing right must not reset the window
function homeHit(): void {
    $mem = (array)($_SESSION['orms_lookup_hits'] ?? []);
    $mem[] = time();
    $_SESSION['orms_lookup_hits'] = $mem;
    try {
        qExec("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)", 'ss', ORMS_RL_KEY, homeIp());
        qExec("DELETE FROM login_attempts WHERE username = ? AND attempt_time < DATE_SUB(NOW(), INTERVAL 1 DAY)", 's', ORMS_RL_KEY);
    } catch (Throwable $e) {}
}

// ---------------------------------------------------------------- lookup bits

// yyyy-mm-dd and a real calendar date, nothing else
function homeIsDate(string $d): bool {
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// audit trail without the secret — dob is NEVER written here
function homeLog(string $outcome, array $f): void {
    logActivity(0, 'public', 'Public Result Lookup',
        'school=' . (int)$f['school'] . ', term=' . (int)$f['term'] . ', class=' . (int)$f['class']
        . ', section=' . (int)$f['section']
        . ', id=' . preg_replace('/[\x00-\x1F\x7F]/u', '', mb_substr((string)$f['ident'], 0, 30))
        . ' — ' . $outcome . ' — ip ' . homeIp());
}

// ---------------------------------------------------------------- which school

// saas switch. read ONCE and BEFORE any pin, so it always comes off the platform row.
// off -> this file behaves exactly like the single-school portal it has always been
function homeMulti(): bool {
    static $on = null;
    if ($on === null) $on = ormsHasTenancy() && ormsPlatformMode();
    return $on;
}

// tenants a visitor may pick. suspended/cancelled schools are not listed and cannot be looked up
function homeSchools(): array {
    static $rows = null;
    if ($rows !== null) return $rows;
    try { $rows = qAll("SELECT id, name, code FROM schools WHERE status IN ('Trial', 'Active') ORDER BY name ASC"); }
    catch (Throwable $e) { $rows = []; }
    return $rows;
}

// nothing picked yet / nothing to pick -> platform branding in saas mode, tenant #1 otherwise
// (a migrated single-school install keeps every setting row on school 1)
function homeDefaultSchool(): int { return homeMulti() ? 0 : 1; }

// the school this request is about. an id that isn't an active tenant resolves to 0, and 0
// dead-ends every query below — "wrong school" answers with the same line as "wrong roll no"
function homeSchool($raw): int {
    if (!homeMulti()) return 1;
    $id = (int)$raw;
    foreach (homeSchools() as $s) if ((int)$s['id'] === $id) return $id;
    return 0;
}

// whose settings this page reads. must run before the first getSetting() of the branch that needs it
function homePin(int $school): void { $GLOBALS['ORMS_SETTING_SCHOOL'] = $school; }

// one school's card/page branding. pins first, so every key comes back through the school-scoped
// getter instead of whichever tenant row an unscoped read happened to land on
function homeBrand(int $school): array {
    homePin($school);
    $site = getSiteBranding();
    $b = ormsResultBranding();
    foreach (array_keys($b) as $k) {
        $v = (string)getSetting($k, '');
        if ($v !== '') $b[$k] = $v;
    }
    // identity must never fall through to another tenant's row — reset it explicitly when unset here
    foreach (['result_school_name' => $site['site_name'], 'result_logo' => $site['site_logo'],
              'result_school_address' => '', 'result_school_phone' => ''] as $k => $d) {
        if ((string)getSetting($k, '') === '') $b[$k] = $d;
    }
    return $b;
}

// ---------------------------------------------------------------- lookup bits, one school at a time
// the whole cascade is scoped: anonymous traffic used to see every tenant's year/class/section names.
// each read tries the tenant query and falls back to the pre-migration one, so an un-migrated db runs
// this file unchanged.

function homeYears(int $school): array {
    if ($school <= 0) return [];
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return qAll("SELECT id, name FROM academic_years WHERE school_id = ? ORDER BY start_date DESC, id DESC", 'i', $school);
    } catch (Throwable $e) {
        try { return qAll("SELECT id, name FROM academic_years ORDER BY start_date DESC, id DESC"); }
        catch (Throwable $e2) { return []; }
    }
}

function homeCurrentYear(int $school): int {
    if ($school <= 0) return 0;
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return (int)qVal("SELECT id FROM academic_years WHERE is_current = 1 AND school_id = ? ORDER BY id DESC LIMIT 1", 'i', $school);
    } catch (Throwable $e) {
        try { return (int)qVal("SELECT id FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1"); }
        catch (Throwable $e2) { return 0; }
    }
}

// only terms a section has actually been published for — nothing else is checkable in public
function homePublishedTerms(int $yearId, int $school): array {
    if ($yearId <= 0 || $school <= 0) return [];
    $pub = " AND EXISTS (SELECT 1 FROM result_publications p WHERE p.term_id = t.id AND p.is_published = 1)";
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return qAll("SELECT t.id, t.name FROM exam_terms t
                     JOIN academic_years y ON y.id = t.academic_year_id AND y.school_id = ?
                     WHERE t.academic_year_id = ?" . $pub . "
                     ORDER BY t.sort_order ASC, t.id ASC", 'ii', $school, $yearId);
    } catch (Throwable $e) {
        try {
            return qAll("SELECT t.id, t.name FROM exam_terms t
                         WHERE t.academic_year_id = ?" . $pub . "
                         ORDER BY t.sort_order ASC, t.id ASC", 'i', $yearId);
        } catch (Throwable $e2) { return []; }
    }
}

// sections of a class; with a term picked, only the ones published for it so visitors can't pick a dead end
function homeSections(int $classId, int $termId, int $school): array {
    if ($classId <= 0 || $school <= 0) return [];
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return $termId > 0
            ? qAll("SELECT s.id, s.name FROM sections s
                    JOIN classes c ON c.id = s.class_id AND c.school_id = ?
                    JOIN result_publications p ON p.section_id = s.id AND p.term_id = ? AND p.is_published = 1
                    WHERE s.class_id = ? AND s.is_active = 1 ORDER BY s.name ASC", 'iii', $school, $termId, $classId)
            : qAll("SELECT s.id, s.name FROM sections s
                    JOIN classes c ON c.id = s.class_id AND c.school_id = ?
                    WHERE s.class_id = ? AND s.is_active = 1 ORDER BY s.name ASC", 'ii', $school, $classId);
    } catch (Throwable $e) {
        try {
            return $termId > 0
                ? qAll("SELECT s.id, s.name FROM sections s
                        JOIN result_publications p ON p.section_id = s.id AND p.term_id = ? AND p.is_published = 1
                        WHERE s.class_id = ? AND s.is_active = 1 ORDER BY s.name ASC", 'ii', $termId, $classId)
                : qAll("SELECT id, name FROM sections WHERE class_id = ? AND is_active = 1 ORDER BY name ASC", 'i', $classId);
        } catch (Throwable $e2) { return []; }
    }
}

function homeClasses(int $school): array {
    if ($school <= 0) return [];
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return qAll("SELECT id, name FROM classes WHERE is_active = 1 AND school_id = ? ORDER BY sort_order ASC, name ASC", 'i', $school);
    } catch (Throwable $e) {
        try { return qAll("SELECT id, name FROM classes WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"); }
        catch (Throwable $e2) { return []; }
    }
}

// the term through ITS OWN school. ormsTerm() scopes by sid(), which is 0 with no session,
// so the public page resolves the pair itself
function homeTerm(int $termId, int $school): ?array {
    if ($termId <= 0 || $school <= 0) return null;
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return qOne("SELECT t.* FROM exam_terms t JOIN academic_years y ON y.id = t.academic_year_id
                     WHERE t.id = ? AND y.school_id = ?", 'ii', $termId, $school);
    } catch (Throwable $e) {
        try { return qOne("SELECT * FROM exam_terms WHERE id = ?", 'i', $termId); }
        catch (Throwable $e2) { return null; }
    }
}

// the section through its class' school — a foreign section id reads as "not found"
function homeSection(int $secId, int $school): ?array {
    if ($secId <= 0 || $school <= 0) return null;
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return qOne("SELECT s.id, s.class_id FROM sections s JOIN classes c ON c.id = s.class_id
                     WHERE s.id = ? AND c.school_id = ?", 'ii', $secId, $school);
    } catch (Throwable $e) {
        try { return qOne("SELECT id, class_id FROM sections WHERE id = ?", 'i', $secId); }
        catch (Throwable $e2) { return null; }
    }
}

// dob is part of the WHERE, never compared afterwards. no dob on file = not lookup-able.
// roll_no and admission_no collide across tenants now, so the school leg is what keeps them apart —
// the fallback below drops it safely, the section was already proven to belong to this school
function homeStudent(int $secId, int $school, string $dob, string $ident): ?array {
    if ($secId <= 0 || $school <= 0) return null;
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        return qOne("SELECT id FROM students
                     WHERE section_id = ? AND school_id = ? AND status = 'Active' AND dob IS NOT NULL AND dob = ?
                       AND (roll_no = ? OR admission_no = ?)
                     LIMIT 1", 'iisss', $secId, $school, $dob, $ident, $ident);
    } catch (Throwable $e) {
        try {
            return qOne("SELECT id FROM students
                         WHERE section_id = ? AND status = 'Active' AND dob IS NOT NULL AND dob = ?
                           AND (roll_no = ? OR admission_no = ?)
                         LIMIT 1", 'isss', $secId, $dob, $ident, $ident);
        } catch (Throwable $e2) { return null; }
    }
}

// the owning school of a printed card — header branding ONLY. the token lookup itself stays global
function homeTokenSchool(string $tok): int {
    if (!ormsHasTenancy()) return 1;
    try { return (int)qVal("SELECT school_id FROM result_summaries WHERE verify_token = ?", 's', $tok); }
    catch (Throwable $e) {
        try {
            return (int)qVal("SELECT st.school_id FROM result_summaries rs
                              JOIN students st ON st.id = rs.student_id WHERE rs.verify_token = ?", 's', $tok);
        } catch (Throwable $e2) { return 0; }
    }
}

// frozen flag when the published card carries one, live rule otherwise. dob never reaches this
function homeWithheld(array $res, int $studentId): bool {
    if (array_key_exists('withheld', $res)) return (int)$res['withheld'] === 1;
    return !empty(ormsWithholdCheck($studentId, (int)($res['term']['academic_year_id'] ?? 0) ?: null)['withheld']);
}

// the school's own wording — no balance, no reason, no marks
function homeWithholdBlock(): string {
    $msg = (string)getSetting('withhold_message', 'This result has been withheld by the school. Please contact the school office.');
    return '<div class="rc-withheld"><i class="fas fa-lock"></i><h3>Result Withheld</h3><p>'
         . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
}

// stored flag only — the qr verifies the card as it was published
function homeCardWithheld(string $tok): bool {
    try { return (int)qVal("SELECT is_withheld FROM result_summaries WHERE verify_token = ?", 's', $tok) === 1; }
    catch (Throwable $e) { return false; }
}

// ---------------------------------------------------------------- qr verify

// ?verify=<token> — the qr printed on every published card resolves here. the token IS the
// capability: no dob, no login. one identical failure line for every miss, same throttle as the form
$verifyTok = trim((string)($_GET['verify'] ?? ''));
if ($verifyTok !== '') {
    $vr = null; $vSchool = 0;
    $vFail = 'This verification code is not valid, or the result is no longer published.';
    if (homeHits() >= ORMS_RL_MAX) {
        $vFail = 'Too many attempts from this device. Please wait ' . ORMS_RL_WIN . ' minutes and try again.';
    } else {
        homeHit();
        if (preg_match('/^[a-f0-9]{32}$/', $verifyTok)) {
            try {
                $vr = qOne("SELECT rs.percentage, rs.grade, rs.gpa, rs.`position`, rs.section_total, rs.result_status,
                                   rs.total_obtained, rs.total_max,
                                   u.full_name, u.username, st.admission_no, st.roll_no,
                                   c.name AS class_name, c.show_position AS class_show_pos, sec.name AS section_name,
                                   t.name AS term_name, ay.name AS year_name, rp.published_at
                            FROM result_summaries rs
                            JOIN students st  ON st.id = rs.student_id
                            JOIN users u      ON u.id = st.user_id
                            JOIN sections sec ON sec.id = rs.section_id
                            JOIN classes c    ON c.id = sec.class_id
                            JOIN exam_terms t ON t.id = rs.term_id
                            JOIN academic_years ay ON ay.id = rs.academic_year_id
                            JOIN result_publications rp ON rp.term_id = rs.term_id AND rp.section_id = rs.section_id AND rp.is_published = 1
                            WHERE rs.verify_token = ?", 's', $verifyTok);
            } catch (Throwable $e) { $vr = null; }
        }
        // token never logged — a copied log line must not become a working verify link.
        // admission numbers repeat across tenants, so the log line carries the owning school
        $vWh = $vr && homeCardWithheld($verifyTok);      // withheld card = same invalid line, nothing new on the wire
        $vSchool = $vr ? homeTokenSchool($verifyTok) : 0;
        logActivity(0, 'public', 'Result Verify',
            ($vr ? ($vWh ? 'withheld: ' : 'verified: ') . $vr['admission_no'] . ' — ' . $vr['term_name'] . ' ' . $vr['year_name']
                        . ' — school ' . $vSchool
                 : 'invalid or unpublished token')
            . ' — ip ' . homeIp());
        if ($vWh) $vr = null;
    }
    // a genuine card is headed by the school that issued it; every dead end keeps the neutral
    // branding, so a withheld/invalid token still says nothing about whose card it is
    $brand = homeBrand(($vr ? $vSchool : 0) ?: homeDefaultSchool());
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $vPos = $vr && getSetting('result_show_position', '1') === '1' && (int)($vr['class_show_pos'] ?? 1) === 1 && $vr['position'];
    $vGpa = $vr && getSetting('result_show_gpa', '1') === '1';
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
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="same-origin">
    <title>Result Verification - <?php echo $e($brand['result_school_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="orms-home-page">
        <header class="orms-home-hero no-print">
            <?php if (($brand['result_logo'] ?? '') !== ''): ?>
            <img class="orms-home-logo" src="<?php echo $e($brand['result_logo']); ?>" alt="">
            <?php endif; ?>
            <div class="orms-home-hero-text">
                <h1 class="orms-home-school"><?php echo $e($brand['result_school_name']); ?></h1>
                <span class="orms-home-tag"><i class="fas fa-qrcode"></i> Result Verification</span>
            </div>
        </header>
        <main class="orms-home-main">
            <?php if ($vr): ?>
            <div class="data-section orms-home-card verify-card verify-ok">
                <div class="verify-badge"><i class="fas fa-circle-check"></i></div>
                <h2>Genuine Result Card</h2>
                <p class="verify-sub">This result was issued by <?php echo $e($brand['result_school_name']); ?> and matches our records exactly.</p>
                <div class="about-table-wrapper">
                    <table class="about-roles-table">
                        <tbody>
                            <tr><th><i class="fas fa-user-graduate"></i> Student</th><td><?php echo $e($vr['full_name'] ?: $vr['username']); ?></td></tr>
                            <tr><th><i class="fas fa-id-card"></i> Admission No</th><td><?php echo $e($vr['admission_no']); ?></td></tr>
                            <tr><th><i class="fas fa-hashtag"></i> Roll No</th><td><?php echo $e($vr['roll_no'] !== null && $vr['roll_no'] !== '' ? $vr['roll_no'] : '—'); ?></td></tr>
                            <tr><th><i class="fas fa-school"></i> Class</th><td><?php echo $e($vr['class_name'] . ' – ' . $vr['section_name']); ?></td></tr>
                            <tr><th><i class="fas fa-clipboard-list"></i> Examination</th><td><?php echo $e($vr['term_name'] . ' — ' . $vr['year_name']); ?></td></tr>
                            <tr><th><i class="fas fa-bullseye"></i> Total Marks</th><td><?php echo $e(rtrim(rtrim(number_format((float)$vr['total_obtained'], 2, '.', ''), '0'), '.') . ' / ' . rtrim(rtrim(number_format((float)$vr['total_max'], 2, '.', ''), '0'), '.')); ?></td></tr>
                            <tr><th><i class="fas fa-percent"></i> Percentage</th><td><?php echo $e(number_format((float)$vr['percentage'], 2)); ?>%</td></tr>
                            <tr><th><i class="fas fa-award"></i> Grade</th><td><?php echo $e($vr['grade'] ?: '—'); ?></td></tr>
                            <?php if ($vGpa): ?><tr><th><i class="fas fa-star-half-stroke"></i> GPA</th><td><?php echo $e(number_format((float)$vr['gpa'], 2)); ?></td></tr><?php endif; ?>
                            <?php if ($vPos): ?><tr><th><i class="fas fa-ranking-star"></i> Position</th><td><?php echo $e(ormsOrdinal((int)$vr['position']) . ' of ' . (int)$vr['section_total']); ?></td></tr><?php endif; ?>
                            <tr><th><i class="fas fa-flag-checkered"></i> Result</th>
                                <td><span class="status-badge <?php echo $vr['result_status'] === 'PASS' ? 'status-active' : 'status-inactive'; ?>"><?php echo $e($vr['result_status']); ?></span></td></tr>
                            <tr><th><i class="fas fa-bullhorn"></i> Published</th><td><?php echo $e($vr['published_at'] ? date('d M Y', strtotime($vr['published_at'])) : '—'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="verify-note"><i class="fas fa-shield-halved"></i> If the printed card shows different numbers than this page, the printed card has been altered.</p>
            </div>
            <?php else: ?>
            <div class="data-section orms-home-card verify-card verify-bad">
                <div class="verify-badge"><i class="fas fa-circle-xmark"></i></div>
                <h2>Not Verified</h2>
                <p class="verify-sub"><?php echo $e($vFail); ?></p>
            </div>
            <?php endif; ?>
            <div class="data-section orms-home-card orms-home-links no-print mt-20">
                <a class="btn btn-secondary" href="index.php"><i class="fas fa-magnifying-glass"></i> Check a Result</a>
                <a class="btn btn-secondary" href="login.php"><i class="fas fa-right-to-bracket"></i> Staff &amp; Student Login</a>
            </div>
        </main>
        <footer class="orms-home-footer no-print">
            <p><?php echo $e(getSiteBranding()['copyright_text']); ?></p>
        </footer>
    </div>
</body>
</html>
<?php
    exit();
}

// ---------------------------------------------------------------- ajax

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        switch ($action) {
            // school step -> that tenant's years, terms and classes in one round trip
            case 'getSchoolData':
                requireCsrfJson();
                $school = homeSchool($_POST['school_id'] ?? 0);
                $yrs    = homeYears($school);
                $cur    = homeCurrentYear($school) ?: (int)($yrs[0]['id'] ?? 0);
                jsonOk(['years' => $yrs, 'year_id' => $cur, 'classes' => homeClasses($school),
                        'terms' => homePublishedTerms($cur, $school)]);

            case 'getTerms':
                requireCsrfJson();
                jsonOk(['data' => homePublishedTerms((int)($_POST['year_id'] ?? 0), homeSchool($_POST['school_id'] ?? 0))]);

            case 'getSections':
                requireCsrfJson();
                jsonOk(['data' => homeSections((int)($_POST['class_id'] ?? 0), (int)($_POST['term_id'] ?? 0),
                                               homeSchool($_POST['school_id'] ?? 0))]);

            case 'lookup':
                requireCsrfJson();                       // public form, still not cross-site postable

                // throttle checked BEFORE a single student row is touched
                if (homeHits() >= ORMS_RL_MAX) {
                    jsonErr('Too many attempts from this device. Please wait ' . ORMS_RL_WIN . ' minutes and try again.');
                }
                homeHit();

                // school first — an unknown one resolves to 0 and dead-ends everything below
                $school  = homeSchool($_POST['school_id'] ?? 0);
                homePin($school);                        // withhold wording + card branding follow this tenant
                $yearId  = (int)($_POST['year_id'] ?? 0);
                $termId  = (int)($_POST['term_id'] ?? 0);
                $classId = (int)($_POST['class_id'] ?? 0);
                $secId   = (int)($_POST['section_id'] ?? 0);
                $ident   = trim((string)($_POST['ident'] ?? ''));   // roll no OR admission no
                $dob     = trim((string)($_POST['dob'] ?? ''));
                $tag     = ['school' => $school, 'term' => $termId, 'class' => $classId, 'section' => $secId, 'ident' => $ident];

                // every branch below answers with the SAME line — which field was wrong is never
                // revealed, and an unknown school is just another wrong field
                if (!$school || !$termId || !$secId || $ident === '' || !homeIsDate($dob)) {
                    homeLog('incomplete', $tag); jsonErr(ORMS_LOOKUP_FAIL);
                }

                $term = homeTerm($termId, $school);
                if (!$term || ($yearId && (int)$term['academic_year_id'] !== $yearId)) {
                    homeLog('bad term', $tag); jsonErr(ORMS_LOOKUP_FAIL);
                }

                $sec = homeSection($secId, $school);
                if (!$sec || ($classId && (int)$sec['class_id'] !== $classId)) {
                    homeLog('bad section', $tag); jsonErr(ORMS_LOOKUP_FAIL);
                }

                // publish gate FIRST — an unpublished term never reaches a students row
                if (!ormsIsPublished($termId, $secId)) {
                    homeLog('not published', $tag); jsonErr(ORMS_LOOKUP_FAIL);
                }

                // dob goes INTO the where clause and is never read back, compared or logged
                $st = homeStudent($secId, $school, $dob, $ident);
                if (!$st) { homeLog('no match', $tag); jsonErr(ORMS_LOOKUP_FAIL); }

                $res = ormsStudentResult((int)$st['id'], $termId);
                if (!$res || empty($res['published'])) { homeLog('no card', $tag); jsonErr(ORMS_LOOKUP_FAIL); }

                // identity is already proven here — roll/admission AND dob matched and the term is published,
                // so naming the withhold leaks nothing new. every branch above still answers ORMS_LOOKUP_FAIL
                if (homeWithheld($res, (int)$st['id'])) {
                    homeLog('withheld', $tag);
                    jsonOk(['html' => homeWithholdBlock(), 'withheld' => 1]);
                }

                homeLog('success', $tag);
                jsonOk(['html' => ormsRenderResultCard($res, homeBrand($school))]);   // the card wears ITS school's name

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('index.php public lookup error: ' . $e->getMessage());
        jsonErr('Service unavailable. Please try again later.');   // never leak schema to the public
    }
}

// ---------------------------------------------------------------- page data

$multi   = homeMulti();                   // saas mode -> the form opens with a School step
$schools = $multi ? homeSchools() : [];
$school  = homeSchool($_GET['school'] ?? 0);
if ($multi && !$school && count($schools) === 1) $school = (int)$schools[0]['id'];   // one tenant -> no pointless step

// pin BEFORE the first read — hero, withhold wording and the card all follow this school
$brand   = homeBrand($school ?: homeDefaultSchool());
$years   = homeYears($school);
$yearId  = homeCurrentYear($school) ?: (int)($years[0]['id'] ?? 0);
$yearNm  = '';
foreach ($years as $y) if ((int)$y['id'] === $yearId) { $yearNm = $y['name']; break; }

$terms   = homePublishedTerms($yearId, $school);
$classes = homeClasses($school);
// fresh install -> friendly notice, never a fatal. in saas mode "ready" means there IS a tenant to pick
$ready   = $multi ? (bool)$schools : ($years && $classes);
$today   = date('Y-m-d');
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
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="same-origin">
    <title>Check Your Result - <?php echo htmlspecialchars($brand['result_school_name']); ?></title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="orms-home-page">

        <header class="orms-home-hero no-print">
            <?php if (($brand['result_logo'] ?? '') !== ''): ?>
            <img class="orms-home-logo" src="<?php echo htmlspecialchars($brand['result_logo']); ?>" alt="">
            <?php endif; ?>
            <div class="orms-home-hero-text">
                <h1 class="orms-home-school"><?php echo htmlspecialchars($brand['result_school_name']); ?></h1>
                <?php if (($brand['result_school_address'] ?? '') !== ''): ?>
                <p class="orms-home-meta"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($brand['result_school_address']); ?></p>
                <?php endif; ?>
                <?php if (($brand['result_school_phone'] ?? '') !== ''): ?>
                <p class="orms-home-meta"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($brand['result_school_phone']); ?></p>
                <?php endif; ?>
                <span class="orms-home-tag"><i class="fas fa-award"></i> Online Result Portal</span>
            </div>
        </header>

        <div class="orms-home-trustbar no-print">
            <span><i class="fas fa-shield-halved"></i> Official school portal</span>
            <span><i class="fas fa-bullhorn"></i> Published results only</span>
            <span><i class="fas fa-qrcode"></i> QR-verifiable cards</span>
        </div>

        <main class="orms-home-main">

            <?php if (!$ready): ?>
            <div class="data-section orms-home-card no-print">
                <div class="orms-empty">
                    <i class="fas fa-screwdriver-wrench"></i>
                    <h4>Result portal is not ready yet</h4>
                    <p><?php echo $multi ? 'No school has been set up on this portal yet.' : 'Classes and academic years have not been set up.'; ?> Please check back later.</p>
                </div>
            </div>
            <div class="data-section orms-home-card orms-home-links no-print mt-20">
                <a class="btn btn-secondary" href="login.php"><i class="fas fa-right-to-bracket"></i> Staff &amp; Student Login</a>
            </div>
            <?php else: ?>

            <div class="orms-home-steps no-print">
                <div class="orms-step">
                    <span class="orms-step-n">1</span>
                    <div>
                        <h4><i class="fas <?php echo $multi ? 'fa-city' : 'fa-clipboard-list'; ?>"></i> <?php echo $multi ? 'Find your school' : 'Select the exam'; ?></h4>
                        <p><?php echo $multi ? 'Search by school name or code, then pick the exam.' : 'Academic year and term, then your class and section.'; ?></p>
                    </div>
                </div>
                <div class="orms-step">
                    <span class="orms-step-n">2</span>
                    <div>
                        <h4><i class="fas fa-id-card"></i> Prove it's you</h4>
                        <p>Roll or admission number plus your date of birth.</p>
                    </div>
                </div>
                <div class="orms-step">
                    <span class="orms-step-n">3</span>
                    <div>
                        <h4><i class="fas fa-file-lines"></i> View &amp; print</h4>
                        <p>The full card appears instantly &mdash; print or save as PDF.</p>
                    </div>
                </div>
            </div>

            <div class="data-section orms-home-card orms-card-accent no-print" id="lookupCard">
                <div class="section-header">
                    <h2><i class="fas fa-magnifying-glass"></i> Check Your Result</h2>
                </div>

                <?php if ($multi && !$school): ?>
                <div class="info-banner mb-24">
                    <i class="fas fa-city"></i>
                    <span>Start by choosing your school &mdash; search by its name or its school code. Roll and admission numbers are only unique inside a school.</span>
                </div>
                <?php elseif (!$terms): ?>
                <div class="info-banner info-banner-warning mb-24">
                    <i class="fas fa-hourglass-half"></i>
                    <span>No results have been published for <?php echo htmlspecialchars($yearNm !== '' ? $yearNm : 'the current session'); ?> yet. Try another academic year, or check back after your school announces the result.</span>
                </div>
                <?php else: ?>
                <div class="info-banner mb-24">
                    <i class="fas fa-shield-halved"></i>
                    <span>Enter your details exactly as they appear on your admission record. Date of birth is required &mdash; it confirms the result belongs to you.</span>
                </div>
                <?php endif; ?>

                <form id="lookupForm" autocomplete="off">
                    <div class="orms-form-cols">
                    <div class="orms-form-col">
                    <?php if ($multi): ?>
                    <div class="orms-fs-title"><i class="fas fa-city"></i> Your School</div>
                    <div class="form-grid orms-tn-step">
                        <div class="form-group">
                            <label for="fSchool"><i class="fas fa-city"></i> School *</label>
                            <select id="fSchool">
                                <option value="">Select your school</option>
                                <?php foreach ($schools as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"<?php echo (int)$s['id'] === $school ? ' selected' : ''; ?>><?php echo htmlspecialchars($s['name'] . ' (' . $s['code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="orms-fs-title"><i class="fas fa-graduation-cap"></i> Examination</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fYear"><i class="fas fa-calendar-days"></i> Academic Year</label>
                            <select id="fYear">
                                <?php if (!$years): ?>
                                <option value="">Select school first</option>
                                <?php else: foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>"<?php echo (int)$y['id'] === $yearId ? ' selected' : ''; ?>><?php echo htmlspecialchars($y['name']); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fTerm"><i class="fas fa-clipboard-list"></i> Exam Term</label>
                            <select id="fTerm">
                                <?php if (!$terms): ?>
                                <option value="">No published results</option>
                                <?php else: foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fClass"><i class="fas fa-school"></i> Class</label>
                            <select id="fClass">
                                <option value=""><?php echo $classes ? 'Select class' : ($multi && !$school ? 'Select school first' : 'No classes yet'); ?></option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fSection"><i class="fas fa-layer-group"></i> Section</label>
                            <select id="fSection">
                                <option value="">Select class first</option>
                            </select>
                        </div>
                    </div>
                    </div>

                    <div class="orms-form-col">
                    <div class="orms-fs-title"><i class="fas fa-user-graduate"></i> Student Identity</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fIdent"><i class="fas fa-id-card"></i> Roll No or Admission No</label>
                            <input type="text" id="fIdent" maxlength="30" required placeholder="e.g. 12 or STU-2026-0001">
                        </div>
                        <div class="form-group">
                            <label for="fDob"><i class="fas fa-cake-candles"></i> Date of Birth *</label>
                            <input type="date" id="fDob" required max="<?php echo $today; ?>">
                        </div>
                    </div>
                    </div>
                    </div>

                    <div class="form-actions orms-actions-row">
                        <button type="submit" class="btn btn-primary btn-block orms-home-submit" id="btnCheck">
                            <i class="fas fa-magnifying-glass"></i> Check Result
                        </button>
                        <a class="btn btn-secondary" href="login.php"><i class="fas fa-right-to-bracket"></i> Staff &amp; Student Login</a>
                    </div>
                </form>
            </div>

            <!-- placeholder while the lookup is in flight — card shape, no layout jump -->
            <div class="data-section orms-home-card no-print initially-hidden" id="lookupSkeleton">
                <div class="skeleton skeleton-text-large skeleton-w-50 skeleton-mb-md"></div>
                <div class="skeleton-table">
                    <?php for ($i = 0; $i < 5; $i++): ?>
                    <div class="skeleton-table-row">
                        <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                        <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="data-section orms-home-card orms-card-accent initially-hidden" id="resultWrap">
                <div class="section-header no-print">
                    <h2><i class="fas fa-file-lines"></i> Result Card</h2>
                    <div class="btn-group-inline">
                        <a class="btn btn-secondary" href="index.php"><i class="fas fa-house"></i> Back to Home</a>
                        <button type="button" class="btn btn-secondary" id="btnAnother"><i class="fas fa-magnifying-glass"></i> Check Another</button>
                        <button type="button" class="btn btn-primary" id="btnPrintResult" onclick="ORMS.printOnly('#resultBody')"><i class="fas fa-print"></i> Print / Save PDF</button>
                    </div>
                </div>
                <div id="resultBody"></div>
            </div>

            <?php endif; ?>

        </main>

        <footer class="orms-home-footer no-print">
            <p><?php echo htmlspecialchars(getSiteBranding()['copyright_text']); ?></p>
        </footer>
    </div>

    <button class="login-theme-toggle no-print" type="button" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?php echo csrfToken(); ?>';</script>
    <script>
    // theme toggle — no sidebar on this page, so it carries its own (same keys as the rest of the app)
    function initTheme() {
        var saved = localStorage.getItem('theme');
        if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }
    function toggleTheme() {
        var isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }
    function updateThemeIcon(isDark) {
        var i = document.getElementById('themeIcon');
        if (i) i.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    initTheme();

    var LOOKUP_FAIL = <?php echo json_encode(ORMS_LOOKUP_FAIL); ?>;

    var MULTI = <?php echo $multi ? 'true' : 'false'; ?>;      // saas mode -> the school step is live

    // every cascade call carries it — the server re-validates, this just keeps the round trips scoped
    function schoolId() { return MULTI ? ($('#fSchool').val() || 0) : 0; }

    // repaint a select from ajax rows and re-sync the searchable control over it
    function fill($el, rows, empty, lead) {
        $el.html(rows.length ? (lead || '') : '<option value="">' + empty + '</option>');
        rows.forEach(function (r) { $el.append($('<option>').val(r.id).text(r.name)); });
        ORMS.dropdown.refresh($el);
    }

    $(document).ready(function () {
        if (!$('#lookupForm').length) return;                 // portal not set up -> nothing to wire
        ORMS.dropdown('#fSchool, #fYear, #fTerm, #fClass, #fSection');
        $('#fSchool').on('change', loadSchool);
        $('#fYear').on('change', loadTerms);
        $('#fTerm').on('change', loadSections);
        $('#fClass').on('change', loadSections);
        $('#lookupForm').on('submit', checkResult);
        $('#btnAnother').on('click', resetLookup);
    });

    // school -> that tenant's years, terms and classes. one trip, then the usual cascade takes over
    function loadSchool() {
        var sid = $('#fSchool').val() || '';
        fill($('#fYear'), [], sid ? 'Loading…' : 'Select school first');
        fill($('#fTerm'), [], sid ? 'Loading…' : 'Select school first');
        fill($('#fClass'), [], sid ? 'Loading…' : 'Select school first');
        fill($('#fSection'), [], 'Select class first');
        if (!sid) return;
        ORMS.post('getSchoolData', { school_id: sid }).done(function (res) {
            if (!res || !res.success) { loadSchoolFailed(); return; }
            fill($('#fYear'), res.years || [], 'No academic year yet');
            $('#fYear').val(res.year_id || '');
            ORMS.dropdown.refresh('#fYear');
            fill($('#fTerm'), res.terms || [], 'No published results');
            fill($('#fClass'), res.classes || [], 'No classes yet', '<option value="">Select class</option>');
        }).fail(loadSchoolFailed);
    }

    function loadSchoolFailed() {
        fill($('#fYear'), [], 'Could not load');
        fill($('#fTerm'), [], 'Could not load');
        fill($('#fClass'), [], 'Could not load');
    }

    // year -> published terms only
    function loadTerms() {
        fill($('#fTerm'), [], 'Loading…');
        ORMS.post('getTerms', { year_id: $('#fYear').val() || 0, school_id: schoolId() }).done(function (res) {
            fill($('#fTerm'), (res && res.data) || [], 'No published results');
            loadSections();
        }).fail(function () { fill($('#fTerm'), [], 'No published results'); });
    }

    // class (+ term) -> sections that actually have a published result
    function loadSections() {
        var cid = $('#fClass').val() || '', tid = $('#fTerm').val() || '';
        fill($('#fSection'), [], cid ? 'Loading…' : 'Select class first');
        if (!cid) return;
        ORMS.post('getSections', { class_id: cid, term_id: tid || 0, school_id: schoolId() }).done(function (res) {
            fill($('#fSection'), (res && res.data) || [], 'No published section', '<option value="">Select section</option>');
        }).fail(function () { fill($('#fSection'), [], 'Could not load sections'); });
    }

    function checkResult(e) {
        e.preventDefault();
        var d = {
            school_id:  schoolId(),
            year_id:    $('#fYear').val() || 0,
            term_id:    $('#fTerm').val() || 0,
            class_id:   $('#fClass').val() || 0,
            section_id: $('#fSection').val() || 0,
            ident:      $.trim($('#fIdent').val() || ''),
            dob:        $('#fDob').val() || ''
        };
        if (MULTI && !d.school_id) {
            ORMS.err('Please choose your school first.', 'School missing');
            return;
        }
        if (!d.term_id || !d.class_id || !d.section_id || !d.ident || !d.dob) {
            ORMS.err('Please fill every field, including date of birth.', 'Details missing');
            return;
        }

        // bar + button spinner + card placeholder, never a blocking dialog
        $('#lookupSkeleton').removeClass('initially-hidden');
        ORMS.post('lookup', d, { btn: '#btnCheck', busyLabel: 'Checking…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || LOOKUP_FAIL, 'Result not found'); return; }
                $('#resultBody').html(res.html);                 // server-rendered + escaped
                $('#btnPrintResult').toggle(!res.withheld);       // withheld -> banner only, nothing to print
                ORMS.qr();                                       // fill the card's verify code
                $('#lookupCard').hide();
                $('.orms-home-page').addClass('orms-result-mode');   // card only — hero/steps/links leave
                $('#resultWrap').removeClass('initially-hidden').show();
                $('html, body').animate({ scrollTop: 0 }, 200);
            })
            .fail(function (msg) { ORMS.err(msg || 'Could not reach the server. Please try again.'); })
            .always(function () { $('#lookupSkeleton').addClass('initially-hidden'); });
    }

    function resetLookup() {
        $('#resultWrap').hide();
        $('#resultBody').empty();
        $('.orms-home-page').removeClass('orms-result-mode');        // bring the page back
        $('#lookupCard').show();
        $('#fIdent').val('');
        $('#fDob').val('');
        $('html, body').animate({ scrollTop: 0 }, 200);
    }
    </script>
</body>
</html>
