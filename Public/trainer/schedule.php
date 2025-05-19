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

// Set default theme
$theme = 'dark';

// Check if trainer_settings table exists and has theme_preference column
try {
    // First check if the trainer_settings table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

    if (!$tableExists) {
        // Create trainer_settings table if it doesn't exist
        $conn->exec("
            CREATE TABLE trainer_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                theme_preference VARCHAR(20) DEFAULT 'dark',
                notification_email TINYINT(1) DEFAULT 1,
                notification_sms TINYINT(1) DEFAULT 0,
                auto_confirm_appointments TINYINT(1) DEFAULT 0,
                availability_monday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_tuesday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_wednesday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_thursday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_friday VARCHAR(20) DEFAULT '09:00-17:00',
                availability_saturday VARCHAR(20) DEFAULT '09:00-13:00',
                availability_sunday VARCHAR(20) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id)
            )
        ");
        
        // Insert default settings for this user
        $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id) VALUES (?)");
        $stmt->execute([$userId]);
    } else {
        // Check if theme_preference column exists
        $columnExists = $conn->query("SHOW COLUMNS FROM trainer_settings LIKE 'theme_preference'")->rowCount() > 0;
        
        if (!$columnExists) {
            // Add theme_preference column if it doesn't exist
            $conn->exec("ALTER TABLE trainer_settings ADD COLUMN theme_preference VARCHAR(20) DEFAULT 'dark' AFTER user_id");
        }
    }

    // Now try to get the theme preference
    $stmt = $conn->prepare("SELECT theme_preference FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $themeResult = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($themeResult && isset($themeResult['theme_preference'])) {
        $theme = $themeResult['theme_preference'];
    }
} catch (PDOException $e) {
    // If there's any error, just use the default theme
    // You might want to log this error for debugging
    // error_log('Theme preference error: ' . $e->getMessage());
}

// Get current date or from URL parameter
$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selectedDate = new DateTime($currentDate);
$formattedDate = $selectedDate->format('l, F j, Y');

// Get member filter from URL parameter
$memberFilter = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;

