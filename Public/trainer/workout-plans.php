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

// Set default theme to dark
$theme = 'dark';

// Enhanced theme preference system
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE trainer_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                theme_preference VARCHAR(20) DEFAULT 'dark',
                notification_email TINYINT(1) DEFAULT 1,
                notification_sms TINYINT(1) DEFAULT 0,
                auto_confirm_appointments TINYINT(1) DEFAULT 0,
                default_session_duration INT DEFAULT 60,
                working_hours_start TIME DEFAULT '06:00:00',
                working_hours_end TIME DEFAULT '22:00:00',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id)
            )
        ");
        
        $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id, theme_preference) VALUES (?, 'dark')");
        $stmt->execute([$userId]);
    }

    $stmt = $conn->prepare("SELECT theme_preference FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $themeResult = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($themeResult && isset($themeResult['theme_preference'])) {
        $theme = $themeResult['theme_preference'];
    }
} catch (PDOException $e) {
    // Use default theme on error
}

// Enhanced member management - Fetch actual gym members
$members = [];
try {
    // Fetch all active members from the users table with role 'Member'
    $memberQuery = "
        SELECT u.id, u.name, u.email, u.profile_image, u.phone, u.date_of_birth, u.created_at,
               COUNT(DISTINCT wp.id) as workout_plans_count,
               COUNT(DISTINCT ts.id) as total_sessions,
               MAX(wp.created_at) as last_plan_date,
               MAX(ts.created_at) as last_session_date
        FROM users u
        LEFT JOIN workout_plans wp ON u.id = wp.member_id AND wp.trainer_id = ?
        LEFT JOIN trainer_schedule ts ON u.id = ts.member_id AND ts.trainer_id = ?
        WHERE u.role = 'Member' AND u.status = 'active'
        GROUP BY u.id, u.name, u.email, u.profile_image, u.phone, u.date_of_birth, u.created_at
        ORDER BY u.name ASC
    ";
    
    $memberStmt = $conn->prepare($memberQuery);
    $memberStmt->execute([$userId, $userId]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
    error_log("Error fetching members: " . $e->getMessage());
}

// Enhanced workout plans with categories and goals
$workoutPlans = [];
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'workout_plans'")->rowCount() > 0;

    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE workout_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NULL,
                title VARCHAR(100) NOT NULL,
                description TEXT,
                category VARCHAR(50) DEFAULT 'general',
                primary_goal VARCHAR(50) DEFAULT 'fitness',
                difficulty VARCHAR(20) DEFAULT 'intermediate',
                duration VARCHAR(20) DEFAULT '4 weeks',
                frequency VARCHAR(20) DEFAULT '3 days/week',
                estimated_calories_per_session INT DEFAULT 300,
                equipment_needed TEXT,
                is_template TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                completion_rate DECIMAL(5,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (trainer_id),
                INDEX (member_id),
                INDEX (category),
                INDEX (difficulty)
            )
        ");
    }

    $exercisesTableExists = $conn->query("SHOW TABLES LIKE 'workout_exercises'")->rowCount() > 0;

    if (!$exercisesTableExists) {
        $conn->exec("
            CREATE TABLE workout_exercises (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workout_plan_id INT NOT NULL,
                exercise_name VARCHAR(100) NOT NULL,
                exercise_category VARCHAR(50) DEFAULT 'strength',
                muscle_groups TEXT,
                sets INT DEFAULT 3,
                reps VARCHAR(20) DEFAULT '10-12',
                weight VARCHAR(20) DEFAULT '',
                rest_time INT DEFAULT 60,
                tempo VARCHAR(20) DEFAULT '',
                notes TEXT,
                day_number INT DEFAULT 1,
                exercise_order INT DEFAULT 0,
                is_superset TINYINT(1) DEFAULT 0,
                superset_group INT DEFAULT 0,
                difficulty_progression TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (workout_plan_id),
                INDEX (exercise_category),
                INDEX (day_number)
            )
        ");
    }

    // Get member filter
    $memberFilter = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
    $categoryFilter = isset($_GET['category']) ? $_GET['category'] : '';
    $difficultyFilter = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';

    $planQuery = "
        SELECT wp.*, u.name as member_name, u.profile_image, u.email as member_email,
               COUNT(DISTINCT we.id) as exercise_count,
               COUNT(DISTINCT we.day_number) as day_count,
               AVG(we.sets * CAST(SUBSTRING_INDEX(we.reps, '-', 1) AS UNSIGNED)) as avg_volume
        FROM workout_plans wp
        LEFT JOIN users u ON wp.member_id = u.id
        LEFT JOIN workout_exercises we ON wp.id = we.workout_plan_id
        WHERE wp.trainer_id = ? AND wp.is_active = 1
    ";
    
    $planParams = [$userId];
    
    if ($memberFilter > 0) {
        $planQuery .= " AND wp.member_id = ?";
        $planParams[] = $memberFilter;
    }
    
    if (!empty($categoryFilter)) {
        $planQuery .= " AND wp.category = ?";
        $planParams[] = $categoryFilter;
    }
    
    if (!empty($difficultyFilter)) {
        $planQuery .= " AND wp.difficulty = ?";
        $planParams[] = $difficultyFilter;
    }
    
    $planQuery .= " GROUP BY wp.id ORDER BY wp.created_at DESC";
    
    $planStmt = $conn->prepare($planQuery);
    $planStmt->execute($planParams);
    $workoutPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Handle error
    error_log("Error fetching workout plans: " . $e->getMessage());
}

