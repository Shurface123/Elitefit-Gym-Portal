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

// Get workout ID from URL parameter
$workoutId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get workout details
$workoutStmt = $conn->prepare("
    SELECT wp.*, u.name as trainer_name, u.profile_image as trainer_image
    FROM workout_plans wp
    JOIN users u ON wp.trainer_id = u.id
    WHERE wp.id = ? AND wp.member_id = ?
");
$workoutStmt->execute([$workoutId, $userId]);
$workout = $workoutStmt->fetch(PDO::FETCH_ASSOC);

// If workout not found or doesn't belong to this member, redirect to workouts page
if (!$workout) {
    header('Location: workouts.php');
    exit;
}

// Get exercises for this workout
$exercisesStmt = $conn->prepare("
    SELECT * FROM workout_exercises
    WHERE workout_plan_id = ?
    ORDER BY day_number, exercise_order
");
$exercisesStmt->execute([$workoutId]);
$exercises = $exercisesStmt->fetchAll(PDO::FETCH_ASSOC);

// Group exercises by day
$exercisesByDay = [];
foreach ($exercises as $exercise) {
    $dayNumber = $exercise['day_number'];
    if (!isset($exercisesByDay[$dayNumber])) {
        $exercisesByDay[$dayNumber] = [];
    }
    $exercisesByDay[$dayNumber][] = $exercise;
}

// Handle workout completion form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_completion'])) {
    try {
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

// Get workout completion history
$completionStmt = $conn->prepare("
    SELECT * FROM workout_completion
    WHERE member_id = ? AND workout_plan_id = ?
    ORDER BY completion_date DESC
");
$completionStmt->execute([$userId, $workoutId]);
$completionHistory = $completionStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workout Details - EliteFit Gym</title>
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
                    <h1><?php echo htmlspecialchars($workout['title']); ?></h1>
                    <p>Workout Plan Details</p>
                </div>
                <div class="header-actions">
                    <a href="workouts.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Workouts
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
            
            <!-- Workout Details -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> Workout Information</h2>
                    <button class="btn btn-sm" data-modal="logCompletionModal">
                        <i class="fas fa-check"></i> Log Completion
                    </button>
                </div>
                <div class="card-content">
                    <div class="workout-details">
                        <div class="workout-meta">
                            <div class="meta-item">
                                <div class="meta-label">Trainer</div>
                                <div class="meta-value">
                                    <div class="trainer-info">
                                        <?php if (!empty($workout['trainer_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($workout['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                        <?php else: ?>
                                            <div class="trainer-avatar-placeholder">
                                                <?php echo strtoupper(substr($workout['trainer_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <span><?php echo htmlspecialchars($workout['trainer_name']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Difficulty</div>
                                <div class="meta-value">
                                    <span class="badge badge-<?php echo strtolower($workout['difficulty']); ?>">
                                        <?php echo ucfirst($workout['difficulty']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Duration</div>
                                <div class="meta-value"><?php echo htmlspecialchars($workout['duration']); ?></div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Frequency</div>
                                <div class="meta-value"><?php echo htmlspecialchars($workout['frequency']); ?></div>
                            </div>
                            
                            <div class="meta-item">
                                <div class="meta-label">Created</div>
                                <div class="meta-value"><?php echo formatDate($workout['created_at']); ?></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($workout['description'])): ?>
                            <div class="workout-description">
                                <h3>Description</h3>
                                <p><?php echo nl2br(htmlspecialchars($workout['description'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Workout Exercises -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Exercise Plan</h2>
                    <div class="card-actions">
                        <button class="btn btn-sm btn-outline" id="printWorkoutBtn">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-content">
                    <?php if (count($exercisesByDay) > 0): ?>
                        <div class="workout-days">
                            <?php foreach ($exercisesByDay as $dayNumber => $dayExercises): ?>
                                <div class="workout-day">
                                    <div class="day-header">
                                        <h3>Day <?php echo $dayNumber; ?></h3>
                                        <span class="exercise-count"><?php echo count($dayExercises); ?> exercises</span>
                                    </div>
                                    
                                    <div class="exercises-list">
                                        <?php foreach ($dayExercises as $index => $exercise): ?>
                                            <div class="exercise-item">
                                                <div class="exercise-number"><?php echo $index + 1; ?></div>
                                                <div class="exercise-details">
                                                    <h4><?php echo htmlspecialchars($exercise['exercise_name']); ?></h4>
                                                    <div class="exercise-meta">
                                                        <span><i class="fas fa-layer-group"></i> <?php echo $exercise['sets']; ?> sets</span>
                                                        <span><i class="fas fa-redo"></i> <?php echo htmlspecialchars($exercise['reps']); ?> reps</span>
                                                        <span><i class="fas fa-clock"></i> <?php echo $exercise['rest_time']; ?>s rest</span>
                                                    </div>
                                                    <?php if (!empty($exercise['notes'])): ?>
                                                        <div class="exercise-notes">
                                                            <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($exercise['notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-dumbbell"></i>
                            <p>No exercises found for this workout plan</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Completion History -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-history"></i> Completion History</h2>
                </div>
                <div class="card-content">
                    <?php if (count($completionHistory) > 0): ?>
                        <div class="completion-history">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Duration</th>
                                        <th>Difficulty Rating</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completionHistory as $completion): ?>
                                        <tr>
                                            <td><?php echo formatDate($completion['completion_date']); ?></td>
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
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No completion records yet</p>
                            <button class="btn" data-modal="logCompletionModal">Log Your First Completion</button>
                        </div>
                    <?php endif; ?>
                </div>
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
                <form action="" method="post">
                    <input type="hidden" name="log_completion" value="1">
                    
                    <div class="form-group">
                        <label for="completion_date">Completion Date</label>
                        <input type="date" id="completion_date" name="completion_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="duration_minutes">Duration (minutes)</label>
                        <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="difficulty_rating">Difficulty Rating</label>
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
                        <label for="notes">Notes (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
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
        
        // Print functionality
        document.getElementById('printWorkoutBtn').addEventListener('click', function() {
            window.print();
        });
        
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
