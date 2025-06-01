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

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$trainerFilter = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;

// Build query based on filters
$query = "
    SELECT ts.id, ts.title, ts.description, ts.start_time, ts.end_time, ts.status,
           u.id as trainer_id, u.name as trainer_name, u.profile_image as trainer_image,
           u.specialization, u.rating
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ?
";

$queryParams = [$userId];

// Apply filters
if (!empty($statusFilter)) {
    $query .= " AND ts.status = ?";
    $queryParams[] = $statusFilter;
}

if (!empty($dateFilter)) {
    $query .= " AND DATE(ts.start_time) = ?";
    $queryParams[] = $dateFilter;
}

if ($trainerFilter > 0) {
    $query .= " AND ts.trainer_id = ?";
    $queryParams[] = $trainerFilter;
}

$query .= " ORDER BY ts.start_time DESC";

// Execute query
$stmt = $conn->prepare($query);
$stmt->execute($queryParams);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available trainers for booking
$availableTrainersStmt = $conn->prepare("
    SELECT u.id, u.name, u.profile_image, u.specialization, u.rating, u.hourly_rate
    FROM users u 
    WHERE u.role = 'Trainer' AND u.status = 'active'
    ORDER BY u.rating DESC, u.name ASC
");
$availableTrainersStmt->execute();
$availableTrainers = $availableTrainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming appointments
$upcomingStmt = $conn->prepare("
    SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
           u.id as trainer_id, u.name as trainer_name, u.profile_image as trainer_image
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ? 
    AND ts.start_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    AND ts.status IN ('scheduled', 'confirmed')
    ORDER BY ts.start_time ASC
    LIMIT 3
");
$upcomingStmt->execute([$userId]);
$upcomingAppointments = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle appointment booking
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['book_appointment'])) {
        $trainerId = $_POST['trainer_id'];
        $sessionType = $_POST['session_type'];
        $appointmentDate = $_POST['appointment_date'];
        $appointmentTime = $_POST['appointment_time'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            $bookingStmt = $conn->prepare("
                INSERT INTO trainer_schedule (trainer_id, member_id, title, description, start_time, end_time, status)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
            ");
            
            $startDateTime = $appointmentDate . ' ' . $appointmentTime;
            $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +1 hour'));
            
            $bookingStmt->execute([
                $trainerId,
                $userId,
                $sessionType,
                $notes,
                $startDateTime,
                $endDateTime
            ]);
            
            $message = 'Appointment booked successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error booking appointment: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['send_message'])) {
        $trainerId = $_POST['trainer_id'];
        $messageText = $_POST['message'];
        
        try {
            // Create messages table if not exists
            $conn->exec("
                CREATE TABLE IF NOT EXISTS trainer_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_id INT NOT NULL,
                    receiver_id INT NOT NULL,
                    message TEXT NOT NULL,
                    is_read TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (sender_id),
                    INDEX (receiver_id)
                )
            ");
            
            $messageStmt = $conn->prepare("
                INSERT INTO trainer_messages (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");
            $messageStmt->execute([$userId, $trainerId, $messageText]);
            
            $message = 'Message sent successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error sending message: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['cancel_appointment'])) {
        $appointmentId = $_POST['appointment_id'];
        
        try {
            $updateStmt = $conn->prepare("
                UPDATE trainer_schedule 
                SET status = 'cancelled' 
                WHERE id = ? AND member_id = ?
            ");
            $updateStmt->execute([$appointmentId, $userId]);
            
            $message = 'Appointment cancelled successfully.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error cancelling appointment: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Format functions
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function formatTime($time) {
    return date('g:i A', strtotime($time));
}

function getDayName($date) {
    return date('l', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - EliteFit Gym</title>
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
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stat-card .icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-card.upcoming .icon {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
        }

        .stat-card.completed .icon {
            background: linear-gradient(135deg, #059669, #10b981);
            color: white;
        }

        .stat-card.cancelled .icon {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: white;
        }

        .stat-card.total .icon {
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
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

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .action-card h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .action-card i {
            color: var(--primary-orange);
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

        /* Appointments List */
        .appointments-grid {
            display: grid;
            gap: 1.5rem;
        }

        .appointment-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
            position: relative;
        }

        .appointment-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .appointment-date {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .appointment-time {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-orange);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-badge.scheduled {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .status-badge.confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .status-badge.completed {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .status-badge.cancelled {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .trainer-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1rem 0;
        }

        .trainer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .trainer-avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-orange);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .trainer-details h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .trainer-specialization {
            font-size: 0.875rem;
            color: var(--text-secondary);
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
            max-width: 500px;
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

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .mobile-menu-toggle {
            display: none;
        }

        /* Trainer Selection */
        .trainer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .trainer-card {
            background: var(--bg-tertiary);
            border-radius: var(--border-radius);
            padding: 1rem;
            border: 2px solid transparent;
            cursor: pointer;
            transition: var(--transition);
        }

        .trainer-card:hover,
        .trainer-card.selected {
            border-color: var(--primary-orange);
            background: rgba(255, 107, 53, 0.05);
        }

        .trainer-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .trainer-rating {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: #fbbf24;
            font-size: 0.875rem;
        }

        .trainer-rate {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
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
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Appointments</h1>
                    <p>Manage your training sessions and book new appointments</p>
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
                    <button class="btn btn-primary" onclick="openBookingModal()">
                        <i class="fas fa-plus"></i> Book Session
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
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <?php
                    $totalAppointments = count($appointments);
                    $upcomingCount = count($upcomingAppointments);
                    $completedCount = count(array_filter($appointments, function($a) { return $a['status'] === 'completed'; }));
                    $cancelledCount = count(array_filter($appointments, function($a) { return $a['status'] === 'cancelled'; }));
                    ?>
                    <div class="stat-card upcoming">
                        <div class="icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-value"><?php echo $upcomingCount; ?></div>
                        <div class="stat-label">Upcoming Sessions</div>
                    </div>
                    <div class="stat-card completed">
                        <div class="icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-value"><?php echo $completedCount; ?></div>
                        <div class="stat-label">Completed Sessions</div>
                    </div>
                    <div class="stat-card cancelled">
                        <div class="icon"><i class="fas fa-times-circle"></i></div>
                        <div class="stat-value"><?php echo $cancelledCount; ?></div>
                        <div class="stat-label">Cancelled Sessions</div>
                    </div>
                    <div class="stat-card total">
                        <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="stat-value"><?php echo $totalAppointments; ?></div>
                        <div class="stat-label">Total Appointments</div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <div class="action-card">
                        <h3><i class="fas fa-calendar-plus"></i> Quick Book</h3>
                        <p>Book a session with your preferred trainer for today or tomorrow.</p>
                        <button class="btn btn-primary" onclick="openBookingModal()" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Book Now
                        </button>
                    </div>
                    <div class="action-card">
                        <h3><i class="fas fa-comments"></i> Message Trainers</h3>
                        <p>Send a message to any of our available trainers.</p>
                        <button class="btn btn-outline" onclick="openMessageModal()" style="margin-top: 1rem;">
                            <i class="fas fa-envelope"></i> Send Message
                        </button>
                    </div>
                </div>
                
                <!-- Upcoming Appointments -->
                <?php if (!empty($upcomingAppointments)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-day"></i> Upcoming Sessions</h2>
                    </div>
                    <div class="card-content">
                        <div class="appointments-grid">
                            <?php foreach ($upcomingAppointments as $appointment): ?>
                                <div class="appointment-card">
                                    <div class="appointment-header">
                                        <div>
                                            <div class="appointment-date"><?php echo formatDate($appointment['start_time']); ?></div>
                                            <div class="appointment-time"><?php echo formatTime($appointment['start_time']); ?> - <?php echo formatTime($appointment['end_time']); ?></div>
                                        </div>
                                        <span class="status-badge <?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <h4><?php echo htmlspecialchars($appointment['title']); ?></h4>
                                    
                                    <div class="trainer-info">
                                        <?php if (!empty($appointment['trainer_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($appointment['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                        <?php else: ?>
                                            <div class="trainer-avatar-placeholder">
                                                <?php echo strtoupper(substr($appointment['trainer_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="trainer-details">
                                            <h4><?php echo htmlspecialchars($appointment['trainer_name']); ?></h4>
                                        </div>
                                    </div>
                                    
                                    <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                        <button class="btn btn-sm btn-danger" onclick="confirmCancel(<?php echo $appointment['id']; ?>, '<?php echo formatDate($appointment['start_time']); ?>', '<?php echo formatTime($appointment['start_time']); ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="openMessageModal(<?php echo $appointment['trainer_id']; ?>)">
                                            <i class="fas fa-envelope"></i> Message
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- All Appointments -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-alt"></i> All Appointments</h2>
                    </div>
                    <div class="card-content">
                        <!-- Filters -->
                        <form method="get" class="form-row" style="margin-bottom: 2rem;">
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Statuses</option>
                                    <option value="scheduled" <?php echo $statusFilter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control" value="<?php echo $dateFilter; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Trainer</label>
                                <select name="trainer_id" class="form-control">
                                    <option value="0">All Trainers</option>
                                    <?php foreach ($availableTrainers as $trainer): ?>
                                        <option value="<?php echo $trainer['id']; ?>" <?php echo $trainerFilter === $trainer['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($trainer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                            </div>
                        </form>
                        
                        <?php if (empty($appointments)): ?>
                            <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="fas fa-calendar-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <h3>No appointments found</h3>
                                <p>You don't have any appointments matching your filters.</p>
                                <button class="btn btn-primary" onclick="openBookingModal()" style="margin-top: 1rem;">
                                    <i class="fas fa-plus"></i> Book Your First Session
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="appointments-grid">
                                <?php foreach ($appointments as $appointment): ?>
                                    <div class="appointment-card">
                                        <div class="appointment-header">
                                            <div>
                                                <div class="appointment-date"><?php echo formatDate($appointment['start_time']); ?></div>
                                                <div class="appointment-time"><?php echo formatTime($appointment['start_time']); ?> - <?php echo formatTime($appointment['end_time']); ?></div>
                                            </div>
                                            <span class="status-badge <?php echo $appointment['status']; ?>">
                                                <?php echo ucfirst($appointment['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <h4><?php echo htmlspecialchars($appointment['title']); ?></h4>
                                        
                                        <?php if (!empty($appointment['description'])): ?>
                                            <p style="color: var(--text-secondary); margin: 0.5rem 0;"><?php echo htmlspecialchars($appointment['description']); ?></p>
                                        <?php endif; ?>
                                        
                                        <div class="trainer-info">
                                            <?php if (!empty($appointment['trainer_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($appointment['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                            <?php else: ?>
                                                <div class="trainer-avatar-placeholder">
                                                    <?php echo strtoupper(substr($appointment['trainer_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="trainer-details">
                                                <h4><?php echo htmlspecialchars($appointment['trainer_name']); ?></h4>
                                                <?php if (!empty($appointment['specialization'])): ?>
                                                    <div class="trainer-specialization"><?php echo htmlspecialchars($appointment['specialization']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php 
                                            $appointmentTime = new DateTime($appointment['start_time']);
                                            $now = new DateTime();
                                            $canCancel = ($appointmentTime > $now) && ($appointment['status'] !== 'cancelled');
                                        ?>
                                        
                                        <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                                            <?php if ($canCancel): ?>
                                                <button class="btn btn-sm btn-danger" onclick="confirmCancel(<?php echo $appointment['id']; ?>, '<?php echo formatDate($appointment['start_time']); ?>', '<?php echo formatTime($appointment['start_time']); ?>')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-outline" onclick="openMessageModal(<?php echo $appointment['trainer_id']; ?>)">
                                                <i class="fas fa-envelope"></i> Message
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
    </div>
    
    <!-- Booking Modal -->
    <div class="modal" id="bookingModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Book New Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post">
                    <input type="hidden" name="book_appointment" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Select Trainer</label>
                        <div class="trainer-grid">
                            <?php foreach ($availableTrainers as $trainer): ?>
                                <div class="trainer-card" onclick="selectTrainer(<?php echo $trainer['id']; ?>)">
                                    <div class="trainer-card-header">
                                        <?php if (!empty($trainer['profile_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($trainer['profile_image']); ?>" alt="Trainer" class="trainer-avatar">
                                        <?php else: ?>
                                            <div class="trainer-avatar-placeholder">
                                                <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <h4><?php echo htmlspecialchars($trainer['name']); ?></h4>
                                            <?php if (!empty($trainer['specialization'])): ?>
                                                <div class="trainer-specialization"><?php echo htmlspecialchars($trainer['specialization']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($trainer['rating'])): ?>
                                        <div class="trainer-rating">
                                            <i class="fas fa-star"></i>
                                            <span><?php echo number_format($trainer['rating'], 1); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($trainer['hourly_rate'])): ?>
                                        <div class="trainer-rate">$<?php echo $trainer['hourly_rate']; ?>/hour</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="trainer_id" id="selected_trainer" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Session Type</label>
                            <select name="session_type" class="form-control" required>
                                <option value="">Select Session Type</option>
                                <option value="Personal Training">Personal Training</option>
                                <option value="Strength Training">Strength Training</option>
                                <option value="Cardio Session">Cardio Session</option>
                                <option value="Flexibility Training">Flexibility Training</option>
                                <option value="Nutrition Consultation">Nutrition Consultation</option>
                                <option value="Fitness Assessment">Fitness Assessment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="appointment_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Time</label>
                        <select name="appointment_time" class="form-control" required>
                            <option value="">Select Time</option>
                            <option value="06:00:00">6:00 AM</option>
                            <option value="07:00:00">7:00 AM</option>
                            <option value="08:00:00">8:00 AM</option>
                            <option value="09:00:00">9:00 AM</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="11:00:00">11:00 AM</option>
                            <option value="12:00:00">12:00 PM</option>
                            <option value="13:00:00">1:00 PM</option>
                            <option value="14:00:00">2:00 PM</option>
                            <option value="15:00:00">3:00 PM</option>
                            <option value="16:00:00">4:00 PM</option>
                            <option value="17:00:00">5:00 PM</option>
                            <option value="18:00:00">6:00 PM</option>
                            <option value="19:00:00">7:00 PM</option>
                            <option value="20:00:00">8:00 PM</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Any specific requirements or goals for this session..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Book Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Message Modal -->
    <div class="modal" id="messageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Message to Trainer</h3>
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
    
    <!-- Cancel Modal -->
    <div class="modal" id="cancelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Appointment</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem;"></i>
                    <p>Are you sure you want to cancel your appointment on <strong id="cancel-date"></strong> at <strong id="cancel-time"></strong>?</p>
                    <p style="color: var(--text-secondary); font-size: 0.875rem;">This action cannot be undone.</p>
                </div>
                
                <form method="post">
                    <input type="hidden" name="cancel_appointment" value="1">
                    <input type="hidden" id="appointment-id" name="appointment_id" value="">
                    
                    <div style="display: flex; gap: 1rem;">
                        <button type="button" class="btn btn-outline close-modal">Keep Appointment</button>
                        <button type="submit" class="btn btn-danger">Yes, Cancel</button>
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
        
        // Open booking modal
        function openBookingModal() {
            document.getElementById('bookingModal').classList.add('show');
        }
        
        // Open message modal
        function openMessageModal(trainerId = null) {
            const modal = document.getElementById('messageModal');
            if (trainerId) {
                const trainerSelect = modal.querySelector('select[name="trainer_id"]');
                trainerSelect.value = trainerId;
            }
            modal.classList.add('show');
        }
        
        // Select trainer for booking
        function selectTrainer(trainerId) {
            // Remove previous selection
            document.querySelectorAll('.trainer-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            event.currentTarget.classList.add('selected');
            
            // Set hidden input value
            document.getElementById('selected_trainer').value = trainerId;
        }
        
        // Confirm cancel appointment
        function confirmCancel(appointmentId, date, time) {
            document.getElementById('appointment-id').value = appointmentId;
            document.getElementById('cancel-date').textContent = date;
            document.getElementById('cancel-time').textContent = time;
            document.getElementById('cancelModal').classList.add('show');
        }
        
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
    </script>
</body>
</html>