<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Shared Sidebar Component
 * Include this file in all dashboard pages
 *
 * Required variables before including:
 * - $username: Current logged-in username
 * - $role: Current user role key (from the roles table)
 * - $current_page: Current page identifier (must match a $RBAC_PAGES key)
 * - $user_id: Current user ID
 */

// hit directly = the branch switcher posting back. included the normal way, this block never runs,
// so no page has to grow a handler for it
if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    require_once __DIR__ . '/config.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'setBranch') jsonErr('Invalid request');
    if (empty($_SESSION['user_id'])) jsonOut(['success' => false, 'login' => true]);
    requireCsrfJson();

    // a branch admin is pinned by ormsBranchLock() — the picker is never theirs to move
    $sbRole = $_SESSION['role'] ?? '';
    if (ormsBranchLock($sbRole) || !ormsSchoolWide($sbRole)) jsonErr('Not allowed');

    $sbPick = (int)($_POST['branch_id'] ?? 0);        // 0 = all branches this role may reach
    if ($sbPick && !ormsOwns('branches', $sbPick)) jsonErr('Not allowed');
    $_SESSION['branch_filter'] = $sbPick;
    logActivity((int)$_SESSION['user_id'], (string)($_SESSION['username'] ?? ''), 'Branch Switch',
                'branch_filter=' . ($sbPick ?: 'all'));
    jsonOk(['branch_id' => $sbPick]);
}

if (!isset($username) || !isset($role) || !isset($current_page) || !isset($user_id)) {
    die('Sidebar requires $username, $role, $current_page, and $user_id variables');
}

// whose tenant am I in? the school's own name/logo, site branding as the fallback — never the
// signed-in user's avatar, which said nothing about which school the page belongs to
$site_brand  = getSiteBranding();
$is_platform = ormsIsPlatform();
$school_name = $site_brand['site_name'];
$school_logo = $site_brand['site_logo'];
if (!$is_platform && ormsHasTenancy()) {
    try {
        $sch = qOne("SELECT name, logo FROM schools WHERE id = ?", 'i', sid());
        if ($sch) {
            if (trim((string)$sch['name']) !== '') $school_name = $sch['name'];
            if (trim((string)$sch['logo']) !== '') $school_logo = $sch['logo'];
        }
    } catch (Throwable $e) { /* pre-migration db — site branding stands */ }
}

