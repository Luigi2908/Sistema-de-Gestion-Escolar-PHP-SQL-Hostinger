<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

// rbac view gate
requirePerm('marks_entry', 'v');

$username     = $_SESSION['username'];
$role         = $_SESSION['role'];
$user_id      = (int)$_SESSION['user_id'];
$current_page = 'marks_entry';

$isAdmin   = ($role === 'Admin');
$teacherId = $isAdmin ? null : ormsTeacherId($user_id);
$canAdd    = can('marks_entry', 'a');
$year      = ormsCurrentYear();
$yearId    = $year ? (int)$year['id'] : 0;
$terms     = $yearId ? ormsTerms($yearId) : [];

$DEFAULT_AVATAR = 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg';

// "?,?,?" for an IN list — placeholders only, values always bound
function meIn(array $ids) {
    return implode(',', array_fill(0, count($ids), '?'));
}

// ---- tenant scope. this page loads config only (never result_engine), so the probes live here.
// meMarksScope() MUST stay byte-identical to result_engine's ormsMarksScopeSql() — the completion
// maths is compared across both files and a differing clause would desync entered vs expected

// school_id landed on this table yet? one probe per table per request — an install that has not
// taken the tenant migration keeps the old shape instead of fataling on a missing column
function meHasSchoolCol($table) {
    static $seen = [];
    if (!isset($seen[$table])) {
        try { qVal("SELECT school_id FROM `$table` LIMIT 1"); $seen[$table] = true; }
        catch (Throwable $e) { $seen[$table] = false; }
    }
    return $seen[$table];
}

// " AND <alias>.school_id = ?" or '' when the column isn't there yet — every caller binds sid()
// for the ? it adds. same shape as result_engine's ormsSchoolSql(), on purpose
function meSchoolSql($table, $alias) {
    return meHasSchoolCol($table) ? " AND $alias.school_id = ?" : '';
}

// THE marks tenant predicate — reads the same here as in results / result_engine / dashboard
function meMarksScope($alias = 'm') { return meSchoolSql('marks', $alias); }

// bind helpers so a call site never has to remember whether the clause added a ? or not
function meSchoolT($frag) { return $frag ? 'i' : ''; }
function meSchoolA($frag) { return $frag ? [sid()] : []; }

// every id off the request goes through here — 0 back means another school (or another branch).
// pre-migration install has no school_id columns, so the resolver would deny everything: raw id stands
function meOwn($table, $id) { return meHasSchoolCol('classes') ? ormsOwns($table, $id) : (int)$id; }

// highest band at or below pct — same rule as ormsGradeFor, but off a preloaded scheme (no query per row)
function meGrade(array $bands, $pct) {
    $p = round((float)$pct, 2);
    $hit = null;
    foreach ($bands as $b) {
        $min = (float)$b['min_percent'];
        if ($min <= $p && ($hit === null || $min > (float)$hit['min_percent'])) $hit = $b;
    }
    return $hit;
}

// trims 100.00 -> 100 for labels
function meNum($n) {
    $s = number_format((float)$n, 2, '.', '');
    return strpos($s, '.') === false ? $s : rtrim(rtrim($s, '0'), '.');
}

// year-end close — the widest entry gate. one lookup, reused by the cards, the grid and the save
function meYearLock($yearId) {
    try {
        $y = $yearId ? qOne("SELECT name, is_locked FROM academic_years WHERE id = ?", 'i', (int)$yearId) : null;
    } catch (Throwable $e) { $y = null; }               // column not migrated yet -> never lock people out
    return ['locked' => $y ? ((int)$y['is_locked'] === 1) : false, 'name' => (string)($y['name'] ?? '')];
}

// two-box subject: both components configured and worth something
function meSplit($th, $pr) {
    return $th !== null && $pr !== null && (float)$th > 0 && (float)$pr > 0;
}

// per-class marks config for one subject, split + counts-toward-total resolved once
function meCfg(array $cs, $csInclude = 1, $subInclude = 1) {
    $th = $cs['theory_marks'] ?? null; $pr = $cs['practical_marks'] ?? null;
    return [
        'total'     => (float)$cs['total_marks'],
        'pass'      => (float)$cs['passing_marks'],
        'theory'    => (float)$th,
        'practical' => (float)$pr,
        'split'     => meSplit($th, $pr),
        'optional'  => (int)($cs['is_optional'] ?? 0) === 1,   // enrolment-gated elective
        // either flag off = graded and printed, but out of the total/percentage/gpa/pass-fail
        'excl'      => ((int)$csInclude === 0 || (int)$subInclude === 0)
    ];
}

// assessment components live at all? one probe — an un-migrated install keeps the old single-box maths
function meCompOn() {
    static $on = null;
    if ($on === null) $on = function_exists('ormsHasAssessment') && function_exists('ormsSchemeForClass')
        && function_exists('ormsComponents') && function_exists('ormsConvertComponents') && ormsHasAssessment();
    return $on;
}

// the class's scheme + its components, resolved once per class. weight 0 or no rows = no scheme at all
function meScheme($classId) {
    static $c = [];
    $k = (int)$classId;
    if (!array_key_exists($k, $c)) {
        $id    = meCompOn() ? (int)ormsSchemeForClass($k) : 0;
        $comps = $id ? ormsComponents($id) : [];
        $w = 0.0;
        foreach ($comps as $x) $w += (float)$x['weight_percent'];
        $c[$k] = ($comps && $w > 0) ? ['id' => $id, 'comps' => $comps, 'weight' => round($w, 2)]
                                    : ['id' => 0, 'comps' => [], 'weight' => 0.0];
    }
    return $c[$k];
}

function meComps($classId) { return meScheme($classId)['comps']; }

// scheme name + its ca/exam split, for the grid header
function meSchemeInfo($classId) {
    $sc = meScheme($classId);
    if (!$sc['comps']) return null;
    $ca = $ex = 0.0; $name = '';
    foreach ($sc['comps'] as $c) {
        $w = (float)$c['weight_percent'];
        if ((int)$c['is_exam'] === 1) $ex += $w; else $ca += $w;
    }
    if (function_exists('ormsAssessmentSchemes'))
        foreach (ormsAssessmentSchemes(false) as $x) if ((int)$x['id'] === $sc['id']) { $name = (string)$x['name']; break; }
    return ['id' => $sc['id'], 'name' => $name !== '' ? $name : 'Assessment scheme',
            'ca' => round($ca, 2), 'exam' => round($ex, 2), 'weight' => $sc['weight']];
}

// components rescale a subject onto the weight sum — the pass ratio rides along so it survives the rescale
function meWeighted(array $c, $weight) {
    $tot = (float)$c['total'];
    return ['total' => round((float)$weight, 2),
            'pass'  => $tot > 0 ? round((float)$c['pass'] / $tot * (float)$weight, 2) : 0.0];
}

// bands of the class's OWN grading set — never the global default
function meBands($classId) {
    return function_exists('ormsSetForClass')
        ? ormsGradingScheme((int)ormsSetForClass((int)$classId)) : ormsGradingScheme();
}

// raw component scores for a whole section in ONE query: "stu:sub" => [component_id => score]
function meCompMap($sectionId, $termId, array $subIds, array $comps) {
    $out = [];
    if (!$comps || !$subIds) return $out;
    foreach (qAll("SELECT mc.student_id, mc.subject_id, mc.component_id, mc.score
                   FROM mark_components mc
                   JOIN students st ON st.id = mc.student_id AND st.section_id = ? AND st.status = 'Active'
                   WHERE mc.term_id = ? AND mc.subject_id IN (" . meIn($subIds) . ")",
                  'ii' . str_repeat('i', count($subIds)), (int)$sectionId, (int)$termId, ...$subIds) as $r)
        $out[$r['student_id'] . ':' . $r['subject_id']][(int)$r['component_id']] =
            $r['score'] === null ? null : (float)$r['score'];
    return $out;
}

// the columns the SESSION user may fill for this section — teacher: teacher_subjects, admin: class_subjects.
// never a client-supplied subject list; $wantSubjectId only narrows what the query already allows
function meSubjectCols($sectionId, $classId, $yearId, $isAdmin, $teacherId, $wantSubjectId) {
    // cols: id, name, code, total, pass, theory/practical split, optional flag, both include_in_total flags
    $cols = "sub.id, sub.name, sub.code, cs.total_marks, cs.passing_marks,
             cs.theory_marks, cs.practical_marks, cs.is_optional,
             cs.include_in_total AS cs_include, sub.include_in_total AS sub_include";
    $sc = meSchoolSql('classes', 'c');           // school clause goes LAST so the ids above never shift
    if ($isAdmin) {
        // classes carries the tenant stamp, so the join IS the school check — an admin of school A
        // must never resolve a column set out of school B's class, whatever section_id was posted
        $sql = "SELECT $cols
                FROM class_subjects cs
                JOIN classes c    ON c.id = cs.class_id
                JOIN subjects sub ON sub.id = cs.subject_id
                WHERE cs.class_id = ?" . ($wantSubjectId ? " AND cs.subject_id = ?" : '') . $sc . "
                ORDER BY cs.sort_order ASC, sub.name ASC";
        return qAll($sql, 'i' . ($wantSubjectId ? 'i' : '') . meSchoolT($sc),
                    ...array_merge([$classId], $wantSubjectId ? [$wantSubjectId] : [], meSchoolA($sc)));
    }
    if (!$teacherId) return [];
    // inner join on class_subjects — a subject with no marks config gets no column (nothing to snapshot)
    $sql = "SELECT $cols
            FROM teacher_subjects ts
            JOIN subjects sub ON sub.id = ts.subject_id
            JOIN class_subjects cs ON cs.class_id = ts.class_id AND cs.subject_id = ts.subject_id
            JOIN classes c ON c.id = cs.class_id
            WHERE ts.teacher_id = ? AND ts.section_id = ? AND ts.academic_year_id = ?"
          . ($wantSubjectId ? " AND ts.subject_id = ?" : '') . $sc . "
            ORDER BY cs.sort_order ASC, sub.name ASC";
    return qAll($sql, 'iii' . ($wantSubjectId ? 'i' : '') . meSchoolT($sc),
                ...array_merge([$teacherId, $sectionId, $yearId], $wantSubjectId ? [$wantSubjectId] : [], meSchoolA($sc)));
}

