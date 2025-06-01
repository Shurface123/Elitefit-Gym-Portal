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
$theme = getThemePreference($conn, $userId) ?: 'dark'; // Default to dark theme

// Get filter parameters
$specializationFilter = isset($_GET['specialization']) ? $_GET['specialization'] : '';
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Get all trainers with enhanced data
$trainersQuery = "
    SELECT u.id, u.name, u.email, u.profile_image, tp.bio, tp.specialization, tp.certification,
           tp.experience_years, tp.rating, tp.hourly_rate, tp.availability_status,
           (SELECT COUNT(*) FROM trainer_members WHERE trainer_id = u.id) as member_count,
           (SELECT AVG(rating) FROM trainer_reviews WHERE trainer_id = u.id) as avg_rating,
           (SELECT COUNT(*) FROM trainer_reviews WHERE trainer_id = u.id) as review_count
    FROM users u
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE u.role = 'Trainer'
";

// Apply filters
$queryParams = [];
if (!empty($specializationFilter) || !empty($searchQuery)) {
    $trainersQuery .= " AND (";
    
    if (!empty($specializationFilter)) {
        $trainersQuery .= "tp.specialization LIKE ?";
        $queryParams[] = "%$specializationFilter%";
    }
    
    if (!empty($searchQuery)) {
        if (!empty($specializationFilter)) {
            $trainersQuery .= " OR ";
        }
        $trainersQuery .= "u.name LIKE ? OR tp.bio LIKE ? OR tp.specialization LIKE ? OR tp.certification LIKE ?";
        $queryParams[] = "%$searchQuery%";
        $queryParams[] = "%$searchQuery%";
        $queryParams[] = "%$searchQuery%";
        $queryParams[] = "%$searchQuery%";
    }
    
    $trainersQuery .= ")";
}

$trainersQuery .= " ORDER BY tp.rating DESC, u.name ASC";

