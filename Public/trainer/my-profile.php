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

// Get theme preference with robust error handling
$theme = 'dark'; // Default theme

try {
    // Check if trainer_settings table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->fetch();
    
    if ($tableCheck) {
        // Check if theme_preference column exists
        $columnCheck = $conn->query("SHOW COLUMNS FROM trainer_settings LIKE 'theme_preference'")->fetch();
        
        if (!$columnCheck) {
            // Add the column if it doesn't exist
            $conn->exec("ALTER TABLE trainer_settings ADD COLUMN theme_preference VARCHAR(50) DEFAULT 'dark'");
        }
        
        // Now safely query the theme preference
        $stmt = $conn->prepare("SELECT theme_preference FROM trainer_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $themeResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $theme = $themeResult ? $themeResult['theme_preference'] : 'dark';
    }
} catch (PDOException $e) {
    // Silently fall back to default theme if any error occurs
    error_log("Theme preference error: " . $e->getMessage());
}

// Rest of your existing code remains unchanged...
// Get trainer profile data
$stmt = $conn->prepare("
    SELECT u.*, tp.specialization, tp.experience_years, tp.certification, tp.bio, tp.hourly_rate, tp.profile_image
    FROM users u
    LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

// ... rest of your existing PHP code ...
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - EliteFit Gym</title>
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
                    <li><a href="my-profile.php" class="active"><i class="fas fa-user"></i> <span>My Profile</span></a></li>
                    <li><a href="members.php"><i class="fas fa-users"></i> <span>Members</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Training</div>
                <ul class="sidebar-menu">
                    <li><a href="workout-plans.php"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
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
                    <h1>My Profile</h1>
                    <p>Manage your personal and professional information</p>
                </div>
            </div>
            
            <?php if (!empty($updateMessage)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($updateMessage); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($updateError)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($updateError); ?></div>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-edit"></i> Edit Profile</h2>
                </div>
                <div class="card-content">
                    <form action="" method="post" enctype="multipart/form-data">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Full Name</label>
                                <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($trainer['name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($trainer['email']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($trainer['phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="specialization">Specialization</label>
                                <input type="text" id="specialization" name="specialization" class="form-control" value="<?php echo htmlspecialchars($trainer['specialization'] ?? ''); ?>" placeholder="e.g., Weight Loss, Strength Training">
                            </div>
                            
                            <div class="form-group">
                                <label for="experience_years">Years of Experience</label>
                                <input type="number" id="experience_years" name="experience_years" class="form-control" value="<?php echo htmlspecialchars($trainer['experience_years'] ?? ''); ?>" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="certification">Certifications</label>
                                <input type="text" id="certification" name="certification" class="form-control" value="<?php echo htmlspecialchars($trainer['certification'] ?? ''); ?>" placeholder="e.g., NASM, ACE, ISSA">
                            </div>
                            
                            <div class="form-group">
                                <label for="hourly_rate">Hourly Rate ($)</label>
                                <input type="number" id="hourly_rate" name="hourly_rate" class="form-control" value="<?php echo htmlspecialchars($trainer['hourly_rate'] ?? ''); ?>" min="0" step="0.01">
                            </div>
                            
                            <div class="form-group">
                                <label for="profile_image">Profile Image</label>
                                <input type="file" id="profile_image" name="profile_image" class="form-control">
                                <p class="form-text">Maximum file  class="form-control">
                                <p class="form-text">Maximum file size is 5MB. Allowed formats: JPG, PNG, GIF.</p>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="bio">Professional Bio</label>
                                <textarea id="bio" name="bio" class="form-control" rows="4"><?php echo htmlspecialchars($trainer['bio'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-line"></i> Performance Overview</h2>
                </div>
                <div class="card-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-info">
                                <?php
                                // Get total members count
                                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM trainer_members WHERE trainer_id = ? AND status = 'active'");
                                $stmt->execute([$userId]);
                                $totalMembers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                                <h3><?php echo $totalMembers; ?></h3>
                                <p>Active Members</p>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="stat-info">
                                <?php
                                // Get total sessions count
                                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM trainer_schedule WHERE trainer_id = ?");
                                $stmt->execute([$userId]);
                                $totalSessions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                                <h3><?php echo $totalSessions; ?></h3>
                                <p>Total Sessions</p>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                            <div class="stat-info">
                                <?php
                                // Get total workouts count
                                $stmt = $conn->prepare("SELECT COUNT(*) as total FROM workouts WHERE trainer_id = ?");
                                $stmt->execute([$userId]);
                                $totalWorkouts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                                ?>
                                <h3><?php echo $totalWorkouts; ?></h3>
                                <p>Workout Plans</p>
                            </div>
                        </div>
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