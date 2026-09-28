# REQUIREMENTS — Online Result Management System (ORMS)

> **Read this first:** This is the build contract for an enterprise-level Student Result Management System on the Rameez Scripts PHP/MySQL template. The template's auth/RBAC/settings/logging/notification infrastructure is **REUSED, never duplicated** — we only add the academic layer on top. Build phase-by-phase in order; each phase has acceptance criteria.

---

## 1. Overview

| Item | Value |
|------|-------|
| Project | Online Result Management System |
| Path | `C:\xampp\htdocs\ONLINE RESULT MANAGEMENT` |
| Stack | PHP 8+ / MySQL (XAMPP), template UI (styles.css, sidebar.php, Navy theme, dark mode) |
| DB name | `result` (set in `config.php`) |
| Roles | **Admin · Principal · Teacher · Student** (4, driven by existing `roles` RBAC matrix) |
| Core promise | Register students → manage classes/subjects/teachers → teachers bulk-enter marks in a large popup (only THEIR subjects) → head teacher approves → results published → students view/print branded result cards. Result branding (logo, school name), the head teacher's name/signature and exam terms fully editable from Result Settings. |

**Goals:** zero duplicated infrastructure · marks integrity (snapshot totals, locked after publish, full audit) · fast bulk entry (one popup, one transaction) · configurable result card · role-scoped data at SERVER level.

---

## 2. Roles & Access

### 2.1 RBAC page matrix (seeded into existing `roles.permissions` JSON)

Page keys: existing (`dashboard, users, logs, sessions, settings, backup, smtp_setup, oauth_setup, roles`) **+ new** (`students, teachers, classes, subjects, marks_entry, results, my_results, result_settings`).

| Page | Admin | Principal | Teacher | Student |
|------|-------|-----------|---------|---------|
| dashboard | VAED | V | V | V |
| students.php | VAED | V+A+E | V (own sections) | — |
| teachers.php | VAED | V+A+E | — | — |
| classes.php / subjects.php | VAED | V+A+E | V | — |
| marks_entry.php | VAED | V (watches progress, never types marks) | V+A+E (own assignments) | — |
| results.php (overview/approve/publish/tabulation) | VAED | V+A+E | V (own sections) | — |
| my_results.php | V (any student) | V (any student) | — | V (self only) |
| result_settings.php | VAED | V+E | — | — |
| logs | VAED | V | — | — |
| users, roles, sessions, settings, backup, smtp, oauth | VAED | — | — | — |

> **Principal = academic authority, zero system administration.** The head teacher runs the school's academics but never touches user accounts, the permission matrix, SMTP/OAuth, backups or site settings — that stays the Admin's (IT) job. No Delete bit anywhere: heads correct records, they don't erase them.

> **Note:** the seed grants Admin full VAED on all 17 keys rather than the `V`-only shown above for `my_results`. Functionally identical — `my_results` only ever checks `v` — and it keeps a super-admin from being locked out of a page as the app grows.

### 2.2 Row-level scoping (server-side, ALWAYS in WHERE clause — never client-side hiding)

- **Teacher** → only rows reachable through `teacher_subjects` (their class/section/subject for the current academic year). Marks writes re-verify ownership + term open + not published on EVERY request.
- **Student** → only `students.user_id = $_SESSION['user_id']` AND only **published** results.
- **Admin** → everything; may impersonate (existing `impersonate.php`, logged).
- **Principal** → the whole school, unscoped, exactly like Admin. Gated by `ormsSchoolWide()` in `config.php` (`['Admin','Principal']`) — **identity decides reach, never permission bits.** A Principal deliberately has **no `teachers` row** (the Teachers page lists anyone holding one, and `teacher_subjects.teacher_id` FKs to `teachers.id`), so without this identity gate every scoped query returns zero rows. May NOT impersonate.

### 2.3 Approval gate (head teacher sign-off before publish)

State machine on `result_publications` (one row per term+section), column `approval_status`:

`Draft` → **Submit for Approval** → `Pending` → **Approve** → `Approved` → **Publish** → live
`Pending` → **Reject** (mandatory note) → `Rejected` → resubmit → `Pending`