// Enhanced form handling with validation
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_plan'])) {
        try {
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? 'general';
            $primaryGoal = $_POST['primary_goal'] ?? 'fitness';
            $memberId = !empty($_POST['member_id']) ? $_POST['member_id'] : null;
            $difficulty = $_POST['difficulty'] ?? 'intermediate';
            $duration = $_POST['duration'] ?? '4 weeks';
            $frequency = $_POST['frequency'] ?? '3 days/week';
            $estimatedCalories = intval($_POST['estimated_calories'] ?? 300);
            $equipmentNeeded = trim($_POST['equipment_needed'] ?? '');
            $isTemplate = isset($_POST['is_template']) ? 1 : 0;
            
            // Enhanced validation
            if (empty($title)) {
                throw new Exception('Plan title is required');
            }
            
            // Verify member exists if provided
            if ($memberId) {
                $memberCheckStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'Member' AND status = 'active'");
                $memberCheckStmt->execute([$memberId]);
                $memberExists = $memberCheckStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$memberExists) {
                    throw new Exception('Selected member is not valid or not active');
                }
            }
            
            $stmt = $conn->prepare("
                INSERT INTO workout_plans 
                (trainer_id, member_id, title, description, category, primary_goal, difficulty, 
                 duration, frequency, estimated_calories_per_session, equipment_needed, is_template) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $memberId, $title, $description, $category, $primaryGoal,
                $difficulty, $duration, $frequency, $estimatedCalories, $equipmentNeeded, $isTemplate
            ]);
            
            $planId = $conn->lastInsertId();
            
            // Enhanced exercise handling
            if (isset($_POST['exercise_name']) && is_array($_POST['exercise_name'])) {
                $exerciseNames = $_POST['exercise_name'];
                $exerciseCategories = $_POST['exercise_category'] ?? [];
                $muscleGroups = $_POST['muscle_groups'] ?? [];
                $sets = $_POST['sets'] ?? [];
                $reps = $_POST['reps'] ?? [];
                $weights = $_POST['weight'] ?? [];
                $restTimes = $_POST['rest_time'] ?? [];
                $tempos = $_POST['tempo'] ?? [];
                $notes = $_POST['exercise_notes'] ?? [];
                $dayNumbers = $_POST['day_number'] ?? [];
                $isSupersets = $_POST['is_superset'] ?? [];
                $supersetGroups = $_POST['superset_group'] ?? [];
                
                $exerciseStmt = $conn->prepare("
                    INSERT INTO workout_exercises 
                    (workout_plan_id, exercise_name, exercise_category, muscle_groups, sets, reps, 
                     weight, rest_time, tempo, notes, day_number, exercise_order, is_superset, superset_group) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($exerciseNames as $index => $name) {
                    if (empty(trim($name))) continue;
                    
                    $exerciseStmt->execute([
                        $planId,
                        trim($name),
                        $exerciseCategories[$index] ?? 'strength',
                        $muscleGroups[$index] ?? '',
                        $sets[$index] ?? 3,
                        $reps[$index] ?? '10-12',
                        $weights[$index] ?? '',
                        $restTimes[$index] ?? 60,
                        $tempos[$index] ?? '',
                        $notes[$index] ?? '',
                        $dayNumbers[$index] ?? 1,
                        $index,
                        isset($isSupersets[$index]) ? 1 : 0,
                        $supersetGroups[$index] ?? 0
                    ]);
                }
            }
            
            $memberName = $memberId ? $memberExists['name'] : 'template';
            $message = "Workout plan created successfully" . ($memberId ? " for " . $memberName : " as template") . "!";
            $messageType = 'success';
            
            header("Location: workout-plans.php?created=1" . ($memberFilter ? "&member_id=$memberFilter" : ""));
            exit;
        } catch (Exception $e) {
            $message = 'Error creating workout plan: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    // Additional form handlers for update, delete, duplicate...
}

