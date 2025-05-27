<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userEmail = $_SESSION['email'] ?? '';
$profileImage = $_SESSION['profile_image'] ?? '';
$specialization = $_SESSION['specialization'] ?? 'General Fitness';

// Connect to database
// require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

// Include theme preference helper
require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Create necessary tables if they don't exist
createTrainerTables($conn);

// Get comprehensive trainer statistics
$stats = getTrainerStats($conn, $userId);

// Get upcoming sessions with real-time data
$upcomingSessions = getUpcomingSessions($conn, $userId);

// Get recent members with enhanced data
$recentMembers = getRecentMembers($conn, $userId);

// Get real-time workout activities
$workoutActivities = getWorkoutActivities($conn, $userId, $specialization);

// Get recent activities with advanced filtering
$recentActivities = getRecentActivities($conn, $userId);

// Get calendar events for the month
$calendarEvents = getCalendarEvents($conn, $userId);

// Get nutrition plans overview
$nutritionOverview = getNutritionOverview($conn, $userId);

// Get member progress data for charts
$progressData = getMemberProgressData($conn, $userId);

// Helper functions
function createTrainerTables($conn) {
    try {
        // Trainer schedule table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS trainer_schedule (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                start_time DATETIME NOT NULL,
                end_time DATETIME NOT NULL,
                status ENUM('scheduled', 'confirmed', 'completed', 'cancelled') DEFAULT 'scheduled',
                session_type ENUM('personal', 'group', 'consultation', 'assessment') DEFAULT 'personal',
                location VARCHAR(255),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_trainer_date (trainer_id, start_time),
                INDEX idx_member_date (member_id, start_time)
            )
        ");

        // Trainer members table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS trainer_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NOT NULL,
                assigned_date DATE NOT NULL,
                status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
                specialization_focus VARCHAR(255),
                goals TEXT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_trainer_member (trainer_id, member_id)
            )
        ");

        // Workout plans table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS workout_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                difficulty ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner',
                duration_weeks INT DEFAULT 4,
                sessions_per_week INT DEFAULT 3,
                specialization VARCHAR(255),
                status ENUM('draft', 'active', 'completed', 'archived') DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Nutrition plans table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS nutrition_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NOT NULL,
                plan_name VARCHAR(255) NOT NULL,
                goal ENUM('weight_loss', 'muscle_gain', 'maintenance', 'performance') NOT NULL,
                daily_calories INT,
                daily_protein DECIMAL(5,2),
                daily_carbs DECIMAL(5,2),
                daily_fats DECIMAL(5,2),
                daily_water_liters DECIMAL(3,1) DEFAULT 2.5,
                meal_timing JSON,
                restrictions TEXT,
                notes TEXT,
                status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Meal plans table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS meal_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nutrition_plan_id INT NOT NULL,
                meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
                day_of_week TINYINT NOT NULL,
                meal_name VARCHAR(255) NOT NULL,
                ingredients TEXT,
                instructions TEXT,
                calories INT,
                protein DECIMAL(5,2),
                carbs DECIMAL(5,2),
                fats DECIMAL(5,2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (nutrition_plan_id) REFERENCES nutrition_plans(id) ON DELETE CASCADE
            )
        ");

        // Trainer activity log
        $conn->exec("
            CREATE TABLE IF NOT EXISTS trainer_activity (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT,
                activity_type ENUM('session', 'workout', 'nutrition', 'progress', 'member', 'assessment') NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_trainer_activity (trainer_id, created_at)
            )
        ");

        // Member progress tracking
        $conn->exec("
            CREATE TABLE IF NOT EXISTS member_progress (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NOT NULL,
                measurement_date DATE NOT NULL,
                weight DECIMAL(5,2),
                body_fat_percentage DECIMAL(4,2),
                muscle_mass DECIMAL(5,2),
                measurements JSON,
                photos JSON,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_member_progress (member_id, measurement_date)
            )
        ");

    } catch (PDOException $e) {
        // Log error but continue
        error_log("Error creating trainer tables: " . $e->getMessage());
    }
}

