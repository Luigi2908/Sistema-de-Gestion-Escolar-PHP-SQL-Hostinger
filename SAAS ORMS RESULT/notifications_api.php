<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit();
}

header('Content-Type: application/json');
$user_id = $_SESSION['user_id'];
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// row must be this user's AND this school's before it can be flipped. the tenant leg rides on the
// OWNING USER, not notifications.school_id — the insert path doesn't stamp that column yet, so a
// perfectly legitimate row can still carry the schema default and would fail a direct compare.
function notifOwned(int $id, int $uid): bool {
    if ($id <= 0 || $uid <= 0) return false;
    try {
        if (ormsIsPlatform()) {   // operator has no school of its own to match against
            return (int) qVal("SELECT COUNT(*) FROM notifications WHERE id = ? AND user_id = ?", 'ii', $id, $uid) > 0;
        }
        return (int) qVal("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id
                           WHERE n.id = ? AND n.user_id = ? AND u.school_id = ?", 'iii', $id, $uid, sid()) > 0;
    } catch (Throwable $e) {   // pre-migration schema — no users.school_id yet
        try { return (int) qVal("SELECT COUNT(*) FROM notifications WHERE id = ? AND user_id = ?", 'ii', $id, $uid) > 0; }
        catch (Throwable $e2) { return false; }
    }
}

try {
    switch ($action) {
        case 'getCount':
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'getRecent':
            $notifications = getRecentNotifications($user_id, 10);
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['success' => true, 'notifications' => $notifications, 'count' => $count]);
            break;

        case 'markRead':
            requireCsrfJson(); // writes only — getCount/getRecent stay open so the bell never breaks
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            // same message either way — a "not yours" reply would enumerate other people's ids
            if ($id > 0 && notifOwned($id, (int)$user_id)) {
                markNotificationRead($id, $user_id);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            }
            break;

        case 'markAllRead':
            requireCsrfJson();
            markAllNotificationsRead($user_id);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('notifications_api.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load notifications']);   // detail stays in the log
}
exit();
