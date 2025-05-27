<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include theme helper
require_once 'dashboard-theme-helper.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Get theme preference
$theme = getThemePreference($userId);
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Handle form submissions
$message = '';
$messageType = '';

// Update profile
if (isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    if (!empty($name) && !empty($email)) {
        $stmt = $conn->prepare("
            UPDATE users 
            SET name = :name, email = :email, phone = :phone
            WHERE id = :id
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':id', $userId);
        
        if ($stmt->execute()) {
            // Update session data
            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;
            
            $message = "Profile updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating profile.";
            $messageType = "danger";
        }
    } else {
        $message = "Name and email are required.";
        $messageType = "warning";
    }
}

// Change password
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Get current password hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->bindParam(':id', $userId);
    $stmt->execute();
    $currentHash = $stmt->fetchColumn();
    
    // Verify current password
    if (password_verify($currentPassword, $currentHash)) {
        // Check if new passwords match
        if ($newPassword === $confirmPassword) {
            // Check password strength
            if (strlen($newPassword) >= 8) {
                // Hash new password
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update password
                $updateStmt = $conn->prepare("
                    UPDATE users 
                    SET password = :password
                    WHERE id = :id
                ");
                $updateStmt->bindParam(':password', $newHash);
                $updateStmt->bindParam(':id', $userId);
                
                if ($updateStmt->execute()) {
                    $message = "Password changed successfully!";
                    $messageType = "success";
                } else {
                    $message = "Error changing password.";
                    $messageType = "danger";
                }
            } else {
                $message = "Password must be at least 8 characters long.";
                $messageType = "warning";
            }
        } else {
            $message = "New passwords do not match.";
            $messageType = "warning";
        }
    } else {
        $message = "Current password is incorrect.";
        $messageType = "danger";
    }
}

// Update notification settings
if (isset($_POST['update_notifications'])) {
    $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
    $maintenanceReminders = isset($_POST['maintenance_reminders']) ? 1 : 0;
    $inventoryAlerts = isset($_POST['inventory_alerts']) ? 1 : 0;
    
    $stmt = $conn->prepare("
        INSERT INTO user_preferences (user_id, email_notifications, maintenance_reminders, inventory_alerts)
        VALUES (:user_id, :email_notifications, :maintenance_reminders, :inventory_alerts)
        ON DUPLICATE KEY UPDATE 
            email_notifications = :email_notifications,
            maintenance_reminders = :maintenance_reminders,
            inventory_alerts = :inventory_alerts
    ");
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':email_notifications', $emailNotifications);
    $stmt->bindParam(':maintenance_reminders', $maintenanceReminders);
    $stmt->bindParam(':inventory_alerts', $inventoryAlerts);
    
    if ($stmt->execute()) {
        $message = "Notification settings updated successfully!";
        $messageType = "success";
    } else {
        $message = "Error updating notification settings.";
        $messageType = "danger";
    }
}

