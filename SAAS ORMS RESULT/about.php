<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// living documentation — open to every logged-in role, so no page perm key here
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username     = $_SESSION['username'];
$role         = $_SESSION['role'] ?? '';   // no default guess — four roles now, a wrong fallback mislabels the badge
$user_id      = $_SESSION['user_id'];
$current_page = 'about';

$branding   = getSiteBranding();
$aboutRoles = readRoles();          // live matrix source — roles.php edits land here instantly
$bands      = ormsGradingScheme();  // live grading source — result settings edits land here instantly
 // every set, so a school running a nursery scheme next to a senior one documents both. inactive sets
 // stay listed — a card already graded against one must still be readable here
$gradeSets  = ormsHasGradingSets() ? ormsGradingSets(false) : [];
$bandsBySet = [];
foreach ($gradeSets as $gsRow) $bandsBySet[(int)$gsRow['id']] = ormsGradingScheme((int)$gsRow['id']);
$curYear    = ormsCurrentYear();

// stored role colour is data, but never trust it into markup — hex or the house accent
function aboutRoleColor($c) {
    return preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)$c) ? (string)$c : '#0074D9';
}

// one role chip, label + colour straight off the roles row — same source the matrix header uses
function aboutRoleChip($key) {
    $r = roleByKey($key);
    return '<span class="role-badge rbac-rolehead" data-role-color="' . htmlspecialchars(aboutRoleColor($r['color'] ?? null)) . '">'
         . htmlspecialchars($r['label'] ?? $key) . '</span>';
}

// what each registered page does — labels/icons/groups come from $RBAC_PAGES, never retyped
$PAGE_NOTES = [
    'dashboard'       => 'Role-aware home. Admin sees school-wide KPIs and charts, Principal sees the same school-wide picture built around oversight — entry still outstanding and the sections waiting to be approved — teacher sees entry progress per assignment, student sees their latest result.',
    'users'           => 'Login accounts for all four roles — create, edit, reset password, impersonate.',
    'logs'            => 'Audit trail. Every create / update / delete / publish lands here with user, IP and timestamp.',
    'sessions'        => 'Active logins and devices; force-logout a session.',
    'settings'        => 'Site name, logo, language, maintenance mode and theme defaults.',
    'backup'          => 'SQL export / import — covers the academic tables automatically.',
    'smtp_setup'      => 'Outgoing mail credentials used by password reset, OTP and result-published mail.',
    'oauth_setup'     => 'Google sign-in client configuration.',
    'roles'           => 'The permission matrix itself. Editing a checkbox here changes access everywhere, including the table on this page.',
    'students'        => 'Register students (account + profile in one transaction), list / filter / edit, CSV import, promotion tool, printable credential slip.',
    'teachers'        => 'Teacher profiles and the Assign Subjects modal — one teacher per subject per section per year.',
    'classes'         => 'Classes and their sections, with delete blocked once students or marks exist.',
    'subjects'        => 'Subject catalogue plus per-class mapping, where total marks and passing marks are configured.',
    'marks_entry'     => 'The bulk entry popup. Rows are the section roster, columns are only the subjects the signed-in teacher owns. A subject configured with a theory and a practical component gets two boxes, every cell carries an optional remark, and one save writes the lot in one transaction. A Principal opens the same screen to watch progress across the school, but holds View only and can never save a cell.',
    'results'         => 'Completion overview per term, the approval gate (submit, approve or reject a finished section), publish / unpublish per section, tabulation sheet and bulk card printing.',
    'my_results'      => 'Student portal — published results across terms, each opening the printable result card. Staff open the same screen to reach a student: Admin, Owner and Principal reach any student in the school, a Branch Admin their own campus, and a teacher only the years they actually held that student\'s section (sections are reused across years, so the match is per year, never against the student\'s live section).',
    'broadsheet'      => 'The whole-class sheet — one row per student, one column per subject, with the totals, percentage, grade, position and PASS/FAIL down the side. It is what a head teacher reads at a glance before approving a section, and it prints as a landscape summary for the file. Rows follow the same scoping as everywhere else: Admin and Principal read any section, a teacher reads only the sections their assignments reach.',
    'attendance'      => 'Days present against the days the school was actually in session, typed per section and per term in one grid. Whatever is recorded here prints on the result card and is frozen into the summary when the section is published, so a later correction never rewrites an issued card. Teachers fill it for their own sections; Admin and Principal see the whole school.',
    'fees'            => 'The fee ledger per student per academic year — charges and payments as separate rows, the balance being charges minus payments — plus the switch that decides whether a family may open the result card at all. A student can be put on a manual hold, or the school can turn on arrears withholding so any balance above the configured threshold hides the card by itself. Withholding is never a publishing block: staff still read the card with a WITHHELD stamp, the family sees the withholding message instead.',
    'result_settings' => 'Result branding, academic years, exam terms, grading bands and display options. Everything printed on a card is configured here, including the head teacher\'s name, designation and signature image, and whether publishing has to wait for approval.',
];

// 14 academic tables added on top of the template
$DATA_MODEL = [
    ['academic_years',      'Session years such as 2025-2026; exactly one carries is_current. is_locked is the year-end close — a locked year accepts no marks entry anywhere and can never be the current year.'],
    ['exam_terms',          'Exam terms inside a year (First / Mid / Final) with status Upcoming, Open or Closed. Marks are only accepted while a term is Open. weightage drives the weighted year result on the term-wise cards.'],
    ['classes',             'Class list (Class 4, Class 5) with sort order and an active flag, the card template override and show_position, which hides rank on this class\'s cards. Also grading_set_id, the grading set this class is marked against, and assessment_scheme_id, the CA / exam split its subjects are typed under.'],
    ['sections',            'Sections inside a class (A, B) with optional capacity and an optional class teacher.'],
    ['subjects',            'Subject catalogue with a unique code, a type (Core / Elective / Optional) and the default include_in_total flag.'],
    ['class_subjects',      'Which subjects a class studies, plus total_marks and passing_marks for that class, the optional theory_marks / practical_marks split, the per-class include_in_total override and is_optional, which turns the subject into an enrolment-gated elective. The marks configuration lives here, not on the subject.'],
    ['student_subjects',    'Elective enrolment — which students actually take an optional subject in a given year. An optional subject exists only for the students listed here: it is skipped on everyone else\'s card, expects no marks cell and counts toward nobody else\'s completion. Managed from Subjects › Assign to Class › Students.'],
    ['teachers',            'Teacher profile linked one-to-one to a users row; name, phone and photo stay on users.'],
    ['students',            'Student profile linked one-to-one to a users row — admission no, roll no, class, section, year, guardian details and status.'],
    ['teacher_subjects',    'Teaching assignments. Unique per year + section + subject, so one subject in one section has exactly one teacher.'],
    ['marks',               'One row per student, subject and term. Stores the obtained marks, the theory and practical components behind them, the absent flag, the grade, the teacher\'s remark and a snapshot of total_marks and passing_marks. Unique on student + subject + term, so a bulk save is an upsert. Under an assessment scheme it also keeps the weighted halves in ca_obtained and exam_obtained and a snapshot of the scheme in assessment_scheme_id.'],
    ['grading_scheme',      'Grade bands — grade, min percent, max percent, grade point, remarks, a colour and the is_fail flag that marks a band as a failing grade. set_id ties the band to a grading set, so the same letter may exist in more than one set, and interpretation carries the parent-facing meaning printed in the Key to Grades block. Editable in Result Settings.'],
    ['grading_sets',        'Named grading schemes — a school can run several at once, a descriptive one for the lower primary and A1–F9 for the seniors. One row carries is_default, and classes.grading_set_id points a class at the set it is graded against.'],
    ['assessment_schemes',  'Named continuous-assessment / exam splits such as 50 / 50, 30 / 70 or 40 / 60. A class points at one through classes.assessment_scheme_id; with none set the subject keeps its single mark box.'],
    ['assessment_components', 'The parts of a scheme — Class Exercises, Project Work, End of Term Exam — each with its own max_marks, the weight_percent it contributes to the subject total, and is_exam, which decides whether it lands in the CA half or the exam half.'],
    ['mark_components',     'The raw score a student got in one component of one subject in one term, before weighting. Unique on student + subject + term + component; the weighted result of these rows is what lands in marks.'],
    ['attendance_summary',  'Days present and the days the school was in session, per student per term, with the section and year on the row and an optional remark. One row per student per term.'],
    ['student_fees',        'The fee ledger — one row per charge or payment, per student per academic year, with an optional term, a description, a positive amount that takes its sign from entry_type, a date and a reference.'],
    ['result_publications', 'Publish state per term + section, with who published it and when, plus who unpublished it and why. Also carries the approval trail — approval_status (Draft, Pending, Approved or Rejected), who submitted it and when, who approved it and when, and the note a rejection must carry. Publishing locks marks entry.'],
    ['result_summaries',    'Frozen result per student per term — totals, percentage, grade, GPA, position, PASS/FAIL, the counted-subject count and section_total, the rank denominator. Written at publish so positions and "of N" never drift afterwards. Also holds verify_token, the code behind the QR printed on the card, and the two written comments — teacher_remarks from the class teacher and principal_remarks from the head teacher — both carried across a republish. Publishing also freezes days_present and days_total, the grading_set_id the card was graded against, and is_withheld with withheld_reason.'],
];

// template tables reused as-is (never forked)
$TEMPLATE_TABLES = [
    ['users',               'Login accounts for all four roles, plus full name, phone, photo and theme preference.'],
    ['roles',               'Role registry and the permissions JSON that drives the matrix below.'],
    ['system_settings',     'Key/value settings, including every result_* branding and display key.'],
    ['activity_logs',       'Audit trail of every mutation.'],
    ['notifications',       'In-app bell messages, including result-published alerts.'],
    ['push_subscriptions',  'Browser push endpoints for the same alerts.'],
    ['user_sessions',       'Active device sessions.'],
    ['login_attempts',      'Rate-limit counters for failed logins.'],
    ['remember_tokens',     'Remember-me cookies.'],
    ['password_resets',     'Password reset tokens.'],
    ['email_verifications', 'Email verification / OTP codes.'],
];

