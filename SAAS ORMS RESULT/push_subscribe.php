<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// web push sub endpoint — key handout + subscribe/unsubscribe/test. session-bound.
require_once 'config.php';
require_once 'webpush_helper.php';

header('Content-Type: application/json');

// must be logged in
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'login' => true]);
    exit();
}

$action = $_REQUEST['action'] ?? '';

// hand the vapid public key to client js so it can subscribe. read-only, no csrf.
if ($action === 'key') {
    echo json_encode(['success' => true, 'key' => getSetting('vapid_public_key', '')]);
    exit();
}

// everything below mutates — POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit();
}

// csrf is MANDATORY — a missing token used to skip the check entirely, which let a
// cross-site post register an attacker's endpoint under this user and steal their pushes
$csrf = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Security check failed']);
    exit();
}

$conn = getDBConnection();

if ($action === 'subscribe') {
    $endpoint = trim((string)($_POST['endpoint'] ?? ''));
    $p256dh   = trim((string)($_POST['p256dh'] ?? ''));
    $auth     = trim((string)($_POST['auth'] ?? ''));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        echo json_encode(['success' => false, 'message' => 'Missing subscription data']);
        exit();
    }

    // upsert keyed by endpoint. refresh keys, but a re-subscribe from a DIFFERENT user must NOT
    // silently steal an existing endpoint — that would redirect the owner's push to the caller's
    // device and mute the owner. only the current owner's own row is refreshed; a foreign endpoint
    // conflict is left untouched (a stale device is re-owned after its owner unsubscribes).
    $stmt = $conn->prepare(
        "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             p256dh = IF(user_id = VALUES(user_id), VALUES(p256dh), p256dh),
             auth   = IF(user_id = VALUES(user_id), VALUES(auth),   auth)"
    );
    $stmt->bind_param("isss", $userId, $endpoint, $p256dh, $auth);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => (bool)$ok]);
    exit();
}

if ($action === 'unsubscribe') {
    $endpoint = trim((string)($_POST['endpoint'] ?? ''));
    if ($endpoint === '') {
        echo json_encode(['success' => false, 'message' => 'Missing endpoint']);
        exit();
    }
    // scoped to owner — a user can only drop their own sub
    $stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
    $stmt->bind_param("si", $endpoint, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => (bool)$ok]);
    exit();
}

// fire a test push to the current user's own devices (verify end-to-end)
if ($action === 'test') {
    $r = webpushSendToUser($userId, 'Test notification', 'Web push is working on this device.', 'dashboard.php');
    echo json_encode(['success' => ($r['sent'] > 0), 'result' => $r]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
exit();
