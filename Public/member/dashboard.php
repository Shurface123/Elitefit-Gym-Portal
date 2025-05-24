<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Helper functions to replace count()
function array_has_items($array) {
    if (!isset($array) || !is_array($array)) {
        return false;
    }
    
    foreach ($array as $item) {
        return true; // If we get here, there's at least one item
    }
    return false; // No items found
}

function array_count($array) {
    if (!isset($array) || !is_array($array)) {
        return 0;
    }
    
    $count = 0;
    foreach ($array as $item) {
        $count++;
    }
    return $count;
}

// Helper function for time ago
function timeAgo($datetime) {
    if (empty($datetime)) return 'Unknown';
    
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return 'Unknown';
    
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
        return date("M j, Y", $timestamp);
    }
}

// Helper function for date formatting
function formatDate($date) {
    if (empty($date)) return 'Not specified';
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M j, Y', $timestamp) : 'Not specified';
}

// Helper function for time formatting
function formatTime($datetime) {
    if (empty($datetime)) return 'Not specified';
    $timestamp = strtotime($datetime);
    return $timestamp !== false ? date('g:i A', $timestamp) : 'Not specified';
}

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

// Get comprehensive member details
$stmt = $conn->prepare("
  SELECT 
      id, name, email, experience_level, fitness_goals, preferred_routines,
      height, weight, date_of_birth, join_date, phone, membership_type
  FROM users 
  WHERE id = ?
");
$stmt->execute([$userId]);
$memberDetails = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if user exists and set default values
if ($memberDetails === false) {
    // If no user found, create a default array
    $memberDetails = [
        'height' => 'Not specified',
        'weight' => 'Not specified',
        'date_of_birth' => null,
        'join_date' => date('Y-m-d'),
        'membership_type' => 'Standard',
        'experience_level' => 'Not specified',
        'fitness_goals' => 'No goals set yet',
        'preferred_routines' => 'No preferences set'
    ];
} else {
    // Set default values for missing fields
    $memberDetails['height'] = $memberDetails['height'] ?? 'Not specified';
    $memberDetails['weight'] = $memberDetails['weight'] ?? 'Not specified';
    $memberDetails['date_of_birth'] = $memberDetails['date_of_birth'] ?? null;
    $memberDetails['join_date'] = $memberDetails['join_date'] ?? date('Y-m-d');
    $memberDetails['membership_type'] = $memberDetails['membership_type'] ?? 'Standard';
    $memberDetails['experience_level'] = $memberDetails['experience_level'] ?? 'Not specified';
    $memberDetails['fitness_goals'] = $memberDetails['fitness_goals'] ?? 'No goals set yet';
    $memberDetails['preferred_routines'] = $memberDetails['preferred_routines'] ?? 'No preferences set';
}

// Calculate membership duration
$joinDate = new DateTime($memberDetails['join_date']);
$now = new DateTime();
$membershipDuration = $joinDate->diff($now);

// Get assigned workouts
$workoutStmt = $conn->prepare("
   SELECT wp.id, wp.title, wp.description, wp.created_at, wp.difficulty,
       u.name as trainer_name, u.profile_image as trainer_image,
       (SELECT COUNT(*) FROM workout_exercises WHERE workout_id = wp.id) as exercise_count
FROM workout_plans wp
JOIN users u ON wp.trainer_id = u.id
WHERE wp.member_id = ?
ORDER BY wp.created_at DESC
LIMIT 5
");
$workoutStmt->execute([$userId]);
$workouts = $workoutStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming appointments
$appointmentStmt = $conn->prepare("
   SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
          u.name as trainer_name, u.profile_image as trainer_image
   FROM trainer_schedule ts
   JOIN users u ON ts.trainer_id = u.id
   WHERE ts.member_id = ? AND ts.start_time > NOW() AND ts.status IN ('scheduled', 'confirmed')
   ORDER BY ts.start_time ASC
   LIMIT 3
");
$appointmentStmt->execute([$userId]);
$appointments = $appointmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent progress data
$progressStmt = $conn->prepare("
   SELECT pt.*, pt.created_at as tracking_date
FROM progress_tracking pt
WHERE pt.member_id = ?
ORDER BY pt.created_at DESC
LIMIT 5
");
$progressStmt->execute([$userId]);
$progressData = $progressStmt->fetchAll(PDO::FETCH_ASSOC);

// Format progress data for charts
$chartLabels = [];
$weightData = [];
$bodyFatData = [];
$muscleMassData = [];

foreach (array_reverse($progressData) as $entry) {
   $chartLabels[] = date('M d', strtotime($entry['tracking_date']));
   $weightData[] = $entry['weight'] ?? 0;
   $bodyFatData[] = $entry['body_fat'] ?? 0;
   $muscleMassData[] = $entry['muscle_mass'] ?? 0;
}

// Get notifications
$notificationStmt = $conn->prepare("
   SELECT * FROM member_notifications 
   WHERE member_id = ? AND is_read = 0
   ORDER BY created_at DESC
   LIMIT 5
");
$notificationStmt->execute([$userId]);
$notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);

// Get workout completion stats
$completionStmt = $conn->prepare("
   SELECT COUNT(*) as total_completed, SUM(duration_minutes) as total_minutes
   FROM workout_completion
   WHERE member_id = ?
");
$completionStmt->execute([$userId]);
$completionStats = $completionStmt->fetch(PDO::FETCH_ASSOC);
$totalCompleted = $completionStats['total_completed'] ?? 0;
$totalHours = round(($completionStats['total_minutes'] ?? 0) / 60, 1);

// Get total trainers
$trainersStmt = $conn->prepare("
   SELECT COUNT(DISTINCT trainer_id) as total_trainers
   FROM trainer_members
   WHERE member_id = ?
");
$trainersStmt->execute([$userId]);
$trainersStats = $trainersStmt->fetch(PDO::FETCH_ASSOC);
$totalTrainers = $trainersStats['total_trainers'] ?? 0;

// Calculate BMI if height and weight are available
$bmi = '';
$bmiCategory = '';
if (!empty($memberDetails['height']) && !empty($memberDetails['weight']) && 
    $memberDetails['height'] !== 'Not specified' && $memberDetails['weight'] !== 'Not specified') {
    $heightInMeters = $memberDetails['height'] / 100;
    $bmi = round($memberDetails['weight'] / ($heightInMeters * $heightInMeters), 1);
    
    if ($bmi < 18.5) {
        $bmiCategory = 'Underweight';
    } elseif ($bmi < 25) {
        $bmiCategory = 'Normal';
    } elseif ($bmi < 30) {
        $bmiCategory = 'Overweight';
    } else {
        $bmiCategory = 'Obese';
    }
}

?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Member Dashboard - EliteFit Gym</title>
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
            align-items: center;
            gap: 1rem;
        }

        .notification-bell {
            position: relative;
            cursor: pointer;
            padding: 0.75rem;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
            transition: all 0.3s ease;
        }

        .notification-bell:hover {
            background: rgba(255, 107, 53, 0.2);
        }

        .notification-badge {
            position: absolute;
            top: 0.25rem;
            right: 0.25rem;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            width: 320px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }

        .notification-body {
            max-height: 300px;
            overflow-y: auto;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.3s ease;
        }

        .notification-item:hover {
            background: rgba(255, 107, 53, 0.05);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
        }

        .notification-content p {
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .notification-time {
            font-size: 0.75rem;
            color: var(--text);
            opacity: 0.6;
        }

        .notification-empty {
            padding: 2rem;
            text-align: center;
            color: var(--text);
            opacity: 0.6;
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
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
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
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
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
            font-size: 2rem;
            font-weight: 700;
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
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
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

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
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
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .fitness-profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .profile-section {
            background: rgba(255, 107, 53, 0.05);
            border-radius: 1rem;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .profile-section h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: var(--card-bg);
            border-radius: 0.5rem;
            border: 1px solid var(--border);
        }

        .profile-item:last-child {
            margin-bottom: 0;
        }

        .profile-label {
            font-weight: 500;
            color: var(--text);
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-label i {
            color: var(--primary);
            width: 16px;
        }

        .profile-value {
            font-weight: 600;
            color: var(--text);
        }

        .bmi-indicator {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }

        .bmi-normal {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .bmi-underweight {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .bmi-overweight {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .bmi-obese {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .chart-container {
            height: 300px;
            margin-top: 1rem;
        }

        .chart-container.active {
            display: block;
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

        .empty-state p {
            margin-bottom: 0.5rem;
        }

        .appointments-list,
        .workouts-list {
            display: grid;
            gap: 1rem;
        }

        .appointment-item,
        .workout-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.75rem;
            border-left: 4px solid var(--primary);
            transition: all 0.3s ease;
        }

        .appointment-item:hover,
        .workout-item:hover {
            background: rgba(255, 107, 53, 0.1);
            transform: translateX(4px);
        }

        .appointment-date {
            text-align: center;
            min-width: 60px;
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

        .appointment-details,
        .workout-info {
            flex: 1;
        }

        .appointment-details h4,
        .workout-info h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .trainer-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text);
            opacity: 0.8;
        }

        .trainer-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
        }

        .trainer-avatar-placeholder {
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

        .workout-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.7;
            margin-top: 0.5rem;
        }

        .workout-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .workout-meta i {
            color: var(--primary);
        }

        .workout-actions {
            display: flex;
            gap: 0.5rem;
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

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .quick-action-item {
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

        .quick-action-item:hover {
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

        .quick-action-text {
            font-weight: 500;
            text-align: center;
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

            .fitness-profile-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
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
               <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
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
                   <h1>Welcome back, <?php echo htmlspecialchars($userName); ?>!</h1>
                   <p>Here's an overview of your fitness journey</p>
               </div>
               <div class="header-actions">
                   <div class="notification-bell" id="notificationBell">
                       <?php if (array_has_items($notifications)): ?>
                           <span class="notification-badge"><?php echo array_count($notifications); ?></span>
                       <?php endif; ?>
                       <i class="fas fa-bell"></i>
                       
                       <!-- Notification Dropdown -->
                       <div class="notification-dropdown" id="notificationDropdown">
                           <div class="notification-header">
                               <h3>Notifications</h3>
                               <a href="notifications.php">View All</a>
                           </div>
                           <div class="notification-body">
                               <?php if (array_has_items($notifications)): ?>
                                   <?php foreach ($notifications as $notification): ?>
                                       <div class="notification-item">
                                           <div class="notification-icon">
                                               <i class="fas fa-<?php echo $notification['icon'] ?? 'bell'; ?>"></i>
                                           </div>
                                           <div class="notification-content">
                                               <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                               <span class="notification-time">
                                                   <?php echo timeAgo($notification['created_at']); ?>
                                               </span>
                                           </div>
                                       </div>
                                   <?php endforeach; ?>
                               <?php else: ?>
                                   <div class="notification-empty">
                                       <p>No new notifications</p>
                                   </div>
                               <?php endif; ?>
                           </div>
                       </div>
                   </div>
                   
                   <div class="theme-toggle" id="themeToggle">
                       <i class="fas fa-moon"></i>
                   </div>
               </div>
           </div>
           
           <!-- Stats Cards -->
           <div class="stats-grid">
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-calendar-check"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Member Since</h3>
                       <div class="stat-value"><?php echo formatDate($memberDetails['join_date'] ?? date('Y-m-d')); ?></div>
                       <div class="stat-label">
                           <?php 
                               if ($membershipDuration->y > 0) {
                                   echo $membershipDuration->y . ' year' . ($membershipDuration->y > 1 ? 's' : '');
                               } elseif ($membershipDuration->m > 0) {
                                   echo $membershipDuration->m . ' month' . ($membershipDuration->m > 1 ? 's' : '');
                               } else {
                                   echo $membershipDuration->d . ' day' . ($membershipDuration->d > 1 ? 's' : '');
                               }
                           ?> with us
                       </div>
                   </div>
               </div>
               
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-dumbbell"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Workout Plans</h3>
                       <div class="stat-value"><?php echo array_count($workouts); ?></div>
                       <div class="stat-label">assigned to you</div>
                   </div>
               </div>
               
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-check-circle"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Completed Workouts</h3>
                       <div class="stat-value"><?php echo $totalCompleted; ?></div>
                       <div class="stat-label"><?php echo $totalHours; ?> hours total</div>
                   </div>
               </div>
               
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-weight"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Current Weight</h3>
                       <div class="stat-value">
                           <?php 
                               echo array_has_items($progressData) && isset($progressData[0]['weight']) 
                                   ? number_format($progressData[0]['weight'], 1) . ' kg' 
                                   : ($memberDetails['weight'] != 'Not specified' ? $memberDetails['weight'] . ' kg' : 'N/A'); 
                           ?>
                       </div>
                       <div class="stat-label">
                           <?php 
                               if (array_count($progressData) >= 2 && isset($progressData[0]['weight'], $progressData[1]['weight'])) {
                                   $weightDiff = $progressData[0]['weight'] - $progressData[1]['weight'];
                                   $direction = $weightDiff < 0 ? 'down' : 'up';
                                   echo abs($weightDiff) > 0 
                                       ? number_format(abs($weightDiff), 1) . ' kg ' . $direction . ' since last check' 
                                       : 'No change since last check';
                               } else {
                                   echo 'Track your progress';
                               }
                           ?>
                       </div>
                   </div>
               </div>
           </div>
           
           <!-- Main Dashboard Content -->
           <div class="dashboard-grid">
               <!-- Progress Chart -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-chart-line"></i> Your Progress</h2>
                       <a href="progress.php" class="btn btn-sm">View Details</a>
                   </div>
                   <div class="card-content">
                       <?php if (array_has_items($progressData)): ?>
                           <div class="chart-container active">
                               <canvas id="progressChart"></canvas>
                           </div>
                       <?php else: ?>
                           <div class="empty-state">
                               <i class="fas fa-chart-line"></i>
                               <p>No progress data available yet</p>
                               <p>Your trainer will add your progress measurements soon</p>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
               
               <!-- Enhanced Fitness Profile -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-user-circle"></i> Fitness Profile</h2>
                       <a href="profile.php" class="btn btn-sm">Edit Profile</a>
                   </div>
                   <div class="card-content">
                       <div class="fitness-profile-grid">
                           <div class="profile-section">
                               <h3><i class="fas fa-info-circle"></i> Personal Info</h3>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-star"></i> Experience Level
                                   </div>
                                   <div class="profile-value">
                                       <?php echo htmlspecialchars($memberDetails['experience_level'] ?? 'Not specified'); ?>
                                   </div>
                               </div>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-ruler-vertical"></i> Height
                                   </div>
                                   <div class="profile-value">
                                       <?php echo htmlspecialchars($memberDetails['height'] ?? 'Not specified'); ?>
                                       <?php if ($memberDetails['height'] !== 'Not specified'): ?>
                                           cm
                                       <?php endif; ?>
                                   </div>
                               </div>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-weight"></i> Weight
                                   </div>
                                   <div class="profile-value">
                                       <?php echo htmlspecialchars($memberDetails['weight'] ?? 'Not specified'); ?>
                                       <?php if ($memberDetails['weight'] !== 'Not specified'): ?>
                                           kg
                                       <?php endif; ?>
                                   </div>
                               </div>
                               
                               <?php if (!empty($bmi)): ?>
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-calculator"></i> BMI
                                   </div>
                                   <div class="profile-value">
                                       <?php echo $bmi; ?>
                                       <span class="bmi-indicator bmi-<?php echo strtolower($bmiCategory); ?>">
                                           <?php echo $bmiCategory; ?>
                                       </span>
                                   </div>
                               </div>
                               <?php endif; ?>
                           </div>
                           
                           <div class="profile-section">
                               <h3><i class="fas fa-target"></i> Fitness Goals</h3>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-bullseye"></i> Primary Goals
                                   </div>
                                   <div class="profile-value">
                                       <?php echo htmlspecialchars($memberDetails['fitness_goals'] ?? 'No goals set yet'); ?>
                                   </div>
                               </div>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-heart"></i> Preferred Routines
                                   </div>
                                   <div class="profile-value">
                                       <?php echo htmlspecialchars($memberDetails['preferred_routines'] ?? 'No preferences set'); ?>
                                   </div>
                               </div>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-crown"></i> Membership Type
                                   </div>
                                   <div class="profile-value">
                                       <?php echo htmlspecialchars($memberDetails['membership_type'] ?? 'Standard'); ?>
                                   </div>
                               </div>
                               
                               <div class="profile-item">
                                   <div class="profile-label">
                                       <i class="fas fa-users"></i> Trainers
                                   </div>
                                   <div class="profile-value">
                                       <?php echo $totalTrainers; ?> assigned
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>
               
               <!-- Upcoming Appointments -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-calendar-alt"></i> Upcoming Appointments</h2>
                       <a href="appointments.php" class="btn btn-sm">View All</a>
                   </div>
                   <div class="card-content">
                       <?php if (array_has_items($appointments)): ?>
                           <div class="appointments-list">
                               <?php foreach ($appointments as $appointment): ?>
                                   <div class="appointment-item">
                                       <div class="appointment-date">
                                           <div class="date"><?php echo formatDate($appointment['start_time']); ?></div>
                                           <div class="time"><?php echo formatTime($appointment['start_time']); ?> - <?php echo formatTime($appointment['end_time']); ?></div>
                                       </div>
                                       
                                       <div class="appointment-details">
                                           <h4><?php echo htmlspecialchars($appointment['title']); ?></h4>
                                           <div class="trainer-info">
                                               <?php if (!empty($appointment['trainer_image'])): ?>
                                                   <img src="<?php echo htmlspecialchars($appointment['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                               <?php else: ?>
                                                   <div class="trainer-avatar-placeholder">
                                                       <?php echo strtoupper(substr($appointment['trainer_name'], 0, 1)); ?>
                                                   </div>
                                               <?php endif; ?>
                                               <span>with <?php echo htmlspecialchars($appointment['trainer_name']); ?></span>
                                           </div>
                                       </div>
                                       
                                       <div class="appointment-status">
                                           <span class="status-badge <?php echo $appointment['status']; ?>">
                                               <?php echo ucfirst($appointment['status']); ?>
                                           </span>
                                       </div>
                                   </div>
                               <?php endforeach; ?>
                           </div>
                       <?php else: ?>
                           <div class="empty-state">
                               <i class="fas fa-calendar-alt"></i>
                               <p>No upcoming appointments</p>
                               <a href="appointments.php" class="btn">Schedule a Session</a>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
               
               <!-- Recent Workouts -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-dumbbell"></i> My Workout Plans</h2>
                       <a href="workouts.php" class="btn btn-sm">View All</a>
                   </div>
                   <div class="card-content">
                       <?php if (array_has_items($workouts)): ?>
                           <div class="workouts-list">
                               <?php foreach ($workouts as $workout): ?>
                                   <div class="workout-item">
                                       <div class="workout-info">
                                           <h4><?php echo htmlspecialchars($workout['title']); ?></h4>
                                           <div class="trainer-info">
                                               <?php if (!empty($workout['trainer_image'])): ?>
                                                   <img src="<?php echo htmlspecialchars($workout['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                               <?php else: ?>
                                                   <div class="trainer-avatar-placeholder">
                                                       <?php echo strtoupper(substr($workout['trainer_name'], 0, 1)); ?>
                                                   </div>
                                               <?php endif; ?>
                                               <span>by <?php echo htmlspecialchars($workout['trainer_name']); ?></span>
                                           </div>
                                           <div class="workout-meta">
                                               <span><i class="fas fa-tasks"></i> <?php echo $workout['exercise_count']; ?> exercises</span>
                                               <span><i class="fas fa-signal"></i> <?php echo ucfirst($workout['difficulty'] ?? 'Beginner'); ?></span>
                                               <span><i class="fas fa-calendar"></i> <?php echo formatDate($workout['created_at']); ?></span>
                                           </div>
                                       </div>
                                       <div class="workout-actions">
                                           <a href="workout-details.php?id=<?php echo $workout['id']; ?>" class="btn btn-sm">View Details</a>
                                       </div>
                                   </div>
                               <?php endforeach; ?>
                           </div>
                       <?php else: ?>
                           <div class="empty-state">
                               <i class="fas fa-dumbbell"></i>
                               <p>No workout plans assigned yet</p>
                               <a href="workouts.php" class="btn">Request a Workout Plan</a>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
               
               <!-- Quick Actions -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                   </div>
                   <div class="card-content">
                       <div class="quick-actions">
                           <a href="appointments.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-calendar-plus"></i>
                               </div>
                               <div class="quick-action-text">Book a Session</div>
                           </a>
                           
                           <a href="workouts.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-dumbbell"></i>
                               </div>
                               <div class="quick-action-text">Request Workout</div>
                           </a>
                           
                           <a href="progress.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-weight"></i>
                               </div>
                               <div class="quick-action-text">Log Progress</div>
                           </a>
                           
                           <a href="nutrition.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-apple-alt"></i>
                               </div>
                               <div class="quick-action-text">Track Nutrition</div>
                           </a>
                       </div>
                   </div>
               </div>
           </div>
       </div>
   </div>
   
   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <script>
       // Mobile menu toggle
       document.getElementById('mobileMenuToggle').addEventListener('click', function() {
           document.getElementById('sidebar').classList.toggle('show');
       });
       
       // Notification dropdown toggle
       document.getElementById('notificationBell').addEventListener('click', function(e) {
           e.stopPropagation();
           document.getElementById('notificationDropdown').classList.toggle('show');
       });
       
       // Close notification dropdown when clicking outside
       document.addEventListener('click', function(e) {
           if (!document.getElementById('notificationBell').contains(e.target)) {
               document.getElementById('notificationDropdown').classList.remove('show');
           }
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
       
       // Initialize progress chart if data exists
       const progressChartEl = document.getElementById('progressChart');
       if (progressChartEl) {
           const ctx = progressChartEl.getContext('2d');
           
           const chartLabels = <?php echo json_encode($chartLabels); ?>;
           const weightData = <?php echo json_encode($weightData); ?>;
           const bodyFatData = <?php echo json_encode($bodyFatData); ?>;
           const muscleMassData = <?php echo json_encode($muscleMassData); ?>;
           
           if (chartLabels && chartLabels.length > 0) {
               new Chart(ctx, {
                   type: 'line',
                   data: {
                       labels: chartLabels,
                       datasets: [
                           {
                               label: 'Weight (kg)',
                               data: weightData,
                               backgroundColor: 'rgba(255, 107, 53, 0.1)',
                               borderColor: '#ff6b35',
                               borderWidth: 3,
                               tension: 0.4,
                               fill: true
                           },
                           {
                               label: 'Body Fat (%)',
                               data: bodyFatData,
                               backgroundColor: 'rgba(231, 76, 60, 0.1)',
                               borderColor: '#e74c3c',
                               borderWidth: 3,
                               tension: 0.4,
                               fill: true
                           },
                           {
                               label: 'Muscle Mass (kg)',
                               data: muscleMassData,
                               backgroundColor: 'rgba(39, 174, 96, 0.1)',
                               borderColor: '#27ae60',
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
                               labels: {
                                   usePointStyle: true,
                                   padding: 20
                               }
                           }
                       },
                       scales: {
                           y: {
                               beginAtZero: false,
                               grid: {
                                   color: 'rgba(0, 0, 0, 0.1)'
                               }
                           },
                           x: {
                               grid: {
                                   color: 'rgba(0, 0, 0, 0.1)'
                               }
                           }
                       },
                       elements: {
                           point: {
                               radius: 6,
                               hoverRadius: 8
                           }
                       }
                   }
               });
           }
       }
   </script>
</body>
</html>