- Controlled by setting `require_principal_approval` (default `1`). When on, publishing a section whose status is not `Approved` is refused server-side, in `results.php` **and** in `ormsPublishSection()`.
- **Admin may approve as well as the Principal**, so a school with no Principal account can never lock itself out of publishing.
- Unpublishing resets the section to `Draft` — a pulled result must be re-approved before it can go live again.
- Audit columns: `submitted_by/submitted_at`, `approved_by/approved_at`, `review_note`. Sections already published before this feature were back-filled to `Approved` by the migration.

### 2.4 Account rules

- Public signup **disabled** (`allow_public_signup=0`; all 4 roles `hidden_signup=1`). Admin creates all accounts.
- Student login = `admission_no` as username (e.g. `STU-2026-0001`), default pwd from setting `student_default_password`. Teacher login = chosen username or `employee_no`, default from `teacher_default_password`. Printable **credential slip** after registration.
- Template's seeded `User` role: migrate any users → `Student`, delete `User` row.

---

## 3. Reused Template Infrastructure (DO NOT rebuild)

| Template asset | Reused for |
|----------------|-----------|
| `users` table + login/logout/forgot/reset/OTP/OAuth | All 4 roles' accounts, profile photos, theme prefs |
| `roles` table + roles.php matrix editor | The 4-role permission matrix above |
| `system_settings` + settings.php | All `result_*` settings keys (§6) |
| `activity_logs` + logs.php | Audit of every CRUD incl. bulk marks + publish/unpublish |
| `notifications` + bell + `push_subscriptions` + `webpush_helper.php` | "Result published" alerts to students (in-app + web push) |
| SMTP setup + mailer | Optional result-published email |
| `user_sessions`, `login_attempts`, `remember_tokens`, `password_resets`, `email_verifications` | Auth security as-is |
| `backup.php` | Covers new tables automatically |
| `sidebar.php`, `styles.css`, dark mode, mobile menu, PWA | App shell — extend, never fork |

---

## 4. Database Schema

### 4.1 🚨 Phase-0 blocker fix — `users` table

| Change | Why |
|--------|-----|
| `MODIFY role VARCHAR(50) NOT NULL DEFAULT 'Student'` | Currently `ENUM('Admin','User')` — Teacher/Student **cannot be assigned** (MySQL coerces to `''`). Must be first migration. |
| `ADD full_name VARCHAR(100)`, `ADD phone VARCHAR(20)`, `ADD is_active TINYINT(1) DEFAULT 1` | Names/contact/suspension for all people; students/teachers reference `users` for name+photo (DRY). |

### 4.2 New tables (13) — all InnoDB utf8mb4, real FKs (template tables stay untouched)

| Table | Columns (key ones) | Keys/Rules |
|-------|--------------------|-----------|
| `academic_years` | id, name('2025-2026'), start_date, end_date, is_current | one is_current enforced in code |
| `exam_terms` | id, academic_year_id FK, name, sort_order, status ENUM('Upcoming','Open','Closed') | UNIQUE(year,name); entry only when **Open**; managed in Result Settings |
| `classes` | id, name, sort_order, is_active | UNIQUE(name) |
| `sections` | id, class_id FK, name('A'), capacity NULL, is_active | UNIQUE(class_id,name) |
| `subjects` | id, name, code, is_active | UNIQUE(code) |
| `class_subjects` | id, class_id FK, subject_id FK, total_marks DEC(6,2)=100, passing_marks DEC(6,2)=33, sort_order | UNIQUE(class_id,subject_id) — per-class marks config |
| `teachers` | id, user_id FK UNIQUE, employee_no UNIQUE, qualification, joining_date, status | name/phone/photo live on `users` |
| `students` | id, user_id FK UNIQUE, admission_no UNIQUE, roll_no, class_id FK, section_id FK, academic_year_id FK, father_name, dob, gender, guardian_phone, address, admission_date, status ENUM('Active','Inactive','Passed Out','Transferred') | INDEX(section_id,status); roll dup checked per section+year in code |
| `teacher_subjects` | id, teacher_id FK, class_id, section_id FK, subject_id FK, academic_year_id FK | UNIQUE(year,section,subject) → one teacher per subject per section; INDEX(teacher_id) |
| `marks` | id, student_id FK, class_id, section_id, subject_id FK, term_id FK, academic_year_id, marks_obtained DEC(6,2) NULL, **total_marks DEC(6,2) snapshot**, is_absent TINYINT, grade VARCHAR(5), entered_by, updated_by, timestamps | **UNIQUE(student_id,subject_id,term_id)** → bulk save = upsert; INDEX(section_id,term_id,subject_id) |
| `grading_scheme` | id, grade, min_percent, max_percent, grade_point DEC(3,1), remarks, sort_order | no gaps/overlap validated in code; editable in Result Settings |
| `result_publications` | id, term_id FK, class_id, section_id FK, academic_year_id, is_published, published_by, published_at | UNIQUE(term_id,section_id) — publish per section |
| `result_summaries` | id, student_id FK, term_id FK, section_id, academic_year_id, total_obtained, total_max, percentage DEC(5,2), grade, gpa DEC(3,2), position INT, result_status ENUM('PASS','FAIL'), failed_subjects, generated_at | UNIQUE(student_id,term_id); **regenerated at publish** (snapshot = positions never drift) |