// every calculation in the app, mirrored from result_engine.php + config.php — keep in sync when the maths changes
$FORMULAS = [
    'Marks & grading' => [
        ['Subject percentage',
         'round(obtained ÷ total_marks × 100, 2). A total of 0 returns 0% instead of dividing by zero.',
         'ormsPercent() in config.php — marks grid preview, result card, tabulation sheet'],
        ['Grading set resolution',
         'Which bands a mark is graded against: classes.grading_set_id, else the set flagged is_default, else set 1. It is resolved once per section or per card, never per row. A published card grades against result_summaries.grading_set_id whenever that column is filled, so pointing a class at a different set later can never silently regrade a card that has already been issued.',
         'ormsSetForClass() / ormsDefaultSetId() in config.php, ormsBands() in result_engine.php — marks save, card, broadsheet, summaries'],
        ['Grade band lookup',
         'The highest band whose min_percent is less than or equal to the rounded percentage. max_percent is stored for display but is deliberately not used in the lookup, because the seeded bands leave gaps (A is 80–89, A+ is 90–100) and a between-test would leave 89.5% ungraded. Grading sets mean the same letter can exist in more than one set, so every lookup carries the set id with it — a letter on its own never identifies a band.',
         'ormsGradeFor() in config.php, ormsBandFor() in result_engine.php — marks save, card, summaries'],
        ['Fail band (is_fail)',
         'A grade band can be flagged is_fail in the grading scheme. That flag is ANDed on top of the pass mark, so a score at or above passing_marks whose band is flagged as a fail is still not a pass. Result Settings warns when no band carries the flag.',
         'ormsComputeStudentRow() — subject pass, PASS/FAIL, failed list'],
        ['Subject pass',
         'obtained is greater than or equal to class_subjects.passing_marks AND the band the percentage falls into is not flagged is_fail. A subject with no marks row at all, or one marked absent, is never counted as passed.',
         'ormsComputeStudentRow() — result card, summary, PASS/FAIL'],
        ['Theory / practical split',
         'When the class_subjects row carries both theory_marks and practical_marks above zero, that subject is entered as two boxes instead of one. Each component is validated against its own maximum, marks_obtained is stored as theory + practical computed on the server (a posted total is ignored), and the sum is rejected if it exceeds total_marks. The components are kept in marks.theory_obtained and marks.practical_obtained. With no split configured the subject keeps a single box and behaves exactly as before.',
         'marks_entry.php save handler — marks grid, marks table'],
        ['Assessment component conversion',
         'When the class carries an assessment_scheme_id the subject is typed as several components instead of one box, and obtained = Σ (component score ÷ component max_marks × component weight_percent) over every component, rounded once at the end. The CA slice is that same sum over the components flagged is_exam = 0 and the exam slice is the sum over is_exam = 1, so a 50 / 50 or 30 / 70 school reads both halves — they are stored in marks.ca_obtained and marks.exam_obtained and printed as their own columns while "Show CA / Exam columns" is on. A blank component scores 0 but does not by itself count as entered; with every component blank the marks row is deleted rather than stored as a NULL mark. A raw score above its component maximum is a validation error: reported and skipped, never clamped.',
         'ormsConvertComponents() in config.php — marks entry grid and save, result card, broadsheet'],
        ['Rescaled subject total and pass mark',
         'A subject under an assessment scheme is marked out of the sum of the weights rather than the class total: total = Σ weight_percent, and the pass mark travels with it — pass = round(class_subjects.passing_marks ÷ class_subjects.total_marks × Σ weights, 2) — so the pass ratio survives the rescale. Both are written into marks.total_marks and marks.passing_marks as snapshots, exactly like a single-box subject, so editing the scheme afterwards never rewrites what is already entered. A scheme beats the theory / practical split: for a class that has one, the two-box layout is ignored for its subjects.',
         'ormsComponentWeight() in config.php, marks_entry.php save handler — marks grid header, card, tabulation'],
        ['Excluded subject (include_in_total)',
         'A subject is counted only when class_subjects.include_in_total and subjects.include_in_total are both 1 — the class row is the per-class override, the subject row is the global default. An excluded subject is still entered, graded and printed on the card, marked "not counted", but it adds nothing to the total, the maximum, the percentage, the GPA or the PASS/FAIL decision. Its column in the marks grid is labelled NOT COUNTED so the teacher knows before typing.',
         'ormsSectionSubjects() counted flag, ormsComputeStudentRow(), marks_entry.php grid header'],
        ['Optional subject (elective enrolment)',
         'A class_subjects row flagged is_optional applies only to the students enrolled in it for that academic year (student_subjects, managed from Subjects › Assign to Class › Students). For everyone else the subject simply does not exist: it is not printed on their card, expects no marks cell, and adds nothing to any total or to completion. The marks grid greys their cell out as "Not enrolled" and the save handler rejects a mark posted for a non-enrolled student. Flipping an existing subject to optional auto-enrols the whole current roster (plus anyone holding marks in it) so nobody\'s result changes until the school un-enrols the skippers; un-enrolling a student who already has marks that year is refused.',
         'ormsElectiveMap()/ormsElectiveSql() in config.php, ormsComputeStudentRow(), marks_entry.php grid + save, subjects.php enrolment editor'],
        ['Subject remark',
         'Every cell in the marks grid can carry a short note (marks.remarks, 255 characters) written from a comment button on the cell — it is optional, outside the tab order and saved by the same bulk save. On the result card that note replaces the remark the grading band would have printed; with no note the card falls back to the band remark, or to Absent / Fail.',
         'marks_entry.php save handler, ormsComputeStudentRow() mark_note — result card Remarks column'],
        ['Absent (AB)',
         'The cell stores marks_obtained as NULL with is_absent = 1, and any theory / practical component is cleared with it. When results are computed the subject scores 0, the full total still counts toward the maximum, the subject is failed and it is graded with the band for 0%.',
         'marks_entry.php save handler, ormsComputeStudentRow()'],
        ['Blank cell',
         'A subject with no marks row keeps no grade. It is treated as not passed and contributes 0 obtained against its full total once the section is computed.',
         'ormsComputeStudentRow()'],
        ['Grade stored on the row',
         'The grade is computed from the live grading scheme at save time and written into marks.grade, so a row always carries the grade it was saved with.',
         'marks_entry.php save handler'],
    ],
    'Result summary' => [
        ['Overall percentage',
         'round(sum of obtained ÷ sum of total_marks × 100, 2) across every counted subject mapped to the section class — not only the subjects that were entered, and never the excluded ones.',
         'ormsComputeStudentRow() — summary, card, tabulation'],
        ['Overall grade',
         'The same band lookup applied to the overall percentage.',
         'ormsComputeStudentRow()'],
        ['GPA',
         'The average of the subject grade_point values, rounded to 2 decimals, over the counted section subjects only. Absent and blank subjects contribute the grade point of their 0% band; excluded subjects contribute nothing at all.',
         'ormsComputeStudentRow() — summary, card (hidden when Show GPA is off)'],
        ['Counted subject count',
         'How many subjects actually fed the totals for that student. It is frozen into result_summaries.subjects_count at publish, so a later change to include_in_total cannot rewrite what an issued card was built from.',
         'ormsComputeStudentRow(), ormsWriteSummaries()'],
        ['PASS / FAIL',
         'PASS only when every counted subject passed. Otherwise FAIL, and the failed subject names are stored as a comma list trimmed to fit 255 characters without cutting a name in half. A student with no counted subject at all is never a PASS.',
         'ormsComputeStudentRow(), ormsTruncList()'],
        ['Position',
         'Students of the section are sorted by total_obtained descending for that term. Equal totals (within 0.001) share the same position and the next rank skips, so a tie for first produces 1, 1, 3.',
         'ormsBuildSectionResults() — computed at publish, stored in result_summaries'],
        ['Position "of N"',
         'N is frozen at publish into result_summaries.section_total, so "3rd of 32" still reads 32 after a student is transferred or deactivated. Only rows written before that column existed fall back to a live count of the stored summaries; an unpublished preview uses the live ranked roster count.',
         'ormsWriteSummaries(), ormsStudentResult() — result card'],
        ['Published snapshot',
         'Once a section is published the card reads totals, percentage, grade, GPA, position and PASS/FAIL from result_summaries. Unpublished results are computed live, so a preview always reflects the current marks.',
         'ormsStudentResult()'],
        ['Class teacher and head teacher remarks',
         'Two written comments live on the summary row: teacher_remarks from the class teacher and principal_remarks from the head teacher, typed per student from Results. Each prints beside the other on the card when its display toggle is on ("Show principal remark" for the head teacher\'s). Both are read back before a republish and written again with the new figures, exactly as teacher_remarks has always been, so pulling a section and publishing it again never wipes a comment somebody already typed.',
         'ormsWriteSummaries() carry-across — result card remarks block'],
    ],
    'Attendance, fees & withholding' => [
        ['Attendance percentage',
         'round(days_present ÷ days_total × 100, 1), and never a division by zero — a days_total of 0 reads 0. A student with nothing recorded prints nothing at all on the card instead of "0 / 0". Both figures are frozen into result_summaries.days_present and days_total at publish, so a later correction on the attendance grid does not rewrite an issued card.',
         'ormsAttendanceMap() / ormsAttendanceFor() in config.php, ormsWriteSummaries() — attendance grid, result card, broadsheet'],
        ['Fee balance',
         'Per student and per academic year: Σ amount where entry_type is Charge − Σ amount where entry_type is Payment. Amounts are always stored positive and the entry type carries the sign. A whole list is netted in one grouped query, never a query per student, and the dashboard tile drops the families in credit before totalling the arrears, so one credit can never mask another family\'s debt.',
         'ormsFeeBalanceMap() / ormsFeeBalance() in config.php — fee ledger, withholding check, dashboard tile'],
        ['Result withholding',
         'A card is withheld when students.fee_hold = 1, or when the withhold_on_arrears setting is on AND the balance is strictly greater than arrears_threshold. The manual hold wins over everything and carries its own note as the reason. Withholding is evaluated LIVE every time a card is opened, published or not, so clearing a debt restores the card without a republish and a hold set after publishing bites immediately. result_summaries.is_withheld and withheld_reason are written at publish purely as the audit record — and as the one deliberate exception, the QR verification page (index.php?verify=) reads that FROZEN flag, because a printed certificate must keep verifying as authentic. It hides the card from the family only: the public lookup answers with the withhold_message setting after a correct identity match, a student or guardian sees a banner, and Admin and Principal still read the card with a WITHHELD stamp. Withholding never blocks publishing the section — the publish dialog only warns how many students it will affect.',
         'ormsWithholdCheck() in config.php, ormsWriteSummaries(), index.php lookup and ?verify=, my_results.php and result_card.php (the staff stamp prints the balance for the card\'s own academic year)'],
    ],
    'Daily register, fee structures & timetable' => [
        ['Daily attendance status',
         'One row per student per DATE, status P (Present), A (Absent), L (Late) or LV (Leave), unique on (student, date) so re-saving a register updates rather than duplicates. The daily register is deliberately separate from the per-term totals that print on report cards — marking days never rewrites a card, and the term grid stays the single source the publish freeze reads. Future dates are refused.',
         'attendance.php Daily Register tab — attendance_daily table, saveDaily upsert'],
        ['Absentee WhatsApp alert',
         'The day\'s A and L rows across the viewer\'s section scope build the absentee list, each with a wa.me link to the guardian\'s number carrying a pre-filled message (student, class, roll, date, status). Numbers are digits-only; a leading 0 is swapped for the whatsapp_country_code setting (default 92) because wa.me only accepts international format. No API, no cost — the click opens the school\'s own WhatsApp.',
         'attendance.php getAbsentees + waPhone() — guardian_phone off the students row'],
        ['Monthly fee charge run',
         'Each active Monthly structure raises ONE Charge per active student of its class, in one set-based INSERT…SELECT per structure. Idempotence comes from the ledger reference FS{structure}-{YYYY-MM}: a NOT EXISTS on (student, reference) makes every re-run a no-op, so the run can never double-bill. Charges land dated the 1st of the month; deleting a structure never touches charges it already raised. One-Time structures are never part of the run.',
         'fees.php generateCharges — fee_structures table, student_fees.reference'],
        ['Fee reminder & challan',
         'Reminder buttons appear only while balance > 0: WhatsApp opens wa.me with the balance message, email sends the branded reminder to guardian_email (refused when no mailbox or nothing owed). The challan is a client-printed slip off the live overview row — charged, paid, balance — never a stored document, so it always shows the current position.',
         'fees.php emailFeeReminder / waFeeLink() / printChallan()'],
        ['Class owns its subjects',
         'A period and an exam paper may only name a subject that class actually studies (a class_subjects row). Resolving the id against the school is NOT the same fence — it proves the subject belongs to the tenant, not that this class teaches it — so both save handlers re-check membership against the section\'s class before writing. A timetable cell naming an off-list subject is dropped and named back in the save message rather than refusing the whole grid, because un-mapping a subject would otherwise leave that section\'s timetable permanently unsaveable; the grid also flags such a cell on load so it is visible before it disappears. An exam row for an off-list subject is skipped, and saving a date sheet additionally clears any stored paper for a subject the class no longer studies — those rows are invisible on the date sheet (it is built from class_subjects) yet still surfaced as "next exam" on every dashboard.',
         'ttClassSubjects() / ttSectionClass() in timetable.php — saveTimetable, saveExams, getTimetable off flag'],
        ['Section owns its grid',
         'The editor holds ONE section\'s grid and saving wipes that section before rewriting it, so the grid in memory carries the section it was loaded for (TT_SEC) and a save whose target no longer matches is refused and reloaded instead of copying one section\'s periods over another\'s. The grid is cleared before every fetch and a late reply is dropped if the selection moved on, so a failed or slow load can never leave the previous section\'s cells on screen. The date sheet carries the same stamp for its term and class (EX_KEY).',
         'timetable.php resetTT() / TT_SEC / EX_KEY — the class timetable and exam date sheet tabs'],
        ['Teacher clash rule',
         'A timetable saves as a whole section grid (wipe + rewrite in one transaction), and before writing, every slot with a teacher is checked against every OTHER section\'s same day + period in ONE row-constructor lookup, never a query per period. Any hit blocks the whole save — nothing is written — and names the section holding the teacher, so a teacher can never be scheduled into two rooms at once. The lookup is fenced to this school (a clash message must never name another tenant\'s class) but deliberately NOT to one campus: a teacher booked in two campuses at the same period is still in two rooms at once, so it must still block — a campus admin is simply told "another campus" instead of a class they cannot reach. The subject dropdown is built from class_subjects AND the save re-checks membership server-side, because a filtered dropdown is not a fence.',
         'timetable.php saveTimetable — timetable_slots, uniq (section, day, period)'],
        ['Exam date sheet',
         'One row per subject of the class per term, unique on (term, class, subject). Saving upserts dated rows and deletes rows whose date was cleared, so the sheet always mirrors the editor. Unscheduled subjects list first as blanks to fill; the print shows dated papers in date order.',
         'timetable.php saveExams — exam_schedule table'],
    ],
    'Tenancy, branches & subscription' => [
        ['Which rows a school reaches',
         'Every tenant-owned table carries school_id, and every query is fenced by sid() — the signed-in school, resolved once per request. "School-wide" means the whole of THIS school, never the whole table: the reach helper ormsSchoolWide() covers Admin, Principal and Branch Admin, and each of them still reads only their own school. sid() fails closed, so a session that somehow carries no school is destroyed rather than treated as unrestricted. The platform operator is a separate flag (is_super), never "school 0 means unscoped".',
         'sid() / ormsSchoolWide() / ormsHasTenancy() in config.php — every page'],
        ['Which branch a user reaches',
         'Classes and sections belong to a branch, while academic years and exam terms are school-wide, so all branches share one session and result calendar. A Branch Admin is pinned by ormsBranchLock() and that pin is part of the WHERE clause, not a screen filter. Everyone else school-wide may switch branches from the sidebar, which is a filter (bid()) rather than a boundary — "All branches" is the default.',
         'ormsBranchLock() / bid() / ormsSectionScope() in config.php — sidebar switcher, classes, students, teachers'],
        ['Turning an id from the URL into a row',
         'Any id arriving in $_GET or $_POST is resolved before it is used: ormsOwns() checks the row belongs to this school (and this branch for a Branch Admin) and returns 0 otherwise, so another school\'s record reads exactly like a bad id — no separate error, nothing to probe. Pages call the ormsOwn* wrappers, which fall through to a plain integer on a database that has not been migrated yet.',
         'ormsOwns() / ormsFind*() in config.php, ormsOwn*() in result_engine.php — every page that accepts an id'],
        ['Per-school settings',
         'Settings resolve school row first, then the platform row (school_id 0) as a fallback. That is what lets each school own its result-card branding, admission prefix and grading policy, while SMTP, push keys, Google OAuth and the currency stay the operator\'s. Saving writes a row for the signed-in school, so one school can never overwrite another\'s value. The currency is the exception that proves the rule: it is read from the platform row FLAT, so a school row can never shadow it and every role on every school prints the same symbol.',
         'getSetting() / setSetting() in config.php — every settings surface'],
        ['When a school loses access',
         'Blocked when the school is Suspended or Cancelled, when a Trial school is past trial_ends_at, or when its newest subscription period has lapsed. Blocking is not a logout: the session survives and every page routes to renew.php, with logout and the operator\'s way back out deliberately left open. The gate fails OPEN on any error — a billing check that failed closed would take every school offline over one bad query — and is completely inert while platform_mode is 0.',
         'ormsSubscriptionState() / ormsEnforceSubscription() in config.php — runs before the password gate'],
        ['Renewal dates',
         'A renewal never edits a period row; it inserts a new one starting at the later of today and the current end date, so renewing early extends the subscription instead of truncating it. Only genuinely lapsed rows are marked Expired. Month arithmetic clamps the day (31 Jan + 1 month = 28 Feb). Money is stored DECIMAL. There is one platform currency: every NEW period, payment and invoice is stamped with it, while rows already written keep the currency they were raised in, so history is never re-labelled.',
         'subscriptions.php — record payment, extend, change plan'],
    ],
    'Card templates & term-wise report' => [
        ['Template resolution',
         'Each card resolves its design as: staff preview override (?template=, staff only — Admin, Principal or a teacher of that section) → classes.result_template → the result_template setting → Classic. Registry: Classic Navy, Compact Print, Modern Accent, Elegant Serif, Board Marksheet and Formal Slip (single-term) plus Academic Report, Progress Grades and Consolidated Mark Sheet (term-wise). An unknown key always falls back to Classic.',
         'ormsResolveTemplate(), ormsRenderResultCard() — result card, bulk section print'],
        ['Consolidated Mark Sheet layout',
         'Board-style sheet: one FULL MARK / SECURED MARK column pair per term, a Total row summing each term, then Percentage, Grade, optional GPA, Result (PASS/FAIL) and optional Position as per-term footer rows. Full marks come from the marks snapshot, so a later configuration change never rewrites an issued sheet. A term the student has no entry for prints an em dash rather than a zero, which is also how a subject the student is not enrolled in reads.',
         'ormsCardMarksheet() — Consolidated Mark Sheet template'],
        ['Term-wise term inclusion',
         'The term-wise designs (Academic Report, Progress Grades, Consolidated Mark Sheet) show only published terms of the academic year, plus the term actually being viewed (so a staff draft preview — Admin, Principal or a teacher of that section — can include it, marked with * as draft). Other terms\' drafts are never shown to anyone.',
         'ormsStudentYearResult() — term-wise templates'],
        ['Term weightage',
         'Result Settings › Exam Terms can give each term a weight (validated to at most 100% per year). The moment any term of the year carries a weight above 0, the term-wise designs switch to the WEIGHTED year result and print the weight beside each term name; with every weight at 0 (the default) the plain marks-sum below applies unchanged.',
         'exam_terms.weightage, ormsMergeTermSlices() weighted flag — Academic Report and Progress Grades'],
        ['Term-wise subject overall',
         'Plain mode — per subject: sum of obtained ÷ sum of total across the terms where marks were entered (an absent entry counts 0 obtained but full total); passed = combined obtained ≥ the summed passing marks. Weighted mode — per subject: Σ(term % × term weight) ÷ Σ(weights of the terms where that subject was entered), so a partial year renormalises instead of punishing; passed = that weighted % at or above the weighted passing threshold. Both modes: the grade is the normal band lookup on the resulting percentage AND an is_fail band still fails the subject.',
         'ormsMergeTermSlices() — Academic Report and Progress Grades subject rows'],
        ['Head teacher signature block',
         'The right-hand signature area prints the uploaded signature image (result_principal_signature) and the head teacher\'s name (result_principal_name) above the signature label, with result_principal_designation as the line beneath the name; "Show principal signature" hides the block outright. With no name and no image configured the card falls back to the plain label it printed before, so an unconfigured school loses nothing.',
         'Result Settings › Result Card branding — signature row of every card template'],
        ['Key to Grades block',
         'Every card skin prints the bands of the set that graded it — the grade, its range and the parent-facing interpretation, with the failing bands marked, so a family can read what B2 actually means. The interpretation column falls back to the band remark when no interpretation has been written, so a school that never fills it in still prints something useful. The block is switched off with result_show_grade_key and the line underneath it comes from result_grade_key_note.',
         'ormsKeyToGrades() in result_engine.php — every result card template'],
        ['Term-wise year overall',
         'Raw totals always sum the counted subjects\' actual marks. Plain mode: percentage, grade and GPA come from those sums (GPA = average of the per-subject overall grade points). Weighted mode: year percentage = Σ(term overall % × weight) ÷ Σweights and year GPA = Σ(term GPA × weight) ÷ Σweights, with the grade from the weighted percentage. Year PASS in both modes = every counted subject passed by its own mode\'s rule. Per-term Total/%/Grade/Position rows read each term\'s published snapshot, live maths for the open draft term.',
         'ormsStudentYearResult(), ormsCardReport(), ormsCardProgress()'],
    ],
    'Entry, completion & publishing' => [
        ['Completion percentage',
         'round(entered ÷ expected × 100, 2) where expected = active students × core subjects + each student\'s own elective enrolments — an optional subject expects a cell only from the students who take it. Excluded-from-total subjects still count here: completion measures typing, not scoring. Only marks rows that still join an Active student, a live class_subject mapping and (for electives) a live enrolment are counted, and the count is clamped so stale rows can never push past 100%.',
         'ormsSectionCompletion() + ormsElectiveSql() — results overview, dashboards, marks entry cards'],
        ['Academic year lock',
         'academic_years.is_locked is the year-end close and the widest entry gate of all. While it is on, every term inside that year is read-only: the assignment cards refuse to open for editing, the grid shows the locked banner, and the save handler rejects the request outright — unpublishing cannot reopen it, only unlocking the year can. The current year can never be locked, and a locked year can never be made current.',
         'meYearLock() in marks_entry.php — cards, grid and save gate; set in Result Settings › Academic Years'],
        ['Marks entry permission gate',
         'Entry is allowed only when the term status is Open, the section is not published for that term, the term\'s academic year is not locked, and the user is either an Admin or a teacher holding a teacher_subjects row for that exact section, subject and the term\'s academic year. All four are re-checked on the server before any row is touched — a disabled input is never trusted. A Principal is neither an Admin nor an owning teacher — they hold no teachers row at all — so the gate refuses them by identity, whatever the matrix says: Marks Entry opens for them as View, showing progress, never a saveable cell.',
         'ormsCanEnterMarks() in config.php + meYearLock() — marks_entry.php load and save'],
        ['Bulk save',
         'CSRF, then the page permission, then ownership for each distinct section + subject, then the term / publish / year-lock re-check, then per-row validation, then the total and passing snapshot read from class_subjects. Only after all of that: one transaction and one prepared INSERT ... ON DUPLICATE KEY UPDATE of 16 columns on the unique key student + subject + term, executed per row, then a single activity log entry. Bad rows are skipped and reported instead of aborting the batch.',
         'marks_entry.php save handler'],
        ['Cleared cell',
         'A cell left blank and not marked absent deletes its marks row instead of storing a NULL mark — a NULL would score 0 and fail the subject. Because the remark lives on that same row, clearing the mark clears the remark with it.',
         'marks_entry.php save handler'],
        ['Publish gate',
         'Publishing is refused unless completion is exactly 100%, the section actually has students and mapped subjects, and — while the approval setting is on — the section already reads Approved. Publishing computes every summary and flips result_publications in one transaction, then notifies the students.',
         'ormsPublishSection() in result_engine.php'],
        ['Approval gate',
         'A finished section walks Draft → Pending → Approved before it can go live. Submitting it for approval stamps submitted_by and submitted_at and moves it to Pending; approving stamps approved_by and approved_at and moves it to Approved; rejecting sends it back to Draft and the review note is mandatory, so the reason for the bounce always travels with the section. The state lives in result_publications.approval_status and is re-read on the server at the moment of publishing — the button being visible is never the permission. Sections that were already live when the gate was introduced were back-filled as Approved, so switching the feature on strands nothing.',
         'result_publications.approval_status / submitted_by / approved_by / review_note — results.php submit, approve and reject actions'],
        ['Who may approve',
         'A Principal or an Admin. The head teacher is the intended approver, but an Admin can approve as well, on purpose: a school that has not created a Principal account yet, or one whose head teacher has left, must still be able to publish. Nobody else can approve, whatever the matrix grants them on the Results page.',
         'results.php approve / reject actions'],
        ['Approval requirement toggle',
         'Result Settings › "Require head teacher approval" (require_principal_approval), on by default. Switched off, a section at 100% goes straight to publish exactly as it did before; switched on, Approved becomes one more condition on top of the completion check. Turning it off does not erase the approval trail already stored, so switching it back on picks up where the school left off.',
         'require_principal_approval setting — results.php publish gate'],
        ['Unpublish',
         'Admin or Principal (the same school-wide reach and results Edit bit that publishing needs) and a reason is mandatory. Retracting a wrong result is an academic call, so a head teacher who can put results live can also pull them back. It deletes the stored summaries and clears the publish flag, which reopens marks entry, and it resets the section to Draft — a corrected section is therefore submitted and approved again before it can go live a second time. The whole sign-off trail is cleared with it; the activity log keeps the history.',
         'ormsUnpublishSection() in result_engine.php'],
    ],
    'Records & identity' => [
        ['Admission number',
         'PREFIX-YEAR-0001. The prefix comes from the admission_no_prefix setting, stripped to letters and digits and capped at 10 characters (default STU). The year is the last four-digit group of the current academic year name, so 2025-2026 becomes 2026. The sequence is the highest existing tail for that prefix and year plus one, generated inside the registration transaction.',
         'stuNextAdmissionNo() in students.php — also becomes the student login username'],
        ['Total marks snapshot',
         'marks.total_marks is copied from class_subjects at save time, and the result maths always prefers that stored value over the current configuration. Changing a subject total later affects new entries only — past results are never rewritten.',
         'marks_entry.php save handler, ormsComputeStudentRow()'],
    ],
    'Verification & merit' => [
        ['QR verification',
         'Every published summary row carries a random 128-bit verify_token (32 hex chars), minted at publish and CARRIED ACROSS republishes so an already-printed card\'s QR keeps working. The card prints the token as a QR (toggle: Result Settings › "Verification QR code") that resolves to index.php?verify=<token> — a public page that shows the stored student, term, totals, grade and PASS/FAIL when, and only when, the token matches a currently published summary. Unpublishing kills the link; every failure mode answers with one identical line; lookups share the public form\'s 8-per-10-minutes IP throttle; the token itself is never written to the activity log.',
         'ormsWriteSummaries() token mint, ormsCardTail() + ormsVerifyUrl() QR, index.php ?verify route'],
        ['Position visibility',
         'A card prints the position only when the global "Position in summary" toggle is on AND the class\'s own "Show Position on Card" flag is on (classes.show_position — junior classes commonly switch it off). The same double gate applies to the public verify page. Staff screens (tabulation, merit tab) always see positions — Admin, Principal and the section\'s teacher alike.',
         'ormsClassShowsPosition(), ormsCardSummary()/Formal/Report/Progress, index.php verify view'],
        ['Merit & analytics',
         'Results › Merit & Analytics, per exam term: Toppers = stored published rankings with position ≤ 3 per section (ties included); Subject toppers = highest round(obtained ÷ total × 100, 2) per class and subject over entered marks, every tie listed; Teacher performance = per teacher_subjects assignment, average % (absent counts 0) and pass rate = entered marks at or above their snapshotted passing marks; Year trend = average published percentage per class per academic year. Admin and Principal read the whole school here, because reach is decided by who the user is (ormsSchoolWide) and never by a permission tick; a teacher sees only the sections their assignments reach, through the same ormsSectionScope() the rest of the page uses. Every read is additionally fenced to the signed-in school.',
         'results.php getMerit — Merit & Analytics tab'],
    ],
    'Dashboard figures' => [
        ['Monthly intake delta',
         'The caption under each KPI counts the rows created in the CURRENT calendar month only — students and teachers from their created_at, classes and subjects the same way, published sections from published_at. It is an intake reading, never a running total, so a quiet month honestly reads "No new admissions this month" instead of repeating the headline number.',
         'dashboard.php adminStats — Active Students / Active Teachers cards'],
        ['KPI sparkline',
         'Six buckets, one per calendar month ending with the current one, each holding that month\'s new rows for the same five sources. Missing months are zero, not skipped, so the line keeps a true time axis. All five series come back in ONE union query and the whole block is wrapped in a probe — a database that predates a table renders flat cards instead of an error.',
         'dashboard.php adminStats -> spark, drawn by spark() on the kpi cards'],
        ['Dashboard chart term',
         'Pass % per Class and Grade Distribution both read ONE term — the one picked in either card\'s dropdown. The default is the most recently published term, falling back to the active term. The posted id is whitelisted against this academic year\'s terms before it reaches a query, and both charts always show the same term so two panels can never disagree about what is on screen. Grade Distribution counts published summaries only, folding the grading scheme to one row per letter first so a letter that exists in several grading sets cannot double-count a student.',
         'dashboard.php adminStats term_id + pickTerm() — admin dashboard'],
        ['Dashboard rings',
         'Marks Entry ring = the term-wide completion percentage (same expected-vs-entered rule as everywhere else, clamped per section); Pending Entries = expected − entered. Pass Rate ring = passed ÷ total of the latest PUBLISHED term, and Students Failed is that term\'s remainder — a draft term never moves it. Outstanding Fees and Average Attendance repeat the arrears and attendance readings, and simply do not render when those tables are not installed.',
         'dashboard.php renderGauges() / renderMoney() — admin and principal dashboards'],
        ['One dashboard per role',
         'App Owner → the platform view; School Owner → the school view plus the subscription row, campuses and invoices; Admin → the school view; Principal → oversight (comparison, approval queue, top performers); Branch Admin → the school view fenced to the pinned campus; Teacher → their own sections; Student / Parent → their own card and day. The three wide views read ONE aggregate (dashSchoolPayload) so an owner, an admin and a branch admin can never see different maths for the same figure — the branch admin\'s copy only adds the campus predicate. Every KPI footer is a link only when that role can open the page.',
         'dashboard.php $view resolution, adminStats / ownerStats / branchStats -> dashSchoolPayload()'],
        ['Today strip',
         'Attendance today = P rows ÷ all rows of attendance_daily dated today, with "sections marked" = sections that have any row today over the active sections. Fees collected = Σ Payment rows whose entry_date falls in the current calendar month (and separately today); the Outstanding tile stays the netted per-student arrears. Next exam = the earliest exam_schedule row dated today or later, with the count sitting inside the coming 7 days. Timetable filled = sections holding at least one period ÷ active sections. Unread = the viewer\'s notifications with is_read = 0. Every leg is probe-gated: a table not installed yet returns null and its tile is hidden, never zeroed.',
         'dashOps() in dashboard.php — admin, owner, branch and principal strips'],
        ['Campus fence',
         'On the Branch Admin dashboard every counter carries AND branch_id = bid() — classes hold the campus, students and teachers inherit it; sections, marks, summaries and publications reach it through their class; student_fees walks through the student row. The pin is the session\'s branch, never a posted id, so a campus admin cannot read another campus by editing a request. Subjects stay school-wide because they have no campus.',
         'dashSchoolPayload($branch) / dashSectionProgress($branch) / dashOps($branch) / dashBranchAnd()'],
        ['Owner subscription tiles',
         'Plan and period come from ormsSubscriptionState — the same resolver billing.php reads — so the dashboard and the billing page can never disagree. Days left = floor((ends_at − today) ÷ 86400); the tile turns amber at 30 days and red once the period lapsed or the school is suspended. Seats read ormsQuotaAll: while platform_mode is 0 or the plan cap is 0 the tile reports the live headcount with "no seat limit", otherwise used / cap with amber at 85% and red when full. Pending invoices = billing_invoices rows in Pending for this school. Campus cards show students against the campus capacity (amber 85%, red 100%) — a planning figure, never a limit.',
         'dashSub() / dashBranches() in dashboard.php — ownerStats'],
        ['Platform charts',
         'Collections = six calendar-month buckets of subscription_payments.amount by paid_on, ending with the current month, missing months kept as zero so the axis stays true. Schools by plan = COUNT of schools per plans.name, a school with no plan counted under "No plan".',
         'dashboard.php platformStats -> rev6 / by_plan'],
        ['Teacher day',
         'Periods today = timetable_slots where teacher_id is the signed-in teacher and day_of_week equals the ISO weekday (1 = Monday, the same axis the grid saves). Registers due = the teacher\'s assigned sections (this year, active) with no attendance_daily row dated today — shown only when the role can add attendance. Upcoming exams = the next six date-sheet rows for any class the teacher teaches this year.',
         'dashboard.php teacherStats -> today / att_due / exams'],
        ['Student day',
         'Attendance % this year = P ÷ all attendance_daily rows for the student in the current academic year (absent, late and leave listed beside it). Fee position = ormsFeeBalance for the current year — positive is due, negative is credit — plus the manual fee_hold flag, which turns the tile red and says the card is on hold. Today\'s periods come from the student\'s own section; the next exam from the student\'s class.',
         'dashboard.php studentStats -> att / fee / today / next_exam'],
    ],
    'Subscription, plan limits & billing' => [
        ['Which currency',
         'ONE currency for the whole install, set by the App Owner in Site Settings and stored as system_settings(school_id 0, currency_code). ormsCurrency() reads it flat off the platform row, and bilCurrency() / bilCurrencyFor() both return it, so the school side (fees, result cards) and the billing side (plans, invoices, gateways) cannot drift apart. Schools cannot override it. Amounts are relabelled, never converted — no FX rate exists anywhere, because a rate that drifts overnight silently changes what a customer is charged. Invoices, payments and periods already written keep their own stored currency. schools.billing_currency and plan_prices are retained as history and deliberately not read.',
         'ormsCurrency() / ormsMoney() in config.php, bilCurrency() / bilCurrencyFor() in billing_engine.php'],
        ['Plan seat count',
         'A plan caps students, teachers and branches; 0 in any column means unlimited, and a school with no plan is unlimited. Students count only where status is NOT "Passed Out" or "Transferred" — alumni never occupy a seat, or a school would hit its ceiling by year three purely from graduating. Teachers count status = Active, branches count status = Active. The whole layer is inert while platform_mode is 0, so a single-school install is never capped.',
         'ormsQuota() in config.php — meters on billing.php, gate on every add'],
        ['Seats remaining',
         'left = max(0, cap - used). An add is allowed when room >= 1, and a bulk import is capped to exactly the number of seats left: the rows that fit are imported and the overflow comes back as ordinary skips, never a failed upload. Both the single add and the import ask the SAME function, so the two can never disagree about what the plan allows.',
         'ormsQuotaRoom() — students.php / teachers.php / branches.php add + import'],
        ['Oversell guard',
         'The seat check runs twice: once before any work for a clean error, then again inside the insert transaction after SELECT ... FOR UPDATE on the school row. Two admins clicking Add in the same second both clear an unlocked count, so the locked re-check is the one that actually decides.',
         'ormsQuotaRoomLocked() — inside each add transaction'],
        ['Subscription period',
         'start = the LATER of today and the running period end date, so renewing early EXTENDS and never truncates. end = one day before the same date next month (or next year), so back-to-back periods never share a date. A month-end start clamps: 31 Jan + 1 month is 28 Feb.',
         'bilPeriodEnd() / bilAddMonths() in billing_engine.php'],
        ['Payment applied once',
         'An invoice is locked and re-read inside the transaction; if it is already Paid the call returns quietly instead of buying a second period, so a replayed webhook is harmless. UNIQUE(gateway, gateway_ref) makes it structurally impossible for a second invoice to be settled by a reference the first one used.',
         'bilApplyPayment() — shared by the Stripe webhook and operator approval'],
        ['Amount check',
         'Paid amount is compared to the invoice in MINOR UNITS (integers), never as floats, and the currency must match exactly. Underpayment buys nothing; overpayment is accepted because refunding is a human decision.',
         'bilApplyPayment() / bilMinor() — 2-decimal and zero-decimal currencies'],
        ['What opens access',
         'Only the webhook. Returning from a gateway proves a browser followed a redirect, nothing more, so payment_return.php grants nothing and simply reports what the webhook has recorded. Stripe callbacks are verified by HMAC-SHA256 over "timestamp.rawbody" with a 300-second tolerance, compared with hash_equals — a captured callback cannot be replayed later.',
         'payment_webhook.php + bilStripeVerify()'],
        ['Subscription gate',
         'Blocked when the school is Suspended/Cancelled, when a Trial school is past trial_ends_at, or when its newest period has lapsed. Blocking is not a logout: every page routes to renew.php while logout, account, billing and the gateway round trip stay open so a lapsed school can always pay or leave.',
         'ormsSubscriptionState() / ormsEnforceSubscription() in config.php'],
    ],

    'Chain of authority (who may manage whom)' => [
        ['Role rank',
         'One pinned ladder, lower number = more authority: App Owner 0, School Owner 10, Admin 20, Principal 30, Branch Admin 40, Teacher 50, Student 60. A role invented in Roles & Permissions parks at 25 or below-Admin whatever sort order it is given. The rank is deliberately NOT read from roles.sort_order, because that column is editable by an Admin and the ladder would then be re-orderable by the very role it constrains.',
         'ormsRoleRank() / ormsRoleLadder() in config.php'],
        ['May I grant this role?',
         'rank(target role) >= rank(mine). Never a role stronger than your own — that is how an Admin would mint an Owner login it knows the password of and walk into billing. One exception, the hand-over: when nobody in the school outranks you, you ARE the top of the chain and may create the role above you, so an install with no Owner is never a dead end.',
         'ormsCanAssignRole() / ormsTopRank() — users.php add + edit'],
        ['May I act on this account?',
         'rank(target) >= rank(mine), or it is your own row, or you are the App Owner. Applied identically to edit, delete and "Login as", because resetting a password, deleting the row and wearing the session are the same takeover by three routes.',
         'ormsOutranks() — users.php updateUser/deleteUser, impersonate.php'],
        ['Exactly one School Owner',
         'Granting the role is a TRANSFER, never a second owner: whoever holds it steps down to Admin in the same save, schools.owner_user_id follows the new holder, the outgoing owner is notified and their live session is corrected on the spot. The collapse re-runs on every owner save, so a school that somehow acquired two owners heals itself.',
         'users.php updateUser owner stamp — ormsSchoolOwnerId() reads the ROLE first, the pointer second'],
        ['Branch seat usage',
         'used = active students of the branch, cap = branches.capacity (0 = uncapped, and an uncapped campus shows the headcount alone rather than a division by zero). pct = round(used / cap x 100); the chip turns amber at 85% and red at 100%. This is a local planning figure, NOT a limit — the plan quota still applies on top of it.',
         'seatsCell() in branches.php — Seats column + the Seats-used summary chip'],
        ['Branch re-allocation',
         'A campus may only be handed to another school while it is EMPTY (no students, teachers, classes or logins) and while it is not that school\'s main branch. Every one of those rows carries its own school_id, so moving the branch alone would strand them in a tenant that cannot see them. A campus arriving in a school that has no branches becomes that school\'s main one.',
         'branches.php saveBranch — operator only'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>About - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body class="initially-hidden">
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-info-circle"></i> About App</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>About App</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <div class="about-section">
                <div class="about-header">
                    <div class="about-logo">
                        <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="App Logo">
                    </div>
                    <div class="about-title">
                        <h1><?php echo htmlspecialchars($branding['site_name']); ?></h1>
                        <p class="about-dev">Online Result Management System &mdash; developed by <strong>Mohammad Rameez Imdad</strong> (Rameez Scripts)</p>
                    </div>
                </div>

                <div class="stat-mini">
                    <div><i class="fas fa-user-shield"></i> Signed in as <?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($role !== '' ? $role : 'role not set'); ?>)</div>
                    <div><i class="fas fa-calendar-days"></i> Academic year: <?php echo htmlspecialchars($curYear['name'] ?? 'not set'); ?></div>
                    <div><i class="fas fa-user-tag"></i> Roles: <?php echo count($aboutRoles); ?></div>
                    <div><i class="fas fa-table-list"></i> Pages: <?php echo count($RBAC_PAGES); ?></div>
                    <div><i class="fas fa-ranking-star"></i> Grade bands: <?php echo $bandsBySet ? array_sum(array_map('count', $bandsBySet)) : count($bands); ?></div>
                    <?php if (count($gradeSets) > 1): ?><div><i class="fas fa-layer-group"></i> Grading sets: <?php echo count($gradeSets); ?></div><?php endif; ?>
                </div>

                <div class="tab-nav">
                    <button type="button" class="tab-btn active" data-tab="tabOverview"><i class="fas fa-circle-info"></i> Overview</button>
                    <button type="button" class="tab-btn" data-tab="tabRoles"><i class="fas fa-user-shield"></i> Roles &amp; Access</button>
                    <button type="button" class="tab-btn" data-tab="tabFormulas"><i class="fas fa-calculator"></i> Formulas</button>
                    <button type="button" class="tab-btn" data-tab="tabGrading"><i class="fas fa-ranking-star"></i> Grading Scheme</button>
                    <button type="button" class="tab-btn" data-tab="tabModules"><i class="fas fa-diagram-project"></i> Modules</button>
                    <button type="button" class="tab-btn" data-tab="tabData"><i class="fas fa-database"></i> Data Model</button>
                    <button type="button" class="tab-btn" data-tab="tabDemo"><i class="fas fa-key"></i> Demo Logins</button>
                </div>

                <!-- ============================ Overview ============================ -->
                <div class="tab-pane active" id="tabOverview">
                    <div class="about-card">
                        <h2><i class="fas fa-question-circle"></i> What is this App?</h2>
                        <p>This is an Online Result Management System for a school. It keeps the whole exam cycle in one place: register students and give them a login, organise classes, sections and subjects, let teachers type a whole class of marks in one popup, let the head teacher check a finished section and approve it, let the admin publish it, and let students open and print their own branded result card.</p>
                        <p>Nothing on the result card is hard-coded. The school name, logo, address, footer note, signature labels, grade bands, exam terms and the show/hide toggles for photo, GPA and position all come from <strong>Result Settings</strong>, so the printed card follows the settings without a code change.</p>
                    </div>

                    <div class="about-card">
                        <h2><i class="fas fa-route"></i> How the Cycle Runs</h2>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Step</th><th>Who</th><th>What happens</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td class="step-num">1</td><td class="step-name">Admin</td><td>Sets the academic year and exam terms, then builds classes, sections and subjects, and configures total and passing marks per class subject.</td></tr>
                                    <tr><td class="step-num">2</td><td class="step-name">Admin</td><td>Registers students &mdash; the login account and the student profile are created together, and an admission number becomes the username.</td></tr>
                                    <tr><td class="step-num">3</td><td class="step-name">Admin</td><td>Adds teachers and assigns each of them the exact section and subject they teach for the year.</td></tr>
                                    <tr><td class="step-num">4</td><td class="step-name">Teacher</td><td>Opens Marks Entry while the term is Open and fills the whole roster in one grid, seeing only the subjects assigned to them.</td></tr>
                                    <tr><td class="step-num">5</td><td class="step-name">Admin</td><td>Watches completion per section and, once it reaches 100%, sends the section to the head teacher for approval.</td></tr>
                                    <tr><td class="step-num">6</td><td class="step-name">Principal</td><td>Checks the tabulation sheet and either approves the section or rejects it back with a note saying what to fix. An admin may approve too, so nothing stalls when there is no head teacher on the system. This step is skipped when &ldquo;Require head teacher approval&rdquo; is switched off.</td></tr>
                                    <tr><td class="step-num">7</td><td class="step-name">Admin</td><td>Publishes the approved section. Publishing freezes totals, grades, GPA and positions, and locks marks entry for that term.</td></tr>
                                    <tr class="step-final"><td class="step-num">8</td><td class="step-name">Student</td><td>Gets a notification, opens My Results and prints the result card. Only published results are ever visible.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="about-card">
                        <h2><i class="fas fa-clipboard-list"></i> What Can You Do With It?</h2>
                        <ul class="about-features">
                            <li><i class="fas fa-user-graduate"></i> Student Registration</li>
                            <li><i class="fas fa-file-csv"></i> CSV Import &amp; Promotion</li>
                            <li><i class="fas fa-chalkboard-teacher"></i> Teacher Assignments</li>
                            <li><i class="fas fa-school"></i> Classes &amp; Sections</li>
                            <li><i class="fas fa-book"></i> Subjects &amp; Marks Config</li>
                            <li><i class="fas fa-pen-to-square"></i> Bulk Marks Entry</li>
                            <li><i class="fas fa-user-check"></i> Head Teacher Approval</li>
                            <li><i class="fas fa-award"></i> Publishing &amp; Positions</li>
                            <li><i class="fas fa-lock"></i> Year-End Close</li>
                            <li><i class="fas fa-table"></i> Tabulation Sheet</li>
                            <li><i class="fas fa-print"></i> Printable Result Cards</li>
                            <li><i class="fas fa-sliders"></i> Configurable Branding</li>
                            <li><i class="fas fa-ranking-star"></i> Editable Grading Scheme</li>
                            <li><i class="fas fa-layer-group"></i> Multiple Grading Sets</li>
                            <li><i class="fas fa-scale-balanced"></i> CA &amp; Exam Weighting</li>
                            <li><i class="fas fa-table-cells"></i> Class Broadsheet</li>
                            <li><i class="fas fa-user-check"></i> Attendance on the Card</li>
                            <li><i class="fas fa-money-bill"></i> Fees &amp; Result Withholding</li>
                            <li><i class="fas fa-bell"></i> Publish Notifications</li>
                            <li><i class="fas fa-bell-concierge"></i> Web Push Alerts</li>
                            <li><i class="fas fa-user-shield"></i> Editable Role Matrix</li>
                            <li><i class="fas fa-history"></i> Full Audit Trail</li>
                            <li><i class="fas fa-database"></i> Database Backup</li>
                        </ul>
                    </div>

                    <div class="about-card">
                        <h2><i class="fas fa-shield-halved"></i> How Data Stays Correct</h2>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Rule</th><th>Why it exists</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td class="step-name">Snapshot totals</td><td>Every marks row keeps the total it was entered with, so editing a subject configuration later can never rewrite last term's results.</td></tr>
                                    <tr><td class="step-name">Publish freezes results</td><td>Totals, grade, GPA, position and the &ldquo;of N&rdquo; denominator are written into result_summaries at publish, so a late edit elsewhere cannot shuffle someone's position.</td></tr>
                                    <tr><td class="step-name">Year-end close</td><td>Locking an academic year makes every term inside it read-only for marks entry, on the server as well as in the UI. Only unlocking the year reopens it &mdash; unpublishing a section does not.</td></tr>
                                    <tr><td class="step-name">Server-side scoping</td><td>Which rows a user reaches is decided in the SQL WHERE clause, never hidden in the browser: Admin and Principal are school-wide, a teacher is held to their own assignments and a student to their own row. Reach follows who the user is, so ticking Edit for a role widens what they may change, never how much of the school they can see. Editing a URL does not widen access either.</td></tr>
                                    <tr><td class="step-name">Ownership re-checked on save</td><td>Marks are re-verified against the teacher's assignments, the term status, the year lock and the publish state on every request.</td></tr>
                                    <tr><td class="step-name">Approval before publish</td><td>A finished section is checked by the head teacher before a single student sees it, and who submitted it, who approved it and any rejection note stay on the record afterwards.</td></tr>
                                    <tr><td class="step-name">The grading set is frozen too</td><td>A published summary remembers which grading set graded it, so pointing a class at a different set later changes new results only &mdash; a card already issued keeps the grades it was printed with.</td></tr>
                                    <tr><td class="step-name">Withholding hides, never blocks</td><td>A fee hold hides one family's card and nothing else. The section still publishes, positions and averages still count that student, and staff still read the card with a WITHHELD stamp &mdash; so money never quietly edits the academic record.</td></tr>
                                    <tr><td class="step-name">History survives promotion</td><td>Marks carry their own class, section and year, so promoting a student to the next class leaves old results intact.</td></tr>
                                    <tr><td class="step-name">Soft delete</td><td>Students are deactivated rather than removed, and a hard delete is blocked while marks reference them.</td></tr>
                                    <tr><td class="step-name">Everything is logged</td><td>Create, update, delete, bulk save, publish and unpublish all write to the activity log with user and IP.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================ Roles & Access ============================ -->
                <div class="tab-pane" id="tabRoles">
                    <div class="about-card">
                        <h2><i class="fas fa-users-cog"></i> Roles &amp; Permission Matrix</h2>

                        <div class="info-banner info-banner-top mb-24">
                            <i class="fas fa-circle-info"></i>
                            <span>This table is not written by hand. It is read live from the <strong>roles</strong> table and the page registry every time the page loads, so any checkbox changed in <strong>Roles &amp; Permissions</strong> shows up here on the next refresh.</span>
                        </div>

                        <p class="mb-24">Each cell lists the four rights for that page &mdash; <strong>V</strong>iew, <strong>A</strong>dd, <strong>E</strong>dit, <strong>D</strong>elete. A green tick means granted, a grey dash means denied.</p>

                        <p class="mb-24">Three pages joined the matrix with the broadsheet, attendance and fees work, and setup seeds them like this. <strong>Broadsheet</strong> &mdash; Admin full, Principal View, Teacher View, and a teacher only ever reaches the sections their assignments cover. <strong>Attendance</strong> &mdash; Admin full, Teacher View, Add and Edit on their own sections, Principal View. <strong>Fees</strong> &mdash; Admin full, Principal View and Edit so a head teacher can place or lift a hold without ever erasing a ledger row, and nothing at all for a teacher or a student. Fees is the first page in the new <strong>Finance</strong> group. The table below is the live matrix, so a school that edits those ticks reads its own answer here, never the seeded one.</p>

                        <?php if (empty($aboutRoles)): ?>
                        <div class="orms-empty">
                            <i class="fas fa-user-shield"></i>
                            <h4>No roles found</h4>
                            <p>Run <strong>setup.php</strong> to seed the roles table, then reload this page.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="about-roles-table">
                                <thead>
                                    <tr>
                                        <th>Page / Feature</th>
                                        <?php foreach ($aboutRoles as $r): ?>
                                        <th><span class="role-badge rbac-rolehead" data-role-color="<?php echo htmlspecialchars(aboutRoleColor($r['color'])); ?>"><?php echo htmlspecialchars($r['label']); ?></span></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($RBAC_GROUPS as $grp):
                                        $pagesInGroup = array_filter($RBAC_PAGES, fn($p) => $p['group'] === $grp);
                                        if (!$pagesInGroup) continue;
                                    ?>
                                    <tr class="rbac-grouprow">
                                        <td colspan="<?php echo count($aboutRoles) + 1; ?>"><?php echo htmlspecialchars($grp); ?></td>
                                    </tr>
                                    <?php foreach ($pagesInGroup as $pg): ?>
                                    <tr>
                                        <td><i class="fas <?php echo htmlspecialchars($pg['icon']); ?>"></i> <?php echo htmlspecialchars($pg['label']); ?></td>
                                        <?php foreach ($aboutRoles as $r):
                                            $c = $r['perms'][$pg['key']] ?? ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
                                        ?>
                                        <td>
                                            <?php foreach (['v' => 'View', 'a' => 'Add', 'e' => 'Edit', 'd' => 'Delete'] as $k => $lbl):
                                                $on = !empty($c[$k]);
                                            ?>
                                            <span class="<?php echo $on ? 'perm-on' : 'perm-off'; ?>" title="<?php echo htmlspecialchars($lbl . ($on ? ' granted' : ' denied')); ?>"><i class="fas <?php echo $on ? 'fa-check' : 'fa-minus'; ?>"></i> <?php echo htmlspecialchars(strtoupper($k)); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="about-card">
                        <h2><i class="fas fa-sitemap"></i> Chain of Authority</h2>
                        <p class="mb-24">The permission matrix answers &ldquo;which pages open&rdquo;. The chain answers <em>&ldquo;who may manage whom&rdquo;</em> &mdash; a different question, and the one that decides whether an account can be taken over. Authority runs top to bottom and the ladder is pinned in code, not read from the sortable column in Roles &amp; Permissions.</p>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Rank</th><th>Role</th><th>Owns</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td class="step-name">0</td><td>App Owner</td><td>The platform: every school, the plans, the gateways and the revenue. Above every rung, in every school.</td></tr>
                                    <tr><td class="step-name">10</td><td>School Owner</td><td>One school's subscription &mdash; the plan, the invoices, the renewal. One per school.</td></tr>
                                    <tr><td class="step-name">20</td><td>Admin</td><td>That school's day-to-day running, everything except the money.</td></tr>
                                    <tr><td class="step-name">30</td><td>Principal</td><td>The academic side of the whole school. No system administration.</td></tr>
                                    <tr><td class="step-name">40</td><td>Branch Admin</td><td>The academic side of exactly one campus.</td></tr>
                                    <tr><td class="step-name">50</td><td>Teacher</td><td>Their own assigned sections and subjects.</td></tr>
                                    <tr><td class="step-name">60</td><td>Student / Parent</td><td>Their own published results.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mb-24">Three rules follow from it, and all three are enforced on the server, not in the buttons. <strong>Nobody hands out a role above their own</strong> &mdash; unless nobody in the school outranks them, which is the hand-over case that keeps an ownerless install recoverable. <strong>Nobody edits, deletes or &ldquo;Logs in as&rdquo; an account above their own</strong> &mdash; the three are the same takeover by different routes, so they share one gate. <strong>Your own row is always yours</strong>, which is what lets an owner hand the school over themselves. Every login's place in the chain is visible in the <strong>360&deg; view</strong> on the Users page.</p>
                    </div>

                    <div class="about-card">
                        <h2><i class="fas fa-filter"></i> Row-Level Scoping</h2>
                        <p class="mb-24">The matrix decides which pages open. Scoping decides which <em>rows</em> those pages return, and it is always applied in the SQL, never in the browser.</p>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Role</th><th>Sees</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td class="step-name">Super Admin</td><td>The platform operator, not a member of any school (<strong>school_id 0</strong>). Manages the schools themselves, the subscription plans and the billing &mdash; and nothing else. It holds <em>no</em> academic pages of its own on purpose: to look at a school's data it must enter that school by impersonation, which is written to the activity log. It is also the only role that may take a whole-database backup, because in a shared database one dump is every school's data.</td></tr>
                                    <tr><td class="step-name">School Owner</td><td>The tenant's own top role &mdash; everything an Admin does <em>plus the money</em>: the plan, the invoices and the renewal. Exactly one per school, and granting the role to somebody else is a <strong>transfer</strong>: the outgoing owner steps down to Admin in the same save. The account cannot be deleted, demoted or deactivated by anyone below it, and no Admin can reset its password or &ldquo;Login as&rdquo; it &mdash; only the owner themselves or the App Owner can move that role.</td></tr>
                                    <tr><td class="step-name">Admin</td><td>Everything inside <em>their own school</em>, across all years, branches, classes and sections. May approve, publish, unpublish and impersonate &mdash; each action logged. Another school's rows are invisible to them, whatever the matrix grants. The money stays with the owner: Admin holds no Billing page.</td></tr>
                                    <tr><td class="step-name">Principal</td><td>The whole school on the academic side &mdash; every class, section, student, teacher, result and the activity log &mdash; and may approve, publish and unpublish a finished section. The reach comes from the identity gate <strong>ormsSchoolWide()</strong>, not from a permission tick, which is why a Principal deliberately holds no <strong>teachers</strong> row and never appears on the Teachers page, yet still sees every section. No system administration at all: users, roles, sessions, site settings, backup and mail configuration stay closed, and marks entry is watch-only. On the money side a head teacher may place or lift a fee hold, but never delete a ledger row.</td></tr>
                                    <tr><td class="step-name">Branch Admin</td><td>The school admin's academic matrix, hard-pinned to <em>one branch</em>. The pin comes from the identity gate <strong>ormsBranchLock()</strong> and is part of the SQL WHERE clause, not a screen filter, so their query cannot return a row from another branch even with a tampered URL. No system pages and no Delete anywhere: a branch runs its academics, the school owns its configuration.</td></tr>
                                    <tr><td class="step-name">Teacher</td><td>Only what their teacher_subjects assignments reach for the current year. Marks writes re-verify the assignment, the Open term and the publish state on every request. Attendance is typed for those same sections and the broadsheet shows only those sections; fees are closed to them entirely.</td></tr>
                                    <tr><td class="step-name">Student</td><td>Only their own student row, and only results that have been published. A tampered URL is refused server-side.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================ Formulas ============================ -->
                <div class="tab-pane" id="tabFormulas">
                    <div class="about-card">
                        <h2><i class="fas fa-calculator"></i> Formulas &amp; Business Logic</h2>

                        <div class="info-banner info-banner-top mb-24">
                            <i class="fas fa-circle-info"></i>
                            <span>Every calculation the system performs is listed here with the exact rule it uses and the place it runs. The result maths lives in one file, <strong>result_engine.php</strong>, so no page invents its own percentage.</span>
                        </div>

                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>What</th><th>Formula / Logic</th><th>Where Used</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($FORMULAS as $grp => $rows): ?>
                                    <tr class="step-section-header"><td colspan="3"><?php echo htmlspecialchars($grp); ?></td></tr>
                                    <?php foreach ($rows as $f): ?>
                                    <tr>
                                        <td class="step-name"><?php echo htmlspecialchars($f[0]); ?></td>
                                        <td><?php echo htmlspecialchars($f[1]); ?></td>
                                        <td><?php echo htmlspecialchars($f[2]); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================ Grading Scheme ============================ -->
                <div class="tab-pane" id="tabGrading">
                    <div class="about-card">
                        <h2><i class="fas fa-ranking-star"></i> Grading Scheme</h2>

                        <div class="info-banner info-banner-top mb-24">
                            <i class="fas fa-circle-info"></i>
                            <span>Read live from the <strong>grading_scheme</strong> table, so it always matches what is set in Result Settings. A school can run more than one scheme &mdash; each <strong>grading set</strong> below is a complete scheme, and a class is graded against the set chosen on it, falling back to the default set. A grade is picked as the highest band <em>of that set</em> whose minimum percentage is at or below the score &mdash; the maximum column is shown for reference and is not used in the lookup, which is why bands with gaps still grade correctly. The interpretation is what a parent reads in the Key to Grades block on the card.</span>
                        </div>

                        <?php
                        // 80.00 -> 80 for the band columns
                        $bandNum = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
                        // one block per set; nothing migrated yet -> the single scheme, exactly as before
                        $bandTables = [];
                        foreach ($gradeSets as $gsRow) $bandTables[] = ['set' => $gsRow, 'rows' => $bandsBySet[(int)$gsRow['id']] ?? []];
                        if (!$bandTables) $bandTables[] = ['set' => null, 'rows' => $bands];
                        $bandCount = array_sum(array_map(fn($t) => count($t['rows']), $bandTables));
                        ?>
                        <?php if (!$bandCount): ?>
                        <div class="orms-empty">
                            <i class="fas fa-ranking-star"></i>
                            <h4>No grade bands yet</h4>
                            <p>Add them in <strong>Result Settings &rsaquo; Grading Scheme</strong>, or run setup to seed the defaults.</p>
                        </div>
                        <?php else: foreach ($bandTables as $bt): $bset = $bt['set']; ?>
                        <?php if ($bset): ?>
                        <h3><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($bset['name']); ?>
                            <?php if (!empty($bset['is_default'])): ?><span class="perm-on"><i class="fas fa-check"></i> default set</span><?php endif; ?>
                            <?php if (isset($bset['is_active']) && !$bset['is_active']): ?><span class="perm-off"><i class="fas fa-minus"></i> inactive</span><?php endif; ?>
                        </h3>
                        <?php if (trim((string)($bset['description'] ?? '')) !== ''): ?><p class="mb-24"><?php echo htmlspecialchars((string)$bset['description']); ?></p><?php endif; ?>
                        <?php endif; ?>
                        <?php if (!$bt['rows']): ?>
                        <p class="mb-24"><em>No bands in this set yet.</em></p>
                        <?php else: ?>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper mb-24">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Grade</th><th>Min %</th><th>Max %</th><th>Grade Point</th><th>Interpretation</th><th>Remarks</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bt['rows'] as $b): ?>
                                    <tr>
                                        <td class="step-name"><?php echo htmlspecialchars($b['grade']); ?><?php echo (int)($b['is_fail'] ?? 0) === 1 ? ' <small>(fail band)</small>' : ''; ?></td>
                                        <td><?php echo htmlspecialchars($bandNum($b['min_percent'])); ?></td>
                                        <td><?php echo htmlspecialchars($bandNum($b['max_percent'])); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float)$b['grade_point'], 1)); ?></td>
                                        <td><?php echo htmlspecialchars(trim((string)($b['interpretation'] ?? '')) !== '' ? (string)$b['interpretation'] : '—'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($b['remarks'] ?? '')); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- ============================ Modules ============================ -->
                <div class="tab-pane" id="tabModules">
                    <div class="about-card">
                        <h2><i class="fas fa-diagram-project"></i> Modules &amp; Pages</h2>
                        <p class="mb-24">Page names and icons come from the same registry the sidebar uses, and the &ldquo;who can open it&rdquo; column is read from the live permission matrix &mdash; a role appears once it holds View on that page.</p>

                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Page</th><th>What it does</th><th>Who can open it</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($RBAC_GROUPS as $grp):
                                        $pagesInGroup = array_filter($RBAC_PAGES, fn($p) => $p['group'] === $grp);
                                        if (!$pagesInGroup) continue;
                                    ?>
                                    <tr class="step-section-header"><td colspan="3"><?php echo htmlspecialchars($grp); ?></td></tr>
                                    <?php foreach ($pagesInGroup as $pg):
                                        // roles holding view on this page — straight from the matrix
                                        $viewers = array_filter($aboutRoles, fn($r) => !empty($r['perms'][$pg['key']]['v']));
                                    ?>
                                    <tr>
                                        <td class="step-name"><i class="fas <?php echo htmlspecialchars($pg['icon']); ?>"></i> <?php echo htmlspecialchars($pg['label']); ?><br><small><?php echo htmlspecialchars($pg['file']); ?></small></td>
                                        <td><?php echo htmlspecialchars($PAGE_NOTES[$pg['key']] ?? ''); ?></td>
                                        <td>
                                            <?php if (!$viewers): ?>
                                            <span class="perm-off"><i class="fas fa-minus"></i> nobody</span>
                                            <?php else: foreach ($viewers as $r): ?>
                                            <span class="role-badge rbac-rolehead" data-role-color="<?php echo htmlspecialchars(aboutRoleColor($r['color'])); ?>"><?php echo htmlspecialchars($r['label']); ?></span>
                                            <?php endforeach; endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <tr class="step-section-header"><td colspan="3">Support pages (no permission key &mdash; open to any signed-in user)</td></tr>
                                    <tr><td class="step-name"><i class="fas fa-id-card"></i> My Account<br><small>account.php</small></td><td>Own profile, photo, password and theme.</td><td><span class="perm-on"><i class="fas fa-check"></i> everyone</span></td></tr>
                                    <tr><td class="step-name"><i class="fas fa-file-lines"></i> Result Card<br><small>result_card.php</small></td><td>Renders and prints a single result card. Ownership and publish state are re-checked before anything is shown &mdash; the student the card belongs to, plus Admin, Principal and the teachers holding that section.</td><td><span class="perm-on"><i class="fas fa-check"></i> owner + staff</span></td></tr>
                                    <tr><td class="step-name"><i class="fas fa-info-circle"></i> About App<br><small>about.php</small></td><td>This page &mdash; the living documentation of roles, formulas, grading and data model.</td><td><span class="perm-on"><i class="fas fa-check"></i> everyone</span></td></tr>
                                    <tr><td class="step-name"><i class="fas fa-screwdriver-wrench"></i> Setup<br><small>setup.php · update_setup.php</small></td><td>First-time install and reset, plus the additive schema updater that adds anything missing without touching existing data.</td><td><span class="perm-off"><i class="fas fa-minus"></i> maintenance only</span></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================ Data Model ============================ -->
                <div class="tab-pane" id="tabData">
                    <div class="about-card">
                        <h2><i class="fas fa-database"></i> Academic Tables</h2>
                        <p class="mb-24">The <?php echo count($DATA_MODEL); ?> tables below carry the academic layer. All are InnoDB with utf8mb4 and real foreign keys.</p>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Table</th><th>Purpose</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($DATA_MODEL as $t): ?>
                                    <tr>
                                        <td class="step-name"><?php echo htmlspecialchars($t[0]); ?></td>
                                        <td><?php echo htmlspecialchars($t[1]); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="about-card">
                        <h2><i class="fas fa-recycle"></i> Reused Template Tables</h2>
                        <p class="mb-24">The <?php echo count($TEMPLATE_TABLES); ?> tables below already existed and are reused as they are &mdash; nothing about authentication, settings, logging or notifications was rebuilt for this project.</p>
                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Table</th><th>Used for</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($TEMPLATE_TABLES as $t): ?>
                                    <tr>
                                        <td class="step-name"><?php echo htmlspecialchars($t[0]); ?></td>
                                        <td><?php echo htmlspecialchars($t[1]); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ============================ Demo Logins ============================ -->
                <div class="tab-pane" id="tabDemo">
                    <div class="about-card">
                        <h2><i class="fas fa-key"></i> Demo Accounts</h2>

                        <div class="info-banner info-banner-warning mb-24">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><strong>Demo accounts only.</strong> These are seeded by the installer so the system can be tried immediately. Change every password and remove the sample students before using this on real school data.</span>
                        </div>

                        <div class="table-scroll-hint no-print"><i class="fas fa-arrows-alt-h"></i> Swipe left/right to see all columns</div>
                        <div class="about-table-wrapper">
                            <table class="setup-guide-table">
                                <thead>
                                    <tr><th>Role</th><th>Username</th><th>Password</th><th>What it demonstrates</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="step-name"><?php echo aboutRoleChip('Admin'); ?></td>
                                        <td>admin</td><td>admin123</td>
                                        <td>Full access &mdash; settings, registration, approving, publishing, tabulation and the role matrix.</td>
                                    </tr>
                                    <tr>
                                        <td class="step-name"><?php echo aboutRoleChip('Principal'); ?></td>
                                        <td>principal</td><td>principal123</td>
                                        <td>Head Teacher &mdash; the whole school's students, results and logs, approves a finished section before it is published, and watches marks entry without being able to type in it. No users, roles or system settings.</td>
                                    </tr>
                                    <tr>
                                        <td class="step-name"><?php echo aboutRoleChip('Teacher'); ?></td>
                                        <td>teacher1</td><td>teacher123</td>
                                        <td>Teaches Mathematics and Science in Class 5-A &mdash; the grid shows those two subjects only.</td>
                                    </tr>
                                    <tr>
                                        <td class="step-name"><?php echo aboutRoleChip('Teacher'); ?></td>
                                        <td>teacher2</td><td>teacher123</td>
                                        <td>Teaches English across 5-A and 5-B &mdash; one column, two sections.</td>
                                    </tr>
                                    <tr class="step-final">
                                        <td class="step-name"><?php echo aboutRoleChip('Student'); ?></td>
                                        <td>STU-2026-0001 &hellip; STU-2026-0025</td><td>student123</td>
                                        <td>The admission number is the username. Class 5-A students already have a published First Term result to open and print.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="about-card about-developer">
                    <h2><i class="fas fa-code"></i> About the Developer</h2>
                    <div class="developer-info">
                        <img src="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg" alt="Mohammad Rameez Imdad" class="developer-avatar">
                        <div class="developer-details">
                            <h3>Mohammad Rameez Imdad</h3>
                            <p class="developer-brand">Rameez Scripts</p>
                            <p>I build custom web apps, dashboards, PHP/MySQL solutions and automation tools. If you need something built for your school, business or project, feel free to reach out.</p>
                            <h4 class="dev-cta"><i class="fas fa-handshake"></i> Let's Work Together</h4>
                            <div class="developer-links">
                                <a href="https://whatsapp.rameezscripts.com" target="_blank" rel="noopener" class="dev-link whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                                <a href="https://t.me/rameezscripts" target="_blank" rel="noopener" class="dev-link telegram"><i class="fab fa-telegram"></i> Telegram</a>
                                <a href="mailto:Contact@rameezscripts.com" class="dev-link email"><i class="fas fa-envelope"></i> Email</a>
                                <a href="https://www.youtube.com/@rameezimdad" target="_blank" rel="noopener" class="dev-link youtube"><i class="fab fa-youtube"></i> Subscribe on YouTube</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="about-footer">
                    <p>Developed by <strong>Mohammad Rameez Imdad</strong> (Rameez Scripts) &mdash; <i class="fab fa-whatsapp"></i> <a href="https://whatsapp.rameezscripts.com" target="_blank" rel="noopener">whatsapp.rameezscripts.com</a> &middot; <i class="fab fa-youtube"></i> <a href="https://www.youtube.com/@rameezimdad" target="_blank">@rameezimdad</a></p>
                    <p class="about-version">Online Result Management System &middot; Version 1.0.0</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>
    $(document).ready(function() {
        document.body.classList.remove('initially-hidden');

        // role chips wear the colour stored on the role row — same runtime paint roles.php uses
        $('[data-role-color]').each(function() {
            this.style.backgroundColor = this.getAttribute('data-role-color');
        });

        $('.tab-btn').on('click', function() {
            var id = $(this).data('tab'), $btn = $(this);
            ORMS.swap(function () {                       // crossfade, not a snap
                $('.tab-btn').removeClass('active');
                $btn.addClass('active');
                $('.tab-pane').removeClass('active');
                $('#' + id).addClass('active');
            });
        });
    });
    </script>
</body>
</html>
