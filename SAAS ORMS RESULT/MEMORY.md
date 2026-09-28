# MEMORY — Online Result Management (ORMS)

> **Read this first.** Session-continuity notes for this codebase. The build contract is `requirement.md` (9 phases); this file is the *current state* + the gotchas that will bite you if you "clean them up". Anything under **Don't Undo** was done deliberately.

---

## 1. What this is

School **result management system** — PHP 8 + MySQL (mysqli, prepared statements everywhere), no framework, no build step. Built on top of the in-house *LOGIN SIGNUP WITH SMTP (Premium)* template, so the auth/roles/settings/backup layer is inherited and the **academic + results layer is the new work**.

| | |
|---|---|
| **Path** | `C:\xampp\htdocs\ONLINE RESULT MANAGEMENT` (WSL: `/mnt/c/xampp/htdocs/ONLINE RESULT MANAGEMENT`) |
| **DB** | `result` — localhost / `root` / no password |
| **Install** | open `setup.php` → **Install** |
| **Stack** | PHP 8, MySQL, jQuery 3.7.1, DataTables 1.13.7, SweetAlert2 11, Chart.js 4.4.0, Font Awesome 6.5.1 (all CDN, all pinned) |
| **Theme** | Navy `#001f3f` / `#0074D9`, all CSS in `styles.css` — **no inline `<style>` or `style=""` anywhere** |

---

## 2. Demo logins

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `admin123` |
| Principal (Head Teacher) | `principal` | `principal123` |
| Teacher | `teacher1`, `teacher2` | `teacher123` |
| Student | `STU-2026-0001` … `STU-2026-0025` | `student123` |

Students and teachers are created with `must_change_password = 1` (they share a default password). `ormsEnforcePasswordChange()` at the bottom of `config.php` enforces it globally — logout / account / login stay reachable.

---

## 3. Files & their roles

### Core / shared
| File | Role |
|------|------|
| `config.php` | DB connect, `q*()` query helpers, `json*()` responders, session + CSRF + rate-limit, `getSetting/setSetting`, `$RBAC_PAGES` registry, `can()/requirePerm()`, `orms*` lookups, password-change enforcement |
| `result_engine.php` | **All result maths + all card rendering. Nothing else may compute a percentage.** |
| `orms.js` | Shared client runtime — see §7 |
| `styles.css` | Every style in the app (~8.8k lines) |
| `sidebar.php` / `mobile-menu.php` | Nav, theme bootstrap, speculation-rules prefetch, `pagereveal` body reveal |
| `setup.php` / `update_setup.php` | Install/reset **and** the only schema source — see §8 |
| `about.php` | **Living documentation** — RBAC matrix + Formulas & Business Logic tables. Update in the same pass as any logic change. |

### Academic layer
`students.php` · `teachers.php` · `classes.php` · `subjects.php`

### Results layer
`marks_entry.php` · `results.php` · `my_results.php` · `result_card.php` · `result_settings.php`

### Public / auth
`index.php` (public result lookup) · `login.php` · `signup.php` · `forgot_password.php` · `reset_password.php` · `verify_otp.php` · `logout.php` · `oauth_callback.php`

### System
`users.php` · `roles.php` · `logs.php` · `sessions.php` · `settings.php` · `backup.php` · `smtp_setup.php` · `oauth_setup.php` · `impersonate.php` · `maintenance.php` · `notifications_*.php` · `push_*.php` · `webpush_helper.php` · `sw.js` · `manifest.php` · `welcome_tour.php`

---

## 4. Roles & permissions (RBAC)

**Four** seeded roles in the `roles` table, permissions stored as JSON per page key with **V / A / E / D** bits. `roles.php` edits the matrix live and it propagates everywhere (sidebar, route gates, action gates, the About page table).

| Role | Scope |
|------|-------|
| **Admin** | Everything. |
| **Principal** (head teacher, added 2026-08-01) | The whole school on the academic side. `students/teachers/classes/subjects/results` = V+A+E, `result_settings` = V+E, `dashboard/marks_entry/my_results/logs` = V. **No Delete bit anywhere. Zero system pages** (users, roles, sessions, settings, backup, smtp, oauth). Approves, publishes and unpublishes. Cannot enter marks, cannot impersonate, blocked during maintenance mode (Admin-only, deliberate). |
| **Teacher** | Own `teacher_subjects` assignments only — their sections, their subjects, the years they actually held that section. |
| **Student** | Dashboard + My Results (own row only, published terms only). |

Page keys registered in `$RBAC_PAGES` (`config.php`), grouped **General · System · Academics · Results**. A page missing from that registry never renders in the sidebar or the matrix.

**Row scoping is identity-based** (`stuScope()`, `resScope()`, `myrResolve()`, `result_card.php`, `logs.php`) — resolved from `teacher_subjects`, never from permission bits.

🚨 **`ormsSchoolWide($role)` / `ormsSchoolWideRoles()` (config.php) is the single identity gate** returning true for `['Admin','Principal']`. Every "does this role reach the whole school" test goes through it. It is a **function, not a global array**, on purpose: `getActivityLogs()` sits ~760 lines above it and PHP hoists functions but not variables — as a global it read `null` and threw. A Principal deliberately holds **no `teachers` row** (the Teachers page lists anyone who has one, and `teacher_subjects.teacher_id` FKs to `teachers.id`), which is exactly why the identity gate — not an assignment — has to grant the reach. Without it every scoped query returns `0 = 1`.

---

## 5. Data model

### Academic tables (14, added by this project)
| Table | Notes |
|-------|-------|
| `academic_years` | Exactly one `is_current`. `is_locked` = year-end close — blocks all marks entry, can never be current. |
| `exam_terms` | Terms inside a year. Status `Upcoming / Open / Closed`; marks accepted only while **Open**. **`weightage` now drives the weighted year result** (any weight > 0 turns it on; all 0 = plain sum). |
| `classes` | + `numeric_level` (sorting), `next_class_id` (promotion target), **`result_template`** (NULL = inherit global), **`show_position`** (0 = cards of this class hide rank; ANDed with the global toggle) |
| `sections` | Inside a class; optional capacity + class teacher |
| `subjects` | Unique code, type Core/Elective/Optional, default `include_in_total` |
| `class_subjects` | **Marks config lives here, not on the subject** — `total_marks`, `passing_marks`, optional `theory_marks`/`practical_marks` split, per-class `include_in_total` override, **`is_optional` = enrolment-gated elective** |
| `student_subjects` | **Elective enrolment** — (student, subject, year) unique. An optional subject exists ONLY for enrolled students (card, grid, completion). Managed: Subjects › Assign to Class › **Students** button. |
| `teachers` / `students` | 1:1 profile rows onto `users` (name/phone/photo stay on `users`) |
| `teacher_subjects` | Unique per year + section + subject |
| `marks` | One row per student+subject+term. Stores obtained, theory/practical parts, absent flag, remark, **and a snapshot of `total_marks` + `passing_marks`**. Unique key → bulk save is an upsert. |
| `grading_scheme` | Bands: grade, min%, max%, grade point, remarks, colour, `is_fail` |
| `result_publications` | Publish state per term+section, who published/unpublished and why |
| `result_summaries` | **Frozen** result per student per term — totals, %, grade, GPA, position, PASS/FAIL, `subjects_count`, `section_total`, **`verify_token`** (32-hex QR verify capability, unique) |

### Inherited template tables (11, never forked)
`users` · `roles` · `system_settings` · `activity_logs` · `notifications` · `push_subscriptions` · `user_sessions` · `login_attempts` · `remember_tokens` · `password_resets` · `email_verifications`

---

## 6. Key backend functions (`result_engine.php`)

| Function | Does |
|----------|------|
| `ormsBandFor($pct)` | Grade band — **highest band whose `min_percent` ≤ pct** |
| `ormsComputeStudentRow()` | One student's subject rows + totals, %, GPA, PASS/FAIL, failed list |
| `ormsBuildSectionResults()` | Whole section ranked; ties share a position and the next rank skips (1, 1, 3) |
| `ormsWriteSummaries()` / `ormsComputeSummaries()` | Delete-then-insert `result_summaries` inside a transaction |
| `ormsPublishSection()` | Refuses below 100% entry; returns recipients so the caller notifies **after** commit |
| `ormsUnpublishSection()` | Admin only, reason mandatory, drops the snapshots |
| `ormsResolveResultSection()` | The **frozen** section for a (student, term) — summary → marks → live |
| `ormsStudentResult()` | Everything one card needs; stored snapshot wins when published, live maths otherwise |
| `ormsStudentYearResult()` / `ormsMergeTermSlices()` | Term-wise data for the report templates |
| `ormsTemplates()` / `ormsResolveTemplate()` | Card template registry + resolution |
| `ormsRenderResultCard()` | The only card renderer entry point (also resolves the class `show_position` flag) |

New shared helpers in **config.php** (2026-07-31): `ormsHasElectives()` (one probe/request — pre-migration installs keep old maths) · `ormsElectiveSql()` (**the ONE definition** of the enrolment clause appended to every completion/entered query; aliases `m` + `cs2` everywhere) · `ormsElectiveMap()` (sid → elective set; `null` = table missing → no filtering) · `ormsClassShowsPosition()` · `ormsVerifyUrl()`.

---

## 7. Client wiring (`orms.js` — `window.ORMS`)

| API | Purpose |
|-----|---------|
| `ORMS.post(action, data, opts)` | AJAX funnel. Auto-adds `action` + `csrf_token`. **Picks the feedback signal itself** (see §9 Motion). |
| `ORMS.postOrFail(...)` | Same, but `{success:false}` and transport errors reject + toast |
| `ORMS.dropdown(sel)` / `.refresh(sel)` | SearchableDropdown — the real `<select>` stays in the DOM so `serialize()` works. **Plain `<select>` is banned.** |
| `ORMS.bar` | Thin top loading bar — **reads only** |
| `ORMS.proc` | Branded processing overlay — **writes only**, counter-based |
| `ORMS.busy(btn, on, label)` | Button busy state (disabled + `fa-spinner fa-spin` + verb) |
| `ORMS.swap(fn)` | Wraps a DOM swap in a same-document View Transition — used by every tab switch |
| `ORMS.printOnly(sel)` | Prints ONE element; walks target→body hiding siblings |
| `ORMS.confirmDelete()` · `ORMS.ok/err` · `ORMS.esc` · `ORMS.debounce` · `ORMS.parseCSV` · `ORMS.downloadCSV` | |

**Cache-busting:** every page links `styles.css?v=N` and `orms.js?v=N`. **Bump the version across all PHP files whenever you edit either file**, or the browser serves stale assets.
Current: `styles.css?v=11.7` · `orms.js?v=1.9`

```bash
# bump both (run from project root)
grep -rl 'styles.css?v=OLD' --include="*.php" . | xargs sed -i 's/styles.css?v=OLD/styles.css?v=NEW/g'
```

---

## 8. Schema changes — the two-file rule

- **`update_setup.php` is the ONLY schema source.** `applyUpdates()` = `CREATE TABLE IF NOT EXISTS` + `addColumnIfMissing()` + `INSERT IGNORE` seeds. Idempotent, additive-only, never drops. Safe to run on production after any deploy.
- **`setup.php`** is a thin installer that `require`s it. `?action=install` builds + seeds; `?action=reset&confirm=yes` **drops all 24 tables**.
- Schema evolves → append to `applyUpdates()` **only**. Never edit `setup.php`, never branch a new migration file.
- Both share `installerAllowed()` — anonymous access is permitted only while no Admin row exists (bootstrap), then it's Admin + POST + CSRF.

### ⚠️ Run once after deploy
`update_setup.php` — adds `student_subjects` (+ one-shot behaviour-preserving enrolment backfill), `result_summaries.verify_token` (+ per-row token backfill + unique index), `classes.show_position`, `classes.result_template`, and seeds `result_template` + `result_show_qr` settings. Existing data untouched. **Until it runs, results/marks/dashboard pages that join `student_subjects` will error** — the engine itself degrades gracefully via `ormsHasElectives()`, but publish writes `verify_token` and needs the column.

---

## 9. Result card templates (added 2026-07-31)

8 skins in `ormsTemplates()`:

| Key | Label | Term-wise | Shape |
|-----|-------|:---:|-------|
| `classic` | Classic Navy | — | Original card |
| `compact` | Compact Print | — | Ink-light + dense (CSS skin over classic) |
| `modern` | Modern Accent | — | Rounded, gradient title, striped rows (CSS skin) |
| `elegant` | Elegant Serif | — | Serif, double frame (CSS skin) |
| `board` | Board Marksheet | — | Monochrome bordered grid (CSS skin) |
| `formal` | Formal Slip | — | Own renderer — stats box, key to grades, comment row |
| `report` | Academic Report | ✅ | Subject rows × term columns + year overall |
| `progress` | Progress Grades | ✅ | Grade-letter matrix per term |
| `marksheet` | Consolidated Mark Sheet | ✅ | Board style — FULL/SECURED column pair per term, Total row, then %/Grade/GPA/Result/Position footer rows |