**Integrity rules:** `marks.total_marks` snapshotted from `class_subjects` at save (later config edits never corrupt history) · marks carry class/section/year denormalized (history survives promotion) · student delete = soft (status); hard delete RESTRICTed by marks FK · publish locks marks (unpublish = admin-only, logged).

### 4.2b Production columns (added after the first schema audit — `update_setup.php` §5c)
All nullable or defaulted, so existing rows survive. Applied by `addColumnIfMissing()`, idempotent.

| Table | Added |
|-------|-------|
| `users` | `updated_at` · `must_change_password` · `last_login_at` · `last_login_ip` · `password_changed_at` · `created_by` |
| `activity_logs` | `entity_type` · `entity_id` · `user_agent` · `role` (+ `idx_log_entity`) |
| `system_settings` | `updated_by` · `setting_group` |
| `email_verifications`, `password_resets` | `attempts` · `used_at` · `ip_address` — OTP lockout at 5 tries |
| `remember_tokens` | `ip_address` · `user_agent` · `last_used_at` |
| `login_attempts` | `success` · `user_agent` (rate-limit reads now filter `success = 0`) |
| `user_sessions` | `logged_out_at` |
| `notifications` | `read_at` · `created_by` |
| `push_subscriptions` | `is_active` · `failed_count` · `last_used_at` — 404/410 retires an endpoint |
| `roles` | `description` · `created_at` · `updated_at` · `updated_by` |
| `academic_years` | **`is_locked`** · `updated_at` |
| `classes` | `numeric_level` · `next_class_id` (self-FK) · `updated_at` |
| `subjects` | `short_name` · `subject_type` · **`include_in_total`** · `updated_at` |
| `grading_scheme` | **`is_fail`** · `color` |
| `exam_terms` | `start_date` · `end_date` · `result_date` · `weightage` · `updated_at` |
| `sections` | **`class_teacher_id`** (FK → teachers) · `room_no` · `updated_at` |
| `class_subjects` | `theory_marks` · `practical_marks` · `include_in_total` · `is_optional` · `updated_at` |
| `teachers` | `designation` · `national_id` · `leaving_date` · `emergency_contact` · `remarks` · `created_by` · `updated_by` |
| `students` | `mother_name` · `guardian_name` · `guardian_email` · `national_id` · `blood_group` · `previous_school` · `date_of_leaving` · `leaving_reason` · `remarks` · `created_by` · `updated_by` |
| `teacher_subjects` | `assigned_by` · `is_active` |
| `marks` | `remarks` · `theory_obtained` · `practical_obtained` |
| `result_publications` | `unpublished_by` · `unpublished_at` · `unpublish_reason` (publish audit is never overwritten) |
| `result_summaries` | `class_id` · `section_total` · `subjects_count` · `teacher_remarks` · `promoted_status` · `generated_by` |

> `exam_terms.weightage` is stored and editable, but **no annual aggregation is built on it** — that is a feature, not a column, and is out of scope (§12).

