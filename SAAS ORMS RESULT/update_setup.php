<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

require_once 'config.php';

/*
 | Single schema source for the whole app. Additive + idempotent — run it on a live
 | DB after any deploy, it only adds what's missing and never drops or overwrites.
 | setup.php requires this file and reuses applyUpdates() (install + reset).
 |
 | Also owns the installer access gate (installerAllowed/installerLock) so setup.php
 | and this page share ONE implementation.
 */

// signed-in admin?
/**
 * Who counts as "the installer".
 *
 * 🚨 It is NOT `role === 'Admin'` any more. In a shared database every school has an Admin, and
 * setup.php?action=reset drops every table — one tenant could wipe the platform.
 *
 * The transitional clause is what avoids a deadlock: an existing single-school install has no
 * Super Admin yet, so its owner must still be able to run the updater that creates one. It dies
 * the moment platform_mode flips to 1.
 */
function installerPlatformAdmin(): bool {
    if (empty($_SESSION['user_id']) || !empty($_SESSION['impersonator_id'])) return false;   // never while impersonating
    if (($_SESSION['role'] ?? '') === 'Super Admin' && (int)($_SESSION['school_id'] ?? -1) === 0) return true;
    // transitional: pre-SaaS install. 'School Owner' is here on purpose — the owner promotion below
    // moves a single-school install's ONLY admin onto that role, and leaving it out would lock the
    // install out of its own updater with nothing able to put it back.
    return in_array($_SESSION['role'] ?? '', ['Admin', 'School Owner'], true) && !installerPlatformMode();
}

// read platform_mode without config.php's helpers — this file runs before/without a session context
function installerPlatformMode(): bool {
    try {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($c->connect_error) return false;
        $r = @$c->query("SELECT setting_value FROM system_settings WHERE school_id = 0 AND setting_key = 'platform_mode' LIMIT 1");
        $v = ($r && ($row = $r->fetch_row())) ? (string)$row[0] : '0';
        $c->close();
        return $v === '1';
    } catch (Throwable $e) { return false; }   // pre-migration -> not a saas yet
}

// the installer identity — platform operator, not "whoever holds the string 'Admin'"
function installerAdmin(): bool {
    return installerPlatformAdmin();
}

/**
 * [allowed, why]. Bootstrap = nothing to protect yet: db absent, users table absent,
 * or zero Admin rows. Once an Admin exists only a signed-in Admin gets in.
 * Probes on its own connection — getDBConnection() die()s when the schema isn't there.
 */
function installerAllowed(): array {
    if (installerAdmin()) return [true, 'admin'];

    $db = str_replace('`', '', DB_NAME);
    try {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($c->connect_error) return [true, 'bootstrap']; // no server -> nothing installed

        $cell = static function (string $sql, array $a) use ($c) {
            $st = $c->prepare($sql);
            if (!$st) return null;
            if ($a) $st->bind_param(str_repeat('s', count($a)), ...$a);   // empty types = fatal
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
            return $row ? $row[0] : null;
        };

        // db or users table missing -> genuine first run
        if ($cell("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' LIMIT 1", [$db]) === null) {
            $c->close();
            return [true, 'bootstrap'];
        }
        // zero accounts = nothing to protect and nobody who could ever sign in. without this a
        // half-finished install deadlocks: it seeds the tenant row (so the checks below read
        // "installed") but dies before the admin user, leaving the installer permanently locked.
        if ((int)$cell("SELECT COUNT(*) FROM `$db`.`users`", []) === 0) {
            $c->close();
            return [true, 'bootstrap'];
        }
        // pre-tenant db -> the legacy rule still governs, so an un-migrated install can still migrate
        if ($cell("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'schools' LIMIT 1", [$db]) === null) {
            $admins = (int)$cell("SELECT COUNT(*) FROM `$db`.`users` WHERE role = ?", ['Admin']);
            $c->close();
            return $admins ? [false, 'installed'] : [true, 'bootstrap'];
        }
        // tenant db: bootstrap ONLY while there is genuinely nothing to protect. an Admin row no
        // longer means "installed" — every school legitimately has one.
        $supers  = (int)$cell("SELECT COUNT(*) FROM `$db`.`users` WHERE school_id = 0 AND role = ?", ['Super Admin']);
        $schools = (int)$cell("SELECT COUNT(*) FROM `$db`.`schools` WHERE id > ?", ['0']);
        $c->close();
        return ($supers === 0 && $schools === 0) ? [true, 'bootstrap'] : [false, 'installed'];
    } catch (Throwable $e) {
        return [true, 'bootstrap']; // unreachable / half-built schema -> treat as first run
    }
}

// hard stop for non-admins. exits BEFORE any db work and never names the database
function installerLock(string $heading = 'Already Installed', string $line = 'This system is already set up — the installer is restricted to signed-in administrators.'): void {
    http_response_code(403);
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>$h</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <meta name="theme-color" content="#001f3f">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-lock"></i> $h</h2>
        <p class="subtitle">$l</p>
        <hr>
        <div class="warning-message">
            <i class="fas fa-triangle-exclamation"></i> <strong>Access denied.</strong>
            Sign in as an administrator to run the installer or the database updater.
        </div>
        <a href="login.php" class="btn mt-20"><i class="fas fa-sign-in-alt"></i> Go to Login Page</a>
    </div>
    </div>
    <script>
    if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.body.classList.add('dark-mode');
    }
    </script>
</body>
</html>
HTML;
    exit;
}

// prepared information_schema probe -> first cell or null
function schemaProbe(mysqli $c, string $sql, array $params): ?string {
    $stmt = $c->prepare($sql);
    if (!$stmt) return null;
    // a param-less probe (DATABASE() only) must SKIP bind_param — an empty type string is a
    // fatal ValueError, and it aborted the whole installer at the roles primary-key check
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string)$row[0] : null;
}

