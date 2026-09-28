<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

require_once 'config.php';

// platform-level switches, read before the school pin below so a tenant row can't override them
$platform_mode      = ormsPlatformMode();
$school_signup_open = $platform_mode && getSetting('allow_school_signup', '0') === '1';

// Check maintenance mode - block signup
if (isMaintenanceMode()) {
    header("Location: maintenance.php");
    exit();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// code -> tenant id. 0 = no school (never valid for signup — an account always lives INSIDE a
// school), -1 = unknown or closed. same generic wording for both, so no code is ever confirmed.
if (!function_exists('ormsSchoolFromCode')) {
    function ormsSchoolFromCode(string $code): int {
        if ($code === '') return 0;
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $code)) return -1;
        try { $r = qOne("SELECT id, status FROM schools WHERE code = ? LIMIT 1", 's', $code); }
        catch (Throwable $e) { return -1; }               // pre-migration db — no schools table
        return (!$r || $r['status'] === 'Cancelled') ? -1 : (int)$r['id'];
    }
}

// single-school install -> tenant 1, no field rendered, none of this runs
$is_post       = $_SERVER['REQUEST_METHOD'] === 'POST';
$school_code   = '';
$signup_school = 1;
if ($platform_mode) {
    // POST is authoritative — the cookie is a GET-time prefill only
    $school_code   = strtoupper(trim(substr((string)($is_post ? ($_POST['school_code'] ?? '') : ($_COOKIE['orms_school'] ?? '')), 0, 20)));
    $signup_school = ormsSchoolFromCode($school_code);
    if ($signup_school > 0) $GLOBALS['ORMS_SETTING_SCHOOL'] = $signup_school;   // this school's own policy
}
$school_known = $signup_school > 0;   // 0 (platform) and -1 (unknown) both fail: never a user in school 0

$error = '';
$success = '';
$csrf_token = generateCSRFToken();

// account policy — school system: the admin creates every account, no public self-service.
// the setting is read AFTER the pin, so each school decides for itself.
$signup_open = ($platform_mode ? $school_known : true) && getSetting('allow_public_signup', '0') === '1';
// roles: readRoles() keys its cache on the session and signup has none, so in platform mode ask
// the school's OWN rows (the matrix its admin edits) instead of the school-0 template
$signup_roles = !$signup_open ? [] : ($platform_mode ? ormsPublicRolesFor($signup_school) : getSignupRoles());
$allowed_roles = array_column($signup_roles, 'key');         // the ONLY roles a stranger may ever get
$signup_allowed = $signup_open && !empty($allowed_roles);    // open but no public role = still closed
// no code typed yet -> the school's policy is unknowable, so show the form (it carries the code
// field) instead of the closed panel, or the visitor could never get far enough to enter one
$need_code = $platform_mode && !$school_known;

// public roles for one school, falling back to the school-0 template a new tenant is cloned from
function ormsPublicRolesFor(int $school): array {
    $cols = "role_key AS `key`, label, color";
    $ord  = "is_super = 0 AND hidden_signup = 0 ORDER BY sort_order ASC, role_key ASC";
    try {
        $rows = qAll("SELECT $cols FROM roles WHERE school_id = ? AND $ord", 'i', $school);
        return $rows ?: qAll("SELECT $cols FROM roles WHERE school_id = 0 AND $ord");
    } catch (Throwable $e) { return getSignupRoles(); }      // pre-migration db — no school_id on roles
}

// Check if Google OAuth is enabled
$google_oauth_enabled = false;
$google_login_url = '';
try {
    // one builder owns the url so the single-use state token always gets minted - a hand-rolled
    // url here would be rejected by the callback's state check
    $google_login_url = $signup_allowed ? ormsGoogleAuthUrl() : ''; // closed signup hides the google path too
    $google_oauth_enabled = $google_login_url !== '';
} catch (Exception $e) {
    // OAuth not available, silently skip
}