### 4.3 Two-file schema (CLAUDE.md rule 21)

- **`update_setup.php`** (NEW) = single schema source: `applyUpdates()` with `CREATE TABLE IF NOT EXISTS` ×13 + `addColumnIfMissing()` + the role ENUM→VARCHAR migration (checks current type first) + `INSERT IGNORE` seeds (3 roles, grading scheme, settings, year+terms). Idempotent, additive, run-if-main guard.
- **`setup.php`** (REFACTOR) = thin installer: `?action=install` (default) requires `update_setup.php` → `applyUpdates()` → demo seed; `?action=reset&confirm=yes` (JS confirm) drops all reverse-FK → rebuild → reseed. Keep existing VAPID never-regenerate + uploads/.htaccess blocks. Kill the ~200 lines of copy-paste via `createTable()/addColumnIfMissing()/seedSettings()` helpers.

---

## 5. Pages

**NEW:** `update_setup.php`, `result_settings.php`, `classes.php` (classes+sections tabs), `subjects.php` (subjects + per-class assignment/marks config), `teachers.php` (profiles + Assign Subjects modal), `students.php` (register/list/edit/import/promote/credential slip), `marks_entry.php` (bulk popup), `results.php` (completion overview + publish + tabulation + bulk print), `result_card.php` (single card, print), `my_results.php` (student portal).

**MODIFIED:** `setup.php` (§4.3), `sidebar.php` (role-gated menu: Academics ▸ Classes/Subjects/Teachers/Students · Results ▸ Marks Entry/Results/Result Settings · student sees Dashboard/My Results only), `dashboard.php` (4 role dashboards), `about.php` (living doc §9), `roles.php` (new page keys), `users.php` (role filter chips), `signup.php` (public signup gated off per §2.4), `index.php` (**public result portal §7.8**, replaces the bare redirect), `styles.css` (append page-scoped styles — **no inline CSS anywhere**, rule 20).

Every page: `<?php` at byte 0 → `require config.php` → RBAC gate → self-handled AJAX (`action` + CSRF + bind_param + `exit()` after JSON) — matching template convention.

---

## 6. Result Settings (`result_settings.php`) — the configurability hub

Tabbed page, Admin + Principal (`result_settings` = VAED / V+E). **Everything printed on the result card is configurable here — nothing hardcoded.**

| Tab | Contents | Storage |
|-----|----------|---------|
| **Branding** | School name, address, phone, **result logo upload** (DaisyUI file-input → `uploads/branding/`, fallback = site logo), footer note, left/right signature labels ("Class Teacher"/"Principal"), **head teacher name + designation + signature image upload** (prints above the right-hand label, which used to be a bare ruled line) | `system_settings`: `result_school_name`, `result_school_address`, `result_school_phone`, `result_logo`, `result_footer_note`, `result_signature_left`, `result_signature_right`, `result_principal_name`, `result_principal_designation`, `result_principal_signature`, `result_show_principal_sign`, `result_show_principal_remark` |
| **Exam Terms** | CRUD terms per academic year (name, order, status Upcoming/Open/Closed) — **the changeable "terms"** | `exam_terms` |
| **Academic Years** | CRUD years, set current | `academic_years` |
| **Grading Scheme** | Editable grade bands table (grade, min%, max%, point, remarks) + live preview | `grading_scheme` |
| **Options** | show position / show GPA / show photo toggles (template toggle switches), **require Principal approval before publish**, pass rule (per-subject), default passwords, admission no prefix | `system_settings`: `result_show_position`, `result_show_gpa`, `result_show_photo`, `require_principal_approval`, `student_default_password`, `teacher_default_password`, `admission_no_prefix` |

Default grading seed: A+ 90–100 (4.0/Outstanding) · A 80–89 (3.7/Excellent) · B 70–79 (3.0/Very Good) · C 60–69 (2.3/Good) · D 50–59 (1.7/Fair) · E 40–49 (1.0/Pass) · F 0–39 (0.0/Fail).

---

## 7. Feature Specs

