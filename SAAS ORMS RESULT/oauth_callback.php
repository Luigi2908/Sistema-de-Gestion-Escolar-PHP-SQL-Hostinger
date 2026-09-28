<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Google OAuth Callback Handler
 */

require_once 'config.php';

// resolve the tenant BEFORE reading any oauth/signup setting so they come from THIS school (platform mode).
// single-school install -> tenant 1 / main branch and every lookup below stays unscoped exactly as before.
$oauth_school = 1; $oauth_branch = 1; $oScoped = false;
if (function_exists('ormsPlatformMode') && ormsPlatformMode()) {
    $oc_code = strtoupper(trim(substr((string)($_COOKIE['orms_school'] ?? ''), 0, 20)));
    $oauth_school = (int) ormsSchoolFromCode($oc_code);
    if ($oauth_school <= 0) {                 // tenant unknown -> never mint a school-0 (platform) user
        header('Location: login.php?error=oauth_school');
        exit();
    }
    $GLOBALS['ORMS_SETTING_SCHOOL'] = $oauth_school;   // read THIS school's oauth + signup policy
    try { $oauth_branch = (int) (qVal("SELECT id FROM branches WHERE school_id = ? AND is_main = 1 LIMIT 1", 'i', $oauth_school) ?: 1); }
    catch (Throwable $e) { $oauth_branch = 1; }
    $oScoped = true;
}

// Check if Google OAuth is enabled
$oauth_enabled = getSetting('google_oauth_enabled', '0');
if ($oauth_enabled !== '1') {
    header("Location: login.php");
    exit();
}

// Get OAuth credentials from database
$client_id = getSetting('google_client_id', '');
$client_secret = getSetting('google_client_secret', '');
$redirect_uri = getSetting('google_redirect_uri', '');

if (empty($client_id) || empty($client_secret) || empty($redirect_uri)) {
    header("Location: login.php?error=oauth_not_configured");
    exit();
}

// Check for error from Google
if (isset($_GET['error'])) {
    header("Location: login.php?error=oauth_denied");
    exit();
}

// Check for authorization code
if (!isset($_GET['code'])) {
    header("Location: login.php");
    exit();
}

$code = $_GET['code'];

// login-CSRF guard: the state must be the single-use one we minted for THIS session.
// without it an attacker can feed us a code for their own Google account and silently sign the victim in
if (!ormsCheckOAuthState(isset($_GET['state']) ? $_GET['state'] : null)) {
    logActivity(0, 'guest', 'OAuth State Rejected', 'Google callback with missing/mismatched state from IP ' . $_SERVER['REMOTE_ADDR']);
    header("Location: login.php?error=oauth_state");
    exit();
}

// Exchange authorization code for access token
$token_url = 'https://oauth2.googleapis.com/token';
$token_data = [
    'code' => $code,
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => $redirect_uri,
    'grant_type' => 'authorization_code'
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$token_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    error_log("Google OAuth token error: " . $token_response);
    header("Location: login.php?error=oauth_token_failed");
    exit();
}

$token_data = json_decode($token_response, true);

if (!isset($token_data['access_token'])) {
    error_log("Google OAuth: No access token in response");
    header("Location: login.php?error=oauth_token_failed");
    exit();
}

$access_token = $token_data['access_token'];

// Get user info from Google
$userinfo_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init($userinfo_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$userinfo_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    error_log("Google OAuth userinfo error: " . $userinfo_response);
    header("Location: login.php?error=oauth_userinfo_failed");
    exit();
}

$google_user = json_decode($userinfo_response, true);

if (!isset($google_user['id']) || !isset($google_user['email'])) {
    error_log("Google OAuth: Missing user data");
    header("Location: login.php?error=oauth_userinfo_failed");
    exit();
}

$google_id = $google_user['id'];
$google_email = $google_user['email'];
$google_name = isset($google_user['name']) ? $google_user['name'] : '';

// an UNVERIFIED google address proves nothing — anyone can put admin@school.tld on a throwaway
// account. reject before any lookup, otherwise the email branch below hands out that user's role
if (empty($google_user['verified_email'])) {
    error_log("Google OAuth: unverified email rejected - " . $google_email);
    logActivity(0, $google_email, 'OAuth Rejected', 'Google account email is not verified');
    header("Location: login.php?error=oauth_unverified");
    exit();
}

