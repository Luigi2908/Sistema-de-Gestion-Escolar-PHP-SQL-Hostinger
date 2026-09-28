<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// platform-level switches. read BEFORE the school pin below, so a tenant row can never override
// the operator's master switch or take the whole platform out of maintenance
$platform_mode      = ormsPlatformMode();
$school_signup_open = $platform_mode && getSetting('allow_school_signup', '0') === '1';

// Check maintenance mode
$is_maintenance = isMaintenanceMode();
$maintenance_login = isset($_GET['maintenance']) && $_GET['maintenance'] === '1';

// If maintenance is ON and user is NOT coming via maintenance page link, redirect
if ($is_maintenance && !$maintenance_login) {
    header("Location: maintenance.php");
    exit();
}

// code -> tenant id. 0 = platform login (blank code = the Super Admin), -1 = unknown or closed,
// which the caller answers with the SAME text as a wrong password — never confirm a code exists.
// `code` collation is case-insensitive, so no LOWER() wrapper (it would skip the unique index too).
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
$is_post      = $_SERVER['REQUEST_METHOD'] === 'POST';
$school_code  = '';
$login_school = 1;
if ($platform_mode) {
    // POST is authoritative. a field that is present and empty means "log me into the platform",
    // so the cookie is a GET-time prefill ONLY — reading it on POST would hijack that choice
    $school_code  = strtoupper(trim(substr((string)($is_post ? ($_POST['school_code'] ?? '') : ($_COOKIE['orms_school'] ?? '')), 0, 20)));
    $login_school = ormsSchoolFromCode($school_code);
    // this school's own branding + policy on its own login screen
    if ($login_school > 0) $GLOBALS['ORMS_SETTING_SCHOOL'] = $login_school;
}

$error = '';
$success = '';
$csrf_token = generateCSRFToken();

// Check for success messages
if (isset($_GET['verified']) && $_GET['verified'] == '1') {
    $success = 'Email verified successfully! You can now login.';
}
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    $success = 'Password reset successfully! You can now login with your new password.';
}

// Check for OAuth error messages
if (isset($_GET['error'])) {
    $oauth_errors = [
        'oauth_denied' => 'Google login was cancelled.',
        'oauth_token_failed' => 'Google authentication failed. Please try again.',
        'oauth_userinfo_failed' => 'Could not get Google account info. Please try again.',
        'oauth_create_failed' => 'Could not create account. Please try again.',
        'oauth_not_configured' => 'Google login is not configured. Contact administrator.',
        'oauth_unverified' => 'Your Google email address is not verified. Verify it with Google, then try again.',
        'oauth_state' => 'Your Google login request expired or did not start here. Please try again.',
        'oauth_inactive' => 'This account is not active. Please contact the school office.',
        'oauth_no_account' => 'No account found for this Google address. Please contact the school office.',
        'oauth_school' => 'Enter your school code and sign in once before using Google login.',
        'oauth_error' => 'An error occurred with Google login. Please try again.'
    ];
    $error = isset($oauth_errors[$_GET['error']]) ? $oauth_errors[$_GET['error']] : '';
}

// Check if Forgot Password is enabled
$show_forgot_password = getSetting('show_forgot_password', '1') === '1';

// Check if Google OAuth is enabled — helper mints the single-use state with the url
$google_login_url = ormsGoogleAuthUrl();
$google_oauth_enabled = $google_login_url !== '';

