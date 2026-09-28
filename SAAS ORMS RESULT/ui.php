<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
$user_id = $_SESSION['user_id'];
$current_page = 'ui';

// AJAX (per-user theme — always self-service, no rbac gate)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'getTheme':
                echo json_encode(['success' => true, 'data' => getUserTheme($user_id)]);
                exit();

            case 'saveTheme':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                requireCsrfJson(); // self-service, but still a write

                $d = themeDefaults();
                $theme_primary = isset($_POST['theme_primary']) ? trim($_POST['theme_primary']) : $d['theme_primary'];
                $theme_secondary = isset($_POST['theme_secondary']) ? trim($_POST['theme_secondary']) : $d['theme_secondary'];
                $theme_accent = isset($_POST['theme_accent']) ? trim($_POST['theme_accent']) : $d['theme_accent'];
                $theme_mode = isset($_POST['theme_mode']) ? trim($_POST['theme_mode']) : $d['theme_mode'];

                // validate hex
                $color_pattern = '/^#[0-9A-Fa-f]{6}$/';
                if (!preg_match($color_pattern, $theme_primary) ||
                    !preg_match($color_pattern, $theme_secondary) ||
                    !preg_match($color_pattern, $theme_accent)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid color format. Use hex colors like #111827']);
                    exit();
                }

                // validate mode
                if (!in_array($theme_mode, ['light', 'dark'])) {
                    $theme_mode = 'light';
                }

                $result = setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode);

                if ($result) {
                    logActivity($user_id, $username, 'Theme Updated', "Updated UI colors: Primary=$theme_primary, Secondary=$theme_secondary, Accent=$theme_accent, Mode=$theme_mode");
                    echo json_encode(['success' => true, 'message' => 'Theme settings saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save theme settings']);
                }
                exit();

            case 'resetTheme':
                requireCsrfJson(); // self-service, but still a write
                $d = themeDefaults();
                $result = setUserTheme($user_id, $d['theme_primary'], $d['theme_secondary'], $d['theme_accent'], $d['theme_mode']);

                if ($result) {
                    logActivity($user_id, $username, 'Theme Reset', 'Reset UI colors to default');
                    echo json_encode(['success' => true, 'message' => 'Theme reset to default']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset theme']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("ui.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// render page
$td = themeDefaults(); // form seed + reset baseline
?>
<!--
  Developed by Mohammad Rameez Imdad (Rameez Scripts)
  WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>UI Customization - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=15.2">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#001f3f">
    <link rel="apple-touch-icon" href="icon-192.png">
</head>
<body>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <div class="header-page">
                    <h1><i class="fas fa-palette"></i> UI Customization</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>UI Customization</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Your Theme (summary + actions) -->
            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title"><i class="fas fa-circle-half-stroke"></i> Your Theme</h3>
                </div>
                <div class="lte-card-body">
                    <div class="current-theme-strip">
                        <div class="current-swatch">
                            <span class="current-swatch-chip" id="curPrimary"></span>
                            <div>
                                <span class="current-swatch-label">Primary</span>
                                <span class="current-swatch-hex" id="curPrimaryHex"><?php echo strtoupper($td['theme_primary']); ?></span>
                            </div>
                        </div>
                        <div class="current-swatch">
                            <span class="current-swatch-chip" id="curSecondary"></span>
                            <div>
                                <span class="current-swatch-label">Secondary</span>
                                <span class="current-swatch-hex" id="curSecondaryHex"><?php echo strtoupper($td['theme_secondary']); ?></span>
                            </div>
                        </div>
                        <div class="current-swatch">
                            <span class="current-swatch-chip" id="curAccent"></span>
                            <div>
                                <span class="current-swatch-label">Accent</span>
                                <span class="current-swatch-hex" id="curAccentHex"><?php echo strtoupper($td['theme_accent']); ?></span>
                            </div>
                        </div>
                        <div class="current-theme-meta">
                            <span class="current-theme-name" id="currentThemeName">Obsidian &amp; Mint · UIv7</span>
                            <span class="current-mode-badge" id="currentModeBadge"><i class="fas fa-sun"></i> Light</span>
                        </div>
                    </div>

                    <div class="theme-action-row">
                        <button type="button" class="btn btn-primary" onclick="saveTheme()">
                            <i class="fas fa-save"></i> Save Theme Settings
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetTheme()">
                            <i class="fas fa-undo"></i> Reset to Default
                        </button>
                    </div>
                </div>
            </div>

            <!-- Palette Gallery -->
            <div class="lte-card">
                <div class="lte-card-header">
                    <h3 class="lte-card-title"><i class="fas fa-swatchbook"></i> Color Palettes</h3>
                    <div class="lte-card-tools">
                        <button type="button" onclick="toggleLteCard(this)" title="Collapse"><i class="fas fa-minus"></i></button>
                    </div>
                </div>
                <div class="lte-card-body">
                    <p class="help-text ui-help-text">
                        <i class="fas fa-info-circle"></i> Click a palette to preview it live across the whole dashboard, then press <strong>Save Theme Settings</strong> to keep it. Saved per user.
                    </p>

                    <div class="palette-tools">
                        <div class="palette-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="palSearch" placeholder="Search 41 palettes by name, id or hex…" autocomplete="off">
                        </div>
                        <span class="palette-count" id="palCount"><?php echo count($UI_PALETTES); ?> palettes</span>
                    </div>

                    <div class="palette-gallery">
                        <?php $__grp = null; foreach ($UI_PALETTES as $pal):
                            $g = $pal['g'] ?? '';
                            if ($g !== $__grp): $__grp = $g; ?>
                        <div class="palette-group" data-group="<?php echo htmlspecialchars($g, ENT_QUOTES); ?>"><?php echo htmlspecialchars($g); ?></div>
                        <?php endif; ?>
                        <button type="button" class="palette-card"
                                data-name="<?php echo htmlspecialchars(strtolower($pal['name'] . ' ' . $pal['id'] . ' ' . $pal['p'] . ' ' . $pal['s'] . ' ' . $pal['a'] . ' ' . $g), ENT_QUOTES); ?>"
                                data-id="<?php echo $pal['id']; ?>"
                                data-p="<?php echo $pal['p']; ?>"
                                data-s="<?php echo $pal['s']; ?>"
                                data-a="<?php echo $pal['a']; ?>"
                                onclick="selectPalette('<?php echo $pal['p']; ?>', '<?php echo $pal['s']; ?>', '<?php echo $pal['a']; ?>', '<?php echo htmlspecialchars($pal['name'], ENT_QUOTES); ?>')">
                            <span class="palette-check"><i class="fas fa-check"></i></span>
                            <?php if ($pal['id'] === 'UIv7'): ?><span class="palette-default-badge">Default</span><?php endif; ?>
                            <span class="palette-swatch">
                                <span style="background: <?php echo $pal['p']; ?>;"></span>
                                <span style="background: <?php echo $pal['s']; ?>;"></span>
                                <span style="background: <?php echo $pal['a']; ?>;"></span>
                            </span>
                            <span class="palette-meta">
                                <span class="palette-name"><?php echo htmlspecialchars($pal['name']); ?></span>
                                <span class="palette-id"><?php echo $pal['id']; ?></span>
                            </span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Custom Colours & Mode (advanced, collapsed) -->
            <div class="lte-card collapsed">
                <div class="lte-card-header">
                    <h3 class="lte-card-title"><i class="fas fa-sliders-h"></i> Custom Colours &amp; Mode</h3>
                    <div class="lte-card-tools">
                        <button type="button" onclick="toggleLteCard(this)" title="Expand"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div class="lte-card-body">
                    <div class="form-grid form-grid-3col">
                        <div class="form-group">
                            <label><i class="fas fa-square" id="primaryColorIcon"></i> Primary Color</label>
                            <div class="color-picker-row">
                                <input type="color" id="theme_primary" name="theme_primary" value="<?php echo $td['theme_primary']; ?>" class="color-picker-input">
                                <input type="text" id="theme_primary_hex" value="<?php echo $td['theme_primary']; ?>" maxlength="7" class="hex-input">
                            </div>
                            <small class="help-text color-help-text">Sidebar &amp; headers</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-square" id="secondaryColorIcon"></i> Secondary Color</label>
                            <div class="color-picker-row">
                                <input type="color" id="theme_secondary" name="theme_secondary" value="<?php echo $td['theme_secondary']; ?>" class="color-picker-input">
                                <input type="text" id="theme_secondary_hex" value="<?php echo $td['theme_secondary']; ?>" maxlength="7" class="hex-input">
                            </div>
                            <small class="help-text color-help-text">Hover states &amp; gradients</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-square" id="accentColorIcon"></i> Accent Color</label>
                            <div class="color-picker-row">
                                <input type="color" id="theme_accent" name="theme_accent" value="<?php echo $td['theme_accent']; ?>" class="color-picker-input">
                                <input type="text" id="theme_accent_hex" value="<?php echo $td['theme_accent']; ?>" maxlength="7" class="hex-input">
                            </div>
                            <small class="help-text color-help-text">Buttons &amp; links</small>
                        </div>
                    </div>

                    <div class="form-group mt-20">
                        <label><i class="fas fa-adjust"></i> Default Theme Mode</label>
                        <div class="theme-mode-options">
                            <label class="theme-mode-option" id="lightModeOption">
                                <input type="radio" name="theme_mode" value="light" id="theme_mode_light" onchange="onModeChange()" <?php echo $td['theme_mode'] !== 'dark' ? 'checked' : ''; ?>>
                                <i class="fas fa-sun icon-sun"></i>
                                <span>Light Mode</span>
                            </label>
                            <label class="theme-mode-option" id="darkModeOption">
                                <input type="radio" name="theme_mode" value="dark" id="theme_mode_dark" onchange="onModeChange()" <?php echo $td['theme_mode'] === 'dark' ? 'checked' : ''; ?>>
                                <i class="fas fa-moon icon-moon"></i>
                                <span>Dark Mode</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="orms.js?v=2.5"></script>

    <script>
        // sidebar.php defines ORMS_CSRF — ride it on every raw $.ajax from this page
        $(document).ajaxSend(function (e, x) { if (window.ORMS_CSRF) x.setRequestHeader('X-CSRF-Token', window.ORMS_CSRF); });

        // app default palette (UIv7 · Obsidian & Mint) from config
        const THEME_DEFAULTS = <?php echo json_encode(themeDefaults()); ?>;

        $(document).ready(function() {
            loadTheme();
        });

        // collapse / expand an lte-card
        function toggleLteCard(btn) {
            const card = btn.closest('.lte-card');
            const collapsed = card.classList.toggle('collapsed');
            const icon = btn.querySelector('i');
            if (icon) icon.className = 'fas fa-' + (collapsed ? 'plus' : 'minus');
            btn.title = collapsed ? 'Expand' : 'Collapse';
        }

        // pull saved theme into the controls
        function loadTheme() {
            $.ajax({
                url: '?action=getTheme',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        loadThemeValues(response.data);
                    } else {
                        refreshUI();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    refreshUI(); // fall back to seeded defaults
                }
            });
        }

        // sync color picker -> hex
        document.getElementById('theme_primary').addEventListener('input', function() {
            document.getElementById('theme_primary_hex').value = this.value.toUpperCase();
            refreshUI();
        });
        document.getElementById('theme_secondary').addEventListener('input', function() {
            document.getElementById('theme_secondary_hex').value = this.value.toUpperCase();
            refreshUI();
        });
        document.getElementById('theme_accent').addEventListener('input', function() {
            document.getElementById('theme_accent_hex').value = this.value.toUpperCase();
            refreshUI();
        });

        // sync hex -> color picker
        document.getElementById('theme_primary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { document.getElementById('theme_primary').value = this.value; refreshUI(); }
        });
        document.getElementById('theme_secondary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { document.getElementById('theme_secondary').value = this.value; refreshUI(); }
        });
        document.getElementById('theme_accent_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) { document.getElementById('theme_accent').value = this.value; refreshUI(); }
        });

        // one pass: summary swatches + label icons + live re-skin + active palette + mode badge
        function refreshUI() {
            const p = document.getElementById('theme_primary').value;
            const s = document.getElementById('theme_secondary').value;
            const a = document.getElementById('theme_accent').value;

            // summary chips
            document.getElementById('curPrimary').style.background = p;
            document.getElementById('curSecondary').style.background = s;
            document.getElementById('curAccent').style.background = a;
            document.getElementById('curPrimaryHex').textContent = p.toUpperCase();
            document.getElementById('curSecondaryHex').textContent = s.toUpperCase();
            document.getElementById('curAccentHex').textContent = a.toUpperCase();

            // label icons (advanced card)
            document.getElementById('primaryColorIcon').style.color = p;
            document.getElementById('secondaryColorIcon').style.color = s;
            document.getElementById('accentColorIcon').style.color = a;

            applyThemePreview();      // live re-skin whole page
            highlightActivePalette(); // mark matching gallery card + name
        }

        // apply current colors live (temp preview, not persisted)
        function applyThemePreview() {
            const p = document.getElementById('theme_primary').value;
            const s = document.getElementById('theme_secondary').value;
            const a = document.getElementById('theme_accent').value;
            document.documentElement.style.setProperty('--navy-primary', p);
            document.documentElement.style.setProperty('--navy-light', s);
            document.documentElement.style.setProperty('--navy-dark', p);
            document.documentElement.style.setProperty('--navy-hover', s);
            document.documentElement.style.setProperty('--navy-accent', a);
        }

        // mark the palette card matching the form; show its name (or "Custom")
        function highlightActivePalette() {
            const cp = document.getElementById('theme_primary').value.toLowerCase();
            const cs = document.getElementById('theme_secondary').value.toLowerCase();
            const ca = document.getElementById('theme_accent').value.toLowerCase();
            let matched = null;
            document.querySelectorAll('.palette-card').forEach(function(card) {
                const m = card.dataset.p.toLowerCase() === cp
                       && card.dataset.s.toLowerCase() === cs
                       && card.dataset.a.toLowerCase() === ca;
                card.classList.toggle('active', m);
                if (m) matched = card.querySelector('.palette-name').textContent + ' · ' + card.dataset.id;
            });
            document.getElementById('currentThemeName').textContent = matched || 'Custom colours';
        }

        // gallery pick -> fill controls + live preview
        function selectPalette(p, s, a, name) {
            document.getElementById('theme_primary').value = p;
            document.getElementById('theme_primary_hex').value = p.toUpperCase();
            document.getElementById('theme_secondary').value = s;
            document.getElementById('theme_secondary_hex').value = s.toUpperCase();
            document.getElementById('theme_accent').value = a;
            document.getElementById('theme_accent_hex').value = a.toUpperCase();
            refreshUI();
        }

        // mode radio changed -> update badge + live dark/light preview
        function onModeChange() {
            const dark = document.getElementById('theme_mode_dark').checked;
            refreshModeBadge();
            document.body.classList.toggle('dark-mode', dark);
        }

        // badge text only (no live body toggle) — used on load
        function refreshModeBadge() {
            const dark = document.getElementById('theme_mode_dark').checked;
            document.getElementById('currentModeBadge').innerHTML = dark
                ? '<i class="fas fa-moon"></i> Dark'
                : '<i class="fas fa-sun"></i> Light';
        }

        // persist
        function saveTheme() {
            const formData = new FormData();
            formData.append('theme_primary', document.getElementById('theme_primary').value);
            formData.append('theme_secondary', document.getElementById('theme_secondary').value);
            formData.append('theme_accent', document.getElementById('theme_accent').value);
            formData.append('theme_mode', document.querySelector('input[name="theme_mode"]:checked').value);

            Swal.fire({ title: 'Saving Theme...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.ajax({
                url: '?action=saveTheme',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        applyThemePreview();
                        const mode = document.querySelector('input[name="theme_mode"]:checked').value;
                        localStorage.setItem('theme', mode);
                        document.body.classList.toggle('dark-mode', mode === 'dark');

                        Swal.fire({ icon: 'success', title: 'Theme Saved!', text: 'Your custom theme has been saved.', timer: 2000, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                }
            });
        }

        // reset to app default (UIv7)
        function resetTheme() {
            Swal.fire({
                icon: 'warning',
                title: 'Reset Theme?',
                text: 'This will reset your colors to the default Obsidian & Mint (UIv7) theme.',
                showCancelButton: true,
                confirmButtonColor: THEME_DEFAULTS.theme_primary,
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (!result.isConfirmed) return;
                $.ajax({
                    url: '?action=resetTheme',
                    method: 'POST',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            loadThemeValues(THEME_DEFAULTS);
                            localStorage.setItem('theme', THEME_DEFAULTS.theme_mode);
                            document.body.classList.toggle('dark-mode', THEME_DEFAULTS.theme_mode === 'dark');
                            Swal.fire({ icon: 'success', title: 'Theme Reset!', text: 'Colors reset to the default palette.', timer: 2000, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + error });
                    }
                });
            });
        }

        // fill controls from a theme object {theme_primary, theme_secondary, theme_accent, theme_mode}
        function loadThemeValues(data) {
            if (data.theme_primary) {
                document.getElementById('theme_primary').value = data.theme_primary;
                document.getElementById('theme_primary_hex').value = data.theme_primary.toUpperCase();
            }
            if (data.theme_secondary) {
                document.getElementById('theme_secondary').value = data.theme_secondary;
                document.getElementById('theme_secondary_hex').value = data.theme_secondary.toUpperCase();
            }
            if (data.theme_accent) {
                document.getElementById('theme_accent').value = data.theme_accent;
                document.getElementById('theme_accent_hex').value = data.theme_accent.toUpperCase();
            }
            document.getElementById('theme_mode_dark').checked = (data.theme_mode === 'dark');
            document.getElementById('theme_mode_light').checked = (data.theme_mode !== 'dark');

            refreshUI();
            refreshModeBadge();
        }

        // 41 swatches is a wall unless you can narrow it. Matches name, id, section AND hex, so
        // "#635BFF" or "blurple" or "fintech" all land on the same card.
        (function () {
            var box = document.getElementById('palSearch');
            if (!box) return;
            var cards   = [].slice.call(document.querySelectorAll('.palette-card'));
            var heads   = [].slice.call(document.querySelectorAll('.palette-group'));
            var counter = document.getElementById('palCount');
            box.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase(), shown = 0;
                cards.forEach(function (c) {
                    var hit = !q || (c.getAttribute('data-name') || '').indexOf(q) >= 0;
                    c.hidden = !hit;
                    if (hit) shown++;
                });
                // a section heading with nothing under it is noise
                heads.forEach(function (h) {
                    var any = false, n = h.nextElementSibling;
                    while (n && !n.classList.contains('palette-group')) {
                        if (n.classList.contains('palette-card') && !n.hidden) { any = true; break; }
                        n = n.nextElementSibling;
                    }
                    h.hidden = !any;
                });
                counter.textContent = shown + (shown === 1 ? ' palette' : ' palettes') + (q ? ' matched' : '');
            });
        })();
    </script>
</body>
</html>