// Get site branding
$branding = getSiteBranding();

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // closed signup rejects the POST as well — a hand-crafted request must never create a user
    if ($platform_mode && !$school_known) {
        // blank, unknown and closed all answer the same — a code is never confirmed from here
        $error = 'Enter a valid school code to continue.';
    } elseif (!$signup_allowed) {
        $error = $signup_open
            ? 'No self-service account type is available. Please contact the school administration.'
            : 'Registration is closed. Accounts are created by the school administration.';
    } elseif (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        // Input validation
        $username = validateUsername($username);
        $password = validatePassword($password);

        // role is NEVER taken from the client as-is — only a whitelisted public role survives
        $posted_role = isset($_POST['role']) ? trim($_POST['role']) : '';
        $role = in_array($posted_role, $allowed_roles, true)
            ? $posted_role
            : (count($allowed_roles) === 1 ? $allowed_roles[0] : '');   // single public role = implicit, else must be chosen
        // belt and braces: the whitelist already drops is_super rows, but self-service must never
        // be one edit in roles.php away from minting a platform operator
        if (in_array($role, ormsPlatformRoles(), true)) $role = '';

        if ($username === false) {
            $error = 'Invalid username. Use 3-50 letters, digits, _ . or -';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($password === false) {
            $error = 'Password must be between 6 and 255 characters.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif ($role === '') {
            $error = 'Please choose a valid account type.';
        } else {
            try {
                $conn = getDBConnection();

                // uniqueness is per school in platform mode (UNIQUE(school_id, username)) — a global
                // check would stop school B reusing a name school A already took, and the "already
                // taken" reply would confirm accounts inside a school the visitor cannot see.
                // it must scope on EXACTLY the same condition login.php does: an unscoped login
                // needs num_rows == 1, so letting two schools share a username there kills both
                $scoped = $platform_mode && ormsHasTenancy();
                try {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?" . ($scoped ? " AND school_id = ?" : ""));
                    $scoped ? $stmt->bind_param("si", $username, $signup_school) : $stmt->bind_param("s", $username);
                } catch (Throwable $e) { // pre-migration db — no school_id column
                    $scoped = false;
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->bind_param("s", $username);
                }
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $error = 'Username already taken. Please choose another.';
                    $stmt->close();
                } else {
                    $stmt->close();

                    // Check if email already exists — scope to THIS school like the username check above
                    // (email is not unique across schools; an unscoped "taken" leaks accounts the visitor
                    // cannot see, and blocks a legitimate signup because another school owns the address)
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?" . ($scoped ? " AND school_id = ?" : ""));
                    $scoped ? $stmt->bind_param("si", $email, $signup_school) : $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $error = 'Email already registered. Please use another.';
                        $stmt->close();
                    } else {
                        $stmt->close();

                        // Hash password and create user with the validated role
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $active = 1;

                        // school_id must be written explicitly: the column defaults to 0, and 0 is
                        // the PLATFORM — a self-signup that inherits it becomes a tenantless account
                        try {
                            // 6 old cols + school_id -> 'sssssi' becomes 'sssssii'
                            $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, email, role, is_active, school_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssssii", $username, $username, $hashed_password, $email, $role, $active, $signup_school);
                        } catch (Throwable $e) { // pre-migration db — no tenant column yet
                            $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, email, role, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssssi", $username, $username, $hashed_password, $email, $role, $active);
                        }

                        if ($stmt->execute()) {
                            $new_user_id = $stmt->insert_id;
                            $stmt->close();

                            // Log the signup. logActivity() stamps the tenant from the session and a
                            // visitor has none, so pin it for the one call — otherwise the row files
                            // under the platform and the school's own admin never sees it
                            $_SESSION['school_id'] = $signup_school;
                            logActivity($new_user_id, $username, 'Signup', "New user registered ($role)");
                            unset($_SESSION['school_id']);

                            // Notify admins about new registration — this school's admins, explicitly
                            try { createNotificationForAdmins('New User Registered', 'User "' . htmlspecialchars($username) . '" has signed up.', 'info', 'users.php', $signup_school); } catch (Exception $e) {}

                            // Check if email verification is enabled
                            $verification_enabled = getSetting('email_verification_enabled', '0');
                            $smtp_enabled = getSetting('smtp_enabled', '0');

                            if ($verification_enabled === '1' && $smtp_enabled === '1') {
                                // Send OTP email for verification
                                $otp = createEmailVerification($new_user_id, $email);
                                $emailBody = getOTPEmailTemplate($otp, 'verify');
                                sendEmail($email, 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                                header("Location: verify_otp.php?email=" . urlencode($email));
                                exit();
                            } else {
                                // Auto-verify if email verification is disabled
                                $verify_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
                                $verify_stmt->bind_param("i", $new_user_id);
                                $verify_stmt->execute();
                                $verify_stmt->close();
                            }

                            $success = 'Account created successfully! You can now login.';
                        } else {
                            $error = 'Registration failed. Please try again.';
                            $stmt->close();
                        }
                    }
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "doesn't exist") !== false) {
                    $error = 'Database not set up. Please run <a href="setup.php" class="login-link">setup.php</a> first.';
                } else {
                    $error = 'An error occurred. Please contact administrator.';
                    error_log("Signup error: " . $e->getMessage());
                }
            }
        }
    }
}
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
    <title>Sign Up - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="preload" as="image" href="icon-192.png">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="login-logo">
            <h2><?php echo ($signup_allowed || $need_code) ? 'Create Account' : 'Account Registration'; ?></h2>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (!$signup_allowed && !$need_code): ?>
            <div class="orms-empty">
                <i class="fas fa-user-lock"></i>
                <h4>Registration is closed</h4>
                <p>Accounts for this system are created by the school administration. Please contact the school office to receive your username and password.</p>
            </div>
            <a href="login.php" class="btn btn-primary btn-block">
                <i class="fas fa-right-to-bracket"></i> Back to Login
            </a>
            <?php else: ?>

            <?php if ($need_code): ?>
            <div class="info-banner">
                <i class="fas fa-circle-info"></i>
                <span>Enter your school code first — the account types your school offers appear once it is recognised.</span>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <?php if ($platform_mode): ?>
                <div class="form-group">
                    <label><i class="fas fa-school"></i> School Code</label>
                    <input type="text" name="school_code" class="school-code-input"<?php echo $school_code === '' ? ' autofocus' : ''; ?> required autocomplete="organization" maxlength="20" placeholder="Your school's code" value="<?php echo htmlspecialchars($school_code); ?>">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username</label>
                    <input type="text" name="username" required<?php echo ($platform_mode && $school_code === '') ? '' : ' autofocus'; ?> autocomplete="username" minlength="3" maxlength="50" placeholder="Choose a username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" name="email" required autocomplete="email" maxlength="100" placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password</label>
                    <input type="password" name="password" required autocomplete="new-password" minlength="6" placeholder="Minimum 6 characters">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Confirm Password</label>
                    <input type="password" name="confirm_password" required autocomplete="new-password" minlength="6" placeholder="Confirm your password">
                </div>

                <?php if (count($signup_roles) > 1): ?>
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Account Type</label>
                    <select id="role" name="role" required>
                        <?php foreach ($signup_roles as $r): ?>
                        <option value="<?php echo htmlspecialchars($r['key']); ?>"<?php echo (isset($_POST['role']) && $_POST['role'] === $r['key']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($r['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-user-plus"></i> Sign Up
                </button>
            </form>

            <?php if ($google_oauth_enabled): ?>
            <div class="oauth-divider">
                <hr>
                <span>or continue with</span>
                <hr>
            </div>
            <a href="<?php echo htmlspecialchars($google_login_url); ?>" class="google-oauth-btn">
                <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                Sign up with Google
            </a>
            <?php endif; ?>
            <?php endif; // signup_allowed ?>

            <div class="login-footer">
                <p class="login-footer-link">Already have an account? <a href="login.php" class="login-link">Login here</a></p>
                <?php if ($school_signup_open): ?>
                <p class="login-footer-link">New school? <a href="register_school.php" class="login-link">Register your school</a></p>
                <?php endif; ?>
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
    // Theme Toggle for Signup Page
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

    <?php if (($signup_allowed || $need_code) && count($signup_roles) > 1): ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="orms.js?v=2.5"></script>
    <script>ORMS.dropdown('#role');</script>
    <?php endif; ?>
</body>
</html>
