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
$difficultyFilter = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Get all workout plans assigned to this member
$workoutsQuery = "
    SELECT wp.*, u.name as trainer_name, u.profile_image as trainer_image,
           (SELECT COUNT(*) FROM workout_exercises WHERE workout_id = wp.id) as exercise_count
    FROM workout_plans wp
    JOIN users u ON wp.trainer_id = u.id
    WHERE wp.member_id = ?
";

// Apply filters
$queryParams = [$userId];
if (!empty($difficultyFilter) || !empty($searchQuery)) {
    $workoutsQuery .= " AND (";
    
    if (!empty($difficultyFilter)) {
        $workoutsQuery .= "wp.difficulty = ?";
        $queryParams[] = $difficultyFilter;
    }
    
    if (!empty($searchQuery)) {
        if (!empty($difficultyFilter)) {
            $workoutsQuery .= " OR ";
        }
        $workoutsQuery .= "wp.title LIKE ? OR wp.description LIKE ?";
        $queryParams[] = "%$searchQuery%";
        $queryParams[] = "%$searchQuery%";
    }
    
    $workoutsQuery .= ")";
}

$workoutsQuery .= " ORDER BY wp.created_at DESC";

$workoutStmt = $conn->prepare($workoutsQuery);
$workoutStmt->execute($queryParams);
$workouts = $workoutStmt->fetchAll(PDO::FETCH_ASSOC);

