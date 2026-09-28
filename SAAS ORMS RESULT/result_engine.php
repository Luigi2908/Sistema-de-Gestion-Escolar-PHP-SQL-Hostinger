<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// result maths lives here and NOWHERE else — totals, grades, gpa, pass/fail, positions, publish
// include only. pages never recompute a percentage on their own
require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------- tenant scope

// school_id landed on this table yet? one probe per table per request — an install that has not
// taken the tenant migration keeps the old shape instead of fataling on a column that isn't there
function ormsHasSchoolCol(string $table): bool {
    static $seen = [];
    if (!isset($seen[$table])) {
        try { qVal("SELECT school_id FROM `$table` LIMIT 1"); $seen[$table] = true; }
        catch (Throwable $e) { $seen[$table] = false; }
    }
    return $seen[$table];
}

// " AND <alias>.school_id = ?" or '' when the column isn't there yet. ONE definition — every file that
// scopes a tenant table reads the same clause, and every caller binds sid() for the ? it adds
function ormsSchoolSql(string $table, string $alias): string {
    return ormsHasSchoolCol($table) ? " AND $alias.school_id = ?" : '';
}

// THE marks tenant predicate — the one that has to read the same in every file that counts entered
// marks (results / result_engine / marks_entry / dashboard). alias is m everywhere
function ormsMarksScopeSql(string $alias = 'm'): string { return ormsSchoolSql('marks', $alias); }

// branch pin for a school-wide role — Branch Admin only, and only once the migration has run.
// caller binds ormsBranchLock() for the ? this adds
function ormsBranchSql(string $table, string $alias): string {
    return (ormsBranchLock() && ormsHasSchoolCol($table)) ? " AND $alias.branch_id = ?" : '';
}

// ormsFind*() with the pre-migration escape hatch: an install with no school_id columns would have
// the resolver deny every id, so there the raw id stands and the scope checks stay the only gate
function ormsTenantLive(): bool   { return ormsHasSchoolCol('classes'); }
function ormsOwnTerm($id): int    { return ormsTenantLive() ? ormsFindTerm($id)    : (int)$id; }
function ormsOwnSection($id): int { return ormsTenantLive() ? ormsFindSection($id) : (int)$id; }
function ormsOwnStudent($id): int { return ormsTenantLive() ? ormsFindStudent($id) : (int)$id; }
function ormsOwnSubject($id): int { return ormsTenantLive() ? ormsFindSubject($id) : (int)$id; }
function ormsOwnClass($id): int   { return ormsTenantLive() ? ormsFindClass($id)   : (int)$id; }
function ormsOwnYear($id): int    { return ormsTenantLive() ? ormsFindYear($id)    : (int)$id; }

// ---------------------------------------------------------------- grade bands

// one read per SET per request — same rule as ormsGradeFor() (highest band whose min <= pct), no query per row
function ormsBands(?int $setId = null): array {
    static $cache = [];
    $k = (int)($setId ?: (ormsHasGradingSets() ? ormsDefaultSetId() : 0));   // null -> default, so the key is stable
    if (!isset($cache[$k])) {
        $bands = ormsGradingScheme($setId);
        usort($bands, fn($a, $b) => (float)$b['min_percent'] <=> (float)$a['min_percent']);
        $cache[$k] = $bands;
    }
    return $cache[$k];
}

function ormsBandFor(float $percent, ?int $setId = null): ?array {
    $pct = round($percent, 2);
    foreach (ormsBands($setId) as $b) if ((float)$b['min_percent'] <= $pct) return $b;
    return ormsGradeFor($pct, $setId); // scheme empty -> single fallback query
}

// stored colours ride inline, so nothing but a real hex is ever let through
function ormsHex(?string $v): string {
    $v = trim((string)$v);
    return preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $v) ? $v : '';
}

// term-wise sheets grow a column (or a column pair) per term, and the card is a fixed A4 width.
// this tightens type + padding as terms multiply so 3, 4 or 5 terms still fit the same sheet
function ormsTermDensity(int $terms): string {
    if ($terms >= 5) return ' rc-dense-3';
    if ($terms === 4) return ' rc-dense-2';
    if ($terms === 3) return ' rc-dense-1';
    return '';
}

// 1st / 2nd / 3rd / 4th / 11th
function ormsOrdinal(int $n): string {
    $sfx = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($sfx[($v - 20) % 10] ?? $sfx[$v] ?? $sfx[0]);
}

// keep a failed list inside varchar(255) without slicing a name in half
function ormsTruncList(array $names, int $limit = 255): string {
    $out = '';
    foreach ($names as $n) {
        $next = $out === '' ? $n : $out . ', ' . $n;
        if (mb_strlen($next) > $limit - 3) return $out === '' ? mb_substr($n, 0, $limit) : $out . ', …';
        $out = $next;
    }
    return $out;
}

// ---------------------------------------------------------------- section config

// class of a section — cached, one resolve per section per request
function ormsSectionClassId(int $sectionId): int {
    static $cache = [];
    if (!isset($cache[$sectionId])) {
        try { $cache[$sectionId] = (int)(qVal("SELECT class_id FROM sections WHERE id = ?", 'i', $sectionId) ?? 0); }
        catch (Throwable $e) { $cache[$sectionId] = 0; }
    }
    return $cache[$sectionId];
}

// marks select list — ca/exam snapshots only exist once the assessment migration has run
function ormsMarkCols(): string {
    return "student_id, subject_id, marks_obtained, total_marks, passing_marks, is_absent, remarks"
         . (ormsHasAssessment() ? ", ca_obtained, exam_obtained" : '');
}

