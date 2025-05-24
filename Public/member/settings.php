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

// Get user settings
$settings = [
    'theme' => 'light',
    'measurement_unit' => 'metric',
    'email_notifications' => true,
    'push_notifications' => true,
    'workout_reminders' => true,
    'profile_visibility' => 'members',
    'show_progress' => true
];

try {
    // Check if member_settings table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'member_settings'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create member_settings table
        $conn->exec("
            CREATE TABLE member_settings (
                user_id INT PRIMARY KEY,
                theme VARCHAR(20) DEFAULT 'light',
                measurement_unit VARCHAR(20) DEFAULT 'metric',
                email_notifications BOOLEAN DEFAULT TRUE,
                gender ENUM('Male', 'Female', 'Other') DEFAULT NULL,
                push_notifications BOOLEAN DEFAULT TRUE,
                workout_reminders BOOLEAN DEFAULT TRUE,
                profile_visibility VARCHAR(20) DEFAULT 'members',
                show_progress BOOLEAN DEFAULT TRUE,
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
    }
} catch (PDOException $e) {
    // Handle error
}

// Get user profile data
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
    'profile_image' => $profileImage
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
        $profile['date_of_birth'] = $userData['date_of_birth'] ?? '';
        $profile['gender'] = $userData['gender'] ?? '';
        $profile['height'] = $userData['height'] ?? '';
        $profile['weight'] = $userData['weight'] ?? '';
        $profile['experience_level'] = $userData['experience_level'] ?? '';
        $profile['fitness_goals'] = $userData['fitness_goals'] ?? '';
        $profile['preferred_routines'] = $userData['preferred_routines'] ?? '';
        $profile['profile_image'] = $userData['profile_image'] ?? '';
    }
} catch (PDOException $e) {
    // Handle error
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Update profile information
        try {
            $name = $_POST['name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $dateOfBirth = $_POST['date_of_birth'] ?? null;
            $gender = $_POST['gender'] ?? '';
            $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
            $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
            $experienceLevel = $_POST['experience_level'] ?? '';
            $fitnessGoals = $_POST['fitness_goals'] ?? '';
            $preferredRoutines = $_POST['preferred_routines'] ?? '';
            
            // Handle profile image upload
            $profileImagePath = $profile['profile_image'];
            
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
                    $profileImagePath = '/uploads/profile_images/' . $newFilename;
                    
                    // Delete old profile image if exists
                    if (!empty($profile['profile_image']) && $profile['profile_image'] !== $profileImagePath) {
                        $oldFilePath = __DIR__ . '/..' . $profile['profile_image'];
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                        }
                    }
                }
            }
            
            // Update user data
            $stmt = $conn->prepare("
                UPDATE users 
                SET name = ?, phone = ?, date_of_birth = ?, gender = ?, 
                    height = ?, weight = ?, experience_level = ?, 
                    fitness_goals = ?, preferred_routines = ?, profile_image = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $name, $phone, $dateOfBirth, $gender, 
                $height, $weight, $experienceLevel, 
                $fitnessGoals, $preferredRoutines, $profileImagePath, 
                $userId
            ]);
            
            // Update session data
            $_SESSION['name'] = $name;
            $_SESSION['profile_image'] = $profileImagePath;
            
            // Update profile in memory
            $profile['name'] = $name;
            $profile['phone'] = $phone;
            $profile['date_of_birth'] = $dateOfBirth;
            $profile['gender'] = $gender;
            $profile['height'] = $height;
            $profile['weight'] = $weight;
            $profile['experience_level'] = $experienceLevel;
            $profile['fitness_goals'] = $fitnessGoals;
            $profile['preferred_routines'] = $preferredRoutines;
            $profile['profile_image'] = $profileImagePath;
            
            $message = 'Profile updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating profile: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_settings'])) {
        // Update user settings
        try {
            $theme = $_POST['theme'] ?? 'light';
            $measurementUnit = $_POST['measurement_unit'] ?? 'metric';
            $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
            $pushNotifications = isset($_POST['push_notifications']) ? 1 : 0;
            $workoutReminders = isset($_POST['workout_reminders']) ? 1 : 0;
            $profileVisibility = $_POST['profile_visibility'] ?? 'members';
            $showProgress = isset($_POST['show_progress']) ? 1 : 0;
            
            // Update settings
            $stmt = $conn->prepare("
                UPDATE member_settings 
                SET theme = ?, measurement_unit = ?, email_notifications = ?, 
                    push_notifications = ?, workout_reminders = ?, 
                    profile_visibility = ?, show_progress = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $theme, $measurementUnit, $emailNotifications, 
                $pushNotifications, $workoutReminders, 
                $profileVisibility, $showProgress, 
                $userId
            ]);
            
            // Update settings in memory
            $settings['theme'] = $theme;
            $settings['measurement_unit'] = $measurementUnit;
            $settings['email_notifications'] = (bool)$emailNotifications;
            $settings['push_notifications'] = (bool)$pushNotifications;
            $settings['workout_reminders'] = (bool)$workoutReminders;
            $settings['profile_visibility'] = $profileVisibility;
            $settings['show_progress'] = (bool)$showProgress;
            
            $message = 'Settings updated successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['change_password'])) {
        // Change password
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Get current password hash
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
                // Update password
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
    } elseif (isset($_POST['delete_account'])) {
        // Delete account
        try {
            $confirmDelete = $_POST['confirm_delete'] ?? '';
            
            if ($confirmDelete !== 'DELETE') {
                $message = 'Please type DELETE to confirm account deletion.';
                $messageType = 'error';
            } else {
                // Delete user data
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                
                // Destroy session
                session_destroy();
                
                // Redirect to login page
                header("Location: ../login.php?deleted=1");
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error deleting account: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get current theme
$theme = $settings['theme'];
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Inline CSS Styles -->
    <style>
        /* Variables */
        :root {
            /* Light Theme */
            --bg-light: #f8f9fa;
            --card-bg-light: #ffffff;
            --text-light: #333333;
            --text-secondary-light: #6c757d;
            --border-light: #dee2e6;
            --hover-light: #f1f3f5;
            --primary-light: #ff6b00;
            --primary-hover-light: #e05e00;
            --success-light: #28a745;
            --danger-light: #dc3545;
            --warning-light: #ffc107;
            --info-light: #17a2b8;
            
            /* Dark Theme */
            --bg-dark: #121212;
            --card-bg-dark: #1e1e1e;
            --text-dark: #e0e0e0;
            --text-secondary-dark: #adb5bd;
            --border-dark: #333333;
            --hover-dark: #2a2a2a;
            --primary-dark: #ff6b00;
            --primary-hover-dark: #ff8c3f;
            --success-dark: #28a745;
            --danger-dark: #dc3545;
            --warning-dark: #ffc107;
            --info-dark: #17a2b8;
            
            /* Default Theme (Light) */
            --bg: var(--bg-light);
            --card-bg: var(--card-bg-light);
            --text: var(--text-light);
            --text-secondary: var(--text-secondary-light);
            --border: var(--border-light);
            --hover: var(--hover-light);
            --primary: var(--primary-light);
            --primary-hover: var(--primary-hover-light);
            --success: var(--success-light);
            --danger: var(--danger-light);
            --warning: var(--warning-light);
            --info: var(--info-light);
        }
        
        /* Dark Theme */
        html[data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-bg-dark);
            --text: var(--text-dark);
            --text-secondary: var(--text-secondary-dark);
            --border: var(--border-dark);
            --hover: var(--hover-dark);
            --primary: var(--primary-dark);
            --primary-hover: var(--primary-hover-dark);
            --success: var(--success-dark);
            --danger: var(--danger-dark);
            --warning: var(--warning-dark);
            --info: var(--info-dark);
        }
        
        /* Orange Theme */
        html[data-theme="orange"] {
            --bg: #fff9f2;
            --card-bg: #ffffff;
            --text: #333333;
            --text-secondary: #6c757d;
            --border: #ffcb9a;
            --hover: #fff0e0;
            --primary: #ff6b00;
            --primary-hover: #e05e00;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }
        
        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        a:hover {
            color: var(--primary-hover);
        }
        
        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background-color: var(--card-bg);
            border-right: 1px solid var(--border);
            padding: 1.5rem 0;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            transition: transform 0.3s ease, background-color 0.3s ease, border-color 0.3s ease;
            z-index: 100;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            margin-bottom: 2rem;
        }
        
        .sidebar-header i {
            color: var(--primary);
            margin-right: 0.75rem;
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
        }
        
        .sidebar-user {
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            margin-bottom: 2rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary);
            color: white;
            font-weight: 600;
        }
        
        .user-info h3 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .user-status {
            font-size: 0.75rem;
            color: var(--text-secondary);
            background-color: var(--hover);
            padding: 0.125rem 0.5rem;
            border-radius: 0.25rem;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--text);
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .sidebar-menu a:hover {
            background-color: var(--hover);
            color: var(--primary);
        }
        
        .sidebar-menu a.active {
            background-color: var(--hover);
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }
        
        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text);
            font-size: 1.5rem;
            cursor: pointer;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 101;
            transition: color 0.3s ease;
        }
        
        .mobile-menu-toggle:hover {
            color: var(--primary);
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            color: var(--text-secondary);
        }
        
        .header-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        /* Cards */
        .card {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        
        .card-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        
        .card-content {
            padding: 1.5rem;
        }
        
        /* Tabs */
        .tabs {
            margin-bottom: 1.5rem;
        }
        
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            scrollbar-width: none; /* Firefox */
        }
        
        .tab-buttons::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Edge */
        }
        
        .tab-btn {
            padding: 0.75rem 1.25rem;
            border: none;
            background: none;
            color: var(--text);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: color 0.3s ease, border-color 0.3s ease;
            border-bottom: 2px solid transparent;
        }
        
        .tab-btn:hover {
            color: var(--primary);
        }
        
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-btn i {
            margin-right: 0.5rem;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            border: 1px solid var(--border);
            border-radius: 0.375rem;
            background-color: var(--card-bg);
            color: var(--text);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 107, 0, 0.25);
        }
        
        .form-control::placeholder {
            color: var(--text-secondary);
        }
        
        .form-text {
            margin-top: 0.25rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 0.375rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            color: white;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        
        .btn-outline:hover {
            background-color: var(--hover);
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #bd2130;
            color: white;
        }
        
        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }
        
        .btn-icon {
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: transparent;
            color: var(--text);
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .btn-icon:hover {
            background-color: var(--hover);
            color: var(--primary);
        }
        
        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        
        .alert i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .alert-error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .alert .close {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }
        
        .alert .close:hover {
            opacity: 1;
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
        
        input:focus + .slider {
            box-shadow: 0 0 1px var(--primary);
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            position: relative;
            border: 2px solid var(--border);
            transition: border-color 0.3s ease;
        }
        
        .theme-option.active {
            border-color: var(--primary);
        }
        
        .theme-option:hover {
            border-color: var(--primary);
        }
        
        .theme-light {
            background-color: #f8f9fa;
        }
        
        .theme-dark {
            background-color: #121212;
        }
        
        .theme-orange {
            background-color: #fff9f2;
        }
        
        .theme-check {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: var(--primary);
            font-size: 1.25rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .theme-option.active .theme-check {
            opacity: 1;
        }
        
        /* Profile Image Upload */
        .profile-image-upload {
            position: relative;
            width: 120px;
            height: 120px;
            margin-bottom: 1.5rem;
        }
        
        .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
        }
        
        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: var(--hover);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--text-secondary);
            border: 3px solid var(--border);
        }
        
        .profile-image-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
            transition: background-color 0.3s ease;
        }
        
        .profile-image-upload-btn:hover {
            background-color: var(--primary-hover);
        }
        
        .profile-image-upload input[type="file"] {
            display: none;
        }
        
        /* Danger Zone */
        .danger-zone {
            border: 1px solid var(--danger);
            border-radius: 0.375rem;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        
        .danger-zone h4 {
            color: var(--danger);
            margin-bottom: 1rem;
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1.5rem;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                margin-top: 1rem;
                width: 100%;
            }
            
            .tab-buttons {
                flex-wrap: nowrap;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php if (!empty($profileImage)): ?>
                        <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h3><?php echo htmlspecialchars($userName); ?></h3>
                    <span class="user-status">Member</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="workouts.php"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
                <li><a href="progress.php"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
                <li><a href="appointments.php"><i class="fas fa-calendar-alt"></i> <span>Appointments</span></a></li>
                <li><a href="nutrition.php"><i class="fas fa-apple-alt"></i> <span>Nutrition</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-friends"></i> <span>Trainers</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Mobile Menu Toggle -->
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
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
            <div class="tabs">
                <div class="tab-buttons">
                    <button class="tab-btn active" data-tab="profile">
                        <i class="fas fa-user"></i> Profile
                    </button>
                    <button class="tab-btn" data-tab="account">
                        <i class="fas fa-user-shield"></i> Account
                    </button>
                    <button class="tab-btn" data-tab="preferences">
                        <i class="fas fa-sliders-h"></i> Preferences
                    </button>
                    <button class="tab-btn" data-tab="notifications">
                        <i class="fas fa-bell"></i> Notifications
                    </button>
                    <button class="tab-btn" data-tab="privacy">
                        <i class="fas fa-lock"></i> Privacy
                    </button>
                </div>
                
                <!-- Profile Tab -->
                <div class="tab-content active" id="profile-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Profile Information</h3>
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
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="phone">Phone Number</label>
                                        <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($profile['phone']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="date_of_birth">Date of Birth</label>
                                        <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($profile['date_of_birth']); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-grid">
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
                                        <label for="experience_level">Fitness Experience Level</label>
                                        <select id="experience_level" name="experience_level" class="form-control">
                                            <option value="">-- Select Experience Level --</option>
                                            <option value="Beginner" <?php echo $profile['experience_level'] === 'Beginner' ? 'selected' : ''; ?>>Beginner</option>
                                            <option value="Intermediate" <?php echo $profile['experience_level'] === 'Intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                            <option value="Advanced" <?php echo $profile['experience_level'] === 'Advanced' ? 'selected' : ''; ?>>Advanced</option>
                                            <option value="Professional" <?php echo $profile['experience_level'] === 'Professional' ? 'selected' : ''; ?>>Professional</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="height">Height (cm)</label>
                                        <input type="number" id="height" name="height" class="form-control" step="0.1" min="0" value="<?php echo htmlspecialchars($profile['height']); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="weight">Weight (kg)</label>
                                        <input type="number" id="weight" name="weight" class="form-control" step="0.1" min="0" value="<?php echo htmlspecialchars($profile['weight']); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="fitness_goals">Fitness Goals</label>
                                    <textarea id="fitness_goals" name="fitness_goals" class="form-control" rows="3"><?php echo htmlspecialchars($profile['fitness_goals']); ?></textarea>
                                    <div class="form-text">Describe your fitness goals and what you want to achieve.</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="preferred_routines">Preferred Workout Routines</label>
                                    <input type="text" id="preferred_routines" name="preferred_routines" class="form-control" value="<?php echo htmlspecialchars($profile['preferred_routines']); ?>">
                                    <div class="form-text">Enter your preferred workout types separated by commas (e.g., Strength Training, Cardio, Yoga).</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Changes
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
                            <h3>Change Password</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="change_password" value="1">
                                
                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="new_password">New Password</label>
                                        <input type="password" id="new_password" name="new_password" class="form-control" required>
                                        <div class="form-text">Password must be at least 8 characters long.</div>
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
                
                <!-- Preferences Tab -->
                <div class="tab-content" id="preferences-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Appearance</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post" id="preferencesForm">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <div class="form-group">
                                    <label>Theme</label>
                                    <div class="theme-selector">
                                        <div class="theme-option theme-light <?php echo $settings['theme'] === 'light' ? 'active' : ''; ?>" data-theme="light">
                                            <i class="fas fa-check theme-check"></i>
                                        </div>
                                        <div class="theme-option theme-dark <?php echo $settings['theme'] === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                                            <i class="fas fa-check theme-check"></i>
                                        </div>
                                        <div class="theme-option theme-orange <?php echo $settings['theme'] === 'orange' ? 'active' : ''; ?>" data-theme="orange">
                                            <i class="fas fa-check theme-check"></i>
                                        </div>
                                    </div>
                                    <input type="hidden" name="theme" id="themeInput" value="<?php echo $settings['theme']; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="measurement_unit">Measurement Units</label>
                                    <select id="measurement_unit" name="measurement_unit" class="form-control">
                                        <option value="metric" <?php echo $settings['measurement_unit'] === 'metric' ? 'selected' : ''; ?>>Metric (kg, cm, km)</option>
                                        <option value="imperial" <?php echo $settings['measurement_unit'] === 'imperial' ? 'selected' : ''; ?>>Imperial (lb, ft/in, mi)</option>
                                    </select>
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
                            <h3>Notification Settings</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <div class="form-group">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label for="email_notifications">Email Notifications</label>
                                        <label class="switch">
                                            <input type="checkbox" id="email_notifications" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="form-text">Receive notifications about appointments, workouts, and gym updates via email.</div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label for="push_notifications">Push Notifications</label>
                                        <label class="switch">
                                            <input type="checkbox" id="push_notifications" name="push_notifications" <?php echo $settings['push_notifications'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="form-text">Receive push notifications on your device.</div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label for="workout_reminders">Workout Reminders</label>
                                        <label class="switch">
                                            <input type="checkbox" id="workout_reminders" name="workout_reminders" <?php echo $settings['workout_reminders'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="form-text">Receive reminders about your scheduled workouts.</div>
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
                
                <!-- Privacy Tab -->
                <div class="tab-content" id="privacy-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Privacy Settings</h3>
                        </div>
                        <div class="card-content">
                            <form action="settings.php" method="post">
                                <input type="hidden" name="update_settings" value="1">
                                
                                <div class="form-group">
                                    <label for="profile_visibility">Profile Visibility</label>
                                    <select id="profile_visibility" name="profile_visibility" class="form-control">
                                        <option value="public" <?php echo $settings['profile_visibility'] === 'public' ? 'selected' : ''; ?>>Public - Anyone can view your profile</option>
                                        <option value="members" <?php echo $settings['profile_visibility'] === 'members' ? 'selected' : ''; ?>>Members Only - Only gym members can view your profile</option>
                                        <option value="private" <?php echo $settings['profile_visibility'] === 'private' ? 'selected' : ''; ?>>Private - Only you and trainers can view your profile</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label for="show_progress">Show Progress to Trainers</label>
                                        <label class="switch">
                                            <input type="checkbox" id="show_progress" name="show_progress" <?php echo $settings['show_progress'] ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="form-text">Allow trainers to view your workout progress and statistics.</div>
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
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
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
                themeInput.value = theme;
                
                // Update theme
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
                            // Create image element
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.classList.add('profile-image');
                            
                            // Replace placeholder with image
                            profilePlaceholder.parentNode.replaceChild(img, profilePlaceholder);
                        }
                    }
                    
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }
        
        // Delete account confirmation
        const deleteAccountForm = document.getElementById('deleteAccountForm');
        const deleteAccountBtn = document.getElementById('deleteAccountBtn');
        
        if (deleteAccountForm && deleteAccountBtn) {
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
    </script>
</body>
</html>