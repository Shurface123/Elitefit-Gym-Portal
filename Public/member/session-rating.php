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

// Check if session_ratings table exists, create if not
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'session_ratings'")->rowCount() > 0;
    
    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE session_ratings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                member_id INT NOT NULL,
                trainer_id INT NOT NULL,
                rating INT NOT NULL,
                feedback TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY (session_id, member_id),
                INDEX (trainer_id),
                INDEX (member_id)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error
}

// Get session ID from URL
$sessionId = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

// Check if session exists and belongs to this member
$sessionStmt = $conn->prepare("
    SELECT ts.*, u.name as trainer_name, u.profile_image as trainer_image
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.id = ? AND ts.member_id = ? AND ts.status = 'completed'
");
$sessionStmt->execute([$sessionId, $userId]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

// If session not found or not completed, redirect to appointments page
if (!$session) {
    header('Location: appointments.php');
    exit;
}

// Check if session has already been rated
$ratingStmt = $conn->prepare("
    SELECT * FROM session_ratings
    WHERE session_id = ? AND member_id = ?
");
$ratingStmt->execute([$sessionId, $userId]);
$existingRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    $rating = intval($_POST['rating']);
    $feedback = $_POST['feedback'] ?? '';
    
    try {
        // Validate rating
        if ($rating < 1 || $rating > 5) {
            throw new Exception('Please select a rating between 1 and 5 stars.');
        }
        
        if ($existingRating) {
            // Update existing rating
            $stmt = $conn->prepare("
                UPDATE session_ratings
                SET rating = ?, feedback = ?
                WHERE id = ?
            ");
            $stmt->execute([$rating, $feedback, $existingRating['id']]);
            
            $message = 'Your rating has been updated successfully!';
        } else {
            // Insert new rating
            $stmt = $conn->prepare("
                INSERT INTO session_ratings (session_id, member_id, trainer_id, rating, feedback)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sessionId, $userId, $session['trainer_id'], $rating, $feedback]);
            
            // Create notification for trainer
            $notificationTableExists = $conn->query("SHOW TABLES LIKE 'trainer_notifications'")->rowCount() > 0;
            
            if ($notificationTableExists) {
                $notifyStmt = $conn->prepare("
                    INSERT INTO trainer_notifications (trainer_id, message, icon, link)
                    VALUES (?, ?, ?, ?)
                ");
                $notifyStmt->execute([
                    $session['trainer_id'],
                    "$userName has rated your session on " . date('M j, Y', strtotime($session['start_time'])) . " with $rating stars!",
                    "star",
                    "session-ratings.php"
                ]);
            }
            
            $message = 'Thank you for rating your session!';
        }
        
        $messageType = 'success';
        
        // Refresh existing rating data
        $ratingStmt->execute([$sessionId, $userId]);
        $existingRating = $ratingStmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Session - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
    <style>
        .session-details {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .session-title {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .session-time {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            color: rgba(var(--foreground-rgb), 0.7);
        }
        
        .trainer-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .rating-form {
            margin-top: 20px;
        }
        
        .star-rating {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .star-rating input {
            display: none;
        }
        
        .star-rating label {
            font-size: 2rem;
            color: var(--card-border);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .star-rating label:hover,
        .star-rating label:hover ~ label,
        .star-rating input:checked ~ label {
            color: var(--primary);
        }
        
        .rating-display {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 20px;
        }
        
        .rating-display .star {
            color: var(--primary);
            font-size: 1.5rem;
        }
        
        .rating-display .star.empty {
            color: var(--card-border);
        }
        
        .rating-date {
            font-size: 0.9rem;
            color: rgba(var(--foreground-rgb), 0.7);
            margin-left: 10px;
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
                    <h1>Rate Your Session</h1>
                    <p>Share your feedback about your training session</p>
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
            
            <!-- Session Details -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> Session Details</h2>
                </div>
                <div class="card-content">
                    <div class="session-details">
                        <div class="session-header">
                            <div>
                                <div class="session-title"><?php echo htmlspecialchars($session['title']); ?></div>
                                <div class="session-time">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo formatDate($session['start_time']); ?></span>
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo formatTime($session['start_time']); ?> - <?php echo formatTime($session['end_time']); ?></span>
                                </div>
                            </div>
                            <div class="session-status">
                                <span class="status-badge completed">Completed</span>
                            </div>
                        </div>
                        
                        <?php if (!empty($session['description'])): ?>
                            <div class="session-description">
                                <?php echo nl2br(htmlspecialchars($session['description'])); ?>
                            </div>
                        <?php endif; ?>
                        
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
                    
                    <?php if ($existingRating): ?>
                        <!-- Existing Rating -->
                        <div class="existing-rating">
                            <h3>Your Rating</h3>
                            <div class="rating-display">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star <?php echo $i <= $existingRating['rating'] ? '' : 'empty'; ?>">
                                        <i class="fas fa-star"></i>
                                    </span>
                                <?php endfor; ?>
                                <span class="rating-date">Rated on <?php echo formatDate($existingRating['created_at']); ?></span>
                            </div>
                            
                            <?php if (!empty($existingRating['feedback'])): ?>
                                <div class="feedback">
                                    <h4>Your Feedback</h4>
                                    <p><?php echo nl2br(htmlspecialchars($existingRating['feedback'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-actions">
                                <button type="button" id="editRatingBtn" class="btn">
                                    <i class="fas fa-edit"></i> Edit Rating
                                </button>
                            </div>
                        </div>
                        
                        <!-- Edit Rating Form (hidden by default) -->
                        <div class="rating-form" id="editRatingForm" style="display: none;">
                            <h3>Edit Your Rating</h3>
                            <form action="" method="post">
                                <input type="hidden" name="submit_rating" value="1">
                                
                                <div class="form-group">
                                    <label>How would you rate this session?</label>
                                    <div class="star-rating">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" <?php echo $existingRating['rating'] == $i ? 'checked' : ''; ?>>
                                            <label for="star<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="feedback">Your Feedback (Optional)</label>
                                    <textarea id="feedback" name="feedback" class="form-control" rows="4"><?php echo htmlspecialchars($existingRating['feedback']); ?></textarea>
                                    <div class="form-text">Share your thoughts about the session, what you liked, and any suggestions for improvement.</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" id="cancelEditBtn" class="btn btn-outline">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Update Rating</button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- New Rating Form -->
                        <div class="rating-form">
                            <h3>Rate This Session</h3>
                            <form action="" method="post">
                                <input type="hidden" name="submit_rating" value="1">
                                
                                <div class="form-group">
                                    <label>How would you rate this session?</label>
                                    <div class="star-rating">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                            <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" <?php echo $i == 3 ? 'checked' : ''; ?>>
                                            <label for="star<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="feedback">Your Feedback (Optional)</label>
                                    <textarea id="feedback" name="feedback" class="form-control" rows="4"></textarea>
                                    <div class="form-text">Share your thoughts about the session, what you liked, and any suggestions for improvement.</div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Submit Rating</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
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
        
        // Edit rating toggle
        const editRatingBtn = document.getElementById('editRatingBtn');
        const cancelEditBtn = document.getElementById('cancelEditBtn');
        const editRatingForm = document.getElementById('editRatingForm');
        const existingRating = document.querySelector('.existing-rating');
        
        if (editRatingBtn && cancelEditBtn && editRatingForm && existingRating) {
            editRatingBtn.addEventListener('click', function() {
                existingRating.style.display = 'none';
                editRatingForm.style.display = 'block';
            });
            
            cancelEditBtn.addEventListener('click', function() {
                editRatingForm.style.display = 'none';
                existingRating.style.display = 'block';
            });
        }
    </script>
</body>
</html>
