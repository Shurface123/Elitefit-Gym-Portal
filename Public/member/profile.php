<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userEmail = $_SESSION['email'] ?? '';
$profileImage = $_SESSION['profile_image'] ?? '';

// Connect to database
$conn = connectDB();

// Include theme preference helper
require_once 'member-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Get user's measurement unit preference
$measurementUnit = 'metric'; // Default
try {
    $stmt = $conn->prepare("SELECT measurement_unit FROM member_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $measurementUnit = $result['measurement_unit'];
    }
} catch (PDOException $e) {
    // Use default
}

// Get comprehensive user profile data
$profile = [
    'name' => $userName,
    'email' => $userEmail,
    'phone' => '',
    'height' => '',
    'weight' => '',
    'date_of_birth' => '',
    'experience_level' => '',
    'fitness_goals' => '',
    'preferred_routines' => '',
    'profile_image' => $profileImage,
    'join_date' => date('Y-m-d'),
    'emergency_contact' => '',
    'medical_conditions' => '',
    'membership_type' => 'Standard'
];

try {
    // Get profile data from users table
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        // Update profile with values from database
        foreach ($profile as $key => $value) {
            if (isset($userData[$key])) {
                $profile[$key] = $userData[$key];
            }
        }
    }
} catch (PDOException $e) {
    // Handle error - default profile already set
}

// Calculate age and membership duration
$age = '';
$membershipDuration = '';

if (!empty($profile['date_of_birth'])) {
    $dob = new DateTime($profile['date_of_birth']);
    $now = new DateTime();
    $interval = $now->diff($dob);
    $age = $interval->y;
}

if (!empty($profile['join_date'])) {
    $joinDate = new DateTime($profile['join_date']);
    $now = new DateTime();
    $interval = $now->diff($joinDate);
    
    if ($interval->y > 0) {
        $membershipDuration = $interval->y . ' year' . ($interval->y > 1 ? 's' : '');
    } elseif ($interval->m > 0) {
        $membershipDuration = $interval->m . ' month' . ($interval->m > 1 ? 's' : '');
    } else {
        $membershipDuration = $interval->d . ' day' . ($interval->d > 1 ? 's' : '');
    }
}

// Format height and weight based on measurement unit
$formattedHeight = '';
$formattedWeight = '';

if (!empty($profile['height'])) {
    if ($measurementUnit === 'imperial') {
        $heightInches = $profile['height'] / 2.54;
        $feet = floor($heightInches / 12);
        $inches = round($heightInches % 12);
        $formattedHeight = $feet . "' " . $inches . '"';
    } else {
        $formattedHeight = $profile['height'] . ' cm';
    }
}

if (!empty($profile['weight'])) {
    if ($measurementUnit === 'imperial') {
        $weightLbs = round($profile['weight'] * 2.20462);
        $formattedWeight = $weightLbs . ' lbs';
    } else {
        $formattedWeight = $profile['weight'] . ' kg';
    }
}

// Get comprehensive workout stats
$workoutStats = [
    'total_workouts' => 0,
    'total_hours' => 0,
    'streak' => 0,
    'favorite_exercise' => 'Not available',
    'avg_session_duration' => 0,
    'calories_burned' => 0
];

