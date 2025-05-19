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

// Get filter parameters
$trainerFilter = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-3 months'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get all trainers for filter dropdown
$trainersStmt = $conn->prepare("
    SELECT DISTINCT u.id, u.name
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ? AND ts.status = 'completed'
    ORDER BY u.name ASC
");
$trainersStmt->execute([$userId]);
$trainers = $trainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query based on filters
$query = "
    SELECT ts.*, u.name as trainer_name, u.profile_image as trainer_image,
           sr.rating, sr.feedback, sr.created_at as rating_date
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    LEFT JOIN session_ratings sr ON sr.session_id = ts.id AND sr.member_id = ts.member_id
    WHERE ts.member_id = ? AND ts.status = 'completed'
    AND ts.start_time BETWEEN ? AND ?
";

$queryParams = [$userId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];

// Apply trainer filter
if ($trainerFilter > 0) {
    $query .= " AND ts.trainer_id = ?";
    $queryParams[] = $trainerFilter;
}

// Add order by
$query .= " ORDER BY ts.start_time DESC";

// Execute query
$stmt = $conn->prepare($query);
$stmt->execute($queryParams);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get session statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_sessions,
        SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)) as total_minutes,
        COUNT(DISTINCT trainer_id) as unique_trainers,
        AVG(sr.rating) as avg_rating
    FROM trainer_schedule ts
    LEFT JOIN session_ratings sr ON sr.session_id = ts.id AND sr.member_id = ts.member_id
    WHERE ts.member_id = ? AND ts.status = 'completed'
    AND ts.start_time BETWEEN ? AND ?
";

$statsParams = [$userId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];

// Apply trainer filter to stats
if ($trainerFilter > 0) {
    $statsQuery .= " AND ts.trainer_id = ?";
    $statsParams[] = $trainerFilter;
}

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    return date('g:i A', strtotime($time));
}

// Format duration
function formatDuration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    if ($hours > 0) {
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ($mins > 0 ? ' ' . $mins . ' min' : '');
    } else {
        return $mins . ' min';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session History - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            padding: 15px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            margin: 10px 0;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: rgba(var(--foreground-rgb), 0.7);
        }
        
        .session-item {
            display: flex;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--card-border);
        }
        
        .session-date {
            min-width: 100px;
            margin-right: 20px;
            text-align: center;
        }
        
        .session-date .date {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .session-date .time {
            font-size: 0.8rem;
            color: rgba(var(--foreground-rgb), 0.7);
        }
        
        .session-details {
            flex: 1;
        }
        
        .session-title {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .session-description {
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .session-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .session-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .session-rating {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .rating-stars {
            display: flex;
            gap: 2px;
        }
        
        .rating-stars .star {
            color: var(--primary);
        }
        
        .rating-stars .star.empty {
            color: var(--card-border);
        }
        
        .session-feedback {
            margin-top: 10px;
            padding: 10px;
            background-color: var(--primary-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-style: italic;
        }
        
        .session-actions {
            margin-left: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-end;
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
                <li><a href="appointments.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Appointments</span></a></li>
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
                    <h1>Session History</h1>
                    <p>Review your completed training sessions</p>
                </div>
                <div class="header-actions">
                    <a href="appointments.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Appointments
                    </a>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="card">
                <div class="card-content">
                    <form action="" method="get" class="filters-form">
                        <div class="form-group">
                            <label for="trainer_id">Trainer:</label>
                            <select id="trainer_id" name="trainer_id" class="form-control">
                                <option value="0">All Trainers</option>
                                <?php foreach ($trainers as $trainer): ?>
                                    <option value="<?php echo $trainer['id']; ?>" <?php echo $trainerFilter == $trainer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($trainer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="start_date">From:</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">To:</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                        </div>
                        
                        <button type="submit" class="btn">Apply Filters</button>
                        <a href="session-history.php" class="btn btn-outline">Reset</a>
                    </form>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-bar"></i> Session Statistics</h2>
                </div>
                <div class="card-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Sessions</div>
                            <div class="stat-value"><?php echo $stats['total_sessions'] ?? 0; ?></div>
                            <div class="stat-description">Completed sessions</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Total Time</div>
                            <div class="stat-value"><?php echo formatDuration($stats['total_minutes'] ?? 0); ?></div>
                            <div class="stat-description">Training time</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Trainers</div>
                            <div class="stat-value"><?php echo $stats['unique_trainers'] ?? 0; ?></div>
                            <div class="stat-description">Different trainers</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Average Rating</div>
                            <div class="stat-value">
                                <?php 
                                    $avgRating = $stats['avg_rating'] ?? 0;
                                    echo $avgRating > 0 ? number_format($avgRating, 1) : 'N/A'; 
                                ?>
                            </div>
                            <div class="stat-description">Session satisfaction</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Session History -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> Completed Sessions</h2>
                </div>
                <div class="card-content">
                    <?php if (empty($sessions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-check"></i>
                            <h3>No Completed Sessions</h3>
                            <p>You don't have any completed sessions in the selected date range.</p>
                        </div>
                    <?php else: ?>
                        <div class="sessions-list">
                            <?php foreach ($sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-date">
                                        <div class="date"><?php echo formatDate($session['start_time']); ?></div>
                                        <div class="time"><?php echo formatTime($session['start_time']); ?></div>
                                        <div class="duration">
                                            <?php 
                                                $duration = (strtotime($session['end_time']) - strtotime($session['start_time'])) / 60;
                                                echo formatDuration($duration);
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="session-details">
                                        <div class="session-title"><?php echo htmlspecialchars($session['title']); ?></div>
                                        
                                        <?php if (!empty($session['description'])): ?>
                                            <div class="session-description">
                                                <?php echo nl2br(htmlspecialchars($session['description'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="session-meta">
                                            <div class="meta-item">
                                                <i class="fas fa-user"></i>
                                                <span>Trainer: <?php echo htmlspecialchars($session['trainer_name']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if (isset($session['rating'])): ?>
                                            <div class="session-rating">
                                                <div class="rating-label">Your Rating:</div>
                                                <div class="rating-stars">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <span class="star <?php echo $i <= $session['rating'] ? '' : 'empty'; ?>">
                                                            <i class="fas fa-star"></i>
                                                        </span>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($session['feedback'])): ?>
                                                <div class="session-feedback">
                                                    "<?php echo htmlspecialchars($session['feedback']); ?>"
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="session-actions">
                                        <?php if (!isset($session['rating'])): ?>
                                            <a href="session-rating.php?session_id=<?php echo $session['id']; ?>" class="btn btn-sm">
                                                <i class="fas fa-star"></i> Rate Session
                                            </a>
                                        <?php else: ?>
                                            <a href="session-rating.php?session_id=<?php echo $session['id']; ?>" class="btn btn-sm btn-outline">
                                                <i class="fas fa-edit"></i> Edit Rating
                                            </a>
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
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
    </script>
</body>
</html>