`marksheet` (added 2026-07-31) is modelled on a CBSE-style printed sheet: `ormsCardShell` masthead, boxed `.rcm-info` identity block, then a `<thead>` of two rows — term names with `colspan="2"` over a `rowspan="2"` Subjects cell, then the FULL/SECURED labels. Every body row is 1 + 2×terms cells and every footer row is 1 + terms×`colspan="2"`; keep those in step or the grid shears. `ormsTermSlice()` now also carries `result_status` from the stored summary so the per-term Result row shows the published snapshot.

**Resolution order:** staff `?template=` preview → `classes.result_template` → `result_template` setting → `classic`.
Configured in **Result Settings → Templates** (gallery with live preview + per-class dropdowns). Renderers all live in `result_engine.php`; skins are the `.rc-t-*` CSS block.

### Motion & feedback (per `docs/animation.md`)
**Two signals, never both** — writes get the branded `.proc-ov` overlay, reads get the thin `#ormsBar`. Wired **into `ORMS.post`**, not into call sites: the verb is derived from the action name (`deleteX` → "Deleting…", `publish` → "Publishing…"). A new endpoint gets correct feedback for free.
Plain (non-AJAX) form posts — login, signup, reset, OTP — get the overlay from a document-level `submit` listener that skips `e.defaultPrevented`.

---

## 9b. Head teacher approval gate (added 2026-08-01)

State machine on `result_publications` (one row per term+section), column `approval_status`:

`Draft` → **Submit for Approval** → `Pending` → **Approve** → `Approved` → **Publish** → live.
**Reject** (mandatory note) sends `Pending` → `Rejected`; resubmitting returns it to `Pending`.

- Setting **`require_principal_approval`** (default `1`). When on, publishing a non-`Approved` section is refused in `results.php` **and** again inside `ormsPublishSection()`.
- **Admin may approve as well as Principal** — so a school with no Principal account can never lock itself out of publishing. This is deliberate; don't "tighten" it to Principal-only.
- Actions in `results.php`: `submitApproval`, `approveSection`, `rejectSection`, `savePrincipalRemarks`. Read side is folded into the existing `getSections` query (no `getApprovals` action) so the tab and the status chips can never disagree.
- Helpers in `result_engine.php`: `ormsApprovalStatus/Required/Blocked()`, `ormsCompletionGate()` (the 100% check, extracted so publish and submit share it), `ormsHeadLabel()`.
- `ormsApprovalStatus()` catches a pre-migration DB and returns `'Approved'` → gate off, never a locked-out install.
- **Unpublish clears the whole sign-off trail** and resets to `Draft`; the activity log keeps the history.
- 🚨 **`ormsApprovalInvalidate()` lives in `config.php`, not `result_engine.php`** — `marks_entry.php` loads config only. Any marks write on an `Approved`-but-unpublished section resets it to `Draft`, so a sign-off can never outlive the marks it was given for. It has its own pre-migration try/catch: without it, an un-migrated DB would throw mid-transaction and roll back a legitimate marks save.
- `result_summaries.principal_remarks` is carried across republish at **all three points** (the `$keep` SELECT, the INSERT column list, the per-row re-apply) exactly like `teacher_remarks`. The bind string is 20 chars — `iiiiidddsdissiissssi`.
- Card: `result_principal_name` / `_designation` / `_signature` print above the right-hand signature label. `.rc-sign` has `border-top` (that *is* the signature rule), so the scan is positioned **out of flow** (`position:absolute; bottom:100%`) to sit above the line — a negative margin shifts the whole row instead.

---

## 9c. Offline score sheet — download → fill offline → import (added 2026-08-01)

Built because teachers often lack the mobile data to stay online while typing a whole class.

- Actions in `marks_entry.php`: `downloadSheet` (CSV attachment), `importMarksPreview` (validates, writes nothing), `importMarks` (commits).
- 🚨 **`mePersist($ctx, $rows, $dry)` is the ONE write path** — the grid save, the import preview (`$dry = true`) and the import commit all call it, so preview and commit can never drift. Do **not** add a second write path; that is the most likely way to re-open the cleared-cell / snapshot bugs in §10.
- `importMarksPreview` must keep a name ending in `preview` — `orms.js`'s `READ_RE` matches `(stats|preview|activity|cards)$` and routes it to the thin top bar instead of the write overlay.
- CSV: `#` comment header lines, then `Admission No, Roll No, Student Name,` + one column per subject **the current user may enter** (`meSubjectCols()`). Pre-filled so a half-finished sheet round-trips. `AB` = absent, blank = clear, `-` = optional subject the student isn't enrolled in. UTF-8 BOM for Excel. A **theory+practical split subject gets two columns** — one column can't express the split without corrupting it.
- Validation is server-side and **re-run on commit**; the preview payload from the client is never trusted. Skip-and-collect, never fail-fast. Per-subject authority via `ormsCanEnterMarks()` on both passes.
- CSV only, no XLSX — this project is Composer-free.

---

## 9d. 🏢 Multi-tenant SaaS conversion (started 2026-08-13) — Phase 0 landed

The app is becoming a **multi-school + multi-branch SaaS**: Platform → School → Branch. Plan file: `~/.claude/plans/multi-school-multi-branch-saas-luminous-moon.md`.

**Locked decisions:** shared DB with `school_id` scoping (not DB-per-school) · login = **School Code + username** · classes/sections **per branch**, academic years + terms **school-wide** · roles gain **Super Admin** (platform) and **Branch Admin** (one branch) → 6 roles · billing = **manual approval** first (plans/subscriptions/expiry gate/renew page; gateway later on the same tables).

### 🚨 `platform_mode` is the master switch — and it ships as `0`
Seeded `platform_mode = '0'`, meaning **the app behaves exactly as the single-school system it has always been**: no school code on login, subscription gate completely inert, school Admins keep the installer. Flip it to `1` **only** once a Super Admin exists. Everything tenant-shaped is dormant until then, which is what makes the migration safe on a live install.

### Phase 0 contracts (config.php) — use these, never hand-roll a filter
| Helper | Contract |
|---|---|
| `sid()` | current school. **Fails closed** — a logged-in session with no school stamp is destroyed and bounced to login, never treated as unscoped |
| `ormsIsPlatform()` | platform operator. A **separate session flag** (`is_super`), *never* `sid() === 0` — otherwise a session that merely forgot its stamp reads as god mode |
| `bid()` / `ormsBranchLock()` | branch filter; a Branch Admin is **pinned** and cannot override it |
| `ormsOwns($table,$id)` + `ormsFindStudent/Teacher/Class/Section/Subject/Term/Year()` | **the only sanctioned way to turn a request id into a row.** Returns 0 for another school/branch. `$table` is whitelist-matched, never interpolated |
| `ormsSectionScope()` / `ormsCanSeeSection()` | **one** definition, replacing the four byte-identical clones (`resScope`/`attScope`/`bsScope`/`feeScope`). Never returns null |
| `getSetting()/setSetting()` | per school with a **platform (school 0) fallback**; cached per request per school. Call unchanged |
| `ormsEnforceSubscription()` | runs at the file bottom **before** `ormsEnforcePasswordChange()`; fails **open** on any error |

### Don't-undo rules born in Phase 0
1. **`ormsSchoolWide()` now means "the whole of THIS school", never "no filter".** It was the wildcard: every rail returned an empty predicate for Admin/Principal, which in a shared DB is every school. Restoring an empty branch anywhere re-opens the whole conversion.
2. **`school_id = 0` means platform.** Only `users` (Super Admins), `system_settings` (platform defaults) and `roles` (the template set) may ever hold it. Nothing else, ever.
3. **`users.school_id` is `INT NOT NULL DEFAULT 0` with NO foreign key.** `0` not `NULL` because MySQL treats NULLs as distinct in a unique index, which would let two Super Admins share a username under `UNIQUE(school_id, username)`. No FK because no `schools` row can have id 0.
4. **Platform pages are seeded `$none` for every school role.** They live in `$platformPages`, deliberately *not* in `$pages` — appending them would flow through `$adminPerms = array_fill_keys($pages, $full)` and the `array_diff_key` merge and silently grant every school's Admin full CRUD over the platform console on the next migration run.
5. **`backup` is platform-only.** One `SHOW TABLES` dump in a shared DB is every tenant's data; it cannot be fixed by scoping.
6. **`installerAdmin()` is no longer `role === 'Admin'`.** Every school has an Admin and `setup.php?action=reset` drops everything. It is now the platform operator, with a **transitional clause** (`Admin && !platform_mode`) that exists solely so an un-migrated single-school install can still run the updater that creates its first Super Admin. That clause dies when `platform_mode` flips.
7. **Unique-index rebuilds ADD the composite before DROPping the global one.** If the add fails the old key still protects the table (over-strict, never permissive); the reverse order can leave a table with no uniqueness at all, which surfaces as "Invalid username or password" for every duplicate-named user.
8. **`login_attempts` is keyed `(username, school_id)`.** Username alone let one school's five bad tries lock out every other school's `admin`, and a success at school A cleared school B's counter.
9. **`renew.php` is in BOTH bottom gates' allow-lists** — otherwise a user who is past-due *and* force-password-flagged ping-pongs between `renew.php` and `account.php`.

### Phase 1 (scoping sweep) — all 5 file groups landed
`students`/`teachers` · `result_settings` · `classes`/`subjects`/`broadsheet`/`attendance`/`fees` · `dashboard`/`users`/`logs`/`roles`/`sessions`/`settings`/`backup`/`notifications_api` · `marks_entry`/`results`/`result_engine`/`my_results`/`result_card`.

**The results core is where the live holes were:** `publish` and `unpublish` in `results.php` had **no scope check at all**, so a school-wide role could POST another school's `term_id`+`section_id` and publish that school's unfinished results to their parents. All eleven endpoints now resolve ids and check `ormsCanSeeSection()`.

**Resolver naming — don't be fooled by grep.** The pages call `ormsOwnStudent()` / `ormsOwnTerm()` / `ormsOwnSection()` / `ormsOwnClass()` / `ormsOwnSubject()` / `ormsOwnYear()`, thin wrappers in `result_engine.php` over the `ormsFind*` family that fall through to a raw int on a pre-migration DB (`ormsTenantLive()`). Searching for `ormsFindStudent` in `my_results.php`/`result_card.php` finds nothing and looks unscoped — it isn't.

**The entered predicate is intact at all 8 sites** (dashboard ×4, results ×2, result_engine ×1, marks_entry ×1), base string byte-identical, elective clause still only from `ormsElectiveSql()`. The tenant fragment is `" AND m.school_id = ?"` appended immediately after `ormsElectiveSql()` with `sid()` bound at that slot — both the results agent and the dashboard agent independently chose the same shape, so they match.

`marks_entry.php` carries a **structural copy** of the helpers (`meSchoolSql` etc.) because it loads `config.php` only, never `result_engine.php` — that separation is deliberate and long-standing.

Bugs caught during the sweep, worth remembering:
- **`readRoles()` was briefly broken** by the per-school cache rewrite (`$cache[]` appended to the outer static and it returned the whole array, so every call after the first returned empty and RBAC silently vanished mid-request). Fixed; the cache appends to `$cache[$s]` and returns `$cache[$s]`.
- **`readRoles()` reads ONE school's rows**, not `IN (0, ?)` — merging the school-0 template would show every school a `Super Admin` row it must never assign, plus duplicates of its own four roles. School 0 is only a fallback when a school has no rows of its own.
- **`ormsOwns()` / `ormsSectionScope()` must stay column-tolerant.** Both join `school_id`, so on a pre-migration DB they threw and the catch returned `0`/`[]` — i.e. denied everything. They now check `ormsHasTenancy()` first. Don't remove that check.
- **`classes.php`'s teacher picker had an unbracketed `WHERE t.status = 'Active' OR t.id IN (...)`** — appending a tenant fence would have bound to only the OR's right side. Now parenthesised.
- **`ormsStampTenant($userId, $role)` is the one writer of the tenant session keys.** All four mint paths call it (login, remember-me, the three oauth branches, impersonate). `sid()` fails closed, so a path that forgets it puts the user in a login loop.
- **A single-school install loses whole-DB `backup.php`** now that it is platform-gated (`ormsIsPlatform()` is false there). Deliberate; revisit with a per-school export.

### Phase 2 (platform console, auth, billing) — new pages
`schools.php` · `branches.php` · `plans.php` · `subscriptions.php` · `register_school.php` · `renew.php`. All double-gated: the RBAC key **and** a hard `ormsIsPlatform()` check, so a school admin whose matrix somehow carried the key still never reaches a query.