try {
    // Get workout completion stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(duration_minutes) as total_minutes, SUM(calories_burned) as total_calories FROM workout_completion WHERE member_id = ?");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats) {
        $workoutStats['total_workouts'] = $stats['total'] ?: 0;
        $workoutStats['total_hours'] = round(($stats['total_minutes'] ?: 0) / 60, 1);
        $workoutStats['calories_burned'] = $stats['total_calories'] ?: 0;
        $workoutStats['avg_session_duration'] = $stats['total'] > 0 ? round(($stats['total_minutes'] ?: 0) / $stats['total']) : 0;
    }
    
    // Calculate current streak
    $stmt = $conn->prepare("
        SELECT completion_date FROM workout_completion 
        WHERE member_id = ? 
        ORDER BY completion_date DESC
        LIMIT 30
    ");
    $stmt->execute([$userId]);
    $workoutDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($workoutDates)) {
        $streak = 0;
        $today = new DateTime();
        $yesterday = clone $today;
        $yesterday->modify('-1 day');
        
        $lastWorkoutDate = new DateTime($workoutDates[0]);
        if ($lastWorkoutDate->format('Y-m-d') === $today->format('Y-m-d') || 
            $lastWorkoutDate->format('Y-m-d') === $yesterday->format('Y-m-d')) {
            $streak = 1;
            
            $checkDate = clone $yesterday;
            $checkDate->modify('-1 day');
            
            for ($i = 1; $i < count($workoutDates); $i++) {
                $workoutDate = new DateTime($workoutDates[$i]);
                if ($workoutDate->format('Y-m-d') === $checkDate->format('Y-m-d')) {
                    $streak++;
                    $checkDate->modify('-1 day');
                } else {
                    break;
                }
            }
        }
        
        $workoutStats['streak'] = $streak;
    }
} catch (PDOException $e) {
    // Handle error
}

