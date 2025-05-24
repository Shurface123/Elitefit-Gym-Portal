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
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$trainerFilter = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;

// Build query based on filters
$query = "
    SELECT ts.id, ts.title, ts.description, ts.start_time, ts.end_time, ts.status,
           u.id as trainer_id, u.name as trainer_name, u.profile_image as trainer_image
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

// Add order by
$query .= " ORDER BY ts.start_time DESC";

// Execute query
$stmt = $conn->prepare($query);
$stmt->execute($queryParams);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all trainers for filter dropdown
$trainersStmt = $conn->prepare("
    SELECT DISTINCT u.id, u.name
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ?
    ORDER BY u.name ASC
");
$trainersStmt->execute([$userId]);
$trainers = $trainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming appointments (next 7 days)
$upcomingStmt = $conn->prepare("
    SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
           u.id as trainer_id, u.name as trainer_name, u.profile_image as trainer_image
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ? 
    AND ts.start_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    AND ts.status IN ('scheduled', 'confirmed')
    ORDER BY ts.start_time ASC
");
$upcomingStmt->execute([$userId]);
$upcomingAppointments = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle appointment cancellation
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
    $appointmentId = $_POST['appointment_id'];
    
    try {
        // Get appointment details first
        $checkStmt = $conn->prepare("
            SELECT start_time, trainer_id FROM trainer_schedule 
            WHERE id = ? AND member_id = ?
        ");
        $checkStmt->execute([$appointmentId, $userId]);
        $appointmentDetails = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($appointmentDetails) {
            // Check if appointment is in the future
            $appointmentTime = new DateTime($appointmentDetails['start_time']);
            $now = new DateTime();
            
            if ($appointmentTime > $now) {
                // Update appointment status
                $updateStmt = $conn->prepare("
                    UPDATE trainer_schedule 
                    SET status = 'cancelled' 
                    WHERE id = ? AND member_id = ?
                ");
                $updateStmt->execute([$appointmentId, $userId]);
                
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
                $notifyStmt = $conn->prepare("
                    INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                    VALUES (?, ?, ?, ?)
                ");
                $notifyStmt->execute([
                    $appointmentDetails['trainer_id'],
                    "Session cancelled by " . $userName . " for " . date('M j, Y', strtotime($appointmentDetails['start_time'])) . " at " . date('g:i A', strtotime($appointmentDetails['start_time'])),
                    "calendar-times",
                    "schedule.php?date=" . date('Y-m-d', strtotime($appointmentDetails['start_time']))
                ]);
                
                $message = 'Appointment cancelled successfully.';
                $messageType = 'success';
            } else {
                $message = 'Cannot cancel past appointments.';
                $messageType = 'error';
            }
        } else {
            $message = 'Appointment not found.';
            $messageType = 'error';
        }
    } catch (PDOException $e) {
        $message = 'Error cancelling appointment: ' . $e->getMessage();
        $messageType = 'error';
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

// Get day name
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
                    <h1>Appointments</h1>
                    <p>Manage your training sessions and appointments</p>
                </div>
                <div class="header-actions">
                    <a href="book-session.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Book New Session
                    </a>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Upcoming Appointments -->
            <?php if (!empty($upcomingAppointments)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-day"></i> Upcoming Appointments</h2>
                    </div>
                    <div class="card-content">
                        <div class="appointments-list">
                            <?php foreach ($upcomingAppointments as $appointment): ?>
                                <div class="appointment-item">
                                    <div class="appointment-date">
                                        <div class="date"><?php echo formatDate($appointment['start_time']); ?></div>
                                        <div class="time"><?php echo formatTime($appointment['start_time']); ?> - <?php echo formatTime($appointment['end_time']); ?></div>
                                        <div class="day"><?php echo getDayName($appointment['start_time']); ?></div>
                                    </div>
                                    
                                    <div class="appointment-details">
                                        <h4><?php echo htmlspecialchars($appointment['title']); ?></h4>
                                        <?php if (!empty($appointment['description'])): ?>
                                            <div class="appointment-description">
                                                <?php echo htmlspecialchars($appointment['description']); ?>
                                            </div>
                                        <?php endif; ?>
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
                                    
                                    <div class="appointment-actions">
                                        <button class="btn btn-sm btn-danger" onclick="confirmCancel(<?php echo $appointment['id']; ?>, '<?php echo formatDate($appointment['start_time']); ?>', '<?php echo formatTime($appointment['start_time']); ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Appointment Filters -->
            <div class="card">
                <div class="card-content">
                    <form action="appointments.php" method="get" class="filters-form">
                        <div class="form-group">
                            <label for="status">Filter by Status:</label>
                            <select id="status" name="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="scheduled" <?php echo $statusFilter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="date">Filter by Date:</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo $dateFilter; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="trainer_id">Filter by Trainer:</label>
                            <select id="trainer_id" name="trainer_id" class="form-control">
                                <option value="0">All Trainers</option>
                                <?php foreach ($trainers as $trainer): ?>
                                    <option value="<?php echo $trainer['id']; ?>" <?php echo $trainerFilter === $trainer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($trainer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn">Apply Filters</button>
                        <?php if (!empty($statusFilter) || !empty($dateFilter) || $trainerFilter > 0): ?>
                            <a href="appointments.php" class="btn btn-outline">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- All Appointments -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-calendar-alt"></i> All Appointments</h2>
                </div>
                <div class="card-content">
                    <?php if (empty($appointments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <h3>No appointments found</h3>
                            <p>You don't have any appointments matching your filters.</p>
                            <a href="book-session.php" class="btn">Book a Session</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Session</th>
                                        <th>Trainer</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $appointment): ?>
                                        <tr>
                                            <td>
                                                <div class="appointment-time">
                                                    <div><?php echo formatDate($appointment['start_time']); ?></div>
                                                    <div><?php echo formatTime($appointment['start_time']); ?> - <?php echo formatTime($appointment['end_time']); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="appointment-info">
                                                    <div class="appointment-title"><?php echo htmlspecialchars($appointment['title']); ?></div>
                                                    <?php if (!empty($appointment['description'])): ?>
                                                        <div class="appointment-description"><?php echo htmlspecialchars($appointment['description']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="trainer-info">
                                                    <?php if (!empty($appointment['trainer_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($appointment['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                                    <?php else: ?>
                                                        <div class="trainer-avatar-placeholder">
                                                            <?php echo strtoupper(substr($appointment['trainer_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($appointment['trainer_name']); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $appointment['status']; ?>">
                                                    <?php echo ucfirst($appointment['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                    $appointmentTime = new DateTime($appointment['start_time']);
                                                    $now = new DateTime();
                                                    $canCancel = ($appointmentTime > $now) && ($appointment['status'] !== 'cancelled');
                                                ?>
                                                <?php if ($canCancel): ?>
                                                    <button class="btn btn-sm btn-danger" onclick="confirmCancel(<?php echo $appointment['id']; ?>, '<?php echo formatDate($appointment['start_time']); ?>', '<?php echo formatTime($appointment['start_time']); ?>')">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">No actions available</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cancel Appointment Modal -->
    <div class="modal" id="cancelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cancel Appointment</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="warning-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Are you sure you want to cancel your appointment on <span id="cancel-date"></span> at <span id="cancel-time"></span>?</p>
                </div>
                
                <form action="appointments.php" method="post">
                    <input type="hidden" name="cancel_appointment" value="1">
                    <input type="hidden" id="appointment-id" name="appointment_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">No, Keep Appointment</button>
                        <button type="submit" class="btn btn-danger">Yes, Cancel Appointment</button>
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
        const modal = document.getElementById('cancelModal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        // Close modal
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                modal.classList.remove('show');
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        });
        
        // Confirm cancel appointment
        function confirmCancel(appointmentId, date, time) {
            document.getElementById('appointment-id').value = appointmentId;
            document.getElementById('cancel-date').textContent = date;
            document.getElementById('cancel-time').textContent = time;
            modal.classList.add('show');
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