// branch switcher — school-wide roles only, and only when there IS more than one branch.
// a branch admin never sees it: ormsBranchLock() already decides for them
$sb_branches = [];
if (!$is_platform && ormsHasTenancy() && ormsSchoolWide($role) && !ormsBranchLock($role)) {
    try { $sb_branches = qAll("SELECT id, name FROM branches WHERE school_id = ? AND status = 'Active'
                               ORDER BY is_main DESC, name ASC", 'i', sid()); }
    catch (Throwable $e) { $sb_branches = []; }   // pre-migration db — no branches, no picker
}
$sb_active = (int)($_SESSION['branch_filter'] ?? 0);

// nav sections come off the registry's own group field, so a page added to $RBAC_PAGES shows up
// here on its own. $nav_top = keys already rendered above the groups, never twice
$nav_top   = ['dashboard', 'my_results'];
$nav_first = ['System' => ['users', 'logs', 'settings', 'oauth_setup', 'smtp_setup', 'sessions', 'backup', 'roles']]; // keep the familiar order
$nav_groups = [];
foreach ($RBAC_PAGES as $np) {
    if (in_array($np['key'], $nav_top, true)) continue;
    $nav_groups[$np['group']][] = $np['key'];
}
foreach ($nav_first as $ng => $pref) {   // preferred order first, anything new lands after it
    if (empty($nav_groups[$ng])) continue;
    $nav_groups[$ng] = array_merge(array_values(array_intersect($pref, $nav_groups[$ng])),
                                   array_values(array_diff($nav_groups[$ng], $pref)));
}
$nav_sections = [];                      // render order = $RBAC_GROUPS
foreach (($RBAC_GROUPS ?? array_keys($nav_groups)) as $ng) {
    if (!empty($nav_groups[$ng])) $nav_sections[$ng] = $nav_groups[$ng];
}
// Get user's custom theme
$user_theme = getUserTheme($user_id);
// Get default language for Google Translate
$default_language = getDefaultLanguage();

// Output custom theme CSS
echo generateUserThemeCSS($user_id);
?>
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title orms-tn-brand" title="<?php echo htmlspecialchars($is_platform ? 'Platform console' : $school_name); ?>">
            <i class="fas <?php echo $is_platform ? 'fa-shield-halved' : 'fa-school'; ?>"></i>
            <span class="sidebar-title-text">
                <?php if ($is_platform): ?>
                <span class="orms-tn-badge"><i class="fas fa-satellite-dish"></i> Platform</span>
                <?php else: ?>
                <?php echo htmlspecialchars($school_name); ?>
                <?php endif; ?>
            </span>
        </div>
        <button class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
    </div>
    <div class="sidebar-logo-section">
        <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="<?php echo htmlspecialchars($school_name); ?>" class="sidebar-logo"
             onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($site_brand['site_logo'], ENT_QUOTES); ?>'">
    </div>
    <?php if (count($sb_branches) > 1): ?>
    <!-- revealed by the script below once the searchable control is built — a page without orms.js
         shows nothing here rather than a dead plain select -->
    <div class="sidebar-menu-section orms-tn-switch initially-hidden">
        <div class="sidebar-menu-title"><i class="fas fa-code-branch"></i> Branch</div>
        <select id="sbBranchPick" class="orms-tn-select">
            <option value="0"<?php echo $sb_active === 0 ? ' selected' : ''; ?>>All branches</option>
            <?php foreach ($sb_branches as $sbb): ?>
            <option value="<?php echo (int)$sbb['id']; ?>"<?php echo $sb_active === (int)$sbb['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($sbb['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['impersonator_id'])): ?>
    <div class="impersonate-notice">
        <i class="fas fa-user-secret"></i>
        <div class="impersonate-text">
            <span>Viewing as <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="impersonate.php?action=stop"><i class="fas fa-arrow-rotate-left"></i> Return to admin</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title">Navigation</div>
        <ul class="sidebar-menu">
            <?php
            // registry-driven items. render one <li> from a $RBAC_PAGES key (perm-gated)
            $rbacByKey = [];
            foreach ($RBAC_PAGES as $p) { $rbacByKey[$p['key']] = $p; }

            // active key = $current_page (page id matches registry key)
            // roles = hard editor gate, every other page = view perm. one gate, item + section test
            $navCanSee = function($key) { return $key === 'roles' ? canEditRbac($_SESSION['role'] ?? '') : can($key, 'v'); };

            // RETURNS the <li> so a dropdown group can nest it; renderNavItem just echoes the same html
            $navItem = function($key) use ($rbacByKey, $current_page, $navCanSee) {
                if (!isset($rbacByKey[$key]) || !$navCanSee($key)) return '';
                $p = $rbacByKey[$key];
                $icon = strpos($p['icon'], ' ') !== false ? $p['icon'] : 'fas ' . $p['icon']; // brands already prefixed
                $active = $current_page === $key ? 'active' : '';
                return '<li data-tooltip="' . htmlspecialchars($p['label']) . '">'
                     . '<a href="' . htmlspecialchars($p['file']) . '" class="' . $active . '">'
                     . '<i class="' . htmlspecialchars($icon) . '"></i>'
                     . '<span>' . htmlspecialchars($p['label']) . '</span>'
                     . '</a></li>';
            };

            $renderNavItem = function($key) use ($navItem) { echo $navItem($key); };

            // collapsible group: parent row + a right-side "+" that rotates to "x" when open.
            // The group holding the CURRENT page renders open server-side, and the saved state may
            // never close it — otherwise the page you are standing on hides behind a shut row.
            // Empty in, nothing out: a group whose children are all permission-denied never renders.
            $sbGroup = function(string $key, string $icon, string $label, string $links, array $pages) use ($current_page) {
                if (trim($links) === '') return '';
                $open = in_array($current_page, $pages, true);
                return '<li class="sb-group' . ($open ? ' open' : '') . '" data-group="' . htmlspecialchars($key) . '"'
                     . ' data-tooltip="' . htmlspecialchars($label) . '">'
                     . '<a href="#" class="sb-group-btn" onclick="sbGroup(event, this)" aria-expanded="' . ($open ? 'true' : 'false') . '">'
                     . '<i class="' . htmlspecialchars($icon) . '"></i><span>' . htmlspecialchars($label) . '</span>'
                     . '<i class="fas fa-plus sb-plus"></i></a>'
                     . '<ul class="sb-sub">' . $links . '</ul></li>';
            };

            // group parent shows only when at least one child is viewable
            $anyView = function(array $keys) use ($rbacByKey, $navCanSee) {
                foreach ($keys as $k) if (isset($rbacByKey[$k]) && $navCanSee($k)) return true;
                return false;
            };
            ?>
            <?php $renderNavItem('dashboard'); ?>
            <?php $renderNavItem('my_results'); ?>
        </ul>
    </div>

    <?php foreach ($nav_sections as $navGroup => $navKeys): ?>
    <?php if ($navGroup === 'System' || !$anyView($navKeys)) continue; ?>
    <div class="sidebar-menu-section">
        <div class="sidebar-menu-title"><?php echo htmlspecialchars($navGroup); ?></div>
        <ul class="sidebar-menu">
            <?php foreach ($navKeys as $nk) $renderNavItem($nk); ?>
        </ul>
    </div>
    <?php endforeach; ?>

    <?php
    // My Account and System are the secondary sections: they collapse into dropdown groups so the
    // daily-use navigation above them stays the first thing on screen. Daily sections stay flat.
    $acctPage  = ['account', 'ui', 'about'];
    $acctLinks = '<li data-tooltip="My Profile"><a href="account.php" class="' . ($current_page === 'account' ? 'active' : '') . '">'
               . '<i class="fas fa-user"></i><span>My Profile</span></a></li>'
               . '<li data-tooltip="UI Customization"><a href="ui.php" class="' . ($current_page === 'ui' ? 'active' : '') . '">'
               . '<i class="fas fa-palette"></i><span>UI Customization</span></a></li>'
               . '<li data-tooltip="About App"><a href="about.php" class="' . ($current_page === 'about' ? 'active' : '') . '">'
               . '<i class="fas fa-info-circle"></i><span>About App</span></a></li>';

    // system pages incl. users + activity logs — sits under My Account, so it renders last
    $systemKeys = $nav_sections['System'] ?? [];
    $sysLinks   = '';
    foreach ($systemKeys as $sk) $sysLinks .= $navItem($sk);
    ?>
    <div class="sidebar-menu-section">
        <ul class="sidebar-menu">
            <?php echo $sbGroup('account', 'fas fa-circle-user', 'My Account', $acctLinks, $acctPage); ?>
            <?php echo $sbGroup('system', 'fas fa-gears', 'System', $sysLinks, $systemKeys); ?>
        </ul>
    </div>
    <div id="google_translate_element" class="notranslate gt-offscreen"></div>
    <div class="sidebar-theme">
        <button onclick="toggleTheme()">
            <i class="fas fa-moon" id="themeIcon"></i>
            <span id="themeText">Dark Mode</span>
        </button>
    </div>
    <div class="sidebar-logout">
        <button onclick="window.location.href='logout.php'">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- csrf for every page that ships the sidebar — the bell, push subscribe and theme save all
     read it, so it has to exist before their scripts run. pages that set it again are harmless. -->
<script>window.ORMS_CSRF = window.ORMS_CSRF || '<?php echo csrfToken(); ?>';</script>

<!-- Theme Toggle JavaScript -->
<script>
/**
 * Theme Toggle Functionality
 * Handles light/dark mode switching with localStorage persistence
 * Also respects user's saved theme preference from database
 */
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const userThemeMode = '<?php echo $user_theme['theme_mode']; ?>';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    // Priority: localStorage > database setting > system preference
    let isDark = false;
    if (savedTheme) {
        isDark = savedTheme === 'dark';
    } else if (userThemeMode) {
        isDark = userThemeMode === 'dark';
        // Save to localStorage so it persists
        localStorage.setItem('theme', userThemeMode);
    } else {
        isDark = prefersDark;
    }

    if (isDark) {
        document.body.classList.add('dark-mode');
        updateThemeButton(true);
    } else {
        document.body.classList.remove('dark-mode');
        updateThemeButton(false);
    }
}

