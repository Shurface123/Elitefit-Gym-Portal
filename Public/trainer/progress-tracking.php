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

// Check if progress_tracking table exists, create if not
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'progress_tracking'")->rowCount() > 0;
    
    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE progress_tracking (
                id INT AUTO_INCREMENT PRIMARY KEY,
                trainer_id INT NOT NULL,
                member_id INT NOT NULL,
                tracking_date DATE NOT NULL,
                weight DECIMAL(5,2) NULL,
                body_fat DECIMAL(5,2) NULL,
                muscle_mass DECIMAL(5,2) NULL,
                chest DECIMAL(5,2) NULL,
                waist DECIMAL(5,2) NULL,
                hips DECIMAL(5,2) NULL,
                arms DECIMAL(5,2) NULL,
                thighs DECIMAL(5,2) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (trainer_id),
                INDEX (member_id),
                INDEX (tracking_date)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error - table creation failed
}

// Get progress data
$progressData = [];
try {
    if ($memberFilter > 0) {
        // Get progress data for specific member
        $stmt = $conn->prepare("
            SELECT * FROM progress_tracking
            WHERE trainer_id = ? AND member_id = ?
            ORDER BY tracking_date DESC
        ");
        $stmt->execute([$userId, $memberFilter]);
        $progressData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get member details
        $stmt = $conn->prepare("SELECT name, profile_image FROM users WHERE id = ?");
        $stmt->execute([$memberFilter]);
        $memberDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Get latest progress entry for each member
        $stmt = $conn->prepare("
            SELECT pt.*, u.name, u.profile_image
            FROM progress_tracking pt
            JOIN users u ON pt.member_id = u.id
            WHERE pt.trainer_id = ?
            AND pt.tracking_date = (
                SELECT MAX(tracking_date) 
                FROM progress_tracking 
                WHERE member_id = pt.member_id AND trainer_id = ?
            )
            ORDER BY pt.tracking_date DESC
        ");
        $stmt->execute([$userId, $userId]);
        $progressData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty progressData array already set
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_progress'])) {
        // Add new progress entry
        try {
            $memberId = $_POST['member_id'];
            $trackingDate = $_POST['tracking_date'];
            $weight = !empty($_POST['weight']) ? $_POST['weight'] : null;
            $bodyFat = !empty($_POST['body_fat']) ? $_POST['body_fat'] : null;
            $muscleMass = !empty($_POST['muscle_mass']) ? $_POST['muscle_mass'] : null;
            $chest = !empty($_POST['chest']) ? $_POST['chest'] : null;
            $waist = !empty($_POST['waist']) ? $_POST['waist'] : null;
            $hips = !empty($_POST['hips']) ? $_POST['hips'] : null;
            $arms = !empty($_POST['arms']) ? $_POST['arms'] : null;
            $thighs = !empty($_POST['thighs']) ? $_POST['thighs'] : null;
            $notes = $_POST['notes'] ?? '';
            
            // Check if entry already exists for this date and member
            $stmt = $conn->prepare("
                SELECT id FROM progress_tracking
                WHERE trainer_id = ? AND member_id = ? AND tracking_date = ?
            ");
            $stmt->execute([$userId, $memberId, $trackingDate]);
            $existingEntry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingEntry) {
                // Update existing entry
                $stmt = $conn->prepare("
                    UPDATE progress_tracking
                    SET weight = ?, body_fat = ?, muscle_mass = ?,
                        chest = ?, waist = ?, hips = ?, arms = ?, thighs = ?,
                        notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $weight, $bodyFat, $muscleMass,
                    $chest, $waist, $hips, $arms, $thighs,
                    $notes, $existingEntry['id']
                ]);
                
                $message = 'Progress data updated successfully!';
            } else {
                // Insert new entry
                $stmt = $conn->prepare("
                    INSERT INTO progress_tracking
                    (trainer_id, member_id, tracking_date, weight, body_fat, muscle_mass,
                     chest, waist, hips, arms, thighs, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $memberId, $trackingDate, $weight, $bodyFat, $muscleMass,
                    $chest, $waist, $hips, $arms, $thighs, $notes
                ]);
                
                $message = 'Progress data added successfully!';
            }
            
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: progress-tracking.php?member_id=$memberId&added=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error saving progress data: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete_progress'])) {
        // Delete progress entry
        try {
            $progressId = $_POST['delete_progress_id'];
            
            // Get member ID before deleting
            $stmt = $conn->prepare("SELECT member_id FROM progress_tracking WHERE id = ? AND trainer_id = ?");
            $stmt->execute([$progressId, $userId]);
            $progressEntry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($progressEntry) {
                $memberId = $progressEntry['member_id'];
                
                // Delete the entry
                $stmt = $conn->prepare("DELETE FROM progress_tracking WHERE id = ? AND trainer_id = ?");
                $stmt->execute([$progressId, $userId]);
                
                $message = 'Progress entry deleted successfully!';
                $messageType = 'success';
                
                // Redirect to prevent form resubmission
                header("Location: progress-tracking.php?member_id=$memberId&deleted=1");
                exit;
            } else {
                $message = 'Progress entry not found.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting progress entry: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check for URL parameters
if (isset($_GET['added']) && $_GET['added'] == '1') {
    $message = 'Progress data saved successfully!';
    $messageType = 'success';
}

if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $message = 'Progress entry deleted successfully!';
    $messageType = 'success';
}

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Get chart data for selected member
$chartData = [
    'weight' => ['labels' => [], 'values' => []],
    'bodyFat' => ['labels' => [], 'values' => []],
    'muscleMass' => ['labels' => [], 'values' => []]
];

if ($memberFilter > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT tracking_date, weight, body_fat, muscle_mass
            FROM progress_tracking
            WHERE trainer_id = ? AND member_id = ?
            ORDER BY tracking_date ASC
        ");
        $stmt->execute([$userId, $memberFilter]);
        $chartEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($chartEntries as $entry) {
            $formattedDate = date('M j', strtotime($entry['tracking_date']));
            
            $chartData['weight']['labels'][] = $formattedDate;
            $chartData['weight']['values'][] = $entry['weight'];
            
            $chartData['bodyFat']['labels'][] = $formattedDate;
            $chartData['bodyFat']['values'][] = $entry['body_fat'];
            
            $chartData['muscleMass']['labels'][] = $formattedDate;
            $chartData['muscleMass']['values'][] = $entry['muscle_mass'];
        }
    } catch (PDOException $e) {
        // Handle error - chart data arrays already initialized
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Tracking - EliteFit Gym</title>
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
                    <li><a href="workout-plans.php"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
                    <li><a href="schedule.php"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
                    <li><a href="progress-tracking.php" class="active"><i class="fas fa-chart-line"></i> <span>Progress Tracking</span></a></li>
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
                    <h1>Progress Tracking</h1>
                    <p>Track and monitor your clients' fitness progress</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" data-modal="addProgressModal">
                        <i class="fas fa-plus"></i> Add Progress Data
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
            
            <!-- Member Filter -->
            <div class="card">
                <div class="card-content">
                    <div class="filter-container">
                        <div class="form-group">
                            <label for="member-filter">Select Member:</label>
                            <select id="member-filter" class="form-control">
                                <option value="0">All Members</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($memberFilter > 0): ?>
                            <button class="btn btn-primary" data-modal="addProgressModal">
                                <i class="fas fa-plus"></i> Add Progress Data
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($memberFilter > 0 && !empty($progressData)): ?>
                <!-- Progress Charts -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-line"></i> Progress Charts for <?php echo htmlspecialchars($memberDetails['name'] ?? ''); ?></h2>
                    </div>
                    <div class="card-content">
                        <div class="charts-grid">
                            <div class="chart-container">
                                <h3>Weight Progress</h3>
                                <canvas id="weightChart"></canvas>
                                <input type="hidden" id="weightChartData" value='<?php echo json_encode($chartData['weight']); ?>'>
                            </div>
                            
                            <div class="chart-container">
                                <h3>Body Fat Progress</h3>
                                <canvas id="bodyFatChart"></canvas>
                                <input type="hidden" id="bodyFatChartData" value='<?php echo json_encode($chartData['bodyFat']); ?>'>
                            </div>
                            
                            <div class="chart-container">
                                <h3>Muscle Mass Progress</h3>
                                <canvas id="muscleMassChart"></canvas>
                                <input type="hidden" id="muscleMassChartData" value='<?php echo json_encode($chartData['muscleMass']); ?>'>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Progress Data Table -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <?php if ($memberFilter > 0): ?>
                            <i class="fas fa-table"></i> Progress History for <?php echo htmlspecialchars($memberDetails['name'] ?? ''); ?>
                        <?php else: ?>
                            <i class="fas fa-table"></i> Recent Progress Data
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="card-content">
                    <?php if (empty($progressData)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>No progress data available</p>
                            <?php if ($memberFilter > 0): ?>
                                <button class="btn btn-primary" data-modal="addProgressModal">Add First Progress Entry</button>
                            <?php else: ?>
                                <p>Select a member to track their progress</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table" data-sortable>
                                <thead>
                                    <tr>
                                        <?php if ($memberFilter == 0): ?>
                                            <th>Member</th>
                                        <?php endif; ?>
                                        <th data-sortable>Date</th>
                                        <th data-sortable>Weight (kg)</th>
                                        <th data-sortable>Body Fat (%)</th>
                                        <th data-sortable>Muscle Mass (kg)</th>
                                        <th>Measurements (cm)</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($progressData as $entry): ?>
                                        <tr>
                                            <?php if ($memberFilter == 0): ?>
                                                <td>
                                                    <div class="member-info">
                                                        <?php if (!empty($entry['profile_image'])): ?>
                                                            <img src="<?php echo htmlspecialchars($entry['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                        <?php else: ?>
                                                            <div class="member-avatar-placeholder">
                                                                <?php echo strtoupper(substr($entry['name'], 0, 1)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <a href="progress-tracking.php?member_id=<?php echo $entry['member_id']; ?>">
                                                            <?php echo htmlspecialchars($entry['name']); ?>
                                                        </a>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo formatDate($entry['tracking_date']); ?></td>
                                            <td><?php echo $entry['weight'] ? number_format($entry['weight'], 1) : '-'; ?></td>
                                            <td><?php echo $entry['body_fat'] ? number_format($entry['body_fat'], 1) : '-'; ?></td>
                                            <td><?php echo $entry['muscle_mass'] ? number_format($entry['muscle_mass'], 1) : '-'; ?></td>
                                            <td>
                                                <?php if ($entry['chest'] || $entry['waist'] || $entry['hips'] || $entry['arms'] || $entry['thighs']): ?>
                                                    <div class="measurements-tooltip">
                                                        <i class="fas fa-ruler"></i> View
                                                        <div class="tooltip-content">
                                                            <table class="measurements-table">
                                                                <?php if ($entry['chest']): ?>
                                                                    <tr>
                                                                        <td>Chest:</td>
                                                                        <td><?php echo number_format($entry['chest'], 1); ?> cm</td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                                <?php if ($entry['waist']): ?>
                                                                    <tr>
                                                                        <td>Waist:</td>
                                                                        <td><?php echo number_format($entry['waist'], 1); ?> cm</td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                                <?php if ($entry['hips']): ?>
                                                                    <tr>
                                                                        <td>Hips:</td>
                                                                        <td><?php echo number_format($entry['hips'], 1); ?> cm</td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                                <?php if ($entry['arms']): ?>
                                                                    <tr>
                                                                        <td>Arms:</td>
                                                                        <td><?php echo number_format($entry['arms'], 1); ?> cm</td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                                <?php if ($entry['thighs']): ?>
                                                                    <tr>
                                                                        <td>Thighs:</td>
                                                                        <td><?php echo number_format($entry['thighs'], 1); ?> cm</td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                            </table>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($entry['notes'])): ?>
                                                    <div class="notes-tooltip">
                                                        <i class="fas fa-sticky-note"></i> View
                                                        <div class="tooltip-content">
                                                            <?php echo nl2br(htmlspecialchars($entry['notes'])); ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-outline" onclick="editProgress(<?php echo $entry['id']; ?>)" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $entry['id']; ?>)" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
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
    
    <!-- Add Progress Modal -->
    <div class="modal" id="addProgressModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Progress Data</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="progress-tracking.php" method="post" data-validate>
                    <input type="hidden" name="add_progress" value="1">
                    
                    <div class="form-group">
                        <label for="member_id">Member</label>
                        <select id="member_id" name="member_id" class="form-control" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tracking_date">Date</label>
                        <input type="date" id="tracking_date" name="tracking_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <h4>Body Composition</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="weight">Weight (kg)</label>
                            <input type="number" id="weight" name="weight" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="body_fat">Body Fat (%)</label>
                            <input type="number" id="body_fat" name="body_fat" class="form-control" step="0.1" min="0" max="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="muscle_mass">Muscle Mass (kg)</label>
                            <input type="number" id="muscle_mass" name="muscle_mass" class="form-control" step="0.1" min="0">
                        </div>
                    </div>
                    
                    <h4>Measurements (cm)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="chest">Chest</label>
                            <input type="number" id="chest" name="chest" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="waist">Waist</label>
                            <input type="number" id="waist" name="waist" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="hips">Hips</label>
                            <input type="number" id="hips" name="hips" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="arms">Arms</label>
                            <input type="number" id="arms" name="arms" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="thighs">Thighs</label>
                            <input type="number" id="thighs" name="thighs" class="form-control" step="0.1" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Progress Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Progress Modal -->
    <div class="modal" id="editProgressModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Progress Data</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="progress-tracking.php" method="post" data-validate id="editProgressForm">
                    <input type="hidden" name="add_progress" value="1">
                    <input type="hidden" id="edit_progress_id" name="progress_id" value="">
                    
                    <div class="form-group">
                        <label for="edit_member_id">Member</label>
                        <select id="edit_member_id" name="member_id" class="form-control" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo htmlspecialchars($member['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_tracking_date">Date</label>
                        <input type="date" id="edit_tracking_date" name="tracking_date" class="form-control" required>
                    </div>
                    
                    <h4>Body Composition</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_weight">Weight (kg)</label>
                            <input type="number" id="edit_weight" name="weight" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_body_fat">Body Fat (%)</label>
                            <input type="number" id="edit_body_fat" name="body_fat" class="form-control" step="0.1" min="0" max="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_muscle_mass">Muscle Mass (kg)</label>
                            <input type="number" id="edit_muscle_mass" name="muscle_mass" class="form-control" step="0.1" min="0">
                        </div>
                    </div>
                    
                    <h4>Measurements (cm)</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_chest">Chest</label>
                            <input type="number" id="edit_chest" name="chest" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_waist">Waist</label>
                            <input type="number" id="edit_waist" name="waist" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_hips">Hips</label>
                            <input type="number" id="edit_hips" name="hips" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_arms">Arms</label>
                            <input type="number" id="edit_arms" name="arms" class="form-control" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_thighs">Thighs</label>
                            <input type="number" id="edit_thighs" name="thighs" class="form-control" step="0.1" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_notes">Notes</label>
                        <textarea id="edit_notes" name="notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Progress Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteConfirmModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this progress entry? This action cannot be undone.</p>
                
                <form action="progress-tracking.php" method="post">
                    <input type="hidden" name="delete_progress" value="1">
                    <input type="hidden" id="delete_progress_id" name="delete_progress_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Entry</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../assets/js/trainer-dashboard.js"></script>
    <script>
        // Member filter
        document.getElementById('member-filter').addEventListener('change', function() {
            const memberId = this.value;
            window.location.href = `progress-tracking.php${memberId > 0 ? '?member_id=' + memberId : ''}`;
        });
        
        // Initialize charts if data is available
        document.addEventListener('DOMContentLoaded', function() {
            // Weight chart
            const weightChartEl = document.getElementById('weightChart');
            if (weightChartEl) {
                const ctx = weightChartEl.getContext('2d');
                const chartDataEl = document.getElementById('weightChartData');
                
                if (chartDataEl) {
                    try {
                        const chartData = JSON.parse(chartDataEl.value);
                        
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: chartData.labels,
                                datasets: [{
                                    label: 'Weight (kg)',
                                    data: chartData.values,
                                    backgroundColor: 'rgba(255, 136, 0, 0.2)',
                                    borderColor: 'rgba(255, 136, 0, 1)',
                                    borderWidth: 2,
                                    tension: 0.1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: {
                                        beginAtZero: false,
                                        ticks: {
                                            callback: function(value) {
                                                return value + ' kg';
                                            }
                                        }
                                    }
                                },
                                plugins: {
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                return context.dataset.label + ': ' + context.raw + ' kg';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    } catch (e) {
                        console.error('Error parsing weight chart data:', e);
                    }
                }
            }
            
            // Body fat chart
            const bodyFatChartEl = document.getElementById('bodyFatChart');
            if (bodyFatChartEl) {
                const ctx = bodyFatChartEl.getContext('2d');
                const chartDataEl = document.getElementById('bodyFatChartData');
                
                if (chartDataEl) {
                    try {
                        const chartData = JSON.parse(chartDataEl.value);
                        
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: chartData.labels,
                                datasets: [{
                                    label: 'Body Fat (%)',
                                    data: chartData.values,
                                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    borderWidth: 2,
                                    tension: 0.1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: {
                                        beginAtZero: false,
                                        ticks: {
                                            callback: function(value) {
                                                return value + '%';
                                            }
                                        }
                                    }
                                },
                                plugins: {
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                return context.dataset.label + ': ' + context.raw + '%';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    } catch (e) {
                        console.error('Error parsing body fat chart data:', e);
                    }
                }
            }
            
            // Muscle mass chart
            const muscleMassChartEl = document.getElementById('muscleMassChart');
            if (muscleMassChartEl) {
                const ctx = muscleMassChartEl.getContext('2d');
                const chartDataEl = document.getElementById('muscleMassChartData');
                
                if (chartDataEl) {
                    try {
                        const chartData = JSON.parse(chartDataEl.value);
                        
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: chartData.labels,
                                datasets: [{
                                    label: 'Muscle Mass (kg)',
                                    data: chartData.values,
                                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 2,
                                    tension: 0.1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: {
                                        beginAtZero: false,
                                        ticks: {
                                            callback: function(value) {
                                                return value + ' kg';
                                            }
                                        }
                                    }
                                },
                                plugins: {
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                return context.dataset.label + ': ' + context.raw + ' kg';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    } catch (e) {
                        console.error('Error parsing muscle mass chart data:', e);
                    }
                }
            }
        });
        
        // Edit progress entry
        function editProgress(progressId) {
            // Fetch progress data
            fetch(`get_progress.php?id=${progressId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const progress = data.progress;
                        
                        // Populate form fields
                        document.getElementById('edit_progress_id').value = progress.id;
                        document.getElementById('edit_member_id').value = progress.member_id;
                        document.getElementById('edit_tracking_date').value = progress.tracking_date;
                        document.getElementById('edit_weight').value = progress.weight || '';
                        document.getElementById('edit_body_fat').value = progress.body_fat || '';
                        document.getElementById('edit_muscle_mass').value = progress.muscle_mass || '';
                        document.getElementById('edit_chest').value = progress.chest || '';
                        document.getElementById('edit_waist').value = progress.waist || '';
                        document.getElementById('edit_hips').value = progress.hips || '';
                        document.getElementById('edit_arms').value = progress.arms || '';
                        document.getElementById('edit_thighs').value = progress.thighs || '';
                        document.getElementById('edit_notes').value = progress.notes || '';
                        
                        // Open the modal
                        openModal(document.getElementById('editProgressModal'));
                    } else {
                        showNotification('Error loading progress data', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error fetching progress data:', error);
                    showNotification('Error loading progress data', 'error');
                });
        }
        
        // Confirm delete
        function confirmDelete(progressId) {
            document.getElementById('delete_progress_id').value = progressId;
            openModal(document.getElementById('deleteConfirmModal'));
        }
    </script>
</body>
</html>
