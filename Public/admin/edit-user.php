<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Get user data
$adminId = $_SESSION['user_id'];
$adminName = $_SESSION['name'];

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "User ID is required";
    header("Location: users.php");
    exit;
}

$userId = (int)$_GET['id'];

// Connect to database
$conn = connectDB();

// Get user details
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['error_message'] = "User not found";
        header("Location: users.php");
        exit;
    }
} catch (Exception $e) {
    $_SESSION['error_message'] = "Error retrieving user: " . $e->getMessage();
    header("Location: users.php");
    exit;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Get form data
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $medical_conditions = trim($_POST['medical_conditions'] ?? '');
        $membership_type = trim($_POST['membership_type'] ?? '');
        $membership_start = $_POST['membership_start'] ?? null;
        $membership_end = $_POST['membership_end'] ?? null;
        
        // Validation
        if (empty($name) || empty($email) || empty($role)) {
            throw new Exception("Name, email, and role are required fields");
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        
        // Check if email already exists for another user
        $emailCheckStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $emailCheckStmt->execute([$email, $userId]);
        if ($emailCheckStmt->fetch()) {
            throw new Exception("Email already exists for another user");
        }
        
        // Store original data for logging
        $originalData = $user;
        
        // Handle password update if provided
        $passwordUpdate = "";
        $passwordParams = [];
        if (!empty($_POST['password'])) {
            $password = $_POST['password'];
            $confirmPassword = $_POST['confirm_password'];
            
            if ($password !== $confirmPassword) {
                throw new Exception("Passwords do not match");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters long");
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $passwordUpdate = ", password = ?";
            $passwordParams[] = $hashedPassword;
        }
        
        // Prepare update query
        $sql = "UPDATE users SET 
                name = ?, 
                email = ?, 
                role = ?, 
                status = ?, 
                phone = ?, 
                address = ?, 
                emergency_contact = ?, 
                medical_conditions = ?, 
                membership_type = ?, 
                membership_start = ?, 
                membership_end = ?, 
                updated_at = NOW()
                $passwordUpdate
                WHERE id = ?";
        
        $params = [
            $name, $email, $role, $status, $phone, $address, 
            $emergency_contact, $medical_conditions, $membership_type, 
            $membership_start, $membership_end
        ];
        
        // Add password parameter if updating
        $params = array_merge($params, $passwordParams);
        $params[] = $userId;
        
        $updateStmt = $conn->prepare($sql);
        $updateStmt->execute($params);
        
        // Log status change if status was modified
        if ($originalData['status'] !== $status) {
            $statusStmt = $conn->prepare("
                INSERT INTO user_status_history 
                (user_id, old_status, new_status, changed_by, change_reason, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $statusStmt->execute([
                $userId,
                $originalData['status'],
                $status,
                $adminId,
                'Status updated by admin',
                $_SERVER['REMOTE_ADDR']
            ]);
        }
        
        // Log the action in admin_logs
        $changes = [];
        if ($originalData['name'] !== $name) $changes[] = "name";
        if ($originalData['email'] !== $email) $changes[] = "email";
        if ($originalData['role'] !== $role) $changes[] = "role";
        if ($originalData['status'] !== $status) $changes[] = "status";
        if (!empty($_POST['password'])) $changes[] = "password";
        
        $changesText = !empty($changes) ? implode(', ', $changes) : 'profile data';
        $logMessage = "Updated user: " . $name . " (" . $email . "). Changed: " . $changesText;
        
        // Check admin_logs table structure
        $checkColumnsStmt = $conn->prepare("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'admin_logs'
        ");
        $checkColumnsStmt->execute();
        $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Create admin_logs table if it doesn't exist
        if (empty($columns)) {
            $createTableStmt = $conn->prepare("
                CREATE TABLE admin_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    log_message TEXT,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    ip_address VARCHAR(45),
                    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");
            $createTableStmt->execute();
            $columns = ['id', 'admin_id', 'action', 'log_message', 'timestamp', 'ip_address'];
        }
        
        // Build dynamic SQL query for logging
        $sql = "INSERT INTO admin_logs (";
        $params = [];
        $placeholders = [];
        
        $sql .= "admin_id, action";
        $params[] = $adminId;
        $params[] = "edit_user";
        $placeholders[] = "?";
        $placeholders[] = "?";
        
        if (in_array('details', $columns)) {
            $sql .= ", details";
            $params[] = $logMessage;
            $placeholders[] = "?";
        } elseif (in_array('log_message', $columns)) {
            $sql .= ", log_message";
            $params[] = $logMessage;
            $placeholders[] = "?";
        }
        
        if (in_array('ip_address', $columns)) {
            $sql .= ", ip_address";
            $params[] = $_SERVER['REMOTE_ADDR'];
            $placeholders[] = "?";
        }
        
        $sql .= ") VALUES (" . implode(", ", $placeholders) . ")";
        
        $logStmt = $conn->prepare($sql);
        $logStmt->execute($params);
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "User " . $name . " has been successfully updated.";
        
        // Redirect to users page or back to edit page
        if (isset($_POST['save_and_continue'])) {
            header("Location: edit-user.php?id=" . $userId);
        } else {
            header("Location: users.php");
        }
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        $_SESSION['error_message'] = "Error updating user: " . $e->getMessage();
    }
}

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #f97316;
            --primary-light: #fb923c;
            --primary-dark: #ea580c;
            --secondary: #1f2937;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #0ea5e9;
            --border-radius: 0.75rem;
            --font-family: 'Poppins', sans-serif;
            --transition-speed: 0.3s;
        }

        [data-theme="light"] {
            --bg-color: #f9fafb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --card-bg: #ffffff;
            --card-hover: #f8fafc;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --sidebar-text: #1f2937;
            --sidebar-hover: #f3f4f6;
            --header-bg: #ffffff;
            --input-bg: #ffffff;
            --input-border: #d1d5db;
            --shadow-color: rgba(0, 0, 0, 0.05);
            --shadow-color-hover: rgba(0, 0, 0, 0.1);
        }

        [data-theme="dark"] {
            --bg-color: #0f172a;
            --text-color: #e2e8f0;
            --text-muted: #94a3b8;
            --card-bg: #1e293b;
            --card-hover: #334155;
            --border-color: #334155;
            --sidebar-bg: #1e293b;
            --sidebar-text: #e2e8f0;
            --sidebar-hover: #334155;
            --header-bg: #1e293b;
            --input-bg: #374151;
            --input-border: #4b5563;
            --shadow-color: rgba(0, 0, 0, 0.2);
            --shadow-color-hover: rgba(0, 0, 0, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color var(--transition-speed) ease, 
                        color var(--transition-speed) ease;
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all var(--transition-speed) ease;
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            box-shadow: 3px 0 10px var(--shadow-color);
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 10px;
            color: var(--primary);
            font-weight: 700;
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
            padding: 12px 15px;
            color: var(--sidebar-text);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed) ease;
            position: relative;
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
            transform: translateX(5px);
        }

        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: margin-left var(--transition-speed) ease;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: var(--header-bg);
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px var(--shadow-color);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--danger));
        }

        .header h1 {
            font-size: 1.8rem;
            color: var(--text-color);
            font-weight: 600;
        }

        .header p {
            color: var(--text-muted);
            margin-top: 5px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            color: var(--text-muted);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb a:hover {
            color: var(--primary-dark);
        }

        .breadcrumb i {
            margin: 0 10px;
        }

        .form-container {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px var(--shadow-color);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .form-header h3 i {
            margin-right: 10px;
        }

        .form-body {
            padding: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .form-section {
            background-color: var(--card-hover);
            padding: 20px;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
        }

        .form-section h4 {
            color: var(--primary);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .form-section h4 i {
            margin-right: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group label.required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--input-border);
            border-radius: var(--border-radius);
            background-color: var(--input-bg);
            color: var(--text-color);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .form-control:disabled {
            background-color: var(--border-color);
            cursor: not-allowed;
            opacity: 0.6;
        }

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            padding: 12px 24px;
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
            font-size: 0.9rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.2);
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-secondary {
            background-color: var(--secondary);
        }

        .btn-secondary:hover {
            background-color: #374151;
        }

        .btn-success {
            background-color: var(--success);
        }

        .btn-success:hover {
            background-color: #059669;
        }

        .btn-danger {
            background-color: var(--danger);
        }

        .btn-danger:hover {
            background-color: #dc2626;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding: 20px 30px;
            background-color: var(--card-hover);
            border-top: 1px solid var(--border-color);
        }

        .alert {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            animation: slideInDown 0.3s ease;
        }

        .alert i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }

        @keyframes slideInDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle .toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 5px;
        }

        .password-toggle .toggle-btn:hover {
            color: var(--primary);
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            margin-bottom: 15px;
        }

        .avatar-section {
            text-align: center;
            margin-bottom: 20px;
        }

        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1100;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            width: 45px;
            height: 45px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        @media (max-width: 576px) {
            .form-body {
                padding: 20px;
            }
            
            .form-section {
                padding: 15px;
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
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="users.php" class="active"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="archived-users.php"><i class="fas fa-archive"></i> <span>Archived Users</span></a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="admin_settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-user-edit"></i> Edit User</h1>
                    <p>Modify user account information and settings</p>
                </div>
            </div>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="dashboard.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="users.php">Users</a>
                <i class="fas fa-chevron-right"></i>
                <span>Edit User</span>
            </div>

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Edit User Form -->
            <div class="form-container">
                <div class="form-header">
                    <h3><i class="fas fa-user-edit"></i> Edit User: <?php echo htmlspecialchars($user['name']); ?></h3>
                    <span>ID: #<?php echo $user['id']; ?></span>
                </div>

                <form method="POST" id="editUserForm">
                    <div class="form-body">
                        <div class="form-grid">
                            <!-- Basic Information -->
                            <div class="form-section">
                                <h4><i class="fas fa-user"></i> Basic Information</h4>
                                
                                <div class="avatar-section">
                                    <img src="https://randomuser.me/api/portraits/<?php echo $user['role'] === 'Member' ? 'men' : 'women'; ?>/<?php echo $user['id'] % 50; ?>.jpg" 
                                         alt="User Avatar" class="user-avatar">
                                </div>

                                <div class="form-group">
                                    <label for="name" class="required">Full Name</label>
                                    <input type="text" id="name" name="name" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="email" class="required">Email Address</label>
                                    <input type="email" id="email" name="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="phone">Phone Number</label>
                                    <input type="tel" id="phone" name="phone" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <!-- Account Settings -->
                            <div class="form-section">
                                <h4><i class="fas fa-cog"></i> Account Settings</h4>

                                <div class="form-group">
                                    <label for="role" class="required">Role</label>
                                    <select id="role" name="role" class="form-control" required>
                                        <option value="Member" <?php echo $user['role'] === 'Member' ? 'selected' : ''; ?>>Member</option>
                                        <option value="Trainer" <?php echo $user['role'] === 'Trainer' ? 'selected' : ''; ?>>Trainer</option>
                                        <option value="EquipmentManager" <?php echo $user['role'] === 'EquipmentManager' ? 'selected' : ''; ?>>Equipment Manager</option>
                                        <?php if ($_SESSION['role'] === 'Admin'): ?>
                                            <option value="Admin" <?php echo $user['role'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="status" class="required">Status</label>
                                    <select id="status" name="status" class="form-control" required>
                                        <option value="Active" <?php echo ($user['status'] ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo ($user['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Suspended" <?php echo ($user['status'] ?? '') === 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="password">New Password (leave blank to keep current)</label>
                                    <div class="password-toggle">
                                        <input type="password" id="password" name="password" class="form-control">
                                        <button type="button" class="toggle-btn" onclick="togglePassword('password')">
                                            <i class="fas fa-eye" id="password-icon"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" id="confirm_password" name="confirm_password" class="form-control">
                                        <button type="button" class="toggle-btn" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye" id="confirm_password-icon"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact Information -->
                            <div class="form-section">
                                <h4><i class="fas fa-address-card"></i> Contact Information</h4>

                                <div class="form-group">
                                    <label for="address">Address</label>
                                    <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="emergency_contact">Emergency Contact</label>
                                    <input type="text" id="emergency_contact" name="emergency_contact" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['emergency_contact'] ?? ''); ?>">
                                </div>
                            </div>

                            <!-- Health & Membership -->
                            <div class="form-section">
                                <h4><i class="fas fa-heartbeat"></i> Health & Membership</h4>

                                <div class="form-group">
                                    <label for="medical_conditions">Medical Conditions</label>
                                    <textarea id="medical_conditions" name="medical_conditions" class="form-control" rows="3"><?php echo htmlspecialchars($user['medical_conditions'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="membership_type">Membership Type</label>
                                    <select id="membership_type" name="membership_type" class="form-control">
                                        <option value="">Select Membership</option>
                                        <option value="Basic" <?php echo ($user['membership_type'] ?? '') === 'Basic' ? 'selected' : ''; ?>>Basic</option>
                                        <option value="Premium" <?php echo ($user['membership_type'] ?? '') === 'Premium' ? 'selected' : ''; ?>>Premium</option>
                                        <option value="VIP" <?php echo ($user['membership_type'] ?? '') === 'VIP' ? 'selected' : ''; ?>>VIP</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="membership_start">Membership Start Date</label>
                                    <input type="date" id="membership_start" name="membership_start" class="form-control" 
                                           value="<?php echo $user['membership_start'] ?? ''; ?>">
                                </div>

                                <div class="form-group">
                                    <label for="membership_end">Membership End Date</label>
                                    <input type="date" id="membership_end" name="membership_end" class="form-control" 
                                           value="<?php echo $user['membership_end'] ?? ''; ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="users.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" name="save_and_continue" class="btn btn-success">
                            <i class="fas fa-save"></i> Save & Continue Editing
                        </button>
                        <button type="submit" class="btn">
                            <i class="fas fa-check"></i> Save & Return
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Password toggle functionality
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Form validation
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password && password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password && password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
            
            // Show loading state
            const submitBtns = this.querySelectorAll('button[type="submit"]');
            submitBtns.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            });
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.animation = 'slideOutUp 0.3s ease';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Real-time password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            
            // You can add a password strength indicator here
            console.log('Password strength:', strength);
        });

        function calculatePasswordStrength(password) {
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            return strength;
        }

        // Membership date validation
        document.getElementById('membership_start').addEventListener('change', function() {
            const startDate = new Date(this.value);
            const endDateField = document.getElementById('membership_end');
            
            if (endDateField.value) {
                const endDate = new Date(endDateField.value);
                if (startDate > endDate) {
                    alert('Membership start date cannot be after end date');
                    this.value = '';
                }
            }
        });

        document.getElementById('membership_end').addEventListener('change', function() {
            const endDate = new Date(this.value);
            const startDateField = document.getElementById('membership_start');
            
            if (startDateField.value) {
                const startDate = new Date(startDateField.value);
                if (endDate < startDate) {
                    alert('Membership end date cannot be before start date');
                    this.value = '';
                }
            }
        });

        // Add CSS for slide out animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideOutUp {
                from {
                    transform: translateY(0);
                    opacity: 1;
                }
                to {
                    transform: translateY(-20px);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