### 7.1 Student Registration (`students.php`)
- Form: photo (DaisyUI file-input), full name, father name, DOB (date picker), gender, guardian phone, address, class → section (dependent SearchableDropdowns), roll no, admission date. Auto `admission_no` = `{prefix}-{year}-{seq}` (e.g. STU-2026-0001).
- Creates `users` (username=admission_no, default pwd hashed, role=Student) **+ `students` row in ONE transaction**; success popup offers printable credential slip.
- List: DataTable 10/page, CSV/PDF/Print, filters (class, section, year, status — SearchableDropdowns), photo+name+admission/roll/class chips, actions: view/edit/reset-pwd/status/credential slip.
- **CSV Import**: template download → validate headers → dedup by admission_no (sheet AND batch) → skip-and-collect errors → one transaction → `N imported, M skipped` popup → ONE log entry.
- **Promotion tool**: pick source class+section+year → target class+section+next year → preview list → bulk update (marks history untouched — it carries its own year/class).

### 7.2 Class Management (`classes.php`, `subjects.php`, `teachers.php`)
- Classes+sections CRUD; delete RESTRICTed when students/marks exist (deactivate instead).
- Subjects CRUD + **Assign to Class** with per-class `total_marks`/`passing_marks`.
- Teachers: profile (links `users` row) + **Assign Subjects** modal: year → class → section → subjects; enforces one-teacher-per-subject-per-section; shows each teacher's load as chips.

### 7.3 ⭐ Bulk Marks Entry (`marks_entry.php`) — flagship
- Teacher lands on a **DataTable of their own assignments only** (from `teacher_subjects`) — columns: Class · Section · Subject · Students · Term · Progress (`28 / 32` + completion bar, sorts by %) · Status · Actions (Enter Marks / View). 10 rows/page, CSV/PDF/Print, responsive, filters above it. Admin sees all sections + free subject choice.
- Pick term (Open terms only) → **[Enter Marks] opens a large popup — modal at ~80% viewport width (max 1100px), max-height 85vh, scrollable body, sticky header + sticky student column**.
- Grid: rows = ALL Active students of the section (roll, photo, name); **columns = ONLY the logged-in teacher's assigned subject(s) for that section — the 1 or 2 subjects they teach** — one number input per cell (`/100` suffix from class_subjects) + per-cell **AB** (absent) toggle.
- Prefilled with existing marks (edit = same popup). Live per-cell grade preview via grading scheme. Validation: 0 ≤ marks ≤ total, client + server. **Keyboard-first:** Enter/↓ jump to next student — full class enterable without touching the mouse. Footer: entered-count summary + unsaved-changes guard on close.
- **Save All** = ONE AJAX POST (JSON array) → server: RBAC gate + ownership + term Open + section not published → validate every row → **single transaction, prepared upsert** (`INSERT … ON DUPLICATE KEY UPDATE` on the unique key), grade computed+stored per row → ONE activity_log ("Bulk marks: Mathematics, Class 5-A, First Term — 32 saved") → `{saved, skipped, errors[]}`.
- Feedback per rule 31: top loading bar + button `fa-spinner fa-spin` "Saving…" + disabled + `.finally()` re-enable. Published/Closed → grid renders read-only with a lock banner.

### 7.3b 📴 Offline Score Sheet (download → fill offline → import)

**Why:** teachers frequently do not have the mobile data to stay online while typing a whole class. They must be able to work offline and sync once.

