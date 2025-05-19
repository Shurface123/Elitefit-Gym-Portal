<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
$conn = connectDB();

// Include theme preference helper
require_once 'dashboard-theme-fix.php';
$theme = getThemePreference($conn, $userId);

// Get trainer settings
$settings = [
    'theme_preference' => $theme,
    'notification_email' => 1,
    'notification_sms' => 0,
    'auto_confirm_appointments' => 0,
    'availability_monday' => '09:00-17:00',
    'availability_tuesday' => '09:00-17:00',
    'availability_wednesday' => '09:00-17:00',
    'availability_thursday' => '09:00-17:00',
    'availability_friday' => '09:00-17:00',
    'availability_saturday' => '09:00-13:00',
    'availability_sunday' => ''
];

try {
    // Check if trainer_settings table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

    if (!$tableExists) {
        // Create trainer_settings table if it doesn't exist
        $conn->exec("
            CREATE TABLE trainer_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                theme_preference VARCHAR(20) DEFAULT 'dark',
                notification_email TINYINT(1) DEFAULT 1,
                notification_sms TINYINT(1) DEFAULT 0,
                auto_confirm_appointments TINYINT(1) DEFAULT 0,
                availability_monday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_tuesday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_wednesday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_thursday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_friday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_saturday VARCHAR(20) DEFAULT '09:00-13:00',
                availability_sunday VARCHAR(20) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id)
            )
        ");
        
        // Insert default settings for this user
        $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id) VALUES (?)");
        $stmt->execute([$userId]);
    }

    // Get settings from database
    $stmt = $conn->prepare("SELECT * FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dbSettings) {
        // Update settings with values from database
        foreach ($settings as $key => $value) {
            if (isset($dbSettings[$key])) {
                $settings[$key] = $dbSettings[$key];
            }
        }
    }
} catch (PDOException $e) {
    // Handle error - default settings already set
    // You might want to log this error for debugging
    // error_log('Settings error: ' . $e->getMessage());
}

// Get user profile data
$profile = [
    'name' => $userName,
    'email' => $_SESSION['email'] ?? '',
    'phone' => '',
    'bio' => '',
    'specialties' => '',
    'certifications' => '',
    'profile_image' => $_SESSION['profile_image'] ?? ''
];