// Helper functions
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function getDifficultyClass($difficulty) {
    switch (strtolower($difficulty)) {
        case 'beginner': return 'success';
        case 'intermediate': return 'warning';
        case 'advanced': return 'danger';
        default: return 'secondary';
    }
}

function getCategoryIcon($category) {
    $icons = [
        'strength' => 'fas fa-dumbbell',
        'cardio' => 'fas fa-heartbeat',
        'flexibility' => 'fas fa-leaf',
        'sports' => 'fas fa-futbol',
        'rehabilitation' => 'fas fa-medkit',
        'general' => 'fas fa-star'
    ];
    return $icons[$category] ?? 'fas fa-star';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Workout Plans - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Dark theme (default) */
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --secondary: #2c2c2c;
            --background: #121212;
            --surface: #1e1e1e;
            --surface-variant: #2a2a2a;
            --on-background: #ffffff;
            --on-surface: #e0e0e0;
            --on-surface-variant: #b0b0b0;
            --border: #333333;
            --success: #4caf50;
            --warning: #ff9800;
            --error: #f44336;
            --info: #2196f3;
        }

        [data-theme="light"] {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --secondary: #f5f5f5;
            --background: #ffffff;
            --surface: #f8f9fa;
            --surface-variant: #e9ecef;
            --on-background: #212529;
            --on-surface: #495057;
            --on-surface-variant: #6c757d;
            --border: #dee2e6;
            --success: #28a745;
            --warning: #ffc107;
            --error: #dc3545;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--on-background);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Enhanced Sidebar */
        .sidebar {
            width: 280px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .sidebar-header h2 {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.5rem;
            margin-top: 0.5rem;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section-title {
            padding: 0 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--on-surface-variant);
            margin-bottom: 1rem;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 2rem;
            color: var(--on-surface);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background: var(--surface-variant);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu a.active {
            background: var(--surface-variant);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 600;
        }

        .sidebar-menu i {
            width: 20px;
            margin-right: 1rem;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            background: var(--background);
        }

        /* Enhanced Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--on-background);
            margin-bottom: 0.25rem;
        }

        .header p {
            color: var(--on-surface-variant);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Theme Toggle */
        .theme-toggle {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .theme-toggle:hover {
            background: var(--surface-variant);
        }

        /* Enhanced Cards */
        .card {
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }

        .card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-variant);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-content {
            padding: 2rem;
        }

        /* Enhanced Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--on-surface);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            background: var(--surface-variant);
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #d32f2f;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Enhanced Filters */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--on-surface);
            font-size: 0.875rem;
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--background);
            color: var(--on-background);
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--on-surface-variant);
        }

        /* Enhanced Workout Plans Grid */
        .workout-plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 2rem;
        }

        .workout-plan-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }

        .workout-plan-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            border-color: var(--primary);
        }

        .workout-plan-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            position: relative;
        }

        .workout-plan-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
        }

        .workout-plan-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .plan-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success { background: var(--success); color: white; }
        .badge-warning { background: var(--warning); color: white; }
        .badge-danger { background: var(--error); color: white; }
        .badge-secondary { background: var(--on-surface-variant); color: white; }

        .workout-plan-details {
            padding: 1.5rem;
        }

        .plan-description {
            color: var(--on-surface-variant);
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .plan-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: var(--surface-variant);
            border-radius: 8px;
        }

        .stat i {
            color: var(--primary);
            width: 16px;
        }

        .stat span {
            font-size: 0.875rem;
            font-weight: 500;
        }

        .plan-member {
            margin: 1rem 0;
            padding: 1rem;
            background: var(--surface-variant);
            border-radius: 8px;
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .member-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .member-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .plan-date {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            margin-top: 1rem;
        }

        .workout-plan-actions {
            padding: 1rem 1.5rem;
            background: var(--surface-variant);
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--on-surface-variant);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        /* Enhanced Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--surface);
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        .modal-lg {
            max-width: 900px;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-variant);
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--on-surface);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--on-surface-variant);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .close-modal:hover {
            background: var(--surface);
            color: var(--on-surface);
        }

        .modal-body {
            padding: 2rem;
        }

        /* Enhanced Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--on-surface);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            margin-top: 0.25rem;
        }

        /* Exercise Items */
        .exercise-item {
            padding: 1.5rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 1rem;
            background: var(--surface-variant);
            position: relative;
        }

        .exercise-item:hover {
            border-color: var(--primary);
        }

        .remove-exercise {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            color: var(--error);
            border: 1px solid rgba(244, 67, 54, 0.2);
        }

        .alert .close {
            position: absolute;
            top: 0.5rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        .alert .close:hover {
            opacity: 1;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            padding: 2rem;
            color: var(--on-surface-variant);
        }

        .loading-spinner i {
            font-size: 1.5rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Mobile Responsiveness */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 1.2rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .header {
                padding-top: 4rem;
            }

            .workout-plans-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .plan-stats {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Advanced Features */
        .plan-progress {
            margin: 1rem 0;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--surface-variant);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            transition: width 0.3s ease;
        }

        .category-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            margin-right: 1rem;
        }

        .plan-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .metric {
            text-align: center;
            padding: 1rem;
            background: var(--surface-variant);
            border-radius: 8px;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }

        .metric-label {
            font-size: 0.8rem;
            color: var(--on-surface-variant);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Member count indicator */
        .member-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard-container">
        <!-- Enhanced Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div style="display: flex; align-items: center;">
                    <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                    <h2>EliteFit Gym</h2>
                </div>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Main</div>
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                    <li><a href="my-profile.php"><i class="fas fa-user"></i> <span>My Profile</span></a></li>
                    <li><a href="members.php"><i class="fas fa-users"></i> <span>Members</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Training</div>
                <ul class="sidebar-menu">
                    <li><a href="workout-plans.php" class="active"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
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
            <!-- Enhanced Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-dumbbell" style="color: var(--primary);"></i> Advanced Workout Plans</h1>
                    <p>Create, manage, and track comprehensive workout programs for your clients</p>
                </div>
                <div class="header-actions">
                    <div class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-moon" id="themeIcon"></i>
                        <span id="themeText">Dark</span>
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addPlanModal')">
                        <i class="fas fa-plus"></i> Create New Plan
                    </button>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Enhanced Filters -->
            <div class="card">
                <div class="card-content">
                    <div class="filters">
                        <div class="filter-group">
                            <label for="member-filter">Filter by Member:
                                <span class="member-count"><?php echo count($members); ?> available</span>
                            </label>
                            <select id="member-filter" class="form-control">
                                <option value="0">All Members</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['name']); ?>
                                        <?php if ($member['workout_plans_count'] > 0): ?>
                                            (<?php echo $member['workout_plans_count']; ?> plans)
                                        <?php else: ?>
                                            (No plans yet)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="category-filter">Category:</label>
                            <select id="category-filter" class="form-control">
                                <option value="">All Categories</option>
                                <option value="strength" <?php echo $categoryFilter === 'strength' ? 'selected' : ''; ?>>Strength Training</option>
                                <option value="cardio" <?php echo $categoryFilter === 'cardio' ? 'selected' : ''; ?>>Cardiovascular</option>
                                <option value="flexibility" <?php echo $categoryFilter === 'flexibility' ? 'selected' : ''; ?>>Flexibility</option>
                                <option value="sports" <?php echo $categoryFilter === 'sports' ? 'selected' : ''; ?>>Sports Specific</option>
                                <option value="rehabilitation" <?php echo $categoryFilter === 'rehabilitation' ? 'selected' : ''; ?>>Rehabilitation</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="difficulty-filter">Difficulty:</label>
                            <select id="difficulty-filter" class="form-control">
                                <option value="">All Levels</option>
                                <option value="beginner" <?php echo $difficultyFilter === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo $difficultyFilter === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo $difficultyFilter === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="plan-search">Search Plans:</label>
                            <div class="search-box">
                                <input type="text" id="plan-search" class="form-control" placeholder="Search by title, description, or exercises...">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Enhanced Workout Plans -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-dumbbell"></i> Your Workout Plans (<?php echo count($workoutPlans); ?>)</h2>
                    <div class="card-actions">
                        <button class="btn btn-sm btn-outline" onclick="exportPlans()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="openModal('addPlanModal')">
                            <i class="fas fa-plus"></i> New Plan
                        </button>
                    </div>
                </div>
                <div class="card-content">
                    <?php if (empty($workoutPlans)): ?>
                        <div class="empty-state">
                            <i class="fas fa-dumbbell"></i>
                            <p>No workout plans created yet</p>
                            <p style="margin-bottom: 2rem; font-size: 0.9rem;">Start building comprehensive workout programs for your clients</p>
                            <button class="btn btn-primary" onclick="openModal('addPlanModal')">
                                <i class="fas fa-plus"></i> Create Your First Workout Plan
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="workout-plans-grid">
                            <?php foreach ($workoutPlans as $plan): ?>
                                <div class="workout-plan-card" data-plan-id="<?php echo $plan['id']; ?>">
                                    <div class="workout-plan-header">
                                        <div style="display: flex; align-items: center; margin-bottom: 0.75rem;">
                                            <div class="category-icon">
                                                <i class="<?php echo getCategoryIcon($plan['category']); ?>"></i>
                                            </div>
                                            <div>
                                                <h3><?php echo htmlspecialchars($plan['title']); ?></h3>
                                                <div style="font-size: 0.9rem; opacity: 0.9;">
                                                    <?php echo ucfirst($plan['category']); ?> • <?php echo ucfirst($plan['primary_goal']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="plan-badges">
                                            <span class="badge badge-<?php echo getDifficultyClass($plan['difficulty']); ?>">
                                                <?php echo ucfirst($plan['difficulty']); ?>
                                            </span>
                                            <?php if ($plan['is_template']): ?>
                                                <span class="badge badge-secondary">Template</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-plan-details">
                                        <?php if (!empty($plan['description'])): ?>
                                            <div class="plan-description">
                                                <?php echo htmlspecialchars($plan['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="plan-metrics">
                                            <div class="metric">
                                                <span class="metric-value"><?php echo $plan['exercise_count']; ?></span>
                                                <span class="metric-label">Exercises</span>
                                            </div>
                                            <div class="metric">
                                                <span class="metric-value"><?php echo $plan['day_count']; ?></span>
                                                <span class="metric-label">Days</span>
                                            </div>
                                            <div class="metric">
                                                <span class="metric-value"><?php echo $plan['estimated_calories_per_session']; ?></span>
                                                <span class="metric-label">Cal/Session</span>
                                            </div>
                                        </div>
                                        
                                        <div class="plan-stats">
                                            <div class="stat">
                                                <i class="fas fa-calendar-day"></i>
                                                <span><?php echo htmlspecialchars($plan['duration']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-calendar-week"></i>
                                                <span><?php echo htmlspecialchars($plan['frequency']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($plan['member_name'])): ?>
                                            <div class="plan-member">
                                                <div class="member-info">
                                                    <?php if (!empty($plan['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($plan['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($plan['member_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong>Assigned to:</strong> <?php echo htmlspecialchars($plan['member_name']); ?>
                                                        <?php if (!empty($plan['member_email'])): ?>
                                                            <br><small style="color: var(--on-surface-variant);"><?php echo htmlspecialchars($plan['member_email']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="plan-member">
                                                <div class="member-info">
                                                    <div class="member-avatar-placeholder">
                                                        <i class="fas fa-star"></i>
                                                    </div>
                                                    <span><strong>Status:</strong> Template (Not assigned to any member)</span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="plan-progress">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                                <span style="font-size: 0.8rem; color: var(--on-surface-variant);">Completion Rate</span>
                                                <span style="font-size: 0.8rem; font-weight: 600; color: var(--primary);"><?php echo number_format($plan['completion_rate'], 1); ?>%</span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $plan['completion_rate']; ?>%;"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="plan-date">
                                            Created: <?php echo formatDate($plan['created_at']); ?>
                                            <?php if ($plan['updated_at'] !== $plan['created_at']): ?>
                                                • Updated: <?php echo formatDate($plan['updated_at']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-plan-actions">
                                        <button class="btn btn-sm btn-outline" onclick="viewPlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="editPlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="duplicatePlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="sharePlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-share"></i> Share
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="confirmDeletePlan(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['title']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Add Workout Plan Modal -->
    <div class="modal" id="addPlanModal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Create Advanced Workout Plan</h3>
                <button class="close-modal" onclick="closeModal('addPlanModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workout-plans.php" method="post" id="addPlanForm">
                    <input type="hidden" name="add_plan" value="1">
                    
                    <!-- Basic Information -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </h4>
                        
                        <div class="form-group">
                            <label for="title">Plan Title *</label>
                            <input type="text" id="title" name="title" class="form-control" required 
                                   placeholder="e.g., Advanced Strength Building Program">
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3" 
                                      placeholder="Describe the goals, target audience, and key features of this workout plan..."></textarea>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="category">Category *</label>
                                <select id="category" name="category" class="form-control" required>
                                    <option value="strength">Strength Training</option>
                                    <option value="cardio">Cardiovascular</option>
                                    <option value="flexibility">Flexibility & Mobility</option>
                                    <option value="sports">Sports Specific</option>
                                    <option value="rehabilitation">Rehabilitation</option>
                                    <option value="general">General Fitness</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="primary_goal">Primary Goal *</label>
                                <select id="primary_goal" name="primary_goal" class="form-control" required>
                                    <option value="muscle_gain">Muscle Gain</option>
                                    <option value="fat_loss">Fat Loss</option>
                                    <option value="strength">Strength</option>
                                    <option value="endurance">Endurance</option>
                                    <option value="flexibility">Flexibility</option>
                                    <option value="rehabilitation">Rehabilitation</option>
                                    <option value="general_fitness">General Fitness</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Assignment & Settings -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-cog"></i> Assignment & Settings
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="member_id">Assign to Member
                                    <span class="member-count"><?php echo count($members); ?> available</span>
                                </label>
                                <select id="member_id" name="member_id" class="form-control">
                                    <option value="">-- Select Member (Optional) --</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>" 
                                                data-email="<?php echo htmlspecialchars($member['email']); ?>"
                                                data-phone="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($member['name']); ?>
                                            <?php if ($member['workout_plans_count'] > 0): ?>
                                                (<?php echo $member['workout_plans_count']; ?> existing plans)
                                            <?php else: ?>
                                                (New member)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle"></i> 
                                    Leave empty to create a template that can be assigned later
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="difficulty">Difficulty Level *</label>
                                <select id="difficulty" name="difficulty" class="form-control" required>
                                    <option value="beginner">Beginner</option>
                                    <option value="intermediate" selected>Intermediate</option>
                                    <option value="advanced">Advanced</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="duration">Program Duration *</label>
                                <select id="duration" name="duration" class="form-control" required>
                                    <option value="1 week">1 Week</option>
                                    <option value="2 weeks">2 Weeks</option>
                                    <option value="4 weeks" selected>4 Weeks</option>
                                    <option value="6 weeks">6 Weeks</option>
                                    <option value="8 weeks">8 Weeks</option>
                                    <option value="12 weeks">12 Weeks</option>
                                    <option value="16 weeks">16 Weeks</option>
                                    <option value="ongoing">Ongoing</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="frequency">Training Frequency *</label>
                                <select id="frequency" name="frequency" class="form-control" required>
                                    <option value="1 day/week">1 Day/Week</option>
                                    <option value="2 days/week">2 Days/Week</option>
                                    <option value="3 days/week" selected>3 Days/Week</option>
                                    <option value="4 days/week">4 Days/Week</option>
                                    <option value="5 days/week">5 Days/Week</option>
                                    <option value="6 days/week">6 Days/Week</option>
                                    <option value="daily">Daily</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="estimated_calories">Est. Calories per Session</label>
                                <input type="number" id="estimated_calories" name="estimated_calories" 
                                       class="form-control" min="50" max="1000" value="300" step="25">
                            </div>
                            
                            <div class="form-group">
                                <label for="equipment_needed">Equipment Needed</label>
                                <input type="text" id="equipment_needed" name="equipment_needed" 
                                       class="form-control" placeholder="e.g., Dumbbells, Barbell, Resistance Bands">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="is_template" name="is_template">
                                <label for="is_template">Save as Template</label>
                            </div>
                            <div class="form-text">Templates can be reused and assigned to multiple clients.</div>
                        </div>
                    </div>
                    
                    <!-- Exercises -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-list"></i> Exercises
                        </h4>
                        
                        <div id="exercisesContainer">
                            <!-- Exercise items will be added here -->
                        </div>
                        
                        <button type="button" class="btn btn-outline" onclick="addExerciseItem()">
                            <i class="fas fa-plus"></i> Add Exercise
                        </button>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addPlanModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Workout Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Theme management
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            
            // Update theme toggle button
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            
            if (newTheme === 'dark') {
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark';
            } else {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light';
            }
            
            // Save theme preference
            fetch('update_theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ theme: newTheme })
            });
        }

        // Initialize theme
        document.addEventListener('DOMContentLoaded', function() {
            const theme = '<?php echo $theme; ?>';
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            
            if (theme === 'dark') {
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark';
            } else {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light';
            }
        });

        // Modal management
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        });

        // Filter functionality
        document.getElementById('member-filter').addEventListener('change', function() {
            updateFilters();
        });

        document.getElementById('category-filter').addEventListener('change', function() {
            updateFilters();
        });

        document.getElementById('difficulty-filter').addEventListener('change', function() {
            updateFilters();
        });

        function updateFilters() {
            const memberId = document.getElementById('member-filter').value;
            const category = document.getElementById('category-filter').value;
            const difficulty = document.getElementById('difficulty-filter').value;
            
            let url = 'workout-plans.php?';
            const params = [];
            
            if (memberId > 0) params.push(`member_id=${memberId}`);
            if (category) params.push(`category=${category}`);
            if (difficulty) params.push(`difficulty=${difficulty}`);
            
            url += params.join('&');
            window.location.href = url;
        }

        // Search functionality
        document.getElementById('plan-search').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const planCards = document.querySelectorAll('.workout-plan-card');
            
            planCards.forEach(card => {
                const title = card.querySelector('h3').textContent.toLowerCase();
                const description = card.querySelector('.plan-description')?.textContent.toLowerCase() || '';
                const category = card.querySelector('.workout-plan-header').textContent.toLowerCase();
                
                if (title.includes(searchValue) || description.includes(searchValue) || category.includes(searchValue)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Exercise management
        let exerciseCount = 0;

        function addExerciseItem() {
            exerciseCount++;
            const container = document.getElementById('exercisesContainer');
            
            const exerciseItem = document.createElement('div');
            exerciseItem.className = 'exercise-item';
            exerciseItem.innerHTML = `
                <button type="button" class="remove-exercise btn btn-sm btn-danger" onclick="removeExercise(this)">
                    <i class="fas fa-trash"></i> Remove
                </button>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Day Number *</label>
                        <select name="day_number[]" class="form-control" required>
                            <option value="1">Day 1</option>
                            <option value="2">Day 2</option>
                            <option value="3">Day 3</option>
                            <option value="4">Day 4</option>
                            <option value="5">Day 5</option>
                            <option value="6">Day 6</option>
                            <option value="7">Day 7</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Exercise Category</label>
                        <select name="exercise_category[]" class="form-control">
                            <option value="strength">Strength</option>
                            <option value="cardio">Cardio</option>
                            <option value="flexibility">Flexibility</option>
                            <option value="plyometric">Plyometric</option>
                            <option value="core">Core</option>
                            <option value="balance">Balance</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Exercise Name *</label>
                    <input type="text" name="exercise_name[]" class="form-control" required 
                           placeholder="e.g., Barbell Back Squat">
                </div>
                
                <div class="form-group">
                    <label>Target Muscle Groups</label>
                    <input type="text" name="muscle_groups[]" class="form-control" 
                           placeholder="e.g., Quadriceps, Glutes, Hamstrings">
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Sets *</label>
                        <input type="number" name="sets[]" class="form-control" min="1" max="10" value="3" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Reps *</label>
                        <input type="text" name="reps[]" class="form-control" value="10-12" required 
                               placeholder="e.g., 8-10, 15, AMRAP">
                    </div>
                    
                    <div class="form-group">
                        <label>Weight/Resistance</label>
                        <input type="text" name="weight[]" class="form-control" 
                               placeholder="e.g., 135lbs, Bodyweight, Heavy">
                    </div>
                    
                    <div class="form-group">
                        <label>Rest Time (seconds)</label>
                        <input type="number" name="rest_time[]" class="form-control" min="0" max="600" value="60">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tempo</label>
                        <input type="text" name="tempo[]" class="form-control" 
                               placeholder="e.g., 3-1-2-0, Explosive, Controlled">
                    </div>
                    
                    <div class="form-group">
                        <label>Superset Group</label>
                        <input type="number" name="superset_group[]" class="form-control" min="0" max="10" value="0">
                        <div class="form-text">0 = No superset, 1+ = Superset group number</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_superset[]" value="1">
                        <label>Part of Superset</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Exercise Notes</label>
                    <textarea name="exercise_notes[]" class="form-control" rows="2" 
                              placeholder="Form cues, modifications, progressions, etc."></textarea>
                </div>
            `;
            
            container.appendChild(exerciseItem);
        }

        function removeExercise(button) {
            const exerciseItem = button.closest('.exercise-item');
            exerciseItem.remove();
        }

        // Plan management functions
        function viewPlan(planId) {
            // Implementation for viewing plan details
            window.open(`view_workout_plan.php?id=${planId}`, '_blank');
        }

        function editPlan(planId) {
            // Implementation for editing plan
            window.location.href = `edit_workout_plan.php?id=${planId}`;
        }

        function duplicatePlan(planId) {
            if (confirm('Are you sure you want to duplicate this workout plan?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'workout-plans.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'duplicate_plan';
                input.value = '1';
                form.appendChild(input);
                
                const planIdInput = document.createElement('input');
                planIdInput.type = 'hidden';
                planIdInput.name = 'plan_id';
                planIdInput.value = planId;
                form.appendChild(planIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function sharePlan(planId) {
            // Implementation for sharing plan
            const shareUrl = `${window.location.origin}/share_workout_plan.php?id=${planId}`;
            
            if (navigator.share) {
                navigator.share({
                    title: 'Workout Plan',
                    text: 'Check out this workout plan!',
                    url: shareUrl
                });
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(shareUrl).then(() => {
                    alert('Share link copied to clipboard!');
                });
            }
        }

        function confirmDeletePlan(planId, planTitle) {
            if (confirm(`Are you sure you want to delete the workout plan "${planTitle}"?\n\nThis action cannot be undone and will remove all exercises associated with this plan.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'workout-plans.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_plan';
                input.value = '1';
                form.appendChild(input);
                
                const planIdInput = document.createElement('input');
                planIdInput.type = 'hidden';
                planIdInput.name = 'plan_id';
                planIdInput.value = planId;
                form.appendChild(planIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function exportPlans() {
            // Implementation for exporting plans
            window.open('export_workout_plans.php', '_blank');
        }

        // Member selection enhancement
        document.getElementById('member_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const email = selectedOption.getAttribute('data-email');
                const phone = selectedOption.getAttribute('data-phone');
                
                // You can add member info display here if needed
                console.log('Selected member:', {
                    id: selectedOption.value,
                    name: selectedOption.text,
                    email: email,
                    phone: phone
                });
            }
        });

        // Initialize the form with one exercise
        document.addEventListener('DOMContentLoaded', function() {
            addExerciseItem();
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });

        // Form validation
        document.getElementById('addPlanForm').addEventListener('submit', function(e) {
            const exerciseNames = document.querySelectorAll('input[name="exercise_name[]"]');
            let hasExercises = false;
            
            exerciseNames.forEach(input => {
                if (input.value.trim()) {
                    hasExercises = true;
                }
            });
            
            if (!hasExercises) {
                e.preventDefault();
                alert('Please add at least one exercise to the workout plan.');
                return false;
            }
        });
    </script>
</body>
</html>