- **Download Score Sheet** → CSV for the chosen section + term. Roster rows (Admission No · Roll No · Student Name) × one column per subject **the current user may enter** (same `meSubjectCols()` source as the grid, so a teacher gets only their own subjects, Admin gets all). Column header carries the scale — `Mathematics (100)` — so the max mark is known offline. **Pre-filled with marks already entered** (number, `AB` for absent, blank for none) so a half-finished sheet round-trips. Optional-subject cells for non-enrolled students are marked `-`. UTF-8 BOM so Excel opens names correctly.
- **Import Score Sheet** → `importMarksPreview` (validates, writes nothing, ends in `preview` so the READ_RE routes it to the thin top bar) → summary of adds / updates / clears / skips + the problem list → SweetAlert2 confirm → `importMarks` (commits).
- **Validation is server-side and re-run on commit** — the preview result from the client is never trusted. Per-subject authority via `ormsCanEnterMarks()`; term Open, section not published, elective enrolment via `ormsElectiveSql()`, 0 ≤ marks ≤ that subject's `total_marks`.
- **Skip-and-collect, never fail-fast:** unknown/other-section/inactive admission no, un-owned or untaught subject column, non-enrolled elective, out-of-range or unparseable value, duplicate admission no (first wins) — each reported with its row number while the good rows still import.
- Parser tolerates what spreadsheets do: `#` comment lines and blanks skipped, case-insensitive header match, ` (100)` suffix stripped, `AB`/`A`/`ABS`/`ABSENT` = absent, `-`/blank = no mark, trailing `.0` accepted.
- ONE transaction, ONE batched write, ONE `activity_log` entry for the whole import. **Shares the grid's per-cell persist helper — no second write path**, so a cleared cell still DELETEs its row and never writes `marks_obtained = NULL`, and `total_marks`/`passing_marks` snapshots behave identically to online entry.
- CSV only (no XLSX — this project runs Composer-free, and every spreadsheet app reads CSV offline).

### 7.4 Results Engine & Publishing (`results.php`)
- **Completion overview** (per term): chevron pipeline tabs `All / Not Started / In Progress / Complete / Published` filtering a section-rows DataTable (section, students, subjects, entered %, progress bar, status, actions).
- **Publish** (per section, Admin): gated on **100% completion** → transaction: compute all `result_summaries` (totals, %, grade, GPA, position, PASS/FAIL) + set `result_publications.is_published=1` → notify every student of the section (in-app + web push; email if SMTP on) → log. **Unpublish** = admin-only, confirm + reason, deletes summaries, reopens entry, logged.
- **Tabulation sheet / broadsheet**: students × subjects grid + total/%/grade/position per row, DataTable export CSV/PDF/Print — the class-wide master sheet.
- **Bulk print**: all result cards of a section sequentially, `page-break-after` each — one Ctrl+P for the whole class.

### 7.5 Result Card (`result_card.php`) — the printable DMC
- Header: **result_logo + result_school_name** + address/phone (all from Result Settings) + term + year.
- Student block: photo (toggle), name, father name, admission no, roll no, class–section.
- Marks table: subject | total | obtained (AB shown for absent) | grade | remarks; failed subjects tinted.
- Summary: total obtained/max, percentage, grade, GPA (toggle), **position (toggle, "3rd of 32")**, PASS/FAIL badge.
- Footer: date printed, left/right signature lines (configurable labels), footer note.
- A4 print CSS (`@media print` — sidebar/nav hidden, clean card only); Print button = `window.print()` (browser PDF).

### 7.6 Student Portal (`my_results.php` + dashboard)
- Lists **published** results across terms/years → View opens own result card (server re-checks ownership + published — URL tampering gets 403).
- Student dashboard: KPI small-boxes (Latest %, Grade, Position, Subjects), line chart "My % across terms", notifications bell shows publish alerts.

### 7.7 Dashboards (`dashboard.php`, AdminLTE per docs/dashboard.md)
- **Admin:** small-boxes (Students, Teachers, Classes, Published Sections this term) → info-boxes (Subjects, Sections, Entry completion %, Pass rate) → charts: pass % per class (bar), grade distribution (doughnut) → Top 5 students (lte-card table) → recent activity.
- **Teacher:** their assignments as completion cards (X/Y entered, progress bar) + [Enter Marks] shortcuts + published results of their sections.
- **Student:** §7.6. All wrapped in collapsible `lte-card`s; skeletons while loading.

### 7.8 🌐 Public Result Portal (`index.php`) — no login required
The site homepage doubles as a public result-checking counter, so a student (or parent) can get a result card without an account.

- **Branding header** — logo, school name, address, phone, all pulled live from Result Settings (§6). Renaming the school there changes this page with zero code edits.
- **Lookup form** — Academic Year → Exam Term (only terms with at least one published section) → Class → Section (dependent) → Roll No **or** Admission No → **Date of Birth (mandatory)** → Check Result.
- **Output** — the full result card rendered inline via `ormsRenderResultCard()`, with a Print button. Logged-in visitors are redirected to `dashboard.php` instead.

