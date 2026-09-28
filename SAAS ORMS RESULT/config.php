<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Database Configuration File
 */

// Error handling - disable display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Changed to 0 for production security
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Database credentials — Auto-detect local development vs Hostinger production
$is_local_env = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'], true);

if ($is_local_env) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u612988177_sisgescolar');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // Hostinger Production (sistemagestionescolar.ceie.website)
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u612988177_sisgescolar');
    define('DB_USER', 'u612988177_ns5gescolar');
    define('DB_PASS', 'TU_PASSWORD_AQUI'); // Ingresa aquí la contraseña que asignaste al usuario de MySQL en Hostinger
}

// Security constants
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('MAX_OTP_ATTEMPTS', 5);     // wrong guesses before a 6-digit code is dead

// Auto-detect HTTPS (works on both XAMPP and Hostinger)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', $is_https ? 1 : 0);
    ini_set('session.cookie_samesite', $is_https ? 'Strict' : 'Lax');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 48);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Auto-login from Remember Me cookie
// runs on EVERY include, so it must never fatal — setup.php is loaded before the db exists
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    try {
        // soft probe first: no db / no tables -> skip silently so the installer can still run
        if (getDBConnection(true)) {
            $remembered_user = validateRememberToken(); // already refuses deactivated accounts
            if ($remembered_user) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $remembered_user['user_id'];
                $_SESSION['username'] = $remembered_user['username'];
                $_SESSION['role'] = $remembered_user['role'];
                $_SESSION['must_change_password'] = (int)($remembered_user['must_change_password'] ?? 0);
                // tenant stamp — without it sid() would fail closed and bounce a valid remembered login.
                // default 1 (not 0) matches ormsStampTenant: a pre-migration row carries no school_id, and
                // 0 would drop the session onto the platform, reading/writing every school's default settings.
                $_SESSION['school_id'] = (int)($remembered_user['school_id'] ?? 1);
                $_SESSION['branch_id'] = (int)($remembered_user['branch_id'] ?? 0);
                $_SESSION['is_super']  = ($remembered_user['role'] ?? '') === 'Super Admin';
                $_SESSION['LAST_ACTIVITY'] = time();
            }
        }
    } catch (Throwable $e) {
        // schema not ready yet, skip auto-login
    }
}

// Set HTTP security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Create database connection (singleton - reuses same connection per request)
// $soft = true -> return null instead of die() (auto-login probe / installer path)
function getDBConnection($soft = false) {
    static $conn = null;
    try {
        if ($conn === null || !@$conn->ping()) {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            if ($conn->connect_error) {
                error_log("Database connection failed: " . $conn->connect_error);
                $conn = null; // let a later call retry
                if ($soft) return null;
                die("Database connection failed. Please try again later.");
            }

            $conn->set_charset("utf8mb4");
        }
        return $conn;
    } catch (Exception $e) {
        $conn = null;
        error_log("Database error: " . $e->getMessage());
        if ($soft) return null;
        die("Database error occurred. Please contact administrator.");
    }
}

// ============================================
// Query Helpers — the ONLY way pages touch the DB (prepared, always)
// ============================================

