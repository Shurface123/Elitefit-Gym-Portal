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
$difficultyFilter = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Get all workout plans assigned to this member
$workoutsQuery = "
    SELECT wp.*, u.name as trainer_name, u.profile_image as trainer_image,
           (SELECT COUNT(*) FROM workout_exercises WHERE workout_id = wp.id) as exercise_count
    FROM workout_plans wp
    JOIN users u ON wp.trainer_id = u.id
    WHERE wp.member_id = ?
";

// Apply filters
$queryParams = [$userId];
if (!empty($difficultyFilter) || !empty($searchQuery)) {
    $workoutsQuery .= " AND (";
    
    if (!empty($difficultyFilter)) {
        $workoutsQuery .= "wp.difficulty = ?";
        $queryParams[] = $difficultyFilter;
    }
    
    if (!empty($searchQuery)) {
        if (!empty($difficultyFilter)) {
            $workoutsQuery .= " OR ";
        }
        $workoutsQuery .= "wp.title LIKE ? OR wp.description LIKE ?";
        $queryParams[] = "%$searchQuery%";
        $queryParams[] = "%$searchQuery%";
    }
    
    $workoutsQuery .= ")";
}

$workoutsQuery .= " ORDER BY wp.created_at DESC";

$workoutStmt = $conn->prepare($workoutsQuery);
$workoutStmt->execute($queryParams);
$workouts = $workoutStmt->fetchAll(PDO::FETCH_ASSOC);

