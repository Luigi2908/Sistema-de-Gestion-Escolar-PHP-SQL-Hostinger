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
requirePerm('results', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = $_SESSION['user_id'];
$current_page = 'results';

// school-wide reach — the head of school sees and signs off on every section, same as admin
$isWide = ormsSchoolWide($role);
// publishing, unpublishing, sign-off and both written remarks sit behind the same gate
$canPublish = $isWide && can('results', 'e');
// head-teacher sign-off currently switched on? drives the chips and the publish button
$apprGate   = ormsApprovalRequired();
// what this school calls its head — "Principal", "Headmistress", whatever admin set
$headLabel  = (string)(getSetting('result_principal_designation', 'Principal') ?: 'Principal');

// section scope + the single-section gate now live in config.php (ormsSectionScope / ormsCanSeeSection).
// they never return "everything" — school-wide is THIS school's section list, so there is no
// unfiltered branch left for a posted section_id to slip through.

// every term/section/student id that arrives from the request goes through here first — a resolved
// 0 means it belongs to another school (or another branch) and the endpoint stops right there
function resIds(): array {
    return [ormsOwnTerm($_POST['term_id'] ?? 0), ormsOwnSection($_POST['section_id'] ?? 0)];
}

function resSectionLabel(int $sectionId): string {
    $r = qOne("SELECT c.name AS cn, s.name AS sn FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.id = ?", 'i', $sectionId);
    return $r ? $r['cn'] . ' – ' . $r['sn'] : 'Section #' . $sectionId;
}

// entered/expected -> the label the chevrons filter on
function resStatus(int $entered, int $expected, bool $published): string {
    if ($published) return 'Published';
    if ($expected <= 0 || $entered <= 0) return 'Not Started';
    return $entered >= $expected ? 'Complete' : 'In Progress';
}

// column probe, one per request — an install that hasn't taken the migration keeps the old behaviour
function resHasCol(string $table, string $col): bool {
    static $seen = [];
    $k = "$table.$col";
    if (!isset($seen[$k])) {
        try { qVal("SELECT `$col` FROM `$table` LIMIT 1"); $seen[$k] = true; }
        catch (Throwable $e) { $seen[$k] = false; }
    }
    return $seen[$k];
}

// id => name maps for the two per-class rule pickers, built once per request
function resRuleNames(): array {
    static $m = null;
    if ($m === null) {
        $m = ['sets' => [], 'schemes' => [], 'def' => 0];
        try {
            if (function_exists('ormsHasGradingSets') && ormsHasGradingSets()) {
                $m['sets'] = array_column(ormsGradingSets(false), 'name', 'id');
                $m['def']  = ormsDefaultSetId();
            }
            if (function_exists('ormsHasAssessment') && ormsHasAssessment())
                $m['schemes'] = array_column(ormsAssessmentSchemes(false), 'name', 'id');
        } catch (Throwable $e) {}
    }
    return $m;
}

// which rules a class grades by -> [set name, scheme name]. blank scheme = one mark box per subject
function resRuleLabels(?int $setId, ?int $schemeId): array {
    $m = resRuleNames();
    if (!$m['sets']) return ['', ''];
    return [(string)($m['sets'][$setId ?: $m['def']] ?? ''), $schemeId ? (string)($m['schemes'][$schemeId] ?? '') : ''];
}

// withheld students per section — ONE roster read + ONE grouped fee read for the whole listing, never per student.
// live = what the fee rule says right now, frozen = the flag actually hiding already-published cards
function resWithheldMap(array $sectionIds, int $termId, int $yearId): array {
    $out = ['live' => array_fill_keys($sectionIds, 0), 'frozen' => array_fill_keys($sectionIds, 0)];
    if (!$sectionIds || !function_exists('ormsFeeBalanceMap')) return $out;
    $ph = implode(',', array_fill(0, count($sectionIds), '?'));
    $it = str_repeat('i', count($sectionIds));

    try {
        $st = qAll("SELECT id, section_id, fee_hold FROM students WHERE status = 'Active' AND section_id IN ($ph)", $it, ...$sectionIds);
        if ($st) {
            $bal = ormsFeeBalanceMap(array_map('intval', array_column($st, 'id')), $yearId);
            $on  = ormsWithholdEnabled();
            $thr = ormsArrearsThreshold();
            foreach ($st as $s)                                   // manual hold OR over the arrears line
                if ((int)$s['fee_hold'] === 1 || ($on && (float)($bal[(int)$s['id']] ?? 0) > $thr)) $out['live'][(int)$s['section_id']]++;
        }
    } catch (Throwable $e) {}                                     // pre-migration db -> nothing is withheld

    try {
        foreach (qAll("SELECT section_id, SUM(is_withheld) AS n FROM result_summaries
                       WHERE term_id = ? AND section_id IN ($ph) GROUP BY section_id", 'i' . $it, $termId, ...$sectionIds) as $r)
            $out['frozen'][(int)$r['section_id']] = (int)$r['n'];
    } catch (Throwable $e) {}
    return $out;
}

// Handle AJAX requests
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'getTerms':
                $yearId = ormsOwnYear($_POST['year_id'] ?? $_GET['year_id'] ?? 0);   // another school's year -> no terms
                jsonOk(['data' => $yearId ? array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'status' => $t['status']], ormsTerms($yearId)) : []]);

            case 'getSections':
                $termId = ormsOwnTerm($_POST['term_id'] ?? 0);
                $term   = $termId ? ormsTerm($termId) : null;
                if (!$term) jsonErr('Please choose an exam term');
                $yearId = (int)$term['academic_year_id'];

                // never null — school-wide is this school's own section list, branch admins are pinned
                $scope = ormsSectionScope($role, $user_id, $yearId);
                if (!$scope) jsonOk(['data' => [], 'term' => $term['name']]);

                // one set-based read — counts per section, never a query per row.
                // electives: expected = students x core subjects + each student's own enrolments
                $hasEl   = ormsHasElectives();
                $coreSel = $hasEl
                    ? "(SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id AND cs.is_optional = 0)"
                    : "(SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id)";
                $elSel   = $hasEl
                    ? "(SELECT COUNT(*) FROM student_subjects ss
                          JOIN students st3       ON st3.id = ss.student_id AND st3.section_id = sec.id AND st3.status = 'Active'
                          JOIN class_subjects cs3 ON cs3.class_id = st3.class_id AND cs3.subject_id = ss.subject_id AND cs3.is_optional = 1
                        WHERE ss.academic_year_id = ?)"
                    : "0";
                // per-class grading rules ride along on the join we already have — never a lookup per row
                $ruleSel = resHasCol('classes', 'grading_set_id')
                    ? "c.grading_set_id, c.assessment_scheme_id,"
                    : "NULL AS grading_set_id, NULL AS assessment_scheme_id,";
                $scM = ormsMarksScopeSql();     // its ? sits between the entered subquery's term and the rp join's term
                $sql = "SELECT sec.id AS section_id, sec.name AS section_name, c.id AS class_id, c.name AS class_name,
                               $ruleSel
                               (SELECT COUNT(*) FROM students st WHERE st.section_id = sec.id AND st.status = 'Active') AS students,
                               (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) AS subjects,
                               $coreSel AS core_subjects,
                               $elSel AS elective_cells,
                               (SELECT COUNT(*) FROM marks m
                                  JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                                  JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                                WHERE m.section_id = sec.id AND m.term_id = ?
                                  AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . $scM . ") AS entered,
                               COALESCE(rp.is_published, 0) AS is_published, rp.published_at,
                               rp.unpublished_at, rp.unpublish_reason, uu.full_name AS unpublished_by_name,
                               COALESCE(rp.approval_status, 'Draft') AS approval_status, rp.review_note,
                               rp.submitted_at, rp.approved_at,
                               su.full_name AS submitted_by_name, au.full_name AS approved_by_name
                        FROM sections sec
                        JOIN classes c ON c.id = sec.class_id
                        LEFT JOIN result_publications rp ON rp.section_id = sec.id AND rp.term_id = ?
                        LEFT JOIN users uu ON uu.id = rp.unpublished_by
                        LEFT JOIN users su ON su.id = rp.submitted_by
                        LEFT JOIN users au ON au.id = rp.approved_by
                        WHERE sec.is_active = 1";
                // bind order follows the sql left to right: [elective year] term, [school], rp term, scope
                $types  = ($hasEl ? 'i' : '') . 'i' . ($scM ? 'i' : '') . 'i';
                $params = array_merge($hasEl ? [$yearId] : [], [$termId], $scM ? [sid()] : [], [$termId]);
                // scoping lives in the WHERE of the OUTER driver, not only in the entered subquery —
                // otherwise expected would span every school while entered counts one, and completion never hits 100%
                $sql   .= " AND sec.id IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
                $types .= str_repeat('i', count($scope));
                $params = array_merge($params, $scope);
                $sql .= " ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC";

                $raw = qAll($sql, $types, ...$params);
                // fee withholding for the whole listing in one pass — the publish dialog reads it straight off the row
                $wh  = resWithheldMap(array_map(fn($r) => (int)$r['section_id'], $raw), $termId, $yearId);

                $rows = array_map(function ($r) use ($hasEl, $wh) {
                    $students = (int)$r['students']; $subjects = (int)$r['subjects'];
                    $expected = $hasEl
                        ? $students * (int)$r['core_subjects'] + (int)$r['elective_cells']
                        : $students * $subjects;
                    $entered  = min((int)$r['entered'], $expected);
                    $pub      = (int)$r['is_published'] === 1;
                    $secId    = (int)$r['section_id'];
                    // published -> the frozen flag is what actually hides a card, otherwise the live rule
                    $held     = $pub ? (int)($wh['frozen'][$secId] ?? 0) : (int)($wh['live'][$secId] ?? 0);
                    $rl       = resRuleLabels($r['grading_set_id'] === null ? null : (int)$r['grading_set_id'],
                                              $r['assessment_scheme_id'] === null ? null : (int)$r['assessment_scheme_id']);
                    return [
                        'section_id'   => $secId,
                        'withheld'     => $held,
                        'gset'         => $rl[0],
                        'scheme'       => $rl[1],
                        'label'        => $r['class_name'] . ' – ' . $r['section_name'],
                        'class_id'     => (int)$r['class_id'],
                        'class_name'   => $r['class_name'],
                        'section_name' => $r['section_name'],
                        'students'     => $students,
                        'subjects'     => $subjects,
                        'entered'      => $entered,
                        'expected'     => $expected,
                        'pct'          => $expected > 0 ? round($entered / $expected * 100, 2) : 0,
                        'is_published' => $pub ? 1 : 0,
                        'published_at' => $r['published_at'] ? date('d M Y', strtotime($r['published_at'])) : '',
                        // unpublish trail — only meaningful while the section sits unpublished
                        'unpub_at'     => (!$pub && $r['unpublished_at']) ? date('d M Y H:i', strtotime($r['unpublished_at'])) : '',
                        'unpub_by'     => !$pub ? (string)($r['unpublished_by_name'] ?? '') : '',
                        'unpub_why'    => !$pub ? (string)($r['unpublish_reason'] ?? '') : '',
                        // sign-off trail — feeds the chip here and the whole Approvals tab, one read for both
                        'appr'         => (string)$r['approval_status'],
                        'sub_by'       => (string)($r['submitted_by_name'] ?? ''),
                        'sub_at'       => $r['submitted_at'] ? date('d M Y H:i', strtotime($r['submitted_at'])) : '',
                        'app_by'       => (string)($r['approved_by_name'] ?? ''),
                        'app_at'       => $r['approved_at'] ? date('d M Y H:i', strtotime($r['approved_at'])) : '',
                        'note'         => (string)($r['review_note'] ?? ''),
                        'status'       => resStatus($entered, $expected, $pub)
                    ];
                }, $raw);

                jsonOk(['data' => $rows, 'term' => $term['name'], 'gate' => $apprGate ? 1 : 0]);

            case 'publish':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');
                if (!$isWide) jsonErr('You are not allowed to publish results.');

                [$termId, $sectionId] = resIds();                        // another school's ids resolve to 0
                $term = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                $yearId = (int)$term['academic_year_id'];
                if (!ormsCanSeeSection($sectionId, $role, $user_id, $yearId)) jsonErr('You are not assigned to this section');
                // sign-off first — admin is not exempt, but admin can approve, so nobody gets locked out
                if (ormsApprovalBlocked($termId, $sectionId)) jsonErr('This section needs Principal approval before it can be published.');

                $res = ormsPublishSection($termId, $sectionId, $yearId, $user_id);
                if (!$res['ok']) jsonErr($res['message']);

                $label = resSectionLabel($sectionId);
                $msg   = 'Your ' . $term['name'] . ' result for ' . $label . ' has been published. Open My Results to view and print your result card.';
                foreach ($res['students'] as $uid) {                     // in-app + web push, after commit
                    try { createNotification($uid, 'Result Published', $msg, 'success', 'my_results.php'); } catch (Throwable $e) {}
                }
                logActivity($user_id, $username, 'Result Published', "Published {$term['name']} results for {$label} — {$res['count']} student(s)");
                jsonOk(['message' => $res['message'], 'count' => $res['count']]);

            case 'unpublish':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');
                if (!$isWide) jsonErr('You are not allowed to unpublish results.');

                [$termId, $sectionId] = resIds();
                $reason = trim($_POST['reason'] ?? '');
                $term   = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                if ($reason === '')       jsonErr('Please state why this result is being unpublished');
                if (!ormsCanSeeSection($sectionId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');

                $res = ormsUnpublishSection($termId, $sectionId, $user_id, $reason);
                if (!$res['ok']) jsonErr($res['message']);

                $label = resSectionLabel($sectionId);
                logActivity($user_id, $username, 'Result Unpublished', "Unpublished {$term['name']} results for {$label} — reason: {$reason}");
                jsonOk(['message' => $res['message']]);

            // ---- sign-off workflow: Draft -> Pending -> Approved -> published. reject sends it back ----

            // whoever owns the section pushes it up. no school-wide check — ormsCanSeeSection() is the reach limit
            case 'submitApproval':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');

                [$termId, $sectionId] = resIds();
                $term = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                $yearId = (int)$term['academic_year_id'];
                if (!ormsCanSeeSection($sectionId, $role, $user_id, $yearId)) jsonErr('You are not assigned to this section');
                if (ormsIsPublished($termId, $sectionId)) jsonErr('This section is already published');

                $state = ormsApprovalStatus($termId, $sectionId);
                if ($state === 'Pending')  jsonErr('This section is already waiting for approval');
                if ($state === 'Approved') jsonErr('This section is already approved — publish it from the Sections tab');

                $gate = ormsCompletionGate($termId, $sectionId, 'Submitting for approval');
                if (!$gate['ok']) jsonErr($gate['message']);

                $classId = (int)qVal("SELECT class_id FROM sections WHERE id = ?", 'i', $sectionId);
                if (!$classId) jsonErr('Section not found');
                // 5 base params: i term, i class, i section, i year, i submitted_by. +1 tenant col => 6
                $tenP = ormsHasSchoolCol('result_publications');
                $args = [$termId, $classId, $sectionId, $yearId, $user_id];
                if ($tenP) $args[] = sid();
                qExec("INSERT INTO result_publications (term_id, class_id, section_id, academic_year_id, is_published,
                                                        approval_status, submitted_by, submitted_at" . ($tenP ? ", school_id" : '') . ")
                       VALUES (?, ?, ?, ?, 0, 'Pending', ?, NOW()" . ($tenP ? ", ?" : '') . ")
                       ON DUPLICATE KEY UPDATE approval_status = 'Pending', submitted_by = VALUES(submitted_by),
                                               submitted_at = NOW(), review_note = NULL,
                                               class_id = VALUES(class_id), academic_year_id = VALUES(academic_year_id)",
                      'iiiii' . ($tenP ? 'i' : ''), ...$args);

                $label = resSectionLabel($sectionId);
                createNotificationForAdmins('Results Awaiting Approval',
                    $term['name'] . ' results for ' . $label . ' were submitted by ' . $username . ' and are waiting for your approval.',
                    'info', 'results.php');
                logActivity($user_id, $username, 'Result Submitted For Approval', "Submitted {$term['name']} results for {$label} for approval");
                jsonOk(['message' => 'Sent for approval — the ' . $headLabel . ' has been notified']);

            case 'approveSection':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');
                if (!$isWide) jsonErr('You are not allowed to approve results.');

                [$termId, $sectionId] = resIds();
                $term = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                if (!ormsCanSeeSection($sectionId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');
                if (ormsApprovalStatus($termId, $sectionId) !== 'Pending') jsonErr('Only a section waiting for approval can be approved');

                $subBy = (int)qVal("SELECT submitted_by FROM result_publications WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId);
                qExec("UPDATE result_publications SET approval_status = 'Approved', approved_by = ?, approved_at = NOW(), review_note = NULL
                       WHERE term_id = ? AND section_id = ?", 'iii', $user_id, $termId, $sectionId);

                $label = resSectionLabel($sectionId);
                if ($subBy) createNotification($subBy, 'Results Approved',
                    $term['name'] . ' results for ' . $label . ' were approved and can now be published.', 'success', 'results.php');
                logActivity($user_id, $username, 'Result Approved', "Approved {$term['name']} results for {$label}");
                jsonOk(['message' => 'Approved — this section can be published now']);

            case 'rejectSection':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');
                if (!$isWide) jsonErr('You are not allowed to review results.');

                [$termId, $sectionId] = resIds();
                $note = mb_substr(trim($_POST['note'] ?? ''), 0, 255);   // column is varchar(255)
                $term = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                if ($note === '')         jsonErr('Please state what needs fixing before sending this back');
                if (!ormsCanSeeSection($sectionId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');
                if (ormsApprovalStatus($termId, $sectionId) !== 'Pending') jsonErr('Only a section waiting for approval can be sent back');

                $subBy = (int)qVal("SELECT submitted_by FROM result_publications WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId);
                qExec("UPDATE result_publications SET approval_status = 'Rejected', review_note = ?, approved_by = NULL, approved_at = NULL
                       WHERE term_id = ? AND section_id = ?", 'sii', $note, $termId, $sectionId);

                $label = resSectionLabel($sectionId);
                if ($subBy) createNotification($subBy, 'Results Sent Back',
                    $term['name'] . ' results for ' . $label . ' were sent back for correction: ' . $note, 'warning', 'results.php');
                logActivity($user_id, $username, 'Result Rejected', "Sent back {$term['name']} results for {$label} — note: {$note}");
                jsonOk(['message' => 'Sent back for correction']);

            // head's comment on a published card — same shape as saveRemarks, tighter gate
            case 'savePrincipalRemarks':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');
                if (!$canPublish) jsonErr("You are not allowed to write the head teacher's remarks.");

                $termId    = ormsOwnTerm($_POST['term_id'] ?? 0);
                $studentId = ormsOwnStudent($_POST['student_id'] ?? 0);       // another school's child -> 0
                $remarks   = mb_substr(trim($_POST['remarks'] ?? ''), 0, 255);   // column is varchar(255)
                $term      = $termId ? ormsTerm($termId) : null;
                if (!$term || !$studentId) jsonErr('Invalid term or student');

                // frozen section, not the live seat — a promoted student's old card keeps its scope check
                $secId = ormsResolveResultSection($studentId, $termId);
                if (!$secId || !ormsCanSeeSection($secId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');

                $n = qExec("UPDATE result_summaries SET principal_remarks = ? WHERE student_id = ? AND term_id = ?",
                           'sii', $remarks, $studentId, $termId);
                if ($n === 0 && !qVal("SELECT id FROM result_summaries WHERE student_id = ? AND term_id = ?", 'ii', $studentId, $termId)) {
                    jsonErr('Publish this section first — remarks attach to a generated result');
                }

                $who = (string)qVal("SELECT u.full_name FROM students st JOIN users u ON u.id = st.user_id WHERE st.id = ?", 'i', $studentId);
                logActivity($user_id, $username, 'Principal Remarks Updated',
                            "{$term['name']} — {$who} (student_id={$studentId}) — " . ($remarks === '' ? 'cleared' : $remarks));
                jsonOk(['message' => $remarks === '' ? 'Remarks cleared' : 'Remarks saved', 'remarks' => $remarks]);

            case 'getTabulation':
                requireCsrfJson();
                [$termId, $sectionId] = resIds();
                $term = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                if (!ormsCanSeeSection($sectionId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');

                $subs = array_map(fn($s) => ['id' => (int)$s['subject_id'], 'name' => $s['name'],
                                             'total' => (float)$s['total_marks'], 'counted' => (int)$s['counted']],
                                  ormsSectionSubjects($sectionId));

                // both saved comments in one read — the tabulation never queries per student
                $rem = array_column(qAll("SELECT student_id, teacher_remarks, principal_remarks FROM result_summaries
                                          WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId),
                                    null, 'student_id');

                $rows = array_map(function ($r) use ($rem) {
                    $cells = [];
                    // ca/exam only exist when the class runs an assessment scheme — null keeps the cell single-valued
                    foreach ($r['subjects'] as $s) $cells[$s['subject_id']] = ['v' => $s['obtained'], 'ab' => $s['is_absent'], 'e' => $s['entered'],
                                                                               'ca' => $s['ca'] ?? null, 'ex' => $s['exam'] ?? null];
                    unset($r['subjects']);
                    $saved        = $rem[$r['student_id']] ?? null;      // no summary row = nothing to attach a remark to
                    $r['cells']     = $cells;
                    $r['remarks']   = (string)($saved['teacher_remarks'] ?? '');
                    $r['p_remarks'] = (string)($saved['principal_remarks'] ?? '');
                    $r['saved']     = $saved ? 1 : 0;
                    return $r;
                }, ormsBuildSectionResults($termId, $sectionId));

                // which rules produced these numbers — staff should never have to guess the class's grading set
                $clsSel = resHasCol('classes', 'grading_set_id')
                    ? "c.grading_set_id, c.assessment_scheme_id"
                    : "NULL AS grading_set_id, NULL AS assessment_scheme_id";
                $cls = qOne("SELECT $clsSel FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.id = ?", 'i', $sectionId);
                $rl  = resRuleLabels(($cls['grading_set_id'] ?? null) === null ? null : (int)$cls['grading_set_id'],
                                     ($cls['assessment_scheme_id'] ?? null) === null ? null : (int)$cls['assessment_scheme_id']);

                jsonOk(['subjects' => $subs, 'rows' => $rows, 'label' => resSectionLabel($sectionId),
                        'term' => $term['name'], 'published' => ormsIsPublished($termId, $sectionId) ? 1 : 0,
                        'gset' => $rl[0], 'scheme' => $rl[1],
                        'fees' => (function_exists('ormsHasFees')       && ormsHasFees())       ? 1 : 0,
                        'att'  => (function_exists('ormsHasAttendance') && ormsHasAttendance()) ? 1 : 0]);

            case 'saveRemarks':
                requireCsrfJson();                                      // csrf first, then rbac
                requirePermJson('results', 'e');
                if (!$canPublish) jsonErr('You are not allowed to write result remarks.');

                $termId    = ormsOwnTerm($_POST['term_id'] ?? 0);
                $studentId = ormsOwnStudent($_POST['student_id'] ?? 0);
                $remarks   = mb_substr(trim($_POST['remarks'] ?? ''), 0, 255);   // column is varchar(255)
                $term      = $termId ? ormsTerm($termId) : null;
                if (!$term || !$studentId) jsonErr('Invalid term or student');

                // frozen section, not the live seat — a promoted student's old card keeps its scope check
                $secId = ormsResolveResultSection($studentId, $termId);
                if (!$secId || !ormsCanSeeSection($secId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');

                // plain update on the stored row — totals, grades and positions are never recomputed here
                $n = qExec("UPDATE result_summaries SET teacher_remarks = ? WHERE student_id = ? AND term_id = ?",
                           'sii', $remarks, $studentId, $termId);
                if ($n === 0 && !qVal("SELECT id FROM result_summaries WHERE student_id = ? AND term_id = ?", 'ii', $studentId, $termId)) {
                    jsonErr('Publish this section first — remarks attach to a generated result');
                }

                $who = (string)qVal("SELECT u.full_name FROM students st JOIN users u ON u.id = st.user_id WHERE st.id = ?", 'i', $studentId);
                logActivity($user_id, $username, 'Result Remarks Updated',
                            "{$term['name']} — {$who} (student_id={$studentId}) — " . ($remarks === '' ? 'cleared' : $remarks));
                jsonOk(['message' => $remarks === '' ? 'Remarks cleared' : 'Remarks saved', 'remarks' => $remarks]);

            case 'bulkCards':
                requireCsrfJson();
                [$termId, $sectionId] = resIds();
                $term = $termId ? ormsTerm($termId) : null;
                if (!$term || !$sectionId) jsonErr('Invalid term or section');
                if (!ormsCanSeeSection($sectionId, $role, $user_id, (int)$term['academic_year_id'])) jsonErr('You are not assigned to this section');

                // batched: a handful of reads for the whole class, maths still comes from the engine
                $year  = qOne("SELECT * FROM academic_years WHERE id = ?", 'i', (int)$term['academic_year_id']);
                $brand = ormsResultBranding();
                $subs  = ormsSectionSubjects($sectionId);
                $mk = [];
                foreach (qAll("SELECT student_id, subject_id, marks_obtained, total_marks, passing_marks, is_absent, remarks
                               FROM marks WHERE section_id = ? AND term_id = ?", 'ii', $sectionId, $termId) as $m) {
                    $mk[(int)$m['student_id'] . ':' . (int)$m['subject_id']] = $m;
                }
                $stored = [];
                foreach (qAll("SELECT * FROM result_summaries WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId) as $s) {
                    $stored[(int)$s['student_id']] = $s;
                }
                $ranked = ormsBuildSectionResults($termId, $sectionId);
                $livePos = array_column($ranked, 'position', 'student_id');
                // denominator frozen at publish; live roster only while unpublished
                $first = $stored ? reset($stored) : null;
                $of  = $stored ? ((int)($first['section_total'] ?? 0) ?: count($stored)) : count($ranked);
                $pub = ormsIsPublished($termId, $sectionId);
                $ct  = ormsClassTeacherName($sectionId);   // one read, printed on every card

                $students = qAll("SELECT st.*, u.full_name, u.username, u.profile_image,
                                         c.name AS class_name, sec.name AS section_name
                                  FROM students st
                                  JOIN users u      ON u.id = st.user_id
                                  JOIN classes c    ON c.id = st.class_id
                                  JOIN sections sec ON sec.id = st.section_id
                                  WHERE st.section_id = ? AND st.status = 'Active'
                                  ORDER BY CAST(st.roll_no AS UNSIGNED) ASC, st.roll_no ASC, u.full_name ASC", 'i', $sectionId);

                $en = ormsElectiveMap(array_column($students, 'id'), (int)$term['academic_year_id']);

                $html = '';
                foreach ($students as $st) {
                    $sid  = (int)$st['id'];
                    $live = ormsComputeStudentRow($st, $subs, $mk, $en === null ? null : ($en[$sid] ?? []));
                    $s    = $stored[$sid] ?? null;
                    $html .= '<div class="page-break">' . ormsRenderResultCard([
                        'student'  => $st, 'term' => $term, 'year' => $year, 'subjects' => $live['subjects'],
                        'summary'  => $s ? [
                            'total_obtained' => (float)$s['total_obtained'], 'total_max' => (float)$s['total_max'],
                            'percentage' => (float)$s['percentage'], 'grade' => $s['grade'], 'gpa' => (float)$s['gpa'],
                            'result_status' => $s['result_status'], 'failed_subjects' => (string)$s['failed_subjects']
                        ] : $live,
                        'position' => $s ? ($s['position'] !== null ? (int)$s['position'] : null) : ($livePos[$sid] ?? null),
                        'section_total' => $of, 'published' => $pub,
                        'verify_token' => (string)($s['verify_token'] ?? ''),
                        'teacher_remarks' => (string)($s['teacher_remarks'] ?? ''), 'class_teacher' => $ct,
                        'principal_remarks' => (string)($s['principal_remarks'] ?? '')
                    ], $brand) . '</div>';
                }
                if ($html === '') jsonErr('This section has no active students');
                jsonOk(['html' => $html, 'count' => count($students)]);

            // merit & analytics — published summaries + entered marks, all reads, teacher-scoped
            case 'getMerit':
                requireCsrfJson();
                $termId = ormsOwnTerm($_POST['term_id'] ?? 0);
                $term   = $termId ? ormsTerm($termId) : null;
                if (!$term) jsonErr('Please choose an exam term');
                $yearId = (int)$term['academic_year_id'];

                // the section list IS the tenant filter on every driver below — no query here runs unscoped
                $scope = ormsSectionScope($role, $user_id, $yearId);
                if (!$scope) {
                    jsonOk(['toppers' => [], 'subject_best' => [], 'teacher_avg' => [], 'yoy' => [], 'term' => $term['name']]);
                }
                $in = fn(string $col) => " AND $col IN (" . implode(',', array_fill(0, count($scope), '?')) . ")";
                $scopeT = str_repeat('i', count($scope));
                $scopeA = $scope;
                $scM    = ormsMarksScopeSql();                       // one ? each time it appears
                $scMT   = $scM ? 'i' : '';
                $scMA   = $scM ? [sid()] : [];

                // 1. toppers — the stored, published rankings; top 3 per section (ties included).
                // withheld rides along so nobody prints a merit list naming a card that is being held back
                $whSel = resHasCol('result_summaries', 'is_withheld') ? "rs.is_withheld" : "0 AS is_withheld";
                $toppers = qAll("SELECT rs.`position`, rs.percentage, rs.grade, rs.gpa, rs.result_status, rs.section_total, $whSel,
                                        u.full_name, u.username, st.roll_no, st.admission_no,
                                        c.name AS class_name, sec.name AS section_name
                                 FROM result_summaries rs
                                 JOIN students st  ON st.id = rs.student_id
                                 JOIN users u      ON u.id = st.user_id
                                 JOIN sections sec ON sec.id = rs.section_id
                                 JOIN classes c    ON c.id = sec.class_id
                                 WHERE rs.term_id = ? AND rs.`position` <= 3" . $in('rs.section_id') . "
                                 ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, rs.`position` ASC, u.full_name ASC",
                                'i' . $scopeT, $termId, ...$scopeA);

                // 2. subject toppers — highest % per (class, subject) this term, every tie listed
                $best = qAll("SELECT b.class_name, b.subject_name, b.best_pct,
                                     u.full_name, u.username, st.roll_no, sec.name AS section_name
                              FROM (SELECT m.class_id, m.subject_id, c.name AS class_name, sub.name AS subject_name,
                                           MAX(ROUND(m.marks_obtained / m.total_marks * 100, 2)) AS best_pct
                                    FROM marks m
                                    JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                                    JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                                    JOIN classes c    ON c.id = m.class_id
                                    JOIN subjects sub ON sub.id = m.subject_id
                                    WHERE m.term_id = ? AND m.marks_obtained IS NOT NULL AND m.total_marks > 0"
                                        . $in('m.section_id') . ormsElectiveSql() . $scM . "
                                    GROUP BY m.class_id, m.subject_id, c.name, sub.name) b
                              JOIN marks m ON m.class_id = b.class_id AND m.subject_id = b.subject_id AND m.term_id = ?
                                          AND m.marks_obtained IS NOT NULL AND m.total_marks > 0
                                          AND ROUND(m.marks_obtained / m.total_marks * 100, 2) = b.best_pct
                              JOIN students st  ON st.id = m.student_id AND st.status = 'Active' AND st.section_id = m.section_id
                              JOIN users u      ON u.id = st.user_id
                              JOIN sections sec ON sec.id = m.section_id
                              WHERE 1 = 1" . $in('m.section_id') . $scM . "
                              ORDER BY b.class_name ASC, b.subject_name ASC, u.full_name ASC",
                             'i' . $scopeT . $scMT . 'i' . $scopeT . $scMT,   // inner: term, scope, school | outer: term, scope, school
                             ...array_merge([$termId], $scopeA, $scMA, [$termId], $scopeA, $scMA));

                // 3. teacher averages — avg % + pass rate per assignment, one aggregated subquery
                $tAvg = qAll("SELECT u.full_name AS teacher_name, c.name AS class_name, sec.name AS section_name,
                                     sub.name AS subject_name, COALESCE(agg.n, 0) AS n, agg.avg_pct, agg.pass_pct
                              FROM teacher_subjects ts
                              JOIN teachers t   ON t.id = ts.teacher_id
                              JOIN users u      ON u.id = t.user_id
                              JOIN classes c    ON c.id = ts.class_id
                              JOIN sections sec ON sec.id = ts.section_id
                              JOIN subjects sub ON sub.id = ts.subject_id
                              LEFT JOIN (SELECT m.section_id, m.subject_id, COUNT(*) AS n,
                                                ROUND(AVG(COALESCE(m.marks_obtained, 0) / m.total_marks * 100), 2) AS avg_pct,
                                                ROUND(SUM(m.is_absent = 0 AND m.marks_obtained >= COALESCE(m.passing_marks, 0)) / COUNT(*) * 100, 1) AS pass_pct
                                         FROM marks m
                                         JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                                         JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                                         WHERE m.term_id = ? AND m.total_marks > 0
                                           AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . $scM . "
                                         GROUP BY m.section_id, m.subject_id) agg
                                ON agg.section_id = ts.section_id AND agg.subject_id = ts.subject_id
                              WHERE ts.academic_year_id = ?" . $in('ts.section_id') . "
                              ORDER BY u.full_name ASC, c.sort_order ASC, sec.name ASC, sub.name ASC",
                             'i' . $scMT . 'i' . $scopeT,             // agg subquery runs first: term, school, then year, scope
                             ...array_merge([$termId], $scMA, [$yearId], $scopeA));

                // 4. year-over-year — published average % per year per class
                $yoy = qAll("SELECT ay.name AS year_name, c.name AS class_name,
                                    ROUND(AVG(rs.percentage), 2) AS avg_pct, COUNT(*) AS n
                             FROM result_summaries rs
                             JOIN academic_years ay ON ay.id = rs.academic_year_id
                             JOIN sections sec ON sec.id = rs.section_id
                             JOIN classes c    ON c.id = sec.class_id
                             WHERE 1 = 1" . $in('rs.section_id') . "
                             GROUP BY ay.id, ay.name, ay.start_date, c.id, c.name, c.sort_order
                             ORDER BY ay.start_date ASC, ay.id ASC, c.sort_order ASC, c.name ASC",
                            $scopeT, ...$scopeA);

                jsonOk(['term' => $term['name'], 'toppers' => $toppers, 'subject_best' => $best,
                        'teacher_avg' => $tAvg, 'yoy' => $yoy]);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('Results.php error: ' . $e->getMessage());
        jsonErr('Could not complete that request');     // real reason stays in the log, never on the wire
    }
}

$years    = ormsYears();
$curYear  = ormsCurrentYear();
$yearId   = $curYear ? (int)$curYear['id'] : ($years ? (int)$years[0]['id'] : 0);
$terms    = $yearId ? ormsTerms($yearId) : [];
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
    <title>Results &amp; Publishing - Online Result Management</title>

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
                    <h1><i class="fas fa-award"></i> Results &amp; Publishing</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Results</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="data-section no-print">
                <div class="tab-nav no-print" id="resTabs">
                    <button type="button" class="tab-btn active" data-tab="sections"><i class="fas fa-list-check"></i> Sections</button>
                    <button type="button" class="tab-btn" data-tab="approvals"><i class="fas fa-stamp"></i> Approvals</button>
                    <button type="button" class="tab-btn" data-tab="tabulation"><i class="fas fa-table-list"></i> Tabulation Sheet</button>
                    <button type="button" class="tab-btn" data-tab="merit"><i class="fas fa-trophy"></i> Merit &amp; Analytics</button>
                </div>

            <!-- ============ TAB 1: SECTIONS ============ -->
            <div class="tab-pane active" id="pane-sections">
                <div class="section-header">
                    <h2><i class="fas fa-list-check"></i> Marks Entry Completion</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" id="btnRefresh" onclick="loadSections()"><i class="fas fa-sync"></i> Refresh</button>
                    </div>
                </div>

                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Filters</h3>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-days"></i> Academic Year</label>
                            <select id="filterYear" class="filter-input">
                                <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int)$y['id']; ?>" <?php echo (int)$y['id'] === $yearId ? 'selected' : ''; ?>><?php echo htmlspecialchars($y['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-file-pen"></i> Exam Term</label>
                            <select id="filterTerm" class="filter-input">
                                <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name'] . ' (' . $t['status'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- class + section are filled from the loaded rows, so they can never offer
                             a section this user is not scoped to -->
                        <div class="filter-group">
                            <label><i class="fas fa-school"></i> Class</label>
                            <select id="filterClass" class="filter-input">
                                <option value="">All Classes</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-users-rectangle"></i> Section</label>
                            <select id="filterSection" class="filter-input">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="chev-pipeline" id="chevPipeline"></div>

                <div id="secSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 7; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="secWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="secTable" class="display table-full-width"></table>
                    </div>
                </div>

                <div id="secEmpty" class="orms-empty initially-hidden">
                    <i class="fas fa-inbox"></i>
                    <h4>No sections to show</h4>
                    <p>Pick an exam term above<?php echo $isWide ? '' : ', or ask the admin to assign you a subject'; ?>.</p>
                </div>
            </div>

            <!-- ============ TAB 2: APPROVALS ============ -->
            <div class="tab-pane" id="pane-approvals">
                <div class="section-header">
                    <h2><i class="fas fa-stamp"></i> Result Approvals <span class="myr-sub" id="apprLabel"></span></h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-primary" onclick="loadSections()"><i class="fas fa-sync"></i> Refresh</button>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>A section goes <b>Draft &rarr; Pending &rarr; Approved</b> before it can be published. The class teacher submits it once marks entry hits 100%, the <?php echo htmlspecialchars($headLabel); ?> approves it or sends it back with a note. Unpublishing a result sends it back to Draft.</span>
                </div>

                <div class="info-banner info-banner-warning mb-24 initially-hidden" id="apprOff">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Sign-off is switched <b>off</b> in Result Settings &mdash; sections can be published straight from the Sections tab. Anything submitted here is still tracked.</span>
                </div>

                <div class="chev-pipeline" id="apprPipeline"></div>

                <div id="apprEmpty" class="orms-empty">
                    <i class="fas fa-stamp"></i>
                    <h4>Nothing to review</h4>
                    <p>Pick an exam term on the Sections tab to see what is waiting for sign-off.</p>
                </div>

                <div id="apprWrap" class="initially-hidden">
                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                    <div class="table-responsive">
                        <table id="apprTable" class="display table-full-width"></table>
                    </div>
                </div>
            </div>

            <!-- ============ TAB 3: TABULATION SHEET ============ -->
            <div class="tab-pane" id="pane-tabulation">
                <div class="section-header">
                    <h2><i class="fas fa-table-list"></i> Tabulation Sheet <span class="myr-sub" id="tabLabel"></span></h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-secondary initially-hidden" id="tabClose" onclick="closeTabulation()"><i class="fas fa-arrow-left"></i> Back to Sections</button>
                    </div>
                </div>

                <div id="tabEmpty" class="orms-empty">
                    <i class="fas fa-table-list"></i>
                    <h4>No sheet open</h4>
                    <p>Open a section&rsquo;s tabulation sheet from the Sections tab to see every student and subject side by side.</p>
                </div>

                <div id="tabCard" class="initially-hidden">
                    <div class="help-text" id="tabRules"></div>

                    <div class="form-group initially-hidden" id="tabCaWrap">
                        <label><i class="fas fa-percent"></i> Show CA &amp; Exam split</label>
                        <div class="toggle-switch">
                            <input type="checkbox" id="tabShowCa" class="toggle-input">
                            <label for="tabShowCa" class="toggle-label"><span class="toggle-slider"></span></label>
                        </div>
                        <div class="help-text"><i class="fas fa-circle-info"></i> Off by default. Switch it on to see the continuous-assessment and exam halves under each subject mark.</div>
                    </div>

                    <div class="table-scroll-hint"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all subjects</div>
                    <div class="table-responsive">
                        <table id="tabTable" class="display table-full-width"></table>
                    </div>
                </div>
            </div>

            <!-- ============ TAB 4: MERIT & ANALYTICS ============ -->
            <div class="tab-pane" id="pane-merit">
                <div class="section-header">
                    <h2><i class="fas fa-trophy"></i> Merit &amp; Analytics <span class="myr-sub" id="meritLabel"></span></h2>
                    <div class="btn-group-inline">
                        <button type="button" class="btn btn-primary" onclick="loadMerit(this)"><i class="fas fa-sync"></i> Refresh</button>
                    </div>
                </div>

                <div class="info-banner info-banner-top mb-24">
                    <i class="fas fa-circle-info"></i>
                    <span>Built from the exam term selected on the <b>Sections</b> tab. Toppers and the year trend use <b>published</b> results only; subject bests and teacher averages read the entered marks. A student whose card is being <b>withheld</b> for outstanding fees is flagged in the toppers list &mdash; check before printing a merit list.</span>
                </div>

                <div id="meritEmpty" class="orms-empty">
                    <i class="fas fa-trophy"></i>
                    <h4>Nothing to rank yet</h4>
                    <p>Publish at least one section of this term to see toppers, subject bests and teacher performance.</p>
                </div>

                <div id="meritBody" class="initially-hidden">
                    <div class="section-header">
                        <h2><i class="fas fa-ranking-star"></i> Toppers — Top 3 per Section</h2>
                    </div>
                    <div class="table-responsive">
                        <table id="topTable" class="display table-full-width"></table>
                    </div>

                    <div class="section-header mt-20">
                        <h2><i class="fas fa-book"></i> Subject Toppers</h2>
                    </div>
                    <div id="subjBest"></div>

                    <div class="section-header mt-20">
                        <h2><i class="fas fa-chalkboard-teacher"></i> Teacher Performance</h2>
                    </div>
                    <div id="teachAvg"></div>

                    <div class="section-header mt-20">
                        <h2><i class="fas fa-chart-line"></i> Year-over-Year Average</h2>
                    </div>
                    <div class="merit-chart" id="yoyWrap"><canvas id="yoyChart"></canvas></div>
                </div>
            </div>

            </div><!-- /tabbed data-section -->
        </div>
    </div>

    <!-- Unpublish (admin only) -->
    <div class="modal-overlay" id="unpubModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-lock-open"></i> Unpublish Result</h3>
                <button class="close-btn" onclick="closeUnpub()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="unpubForm">
                    <input type="hidden" id="unpubSection">
                    <div class="form-group">
                        <label><i class="fas fa-triangle-exclamation"></i> Section</label>
                        <div class="help-text" id="unpubWhat"></div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-comment-dots"></i> Reason *</label>
                        <textarea id="unpubReason" rows="3" required placeholder="e.g. Mathematics marks of two students were entered against the wrong roll number"></textarea>
                        <div class="help-text"><i class="fas fa-circle-info"></i> Unpublishing deletes this section's saved result summaries (totals, grades, GPA and positions), hides the cards from students and <strong>reopens marks entry</strong> for the term. Positions are recalculated when you publish again. The reason is written to the activity log.</div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger" id="unpubBtn"><i class="fas fa-lock-open"></i> Unpublish</button>
                        <button type="button" class="btn btn-secondary" onclick="closeUnpub()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Written remarks — one modal, two kinds (class teacher / head). Same gate as publishing -->
    <?php if ($canPublish): ?>
    <div class="modal-overlay" id="remModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="remTitle"><i class="fas fa-comment-dots"></i> Class Teacher&rsquo;s Remarks</h3>
                <button class="close-btn" onclick="closeRemarks()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="remForm">
                    <input type="hidden" id="remStudent">
                    <div class="form-group">
                        <label><i class="fas fa-user-graduate"></i> Student</label>
                        <div class="help-text" id="remWho"></div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-pen"></i> Remarks</label>
                        <textarea id="remText" rows="3" maxlength="255" placeholder="e.g. Consistent improvement in Mathematics — keep it up"></textarea>
                        <div class="help-text"><i class="fas fa-circle-info"></i> <span id="remHelp">Printed on the result card under the summary.</span> Editing this never recalculates totals, grades, GPA or positions. Leave it empty to remove the remark. Max 255 characters.</div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="remBtn"><i class="fas fa-save"></i> Save Remarks</button>
                        <button type="button" class="btn btn-secondary" onclick="closeRemarks()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- bulk print target: hidden on screen, the only thing that prints -->
    <div id="bulkPrintArea" class="print-only"></div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>window.ORMS_CSRF = '<?= csrfToken() ?>';</script>
    <script>
    var IS_WIDE = <?php echo $isWide ? 'true' : 'false'; ?>;
    var CAN_PUBLISH = <?php echo $canPublish ? 'true' : 'false'; ?>;
    var APPR_GATE = <?php echo $apprGate ? 'true' : 'false'; ?>;
    var HEAD = <?php echo json_encode($headLabel); ?>;
    var allRows = [], chev = 'all', secTable = null, tabTable = null, tabRows = [];
    var apprChev = 'all', apprTable = null;

    var CHEVS = [
        ['all',         'All',         'fa-layer-group'],
        ['Not Started', 'Not Started', 'fa-circle-notch'],
        ['In Progress', 'In Progress', 'fa-hourglass-half'],
        ['Complete',    'Complete',    'fa-circle-check'],
        ['Published',   'Published',   'fa-bullhorn']
    ];

    // sign-off states — same chevron pattern, second dimension
    var APPR_CHEVS = [
        ['all',      'All',       'fa-layer-group'],
        ['Draft',    'Draft',     'fa-pen-ruler'],
        ['Pending',  'Pending',   'fa-hourglass-half'],
        ['Approved', 'Approved',  'fa-circle-check'],
        ['Rejected', 'Sent Back', 'fa-rotate-left']
    ];

    var APPR_META = {
        Draft:    ['appr-draft',    'fa-pen-ruler',       'Draft'],
        Pending:  ['appr-pending',  'fa-hourglass-half',  'Awaiting Approval'],
        Approved: ['appr-approved', 'fa-circle-check',    'Approved'],
        Rejected: ['appr-rejected', 'fa-rotate-left',     'Sent Back']
    };

    function apprChip(s) {
        var m = APPR_META[s] || APPR_META.Draft;
        return '<span class="appr-badge ' + m[0] + '"><i class="fas ' + m[1] + '"></i> ' + ORMS.esc(m[2]) + '</span>';
    }

    // pdf/excel libs pulled only when an export is clicked
    function loadExportDeps(callback) {
        if (window.pdfMake) { callback(); return; }
        var urls = ['https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
                    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js',
                    'https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js'];
        var loaded = 0;
        (function loadNext() {
            if (loaded >= urls.length) { callback(); return; }
            var s = document.createElement('script');
            s.src = urls[loaded];
            s.onload = function () { loaded++; loadNext(); };
            document.head.appendChild(s);
        })();
    }

    // pipeline order, so sorting the column walks the workflow instead of the alphabet
    // (alphabetical put "Complete" before "In Progress" before "Not Started" — meaningless here)
    var STATUS_RANK = { 'Not Started': 1, 'In Progress': 2, 'Complete': 3, 'Published': 4 };
    function statusRank(r) { return (STATUS_RANK[r.status] || 0) * 10 + (r.is_published ? 1 : 0); }

    // publishing column walks the sign-off flow: draft -> sent back -> awaiting -> approved -> published
    var APPR_RANK = { Draft: 1, Rejected: 2, Pending: 3, Approved: 4 };
    function pubRank(r) { return r.is_published ? 9 : (APPR_RANK[r.appr] || 1); }

    // same icon the chevron chip uses, so the cell and the pipeline never disagree
    function statusIcon(s) {
        for (var i = 0; i < CHEVS.length; i++) if (CHEVS[i][0] === s) return CHEVS[i][2];
        return 'fa-circle-notch';
    }

    function statusClass(s) {
        return s === 'Published' ? 'status-active'
             : s === 'Complete'  ? 'status-user'
             : s === 'In Progress' ? 'status-current' : 'status-inactive';
    }

    function fillClass(r) {
        return r.is_published ? 'is-published' : (r.pct >= 100 ? 'is-complete' : (r.pct <= 0 ? 'is-none' : 'is-partial'));
    }

    // fee holds are the one publishing surprise nobody wants — say it on the row, not in a dialog only
    function heldChip(n, of) {
        return '<span class="fee-owing" title="These cards stay hidden from parents until the fees are cleared">' +
               '<i class="fas fa-hand-holding-dollar"></i> ' + n + (of ? ' of ' + of : '') + ' withheld</span>';
    }

    // which grading set / assessment scheme produced this section's numbers
    function rulesLine(row) {
        if (!row.gset) return '';
        return '<br><small class="text-muted"><i class="fas fa-scale-balanced"></i> ' + ORMS.esc(row.gset) +
               (row.scheme ? ' · <i class="fas fa-percent"></i> ' + ORMS.esc(row.scheme) : '') + '</small>';
    }

    function termId() { return parseInt($('#filterTerm').val() || 0, 10); }

    $(document).ready(function () {
        ORMS.dropdown('#filterYear');
        ORMS.dropdown('#filterTerm');
        $('#filterYear').on('change', loadTerms);
        $('#filterTerm').on('change', loadSections);
        loadSections();
    });

    function loadTerms() {
        ORMS.post('getTerms', { year_id: $('#filterYear').val() }).done(function (res) {
            var $t = $('#filterTerm').empty();
            (res.data || []).forEach(function (t) {
                $t.append($('<option>').val(t.id).text(t.name + ' (' + t.status + ')'));
            });
            ORMS.dropdown.refresh('#filterTerm');
            loadSections();
        });
    }

    function loadSections() {
        var tid = termId();
        if (!tid) { allRows = []; renderChev(); showEmpty('Create an exam term in Result Settings first.'); return; }

        ORMS.post('getSections', { term_id: tid }).done(function (res) {
            if (!res.success) { showEmpty(res.message || 'Failed to load sections'); ORMS.err(res.message || 'Failed to load sections'); return; }
            allRows = res.data || [];
            APPR_GATE = !!res.gate;                      // setting can change mid-session, trust the server
            $('#apprLabel').text('— ' + res.term);
            syncSecFilters();
            renderChev();
            if (!allRows.length) { showEmpty(IS_WIDE ? 'No active sections found for this term.' : 'You are not assigned to any section this year.'); return; }
            $('#secSkeleton').hide(); $('#secEmpty').hide(); $('#secWrap').show();
            initSecTable();
            renderAppr();                                // same rows drive the sign-off view — no second fetch
        }).fail(function (msg) { showEmpty('Could not reach the server.'); ORMS.err(msg); });
    }

    function showEmpty(msg) {
        $('#secSkeleton').hide(); $('#secWrap').hide();
        $('#secEmpty p').text(msg);
        $('#secEmpty').show();
        renderAppr();
        closeTabulation();
    }

    function renderChev() {
        var html = '';
        CHEVS.forEach(function (c) {
            var pool = scoped();
            var n = c[0] === 'all' ? pool.length : pool.filter(function (r) { return r.status === c[0]; }).length;
            html += '<button type="button" class="chev-item' + (chev === c[0] ? ' active' : '') + '" onclick="setChev(\'' + c[0] + '\')">' +
                    '<span class="chev-label"><i class="fas ' + c[2] + '"></i> ' + ORMS.esc(c[1]) + '</span>' +
                    '<span class="chev-count">' + n + '</span></button>';
        });
        $('#chevPipeline').html(html);
    }

    function setChev(s) {
        chev = s;
        renderChev();
        if (secTable) secTable.clear().rows.add(filtered()).draw();
    }

    // rows after the Class/Section dropdowns but BEFORE the chevron — the pipeline counts run off
    // this too, otherwise the chips would advertise sections the filters have already hidden
    function scoped() {
        var c = parseInt($('#filterClass').val(), 10) || 0,
            x = parseInt($('#filterSection').val(), 10) || 0;
        if (!c && !x) return allRows;
        return allRows.filter(function (r) {
            if (c && parseInt(r.class_id, 10) !== c) return false;
            if (x && parseInt(r.section_id, 10) !== x) return false;
            return true;
        });
    }

    function filtered() {
        var rows = scoped();
        return chev === 'all' ? rows : rows.filter(function (r) { return r.status === chev; });
    }

    // rebuild the two pickers from whatever the server actually returned
    function syncSecFilters() {
        var $c = $('#filterClass'), $x = $('#filterSection');
        var keepC = $c.val() || '', keepX = $x.val() || '';
        var seen = {}, cls = [];
        allRows.forEach(function (r) {
            if (r.class_id && !seen[r.class_id]) { seen[r.class_id] = 1; cls.push([r.class_id, r.class_name]); }
        });
        $c.html('<option value="">All Classes</option>' + cls.map(function (c) {
            return '<option value="' + c[0] + '">' + ORMS.esc(c[1]) + '</option>';
        }).join(''));
        if (keepC && seen[keepC]) $c.val(keepC); else keepC = '';

        var secs = allRows.filter(function (r) { return !keepC || String(r.class_id) === String(keepC); });
        $x.html('<option value="">All Sections</option>' + secs.map(function (r) {
            return '<option value="' + r.section_id + '">' + ORMS.esc(r.label) + '</option>';
        }).join(''));
        if (keepX && secs.some(function (r) { return String(r.section_id) === String(keepX); })) $x.val(keepX);

        ORMS.dropdown('#filterClass, #filterSection');
        ORMS.dropdown.refresh('#filterClass, #filterSection');
    }

    function applySecFilters() {
        renderChev();                                   // counts follow the narrowed set
        if (secTable) secTable.clear().rows.add(filtered()).draw();
        renderAppr();
    }

    $(function () {
        $('#filterClass').on('change', function () { syncSecFilters(); applySecFilters(); });
        $('#filterSection').on('change', applySecFilters);
    });

    function initSecTable() {
        if (secTable) { secTable.destroy(); $('#secTable').empty(); secTable = null; }
        secTable = $('#secTable').DataTable({
            data: filtered(),
            destroy: true,
            columns: [
                { data: 'label', title: 'Class – Section', render: function (d, t, row) {
                    return t === 'display' ? '<strong>' + ORMS.esc(d) + '</strong>' + rulesLine(row) : d;
                } },
                { data: 'students', title: 'Students' },
                { data: 'subjects', title: 'Subjects' },
                { data: 'entered', title: 'Entered', render: function (d, t, row) { return t === 'display' ? d + ' / ' + row.expected : d; } },
                { data: 'pct', title: 'Completion', render: function (d, t, row) {
                    if (t !== 'display') return d;
                    var c = fillClass(row);
                    return '<div class="completion-wrap ' + c + '"><div class="completion-bar">' +
                           '<span class="completion-fill" style="width:' + d + '%"></span></div>' +
                           '<span class="completion-pct ' + c + '">' + d + '%</span></div>';
                } },
                { data: 'status', title: 'Status', className: 'st-cell', render: function (d, t, row) {
                    // sort walks the pipeline; the badge word alone is the search text — everything
                    // else (draft/withheld/dates) lives in the Publishing column now
                    if (t === 'sort' || t === 'type') return statusRank(row);
                    if (t !== 'display') return d;
                    return '<span class="status-badge st-main ' + statusClass(d) + '">' +
                           '<i class="fas ' + statusIcon(d) + '"></i> ' + ORMS.esc(d) + '</span>';
                } },
                { data: null, title: 'Publishing', className: 'st-cell', render: function (d, t, row) {
                    if (t === 'sort' || t === 'type') return pubRank(row);
                    // sign-off state sits where publishing happens — hidden on installs that never use it
                    var showAppr = !row.is_published && (APPR_GATE || row.appr !== 'Draft');
                    // the search text must mirror exactly what the cell shows — otherwise typing
                    // "draft" matches a published section whose Draft chip is not even rendered
                    if (t !== 'display') {
                        return [showAppr ? (APPR_META[row.appr] || APPR_META.Draft)[2] : '',
                                row.withheld > 0 ? 'withheld' : '',
                                (row.is_published && row.published_at) ? 'published ' + row.published_at : '',
                                row.unpub_at ? 'unpublished ' + row.unpub_at + ' ' + (row.unpub_by || '') : '',
                                row.unpub_why || ''].join(' ').replace(/\s+/g, ' ').trim();
                    }

                    var h = '<div class="st-stack">';
                    if (showAppr) h += apprChip(row.appr);
                    // fee holds land next to the publish decision, not buried in the dialog
                    if (row.withheld > 0) h += heldChip(row.withheld, row.students);
                    if (row.is_published && row.published_at) {
                        h += '<span class="st-meta"><i class="fas fa-calendar-check"></i> ' + ORMS.esc(row.published_at) + '</span>';
                    }
                    if (row.unpub_at) {          // pulled back at some point — say who, when and why
                        h += '<span class="st-meta"><i class="fas fa-lock-open"></i> Unpublished ' + ORMS.esc(row.unpub_at) +
                             (row.unpub_by ? ' by ' + ORMS.esc(row.unpub_by) : '') + '</span>';
                        if (row.unpub_why) h += '<span class="st-meta st-why" title="' + ORMS.esc(row.unpub_why) + '">Reason: ' + ORMS.esc(row.unpub_why) + '</span>';
                    }
                    h += '</div>';
                    return h === '<div class="st-stack"></div>' ? '<span class="text-muted">&mdash;</span>' : h;
                } },
                { data: null, title: 'Actions', orderable: false, render: function (d, t, row) {
                    var b = '<button class="btn btn-secondary btn-sm" onclick="showTabulation(' + row.section_id + ')"><i class="fas fa-table-list"></i> Tabulation</button> ';
                    if (row.is_published) {
                        b += '<button class="btn btn-primary btn-sm" onclick="bulkPrint(' + row.section_id + ', this)"><i class="fas fa-print"></i> Print Cards</button> ';
                        if (IS_WIDE) b += '<button class="btn btn-danger btn-sm" onclick="openUnpub(' + row.section_id + ')"><i class="fas fa-lock-open"></i> Unpublish</button>';
                    } else if (IS_WIDE) {
                        var why = blockReason(row);
                        b += '<button class="btn btn-success btn-sm" onclick="publishSection(' + row.section_id + ', this)"' +
                             (why ? ' disabled title="' + ORMS.esc(why) + '"' : '') + '><i class="fas fa-bullhorn"></i> Publish</button>';
                    } else if (canSubmit(row)) {           // teacher's own action lands where they work
                        b += submitBtn(row);
                    }
                    return b;
                } }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [                                    // actions column is last -> always excluded
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Completion Overview', exportOptions: { columns: ':not(:last-child)' } },
                { text: '<i class="fas fa-file-pdf"></i> PDF', title: 'Completion Overview', exportOptions: { columns: ':not(:last-child)' },
                  action: function (e, dt, node, config) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Completion Overview', exportOptions: { columns: ':not(:last-child)' } }
            ],
            order: [[0, 'asc']]
        });
    }

    function rowOf(sectionId) {
        return allRows.filter(function (r) { return r.section_id === sectionId; })[0] || {};
    }

    function publishSection(sectionId, btn) {
        var row = rowOf(sectionId);
        // a warning, never a gate — publishing goes ahead, the held cards just stay hidden
        var warn = row.withheld > 0
            ? '<br><br><span class="fee-owing"><i class="fas fa-hand-holding-dollar"></i> <strong>' + row.withheld + ' of ' + row.students +
              ' students will be withheld for outstanding fees</strong> — their cards will not be visible to parents until cleared.</span>' +
              '<br>Publishing still goes ahead for the whole section.'
            : '';
        Swal.fire({
            icon: 'question',
            title: 'Publish ' + row.label + '?',
            html: 'Totals, grades, GPA and positions will be generated and every student of this section gets a notification.<br><br>Marks entry for this term stays locked until you unpublish.' + warn,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-bullhorn"></i> Publish',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ORMS.post('publish', { term_id: termId(), section_id: sectionId }, { btn: btn, busyLabel: 'Publishing…' })
                .done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    loadSections();
                })
                .fail(function (msg) { ORMS.err(msg); });
        });
    }

    // ---------- sign-off workflow ----------

    function canSubmit(row) { return !row.is_published && (row.appr === 'Draft' || row.appr === 'Rejected'); }

    // '' = publishable. anything else is the tooltip explaining why the button is dead
    function blockReason(row) {
        if (!(row.pct >= 100 && row.expected > 0)) return 'Publishing needs 100% marks entry';
        return (APPR_GATE && row.appr !== 'Approved') ? 'Needs ' + HEAD + ' approval first' : '';
    }

    function submitBtn(row) {
        var ready = row.pct >= 100 && row.expected > 0;
        return '<button class="btn btn-primary btn-sm" onclick="submitApproval(' + row.section_id + ', this)"' +
               (ready ? '' : ' disabled title="Marks entry must be 100% complete"') +
               '><i class="fas fa-paper-plane"></i> Submit for Approval</button> ';
    }

    function apprActions(row) {
        if (row.is_published) {
            return CAN_PUBLISH
                ? '<button class="btn btn-danger btn-sm" onclick="openUnpub(' + row.section_id + ')"><i class="fas fa-lock-open"></i> Unpublish</button>'
                : '<span class="text-muted">—</span>';
        }
        var b = canSubmit(row) ? submitBtn(row) : '';
        if (row.appr === 'Pending' && IS_WIDE) {
            b += '<button class="btn btn-success btn-sm" onclick="approveSection(' + row.section_id + ', this)"><i class="fas fa-stamp"></i> Approve</button> ' +
                 '<button class="btn btn-danger btn-sm" onclick="rejectSection(' + row.section_id + ', this)"><i class="fas fa-rotate-left"></i> Send Back</button> ';
        }
        if (row.appr === 'Approved' && CAN_PUBLISH) {
            var why = blockReason(row);
            b += '<button class="btn btn-success btn-sm" onclick="publishSection(' + row.section_id + ', this)"' +
                 (why ? ' disabled title="' + ORMS.esc(why) + '"' : '') + '><i class="fas fa-bullhorn"></i> Publish</button>';
        }
        return b || '<span class="text-muted">Waiting for the ' + ORMS.esc(HEAD) + '</span>';
    }

    // the Class/Section pickers sit in the page-level filter block, so they narrow the sign-off
    // list too — otherwise the filters would silently lie on this tab
    function apprFiltered() {
        var rows = scoped();
        return apprChev === 'all' ? rows : rows.filter(function (r) { return r.appr === apprChev; });
    }

    function renderApprChev() {
        var html = '';
        var pool = scoped();
        APPR_CHEVS.forEach(function (c) {
            var n = c[0] === 'all' ? pool.length : pool.filter(function (r) { return r.appr === c[0]; }).length;
            html += '<button type="button" class="chev-item' + (apprChev === c[0] ? ' active' : '') + '" onclick="setApprChev(\'' + c[0] + '\')">' +
                    '<span class="chev-label"><i class="fas ' + c[2] + '"></i> ' + ORMS.esc(c[1]) + '</span>' +
                    '<span class="chev-count">' + n + '</span></button>';
        });
        $('#apprPipeline').html(html);
    }

    function setApprChev(s) {
        apprChev = s;
        renderApprChev();
        if (apprTable) apprTable.clear().rows.add(apprFiltered()).draw();
    }

    // fed by the same getSections rows the Sections tab uses — one read, two views, never out of sync
    function renderAppr() {
        $('#apprOff').toggleClass('initially-hidden', APPR_GATE);
        renderApprChev();
        if (!scoped().length) {
            if (apprTable) { apprTable.destroy(); $('#apprTable').empty(); apprTable = null; }
            $('#apprWrap').addClass('initially-hidden');
            $('#apprEmpty').removeClass('initially-hidden');
            return;
        }
        $('#apprEmpty').addClass('initially-hidden');
        $('#apprWrap').removeClass('initially-hidden');
        initApprTable();
    }

    function whoWhen(who, when) {
        if (!who && !when) return '<span class="text-muted">—</span>';
        return '<strong>' + ORMS.esc(who || '—') + '</strong>' + (when ? '<br><small class="text-muted">' + ORMS.esc(when) + '</small>' : '');
    }

    function initApprTable() {
        if (apprTable) { apprTable.destroy(); $('#apprTable').empty(); apprTable = null; }
        apprTable = $('#apprTable').DataTable({
            data: apprFiltered(),
            destroy: true,
            columns: [
                { data: 'label', title: 'Class – Section', render: function (d) { return '<strong>' + ORMS.esc(d) + '</strong>'; } },
                { data: 'pct', title: 'Marks Entry', render: function (d, t, row) {
                    return t === 'display' ? ORMS.esc(row.entered + ' / ' + row.expected) + ' <small class="text-muted">(' + d + '%)</small>' : d;
                } },
                { data: 'appr', title: 'Approval', render: function (d, t, row) {
                    if (t !== 'display') return d;
                    return apprChip(d) +
                           (row.is_published ? ' <span class="status-badge status-active">Published</span>' : '') +
                           (row.note ? '<br><small class="appr-note"><i class="fas fa-circle-exclamation"></i> ' + ORMS.esc(row.note) + '</small>' : '');
                } },
                { data: 'sub_at', title: 'Submitted', render: function (d, t, row) { return t === 'display' ? whoWhen(row.sub_by, d) : (d || ''); } },
                { data: 'app_at', title: 'Approved',  render: function (d, t, row) { return t === 'display' ? whoWhen(row.app_by, d) : (d || ''); } },
                { data: null, title: 'Actions', orderable: false, render: function (d, t, row) { return apprActions(row); } }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [                                    // actions column is last -> always excluded
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Result Approvals', exportOptions: { columns: ':not(:last-child)' } },
                { text: '<i class="fas fa-file-pdf"></i> PDF', title: 'Result Approvals', exportOptions: { columns: ':not(:last-child)' },
                  action: function (e, dt, node, config) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Result Approvals', exportOptions: { columns: ':not(:last-child)' } }
            ],
            order: [[0, 'asc']]
        });
    }

    function submitApproval(sectionId, btn) {
        var row = rowOf(sectionId);
        Swal.fire({
            icon: 'question',
            title: 'Send ' + row.label + ' for approval?',
            html: 'The ' + ORMS.esc(HEAD) + ' is notified and can approve it or send it back with a note.<br><br>Marks entry must already be 100% complete.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-paper-plane"></i> Submit',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ORMS.post('submitApproval', { term_id: termId(), section_id: sectionId }, { btn: btn, busyLabel: 'Submitting…' })
                .done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    loadSections();
                })
                .fail(function (msg) { ORMS.err(msg); });
        });
    }

    function approveSection(sectionId, btn) {
        var row = rowOf(sectionId);
        Swal.fire({
            icon: 'question',
            title: 'Approve ' + row.label + '?',
            html: 'Approving clears this section for publishing. The teacher who submitted it is notified.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-stamp"></i> Approve',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ORMS.post('approveSection', { term_id: termId(), section_id: sectionId }, { btn: btn, busyLabel: 'Approving…' })
                .done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    loadSections();
                })
                .fail(function (msg) { ORMS.err(msg); });
        });
    }

    // reason is mandatory and travels back to the submitter, same as an unpublish reason
    function rejectSection(sectionId, btn) {
        var row = rowOf(sectionId);
        Swal.fire({
            icon: 'warning',
            title: 'Send ' + row.label + ' back?',
            input: 'textarea',
            inputLabel: 'What needs fixing?',
            inputPlaceholder: 'e.g. Mathematics marks of two students are against the wrong roll number',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Send Back',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            inputValidator: function (v) { return $.trim(v || '') ? undefined : 'Please state what needs fixing'; }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ORMS.post('rejectSection', { term_id: termId(), section_id: sectionId, note: $.trim(r.value) },
                      { btn: btn, busyLabel: 'Sending back…' })
                .done(function (res) {
                    if (!res.success) { ORMS.err(res.message); return; }
                    ORMS.ok(res.message);
                    loadSections();
                })
                .fail(function (msg) { ORMS.err(msg); });
        });
    }

    function openUnpub(sectionId) {
        var row = rowOf(sectionId);
        $('#unpubSection').val(sectionId);
        $('#unpubWhat').text(row.label + ' — published ' + (row.published_at || ''));
        $('#unpubReason').val('');
        $('#unpubModal').addClass('active');
    }

    function closeUnpub() { $('#unpubModal').removeClass('active'); }
    $('#unpubModal').on('click', function (e) { if (e.target === this) closeUnpub(); });

    $('#unpubForm').on('submit', function (e) {
        e.preventDefault();
        var reason = $.trim($('#unpubReason').val());
        if (!reason) { ORMS.err('Please state a reason'); return; }
        var sectionId = parseInt($('#unpubSection').val(), 10);

        ORMS.confirmDelete('This deletes the saved result summaries for this section and reopens marks entry.', 'Unpublish this result?')
            .then(function (yes) {
                if (!yes) return;
                ORMS.post('unpublish', { term_id: termId(), section_id: sectionId, reason: reason },
                          { btn: '#unpubBtn', busyLabel: 'Unpublishing…' })
                    .done(function (res) {
                        if (!res.success) { ORMS.err(res.message); return; }
                        closeUnpub();
                        ORMS.ok(res.message);
                        loadSections();
                    })
                    .fail(function (msg) { ORMS.err(msg); });
            });
    });

    // one column builder, two kinds of comment — the modal decides which field it writes
    function remCol(title, key, kind, icon) {
        return { data: null, title: title, orderable: false, render: function (d, t, row) {
            if (t !== 'display') return row[key] || '';
            var has = (row[key] || '') !== '';
            return '<button class="btn btn-secondary btn-sm" onclick="openRemarks(' + row.student_id + ', \'' + kind + '\')">' +
                   '<i class="fas ' + icon + '"></i> ' + (has ? 'Edit' : 'Add') + '</button>' +
                   (has ? '<br><small class="text-muted">' + ORMS.esc(row[key]) + '</small>' : '');
        } };
    }

    // ca/exam halves sit under the subject mark, off by default so the sheet stays readable
    function caSplit(c) {
        if (!c || c.ca === null || c.ca === undefined || !$('#tabShowCa').is(':checked')) return '';
        return '<br><small class="rc-ca-col">CA ' + c.ca + ' · Ex ' + (c.ex === null || c.ex === undefined ? '—' : c.ex) + '</small>';
    }

    $(document).on('change', '#tabShowCa', function () {
        if (tabTable) tabTable.rows().invalidate('data').draw(false);   // redraw only, the rows are already here
    });

    function showTabulation(sectionId) {
        ORMS.post('getTabulation', { term_id: termId(), section_id: sectionId }).done(function (res) {
            if (!res.success) { ORMS.err(res.message); return; }
            $('#tabLabel').text('— ' + res.label + ' · ' + res.term);
            $('#tabEmpty').addClass('initially-hidden');
            $('#tabCard, #tabClose').removeClass('initially-hidden');
            goTab('tabulation');                                  // sheet lives in its own tab now

            // say which rules produced these numbers, and only offer the split when there is one
            $('#tabRules').html(res.gset
                ? '<i class="fas fa-scale-balanced"></i> Graded on <strong>' + ORMS.esc(res.gset) + '</strong>' +
                  (res.scheme ? ' &middot; marked with <strong>' + ORMS.esc(res.scheme) + '</strong> (continuous assessment + exam)'
                              : ' &middot; one mark per subject, no components')
                : '');
            $('#tabCaWrap').toggleClass('initially-hidden', !res.scheme);
            if (!res.scheme) $('#tabShowCa').prop('checked', false);

            var cols = [
                { data: 'position', title: 'Pos' },
                { data: 'roll_no', title: 'Roll', render: function (d) { return ORMS.esc(d || '—'); } },
                { data: 'name', title: 'Student', render: function (d) { return '<strong>' + ORMS.esc(d) + '</strong>'; } }
            ];
            tabRows = res.rows || [];
            res.subjects.forEach(function (s) {
                cols.push({
                    data: null, title: s.name + ' /' + s.total + (s.counted ? '' : ' (not counted)'), orderable: true,
                    render: function (d, t, row) {
                        var c = row.cells[s.id];
                        if (!c || !c.e) return t === 'display' ? '<span class="text-muted">—</span>' : -1;
                        if (c.ab) return t === 'display' ? '<span class="rc-absent">AB</span>' : 0;
                        return t === 'display' ? c.v + caSplit(c) : c.v;
                    }
                });
            });
            cols.push({ data: 'total_obtained', title: 'Total', render: function (d, t, row) { return t === 'display' ? d + ' / ' + row.total_max : d; } });
            cols.push({ data: 'percentage', title: '%', render: function (d, t) { return t === 'display' ? d + '%' : d; } });
            cols.push({ data: 'grade', title: 'Grade' });
            cols.push({ data: 'gpa', title: 'GPA' });
            cols.push({ data: 'result_status', title: 'Result', render: function (d, t) {
                return t === 'display' ? '<span class="status-badge ' + (d === 'PASS' ? 'status-active' : 'status-inactive') + '">' + d + '</span>' : d;
            } });
            // attendance + withholding ride on the same rows the engine already built — no extra fetch per student
            if (res.att) cols.push({ data: null, title: 'Attendance', render: function (d, t, row) {
                var a = row.attendance;
                if (!a || !a.total) return t === 'display' ? '<span class="text-muted">—</span>' : -1;   // nothing recorded, never "0 / 0"
                var pct = (a.pct === undefined || a.pct === null) ? Math.round(a.present / a.total * 1000) / 10 : a.pct;
                return t === 'display'
                    ? '<span class="rc-att">' + a.present + ' / ' + a.total + '</span> <small class="rc-att-pct">' + pct + '%</small>'
                    : pct;
            } });
            if (res.fees) cols.push({ data: null, title: 'Withheld', render: function (d, t, row) {
                var w = row.withheld ? 1 : 0;
                if (t !== 'display') return w;
                if (!w) return '<span class="text-muted">—</span>';
                return '<span class="rc-withheld"><i class="fas fa-hand-holding-dollar"></i> Withheld</span>' +
                       (row.withheld_reason ? '<br><small class="text-muted">' + ORMS.esc(row.withheld_reason) + '</small>' : '');
            } });
            if (CAN_PUBLISH) {                                  // both written comments, editable after publish
                cols.push(remCol('Remarks', 'remarks', 'teacher', 'fa-comment-dots'));
                cols.push(remCol(HEAD + "'s Remarks", 'p_remarks', 'principal', 'fa-user-tie'));
            }

            if (tabTable) { tabTable.destroy(); $('#tabTable').empty(); tabTable = null; }
            tabTable = $('#tabTable').DataTable({
                data: tabRows,
                destroy: true,
                columns: cols,
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                responsive: true,
                dom: 'Blfrtip',
                buttons: [
                    { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Tabulation - ' + res.label },
                    { text: '<i class="fas fa-file-pdf"></i> PDF', title: 'Tabulation - ' + res.label,
                      action: function (e, dt, node, config) {
                          loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                      } },
                    { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Tabulation - ' + res.label }
                ],
                order: [[0, 'asc']]
            });
            document.getElementById('tabCard').scrollIntoView({ behavior: 'smooth' });
        }).fail(function (msg) { ORMS.err(msg); });
    }

    function closeTabulation() {
        $('#tabCard, #tabClose').addClass('initially-hidden');
        $('#tabEmpty').removeClass('initially-hidden');
        $('#tabLabel').text('');
        goTab('sections');
    }

    // tabs — dt measures columns to 0 inside a hidden pane, so re-measure whichever table just showed.
    // the whole swap rides one view transition, so panes crossfade instead of snapping
    function goTab(t) {
        ORMS.swap(function () {
            $('#resTabs .tab-btn').removeClass('active').filter('[data-tab="' + t + '"]').addClass('active');
            $('.tab-pane').removeClass('active');
            $('#pane-' + t).addClass('active');
            var dt = t === 'sections' ? secTable : (t === 'approvals' ? apprTable
                   : (t === 'tabulation' ? tabTable : (t === 'merit' ? topTable : null)));
            if (dt) { dt.columns.adjust(); if (dt.responsive) dt.responsive.recalc(); }
            if (t === 'merit') {
                if (yoyChart) yoyChart.resize();                    // canvas measured 0 while hidden
                if (meritTerm !== termId()) loadMerit();            // stale or never loaded -> refetch
            }
        });
    }

    $(document).on('click', '#resTabs .tab-btn', function () { goTab($(this).data('tab')); });

    // ---------- merit & analytics ----------
    var topTable = null, yoyChart = null, meritTerm = 0;

    $(document).ready(function () {
        $('#filterTerm').on('change', function () { if ($('#pane-merit').hasClass('active')) loadMerit(); });
    });

    function loadMerit(btn) {
        var tid = termId();
        if (!tid) { $('#meritBody').addClass('initially-hidden'); $('#meritEmpty').removeClass('initially-hidden'); return; }
        ORMS.post('getMerit', { term_id: tid }, { btn: btn }).done(function (res) {
            if (!res.success) { ORMS.err(res.message || 'Failed to load merit data'); return; }
            meritTerm = tid;
            renderMerit(res);
        }).fail(function (msg) { ORMS.err(msg); });
    }

    function meritTable(head, body, empty) {
        if (!body) return '<div class="orms-empty"><i class="fas fa-inbox"></i><h4>' + ORMS.esc(empty) + '</h4></div>';
        return '<div class="about-table-wrapper"><table class="about-roles-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    }

    function renderMerit(res) {
        $('#meritLabel').text('— ' + res.term);
        var any = (res.toppers || []).length || (res.subject_best || []).length || (res.teacher_avg || []).length || (res.yoy || []).length;
        $('#meritEmpty').toggleClass('initially-hidden', !!any);
        $('#meritBody').toggleClass('initially-hidden', !any);
        if (!any) return;

        // toppers — dt with the standard export trio
        if (topTable) { topTable.destroy(); $('#topTable').empty(); topTable = null; }
        topTable = $('#topTable').DataTable({
            data: res.toppers || [],
            destroy: true,
            columns: [
                { data: 'position', title: 'Pos', render: function (d, t) {
                    if (t !== 'display') return d;
                    var medal = d == 1 ? '🥇' : (d == 2 ? '🥈' : '🥉');
                    return medal + ' ' + d;
                } },
                { data: null, title: 'Student', render: function (d, t, r) {
                    // a held card must never quietly top a printed merit list
                    return '<strong>' + ORMS.esc(r.full_name || r.username) + '</strong>' +
                           (r.is_withheld == 1 ? ' <span class="rc-withheld"><i class="fas fa-hand-holding-dollar"></i> Withheld</span>' : '');
                } },
                { data: 'roll_no', title: 'Roll', render: function (d) { return ORMS.esc(d || '—'); } },
                { data: null, title: 'Class – Section', render: function (d, t, r) { return ORMS.esc(r.class_name + ' – ' + r.section_name); } },
                { data: 'percentage', title: '%', render: function (d, t) { return t === 'display' ? d + '%' : d; } },
                { data: 'grade', title: 'Grade' },
                { data: 'gpa', title: 'GPA' },
                { data: 'result_status', title: 'Result', render: function (d, t) {
                    return t === 'display' ? '<span class="status-badge ' + (d === 'PASS' ? 'status-active' : 'status-inactive') + '">' + d + '</span>' : d;
                } }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Merit List - ' + res.term },
                { text: '<i class="fas fa-file-pdf"></i> PDF', title: 'Merit List - ' + res.term,
                  action: function (e, dt, node, config) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Merit List - ' + res.term }
            ],
            order: []
        });

        // subject toppers
        var sb = '';
        (res.subject_best || []).forEach(function (r) {
            sb += '<tr><td>' + ORMS.esc(r.class_name) + '</td><td>' + ORMS.esc(r.subject_name) + '</td>' +
                  '<td><strong>' + ORMS.esc(r.full_name || r.username) + '</strong> <small class="text-muted">Roll ' +
                  ORMS.esc(r.roll_no || '—') + ' · ' + ORMS.esc(r.section_name) + '</small></td>' +
                  '<td>' + ORMS.esc(r.best_pct) + '%</td></tr>';
        });
        $('#subjBest').html(meritTable(
            '<th><i class="fas fa-school"></i> Class</th><th><i class="fas fa-book"></i> Subject</th><th><i class="fas fa-user-graduate"></i> Best Student(s)</th><th><i class="fas fa-percent"></i> Score</th>',
            sb, 'No marks entered for this term yet'));

        // teacher averages
        var ta = '';
        (res.teacher_avg || []).forEach(function (r) {
            var has = parseInt(r.n, 10) > 0;
            ta += '<tr><td><strong>' + ORMS.esc(r.teacher_name) + '</strong></td>' +
                  '<td>' + ORMS.esc(r.class_name + ' – ' + r.section_name) + '</td>' +
                  '<td>' + ORMS.esc(r.subject_name) + '</td>' +
                  '<td>' + (has ? r.n : '<span class="text-muted">—</span>') + '</td>' +
                  '<td>' + (has ? ORMS.esc(r.avg_pct) + '%' : '<span class="text-muted">—</span>') + '</td>' +
                  '<td>' + (has ? ORMS.esc(r.pass_pct) + '%' : '<span class="text-muted">—</span>') + '</td></tr>';
        });
        $('#teachAvg').html(meritTable(
            '<th><i class="fas fa-chalkboard-teacher"></i> Teacher</th><th><i class="fas fa-layer-group"></i> Section</th><th><i class="fas fa-book"></i> Subject</th><th><i class="fas fa-pen"></i> Entered</th><th><i class="fas fa-percent"></i> Avg Score</th><th><i class="fas fa-check-double"></i> Pass Rate</th>',
            ta, 'No teacher assignments for this year'));

        renderYoy(res.yoy || []);
    }

    // one line per class across the published years — spanGaps rides over missing years
    function renderYoy(rows) {
        if (yoyChart) { yoyChart.destroy(); yoyChart = null; }
        if (!rows.length || typeof Chart === 'undefined') { $('#yoyWrap').addClass('initially-hidden'); return; }
        $('#yoyWrap').removeClass('initially-hidden');
        var years = [], seen = {}, classes = {};
        rows.forEach(function (r) {
            if (!seen[r.year_name]) { seen[r.year_name] = 1; years.push(r.year_name); }
            (classes[r.class_name] = classes[r.class_name] || {})[r.year_name] = parseFloat(r.avg_pct);
        });
        var palette = ['#0074D9', '#2ecc40', '#ff851b', '#b10dc9', '#39cccc', '#ff4136', '#001f3f', '#7fdbff'];
        var ds = Object.keys(classes).map(function (cn, i) {
            var col = palette[i % palette.length];
            return { label: cn, data: years.map(function (y) { return classes[cn][y] != null ? classes[cn][y] : null; }),
                     borderColor: col, backgroundColor: col, tension: 0.3, spanGaps: true };
        });
        yoyChart = new Chart(document.getElementById('yoyChart'), {
            type: 'line',
            data: { labels: years, datasets: ds },
            options: { responsive: true, maintainAspectRatio: false,
                       scales: { y: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } } },
                       plugins: { legend: { position: 'bottom' } } }
        });
    }

    // remarks ride on the stored summary — nothing is recomputed, so this stays open after publish
    var remKind = 'teacher';

    function openRemarks(studentId, kind) {
        var row = tabRows.filter(function (r) { return r.student_id === studentId; })[0] || {};
        if (!row.saved) { ORMS.err('Publish this section first — remarks attach to a generated result'); return; }
        remKind = kind === 'principal' ? 'principal' : 'teacher';
        var head = remKind === 'principal';
        $('#remStudent').val(studentId);
        $('#remTitle').html('<i class="fas ' + (head ? 'fa-user-tie' : 'fa-comment-dots') + '"></i> ' +
                            ORMS.esc(head ? HEAD + "'s Remarks" : "Class Teacher's Remarks"));
        $('#remHelp').text(head ? 'Printed on the result card under the class teacher’s line.'
                                : 'Printed on the result card under the summary.');
        $('#remWho').text(row.name + (row.roll_no ? ' · Roll ' + row.roll_no : ''));
        $('#remText').val((head ? row.p_remarks : row.remarks) || '');
        $('#remModal').addClass('active');
    }

    function closeRemarks() { $('#remModal').removeClass('active'); }
    $('#remModal').on('click', function (e) { if (e.target === this) closeRemarks(); });

    $('#remForm').on('submit', function (e) {
        e.preventDefault();
        var sid = parseInt($('#remStudent').val(), 10);
        var head = remKind === 'principal', key = head ? 'p_remarks' : 'remarks';
        ORMS.post(head ? 'savePrincipalRemarks' : 'saveRemarks',
                  { term_id: termId(), student_id: sid, remarks: $('#remText').val() },
                  { btn: '#remBtn', busyLabel: 'Saving…' })
            .done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                tabRows.forEach(function (r) { if (r.student_id === sid) r[key] = res.remarks; });
                if (tabTable) tabTable.clear().rows.add(tabRows).draw(false);   // redraw only, no refetch
                closeRemarks();
                ORMS.ok(res.message);
            })
            .fail(function (msg) { ORMS.err(msg); });
    });

    // whole class in one Ctrl+P — cards stacked with a page break between them
    function bulkPrint(sectionId, btn) {
        ORMS.post('bulkCards', { term_id: termId(), section_id: sectionId }, { btn: btn, busyLabel: 'Building…' })
            .done(function (res) {
                if (!res.success) { ORMS.err(res.message); return; }
                $('#bulkPrintArea').html(res.html);
                ORMS.qr();                                   // verify codes before the print snapshot
                // drop the cards after printing so a later Ctrl+P never reprints a stale class
                $(window).one('afterprint', function () { $('#bulkPrintArea').empty(); });
                setTimeout(function () { window.print(); }, 250);
            })
            .fail(function (msg) { ORMS.err(msg); });
    }
    </script>
</body>
</html>
