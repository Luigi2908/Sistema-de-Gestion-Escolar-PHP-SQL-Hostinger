<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Maintenance Mode Page
 */

require_once 'config.php';

// If maintenance mode is OFF, redirect to index
if (!isMaintenanceMode()) {
    header("Location: index.php");
    exit();
}

// maintenance_mode is platform-level — it takes the WHOLE platform down, so the exempt role is the
// platform operator. single-school installs keep the literal Admin that has always been let through.
$platform_mode = ormsPlatformMode();
$maint_exempt  = $platform_mode ? ormsIsPlatform() : (($_SESSION['role'] ?? '') === 'Admin');
if (isset($_SESSION['user_id']) && $maint_exempt) {
    header("Location: dashboard.php");
    exit();
}

// Get site branding
$branding = getSiteBranding();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Under Maintenance - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <div class="maintenance-container">
        <!-- Admin Login Icon (Top Right) -->
        <a href="login.php?maintenance=1" class="maintenance-admin-link" title="<?php echo $platform_mode ? 'Operator Login' : 'Admin Login'; ?>">
            <i class="fas fa-user-shield"></i>
        </a>

        <div class="maintenance-content">
            <div class="maintenance-icon">
                <i class="fas fa-tools"></i>
            </div>
            <h1 class="maintenance-title">Under Maintenance</h1>
            <p class="maintenance-desc">
                We are currently performing scheduled maintenance. We'll be back shortly.
            </p>
            <div class="maintenance-info">
                <i class="fas fa-info-circle"></i>
                <span>Thank you for your patience. Please check back soon.</span>
            </div>
            <div class="maintenance-brand">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="maintenance-logo" onerror="this.style.display='none'">
                <p><?php echo $branding['copyright_text']; ?></p>
            </div>
        </div>
    </div>

    <!-- Theme Toggle Button -->
    <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>

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
</body>
</html>
