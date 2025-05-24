<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userEmail = $_SESSION['email'] ?? '';
$profileImage = $_SESSION['profile_image'] ?? '';

// Connect to database
$conn = connectDB();

// Include theme preference helper
require_once 'member-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Get user's measurement unit preference
$measurementUnit = 'metric'; // Default
try {
    $stmt = $conn->prepare("SELECT measurement_unit FROM member_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $measurementUnit = $result['measurement_unit'];
    }
} catch (PDOException $e) {
    // Use default
}

// Get user profile data
$profile = [
    'name' => $userName,
    'email' => $userEmail,
    'phone' => '',
    'height' => '',
    'weight' => '',
    'date_of_birth' => '',
    'experience_level' => '',
    'fitness_goals' => '',
    'preferred_routines' => '',
    'profile_image' => $profileImage,
    'join_date' => date('Y-m-d')
];

try {
    // Get profile data from users table
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userData) {
        // Update profile with values from database
        $profile['name'] = $userData['name'];
        $profile['email'] = $userData['email'];
        $profile['phone'] = $userData['phone'] ?? '';
        $profile['height'] = $userData['height'] ?? '';
        $profile['weight'] = $userData['weight'] ?? '';
        $profile['date_of_birth'] = $userData['date_of_birth'] ?? '';
        $profile['experience_level'] = $userData['experience_level'] ?? '';
        $profile['fitness_goals'] = $userData['fitness_goals'] ?? '';
        $profile['preferred_routines'] = $userData['preferred_routines'] ?? '';
        $profile['profile_image'] = $userData['profile_image'] ?? '';
        $profile['join_date'] = $userData['join_date'] ?? date('Y-m-d');
    }
} catch (PDOException $e) {
    // Handle error - default profile already set
}

// Calculate age if date of birth is available
$age = '';
if (!empty($profile['date_of_birth'])) {
    $dob = new DateTime($profile['date_of_birth']);
    $now = new DateTime();
    $interval = $now->diff($dob);
    $age = $interval->y;
}

// Format height and weight based on measurement unit
$formattedHeight = '';
$formattedWeight = '';

if (!empty($profile['height'])) {
    if ($measurementUnit === 'imperial') {
        // Convert cm to feet and inches
        $heightInches = $profile['height'] / 2.54;
        $feet = floor($heightInches / 12);
        $inches = round($heightInches % 12);
        $formattedHeight = $feet . "' " . $inches . '"';
    } else {
        $formattedHeight = $profile['height'] . ' cm';
    }
}

if (!empty($profile['weight'])) {
    if ($measurementUnit === 'imperial') {
        // Convert kg to lbs
        $weightLbs = round($profile['weight'] * 2.20462);
        $formattedWeight = $weightLbs . ' lbs';
    } else {
        $formattedWeight = $profile['weight'] . ' kg';
    }
}

// Get workout stats
$workoutStats = [
    'total_workouts' => 0,
    'total_hours' => 0,
    'streak' => 0
];