// Get all members assigned to this trainer
$members = [];
try {
    // Check if trainer_members table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_members'")->rowCount() > 0;

    if ($tableExists) {
        // Check if status column exists in trainer_members
        $statusColumnExists = $conn->query("SHOW COLUMNS FROM trainer_members LIKE 'status'")->rowCount() > 0;
        
        $memberQuery = "
            SELECT u.id, u.name, u.email, u.profile_image
            FROM trainer_members tm
            JOIN users u ON tm.member_id = u.id
            WHERE tm.trainer_id = ?
        ";
        
        if ($statusColumnExists) {
            $memberQuery .= " AND tm.status = 'active'";
        }
        
        $memberQuery .= " ORDER BY u.name ASC";
        
        $memberStmt = $conn->prepare($memberQuery);
        $memberStmt->execute([$userId]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty members array already set
    // error_log('Members query error: ' . $e->getMessage());
}

// Get schedule for the selected date
$schedule = [];
try {
    // Check if trainer_schedule table exists
    if ($conn->query("SHOW TABLES LIKE 'trainer_schedule'")->rowCount() > 0) {
        $scheduleQuery = "
            SELECT ts.id, ts.title, ts.description, ts.start_time, ts.end_time, ts.status, 
                   ts.member_id, u.name as member_name, u.profile_image
            FROM trainer_schedule ts
            LEFT JOIN users u ON ts.member_id = u.id
            WHERE ts.trainer_id = ? AND DATE(ts.start_time) = ?
        ";
        
        if ($memberFilter > 0) {
            $scheduleQuery .= " AND ts.member_id = ?";
            $scheduleParams = [$userId, $currentDate, $memberFilter];
        } else {
            $scheduleParams = [$userId, $currentDate];
        }
        
        $scheduleQuery .= " ORDER BY ts.start_time ASC";
        
        $scheduleStmt = $conn->prepare($scheduleQuery);
        $scheduleStmt->execute($scheduleParams);
        $schedule = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty schedule array already set
    // error_log('Schedule query error: ' . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_session'])) {
        // Add new session
        try {
            $title = $_POST['title'];
            $description = $_POST['description'] ?? '';
            $memberId = !empty($_POST['member_id']) ? $_POST['member_id'] : null;
            $date = $_POST['date'];
            $startTime = $_POST['start_time'];
            $endTime = $_POST['end_time'];
            $status = $_POST['status'] ?? 'scheduled';
            
            $startDateTime = $date . ' ' . $startTime;
            $endDateTime = $date . ' ' . $endTime;
            
            $stmt = $conn->prepare("
                INSERT INTO trainer_schedule 
                (trainer_id, member_id, title, description, start_time, end_time, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$userId, $memberId, $title, $description, $startDateTime, $endDateTime, $status]);
            
            $message = 'Session added successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: schedule.php?date=$date&created=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error adding session: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_session'])) {
        // Update existing session
        try {
            $sessionId = $_POST['session_id'];
            $title = $_POST['title'];
            $description = $_POST['description'] ?? '';
            $memberId = !empty($_POST['member_id']) ? $_POST['member_id'] : null;
            $date = $_POST['date'];
            $startTime = $_POST['start_time'];
            $endTime = $_POST['end_time'];
            $status = $_POST['status'] ?? 'scheduled';
            
            $startDateTime = $date . ' ' . $startTime;
            $endDateTime = $date . ' ' . $endTime;
            
            $stmt = $conn->prepare("
                UPDATE trainer_schedule 
                SET member_id = ?, title = ?, description = ?, start_time = ?, end_time = ?, status = ?
                WHERE id = ? AND trainer_id = ?
            ");
            
            $stmt->execute([$memberId, $title, $description, $startDateTime, $endDateTime, $status, $sessionId, $userId]);
            
            $message = 'Session updated successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: schedule.php?date=$date&updated=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error updating session: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete_session'])) {
        // Delete session
        try {
            $sessionId = $_POST['delete_session_id'];
            
            $stmt = $conn->prepare("DELETE FROM trainer_schedule WHERE id = ? AND trainer_id = ?");
            $stmt->execute([$sessionId, $userId]);
            
            $message = 'Session deleted successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: schedule.php?date=$currentDate&deleted=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error deleting session: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_status'])) {
        // Update session status
        try {
            $sessionId = $_POST['status_session_id'];
            $status = $_POST['status'];
            
            $stmt = $conn->prepare("UPDATE trainer_schedule SET status = ? WHERE id = ? AND trainer_id = ?");
            $stmt->execute([$status, $sessionId, $userId]);
            
            $message = 'Session status updated successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: schedule.php?date=$currentDate&updated=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error updating status: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get time slots for the day (30-minute intervals)
$timeSlots = [];
$startHour = 6; // 6 AM
$endHour = 22; // 10 PM

for ($hour = $startHour; $hour < $endHour; $hour++) {
    for ($minute = 0; $minute < 60; $minute += 30) {
        $time = sprintf('%02d:%02d:00', $hour, $minute);
        $timeSlots[] = $time;
    }
}

// Function to format time for display
function formatTime($time) {
    $dateTime = new DateTime($time);
    return $dateTime->format('g:i A');
}

// Function to check if a session exists at a specific time
function hasSessionAtTime($schedule, $timeSlot) {
    $timeSlotDateTime = new DateTime($timeSlot);
    
    foreach ($schedule as $session) {
        $startTime = new DateTime($session['start_time']);
        $endTime = new DateTime($session['end_time']);
        
        if ($timeSlotDateTime >= $startTime && $timeSlotDateTime < $endTime) {
            return $session;
        }
    }
    
    return false;
}

// Function to get session duration in 30-minute blocks
function getSessionDuration($session) {
    $startTime = new DateTime($session['start_time']);
    $endTime = new DateTime($session['end_time']);
    $diff = $startTime->diff($endTime);
    
    // Calculate total minutes
    $totalMinutes = ($diff->h * 60) + $diff->i;
    
    // Convert to 30-minute blocks (rounded up)
    return ceil($totalMinutes / 30);
}

// Get previous and next day for navigation
$prevDay = (new DateTime($currentDate))->modify('-1 day')->format('Y-m-d');
$nextDay = (new DateTime($currentDate))->modify('+1 day')->format('Y-m-d');

// Get week dates for the week view
$weekDates = [];
$weekStart = (new DateTime($currentDate))->modify('monday this week');

for ($i = 0; $i < 7; $i++) {
    $date = clone $weekStart;
    $date->modify("+$i days");
    $weekDates[] = $date;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/trainer-dashboard.css">
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
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
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
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Schedule</h1>
                    <p>Manage your training sessions</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" data-modal="addSessionModal">
                        <i class="fas fa-plus"></i> New Session
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
            
            <!-- Schedule Controls -->
            <div class="card">
                <div class="card-content">
                    <div class="schedule-controls">
                        <div class="date-navigation">
                            <a href="?date=<?php echo $prevDay; ?><?php echo $memberFilter ? '&member_id='.$memberFilter : ''; ?>" class="btn btn-sm btn-outline">
                                <i class="fas fa-chevron-left"></i> Previous Day
                            </a>
                            <h2><?php echo $formattedDate; ?></h2>
                            <a href="?date=<?php echo $nextDay; ?><?php echo $memberFilter ? '&member_id='.$memberFilter : ''; ?>" class="btn btn-sm btn-outline">
                                Next Day <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        
                        <div class="schedule-filters">
                            <div class="form-group">
                                <label for="date-picker">Go to Date:</label>
                                <input type="date" id="date-picker" class="form-control" value="<?php echo $currentDate; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="member-filter">Filter by Member:</label>
                                <select id="member-filter" class="form-control">
                                    <option value="0">All Members</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($member['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Day Schedule -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-calendar-day"></i> Daily Schedule</h2>
                    <div class="card-actions">
                        <button class="btn btn-sm btn-outline" data-modal="addSessionModal">
                            <i class="fas fa-plus"></i> Add Session
                        </button>
                    </div>
                </div>
                <div class="card-content">
                    <div class="schedule-timeline">
                        <div class="timeline-header">
                            <div class="timeline-time">Time</div>
                            <div class="timeline-content">Session</div>
                        </div>
                        
                        <?php foreach ($timeSlots as $index => $timeSlot): ?>
                            <?php 
                                $fullTimeSlot = $currentDate . ' ' . $timeSlot;
                                $session = hasSessionAtTime($schedule, $fullTimeSlot);
                                $isSessionStart = $session && date('H:i:s', strtotime($session['start_time'])) === $timeSlot;
                                
                                // Skip slots that are in the middle of a session (not the start)
                                if ($session && !$isSessionStart) continue;
                                
                                // Calculate rowspan for sessions that span multiple slots
                                $rowspan = $session ? getSessionDuration($session) : 1;
                            ?>
                            <div class="timeline-row">
                                <div class="timeline-time">
                                    <?php echo formatTime($fullTimeSlot); ?>
                                </div>
                                
                                <?php if ($session): ?>
                                    <div class="timeline-session <?php echo $session['status']; ?>" style="grid-row: span <?php echo $rowspan; ?>;">
                                        <div class="session-header">
                                            <h4><?php echo htmlspecialchars($session['title']); ?></h4>
                                            <div class="session-time">
                                                <?php 
                                                    echo formatTime($session['start_time']) . ' - ' . formatTime($session['end_time']);
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($session['description'])): ?>
                                            <div class="session-description">
                                                <?php echo htmlspecialchars($session['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
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
                                        
                                        <div class="session-status">
                                            <span class="status-badge <?php echo $session['status']; ?>">
                                                <?php echo ucfirst($session['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="session-actions">
                                            <button class="btn btn-sm btn-outline" onclick="editSession(<?php echo $session['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline" onclick="showStatusModal(<?php echo $session['id']; ?>, '<?php echo $session['status']; ?>')">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $session['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="timeline-empty">
                                        <button class="add-session-btn" onclick="quickAddSession('<?php echo $timeSlot; ?>')">
                                            <i class="fas fa-plus"></i> Add Session
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($timeSlots)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-day"></i>
                                <p>No time slots defined</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Session Modal -->
    <div class="modal" id="addSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="schedule.php" method="post" data-validate>
                    <input type="hidden" name="add_session" value="1">
                    
                    <div class="form-group">
                        <label for="title">Session Title</label>
                        <input type="text" id="title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="member_id">Member (Optional)</label>
                        <select id="member_id" name="member_id" class="form-control">
                            <option value="">-- Select Member --</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="date">Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo $currentDate; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Session Modal -->
    <div class="modal" id="editSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="schedule.php" method="post" data-validate>
                    <input type="hidden" name="update_session" value="1">
                    <input type="hidden" id="edit_session_id" name="session_id" value="">
                    
                    <div class="form-group">
                        <label for="edit_title">Session Title</label>
                        <input type="text" id="edit_title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Description (Optional)</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_member_id">Member (Optional)</label>
                        <select id="edit_member_id" name="member_id" class="form-control">
                            <option value="">-- Select Member --</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_date">Date</label>
                            <input type="date" id="edit_date" name="date" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_start_time">Start Time</label>
                            <input type="time" id="edit_start_time" name="start_time" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_end_time">End Time</label>
                            <input type="time" id="edit_end_time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" class="form-control">
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteConfirmModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this session? This action cannot be undone.</p>
                
                <form action="schedule.php" method="post">
                    <input type="hidden" name="delete_session" value="1">
                    <input type="hidden" id="delete_session_id" name="delete_session_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal" id="updateStatusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Session Status</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="schedule.php" method="post">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" id="status_session_id" name="status_session_id" value="">
                    
                    <div class="form-group">
                        <label for="status_update">Status</label>
                        <select id="status_update" name="status" class="form-control">
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/js/trainer-dashboard.js"></script>
    <script>
        // Date picker navigation
        document.getElementById('date-picker').addEventListener('change', function() {
            const date = this.value;
            const memberFilter = document.getElementById('member-filter').value;
            window.location.href = `schedule.php?date=${date}${memberFilter > 0 ? '&member_id=' + memberFilter : ''}`;
        });
        
        // Member filter
        document.getElementById('member-filter').addEventListener('change', function() {
            const memberId = this.value;
            const date = document.getElementById('date-picker').value;
            window.location.href = `schedule.php?date=${date}${memberId > 0 ? '&member_id=' + memberId : ''}`;
        });
        
        // Quick add session at specific time
        function quickAddSession(time) {
            const modal = document.getElementById('addSessionModal');
            const startTimeInput = document.getElementById('start_time');
            
            // Set the start time
            startTimeInput.value = time.substring(0, 5); // Format HH:MM
            
            // Calculate end time (30 minutes later)
            const startDate = new Date(`2000-01-01T${time}`);
            startDate.setMinutes(startDate.getMinutes() + 30);
            const endTime = startDate.toTimeString().substring(0, 5);
            
            document.getElementById('end_time').value = endTime;
            
            // Open the modal
            openModal(modal);
        }
        
        // Edit session
        function editSession(sessionId) {
            // Find the session data
            fetch(`get_session.php?id=${sessionId}`)
                .then(response => response.json())
                .then(session => {
                    // Populate the edit form
                    document.getElementById('edit_session_id').value = session.id;
                    document.getElementById('edit_title').value = session.title;
                    document.getElementById('edit_description').value = session.description || '';
                    document.getElementById('edit_member_id').value = session.member_id || '';
                    
                    // Format date and time
                    const startDateTime = new Date(session.start_time);
                    const endDateTime = new Date(session.end_time);
                    
                    document.getElementById('edit_date').value = startDateTime.toISOString().split('T')[0];
                    document.getElementById('edit_start_time').value = startDateTime.toTimeString().substring(0, 5);
                    document.getElementById('edit_end_time').value = endDateTime.toTimeString().substring(0, 5);
                    document.getElementById('edit_status').value = session.status;
                    
                    // Open the modal
                    openModal(document.getElementById('editSessionModal'));
                })
                .catch(error => {
                    console.error('Error fetching session:', error);
                    // Fallback if fetch fails - use data attributes
                    const sessionElements = document.querySelectorAll('.timeline-session');
                    for (const element of sessionElements) {
                        if (element.getAttribute('data-session-id') == sessionId) {
                            // Extract data from the DOM
                            const title = element.querySelector('h4').textContent;
                            const description = element.querySelector('.session-description')?.textContent || '';
                            const timeText = element.querySelector('.session-time').textContent;
                            const status = element.querySelector('.status-badge').textContent.toLowerCase();
                            
                            // Set values
                            document.getElementById('edit_session_id').value = sessionId;
                            document.getElementById('edit_title').value = title;
                            document.getElementById('edit_description').value = description;
                            document.getElementById('edit_status').value = status;
                            
                            // Open the modal
                            openModal(document.getElementById('editSessionModal'));
                            break;
                        }
                    }
                });
        }
        
        // Confirm delete
        function confirmDelete(sessionId) {
            document.getElementById('delete_session_id').value = sessionId;
            openModal(document.getElementById('deleteConfirmModal'));
        }
        
        // Show status update modal
        function showStatusModal(sessionId, currentStatus) {
            document.getElementById('status_session_id').value = sessionId;
            document.getElementById('status_update').value = currentStatus;
            openModal(document.getElementById('updateStatusModal'));
        }
    </script>
</body>
</html>