$trainerStmt = $conn->prepare($trainersQuery);
$trainerStmt->execute($queryParams);
$trainers = $trainerStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all specializations for filter dropdown
$specializationStmt = $conn->prepare("
    SELECT DISTINCT specialization FROM trainer_profiles WHERE specialization IS NOT NULL AND specialization != ''
");
$specializationStmt->execute();
$specializationResults = $specializationStmt->fetchAll(PDO::FETCH_ASSOC);

// Process specializations into a unique list
$allSpecializations = [];
foreach ($specializationResults as $result) {
    $specializationList = explode(',', $result['specialization']);
    foreach ($specializationList as $specialty) {
        $specialty = trim($specialty);
        if (!empty($specialty) && !in_array($specialty, $allSpecializations)) {
            $allSpecializations[] = $specialty;
        }
    }
}
sort($allSpecializations);

// Get my trainers (trainers assigned to this member)
$myTrainersStmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.profile_image, tp.bio, tp.specialization, tp.certification,
           tp.rating, tp.experience_years, tm.created_at,
           (SELECT AVG(rating) FROM trainer_reviews WHERE trainer_id = u.id) as avg_rating
    FROM trainer_members tm
    JOIN users u ON tm.trainer_id = u.id
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE tm.member_id = ?
    ORDER BY tm.created_at DESC
");
$myTrainersStmt->execute([$userId]);
$myTrainers = $myTrainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming sessions with trainers
$sessionsStmt = $conn->prepare("
    SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status, ts.description,
           u.id as trainer_id, u.name as trainer_name, u.profile_image as trainer_image
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ? AND ts.start_time > NOW() AND ts.status IN ('scheduled', 'confirmed')
    ORDER BY ts.start_time ASC
    LIMIT 5
");
$sessionsStmt->execute([$userId]);
$upcomingSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent messages count
$messagesStmt = $conn->prepare("
    SELECT COUNT(*) as unread_count 
    FROM member_messages 
    WHERE receiver_id = ? AND is_read = 0
");
$messagesStmt->execute([$userId]);
$unreadMessages = $messagesStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message'])) {
        // Send message to trainer
        try {
            $trainerId = $_POST['trainer_id'];
            $messageContent = $_POST['message'];
            $messageType = $_POST['message_type'] ?? 'general';
            
            // Check if messages table exists
            $tableExists = $conn->query("SHOW TABLES LIKE 'member_messages'")->rowCount() > 0;
            
            if (!$tableExists) {
                // Create enhanced messages table
                $conn->exec("
                    CREATE TABLE member_messages (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        sender_id INT NOT NULL,
                        receiver_id INT NOT NULL,
                        message TEXT NOT NULL,
                        message_type ENUM('general', 'feedback', 'complaint', 'question') DEFAULT 'general',
                        priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                        is_read TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX (sender_id),
                        INDEX (receiver_id),
                        INDEX (is_read),
                        INDEX (message_type)
                    )
                ");
            }
            
            // Insert message
            $stmt = $conn->prepare("
                INSERT INTO member_messages (sender_id, receiver_id, message, message_type)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $trainerId, $messageContent, $messageType]);
            
            // Create notification for trainer
            $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
            
            if (!$notificationTableExists) {
                // Create enhanced notifications table
                $conn->exec("
                    CREATE TABLE trainer_notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        trainer_id INT NOT NULL,
                        message TEXT NOT NULL,
                        type ENUM('message', 'session', 'feedback', 'system') DEFAULT 'message',
                        icon VARCHAR(50) DEFAULT 'bell',
                        is_read TINYINT(1) DEFAULT 0,
                        link VARCHAR(255),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX (trainer_id),
                        INDEX (is_read),
                        INDEX (type)
                    )
                ");
            }
            
            // Insert notification
            $stmt = $conn->prepare("
                INSERT INTO trainer_notifications (trainer_id, message, type, icon, link)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $trainerId, 
                "New " . $messageType . " message from " . $userName, 
                "message",
                "envelope", 
                "messages.php?member_id=" . $userId
            ]);
            
            $message = 'Message sent successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error sending message: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['request_session'])) {
        // Request session with trainer
        try {
            $trainerId = $_POST['trainer_id'];
            $sessionDate = $_POST['session_date'];
            $sessionTime = $_POST['session_time'];
            $sessionType = $_POST['session_type'] ?? 'personal';
            $sessionNotes = $_POST['session_notes'] ?? '';
            
            // Format date and time
            $startDateTime = $sessionDate . ' ' . $sessionTime;
            $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +1 hour'));
            
            // Insert session request
            $stmt = $conn->prepare("
                INSERT INTO trainer_schedule 
                (trainer_id, member_id, title, description, start_time, end_time, status, session_type) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $trainerId, 
                $userId, 
                ucfirst($sessionType) . ' Training Session', 
                $sessionNotes, 
                $startDateTime, 
                $endDateTime, 
                'pending',
                $sessionType
            ]);
            
            // Create notification for trainer
            $stmt = $conn->prepare("
                INSERT INTO trainer_notifications (trainer_id, message, type, icon, link)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $trainerId, 
                "New " . $sessionType . " session request from " . $userName . " on " . date('M j, Y', strtotime($sessionDate)) . " at " . date('g:i A', strtotime($sessionTime)), 
                "session",
                "calendar-plus", 
                "schedule.php?date=" . $sessionDate
            ]);
            
            $message = 'Session request sent successfully! You will be notified once the trainer responds.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error requesting session: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['cancel_session'])) {
        // Cancel session
        try {
            $sessionId = $_POST['session_id'];
            $cancelReason = $_POST['cancel_reason'] ?? '';
            
            // Update session status
            $stmt = $conn->prepare("
                UPDATE trainer_schedule 
                SET status = 'cancelled', description = CONCAT(COALESCE(description, ''), '\n\nCancellation reason: ', ?)
                WHERE id = ? AND member_id = ?
            ");
            $stmt->execute([$cancelReason, $sessionId, $userId]);
            
            // Get trainer ID for notification
            $stmt = $conn->prepare("
                SELECT trainer_id, start_time, title
                FROM trainer_schedule 
                WHERE id = ?
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                // Create notification for trainer
                $stmt = $conn->prepare("
                    INSERT INTO trainer_notifications (trainer_id, message, type, icon, link)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $session['trainer_id'], 
                    "Session cancelled by " . $userName . " for " . date('M j, Y', strtotime($session['start_time'])) . " at " . date('g:i A', strtotime($session['start_time'])) . (!empty($cancelReason) ? " - Reason: " . $cancelReason : ""), 
                    "session",
                    "calendar-times", 
                    "schedule.php?date=" . date('Y-m-d', strtotime($session['start_time']))
                ]);
            }
            
            $message = 'Session cancelled successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error cancelling session: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['submit_feedback'])) {
        // Submit feedback/review for trainer
        try {
            $trainerId = $_POST['trainer_id'];
            $rating = $_POST['rating'];
            $feedbackText = $_POST['feedback_text'];
            $feedbackType = $_POST['feedback_type'] ?? 'general';
            
            // Check if reviews table exists
            $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_reviews'")->rowCount() > 0;
            
            if (!$tableExists) {
                // Create reviews table
                $conn->exec("
                    CREATE TABLE trainer_reviews (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        trainer_id INT NOT NULL,
                        member_id INT NOT NULL,
                        rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
                        review_text TEXT,
                        feedback_type ENUM('general', 'session', 'communication', 'expertise') DEFAULT 'general',
                        is_anonymous TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_member_trainer (member_id, trainer_id),
                        INDEX (trainer_id),
                        INDEX (rating)
                    )
                ");
            }
            
            // Insert or update review
            $stmt = $conn->prepare("
                INSERT INTO trainer_reviews (trainer_id, member_id, rating, review_text, feedback_type)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                rating = VALUES(rating), 
                review_text = VALUES(review_text), 
                feedback_type = VALUES(feedback_type),
                updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$trainerId, $userId, $rating, $feedbackText, $feedbackType]);
            
            // Update trainer's average rating
            $stmt = $conn->prepare("
                UPDATE trainer_profiles 
                SET rating = (SELECT AVG(rating) FROM trainer_reviews WHERE trainer_id = ?)
                WHERE user_id = ?
            ");
            $stmt->execute([$trainerId, $trainerId]);
            
            // Create notification for trainer
            $stmt = $conn->prepare("
                INSERT INTO trainer_notifications (trainer_id, message, type, icon, link)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $trainerId, 
                "New " . $rating . "-star " . $feedbackType . " feedback from " . $userName, 
                "feedback",
                "star", 
                "reviews.php"
            ]);
            
            $message = 'Feedback submitted successfully! Thank you for your review.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error submitting feedback: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// // Format date for display
// function formatDate($date) {
//     return date('M j, Y', strtotime($date));
// }

// // Format time for display
// function formatTime($time) {
//     return date('g:i A', strtotime($time));
// }

// Generate star rating HTML
function generateStarRating($rating, $maxStars = 5) {
    $html = '';
    $fullStars = floor($rating);
    $hasHalfStar = ($rating - $fullStars) >= 0.5;
    
    for ($i = 1; $i <= $maxStars; $i++) {
        if ($i <= $fullStars) {
            $html .= '<i class="fas fa-star"></i>';
        } elseif ($i == $fullStars + 1 && $hasHalfStar) {
            $html .= '<i class="fas fa-star-half-alt"></i>';
        } else {
            $html .= '<i class="far fa-star"></i>';
        }
    }
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainers - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Dark Theme Colors */
            --bg-primary: #0f0f0f;
            --bg-secondary: #1a1a1a;
            --bg-tertiary: #2a2a2a;
            --bg-card: #1e1e1e;
            --bg-hover: #333333;
            
            /* Orange Accent Colors */
            --accent-primary: #ff6b35;
            --accent-secondary: #ff8c42;
            --accent-light: #ffb366;
            --accent-dark: #e55a2b;
            
            /* Text Colors */
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --text-muted: #808080;
            --text-inverse: #000000;
            
            /* Border Colors */
            --border-primary: #333333;
            --border-secondary: #404040;
            --border-accent: var(--accent-primary);
            
            /* Status Colors */
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #3b82f6;
            
            /* Shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
            
            /* Transitions */
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
            --transition-slow: 0.5s ease-in-out;
        }

        /* Light Theme Override */
        [data-theme="light"] {
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --bg-card: #ffffff;
            --bg-hover: #f1f5f9;
            
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-inverse: #ffffff;
            
            --border-primary: #e2e8f0;
            --border-secondary: #cbd5e1;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Enhanced Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--bg-secondary) 0%, var(--bg-tertiary) 100%);
            border-right: 1px solid var(--border-primary);
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform var(--transition-normal);
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-card);
        }

        .sidebar-header i {
            color: var(--accent-primary);
            margin-right: 0.75rem;
        }

        .sidebar-header h2 {
            color: var(--text-primary);
            font-size: 1.5rem;
            font-weight: 700;
            display: inline-block;
        }

        .sidebar-user {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-card);
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            margin-bottom: 1rem;
            border: 3px solid var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-inverse);
        }

        .user-info h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-status {
            color: var(--accent-primary);
            font-size: 0.875rem;
            font-weight: 500;
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
            padding: 0.875rem 1.5rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all var(--transition-fast);
            border-radius: 0 25px 25px 0;
            margin-right: 1rem;
            position: relative;
        }

        .sidebar-menu a:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
            transform: translateX(5px);
        }

        .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: var(--text-inverse);
            box-shadow: var(--shadow-md);
        }

        .sidebar-menu a i {
            width: 20px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            background: var(--bg-primary);
            min-height: 100vh;
        }

        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--accent-primary);
            color: var(--text-inverse);
            border: none;
            padding: 0.75rem;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-fast);
        }

        .mobile-menu-toggle:hover {
            background: var(--accent-dark);
            transform: scale(1.1);
        }

        .header {
            background: var(--bg-card);
            padding: 2rem;
            border-bottom: 1px solid var(--border-primary);
            box-shadow: var(--shadow-sm);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        /* Enhanced Cards */
        .card {
            background: var(--bg-card);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            margin: 2rem;
            border: 1px solid var(--border-primary);
            overflow: hidden;
            transition: all var(--transition-normal);
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-secondary);
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header i {
            color: var(--accent-primary);
        }

        .card-content {
            padding: 2rem;
        }

        /* Enhanced Alert System */
        .alert {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            margin: 1rem 2rem;
            border-radius: 12px;
            border-left: 4px solid;
            position: relative;
            animation: slideInDown 0.3s ease-out;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-left-color: var(--error);
            color: var(--error);
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }

        .alert .close {
            position: absolute;
            right: 1rem;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.2rem;
            opacity: 0.7;
            transition: opacity var(--transition-fast);
        }

        .alert .close:hover {
            opacity: 1;
        }

        /* Enhanced Forms */
        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .form-control {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-primary);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
            transition: all var(--transition-fast);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-primary);
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
            color: var(--text-muted);
        }

        /* Enhanced Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--accent-primary);
            color: var(--text-inverse);
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: var(--accent-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline {
            background: transparent;
            color: var(--accent-primary);
            border: 2px solid var(--accent-primary);
        }

        .btn-outline:hover {
            background: var(--accent-primary);
            color: var(--text-inverse);
        }

        .btn-danger {
            background: var(--error);
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Enhanced Trainer Cards */
        .trainers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 2rem;
        }

        .trainer-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid var(--border-primary);
            transition: all var(--transition-normal);
            position: relative;
            overflow: hidden;
        }

        .trainer-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
        }

        .trainer-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--accent-primary);
        }

        .trainer-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .trainer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1.5rem;
            border: 3px solid var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .trainer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .trainer-info h3 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .trainer-meta {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .trainer-meta span {
            color: var(--text-secondary);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .trainer-rating {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0;
        }

        .star-rating {
            color: #fbbf24;
            display: flex;
            gap: 0.125rem;
        }

        .rating-text {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .trainer-specialties {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .specialty-badge {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: var(--text-inverse);
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .trainer-bio {
            color: var(--text-secondary);
            margin: 1rem 0;
            line-height: 1.6;
        }

        .trainer-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
            padding: 1rem;
            background: var(--bg-primary);
            border-radius: 8px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .trainer-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .trainer-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* Enhanced Sessions */
        .sessions-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .session-item {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 1.5rem;
            align-items: center;
            padding: 1.5rem;
            background: var(--bg-secondary);
            border-radius: 12px;
            border: 1px solid var(--border-primary);
            transition: all var(--transition-fast);
        }

        .session-item:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .session-date {
            text-align: center;
            min-width: 100px;
        }

        .session-date .date {
            font-weight: 700;
            color: var(--accent-primary);
        }

        .session-date .time {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .session-details h4 {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .trainer-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .trainer-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
        }

        .trainer-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .trainer-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-inverse);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.scheduled {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .status-badge.confirmed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        /* Enhanced Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: slideInUp 0.3s ease-out;
        }

        .modal-header {
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all var(--transition-fast);
        }

        .close-modal:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }

        .modal-body {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .warning-message {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--warning);
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .warning-message i {
            color: var(--warning);
            font-size: 1.5rem;
        }

        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--accent-primary);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
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
            }

            .trainers-grid {
                grid-template-columns: 1fr;
            }

            .session-item {
                grid-template-columns: 1fr;
                gap: 1rem;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .trainer-actions {
                flex-direction: column;
            }

            .card {
                margin: 1rem;
            }

            .card-content {
                padding: 1rem;
            }
        }

        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--accent-primary);
            color: var(--text-inverse);
            border: none;
            padding: 1rem;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-fast);
            z-index: 1000;
        }

        .theme-toggle:hover {
            background: var(--accent-dark);
            transform: scale(1.1);
        }

        /* Enhanced Feedback Form */
        .feedback-rating {
            display: flex;
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .feedback-rating input[type="radio"] {
            display: none;
        }

        .feedback-rating label {
            font-size: 2rem;
            color: var(--text-muted);
            cursor: pointer;
            transition: color var(--transition-fast);
        }

        .feedback-rating input[type="radio"]:checked ~ label,
        .feedback-rating label:hover {
            color: #fbbf24;
        }

        /* Message Type Selector */
        .message-type-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .message-type-option {
            display: none;
        }

        .message-type-label {
            padding: 0.75rem;
            border: 2px solid var(--border-primary);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-size: 0.875rem;
            font-weight: 600;
        }

        .message-type-option:checked + .message-type-label {
            border-color: var(--accent-primary);
            background: var(--accent-primary);
            color: var(--text-inverse);
        }

        .message-type-label:hover {
            border-color: var(--accent-primary);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Enhanced Sidebar -->
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
                    <span class="user-status">Premium Member</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="workouts.php"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
                <li><a href="progress.php"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
                <li><a href="appointments.php"><i class="fas fa-calendar-alt"></i> <span>Appointments</span></a></li>
                <li><a href="nutrition.php"><i class="fas fa-apple-alt"></i> <span>Nutrition</span></a></li>
                <li><a href="trainers.php" class="active"><i class="fas fa-user-friends"></i> <span>Trainers</span></a></li>
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
            
            <!-- Enhanced Header -->
            <div class="header">
                <div>
                    <h1>Professional Trainers</h1>
                    <p>Connect with our certified fitness experts and achieve your goals</p>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Enhanced Trainer Filters -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-filter"></i> Filter & Search</h2>
                </div>
                <div class="card-content">
                    <form action="trainers.php" method="get" class="filters-form">
                        <div class="form-group">
                            <label for="specialization">Filter by Specialty:</label>
                            <select id="specialization" name="specialization" class="form-control">
                                <option value="">All Specialties</option>
                                <?php foreach ($allSpecializations as $specialty): ?>
                                    <option value="<?php echo htmlspecialchars($specialty); ?>" <?php echo $specializationFilter === $specialty ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($specialty); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="search">Search Trainers:</label>
                            <div class="search-box">
                                <input type="text" id="search" name="search" placeholder="Search by name, specialty, or certification..." value="<?php echo htmlspecialchars($searchQuery); ?>" class="form-control">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <?php if (!empty($specializationFilter) || !empty($searchQuery)): ?>
                                <a href="trainers.php" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- My Trainers Section -->
            <?php if (!empty($myTrainers)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-star"></i> My Personal Trainers</h2>
                        <span class="badge"><?php echo count($myTrainers); ?> trainer<?php echo count($myTrainers) !== 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="card-content">
                        <div class="trainers-grid">
                            <?php foreach ($myTrainers as $trainer): ?>
                                <div class="trainer-card">
                                    <div class="trainer-header">
                                        <div class="trainer-avatar">
                                            <?php if (!empty($trainer['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($trainer['profile_image']); ?>" alt="<?php echo htmlspecialchars($trainer['name']); ?>">
                                            <?php else: ?>
                                                <div class="avatar-placeholder">
                                                    <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="trainer-info">
                                            <h3><?php echo htmlspecialchars($trainer['name']); ?></h3>
                                            <div class="trainer-meta">
                                                <span><i class="fas fa-calendar-check"></i> Your trainer since <?php echo formatDate($trainer['created_at']); ?></span>
                                                <?php if (!empty($trainer['experience_years'])): ?>
                                                    <span><i class="fas fa-medal"></i> <?php echo $trainer['experience_years']; ?> years experience</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($trainer['avg_rating'])): ?>
                                        <div class="trainer-rating">
                                            <div class="star-rating">
                                                <?php echo generateStarRating($trainer['avg_rating']); ?>
                                            </div>
                                            <span class="rating-text"><?php echo number_format($trainer['avg_rating'], 1); ?>/5</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($trainer['specialization'])): ?>
                                        <div class="trainer-specialties">
                                            <?php 
                                                $specialtiesList = explode(',', $trainer['specialization']);
                                                foreach ($specialtiesList as $specialty): 
                                                    $specialty = trim($specialty);
                                                    if (!empty($specialty)):
                                            ?>
                                                <span class="specialty-badge"><?php echo htmlspecialchars($specialty); ?></span>
                                            <?php 
                                                    endif;
                                                endforeach; 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($trainer['bio'])): ?>
                                        <div class="trainer-bio">
                                            <?php echo nl2br(htmlspecialchars(substr($trainer['bio'], 0, 150))); ?>
                                            <?php if (strlen($trainer['bio']) > 150): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="trainer-actions">
                                        <button class="btn btn-primary" onclick="openMessageModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-envelope"></i> Message
                                        </button>
                                        <button class="btn" onclick="openSessionModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-calendar-plus"></i> Book Session
                                        </button>
                                        <button class="btn btn-outline" onclick="openFeedbackModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-star"></i> Feedback
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Upcoming Sessions -->
            <?php if (!empty($upcomingSessions)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-alt"></i> Upcoming Training Sessions</h2>
                        <a href="appointments.php" class="btn btn-sm">
                            <i class="fas fa-eye"></i> View All
                        </a>
                    </div>
                    <div class="card-content">
                        <div class="sessions-list">
                            <?php foreach ($upcomingSessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-date">
                                        <div class="date"><?php echo formatDate($session['start_time']); ?></div>
                                        <div class="time"><?php echo formatTime($session['start_time']); ?> - <?php echo formatTime($session['end_time']); ?></div>
                                    </div>
                                    
                                    <div class="session-details">
                                        <h4><?php echo htmlspecialchars($session['title']); ?></h4>
                                        <div class="trainer-info">
                                            <?php if (!empty($session['trainer_image'])): ?>
                                                <div class="trainer-avatar-small">
                                                    <img src="<?php echo htmlspecialchars($session['trainer_image']); ?>" alt="Trainer">
                                                </div>
                                            <?php else: ?>
                                                <div class="trainer-avatar-placeholder">
                                                    <?php echo strtoupper(substr($session['trainer_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span>with <?php echo htmlspecialchars($session['trainer_name']); ?></span>
                                        </div>
                                        <?php if (!empty($session['description'])): ?>
                                            <p class="session-description"><?php echo htmlspecialchars($session['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="session-status">
                                        <span class="status-badge <?php echo $session['status']; ?>">
                                            <?php echo ucfirst($session['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="session-actions">
                                        <button class="btn btn-sm btn-danger" onclick="openCancelSessionModal(<?php echo $session['id']; ?>, '<?php echo formatDate($session['start_time']); ?>', '<?php echo formatTime($session['start_time']); ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- All Trainers -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-users"></i> All Available Trainers</h2>
                    <span class="badge"><?php echo count($trainers); ?> trainer<?php echo count($trainers) !== 1 ? 's' : ''; ?></span>
                </div>
                <div class="card-content">
                    <?php if (empty($trainers)): ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h3>No trainers found</h3>
                            <p>Try adjusting your search criteria or clear the filters to see all available trainers.</p>
                            <a href="trainers.php" class="btn">
                                <i class="fas fa-refresh"></i> Show All Trainers
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="trainers-grid">
                            <?php foreach ($trainers as $trainer): ?>
                                <div class="trainer-card">
                                    <div class="trainer-header">
                                        <div class="trainer-avatar">
                                            <?php if (!empty($trainer['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($trainer['profile_image']); ?>" alt="<?php echo htmlspecialchars($trainer['name']); ?>">
                                            <?php else: ?>
                                                <div class="avatar-placeholder">
                                                    <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="trainer-info">
                                            <h3><?php echo htmlspecialchars($trainer['name']); ?></h3>
                                            <div class="trainer-meta">
                                                <span><i class="fas fa-users"></i> <?php echo $trainer['member_count']; ?> active members</span>
                                                <?php if (!empty($trainer['experience_years'])): ?>
                                                    <span><i class="fas fa-medal"></i> <?php echo $trainer['experience_years']; ?> years experience</span>
                                                <?php endif; ?>
                                                <?php if (!empty($trainer['availability_status'])): ?>
                                                    <span><i class="fas fa-circle" style="color: <?php echo $trainer['availability_status'] === 'available' ? 'var(--success)' : 'var(--warning)'; ?>"></i> <?php echo ucfirst($trainer['availability_status']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($trainer['avg_rating'])): ?>
                                        <div class="trainer-rating">
                                            <div class="star-rating">
                                                <?php echo generateStarRating($trainer['avg_rating']); ?>
                                            </div>
                                            <span class="rating-text"><?php echo number_format($trainer['avg_rating'], 1); ?>/5 (<?php echo $trainer['review_count']; ?> reviews)</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($trainer['specialization'])): ?>
                                        <div class="trainer-specialties">
                                            <?php 
                                                $specialtiesList = explode(',', $trainer['specialization']);
                                                foreach ($specialtiesList as $specialty): 
                                                    $specialty = trim($specialty);
                                                    if (!empty($specialty)):
                                            ?>
                                                <span class="specialty-badge"><?php echo htmlspecialchars($specialty); ?></span>
                                            <?php 
                                                    endif;
                                                endforeach; 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($trainer['bio'])): ?>
                                        <div class="trainer-bio">
                                            <?php echo nl2br(htmlspecialchars(substr($trainer['bio'], 0, 150))); ?>
                                            <?php if (strlen($trainer['bio']) > 150): ?>...<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="trainer-stats">
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $trainer['member_count']; ?></div>
                                            <div class="stat-label">Members</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo $trainer['experience_years'] ?? '0'; ?></div>
                                            <div class="stat-label">Years Exp</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="stat-value"><?php echo number_format($trainer['avg_rating'] ?? 0, 1); ?></div>
                                            <div class="stat-label">Rating</div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($trainer['certification'])): ?>
                                        <div class="trainer-certifications">
                                            <h4><i class="fas fa-certificate"></i> Certifications</h4>
                                            <p><?php echo nl2br(htmlspecialchars($trainer['certification'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="trainer-actions">
                                        <button class="btn btn-primary" onclick="openMessageModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-envelope"></i> Message
                                        </button>
                                        <button class="btn" onclick="openSessionModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-calendar-plus"></i> Book Session
                                        </button>
                                        <button class="btn btn-outline" onclick="openFeedbackModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-star"></i> Review
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
    
    <!-- Enhanced Message Trainer Modal -->
    <div class="modal" id="messageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Send Message to Trainer</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="trainers.php" method="post">
                    <input type="hidden" name="send_message" value="1">
                    <input type="hidden" id="message_trainer_id" name="trainer_id" value="">
                    
                    <div class="form-group">
                        <label for="message_trainer_name">To:</label>
                        <input type="text" id="message_trainer_name" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Message Type:</label>
                        <div class="message-type-selector">
                            <input type="radio" id="msg_general" name="message_type" value="general" class="message-type-option" checked>
                            <label for="msg_general" class="message-type-label">
                                <i class="fas fa-comment"></i><br>General
                            </label>
                            
                            <input type="radio" id="msg_question" name="message_type" value="question" class="message-type-option">
                            <label for="msg_question" class="message-type-label">
                                <i class="fas fa-question-circle"></i><br>Question
                            </label>
                            
                            <input type="radio" id="msg_feedback" name="message_type" value="feedback" class="message-type-option">
                            <label for="msg_feedback" class="message-type-label">
                                <i class="fas fa-star"></i><br>Feedback
                            </label>
                            
                            <input type="radio" id="msg_complaint" name="message_type" value="complaint" class="message-type-option">
                            <label for="msg_complaint" class="message-type-label">
                                <i class="fas fa-exclamation-triangle"></i><br>Concern
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="message">Message:</label>
                        <textarea id="message" name="message" class="form-control" rows="6" placeholder="Type your message here..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Book Session Modal -->
    <div class="modal" id="sessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Book Training Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="trainers.php" method="post">
                    <input type="hidden" name="request_session" value="1">
                    <input type="hidden" id="session_trainer_id" name="trainer_id" value="">
                    
                    <div class="form-group">
                        <label for="session_trainer_name">Trainer:</label>
                        <input type="text" id="session_trainer_name" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Session Type:</label>
                        <div class="message-type-selector">
                            <input type="radio" id="session_personal" name="session_type" value="personal" class="message-type-option" checked>
                            <label for="session_personal" class="message-type-label">
                                <i class="fas fa-user"></i><br>Personal
                            </label>
                            
                            <input type="radio" id="session_group" name="session_type" value="group" class="message-type-option">
                            <label for="session_group" class="message-type-label">
                                <i class="fas fa-users"></i><br>Group
                            </label>
                            
                            <input type="radio" id="session_consultation" name="session_type" value="consultation" class="message-type-option">
                            <label for="session_consultation" class="message-type-label">
                                <i class="fas fa-comments"></i><br>Consultation
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="session_date">Preferred Date:</label>
                            <input type="date" id="session_date" name="session_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="session_time">Preferred Time:</label>
                            <select id="session_time" name="session_time" class="form-control" required>
                                <option value="">Select Time</option>
                                <option value="06:00">6:00 AM</option>
                                <option value="07:00">7:00 AM</option>
                                <option value="08:00">8:00 AM</option>
                                <option value="09:00">9:00 AM</option>
                                <option value="10:00">10:00 AM</option>
                                <option value="11:00">11:00 AM</option>
                                <option value="12:00">12:00 PM</option>
                                <option value="13:00">1:00 PM</option>
                                <option value="14:00">2:00 PM</option>
                                <option value="15:00">3:00 PM</option>
                                <option value="16:00">4:00 PM</option>
                                <option value="17:00">5:00 PM</option>
                                <option value="18:00">6:00 PM</option>
                                <option value="19:00">7:00 PM</option>
                                <option value="20:00">8:00 PM</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="session_notes">Session Goals & Notes:</label>
                        <textarea id="session_notes" name="session_notes" class="form-control" rows="4" placeholder="Describe your fitness goals, any specific areas you'd like to focus on, or special requirements..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calendar-check"></i> Request Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Feedback Modal -->
    <div class="modal" id="feedbackModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-star"></i> Rate & Review Trainer</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="trainers.php" method="post">
                    <input type="hidden" name="submit_feedback" value="1">
                    <input type="hidden" id="feedback_trainer_id" name="trainer_id" value="">
                    
                    <div class="form-group">
                        <label for="feedback_trainer_name">Trainer:</label>
                        <input type="text" id="feedback_trainer_name" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Overall Rating:</label>
                        <div class="feedback-rating">
                            <input type="radio" id="star5" name="rating" value="5">
                            <label for="star5">★</label>
                            <input type="radio" id="star4" name="rating" value="4">
                            <label for="star4">★</label>
                            <input type="radio" id="star3" name="rating" value="3">
                            <label for="star3">★</label>
                            <input type="radio" id="star2" name="rating" value="2">
                            <label for="star2">★</label>
                            <input type="radio" id="star1" name="rating" value="1">
                            <label for="star1">★</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Feedback Category:</label>
                        <div class="message-type-selector">
                            <input type="radio" id="feedback_general" name="feedback_type" value="general" class="message-type-option" checked>
                            <label for="feedback_general" class="message-type-label">
                                <i class="fas fa-comment"></i><br>General
                            </label>
                            
                            <input type="radio" id="feedback_session" name="feedback_type" value="session" class="message-type-option">
                            <label for="feedback_session" class="message-type-label">
                                <i class="fas fa-dumbbell"></i><br>Session
                            </label>
                            
                            <input type="radio" id="feedback_communication" name="feedback_type" value="communication" class="message-type-option">
                            <label for="feedback_communication" class="message-type-label">
                                <i class="fas fa-comments"></i><br>Communication
                            </label>
                            
                            <input type="radio" id="feedback_expertise" name="feedback_type" value="expertise" class="message-type-option">
                            <label for="feedback_expertise" class="message-type-label">
                                <i class="fas fa-brain"></i><br>Expertise
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="feedback_text">Your Review:</label>
                        <textarea id="feedback_text" name="feedback_text" class="form-control" rows="5" placeholder="Share your experience with this trainer..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Cancel Session Modal -->
    <div class="modal" id="cancelSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-times"></i> Cancel Training Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="warning-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <p><strong>Are you sure you want to cancel your session?</strong></p>
                        <p>Session on <span id="cancel_session_date"></span> at <span id="cancel_session_time"></span></p>
                    </div>
                </div>
                
                <form action="trainers.php" method="post">
                    <input type="hidden" name="cancel_session" value="1">
                    <input type="hidden" id="cancel_session_id" name="session_id" value="">
                    
                    <div class="form-group">
                        <label for="cancel_reason">Reason for Cancellation (Optional):</label>
                        <textarea id="cancel_reason" name="cancel_reason" class="form-control" rows="3" placeholder="Let your trainer know why you're cancelling..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Keep Session</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Theme Toggle Button -->
    <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
        <i class="fas fa-moon"></i>
    </button>
    
    <script>
        // Enhanced Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
            
            // Add overlay for mobile
            if (sidebar.classList.contains('show')) {
                const overlay = document.createElement('div');
                overlay.className = 'mobile-overlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 999;
                `;
                overlay.addEventListener('click', () => {
                    sidebar.classList.remove('show');
                    overlay.remove();
                });
                document.body.appendChild(overlay);
            }
        });
        
        // Enhanced Modal functionality
        const modals = document.querySelectorAll('.modal');
        const closeModalBtns = document.querySelectorAll('.close-modal');
        
        // Close modal function
        function closeModal(modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Open modal function
        function openModal(modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Close modal event listeners
        closeModalBtns.forEach(button => {
            button.addEventListener('click', function() {
                closeModal(this.closest('.modal'));
            });
        });
        
        // Close modal when clicking outside
        modals.forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this);
                }
            });
        });
        
        // Escape key to close modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modals.forEach(modal => {
                    if (modal.classList.contains('show')) {
                        closeModal(modal);
                    }
                });
            }
        });
        
        // Enhanced modal functions
        function openMessageModal(trainerId, trainerName) {
            document.getElementById('message_trainer_id').value = trainerId;
            document.getElementById('message_trainer_name').value = trainerName;
            document.getElementById('message').value = '';
            document.querySelector('input[name="message_type"][value="general"]').checked = true;
            openModal(document.getElementById('messageModal'));
        }
        
        function openSessionModal(trainerId, trainerName) {
            document.getElementById('session_trainer_id').value = trainerId;
            document.getElementById('session_trainer_name').value = trainerName;
            document.getElementById('session_date').value = '';
            document.getElementById('session_time').value = '';
            document.getElementById('session_notes').value = '';
            document.querySelector('input[name="session_type"][value="personal"]').checked = true;
            openModal(document.getElementById('sessionModal'));
        }
        
        function openFeedbackModal(trainerId, trainerName) {
            document.getElementById('feedback_trainer_id').value = trainerId;
            document.getElementById('feedback_trainer_name').value = trainerName;
            document.getElementById('feedback_text').value = '';
            document.querySelectorAll('input[name="rating"]').forEach(radio => radio.checked = false);
            document.querySelector('input[name="feedback_type"][value="general"]').checked = true;
            openModal(document.getElementById('feedbackModal'));
        }
        
        function openCancelSessionModal(sessionId, sessionDate, sessionTime) {
            document.getElementById('cancel_session_id').value = sessionId;
            document.getElementById('cancel_session_date').textContent = sessionDate;
            document.getElementById('cancel_session_time').textContent = sessionTime;
            document.getElementById('cancel_reason').value = '';
            openModal(document.getElementById('cancelSessionModal'));
        }
        
        // Enhanced alert close functionality
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
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            }, 5000);
        });
        
        // Enhanced theme toggle
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        
        themeToggle.addEventListener('click', function() {
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            
            // Update icon
            const icon = this.querySelector('i');
            icon.className = newTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
            
            // Save preference to server
            fetch('update-theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ theme: newTheme })
            });
        });
        
        // Enhanced star rating interaction
        const starRatings = document.querySelectorAll('.feedback-rating');
        starRatings.forEach(rating => {
            const stars = rating.querySelectorAll('label');
            const inputs = rating.querySelectorAll('input');
            
            stars.forEach((star, index) => {
                star.addEventListener('mouseenter', () => {
                    stars.forEach((s, i) => {
                        s.style.color = i >= index ? '#fbbf24' : 'var(--text-muted)';
                    });
                });
                
                star.addEventListener('mouseleave', () => {
                    const checkedIndex = Array.from(inputs).findIndex(input => input.checked);
                    stars.forEach((s, i) => {
                        s.style.color = checkedIndex !== -1 && i >= checkedIndex ? '#fbbf24' : 'var(--text-muted)';
                    });
                });
                
                star.addEventListener('click', () => {
                    inputs[index].checked = true;
                    stars.forEach((s, i) => {
                        s.style.color = i >= index ? '#fbbf24' : 'var(--text-muted)';
                    });
                });
            });
        });
        
        // Form validation
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = this.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.style.borderColor = 'var(--error)';
                        field.addEventListener('input', function() {
                            this.style.borderColor = 'var(--border-primary)';
                        }, { once: true });
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
        });
        
        // Enhanced search functionality
        const searchInput = document.getElementById('search');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    // Auto-submit search after 1 second of no typing
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.closest('form').submit();
                    }
                }, 1000);
            });
        }
        
        // Smooth scrolling for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        // Initialize theme based on saved preference
        const savedTheme = html.getAttribute('data-theme') || 'dark';
        const themeIcon = themeToggle.querySelector('i');
        themeIcon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
        
        // Add loading states to buttons
        const submitButtons = document.querySelectorAll('button[type="submit"]');
        submitButtons.forEach(button => {
            button.addEventListener('click', function() {
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                this.disabled = true;
                
                // Re-enable after form submission or timeout
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.disabled = false;
                }, 3000);
            });
        });
    </script>
</body>
</html>