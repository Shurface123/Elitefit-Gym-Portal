<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Helper functions to replace count()
function array_has_items($array) {
    if (!isset($array) || !is_array($array)) {
        return false;
    }
    
    foreach ($array as $item) {
        return true; // If we get here, there's at least one item
    }
    return false; // No items found
}

function array_count($array) {
    if (!isset($array) || !is_array($array)) {
        return 0;
    }
    
    $count = 0;
    foreach ($array as $item) {
        $count++;
    }
    return $count;
}

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

// Get basic member details first (only columns we know exist)
$stmt = $conn->prepare("
  SELECT 
      id,
      name,
      email,
      experience_level, 
      fitness_goals, 
      preferred_routines
  FROM users 
  WHERE id = ?
");
$stmt->execute([$userId]);
$memberDetails = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if the users table has the necessary columns, if not, alter the table
try {
    // Check if height column exists
    $heightExists = $conn->query("SHOW COLUMNS FROM users LIKE 'height'")->rowCount() > 0;
    if (!$heightExists) {
        $conn->exec("ALTER TABLE users ADD COLUMN height VARCHAR(20) DEFAULT NULL");
    }
    
    // Check if weight column exists
    $weightExists = $conn->query("SHOW COLUMNS FROM users LIKE 'weight'")->rowCount() > 0;
    if (!$weightExists) {
        $conn->exec("ALTER TABLE users ADD COLUMN weight DECIMAL(5,2) DEFAULT NULL");
    }
    
    // Check if date_of_birth column exists
    $dobExists = $conn->query("SHOW COLUMNS FROM users LIKE 'date_of_birth'")->rowCount() > 0;
    if (!$dobExists) {
        $conn->exec("ALTER TABLE users ADD COLUMN date_of_birth DATE DEFAULT NULL");
    }
    
    // Check if join_date column exists
    $joinDateExists = $conn->query("SHOW COLUMNS FROM users LIKE 'join_date'")->rowCount() > 0;
    if (!$joinDateExists) {
        $conn->exec("ALTER TABLE users ADD COLUMN join_date DATE DEFAULT CURRENT_DATE");
        // Update the current user's join_date if it's null
        $conn->prepare("UPDATE users SET join_date = CURRENT_DATE WHERE id = ? AND join_date IS NULL")->execute([$userId]);
    }
    
    // Now that we've ensured all columns exist, get the additional data
    if ($heightExists || $weightExists || $dobExists || $joinDateExists) {
        $additionalColumns = [];
        if ($heightExists) $additionalColumns[] = "height";
        if ($weightExists) $additionalColumns[] = "weight";
        if ($dobExists) $additionalColumns[] = "date_of_birth";
        if ($joinDateExists) $additionalColumns[] = "join_date";
        
        if (!empty($additionalColumns)) {
            $columnsStr = implode(", ", $additionalColumns);
            $stmt = $conn->prepare("SELECT $columnsStr FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $additionalData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Merge the additional data with member details
            // Add this helper function at the top of your file along with the other helper functions
function custom_array_merge($array1, $array2) {
    if (!is_array($array1) || !is_array($array2)) {
        return is_array($array1) ? $array1 : (is_array($array2) ? $array2 : []);
    }
    
    $result = $array1;
    foreach ($array2 as $key => $value) {
        $result[$key] = $value;
    }
    
    return $result;
}

// Then replace the array_merge call with:
if ($additionalData) {
    $memberDetails = custom_array_merge($memberDetails, $additionalData);
}
        }
    }
} catch (PDOException $e) {
    // Log the error but continue execution
    error_log('Error checking or adding columns: ' . $e->getMessage());
}

// Set default values for missing fields
$memberDetails['height'] = $memberDetails['height'] ?? 'Not specified';
$memberDetails['weight'] = $memberDetails['weight'] ?? 'Not specified';
$memberDetails['date_of_birth'] = $memberDetails['date_of_birth'] ?? null;
$memberDetails['join_date'] = $memberDetails['join_date'] ?? date('Y-m-d');

// Calculate membership duration
$joinDate = new DateTime($memberDetails['join_date']);
$now = new DateTime();
$membershipDuration = $joinDate->diff($now);

// Get assigned workouts - FIXED: Removed wp.difficulty column
$workoutStmt = $conn->prepare("
   SELECT wp.id, wp.title, wp.description, wp.created_at,
       u.name as trainer_name, u.profile_image as trainer_image,
       (SELECT COUNT(*) FROM workout_exercises WHERE workout_id = wp.id) as exercise_count
FROM workout_plans wp
JOIN users u ON wp.trainer_id = u.id
WHERE wp.member_id = ?
ORDER BY wp.created_at DESC
LIMIT 5
");
$workoutStmt->execute([$userId]);
$workouts = $workoutStmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming appointments
$appointmentStmt = $conn->prepare("
   SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
          u.name as trainer_name, u.profile_image as trainer_image
   FROM trainer_schedule ts
   JOIN users u ON ts.trainer_id = u.id
   WHERE ts.member_id = ? AND ts.start_time > NOW() AND ts.status IN ('scheduled', 'confirmed')
   ORDER BY ts.start_time ASC
   LIMIT 3
");
$appointmentStmt->execute([$userId]);
$appointments = $appointmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent progress data
$progressStmt = $conn->prepare("
   SELECT pt.created_at, pt.weight, pt.body_fat
FROM progress_tracking pt
JOIN users u ON pt.trainer_id = u.id
WHERE pt.member_id = ?
ORDER BY pt.created_at DESC
LIMIT 5
");
$progressStmt->execute([$userId]);
$progressData = $progressStmt->fetchAll(PDO::FETCH_ASSOC);

// Format progress data for charts
$chartLabels = [];
$weightData = [];
$bodyFatData = [];
$muscleMassData = [];

foreach (array_reverse($progressData) as $entry) {
   $chartLabels[] = date('M d', strtotime($entry['tracking_date']));
   $weightData[] = $entry['weight'];
   $bodyFatData[] = $entry['body_fat'];
   $muscleMassData[] = $entry['muscle_mass'];
}

// Get notifications
$notificationStmt = $conn->prepare("
   SELECT * FROM member_notifications 
   WHERE member_id = ? AND is_read = 0
   ORDER BY created_at DESC
   LIMIT 5
");
$notificationStmt->execute([$userId]);
$notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);

// Get workout completion stats
$completionStmt = $conn->prepare("
   SELECT COUNT(*) as total_completed
   FROM workout_completion
   WHERE member_id = ?
");
$completionStmt->execute([$userId]);
$completionStats = $completionStmt->fetch(PDO::FETCH_ASSOC);
$totalCompleted = $completionStats['total_completed'] ?? 0;

// Get total trainers
$trainersStmt = $conn->prepare("
   SELECT COUNT(DISTINCT trainer_id) as total_trainers
   FROM trainer_members
   WHERE member_id = ?
");
$trainersStmt->execute([$userId]);
$trainersStats = $trainersStmt->fetch(PDO::FETCH_ASSOC);
$totalTrainers = $trainersStats['total_trainers'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Member Dashboard - EliteFit Gym</title>
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
               <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
               <li><a href="workouts.php"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
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
                   <h1>Welcome, <?php echo htmlspecialchars($userName); ?></h1>
                   <p>Here's an overview of your fitness journey</p>
               </div>
               <div class="header-actions">
                   <div class="notification-bell" id="notificationBell">
                       <?php if (array_has_items($notifications)): ?>
                           <span class="notification-badge"><?php echo array_count($notifications); ?></span>
                       <?php endif; ?>
                       <i class="fas fa-bell"></i>
                       
                       <!-- Notification Dropdown -->
                       <div class="notification-dropdown" id="notificationDropdown">
                           <div class="notification-header">
                               <h3>Notifications</h3>
                               <a href="notifications.php">View All</a>
                           </div>
                           <div class="notification-body">
                               <?php if (array_has_items($notifications)): ?>
                                   <?php foreach ($notifications as $notification): ?>
                                       <div class="notification-item">
                                           <div class="notification-icon">
                                               <i class="fas fa-<?php echo $notification['icon'] ?? 'bell'; ?>"></i>
                                           </div>
                                           <div class="notification-content">
                                               <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                               <span class="notification-time">
                                                   <?php echo timeAgo($notification['created_at']); ?>
                                               </span>
                                           </div>
                                       </div>
                                   <?php endforeach; ?>
                               <?php else: ?>
                                   <div class="notification-empty">
                                       <p>No new notifications</p>
                                   </div>
                               <?php endif; ?>
                           </div>
                       </div>
                   </div>
                   
                   <div class="theme-toggle" id="themeToggle">
                       <i class="fas fa-moon"></i>
                   </div>
               </div>
           </div>
           
           <!-- Stats Cards -->
           <div class="stats-grid">
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-calendar-check"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Member Since</h3>
                       <div class="stat-value"><?php echo formatDate($memberDetails['join_date'] ?? date('Y-m-d')); ?></div>
                       <div class="stat-label">
                           <?php 
                               if ($membershipDuration->y > 0) {
                                   echo $membershipDuration->y . ' year' . ($membershipDuration->y > 1 ? 's' : '');
                               } elseif ($membershipDuration->m > 0) {
                                   echo $membershipDuration->m . ' month' . ($membershipDuration->m > 1 ? 's' : '');
                               } else {
                                   echo $membershipDuration->d . ' day' . ($membershipDuration->d > 1 ? 's' : '');
                               }
                           ?> with us
                       </div>
                   </div>
               </div>
               
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-dumbbell"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Workout Plans</h3>
                       <div class="stat-value"><?php echo array_count($workouts); ?></div>
                       <div class="stat-label">assigned to you</div>
                   </div>
               </div>
               
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-check-circle"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Completed Workouts</h3>
                       <div class="stat-value"><?php echo $totalCompleted; ?></div>
                       <div class="stat-label">great job!</div>
                   </div>
               </div>
               
               <div class="stat-card">
                   <div class="stat-icon">
                       <i class="fas fa-weight"></i>
                   </div>
                   <div class="stat-content">
                       <h3>Current Weight</h3>
                       <div class="stat-value">
                           <?php 
                               echo array_has_items($progressData) && isset($progressData[0]['weight']) 
                                   ? number_format($progressData[0]['weight'], 1) . ' kg' 
                                   : ($memberDetails['weight'] != 'Not specified' ? $memberDetails['weight'] . ' kg' : 'N/A'); 
                           ?>
                       </div>
                       <div class="stat-label">
                           <?php 
                               if (array_count($progressData) >= 2 && isset($progressData[0]['weight'], $progressData[1]['weight'])) {
                                   $weightDiff = $progressData[0]['weight'] - $progressData[1]['weight'];
                                   $direction = $weightDiff < 0 ? 'down' : 'up';
                                   echo abs($weightDiff) > 0 
                                       ? number_format(abs($weightDiff), 1) . ' kg ' . $direction . ' since last check' 
                                       : 'No change since last check';
                               } else {
                                   echo 'No previous data';
                               }
                           ?>
                       </div>
                   </div>
               </div>
           </div>
           
           <!-- Main Dashboard Content -->
           <div class="dashboard-grid">
               <!-- Progress Chart -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-chart-line"></i> Your Progress</h2>
                       <a href="progress.php" class="btn btn-sm">View Details</a>
                   </div>
                   <div class="card-content">
                       <?php if (array_has_items($progressData)): ?>
                           <div class="chart-container active">
                               <canvas id="progressChart"></canvas>
                           </div>
                       <?php else: ?>
                           <div class="empty-state">
                               <i class="fas fa-chart-line"></i>
                               <p>No progress data available yet</p>
                               <p>Your trainer will add your progress measurements soon</p>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
               
               <!-- Fitness Profile -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-user-circle"></i> Fitness Profile</h2>
                       <a href="profile.php" class="btn btn-sm">Edit Profile</a>
                   </div>
                   <div class="card-content">
                       <div class="profile-details">
                           <div class="profile-item">
                               <div class="profile-label">Experience Level</div>
                               <div class="profile-value">
                                   <?php echo htmlspecialchars($memberDetails['experience_level'] ?? 'Not specified'); ?>
                               </div>
                           </div>
                           
                           <div class="profile-item">
                               <div class="profile-label">Fitness Goals</div>
                               <div class="profile-value">
                                   <?php echo htmlspecialchars($memberDetails['fitness_goals'] ?? 'No goals set yet'); ?>
                               </div>
                           </div>
                           
                           <div class="profile-item">
                               <div class="profile-label">Preferred Routines</div>
                               <div class="profile-value">
                                   <?php echo htmlspecialchars($memberDetails['preferred_routines'] ?? 'No preferences set'); ?>
                               </div>
                           </div>
                           
                           <div class="profile-item">
                               <div class="profile-label">Height</div>
                               <div class="profile-value">
                                   <?php echo htmlspecialchars($memberDetails['height'] ?? 'Not specified'); ?>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>
               
               <!-- Upcoming Appointments -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-calendar-alt"></i> Upcoming Appointments</h2>
                       <a href="appointments.php" class="btn btn-sm">View All</a>
                   </div>
                   <div class="card-content">
                       <?php if (array_has_items($appointments)): ?>
                           <div class="appointments-list">
                               <?php foreach ($appointments as $appointment): ?>
                                   <div class="appointment-item">
                                       <div class="appointment-date">
                                           <div class="date"><?php echo formatDate($appointment['start_time']); ?></div>
                                           <div class="time"><?php echo formatTime($appointment['start_time']); ?> - <?php echo formatTime($appointment['end_time']); ?></div>
                                       </div>
                                       
                                       <div class="appointment-details">
                                           <h4><?php echo htmlspecialchars($appointment['title']); ?></h4>
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
                                   </div>
                               <?php endforeach; ?>
                           </div>
                       <?php else: ?>
                           <div class="empty-state">
                               <i class="fas fa-calendar-alt"></i>
                               <p>No upcoming appointments</p>
                               <a href="appointments.php" class="btn">Schedule a Session</a>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
               
               <!-- Recent Workouts -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-dumbbell"></i> My Workout Plans</h2>
                       <a href="workouts.php" class="btn btn-sm">View All</a>
                   </div>
                   <div class="card-content">
                       <?php if (array_has_items($workouts)): ?>
                           <div class="workouts-list">
                               <?php foreach ($workouts as $workout): ?>
                                   <div class="workout-item">
                                       <div class="workout-info">
                                           <h4><?php echo htmlspecialchars($workout['title']); ?></h4>
                                           <div class="workout-meta">
                                               <div class="trainer-info">
                                                   <?php if (!empty($workout['trainer_image'])): ?>
                                                       <img src="<?php echo htmlspecialchars($workout['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                                   <?php else: ?>
                                                       <div class="trainer-avatar-placeholder">
                                                           <?php echo strtoupper(substr($workout['trainer_name'], 0, 1)); ?>
                                                       </div>
                                                   <?php endif; ?>
                                                   <span>by <?php echo htmlspecialchars($workout['trainer_name']); ?></span>
                                               </div>
                                               <div class="workout-stats">
                                                   <span><i class="fas fa-tasks"></i> <?php echo $workout['exercise_count']; ?> exercises</span>
                                                   <span><i class="fas fa-calendar"></i> <?php echo formatDate($workout['created_at']); ?></span>
                                               </div>
                                           </div>
                                       </div>
                                       <div class="workout-actions">
                                           <a href="workout-details.php?id=<?php echo $workout['id']; ?>" class="btn btn-sm">View Details</a>
                                       </div>
                                   </div>
                               <?php endforeach; ?>
                           </div>
                       <?php else: ?>
                           <div class="empty-state">
                               <i class="fas fa-dumbbell"></i>
                               <p>No workout plans assigned yet</p>
                               <a href="workouts.php" class="btn">Request a Workout Plan</a>
                           </div>
                       <?php endif; ?>
                   </div>
               </div>
               
               <!-- Quick Actions -->
               <div class="card">
                   <div class="card-header">
                       <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                   </div>
                   <div class="card-content">
                       <div class="quick-actions">
                           <a href="appointments.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-calendar-plus"></i>
                               </div>
                               <div class="quick-action-text">Book a Session</div>
                           </a>
                           
                           <a href="workouts.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-dumbbell"></i>
                               </div>
                               <div class="quick-action-text">Request Workout</div>
                           </a>
                           
                           <a href="progress.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-weight"></i>
                               </div>
                               <div class="quick-action-text">Log Progress</div>
                           </a>
                           
                           <a href="trainers.php" class="quick-action-item">
                               <div class="quick-action-icon">
                                   <i class="fas fa-user-friends"></i>
                               </div>
                               <div class="quick-action-text">View Trainers</div>
                           </a>
                       </div>
                   </div>
               </div>
           </div>
       </div>
   </div>
   
   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <script>
       // Mobile menu toggle
       document.getElementById('mobileMenuToggle').addEventListener('click', function() {
           document.getElementById('sidebar').classList.toggle('show');
       });
       
       // Notification dropdown toggle
       document.getElementById('notificationBell').addEventListener('click', function(e) {
           e.stopPropagation();
           document.getElementById('notificationDropdown').classList.toggle('show');
       });
       
       // Close notification dropdown when clicking outside
       document.addEventListener('click', function(e) {
           if (!document.getElementById('notificationBell').contains(e.target)) {
               document.getElementById('notificationDropdown').classList.remove('show');
           }
       });
       
       // Theme toggle
       document.getElementById('themeToggle').addEventListener('click', function() {
           const currentTheme = document.documentElement.getAttribute('data-theme');
           const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
           
           document.documentElement.setAttribute('data-theme', newTheme);
           
           // Save theme preference
           fetch('save-theme.php', {
               method: 'POST',
               headers: {
                   'Content-Type': 'application/x-www-form-urlencoded',
               },
               body: 'theme=' + newTheme
           });
           
           // Update icon
           this.innerHTML = newTheme === 'dark' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
       });
       
       // Initialize theme icon
       document.getElementById('themeToggle').innerHTML = 
           document.documentElement.getAttribute('data-theme') === 'dark' 
               ? '<i class="fas fa-moon"></i>' 
               : '<i class="fas fa-sun"></i>';
       
       // Initialize progress chart if data exists
       const progressChartEl = document.getElementById('progressChart');
       if (progressChartEl) {
           const ctx = progressChartEl.getContext('2d');
           
           const chartLabels = <?php echo json_encode($chartLabels); ?>;
           const weightData = <?php echo json_encode($weightData); ?>;
           const bodyFatData = <?php echo json_encode($bodyFatData); ?>;
           const muscleMassData = <?php echo json_encode($muscleMassData); ?>;
           
           if (chartLabels && chartLabels.length > 0) {
               new Chart(ctx, {
                   type: 'line',
                   data: {
                       labels: chartLabels,
                       datasets: [
                           {
                               label: 'Weight (kg)',
                               data: weightData,
                               backgroundColor: 'rgba(255, 102, 0, 0.2)',
                               borderColor: 'rgba(255, 102, 0, 1)',
                               borderWidth: 2,
                               tension: 0.1
                           },
                           {
                               label: 'Body Fat (%)',
                               data: bodyFatData,
                               backgroundColor: 'rgba(220, 53, 69, 0.2)',
                               borderColor: 'rgba(220, 53, 69, 1)',
                               borderWidth: 2,
                               tension: 0.1
                           },
                           {
                               label: 'Muscle Mass (kg)',
                               data: muscleMassData,
                               backgroundColor: 'rgba(40, 167, 69, 0.2)',
                               borderColor: 'rgba(40, 167, 69, 1)',
                               borderWidth: 2,
                               tension: 0.1
                           }
                       ]
                   },
                   options: {
                       responsive: true,
                       maintainAspectRatio: false,
                       plugins: {
                           legend: {
                               position: 'top',
                           }
                       },
                       scales: {
                           y: {
                               beginAtZero: false
                           }
                       }
                   }
               });
           }
       }
   </script>
</body>
</html>