function getTrainerStats($conn, $userId) {
    $stats = [
        'total_members' => 0,
        'active_sessions' => 0,
        'completed_workouts' => 0,
        'upcoming_sessions' => 0,
        'nutrition_plans' => 0,
        'this_week_sessions' => 0,
        'member_progress_updates' => 0,
        'avg_session_rating' => 0
    ];

    try {
        // Total active members
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM trainer_members 
            WHERE trainer_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        $stats['total_members'] = $stmt->fetch()['count'];

        // Today's sessions
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND DATE(start_time) = CURDATE() 
            AND status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute([$userId]);
        $stats['active_sessions'] = $stmt->fetch()['count'];

        // Upcoming sessions
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND start_time > NOW() 
            AND status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute([$userId]);
        $stats['upcoming_sessions'] = $stmt->fetch()['count'];

        // This week's sessions
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND WEEK(start_time) = WEEK(NOW())
            AND YEAR(start_time) = YEAR(NOW())
            AND status IN ('scheduled', 'confirmed', 'completed')
        ");
        $stmt->execute([$userId]);
        $stats['this_week_sessions'] = $stmt->fetch()['count'];

        // Active nutrition plans
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM nutrition_plans 
            WHERE trainer_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        $stats['nutrition_plans'] = $stmt->fetch()['count'];

        // Recent progress updates
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM member_progress 
            WHERE trainer_id = ? 
            AND measurement_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$userId]);
        $stats['member_progress_updates'] = $stmt->fetch()['count'];

    } catch (PDOException $e) {
        error_log("Error getting trainer stats: " . $e->getMessage());
    }

    return $stats;
}

function getUpcomingSessions($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT ts.*, 
                   u.name as member_name, 
                   u.profile_image,
                   u.phone as member_phone,
                   tm.specialization_focus
            FROM trainer_schedule ts
            LEFT JOIN users u ON ts.member_id = u.id
            LEFT JOIN trainer_members tm ON (tm.trainer_id = ts.trainer_id AND tm.member_id = ts.member_id)
            WHERE ts.trainer_id = ? 
            AND ts.start_time >= NOW()
            AND ts.status IN ('scheduled', 'confirmed')
            ORDER BY ts.start_time ASC
            LIMIT 8
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting upcoming sessions: " . $e->getMessage());
        return [];
    }
}

function getRecentMembers($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT u.*, 
                   tm.assigned_date,
                   tm.specialization_focus,
                   tm.goals,
                   tm.status as member_status,
                   (SELECT COUNT(*) FROM trainer_schedule WHERE member_id = u.id AND trainer_id = ? AND status = 'completed') as completed_sessions,
                   (SELECT measurement_date FROM member_progress WHERE member_id = u.id ORDER BY measurement_date DESC LIMIT 1) as last_progress_update
            FROM trainer_members tm
            JOIN users u ON tm.member_id = u.id
            WHERE tm.trainer_id = ? AND tm.status = 'active'
            ORDER BY tm.assigned_date DESC
            LIMIT 6
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting recent members: " . $e->getMessage());
        return [];
    }
}

function getWorkoutActivities($conn, $userId, $specialization) {
    try {
        $stmt = $conn->prepare("
            SELECT wp.*, 
                   u.name as member_name,
                   u.profile_image,
                   (SELECT COUNT(*) FROM workout_exercises WHERE workout_plan_id = wp.id) as exercise_count,
                   (SELECT COUNT(*) FROM workout_completion WHERE workout_plan_id = wp.id) as completion_count
            FROM workout_plans wp
            LEFT JOIN users u ON wp.member_id = u.id
            WHERE wp.trainer_id = ? 
            AND (wp.specialization = ? OR wp.specialization IS NULL)
            AND wp.status = 'active'
            ORDER BY wp.updated_at DESC
            LIMIT 8
        ");
        $stmt->execute([$userId, $specialization]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting workout activities: " . $e->getMessage());
        return [];
    }
}

function getRecentActivities($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT ta.*, 
                   u.name as member_name,
                   u.profile_image
            FROM trainer_activity ta
            LEFT JOIN users u ON ta.member_id = u.id
            WHERE ta.trainer_id = ?
            ORDER BY ta.created_at DESC
            LIMIT 15
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting recent activities: " . $e->getMessage());
        return [];
    }
}

function getCalendarEvents($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT ts.id,
                   ts.title,
                   ts.start_time,
                   ts.end_time,
                   ts.status,
                   ts.session_type,
                   u.name as member_name,
                   u.profile_image
            FROM trainer_schedule ts
            LEFT JOIN users u ON ts.member_id = u.id
            WHERE ts.trainer_id = ?
            AND ts.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND ts.start_time <= DATE_ADD(NOW(), INTERVAL 30 DAY)
            ORDER BY ts.start_time ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting calendar events: " . $e->getMessage());
        return [];
    }
}