**Security rules (a public endpoint exposing marks — non-negotiable):**

| Rule | Why |
|---|---|
| DOB must match `students.dob`; class + roll alone NEVER returns a result | Otherwise anyone can enumerate every student's marks by walking roll numbers |
| Only `result_publications.is_published = 1` rows are visible | Unpublished marks must never leak |
| One generic failure message for every failure mode (wrong DOB, no such roll, unpublished, inactive) | Distinct errors confirm which roll numbers exist |
| Rate limit: max 8 attempts / 10 min per IP | Blocks brute-forcing DOB |
| CSRF on the lookup POST; all reads prepared; all output escaped | Standard, applies even though the page is public |
| `logActivity` records class/roll + outcome, **never the DOB** | Audit trail without storing the secret |
| `<meta name="robots" content="noindex, nofollow">` | Keeps student results out of search engines |

---

## 8. Formulas & Business Logic (must mirror into about.php)

| What | Formula / Logic | Where |
|------|-----------------|-------|
| Percentage (subject) | obtained / total_marks × 100, 2dp, divide-by-zero → 0 | card, tabulation |
| Grade | **highest band whose `min_percent` ≤ pct** (`max_percent` is display-only). Not `BETWEEN` — seeded bands gap at 89→90, and `BETWEEN` would return no grade for 89.5 | marks save, card |
| Subject pass | `obtained ≥ class_subjects.passing_marks` **AND** a marks row exists **AND** not absent — a blank cell counts as failed | card, summary |
| Absent | stored as `marks_obtained = NULL, is_absent = 1`; 0 is applied at compute time, the full total still counts toward the max, subject failed | marks, card |
| Blank / un-entered | treated exactly like absent for maths, but excluded from completion — publish is blocked until none remain | summary, results |
| Overall % | Σ obtained / Σ total_marks × 100 (all section subjects) | summary |
| Overall grade | band of the overall % | summary |
| GPA | average of the per-subject `grade_point` over **all** section subjects, 2dp — an absent/un-entered subject contributes its 0% band point (0.0) | summary |
| PASS/FAIL | PASS iff every subject passed; else FAIL + comma-separated `failed_subjects` (truncated on a comma boundary to fit VARCHAR(255)) | summary |
| Position | rank by `total_obtained` DESC within section+term; ties share a position and the next rank skips (1,1,3). Epsilon compare — DECIMAL returns as a string | publish compute |
| Completion % | marks joined to an **Active** student **and** a live `class_subjects` row / (active students × section subjects) × 100, clamped to 100 so stale rows can't exceed it | results.php, dashboards |
| Entry allowed | term=Open AND section not published AND (admin OR teacher owns the assignment), resolved from the session — `ormsCanEnterMarks()` | marks_entry server gate |
| Admission no | `{prefix}-{yearTag}-{0001}` from MAX(sequence) for that prefix+year, generated inside the txn, retries on duplicate-key | students.php |
| total_marks snapshot | each `marks` row stores the total it was entered with; later `class_subjects` edits never rewrite history | marks save |
| **Excluded subject** | counted only when `class_subjects.include_in_total = 1` **AND** `subjects.include_in_total = 1`. An excluded subject still prints on the card with its marks and grade, and still counts toward completion — but is out of `total_obtained`, `total_max`, percentage, **GPA (not in the mean, divisor drops)** and PASS/FAIL, so it can never fail a student. `subjects_count` stores how many counted. Nothing counted ⇒ never PASS | result_engine.php |
| **Fail band** | `grading_scheme.is_fail` is **ANDed** onto the pass test — it can only turn a pass into a fail, never the reverse: `passed = entered && !absent && obtained >= passing && !is_fail`. Overall PASS/FAIL stays "every counted subject passed" | result_engine.php |
| Theory / practical | split applies when `class_subjects.theory_marks` **and** `practical_marks` are both set and > 0, and they must sum to `total_marks` (±0.01). `marks_obtained` is the server-side sum — a posted total is ignored. Unset ⇒ single-figure entry as before | marks_entry.php |
| Subject remark | `marks.remarks` (per student per subject) overrides the grading-band remark in the card's Remarks column | result_engine.php |
| Position "of N" | `result_summaries.section_total` frozen at publish and read back — no live COUNT, so a published rank cannot drift when the roster changes | publish compute |
| Year lock | `academic_years.is_locked = 1` blocks marks entry for every term of that year, enforced server-side alongside term-Open and not-published. The **current** year cannot be locked | marks_entry.php, result_settings.php |
| Republish safety | `result_summaries` is delete-then-insert, so `teacher_remarks` and `promoted_status` are read into a map **before** the delete and re-inserted — republishing never erases a class teacher's comment | result_engine.php |