// Get workout completion history
$completionStmt = $conn->prepare("
    SELECT wc.*, wp.title as workout_title
    FROM workout_completion wc
    JOIN workout_plans wp ON wc.workout_plan_id = wp.id
    WHERE wc.member_id = ?
    ORDER BY wc.completion_date DESC
    LIMIT 5
");
$completionStmt->execute([$userId]);
$completionHistory = $completionStmt->fetchAll(PDO::FETCH_ASSOC);

// Get workout templates (for requesting new workouts)
$templateStmt = $conn->prepare("
    SELECT wp.*, u.name as trainer_name
    FROM workout_plans wp
    JOIN users u ON wp.trainer_id = u.id
    WHERE wp.is_template = 1
    ORDER BY wp.title ASC
");
$templateStmt->execute();
$templates = $templateStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_workout'])) {
        // Request new workout
        try {
            $trainerId = $_POST['trainer_id'];
            $templateId = !empty($_POST['template_id']) ? $_POST['template_id'] : null;
            $notes = $_POST['notes'] ?? '';
            
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
            
            $message = "New workout plan request from " . $userName;
            if ($templateId) {
                $stmt2 = $conn->prepare("SELECT title FROM workout_plans WHERE id = ?");
                $stmt2->execute([$templateId]);
                $template = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($template) {
                    $message .= " based on template: " . $template['title'];
                }
            }
            
            $stmt->execute([
                $trainerId, 
                $message,
                "dumbbell", 
                "workout-plans.php?member_id=" . $userId
            ]);
            
            $message = 'Workout request sent successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error sending request: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['log_completion'])) {
        // Log workout completion
        try {
            $workoutId = $_POST['workout_id'];
            $completionDate = $_POST['completion_date'];
            $durationMinutes = $_POST['duration_minutes'];
            $difficultyRating = $_POST['difficulty_rating'];
            $notes = $_POST['notes'] ?? '';
            
            // Insert workout completion log
            $stmt = $conn->prepare("
                INSERT INTO workout_completion 
                (member_id, workout_plan_id, completion_date, duration_minutes, difficulty_rating, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$userId, $workoutId, $completionDate, $durationMinutes, $difficultyRating, $notes]);
            
            $message = 'Workout completion logged successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error logging workout completion: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get trainers assigned to this member
$trainerStmt = $conn->prepare("
    SELECT u.id, u.name
    FROM trainer_members tm
    JOIN users u ON tm.trainer_id = u.id
    WHERE tm.member_id = ?
    ORDER BY u.name ASC
");
$trainerStmt->execute([$userId]);
$trainers = $trainerStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Workouts - EliteFit Gym</title>
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
                <li><a href="workouts.php" class="active"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
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
                    <h1>My Workouts</h1>
                    <p>View and manage your workout plans</p>
                </div>
                <div class="header-actions">
                    <button class="btn" id="requestWorkoutBtn">
                        <i class="fas fa-plus"></i> Request Workout
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
            
            <!-- Workout Filters -->
            <div class="card">
                <div class="card-content">
                    <form action="workouts.php" method="get" class="filters-form">
                        <div class="form-group">
                            <label for="difficulty">Filter by Difficulty:</label>
                            <select id="difficulty" name="difficulty" class="form-control">
                                <option value="">All Difficulties</option>
                                <option value="beginner" <?php echo $difficultyFilter === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo $difficultyFilter === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo $difficultyFilter === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="search">Search:</label>
                            <div class="search-box">
                                <input type="text" id="search" name="search" placeholder="Search workouts..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn">Apply Filters</button>
                        <?php if (!empty($difficultyFilter) || !empty($searchQuery)): ?>
                            <a href="workouts.php" class="btn btn-outline">Clear Filters</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Workout Plans -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-dumbbell"></i> Your Workout Plans</h2>
                </div>
                <div class="card-content">
                    <?php if (empty($workouts)): ?>
                        <div class="empty-state">
                            <i class="fas fa-dumbbell"></i>
                            <h3>No Workout Plans</h3>
                            <p>You don't have any workout plans assigned yet.</p>
                            <button class="btn" id="emptyRequestWorkoutBtn">Request a Workout Plan</button>
                        </div>
                    <?php else: ?>
                        <div class="workouts-grid">
                            <?php foreach ($workouts as $workout): ?>
                                <div class="workout-card">
                                    <div class="workout-header">
                                        <h3><?php echo htmlspecialchars($workout['title']); ?></h3>
                                        <div class="workout-badges">
                                            <span class="badge badge-<?php echo strtolower($workout['difficulty']); ?>">
                                                <?php echo ucfirst($workout['difficulty']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-details">
                                        <?php if (!empty($workout['description'])): ?>
                                            <div class="workout-description">
                                                <?php echo nl2br(htmlspecialchars($workout['description'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="workout-stats">
                                            <div class="stat">
                                                <i class="fas fa-calendar-day"></i>
                                                <span><?php echo htmlspecialchars($workout['duration']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-calendar-week"></i>
                                                <span><?php echo htmlspecialchars($workout['frequency']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-list"></i>
                                                <span><?php echo $workout['exercise_count']; ?> exercises</span>
                                            </div>
                                        </div>
                                        
                                        <div class="trainer-info">
                                            <?php if (!empty($workout['trainer_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($workout['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                            <?php else: ?>
                                                <div class="trainer-avatar-placeholder">
                                                    <?php echo strtoupper(substr($workout['trainer_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span>Created by: <?php echo htmlspecialchars($workout['trainer_name']); ?></span>
                                        </div>
                                        
                                        <div class="workout-date">
                                            Created: <?php echo formatDate($workout['created_at']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-actions">
                                        <a href="workout-details.php?id=<?php echo $workout['id']; ?>" class="btn btn-sm">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                        <button class="btn btn-sm btn-outline" onclick="logCompletion(<?php echo $workout['id']; ?>, '<?php echo htmlspecialchars($workout['title']); ?>')">
                                            <i class="fas fa-check"></i> Log Completion
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Completions -->
            <?php if (!empty($completionHistory)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Completions</h2>
                    </div>
                    <div class="card-content">
                        <div class="completion-history">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Workout</th>
                                        <th>Duration</th>
                                        <th>Difficulty Rating</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completionHistory as $completion): ?>
                                        <tr>
                                            <td><?php echo formatDate($completion['completion_date']); ?></td>
                                            <td><?php echo htmlspecialchars($completion['workout_title']); ?></td>
                                            <td><?php echo $completion['duration_minutes']; ?> minutes</td>
                                            <td>
                                                <div class="rating">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star<?php echo $i <= $completion['difficulty_rating'] ? '' : '-o'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </td>
                                            <td><?php echo !empty($completion['notes']) ? htmlspecialchars($completion['notes']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Request Workout Modal -->
    <div class="modal" id="requestWorkoutModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Request Workout Plan</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workouts.php" method="post">
                    <input type="hidden" name="request_workout" value="1">
                    
                    <div class="form-group">
                        <label for="trainer_id">Select Trainer:</label>
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
                        <label for="template_id">Based on Template (Optional):</label>
                        <select id="template_id" name="template_id" class="form-control">
                            <option value="">-- Select Template --</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo $template['id']; ?>">
                                    <?php echo htmlspecialchars($template['title']); ?> (<?php echo ucfirst($template['difficulty']); ?>) - by <?php echo htmlspecialchars($template['trainer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Additional Notes:</label>
                        <textarea id="notes" name="notes" class="form-control" rows="4" placeholder="Describe your goals, preferences, or any specific requirements for this workout plan..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Log Completion Modal -->
    <div class="modal" id="logCompletionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Log Workout Completion</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workouts.php" method="post">
                    <input type="hidden" name="log_completion" value="1">
                    <input type="hidden" id="completion_workout_id" name="workout_id" value="">
                    
                    <div class="form-group">
                        <label for="workout_title">Workout:</label>
                        <input type="text" id="workout_title" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="completion_date">Completion Date:</label>
                        <input type="date" id="completion_date" name="completion_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="duration_minutes">Duration (minutes):</label>
                        <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="difficulty_rating">Difficulty Rating:</label>
                        <div class="rating-input">
                            <div class="stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <input type="radio" id="star<?php echo $i; ?>" name="difficulty_rating" value="<?php echo $i; ?>" <?php echo $i === 3 ? 'checked' : ''; ?>>
                                    <label for="star<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="completion_notes">Notes (Optional):</label>
                        <textarea id="completion_notes" name="notes" class="form-control" rows="3" placeholder="How did the workout go? Any challenges or achievements?"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
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
        const modalTriggers = [
            { id: 'requestWorkoutBtn', modal: 'requestWorkoutModal' },
            { id: 'emptyRequestWorkoutBtn', modal: 'requestWorkoutModal' }
        ];
        
        // Open modal
        modalTriggers.forEach(trigger => {
            const element = document.getElementById(trigger.id);
            if (element) {
                element.addEventListener('click', function() {
                    document.getElementById(trigger.modal).classList.add('show');
                });
            }
        });
        
        // Close modal
        document.querySelectorAll('.close-modal').forEach(button => {
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
        
        // Log completion
        function logCompletion(workoutId, workoutTitle) {
            document.getElementById('completion_workout_id').value = workoutId;
            document.getElementById('workout_title').value = workoutTitle;
            document.getElementById('logCompletionModal').classList.add('show');
        }
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Star rating functionality
        const ratingInputs = document.querySelectorAll('.rating-input input');
        const ratingLabels = document.querySelectorAll('.rating-input label');
        
        ratingInputs.forEach((input, index) => {
            input.addEventListener('change', function() {
                const rating = parseInt(this.value);
                
                ratingLabels.forEach((label, i) => {
                    if (i < rating) {
                        label.classList.add('active');
                    } else {
                        label.classList.remove('active');
                    }
                });
            });
        });
    </script>
</body>
</html>
