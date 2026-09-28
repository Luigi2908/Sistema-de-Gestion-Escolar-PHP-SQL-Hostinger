<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Self-service school registration. Reachable ONLY when platform_mode = 1 AND
 * allow_school_signup = 1 — a single-school install never has this door.
 */

require_once 'config.php';

if (isset($_SESSION['user_id'])) { header('Location: dashboard.php'); exit(); }
if (isMaintenanceMode())         { header('Location: maintenance.php'); exit(); }

// both switches are the operator's. no session here, so getSetting() already reads the platform row
$platform_mode = ormsPlatformMode();
$open = $platform_mode && getSetting('allow_school_signup', '0') === '1' && ormsHasTenancy();

$error = '';
$done = null;                        // set on success -> the credential panel replaces the form
$csrf_token = generateCSRFToken();
$branding = getSiteBranding();
$in = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));   // sticky field helper

// name -> code. digits appended until it's free, so a busy platform never hands out a taken one
function ormsMakeSchoolCode(string $name): string {
    $base = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)), 0, 8);
    if (strlen($base) < 3) $base = str_pad($base ?: 'SCH', 3, 'X');
    for ($i = 0; $i < 25; $i++) {
        $try = $i ? substr($base, 0, 16) . random_int(100, 9999) : $base;
        if (!qVal("SELECT id FROM schools WHERE code = ? LIMIT 1", 's', $try)) return $try;
    }
    return 'S' . time();             // last resort — time is unique enough at this point
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // one throttle key per ip: every submit burns a slot, so registration spam dies at 5 per 15 min
    $rl_key = substr('reg:' . ormsIp(), 0, 50);

    if (!$open) {
        $error = 'School registration is closed.';
    } elseif (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (checkLoginAttempts($rl_key, 0)) {
        $error = 'Too many attempts. Please try again in 15 minutes.';
    } else {
        recordLoginAttempt($rl_key, 0, 0);

        $name    = $in('school_name');
        $code    = strtoupper($in('school_code'));
        $email   = $in('email');
        $phone   = substr($in('phone'), 0, 30);
        $address = substr($in('address'), 0, 255);
        $admin   = validateUsername($in('admin_username'));
        $pwd     = validatePassword((string)($_POST['admin_password'] ?? ''));
        $pwd2    = (string)($_POST['confirm_password'] ?? '');

        if (strlen($name) < 3 || strlen($name) > 150) {
            $error = 'Enter the school name (3-150 characters).';
        } elseif ($code !== '' && !preg_match('/^[A-Z0-9]{3,20}$/', $code)) {
            $error = 'School code must be 3-20 letters or digits, no spaces.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $error = 'Enter a valid contact email address.';
        } elseif ($admin === false) {
            $error = 'Invalid admin username. Use 3-50 letters, digits, _ . or -';
        } elseif ($pwd === false) {
            $error = 'Password must be between 6 and 255 characters.';
        } elseif ($pwd !== $pwd2) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $conn = getDBConnection();
                // taken codes and emails are told plainly — this is a registration form, not a
                // login, and the visitor has to be able to pick something that works
                if ($code !== '' && qVal("SELECT id FROM schools WHERE code = ? LIMIT 1", 's', $code)) {
                    $error = 'That school code is already taken. Please choose another.';
                } elseif (qVal("SELECT id FROM users WHERE email = ? LIMIT 1", 's', $email)) {
                    // password reset looks accounts up by email alone, so a duplicate would make
                    // "forgot password" ambiguous across two schools
                    $error = 'That email address is already registered.';
                } else {
                    if ($code === '') $code = ormsMakeSchoolCode($name);
                    $trial_ends = date('Y-m-d', strtotime('+14 days'));
                    $plan_id = qVal("SELECT id FROM plans WHERE name = 'Trial' ORDER BY id ASC LIMIT 1");
                    $plan_id = $plan_id === null ? null : (int)$plan_id;   // no Trial plan seeded -> leave it null
                    $hash = password_hash($pwd, PASSWORD_DEFAULT);
                    $full = substr($name, 0, 93) . ' Admin';   // users.full_name is VARCHAR(100)

                    $conn->begin_transaction();
                    try {
                        // 1. the tenant itself — Trial, 14 days, unique code
                        $school_id = qInsert("INSERT INTO schools (name, code, address, phone, email, status, plan_id, trial_ends_at, notes)
                                              VALUES (?, ?, ?, ?, ?, 'Trial', ?, ?, 'Self-registered')",
                                             'sssssis', $name, $code, $address, $phone, $email, $plan_id, $trial_ends);
                        if ($school_id <= 0) throw new RuntimeException('school insert failed');

                        // 2. main branch — every student/class/user hangs off a branch
                        $branch_id = qInsert("INSERT INTO branches (school_id, name, code, address, phone, is_main, status)
                                              VALUES (?, 'Main Branch', 'MAIN', ?, ?, 1, 'Active')",
                                             'iss', $school_id, $address, $phone);
                        if ($branch_id <= 0) throw new RuntimeException('branch insert failed');

                        // 3. the school's first user is its OWNER, not an Admin — whoever registers the
                        //    school is who the subscription belongs to, and only that role reaches billing.
                        //    must_change_password -> a fresh secret on first login
                        $user_id = qInsert("INSERT INTO users (username, full_name, email, password, role, is_active, email_verified, must_change_password, school_id, branch_id)
                                            VALUES (?, ?, ?, ?, 'School Owner', 1, 1, 1, ?, ?)",
                                           'ssssii', $admin, $full, $email, $hash, $school_id, $branch_id);
                        if ($user_id <= 0) throw new RuntimeException('admin insert failed');

                        // 4. owner back-pointer, now that the user has an id
                        qExec("UPDATE schools SET owner_user_id = ? WHERE id = ?", 'ii', $user_id, $school_id);

                        // 5. the trial subscription the gate reads — without a row it would never expire
                        // name the currency: the column default would stamp the row with a code that
                        // has nothing to do with this install, and every money screen would print it
                        qExec("INSERT INTO school_subscriptions (school_id, plan_id, starts_at, ends_at, status, amount, currency, notes)
                               VALUES (?, ?, CURDATE(), ?, 'Active', 0, ?, 'Self-service trial')",
                              'iiss', $school_id, $plan_id, $trial_ends, ormsCurrency()['code']);

                        // 6. clone the school-0 role template. WITHOUT this the school has no
                        // permission matrix at all and its own admin can't open a single page.
                        // Super Admin never travels — the operator exists only at school 0
                        $cloned = qExec("INSERT IGNORE INTO roles (school_id, role_key, label, color, sort_order, is_super, hidden_signup, permissions)
                                         SELECT ?, role_key, label, color, sort_order, is_super, hidden_signup, permissions
                                         FROM roles WHERE school_id = 0 AND role_key <> 'Super Admin'", 'i', $school_id);
                        if ($cloned < 1) throw new RuntimeException('role template empty — run update_setup.php');

                        $conn->commit();
                    } catch (Throwable $e) {
                        $conn->rollback();
                        throw $e;
                    }

                    // audit trail. logActivity() stamps the tenant from the session and a visitor has
                    // none, so pin it for the one call or the row files under the platform instead
                    $_SESSION['school_id'] = $school_id;
                    logActivity($user_id, $admin, 'School Registered', "School \"$name\" ($code) registered — trial ends $trial_ends", 'school', $school_id);
                    unset($_SESSION['school_id']);

                    // ping the operator. createNotificationForAdmins() only knows school-wide roles,
                    // and Super Admin is deliberately not one of them
                    try {
                        foreach (qAll("SELECT id FROM users WHERE role = 'Super Admin' AND is_active = 1 AND school_id = 0") as $op) {
                            createNotification((int)$op['id'], 'New School Registered',
                                'School "' . htmlspecialchars($name) . '" (' . $code . ') registered a 14-day trial.', 'info', 'schools.php');
                        }
                    } catch (Throwable $e) {}

                    // remember the code so the login page opens on the right school
                    setcookie('orms_school', $code, ['expires' => time() + 31536000, 'path' => '/',
                        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);

                    // the throttle row stays on purpose — a success must count too, or one ip could
                    // mint schools forever by simply succeeding every time
                    $done = ['name' => $name, 'code' => $code, 'admin' => $admin, 'trial_ends' => $trial_ends];
                }
            } catch (Throwable $e) {
                error_log('School registration: ' . $e->getMessage());
                // two visitors racing the same code -> the unique key wins, tell them to pick another
                $error = (int)$e->getCode() === 1062
                    ? 'That school code or username was just taken. Please try again.'
                    : 'Could not create the school. Please try again or contact support.';
            }
        }
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
    <title>Register Your School - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="preload" as="image" href="icon-192.png">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="login-container">
        <div class="login-box reg-box">
            <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="login-logo">
            <h2><?php echo $done ? 'School Created' : 'Register Your School'; ?></h2>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($done): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($done['name']); ?> is ready. Your 14-day trial ends <?php echo htmlspecialchars(date('d M Y', strtotime($done['trial_ends']))); ?>.
            </div>
            <div class="reg-cred">
                <p><span><i class="fas fa-key"></i> School Code</span><strong><?php echo htmlspecialchars($done['code']); ?></strong></p>
                <p><span><i class="fas fa-user-shield"></i> Admin Username</span><strong><?php echo htmlspecialchars($done['admin']); ?></strong></p>
            </div>
            <div class="info-banner">
                <i class="fas fa-circle-info"></i>
                <span>Write the school code down — staff type it on the login page. You will be asked to set a new password the first time you sign in.</span>
            </div>
            <a href="login.php" class="btn btn-primary btn-block">
                <i class="fas fa-right-to-bracket"></i> Go to Login
            </a>

            <?php elseif (!$open): ?>
            <div class="orms-empty">
                <i class="fas fa-store-slash"></i>
                <h4>Registration is closed</h4>
                <p>New schools are being onboarded by our team at the moment. Please get in touch and we will set your school up for you.</p>
            </div>
            <a href="login.php" class="btn btn-primary btn-block">
                <i class="fas fa-right-to-bracket"></i> Back to Login
            </a>

            <?php else: ?>
            <div class="info-banner">
                <i class="fas fa-gift"></i>
                <span>Free 14-day trial. You get your own school code, a main branch and an admin account.</span>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <p class="reg-legend"><i class="fas fa-school"></i> School</p>

                <div class="form-group">
                    <label><i class="fas fa-building"></i> School Name</label>
                    <input type="text" name="school_name" required autofocus maxlength="150" placeholder="e.g. Greenfield Public School" value="<?php echo htmlspecialchars($in('school_name')); ?>">
                </div>

                <div class="reg-grid">
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> School Code</label>
                        <input type="text" name="school_code" class="school-code-input" maxlength="20" pattern="[A-Za-z0-9]{3,20}" placeholder="Auto if blank" value="<?php echo htmlspecialchars($in('school_code')); ?>">
                        <p class="help-text">3-20 letters or digits. Staff type this to log in.</p>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone</label>
                        <input type="text" name="phone" maxlength="30" placeholder="Optional" value="<?php echo htmlspecialchars($in('phone')); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-location-dot"></i> Address</label>
                    <input type="text" name="address" maxlength="255" placeholder="Optional" value="<?php echo htmlspecialchars($in('address')); ?>">
                </div>

                <p class="reg-legend"><i class="fas fa-user-shield"></i> Administrator</p>

                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Contact Email</label>
                    <input type="email" name="email" required maxlength="100" autocomplete="email" placeholder="Used for login help and billing" value="<?php echo htmlspecialchars($in('email')); ?>">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user"></i> Admin Username</label>
                    <input type="text" name="admin_username" required minlength="3" maxlength="50" autocomplete="username" placeholder="Choose a username" value="<?php echo htmlspecialchars($in('admin_username')); ?>">
                </div>

                <div class="reg-grid">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password</label>
                        <input type="password" name="admin_password" required minlength="6" autocomplete="new-password" placeholder="Minimum 6 characters">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Confirm Password</label>
                        <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password" placeholder="Repeat password">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-circle-plus"></i> Create School
                </button>
            </form>
            <?php endif; ?>

            <div class="login-footer">
                <p class="login-footer-link">Already have a school? <a href="login.php" class="login-link">Login here</a></p>
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
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
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    initTheme();
    </script>
    <script src="orms.js?v=2.5"></script>
</body>
</html>
