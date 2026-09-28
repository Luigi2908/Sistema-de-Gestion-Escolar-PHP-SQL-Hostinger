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
$current_page = 'account';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getAccountInfo':
                $stmt = $conn->prepare("SELECT id, username, email, role, profile_image, created_at FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'profile_image' => $user['profile_image'],
                            'created_at' => date('M d, Y H:i:s', strtotime($user['created_at'])),
                            'allow_user_uploads' => $allowUserUploads
                        ]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }

                $stmt->close();
                exit();

            case 'updateProfile':
                requireCsrfJson();
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newUsername = isset($_POST['username']) ? trim($_POST['username']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';

                // Validate inputs
                if (empty($newUsername) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                $newUsername = validateUsername($newUsername);
                if ($newUsername === false) {
                    echo json_encode(['success' => false, 'message' => 'Invalid username format. Use 3-50 letters, digits, _ . or -']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // Check if username is already taken by another user — scope to THIS school. The unique
                // key is (school_id, username), so an unscoped check falsely blocks a rename another school
                // already uses and confirms an account exists in a school the caller cannot see.
                $u_scoped = function_exists('ormsPlatformMode') && ormsPlatformMode() && ormsHasTenancy();
                if ($u_scoped) {
                    $sidv = sid();
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? AND school_id = ?");
                    $stmt->bind_param("sii", $newUsername, $user_id, $sidv);
                } else {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $stmt->bind_param("si", $newUsername, $user_id);
                }
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Username already taken']);
                    exit();
                }
                $stmt->close();

                // Update profile
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $newUsername, $email, $user_id);

                if ($stmt->execute()) {
                    // Update session username if changed
                    $_SESSION['username'] = $newUsername;

                    // Log activity
                    logActivity($user_id, $newUsername, 'Profile Updated', 'Updated username and email');

                    $stmt->close();

                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'new_username' => $newUsername]);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
                }
                exit();

            case 'changePassword':
                requireCsrfJson();
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
                $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
                $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                // Validate inputs
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit();
                }

                $newPassword = validatePassword($newPassword);
                if ($newPassword === false) {
                    echo json_encode(['success' => false, 'message' => 'Password must be 6-255 characters']);
                    exit();
                }

                // Verify current password
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    if (!password_verify($currentPassword, $user['password'])) {
                        $stmt->close();
                        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                        exit();
                    }
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                $stmt->close();

                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $user_id);

                if ($stmt->execute()) {
                    // Log activity
                    logActivity($user_id, $username, 'Password Changed', 'User changed their password');

                    // Notify user
                    try { createNotification($user_id, 'Password Changed', 'Your password was changed successfully. If this wasn\'t you, contact an administrator immediately.', 'warning', 'account.php'); } catch (Exception $e) {}

                    $stmt->close();

                    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
                } else {
                    $stmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to change password']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Account.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
        exit();
    }
}

// Handle profile image upload (separate from AJAX JSON responses)
if (isset($_POST['action']) && $_POST['action'] === 'uploadProfileImage') {
    header('Content-Type: application/json');
    requireCsrfJson();

    try {
        // Check if user uploads are allowed
        $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';
        if (!$allowUserUploads && $role !== 'Admin') {
            echo json_encode(['success' => false, 'message' => 'Profile uploads are currently disabled']);
            exit();
        }

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }

        // Upload the file
        $uploadResult = uploadProfileImage($_FILES['profile_image'], $user_id);

        if (!$uploadResult['success']) {
            echo json_encode($uploadResult);
            exit();
        }

        // Get old profile image
        $oldImage = getProfileImage($user_id);

        // Update database
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
        $stmt->bind_param("si", $uploadResult['filename'], $user_id);

        if ($stmt->execute()) {
            // Delete old image if exists
            if ($oldImage) {
                deleteProfileImage($oldImage);
            }

            // Log activity
            logActivity($user_id, $username, 'Profile Image Updated', 'User uploaded a new profile image');

            $stmt->close();

            echo json_encode([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'image_url' => $uploadResult['filename']
            ]);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Failed to update profile image']);
        }
    } catch (Exception $e) {
        error_log("Profile image upload error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
    }
    exit();
}

