<?php
// Start session
session_start();

// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Include database connection
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../functions.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
$conn = connectDB();

// Handle user approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetUserId = $_POST['user_id'] ?? 0;
    $reason = $_POST['reason'] ?? '';
    
    if (!empty($targetUserId)) {
        // Get user details
        $userStmt = $conn->prepare("SELECT email, role FROM users WHERE id = ?");
        $userStmt->execute([$targetUserId]);
        $userDetails = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userDetails) {
            if ($action === 'approve') {
                // Approve user
                $updateStmt = $conn->prepare("UPDATE users SET status = 'Active', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $updateStmt->execute([$userId, $targetUserId]);
                
                // Log activity
                logUserActivity($userId, 'Approved User', "Approved user ID: $targetUserId");
                
                // Update analytics
                $analyticsStmt = $conn->prepare("UPDATE registration_analytics SET status = 'Active', completion_step = 'Admin Approved' WHERE user_id = ?");
                $analyticsStmt->execute([$targetUserId]);
                
                // Send approval email
                $subject = "EliteFit Gym - Your Account Has Been Approved";
                $body = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background-color: #FF8C00; color: white; padding: 20px; text-align: center; }
                            .content { padding: 20px; background-color: #f9f9f9; }
                            .button { display: inline-block; background-color: #FF8C00; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; }
                            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>Account Approved!</h1>
                            </div>
                            <div class='content'>
                                <p>Good news! Your EliteFit Gym account has been approved by our administrators.</p>
                                <p>You can now log in to your account and start using all the features available to you as a " . htmlspecialchars($userDetails['role']) . ".</p>
                                <p style='text-align: center;'>
                                    <a href='https://" . $_SERVER['HTTP_HOST'] . "/login.php' class='button'>Login to Your Account</a>
                                </p>
                                <p>If you have any questions, please don't hesitate to contact our support team.</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " EliteFit Gym. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";
                
                sendEmail($userDetails['email'], $subject, $body);
                
                $_SESSION['approval_message'] = "User has been approved successfully.";
                $_SESSION['approval_message_type'] = "success";
            } elseif ($action === 'reject') {
                // Reject user
                $updateStmt = $conn->prepare("UPDATE users SET status = 'Rejected', rejection_reason = ?, rejected_by = ?, rejected_at = NOW() WHERE id = ?");
                $updateStmt->execute([$reason, $userId, $targetUserId]);
                
                // Log activity
                logUserActivity($userId, 'Rejected User', "Rejected user ID: $targetUserId. Reason: $reason");
                
                // Update analytics
                $analyticsStmt = $conn->prepare("UPDATE registration_analytics SET status = 'Rejected', completion_step = 'Admin Rejected' WHERE user_id = ?");
                $analyticsStmt->execute([$targetUserId]);
                
                // Send rejection email
                $subject = "EliteFit Gym - Your Account Application Status";
                $body = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background-color: #FF8C00; color: white; padding: 20px; text-align: center; }
                            .content { padding: 20px; background-color: #f9f9f9; }
                            .button { display: inline-block; background-color: #FF8C00; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; }
                            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>Account Application Update</h1>
                            </div>
                            <div class='content'>
                                <p>Thank you for your interest in joining EliteFit Gym.</p>
                                <p>After reviewing your application, we regret to inform you that we are unable to approve your account at this time.</p>
                                " . (!empty($reason) ? "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") . "
                                <p>If you believe this is an error or would like to provide additional information, please contact our support team.</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " EliteFit Gym. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";
                
                sendEmail($userDetails['email'], $subject, $body);
                
                $_SESSION['approval_message'] = "User has been rejected.";
                $_SESSION['approval_message_type'] = "warning";
            }
        } else {
            $_SESSION['approval_message'] = "User not found.";
            $_SESSION['approval_message_type'] = "danger";
        }
    } else {
        $_SESSION['approval_message'] = "Invalid user ID.";
        $_SESSION['approval_message_type'] = "danger";
    }
    
    // Redirect back to the approval page
    header("Location: user_approval.php");
    exit;
}

// Get pending users
$pendingStmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.role, u.experience_level, u.registration_date, u.email_verified_at
    FROM users u
    WHERE u.status = 'Pending Admin Approval'
    ORDER BY u.registration_date DESC
");
$pendingStmt->execute();
$pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently approved/rejected users
$recentStmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.role, u.status, u.approved_at, u.rejected_at, 
           u.rejection_reason, a.name as approved_by, r.name as rejected_by
    FROM users u
    LEFT JOIN users a ON u.approved_by = a.id
    LEFT JOIN users r ON u.rejected_by = r.id
    WHERE (u.status = 'Active' AND u.approved_at IS NOT NULL) 
       OR (u.status = 'Rejected' AND u.rejected_at IS NOT NULL)
    ORDER BY COALESCE(u.approved_at, u.rejected_at) DESC
    LIMIT 10
");
$recentStmt->execute();
$recentUsers = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get approval statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'Pending Admin Approval' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'Active' AND approved_at IS NOT NULL THEN 1 END) as approved_count,
        COUNT(CASE WHEN status = 'Rejected' THEN 1 END) as rejected_count,
        AVG(CASE 
            WHEN status = 'Active' AND approved_at IS NOT NULL 
            THEN TIMESTAMPDIFF(HOUR, registration_date, approved_at) 
        END) as avg_approval_time
    FROM users
    WHERE role IN ('Trainer', 'EquipmentManager')
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get message from session
$message = isset($_SESSION['approval_message']) ? $_SESSION['approval_message'] : '';
$messageType = isset($_SESSION['approval_message_type']) ? $_SESSION['approval_message_type'] : '';
unset($_SESSION['approval_message']);
unset($_SESSION['approval_message_type']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Approval - EliteFit Gym Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .dark-theme .card {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            font-weight: 600;
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
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
            text-align: center;
        }
        
        .dark-theme .stat-card {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .stat-card .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .stat-card .stat-label {
            color: #777;
            font-size: 0.9rem;
        }
        
        .dark-theme .stat-card .stat-label {
            color: #aaa;
        }
        
        .pending-icon {
            color: var(--warning);
        }
        
        .approved-icon {
            color: var(--success);
        }
        
        .rejected-icon {
            color: var(--danger);
        }
        
        .time-icon {
            color: var(--info);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .dark-theme .table th, 
        .dark-theme .table td {
            border-bottom: 1px solid #3d3d3d;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .dark-theme .table th {
            background-color: #3d3d3d;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }
        
        .badge-warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        
        .badge-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        
        .btn {
            padding: 8px 15px;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-block;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.2);
            color: var(--success);
        }
        
        .alert-warning {
            background-color: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.2);
            color: var(--warning);
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.2);
            color: var(--danger);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: var(--border-radius);
            width: 500px;
            max-width: 90%;
            position: relative;
        }
        
        .dark-theme .modal-content {
            background-color: #2d2d2d;
        }
        
        .modal-header {
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 15px;
        }
        
        .dark-theme .modal-header {
            border-bottom: 1px solid #3d3d3d;
        }
        
        .modal-title {
            margin: 0;
            font-size: 1.2rem;
        }
        
        .close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
        }
        
        .dark-theme .form-control {
            background-color: #3d3d3d;
            border-color: #4d4d4d;
            color: #f5f5f5;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .modal-footer {
            padding-top: 15px;
            border-top: 1px solid #eee;
            margin-top: 15px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .dark-theme .modal-footer {
            border-top: 1px solid #3d3d3d;
        }
        
        .user-details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 15px;
        }
        
        .dark-theme .user-details {
            background-color: #3d3d3d;
        }
        
        .user-details p {
            margin: 5px 0;
        }
        
        .user-details strong {
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar-header h2, .sidebar-menu a span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="<?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'dark-theme' : ''; ?>">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell" style="font-size: 1.5rem; color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="user_approval.php" class="active"><i class="fas fa-user-check"></i> <span>User Approval</span></a></li>
                <li><a href="analytics.php"><i class="fas fa-chart-bar"></i> <span>Analytics</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>User Approval</h1>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/men/1.jpg" alt="Admin Avatar">
                    <span><?php echo htmlspecialchars($userName); ?></span>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistics -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon pending-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                    <div class="stat-label">Pending Approvals</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon approved-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['approved_count']; ?></div>
                    <div class="stat-label">Approved Users</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon rejected-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['rejected_count']; ?></div>
                    <div class="stat-label">Rejected Users</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon time-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo round($stats['avg_approval_time'] ?? 0, 1); ?></div>
                    <div class="stat-label">Avg. Approval Time (hours)</div>
                </div>
            </div>
            
            <!-- Pending Approvals -->
            <div class="card">
                <div class="card-header">
                    <h2>Pending Approvals</h2>
                </div>
                <div class="card-body">
                    <?php if (count($pendingUsers) > 0): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Registration Date</th>
                                        <th>Email Verified</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingUsers as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($user['registration_date'])); ?></td>
                                            <td>
                                                <?php if ($user['email_verified_at']): ?>
                                                    <span class="badge badge-success">Verified</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-success" onclick="showApproveModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="showRejectModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">No pending approvals at this time.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h2>Recent Activity</h2>
                </div>
                <div class="card-body">
                    <?php if (count($recentUsers) > 0): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>By Admin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUsers as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td>
                                                <?php if ($user['status'] === 'Active'): ?>
                                                    <span class="badge badge-success">Approved</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Rejected</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $date = $user['status'] === 'Active' ? $user['approved_at'] : $user['rejected_at'];
                                                    echo date('M d, Y H:i', strtotime($date)); 
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    echo htmlspecialchars($user['status'] === 'Active' ? $user['approved_by'] : $user['rejected_by']); 
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">No recent activity.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('approveModal')">&times;</span>
            <div class="modal-header">
                <h3 class="modal-title">Approve User</h3>
            </div>
            <form action="user_approval.php" method="post">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="user_id" id="approve_user_id">
                
                <div class="user-details" id="approve_user_details">
                    <p>Are you sure you want to approve <strong id="approve_user_name"></strong>?</p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('rejectModal')">&times;</span>
            <div class="modal-header">
                <h3 class="modal-title">Reject User</h3>
            </div>
            <form action="user_approval.php" method="post">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="user_id" id="reject_user_id">
                
                <div class="user-details" id="reject_user_details">
                    <p>Are you sure you want to reject <strong id="reject_user_name"></strong>?</p>
                </div>
                
                <div class="form-group">
                    <label for="reason">Reason for Rejection</label>
                    <textarea name="reason" id="reason" class="form-control" rows="3" placeholder="Provide a reason for rejection (optional)"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject User</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Show approve modal
        function showApproveModal(userId, userName) {
            document.getElementById('approve_user_id').value = userId;
            document.getElementById('approve_user_name').textContent = userName;
            document.getElementById('approveModal').style.display = 'block';
        }
        
        // Show reject modal
        function showRejectModal(userId, userName) {
            document.getElementById('reject_user_id').value = userId;
            document.getElementById('reject_user_name').textContent = userName;
            document.getElementById('rejectModal').style.display = 'block';
        }
        
        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
