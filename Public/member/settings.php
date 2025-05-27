<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userEmail = $_SESSION['email'] ?? '';
$profileImage = $_SESSION['profile_image'] ?? '';

// Connect to database
$conn = connectDB();

// Initialize default settings
$settings = [
    'theme' => 'dark',
    'measurement_unit' => 'metric',
    'email_notifications' => true,
    'push_notifications' => true,
    'workout_reminders' => true,
    'appointment_reminders' => true,
    'progress_sharing' => true,
    'profile_visibility' => 'members',
    'show_progress' => true,
    'auto_sync_devices' => false,
    'data_export_format' => 'json',
    'session_timeout' => 30,
    'two_factor_auth' => false,
    'marketing_emails' => false,
    'workout_music' => true,
    'rest_timer_sound' => true,
    'voice_coaching' => false,
    'dark_mode_schedule' => false,
    'auto_backup' => true,
    'share_achievements' => true,
    'weekly_summary' => true,
    'goal_reminders' => true,
    'social_features' => true,
    'location_tracking' => false,
    'calorie_tracking' => true,
    'heart_rate_zones' => true,
    'workout_intensity' => 'moderate',
    'preferred_workout_time' => 'morning',
    'rest_day_reminders' => true,
    'nutrition_tracking' => true,
    'water_reminders' => true,
    'sleep_tracking' => false,
    'step_goal' => 10000,
    'weekly_workout_goal' => 4,
    'language' => 'en',
    'timezone' => 'UTC',
    'date_format' => 'Y-m-d',
    'time_format' => '24h',
    'currency' => 'USD'
];

try {
    // Check if member_settings table exists and create if not
    $tableExists = $conn->query("SHOW TABLES LIKE 'member_settings'")->rowCount() > 0;
    
    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE member_settings (
                user_id INT PRIMARY KEY,
                theme VARCHAR(20) DEFAULT 'dark',
                measurement_unit VARCHAR(20) DEFAULT 'metric',
                email_notifications BOOLEAN DEFAULT TRUE,
                push_notifications BOOLEAN DEFAULT TRUE,
                workout_reminders BOOLEAN DEFAULT TRUE,
                appointment_reminders BOOLEAN DEFAULT TRUE,
                progress_sharing BOOLEAN DEFAULT TRUE,
                profile_visibility VARCHAR(20) DEFAULT 'members',
                show_progress BOOLEAN DEFAULT TRUE,
                auto_sync_devices BOOLEAN DEFAULT FALSE,
                data_export_format VARCHAR(20) DEFAULT 'json',
                session_timeout INT DEFAULT 30,
                two_factor_auth BOOLEAN DEFAULT FALSE,
                marketing_emails BOOLEAN DEFAULT FALSE,
                workout_music BOOLEAN DEFAULT TRUE,
                rest_timer_sound BOOLEAN DEFAULT TRUE,
                voice_coaching BOOLEAN DEFAULT FALSE,
                dark_mode_schedule BOOLEAN DEFAULT FALSE,
                auto_backup BOOLEAN DEFAULT TRUE,
                share_achievements BOOLEAN DEFAULT TRUE,
                weekly_summary BOOLEAN DEFAULT TRUE,
                goal_reminders BOOLEAN DEFAULT TRUE,
                social_features BOOLEAN DEFAULT TRUE,
                location_tracking BOOLEAN DEFAULT FALSE,
                calorie_tracking BOOLEAN DEFAULT TRUE,
                heart_rate_zones BOOLEAN DEFAULT TRUE,
                workout_intensity VARCHAR(20) DEFAULT 'moderate',
                preferred_workout_time VARCHAR(20) DEFAULT 'morning',
                rest_day_reminders BOOLEAN DEFAULT TRUE,
                nutrition_tracking BOOLEAN DEFAULT TRUE,
                water_reminders BOOLEAN DEFAULT TRUE,
                sleep_tracking BOOLEAN DEFAULT FALSE,
                step_goal INT DEFAULT 10000,
                weekly_workout_goal INT DEFAULT 4,
                language VARCHAR(10) DEFAULT 'en',
                timezone VARCHAR(50) DEFAULT 'UTC',
                date_format VARCHAR(20) DEFAULT 'Y-m-d',
                time_format VARCHAR(10) DEFAULT '24h',
                currency VARCHAR(10) DEFAULT 'USD',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Insert default settings for the user
        $stmt = $conn->prepare("INSERT INTO member_settings (user_id) VALUES (?)");
        $stmt->execute([$userId]);
    }
    
    // Get user settings
    $stmt = $conn->prepare("SELECT * FROM member_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userSettings) {
        $settings = array_merge($settings, $userSettings);
    } else {
        // Insert default settings if none exist
        $stmt = $conn->prepare("INSERT INTO member_settings (user_id) VALUES (?)");
        $stmt->execute([$userId]);
    }
} catch (PDOException $e) {
    error_log("Settings error: " . $e->getMessage());
}

// Get user profile data with enhanced fields
$profile = [
    'name' => $userName,
    'email' => $userEmail,
    'phone' => '',
    'date_of_birth' => '',
    'gender' => '',
    'height' => '',
    'weight' => '',
    'experience_level' => '',
    'fitness_goals' => '',
    'preferred_routines' => '',
    'profile_image' => $profileImage,
    'emergency_contact_name' => '',
    'emergency_contact_phone' => '',
    'medical_conditions' => '',
    'allergies' => '',
    'current_medications' => '',
    'fitness_level' => '',
    'target_weight' => '',
    'body_fat_percentage' => '',
    'muscle_mass' => '',
    'activity_level' => '',
    'sleep_hours' => '',
    'stress_level' => '',
    'occupation' => '',
    'bio' => '',
    'social_instagram' => '',
    'social_facebook' => '',
    'social_twitter' => '',
    'preferred_trainer_gender' => '',
    'workout_frequency' => '',
    'nutrition_plan' => '',
    'supplement_usage' => ''
];

