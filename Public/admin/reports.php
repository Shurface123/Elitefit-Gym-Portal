<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n'); // Current month by default
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y'); // Current year by default
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'user_activity';

// Connect to database
$conn = connectDB();

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';

// Function to get month name
function getMonthName($month) {
    return date('F', mktime(0, 0, 0, $month, 1));
}

// Function to get user activity data
function getUserActivityData($conn, $month, $year) {
    // Get login activity by day
    $loginQuery = "
        SELECT 
            DAY(timestamp) as day,
            COUNT(*) as login_count,
            SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_logins,
            SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_logins
        FROM login_logs
        WHERE MONTH(timestamp) = ? AND YEAR(timestamp) = ?
        GROUP BY DAY(timestamp)
        ORDER BY DAY(timestamp)
    ";
    
    $loginStmt = $conn->prepare($loginQuery);
    $loginStmt->execute([$month, $year]);
    $loginData = $loginStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get registration activity by day
    $registrationQuery = "
        SELECT 
            DAY(timestamp) as day,
            COUNT(*) as registration_count,
            SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_registrations,
            SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_registrations
        FROM registration_logs
        WHERE MONTH(timestamp) = ? AND YEAR(timestamp) = ?
        GROUP BY DAY(timestamp)
        ORDER BY DAY(timestamp)
    ";
    
    $registrationStmt = $conn->prepare($registrationQuery);
    $registrationStmt->execute([$month, $year]);
    $registrationData = $registrationStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user activity by role
    $roleQuery = "
        SELECT 
            role,
            COUNT(*) as login_count
        FROM login_logs
        WHERE MONTH(timestamp) = ? AND YEAR(timestamp) = ? AND success = 1 AND role IS NOT NULL
        GROUP BY role
        ORDER BY login_count DESC
    ";
    
    $roleStmt = $conn->prepare($roleQuery);
    $roleStmt->execute([$month, $year]);
    $roleData = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'login_data' => $loginData,
        'registration_data' => $registrationData,
        'role_data' => $roleData
    ];
}

// Function to get trainer performance data
function getTrainerPerformanceData($conn, $month, $year) {
    // Get workout plans created by trainers
    $workoutQuery = "
        SELECT 
            u.id as trainer_id,
            u.name as trainer_name,
            COUNT(w.id) as workout_count
        FROM users u
        LEFT JOIN workouts w ON u.id = w.trainer_id AND MONTH(w.created_at) = ? AND YEAR(w.created_at) = ?
        WHERE u.role = 'Trainer'
        GROUP BY u.id, u.name
        ORDER BY workout_count DESC
    ";
    
    $workoutStmt = $conn->prepare($workoutQuery);
    $workoutStmt->execute([$month, $year]);
    $workoutData = $workoutStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get member assignments to trainers
    $memberQuery = "
        SELECT 
            u.id as trainer_id,
            u.name as trainer_name,
            COUNT(tm.member_id) as member_count
        FROM users u
        LEFT JOIN trainer_members tm ON u.id = tm.trainer_id
        WHERE u.role = 'Trainer'
        GROUP BY u.id, u.name
        ORDER BY member_count DESC
    ";
    
    $memberStmt = $conn->prepare($memberQuery);
    $memberStmt->execute();
    $memberData = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'workout_data' => $workoutData,
        'member_data' => $memberData
    ];
}

// Function to get equipment management data
function getEquipmentManagementData($conn, $month, $year) {
    // Get equipment status counts
    $statusQuery = "
        SELECT 
            status,
            COUNT(*) as count
        FROM equipment
        GROUP BY status
    ";
    
    $statusStmt = $conn->prepare($statusQuery);
    $statusStmt->execute();
    $statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get maintenance schedule for the month
    $maintenanceQuery = "
        SELECT 
            e.name as equipment_name,
            m.scheduled_date,
            m.status as maintenance_status
        FROM maintenance_schedule m
        JOIN equipment e ON m.equipment_id = e.id
        WHERE MONTH(m.scheduled_date) = ? AND YEAR(m.scheduled_date) = ?
        ORDER BY m.scheduled_date
    ";
    
    $maintenanceStmt = $conn->prepare($maintenanceQuery);
    $maintenanceStmt->execute([$month, $year]);
    $maintenanceData = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'status_data' => $statusData,
        'maintenance_data' => $maintenanceData
    ];
}

