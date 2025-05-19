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

// Check if recurring_appointments table exists, create if not
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'recurring_appointments'")->rowCount() > 0;
    
    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE recurring_appointments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                trainer_id INT NOT NULL,
                title VARCHAR(100) NOT NULL,
                description TEXT,
                day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                frequency ENUM('weekly', 'biweekly', 'monthly') NOT NULL DEFAULT 'weekly',
                start_date DATE NOT NULL,
                end_date DATE,
                status ENUM('active', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (member_id),
                INDEX (trainer_id),
                INDEX (status)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error
}

// Get all trainers assigned to this member
$trainersStmt = $conn->prepare("
    SELECT u.id, u.name, u.profile_image
    FROM trainer_members tm
    JOIN users u ON tm.trainer_id = u.id
    WHERE tm.member_id = ?
    ORDER BY u.name ASC
");
$trainersStmt->execute([$userId]);
$trainers = $trainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recurring appointments
$recurringStmt = $conn->prepare("
    SELECT ra.*, u.name as trainer_name, u.profile_image as trainer_image
    FROM recurring_appointments ra
    JOIN users u ON ra.trainer_id = u.id
    WHERE ra.member_id = ?
    ORDER BY ra.day_of_week, ra.start_time
");
$recurringStmt->execute([$userId]);
$recurringAppointments = $recurringStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_recurring'])) {
        // Create new recurring appointment
        try {
            $trainerId = $_POST['trainer_id'];
            $title = $_POST['title'];
            $description = $_POST['description'] ?? '';
            $dayOfWeek = $_POST['day_of_week'];
            $startTime = $_POST['start_time'];
            $endTime = $_POST['end_time'];
            $frequency = $_POST['frequency'];
            $startDate = $_POST['start_date'];
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            
            // Validate inputs
            if (empty($trainerId) || empty($title) || !is_numeric($dayOfWeek) || 
                empty($startTime) || empty($endTime) || empty($frequency) || empty($startDate)) {
                throw new Exception('All required fields must be filled out.');
            }
            
            // Validate day of week
            if ($dayOfWeek < 0 || $dayOfWeek > 6) {
                throw new Exception('Invalid day of week selected.');
            }
            
            // Validate times
            if ($startTime >= $endTime) {
                throw new Exception('End time must be after start time.');
            }
            
            // Validate dates
            $startDateObj = new DateTime($startDate);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($startDateObj < $today) {
                throw new Exception('Start date cannot be in the past.');
            }
            
            if ($endDate && new DateTime($endDate) <= $startDateObj) {
                throw new Exception('End date must be after start date.');
            }
            
            // Insert recurring appointment
            $stmt = $conn->prepare("
                INSERT INTO recurring_appointments 
                (member_id, trainer_id, title, description, day_of_week, start_time, end_time, frequency, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $trainerId, $title, $description, $dayOfWeek, 
                $startTime, $endTime, $frequency, $startDate, $endDate
            ]);
            
            $recurringId = $conn->lastInsertId();
            
            // Create notification for trainer
            $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
            
            if ($notificationTableExists) {
                $notifyStmt = $conn->prepare("
                    INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                    VALUES (?, ?, ?, ?)
                ");
                
                $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $frequencyText = [
                    'weekly' => 'weekly',
                    'biweekly' => 'every two weeks',
                    'monthly' => 'monthly'
                ];
                
                $notifyStmt->execute([
                    $trainerId,
                    "$userName has scheduled a recurring $frequencyText[$frequency] session on $dayNames[$dayOfWeek]s at " . 
                    date('g:i A', strtotime($startTime)) . " starting from " . date('M j, Y', strtotime($startDate)),
                    "calendar-alt",
                    "recurring-sessions.php"
                ]);
            }
            
            // Generate first few sessions
            $currentDate = new DateTime($startDate);
            
            // Adjust to the first occurrence of the selected day of week
            $currentDayOfWeek = (int)$currentDate->format('w'); // 0 (Sunday) to 6 (Saturday)
            $daysToAdd = ($dayOfWeek - $currentDayOfWeek + 7) % 7;
            $currentDate->modify("+$daysToAdd days");
            
            // Generate first 4 occurrences
            $count = 0;
            $maxOccurrences = 4;
            
            while ($count < $maxOccurrences) {
                $sessionDate = $currentDate->format('Y-m-d');
                
                // Check if we've passed the end date
                if ($endDate && $sessionDate > $endDate) {
                    break;
                }
                
                // Create session
                $sessionStartDateTime = $sessionDate . ' ' . $startTime;
                $sessionEndDateTime = $sessionDate . ' ' . $endTime;
                
                $sessionStmt = $conn->prepare("
                    INSERT INTO trainer_schedule 
                    (trainer_id, member_id, title, description, start_time, end_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $sessionStmt->execute([
                    $trainerId, $userId, $title, $description, 
                    $sessionStartDateTime, $sessionEndDateTime, 'scheduled'
                ]);
                
                // Move to next occurrence
                switch ($frequency) {
                    case 'weekly':
                        $currentDate->modify('+1 week');
                        break;
                    case 'biweekly':
                        $currentDate->modify('+2 weeks');
                        break;
                    case 'monthly':
                        $currentDate->modify('+1 month');
                        break;
                }
                
                $count++;
            }
            
            $message = 'Recurring appointment created successfully! The first ' . $count . ' sessions have been scheduled.';
            $messageType = 'success';
            
            // Refresh recurring appointments list
            $recurringStmt->execute([$userId]);
            $recurringAppointments = $recurringStmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_recurring'])) {
        // Update recurring appointment
        try {
            $recurringId = $_POST['recurring_id'];
            $title = $_POST['title'];
            $description = $_POST['description'] ?? '';
            $frequency = $_POST['frequency'];
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $status = $_POST['status'];
            
            // Validate inputs
            if (empty($recurringId) || empty($title) || empty($frequency) || empty($status)) {
                throw new Exception('All required fields must be filled out.');
            }
            
            // Get current recurring appointment
            $stmt = $conn->prepare("
                SELECT * FROM recurring_appointments
                WHERE id = ? AND member_id = ?
            ");
            $stmt->execute([$recurringId, $userId]);
            $currentRecurring = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentRecurring) {
                throw new Exception('Recurring appointment not found.');
            }
            
            // Update recurring appointment
            $stmt = $conn->prepare("
                UPDATE recurring_appointments
                SET title = ?, description = ?, frequency = ?, end_date = ?, status = ?
                WHERE id = ? AND member_id = ?
            ");
            
            $stmt->execute([
                $title, $description, $frequency, $endDate, $status,
                $recurringId, $userId
            ]);
            
            // If status changed to cancelled, cancel all future sessions
            if ($status === 'cancelled' && $currentRecurring['status'] !== 'cancelled') {
                $stmt = $conn->prepare("
                    UPDATE trainer_schedule
                    SET status = 'cancelled'
                    WHERE member_id = ? AND trainer_id = ? AND title = ? AND start_time > NOW()
                ");
                
                $stmt->execute([$userId, $currentRecurring['trainer_id'], $currentRecurring['title']]);
                
                // Create notification for trainer
                $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
                
                if ($notificationTableExists) {
                    $notifyStmt = $conn->prepare("
                        INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    $notifyStmt->execute([
                        $currentRecurring['trainer_id'],
                        "$userName has cancelled their recurring sessions for " . $currentRecurring['title'],
                        "calendar-times",
                        "recurring-sessions.php"
                    ]);
                }
            }
            
            $message = 'Recurring appointment updated successfully!';
            $messageType = 'success';
            
            // Refresh recurring appointments list
            $recurringStmt->execute([$userId]);
            $recurringAppointments = $recurringStmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete_recurring'])) {
        // Delete recurring appointment
        try {
            $recurringId = $_POST['recurring_id'];
            
            // Get current recurring appointment
            $stmt = $conn->prepare("
                SELECT * FROM recurring_appointments
                WHERE id = ? AND member_id = ?
            ");
            $stmt->execute([$recurringId, $userId]);
            $currentRecurring = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentRecurring) {
                throw new Exception('Recurring appointment not found.');
            }
            
            // Delete recurring appointment
            $stmt = $conn->prepare("
                DELETE FROM recurring_appointments
                WHERE id = ? AND member_id = ?
            ");
            
            $stmt->execute([$recurringId, $userId]);
            
            // Cancel all future sessions
            $stmt = $conn->prepare("
                UPDATE trainer_schedule
                SET status = 'cancelled'
                WHERE member_id = ? AND trainer_id = ? AND title = ? AND start_time > NOW()
            ");
            
            $stmt->execute([$userId, $currentRecurring['trainer_id'], $currentRecurring['title']]);
            
            // Create notification for trainer
            $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
            
            if ($notificationTableExists) {
                $notifyStmt = $conn->prepare("
                    INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                    VALUES (?, ?, ?, ?)
                ");
                
                $notifyStmt->execute([
                    $currentRecurring['trainer_id'],
                    "$userName has deleted their recurring sessions for " . $currentRecurring['title'],
                    "calendar-times",
                    "recurring-sessions.php"
                ]);
            }
            
            $message = 'Recurring appointment deleted successfully!';
            $messageType = 'success';
            
            // Refresh recurring appointments list
            $recurringStmt->execute([$userId]);
            $recurringAppointments = $recurringStmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Helper functions
function getDayName($dayNumber) {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[$dayNumber];
}

function formatTime($time) {
    return date('g:i A', strtotime($time));
}

function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function getFrequencyText($frequency) {
    switch ($frequency) {
        case 'weekly':
            return 'Weekly';
        case 'biweekly':
            return 'Every Two Weeks';
        case 'monthly':
            return 'Monthly';
        default:
            return ucfirst($frequency);
    }
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'active':
            return 'success';
        case 'paused':
            return 'warning';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Appointments - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
    <style>
        .recurring-appointments {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .recurring-item {
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            padding: 15px;
            background-color: var(--card-bg);
            transition: var(--transition);
        }
        
        .recurring-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .recurring-title {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .recurring-schedule {
            margin-bottom: 10px;
        }
        
        .schedule-item {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .recurring-trainer {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .recurring-dates {
            margin-top: 10px;
            font-size: 0.9rem;
            color: rgba(var(--foreground-rgb), 0.7);
        }
        
        .recurring-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 15px;
        }
        
        .day-selector {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .day-option {
            padding: 8px 12px;
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .day-option:hover {
            background-color: var(--primary-light);
            border-color: var(--primary);
        }
        
        .day-option.selected {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
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
            <!-- Mobile Menu Toggle -->
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Recurring Appointments</h1>
                    <p>Schedule regular training sessions with your trainers</p>
                </div>
                <div class="header-actions">
                    <a href="appointments.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Appointments
                    </a>
                    <button class="btn btn-primary" data-modal="createRecurringModal">
                        <i class="fas fa-plus"></i> New Recurring Session
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
            
            <!-- Recurring Appointments -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-calendar-alt"></i> Your Recurring Sessions</h2>
                </div>
                <div class="card-content">
                    <?php if (empty($recurringAppointments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <h3>No Recurring Sessions</h3>
                            <p>You don't have any recurring sessions scheduled yet.</p>
                            <button class="btn btn-primary" data-modal="createRecurringModal">Schedule Your First Recurring Session</button>
                        </div>
                    <?php else: ?>
                        <div class="recurring-appointments">
                            <?php foreach ($recurringAppointments as $recurring): ?>
                                <div class="recurring-item">
                                    <div class="recurring-header">
                                        <div>
                                            <div class="recurring-title"><?php echo htmlspecialchars($recurring['title']); ?></div>
                                            <span class="status-badge <?php echo getStatusBadgeClass($recurring['status']); ?>">
                                                <?php echo ucfirst($recurring['status']); ?>
                                            </span>
                                        </div>
                                        <div class="recurring-frequency">
                                            <span class="badge"><?php echo getFrequencyText($recurring['frequency']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($recurring['description'])): ?>
                                        <div class="recurring-description">
                                            <?php echo nl2br(htmlspecialchars($recurring['description'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="recurring-schedule">
                                        <div class="schedule-item">
                                            <i class="fas fa-calendar-day"></i>
                                            <span><?php echo getDayName($recurring['day_of_week']); ?>s</span>
                                        </div>
                                        <div class="schedule-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo formatTime($recurring['start_time']); ?> - <?php echo formatTime($recurring['end_time']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="recurring-trainer">
                                        <?php if (!empty($recurring['trainer_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($recurring['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                        <?php else: ?>
                                            <div class="trainer-avatar-placeholder">
                                                <?php echo strtoupper(substr($recurring['trainer_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <span>with <?php echo htmlspecialchars($recurring['trainer_name']); ?></span>
                                    </div>
                                    
                                    <div class="recurring-dates">
                                        <div>Started: <?php echo formatDate($recurring['start_date']); ?></div>
                                        <?php if (!empty($recurring['end_date'])): ?>
                                            <div>Ends: <?php echo formatDate($recurring['end_date']); ?></div>
                                        <?php else: ?>
                                            <div>No end date</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="recurring-actions">
                                        <button class="btn btn-sm btn-outline" onclick="editRecurring(<?php echo $recurring['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteRecurring(<?php echo $recurring['id']; ?>, '<?php echo htmlspecialchars($recurring['title']); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Information Card -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> About Recurring Sessions</h2>
                </div>
                <div class="card-content">
                    <div class="info-content">
                        <p>Recurring sessions allow you to schedule regular training sessions with your trainers without having to book each session individually.</p>
                        
                        <h3>How it works:</h3>
                        <ul>
                            <li>Choose a trainer, day of the week, time, and frequency (weekly, biweekly, or monthly).</li>
                            <li>The system will automatically create individual sessions based on your recurring schedule.</li>
                            <li>You can edit or cancel your recurring schedule at any time.</li>
                            <li>Individual sessions can still be cancelled separately if needed.</li>
                        </ul>
                        
                        <h3>Frequency options:</h3>
                        <ul>
                            <li><strong>Weekly:</strong> Sessions occur every week on the selected day.</li>
                            <li><strong>Biweekly:</strong> Sessions occur every two weeks on the selected day.</li>
                            <li><strong>Monthly:</strong> Sessions occur once a month on the selected day.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Recurring Modal -->
    <div class="modal" id="createRecurringModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Recurring Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="" method="post" id="createRecurringForm">
                    <input type="hidden" name="create_recurring" value="1">
                    <input type="hidden" id="selected_day" name="day_of_week" value="">
                    
                    <div class="form-group">
                        <label for="trainer_id">Select Trainer</label>
                        <select id="trainer_id" name="trainer_id" class="form-control" required>
                            <option value="">-- Select Trainer --</option>
                            <?php foreach ($trainers as $trainer): ?>
                                <option value="<?php echo $trainer['id']; ?>">
                                    <?php echo htmlspecialchars($trainer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="title">Session Title</label>
                        <input type="text" id="title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Day of Week</label>
                        <div class="day-selector">
                            <div class="day-option" data-day="0">Sunday</div>
                            <div class="day-option" data-day="1">Monday</div>
                            <div class="day-option" data-day="2">Tuesday</div>
                            <div class="day-option" data-day="3">Wednesday</div>
                            <div class="day-option" data-day="4">Thursday</div>
                            <div class="day-option" data-day="5">Friday</div>
                            <div class="day-option" data-day="6">Saturday</div>
                        </div>
                    </div>
                    
                    <div class="form-grid">
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
                        <label for="frequency">Frequency</label>
                        <select id="frequency" name="frequency" class="form-control" required>
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Every Two Weeks</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date">End Date (Optional)</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Recurring Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Recurring Modal -->
    <div class="modal" id="editRecurringModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Recurring Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="" method="post" id="editRecurringForm">
                    <input type="hidden" name="update_recurring" value="1">
                    <input type="hidden" id="edit_recurring_id" name="recurring_id" value="">
                    
                    <div class="form-group">
                        <label for="edit_title">Session Title</label>
                        <input type="text" id="edit_title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Description (Optional)</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_frequency">Frequency</label>
                        <select id="edit_frequency" name="frequency" class="form-control" required>
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Every Two Weeks</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_end_date">End Date (Optional)</label>
                        <input type="date" id="edit_end_date" name="end_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-text">
                        <p><strong>Note:</strong> Day of week, time, and trainer cannot be changed for existing recurring sessions. If you need to change these, please delete this recurring session and create a new one.</p>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Recurring Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Recurring Modal -->
    <div class="modal" id="deleteRecurringModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Recurring Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="warning-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Are you sure you want to delete the recurring session "<span id="delete-recurring-title"></span>"?</p>
                    <p>This will cancel all future sessions in this recurring schedule.</p>
                </div>
                
                <form action="" method="post">
                    <input type="hidden" name="delete_recurring" value="1">
                    <input type="hidden" id="delete_recurring_id" name="recurring_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Recurring Session</button>
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
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Modal functionality
        const modals = document.querySelectorAll('.modal');
        const modalTriggers = document.querySelectorAll('[data-modal]');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        modalTriggers.forEach(trigger => {
            trigger.addEventListener('click', function() {
                const modalId = this.getAttribute('data-modal');
                document.getElementById(modalId).classList.add('show');
            });
        });
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const modal = this.closest('.modal');
                modal.classList.remove('show');
            });
        });
        
        window.addEventListener('click', function(event) {
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });
        
        // Day selector
        const dayOptions = document.querySelectorAll('.day-option');
        const selectedDayInput = document.getElementById('selected_day');
        
        dayOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                dayOptions.forEach(opt => opt.classList.remove('selected'));
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Update hidden input
                selectedDayInput.value = this.getAttribute('data-day');
            });
        });
        
        // Form validation
        document.getElementById('createRecurringForm').addEventListener('submit', function(e) {
            if (!selectedDayInput.value) {
                e.preventDefault();
                alert('Please select a day of the week.');
                return false;
            }
            
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            if (startTime >= endTime) {
                e.preventDefault();
                alert('End time must be after start time.');
                return false;
            }
            
            return true;
        });
        
        // Edit recurring session
        function editRecurring(recurringId) {
            // Fetch recurring session data
            fetch(`get-recurring.php?id=${recurringId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const recurring = data.recurring;
                        
                        // Populate form fields
                        document.getElementById('edit_recurring_id').value = recurring.id;
                        document.getElementById('edit_title').value = recurring.title;
                        document.getElementById('edit_description').value = recurring.description || '';
                        document.getElementById('edit_frequency').value = recurring.frequency;
                        document.getElementById('edit_end_date').value = recurring.end_date || '';
                        document.getElementById('edit_status').value = recurring.status;
                        
                        // Show modal
                        document.getElementById('editRecurringModal').classList.add('show');
                    } else {
                        alert('Error loading recurring session data: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while loading the recurring session data.');
                });
        }
        
        // Delete recurring session
        function deleteRecurring(recurringId, title) {
            document.getElementById('delete_recurring_id').value = recurringId;
            document.getElementById('delete-recurring-title').textContent = title;
            document.getElementById('deleteRecurringModal').classList.add('show');
        }
    </script>
</body>
</html>
