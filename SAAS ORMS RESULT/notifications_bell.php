<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Shared header bell — include in the header of every dashboard page
 */
// header quick-theme state (palette registry + current user theme from config)
// the registry holds 41 palettes; a header dropdown is a quick pick, not a gallery — the first
// dozen fit the panel and "More theme options" opens the searchable full set on ui.php
$__palettes = array_slice(isset($UI_PALETTES) ? $UI_PALETTES : [], 0, 12);
$__uiTheme = (function_exists('getUserTheme') && isset($user_id)) ? getUserTheme($user_id) : ['theme_primary' => '#111827', 'theme_accent' => '#34D399'];
?>
<div class="header-right">
    <!-- page action buttons (Add / Refresh / exports) get hoisted in here by orms.js,
         so every section shows its own toolbar in the header instead of above the table -->
    <div class="header-actions" id="headerActions"></div>

    <!-- Theme quick switcher -->
    <div class="header-tool-wrapper">
        <button class="header-tool-btn" onclick="toggleThemeMenu(event)" title="Theme & appearance"><i class="fas fa-palette"></i></button>
        <div class="header-tool-dropdown theme-quick-menu" id="themeQuickMenu">
            <div class="header-tool-header">
                <strong><i class="fas fa-palette"></i> Theme</strong>
                <button type="button" class="mode-toggle-btn" onclick="quickToggleMode()" title="Toggle light / dark">
                    <i class="fas fa-adjust"></i> <span id="quickModeLabel">Dark</span>
                </button>
            </div>
            <div class="theme-quick-grid">
                <?php foreach ($__palettes as $pal):
                    $__act = (strcasecmp($pal['p'], $__uiTheme['theme_primary']) === 0 && strcasecmp($pal['a'], $__uiTheme['theme_accent']) === 0) ? ' active' : '';
                ?>
                <button type="button" class="theme-quick-swatch<?php echo $__act; ?>" title="<?php echo htmlspecialchars($pal['name'] . ' (' . $pal['id'] . ')'); ?>"
                        data-p="<?php echo $pal['p']; ?>" data-a="<?php echo $pal['a']; ?>"
                        onclick="quickApplyTheme('<?php echo $pal['p']; ?>','<?php echo $pal['s']; ?>','<?php echo $pal['a']; ?>')">
                    <span style="background: linear-gradient(135deg, <?php echo $pal['p']; ?> 0 45%, <?php echo $pal['a']; ?> 55% 100%);"></span>
                </button>
                <?php endforeach; ?>
            </div>
            <a href="ui.php" class="header-tool-footer">More theme options <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>

    <!-- Language switcher -->
    <div class="header-tool-wrapper">
        <button class="header-tool-btn" onclick="toggleLangMenu(event)" title="Language">
            <i class="fas fa-language"></i><span class="lang-current" id="curLangLabel">EN</span>
        </button>
        <div class="header-tool-dropdown lang-menu" id="langMenu"></div>
    </div>

    <div class="notification-bell-wrapper">
        <button class="notification-bell-btn" onclick="toggleNotificationDropdown()" title="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-badge" id="notifBadge">0</span>
        </button>
        <div class="notification-dropdown" id="notifDropdown">
            <div class="notification-dropdown-header">
                <strong>Notifications</strong>
                <button onclick="markAllNotificationsRead()" title="Mark all read"><i class="fas fa-check-double"></i> Mark all read</button>
            </div>
            <div class="notification-dropdown-body" id="notifList">
                <div class="notification-empty"><i class="fas fa-bell-slash"></i><br>No notifications</div>
            </div>
        </div>
    </div>
    <div>Welcome, <?php echo htmlspecialchars($username); ?></div>
</div>

