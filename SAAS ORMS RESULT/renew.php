<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Subscription lapsed — the only page a blocked school can reach.
 * Deliberately NOT a logout: staff keep their session so paying restores them mid-flow.
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// already paid (or the gate is off) -> nothing to renew
if (!ormsSubscriptionBlocked()) { header('Location: dashboard.php'); exit(); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'];
$state    = ormsSubscriptionState();
$isAdmin  = ormsSchoolWide($role);          // only a school admin sees the money detail
$school   = null;
try { $school = qOne("SELECT name, code, status FROM schools WHERE id = ?", 'i', sid()); } catch (Throwable $e) {}

$wa    = preg_replace('/\D+/', '', (string) getSetting('platform_whatsapp', '923224083545'));
$brand = getSiteBranding();
$msg   = [
    'expired'   => 'This school\'s subscription has expired.',
    'suspended' => 'This school\'s account has been suspended.',
    'cancelled' => 'This school\'s account has been closed.',
][$state['status']] ?? 'This school\'s subscription is not active.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Subscription — <?php echo htmlspecialchars($brand['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
</head>
<body class="renew-page">
    <div class="renew-wrap">
        <div class="renew-card">
            <span class="renew-icon"><i class="fas fa-lock"></i></span>
            <h1><?php echo htmlspecialchars($msg); ?></h1>

            <?php if ($school): ?>
            <p class="renew-school">
                <i class="fas fa-city"></i> <?php echo htmlspecialchars($school['name']); ?>
                <span class="renew-code"><?php echo htmlspecialchars($school['code']); ?></span>
            </p>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <p>Results, marks entry and reports stay locked until the subscription is renewed. Your data is safe and nothing has been deleted.</p>
                <?php if (!empty($state['ends_at'])): ?>
                <p class="renew-meta"><i class="far fa-calendar"></i> Access ended <?php echo htmlspecialchars(date('d M Y', strtotime($state['ends_at']))); ?></p>
                <?php endif; ?>
                <div class="renew-actions">
                    <a class="btn btn-primary" target="_blank" rel="noopener"
                       href="https://wa.me/<?php echo htmlspecialchars($wa); ?>?text=<?php echo rawurlencode('Renew subscription for ' . ($school['name'] ?? '') . ' (' . ($school['code'] ?? '') . ')'); ?>">
                        <i class="fab fa-whatsapp"></i> Renew via WhatsApp
                    </a>
                </div>
                <p class="renew-meta">Send your payment receipt and the account is reactivated the same day.</p>
            <?php else: ?>
                <p>Please contact your school administrator — the school's subscription needs renewing before results are available again.</p>
            <?php endif; ?>

            <div class="renew-foot">
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($username); ?></span>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</body>
</html>
