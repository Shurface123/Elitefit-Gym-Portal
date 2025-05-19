<?php
// Start session
session_start();

// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Include database connection
require_once __DIR__ . '/../db_connect.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
$conn = connectDB();

// Get date range filter
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get registration statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_registrations,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as completed_registrations,
        SUM(CASE WHEN status = 'Pending Email Verification' THEN 1 ELSE 0 END) as pending_email_verification,
        SUM(CASE WHEN status = 'Pending Admin Approval' THEN 1 ELSE 0 END) as pending_admin_approval,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_registrations,
        ROUND((SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as completion_rate
    FROM registration_analytics
    WHERE registration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
";
$statsStmt = $conn->prepare($statsQuery);
$statsStmt->execute([$startDate, $endDate]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get registration by role
$roleQuery = "
    SELECT 
        role,
        COUNT(*) as count,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as completed,
        ROUND((SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as completion_rate
    FROM registration_analytics
    WHERE registration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY role
    ORDER BY count DESC
";
$roleStmt = $conn->prepare($roleQuery);
$roleStmt->execute([$startDate, $endDate]);
$roleStats = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily registration counts
$dailyQuery = "
    SELECT 
        DATE(registration_date) as date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as completed
    FROM registration_analytics
    WHERE registration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(registration_date)
    ORDER BY date
";
$dailyStmt = $conn->prepare($dailyQuery);
$dailyStmt->execute([$startDate, $endDate]);
$dailyStats = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

// Get completion step breakdown
$stepQuery = "
    SELECT 
        completion_step,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM registration_analytics WHERE registration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY))) * 100, 2) as percentage
    FROM registration_analytics
    WHERE registration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY completion_step
    ORDER BY 
        CASE 
            WHEN completion_step = 'Started' THEN 1
            WHEN completion_step = 'Form Submitted' THEN 2
            WHEN completion_step = 'Verification Completed' THEN 3
            WHEN completion_step = 'Email Verified' THEN 4
            WHEN completion_step = 'Admin Approved' THEN 5
            WHEN completion_step = 'Admin Rejected' THEN 6
            ELSE 7
        END
";
$stepStmt = $conn->prepare($stepQuery);
$stepStmt->execute([$startDate, $endDate, $startDate, $endDate]);
$stepStats = $stepStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent registrations
$recentQuery = "
    SELECT 
        ra.id,
        ra.email,
        ra.role,
        ra.registration_date,
        ra.ip_address,
        ra.completion_step,
        ra.status,
        u.name
    FROM registration_analytics ra
    LEFT JOIN users u ON ra.user_id = u.id
    WHERE ra.registration_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    ORDER BY ra.registration_date DESC
    LIMIT 10
";
$recentStmt = $conn->prepare($recentQuery);
$recentStmt->execute([$startDate, $endDate]);
$recentRegistrations = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for charts
$dailyLabels = [];
$dailyTotals = [];
$dailyCompleted = [];

foreach ($dailyStats as $day) {
    $dailyLabels[] = date('M d', strtotime($day['date']));
    $dailyTotals[] = $day['total'];
    $dailyCompleted[] = $day['completed'];
}

$stepLabels = [];
$stepCounts = [];
$stepPercentages = [];

foreach ($stepStats as $step) {
    $stepLabels[] = $step['completion_step'];
    $stepCounts[] = $step['count'];
    $stepPercentages[] = $step['percentage'];
}

$roleLabels = [];
$roleCounts = [];
$roleCompletionRates = [];

foreach ($roleStats as $role) {
    $roleLabels[] = $role['role'];
    $roleCounts[] = $role['count'];
    $roleCompletionRates[] = $role['completion_rate'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Analytics - EliteFit Gym Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .filter-form {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: flex-end;
        }
        
        .form-group {
            flex: 1;
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
        
        .btn {
            padding: 10px 15px;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-block;
            text-decoration: none;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
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
        
        .badge-info {
            background-color: rgba(23, 162, 184, 0.1);
            color: var(--info);
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
            
            .filter-form {
                flex-direction: column;
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
                <li><a href="user_approval.php"><i class="fas fa-user-check"></i> <span>User Approval</span></a></li>
                <li><a href="registration_analytics.php" class="active"><i class="fas fa-chart-bar"></i> <span>Registration Analytics</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>Registration Analytics</h1>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/men/1.jpg" alt="Admin Avatar">
                    <span><?php echo htmlspecialchars($userName); ?></span>
                </div>
            </div>
            
            <!-- Date Filter -->
            <div class="card">
                <div class="card-header">
                    <h2>Date Range Filter</h2>
                </div>
                <div class="card-body">
                    <form action="" method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Apply Filter</button>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_registrations']; ?></div>
                    <div class="stat-label">Total Registrations</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--success);">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['completed_registrations']; ?></div>
                    <div class="stat-label">Completed Registrations</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--warning);">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_email_verification']; ?></div>
                    <div class="stat-label">Pending Email Verification</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--info);">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['pending_admin_approval']; ?></div>
                    <div class="stat-label">Pending Admin Approval</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--danger);">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['rejected_registrations']; ?></div>
                    <div class="stat-label">Rejected Registrations</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: var(--primary);">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['completion_rate']; ?>%</div>
                    <div class="stat-label">Completion Rate</div>
                </div>
            </div>
            
            <!-- Daily Registration Chart -->
            <div class="card">
                <div class="card-header">
                    <h2>Daily Registration Trend</h2>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Registration by Role Chart -->
            <div class="card">
                <div class="card-header">
                    <h2>Registration by Role</h2>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="roleChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Registration Funnel Chart -->
            <div class="card">
                <div class="card-header">
                    <h2>Registration Funnel</h2>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="funnelChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Recent Registrations -->
            <div class="card">
                <div class="card-header">
                    <h2>Recent Registrations</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name/Email</th>
                                    <th>Role</th>
                                    <th>Registration Date</th>
                                    <th>IP Address</th>
                                    <th>Completion Step</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRegistrations as $registration): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($registration['name'])): ?>
                                                <?php echo htmlspecialchars($registration['name']); ?><br>
                                                <small><?php echo htmlspecialchars($registration['email']); ?></small>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($registration['email']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($registration['role']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($registration['registration_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($registration['ip_address']); ?></td>
                                        <td><?php echo htmlspecialchars($registration['completion_step']); ?></td>
                                        <td>
                                            <?php 
                                                $badgeClass = '';
                                                switch ($registration['status']) {
                                                    case 'Active':
                                                        $badgeClass = 'badge-success';
                                                        break;
                                                    case 'Pending Email Verification':
                                                    case 'Pending Admin Approval':
                                                        $badgeClass = 'badge-warning';
                                                        break;
                                                    case 'Rejected':
                                                        $badgeClass = 'badge-danger';
                                                        break;
                                                    default:
                                                        $badgeClass = 'badge-info';
                                                }
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($registration['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Daily Registration Chart
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyChart = new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dailyLabels); ?>,
                datasets: [
                    {
                        label: 'Total Registrations',
                        data: <?php echo json_encode($dailyTotals); ?>,
                        backgroundColor: 'rgba(255, 102, 0, 0.2)',
                        borderColor: 'rgba(255, 102, 0, 1)',
                        borderWidth: 2,
                        tension: 0.1
                    },
                    {
                        label: 'Completed Registrations',
                        data: <?php echo json_encode($dailyCompleted); ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.2)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2,
                        tension: 0.1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        
        // Registration by Role Chart
        const roleCtx = document.getElementById('roleChart').getContext('2d');
        const roleChart = new Chart(roleCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($roleLabels); ?>,
                datasets: [
                    {
                        label: 'Total Registrations',
                        data: <?php echo json_encode($roleCounts); ?>,
                        backgroundColor: 'rgba(255, 102, 0, 0.7)',
                        borderColor: 'rgba(255, 102, 0, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        
        // Registration Funnel Chart
        const funnelCtx = document.getElementById('funnelChart').getContext('2d');
        const funnelChart = new Chart(funnelCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($stepLabels); ?>,
                datasets: [
                    {
                        label: 'Number of Users',
                        data: <?php echo json_encode($stepCounts); ?>,
                        backgroundColor: [
                            'rgba(255, 102, 0, 0.7)',
                            'rgba(255, 140, 0, 0.7)',
                            'rgba(255, 193, 7, 0.7)',
                            'rgba(40, 167, 69, 0.7)',
                            'rgba(23, 162, 184, 0.7)',
                            'rgba(220, 53, 69, 0.7)'
                        ],
                        borderColor: [
                            'rgba(255, 102, 0, 1)',
                            'rgba(255, 140, 0, 1)',
                            'rgba(255, 193, 7, 1)',
                            'rgba(40, 167, 69, 1)',
                            'rgba(23, 162, 184, 1)',
                            'rgba(220, 53, 69, 1)'
                        ],
                        borderWidth: 1
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