try {
    // Check if workout_logs table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'workout_logs'")->rowCount() > 0;
    
    if ($tableExists) {
        // Get total workouts
        $stmt = $conn->prepare("SELECT COUNT(*) FROM workout_logs WHERE user_id = ?");
        $stmt->execute([$userId]);
        $workoutStats['total_workouts'] = $stmt->fetchColumn();
        
        // Get total hours
        $stmt = $conn->prepare("SELECT SUM(duration_minutes) FROM workout_logs WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalMinutes = $stmt->fetchColumn() ?: 0;
        $workoutStats['total_hours'] = round($totalMinutes / 60, 1);
        
        // Calculate streak
        // This is a simplified version - a real implementation would be more complex
        $stmt = $conn->prepare("
            SELECT workout_date FROM workout_logs 
            WHERE user_id = ? 
            ORDER BY workout_date DESC
        ");
        $stmt->execute([$userId]);
        $workoutDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($workoutDates)) {
            $streak = 0;
            $today = new DateTime();
            $yesterday = clone $today;
            $yesterday->modify('-1 day');
            
            // Check if worked out today or yesterday
            $lastWorkoutDate = new DateTime($workoutDates[0]);
            if ($lastWorkoutDate->format('Y-m-d') === $today->format('Y-m-d') || 
                $lastWorkoutDate->format('Y-m-d') === $yesterday->format('Y-m-d')) {
                $streak = 1;
                
                // Check consecutive days before yesterday
                $checkDate = clone $yesterday;
                $checkDate->modify('-1 day');
                
                // Alternative approach if count() function is problematic
        $workoutDatesCount = 0;
        if (is_array($workoutDates)) {
            foreach ($workoutDates as $date) {
                    $workoutDatesCount++;
        }
    
            for ($i = 1; $i < $workoutDatesCount; $i++) {
            $workoutDate = new DateTime($workoutDates[$i]);
            if ($workoutDate->format('Y-m-d') === $checkDate->format('Y-m-d')) {
            $streak++;
            $checkDate->modify('-1 day');
        } else {
            break;
        }
    }
}
            }
            
            $workoutStats['streak'] = $streak;
        }
    }
} catch (PDOException $e) {
    // Handle error
}

// Get achievements
$achievements = [];

try {
    // Check if achievements table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'achievements'")->rowCount() > 0;
    
    if ($tableExists) {
        // Get user achievements
        $stmt = $conn->prepare("
            SELECT a.* FROM achievements a
            JOIN user_achievements ua ON a.id = ua.achievement_id
            WHERE ua.user_id = ?
            ORDER BY ua.achieved_date DESC
        ");
        $stmt->execute([$userId]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Create sample achievements for display
        $achievements = [
            [
                'name' => 'First Workout',
                'icon' => 'fa-dumbbell',
                'achieved_date' => date('Y-m-d', strtotime('-30 days'))
            ],
            [
                'name' => 'Weight Goal',
                'icon' => 'fa-weight',
                'achieved_date' => date('Y-m-d', strtotime('-15 days'))
            ],
            [
                'name' => '10 Workouts',
                'icon' => 'fa-fire',
                'achieved_date' => date('Y-m-d', strtotime('-7 days'))
            ]
        ];
    }
} catch (PDOException $e) {
    // Handle error
}

// Get upcoming appointments
$appointments = [];

try {
    // Check if appointments table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'appointments'")->rowCount() > 0;
    
    if ($tableExists) {
        // Get upcoming appointments
        $stmt = $conn->prepare("
            SELECT a.*, t.name as trainer_name, t.profile_image as trainer_image
            FROM appointments a
            LEFT JOIN users t ON a.trainer_id = t.id
            WHERE a.user_id = ? AND a.appointment_date >= CURDATE()
            ORDER BY a.appointment_date, a.appointment_time
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - EliteFit Gym</title>
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
                <li><a href="trainers.php"><i class="fas fa-user-friends"></i> <span>Trainers</span></a></li>
                <li><a href="profile.php" class="active"><i class="fas fa-user"></i> <span>Profile</span></a></li>
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
            
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php if (!empty($profile['profile_image'])): ?>
                        <img src="<?php echo htmlspecialchars($profile['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($profile['name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h1 class="profile-name"><?php echo htmlspecialchars($profile['name']); ?></h1>
                <div class="profile-role">Member since <?php echo date('F Y', strtotime($profile['join_date'])); ?></div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $workoutStats['total_workouts']; ?></div>
                        <div class="stat-text">Workouts</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $workoutStats['total_hours']; ?></div>
                        <div class="stat-text">Hours</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $workoutStats['streak']; ?></div>
                        <div class="stat-text">Day Streak</div>
                    </div>
                </div>
                
                <?php if (!empty($profile['fitness_goals'])): ?>
                    <p class="profile-bio"><?php echo htmlspecialchars($profile['fitness_goals']); ?></p>
                <?php endif; ?>
                
                <a href="settings.php" class="btn btn-outline">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
            </div>
            
            <!-- Profile Details -->
            <div class="profile-details">
                <!-- Personal Information -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                    </div>
                    <div class="detail-content">
                        <div class="detail-item">
                            <div class="detail-label">Email</div>
                            <div class="detail-value"><?php echo htmlspecialchars($profile['email']); ?></div>
                        </div>
                        
                        <?php if (!empty($profile['phone'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value"><?php echo htmlspecialchars($profile['phone']); ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($age)): ?>
                            <div class="detail-item">
                                <div class="detail-label">Age</div>
                                <div class="detail-value"><?php echo $age; ?> years</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($formattedHeight)): ?>
                            <div class="detail-item">
                                <div class="detail-label">Height</div>
                                <div class="detail-value"><?php echo $formattedHeight; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($formattedWeight)): ?>
                            <div class="detail-item">
                                <div class="detail-label">Weight</div>
                                <div class="detail-value"><?php echo $formattedWeight; ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Fitness Profile -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3><i class="fas fa-heartbeat"></i> Fitness Profile</h3>
                    </div>
                    <div class="detail-content">
                        <div class="detail-item">
                            <div class="detail-label">Experience Level</div>
                            <div class="detail-value">
                                <?php echo !empty($profile['experience_level']) ? htmlspecialchars($profile['experience_level']) : 'Not specified'; ?>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Fitness Goals</div>
                            <div class="detail-value">
                                <?php if (!empty($profile['fitness_goals'])): ?>
                                    <?php echo htmlspecialchars($profile['fitness_goals']); ?>
                                <?php else: ?>
                                    Not specified
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Preferred Workouts</div>
                            <div class="detail-value">
                                <?php if (!empty($profile['preferred_routines'])): ?>
                                    <?php 
                                    $routines = explode(',', $profile['preferred_routines']);
                                    foreach ($routines as $routine): 
                                    ?>
                                        <span class="badge badge-primary"><?php echo htmlspecialchars(trim($routine)); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    Not specified
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Achievements -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3><i class="fas fa-trophy"></i> Achievements</h3>
                    </div>
                    <div class="detail-content">
                        <?php if (empty($achievements)): ?>
                            <p>No achievements yet. Keep working out to earn badges!</p>
                        <?php else: ?>
                            <div class="achievement-list">
                                <?php foreach ($achievements as $achievement): ?>
                                    <div class="achievement">
                                        <div class="achievement-icon">
                                            <i class="fas <?php echo $achievement['icon'] ?? 'fa-award'; ?>"></i>
                                        </div>
                                        <div class="achievement-name"><?php echo htmlspecialchars($achievement['name']); ?></div>
                                        <div class="achievement-date">
                                            <?php echo date('M d, Y', strtotime($achievement['achieved_date'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Upcoming Appointments -->
                <div class="detail-card">
                    <div class="detail-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Appointments</h3>
                        <a href="appointments.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="detail-content">
                        <?php if (empty($appointments)): ?>
                            <p>No upcoming appointments. <a href="appointments.php">Schedule one now</a>.</p>
                        <?php else: ?>
                            <?php foreach ($appointments as $appointment): ?>
                                <div class="appointment-item">
                                    <div class="appointment-date">
                                        <div class="date-day"><?php echo date('d', strtotime($appointment['appointment_date'])); ?></div>
                                        <div class="date-month"><?php echo date('M', strtotime($appointment['appointment_date'])); ?></div>
                                    </div>
                                    <div class="appointment-details">
                                        <div class="appointment-title"><?php echo htmlspecialchars($appointment['appointment_type']); ?></div>
                                        <div class="appointment-time">
                                            <i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?>
                                        </div>
                                        <div class="appointment-trainer">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($appointment['trainer_name'] ?? 'No trainer assigned'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
    </script>
</body>
</html>