// Get achievements
$achievements = [];
try {
    $stmt = $conn->prepare("
        SELECT a.*, ua.achieved_date 
        FROM achievements a
        JOIN user_achievements ua ON a.id = ua.achievement_id
        WHERE ua.user_id = ?
        ORDER BY ua.achieved_date DESC
        LIMIT 6
    ");
    $stmt->execute([$userId]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Create sample achievements for display
    $achievements = [
        ['name' => 'First Workout', 'icon' => 'fa-dumbbell', 'achieved_date' => date('Y-m-d', strtotime('-30 days'))],
        ['name' => 'Weight Goal', 'icon' => 'fa-weight', 'achieved_date' => date('Y-m-d', strtotime('-15 days'))],
        ['name' => '10 Workouts', 'icon' => 'fa-fire', 'achieved_date' => date('Y-m-d', strtotime('-7 days'))]
    ];
}

// Get upcoming appointments
$appointments = [];
try {
    $stmt = $conn->prepare("
        SELECT ts.*, u.name as trainer_name, u.profile_image as trainer_image
        FROM trainer_schedule ts
        LEFT JOIN users u ON ts.trainer_id = u.id
        WHERE ts.member_id = ? AND ts.start_time >= NOW()
        ORDER BY ts.start_time
        LIMIT 3
    ");
    $stmt->execute([$userId]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Get recent progress data
$progressData = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM progress_tracking 
        WHERE member_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $progressData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - EliteFit Gym</title>
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

        .profile-hero {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 2rem;
            padding: 3rem 2rem;
            text-align: center;
            color: white;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="1" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="1" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .profile-hero-content {
            position: relative;
            z-index: 1;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 3rem;
            margin: 0 auto 1.5rem;
            border: 4px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-name {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .profile-role {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }

        .profile-stats-hero {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 2rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .stat-item-hero {
            text-align: center;
        }

        .stat-number-hero {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: block;
        }

        .stat-text-hero {
            font-size: 0.9rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .profile-section {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .profile-section:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .info-grid {
            display: grid;
            gap: 1.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.75rem;
            border-left: 4px solid var(--primary);
        }

        .info-label {
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-label i {
            color: var(--primary);
            width: 20px;
        }

        .info-value {
            font-weight: 500;
            color: var(--text);
        }

        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .achievement-card {
            background: rgba(255, 107, 53, 0.05);
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .achievement-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .achievement-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            margin: 0 auto 1rem;
        }

        .achievement-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .achievement-date {
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.7;
        }

        .appointments-list {
            display: grid;
            gap: 1rem;
        }

        .appointment-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.5rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 1rem;
            border-left: 4px solid var(--primary);
        }

        .appointment-date {
            text-align: center;
            min-width: 60px;
        }

        .date-day {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .date-month {
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.7;
            text-transform: uppercase;
        }

        .appointment-details {
            flex: 1;
        }

        .appointment-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .appointment-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--text);
            opacity: 0.8;
        }

        .appointment-meta i {
            color: var(--primary);
            margin-right: 0.25rem;
        }

        .progress-chart {
            background: rgba(255, 107, 53, 0.05);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .progress-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .progress-label {
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress-label i {
            color: var(--primary);
        }

        .progress-value {
            font-weight: 600;
            color: var(--primary);
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

        .edit-profile-btn {
            position: absolute;
            top: 2rem;
            right: 2rem;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .edit-profile-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: var(--primary);
            color: white;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
            margin: 0.25rem;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        @media (max-width: 1024px) {
            .profile-content {
                grid-template-columns: 1fr;
            }
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

            .profile-hero {
                padding: 2rem 1rem;
            }

            .profile-name {
                font-size: 2rem;
            }

            .profile-stats-hero {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .stat-number-hero {
                font-size: 2rem;
            }

            .profile-section {
                padding: 1.5rem;
            }

            .achievements-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .edit-profile-btn {
                position: static;
                margin-top: 1rem;
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
                <li><a href="workouts.php"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
                <li><a href="progress.php"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
                <li><a href="appointments.php"><i class="fas fa-calendar-alt"></i> <span>Appointments</span></a></li>
                <li><a href="nutrition.php"><i class="fas fa-apple-alt"></i> <span>Nutrition</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-friends"></i> <span>Trainers</span></a></li>
                <li><a href="profile.php" class="active"><i class="fas fa-user"></i> <span>Profile</span></a></li>
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
            
            <!-- Profile Hero Section -->
            <div class="profile-hero">
                <a href="settings.php" class="btn btn-outline edit-profile-btn">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <div class="profile-hero-content">
                    <div class="profile-avatar-large">
                        <?php if (!empty($profile['profile_image'])): ?>
                            <img src="<?php echo htmlspecialchars($profile['profile_image']); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo strtoupper(substr($profile['name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <h1 class="profile-name"><?php echo htmlspecialchars($profile['name']); ?></h1>
                    <div class="profile-role">
                        <?php echo htmlspecialchars($profile['membership_type']); ?> Member
                        <?php if (!empty($membershipDuration)): ?>
                            • <?php echo $membershipDuration; ?> with us
                        <?php endif; ?>
                    </div>
                    
                    <div class="profile-stats-hero">
                        <div class="stat-item-hero">
                            <span class="stat-number-hero"><?php echo $workoutStats['total_workouts']; ?></span>
                            <span class="stat-text-hero">Workouts</span>
                        </div>
                        <div class="stat-item-hero">
                            <span class="stat-number-hero"><?php echo $workoutStats['total_hours']; ?></span>
                            <span class="stat-text-hero">Hours</span>
                        </div>
                        <div class="stat-item-hero">
                            <span class="stat-number-hero"><?php echo $workoutStats['streak']; ?></span>
                            <span class="stat-text-hero">Day Streak</span>
                        </div>
                        <div class="stat-item-hero">
                            <span class="stat-number-hero"><?php echo number_format($workoutStats['calories_burned']); ?></span>
                            <span class="stat-text-hero">Calories</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Profile Content Grid -->
            <div class="profile-content">
                <!-- Personal Information -->
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <h2 class="section-title">Personal Information</h2>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-envelope"></i>
                                Email
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($profile['email']); ?></div>
                        </div>
                        
                        <?php if (!empty($profile['phone'])): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-phone"></i>
                                    Phone
                                </div>
                                <div class="info-value"><?php echo htmlspecialchars($profile['phone']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($age)): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-birthday-cake"></i>
                                    Age
                                </div>
                                <div class="info-value"><?php echo $age; ?> years old</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($formattedHeight)): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-ruler-vertical"></i>
                                    Height
                                </div>
                                <div class="info-value"><?php echo $formattedHeight; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($formattedWeight)): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="fas fa-weight"></i>
                                    Weight
                                </div>
                                <div class="info-value"><?php echo $formattedWeight; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-calendar-plus"></i>
                                Member Since
                            </div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($profile['join_date'])); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Fitness Profile -->
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <h2 class="section-title">Fitness Profile</h2>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-signal"></i>
                                Experience Level
                            </div>
                            <div class="info-value">
                                <?php echo !empty($profile['experience_level']) ? ucfirst(htmlspecialchars($profile['experience_level'])) : 'Not specified'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-target"></i>
                                Primary Goals
                            </div>
                            <div class="info-value">
                                <?php echo !empty($profile['fitness_goals']) ? htmlspecialchars($profile['fitness_goals']) : 'Not specified'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-clock"></i>
                                Avg Session
                            </div>
                            <div class="info-value"><?php echo $workoutStats['avg_session_duration']; ?> minutes</div>
                        </div>
                        
                        <?php if (!empty($profile['preferred_routines'])): ?>
                            <div class="info-item" style="grid-column: 1 / -1;">
                                <div class="info-label">
                                    <i class="fas fa-dumbbell"></i>
                                    Preferred Workouts
                                </div>
                                <div class="info-value">
                                    <?php 
                                    $routines = explode(',', $profile['preferred_routines']);
                                    foreach ($routines as $routine): 
                                    ?>
                                        <span class="badge"><?php echo htmlspecialchars(trim($routine)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Achievements -->
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <h2 class="section-title">Achievements</h2>
                    </div>
                    <?php if (empty($achievements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-medal"></i>
                            <p>No achievements yet. Keep working out to earn badges!</p>
                        </div>
                    <?php else: ?>
                        <div class="achievements-grid">
                            <?php foreach ($achievements as $achievement): ?>
                                <div class="achievement-card">
                                    <div class="achievement-icon">
                                        <i class="fas <?php echo $achievement['icon'] ?? 'fa-award'; ?>"></i>
                                    </div>
                                    <div class="achievement-name"><?php echo htmlspecialchars($achievement['name']); ?></div>
                                    <div class="achievement-date">
                                        <?php echo date('M d, Y', strtotime($achievement['achieved_date'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Upcoming Appointments -->
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <h2 class="section-title">Upcoming Sessions</h2>
                    </div>
                    <?php if (empty($appointments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-plus"></i>
                            <p>No upcoming appointments.</p>
                            <a href="appointments.php" class="btn btn-sm">Schedule Session</a>
                        </div>
                    <?php else: ?>
                        <div class="appointments-list">
                            <?php foreach ($appointments as $appointment): ?>
                                <div class="appointment-card">
                                    <div class="appointment-date">
                                        <div class="date-day"><?php echo date('d', strtotime($appointment['start_time'])); ?></div>
                                        <div class="date-month"><?php echo date('M', strtotime($appointment['start_time'])); ?></div>
                                    </div>
                                    <div class="appointment-details">
                                        <div class="appointment-title"><?php echo htmlspecialchars($appointment['title']); ?></div>
                                        <div class="appointment-meta">
                                            <span><i class="fas fa-clock"></i> <?php echo date('g:i A', strtotime($appointment['start_time'])); ?></span>
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($appointment['trainer_name'] ?? 'TBA'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Progress Overview -->
                <div class="profile-section full-width">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h2 class="section-title">Progress Overview</h2>
                    </div>
                    <?php if (empty($progressData)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <p>No progress data available yet. Your trainer will add measurements soon.</p>
                        </div>
                    <?php else: ?>
                        <div class="progress-chart">
                            <?php foreach (array_slice($progressData, 0, 3) as $progress): ?>
                                <div class="progress-item">
                                    <div class="progress-label">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($progress['created_at'])); ?>
                                    </div>
                                    <div class="progress-value">
                                        <?php if (!empty($progress['weight'])): ?>
                                            Weight: <?php echo $progress['weight']; ?>kg
                                        <?php endif; ?>
                                        <?php if (!empty($progress['body_fat'])): ?>
                                            • Body Fat: <?php echo $progress['body_fat']; ?>%
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div style="text-align: center; margin-top: 1rem;">
                                <a href="progress.php" class="btn btn-outline btn-sm">
                                    <i class="fas fa-chart-line"></i> View Full Progress
                                </a>
                            </div>
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
        
        // Add smooth scrolling for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>