// subjects mapped to the section's class + their per-class marks config.
// counted = BOTH flags on. the class_subjects row is the override, the subject row is the global default
function ormsSectionSubjects(int $sectionId): array {
    return qAll("SELECT cs.subject_id, sub.name, sub.code, cs.total_marks, cs.passing_marks, cs.sort_order, cs.is_optional,
                        (cs.include_in_total = 1 AND sub.include_in_total = 1) AS counted
                 FROM sections sec
                 JOIN class_subjects cs ON cs.class_id = sec.class_id
                 JOIN subjects sub      ON sub.id = cs.subject_id
                 WHERE sec.id = ?
                 ORDER BY cs.sort_order ASC, sub.name ASC", 'i', $sectionId);
}

// class teacher of a section — printed under the left signature when one is set
function ormsClassTeacherName(int $sectionId): string {
    try {
        return (string)(qVal("SELECT u.full_name FROM sections sec
                              JOIN teachers t ON t.id = sec.class_teacher_id
                              JOIN users u    ON u.id = t.user_id
                              WHERE sec.id = ?", 'i', $sectionId) ?? '');
    } catch (Throwable $e) { return ''; }
}

// entered rows / (active students x core subjects + each student's own electives) x 100
function ormsSectionCompletion(int $termId, int $sectionId): array {
    $students = (int)qVal("SELECT COUNT(*) FROM students WHERE section_id = ? AND status = 'Active'", 'i', $sectionId);
    $subjects = (int)qVal("SELECT COUNT(*) FROM class_subjects cs
                           JOIN sections sec ON sec.class_id = cs.class_id
                           WHERE sec.id = ?", 'i', $sectionId);
    $core     = (int)qVal("SELECT COUNT(*) FROM class_subjects cs
                           JOIN sections sec ON sec.class_id = cs.class_id
                           WHERE sec.id = ? AND cs.is_optional = 0", 'i', $sectionId);

    // an optional subject only counts for the students enrolled in it
    if (ormsHasElectives()) {
        $yearId = (int)qVal("SELECT academic_year_id FROM exam_terms WHERE id = ?", 'i', $termId);
        $elect  = (int)qVal("SELECT COUNT(*) FROM student_subjects ss
                             JOIN students st ON st.id = ss.student_id AND st.section_id = ? AND st.status = 'Active'
                             JOIN class_subjects cs ON cs.class_id = st.class_id AND cs.subject_id = ss.subject_id AND cs.is_optional = 1
                             WHERE ss.academic_year_id = ?", 'ii', $sectionId, $yearId);
    } else {
        $elect = ($subjects - $core) * $students;   // pre-migration -> old maths
    }
    $expected = $students * $core + $elect;

    // a cell counts only if: live class_subject + active student STILL IN THIS SECTION + a real value (or absent)
    $scM = ormsMarksScopeSql();                  // '' pre-migration -> 2 params, else 3 (school last)
    $entered = (int)qVal("SELECT COUNT(*) FROM marks m
                          JOIN students st2       ON st2.id = m.student_id AND st2.status = 'Active' AND st2.section_id = m.section_id
                          JOIN class_subjects cs2 ON cs2.class_id = m.class_id AND cs2.subject_id = m.subject_id
                          WHERE m.section_id = ? AND m.term_id = ?
                            AND (m.marks_obtained IS NOT NULL OR m.is_absent = 1)" . ormsElectiveSql() . $scM,
                         $scM ? 'iii' : 'ii', ...($scM ? [$sectionId, $termId, sid()] : [$sectionId, $termId]));
    if ($entered > $expected) $entered = $expected; // stale rows can never push past 100%

    return [
        'entered'  => $entered,
        'expected' => $expected,
        'pct'      => $expected > 0 ? round($entered / $expected * 100, 2) : 0.0,
        'students' => $students,
        'subjects' => $subjects
    ];
}

// ---------------------------------------------------------------- the maths

// one student's subject rows + totals. absent -> obtained 0, full total still counts, subject failed.
// a NOT-counted subject still prints (marks + grade) but stays out of totals, %, gpa and pass/fail.
// $enrolled = this student's elective set ([subject_id => 1]); an optional subject they are not
// enrolled in is skipped entirely — not printed, not counted, not part of completion. null = no filtering
function ormsComputeStudentRow(array $st, array $subs, array $marksByKey, ?array $enrolled = null, ?int $setId = null): array {
    $sid = (int)$st['id'];
    $obt = 0.0; $max = 0.0; $points = []; $failed = []; $rows = []; $enteredN = 0; $countN = 0;

    foreach ($subs as $s) {
        if ((int)($s['is_optional'] ?? 0) === 1 && $enrolled !== null && !isset($enrolled[(int)$s['subject_id']])) continue; // not their elective
        $m       = $marksByKey[$sid . ':' . (int)$s['subject_id']] ?? null;
        $absent  = $m && (int)$m['is_absent'] === 1;
        $has     = $m !== null && ($m['marks_obtained'] !== null || $absent);   // blank row = nothing entered
        $total   = (float)($m['total_marks'] ?? $s['total_marks']);      // snapshot wins over current config
        $scored  = ($has && !$absent) ? (float)$m['marks_obtained'] : 0.0;
        $passing = (float)($m['passing_marks'] ?? $s['passing_marks']);  // snapshot wins, live config only as fallback
        $pct     = ormsPercent($scored, $total);
        $band    = ormsBandFor($pct, $setId);
        $isFail  = (int)($band['is_fail'] ?? 0) === 1;                   // scheme band flagged fail — ANDed on top of the pass mark
        $passed  = $has && !$absent && $scored >= $passing && !$isFail;  // missing/blank row = not passed
        $counted = (int)($s['counted'] ?? 1) === 1;
        $note    = trim((string)($m['remarks'] ?? ''));                  // teacher's own line beats the band remark

        if ($counted) {                                                  // excluded subject touches no aggregate
            $obt += $scored; $max += $total; $countN++;
            $points[] = (float)($band['grade_point'] ?? 0);
            if (!$passed) $failed[] = $s['name'];
        }
        if ($has) $enteredN++;                                           // completion counts every mapped subject

        $rows[] = [
            'subject_id'  => (int)$s['subject_id'],
            'name'        => $s['name'],
            'code'        => $s['code'],
            'total'       => $total,
            'obtained'    => $scored,
            'ca'          => isset($m['ca_obtained']) ? (float)$m['ca_obtained'] : null,     // scheme split, null when the class has none
            'exam'        => isset($m['exam_obtained']) ? (float)$m['exam_obtained'] : null,
            'is_absent'   => $absent ? 1 : 0,
            'entered'     => $has ? 1 : 0,
            'counted'     => $counted ? 1 : 0,
            'passing'     => $passing,
            'passed'      => $passed ? 1 : 0,
            'percent'     => $pct,
            'grade'       => $band['grade'] ?? '-',
            'grade_point' => (float)($band['grade_point'] ?? 0),
            'grade_color' => ormsHex($band['color'] ?? ''),
            'band_fail'   => $isFail ? 1 : 0,
            'mark_note'   => $note !== '' ? 1 : 0,
            'remarks'     => $note !== '' ? $note : ($band['remarks'] ?? '')
        ];
    }

    $pctAll = ormsPercent($obt, $max);
    $bandAll = ormsBandFor($pctAll, $setId);

    return [
        'student_id'      => $sid,
        'roll_no'         => $st['roll_no'] ?? '',
        'admission_no'    => $st['admission_no'] ?? '',
        'name'            => $st['full_name'] ?: ($st['username'] ?? ''),
        'subjects'        => $rows,
        'entered_count'   => $enteredN,
        'subjects_count'  => $countN,                                 // counted subjects only — frozen into the summary
        'total_obtained'  => round($obt, 2),
        'total_max'       => round($max, 2),
        'percentage'      => $pctAll,
        'grade'           => $bandAll['grade'] ?? '-',
        'gpa'             => $points ? round(array_sum($points) / count($points), 2) : 0.00,
        'result_status'   => ($countN && !$failed) ? 'PASS' : 'FAIL',  // pass iff EVERY counted subject passed. nothing counted = never a pass
        'failed_subjects' => ormsTruncList($failed),
        'position'        => null
    ];
}

// whole section for a term, ranked. ties share a position and the next rank skips (1,1,3)
function ormsBuildSectionResults(int $termId, int $sectionId): array {
    $students = qAll("SELECT st.id, st.roll_no, st.admission_no, u.full_name, u.username
                      FROM students st JOIN users u ON u.id = st.user_id
                      WHERE st.section_id = ? AND st.status = 'Active'
                      ORDER BY CAST(st.roll_no AS UNSIGNED) ASC, st.roll_no ASC, u.full_name ASC", 'i', $sectionId);
    if (!$students) return [];

    $subs  = ormsSectionSubjects($sectionId);
    $setId = ormsSetForClass(ormsSectionClassId($sectionId));            // grading set resolved ONCE, never per row
    $mk = [];                                                            // o(1) lookup, one pass
    foreach (qAll("SELECT " . ormsMarkCols() . "
                   FROM marks WHERE section_id = ? AND term_id = ?", 'ii', $sectionId, $termId) as $m) {
        $mk[(int)$m['student_id'] . ':' . (int)$m['subject_id']] = $m;
    }

    // per-student elective sets, attendance and fee balances — one read each for the whole section
    $yearId = (int)qVal("SELECT academic_year_id FROM exam_terms WHERE id = ?", 'i', $termId);
    $ids = array_map(fn($st) => (int)$st['id'], $students);
    $en  = ormsElectiveMap($ids, $yearId);
    $att = ormsAttendanceMap($sectionId, $termId);
    $bal = ormsFeeBalanceMap($ids, $yearId);

    $rows = array_map(function ($st) use ($subs, $mk, $en, $setId, $att, $bal, $yearId) {
        $sid = (int)$st['id'];
        $r = ormsComputeStudentRow($st, $subs, $mk, $en === null ? null : ($en[$sid] ?? []), $setId);
        $a = $att[$sid] ?? null;
        $r['attendance'] = $a ? $a + ['pct' => $a['total'] > 0 ? round($a['present'] / $a['total'] * 100, 1) : 0.0] : null;
        $w = ormsWithholdCheck($sid, $yearId, $bal[$sid] ?? null);       // balance handed in, no per-student query
        $r['withheld'] = $w['withheld'] ? 1 : 0;
        $r['withheld_reason'] = $w['reason'];
        return $r;
    }, $students);

    usort($rows, fn($a, $b) => $b['total_obtained'] <=> $a['total_obtained']);
    $pos = 0; $prev = null;
    foreach ($rows as $i => &$r) {
        if ($prev === null || abs($r['total_obtained'] - $prev) > 0.001) $pos = $i + 1; // tie -> keep, else skip to i+1
        $r['position'] = $pos;
        $prev = $r['total_obtained'];
    }
    unset($r);

    return $rows;
}

// ---------------------------------------------------------------- persistence

// attendance/withhold/grading-set cols on result_summaries there yet? pre-migration installs write the old 20
function ormsHasSummaryExtras(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT is_withheld FROM result_summaries LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// delete-then-insert, runs INSIDE the caller's transaction
function ormsWriteSummaries(int $termId, int $sectionId, int $yearId, int $byUserId = 0): int {
    $rows = ormsBuildSectionResults($termId, $sectionId);

    // a recompute must not wipe either written comment, a promotion decision or a printed
    // QR's verify token — carry all four across the delete
    $keep = [];
    foreach (qAll("SELECT student_id, teacher_remarks, principal_remarks, promoted_status, verify_token FROM result_summaries
                   WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId) as $k) {
        $keep[(int)$k['student_id']] = $k;
    }
    qExec("DELETE FROM result_summaries WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId);
    if (!$rows) return 0;

    $classId = ormsSectionClassId($sectionId);
    $of      = count($rows);   // rank denominator frozen here — "3rd of 32" never recounts later
    $gset    = ormsSetForClass($classId);   // frozen too — repointing the class later never regrades this card
    $extra   = ormsHasSummaryExtras();      // attendance/withhold/set cols there yet
    $ten     = ormsHasSchoolCol('result_summaries');   // tenant col there yet
    $school  = sid();

    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO result_summaries
        (student_id, term_id, section_id, class_id, academic_year_id, total_obtained, total_max, percentage, grade, gpa,
         `position`, result_status, failed_subjects, section_total, subjects_count, teacher_remarks, principal_remarks,
         promoted_status, verify_token, generated_by"
         . ($extra ? ", days_present, days_total, is_withheld, withheld_reason, grading_set_id" : '')
         . ($ten ? ", school_id" : '') . ")
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?"
         . ($extra ? ", ?, ?, ?, ?, ?" : '') . ($ten ? ", ?" : '') . ")");
    if (!$stmt) throw new RuntimeException('Summary insert prepare failed: ' . $conn->error);

    // bound once, re-executed per student — no per-row concat, no per-row prepare
    // 20 base params: i student, i term, i section, i class, i year, d obt, d max, d pct, s grade, d gpa,
    //            i pos, s status, s failed, i sec_total, i subj_count, s remarks, s head remarks, s promoted, s token, i generated_by
    // +5 when migrated: d days_present, d days_total, i is_withheld, s withheld_reason, i grading_set_id  => 25
    // +1 when tenanted: i school_id, always LAST so the base 20 never shift                               => 21 / 26
    $sid = $tob = $tmx = $pct = $gpa = $pos = $subN = 0; $grade = $status = $failed = $tRem = $pRem = $tok = ''; $promo = 'Pending';
    $dPres = $dTot = $whR = null; $wh = 0;
    $by = $byUserId ?: null;                                             // unknown author stays NULL, never 0
    $B = 'iiiiidddsdissiissssi';                                         // the base 20, counted once
    if ($extra) {
        $ten
            ? $stmt->bind_param($B . 'ddisii', $sid, $termId, $sectionId, $classId, $yearId, $tob, $tmx, $pct, $grade, $gpa,
                                $pos, $status, $failed, $of, $subN, $tRem, $pRem, $promo, $tok, $by,
                                $dPres, $dTot, $wh, $whR, $gset, $school)
            : $stmt->bind_param($B . 'ddisi',  $sid, $termId, $sectionId, $classId, $yearId, $tob, $tmx, $pct, $grade, $gpa,
                                $pos, $status, $failed, $of, $subN, $tRem, $pRem, $promo, $tok, $by,
                                $dPres, $dTot, $wh, $whR, $gset);
    } else {
        $ten
            ? $stmt->bind_param($B . 'i', $sid, $termId, $sectionId, $classId, $yearId, $tob, $tmx, $pct, $grade, $gpa,
                                $pos, $status, $failed, $of, $subN, $tRem, $pRem, $promo, $tok, $by, $school)
            : $stmt->bind_param($B,       $sid, $termId, $sectionId, $classId, $yearId, $tob, $tmx, $pct, $grade, $gpa,
                                $pos, $status, $failed, $of, $subN, $tRem, $pRem, $promo, $tok, $by);
    }

    foreach ($rows as $r) {
        $sid = $r['student_id']; $tob = $r['total_obtained']; $tmx = $r['total_max']; $pct = $r['percentage'];
        $grade = (string)$r['grade']; $gpa = $r['gpa']; $pos = (int)$r['position'];
        $status = $r['result_status']; $failed = $r['failed_subjects']; $subN = (int)$r['subjects_count'];
        $tRem  = (string)($keep[$sid]['teacher_remarks'] ?? '');
        $pRem  = (string)($keep[$sid]['principal_remarks'] ?? '');
        $promo = (string)($keep[$sid]['promoted_status'] ?? 'Pending');
        $tok   = (string)($keep[$sid]['verify_token'] ?? '') ?: bin2hex(random_bytes(16)); // reprint keeps the same QR
        $dPres = $r['attendance']['present'] ?? null;                    // nothing recorded stays NULL, never 0/0
        $dTot  = $r['attendance']['total'] ?? null;
        $wh    = (int)($r['withheld'] ?? 0);
        $whR   = ($r['withheld_reason'] ?? '') !== '' ? $r['withheld_reason'] : null;
        $stmt->execute();
    }
    $stmt->close();
    return count($rows);
}

// public entry — own transaction
function ormsComputeSummaries(int $termId, int $sectionId, int $yearId, int $byUserId = 0): int {
    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        $n = ormsWriteSummaries($termId, $sectionId, $yearId, $byUserId);
        $conn->commit();
        return $n;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// ---------------------------------------------------------------- approval gate

// stored sign-off state of a (term, section) pair. no row yet = never submitted
function ormsApprovalStatus(int $termId, int $sectionId): string {
    try {
        return (string)(qVal("SELECT approval_status FROM result_publications WHERE term_id = ? AND section_id = ?",
                             'ii', $termId, $sectionId) ?: 'Draft');
    } catch (Throwable $e) { return 'Approved'; }   // pre-migration db -> gate off, never a locked-out install
}

function ormsApprovalRequired(): bool { return (string)getSetting('require_principal_approval', '1') === '1'; }

// single source for "may this be published yet" — pages and the engine can never drift apart
function ormsApprovalBlocked(int $termId, int $sectionId): bool {
    return ormsApprovalRequired() && ormsApprovalStatus($termId, $sectionId) !== 'Approved';
}

// 100% marks-entry gate — publish and submit-for-approval both run it, so the maths lives once
function ormsCompletionGate(int $termId, int $sectionId, string $verb = 'Publishing'): array {
    $c = ormsSectionCompletion($termId, $sectionId);
    if ($c['expected'] <= 0) {
        return ['ok' => false, 'completion' => $c, 'message' => 'This section has no active students or no subjects mapped.'];
    }
    if ($c['entered'] < $c['expected']) {
        return ['ok' => false, 'completion' => $c,
                'message' => 'Marks entry is ' . $c['pct'] . '% complete (' . $c['entered'] . ' of ' . $c['expected'] . ' cells). ' . $verb . ' is allowed at 100% only.'];
    }
    return ['ok' => true, 'completion' => $c, 'message' => ''];
}

// publish is refused below 100% and without sign-off — returns a message, never throws
function ormsPublishSection(int $termId, int $sectionId, int $yearId, int $byUserId): array {
    // the engine is callable from anywhere, so the tenant check is repeated here, never only in the page
    if (!ormsOwnTerm($termId) || !ormsOwnSection($sectionId))
        return ['ok' => false, 'message' => 'That term or section is not part of this school.'];

    $gate = ormsCompletionGate($termId, $sectionId);
    if (!$gate['ok']) return ['ok' => false, 'message' => $gate['message'], 'completion' => $gate['completion']];
    $c = $gate['completion'];

    // enforced here too, not only in the page — the engine is callable from anywhere
    if (ormsApprovalBlocked($termId, $sectionId)) {
        return ['ok' => false, 'completion' => $c, 'message' => 'This section needs Principal approval before it can be published.'];
    }

    $sec = qOne("SELECT id, class_id FROM sections WHERE id = ?", 'i', $sectionId);
    if (!$sec) return ['ok' => false, 'message' => 'Section not found'];

    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        $n = ormsWriteSummaries($termId, $sectionId, $yearId, $byUserId);
        // re-publishing clears the previous unpublish audit — the activity log keeps the history.
        // live rows are always Approved: with the gate off the publisher IS the authority, with it on
        // the head's stamp is already there and COALESCE keeps their name on it
        // 6 base params: i term, i class, i section, i year, i published_by, i approved_by. +1 tenant col => 7
        $tenP = ormsHasSchoolCol('result_publications');
        $args = [$termId, (int)$sec['class_id'], $sectionId, $yearId, $byUserId, $byUserId];
        if ($tenP) $args[] = sid();
        qExec("INSERT INTO result_publications (term_id, class_id, section_id, academic_year_id, is_published, published_by, published_at,
                                                approval_status, approved_by, approved_at" . ($tenP ? ", school_id" : '') . ")
               VALUES (?, ?, ?, ?, 1, ?, NOW(), 'Approved', ?, NOW()" . ($tenP ? ", ?" : '') . ")
               ON DUPLICATE KEY UPDATE is_published = 1, published_by = VALUES(published_by), published_at = NOW(),
                                       class_id = VALUES(class_id), academic_year_id = VALUES(academic_year_id),
                                       approval_status = 'Approved',
                                       approved_by = COALESCE(approved_by, VALUES(approved_by)),
                                       approved_at = COALESCE(approved_at, NOW()),
                                       unpublished_by = NULL, unpublished_at = NULL, unpublish_reason = NULL",
               'iiiiii' . ($tenP ? 'i' : ''), ...$args);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Publish failed: ' . $e->getMessage());
        // detail stays in the log — results.php relays this straight to the browser
        return ['ok' => false, 'message' => 'Publish failed. Please try again or contact the administrator.'];
    }

    // recipients handed back — caller notifies AFTER the commit
    $users = array_map('intval', array_column(
        qAll("SELECT u.id FROM students st JOIN users u ON u.id = st.user_id
              WHERE st.section_id = ? AND st.status = 'Active'", 'i', $sectionId), 'id'));

    return ['ok' => true, 'count' => $n, 'students' => $users, 'completion' => $c,
            'message' => 'Published — ' . $n . ' result(s) generated'];
}

// admin only, reason mandatory. drops the snapshots so entry reopens
function ormsUnpublishSection(int $termId, int $sectionId, int $byUserId, string $reason): array {
    if (!ormsOwnTerm($termId) || !ormsOwnSection($sectionId))   // repeated here, never only in the page
        return ['ok' => false, 'message' => 'That term or section is not part of this school.'];
    $reason = mb_substr(trim($reason), 0, 255);   // column is varchar(255) — trim, never let strict mode reject the write
    if ($reason === '') return ['ok' => false, 'message' => 'A reason is required to unpublish.'];
    if (!ormsIsPublished($termId, $sectionId)) return ['ok' => false, 'message' => 'This section is not published.'];

    $conn = getDBConnection();
    $conn->begin_transaction();
    try {
        $n = qExec("DELETE FROM result_summaries WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId);
        // published_by / published_at are NOT touched — who published stays, who pulled it is written beside it.
        // the sign-off trail IS cleared: a pulled result goes back to Draft and must be re-submitted
        qExec("UPDATE result_publications
               SET is_published = 0, unpublished_by = ?, unpublished_at = NOW(), unpublish_reason = ?,
                   approval_status = 'Draft', submitted_by = NULL, submitted_at = NULL,
                   approved_by = NULL, approved_at = NULL, review_note = NULL
               WHERE term_id = ? AND section_id = ?", 'isii', $byUserId, $reason, $termId, $sectionId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Unpublish failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Unpublish failed. Please try again or contact the administrator.'];
    }

    return ['ok' => true, 'deleted' => $n, 'reason' => $reason,
            'message' => 'Unpublished — ' . $n . ' summary row(s) removed, marks entry is open again'];
}

// ---------------------------------------------------------------- card data

// the section a (student, term) result is frozen against — promotion must never orphan a published card.
// summary first, then the section the marks were tagged with, live section last
function ormsResolveResultSection(int $studentId, int $termId): ?int {
    $id = qVal("SELECT section_id FROM result_summaries WHERE student_id = ? AND term_id = ? LIMIT 1", 'ii', $studentId, $termId)
       ?? qVal("SELECT section_id FROM marks WHERE student_id = ? AND term_id = ? ORDER BY id DESC LIMIT 1", 'ii', $studentId, $termId)
       ?? qVal("SELECT section_id FROM students WHERE id = ?", 'i', $studentId);
    return $id !== null ? (int)$id : null;
}

// everything one result card needs. stored snapshot wins when published, live maths otherwise
function ormsStudentResult(int $studentId, int $termId): ?array {
    $st = qOne("SELECT st.*, u.full_name, u.username, u.profile_image,
                       c.name AS class_name, sec.name AS section_name
                FROM students st
                JOIN users u    ON u.id = st.user_id
                JOIN classes c  ON c.id = st.class_id
                JOIN sections sec ON sec.id = st.section_id
                WHERE st.id = ?", 'i', $studentId);
    if (!$st) return null;

    $term = ormsTerm($termId);
    if (!$term) return null;
    $year = qOne("SELECT * FROM academic_years WHERE id = ?", 'i', (int)$term['academic_year_id']);

    // frozen section, not the live one — a promoted student still opens last term's card
    $sectionId = ormsResolveResultSection($studentId, $termId) ?? (int)$st['section_id'];
    if ($sectionId !== (int)$st['section_id']) {                             // relabel the card to the section it belongs to
        $frozen = qOne("SELECT c.name AS class_name, sec.name AS section_name
                        FROM sections sec JOIN classes c ON c.id = sec.class_id WHERE sec.id = ?", 'i', $sectionId);
        if ($frozen) { $st['class_name'] = $frozen['class_name']; $st['section_name'] = $frozen['section_name']; }
    }

    // stored read FIRST — a published card grades against the set it was published with
    $stored = qOne("SELECT * FROM result_summaries WHERE student_id = ? AND term_id = ?", 'ii', $studentId, $termId);
    $setId  = (int)($stored['grading_set_id'] ?? 0) ?: ormsSetForClass(ormsSectionClassId($sectionId));
    $yearId = (int)($term['academic_year_id'] ?? 0);

    // live maths for the subject rows — same helper the section build uses
    $subs = ormsSectionSubjects($sectionId);
    $mk = [];
    foreach (qAll("SELECT " . ormsMarkCols() . "
                   FROM marks WHERE student_id = ? AND term_id = ?", 'ii', $studentId, $termId) as $m) {
        $mk[(int)$m['student_id'] . ':' . (int)$m['subject_id']] = $m;
    }
    $en   = ormsElectiveMap([$studentId], $yearId);
    $live = ormsComputeStudentRow($st, $subs, $mk, $en === null ? null : ($en[$studentId] ?? []), $setId);

    // frozen attendance + withhold state beat today's numbers; unpublished cards evaluate live
    if ($stored && ($stored['days_total'] ?? null) !== null) {
        $dp = (float)$stored['days_present']; $dt = (float)$stored['days_total'];
        $att = ['present' => $dp, 'total' => $dt, 'pct' => $dt > 0 ? round($dp / $dt * 100, 1) : 0.0];
    } else {
        $att = ormsAttendanceFor($studentId, $termId);
    }
    // withholding is a LIVE fee state, never the frozen one — a parent who clears the debt gets the
    // card back without a republish, and a hold set after publishing bites immediately. the stored
    // is_withheld column stays as the publish-time audit record and is only a fallback
    $w = function_exists('ormsWithholdCheck') ? ormsWithholdCheck($studentId, $yearId) : null;
    if ($w !== null) { $wh = $w['withheld'] ? 1 : 0; $whR = $w['reason']; }
    else { $wh = (int)($stored['is_withheld'] ?? 0); $whR = (string)($stored['withheld_reason'] ?? ''); }

    if ($stored) {
        $summary = [
            'total_obtained'  => (float)$stored['total_obtained'],
            'total_max'       => (float)$stored['total_max'],
            'percentage'      => (float)$stored['percentage'],
            'grade'           => $stored['grade'],
            'gpa'             => (float)$stored['gpa'],
            'result_status'   => $stored['result_status'],
            'failed_subjects' => (string)$stored['failed_subjects']
        ];
        $position = $stored['position'] !== null ? (int)$stored['position'] : null;
        // denominator was frozen at publish — only pre-column rows fall back to a live count
        $of = (int)($stored['section_total'] ?? 0)
           ?: (int)qVal("SELECT COUNT(*) FROM result_summaries WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId);
    } else {
        $summary = [
            'total_obtained'  => $live['total_obtained'],
            'total_max'       => $live['total_max'],
            'percentage'      => $live['percentage'],
            'grade'           => $live['grade'],
            'gpa'             => $live['gpa'],
            'result_status'   => $live['result_status'],
            'failed_subjects' => $live['failed_subjects']
        ];
        $ranked   = ormsBuildSectionResults($termId, $sectionId);          // live rank preview
        $position = null;
        foreach ($ranked as $r) if ($r['student_id'] === (int)$st['id']) { $position = (int)$r['position']; break; }
        $of = count($ranked);
    }

    return [
        'student'         => $st,
        'term'            => $term,
        'year'            => $year,
        'subjects'        => $live['subjects'],
        'summary'         => $summary,
        'position'        => $position,
        'section_total'   => $of,
        'teacher_remarks'   => (string)($stored['teacher_remarks'] ?? ''),
        'principal_remarks' => (string)($stored['principal_remarks'] ?? ''),
        'class_teacher'   => ormsClassTeacherName($sectionId),
        'verify_token'    => (string)($stored['verify_token'] ?? ''),
        'attendance'      => $att,
        'withheld'        => $wh,
        'withheld_reason' => $whR,
        'grading_set_id'  => $setId,
        'published'       => ormsIsPublished($termId, $sectionId)
    ];
}

// ---------------------------------------------------------------- templates

// registry — key => card skin. report + progress are the term-wise ones
function ormsTemplates(): array {
    return [
        'classic'  => ['label' => 'Classic Navy',    'termwise' => 0, 'desc' => 'The original card — navy header, summary strip. One term per card.'],
        'compact'  => ['label' => 'Compact Print',   'termwise' => 0, 'desc' => 'Same layout, ink-light and dense — best for bulk printing. One term per card.'],
        'modern'   => ['label' => 'Modern Accent',   'termwise' => 0, 'desc' => 'Rounded contemporary look — gradient title bar, striped rows, stat chips. One term per card.'],
        'elegant'  => ['label' => 'Elegant Serif',   'termwise' => 0, 'desc' => 'Certificate feel — serif type, double frame, understated rules. One term per card.'],
        'board'    => ['label' => 'Board Marksheet', 'termwise' => 0, 'desc' => 'Dense black & white bordered grid, board-exam style — sharpest for photocopies. One term per card.'],
        'formal'   => ['label' => 'Formal Slip',     'termwise' => 0, 'desc' => 'Bordered examination slip — position box, key to grades, comment row. One term per card.'],
        'report'   => ['label' => 'Academic Report', 'termwise' => 1, 'desc' => 'Term-wise report — every term of the year side by side plus a year overall.'],
        'progress' => ['label' => 'Progress Grades', 'termwise' => 1, 'desc' => 'Term-wise grade matrix — a grade letter per subject per term with the year overall.'],
        'marksheet' => ['label' => 'Consolidated Mark Sheet', 'termwise' => 1, 'desc' => 'Board-style sheet — full mark and secured mark side by side for every term, with a totals row and percentage, grade and result per term.'],
    ];
}

// class override -> global setting -> classic. cached — bulk print resolves once per class
function ormsResolveTemplate(?int $classId): string {
    static $cache = [];
    $key = (int)$classId;
    if (!isset($cache[$key])) {
        $tpl = '';
        if ($key) { try { $tpl = (string)(qVal("SELECT result_template FROM classes WHERE id = ?", 'i', $key) ?? ''); } catch (Throwable $e) {} }
        if ($tpl === '') $tpl = (string)getSetting('result_template', 'classic');
        $cache[$key] = isset(ormsTemplates()[$tpl]) ? $tpl : 'classic';
    }
    return $cache[$key];
}

// ---------------------------------------------------------------- term-wise data

// one student x one term, no ranking. published summary numbers win over live maths
function ormsTermSlice(array $st, int $termId, ?array $enrolled = null): ?array {
    $sid = (int)$st['id'];
    $sec = ormsResolveResultSection($sid, $termId);
    if (!$sec) return null;

    // summary first — its frozen grading_set_id grades this slice, live set only as fallback
    $s = qOne("SELECT total_obtained, total_max, percentage, grade, gpa, `position`, section_total, result_status"
              . (ormsHasSummaryExtras() ? ", grading_set_id" : '') . "
               FROM result_summaries WHERE student_id = ? AND term_id = ?", 'ii', $sid, $termId);
    $setId = (int)($s['grading_set_id'] ?? 0) ?: ormsSetForClass(ormsSectionClassId($sec));

    $mk = [];
    foreach (qAll("SELECT " . ormsMarkCols() . "
                   FROM marks WHERE student_id = ? AND term_id = ?", 'ii', $sid, $termId) as $m) {
        $mk[$sid . ':' . (int)$m['subject_id']] = $m;
    }
    $row = ormsComputeStudentRow($st, ormsSectionSubjects($sec), $mk, $enrolled, $setId);
    $row['grading_set_id'] = $setId;                                    // merge reads it back for the year overall

    if ($s) {
        $row['total_obtained'] = (float)$s['total_obtained']; $row['total_max'] = (float)$s['total_max'];
        $row['percentage'] = (float)$s['percentage']; $row['grade'] = $s['grade']; $row['gpa'] = (float)$s['gpa'];
        $row['position'] = $s['position'] !== null ? (int)$s['position'] : null;
        $row['section_total'] = (int)($s['section_total'] ?? 0);
        $row['result_status'] = $s['result_status'];   // published snapshot wins, same rule as the rest of the card
    }
    return $row;
}

// all terms of a year for one student — published terms + the term already opened (its draft was gated upstream).
// matrix merges subjects across terms; overall = summed marks resolved through the same band rules
function ormsStudentYearResult(int $studentId, int $yearId, int $openTermId): ?array {
    $st = qOne("SELECT st.*, u.full_name, u.username, u.profile_image
                FROM students st JOIN users u ON u.id = st.user_id WHERE st.id = ?", 'i', $studentId);
    if (!$st) return null;

    $en    = ormsElectiveMap([$studentId], $yearId);
    $enSet = $en === null ? null : ($en[$studentId] ?? []);

    $cols = [];
    foreach (qAll("SELECT * FROM exam_terms WHERE academic_year_id = ? ORDER BY sort_order ASC, id ASC", 'i', $yearId) as $t) {
        $tid = (int)$t['id'];
        $sec = ormsResolveResultSection($studentId, $tid);
        $pub = $sec !== null && ormsIsPublished($tid, $sec);
        if (!$pub && $tid !== $openTermId) continue;               // other terms' drafts stay hidden
        $slice = ormsTermSlice($st, $tid, $enSet);
        if ($slice) $cols[] = ['term' => $t, 'published' => $pub, 'slice' => $slice];
    }
    if (!$cols) return null;

    return ['terms' => $cols] + ormsMergeTermSlices($cols);
}

// slices -> subject matrix + year overall. split out so the settings preview can feed it sample slices.
// any term weightage > 0 turns on the WEIGHTED year result: % / grade / gpa = Σ(term value × weight) / Σweight
// over the terms carrying a weight (renormalised, so a partial year still reads right). raw totals stay raw.
// all weights 0 (the default) = the original plain marks sum — nothing changes for unconfigured schools
function ormsMergeTermSlices(array $cols): array {
    $wts      = array_map(fn($c) => max(0.0, (float)($c['term']['weightage'] ?? 0)), $cols);
    $weighted = array_sum($wts) > 0;
    $setId    = (int)($cols[0]['slice']['grading_set_id'] ?? 0) ?: null;   // same set the term slices used

    // subject matrix — union across terms, first-seen order
    $mx = [];
    foreach ($cols as $ci => $c) {
        foreach ($c['slice']['subjects'] as $s) {
            $id = (int)$s['subject_id'];
            $mx[$id] ??= ['name' => $s['name'], 'code' => $s['code'], 'counted' => 0, 'per_term' => [],
                          'obt' => 0.0, 'max' => 0.0, 'passing' => 0.0, 'entered' => 0,
                          'wp' => 0.0, 'wth' => 0.0, 'ww' => 0.0];
        }
    }
    foreach ($cols as $ci => $c) {
        foreach ($c['slice']['subjects'] as $s) {
            $id = (int)$s['subject_id'];
            $mx[$id]['per_term'][$ci] = $s;
            if ((int)$s['counted'] === 1) $mx[$id]['counted'] = 1;
            if ($s['entered']) {                                    // absent counts: 0 scored, full total
                $mx[$id]['obt'] += $s['obtained']; $mx[$id]['max'] += $s['total'];
                $mx[$id]['passing'] += $s['passing']; $mx[$id]['entered']++;
                if ($wts[$ci] > 0) {                                // weighted % + weighted pass threshold
                    $mx[$id]['wp']  += (float)$s['percent'] * $wts[$ci];
                    $mx[$id]['wth'] += ((float)$s['total'] > 0 ? (float)$s['passing'] / (float)$s['total'] * 100 : 0) * $wts[$ci];
                    $mx[$id]['ww']  += $wts[$ci];
                }
            }
        }
    }

    $obtAll = $maxAll = 0.0; $points = []; $failed = [];
    foreach ($mx as &$s) {
        if ($weighted && $s['ww'] > 0) {
            $pct    = round($s['wp'] / $s['ww'], 2);
            $band   = ormsBandFor($pct, $setId);
            $passed = $s['entered'] > 0 && $pct + 0.001 >= round($s['wth'] / $s['ww'], 2) && (int)($band['is_fail'] ?? 0) !== 1;
        } else {
            $pct    = ormsPercent($s['obt'], $s['max']);
            $band   = ormsBandFor($pct, $setId);
            $passed = $s['entered'] > 0 && $s['obt'] >= $s['passing'] && (int)($band['is_fail'] ?? 0) !== 1;
        }
        $s['overall'] = ['obt' => $s['obt'], 'max' => $s['max'], 'pct' => $pct, 'passed' => $passed ? 1 : 0,
                         'grade' => $band['grade'] ?? '-', 'color' => ormsHex($band['color'] ?? '')];
        if ($s['counted']) {
            $obtAll += $s['obt']; $maxAll += $s['max'];
            $points[] = (float)($band['grade_point'] ?? 0);
            if (!$passed) $failed[] = $s['name'];
        }
    }
    unset($s);

    $pctAll = ormsPercent($obtAll, $maxAll);
    $gpaAll = $points ? round(array_sum($points) / count($points), 2) : 0.00;
    if ($weighted) {                                                // year % + gpa from the term summaries
        $wp = $wg = $ww = 0.0;
        foreach ($cols as $ci => $c) {
            if ($wts[$ci] <= 0) continue;
            $wp += (float)$c['slice']['percentage'] * $wts[$ci];
            $wg += (float)$c['slice']['gpa'] * $wts[$ci];
            $ww += $wts[$ci];
        }
        if ($ww > 0) { $pctAll = round($wp / $ww, 2); $gpaAll = round($wg / $ww, 2); }
    }
    $bandAll = ormsBandFor($pctAll, $setId);
    return [
        'matrix'   => $mx,
        'weighted' => $weighted ? 1 : 0,
        'overall'  => [
            'total_obtained'  => round($obtAll, 2), 'total_max' => round($maxAll, 2),
            'percentage'      => $pctAll, 'grade' => $bandAll['grade'] ?? '-',
            'gpa'             => $gpaAll,
            'result_status'   => ($points && !$failed) ? 'PASS' : 'FAIL',
            'failed_subjects' => ormsTruncList($failed)
        ]
    ];
}

// ---------------------------------------------------------------- card markup

// esc / trim-zeros / toggle — shared by every skin
function ormsCardE($v): string   { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ormsCardNum($v): string { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); } // 100.00 -> 100
function ormsCardOn(array $b, string $k, string $d = '1'): bool { return (string)($b[$k] ?? $d) === '1'; }

// what the head of school is called on this card — "Principal", "Headmistress", whatever admin set
function ormsHeadLabel(array $b): string { return trim((string)($b['result_principal_designation'] ?? '')) ?: 'Principal'; }

// "First Term — Result Card — 2025-2026"
function ormsCardTitle(array $res, array $b): string {
    $label = trim((string)($b['result_title'] ?? '')) !== '' ? $b['result_title'] : 'Result Card';
    return trim(($res['term']['name'] ?? '') . ' — ' . $label . (($res['year']['name'] ?? '') !== '' ? ' — ' . $res['year']['name'] : ''));
}

// open tag + school header + title bar — accent is admin-set, runtime value so it rides inline
function ormsCardShell(array $b, string $skin, string $title): string {
    $e = 'ormsCardE';
    $meta = array_filter([$b['result_school_address'] ?? '', ($b['result_school_phone'] ?? '') !== '' ? 'Phone: ' . $b['result_school_phone'] : '']);
    $accent = ormsHex($b['result_accent_color'] ?? '');
    $h = '<div class="result-card' . $skin . '"' . ($accent !== '' ? ' style="--rc-accent:' . $e($accent) . '"' : '') . '>';
    $h .= '<div class="rc-header">';
    if (($b['result_logo'] ?? '') !== '') $h .= '<img class="rc-logo" src="' . $e($b['result_logo']) . '" alt="">';
    $h .= '<div class="rc-school"><h2 class="rc-school-name">' . $e($b['result_school_name']) . '</h2>';
    if ($meta) $h .= '<p class="rc-school-meta">' . implode('<br>', array_map($e, $meta)) . '</p>';
    $h .= '</div></div>';
    return $h . '<span class="rc-title">' . $e($title) . '</span>';
}

// photo + info fields block — classic + report reuse it, formal builds its own 3-zone row
function ormsCardStudent(array $res, array $b): string {
    $e = 'ormsCardE'; $st = $res['student'];
    $h = '<div class="rc-student">';
    if (ormsCardOn($b, 'result_show_photo') && ($st['profile_image'] ?? '') !== '') {
        $h .= '<img class="rc-photo" src="' . $e($st['profile_image']) . '" alt="">';
    }
    $h .= '<div class="rc-fields">' . ormsCardFieldRows($res, $b);
    return $h . '</div></div>';
}

// label/value rows — attendance carries its own hooks, everything else is a plain pair
function ormsCardFieldRows(array $res, array $b): string {
    $e = 'ormsCardE'; $a = $res['attendance'] ?? null; $h = '';
    foreach (ormsCardFields($res, $b) as $k => $v) {
        $val = ($k === 'Attendance' && $a)
             ? '<span class="rc-att">' . $e(ormsCardNum($a['present']) . ' / ' . ormsCardNum($a['total']))
               . ' <b class="rc-att-pct">' . $e(number_format((float)($a['pct'] ?? 0), 1) . '%') . '</b></span>'
             : $e($v);
        $h .= '<div class="rc-field"><span>' . $e($k) . '</span><span>' . $val . '</span></div>';
    }
    return $h;
}

function ormsCardFields(array $res, array $b): array {
    $st = $res['student'];
    $fields = [
        'Student Name' => $st['full_name'] ?: $st['username'],
        'Father Name'  => $st['father_name'] ?: '—',
        'Admission No' => $st['admission_no'],
        'Roll No'      => trim((string)($st['roll_no'] ?? '')) !== '' ? $st['roll_no'] : '—',
        'Class'        => $st['class_name'] . ' – ' . $st['section_name']
    ];
    if (ormsCardOn($b, 'result_show_dob')) $fields['Date of Birth'] = !empty($st['dob']) ? date('d M Y', strtotime($st['dob'])) : '—';
    // nothing recorded -> no row at all, never "0 / 0"
    $att = $res['attendance'] ?? null;
    if ($att && (float)$att['total'] > 0 && ormsCardOn($b, 'result_show_attendance')) {
        $fields['Attendance'] = ormsCardNum($att['present']) . ' / ' . ormsCardNum($att['total'])
                              . ' (' . number_format((float)($att['pct'] ?? 0), 1) . '%)';
    }
    return $fields;
}

// grade interpretation legend — the tail prints it on EVERY skin, so a parent always sees what "B2" means
function ormsKeyToGrades(?int $setId = null): string {
    static $show = null, $note = null;                               // settings read once per request, not per card
    if ($show === null) {
        $show = (string)getSetting('result_show_grade_key', '1');
        $note = trim((string)getSetting('result_grade_key_note', ''));
    }
    if ($show !== '1') return '';
    $bands = ormsBands($setId);                                      // already sorted desc by min
    if (!$bands) return '';
    $e = 'ormsCardE'; $n = 'ormsCardNum';
    $h = '<div class="rc-key"><span class="rc-key-title">Key to Grades</span>'
       . '<table class="rc-key-table"><thead><tr><th>Grade</th><th>Range</th><th>Interpretation</th></tr></thead><tbody>';
    foreach ($bands as $g) {
        $tint = ormsHex($g['color'] ?? '');
        $txt  = trim((string)($g['interpretation'] ?? '')) ?: trim((string)($g['remarks'] ?? ''));   // remarks as fallback
        $h .= '<tr><td><b' . ($tint !== '' ? ' style="color:' . $e($tint) . '"' : '') . '>' . $e($g['grade']) . '</b></td>'
            . '<td>' . $e($n($g['min_percent'])) . '–' . $e($n($g['max_percent'])) . '%</td>'
            . '<td>' . ($txt !== '' ? $e($txt) : '—')
            . ((int)($g['is_fail'] ?? 0) === 1 ? ' <i class="rc-key-fail">(Fail)</i>' : '') . '</td></tr>';
    }
    $h .= '</tbody></table>';
    if ($note !== '') $h .= '<p class="rc-key-note">' . $e($note) . '</p>';
    return $h . '</div>';
}

// failed line + remarks + verify QR + signatures + footer + close — the bottom every skin shares
function ormsCardTail(array $res, array $b, array $sum, bool $remarksLine = true): string {
    $e = 'ormsCardE'; $h = '';
    // engine only exposes the flag — who may see a withheld card is the page's call
    if (!empty($res['withheld'])) {
        $wr = trim((string)($res['withheld_reason'] ?? ''));
        $h .= '<div class="rc-withheld">WITHHELD' . ($wr !== '' ? ' — ' . $e($wr) : '') . '</div>';
    }
    $pass = ($sum['result_status'] ?? '') === 'PASS';
    if (!$pass && ($sum['failed_subjects'] ?? '') !== '' && ormsCardOn($b, 'result_show_failed_line')) {
        $h .= '<p class="rc-school-meta">Failed in: ' . $e($sum['failed_subjects']) . '</p>';
    }
    $tRem = trim((string)($res['teacher_remarks'] ?? ''));
    if ($remarksLine && $tRem !== '') $h .= '<p class="rc-school-meta"><strong>Class Teacher&rsquo;s Remarks:</strong> ' . $e($tRem) . '</p>';
    $pRem = trim((string)($res['principal_remarks'] ?? ''));
    if ($remarksLine && $pRem !== '' && ormsCardOn($b, 'result_show_principal_remark')) {
        $h .= '<p class="rc-school-meta"><strong>' . $e(ormsHeadLabel($b)) . '&rsquo;s Remarks:</strong> ' . $e($pRem) . '</p>';
    }
    $h .= ormsKeyToGrades($res['grading_set_id'] ?? null);           // one place, so every skin prints the legend
    // anti-forgery QR — published cards only (drafts have no stored token). js fills the box client-side
    $tok = trim((string)($res['verify_token'] ?? ''));
    if ($tok !== '' && !empty($res['published']) && ormsCardOn($b, 'result_show_qr')) {
        $h .= '<div class="rc-verify"><div class="rc-qr" data-qr="' . $e(ormsVerifyUrl($tok)) . '"></div>'
            . '<span class="rc-verify-txt">Scan to verify this result card</span></div>';
    }
    if (ormsCardOn($b, 'result_show_signatures')) {
        $ct  = trim((string)($res['class_teacher'] ?? ''));           // real name under the label when the section has one
        $sig = trim((string)($b['result_principal_signature'] ?? '')); // uploaded scan, head's side only
        $pn  = trim((string)($b['result_principal_name'] ?? ''));
        $h .= '<div class="rc-signatures"><div class="rc-sign">' . $e($b['result_signature_left'])
            . ($ct !== '' ? '<br><small>' . $e($ct) . '</small>' : '') . '</div>'
            . '<div class="rc-sign">'
            . ($sig !== '' && ormsCardOn($b, 'result_show_principal_sign') ? '<img class="rc-sign-img" src="' . $e($sig) . '" alt="">' : '')
            . $e($b['result_signature_right'])
            . ($pn !== '' ? '<br><small>' . $e($pn) . '</small>' : '') . '</div></div>';
    }
    if (ormsCardOn($b, 'result_show_footer')) {
        $h .= '<div class="rc-footer"><span>' . $e($b['result_footer_note']) . '</span><span>Printed: ' . date('d M Y') . '</span></div>';
    }
    return $h . '</div>';
}

// overall summary strip — classic + report reuse it
function ormsCardSummary(array $res, array $b, array $sum, bool $withPosition = true): string {
    $e = 'ormsCardE'; $n = 'ormsCardNum';
    $pass = ($sum['result_status'] ?? '') === 'PASS';
    $h = '<div class="rc-summary">'
        . '<div class="rc-summary-item"><span>Total Marks</span><strong>' . $e($n($sum['total_obtained'])) . ' / ' . $e($n($sum['total_max'])) . '</strong></div>'
        . '<div class="rc-summary-item"><span>Percentage</span><strong>' . $e(number_format((float)$sum['percentage'], 2)) . '%</strong></div>'
        . '<div class="rc-summary-item"><span>Grade</span><strong>' . $e($sum['grade'] ?: '-') . '</strong></div>';
    if (ormsCardOn($b, 'result_show_gpa')) {
        $h .= '<div class="rc-summary-item"><span>GPA</span><strong>' . $e(number_format((float)$sum['gpa'], 2)) . '</strong></div>';
    }
    if ($withPosition && ormsCardOn($b, 'result_show_position') && (int)($res['show_position'] ?? 1) === 1 && $res['position']) {
        $h .= '<div class="rc-summary-item"><span>Position</span><strong>' . $e(ormsOrdinal((int)$res['position']) . ' of ' . (int)$res['section_total']) . '</strong></div>';
    }
    return $h . '<div class="rc-summary-item"><span>Result</span><strong><b class="rc-badge ' . ($pass ? 'rc-badge-pass' : 'rc-badge-fail') . '">'
        . ($pass ? 'PASS' : 'FAIL') . '</b></strong></div></div>';
}

// every caller renders through here — template comes from the class unless the caller forces one.
// result_card.php prints one, results.php stacks a whole section. never echoes
function ormsRenderResultCard(array $res, ?array $brand = null, ?string $tpl = null): string {
    $b = $brand ?: ormsResultBranding();
    $tpl = $tpl !== null && isset(ormsTemplates()[$tpl]) ? $tpl : ormsResolveTemplate((int)($res['student']['class_id'] ?? 0));
    $res['show_position'] ??= ormsClassShowsPosition((int)($res['student']['class_id'] ?? 0)) ? 1 : 0; // junior classes can hide ranks

    if ($tpl === 'report' || $tpl === 'progress' || $tpl === 'marksheet') {
        try {
            $yr = ormsStudentYearResult((int)$res['student']['id'], (int)($res['term']['academic_year_id'] ?? 0), (int)($res['term']['id'] ?? 0));
        } catch (Throwable $e) { $yr = null; }
        if ($yr) {
            if ($tpl === 'report')   return ormsCardReport($res, $b, $yr);
            if ($tpl === 'progress') return ormsCardProgress($res, $b, $yr);
            return ormsCardMarksheet($res, $b, $yr);
        }
        $tpl = 'classic';                                            // nothing term-wise yet -> never a blank card
    }
    if ($tpl === 'formal') return ormsCardFormal($res, $b);
    $skins = ['compact' => ' rc-t-compact', 'modern' => ' rc-t-modern', 'elegant' => ' rc-t-elegant', 'board' => ' rc-t-board'];
    return ormsCardClassic($res, $b, $skins[$tpl] ?? '');            // skin-only templates share the classic markup
}

// the original single-term card. compact is the same markup under an ink-light skin
function ormsCardClassic(array $res, array $b, string $skin = ''): string {
    $e = 'ormsCardE'; $n = 'ormsCardNum';
    $sum = $res['summary'];
    $h = ormsCardShell($b, $skin, ormsCardTitle($res, $b)) . ormsCardStudent($res, $b);

    $showGrade = ormsCardOn($b, 'result_show_grade_col');
    $showRem   = ormsCardOn($b, 'result_show_remarks_col');
    // scheme classes carry a CA/exam split on every mark row — no scheme, no columns
    $showCa    = ormsCardOn($b, 'result_show_ca_columns')
              && (bool)array_filter($res['subjects'], fn($s) => ($s['ca'] ?? null) !== null || ($s['exam'] ?? null) !== null);
    $h .= '<table class="rc-table"><thead><tr><th>Subject</th>'
        . ($showCa ? '<th class="rc-ca-col">CA</th><th class="rc-ca-col">Exam</th>' : '')
        . '<th>Total</th><th>Obtained</th>'
        . ($showGrade ? '<th>Grade</th>' : '') . ($showRem ? '<th>Remarks</th>' : '') . '</tr></thead><tbody>';
    foreach ($res['subjects'] as $s) {
        $obt   = $s['is_absent'] ? '<span class="rc-absent">AB</span>' : $e($n($s['obtained']));
        $skip  = (int)($s['counted'] ?? 1) === 0;                    // prints, but scores nothing
        $tint  = (string)($s['grade_color'] ?? '');                  // stored band colour, runtime value -> inline
        $rem   = !empty($s['mark_note']) ? $s['remarks']             // teacher's own note wins the column
               : ($s['is_absent'] ? 'Absent' : ($s['passed'] ? $s['remarks'] : 'Fail'));
        $cls = trim(($s['passed'] ? '' : 'rc-fail ') . ($skip ? 'rc-skip' : ''));
        $h .= '<tr' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>'
            . '<td>' . $e($s['name']) . ($s['code'] !== '' && $s['code'] !== null ? ' <small>(' . $e($s['code']) . ')</small>' : '')
            . ($skip ? ' <small class="text-muted">(not counted)</small>' : '') . '</td>'
            . ($showCa ? '<td class="rc-ca-col">' . (($s['ca'] ?? null) !== null ? $e($n($s['ca'])) : '—') . '</td>'
                       . '<td class="rc-ca-col">' . (($s['exam'] ?? null) !== null ? $e($n($s['exam'])) : '—') . '</td>' : '')
            . '<td>' . $e($n($s['total'])) . '</td>'
            . '<td>' . $obt . '</td>'
            . ($showGrade ? '<td' . ($tint !== '' ? ' style="color:' . $e($tint) . '"' : '') . '>' . $e($s['grade']) . '</td>' : '')
            . ($showRem ? '<td>' . $e($rem) . '</td>' : '')
            . '</tr>';
    }
    $h .= '</tbody></table>';

    return $h . ormsCardSummary($res, $b, $sum) . ormsCardTail($res, $b, $sum);
}

// bordered examination slip — info fields left, photo mid, stats box right, key to grades + comment row
function ormsCardFormal(array $res, array $b): string {
    $e = 'ormsCardE'; $n = 'ormsCardNum';
    $st = $res['student']; $sum = $res['summary'];
    $pass = $sum['result_status'] === 'PASS';
    $h = ormsCardShell($b, ' rc-t-formal', ormsCardTitle($res, $b));

    // 3-zone info row
    $h .= '<div class="rcf-info"><div class="rc-fields">' . ormsCardFieldRows($res, $b) . '</div>';
    if (ormsCardOn($b, 'result_show_photo') && ($st['profile_image'] ?? '') !== '') {
        $h .= '<img class="rc-photo" src="' . $e($st['profile_image']) . '" alt="">';
    }
    $stats = [];
    if (ormsCardOn($b, 'result_show_position') && (int)($res['show_position'] ?? 1) === 1 && $res['position']) {
        $stats['Position'] = ormsOrdinal((int)$res['position']);
        $stats['Students in Class'] = (string)(int)$res['section_total'];
    }
    $stats['Total Score'] = $n($sum['total_obtained']) . ' / ' . $n($sum['total_max']);
    $stats['Percentage']  = number_format((float)$sum['percentage'], 2) . '%';
    $stats['Grade']       = (string)($sum['grade'] ?: '-');
    if (ormsCardOn($b, 'result_show_gpa')) $stats['GPA'] = number_format((float)$sum['gpa'], 2);
    $h .= '<div class="rcf-stats">';
    foreach ($stats as $k => $v) $h .= '<div class="rcf-stat"><span>' . $e($k) . '</span><b>' . $e($v) . '</b></div>';
    $h .= '<div class="rcf-stat"><span>Result</span><b class="rc-badge ' . ($pass ? 'rc-badge-pass' : 'rc-badge-fail') . '">' . ($pass ? 'PASS' : 'FAIL') . '</b></div>';
    $h .= '</div></div>';

    // numbered marks table with a % column
    $showGrade = ormsCardOn($b, 'result_show_grade_col');
    $showRem   = ormsCardOn($b, 'result_show_remarks_col');
    $h .= '<table class="rc-table rcf-table"><thead><tr><th>#</th><th>Subject</th><th>Total</th><th>Obtained</th><th>%</th>'
        . ($showGrade ? '<th>Grade</th>' : '') . ($showRem ? '<th>Remarks</th>' : '') . '</tr></thead><tbody>';
    foreach ($res['subjects'] as $i => $s) {
        $obt  = $s['is_absent'] ? '<span class="rc-absent">AB</span>' : $e($n($s['obtained']));
        $skip = (int)($s['counted'] ?? 1) === 0;
        $tint = (string)($s['grade_color'] ?? '');
        $rem  = !empty($s['mark_note']) ? $s['remarks'] : ($s['is_absent'] ? 'Absent' : ($s['passed'] ? $s['remarks'] : 'Fail'));
        $cls  = trim(($s['passed'] ? '' : 'rc-fail ') . ($skip ? 'rc-skip' : ''));
        $h .= '<tr' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><td>' . ($i + 1) . '</td>'
            . '<td>' . $e($s['name']) . ($s['code'] !== '' && $s['code'] !== null ? ' <small>(' . $e($s['code']) . ')</small>' : '')
            . ($skip ? ' <small class="text-muted">(not counted)</small>' : '') . '</td>'
            . '<td>' . $e($n($s['total'])) . '</td><td>' . $obt . '</td>'
            . '<td>' . $e(number_format((float)$s['percent'], 1)) . '%</td>'
            . ($showGrade ? '<td' . ($tint !== '' ? ' style="color:' . $e($tint) . '"' : '') . '>' . $e($s['grade']) . '</td>' : '')
            . ($showRem ? '<td>' . $e($rem) . '</td>' : '') . '</tr>';
    }
    $h .= '</tbody></table>';

    // the two written comments side by side. tail runs with $remarksLine off, so both live here
    $tRem = trim((string)($res['teacher_remarks'] ?? ''));
    $pRem = trim((string)($res['principal_remarks'] ?? ''));
    $h .= '<div class="rcf-bottom">'
        . '<div class="rcf-comment"><span class="rc-key-title">Class Teacher&rsquo;s Comment</span><p>'
        . ($tRem !== '' ? $e($tRem) : '&mdash;') . '</p>'
        . ($pRem !== '' && ormsCardOn($b, 'result_show_principal_remark')
            ? '<span class="rc-key-title">' . $e(ormsHeadLabel($b)) . '&rsquo;s Comment</span><p>' . $e($pRem) . '</p>' : '')
        . '</div></div>';

    return $h . ormsCardTail($res, $b, $sum, false);
}

// term-wise academic report — subject rows x term columns + year overall
function ormsCardReport(array $res, array $b, array $yr): string {
    $e = 'ormsCardE'; $n = 'ormsCardNum';
    $label  = trim((string)($b['result_title'] ?? '')) !== '' ? $b['result_title'] : 'Result Card';
    $title  = trim((($res['year']['name'] ?? '') !== '' ? $res['year']['name'] . ' — ' : '') . 'Term-wise ' . $label);
    $openId = (int)($res['term']['id'] ?? 0);
    $cols   = $yr['terms']; $ov = $yr['overall'];
    $showGrade = ormsCardOn($b, 'result_show_grade_col');

    $h = ormsCardShell($b, ' rc-t-report', $title) . ormsCardStudent($res, $b);

    // header — one column per term (weight beside the name when the year is weighted), then the year overall
    $wtd = !empty($yr['weighted']);
    $anyDraft = false;
    $h .= '<table class="rc-table rcr-table' . ormsTermDensity(count($cols)) . '"><thead><tr><th>Subject</th>';
    foreach ($cols as $c) {
        $dr = !$c['published'];
        $anyDraft = $anyDraft || $dr;
        $w  = (float)($c['term']['weightage'] ?? 0);
        $h .= '<th>' . $e($c['term']['name']) . ($wtd && $w > 0 ? ' <small>' . $e($n($w)) . '%</small>' : '') . ($dr ? '<sup>*</sup>' : '') . '</th>';
    }
    $h .= '<th>Overall</th><th>%</th>' . ($showGrade ? '<th>Grade</th>' : '') . '</tr></thead><tbody>';

    foreach ($yr['matrix'] as $s) {
        $o = $s['overall'];
        $failRow = $s['counted'] && !$o['passed'] && $s['entered'] > 0;
        $h .= '<tr' . ($failRow ? ' class="rc-fail"' : '') . '><td>' . $e($s['name'])
            . ($s['code'] !== '' && $s['code'] !== null ? ' <small>(' . $e($s['code']) . ')</small>' : '')
            . (!$s['counted'] ? ' <small class="text-muted">(not counted)</small>' : '') . '</td>';
        foreach ($cols as $ci => $c) {
            $t = $s['per_term'][$ci] ?? null;
            if (!$t || !$t['entered']) { $h .= '<td>—</td>'; continue; }
            $h .= '<td>' . ($t['is_absent'] ? '<span class="rc-absent">AB</span>' : $e($n($t['obtained']))) . ' / ' . $e($n($t['total'])) . '</td>';
        }
        $h .= '<td>' . ($s['entered'] > 0 ? $e($n($o['obt']) . ' / ' . $n($o['max'])) : '—') . '</td>'
            . '<td>' . ($s['entered'] > 0 ? $e(number_format((float)$o['pct'], 1)) . '%' : '—') . '</td>'
            . ($showGrade ? '<td' . ($o['color'] !== '' ? ' style="color:' . $e($o['color']) . '"' : '') . '>' . ($s['entered'] > 0 ? $e($o['grade']) : '—') . '</td>' : '')
            . '</tr>';
    }
    $h .= '</tbody>';

    // per-term summary rows — totals, %, grade, position. overall value spans the year columns
    $span = 2 + ($showGrade ? 1 : 0);                                // Overall + % (+ Grade)
    $rowsOut = [
        ['Total',      fn($sl) => $n($sl['total_obtained']) . ' / ' . $n($sl['total_max']),  $n($ov['total_obtained']) . ' / ' . $n($ov['total_max'])],
        ['Percentage', fn($sl) => number_format((float)$sl['percentage'], 2) . '%',          number_format((float)$ov['percentage'], 2) . '%'],
        ['Grade',      fn($sl) => (string)($sl['grade'] ?: '-'),                             (string)($ov['grade'] ?: '-')],
    ];
    if (ormsCardOn($b, 'result_show_gpa')) {
        $rowsOut[] = ['GPA', fn($sl) => number_format((float)$sl['gpa'], 2), number_format((float)$ov['gpa'], 2)];
    }
    $h .= '<tfoot>';
    foreach ($rowsOut as [$lbl, $fmt, $ovVal]) {
        $h .= '<tr><td>' . $e($lbl) . '</td>';
        foreach ($cols as $c) $h .= '<td>' . $e($fmt($c['slice'])) . '</td>';
        $h .= '<td colspan="' . $span . '">' . $e($ovVal) . '</td></tr>';
    }
    if (ormsCardOn($b, 'result_show_position') && (int)($res['show_position'] ?? 1) === 1) {
        $h .= '<tr><td>Position</td>';
        foreach ($cols as $c) {
            $sl = $c['slice'];
            $pos = $sl['position'] ?? (((int)$c['term']['id'] === $openId) ? $res['position'] : null);   // open draft -> live rank
            $of  = (int)(($sl['section_total'] ?? 0) ?: (((int)$c['term']['id'] === $openId) ? (int)$res['section_total'] : 0));
            $h .= '<td>' . ($pos ? $e(ormsOrdinal((int)$pos) . ($of ? ' of ' . $of : '')) : '—') . '</td>';
        }
        $h .= '<td colspan="' . $span . '">—</td></tr>';
    }
    $h .= '</tfoot></table>';

    if ($wtd)      $h .= '<p class="rc-school-meta rcr-draft">Year overall is the weighted average of the terms — weights are shown beside each term.</p>';
    if ($anyDraft) $h .= '<p class="rc-school-meta rcr-draft">* Draft term — not published yet, numbers can still change.</p>';

    $ovRes = ['position' => null, 'section_total' => 0] + $res;      // overall strip never shows a per-term position
    return $h . ormsCardSummary($ovRes, $b, $ov, false) . ormsCardTail($res, $b, $ov);
}

// term-wise grade matrix — a letter per subject per term, marks stay off the card
function ormsCardProgress(array $res, array $b, array $yr): string {
    $e = 'ormsCardE';
    $title  = trim((($res['year']['name'] ?? '') !== '' ? $res['year']['name'] . ' — ' : '') . 'Progress Card');
    $openId = (int)($res['term']['id'] ?? 0);
    $cols   = $yr['terms']; $ov = $yr['overall'];

    $h = ormsCardShell($b, ' rc-t-progress', $title) . ormsCardStudent($res, $b);

    $wtd = !empty($yr['weighted']);
    $anyDraft = false;
    $h .= '<table class="rc-table rcp-table' . ormsTermDensity(count($cols)) . '"><thead><tr><th>Subject</th>';
    foreach ($cols as $c) {
        $dr = !$c['published'];
        $anyDraft = $anyDraft || $dr;
        $w  = (float)($c['term']['weightage'] ?? 0);
        $h .= '<th>' . $e($c['term']['name']) . ($wtd && $w > 0 ? ' <small>' . $e(ormsCardNum($w)) . '%</small>' : '') . ($dr ? '<sup>*</sup>' : '') . '</th>';
    }
    $h .= '<th>Overall</th><th>%</th></tr></thead><tbody>';

    foreach ($yr['matrix'] as $s) {
        $o = $s['overall'];
        $failRow = $s['counted'] && !$o['passed'] && $s['entered'] > 0;
        $h .= '<tr' . ($failRow ? ' class="rc-fail"' : '') . '><td>' . $e($s['name'])
            . ($s['code'] !== '' && $s['code'] !== null ? ' <small>(' . $e($s['code']) . ')</small>' : '')
            . (!$s['counted'] ? ' <small class="text-muted">(not counted)</small>' : '') . '</td>';
        foreach ($cols as $ci => $c) {
            $t = $s['per_term'][$ci] ?? null;
            if (!$t || !$t['entered']) { $h .= '<td>—</td>'; continue; }
            $tint = (string)($t['grade_color'] ?? '');
            $h .= '<td><b' . ($tint !== '' ? ' style="color:' . $e($tint) . '"' : '') . '>' . $e($t['grade']) . '</b>'
                . ($t['is_absent'] ? ' <span class="rc-absent">AB</span>' : '') . '</td>';
        }
        $h .= '<td>' . ($s['entered'] > 0 ? '<b' . ($o['color'] !== '' ? ' style="color:' . $e($o['color']) . '"' : '') . '>' . $e($o['grade']) . '</b>' : '—') . '</td>'
            . '<td>' . ($s['entered'] > 0 ? $e(number_format((float)$o['pct'], 1)) . '%' : '—') . '</td></tr>';
    }
    $h .= '</tbody><tfoot>';

    // per-term % + position under the letters
    $h .= '<tr><td>Percentage</td>';
    foreach ($cols as $c) $h .= '<td>' . $e(number_format((float)$c['slice']['percentage'], 2)) . '%</td>';
    $h .= '<td colspan="2">' . $e(number_format((float)$ov['percentage'], 2)) . '%</td></tr>';
    if (ormsCardOn($b, 'result_show_position') && (int)($res['show_position'] ?? 1) === 1) {
        $h .= '<tr><td>Position</td>';
        foreach ($cols as $c) {
            $sl = $c['slice'];
            $pos = $sl['position'] ?? (((int)$c['term']['id'] === $openId) ? $res['position'] : null);
            $of  = (int)(($sl['section_total'] ?? 0) ?: (((int)$c['term']['id'] === $openId) ? (int)$res['section_total'] : 0));
            $h .= '<td>' . ($pos ? $e(ormsOrdinal((int)$pos) . ($of ? ' of ' . $of : '')) : '—') . '</td>';
        }
        $h .= '<td colspan="2">—</td></tr>';
    }
    $h .= '</tfoot></table>';

    if ($wtd)      $h .= '<p class="rc-school-meta rcr-draft">Year overall is the weighted average of the terms — weights are shown beside each term.</p>';
    if ($anyDraft) $h .= '<p class="rc-school-meta rcr-draft">* Draft term — not published yet, grades can still change.</p>';

    $ovRes = ['position' => null, 'section_total' => 0] + $res;
    return $h . ormsCardSummary($ovRes, $b, $ov, false) . ormsCardTail($res, $b, $ov);
}

// board-style consolidated sheet — FULL MARK / SECURED MARK pair per term, totals row,
// then percentage / grade / result per term. everything printed here still comes from
// the saved branding, the card toggles and the grading scheme, same as every other skin
function ormsCardMarksheet(array $res, array $b, array $yr): string {
    $e = 'ormsCardE'; $n = 'ormsCardNum';
    $st   = $res['student'];
    $cols = $yr['terms']; $ov = $yr['overall'];
    $wtd  = !empty($yr['weighted']);
    $openId = (int)($res['term']['id'] ?? 0);

    // "MARK SHEET FOR THE EXAMINATION SESSION 2025-2026" — label is the admin's result_title
    $label = trim((string)($b['result_title'] ?? '')) !== '' ? $b['result_title'] : 'Mark Sheet';
    $yName = trim((string)($res['year']['name'] ?? ''));
    $title = mb_strtoupper(trim($label . ' for the examination session' . ($yName !== '' ? ' ' . $yName : '')), 'UTF-8');

    $h = ormsCardShell($b, ' rc-t-marksheet', $title);

    // identity block — the reference's four fields, plus roll/dob only when those toggles are on
    $colA = ['Student Name' => $st['full_name'] ?: $st['username'], "Father's Name" => $st['father_name'] ?: '—'];
    $colB = ['Admission No' => $st['admission_no'], 'Class' => $st['class_name'] . ' – ' . $st['section_name']];
    if (trim((string)($st['roll_no'] ?? '')) !== '') $colB['Roll No'] = $st['roll_no'];
    if (ormsCardOn($b, 'result_show_dob')) $colA['Date of Birth'] = !empty($st['dob']) ? date('d M Y', strtotime($st['dob'])) : '—';

    $h .= '<div class="rcm-info">';
    if (ormsCardOn($b, 'result_show_photo') && ($st['profile_image'] ?? '') !== '') {
        $h .= '<img class="rc-photo" src="' . $e($st['profile_image']) . '" alt="">';
    }
    $h .= '<div class="rcm-info-cols">';
    foreach ([$colA, $colB] as $grp) {
        $h .= '<div class="rcm-info-col">';
        foreach ($grp as $k => $v) $h .= '<div class="rcm-field"><span>' . $e($k) . '</span><b>' . $e($v) . '</b></div>';
        $h .= '</div>';
    }
    $h .= '</div></div>';

    // two header rows: term names spanning their pair, then the FULL / SECURED labels
    $anyDraft = false;
    $h .= '<table class="rc-table rcm-table' . ormsTermDensity(count($cols)) . '"><thead><tr><th class="rcm-sub" rowspan="2">Subjects</th>';
    foreach ($cols as $c) {
        $dr = !$c['published'];
        $anyDraft = $anyDraft || $dr;
        $w = (float)($c['term']['weightage'] ?? 0);
        $h .= '<th colspan="2">' . $e($c['term']['name'])
            . ($wtd && $w > 0 ? ' <small>' . $e($n($w)) . '%</small>' : '')
            . ($dr ? '<sup>*</sup>' : '') . '</th>';
    }
    $h .= '</tr><tr>';
    foreach ($cols as $c) $h .= '<th>Full Mark</th><th>Secured Mark</th>';
    $h .= '</tr></thead><tbody>';

    foreach ($yr['matrix'] as $s) {
        $o = $s['overall'];
        $failRow = $s['counted'] && !$o['passed'] && $s['entered'] > 0;
        $h .= '<tr' . ($failRow ? ' class="rc-fail"' : '') . '><td class="rcm-sub">' . $e($s['name'])
            . (!$s['counted'] ? ' <small class="text-muted">(not counted)</small>' : '') . '</td>';
        foreach ($cols as $ci => $c) {
            $t = $s['per_term'][$ci] ?? null;
            if (!$t || !$t['entered']) { $h .= '<td>—</td><td>—</td>'; continue; }   // not sat / not enrolled
            $h .= '<td>' . $e($n($t['total'])) . '</td><td>'
                . ($t['is_absent'] ? '<span class="rc-absent">AB</span>' : $e($n($t['obtained']))) . '</td>';
        }
        $h .= '</tr>';
    }

    // totals come off the term slice, so a published term keeps its frozen numbers
    $h .= '<tr class="rcm-total"><td class="rcm-sub">Total</td>';
    foreach ($cols as $c) {
        $sl = $c['slice'];
        $h .= '<td>' . $e($n($sl['total_max'])) . '</td><td>' . $e($n($sl['total_obtained'])) . '</td>';
    }
    $h .= '</tr></tbody><tfoot>';

    $footRows = [['Percentage', fn($sl) => number_format((float)$sl['percentage'], 2) . '%'],
                 ['Grade',      fn($sl) => (string)($sl['grade'] ?: '-')]];
    if (ormsCardOn($b, 'result_show_gpa')) {
        $footRows[] = ['GPA', fn($sl) => number_format((float)$sl['gpa'], 2)];
    }
    foreach ($footRows as [$lbl, $fmt]) {
        $h .= '<tr><td class="rcm-sub">' . $e($lbl) . '</td>';
        foreach ($cols as $c) $h .= '<td colspan="2">' . $e($fmt($c['slice'])) . '</td>';
        $h .= '</tr>';
    }

    $h .= '<tr><td class="rcm-sub">Result</td>';
    foreach ($cols as $c) {
        $ps = ($c['slice']['result_status'] ?? '') === 'PASS';
        $h .= '<td colspan="2"><b class="rc-badge ' . ($ps ? 'rc-badge-pass' : 'rc-badge-fail') . '">'
            . ($ps ? 'PASS' : 'FAIL') . '</b></td>';
    }
    $h .= '</tr>';

    if (ormsCardOn($b, 'result_show_position') && (int)($res['show_position'] ?? 1) === 1) {
        $h .= '<tr><td class="rcm-sub">Position</td>';
        foreach ($cols as $c) {
            $sl  = $c['slice'];
            $pos = $sl['position'] ?? (((int)$c['term']['id'] === $openId) ? $res['position'] : null);   // open draft -> live rank
            $of  = (int)(($sl['section_total'] ?? 0) ?: (((int)$c['term']['id'] === $openId) ? (int)$res['section_total'] : 0));
            $h .= '<td colspan="2">' . ($pos ? $e(ormsOrdinal((int)$pos) . ($of ? ' of ' . $of : '')) : '—') . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tfoot></table>';

    if ($wtd)      $h .= '<p class="rc-school-meta rcr-draft">Year overall is the weighted average of the terms — weights are shown beside each term.</p>';
    if ($anyDraft) $h .= '<p class="rc-school-meta rcr-draft">* Draft term — not published yet, numbers can still change.</p>';

    $ovRes = ['position' => null, 'section_total' => 0] + $res;   // the year strip never shows a per-term rank
    return $h . ormsCardSummary($ovRes, $b, $ov, false) . ormsCardTail($res, $b, $ov);
}
