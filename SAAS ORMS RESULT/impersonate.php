<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// must be logged in
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$action = $_GET['action'] ?? '';

// start — become another user (admin only)
if ($action === 'start') {
    // no nested impersonation
    if (!empty($_SESSION['impersonator_id'])) { header('Location: dashboard.php'); exit(); }
    // admin or the platform operator. a plain school Admin stays confined to its own school below
    $isPlat = function_exists('ormsIsPlatform') && ormsIsPlatform();
    // School Owner is the tenant's top role and carries Admin's whole matrix, so it gets Admin's
    // reach here too. Leaving it out made the "Login as" button in users.php render for an owner and
    // then silently bounce them to the dashboard — a live button that does nothing.
    if (!in_array($_SESSION['role'] ?? '', ['Admin', 'School Owner'], true) && !$isPlat) { header('Location: dashboard.php'); exit(); }

    // csrf — start mutates the session; reject a cross-site GET (e.g. <img src=impersonate.php?action=start&user_id=N>)
    if (!validateCSRFToken((string)($_GET['csrf'] ?? ($_POST['csrf_token'] ?? '')))) { header('Location: users.php'); exit(); }

    $targetId = (int)($_GET['user_id'] ?? 0);
    // no self-impersonation
    if ($targetId <= 0 || $targetId === (int)$_SESSION['user_id']) { header('Location: users.php'); exit(); }

    $conn = getDBConnection();
    // school/branch ride along — becoming a user means adopting their tenant, not keeping yours
    if ($stmt = @$conn->prepare("SELECT id, username, role, is_active, school_id, branch_id FROM users WHERE id = ?")) {
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else { // pre-migration schema
        $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$u) { header('Location: users.php'); exit(); }
    // deactivated accounts were never checked here
    if (isset($u['is_active']) && (int)$u['is_active'] !== 1) { header('Location: users.php'); exit(); }

    // a school Admin may only impersonate inside its OWN school. only the operator crosses tenants.
    if (!$isPlat && isset($u['school_id']) && function_exists('sid') && (int)$u['school_id'] !== sid()) {
        header('Location: users.php'); exit();
    }
    // and a school Admin may never step INTO the platform operator — becoming a Super Admin / a school-0 row
    // would inherit is_super below (line ~71) and hand over full platform control
    if (!$isPlat && (($u['role'] ?? '') === 'Super Admin' || (int)($u['school_id'] ?? 0) === 0)) {
        header('Location: users.php'); exit();
    }
    // chain of authority: becoming an account ABOVE you is the same takeover as editing it. Without
    // this an Admin could "Login as" the School Owner and walk straight into billing, wearing the
    // owner's session while the audit trail only shows an impersonation start.
    if (function_exists('ormsOutranks') && ormsOutranks($targetId, $u['role'] ?? null) !== '') {
        header('Location: users.php'); exit();
    }

    // stash the real admin so we can switch back — tenant included, or "stop" strands the
    // operator inside the school they stepped into
    $_SESSION['impersonator_id'] = (int)$_SESSION['user_id'];
    $_SESSION['impersonator_username'] = $_SESSION['username'];
    $_SESSION['impersonator_role'] = $_SESSION['role'];
    $_SESSION['impersonator_school'] = $_SESSION['school_id'] ?? null;
    $_SESSION['impersonator_branch'] = $_SESSION['branch_id'] ?? null;
    $_SESSION['impersonator_super']  = !empty($_SESSION['is_super']);

    logActivity($_SESSION['user_id'], $_SESSION['username'], 'Impersonate Start', 'as #' . $u['id'] . ' (' . $u['username'] . ')');

    session_regenerate_id(true);   // the swap is a privilege change — mint a fresh id

    // become the target — effective role is now theirs (RBAC sees the real user)
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['role'] = $u['role'];
    $_SESSION['school_id'] = (int)($u['school_id'] ?? ($_SESSION['school_id'] ?? 0));
    $_SESSION['branch_id'] = (int)($u['branch_id'] ?? 0);
    $_SESSION['is_super']  = ($u['role'] ?? '') === 'Super Admin';
    unset($_SESSION['branch_filter']);   // don't carry a branch picker into another tenant
    $_SESSION['LAST_ACTIVITY'] = time();

    header('Location: dashboard.php');
    exit();
}

// stop — restore the real admin (ungated except for being impersonating)
if ($action === 'stop') {
    if (!empty($_SESSION['impersonator_id'])) {
        logActivity((int)$_SESSION['impersonator_id'], $_SESSION['impersonator_username'] ?? '', 'Impersonate Stop', 'was #' . $_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$_SESSION['impersonator_id'];
        $_SESSION['username'] = $_SESSION['impersonator_username'];
        $_SESSION['role'] = $_SESSION['impersonator_role'];
        // restore the tenant too, else the operator lands back in the school they were visiting
        if (array_key_exists('impersonator_school', $_SESSION) && $_SESSION['impersonator_school'] !== null) {
            $_SESSION['school_id'] = (int)$_SESSION['impersonator_school'];
        }
        $_SESSION['branch_id'] = (int)($_SESSION['impersonator_branch'] ?? 0);
        $_SESSION['is_super']  = !empty($_SESSION['impersonator_super']);
        unset($_SESSION['branch_filter']);
        $_SESSION['LAST_ACTIVITY'] = time();
        unset($_SESSION['impersonator_id'], $_SESSION['impersonator_username'], $_SESSION['impersonator_role'],
              $_SESSION['impersonator_school'], $_SESSION['impersonator_branch'], $_SESSION['impersonator_super']);
    }
    header('Location: users.php');
    exit();
}

header('Location: dashboard.php');
exit();