// Get report data based on type
$reportData = [];
switch ($reportType) {
    case 'user_activity':
        $reportData = getUserActivityData($conn, $month, $year);
        break;
    case 'trainer_performance':
        $reportData = getTrainerPerformanceData($conn, $month, $year);
        break;
    case 'equipment_management':
        $reportData = getEquipmentManagementData($conn, $month, $year);
        break;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .filter-section {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-label {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--orange);
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
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 30px;
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
        
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 5px;
        }
        
        .report-tab {
            padding: 10px 15px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            color: var(--text-color);
            text-decoration: none;
        }
        
        .report-tab:hover {
            background-color: var(--sidebar-hover);
        }
        
        .report-tab.active {
            background-color: var(--orange);
            color: white;
            border-color: var(--orange);
        }
        
        .report-section {
            margin-bottom: 30px;
        }
        
        .report-section h3 {
            margin-bottom: 15px;
            color: var(--text-color);
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        
        .summary-card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: white;
            font-size: 1.5rem;
        }
        
        .summary-card-icon.success {
            background-color: var(--success);
        }
        
        .summary-card-icon.danger {
            background-color: var(--danger);
        }
        
        .summary-card-icon.warning {
            background-color: var(--warning);
        }
        
        .summary-card-icon.info {
            background-color: var(--info);
        }
        
        .summary-card h4 {
            font-size: 1.2rem;
            margin-bottom: 5px;
            color: var(--text-color);
        }
        
        .summary-card p {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .summary-card small {
            color: var(--text-color);
            opacity: 0.7;
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
            
            .filter-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .summary-cards {
                grid-template-columns: 1fr;
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
                <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="reports.php" class="active"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="admin_settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Reports</h1>
                    <p>View detailed reports and analytics</p>
                </div>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/women/1.jpg" alt="User Avatar">
                    <div class="dropdown">
                        <div class="dropdown-toggle" onclick="toggleDropdown()">
                            <span><?php echo htmlspecialchars($userName); ?></span>
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
            
            <!-- Report Filters -->
            <div class="card">
                <form action="" method="get">
                    <div class="filter-section">
                        <div class="filter-group">
                            <label class="filter-label" for="report_type">Report Type:</label>
                            <select name="report_type" id="report_type" class="filter-select">
                                <option value="user_activity" <?php echo $reportType === 'user_activity' ? 'selected' : ''; ?>>User Activity</option>
                                <option value="trainer_performance" <?php echo $reportType === 'trainer_performance' ? 'selected' : ''; ?>>Trainer Performance</option>
                                <option value="equipment_management" <?php echo $reportType === 'equipment_management' ? 'selected' : ''; ?>>Equipment Management</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label" for="month">Month:</label>
                            <select name="month" id="month" class="filter-select">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $month === $i ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label" for="year">Year:</label>
                            <select name="year" id="year" class="filter-select">
                                <?php for ($i = date('Y'); $i >= date('Y') - 5; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $year === $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-sm">Generate Report</button>
                        <a href="reports.php" class="btn btn-sm" style="background-color: var(--secondary);">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Report Tabs -->
            <div class="report-tabs">
                <a href="?report_type=user_activity&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'user_activity' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> User Activity
                </a>
                <a href="?report_type=trainer_performance&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'trainer_performance' ? 'active' : ''; ?>">
                    <i class="fas fa-user-tie"></i> Trainer Performance
                </a>
                <a href="?report_type=equipment_management&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'equipment_management' ? 'active' : ''; ?>">
                    <i class="fas fa-dumbbell"></i> Equipment Management
                </a>
            </div>
            
            <!-- Report Content -->
            <?php if ($reportType === 'user_activity'): ?>
                <div class="card">
                    <h2>User Activity Report - <?php echo getMonthName($month) . ' ' . $year; ?></h2>
                    
                    <!-- Summary Cards -->
                    <div class="summary-cards">
                        <?php
                        // Calculate summary statistics
                        $totalLogins = 0;
                        $successfulLogins = 0;
                        $failedLogins = 0;
                        
                        foreach ($reportData['login_data'] as $data) {
                            $totalLogins += $data['login_count'];
                            $successfulLogins += $data['successful_logins'];
                            $failedLogins += $data['failed_logins'];
                        }
                        
                        $totalRegistrations = 0;
                        $successfulRegistrations = 0;
                        $failedRegistrations = 0;
                        
                        foreach ($reportData['registration_data'] as $data) {
                            $totalRegistrations += $data['registration_count'];
                            $successfulRegistrations += $data['successful_registrations'];
                            $failedRegistrations += $data['failed_registrations'];
                        }
                        ?>
                        
                        <div class="summary-card">
                            <div class="summary-card-icon success">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <h4>Total Logins</h4>
                            <p><?php echo $totalLogins; ?></p>
                            <small><?php echo $successfulLogins; ?> successful, <?php echo $failedLogins; ?> failed</small>
                        </div>
                        
                        <div class="summary-card">
                            <div class="summary-card-icon info">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <h4>Total Registrations</h4>
                            <p><?php echo $totalRegistrations; ?></p>
                            <small><?php echo $successfulRegistrations; ?> successful, <?php echo $failedRegistrations; ?> failed</small>
                        </div>
                        
                        <div class="summary-card">
                            <div class="summary-card-icon warning">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4>Success Rate</h4>
                            <p><?php echo $totalLogins > 0 ? round(($successfulLogins / $totalLogins) * 100) : 0; ?>%</p>
                            <small>Login success rate</small>
                        </div>
                        
                        <div class="summary-card">
                            <div class="summary-card-icon danger">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h4>Failed Attempts</h4>
                            <p><?php echo $failedLogins; ?></p>
                            <small>Failed login attempts</small>
                        </div>
                    </div>
                    
                    <!-- Login Activity Chart -->
                    <div class="report-section">
                        <h3>Login Activity</h3>
                        <div class="chart-container">
                            <canvas id="loginChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- User Activity by Role -->
                    <div class="report-section">
                        <h3>User Activity by Role</h3>
                        <div class="chart-container">
                            <canvas id="roleChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Login Data Table -->
                    <div class="report-section">
                        <h3>Daily Login Data</h3>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Total Logins</th>
                                        <th>Successful</th>
                                        <th>Failed</th>
                                        <th>Success Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($reportData['login_data']) > 0): ?>
                                        <?php foreach ($reportData['login_data'] as $data): ?>
                                            <tr>
                                                <td><?php echo $data['day']; ?></td>
                                                <td><?php echo $data['login_count']; ?></td>
                                                <td><?php echo $data['successful_logins']; ?></td>
                                                <td><?php echo $data['failed_logins']; ?></td>
                                                <td>
                                                    <?php echo $data['login_count'] > 0 ? round(($data['successful_logins'] / $data['login_count']) * 100) : 0; ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center;">No login data available for this period.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <script>
                    // Login Activity Chart
                    const loginCtx = document.getElementById('loginChart').getContext('2d');
                    const loginChart = new Chart(loginCtx, {
                        type: 'line',
                        data: {
                            labels: [
                                <?php
                                $days = [];
                                foreach ($reportData['login_data'] as $data) {
                                    $days[] = "Day " . $data['day'];
                                }
                                echo "'" . implode("', '", $days) . "'";
                                ?>
                            ],
                            datasets: [
                                {
                                    label: 'Successful Logins',
                                    data: [
                                        <?php
                                        $successData = [];
                                        foreach ($reportData['login_data'] as $data) {
                                            $successData[] = $data['successful_logins'];
                                        }
                                        echo implode(", ", $successData);
                                        ?>
                                    ],
                                    borderColor: '#28a745',
                                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                    tension: 0.4,
                                    fill: true
                                },
                                {
                                    label: 'Failed Logins',
                                    data: [
                                        <?php
                                        $failedData = [];
                                        foreach ($reportData['login_data'] as $data) {
                                            $failedData[] = $data['failed_logins'];
                                        }
                                        echo implode(", ", $failedData);
                                        ?>
                                    ],
                                    borderColor: '#dc3545',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    tension: 0.4,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Daily Login Activity',
                                    color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                                    }
                                }
                            }
                        }
                    });
                    
                    // Role Activity Chart
                    const roleCtx = document.getElementById('roleChart').getContext('2d');
                    const roleChart = new Chart(roleCtx, {
                        type: 'pie',
                        data: {
                            labels: [
                                <?php
                                $roles = [];
                                foreach ($reportData['role_data'] as $data) {
                                    $roles[] = $data['role'];
                                }
                                echo "'" . implode("', '", $roles) . "'";
                                ?>
                            ],
                            datasets: [{
                                data: [
                                    <?php
                                    $counts = [];
                                    foreach ($reportData['role_data'] as $data) {
                                        $counts[] = $data['login_count'];
                                    }
                                    echo implode(", ", $counts);
                                    ?>
                                ],
                                backgroundColor: [
                                    '#FF8C00',
                                    '#28a745',
                                    '#dc3545',
                                    '#17a2b8'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Login Activity by Role',
                                    color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                }
                            }
                        }
                    });
                </script>
            <?php elseif ($reportType === 'trainer_performance'): ?>
                <div class="card">
                    <h2>Trainer Performance Report - <?php echo getMonthName($month) . ' ' . $year; ?></h2>
                    
                    <!-- Workout Plans Chart -->
                    <div class="report-section">
                        <h3>Workout Plans Created by Trainers</h3>
                        <div class="chart-container">
                            <canvas id="workoutChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Member Assignments Chart -->
                    <div class="report-section">
                        <h3>Member Assignments to Trainers</h3>
                        <div class="chart-container">
                            <canvas id="memberChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Trainer Performance Table -->
                    <div class="report-section">
                        <h3>Trainer Performance Data</h3>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Trainer Name</th>
                                        <th>Workout Plans Created</th>
                                        <th>Assigned Members</th>
                                        <th>Performance Rating</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($reportData['workout_data']) > 0): ?>
                                        <?php foreach ($reportData['workout_data'] as $trainer): ?>
                                            <?php
                                            // Find member count for this trainer
                                            $memberCount = 0;
                                            foreach ($reportData['member_data'] as $memberData) {
                                                if ($memberData['trainer_id'] === $trainer['trainer_id']) {
                                                    $memberCount = $memberData['member_count'];
                                                    break;
                                                }
                                            }
                                            
                                            // Calculate performance rating (simple algorithm)
                                            $workoutCount = $trainer['workout_count'];
                                            $rating = 'N/A';
                                            $badgeClass = 'badge-secondary';
                                            
                                            if ($memberCount > 0) {
                                                $ratio = $workoutCount / $memberCount;
                                                if ($ratio >= 2) {
                                                    $rating = 'Excellent';
                                                    $badgeClass = 'badge-success';
                                                } elseif ($ratio >= 1) {
                                                    $rating = 'Good';
                                                    $badgeClass = 'badge-info';
                                                } elseif ($ratio >= 0.5) {
                                                    $rating = 'Average';
                                                    $badgeClass = 'badge-warning';
                                                } else {
                                                    $rating = 'Poor';
                                                    $badgeClass = 'badge-danger';
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($trainer['trainer_name']); ?></td>
                                                <td><?php echo $trainer['workout_count']; ?></td>
                                                <td><?php echo $memberCount; ?></td>
                                                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $rating; ?></span></td>
                                                <td>
                                                    <a href="view-trainer.php?id=<?php echo $trainer['trainer_id']; ?>" class="btn btn-sm btn-info">View</a>
                                                    <?php if ($rating === 'Poor'): ?>
                                                        <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $trainer['trainer_id']; ?>, '<?php echo htmlspecialchars($trainer['trainer_name']); ?>')" class="btn btn-sm btn-danger">Remove</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center;">No trainer data available for this period.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <script>
                    // Workout Plans Chart
                    const workoutCtx = document.getElementById('workoutChart').getContext('2d');
                    const workoutChart = new Chart(workoutCtx, {
                        type: 'bar',
                        data: {
                            labels: [
                                <?php
                                $trainerNames = [];
                                foreach ($reportData['workout_data'] as $data) {
                                    $trainerNames[] = $data['trainer_name'];
                                }
                                echo "'" . implode("', '", $trainerNames) . "'";
                                ?>
                            ],
                            datasets: [{
                                label: 'Workout Plans Created',
                                data: [
                                    <?php
                                    $workoutCounts = [];
                                    foreach ($reportData['workout_data'] as $data) {
                                        $workoutCounts[] = $data['workout_count'];
                                    }
                                    echo implode(", ", $workoutCounts);
                                    ?>
                                ],
                                backgroundColor: '#FF8C00',
                                borderColor: '#e67e00',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Workout Plans Created by Trainers',
                                    color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                                    }
                                }
                            }
                        }
                    });
                    
                    // Member Assignments Chart
                    const memberCtx = document.getElementById('memberChart').getContext('2d');
                    const memberChart = new Chart(memberCtx, {
                        type: 'bar',
                        data: {
                            labels: [
                                <?php
                                $trainerNames = [];
                                foreach ($reportData['member_data'] as $data) {
                                    $trainerNames[] = $data['trainer_name'];
                                }
                                echo "'" . implode("', '", $trainerNames) . "'";
                                ?>
                            ],
                            datasets: [{
                                label: 'Assigned Members',
                                data: [
                                    <?php
                                    $memberCounts = [];
                                    foreach ($reportData['member_data'] as $data) {
                                        $memberCounts[] = $data['member_count'];
                                    }
                                    echo implode(", ", $memberCounts);
                                    ?>
                                ],
                                backgroundColor: '#17a2b8',
                                borderColor: '#138496',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Member Assignments to Trainers',
                                    color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                                    }
                                }
                            }
                        }
                    });
                </script>
            <?php elseif ($reportType === 'equipment_management'): ?>
                <div class="card">
                    <h2>Equipment Management Report - <?php echo getMonthName($month) . ' ' . $year; ?></h2>
                    
                    <!-- Equipment Status Chart -->
                    <div class="report-section">
                        <h3>Equipment Status Overview</h3>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Maintenance Schedule Table -->
                    <div class="report-section">
                        <h3>Maintenance Schedule</h3>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Equipment Name</th>
                                        <th>Scheduled Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($reportData['maintenance_data']) > 0): ?>
                                        <?php foreach ($reportData['maintenance_data'] as $maintenance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($maintenance['equipment_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($maintenance['scheduled_date'])); ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    switch ($maintenance['maintenance_status']) {
                                                        case 'Completed':
                                                            $statusClass = 'badge-success';
                                                            break;
                                                        case 'In Progress':
                                                            $statusClass = 'badge-warning';
                                                            break;
                                                        case 'Scheduled':
                                                            $statusClass = 'badge-info';
                                                            break;
                                                        case 'Overdue':
                                                            $statusClass = 'badge-danger';
                                                            break;
                                                        default:
                                                            $statusClass = 'badge-secondary';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <?php echo htmlspecialchars($maintenance['maintenance_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="#" class="btn btn-sm btn-info">View</a>
                                                    <?php if ($maintenance['maintenance_status'] === 'Overdue'): ?>
                                                        <a href="#" class="btn btn-sm btn-warning">Reschedule</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center;">No maintenance scheduled for this period.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <script>
                    // Equipment Status Chart
                    const statusCtx = document.getElementById('statusChart').getContext('2d');
                    const statusChart = new Chart(statusCtx, {
                        type: 'doughnut',
                        data: {
                            labels: [
                                <?php
                                $statuses = [];
                                foreach ($reportData['status_data'] as $data) {
                                    $statuses[] = $data['status'];
                                }
                                echo "'" . implode("', '", $statuses) . "'";
                                ?>
                            ],
                            datasets: [{
                                data: [
                                    <?php
                                    $counts = [];
                                    foreach ($reportData['status_data'] as $data) {
                                        $counts[] = $data['count'];
                                    }
                                    echo implode(", ", $counts);
                                    ?>
                                ],
                                backgroundColor: [
                                    '#28a745',
                                    '#17a2b8',
                                    '#ffc107'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    }
                                },
                                title: {
                                    display: true,
                                    text: 'Equipment Status Distribution',
                                    color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                }
                            }
                        }
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div style="background-color: var(--card-bg); margin: 15% auto; padding: 20px; border: 1px solid var(--border-color); border-radius: var(--border-radius); width: 50%; max-width: 500px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
            <h3 style="margin-bottom: 20px; color: var(--text-color);">Confirm Removal</h3>
            <p style="margin-bottom: 20px; color: var(--text-color);">Are you sure you want to remove <span id="deleteUserName"></span> due to poor performance? This action cannot be undone.</p>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="closeDeleteModal()" class="btn btn-sm" style="background-color: var(--secondary);">Cancel</button>
                <form id="deleteForm" action="delete-user.php" method="post">
                    <input type="hidden" id="deleteUserId" name="user_id">
                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
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