function toggleTheme() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButton(isDark);
}

function updateThemeButton(isDark) {
    const icon = document.getElementById('themeIcon');
    const text = document.getElementById('themeText');

    if (icon && text) {
        if (isDark) {
            icon.className = 'fas fa-sun';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

// Initialize theme on page load
initTheme();

/**
 * Sidebar Collapse Functionality
 * Handles sidebar expand/collapse with localStorage persistence
 */
function initSidebar() {
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        document.getElementById('sidebar').classList.add('collapsed');
        updateSidebarIcon(true);
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed);
    updateSidebarIcon(isCollapsed);
}

/* Sidebar dropdown groups — the "+" opens the submenu and rotates into an "x". */
function sbGroup(e, btn) {
    e.preventDefault();
    const li = btn.closest('.sb-group');
    if (!li) return;
    const open = li.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    let st = {};
    try { st = JSON.parse(localStorage.getItem('sbGroups') || '{}'); } catch (err) { st = {}; }
    st[li.dataset.group] = open;
    try { localStorage.setItem('sbGroups', JSON.stringify(st)); } catch (err) {}
}

/* restore the remembered state — but a group holding the ACTIVE page is never closed by it,
   or the page you are standing on hides behind a shut row. */
(function () {
    let st = {};
    try { st = JSON.parse(localStorage.getItem('sbGroups') || '{}'); } catch (err) { return; }
    document.querySelectorAll('.sb-group').forEach(function (li) {
        if (li.querySelector('.sb-sub a.active')) return;
        const want = !!st[li.dataset.group];
        li.classList.toggle('open', want);
        const b = li.querySelector('.sb-group-btn');
        if (b) b.setAttribute('aria-expanded', want ? 'true' : 'false');
    });
})();

function updateSidebarIcon(isCollapsed) {
    const icon = document.getElementById('sidebarToggleIcon');
    if (icon) {
        icon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
}

// Initialize sidebar on page load
initSidebar();

// branch filter -> session, then reload so every query on the page re-runs scoped.
// wired on DOMContentLoaded because orms.js loads at the end of the page
document.addEventListener('DOMContentLoaded', function () {
    var pick = document.getElementById('sbBranchPick');
    if (!pick || !window.ORMS || !window.jQuery) return;
    ORMS.dropdown('#sbBranchPick');
    pick.closest('.orms-tn-switch').classList.remove('initially-hidden');
    jQuery('#sbBranchPick').on('change', function () {
        var id = this.value || 0;
        ORMS.post('setBranch', { branch_id: id, csrf_token: '<?php echo csrfToken(); ?>' },
                  { url: 'sidebar.php', verb: 'Switching branch' })
            .done(function (res) {
                if (res && res.success) { window.location.reload(); return; }
                ORMS.err((res && res.message) || 'Could not switch branch');
            })
            .fail(function (msg) { ORMS.err(msg || 'Could not switch branch'); });
    });
});

// Listen for system theme changes
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        if (e.matches) {
            document.body.classList.add('dark-mode');
            updateThemeButton(true);
        } else {
            document.body.classList.remove('dark-mode');
            updateThemeButton(false);
        }
    }
});
</script>

