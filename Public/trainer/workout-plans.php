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

// Include theme preference helper
require_once 'dashboard-theme-fix.php';
$theme = getThemePreference($conn, $userId);

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
}

// Get member filter from URL parameter
$memberFilter = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;

// Get workout plans
$workoutPlans = [];
try {
    // Check if workout_plans table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'workout_plans'")->rowCount() > 0;

    if (!$tableExists) {
        // Create workout_plans table if it doesn't exist
        $conn->exec("
            CREATE TABLE workout_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NULL,
                title VARCHAR(100) NOT NULL,
                description TEXT,
                difficulty VARCHAR(20) DEFAULT 'intermediate',
                duration VARCHAR(20) DEFAULT '4 weeks',
                frequency VARCHAR(20) DEFAULT '3 days/week',
                is_template TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (trainer_id),
                INDEX (member_id)
            )
        ");
    }

    // Check if workout_exercises table exists
    $exercisesTableExists = $conn->query("SHOW TABLES LIKE 'workout_exercises'")->rowCount() > 0;

    if (!$exercisesTableExists) {
        // Create workout_exercises table if it doesn't exist
        $conn->exec("
            CREATE TABLE workout_exercises (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workout_plan_id INT NOT NULL,
                exercise_name VARCHAR(100) NOT NULL,
                sets INT DEFAULT 3,
                reps VARCHAR(20) DEFAULT '10-12',
                rest_time INT DEFAULT 60,
                notes TEXT,
                day_number INT DEFAULT 1,
                exercise_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (workout_plan_id)
            )
        ");
    }

    // Get workout plans
    $planQuery = "
        SELECT wp.*, u.name as member_name, u.profile_image
        FROM workout_plans wp
        LEFT JOIN users u ON wp.member_id = u.id
        WHERE wp.trainer_id = ?
    ";
    
    if ($memberFilter > 0) {
        $planQuery .= " AND wp.member_id = ?";
        $planParams = [$userId, $memberFilter];
    } else {
        $planParams = [$userId];
    }
    
    $planQuery .= " ORDER BY wp.created_at DESC";
    
    $planStmt = $conn->prepare($planQuery);
    $planStmt->execute($planParams);
    $workoutPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get exercise counts for each plan
    foreach ($workoutPlans as &$plan) {
        $exerciseStmt = $conn->prepare("
            SELECT COUNT(*) as exercise_count, COUNT(DISTINCT day_number) as day_count
            FROM workout_exercises
            WHERE workout_plan_id = ?
        ");
        $exerciseStmt->execute([$plan['id']]);
        $exerciseCounts = $exerciseStmt->fetch(PDO::FETCH_ASSOC);
        
        $plan['exercise_count'] = $exerciseCounts['exercise_count'] ?? 0;
        $plan['day_count'] = $exerciseCounts['day_count'] ?? 0;
    }
} catch (PDOException $e) {
    // Handle error - empty workoutPlans array already set
    // You might want to log this error for debugging
    // error_log('Workout plans error: ' . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_plan'])) {
        // Add new workout plan
        try {
            $title = $_POST['title'];
            $description = $_POST['description'] ?? '';
            $memberId = !empty($_POST['member_id']) ? $_POST['member_id'] : null;
            $difficulty = $_POST['difficulty'] ?? 'intermediate';
            $duration = $_POST['duration'] ?? '4 weeks';
            $frequency = $_POST['frequency'] ?? '3 days/week';
            $isTemplate = isset($_POST['is_template']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                INSERT INTO workout_plans 
                (trainer_id, member_id, title, description, difficulty, duration, frequency, is_template) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$userId, $memberId, $title, $description, $difficulty, $duration, $frequency, $isTemplate]);
            $planId = $conn->lastInsertId();
            
            // Add exercises if provided
            if (isset($_POST['exercise_name']) && is_array($_POST['exercise_name'])) {
                $exerciseNames = $_POST['exercise_name'];
                $sets = $_POST['sets'] ?? [];
                $reps = $_POST['reps'] ?? [];
                $restTimes = $_POST['rest_time'] ?? [];
                $notes = $_POST['exercise_notes'] ?? [];
                $dayNumbers = $_POST['day_number'] ?? [];
                
                $exerciseStmt = $conn->prepare("
                    INSERT INTO workout_exercises 
                    (workout_plan_id, exercise_name, sets, reps, rest_time, notes, day_number, exercise_order) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($exerciseNames as $index => $name) {
                    if (empty($name)) continue;
                    
                    $exerciseStmt->execute([
                        $planId,
                        $name,
                        $sets[$index] ?? 3,
                        $reps[$index] ?? '10-12',
                        $restTimes[$index] ?? 60,
                        $notes[$index] ?? '',
                        $dayNumbers[$index] ?? 1,
                        $index
                    ]);
                }
            }
            
            $message = 'Workout plan created successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: workout-plans.php?created=1" . ($memberFilter ? "&member_id=$memberFilter" : ""));
            exit;
        } catch (PDOException $e) {
            $message = 'Error creating workout plan: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_plan'])) {
        // Update existing workout plan
        try {
            $planId = $_POST['plan_id'];
            $title = $_POST['title'];
            $description = $_POST['description'] ?? '';
            $memberId = !empty($_POST['member_id']) ? $_POST['member_id'] : null;
            $difficulty = $_POST['difficulty'] ?? 'intermediate';
            $duration = $_POST['duration'] ?? '4 weeks';
            $frequency = $_POST['frequency'] ?? '3 days/week';
            $isTemplate = isset($_POST['is_template']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                UPDATE workout_plans 
                SET member_id = ?, title = ?, description = ?, difficulty = ?, duration = ?, frequency = ?, is_template = ?
                WHERE id = ? AND trainer_id = ?
            ");
            
            $stmt->execute([$memberId, $title, $description, $difficulty, $duration, $frequency, $isTemplate, $planId, $userId]);
            
            // Delete existing exercises
            $conn->prepare("DELETE FROM workout_exercises WHERE workout_plan_id = ?")->execute([$planId]);
            
            // Add updated exercises
            if (isset($_POST['exercise_name']) && is_array($_POST['exercise_name'])) {
                $exerciseNames = $_POST['exercise_name'];
                $sets = $_POST['sets'] ?? [];
                $reps = $_POST['reps'] ?? [];
                $restTimes = $_POST['rest_time'] ?? [];
                $notes = $_POST['exercise_notes'] ?? [];
                $dayNumbers = $_POST['day_number'] ?? [];
                
                $exerciseStmt = $conn->prepare("
                    INSERT INTO workout_exercises 
                    (workout_plan_id, exercise_name, sets, reps, rest_time, notes, day_number, exercise_order) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($exerciseNames as $index => $name) {
                    if (empty($name)) continue;
                    
                    $exerciseStmt->execute([
                        $planId,
                        $name,
                        $sets[$index] ?? 3,
                        $reps[$index] ?? '10-12',
                        $restTimes[$index] ?? 60,
                        $notes[$index] ?? '',
                        $dayNumbers[$index] ?? 1,
                        $index
                    ]);
                }
            }
            
            $message = 'Workout plan updated successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: workout-plans.php?updated=1" . ($memberFilter ? "&member_id=$memberFilter" : ""));
            exit;
        } catch (PDOException $e) {
            $message = 'Error updating workout plan: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete_plan'])) {
        // Delete workout plan
        try {
            $planId = $_POST['plan_id'];
            
            // Delete exercises first (foreign key constraint)
            $conn->prepare("DELETE FROM workout_exercises WHERE workout_plan_id = ?")->execute([$planId]);
            
            // Delete the plan
            $conn->prepare("DELETE FROM workout_plans WHERE id = ? AND trainer_id = ?")->execute([$planId, $userId]);
            
            $message = 'Workout plan deleted successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: workout-plans.php?deleted=1" . ($memberFilter ? "&member_id=$memberFilter" : ""));
            exit;
        } catch (PDOException $e) {
            $message = 'Error deleting workout plan: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['duplicate_plan'])) {
        // Duplicate workout plan
        try {
            $planId = $_POST['plan_id'];
            
            // Get the original plan
            $stmt = $conn->prepare("SELECT * FROM workout_plans WHERE id = ? AND trainer_id = ?");
            $stmt->execute([$planId, $userId]);
            $originalPlan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($originalPlan) {
                // Create a new plan with the same details
                $stmt = $conn->prepare("
                    INSERT INTO workout_plans 
                    (trainer_id, member_id, title, description, difficulty, duration, frequency, is_template) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $newTitle = $originalPlan['title'] . ' (Copy)';
                $stmt->execute([
                    $userId,
                    $originalPlan['member_id'],
                    $newTitle,
                    $originalPlan['description'],
                    $originalPlan['difficulty'],
                    $originalPlan['duration'],
                    $originalPlan['frequency'],
                    $originalPlan['is_template']
                ]);
                
                $newPlanId = $conn->lastInsertId();
                
                // Copy exercises
                $stmt = $conn->prepare("SELECT * FROM workout_exercises WHERE workout_plan_id = ? ORDER BY day_number, exercise_order");
                $stmt->execute([$planId]);
                $exercises = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($exercises)) {
                    $exerciseStmt = $conn->prepare("
                        INSERT INTO workout_exercises 
                        (workout_plan_id, exercise_name, sets, reps, rest_time, notes, day_number, exercise_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($exercises as $exercise) {
                        $exerciseStmt->execute([
                            $newPlanId,
                            $exercise['exercise_name'],
                            $exercise['sets'],
                            $exercise['reps'],
                            $exercise['rest_time'],
                            $exercise['notes'],
                            $exercise['day_number'],
                            $exercise['exercise_order']
                        ]);
                    }
                }
                
                $message = 'Workout plan duplicated successfully!';
                $messageType = 'success';
                
                // Redirect to prevent form resubmission
                header("Location: workout-plans.php?duplicated=1" . ($memberFilter ? "&member_id=$memberFilter" : ""));
                exit;
            } else {
                $message = 'Workout plan not found.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Error duplicating workout plan: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check for URL parameters
if (isset($_GET['created']) && $_GET['created'] == '1') {
    $message = 'Workout plan created successfully!';
    $messageType = 'success';
}

if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $message = 'Workout plan updated successfully!';
    $messageType = 'success';
}

if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $message = 'Workout plan deleted successfully!';
    $messageType = 'success';
}

if (isset($_GET['duplicated']) && $_GET['duplicated'] == '1') {
    $message = 'Workout plan duplicated successfully!';
    $messageType = 'success';
}

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Get difficulty badge class
function getDifficultyClass($difficulty) {
    switch (strtolower($difficulty)) {
        case 'beginner':
            return 'success';
        case 'intermediate':
            return 'warning';
        case 'advanced':
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
    <title>Workout Plans - EliteFit Gym</title>
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
                    <li><a href="workout-plans.php" class="active"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
                    <li><a href="schedule.php"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
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
                    <h1>Workout Plans</h1>
                    <p>Create and manage workout plans for your clients</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" data-modal="addPlanModal">
                        <i class="fas fa-plus"></i> New Workout Plan
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
            
            <!-- Filters -->
            <div class="card">
                <div class="card-content">
                    <div class="filters">
                        <div class="filter-group">
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
                        
                        <div class="filter-group">
                            <label for="plan-search">Search Plans:</label>
                            <div class="search-box">
                                <input type="text" id="plan-search" placeholder="Search by title or description...">
                                <i class="fas fa-search"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Workout Plans -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-dumbbell"></i> Your Workout Plans</h2>
                    <div class="card-actions">
                        <button class="btn btn-sm btn-outline" data-modal="addPlanModal">
                            <i class="fas fa-plus"></i> New Plan
                        </button>
                    </div>
                </div>
                <div class="card-content">
                    <?php if (empty($workoutPlans)): ?>
                        <div class="empty-state">
                            <i class="fas fa-dumbbell"></i>
                            <p>No workout plans created yet</p>
                            <button class="btn btn-primary" data-modal="addPlanModal">Create Your First Workout Plan</button>
                        </div>
                    <?php else: ?>
                        <div class="workout-plans-grid">
                            <?php foreach ($workoutPlans as $plan): ?>
                                <div class="workout-plan-card" data-plan-id="<?php echo $plan['id']; ?>">
                                    <div class="workout-plan-header">
                                        <h3><?php echo htmlspecialchars($plan['title']); ?></h3>
                                        <div class="plan-badges">
                                            <span class="badge badge-<?php echo getDifficultyClass($plan['difficulty']); ?>">
                                                <?php echo ucfirst($plan['difficulty']); ?>
                                            </span>
                                            <?php if ($plan['is_template']): ?>
                                                <span class="badge badge-secondary">Template</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-plan-details">
                                        <?php if (!empty($plan['description'])): ?>
                                            <div class="plan-description">
                                                <?php echo htmlspecialchars($plan['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="plan-stats">
                                            <div class="stat">
                                                <i class="fas fa-calendar-day"></i>
                                                <span><?php echo htmlspecialchars($plan['duration']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-calendar-week"></i>
                                                <span><?php echo htmlspecialchars($plan['frequency']); ?></span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-list"></i>
                                                <span><?php echo $plan['exercise_count']; ?> exercises</span>
                                            </div>
                                            <div class="stat">
                                                <i class="fas fa-layer-group"></i>
                                                <span><?php echo $plan['day_count']; ?> days</span>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($plan['member_name'])): ?>
                                            <div class="plan-member">
                                                <div class="member-info">
                                                    <?php if (!empty($plan['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($plan['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($plan['member_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span>For: <?php echo htmlspecialchars($plan['member_name']); ?></span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="plan-date">
                                            Created: <?php echo formatDate($plan['created_at']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="workout-plan-actions">
                                        <button class="btn btn-sm btn-outline" onclick="viewPlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="editPlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-outline" onclick="duplicatePlan(<?php echo $plan['id']; ?>)">
                                            <i class="fas fa-copy"></i> Duplicate
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="confirmDeletePlan(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['title']); ?>')">
                                            <i class="fas fa-trash"></i> Delete
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
    
    <!-- Add Workout Plan Modal -->
    <div class="modal" id="addPlanModal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Create New Workout Plan</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workout-plans.php" method="post" data-validate>
                    <input type="hidden" name="add_plan" value="1">
                    
                    <div class="form-group">
                        <label for="title">Plan Title</label>
                        <input type="text" id="title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="member_id">Assign to Member (Optional)</label>
                            <select id="member_id" name="member_id" class="form-control">
                                <option value="">-- Select Member --</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="difficulty">Difficulty Level</label>
                            <select id="difficulty" name="difficulty" class="form-control">
                                <option value="beginner">Beginner</option>
                                <option value="intermediate" selected>Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="duration">Duration</label>
                            <select id="duration" name="duration" class="form-control">
                                <option value="1 week">1 week</option>
                                <option value="2 weeks">2 weeks</option>
                                <option value="4 weeks" selected>4 weeks</option>
                                <option value="8 weeks">8 weeks</option>
                                <option value="12 weeks">12 weeks</option>
                                <option value="ongoing">Ongoing</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="frequency">Frequency</label>
                            <select id="frequency" name="frequency" class="form-control">
                                <option value="1 day/week">1 day/week</option>
                                <option value="2 days/week">2 days/week</option>
                                <option value="3 days/week" selected>3 days/week</option>
                                <option value="4 days/week">4 days/week</option>
                                <option value="5 days/week">5 days/week</option>
                                <option value="6 days/week">6 days/week</option>
                                <option value="daily">Daily</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_template" name="is_template">
                            <label for="is_template">Save as Template</label>
                        </div>
                        <div class="form-text">Templates can be reused for multiple clients.</div>
                    </div>
                    
                    <h4>Exercises</h4>
                    <div id="exercisesContainer">
                        <!-- Exercise items will be added here -->
                        <div class="exercise-item">
                            <div class="form-group">
                                <label for="day_number_1">Day Number</label>
                                <select name="day_number[]" class="form-control">
                                    <option value="1">Day 1</option>
                                    <option value="2">Day 2</option>
                                    <option value="3">Day 3</option>
                                    <option value="4">Day 4</option>
                                    <option value="5">Day 5</option>
                                    <option value="6">Day 6</option>
                                    <option value="7">Day 7</option>
                                </select>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Exercise Name</label>
                                    <input type="text" name="exercise_name[]" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Sets</label>
                                    <input type="number" name="sets[]" class="form-control" min="1" value="3" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Reps</label>
                                    <input type="text" name="reps[]" class="form-control" value="10-12" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Rest Time (seconds)</label>
                                    <input type="number" name="rest_time[]" class="form-control" min="0" value="60" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="exercise_notes[]" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" class="btn btn-outline" id="addExerciseBtn">
                            <i class="fas fa-plus"></i> Add Exercise
                        </button>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Workout Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Workout Plan Modal -->
    <div class="modal" id="viewPlanModal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3 id="viewPlanTitle">Workout Plan Details</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="viewPlanContent">
                    <!-- Plan details will be loaded here -->
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>Loading plan details...</span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline close-modal">Close</button>
                    <button type="button" class="btn btn-primary" id="editPlanBtn">Edit Plan</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Workout Plan Modal -->
    <div class="modal" id="editPlanModal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Edit Workout Plan</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="workout-plans.php" method="post" data-validate id="editPlanForm">
                    <input type="hidden" name="update_plan" value="1">
                    <input type="hidden" id="edit_plan_id" name="plan_id" value="">
                    
                    <div class="form-group">
                        <label for="edit_title">Plan Title</label>
                        <input type="text" id="edit_title" name="title" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Description (Optional)</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_member_id">Assign to Member (Optional)</label>
                            <select id="edit_member_id" name="member_id" class="form-control">
                                <option value="">-- Select Member --</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo htmlspecialchars($member['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_difficulty">Difficulty Level</label>
                            <select id="edit_difficulty" name="difficulty" class="form-control">
                                <option value="beginner">Beginner</option>
                                <option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_duration">Duration</label>
                            <select id="edit_duration" name="duration" class="form-control">
                                <option value="1 week">1 week</option>
                                <option value="2 weeks">2 weeks</option>
                                <option value="4 weeks">4 weeks</option>
                                <option value="8 weeks">8 weeks</option>
                                <option value="12 weeks">12 weeks</option>
                                <option value="ongoing">Ongoing</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_frequency">Frequency</label>
                            <select id="edit_frequency" name="frequency" class="form-control">
                                <option value="1 day/week">1 day/week</option>
                                <option value="2 days/week">2 days/week</option>
                                <option value="3 days/week">3 days/week</option>
                                <option value="4 days/week">4 days/week</option>
                                <option value="5 days/week">5 days/week</option>
                                <option value="6 days/week">6 days/week</option>
                                <option value="daily">Daily</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="edit_is_template" name="is_template">
                            <label for="edit_is_template">Save as Template</label>
                        </div>
                        <div class="form-text">Templates can be reused for multiple clients.</div>
                    </div>
                    
                    <h4>Exercises</h4>
                    <div id="editExercisesContainer">
                        <!-- Exercise items will be loaded here -->
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>Loading exercises...</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" class="btn btn-outline" id="editAddExerciseBtn">
                            <i class="fas fa-plus"></i> Add Exercise
                        </button>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Workout Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deletePlanModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the workout plan "<span id="planTitleToDelete"></span>"?</p>
                <p>This action cannot be undone and will remove all exercises associated with this plan.</p>
                
                <form action="workout-plans.php" method="post">
                    <input type="hidden" name="delete_plan" value="1">
                    <input type="hidden" id="planIdToDelete" name="plan_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Duplicate Plan Form (Hidden) -->
    <form id="duplicatePlanForm" action="workout-plans.php" method="post" style="display: none;">
        <input type="hidden" name="duplicate_plan" value="1">
        <input type="hidden" id="duplicatePlanId" name="plan_id" value="">
    </form>

    <script src="../assets/js/trainer-dashboard.js"></script>
    <script>
        // Member filter
        document.getElementById('member-filter').addEventListener('change', function() {
            const memberId = this.value;
            window.location.href = `workout-plans.php${memberId > 0 ? '?member_id=' + memberId : ''}`;
        });
        
        // Search functionality
        document.getElementById('plan-search').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const planCards = document.querySelectorAll('.workout-plan-card');
            
            planCards.forEach(card => {
                const title = card.querySelector('h3').textContent.toLowerCase();
                const description = card.querySelector('.plan-description')?.textContent.toLowerCase() || '';
                
                if (title.includes(searchValue) || description.includes(searchValue)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // Add exercise button
        document.getElementById('addExerciseBtn').addEventListener('click', function() {
            addExerciseItem('exercisesContainer');
        });
        
        // Edit add exercise button
        document.getElementById('editAddExerciseBtn').addEventListener('click', function() {
            addExerciseItem('editExercisesContainer');
        });
        
        // Function to add exercise item
        function addExerciseItem(containerId) {
            const container = document.getElementById(containerId);
            const exerciseCount = container.querySelectorAll('.exercise-item').length;
            
            const exerciseItem = document.createElement('div');
            exerciseItem.className = 'exercise-item';
            exerciseItem.innerHTML = `
                <div class="form-group">
                    <label for="day_number_${exerciseCount + 1}">Day Number</label>
                    <select name="day_number[]" class="form-control">
                        <option value="1">Day 1</option>
                        <option value="2">Day 2</option>
                        <option value="3">Day 3</option>
                        <option value="4">Day 4</option>
                        <option value="5">Day 5</option>
                        <option value="6">Day 6</option>
                        <option value="7">Day 7</option>
                    </select>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Exercise Name</label>
                        <input type="text" name="exercise_name[]" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Sets</label>
                        <input type="number" name="sets[]" class="form-control" min="1" value="3" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Reps</label>
                        <input type="text" name="reps[]" class="form-control" value="10-12" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Rest Time (seconds)</label>
                        <input type="number" name="rest_time[]" class="form-control" min="0" value="60" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="exercise_notes[]" class="form-control" rows="2"></textarea>
                </div>
                
                <button type="button" class="btn btn-sm btn-danger remove-exercise">
                    <i class="fas fa-trash"></i> Remove
                </button>
                
                <hr style="margin: 20px 0;">
            `;
            
            container.appendChild(exerciseItem);
            
            // Add event listener to the new remove button
            const removeBtn = exerciseItem.querySelector('.remove-exercise');
            if (removeBtn) {
                removeBtn.addEventListener('click', function() {
                    container.removeChild(exerciseItem);
                });
            }
        }
        
        // View workout plan
        function viewPlan(planId) {
            const modal = document.getElementById('viewPlanModal');
            const content = document.getElementById('viewPlanContent');
            const editBtn = document.getElementById('editPlanBtn');
            
            // Show loading spinner
            content.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span>Loading plan details...</span>
                </div>
            `;
            
            // Open the modal
            openModal(modal);
            
            // Fetch plan details
            fetch(`get_workout_plan.php?id=${planId}`)
                .then(response => response.json())
                .then(data => {
                    // Update modal title
                    document.getElementById('viewPlanTitle').textContent = data.plan.title;
                    
                    // Set up edit button
                    editBtn.onclick = function() {
                        closeModal(modal);
                        editPlan(planId);
                    };
                    
                    // Build the content
                    let html = `
                        <div class="plan-details">
                            <div class="plan-header">
                                <div class="plan-badges">
                                    <span class="badge badge-${getDifficultyClass(data.plan.difficulty)}">
                                        ${data.plan.difficulty}
                                    </span>
                                    ${data.plan.is_template ? '<span class="badge badge-secondary">Template</span>' : ''}
                                </div>
                                
                                <div class="plan-stats">
                                    <div class="stat">
                                        <i class="fas fa-calendar-day"></i>
                                        <span>${data.plan.duration}</span>
                                    </div>
                                    <div class="stat">
                                        <i class="fas fa-calendar-week"></i>
                                        <span>${data.plan.frequency}</span>
                                    </div>
                                </div>
                            </div>
                            
                            ${data.plan.description ? `<div class="plan-description">${data.plan.description}</div>` : ''}
                            
                            ${data.plan.member_name ? `
                                <div class="plan-member">
                                    <div class="member-info">
                                        ${data.plan.profile_image ? `
                                            <img src="${data.plan.profile_image}" alt="Profile" class="member-avatar">
                                        ` : `
                                            <div class="member-avatar-placeholder">
                                                ${data.plan.member_name.charAt(0).toUpperCase()}
                                            </div>
                                        `}
                                        <span>For: ${data.plan.member_name}</span>
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                    `;
                    
                    // Group exercises by day
                    const exercisesByDay = {};
                    data.exercises.forEach(exercise => {
                        if (!exercisesByDay[exercise.day_number]) {
                            exercisesByDay[exercise.day_number] = [];
                        }
                        exercisesByDay[exercise.day_number].push(exercise);
                    });
                    
                    // Add exercises by day
                    html += '<div class="workout-days">';
                    
                    Object.keys(exercisesByDay).sort((a, b) => a - b).forEach(day => {
                        html += `
                            <div class="workout-day">
                                <h4>Day ${day}</h4>
                                <div class="exercises-list">
                        `;
                        
                        exercisesByDay[day].forEach(exercise => {
                            html += `
                                <div class="exercise">
                                    <div class="exercise-header">
                                        <h5>${exercise.exercise_name}</h5>
                                        <div class="exercise-meta">
                                            <span>${exercise.sets} sets</span>
                                            <span>${exercise.reps} reps</span>
                                            <span>${exercise.rest_time}s rest</span>
                                        </div>
                                    </div>
                                    ${exercise.notes ? `<div class="exercise-notes">${exercise.notes}</div>` : ''}
                                </div>
                            `;
                        });
                        
                        html += `
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    
                    // Update content
                    content.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error fetching plan:', error);
                    content.innerHTML = `
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <p>Error loading workout plan details. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        // Edit workout plan
        function editPlan(planId) {
            const modal = document.getElementById('editPlanModal');
            const container = document.getElementById('editExercisesContainer');
            
            // Show loading spinner
            container.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span>Loading exercises...</span>
                </div>
            `;
            
            // Set plan ID
            document.getElementById('edit_plan_id').value = planId;
            
            // Open the modal
            openModal(modal);
            
            // Fetch plan details
            fetch(`get_workout_plan.php?id=${planId}`)
                .then(response => response.json())
                .then(data => {
                    // Populate form fields
                    document.getElementById('edit_title').value = data.plan.title;
                    document.getElementById('edit_description').value = data.plan.description || '';
                    document.getElementById('edit_member_id').value = data.plan.member_id || '';
                    document.getElementById('edit_difficulty').value = data.plan.difficulty;
                    document.getElementById('edit_duration').value = data.plan.duration;
                    document.getElementById('edit_frequency').value = data.plan.frequency;
                    document.getElementById('edit_is_template').checked = data.plan.is_template == 1;
                    
                    // Clear exercises container
                    container.innerHTML = '';
                    
                    // Add exercises
                    data.exercises.forEach(exercise => {
                        const exerciseItem = document.createElement('div');
                        exerciseItem.className = 'exercise-item';
                        exerciseItem.innerHTML = `
                            <div class="form-group">
                                <label>Day Number</label>
                                <select name="day_number[]" class="form-control">
                                    <option value="1" ${exercise.day_number == 1 ? 'selected' : ''}>Day 1</option>
                                    <option value="2" ${exercise.day_number == 2 ? 'selected' : ''}>Day 2</option>
                                    <option value="3" ${exercise.day_number == 3 ? 'selected' : ''}>Day 3</option>
                                    <option value="4" ${exercise.day_number == 4 ? 'selected' : ''}>Day 4</option>
                                    <option value="5" ${exercise.day_number == 5 ? 'selected' : ''}>Day 5</option>
                                    <option value="6" ${exercise.day_number == 6 ? 'selected' : ''}>Day 6</option>
                                    <option value="7" ${exercise.day_number == 7 ? 'selected' : ''}>Day 7</option>
                                </select>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Exercise Name</label>
                                    <input type="text" name="exercise_name[]" class="form-control" value="${exercise.exercise_name}" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Sets</label>
                                    <input type="number" name="sets[]" class="form-control" min="1" value="${exercise.sets}" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Reps</label>
                                    <input type="text" name="reps[]" class="form-control" value="${exercise.reps}" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Rest Time (seconds)</label>
                                    <input type="number" name="rest_time[]" class="form-control" min="0" value="${exercise.rest_time}" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="exercise_notes[]" class="form-control" rows="2">${exercise.notes || ''}</textarea>
                            </div>
                            
                            <button type="button" class="btn btn-sm btn-danger remove-exercise">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                            
                            <hr style="margin: 20px 0;">
                        `;
                        
                        container.appendChild(exerciseItem);
                        
                        // Add event listener to the remove button
                        const removeBtn = exerciseItem.querySelector('.remove-exercise');
                        if (removeBtn) {
                            removeBtn.addEventListener('click', function() {
                                container.removeChild(exerciseItem);
                            });
                        }
                    });
                    
                    // If no exercises, add an empty one
                    if (data.exercises.length === 0) {
                        addExerciseItem('editExercisesContainer');
                    }
                })
                .catch(error => {
                    console.error('Error fetching plan:', error);
                    container.innerHTML = `
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <p>Error loading workout plan details. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        // Confirm delete plan
        function confirmDeletePlan(planId, planTitle) {
            document.getElementById('planIdToDelete').value = planId;
            document.getElementById('planTitleToDelete').textContent = planTitle;
            
            const modal = document.getElementById('deletePlanModal');
            if (modal) {
                openModal(modal);
            }
        }
        
        // Duplicate plan
        function duplicatePlan(planId) {
            if (confirm('Are you sure you want to duplicate this workout plan?')) {
                document.getElementById('duplicatePlanId').value = planId;
                document.getElementById('duplicatePlanForm').submit();
            }
        }
        
        // Helper function to get difficulty class
        function getDifficultyClass(difficulty) {
            switch (difficulty.toLowerCase()) {
                case 'beginner':
                    return 'success';
                case 'intermediate':
                    return 'warning';
                case 'advanced':
                    return 'danger';
                default:
                    return 'secondary';
            }
        }
    </script>
</body>
</html>