// section + its class, one shape for the grid, the save and the score sheet.
// the classes join carries the tenant stamp, so every class_id read out of here is this school's
function meSection($sectionId) {
    $sc = meSchoolSql('classes', 'c');
    return qOne("SELECT s.id, s.class_id, s.name AS section_name, c.name AS class_name
                 FROM sections s JOIN classes c ON c.id = s.class_id
                 WHERE s.id = ?" . $sc, 'i' . meSchoolT($sc), ...array_merge([(int)$sectionId], meSchoolA($sc)));
}

// optional-subject enrolment: subject_id => [student_id => 1]. a listed subject with no rows is closed
// to everyone, never open to all. $sectionId narrows to that section's active roster, 0 = the whole year
function meEnrolMap(array $optIds, $yearId, $sectionId = 0) {
    $out = [];
    if (!$optIds || !ormsHasElectives()) return $out;   // pre-migration install -> no filtering at all
    foreach ($optIds as $oid) $out[(int)$oid] = [];
    $ph  = meIn($optIds);
    $sql = $sectionId
         ? "SELECT ss.subject_id, ss.student_id FROM student_subjects ss
            JOIN students st ON st.id = ss.student_id AND st.section_id = ? AND st.status = 'Active'
            WHERE ss.academic_year_id = ? AND ss.subject_id IN ($ph)"
         : "SELECT subject_id, student_id FROM student_subjects
            WHERE academic_year_id = ? AND subject_id IN ($ph)";
    $args = $sectionId ? array_merge([(int)$sectionId, (int)$yearId], $optIds) : array_merge([(int)$yearId], $optIds);
    foreach (qAll($sql, str_repeat('i', count($args)), ...$args) as $r)
        $out[(int)$r['subject_id']][(int)$r['student_id']] = 1;
    return $out;
}

// subjects the session user may NOT fill — [] means all clear
function meDenied($userId, $role, $sectionId, $termId, array $subjIds) {
    return array_values(array_filter($subjIds, function ($sid) use ($userId, $role, $sectionId, $termId) {
        return !ormsCanEnterMarks($userId, $role, $sectionId, (int)$sid, $termId);
    }));
}

// locks that close a whole section+term. widest first — unpublishing can't reopen a closed year
function meLockGuard(array $term, $sectionId) {
    $yl = meYearLock((int)$term['academic_year_id']);
    if ($yl['locked'])
        jsonErr('Academic year "' . $yl['name'] . '" is locked — marks for this year can no longer be entered or edited.');
    if (($term['status'] ?? '') !== 'Open')
        jsonErr('Term "' . $term['name'] . '" is ' . $term['status'] . ' — marks entry is closed.');
    if (ormsIsPublished((int)$term['id'], $sectionId))
        jsonErr('Results for this section are published. Unpublish first to edit marks.');
}

// everything a write needs, resolved once: total/passing snapshot, elective enrolment, roster,
// subject names, grade bands and what already exists (so an import knows added from updated)
function meCtx(array $term, array $sec, array $subjIds, $userId) {
    $termId = (int)$term['id']; $secId = (int)$sec['id'];
    $classId = (int)$sec['class_id']; $tYear = (int)$term['academic_year_id'];

    // components beat the theory/practical split — one scheme for every subject of the class
    $sc = meScheme($classId); $comps = $sc['comps']; $wt = $sc['weight'];

    // "counted" needs BOTH flags — class_subjects.include_in_total AND subjects.include_in_total.
    // ormsClassSubject() only returns the class_subjects row, so the subject-level flag is
    // fetched here in one query rather than being silently defaulted to 1
    $subInc = [];
    foreach (qAll("SELECT id, include_in_total FROM subjects WHERE id IN (" . meIn($subjIds) . ")",
                  str_repeat('i', count($subjIds)), ...$subjIds) as $r) $subInc[(int)$r['id']] = (int)$r['include_in_total'];

    // snapshot from class_subjects, never from the client — later config edits must not rewrite history
    $cfg = [];
    foreach ($subjIds as $sid) {
        $cs = ormsClassSubject($classId, $sid);
        if (!$cs) jsonErr('One of these subjects has no marks configuration for this class.');
        $cfg[$sid] = meCfg($cs, $cs['include_in_total'] ?? 1, $subInc[$sid] ?? 1);
        // rescaled onto the weight sum, split switched off — the components are the only boxes now
        if ($comps) $cfg[$sid] = array_merge($cfg[$sid], meWeighted($cfg[$sid], $wt), ['split' => false]);
    }

    // optional subject takes ENROLLED students only — the disabled ui is never trusted
    $enrol = meEnrolMap(array_values(array_filter($subjIds, function ($s) use ($cfg) { return !empty($cfg[$s]['optional']); })), $tYear);

    $names = [];
    foreach (qAll("SELECT id, name FROM subjects WHERE id IN (" . meIn($subjIds) . ")",
                  str_repeat('i', count($subjIds)), ...$subjIds) as $r) $names[(int)$r['id']] = $r['name'];

    $roster = [];   // O(1) membership check + a readable roll in errors
    foreach (qAll("SELECT id, roll_no FROM students WHERE section_id = ? AND status = 'Active'", 'i', $secId) as $r)
        $roster[(int)$r['id']] = $r['roll_no'];

    $exist = [];
    foreach (qAll("SELECT student_id, subject_id FROM marks
                   WHERE term_id = ? AND section_id = ? AND subject_id IN (" . meIn($subjIds) . ")",
                  'ii' . str_repeat('i', count($subjIds)), $termId, $secId, ...$subjIds) as $r)
        $exist[$r['student_id'] . ':' . $r['subject_id']] = 1;

    return ['cfg' => $cfg, 'enrol' => $enrol, 'names' => $names, 'roster' => $roster, 'exist' => $exist,
            'bands' => meBands($classId), 'class_id' => $classId, 'section_id' => $secId,
            'term_id' => $termId, 'year_id' => $tYear, 'user_id' => (int)$userId,
            'comps' => $comps, 'scheme_id' => $comps ? $sc['id'] : 0, 'weight' => $wt];
}

// EVERY marks write on this page lands here: validate -> classify -> ONE txn, ONE prepared pair.
// $dry stops before the write, so import preview and import commit can never drift apart
function mePersist(array $ctx, array $rows, $dry = false) {
    $cfg = $ctx['cfg']; $roster = $ctx['roster']; $names = $ctx['names'];
    $enrolOk = $ctx['enrol']; $exist = $ctx['exist']; $bands = $ctx['bands'];
    $comps = $ctx['comps'] ?? []; $schemeId = (int)($ctx['scheme_id'] ?? 0);
    $out = ['saved' => 0, 'skipped' => 0, 'errors' => [], 'bad' => [],
            'added' => 0, 'updated' => 0, 'cleared' => 0];
    $ops = [];

    // one entry box (component, theory or practical): '' -> null, else numeric within its own max
    $part = function ($v, $max) {
        if ($v === null || $v === '') return [true, null, ''];
        if (!is_numeric($v))          return [false, null, 'must be a number'];
        $n = round((float)$v, 2);
        if ($n < 0 || $n > $max)      return [false, null, 'must be between 0 and ' . meNum($max)];
        return [true, $n, ''];
    };

    foreach ($rows as $r) {
        $stu = (int)($r['student_id'] ?? 0);
        $sub = (int)($r['subject_id'] ?? 0);
        $key = $stu . ':' . $sub;
        $at  = trim((string)($r['at'] ?? ''));       // "Row 7" for imports, empty for the grid
        $at  = $at === '' ? '' : $at . ' · ';

        if (!isset($roster[$stu])) {
            $out['skipped']++; $out['bad'][] = $key;
            $out['errors'][] = $at . 'Student #' . $stu . ' is not an active student of this section.';
            continue;
        }
        if (!isset($cfg[$sub])) {
            $out['skipped']++; $out['bad'][] = $key;
            $out['errors'][] = $at . 'Subject #' . $sub . ' is not configured for this class.';
            continue;
        }

        $who = $at . 'Roll ' . (($roster[$stu] !== null && $roster[$stu] !== '') ? $roster[$stu] : '#' . $stu)
             . ' · ' . ($names[$sub] ?? ('#' . $sub));

        if (isset($enrolOk[$sub]) && !isset($enrolOk[$sub][$stu])) {   // elective, not their pick
            $out['skipped']++; $out['bad'][] = $key;
            $out['errors'][] = $who . ': not enrolled in this optional subject.';
            continue;
        }

        $tot = $cfg[$sub]['total'];
        $abs = empty($r['is_absent']) ? 0 : 1;
        $obt = $th = $pr = $ca = $ex = null;
        $scores = [];

        if ($comps && !$abs) {
            // one raw box per component, each against its own max — the weighting is the engine's job
            $raw = is_array($r['comp'] ?? null) ? $r['comp'] : [];
            $err = '';
            foreach ($comps as $cp) {
                [$okC, $v, $eC] = $part($raw[(int)$cp['id']] ?? null, (float)$cp['max_marks']);
                if (!$okC) { $err = $cp['name'] . ' ' . $eC; break; }
                $scores[(int)$cp['id']] = $v;
            }
            if ($err !== '') {
                $out['skipped']++; $out['bad'][] = $key;
                $out['errors'][] = $who . ': ' . $err . '.';
                continue;
            }
            $cv = ormsConvertComponents($scores, $comps);       // never reimplement the maths here
            if (!empty($cv['entered'])) {
                $obt = round((float)$cv['obtained'], 2);
                $ca  = round((float)$cv['ca'], 2);
                $ex  = round((float)$cv['exam'], 2);
            }
        } elseif ($cfg[$sub]['split'] && !$abs) {
            // two boxes -> the stored total is the sum WE compute, a posted total is ignored
            [$okT, $th, $eT] = $part($r['theory'] ?? null,    $cfg[$sub]['theory']);
            [$okP, $pr, $eP] = $part($r['practical'] ?? null, $cfg[$sub]['practical']);
            if (!$okT || !$okP) {
                $out['skipped']++; $out['bad'][] = $key;
                $out['errors'][] = $who . ': ' . (!$okT ? 'theory ' . $eT : 'practical ' . $eP) . '.';
                continue;
            }
            if ($th !== null || $pr !== null) {
                $obt = round((float)$th + (float)$pr, 2);
                if ($obt > $tot) {
                    $out['skipped']++; $out['bad'][] = $key;
                    $out['errors'][] = $who . ': theory + practical is ' . meNum($obt) . ', more than the subject total of ' . meNum($tot) . '.';
                    continue;
                }
            }
        } elseif (!$abs) {
            $raw = $r['marks_obtained'] ?? null;
            if ($raw !== null && $raw !== '') {
                if (!is_numeric($raw)) {
                    $out['skipped']++; $out['bad'][] = $key;
                    $out['errors'][] = $who . ': marks must be a number.';
                    continue;
                }
                $obt = round((float)$raw, 2);
                if ($obt < 0 || $obt > $tot) {
                    $out['skipped']++; $out['bad'][] = $key;
                    $out['errors'][] = $who . ': marks must be between 0 and ' . meNum($tot) . '.';
                    continue;
                }
            }
        }

        // blank and not absent -> the row goes, never a null mark left behind (it would score 0 and fail)
        if (!$abs && $obt === null) {
            $ops[] = ['del', $stu, $sub];
            if (isset($exist[$key])) $out['cleared']++;
            $out['saved']++;
            continue;
        }

        // absent -> obtained NULL but counts 0 against the full total
        $band = meGrade($bands, ormsPercent((float)($obt ?? 0), $tot));
        $rem  = mb_substr(trim((string)($r['remarks'] ?? '')), 0, 255);   // varchar(255), chars not bytes
        $ops[] = ['set', $stu, $sub, $obt, $tot, $cfg[$sub]['pass'], $abs,
                  $band ? $band['grade'] : null, $rem === '' ? null : $rem,
                  $abs ? null : $th, $abs ? null : $pr,                   // absent keeps no components
                  $comps ? $schemeId : null, $abs ? null : $ca, $abs ? null : $ex,
                  $abs ? [] : $scores];
        isset($exist[$key]) ? $out['updated']++ : $out['added']++;
        $out['saved']++;
    }

    if ($dry || !$ops) return $out;

    $bStu = $bSub = $bAbs = 0;
    $bClass = $ctx['class_id']; $bSec = $ctx['section_id']; $bTerm = $ctx['term_id'];
    $bYear = $ctx['year_id'];   $bBy = $ctx['user_id'];
    $bObt = null; $bTot = 0.0; $bPass = 0.0; $bGrade = null; $bRem = null; $bTh = null; $bPr = null;
    $bSch = null; $bCa = null; $bEx = null; $bComp = 0; $bScore = null;
    $bSchool = sid();                                    // tenant stamp, same for every row of the batch

    $conn = getDBConnection();
    $conn->begin_transaction();                          // one txn, two statements, executed in a loop
    try {
        $wide = meCompOn();      // migrated schema -> the row also snapshots its scheme + ca/exam split
        $ten  = meHasSchoolCol('marks');                 // tenant col there yet
        $stmt = $conn->prepare(
            "INSERT INTO marks (student_id, class_id, section_id, subject_id, term_id, academic_year_id,
                                marks_obtained, total_marks, passing_marks, is_absent, grade, remarks,
                                theory_obtained, practical_obtained"
             . ($wide ? ", assessment_scheme_id, ca_obtained, exam_obtained" : '') . ", entered_by, updated_by"
             . ($ten ? ", school_id" : '') . ")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($wide ? ", ?, ?, ?" : '') . ", ?, ?" . ($ten ? ", ?" : '') . ")
             ON DUPLICATE KEY UPDATE marks_obtained     = VALUES(marks_obtained),
                                     is_absent          = VALUES(is_absent),
                                     grade              = VALUES(grade),
                                     remarks            = VALUES(remarks),
                                     theory_obtained    = VALUES(theory_obtained),
                                     practical_obtained = VALUES(practical_obtained),
                                     total_marks        = VALUES(total_marks),
                                     passing_marks      = VALUES(passing_marks),
                                     class_id           = VALUES(class_id),
                                     section_id         = VALUES(section_id),
                                     updated_by         = VALUES(updated_by)"
             . ($wide ? ", assessment_scheme_id = VALUES(assessment_scheme_id),
                                     ca_obtained        = VALUES(ca_obtained),
                                     exam_obtained      = VALUES(exam_obtained)" : ''));
        if (!$stmt) throw new RuntimeException($conn->error);
        // 16 cols: i student, i class, i section, i subject, i term, i year, d obtained, d total, d passing,
        //          i absent, s grade, s remarks, d theory, d practical, i entered_by, i updated_by
        // migrated: +i scheme, +d ca, +d exam before entered_by -> 19
        // tenanted: +i school_id, always LAST so nothing above it shifts       -> 17 / 20
        $t = ($wide ? 'iiiiiidddissddiddii' : 'iiiiiidddissddii') . ($ten ? 'i' : '');
        if ($wide) {
            $ten ? $stmt->bind_param($t, $bStu, $bClass, $bSec, $bSub, $bTerm, $bYear,
                                     $bObt, $bTot, $bPass, $bAbs, $bGrade, $bRem, $bTh, $bPr,
                                     $bSch, $bCa, $bEx, $bBy, $bBy, $bSchool)
                 : $stmt->bind_param($t, $bStu, $bClass, $bSec, $bSub, $bTerm, $bYear,
                                     $bObt, $bTot, $bPass, $bAbs, $bGrade, $bRem, $bTh, $bPr,
                                     $bSch, $bCa, $bEx, $bBy, $bBy);
        } else {
            $ten ? $stmt->bind_param($t, $bStu, $bClass, $bSec, $bSub, $bTerm, $bYear,
                                     $bObt, $bTot, $bPass, $bAbs, $bGrade, $bRem, $bTh, $bPr, $bBy, $bBy, $bSchool)
                 : $stmt->bind_param($t, $bStu, $bClass, $bSec, $bSub, $bTerm, $bYear,
                                     $bObt, $bTot, $bPass, $bAbs, $bGrade, $bRem, $bTh, $bPr, $bBy, $bBy);
        }

        // cleared cell -> the row goes, never a null mark left behind
        $del = $conn->prepare("DELETE FROM marks WHERE student_id = ? AND subject_id = ? AND term_id = ?");
        if (!$del) throw new RuntimeException($conn->error);
        $del->bind_param('iii', $bStu, $bSub, $bTerm);

        // raw component scores ride the SAME txn — prepared once, executed in the loop
        $cSet = $cDel = $cWipe = null;
        if ($comps) {
            $cSet = $conn->prepare(
                "INSERT INTO mark_components (student_id, subject_id, term_id, component_id, score, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by)");
            if (!$cSet) throw new RuntimeException($conn->error);
            $cSet->bind_param('iiiidi', $bStu, $bSub, $bTerm, $bComp, $bScore, $bBy);   // 6: i i i i d i

            $cDel = $conn->prepare("DELETE FROM mark_components
                                    WHERE student_id = ? AND subject_id = ? AND term_id = ? AND component_id = ?");
            if (!$cDel) throw new RuntimeException($conn->error);
            $cDel->bind_param('iiii', $bStu, $bSub, $bTerm, $bComp);

            $cWipe = $conn->prepare("DELETE FROM mark_components WHERE student_id = ? AND subject_id = ? AND term_id = ?");
            if (!$cWipe) throw new RuntimeException($conn->error);
            $cWipe->bind_param('iii', $bStu, $bSub, $bTerm);
        }

        foreach ($ops as $o) {
            $bStu = $o[1]; $bSub = $o[2];
            // cleared cell -> the marks row AND its component scores go together
            if ($o[0] === 'del') { $del->execute(); if ($cWipe) $cWipe->execute(); continue; }
            $bObt = $o[3]; $bTot = $o[4]; $bPass = $o[5]; $bAbs = $o[6];
            $bGrade = $o[7]; $bRem = $o[8]; $bTh = $o[9]; $bPr = $o[10];
            $bSch = $o[11] ?? null; $bCa = $o[12] ?? null; $bEx = $o[13] ?? null;
            $stmt->execute();
            if (!$cSet) continue;
            if ($bAbs) { $cWipe->execute(); continue; }          // absent keeps no component scores
            foreach (($o[14] ?? []) as $cid => $val) {
                $bComp = (int)$cid;
                if ($val === null) { $cDel->execute(); continue; }  // emptied box -> that score goes
                $bScore = $val;
                $cSet->execute();
            }
        }
        $stmt->close();
        $del->close();
        if ($cSet) { $cSet->close(); $cDel->close(); $cWipe->close(); }
        // sign-off is only valid for the marks it was given for — changing them sends it back to Draft
        $out['approval_reset'] = ormsApprovalInvalidate((int)$ctx['term_id'], (int)$ctx['section_id']) > 0 ? 1 : 0;
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('marks_entry persist: ' . $e->getMessage());
        jsonErr('Save failed — nothing was written. Please try again.');
    }
    if (!empty($out['approval_reset'])) {
        logActivity((int)$ctx['user_id'], $_SESSION['username'] ?? '', 'Approval Reset',
                    'Marks changed after approval — section returned to Draft and must be approved again',
                    'result_publication', (int)$ctx['section_id']);
    }
    return $out;
}

// ---- offline score sheet: csv text in, csv text out ----

// remove accents for flexible bilingual matching
function meStripAccents($str) {
    $accents = [
        'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'Á'=>'a', 'É'=>'e', 'Í'=>'i', 'Ó'=>'o', 'Ú'=>'u',
        'ñ'=>'n', 'Ñ'=>'n', 'ü'=>'u', 'Ü'=>'u', 'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ò'=>'o', 'ù'=>'u',
        'À'=>'a', 'È'=>'e', 'Ì'=>'i', 'Ò'=>'o', 'Ù'=>'u'
    ];
    return strtr((string)$str, $accents);
}

// spreadsheet whitespace incl nbsp. $all strips it everywhere, else just the ends
function meTrim($v, $all = false) {
    $s = (string)$v;
    $r = preg_replace($all ? '/[\s\x{00A0}]+/u' : '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s);
    return $r === null ? trim($s) : $r;   // odd encoding -> plain trim, never null
}

// "Matemáticas (100)" -> "matematicas". case, spacing, accents, underscores and max-mark suffix all ignored
function meNorm($s) {
    $s = meTrim(preg_replace('/\([^()]*\)\s*$/', '', meTrim($s)));
    $d = preg_replace('/\s*[-\x{2010}-\x{2015}\x{2212}]\s*/u', ' - ', $s);   // every dash to one shape
    if ($d !== null) $s = $d;
    $s = meStripAccents($s);
    return mb_strtolower(preg_replace('/[\s_]+/', ' ', $s), 'UTF-8');
}

// sheet columns for one subject — components win, else a split subject owns a theory + practical pair
function meSheetCols(array $sub, array $comps = []) {
    $c = meCfg($sub, $sub['cs_include'] ?? 1, $sub['sub_include'] ?? 1);
    $id = (int)$sub['id'];
    if ($comps) return array_map(function ($cp) use ($id, $sub) {
        return ['id' => $id, 'part' => 'c' . (int)$cp['id'],
                'label' => $sub['name'] . ' — ' . $cp['name'] . ' (' . meNum($cp['max_marks']) . ')'];
    }, $comps);
    return $c['split']
        ? [['id' => $id, 'part' => 't', 'label' => $sub['name'] . ' Teoría ('    . meNum($c['theory'])    . ')'],
           ['id' => $id, 'part' => 'p', 'label' => $sub['name'] . ' Práctica (' . meNum($c['practical']) . ')']]
        : [['id' => $id, 'part' => '',  'label' => $sub['name'] . ' ('           . meNum($c['total'])     . ')']];
}

// prefilled cell: AB for absent, the stored number, or blank when nothing is entered
function meSheetVal($m, $part, array $comp = []) {
    if ($part !== '' && $part[0] === 'c') {                     // component column, raw score as entered
        if ($m && (int)$m['is_absent'] === 1) return 'AB';
        $v = $comp[(int)substr($part, 1)] ?? null;
        return $v === null ? '' : meNum($v);
    }
    if (!$m) return '';
    if ((int)$m['is_absent'] === 1) return 'AB';
    if ($part === '') return $m['marks_obtained'] === null ? '' : meNum($m['marks_obtained']);
    // saved before the split was configured -> old total rides in theory, same as the grid does
    if ($m['theory_obtained'] === null && $m['practical_obtained'] === null)
        return ($part === 't' && $m['marks_obtained'] !== null) ? meNum($m['marks_obtained']) : '';
    $v = $part === 't' ? $m['theory_obtained'] : $m['practical_obtained'];
    return $v === null ? '' : meNum($v);
}

// rfc 4180 line: quote only when it matters, "" escapes a quote. Default ';' for Spanish Excel
function meCsvRow(array $r, $delim = ';') {
    $pattern = $delim === ';' ? '/[";\r\n]/' : '/[",\r\n]/';
    return implode($delim, array_map(function ($v) use ($pattern) {
        $s = (string)$v;
        return preg_match($pattern, $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }, $r)) . "\r\n";
}

// formula guard for free text — a leading =, + or @ is neutralised, never dropped
function meCsvSafe($s) {
    $s = (string)$s;
    return ($s !== '' && strpos('=+@', $s[0]) !== false) ? "'" . $s : $s;
}

// rfc 4180 parse, mirrors ORMS.parseCSV: quoted fields, "" escape, embedded commas/semicolons/newlines, crlf|lf|cr
function meParseCsv($text) {
    $rows = []; $row = []; $val = ''; $inQ = false;
    $s = (string)$text;
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);   // excel bom

    // Auto-detect delimiter from the first non-empty line (outside quotes)
    $delim = ',';
    $countSemi = 0; $countComma = 0; $inDetectQ = false;
    for ($d = 0, $dl = strlen($s); $d < $dl; $d++) {
        $dc = $s[$d];
        if ($dc === '"') $inDetectQ = !$inDetectQ;
        elseif (!$inDetectQ) {
            if ($dc === ';') $countSemi++;
            elseif ($dc === ',') $countComma++;
            elseif ($dc === "\n" || $dc === "\r") {
                if ($countSemi > 0 || $countComma > 0) break;
            }
        }
    }
    if ($countSemi > $countComma) $delim = ';';

    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($inQ) {
            if ($c !== '"') { $val .= $c; continue; }
            if (($s[$i + 1] ?? '') === '"') { $val .= '"'; $i++; } else $inQ = false;
        } elseif ($c === '"') {
            $inQ = true;
        } elseif ($c === $delim) {
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

// AB/A/ABS/ABSENT/AUSENTE/FALTA = absent · '' and a dash = no mark · trailing .0 and stray spaces tolerated · decimal comma allowed
function meCell($v) {
    $s = meTrim($v, true);
    if ($s === '' || $s === '-' || $s === '–' || $s === '—') return ['blank', null];
    if (preg_match('/^(ab|a|abs|absent|ausente|aus|falta)$/i', $s)) return ['absent', null];
    $sNum = str_replace(',', '.', $s);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $sNum)) return ['bad', null];
    return ['num', round((float)$sNum, 2)];
}

// preview and commit run the SAME path — $commit is the only difference, so they can never disagree
function meImportRun($commit, $userId, $username, $role, $isAdmin, $teacherId) {
    $sectionId = meOwn('sections',   $_POST['section_id'] ?? 0);      // foreign id -> 0 -> stops below
    $termId    = meOwn('exam_terms', $_POST['term_id'] ?? 0);
    $wantSub   = meOwn('subjects',   $_POST['subject_id'] ?? 0);
    $csv       = (string)($_POST['csv'] ?? '');
    if (meTrim($csv) === '')     jsonErr('No se cargó ninguna hoja de calificaciones.');
    if (strlen($csv) > 1048576)  jsonErr('El archivo es demasiado grande — importe una sección a la vez.');

    $term = $termId ? ormsTerm($termId) : null;
    $sec  = $sectionId ? meSection($sectionId) : null;
    if (!$term || !$sec) jsonErr('No se encontró la sección o el período seleccionado.');
    $classId = (int)$sec['class_id']; $tYear = (int)$term['academic_year_id'];

    meLockGuard($term, $sectionId);                     // year / term / publish — hard stops

    // columns resolved server-side; nothing in the uploaded file can widen this
    $subjects = meSubjectCols($sectionId, $classId, $tYear, $isAdmin, $teacherId, $wantSub);
    if (!$subjects) jsonErr('No tiene materias asignadas en esta sección, o el grado no tiene materias configuradas.');

    $comps  = meComps($classId);        // scheme -> the file carries one column per component
    $issues = [];
    $denied = array_flip(meDenied($userId, $role, $sectionId, $termId,
                                  array_map(function ($s) { return (int)$s['id']; }, $subjects)));
    foreach ($subjects as $s) if (isset($denied[(int)$s['id']]))    // reported once per column, never per row
        $issues[] = 'Columna "' . $s['name'] . '": usted no tiene permisos para calificar esta materia — fue omitida.';
    $mine = array_values(array_filter($subjects, function ($s) use ($denied) { return !isset($denied[(int)$s['id']]); }));
    if (!$mine) jsonErr('No tiene permisos para calificar ninguna materia de esta sección en este momento.');

    // exactly the labels the download writes, so a round trip always matches
    $allow = $nm = [];
    foreach ($mine as $s) {
        $nm[(int)$s['id']] = $s['name'];
        foreach (meSheetCols($s, $comps) as $c) {
            $allow[meNorm($c['label'])] = $c;
            // Also allow bilingual variants (teoria/theory, practica/practical)
            if ($c['part'] === 't') {
                $allow[meNorm($s['name'] . ' teoria')] = $c;
                $allow[meNorm($s['name'] . ' theory')] = $c;
            } elseif ($c['part'] === 'p') {
                $allow[meNorm($s['name'] . ' practica')] = $c;
                $allow[meNorm($s['name'] . ' practical')] = $c;
            } elseif ($c['part'] === '') {
                $allow[meNorm($s['name'])] = $c;
            }
        }
    }

    // every subject the class teaches, so an unknown column is told apart from a forbidden one
    $known = $ours = [];
    $scK = meSchoolSql('classes', 'c');
    foreach (qAll("SELECT sub.name FROM class_subjects cs
                   JOIN subjects sub ON sub.id = cs.subject_id
                   JOIN classes c    ON c.id = cs.class_id
                   WHERE cs.class_id = ?" . $scK,
                  'i' . meSchoolT($scK), ...array_merge([$classId], meSchoolA($scK))) as $r) {
        $n = meNorm($r['name']);
        $known[$n] = $known[$n . ' teoria'] = $known[$n . ' theory'] = $known[$n . ' practica'] = $known[$n . ' practical'] = 1;
        foreach ($comps as $cp) $known[meNorm($r['name'] . ' — ' . $cp['name'])] = 1;
    }
    foreach ($mine as $s) {
        $n = meNorm($s['name']);
        $ours[$n] = $ours[$n . ' teoria'] = $ours[$n . ' theory'] = $ours[$n . ' practica'] = $ours[$n . ' practical'] = 1;
        foreach ($comps as $cp) $ours[meNorm($s['name'] . ' — ' . $cp['name'])] = 1;
    }

    // header = first non-comment line; keys stay the source line index so errors point at the file
    $head = null; $body = [];
    foreach (meParseCsv($csv) as $i => $row) {
        $f0 = meTrim($row[0] ?? '');
        if ($f0 !== '' && $f0[0] === '#') continue;                          // comment
        if (meTrim(implode('', $row), true) === '') continue;                // blank line
        if ($head === null) { $head = $row; continue; }
        $body[$i] = $row;
    }
    if ($head === null)      jsonErr('El archivo no contiene fila de encabezados.');
    if (!$body)              jsonErr('El archivo no contiene filas de datos debajo del encabezado.');
    if (count($body) > 3000) jsonErr('Demasiadas filas — importe 3000 estudiantes o menos a la vez.');

    $admCol = -1; $map = $seen = [];
    foreach ($head as $ci => $h) {
        $n = meNorm($h);
        if ($n === '') continue;
        if (in_array($n, [
            'admission no', 'admissionno', 'admission number',
            'matricula', 'numero de matricula', 'numero matricula', 'no matricula',
            'codigo', 'id estudiante', 'identificacion', 'documento', 'documento identidad'
        ], true)) {
            if ($admCol < 0) $admCol = $ci;
            continue;
        }
        if (in_array($n, [
            'roll no', 'roll', 'student name', 'name',
            'rollo', 'numero de rollo', 'numero rollo', 'no rollo', 'lista', 'orden',
            'estudiante', 'nombre del estudiante', 'nombre estudiante', 'alumno', 'antiguo alumno', 'nombre', 'nombre completo'
        ], true)) {
            continue;   // read-only helpers
        }
        if (!isset($allow[$n])) {
            $issues[] = !isset($known[$n])
                ? 'Columna "' . meTrim($h) . '" no es una materia de esta clase — fue omitida.'
                : (isset($ours[$n])
                    ? 'Columna "' . meTrim($h) . '" no coincide con el formato de esta hoja — descargue una plantilla actualizada. Fue omitida.'
                    : 'Columna "' . meTrim($h) . '": usted no tiene permisos para calificar esta materia — fue omitida.');
            continue;
        }
        $k = $allow[$n]['id'] . $allow[$n]['part'];
        if (isset($seen[$k])) { $issues[] = 'Columna "' . meTrim($h) . '" aparece duplicada — solo se usó la primera.'; continue; }
        $seen[$k] = 1;
        $map[$ci] = $allow[$n];
    }
    if ($admCol < 0) jsonErr('La fila de encabezados debe incluir una columna "Matrícula" o "Admission No" — descargue la plantilla actualizada.');
    if (!$map)       jsonErr('Ninguna columna de materias en el archivo coincide con este grado — descargue la plantilla actualizada.');

    // a scheme subject needs ALL its component columns — a missing one would silently wipe that score
    if ($comps) {
        $need = count($comps); $got = [];
        foreach ($map as $c) $got[(int)$c['id']] = ($got[(int)$c['id']] ?? 0) + 1;
        foreach ($got as $sid => $cnt) {
            if ($cnt >= $need) continue;
            $issues[] = 'La materia "' . ($nm[$sid] ?? ('#' . $sid)) . '" necesita ' . ($need - $cnt)
                      . ' columna(s) de componentes faltantes — descargue una plantilla actualizada.';
            foreach ($map as $ci => $c) if ((int)$c['id'] === $sid) unset($map[$ci]);
        }
        if (!$map) jsonErr('El archivo no contiene las columnas completas de componentes para este grado — descargue la plantilla actualizada.');
    }

    // roster keyed by admission no, one query. status kept so "not active" gets its own message
    $byAdm = [];
    foreach (qAll("SELECT id, admission_no, status FROM students WHERE section_id = ?", 'i', $sectionId) as $r)
        $byAdm[mb_strtolower(meTrim($r['admission_no']))] = $r;

    $subjIds = array_values(array_unique(array_map(function ($c) { return (int)$c['id']; }, $map)));
    $ctx     = meCtx($term, $sec, $subjIds, $userId);

    $rows = []; $dup = []; $miss = []; $skipParse = 0; $matched = 0;
    foreach ($body as $i => $row) {
        $at  = 'Fila ' . ($i + 1);
        $adm = meTrim($row[$admCol] ?? '');
        if ($adm === '')  { $issues[] = $at . ': número de matrícula vacío — fila omitida.'; $skipParse++; continue; }
        $k = mb_strtolower($adm);
        if (isset($dup[$k])) { $issues[] = $at . ': la matrícula ' . $adm . ' está repetida en el archivo — solo se procesó la primera.'; $skipParse++; continue; }
        $dup[$k] = 1;
        $st = $byAdm[$k] ?? null;
        if (!$st)                      { $miss[$k] = [$at, $adm]; $skipParse++; continue; }
        if ($st['status'] !== 'Active') { $issues[] = $at . ': el estudiante ' . $adm . ' tiene estado ' . $st['status'] . ' — solo se pueden ingresar notas a estudiantes activos.'; $skipParse++; continue; }
        $stu = (int)$st['id'];
        $matched++;

        $cells = [];
        foreach ($map as $ci => $c) $cells[$c['id']][$c['part']] = $row[$ci] ?? '';

        foreach ($cells as $sid => $parts) {
            $sid   = (int)$sid;
            $who   = $at . ' · ' . $adm . ' · ' . ($ctx['names'][$sid] ?? ('#' . $sid));
            $takes = !isset($ctx['enrol'][$sid]) || isset($ctx['enrol'][$sid][$stu]);
            $has   = isset($ctx['exist'][$stu . ':' . $sid]);

            if ($comps) {
                // every component column of the subject, AB in any one of them makes the whole cell absent
                $cAbs = false; $anyNum = false; $badName = ''; $cvals = [];
                foreach ($comps as $cp) {
                    $cid = (int)$cp['id'];
                    [$cst, $cvl] = meCell($parts['c' . $cid] ?? '');
                    if ($cst === 'bad')    { $badName = $cp['name']; break; }
                    if ($cst === 'absent') { $cAbs = true; $cvals[$cid] = null; continue; }
                    if ($cst === 'num')    $anyNum = true;
                    $cvals[$cid] = $cst === 'num' ? $cvl : null;
                }
                if ($badName !== '') { $issues[] = $who . ': ' . $badName . ' debe ser un número o AB (ausente).'; $skipParse++; continue; }
                if ($cAbs && $anyNum) { $issues[] = $who . ': un componente indica ausente y otro tiene calificación numérica.'; $skipParse++; continue; }
                // nothing in the file and nothing stored (or a subject they don't take) is simply not a change
                if (!$cAbs && !$anyNum && (!$takes || !$has)) continue;
                $rows[] = ['student_id' => $stu, 'subject_id' => $sid, 'at' => $at,
                           'is_absent' => $cAbs ? 1 : 0, 'comp' => $cvals];
                continue;
            }

            if (empty($ctx['cfg'][$sid]['split'])) {
                [$state, $val] = meCell($parts[''] ?? '');
                if ($state === 'bad') { $issues[] = $who . ': "' . meTrim($parts[''] ?? '') . '" no es un número válido ni AB (ausente).'; $skipParse++; continue; }
                // blank/dash with nothing stored (or a subject they don't take) is simply not a change
                if ($state === 'blank' && (!$takes || !$has)) continue;
                $rows[] = ['student_id' => $stu, 'subject_id' => $sid, 'at' => $at,
                           'is_absent' => $state === 'absent' ? 1 : 0, 'marks_obtained' => $val];
                continue;
            }

            [$ts, $tv] = meCell($parts['t'] ?? '');
            [$ps, $pv] = meCell($parts['p'] ?? '');
            if ($ts === 'bad' || $ps === 'bad') { $issues[] = $who . ': teoría y práctica deben ser un número o AB (ausente).'; $skipParse++; continue; }
            $abs = ($ts === 'absent' || $ps === 'absent');
            if ($abs && ($ts === 'num' || $ps === 'num')) { $issues[] = $who . ': una parte indica ausente y la otra tiene calificación numérica.'; $skipParse++; continue; }
            if (!$abs && $ts !== 'num' && $ps !== 'num' && (!$takes || !$has)) continue;
            $rows[] = ['student_id' => $stu, 'subject_id' => $sid, 'at' => $at,
                       'is_absent' => $abs ? 1 : 0, 'theory' => $tv, 'practical' => $pv];
        }
    }

    // one lookup tells "not in this section" apart from "no such admission no"
    if ($miss) {
        $found = [];
        $vals  = array_map(function ($m) { return $m[1]; }, $miss);
        $scS = meSchoolSql('students', 'st');
        foreach (qAll("SELECT st.admission_no FROM students st
                       WHERE st.admission_no IN (" . meIn($vals) . ")" . $scS,
                      str_repeat('s', count($vals)) . meSchoolT($scS),
                      ...array_merge(array_values($vals), meSchoolA($scS))) as $r)
            $found[mb_strtolower($r['admission_no'])] = 1;
        foreach ($miss as $k => $m)
            $issues[] = $m[0] . ': ' . (isset($found[$k]) ? 'el estudiante con matrícula ' . $m[1] . ' no pertenece a esta sección.'
                                                          : 'la matrícula ' . $m[1] . ' no fue encontrada en el sistema.');
    }

    $res     = mePersist($ctx, $rows, !$commit);          // dry on preview, same decisions either way
    $issues  = array_merge($issues, $res['errors']);
    $skipped = $skipParse + $res['skipped'];
    $changes = $res['added'] + $res['updated'] + $res['cleared'];
    $shown   = array_slice($issues, 0, 200);
    $more    = max(0, count($issues) - 200);

    if (!$commit) {
        jsonOk(['added' => $res['added'], 'updated' => $res['updated'], 'cleared' => $res['cleared'],
                'skipped' => $skipped, 'students' => $matched, 'changes' => $changes,
                'issues' => $shown, 'more' => $more,
                'message' => $changes . ($changes === 1 ? ' calificación lista' : ' calificaciones listas') . ' para guardar'
                           . ($skipped ? ', ' . $skipped . ' omitidas' : '')]);
    }

    // ONE log for the whole import, never one per mark
    logActivity($userId, $username, 'Marks Imported',
        'Score sheet import: ' . $sec['class_name'] . '-' . $sec['section_name'] . ', ' . $term['name']
        . ' — ' . $res['added'] . ' added, ' . $res['updated'] . ' updated, ' . $res['cleared'] . ' cleared, '
        . $skipped . ' skipped');

    jsonOk(['imported' => $res['saved'], 'added' => $res['added'], 'updated' => $res['updated'],
            'cleared' => $res['cleared'], 'skipped' => $skipped, 'errors' => $shown, 'more' => $more,
            'message' => $res['saved'] . ($res['saved'] === 1 ? ' nota importada' : ' notas importadas')
                       . ($skipped ? ', ' . $skipped . ' omitidas' : '')]);
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {

            // ---- landing cards: one per (class, section, subject) a user may touch
            case 'getAssignments':
                requireCsrfJson();
                requirePermJson('marks_entry', 'v');

                $termId = meOwn('exam_terms', $_POST['term_id'] ?? 0);   // another school's term -> 0
                $term   = $termId ? ormsTerm($termId) : null;
                if (!$term) jsonErr('Pick an exam term first.');
                $tYear = (int)$term['academic_year_id'];
                $open  = (($term['status'] ?? '') === 'Open');
                $yl    = meYearLock($tYear);        // year closed -> nothing in it is editable
                $ylMsg = 'Academic year "' . $yl['name'] . '" is locked — marks for this year can no longer be entered or edited.';

                $cards = [];
                if ($isAdmin) {
                    $fClass   = meOwn('classes',  $_POST['class_id'] ?? 0);      // foreign filter id -> 0 -> no filter
                    $fSection = meOwn('sections', $_POST['section_id'] ?? 0);
                    $fSubject = meOwn('subjects', $_POST['subject_id'] ?? 0);

                    // tenant filter on the OUTER driver, not just on the counts below
                    $scC = meSchoolSql('classes', 'c');
                    $sql = "SELECT s.id AS section_id, s.class_id, s.name AS section_name, c.name AS class_name
                            FROM sections s JOIN classes c ON c.id = s.class_id
                            WHERE s.is_active = 1 AND c.is_active = 1" . $scC;
                    $types = meSchoolT($scC); $args = meSchoolA($scC);   // school first, then the pickers
                    if ($fClass)   { $sql .= " AND s.class_id = ?"; $types .= 'i'; $args[] = $fClass; }
                    if ($fSection) { $sql .= " AND s.id = ?";       $types .= 'i'; $args[] = $fSection; }
                    $sql .= " ORDER BY c.sort_order ASC, c.name ASC, s.name ASC";
                    $secs = qAll($sql, $types, ...$args);

                    // all class_subjects in scope in ONE query, grouped in memory (no n+1)
                    $classIds = array_values(array_unique(array_map(function ($s) { return (int)$s['class_id']; }, $secs)));
                    $byClass  = [];
                    if ($classIds) {
                        $sql2 = "SELECT cs.class_id, cs.subject_id, sub.name AS subject_name
                                 FROM class_subjects cs JOIN subjects sub ON sub.id = cs.subject_id
                                 WHERE sub.is_active = 1 AND cs.class_id IN (" . meIn($classIds) . ")";
                        $t2 = str_repeat('i', count($classIds)); $a2 = $classIds;
                        if ($fSubject) { $sql2 .= " AND cs.subject_id = ?"; $t2 .= 'i'; $a2[] = $fSubject; }
                        $sql2 .= " ORDER BY cs.sort_order ASC, sub.name ASC";
                        foreach (qAll($sql2, $t2, ...$a2) as $r) $byClass[(int)$r['class_id']][] = $r;
                    }

                    foreach ($secs as $s) {
                        $subs = $byClass[(int)$s['class_id']] ?? [];
                        if (!$subs) continue; // class has no subjects configured yet
                        $one = (count($subs) === 1);
                        $cards[] = [
                            'section_id'    => (int)$s['section_id'],
                            'class_id'      => (int)$s['class_id'],
                            'class_name'    => $s['class_name'],
                            'section_name'  => $s['section_name'],
                            'subject_id'    => $one ? (int)$subs[0]['subject_id'] : 0, // 0 = all class subjects
                            'subject_name'  => $one ? $subs[0]['subject_name'] : (count($subs) . ' subjects'),
                            'subject_count' => count($subs),
                            'subject_ids'   => array_map(function ($x) { return (int)$x['subject_id']; }, $subs)
                        ];
                    }
                } else {
                    if (!$teacherId) jsonOk(['cards' => [], 'no_teacher' => true,
                        'term' => $term + ['year_locked' => $yl['locked'] ? 1 : 0, 'year_lock' => $yl['locked'] ? $ylMsg : '']]);
                    foreach (ormsTeacherAssignments($teacherId, $tYear) as $a) {
                        $cards[] = [
                            'section_id'    => (int)$a['section_id'],
                            'class_id'      => (int)$a['class_id'],
                            'class_name'    => $a['class_name'],
                            'section_name'  => $a['section_name'],
                            'subject_id'    => (int)$a['subject_id'],
                            'subject_name'  => $a['subject_name'],
                            'subject_count' => 1,
                            'subject_ids'   => [(int)$a['subject_id']]
                        ];
                    }
                }

                // counts for every card in a handful of grouped queries, then O(1) map lookups
                $secIds = array_values(array_unique(array_map(function ($c) { return $c['section_id']; }, $cards)));
                $stuCnt = $mkCnt = $pubMap = $optSet = $enrCnt = [];
                if ($secIds) {
                    $ph = meIn($secIds); $t = str_repeat('i', count($secIds));
                    foreach (qAll("SELECT section_id, COUNT(*) AS c FROM students
                                   WHERE status = 'Active' AND section_id IN ($ph) GROUP BY section_id", $t, ...$secIds) as $r)
                        $stuCnt[(int)$r['section_id']] = (int)$r['c'];
                    // same counting rule as the engine: live class_subject + active student still in this section + a real value
                    $scM = meMarksScope();      // its ? sits between the term and the section IN list
                    foreach (qAll("SELECT m.section_id, m.subject_id, COUNT(*) AS c FROM marks m
                                   JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                                   JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                                   WHERE m.term_id = ?
                                     AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . $scM . "
                                     AND m.section_id IN ($ph)
                                   GROUP BY m.section_id, m.subject_id",
                                  'i' . meSchoolT($scM) . $t,
                                  ...array_merge([$termId], meSchoolA($scM), $secIds)) as $r)
                        $mkCnt[$r['section_id'] . ':' . $r['subject_id']] = (int)$r['c'];
                    foreach (qAll("SELECT section_id FROM result_publications
                                   WHERE is_published = 1 AND term_id = ? AND section_id IN ($ph)", 'i' . $t, $termId, ...$secIds) as $r)
                        $pubMap[(int)$r['section_id']] = true;
                    // an optional subject expects only its enrolled students
                    if (ormsHasElectives()) {
                        $scO = meSchoolSql('classes', 'c');
                        foreach (qAll("SELECT cs.class_id, cs.subject_id FROM class_subjects cs
                                       JOIN classes c ON c.id = cs.class_id
                                       WHERE cs.is_optional = 1" . $scO,
                                      meSchoolT($scO), ...meSchoolA($scO)) as $r)
                            $optSet[$r['class_id'] . ':' . $r['subject_id']] = 1;
                        if ($optSet) {
                            foreach (qAll("SELECT st.section_id, ss.subject_id, COUNT(*) AS c FROM student_subjects ss
                                           JOIN students st ON st.id = ss.student_id AND st.status = 'Active' AND st.section_id IN ($ph)
                                           WHERE ss.academic_year_id = ?
                                           GROUP BY st.section_id, ss.subject_id",
                                          $t . 'i', ...array_merge($secIds, [$tYear])) as $r)
                                $enrCnt[$r['section_id'] . ':' . $r['subject_id']] = (int)$r['c'];
                        }
                    }
                }

                foreach ($cards as &$c) {
                    $stu = $stuCnt[$c['section_id']] ?? 0;
                    $ent = 0; $exp = 0;
                    foreach ($c['subject_ids'] as $sid) {
                        $ent += $mkCnt[$c['section_id'] . ':' . $sid] ?? 0;
                        $exp += isset($optSet[$c['class_id'] . ':' . $sid])
                              ? ($enrCnt[$c['section_id'] . ':' . $sid] ?? 0)   // elective -> its enrolment
                              : $stu;
                    }
                    $c['students']  = $stu;
                    $c['expected']  = $exp;
                    $c['entered']   = min($ent, $c['expected']);   // stale rows can never push past 100%
                    $c['published'] = !empty($pubMap[$c['section_id']]);
                    $c['term_name'] = $term['name'];
                    $c['can_enter'] = $open && !$yl['locked'] && !$c['published'] && $canAdd && $stu > 0;
                    $c['lock']      = $yl['locked'] ? $ylMsg
                                    : (!$open      ? 'Term "' . $term['name'] . '" is ' . $term['status'] . ' — entry is closed.'
                                    : ($c['published'] ? 'Results are published — unpublish to edit.'
                                    : ($stu === 0     ? 'No active students in this section.'
                                    : (!$canAdd       ? 'You have view-only access to marks entry.' : ''))));
                    unset($c['subject_ids']);
                }
                unset($c);

                jsonOk(['cards' => $cards, 'term' => ['id' => (int)$term['id'], 'name' => $term['name'], 'status' => $term['status'],
                                                      'year_locked' => $yl['locked'] ? 1 : 0, 'year_lock' => $yl['locked'] ? $ylMsg : '']]);

            // ---- everything the popup needs in ONE request
            case 'getGrid':
                requireCsrfJson();
                requirePermJson('marks_entry', 'v');

                // every id off the request is resolved first — a foreign one comes back 0 and stops here
                $sectionId = meOwn('sections',   $_POST['section_id'] ?? 0);
                $termId    = meOwn('exam_terms', $_POST['term_id'] ?? 0);
                $wantSub   = meOwn('subjects',   $_POST['subject_id'] ?? 0);

                $term = $termId ? ormsTerm($termId) : null;
                $sec  = $sectionId ? meSection($sectionId) : null;
                if (!$term || !$sec) jsonErr('Section or term not found.');

                $classId = (int)$sec['class_id'];
                $tYear   = (int)$term['academic_year_id'];

                // columns resolved server-side — a teacher can never widen this by posting a subject id
                $subjects = meSubjectCols($sectionId, $classId, $tYear, $isAdmin, $teacherId, $wantSub);
                if (!$subjects) jsonErr('You have no subject assigned for this section, or the class has no marks configuration.');

                $published = ormsIsPublished($termId, $sectionId);
                $termOpen  = (($term['status'] ?? '') === 'Open');
                $yl        = meYearLock($tYear);
                $locked    = $published || !$termOpen || $yl['locked'];

                $canSave = !$locked && $canAdd;
                if ($canSave) {
                    foreach ($subjects as $s) {
                        if (!ormsCanEnterMarks($user_id, $role, $sectionId, (int)$s['id'], $termId)) { $canSave = false; break; }
                    }
                }
                // widest reason first — unpublishing cannot reopen a closed year
                $lockMsg = $yl['locked'] ? 'Academic year "' . $yl['name'] . '" is locked — marks for this year can no longer be entered or edited. An admin must unlock the year in Result Settings.'
                         : ($published ? 'Results for this section are published — marks are locked. An admin must unpublish to edit.'
                         : (!$termOpen ? 'Term "' . $term['name'] . '" is ' . $term['status'] . ' — marks entry is closed for this term.'
                         : (!$canSave ? 'You have view-only access to these marks.' : '')));

                // roster, numeric roll first, blanks last
                $students = qAll("SELECT st.id, st.roll_no, st.admission_no, u.full_name, u.username, u.profile_image
                                  FROM students st JOIN users u ON u.id = st.user_id
                                  WHERE st.section_id = ? AND st.status = 'Active'
                                  ORDER BY (st.roll_no IS NULL OR st.roll_no = '') ASC,
                                           CAST(st.roll_no AS UNSIGNED) ASC, st.roll_no ASC, u.full_name ASC", 'i', $sectionId);

                $subIds = array_map(function ($s) { return (int)$s['id']; }, $subjects);
                $marks  = [];
                foreach (qAll("SELECT student_id, subject_id, marks_obtained, is_absent, grade,
                                      remarks, theory_obtained, practical_obtained FROM marks
                               WHERE term_id = ? AND section_id = ? AND subject_id IN (" . meIn($subIds) . ")",
                               'ii' . str_repeat('i', count($subIds)), $termId, $sectionId, ...$subIds) as $m) {
                    $marks[$m['student_id'] . ':' . $m['subject_id']] = [
                        'm' => $m['marks_obtained'] === null ? null : (float)$m['marks_obtained'],
                        'a' => (int)$m['is_absent'],
                        'g' => $m['grade'],
                        'r' => (string)($m['remarks'] ?? ''),
                        't' => $m['theory_obtained']    === null ? null : (float)$m['theory_obtained'],
                        'p' => $m['practical_obtained'] === null ? null : (float)$m['practical_obtained']
                    ];
                }

                // components beat the theory/practical split for every subject of this class
                $sc      = meScheme($classId);
                $comps   = $sc['comps'];
                $wt      = $sc['weight'];
                $compMap = meCompMap($sectionId, $termId, $subIds, $comps);
                foreach ($compMap as $k => $v) $compMap[$k] = (object)$v;   // one object per cell for the client

                $bands = [];
                foreach (meBands($classId) as $b) {
                    $bands[] = ['grade' => $b['grade'], 'min' => (float)$b['min_percent'], 'point' => (float)$b['grade_point']];
                }

                // optional-subject enrolment: subject_id => [student ids] — the grid greys out the rest
                $optIds = [];
                foreach ($subjects as $s) if ((int)($s['is_optional'] ?? 0) === 1) $optIds[] = (int)$s['id'];
                $enrol = meEnrolMap($optIds, $tYear, $sectionId);              // no rows = restricted to nobody
                foreach ($enrol as $k => $v) $enrol[$k] = array_keys($v);      // client wants a plain id list

                jsonOk([
                    'section'  => ['id' => (int)$sec['id'], 'class_id' => $classId,
                                   'class_name' => $sec['class_name'], 'section_name' => $sec['section_name']],
                    'term'     => ['id' => (int)$term['id'], 'name' => $term['name'], 'status' => $term['status']],
                    'enrol'    => (object)$enrol,
                    'subjects' => array_map(function ($s) use ($comps, $wt) {
                                      $c  = meCfg($s, $s['cs_include'] ?? 1, $s['sub_include'] ?? 1);
                                      $w  = $comps ? meWeighted($c, $wt) : null;   // scheme -> marked out of the weight sum
                                      $sp = !$w && $c['split'];                    // split only survives without a scheme
                                      return ['id' => (int)$s['id'], 'name' => $s['name'], 'code' => $s['code'],
                                              'total' => $w ? $w['total'] : $c['total'],
                                              'pass'  => $w ? $w['pass']  : $c['pass'],
                                              'theory' => $sp ? $c['theory'] : 0,
                                              'practical' => $sp ? $c['practical'] : 0,
                                              'split' => $sp ? 1 : 0, 'excl' => $c['excl'] ? 1 : 0,
                                              'scheme' => $w ? 1 : 0, 'raw_split' => $c['split'] ? 1 : 0,
                                              'raw_total' => $c['total'],
                                              'optional' => $c['optional'] ? 1 : 0];
                                  }, $subjects),
                    'students' => array_map(function ($s) use ($DEFAULT_AVATAR) {
                                      return ['id' => (int)$s['id'], 'roll' => $s['roll_no'], 'adm' => $s['admission_no'],
                                              'name' => $s['full_name'] !== null && $s['full_name'] !== '' ? $s['full_name'] : $s['username'],
                                              'photo' => $s['profile_image'] ?: $DEFAULT_AVATAR];
                                  }, $students),
                    'marks'    => (object)$marks,
                    'comp'     => (object)$compMap,
                    'scheme'   => $comps ? array_merge(meSchemeInfo($classId), ['components' =>
                                      array_map(function ($c) {
                                          return ['id' => (int)$c['id'], 'name' => $c['name'],
                                                  'max' => (float)$c['max_marks'], 'w' => (float)$c['weight_percent'],
                                                  'exam' => (int)$c['is_exam']];
                                      }, $comps)]) : null,
                    'grading'  => $bands,
                    'locked'   => $locked || !$canSave,
                    'lock'     => $lockMsg,
                    'can_save' => $canSave
                ]);

            // ---- bulk save: gate -> validate -> ONE transaction, ONE prepared upsert, ONE log
            case 'saveMarks':
                requireCsrfJson();                                   // 1. csrf
                requirePermJson('marks_entry', 'a');                 // 2. rbac add/edit

                $termId    = meOwn('exam_terms', $_POST['term_id'] ?? 0);
                $sectionId = meOwn('sections',   $_POST['section_id'] ?? 0);
                $rows      = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($rows) || !$rows) jsonErr('Nothing to save.');
                if (count($rows) > 3000)        jsonErr('Too many rows in one request.');

                $term = $termId ? ormsTerm($termId) : null;
                $sec  = $sectionId ? meSection($sectionId) : null;
                if (!$term || !$sec) jsonErr('Section or term not found.');

                // subject ids ride in the payload — deduped FIRST, then resolved, so the tenant lookup
                // runs once per distinct subject and never once per row
                $subjIds = [];
                foreach ($rows as $r) { $s = (int)($r['subject_id'] ?? 0); if ($s > 0) $subjIds[$s] = true; }
                $subjIds = array_values(array_filter(array_map(fn($s) => meOwn('subjects', $s), array_keys($subjIds))));
                if (!$subjIds) jsonErr('Nothing to save.');

                // 3. explicit lock re-check, then ownership on every distinct subject — never trust the disabled ui
                meLockGuard($term, $sectionId);
                if (meDenied($user_id, $role, $sectionId, $termId, $subjIds))
                    jsonErr('You are not allowed to enter marks for one or more of these subjects.');

                // 4. config snapshot + enrolment + roster + bands, then the one shared write path
                $ctx = meCtx($term, $sec, $subjIds, $user_id);
                $res = mePersist($ctx, $rows);

                // 5. ONE log entry for the whole batch
                $subjLabel = implode(', ', array_map(function ($id) use ($ctx) { return $ctx['names'][$id] ?? ('#' . $id); }, $subjIds));
                logActivity($user_id, $username, 'Bulk Marks Saved',
                    'Bulk marks: ' . $subjLabel . ', Class ' . $sec['class_name'] . '-' . $sec['section_name']
                    . ', ' . $term['name'] . ' — ' . $res['saved'] . ' saved' . ($res['skipped'] ? ', ' . $res['skipped'] . ' skipped' : ''));

                // 6. result contract
                jsonOk([
                    'saved'   => $res['saved'],
                    'skipped' => $res['skipped'],
                    'errors'  => $res['errors'],
                    'bad'     => $res['bad'],
                    'message' => $res['saved'] . ' mark' . ($res['saved'] === 1 ? '' : 's') . ' saved'
                               . ($res['skipped'] ? ', ' . $res['skipped'] . ' skipped' : '')
                ]);

            // ---- offline round trip: csv out, csv back in ----
            case 'downloadSheet':
                requireCsrfJson();
                requirePermJson('marks_entry', 'v');

                $sectionId = meOwn('sections',   $_POST['section_id'] ?? 0);
                $termId    = meOwn('exam_terms', $_POST['term_id'] ?? 0);
                $wantSub   = meOwn('subjects',   $_POST['subject_id'] ?? 0);

                $term = $termId ? ormsTerm($termId) : null;
                $sec  = $sectionId ? meSection($sectionId) : null;
                if (!$term || !$sec) jsonErr('Section or term not found.');
                $classId = (int)$sec['class_id'];
                $tYear   = (int)$term['academic_year_id'];

                // same column resolver the grid uses — teacher gets their own subjects, admin gets all
                $subjects = meSubjectCols($sectionId, $classId, $tYear, $isAdmin, $teacherId, $wantSub);
                if (!$subjects) jsonErr('You have no subject assigned for this section, or the class has no marks configuration.');

                $comps = meComps($classId);                   // scheme -> one column per component
                $cols  = [];
                foreach ($subjects as $s) foreach (meSheetCols($s, $comps) as $c) $cols[] = $c;

                // roster, numeric roll first, blanks last — same order as the grid
                $students = qAll("SELECT st.id, st.roll_no, st.admission_no, u.full_name, u.username
                                  FROM students st JOIN users u ON u.id = st.user_id
                                  WHERE st.section_id = ? AND st.status = 'Active'
                                  ORDER BY (st.roll_no IS NULL OR st.roll_no = '') ASC,
                                           CAST(st.roll_no AS UNSIGNED) ASC, st.roll_no ASC, u.full_name ASC", 'i', $sectionId);

                $subIds = array_map(function ($s) { return (int)$s['id']; }, $subjects);
                $marks  = [];
                foreach (qAll("SELECT student_id, subject_id, marks_obtained, is_absent, theory_obtained, practical_obtained
                               FROM marks WHERE term_id = ? AND section_id = ? AND subject_id IN (" . meIn($subIds) . ")",
                              'ii' . str_repeat('i', count($subIds)), $termId, $sectionId, ...$subIds) as $m)
                    $marks[$m['student_id'] . ':' . $m['subject_id']] = $m;
                $cmk = meCompMap($sectionId, $termId, $subIds, $comps);   // raw component scores, ONE query

                // electives: only the enrolled get a fillable cell, everyone else carries the dash marker
                $optIds = [];
                foreach ($subjects as $s) if ((int)($s['is_optional'] ?? 0) === 1) $optIds[] = (int)$s['id'];
                $enrol = meEnrolMap($optIds, $tYear, $sectionId);

                // comment lines stay comma/semi-free so excel keeps them in one cell
                $out = '# Hoja de Calificaciones ORMS | ' . $sec['class_name'] . ' - ' . $sec['section_name']
                     . ' | ' . $term['name'] . ' | Generado: ' . date('Y-m-d') . "\r\n"
                     . "# Ingrese las notas en las columnas de materias. Use AB o Ausente para ausencias. Deje en blanco para borrar.\r\n"
                     . "# Un guion (-) indica que el estudiante no cursa esa materia optativa — no lo modifique.\r\n"
                     . "# No modifique la columna Matricula ni los encabezados de las materias.\r\n"
                     . ($comps ? "# Esta clase califica por componentes — ingrese la puntuacion en cada columna de componente.\r\n" : '');
                $out .= meCsvRow(array_merge(['Matrícula', 'Rollo', 'Estudiante'],
                                             array_map(function ($c) { return $c['label']; }, $cols)), ';');

                foreach ($students as $st) {
                    $stu  = (int)$st['id'];
                    $line = [$st['admission_no'], (string)$st['roll_no'],
                             meCsvSafe(($st['full_name'] !== null && $st['full_name'] !== '') ? $st['full_name'] : $st['username'])];
                    foreach ($cols as $c) {
                        $line[] = (isset($enrol[$c['id']]) && !isset($enrol[$c['id']][$stu]))
                                ? '-' : meSheetVal($marks[$stu . ':' . $c['id']] ?? null, $c['part'],
                                                   $cmk[$stu . ':' . $c['id']] ?? []);
                    }
                    $out .= meCsvRow($line, ';');
                }

                $slug = function ($s) { return trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$s), '-'); };
                $file = 'Hoja_Calificaciones_' . $slug($sec['class_name'] . '-' . $sec['section_name']) . '_' . $slug($term['name']) . '.csv';

                header('Content-Type: text/csv; charset=utf-8');     // replaces the json header set above
                header('Content-Disposition: attachment; filename="' . $file . '"');
                header('Cache-Control: no-store');
                echo "\xEF\xBB\xBF" . $out;                          // bom = excel reads utf8 names right
                exit();

            case 'importMarksPreview':
                requireCsrfJson();
                requirePermJson('marks_entry', 'a');
                meImportRun(false, $user_id, $username, $role, $isAdmin, $teacherId);

            case 'importMarks':
                requireCsrfJson();
                requirePermJson('marks_entry', 'a');
                meImportRun(true, $user_id, $username, $role, $isAdmin, $teacherId);

            default:
                jsonErr('Invalid action');
        }
    } catch (Throwable $e) {
        error_log('marks_entry.php error: ' . $e->getMessage());
        jsonErr('Could not complete that request');     // real reason stays in the log, never on the wire
    }
}

// admin filter sources — small lists, embedded once so class->section->subject chains need no extra ajax
$adminClasses = $adminSections = $adminSubjects = [];
if ($isAdmin) {
    // every picker rides the classes join — another school's names never reach the dropdowns
    $scP = meSchoolSql('classes', 'c');  $tP = meSchoolT($scP);  $aP = meSchoolA($scP);
    $adminClasses  = qAll("SELECT c.id, c.name FROM classes c
                           WHERE c.is_active = 1" . $scP . " ORDER BY c.sort_order ASC, c.name ASC", $tP, ...$aP);
    $adminSections = qAll("SELECT s.id, s.class_id, s.name FROM sections s
                           JOIN classes c ON c.id = s.class_id
                           WHERE s.is_active = 1" . $scP . " ORDER BY s.name ASC", $tP, ...$aP);
    $adminSubjects = qAll("SELECT cs.class_id, sub.id, sub.name
                           FROM class_subjects cs
                           JOIN subjects sub ON sub.id = cs.subject_id
                           JOIN classes c    ON c.id = cs.class_id
                           WHERE sub.is_active = 1" . $scP . " ORDER BY cs.sort_order ASC, sub.name ASC", $tP, ...$aP);
}

$openTermId = 0;
foreach ($terms as $t) { if ($t['status'] === 'Open') { $openTermId = (int)$t['id']; break; } }
if (!$openTermId && $terms) $openTermId = (int)$terms[0]['id'];

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
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
    <title>Marks Entry - Result Management System</title>

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
                    <h1><i class="fas fa-pen-to-square"></i> Bulk Marks Entry</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>Marks Entry</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <?php if (!$year || !$terms): ?>
            <div class="data-section">
                <div class="orms-empty">
                    <i class="fas fa-calendar-xmark"></i>
                    <h4>No academic year or exam term found</h4>
                    <p>Set a current academic year and at least one exam term in Result Settings before entering marks.</p>
                </div>
            </div>
            <?php else: ?>

            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-list-check"></i> My Assignments</h2>
                    <div class="btn-group-inline">
                        <button class="btn btn-primary" id="refreshBtn" onclick="loadAssignments(this)">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <div class="filters-header">
                        <h3><i class="fas fa-filter"></i> Term &amp; Scope</h3>
                        <?php if ($isAdmin): ?>
                        <button class="btn btn-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear All</button>
                        <?php endif; ?>
                    </div>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-check"></i> Exam Term</label>
                            <select id="filterTerm" class="filter-input">
                                <?php foreach ($terms as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>"<?php echo ((int)$t['id'] === $openTermId ? ' selected' : ''); ?>>
                                    <?php echo htmlspecialchars($t['name'] . ' — ' . $t['status']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isAdmin): ?>
                        <div class="filter-group">
                            <label><i class="fas fa-chalkboard"></i> Class</label>
                            <select id="filterClass" class="filter-input">
                                <option value="">All Classes</option>
                                <?php foreach ($adminClasses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-layer-group"></i> Section</label>
                            <select id="filterSection" class="filter-input"><option value="">All Sections</option></select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-book"></i> Subject</label>
                            <select id="filterSubject" class="filter-input"><option value="">All Subjects</option></select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- one tab per section of the chosen class; hidden when there is only one -->
                <div class="tab-nav initially-hidden" id="meSecTabs" role="tablist"></div>

                <div class="stat-mini" id="scopeStats">
                    <div><i class="fas fa-calendar-days"></i> Year <b><?php echo htmlspecialchars($year['name']); ?></b></div>
                    <div><i class="fas fa-clipboard-list"></i> Assignments <b id="statCards">0</b></div>
                    <div><i class="fas fa-users"></i> Students <b id="statStudents">0</b></div>
                    <div><i class="fas fa-pen"></i> Entered <b id="statEntered">0</b> / <span id="statExpected">0</span></div>
                </div>

                <div class="marks-locked-banner initially-hidden" id="termBanner">
                    <i class="fas fa-lock"></i>
                    <span id="termBannerText"></span>
                </div>

                <!-- skeleton while the first fetch runs -->
                <div id="assignSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-2"></div>
                            <div class="skeleton skeleton-table-cell skeleton-flex-1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="initially-hidden" id="assignTableWrap">
                    <div class="table-scroll-hint">
                        <i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns
                    </div>
                    <div class="table-responsive">
                        <table id="assignTable" class="display table-full-width"></table>
                    </div>
                </div>
                <div class="initially-hidden" id="assignEmpty"></div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- Bulk marks popup -->
    <div class="modal-overlay" id="marksModal">
        <div class="modal marks-modal" onclick="event.stopPropagation()">
            <div class="marks-modal-head">
                <h3 id="marksTitle"><i class="fas fa-pen-to-square"></i> Calificaciones</h3>
                <button class="close-btn" onclick="closeMarks()" title="Cerrar"><i class="fas fa-times"></i></button>
            </div>

            <!-- toolbar lives between head and body so it never fights the sticky thead -->
            <div class="marks-toolbar" id="marksToolbar"></div>

            <!-- one line so the offline round trip is discoverable, not a hidden feature -->
            <div class="info-banner marks-help initially-hidden" id="sheetHelp">
                <i class="fas fa-cloud-arrow-down"></i>
                <span id="sheetHelpText"></span>
            </div>

            <div class="marks-locked-banner initially-hidden" id="marksLock">
                <i class="fas fa-lock"></i>
                <span id="marksLockText"></span>
            </div>

            <div class="marks-modal-body" id="marksBody">
                <table class="marks-grid" id="marksGrid">
                    <thead><tr id="marksHead"></tr></thead>
                    <tbody id="marksRows"></tbody>
                </table>
            </div>

            <div class="marks-modal-foot">
                <div class="marks-footer-summary" id="marksSummary"></div>
                <div class="btn-group-inline">
                    <button class="btn btn-secondary" onclick="closeMarks()"><i class="fas fa-times"></i> Cerrar</button>
                    <button class="btn btn-success" id="saveBtn" onclick="saveMarks(this)"><i class="fas fa-save"></i> Guardar Todo</button>
                </div>
            </div>
        </div>
    </div>

    <!-- score sheet download: posts into a hidden frame so the attachment never unloads this page -->
    <form id="sheetForm" method="post" action="marks_entry.php?action=downloadSheet" target="sheetFrame" class="initially-hidden">
        <input type="hidden" name="csrf_token">
        <input type="hidden" name="section_id">
        <input type="hidden" name="term_id">
        <input type="hidden" name="subject_id">
    </form>
    <iframe id="sheetFrame" name="sheetFrame" title="Score sheet download" class="initially-hidden"></iframe>
    <input type="file" id="sheetCsvInput" accept=".csv,text/csv" class="initially-hidden">

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
    // lazy pdf deps — only fetched when someone actually exports
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
    var IS_ADMIN  = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    var CAN_ADD   = <?php echo $canAdd ? 'true' : 'false'; ?>;
    var SECTIONS  = <?php echo json_encode($adminSections, $jsonFlags); ?>;
    var SUBJECTS  = <?php echo json_encode($adminSubjects, $jsonFlags); ?>;
    var DEF_AVATAR = <?php echo json_encode($DEFAULT_AVATAR, $jsonFlags); ?>;
    var GRID = null;   // popup state: subjects, students, inputs matrix, dirty flag
    var assignTable = null;   // landing datatable
    var WANT_SUB = 0;  // subject the popup was opened for — 0 = every subject of the section

    function esc(s) { return ORMS.esc(s); }
    function el(id) { return document.getElementById(id); }
    // class toggle, never element.style — jquery show() would flatten grid/flex to block
    function show(sel, on) { $(sel).toggleClass('initially-hidden', !on); }

    // ---------- landing ----------

    $(document).ready(function () {
        if (!el('filterTerm')) return;
        ORMS.dropdown('#filterTerm');
        if (IS_ADMIN) {
            ORMS.dropdown('#filterClass, #filterSection, #filterSubject');
            $('#filterClass').on('change', function () { syncClassChains(); loadAssignments(); });
            $('#filterSection, #filterSubject').on('change', function () { paintSecTabs(); loadAssignments(); });
            // the section tabs and the Section dropdown are the same choice, kept in step
            ORMS.sectionTabs.bind('#meSecTabs', '#filterSection', function () { loadAssignments(); });
            syncClassChains();
        }
        $('#filterTerm').on('change', function () { loadAssignments(); });
        loadAssignments();
    });

    // class -> section + subject option chains, no round trip
    function syncClassChains() {
        var cid = $('#filterClass').val() || '';
        var secHtml = '<option value="">All Sections</option>', subHtml = '<option value="">All Subjects</option>';
        SECTIONS.forEach(function (s) {
            if (!cid || String(s.class_id) === String(cid)) secHtml += '<option value="' + s.id + '">' + esc(s.name) + '</option>';
        });
        var seen = {};
        SUBJECTS.forEach(function (s) {
            if (cid && String(s.class_id) !== String(cid)) return;
            if (seen[s.id]) return;
            seen[s.id] = 1;
            subHtml += '<option value="' + s.id + '">' + esc(s.name) + '</option>';
        });
        $('#filterSection').html(secHtml).val('');
        $('#filterSubject').html(subHtml).val('');
        ORMS.dropdown.refresh('#filterSection, #filterSubject');
        paintSecTabs();
    }

    // sections of the chosen class as tabs — marks are typed one section at a time, so the switch
    // that matters most stops being a dropdown among five
    function paintSecTabs() {
        var cid = $('#filterClass').val() || '';
        ORMS.sectionTabs('#meSecTabs', '#filterSection',
            SECTIONS.filter(function (s) { return !cid || String(s.class_id) === String(cid); })
                    .map(function (s) { return { id: s.id, name: s.name }; }),
            { all: 'All sections', allValue: '' });
    }

    function clearFilters() {
        $('#filterClass').val('');
        syncClassChains();
        ORMS.dropdown.refresh('#filterClass');
        loadAssignments();
    }

    function loadAssignments(btn) {
        var data = { term_id: $('#filterTerm').val() || 0 };
        if (IS_ADMIN) {
            data.class_id   = $('#filterClass').val() || 0;
            data.section_id = $('#filterSection').val() || 0;
            data.subject_id = $('#filterSubject').val() || 0;
        }
        ORMS.post('getAssignments', data, { btn: btn, busyLabel: 'Loading…' })
            .done(function (res) {
                show('#assignSkeleton', false);
                if (!res || !res.success) { renderEmpty('fa-triangle-exclamation', 'Could not load assignments', (res && res.message) || 'Please try again.'); return; }
                renderAssignments(res.cards || [], res.term || null, !!res.no_teacher);
            })
            .fail(function (msg) {
                show('#assignSkeleton', false);
                renderEmpty('fa-plug-circle-xmark', 'Connection error', msg || 'Could not reach the server.');
            });
    }

    function renderEmpty(icon, title, text) {
        if (assignTable) { assignTable.clear().draw(); }
        show('#assignTableWrap', false);
        el('assignEmpty').innerHTML = '<div class="orms-empty"><i class="fas ' + icon + '"></i><h4>' + esc(title) + '</h4><p>' + esc(text) + '</p></div>';
        show('#assignEmpty', true);
        el('statCards').textContent = '0';
        el('statStudents').textContent = '0';
        el('statEntered').textContent = '0';
        el('statExpected').textContent = '0';
    }

    // the ONE runtime style in this page — the bar's computed width
    function completionHtml(pct, state) {
        return '<div class="completion-wrap"><div class="completion-bar">' +
               '<span class="completion-fill ' + state + '" style="width:' + pct + '%"></span>' +
               '</div><span class="completion-pct ' + state + '">' + pct + '%</span></div>';
    }

    function fillClass(c, pct) {
        if (c.published) return 'is-published';
        return pct >= 100 ? 'is-complete' : (pct > 0 ? 'is-partial' : 'is-none');
    }

    // published > term lock > how much is filled in
    function rowStatus(c, pct, closed) {
        if (c.published) return 'Published';
        if (closed) return 'Locked';
        return pct >= 100 ? 'Complete' : (pct > 0 ? 'In Progress' : 'Not Started');
    }

    // same badge mapping as results.php
    function statusClass(s) {
        return s === 'Published'   ? 'status-active'
             : s === 'Complete'    ? 'status-user'
             : s === 'In Progress' ? 'status-current' : 'status-inactive';
    }

    function statusIcon(s) {
        return s === 'Published'   ? 'fa-bullhorn'
             : s === 'Locked'      ? 'fa-lock'
             : s === 'Complete'    ? 'fa-circle-check'
             : s === 'In Progress' ? 'fa-pen' : 'fa-hourglass-start';
    }

    function renderAssignments(rows, term, noTeacher) {
        var yrLock = !!(term && term.year_locked);
        var closed = !!(term && (term.status !== 'Open' || yrLock));
        if (closed) el('termBannerText').textContent = yrLock
            ? (term.year_lock + ' You can still view saved marks.')
            : ('Term "' + term.name + '" is ' + term.status + ' — marks entry is closed. You can still view saved marks.');
        show('#termBanner', closed);

        if (!rows.length) {
            renderEmpty('fa-clipboard-question',
                noTeacher ? 'No teacher profile linked' : 'Nothing assigned here',
                noTeacher ? 'Your login is not linked to a teacher profile yet. Ask an admin to create one and assign your subjects.'
                          : 'No class-section-subject matches this scope. Try another term or clear the filters.');
            return;
        }

        // decorate once — pct is what the Progress column sorts on
        var students = 0, entered = 0, expected = 0;
        rows.forEach(function (c) {
            c.pct    = c.expected > 0 ? Math.round(c.entered / c.expected * 100) : 0;
            c.state  = fillClass(c, c.pct);
            c.status = rowStatus(c, c.pct, closed);
            students += c.students; entered += c.entered; expected += c.expected;
        });

        show('#assignEmpty', false);
        show('#assignTableWrap', true);   // visible first — dt measures column widths on init
        buildAssignTable(rows);
        el('statCards').textContent = rows.length;
        el('statStudents').textContent = students;
        el('statEntered').textContent = entered;
        el('statExpected').textContent = expected;
    }

    function buildAssignTable(rows) {
        if (assignTable) { assignTable.destroy(); $('#assignTable').empty(); }
        assignTable = $('#assignTable').DataTable({
            data: rows,
            destroy: true,
            columns: [
                { data: 'class_name', title: 'Class', render: function (d, t) {
                    return t === 'display' ? '<strong>' + esc(d) + '</strong>' : d;
                } },
                { data: 'section_name', title: 'Section', render: function (d) { return esc(d); } },
                { data: 'subject_name', title: 'Subject', render: function (d, t) {
                    return t === 'display'
                        ? '<span class="subject-chip"><i class="fas fa-book"></i> ' + esc(d) + '</span>' : d;
                } },
                { data: 'students', title: 'Students' },
                { data: 'term_name', title: 'Term', render: function (d) { return esc(d); } },
                { data: 'pct', title: 'Progress', render: function (d, t, row) {
                    if (t === 'sort' || t === 'type') return d;               // sort on % — never on "28 / 32"
                    if (t !== 'display') return row.entered + ' / ' + row.expected;
                    return '<b>' + row.entered + '</b> / ' + row.expected + completionHtml(d, row.state);
                } },
                { data: 'status', title: 'Status', render: function (d, t) {
                    if (t !== 'display') return d;
                    return '<span class="status-badge ' + statusClass(d) + '">' +
                           '<i class="fas ' + statusIcon(d) + '"></i> ' + esc(d) + '</span>';
                } },
                { data: null, title: 'Actions', orderable: false, render: function (d, t, row) {
                    var open = 'openMarks(' + row.section_id + ',' + row.subject_id + ')';
                    if (row.can_enter) {
                        return '<button class="btn btn-primary btn-sm" onclick="' + open + '">' +
                               '<i class="fas fa-pen-to-square"></i> Enter Marks</button>';
                    }
                    return '<button class="btn btn-primary btn-sm" disabled title="' + esc(row.lock) + '">' +
                           '<i class="fas fa-lock"></i> Enter Marks</button> ' +
                           '<button class="btn btn-secondary btn-sm" onclick="' + open + '">' +
                           '<i class="fas fa-eye"></i> View</button>';
                } }
            ],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            dom: 'Blfrtip',
            buttons: [
                { extend: 'csv', text: '<i class="fas fa-file-csv"></i> CSV', title: 'Marks Entry', exportOptions: { columns: ':not(:last-child)' } },
                { text: '<i class="fas fa-file-pdf"></i> PDF',
                  action: function (e, dt, node, config) {
                      loadExportDeps(function () { $.fn.dataTable.ext.buttons.pdfHtml5.action.call(dt.button(node), e, dt, node, config); });
                  },
                  title: 'Marks Entry', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', title: 'Marks Entry', exportOptions: { columns: ':not(:last-child)' } }
            ],
            order: [[0, 'asc']],
            language: { emptyTable: 'Nothing assigned for this scope' }
        });
    }

    // ---------- popup ----------

    function openMarks(sectionId, subjectId) {
        WANT_SUB = subjectId || 0;   // the score sheet must carry the same columns as the grid
        ORMS.post('getGrid', { section_id: sectionId, subject_id: WANT_SUB, term_id: $('#filterTerm').val() || 0 })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Could not open the marks sheet'); return; }
                buildGrid(res);
                el('marksModal').classList.add('active');
                focusFirst();
            })
            .fail(function (msg) { ORMS.err(msg || 'Connection error'); });
    }

    function buildGrid(d) {
        GRID = { d: d, inputs: [], dirty: false, locked: !!d.locked };

        el('marksTitle').innerHTML = '<i class="fas fa-pen-to-square"></i> ' +
            esc(d.section.class_name + ' – ' + d.section.section_name) + ' · ' + esc(d.term.name);

        // optional-subject enrolment: no map entry = unrestricted, an entry = only the listed students
        function canTake(sub, stId) {
            var e = d.enrol && d.enrol[sub.id];
            return !sub.optional || !e ? true : e.indexOf(stId) !== -1;
        }
        GRID.canTake = canTake;

        // toolbar: counts + per subject total config. cell total honours elective enrolment
        var chips = '', cells = 0;
        var SCH = d.scheme || null, CPS = (SCH && SCH.components) || [];   // scheme beats theory/practical
        d.students.forEach(function (st) {
            d.subjects.forEach(function (sub) { if (canTake(sub, st.id)) cells++; });
        });
        if (SCH) chips += '<span class="subject-chip"><i class="fas fa-layer-group"></i> ' + esc(SCH.name) +
                          ' · Continuous Assessment ' + num(SCH.ca) + '% · Exam ' + num(SCH.exam) + '%' +
                          ' · ' + CPS.length + ' components</span>';
        d.subjects.forEach(function (s) {
            chips += '<span class="subject-chip"><i class="fas fa-book"></i> ' + esc(s.name) + ' · /' + num(s.total) +
                     ' · pass ' + num(s.pass) +
                     (s.scheme ? ' · components' + (s.raw_split ? ' (theory/practical ignored)' : '') : '') +
                     (s.split ? ' · theory ' + num(s.theory) + ' + practical ' + num(s.practical) : '') +
                     (s.optional ? ' · elective (' + ((d.enrol && d.enrol[s.id]) ? d.enrol[s.id].length : d.students.length) + ' enrolled)' : '') +
                     (s.excl ? ' · not counted in total' : '') + '</span>';
        });
        var canImport = !GRID.locked && CAN_ADD;
        el('marksToolbar').innerHTML =
            '<span><i class="fas fa-users"></i> <b>' + d.students.length + '</b> ' + (d.students.length === 1 ? 'estudiante' : 'estudiantes') + '</span>' +
            '<span><i class="fas fa-table-cells"></i> <b id="tbEntered">0</b> / ' + cells + ' notas</span>' +
            chips +
            '<span class="marks-offline">' +
            '<button type="button" class="btn btn-secondary btn-sm" id="sheetDlBtn" onclick="sheetDownload(this)">' +
            '<i class="fas fa-file-arrow-down"></i> Descargar plantilla</button>' +
            (canImport ? '<button type="button" class="btn btn-secondary btn-sm" id="sheetImpBtn" onclick="sheetPick()">' +
                         '<i class="fas fa-file-import"></i> Importar calificaciones</button>' : '') +
            '</span>';

        el('sheetHelpText').textContent = canImport
            ? '¿Desea trabajar sin conexión? Descargue la plantilla de calificaciones, complétela en Excel e impórtela aquí. Podrá previsualizar los cambios antes de guardar.'
            : 'Descargue la plantilla para una copia de respaldo — la importación está bloqueada mientras este período esté cerrado.';
        show('#sheetHelp', true);

        if (d.lock) el('marksLockText').textContent = d.lock;
        show('#marksLock', !!d.lock);

        // head: roll first, then student (css pins them in this order)
        var head = '<th class="col-roll">Rollo</th><th class="col-student">Estudiante</th>';
        var compHead = CPS.map(function (c) { return esc(c.name) + ' /' + num(c.max); }).join(' · ');
        d.subjects.forEach(function (s) {
            head += '<th class="col-subject"><i class="fas fa-book"></i> ' + esc(s.name) +
                    ' <span>/' + num(s.total) +
                    (s.split ? ' = T ' + num(s.theory) + ' + P ' + num(s.practical) : '') + '</span>' +
                    (s.scheme ? ' <span class="me-comp-head">' + compHead + '</span>' : '') +
                    (s.optional ? ' <span class="perm-off"><i class="fas fa-circle-half-stroke"></i> ELECTIVA</span>' : '') +
                    // excluded subject is still graded and printed, it just never joins the total
                    (s.excl ? ' <span class="perm-off"><i class="fas fa-ban"></i> NO CUENTA</span>' : '') +
                    '</th>';
        });
        el('marksHead').innerHTML = head;

        if (!d.students.length) {
            el('marksRows').innerHTML = '<tr><td class="col-roll">—</td><td class="col-student" colspan="' + (d.subjects.length + 1) + '">' +
                '<div class="orms-empty"><i class="fas fa-user-slash"></i><h4>No hay estudiantes activos</h4>' +
                '<p>Esta sección no tiene estudiantes activos para calificar.</p></div></td></tr>';
            el('marksSummary').innerHTML = '';
            show('#saveBtn', false);
            return;
        }

        var rows = '';
        d.students.forEach(function (st, r) {
            rows += '<tr data-student="' + st.id + '">';
            rows += '<td class="col-roll">' + esc(st.roll || '—') + '</td>';
            rows += '<td class="col-student"><div class="marks-student">' +
                    '<img class="marks-student-photo" src="' + esc(st.photo) + '" alt=""' +
                    ' onerror="this.onerror=null;this.src=DEF_AVATAR">' +
                    '<span><span class="marks-student-name">' + esc(st.name) + '</span>' +
                    '<span class="marks-student-roll">' + esc(st.adm || '') + '</span></span></div></td>';
            // c counts INPUT boxes, not subjects — a split subject owns two of them, so
            // enter/arrow nav keeps landing on the same box of the next student
            var c = 0;
            d.subjects.forEach(function (sub) {
                // not their elective -> a dead cell, no boxes. c still advances so column nav stays aligned
                if (!canTake(sub, st.id)) {
                    c += sub.scheme ? CPS.length : (sub.split ? 2 : 1);
                    rows += '<td class="col-subject"><div class="marks-cell marks-cell-na" title="Not enrolled in this optional subject">' +
                            '<span class="mark-na"><i class="fas fa-user-slash"></i> Not enrolled</span></div></td>';
                    return;
                }
                var m = d.marks[st.id + ':' + sub.id] || null;
                var absent = m && m.a ? 1 : 0;
                var rem = (m && m.r) ? String(m.r) : '';
                var lock = (absent || GRID.locked) ? ' disabled' : '';
                // the first box carries the whole cell's state; the second only nav + its own max
                var own = ' data-student="' + st.id + '" data-subject="' + sub.id + '"' +
                          ' data-total="' + sub.total + '" data-pass="' + sub.pass + '"' +
                          ' data-split="' + (sub.split ? 1 : 0) + '" data-scheme="' + (sub.scheme ? 1 : 0) + '"' +
                          ' data-had="' + (m ? 1 : 0) + '"' +
                          ' data-absent="' + absent + '" data-remark="' + esc(rem) + '"';
                var body, tv, pv;
                if (sub.scheme) {
                    // raw score per component, the sum span converts them live with the server's formula
                    var cm = (d.comp && d.comp[st.id + ':' + sub.id]) || null;
                    body = '';
                    CPS.forEach(function (cp, ci) {
                        var cv = (!absent && cm && cm[cp.id] !== null && cm[cp.id] !== undefined) ? num(cm[cp.id]) : '';
                        body += '<label class="me-comp"><span class="me-comp-head" title="' +
                                esc(cp.name + ' · max ' + num(cp.max) + ' · worth ' + num(cp.w) + ' of ' + num(sub.total) +
                                    (cp.exam ? ' · exam' : ' · continuous assessment')) + '">' +
                                esc(cp.name) + ' /' + num(cp.max) + '</span>' +
                                compBox(r, c++, cv, cp, ci === 0 ? own : '', lock) + '</label>';
                    });
                    body += '<span class="me-comp-sum">— / ' + num(sub.total) + '</span>';
                } else {
                    tv = cellVal(m, 't', absent); pv = cellVal(m, 'p', absent);
                    // row saved before the split was configured: show its old total in theory so a save
                    // can never silently drop it — over the theory max it paints invalid and blocks saving
                    if (sub.split && tv === '' && pv === '') tv = cellVal(m, 'm', absent);
                    body = sub.split
                        ? box(r, c++, tv, sub.theory, 'Theory marks', own, lock) + hint('T /' + num(sub.theory)) +
                          box(r, c++, pv, sub.practical, 'Practical marks', '', lock) + hint('P /' + num(sub.practical))
                        : box(r, c++, cellVal(m, 'm', absent), sub.total, 'Marks', own, lock) + hint('/' + num(sub.total));
                }

                rows += '<td class="col-subject"><div class="marks-cell' + (sub.split ? ' marks-cell-split' : '') + '">' + body +
                        '<button type="button" class="mark-ab-btn' + (absent ? ' active' : '') + '"' +
                        (GRID.locked ? ' disabled' : '') + ' title="Toggle absent (press A in the box)">AB</button>' +
                        noteBtn(rem) +
                        '<span class="mark-grade-preview">—</span>' +
                        '</div></td>';
            });
            rows += '</tr>';
        });
        el('marksRows').innerHTML = rows;

        // index the boxes once — keyboard nav is O(1) after this
        GRID.inputs = [];
        $('#marksRows .mark-input').each(function () {
            var r = +this.dataset.r, c = +this.dataset.c;
            (GRID.inputs[r] = GRID.inputs[r] || [])[c] = this;
        });
        $('#marksRows .marks-cell').each(function () { paintCell(this); });

        show('#saveBtn', !GRID.locked && CAN_ADD);
        refreshSummary();
    }

    function num(n) {
        var v = Number(n);
        if (!isFinite(v)) return '';
        return String(Math.round(v * 100) / 100);
    }

    // ---------- cell markup ----------

    // enterkeyhint: the phone keyboard's action key drives the same next-box hop the
    // desktop Enter handler does — without it android/ios offer "Go" and submit instead
    function box(r, c, v, max, label, own, lock) {
        return '<input class="mark-input" type="number" inputmode="decimal" enterkeyhint="next" step="0.01" min="0" max="' + max + '"' +
               ' value="' + v + '" data-r="' + r + '" data-c="' + c + '" data-max="' + max + '"' +
               own + lock + ' aria-label="' + label + '">';
    }

    // one component box — its own max and weight ride along so the cell converts without a round trip
    function compBox(r, c, v, cp, own, lock) {
        return '<input class="mark-input me-comp-in" type="number" inputmode="decimal" enterkeyhint="next" step="0.01" min="0"' +
               ' max="' + cp.max + '" value="' + v + '" data-r="' + r + '" data-c="' + c + '"' +
               ' data-max="' + cp.max + '" data-comp="' + cp.id + '" data-w="' + cp.w + '"' + own + lock +
               ' aria-label="' + esc(cp.name) + '">';
    }

    function hint(t) { return '<span class="mark-total-hint">' + esc(t) + '</span>'; }

    // saved value for one box; absent clears the boxes but keeps the flag
    function cellVal(m, k, absent) {
        return (m && !absent && m[k] !== null && m[k] !== undefined) ? num(m[k]) : '';
    }

    // note affordance — tabindex -1 keeps it out of the typing flow, navy once a remark exists
    function noteBtn(rem) {
        return '<button type="button" tabindex="-1" data-note="1" class="btn btn-sm ' +
               (rem ? 'btn-primary' : 'btn-secondary') + '" title="' +
               esc(rem ? 'Remark: ' + rem : 'Add a remark for this subject') + '">' +
               '<i class="fas ' + (rem ? 'fa-comment-dots' : 'fa-comment') + '"></i></button>';
    }

    // grade band mirror of ormsGradeFor — highest band whose min <= pct
    function bandFor(pct) {
        var p = Math.round(pct * 100) / 100, hit = null;
        (GRID.d.grading || []).forEach(function (b) { if (b.min <= p && (!hit || b.min > hit.min)) hit = b; });
        return hit;
    }

    // any element of a cell -> its boxes, first one holds the cell state
    function boxesOf(el) { return $(el).closest('.marks-cell').find('.mark-input'); }
    function filled(boxes) { return boxes.get().some(function (b) { return String(b.value).trim() !== ''; }); }

    // per cell: validity + live grade preview. split subject scores on theory + practical
    function paintCell(el) {
        var cell = $(el).closest('.marks-cell'), $g = cell.find('.mark-grade-preview');
        var boxes = cell.find('.mark-input'), main = boxes[0];
        if (!main) return;
        var total = +main.dataset.total, pass = +main.dataset.pass, absent = main.dataset.absent === '1';
        var sch = main.dataset.scheme === '1';
        var bad = false, any = false, sum = 0, pct = null, fail = false;

        boxes.each(function () {
            var raw = String(this.value).trim();
            if (raw === '') return;
            any = true;
            var v = Number(raw);
            if (!isFinite(v) || v < 0 || v > +this.dataset.max) { bad = true; return; }
            // same formula the server converts with: share of the component max, times its weight
            sum += sch ? (v / (+this.dataset.max || 1)) * (+this.dataset.w || 0) : v;
        });
        if (sch) sum = Math.round(sum * 100) / 100;
        if (!bad && any && !sch && sum > total) bad = true;   // parts may never out-total the subject

        var $sum = cell.find('.me-comp-sum');
        if ($sum.length) $sum.text((bad ? '!' : (absent ? 'AB' : (any ? num(sum) : '—'))) + ' / ' + num(total));

        if (absent) { pct = 0; fail = true; }
        else if (any && !bad) { pct = total > 0 ? (sum / total * 100) : 0; fail = sum < pass; }

        boxes.toggleClass('invalid', bad);
        if (bad) { $g.text('!').removeClass('is-fail'); return; }
        if (pct === null) { $g.text('—').removeClass('is-fail'); return; }
        var b = bandFor(pct), g = b ? b.grade : Math.round(pct) + '%';
        $g.text(absent ? 'AB/' + g : g).toggleClass('is-fail', fail);   // absent stores the 0% grade
    }

    function cellStats() {
        var entered = 0, absent = 0, invalid = 0, notes = 0, total = 0;
        $('#marksRows .marks-cell').each(function () {
            var boxes = $(this).find('.mark-input'), main = boxes[0];
            if (!main) return;
            total++;
            if (main.dataset.remark) notes++;
            if (main.dataset.absent === '1') { absent++; entered++; return; }
            if (boxes.filter('.invalid').length) { invalid++; return; }
            if (filled(boxes)) entered++;
        });
        return { entered: entered, absent: absent, invalid: invalid, notes: notes, total: total };
    }

    function refreshSummary() {
        var s = cellStats();
        el('marksSummary').innerHTML =
            '<span><i class="fas fa-check-circle"></i> Entered <b>' + s.entered + '</b> of ' + s.total + '</span>' +
            '<span><i class="fas fa-user-slash"></i> Absent <b>' + s.absent + '</b></span>' +
            (s.notes ? '<span><i class="fas fa-comment-dots"></i> Remarks <b>' + s.notes + '</b></span>' : '') +
            (s.invalid ? '<span><i class="fas fa-triangle-exclamation"></i> <b>' + s.invalid + '</b> invalid</span>' : '') +
            (GRID.dirty ? '<span><i class="fas fa-circle-dot"></i> Unsaved changes</span>' : '');
        var tb = el('tbEntered');
        if (tb) tb.textContent = s.entered;
        $('#saveBtn').prop('disabled', s.invalid > 0);
    }

    function markDirty(inp) {
        GRID.dirty = true;
        $(inp).closest('tr').removeClass('marks-row-saved marks-row-error');
    }

    // ---------- keyboard-first entry ----------

    function move(inp, dr, dc) {
        var r = +inp.dataset.r, c = +inp.dataset.c, g = GRID.inputs;
        var nr = r + dr, nc = c + dc, guard = 0;
        while (guard++ < 5000) {
            if (nr < 0 || nr >= g.length) return;
            var row = g[nr] || [];
            if (nc < 0 || nc >= row.length) return;
            var t = row[nc];
            if (t && !t.disabled) { t.focus(); t.select(); return; }
            nr += dr; nc += dc;            // skip absent/disabled cells, keep the same direction
        }
    }

    function focusFirst() {
        var g = GRID && GRID.inputs;
        if (!g) return;
        for (var r = 0; r < g.length; r++) {
            var row = g[r] || [];
            for (var c = 0; c < row.length; c++) {
                if (row[c] && !row[c].disabled) { row[c].focus(); row[c].select(); return; }
            }
        }
    }

    // delegated on the tbody, NOT on document: the modal shell carries onclick="event.stopPropagation()"
    // (so a click inside never closes it), which also stops clicks from ever reaching document —
    // AB and the remark button silently did nothing. #marksRows sits inside the modal, so it gets
    // the event first. Keep every marks-grid handler bound here for the same reason.
    $('#marksRows')
        .on('input', '.mark-input', function () {
            markDirty(this);
            paintCell(this);
            refreshSummary();
        })
        .on('keydown', '.mark-input', function (e) {
            var k = e.key;
            if (k === 'Enter')      { e.preventDefault(); move(this, e.shiftKey ? -1 : 1, 0); }
            else if (k === 'ArrowDown')  { e.preventDefault(); move(this, 1, 0); }
            else if (k === 'ArrowUp')    { e.preventDefault(); move(this, -1, 0); }
            else if (k === 'ArrowRight') { e.preventDefault(); move(this, 0, 1); }
            else if (k === 'ArrowLeft')  { e.preventDefault(); move(this, 0, -1); }
            else if ((k === 'a' || k === 'A') && !e.ctrlKey && !e.metaKey && !e.altKey) {   // ctrl+a stays select-all
                e.preventDefault();
                toggleAbsent($(this).closest('.marks-cell').find('.mark-ab-btn')[0]);
            }
        })
        .on('click', '.mark-ab-btn', function () { toggleAbsent(this); })
        .on('click', '[data-note]', function () { openNote(this); });

    function toggleAbsent(btn) {
        if (!btn || btn.disabled || !GRID || GRID.locked) return;
        var $b = $(btn), boxes = boxesOf(btn), main = boxes[0];
        var on = main.dataset.absent !== '1';
        main.dataset.absent = on ? '1' : '0';
        $b.toggleClass('active', on);
        boxes.each(function () { if (on) { this.value = ''; this.disabled = true; } else { this.disabled = false; } });
        if (!on) main.focus();
        markDirty(main);
        paintCell(main);
        refreshSummary();
    }

    // ---------- per-subject remark ----------

    // opens off a tabindex -1 button, so typing marks never lands here by accident
    function openNote(btn) {
        var boxes = boxesOf(btn), main = boxes[0];
        if (!main) return;
        var $tr = $(btn).closest('tr'), name = $tr.find('.marks-student-name').text();
        var sub = GRID ? (GRID.d.subjects.filter(function (s) { return String(s.id) === main.dataset.subject; })[0] || null) : null;
        var where = name + ' · ' + (sub ? sub.name : 'Subject');
        var rem = main.dataset.remark || '';

        // who strip shared by both popups
        var av = ($.trim(name) || 'S').charAt(0).toUpperCase();
        var who = '<div class="rmk-who"><span class="rmk-av">' + esc(av) + '</span>' +
            '<div class="rmk-who-t"><b>' + esc(name) + '</b>' +
            '<small><i class="fas fa-book"></i>' + esc(sub ? sub.name : 'Subject') + '</small></div></div>';

        if (!GRID || GRID.locked) {
            Swal.fire({
                title: 'Subject remark',
                html: '<div class="rmk-pop">' + who +
                    '<div class="rmk-view">' + (rem ? esc(rem) : '<i>No remark recorded.</i>') + '</div></div>',
                confirmButtonText: '<i class="fas fa-check"></i> Close'
            });
            return;
        }

        var quick = ['Excellent work', 'Good effort', 'Can do better', 'Needs support'];
        Swal.fire({
            title: 'Subject remark',
            html: '<div class="rmk-pop">' + who +
                '<textarea id="rmkText" class="rmk-text" rows="3" maxlength="255" aria-label="Subject remark"' +
                ' placeholder="Short note for this subject — leave empty to clear">' + esc(rem) + '</textarea>' +
                '<div class="rmk-foot"><div class="rmk-quick">' +
                    quick.map(function (q) { return '<button type="button" class="rmk-chip" data-txt="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') +
                '</div><span class="rmk-count" id="rmkCount"></span></div></div>',
            footer: 'Stored with the mark. Clearing the mark removes the remark too.',
            showCancelButton: true,
            focusConfirm: false,
            confirmButtonText: '<i class="fas fa-check"></i> Apply',
            cancelButtonText: '<i class="fas fa-arrow-left"></i> Cancel',
            didOpen: function (pop) {
                var t = pop.querySelector('#rmkText'), c = pop.querySelector('#rmkCount');
                var upd = function () { c.textContent = t.value.length + '/255'; };
                t.addEventListener('input', upd); upd();
                pop.querySelectorAll('.rmk-chip').forEach(function (ch) {
                    ch.addEventListener('click', function () { t.value = ch.dataset.txt; upd(); t.focus(); });
                });
                t.focus();
                t.setSelectionRange(t.value.length, t.value.length);
            },
            // newlines -> spaces, remark stays the single-line note it always was
            preConfirm: function () { return document.getElementById('rmkText').value.replace(/\s*\n+\s*/g, ' '); }
        }).then(function (res) {
            if (!res.isConfirmed) { if (!main.disabled) main.focus(); return; }
            var v = String(res.value || '').trim().slice(0, 255);
            if (v !== rem) {
                main.dataset.remark = v;
                $(btn).replaceWith(noteBtn(v));   // rebuild -> icon, colour and tooltip stay in sync
                markDirty(main);
                refreshSummary();
            }
            if (!main.disabled) main.focus();
        });
    }

    // ---------- save ----------

    function saveMarks(btn) {
        if (!GRID || GRID.locked) return;
        var s = cellStats();
        if (s.invalid) { ORMS.err('Fix the highlighted cells first — ' + s.invalid + ' value(s) are out of range.'); return; }

        // send meaningful cells only: has a value, is absent, or previously existed (so clearing persists)
        var rows = [];
        $('#marksRows .marks-cell').each(function () {
            var boxes = $(this).find('.mark-input'), main = boxes[0];
            if (!main) return;
            var ab = main.dataset.absent === '1', had = main.dataset.had === '1';
            var vals = boxes.get().map(function (b) { return String(b.value).trim(); });
            if (!ab && !filled(boxes) && !had) return;
            var row = {
                student_id: +main.dataset.student,
                subject_id: +main.dataset.subject,
                is_absent: ab ? 1 : 0,
                remarks: main.dataset.remark || ''
            };
            // scheme cell posts its raw component scores — the server converts, a posted total is ignored
            if (main.dataset.scheme === '1') {
                var comp = {};
                boxes.each(function () {
                    var t = String(this.value).trim();
                    comp[this.dataset.comp] = (ab || t === '') ? null : Number(t);
                });
                row.comp = comp;
            } else if (main.dataset.split === '1') {
                row.theory    = (ab || vals[0] === '') ? null : Number(vals[0]);
                row.practical = (ab || vals[1] === '') ? null : Number(vals[1]);
            } else {
                row.marks_obtained = (ab || vals[0] === '') ? null : Number(vals[0]);
            }
            rows.push(row);
        });
        if (!rows.length) { ORMS.err('Nothing to save — enter at least one mark.'); return; }

        ORMS.post('saveMarks', {
            term_id: GRID.d.term.id,
            section_id: GRID.d.section.id,
            rows: JSON.stringify(rows)
        }, { btn: btn, busyLabel: 'Saving…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Save failed'); return; }
                applySaveResult(res);
                if (res.errors && res.errors.length) {
                    var lines = res.errors.slice(0, 12).map(function (m) { return esc(m); });
                    if (res.errors.length > 12) lines.push('… and ' + (res.errors.length - 12) + ' more (see console)');
                    console.warn('Marks skipped:', res.errors);
                    Swal.fire({
                        icon: res.saved ? 'warning' : 'error',
                        title: res.saved + ' saved, ' + res.skipped + ' skipped',
                        html: lines.join('<br>') + apprNote(res)
                    });
                } else if (res.approval_reset) {
                    Swal.fire({ icon: 'success', title: res.message || 'Marks saved', html: apprNote(res) });
                } else {
                    ORMS.ok(res.message || 'Marks saved');
                }
                loadAssignments();
            })
            .fail(function (msg) { ORMS.err(msg || 'Connection error'); });
    }

    // an approval silently dying on edit is worse than none — always say it out loud
    function apprNote(res) {
        return (res && res.approval_reset)
            ? '<p class="info-banner"><i class="fas fa-rotate-left"></i> This section was already approved &mdash; changing the marks sent it back to Draft, so it needs approving again before it can be published.</p>'
            : '';
    }

    function applySaveResult(res) {
        var bad = {};
        (res.bad || []).forEach(function (k) { bad[k] = 1; });
        $('#marksRows .marks-cell').each(function () {
            var boxes = $(this).find('.mark-input'), main = boxes[0];
            if (!main) return;
            var ab = main.dataset.absent === '1';
            var key = main.dataset.student + ':' + main.dataset.subject;
            var $tr = $(this).closest('tr');
            if (bad[key]) { $tr.addClass('marks-row-error').removeClass('marks-row-saved'); return; }
            var kept = ab || filled(boxes);
            main.dataset.had = kept ? '1' : '0';                    // cleared cells were deleted server-side
            if (!kept && main.dataset.remark) {                     // the row went, so did its remark
                main.dataset.remark = '';
                $(this).find('[data-note]').replaceWith(noteBtn(''));
            }
            $tr.addClass('marks-row-saved').removeClass('marks-row-error');
        });
        GRID.dirty = false;
        refreshSummary();
    }

    // ---------- offline score sheet ----------

    function sheetScope() {
        return { term_id: GRID.d.term.id, section_id: GRID.d.section.id, subject_id: WANT_SUB || 0 };
    }

    function sheetPick() { el('sheetCsvInput').click(); }

    // a download is a read -> thin top bar, never the write overlay
    function sheetDownload(btn) {
        if (!GRID) return;
        var f = el('sheetForm'), s = sheetScope();
        f.csrf_token.value = window.ORMS_CSRF || '';
        f.section_id.value = s.section_id;
        f.term_id.value    = s.term_id;
        f.subject_id.value = s.subject_id;
        ORMS.bar.start();
        ORMS.busy(btn, true, 'Preparando…');
        f.submit();
        // an attachment never fires the frame's load event, so a timer is the only honest release
        setTimeout(function () { ORMS.busy(btn, false); ORMS.bar.done(); }, 1200);
    }

    // the frame stays empty on a real download — only a json error ever renders in there
    $('#sheetFrame').on('load', function () {
        var t = '', r = {};
        try { t = String((this.contentDocument && this.contentDocument.body && this.contentDocument.body.textContent) || '').trim(); } catch (e) {}
        if (t.charAt(0) !== '{') return;
        try { r = JSON.parse(t); } catch (e) {}
        ORMS.err(r.message || 'No se pudo generar la plantilla.');
    });

    el('sheetCsvInput').addEventListener('change', function () {
        var input = this, file = input.files && input.files[0];
        if (!file || !GRID) return;
        var reader = new FileReader();
        reader.onerror = function () { input.value = ''; ORMS.err('No se pudo leer el archivo.'); };
        reader.onload = function (ev) {
            input.value = '';                                    // same file twice must fire again
            var text = String(ev.target.result || '');
            // quick local sanity check only — the server does the authoritative parse
            var data = ORMS.parseCSV(text).filter(function (r) {
                var f0 = String((r && r[0]) || '').trim();
                return f0.charAt(0) !== '#' && (r || []).join('').trim() !== '';
            });
            if (data.length < 2) { ORMS.err('El archivo no contiene filas de datos debajo del encabezado.'); return; }
            sheetPreview(text);
        };
        reader.readAsText(file);
    });

    function sheetIssues(list, more) {
        if (!list || !list.length) return '';
        var h = '<div class="about-table-wrapper sheet-issues"><table class="about-roles-table"><thead><tr>' +
                '<th><i class="fas fa-list-ol"></i> #</th><th><i class="fas fa-circle-exclamation"></i> Detalle</th>' +
                '</tr></thead><tbody>';
        list.forEach(function (m, i) { h += '<tr><td>' + (i + 1) + '</td><td>' + esc(m) + '</td></tr>'; });
        return h + '</tbody></table></div>' +
               (more ? '<p><i class="fas fa-ellipsis"></i> y ' + more + ' más — verifique en la consola del navegador.</p>' : '');
    }

    function sheetCounts(r) {
        return '<div class="sheet-counts">' +
               (r.students === undefined ? '' : '<span><i class="fas fa-users"></i> Estudiantes <b>' + r.students + '</b></span>') +
               '<span><i class="fas fa-plus"></i> Nuevos <b>' + r.added + '</b></span>' +
               '<span><i class="fas fa-pen"></i> Actualizados <b>' + r.updated + '</b></span>' +
               '<span><i class="fas fa-eraser"></i> Borrados <b>' + r.cleared + '</b></span>' +
               '<span><i class="fas fa-forward"></i> Omitidos <b>' + r.skipped + '</b></span></div>';
    }

    // step 1 — nothing is written, the teacher sees exactly what would change
    function sheetPreview(text) {
        ORMS.post('importMarksPreview', $.extend(sheetScope(), { csv: text }),
                  { btn: '#sheetImpBtn', busyLabel: 'Verificando…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'No se pudo leer la plantilla.'); return; }
                if (res.issues && res.issues.length) console.warn('Score sheet problems:', res.issues);
                if (!res.changes) {
                    Swal.fire({ icon: 'info', title: 'Sin cambios pendientes', width: 640,
                                html: sheetCounts(res) + sheetIssues(res.issues, res.more) });
                    return;
                }
                Swal.fire({
                    icon: res.skipped ? 'warning' : 'question',
                    title: res.changes + (res.changes === 1 ? ' calificación lista' : ' calificaciones listas') + ' para importar',
                    html: sheetCounts(res) +
                          (GRID && GRID.dirty ? '<p><i class="fas fa-triangle-exclamation"></i> Las notas no guardadas en la cuadrícula serán reemplazadas.</p>' : '') +
                          sheetIssues(res.issues, res.more),
                    width: 640, showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-file-import"></i> Importar',
                    cancelButtonText: '<i class="fas fa-times"></i> Cancelar'
                }).then(function (x) { if (x.isConfirmed) sheetCommit(text); });
            })
            .fail(function (msg) { ORMS.err(msg || 'Error de conexión'); });
    }

    // step 2 — the server re-validates everything, the preview result is never trusted back
    function sheetCommit(text) {
        var s = sheetScope();
        ORMS.post('importMarks', $.extend({}, s, { csv: text }), { btn: '#sheetImpBtn', busyLabel: 'Importando…' })
            .done(function (res) {
                if (!res || !res.success) { ORMS.err((res && res.message) || 'Error al importar'); return; }
                if (res.errors && res.errors.length) console.warn('Score sheet skipped:', res.errors);
                if (GRID) GRID.dirty = false;              // the refetch below is the truth now
                openMarks(s.section_id, s.subject_id);      // refetch -> cells, summary and completion bars
                loadAssignments();
                Swal.fire({
                    icon: res.skipped ? 'warning' : 'success',
                    title: res.imported + ' notas importadas' + (res.skipped ? ', ' + res.skipped + ' omitidas' : ''),
                    html: sheetIssues(res.errors, res.more) + apprNote(res), width: 640
                });
            })
            .fail(function (msg) { ORMS.err(msg || 'Error de conexión'); });
    }

    // ---------- close guard ----------

    function closeMarks(force) {
        if (!force && GRID && GRID.dirty) {
            Swal.fire({
                icon: 'warning', title: '¿Descartar notas sin guardar?',
                text: 'Los cambios en esta hoja no han sido guardados todavía.',
                showCancelButton: true, confirmButtonColor: '#ea4335',
                confirmButtonText: '<i class="fas fa-times"></i> Descartar',
                cancelButtonText: '<i class="fas fa-arrow-left"></i> Seguir editando'
            }).then(function (r) { if (r.isConfirmed) closeMarks(true); });
            return;
        }
        el('marksModal').classList.remove('active');
        GRID = null;
    }

    $('#marksModal').on('click', function (e) { if (e.target === this) closeMarks(); });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && el('marksModal').classList.contains('active')) closeMarks();
    });

    window.addEventListener('beforeunload', function (e) {
        if (GRID && GRID.dirty) { e.preventDefault(); e.returnValue = ''; return ''; }
    });
    </script>
</body>
</html>