<script>
(function() {
    // Prevent duplicate initialization
    if (window._notifBellInit) return;
    window._notifBellInit = true;

    var notifDropdownOpen = false;

    window.toggleNotificationDropdown = function() {
        var dd = document.getElementById('notifDropdown');
        notifDropdownOpen = !notifDropdownOpen;
        if (notifDropdownOpen) {
            dd.classList.add('open');
            loadNotifications();
        } else {
            dd.classList.remove('open');
        }
    };

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        var wrapper = document.querySelector('.notification-bell-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            var dd = document.getElementById('notifDropdown');
            if (dd) dd.classList.remove('open');
            notifDropdownOpen = false;
        }
    });

    function timeAgoNotif(dateStr) {
        var seconds = Math.floor((new Date() - new Date(dateStr.replace(' ', 'T'))) / 1000);
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
        return Math.floor(seconds / 86400) + 'd ago';
    }

    function getTypeIcon(type) {
        switch(type) {
            case 'success': return '<i class="fas fa-check-circle text-success"></i>';
            case 'warning': return '<i class="fas fa-exclamation-triangle text-warning"></i>';
            case 'danger': return '<i class="fas fa-exclamation-circle text-danger"></i>';
            default: return '<i class="fas fa-info-circle text-info"></i>';
        }
    }

    function loadNotifications() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'notifications_api.php?action=getRecent', true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        renderNotifications(resp.notifications);
                        updateBadge(resp.count);
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    function renderNotifications(notifications) {
        var container = document.getElementById('notifList');
        if (!notifications || notifications.length === 0) {
            container.innerHTML = '<div class="notification-empty"><i class="fas fa-bell-slash"></i><br>No notifications</div>';
            return;
        }

        var html = '';
        notifications.forEach(function(n) {
            var unreadClass = n.is_read == 0 ? ' unread' : '';
            // link is data too — every caller passes a literal path today, but an unescaped value
            // inside an inline handler is one deep-link away from being stored xss
            var linkAttr = n.link ? ' data-href="' + escapeHtml(n.link) + '"' : '';
            html += '<div class="notification-item' + unreadClass + '"' + linkAttr + ' data-id="' + n.id + '">';
            html += '<div class="notification-item-icon">' + getTypeIcon(n.type) + '</div>';
            html += '<div class="notification-item-content">';
            html += '<div class="notification-item-title">' + escapeHtml(n.title) + '</div>';
            html += '<div class="notification-item-message">' + escapeHtml(n.message) + '</div>';
            html += '<div class="notification-item-time">' + timeAgoNotif(n.created_at) + '</div>';
            html += '</div>';
            if (n.is_read == 0) {
                html += '<button class="notification-item-mark" onclick="event.stopPropagation();markNotifRead(' + n.id + ',this)" title="Mark read"><i class="fas fa-check"></i></button>';
            }
            html += '</div>';
        });
        container.innerHTML = html;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateBadge(count) {
        var badge = document.getElementById('notifBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    window.markNotifRead = function(id, btn) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'notifications_api.php?action=markRead', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                if (btn) {
                    var item = btn.closest('.notification-item');
                    if (item) item.classList.remove('unread');
                    btn.remove();
                }
                pollNotificationCount();
            }
        };
        xhr.send('id=' + id + '&csrf_token=' + encodeURIComponent(window.ORMS_CSRF || ''));
    };

    window.markAllNotificationsRead = function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'notifications_api.php?action=markAllRead', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                loadNotifications();
                updateBadge(0);
            }
        };
        xhr.send('csrf_token=' + encodeURIComponent(window.ORMS_CSRF || ''));
    };

    function pollNotificationCount() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'notifications_api.php?action=getCount', true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        updateBadge(resp.count);
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    // hide the badge before first paint, then load the real count
    updateBadge(0);
    pollNotificationCount();

    // Poll every 30 seconds
    setInterval(pollNotificationCount, 30000);
})();
</script>

