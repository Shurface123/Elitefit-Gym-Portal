<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

// Include theme preference helper
require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Get filter parameters
$filterType = $_GET['type'] ?? '';
$filterMember = $_GET['member'] ?? '';
$filterDate = $_GET['date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Get activities with advanced filtering
$activities = getTrainerActivities($conn, $userId, $filterType, $filterMember, $filterDate, $limit, $offset);
$totalActivities = getTotalActivities($conn, $userId, $filterType, $filterMember, $filterDate);
$totalPages = ceil($totalActivities / $limit);

// Get activity statistics
$activityStats = getActivityStats($conn, $userId);

// Get members for filter dropdown
$members = getTrainerMembers($conn, $userId);

// Get activity types for filter
$activityTypes = ['session', 'workout', 'nutrition', 'progress', 'member', 'assessment'];

function getTrainerActivities($conn, $trainerId, $filterType, $filterMember, $filterDate, $limit, $offset) {
    try {
        $whereConditions = ["ta.trainer_id = ?"];
        $params = [$trainerId];
        
        if (!empty($filterType)) {
            $whereConditions[] = "ta.activity_type = ?";
            $params[] = $filterType;
        }
        
        if (!empty($filterMember)) {
            $whereConditions[] = "ta.member_id = ?";
            $params[] = $filterMember;
        }
        
        if (!empty($filterDate)) {
            $whereConditions[] = "DATE(ta.created_at) = ?";
            $params[] = $filterDate;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $stmt = $conn->prepare("
            SELECT ta.*, 
                   u.name as member_name,
                   u.profile_image as member_image,
                   u.email as member_email,
                   CASE 
                       WHEN ta.activity_type = 'session' THEN ts.title
                       WHEN ta.activity_type = 'workout' THEN wp.title
                       WHEN ta.activity_type = 'nutrition' THEN np.plan_name
                       ELSE ta.title
                   END as related_title,
                   CASE 
                       WHEN ta.activity_type = 'session' THEN ts.start_time
                       WHEN ta.activity_type = 'workout' THEN wp.created_at
                       WHEN ta.activity_type = 'nutrition' THEN np.created_at
                       ELSE ta.created_at
                   END as related_date
            FROM trainer_activity ta
            LEFT JOIN users u ON ta.member_id = u.id
            LEFT JOIN trainer_schedule ts ON (ta.activity_type = 'session' AND JSON_EXTRACT(ta.metadata, '$.session_id') = ts.id)
            LEFT JOIN workout_plans wp ON (ta.activity_type = 'workout' AND JSON_EXTRACT(ta.metadata, '$.workout_id') = wp.id)
            LEFT JOIN nutrition_plans np ON (ta.activity_type = 'nutrition' AND JSON_EXTRACT(ta.metadata, '$.nutrition_id') = np.id)
            WHERE {$whereClause}
            ORDER BY ta.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting trainer activities: " . $e->getMessage());
        return [];
    }
}

function getTotalActivities($conn, $trainerId, $filterType, $filterMember, $filterDate) {
    try {
        $whereConditions = ["trainer_id = ?"];
        $params = [$trainerId];
        
        if (!empty($filterType)) {
            $whereConditions[] = "activity_type = ?";
            $params[] = $filterType;
        }
        
        if (!empty($filterMember)) {
            $whereConditions[] = "member_id = ?";
            $params[] = $filterMember;
        }
        
        if (!empty($filterDate)) {
            $whereConditions[] = "DATE(created_at) = ?";
            $params[] = $filterDate;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM trainer_activity WHERE {$whereClause}");
        $stmt->execute($params);
        
        return $stmt->fetch()['total'];
    } catch (PDOException $e) {
        error_log("Error getting total activities: " . $e->getMessage());
        return 0;
    }
}

function getActivityStats($conn, $trainerId) {
    try {
        $stats = [];
        
        // Total activities
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM trainer_activity WHERE trainer_id = ?");
        $stmt->execute([$trainerId]);
        $stats['total'] = $stmt->fetch()['total'];
        
        // Today's activities
        $stmt = $conn->prepare("
            SELECT COUNT(*) as today 
            FROM trainer_activity 
            WHERE trainer_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$trainerId]);
        $stats['today'] = $stmt->fetch()['today'];
        
        // This week's activities
        $stmt = $conn->prepare("
            SELECT COUNT(*) as week 
            FROM trainer_activity 
            WHERE trainer_id = ? AND WEEK(created_at) = WEEK(NOW()) AND YEAR(created_at) = YEAR(NOW())
        ");
        $stmt->execute([$trainerId]);
        $stats['week'] = $stmt->fetch()['week'];
        
        // Activities by type
        $stmt = $conn->prepare("
            SELECT activity_type, COUNT(*) as count 
            FROM trainer_activity 
            WHERE trainer_id = ? 
            GROUP BY activity_type
        ");
        $stmt->execute([$trainerId]);
        $stats['by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Most active member
        $stmt = $conn->prepare("
            SELECT u.name, COUNT(*) as activity_count
            FROM trainer_activity ta
            JOIN users u ON ta.member_id = u.id
            WHERE ta.trainer_id = ? AND ta.member_id IS NOT NULL
            GROUP BY ta.member_id, u.name
            ORDER BY activity_count DESC
            LIMIT 1
        ");
        $stmt->execute([$trainerId]);
        $mostActive = $stmt->fetch();
        $stats['most_active_member'] = $mostActive ? $mostActive['name'] : 'None';
        $stats['most_active_count'] = $mostActive ? $mostActive['activity_count'] : 0;
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Error getting activity stats: " . $e->getMessage());
        return [
            'total' => 0,
            'today' => 0,
            'week' => 0,
            'by_type' => [],
            'most_active_member' => 'None',
            'most_active_count' => 0
        ];
    }
}

function getTrainerMembers($conn, $trainerId) {
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.profile_image
            FROM trainer_members tm
            JOIN users u ON tm.member_id = u.id
            WHERE tm.trainer_id = ? AND tm.status = 'active'
            ORDER BY u.name ASC
        ");
        $stmt->execute([$trainerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting trainer members: " . $e->getMessage());
        return [];
    }
}

// Helper functions
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return "Just now";
    } elseif ($difference < 3600) {
        $minutes = round($difference / 60);
        return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
    } elseif ($difference < 86400) {
        $hours = round($difference / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    } elseif ($difference < 604800) {
        $days = round($difference / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    } else {
        return date('M j, Y', $timestamp);
    }
}

function formatDateTime($datetime) {
    return date('M j, Y g:i A', strtotime($datetime));
}

function getActivityIcon($type) {
    $icons = [
        'session' => 'fas fa-calendar-check',
        'workout' => 'fas fa-dumbbell',
        'nutrition' => 'fas fa-apple-alt',
        'progress' => 'fas fa-chart-line',
        'member' => 'fas fa-user',
        'assessment' => 'fas fa-clipboard-check'
    ];
    
    return $icons[$type] ?? 'fas fa-info-circle';
}

function getActivityColor($type) {
    $colors = [
        'session' => '#3498db',
        'workout' => '#e74c3c',
        'nutrition' => '#27ae60',
        'progress' => '#f39c12',
        'member' => '#9b59b6',
        'assessment' => '#34495e'
    ];
    
    return $colors[$type] ?? '#95a5a6';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --secondary: #2c3e50;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --bg-dark: #0a0a0a;
            --card-dark: #1a1a1a;
            --text-dark: #ecf0f1;
            --border-dark: #333333;
            --bg-light: #f8f9fa;
            --card-light: #ffffff;
            --text-light: #2c3e50;
            --border-light: #dee2e6;
        }

        [data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-dark);
            --text: var(--text-dark);
            --border: var(--border-dark);
        }

        [data-theme="light"] {
            --bg: var(--bg-light);
            --card-bg: var(--card-light);
            --text: var(--text-light);
            --border: var(--border-light);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: all 0.3s ease;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
        }

        .stat-content h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text);
            opacity: 0.8;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .filter-bar {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--text);
            font-size: 0.875rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--bg);
            color: var(--text);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .activity-timeline {
            background: var(--card-bg);
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .timeline-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 107, 53, 0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .timeline-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .activity-list {
            max-height: 800px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
        }

        .activity-item:hover {
            background: rgba(255, 107, 53, 0.02);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
            flex-shrink: 0;
            position: relative;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .activity-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .activity-description {
            color: var(--text);
            opacity: 0.8;
            margin-bottom: 0.75rem;
            line-height: 1.5;
        }

        .activity-meta {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .activity-time {
            font-size: 0.875rem;
            color: var(--text);
            opacity: 0.6;
            font-weight: 500;
        }

        .activity-member {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .member-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
        }

        .member-avatar-placeholder {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .activity-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .type-session {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .type-workout {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .type-nutrition {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .type-progress {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .type-member {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .type-assessment {
            background: rgba(52, 73, 94, 0.1);
            color: #34495e;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            color: var(--text);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .current {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .activity-chart {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        .export-actions {
            display: flex;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                grid-template-columns: 1fr;
            }

            .activity-item {
                flex-direction: column;
                gap: 0.75rem;
            }

            .activity-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .activity-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1><i class="fas fa-history"></i> Activity Log</h1>
                <p>Comprehensive view of all your training activities and member interactions</p>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <div class="export-actions">
                    <button class="btn btn-outline" onclick="exportActivities('csv')">
                        <i class="fas fa-download"></i> Export CSV
                    </button>
                    <button class="btn btn-outline" onclick="exportActivities('pdf')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-content">
                    <h3>Total Activities</h3>
                    <div class="stat-value"><?php echo $activityStats['total']; ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <h3>Today's Activities</h3>
                    <div class="stat-value"><?php echo $activityStats['today']; ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stat-content">
                    <h3>This Week</h3>
                    <div class="stat-value"><?php echo $activityStats['week']; ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-star"></i>
                </div>
                <div class="stat-content">
                    <h3>Most Active Member</h3>
                    <div class="stat-value" style="font-size: 1.5rem;"><?php echo htmlspecialchars($activityStats['most_active_member']); ?></div>
                    <div style="font-size: 0.875rem; opacity: 0.7;"><?php echo $activityStats['most_active_count']; ?> activities</div>
                </div>
            </div>
        </div>

        <!-- Activity Chart -->
        <div class="activity-chart">
            <h3 style="margin-bottom: 1rem;"><i class="fas fa-chart-bar"></i> Activity Distribution</h3>
            <div class="chart-container">
                <canvas id="activityChart"></canvas>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label for="filterType">Activity Type</label>
                <select id="filterType" name="type" class="form-control">
                    <option value="">All Types</option>
                    <?php foreach ($activityTypes as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo $filterType === $type ? 'selected' : ''; ?>>
                            <?php echo ucfirst($type); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="filterMember">Member</label>
                <select id="filterMember" name="member" class="form-control">
                    <option value="">All Members</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?php echo $member['id']; ?>" <?php echo $filterMember == $member['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($member['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="filterDate">Date</label>
                <input type="date" id="filterDate" name="date" class="form-control" value="<?php echo htmlspecialchars($filterDate); ?>">
            </div>
            
            <div class="filter-group">
                <label>&nbsp;</label>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" class="btn" onclick="applyFilters()">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-outline" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- Activity Timeline -->
        <div class="activity-timeline">
            <div class="timeline-header">
                <h2><i class="fas fa-timeline"></i> Activity Timeline</h2>
                <div style="font-size: 0.875rem; color: var(--text); opacity: 0.7;">
                    Showing <?php echo count($activities); ?> of <?php echo $totalActivities; ?> activities
                </div>
            </div>
            
            <div class="activity-list">
                <?php if (empty($activities)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No activities found</h3>
                        <p>No activities match your current filters</p>
                        <button class="btn" onclick="clearFilters()">
                            <i class="fas fa-refresh"></i> Clear Filters
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon" style="background-color: <?php echo getActivityColor($activity['activity_type']); ?>">
                                <i class="<?php echo getActivityIcon($activity['activity_type']); ?>"></i>
                            </div>
                            
                            <div class="activity-content">
                                <div class="activity-header">
                                    <div>
                                        <h3 class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></h3>
                                        <span class="activity-type-badge type-<?php echo $activity['activity_type']; ?>">
                                            <?php echo ucfirst($activity['activity_type']); ?>
                                        </span>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo timeAgo($activity['created_at']); ?>
                                    </div>
                                </div>
                                
                                <div class="activity-description">
                                    <?php echo htmlspecialchars($activity['description']); ?>
                                </div>
                                
                                <div class="activity-meta">
                                    <?php if (!empty($activity['member_name'])): ?>
                                        <div class="activity-member">
                                            <?php if (!empty($activity['member_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($activity['member_image']); ?>" alt="Profile" class="member-avatar">
                                            <?php else: ?>
                                                <div class="member-avatar-placeholder">
                                                    <?php echo strtoupper(substr($activity['member_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($activity['member_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="font-size: 0.875rem; color: var(--text); opacity: 0.6;">
                                        <?php echo formatDateTime($activity['created_at']); ?>
                                    </div>
                                    
                                    <?php if (!empty($activity['related_title'])): ?>
                                        <div style="font-size: 0.875rem; color: var(--primary);">
                                            Related: <?php echo htmlspecialchars($activity['related_title']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&type=<?php echo urlencode($filterType); ?>&member=<?php echo urlencode($filterMember); ?>&date=<?php echo urlencode($filterDate); ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&type=<?php echo urlencode($filterType); ?>&member=<?php echo urlencode($filterMember); ?>&date=<?php echo urlencode($filterDate); ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&type=<?php echo urlencode($filterType); ?>&member=<?php echo urlencode($filterMember); ?>&date=<?php echo urlencode($filterDate); ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize activity chart
        const activityData = <?php echo json_encode($activityStats['by_type']); ?>;
        const ctx = document.getElementById('activityChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(activityData).map(key => key.charAt(0).toUpperCase() + key.slice(1)),
                datasets: [{
                    data: Object.values(activityData),
                    backgroundColor: [
                        '#3498db', '#e74c3c', '#27ae60', '#f39c12', '#9b59b6', '#34495e'
                    ],
                    borderWidth: 2,
                    borderColor: 'var(--card-bg)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });
        
        // Filter functions
        function applyFilters() {
            const type = document.getElementById('filterType').value;
            const member = document.getElementById('filterMember').value;
            const date = document.getElementById('filterDate').value;
            
            const params = new URLSearchParams();
            if (type) params.append('type', type);
            if (member) params.append('member', member);
            if (date) params.append('date', date);
            
            window.location.href = 'activity.php?' + params.toString();
        }
        
        function clearFilters() {
            window.location.href = 'activity.php';
        }
        
        // Export functions
        function exportActivities(format) {
            const type = document.getElementById('filterType').value;
            const member = document.getElementById('filterMember').value;
            const date = document.getElementById('filterDate').value;
            
            const params = new URLSearchParams();
            params.append('export', format);
            if (type) params.append('type', type);
            if (member) params.append('member', member);
            if (date) params.append('date', date);
            
            window.open('export-activities.php?' + params.toString(), '_blank');
        }
        
        // Auto-refresh every 30 seconds for real-time updates
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>
