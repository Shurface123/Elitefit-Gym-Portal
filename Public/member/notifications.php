<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$profileImage = $_SESSION['profile_image'] ?? '';

// Connect to database
$conn = connectDB();

// Include theme preference helper
require_once 'member-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Check if member_notifications table exists, create if not
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'member_notifications'")->rowCount() > 0;
    
    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE member_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                message TEXT NOT NULL,
                icon VARCHAR(50) DEFAULT 'bell',
                is_read TINYINT(1) DEFAULT 0,
                link VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (member_id),
                INDEX (is_read)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error
}

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    try {
        $notificationId = intval($_GET['mark_read']);
        $stmt = $conn->prepare("UPDATE member_notifications SET is_read = 1 WHERE id = ? AND member_id = ?");
        $stmt->execute([$notificationId, $userId]);
    } catch (PDOException $e) {
        // Handle error
    }
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    try {
        $stmt = $conn->prepare("UPDATE member_notifications SET is_read = 1 WHERE member_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        // Handle error
    }
}

// Get notifications with pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get total count for pagination
$countStmt = $conn->prepare("SELECT COUNT(*) FROM member_notifications WHERE member_id = ?");
$countStmt->execute([$userId]);
$totalNotifications = $countStmt->fetchColumn();
$totalPages = ceil($totalNotifications / $perPage);

// Get notifications
$notificationsStmt = $conn->prepare("
    SELECT * FROM member_notifications 
    WHERE member_id = ? 
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$notificationsStmt->execute([$userId, $perPage, $offset]);
$notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread count
$unreadStmt = $conn->prepare("SELECT COUNT(*) FROM member_notifications WHERE member_id = ? AND is_read = 0");
$unreadStmt->execute([$userId]);
$unreadCount = $unreadStmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
    <style>
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .notification-item {
            display: flex;
            padding: 15px;
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            transition: var(--transition);
        }
        
        .notification-item.unread {
            background-color: var(--primary-light);
            border-color: var(--primary);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-message {
            margin-bottom: 5px;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: rgba(var(--foreground-rgb), 0.6);
        }
        
        .notification-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            transition: var(--transition);
        }
        
        .pagination a:hover {
            background-color: var(--primary-light);
            border-color: var(--primary);
        }
        
        .pagination .active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .notification-header h3 {
            margin: 0;
        }
        
        .notification-filters {
            display: flex;
            gap: 10px;
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
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
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
                    <h1>Notifications</h1>
                    <p>Stay updated with your fitness journey</p>
                </div>
                <?php if ($unreadCount > 0): ?>
                <div class="header-actions">
                    <a href="notifications.php?mark_all_read=1" class="btn">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Notifications -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class="fas fa-bell"></i> 
                        Your Notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge"><?php echo $unreadCount; ?> unread</span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="card-content">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h3>No Notifications</h3>
                            <p>You don't have any notifications at the moment.</p>
                        </div>
                    <?php else: ?>
                        <div class="notifications-list">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notification-icon">
                                        <i class="fas fa-<?php echo $notification['icon'] ?? 'bell'; ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-message">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </div>
                                        <div class="notification-time">
                                            <?php echo timeAgo($notification['created_at']); ?>
                                        </div>
                                    </div>
                                    <div class="notification-actions">
                                        <?php if (!$notification['is_read']): ?>
                                            <a href="notifications.php?mark_read=<?php echo $notification['id']; ?>&page=<?php echo $page; ?>" class="btn btn-sm">
                                                <i class="fas fa-check"></i> Mark as Read
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($notification['link'])): ?>
                                            <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-external-link-alt"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="notifications.php?page=1"><i class="fas fa-angle-double-left"></i></a>
                                    <a href="notifications.php?page=<?php echo $page - 1; ?>"><i class="fas fa-angle-left"></i></a>
                                <?php endif; ?>
                                
                                // Replace this:
                                // Replace this section in your code where percentages are calculated

// Calculate percentages for progress bars - WITHOUT using min/max functions
$caloriesPercentage = 0;
if ($nutritionData['daily_calories'] > 0) {
    $caloriesPercentage = round(($dailyTotals['calories'] / $nutritionData['daily_calories']) * 100);
    if ($caloriesPercentage > 100) {
        $caloriesPercentage = 100;
    }
}

$proteinPercentage = 0;
if ($nutritionData['protein_target'] > 0) {
    $proteinPercentage = round(($dailyTotals['protein'] / $nutritionData['protein_target']) * 100);
    if ($proteinPercentage > 100) {
        $proteinPercentage = 100;
    }
}

$carbsPercentage = 0;
if ($nutritionData['carbs_target'] > 0) {
    $carbsPercentage = round(($dailyTotals['carbs'] / $nutritionData['carbs_target']) * 100);
    if ($carbsPercentage > 100) {
        $carbsPercentage = 100;
    }
}

$fatPercentage = 0;
if ($nutritionData['fat_target'] > 0) {
    $fatPercentage = round(($dailyTotals['fat'] / $nutritionData['fat_target']) * 100);
    if ($fatPercentage > 100) {
        $fatPercentage = 100;
    }
}

$waterPercentage = 0;
if ($nutritionData['water_target'] > 0) {
    $waterPercentage = round(($waterIntake / $nutritionData['water_target']) * 100);
    if ($waterPercentage > 100) {
        $waterPercentage = 100;
    }
}

$fiberPercentage = 0;
if ($nutritionData['fiber_target'] > 0) {
    $fiberPercentage = round(($dailyTotals['fiber'] / $nutritionData['fiber_target']) * 100);
    if ($fiberPercentage > 100) {
        $fiberPercentage = 100;
    }
}

$sugarPercentage = 0;
if ($nutritionData['sugar_target'] > 0) {
    $sugarPercentage = round(($dailyTotals['sugar'] / $nutritionData['sugar_target']) * 100);
    if ($sugarPercentage > 100) {
        $sugarPercentage = 100;
    }
}

// With this:
<?php 
$filledGlasses = ceil($waterIntake / 300);
if ($filledGlasses > 8) $filledGlasses = 8;
for ($i = 1; $i <= $filledGlasses; $i++): 
?>
    <div class="water-glass filled" data-glass="<?php echo $i; ?>"></div>
<?php endfor; ?>
                                    <?php if ($i == $page): ?>
                                        <span class="active"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="notifications.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="notifications.php?page=<?php echo $page + 1; ?>"><i class="fas fa-angle-right"></i></a>
                                    <a href="notifications.php?page=<?php echo $totalPages; ?>"><i class="fas fa-angle-double-right"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
    </script>
</body>
</html>