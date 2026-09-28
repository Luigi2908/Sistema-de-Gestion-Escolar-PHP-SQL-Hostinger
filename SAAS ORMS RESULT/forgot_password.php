<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Forgot Password Page
 */

require_once 'config.php';

// Check maintenance mode - block forgot password
if (isMaintenanceMode()) {
    header("Location: maintenance.php");
    exit();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// platform mode: pin the tenant (school code from the form, else the last-used school cookie) so a reset
// can never target another school's account that shares this email. single-school mode leaves it 0 (unscoped).
$fp_school = 0;
if (function_exists('ormsPlatformMode') && ormsPlatformMode()) {
    $fp_code = strtoupper(trim(substr((string)($_POST['school_code'] ?? $_COOKIE['orms_school'] ?? ''), 0, 20)));
    $fp_school = (int)ormsSchoolFromCode($fp_code);
    if ($fp_school > 0) $GLOBALS['ORMS_SETTING_SCHOOL'] = $fp_school; // branding + auth scope
}

// Get site branding
$branding = getSiteBranding();

// same session token login.php uses — this form mails an OTP and burns a rate-limit slot, so a
// cross-site POST must not be able to fire it
$csrf_token = generateCSRFToken();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if SMTP is enabled
        $smtp_enabled = getSetting('smtp_enabled', '0');
        if ($smtp_enabled !== '1') {
            $error = 'Password reset is not available. Contact administrator.';
        } else {
            $otp = createPasswordReset($email, $fp_school);

            if ($otp !== false) {
                // Send OTP email
                $emailBody = getOTPEmailTemplate($otp, 'reset');
                $result = sendEmail($email, 'Password Reset - ' . $branding['site_name'], $emailBody);

                if ($result['success']) {
                    // Redirect to reset page
                    header("Location: reset_password.php?email=" . urlencode($email));
                    exit();
                } else {
                    $error = 'Failed to send reset email. Please try again.';
                    error_log("Password reset email failed: " . $result['message']);
                }
            } else {
                // Don't reveal if email exists or not (security best practice)
                // But still show a success-like message
                header("Location: reset_password.php?email=" . urlencode($email));
                exit();
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
    <title>Forgot Password - <?php echo htmlspecialchars($branding['site_name']); ?></title>
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
            <h2>Forgot Password</h2>
            <p class="login-subtitle">
                Enter your email address and we'll send you an OTP to reset your password.
            </p>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" required autofocus autocomplete="email" maxlength="100" placeholder="Enter your registered email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-paper-plane"></i> Send Reset OTP
                </button>
            </form>

            <div class="login-footer">
                <p class="login-footer-link"><a href="login.php" class="login-link"><i class="fas fa-arrow-left"></i> Back to Login</a></p>
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