try {
    // Check if users table has all required columns
    $stmt = $conn->prepare("DESCRIBE users");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Add missing columns if needed
    $requiredColumns = [
        'emergency_contact_name' => 'VARCHAR(255)',
        'emergency_contact_phone' => 'VARCHAR(20)',
        'medical_conditions' => 'TEXT',
        'allergies' => 'TEXT',
        'current_medications' => 'TEXT',
        'fitness_level' => 'VARCHAR(50)',
        'target_weight' => 'DECIMAL(5,2)',
        'body_fat_percentage' => 'DECIMAL(5,2)',
        'muscle_mass' => 'DECIMAL(5,2)',
        'activity_level' => 'VARCHAR(50)',
        'sleep_hours' => 'INT',
        'stress_level' => 'VARCHAR(20)',
        'occupation' => 'VARCHAR(100)',
        'bio' => 'TEXT',
        'social_instagram' => 'VARCHAR(255)',
        'social_facebook' => 'VARCHAR(255)',
        'social_twitter' => 'VARCHAR(255)',
        'preferred_trainer_gender' => 'VARCHAR(20)',
        'workout_frequency' => 'VARCHAR(50)',
        'nutrition_plan' => 'VARCHAR(100)',
        'supplement_usage' => 'TEXT'
    ];
    
    foreach ($requiredColumns as $column => $type) {
        if (!in_array($column, $columns)) {
            $conn->exec("ALTER TABLE users ADD COLUMN $column $type");
        }
    }
    
    // Get profile data from users table
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        foreach ($profile as $key => $value) {
            if (isset($userData[$key])) {
                $profile[$key] = $userData[$key] ?? '';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Profile error: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        try {
            $updateFields = [];
            $updateValues = [];
            
            // Basic profile fields
            $profileFields = [
                'name', 'phone', 'date_of_birth', 'gender', 'height', 'weight',
                'experience_level', 'fitness_goals', 'preferred_routines',
                'emergency_contact_name', 'emergency_contact_phone', 'medical_conditions',
                'allergies', 'current_medications', 'fitness_level', 'target_weight',
                'body_fat_percentage', 'muscle_mass', 'activity_level', 'sleep_hours',
                'stress_level', 'occupation', 'bio', 'social_instagram',
                'social_facebook', 'social_twitter', 'preferred_trainer_gender',
                'workout_frequency', 'nutrition_plan', 'supplement_usage'
            ];
            
            foreach ($profileFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $_POST[$field] ?: null;
                }
            }
            
            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/profile_images/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileExtension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array(strtolower($fileExtension), $allowedExtensions)) {
                    $newFilename = 'profile_' . $userId . '_' . time() . '.' . $fileExtension;
                    $uploadFile = $uploadDir . $newFilename;
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadFile)) {
                        $profileImagePath = '/uploads/profile_images/' . $newFilename;
                        $updateFields[] = "profile_image = ?";
                        $updateValues[] = $profileImagePath;
                        
                        // Delete old profile image
                        if (!empty($profile['profile_image']) && $profile['profile_image'] !== $profileImagePath) {
                            $oldFilePath = __DIR__ . '/..' . $profile['profile_image'];
                            if (file_exists($oldFilePath)) {
                                unlink($oldFilePath);
                            }
                        }
                        
                        $_SESSION['profile_image'] = $profileImagePath;
                    }
                }
            }
            
            if (!empty($updateFields)) {
                $updateValues[] = $userId;
                $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute($updateValues);
                
                // Update session data
                if (isset($_POST['name'])) {
                    $_SESSION['name'] = $_POST['name'];
                }
                
                $message = 'Profile updated successfully!';
                $messageType = 'success';
                
                // Refresh profile data
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userData) {
                    foreach ($profile as $key => $value) {
                        if (isset($userData[$key])) {
                            $profile[$key] = $userData[$key] ?? '';
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $message = 'Error updating profile: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_settings'])) {
        try {
            $settingsFields = [];
            $settingsValues = [];
            
            // Boolean settings
            $booleanSettings = [
                'email_notifications', 'push_notifications', 'workout_reminders',
                'appointment_reminders', 'progress_sharing', 'show_progress',
                'auto_sync_devices', 'two_factor_auth', 'marketing_emails',
                'workout_music', 'rest_timer_sound', 'voice_coaching',
                'dark_mode_schedule', 'auto_backup', 'share_achievements',
                'weekly_summary', 'goal_reminders', 'social_features',
                'location_tracking', 'calorie_tracking', 'heart_rate_zones',
                'rest_day_reminders', 'nutrition_tracking', 'water_reminders',
                'sleep_tracking'
            ];
            
            foreach ($booleanSettings as $setting) {
                $settingsFields[] = "$setting = ?";
                $settingsValues[] = isset($_POST[$setting]) ? 1 : 0;
            }
            
            // String/numeric settings
            $otherSettings = [
                'theme', 'measurement_unit', 'profile_visibility', 'data_export_format',
                'workout_intensity', 'preferred_workout_time', 'language', 'timezone',
                'date_format', 'time_format', 'currency'
            ];
            
            foreach ($otherSettings as $setting) {
                if (isset($_POST[$setting])) {
                    $settingsFields[] = "$setting = ?";
                    $settingsValues[] = $_POST[$setting];
                }
            }
            
            // Numeric settings
            $numericSettings = ['session_timeout', 'step_goal', 'weekly_workout_goal'];
            
            foreach ($numericSettings as $setting) {
                if (isset($_POST[$setting])) {
                    $settingsFields[] = "$setting = ?";
                    $settingsValues[] = intval($_POST[$setting]);
                }
            }
            
            if (!empty($settingsFields)) {
                $settingsValues[] = $userId;
                $sql = "UPDATE member_settings SET " . implode(', ', $settingsFields) . " WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute($settingsValues);
                
                // Update settings in memory
                foreach ($_POST as $key => $value) {
                    if (array_key_exists($key, $settings)) {
                        if (in_array($key, $booleanSettings)) {
                            $settings[$key] = isset($_POST[$key]);
                        } elseif (in_array($key, $numericSettings)) {
                            $settings[$key] = intval($value);
                        } else {
                            $settings[$key] = $value;
                        }
                    }
                }
                
                $message = 'Settings updated successfully!';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Error updating settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['change_password'])) {
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $message = 'User not found.';
                $messageType = 'error';
            } elseif (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $message = 'All password fields are required.';
                $messageType = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $message = 'New password and confirmation do not match.';
                $messageType = 'error';
            } elseif (strlen($newPassword) < 8) {
                $message = 'New password must be at least 8 characters long.';
                $messageType = 'error';
            } elseif (!password_verify($currentPassword, $user['password'])) {
                $message = 'Current password is incorrect.';
                $messageType = 'error';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$passwordHash, $userId]);
                
                $message = 'Password changed successfully!';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Error changing password: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['export_data'])) {
        try {
            $exportType = $_POST['export_type'] ?? 'json';
            $dataTypes = $_POST['data_types'] ?? [];
            
            $exportData = [];
            
            if (in_array('profile', $dataTypes)) {
                $exportData['profile'] = $profile;
            }
            
            if (in_array('settings', $dataTypes)) {
                $exportData['settings'] = $settings;
            }
            
            if (in_array('workouts', $dataTypes)) {
                // Get workout data (assuming workout tables exist)
                $exportData['workouts'] = [];
            }
            
            if (in_array('progress', $dataTypes)) {
                // Get progress data
                $exportData['progress'] = [];
            }
            
            $filename = 'elitefit_data_' . $userId . '_' . date('Y-m-d_H-i-s');
            
            if ($exportType === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . $filename . '.json"');
                echo json_encode($exportData, JSON_PRETTY_PRINT);
            } elseif ($exportType === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                
                $output = fopen('php://output', 'w');
                foreach ($exportData as $section => $data) {
                    fputcsv($output, [$section]);
                    if (is_array($data)) {
                        foreach ($data as $key => $value) {
                            fputcsv($output, [$key, $value]);
                        }
                    }
                    fputcsv($output, []);
                }
                fclose($output);
            }
            exit;
        } catch (Exception $e) {
            $message = 'Error exporting data: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete_account'])) {
        try {
            $confirmDelete = $_POST['confirm_delete'] ?? '';
            
            if ($confirmDelete !== 'DELETE') {
                $message = 'Please type DELETE to confirm account deletion.';
                $messageType = 'error';
            } else {
                // Delete user data
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                
                session_destroy();
                header("Location: ../login.php?deleted=1");
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error deleting account: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$theme = $settings['theme'];
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
            --primary: #ff8800;
            --primary-dark: #e67700;
            --primary-light: #ffaa33;
            --secondary: #2c2c2c;
            --background: #000000;
            --surface: #111111;
            --surface-variant: #1a1a1a;
            --on-background: #ffffff;
            --on-surface: #e0e0e0;
            --on-surface-variant: #b0b0b0;
            --border: #333333;
            --success: #4caf50;
            --warning: #ff9800;
            --error: #f44336;
            --info: #2196f3;
            --shadow: rgba(255, 136, 0, 0.1);
        }

        [data-theme="light"] {
            --primary: #ff8800;
            --primary-dark: #e67700;
            --primary-light: #ffaa33;
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
            --shadow: rgba(0, 0, 0, 0.1);
        }

        [data-theme="orange"] {
            --primary: #ff8800;
            --primary-dark: #e67700;
            --primary-light: #ffaa33;
            --secondary: #fff3e0;
            --background: #fff9f2;
            --surface: #ffffff;
            --surface-variant: #ffecb3;
            --on-background: #333333;
            --on-surface: #495057;
            --on-surface-variant: #6c757d;
            --border: #ffcb9a;
            --success: #4caf50;
            --warning: #ff9800;
            --error: #f44336;
            --info: #2196f3;
            --shadow: rgba(255, 136, 0, 0.15);
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
            transition: all 0.3s ease;
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

        .sidebar-user {
            display: flex;
            align-items: center;
            padding: 0 2rem;
            margin-bottom: 2rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            border: 2px solid var(--primary);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--on-surface);
        }

        .user-status {
            font-size: 0.8rem;
            color: var(--primary);
            background-color: var(--surface-variant);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-weight: 500;
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

        /* Enhanced Cards */
        .card {
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 8px var(--shadow);
            transition: all 0.2s ease;
        }

        .card:hover {
            box-shadow: 0 4px 16px var(--shadow);
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-variant);
        }

        .card-header h3 {
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

        /* Enhanced Tabs */
        .tabs {
            margin-bottom: 2rem;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .tab-buttons::-webkit-scrollbar {
            display: none;
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
            box-shadow: 0 0 0 3px rgba(255, 136, 0, 0.1);
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            margin-top: 0.25rem;
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
            box-shadow: 0 4px 12px rgba(255, 136, 0, 0.3);
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

        /* Switch Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--border);
            transition: .4s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Theme Selector */
        .theme-selector {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .theme-option {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            cursor: pointer;
            position: relative;
            border: 2px solid var(--border);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 0.25rem;
        }

        .theme-option.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 136, 0, 0.1);
        }

        .theme-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .theme-light {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            color: #333;
        }

        .theme-dark {
            background: linear-gradient(135deg, #000000 0%, #111111 100%);
            color: #fff;
        }

        .theme-orange {
            background: linear-gradient(135deg, #fff9f2 0%, #ffecb3 100%);
            color: #333;
        }

        .theme-option i {
            font-size: 1.25rem;
        }

        .theme-option span {
            font-size: 0.7rem;
            font-weight: 500;
        }

        /* Profile Image Upload */
        .profile-image-upload {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 2rem;
        }

        .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
        }

        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: var(--surface-variant);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--on-surface-variant);
            border: 4px solid var(--border);
        }

        .profile-image-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 3px solid var(--surface);
            transition: all 0.2s ease;
        }

        .profile-image-upload-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .profile-image-upload input[type="file"] {
            display: none;
        }

        /* Settings Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

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

        /* Danger Zone */
        .danger-zone {
            border: 1px solid var(--error);
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
            background: rgba(244, 67, 54, 0.05);
        }

        .danger-zone h4 {
            color: var(--error);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Export Section */
        .export-section {
            background: var(--surface-variant);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .export-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .settings-grid {
                grid-template-columns: 1fr;
            }

            .theme-selector {
                justify-content: center;
            }
        }

        /* Advanced Features */
        .feature-card {
            background: var(--surface-variant);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .feature-card h4 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--surface-variant);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php if (!empty($profileImage)): ?>
                        <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h3><?php echo htmlspecialchars($userName); ?></h3>
                    <span class="user-status">Premium Member</span>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Main</div>
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                    <li><a href="workouts.php"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
                    <li><a href="progress.php"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
                    <li><a href="nutrition.php"><i class="fas fa-apple-alt"></i> <span>Nutrition</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Training</div>
                <ul class="sidebar-menu">
                    <li><a href="appointments.php"><i class="fas fa-calendar-alt"></i> <span>Appointments</span></a></li>
                    <li><a href="trainers.php"><i class="fas fa-user-friends"></i> <span>Trainers</span></a></li>
                    <li><a href="classes.php"><i class="fas fa-users"></i> <span>Classes</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Account</div>
                <ul class="sidebar-menu">
                    <li><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
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
                    <p>Customize your EliteFit experience with comprehensive settings and preferences</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-outline" onclick="exportAllData()">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                    <button class="btn btn-primary" onclick="saveAllSettings()">
                        <i class="fas fa-save"></i> Save All
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

            <!-- Enhanced Settings Tabs -->
            <div class="tabs">
                <div class="tab-buttons">
                    <button class="tab-btn active" data-tab="profile">
                        <i class="fas fa-user-circle"></i> Profile & Health
                    </button>
                    <button class="tab-btn" data-tab="preferences">
                        <i class="fas fa-sliders-h"></i> Preferences
                    </button>
                    <button class="tab-btn" data-tab="notifications">
                        <i class="fas fa-bell"></i> Notifications
                    </button>
                    <button class="tab-btn" data-tab="fitness">
                        <i class="fas fa-heartbeat"></i> Fitness Goals
                    </button>
                    <button class="tab-btn" data-tab="privacy">
                        <i class="fas fa-shield-alt"></i> Privacy & Security
                    </button>
                    <button class="tab-btn" data-tab="data">
                        <i class="fas fa-database"></i> Data Management
                    </button>
                    <button class="tab-btn" data-tab="account">
                        <i class="fas fa-user-shield"></i> Account
                    </button>
                </div>
                
                <!-- Profile & Health Tab -->
                <div class="tab-content active" id="profile-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-circle"></i> Personal Information</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <div class="profile-image-upload">
                                    <?php if (!empty($profile['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($profile['profile_image']); ?>" alt="Profile" class="profile-image">
                                    <?php else: ?>
                                        <div class="profile-image-placeholder">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                    <label for="profile_image" class="profile-image-upload-btn">
                                        <i class="fas fa-camera"></i>
                                    </label>
                                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="name">Full Name</label>
                                        <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($profile['name']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($profile['email']); ?>" disabled>
                                        <div class="form-text">Email cannot be changed. Contact support for assistance.</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="phone">Phone Number</label>
                                        <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($profile['phone']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="date_of_birth">Date of Birth</label>
                                        <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($profile['date_of_birth']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="gender">Gender</label>
                                        <select id="gender" name="gender" class="form-control">
                                            <option value="">-- Select Gender --</option>
                                            <option value="Male" <?php echo $profile['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo $profile['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="Other" <?php echo $profile['gender'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                                            <option value="Prefer not to say" <?php echo $profile['gender'] === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="occupation">Occupation</label>
                                        <input type="text" id="occupation" name="occupation" class="form-control" value="<?php echo htmlspecialchars($profile['occupation']); ?>">
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Health & Fitness Information</h4>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="height">Height (cm)</label>
                                        <input type="number" id="height" name="height" class="form-control" step="0.1" min="0" value="<?php echo htmlspecialchars($profile['height']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="weight">Current Weight (kg)</label>
                                        <input type="number" id="weight" name="weight" class="form-control" step="0.1" min="0" value="<?php echo htmlspecialchars($profile['weight']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="target_weight">Target Weight (kg)</label>
                                        <input type="number" id="target_weight" name="target_weight" class="form-control" step="0.1" min="0" value="<?php echo htmlspecialchars($profile['target_weight']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="body_fat_percentage">Body Fat Percentage (%)</label>
                                        <input type="number" id="body_fat_percentage" name="body_fat_percentage" class="form-control" step="0.1" min="0" max="100" value="<?php echo htmlspecialchars($profile['body_fat_percentage']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="experience_level">Fitness Experience Level</label>
                                        <select id="experience_level" name="experience_level" class="form-control">
                                            <option value="">-- Select Experience Level --</option>
                                            <option value="Beginner" <?php echo $profile['experience_level'] === 'Beginner' ? 'selected' : ''; ?>>Beginner (0-6 months)</option>
                                            <option value="Intermediate" <?php echo $profile['experience_level'] === 'Intermediate' ? 'selected' : ''; ?>>Intermediate (6 months - 2 years)</option>
                                            <option value="Advanced" <?php echo $profile['experience_level'] === 'Advanced' ? 'selected' : ''; ?>>Advanced (2+ years)</option>
                                            <option value="Professional" <?php echo $profile['experience_level'] === 'Professional' ? 'selected' : ''; ?>>Professional/Athlete</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="activity_level">Daily Activity Level</label>
                                        <select id="activity_level" name="activity_level" class="form-control">
                                            <option value="">-- Select Activity Level --</option>
                                            <option value="Sedentary" <?php echo $profile['activity_level'] === 'Sedentary' ? 'selected' : ''; ?>>Sedentary (desk job, no exercise)</option>
                                            <option value="Lightly Active" <?php echo $profile['activity_level'] === 'Lightly Active' ? 'selected' : ''; ?>>Lightly Active (light exercise 1-3 days/week)</option>
                                            <option value="Moderately Active" <?php echo $profile['activity_level'] === 'Moderately Active' ? 'selected' : ''; ?>>Moderately Active (moderate exercise 3-5 days/week)</option>
                                            <option value="Very Active" <?php echo $profile['activity_level'] === 'Very Active' ? 'selected' : ''; ?>>Very Active (hard exercise 6-7 days/week)</option>
                                            <option value="Extremely Active" <?php echo $profile['activity_level'] === 'Extremely Active' ? 'selected' : ''; ?>>Extremely Active (very hard exercise, physical job)</option>
                                        </select>
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Health Information</h4>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="sleep_hours">Average Sleep Hours</label>
                                        <input type="number" id="sleep_hours" name="sleep_hours" class="form-control" min="0" max="24" value="<?php echo htmlspecialchars($profile['sleep_hours']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="stress_level">Stress Level</label>
                                        <select id="stress_level" name="stress_level" class="form-control">
                                            <option value="">-- Select Stress Level --</option>
                                            <option value="Low" <?php echo $profile['stress_level'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                                            <option value="Moderate" <?php echo $profile['stress_level'] === 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                                            <option value="High" <?php echo $profile['stress_level'] === 'High' ? 'selected' : ''; ?>>High</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="medical_conditions">Medical Conditions</label>
                                    <textarea id="medical_conditions" name="medical_conditions" class="form-control" rows="3"><?php echo htmlspecialchars($profile['medical_conditions']); ?></textarea>
                                    <div class="form-text">List any medical conditions that may affect your fitness routine.</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="allergies">Allergies</label>
                                    <textarea id="allergies" name="allergies" class="form-control" rows="2"><?php echo htmlspecialchars($profile['allergies']); ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="current_medications">Current Medications</label>
                                    <textarea id="current_medications" name="current_medications" class="form-control" rows="2"><?php echo htmlspecialchars($profile['current_medications']); ?></textarea>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Emergency Contact</h4>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="emergency_contact_name">Emergency Contact Name</label>
                                        <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control" value="<?php echo htmlspecialchars($profile['emergency_contact_name']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="emergency_contact_phone">Emergency Contact Phone</label>
                                        <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control" value="<?php echo htmlspecialchars($profile['emergency_contact_phone']); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Profile
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Preferences Tab -->
                <div class="tab-content" id="preferences-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-palette"></i> Appearance & Interface</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post" id="preferencesForm">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <div class="form-group">
                                    <label>Theme</label>
                                    <div class="theme-selector">
                                        <div class="theme-option theme-light <?php echo $settings['theme'] === 'light' ? 'active' : ''; ?>" data-theme="light">
                                            <i class="fas fa-sun"></i>
                                            <span>Light</span>
                                        </div>
                                        <div class="theme-option theme-dark <?php echo $settings['theme'] === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                                            <i class="fas fa-moon"></i>
                                            <span>Dark</span>
                                        </div>
                                        <div class="theme-option theme-orange <?php echo $settings['theme'] === 'orange' ? 'active' : ''; ?>" data-theme="orange">
                                            <i class="fas fa-fire"></i>
                                            <span>Orange</span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="theme" id="themeInput" value="<?php echo $settings['theme']; ?>">
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="measurement_unit">Measurement Units</label>
                                        <select id="measurement_unit" name="measurement_unit" class="form-control">
                                            <option value="metric" <?php echo $settings['measurement_unit'] === 'metric' ? 'selected' : ''; ?>>Metric (kg, cm, km)</option>
                                            <option value="imperial" <?php echo $settings['measurement_unit'] === 'imperial' ? 'selected' : ''; ?>>Imperial (lb, ft/in, mi)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="language">Language</label>
                                        <select id="language" name="language" class="form-control">
                                            <option value="en" <?php echo $settings['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="es" <?php echo $settings['language'] === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                            <option value="fr" <?php echo $settings['language'] === 'fr' ? 'selected' : ''; ?>>French</option>
                                            <option value="de" <?php echo $settings['language'] === 'de' ? 'selected' : ''; ?>>German</option>
                                            <option value="it" <?php echo $settings['language'] === 'it' ? 'selected' : ''; ?>>Italian</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="timezone">Timezone</label>
                                        <select id="timezone" name="timezone" class="form-control">
                                            <option value="UTC" <?php echo $settings['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                            <option value="America/New_York" <?php echo $settings['timezone'] === 'America/New_York' ? 'selected' : ''; ?>>Eastern Time</option>
                                            <option value="America/Chicago" <?php echo $settings['timezone'] === 'America/Chicago' ? 'selected' : ''; ?>>Central Time</option>
                                            <option value="America/Denver" <?php echo $settings['timezone'] === 'America/Denver' ? 'selected' : ''; ?>>Mountain Time</option>
                                            <option value="America/Los_Angeles" <?php echo $settings['timezone'] === 'America/Los_Angeles' ? 'selected' : ''; ?>>Pacific Time</option>
                                            <option value="Europe/London" <?php echo $settings['timezone'] === 'Europe/London' ? 'selected' : ''; ?>>London</option>
                                            <option value="Europe/Paris" <?php echo $settings['timezone'] === 'Europe/Paris' ? 'selected' : ''; ?>>Paris</option>
                                            <option value="Asia/Tokyo" <?php echo $settings['timezone'] === 'Asia/Tokyo' ? 'selected' : ''; ?>>Tokyo</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="date_format">Date Format</label>
                                        <select id="date_format" name="date_format" class="form-control">
                                            <option value="Y-m-d" <?php echo $settings['date_format'] === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                            <option value="m/d/Y" <?php echo $settings['date_format'] === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                            <option value="d/m/Y" <?php echo $settings['date_format'] === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                            <option value="M j, Y" <?php echo $settings['date_format'] === 'M j, Y' ? 'selected' : ''; ?>>Month DD, YYYY</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="time_format">Time Format</label>
                                        <select id="time_format" name="time_format" class="form-control">
                                            <option value="24h" <?php echo $settings['time_format'] === '24h' ? 'selected' : ''; ?>>24 Hour</option>
                                            <option value="12h" <?php echo $settings['time_format'] === '12h' ? 'selected' : ''; ?>>12 Hour (AM/PM)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="currency">Currency</label>
                                        <select id="currency" name="currency" class="form-control">
                                            <option value="USD" <?php echo $settings['currency'] === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                            <option value="EUR" <?php echo $settings['currency'] === 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                            <option value="GBP" <?php echo $settings['currency'] === 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                            <option value="CAD" <?php echo $settings['currency'] === 'CAD' ? 'selected' : ''; ?>>CAD ($)</option>
                                            <option value="AUD" <?php echo $settings['currency'] === 'AUD' ? 'selected' : ''; ?>>AUD ($)</option>
                                        </select>
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Audio & Sound Settings</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Workout Music</h4>
                                            <p>Enable background music during workouts</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="workout_music" <?php echo $settings['workout_music'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Rest Timer Sound</h4>
                                            <p>Play sound when rest timer ends</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="rest_timer_sound" <?php echo $settings['rest_timer_sound'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Voice Coaching</h4>
                                            <p>Enable voice guidance during exercises</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="voice_coaching" <?php echo $settings['voice_coaching'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Notifications Tab -->
                <div class="tab-content" id="notifications-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-bell"></i> Notification Settings</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <h4 style="margin-bottom: 1rem; color: var(--primary);">General Notifications</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Email Notifications</h4>
                                            <p>Receive notifications via email</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Push Notifications</h4>
                                            <p>Receive push notifications on your device</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="push_notifications" <?php echo $settings['push_notifications'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Marketing Emails</h4>
                                            <p>Receive promotional emails and offers</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="marketing_emails" <?php echo $settings['marketing_emails'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Workout & Training Reminders</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Workout Reminders</h4>
                                            <p>Get reminded about scheduled workouts</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="workout_reminders" <?php echo $settings['workout_reminders'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Appointment Reminders</h4>
                                            <p>Get reminded about trainer appointments</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="appointment_reminders" <?php echo $settings['appointment_reminders'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Rest Day Reminders</h4>
                                            <p>Get reminded to take rest days</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="rest_day_reminders" <?php echo $settings['rest_day_reminders'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Goal Reminders</h4>
                                            <p>Get reminded about your fitness goals</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="goal_reminders" <?php echo $settings['goal_reminders'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Health & Wellness Reminders</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Water Reminders</h4>
                                            <p>Get reminded to stay hydrated</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="water_reminders" <?php echo $settings['water_reminders'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Weekly Summary</h4>
                                            <p>Receive weekly progress summaries</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="weekly_summary" <?php echo $settings['weekly_summary'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Fitness Goals Tab -->
                <div class="tab-content" id="fitness-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-heartbeat"></i> Fitness Goals & Tracking</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="step_goal">Daily Step Goal</label>
                                        <input type="number" id="step_goal" name="step_goal" class="form-control" min="1000" max="50000" value="<?php echo $settings['step_goal']; ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="weekly_workout_goal">Weekly Workout Goal</label>
                                        <input type="number" id="weekly_workout_goal" name="weekly_workout_goal" class="form-control" min="1" max="14" value="<?php echo $settings['weekly_workout_goal']; ?>">
                                        <div class="form-text">Number of workouts per week</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="workout_intensity">Preferred Workout Intensity</label>
                                        <select id="workout_intensity" name="workout_intensity" class="form-control">
                                            <option value="light" <?php echo $settings['workout_intensity'] === 'light' ? 'selected' : ''; ?>>Light</option>
                                            <option value="moderate" <?php echo $settings['workout_intensity'] === 'moderate' ? 'selected' : ''; ?>>Moderate</option>
                                            <option value="intense" <?php echo $settings['workout_intensity'] === 'intense' ? 'selected' : ''; ?>>Intense</option>
                                            <option value="extreme" <?php echo $settings['workout_intensity'] === 'extreme' ? 'selected' : ''; ?>>Extreme</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="preferred_workout_time">Preferred Workout Time</label>
                                        <select id="preferred_workout_time" name="preferred_workout_time" class="form-control">
                                            <option value="morning" <?php echo $settings['preferred_workout_time'] === 'morning' ? 'selected' : ''; ?>>Morning (6AM - 12PM)</option>
                                            <option value="afternoon" <?php echo $settings['preferred_workout_time'] === 'afternoon' ? 'selected' : ''; ?>>Afternoon (12PM - 6PM)</option>
                                            <option value="evening" <?php echo $settings['preferred_workout_time'] === 'evening' ? 'selected' : ''; ?>>Evening (6PM - 10PM)</option>
                                            <option value="night" <?php echo $settings['preferred_workout_time'] === 'night' ? 'selected' : ''; ?>>Night (10PM - 6AM)</option>
                                        </select>
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Tracking Features</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Calorie Tracking</h4>
                                            <p>Track calories burned during workouts</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="calorie_tracking" <?php echo $settings['calorie_tracking'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Heart Rate Zones</h4>
                                            <p>Monitor heart rate zones during exercise</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="heart_rate_zones" <?php echo $settings['heart_rate_zones'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Nutrition Tracking</h4>
                                            <p>Track your daily nutrition intake</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="nutrition_tracking" <?php echo $settings['nutrition_tracking'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Sleep Tracking</h4>
                                            <p>Monitor your sleep patterns</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="sleep_tracking" <?php echo $settings['sleep_tracking'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Location Tracking</h4>
                                            <p>Track workout locations and routes</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="location_tracking" <?php echo $settings['location_tracking'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Fitness Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Privacy & Security Tab -->
                <div class="tab-content" id="privacy-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-shield-alt"></i> Privacy & Security Settings</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <h4 style="margin-bottom: 1rem; color: var(--primary);">Profile Privacy</h4>
                                
                                <div class="form-group">
                                    <label for="profile_visibility">Profile Visibility</label>
                                    <select id="profile_visibility" name="profile_visibility" class="form-control">
                                        <option value="public" <?php echo $settings['profile_visibility'] === 'public' ? 'selected' : ''; ?>>Public - Anyone can view your profile</option>
                                        <option value="members" <?php echo $settings['profile_visibility'] === 'members' ? 'selected' : ''; ?>>Members Only - Only gym members can view your profile</option>
                                        <option value="trainers" <?php echo $settings['profile_visibility'] === 'trainers' ? 'selected' : ''; ?>>Trainers Only - Only trainers can view your profile</option>
                                        <option value="private" <?php echo $settings['profile_visibility'] === 'private' ? 'selected' : ''; ?>>Private - Only you can view your profile</option>
                                    </select>
                                </div>

                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Show Progress to Trainers</h4>
                                            <p>Allow trainers to view your workout progress</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="show_progress" <?php echo $settings['show_progress'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Progress Sharing</h4>
                                            <p>Allow sharing progress with other members</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="progress_sharing" <?php echo $settings['progress_sharing'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Share Achievements</h4>
                                            <p>Share your fitness achievements publicly</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="share_achievements" <?php echo $settings['share_achievements'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Social Features</h4>
                                            <p>Enable social features and interactions</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="social_features" <?php echo $settings['social_features'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Security Settings</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Two-Factor Authentication</h4>
                                            <p>Add extra security to your account</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="two_factor_auth" <?php echo $settings['two_factor_auth'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Auto Sync Devices</h4>
                                            <p>Automatically sync data from fitness devices</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="auto_sync_devices" <?php echo $settings['auto_sync_devices'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="session_timeout">Session Timeout (minutes)</label>
                                    <input type="number" id="session_timeout" name="session_timeout" class="form-control" min="5" max="120" value="<?php echo $settings['session_timeout']; ?>">
                                    <div class="form-text">How long before you're automatically logged out due to inactivity</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Privacy Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Data Management Tab -->
                <div class="tab-content" id="data-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-database"></i> Data Management</h3>
                        </div>
                        <div class="card-content">
                            <div class="export-section">
                                <h4 style="color: var(--primary); margin-bottom: 1rem;"><i class="fas fa-download"></i> Export Your Data</h4>
                                <p>Download your personal data in various formats for backup or transfer purposes.</p>
                                
                                <form action="settings.php" method="post">
                                    <input type="hidden" name="export_data" value="1">
                                    
                                    <div class="form-group">
                                        <label>Select Data to Export:</label>
                                        <div class="export-options">
                                            <div class="export-option">
                                                <input type="checkbox" id="export_profile" name="data_types[]" value="profile" checked>
                                                <label for="export_profile">Profile Information</label>
                                            </div>
                                            <div class="export-option">
                                                <input type="checkbox" id="export_settings" name="data_types[]" value="settings" checked>
                                                <label for="export_settings">Settings & Preferences</label>
                                            </div>
                                            <div class="export-option">
                                                <input type="checkbox" id="export_workouts" name="data_types[]" value="workouts">
                                                <label for="export_workouts">Workout History</label>
                                            </div>
                                            <div class="export-option">
                                                <input type="checkbox" id="export_progress" name="data_types[]" value="progress">
                                                <label for="export_progress">Progress Data</label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="export_format">Export Format:</label>
                                        <select id="export_format" name="export_type" class="form-control" style="max-width: 200px;">
                                            <option value="json">JSON</option>
                                            <option value="csv">CSV</option>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-download"></i> Export Data
                                    </button>
                                </form>
                            </div>

                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <h4 style="margin: 2rem 0 1rem; color: var(--primary);">Data Settings</h4>
                                
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Auto Backup</h4>
                                            <p>Automatically backup your data regularly</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="auto_backup" <?php echo $settings['auto_backup'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div class="setting-item">
                                        <div class="setting-info">
                                            <h4>Dark Mode Schedule</h4>
                                            <p>Automatically switch to dark mode at night</p>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="dark_mode_schedule" <?php echo $settings['dark_mode_schedule'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="data_export_format">Default Export Format</label>
                                    <select id="data_export_format" name="data_export_format" class="form-control">
                                        <option value="json" <?php echo $settings['data_export_format'] === 'json' ? 'selected' : ''; ?>>JSON</option>
                                        <option value="csv" <?php echo $settings['data_export_format'] === 'csv' ? 'selected' : ''; ?>>CSV</option>
                                        <option value="xml" <?php echo $settings['data_export_format'] === 'xml' ? 'selected' : ''; ?>>XML</option>
                                    </select>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Data Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Account Tab -->
                <div class="tab-content" id="account-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-shield"></i> Account Security</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="change_password" value="1">
                                
                                <h4 style="margin-bottom: 1rem; color: var(--primary);">Change Password</h4>
                                
                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="new_password">New Password</label>
                                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8">
                                        <div class="form-text">Password must be at least 8 characters long</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="confirm_password">Confirm New Password</label>
                                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
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
                    
                    <!-- Account Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-bar"></i> Account Statistics</h3>
                        </div>
                        <div class="card-content">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-value">0</div>
                                    <div class="stat-label">Total Workouts</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">0</div>
                                    <div class="stat-label">Hours Trained</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">0</div>
                                    <div class="stat-label">Calories Burned</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">0</div>
                                    <div class="stat-label">Days Active</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Danger Zone -->
                    <div class="danger-zone">
                        <h4><i class="fas fa-exclamation-triangle"></i> Danger Zone</h4>
                        <p>Once you delete your account, there is no going back. Please be certain.</p>
                        
                        <form action="settings.php" method="post" id="deleteAccountForm">
                            <input type="hidden" name="delete_account" value="1">
                            
                            <div class="form-group">
                                <label for="confirm_delete">Type "DELETE" to confirm</label>
                                <input type="text" id="confirm_delete" name="confirm_delete" class="form-control" required>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-danger" id="deleteAccountBtn">
                                    <i class="fas fa-trash-alt"></i> Delete Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Tab switching
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all buttons and contents
                tabBtns.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Add active class to clicked button and corresponding content
                this.classList.add('active');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // Theme selector
        const themeOptions = document.querySelectorAll('.theme-option');
        const themeInput = document.getElementById('themeInput');
        const html = document.documentElement;
        
        themeOptions.forEach(option => {
            option.addEventListener('click', function() {
                const theme = this.getAttribute('data-theme');
                
                // Remove active class from all options
                themeOptions.forEach(opt => opt.classList.remove('active'));
                
                // Add active class to clicked option
                this.classList.add('active');
                
                // Update hidden input value
                if (themeInput) {
                    themeInput.value = theme;
                }
                
                // Update theme immediately
                html.setAttribute('data-theme', theme);
            });
        });
        
        // Profile image preview
        const profileImageInput = document.getElementById('profile_image');
        if (profileImageInput) {
            profileImageInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        const profileImage = document.querySelector('.profile-image');
                        const profilePlaceholder = document.querySelector('.profile-image-placeholder');
                        
                        if (profileImage) {
                            profileImage.src = e.target.result;
                        } else if (profilePlaceholder) {
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.classList.add('profile-image');
                            profilePlaceholder.parentNode.replaceChild(img, profilePlaceholder);
                        }
                    }
                    
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }
        
        // Password confirmation
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        if (newPasswordInput && confirmPasswordInput) {
            function checkPasswordMatch() {
                if (newPasswordInput.value !== confirmPasswordInput.value) {
                    confirmPasswordInput.setCustomValidity('Passwords do not match');
                } else {
                    confirmPasswordInput.setCustomValidity('');
                }
            }
            
            newPasswordInput.addEventListener('input', checkPasswordMatch);
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        }
        
        // Delete account confirmation
        const deleteAccountForm = document.getElementById('deleteAccountForm');
        if (deleteAccountForm) {
            deleteAccountForm.addEventListener('submit', function(e) {
                const confirmInput = document.getElementById('confirm_delete');
                
                if (confirmInput.value !== 'DELETE') {
                    e.preventDefault();
                    alert('Please type DELETE to confirm account deletion.');
                } else if (!confirm('Are you absolutely sure you want to delete your account? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        }
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Global functions for header buttons
        function exportAllData() {
            // Trigger export with all data types
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'settings.php';
            
            const exportInput = document.createElement('input');
            exportInput.type = 'hidden';
            exportInput.name = 'export_data';
            exportInput.value = '1';
            form.appendChild(exportInput);
            
            const typeInput = document.createElement('input');
            typeInput.type = 'hidden';
            typeInput.name = 'export_type';
            typeInput.value = 'json';
            form.appendChild(typeInput);
            
            ['profile', 'settings', 'workouts', 'progress'].forEach(type => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'data_types[]';
                input.value = type;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        function saveAllSettings() {
            // Save all forms
            const forms = document.querySelectorAll('form[method="post"]');
            let savedCount = 0;
            
            forms.forEach(form => {
                if (form.querySelector('input[name="update_settings"]') || 
                    form.querySelector('input[name="update_profile"]')) {
                    
                    const formData = new FormData(form);
                    
                    fetch('settings.php', {
                        method: 'POST',
                        body: formData
                    }).then(response => {
                        savedCount++;
                        if (savedCount === forms.length) {
                            alert('All settings saved successfully!');
                            location.reload();
                        }
                    }).catch(error => {
                        console.error('Error saving settings:', error);
                    });
                }
            });
        }
        
        // Auto-save functionality
        let autoSaveTimeout;
        const autoSaveInputs = document.querySelectorAll('input, select, textarea');
        
        autoSaveInputs.forEach(input => {
            input.addEventListener('change', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    // Auto-save after 2 seconds of inactivity
                    const form = this.closest('form');
                    if (form && (form.querySelector('input[name="update_settings"]') || 
                                form.querySelector('input[name="update_profile"]'))) {
                        
                        const formData = new FormData(form);
                        
                        fetch('settings.php', {
                            method: 'POST',
                            body: formData
                        }).then(response => {
                            // Show subtle save indicator
                            const indicator = document.createElement('div');
                            indicator.textContent = 'Settings auto-saved';
                            indicator.style.cssText = `
                                position: fixed;
                                top: 20px;
                                right: 20px;
                                background: var(--success);
                                color: white;
                                padding: 0.5rem 1rem;
                                border-radius: 4px;
                                font-size: 0.8rem;
                                z-index: 10000;
                                opacity: 0;
                                transition: opacity 0.3s ease;
                            `;
                            
                            document.body.appendChild(indicator);
                            
                            setTimeout(() => {
                                indicator.style.opacity = '1';
                            }, 100);
                            
                            setTimeout(() => {
                                indicator.style.opacity = '0';
                                setTimeout(() => {
                                    document.body.removeChild(indicator);
                                }, 300);
                            }, 2000);
                        });
                    }
                }, 2000);
            });
        });
        
        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            const currentTheme = '<?php echo $theme; ?>';
            html.setAttribute('data-theme', currentTheme);
        });
    </script>
</body>
</html>
