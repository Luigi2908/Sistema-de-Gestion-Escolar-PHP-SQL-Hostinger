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

$username = $_SESSION['username'];
$role     = $_SESSION['role'];
$user_id  = $_SESSION['user_id'];

// both ids arrive from the query string, so both are resolved against this school before anything
// reads them. the raw pair is kept for the deny log — a cross-tenant probe should be readable in it
$reqStudent = (int)($_GET['student_id'] ?? 0);
$reqTerm    = (int)($_GET['term_id'] ?? 0);
$studentId  = ormsOwnStudent($reqStudent);
$termId     = ormsOwnTerm($reqTerm);

$isStudent    = ormsStudentId($user_id) !== null;
$current_page = $isStudent ? 'my_results' : 'results';
$backLink     = $isStudent ? 'my_results.php' : 'results.php';

// staff can preview any skin — students always get the class template
$tplOverride = '';
if (!$isStudent && isset(ormsTemplates()[$_GET['template'] ?? ''])) $tplOverride = $_GET['template'];

// rbac view gate — students arrive from My Results, staff from either list. the branch gates below still decide the row
requirePerm($isStudent ? 'my_results' : (can('results', 'v') ? 'results' : 'my_results'), 'v');

// frozen flag when the card carries one, live rule otherwise -> [withheld, reason]
function rcWithheld(array $res, int $studentId): array {
    if (array_key_exists('withheld', $res)) return [(int)$res['withheld'] === 1, (string)($res['withheld_reason'] ?? '')];
    $c = ormsWithholdCheck($studentId, (int)($res['term']['academic_year_id'] ?? 0) ?: null);
    return [!empty($c['withheld']), (string)$c['reason']];
}

// access is decided here, on every request — the query string is never trusted
$res = null; $deny = ''; $asStaff = false;
$term = $termId ? ormsTerm($termId) : null;
$st   = $studentId ? qOne("SELECT id, user_id, section_id FROM students WHERE id = ?", 'i', $studentId) : null;

if (!$term || !$st) {
    $deny = 'That result card does not exist.';
} else {
    // the section this term was frozen against — a later promotion must not lock anyone out of an old card
    $sectionId = ormsResolveResultSection($studentId, $termId) ?? (int)$st['section_id'];
    $yearId    = (int)$term['academic_year_id'];
    $allowed   = false;

    if (ormsSchoolWide($role) && can('results', 'v')) {
        $allowed = $asStaff = true;                        // school-wide sees any card
    } elseif (can('results', 'v') && ($teacherId = ormsTeacherId($user_id))) {
        $allowed = (int)qVal("SELECT COUNT(*) FROM teacher_subjects
                              WHERE teacher_id = ? AND section_id = ? AND academic_year_id = ?",
                             'iii', $teacherId, $sectionId, $yearId) > 0;
        $asStaff = $allowed;                               // reached it as staff, not as the family
        if (!$allowed) $deny = 'You are not assigned to this section.';
    } elseif ((int)$st['user_id'] === $user_id) {
        $allowed = ormsIsPublished($termId, $sectionId);   // own card, published only
        if (!$allowed) $deny = 'This result has not been published yet.';
    } else {
        $deny = 'You can only open your own result card.';
    }

    if ($allowed) {
        $res = ormsStudentResult($studentId, $termId);
        if (!$res) $deny = 'That result card does not exist.';
    }
}

if ($deny !== '') {
    http_response_code(403);
    logActivity($user_id, $username, 'Result Card Denied', "student_id={$reqStudent}, term_id={$reqTerm} — {$deny}");
}

// withheld: staff print it stamped, the family gets the school's line instead of the card
list($whOn, $whReason) = $res ? rcWithheld($res, $studentId) : [false, ''];
$whBlock = $whOn && !$asStaff;
if ($whBlock) logActivity($user_id, $username, 'Result Card Withheld', "student_id={$studentId}, term_id={$termId}");
$whStamp = '';
if ($whOn && $asStaff) {
    $bits = [];                                                   // money detail is school-wide only
    if (ormsSchoolWide($role)) {
        if ($whReason !== '') $bits[] = $whReason;
        // same year as $whReason above — an unscoped call prints a lifetime figure beside it
        $bits[] = 'Balance ' . ormsMoney(ormsFeeBalance($studentId, (int)($res['term']['academic_year_id'] ?? 0) ?: null));
    }
    $whStamp = '<div class="rc-withheld"><i class="fas fa-stamp"></i> <b>WITHHELD</b> — hidden from the family'
             . ($bits ? ' &middot; ' . htmlspecialchars(implode(' · ', $bits), ENT_QUOTES, 'UTF-8') : '') . '</div>';
}

$cardTitle = $res ? (($res['student']['full_name'] ?: $res['student']['username']) . ' — ' . $res['term']['name']) : 'Result Card';
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
    <title><?php echo htmlspecialchars($cardTitle); ?> - Result Card</title>

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
            <div class="header no-print">
                <div class="header-page">
                    <h1><i class="fas fa-file-lines"></i> Result Card</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <a href="<?php echo $backLink; ?>"><?php echo $isStudent ? 'My Results' : 'Results'; ?></a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Result Card</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section">
                <div class="section-header no-print">
                    <h2><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($cardTitle); ?></h2>
                    <div class="btn-group-inline">
                        <?php if ($res && !$isStudent): ?>
                        <select id="tplPreview" title="Card template">
                            <option value="">Template: Class default</option>
                            <?php foreach (ormsTemplates() as $tk => $t): ?>
                            <option value="<?php echo htmlspecialchars($tk); ?>"<?php echo $tplOverride === $tk ? ' selected' : ''; ?>><?php echo htmlspecialchars($t['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <a class="btn btn-secondary no-print" href="<?php echo $backLink; ?>"><i class="fas fa-arrow-left"></i> Back</a>
                        <?php if ($res && !$whBlock): ?>
                        <button type="button" class="btn btn-primary no-print" onclick="ORMS.printOnly('#printCard')"><i class="fas fa-print"></i> Print / Save PDF</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($res && $whBlock): ?>
                    <div class="rc-withheld">
                        <i class="fas fa-lock"></i>
                        <h3>Result Withheld</h3>
                        <p><?php echo htmlspecialchars((string)getSetting('withhold_message', 'This result has been withheld by the school. Please contact the school office.')); ?></p>
                        <p class="rc-key-note"><?php echo htmlspecialchars($res['term']['name'] . ' — ' . ($res['year']['name'] ?? '')); ?></p>
                    </div>
                <?php elseif ($res): ?>
                    <?php if (!$res['published']): ?>
                    <div class="info-banner no-print">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Draft preview — this term is not published yet, so totals and positions can still change.</span>
                    </div>
                    <?php endif; ?>
                    <div id="printCard"><?php echo $whStamp . ormsRenderResultCard($res, null, $tplOverride !== '' ? $tplOverride : null); ?></div>
                <?php else: ?>
                    <div class="orms-empty">
                        <i class="fas fa-ban"></i>
                        <h4>Access denied</h4>
                        <p><?php echo htmlspecialchars($deny); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>
    <script>
        // template preview — staff flips skins without touching the class default
        ORMS.dropdown('#tplPreview');
        $('#tplPreview').on('change', function () {
            var base = 'result_card.php?student_id=<?= $studentId ?>&term_id=<?= $termId ?>';
            location.href = this.value ? base + '&template=' + encodeURIComponent(this.value) : base;
        });
    </script>
</body>
</html>