<script>
// header tools — quick theme switcher + language switcher (both persist per user)
(function() {
    if (window._headerToolsInit) return;
    window._headerToolsInit = true;

    function closeTools(except) {
        ['themeQuickMenu', 'langMenu'].forEach(function(id) {
            if (id !== except) { var el = document.getElementById(id); if (el) el.classList.remove('open'); }
        });
    }
    window.toggleThemeMenu = function(e) {
        if (e) e.stopPropagation();
        var m = document.getElementById('themeQuickMenu');
        var open = !m.classList.contains('open');
        closeTools('themeQuickMenu');
        m.classList.toggle('open', open);
    };
    window.toggleLangMenu = function(e) {
        if (e) e.stopPropagation();
        var m = document.getElementById('langMenu');
        var open = !m.classList.contains('open');
        closeTools('langMenu');
        m.classList.toggle('open', open);
    };
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.header-tool-wrapper')) closeTools();
    });

    // notification rows navigate from data-href — replaces the old inline onclick, which
    // interpolated the link straight into an attribute. the mark-read button opts out.
    document.addEventListener('click', function(e) {
        if (e.target.closest('.notification-mark-read, .mark-read-btn, button, a')) return;
        var row = e.target.closest('.notification-item[data-href]');
        if (row) window.location.href = row.getAttribute('data-href');
    });

    // persist theme (partial) to the shared endpoint
    function savTheme(params) {
        try {
            params = Object.assign({}, params, { csrf_token: window.ORMS_CSRF || '' });
            fetch('theme_save.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString()
            });
        } catch (e) {}
    }

    // instant re-skin + save (colours only; current mode kept)
    window.quickApplyTheme = function(p, s, a) {
        var r = document.documentElement.style;
        r.setProperty('--navy-primary', p);
        r.setProperty('--navy-light', s);
        r.setProperty('--navy-dark', p);
        r.setProperty('--navy-hover', s);
        r.setProperty('--navy-accent', a);
        savTheme({ theme_primary: p, theme_secondary: s, theme_accent: a });
        document.querySelectorAll('.theme-quick-swatch').forEach(function(sw) {
            sw.classList.toggle('active', sw.dataset.p.toLowerCase() === p.toLowerCase() && sw.dataset.a.toLowerCase() === a.toLowerCase());
        });
    };

    // light/dark toggle (syncs the sidebar button + saves mode)
    window.quickToggleMode = function() {
        var isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        if (typeof updateThemeButton === 'function') updateThemeButton(isDark);
        var lbl = document.getElementById('quickModeLabel');
        if (lbl) lbl.textContent = isDark ? 'Light' : 'Dark';
        savTheme({ theme_mode: isDark ? 'dark' : 'light' });
    };
    (function() { var l = document.getElementById('quickModeLabel'); if (l) l.textContent = document.body.classList.contains('dark-mode') ? 'Light' : 'Dark'; })();

    // ---- language (drives the sidebar's hidden google-translate combo, in place, no reload) ----
    window.GT_LANGS = window.GT_LANGS || [
        { code: 'en',    name: 'English',  label: 'EN', flag: '🇬🇧' },
        { code: 'fr',    name: 'Français', label: 'FR', flag: '🇫🇷' },
        { code: 'es',    name: 'Español',  label: 'ES', flag: '🇪🇸' },
        { code: 'ar',    name: 'العربية',   label: 'ع',  flag: '🇸🇦', rtl: true },
        { code: 'de',    name: 'Deutsch',  label: 'DE', flag: '🇩🇪' },
        { code: 'zh-CN', name: '中文',      label: '中',  flag: '🇨🇳' },
        { code: 'hi',    name: 'हिन्दी',     label: 'HI', flag: '🇮🇳' },
        { code: 'ur',    name: 'اردو',      label: 'UR', flag: '🇵🇰', rtl: true }
    ];

    function gtSetCookie(lang) {
        var host = location.hostname;
        document.cookie = 'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        document.cookie = 'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain=' + host;
        document.cookie = 'googtrans=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain=.' + host;
        if (lang && lang !== 'en') {
            document.cookie = 'googtrans=/en/' + lang + ';path=/';
            document.cookie = 'googtrans=/en/' + lang + ';path=/;domain=' + host;
        }
    }
    function gtFire(lang) {
        var combo = document.querySelector('.goog-te-combo');
        if (!combo) return false;
        combo.value = (lang === 'en' ? '' : lang);
        combo.dispatchEvent(new Event('change'));
        return true;
    }
    function renderLangs() {
        var menu = document.getElementById('langMenu');
        if (!menu) return;
        var cur = localStorage.getItem('appLang') || 'en';
        var html = '';
        window.GT_LANGS.forEach(function(l) {
            html += '<button type="button" class="lang-item' + (l.code === cur ? ' active' : '') + '" data-code="' + l.code + '" onclick="applyLanguage(\'' + l.code + '\')">'
                 + '<span class="lang-flag">' + l.flag + '</span><span>' + l.name + '</span>'
                 + (l.code === cur ? '<i class="fas fa-check lang-check"></i>' : '') + '</button>';
        });
        menu.innerHTML = html;
        var lbl = document.getElementById('curLangLabel');
        var meta = window.GT_LANGS.find(function(l) { return l.code === cur; });
        if (lbl && meta) lbl.textContent = meta.label;
    }
    window.applyLanguage = function(lang, skipSave) {
        if (!skipSave) localStorage.setItem('appLang', lang);
        // keep the sidebar's english-default logic in sync
        if (lang === 'en') localStorage.setItem('lang_reverted_to_english', 'true');
        else localStorage.removeItem('lang_reverted_to_english');
        gtSetCookie(lang);
        var meta = window.GT_LANGS.find(function(l) { return l.code === lang; });
        document.documentElement.setAttribute('dir', (meta && meta.rtl) ? 'rtl' : 'ltr');
        renderLangs();
        closeTools();
        cleanSwitchGt(lang);
    };

    // clean switch — revert the current translation to the original first (this clears the
    // OLD language so there's no stale/mixed text), then apply the new one. Reload only as a
    // last resort if GT never loads (cookie is already set; server-side session keeps login).
    function cleanSwitchGt(lang) {
        function doSwitch() {
            gtFire('en');                                   // revert to original -> old cache cleared
            if (lang !== 'en') setTimeout(function () { gtFire(lang); }, 90);
        }
        if (document.querySelector('.goog-te-combo')) { doSwitch(); return; }
        var n = 0, t = setInterval(function () {
            if (document.querySelector('.goog-te-combo')) { clearInterval(t); doSwitch(); }
            else if (++n > 25) { clearInterval(t); location.reload(); }
        }, 200);
    }

    renderLangs();

    // re-apply the user's saved language once the google combo is ready
    (function() {
        var saved = localStorage.getItem('appLang');
        if (!saved || saved === 'en') return;
        var n = 0, t = setInterval(function() {
            if (document.querySelector('.goog-te-combo')) { clearInterval(t); applyLanguage(saved, true); }
            else if (++n > 60) clearInterval(t);
        }, 300);
    })();
})();
</script>