try {
    // Get profile data from users table
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        // Update profile with values from database
        $profile['name'] = $userData['name'];
        $profile['email'] = $userData['email'];
        $profile['phone'] = $userData['phone'] ?? '';
        $profile['profile_image'] = $userData['profile_image'] ?? '';
        
        // Check if trainer_profiles table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_profiles'")->rowCount() > 0;
        
        if ($tableExists) {
            // Get additional profile data
            $stmt = $conn->prepare("SELECT * FROM trainer_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $trainerProfile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($trainerProfile) {
                $profile['bio'] = $trainerProfile['bio'] ?? '';
                $profile['specialties'] = $trainerProfile['specialties'] ?? '';
                $profile['certifications'] = $trainerProfile['certifications'] ?? '';
            }
        } else {
            // Create trainer_profiles table if it doesn't exist
            $conn->exec("
                CREATE TABLE trainer_profiles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    bio TEXT,
                    specialties TEXT,
                    certifications TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (user_id)
                )
            ");
        }
    }
} catch (PDOException $e) {
    // Handle error - default profile already set
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        // Update settings
        try {
            $themePreference = $_POST['theme_preference'] ?? 'dark';
            $notificationEmail = isset($_POST['notification_email']) ? 1 : 0;
            $notificationSms = isset($_POST['notification_sms']) ? 1 : 0;
            $autoConfirmAppointments = isset($_POST['auto_confirm_appointments']) ? 1 : 0;
            
            // Get availability values
            $availabilityDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $availability = [];
            
            foreach ($availabilityDays as $day) {
                $startTime = $_POST["availability_{$day}_start"] ?? '';
                $endTime = $_POST["availability_{$day}_end"] ?? '';
                
                if (!empty($startTime) && !empty($endTime)) {
                    $availability[$day] = "$startTime-$endTime";
                } else {
                    $availability[$day] = '';
                }
            }
            
            // Update settings in database
            $stmt = $conn->prepare("
                UPDATE trainer_settings 
                SET theme_preference = ?, 
                    notification_email = ?, 
                    notification_sms = ?, 
                    auto_confirm_appointments = ?,
                    availability_monday = ?,
                    availability_tuesday = ?,
                    availability_wednesday = ?,
                    availability_thursday = ?,
                    availability_friday = ?,
                    availability_saturday = ?,
                    availability_sunday = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $themePreference,
                $notificationEmail,
                $notificationSms,
                $autoConfirmAppointments,
                $availability['monday'],
                $availability['tuesday'],
                $availability['wednesday'],
                $availability['thursday'],
                $availability['friday'],
                $availability['saturday'],
                $availability['sunday'],
                $userId
            ]);
            
            // Update settings in memory
            $settings['theme_preference'] = $themePreference;
            $settings['notification_email'] = $notificationEmail;
            $settings['notification_sms'] = $notificationSms;
            $settings['auto_confirm_appointments'] = $autoConfirmAppointments;
            
            foreach ($availabilityDays as $day) {
                $settings["availability_$day"] = $availability[$day];
            }
            
            $message = 'Settings updated successfully!';
            $messageType = 'success';
            
            // Update theme in session
            $theme = $themePreference;
        } catch (PDOException $e) {
            $message = 'Error updating settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_profile'])) {
        // Update profile
        try {
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $bio = $_POST['bio'] ?? '';
            $specialties = $_POST['specialties'] ?? '';
            $certifications = $_POST['certifications'] ?? '';
            
            // Update user data
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, email = ?, phone = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$name, $email, $phone, $userId]);
            
            // Check if trainer_profiles table exists and has a record for this user
            $stmt = $conn->prepare("SELECT id FROM trainer_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $profileExists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($profileExists) {
                // Update existing profile
                $stmt = $conn->prepare("
                    UPDATE trainer_profiles 
                    SET bio = ?, specialties = ?, certifications = ?
                    WHERE user_id = ?
                ");
                
                $stmt->execute([$bio, $specialties, $certifications, $userId]);
            } else {
                // Insert new profile
                $stmt = $conn->prepare("
                    INSERT INTO trainer_profiles (user_id, bio, specialties, certifications)
                    VALUES (?, ?, ?, ?)
                ");
                
                $stmt->execute([$userId, $bio, $specialties, $certifications]);
            }
            
            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/profile_images/';
                
                // Create directory if it doesn't exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // Generate unique filename
                $fileExtension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $newFilename = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
                $uploadFile = $uploadDir . $newFilename;
                
                // Move uploaded file
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadFile)) {
                    // Update profile image in database
                    $imageUrl = '/uploads/profile_images/' . $newFilename;
                    
                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->execute([$imageUrl, $userId]);
                    
                    // Update profile in memory
                    $profile['profile_image'] = $imageUrl;
                    
                    // Update session
                    $_SESSION['profile_image'] = $imageUrl;
                }
            }
            
            // Update profile in memory
            $profile['name'] = $name;
            $profile['email'] = $email;
            $profile['phone'] = $phone;
            $profile['bio'] = $bio;
            $profile['specialties'] = $specialties;
            $profile['certifications'] = $certifications;
            
            // Update session
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;
            
            $message = 'Profile updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating profile: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_password'])) {
        // Update password
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Validate passwords
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $message = 'All password fields are required.';
                $messageType = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $message = 'New passwords do not match.';
                $messageType = 'error';
            } else {
                // Get current password hash
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($currentPassword, $user['password'])) {
                    // Update password
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$passwordHash, $userId]);
                    
                    $message = 'Password updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Current password is incorrect.';
                    $messageType = 'error';
                }
            }
        } catch (PDOException $e) {
            $message = 'Error updating password: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Parse availability times
$availabilityTimes = [];
foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
    $availabilityTimes[$day] = [
        'start' => '',
        'end' => ''
    ];
    
    $timeRange = $settings["availability_$day"] ?? '';
    if (!empty($timeRange) && strpos($timeRange, '-') !== false) {
        list($start, $end) = explode('-', $timeRange);
        $availabilityTimes[$day]['start'] = $start;
        $availabilityTimes[$day]['end'] = $end;
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/trainer-dashboard.css">
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Main</div>
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                    <li><a href="my-profile.php"><i class="fas fa-user"></i> <span>My Profile</span></a></li>
                    <li><a href="members.php"><i class="fas fa-users"></i> <span>Members</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Training</div>
                <ul class="sidebar-menu">
                    <li><a href="workout-plans.php"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
                    <li><a href="schedule.php"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
                    <li><a href="progress-tracking.php"><i class="fas fa-chart-line"></i> <span>Progress Tracking</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Account</div>
                <ul class="sidebar-menu">
                    <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Settings</h1>
                    <p>Manage your account settings and preferences</p>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Settings Tabs -->
            <div class="card">
                <div class="card-content">
                    <div class="tabs">
                        <div class="tab-buttons">
                            <button class="tab-btn active" data-tab="general">
                                <i class="fas fa-sliders-h"></i> General
                            </button>
                            <button class="tab-btn" data-tab="profile">
                                <i class="fas fa-user-circle"></i> Profile
                            </button>
                            <button class="tab-btn" data-tab="password">
                                <i class="fas fa-lock"></i> Password
                            </button>
                            <button class="tab-btn" data-tab="availability">
                                <i class="fas fa-calendar-alt"></i> Availability
                            </button>
                        </div>
                        
                        <!-- General Settings Tab -->
                        <div class="tab-content active" id="general-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <h3>Theme Preferences</h3>
                                <div class="theme-options">
                                    <div class="theme-option <?php echo $settings['theme_preference'] === 'light' ? 'active' : ''; ?>" data-theme="light">
                                        <div class="theme-preview light-theme">
                                            <div class="preview-header"></div>
                                            <div class="preview-sidebar"></div>
                                            <div class="preview-content"></div>
                                        </div>
                                        <div class="theme-label">
                                            <input type="radio" name="theme_preference" value="light" <?php echo $settings['theme_preference'] === 'light' ? 'checked' : ''; ?>>
                                            Light
                                        </div>
                                    </div>
                                    
                                    <div class="theme-option <?php echo $settings['theme_preference'] === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                                        <div class="theme-preview dark-theme">
                                            <div class="preview-header"></div>
                                            <div class="preview-sidebar"></div>
                                            <div class="preview-content"></div>
                                        </div>
                                        <div class="theme-label">
                                            <input type="radio" name="theme_preference" value="dark" <?php echo $settings['theme_preference'] === 'dark' ? 'checked' : ''; ?>>
                                            Dark
                                        </div>
                                    </div>
                                    
                                    <div class="theme-option <?php echo $settings['theme_preference'] === 'orange' ? 'active' : ''; ?>" data-theme="orange">
                                        <div class="theme-preview orange-theme">
                                            <div class="preview-header"></div>
                                            <div class="preview-sidebar"></div>
                                            <div class="preview-content"></div>
                                        </div>
                                        <div class="theme-label">
                                            <input type="radio" name="theme_preference" value="orange" <?php echo $settings['theme_preference'] === 'orange' ? 'checked' : ''; ?>>
                                            Orange
                                        </div>
                                    </div>
                                </div>
                                
                                <h3>Notification Settings</h3>
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="notification_email" name="notification_email" <?php echo $settings['notification_email'] ? 'checked' : ''; ?>>
                                        <label for="notification_email">Email Notifications</label>
                                    </div>
                                    <div class="form-text">Receive notifications about new appointments, cancellations, and updates via email.</div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="notification_sms" name="notification_sms" <?php echo $settings['notification_sms'] ? 'checked' : ''; ?>>
                                        <label for="notification_sms">SMS Notifications</label>
                                    </div>
                                    <div class="form-text">Receive notifications about new appointments, cancellations, and updates via SMS.</div>
                                </div>
                                
                                <h3>Appointment Settings</h3>
                                <div class="form-group">
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="auto_confirm_appointments" name="auto_confirm_appointments" <?php echo $settings['auto_confirm_appointments'] ? 'checked' : ''; ?>>
                                        <label for="auto_confirm_appointments">Auto-confirm Appointments</label>
                                    </div>
                                    <div class="form-text">Automatically confirm new appointments without manual approval.</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Save Settings</button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Profile Tab -->
                        <div class="tab-content" id="profile-tab">
                            <form action="settings.php" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <div class="profile-image-upload">
                                    <div class="current-image">
                                        <?php if (!empty($profile['profile_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($profile['profile_image']); ?>" alt="Profile Image">
                                        <?php else: ?>
                                            <div class="profile-placeholder">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="upload-controls">
                                        <label for="profile_image" class="btn btn-outline">
                                            <i class="fas fa-upload"></i> Upload New Image
                                        </label>
                                        <input type="file" id="profile_image" name="profile_image" accept="image/*" style="display: none;">
                                        <div class="form-text">Recommended size: 300x300 pixels. Max file size: 2MB.</div>
                                    </div>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="name">Full Name</label>
                                        <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($profile['name']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="phone">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($profile['phone']); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="bio">Bio</label>
                                    <textarea id="bio" name="bio" class="form-control" rows="4"><?php echo htmlspecialchars($profile['bio']); ?></textarea>
                                    <div class="form-text">Tell your clients about yourself, your experience, and your training philosophy.</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="specialties">Specialties</label>
                                    <textarea id="specialties" name="specialties" class="form-control" rows="3"><?php echo htmlspecialchars($profile['specialties']); ?></textarea>
                                    <div class="form-text">List your training specialties (e.g., Weight Loss, Muscle Building, Sports Performance).</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="certifications">Certifications</label>
                                    <textarea id="certifications" name="certifications" class="form-control" rows="3"><?php echo htmlspecialchars($profile['certifications']); ?></textarea>
                                    <div class="form-text">List your professional certifications and qualifications.</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Save Profile</button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Password Tab -->
                        <div class="tab-content" id="password-tab">
                            <form action="settings.php" method="post" data-validate>
                                <input type="hidden" name="update_password" value="1">
                                
                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8">
                                    <div class="form-text">Password must be at least 8 characters long.</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                                </div>
                                
                                <div class="password-strength">
                                    <div class="password-strength-label">Password Strength:</div>
                                    <div class="password-strength-meter">
                                        <div id="passwordStrengthMeter" class="strength-meter-bar"></div>
                                    </div>
                                    <div id="passwordStrengthText" class="password-strength-text">Too weak</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Update Password</button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Availability Tab -->
                        <div class="tab-content" id="availability-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <p>Set your regular working hours for each day of the week. Leave both fields empty if you're not available on a particular day.</p>
                                
                                <div class="availability-grid">
                                    <?php 
                                    $days = [
                                        'monday' => 'Monday',
                                        'tuesday' => 'Tuesday',
                                        'wednesday' => 'Wednesday',
                                        'thursday' => 'Thursday',
                                        'friday' => 'Friday',
                                        'saturday' => 'Saturday',
                                        'sunday' => 'Sunday'
                                    ];
                                    
                                    foreach ($days as $dayKey => $dayName): 
                                    ?>
                                        <div class="availability-day">
                                            <div class="day-label"><?php echo $dayName; ?></div>
                                            <div class="time-inputs">
                                                <div class="time-input">
                                                    <label for="availability_<?php echo $dayKey; ?>_start">From</label>
                                                    <input type="time" id="availability_<?php echo $dayKey; ?>_start" name="availability_<?php echo $dayKey; ?>_start" class="form-control" value="<?php echo $availabilityTimes[$dayKey]['start']; ?>">
                                                </div>
                                                <div class="time-input">
                                                    <label for="availability_<?php echo $dayKey; ?>_end">To</label>
                                                    <input type="time" id="availability_<?php echo $dayKey; ?>_end" name="availability_<?php echo $dayKey; ?>_end" class="form-control" value="<?php echo $availabilityTimes[$dayKey]['end']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Save Availability</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/trainer-dashboard.js"></script>
    <script>
        // File input preview
        document.getElementById('profile_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    
                    const currentImage = document.querySelector('.current-image');
                    currentImage.innerHTML = '';
                    currentImage.appendChild(img);
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Tab navigation
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', function() {
                // Update active tab button
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Show the selected tab content
                const tabId = this.getAttribute('data-tab');
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // Theme options
        document.querySelectorAll('.theme-option').forEach(option => {
            option.addEventListener('click', function() {
                // Update radio button
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Update active class
                document.querySelectorAll('.theme-option').forEach(opt => {
                    opt.classList.remove('active');
                });
                this.classList.add('active');
                
                // Apply theme immediately for preview
                const theme = this.getAttribute('data-theme');
                document.documentElement.setAttribute('data-theme', theme);
            });
        });
    </script>
</body>
</html>
