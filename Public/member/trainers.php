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
$specializationFilter = isset($_GET['specialization']) ? $_GET['specialization'] : '';
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Get all trainers
$trainersQuery = "
    SELECT u.id, u.name, u.email, u.profile_image, tp.bio, tp.specialization, tp.certification,
           (SELECT COUNT(*) FROM trainer_members WHERE trainer_id = u.id) as member_count
    FROM users u
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE u.role = 'Trainer'
";

// Apply filters
$queryParams = [];
if (!empty($specializationFilter) || !empty($searchQuery)) {
    $trainersQuery .= " AND (";
    
    if (!empty($specialtyFilter)) {
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

$trainersQuery .= " ORDER BY u.name ASC";

$trainerStmt = $conn->prepare($trainersQuery);
$trainerStmt->execute($queryParams);
$trainers = $trainerStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all specialization for filter dropdown
$specializationStmt = $conn->prepare("
    SELECT DISTINCT specialization FROM trainer_profiles WHERE specialization IS NOT NULL AND specialization != ''
");
$specializationStmt->execute();
$specializationResults = $specializationStmt->fetchAll(PDO::FETCH_ASSOC);

// Process specialization into a unique list
$allSpecialization = [];
foreach ($specializationResults as $result) {
    $specializationList = explode(',', $result['specialization']);
    foreach ($specializationList as $specialty) {
        $specialty = trim($specialty);
        if (!empty($specialty) && !in_array($specialty, $allSpecialization)) {
            $allSpecialization[] = $specialty;
        }
    }
}
sort($allSpecialization);

// Get my trainers (trainers assigned to this member)
$myTrainersStmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.profile_image, tp.bio, tp.specialization, tp.certification,
           tm.created_at
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
    SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
           u.id as trainer_id, u.name as trainer_name, u.profile_image as trainer_image
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ? AND ts.start_time > NOW() AND ts.status IN ('scheduled', 'confirmed')
    ORDER BY ts.start_time ASC
    LIMIT 5
");
$sessionsStmt->execute([$userId]);
$upcomingSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message'])) {
        // Send message to trainer
        try {
            $trainerId = $_POST['trainer_id'];
            $messageContent = $_POST['message'];
            
            // Check if messages table exists
            $tableExists = $conn->query("SHOW TABLES LIKE 'member_messages'")->rowCount() > 0;
            
            if (!$tableExists) {
                // Create messages table
                $conn->exec("
                    CREATE TABLE member_messages (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        sender_id INT NOT NULL,
                        receiver_id INT NOT NULL,
                        message TEXT NOT NULL,
                        is_read TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX (sender_id),
                        INDEX (receiver_id),
                        INDEX (is_read)
                    )
                ");
            }
            
            // Insert message
            $stmt = $conn->prepare("
                INSERT INTO member_messages (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $trainerId, $messageContent]);
            
            // Create notification for trainer
            $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
            
            if (!$notificationTableExists) {
                // Create notifications table
                $conn->exec("
                    CREATE TABLE trainer_notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        trainer_id INT NOT NULL,
                        message TEXT NOT NULL,
                        icon VARCHAR(50) DEFAULT 'bell',
                        is_read TINYINT(1) DEFAULT 0,
                        link VARCHAR(255),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX (trainer_id),
                        INDEX (is_read)
                    )
                ");
            }
            
            // Insert notification
            $stmt = $conn->prepare("
                INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $trainerId, 
                "New message from " . $userName, 
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
            $sessionNotes = $_POST['session_notes'] ?? '';
            
            // Format date and time
            $startDateTime = $sessionDate . ' ' . $sessionTime;
            $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +1 hour'));
            
            // Insert session request
            $stmt = $conn->prepare("
                INSERT INTO trainer_schedule 
                (trainer_id, member_id, title, description, start_time, end_time, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $trainerId, 
                $userId, 
                'Session Request', 
                $sessionNotes, 
                $startDateTime, 
                $endDateTime, 
                'scheduled'
            ]);
            
            // Create notification for trainer
            $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
            
            if (!$notificationTableExists) {
                // Create notifications table
                $conn->exec("
                    CREATE TABLE trainer_notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        trainer_id INT NOT NULL,
                        message TEXT NOT NULL,
                        icon VARCHAR(50) DEFAULT 'bell',
                        is_read TINYINT(1) DEFAULT 0,
                        link VARCHAR(255),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX (trainer_id),
                        INDEX (is_read)
                    )
                ");
            }
            
            // Insert notification
            $stmt = $conn->prepare("
                INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $trainerId, 
                "New session request from " . $userName . " on " . date('M j, Y', strtotime($sessionDate)) . " at " . date('g:i A', strtotime($sessionTime)), 
                "calendar-alt", 
                "schedule.php?date=" . $sessionDate
            ]);
            
            $message = 'Session request sent successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error requesting session: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['cancel_session'])) {
        // Cancel session
        try {
            $sessionId = $_POST['session_id'];
            
            // Update session status
            $stmt = $conn->prepare("
                UPDATE trainer_schedule 
                SET status = 'cancelled' 
                WHERE id = ? AND member_id = ?
            ");
            $stmt->execute([$sessionId, $userId]);
            
            // Get trainer ID for notification
            $stmt = $conn->prepare("
                SELECT trainer_id, start_time 
                FROM trainer_schedule 
                WHERE id = ?
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                // Create notification for trainer
                $stmt = $conn->prepare("
                    INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $session['trainer_id'], 
                    "Session cancelled by " . $userName . " for " . date('M j, Y', strtotime($session['start_time'])) . " at " . date('g:i A', strtotime($session['start_time'])), 
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
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainers - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
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
            
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Trainers</h1>
                    <p>Connect with our professional fitness trainers</p>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Trainer Filters -->
            <div class="card">
                <div class="card-content">
                    <form action="trainers.php" method="get" class="filters-form">
                        <div class="form-group">
                            <label for="specialty">Filter by Specialty:</label>
                            <select id="specialty" name="specialty" class="form-control">
                                <option value="">All Specialties</option>
                                <?php foreach ($allSpecialties as $specialty): ?>
                                    <option value="<?php echo htmlspecialchars($specialty); ?>" <?php echo $specialtyFilter === $specialty ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($specialty); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="search">Search:</label>
                            <div class="search-box">
                                <input type="text" id="search" name="search" placeholder="Search trainers..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn">Apply Filters</button>
                        <?php if (!empty($specialtyFilter) || !empty($searchQuery)): ?>
                            <a href="trainers.php" class="btn btn-outline">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- My Trainers -->
            <?php if (!empty($myTrainers)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-star"></i> My Trainers</h2>
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
                                                <span><i class="fas fa-calendar-check"></i> Your trainer since <?php echo formatDate($trainer['joined_date']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($trainer['specialties'])): ?>
                                        <div class="trainer-specialties">
                                            <?php 
                                                $specialtiesList = explode(',', $trainer['specialties']);
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
                                            <?php echo nl2br(htmlspecialchars($trainer['bio'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="trainer-actions">
                                        <button class="btn btn-primary" onclick="openMessageModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-envelope"></i> Message
                                        </button>
                                        <button class="btn" onclick="openSessionModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-calendar-plus"></i> Book Session
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
                        <h2><i class="fas fa-calendar-alt"></i> Upcoming Sessions</h2>
                        <a href="appointments.php" class="btn btn-sm">View All</a>
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
                                                <img src="<?php echo htmlspecialchars($session['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                            <?php else: ?>
                                                <div class="trainer-avatar-placeholder">
                                                    <?php echo strtoupper(substr($session['trainer_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span>with <?php echo htmlspecialchars($session['trainer_name']); ?></span>
                                        </div>
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
                    <h2><i class="fas fa-users"></i> Our Trainers</h2>
                </div>
                <div class="card-content">
                    <?php if (empty($trainers)): ?>
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h3>No trainers found</h3>
                            <p>Try adjusting your search criteria or clear the filters.</p>
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
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($trainer['specialties'])): ?>
                                        <div class="trainer-specialties">
                                            <?php 
                                                $specialtiesList = explode(',', $trainer['specialties']);
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
                                            <?php echo nl2br(htmlspecialchars($trainer['bio'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($trainer['certifications'])): ?>
                                        <div class="trainer-certifications">
                                            <h4>Certifications</h4>
                                            <p><?php echo nl2br(htmlspecialchars($trainer['certifications'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="trainer-actions">
                                        <button class="btn btn-primary" onclick="openMessageModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-envelope"></i> Message
                                        </button>
                                        <button class="btn" onclick="openSessionModal(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')">
                                            <i class="fas fa-calendar-plus"></i> Book Session
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
    
    <!-- Message Trainer Modal -->
    <div class="modal" id="messageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Message Trainer</h3>
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
                        <label for="message">Message:</label>
                        <textarea id="message" name="message" class="form-control" rows="5" required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Book Session Modal -->
    <div class="modal" id="sessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Book Session</h3>
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
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="session_date">Date:</label>
                            <input type="date" id="session_date" name="session_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="session_time">Time:</label>
                            <input type="time" id="session_time" name="session_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="session_notes">Notes (Optional):</label>
                        <textarea id="session_notes" name="session_notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Request Session</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Cancel Session Modal -->
    <div class="modal" id="cancelSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Session</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="warning-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Are you sure you want to cancel your session on <span id="cancel_session_date"></span> at <span id="cancel_session_time"></span>?</p>
                </div>
                
                <form action="trainers.php" method="post">
                    <input type="hidden" name="cancel_session" value="1">
                    <input type="hidden" id="cancel_session_id" name="session_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">No, Keep Session</button>
                        <button type="submit" class="btn btn-danger">Yes, Cancel Session</button>
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
        const closeModalBtns = document.querySelectorAll('.close-modal');
        
        // Close modal
        closeModalBtns.forEach(button => {
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
        
        // Open message modal
        function openMessageModal(trainerId, trainerName) {
            document.getElementById('message_trainer_id').value = trainerId;
            document.getElementById('message_trainer_name').value = trainerName;
            document.getElementById('messageModal').classList.add('show');
        }
        
        // Open session modal
        function openSessionModal(trainerId, trainerName) {
            document.getElementById('session_trainer_id').value = trainerId;
            document.getElementById('session_trainer_name').value = trainerName;
            document.getElementById('session_date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('session_time').value = '09:00';
            document.getElementById('sessionModal').classList.add('show');
        }
        
        // Open cancel session modal
        function openCancelSessionModal(sessionId, sessionDate, sessionTime) {
            document.getElementById('cancel_session_id').value = sessionId;
            document.getElementById('cancel_session_date').textContent = sessionDate;
            document.getElementById('cancel_session_time').textContent = sessionTime;
            document.getElementById('cancelSessionModal').classList.add('show');
        }
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
    </script>
</body>
</html>
