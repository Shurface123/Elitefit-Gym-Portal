<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
$conn = connectDB();

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';

// Process theme change
if (isset($_POST['update_theme'])) {
    $newTheme = $_POST['theme'];
    setcookie('admin_theme', $newTheme, time() + (86400 * 30), "/"); // 30 days
    $theme = $newTheme;
    
    // Save theme preference to database for persistence across devices
    saveUserPreference($conn, $userId, 'theme', $newTheme);
    
    // Redirect to prevent form resubmission
    header("Location: admin_settings.php?section=appearance&status=success&message=" . urlencode("Theme updated successfully"));
    exit;
}

// Process password change
$passwordMessage = '';
$passwordError = '';

if (isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate input
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $passwordError = "All password fields are required";
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = "New passwords do not match";
    } elseif (strlen($newPassword) < 8) {
        $passwordError = "Password must be at least 8 characters long";
    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $passwordError = "Password must include at least one uppercase letter, one lowercase letter, and one number";
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($currentPassword, $user['password'])) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$hashedPassword, $userId]);
            
            // Log the password change
            logUserActivity($conn, $userId, 'password_change', 'Password changed successfully');
            
            $passwordMessage = "Password updated successfully";
        } else {
            $passwordError = "Current password is incorrect";
        }
    }
}

// Process notification settings
$notificationMessage = '';

