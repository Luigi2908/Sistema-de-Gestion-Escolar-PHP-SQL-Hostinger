<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Mobile Bottom Navigation Bar
 * Include this file in all dashboard pages (before app-container)
 */

// Get current page from the including file
$mobile_current = isset($current_page) ? $current_page : '';

// bar = the first 3 of this curated list the role can actually open. same $RBAC_PAGES the sidebar
// reads, so a new role gets a real nav without touching this file — never a hardcoded role name.
// the Platform keys sit high but are perm-gated like everything else: a school role holds no view
// bit on them, so they fall straight through and the school bar stays dashboard/users/settings
$mobile_priority = ['dashboard', 'schools', 'subscriptions', 'plans',
                    'users', 'settings', 'students', 'results', 'marks_entry', 'my_results', 'classes', 'subjects', 'teachers',
                    'attendance', 'broadsheet', 'fees', 'branches', 'logs'];   // day-to-day work outranks the audit log
$mobile_short    = ['settings' => 'Settings', 'marks_entry' => 'Marks', 'my_results' => 'My Results', 'classes' => 'Classes', 'logs' => 'Logs',
                    'attendance' => 'Attend', 'broadsheet' => 'Sheet', 'subscriptions' => 'Billing', 'branches' => 'Branches'];
// thin perms (student) -> personal pages top it up. these 3 are open to everyone
$mobile_fallback = [
    ['file' => 'account.php', 'key' => 'account', 'icon' => 'fas fa-user-circle', 'label' => 'Account'],
    ['file' => 'ui.php',      'key' => 'ui',      'icon' => 'fas fa-palette',     'label' => 'Theme'],
    ['file' => 'about.php',   'key' => 'about',   'icon' => 'fas fa-info-circle', 'label' => 'About'],
];

$mobile_reg = [];
foreach ($RBAC_PAGES as $mp) $mobile_reg[$mp['key']] = $mp;

$mobile_items = [];
foreach ($mobile_priority as $mk) {
    if (count($mobile_items) === 3) break;
    if (!isset($mobile_reg[$mk]) || !can($mk, 'v')) continue;
    $mp = $mobile_reg[$mk];
    $mobile_items[] = [
        'file'  => $mp['file'],
        'key'   => $mk,
        'icon'  => strpos($mp['icon'], ' ') !== false ? $mp['icon'] : 'fas ' . $mp['icon'],   // brands already prefixed
        'label' => $mobile_short[$mk] ?? $mp['label']
    ];
}
foreach ($mobile_fallback as $mf) {
    if (count($mobile_items) === 3) break;
    $mobile_items[] = $mf;
}
?>

<!-- Mobile Bottom Navigation Bar -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
    <?php foreach ($mobile_items as $mi): ?>
    <a href="<?php echo htmlspecialchars($mi['file']); ?>" class="mobile-nav-item <?php echo $mobile_current === $mi['key'] ? 'active' : ''; ?>">
        <i class="<?php echo htmlspecialchars($mi['icon']); ?>"></i>
        <span><?php echo htmlspecialchars($mi['label']); ?></span>
    </a>
    <?php endforeach; ?>

    <button class="mobile-nav-item mobile-nav-more" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
        <span>More</span>
    </button>
</nav>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

<script>
/**
 * Mobile Menu Functions
 */
function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');

        if (sidebar.classList.contains('mobile-open')) {
            lockScroll();
        } else {
            unlockScroll();
        }
    }
}

// body scroll lock -> .scroll-locked in styles.css. only the top offset stays inline,
// because it is computed from the live scroll position
function lockScroll() {
    document.body.style.top = `-${window.scrollY}px`;
    document.body.classList.add('scroll-locked');
}

function unlockScroll() {
    if (!document.body.classList.contains('scroll-locked')) return;   // lock not held
    const scrollY = document.body.style.top;
    document.body.classList.remove('scroll-locked');
    document.body.style.top = '';
    window.scrollTo(0, parseInt(scrollY || '0') * -1);
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');

        unlockScroll();
    }
}

// Close mobile menu when clicking a link in sidebar
document.addEventListener('DOMContentLoaded', function() {
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a:not(.submenu-toggle)');
    sidebarLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            closeMobileMenu();
        });
    });

    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });

    // Handle swipe to close sidebar
    let touchStartX = 0;
    let touchEndX = 0;
    const sidebar = document.getElementById('sidebar');

    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        sidebar.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
    }

    function handleSwipe() {
        const swipeThreshold = 50;
        if (touchStartX - touchEndX > swipeThreshold) {
            // Swipe left - close sidebar
            closeMobileMenu();
        }
    }

    // Prevent body scroll when touching sidebar overlay
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('touchmove', function(e) {
            e.preventDefault();
        }, { passive: false });
    }
});

// Handle orientation change
window.addEventListener('orientationchange', function() {
    // Small delay to let the browser adjust
    setTimeout(function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    }, 100);
});

// Handle resize - close mobile menu if window becomes larger.
// clearing the scroll-lock styles without restoring scrollTop drops the reader back
// at the top of the page, so unwind through the same path the close button uses
window.addEventListener('resize', function() {
    if (window.innerWidth <= 768) return;

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');

    unlockScroll();   // no-op when the lock isn't held
});
</script>