// Get user data
$stmt = $conn->prepare("
    SELECT u.*, up.email_notifications, up.maintenance_reminders, up.inventory_alerts
    FROM users u
    LEFT JOIN user_preferences up ON u.id = up.user_id
    WHERE u.id = :id
");
$stmt->bindParam(':id', $userId);
$stmt->execute();
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

// Set default values for notification settings
if ($userData['email_notifications'] === null) {
    $userData['email_notifications'] = 1;
}
if ($userData['maintenance_reminders'] === null) {
    $userData['maintenance_reminders'] = 1;
}
if ($userData['inventory_alerts'] === null) {
    $userData['inventory_alerts'] = 1;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff6600;
            --primary-dark: #e65c00;
            --secondary: #333;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 8px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
            min-height: 100vh;
        }
        
        body.dark-theme {
            background-color: #1a1a1a;
            color: #f5f5f5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--secondary);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 10px;
            color: var(--primary);
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 10px;
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all 0.3s;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: var(--primary);
        }
        
        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .dark-theme .header {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .header h1 {
            font-size: 1.8rem;
            color: var(--secondary);
        }
        
        .dark-theme .header h1 {
            color: #f5f5f5;
        }
        
        .user-info {
            display: flex;
            align-items: center;
        }
        
        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .user-info .dropdown {
            position: relative;
        }
        
        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        
        .user-info .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 10px 0;
            min-width: 180px;
            z-index: 1000;
            display: none;
        }
        
        .dark-theme .user-info .dropdown-menu {
            background-color: #2d2d2d;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .user-info .dropdown-menu.show {
            display: block;
        }
        
        .user-info .dropdown-menu a {
            display: block;
            padding: 8px 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .dark-theme .user-info .dropdown-menu a {
            color: #f5f5f5;
        }
        
        .user-info .dropdown-menu a:hover {
            background-color: #f8f9fa;
        }
        
        .dark-theme .user-info .dropdown-menu a:hover {
            background-color: #3d3d3d;
        }
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .dark-theme .card {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dark-theme .card-header {
            border-bottom: 1px solid #3d3d3d;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn {
            padding: 8px 15px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background-color: #444;
        }
        
        .theme-switch {
            display: flex;
            align-items: center;
            margin-right: 20px;
        }
        
        .theme-switch label {
            margin: 0 10px 0 0;
            cursor: pointer;
        }
        
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
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
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
        
        .form-control {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .dark-theme .form-control {
            background-color: #2d2d2d;
            border-color: #3d3d3d;
            color: #f5f5f5;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(255, 102, 0, 0.25);
        }
        
        .form-check-input {
            width: 1.2em;
            height: 1.2em;
            margin-top: 0.15em;
            margin-right: 0.5em;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.25em;
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out;
        }
        
        .dark-theme .form-check-input {
            background-color: #2d2d2d;
            border-color: #3d3d3d;
        }
        
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .form-check-label {
            cursor: pointer;
        }
        
        .alert {
            padding: 10px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .dark-theme .alert-success {
            background-color: #155724;
            color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .dark-theme .alert-danger {
            background-color: #721c24;
            color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .dark-theme .alert-warning {
            background-color: #856404;
            color: #fff3cd;
            border: 1px solid #ffeeba;
        }
        
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .dark-theme .nav-tabs {
            border-bottom: 1px solid #3d3d3d;
        }
        
        .nav-tabs .nav-link {
            margin-bottom: -1px;
            border: 1px solid transparent;
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
            padding: 10px 15px;
            color: var(--secondary);
            transition: all 0.3s;
        }
        
        .dark-theme .nav-tabs .nav-link {
            color: #f5f5f5;
        }
        
        .nav-tabs .nav-link:hover {
            border-color: #e9ecef #e9ecef #dee2e6;
        }
        
        .dark-theme .nav-tabs .nav-link:hover {
            border-color: #3d3d3d #3d3d3d #3d3d3d;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: white;
            border-color: #dee2e6 #dee2e6 white;
        }
        
        .dark-theme .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: #2d2d2d;
            border-color: #3d3d3d #3d3d3d #2d2d2d;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }
            
            .sidebar-header h2, .sidebar-menu a span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .user-info {
                margin-top: 10px;
                align-self: flex-end;
            }
        }
    </style>
</head>
<body class="<?php echo $theme === 'dark' ? 'dark-theme' : ''; ?>">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="equipment.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
                <li><a href="calendar.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a></li>
                <li><a href="report.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>Settings</h1>
                <div class="d-flex align-items-center">
                    <div class="theme-switch">
                        <label for="theme-toggle">
                            <i class="fas fa-moon" style="color: <?php echo $theme === 'dark' ? 'var(--primary)' : '#aaa'; ?>"></i>
                        </label>
                        <label class="switch">
                            <input type="checkbox" id="theme-toggle" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="user-info">
                        <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar">
                        <div class="dropdown">
                            <div class="dropdown-toggle" onclick="toggleDropdown()">
                                <span><?php echo htmlspecialchars($userName); ?></span>
                                <i class="fas fa-chevron-down ml-2"></i>
                            </div>
                            <div class="dropdown-menu" id="userDropdown">
                                <a href="settings.php"><i class="fas fa-user"></i> Profile</a>
                                <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Settings Tabs -->
            <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">Profile</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab" aria-controls="password" aria-selected="false">Password</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button" role="tab" aria-controls="notifications" aria-selected="false">Notifications</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" data-bs-target="#appearance" type="button" role="tab" aria-controls="appearance" aria-selected="false">Appearance</button>
                </li>
            </ul>
            
            <div class="tab-content" id="settingsTabsContent">
                <!-- Profile Tab -->
                <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Profile Information</h3>
                        </div>
                        <div class="card-body">
                            <form action="" method="POST">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($userData['name']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($userData['email']); ?>" required>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="role" class="form-label">Role</label>
                                        <input type="text" class="form-control" id="role" value="Equipment Manager" readonly>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Password Tab -->
                <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Change Password</h3>
                        </div>
                        <div class="card-body">
                            <form action="" method="POST">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <div class="form-text">Password must be at least 8 characters long.</div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Notifications Tab -->
                <div class="tab-pane fade" id="notifications" role="tabpanel" aria-labelledby="notifications-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Notification Settings</h3>
                        </div>
                        <div class="card-body">
                            <form action="" method="POST">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="email_notifications" name="email_notifications" <?php echo $userData['email_notifications'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="email_notifications">Receive email notifications</label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="maintenance_reminders" name="maintenance_reminders" <?php echo $userData['maintenance_reminders'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="maintenance_reminders">Maintenance schedule reminders</label>
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="inventory_alerts" name="inventory_alerts" <?php echo $userData['inventory_alerts'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="inventory_alerts">Low inventory alerts</label>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" name="update_notifications" class="btn btn-primary">Save Preferences</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Appearance Tab -->
                <div class="tab-pane fade" id="appearance" role="tabpanel" aria-labelledby="appearance-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3>Appearance Settings</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <h5>Theme</h5>
                                <p>Choose between light and dark theme for your dashboard.</p>
                                <div class="d-flex align-items-center mt-3">
                                    <div class="form-check me-4">
                                        <input class="form-check-input" type="radio" name="theme_option" id="theme_light" value="light" <?php echo $theme === 'light' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="theme_light">
                                            Light Theme
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="theme_option" id="theme_dark" value="dark" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="theme_dark">
                                            Dark Theme
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Toggle user dropdown
        function toggleDropdown() {
            document.getElementById('userDropdown').classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.dropdown-toggle') && !event.target.matches('.dropdown-toggle *')) {
                var dropdowns = document.getElementsByClassName('dropdown-menu');
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }
        
        // Theme toggle
        document.getElementById('theme-toggle').addEventListener('change', function() {
            const theme = this.checked ? 'dark' : 'light';
            
            // Save theme preference via AJAX
            $.ajax({
                url: 'save-theme.php',
                type: 'POST',
                data: { theme: theme },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Toggle body class
                        document.body.classList.toggle('dark-theme', theme === 'dark');
                        
                        // Update radio buttons
                        if (theme === 'dark') {
                            document.getElementById('theme_dark').checked = true;
                        } else {
                            document.getElementById('theme_light').checked = true;
                        }
                    }
                }
            });
        });
        
        // Theme radio buttons
        document.querySelectorAll('input[name="theme_option"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                const theme = this.value;
                
                // Update toggle switch
                document.getElementById('theme-toggle').checked = (theme === 'dark');
                
                // Save theme preference via AJAX
                $.ajax({
                    url: 'save-theme.php',
                    type: 'POST',
                    data: { theme: theme },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Toggle body class
                            document.body.classList.toggle('dark-theme', theme === 'dark');
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
