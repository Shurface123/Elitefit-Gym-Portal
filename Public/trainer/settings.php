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

// Initialize default settings to prevent undefined variable errors
$settings = [
    'theme_preference' => 'dark',
    'notification_email' => 1,
    'notification_sms' => 0,
    'notification_push' => 1,
    'auto_confirm_appointments' => 0,
    'default_session_duration' => 60,
    'working_hours_start' => '06:00:00',
    'working_hours_end' => '22:00:00',
    'break_duration' => 15,
    'max_daily_sessions' => 12,
    'booking_advance_days' => 30,
    'cancellation_hours' => 24,
    'timezone' => 'UTC',
    'currency' => 'USD',
    'language' => 'en',
    'date_format' => 'Y-m-d',
    'time_format' => '24h',
    'week_start' => 'monday',
    'auto_backup' => 1,
    'two_factor_auth' => 0,
    'session_timeout' => 30,
    'email_reminders' => 1,
    'sms_reminders' => 0,
    'calendar_sync' => 0,
    'public_profile' => 1,
    'show_availability' => 1,
    'allow_online_booking' => 1,
    'require_payment_upfront' => 0,
    'availability_monday' => '09:00-17:00',
    'availability_tuesday' => '09:00-17:00',
    'availability_wednesday' => '09:00-17:00',
    'availability_thursday' => '09:00-17:00',
    'availability_friday' => '09:00-17:00',
    'availability_saturday' => '09:00-13:00',
    'availability_sunday' => ''
];

// Set default theme to dark
$theme = 'dark';

// Enhanced theme preference system with proper error handling
try {
    // Check if trainer_settings table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE trainer_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                theme_preference VARCHAR(20) DEFAULT 'dark',
                notification_email TINYINT(1) DEFAULT 1,
                notification_sms TINYINT(1) DEFAULT 0,
                notification_push TINYINT(1) DEFAULT 1,
                auto_confirm_appointments TINYINT(1) DEFAULT 0,
                default_session_duration INT DEFAULT 60,
                working_hours_start TIME DEFAULT '06:00:00',
                working_hours_end TIME DEFAULT '22:00:00',
                break_duration INT DEFAULT 15,
                max_daily_sessions INT DEFAULT 12,
                booking_advance_days INT DEFAULT 30,
                cancellation_hours INT DEFAULT 24,
                timezone VARCHAR(50) DEFAULT 'UTC',
                currency VARCHAR(10) DEFAULT 'USD',
                language VARCHAR(10) DEFAULT 'en',
                date_format VARCHAR(20) DEFAULT 'Y-m-d',
                time_format VARCHAR(10) DEFAULT '24h',
                week_start VARCHAR(10) DEFAULT 'monday',
                auto_backup TINYINT(1) DEFAULT 1,
                two_factor_auth TINYINT(1) DEFAULT 0,
                session_timeout INT DEFAULT 30,
                email_reminders TINYINT(1) DEFAULT 1,
                sms_reminders TINYINT(1) DEFAULT 0,
                calendar_sync TINYINT(1) DEFAULT 0,
                public_profile TINYINT(1) DEFAULT 1,
                show_availability TINYINT(1) DEFAULT 1,
                allow_online_booking TINYINT(1) DEFAULT 1,
                require_payment_upfront TINYINT(1) DEFAULT 0,
                availability_monday VARCHAR(50) DEFAULT '09:00-17:00',
                availability_tuesday VARCHAR(50) DEFAULT '09:00-17:00',
                availability_wednesday VARCHAR(50) DEFAULT '09:00-17:00',
                availability_thursday VARCHAR(50) DEFAULT '09:00-17:00',
                availability_friday VARCHAR(50) DEFAULT '09:00-17:00',
                availability_saturday VARCHAR(50) DEFAULT '09:00-13:00',
                availability_sunday VARCHAR(50) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id)
            )
        ");
        
        // Insert default settings for the user
        $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id, theme_preference) VALUES (?, 'dark')");
        $stmt->execute([$userId]);
    }

    // Fetch user settings
    $stmt = $conn->prepare("SELECT * FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Merge user settings with defaults
    if ($userSettings) {
        $settings = array_merge($settings, $userSettings);
        $theme = $settings['theme_preference'];
    } else {
        // Insert default settings if none exist
        $stmt = $conn->prepare("
            INSERT INTO trainer_settings (
                user_id, theme_preference, notification_email, notification_sms, 
                notification_push, auto_confirm_appointments, default_session_duration,
                working_hours_start, working_hours_end, break_duration, max_daily_sessions,
                booking_advance_days, cancellation_hours, timezone, currency, language,
                date_format, time_format, week_start, auto_backup, two_factor_auth,
                session_timeout, email_reminders, sms_reminders, calendar_sync,
                public_profile, show_availability, allow_online_booking, require_payment_upfront,
                availability_monday, availability_tuesday, availability_wednesday,
                availability_thursday, availability_friday, availability_saturday, availability_sunday
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId, $settings['theme_preference'], $settings['notification_email'],
            $settings['notification_sms'], $settings['notification_push'], 
            $settings['auto_confirm_appointments'], $settings['default_session_duration'],
            $settings['working_hours_start'], $settings['working_hours_end'],
            $settings['break_duration'], $settings['max_daily_sessions'],
            $settings['booking_advance_days'], $settings['cancellation_hours'],
            $settings['timezone'], $settings['currency'], $settings['language'],
            $settings['date_format'], $settings['time_format'], $settings['week_start'],
            $settings['auto_backup'], $settings['two_factor_auth'], $settings['session_timeout'],
            $settings['email_reminders'], $settings['sms_reminders'], $settings['calendar_sync'],
            $settings['public_profile'], $settings['show_availability'], 
            $settings['allow_online_booking'], $settings['require_payment_upfront'],
            $settings['availability_monday'], $settings['availability_tuesday'],
            $settings['availability_wednesday'], $settings['availability_thursday'],
            $settings['availability_friday'], $settings['availability_saturday'],
            $settings['availability_sunday']
        ]);
    }
} catch (PDOException $e) {
    // Use default settings on error
    error_log("Settings error: " . $e->getMessage());
}