- **Creating a school is one transaction and the role clone is step 3, before the user insert:** school → main branch → **clone the school-0 role rows except `Super Admin`** → Admin user → `owner_user_id` → subscription. A **zero-row role clone throws and rolls everything back** — a school with no permission matrix is dead on arrival, so it is a hard failure, not a warning.
- **Billing never updates a period row.** Renewal INSERTs a new `school_subscriptions` row starting at `max(today, current ends_at)`, so an early renewal extends instead of truncating. Only genuinely lapsed rows are marked `Expired`. `subAddMonths()` clamps day-of-month (31 Jan + 1 month = 28 Feb).
- **Currency is fixed by the school's own newest period row**, not the posted value — otherwise two currencies could enter one `SUM(amount)`. Note `ormsMoney()` statically caches the *operator's* symbol per request, so it is wrong for a cross-school money screen; `subscriptions.php` uses a local `subMoney()`. Give `ormsMoney()` an optional `$code` argument if another platform page needs per-row currency.
- **`schools.trial_ends_at` is now enforced** — `ormsSubscriptionState()` previously treated a never-billed `Trial` school as permanently active, so the column meant nothing. A Trial school past `trial_ends_at` is `expired`.
- Public `index.php` gets a school step before the Year/Term/Class/Section cascade (admission numbers collide across schools) and pins `$GLOBALS['ORMS_SETTING_SCHOOL']` so it shows **that** school's branding. The `?verify=` QR query is byte-identical and still has **no** school filter — the school is resolved afterwards and only decides the header. DOB stays in the WHERE clause, never logged; one identical failure line covers "unknown school" too.
- `sidebar.php` now shows the **school's** name and logo (was the user's profile picture + a hardcoded Blogger URL), a "Platform" pill for the operator, and a branch switcher gated on `ormsSchoolWide() && !ormsBranchLock() && count($branches) > 1`.

### Phase 3 (close-out) — done
Cache-busters unified to **`styles.css?v=12.0`** and **`orms.js?v=2.0`** across all 42 refs · `about.php` gained the Super Admin and Branch Admin role rows plus a **"Tenancy, branches & subscription"** formulas group (the RBAC matrix itself renders live from `readRoles()`, so it picked up all 6 roles on its own) · the tenancy contract was written up beside `requirement.md`.

**Two blockers only fixable in `config.php`, fixed at the end:**
- `ormsTerm()` returned null for logged-out visitors (`sid()` is 0 with no session), so **every valid public lookup answered "no result found"** on a migrated DB. It now falls back to `$GLOBALS['ORMS_SETTING_SCHOOL']`.
- `ormsResultBranding()` read `LIKE 'result\_%'` with no school leg, so **last row won and every page printed an arbitrary tenant's name and logo**. Now school row over platform row.

🚨 **Name the Super Admin something other than `admin`.** While `platform_mode = '0'` the login lookup is deliberately unscoped and requires exactly one matching row, so a Super Admin at school 0 called `admin` plus a school-1 `admin` **locks out both accounts**. Use `superadmin`. (Scoping the non-platform login to `school_id IN (0,1)` with a deterministic tiebreak is the proper fix if this ever bites.)

**Open decisions flagged during the build, not yet made:** `ormsCurrencyCode()` defaults blank input to `USD` rather than the install's currency · `subscription_payments.subscription_id` is `ON DELETE SET NULL` while periods cascade from `schools`, so receipts can outlive a purged school · a single-school install loses whole-DB `backup.php` now that it is platform-gated. Audit baseline at Phase 1 start: **214** unfiltered tenant-table statements, **149** raw `WHERE id = ?` fetches. ⚠️ The raw grep is now noisy — agents added deliberate pre-migration fallback queries that legitimately omit the filter, so judge per-file coverage rather than the total.

---

## 10. 🚨 Don't Undo — decisions & gotchas

These all look like bugs and are not. Reverting any of them re-opens a real defect.

1. **`.initially-hidden` MUST be the last rule in `styles.css`.** It's `display:none` at specificity (0,1,0) — the same as every component class — so ties break on **source order**. Declared mid-file it silently lost to any component that sets its own `display` later (`.marks-locked-banner`, `.orms-empty`, `.stat-mini`, `.assign-grid`, `.a4-wrap`), leaving those permanently visible. **Do not "fix" it with `!important`** — ~12 elements across logs/backup/settings/oauth/sessions/results are revealed with jQuery `.show()`, whose inline style must still win. Add new rules *above* it.
2. **Grade lookup is "highest band whose `min_percent` ≤ pct", not `BETWEEN`.** Seeded bands gap at 89→90, so `BETWEEN` returns no grade for 89.5.
3. **A cleared marks cell DELETEs its row** — it must never write `marks_obtained = NULL`. Four files share one byte-identical "entered" predicate (`result_engine.php`, `results.php`, `dashboard.php`, `marks_entry.php`): `(m.marks_obtained IS NOT NULL OR m.is_absent = 1)` + `st2.section_id = m.section_id`. Diverging any copy publishes wrong FAILs.
4. **Always resolve the frozen section** via `ormsResolveResultSection()`, never live `students.section_id`, for published data — otherwise promotion orphans old cards.
5. **`marks.total_marks` and `marks.passing_marks` are snapshots.** Later `class_subjects` edits must never rewrite history.
6. **`include_in_total` changes the maths.** A subject counts only when BOTH `subjects.include_in_total` and `class_subjects.include_in_total` are 1. An excluded subject still prints and still counts toward *completion*, but is out of total / % / **GPA (divisor drops)** / PASS-FAIL. Nothing counted ⇒ never PASS.
7. **`grading_scheme.is_fail` is ANDed** onto the pass test — it can only turn a pass into a fail, never the reverse.
8. **`result_summaries` is delete-then-insert on republish**, so `teacher_remarks` / `promoted_status` are read into a map **before** the delete and re-inserted. Removing that erases class-teacher comments.
9. **`ORMS.post` drops the page's query string** — it posts to `window.location.pathname`. Any AJAX-reachable param must be read `$_POST['x'] ?? $_GET['x']` (results/subjects/my_results do) **or** the caller passes `{method:'GET'}` (teachers.php does).
10. **`READ_RE` in `orms.js` needs the tail alternation `(stats|preview|activity|cards)$`** — without it `adminStats`, `studentStats`, `teacherStats`, `recentActivity`, `promotePreview` and `bulkCards` dim the whole screen on a plain refresh. New read actions that don't start with `get/load/fetch` go there, or pass `{read:true}`.
11. **DataTables / Chart.js inside a `.tab-pane` measure to 0** while hidden. Every tab handler must call `columns.adjust()` (+ `responsive.recalc()`) and `chart.resize()` on show.
12. **Print CSS honours the template skins.** `.rc-title` / `.rc-table th` use `var(--rc-accent)`, and compact/elegant/board keep their outlined treatment — a flat navy `!important` would flatten all 8 templates back to Classic on paper.
13. **Public `index.php` lookup requires DOB in the WHERE clause**, published-only, 8 lookups/10 min per IP, **identical failure message for every failure mode**, DOB never logged. Don't relax any of these.
14. **OTP verify fetches by email only**, then compares — so wrong guesses actually increment `attempts` (lockout at 5). Adding `AND otp_code = ?` silently defeats the counter.
15. **`login_attempts` stores successes too** — every rate-limit read must filter `success = 0`, or a login counts toward its own lockout.
16. **Never run `setup.php?action=reset` on real data** — it drops all 24 tables.
17. Sidebar uses **flat permission-gated sections**, not dropdowns (user preference).
18. `logActivity($uid, $uname, $action, $details='', $entity_type=null, $entity_id=null)` — the new params are trailing/optional; ~75 call sites depend on that.
19. **(2026-07-31) The entered predicate is now base + `ormsElectiveSql()`.** The 4 completion sites (`result_engine`, `results.php`, `dashboard.php`, `marks_entry.php`) still share the byte-identical base `(m.marks_obtained IS NOT NULL OR m.is_absent = 1)`, and the elective clause is appended ONLY via `ormsElectiveSql()` — never inline it, never rename its `m`/`cs2`/`ss2` aliases.
20. **`verify_token` is carried across republish** in the same `$keep` map as `teacher_remarks`/`promoted_status` — dropping it from the carry kills every already-printed QR. New rows mint `bin2hex(random_bytes(16))`.
21. **The elective backfill in `update_setup.php` runs ONLY on the run that creates `student_subjects`** (`$ssFresh`). Re-running it later would re-enrol students the school deliberately removed. Same reason `updateClassSubject` auto-enrols the roster only on the 0→1 `is_optional` transition.
22. **Un-enrolling a student who has marks in that subject+year is refused** (`saveElectiveStudents` skip-and-collect; the modal locks the checkbox). Un-enrolment would silently hide a real score.
23. **Weighted year result activates only when a term weight > 0** — all weights 0 keeps the original plain marks-sum. Weighted mode: subject % = Σ(term % × w)/Σw over entered terms; year %/GPA = weighted over term summaries; raw totals stay raw sums. Don't "simplify" either branch away.
24. **QR flow:** `ormsCardTail` emits `.rc-qr[data-qr]` (published + token + `result_show_qr` only) → `ORMS.qr()` fills it client-side via **qrcodejs 1.0.0 pinned** on index / result_card / results / my_results / result_settings. Public resolver = `index.php?verify=<32-hex>` — identical failure line for all misses, shared 8/10-min IP throttle, token never logged. Signed-in users are NOT redirected away when `?verify=` is present.
25. **Position gate is doubled:** global `result_show_position` AND `classes.show_position` (`ormsClassShowsPosition()`), enforced in all four card renderers + the public verify page. Staff tabulation/merit always see positions.
26. **🚨 A modal shell carrying `onclick="event.stopPropagation()"` kills every `$(document).on('click', …)` delegated handler inside it.** This is why the marks grid's AB and remark buttons silently did nothing while typing and the keyboard `A` shortcut still worked — `input`/`keydown` bubble, `click` was swallowed at the modal. **Bind marks-grid handlers to `#marksRows`, never to `document`** (fixed 2026-07-31). Same trap applies to any new control placed inside `#marksModal`, `#assignModal`, `#teacherModal`, `#viewModal`, `#statusModal` etc. — bind to a container INSIDE the modal, or use an inline `onclick` (which is why the Assign-Subjects remove button deliberately uses one). Audited 2026-07-31: every other document-delegated click in the app targets page-level chrome (`#resTabs`, `#rolePipeline`, `.js-go-tab`, `[data-close]`), none sit inside a stopPropagation modal.
27. **Printing anything inside a modal needs `printable-modal` on the overlay.** The global print block hides every `.modal-overlay`, so popup content prints as a blank page — that is the documented credential-slip bug. `.modal-overlay.printable-modal` (0,2,0) outranks it and flattens the overlay into normal flow; pair it with `ORMS.printOnly('#target')`, which hides the modal header and the rest of the page. Used by Result Settings → Templates → Print Preview; **the same one-line class fixes the students.php credential slip** whenever that gets picked up.
28. **Marks-grid zebra rows must repaint the pinned cells.** `.col-roll` / `.col-student` are `position: sticky` with their own opaque background, so a stripe on plain `td` stops at the third column. The stripe / hover / saved / error rules each list all three selectors, in that order, and dark mode repeats the set (its `body.dark-mode` prefix outranks the light hover rule, so dark hover is re-declared too).
29. **(2026-08-12) The viewport meta must NOT carry `maximum-scale=1.0, user-scalable=no`.** All 35 tags are now `width=device-width, initial-scale=1.0, viewport-fit=cover`. Blocking pinch-zoom fails WCAG 1.4.4 and is actively wrong for this app — a parent reading a dense marksheet on a phone has to zoom. `viewport-fit=cover` is what makes `env(safe-area-inset-*)` resolve to a real number; the safe-area rules had been in `styles.css` since day one but returned 0 without it.
30. **(2026-08-12) `table.dataTable`'s mobile `min-width: 600px` is scoped `:not(.dtr-inline)` on purpose.** DataTables **Responsive 2.5.0 is loaded on every list page** and marks the tables it drives `.dtr-inline`. A blanket 600px floor means Responsive can never make the table fit, so it folds nothing and the page just scrolls sideways — the extension was being paid for and doing nothing. The floor stays only for tables Responsive isn't driving. The `.dtr-*` child-row skin at the bottom of `styles.css` is required too: the CDN stylesheet only paints the +/- control (green/red, which reads as add/delete here) and leaves the folded row unthemed and unreadable in dark mode.
31. **(2026-08-12) The mobile DataTables toolbar drops `.dataTables_wrapper::before` and re-places info/paginate.** The desktop strip is one absolutely-positioned surface sized for a single grid row; once the toolbar stacks it can't stretch, so each piece carries its own surface instead. `.dataTables_info` (`grid-column: 1`) and `.dataTables_paginate` (`grid-column: 2 / -1`) **must** be reset to `1 / -1` in the same block — left alone the paginator conjures an implicit second column and un-stacks the whole strip.
32. **(2026-08-12) `.a4-sheet` keeps its true 210mm width on mobile and pans.** Squeezing an A4 card into 360px crushes every mark column, and the term-wise skins (up to 5 terms × 2 cols = 11 columns) become unreadable. Now that pinch-zoom is back the sheet reads like a PDF. `#cardWrap` in `my_results.php` is the toggled element and `.a4-wrap` moved *inside* it, so `vis('#cardWrap')` and `ORMS.printOnly('#cardWrap')` still work and the swipe hint (`.no-print`) never reaches paper.
33. **(2026-08-12) `orms.js` sets a global `columnDefs` default pinning `responsivePriority` on the first and last column.** Responsive folds from the right, so the Actions column — always last — was the first to disappear and every edit became expand-then-tap. No page uses `columnDefs`, so nothing collides, and `columnDefs` is applied *before* `columns`, so `teachers.php`'s explicit per-column priorities still win.
34. **(2026-08-12) On mobile the marks grid pins ONLY `.col-roll`.** `.col-roll` (60px) *and* `.col-student` (170px) were both `position: sticky` — 230px of a 360px screen frozen, leaving ~90px for the box you opened the page to type in, on a grid that runs **one column per subject** and is therefore always a sideways scroll. `.col-roll + .col-student` goes `position: static` ≤768px and the name column shrinks to 124px with the 34px avatar hidden. Roll is the mark register's own key, so row identity survives the scroll. The zebra/hover/saved/error rules (Don't Undo #28) already name `.col-roll + .col-student` explicitly at higher specificity, so releasing the pin does **not** break the stripe.
35. **(2026-08-12) `.orms-dd-panel` is a fixed bottom sheet ≤768px, and its backdrop must stay a real element.** The desktop panel is a 320px box anchored under its trigger; once its own search box raises the keyboard the visible viewport is ~250px and the list clips off-screen. Plain `<select>` is banned project-wide, so this lands on every filter and form field. Three things are load-bearing:
    - **`.orms-dd-backdrop` is a real `<div>`, never a pseudo-element on `.orms-dd-wrap`.** The outside-click handler closes on anything failing `closest('.orms-dd-wrap')`; a pseudo counts as the wrap, so tapping away would never dismiss the sheet.
    - **`--orms-dd-lift`** is written from `visualViewport` in `ddSheetLift()`. iOS pins `position: fixed` to the *layout* viewport, so without it an open keyboard sits on top of the sheet.
    - **No autofocus on the search box in sheet mode** — the keyboard would eat most of a 70dvh sheet before the user has seen the list.
    `ddSheetSync()` is the single source of truth (backdrop + `body.orms-dd-sheet-open`) and every close path funnels through `ddClose`, plus a `pageshow` failsafe so bfcache can't restore a stuck scroll lock. `.orms-dd-fixed` in the CSS is **dead** — nothing has ever applied it; the panel is positioned entirely in CSS, which is what makes the sheet possible without touching inline styles.