<!-- Google Translate Integration -->
<script>
/**
 * Google Translate Functionality
 * Auto-translates page based on admin's default language setting
 */
function googleTranslateElementInit() {
    new google.translate.TranslateElement({
        pageLanguage: 'en',
        autoDisplay: false,
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE
    }, 'google_translate_element');
}

// Set default language from admin setting
(function() {
    const defaultLang = '<?php echo htmlspecialchars($default_language); ?>';
    const isReverted = localStorage.getItem('lang_reverted_to_english');

    if (isReverted === 'true') {
        // User manually reverted to English — don't auto-translate
        return;
    }

    if (defaultLang && defaultLang !== 'en') {
        const currentTrans = document.cookie.split(';').find(c => c.trim().startsWith('googtrans='));
        if (!currentTrans || currentTrans.trim() === 'googtrans=') {
            document.cookie = 'googtrans=/en/' + defaultLang + ';path=/';
            document.cookie = 'googtrans=/en/' + defaultLang + ';path=/;domain=' + window.location.hostname;
        }
    }
})();

function revertToEnglish() {
    // Clear googtrans cookies
    document.cookie = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.cookie = 'googtrans=;path=/;domain=' + window.location.hostname + ';expires=Thu, 01 Jan 1970 00:00:00 GMT';
    // Remember user chose English
    localStorage.setItem('lang_reverted_to_english', 'true');
    location.reload();
}
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<!-- Web Push opt-in (registers sw.js, offers "Enable alerts") -->
<script src="push_register.js" defer></script>

