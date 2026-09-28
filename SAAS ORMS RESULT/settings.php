<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'User';
$user_id = $_SESSION['user_id'];
$current_page = 'settings';

// rbac view gate
requirePerm('settings', 'v');

$isPlat = ormsIsPlatform();   // smtp/vapid/oauth/platform_mode + maintenance are the operator's
$school = sid();

// branding lives under the school's own folder — a flat uploads/branding/ lets one school's
// upload collide with (and overwrite) another's, and hands out a guessable cross-tenant path
function brandingDir(int $school): string { return 'uploads/' . $school . '/branding/'; }

// is this file still pointed at by ANOTHER school's site_logo? legacy flat uploads predate the
// split, so two rows can share one file — unlink then would blank the other school's logo.
// pre-migration db has one tenant only, so a failed probe means "safe to remove"
function logoShared(string $rel, int $school): bool {
    if ($rel === '') return false;
    try {
        return (int) qVal("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'site_logo'
                           AND setting_value = ? AND school_id <> ?", 'si', $rel, $school) > 0;
    } catch (Throwable $e) { return false; }
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'getSettings') {
        try {
            $allowUserUploads = getSetting('allow_user_profile_uploads', '1');
            $currency = ormsCurrency();
            $default_language = getSetting('default_language', 'en');
            $maintenance_mode = getSetting('maintenance_mode', '0');
            $branding = getSiteBranding();

            echo json_encode([
                'success' => true,
                'data' => [
                    'allow_user_profile_uploads' => $allowUserUploads,
                    'currency_code' => $currency['code'],
                    'currency_symbol' => $currency['symbol'],
                    'default_language' => $default_language,
                    'is_platform' => $isPlat ? 1 : 0,
                    'maintenance_mode' => $maintenance_mode,
                    'platform_mode' => (string) ormsPlatformSetting('platform_mode', '0'),
                    'site_name' => $branding['site_name'],
                    'site_logo' => $branding['site_logo'],
                    'copyright_text' => $branding['copyright_text']
                ]
            ]);
            exit();
        } catch (Exception $e) {
            error_log('settings getSettings: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading settings. Please try again.']);
            exit();
        }
    }

    if ($_POST['action'] === 'saveSettings') {
        requirePermJson('settings', 'e');
        requireCsrfJson();
        try {
            $allowUserUploads = isset($_POST['allow_user_profile_uploads']) ? $_POST['allow_user_profile_uploads'] : '0';
            $siteName = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
            $copyrightText = isset($_POST['copyright_text']) ? trim($_POST['copyright_text']) : '';

            // Save settings
            setSetting('allow_user_profile_uploads', $allowUserUploads);

            if (!empty($siteName)) {
                setSetting('site_name', $siteName);
            }
            if (!empty($copyrightText)) {
                setSetting('copyright_text', $copyrightText);
            }

            $default_language = isset($_POST['default_language']) ? trim($_POST['default_language']) : 'en';
            setSetting('default_language', $default_language);

            // maintenance mode is the OPERATOR's switch: login/signup/oauth read it with no session,
            // so it resolves against the platform row. a school writing its own copy would take
            // nothing offline and would read as if it had — silently ignore the field for tenants
            if ($isPlat) {
                // 🚨 ONE currency for the whole install and it is the App Owner's. ormsPlatformSet(),
                // never setSetting(): setSetting() writes ormsSettingSchool(), and a school-scoped copy
                // SHADOWS the platform row in ormsSettingCache() — the exact bug that gave school 1 its
                // own symbol and left every other tenant on the seed default.
                // validate against the registry, derive the symbol server-side - never trust a posted one
                $currency_code = ormsCurrencyCode($_POST['currency_code'] ?? $_POST['currency_symbol'] ?? '');
                ormsPlatformSet('currency_code',    $currency_code);
                ormsPlatformSet('currency_symbol',  ormsCurrencies()[$currency_code][0]);
                ormsPlatformSet('billing_currency', $currency_code);   // legacy mirror — kept in step, not read

                setSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? $_POST['maintenance_mode'] : '0');
                // 🚨 the SaaS master switch is written with ormsPlatformSet(), NOT setSetting(): it is
                // read through ormsPlatformSetting() (school 0, flat), and a school-scoped copy is the
                // exact shadowing bug that once left plan limits and the expiry gate silently inert.
                ormsPlatformSet('platform_mode', ($_POST['platform_mode'] ?? '0') === '1' ? '1' : '0');
            }

            // Log activity
            logActivity($user_id, $username, 'Settings Updated', 'System settings updated');

            echo json_encode([
                'success' => true,
                'message' => 'Settings saved successfully'
            ]);
            exit();
        } catch (Exception $e) {
            error_log('settings saveSettings: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error saving settings. Please try again.']);
            exit();
        }
    }

    if ($_POST['action'] === 'uploadSiteLogo') {
        requirePermJson('settings', 'e');
        requireCsrfJson();
        try {
            if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred']);
                exit();
            }

            $file = $_FILES['logo_file'];
            $rel_dir = brandingDir($school);
            $upload_dir = __DIR__ . '/' . $rel_dir;
            if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'Could not create ' . $rel_dir . ' — check folder permissions']);
                exit();
            }

            // Validate file
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if ($file['size'] > $max_size) {
                echo json_encode(['success' => false, 'message' => 'File size must be less than 2MB']);
                exit();
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, WEBP, and SVG allowed']);
                exit();
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed_extensions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file extension']);
                exit();
            }

            // Delete old logo file if it exists locally. prefix check stays — it is what stops a
            // crafted setting value turning this into an arbitrary unlink. two prefixes are legal:
            // this school's own folder, and the legacy flat one from before the split
            $old_logo = (string) getSetting('site_logo', '');
            $mine   = $old_logo !== '' && strpos($old_logo, $rel_dir) === 0;
            $legacy = $old_logo !== '' && strpos($old_logo, 'uploads/branding/') === 0 && !logoShared($old_logo, $school);
            if (($mine || $legacy) && is_file(__DIR__ . '/' . $old_logo)) {
                @unlink(__DIR__ . '/' . $old_logo);
            }

            // Generate unique filename
            $filename = 'site_logo_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $relative_path = $rel_dir . $filename;
                setSetting('site_logo', $relative_path);

                logActivity($user_id, $username, 'Logo Updated', 'Site logo uploaded');

                echo json_encode([
                    'success' => true,
                    'message' => 'Logo uploaded successfully',
                    'logo_path' => $relative_path
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
            }
            exit();
        } catch (Exception $e) {
            error_log('settings uploadSiteLogo: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error uploading logo. Please try again.']);
            exit();
        }
    }
}
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
    <title>System Settings - Dashboard System</title>

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
                    <h1><i class="fas fa-cog"></i> System Settings</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>System</span>
                        <span class="breadcrumb-sep">/</span>
                        <span>Site Settings</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card skeleton-card-mb">
                    <div class="skeleton skeleton-text-large skeleton-w-60 skeleton-mb-md"></div>
                    <div class="skeleton skeleton-text skeleton-w-80 skeleton-mb-sm"></div>
                    <div class="skeleton skeleton-text skeleton-w-70"></div>
                </div>
                <div class="dashboard-grid-4 skeleton-grid-mb">
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-60"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-60"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-60"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon skeleton-mb-md"></div>
                        <div class="skeleton skeleton-text skeleton-w-60"></div>
                    </div>
                </div>
            </div>

            <!-- Settings Content -->
            <div id="settingsContent" class="initially-hidden">
                <!-- Quick Actions -->
                <div class="quick-actions-bar">
                    <button class="btn btn-success" onclick="saveSettings()">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <button class="btn btn-secondary" onclick="loadSettings()">
                        <i class="fas fa-sync"></i> Reload
                    </button>
                </div>

                <!-- Site Branding Card (Full Width) -->
                <div class="settings-mega-card mb-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy">
                            <i class="fas fa-paint-brush"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">Site Branding</h3>
                            <p class="settings-card-subtitle">Customize login screen name, logo & copyright</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="form-grid form-grid-2col">
                            <div class="form-group">
                                <label><i class="fas fa-heading"></i> Site Name</label>
                                <input type="text" id="siteName" placeholder="e.g. Dashboard System" maxlength="100">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-copyright"></i> Copyright Text</label>
                                <input type="text" id="copyrightText" placeholder="e.g. &copy; 2026 My Company. All rights reserved." maxlength="200">
                            </div>
                            <?php if ($isPlat): ?>
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Currency</label>
                                <select id="currencyCode">
                                    <?php foreach (array_keys(ormsCurrencies()) as $ccode): ?>
                                    <option value="<?php echo $ccode; ?>"><?php echo htmlspecialchars(ormsCurrencyLabel($ccode), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text"><i class="fas fa-triangle-exclamation"></i> One currency for the whole platform &mdash; every school, every fee screen and every new invoice. Amounts are <b>relabelled, never converted</b>, so check <a href="plans.php">Plans</a> and your fee structures after changing it. Invoices and payments already issued keep the currency they were raised in.</div>
                            </div>
                            <?php else: ?>
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Currency</label>
                                <input type="text" value="<?php echo htmlspecialchars(ormsCurrencyLabel(ormsCurrency()['code']), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                <div class="help-text"><i class="fas fa-lock"></i> Set by the platform operator and shared by every school. Fees, invoices and result cards all print in it.</div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label><i class="fas fa-language"></i> Default Language</label>
                                <select id="defaultLanguage">
                                    <option value="en">English</option>
                                    <option value="af">Afrikaans</option>
                                    <option value="sq">Shqip (Albanian)</option>
                                    <option value="am">አማርኛ (Amharic)</option>
                                    <option value="ar">العربية (Arabic)</option>
                                    <option value="hy">Հայերեն (Armenian)</option>
                                    <option value="az">Azərbaycan (Azerbaijani)</option>
                                    <option value="eu">Euskara (Basque)</option>
                                    <option value="be">Беларуская (Belarusian)</option>
                                    <option value="bn">বাংলা (Bengali)</option>
                                    <option value="bs">Bosanski (Bosnian)</option>
                                    <option value="bg">Български (Bulgarian)</option>
                                    <option value="ca">Català (Catalan)</option>
                                    <option value="ceb">Cebuano</option>
                                    <option value="ny">Chichewa</option>
                                    <option value="zh-CN">中文简体 (Chinese Simplified)</option>
                                    <option value="zh-TW">中文繁體 (Chinese Traditional)</option>
                                    <option value="co">Corsu (Corsican)</option>
                                    <option value="hr">Hrvatski (Croatian)</option>
                                    <option value="cs">Čeština (Czech)</option>
                                    <option value="da">Dansk (Danish)</option>
                                    <option value="nl">Nederlands (Dutch)</option>
                                    <option value="eo">Esperanto</option>
                                    <option value="et">Eesti (Estonian)</option>
                                    <option value="tl">Filipino (Tagalog)</option>
                                    <option value="fi">Suomi (Finnish)</option>
                                    <option value="fr">Français (French)</option>
                                    <option value="fy">Frysk (Frisian)</option>
                                    <option value="gl">Galego (Galician)</option>
                                    <option value="ka">ქართული (Georgian)</option>
                                    <option value="de">Deutsch (German)</option>
                                    <option value="el">Ελληνικά (Greek)</option>
                                    <option value="gu">ગુજરાતી (Gujarati)</option>
                                    <option value="ht">Kreyòl Ayisyen (Haitian Creole)</option>
                                    <option value="ha">Hausa</option>
                                    <option value="haw">ʻŌlelo Hawaiʻi (Hawaiian)</option>
                                    <option value="he">עברית (Hebrew)</option>
                                    <option value="hi">हिन्दी (Hindi)</option>
                                    <option value="hmn">Hmong</option>
                                    <option value="hu">Magyar (Hungarian)</option>
                                    <option value="is">Íslenska (Icelandic)</option>
                                    <option value="ig">Igbo</option>
                                    <option value="id">Bahasa Indonesia (Indonesian)</option>
                                    <option value="ga">Gaeilge (Irish)</option>
                                    <option value="it">Italiano (Italian)</option>
                                    <option value="ja">日本語 (Japanese)</option>
                                    <option value="jv">Basa Jawa (Javanese)</option>
                                    <option value="kn">ಕನ್ನಡ (Kannada)</option>
                                    <option value="kk">Қазақ (Kazakh)</option>
                                    <option value="km">ខ្មែរ (Khmer)</option>
                                    <option value="rw">Kinyarwanda</option>
                                    <option value="ko">한국어 (Korean)</option>
                                    <option value="ku">Kurdî (Kurdish)</option>
                                    <option value="ky">Кыргызча (Kyrgyz)</option>
                                    <option value="lo">ລາວ (Lao)</option>
                                    <option value="la">Latina (Latin)</option>
                                    <option value="lv">Latviešu (Latvian)</option>
                                    <option value="lt">Lietuvių (Lithuanian)</option>
                                    <option value="lb">Lëtzebuergesch (Luxembourgish)</option>
                                    <option value="mk">Македонски (Macedonian)</option>
                                    <option value="mg">Malagasy</option>
                                    <option value="ms">Bahasa Melayu (Malay)</option>
                                    <option value="ml">മലയാളം (Malayalam)</option>
                                    <option value="mt">Malti (Maltese)</option>
                                    <option value="mi">Māori</option>
                                    <option value="mr">मराठी (Marathi)</option>
                                    <option value="mn">Монгол (Mongolian)</option>
                                    <option value="my">မြန်မာ (Myanmar/Burmese)</option>
                                    <option value="ne">नेपाली (Nepali)</option>
                                    <option value="no">Norsk (Norwegian)</option>
                                    <option value="or">ଓଡ଼ିଆ (Odia)</option>
                                    <option value="ps">پښتو (Pashto)</option>
                                    <option value="fa">فارسی (Persian)</option>
                                    <option value="pl">Polski (Polish)</option>
                                    <option value="pt">Português (Portuguese)</option>
                                    <option value="pa">ਪੰਜਾਬੀ (Punjabi)</option>
                                    <option value="ro">Română (Romanian)</option>
                                    <option value="ru">Русский (Russian)</option>
                                    <option value="sm">Gagana Samoa (Samoan)</option>
                                    <option value="gd">Gàidhlig (Scots Gaelic)</option>
                                    <option value="sr">Српски (Serbian)</option>
                                    <option value="st">Sesotho</option>
                                    <option value="sn">Shona</option>
                                    <option value="sd">سنڌي (Sindhi)</option>
                                    <option value="si">සිංහල (Sinhala)</option>
                                    <option value="sk">Slovenčina (Slovak)</option>
                                    <option value="sl">Slovenščina (Slovenian)</option>
                                    <option value="so">Soomaali (Somali)</option>
                                    <option value="es">Español (Spanish)</option>
                                    <option value="su">Basa Sunda (Sundanese)</option>
                                    <option value="sw">Kiswahili (Swahili)</option>
                                    <option value="sv">Svenska (Swedish)</option>
                                    <option value="tg">Тоҷикӣ (Tajik)</option>
                                    <option value="ta">தமிழ் (Tamil)</option>
                                    <option value="tt">Татар (Tatar)</option>
                                    <option value="te">తెలుగు (Telugu)</option>
                                    <option value="th">ไทย (Thai)</option>
                                    <option value="tr">Türkçe (Turkish)</option>
                                    <option value="tk">Türkmen (Turkmen)</option>
                                    <option value="uk">Українська (Ukrainian)</option>
                                    <option value="ur">اردو (Urdu)</option>
                                    <option value="ug">ئۇيغۇرچە (Uyghur)</option>
                                    <option value="uz">Oʻzbek (Uzbek)</option>
                                    <option value="vi">Tiếng Việt (Vietnamese)</option>
                                    <option value="cy">Cymraeg (Welsh)</option>
                                    <option value="xh">isiXhosa (Xhosa)</option>
                                    <option value="yi">ייִדיש (Yiddish)</option>
                                    <option value="yo">Yorùbá (Yoruba)</option>
                                    <option value="zu">isiZulu (Zulu)</option>
                                </select>
                                <button type="button" class="btn btn-secondary btn-sm mt-8" onclick="revertToEnglish()">
                                    <i class="fas fa-undo"></i> Revert to English
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-image"></i> Site Logo</label>
                            <div class="logo-upload-row">
                                <img id="logoPreview" src="" alt="Logo Preview" class="logo-preview-img initially-hidden">
                                <div class="logo-upload-controls">
                                    <div class="logo-upload-controls-inner">
                                        <label for="logoFileInput" class="btn btn-primary logo-upload-btn">
                                            <i class="fas fa-upload"></i> Choose Image
                                        </label>
                                        <input type="file" id="logoFileInput" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" class="initially-hidden">
                                        <span id="logoFileName" class="logo-file-name">No file chosen</span>
                                    </div>
                                    <p class="logo-help-text">Allowed: JPG, PNG, GIF, WEBP, SVG (Max 2MB)</p>
                                </div>
                            </div>
                        </div>
                        <div class="info-banner info-banner-top">
                            <i class="fas fa-info-circle"></i>
                            <span>These settings apply to the Login, Sign Up, and Setup pages</span>
                        </div>
                    </div>
                </div>

                <!-- SaaS Mode Card — operator only. Nothing else in the app can flip this. -->
                <?php if ($isPlat): ?>
                <div class="settings-mega-card mb-24">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-navy">
                            <i class="fas fa-tower-broadcast"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">SaaS Mode</h3>
                            <p class="settings-card-subtitle">Run this install as a platform of separate schools, or as one school</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon"><i class="fas fa-building-columns"></i></div>
                                <div class="control-info">
                                    <div class="control-title">Enable SaaS mode</div>
                                    <div class="control-desc">Turns on the parts that only make sense with paying tenants: plan limits, the subscription gate, self-serve checkout and the automatic expiry sweep. While it is off, every one of those is inert and the app behaves as a single school.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="platformMode" class="toggle-input-large">
                                    <label for="platformMode" class="toggle-label-large">
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                <div class="toggle-status" id="platformToggleStatus">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>
                        <div class="info-banner info-banner-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Switching it on starts enforcing every school's plan limits and its subscription end date immediately. Check <a href="subscriptions.php">Subscriptions</a> and <a href="plans.php">Plans</a> first &mdash; a school past its date is blocked the moment you save.</span>
                        </div>
                    </div>
                </div>

                <!-- Maintenance Mode Card (Full Width Warning) — operator only -->
                <div class="settings-mega-card mb-24 maintenance-card-warning">
                    <div class="settings-card-header">
                        <div class="settings-card-icon icon-gradient-danger">
                            <i class="fas fa-hard-hat"></i>
                        </div>
                        <div>
                            <h3 class="settings-card-title">Maintenance Mode</h3>
                            <p class="settings-card-subtitle">Take your site offline for maintenance</p>
                        </div>
                    </div>
                    <div class="settings-card-body">
                        <div class="control-group">
                            <div class="control-group-header">
                                <div class="control-icon">
                                    <i class="fas fa-power-off"></i>
                                </div>
                                <div class="control-info">
                                    <div class="control-title">Enable Maintenance Mode</div>
                                    <div class="control-desc">When enabled, only admins can access the site. All visitors see a maintenance page.</div>
                                </div>
                            </div>
                            <div class="control-toggle-wrapper">
                                <div class="toggle-switch-large">
                                    <input type="checkbox" id="maintenanceMode" class="toggle-input-large">
                                    <label for="maintenanceMode" class="toggle-label-large">
                                        <span class="toggle-slider-large"></span>
                                    </label>
                                </div>
                                <div class="toggle-status" id="maintenanceToggleStatus">
                                    <span class="status-dot status-disabled"></span>
                                    <span class="status-text">Disabled</span>
                                </div>
                            </div>
                        </div>
                        <div class="info-banner info-banner-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Login, Signup, Forgot Password, and Google OAuth will be blocked for all users. Only manual admin login will be available via a small icon on the maintenance page.</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 2x2 Grid Layout -->
                <div class="settings-grid-2x2">

                    <!-- Card 1: System Overview -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-navy">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">System Overview</h3>
                                <p class="settings-card-subtitle">Core system configuration</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-success">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value"><?php echo DB_NAME; ?></div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-accent">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Session Timeout</div>
                                    <div class="stat-item-value"><?php echo SESSION_TIMEOUT / 60; ?> minutes</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-warning">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Max Login Attempts</div>
                                    <div class="stat-item-value"><?php echo MAX_LOGIN_ATTEMPTS; ?> attempts</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon stat-icon-danger">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Lockout Duration</div>
                                    <div class="stat-item-value"><?php echo LOGIN_LOCKOUT_TIME / 60; ?> minutes</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: User Management -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-success">
                                <i class="fas fa-users-cog"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">User Management</h3>
                                <p class="settings-card-subtitle">Control user permissions</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="control-group">
                                <div class="control-group-header">
                                    <div class="control-icon">
                                        <i class="fas fa-image"></i>
                                    </div>
                                    <div class="control-info">
                                        <div class="control-title">Profile Image Uploads</div>
                                        <div class="control-desc">Allow users to upload profile pictures</div>
                                    </div>
                                </div>
                                <div class="control-toggle-wrapper">
                                    <div class="toggle-switch-large">
                                        <input type="checkbox" id="allowUserUploads" class="toggle-input-large">
                                        <label for="allowUserUploads" class="toggle-label-large">
                                            <span class="toggle-slider-large"></span>
                                        </label>
                                    </div>
                                    <div class="toggle-status" id="uploadToggleStatus">
                                        <span class="status-dot status-disabled"></span>
                                        <span class="status-text">Disabled</span>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner">
                                <i class="fas fa-info-circle"></i>
                                <span>When disabled, only administrators can manage all profile images</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3: Security Status -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-blue">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Security Status</h3>
                                <p class="settings-card-subtitle">Active security features</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Session Security</div>
                                    <div class="security-feature-desc">HTTPOnly cookies & Strict SameSite</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Password Encryption</div>
                                    <div class="security-feature-desc">Bcrypt (PASSWORD_DEFAULT)</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Rate Limiting</div>
                                    <div class="security-feature-desc">User & IP tracking enabled</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-code"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">CSRF Protection</div>
                                    <div class="security-feature-desc">Token-based validation</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 4: Server Information -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon icon-gradient-warning">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Server Information</h3>
                                <p class="settings-card-subtitle">Environment details</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-database"></i>
                                    <span>Database Host</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_HOST; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-user"></i>
                                    <span>Database User</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_USER; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-code"></i>
                                    <span>PHP Version</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="version-badge"><?php echo phpversion(); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-hdd"></i>
                                    <span>Max Upload</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="upload-badge"><?php echo ini_get('upload_max_filesize'); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-clock"></i>
                                    <span>Server Time</span>
                                </div>
                                <div class="server-info-value time-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                            </div>
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
        window.ORMS_CSRF = '<?= csrfToken() ?>';   // csrf for this page's $.ajax calls
        $(document).ajaxSend(function(e, x){ if (window.ORMS_CSRF) x.setRequestHeader('X-CSRF-Token', window.ORMS_CSRF); });
        $(document).ready(function() {
            // 150+ currencies — searchable or it's unusable. ORMS.dropdown is the project's
            // component (select2 was the odd one out here and pulled two extra CDNs)
            if (window.ORMS && ORMS.dropdown) {
                ORMS.dropdown('#defaultLanguage', { searchPlaceholder: 'Search language…' });
                ORMS.dropdown('#currencyCode',   { searchPlaceholder: 'Search currency, code or symbol…' });
            }
            loadSettings();
        });

        function loadSettings() {
            // Show skeleton, hide content
            $('#loadingSkeleton').show();
            $('#settingsContent').hide();

            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'getSettings' },
                dataType: 'json',
                success: function(response) {
                    // Hide skeleton, show content
                    setTimeout(() => {
                        $('#loadingSkeleton').hide();
                        $('#settingsContent').fadeIn(300);
                    }, 500);

                    if (response.success) {
                        const data = response.data;

                        // Set toggle states
                        const isEnabled = data.allow_user_profile_uploads === '1';
                        document.getElementById('allowUserUploads').checked = isEnabled;
                        updateToggleStatus(isEnabled);

                        // Load branding fields
                        document.getElementById('siteName').value = data.site_name || '';
                        document.getElementById('copyrightText').value = data.copyright_text || '';

                        // Set maintenance mode — card only rendered for the operator
                        const maintEl = document.getElementById('maintenanceMode');
                        if (maintEl) {
                            maintEl.checked = data.maintenance_mode === '1';
                            updateMaintenanceStatus(maintEl.checked);
                        }

                        // SaaS mode — same card rule: operator only, so guard the element
                        const platEl = document.getElementById('platformMode');
                        if (platEl) {
                            platEl.checked = data.platform_mode === '1';
                            updateDotStatus('platformToggleStatus', platEl.checked, 'Enabled', 'Disabled');
                        }

                        // Set currency
                        $('#currencyCode').val(data.currency_code || 'USD').trigger('change');

                        // Set default language
                        $('#defaultLanguage').val(data.default_language || 'en').trigger('change');

                        // values arrived after the dropdowns were built — re-read them
                        if (window.ORMS && ORMS.dropdown) {
                            ORMS.dropdown.refresh('#currencyCode');
                            ORMS.dropdown.refresh('#defaultLanguage');
                        }

                        // Show logo preview
                        updateLogoPreview(data.site_logo);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load settings'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('#loadingSkeleton').hide();
                    $('#settingsContent').show();

                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function saveSettings() {
            const allowUserUploads = document.getElementById('allowUserUploads').checked ? '1' : '0';
            const siteName = document.getElementById('siteName').value.trim();
            const copyrightText = document.getElementById('copyrightText').value.trim();

            Swal.fire({
                title: 'Saving Settings...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'saveSettings',
                    allow_user_profile_uploads: allowUserUploads,
                    site_name: siteName,
                    copyright_text: copyrightText,
                    currency_code: currencyValue(),
                    default_language: document.getElementById('defaultLanguage').value,
                    maintenance_mode: maintenanceValue(),
                    platform_mode: platformValue()
                },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        // Update Google Translate cookie for language change
                        localStorage.removeItem('lang_reverted_to_english');
                        var newLang = document.getElementById('defaultLanguage').value;
                        if (newLang === 'en') {
                            document.cookie = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
                            document.cookie = 'googtrans=;path=/;domain=' + window.location.hostname + ';expires=Thu, 01 Jan 1970 00:00:00 GMT';
                        } else {
                            document.cookie = 'googtrans=/en/' + newLang + ';path=/';
                            document.cookie = 'googtrans=/en/' + newLang + ';path=/;domain=' + window.location.hostname;
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(function() {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to save settings. Please try again.'
                    });
                }
            });
        }

        function revertToEnglish() {
            $('#defaultLanguage').val('en').trigger('change');
            // Save English as default language
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'saveSettings', allow_user_profile_uploads: document.getElementById('allowUserUploads').checked ? '1' : '0', site_name: document.getElementById('siteName').value.trim(), copyright_text: document.getElementById('copyrightText').value.trim(), currency_code: currencyValue(), default_language: 'en', maintenance_mode: maintenanceValue(), platform_mode: platformValue() },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        document.cookie = 'googtrans=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT';
                        document.cookie = 'googtrans=;path=/;domain=' + window.location.hostname + ';expires=Thu, 01 Jan 1970 00:00:00 GMT';
                        localStorage.setItem('lang_reverted_to_english', 'true');
                        Swal.fire({ icon: 'success', title: 'Reverted!', text: 'Language set to English', timer: 1500, showConfirmButton: false }).then(function() { location.reload(); });
                    }
                }
            });
        }

        function updateToggleStatus(isEnabled) {
            const statusElement = document.getElementById('uploadToggleStatus');
            const statusDot = statusElement.querySelector('.status-dot');
            const statusText = statusElement.querySelector('.status-text');

            if (isEnabled) {
                statusDot.classList.remove('status-disabled');
                statusDot.classList.add('status-enabled');
                statusText.textContent = 'Enabled';
                statusText.className = 'status-text text-success';
            } else {
                statusDot.classList.remove('status-enabled');
                statusDot.classList.add('status-disabled');
                statusText.textContent = 'Disabled';
                statusText.className = 'status-text text-muted';
            }
        }

        // card is operator-only — a tenant posts nothing, the server ignores the field anyway
        function currencyValue() {
            const el = document.getElementById('currencyCode');   // operator only
            return el ? el.value : '';
        }

        function maintenanceValue() {
            const el = document.getElementById('maintenanceMode');
            return el && el.checked ? '1' : '0';
        }

        function platformValue() {
            const el = document.getElementById('platformMode');
            return el && el.checked ? '1' : '0';
        }

        // one dot/label painter for any of these toggles — the maintenance one keeps its own
        // wording because "enabled" there is a warning, not a state
        function updateDotStatus(id, on, onText, offText) {
            const box = document.getElementById(id);
            if (!box) return;
            const dot = box.querySelector('.status-dot'), txt = box.querySelector('.status-text');
            dot.classList.toggle('status-enabled', on);
            dot.classList.toggle('status-disabled', !on);
            txt.textContent = on ? onText : offText;
            txt.className = 'status-text ' + (on ? 'text-success' : 'text-muted');
        }

        function updateMaintenanceStatus(isEnabled) {
            const statusElement = document.getElementById('maintenanceToggleStatus');
            if (!statusElement) return;
            const statusDot = statusElement.querySelector('.status-dot');
            const statusText = statusElement.querySelector('.status-text');

            if (isEnabled) {
                statusDot.classList.remove('status-disabled');
                statusDot.classList.add('status-enabled');
                statusText.textContent = 'Enabled';
                statusText.className = 'status-text text-danger';
            } else {
                statusDot.classList.remove('status-enabled');
                statusDot.classList.add('status-disabled');
                statusText.textContent = 'Disabled';
                statusText.className = 'status-text text-muted';
            }
        }

        // Add event listener to toggles
        $(document).on('change', '#allowUserUploads', function() {
            updateToggleStatus(this.checked);
        });

        $(document).on('change', '#maintenanceMode', function() {
            updateMaintenanceStatus(this.checked);
        });

        $(document).on('change', '#platformMode', function() {
            updateDotStatus('platformToggleStatus', this.checked, 'Enabled', 'Disabled');
        });

        // Logo preview
        function updateLogoPreview(url) {
            const preview = document.getElementById('logoPreview');
            if (url && url.trim() !== '') {
                preview.src = url;
                preview.style.display = 'block';
                preview.onerror = function() { this.style.display = 'none'; };
            } else {
                preview.style.display = 'none';
            }
        }

        // File input change - preview and auto-upload
        $(document).on('change', '#logoFileInput', function() {
            const file = this.files[0];
            if (!file) return;

            // Show file name
            document.getElementById('logoFileName').textContent = file.name;

            // Local preview
            const reader = new FileReader();
            reader.onload = function(e) {
                updateLogoPreview(e.target.result);
            };
            reader.readAsDataURL(file);

            // Upload immediately
            const formData = new FormData();
            formData.append('action', 'uploadSiteLogo');
            formData.append('logo_file', file);

            Swal.fire({
                title: 'Uploading Logo...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        updateLogoPreview(response.logo_path);
                        Swal.fire({
                            icon: 'success',
                            title: 'Logo Updated!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Upload Failed',
                            text: response.message
                        });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to upload logo. Please try again.'
                    });
                }
            });
        });
    </script>
</body>
</html>