// If we reach here, render the HTML page
// key => label/colour for the js badge — role paints itself from the roles table
$roleMeta = [];
foreach (readRoles() as $r) $roleMeta[$r['key']] = ['label' => $r['label'], 'color' => $r['color']];
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
    <title>My Account - Dashboard System</title>

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
                    <h1><i class="fas fa-user-circle"></i> My Account</h1>
                    <nav class="header-crumb">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <span class="breadcrumb-sep">/</span>
                        <span>My Account</span>
                        <span class="breadcrumb-sep">/</span>
                        <span>Profile</span>
                    </nav>
                </div>
                <?php include 'notifications_bell.php'; ?>
            </div>

            <!-- Profile Image Section -->
            <div class="data-section mb-30">
                <div class="section-header">
                    <h2><i class="fas fa-image"></i> Profile Image</h2>
                </div>

                <div class="profile-section-grid">
                    <!-- Current Profile Image Display -->
                    <div class="profile-image-display">
                        <div class="profile-image-container">
                            <img id="currentProfileImage" src="" alt="Profile" class="profile-img-cover initially-hidden">
                            <i id="defaultProfileIcon" class="fas fa-user initially-hidden"></i>
                        </div>
                        <div class="profile-image-label">Current Image</div>
                    </div>

                    <!-- Upload Form -->
                    <div class="profile-upload-form">
                        <form id="profileImageForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label><i class="fas fa-upload"></i> Upload New Profile Image</label>
                                <input type="file" id="profileImageInput" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="file-input-styled">
                                <div class="help-text">
                                    <i class="fas fa-info-circle"></i> Accepted: JPG, PNG, GIF, WEBP (Max 2MB)
                                </div>
                            </div>

                            <div class="form-actions initially-hidden" id="uploadSection">
                                <button type="submit" class="btn btn-primary" id="uploadBtn">
                                    <i class="fas fa-upload"></i> Upload Image
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="cancelUpload()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>

                        <div id="uploadDisabledMessage" class="warning-message initially-hidden">
                            <i class="fas fa-exclamation-triangle"></i> Profile image uploads are currently disabled by administrator.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Account Information Card -->
            <div class="account-info-card">
                <h3><i class="fas fa-info-circle"></i> Account Information</h3>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-user"></i> Username:</div>
                    <div class="info-value" id="display-username">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-envelope"></i> Email:</div>
                    <div class="info-value" id="display-email">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-user-tag"></i> Role:</div>
                    <div class="info-value" id="display-role">Loading...</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="fas fa-calendar-alt"></i> Member Since:</div>
                    <div class="info-value" id="display-created">Loading...</div>
                </div>
            </div>

            <!-- Update Profile Section -->
            <div class="data-section mb-30">
                <div class="section-header">
                    <h2><i class="fas fa-edit"></i> Update Profile</h2>
                </div>

                <form id="profileForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Username *</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Section -->
            <div class="data-section mb-30">
                <div class="section-header">
                    <h2><i class="fas fa-lock"></i> Change Password</h2>
                </div>

                <form id="passwordForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Current Password *</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> New Password *</label>
                            <input type="password" id="new_password" name="new_password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5"></script>
    <script src="orms.js?v=2.5"></script>

    <script>
        window.ORMS_CSRF = '<?= csrfToken() ?>';   // csrf for this page's $.ajax calls
        $(document).ajaxSend(function(e, x){ if (window.ORMS_CSRF) x.setRequestHeader('X-CSRF-Token', window.ORMS_CSRF); });
        let allowUserUploads = true;

        // roles straight from the roles table — label + colour per key
        const ROLE_META = <?php echo json_encode($roleMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // stored role colour -> swatch, luminance picks readable text. hex validated, never echoed raw
        function roleStyle(hex) {
            var h = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(hex || '')) ? String(hex) : '#0074D9';
            var c = h.length === 4 ? h[1] + h[1] + h[2] + h[2] + h[3] + h[3] : h.slice(1);
            var lum = parseInt(c.slice(0, 2), 16) * .299 + parseInt(c.slice(2, 4), 16) * .587 + parseInt(c.slice(4, 6), 16) * .114;
            return 'background:' + h + ';color:' + (lum > 160 ? '#001f3f' : '#fff');
        }

        $(document).ready(function() {
            loadAccountInfo();
        });

        function loadAccountInfo() {
            $.ajax({
                url: '?action=getAccountInfo',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;

                        // Display account info
                        document.getElementById('display-username').textContent = data.username;
                        document.getElementById('display-email').textContent = data.email;
                        const rm = ROLE_META[data.role];
                        document.getElementById('display-role').innerHTML =
                            '<span class="role-badge" style="' + roleStyle(rm && rm.color) + '">' +
                            $('<span>').text(rm ? rm.label : (data.role || '—')).html() + '</span>';
                        document.getElementById('display-created').textContent = data.created_at;

                        // Populate form
                        document.getElementById('username').value = data.username;
                        document.getElementById('email').value = data.email;

                        // Handle profile image
                        allowUserUploads = data.allow_user_uploads;
                        if (data.profile_image) {
                            document.getElementById('currentProfileImage').src = data.profile_image;
                            document.getElementById('currentProfileImage').style.display = 'block';
                            document.getElementById('defaultProfileIcon').style.display = 'none';
                        } else {
                            document.getElementById('currentProfileImage').style.display = 'none';
                            document.getElementById('defaultProfileIcon').style.display = 'block';
                        }

                        // Check if uploads are allowed
                        if (!allowUserUploads && data.role !== 'Admin') {
                            document.getElementById('profileImageInput').disabled = true;
                            document.getElementById('uploadDisabledMessage').style.display = 'block';
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load account info'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not load account information'
                    });
                }
            });
        }

        // Profile image file selection
        document.getElementById('profileImageInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];

                // Validate file size
                if (file.size > 2 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'Profile image must be less than 2MB'
                    });
                    this.value = '';
                    return;
                }

                // Show upload button
                document.getElementById('uploadSection').style.display = 'flex';

                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('currentProfileImage').src = e.target.result;
                    document.getElementById('currentProfileImage').style.display = 'block';
                    document.getElementById('defaultProfileIcon').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });

        // Cancel upload
        function cancelUpload() {
            document.getElementById('profileImageInput').value = '';
            document.getElementById('uploadSection').style.display = 'none';
            loadAccountInfo(); // Reload to show original image
        }

        // Profile image upload form
        document.getElementById('profileImageForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            const fileInput = document.getElementById('profileImageInput');

            if (!fileInput.files || !fileInput.files[0]) {
                Swal.fire({
                    icon: 'error',
                    title: 'No File Selected',
                    text: 'Please select an image to upload'
                });
                return;
            }

            formData.append('profile_image', fileInput.files[0]);
            formData.append('action', 'uploadProfileImage');

            Swal.fire({
                title: 'Uploading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Reset form and hide upload buttons
                        document.getElementById('profileImageInput').value = '';
                        document.getElementById('uploadSection').style.display = 'none';

                        // Reload account info to show new image
                        setTimeout(() => loadAccountInfo(), 500);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Upload Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Upload Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Profile Update Form
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            Swal.fire({
                title: 'Updating Profile...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=updateProfile',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Update displayed username if it changed
                        if (response.new_username) {
                            document.getElementById('display-username').textContent = response.new_username;
                        }

                        setTimeout(() => loadAccountInfo(), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Password Change Form
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'New passwords do not match'
                });
                return;
            }

            const formData = new FormData(this);

            Swal.fire({
                title: 'Changing Password...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=changePassword',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Clear password form
                        document.getElementById('passwordForm').reset();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });
    </script>
</body>
</html>
