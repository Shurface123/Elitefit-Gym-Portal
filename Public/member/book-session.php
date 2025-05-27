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

// Get all trainers
$trainersQuery = "
    SELECT u.id, u.name, u.email, u.profile_image, 
           tp.bio, tp.specialization, tp.certification,
           tp.availability_monday, tp.availability_tuesday, tp.availability_wednesday,
           tp.availability_thursday, tp.availability_friday, tp.availability_saturday,
           tp.availability_sunday,
           (SELECT COUNT(*) FROM trainer_members WHERE trainer_id = u.id) as member_count
    FROM users u
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE u.role = 'Trainer'
    ORDER BY u.name ASC
";


$trainerStmt = $conn->prepare($trainersQuery);
$trainerStmt->execute();
$trainers = $trainerStmt->fetchAll(PDO::FETCH_ASSOC);

// Get my trainers (trainers assigned to this member)
$myTrainersStmt = $conn->prepare("
    SELECT u.id, u.name, u.email, u.profile_image, 
           tp.bio, tp.specialization, tp.certification,
           tp.availability_monday, tp.availability_tuesday, tp.availability_wednesday,
           tp.availability_thursday, tp.availability_friday, tp.availability_saturday,
           tp.availability_sunday,
           tm.created_at 
    FROM trainer_members tm
    JOIN users u ON tm.trainer_id = u.id
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE tm.member_id = ?
    ORDER BY tm.created_at DESC
");

$myTrainersStmt->execute([$userId]);
$myTrainers = $myTrainersStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_session'])) {
    $trainerId = $_POST['trainer_id'];
    $sessionDate = $_POST['session_date'];
    $sessionTime = $_POST['session_time'];
    $sessionDuration = $_POST['session_duration'];
    $sessionTitle = $_POST['session_title'];
    $sessionNotes = $_POST['session_notes'] ?? '';
    
    try {
        // Validate inputs
        if (empty($trainerId) || empty($sessionDate) || empty($sessionTime) || empty($sessionDuration) || empty($sessionTitle)) {
            throw new Exception('All required fields must be filled out.');
        }
        
        // Calculate end time
        $startDateTime = $sessionDate . ' ' . $sessionTime;
        $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +' . $sessionDuration . ' minutes'));
        
        // Check if the selected time is in the future
        $selectedTime = new DateTime($startDateTime);
        $now = new DateTime();
        
        if ($selectedTime <= $now) {
            throw new Exception('Please select a future date and time.');
        }
        
        // Check if trainer is available at the selected time
        $availabilityStmt = $conn->prepare("
            SELECT id FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
            AND status != 'cancelled'
        ");
        $availabilityStmt->execute([
            $trainerId, 
            $startDateTime, $startDateTime,
            $endDateTime, $endDateTime,
            $startDateTime, $endDateTime
        ]);
        
        if ($availabilityStmt->rowCount() > 0) {
            throw new Exception('The trainer is not available at the selected time. Please choose a different time.');
        }
        
        // Insert session request
        $stmt = $conn->prepare("
            INSERT INTO trainer_schedule 
            (trainer_id, member_id, title, description, start_time, end_time, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $trainerId, 
            $userId, 
            $sessionTitle, 
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
        
        $message = 'Session booked successfully! Your trainer will confirm the appointment soon.';
        $messageType = 'success';
        
        // Redirect to appointments page
        header("Location: appointments.php?booked=1");
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get trainer availability for a specific date (AJAX request)
if (isset($_GET['get_availability']) && isset($_GET['trainer_id']) && isset($_GET['date'])) {
    $trainerId = $_GET['trainer_id'];
    $date = $_GET['date'];
    
    try {
        // Get trainer's schedule for the selected date
        $scheduleStmt = $conn->prepare("
            SELECT start_time, end_time 
            FROM trainer_schedule 
            WHERE trainer_id = ? 
            AND DATE(start_time) = ? 
            AND status != 'cancelled'
        ");
        $scheduleStmt->execute([$trainerId, $date]);
        $bookedSlots = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get trainer's availability settings
        $availabilityStmt = $conn->prepare("
            SELECT 
                availability_monday, availability_tuesday, availability_wednesday,
                availability_thursday, availability_friday, availability_saturday, availability_sunday
            FROM trainer_profiles 
            WHERE user_id = ?
        ");
        $availabilityStmt->execute([$trainerId]);
        $availabilitySettings = $availabilityStmt->fetch(PDO::FETCH_ASSOC);
        
        // Determine day of week
        $dayOfWeek = strtolower(date('l', strtotime($date)));
        $availabilityKey = 'availability_' . $dayOfWeek;
        
        $availabilityRange = $availabilitySettings[$availabilityKey] ?? '';
        
        // Parse availability range
        $availableSlots = [];
        if (!empty($availabilityRange) && strpos($availabilityRange, '-') !== false) {
            list($startTime, $endTime) = explode('-', $availabilityRange);
            
            // Generate 30-minute slots within availability range
            $currentSlot = strtotime($date . ' ' . $startTime);
            $endSlot = strtotime($date . ' ' . $endTime);
            
            while ($currentSlot < $endSlot) {
                $slotStart = date('H:i:s', $currentSlot);
                $currentSlot += 30 * 60; // Add 30 minutes
                $slotEnd = date('H:i:s', $currentSlot);
                
                // Check if slot is available (not booked)
                $isAvailable = true;
                foreach ($bookedSlots as $bookedSlot) {
                    $bookedStart = strtotime($bookedSlot['start_time']);
                    $bookedEnd = strtotime($bookedSlot['end_time']);
                    
                    if (
                        ($currentSlot > $bookedStart && $currentSlot <= $bookedEnd) ||
                        ($currentSlot - 30*60 >= $bookedStart && $currentSlot - 30*60 < $bookedEnd) ||
                        ($currentSlot - 30*60 <= $bookedStart && $currentSlot > $bookedEnd)
                    ) {
                        $isAvailable = false;
                        break;
                    }
                }
                
                if ($isAvailable) {
                    $availableSlots[] = [
                        'start' => date('H:i', $currentSlot - 30*60),
                        'end' => date('H:i', $currentSlot)
                    ];
                }
            }
        }
        
        // Return available slots as JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'availableSlots' => $availableSlots
        ]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// // Format date for display
// function formatDate($date) {
//     return date('M j, Y', strtotime($date));
// }
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Session - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
    <style>
        .time-slots {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .time-slot {
            padding: 10px;
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .time-slot:hover {
            background-color: var(--primary-light);
            border-color: var(--primary);
        }
        
        .time-slot.selected {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .trainer-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .trainer-card {
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            padding: 15px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .trainer-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }
        
        .trainer-card.selected {
            border-color: var(--primary);
            background-color: var(--primary-light);
        }
        
        .trainer-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .trainer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 15px;
        }
        
        .trainer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .trainer-info h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        
        .trainer-specialties {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        
        .specialty-badge {
            background-color: var(--primary-light);
            color: var(--primary);
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
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
                    <h1>Book a Session</h1>
                    <p>Schedule a training session with one of our professional trainers</p>
                </div>
                <div class="header-actions">
                    <a href="appointments.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Appointments
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
            
            <!-- Booking Form -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-calendar-plus"></i> Book Your Session</h2>
                </div>
                <div class="card-content">
                    <form action="book-session.php" method="post" id="bookingForm">
                        <input type="hidden" name="book_session" value="1">
                        <input type="hidden" id="selected_trainer_id" name="trainer_id" value="">
                        <input type="hidden" id="selected_time" name="session_time" value="">
                        
                        <!-- Step 1: Select Trainer -->
                        <div class="booking-step" id="step1">
                            <h3>Step 1: Select a Trainer</h3>
                            
                            <?php if (!empty($myTrainers)): ?>
                                <h4>Your Trainers</h4>
                                <div class="trainer-cards">
                                    <?php foreach ($myTrainers as $trainer): ?>
                                        <div class="trainer-card" data-trainer-id="<?php echo $trainer['id']; ?>">
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
                                                        <span>Your trainer since <?php echo formatDate($trainer['joined_date']); ?></span>
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
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <h4>All Trainers</h4>
                            <div class="trainer-cards">
                                <?php foreach ($trainers as $trainer): ?>
                                    <?php 
                                        // Skip trainers already shown in "Your Trainers" section
                                        $alreadyShown = false;
                                        foreach ($myTrainers as $myTrainer) {
                                            if ($myTrainer['id'] == $trainer['id']) {
                                                $alreadyShown = true;
                                                break;
                                            }
                                        }
                                        if ($alreadyShown) continue;
                                    ?>
                                    <div class="trainer-card" data-trainer-id="<?php echo $trainer['id']; ?>">
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
                                                    <span><?php echo $trainer['member_count']; ?> active members</span>
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
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-primary" id="nextToStep2" disabled>Next: Select Date & Time</button>
                            </div>
                        </div>
                        
                        <!-- Step 2: Select Date & Time -->
                        <div class="booking-step" id="step2" style="display: none;">
                            <h3>Step 2: Select Date & Time</h3>
                            
                            <div class="form-group">
                                <label for="session_date">Select Date</label>
                                <input type="date" id="session_date" name="session_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Select Time</label>
                                <div id="timeSlots" class="time-slots">
                                    <div class="empty-state">
                                        <p>Please select a date to see available time slots</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="session_duration">Session Duration</label>
                                <select id="session_duration" name="session_duration" class="form-control" required>
                                    <option value="30">30 minutes</option>
                                    <option value="60" selected>1 hour</option>
                                    <option value="90">1.5 hours</option>
                                    <option value="120">2 hours</option>
                                </select>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-outline" id="backToStep1">Back: Select Trainer</button>
                                <button type="button" class="btn btn-primary" id="nextToStep3" disabled>Next: Session Details</button>
                            </div>
                        </div>
                        
                        <!-- Step 3: Session Details -->
                        <div class="booking-step" id="step3" style="display: none;">
                            <h3>Step 3: Session Details</h3>
                            
                            <div class="form-group">
                                <label for="session_title">Session Title</label>
                                <input type="text" id="session_title" name="session_title" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="session_notes">Notes for Trainer (Optional)</label>
                                <textarea id="session_notes" name="session_notes" class="form-control" rows="4"></textarea>
                                <div class="form-text">Include any specific goals, concerns, or requests for this session.</div>
                            </div>
                            
                            <div class="booking-summary">
                                <h4>Booking Summary</h4>
                                <div class="summary-item">
                                    <div class="summary-label">Trainer:</div>
                                    <div class="summary-value" id="summary-trainer">Not selected</div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Date:</div>
                                    <div class="summary-value" id="summary-date">Not selected</div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Time:</div>
                                    <div class="summary-value" id="summary-time">Not selected</div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Duration:</div>
                                    <div class="summary-value" id="summary-duration">1 hour</div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-outline" id="backToStep2">Back: Select Date & Time</button>
                                <button type="submit" class="btn btn-primary">Book Session</button>
                            </div>
                        </div>
                    </form>
                </div>
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
        
        // Booking form steps
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        
        const nextToStep2Btn = document.getElementById('nextToStep2');
        const backToStep1Btn = document.getElementById('backToStep1');
        const nextToStep3Btn = document.getElementById('nextToStep3');
        const backToStep2Btn = document.getElementById('backToStep2');
        
        // Step 1: Select Trainer
        const trainerCards = document.querySelectorAll('.trainer-card');
        let selectedTrainerId = null;
        let selectedTrainerName = '';
        
        trainerCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remove selected class from all cards
                trainerCards.forEach(c => c.classList.remove('selected'));
                
                // Add selected class to clicked card
                this.classList.add('selected');
                
                // Store selected trainer ID
                selectedTrainerId = this.getAttribute('data-trainer-id');
                selectedTrainerName = this.querySelector('.trainer-info h3').textContent;
                document.getElementById('selected_trainer_id').value = selectedTrainerId;
                
                // Enable next button
                nextToStep2Btn.disabled = false;
                
                // Update summary
                document.getElementById('summary-trainer').textContent = selectedTrainerName;
            });
        });
        
        // Navigation between steps
        nextToStep2Btn.addEventListener('click', function() {
            step1.style.display = 'none';
            step2.style.display = 'block';
        });
        
        backToStep1Btn.addEventListener('click', function() {
            step2.style.display = 'none';
            step1.style.display = 'block';
        });
        
        nextToStep3Btn.addEventListener('click', function() {
            step2.style.display = 'none';
            step3.style.display = 'block';
        });
        
        backToStep2Btn.addEventListener('click', function() {
            step3.style.display = 'none';
            step2.style.display = 'block';
        });
        
        // Step 2: Date and Time selection
        const sessionDateInput = document.getElementById('session_date');
        const timeSlotsContainer = document.getElementById('timeSlots');
        let selectedTime = null;
        
        sessionDateInput.addEventListener('change', function() {
            const selectedDate = this.value;
            
            if (selectedDate && selectedTrainerId) {
                // Show loading state
                timeSlotsContainer.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading available time slots...</div>';
                
                // Fetch available time slots
                fetch(`book-session.php?get_availability=1&trainer_id=${selectedTrainerId}&date=${selectedDate}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.availableSlots.length > 0) {
                                // Render time slots
                                timeSlotsContainer.innerHTML = '';
                                data.availableSlots.forEach(slot => {
                                    const timeSlot = document.createElement('div');
                                    timeSlot.className = 'time-slot';
                                    timeSlot.textContent = slot.start;
                                    timeSlot.setAttribute('data-time', slot.start);
                                    
                                    timeSlot.addEventListener('click', function() {
                                        // Remove selected class from all time slots
                                        document.querySelectorAll('.time-slot').forEach(ts => ts.classList.remove('selected'));
                                        
                                        // Add selected class to clicked time slot
                                        this.classList.add('selected');
                                        
                                        // Store selected time
                                        selectedTime = this.getAttribute('data-time');
                                        document.getElementById('selected_time').value = selectedTime;
                                        
                                        // Enable next button
                                        nextToStep3Btn.disabled = false;
                                        
                                        // Update summary
                                        document.getElementById('summary-time').textContent = selectedTime;
                                    });
                                    
                                    timeSlotsContainer.appendChild(timeSlot);
                                });
                            } else {
                                timeSlotsContainer.innerHTML = '<div class="empty-state"><p>No available time slots for this date. Please select another date.</p></div>';
                                nextToStep3Btn.disabled = true;
                            }
                        } else {
                            timeSlotsContainer.innerHTML = `<div class="empty-state"><p>Error: ${data.error}</p></div>`;
                            nextToStep3Btn.disabled = true;
                        }
                    })
                    .catch(error => {
                        timeSlotsContainer.innerHTML = '<div class="empty-state"><p>Error loading time slots. Please try again.</p></div>';
                        nextToStep3Btn.disabled = true;
                    });
                
                // Update summary
                document.getElementById('summary-date').textContent = new Date(selectedDate).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            } else {
                timeSlotsContainer.innerHTML = '<div class="empty-state"><p>Please select a trainer and date to see available time slots</p></div>';
                nextToStep3Btn.disabled = true;
            }
        });
        
        // Update duration summary when changed
        document.getElementById('session_duration').addEventListener('change', function() {
            const duration = this.options[this.selectedIndex].text;
            document.getElementById('summary-duration').textContent = duration;
        });
        
        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const sessionTitle = document.getElementById('session_title').value;
            
            if (!selectedTrainerId) {
                e.preventDefault();
                alert('Please select a trainer.');
                return false;
            }
            
            if (!sessionDateInput.value) {
                e.preventDefault();
                alert('Please select a date.');
                return false;
            }
            
            if (!selectedTime) {
                e.preventDefault();
                alert('Please select a time slot.');
                return false;
            }
            
            if (!sessionTitle) {
                e.preventDefault();
                alert('Please enter a session title.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>