// $ddl = column list ONLY; engine/charset appended here so every table matches
function createTable(mysqli $c, string $name, string $ddl, ?callable $log = null): bool {
    $existed = schemaProbe($c, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1", [$name]) !== null;
    if ($c->query("CREATE TABLE IF NOT EXISTS `$name` (\n$ddl\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
        $log && $log($existed ? 'info' : 'success', 'Table "' . $name . '" ' . ($existed ? 'already exists' : 'created'));
        return true;
    }
    $log && $log('error', 'Table "' . $name . '" failed: ' . $c->error);
    return false;
}

// add col only when absent
function addColumnIfMissing(mysqli $c, string $table, string $col, string $definition, ?callable $log = null): bool {
    if (schemaProbe($c, "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1", [$table, $col]) !== null) return false;
    if ($c->query("ALTER TABLE `$table` ADD COLUMN `$col` $definition")) {
        $log && $log('success', 'Column "' . $table . '.' . $col . '" added');
        return true;
    }
    $log && $log('error', 'Column "' . $table . '.' . $col . '" failed: ' . $c->error);
    return false;
}

// convert only while the col still carries the OLD type -> re-running is a no-op. errors logged, caller words the success line
function modifyColumnIfType(mysqli $c, string $table, string $col, string $expectType, string $newDefinition, ?callable $log = null): bool {
    $type = schemaProbe($c, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1", [$table, $col]);
    if ($type === null || stripos($type, $expectType) !== 0) return false; // missing or already converted
    if ($c->query("ALTER TABLE `$table` MODIFY `$col` $newDefinition")) return true;
    $log && $log('error', 'Could not modify ' . $table . '.' . $col . ': ' . $c->error);
    return false;
}

// insert-only settings -> admin edits are never clobbered
function seedSettings(mysqli $c, array $kv, ?callable $log = null): void {
    $stmt = $c->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    if (!$stmt) { $log && $log('error', 'Settings seed failed: ' . $c->error); return; }
    $k = $v = '';
    $stmt->bind_param("ss", $k, $v);
    $added = 0;
    foreach ($kv as $key => $val) {
        $k = (string)$key;
        $v = (string)$val;
        $stmt->execute();
        $added += $stmt->affected_rows > 0 ? 1 : 0;
    }
    $stmt->close();
    $log && $log($added ? 'success' : 'info', $added ? $added . ' setting(s) seeded (' . count($kv) . ' checked)' : count($kv) . ' setting(s) already present');
}

function indexExists(mysqli $c, string $table, string $index): bool {
    return schemaProbe($c, "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1", [$table, $index]) !== null;
}

/**
 * THE schema entry point. Never echoes — all output goes through $log('success'|'info'|'error', 'msg').
 */
// one-shot legacy cleanup, deliberately NOT inline in applyUpdates(): that function is the
// additive schema source and must stay free of DELETE. this migrates users off the template's
// 'User' role and then drops the now-unreferenced row. idempotent — a second run matches nothing.
function ormsRetireLegacyUserRole(mysqli $conn, callable $say): void {
    $conn->query("UPDATE users SET role = 'Student' WHERE role = 'User'");
    if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' user(s) migrated from role "User" to "Student"');
    if ($conn->query("DELETE FROM roles WHERE role_key = 'User'") && $conn->affected_rows > 0) {
        $say('success', 'Legacy role "User" removed');
    }
}

function applyUpdates(mysqli $conn, ?callable $log = null): void {
    $say = static function (string $t, string $m) use ($log): void { if ($log) $log($t, $m); };

    // ---------------------------------------------------------------- 1. template tables
    $say('info', 'Core tables');

    $core = [
        'users' => <<<'SQL'
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) NOT NULL,
            role ENUM('Admin', 'User') DEFAULT 'User',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_role (role)
        SQL,
        'activity_logs' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_username (username),
            INDEX idx_action (action),
            INDEX idx_timestamp (timestamp)
        SQL,
        'system_settings' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key)
        SQL,
        'email_verifications' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_email (email)
        SQL,
        'password_resets' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) DEFAULT 0,
            INDEX idx_email (email)
        SQL,
        'remember_tokens' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_token (token_hash)
        SQL,
        'login_attempts' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_attempt_time (attempt_time)
        SQL,
        'user_sessions' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id VARCHAR(128) NOT NULL UNIQUE,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            force_logout TINYINT(1) DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id)
        SQL,
        'notifications' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info','success','warning','danger') DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            link VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_user_read (user_id, is_read)
        SQL,
        'push_subscriptions' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_endpoint (endpoint(191)),
            INDEX idx_user (user_id)
        SQL,
        'roles' => <<<'SQL'
            role_key VARCHAR(50) PRIMARY KEY,
            label VARCHAR(100) NOT NULL,
            color VARCHAR(10) DEFAULT '#0074D9',
            sort_order INT(11) DEFAULT 0,
            is_super TINYINT(1) DEFAULT 0,
            hidden_signup TINYINT(1) DEFAULT 0,
            permissions LONGTEXT NOT NULL
        SQL,
    ];
    foreach ($core as $t => $ddl) createTable($conn, $t, $ddl, $log);

    // ---------------------------------------------------------------- 2. blocker fix: role ENUM -> VARCHAR
    // ENUM('Admin','User') silently coerces Teacher/Student to '' — must run before ANY seeding
    $conn->query("UPDATE users SET role = 'User' WHERE role IS NULL OR role = ''"); // no NULLs before NOT NULL
    $conv = modifyColumnIfType($conn, 'users', 'role', 'enum', "VARCHAR(50) NOT NULL DEFAULT 'Student'", $log);
    $say($conv ? 'success' : 'info', $conv
        ? 'users.role converted ENUM → VARCHAR(50) — Teacher/Student are now assignable'
        : 'users.role already VARCHAR(50) — no conversion needed');

    if (!indexExists($conn, 'users', 'idx_role') && $conn->query("ALTER TABLE users ADD INDEX idx_role (role)")) {
        $say('success', 'Index users.idx_role restored');
    }

    // ---------------------------------------------------------------- 3. users columns
    $say('info', 'User profile columns');

    // template cols (AFTER order matters — profile_image before the theme block)
    addColumnIfMissing($conn, 'users', 'profile_image', "VARCHAR(255) DEFAULT NULL AFTER email", $log);
    if (addColumnIfMissing($conn, 'users', 'email_verified', "TINYINT(1) DEFAULT 0 AFTER email", $log)) {
        $conn->query("UPDATE users SET email_verified = 1"); // existing logins stay usable
    }
    addColumnIfMissing($conn, 'users', 'theme_primary',   "VARCHAR(20) DEFAULT '#111827' AFTER profile_image", $log);
    addColumnIfMissing($conn, 'users', 'theme_secondary', "VARCHAR(20) DEFAULT '#374151' AFTER theme_primary", $log);
    addColumnIfMissing($conn, 'users', 'theme_accent',    "VARCHAR(20) DEFAULT '#34D399' AFTER theme_secondary", $log);
    addColumnIfMissing($conn, 'users', 'theme_mode',      "VARCHAR(10) DEFAULT 'light' AFTER theme_accent", $log);
    addColumnIfMissing($conn, 'users', 'google_id',       "VARCHAR(255) DEFAULT NULL AFTER theme_mode", $log);

    // oauth_callback.php looks users up by google_id — prefix index, utf8mb4 col is too wide for a full key
    if (!indexExists($conn, 'users', 'idx_google_id') && $conn->query("ALTER TABLE users ADD INDEX idx_google_id (google_id(191))")) {
        $say('success', 'Index users.idx_google_id added (Google sign-in lookups)');
    }

    // orms cols — students/teachers read name+phone+photo off users (DRY)
    addColumnIfMissing($conn, 'users', 'full_name', "VARCHAR(100) DEFAULT NULL AFTER username", $log);
    addColumnIfMissing($conn, 'users', 'phone',     "VARCHAR(20) DEFAULT NULL AFTER email", $log);
    addColumnIfMissing($conn, 'users', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER role", $log);

    $conn->query("UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = ''");
    if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' user(s) given a default full_name');

    // ---------------------------------------------------------------- 4. retire the template 'User' role
    ormsRetireLegacyUserRole($conn, $say);   // the only row-removing step, kept out of applyUpdates' additive body

    // ---------------------------------------------------------------- 5. academic tables (parents first — real FKs)
    $say('info', 'Academic tables');

    $academic = [
        'academic_years' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(20) NOT NULL,
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_year_name (name),
            INDEX idx_year_current (is_current)
        SQL,
        'classes' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_class_name (name),
            INDEX idx_class_active (is_active)
        SQL,
        'subjects' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(20) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_subject_code (code),
            INDEX idx_subject_active (is_active)
        SQL,
        'grading_scheme' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            grade VARCHAR(5) NOT NULL,
            min_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            max_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            grade_point DECIMAL(3,1) NOT NULL DEFAULT 0.0,
            remarks VARCHAR(100) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_grade (grade),
            INDEX idx_grade_sort (sort_order)
        SQL,
        'exam_terms' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            academic_year_id INT NOT NULL,
            name VARCHAR(50) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status ENUM('Upcoming','Open','Closed') NOT NULL DEFAULT 'Upcoming',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_term (academic_year_id, name),
            INDEX idx_term_status (status),
            CONSTRAINT fk_terms_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'sections' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            name VARCHAR(20) NOT NULL,
            capacity INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_section (class_id, name),
            INDEX idx_section_active (is_active),
            CONSTRAINT fk_sections_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE ON UPDATE CASCADE
        SQL,
        'class_subjects' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            total_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            passing_marks DECIMAL(6,2) NOT NULL DEFAULT 33.00,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_class_subject (class_id, subject_id),
            INDEX idx_cs_subject (subject_id),
            CONSTRAINT fk_cs_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'teachers' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            employee_no VARCHAR(30) NOT NULL,
            qualification VARCHAR(150) DEFAULT NULL,
            joining_date DATE DEFAULT NULL,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_teacher_user (user_id),
            UNIQUE KEY uniq_employee_no (employee_no),
            INDEX idx_teacher_status (status),
            CONSTRAINT fk_teachers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        SQL,
        'students' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            admission_no VARCHAR(30) NOT NULL,
            roll_no VARCHAR(20) DEFAULT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            father_name VARCHAR(100) DEFAULT NULL,
            dob DATE DEFAULT NULL,
            gender ENUM('Male','Female','Other') DEFAULT NULL,
            guardian_phone VARCHAR(20) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            admission_date DATE DEFAULT NULL,
            status ENUM('Active','Inactive','Passed Out','Transferred') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_user (user_id),
            UNIQUE KEY uniq_admission_no (admission_no),
            INDEX idx_student_section_status (section_id, status),
            INDEX idx_student_class (class_id),
            INDEX idx_student_year (academic_year_id),
            INDEX idx_student_roll (roll_no),
            CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_students_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_students_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'teacher_subjects' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            subject_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assignment (academic_year_id, section_id, subject_id),
            INDEX idx_ts_teacher (teacher_id),
            INDEX idx_ts_section (section_id),
            INDEX idx_ts_subject (subject_id),
            INDEX idx_ts_class (class_id),
            CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ts_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_ts_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_ts_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'marks' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            subject_id INT NOT NULL,
            term_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            marks_obtained DECIMAL(6,2) DEFAULT NULL,
            total_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            passing_marks DECIMAL(6,2) DEFAULT NULL,
            is_absent TINYINT(1) NOT NULL DEFAULT 0,
            grade VARCHAR(5) DEFAULT NULL,
            entered_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mark (student_id, subject_id, term_id),
            INDEX idx_marks_grid (section_id, term_id, subject_id),
            INDEX idx_marks_term (term_id),
            INDEX idx_marks_subject (subject_id),
            INDEX idx_marks_year (academic_year_id),
            CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_marks_term FOREIGN KEY (term_id) REFERENCES exam_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'result_publications' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            term_id INT NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            published_by INT DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_publication (term_id, section_id),
            INDEX idx_pub_section (section_id),
            INDEX idx_pub_state (is_published),
            CONSTRAINT fk_pub_term FOREIGN KEY (term_id) REFERENCES exam_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_pub_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'result_summaries' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            term_id INT NOT NULL,
            section_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            total_obtained DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            total_max DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            grade VARCHAR(5) DEFAULT NULL,
            gpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
            `position` INT DEFAULT NULL,
            result_status ENUM('PASS','FAIL') NOT NULL DEFAULT 'FAIL',
            failed_subjects VARCHAR(255) DEFAULT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_summary (student_id, term_id),
            INDEX idx_sum_section_term (section_id, term_id),
            INDEX idx_sum_term (term_id),
            CONSTRAINT fk_sum_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_sum_term FOREIGN KEY (term_id) REFERENCES exam_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'student_subjects' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ss (student_id, subject_id, academic_year_id),
            INDEX idx_ss_subject_year (subject_id, academic_year_id),
            INDEX idx_ss_class (class_id),
            CONSTRAINT fk_ss_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ss_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_ss_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE ON UPDATE CASCADE
        SQL,
        'grading_sets' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_gset_name (name)
        SQL,
        'assessment_schemes' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ascheme_name (name)
        SQL,
        'assessment_components' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            scheme_id INT NOT NULL,
            name VARCHAR(60) NOT NULL,
            max_marks DECIMAL(6,2) NOT NULL DEFAULT 100.00,
            weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            is_exam TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_acomp (scheme_id, name),
            INDEX idx_acomp_scheme (scheme_id, sort_order),
            CONSTRAINT fk_acomp_scheme FOREIGN KEY (scheme_id) REFERENCES assessment_schemes(id) ON DELETE CASCADE ON UPDATE CASCADE
        SQL,
        'mark_components' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            term_id INT NOT NULL,
            component_id INT NOT NULL,
            score DECIMAL(6,2) DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mc (student_id, subject_id, term_id, component_id),
            INDEX idx_mc_grid (term_id, subject_id),
            INDEX idx_mc_comp (component_id),
            CONSTRAINT fk_mc_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_mc_comp FOREIGN KEY (component_id) REFERENCES assessment_components(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_mc_term FOREIGN KEY (term_id) REFERENCES exam_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'attendance_summary' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            term_id INT NOT NULL,
            section_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            days_present DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            days_total DECIMAL(6,1) NOT NULL DEFAULT 0.0,
            remarks VARCHAR(255) DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_att (student_id, term_id),
            INDEX idx_att_grid (section_id, term_id),
            CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_att_term FOREIGN KEY (term_id) REFERENCES exam_terms(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
        'student_fees' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            term_id INT DEFAULT NULL,
            entry_type ENUM('Charge','Payment') NOT NULL DEFAULT 'Charge',
            description VARCHAR(150) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            entry_date DATE DEFAULT NULL,
            reference VARCHAR(50) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fee_student (student_id, academic_year_id),
            INDEX idx_fee_term (term_id),
            CONSTRAINT fk_fee_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_fee_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT ON UPDATE CASCADE
        SQL,
    ];
    // fresh table? -> the one-time elective backfill below may run. checked BEFORE the create
    $ssq = $conn->query("SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_subjects'");
    $ssFresh = $ssq ? !$ssq->fetch_row() : false;
    // same trick for the new tables — starter sets/schemes seed ONLY on the run that creates them
    $gsq = $conn->query("SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grading_sets'");
    $gsFresh = $gsq ? !$gsq->fetch_row() : false;
    $asq = $conn->query("SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_schemes'");
    $asFresh = $asq ? !$asq->fetch_row() : false;
    foreach ($academic as $t => $ddl) createTable($conn, $t, $ddl, $log);

    // ---------------------------------------------------------------- 5b. academic column migrations (existing installs)
    // gpa: DECIMAL(3,2) caps at 9.99 — a 10-point scheme overflows under STRICT_TRANS_TABLES and publish rolls back
    if (modifyColumnIfType($conn, 'result_summaries', 'gpa', 'decimal(3,2)', "DECIMAL(4,2) NOT NULL DEFAULT 0.00", $log)) {
        $say('success', 'result_summaries.gpa widened DECIMAL(3,2) → DECIMAL(4,2) — 10-point schemes no longer overflow');
    }

    // snapshot of the pass mark at entry time — editing class_subjects later must not flip a published PASS to Fail
    addColumnIfMissing($conn, 'marks', 'passing_marks', "DECIMAL(6,2) DEFAULT NULL AFTER total_marks", $log);
    $conn->query("UPDATE marks m JOIN class_subjects cs ON cs.class_id = m.class_id AND cs.subject_id = m.subject_id
                  SET m.passing_marks = cs.passing_marks WHERE m.passing_marks IS NULL");
    if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' mark row(s) backfilled with a passing_marks snapshot');

    // ---------------------------------------------------------------- 5c. production columns
    // real-world gaps: audit trail, status flags, business fields. all nullable/defaulted so existing rows survive.
    $say('info', 'Production columns');

    // fk guard — these columns are new so no orphan values can exist, but never re-add an existing constraint
    $fkAdd = function (string $t, string $fk, string $sql) use ($conn, $say) {
        $q = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
                             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?");
        if (!$q) return;
        $q->bind_param('ss', $t, $fk);
        $q->execute();
        $c = (int)($q->get_result()->fetch_assoc()['c'] ?? 0);
        $q->close();
        if ($c === 0) {
            if ($conn->query($sql)) $say('success', "Foreign key {$fk} added");
            else $say('error', "Could not add {$fk}: " . $conn->error);
        }
    };

    // ---- users: audit + first-login control
    addColumnIfMissing($conn, 'users', 'updated_at',           "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);
    addColumnIfMissing($conn, 'users', 'must_change_password', "TINYINT(1) NOT NULL DEFAULT 0 AFTER password", $log);
    addColumnIfMissing($conn, 'users', 'last_login_at',        "DATETIME DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'users', 'last_login_ip',        "VARCHAR(45) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'users', 'password_changed_at',  "DATETIME DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'users', 'created_by',           "INT DEFAULT NULL", $log);

    // ---- activity_logs: make the trail queryable per record
    addColumnIfMissing($conn, 'activity_logs', 'entity_type', "VARCHAR(50) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'activity_logs', 'entity_id',   "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'activity_logs', 'user_agent',  "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'activity_logs', 'role',        "VARCHAR(50) DEFAULT NULL", $log);
    if (!indexExists($conn, 'activity_logs', 'idx_log_entity')) {
        $conn->query("ALTER TABLE activity_logs ADD INDEX idx_log_entity (entity_type, entity_id)");
    }

    // ---- system_settings: who changed the grading scheme
    addColumnIfMissing($conn, 'system_settings', 'updated_by',    "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'system_settings', 'setting_group', "VARCHAR(50) DEFAULT NULL", $log);
    $conn->query("UPDATE system_settings SET setting_group = 'result' WHERE setting_group IS NULL AND setting_key LIKE 'result[_]%'");
    $conn->query("UPDATE system_settings SET setting_group = 'smtp'   WHERE setting_group IS NULL AND setting_key LIKE 'smtp[_]%'");
    $conn->query("UPDATE system_settings SET setting_group = 'oauth'  WHERE setting_group IS NULL AND setting_key LIKE 'google[_]%'");
    $conn->query("UPDATE system_settings SET setting_group = 'general' WHERE setting_group IS NULL");

    // ---- otp tables: an unlimited-guess 6-digit code is brute-forceable
    foreach (['email_verifications', 'password_resets'] as $otp) {
        addColumnIfMissing($conn, $otp, 'attempts',   "TINYINT NOT NULL DEFAULT 0", $log);
        addColumnIfMissing($conn, $otp, 'used_at',    "DATETIME DEFAULT NULL", $log);
    }
    addColumnIfMissing($conn, 'password_resets',     'ip_address', "VARCHAR(45) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'email_verifications', 'ip_address', "VARCHAR(45) DEFAULT NULL", $log);

    // ---- remember_tokens: "sign out my other devices" needs to name them
    addColumnIfMissing($conn, 'remember_tokens', 'ip_address',   "VARCHAR(45) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'remember_tokens', 'user_agent',   "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'remember_tokens', 'last_used_at', "DATETIME DEFAULT NULL", $log);

    // ---- login_attempts: separate a brute force from a typo run
    if (addColumnIfMissing($conn, 'login_attempts', 'success', "TINYINT(1) NOT NULL DEFAULT 0", $log)) {
        $conn->query("UPDATE login_attempts SET success = 0"); // every historic row was a failure
    }
    addColumnIfMissing($conn, 'login_attempts', 'user_agent', "VARCHAR(255) DEFAULT NULL", $log);

    addColumnIfMissing($conn, 'user_sessions', 'logged_out_at', "DATETIME DEFAULT NULL", $log);

    // ---- notifications: is_read loses *when*
    addColumnIfMissing($conn, 'notifications', 'read_at',    "DATETIME DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'notifications', 'created_by', "INT DEFAULT NULL", $log);

    // ---- push_subscriptions: dead endpoints otherwise accumulate forever
    addColumnIfMissing($conn, 'push_subscriptions', 'is_active',    "TINYINT(1) NOT NULL DEFAULT 1", $log);
    addColumnIfMissing($conn, 'push_subscriptions', 'failed_count', "INT NOT NULL DEFAULT 0", $log);
    addColumnIfMissing($conn, 'push_subscriptions', 'last_used_at', "DATETIME DEFAULT NULL", $log);

    // ---- roles: this table controls the whole app and had no timestamps at all
    addColumnIfMissing($conn, 'roles', 'description', "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'roles', 'created_at',  "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP", $log);
    addColumnIfMissing($conn, 'roles', 'updated_at',  "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);
    addColumnIfMissing($conn, 'roles', 'updated_by',  "INT DEFAULT NULL", $log);

    // ---- academic_years: year-end close
    addColumnIfMissing($conn, 'academic_years', 'is_locked', "TINYINT(1) NOT NULL DEFAULT 0", $log);
    addColumnIfMissing($conn, 'academic_years', 'updated_at', "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);

    // ---- classes: promotion target + correct numeric ordering
    addColumnIfMissing($conn, 'classes', 'numeric_level',  "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'classes', 'next_class_id',  "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'classes', 'result_template', "VARCHAR(20) DEFAULT NULL AFTER next_class_id", $log); // null = global default
    addColumnIfMissing($conn, 'classes', 'show_position',  "TINYINT(1) NOT NULL DEFAULT 1 AFTER result_template", $log); // junior classes often hide ranks
    addColumnIfMissing($conn, 'classes', 'updated_at',     "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);
    $conn->query("UPDATE classes SET numeric_level = CAST(REGEXP_SUBSTR(name, '[0-9]+') AS UNSIGNED)
                  WHERE numeric_level IS NULL AND name REGEXP '[0-9]'");
    $fkAdd('classes', 'fk_classes_next', "ALTER TABLE classes ADD CONSTRAINT fk_classes_next
            FOREIGN KEY (next_class_id) REFERENCES classes(id) ON DELETE SET NULL ON UPDATE CASCADE");

    // ---- subjects: not every graded subject counts toward the percentage
    addColumnIfMissing($conn, 'subjects', 'short_name',       "VARCHAR(20) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'subjects', 'subject_type',     "ENUM('Core','Elective','Optional') NOT NULL DEFAULT 'Core'", $log);
    addColumnIfMissing($conn, 'subjects', 'include_in_total', "TINYINT(1) NOT NULL DEFAULT 1", $log);
    addColumnIfMissing($conn, 'subjects', 'updated_at',       "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);

    // ---- grading_scheme: the scheme itself never knew which band is a fail
    addColumnIfMissing($conn, 'grading_scheme', 'is_fail', "TINYINT(1) NOT NULL DEFAULT 0", $log);
    addColumnIfMissing($conn, 'grading_scheme', 'color',   "VARCHAR(10) DEFAULT NULL", $log);
    $conn->query("UPDATE grading_scheme SET is_fail = 1 WHERE grade_point <= 0");

    // ---- exam_terms: exam window + weighting for a combined annual result
    addColumnIfMissing($conn, 'exam_terms', 'start_date',  "DATE DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'exam_terms', 'end_date',    "DATE DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'exam_terms', 'result_date', "DATE DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'exam_terms', 'weightage',   "DECIMAL(5,2) NOT NULL DEFAULT 0.00", $log);
    addColumnIfMissing($conn, 'exam_terms', 'updated_at',  "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);

    // ---- sections: the card prints "Class Teacher" but nothing stored one
    addColumnIfMissing($conn, 'sections', 'class_teacher_id', "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'sections', 'room_no',          "VARCHAR(20) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'sections', 'updated_at',       "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);
    if (!indexExists($conn, 'sections', 'idx_section_teacher')) {
        $conn->query("ALTER TABLE sections ADD INDEX idx_section_teacher (class_teacher_id)");
    }
    $fkAdd('sections', 'fk_sections_teacher', "ALTER TABLE sections ADD CONSTRAINT fk_sections_teacher
            FOREIGN KEY (class_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL ON UPDATE CASCADE");

    // ---- class_subjects: theory/practical split + per-class total override
    addColumnIfMissing($conn, 'class_subjects', 'theory_marks',     "DECIMAL(6,2) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'class_subjects', 'practical_marks',  "DECIMAL(6,2) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'class_subjects', 'include_in_total', "TINYINT(1) NOT NULL DEFAULT 1", $log);
    addColumnIfMissing($conn, 'class_subjects', 'is_optional',      "TINYINT(1) NOT NULL DEFAULT 0", $log);
    addColumnIfMissing($conn, 'class_subjects', 'updated_at',       "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP", $log);

    // ---- elective backfill: runs ONLY the run that created student_subjects. behaviour-preserving —
    // every active student keeps every optional subject of their class (the school un-enrols skippers after),
    // and anyone with historical marks in an optional subject stays enrolled so old cards never lose a row
    if ($ssFresh) {
        $conn->query("INSERT IGNORE INTO student_subjects (student_id, class_id, subject_id, academic_year_id)
                      SELECT st.id, st.class_id, cs.subject_id, st.academic_year_id
                      FROM students st JOIN class_subjects cs ON cs.class_id = st.class_id
                      WHERE cs.is_optional = 1 AND st.status = 'Active'");
        $conn->query("INSERT IGNORE INTO student_subjects (student_id, class_id, subject_id, academic_year_id)
                      SELECT DISTINCT m.student_id, m.class_id, m.subject_id, m.academic_year_id
                      FROM marks m JOIN class_subjects cs ON cs.class_id = m.class_id AND cs.subject_id = m.subject_id
                      WHERE cs.is_optional = 1");
        $say('success', 'Optional-subject enrolments backfilled — existing students keep every subject they have today');
    }

    // ---- teachers: HR fields a school actually keeps
    addColumnIfMissing($conn, 'teachers', 'designation',       "VARCHAR(100) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teachers', 'national_id',       "VARCHAR(30) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teachers', 'leaving_date',      "DATE DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teachers', 'emergency_contact', "VARCHAR(20) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teachers', 'remarks',           "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teachers', 'created_by',        "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teachers', 'updated_by',        "INT DEFAULT NULL", $log);

    // ---- students: the fields every official record asks for
    addColumnIfMissing($conn, 'students', 'mother_name',     "VARCHAR(100) DEFAULT NULL AFTER father_name", $log);
    addColumnIfMissing($conn, 'students', 'guardian_name',   "VARCHAR(100) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'guardian_email',  "VARCHAR(100) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'national_id',     "VARCHAR(30) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'blood_group',     "VARCHAR(5) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'previous_school', "VARCHAR(150) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'date_of_leaving', "DATE DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'leaving_reason',  "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'remarks',         "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'created_by',      "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'students', 'updated_by',      "INT DEFAULT NULL", $log);

    addColumnIfMissing($conn, 'teacher_subjects', 'assigned_by', "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'teacher_subjects', 'is_active',   "TINYINT(1) NOT NULL DEFAULT 1", $log);

    // ---- marks: per-subject comment + theory/practical breakdown
    addColumnIfMissing($conn, 'marks', 'remarks',            "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'marks', 'theory_obtained',    "DECIMAL(6,2) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'marks', 'practical_obtained', "DECIMAL(6,2) DEFAULT NULL", $log);

    // ---- result_publications: unpublish must not overwrite who published
    addColumnIfMissing($conn, 'result_publications', 'unpublished_by',   "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_publications', 'unpublished_at',   "DATETIME DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_publications', 'unpublish_reason', "VARCHAR(255) DEFAULT NULL", $log);

    // ---- head-teacher approval gate: Draft -> Pending -> Approved -> published. Rejected bounces back with a note
    $freshGate = addColumnIfMissing($conn, 'result_publications', 'approval_status', "ENUM('Draft','Pending','Approved','Rejected') NOT NULL DEFAULT 'Draft'", $log);
    addColumnIfMissing($conn, 'result_publications', 'submitted_by', "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_publications', 'submitted_at', "DATETIME DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_publications', 'approved_by',  "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_publications', 'approved_at',  "DATETIME DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_publications', 'review_note',  "VARCHAR(255) DEFAULT NULL", $log);
    // whatever is already live was approved by whoever published it — without this, switching the gate on strands every published section
    if ($freshGate) {
        $conn->query("UPDATE result_publications SET approval_status = 'Approved', approved_by = published_by, approved_at = published_at WHERE is_published = 1");
        if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' already-published section(s) back-filled as Approved');
    }
    if (!indexExists($conn, 'result_publications', 'idx_pub_approval') && $conn->query("ALTER TABLE result_publications ADD INDEX idx_pub_approval (approval_status)")) {
        $say('success', 'Index result_publications.idx_pub_approval added');
    }

    // ---- result_summaries: freeze the rank denominator, carry the class, hold the teacher's comment
    addColumnIfMissing($conn, 'result_summaries', 'class_id',        "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'section_total',   "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'subjects_count',  "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'teacher_remarks', "VARCHAR(255) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'principal_remarks', "VARCHAR(255) DEFAULT NULL", $log); // carried across republish like teacher_remarks
    addColumnIfMissing($conn, 'result_summaries', 'promoted_status', "ENUM('Pending','Promoted','Detained') NOT NULL DEFAULT 'Pending'", $log);
    addColumnIfMissing($conn, 'result_summaries', 'generated_by',    "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'verify_token',    "VARCHAR(32) DEFAULT NULL", $log);
    // one unguessable token per existing result — printed as a QR, resolved by the public verify page
    $need = [];
    $r = $conn->query("SELECT id FROM result_summaries WHERE verify_token IS NULL");
    while ($r && $row = $r->fetch_row()) $need[] = (int)$row[0];
    if ($need) {
        $vStmt2 = $conn->prepare("UPDATE result_summaries SET verify_token = ? WHERE id = ?");
        if ($vStmt2) {
            $vTok = ''; $vId = 0;
            $vStmt2->bind_param('si', $vTok, $vId);
            foreach ($need as $vId) { $vTok = bin2hex(random_bytes(16)); $vStmt2->execute(); }
            $vStmt2->close();
            $say('success', count($need) . ' result row(s) issued a verification token');
        }
    }
    if (!indexExists($conn, 'result_summaries', 'uniq_verify_token')) {
        $conn->query("ALTER TABLE result_summaries ADD UNIQUE INDEX uniq_verify_token (verify_token)");
    }
    $conn->query("UPDATE result_summaries rs JOIN sections s ON s.id = rs.section_id
                  SET rs.class_id = s.class_id WHERE rs.class_id IS NULL");
    $conn->query("UPDATE result_summaries rs
                  JOIN (SELECT term_id, section_id, COUNT(*) n FROM result_summaries GROUP BY term_id, section_id) t
                    ON t.term_id = rs.term_id AND t.section_id = rs.section_id
                  SET rs.section_total = t.n WHERE rs.section_total IS NULL");

    // ---------------------------------------------------------------- 5d. grading sets, components, attendance, fees
    $say('info', 'Grading sets, components, attendance & fees');

    // ---- grading_scheme: one scheme was never enough — nursery is not grade 9
    if (addColumnIfMissing($conn, 'grading_scheme', 'set_id', "INT NOT NULL DEFAULT 1 AFTER id", $log)) {
        $say('success', 'grading_scheme.set_id added — schemes are now per-set');
    }
    addColumnIfMissing($conn, 'grading_scheme', 'interpretation', "VARCHAR(255) DEFAULT NULL", $log); // what the band means to a parent

    // uniq_grade(grade) blocked the same letter living in two sets — the unique moves to (set_id, grade)
    if (indexExists($conn, 'grading_scheme', 'uniq_grade')) {
        if ($conn->query("ALTER TABLE grading_scheme DROP INDEX uniq_grade")) $say('success', 'grading_scheme.uniq_grade dropped — a grade letter may now repeat across sets');
        else $say('error', 'Could not drop grading_scheme.uniq_grade: ' . $conn->error);
    }
    if (!indexExists($conn, 'grading_scheme', 'uniq_set_grade')) {
        if ($conn->query("ALTER TABLE grading_scheme ADD UNIQUE KEY uniq_set_grade (set_id, grade)")) $say('success', 'grading_scheme.uniq_set_grade (set_id, grade) added');
        else $say('error', 'Could not add grading_scheme.uniq_set_grade: ' . $conn->error);
    }

    // ---- classes: which grading set grades it, which assessment scheme it is marked on
    addColumnIfMissing($conn, 'classes', 'grading_set_id',       "INT DEFAULT NULL", $log); // null = inherit the default set
    addColumnIfMissing($conn, 'classes', 'assessment_scheme_id', "INT DEFAULT NULL", $log); // null = one mark box, no components

    // ---- marks: snapshot the scheme + keep the weighted CA/exam split behind the total
    addColumnIfMissing($conn, 'marks', 'assessment_scheme_id', "INT DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'marks', 'ca_obtained',          "DECIMAL(6,2) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'marks', 'exam_obtained',        "DECIMAL(6,2) DEFAULT NULL", $log);

    // ---- students: manual withhold switch, independent of the arrears rule
    addColumnIfMissing($conn, 'students', 'fee_hold',      "TINYINT(1) NOT NULL DEFAULT 0", $log);
    addColumnIfMissing($conn, 'students', 'fee_hold_note', "VARCHAR(150) DEFAULT NULL", $log);

    // ---- result_summaries: attendance + withholding frozen at publish, plus the set that graded the card
    addColumnIfMissing($conn, 'result_summaries', 'days_present',    "DECIMAL(6,1) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'days_total',      "DECIMAL(6,1) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'is_withheld',     "TINYINT(1) NOT NULL DEFAULT 0", $log);
    addColumnIfMissing($conn, 'result_summaries', 'withheld_reason', "VARCHAR(150) DEFAULT NULL", $log);
    addColumnIfMissing($conn, 'result_summaries', 'grading_set_id',  "INT DEFAULT NULL", $log); // re-pointing a class never regrades old cards

    // ---------------------------------------------------------------- 5e. multi-tenant: schools, branches, plans, billing
    // sits AFTER the academic tables (the fk targets must exist) and BEFORE the roles seed,
    // because roles/settings become per-school below and 6a has to seed into a school.
    $say('info', 'Multi-tenant (schools, branches, plans, subscriptions)');

    // fresh? -> the one-shot tenant #1 seed runs only on the install that creates the table
    $scq = $conn->query("SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools'");
    $scFresh = $scq ? !$scq->fetch_row() : false;

    $saas = [
        'plans' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            price_yearly DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_students INT NOT NULL DEFAULT 0,
            max_teachers INT NOT NULL DEFAULT 0,
            max_branches INT NOT NULL DEFAULT 1,
            features TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_name (name)
        SQL,
        'schools' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            code VARCHAR(20) NOT NULL,
            logo VARCHAR(255) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            status ENUM('Trial','Active','Suspended','Cancelled') NOT NULL DEFAULT 'Trial',
            plan_id INT DEFAULT NULL,
            trial_ends_at DATE DEFAULT NULL,
            owner_user_id INT DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_school_code (code),
            INDEX idx_school_status (status),
            CONSTRAINT fk_schools_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL ON UPDATE CASCADE
        SQL,
        'branches' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            code VARCHAR(20) NOT NULL,
            address VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            is_main TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_branch_code (school_id, code),
            INDEX idx_branch_school (school_id, status),
            CONSTRAINT fk_branches_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE
        SQL,
        // multi-currency pricing. A plan's own columns are its price in the PLATFORM currency; a row
        // here is the price in one other currency. Prices are CHOSEN, never converted — no FX rate is
        // applied anywhere, because a rate that drifts overnight silently changes what a school pays.
        'plan_prices' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            currency VARCHAR(10) NOT NULL,
            price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            price_yearly DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_ccy (plan_id, currency),
            CONSTRAINT fk_planprice_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE ON UPDATE CASCADE
        SQL,
        'school_subscriptions' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_id INT NOT NULL,
            plan_id INT DEFAULT NULL,
            starts_at DATE NOT NULL,
            ends_at DATE NOT NULL,
            status ENUM('Active','Expired','Cancelled') NOT NULL DEFAULT 'Active',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sub_school (school_id, ends_at),
            CONSTRAINT fk_sub_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL ON UPDATE CASCADE
        SQL,
        'subscription_payments' => <<<'SQL'
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_id INT NOT NULL,
            subscription_id INT DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            paid_on DATE NOT NULL,
            method VARCHAR(40) DEFAULT NULL,
            reference VARCHAR(100) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            recorded_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pay_school (school_id, paid_on),
            CONSTRAINT fk_pay_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_pay_sub FOREIGN KEY (subscription_id) REFERENCES school_subscriptions(id) ON DELETE SET NULL ON UPDATE CASCADE
        SQL,
    ];
    foreach ($saas as $t => $ddl) createTable($conn, $t, $ddl, $log);

    // ---- starter plans + tenant #1, once. the existing install BECOMES school 1 so nothing breaks
    if ($scFresh) {
        $ps = $conn->prepare("INSERT IGNORE INTO plans (name, price_monthly, price_yearly, max_students, max_teachers, max_branches, features, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ps) {
            // 0 in a max column = unlimited
            foreach ([['Trial', 0, 0, 50, 5, 1, 'Evaluation only', 0],
                      ['Starter', 3000, 30000, 300, 20, 1, 'One branch, all result features', 1],
                      ['Standard', 6000, 60000, 1000, 60, 3, 'Up to 3 branches, fees + attendance', 2],
                      ['Premium', 12000, 120000, 0, 0, 0, 'Unlimited students, teachers and branches', 3]] as $p) {
                $ps->bind_param('sddiiisi', $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]);
                $ps->execute();
            }
            $ps->close();
            $say('success', 'Starter plans seeded (Trial / Starter / Standard / Premium)');
        }

        // name/address/phone come from what this install already calls itself
        $get1 = static function (string $k, string $d) use ($conn): string {
            $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
            if (!$st) return $d;
            $st->bind_param('s', $k);
            $st->execute();
            $row = $st->get_result()->fetch_row();
            $st->close();
            return ($row && trim((string)$row[0]) !== '') ? (string)$row[0] : $d;
        };
        $sName = $get1('result_school_name', $get1('site_name', 'Main School'));
        $sAddr = $get1('result_school_address', '');
        $sPhone = $get1('result_school_phone', '');
        $st = $conn->prepare("INSERT IGNORE INTO schools (id, name, code, address, phone, status, plan_id, notes)
                              VALUES (1, ?, 'SCH01', ?, ?, 'Active', (SELECT id FROM plans WHERE name = 'Premium' LIMIT 1), 'Original single-school install')");
        if ($st) { $st->bind_param('sss', $sName, $sAddr, $sPhone); $st->execute(); $st->close(); }
        $conn->query("INSERT IGNORE INTO branches (id, school_id, name, code, is_main) VALUES (1, 1, 'Main Branch', 'MAIN', 1)");
        // long runway so the new gate can never lock out the install that just migrated
        $conn->query("INSERT IGNORE INTO school_subscriptions (id, school_id, plan_id, starts_at, ends_at, status, notes)
                      SELECT 1, 1, p.id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 YEAR), 'Active', 'Grandfathered on migration'
                      FROM plans p WHERE p.name = 'Premium' LIMIT 1");
        $say('success', 'Tenant #1 seeded from existing settings — code SCH01, Main Branch, grandfathered subscription');
    }

    // ---- branch profile columns. a campus is more than a name + a phone number: the school owner
    // needs to know where it is, who runs it and how full it is. capacity 0 = uncapped (the default),
    // so an install that never fills these in behaves exactly as it did before.
    $brnCols = [
        'city'         => "VARCHAR(80) DEFAULT NULL AFTER address",
        'email'        => "VARCHAR(100) DEFAULT NULL AFTER phone",
        'head_user_id' => "INT DEFAULT NULL AFTER email",              // branch in-charge, points at users.id
        'opened_on'    => "DATE DEFAULT NULL AFTER head_user_id",
        'capacity'     => "INT NOT NULL DEFAULT 0 AFTER opened_on",    // 0 = no seat cap on this campus
        'notes'        => "VARCHAR(255) DEFAULT NULL AFTER capacity",
    ];
    $brnAdded = 0;
    foreach ($brnCols as $c => $ddl) if (addColumnIfMissing($conn, 'branches', $c, $ddl, $log)) $brnAdded++;
    if ($brnAdded) $say('success', $brnAdded . ' branch profile column(s) added (city, email, head, opened, capacity, notes)');
    // a head who was deleted leaves a dangling pointer — clear it every run, the UI then reads "not set"
    $conn->query("UPDATE branches b LEFT JOIN users u ON u.id = b.head_user_id AND u.school_id = b.school_id
                  SET b.head_user_id = NULL WHERE b.head_user_id IS NOT NULL AND u.id IS NULL");
    if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' branch head pointer(s) cleared — the account no longer exists');

    // ---- tenant columns. school_id defaults to 1 so every existing row lands in tenant #1 automatically
    $tenantTables = ['roles', 'system_settings', 'academic_years', 'classes', 'subjects', 'grading_sets',
                     'assessment_schemes', 'teachers', 'students', 'activity_logs', 'notifications', 'student_fees',
                     'marks', 'result_summaries', 'result_publications', 'attendance_summary'];
    $tenantAdded = 0;
    foreach ($tenantTables as $t) {
        if (addColumnIfMissing($conn, $t, 'school_id', "INT NOT NULL DEFAULT 1", $log)) $tenantAdded++;
        if (!indexExists($conn, $t, 'idx_' . substr($t, 0, 20) . '_school')) {
            $conn->query("ALTER TABLE `$t` ADD INDEX `idx_" . substr($t, 0, 20) . "_school` (school_id)");
        }
    }
    $say($tenantAdded ? 'success' : 'info', $tenantAdded ? $tenantAdded . ' table(s) gained school_id' : 'school_id already on every tenant table');

    // users is the exception: 0 = platform super admin, so NO fk and NO default of 1.
    // NULL would break UNIQUE(school_id, username) — mysql treats NULLs as distinct, letting
    // two super admins share a username. the backfill runs only on the run that adds the column.
    if (addColumnIfMissing($conn, 'users', 'school_id', "INT NOT NULL DEFAULT 0 AFTER role", $log)) {
        $conn->query("UPDATE users SET school_id = 1");   // existing accounts belong to tenant #1
        $say('success', 'users.school_id added — existing accounts assigned to school 1');
    }
    if (!indexExists($conn, 'users', 'idx_users_school')) $conn->query("ALTER TABLE users ADD INDEX idx_users_school (school_id, role)");

    // repair sweep, every run: school 0 is reserved for the platform (Super Admins only). the
    // backfill above fires ONLY on the run that adds the column, so any account created after it
    // — the installer's own demo admin included — lands on 0 and then sees a completely empty app,
    // because every tenant table defaults to school 1. idempotent: matches nothing once clean.
    if (schemaProbe($conn, "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'school_id' LIMIT 1", []) !== null) {
        $conn->query("UPDATE users SET school_id = 1 WHERE school_id = 0 AND role <> 'Super Admin'");
        if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' school-0 account(s) reassigned to school 1 (0 is platform-only)');
    }

    // login_attempts: 0 = platform / public lookups. every school has an "admin", so a
    // username-only lockout key lets one school lock out all the others.
    addColumnIfMissing($conn, 'login_attempts', 'school_id', "INT NOT NULL DEFAULT 0", $log);
    if (!indexExists($conn, 'login_attempts', 'idx_attempt_school')) {
        $conn->query("ALTER TABLE login_attempts ADD INDEX idx_attempt_school (username, school_id, attempt_time)");
    }

    // password_resets / email_verifications are keyed on EMAIL, which is NOT unique across schools.
    // add school_id so a reset/verify can be pinned to one tenant — email alone must never cross schools
    // (0 = pre-scope / single-school; scoped only when platform_mode resolves a real school).
    foreach (['password_resets', 'email_verifications'] as $t) {
        if (addColumnIfMissing($conn, $t, 'school_id', "INT NOT NULL DEFAULT 0", $log)) {
            // backfill from the owning account; a cross-school duplicate email resolves to one match (transient rows)
            $conn->query("UPDATE `$t` r JOIN users u ON u.email = r.email SET r.school_id = u.school_id WHERE r.school_id = 0");
            $say('success', "$t.school_id added" . ($conn->affected_rows ? ' and backfilled' : ''));
        }
        if (!indexExists($conn, $t, 'idx_' . substr($t, 0, 20) . '_school')) {
            $conn->query("ALTER TABLE `$t` ADD INDEX `idx_" . substr($t, 0, 20) . "_school` (email, school_id)");
        }
    }

    // branch_id: classes carry the branch, students/teachers/users inherit it
    foreach (['classes', 'teachers', 'students', 'users'] as $t) {
        if (addColumnIfMissing($conn, $t, 'branch_id', "INT DEFAULT NULL", $log)) {
            $conn->query("UPDATE `$t` SET branch_id = 1 WHERE branch_id IS NULL");   // one branch today
        }
        if (!indexExists($conn, $t, 'idx_' . substr($t, 0, 20) . '_branch')) {
            $conn->query("ALTER TABLE `$t` ADD INDEX `idx_" . substr($t, 0, 20) . "_branch` (branch_id)");
        }
        // repair sweep, EVERY run — seeders and old code paths leave NULL branches behind, and a NULL
        // row is invisible to every branch-fenced count/list. stray rows land on their school's main branch
        $conn->query("UPDATE `$t` x JOIN branches b ON b.school_id = x.school_id AND b.is_main = 1
                      SET x.branch_id = b.id WHERE x.branch_id IS NULL");
        if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . " $t row(s) re-homed to their school's main branch");
    }

    // activity_logs gets branch_id so a Branch Admin's log view can be pinned to their own branch.
    // 0 = platform / public lookup / unbranched (still visible school-wide). nullable-style default, no backfill.
    addColumnIfMissing($conn, 'activity_logs', 'branch_id', "INT NOT NULL DEFAULT 0", $log);
    if (!indexExists($conn, 'activity_logs', 'idx_log_branch')) {
        $conn->query("ALTER TABLE activity_logs ADD INDEX idx_log_branch (school_id, branch_id)");
    }

    // ---- platform settings row (school 0) = the fallback getSetting() falls through to.
    // smtp/vapid/oauth are the operator's, not a school's — copy them up once.
    // 🚨 billing_* and gw_* are here for the same reason platform_mode had to be: they are read
    // through ormsPlatformSetting() (school 0 FLAT, never the inherited cache), but an install that
    // configured billing before that split stored them on school 1 — where nothing reads them any
    // more. Without this copy the operator's console shows "not configured" over live credentials.
    $conn->query("INSERT IGNORE INTO system_settings (school_id, setting_key, setting_value)
                  SELECT 0, setting_key, setting_value FROM system_settings
                  WHERE school_id = 1 AND (setting_key LIKE 'smtp\\_%' OR setting_key LIKE 'vapid\\_%'
                        OR setting_key LIKE 'google\\_%' OR setting_key LIKE 'billing\\_%'
                        OR setting_key LIKE 'gw\\_%'
                        OR setting_key IN ('enable_web_push','default_language','maintenance_mode','platform_mode'))");

    // ---------------------------------------------------------------- 5f. unique keys -> composite (school-scoped)
    // the only non-additive step in this file. every rebuild is guarded, so a re-run is a no-op and
    // an interrupted run resumes where it stopped. BACK THE DATABASE UP BEFORE THE FIRST RUN.
    $say('info', 'Rebuilding unique keys as school-scoped');

    // [table, old index name, new index name, new column list]
    $uniqSwaps = [
        ['users',              'username',            'uniq_school_username',  '(school_id, username)'],
        ['students',           'uniq_admission_no',   'uniq_school_admission', '(school_id, admission_no)'],
        ['teachers',           'uniq_employee_no',    'uniq_school_employee',  '(school_id, employee_no)'],
        ['academic_years',     'uniq_year_name',      'uniq_school_year',      '(school_id, name)'],
        ['classes',            'uniq_class_name',     'uniq_school_class',     '(school_id, branch_id, name)'],
        ['subjects',           'uniq_subject_code',   'uniq_school_subject',   '(school_id, code)'],
        ['grading_sets',       'uniq_gset_name',      'uniq_school_gset',      '(school_id, name)'],
        ['assessment_schemes', 'uniq_ascheme_name',   'uniq_school_ascheme',   '(school_id, name)'],
        ['system_settings',    'setting_key',         'uniq_school_setting',   '(school_id, setting_key)'],
    ];
    foreach ($uniqSwaps as [$t, $old, $new, $cols]) {
        if (indexExists($conn, $t, $new)) continue;                       // already swapped
        if ($conn->query("ALTER TABLE `$t` ADD UNIQUE KEY `$new` $cols")) {
            // drop the global one ONLY after the replacement is in place — never leave the table unprotected
            if (indexExists($conn, $t, $old)) $conn->query("ALTER TABLE `$t` DROP INDEX `$old`");
            $say('success', "$t.$new $cols — was globally unique on $old");
        } else {
            $say('error', "Could not add $t.$new: " . $conn->error);      // duplicates across schools -> fix data, re-run
        }
    }

    // roles.role_key is a PRIMARY KEY, so it needs the pk swapped rather than an index added
    $rolePk = schemaProbe($conn, "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND CONSTRAINT_NAME = 'PRIMARY'", []);
    if ((int)$rolePk === 1) {
        if ($conn->query("ALTER TABLE roles DROP PRIMARY KEY, ADD PRIMARY KEY (school_id, role_key)")) {
            $say('success', 'roles PRIMARY KEY -> (school_id, role_key) — every school owns its own matrix');
        } else {
            $say('error', 'Could not repoint the roles primary key: ' . $conn->error);
        }
    }

    // ---------------------------------------------------------------- 5g. school ops tables
    // daily register + fee structures + timetable/exam schedule. all fenced via their parents
    // (student/section/class/term carry the school), so no school_id column of their own
    $say('info', 'School ops tables (daily attendance, fee structures, timetable)');

    createTable($conn, 'attendance_daily', <<<'SQL'
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        section_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        att_date DATE NOT NULL,
        status ENUM('P','A','L','LV') NOT NULL DEFAULT 'P',
        remarks VARCHAR(120) DEFAULT NULL,
        marked_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_day (student_id, att_date),
        INDEX idx_ad_section_date (section_id, att_date),
        INDEX idx_ad_date_status (att_date, status),
        CONSTRAINT fk_ad_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_ad_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE ON UPDATE CASCADE
    SQL, $log);

    createTable($conn, 'fee_structures', <<<'SQL'
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        frequency ENUM('Monthly','One-Time') NOT NULL DEFAULT 'Monthly',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fs (class_id, name),
        CONSTRAINT fk_fs_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE ON UPDATE CASCADE
    SQL, $log);

    createTable($conn, 'timetable_slots', <<<'SQL'
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_id INT NOT NULL,
        day_of_week TINYINT NOT NULL,
        period_no TINYINT NOT NULL,
        subject_id INT DEFAULT NULL,
        teacher_id INT DEFAULT NULL,
        start_time VARCHAR(5) DEFAULT NULL,
        end_time VARCHAR(5) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slot (section_id, day_of_week, period_no),
        INDEX idx_tt_teacher (teacher_id, day_of_week, period_no),
        CONSTRAINT fk_tt_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_tt_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_tt_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL ON UPDATE CASCADE
    SQL, $log);

    createTable($conn, 'exam_schedule', <<<'SQL'
        id INT AUTO_INCREMENT PRIMARY KEY,
        term_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        exam_date DATE DEFAULT NULL,
        start_time VARCHAR(5) DEFAULT NULL,
        end_time VARCHAR(5) DEFAULT NULL,
        room VARCHAR(40) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_exam (term_id, class_id, subject_id),
        INDEX idx_es_term (term_id, exam_date),
        CONSTRAINT fk_es_term FOREIGN KEY (term_id) REFERENCES exam_terms(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_es_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_es_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
    SQL, $log);

    // ---------------------------------------------------------------- 5h. billing: invoices + gateway audit
    // self-serve checkout. an invoice is the ONLY thing a gateway is ever handed, and uniq_gw_ref is
    // what makes a replayed webhook harmless — the same reference can buy a period exactly once.
    // gateway_ref stays NULL until a gateway claims the invoice, and MySQL allows many NULLs in a
    // unique index, so any number of Pending invoices coexist.
    $say('info', 'Billing (invoices, gateway events)');

    createTable($conn, 'billing_invoices', <<<'SQL'
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        invoice_no VARCHAR(30) NOT NULL,
        token CHAR(48) NOT NULL,
        plan_id INT DEFAULT NULL,
        cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) NOT NULL DEFAULT 'USD',
        status ENUM('Pending','Paid','Failed','Cancelled','Expired') NOT NULL DEFAULT 'Pending',
        gateway VARCHAR(30) NOT NULL DEFAULT 'manual',
        gateway_ref VARCHAR(191) DEFAULT NULL,
        payment_id INT DEFAULT NULL,
        subscription_id INT DEFAULT NULL,
        period_start DATE DEFAULT NULL,
        period_end DATE DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        proof VARCHAR(255) DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_inv_no (invoice_no),
        UNIQUE KEY uniq_inv_token (token),
        UNIQUE KEY uniq_gw_ref (gateway, gateway_ref),
        INDEX idx_inv_school (school_id, status, created_at),
        CONSTRAINT fk_inv_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_inv_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL ON UPDATE CASCADE
    SQL, $log);

    // every callback lands here BEFORE it is trusted — signature_ok/applied make a failed or replayed
    // hit readable months later. deliberately no FK on invoice_id: a hit for an unknown invoice must
    // still be recorded, that is exactly the case worth seeing.
    createTable($conn, 'billing_events', <<<'SQL'
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT DEFAULT NULL,
        school_id INT DEFAULT NULL,
        gateway VARCHAR(30) NOT NULL,
        event VARCHAR(60) NOT NULL,
        reference VARCHAR(191) DEFAULT NULL,
        signature_ok TINYINT(1) NOT NULL DEFAULT 0,
        applied TINYINT(1) NOT NULL DEFAULT 0,
        message VARCHAR(255) DEFAULT NULL,
        payload MEDIUMTEXT DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ev_gw (gateway, reference),
        INDEX idx_ev_inv (invoice_id, created_at)
    SQL, $log);

    // the receipt points back at the invoice that produced it (manual receipts keep NULL)
    addColumnIfMissing($conn, 'subscription_payments', 'invoice_id', 'INT DEFAULT NULL AFTER subscription_id', $log);
    addColumnIfMissing($conn, 'schools', 'logo', "VARCHAR(255) DEFAULT NULL AFTER code", $log);
    addColumnIfMissing($conn, 'schools', 'trial_ends_at', "DATE DEFAULT NULL AFTER plan_id", $log);
    addColumnIfMissing($conn, 'schools', 'billing_currency', "VARCHAR(10) DEFAULT NULL AFTER plan_id", $log);

    // ---------------------------------------------------------------- 6a. roles + permission matrix
    $say('info', 'Roles & permissions');

    // page-key registry — keep in sync with $RBAC_PAGES in config.php
    $pages = ['dashboard', 'users', 'logs', 'sessions', 'settings', 'backup', 'smtp_setup', 'oauth_setup', 'roles',
              'branches', 'students', 'teachers', 'classes', 'subjects', 'marks_entry', 'results', 'my_results',
              'result_settings', 'broadsheet', 'attendance', 'fees', 'timetable', 'billing'];

    $none = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
    $view = ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0];
    $full = ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1];

    // 🚨 platform pages are listed SEPARATELY and handed $none to every school role. appending them
    // to $pages instead would flow through $adminPerms = array_fill_keys($pages, $full) and the
    // array_diff_key merge below, silently granting every school's Admin full CRUD over the
    // platform console (schools/plans/subscriptions) on the next migration run.
    $platformPages = ['schools', 'plans', 'subscriptions', 'gateways'];
    $adminPerms = array_fill_keys($pages, $full) + array_fill_keys($platformPages, $none);
    $adminPerms['backup'] = $none;   // a shared db means one dump = every tenant's data. platform only.
    $adminPerms['billing'] = $none;  // money is the school OWNER's, not the day-to-day admin's

    // school owner: the tenant's own top role. everything Admin does PLUS the money — plan, invoices,
    // renewal. Admin runs the school day to day; the owner is who the subscription belongs to, so the
    // two differ by exactly one page and nothing else has to be kept in sync.
    $ownerPerms = $adminPerms;
    $ownerPerms['billing'] = $full;

    $teacherPerms = array_fill_keys($pages, $none);
    foreach (['dashboard', 'students', 'classes', 'subjects', 'results', 'broadsheet'] as $pk) $teacherPerms[$pk] = $view;
    $teacherPerms['marks_entry'] = ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]; // enter + edit own, never delete
    $teacherPerms['attendance']  = ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]; // own sections, scoped by teacher_subjects
    $teacherPerms['timetable']   = $view;                                    // read their periods, never edit the grid
    $teacherPerms['my_results']  = $view;                                    // myrResolve() pins them to the years they actually held that student's section

    $studentPerms = array_fill_keys($pages, $none);
    $studentPerms['dashboard'] = $view;
    $studentPerms['my_results'] = $view;

    // principal / head teacher: academic authority, zero system admin. no delete anywhere — heads correct, they don't erase
    $edit = ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0];
    $principalPerms = array_fill_keys($pages, $none);
    foreach (['dashboard', 'marks_entry', 'my_results', 'logs', 'broadsheet', 'attendance', 'timetable'] as $pk) $principalPerms[$pk] = $view; // watch entry, never type marks
    foreach (['students', 'teachers', 'classes', 'subjects', 'results'] as $pk) $principalPerms[$pk] = $edit;
    $principalPerms['result_settings'] = ['v' => 1, 'a' => 0, 'e' => 1, 'd' => 0]; // grading + card policy, no new schemes
    $principalPerms['fees']            = ['v' => 1, 'a' => 0, 'e' => 1, 'd' => 0]; // may hold/release, never erase a ledger row

    // platform operator: the console and nothing else. NO academic pages — a super admin reaches
    // school data only by impersonating into it, which is audited. keeps the blast radius small.
    $superPerms = array_fill_keys($pages, $none) + array_fill_keys($platformPages, $full);
    foreach (['dashboard', 'logs'] as $pk) $superPerms[$pk] = $view;
    $superPerms['users']  = $full;   // needs to mint the first admin of a new school
    $superPerms['backup'] = $full;   // the whole-database dump belongs to the operator alone
    // campuses are PROVISIONING, not academic data — "up to 3 branches" is what a plan sells, so the
    // operator has to be able to see and repair the branch list of a school it is supporting.
    $superPerms['branches'] = $full;
    // SMTP, OAuth, the push keys, maintenance mode and the SaaS switch itself are all the OPERATOR's
    // settings — this file already copies them up to the platform row for exactly that reason. Without
    // these three the operator could not open the pages that own them, and every "fix it here" link on
    // its own dashboard bounced straight back to the dashboard.
    $superPerms['settings']    = $full;
    $superPerms['smtp_setup']  = $full;
    $superPerms['oauth_setup'] = $full;

    // branch admin: the school admin's academic matrix, pinned to ONE branch by ormsBranchLock().
    // no system pages, no delete — the branch runs its academics, the school owns its configuration.
    $branchPerms = array_fill_keys($pages, $none) + array_fill_keys($platformPages, $none);
    foreach (['dashboard', 'marks_entry', 'my_results', 'broadsheet', 'attendance', 'timetable'] as $pk) $branchPerms[$pk] = $view;
    foreach (['students', 'teachers', 'classes', 'subjects', 'results'] as $pk) $branchPerms[$pk] = $edit;

    // cols: key, label, color, sort, is_super, hidden_signup, perms  (all hidden from signup — admin creates accounts)
    $seedRoles = [
        ['Super Admin',  'App Owner',    '#0b1f3a', -2, 1, 1, $superPerms],
        ['School Owner', 'School Owner', '#0b6b3a', -1, 0, 1, $ownerPerms],
        ['Admin',        'Admin',        '#155724', 0, 1, 1, $adminPerms],
        ['Principal',    'Principal',    '#6f42c1', 1, 0, 1, $principalPerms],
        ['Branch Admin', 'Branch Admin', '#b45309', 2, 0, 1, $branchPerms],
        ['Teacher',      'Teacher',      '#0074D9', 3, 0, 1, $teacherPerms],
        ['Student',      'Student',      '#6c757d', 4, 0, 1, $studentPerms],
    ];
    // older installs seeded Teacher=1/Student=2 — push them down so Principal sits second everywhere
    $conn->query("UPDATE roles SET sort_order = 2 WHERE role_key = 'Teacher' AND sort_order <> 2");
    $conn->query("UPDATE roles SET sort_order = 3 WHERE role_key = 'Student' AND sort_order <> 3");
    $conn->query("UPDATE roles SET description = 'Head Teacher — school-wide academic authority, no system administration'
                  WHERE role_key = 'Principal' AND (description IS NULL OR description = '')");

    // roles are per school now (pk = school_id, role_key). school 0 holds the template set that
    // new schools are cloned from; Super Admin lives ONLY at school 0.
    $selRole = $conn->prepare("SELECT permissions FROM roles WHERE school_id = ? AND role_key = ? LIMIT 1");
    $insRole = $conn->prepare("INSERT IGNORE INTO roles (school_id, role_key, label, color, sort_order, is_super, hidden_signup, permissions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $updRole = $conn->prepare("UPDATE roles SET permissions = ? WHERE school_id = ? AND role_key = ?");
    if (!$selRole || !$insRole || !$updRole) {
        $say('error', 'Roles table unavailable: ' . $conn->error);
        $seedRoles = []; // skip the loop, the rest of the schema still runs
    }

    // school 0 (template) + every existing tenant
    $roleScopes = [0];
    if ($rs = $conn->query("SELECT id FROM schools ORDER BY id ASC")) {
        while ($r = $rs->fetch_row()) $roleScopes[] = (int)$r[0];
    }

    $seededN = 0; $mergedN = 0;
    foreach ($roleScopes as $rsid) {
        foreach ($seedRoles as [$rkey, $label, $color, $sort, $super, $hidden, $defPerms]) {
            if ($rkey === 'Super Admin' && $rsid !== 0) continue;   // operator is platform-only
            $selRole->bind_param("is", $rsid, $rkey);
            $selRole->execute();
            $res = $selRole->get_result();
            $row = $res ? $res->fetch_assoc() : null;

            if (!$row) { // fresh role
                $json = json_encode($defPerms);
                $insRole->bind_param("isssiiis", $rsid, $rkey, $label, $color, $sort, $super, $hidden, $json);
                $insRole->execute();
                $seededN++;
                continue;
            }

            // existing role: merge ONLY the missing page keys, admin edits win
            $cur = json_decode((string)$row['permissions'], true) ?: [];
            $missing = array_diff_key($defPerms, $cur);
            if (!$missing) continue;
            $json = json_encode($cur + $defPerms);
            $updRole->bind_param("sis", $json, $rsid, $rkey);
            $updRole->execute();
            $mergedN++;
        }
    }
    $say('success', "Roles: {$seededN} seeded, {$mergedN} merged across " . count($roleScopes) . ' scope(s) incl. the school-0 template');
    $selRole && $selRole->close();
    $insRole && $insRole->close();
    $updRole && $updRole->close();

    // parents sign in with the student's login — say so wherever the role label shows.
    // only the untouched default flips; a school's own relabel stays
    $conn->query("UPDATE roles SET label = 'Student / Parent' WHERE role_key = 'Student' AND label = 'Student'");
    if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' Student role label(s) rebranded "Student / Parent"');
    // existing installs seeded the operator as "Super Admin" — the key stays (every gate reads it),
    // only what a human sees changes. -2 keeps it above School Owner in every picker.
    $conn->query("UPDATE roles SET label = 'App Owner' WHERE role_key = 'Super Admin' AND label = 'Super Admin'");
    $conn->query("UPDATE roles SET sort_order = -2 WHERE role_key = 'Super Admin' AND sort_order <> -2");
    $conn->query("UPDATE roles SET description = 'Platform operator — owns the SaaS: schools, plans, gateways, revenue'
                  WHERE role_key = 'Super Admin' AND (description IS NULL OR description = '')");
    $conn->query("UPDATE roles SET description = 'Tenant owner — the school''s subscription, plan and invoices'
                  WHERE role_key = 'School Owner' AND (description IS NULL OR description = '')");

    // the merge above fills only MISSING page keys, and 'branches' has existed (denied) in the operator
    // row since the SaaS build — so the grant has to be flipped explicitly, once, on existing installs.
    if ($rs = $conn->query("SELECT permissions FROM roles WHERE school_id = 0 AND role_key = 'Super Admin' LIMIT 1")) {
        $row = $rs->fetch_assoc();
        $perm = $row ? (json_decode((string) $row['permissions'], true) ?: []) : [];
        $grant = [];
        foreach (['branches', 'settings', 'smtp_setup', 'oauth_setup'] as $pk) {
            if (empty($perm[$pk]['v'])) { $perm[$pk] = $full; $grant[] = $pk; }
        }
        if ($perm && $grant) {
            $json = json_encode($perm);
            if ($st = $conn->prepare("UPDATE roles SET permissions = ? WHERE school_id = 0 AND role_key = 'Super Admin'")) {
                $st->bind_param('s', $json);
                $st->execute();
                $st->close();
                $say('success', 'App Owner granted: ' . implode(', ', $grant) .
                     ' — provisioning and platform configuration are the operator\'s, not a school\'s');
            }
        }
    }

    // same story for Teacher + my_results: the key has existed (denied) since the first roles seed,
    // so the merge above skips it. my_results already carries a teacher branch — myrResolve() only
    // returns the years that teacher actually held the student's section — it was just unreachable.
    $tFixed = 0;
    if ($rs = $conn->query("SELECT school_id, permissions FROM roles WHERE role_key = 'Teacher'")) {
        $upd = $conn->prepare("UPDATE roles SET permissions = ? WHERE school_id = ? AND role_key = 'Teacher'");
        while ($upd && ($row = $rs->fetch_assoc())) {
            $perm = json_decode((string)$row['permissions'], true) ?: [];
            if (!$perm || !empty($perm['my_results']['v'])) continue;
            $perm['my_results'] = $view;
            $json = json_encode($perm);
            $rsid = (int)$row['school_id'];
            $upd->bind_param('si', $json, $rsid);
            $upd->execute();
            $tFixed++;
        }
        $upd && $upd->close();
    }
    if ($tFixed) $say('success', "Teacher granted my_results view in {$tFixed} scope(s) — read a student's card for the years they taught them");


    // ---- owners: every tenant gets exactly one School Owner ----------------------------------
    // existing installs stamped owner_user_id but left that account on 'Admin', so the new role
    // would exist with nobody in it. Backfill the stamp first (a school with no owner takes its
    // oldest admin), then promote. Additive: no other Admin is touched.
    // a real School Owner always wins the stamp; the oldest Admin only fills a school that has none
    $conn->query("UPDATE schools s
                  JOIN (SELECT school_id, MIN(id) AS uid FROM users
                        WHERE role = 'School Owner' AND school_id > 0 GROUP BY school_id) f
                    ON f.school_id = s.id
                  SET s.owner_user_id = f.uid");
    $conn->query("UPDATE schools s
                  JOIN (SELECT school_id, MIN(id) AS uid FROM users
                        WHERE role = 'Admin' AND school_id > 0 GROUP BY school_id) f
                    ON f.school_id = s.id
                  SET s.owner_user_id = f.uid
                  WHERE s.owner_user_id IS NULL");
    // ONLY in platform mode. A single-school install was never sold a subscription, so there is
    // nothing for an owner to own — renaming its admin would be churn with a lockout risk attached.
    if (installerPlatformMode()) {
        $conn->query("UPDATE users u JOIN schools s ON s.owner_user_id = u.id AND s.id = u.school_id
                      SET u.role = 'School Owner' WHERE u.role = 'Admin'");
        if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' school owner(s) promoted from Admin to School Owner');
    } else {
        $say('info', 'Single-school install — School Owner seeded but nobody promoted (platform_mode is 0)');
    }

    // ---------------------------------------------------------------- 6b. grading scheme (only when empty)
    $gRes = $conn->query("SELECT COUNT(*) AS c FROM grading_scheme");
    $gradeCount = $gRes ? (int)$gRes->fetch_assoc()['c'] : -1;
    if ($gradeCount < 0) {
        $say('error', 'grading_scheme unavailable: ' . $conn->error);
    } elseif ($gradeCount === 0) {
        // grade, min%, max%, point, remarks, order
        $bands = [
            ['A+', 90, 100, 4.0, 'Outstanding', 1],
            ['A',  80,  89, 3.7, 'Excellent',   2],
            ['B',  70,  79, 3.0, 'Very Good',   3],
            ['C',  60,  69, 2.3, 'Good',        4],
            ['D',  50,  59, 1.7, 'Fair',        5],
            ['E',  40,  49, 1.0, 'Pass',        6],
            ['F',   0,  39, 0.0, 'Fail',        7],
        ];
        $g = $rm = '';
        $mn = $mx = $gp = 0.0;
        $so = 0;
        $gStmt = $conn->prepare("INSERT IGNORE INTO grading_scheme (grade, min_percent, max_percent, grade_point, remarks, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $gStmt->bind_param("sdddsi", $g, $mn, $mx, $gp, $rm, $so);
        foreach ($bands as [$g, $mn, $mx, $gp, $rm, $so]) $gStmt->execute();
        $gStmt->close();
        $say('success', count($bands) . ' default grade bands seeded (A+ … F)');
    } else {
        $say('info', 'Grading scheme already configured (' . $gradeCount . ' bands) — left untouched');
    }

    // ---------------------------------------------------------------- 6b2. grading sets + assessment schemes
    $say('info', 'Grading sets & assessment schemes');

    // set 1 always exists — grading_scheme.set_id defaults to 1, so no legacy band is ever orphaned
    $conn->query("INSERT IGNORE INTO grading_sets (id, name, description, is_default, is_active)
                  VALUES (1, 'Default Scheme', 'The school-wide grading scheme.', 1, 1)");
    $madeDefSet = $conn->affected_rows > 0;
    $say($madeDefSet ? 'success' : 'info', $madeDefSet ? 'Default grading set created — every existing band belongs to it' : 'Default grading set already present');

    // the remark we already print is the closest thing to a parent-facing meaning — seed from it
    $conn->query("UPDATE grading_scheme SET interpretation = remarks WHERE interpretation IS NULL AND remarks IS NOT NULL");
    if ($conn->affected_rows > 0) $say('success', $conn->affected_rows . ' grade band(s) given an interpretation from their remark');

    // starter sets: only on the run that creates grading_sets, so an admin's own sets are never touched
    if ($gsFresh) {
        // name, desc, bands[grade, min, max, point, remarks, interpretation, is_fail, order]
        $starterSets = [
            ['Lower Primary (Descriptive)', 'Descriptive bands for the early years — what the child can do, not just a mark.', [
                ['A', 80, 100, 4.0, 'Excellent',  'Excellent — has mastered the skill',  0, 1],
                ['B', 65,  79, 3.0, 'Good',       'Good — works well with little help',  0, 2],
                ['C', 50,  64, 2.0, 'Fair',       'Fair — needs some support',           0, 3],
                ['D', 40,  49, 1.0, 'Developing', 'Developing — needs regular support',  0, 4],
                ['E',  0,  39, 0.0, 'Beginning',  'Beginning — needs close support',     1, 5],
            ]],
            ['Senior School (A1–F9)', 'Nine-point senior grading, A1 down to F9.', [
                ['A1', 75, 100, 4.0, 'Excellent', 'Excellent', 0, 1],
                ['B2', 70,  74, 3.6, 'Very Good', 'Very Good', 0, 2],
                ['B3', 65,  69, 3.2, 'Good',      'Good',      0, 3],
                ['C4', 60,  64, 2.8, 'Credit',    'Credit',    0, 4],
                ['C5', 55,  59, 2.4, 'Credit',    'Credit',    0, 5],
                ['C6', 50,  54, 2.0, 'Credit',    'Credit',    0, 6],
                ['D7', 45,  49, 1.6, 'Pass',      'Pass',      0, 7],
                ['E8', 40,  44, 1.2, 'Pass',      'Pass',      0, 8],
                ['F9',  0,  39, 0.0, 'Fail',      'Fail',      1, 9],
            ]],
        ];

        $insSet  = $conn->prepare("INSERT IGNORE INTO grading_sets (name, description, is_default, is_active) VALUES (?, ?, 0, 1)");
        $insBand = $conn->prepare("INSERT IGNORE INTO grading_scheme (set_id, grade, min_percent, max_percent, grade_point, remarks, interpretation, is_fail, sort_order)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$insSet || !$insBand) {
            $say('error', 'Starter grading sets skipped: ' . $conn->error);
        } else {
            $sName = $sDesc = '';
            $insSet->bind_param('ss', $sName, $sDesc);
            $setId = $bFail = $bSort = 0;
            $bGrade = $bRem = $bInt = '';
            $bMin = $bMax = $bPoint = 0.0;
            $insBand->bind_param('isdddssii', $setId, $bGrade, $bMin, $bMax, $bPoint, $bRem, $bInt, $bFail, $bSort);
            $nSets = $nBands = 0;
            foreach ($starterSets as [$sName, $sDesc, $bands]) {
                $insSet->execute();
                $setId = (int)$insSet->insert_id;
                if (!$setId) continue; // name already taken -> leave it alone
                foreach ($bands as [$bGrade, $bMin, $bMax, $bPoint, $bRem, $bInt, $bFail, $bSort]) { $insBand->execute(); $nBands++; }
                $nSets++;
            }
            $insSet->close();
            $insBand->close();
            $say('success', $nSets . ' grading set(s) seeded with ' . $nBands . ' band(s) — pick one per class on the class form');
        }
    }

    // starter CA/exam splits: only on the run that creates assessment_schemes
    if ($asFresh) {
        // name, desc, components[name, max_marks, weight, is_exam, order] — weights sum to 100
        $starterSchemes = [
            ['50 / 50 (CA & Exam)', 'Half continuous assessment, half end of term exam.', [
                ['Class Exercises',   20, 15, 0, 1],
                ['Project Work',      20, 15, 0, 2],
                ['Mini Exam',         20, 20, 0, 3],
                ['End of Term Exam', 100, 50, 1, 4],
            ]],
            ['30 / 70 (CA & Exam)', 'Thirty percent continuous assessment, seventy percent exam.', [
                ['Class Work',        30, 15, 0, 1],
                ['Mini Exam',         30, 15, 0, 2],
                ['End of Term Exam', 100, 70, 1, 3],
            ]],
            ['40 / 60 (CA & Exam)', 'One continuous assessment score plus the end of term exam.', [
                ['Continuous Assessment',  40, 40, 0, 1],
                ['End of Term Exam',      100, 60, 1, 2],
            ]],
        ];

        $insSch = $conn->prepare("INSERT IGNORE INTO assessment_schemes (name, description, is_active) VALUES (?, ?, 1)");
        $insCmp = $conn->prepare("INSERT IGNORE INTO assessment_components (scheme_id, name, max_marks, weight_percent, is_exam, sort_order)
                                  VALUES (?, ?, ?, ?, ?, ?)");
        if (!$insSch || !$insCmp) {
            $say('error', 'Starter assessment schemes skipped: ' . $conn->error);
        } else {
            $chName = $chDesc = '';
            $insSch->bind_param('ss', $chName, $chDesc);
            $schId = $cExam = $cSort = 0;
            $cName = '';
            $cMax = $cWeight = 0.0;
            $insCmp->bind_param('isddii', $schId, $cName, $cMax, $cWeight, $cExam, $cSort);
            $nSch = $nCmp = 0;
            foreach ($starterSchemes as [$chName, $chDesc, $comps]) {
                $insSch->execute();
                $schId = (int)$insSch->insert_id;
                if (!$schId) continue;
                foreach ($comps as [$cName, $cMax, $cWeight, $cExam, $cSort]) { $insCmp->execute(); $nCmp++; }
                $nSch++;
            }
            $insSch->close();
            $insCmp->close();
            $say('success', $nSch . ' assessment scheme(s) seeded with ' . $nCmp . ' component(s) — CA/exam weighting is ready to attach to a class');
        }
    }

    // ---------------------------------------------------------------- 6c. settings
    $say('info', 'System settings');

    // ---- ONE currency for the whole install.
    // 🚨 It lives on the PLATFORM row (school 0) and every role reads it there. The seed below has no
    // school_id, so the column default (1) used to park currency_code on tenant #1 — where it
    // SHADOWED the operator's row for that tenant and was invisible to every other one. Billing had
    // its own separate knob on top, and the two seeds disagreed out of the box (USD vs PKR).
    // This picks the value the install actually meant, pins it to school 0, and clears every
    // school-scoped copy so nothing can shadow it again. Re-running matches nothing.
    $say('info', 'Currency');

    $pick = static function (mysqli $c, string $sql): string {
        $r   = $c->query($sql);
        $row = $r ? $r->fetch_row() : null;
        return $row ? trim((string) $row[0]) : '';
    };
    // a human edit beats a seed, the platform row beats a tenant, the lowest tenant beats the rest.
    // updated_by is NULL for installer/seed writes and set for admin edits
    $ord    = "ORDER BY (updated_by IS NOT NULL) DESC, (school_id = 0) DESC, school_id ASC LIMIT 1";
    $curRaw = $pick($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'currency_code' $ord");
    if ($curRaw === '') {   // pre-registry install: the symbol itself WAS the setting
        $curRaw = $pick($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'currency_symbol' $ord");
    }
    $curCode = ormsCurrencyCode($curRaw);

    // billing_currency was the second knob. Reconcile once: whichever one a person actually chose
    // wins, and if nobody chose either, real money settles it — billing has invoices behind it
    $bilRaw = $pick($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'billing_currency' $ord");
    if ($bilRaw !== '' && ormsCurrencyCode($bilRaw) !== $curCode) {
        $bilCode   = ormsCurrencyCode($bilRaw);
        $curEdited = $pick($conn, "SELECT 1 FROM system_settings WHERE setting_key = 'currency_code'    AND updated_by IS NOT NULL LIMIT 1") !== '';
        $bilEdited = $pick($conn, "SELECT 1 FROM system_settings WHERE setting_key = 'billing_currency' AND updated_by IS NOT NULL LIMIT 1") !== '';
        $paid = $pick($conn, "SELECT 1 FROM subscription_payments LIMIT 1") !== ''
             || $pick($conn, "SELECT 1 FROM billing_invoices WHERE status = 'Paid' LIMIT 1") !== '';
        $win = ($curEdited && !$bilEdited) ? $curCode
             : ((($bilEdited && !$curEdited) || $paid) ? $bilCode : $curCode);
        $say('success', "Display currency ($curCode) and billing currency ($bilCode) disagreed — the install now uses $win everywhere"
                      . ($paid && $win === $bilCode ? ' (money has already changed hands in it)' : '')
                      . '. Wrong one? Change it in Settings, no db edit needed.');
        $curCode = $win;
    }
    $curSym = ormsCurrencies()[$curCode][0];

    // clear the school-scoped copies FIRST — the operator's choice is the only currency now, so an
    // admin's old edit is void. Deleting before the pin also means a half-swapped unique key (5f)
    // cannot make the write below land on tenant #1 instead of school 0
    $shadow = [];
    if ($r = $conn->query("SELECT DISTINCT setting_value FROM system_settings
                           WHERE school_id <> 0 AND setting_key = 'currency_code'")) {
        while ($x = $r->fetch_row()) $shadow[] = (string) $x[0];
    }
    $conn->query("DELETE FROM system_settings WHERE school_id <> 0
                  AND setting_key IN ('currency_code', 'currency_symbol', 'billing_currency')");
    if ($conn->affected_rows > 0) {
        $say('success', $conn->affected_rows . ' school-scoped currency row(s) removed'
             . ($shadow ? ' (was ' . implode(', ', array_slice($shadow, 0, 5)) . ')' : '')
             . ' — the platform currency now reaches every school');
    }

    // pin all three to the platform row. ON DUPLICATE, not INSERT IGNORE: a school-0 row already
    // holding the old seeded default has to be CORRECTED, not kept. updated_by stays NULL — this
    // is an installer write, not a person's choice
    if ($st = $conn->prepare("INSERT INTO system_settings (school_id, setting_key, setting_value)
                              VALUES (0, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")) {
        $k = $v = '';
        $st->bind_param('ss', $k, $v);
        foreach (['currency_code' => $curCode, 'currency_symbol' => $curSym, 'billing_currency' => $curCode] as $key => $val) {
            $k = $key; $v = $val; $st->execute();
        }
        $st->close();
    }
    $say('success', "Currency: $curCode ($curSym) — one currency, every role, billing included");

    // per-school billing overrides are no longer read. The column stays (it is the only record of
    // what a school used to be billed in) but the platform currency applies to everyone now
    $legacy = (int) $pick($conn, "SELECT COUNT(*) FROM schools WHERE billing_currency IS NOT NULL AND billing_currency <> ''");
    if ($legacy > 0) $say('info', $legacy . ' school(s) still carry a per-school billing currency — kept as history, not used');

    // the three money tables defaulted to a hardcoded 'PKR'. Every write names its currency, but a
    // default that contradicts the install is a trap for the next INSERT that forgets
    foreach (['school_subscriptions', 'subscription_payments', 'billing_invoices'] as $mt) {
        $def = schemaProbe($conn, "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'currency' LIMIT 1", [$mt]);
        if ($def === null || $def === $curCode) continue;                    // absent, or already right
        if ($conn->query("ALTER TABLE `$mt` MODIFY `currency` VARCHAR(10) NOT NULL DEFAULT '"
                         . $conn->real_escape_string($curCode) . "'")) {
            $say('success', "$mt.currency default is $curCode (was $def)");
        }
    }

    seedSettings($conn, [
        // site branding
        'site_name'                  => 'Online Result Management',
        'site_logo'                  => 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg',
        'copyright_text'             => '&copy; 2026 Online Result Management. All rights reserved.',
        // currency_code / currency_symbol / billing_currency are NOT here — they are platform-owned
        // and pinned to school 0 in the block above. seedSettings() has no school_id, so anything in
        // this array lands on tenant #1 and would shadow the platform row for that tenant
        'default_language'           => 'en',
        'show_forgot_password'       => '1',
        'maintenance_mode'           => '0',
        // THE saas switch. 0 = behave exactly like the single-school app it has always been:
        // no school code on login, subscription gate inert, school admins keep the installer.
        // flip to 1 only once a Super Admin exists — see MEMORY.md.
        'platform_mode'              => '0',
        'platform_name'              => 'Result SaaS',
        'allow_school_signup'        => '0',   // self-serve "register your school" — off until wanted
        'platform_whatsapp'          => '923224083545',
        // billing / self-serve checkout. gateway secrets belong to the PLATFORM row (school 0) alone —
        // ormsPlatformSetting() reads school 0 explicitly, so a school with 'settings' edit rights can
        // never shadow a key and route its own checkout at somebody else's merchant account.
        'billing_enabled'            => '0',   // master switch for self-serve checkout
        'billing_test_mode'          => '1',
        'billing_invoice_prefix'     => 'INV',
        'gw_manual_enabled'          => '1',   // always available: no keys, no gateway, still a real invoice
        'gw_manual_label'            => 'Bank Transfer',
        'gw_manual_instructions'     => "Transfer the invoice amount to:\nBank: -\nTitle: -\nAccount / IBAN: -\n\nThen send the receipt on WhatsApp. Activation is manual, usually same day.",
        'gw_stripe_enabled'          => '0',
        'gw_stripe_pk'               => '',
        'gw_stripe_sk'               => '',
        'gw_stripe_webhook_secret'   => '',
        'allow_user_profile_uploads' => '1',
        // smtp
        'smtp_enabled'               => '0',
        'smtp_host'                  => '',
        'smtp_port'                  => '587',
        'smtp_username'              => '',
        'smtp_password'              => '',
        'smtp_from_email'            => '',
        'smtp_from_name'             => 'Online Result Management',
        'smtp_encryption'            => 'tls',
        'email_verification_enabled' => '0',
        // google oauth
        'google_oauth_enabled'       => '0',
        'google_client_id'           => '',
        'google_client_secret'       => '',
        'google_redirect_uri'        => '',
        // result card branding + options (result_settings.php owns these)
        'result_school_name'         => 'Your School Name',
        'result_school_address'      => '',
        'result_school_phone'        => '',
        'result_logo'                => '',
        'result_footer_note'         => 'This is a computer generated result card.',
        'result_signature_left'      => 'Class Teacher',
        'result_signature_right'     => 'Principal',
        'result_show_position'       => '1',
        'result_show_gpa'            => '1',
        'result_show_photo'          => '1',
        // card customisation — all optional, every one honoured by ormsRenderResultCard()
        'result_title'               => 'Result Card',
        'result_accent_color'        => '#001f3f',
        'result_template'            => 'classic',
        'result_show_dob'            => '1',
        'result_show_grade_col'      => '1',
        'result_show_remarks_col'    => '1',
        'result_show_failed_line'    => '1',
        'result_show_signatures'     => '1',
        'result_show_footer'         => '1',
        'result_show_qr'             => '1',
        // head teacher: name + signature image print above the right-hand signature label
        'result_principal_name'        => '',
        'result_principal_designation' => 'Principal',
        'result_principal_signature'   => '',
        'result_show_principal_sign'   => '1',
        'result_show_principal_remark' => '1',
        // grade key, attendance block + fee withholding
        'result_show_attendance'     => '1',
        'result_show_grade_key'      => '1',
        'result_show_ca_columns'     => '1',
        'result_grade_key_note'      => 'Grades above show how each band is interpreted.',
        'withhold_on_arrears'        => '0',
        'arrears_threshold'          => '0',
        'withhold_message'           => 'This result has been withheld by the school. Please contact the school office.',
        'attendance_default_days'    => '0',
        // publish gate — section must be Approved before it goes live. Admin may approve too, so no install can lock itself out
        'require_principal_approval' => '1',
        'student_default_password'   => 'student123',
        'teacher_default_password'   => 'teacher123',
        'admission_no_prefix'        => 'STU',
        'allow_public_signup'        => '0',
    ], $log);

    // ---------------------------------------------------------------- 6d. VAPID (generated ONCE — regenerating kills every subscription)
    $vk = 'vapid_public_key';
    $vStmt = $conn->prepare("SELECT 1 FROM system_settings WHERE setting_key = ? LIMIT 1");
    $vStmt->bind_param("s", $vk);
    $vStmt->execute();
    $haveVapid = (bool)$vStmt->get_result()->fetch_row();
    $vStmt->close();

    if ($haveVapid) {
        $say('info', 'VAPID keys already exist (kept — never regenerated)');
    } else {
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($ec) {
            openssl_pkey_export($ec, $privPem);
            $det = openssl_pkey_get_details($ec);
            // public key -> raw 65-byte P-256 point -> base64url (the applicationServerKey)
            $pubRaw = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);
            seedSettings($conn, [
                'vapid_public_key'  => rtrim(strtr(base64_encode($pubRaw), '+/', '-_'), '='),
                'vapid_private_key' => $privPem,
                'vapid_subject'     => 'mailto:admin@example.com',
                'enable_web_push'   => '1',
            ]);
            $say('success', 'VAPID keys generated (web push enabled)');
        } else {
            $say('error', 'OpenSSL EC unavailable — set the vapid_* settings manually to use web push');
        }
    }

    // ---------------------------------------------------------------- 6e. upload dirs + security
    $say('info', 'Upload directories');

    foreach (['uploads', 'uploads/profiles', 'uploads/branding'] as $d) {
        $path = __DIR__ . '/' . $d;
        if (is_dir($path)) { $say('info', 'Directory "' . $d . '/" already exists'); continue; }
        $made = @mkdir($path, 0755, true);
        $say($made ? 'success' : 'error', 'Directory "' . $d . '/" ' . ($made ? 'created' : 'could NOT be created'));
    }

    $probe = __DIR__ . '/uploads/profiles/.writetest';
    if (@file_put_contents($probe, 'ok') !== false) { @unlink($probe); $say('success', 'Upload directory is writable'); }
    else $say('error', 'Upload directory is not writable — check folder permissions');

    // block script execution inside uploads/
    // this string IS the source of truth — applyUpdates rewrites uploads/.htaccess from it on every
    // run, so editing the file by hand is pointless. carries both apache 2.2 and 2.4 syntax: on 2.4
    // "deny from all" alone is inert unless mod_access_compat happens to be loaded.
    $rules = "# Protect uploads directory\n"
           . "Options -Indexes\n\n"
           . "<FilesMatch \"\\.(php|php[3-8]|phtml|phar|pl|py|jsp|asp|aspx|htm|html|shtml|sh|cgi|htaccess)$\">\n"
           . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
           . "    <IfModule !mod_authz_core.c>\n        Order Allow,Deny\n        Deny from all\n    </IfModule>\n"
           . "</FilesMatch>\n"
           . "\n# Allow image files only\n"
           . "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp|svg)$\">\n"
           . "    <IfModule mod_authz_core.c>\n        Require all granted\n    </IfModule>\n"
           . "    <IfModule !mod_authz_core.c>\n        Allow from all\n    </IfModule>\n"
           . "</FilesMatch>\n"
           . "\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n";
    $htaccess = __DIR__ . '/uploads/.htaccess';
    if (is_file($htaccess) && file_get_contents($htaccess) === $rules) $say('info', 'Security .htaccess already in place');
    else $say(@file_put_contents($htaccess, $rules) !== false ? 'success' : 'error', 'Security .htaccess in uploads/');

    $say('success', 'Schema is up to date');
}

// run-if-main: required by setup.php -> stop here, functions are enough
if (realpath(__FILE__) !== realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) return;

// standalone page = a live DDL endpoint. bootstrap or signed-in admin only, and it exits
// before anything (db name included) is echoed to a visitor
[$updAllowed] = installerAllowed();
if (!$updAllowed) installerLock('Already Installed', 'The database updater is restricted to signed-in administrators.');

// DDL runs on a csrf-signed POST only. on GET this page just shows the button — a bare GET used
// to apply the schema, so a lured top-level navigation (samesite=lax still sends the cookie) was
// enough to re-run the updater on someone else's behalf.
$updPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($updPost && !validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    installerLock('Security Check Failed', 'That request could not be verified. Reopen this page and try again.');
}
$updMode  = (string)($_POST['mode'] ?? 'schema');
$updRun   = $updPost && $updMode === 'schema';
$ownerRun = $updPost && $updMode === 'app_owner';

/**
 * App Owner bootstrap. The platform has exactly ONE operator account, and this is the only door in:
 * schools.php hands out tenants but needs an operator to already exist, and users.php refuses to
 * mint a second Super Admin. Without this step a fresh install can never become a SaaS.
 */
function installerAppOwners(): int {
    try {
        $r = getDBConnection()->query("SELECT COUNT(*) FROM users WHERE school_id = 0 AND role = 'Super Admin'");
        return $r ? (int)$r->fetch_row()[0] : 0;
    } catch (Throwable $e) { return 0; }
}

$ownerMsg = $ownerErr = '';
if ($ownerRun) {
    $aoUser = trim((string)($_POST['ao_user'] ?? ''));
    $aoPass = (string)($_POST['ao_pass'] ?? '');
    $aoMail = trim((string)($_POST['ao_email'] ?? ''));
    try {
        if (installerAppOwners() > 0)                              $ownerErr = 'An App Owner already exists — the platform has exactly one.';
        elseif (!preg_match('/^[A-Za-z0-9._-]{4,50}$/', $aoUser))  $ownerErr = 'Username must be 4-50 letters, digits, dot, dash or underscore.';
        elseif (strlen($aoPass) < 8)                               $ownerErr = 'Password must be at least 8 characters.';
        elseif ($aoMail !== '' && !filter_var($aoMail, FILTER_VALIDATE_EMAIL)) $ownerErr = 'That email address is not valid.';
        else {
            $conn = getDBConnection();
            // school_id 0 is what makes it the platform operator — sid() treats 0 as legitimate for
            // this account alone, and readRoles() keeps the Super Admin matrix at school 0 only
            $st = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, is_active, email_verified, must_change_password, school_id)
                                  VALUES (?, ?, ?, ?, 'Super Admin', 1, 1, 0, 0)");
            if (!$st) throw new RuntimeException($conn->error);
            $aoName = 'App Owner';
            $aoHash = password_hash($aoPass, PASSWORD_DEFAULT);
            $aoMailF = $aoMail !== '' ? $aoMail : ($aoUser . '@platform.local');
            $st->bind_param('ssss', $aoUser, $aoName, $aoMailF, $aoHash);
            if ($st->execute()) {
                $ownerMsg = 'App Owner "' . $aoUser . '" created. Sign in as that account, then switch platform_mode on in Site Settings.';
                logActivity((int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['username'] ?? 'installer'),
                    'App Owner Created', 'Platform operator account "' . $aoUser . '" created from update_setup.php');
            } else {
                $ownerErr = 'Could not create the account: ' . $conn->error;
            }
            $st->close();
        }
    } catch (Throwable $ex) { $ownerErr = 'Could not create the account: ' . $ex->getMessage(); }
}

// standalone updater page
$logIcons = ['success' => 'fa-check-circle', 'info' => 'fa-info-circle', 'error' => 'fa-times-circle'];
$pageLog = function (string $type, string $msg) use ($logIcons): void {
    $t = isset($logIcons[$type]) ? $type : 'info';
    echo '<div class="log-item log-' . $t . '"><i class="fas ' . $logIcons[$t] . '"></i> ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
};
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
    <title>Database Update</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="setup-wrapper">
    <div class="setup-container">
        <h2><i class="fas fa-database"></i> Database Update</h2>
        <p class="subtitle">Adding anything that is missing &mdash; existing data is never touched.</p>
        <hr>

        <?php if ($ownerMsg !== ''): ?>
            <div class="log-item log-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($ownerMsg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($ownerErr !== ''): ?>
            <div class="log-item log-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($ownerErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (installerAppOwners() === 0): ?>
        <div class="setup-note">
            <h3><i class="fas fa-user-shield"></i> Create the App Owner</h3>
            <p class="subtitle">The single account that owns the platform &mdash; schools, plans, payment gateways and revenue.
               It lives outside every school and is the only role that can hand out tenants. There is exactly one, and
               this page is the only place it can be created.</p>
            <form method="post" action="update_setup.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="mode" value="app_owner">
                <div class="form-group">
                    <label for="ao_user"><i class="fas fa-user"></i> Username</label>
                    <input type="text" id="ao_user" name="ao_user" required minlength="4" maxlength="50" placeholder="appowner">
                </div>
                <div class="form-group">
                    <label for="ao_pass"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="ao_pass" name="ao_pass" required minlength="8" placeholder="at least 8 characters">
                </div>
                <div class="form-group">
                    <label for="ao_email"><i class="fas fa-envelope"></i> Email <small>(optional)</small></label>
                    <input type="email" id="ao_email" name="ao_email" maxlength="100">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-shield"></i> Create App Owner</button>
            </form>
        </div>
        <hr>
        <?php endif; ?>

        <?php if (!$updRun): ?>
        <form method="post" action="update_setup.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-arrow-up-right-dots"></i> Apply Schema Updates
            </button>
        </form>
        <?php else: ?>
        <?php
        // create the db first if it's missing, then hand over to the app connection
        // try/catch: php 8.1 mysqli throws by default - a blank page here would be unusable (display_errors=0)
        try {
            // report OFF: createTable/addColumnIfMissing are written to return false and log the
            // failing table/column — exception mode would abort the whole run on one hiccup
            mysqli_report(MYSQLI_REPORT_OFF);
            $boot = @new mysqli(DB_HOST, DB_USER, DB_PASS);
            if ($boot->connect_error) {
                $pageLog('error', 'Connection failed: ' . $boot->connect_error);
            } elseif (!$boot->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                $pageLog('error', 'Could not create database "' . DB_NAME . '": ' . $boot->error);
            } else {
                $boot->close();
                $pageLog('success', 'Database "' . DB_NAME . '" ready and connected');
                applyUpdates(getDBConnection(), $pageLog);
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // back to app default
                logActivity((int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['username'] ?? 'installer'),
                    'Schema Update', 'update_setup.php applied the schema (additive, no data touched)');
                $pageLog('success', 'Update completed successfully');
            }
        } catch (Throwable $e) {
            $pageLog('error', 'Update aborted: ' . $e->getMessage());
            error_log('update_setup.php: ' . $e->getMessage());
        }
        ?>
        <?php endif; ?>

        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Go to Login Page
        </a>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    </div>

    <script>
    // theme toggle (standalone page - no sidebar.php here)
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
            document.body.classList.add('dark-mode');
            updateThemeIcon(true);
        }
    }

    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeIcon(isDark);
    }

    function updateThemeIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();
    </script>
</body>
</html>
