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

// Include theme preference helper
require_once 'dashboard-theme-fix.php';
$theme = getThemePreference($conn, $userId);

// Get trainer stats
$stats = [
    'total_members' => 0,
    'active_sessions' => 0,
    'completed_workouts' => 0,
    'upcoming_sessions' => 0
];

try {
    // Check if trainer_members table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_members'")->rowCount() > 0;
    
    if ($tableExists) {
        // Check if status column exists in trainer_members
        $statusColumnExists = $conn->query("SHOW COLUMNS FROM trainer_members LIKE 'status'")->rowCount() > 0;
        
        $memberQuery = "SELECT COUNT(*) as count FROM trainer_members WHERE trainer_id = ?";
        if ($statusColumnExists) {
            $memberQuery .= " AND status = 'active'";
        }
        
        $stmt = $conn->prepare($memberQuery);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_members'] = $result['count'];
    }
    
    // Check if trainer_schedule table exists
    if ($conn->query("SHOW TABLES LIKE 'trainer_schedule'")->rowCount() > 0) {
        // Active sessions (today)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND DATE(start_time) = CURDATE() 
            AND status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['active_sessions'] = $result['count'];
        
        // Upcoming sessions (future)
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND DATE(start_time) > CURDATE() 
            AND status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['upcoming_sessions'] = $result['count'];
    }
    
    // Check if workouts table exists
    $workoutsTableExists = $conn->query("SHOW TABLES LIKE 'workouts'")->rowCount() > 0;
    if (!$workoutsTableExists) {
        $workoutsTableExists = $conn->query("SHOW TABLES LIKE 'workout'")->rowCount() > 0;
    }
    
    if ($workoutsTableExists) {
        // Try with 'workouts' table first
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM workouts 
                WHERE trainer_id = ? 
                AND status = 'completed'
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['completed_workouts'] = $result['count'];
        } catch (PDOException $e) {
            // If that fails, try with 'workout' table
            try {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM workout 
                    WHERE trainer_id = ? 
                    AND status = 'completed'
                ");
                $stmt->execute([$userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $stats['completed_workouts'] = $result['count'];
            } catch (PDOException $e) {
                // If both fail, leave the default value
            }
        }
    }
} catch (PDOException $e) {
    // Handle error - stats already initialized with defaults
}

// Get recent members
$recentMembers = [];
try {
    // Check if trainer_members table exists
    if ($conn->query("SHOW TABLES LIKE 'trainer_members'")->rowCount() > 0) {
        $stmt = $conn->prepare("
            SELECT m.id, m.name, m.email, m.profile_image, tm.joined_date
            FROM trainer_members tm
            JOIN users m ON tm.member_id = m.id
            WHERE tm.trainer_id = ?
            ORDER BY tm.joined_date DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $recentMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty array already set
}

// Get upcoming sessions
$upcomingSessions = [];
try {
    // Check if trainer_schedule table exists
    if ($conn->query("SHOW TABLES LIKE 'trainer_schedule'")->rowCount() > 0) {
        $stmt = $conn->prepare("
            SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status, 
                   m.id as member_id, m.name as member_name, m.profile_image
            FROM trainer_schedule ts
            LEFT JOIN users m ON ts.member_id = m.id
            WHERE ts.trainer_id = ? 
            AND ts.start_time >= NOW()
            AND ts.status IN ('scheduled', 'confirmed')
            ORDER BY ts.start_time ASC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $upcomingSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty array already set
}

// Get recent activity
$recentActivity = [];
try {
    // Check if trainer_activity table exists
    if ($conn->query("SHOW TABLES LIKE 'trainer_activity'")->rowCount() > 0) {
        $stmt = $conn->prepare("
            SELECT ta.id, ta.activity_type, ta.description, ta.created_at, 
                   m.id as member_id, m.name as member_name, m.profile_image
            FROM trainer_activity ta
            LEFT JOIN users m ON ta.member_id = m.id
            WHERE ta.trainer_id = ?
            ORDER BY ta.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty array already set
}

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Dashboard - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/trainer-dashboard.css">
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
                    <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
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
                    <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Welcome, <?php echo htmlspecialchars($userName); ?></h1>
                    <p>Here's what's happening with your clients today</p>
                </div>
                <div class="user-menu">
                    <div class="user-info">
                        <span><?php echo htmlspecialchars($userName); ?></span>
                        <span class="role-badge">Trainer</span>
                    </div>
                    <div class="user-avatar">
                        <?php if (isset($_SESSION['profile_image']) && !empty($_SESSION['profile_image'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['profile_image']); ?>" alt="Profile">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <?php echo strtoupper(substr($userName, 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Members</h3>
                        <div class="stat-value"><?php echo $stats['total_members']; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Today's Sessions</h3>
                        <div class="stat-value"><?php echo $stats['active_sessions']; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Completed Workouts</h3>
                        <div class="stat-value"><?php echo $stats['completed_workouts']; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Upcoming Sessions</h3>
                        <div class="stat-value"><?php echo $stats['upcoming_sessions']; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Main Dashboard Content -->
            <div class="dashboard-grid">
                <!-- Upcoming Sessions -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-day"></i> Upcoming Sessions</h2>
                        <a href="schedule.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($upcomingSessions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar"></i>
                                <p>No upcoming sessions</p>
                                <a href="schedule.php" class="btn btn-primary">Schedule a Session</a>
                            </div>
                        <?php else: ?>
                            <div class="sessions-list">
                                <?php foreach ($upcomingSessions as $session): ?>
                                    <div class="session-item">
                                        <div class="session-time">
                                            <div class="date"><?php echo formatDate($session['start_time']); ?></div>
                                            <div class="time"><?php echo formatTime($session['start_time']); ?> - <?php echo formatTime($session['end_time']); ?></div>
                                        </div>
                                        
                                        <div class="session-details">
                                            <h4><?php echo htmlspecialchars($session['title']); ?></h4>
                                            <?php if (!empty($session['member_name'])): ?>
                                                <div class="session-member">
                                                    <?php if (!empty($session['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($session['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($session['member_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($session['member_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="session-status">
                                            <span class="status-badge <?php echo $session['status']; ?>">
                                                <?php echo ucfirst($session['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Members -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-plus"></i> Recent Members</h2>
                        <a href="members.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recentMembers)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No members yet</p>
                                <a href="members.php" class="btn btn-primary">Add Members</a>
                            </div>
                        <?php else: ?>
                            <div class="members-list">
                                <?php foreach ($recentMembers as $member): ?>
                                    <div class="member-item">
                                        <div class="member-avatar">
                                            <?php if (!empty($member['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($member['profile_image']); ?>" alt="Profile">
                                            <?php else: ?>
                                                <div class="avatar-placeholder">
                                                    <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="member-details">
                                            <h4><?php echo htmlspecialchars($member['name']); ?></h4>
                                            <div class="member-email"><?php echo htmlspecialchars($member['email']); ?></div>
                                            <?php if (isset($member['joined_date'])): ?>
                                                <div class="member-joined">Joined: <?php echo formatDate($member['joined_date']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="member-actions">
                                            <a href="members.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Activity Chart -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Workout Activity</h2>
                    </div>
                    <div class="card-content">
                        <div class="chart-container">
                            <canvas id="workoutActivityChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Member Stats -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-pie"></i> Member Statistics</h2>
                    </div>
                    <div class="card-content">
                        <div class="chart-container">
                            <canvas id="memberStatsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="card full-width">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Activity</h2>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recentActivity)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No recent activity</p>
                            </div>
                        <?php else: ?>
                            <div class="activity-timeline">
                                <?php foreach ($recentActivity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?php 
                                                $iconClass = 'fas fa-info-circle';
                                                switch ($activity['activity_type']) {
                                                    case 'session':
                                                        $iconClass = 'fas fa-calendar-check';
                                                        break;
                                                    case 'workout':
                                                        $iconClass = 'fas fa-dumbbell';
                                                        break;
                                                    case 'member':
                                                        $iconClass = 'fas fa-user';
                                                        break;
                                                    case 'progress':
                                                        $iconClass = 'fas fa-chart-line';
                                                        break;
                                                }
                                            ?>
                                            <i class="<?php echo $iconClass; ?>"></i>
                                        </div>
                                        
                                        <div class="activity-content">
                                            <div class="activity-header">
                                                <h4><?php echo htmlspecialchars($activity['activity_type']); ?></h4>
                                                <span class="activity-time">
                                                    <?php echo formatDate($activity['created_at']); ?> at <?php echo formatTime($activity['created_at']); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="activity-description">
                                                <?php echo htmlspecialchars($activity['description']); ?>
                                            </div>
                                            
                                            <?php if (!empty($activity['member_name'])): ?>
                                                <div class="activity-member">
                                                    <?php if (!empty($activity['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($activity['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($activity['member_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($activity['member_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../assets/js/trainer-dashboard.js"></script>
</body>
</html>