---

## 9. Standards Compliance (CLAUDE.md — enforced every phase)

Byte-0 `<?php` + 3-line Rameez Scripts branding in every file · bind_param everywhere, types verified · CSRF on all POSTs, `exit()` after JSON · role gate BEFORE data, scoping in WHERE · `styles.css` + `sidebar.php` on every page, **zero inline CSS** · SearchableDropdown/MultiSelect only (plain `<select>` banned) · top loading bar + button busy state on every mutation (rule 31) · toggle switches + DaisyUI file inputs · DataTables 10/page + CSV/PDF/Print (pinned CDNs) · skeleton loaders · AdminLTE dashboard components · About = living RBAC + Formulas doc · every CRUD → activity_logs · ISO dates in DB, `d M Y` on cards · mobile: 16px inputs, responsive tables, popup grid scrolls · Welcome Tour (4 steps: Dashboard / Academics / Marks Entry / Results) once per user.

---

## 10. Demo Seed (setup install — generic names per demo-data.md)

1 year (2025-2026, current) + 3 terms (First Term **Open**, Mid Term, Final) · default grading scheme · classes 4-5 with sections A/B · 6 subjects (English, Math, Science, Urdu, Islamiat, Computer) mapped @100/33 · 2 teachers (`teacher1/teacher123` — Math+Science 5-A, `teacher2/teacher123` — English 5-A/5-B) · 15 students in 5-A, 10 in 5-B (`stu-2026-0001/student123` pattern) · First Term marks for 5-A fully entered + **published** (so cards/portal demo instantly) · admin `admin/admin123`. All credentials on About page.

---

## 11. Build Phases (contract)

| # | Phase | Delivers | Acceptance |
|---|-------|----------|-----------|
| 0 | Schema & Foundation | `update_setup.php` (13 tables, role ENUM→VARCHAR fix, users cols, seeds, helpers), thin `setup.php`, sidebar keys, roles matrix | Fresh install + re-run both clean; Teacher/Student assignable; reset rebuilds |
| 1 | Result Settings | §6 hub complete incl. logo upload, terms CRUD, grading editor | Change name/logo/terms → reflected everywhere with zero code edits |
| 2 | Classes & Subjects | classes/sections/subjects/class_subjects CRUD | Marks config per class-subject; RESTRICT rules work |
| 3 | Teachers & Assignments | teachers.php + assignment modal | One-teacher-per-subject-per-section enforced; load chips visible |
| 4 | Student Registration | register txn, list, import CSV, credential slip, promotion | Student can log in immediately; import skips dups with report |
| 5 | ⭐ Bulk Marks Entry | §7.3 popup end-to-end | Teacher sees ONLY own subjects (1–2 cols); 30 students saved in one txn; keyboard-only entry works; lock states correct |
| 6 | Results & Publishing | completion chevrons, publish/unpublish, summaries, positions, card, tabulation, bulk print | Publish blocked <100%; positions correct incl. ties; card matches settings; A4 print clean |
| 7 | Portal, Dashboards, Notifications | 3 dashboards, my_results, publish alerts (bell/push/email) | Student sees only own published results; unpublished = 403; alerts arrive |
| 8 | About + Audit | about.php living doc, log sweep, ui-test + verify checklists, demo polish | Byte-0 audit passes; every mutation logged + animated; About tables complete |

---

## 12. Out of Scope (future)

Attendance · fees · timetable · SMS gateway · grace marks · weighted multi-term aggregate/annual combined card · multi-school · parent role · online exams.

---

_Built by Rameez Scripts._