// prepare + bind + execute. types auto-derived if omitted, never string-interpolated
function qPrep(string $sql, string $types, array $params) {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('SQL prepare failed: ' . $conn->error);
    if ($params) {
        if ($types === '') foreach ($params as $p) $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

// all rows as assoc arrays, [] if none
function qAll(string $sql, string $types = '', ...$params): array {
    $stmt = qPrep($sql, $types, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

// first row or null
function qOne(string $sql, string $types = '', ...$params): ?array {
    $stmt = qPrep($sql, $types, $params);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// first column of first row, or null
function qVal(string $sql, string $types = '', ...$params) {
    $stmt = qPrep($sql, $types, $params);
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? $row[0] : null;
}

// affected rows
function qExec(string $sql, string $types = '', ...$params): int {
    $stmt = qPrep($sql, $types, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

// new insert_id
function qInsert(string $sql, string $types = '', ...$params): int {
    $stmt = qPrep($sql, $types, $params);
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

// ============================================
// JSON Response Helpers — always exit, never leak html into json
// ============================================

function jsonOut(array $data): void {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit();
}

function jsonOk(array $extra = []): void {
    jsonOut(['success' => true] + $extra);
}

function jsonErr(string $message, array $extra = []): void {
    jsonOut(['success' => false, 'message' => $message] + $extra);
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// session token for ajax pages — same token login/signup use, created on demand
function csrfToken(): string {
    return generateCSRFToken();
}

// ajax post guard. validate only — token is NOT consumed, so every call on one page load passes
function requireCsrfJson(): void {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || $token === '' || !validateCSRFToken($token)) {
        jsonErr('Session expired. Please refresh the page.');
    }
}

// Session timeout check
function checkSessionTimeout() {
    // Check if force-logout flag is set
    try {
        if (checkForceLogout()) {
            removeUserSession();
            session_unset();
            session_destroy();
            return false;
        }
    } catch (Exception $e) {
        // user_sessions table may not exist yet
    }

    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
        try { removeUserSession(); } catch (Exception $e) {}
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();

    // Track session activity
    if (isset($_SESSION['user_id'])) {
        try { trackUserSession($_SESSION['user_id']); } catch (Exception $e) {}
    }

    return true;
}

// Input validation functions
function validateUsername($username) {
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) {
        return false;
    }
    // '-' and '.' are allowed on purpose: a student's login IS their admission number, and this app
    // issues those as STU-2026-0031. Letters/digits/_ only meant every student account it created
    // was rejected at the login form and could never sign in at all.
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        return false;
    }
    return $username;
}

function validatePassword($password) {
    if (strlen($password) < 6 || strlen($password) > 255) {
        return false;
    }
    return $password;
}

// request fingerprint — one place, reused by every audit column
function ormsIp(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function ormsUa(): ?string {
    return isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
}

// Rate limiting functions
// $school joins the key on purpose: every school has a user called "admin", so a username-only
// counter lets one school lock out every other school's admin (and lets a success at school A
// clear school B's failure count). $school is trailing/optional — old 1-arg calls still work.
// 🚨 the SOURCE IP is part of the key too, and must stay. keyed on the username alone, five wrong
// guesses from anywhere froze the real owner out of their own account for 15 minutes — a free
// denial of service against any username an attacker can read off a login page. Per (user, school,
// ip) the guesser only ever locks out their own host; the owner signs in from theirs untouched.
function checkLoginAttempts($username, $school = 0) {
    $lockout = LOGIN_LOCKOUT_TIME;
    $s  = (int)$school;
    $ip = ormsIp();
    // only failures count toward the lockout — a success row must never lock the next login out
    try {
        $n = qVal("SELECT COUNT(*) FROM login_attempts
                   WHERE username = ? AND school_id = ? AND ip_address = ? AND success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)", 'sisi', $username, $s, $ip, $lockout);
    } catch (Throwable $e) { // pre-update schema, no success/school column yet
        try {
            $n = qVal("SELECT COUNT(*) FROM login_attempts
                       WHERE username = ? AND ip_address = ? AND success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)", 'ssi', $username, $ip, $lockout);
        } catch (Throwable $e2) {
            $n = qVal("SELECT COUNT(*) FROM login_attempts
                       WHERE username = ? AND ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)", 'ssi', $username, $ip, $lockout);
        }
    }
    return (int)$n >= MAX_LOGIN_ATTEMPTS;
}

// $success is a trailing default — old calls keep recording a failure
function recordLoginAttempt($username, $success = 0, $school = 0) {
    $ok = $success ? 1 : 0;
    $s  = (int)$school;
    try {
        qExec("INSERT INTO login_attempts (username, school_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?, ?)",
              'sissi', $username, $s, ormsIp(), ormsUa(), $ok);
    } catch (Throwable $e) {
        try { qExec("INSERT INTO login_attempts (username, ip_address, user_agent, success) VALUES (?, ?, ?, ?)", 'sssi', $username, ormsIp(), ormsUa(), $ok); }
        catch (Throwable $e2) { qExec("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)", 'ss', $username, ormsIp()); }
    }
}

// clears THIS host's failures only — wiping every ip on success would let a legit sign-in
// reset an attacker's counter for them
function clearLoginAttempts($username, $school = 0) {
    $s  = (int)$school;
    $ip = ormsIp();
    try {
        qExec("DELETE FROM login_attempts WHERE username = ? AND school_id = ? AND ip_address = ?", 'sis', $username, $s, $ip);
    } catch (Throwable $e) { // pre-update schema
        qExec("DELETE FROM login_attempts WHERE username = ? AND ip_address = ?", 'ss', $username, $ip);
    }
}

// Activity Logging Functions
// entity_type/entity_id are trailing + optional — every existing call site keeps working untouched.
// user_agent + role are captured here, so no caller has to pass them
// $school is trailing/optional like $entity_type/$entity_id — ~75 call sites depend on that.
// pass it from pre-session pages (signup, blocked login, school registration), whose rows would
// otherwise land at school 0 because there is no session to read the tenant from.
function logActivity($user_id, $username, $action, $details = '', $entity_type = null, $entity_id = null, ?int $school = null) {
    $uid  = (int)$user_id;
    $ip   = ormsIp();
    $ua   = ormsUa();
    $role = $_SESSION['role'] ?? null;
    $eid  = $entity_id === null ? null : (int)$entity_id;
    $ok   = true;

    $sch = $school !== null ? (int)$school : (isset($_SESSION['school_id']) ? (int)$_SESSION['school_id'] : 0);   // 0 = platform/public
    $brn = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;   // 0 = platform/public/unbranched

    try {
        qExec("INSERT INTO activity_logs (user_id, username, action, details, ip_address, user_agent, role, entity_type, entity_id, school_id, branch_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
              'isssssssiii', $uid, $username, $action, $details, $ip, $ua, $role, $entity_type, $eid, $sch, $brn);
    } catch (Throwable $e) {
        error_log("Activity logging error: " . $e->getMessage());
        try { // no school_id column yet
            qExec("INSERT INTO activity_logs (user_id, username, action, details, ip_address, user_agent, role, entity_type, entity_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                  'isssssssi', $uid, $username, $action, $details, $ip, $ua, $role, $entity_type, $eid);
        } catch (Throwable $e2) {
            // pre-update schema — keep the audit trail alive on the original columns
            try {
                qExec("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)",
                      'issss', $uid, $username, $action, $details, $ip);
            } catch (Throwable $e3) { $ok = false; }
        }
    }

    // a password change logged anywhere in the app retires the force-change flag
    if ($uid > 0 && preg_match('/password\s*(chang|reset)/i', (string)$action)) ormsPasswordChanged($uid);

    return $ok;
}

function getActivityLogs($user_id = null, $role = 'User', $limit = null) {
    try {
        $conn = getDBConnection();

        // school-wide roles see all logs OF THEIR SCHOOL, everyone else only their own.
        // this branch used to have no WHERE at all — and the public result lookup writes admission
        // numbers into details, so unscoped it leaked student identifiers, not just metadata.
        if (ormsSchoolWide($role) && $user_id === null) {
            $plat = ormsIsPlatform();
            try {
                $sql = "SELECT * FROM activity_logs" . ($plat ? "" : " WHERE school_id = ?") . " ORDER BY timestamp DESC";
                if ($limit) $sql .= " LIMIT ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new RuntimeException('prepare failed');
                $s = sid();
                if ($plat)          { if ($limit) $stmt->bind_param("i", $limit); }
                elseif ($limit)     { $stmt->bind_param("ii", $s, $limit); }
                else                { $stmt->bind_param("i", $s); }
            } catch (Throwable $e) { // pre-migration db — no school_id column
                $sql = "SELECT * FROM activity_logs ORDER BY timestamp DESC";
                if ($limit) $sql .= " LIMIT ?";
                $stmt = $conn->prepare($sql);
                if ($limit) $stmt->bind_param("i", $limit);
            }
        } else {
            $sql = "SELECT * FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC";
            if ($limit) {
                $sql .= " LIMIT ?";
            }
            $stmt = $conn->prepare($sql);
            if ($limit) {
                $stmt->bind_param("ii", $user_id, $limit);
            } else {
                $stmt->bind_param("i", $user_id);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }

        $stmt->close();

        return $logs;
    } catch (Exception $e) {
        error_log("Get activity logs error: " . $e->getMessage());
        return [];
    }
}

// System Settings Functions
// per school, falling back to the platform row (school 0) — that's how smtp/vapid/oauth stay the
// operator's while branding, grading policy and prefixes belong to each school.
// cached per request per school; setSetting() busts it.
function ormsSettingCache(?int $school = null, ?array $set = null): array {
    static $c = [];
    $s = $school ?? ormsSettingSchool();
    if ($set !== null) { $c[$s] = $set; return $set; }
    if (isset($c[$s])) return $c[$s];

    $out = [];
    try {
        // school row wins, platform row fills the gaps — ORDER BY puts 0 first so the school overwrites it
        foreach (qAll("SELECT setting_key, setting_value FROM system_settings WHERE school_id IN (0, ?) ORDER BY school_id ASC", 'i', $s) as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        try { // pre-migration schema — one global row per key
            foreach (qAll("SELECT setting_key, setting_value FROM system_settings") as $r) $out[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e2) { return []; }
    }
    $c[$s] = $out;
    return $out;
}

// which school's settings this request reads. login/signup/public pages have no session yet,
// so they may pin one explicitly via $GLOBALS['ORMS_SETTING_SCHOOL'] (school-code login, public lookup).
function ormsSettingSchool(): int {
    if (isset($GLOBALS['ORMS_SETTING_SCHOOL'])) return (int) $GLOBALS['ORMS_SETTING_SCHOOL'];
    return isset($_SESSION['school_id']) ? (int) $_SESSION['school_id'] : 0;
}

function getSetting($key, $default = null) {
    $all = ormsSettingCache();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

// which school an EMAIL-keyed auth flow (password reset, email verify, signup email check) scopes to.
// 0 = do not scope: single-school install, or platform mode with no school resolved yet. The token
// tables default school_id to 0 there too, so behaviour is byte-identical to before the migration.
// >0 pins the resolving tenant so one school's email can never reset/verify another school's account.
function ormsAuthSchool(): int {
    if (!function_exists('ormsPlatformMode') || !ormsPlatformMode()) return 0;
    $s = ormsSettingSchool();
    return $s > 0 ? $s : 0;
}

function setSetting($key, $value) {
    $uid = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; // null = system/installer write
    $s   = ormsSettingSchool();
    try {
        // the unique is (school_id, setting_key), so this inserts a row for THIS school
        // instead of overwriting whichever school happened to own the key first
        qExec("INSERT INTO system_settings (school_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?", 'issisi', $s, $key, $value, $uid, $value, $uid);
        ormsSettingCache($s, array_merge(ormsSettingCache($s), [$key => $value]));  // keep the request consistent
        return true;   // affected_rows is 0 on an unchanged value — callers test success, not row count
    } catch (Throwable $e) {
        error_log("Set setting error: " . $e->getMessage());
        // pre-update schema ONLY. on a migrated db the tenant column exists, so a transient failure
        // (deadlock/lock timeout) must NOT fall through to a school-0 write — that row is the platform
        // default every tenant inherits. fail the save instead of silently rewriting it.
        if (function_exists('ormsHasTenancy') && ormsHasTenancy()) return false;
        try { // pre-update schema (no school_id column)
            qExec("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = ?", 'sss', $key, $value, $value);
            ormsSettingCache($s, array_merge(ormsSettingCache($s), [$key => $value]));
            return true;
        } catch (Throwable $e2) { return false; }
    }
}

function getDefaultLanguage() {
    return getSetting('default_language', 'en');
}

function isMaintenanceMode() {
    return getSetting('maintenance_mode', '0') === '1';
}

// Profile Image Upload Functions
function uploadProfileImage($file, $user_id) {
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    // Check file type by MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    // Check extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    // Generate unique filename
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/profiles/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

// only ever unlink inside our own uploads folder — never a caller-supplied path
function deleteProfileImage($filepath) {
    if (!is_string($filepath) || strncmp($filepath, 'uploads/', 8) !== 0) return false;

    $base = realpath(__DIR__ . '/uploads');
    $full = realpath(__DIR__ . '/' . $filepath);
    if (!$base || !$full || !is_file($full)) return false;
    if (strncmp($full, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) return false; // traversal out

    return @unlink($full);
}

function getProfileImage($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $profile_image = $row['profile_image'];
            $stmt->close();
            return $profile_image;
        }

        $stmt->close();
        return null;
    } catch (Exception $e) {
        error_log("Get profile image error: " . $e->getMessage());
        return null;
    }
}

// User Theme Preferences Functions

// app default palette = UIv7 · Obsidian & Mint (hexes from colors.md)
function themeDefaults() {
    return [
        'theme_primary'   => '#111827',
        'theme_secondary' => '#374151',
        'theme_accent'    => '#34D399',
        'theme_mode'      => 'light'
    ];
}

// official palette registry — primary|secondary|accent copied verbatim from colors.md (default first)
// Every palette the colour registry defines — the whole of docs/colors.md, exact hexes, grouped by
// its own sections. UIv7 stays the app default (it is what themeDefaults() ships). The list was
// GENERATED from that file rather than retyped: a palette is only ever as good as its hex codes.
$UI_PALETTES = [
    // default
    ['id' => 'UIv7',  'name' => 'Obsidian & Mint',      'p' => '#111827', 's' => '#374151', 'a' => '#34D399', 'g' => 'Default'],
    // Classic enterprise
    ['id' => 'UI1',   'name' => 'Enterprise Navy',      'p' => '#1E3A5F', 's' => '#4F6D8C', 'a' => '#5B8DEF', 'g' => 'Classic enterprise'],
    ['id' => 'UI2',   'name' => 'Graphite & Cyan',      'p' => '#2C313C', 's' => '#49515F', 'a' => '#00B8D9', 'g' => 'Classic enterprise'],
    ['id' => 'UI3',   'name' => 'Forest Executive',     'p' => '#234E52', 's' => '#52796F', 'a' => '#84A98C', 'g' => 'Classic enterprise'],
    ['id' => 'UI4',   'name' => 'Walnut & Sand',        'p' => '#4E3D32', 's' => '#7B6855', 'a' => '#C49A6C', 'g' => 'Classic enterprise'],
    ['id' => 'UI5',   'name' => 'Emerald Corporate',    'p' => '#0F766E', 's' => '#4CAF94', 'a' => '#22C55E', 'g' => 'Classic enterprise'],
    ['id' => 'UI6',   'name' => 'Mocha Executive',      'p' => '#5B4636', 's' => '#8B7355', 'a' => '#D4A373', 'g' => 'Classic enterprise'],
    ['id' => 'UI7',   'name' => 'Charcoal & Soft Gold', 'p' => '#2B2F36', 's' => '#4A4F57', 'a' => '#D4AF37', 'g' => 'Classic enterprise'],
    // Latest SaaS 2026
    ['id' => 'UIv1',  'name' => 'Zinc & Sky',           'p' => '#18181B', 's' => '#3F3F46', 'a' => '#0EA5E9', 'g' => 'Latest SaaS 2026'],
    ['id' => 'UIv2',  'name' => 'Ink & Violet',         'p' => '#0F0F12', 's' => '#27272A', 'a' => '#8B5CF6', 'g' => 'Latest SaaS 2026'],
    ['id' => 'UIv3',  'name' => 'Slate & Rose',         'p' => '#1E293B', 's' => '#475569', 'a' => '#F43F5E', 'g' => 'Latest SaaS 2026'],
    ['id' => 'UIv4',  'name' => 'Stone & Amber',        'p' => '#292524', 's' => '#57534E', 'a' => '#F59E0B', 'g' => 'Latest SaaS 2026'],
    ['id' => 'UIv5',  'name' => 'Mineral Teal',         'p' => '#134E4A', 's' => '#5F7A78', 'a' => '#2DD4BF', 'g' => 'Latest SaaS 2026'],
    ['id' => 'UIv6',  'name' => 'Paper & Copper',       'p' => '#3F2E24', 's' => '#6B5344', 'a' => '#C47B4A', 'g' => 'Latest SaaS 2026'],
    ['id' => 'UIv8',  'name' => 'Cloud & Indigo Soft',  'p' => '#312E81', 's' => '#4C51BF', 'a' => '#818CF8', 'g' => 'Latest SaaS 2026'],
    // 2026 Trends
    ['id' => 'UIv9',  'name' => 'Aurora Violet',        'p' => '#1A1025', 's' => '#3B2A52', 'a' => '#A78BFA', 'g' => '2026 Trends'],
    ['id' => 'UIv10', 'name' => 'Midnight Neon',        'p' => '#0A0A0F', 's' => '#1C1C28', 'a' => '#22D3EE', 'g' => '2026 Trends'],
    ['id' => 'UIv11', 'name' => 'Soft Coral SaaS',      'p' => '#1F2937', 's' => '#4B5563', 'a' => '#FB7185', 'g' => '2026 Trends'],
    ['id' => 'UIv12', 'name' => 'Arctic Frost',         'p' => '#0C4A6E', 's' => '#0369A1', 'a' => '#38BDF8', 'g' => '2026 Trends'],
    ['id' => 'UIv13', 'name' => 'Quiet Olive',          'p' => '#1C1917', 's' => '#44403C', 'a' => '#A3B18A', 'g' => '2026 Trends'],
    ['id' => 'UIv14', 'name' => 'Ink & Lime',           'p' => '#09090B', 's' => '#27272A', 'a' => '#A3E635', 'g' => '2026 Trends'],
    ['id' => 'UIv15', 'name' => 'Rose Quartz',          'p' => '#3F1D2E', 's' => '#6B3A4F', 'a' => '#E879A9', 'g' => '2026 Trends'],
    ['id' => 'UIv16', 'name' => 'Carbon Electric',      'p' => '#111827', 's' => '#1F2937', 'a' => '#6366F1', 'g' => '2026 Trends'],
    ['id' => 'UIv17', 'name' => 'Warm Terracotta',      'p' => '#292524', 's' => '#57534E', 'a' => '#E07A5F', 'g' => '2026 Trends'],
    ['id' => 'UIv18', 'name' => 'Ocean Deep',           'p' => '#0B1D36', 's' => '#1B3A5F', 'a' => '#14B8A6', 'g' => '2026 Trends'],
    // Designer Picks 2026
    ['id' => 'UIv19', 'name' => 'Cloud Dancer',         'p' => '#141414', 's' => '#2B2F36', 'a' => '#BFD3E7', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv20', 'name' => 'Soft Ember Glow',      'p' => '#2B1538', 's' => '#5A4B8A', 'a' => '#FF6A3D', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv21', 'name' => 'Mood Mode Cyan',       'p' => '#0B0D10', 's' => '#151A21', 'a' => '#40E0FF', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv22', 'name' => 'Neon Lime Pop',        'p' => '#070A0F', 's' => '#1A1F2E', 'a' => '#B6FF3B', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv23', 'name' => 'Laser Magenta',        'p' => '#0F0A12', 's' => '#2A1A28', 'a' => '#FF3BD4', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv24', 'name' => 'Holo Lilac AI',        'p' => '#07070A', 's' => '#1A1528', 'a' => '#B9A7FF', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv25', 'name' => 'Plasma Teal',          'p' => '#0A1214', 's' => '#163038', 'a' => '#00F5D4', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv26', 'name' => 'Eco Digital',          'p' => '#101417', 's' => '#316263', 'a' => '#C36A4A', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv27', 'name' => 'Warm Mahogany',        'p' => '#221A18', 's' => '#7A2E2A', 'a' => '#C9A46B', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv28', 'name' => 'Fiery Coral Ruby',     'p' => '#1A0A0C', 's' => '#4A1520', 'a' => '#FF5A4A', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv29', 'name' => 'Signal Blue Tech',     'p' => '#0A0F1A', 's' => '#152040', 'a' => '#3B7BFF', 'g' => 'Designer Picks 2026'],
    ['id' => 'UIv30', 'name' => 'Fig & Pear',           'p' => '#2D1F24', 's' => '#5C3D45', 'a' => '#A8C256', 'g' => 'Designer Picks 2026'],
    // X / SaaS Product Picks
    ['id' => 'UIv31', 'name' => 'Fintech Blurple',      'p' => '#0A2540', 's' => '#425466', 'a' => '#635BFF', 'g' => 'X / SaaS Product Picks'],
    ['id' => 'UIv32', 'name' => 'Violet Flow',          'p' => '#1C1D22', 's' => '#44454D', 'a' => '#5E6AD2', 'g' => 'X / SaaS Product Picks'],
    ['id' => 'UIv33', 'name' => 'Mono Pro',             'p' => '#000000', 's' => '#525252', 'a' => '#171717', 'g' => 'X / SaaS Product Picks'],
    ['id' => 'UIv34', 'name' => 'Warm Analytics',       'p' => '#7C2D12', 's' => '#9A3412', 'a' => '#F97316', 'g' => 'X / SaaS Product Picks'],
];

function getUserTheme($user_id) {
    $defaults = themeDefaults();

    try {
        $conn = getDBConnection();

        $stmt = @$conn->prepare("SELECT theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = ?");
        if (!$stmt) {
            return $defaults; // Columns don't exist yet
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return [
                'theme_primary' => $row['theme_primary'] ?? $defaults['theme_primary'],
                'theme_secondary' => $row['theme_secondary'] ?? $defaults['theme_secondary'],
                'theme_accent' => $row['theme_accent'] ?? $defaults['theme_accent'],
                'theme_mode' => $row['theme_mode'] ?? $defaults['theme_mode']
            ];
        }

        $stmt->close();
        return $defaults;
    } catch (Exception $e) {
        error_log("Get user theme error: " . $e->getMessage());
        return $defaults;
    }
}

function setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode) {
    try {
        $conn = getDBConnection();

        $stmt = @$conn->prepare("UPDATE users SET theme_primary = ?, theme_secondary = ?, theme_accent = ?, theme_mode = ? WHERE id = ?");
        if (!$stmt) {
            return false; // Columns don't exist, need to run setup.php first
        }
        $stmt->bind_param("ssssi", $theme_primary, $theme_secondary, $theme_accent, $theme_mode, $user_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set user theme error: " . $e->getMessage());
        return false;
    }
}

// Site Branding Functions
function getSiteBranding() {
    $defaults = [
        'site_name' => 'Dashboard System',
        'site_logo' => 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg',
        'copyright_text' => '&copy; 2026 Dashboard System. All rights reserved.'
    ];

    try {
        $name = getSetting('site_name', '');
        $logo = getSetting('site_logo', '');
        $copyright = getSetting('copyright_text', '');

        return [
            'site_name' => !empty($name) ? $name : $defaults['site_name'],
            'site_logo' => !empty($logo) ? $logo : $defaults['site_logo'],
            'copyright_text' => !empty($copyright) ? $copyright : $defaults['copyright_text']
        ];
    } catch (Exception $e) {
        return $defaults;
    }
}

// ============================================
// SMTP Email Functions
// ============================================

function sendEmail($to, $subject, $htmlBody) {
    $smtp_enabled = getSetting('smtp_enabled', '0');
    if ($smtp_enabled !== '1') {
        return ['success' => false, 'message' => 'SMTP is not enabled'];
    }

    $host = getSetting('smtp_host', '');
    $port = intval(getSetting('smtp_port', '587'));
    $username = getSetting('smtp_username', '');
    $password = getSetting('smtp_password', '');
    $from_email = getSetting('smtp_from_email', '');
    $from_name = getSetting('smtp_from_name', 'Dashboard System');
    $encryption = getSetting('smtp_encryption', 'tls');

    if (empty($host) || empty($username) || empty($password) || empty($from_email)) {
        return ['success' => false, 'message' => 'SMTP credentials not configured'];
    }

    try {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno, $errstr, 30,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$socket) {
            return ['success' => false, 'message' => "Connection failed: $errstr"];
        }

        stream_set_timeout($socket, 10);

        $readResponse = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && ($line[3] === ' ' || $line[3] === "\r")) break;
            }
            return trim($response);
        };

        $sendCommand = function($cmd) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            return $readResponse();
        };

        $readResponse(); // greeting
        $sendCommand('EHLO ' . gethostname());

        if ($encryption === 'tls') {
            $resp = $sendCommand('STARTTLS');
            if (strpos($resp, '220') === false) {
                fclose($socket);
                return ['success' => false, 'message' => 'STARTTLS failed'];
            }
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                fclose($socket);
                return ['success' => false, 'message' => 'TLS encryption failed'];
            }
            $sendCommand('EHLO ' . gethostname());
        }

        $sendCommand('AUTH LOGIN');
        $sendCommand(base64_encode($username));
        $authResp = $sendCommand(base64_encode($password));

        if (strpos($authResp, '235') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Authentication failed. Check username/password.'];
        }

        $resp = $sendCommand("MAIL FROM:<$from_email>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Sender rejected by server'];
        }

        $resp = $sendCommand("RCPT TO:<$to>");
        if (strpos($resp, '250') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'Recipient rejected by server'];
        }

        $resp = $sendCommand('DATA');
        if (strpos($resp, '354') === false) {
            fclose($socket);
            return ['success' => false, 'message' => 'DATA command rejected'];
        }

        $msg = "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from_email>\r\n";
        $msg .= "To: $to\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Date: " . date('r') . "\r\n";
        $msg .= "Message-ID: <" . uniqid() . "@" . gethostname() . ">\r\n";
        $msg .= "\r\n";
        $msg .= $htmlBody . "\r\n.\r\n";

        fputs($socket, $msg);
        $sendResp = $readResponse();

        $sendCommand('QUIT');
        fclose($socket);

        if (strpos($sendResp, '250') !== false) {
            return ['success' => true, 'message' => 'Email sent successfully'];
        }

        return ['success' => false, 'message' => 'Email delivery failed'];

    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) fclose($socket);
        return ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
    }
}

function getOTPEmailTemplate($otp, $purpose = 'verify') {
    $branding = getSiteBranding();
    $siteName = htmlspecialchars($branding['site_name']);

    $title = ($purpose === 'verify') ? 'Verify Your Email' : 'Reset Your Password';
    $message = ($purpose === 'verify')
        ? 'Use the following OTP code to verify your email address:'
        : 'Use the following OTP code to reset your password:';

    return '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:0;">'
        . '<h1 style="color:#fff;margin:0;font-size:22px;">' . $siteName . '</h1>'
        . '</div>'
        . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
        . '<h2 style="color:#333;margin-top:0;">' . $title . '</h2>'
        . '<p style="color:#666;">' . $message . '</p>'
        . '<div style="background:#f8f9fa;padding:20px;text-align:center;border-radius:0;margin:20px 0;">'
        . '<span style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#001f3f;">' . $otp . '</span>'
        . '</div>'
        . '<p style="color:#999;font-size:13px;">This code expires in 10 minutes. Do not share it with anyone.</p>'
        . '</div>'
        . '<div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0;border:1px solid #e9ecef;border-top:none;">'
        . '<p style="color:#999;font-size:12px;margin:0;">This is an automated message. Please do not reply.</p>'
        . '</div></div>';
}

// login credentials mail for a freshly created account — best-effort, the add itself never fails on it.
// placeholder mailboxes (@example.com) are skipped up front, so imports and mailbox-less students cost nothing
function sendCredentialsEmail(string $to, string $name, string $role, string $uname, string $pwd): array {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'no mailbox on file'];
    if (str_ends_with(strtolower($to), '@example.com'))        return ['success' => false, 'message' => 'placeholder address'];

    $branding = getSiteBranding();
    $site = htmlspecialchars($branding['site_name']);
    $code = '';
    try { if (sid()) $code = (string) qVal("SELECT code FROM schools WHERE id = ?", 'i', sid()); } catch (Throwable $e) {}
    $url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\') . '/login.php';

    // label/value row, same skin as the OTP template
    $row = static fn(string $l, string $v): string =>
        '<tr><td style="padding:8px 12px;color:#666;font-size:13px;border-bottom:1px solid #e9ecef;">' . $l . '</td>'
      . '<td style="padding:8px 12px;color:#001f3f;font-size:14px;font-weight:bold;border-bottom:1px solid #e9ecef;">' . htmlspecialchars($v) . '</td></tr>';

    $html = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
        . '<div style="background:#001f3f;padding:20px;text-align:center;border-radius:0;">'
        . '<h1 style="color:#fff;margin:0;font-size:22px;">' . $site . '</h1>'
        . '</div>'
        . '<div style="background:#fff;padding:30px;border:1px solid #e9ecef;border-top:none;">'
        . '<h2 style="color:#333;margin-top:0;">Your ' . htmlspecialchars($role) . ' Account</h2>'
        . '<p style="color:#666;">Hello ' . htmlspecialchars($name) . ', your ' . strtolower(htmlspecialchars($role))
        . ' account has been created. Sign in with the details below:</p>'
        . '<table style="width:100%;border-collapse:collapse;background:#f8f9fa;margin:20px 0;">'
        . ($code !== '' ? $row('School Code', $code) : '')
        . $row('Username', $uname)
        . $row('Password', $pwd)
        . '</table>'
        . '<div style="text-align:center;margin:24px 0;">'
        . '<a href="' . htmlspecialchars($url) . '" style="background:#0074D9;color:#fff;padding:12px 28px;text-decoration:none;font-weight:bold;display:inline-block;">Sign In</a>'
        . '</div>'
        . '<p style="color:#999;font-size:13px;">You will be asked to set a new password on your first login. Keep these details private.</p>'
        . '</div>'
        . '<div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0;border:1px solid #e9ecef;border-top:none;">'
        . '<p style="color:#999;font-size:12px;margin:0;">This is an automated message. Please do not reply.</p>'
        . '</div></div>';

    return sendEmail($to, 'Your Login Details - ' . $branding['site_name'], $html);
}

// ============================================
// OTP & Email Verification Functions
// ============================================

function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function createEmailVerification($user_id, $email) {
    $otp = generateOTP();
    qExec("DELETE FROM email_verifications WHERE user_id = ?", 'i', (int)$user_id);
    try { // carry the tenant (from the user row) when the column exists — migrated schema
        $sch = (int) qVal("SELECT school_id FROM users WHERE id = ?", 'i', (int)$user_id);
        qExec("INSERT INTO email_verifications (user_id, email, school_id, otp_code, expires_at, ip_address)
               VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)", 'isiss', (int)$user_id, $email, $sch, $otp, ormsIp());
        return $otp;
    } catch (Throwable $e) { /* pre-migration — no school_id column */ }
    qExec("INSERT INTO email_verifications (user_id, email, otp_code, expires_at, ip_address)
           VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)", 'isss', (int)$user_id, $email, $otp, ormsIp());
    return $otp;
}

// 6 digits with unlimited guesses is brute-forceable — the live code is fetched by email,
// every attempt is counted, and the code dies once MAX_OTP_ATTEMPTS guesses are on it
function verifyEmailOTP($email, $otp, $school = null) {
    $s = $school === null ? ormsAuthSchool() : (int)$school;
    try {
        $row = $s > 0
            ? qOne("SELECT id, user_id, otp_code, attempts FROM email_verifications
                     WHERE email = ? AND school_id = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 'si', $email, $s)
            : qOne("SELECT id, user_id, otp_code, attempts FROM email_verifications
                     WHERE email = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 's', $email);
    } catch (Throwable $e) { // pre-migration: no school_id column
        $row = qOne("SELECT id, user_id, otp_code, attempts FROM email_verifications
                     WHERE email = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 's', $email);
    }
    if (!$row) return false;
    if ((int)$row['attempts'] >= MAX_OTP_ATTEMPTS) return false;   // burned — request a new code

    qExec("UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?", 'i', (int)$row['id']);
    if (!hash_equals((string)$row['otp_code'], (string)$otp)) return false;

    qExec("UPDATE email_verifications SET used = 1, used_at = NOW() WHERE id = ?", 'i', (int)$row['id']);
    qExec("UPDATE users SET email_verified = 1 WHERE id = ?", 'i', (int)$row['user_id']);
    return true;
}

// ============================================
// Password Reset Functions
// ============================================

function createPasswordReset($email, $school = null) {
    $s = $school === null ? ormsAuthSchool() : (int)$school;

    // resolve the account WITHIN the tenant when scoped — email alone is not unique across schools
    $uid = $s > 0
        ? qVal("SELECT id FROM users WHERE email = ? AND school_id = ?", 'si', $email, $s)
        : qVal("SELECT id FROM users WHERE email = ?", 's', $email);
    if (!$uid) return false;

    $otp = generateOTP();
    if ($s > 0) {
        try { // scoped path (platform mode -> migrated schema has school_id)
            qExec("DELETE FROM password_resets WHERE email = ? AND school_id = ?", 'si', $email, $s);
            qExec("INSERT INTO password_resets (email, school_id, otp_code, expires_at, ip_address)
                   VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?)", 'siss', $email, $s, $otp, ormsIp());
            return $otp;
        } catch (Throwable $e) { /* column missing — degrade to the unscoped path below */ }
    }
    qExec("DELETE FROM password_resets WHERE email = ?", 's', $email);
    qExec("INSERT INTO password_resets (email, otp_code, expires_at, ip_address)
           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?)", 'sss', $email, $otp, ormsIp());
    return $otp;
}

// same brute-force guard as the email OTP — count every attempt, kill the code after MAX_OTP_ATTEMPTS
function verifyPasswordResetOTP($email, $otp, $school = null) {
    $s = $school === null ? ormsAuthSchool() : (int)$school;
    try {
        $row = $s > 0
            ? qOne("SELECT id, otp_code, attempts FROM password_resets
                     WHERE email = ? AND school_id = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 'si', $email, $s)
            : qOne("SELECT id, otp_code, attempts FROM password_resets
                     WHERE email = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 's', $email);
    } catch (Throwable $e) { // pre-migration: no school_id column
        $row = qOne("SELECT id, otp_code, attempts FROM password_resets
                     WHERE email = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1", 's', $email);
    }
    if (!$row) return false;
    if ((int)$row['attempts'] >= MAX_OTP_ATTEMPTS) return false;   // burned — request a new code

    qExec("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?", 'i', (int)$row['id']);
    if (!hash_equals((string)$row['otp_code'], (string)$otp)) return false;

    qExec("UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = ?", 'i', (int)$row['id']);
    return true;
}

// ============================================
// Remember Me Functions
// ============================================

function createRememberToken($user_id) {
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);

    qExec("DELETE FROM remember_tokens WHERE user_id = ?", 'i', (int)$user_id);
    // ip + ua so "sign out my other devices" can actually name the device
    qExec("INSERT INTO remember_tokens (user_id, token_hash, expires_at, ip_address, user_agent)
           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)", 'isss', (int)$user_id, $token_hash, ormsIp(), ormsUa());

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', $token, time() + (86400 * 30), '/', '', $secure, true);

    return $token;
}

function validateRememberToken() {
    if (!isset($_COOKIE['remember_token'])) return null;

    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);

    // deactivated account -> no row -> cookie gets wiped below.
    // school_id/branch_id ride along so the auto-login can restore the tenant stamp
    try {
        $user = qOne("SELECT rt.id, rt.user_id, u.username, u.role, u.must_change_password, u.school_id, u.branch_id
                      FROM remember_tokens rt JOIN users u ON rt.user_id = u.id
                      WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND u.is_active = 1", 's', $token_hash);
    } catch (Throwable $e) { // pre-migration schema
        $user = qOne("SELECT rt.id, rt.user_id, u.username, u.role, u.must_change_password
                      FROM remember_tokens rt JOIN users u ON rt.user_id = u.id
                      WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND u.is_active = 1", 's', $token_hash);
    }

    if ($user) {
        // rotate on every use — a stolen cookie used to stay valid the full 30 days with no way to
        // spot the replay. the row is updated in place so "sign out my other devices" still works,
        // and the expiry is NOT extended: rotation must not turn 30 days into forever.
        $fresh = bin2hex(random_bytes(32));
        try {
            qExec("UPDATE remember_tokens SET token_hash = ?, last_used_at = NOW(), ip_address = ?, user_agent = ? WHERE id = ?",
                  'sssi', hash('sha256', $fresh), ormsIp(), ormsUa(), (int)$user['id']);
        } catch (Throwable $e) { // pre-migration: no ip/ua columns
            qExec("UPDATE remember_tokens SET token_hash = ?, last_used_at = NOW() WHERE id = ?",
                  'si', hash('sha256', $fresh), (int)$user['id']);
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('remember_token', $fresh, time() + (86400 * 30), '/', '', $secure, true);
        $_COOKIE['remember_token'] = $fresh; // same request may read it again
        return $user;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
    return null;
}

function clearRememberToken($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', '', time() - 3600, '/', '', $secure, true);
}

// ============================================
// Session Tracking Functions
// ============================================

function trackUserSession($user_id) {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';

        $stmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, last_activity)
                               VALUES (?, ?, ?, ?, NOW())
                               ON DUPLICATE KEY UPDATE last_activity = NOW(), ip_address = ?, user_agent = ?");
        $stmt->bind_param("isssss", $user_id, $session_id, $ip, $user_agent, $ip, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

function removeUserSession() {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

function checkForceLogout() {
    try {
        $conn = getDBConnection();
        $session_id = session_id();
        $stmt = $conn->prepare("SELECT force_logout FROM user_sessions WHERE session_id = ?");
        $stmt->bind_param("s", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['force_logout'] == 1;
        }
        $stmt->close();
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function cleanExpiredSessions($timeout = 1800) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->bind_param("i", $timeout);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

// ============================================
// Notification Functions
// ============================================

// signature unchanged — created_by comes from the session, so the ~7 existing call sites stay untouched
function createNotification($user_id, $title, $message, $type = 'info', $link = null) {
    try {
        $by = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; // null = system-generated
        // stamp the RECIPIENT's school, not the sender's — a super admin notifying a school
        // must file the row under that school, not under the platform
        try {
            $sch = (int)(qVal("SELECT school_id FROM users WHERE id = ?", 'i', (int)$user_id) ?? 0);
            qExec("INSERT INTO notifications (user_id, title, message, type, link, created_by, school_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                  'issssii', (int)$user_id, $title, $message, $type, $link, $by, $sch);
        } catch (Throwable $e) { // pre-migration schema
            qExec("INSERT INTO notifications (user_id, title, message, type, link, created_by) VALUES (?, ?, ?, ?, ?, ?)",
                  'issssi', (int)$user_id, $title, $message, $type, $link, $by);
        }

        // also fire a browser/OS push to this user's devices (best-effort)
        if ($user_id && file_exists(__DIR__ . '/webpush_helper.php')) {
            try {
                require_once __DIR__ . '/webpush_helper.php';
                if (function_exists('webpushSendToUser')) {
                    webpushSendToUser($user_id, $title, $message, $link ?: 'dashboard.php');
                }
            } catch (Throwable $e) { /* push best-effort — never break the notification */ }
        }

        return true;
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

// every oversight role, not just Admin — a head teacher who never hears about a new student isn't overseeing anything.
// scoped to ONE school: unscoped, "Student Registered: <name> (<admission_no>)" would reach — and push to
// the phone of — every school's admins. $school is trailing/optional, defaults to the caller's tenant.
function createNotificationForAdmins($title, $message, $type = 'info', $link = null, ?int $school = null) {
    try {
        $conn = getDBConnection();
        $roles = ormsSchoolWideRoles();
        $s  = $school ?? sid();
        $ph = implode(',', array_fill(0, count($roles), '?'));
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE role IN ($ph) AND is_active = 1 AND school_id = ?");
            $args = array_merge($roles, [$s]);
            $stmt->bind_param(str_repeat('s', count($roles)) . 'i', ...$args);
        } catch (Throwable $e) { // pre-migration schema
            $stmt = $conn->prepare("SELECT id FROM users WHERE role IN ($ph) AND is_active = 1");
            $stmt->bind_param(str_repeat('s', count($roles)), ...$roles);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            createNotification($row['id'], $title, $message, $type, $link);
        }
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("Admin notification error: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotificationCount($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['count'];
    } catch (Exception $e) {
        return 0;
    }
}

function getRecentNotifications($user_id, $limit = 10) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        $stmt->close();
        return $notifications;
    } catch (Exception $e) {
        return [];
    }
}

function markNotificationRead($id, $user_id) {
    try {
        qExec("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND is_read = 0",
              'ii', (int)$id, (int)$user_id);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function markAllNotificationsRead($user_id) {
    try {
        qExec("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0", 'i', (int)$user_id);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// Generate CSS variables for user theme
function generateUserThemeCSS($user_id) {
    $theme = getUserTheme($user_id);

    // Calculate hover color (slightly lighter/darker)
    $primary = $theme['theme_primary'];

    $css = "<style id='user-theme-css'>\n";
    $css .= ":root {\n";
    $css .= "    --navy-primary: {$theme['theme_primary']};\n";
    $css .= "    --navy-light: {$theme['theme_secondary']};\n";
    $css .= "    --navy-accent: {$theme['theme_accent']};\n";
    $css .= "    --navy-dark: {$theme['theme_primary']};\n";
    $css .= "    --navy-hover: {$theme['theme_secondary']};\n";
    $css .= "}\n";
    $css .= "</style>\n";

    return $css;
}

// ============================================
// RBAC — data-driven roles & permissions
// ============================================

// page registry — keys fixed (seed/guards/sidebar/matrix/about all key off these)
// labels/icons mirror sidebar.php so visible menu stays unchanged
$RBAC_PAGES = [
    // platform console — super admin only. a school role holds $none on all three (see the roles seed)
    ['key' => 'schools',       'label' => 'Schools',       'icon' => 'fa-city',         'group' => 'Platform', 'file' => 'schools.php'],
    ['key' => 'plans',         'label' => 'Plans',         'icon' => 'fa-layer-group',  'group' => 'Platform', 'file' => 'plans.php'],
    ['key' => 'subscriptions', 'label' => 'Subscriptions',  'icon' => 'fa-file-invoice-dollar', 'group' => 'Platform', 'file' => 'subscriptions.php'],
    ['key' => 'gateways',      'label' => 'Payment Gateways', 'icon' => 'fa-credit-card',  'group' => 'Platform', 'file' => 'gateways.php'],

    ['key' => 'dashboard',   'label' => 'Dashboard',           'icon' => 'fa-chart-line',  'group' => 'General', 'file' => 'dashboard.php'],
    ['key' => 'users',       'label' => 'Users',               'icon' => 'fa-users',       'group' => 'System',  'file' => 'users.php'],
    ['key' => 'logs',        'label' => 'Activity Logs',       'icon' => 'fa-history',     'group' => 'System',  'file' => 'logs.php'],
    ['key' => 'sessions',    'label' => 'Sessions',            'icon' => 'fa-desktop',     'group' => 'System',  'file' => 'sessions.php'],
    ['key' => 'settings',    'label' => 'Site Settings',       'icon' => 'fa-cog',         'group' => 'System',  'file' => 'settings.php'],
    ['key' => 'backup',      'label' => 'Backup',              'icon' => 'fa-database',    'group' => 'System',  'file' => 'backup.php'],
    ['key' => 'smtp_setup',  'label' => 'SMTP Setup',          'icon' => 'fa-envelope',    'group' => 'System',  'file' => 'smtp_setup.php'],
    ['key' => 'oauth_setup', 'label' => 'OAuth Setup',         'icon' => 'fab fa-google',  'group' => 'System',  'file' => 'oauth_setup.php'],
    ['key' => 'roles',       'label' => 'Roles & Permissions', 'icon' => 'fa-user-shield', 'group' => 'System',  'file' => 'roles.php'],

    // academic layer
    ['key' => 'branches',        'label' => 'Branches',           'icon' => 'fa-code-branch',        'group' => 'Academics', 'file' => 'branches.php'],
    ['key' => 'students',        'label' => 'Students',           'icon' => 'fa-user-graduate',      'group' => 'Academics', 'file' => 'students.php'],
    ['key' => 'teachers',        'label' => 'Teachers',           'icon' => 'fa-chalkboard-teacher', 'group' => 'Academics', 'file' => 'teachers.php'],
    ['key' => 'classes',         'label' => 'Classes & Sections', 'icon' => 'fa-school',             'group' => 'Academics', 'file' => 'classes.php'],
    ['key' => 'subjects',        'label' => 'Subjects',           'icon' => 'fa-book',               'group' => 'Academics', 'file' => 'subjects.php'],

    // results layer
    ['key' => 'marks_entry',     'label' => 'Marks Entry',        'icon' => 'fa-pen-to-square',      'group' => 'Results',   'file' => 'marks_entry.php'],
    ['key' => 'results',         'label' => 'Results',            'icon' => 'fa-award',              'group' => 'Results',   'file' => 'results.php'],
    ['key' => 'my_results',      'label' => 'My Results',         'icon' => 'fa-file-lines',         'group' => 'Results',   'file' => 'my_results.php'],
    ['key' => 'result_settings', 'label' => 'Result Settings',    'icon' => 'fa-sliders',            'group' => 'Results',   'file' => 'result_settings.php'],
    ['key' => 'broadsheet',      'label' => 'Broadsheet',         'icon' => 'fa-table-cells',        'group' => 'Results',   'file' => 'broadsheet.php'],

    // attendance feeds the card, fees decide whether a card may be seen at all
    ['key' => 'attendance',      'label' => 'Attendance',         'icon' => 'fa-user-check',         'group' => 'Academics', 'file' => 'attendance.php'],
    ['key' => 'timetable',       'label' => 'Timetable',          'icon' => 'fa-calendar-days',      'group' => 'Academics', 'file' => 'timetable.php'],
    ['key' => 'fees',            'label' => 'Fees',               'icon' => 'fa-money-bill',         'group' => 'Finance',   'file' => 'fees.php'],
    ['key' => 'billing',         'label' => 'Subscription',       'icon' => 'fa-receipt',            'group' => 'Finance',   'file' => 'billing.php'],
];

// matrix row order in roles.php — a page whose group is missing here never renders
$RBAC_GROUPS = ['Platform', 'General', 'Academics', 'Results', 'Finance', 'System'];

$RBAC_EDIT_ROLES = ['School Owner', 'Admin', 'Super Admin']; // who may open/edit the matrix (hard gate). the operator
                                             // needs it too, or a school with a broken matrix is unrecoverable

/* ===================================================================
   TENANT CORE — every school is invisible to every other school.
   Read this before touching any query: "school-wide" means the whole of
   YOUR school, never the whole table. There is no unfiltered branch.
   =================================================================== */

// platform operator. a SEPARATE session flag on purpose — never "school_id === 0",
// because a session that simply forgot to set school_id would then read as god mode.
function ormsIsPlatform(): bool { return !empty($_SESSION['is_super']); }

function ormsPlatformRoles(): array { return ['Super Admin']; }

// auto-heal schema gaps on schools & billing tables so queries like SELECT s.trial_ends_at never fail
function ormsEnsureSchoolColumns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = getDBConnection(true);
        if (!$c) return;
        $probe = @$c->query("SHOW COLUMNS FROM `schools` LIKE 'trial_ends_at'");
        if ($probe && $probe->num_rows === 0) {
            @$c->query("ALTER TABLE `schools` ADD COLUMN `trial_ends_at` DATE DEFAULT NULL AFTER `plan_id`");
        }
        $probeLogo = @$c->query("SHOW COLUMNS FROM `schools` LIKE 'logo'");
        if ($probeLogo && $probeLogo->num_rows === 0) {
            @$c->query("ALTER TABLE `schools` ADD COLUMN `logo` VARCHAR(255) DEFAULT NULL AFTER `code`");
        }
        $probeCur = @$c->query("SHOW COLUMNS FROM `schools` LIKE 'billing_currency'");
        if ($probeCur && $probeCur->num_rows === 0) {
            @$c->query("ALTER TABLE `schools` ADD COLUMN `billing_currency` VARCHAR(10) DEFAULT NULL AFTER `plan_id`");
        }
        $probeInv = @$c->query("SHOW TABLES LIKE 'billing_invoices'");
        if ($probeInv && $probeInv->num_rows > 0) {
            $colCycle = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'cycle'");
            if ($colCycle && $colCycle->num_rows === 0) {
                $colOld = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'billing_cycle'");
                if ($colOld && $colOld->num_rows > 0) {
                    @$c->query("ALTER TABLE `billing_invoices` CHANGE `billing_cycle` `cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly'");
                } else {
                    @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `plan_id`");
                }
            }
            $colPaid = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'paid_at'");
            if ($colPaid && $colPaid->num_rows === 0) {
                @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `paid_at` DATETIME DEFAULT NULL AFTER `period_end`");
            }
            $colProof = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'proof'");
            if ($colProof && $colProof->num_rows === 0) {
                @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `proof` VARCHAR(255) DEFAULT NULL AFTER `paid_at`");
            }
            $colToken = @$c->query("SHOW COLUMNS FROM `billing_invoices` LIKE 'token'");
            if ($colToken && $colToken->num_rows === 0) {
                @$c->query("ALTER TABLE `billing_invoices` ADD COLUMN `token` CHAR(48) NOT NULL DEFAULT '' AFTER `invoice_no`");
            }
        }
    } catch (Throwable $e) {}
}

// have the tenant columns landed yet? one probe per request, same shape as ormsHasElectives()
function ormsHasTenancy(): bool {
    static $has = null;
    if ($has === null) {
        try { 
            qVal("SELECT school_id FROM users LIMIT 1"); 
            $has = true; 
            ormsEnsureSchoolColumns();
        }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// current tenant. fails CLOSED once tenancy exists: a logged-in session with no school stamp is a
// bug, not a licence to read everything. 0 is only ever legitimate for the platform operator.
function sid(): int {
    if (!isset($_SESSION['user_id'])) return 0;              // logged out — callers gate separately
    if (isset($_SESSION['school_id'])) return (int) $_SESSION['school_id'];
    if (ormsIsPlatform()) return 0;
    if (!ormsHasTenancy()) return 1;                         // pre-migration db — one school, id 1
    // migrated db + a session minted before this deploy: bounce once, don't hand back everything
    session_unset(); session_destroy();
    header('Location: login.php?stale=1');
    exit();
}

// active branch filter. 0 = every branch this role may reach (a filter, not a boundary).
// a Branch Admin is PINNED — their own branch always wins over any picker.
function bid(): int {
    $lock = ormsBranchLock();
    if ($lock) return $lock;
    return (int) ($_SESSION['branch_filter'] ?? 0);
}

function ormsBranchLock(?string $role = null): int {
    $r = $role ?? ($_SESSION['role'] ?? '');
    return $r === 'Branch Admin' ? (int) ($_SESSION['branch_id'] ?? 0) : 0;
}

// tables carrying their own school_id — the whitelist ormsFind*/audits work from
function ormsTenantTables(): array {
    return ['students', 'teachers', 'classes', 'subjects', 'academic_years', 'grading_sets',
            'assessment_schemes', 'marks', 'result_summaries', 'result_publications',
            'attendance_summary', 'student_fees', 'activity_logs', 'notifications'];
}

/**
 * THE resolver. Turns an id that arrived from the request into a verified row id,
 * or 0 when it belongs to another school. Never interpolate $table from input —
 * it is matched against a fixed whitelist first.
 * Tables reached through a parent (sections, class_subjects, exam_terms, teacher_subjects)
 * are resolved by joining that parent, so they need no school_id column of their own.
 */
function ormsOwns(string $table, $rawId): int {
    $id = (int) $rawId;
    if ($id <= 0) return 0;
    if (!ormsHasTenancy()) return $id;                       // pre-migration db — one school, nothing to gate
    if (ormsIsPlatform()) return $id;                        // operator already chose a school
    $s = sid();
    if (!$s) return 0;

    // parent-joined children -> the join IS the tenant check
    $via = [
        'sections'         => "SELECT s.id FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.id = ? AND c.school_id = ?",
        'exam_terms'       => "SELECT t.id FROM exam_terms t JOIN academic_years y ON y.id = t.academic_year_id WHERE t.id = ? AND y.school_id = ?",
        'class_subjects'   => "SELECT cs.id FROM class_subjects cs JOIN classes c ON c.id = cs.class_id WHERE cs.id = ? AND c.school_id = ?",
        'teacher_subjects' => "SELECT ts.id FROM teacher_subjects ts JOIN teachers t ON t.id = ts.teacher_id WHERE ts.id = ? AND t.school_id = ?",
        'users'            => "SELECT id FROM users WHERE id = ? AND school_id = ?",
        'branches'         => "SELECT id FROM branches WHERE id = ? AND school_id = ?",
    ];
    try {
        $sql = $via[$table] ?? (in_array($table, ormsTenantTables(), true)
            ? "SELECT id FROM `$table` WHERE id = ? AND school_id = ?" : null);
        if ($sql === null) return 0;                          // unknown table -> deny, never guess
        $ok = (int) qVal($sql, 'ii', $id, $s);
    } catch (Throwable $e) { return 0; }
    if (!$ok) return 0;

    // branch admins are pinned one level deeper. sections/class_subjects have no
    // branch_id of their own -> pin through the parent class's branch_id.
    $lock = ormsBranchLock();
    if ($lock) {
        $branchVia = [
            'students'       => "SELECT COUNT(*) FROM students WHERE id = ? AND branch_id = ?",
            'teachers'       => "SELECT COUNT(*) FROM teachers WHERE id = ? AND branch_id = ?",
            'classes'        => "SELECT COUNT(*) FROM classes WHERE id = ? AND branch_id = ?",
            'sections'       => "SELECT COUNT(*) FROM sections s JOIN classes c ON c.id = s.class_id WHERE s.id = ? AND c.branch_id = ?",
            'class_subjects' => "SELECT COUNT(*) FROM class_subjects cs JOIN classes c ON c.id = cs.class_id WHERE cs.id = ? AND c.branch_id = ?",
        ];
        if (isset($branchVia[$table])) {
            try {
                if ((int) qVal($branchVia[$table], 'ii', $id, $lock) === 0) return 0;
            } catch (Throwable $e) { return 0; }
        }
    }
    return $ok;
}

/**
 * Write the tenant stamp onto a freshly minted session. Every login path must call this —
 * login.php, the remember-me auto-login, oauth_callback.php and impersonate.php — because sid()
 * fails closed and a session without the stamp is destroyed on its first scoped query.
 */
function ormsStampTenant(int $userId, string $role = ''): void {
    $school = 1; $branch = 0;
    try {
        $r = qOne("SELECT school_id, branch_id FROM users WHERE id = ?", 'i', $userId);
        if ($r) { $school = (int)$r['school_id']; $branch = (int)($r['branch_id'] ?? 0); }
    } catch (Throwable $e) { /* pre-migration db — one school, id 1 */ }
    $_SESSION['school_id'] = $school;
    $_SESSION['branch_id'] = $branch;
    $_SESSION['is_super']  = $role === 'Super Admin';
    unset($_SESSION['branch_filter']);
}

// typed shorthands — what pages actually call
function ormsFindStudent($id): int { return ormsOwns('students', $id); }
function ormsFindTeacher($id): int { return ormsOwns('teachers', $id); }
function ormsFindClass($id): int   { return ormsOwns('classes', $id); }
function ormsFindSection($id): int { return ormsOwns('sections', $id); }
function ormsFindSubject($id): int { return ormsOwns('subjects', $id); }
function ormsFindTerm($id): int    { return ormsOwns('exam_terms', $id); }
function ormsFindYear($id): int    { return ormsOwns('academic_years', $id); }

/**
 * The section-id list a role may touch — ONE definition, replacing the four byte-identical
 * clones that used to live in results/attendance/broadsheet/fees.
 * Returns an int array. NEVER null: "school-wide" is this school's full section list,
 * so no caller can fall into an unfiltered branch by accident.
 */
function ormsSectionScope(?string $role = null, ?int $userId = null, ?int $yearId = null): array {
    $role = $role ?? ($_SESSION['role'] ?? '');
    $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    $tenant = ormsHasTenancy();                               // pre-migration -> drop the school leg
    $s = sid();
    try {
        if (ormsSchoolWide($role)) {
            $lock = $tenant ? ormsBranchLock($role) : 0;
            if (!$tenant) {
                return array_map('intval', array_column(qAll("SELECT id FROM sections"), 'id'));
            }
            $sql = "SELECT sec.id FROM sections sec JOIN classes c ON c.id = sec.class_id WHERE c.school_id = ?"
                 . ($lock ? " AND c.branch_id = ?" : "");
            $rows = $lock ? qAll($sql, 'ii', $s, $lock) : qAll($sql, 'i', $s);
            return array_map('intval', array_column($rows, 'id'));
        }
        $tid = ormsTeacherId($userId);
        if (!$tid) return [];                                 // no teacher row -> deny all
        $yearId = $yearId ?: (int) (ormsCurrentYear()['id'] ?? 0);
        if (!$tenant) {
            return array_map('intval', array_column(qAll(
                "SELECT DISTINCT section_id FROM teacher_subjects WHERE teacher_id = ? AND academic_year_id = ?",
                'ii', $tid, $yearId), 'section_id'));
        }
        return array_map('intval', array_column(qAll(
            "SELECT DISTINCT ts.section_id FROM teacher_subjects ts
             JOIN teachers t ON t.id = ts.teacher_id
             WHERE ts.teacher_id = ? AND ts.academic_year_id = ? AND t.school_id = ?",
            'iii', $tid, $yearId, $s), 'section_id'));
    } catch (Throwable $e) { return []; }
}

// may this role act on this section? closed by default
function ormsCanSeeSection(int $sectionId, ?string $role = null, ?int $userId = null, ?int $yearId = null): bool {
    return $sectionId > 0 && in_array($sectionId, ormsSectionScope($role, $userId, $yearId), true);
}

// roles whose data reach is the WHOLE school — meaning this school, all branches. identity decides
// reach, never permission bits: ticking "Edit" for a role must never widen which rows it covers.
// Branch Admin is school-wide INSIDE one branch, so it lives here and is pinned by ormsBranchLock().
// a function, not a global: fns hoist, so callers earlier in this file can't read it before it's assigned.
function ormsSchoolWideRoles(): array { return ['School Owner', 'Admin', 'Principal', 'Branch Admin']; }

function ormsSchoolWide(?string $role = null): bool {
    return in_array($role ?? ($_SESSION['role'] ?? ''), ormsSchoolWideRoles(), true);
}

// all roles + decoded perms, sorted by sort_order. cached per request PER SCHOOL — the cache key
// must carry the tenant, or roleByKey() first-match would hand one school's matrix to the platform
// (every school owns a row keyed 'Teacher' once roles are per-school).
function readRoles($forceReload = false) {
    static $cache = [];
    $s = isset($_SESSION['school_id']) ? (int)$_SESSION['school_id'] : 0;
    if (isset($cache[$s]) && !$forceReload) return $cache[$s];

    $cache[$s] = [];
    $cols = "role_key, label, color, sort_order, is_super, hidden_signup, permissions";
    try {
        $conn = getDBConnection();
        // ONE school's rows only. merging school 0 in would show every school a "Super Admin" row
        // it must never be able to assign, and duplicate its own four roles.
        // tolerate a missing table (pre-setup) so the app never fatals.
        $load = static function ($res) use (&$cache, $s) {
            if (!$res) return 0;
            $n = 0;
            while ($row = $res->fetch_assoc()) {
                $perms = json_decode($row['permissions'] ?? '', true);
                $cache[$s][] = [
                    'key' => $row['role_key'],
                    'label' => $row['label'],
                    'color' => $row['color'] ?? '#0074D9',
                    'sort' => (int)$row['sort_order'],
                    'is_super' => (int)$row['is_super'],
                    'hidden_signup' => (int)$row['hidden_signup'],
                    'perms' => is_array($perms) ? $perms : []
                ];
                $n++;
            }
            return $n;
        };

        if ($stmt = @$conn->prepare("SELECT $cols FROM roles WHERE school_id = ? ORDER BY sort_order ASC, role_key ASC")) {
            $stmt->bind_param('i', $s);
            $stmt->execute();
            $got = $load($stmt->get_result());
            $stmt->close();
            // a school created before the template-copier ran -> fall back to the school-0 template
            if (!$got && $s !== 0 && ($st2 = @$conn->prepare("SELECT $cols FROM roles WHERE school_id = 0 AND role_key <> 'Super Admin' ORDER BY sort_order ASC, role_key ASC"))) {
                $st2->execute();
                $load($st2->get_result());
                $st2->close();
            }
        } else { // pre-migration schema — no school_id column yet
            $load(@$conn->query("SELECT $cols FROM roles ORDER BY sort_order ASC, role_key ASC"));
        }
    } catch (Exception $e) {
        // table not ready yet — return empty, callers fall back to defaults
    }
    return $cache[$s];
}

// one role by key, or null
function roleByKey($key) {
    foreach (readRoles() as $r) {
        if ($r['key'] === $key) return $r;
    }
    return null;
}

// can this role open/edit the matrix
function canEditRbac($role) {
    global $RBAC_EDIT_ROLES;
    return in_array($role, $RBAC_EDIT_ROLES, true);
}

// fallback perms when roles table empty/missing — Admin = all, else dash+logs view.
// Super Admin must be in here too: it is the recovery path when a school's matrix is broken
function rbacDefaultPerms($roleKey) {
    global $RBAC_PAGES;
    $out = [];
    $admin = in_array($roleKey, ['Admin', 'Super Admin'], true);
    foreach ($RBAC_PAGES as $p) {
        $view = $admin || in_array($p['key'], ['dashboard', 'logs'], true) ? 1 : 0;
        $out[$p['key']] = ['v' => $view, 'a' => $admin ? 1 : 0, 'e' => $admin ? 1 : 0, 'd' => $admin ? 1 : 0];
    }
    return $out;
}

// live perms for a role — table first, defaults as fallback
function rbacPermsFor($role) {
    $r = roleByKey($role);
    return ($r && !empty($r['perms'])) ? $r['perms'] : rbacDefaultPerms($role);
}

// bool — matrix is the single source of truth. only granted perms apply. unknown => false
function hasPerm($role, $pageKey, $perm = 'v') {
    $perms = rbacPermsFor($role);
    return !empty($perms[$pageKey][$perm]);
}

// shorthand on current session role
function can($pageKey, $perm = 'v') {
    return hasPerm($_SESSION['role'] ?? '', $pageKey, $perm);
}

// page-top guard — redirect for HTML, JSON for ajax/action requests
function requirePerm($pageKey, $perm = 'v') {
    $isAjax = isset($_GET['action']) || isset($_POST['action'])
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if (empty($_SESSION['user_id'])) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit(); }
        header('Location: login.php');
        exit();
    }
    if (!can($pageKey, $perm)) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
        $safe = can('dashboard', 'v') ? 'dashboard.php' : 'account.php';
        header('Location: ' . $safe);
        exit();
    }
}

// ajax action guard — json + exit if not logged in or denied
function requirePermJson($pageKey, $perm = 'v') {
    if (empty($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    if (!can($pageKey, $perm)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }
}

// roles offered on signup — public, non-super
function getSignupRoles() {
    $out = [];
    foreach (readRoles() as $r) {
        if (!$r['is_super'] && !$r['hidden_signup']) {
            $out[] = ['key' => $r['key'], 'label' => $r['label'], 'color' => $r['color']];
        }
    }
    return $out;
}

// ============================================
// ORMS — shared academic layer (years, terms, grading, ownership gates)
// every page calls these; academic tables may not exist pre-setup, so all reads are tolerant
// ============================================

// the one year flagged current — per school. is_current is a per-school invariant now
function ormsCurrentYear(): ?array {
    try {
        return qOne("SELECT * FROM academic_years WHERE is_current = 1 AND school_id = ? ORDER BY id DESC LIMIT 1", 'i', sid());
    } catch (Throwable $e) {
        try { return qOne("SELECT * FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1"); }
        catch (Throwable $e2) { return null; }
    }
}

// all years, newest first — this school only
function ormsYears(): array {
    try {
        return qAll("SELECT * FROM academic_years WHERE school_id = ? ORDER BY start_date DESC, id DESC", 'i', sid());
    } catch (Throwable $e) {
        try { return qAll("SELECT * FROM academic_years ORDER BY start_date DESC, id DESC"); }
        catch (Throwable $e2) { return []; }
    }
}

// terms of a year, optionally only the Open ones (entry window).
// the year must be ours, or the picker lists another school's term names
function ormsTerms(int $yearId, bool $openOnly = false): array {
    try {
        if (!ormsHasTenancy()) throw new RuntimeException('pre-migration');
        $sql = "SELECT t.* FROM exam_terms t JOIN academic_years y ON y.id = t.academic_year_id
                WHERE t.academic_year_id = ? AND y.school_id = ?"
             . ($openOnly ? " AND t.status = 'Open'" : '')
             . " ORDER BY t.sort_order ASC, t.id ASC";
        return qAll($sql, 'ii', $yearId, sid());
    } catch (Throwable $e) {
        try {
            $sql = "SELECT * FROM exam_terms WHERE academic_year_id = ?"
                 . ($openOnly ? " AND status = 'Open'" : '')
                 . " ORDER BY sort_order ASC, id ASC";
            return qAll($sql, 'i', $yearId);
        } catch (Throwable $e2) { return []; }
    }
}

// one term — via its year, so a foreign term id resolves to null instead of a usable row.
// this is what stops a publish/approve endpoint accepting another school's term.
function ormsTerm(int $termId): ?array {
    try {
        // public pages carry no session, so sid() is 0 there — fall back to the school the page
        // pinned. index.php has already proven the term belongs to it before calling in.
        $s = sid() ?: (int)($GLOBALS['ORMS_SETTING_SCHOOL'] ?? 0);
        return qOne("SELECT t.* FROM exam_terms t JOIN academic_years y ON y.id = t.academic_year_id
                     WHERE t.id = ? AND y.school_id = ?", 'ii', $termId, $s);
    } catch (Throwable $e) {
        try { return qOne("SELECT * FROM exam_terms WHERE id = ?", 'i', $termId); }
        catch (Throwable $e2) { return null; }
    }
}

// grading_sets + grading_scheme.set_id there yet? one probe per request - pre-migration installs keep the single scheme
function ormsHasGradingSets(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT 1 FROM grading_sets LIMIT 1"); qVal("SELECT set_id FROM grading_scheme LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// set rows for the pickers, cached per request PER SCHOOL
function ormsGradingSets(bool $activeOnly = true): array {
    static $cache = [];
    $k = sid() . ':' . ($activeOnly ? 1 : 0);
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        if (ormsHasGradingSets()) {
            try {
                $cache[$k] = qAll("SELECT id, name, description, is_default, is_active FROM grading_sets WHERE school_id = ?"
                    . ($activeOnly ? " AND is_active = 1" : '') . " ORDER BY is_default DESC, name ASC", 'i', sid());
            } catch (Throwable $e) {
                try {
                    $cache[$k] = qAll("SELECT id, name, description, is_default, is_active FROM grading_sets"
                        . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY is_default DESC, name ASC");
                } catch (Throwable $e2) { $cache[$k] = []; }
            }
        }
    }
    return $cache[$k];
}

// is_default -> lowest id -> 1. never 0, a card always has a set to grade against.
// 🚨 school-scoped: unscoped, a school with no default of its own graded its children against
// whichever school owned the lowest set id — the wrong grade letter on a printed report card.
function ormsDefaultSetId(): int {
    static $ids = [];
    $s = sid();
    if (!isset($ids[$s])) {
        $id = 1;
        if (ormsHasGradingSets()) {
            try {
                $id = (int)(qVal("SELECT id FROM grading_sets WHERE is_default = 1 AND is_active = 1 AND school_id = ? ORDER BY id ASC LIMIT 1", 'i', $s)
                         ?? qVal("SELECT id FROM grading_sets WHERE school_id = ? ORDER BY id ASC LIMIT 1", 'i', $s) ?? 1);
            } catch (Throwable $e) {
                try {
                    $id = (int)(qVal("SELECT id FROM grading_sets WHERE is_default = 1 AND is_active = 1 ORDER BY id ASC LIMIT 1")
                             ?? qVal("SELECT id FROM grading_sets ORDER BY id ASC LIMIT 1") ?? 1);
                } catch (Throwable $e2) { $id = 1; }
            }
        }
        $ids[$s] = $id < 1 ? 1 : $id;
    }
    return $ids[$s];
}

// class override -> default set. cached per school - a section build resolves once, never per row
function ormsSetForClass(?int $classId): int {
    static $cache = [];
    $k = sid() . ':' . (int)$classId;
    if (!isset($cache[$k])) {
        $v = 0;
        if ($classId && ormsHasGradingSets()) {
            // the class must be ours, and so must the set it points at
            try { $v = (int)(qVal("SELECT c.grading_set_id FROM classes c JOIN grading_sets gs ON gs.id = c.grading_set_id
                                   WHERE c.id = ? AND c.school_id = ? AND gs.school_id = ?", 'iii', (int)$classId, sid(), sid()) ?? 0); }
            catch (Throwable $e) {
                try { $v = (int)(qVal("SELECT grading_set_id FROM classes WHERE id = ?", 'i', (int)$classId) ?? 0); }
                catch (Throwable $e2) { $v = 0; }
            }
        }
        $cache[$k] = $v ?: ormsDefaultSetId();   // set beats default
    }
    return $cache[$k];
}

// ordered grade bands of one set (highest band first). null = the default set
function ormsGradingScheme(?int $setId = null): array {
    try {
        if (!ormsHasGradingSets()) return qAll("SELECT * FROM grading_scheme ORDER BY sort_order ASC, min_percent DESC");
        return qAll("SELECT * FROM grading_scheme WHERE set_id = ? ORDER BY sort_order ASC, min_percent DESC",
                    'i', $setId ?: ormsDefaultSetId());
    } catch (Throwable $e) { return []; }
}

// highest band at or below pct - bands can have gaps (80-89 then 90-100), BETWEEN would drop 89.5
function ormsGradeFor(float $percent, ?int $setId = null): ?array {
    $pct = round($percent, 2);
    try {
        if (!ormsHasGradingSets()) return qOne("SELECT * FROM grading_scheme WHERE min_percent <= ? ORDER BY min_percent DESC LIMIT 1", 'd', $pct);
        return qOne("SELECT * FROM grading_scheme WHERE set_id = ? AND min_percent <= ? ORDER BY min_percent DESC LIMIT 1",
                    'id', $setId ?: ormsDefaultSetId(), $pct);
    } catch (Throwable $e) { return null; }
}

// obtained/total as % — 2dp, divide-by-zero safe
function ormsPercent(float $obtained, float $total): float {
    return $total > 0 ? round($obtained / $total * 100, 2) : 0.0;
}

// iso 4217 => [symbol, name, legacy code]. keyed by CODE not symbol - $ / ₨ / £ / kr are each shared by many currencies
function ormsCurrencies(): array {
    static $c = null;
    return $c ??= [
        'AED' => ['د.إ', 'UAE Dirham', ''],           'AFN' => ['؋', 'Afghan Afghani', ''],
        'ALL' => ['L', 'Albanian Lek', ''],           'AMD' => ['֏', 'Armenian Dram', ''],
        'ANG' => ['ƒ', 'Netherlands Antillean Guilder', ''], 'AOA' => ['Kz', 'Angolan Kwanza', ''],
        'ARS' => ['$', 'Argentine Peso', ''],         'AUD' => ['A$', 'Australian Dollar', ''],
        'AWG' => ['ƒ', 'Aruban Florin', ''],          'AZN' => ['₼', 'Azerbaijani Manat', ''],
        'BAM' => ['KM', 'Bosnia-Herzegovina Convertible Mark', ''], 'BBD' => ['Bds$', 'Barbadian Dollar', ''],
        'BDT' => ['৳', 'Bangladeshi Taka', ''],       'BGN' => ['лв', 'Bulgarian Lev', ''],
        'BHD' => ['.د.ب', 'Bahraini Dinar', ''],      'BIF' => ['FBu', 'Burundian Franc', ''],
        'BMD' => ['BD$', 'Bermudian Dollar', ''],     'BND' => ['B$', 'Brunei Dollar', ''],
        'BOB' => ['Bs', 'Bolivian Boliviano', ''],    'BRL' => ['R$', 'Brazilian Real', ''],
        'BSD' => ['B$', 'Bahamian Dollar', ''],       'BTN' => ['Nu.', 'Bhutanese Ngultrum', ''],
        'BWP' => ['P', 'Botswanan Pula', ''],         'BYN' => ['Br', 'Belarusian Ruble', 'BYR'],
        'BZD' => ['BZ$', 'Belize Dollar', ''],        'CAD' => ['C$', 'Canadian Dollar', ''],
        'CDF' => ['FC', 'Congolese Franc', ''],       'CHF' => ['CHF', 'Swiss Franc', ''],
        'CLP' => ['$', 'Chilean Peso', ''],           'CNY' => ['¥', 'Chinese Yuan', ''],
        'COP' => ['$', 'Colombian Peso', ''],         'CRC' => ['₡', 'Costa Rican Colón', ''],
        'CUP' => ['$', 'Cuban Peso', ''],             'CVE' => ['$', 'Cape Verdean Escudo', ''],
        'CZK' => ['Kč', 'Czech Koruna', ''],          'DJF' => ['Fdj', 'Djiboutian Franc', ''],
        'DKK' => ['kr', 'Danish Krone', ''],          'DOP' => ['RD$', 'Dominican Peso', ''],
        'DZD' => ['د.ج', 'Algerian Dinar', ''],       'EGP' => ['E£', 'Egyptian Pound', ''],
        'ERN' => ['Nfk', 'Eritrean Nakfa', ''],       'ETB' => ['Br', 'Ethiopian Birr', ''],
        'EUR' => ['€', 'Euro', ''],                   'FJD' => ['FJ$', 'Fijian Dollar', ''],
        'FKP' => ['£', 'Falkland Islands Pound', ''], 'GBP' => ['£', 'British Pound Sterling', ''],
        'GEL' => ['₾', 'Georgian Lari', ''],          'GHS' => ['GH₵', 'Ghanaian Cedi', 'GHC'],
        'GIP' => ['£', 'Gibraltar Pound', ''],        'GMD' => ['D', 'Gambian Dalasi', ''],
        'GNF' => ['FG', 'Guinean Franc', ''],         'GTQ' => ['Q', 'Guatemalan Quetzal', ''],
        'GYD' => ['G$', 'Guyanaese Dollar', ''],      'HKD' => ['HK$', 'Hong Kong Dollar', ''],
        'HNL' => ['L', 'Honduran Lempira', ''],       'HTG' => ['G', 'Haitian Gourde', ''],
        'HUF' => ['Ft', 'Hungarian Forint', ''],      'IDR' => ['Rp', 'Indonesian Rupiah', ''],
        'ILS' => ['₪', 'Israeli New Shekel', ''],     'INR' => ['₹', 'Indian Rupee', ''],
        'IQD' => ['ع.د', 'Iraqi Dinar', ''],          'IRR' => ['﷼', 'Iranian Rial', ''],
        'ISK' => ['kr', 'Icelandic Króna', ''],       'JMD' => ['J$', 'Jamaican Dollar', ''],
        'JOD' => ['د.ا', 'Jordanian Dinar', ''],      'JPY' => ['¥', 'Japanese Yen', ''],
        'KES' => ['KSh', 'Kenyan Shilling', ''],      'KGS' => ['с', 'Kyrgystani Som', ''],
        'KHR' => ['៛', 'Cambodian Riel', ''],         'KMF' => ['CF', 'Comorian Franc', ''],
        'KPW' => ['₩', 'North Korean Won', ''],       'KRW' => ['₩', 'South Korean Won', ''],
        'KWD' => ['د.ك', 'Kuwaiti Dinar', ''],        'KYD' => ['CI$', 'Cayman Islands Dollar', ''],
        'KZT' => ['₸', 'Kazakhstani Tenge', ''],      'LAK' => ['₭', 'Laotian Kip', ''],
        'LBP' => ['ل.ل', 'Lebanese Pound', ''],       'LKR' => ['₨', 'Sri Lankan Rupee', ''],
        'LRD' => ['L$', 'Liberian Dollar', ''],       'LSL' => ['M', 'Lesotho Loti', ''],
        'LYD' => ['ل.د', 'Libyan Dinar', ''],         'MAD' => ['د.م.', 'Moroccan Dirham', ''],
        'MDL' => ['L', 'Moldovan Leu', ''],           'MGA' => ['Ar', 'Malagasy Ariary', ''],
        'MKD' => ['ден', 'Macedonian Denar', ''],     'MMK' => ['K', 'Myanmar Kyat', ''],
        'MNT' => ['₮', 'Mongolian Tugrik', ''],       'MOP' => ['MOP$', 'Macanese Pataca', ''],
        'MRU' => ['UM', 'Mauritanian Ouguiya', 'MRO'], 'MUR' => ['₨', 'Mauritian Rupee', ''],
        'MVR' => ['Rf', 'Maldivian Rufiyaa', ''],     'MWK' => ['MK', 'Malawian Kwacha', ''],
        'MXN' => ['$', 'Mexican Peso', ''],           'MYR' => ['RM', 'Malaysian Ringgit', ''],
        'MZN' => ['MT', 'Mozambican Metical', ''],    'NAD' => ['N$', 'Namibian Dollar', ''],
        'NGN' => ['₦', 'Nigerian Naira', ''],         'NIO' => ['C$', 'Nicaraguan Córdoba', ''],
        'NOK' => ['kr', 'Norwegian Krone', ''],       'NPR' => ['₨', 'Nepalese Rupee', ''],
        'NZD' => ['NZ$', 'New Zealand Dollar', ''],   'OMR' => ['ر.ع.', 'Omani Rial', ''],
        'PAB' => ['B/.', 'Panamanian Balboa', ''],    'PEN' => ['S/', 'Peruvian Sol', ''],
        'PGK' => ['K', 'Papua New Guinean Kina', ''], 'PHP' => ['₱', 'Philippine Peso', ''],
        'PKR' => ['₨', 'Pakistani Rupee', ''],        'PLN' => ['zł', 'Polish Złoty', ''],
        'PYG' => ['₲', 'Paraguayan Guarani', ''],     'QAR' => ['ر.ق', 'Qatari Riyal', ''],
        'RON' => ['lei', 'Romanian Leu', ''],         'RSD' => ['дин', 'Serbian Dinar', ''],
        'RUB' => ['₽', 'Russian Ruble', ''],          'RWF' => ['FRw', 'Rwandan Franc', ''],
        'SAR' => ['﷼', 'Saudi Riyal', ''],            'SBD' => ['SI$', 'Solomon Islands Dollar', ''],
        'SCR' => ['₨', 'Seychellois Rupee', ''],      'SDG' => ['ج.س', 'Sudanese Pound', ''],
        'SEK' => ['kr', 'Swedish Krona', ''],         'SGD' => ['S$', 'Singapore Dollar', ''],
        'SHP' => ['£', 'Saint Helena Pound', ''],     'SLE' => ['Le', 'Sierra Leonean Leone', 'SLL'],
        'SOS' => ['Sh', 'Somali Shilling', ''],       'SRD' => ['$', 'Surinamese Dollar', ''],
        'SSP' => ['£', 'South Sudanese Pound', ''],   'STN' => ['Db', 'São Tomé & Príncipe Dobra', 'STD'],
        'SVC' => ['₡', 'Salvadoran Colón', ''],       'SYP' => ['£', 'Syrian Pound', ''],
        'SZL' => ['L', 'Swazi Lilangeni', ''],        'THB' => ['฿', 'Thai Baht', ''],
        'TJS' => ['ЅМ', 'Tajikistani Somoni', ''],    'TMT' => ['m', 'Turkmenistani Manat', ''],
        'TND' => ['د.ت', 'Tunisian Dinar', ''],       'TOP' => ['T$', 'Tongan Paanga', ''],
        'TRY' => ['₺', 'Turkish Lira', ''],           'TTD' => ['TT$', 'Trinidad & Tobago Dollar', ''],
        'TWD' => ['NT$', 'New Taiwan Dollar', ''],    'TZS' => ['TSh', 'Tanzanian Shilling', ''],
        'UAH' => ['₴', 'Ukrainian Hryvnia', ''],      'UGX' => ['USh', 'Ugandan Shilling', ''],
        'USD' => ['$', 'US Dollar', ''],              'UYU' => ['$U', 'Uruguayan Peso', ''],
        'UZS' => ['som', 'Uzbekistani Som', ''],      'VES' => ['Bs.S', 'Venezuelan Bolívar', 'VEF'],
        'VND' => ['₫', 'Vietnamese Dong', ''],        'VUV' => ['VT', 'Vanuatu Vatu', ''],
        'WST' => ['WS$', 'Samoan Tala', ''],          'XAF' => ['FCFA', 'Central African CFA Franc', ''],
        'XCD' => ['EC$', 'East Caribbean Dollar', ''], 'XOF' => ['CFA', 'West African CFA Franc', ''],
        'XPF' => ['₣', 'CFP Franc', ''],              'YER' => ['﷼', 'Yemeni Rial', ''],
        'ZAR' => ['R', 'South African Rand', ''],     'ZMW' => ['ZK', 'Zambian Kwacha', ''],
        'ZWG' => ['ZiG', 'Zimbabwe Gold', 'ZWL'],
    ];
}

// anything stored/posted -> valid iso code. accepts code, legacy code (GHC) and the old symbol-only values
function ormsCurrencyCode(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return 'USD';
    $cur = ormsCurrencies();
    $up  = strtoupper($raw);
    if (isset($cur[$up])) return $up;
    foreach ($cur as $code => $m) if ($m[2] !== '' && $m[2] === $up) return $code;   // GHC -> GHS
    // pre-registry installs stored the symbol itself
    $legacy = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY', '₹' => 'INR', '₨' => 'PKR',
               '₱' => 'PHP', '₩' => 'KRW', '﷼' => 'SAR', 'RM' => 'MYR', 'R' => 'ZAR', 'kr' => 'SEK',
               'A$' => 'AUD', 'C$' => 'CAD', 'NZ$' => 'NZD'];
    return $legacy[$raw] ?? 'USD';
}

// active currency — ONE for the whole install. The App Owner owns it, so it reads the PLATFORM
// row flat (school 0) like platform_mode and the gateway keys: getSetting() lets a school row
// shadow the platform one, which is exactly how every tenant ended up on the seed default.
// not memoised on purpose — the symbol used to be cached per request and came out wrong inside
// an impersonation and in any loop that walked more than one school
function ormsCurrency(): array {
    $raw = '';
    try {
        $raw = (string) ormsPlatformSetting('currency_code', '');
        if ($raw === '') $raw = (string) ormsPlatformSetting('currency_symbol', '');
        if ($raw === '') {                  // update_setup not re-run yet: seed parked it on tenant #1
            $one = ormsSettingCache(1);     // read THAT flat too, so every role still agrees
            $raw = (string) ($one['currency_code'] ?? $one['currency_symbol'] ?? '');
        }
    } catch (Throwable $e) { $raw = ''; }
    $code = ormsCurrencyCode($raw);
    [$sym, $name] = ormsCurrencies()[$code];
    return ['code' => $code, 'symbol' => $sym, 'name' => $name];
}

// option label — carries code, name, symbol and legacy code so the search box matches any of them
function ormsCurrencyLabel(string $code): string {
    $m = ormsCurrencies()[$code] ?? null;
    if (!$m) return $code;
    return $code . ' — ' . $m[1] . ' (' . $m[0] . ($m[2] !== '' ? ', ' . $m[2] : '') . ')';
}

// every result_* setting in one hit, site branding as fallback
function ormsResultBranding(): array {
    $site = getSiteBranding();
    $out = [
        'result_school_name'     => $site['site_name'],
        'result_school_address'  => '',
        'result_school_phone'    => '',
        'result_logo'            => $site['site_logo'],
        'result_footer_note'     => '',
        'result_signature_left'  => 'Class Teacher',
        'result_signature_right' => 'Principal',
        'result_show_position'   => '1',
        'result_show_gpa'        => '1',
        'result_show_photo'      => '1',
        'result_show_qr'         => '1',
        // head teacher block on the card — name + signature image sit above the right-hand label
        'result_principal_name'        => '',
        'result_principal_designation' => 'Principal',
        'result_principal_signature'   => '',
        'result_show_principal_sign'   => '1',
        'result_show_principal_remark' => '1',
        // attendance / grade-key / CA columns — new card blocks, on unless admin turns them off
        'result_show_attendance'  => '1',
        'result_show_grade_key'   => '1',
        'result_show_ca_columns'  => '1'
    ];
    try {
        // school row beats the platform row — ORDER BY puts 0 first so the school overwrites it.
        // unscoped, the last row won and every page printed an arbitrary tenant's name and logo.
        $s = ormsSettingSchool();
        foreach (qAll("SELECT setting_key, setting_value FROM system_settings
                       WHERE setting_key LIKE 'result\\_%' AND school_id IN (0, ?)
                       ORDER BY school_id ASC", 'i', $s) as $r) {
            if (($r['setting_value'] ?? '') !== '') $out[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        try { // pre-migration schema — one global row per key
            foreach (qAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'result\\_%'") as $r) {
                if (($r['setting_value'] ?? '') !== '') $out[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e2) { /* settings not seeded yet — defaults stand */ }
    }
    return $out;
}

// teachers.id / students.id from a login user_id
function ormsTeacherId(int $userId): ?int {
    try {
        $id = qVal("SELECT id FROM teachers WHERE user_id = ?", 'i', $userId);
        return $id !== null ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

function ormsStudentId(int $userId): ?int {
    try {
        $id = qVal("SELECT id FROM students WHERE user_id = ?", 'i', $userId);
        return $id !== null ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

// a teacher's load for a year — readable rows for the assignment cards
function ormsTeacherAssignments(int $teacherId, int $yearId): array {
    try {
        return qAll(
            "SELECT ts.id, ts.teacher_id, ts.class_id, ts.section_id, ts.subject_id, ts.academic_year_id,
                    c.name AS class_name, sec.name AS section_name,
                    sub.name AS subject_name, sub.code AS subject_code,
                    cs.total_marks, cs.passing_marks
             FROM teacher_subjects ts
             JOIN classes  c   ON c.id   = ts.class_id
             JOIN sections sec ON sec.id = ts.section_id
             JOIN subjects sub ON sub.id = ts.subject_id
             LEFT JOIN class_subjects cs ON cs.class_id = ts.class_id AND cs.subject_id = ts.subject_id
             WHERE ts.teacher_id = ? AND ts.academic_year_id = ?
             ORDER BY c.sort_order ASC, c.name ASC, sec.name ASC, sub.name ASC",
            'ii', $teacherId, $yearId);
    } catch (Throwable $e) { return []; }
}

// section+term locked by publish
function ormsIsPublished(int $termId, int $sectionId): bool {
    try {
        return (int)qVal("SELECT is_published FROM result_publications WHERE term_id = ? AND section_id = ?", 'ii', $termId, $sectionId) === 1;
    } catch (Throwable $e) { return false; }
}

// per-class marks config (total/passing) for a subject
function ormsClassSubject(int $classId, int $subjectId): ?array {
    try {
        return qOne("SELECT * FROM class_subjects WHERE class_id = ? AND subject_id = ?", 'ii', $classId, $subjectId);
    } catch (Throwable $e) { return null; }
}

// student_subjects there yet? one probe per request — pre-migration installs keep the old maths
function ormsHasElectives(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT 1 FROM student_subjects LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// enrolment clause for every completion/entered query — ONE definition, aliases are m + cs2 everywhere
function ormsElectiveSql(): string {
    return ormsHasElectives()
        ? " AND (cs2.is_optional = 0 OR EXISTS (SELECT 1 FROM student_subjects ss2 WHERE ss2.student_id = m.student_id AND ss2.subject_id = m.subject_id AND ss2.academic_year_id = m.academic_year_id))"
        : '';
}

// optional-subject enrolment for a year — sid => [subject_id => 1]. null = table missing (no filtering)
function ormsElectiveMap(array $studentIds, int $yearId): ?array {
    if (!ormsHasElectives()) return null;
    $studentIds = array_map('intval', $studentIds);
    if (!$studentIds || !$yearId) return [];
    try {
        $out = array_fill_keys($studentIds, []);
        $ph  = implode(',', array_fill(0, count($studentIds), '?'));
        foreach (qAll("SELECT student_id, subject_id FROM student_subjects
                       WHERE academic_year_id = ? AND student_id IN ($ph)",
                      'i' . str_repeat('i', count($studentIds)), $yearId, ...$studentIds) as $r) {
            $out[(int)$r['student_id']][(int)$r['subject_id']] = 1;
        }
        return $out;
    } catch (Throwable $e) { return null; }
}

// ---- assessment components — CA/exam weighting, per class

// assessment tables + the marks snapshot cols there yet? one probe per request
function ormsHasAssessment(): bool {
    static $has = null;
    if ($has === null) {
        try {
            qVal("SELECT 1 FROM assessment_components LIMIT 1");
            qVal("SELECT assessment_scheme_id FROM classes LIMIT 1");
            qVal("SELECT ca_obtained FROM marks LIMIT 1");
            $has = true;
        } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// scheme rows for the pickers, cached per request PER SCHOOL
function ormsAssessmentSchemes(bool $activeOnly = true): array {
    static $cache = [];
    $k = sid() . ':' . ($activeOnly ? 1 : 0);
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        if (ormsHasAssessment()) {
            try {
                $cache[$k] = qAll("SELECT id, name, description, is_active FROM assessment_schemes WHERE school_id = ?"
                    . ($activeOnly ? " AND is_active = 1" : '') . " ORDER BY name ASC", 'i', sid());
            } catch (Throwable $e) {
                try {
                    $cache[$k] = qAll("SELECT id, name, description, is_active FROM assessment_schemes"
                        . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY name ASC");
                } catch (Throwable $e2) { $cache[$k] = []; }
            }
        }
    }
    return $cache[$k];
}

// null = no scheme -> single mark box, theory/practical rules stand. cached per school+class.
// the class AND the scheme it points at must both be ours — a foreign scheme changes the maths
function ormsSchemeForClass(?int $classId): ?int {
    static $cache = [];
    $k = sid() . ':' . (int)$classId;
    if (!array_key_exists($k, $cache)) {
        $v = null;
        if ($classId && ormsHasAssessment()) {
            try { $v = qVal("SELECT c.assessment_scheme_id FROM classes c JOIN assessment_schemes a ON a.id = c.assessment_scheme_id
                             WHERE c.id = ? AND c.school_id = ? AND a.school_id = ?", 'iii', (int)$classId, sid(), sid()); }
            catch (Throwable $e) {
                try { $v = qVal("SELECT assessment_scheme_id FROM classes WHERE id = ?", 'i', (int)$classId); }
                catch (Throwable $e2) { $v = null; }
            }
        }
        $cache[$k] = ((int)$v > 0) ? (int)$v : null;
    }
    return $cache[$k];
}

// ordered components of a scheme — cached, the grid + save loop hit this per subject
function ormsComponents(int $schemeId): array {
    static $cache = [];
    if (!isset($cache[$schemeId])) {
        $rows = [];
        if ($schemeId > 0 && ormsHasAssessment()) {
            try {
                $rows = qAll("SELECT id, scheme_id, name, max_marks, weight_percent, is_exam, sort_order
                              FROM assessment_components WHERE scheme_id = ? ORDER BY sort_order ASC, id ASC", 'i', $schemeId);
            } catch (Throwable $e) { $rows = []; }
        }
        $cache[$schemeId] = $rows;
    }
    return $cache[$schemeId];
}

// sum of weights — the subject's effective total once the scheme rescales it
function ormsComponentWeight(int $schemeId): float {
    return round(array_sum(array_map(fn($c) => (float)$c['weight_percent'], ormsComponents($schemeId))), 2);
}

// raw component scores -> the subject's weighted mark. rounds ONCE, at the end
function ormsConvertComponents(array $scores, array $comps): array {
    $obt = $ca = $exam = $wt = 0.0; $entered = false;
    foreach ($comps as $c) {
        $max = (float)$c['max_marks']; $w = (float)$c['weight_percent'];
        $wt += $w;
        $raw = $scores[(int)$c['id']] ?? null;
        if ($raw === null || $raw === '') continue;          // blank scores 0, never counts as entered
        $entered = true;
        $part = $max > 0 ? (float)$raw / $max * $w : 0.0;    // zero ceiling -> no divide
        $obt += $part;
        if ((int)($c['is_exam'] ?? 0) === 1) $exam += $part; else $ca += $part;
    }
    return ['entered' => $entered, 'obtained' => round($obt, 2), 'ca' => round($ca, 2),
            'exam' => round($exam, 2), 'weight' => round($wt, 2)];
}

// ---- attendance — days present / days the school was in session

function ormsHasAttendance(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT 1 FROM attendance_summary LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// whole section in ONE read — [student_id => ['present'=>f,'total'=>f]]
function ormsAttendanceMap(int $sectionId, int $termId): array {
    if (!$sectionId || !$termId || !ormsHasAttendance()) return [];
    try {
        $out = [];
        foreach (qAll("SELECT student_id, days_present, days_total FROM attendance_summary
                       WHERE section_id = ? AND term_id = ?", 'ii', $sectionId, $termId) as $r) {
            $out[(int)$r['student_id']] = ['present' => (float)$r['days_present'], 'total' => (float)$r['days_total']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

// one student — null when nothing recorded, so the card prints nothing instead of "0 / 0"
function ormsAttendanceFor(int $studentId, int $termId): ?array {
    if (!$studentId || !$termId || !ormsHasAttendance()) return null;
    try { $r = qOne("SELECT days_present, days_total FROM attendance_summary WHERE student_id = ? AND term_id = ?", 'ii', $studentId, $termId); }
    catch (Throwable $e) { return null; }
    if (!$r) return null;
    $p = (float)$r['days_present']; $t = (float)$r['days_total'];
    return ['present' => $p, 'total' => $t, 'pct' => $t > 0 ? round($p / $t * 100, 1) : 0.0];
}

// ---- fees & result withholding

function ormsHasFees(): bool {
    static $has = null;
    if ($has === null) {
        try { qVal("SELECT 1 FROM student_fees LIMIT 1"); qVal("SELECT fee_hold FROM students LIMIT 1"); $has = true; }
        catch (Throwable $e) { $has = false; }
    }
    return $has;
}

// charges minus payments — ONE grouped query for the whole list, never a query per student
function ormsFeeBalanceMap(array $studentIds, ?int $yearId = null): array {
    $ids = array_values(array_unique(array_map('intval', $studentIds)));
    if (!$ids || !ormsHasFees()) return [];
    try {
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $sch = ormsHasTenancy() ? " AND school_id = ?" : '';   // defence in depth — ids are global
        $sql = "SELECT student_id, SUM(CASE WHEN entry_type = 'Payment' THEN -amount ELSE amount END) AS bal
                FROM student_fees WHERE student_id IN ($ph)" . ($yearId ? " AND academic_year_id = ?" : '') . $sch . "
                GROUP BY student_id";
        $types = str_repeat('i', count($ids));
        $args  = $ids;
        if ($yearId) { $types .= 'i'; $args[] = (int)$yearId; }
        if ($sch)    { $types .= 'i'; $args[] = sid(); }
        $out = [];
        foreach (qAll($sql, $types, ...$args) as $r) $out[(int)$r['student_id']] = round((float)$r['bal'], 2);
        return $out;
    } catch (Throwable $e) { return []; }
}

function ormsFeeBalance(int $studentId, ?int $yearId = null): float {
    return ormsFeeBalanceMap([$studentId], $yearId)[$studentId] ?? 0.0;
}

// master switch + cutoff, read once per request (called inside section loops)
function ormsWithholdEnabled(): bool { static $v = null; return $v ??= (string)getSetting('withhold_on_arrears', '0') === '1'; }

function ormsArrearsThreshold(): float { static $v = null; return $v ??= (float)getSetting('arrears_threshold', '0'); }

// students on a manual hold — one read per request per school, held rows only (normally a handful)
function ormsFeeHoldMap(): array {
    static $maps = [];
    $s = sid();
    if (!isset($maps[$s])) {
        $maps[$s] = [];
        if (ormsHasFees()) {
            try {
                foreach (qAll("SELECT id, fee_hold_note FROM students WHERE fee_hold = 1 AND school_id = ?", 'i', $s) as $r)
                    $maps[$s][(int)$r['id']] = trim((string)($r['fee_hold_note'] ?? ''));
            } catch (Throwable $e) {
                try {
                    foreach (qAll("SELECT id, fee_hold_note FROM students WHERE fee_hold = 1") as $r)
                        $maps[$s][(int)$r['id']] = trim((string)($r['fee_hold_note'] ?? ''));
                } catch (Throwable $e2) { $maps[$s] = []; }
            }
        }
    }
    return $maps[$s];
}

// manual hold beats everything, then the arrears rule. pass $balance from ormsFeeBalanceMap in a loop
function ormsWithholdCheck(int $studentId, ?int $yearId = null, ?float $balance = null): array {
    $clear = ['withheld' => false, 'reason' => ''];
    if (!ormsHasFees()) return $clear;
    $hold = ormsFeeHoldMap();
    if (isset($hold[$studentId])) {
        return ['withheld' => true, 'reason' => mb_substr($hold[$studentId] !== '' ? $hold[$studentId] : 'On hold by the school', 0, 150)];
    }
    if (!ormsWithholdEnabled()) return $clear;
    $bal = $balance ?? ormsFeeBalance($studentId, $yearId);
    return $bal > ormsArrearsThreshold()
        ? ['withheld' => true, 'reason' => mb_substr('Outstanding balance ' . ormsMoney($bal), 0, 150)]
        : $clear;
}

// symbol + 2dp. $code prints a SPECIFIC currency — a stored invoice, payment or period row keeps
// whatever it was written in. blank/omitted = the install's one currency.
// deliberately not cached: the static symbol was wrong across impersonation and cross-school loops
function ormsMoney(float $v, ?string $code = null): string {
    $c   = $code === null ? '' : trim($code);
    $sym = $c === '' ? ormsCurrency()['symbol'] : (ormsCurrencies()[ormsCurrencyCode($c)][0] ?? '');
    return $sym . number_format($v, 2);
}

// per-class position toggle — ANDed with the global result_show_position setting
function ormsClassShowsPosition(?int $classId): bool {
    static $cache = [];
    $k = (int)$classId;
    if (!isset($cache[$k])) {
        $v = 1;
        if ($k) { try { $v = qVal("SELECT show_position FROM classes WHERE id = ?", 'i', $k) ?? 1; } catch (Throwable $e) { $v = 1; } }
        $cache[$k] = (int)$v === 1;
    }
    return $cache[$k];
}

// absolute url a printed QR resolves to — token is the capability, nothing else rides in it
function ormsVerifyUrl(string $token): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return 'index.php?verify=' . rawurlencode($token);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/index.php?verify=' . rawurlencode($token);
}

// gate: term Open AND not published AND (admin OR teacher owns this section+subject this year)
// teacher resolved from session user_id — nothing here trusts the client
function ormsCanEnterMarks(int $userId, string $role, int $sectionId, int $subjectId, int $termId): bool {
    $term = ormsTerm($termId);
    if (!$term || ($term['status'] ?? '') !== 'Open') return false;
    if (ormsIsPublished($termId, $sectionId)) return false;
    // the section must be ours too — ormsTerm() proves the term, not the section it's paired with
    if (ormsHasTenancy() && !ormsIsPlatform() && !ormsOwns('sections', $sectionId)) return false;
    if ($role === 'Admin') return true;

    $teacherId = ormsTeacherId($userId);
    if (!$teacherId) return false; // not a teacher -> no ownership possible

    // ownership must match the TERM's year, not whatever year is current — otherwise
    // this year's section holder could post into an open term of a past year
    $yearId = (int)($term['academic_year_id'] ?? 0);
    if (!$yearId) { $year = ormsCurrentYear(); $yearId = $year ? (int)$year['id'] : 0; }
    if (!$yearId) return false;

    try {
        return (int)qVal("SELECT COUNT(*) FROM teacher_subjects
                          WHERE teacher_id = ? AND section_id = ? AND subject_id = ? AND academic_year_id = ?",
                         'iiii', $teacherId, $sectionId, $subjectId, $yearId) > 0;
    } catch (Throwable $e) { return false; }
}

// marks changed after the head signed off -> that approval no longer describes the data, so it
// must be earned again. entry is locked once a section is live, so this only ever catches
// Approved-but-unpublished. lives here, not in result_engine, because marks_entry.php loads config only.
// read side of the family (ormsApprovalStatus/Required/Blocked) is in result_engine.php.
function ormsApprovalInvalidate(int $termId, int $sectionId): int {
    try {
        return qExec("UPDATE result_publications
                         SET approval_status = 'Draft', submitted_by = NULL, submitted_at = NULL,
                             approved_by = NULL, approved_at = NULL,
                             review_note = 'Reset automatically - marks changed after approval'
                       WHERE term_id = ? AND section_id = ? AND is_published = 0 AND approval_status = 'Approved'",
                     'ii', $termId, $sectionId);
    } catch (Throwable $e) { return 0; }   // pre-migration db -> no approval trail to reset
}

// ============================================
// Google OAuth — one place builds the authorize url, so the CSRF state can never be forgotten
// ============================================

// mints a single-use state into the session, returns the authorize url ('' when oauth is off/unconfigured)
function ormsGoogleAuthUrl(): string {
    try {
        if (getSetting('google_oauth_enabled', '0') !== '1') return '';
        $cid = (string)getSetting('google_client_id', '');
        $uri = (string)getSetting('google_redirect_uri', '');
        if ($cid === '' || $uri === '') return '';

        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16)); // burned on callback

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $cid,
            'redirect_uri'  => $uri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
            'state'         => $_SESSION['oauth_state']
        ]);
    } catch (Throwable $e) { return ''; } // settings/entropy unavailable -> hide the button
}

// one-shot state check — always clears the stored value, match or not
function ormsCheckOAuthState($state): bool {
    $expected = isset($_SESSION['oauth_state']) ? (string)$_SESSION['oauth_state'] : '';
    unset($_SESSION['oauth_state']);
    return $expected !== '' && is_string($state) && $state !== '' && hash_equals($expected, $state);
}

// login gate shared by every entry point — deactivated staff/students keep no way in
function ormsAccountActive($row): bool {
    return !is_array($row) || !array_key_exists('is_active', $row) || (int)$row['is_active'] === 1;
}

// ============================================
// Forced Password Change
// ============================================

// every student is created on the same default password — retire the flag the moment it changes
function ormsPasswordChanged($user_id) {
    $uid = (int)$user_id;
    if ($uid <= 0) return;
    try {
        qExec("UPDATE users SET must_change_password = 0, password_changed_at = NOW() WHERE id = ?", 'i', $uid);
    } catch (Throwable $e) {
        error_log("Password flag clear error: " . $e->getMessage());
    }
    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $uid) unset($_SESSION['must_change_password']);
}

// is this session parked behind a forced password change?
function ormsPasswordChangeDue(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['must_change_password']) && empty($_SESSION['impersonator_id']);
}

// hold the user on the change-password page until it's done. logout/login/installer stay open
// so nobody can get locked in, and ajax gets json instead of a 302 into html
function ormsEnforcePasswordChange(): void {
    if (!ormsPasswordChangeDue()) return;

    $here = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    // renew.php is in here so a user who is BOTH past-due and force-password-flagged doesn't
    // ping-pong: the subscription gate sends them to renew, this one would bounce them to account
    $open = ['account.php', 'logout.php', 'login.php', 'impersonate.php', 'setup.php', 'update_setup.php',
             'maintenance.php', 'manifest.php', 'theme_save.php', 'oauth_callback.php', 'verify_otp.php', 'renew.php'];
    if (in_array($here, $open, true)) return;

    $ajax = isset($_GET['action']) || isset($_POST['action'])
            || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0;
    if ($ajax) jsonErr('Please change your password before continuing.', ['force_password' => true]);

    header('Location: account.php?force_password=1');
    exit();
}

// ============================================
// Web Push — audited delivery
// ============================================
// defined BEFORE webpush_helper.php loads, so its function_exists guards keep these versions.
// dead endpoints get counted and retired instead of silently vanishing.

if (!function_exists('webpushDeliver')) {
    // send to a list of subs; count failures, retire endpoints the service says are gone. never throws
    function webpushDeliver($conn, $subs, $title, $body, $url) {
        $res = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        if (empty($subs)) return $res;

        // vapid/aes128gcm crypto still lives in the helper — pull it in if a caller skipped it
        if (!function_exists('webpushSendOne') && is_file(__DIR__ . '/webpush_helper.php')) require_once __DIR__ . '/webpush_helper.php';
        if (!function_exists('webpushSendOne')) return $res;

        foreach ($subs as $s) {
            $id = (int)$s['id'];
            $code = webpushSendOne($s, $title, $body, $url);

            if ($code >= 200 && $code < 300) {
                qExec("UPDATE push_subscriptions SET last_used_at = NOW(), failed_count = 0 WHERE id = ?", 'i', $id);
                $res['sent']++;
            } elseif ($code === 404 || $code === 410) {   // endpoint permanently gone
                qExec("UPDATE push_subscriptions SET is_active = 0, failed_count = failed_count + 1 WHERE id = ?", 'i', $id);
                $res['pruned']++;
            } else {
                qExec("UPDATE push_subscriptions SET failed_count = failed_count + 1 WHERE id = ?", 'i', $id);
                $res['failed']++;
            }
        }
        return $res;
    }
}

if (!function_exists('webpushSendToUser')) {
    // push to one user's live devices only. best-effort
    function webpushSendToUser($user_id, $title, $body, $url = 'dashboard.php') {
        $res = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        try {
            if (getSetting('enable_web_push', '1') !== '1') return $res;
            $subs = qAll("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND is_active = 1", 'i', (int)$user_id);
            $res = webpushDeliver(getDBConnection(), $subs, $title, $body, $url);
        } catch (Throwable $e) {
            error_log('webpushSendToUser: ' . $e->getMessage());
        }
        return $res;
    }
}

if (!function_exists('webpushBroadcast')) {
    // push to ALL live devices. best-effort
    function webpushBroadcast($title, $body, $url = 'dashboard.php') {
        $res = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        try {
            if (getSetting('enable_web_push', '1') !== '1') return $res;
            $subs = qAll("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE is_active = 1");
            $res = webpushDeliver(getDBConnection(), $subs, $title, $body, $url);
        } catch (Throwable $e) {
            error_log('webpushBroadcast: ' . $e->getMessage());
        }
        return $res;
    }
}

/**
 * Subscription gate. Mirrors ormsEnforcePasswordChange() below: basename allow-list, then an
 * AJAX branch that answers json instead of redirecting (a 302-to-HTML into JSON.parse breaks
 * every one of the ~200 ajax actions).
 *
 * Fails OPEN on any error, and is completely inert while platform_mode = 0 — a billing gate that
 * fails closed would take every school offline over one bad query, and the single-school install
 * that just migrated must not notice any of this exists.
 */
function ormsSubscriptionState(?int $school = null): array {
    static $c = [];
    $s = $school ?? sid();
    if (isset($c[$s])) return $c[$s];
    $out = ['status' => 'active', 'ends_at' => null];   // default open
    try {
        $sc = qOne("SELECT status, trial_ends_at FROM schools WHERE id = ?", 'i', $s);
        if (!$sc) return $c[$s] = $out;
        if (in_array($sc['status'], ['Suspended', 'Cancelled'], true)) return $c[$s] = ['status' => strtolower($sc['status']), 'ends_at' => null];

        $today = date('Y-m-d');
        $sub = qOne("SELECT ends_at, status FROM school_subscriptions WHERE school_id = ? ORDER BY ends_at DESC, id DESC LIMIT 1", 'i', $s);
        if (!$sub) {
            // never billed. a Trial school still has to expire, or trial_ends_at means nothing
            if ($sc['status'] === 'Trial' && !empty($sc['trial_ends_at'])) {
                $out['ends_at'] = $sc['trial_ends_at'];
                if ($sc['trial_ends_at'] < $today) $out['status'] = 'expired';
            }
            return $c[$s] = $out;
        }
        $out['ends_at'] = $sub['ends_at'];
        if ($sub['status'] !== 'Active' || $sub['ends_at'] < $today) $out['status'] = 'expired';
    } catch (Throwable $e) { /* pre-migration db -> stay open */ }
    return $c[$s] = $out;
}

/**
 * THE saas switch. Read from the PLATFORM row only.
 * getSetting() merges school-over-platform, which is right for branding and wrong for this: a tenant
 * carrying its own platform_mode = 0 row (settings.php has saved the whole key set school-scoped in
 * the past) silently shadowed the platform's 1, and inside that school BOTH the subscription expiry
 * gate and the plan limits went inert while the operator's console still showed the SaaS as live.
 * installerPlatformMode() in update_setup.php has always read school 0 directly — this now matches it.
 */
function ormsPlatformMode(): bool { return (string) ormsPlatformSetting('platform_mode', '0') === '1'; }

// resolve a school code to its id. 0 = blank (platform login), -1 = bad/unknown/cancelled, >0 = the school.
// lives here so every auth page (login, signup, forgot/reset password) shares one definition; login.php
// and signup.php keep a function_exists-guarded local copy that now no-ops against this global.
if (!function_exists('ormsSchoolFromCode')) {
    function ormsSchoolFromCode(string $code): int {
        if ($code === '') return 0;
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $code)) return -1;
        try { $r = qOne("SELECT id, status FROM schools WHERE code = ? LIMIT 1", 's', $code); }
        catch (Throwable $e) { return -1; }               // pre-migration db — no schools table
        return (!$r || $r['status'] === 'Cancelled') ? -1 : (int)$r['id'];
    }
}

function ormsSubscriptionBlocked(): bool {
    if (!ormsPlatformMode()) return false;              // single-school install -> gate is off entirely
    if (empty($_SESSION['user_id']) || ormsIsPlatform()) return false;
    return in_array(ormsSubscriptionState()['status'], ['expired', 'suspended', 'cancelled'], true);
}

function ormsEnforceSubscription(): void {
    if (!ormsSubscriptionBlocked()) return;
    $here = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    // logout MUST stay open or a lapsed school is trapped; impersonate stops a super admin being stuck inside one
    $open = ['renew.php', 'billing.php', 'payment_return.php', 'payment_webhook.php',
             'logout.php', 'login.php', 'account.php', 'impersonate.php',
             'setup.php', 'update_setup.php', 'maintenance.php', 'manifest.php', 'theme_save.php'];
    if (in_array($here, $open, true)) return;

    if (isset($_GET['action']) || isset($_POST['action'])
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')) {
        jsonErr('This school\'s subscription has expired.', ['subscription_expired' => true]);
    }
    header('Location: renew.php');
    exit();
}

/* ===================================================================
   SaaS layer — who owns what, and how much of it they may have.
   Two owners exist and they are not the same person:
     App Owner    — ONE account, school 0, role 'Super Admin'. Owns the platform.
     School Owner — one per tenant, role 'School Owner'. Owns that school's subscription.
   =================================================================== */

// platform-scoped read. getSetting() deliberately lets a school row shadow the platform row — that
// is how branding works — but applying it to gateway credentials would be a payment-redirection
// hole: a school with 'settings' edit rights could point checkout at its own merchant account.
// Anything a gateway is trusted with reads school 0 flat, never the inherited cache.
function ormsPlatformSetting(string $key, $default = null) {
    $all = ormsSettingCache(0);
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

// platform-scoped write. pins the setting scope for one call, then puts it back exactly as it was
// (unset vs. set matters — ormsSettingSchool() falls through to the session when the key is absent).
function ormsPlatformSet(string $key, string $val): bool {
    $had  = array_key_exists('ORMS_SETTING_SCHOOL', $GLOBALS);
    $prev = $had ? $GLOBALS['ORMS_SETTING_SCHOOL'] : null;
    $GLOBALS['ORMS_SETTING_SCHOOL'] = 0;
    try { return setSetting($key, $val); }
    finally {
        if ($had) $GLOBALS['ORMS_SETTING_SCHOOL'] = $prev;
        else      unset($GLOBALS['ORMS_SETTING_SCHOOL']);
    }
}

// ---- the two owners ----------------------------------------------------

// exactly one operator account: the lowest-id Super Admin at school 0. users.php refuses to mint a
// second, to delete it, to deactivate it, or to move it off the role — otherwise the platform can
// be locked out of itself with one careless edit and nothing can grant the role back.
function ormsAppOwnerId(): int {
    static $id = null;
    if ($id !== null) return $id;
    try { $id = (int) qVal("SELECT id FROM users WHERE role = 'Super Admin' AND school_id = 0 ORDER BY id ASC LIMIT 1"); }
    catch (Throwable $e) { $id = 0; }
    return $id;
}

function ormsIsAppOwner(?int $uid = null): bool {
    $u = (int) ($uid ?? ($_SESSION['user_id'] ?? 0));
    return $u > 0 && $u === ormsAppOwnerId();
}

/**
 * The tenant's owner. The ROLE is the source of truth and schools.owner_user_id is only a pointer at
 * it — that pointer goes stale on a fresh install, where applyUpdates() backfills the oldest Admin
 * before the School Owner account is seeded at all. Reading the pointer first meant the wrong account
 * was reported as owner and, worse, protected from deletion while the real owner was not.
 * The pointer is still honoured when nobody holds the role, so a school is never ownerless.
 */
function ormsSchoolOwnerId(?int $school = null): int {
    $s = $school ?? sid();
    if ($s <= 0) return 0;
    try {
        $byRole = (int) qVal("SELECT id FROM users WHERE school_id = ? AND role = 'School Owner' AND is_active = 1 ORDER BY id ASC LIMIT 1", 'i', $s);
        if ($byRole) return $byRole;
        $o = (int) qVal("SELECT owner_user_id FROM schools WHERE id = ?", 'i', $s);
        return ($o && qVal("SELECT id FROM users WHERE id = ? AND school_id = ? AND is_active = 1", 'ii', $o, $s)) ? $o : 0;
    } catch (Throwable $e) { return 0; }
}

function ormsIsSchoolOwner(?int $uid = null): bool {
    $u = (int) ($uid ?? ($_SESSION['user_id'] ?? 0));
    return $u > 0 && $u === ormsSchoolOwnerId();
}

// Accounts that must survive a careless edit. Returns 'app_owner', 'school_owner', or '' — the
// callers phrase their own refusal, because "you cannot delete this" and "you cannot demote this"
// are different sentences for the same protected row.
function ormsProtectedAccount(int $uid, ?int $school = null): string {
    if ($uid <= 0) return '';
    if (ormsIsAppOwner($uid)) return 'app_owner';
    $s = $school;
    if ($s === null) { try { $s = (int) qVal("SELECT school_id FROM users WHERE id = ?", 'i', $uid); } catch (Throwable $e) { $s = 0; } }
    return ($s > 0 && $uid === ormsSchoolOwnerId($s)) ? 'school_owner' : '';
}

// ---- uploads -----------------------------------------------------------
/**
 * ONE image uploader. Several pages were each rolling the same "check the size, sniff the real mime,
 * prove it is an image, invent a name, move it" — the next caller asks this instead.
 * Nothing the client says is trusted: the extension comes from what finfo actually saw (never the
 * filename), and getimagesize() is what stops a renamed script from landing in a web-served folder.
 * Returns [relative path, "WxH"]; exits with jsonErr on anything it refuses.
 */
function ormsSaveImageUpload(?array $f, string $relDir, string $prefix, string $label = 'Image', int $maxMb = 2): array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jsonErr('No image received. Pick a file and try again.');
    if ((int) ($f['size'] ?? 0) > $maxMb * 1024 * 1024) jsonErr($label . ' must be under ' . $maxMb . 'MB');

    $mime  = '';
    if ($finfo = finfo_open(FILEINFO_MIME_TYPE)) { $mime = (string) finfo_file($finfo, $f['tmp_name']); finfo_close($finfo); }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) jsonErr('Only JPG, PNG or WEBP images are allowed');

    $dim = @getimagesize($f['tmp_name']);
    if (!$dim || empty($dim[0]) || empty($dim[1])) jsonErr('That file is not a real image');

    $relDir = trim($relDir, '/') . '/';
    $dir    = __DIR__ . '/' . $relDir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) jsonErr('Could not create ' . $relDir . ' — check folder permissions');

    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];   // never the client's name
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) jsonErr('Upload failed — check folder permissions');
    return [$relDir . $name, $dim[0] . 'x' . $dim[1]];
}

// delete a file THIS app wrote. Refuses anything outside uploads/, because a stored path is data and
// data that reaches unlink() is how a tidy-up button becomes an arbitrary file delete.
function ormsDropUpload(string $rel): void {
    $rel = ltrim(trim($rel), '/');
    if ($rel === '' || strncmp($rel, 'uploads/', 8) !== 0 || strpos($rel, '..') !== false) return;
    $p    = realpath(__DIR__ . '/' . $rel);
    $base = realpath(__DIR__ . '/uploads');
    if ($p && $base && strncmp($p, $base, strlen($base)) === 0 && is_file($p)) @unlink($p);
}

// ---- chain of authority ------------------------------------------------
/**
 * ONE ladder, top to bottom: App Owner -> School Owner -> Admin -> Principal -> Branch Admin ->
 * Teacher -> Student. Lower number = more authority.
 * It is PINNED here and deliberately NOT read from roles.sort_order: roles.php lets an Admin
 * re-order the matrix, so a rank taken from that column could be edited into "Admin outranks the
 * Owner" in two clicks. A role invented in roles.php parks just under Admin whatever sort it carries.
 */
function ormsRoleLadder(): array {
    return ['Super Admin' => 0, 'School Owner' => 10, 'Admin' => 20, 'Principal' => 30,
            'Branch Admin' => 40, 'Teacher' => 50, 'Student' => 60];
}

function ormsRoleRank(?string $roleKey = null): int {
    $k = (string) ($roleKey ?? ($_SESSION['role'] ?? ''));
    $ladder = ormsRoleLadder();
    if (isset($ladder[$k])) return $ladder[$k];
    $r = roleByKey($k);
    return $r ? max(25, 25 + (int) $r['sort']) : 99;   // custom role: below Admin, never above it
}

// human label for a rank line — straight from the live matrix, so a renamed role reads right
function ormsRoleLabel(string $roleKey): string {
    $r = roleByKey($roleKey);
    return $r['label'] ?? ($roleKey !== '' ? $roleKey : 'Unknown');
}

// strongest LIVE account in a school. The hand-over case needs it: an install whose top account is
// an Admin (no owner seeded yet) must still be able to mint the School Owner, or ownership is a dead end.
function ormsTopRank(?int $school = null): int {
    $s = $school ?? sid();
    if ($s <= 0) return 0;
    static $c = [];
    if (isset($c[$s])) return $c[$s];
    $best = 99;
    try {
        foreach (qAll("SELECT DISTINCT role FROM users WHERE school_id = ? AND is_active = 1", 'i', $s) as $r)
            $best = min($best, ormsRoleRank((string) $r['role']));
    } catch (Throwable $e) {}
    return $c[$s] = $best;
}

/**
 * May the caller hand out this role? Never one stronger than their own — that is how a Principal
 * holding the users page promotes itself to Admin, or an Admin mints an Owner login and walks
 * straight into billing. One exception: if nobody in the school outranks the caller they ARE the top
 * of the chain, so they may create the role above them (the install that has no Owner yet).
 */
function ormsCanAssignRole(string $targetRole, ?string $actorRole = null): bool {
    if (ormsIsPlatform()) return true;                       // the operator sits above every school
    $mine = ormsRoleRank($actorRole);
    return ormsRoleRank($targetRole) >= $mine || ormsTopRank() >= $mine;
}

/**
 * May the caller edit / delete / impersonate THIS account? '' = yes, else the refusal sentence.
 * Rank only — which school the row belongs to is a separate gate and still applies.
 */
function ormsOutranks(int $targetUid, ?string $targetRole = null): string {
    if ($targetUid <= 0) return '';
    if ($targetUid === (int) ($_SESSION['user_id'] ?? 0)) return '';   // your own row is your own business
    if (ormsIsPlatform()) return '';
    if ($targetRole === null) {
        try { $targetRole = (string) qVal("SELECT role FROM users WHERE id = ?", 'i', $targetUid); }
        catch (Throwable $e) { $targetRole = ''; }
    }
    if (ormsRoleRank($targetRole) >= ormsRoleRank()) return '';
    return ormsRoleLabel((string) $targetRole) . ' sits above ' . ormsRoleLabel((string) ($_SESSION['role'] ?? '')) .
           ' in the chain of authority — only that account itself or the App Owner can manage it.';
}

/* ---- automatic expiry --------------------------------------------------
   Nothing ever WROTE an expiry. ormsSubscriptionState() compares dates at read time, so a lapsed
   school was blocked correctly while its subscription row still read "Active" and the operator's
   list showed a period that ended months ago as live — and a pending invoice only expired if
   somebody happened to open the Invoices tab. This is the sweep that makes the stored state match
   the calendar, and it is the only thing that does.
   Idempotent by construction: every statement moves rows that are ALREADY past their date, so a
   second run in the same minute changes nothing.
*/
function ormsAutoExpireCfg(): array {
    return [
        'on'      => (string) ormsPlatformSetting('billing_autoexpire', '1') === '1',
        'subs'    => (string) ormsPlatformSetting('billing_expire_subs', '1') === '1',
        'ttl'     => max(1, min(365, (int) ormsPlatformSetting('billing_invoice_ttl_days', '30'))),
        'suspend' => max(0, min(365, (int) ormsPlatformSetting('billing_suspend_days', '0'))),   // 0 = never
        'last'    => (string) ormsPlatformSetting('billing_autoexpire_last', ''),
    ];
}

function ormsAutoExpire(bool $manual = false): array {
    $cfg = ormsAutoExpireCfg();
    $out = ['ran' => false, 'invoices' => 0, 'subscriptions' => 0, 'schools' => 0, 'at' => $cfg['last']];
    if (!$manual && !$cfg['on']) return $out;

    // 1. invoices nobody paid. The window is a setting now, not a number buried in a query.
    try {
        $out['invoices'] = (int) qExec("UPDATE billing_invoices SET status = 'Expired'
                                        WHERE status = 'Pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                                       'i', $cfg['ttl']);
    } catch (Throwable $e) { error_log('autoexpire invoices: ' . $e->getMessage()); }

    // 2. periods whose end date has passed. An old row that has already been renewed past is still
    //    expired — it is history, and history that says "Active" is a lie in every report.
    if ($cfg['subs']) {
        try {
            $out['subscriptions'] = (int) qExec("UPDATE school_subscriptions SET status = 'Expired'
                                                 WHERE status = 'Active' AND ends_at < CURDATE()");
        } catch (Throwable $e) { error_log('autoexpire subscriptions: ' . $e->getMessage()); }
    }

    // 3. suspend, only if the operator asked for it (0 = never, and that is the default: no existing
    //    install should start switching schools off the morning after a deploy). The test is the
    //    NEWEST period or the trial date — never an old row the school has already renewed past.
    if ($cfg['suspend'] > 0) {
        try {
            $out['schools'] = (int) qExec(
                "UPDATE schools s
                    LEFT JOIN (SELECT school_id, MAX(ends_at) AS ends_at FROM school_subscriptions GROUP BY school_id) x
                           ON x.school_id = s.id
                    SET s.status = 'Suspended'
                  WHERE s.status IN ('Active', 'Trial')
                    AND COALESCE(x.ends_at, s.trial_ends_at) IS NOT NULL
                    AND COALESCE(x.ends_at, s.trial_ends_at) < DATE_SUB(CURDATE(), INTERVAL ? DAY)",
                'i', $cfg['suspend']);
        } catch (Throwable $e) { error_log('autoexpire schools: ' . $e->getMessage()); }
    }

    $out['ran'] = true;
    $out['at']  = date('Y-m-d H:i:s');
    ormsPlatformSet('billing_autoexpire_last', $out['at']);

    // a sweep that changed nothing is noise; one that switched a school off is the opposite
    if ($out['invoices'] + $out['subscriptions'] + $out['schools'] > 0) {
        try {
            logActivity((int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['username'] ?? 'system'),
                'Auto Expiry', ($manual ? 'Manual run' : 'Scheduled run') . ' — ' .
                $out['invoices'] . ' invoice(s), ' . $out['subscriptions'] . ' subscription(s), ' .
                $out['schools'] . ' school(s) suspended', 'system_settings', null, 0);
        } catch (Throwable $e) {}
    }
    return $out;
}

// This app has no cron, so the sweep rides a signed-in request — at most once an hour, and never for
// an anonymous visitor. A state that only changes when somebody opens the right page is not a state
// any report can be trusted on, which is exactly the bug this closes.
function ormsAutoExpireTick(): void {
    try {
        if (!ormsPlatformMode() || empty($_SESSION['user_id'])) return;
        $cfg = ormsAutoExpireCfg();
        if (!$cfg['on']) return;
        if ($cfg['last'] !== '' && strtotime($cfg['last']) > time() - 3600) return;
        ormsAutoExpire();
    } catch (Throwable $e) { error_log('autoexpire tick: ' . $e->getMessage()); }
}

// ---- plan limits -------------------------------------------------------
// Caps live on the school's plan; 0 means unlimited. The whole layer is inert while platform_mode
// is 0 — a single-school install was never sold a tier, and capping it on upgrade would break every
// existing deployment the morning after a deploy.

function ormsPlanFor(?int $school = null): ?array {
    static $c = [];
    $s = $school ?? sid();
    if (array_key_exists($s, $c)) return $c[$s];
    try { $c[$s] = qOne("SELECT p.* FROM plans p JOIN schools s ON s.plan_id = p.id WHERE s.id = ?", 'i', $s); }
    catch (Throwable $e) { $c[$s] = null; }
    return $c[$s];
}

// what actually consumes a seat. Alumni never do: a school that has graduated 300 students has not
// used 300 of its plan, or every tenant hits its ceiling by year three and the tier looks broken.
function ormsQuotaKinds(): array {
    return [
        'students' => ['label' => 'Student', 'col' => 'max_students', 'page' => 'students',
                       'sql' => "SELECT COUNT(*) FROM students WHERE school_id = ? AND status NOT IN ('Passed Out','Transferred')"],
        'teachers' => ['label' => 'Teacher', 'col' => 'max_teachers', 'page' => 'teachers',
                       'sql' => "SELECT COUNT(*) FROM teachers WHERE school_id = ? AND status = 'Active'"],
        'branches' => ['label' => 'Branch',  'col' => 'max_branches', 'page' => 'branches',
                       'sql' => "SELECT COUNT(*) FROM branches WHERE school_id = ? AND status = 'Active'"],
    ];
}

// cap / used / left for one kind. Fails OPEN on a missing column or a broken read, for the same
// reason the subscription gate does: a counting bug must never be able to freeze a paying school.
function ormsQuota(string $what, ?int $school = null): array {
    $k    = ormsQuotaKinds()[$what] ?? null;
    $open = ['kind' => $what, 'label' => $k['label'] ?? ucfirst($what), 'cap' => 0, 'used' => 0,
             'left' => PHP_INT_MAX, 'unlimited' => true, 'enforced' => false, 'plan' => ''];
    if (!$k || !ormsPlatformMode()) return $open;

    $s = $school ?? sid();
    if ($s <= 0) return $open;                                   // the operator holds no tenant seat
    $plan = ormsPlanFor($s);
    $open['plan'] = $plan['name'] ?? '';
    $open['enforced'] = true;
    $cap = $plan ? (int) ($plan[$k['col']] ?? 0) : 0;
    if ($cap <= 0) return $open;                                 // 0 = unlimited, and no plan = unlimited

    try { $used = (int) qVal($k['sql'], 'i', $s); } catch (Throwable $e) { return $open; }
    return ['kind' => $what, 'label' => $k['label'], 'cap' => $cap, 'used' => $used,
            'left' => max(0, $cap - $used), 'unlimited' => false, 'enforced' => true,
            'plan' => $plan['name'] ?? ''];
}

function ormsQuotaAll(?int $school = null): array {
    $out = [];
    foreach (array_keys(ormsQuotaKinds()) as $k) $out[$k] = ormsQuota($k, $school);
    return $out;
}

// How many of $want actually fit — the ONE place a limit decision is made, so a single add and a
// 500-row import can never disagree about what the plan allows.
function ormsQuotaRoom(string $what, int $want = 1, ?int $school = null): int {
    if ($want < 1) return 0;
    if (ormsIsPlatform()) return $want;      // operator repairing a customer's data is never capped
    $q = ormsQuota($what, $school);
    return $q['unlimited'] ? $want : (int) min($want, $q['left']);
}

function ormsQuotaMessage(array $q): string {
    return sprintf('%s limit reached — the %s plan allows %d and %d %s in use. Upgrade the plan to add more.',
        $q['label'], $q['plan'] !== '' ? $q['plan'] : 'current', $q['cap'], $q['used'],
        $q['used'] === 1 ? 'is' : 'are');
}

// single-add gate for an ajax handler: exits with the upgrade message when the tier is full.
function ormsQuotaGuard(string $what, ?int $school = null): void {
    if (ormsQuotaRoom($what, 1, $school) >= 1) return;
    $q = ormsQuota($what, $school);
    jsonErr(ormsQuotaMessage($q), ['quota' => $q, 'quota_full' => true]);
}

// Race-safe re-check for inside a transaction. Two admins clicking Add in the same second both pass
// an unlocked count and the tier is oversold by one; locking the school row serialises adds per
// tenant, and the count that follows is then the only one that can be true.
function ormsQuotaRoomLocked(string $what, int $want = 1, ?int $school = null): int {
    $s = $school ?? sid();
    if ($s > 0 && !ormsIsPlatform()) { try { qVal("SELECT id FROM schools WHERE id = ? FOR UPDATE", 'i', $s); } catch (Throwable $e) {} }
    return ormsQuotaRoom($what, $want, $s);
}

// run on every include — must stay the last statements in this file, in this order.
// the expiry sweep goes FIRST so the gate below judges today's state, not yesterday's. It costs one
// cached settings read on a normal request and only writes once an hour.
ormsAutoExpireTick();
// subscription first: a lapsed school is gated before anything else asks it for a password.
ormsEnforceSubscription();
ormsEnforcePasswordChange();
