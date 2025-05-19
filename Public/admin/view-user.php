<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin', '../login.php');

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
$stmt = $conn->prepare("
    SELECT u.*, 
           COUNT(DISTINCT tm.id) as assigned_members,
           COUNT(DISTINCT w.id) as workout_plans
    FROM users u
    LEFT JOIN trainer_members tm ON u.id = tm.trainer_id
    LEFT JOIN workouts w ON u.id = w.trainer_id
    WHERE u.id = ?
    GROUP BY u.id
");
$stmt->execute([$userId]);

if ($stmt->rowCount() === 0) {
    $_SESSION['error_message'] = "User not found";
    header("Location: users.php");
    exit;
}

$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get login history
$loginStmt = $conn->prepare("
    SELECT timestamp, success, ip_address, user_agent
    FROM login_logs
    WHERE email = ?
    ORDER BY timestamp DESC
    LIMIT 10
");
$loginStmt->execute([$user['email']]);
$loginHistory = $loginStmt->fetchAll(PDO::FETCH_ASSOC);

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View User - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #ff4d4d;
            --secondary: #333;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 8px;
            --orange: #FF8C00;
            
            /* Light theme variables */
            --bg-light: #f5f7fa;
            --text-light: #333;
            --card-light: #ffffff;
            --border-light: #e0e0e0;
            --sidebar-light: #ffffff;
            --sidebar-text-light: #333;
            --sidebar-hover-light: #f0f0f0;
            
            /* Dark theme variables */
            --bg-dark: #121212;
            --text-dark: #e0e0e0;
            --card-dark: #1e1e1e;
            --border-dark: #333;
            --sidebar-dark: #1a1a1a;
            --sidebar-text-dark: #e0e0e0;
            --sidebar-hover-dark: #2a2a2a;
        }
        
        [data-theme="light"] {
            --bg-color: var(--bg-light);
            --text-color: var(--text-light);
            --card-bg: var(--card-light);
            --border-color: var(--border-light);
            --sidebar-bg: var(--sidebar-light);
            --sidebar-text: var(--sidebar-text-light);
            --sidebar-hover: var(--sidebar-hover-light);
            --header-bg: var(--card-light);
        }
        
        [data-theme="dark"] {
            --bg-color: var(--bg-dark);
            --text-color: var(--text-dark);
            --card-bg: var(--card-dark);
            --border-color: var(--border-dark);
            --sidebar-bg: var(--sidebar-dark);
            --sidebar-text: var(--sidebar-text-dark);
            --sidebar-hover: var(--sidebar-hover-dark);
            --header-bg: var(--card-dark);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
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
            transition: all 0.3s ease;
            border-right: 1px solid var(--border-color);
            z-index: 1000;
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
            color: var(--orange);
        }
        
        .sidebar-menu {
            list-style: none;
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
            transition: all 0.3s;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: var(--sidebar-hover);
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
            transition: all 0.3s ease;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: var(--header-bg);
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .header h1 {
            font-size: 1.8rem;
            color: var(--text-color);
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
            object-fit: cover;
            border: 2px solid var(--orange);
        }
        
        .user-info .dropdown {
            position: relative;
        }
        
        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            color: var(--text-color);
        }
        
        .user-info .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 10px 0;
            min-width: 180px;
            z-index: 1000;
            display: none;
            border: 1px solid var(--border-color);
        }
        
        .user-info .dropdown-menu.show {
            display: block;
        }
        
        .user-info .dropdown-menu a {
            display: block;
            padding: 8px 20px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .user-info .dropdown-menu a:hover {
            background-color: var(--sidebar-hover);
        }
        
        .card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-header h2 {
            font-size: 1.5rem;
            color: var(--text-color);
        }
        
        .user-profile {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .user-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--orange);
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-details {
            flex: 1;
            min-width: 300px;
        }
        
        .user-details h3 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .user-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .user-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .user-meta-item i {
            color: var(--orange);
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }
        
        .badge-success {
            background-color: var(--success);
        }
        
        .badge-danger {
            background-color: var(--danger);
        }
        
        .badge-warning {
            background-color: var(--warning);
        }
        
        .badge-info {
            background-color: var(--info);
        }
        
        .badge-primary {
            background-color: var(--primary);
        }
        
        .user-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 15px;
            text-align: center;
        }
        
        .stat-card i {
            font-size: 2rem;
            color: var(--orange);
            margin-bottom: 10px;
        }
        
        .stat-card h4 {
            font-size: 2rem;
            margin-bottom: 5px;
            color: var(--text-color);
        }
        
        .stat-card p {
            color: var(--text-color);
            opacity: 0.7;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table th {
            font-weight: 600;
            color: var(--text-color);
            background-color: var(--sidebar-hover);
        }
        
        .btn {
            padding: 8px 15px;
            background-color: var(--orange);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background-color: #e67e00;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.9rem;
        }
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-secondary {
            background-color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background-color: #444;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
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
                margin-top: 15px;
                width: 100%;
                justify-content: space-between;
            }
            
            .user-profile {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .user-details {
                width: 100%;
            }
            
            .user-meta {
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .action-buttons .btn {
                width: 100%;
                margin-bottom: 10px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--orange);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="users.php" class="active"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
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
                    <h1>User Profile</h1>
                    <p>View detailed information about the user</p>
                </div>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/women/1.jpg" alt="Admin Avatar">
                    <div class="dropdown">
                        <div class="dropdown-toggle" onclick="toggleDropdown()">
                            <span><?php echo htmlspecialchars($adminName); ?></span>
                            <i class="fas fa-chevron-down ml-2"></i>
                        </div>
                        <div class="dropdown-menu" id="userDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                            <a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a>
                            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Profile -->
            <div class="card">
                <div class="card-header">
                    <h2>User Information</h2>
                    <div>
                        <a href="users.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Users</a>
                    </div>
                </div>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <img src="https://randomuser.me/api/portraits/<?php echo $user['role'] === 'Admin' ? 'women' : 'men'; ?>/<?php echo $user['id'] % 100; ?>.jpg" alt="User Avatar">
                    </div>
                    
                    <div class="user-details">
                        <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                        
                        <div class="user-meta">
                            <div class="user-meta-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo htmlspecialchars($user['email']); ?></span>
                            </div>
                            
                            <div class="user-meta-item">
                                <i class="fas fa-user-tag"></i>
                                <?php 
                                    $badgeClass = '';
                                    switch ($user['role']) {
                                        case 'Admin':
                                            $badgeClass = 'badge-danger';
                                            break;
                                        case 'Trainer':
                                            $badgeClass = 'badge-success';
                                            break;
                                        case 'EquipmentManager':
                                            $badgeClass = 'badge-warning';
                                            break;
                                        default:
                                            $badgeClass = 'badge-info';
                                    }
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($user['role']); ?></span>
                            </div>
                            
                            <div class="user-meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                            </div>
                        </div>
                        
                        <?php if (!empty($user['experience_level'])): ?>
                            <div class="user-meta-item">
                                <i class="fas fa-medal"></i>
                                <span>Experience/Specialization: <?php echo htmlspecialchars($user['experience_level']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($user['fitness_goals'])): ?>
                            <div style="margin-top: 15px;">
                                <h4 style="margin-bottom: 5px;">
                                    <?php echo $user['role'] === 'Member' ? 'Fitness Goals' : 'Experience'; ?>
                                </h4>
                                <p><?php echo htmlspecialchars($user['fitness_goals']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($user['preferred_routines'])): ?>
                            <div style="margin-top: 15px;">
                                <h4 style="margin-bottom: 5px;">
                                    <?php echo $user['role'] === 'Member' ? 'Preferred Routines' : 'Approach/Certifications'; ?>
                                </h4>
                                <p><?php echo htmlspecialchars($user['preferred_routines']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($user['role'] === 'Trainer'): ?>
                            <div class="user-stats">
                                <div class="stat-card">
                                    <i class="fas fa-users"></i>
                                    <h4><?php echo $user['assigned_members']; ?></h4>
                                    <p>Assigned Members</p>
                                </div>
                                
                                <div class="stat-card">
                                    <i class="fas fa-clipboard-list"></i>
                                    <h4><?php echo $user['workout_plans']; ?></h4>
                                    <p>Workout Plans</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="action-buttons">
                            <a href="edit-user.php?id=<?php echo $user['id']; ?>" class="btn"><i class="fas fa-edit"></i> Edit User</a>
                            <?php if ($user['role'] !== 'Admin' || $user['id'] !== $adminId): ?>
                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')" class="btn btn-danger"><i class="fas fa-trash"></i> Delete User</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Login History -->
            <div class="card">
                <div class="card-header">
                    <h2>Login History</h2>
                </div>
                
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($loginHistory) > 0): ?>
                                <?php foreach ($loginHistory as $login): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i:s', strtotime($login['timestamp'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo $login['success'] ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $login['success'] ? 'Success' : 'Failed'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($login['ip_address']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($login['user_agent'], 0, 100) . '...'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No login history available.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div style="background-color: var(--card-bg); margin: 15% auto; padding: 20px; border: 1px solid var(--border-color); border-radius: var(--border-radius); width: 50%; max-width: 500px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
            <h3 style="margin-bottom: 20px; color: var(--text-color);">Confirm Deletion</h3>
            <p style="margin-bottom: 20px; color: var(--text-color);">Are you sure you want to delete <span id="deleteUserName"></span>? This action cannot be undone.</p>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="closeDeleteModal()" class="btn btn-sm" style="background-color: var(--secondary);">Cancel</button>
                <form id="deleteForm" action="delete-user.php" method="post">
                    <input type="hidden" id="deleteUserId" name="user_id">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
            </div>
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
            
            // Close modal when clicking outside
            if (event.target.id === 'deleteModal') {
                closeDeleteModal();
            }
        }
        
        // Delete user confirmation
        function confirmDelete(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
    </script>
</body>
</html>