// Get workout completion history
$completionStmt = $conn->prepare("
    SELECT wc.*, wp.title as workout_title
    FROM workout_completion wc
    JOIN workout_plans wp ON wc.workout_plan_id = wp.id
    WHERE wc.member_id = ?
    ORDER BY wc.completion_date DESC
    LIMIT 5
");
$completionStmt->execute([$userId]);
$completionHistory = $completionStmt->fetchAll(PDO::FETCH_ASSOC);

// Get workout templates (for requesting new workouts)
$templateStmt = $conn->prepare("
    SELECT wp.*, u.name as trainer_name, u.profile_image as trainer_image,
           (SELECT COUNT(*) FROM workout_exercises WHERE workout_id = wp.id) as exercise_count
    FROM workout_plans wp
    JOIN users u ON wp.trainer_id = u.id
    WHERE wp.is_template = 1
    ORDER BY wp.title ASC
");
$templateStmt->execute();
$templates = $templateStmt->fetchAll(PDO::FETCH_ASSOC);

// Get available trainers for this member
$trainerStmt = $conn->prepare("
    SELECT DISTINCT u.id, u.name, u.profile_image, u.specialization
    FROM users u
    WHERE u.role = 'Trainer' AND u.status = 'active'
    ORDER BY u.name ASC
");
$trainerStmt->execute();
$availableTrainers = $trainerStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_workout'])) {
        // Enhanced workout request with trainer selection and template option
        try {
            $trainerId = $_POST['trainer_id'];
            $templateId = !empty($_POST['template_id']) ? $_POST['template_id'] : null;
            $workoutType = $_POST['workout_type'] ?? 'custom';
            $difficulty = $_POST['difficulty'] ?? 'intermediate';
            $duration = $_POST['duration'] ?? '60';
            $frequency = $_POST['frequency'] ?? '3';
            $goals = $_POST['goals'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $preferredDays = isset($_POST['preferred_days']) ? implode(',', $_POST['preferred_days']) : '';
            
            // Create workout request record
            $requestStmt = $conn->prepare("
                INSERT INTO workout_requests 
                (member_id, trainer_id, template_id, workout_type, difficulty, duration, 
                 frequency, goals, notes, preferred_days, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            
            $requestStmt->execute([
                $userId, $trainerId, $templateId, $workoutType, $difficulty, 
                $duration, $frequency, $goals, $notes, $preferredDays
            ]);
            
            // Create notification for trainer
            $notificationStmt = $conn->prepare("
                INSERT INTO trainer_notifications (trainer_id, message, icon, link, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $templateInfo = '';
            if ($templateId) {
                $templateQuery = $conn->prepare("SELECT title FROM workout_plans WHERE id = ?");
                $templateQuery->execute([$templateId]);
                $template = $templateQuery->fetch(PDO::FETCH_ASSOC);
                if ($template) {
                    $templateInfo = " based on template: " . $template['title'];
                }
            }
            
            $notificationMessage = "New workout plan request from " . $userName . $templateInfo;
            $notificationStmt->execute([
                $trainerId, 
                $notificationMessage,
                "dumbbell", 
                "workout-requests.php?member_id=" . $userId
            ]);
            
            $message = 'Workout request sent successfully! Your trainer will review and create a customized plan for you.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error sending request: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['log_completion'])) {
        // Enhanced workout completion logging
        try {
            $workoutId = $_POST['workout_id'];
            $completionDate = $_POST['completion_date'];
            $durationMinutes = $_POST['duration_minutes'];
            $difficultyRating = $_POST['difficulty_rating'];
            $energyLevel = $_POST['energy_level'] ?? 5;
            $exercisesCompleted = $_POST['exercises_completed'] ?? 0;
            $caloriesBurned = $_POST['calories_burned'] ?? 0;
            $notes = $_POST['notes'] ?? '';
            
            // Insert workout completion log
            $stmt = $conn->prepare("
                INSERT INTO workout_completion 
                (member_id, workout_plan_id, completion_date, duration_minutes, 
                 difficulty_rating, energy_level, exercises_completed, calories_burned, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $userId, $workoutId, $completionDate, $durationMinutes, 
                $difficultyRating, $energyLevel, $exercisesCompleted, $caloriesBurned, $notes
            ]);
            
            $message = 'Workout completion logged successfully! Great job on completing your workout.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error logging workout completion: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Workouts - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --light: #ecf0f1;
            --dark: #2c3e50;
            --bg-light: #f8f9fa;
            --bg-dark: #1a1a1a;
            --card-light: #ffffff;
            --card-dark: #2d2d2d;
            --text-light: #2c3e50;
            --text-dark: #ecf0f1;
            --border-light: #dee2e6;
            --border-dark: #404040;
        }

        [data-theme="light"] {
            --bg: var(--bg-light);
            --card-bg: var(--card-light);
            --text: var(--text-light);
            --border: var(--border-light);
        }

        [data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-dark);
            --text: var(--text-dark);
            --border: var(--border-dark);
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
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text);
            opacity: 0.7;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .card {
            background: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border);
            border-radius: 0.5rem;
            background: var(--card-bg);
            color: var(--text);
            font-size: 0.875rem;
            transition: all 0.3s ease;
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
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text);
            opacity: 0.5;
        }

        .workouts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .workout-card {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .workout-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .workout-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .workout-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .workout-badges {
            display: flex;
            gap: 0.5rem;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-beginner {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .badge-intermediate {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .badge-advanced {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .workout-description {
            color: var(--text);
            opacity: 0.8;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .workout-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text);
            opacity: 0.8;
        }

        .stat i {
            color: var(--primary);
        }

        .trainer-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.5rem;
        }

        .trainer-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .trainer-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .workout-date {
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.6;
            margin-bottom: 1rem;
        }

        .workout-actions {
            display: flex;
            gap: 0.75rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            font-weight: 600;
            color: var(--text);
            background: rgba(255, 107, 53, 0.05);
        }

        .rating {
            display: flex;
            gap: 0.25rem;
        }

        .rating i {
            color: var(--warning);
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 1rem;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: all 0.3s ease;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text);
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .close-modal:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-item input[type="checkbox"] {
            margin: 0;
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.2);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.2);
        }

        .close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.7;
        }

        .close:hover {
            opacity: 1;
        }

        .rating-input {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .rating-input input[type="radio"] {
            display: none;
        }

        .rating-input label {
            cursor: pointer;
            font-size: 1.5rem;
            color: #ddd;
            transition: color 0.3s ease;
        }

        .rating-input input[type="radio"]:checked ~ label,
        .rating-input label:hover,
        .rating-input label.active {
            color: var(--warning);
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

            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .workouts-grid {
                grid-template-columns: 1fr;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .workout-actions {
                flex-direction: column;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell"></i>
                <h2>EliteFit Gym</h2>
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
                    <span class="user-status">Member</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="workouts.php" class="active"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
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
                    <h1>My Workouts</h1>
                    <p>Manage your personalized workout plans and track your progress</p>
                </div>
                <div class="header-actions">
                    <button class="btn" id="requestWorkoutBtn">
                        <i class="fas fa-plus"></i> Request Workout Plan
                    </button>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Workout Filters -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-filter"></i> Filter & Search</h2>
                </div>
                <div class="card-content">
                    <form action="workouts.php" method="get" class="filters-form">
                        <div class="form-group">
                            <label for="difficulty">Difficulty Level:</label>
                            <select id="difficulty" name="difficulty" class="form-control">
                                <option value="">All Levels</option>
                                <option value="beginner" <?php echo $difficultyFilter === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo $difficultyFilter === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo $difficultyFilter === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="search">Search Workouts:</label>
                            <div class="search-box">
                                <input type="text" id="search" name="search" class="form-control" placeholder="Search by name or description..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <?php if (!empty($difficultyFilter) || !empty($searchQuery)): ?>
                                <a href="workouts.php" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Workout Plans -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-dumbbell"></i> Your Workout Plans</h2>
                    <span class="badge badge-primary"><?php echo count($workouts); ?> Plans</span>
                </div>
                <div class="card-content">
                    <?php if (empty($workouts)): ?>
                        <div class="empty-state">
                            <i class="fas fa-dumbbell"></i>
                            <h3>No Workout Plans Yet</h3>
                            <p>You don't have any workout plans assigned. Request a personalized workout plan from our expert trainers!</p>
                            <button class="btn" id="emptyRequestWorkoutBtn">
                                <i class="fas fa-plus"></i> Request Your First Workout Plan
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="workouts-grid">
                            <?php foreach ($workouts as $workout): ?>
                                <div class="workout-card">
                                    <div class="workout-header">
                                        <div>
                                            <h3><?php echo htmlspecialchars($workout['title']); ?></h3>
                                        </div>
                                        <div class="workout-badges">
                                            <span class="badge badge-<?php echo strtolower($workout['difficulty']); ?>">
                                                <?php echo ucfirst($workout['difficulty']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-details">
                                        <?php if (!empty($workout['description'])): ?>
                                            <div class="workout-description">
                                                <?php echo nl2br(htmlspecialchars($workout['description'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="workout-stats">
                                            <div class="stat">
                                                <i class="fas fa-clock"></i>
                                                <span><?php echo htmlspecialchars($workout['duration']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-calendar-week"></i>
                                                <span><?php echo htmlspecialchars($workout['frequency']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-list"></i>
                                                <span><?php echo $workout['exercise_count']; ?> exercises</span>
                                            </div>
                                        </div>
                                        
                                        <div class="trainer-info">
                                            <?php if (!empty($workout['trainer_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($workout['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                            <?php else: ?>
                                                <div class="trainer-avatar-placeholder">
                                                    <?php echo strtoupper(substr($workout['trainer_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><strong>Trainer:</strong> <?php echo htmlspecialchars($workout['trainer_name']); ?></span>
                                        </div>
                                        
                                        <div class="workout-date">
                                            <i class="fas fa-calendar"></i> Created: <?php echo date('M d, Y', strtotime($workout['created_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-actions">
                                        <a href="workout-details.php?id=<?php echo $workout['id']; ?>" class="btn btn-sm">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                        <button class="btn btn-sm btn-outline" onclick="logCompletion(<?php echo $workout['id']; ?>, '<?php echo htmlspecialchars($workout['title']); ?>')">
                                            <i class="fas fa-check"></i> Log Completion
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Completions -->
            <?php if (!empty($completionHistory)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Workout Completions</h2>
                        <a href="progress.php" class="btn btn-sm btn-outline">View All Progress</a>
                    </div>
                    <div class="card-content">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Workout</th>
                                        <th>Duration</th>
                                        <th>Difficulty Rating</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completionHistory as $completion): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($completion['completion_date'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($completion['workout_title']); ?></strong></td>
                                            <td><?php echo $completion['duration_minutes']; ?> min</td>
                                            <td>
                                                <div class="rating">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star<?php echo $i <= $completion['difficulty_rating'] ? '' : '-o'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </td>
                                            <td><?php echo !empty($completion['notes']) ? htmlspecialchars($completion['notes']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Enhanced Request Workout Modal -->
    <div class="modal" id="requestWorkoutModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Request Custom Workout Plan</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workouts.php" method="post">
                    <input type="hidden" name="request_workout" value="1">
                    
                    <div class="form-group">
                        <label for="trainer_id"><i class="fas fa-user-tie"></i> Select Your Trainer:</label>
                        <select id="trainer_id" name="trainer_id" class="form-control" required>
                            <option value="">-- Choose a Trainer --</option>
                            <?php foreach ($availableTrainers as $trainer): ?>
                                <option value="<?php echo $trainer['id']; ?>">
                                    <?php echo htmlspecialchars($trainer['name']); ?>
                                    <?php if (!empty($trainer['specialization'])): ?>
                                        - <?php echo htmlspecialchars($trainer['specialization']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="template_id"><i class="fas fa-clipboard-list"></i> Based on Template (Optional):</label>
                        <select id="template_id" name="template_id" class="form-control">
                            <option value="">-- Create Custom Plan --</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $template['id']; ?>">
                                    <?php echo htmlspecialchars($template['title']); ?> 
                                    (<?php echo ucfirst($template['difficulty']); ?>) 
                                    - <?php echo $template['exercise_count']; ?> exercises
                                    - by <?php echo htmlspecialchars($template['trainer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="workout_type"><i class="fas fa-dumbbell"></i> Workout Type:</label>
                            <select id="workout_type" name="workout_type" class="form-control">
                                <option value="strength">Strength Training</option>
                                <option value="cardio">Cardio</option>
                                <option value="hiit">HIIT</option>
                                <option value="flexibility">Flexibility</option>
                                <option value="mixed">Mixed Training</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="difficulty"><i class="fas fa-signal"></i> Difficulty Level:</label>
                            <select id="difficulty" name="difficulty" class="form-control">
                                <option value="beginner">Beginner</option>
                                <option value="intermediate" selected>Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="duration"><i class="fas fa-clock"></i> Session Duration (minutes):</label>
                            <select id="duration" name="duration" class="form-control">
                                <option value="30">30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60" selected>60 minutes</option>
                                <option value="90">90 minutes</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="frequency"><i class="fas fa-calendar-week"></i> Weekly Frequency:</label>
                            <select id="frequency" name="frequency" class="form-control">
                                <option value="2">2 times per week</option>
                                <option value="3" selected>3 times per week</option>
                                <option value="4">4 times per week</option>
                                <option value="5">5 times per week</option>
                                <option value="6">6 times per week</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-day"></i> Preferred Workout Days:</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="monday" name="preferred_days[]" value="Monday">
                                <label for="monday">Monday</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="tuesday" name="preferred_days[]" value="Tuesday">
                                <label for="tuesday">Tuesday</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="wednesday" name="preferred_days[]" value="Wednesday">
                                <label for="wednesday">Wednesday</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="thursday" name="preferred_days[]" value="Thursday">
                                <label for="thursday">Thursday</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="friday" name="preferred_days[]" value="Friday">
                                <label for="friday">Friday</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="saturday" name="preferred_days[]" value="Saturday">
                                <label for="saturday">Saturday</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="sunday" name="preferred_days[]" value="Sunday">
                                <label for="sunday">Sunday</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="goals"><i class="fas fa-target"></i> Fitness Goals:</label>
                        <textarea id="goals" name="goals" class="form-control" rows="3" placeholder="Describe your fitness goals (e.g., weight loss, muscle gain, endurance, strength...)"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes:</label>
                        <textarea id="notes" name="notes" class="form-control" rows="4" placeholder="Any specific requirements, injuries to consider, equipment preferences, or other details your trainer should know..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn">
                            <i class="fas fa-paper-plane"></i> Send Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Log Completion Modal -->
    <div class="modal" id="logCompletionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Log Workout Completion</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workouts.php" method="post">
                    <input type="hidden" name="log_completion" value="1">
                    <input type="hidden" id="completion_workout_id" name="workout_id" value="">
                    
                    <div class="form-group">
                        <label for="workout_title"><i class="fas fa-dumbbell"></i> Workout:</label>
                        <input type="text" id="workout_title" class="form-control" readonly>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="completion_date"><i class="fas fa-calendar"></i> Completion Date:</label>
                            <input type="date" id="completion_date" name="completion_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="duration_minutes"><i class="fas fa-clock"></i> Duration (minutes):</label>
                            <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="1" max="300" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="exercises_completed"><i class="fas fa-list-check"></i> Exercises Completed:</label>
                            <input type="number" id="exercises_completed" name="exercises_completed" class="form-control" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="calories_burned"><i class="fas fa-fire"></i> Calories Burned (optional):</label>
                            <input type="number" id="calories_burned" name="calories_burned" class="form-control" min="0">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="difficulty_rating"><i class="fas fa-star"></i> Difficulty Rating:</label>
                            <div class="rating-input">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <input type="radio" id="star<?php echo $i; ?>" name="difficulty_rating" value="<?php echo $i; ?>" <?php echo $i === 3 ? 'checked' : ''; ?>>
                                    <label for="star<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="energy_level"><i class="fas fa-battery-three-quarters"></i> Energy Level (1-10):</label>
                            <input type="range" id="energy_level" name="energy_level" class="form-control" min="1" max="10" value="5">
                            <div style="text-align: center; margin-top: 0.5rem;">
                                <span id="energy_display">5</span>/10
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="completion_notes"><i class="fas fa-comment"></i> Workout Notes:</label>
                        <textarea id="completion_notes" name="notes" class="form-control" rows="3" placeholder="How did the workout feel? Any challenges, achievements, or observations?"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i> Save Completion
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Modal functionality
        const modals = document.querySelectorAll('.modal');
        const modalTriggers = [
            { id: 'requestWorkoutBtn', modal: 'requestWorkoutModal' },
            { id: 'emptyRequestWorkoutBtn', modal: 'requestWorkoutModal' }
        ];
        
        // Open modal
        modalTriggers.forEach(trigger => {
            const element = document.getElementById(trigger.id);
            if (element) {
                element.addEventListener('click', function() {
                    document.getElementById(trigger.modal).classList.add('show');
                });
            }
        });
        
        // Close modal
        document.querySelectorAll('.close-modal').forEach(button => {
            button.addEventListener('click', function() {
                this.closest('.modal').classList.remove('show');
            });
        });
        
        // Close modal when clicking outside
        modals.forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });
        
        // Log completion function
        function logCompletion(workoutId, workoutTitle) {
            document.getElementById('completion_workout_id').value = workoutId;
            document.getElementById('workout_title').value = workoutTitle;
            document.getElementById('logCompletionModal').classList.add('show');
        }
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Star rating functionality
        const ratingInputs = document.querySelectorAll('.rating-input input[type="radio"]');
        const ratingLabels = document.querySelectorAll('.rating-input label');
        
        ratingInputs.forEach((input, index) => {
            input.addEventListener('change', function() {
                const rating = parseInt(this.value);
                
                ratingLabels.forEach((label, i) => {
                    if (i < rating) {
                        label.classList.add('active');
                    } else {
                        label.classList.remove('active');
                    }
                });
            });
        });
        
        // Energy level slider
        const energySlider = document.getElementById('energy_level');
        const energyDisplay = document.getElementById('energy_display');
        
        if (energySlider && energyDisplay) {
            energySlider.addEventListener('input', function() {
                energyDisplay.textContent = this.value;
            });
        }
        
        // Initialize rating display
        document.addEventListener('DOMContentLoaded', function() {
            const checkedRating = document.querySelector('.rating-input input[type="radio"]:checked');
            if (checkedRating) {
                const rating = parseInt(checkedRating.value);
                ratingLabels.forEach((label, i) => {
                    if (i < rating) {
                        label.classList.add('active');
                    }
                });
            }
        });
    </script>
</body>
</html>