// Initialize profile with defaults
$profile = [
    'name' => $userName,
    'email' => $_SESSION['email'] ?? '',
    'phone' => '',
    'bio' => '',
    'specialties' => '',
    'certifications' => '',
    'experience_years' => 0,
    'hourly_rate' => 0.00,
    'profile_image' => $_SESSION['profile_image'] ?? '',
    'social_instagram' => '',
    'social_facebook' => '',
    'social_twitter' => '',
    'social_linkedin' => '',
    'website' => ''
];

try {
    // Get profile data from users table
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        $profile['name'] = $userData['name'];
        $profile['email'] = $userData['email'];
        $profile['phone'] = $userData['phone'] ?? '';
        $profile['profile_image'] = $userData['profile_image'] ?? '';
        
        // Check if trainer_profiles table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_profiles'")->rowCount() > 0;
        
        if (!$tableExists) {
            $conn->exec("
                CREATE TABLE trainer_profiles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    bio TEXT,
                    specialties TEXT,
                    certifications TEXT,
                    experience_years INT DEFAULT 0,
                    hourly_rate DECIMAL(10,2) DEFAULT 0.00,
                    social_instagram VARCHAR(255) DEFAULT '',
                    social_facebook VARCHAR(255) DEFAULT '',
                    social_twitter VARCHAR(255) DEFAULT '',
                    social_linkedin VARCHAR(255) DEFAULT '',
                    website VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (user_id)
                )
            ");
        }
        
        $stmt = $conn->prepare("SELECT * FROM trainer_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $trainerProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($trainerProfile) {
            $profile['bio'] = $trainerProfile['bio'] ?? '';
            $profile['specialties'] = $trainerProfile['specialties'] ?? '';
            $profile['certifications'] = $trainerProfile['certifications'] ?? '';
            $profile['experience_years'] = $trainerProfile['experience_years'] ?? 0;
            $profile['hourly_rate'] = $trainerProfile['hourly_rate'] ?? 0.00;
            $profile['social_instagram'] = $trainerProfile['social_instagram'] ?? '';
            $profile['social_facebook'] = $trainerProfile['social_facebook'] ?? '';
            $profile['social_twitter'] = $trainerProfile['social_twitter'] ?? '';
            $profile['social_linkedin'] = $trainerProfile['social_linkedin'] ?? '';
            $profile['website'] = $trainerProfile['website'] ?? '';
        }
    }
} catch (PDOException $e) {
    // Handle error - default profile already set
    error_log("Profile error: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_general_settings'])) {
        try {
            $themePreference = $_POST['theme_preference'] ?? 'dark';
            $timezone = $_POST['timezone'] ?? 'UTC';
            $currency = $_POST['currency'] ?? 'USD';
            $language = $_POST['language'] ?? 'en';
            $dateFormat = $_POST['date_format'] ?? 'Y-m-d';
            $timeFormat = $_POST['time_format'] ?? '24h';
            $weekStart = $_POST['week_start'] ?? 'monday';
            $sessionTimeout = intval($_POST['session_timeout'] ?? 30);
            
            $stmt = $conn->prepare("
                UPDATE trainer_profiles 
                SET theme_preference = ?, timezone = ?, currency = ?, language = ?, 
                    date_format = ?, time_format = ?, week_start = ?, session_timeout = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $themePreference, $timezone, $currency, $language,
                $dateFormat, $timeFormat, $weekStart, $sessionTimeout, $userId
            ]);
            
            // Update local settings
            $settings['theme_preference'] = $themePreference;
            $settings['timezone'] = $timezone;
            $settings['currency'] = $currency;
            $settings['language'] = $language;
            $settings['date_format'] = $dateFormat;
            $settings['time_format'] = $timeFormat;
            $settings['week_start'] = $weekStart;
            $settings['session_timeout'] = $sessionTimeout;
            
            $theme = $themePreference;
            $message = 'General settings updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating general settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['update_notification_settings'])) {
        try {
            $notificationEmail = isset($_POST['notification_email']) ? 1 : 0;
            $notificationSms = isset($_POST['notification_sms']) ? 1 : 0;
            $notificationPush = isset($_POST['notification_push']) ? 1 : 0;
            $emailReminders = isset($_POST['email_reminders']) ? 1 : 0;
            $smsReminders = isset($_POST['sms_reminders']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                UPDATE trainer_profiles 
                SET notification_email = ?, notification_sms = ?, notification_push = ?,
                    email_reminders = ?, sms_reminders = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $notificationEmail, $notificationSms, $notificationPush,
                $emailReminders, $smsReminders, $userId
            ]);
            
            // Update local settings
            $settings['notification_email'] = $notificationEmail;
            $settings['notification_sms'] = $notificationSms;
            $settings['notification_push'] = $notificationPush;
            $settings['email_reminders'] = $emailReminders;
            $settings['sms_reminders'] = $smsReminders;
            
            $message = 'Notification settings updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating notification settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['update_business_settings'])) {
        try {
            $autoConfirmAppointments = isset($_POST['auto_confirm_appointments']) ? 1 : 0;
            $defaultSessionDuration = intval($_POST['default_session_duration'] ?? 60);
            $workingHoursStart = $_POST['working_hours_start'] ?? '06:00:00';
            $workingHoursEnd = $_POST['working_hours_end'] ?? '22:00:00';
            $breakDuration = intval($_POST['break_duration'] ?? 15);
            $maxDailySessions = intval($_POST['max_daily_sessions'] ?? 12);
            $bookingAdvanceDays = intval($_POST['booking_advance_days'] ?? 30);
            $cancellationHours = intval($_POST['cancellation_hours'] ?? 24);
            $publicProfile = isset($_POST['public_profile']) ? 1 : 0;
            $showAvailability = isset($_POST['show_availability']) ? 1 : 0;
            $allowOnlineBooking = isset($_POST['allow_online_booking']) ? 1 : 0;
            $requirePaymentUpfront = isset($_POST['require_payment_upfront']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                UPDATE trainer_profiles 
                SET auto_confirm_appointments = ?, default_session_duration = ?, 
                    working_hours_start = ?, working_hours_end = ?, break_duration = ?,
                    max_daily_sessions = ?, booking_advance_days = ?, cancellation_hours = ?,
                    public_profile = ?, show_availability = ?, allow_online_booking = ?,
                    require_payment_upfront = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $autoConfirmAppointments, $defaultSessionDuration, $workingHoursStart,
                $workingHoursEnd, $breakDuration, $maxDailySessions, $bookingAdvanceDays,
                $cancellationHours, $publicProfile, $showAvailability, $allowOnlineBooking,
                $requirePaymentUpfront, $userId
            ]);
            
            // Update local settings
            $settings['auto_confirm_appointments'] = $autoConfirmAppointments;
            $settings['default_session_duration'] = $defaultSessionDuration;
            $settings['working_hours_start'] = $workingHoursStart;
            $settings['working_hours_end'] = $workingHoursEnd;
            $settings['break_duration'] = $breakDuration;
            $settings['max_daily_sessions'] = $maxDailySessions;
            $settings['booking_advance_days'] = $bookingAdvanceDays;
            $settings['cancellation_hours'] = $cancellationHours;
            $settings['public_profile'] = $publicProfile;
            $settings['show_availability'] = $showAvailability;
            $settings['allow_online_booking'] = $allowOnlineBooking;
            $settings['require_payment_upfront'] = $requirePaymentUpfront;
            
            $message = 'Business settings updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating business settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['update_availability'])) {
        try {
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
            
            $stmt = $conn->prepare("
                UPDATE trainer_profiles 
                SET availability_monday = ?, availability_tuesday = ?, availability_wednesday = ?,
                    availability_thursday = ?, availability_friday = ?, availability_saturday = ?,
                    availability_sunday = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $availability['monday'], $availability['tuesday'], $availability['wednesday'],
                $availability['thursday'], $availability['friday'], $availability['saturday'],
                $availability['sunday'], $userId
            ]);
            
            // Update local settings
            foreach ($availabilityDays as $day) {
                $settings["availability_$day"] = $availability[$day];
            }
            
            $message = 'Availability updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating availability: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['update_profile'])) {
        try {
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $bio = $_POST['bio'] ?? '';
            $specialties = $_POST['specialties'] ?? '';
            $certifications = $_POST['certifications'] ?? '';
            $experienceYears = intval($_POST['experience_years'] ?? 0);
            $hourlyRate = floatval($_POST['hourly_rate'] ?? 0.00);
            $socialInstagram = $_POST['social_instagram'] ?? '';
            $socialFacebook = $_POST['social_facebook'] ?? '';
            $socialTwitter = $_POST['social_twitter'] ?? '';
            $socialLinkedin = $_POST['social_linkedin'] ?? '';
            $website = $_POST['website'] ?? '';
            
            // Update user data
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, email = ?, phone = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $userId]);
            
            // Check if trainer profile exists
            $stmt = $conn->prepare("SELECT id FROM trainer_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $profileExists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($profileExists) {
                $stmt = $conn->prepare("
                    UPDATE trainer_profiles 
                    SET bio = ?, specialties = ?, certifications = ?, experience_years = ?,
                        hourly_rate = ?, social_instagram = ?, social_facebook = ?,
                        social_twitter = ?, social_linkedin = ?, website = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $bio, $specialties, $certifications, $experienceYears, $hourlyRate,
                    $socialInstagram, $socialFacebook, $socialTwitter, $socialLinkedin,
                    $website, $userId
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO trainer_profiles 
                    (user_id, bio, specialties, certifications, experience_years, hourly_rate,
                     social_instagram, social_facebook, social_twitter, social_linkedin, website)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $bio, $specialties, $certifications, $experienceYears, $hourlyRate,
                    $socialInstagram, $socialFacebook, $socialTwitter, $socialLinkedin, $website
                ]);
            }
            
            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/profile_images/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileExtension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $newFilename = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
                $uploadFile = $uploadDir . $newFilename;
                
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadFile)) {
                    $imageUrl = '/uploads/profile_images/' . $newFilename;
                    
                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->execute([$imageUrl, $userId]);
                    
                    $profile['profile_image'] = $imageUrl;
                    $_SESSION['profile_image'] = $imageUrl;
                }
            }
            
            // Update session and local profile
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;
            $profile['name'] = $name;
            $profile['email'] = $email;
            $profile['phone'] = $phone;
            $profile['bio'] = $bio;
            $profile['specialties'] = $specialties;
            $profile['certifications'] = $certifications;
            $profile['experience_years'] = $experienceYears;
            $profile['hourly_rate'] = $hourlyRate;
            $profile['social_instagram'] = $socialInstagram;
            $profile['social_facebook'] = $socialFacebook;
            $profile['social_twitter'] = $socialTwitter;
            $profile['social_linkedin'] = $socialLinkedin;
            $profile['website'] = $website;
            
            $message = 'Profile updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating profile: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['update_password'])) {
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $message = 'All password fields are required.';
                $messageType = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $message = 'New passwords do not match.';
                $messageType = 'error';
            } elseif (strlen($newPassword) < 8) {
                $message = 'Password must be at least 8 characters long.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($currentPassword, $user['password'])) {
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
    
    if (isset($_POST['update_security_settings'])) {
        try {
            $twoFactorAuth = isset($_POST['two_factor_auth']) ? 1 : 0;
            $autoBackup = isset($_POST['auto_backup']) ? 1 : 0;
            $calendarSync = isset($_POST['calendar_sync']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                UPDATE trainer_profiles 
                SET two_factor_auth = ?, auto_backup = ?, calendar_sync = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([$twoFactorAuth, $autoBackup, $calendarSync, $userId]);
            
            // Update local settings
            $settings['two_factor_auth'] = $twoFactorAuth;
            $settings['auto_backup'] = $autoBackup;
            $settings['calendar_sync'] = $calendarSync;
            
            $message = 'Security settings updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating security settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Parse availability times with proper error handling
$availabilityTimes = [];
foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
    $availabilityTimes[$day] = [
        'start' => '',
        'end' => ''
    ];
    
    $timeRange = $settings["availability_$day"] ?? '';
    if (!empty($timeRange) && strpos($timeRange, '-') !== false) {
        $timeParts = explode('-', $timeRange);
        if (count($timeParts) === 2) {
            $availabilityTimes[$day]['start'] = trim($timeParts[0]);
            $availabilityTimes[$day]['end'] = trim($timeParts[1]);
        }
    }
}

// Get statistics for dashboard with proper error handling
$stats = [
    'total_members' => 0,
    'total_sessions' => 0,
    'total_plans' => 0,
    'this_month_sessions' => 0
];

try {
    // Create trainer_members table if it doesn't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS trainer_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT NOT NULL,
        status VARCHAR(20) DEFAULT 'active',
        assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_assignment (trainer_id, member_id)
    )");
    
    // Get member count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM trainer_members tm 
        JOIN users u ON tm.member_id = u.id 
        WHERE tm.trainer_id = ? AND tm.status = 'active'
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_members'] = $result['count'] ?? 0;
    
    // Create trainer_schedule table if it doesn't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS trainer_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Get session count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM trainer_schedule WHERE trainer_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_sessions'] = $result['count'] ?? 0;
    
    // Get this month's sessions
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM trainer_schedule 
        WHERE trainer_id = ? AND MONTH(start_time) = MONTH(CURRENT_DATE()) 
        AND YEAR(start_time) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['this_month_sessions'] = $result['count'] ?? 0;
    
    // Create workout_plans table if it doesn't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS workout_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT,
        plan_name VARCHAR(255) NOT NULL,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Get workout plans count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM workout_plans WHERE trainer_id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_plans'] = $result['count'] ?? 0;
    
} catch (PDOException $e) {
    // Handle error - stats already initialized with defaults
    error_log("Stats error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Settings - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Dark theme (default) */
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --secondary: #2c2c2c;
            --background: #121212;
            --surface: #1e1e1e;
            --surface-variant: #2a2a2a;
            --on-background: #ffffff;
            --on-surface: #e0e0e0;
            --on-surface-variant: #b0b0b0;
            --border: #333333;
            --success: #4caf50;
            --warning: #ff9800;
            --error: #f44336;
            --info: #2196f3;
        }

        [data-theme="light"] {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --secondary: #f5f5f5;
            --background: #ffffff;
            --surface: #f8f9fa;
            --surface-variant: #e9ecef;
            --on-background: #212529;
            --on-surface: #495057;
            --on-surface-variant: #6c757d;
            --border: #dee2e6;
            --success: #28a745;
            --warning: #ffc107;
            --error: #dc3545;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--on-background);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Enhanced Sidebar */
        .sidebar {
            width: 280px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .sidebar-header h2 {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.5rem;
            margin-top: 0.5rem;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section-title {
            padding: 0 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--on-surface-variant);
            margin-bottom: 1rem;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 2rem;
            color: var(--on-surface);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background: var(--surface-variant);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu a.active {
            background: var(--surface-variant);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 600;
        }

        .sidebar-menu i {
            width: 20px;
            margin-right: 1rem;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            background: var(--background);
        }

        /* Enhanced Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--on-background);
            margin-bottom: 0.25rem;
        }

        .header p {
            color: var(--on-surface-variant);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Theme Toggle */
        .theme-toggle {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .theme-toggle:hover {
            background: var(--surface-variant);
        }

        /* Enhanced Cards */
        .card {
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }

        .card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-variant);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-content {
            padding: 2rem;
        }

        /* Enhanced Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--on-surface);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            background: var(--surface-variant);
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #d32f2f;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Enhanced Tabs */
        .tabs {
            display: flex;
            flex-direction: column;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .tab-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            color: var(--on-surface-variant);
            cursor: pointer;
            transition: all 0.2s ease;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .tab-btn:hover {
            color: var(--primary);
            background: var(--surface-variant);
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--surface-variant);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Enhanced Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--on-surface);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--background);
            color: var(--on-background);
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            margin-top: 0.25rem;
        }

        /* Theme Options */
        .theme-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .theme-option {
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .theme-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .theme-option.active {
            border-color: var(--primary);
            background: rgba(255, 107, 53, 0.1);
        }

        .theme-preview {
            width: 100%;
            height: 80px;
            border-radius: 8px;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
        }

        .theme-preview.light-theme {
            background: #ffffff;
        }

        .theme-preview.dark-theme {
            background: #121212;
        }

        .theme-preview::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 20px;
            background: var(--primary);
        }

        .theme-preview::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            width: 30%;
            height: 60px;
            background: var(--surface-variant);
        }

        .theme-label {
            font-weight: 500;
            color: var(--on-surface);
        }

        /* Profile Image Upload */
        .profile-image-upload {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 2rem;
            background: var(--surface-variant);
            border-radius: 12px;
        }

        .current-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid var(--primary);
        }

        .current-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-placeholder {
            width: 100%;
            height: 100%;
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--on-surface-variant);
            font-size: 2rem;
        }

        .upload-controls {
            flex: 1;
        }

        /* Availability Grid */
        .availability-grid {
            display: grid;
            gap: 1.5rem;
        }

        .availability-day {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 1rem;
            align-items: center;
            padding: 1rem;
            background: var(--surface-variant);
            border-radius: 8px;
        }

        .day-label {
            font-weight: 600;
            color: var(--on-surface);
        }

        .time-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .time-input label {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            margin-bottom: 0.25rem;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--on-surface-variant);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Password Strength */
        .password-strength {
            margin-top: 1rem;
        }

        .password-strength-meter {
            width: 100%;
            height: 8px;
            background: var(--surface-variant);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .strength-meter-bar {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 4px;
        }

        .password-strength-text {
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            color: var(--error);
            border: 1px solid rgba(244, 67, 54, 0.2);
        }

        .alert .close {
            position: absolute;
            top: 0.5rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        .alert .close:hover {
            opacity: 1;
        }

        /* Mobile Responsiveness */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 1.2rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .header {
                padding-top: 4rem;
            }

            .tab-buttons {
                flex-wrap: wrap;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .theme-options {
                grid-template-columns: 1fr;
            }

            .availability-day {
                grid-template-columns: 1fr;
            }

            .time-inputs {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .profile-image-upload {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Section Headers */
        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .section-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary);
        }

        .section-header i {
            color: var(--primary);
            font-size: 1.25rem;
        }

        /* Advanced Settings */
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--surface-variant);
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .setting-info h4 {
            font-weight: 600;
            color: var(--on-surface);
            margin-bottom: 0.25rem;
        }

        .setting-info p {
            font-size: 0.875rem;
            color: var(--on-surface-variant);
        }

        .setting-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 50px;
            height: 24px;
            background: var(--border);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .toggle-switch.active {
            background: var(--primary);
        }

        .toggle-switch::before {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .toggle-switch.active::before {
            transform: translateX(26px);
        }

        /* Social Links */
        .social-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .social-input {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .social-input i {
            width: 20px;
            color: var(--primary);
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard-container">
        <!-- Enhanced Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div style="display: flex; align-items: center;">
                    <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                    <h2>EliteFit Gym</h2>
                </div>
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
            <!-- Enhanced Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-cog" style="color: var(--primary);"></i> Advanced Settings</h1>
                    <p>Manage your account settings, preferences, and business configuration</p>
                </div>
                <div class="header-actions">
                    <div class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-moon" id="themeIcon"></i>
                        <span id="themeText">Dark</span>
                    </div>
                    <button class="btn btn-primary" onclick="exportSettings()">
                        <i class="fas fa-download"></i> Export Settings
                    </button>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo htmlspecialchars($message); ?></div>
                    <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_members']; ?></div>
                    <div class="stat-label">Total Members</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_sessions']; ?></div>
                    <div class="stat-label">Total Sessions</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_plans']; ?></div>
                    <div class="stat-label">Workout Plans</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['this_month_sessions']; ?></div>
                    <div class="stat-label">This Month</div>
                </div>
            </div>
            
            <!-- Enhanced Settings Tabs -->
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
                            <button class="tab-btn" data-tab="business">
                                <i class="fas fa-briefcase"></i> Business
                            </button>
                            <button class="tab-btn" data-tab="notifications">
                                <i class="fas fa-bell"></i> Notifications
                            </button>
                            <button class="tab-btn" data-tab="availability">
                                <i class="fas fa-calendar-alt"></i> Availability
                            </button>
                            <button class="tab-btn" data-tab="security">
                                <i class="fas fa-shield-alt"></i> Security
                            </button>
                            <button class="tab-btn" data-tab="password">
                                <i class="fas fa-lock"></i> Password
                            </button>
                        </div>
                        
                        <!-- General Settings Tab -->
                        <div class="tab-content active" id="general-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_general_settings" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-palette"></i>
                                    <h3>Theme & Appearance</h3>
                                </div>
                                
                                <div class="theme-options">
                                    <div class="theme-option <?php echo ($settings['theme_preference'] ?? 'dark') === 'light' ? 'active' : ''; ?>" data-theme="light">
                                        <div class="theme-preview light-theme"></div>
                                        <div class="theme-label">
                                            <input type="radio" name="theme_preference" value="light" <?php echo ($settings['theme_preference'] ?? 'dark') === 'light' ? 'checked' : ''; ?>>
                                            Light Theme
                                > 
                                            Light Theme
                                        </div>
                                    </div>
                                    
                                    <div class="theme-option <?php echo ($settings['theme_preference'] ?? 'dark') === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                                        <div class="theme-preview dark-theme"></div>
                                        <div class="theme-label">
                                            <input type="radio" name="theme_preference" value="dark" <?php echo ($settings['theme_preference'] ?? 'dark') === 'dark' ? 'checked' : ''; ?>>
                                            Dark Theme
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-globe"></i>
                                    <h3>Localization</h3>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="timezone">Timezone</label>
                                        <select id="timezone" name="timezone" class="form-control">
                                            <option value="UTC" <?php echo ($settings['timezone'] ?? 'UTC') === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                            <option value="America/New_York" <?php echo ($settings['timezone'] ?? '') === 'America/New_York' ? 'selected' : ''; ?>>Eastern Time</option>
                                            <option value="America/Chicago" <?php echo ($settings['timezone'] ?? '') === 'America/Chicago' ? 'selected' : ''; ?>>Central Time</option>
                                            <option value="America/Denver" <?php echo ($settings['timezone'] ?? '') === 'America/Denver' ? 'selected' : ''; ?>>Mountain Time</option>
                                            <option value="America/Los_Angeles" <?php echo ($settings['timezone'] ?? '') === 'America/Los_Angeles' ? 'selected' : ''; ?>>Pacific Time</option>
                                            <option value="Europe/London" <?php echo ($settings['timezone'] ?? '') === 'Europe/London' ? 'selected' : ''; ?>>London</option>
                                            <option value="Europe/Paris" <?php echo ($settings['timezone'] ?? '') === 'Europe/Paris' ? 'selected' : ''; ?>>Paris</option>
                                            <option value="Asia/Tokyo" <?php echo ($settings['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : ''; ?>>Tokyo</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="currency">Currency</label>
                                        <select id="currency" name="currency" class="form-control">
                                            <option value="USD" <?php echo ($settings['currency'] ?? 'USD') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                            <option value="EUR" <?php echo ($settings['currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                            <option value="GBP" <?php echo ($settings['currency'] ?? '') === 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                            <option value="CAD" <?php echo ($settings['currency'] ?? '') === 'CAD' ? 'selected' : ''; ?>>CAD ($)</option>
                                            <option value="AUD" <?php echo ($settings['currency'] ?? '') === 'AUD' ? 'selected' : ''; ?>>AUD ($)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="language">Language</label>
                                        <select id="language" name="language" class="form-control">
                                            <option value="en" <?php echo ($settings['language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="es" <?php echo ($settings['language'] ?? '') === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                            <option value="fr" <?php echo ($settings['language'] ?? '') === 'fr' ? 'selected' : ''; ?>>French</option>
                                            <option value="de" <?php echo ($settings['language'] ?? '') === 'de' ? 'selected' : ''; ?>>German</option>
                                            <option value="it" <?php echo ($settings['language'] ?? '') === 'it' ? 'selected' : ''; ?>>Italian</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="date_format">Date Format</label>
                                        <select id="date_format" name="date_format" class="form-control">
                                            <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                            <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                            <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                            <option value="M j, Y" <?php echo ($settings['date_format'] ?? '') === 'M j, Y' ? 'selected' : ''; ?>>Month DD, YYYY</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="time_format">Time Format</label>
                                        <select id="time_format" name="time_format" class="form-control">
                                            <option value="24h" <?php echo ($settings['time_format'] ?? '24h') === '24h' ? 'selected' : ''; ?>>24 Hour</option>
                                            <option value="12h" <?php echo ($settings['time_format'] ?? '') === '12h' ? 'selected' : ''; ?>>12 Hour (AM/PM)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="week_start">Week Starts On</label>
                                        <select id="week_start" name="week_start" class="form-control">
                                            <option value="monday" <?php echo ($settings['week_start'] ?? 'monday') === 'monday' ? 'selected' : ''; ?>>Monday</option>
                                            <option value="sunday" <?php echo ($settings['week_start'] ?? '') === 'sunday' ? 'selected' : ''; ?>>Sunday</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-clock"></i>
                                    <h3>Session Settings</h3>
                                </div>
                                
                                <div class="form-group">
                                    <label for="session_timeout">Session Timeout (minutes)</label>
                                    <input type="number" id="session_timeout" name="session_timeout" class="form-control" 
                                           value="<?php echo $settings['session_timeout'] ?? 30; ?>" min="5" max="120">
                                    <div class="form-text">How long before you're automatically logged out due to inactivity.</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save General Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Profile Tab -->
                        <div class="tab-content" id="profile-tab">
                            <form action="settings.php" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-camera"></i>
                                    <h3>Profile Picture</h3>
                                </div>
                                
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
                                
                                <div class="section-header">
                                    <i class="fas fa-user"></i>
                                    <h3>Basic Information</h3>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="name">Full Name</label>
                                        <input type="text" id="name" name="name" class="form-control" 
                                               value="<?php echo htmlspecialchars($profile['name']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" id="email" name="email" class="form-control" 
                                               value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="phone">Phone Number</label>
                                        <input type="tel" id="phone" name="phone" class="form-control" 
                                               value="<?php echo htmlspecialchars($profile['phone']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="experience_years">Years of Experience</label>
                                        <input type="number" id="experience_years" name="experience_years" class="form-control" 
                                               value="<?php echo $profile['experience_years']; ?>" min="0" max="50">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="hourly_rate">Hourly Rate (<?php echo $settings['currency'] ?? 'USD'; ?>)</label>
                                        <input type="number" id="hourly_rate" name="hourly_rate" class="form-control" 
                                               value="<?php echo $profile['hourly_rate']; ?>" min="0" step="0.01">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="website">Website</label>
                                        <input type="url" id="website" name="website" class="form-control" 
                                               value="<?php echo htmlspecialchars($profile['website']); ?>" 
                                               placeholder="https://yourwebsite.com">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-info-circle"></i>
                                    <h3>Professional Details</h3>
                                </div>
                                
                                <div class="form-group">
                                    <label for="bio">Professional Bio</label>
                                    <textarea id="bio" name="bio" class="form-control" rows="4"><?php echo htmlspecialchars($profile['bio']); ?></textarea>
                                    <div class="form-text">Tell your clients about yourself, your experience, and your training philosophy.</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="specialties">Training Specialties</label>
                                    <textarea id="specialties" name="specialties" class="form-control" rows="3"><?php echo htmlspecialchars($profile['specialties']); ?></textarea>
                                    <div class="form-text">List your training specialties (e.g., Weight Loss, Muscle Building, Sports Performance, Rehabilitation).</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="certifications">Certifications & Qualifications</label>
                                    <textarea id="certifications" name="certifications" class="form-control" rows="3"><?php echo htmlspecialchars($profile['certifications']); ?></textarea>
                                    <div class="form-text">List your professional certifications, degrees, and qualifications.</div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-share-alt"></i>
                                    <h3>Social Media Links</h3>
                                </div>
                                
                                <div class="social-links">
                                    <div class="form-group">
                                        <label for="social_instagram">Instagram</label>
                                        <div class="social-input">
                                            <i class="fab fa-instagram"></i>
                                            <input type="url" id="social_instagram" name="social_instagram" class="form-control" 
                                                   value="<?php echo htmlspecialchars($profile['social_instagram']); ?>" 
                                                   placeholder="https://instagram.com/username">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="social_facebook">Facebook</label>
                                        <div class="social-input">
                                            <i class="fab fa-facebook"></i>
                                            <input type="url" id="social_facebook" name="social_facebook" class="form-control" 
                                                   value="<?php echo htmlspecialchars($profile['social_facebook']); ?>" 
                                                   placeholder="https://facebook.com/username">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="social_twitter">Twitter</label>
                                        <div class="social-input">
                                            <i class="fab fa-twitter"></i>
                                            <input type="url" id="social_twitter" name="social_twitter" class="form-control" 
                                                   value="<?php echo htmlspecialchars($profile['social_twitter']); ?>" 
                                                   placeholder="https://twitter.com/username">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="social_linkedin">LinkedIn</label>
                                        <div class="social-input">
                                            <i class="fab fa-linkedin"></i>
                                            <input type="url" id="social_linkedin" name="social_linkedin" class="form-control" 
                                                   value="<?php echo htmlspecialchars($profile['social_linkedin']); ?>" 
                                                   placeholder="https://linkedin.com/in/username">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Profile
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Business Settings Tab -->
                        <div class="tab-content" id="business-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_business_settings" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-calendar-check"></i>
                                    <h3>Appointment Management</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Auto-confirm Appointments</h4>
                                        <p>Automatically confirm new appointments without manual approval</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['auto_confirm_appointments'] ?? 0) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'auto_confirm_appointments')">
                                        </div>
                                        <input type="hidden" name="auto_confirm_appointments" id="auto_confirm_appointments" 
                                               value="<?php echo ($settings['auto_confirm_appointments'] ?? 0) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Public Profile</h4>
                                        <p>Allow clients to view your public trainer profile</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['public_profile'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'public_profile')">
                                        </div>
                                        <input type="hidden" name="public_profile" id="public_profile" 
                                               value="<?php echo ($settings['public_profile'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Show Availability</h4>
                                        <p>Display your availability to clients for booking</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['show_availability'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'show_availability')">
                                        </div>
                                        <input type="hidden" name="show_availability" id="show_availability" 
                                               value="<?php echo ($settings['show_availability'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Allow Online Booking</h4>
                                        <p>Enable clients to book sessions online through your profile</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['allow_online_booking'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'allow_online_booking')">
                                        </div>
                                        <input type="hidden" name="allow_online_booking" id="allow_online_booking" 
                                               value="<?php echo ($settings['allow_online_booking'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Require Payment Upfront</h4>
                                        <p>Require payment before confirming appointments</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['require_payment_upfront'] ?? 0) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'require_payment_upfront')">
                                        </div>
                                        <input type="hidden" name="require_payment_upfront" id="require_payment_upfront" 
                                               value="<?php echo ($settings['require_payment_upfront'] ?? 0) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-clock"></i>
                                    <h3>Session Configuration</h3>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="default_session_duration">Default Session Duration (minutes)</label>
                                        <input type="number" id="default_session_duration" name="default_session_duration" 
                                               class="form-control" value="<?php echo $settings['default_session_duration'] ?? 60; ?>" 
                                               min="15" max="180" step="15">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="break_duration">Break Between Sessions (minutes)</label>
                                        <input type="number" id="break_duration" name="break_duration" 
                                               class="form-control" value="<?php echo $settings['break_duration'] ?? 15; ?>" 
                                               min="0" max="60" step="5">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="max_daily_sessions">Maximum Daily Sessions</label>
                                        <input type="number" id="max_daily_sessions" name="max_daily_sessions" 
                                               class="form-control" value="<?php echo $settings['max_daily_sessions'] ?? 12; ?>" 
                                               min="1" max="20">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="booking_advance_days">Booking Advance (days)</label>
                                        <input type="number" id="booking_advance_days" name="booking_advance_days" 
                                               class="form-control" value="<?php echo $settings['booking_advance_days'] ?? 30; ?>" 
                                               min="1" max="365">
                                        <div class="form-text">How far in advance clients can book sessions</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="cancellation_hours">Cancellation Notice (hours)</label>
                                        <input type="number" id="cancellation_hours" name="cancellation_hours" 
                                               class="form-control" value="<?php echo $settings['cancellation_hours'] ?? 24; ?>" 
                                               min="1" max="168">
                                        <div class="form-text">Minimum notice required for cancellations</div>
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-business-time"></i>
                                    <h3>Working Hours</h3>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="working_hours_start">Start Time</label>
                                        <input type="time" id="working_hours_start" name="working_hours_start" 
                                               class="form-control" value="<?php echo $settings['working_hours_start'] ?? '06:00'; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="working_hours_end">End Time</label>
                                        <input type="time" id="working_hours_end" name="working_hours_end" 
                                               class="form-control" value="<?php echo $settings['working_hours_end'] ?? '22:00'; ?>">
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Business Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Notifications Tab -->
                        <div class="tab-content" id="notifications-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_notification_settings" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-envelope"></i>
                                    <h3>Email Notifications</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>General Email Notifications</h4>
                                        <p>Receive notifications about appointments, cancellations, and updates via email</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['notification_email'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'notification_email')">
                                        </div>
                                        <input type="hidden" name="notification_email" id="notification_email" 
                                               value="<?php echo ($settings['notification_email'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Email Reminders</h4>
                                        <p>Send automatic email reminders to clients before their sessions</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['email_reminders'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'email_reminders')">
                                        </div>
                                        <input type="hidden" name="email_reminders" id="email_reminders" 
                                               value="<?php echo ($settings['email_reminders'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-sms"></i>
                                    <h3>SMS Notifications</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>SMS Notifications</h4>
                                        <p>Receive important notifications via SMS text messages</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['notification_sms'] ?? 0) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'notification_sms')">
                                        </div>
                                        <input type="hidden" name="notification_sms" id="notification_sms" 
                                               value="<?php echo ($settings['notification_sms'] ?? 0) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>SMS Reminders</h4>
                                        <p>Send automatic SMS reminders to clients before their sessions</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['sms_reminders'] ?? 0) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'sms_reminders')">
                                        </div>
                                        <input type="hidden" name="sms_reminders" id="sms_reminders" 
                                               value="<?php echo ($settings['sms_reminders'] ?? 0) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-bell"></i>
                                    <h3>Push Notifications</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Browser Push Notifications</h4>
                                        <p>Receive real-time notifications in your browser</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['notification_push'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'notification_push')">
                                        </div>
                                        <input type="hidden" name="notification_push" id="notification_push" 
                                               value="<?php echo ($settings['notification_push'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Availability Tab -->
                        <div class="tab-content" id="availability-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_availability" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-calendar-week"></i>
                                    <h3>Weekly Availability</h3>
                                </div>
                                
                                <p style="margin-bottom: 2rem; color: var(--on-surface-variant);">
                                    Set your regular working hours for each day of the week. Leave both fields empty if you're not available on a particular day.
                                </p>
                                
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
                                            <div class="day-label">
                                                <i class="fas fa-calendar-day" style="color: var(--primary); margin-right: 0.5rem;"></i>
                                                <?php echo $dayName; ?>
                                            </div>
                                            <div class="time-inputs">
                                                <div class="time-input">
                                                    <label for="availability_<?php echo $dayKey; ?>_start">From</label>
                                                    <input type="time" id="availability_<?php echo $dayKey; ?>_start" 
                                                           name="availability_<?php echo $dayKey; ?>_start" class="form-control" 
                                                           value="<?php echo $availabilityTimes[$dayKey]['start']; ?>">
                                                </div>
                                                <div class="time-input">
                                                    <label for="availability_<?php echo $dayKey; ?>_end">To</label>
                                                    <input type="time" id="availability_<?php echo $dayKey; ?>_end" 
                                                           name="availability_<?php echo $dayKey; ?>_end" class="form-control" 
                                                           value="<?php echo $availabilityTimes[$dayKey]['end']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Availability
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="copyWeekdaySchedule()">
                                        <i class="fas fa-copy"></i> Copy Weekday Schedule
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="clearAllSchedule()">
                                        <i class="fas fa-times"></i> Clear All
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Security Tab -->
                        <div class="tab-content" id="security-tab">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_security_settings" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-shield-alt"></i>
                                    <h3>Account Security</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Two-Factor Authentication</h4>
                                        <p>Add an extra layer of security to your account with 2FA</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['two_factor_auth'] ?? 0) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'two_factor_auth')">
                                        </div>
                                        <input type="hidden" name="two_factor_auth" id="two_factor_auth" 
                                               value="<?php echo ($settings['two_factor_auth'] ?? 0) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-database"></i>
                                    <h3>Data Management</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Automatic Backup</h4>
                                        <p>Automatically backup your data and settings regularly</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['auto_backup'] ?? 1) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'auto_backup')">
                                        </div>
                                        <input type="hidden" name="auto_backup" id="auto_backup" 
                                               value="<?php echo ($settings['auto_backup'] ?? 1) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-sync"></i>
                                    <h3>Integrations</h3>
                                </div>
                                
                                <div class="setting-item">
                                    <div class="setting-info">
                                        <h4>Calendar Sync</h4>
                                        <p>Sync your schedule with external calendar applications</p>
                                    </div>
                                    <div class="setting-control">
                                        <div class="toggle-switch <?php echo ($settings['calendar_sync'] ?? 0) ? 'active' : ''; ?>" 
                                             onclick="toggleSetting(this, 'calendar_sync')">
                                        </div>
                                        <input type="hidden" name="calendar_sync" id="calendar_sync" 
                                               value="<?php echo ($settings['calendar_sync'] ?? 0) ? '1' : '0'; ?>">
                                    </div>
                                </div>
                                
                                <div class="section-header">
                                    <i class="fas fa-download"></i>
                                    <h3>Data Export</h3>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                    <button type="button" class="btn btn-outline" onclick="exportData('profile')">
                                        <i class="fas fa-user"></i> Export Profile Data
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="exportData('sessions')">
                                        <i class="fas fa-calendar"></i> Export Session Data
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="exportData('members')">
                                        <i class="fas fa-users"></i> Export Member Data
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="exportData('all')">
                                        <i class="fas fa-download"></i> Export All Data
                                    </button>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Security Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Password Tab -->
                        <div class="tab-content" id="password-tab">
                            <form action="settings.php" method="post" id="passwordForm">
                                <input type="hidden" name="update_password" value="1">
                                
                                <div class="section-header">
                                    <i class="fas fa-key"></i>
                                    <h3>Change Password</h3>
                                </div>
                                
                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" class="form-control" 
                                           required minlength="8" onkeyup="checkPasswordStrength()">
                                    <div class="form-text">Password must be at least 8 characters long and include uppercase, lowercase, numbers, and special characters.</div>
                                </div>
                                
                                <div class="password-strength">
                                    <div class="password-strength-label">Password Strength:</div>
                                    <div class="password-strength-meter">
                                        <div id="passwordStrengthMeter" class="strength-meter-bar"></div>
                                    </div>
                                    <div id="passwordStrengthText" class="password-strength-text">Too weak</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                           required onkeyup="checkPasswordMatch()">
                                    <div id="passwordMatchText" class="form-text"></div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary" id="updatePasswordBtn" disabled>
                                        <i class="fas fa-save"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Theme management
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            
            // Update theme toggle button
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            
            if (newTheme === 'dark') {
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark';
            } else {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light';
            }
            
            // Save theme preference
            fetch('update_theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ theme: newTheme })
            });
        }

        // Initialize theme
        document.addEventListener('DOMContentLoaded', function() {
            const theme = '<?php echo $theme; ?>';
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            
            if (theme === 'dark') {
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark';
            } else {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light';
            }
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
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

        // Profile image preview
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

        // Toggle settings
        function toggleSetting(element, settingName) {
            element.classList.toggle('active');
            const input = document.getElementById(settingName);
            const isActive = element.classList.contains('active');
            input.value = isActive ? '1' : '0';
        }

        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const meter = document.getElementById('passwordStrengthMeter');
            const text = document.getElementById('passwordStrengthText');
            
            let strength = 0;
            let feedback = [];
            
            // Length check
            if (password.length >= 8) strength += 1;
            else feedback.push('at least 8 characters');
            
            // Uppercase check
            if (/[A-Z]/.test(password)) strength += 1;
            else feedback.push('uppercase letter');
            
            // Lowercase check
            if (/[a-z]/.test(password)) strength += 1;
            else feedback.push('lowercase letter');
            
            // Number check
            if (/\d/.test(password)) strength += 1;
            else feedback.push('number');
            
            // Special character check
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 1;
            else feedback.push('special character');
            
            // Update meter
            const percentage = (strength / 5) * 100;
            meter.style.width = percentage + '%';
            
            // Update colors and text
            if (strength <= 1) {
                meter.style.background = '#f44336';
                text.textContent = 'Too weak';
                text.style.color = '#f44336';
            } else if (strength <= 2) {
                meter.style.background = '#ff9800';
                text.textContent = 'Weak';
                text.style.color = '#ff9800';
            } else if (strength <= 3) {
                meter.style.background = '#ffc107';
                text.textContent = 'Fair';
                text.style.color = '#ffc107';
            } else if (strength <= 4) {
                meter.style.background = '#4caf50';
                text.textContent = 'Good';
                text.style.color = '#4caf50';
            } else {
                meter.style.background = '#2e7d32';
                text.textContent = 'Strong';
                text.style.color = '#2e7d32';
            }
            
            checkPasswordMatch();
        }

        // Password match checker
        function checkPasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('passwordMatchText');
            const updateBtn = document.getElementById('updatePasswordBtn');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
                updateBtn.disabled = true;
                return;
            }
            
            if (newPassword === confirmPassword) {
                matchText.textContent = '✓ Passwords match';
                matchText.style.color = '#4caf50';
                updateBtn.disabled = newPassword.length < 8;
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.style.color = '#f44336';
                updateBtn.disabled = true;
            }
        }

        // Availability helpers
        function copyWeekdaySchedule() {
            const mondayStart = document.getElementById('availability_monday_start').value;
            const mondayEnd = document.getElementById('availability_monday_end').value;
            
            if (mondayStart && mondayEnd) {
                ['tuesday', 'wednesday', 'thursday', 'friday'].forEach(day => {
                    document.getElementById(`availability_${day}_start`).value = mondayStart;
                    document.getElementById(`availability_${day}_end`).value = mondayEnd;
                });
            }
        }

        function clearAllSchedule() {
            if (confirm('Are you sure you want to clear all availability schedules?')) {
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach(day => {
                    document.getElementById(`availability_${day}_start`).value = '';
                    document.getElementById(`availability_${day}_end`).value = '';
                });
            }
        }

        // Export functions
        function exportSettings() {
            window.open('export_settings.php', '_blank');
        }

        function exportData(type) {
            window.open(`export_data.php?type=${type}`, '_blank');
        }

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = 'var(--error)';
                    } else {
                        field.style.borderColor = 'var(--border)';
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to save current tab
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const activeTab = document.querySelector('.tab-content.active');
                const form = activeTab.querySelector('form');
                if (form) {
                    form.submit();
                }
            }
        });
    </script>
</body>
</html>