<!-- PWA install prompt ("Install app" chip when installable) -->
<script src="pwa_install.js" defer></script>

<!-- Prefetch sidebar destinations on hover -> instant + smooth page transitions.
     Scoped to real page links (skips the '#' submenu toggles; logout is a button, never prefetched). -->
<script type="speculationrules">
{
  "prefetch": [
    {
      "source": "document",
      "where": { "selector_matches": ".sidebar-menu a[href]:not([href='#'])" },
      "eagerness": "moderate"
    }
  ]
}
</script>

<!-- reveal FOUC-guarded pages (about/backup/sessions) before the view-transition snapshot -->
<script>
window.addEventListener('pagereveal', function () {
    if (document.body) document.body.classList.remove('initially-hidden');
});
</script>

<!-- Demo Data Quick-Add — one injector for the whole app: walks every .modal-overlay holding a
     form and drops an fa-circle-info button beside its close icon. New modals get it for free.
     Testing aid: gate it on a role or delete this block before a client go-live. -->
<script>
(function () {
    // one sequence per form, remembered across reloads — unique columns reject a repeated set
    function demoSeq(key) {
        var k = 'rsDemoSeq_' + key, n = parseInt(localStorage.getItem(k) || '0', 10) + 1;
        try { localStorage.setItem(k, n); } catch (e) {}
        return n;
    }
    function demoDate(off) {
        var d = new Date(); d.setDate(d.getDate() + off);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    // name/type aware — no per-page field map to rot when a column is added
    function demoValue(name, type, n, entity, label) {
        name = (name || '').toLowerCase(); label = label || 'Demo';
        var pad = String(n).padStart(3, '0'), yr = new Date().getFullYear();
        if (type === 'email' || /email/.test(name))    return 'demo' + n + '@demo.com';
        if (type === 'password')                       return 'demo1234';
        if (type === 'tel' || /phone|mobile|whatsapp|contact/.test(name))
            return /alternate|alt_|emergency/.test(name) ? '0321' + (2000000 + n) : '0300' + (1000000 + n);
        if (/cnic|passport|nic|nid|national_id/.test(name)) return '35202' + (10000000 + n);
        if (type === 'date') {
            if (/dob|birth/.test(name))                return demoDate(-(9000 + n * 30));
            if (/expiry|next|due|end|leaving/.test(name)) return demoDate(7 + (n % 21));
            return demoDate(-(n % 14));
        }
        if (type === 'time')                           return String(9 + (n % 8)).padStart(2, '0') + ':00';
        if (type === 'number' || /amount|fee|price|salary|cost|marks|total/.test(name)) return String((n % 20 + 1) * 500);
        if (/receipt/.test(name))                      return 'REC-' + yr + '-' + pad;
        if (/invoice/.test(name))                      return 'INV-' + yr + '-' + pad;
        if (/(^|_)(number|no|code|ref)$/.test(name))   return (entity || 'DEMO').substring(0, 3).toUpperCase() + '-' + yr + '-' + pad;
        if (/address/.test(name))                      return 'House ' + n + ', Street ' + ((n % 9) + 1) + ', Demo City';
        if (/city/.test(name))                         return 'Demo City';
        if (/nationality|country/.test(name))          return 'Demo Country';
        if (/father|guardian|mother/.test(name))       return 'Guardian ' + n;
        if (/first_name/.test(name))                   return entity || 'Demo';
        if (/last_name|surname/.test(name))            return String(n);
        if (/full_name|^name$|username/.test(name))    return (entity || 'Demo') + ' ' + n;
        if (/description|notes|remark|content|summary|reason/.test(name))
            return 'Demo ' + label.toLowerCase() + ' ' + n + ' — sample text for testing.';
        return label + ' ' + n;
    }

    function rsSeedForm(form, overlay) {
        var titleEl = overlay.querySelector('.modal-header h3');
        var entity = titleEl ? titleEl.textContent.trim().replace(/^(add|edit|new)\s+/i, '').trim() : '';
        var n = demoSeq(form.id || overlay.id || 'form');

        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            var type = (el.type || '').toLowerCase();
            if (el.disabled || el.readOnly) return;
            // hidden holds the record id — writing it silently turns an Add into an Edit
            if (['hidden', 'file', 'submit', 'button', 'reset'].indexOf(type) !== -1) return;

            if (type === 'checkbox') el.checked = n % 2 === 1;
            else if (el.tagName === 'SELECT') {
                var opts = Array.prototype.filter.call(el.options, function (o) { return o.value !== '' && !o.disabled; });
                if (!opts.length) return;
                el.value = opts[(n - 1) % opts.length].value;   // rotate real options -> valid fk
            } else {
                var g = el.closest('.form-group'), lab = g && g.querySelector('label');
                var v = String(demoValue(el.name || el.id, type, n, entity, lab ? lab.textContent.replace(/\*/g, '').trim() : ''));
                var max = parseInt(el.getAttribute('maxlength') || '0', 10);
                el.value = max > 0 && v.length > max ? v.substring(0, max) : v;
            }
            el.dispatchEvent(new Event('input',  { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        // searchable dropdowns mirror a hidden select — make them re-read it
        if (window.ORMS && ORMS.dropdown) form.querySelectorAll('select').forEach(function (s) {
            if (s.id) ORMS.dropdown.refresh('#' + s.id);
        });
        if (typeof Swal !== 'undefined')
            Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Demo set #' + n, timer: 1500, showConfirmButton: false });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.modal-overlay').forEach(function (ov) {
            var form = ov.querySelector('form'), head = ov.querySelector('.modal-header');
            if (!form || !head || head.querySelector('.demo-seed-btn')) return;
            var tools = head.querySelector('.modal-head-tools');
            if (!tools) {                                  // header is space-between — group the buttons
                tools = document.createElement('div');
                tools.className = 'modal-head-tools';
                head.appendChild(tools);
                var close = head.querySelector('.close-btn');
                if (close) tools.appendChild(close);
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'close-btn demo-seed-btn';
            btn.title = 'Fill this form with demo data (testing)';
            btn.innerHTML = '<i class="fas fa-circle-info"></i>';
            btn.addEventListener('click', function () { rsSeedForm(form, ov); });
            tools.insertBefore(btn, tools.firstChild);
        });
    });
})();
</script>
