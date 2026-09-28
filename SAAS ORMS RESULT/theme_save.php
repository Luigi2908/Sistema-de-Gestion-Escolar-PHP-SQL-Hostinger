<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// shared per-user theme persist — used by the header quick-switcher (any page).
// partial update: only the posted fields change, the rest keep current values.
require_once 'config.php';

header('Content-Type: application/json');

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) { echo json_encode(['success' => false, 'login' => true]); exit(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']); exit();
}

// csrf is MANDATORY — omitting the field used to skip the check outright
$csrf = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Security check failed']); exit();
}

$cur = getUserTheme($uid);
$hex = '/^#[0-9A-Fa-f]{6}$/';

$p = (isset($_POST['theme_primary'])   && preg_match($hex, $_POST['theme_primary']))   ? $_POST['theme_primary']   : $cur['theme_primary'];
$s = (isset($_POST['theme_secondary']) && preg_match($hex, $_POST['theme_secondary'])) ? $_POST['theme_secondary'] : $cur['theme_secondary'];
$a = (isset($_POST['theme_accent'])    && preg_match($hex, $_POST['theme_accent']))    ? $_POST['theme_accent']    : $cur['theme_accent'];
$m = (isset($_POST['theme_mode'])      && in_array($_POST['theme_mode'], ['light', 'dark'])) ? $_POST['theme_mode'] : $cur['theme_mode'];

$ok = setUserTheme($uid, $p, $s, $a, $m);
echo json_encode(['success' => (bool)$ok]);
exit();