function getNutritionOverview($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT np.*,
                   u.name as member_name,
                   u.profile_image,
                   (SELECT COUNT(*) FROM meal_plans WHERE nutrition_plan_id = np.id) as meal_count
            FROM nutrition_plans np
            JOIN users u ON np.member_id = u.id
            WHERE np.trainer_id = ? AND np.status = 'active'
            ORDER BY np.updated_at DESC
            LIMIT 6
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting nutrition overview: " . $e->getMessage());
        return [];
    }
}

function getMemberProgressData($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT mp.measurement_date,
                   AVG(mp.weight) as avg_weight,
                   AVG(mp.body_fat_percentage) as avg_body_fat,
                   AVG(mp.muscle_mass) as avg_muscle_mass,
                   COUNT(DISTINCT mp.member_id) as member_count
            FROM member_progress mp
            WHERE mp.trainer_id = ?
            AND mp.measurement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY mp.measurement_date
            ORDER BY mp.measurement_date ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting progress data: " . $e->getMessage());
        return [];
    }
}

// Format helper functions
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function formatTime($time) {
    return date('g:i A', strtotime($time));
}

function formatDateTime($datetime) {
    return date('M j, Y g:i A', strtotime($datetime));
}

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
        return formatDate($datetime);
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Dashboard - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css">
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

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--card-bg);
            border-right: 1px solid var(--border);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-header i {
            color: var(--primary);
            font-size: 2rem;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-user {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-status {
            font-size: 0.875rem;
            color: var(--primary);
            background: rgba(255, 107, 53, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-weight: 500;
        }

        .sidebar-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 1.5rem;
            color: var(--text);
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 0 2rem 2rem 0;
            margin-right: 1rem;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            transform: translateX(0.5rem);
        }

        .sidebar-menu i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 1.25rem;
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

        .header p {
            color: var(--text);
            opacity: 0.7;
            font-size: 1.1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            cursor: pointer;
            padding: 0.75rem;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            background: rgba(255, 107, 53, 0.2);
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

        .stat-label {
            font-size: 0.875rem;
            color: var(--text);
            opacity: 0.6;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 2rem;
        }

        .card {
            background: var(--card-bg);
            border-radius: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 107, 53, 0.02);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header i {
            color: var(--primary);
        }

        .card-content {
            padding: 1.5rem;
        }

        .card-6 { grid-column: span 6; }
        .card-4 { grid-column: span 4; }
        .card-8 { grid-column: span 8; }
        .card-12 { grid-column: span 12; }

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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
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

        .sessions-list,
        .members-list,
        .activities-list {
            display: grid;
            gap: 1rem;
        }

        .session-item,
        .member-item,
        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.75rem;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }

        .session-item:hover,
        .member-item:hover,
        .activity-item:hover {
            background: rgba(255, 107, 53, 0.1);
            transform: translateX(4px);
        }

        .session-time {
            text-align: center;
            min-width: 80px;
        }

        .date {
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.8;
        }

        .time {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary);
        }

        .session-details,
        .member-details,
        .activity-details {
            flex: 1;
        }

        .session-details h4,
        .member-details h4,
        .activity-details h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text);
            opacity: 0.8;
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

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-badge.scheduled {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .status-badge.confirmed {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .status-badge.active {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        .calendar-container {
            height: 400px;
        }

        .nutrition-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .nutrition-card {
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.75rem;
            padding: 1rem;
            border-left: 4px solid var(--primary);
        }

        .nutrition-card h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .nutrition-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.7;
            margin-top: 0.5rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            padding: 1.5rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 1rem;
            text-decoration: none;
            color: var(--text);
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .quick-action:hover {
            background: rgba(255, 107, 53, 0.1);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .quick-action-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .card-6,
            .card-4,
            .card-8,
            .card-12 {
                grid-column: span 1;
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
                <i class="fas fa-dumbbell"></i>
                <h2>EliteFit Trainer</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php if (!empty($profileImage)): ?>
                        <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h3><?php echo htmlspecialchars($userName); ?></h3>
                    <span class="user-status">Trainer</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="members.php"><i class="fas fa-users"></i> <span>My Members</span></a></li>
                <li><a href="schedule.php"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
                <li><a href="workout-plans.php"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
                <li><a href="nutrition.php"><i class="fas fa-apple-alt"></i> <span>Nutrition Plans</span></a></li>
                <li><a href="progress-trackings.php"><i class="fas fa-chart-line"></i> <span>Progress Tracking</span></a></li>
                <li><a href="assessments.php"><i class="fas fa-clipboard-check"></i> <span>Assessments</span></a></li>
                <li><a href="my-profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Welcome back, <?php echo htmlspecialchars($userName); ?>!</h1>
                    <p>Specialization: <?php echo htmlspecialchars($specialization); ?> • Manage your training sessions and member progress</p>
                </div>
                <div class="header-actions">
                    <div class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon"></i>
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
                        <h3>Active Members</h3>
                        <div class="stat-value"><?php echo $stats['total_members']; ?></div>
                        <div class="stat-label">Under your training</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Today's Sessions</h3>
                        <div class="stat-value"><?php echo $stats['active_sessions']; ?></div>
                        <div class="stat-label">Scheduled for today</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <h3>This Week</h3>
                        <div class="stat-value"><?php echo $stats['this_week_sessions']; ?></div>
                        <div class="stat-label">Total sessions</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-apple-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Nutrition Plans</h3>
                        <div class="stat-value"><?php echo $stats['nutrition_plans']; ?></div>
                        <div class="stat-label">Active plans</div>
                    </div>
                </div>
            </div>

            <!-- Calendar -->
                <div class="card card-4">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar"></i> Training Calendar</h2>
                        <a href="schedule.php" class="btn btn-sm btn-outline">Full Calendar</a>
                    </div>
                    <div class="card-content">
                        <div class="calendar-container" id="miniCalendar"></div>
                    </div>
                </div>
                
            
            <!-- Main Dashboard Content -->
            <div class="dashboard-grid">
                <!-- Upcoming Sessions -->
                <div class="card card-6">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-check"></i> Upcoming Sessions</h2>
                        <a href="schedule.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($upcomingSessions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar"></i>
                                <p>No upcoming sessions</p>
                                <a href="schedule.php" class="btn">Schedule Session</a>
                            </div>
                        <?php else: ?>
                            <div class="sessions-list">
                                <?php foreach (array_slice($upcomingSessions, 0, 4) as $session): ?>
                                    <div class="session-item">
                                        <div class="session-time">
                                            <div class="date"><?php echo formatDate($session['start_time']); ?></div>
                                            <div class="time"><?php echo formatTime($session['start_time']); ?></div>
                                        </div>
                                        
                                        <div class="session-details">
                                            <h4><?php echo htmlspecialchars($session['title']); ?></h4>
                                            <?php if (!empty($session['member_name'])): ?>
                                                <div class="member-info">
                                                    <?php if (!empty($session['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($session['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($session['member_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($session['member_name']); ?></span>
                                                    <?php if (!empty($session['specialization_focus'])): ?>
                                                        <span>• <?php echo htmlspecialchars($session['specialization_focus']); ?></span>
                                                    <?php endif; ?>
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
                <div class="card card-6">
                    <div class="card-header">
                        <h2><i class="fas fa-user-plus"></i> Recent Members</h2>
                        <a href="members.php" class="btn btn-sm btn-outline">Manage All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recentMembers)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No members assigned yet</p>
                                <a href="members.php" class="btn">Add Members</a>
                            </div>
                        <?php else: ?>
                            <div class="members-list">
                                <?php foreach (array_slice($recentMembers, 0, 4) as $member): ?>
                                    <div class="member-item">
                                        <div class="user-avatar">
                                            <?php if (!empty($member['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($member['profile_image']); ?>" alt="Profile">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="member-details">
                                            <h4><?php echo htmlspecialchars($member['name']); ?></h4>
                                            <div class="member-info">
                                                <span><?php echo htmlspecialchars($member['email']); ?></span>
                                            </div>
                                            <div class="member-info">
                                                <span><?php echo $member['completed_sessions']; ?> sessions completed</span>
                                                <?php if (!empty($member['specialization_focus'])): ?>
                                                    <span>• <?php echo htmlspecialchars($member['specialization_focus']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="member-actions">
                                            <span class="status-badge <?php echo $member['member_status']; ?>">
                                                <?php echo ucfirst($member['member_status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Workout Activities -->
                <div class="card card-8">
                    <div class="card-header">
                        <h2><i class="fas fa-dumbbell"></i> Active Workout Plans - <?php echo htmlspecialchars($specialization); ?></h2>
                        <a href="workout-plans.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($workoutActivities)): ?>
                            <div class="empty-state">
                                <i class="fas fa-dumbbell"></i>
                                <p>No active workout plans</p>
                                <a href="workout-plans.php" class="btn">Create Workout Plan</a>
                            </div>
                        <?php else: ?>
                            <div class="activities-list">
                                <?php foreach (array_slice($workoutActivities, 0, 5) as $workout): ?>
                                    <div class="activity-item">
                                        <div class="activity-details">
                                            <h4><?php echo htmlspecialchars($workout['title']); ?></h4>
                                            <div class="member-info">
                                                <?php if (!empty($workout['member_name'])): ?>
                                                    <?php if (!empty($workout['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($workout['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($workout['member_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span>Assigned to <?php echo htmlspecialchars($workout['member_name']); ?></span>
                                                <?php else: ?>
                                                    <span>Template workout plan</span>
                                                <?php endif; ?>
                                                <span>• <?php echo $workout['exercise_count']; ?> exercises</span>
                                                <span>• <?php echo ucfirst($workout['difficulty']); ?> level</span>
                                                <span>• <?php echo $workout['completion_count']; ?> completions</span>
                                            </div>
                                        </div>
                                        
                                        <div class="activity-actions">
                                            <a href="workout-plans.php?id=<?php echo $workout['id']; ?>" class="btn btn-sm">View</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Calendar
                <div class="card card-4">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar"></i> Training Calendar</h2>
                        <a href="schedule.php" class="btn btn-sm btn-outline">Full Calendar</a>
                    </div>
                    <div class="card-content">
                        <div class="calendar-container" id="miniCalendar"></div>
                    </div>
                </div>
                 -->
                <!-- Nutrition Plans Overview -->
                <div class="card card-6">
                    <div class="card-header">
                        <h2><i class="fas fa-apple-alt"></i> Nutrition Management</h2>
                        <a href="nutrition.php" class="btn btn-sm btn-outline">Manage All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($nutritionOverview)): ?>
                            <div class="empty-state">
                                <i class="fas fa-apple-alt"></i>
                                <p>No nutrition plans created yet</p>
                                <a href="nutrition.php" class="btn">Create Nutrition Plan</a>
                            </div>
                        <?php else: ?>
                            <div class="nutrition-grid">
                                <?php foreach (array_slice($nutritionOverview, 0, 4) as $plan): ?>
                                    <div class="nutrition-card">
                                        <h4><?php echo htmlspecialchars($plan['plan_name']); ?></h4>
                                        <div class="member-info">
                                            <?php if (!empty($plan['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($plan['profile_image']); ?>" alt="Profile" class="member-avatar">
                                            <?php else: ?>
                                                <div class="member-avatar-placeholder">
                                                    <?php echo strtoupper(substr($plan['member_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($plan['member_name']); ?></span>
                                        </div>
                                        <div class="nutrition-meta">
                                            <span><?php echo ucfirst(str_replace('_', ' ', $plan['goal'])); ?></span>
                                            <span><?php echo $plan['daily_calories']; ?> cal/day</span>
                                            <span><?php echo $plan['meal_count']; ?> meals</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="quick-actions">
                            <a href="nutrition.php?action=create" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <span>Create Plan</span>
                            </a>
                            
                            <a href="nutrition.php?action=templates" class="quick-action">
                                <div class="quick-action-icon">
                                    <i class="fas fa-clipboard-list"></i>
                                </div>
                                <span>Meal Templates</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Member Progress Chart -->
                <div class="card card-6">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-line"></i> Member Progress Overview</h2>
                        <a href="progress-tracking.php" class="btn btn-sm btn-outline">View Details</a>
                    </div>
                    <div class="card-content">
                        <div class="chart-container">
                            <canvas id="progressChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity Timeline -->
                <div class="card card-12">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Activity</h2>
                        <a href="activity.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recentActivities)): ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No recent activity</p>
                            </div>
                        <?php else: ?>
                            <div class="activities-list">
                                <?php foreach (array_slice($recentActivities, 0, 8) as $activity): ?>
                                    <div class="activity-item">
                                        <div class="stat-icon">
                                            <?php 
                                                $iconClass = 'fas fa-info-circle';
                                                switch ($activity['activity_type']) {
                                                    case 'session':
                                                        $iconClass = 'fas fa-calendar-check';
                                                        break;
                                                    case 'workout':
                                                        $iconClass = 'fas fa-dumbbell';
                                                        break;
                                                    case 'nutrition':
                                                        $iconClass = 'fas fa-apple-alt';
                                                        break;
                                                    case 'progress':
                                                        $iconClass = 'fas fa-chart-line';
                                                        break;
                                                    case 'member':
                                                        $iconClass = 'fas fa-user';
                                                        break;
                                                    case 'assessment':
                                                        $iconClass = 'fas fa-clipboard-check';
                                                        break;
                                                }
                                            ?>
                                            <i class="<?php echo $iconClass; ?>"></i>
                                        </div>
                                        
                                        <div class="activity-details">
                                            <h4><?php echo htmlspecialchars($activity['title']); ?></h4>
                                            <p><?php echo htmlspecialchars($activity['description']); ?></p>
                                            <?php if (!empty($activity['member_name'])): ?>
                                                <div class="member-info">
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
                                        
                                        <div class="activity-time">
                                            <span class="time"><?php echo timeAgo($activity['created_at']); ?></span>
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
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Theme toggle
        document.getElementById('themeToggle').addEventListener('click', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            
            // Save theme preference
            fetch('save-theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'theme=' + newTheme
            });
            
            // Update icon
            this.innerHTML = newTheme === 'dark' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        });
        
        // Initialize theme icon
        document.getElementById('themeToggle').innerHTML = 
            document.documentElement.getAttribute('data-theme') === 'dark' 
                ? '<i class="fas fa-moon"></i>' 
                : '<i class="fas fa-sun"></i>';
        
        // Initialize progress chart
        const progressCtx = document.getElementById('progressChart');
        if (progressCtx) {
            const progressData = <?php echo json_encode($progressData); ?>;
            
            new Chart(progressCtx, {
                type: 'line',
                data: {
                    labels: progressData.map(d => d.measurement_date),
                    datasets: [
                        {
                            label: 'Avg Weight (kg)',
                            data: progressData.map(d => d.avg_weight),
                            backgroundColor: 'rgba(255, 107, 53, 0.1)',
                            borderColor: '#ff6b35',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Avg Body Fat (%)',
                            data: progressData.map(d => d.avg_body_fat),
                            backgroundColor: 'rgba(231, 76, 60, 0.1)',
                            borderColor: '#e74c3c',
                            borderWidth: 3,
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
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false
                        }
                    }
                }
            });
        }
        
        // Initialize mini calendar
        const calendarEl = document.getElementById('miniCalendar');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                height: 300,
                headerToolbar: {
                    left: 'prev,next',
                    center: 'title',
                    right: ''
                },
                events: <?php echo json_encode(array_map(function($event) {
                    return [
                        'title' => $event['title'],
                        'start' => $event['start_time'],
                        'end' => $event['end_time'],
                        'backgroundColor' => $event['status'] === 'confirmed' ? '#27ae60' : '#ff6b35',
                        'borderColor' => $event['status'] === 'confirmed' ? '#27ae60' : '#ff6b35'
                    ];
                }, $calendarEvents)); ?>,
                eventClick: function(info) {
                    window.location.href = 'schedule.php?id=' + info.event.id;
                }
            });
            calendar.render();
        }
    </script>
</body>
</html>
