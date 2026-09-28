<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Where the gateway drops the payer back. Deliberately grants NOTHING: landing here proves only
 * that a browser followed a redirect. Access is opened by payment_webhook.php alone, so this page
 * reports what the webhook has already recorded and waits when it has not landed yet.
 */
require_once 'billing_engine.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$token  = (string) ($_GET['t'] ?? '');
$result = ($_GET['r'] ?? '') === 'cancel' ? 'cancel' : 'ok';
$tries  = max(0, min(20, (int) ($_GET['n'] ?? 0)));
$brand  = getSiteBranding();

$inv = bilInvoiceByToken($token);
// scope it: an invoice is only ever readable inside the school it was raised for
if ($inv && !ormsIsPlatform() && (int) $inv['school_id'] !== sid()) $inv = null;

$plan = ($inv && $inv['plan_id']) ? qOne("SELECT name FROM plans WHERE id = ?", 'i', (int) $inv['plan_id']) : null;
$paid = $inv && $inv['status'] === 'Paid';
// the webhook can lag the redirect by a second or two — keep refreshing for ~40s before giving up
$waiting = $inv && $inv['status'] === 'Pending' && $result === 'ok' && $tries < 10;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Payment — <?php echo htmlspecialchars($brand['site_name']); ?></title>
    <?php if ($waiting): ?><meta http-equiv="refresh" content="4;url=payment_return.php?t=<?php echo urlencode($token); ?>&r=ok&n=<?php echo $tries + 1; ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
</head>
<body class="renew-page">
<div class="renew-wrap">
    <div class="renew-card">
        <?php if (!$inv): ?>
            <span class="renew-icon pay-bad"><i class="fas fa-circle-question"></i></span>
            <h1>Payment not found</h1>
            <p>We could not match this payment to an invoice on your school. If money has left your account, send us the reference and it will be applied by hand.</p>

        <?php elseif ($paid): ?>
            <span class="renew-icon pay-good"><i class="fas fa-circle-check"></i></span>
            <h1>Payment received</h1>
            <p><?php echo htmlspecialchars($plan['name'] ?? 'Subscription'); ?> &middot; <?php echo htmlspecialchars(bilMoney((float) $inv['amount'], $inv['currency'])); ?></p>
            <p class="renew-meta"><i class="far fa-calendar-check"></i> Access runs to <?php echo htmlspecialchars(date('d M Y', strtotime($inv['period_end']))); ?></p>

        <?php elseif ($result === 'cancel'): ?>
            <span class="renew-icon pay-warn"><i class="fas fa-circle-xmark"></i></span>
            <h1>Payment cancelled</h1>
            <p>Nothing was charged. Invoice <?php echo htmlspecialchars($inv['invoice_no']); ?> is still open, so you can pick it up again whenever you are ready.</p>

        <?php elseif ($waiting): ?>
            <span class="renew-icon pay-wait"><i class="fas fa-spinner fa-spin"></i></span>
            <h1>Confirming your payment</h1>
            <p>Waiting for <?php echo htmlspecialchars(bilGateway($inv['gateway'])['label'] ?? $inv['gateway']); ?> to confirm invoice <?php echo htmlspecialchars($inv['invoice_no']); ?>. This page refreshes itself &mdash; you do not need to pay again.</p>

        <?php else: ?>
            <span class="renew-icon pay-warn"><i class="fas fa-hourglass-half"></i></span>
            <h1>Still awaiting confirmation</h1>
            <p>Invoice <?php echo htmlspecialchars($inv['invoice_no']); ?> has not been confirmed yet. If the amount has left your account it will be applied automatically the moment the gateway reports it &mdash; nothing is lost.</p>
        <?php endif; ?>

        <div class="renew-actions">
            <a class="btn btn-primary" href="billing.php"><i class="fas fa-receipt"></i> Subscription</a>
            <?php if ($paid): ?><a class="btn btn-light" href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a><?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
