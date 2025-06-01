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

// Handle theme preference
$theme = 'dark'; // Default theme
if (isset($_POST['toggle_theme'])) {
    $newTheme = $_POST['theme'] === 'dark' ? 'light' : 'dark';
    
    // Update theme in database
    $updateThemeStmt = $conn->prepare("
        UPDATE users SET theme_preference = ? WHERE id = ?
    ");
    $updateThemeStmt->execute([$newTheme, $userId]);
    $theme = $newTheme;
} else {
    // Get current theme preference
    $themeStmt = $conn->prepare("SELECT theme_preference FROM users WHERE id = ?");
    $themeStmt->execute([$userId]);
    $userTheme = $themeStmt->fetchColumn();
    $theme = $userTheme ?: 'dark';
}

// Get date range filter
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-6 months'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$comparisonPeriod = isset($_GET['comparison']) ? $_GET['comparison'] : '30'; // days

// Create progress tracking table if not exists
$conn->exec("
    CREATE TABLE IF NOT EXISTS progress_tracking (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        trainer_id INT NOT NULL,
        tracking_date DATE NOT NULL,
        weight DECIMAL(5,2),
        body_fat DECIMAL(5,2),
        muscle_mass DECIMAL(5,2),
        chest DECIMAL(5,2),
        waist DECIMAL(5,2),
        hips DECIMAL(5,2),
        arms DECIMAL(5,2),
        thighs DECIMAL(5,2),
        neck DECIMAL(5,2),
        forearms DECIMAL(5,2),
        calves DECIMAL(5,2),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (member_id),
        INDEX (trainer_id),
        INDEX (tracking_date)
    )
");

// Create progress photos table if not exists
$conn->exec("
    CREATE TABLE IF NOT EXISTS progress_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        photo_date DATE NOT NULL,
        photo_url VARCHAR(255) NOT NULL,
        photo_type ENUM('front', 'side', 'back', 'other') DEFAULT 'front',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (member_id),
        INDEX (photo_date)
    )
");

// Create goals table if not exists
$conn->exec("
    CREATE TABLE IF NOT EXISTS member_goals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        goal_type ENUM('weight_loss', 'weight_gain', 'muscle_gain', 'fat_loss', 'strength', 'endurance', 'other') NOT NULL,
        target_value DECIMAL(8,2),
        current_value DECIMAL(8,2),
        target_date DATE,
        status ENUM('active', 'completed', 'paused') DEFAULT 'active',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (member_id),
        INDEX (status)
    )
");

// Get progress data
$progressStmt = $conn->prepare("
    SELECT pt.*, u.name as trainer_name, u.profile_image as trainer_image
    FROM progress_tracking pt
    JOIN users u ON pt.trainer_id = u.id
    WHERE pt.member_id = ? AND pt.tracking_date BETWEEN ? AND ?
    ORDER BY pt.tracking_date ASC
");
$progressStmt->execute([$userId, $startDate, $endDate]);
$progressData = $progressStmt->fetchAll(PDO::FETCH_ASSOC);

// Get available trainers
$trainersStmt = $conn->prepare("
    SELECT u.id, u.name, u.profile_image, tp.specialization
    FROM users u
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE u.role = 'Trainer' AND u.status = 'active'
    ORDER BY u.name ASC
");
$trainersStmt->execute();
$availableTrainers = $trainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get member goals
$goalsStmt = $conn->prepare("
    SELECT * FROM member_goals 
    WHERE member_id = ? AND status = 'active'
    ORDER BY created_at DESC
");
$goalsStmt->execute([$userId]);
$memberGoals = $goalsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get progress photos
$photoStmt = $conn->prepare("
    SELECT * FROM progress_photos
    WHERE member_id = ? AND photo_date BETWEEN ? AND ?
    ORDER BY photo_date DESC
");
$photoStmt->execute([$userId, $startDate, $endDate]);
$progressPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message'])) {
        try {
            $trainerId = $_POST['trainer_id'];
            $messageContent = $_POST['message'];
            $messageType_post = $_POST['message_type'] ?? 'progress_update';
            
            // Create messages table if not exists
            $conn->exec("
                CREATE TABLE IF NOT EXISTS trainer_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_id INT NOT NULL,
                    receiver_id INT NOT NULL,
                    message TEXT NOT NULL,
                    message_type ENUM('general', 'progress_update', 'goal_update', 'question') DEFAULT 'general',
                    is_read TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (sender_id),
                    INDEX (receiver_id)
                )
            ");
            
            $stmt = $conn->prepare("
                INSERT INTO trainer_messages (sender_id, receiver_id, message, message_type)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $trainerId, $messageContent, $messageType_post]);
            
            $message = 'Message sent successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error sending message: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['add_goal'])) {
        try {
            $goalType = $_POST['goal_type'];
            $targetValue = $_POST['target_value'];
            $targetDate = $_POST['target_date'];
            $description = $_POST['description'];
            
            $stmt = $conn->prepare("
                INSERT INTO member_goals (member_id, goal_type, target_value, target_date, description)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $goalType, $targetValue, $targetDate, $description]);
            
            $message = 'Goal added successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error adding goal: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Format progress data for charts
$chartLabels = [];
$weightData = [];
$bodyFatData = [];
$muscleMassData = [];
$chestData = [];
$waistData = [];
$hipsData = [];
$armsData = [];
$thighsData = [];

foreach ($progressData as $entry) {
    $chartLabels[] = date('M d', strtotime($entry['tracking_date']));
    $weightData[] = $entry['weight'] ? floatval($entry['weight']) : null;
    $bodyFatData[] = $entry['body_fat'] ? floatval($entry['body_fat']) : null;
    $muscleMassData[] = $entry['muscle_mass'] ? floatval($entry['muscle_mass']) : null;
    $chestData[] = $entry['chest'] ? floatval($entry['chest']) : null;
    $waistData[] = $entry['waist'] ? floatval($entry['waist']) : null;
    $hipsData[] = $entry['hips'] ? floatval($entry['hips']) : null;
    $armsData[] = $entry['arms'] ? floatval($entry['arms']) : null;
    $thighsData[] = $entry['thighs'] ? floatval($entry['thighs']) : null;
}

// Calculate progress statistics
function calculateProgressStats($data, $field) {
    $values = array_filter(array_column($data, $field), function($v) { return $v !== null; });
    if (empty($values)) return null;
    
    return [
        'current' => end($values),
        'previous' => reset($values),
        'change' => end($values) - reset($values),
        'change_percent' => reset($values) != 0 ? ((end($values) - reset($values)) / reset($values)) * 100 : 0,
        'min' => min($values),
        'max' => max($values),
        'avg' => array_sum($values) / count($values)
    ];
}

$stats = [
    'weight' => calculateProgressStats($progressData, 'weight'),
    'body_fat' => calculateProgressStats($progressData, 'body_fat'),
    'muscle_mass' => calculateProgressStats($progressData, 'muscle_mass'),
    'chest' => calculateProgressStats($progressData, 'chest'),
    'waist' => calculateProgressStats($progressData, 'waist'),
    'hips' => calculateProgressStats($progressData, 'hips'),
    'arms' => calculateProgressStats($progressData, 'arms'),
    'thighs' => calculateProgressStats($progressData, 'thighs')
];

// Format functions
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function formatChange($change, $isPercentage = false) {
    if ($change == 0) return 'No change';
    $symbol = $change > 0 ? '+' : '';
    $suffix = $isPercentage ? '%' : '';
    return $symbol . number_format($change, 1) . $suffix;
}

function getChangeClass($change, $isGoodWhenPositive = true) {
    if ($change == 0) return 'neutral';
    if ($isGoodWhenPositive) {
        return $change > 0 ? 'positive' : 'negative';
    } else {
        return $change > 0 ? 'negative' : 'positive';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Tracking - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-orange: #ff6b35;
            --primary-orange-dark: #e55a2b;
            --primary-orange-light: #ff8c5a;
            --sidebar-width: 280px;
            --header-height: 80px;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] {
            --bg-primary: #0f0f0f;
            --bg-secondary: #1a1a1a;
            --bg-tertiary: #2a2a2a;
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --text-muted: #666666;
            --border-color: #333333;
            --card-bg: #1e1e1e;
            --sidebar-bg: #161616;
            --hover-bg: #2a2a2a;
        }

        [data-theme="light"] {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-tertiary: #e9ecef;
            --text-primary: #212529;
            --text-secondary: #6c757d;
            --text-muted: #adb5bd;
            --border-color: #dee2e6;
            --card-bg: #ffffff;
            --sidebar-bg: #f8f9fa;
            --hover-bg: #f1f3f4;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            transition: var(--transition);
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: var(--transition);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-header i {
            color: var(--primary-orange);
            font-size: 2rem;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar-user {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--primary-orange);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            background: var(--primary-orange);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .user-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-status {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .sidebar-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-menu li {
            margin: 0.25rem 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 1.5rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            border-radius: 0 25px 25px 0;
            margin-right: 1rem;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: var(--primary-orange);
            color: white;
            transform: translateX(5px);
        }

        .sidebar-menu i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            background: var(--bg-secondary);
            min-height: 100vh;
        }

        .header {
            background: var(--card-bg);
            padding: 2rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .theme-toggle {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 0.5rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .theme-toggle:hover {
            background: var(--hover-bg);
        }

        /* Content Area */
        .content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: var(--transition);
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
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
            color: var(--primary-orange);
        }

        .card-content {
            padding: 2rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-orange-dark), var(--primary-orange));
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background: var(--hover-bg);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .stat-change.positive {
            color: #10b981;
        }

        .stat-change.negative {
            color: #ef4444;
        }

        .stat-change.neutral {
            color: var(--text-secondary);
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 400px;
            margin: 1rem 0;
        }

        .chart-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .chart-tab {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }

        .chart-tab.active,
        .chart-tab:hover {
            background: var(--primary-orange);
            color: white;
            border-color: var(--primary-orange);
        }

        /* Progress Photos */
        .photos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .photo-item {
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
        }

        .photo-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .photo-image {
            aspect-ratio: 4/3;
            overflow: hidden;
        }

        .photo-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-info {
            padding: 1rem;
        }

        .photo-date {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .photo-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Goals */
        .goals-grid {
            display: grid;
            gap: 1rem;
        }

        .goal-item {
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-orange);
        }

        .goal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .goal-type {
            font-weight: 600;
            text-transform: capitalize;
        }

        .goal-progress {
            margin: 1rem 0;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            transition: width 0.3s ease;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
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
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .close-modal:hover {
            background: var(--hover-bg);
        }

        .modal-body {
            padding: 2rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .alert .close {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: currentColor;
            opacity: 0.7;
        }

        .alert .close:hover {
            opacity: 1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-toggle {
                display: block;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
                background: var(--primary-orange);
                color: white;
                border: none;
                border-radius: 8px;
                padding: 0.75rem;
                cursor: pointer;
            }

            .header {
                padding-left: 4rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .photos-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }

        .mobile-menu-toggle {
            display: none;
        }

        /* Comparison View */
        .comparison-view {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }

        .comparison-card {
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
        }

        .comparison-period {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .comparison-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .comparison-change {
            font-size: 0.875rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Mobile Menu Toggle -->
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>

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
                <li><a href="progress.php" class="active"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
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
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Progress Tracking</h1>
                    <p>Monitor your fitness journey and achievements</p>
                </div>
                <div class="header-actions">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="toggle_theme" value="1">
                        <input type="hidden" name="theme" value="<?php echo $theme; ?>">
                        <button type="submit" class="theme-toggle">
                            <i class="fas fa-<?php echo $theme === 'dark' ? 'sun' : 'moon'; ?>"></i>
                            <span><?php echo $theme === 'dark' ? 'Light' : 'Dark'; ?></span>
                        </button>
                    </form>
                    <button class="btn btn-primary" onclick="openGoalModal()">
                        <i class="fas fa-target"></i> Set Goal
                    </button>
                    <button class="btn btn-outline" onclick="openMessageModal()">
                        <i class="fas fa-envelope"></i> Message Trainer
                    </button>
                </div>
            </div>
            
            <div class="content">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?>">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <div><?php echo $message; ?></div>
                        <button type="button" class="close">&times;</button>
                    </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="card">
                    <div class="card-content">
                        <form method="get" class="form-row">
                            <div class="form-group">
                                <label class="form-label">From Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">To Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Comparison Period</label>
                                <select name="comparison" class="form-control">
                                    <option value="30" <?php echo $comparisonPeriod === '30' ? 'selected' : ''; ?>>Last 30 Days</option>
                                    <option value="60" <?php echo $comparisonPeriod === '60' ? 'selected' : ''; ?>>Last 60 Days</option>
                                    <option value="90" <?php echo $comparisonPeriod === '90' ? 'selected' : ''; ?>>Last 90 Days</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if (empty($progressData)): ?>
                    <div class="card">
                        <div class="card-content">
                            <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Progress Data Available</h3>
                                <p>Your trainer hasn't recorded any progress data for you yet in the selected date range.</p>
                                <p>Try selecting a different date range or contact your trainer to schedule a progress assessment.</p>
                                <button class="btn btn-primary" onclick="openMessageModal()" style="margin-top: 1rem;">
                                    <i class="fas fa-envelope"></i> Contact Trainer
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Progress Statistics -->
                    <div class="stats-grid">
                        <?php if ($stats['weight']): ?>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <div class="stat-icon">
                                        <i class="fas fa-weight"></i>
                                    </div>
                                </div>
                                <div class="stat-value"><?php echo number_format($stats['weight']['current'], 1); ?> kg</div>
                                <div class="stat-label">Current Weight</div>
                                <div class="stat-change <?php echo getChangeClass($stats['weight']['change'], false); ?>">
                                    <i class="fas fa-arrow-<?php echo $stats['weight']['change'] >= 0 ? 'up' : 'down'; ?>"></i>
                                    <?php echo formatChange($stats['weight']['change']); ?> kg
                                    (<?php echo formatChange($stats['weight']['change_percent'], true); ?>)
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($stats['body_fat']): ?>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <div class="stat-icon">
                                        <i class="fas fa-percentage"></i>
                                    </div>
                                </div>
                                <div class="stat-value"><?php echo number_format($stats['body_fat']['current'], 1); ?>%</div>
                                <div class="stat-label">Body Fat</div>
                                <div class="stat-change <?php echo getChangeClass($stats['body_fat']['change'], false); ?>">
                                    <i class="fas fa-arrow-<?php echo $stats['body_fat']['change'] >= 0 ? 'up' : 'down'; ?>"></i>
                                    <?php echo formatChange($stats['body_fat']['change']); ?>%
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($stats['muscle_mass']): ?>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <div class="stat-icon">
                                        <i class="fas fa-dumbbell"></i>
                                    </div>
                                </div>
                                <div class="stat-value"><?php echo number_format($stats['muscle_mass']['current'], 1); ?> kg</div>
                                <div class="stat-label">Muscle Mass</div>
                                <div class="stat-change <?php echo getChangeClass($stats['muscle_mass']['change'], true); ?>">
                                    <i class="fas fa-arrow-<?php echo $stats['muscle_mass']['change'] >= 0 ? 'up' : 'down'; ?>"></i>
                                    <?php echo formatChange($stats['muscle_mass']['change']); ?> kg
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo count($progressData); ?></div>
                            <div class="stat-label">Total Measurements</div>
                            <div class="stat-change neutral">
                                <i class="fas fa-chart-line"></i>
                                <?php echo formatDate($progressData[0]['tracking_date']); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Goals Section -->
                    <?php if (!empty($memberGoals)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h2><i class="fas fa-target"></i> My Goals</h2>
                                <button class="btn btn-sm btn-primary" onclick="openGoalModal()">
                                    <i class="fas fa-plus"></i> Add Goal
                                </button>
                            </div>
                            <div class="card-content">
                                <div class="goals-grid">
                                    <?php foreach ($memberGoals as $goal): ?>
                                        <div class="goal-item">
                                            <div class="goal-header">
                                                <div class="goal-type"><?php echo str_replace('_', ' ', $goal['goal_type']); ?></div>
                                                <div class="goal-status"><?php echo ucfirst($goal['status']); ?></div>
                                            </div>
                                            <div class="goal-description"><?php echo htmlspecialchars($goal['description']); ?></div>
                                            <?php if ($goal['target_value'] && $goal['current_value']): ?>
                                                <div class="goal-progress">
                                                    <?php 
                                                        $progress = ($goal['current_value'] / $goal['target_value']) * 100;
                                                        $progress = min(100, max(0, $progress));
                                                    ?>
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                                    </div>
                                                    <div style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--text-secondary);">
                                                        <?php echo number_format($goal['current_value'], 1); ?> / <?php echo number_format($goal['target_value'], 1); ?>
                                                        (<?php echo number_format($progress, 1); ?>%)
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($goal['target_date']): ?>
                                                <div style="margin-top: 1rem; font-size: 0.875rem; color: var(--text-secondary);">
                                                    <i class="fas fa-calendar"></i> Target: <?php echo formatDate($goal['target_date']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Progress Charts -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-chart-line"></i> Progress Charts</h2>
                            <div class="chart-tabs">
                                <button class="chart-tab active" data-chart="body-composition">Body Composition</button>
                                <button class="chart-tab" data-chart="measurements">Measurements</button>
                                <button class="chart-tab" data-chart="comparison">Comparison</button>
                            </div>
                        </div>
                        <div class="card-content">
                            <div class="chart-container" id="body-composition-chart">
                                <canvas id="bodyCompositionChart"></canvas>
                            </div>
                            <div class="chart-container" id="measurements-chart" style="display: none;">
                                <canvas id="measurementsChart"></canvas>
                            </div>
                            <div class="chart-container" id="comparison-chart" style="display: none;">
                                <div class="comparison-view">
                                    <?php if ($stats['weight']): ?>
                                        <div class="comparison-card">
                                            <div class="comparison-period">Weight Change</div>
                                            <div class="comparison-value"><?php echo number_format($stats['weight']['current'], 1); ?> kg</div>
                                            <div class="comparison-change <?php echo getChangeClass($stats['weight']['change'], false); ?>">
                                                <?php echo formatChange($stats['weight']['change']); ?> kg from start
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($stats['body_fat']): ?>
                                        <div class="comparison-card">
                                            <div class="comparison-period">Body Fat Change</div>
                                            <div class="comparison-value"><?php echo number_format($stats['body_fat']['current'], 1); ?>%</div>
                                            <div class="comparison-change <?php echo getChangeClass($stats['body_fat']['change'], false); ?>">
                                                <?php echo formatChange($stats['body_fat']['change']); ?>% from start
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($stats['muscle_mass']): ?>
                                        <div class="comparison-card">
                                            <div class="comparison-period">Muscle Mass Change</div>
                                            <div class="comparison-value"><?php echo number_format($stats['muscle_mass']['current'], 1); ?> kg</div>
                                            <div class="comparison-change <?php echo getChangeClass($stats['muscle_mass']['change'], true); ?>">
                                                <?php echo formatChange($stats['muscle_mass']['change']); ?> kg from start
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progress History Table -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-history"></i> Progress History</h2>
                        </div>
                        <div class="card-content">
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="border-bottom: 1px solid var(--border-color);">
                                            <th style="padding: 1rem; text-align: left;">Date</th>
                                            <th style="padding: 1rem; text-align: left;">Weight (kg)</th>
                                            <th style="padding: 1rem; text-align: left;">Body Fat (%)</th>
                                            <th style="padding: 1rem; text-align: left;">Muscle Mass (kg)</th>
                                            <th style="padding: 1rem; text-align: left;">Trainer</th>
                                            <th style="padding: 1rem; text-align: left;">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($progressData) as $entry): ?>
                                            <tr style="border-bottom: 1px solid var(--border-color);">
                                                <td style="padding: 1rem;"><?php echo formatDate($entry['tracking_date']); ?></td>
                                                <td style="padding: 1rem;"><?php echo $entry['weight'] ? number_format($entry['weight'], 1) : '-'; ?></td>
                                                <td style="padding: 1rem;"><?php echo $entry['body_fat'] ? number_format($entry['body_fat'], 1) : '-'; ?></td>
                                                <td style="padding: 1rem;"><?php echo $entry['muscle_mass'] ? number_format($entry['muscle_mass'], 1) : '-'; ?></td>
                                                <td style="padding: 1rem;">
                                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                        <?php if (!empty($entry['trainer_image'])): ?>
                                                            <img src="<?php echo htmlspecialchars($entry['trainer_image']); ?>" alt="Trainer" style="width: 30px; height: 30px; border-radius: 50%; object-fit: cover;">
                                                        <?php else: ?>
                                                            <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--primary-orange); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.75rem; font-weight: 600;">
                                                                <?php echo strtoupper(substr($entry['trainer_name'], 0, 1)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <span><?php echo htmlspecialchars($entry['trainer_name']); ?></span>
                                                    </div>
                                                </td>
                                                <td style="padding: 1rem;">
                                                    <?php if (!empty($entry['notes'])): ?>
                                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($entry['notes']); ?>">
                                                            <?php echo htmlspecialchars($entry['notes']); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Progress Photos -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-images"></i> Progress Photos</h2>
                        <button class="btn btn-sm btn-primary" onclick="openPhotoModal()">
                            <i class="fas fa-upload"></i> Upload Photo
                        </button>
                    </div>
                    <div class="card-content">
                        <?php if (empty($progressPhotos)): ?>
                            <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="fas fa-images" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No Progress Photos</h3>
                                <p>You haven't uploaded any progress photos yet.</p>
                                <button class="btn btn-primary" onclick="openPhotoModal()" style="margin-top: 1rem;">
                                    <i class="fas fa-upload"></i> Upload Your First Photo
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="photos-grid">
                                <?php foreach ($progressPhotos as $photo): ?>
                                    <div class="photo-item">
                                        <div class="photo-image">
                                            <img src="<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="Progress Photo" onclick="viewPhoto('<?php echo htmlspecialchars($photo['photo_url']); ?>')">
                                        </div>
                                        <div class="photo-info">
                                            <div class="photo-date"><?php echo formatDate($photo['photo_date']); ?></div>
                                            <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                                                <?php echo ucfirst($photo['photo_type']); ?> View
                                            </div>
                                            <div class="photo-actions">
                                                <button class="btn btn-sm btn-outline" onclick="viewPhoto('<?php echo htmlspecialchars($photo['photo_url']); ?>')">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
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
    
    <!-- Message Trainer Modal -->
    <div class="modal" id="messageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Message Trainer</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="send_message" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Select Trainer</label>
                        <select name="trainer_id" class="form-control" required>
                            <option value="">Choose a trainer</option>
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
                        <label class="form-label">Message Type</label>
                        <select name="message_type" class="form-control" required>
                            <option value="progress_update">Progress Update</option>
                            <option value="goal_update">Goal Update</option>
                            <option value="question">Question</option>
                            <option value="general">General Message</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" rows="5" placeholder="Type your message here..." required></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Goal Modal -->
    <div class="modal" id="goalModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Set New Goal</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="add_goal" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Goal Type</label>
                        <select name="goal_type" class="form-control" required>
                            <option value="">Select Goal Type</option>
                            <option value="weight_loss">Weight Loss</option>
                            <option value="weight_gain">Weight Gain</option>
                            <option value="muscle_gain">Muscle Gain</option>
                            <option value="fat_loss">Fat Loss</option>
                            <option value="strength">Strength Improvement</option>
                            <option value="endurance">Endurance Improvement</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Target Value</label>
                            <input type="number" name="target_value" class="form-control" step="0.1" placeholder="e.g., 70.5">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Target Date</label>
                            <input type="date" name="target_date" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe your goal in detail..." required></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Set Goal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Upload Photo Modal -->
    <div class="modal" id="photoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Progress Photo</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="upload-progress-photo.php" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="form-label">Photo Date</label>
                        <input type="date" name="photo_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Select Photo</label>
                        <input type="file" name="photo_file" class="form-control" accept="image/*" required>
                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.5rem;">
                            Max file size: 5MB. Recommended dimensions: 800x600 pixels.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Photo Type</label>
                        <select name="photo_type" class="form-control">
                            <option value="front">Front View</option>
                            <option value="side">Side View</option>
                            <option value="back">Back View</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="photo_notes" class="form-control" rows="3" placeholder="Add any notes about this photo..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Photo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Photo Modal -->
    <div class="modal" id="viewPhotoModal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3>Progress Photo</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center;">
                    <img id="viewPhotoImage" src="/placeholder.svg" alt="Progress Photo" style="max-width: 100%; height: auto; border-radius: var(--border-radius);">
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
        
        // Modal functionality
        const modals = document.querySelectorAll('.modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        // Close modal function
        function closeModal(modal) {
            modal.classList.remove('show');
        }
        
        // Close modal events
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const modal = this.closest('.modal');
                closeModal(modal);
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            modals.forEach(modal => {
                if (e.target === modal) {
                    closeModal(modal);
                }
            });
        });
        
        // Open modals
        function openMessageModal() {
            document.getElementById('messageModal').classList.add('show');
        }
        
        function openGoalModal() {
            document.getElementById('goalModal').classList.add('show');
        }
        
        function openPhotoModal() {
            document.getElementById('photoModal').classList.add('show');
        }
        
        function viewPhoto(url) {
            document.getElementById('viewPhotoImage').src = url;
            document.getElementById('viewPhotoModal').classList.add('show');
        }
        
        // Chart tabs functionality
        document.querySelectorAll('.chart-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Update active tab
                document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Show corresponding chart
                const chartType = this.getAttribute('data-chart');
                document.querySelectorAll('.chart-container').forEach(container => {
                    container.style.display = 'none';
                });
                document.getElementById(chartType + '-chart').style.display = 'block';
            });
        });
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            }, 5000);
        });
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            const chartLabels = <?php echo json_encode($chartLabels); ?>;
            
            // Body Composition Chart
            const bodyCompCtx = document.getElementById('bodyCompositionChart');
            if (bodyCompCtx && chartLabels && chartLabels.length > 0) {
                new Chart(bodyCompCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Weight (kg)',
                                data: <?php echo json_encode($weightData); ?>,
                                backgroundColor: 'rgba(255, 107, 53, 0.1)',
                                borderColor: '#ff6b35',
                                borderWidth: 3,
                                tension: 0.4,
                                fill: true,
                                pointBackgroundColor: '#ff6b35',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            },
                            {
                                label: 'Body Fat (%)',
                                data: <?php echo json_encode($bodyFatData); ?>,
                                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                borderColor: '#ef4444',
                                borderWidth: 3,
                                tension: 0.4,
                                fill: true,
                                pointBackgroundColor: '#ef4444',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            },
                            {
                                label: 'Muscle Mass (kg)',
                                data: <?php echo json_encode($muscleMassData); ?>,
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderColor: '#10b981',
                                borderWidth: 3,
                                tension: 0.4,
                                fill: true,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 6
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
                                    padding: 20,
                                    font: {
                                        size: 12,
                                        weight: '500'
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                borderColor: '#ff6b35',
                                borderWidth: 1,
                                cornerRadius: 8,
                                displayColors: true
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                }
                            }
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        }
                    }
                });
            }
            
            // Measurements Chart
            const measurementsCtx = document.getElementById('measurementsChart');
            if (measurementsCtx && chartLabels && chartLabels.length > 0) {
                new Chart(measurementsCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Chest (cm)',
                                data: <?php echo json_encode($chestData); ?>,
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderColor: '#3b82f6',
                                borderWidth: 2,
                                tension: 0.4,
                                pointBackgroundColor: '#3b82f6',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 5
                            },
                            {
                                label: 'Waist (cm)',
                                data: <?php echo json_encode($waistData); ?>,
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                borderColor: '#f59e0b',
                                borderWidth: 2,
                                tension: 0.4,
                                pointBackgroundColor: '#f59e0b',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 5
                            },
                            {
                                label: 'Hips (cm)',
                                data: <?php echo json_encode($hipsData); ?>,
                                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                                borderColor: '#8b5cf6',
                                borderWidth: 2,
                                tension: 0.4,
                                pointBackgroundColor: '#8b5cf6',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 5
                            },
                            {
                                label: 'Arms (cm)',
                                data: <?php echo json_encode($armsData); ?>,
                                backgroundColor: 'rgba(6, 182, 212, 0.1)',
                                borderColor: '#06b6d4',
                                borderWidth: 2,
                                tension: 0.4,
                                pointBackgroundColor: '#06b6d4',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 5
                            },
                            {
                                label: 'Thighs (cm)',
                                data: <?php echo json_encode($thighsData); ?>,
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderColor: '#10b981',
                                borderWidth: 2,
                                tension: 0.4,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 5
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
                                    padding: 20,
                                    font: {
                                        size: 12,
                                        weight: '500'
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#ffffff',
                                bodyColor: '#ffffff',
                                borderColor: '#ff6b35',
                                borderWidth: 1,
                                cornerRadius: 8,
                                displayColors: true
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                }
                            }
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        }
                    }
                });
            }
        });
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = this.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.style.borderColor = '#ef4444';
                        isValid = false;
                    } else {
                        field.style.borderColor = '';
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
        });
        
        // Enhanced photo upload preview
        const photoInput = document.querySelector('input[name="photo_file"]');
        if (photoInput) {
            photoInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Create preview if doesn't exist
                        let preview = document.getElementById('photo-preview');
                        if (!preview) {
                            preview = document.createElement('div');
                            preview.id = 'photo-preview';
                            preview.style.marginTop = '1rem';
                            preview.innerHTML = '<img style="max-width: 200px; border-radius: 8px;" />';
                            photoInput.parentNode.appendChild(preview);
                        }
                        preview.querySelector('img').src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    </script>
</body>
</html>