if (isset($_POST['update_notifications'])) {
    $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
    $loginAlerts = isset($_POST['login_alerts']) ? 1 : 0;
    $registrationAlerts = isset($_POST['registration_alerts']) ? 1 : 0;
    $systemAlerts = isset($_POST['system_alerts']) ? 1 : 0;
    $maintenanceAlerts = isset($_POST['maintenance_alerts']) ? 1 : 0;
    $billingAlerts = isset($_POST['billing_alerts']) ? 1 : 0;
    
    // Check if settings already exist
    $stmt = $conn->prepare("SELECT id FROM admin_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    if ($stmt->rowCount() > 0) {
        // Update existing settings
        $updateStmt = $conn->prepare("
            UPDATE admin_settings 
            SET email_notifications = ?, 
                login_alerts = ?, 
                registration_alerts = ?,
                system_alerts = ?,
                maintenance_alerts = ?,
                billing_alerts = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $updateStmt->execute([
            $emailNotifications, 
            $loginAlerts, 
            $registrationAlerts,
            $systemAlerts,
            $maintenanceAlerts,
            $billingAlerts,
            $userId
        ]);
    } else {
        // Insert new settings
        $insertStmt = $conn->prepare("
            INSERT INTO admin_settings (
                user_id, 
                email_notifications, 
                login_alerts, 
                registration_alerts,
                system_alerts,
                maintenance_alerts,
                billing_alerts,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insertStmt->execute([
            $userId, 
            $emailNotifications, 
            $loginAlerts, 
            $registrationAlerts,
            $systemAlerts,
            $maintenanceAlerts,
            $billingAlerts
        ]);
    }
    
    $notificationMessage = "Notification settings updated successfully";
}

// Process display settings
$displayMessage = '';

if (isset($_POST['update_display'])) {
    $itemsPerPage = intval($_POST['items_per_page']);
    $defaultView = $_POST['default_view'];
    $dashboardLayout = $_POST['dashboard_layout'];
    
    // Update display settings
    $stmt = $conn->prepare("SELECT id FROM admin_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    if ($stmt->rowCount() > 0) {
        $updateStmt = $conn->prepare("
            UPDATE admin_settings 
            SET items_per_page = ?, 
                default_view = ?, 
                dashboard_layout = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $updateStmt->execute([$itemsPerPage, $defaultView, $dashboardLayout, $userId]);
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO admin_settings (
                user_id, 
                items_per_page, 
                default_view, 
                dashboard_layout,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $insertStmt->execute([$userId, $itemsPerPage, $defaultView, $dashboardLayout]);
    }
    
    $displayMessage = "Display settings updated successfully";
}

// Process API settings
$apiMessage = '';
$apiError = '';

if (isset($_POST['generate_api_key'])) {
    // Generate a new API key
    $apiKey = bin2hex(random_bytes(16));
    $hashedApiKey = password_hash($apiKey, PASSWORD_DEFAULT);
    
    try {
        // Check if api_keys table exists
        $tableExists = false;
        try {
            $checkTable = $conn->query("SHOW TABLES LIKE 'api_keys'");
            $tableExists = $checkTable->rowCount() > 0;
        } catch (PDOException $e) {
            // Table doesn't exist
        }
        
        if ($tableExists) {
            // Save to database
            $stmt = $conn->prepare("SELECT id FROM api_keys WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            if ($stmt->rowCount() > 0) {
                $updateStmt = $conn->prepare("
                    UPDATE api_keys 
                    SET api_key = ?, 
                        updated_at = NOW() 
                    WHERE user_id = ?
                ");
                $updateStmt->execute([$hashedApiKey, $userId]);
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO api_keys (
                        user_id, 
                        api_key, 
                        created_at, 
                        updated_at
                    ) VALUES (?, ?, NOW(), NOW())
                ");
                $insertStmt->execute([$userId, $hashedApiKey]);
            }
            
            $apiMessage = "API key generated successfully. Your new API key is: <code>$apiKey</code><br>Please save this key as it won't be shown again.";
        } else {
            $apiMessage = "API key generated: <code>$apiKey</code><br>Please save this key. Note: API functionality is not fully set up yet.";
        }
    } catch (PDOException $e) {
        $apiError = "Error generating API key. Please try again later.";
    }
}

// Get current settings
$stmt = $conn->prepare("SELECT * FROM admin_settings WHERE user_id = ?");
$stmt->execute([$userId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Default values if settings don't exist
$emailNotifications = $settings['email_notifications'] ?? 1;
$loginAlerts = $settings['login_alerts'] ?? 1;
$registrationAlerts = $settings['registration_alerts'] ?? 1;
$systemAlerts = $settings['system_alerts'] ?? 1;
$maintenanceAlerts = $settings['maintenance_alerts'] ?? 1;
$billingAlerts = $settings['billing_alerts'] ?? 1;
$itemsPerPage = $settings['items_per_page'] ?? 10;
$defaultView = $settings['default_view'] ?? 'grid';
$dashboardLayout = $settings['dashboard_layout'] ?? 'default';

// Get active section from URL
$section = isset($_GET['section']) ? $_GET['section'] : 'appearance';

// Helper functions
function saveUserPreference($conn, $userId, $key, $value) {
    try {
        $stmt = $conn->prepare("SELECT id FROM user_preferences WHERE user_id = ? AND preference_key = ?");
        $stmt->execute([$userId, $key]);
        
        if ($stmt->rowCount() > 0) {
            $updateStmt = $conn->prepare("
                UPDATE user_preferences 
                SET preference_value = ?, updated_at = NOW() 
                WHERE user_id = ? AND preference_key = ?
            ");
            $updateStmt->execute([$value, $userId, $key]);
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO user_preferences (user_id, preference_key, preference_value, created_at, updated_at) 
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->execute([$userId, $key, $value]);
        }
    } catch (PDOException $e) {
        // Silently fail if the table doesn't exist yet
    }
}

function logUserActivity($conn, $userId, $action, $description) {
    try {
        $insertStmt = $conn->prepare("
            INSERT INTO user_activity_logs (user_id, action, description, ip_address, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insertStmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR']]);
    } catch (PDOException $e) {
        // Silently fail if the table doesn't exist yet
    }
}

// Get recent activity logs
$activityLogs = [];
try {
    $stmt = $conn->prepare("
        SELECT action, description, ip_address, created_at 
        FROM user_activity_logs 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table doesn't exist yet or other database error
    // Just continue with empty activity logs
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Primary Colors */
            --primary: #f97316; /* Orange-500 */
            --primary-light: #fb923c; /* Orange-400 */
            --primary-dark: #ea580c; /* Orange-600 */
            --primary-50: #fff7ed;
            --primary-100: #ffedd5;
            --primary-200: #fed7aa;
            --primary-300: #fdba74;
            --primary-400: #fb923c;
            --primary-500: #f97316;
            --primary-600: #ea580c;
            --primary-700: #c2410c;
            --primary-800: #9a3412;
            --primary-900: #7c2d12;
            
            /* Secondary Colors */
            --secondary: #0f172a; /* Slate-900 */
            --secondary-light: #1e293b; /* Slate-800 */
            --secondary-dark: #0f172a; /* Slate-900 */
            
            /* Neutral Colors */
            --neutral-50: #f8fafc;
            --neutral-100: #f1f5f9;
            --neutral-200: #e2e8f0;
            --neutral-300: #cbd5e1;
            --neutral-400: #94a3b8;
            --neutral-500: #64748b;
            --neutral-600: #475569;
            --neutral-700: #334155;
            --neutral-800: #1e293b;
            --neutral-900: #0f172a;
            
            /* Utility Colors */
            --success: #10b981; /* Emerald-500 */
            --success-light: #34d399; /* Emerald-400 */
            --success-dark: #059669; /* Emerald-600 */
            
            --danger: #ef4444; /* Red-500 */
            --danger-light: #f87171; /* Red-400 */
            --danger-dark: #dc2626; /* Red-600 */
            
            --warning: #f59e0b; /* Amber-500 */
            --warning-light: #fbbf24; /* Amber-400 */
            --warning-dark: #d97706; /* Amber-600 */
            
            --info: #0ea5e9; /* Sky-500 */
            --info-light: #38bdf8; /* Sky-400 */
            --info-dark: #0284c7; /* Sky-600 */
            
            /* UI Variables */
            --border-radius-sm: 0.25rem;
            --border-radius: 0.5rem;
            --border-radius-md: 0.75rem;
            --border-radius-lg: 1rem;
            --border-radius-xl: 1.5rem;
            --border-radius-2xl: 2rem;
            --border-radius-full: 9999px;
            
            --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            --transition-speed: 0.3s;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --shadow-inner: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
            
            /* Light theme variables */
            --bg-light: var(--neutral-50);
            --text-light: var(--neutral-900);
            --text-light-secondary: var(--neutral-600);
            --card-light: #ffffff;
            --border-light: var(--neutral-200);
            --sidebar-light: #ffffff;
            --sidebar-text-light: var(--neutral-800);
            --sidebar-hover-light: var(--neutral-100);
            --input-bg-light: #ffffff;
            --input-border-light: var(--neutral-300);
            --header-light: #ffffff;
            --header-border-light: var(--neutral-200);
            --active-light: var(--primary-50);
            --active-border-light: var(--primary-500);
            
            /* Dark theme variables */
            --bg-dark: var(--neutral-900);
            --text-dark: var(--neutral-100);
            --text-dark-secondary: var(--neutral-400);
            --card-dark: var(--neutral-800);
            --border-dark: var(--neutral-700);
            --sidebar-dark: var(--neutral-800);
            --sidebar-text-dark: var(--neutral-100);
            --sidebar-hover-dark: var(--neutral-700);
            --input-bg-dark: var(--neutral-800);
            --input-border-dark: var(--neutral-600);
            --header-dark: var(--neutral-800);
            --header-border-dark: var(--neutral-700);
            --active-dark: rgba(249, 115, 22, 0.2);
            --active-border-dark: var(--primary-500);
        }
        
        [data-theme="light"] {
            --bg-color: var(--bg-light);
            --text-color: var(--text-light);
            --text-secondary: var(--text-light-secondary);
            --card-bg: var(--card-light);
            --border-color: var(--border-light);
            --sidebar-bg: var(--sidebar-light);
            --sidebar-text: var(--sidebar-text-light);
            --sidebar-hover: var(--sidebar-hover-light);
            --input-bg: var(--input-bg-light);
            --input-border: var(--input-border-light);
            --header-bg: var(--header-light);
            --header-border: var(--header-border-light);
            --active-bg: var(--active-light);
            --active-border: var(--active-border-light);
        }
        
        [data-theme="dark"] {
            --bg-color: var(--bg-dark);
            --text-color: var(--text-dark);
            --text-secondary: var(--text-dark-secondary);
            --card-bg: var(--card-dark);
            --border-color: var(--border-dark);
            --sidebar-bg: var(--sidebar-dark);
            --sidebar-text: var(--sidebar-text-dark);
            --sidebar-hover: var(--sidebar-hover-dark);
            --input-bg: var(--input-bg-dark);
            --input-border: var(--input-border-dark);
            --header-bg: var(--header-dark);
            --header-border: var(--header-border-dark);
            --active-bg: var(--active-dark);
            --active-border: var(--active-border-dark);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: var(--font-family);
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color var(--transition-speed) ease, 
                        color var(--transition-speed) ease;
            line-height: 1.6;
            font-size: 0.95rem;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            padding: 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all var(--transition-speed) ease;
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            box-shadow: var(--shadow);
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 0.75rem;
            color: var(--primary);
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.875rem 1rem;
            color: var(--sidebar-text);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed) ease;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-menu a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background-color: var(--primary);
            transform: scaleY(0);
            transition: transform 0.2s ease;
        }
        
        .sidebar-menu a:hover::before,
        .sidebar-menu a.active::before {
            transform: scaleY(1);
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: var(--sidebar-hover);
            color: var(--primary);
        }
        
        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 1.25rem;
            text-align: center;
            font-size: 1.1rem;
            transition: transform 0.3s ease;
        }
        
        .sidebar-menu a:hover i,
        .sidebar-menu a.active i {
            transform: scale(1.1);
        }
        
        .sidebar-section {
            margin-bottom: 1.5rem;
        }
        
        .sidebar-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
            padding-left: 1rem;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            transition: margin-left var(--transition-speed) ease;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background-color: var(--header-bg);
            padding: 1.25rem 1.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            transition: box-shadow var(--transition-speed) ease;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--header-border);
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
        }
        
        .header:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .header h1 {
            font-size: 1.75rem;
            color: var(--text-color);
            font-weight: 700;
            letter-spacing: -0.025em;
            margin-bottom: 0.25rem;
        }
        
        .header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
        }
        
        .user-info img {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            margin-right: 0.75rem;
            object-fit: cover;
            border: 2px solid var(--primary);
            transition: transform 0.3s ease, border-color 0.3s ease;
        }
        
        .user-info img:hover {
            transform: scale(1.05);
            border-color: var(--primary-light);
        }
        
        .user-info .dropdown {
            position: relative;
        }
        
        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            color: var(--text-color);
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            transition: background-color 0.3s ease;
        }
        
        .user-info .dropdown-toggle:hover {
            background-color: var(--sidebar-hover);
        }
        
        .user-info .dropdown-toggle i {
            margin-left: 0.5rem;
            transition: transform 0.3s ease;
        }
        
        .user-info .dropdown-toggle:hover i {
            transform: rotate(180deg);
        }
        
        .user-info .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            padding: 0.5rem 0;
            min-width: 200px;
            z-index: 1000;
            display: none;
            border: 1px solid var(--border-color);
            transform-origin: top right;
            transform: scale(0.95);
            opacity: 0;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }
        
        .user-info .dropdown-menu.show {
            display: block;
            transform: scale(1);
            opacity: 1;
        }
        
        .user-info .dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .user-info .dropdown-menu a i {
            margin-right: 0.75rem;
            width: 1.25rem;
            text-align: center;
            color: var(--primary);
        }
        
        .user-info .dropdown-menu a:hover {
            background-color: var(--sidebar-hover);
            padding-left: 1.5rem;
        }
        
        .settings-nav {
            display: flex;
            background-color: var(--card-bg);
            border-radius: var(--border-radius-lg);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }
        
        .settings-nav-item {
            flex: 1;
            text-align: center;
            padding: 1rem;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        
        .settings-nav-item i {
            font-size: 1.25rem;
            color: var(--text-secondary);
            transition: color 0.3s, transform 0.3s;
        }
        
        .settings-nav-item:hover i,
        .settings-nav-item.active i {
            color: var(--primary);
            transform: scale(1.1);
        }
        
        .settings-nav-item:hover,
        .settings-nav-item.active {
            background-color: var(--active-bg);
            border-bottom-color: var(--primary);
            color: var(--primary);
        }
        
        .card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), transparent);
            opacity: 0.7;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .settings-section {
            margin-bottom: 2rem;
        }
        
        .settings-section:last-child {
            margin-bottom: 0;
        }
        
        .settings-section h3 {
            display: flex;
            align-items: center;
            font-size: 1.25rem;
            margin-bottom: 1.25rem;
            color: var(--text-color);
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
        }
        
        .settings-section h3 i {
            margin-right: 0.75rem;
            color: var(--primary);
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group:last-child {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text-color);
            transition: all 0.3s;
            font-size: 0.95rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
        }
        
        .form-control::placeholder {
            color: var(--text-secondary);
            opacity: 0.7;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            cursor: pointer;
        }
        
        .form-check:last-child {
            margin-bottom: 0;
        }
        
        .form-check input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid var(--input-border);
            border-radius: var(--border-radius-sm);
            margin-right: 0.75rem;
            position: relative;
            cursor: pointer;
            background-color: var(--input-bg);
            transition: all 0.2s ease;
        }
        
        .form-check input[type="checkbox"]:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .form-check input[type="checkbox"]:checked::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.7rem;
        }
        
        .form-check input[type="checkbox"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
        }
        
        .form-check label {
            margin-bottom: 0;
            font-weight: 400;
            user-select: none;
        }
        
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text-color);
            transition: all 0.3s;
            font-size: 0.95rem;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
        }
        
        .btn {
            padding: 0.75rem 1.25rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            font-size: 0.95rem;
        }
        
        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .btn:hover::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn:active {
            transform: translateY(0);
            box-shadow: var(--shadow);
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        .btn-secondary {
            background-color: var(--neutral-600);
        }
        
        .btn-secondary:hover {
            background-color: var(--neutral-700);
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: var(--success-dark);
        }
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: var(--danger-dark);
        }
        
        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .theme-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .theme-option {
            aspect-ratio: 1/1;
            border-radius: var(--border-radius-lg);
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .theme-option.light {
            background-color: #f8fafc;
            color: #0f172a;
        }
        
        .theme-option.dark {
            background-color: #0f172a;
            color: #f8fafc;
        }
        
        .theme-option.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.2);
        }
        
        .theme-preview {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .theme-preview-header {
            height: 20%;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .theme-preview-body {
            flex: 1;
            display: flex;
        }
        
        .theme-preview-sidebar {
            width: 30%;
            height: 100%;
            padding: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .theme-preview-content {
            width: 70%;
            height: 100%;
            padding: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .theme-option.light .theme-preview-sidebar {
            background-color: #ffffff;
            border-right: 1px solid #e2e8f0;
        }
        
        .theme-option.light .theme-preview-content {
            background-color: #f8fafc;
        }
        
        .theme-option.dark .theme-preview-sidebar {
            background-color: #1e293b;
            border-right: 1px solid #334155;
        }
        
        .theme-option.dark .theme-preview-content {
            background-color: #0f172a;
        }
        
        .theme-preview-menu-item {
            height: 0.5rem;
            border-radius: 2px;
            margin-bottom: 0.25rem;
        }
        
        .theme-option.light .theme-preview-menu-item {
            background-color: #f1f5f9;
        }
        
        .theme-option.dark .theme-preview-menu-item {
            background-color: #334155;
        }
        
        .theme-preview-card {
            height: 1rem;
            border-radius: 4px;
            margin-bottom: 0.25rem;
        }
        
        .theme-option.light .theme-preview-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
        }
        
        .theme-option.dark .theme-preview-card {
            background-color: #1e293b;
            border: 1px solid #334155;
        }
        
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
        }
        
        .alert i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
            color: var(--success);
        }
        
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
            color: var(--danger);
        }
        
        .alert-warning {
            background-color: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.3);
            color: var(--warning);
        }
        
        .alert-info {
            background-color: rgba(14, 165, 233, 0.1);
            border-color: rgba(14, 165, 233, 0.3);
            color: var(--info);
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }
        
        .activity-log {
            list-style: none;
            padding: 0;
        }
        
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: flex-start;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .activity-icon.login {
            background-color: rgba(14, 165, 233, 0.1);
            color: var(--info);
        }
        
        .activity-icon.password {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .activity-icon.settings {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .api-key-container {
            background-color: var(--active-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            position: relative;
        }
        
        .api-key-container code {
            font-family: monospace;
            word-break: break-all;
            color: var(--primary);
            background-color: rgba(249, 115, 22, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: var(--border-radius-sm);
        }
        
        .password-strength {
            height: 5px;
            border-radius: var(--border-radius-full);
            margin-top: 0.5rem;
            background-color: var(--neutral-200);
            overflow: hidden;
        }
        
        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .password-strength-text {
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .password-strength-weak .password-strength-meter {
            width: 25%;
            background-color: var(--danger);
        }
        
        .password-strength-medium .password-strength-meter {
            width: 50%;
            background-color: var(--warning);
        }
        
        .password-strength-good .password-strength-meter {
            width: 75%;
            background-color: var(--info);
        }
        
        .password-strength-strong .password-strength-meter {
            width: 100%;
            background-color: var(--success);
        }
        
        @media (max-width: 1200px) {
            .grid-3 {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                width: 240px;
            }
            
            .main-content {
                margin-left: 240px;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 1.5rem 0.75rem;
                transform: translateX(0);
            }
            
            .sidebar.expanded {
                width: 240px;
                transform: translateX(0);
            }
            
            .sidebar-header h2,
            .sidebar-menu a span,
            .sidebar-section-title {
                display: none;
            }
            
            .sidebar.expanded .sidebar-header h2,
            .sidebar.expanded .sidebar-menu a span,
            .sidebar.expanded .sidebar-section-title {
                display: inline;
            }
            
            .main-content {
                margin-left: 70px;
                padding: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .user-info {
                margin-top: 1rem;
                width: 100%;
                justify-content: space-between;
            }
            
            .settings-nav {
                flex-direction: column;
            }
            
            .settings-nav-item {
                flex-direction: row;
                justify-content: flex-start;
                border-bottom: 1px solid var(--border-color);
                border-left: 3px solid transparent;
            }
            
            .settings-nav-item:last-child {
                border-bottom: none;
            }
            
            .settings-nav-item.active {
                border-bottom-color: var(--border-color);
                border-left-color: var(--primary);
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 1rem;
            }
            
            .header {
                padding: 1rem;
            }
            
            .card {
                padding: 1rem;
            }
            
            .theme-options {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1100;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            width: 2.5rem;
            height: 2.5rem;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow-md);
        }
        
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding-top: 4rem;
            }
        }
    </style>
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
                    <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
                    <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Management</div>
                <ul class="sidebar-menu">
                    <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                    <li><a href="memberships.php"><i class="fas fa-id-card"></i> <span>Memberships</span></a></li>
                    <li><a href="classes.php"><i class="fas fa-calendar-alt"></i> <span>Classes</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Analytics</div>
                <ul class="sidebar-menu">
                    <li><a href="reports.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                    <li><a href="analytics.php"><i class="fas fa-chart-pie"></i> <span>Analytics</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">System</div>
                <ul class="sidebar-menu">
                    <li><a href="admin_settings.php" class="active"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Admin Settings</h1>
                    <p>Customize and configure your admin dashboard experience</p>
                </div>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/women/1.jpg" alt="User Avatar">
                    <div class="dropdown">
                        <div class="dropdown-toggle" onclick="toggleDropdown()">
                            <span><?php echo htmlspecialchars($userName); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="dropdown-menu" id="userDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                            <a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a>
                            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Settings Navigation -->
            <div class="settings-nav">
                <a href="?section=appearance" class="settings-nav-item <?php echo $section === 'appearance' ? 'active' : ''; ?>">
                    <i class="fas fa-palette"></i>
                    <span>Appearance</span>
                </a>
                <a href="?section=security" class="settings-nav-item <?php echo $section === 'security' ? 'active' : ''; ?>">
                    <i class="fas fa-lock"></i>
                    <span>Security</span>
                </a>
                <a href="?section=notifications" class="settings-nav-item <?php echo $section === 'notifications' ? 'active' : ''; ?>">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
                <a href="?section=display" class="settings-nav-item <?php echo $section === 'display' ? 'active' : ''; ?>">
                    <i class="fas fa-desktop"></i>
                    <span>Display</span>
                </a>
                <a href="?section=api" class="settings-nav-item <?php echo $section === 'api' ? 'active' : ''; ?>">
                    <i class="fas fa-code"></i>
                    <span>API</span>
                </a>
                <a href="?section=activity" class="settings-nav-item <?php echo $section === 'activity' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span>Activity</span>
                </a>
            </div>
            
            <?php if (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($_GET['message'] ?? 'Settings updated successfully'); ?></div>
                </div>
            <?php endif; ?>
            
            <!-- Appearance Settings -->
            <?php if ($section === 'appearance'): ?>
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-palette"></i> Theme Settings</h3>
                        
                        <form action="" method="post">
                            <div class="theme-options">
                                <div class="theme-option light <?php echo $theme === 'light' ? 'active' : ''; ?>" onclick="selectTheme('light')">
                                    <div class="theme-preview">
                                        <div class="theme-preview-header">Light Theme</div>
                                        <div class="theme-preview-body">
                                            <div class="theme-preview-sidebar">
                                                <div class="theme-preview-menu-item"></div>
                                                <div class="theme-preview-menu-item"></div>
                                                <div class="theme-preview-menu-item"></div>
                                            </div>
                                            <div class="theme-preview-content">
                                                <div class="theme-preview-card"></div>
                                                <div class="theme-preview-card"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="theme-option dark <?php echo $theme === 'dark' ? 'active' : ''; ?>" onclick="selectTheme('dark')">
                                    <div class="theme-preview">
                                        <div class="theme-preview-header">Dark Theme</div>
                                        <div class="theme-preview-body">
                                            <div class="theme-preview-sidebar">
                                                <div class="theme-preview-menu-item"></div>
                                                <div class="theme-preview-menu-item"></div>
                                                <div class="theme-preview-menu-item"></div>
                                            </div>
                                            <div class="theme-preview-content">
                                                <div class="theme-preview-card"></div>
                                                <div class="theme-preview-card"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <input type="hidden" name="theme" id="themeInput" value="<?php echo $theme; ?>">
                            <button type="submit" name="update_theme" class="btn">
                                <i class="fas fa-save"></i> Save Theme
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Security Settings -->
            <?php if ($section === 'security'): ?>
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-lock"></i> Change Password</h3>
                        
                        <?php if (!empty($passwordMessage)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <div><?php echo htmlspecialchars($passwordMessage); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($passwordError)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <div><?php echo htmlspecialchars($passwordError); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <form action="" method="post">
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-control" required onkeyup="checkPasswordStrength()">
                                <div class="password-strength">
                                    <div class="password-strength-meter" id="passwordStrengthMeter"></div>
                                </div>
                                <div class="password-strength-text" id="passwordStrengthText"></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required onkeyup="checkPasswordMatch()">
                                <div id="passwordMatchMessage" style="font-size: 0.875rem; margin-top: 0.5rem;"></div>
                            </div>
                            
                            <button type="submit" name="update_password" class="btn">
                                <i class="fas fa-key"></i> Update Password
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-shield-alt"></i> Two-Factor Authentication</h3>
                        
                        <p style="margin-bottom: 1rem;">Enhance your account security by enabling two-factor authentication.</p>
                        
                        <button class="btn btn-secondary">
                            <i class="fas fa-qrcode"></i> Setup Two-Factor Authentication
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Notification Settings -->
            <?php if ($section === 'notifications'): ?>
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-bell"></i> Notification Settings</h3>
                        
                        <?php if (!empty($notificationMessage)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <div><?php echo htmlspecialchars($notificationMessage); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <form action="" method="post">
                            <div class="grid-2">
                                <div>
                                    <div class="form-check">
                                        <input type="checkbox" id="email_notifications" name="email_notifications" <?php echo $emailNotifications ? 'checked' : ''; ?>>
                                        <label for="email_notifications">Email Notifications</label>
                                    </div>
                                    <p style="margin-left: 2rem; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        Receive notifications via email for important updates
                                    </p>
                                    
                                    <div class="form-check">
                                        <input type="checkbox" id="login_alerts" name="login_alerts" <?php echo $loginAlerts ? 'checked' : ''; ?>>
                                        <label for="login_alerts">Login Alerts</label>
                                    </div>
                                    <p style="margin-left: 2rem; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        Get notified when someone logs into your account
                                    </p>
                                    
                                    <div class="form-check">
                                        <input type="checkbox" id="registration_alerts" name="registration_alerts" <?php echo $registrationAlerts ? 'checked' : ''; ?>>
                                        <label for="registration_alerts">New Registration Alerts</label>
                                    </div>
                                    <p style="margin-left: 2rem; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        Receive alerts when new users register
                                    </p>
                                </div>
                                
                                <div>
                                    <div class="form-check">
                                        <input type="checkbox" id="system_alerts" name="system_alerts" <?php echo $systemAlerts ? 'checked' : ''; ?>>
                                        <label for="system_alerts">System Alerts</label>
                                    </div>
                                    <p style="margin-left: 2rem; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        Get notified about system updates and maintenance
                                    </p>
                                    
                                    <div class="form-check">
                                        <input type="checkbox" id="maintenance_alerts" name="maintenance_alerts" <?php echo $maintenanceAlerts ? 'checked' : ''; ?>>
                                        <label for="maintenance_alerts">Maintenance Alerts</label>
                                    </div>
                                    <p style="margin-left: 2rem; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        Receive notifications about scheduled maintenance
                                    </p>
                                    
                                    <div class="form-check">
                                        <input type="checkbox" id="billing_alerts" name="billing_alerts" <?php echo $billingAlerts ? 'checked' : ''; ?>>
                                        <label for="billing_alerts">Billing Alerts</label>
                                    </div>
                                    <p style="margin-left: 2rem; margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        Get notified about billing and payment updates
                                    </p>
                                </div>
                            </div>
                            
                            <button type="submit" name="update_notifications" class="btn">
                                <i class="fas fa-save"></i> Save Notification Settings
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Display Settings -->
            <?php if ($section === 'display'): ?>
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-desktop"></i> Display Settings</h3>
                        
                        <?php if (!empty($displayMessage)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <div><?php echo htmlspecialchars($displayMessage); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <form action="" method="post">
                            <div class="grid-2">
                                <div class="form-group">
                                    <label for="items_per_page">Items Per Page</label>
                                    <select name="items_per_page" id="items_per_page" class="form-select">
                                        <option value="10" <?php echo $itemsPerPage == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="25" <?php echo $itemsPerPage == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $itemsPerPage == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $itemsPerPage == 100 ? 'selected' : ''; ?>>100</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="default_view">Default View</label>
                                    <select name="default_view" id="default_view" class="form-select">
                                        <option value="grid" <?php echo $defaultView == 'grid' ? 'selected' : ''; ?>>Grid</option>
                                        <option value="list" <?php echo $defaultView == 'list' ? 'selected' : ''; ?>>List</option>
                                        <option value="table" <?php echo $defaultView == 'table' ? 'selected' : ''; ?>>Table</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="dashboard_layout">Dashboard Layout</label>
                                <select name="dashboard_layout" id="dashboard_layout" class="form-select">
                                    <option value="default" <?php echo $dashboardLayout == 'default' ? 'selected' : ''; ?>>Default</option>
                                    <option value="compact" <?php echo $dashboardLayout == 'compact' ? 'selected' : ''; ?>>Compact</option>
                                    <option value="expanded" <?php echo $dashboardLayout == 'expanded' ? 'selected' : ''; ?>>Expanded</option>
                                    <option value="analytics" <?php echo $dashboardLayout == 'analytics' ? 'selected' : ''; ?>>Analytics Focus</option>
                                </select>
                            </div>
                            
                            <button type="submit" name="update_display" class="btn">
                                <i class="fas fa-save"></i> Save Display Settings
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- API Settings -->
            <?php if ($section === 'api'): ?>
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-code"></i> API Access</h3>
                        
                        <?php if (!empty($apiMessage)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <div><?php echo $apiMessage; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($apiError)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <div><?php echo htmlspecialchars($apiError); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <p style="margin-bottom: 1rem;">Generate an API key to access the EliteFit Gym API. This key will allow you to integrate with third-party applications.</p>
                        
                        <form action="" method="post">
                            <button type="submit" name="generate_api_key" class="btn">
                                <i class="fas fa-key"></i> Generate New API Key
                            </button>
                        </form>
                        
                        <div style="margin-top: 2rem;">
                            <h4 style="margin-bottom: 1rem;">API Documentation</h4>
                            <p style="margin-bottom: 1rem;">Access our API documentation to learn how to integrate with our system:</p>
                            <a href="api-docs.php" class="btn btn-secondary" target="_blank">
                                <i class="fas fa-book"></i> View API Documentation
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Activity Log -->
            <?php if ($section === 'activity'): ?>
                <div class="card">
                    <div class="settings-section">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                        
                        <ul class="activity-log">
                            <?php if (count($activityLogs) > 0): ?>
                                <?php foreach ($activityLogs as $log): ?>
                                    <li class="activity-item">
                                        <?php 
                                            $iconClass = 'settings';
                                            if ($log['action'] === 'login') {
                                                $iconClass = 'login';
                                            } elseif ($log['action'] === 'password_change') {
                                                $iconClass = 'password';
                                            }
                                        ?>
                                        <div class="activity-icon <?php echo $iconClass; ?>">
                                            <?php if ($log['action'] === 'login'): ?>
                                                <i class="fas fa-sign-in-alt"></i>
                                            <?php elseif ($log['action'] === 'password_change'): ?>
                                                <i class="fas fa-key"></i>
                                            <?php else: ?>
                                                <i class="fas fa-cog"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title"><?php echo htmlspecialchars($log['description']); ?></div>
                                            <div class="activity-time">
                                                <i class="far fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                                <span style="margin-left: 0.5rem;"><i class="fas fa-map-marker-alt"></i> IP: <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="activity-item">
                                    <div style="text-align: center; padding: 2rem; width: 100%;">
                                        <i class="fas fa-info-circle" style="font-size: 2rem; color: var(--text-secondary); margin-bottom: 1rem;"></i>
                                        <p>No recent activity found.</p>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                        
                        <div style="margin-top: 1.5rem; text-align: center;">
                            <a href="activity-logs.php" class="btn">
                                <i class="fas fa-list"></i> View All Activity
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle dropdown menu
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
        
        // Theme selection
        function selectTheme(theme) {
            // Update hidden input
            document.getElementById('themeInput').value = theme;
            
            // Update active class
            document.querySelectorAll('.theme-option').forEach(option => {
                option.classList.remove('active');
            });
            
            document.querySelector(`.theme-option.${theme}`).classList.add('active');
            
            // Preview theme
            document.documentElement.setAttribute('data-theme', theme);
        }
        
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const meter = document.getElementById('passwordStrengthMeter');
            const text = document.getElementById('passwordStrengthText');
            
            // Reset
            meter.style.width = '0';
            meter.style.backgroundColor = '';
            text.textContent = '';
            text.className = '';
            
            if (password.length === 0) {
                return;
            }
            
            // Check strength
            let strength = 0;
            
            // Length check
            if (password.length >= 8) {
                strength += 1;
            }
            
            // Uppercase check
            if (/[A-Z]/.test(password)) {
                strength += 1;
            }
            
            // Lowercase check
            if (/[a-z]/.test(password)) {
                strength += 1;
            }
            
            // Number check
            if (/[0-9]/.test(password)) {
                strength += 1;
            }
            
            // Special character check
            if (/[^A-Za-z0-9]/.test(password)) {
                strength += 1;
            }
            
            // Update UI
            if (strength <= 2) {
                meter.style.width = '25%';
                meter.style.backgroundColor = 'var(--danger)';
                text.textContent = 'Weak password';
                text.style.color = 'var(--danger)';
            } else if (strength === 3) {
                meter.style.width = '50%';
                meter.style.backgroundColor = 'var(--warning)';
                text.textContent = 'Medium strength password';
                text.style.color = 'var(--warning)';
            } else if (strength === 4) {
                meter.style.width = '75%';
                meter.style.backgroundColor = 'var(--info)';
                text.textContent = 'Good password';
                text.style.color = 'var(--info)';
            } else {
                meter.style.width = '100%';
                meter.style.backgroundColor = 'var(--success)';
                text.textContent = 'Strong password';
                text.style.color = 'var(--success)';
            }
        }
        
        // Check if passwords match
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const message = document.getElementById('passwordMatchMessage');
            
            if (confirmPassword.length === 0) {
                message.textContent = '';
                return;
            }
            
            if (password === confirmPassword) {
                message.textContent = 'Passwords match';
                message.style.color = 'var(--success)';
            } else {
                message.textContent = 'Passwords do not match';
                message.style.color = 'var(--danger)';
            }
        }
    </script>
</body>
</html>