// Get site branding
$branding = getSiteBranding();

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = isset($_POST['username']) ? $_POST['username'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // Input validation
        $username = validateUsername($username);
        $password = validatePassword($password);

        if ($username === false) {
            $error = 'Invalid username format. Use 3-50 letters, digits, _ . or -';
        } elseif ($password === false) {
            $error = 'Invalid password format.';
        } elseif ($platform_mode && $login_school < 0) {
            // unknown or closed school — same wording as a wrong password. no attempt row either:
            // keying that failure on school 0 would let a stranger lock the operator out
            $error = 'Invalid username or password';
        } else {
            // every school owns an "admin", so the lockout key carries the tenant. 0 while
            // platform_mode is off keeps the counter exactly where it has always been
            $attempt_school = $platform_mode ? $login_school : 0;

            // Check rate limiting
            if (checkLoginAttempts($username, $attempt_school)) {
                $error = 'Too many login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    $conn = getDBConnection();

                    // tenant columns ride along so the session can be stamped below. the school leg
                    // is added only in platform mode — single-school installs keep the original sql
                    $scoped = $platform_mode && ormsHasTenancy();
                    try {
                        $stmt = $conn->prepare("SELECT id, username, password, role, email, email_verified, is_active, must_change_password, school_id, branch_id FROM users WHERE "
                                               . ($scoped ? "school_id = ? AND " : "") . "username = ?");
                        $scoped ? $stmt->bind_param("is", $login_school, $username) : $stmt->bind_param("s", $username);
                    } catch (Throwable $e) { // pre-migration db — no tenant columns on users
                        $stmt = $conn->prepare("SELECT id, username, password, role, email, email_verified, is_active, must_change_password FROM users WHERE username = ?");
                        $stmt->bind_param("s", $username);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                } catch (Exception $e) {
                    // Check if it's a table/column not found error
                    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
                        $error = 'Database not set up. Please run <a href="setup.php" class="login-link">setup.php</a> first.';
                    } else {
                        $error = 'Database error. Please contact administrator.';
                        error_log("Login error: " . $e->getMessage());
                    }
                    if (isset($stmt)) $stmt->close();
                    goto skip_login;
                }

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    if (password_verify($password, $user['password'])) {
                        // deactivated (left / passed out / transferred) -> no way in, Admin included.
                        // checked AFTER the password so it can't be used to enumerate accounts
                        if (!ormsAccountActive($user)) {
                            // pin the tenant for the one log call — the session isn't stamped yet, so
                            // the row would otherwise file under the platform instead of the school
                            $_SESSION['school_id'] = (int)($user['school_id'] ?? $login_school);
                            logActivity($user['id'], $user['username'], 'Login Blocked', 'Login attempt on a deactivated account');
                            unset($_SESSION['school_id']);
                            $error = 'This account is not active. Please contact the school office.';
                            $stmt->close();
                            goto skip_login;
                        }

                        // Block non-admin users during maintenance mode. maintenance is platform-wide,
                        // so in platform mode the exemption is the operator — ormsIsPlatform() reads a
                        // session that isn't stamped yet here, so match the role the same way it does
                        $maint_exempt = $platform_mode
                            ? in_array($user['role'], ormsPlatformRoles(), true)
                            : in_array($user['role'], ['Admin', 'School Owner'], true);
                        if ($is_maintenance && !$maint_exempt) {
                            $error = $platform_mode
                                ? 'Only the platform operator can login during maintenance mode.'
                                : 'Only administrators can login during maintenance mode.';
                            $stmt->close();
                            goto skip_login;
                        }

                        // remember the school for next time; an explicit platform login forgets it
                        if ($platform_mode) {
                            setcookie('orms_school', $school_code, [
                                'expires'  => $school_code !== '' ? time() + 31536000 : time() - 3600,
                                'path'     => '/',
                                'secure'   => !empty($_SERVER['HTTPS']),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                        }

                        // Check if email is verified (when verification is enabled)
                        $verification_required = getSetting('email_verification_enabled', '0') === '1';
                        $email_verified = isset($user['email_verified']) ? $user['email_verified'] : 1;

                        if ($verification_required && !$email_verified) {
                            // Resend OTP and redirect to verification page
                            $otp = createEmailVerification($user['id'], $user['email']);
                            $emailBody = getOTPEmailTemplate($otp, 'verify');
                            sendEmail($user['email'], 'Verify Your Email - ' . $branding['site_name'], $emailBody);

                            $stmt->close();
                            header("Location: verify_otp.php?email=" . urlencode($user['email']));
                            exit();
                        }

                        // Clear failed login attempts
                        clearLoginAttempts($username, $attempt_school);

                        // stamp the login and keep a success row in the trail (only failures drive the lockout)
                        try {
                            qExec("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?", 'si', ormsIp(), (int)$user['id']);
                            recordLoginAttempt($username, 1, $attempt_school);
                        } catch (Throwable $e) {
                            error_log('Login stamp: ' . $e->getMessage());
                        }

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
                        // tenant stamp — ONE writer for the tenant session keys, shared with the
                        // remember-me and oauth paths. sid() fails closed, so a session without it
                        // gets destroyed on the first scoped query and the user loops back to login
                        ormsStampTenant((int)$user['id'], (string)$user['role']);
                        $_SESSION['LAST_ACTIVITY'] = time();

                        // Handle Remember Me
                        $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
                        if ($remember_me) {
                            try {
                                createRememberToken($user['id']);
                            } catch (Exception $e) {
                                // Remember me table may not exist yet, skip
                            }
                        }

                        // Log successful login
                        logActivity($user['id'], $user['username'], 'Login', 'User logged in successfully');

                        // Track session
                        try { trackUserSession($user['id']); } catch (Exception $e) {}

                        $stmt->close();

                        // every student starts on the same default password — send them straight to the change form
                        if (!empty($_SESSION['must_change_password'])) {
                            header("Location: account.php?force_password=1");
                            exit();
                        }

                        header("Location: dashboard.php");
                        exit();
                    } else {
                        recordLoginAttempt($username, 0, $attempt_school);
                        // Alert admins on login attempt spike
                        try {
                            $conn_la = getDBConnection();
                            try {
                                $la_count = (int) qVal("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND school_id = ? AND success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", 'si', $username, $attempt_school);
                            } catch (Throwable $e) { // pre-update schema — no school_id column
                                $la_count = (int) qVal("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", 's', $username);
                            }
                            if ($la_count >= 5) {
                                $dedup = $conn_la->prepare("SELECT id FROM notifications WHERE title = 'Login Alert: Failed Attempts' AND message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
                                $like_str = '%"' . $conn_la->real_escape_string($username) . '"%';
                                $dedup->bind_param("s", $like_str);
                                $dedup->execute();
                                if ($dedup->get_result()->num_rows == 0) {
                                    // the alert belongs to the school being attacked. no session here, so
                                    // the helper's sid() default would file it under school 0 and reach nobody
                                    createNotificationForAdmins('Login Alert: Failed Attempts', $la_count . ' failed login attempts for "' . htmlspecialchars($username) . '" in last 15 min from IP ' . $_SERVER['REMOTE_ADDR'], 'danger', 'logs.php', $platform_mode ? $login_school : 1);
                                }
                                $dedup->close();
                            }
                        } catch (Exception $e) {}
                        $error = 'Invalid username or password';
                    }
                } else {
                    recordLoginAttempt($username, 0, $attempt_school);
                    $error = 'Invalid username or password';
                }

                $stmt->close();
            }
        }
        skip_login:
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Login - <?php echo htmlspecialchars($branding['site_name']); ?></title>
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
            <h2><?php echo htmlspecialchars($branding['site_name']); ?></h2>

            <?php if ($success): ?>
                <div class="success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <?php if ($platform_mode): ?>
                <div class="form-group">
                    <label><i class="fas fa-school"></i> School Code</label>
                    <input type="text" id="school_code" name="school_code" class="school-code-input"<?php echo $school_code === '' ? ' autofocus' : ''; ?> autocomplete="organization" maxlength="20" placeholder="Leave blank for platform login" value="<?php echo htmlspecialchars($school_code); ?>">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username</label>
                    <input type="text" id="username" name="username" required<?php echo ($platform_mode && $school_code === '') ? '' : ' autofocus'; ?> autocomplete="username" minlength="3" maxlength="50">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" minlength="6">
                </div>

                <div class="login-options-row">
                    <label class="remember-me-label">
                        <input type="checkbox" name="remember_me" value="1">
                        Remember Me
                    </label>
                    <?php if ($show_forgot_password && !($is_maintenance && $maintenance_login)): ?>
                    <a href="forgot_password.php" class="login-link">Forgot Password?</a>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>

            <?php if ($is_maintenance && $maintenance_login): ?>
            <div class="info-banner info-banner-warning mt-20">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Maintenance Mode Active - Admin Login Only</span>
            </div>
            <?php else: ?>
                <?php if ($google_oauth_enabled): ?>
                <div class="oauth-divider">
                    <hr>
                    <span>or continue with</span>
                    <hr>
                </div>
                <a href="<?php echo htmlspecialchars($google_login_url); ?>" class="google-oauth-btn">
                    <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    Login with Google
                </a>
                <?php endif; ?>
            <?php endif; ?>

            <div class="login-footer">
                <?php if (!($is_maintenance && $maintenance_login)): ?>
                <?php if (getSetting('allow_public_signup', '0') === '1'): ?>
                <p class="login-footer-link">Don't have an account? <a href="signup.php" class="login-link">Sign Up</a></p>
                <?php else: ?>
                <p class="login-footer-link">Students &amp; parents sign in with the student&rsquo;s login &mdash; or check results on the <a href="index.php" class="login-link">home page</a></p>
                <?php endif; ?>
                <?php if ($school_signup_open): ?>
                <p class="login-footer-link">New school? <a href="register_school.php" class="login-link">Register your school</a></p>
                <?php endif; ?>
                <?php endif; ?>
                <div class="login-contact">
                    <span class="login-contact-title"><i class="fas fa-handshake"></i> Let's Work Together</span>
                    <span class="login-contact-links">
                        <a href="https://whatsapp.rameezscripts.com" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                        <a href="https://t.me/rameezscripts" target="_blank" rel="noopener"><i class="fab fa-telegram"></i> Telegram</a>
                        <a href="mailto:Contact@rameezscripts.com"><i class="fas fa-envelope"></i> Email</a>
                    </span>
                </div>
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
    // Theme Toggle for Login Page
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
    <script src="orms.js?v=2.5"></script>
</body>
</html>