36. **(2026-08-13) `.header-right` carries `position: relative; z-index: 1200`.** Its `view-transition-name` makes it a stacking context, so the bell dropdown's z 1000 was trapped inside — any later positioned element on the page (an `.orms-dd` filter control) painted OVER the open notifications panel. 1200 stays below dd panels (10070), modals and Swal. Removing the z-index re-opens the overlap on every page with filters.
37. **(2026-08-13) `.sidebar-menu-section` has NO `flex: 1`; `.sidebar-theme` has `margin-top: auto`.** flex:1 on every section spread the sidebar's free height across all groups as dead space (the "huge margins between options"); the auto margin now pins theme + logout to the bottom instead. Sidebar is 240px, menu links 9px/12px padding + 14px font, tightened header/logo/logout to match. Re-adding flex:1 re-opens the gaps.
38. **(2026-08-13) The dashboard header is the STANDARD `.header` (icon + h1 + `header-crumb` + bell include) — BY USER REQUEST, matching my_results.** A custom sticky-glass `dash-top` bar (time greeting, gradient name, Session/Term pills, refresh spin, page-jump search, user chip) was built and polished twice earlier the same day and then the user asked for the standard header instead ("jaise my results me header hai aisa hi dashboard me"). All `dash-top`/`dash-hello`/`dash-pill`/`dash-find`/`dash-user`/`dash-refresh` markup, JS (`$jumpPages`/PAGES/toggleFind/dashRefresh) and CSS were REMOVED — don't resurrect them, and don't flag `.header` on dashboard as "should be custom".
39. **(2026-08-13) SearchableDropdown rich options:** `data-sub` on an `<option>` = two-line option with a searchable subtitle, `data-av` = leading avatar chip, `data-search-ph` on the `<select>` = search placeholder. `my_results.php` `#pickStudent` uses all three — option label is now name-only (the closed control shows just the name; the identity chips beside it already carry admission/class), and filtering by admission no still works because the term matches label + sub.
40. **(2026-08-13) Subject Remark popup is custom HTML (`.rmk-*` CSS section) but the save path is untouched** — `preConfirm` feeds the same `res.value` flow into `main.dataset.remark` + `markDirty`; newlines are normalised to spaces so the note stays single-line like the old text input. Chip/counter listeners are bound directly in `didOpen` (the Swal popup lives on `body`, outside the stopPropagation modal trap of #26).

---

## 11. UI conventions

- **ONE header bar per page (2026-07-31).** The standalone `.breadcrumb` bar is gone from all 21 pages; the trail now lives as `<nav class="header-crumb">` under the `<h1>` inside `<div class="header-page">`, with `notifications_bell.php` after it. **Never re-add a separate breadcrumb row** — that was the CLAUDE.md rule-39 violation. The old `.breadcrumb` CSS is intentionally kept in styles.css in case other markup still uses it. `.header > .header-page` is deliberately two-class specificity so it outranks the mobile `.header > div { text-align: right }` rule; the desktop wrap tweak is scoped `@media (min-width: 769px)` so it can't leak into the mobile `flex-direction: column` block.
- **DataTables chrome is themed globally in CSS** — `.dataTables_wrapper` is a grid that places `.dataTables_filter` / `.dataTables_length` / `.dt-buttons` into one toolbar strip (surface drawn by `::before`, absolutely positioned so it can't distort track sizing). Works for `Blfrtip`, `Bfrtip` and `lfrtip` alike, so **never change a page's `dom` string to restyle a table** — fix it in the shared CSS.
- **KPI cards must not reference `--navy-accent`.** `notifications_bell.php` overwrites that variable with the user's saved theme accent, which is how `.bg-info` turned magenta. The `small-box` ramp is `.bg-navy / .bg-navy-2 / .bg-navy-3 / .bg-navy-4` derived from the theme primary, with one status colour reserved for meaning.
- **Term-wise cards auto-fit their term count** — `ormsTermDensity()` stamps `rc-dense-1/2/3` from `count($cols)` (3 / 4 / 5+ terms) onto the marksheet, report and progress tables; the CSS steps padding and type down and drops subject-code suffixes at 4+. `.rcm-table` is `table-layout: fixed` with the subject column at 24%, so the mark columns divide evenly at any term count. Nothing to configure per school.
- **Filter bars** are compact control strips, not panels — one `.filters-section` rule keeps students / teachers / classes / subjects / results / marks entry / logs / users identical. Change it there, not per page.
- **Tabs** (`.tab-nav` + `.tab-pane`) on: about · classes · my_results · result_settings · results · students · subjects. Every switch goes through `ORMS.swap()`.
- **Cards** — `.data-section` (20px padding, 20px bottom margin, trailing child margin zeroed).
- **Dashboard** uses AdminLTE `small-box` KPIs; `my_results` uses the `small-box-sm` compact variant.
- **Navigation** is smooth via cross-document View Transitions (`@view-transition { navigation: auto }`) + hover prefetch (speculation rules, sidebar links only). Sidebar / bottom-nav / header tools carry `view-transition-name` with 0s duration so they don't crossfade.
- **Every page**: `styles.css` + `sidebar.php`, ISO dates stored / `d M Y` displayed, FA icons on every control, skeletons on first paint only.

---

## 11b. Mobile (pass done 2026-08-12)

The template's mobile base was already decent — bottom nav, off-canvas sidebar, touch targets, iOS input-zoom guard. What was broken was everything built *on top* of it, plus two settings that quietly disabled features already paid for.

**Where mobile rules live:** one **MOBILE LAYER** block at the very bottom of `styles.css`, immediately above `.initially-hidden`. It sits last because nearly every rule in it has to beat a base rule of identical specificity and ties break on source order. **New mobile rules go in that block**, never scattered mid-file, and never below `.initially-hidden`.

| Fixed | Was |
|---|---|
| Viewport meta (35 tags) | `user-scalable=no` blocked pinch-zoom **and** the missing `viewport-fit=cover` made every `env(safe-area-inset-*)` rule in the file evaluate to 0 |
| DataTables Responsive | loaded on every list page, defeated by a blanket `min-width: 600px`; folded child rows had **zero** theming (`dtr-` appeared 0× in `styles.css`) |
| Actions column | folded away first on every list — pinned via a global `columnDefs` in `orms.js` |
| DT toolbar | search + length + export trio in one 360px row; now stacked, each with its own surface |
| Wide tables | `.rbac-table` / `.about-roles-table` / `.setup-guide-table` / `.bs-table` / `.att-grid` are `width:100%`, so their scroll wrappers had nothing to scroll and the columns just crushed. Each now has a mobile `min-width` |
| `.modal-wide` / `.modal-lg` | `(0,2,0)` selectors outranking the mobile `.modal` rule → 80–90vw sheets floating in dark gutters on a phone |
| `.modal-split` | form + 310px sticky preview pane side by side on a 360px screen |
| A4 result card | 210mm squeezed to `max-width:100%`, crushing every mark column; now true-size + pan + pinch |
| Bottom-nav clearance | the flat `padding-bottom: 90px` outranked the safe-area rule by source order, so content hid behind the nav on notched iPhones |
| `mobile-menu.php` resize handler | cleared the scroll-lock styles without restoring `scrollTop` — rotating to landscape with the menu open threw the reader back to the top |

**Round 2 (same day) — the two surfaces that were still unusable rather than merely awkward:**

| Fixed | Was |
|---|---|
| Marks grid | roll **and** student both pinned = 230px of 360px frozen; now roll only, name 124px, avatar hidden, subject cols 148px. `enterkeyhint="next"` added to both mark-input builders so the phone keyboard offers Next instead of Go |
| SearchableDropdown | 320px panel anchored under its trigger, clipping behind the keyboard; now a fixed bottom sheet with grab handle, real backdrop, `visualViewport` keyboard lift and no autofocus |

**Swipe hints** use the existing `.table-scroll-hint` component (`display:none` desktop, `block` ≤768px). Added to `about.php` ×10, `backup.php`, `oauth_setup.php`, `smtp_setup.php`, and the two A4 hosts. `roles.php` already had one.

**Still static-only:** none of this has been opened in a real browser — same standing caveat as the rest of the project (see §12).

---

## 12. Pending / next

- **2026-08-13 UI batch shipped:** dashboard header 2.1 (sticky glass full-bleed bar, time-based greeting + date line, gradient name, waving 👋 with reduced-motion guard, profile-photo avatar chip, refresh-button spin) · rich student options in the my_results picker (Don't Undo #39) · notifications-dropdown stacking fix (#36) · compact sidebar (#37) · custom Subject Remark popup (#40). Verification still static-only (php -l + inline-JS parse), same standing caveat as below.
- **2026-08-13 dashboard header:** two rounds of custom-bar polish were SUPERSEDED the same day — the user chose the standard `.header` (see Don't Undo #38). Current state: dashboard opens with the same boxed header as my_results (`fa-chart-line` + "Dashboard" + crumb + bell). All custom header CSS/JS/PHP removed; assets `styles.css?v=11.7`.
- **2026-08-12 mobile pass shipped (2 rounds)** — see §11b. Assets bumped to `styles.css?v=11.3` · `orms.js?v=1.8`. **Wants a real device pass**, in this order: (1) the **dropdown bottom sheet** with the keyboard open on a real iPhone — `visualViewport` lift is the one piece that cannot be reasoned about statically; (2) the **marks grid** — is roll alone enough to keep your place across a sideways scroll, or does the name need to stay pinned after all; (3) the DataTables Responsive fold (does the right column set survive on students / users / results?); (4) the A4 card pan at 360px; (5) the stacked DT toolbar.
- **Known mobile gaps still open:** virtual keyboard occludes inputs focused in the lower half of a bottom-sheet **modal** (needs a `focusin` → `scrollIntoView` in `orms.js` — one handler covers every modal); `enterkeyhint` still absent outside the marks grid; Chart.js legends untested on narrow screens.
- **2026-07-31 batch shipped:** weighted year result (weightage finally wired) · per-student electives (`student_subjects` + enrolment UI + grid/save/completion gating) · QR card verification (+ public verify page) · Merit & Analytics tab in results.php (toppers / subject bests / teacher averages / YoY Chart.js line) · per-class `show_position`. **RE-RUN `update_setup.php`** before touching results on any existing DB.
- **2026-07-31 UI pass:** marks-grid AB/remark click fix (see Don't Undo #26) + zebra rows · `students.php` Student Details is now a 90vw full profile card (`.sprof-*`, six grouped panels, print button) · `teachers.php` Assign Subjects modal 90vw with the current load as a DataTable (was a chip card) and the Add/Edit Teacher modal at 80vw (`.modal-lg`) · chip columns wrapped in `.chip-wrap` for real vertical spacing · admin dashboard gained four analytics widgets: Term Performance Trend (line: avg % + pass % per published term), Subject Performance (horizontal bar, weakest first, colour-banded), Marks Entry by Section (progress bars, worst first) and Students Needing Attention (failed students of the latest published term). Reusable modal widths: `.modal-wide` = 90vw, `.modal-lg` = 80vw · Result Settings → Templates preview widened to a true 60/40 split (`.rs-split-wide`, 6fr/4fr; the pane scrolls internally because a sticky box taller than the viewport clips its own bottom) and gained a **Print Preview** popup that re-hosts the selected design in a real 210mm `.a4-sheet` (see Don't Undo #27).
- **`teachers.php` has no tabs** — the only Academics page still on a single flat list.
- **Credential slip print is broken** (`students.php`): the slip lives inside `#credModal`, and print CSS has `.modal-overlay { display: none !important }`, so printing yields a blank page. Fix with `ORMS.printOnly()` + a print-time un-hide.
- **Nothing has ever been run against a live MySQL** — WSL can't reach XAMPP's mysqld, so all verification to date is static (`php -l`, `node --check`, structural checks). Runtime testing in the browser is still outstanding.
- ~~Known template-layer gaps~~ — **all three closed 2026-09-01, see §13.**

---

## 13. 2026-09-01 — full verification pass + standards/security fix batch

Three parallel audits (standards · security & DB correctness · a 17-point results-maths invariant
check) followed by a fix batch. **Assets bumped to `styles.css?v=12.1` / `orms.js?v=2.1`** (42 + 29
refs). Every file `php -l` / `node --check` clean, byte-0 `<?php` intact, no trailing `?>`.

### The audits found the engine sound
**Zero SQL injection · zero `bind_param` mismatches** (107 raw + 389 helper calls tokenized,
including all four conditional branches of the `marks` and `result_summaries` binds) · correct
`password_hash`, login throttle (`success = 0` filter), OTP-by-email-then-compare,
`session_regenerate_id` on all four mint paths · **13 of 17 results invariants held exactly** —
entered predicate byte-identical at all 4 sites, elective clause only from `ormsElectiveSql()`,
grade lookup still "highest band ≤ pct", `mePersist()` still the sole marks write path,
`include_in_total` still ANDs both flags, republish still carries all four columns.
**Don't undo any of those — they were verified, not assumed.**

### 🚨 New don't-undo rules from this batch
1. **Root `.htaccess` now exists and must stay.** Without it `db-user-setup.sql` (which carries the
   DB password), `error_log.txt`, `MEMORY.md` and `requirement.md` are all
   fetchable over HTTP. It denies `*.md|*.sql|*.bat|*.sh|*.txt|*.log|*.ini|*.yml`, kills directory
   listing, and re-allows `offline.html` (the service-worker fallback would 403 otherwise). Both
   Apache 2.2 and 2.4 syntax are present — don't "clean up" the `mod_authz_core` branches.
2. **`sidebar.php` defines `window.ORMS_CSRF` for the whole app** (`window.ORMS_CSRF || '<token>'`,
   so a page that sets its own still wins). This is what finally allowed CSRF on
   `notifications_api.php` — all 28 bell-bearing pages include sidebar, verified.
3. **`push_subscribe.php` and `theme_save.php` CSRF is MANDATORY now.** They used to read
   `if ($csrf !== '' && !validateCSRFToken($csrf))` — *omitting* the field skipped the check
   entirely, which let a cross-site POST register an attacker's push endpoint under the victim.
   `push_register.js` and `notifications_bell.php` were taught to send the token. Never restore the
   `!== ''` short-circuit.
4. **`my_results.php` `myrResolve()` matched the student's LIVE section against `teacher_subjects`
   for ALL years — that leaked.** Sections are reused across years, so a teacher who held section B
   in 2023 got the 2023 card of a student promoted *into* B in 2024 (that student sat in A back
   then). It now derives one `(year, section)` pair per year from a UNION of
   `result_summaries` / `marks` / live `students` — `ormsResolveResultSection()`'s chain applied
   set-wise — and joins `teacher_subjects` on **both** section and year. Fixes the leak and the
   promoted-student drop-off together.
5. **`stuSectionInScope()` (students.php) gates every student PLACEMENT.** `stuSectionOk()`/
   `stuOwn()` only prove the class/section pair belongs to this school+branch — neither is a
   per-teacher check. Called from `stuForm()` (covers add + edit) and the promote target.
   `bulkImportStudents` resolves `$allowedSec` once before the row loop (O(1) isset per row, not a
   query per row) and skip-and-collects out-of-scope rows.
6. **`update_setup.php` applies DDL on a CSRF-signed POST only.** A bare GET used to run
   `applyUpdates()`, and SameSite=Lax still sends the cookie on a top-level navigation. GET now
   renders an "Apply Schema Updates" button. `setup.php`'s `require_once` is unaffected — the
   run-if-main guard returns first.
7. **Remember-me tokens rotate on every auto-login** (`validateRememberToken()`). A stolen cookie
   used to stay valid the full 30 days. The expiry is deliberately **not** extended on rotation —
   rotation must not turn 30 days into forever. Pre-migration try/catch for the ip/ua columns.
8. **`ormsRetireLegacyUserRole()` holds the only row-removing migration step**, extracted out of
   `applyUpdates()` so that function stays purely additive (rule 21). Don't inline it back.
9. **`#credModal` is `printable-modal` and `stuPrintCred()` uses `ORMS.printOnly('#credBody')`.**
   A bare `window.print()` printed a blank page — `styles.css` `@media print` hides
   `.modal-overlay`. Same pattern as `result_settings.php`'s template print.
10. **`notifications_bell.php` rows navigate from `data-href` + a delegated click handler**, not an
    inline `onclick` built by string-concatenating `n.link`. If you revert the handler the
    notifications stop navigating.
11. **`settings.php` dropped select2 entirely** (it was the only page using it, and pulled two extra
    CDNs) — `#currencyCode` / `#defaultLanguage` are `ORMS.dropdown` now, refreshed after the AJAX
    loads their saved values. `logs.php` and `smtp_setup.php` gained `orms.js` + `ORMS.dropdown`.
    **No plain `<select>` remains anywhere.**
12. **`result_engine.php` publish/unpublish no longer relay `$e->getMessage()`** — `results.php`
    passed it straight to the browser. Detail stays in the log.
13. **The withhold stamp balance is year-scoped.** `myrStamp()` takes a `$yearId` and
    `result_card.php` passes the term's year — an unscoped `ormsFeeBalance()` printed a *lifetime*
    figure next to a *year-scoped* reason.
14. **`meCtx()` now fetches `subjects.include_in_total`** and passes it as `meCfg()`'s third arg.
    Was latent only (`mePersist()` never read that `excl`), but it was a live trap.
15. **Toggle width guard added** for `.toggle-switch` inside `.form-group`/`.toggle-row`/
    `.rs-toggle-row` (60px lock — the component is 60px wide here, not 44px).
16. **Demo Data Quick-Add injector at the bottom of `sidebar.php`** — walks every `.modal-overlay`
    holding a form, adds an `fa-circle-info` button beside the close icon, fills set #N from
    `localStorage`. Skips hidden/file/readonly/disabled, rotates selects, refreshes ORMS dropdowns.
    **It is a testing aid — gate it on a role or delete the block before a client go-live.**
17. **Branding is now correct in all 58 code files** — `https://whatsapp.rameezscripts.com/`
    (was `wa.me/923224083545`, 95 refs) and `Developed by Mohammad Rameez Imdad (Rameez Scripts)`
    (47 short forms). `account.php` had no header at all. **"Let's Work Together" (WhatsApp ·
    Telegram · Email) now renders in the About developer card and the login footer** — it did not
    exist anywhere before.
18. **SweetAlert2 pinned to `@11.14.5`** in all 27 files (was the floating `@11` major tag).
19. `.initially-hidden` is **still** the last rule in `styles.css` (now line ~12309). Everything
    this batch added went above it.

### 2026-09-01 (later) — 🎉 FIRST EVER RUNTIME TEST. Three install-blocking bugs found and fixed.
The app now runs on local XAMPP and the whole pipeline was exercised over HTTP. Everything below
was found by *running* it — all three were invisible to static analysis.

1. **`schemaProbe($conn, $sql, [])` was fatal.** `str_repeat('s', 0)` = `''` and
   `bind_param('')` throws a ValueError. `update_setup.php:1203` (the roles PRIMARY KEY check)
   passes no params, so **every install died there** — after building all 40+ tables but *before*
   seeding roles, settings and the admin user. `schemaProbe()` and `installerAllowed()`'s `$cell`
   now skip `bind_param` when there are no params. Never reintroduce an unguarded `str_repeat`.
2. **Bootstrap deadlock in `installerAllowed()`.** That half-finished install had seeded the
   `schools` row, so the gate read "installed" and locked the installer — but there was no user
   account to sign in as. Fixed by returning bootstrap when `COUNT(*) FROM users` is 0: zero
   accounts means nothing to protect and nobody who could ever get in.
3. **🚨 Fresh installs put the admin on the wrong tenant.** Tenant tables get
   `school_id INT NOT NULL DEFAULT 1`, but `users.school_id` defaults to **0**, and applyUpdates'
   "assign existing accounts to school 1" backfill runs *before* `seedDemoData()` — so it matches
   nothing on a fresh install. The demo admin landed on school 0 and saw an **entirely empty app**:
   0 students, 0 terms, 0 of everything, with no error anywhere. Two fixes, keep both: the seeder
   now stamps `school_id = 1` on each user it creates, and `applyUpdates()` carries an idempotent
   repair sweep (`UPDATE users SET school_id = 1 WHERE school_id = 0 AND role <> 'Super Admin'`) —
   school 0 is platform-only by design.
4. `applyUpdates()` **rewrites `uploads/.htaccess` from a hardcoded `$rules` string on every run**,
   so editing that file by hand is pointless — it reverted the Apache 2.4 version within one run.
   The string itself now carries both 2.2 and 2.4 syntax. Edit `$rules`, never the file.

**Verified working against the live DB** (admin/admin123, teacher1/teacher123):
all 24 pages 200 with zero PHP notices · install + idempotent re-run · dashboard KPIs
(25 students, 86.7% pass) · **elective maths exact — 5-B expects 56 = 10×5 + 6 enrolled in the
optional Computer** · marks entry: 77→grade B, absent→`marks_obtained` NULL + `is_absent=1`→F,
**a cleared cell DELETES its row (confirmed, not nulled)**, 150/100 rejected and nothing written ·
tabulation 552/600 · result card in all 8 templates · **QR verify: real token → Student Two 92%
A+, forged token → "Not Verified"** · public lookup by roll AND by admission no, wrong DOB gives
the byte-identical failure line · CSRF now refuses a missing token on theme_save / push_subscribe /
notifications markRead / ui saveTheme / saveMarks and still passes with one · update_setup GET
shows a button, POST applies · **teacher scope guard proven: adding into a section they don't hold
is refused, their own section succeeds** (tested by temporarily granting `students:a`, then
revoking it — the probe student was deleted, demo data is back to 25).

⚠️ `config.php` now holds **LOCAL XAMPP credentials** (`root` / blank, db name unchanged).
Going live is a two-line swap back to `u247530633_orms` + the host password. The db NAME was
deliberately left identical so nothing else has to change.

### 2026-09-01 — filter audit across every list view + missing filters added
Audited the filter set on all 19 list pages against the data model. Six pages were genuinely
under-filtered; the rest were already complete (marks_entry, broadsheet, attendance, fees,
branches, result_settings, logs). **Every filter is `ORMS.dropdown`-upgraded — no plain `<select>`
remains anywhere in the project.**

| Page | Added | How it filters |
|---|---|---|
| `results.php` | **Class + Section** (cascading) | biggest gap — Year+Term only, so a real school meant 45+ section rows with no way to narrow. Rebuilt from the loaded rows, so it can never offer a section the user isn't scoped to |
| `users.php` | **Status** (Active/Inactive) | deactivated users were unfindable |
| `classes.php` | **Status** on *both* tabs | the Classes tab had no filter block at all |
| `subjects.php` | **Type · Counts in Total · Status** | the Subjects tab had no filter block at all |
| `teachers.php` | **Teaches Subject** | "who teaches Maths" was unanswerable |
| `sessions.php` | **Role + Device** | had zero filters |
| `students.php` | **Gender** | server-side, whitelisted against `stuGenders()` |

🚨 **Don't-undo rules from this batch:**
1. **Every client-side filter reads the row data, never the rendered markup.** `is_active`,
   `subject_type`, `role`, `os` all come off the source array by `dataIndex` — filtering on a
   status badge or a chip label breaks the moment the badge is re-skinned.
2. **`teachers.php` payload now carries `subject_ids`** (and the assignment SQL selects
   `ts.subject_id`). The chips are display strings like `"Class 5 – A · English"` — the filter must
   never scrape them.
3. **`results.php` payload now carries `class_id` / `class_name` / `section_name`.** The SQL always
   selected them; the row mapper just dropped them.
4. **`scoped()` in results.php is the one narrowing helper** — `filtered()`, `apprFiltered()`,
   `renderChev()`, `renderApprChev()` and renderAppr's empty test all run off it, so the chevron
   counts and the sign-off tab follow the Class/Section pickers. If a chip count ever disagrees
   with the visible rows, something bypassed `scoped()`.
5. `students.php` gender is **server-side** (it matches how that page filters everything else) and
   is whitelisted through `stuGenders()` — a bogus value is ignored, never interpolated. Verified:
   `gender=' OR 1=1 --` returns the normal 25 rows.

Verified live: 13 Male + 12 Female = 25 · teacher subject ids match the seed (Teacher One → [2,3]
Maths+Science, Teacher Two → [1] English) · all 23 pages 200 with zero PHP notices · 102 rendered
inline JS blocks parse clean · the header-actions hoist still picks up every toolbar.

### 2026-09-01 — 🟦 SQUARED restyle (no curves anywhere) + results.php Status column rebuilt

**The whole UI is squared. `border-radius` is 0 everywhere — 285 declarations in `styles.css`
plus 5 inline ones in the HTML email templates (`config.php`, `smtp_setup.php`). `--d-radius` is
0 too.** `styles.css?v=13.0` across all 42 refs — a restyle this broad needs the hard cache break.

🚨 **Don't re-round.** If a new component ships with a radius it will look foreign; add it with
`border-radius: 0` (or just omit it). There is **no functional exception** and none is needed:
every spinner in this app is a Font Awesome glyph (`fa-spinner fa-spin`), not a CSS ring, so
nothing depended on `border-radius: 50%`. Avatars, logos, the toggle knob, status dots, tour dots,
the processing bar and the hero blobs are all squares now — that is deliberate, not an oversight.

**`results.php` Status column** was a pile of `<br>`-separated chips that sorted alphabetically:
- **Sorts by pipeline rank now**, not the alphabet — `STATUS_RANK` + `statusRank(row)` give
  Not Started 10 · In Progress 20 · Complete 30 · Published 41. Alphabetical order used to put
  Complete before In Progress before Not Started, which meant nothing.
- **`type === 'filter'` returns exactly the text the cell displays.** It deliberately omits the
  approval label when the chip is not rendered — otherwise searching "draft" matched a *published*
  section whose Draft chip was never shown. Verified: "draft" no longer matches the published row,
  "01 sep" does.
- **Display is one `.st-stack` flex column** (`gap:4px`, left-aligned) instead of `<br>` soup, so
  the state / sign-off / fee / date chips finally line up. `.st-main` carries the icon
  `statusIcon()` pulls from `CHEVS`, so the cell and the chevron pipeline can never show different
  icons for the same state. Long unpublish reasons ellipsize via `.st-why` with the full text in
  the title.

Note left alone on purpose: the Completion bar paints `is-published` with `--navy-accent`. On the
stock Navy theme that is blue, but on a warm custom palette a 100% bar reads red. That is the
intended "accent = published" semantic, not a bug — change `.is-published .completion-fill` only
if the user asks.

### 2026-09-01 — Student 360 view (students.php View popup)

The View modal is now a **360° record**: a sticky identity rail on the left, everything else behind
tabs on the right. `styles.css?v=13.2`.

- **Left rail** (`.s360-side`): photo, name, admission no, class–section–roll, status + login +
  fee-hold badges, KPI tiles (Latest Result · Attendance · Fee Balance), and quick facts.
- **Right** (`.s360-main`): Profile · Results · Attendance · Fees · Subjects · Account.

🚨 **Don't-undo rules:**
1. **The modal tabs are `.s360-tab` / `.s360-pane`, NEVER `.tab-btn` / `.tab-pane`.**
   `ORMS.hoistActions()`/`syncActions()` decide which header toolbar to show by reading
   `.tab-pane.active` — a `.tab-pane` inside this modal would make the page header swap toolbars
   every time someone clicked a tab in here. The prefixes are load-bearing.
2. **Permission gating is per-block, inside `getStudent`.** Row scope (`stuScopeAnd`) already
   decided *which* student may be opened; `can('results'|'attendance'|'fees','v')` decides *what*
   of them this caller may read. A teacher who legitimately sees the child gets `fees => null` —
   the balance never leaves the server, and the Fees tab is not rendered at all. Verified live:
   teacher1 gets `can.fees = 0` and a null fees block; student 20 (another section) still 404s.
3. **One read, not six.** All 360 blocks ride on the existing `getStudent` action (its only caller
   was `stuView`). Don't split this into per-tab fetches — and keep the `get` prefix so orms.js
   `READ_RE` treats it as a read (thin top bar, not the write overlay).
4. Every block is wrapped in its own try/catch so a pre-migration DB degrades to an empty tab
   instead of failing the whole modal.
5. `ormsFeeBalance($id, $yid)` is called **year-scoped**, matching the withhold-stamp fix — never
   the zero-arg lifetime overload.
6. **Print shows the whole record.** `@media print` reveals every `.s360-pane` and hides the tab
   bar, so `ORMS.printOnly('#viewBody')` gives a complete profile rather than whichever tab
   happened to be open.
7. Tables use the house `about-table-wrapper` / `about-roles-table`, **not** DataTables — a
   DataTable inside a `display:none` pane measures 0 and renders with collapsed columns.

Verified live for Student Two: rail reads `STU-2026-0002 · Class 5 – A · Roll 2`, KPIs
`92.00% · A+ / — / $0.00`, results row `552.00/600.00 · 92% · A+ · GPA 3.90 · 1 of 15 · PASS`,
6 subject rows with the optional **Computer → Enrolled**, and proper empty states for attendance.

### 2026-09-01 — button system polish (`styles.css?v=13.3`)
One block at the bottom of styles.css, same specificity as the base `.btn` rules so **source order**
does the work and no per-variant colour is touched. Don't move it above them.
- `transition: all .3s` → targeted properties at .15s (the old one animated transform too, so
  toolbars wobbled). Hover lift `-2px` → `-1px` **plus a shadow**, so it reads as a lift instead of
  a jump. Added a real `:active` pressed state and a `:focus-visible` ring (there was none).
- **`min-height` 40px / 34px `.btn-sm`** so mixed-size toolbars stop looking ragged;
  `.header-actions .btn` is 36px to match `.header-tool-btn`.
- **Disabled no longer flattens to `#ccc` with white text** (unreadable, and it threw away the
  button's meaning). The `background:#ccc` was removed from the original `.btn:disabled` rule —
  it now dims via opacity + grayscale and keeps its variant colour. 🚨 Do **not** add
  `pointer-events:none` to disabled buttons: `results.php` puts the reason a Publish button is
  disabled in its `title`, and that tooltip dies without hover.
- **Neutral buttons on a dark bar get a glass treatment** (`rgba(255,255,255,.14)` + white border):
  `.modal-header .btn-secondary/.btn-primary` and the same in dark mode's header toolbar. `#6c757d`
  on a navy modal header was mud — that was the Print button in the report. success/danger stay
  solid on purpose; those colours carry meaning.
- Not a bug, for the record: action buttons look washed out **behind an open modal** because
  `.modal-overlay` is `rgba(0,0,0,.6)`. That is the backdrop, not the button styling.

⚠️ Testing note: **jsdom cannot resolve CSS `var()`** — a computed-style probe reports
`var(--navy-primary)` as transparent. Hardcoded hex resolves fine. Don't chase that as a bug.

### Environment notes (2026-09-01)
- The 2026-08-22 MariaDB corruption is **resolved** — grant tables were rebuilt (old set parked as
  `mysql_CORRUPT_2026-05-12/`), `mysql_error.log` shows clean starts.
- **From WSL, Apache is at `http://172.18.112.1/…`, NOT `localhost`.** WSL→Windows interop is dead
  (`UtilAcceptVsock: accept4 failed 110`), so no `.exe` can be launched from the shell; `curl` over
  the host IP works fine.
- ✅ **Runtime-tested at last** — see the section above. Pointing config.php at local `root` was
  the answer, since XAMPP's root has a blank password.

### 2026-09-02 — Chain of authority + branch profile/allocation + User 360 (`styles.css?v=14.1`)

**Runtime-tested end to end over curl as all three roles (admin / owner / appowner), then the test
tenant, branches and logins were purged — the box is back to 1 school, 1 branch, 42 users.**
⚠️ **RE-RUN `update_setup.php` after deploy** (adds the 6 branch profile columns + grants the App
Owner the Branches console).

**1. The chain of authority is now a real gate (config.php).** The matrix answers "which pages
open"; nothing answered "who may manage whom", so an **Admin could reset the School Owner's
password, delete the account, or "Login as" it** — three routes to the same takeover, all open.
- `ormsRoleLadder()` / `ormsRoleRank()` — App Owner 0 · School Owner 10 · Admin 20 · Principal 30 ·
  Branch Admin 40 · Teacher 50 · Student 60. 🚨 **The ladder is PINNED in code, never read from
  `roles.sort_order`** — that column is editable by an Admin in roles.php, so a rank taken from it
  would be re-orderable by the very role it constrains. A custom role parks at 25+ (below Admin).
- `ormsCanAssignRole()` — never grant a role above your own. **One exception, the hand-over:** when
  nobody in the school outranks you (`ormsTopRank()`), you are the top of the chain and may create
  the role above you, so an install with no Owner is never a dead end.
- `ormsOutranks()` — '' = allowed, else the refusal sentence. Your own row is always yours.
  Wired into users.php `updateUser`/`deleteUser`/`addUser` **and `impersonate.php`**.
- **Don't undo:** the impersonate guard. Without it "Login as" is a password reset with better
  optics — the audit log only shows an impersonation start.

**2. Two live bugs this surfaced, both fixed (users.php).**
- 🚨 **`is_super` was the wrong test for "assignable role".** `Admin` carries `is_super = 1` in the
  roles seed exactly like `Super Admin`, so `(int)$rk['is_super'] === 1` meant **no tenant, not even
  the School Owner, could ever appoint an Admin** — the role was missing from the dropdown, the role
  chips and the About matrix picker. The gate is now `in_array($newRole, ormsPlatformRoles(), true)`
  (the operator key alone); rank does the rest. Same fix in `$formRoles`.
- 🚨 **Ownership transfer created a SECOND owner.** Granting `School Owner` stamped
  `schools.owner_user_id` but never demoted the previous holder, and `ormsSchoolOwnerId()` then
  picked whichever id was lower — usually the wrong one. Granting the role is now a **transfer**:
  the outgoing owner drops to Admin in the same save, is notified, and their live `$_SESSION['role']`
  is corrected on the spot. The collapse runs on **every** owner save (`$old_role !== $newRole ||
  $newRole === 'School Owner'`), so a school that already has two heals itself on the next save.

**3. branches.php — profile fields + school allocation.**
- 6 new columns via `applyUpdates()`: `city`, `email`, `head_user_id`, `opened_on`, `capacity`,
  `notes`. Every read/write is column-probed (`brnCol`), so a db that hasn't migrated still renders
  and still saves the core fields. A dangling `head_user_id` is cleared on every migration run.
- **Capacity 0 = uncapped** and shows the headcount alone (never a division by zero); the Seats chip
  turns amber at 85% and red at 100%. It is a planning figure, **not** a limit — the plan quota is
  the limit and still applies on top.
- **School allocation, operator only.** `$superPerms['branches'] = $full` (campuses are
  provisioning, not academic data) — plus a one-shot explicit grant in update_setup, because the
  role merge only fills *missing* page keys and `branches` already existed as denied.
  `brnOwns()` resolves by id alone for the operator and every write re-reads the row's school
  (`brnSchoolOf()`) instead of trusting `sid()`, which is 0 for them.
- 🚨 **A campus only MOVES between schools while it is EMPTY, and never if it is that school's main
  branch.** Students, teachers, classes and logins each carry their own `school_id`; moving the
  branch alone would strand them in a tenant that cannot see them. A campus arriving in a school
  with no branches becomes that school's main one.
- Column indexes are looked up by title (`COL` map) because the operator gets an extra School
  column — the old hardcoded `colSearch(4, …)` would have filtered the wrong column.

**4. users.php — profile completion, allocation, and the 360 view.**
- Form gained **Full Name**, **Phone**, **School** (operator only) and **Branch**. Empty full name
  falls back to the username, never an empty display name.
- **Re-homing a login between schools is operator-only and refused when the account owns a
  student/teacher profile row** (that row carries its own `school_id`) or when it is an owner
  account. A move with no branch named lands on the new school's **main** branch — NULL would make
  the account invisible to every branch-fenced count until the next migration sweep.
- **`getUser360`** (stacked modal, mirrors the student 360): identity strip · KPIs (30-day logins,
  last login, live sessions) · account block · **Chain of Authority ladder with live headcounts and
  "this account" marked** · the role's real permission matrix · teacher/student profile · **the
  school card — for the Owner this IS their profile** (plan, renewal, branches/students/teachers vs
  plan caps) · last 10 activity rows. Plan + renewal are blanked for a viewer without `billing` view.
- Row buttons hide (not disable) edit/delete/Login-as for an account that outranks the viewer; the
  360 button is always available — reading is not managing.
- ⚠️ The ladder filter must key on `role_key === 'Super Admin'`, **not** `is_super` — same trap as
  above, it silently dropped Admin from the middle of the ladder.

**5. about.php** gained a **Chain of Authority** card (rank table + the three rules), a School Owner
row in Row-Level Scoping, and a `Chain of authority (who may manage whom)` formulas group covering
rank, both gates, the one-owner invariant, seat usage and re-allocation.

**6. setup.php — the demo install now shows all of it.** Schema needs nothing (setup `require`s
update_setup and reuses `applyUpdates()`, so a fresh install gets the branch columns and the App
Owner's Branches grant for free). What was added is the demo data:
- `$mkUser()` takes an optional **phone**; `$demoPhone` is ONE map read by both the seed calls and a
  backfill, so a number can never drift between "what a fresh install gets" and "what an old install
  is repaired to". Staff get 0300100000{1..7}; students deliberately get none — a student's number is
  the guardian's and lives on the student row.
- Because `$mkUser` short-circuits on an existing username, the phone would never reach an install
  that already had these logins — hence the backfill (`WHERE phone IS NULL OR phone = ''`, never
  overwrites a real number).
- **Branch profiles seeded**: the main campus is filled in (Demo City, head = the Principal,
  opened 2015-04-01, **500 seats**, notes) with `COALESCE(NULLIF(...))` per field so a school's own
  edits survive a re-run — plus a second, deliberately **EMPTY "City Campus" (CITY, 120 seats, head =
  the Branch Admin)**. The empty campus is the point: it is what makes the seat meter, the main-branch
  move, the operator's school re-allocation and the "a branch holding records cannot be deleted" rule
  all demonstrable without touching real data. `INSERT IGNORE` on uniq_branch_code = no-op on re-runs,
  and the branch sweep only re-homes NULL rows to the MAIN branch, so City Campus stays empty.
- Credentials panel: student range corrected to `…0035` (the seed has been 35 students since the ops
  build) and a line explaining the two campuses.
- ⚠️ Verified by re-running **Install** (idempotent) on the live demo db, not by a reset — the
  fresh-install `$mkUser` INSERT path itself is unexercised. `setup.php?action=reset` is the only way
  to prove that end and it wipes everything, so it was not run.

**7. branches.php — chip grouping (13 flat columns -> 6).** The profile columns made the table
overflow: the Branch cell wrapped to four lines and the Actions icons stacked vertically. Rebuilt
per `docs/chip-grouping.md`: **Branch** (name + address + Code/City/Opened chips) · **School**
(operator only) · **Contact** (Head/Role/Phone/Email) · **Status & Seats** (Main/Status/Seats/Used %)
· **People** (Students/Teachers/Classes/Logins, the count itself being the drill button) ·
**Actions** (one `.actions-cell` row). Builders live in orms.js as `ORMS.chip/crow/stack/box/DASH`
and the CSS is scoped to `table.dataTable.chip-table` — no other table changes.
- 🚨 **THE rule for every grouped column: chip html for `display`, the real value for `sort`, a text
  blob for `filter`.** Miss it and sorting sorts by markup while the search box quietly stops matching.
- 🚨 **`responsive: false`** — the chips carry the density and `.table-responsive` already scrolls
  sideways; `scrollX` + `responsive` together clone the header into a second table that drifts.
- Exports need `format: ORMS.asText` or every cell ships raw markup (it also decodes `&mdash;` etc).
- The Status and City dropdowns now filter **hidden single-value columns** — a grouped cell cannot
  offer the exact value they search on. Indexes still come from the `COL` title map.
- Rows tint red when a campus is Inactive or full, amber from 85% of its seats.

**8. Section tabs, everywhere one section IS the view (`ORMS.sectionTabs` in orms.js).** A class has
several sections and switching between them was buried in a dropdown among five other filters. ONE
shared helper paints the same choice as a tab row and keeps the two in step; `.bind()` wires a bar in
one line and, with no `onPick`, just fires the select's own change so a page needs no new logic.
Applied to **broadsheet** (All sections + per section, live headcounts from `getSections`),
**marks_entry**, **timetable** (`min:1` — a section must be picked before the grid exists) and
**attendance › Daily Register** (`min:1`).
- **broadsheet switching REFETCHES that section** rather than hiding rows: position is a rank inside
  the section and the averages/pass rate under the sheet belong to the selection, so a filter-only
  tab would leave whole-class statistics sitting under one section's marks.
- 🚨 **timetable.php and attendance.php run a generic `$('.tab-nav').on('click', '.tab-btn')` pane
  switcher.** A section tab wears the same class, so without a guard one click cleared every
  `.tab-pane`'s active class and blanked the page. Both now `return` when the button carries no
  `data-tab`, and only `[data-tab]` buttons lose `active`. **Any future .tab-btn that is not a pane
  tab needs this.**
- **results.php was deliberately left alone**: its rows already ARE sections, and it has a chevron
  pipeline for status — a section tab bar there would filter a list of sections with a second tab row.

**9. Demo data — all 35 students now carry a result (setup.php).** Was: only Class 5-A (15 students)
had First Term marks, so 20 students had nothing and Class 4 / 5-B demoed empty. Now every section is
marked, summarised and **published**: **206 marks** = 5-A 15x6 + 5-B (10x5 core + 6 enrolled in the
elective = 56) + 4-A 5x6 + 4-B 5x6, and **35 result summaries**.
- 5-A keeps its hand-tuned `$ability` curve untouched (the card/broadsheet demos were built on those
  exact numbers, including the single AB cell at STU-2026-0008 / Urdu). 5-B and Class 4 come off
  `35 + (n * 37) % 60` — deterministic, so a re-run reproduces the same marks.
- 🚨 **The optional subject is marked ONLY for students enrolled in it** (`student_subjects`), and
  `total_max` is `subjects actually taken x 100`, not a flat 600. The four opted-out 5-B students
  correctly show `/500`. Marking a non-enrolled student would invent a 6th subject on their card.
- Positions are ranked **inside each section**, ties share and the next rank skips.

**10. 🚨 Students could never log in — `validateUsername()` rejected their own username.** It allowed
`[a-zA-Z0-9_]` only, while a student's login IS their admission number (`STU-2026-0031`), so every
student account this app has ever created was refused at the login form with "Invalid username
format" — the credentials the installer prints could not be used. The pattern now allows `.` and `-`
(all four message strings updated). Verified: 3 student logins -> 302 -> My Results renders a
published card. Nothing else changes: every query was already prepared.

CSS appended: `.seat-chip`/`.seat-ok|tight|full`, `.u360-ladder`/`.u360-rung`, `.perm-yes|no`,
the whole `.chip-table` block and `.tab-btn .tab-count`.
Cache-buster **styles.css v=14.2 · orms.js v=2.3 across every ref**.

### 2026-09-02 (later) — gateways console, uploads, auto-expiry, chip sweep, 41 palettes

**`styles.css?v=14.5` · `orms.js?v=2.4`.** All curl-tested as admin / owner / appowner; every page
200 with zero notices, error_log clean.

**11. gateways.php reads like Site Settings now.** Live status strip (checkout on/off · mode ·
currency · how many methods a school can actually pick) → one `settings-mega-card` per gateway with
its own enable switch and a **pill that answers "would a school see this right now"** computed from
the LIVE form (Disabled / Needs keys / Checkout is off / Test mode / Live) → sticky save bar that
only appears once the form differs from what is saved, with Discard.
- Secrets: the masked value means "saved, unchanged" (the server contract was already that). A
  **Replace** button blanks the field to type a new one, a key chip reads saved/new/not set, and
  **saving a field that was saved and is now blank asks first** — that is a DELETE, not an edit.
- Webhook URL got a one-click Copy (clipboard API + execCommand fallback for http origins).
- Spacing pass on every text block (`.page-gateways` scoped): card padding, label/help-text margins,
  1.6 line-height, the hint under the webhook now reads as a note block.

**12. Logo URL boxes are real uploads.** `ormsSaveImageUpload()` + `ormsDropUpload()` in config.php
are now THE image uploader (size, real mime via finfo, getimagesize proof, server-invented filename,
`uploads/` fenced delete). schools.php's "Logo URL" text box became a file picker with preview and
Remove, reusing settings.php's existing `.logo-upload-row` markup so it looks like the site logo
upload it sits next to. The stored value is still just the path, so saveSchool/editSchool are
untouched. Replacing a logo drops the file it replaced, but only when this school is the row holding
it (or nobody is). settings.php / result_settings.php / students / teachers / account already
uploaded — schools.php was the last URL box.

**13. Automatic expiry (`ormsAutoExpire*` in config.php).** Nothing ever WROTE an expiry:
`ormsSubscriptionState()` compares dates at read time, so a lapsed school was blocked correctly while
its subscription row still said "Active" and a pending invoice only expired if somebody opened the
Invoices tab. One idempotent sweep now closes out (a) unpaid invoices past a configurable window,
(b) periods past `ends_at`, (c) optionally suspends a school N days past its last paid period.
- **Suspend defaults to 0 = never.** No live install starts switching schools off after a deploy.
- 🚨 **`ormsAutoExpireTick()` runs at most once an hour, on a signed-in request, and only while
  `platform_mode = 1`** — same gate as `ormsSubscriptionBlocked()`, or a single-school install would
  start expiring things it does not bill for. It sits BEFORE `ormsEnforceSubscription()` in config's
  tail so the gate judges today's state.
- Every leg logs its own failure (`error_log`) instead of swallowing it — a counting bug that returns
  0 silently is worse than one that throws. Verified by running all three legs against the live
  schema with `suspend_days = 45`: clean, 0 rows matched, nothing logged.
- UI: an "Automatic expiry" card on gateways.php with a **Run now** that calls the same function the
  schedule does, and asks first when suspension is armed.

**14. Chip grouping rolled out (branches → students → teachers → users).** All four now use the
shared `ORMS.chip/crow/stack/box/asText` builders and `table.dataTable.chip-table`:
| Page | was | now |
|---|---|---|
| branches | 13 | Branch · School* · Contact · Status & Seats · People · Actions |
| students | 8 | Student · Class · Guardian · Status · Actions |
| teachers | 12 (5 hidden in the responsive expand row) | Teacher · Contact · Employment · Teaching Load · Status · Actions |
| users | 10 | User · Access · Where · Activity · Actions |
Same three rules every time: **display = chips, sort = the real value, filter = a text blob**;
`responsive: false`; exports carry `format: ORMS.asText`; and any dropdown that filters on an exact
value (Status, City, Role) gets a **hidden single-value column**, because a grouped cell has none.
Dead helpers removed with the columns they served (`stuStatusClass`, `hrCell`, `badge`, `drillChip`).
**Still flat, on purpose** (`docs/chip-grouping.md` says keep flat when every value stands alone):
broadsheet (a subject matrix), logs / sessions / the callback log (chronological audit streams),
the attendance daily register (a segmented control per row), marks entry. **Not yet converted:**
fees, subjects, classes, results and the gateways invoice table.

**14b. The operator's three tables joined the chip set (schools · plans · subscriptions).**
| Page | was | now |
|---|---|---|
| schools | 9 | School · Subscription · Size · Actions **+ hidden Status, Plan** |
| plans | 11 | Plan · Pricing · Limits · Features · Actions |
| subscriptions | 7 | School · Period · Money · Actions |
- schools.php gained the same `COL` title map as branches: its Status and Plan dropdowns used to be
  `colSearch(2, …)` / `colSearch(3, …)`, and after grouping those indexes point at different columns —
  they now read `COL.status` / `COL.plan`, which resolve to the **hidden single-value columns**.
- subscriptions.php needed no hidden column: its chip pipeline and plan filter already read the ROW
  data (`settings.aoData[idx]._aData`), never a column, so grouping could not disturb them.
- plans.php keeps **Features as its own column** — a list of feature strings is one value, and the
  doc's rule is "don't chip a lone value".
- `order: []` everywhere: all three servers already sort (by id, sort_order, and the subs list order),
  and the old `order: [[0,'asc']]` pointed at a column that no longer exists.

**15. Every palette in the registry ships (15 → 41).** `$UI_PALETTES` was **generated** from
`~/docs/colors.md` rather than retyped — a palette is only as good as its hexes — and carries a `g`
group so ui.php renders the registry's own five sections plus Default. The gallery gained a search
box that matches name, id, section **and hex** (`#635BFF` finds Fintech Blurple) and an empty
section heading hides itself. The header quick-switcher is capped at **12** (`array_slice` in
notifications_bell.php): a dropdown is a quick pick, and "More theme options" opens the full set.
⚠️ The ask was 50; the registry defines 41 and CLAUDE.md forbids inventing hexes — to reach 50, add
9 rows to `docs/colors.md` and regenerate.

**16. Sidebar dropdown groups (`styles.css?v=14.6`).** My Account and System are collapsible groups
now, per `docs/css-styles.md` § "Sidebar Dropdown Groups": parent row + a right-side `fa-plus` that
rotates 45° into an `×`, submenu slides on `max-height`, state in `localStorage.sbGroups`. Daily-use
sections (Navigation, Academics, Results…) stay flat labels — that is the rule, not an oversight.
- `$renderNavItem` was split: **`$navItem($key)` RETURNS the `<li>`** so a group can nest it, and
  `$renderNavItem` just echoes it. `$sbGroup($key,$icon,$label,$links,$pages)` builds the group and
  **returns '' when `$links` is empty**, so a group whose children are all permission-denied never
  renders at all (a Student sees My Account only — no empty System row).
- 🚨 **The group holding the current page renders `open` server-side AND the restore script skips
  any group containing `.sb-sub a.active`** — saved state may never close the page you are standing
  on. Verified: account/ui/about open My Account, users/logs/settings open System.
- Collapsed 70px rail hides the group headers and forces the submenus open, so every link is still
  reachable as an icon.

### 2026-09-02 (4th batch) — operator dashboard · colourful pipeline · multi-currency

**`styles.css?v=14.8`.** ⚠️ **RE-RUN `update_setup.php`** (plan_prices table, schools.billing_currency,
and the platform-settings copy-up below).

**17. The App Owner finally has a dashboard (`$view = 'platform'`).** Every query on dashboard.php is
school-scoped and the operator holds no school, so it fell through to the `basic` fallback — an empty
page for the one person who needs to see everything. New `platformStats` action (platform-gated,
refused for a tenant) returns the whole picture in ONE round trip: tenants (schools by status,
students, teachers, logins, new-in-30-days) · money (collected this month, lifetime, outstanding) ·
subscription watch (healthy / expiring ≤30d / expired / never billed) with a **needs-attention table
sorted worst-first** · the **platform switches** as a Setup-Health-style list (SaaS mode, checkout,
live-vs-test, a payment method ready, auto-expiry, SMTP, maintenance, push keys — each with a "fix
it here" link) · and the box itself (PHP, db + size, uploads, error log, last backup, last expiry
sweep, logins and failed sign-ins in 24h). Every block is best-effort: a missing table gives zeros.
The per-school pass is ONE query with correlated counts — a query per school would be N+1 on the
page the operator opens most.

**18. 🚨 Platform settings written before the school-0 split were unreachable.** `billing_*` and
`gw_*` are read through `ormsPlatformSetting()` (school 0, FLAT), but an install that configured
billing earlier stored them on **school 1**, where nothing reads them any more — the console showed
"not configured" over live values and `bilCurrency()` fell back to its default. update_setup's
existing smtp/vapid/oauth copy-up now also carries `billing\_%`, `gw\_%` and `platform_mode`. Same
class of bug as the `platform_mode` shadowing already recorded above; don't undo either.

**19. Chevron pipeline is colour-coded per stage.** It was a row of identical grey arrows, so finding
"Failed" or "Expiring" meant reading every label. Each position now carries its own hue
(`:nth-child(7n+k)` → a CSS variable pair), the active stage goes solid in that same hue, and the
count rides in a contrasting pill. **Position-based on purpose:** results, subscriptions and users
each build their stages differently and none of them had to change a line.

**20. Multi-currency billing.** The platform billed every school in one hard-coded currency.
- **`plan_prices` (plan_id, currency, monthly, yearly)** — a plan's own columns stay its price in the
  platform currency; a row here is its price in one other. 🚨 **Prices are CHOSEN, never converted:
  no FX rate is applied anywhere**, because a rate that moves overnight silently changes what a
  customer owes. A currency with no row simply has no price.
- **`schools.billing_currency`** (NULL = the platform default, so no existing school is re-priced).
- Two resolvers, and everything goes through them: **`bilCurrencyFor($school)`** (school override →
  platform default) and **`bilPriceIn($plan, $cycle, $ccy)`**. `bilCreateInvoice()` now raises the
  invoice in the SCHOOL's currency and refuses by name when the plan has no price in it.
- plans.php gained a repeating currency-row editor (validated in full before a single row is written:
  unknown code, the base currency as an "extra", and duplicates are each their own sentence) and
  shows every extra price as a chip. schools.php gained the per-school picker.
- billing.php prices its plan cards in the school's currency and flags the ones with no price.
- Untested end: the checkout refusal itself needs `platform_mode = 1`, which this box does not run —
  the price RESOLUTION was verified live (school → USD showed Standard 25/250 and zeros elsewhere).

**21. The Platform Status "Open" buttons went nowhere — three causes, all fixed (`v=14.9`).**
⚠️ **RE-RUN `update_setup.php`.**
- 🚨 **The operator held no `settings` / `smtp_setup` / `oauth_setup` permission**, so five of the
  eight "fix it here" links redirected straight back to the dashboard: click, nothing happens. Those
  pages own SMTP, OAuth, the push keys, maintenance mode and the SaaS switch — every one of which
  this file already copies UP to the platform row precisely because they are the operator's, not a
  school's. `$superPerms` now grants all three, plus a one-shot repair for existing installs (the
  role merge only fills MISSING page keys, so an already-denied bit has to be flipped explicitly).
  Tenants are unaffected: an Admin still gets 302 on gateways/schools and keeps its own settings.php.
- 🚨 **`platform_mode` had no control anywhere.** The installer literally says "switch platform_mode
  on in Site Settings" and no such toggle existed — the master SaaS switch could only be flipped in
  the database. settings.php now has an operator-only **SaaS Mode** card, written with
  **`ormsPlatformSet()`, never `setSetting()`** (a school-scoped copy is the documented shadowing bug
  that once left plan limits and the expiry gate silently inert). Round-tripped live: on → the
  dashboard flag follows → off.
- **A link the viewer cannot follow is no longer rendered as a button.** `platformStats` now sends the
  page key with each flag and the row shows the button only when `can(key,'v')`; otherwise it names
  the page behind a lock icon. A dead "Open" can never come back by adding a flag.

### Left alone deliberately
`result_summaries.promoted_status` is a dead column — the republish carry-over is correct, but
`promoteStudents` only updates `students`, so it never leaves `'Pending'`. Wiring it is a feature
and dropping it is destructive, so it was flagged, not changed.

### 2026-09-03 — ONE global currency: the App Owner's pick now reaches every role and every page

> ⚠️ **RE-RUN `update_setup.php`** after deploying. The PHP fix works without it (the resolver stops
> reading school rows), but the re-run clears the dead shadow rows, reconciles the old billing knob
> and re-points the three money-table column defaults.

**The bug.** Owner changes Currency in Settings → nothing changes for Admin / Principal / Teacher /
Student, and Plans / Billing / Gateways stay on their own currency. Three causes stacked:

1. `seedSettings()` inserts with **no `school_id`**, and the column defaults to **1** — so the seeded
   `currency_code` / `currency_symbol` physically landed on **tenant #1**.
2. `ormsSettingCache()` resolves `school_id IN (0, ?) ORDER BY school_id ASC`, so that school-1 row
   **shadowed** the platform row the owner writes. Confirmed live: school 0 *and* school 1 both held
   `currency_code`, and school 1's copy won for every school-1 user.
3. `settings.php` wrote currency with `setSetting()` on **every** save, so any school admin re-pinned
   their own school's currency permanently.

Plus: `billing_currency` was a **second independent knob** (seeds disagreed out of the box — `USD` vs
`PKR`), and `ormsMoney()` cached the symbol in a `static` for the whole request.

**The rule now — don't undo.** ONE currency for the whole install, owned by the App Owner.
`system_settings(school_id = 0, currency_code)` is the single source. `ormsCurrency()` reads it
**FLAT** off the platform row (like `platform_mode` and the gateway keys) so no school row can ever
shadow it, and `bilCurrency()` / `bilCurrencyFor()` both return it — the school side and the billing
side cannot drift apart. **Schools may not override it.** Prices are still **relabelled, never
converted** — no FX rate anywhere. Rows already written (invoices, payments, periods) keep their own
stored currency; `ormsMoney($v, $code)` prints those.

- `config.php` — `ormsCurrency()` platform-flat with a school-1 flat fallback for the not-yet-re-run
  window; `ormsMoney(float $v, ?string $code = null)`, `static $sym` **deleted** (it was wrong under
  impersonation). All ~40 one-arg call sites unchanged.
- `billing_engine.php` — `bilCurrency()` → `ormsCurrency()['code']`; `bilCurrencyFor()` returns it for
  every school and no longer reads `schools.billing_currency`.
- `settings.php` — currency written with **`ormsPlatformSet()`, never `setSetting()`**, inside the
  `$isPlat` block; tenants see a **readonly** locked field. 🚨 Both JS reads go through
  `currencyValue()` — the raw `getElementById('currencyCode').value` would `TypeError` and kill Save
  for every school admin once the field is hidden.
- `gateways.php` / `schools.php` — the two rival currency controls removed. 🚨 `schools.php` bind
  strings went `'ssssssisssi'`→`'ssssssissi'` and `'sssssssisss'`→`'sssssssiss'`; dropping the form
  field alone would have written `NULL` over every school on edit.
- `subscriptions.php` — new periods/payments stamped with the platform currency, picker retired,
  `subMoney()` collapsed onto `ormsMoney($v, $ccy)`. Stored rows untouched.
- `plans.php` — 🚨 `plnSavePrices()` now guarded by `array_key_exists('pc_currency', $_POST)`; it
  `DELETE`s then re-inserts, so a page that stops rendering the editor would silently wipe the book.
- `register_school.php` — trial period names its currency (the column default was minting `₨` rows).
- `dashboard.php` — both `'PKR'` fallbacks gone; revenue chart Y-axis now names the currency (it
  disagreed with its own tooltip).
- `update_setup.php` §6c — picks the value the install meant (human edit > platform row > lowest
  tenant, via `updated_by`), reconciles the old `billing_currency` (money that changed hands wins),
  **DELETEs every school-scoped currency row**, pins all three to school 0 with `ON DUPLICATE`
  (not `INSERT IGNORE` — an existing school-0 row must be *corrected*), and re-points the money-table
  defaults. The three keys were **removed from the seed array** — leaving them re-creates the shadow.

**Verified against the live DB:** owner sets EUR → schools 0, 1, 2, 3 all resolve `EUR €` (schools 2
and 3 had `CDF`/`CHF` overrides), `bilCurrencyFor()` follows, and `ormsMoney(1500,'PKR')` still prints
`₨1,500.00`. No assets changed, so **no cache-buster bump** (`styles.css?v=15.2`, `orms.js?v=2.4`).

**Still open (same defect class, different keys):** `site_name`, `platform_name`, `platform_whatsapp`
and `allow_school_signup` are also seeded to school 1, read with `getSetting()`, and missing from the
school-1→school-0 copy-up. That is why the login page title reads the hardcoded *"Dashboard System"*
instead of the seeded site name. Also deferred: five money KPIs still `SUM()` across currencies with
no `GROUP BY` (`dashboard.php:1066`/`:1122`/`:287`, `gateways.php:158`, `subscriptions.php:138`) —
only reachable once a platform switches currency mid-life.

Housekeeping: the three `.bat` files and `saas-requirements.md` were deleted at the owner's request.

---

## 15. CSV Templates & Import in Spanish (2026-09-24)

- **Excel in Spanish Compatibility:** `ORMS.downloadCSV` now defaults to semicolon (`;`) with UTF-8 BOM (`\ufeff`), allowing Windows Excel in Spanish locales to open CSV files directly in separate columns (A, B, C...) without cramming the entire row into cell A1.
- **Intelligent Delimiter Auto-Detection:** `ORMS.parseCSV` auto-detects delimiter (`;` or `,`) and respects optional `sep=;` / `sep=,` directives. Files saved from Excel (which uses `;` in Spanish locales) or standard comma-separated files are parsed seamlessly.
- **Bilingual Header Mapping:** Teachers, Students, Classes, and Fees templates are in Spanish with realistic Spanish sample rows. The client-side header validation cleanly accepts both Spanish and legacy English headers.
- **Flexible Backend Normalization:** Date parsers (`tchDate`, `stuNormDate`, `feeNormDate`) accept `YYYY-MM-DD`, `DD/MM/YYYY`, and `DD-MM-YYYY` and normalize to `YYYY-MM-DD`. Gender parser in students accepts `Masculino`, `Femenino`, `Otro`, `M`, `F`. Fee type accepts `Cargo`, `Pago`, `Cobro`, `Abono`.
- **Cache-Buster:** Bumped to **`orms.js?v=2.5`** across all 38 PHP pages.

---

_Built by Rameez Scripts._
