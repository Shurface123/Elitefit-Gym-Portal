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
                break_duration INT DEFAULT 15,
                max_daily_sessions INT DEFAULT 12,
                booking_advance_days INT DEFAULT 30,
                cancellation_hours INT DEFAULT 24,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id)
            )
        ");
        
        $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id, theme_preference) VALUES (?, 'dark')");
        $stmt->execute([$userId]);
    }

    $stmt = $conn->prepare("SELECT * FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($settings && isset($settings['theme_preference'])) {
        $theme = $settings['theme_preference'];
    }
} catch (PDOException $e) {
    // Use default theme on error
}

// Enhanced schedule table
try {
    $scheduleTableExists = $conn->query("SHOW TABLES LIKE 'trainer_schedule'")->rowCount() > 0;

    if (!$scheduleTableExists) {
        $conn->exec("
            CREATE TABLE trainer_schedule (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NOT NULL,
                title VARCHAR(100) NOT NULL,
                description TEXT,
                session_type VARCHAR(50) DEFAULT 'personal_training',
                location VARCHAR(100) DEFAULT 'gym',
                start_time DATETIME NOT NULL,
                end_time DATETIME NOT NULL,
                status VARCHAR(20) DEFAULT 'scheduled',
                priority VARCHAR(20) DEFAULT 'normal',
                recurring_type VARCHAR(20) DEFAULT 'none',
                recurring_end_date DATE NULL,
                session_notes TEXT,
                preparation_notes TEXT,
                equipment_needed TEXT,
                estimated_calories INT DEFAULT 0,
                actual_duration INT DEFAULT 0,
                member_feedback TEXT,
                trainer_rating DECIMAL(2,1) DEFAULT 0.0,
                member_rating DECIMAL(2,1) DEFAULT 0.0,
                payment_status VARCHAR(20) DEFAULT 'pending',
                payment_amount DECIMAL(10,2) DEFAULT 0.00,
                reminder_sent TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (trainer_id),
                INDEX (member_id),
                INDEX (start_time),
                INDEX (status),
                INDEX (session_type)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error
}

// Get current date and view parameters
$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$viewType = isset($_GET['view']) ? $_GET['view'] : 'day';
$memberFilter = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

$selectedDate = new DateTime($currentDate);
$formattedDate = $selectedDate->format('l, F j, Y');

// Get all active gym members (actual registered members)
$members = [];
try {
    // Fetch all active members from the users table with role 'Member'
    $memberQuery = "
        SELECT u.id, u.name, u.email, u.profile_image, u.phone, u.date_of_birth, u.created_at,
               COUNT(ts.id) as total_sessions,
               COUNT(CASE WHEN ts.status = 'completed' THEN 1 END) as completed_sessions,
               MAX(ts.created_at) as last_session_date
        FROM users u
        LEFT JOIN trainer_schedule ts ON u.id = ts.member_id AND ts.trainer_id = ?
        WHERE u.role = 'Member' AND u.status = 'active'
        GROUP BY u.id, u.name, u.email, u.profile_image, u.phone, u.date_of_birth, u.created_at
        ORDER BY u.name ASC
    ";
    
    $memberStmt = $conn->prepare($memberQuery);
    $memberStmt->execute([$userId]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
    error_log("Error fetching members: " . $e->getMessage());
}

// Get schedule based on view type
$schedule = [];
$dateRange = [];

try {
    if ($conn->query("SHOW TABLES LIKE 'trainer_schedule'")->rowCount() > 0) {
        $scheduleQuery = "
            SELECT ts.*, u.name as member_name, u.profile_image, u.phone as member_phone, u.email as member_email,
                   wp.title as workout_plan_title
            FROM trainer_schedule ts
            LEFT JOIN users u ON ts.member_id = u.id
            LEFT JOIN workout_plans wp ON ts.member_id = wp.member_id AND wp.trainer_id = ts.trainer_id
            WHERE ts.trainer_id = ?
        ";
        
        $scheduleParams = [$userId];
        
        // Date filtering based on view type
        if ($viewType === 'day') {
            $scheduleQuery .= " AND DATE(ts.start_time) = ?";
            $scheduleParams[] = $currentDate;
            $dateRange = [$currentDate];
        } elseif ($viewType === 'week') {
            $weekStart = (new DateTime($currentDate))->modify('monday this week')->format('Y-m-d');
            $weekEnd = (new DateTime($currentDate))->modify('sunday this week')->format('Y-m-d');
            $scheduleQuery .= " AND DATE(ts.start_time) BETWEEN ? AND ?";
            $scheduleParams[] = $weekStart;
            $scheduleParams[] = $weekEnd;
            
            // Generate week dates
            for ($i = 0; $i < 7; $i++) {
                $date = (new DateTime($weekStart))->modify("+$i days")->format('Y-m-d');
                $dateRange[] = $date;
            }
        } elseif ($viewType === 'month') {
            $monthStart = (new DateTime($currentDate))->format('Y-m-01');
            $monthEnd = (new DateTime($currentDate))->format('Y-m-t');
            $scheduleQuery .= " AND DATE(ts.start_time) BETWEEN ? AND ?";
            $scheduleParams[] = $monthStart;
            $scheduleParams[] = $monthEnd;
        }
        
        // Additional filters
        if ($memberFilter > 0) {
            $scheduleQuery .= " AND ts.member_id = ?";
            $scheduleParams[] = $memberFilter;
        }
        
        if (!empty($statusFilter)) {
            $scheduleQuery .= " AND ts.status = ?";
            $scheduleParams[] = $statusFilter;
        }
        
        $scheduleQuery .= " ORDER BY ts.start_time ASC";
        
        $scheduleStmt = $conn->prepare($scheduleQuery);
        $scheduleStmt->execute($scheduleParams);
        $schedule = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error
    error_log("Error fetching schedule: " . $e->getMessage());
}

// Enhanced form handling
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_session'])) {
        try {
            $title = trim($_POST['title']);
            $description = trim($_POST['description'] ?? '');
            $sessionType = $_POST['session_type'] ?? 'personal_training';
            $location = trim($_POST['location'] ?? 'gym');
            $memberId = $_POST['member_id']; // Now required
            $date = $_POST['date'];
            $startTime = $_POST['start_time'];
            $endTime = $_POST['end_time'];
            $status = $_POST['status'] ?? 'scheduled';
            $priority = $_POST['priority'] ?? 'normal';
            $recurringType = $_POST['recurring_type'] ?? 'none';
            $recurringEndDate = !empty($_POST['recurring_end_date']) ? $_POST['recurring_end_date'] : null;
            $sessionNotes = trim($_POST['session_notes'] ?? '');
            $preparationNotes = trim($_POST['preparation_notes'] ?? '');
            $equipmentNeeded = trim($_POST['equipment_needed'] ?? '');
            $estimatedCalories = intval($_POST['estimated_calories'] ?? 0);
            $paymentAmount = floatval($_POST['payment_amount'] ?? 0);
            
            $startDateTime = $date . ' ' . $startTime;
            $endDateTime = $date . ' ' . $endTime;
            
            // Enhanced validation
            if (empty($title)) {
                throw new Exception('Session title is required');
            }
            
            if (empty($memberId)) {
                throw new Exception('Please select a member for this session');
            }
            
            // Verify member exists and is active
            $memberCheckStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'Member' AND status = 'active'");
            $memberCheckStmt->execute([$memberId]);
            $memberExists = $memberCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$memberExists) {
                throw new Exception('Selected member is not valid or not active');
            }
            
            if (strtotime($endDateTime) <= strtotime($startDateTime)) {
                throw new Exception('End time must be after start time');
            }
            
            // Check for conflicts
            $conflictQuery = "
                SELECT COUNT(*) as conflicts 
                FROM trainer_schedule 
                WHERE trainer_id = ? 
                AND status NOT IN ('cancelled', 'completed')
                AND (
                    (start_time < ? AND end_time > ?) OR
                    (start_time < ? AND end_time > ?) OR
                    (start_time >= ? AND end_time <= ?)
                )
            ";
            
            $conflictStmt = $conn->prepare($conflictQuery);
            $conflictStmt->execute([
                $userId, 
                $startDateTime, $startDateTime,
                $endDateTime, $endDateTime,
                $startDateTime, $endDateTime
            ]);
            
            $conflicts = $conflictStmt->fetch(PDO::FETCH_ASSOC)['conflicts'];
            
            if ($conflicts > 0) {
                throw new Exception('Time slot conflicts with existing session');
            }
            
            $stmt = $conn->prepare("
                INSERT INTO trainer_schedule 
                (trainer_id, member_id, title, description, session_type, location, start_time, end_time, 
                 status, priority, recurring_type, recurring_end_date, session_notes, preparation_notes, 
                 equipment_needed, estimated_calories, payment_amount) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $memberId, $title, $description, $sessionType, $location, $startDateTime, $endDateTime,
                $status, $priority, $recurringType, $recurringEndDate, $sessionNotes, $preparationNotes,
                $equipmentNeeded, $estimatedCalories, $paymentAmount
            ]);
            
            $sessionId = $conn->lastInsertId();
            
            // Handle recurring sessions
            if ($recurringType !== 'none' && !empty($recurringEndDate)) {
                $currentSessionDate = new DateTime($date);
                $endDate = new DateTime($recurringEndDate);
                
                $interval = match($recurringType) {
                    'daily' => 'P1D',
                    'weekly' => 'P1W',
                    'biweekly' => 'P2W',
                    'monthly' => 'P1M',
                    default => null
                };
                
                if ($interval) {
                    $intervalObj = new DateInterval($interval);
                    
                    while ($currentSessionDate->add($intervalObj) <= $endDate) {
                        $newDate = $currentSessionDate->format('Y-m-d');
                        $newStartDateTime = $newDate . ' ' . $startTime;
                        $newEndDateTime = $newDate . ' ' . $endTime;
                        
                        $stmt->execute([
                            $userId, $memberId, $title, $description, $sessionType, $location, 
                            $newStartDateTime, $newEndDateTime, $status, $priority, $recurringType, 
                            $recurringEndDate, $sessionNotes, $preparationNotes, $equipmentNeeded, 
                            $estimatedCalories, $paymentAmount
                        ]);
                    }
                }
            }
            
            $message = 'Session(s) created successfully for ' . $memberExists['name'] . '!';
            $messageType = 'success';
            
            header("Location: schedule.php?date=$date&view=$viewType&created=1");
            exit;
        } catch (Exception $e) {
            $message = 'Error creating session: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    // Update session status
    if (isset($_POST['update_status'])) {
        try {
            $sessionId = intval($_POST['session_id']);
            $newStatus = $_POST['new_status'];
            $actualDuration = intval($_POST['actual_duration'] ?? 0);
            $memberFeedback = trim($_POST['member_feedback'] ?? '');
            $trainerRating = floatval($_POST['trainer_rating'] ?? 0);
            
            $stmt = $conn->prepare("
                UPDATE trainer_schedule 
                SET status = ?, actual_duration = ?, member_feedback = ?, trainer_rating = ?, updated_at = NOW()
                WHERE id = ? AND trainer_id = ?
            ");
            
            $stmt->execute([$newStatus, $actualDuration, $memberFeedback, $trainerRating, $sessionId, $userId]);
            
            $message = 'Session updated successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error updating session: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    // Delete session
    if (isset($_POST['delete_session'])) {
        try {
            $sessionId = intval($_POST['session_id']);
            
            $stmt = $conn->prepare("DELETE FROM trainer_schedule WHERE id = ? AND trainer_id = ?");
            $stmt->execute([$sessionId, $userId]);
            
            $message = 'Session deleted successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error deleting session: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Helper functions
function formatTime($time) {
    $dateTime = new DateTime($time);
    return $dateTime->format('g:i A');
}

function formatDuration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    if ($hours > 0) {
        return $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
    }
    return $mins . 'm';
}

function getStatusClass($status) {
    return match($status) {
        'scheduled' => 'warning',
        'confirmed' => 'info',
        'in_progress' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
        'no_show' => 'secondary',
        default => 'secondary'
    };
}

function getPriorityClass($priority) {
    return match($priority) {
        'low' => 'success',
        'normal' => 'info',
        'high' => 'warning',
        'urgent' => 'danger',
        default => 'info'
    };
}

function getSessionTypeIcon($type) {
    return match($type) {
        'personal_training' => 'fas fa-user',
        'group_training' => 'fas fa-users',
        'consultation' => 'fas fa-comments',
        'assessment' => 'fas fa-clipboard-check',
        'nutrition' => 'fas fa-apple-alt',
        'rehabilitation' => 'fas fa-medkit',
        default => 'fas fa-dumbbell'
    };
}

// Get time slots for the day view
$timeSlots = [];
$workingHoursStart = $settings['working_hours_start'] ?? '06:00:00';
$workingHoursEnd = $settings['working_hours_end'] ?? '22:00:00';

$startHour = intval(substr($workingHoursStart, 0, 2));
$endHour = intval(substr($workingHoursEnd, 0, 2));

for ($hour = $startHour; $hour < $endHour; $hour++) {
    for ($minute = 0; $minute < 60; $minute += 30) {
        $time = sprintf('%02d:%02d:00', $hour, $minute);
        $timeSlots[] = $time;
    }
}

// Navigation dates
$prevDate = (new DateTime($currentDate))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTime($currentDate))->modify('+1 day')->format('Y-m-d');

if ($viewType === 'week') {
    $prevDate = (new DateTime($currentDate))->modify('-1 week')->format('Y-m-d');
    $nextDate = (new DateTime($currentDate))->modify('+1 week')->format('Y-m-d');
} elseif ($viewType === 'month') {
    $prevDate = (new DateTime($currentDate))->modify('-1 month')->format('Y-m-d');
    $nextDate = (new DateTime($currentDate))->modify('+1 month')->format('Y-m-d');
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Schedule Management - EliteFit Gym</title>
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

        /* Schedule Controls */
        .schedule-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .date-navigation {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .date-navigation h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--on-surface);
            margin: 0;
        }

        .view-toggle {
            display: flex;
            background: var(--surface-variant);
            border-radius: 8px;
            padding: 0.25rem;
            gap: 0.25rem;
        }

        .view-toggle button {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            color: var(--on-surface-variant);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .view-toggle button.active {
            background: var(--primary);
            color: white;
        }

        .view-toggle button:hover:not(.active) {
            background: var(--surface);
            color: var(--on-surface);
        }

        .schedule-filters {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
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
            min-width: 150px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-control.required {
            border-color: var(--primary);
            background: rgba(255, 107, 53, 0.05);
        }

        /* Enhanced Schedule Views */
        .schedule-view {
            background: var(--surface);
            border-radius: 12px;
            overflow: hidden;
        }

        /* Day View */
        .day-schedule {
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 1px;
            background: var(--border);
        }

        .time-slot {
            background: var(--surface);
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            min-height: 80px;
        }

        .time-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--on-surface-variant);
            text-align: center;
            padding: 0.5rem;
        }

        .time-content {
            flex: 1;
            position: relative;
        }

        .session-block {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 8px;
            padding: 1rem;
            margin: 0.25rem 0;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .session-block:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .session-block::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-light);
        }

        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .session-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .session-time {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .session-type {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            margin-bottom: 0.5rem;
        }

        .session-member {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
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
            background: rgba(255, 255, 255, 0.3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .session-status {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.scheduled { background: rgba(255, 152, 0, 0.2); color: #ff9800; }
        .status-badge.confirmed { background: rgba(33, 150, 243, 0.2); color: #2196f3; }
        .status-badge.in_progress { background: rgba(255, 107, 53, 0.2); color: #ff6b35; }
        .status-badge.completed { background: rgba(76, 175, 80, 0.2); color: #4caf50; }
        .status-badge.cancelled { background: rgba(244, 67, 54, 0.2); color: #f44336; }
        .status-badge.no_show { background: rgba(158, 158, 158, 0.2); color: #9e9e9e; }

        .session-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .session-block:hover .session-actions {
            opacity: 1;
        }

        .session-action-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }

        .session-action-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .empty-slot {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--on-surface-variant);
            font-size: 0.875rem;
        }

        .add-session-btn {
            background: var(--surface-variant);
            border: 1px dashed var(--border);
            color: var(--on-surface-variant);
            padding: 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            width: 100%;
        }

        .add-session-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Week View */
        .week-schedule {
            display: grid;
            grid-template-columns: 100px repeat(7, 1fr);
            gap: 1px;
            background: var(--border);
        }

        .week-header {
            background: var(--surface-variant);
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            color: var(--on-surface);
        }

        .week-day {
            background: var(--surface);
            min-height: 120px;
            padding: 0.5rem;
        }

        .week-day-sessions {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .week-session {
            background: var(--primary);
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .week-session:hover {
            background: var(--primary-dark);
            transform: scale(1.02);
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--on-surface-variant);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
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

        .form-group label.required::after {
            content: ' *';
            color: var(--error);
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

            .schedule-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .date-navigation {
                justify-content: center;
            }

            .schedule-filters {
                justify-content: center;
            }

            .day-schedule {
                grid-template-columns: 80px 1fr;
            }

            .week-schedule {
                grid-template-columns: 80px repeat(7, 1fr);
                font-size: 0.8rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Loading States */
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

        /* Advanced Features */
        .priority-indicator {
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .priority-low { background: var(--success); }
        .priority-normal { background: var(--info); }
        .priority-high { background: var(--warning); }
        .priority-urgent { background: var(--error); }

        .session-metrics {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .metric {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .recurring-indicator {
            position: absolute;
            top: 0.5rem;
            left: 0.5rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
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
                    <li><a href="workout-plans.php"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
                    <li><a href="schedule.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
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
                    <h1><i class="fas fa-calendar-alt" style="color: var(--primary);"></i> Advanced Schedule Management</h1>
                    <p>Manage training sessions, appointments, and client schedules efficiently</p>
                </div>
                <div class="header-actions">
                    <div class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-moon" id="themeIcon"></i>
                        <span id="themeText">Dark</span>
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addSessionModal')">
                        <i class="fas fa-plus"></i> New Session
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
            
            <!-- Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?php echo count(array_filter($schedule, fn($s) => $s['status'] === 'scheduled')); ?></div>
                    <div class="stat-label">Scheduled Sessions</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo count(array_filter($schedule, fn($s) => $s['status'] === 'confirmed')); ?></div>
                    <div class="stat-label">Confirmed Sessions</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo count(array_filter($schedule, fn($s) => $s['status'] === 'completed')); ?></div>
                    <div class="stat-label">Completed Today</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo count($members); ?></div>
                    <div class="stat-label">Available Members</div>
                </div>
            </div>
            
            <!-- Enhanced Schedule Controls -->
            <div class="card">
                <div class="card-content">
                    <div class="schedule-controls">
                        <div class="date-navigation">
                            <a href="?date=<?php echo $prevDate; ?>&view=<?php echo $viewType; ?><?php echo $memberFilter ? '&member_id='.$memberFilter : ''; ?><?php echo $statusFilter ? '&status='.$statusFilter : ''; ?>" class="btn btn-sm btn-outline">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                            <h2><?php echo $formattedDate; ?></h2>
                            <a href="?date=<?php echo $nextDate; ?>&view=<?php echo $viewType; ?><?php echo $memberFilter ? '&member_id='.$memberFilter : ''; ?><?php echo $statusFilter ? '&status='.$statusFilter : ''; ?>" class="btn btn-sm btn-outline">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        
                        <div class="view-toggle">
                            <button class="<?php echo $viewType === 'day' ? 'active' : ''; ?>" onclick="changeView('day')">
                                <i class="fas fa-calendar-day"></i> Day
                            </button>
                            <button class="<?php echo $viewType === 'week' ? 'active' : ''; ?>" onclick="changeView('week')">
                                <i class="fas fa-calendar-week"></i> Week
                            </button>
                            <button class="<?php echo $viewType === 'month' ? 'active' : ''; ?>" onclick="changeView('month')">
                                <i class="fas fa-calendar"></i> Month
                            </button>
                        </div>
                        
                        <div class="schedule-filters">
                            <div class="filter-group">
                                <label for="date-picker">Go to Date:</label>
                                <input type="date" id="date-picker" class="form-control" value="<?php echo $currentDate; ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="member-filter">Member:
                                    <span class="member-count"><?php echo count($members); ?> available</span>
                                </label>
                                <select id="member-filter" class="form-control">
                                    <option value="0">All Members</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($member['name']); ?>
                                            <?php if ($member['total_sessions'] > 0): ?>
                                                (<?php echo $member['total_sessions']; ?> sessions)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="status-filter">Status:</label>
                                <select id="status-filter" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="scheduled" <?php echo $statusFilter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="no_show" <?php echo $statusFilter === 'no_show' ? 'selected' : ''; ?>>No Show</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Enhanced Schedule View -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i class="fas fa-calendar-alt"></i> 
                        <?php echo ucfirst($viewType); ?> Schedule
                    </h2>
                    <div style="display: flex; gap: 1rem;">
                        <button class="btn btn-sm btn-outline" onclick="exportSchedule()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="openModal('addSessionModal')">
                            <i class="fas fa-plus"></i> Add Session
                        </button>
                    </div>
                </div>
                <div class="card-content" style="padding: 0;">
                    <div class="schedule-view">
                        <?php if ($viewType === 'day'): ?>
                            <!-- Day View -->
                            <div class="day-schedule">
                                <?php foreach ($timeSlots as $timeSlot): ?>
                                    <div class="time-slot">
                                        <div class="time-label">
                                            <?php echo date('g:i A', strtotime($timeSlot)); ?>
                                        </div>
                                    </div>
                                    <div class="time-slot">
                                        <div class="time-content">
                                            <?php
                                            $slotSessions = array_filter($schedule, function($session) use ($timeSlot, $currentDate) {
                                                $sessionTime = date('H:i:s', strtotime($session['start_time']));
                                                $sessionDate = date('Y-m-d', strtotime($session['start_time']));
                                                return $sessionDate === $currentDate && $sessionTime === $timeSlot;
                                            });
                                            
                                            if (empty($slotSessions)): ?>
                                                <div class="add-session-btn" onclick="openAddSessionModal('<?php echo $currentDate; ?>', '<?php echo $timeSlot; ?>')">
                                                    <i class="fas fa-plus"></i> Add Session
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($slotSessions as $session): ?>
                                                    <div class="session-block" onclick="viewSession(<?php echo $session['id']; ?>)">
                                                        <div class="priority-indicator priority-<?php echo $session['priority']; ?>"></div>
                                                        
                                                        <?php if ($session['recurring_type'] !== 'none'): ?>
                                                            <div class="recurring-indicator">
                                                                <i class="fas fa-redo"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="session-status">
                                                            <span class="status-badge <?php echo $session['status']; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $session['status'])); ?>
                                                            </span>
                                                        </div>
                                                        
                                                        <div class="session-header">
                                                            <div>
                                                                <div class="session-title"><?php echo htmlspecialchars($session['title']); ?></div>
                                                                <div class="session-time">
                                                                    <?php echo formatTime($session['start_time']); ?> - <?php echo formatTime($session['end_time']); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="session-type">
                                                            <i class="<?php echo getSessionTypeIcon($session['session_type']); ?>"></i>
                                                            <?php echo ucfirst(str_replace('_', ' ', $session['session_type'])); ?>
                                                        </div>
                                                        
                                                        <?php if (!empty($session['member_name'])): ?>
                                                            <div class="session-member">
                                                                <?php if (!empty($session['profile_image'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($session['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                                <?php else: ?>
                                                                    <div class="member-avatar-placeholder">
                                                                        <?php echo strtoupper(substr($session['member_name'], 0, 1)); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <span><?php echo htmlspecialchars($session['member_name']); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (!empty($session['location']) || !empty($session['estimated_calories'])): ?>
                                                            <div class="session-metrics">
                                                                <?php if (!empty($session['location'])): ?>
                                                                    <div class="metric">
                                                                        <i class="fas fa-map-marker-alt"></i>
                                                                        <span><?php echo htmlspecialchars($session['location']); ?></span>
                                                                    </div>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($session['estimated_calories'] > 0): ?>
                                                                    <div class="metric">
                                                                        <i class="fas fa-fire"></i>
                                                                        <span><?php echo $session['estimated_calories']; ?> cal</span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="session-actions">
                                                            <button class="session-action-btn" onclick="event.stopPropagation(); editSession(<?php echo $session['id']; ?>)">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            <button class="session-action-btn" onclick="event.stopPropagation(); updateSessionStatus(<?php echo $session['id']; ?>, 'completed')">
                                                                <i class="fas fa-check"></i> Complete
                                                            </button>
                                                            <button class="session-action-btn" onclick="event.stopPropagation(); cancelSession(<?php echo $session['id']; ?>)">
                                                                <i class="fas fa-times"></i> Cancel
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                        <?php elseif ($viewType === 'week'): ?>
                            <!-- Week View -->
                            <div class="week-schedule">
                                <div class="week-header">Time</div>
                                <?php foreach ($dateRange as $date): ?>
                                    <div class="week-header">
                                        <?php echo date('D j', strtotime($date)); ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php foreach ($timeSlots as $timeSlot): ?>
                                    <div class="week-header">
                                        <?php echo date('g A', strtotime($timeSlot)); ?>
                                    </div>
                                    
                                    <?php foreach ($dateRange as $date): ?>
                                        <div class="week-day">
                                            <div class="week-day-sessions">
                                                <?php
                                                $daySessions = array_filter($schedule, function($session) use ($date, $timeSlot) {
                                                    $sessionDate = date('Y-m-d', strtotime($session['start_time']));
                                                    $sessionTime = date('H:i:s', strtotime($session['start_time']));
                                                    return $sessionDate === $date && $sessionTime === $timeSlot;
                                                });
                                                
                                                foreach ($daySessions as $session): ?>
                                                    <div class="week-session" onclick="viewSession(<?php echo $session['id']; ?>)">
                                                        <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                                            <?php echo htmlspecialchars($session['title']); ?>
                                                        </div>
                                                        <?php if (!empty($session['member_name'])): ?>
                                                            <div style="font-size: 0.7rem; opacity: 0.8;">
                                                                <?php echo htmlspecialchars($session['member_name']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                            
                        <?php else: ?>
                            <!-- Month View -->
                            <div style="padding: 2rem; text-align: center; color: var(--on-surface-variant);">
                                <i class="fas fa-calendar fa-3x" style="margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>Month view is coming soon!</p>
                                <p style="font-size: 0.9rem;">Use Day or Week view for detailed schedule management.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Add Session Modal -->
    <div class="modal" id="addSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Schedule New Session</h3>
                <button class="close-modal" onclick="closeModal('addSessionModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form action="schedule.php" method="post" id="addSessionForm">
                    <input type="hidden" name="add_session" value="1">
                    
                    <!-- Basic Information -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-info-circle"></i> Session Details
                        </h4>
                        
                        <div class="form-group">
                            <label for="title" class="required">Session Title</label>
                            <input type="text" id="title" name="title" class="form-control" required 
                                   placeholder="e.g., Personal Training Session, Consultation">
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3" 
                                      placeholder="Session goals, focus areas, special requirements..."></textarea>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="session_type" class="required">Session Type</label>
                                <select id="session_type" name="session_type" class="form-control" required>
                                    <option value="personal_training">Personal Training</option>
                                    <option value="group_training">Group Training</option>
                                    <option value="consultation">Consultation</option>
                                    <option value="assessment">Fitness Assessment</option>
                                    <option value="nutrition">Nutrition Counseling</option>
                                    <option value="rehabilitation">Rehabilitation</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="member_id" class="required">Assign to Member</label>
                                <select id="member_id" name="member_id" class="form-control required" required>
                                    <option value="">-- Select Member (Required) --</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>" 
                                                data-email="<?php echo htmlspecialchars($member['email']); ?>"
                                                data-phone="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($member['name']); ?>
                                            <?php if ($member['total_sessions'] > 0): ?>
                                                (<?php echo $member['total_sessions']; ?> sessions)
                                            <?php else: ?>
                                                (New member)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <i class="fas fa-info-circle"></i> 
                                    <?php echo count($members); ?> active members available for scheduling
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Schedule & Location -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-clock"></i> Schedule & Location
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="date" class="required">Date</label>
                                <input type="date" id="date" name="date" class="form-control" required 
                                       value="<?php echo $currentDate; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="start_time" class="required">Start Time</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="end_time" class="required">End Time</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="location">Location</label>
                                <input type="text" id="location" name="location" class="form-control" 
                                       value="Gym" placeholder="e.g., Gym, Studio A, Online">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Status & Priority -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-cog"></i> Status & Settings
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select id="priority" name="priority" class="form-control">
                                    <option value="low">Low</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="estimated_calories">Estimated Calories</label>
                                <input type="number" id="estimated_calories" name="estimated_calories" 
                                       class="form-control" min="0" max="1000" step="25" placeholder="300">
                            </div>
                            
                            <div class="form-group">
                                <label for="payment_amount">Session Fee ($)</label>
                                <input type="number" id="payment_amount" name="payment_amount" 
                                       class="form-control" min="0" step="0.01" placeholder="75.00">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recurring Options -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-redo"></i> Recurring Options
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="recurring_type">Repeat</label>
                                <select id="recurring_type" name="recurring_type" class="form-control">
                                    <option value="none">No Repeat</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="biweekly">Bi-weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="recurring_end_date">Repeat Until</label>
                                <input type="date" id="recurring_end_date" name="recurring_end_date" class="form-control">
                                <div class="form-text">Leave empty for single session</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Notes -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-sticky-note"></i> Additional Information
                        </h4>
                        
                        <div class="form-group">
                            <label for="session_notes">Session Notes</label>
                            <textarea id="session_notes" name="session_notes" class="form-control" rows="3" 
                                      placeholder="Workout focus, client goals, special considerations..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="preparation_notes">Preparation Notes</label>
                            <textarea id="preparation_notes" name="preparation_notes" class="form-control" rows="2" 
                                      placeholder="Equipment setup, room preparation, materials needed..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="equipment_needed">Equipment Needed</label>
                            <input type="text" id="equipment_needed" name="equipment_needed" class="form-control" 
                                   placeholder="e.g., Dumbbells, Resistance Bands, Yoga Mats">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addSessionModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Schedule Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Session Details Modal -->
    <div class="modal" id="sessionDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Session Details</h3>
                <button class="close-modal" onclick="closeModal('sessionDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="sessionDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal" id="updateStatusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Update Session Status</h3>
                <button class="close-modal" onclick="closeModal('updateStatusModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form action="schedule.php" method="post" id="updateStatusForm">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="session_id" id="update_session_id">
                    
                    <div class="form-group">
                        <label for="new_status" class="required">New Status</label>
                        <select id="new_status" name="new_status" class="form-control" required>
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="no_show">No Show</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="actual_duration">Actual Duration (minutes)</label>
                        <input type="number" id="actual_duration" name="actual_duration" class="form-control" 
                               min="0" max="300" placeholder="60">
                    </div>
                    
                    <div class="form-group">
                        <label for="member_feedback">Member Feedback</label>
                        <textarea id="member_feedback" name="member_feedback" class="form-control" rows="3" 
                                  placeholder="How did the session go? Any feedback from the member..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="trainer_rating">Session Rating (1-5)</label>
                        <select id="trainer_rating" name="trainer_rating" class="form-control">
                            <option value="0">No Rating</option>
                            <option value="1">1 - Poor</option>
                            <option value="2">2 - Fair</option>
                            <option value="3">3 - Good</option>
                            <option value="4">4 - Very Good</option>
                            <option value="5">5 - Excellent</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('updateStatusModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Status
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

        // View management
        function changeView(viewType) {
            const currentDate = '<?php echo $currentDate; ?>';
            const memberFilter = '<?php echo $memberFilter; ?>';
            const statusFilter = '<?php echo $statusFilter; ?>';
            
            let url = `schedule.php?view=${viewType}&date=${currentDate}`;
            if (memberFilter > 0) url += `&member_id=${memberFilter}`;
            if (statusFilter) url += `&status=${statusFilter}`;
            
            window.location.href = url;
        }

        // Filter functionality
        document.getElementById('date-picker').addEventListener('change', function() {
            const date = this.value;
            const viewType = '<?php echo $viewType; ?>';
            const memberFilter = document.getElementById('member-filter').value;
            const statusFilter = document.getElementById('status-filter').value;
            
            let url = `schedule.php?date=${date}&view=${viewType}`;
            if (memberFilter > 0) url += `&member_id=${memberFilter}`;
            if (statusFilter) url += `&status=${statusFilter}`;
            
            window.location.href = url;
        });

        document.getElementById('member-filter').addEventListener('change', function() {
            updateFilters();
        });

        document.getElementById('status-filter').addEventListener('change', function() {
            updateFilters();
        });

        function updateFilters() {
            const date = '<?php echo $currentDate; ?>';
            const viewType = '<?php echo $viewType; ?>';
            const memberId = document.getElementById('member-filter').value;
            const status = document.getElementById('status-filter').value;
            
            let url = `schedule.php?date=${date}&view=${viewType}`;
            if (memberId > 0) url += `&member_id=${memberId}`;
            if (status) url += `&status=${status}`;
            
            window.location.href = url;
        }

        // Session management
        function openAddSessionModal(date = null, time = null) {
            if (date) {
                document.getElementById('date').value = date;
            }
            if (time) {
                document.getElementById('start_time').value = time;
                // Set end time to 1 hour later
                const startTime = new Date(`2000-01-01 ${time}`);
                startTime.setHours(startTime.getHours() + 1);
                const endTime = startTime.toTimeString().slice(0, 5);
                document.getElementById('end_time').value = endTime;
            }
            openModal('addSessionModal');
        }

        function viewSession(sessionId) {
            // Load session details via AJAX
            fetch(`get_session_details.php?id=${sessionId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('sessionDetailsContent').innerHTML = html;
                    openModal('sessionDetailsModal');
                })
                .catch(error => {
                    console.error('Error loading session details:', error);
                    alert('Error loading session details');
                });
        }

        function editSession(sessionId) {
            window.location.href = `edit_session.php?id=${sessionId}`;
        }

        function updateSessionStatus(sessionId, status) {
            document.getElementById('update_session_id').value = sessionId;
            document.getElementById('new_status').value = status;
            openModal('updateStatusModal');
        }

        function cancelSession(sessionId) {
            if (confirm('Are you sure you want to cancel this session?')) {
                updateSessionStatus(sessionId, 'cancelled');
            }
        }

        function deleteSession(sessionId) {
            if (confirm('Are you sure you want to delete this session? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'schedule.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_session';
                input.value = '1';
                form.appendChild(input);
                
                const sessionIdInput = document.createElement('input');
                sessionIdInput.type = 'hidden';
                sessionIdInput.name = 'session_id';
                sessionIdInput.value = sessionId;
                form.appendChild(sessionIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        function exportSchedule() {
            const date = '<?php echo $currentDate; ?>';
            const viewType = '<?php echo $viewType; ?>';
            const memberFilter = '<?php echo $memberFilter; ?>';
            const statusFilter = '<?php echo $statusFilter; ?>';
            
            let url = `export_schedule.php?date=${date}&view=${viewType}`;
            if (memberFilter > 0) url += `&member_id=${memberFilter}`;
            if (statusFilter) url += `&status=${statusFilter}`;
            
            window.open(url, '_blank');
        }

        // Enhanced form validation
        document.getElementById('addSessionForm').addEventListener('submit', function(e) {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const memberId = document.getElementById('member_id').value;
            
            if (!memberId) {
                e.preventDefault();
                alert('Please select a member for this session. Member selection is required.');
                document.getElementById('member_id').focus();
                return false;
            }
            
            if (startTime && endTime && startTime >= endTime) {
                e.preventDefault();
                alert('End time must be after start time');
                return false;
            }
        });

        // Auto-calculate end time when start time changes
        document.getElementById('start_time').addEventListener('change', function() {
            const startTime = this.value;
            const endTimeField = document.getElementById('end_time');
            
            if (startTime && !endTimeField.value) {
                const start = new Date(`2000-01-01 ${startTime}`);
                start.setHours(start.getHours() + 1);
                const endTime = start.toTimeString().slice(0, 5);
                endTimeField.value = endTime;
            }
        });

        // Show/hide recurring options
        document.getElementById('recurring_type').addEventListener('change', function() {
            const recurringEndDate = document.getElementById('recurring_end_date');
            const recurringEndGroup = recurringEndDate.closest('.form-group');
            
            if (this.value === 'none') {
                recurringEndGroup.style.display = 'none';
                recurringEndDate.required = false;
            } else {
                recurringEndGroup.style.display = 'block';
                recurringEndDate.required = true;
            }
        });

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

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N for new session
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openModal('addSessionModal');
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    closeModal(activeModal.id);
                }
            }
        });

        // Session conflict detection
        function checkSessionConflicts() {
            const date = document.getElementById('date').value;
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            if (date && startTime && endTime) {
                fetch('check_conflicts.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        date: date,
                        start_time: startTime,
                        end_time: endTime
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.conflicts > 0) {
                        alert('Warning: This time slot conflicts with existing sessions!');
                    }
                })
                .catch(error => {
                    console.error('Error checking conflicts:', error);
                });
            }
        }

        // Add conflict checking to time inputs
        document.getElementById('start_time').addEventListener('blur', checkSessionConflicts);
        document.getElementById('end_time').addEventListener('blur', checkSessionConflicts);
        document.getElementById('date').addEventListener('change', checkSessionConflicts);
    </script>
</body>
</html>