try {
    $conn = getDBConnection();

    // First, check if user exists by google_id
    $stmt = $conn->prepare("SELECT id, username, role, is_active FROM users WHERE google_id = ?" . ($oScoped ? " AND school_id = ?" : ""));
    $oScoped ? $stmt->bind_param("si", $google_id, $oauth_school) : $stmt->bind_param("s", $google_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Existing Google user - log them in
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!ormsAccountActive($user)) { // deactivated -> google is not a side door
            logActivity($user['id'], $user['username'], 'Login Blocked', 'Google login on a deactivated account');
            header("Location: login.php?error=oauth_inactive");
            exit();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        ormsStampTenant((int)$user['id'], (string)$user['role']);   // sid() fails closed without this
        $_SESSION['LAST_ACTIVITY'] = time();

        logActivity($user['id'], $user['username'], 'Google Login', 'User logged in via Google OAuth');

        header("Location: dashboard.php");
        exit();
    }
    $stmt->close();

    // Check if user exists by email
    $stmt = $conn->prepare("SELECT id, username, role, is_active FROM users WHERE email = ?" . ($oScoped ? " AND school_id = ?" : ""));
    $oScoped ? $stmt->bind_param("si", $google_email, $oauth_school) : $stmt->bind_param("s", $google_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Link Google account to existing user
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!ormsAccountActive($user)) { // never link/login a deactivated account
            logActivity($user['id'], $user['username'], 'Login Blocked', 'Google login on a deactivated account');
            header("Location: login.php?error=oauth_inactive");
            exit();
        }

        $update_stmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
        $update_stmt->bind_param("si", $google_id, $user['id']);
        $update_stmt->execute();
        $update_stmt->close();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        ormsStampTenant((int)$user['id'], (string)$user['role']);   // sid() fails closed without this
        $_SESSION['LAST_ACTIVITY'] = time();

        logActivity($user['id'], $user['username'], 'Google Login', 'Google account linked and logged in');

        header("Location: dashboard.php");
        exit();
    }
    $stmt->close();

    // Create new user from Google account
    // Generate a username from email (part before @)
    $base_username = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $google_email)[0]);
    if (strlen($base_username) < 3) {
        $base_username = 'user_' . $base_username;
    }

    // Ensure unique username
    $username_candidate = $base_username;
    $counter = 1;
    while (true) {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?" . ($oScoped ? " AND school_id = ?" : ""));
        $oScoped ? $check_stmt->bind_param("si", $username_candidate, $oauth_school) : $check_stmt->bind_param("s", $username_candidate);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_stmt->close();

        if ($check_result->num_rows == 0) {
            break;
        }
        $username_candidate = $base_username . '_' . $counter;
        $counter++;
    }

    // no existing account -> this would be a self-signup. honour the same gate signup.php uses
    if (getSetting('allow_public_signup', '0') !== '1') {
        logActivity(0, $google_email, 'OAuth Signup Blocked', 'Public signup is disabled — no account exists for this Google email');
        header('Location: login.php?error=oauth_no_account'); // login.php maps keys only, free text is dropped
        exit();
    }

    // only a role explicitly opened to public signup may be granted
    $signupRoles = getSignupRoles();
    if (empty($signupRoles)) {
        logActivity(0, $google_email, 'OAuth Signup Blocked', 'No role is open to public signup');
        header('Location: login.php?error=oauth_no_account'); // login.php maps keys only, free text is dropped
        exit();
    }

    // Create user with a random password (they'll login via Google)
    $random_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $role = is_array($signupRoles[0]) ? ($signupRoles[0]['key'] ?? '') : $signupRoles[0];
    if ($role === '') {
        header('Location: login.php?error=oauth_no_account'); // login.php maps keys only, free text is dropped
        exit();
    }

    // stamp the tenant on the new account — an oauth signup must never land on school 0 (platform)
    try {
        $insert_stmt = $conn->prepare("INSERT INTO users (username, password, email, role, google_id, school_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sssssii", $username_candidate, $random_password, $google_email, $role, $google_id, $oauth_school, $oauth_branch);
    } catch (Throwable $e) { // pre-migration schema — no school_id/branch_id columns
        $insert_stmt = $conn->prepare("INSERT INTO users (username, password, email, role, google_id) VALUES (?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sssss", $username_candidate, $random_password, $google_email, $role, $google_id);
    }

    if ($insert_stmt->execute()) {
        $new_user_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        // Auto-verify email for Google OAuth users (no OTP needed)
        $verify_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
        $verify_stmt->bind_param("i", $new_user_id);
        $verify_stmt->execute();
        $verify_stmt->close();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $new_user_id;
        $_SESSION['username'] = $username_candidate;
        $_SESSION['role'] = $role;
        ormsStampTenant((int)$new_user_id, (string)$role);
        $_SESSION['LAST_ACTIVITY'] = time();

        logActivity($new_user_id, $username_candidate, 'Google Signup', 'New user registered via Google OAuth');

        header("Location: dashboard.php");
        exit();
    } else {
        $insert_stmt->close();
        error_log("Google OAuth: Failed to create user");
        header("Location: login.php?error=oauth_create_failed");
        exit();
    }
} catch (Exception $e) {
    error_log("Google OAuth callback error: " . $e->getMessage());
    header("Location: login.php?error=oauth_error");
    exit();
}
