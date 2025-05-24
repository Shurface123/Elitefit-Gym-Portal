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

// Get date range filter
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-3 months'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get progress data
$progressStmt = $conn->prepare("
    SELECT pt.*, u.name as trainer_name, u.profile_image as trainer_image
    FROM progress_tracking pt
    JOIN users u ON pt.trainer_id = u.id
    WHERE pt.member_id = ? AND pt.date BETWEEN ? AND ?
    ORDER BY pt.date ASC
");
$progressStmt->execute([$userId, $startDate, $endDate]);
$progressData = $progressStmt->fetchAll(PDO::FETCH_ASSOC);

// Format progress data for charts
$chartLabels = [];
$weightData = [];
$bodyFatData = [];
$muscleMassData = [];
$chestData = [];
$waistData = [];
$hipsData = [];
$armsData = [];
$thighsData = [];

foreach ($progressData as $entry) {
    $chartLabels[] = date('M d', strtotime($entry['tracking_date']));
    $weightData[] = $entry['weight'];
    $bodyFatData[] = $entry['body_fat'];
    $muscleMassData[] = $entry['muscle_mass'];
    $chestData[] = $entry['chest'];
    $waistData[] = $entry['waist'];
    $hipsData[] = $entry['hips'];
    $armsData[] = $entry['arms'];
    $thighsData[] = $entry['thighs'];
}

// Get progress photos
$photoStmt = $conn->prepare("
    SELECT * FROM progress_photos
    WHERE member_id = ? AND photo_date BETWEEN ? AND ?
    ORDER BY photo_date DESC
");
$photoStmt->execute([$userId, $startDate, $endDate]);
$progressPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

// // Format date for display
// function formatDate($date) {
//     return date('M j, Y', strtotime($date));
// }

// Calculate progress changes
function calculateChange($current, $previous) {
    if ($current === null || $previous === null) {
        return ['value' => 0, 'direction' => 'none'];
    }
    
    $change = $current - $previous;
    $direction = $change < 0 ? 'down' : ($change > 0 ? 'up' : 'none');
    
    return ['value' => abs($change), 'direction' => $direction];
}

// Get first and last entries for comparison
$firstEntry = !empty($progressData) ? reset($progressData) : null;
$lastEntry = !empty($progressData) ? end($progressData) : null;

// Calculate changes if we have at least two entries
$changes = [];
if ($firstEntry && $lastEntry && $firstEntry['id'] !== $lastEntry['id']) {
    $changes['weight'] = calculateChange($lastEntry['weight'], $firstEntry['weight']);
    $changes['body_fat'] = calculateChange($lastEntry['body_fat'], $firstEntry['body_fat']);
    $changes['muscle_mass'] = calculateChange($lastEntry['muscle_mass'], $firstEntry['muscle_mass']);
    $changes['chest'] = calculateChange($lastEntry['chest'], $firstEntry['chest']);
    $changes['waist'] = calculateChange($lastEntry['waist'], $firstEntry['waist']);
    $changes['hips'] = calculateChange($lastEntry['hips'], $firstEntry['hips']);
    $changes['arms'] = calculateChange($lastEntry['arms'], $firstEntry['arms']);
    $changes['thighs'] = calculateChange($lastEntry['thighs'], $firstEntry['thighs']);
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Tracking - EliteFit Gym</title>
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
                <li><a href="progress.php" class="active"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
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
                    <h1>Progress Tracking</h1>
                    <p>Monitor your fitness journey and achievements</p>
                </div>
                <div class="header-actions">
                    <button class="btn" id="logProgressBtn">
                        <i class="fas fa-plus"></i> Log Progress
                    </button>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="card">
                <div class="card-content">
                    <form action="progress.php" method="get" class="date-range-form">
                        <div class="form-group">
                            <label for="start_date">From:</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">To:</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                        </div>
                        <button type="submit" class="btn">Apply Filter</button>
                    </form>
                </div>
            </div>
            
            <?php if (empty($progressData)): ?>
                <div class="card">
                    <div class="card-content">
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <h3>No Progress Data Available</h3>
                            <p>Your trainer hasn't recorded any progress data for you yet in the selected date range.</p>
                            <p>Try selecting a different date range or contact your trainer to schedule a progress assessment.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Progress Summary -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Progress Summary</h2>
                        <span class="date-range">
                            <?php echo formatDate($startDate); ?> - <?php echo formatDate($endDate); ?>
                        </span>
                    </div>
                    <div class="card-content">
                        <div class="progress-summary">
                            <div class="summary-item">
                                <div class="summary-label">Weight</div>
                                <div class="summary-value">
                                    <?php echo $lastEntry['weight'] ? number_format($lastEntry['weight'], 1) . ' kg' : 'N/A'; ?>
                                </div>
                                <?php if (isset($changes['weight'])): ?>
                                    <div class="summary-change <?php echo $changes['weight']['direction']; ?>">
                                        <?php if ($changes['weight']['direction'] !== 'none'): ?>
                                            <i class="fas fa-arrow-<?php echo $changes['weight']['direction']; ?>"></i>
                                            <?php echo number_format($changes['weight']['value'], 1); ?> kg
                                        <?php else: ?>
                                            No change
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="summary-item">
                                <div class="summary-label">Body Fat</div>
                                <div class="summary-value">
                                    <?php echo $lastEntry['body_fat'] ? number_format($lastEntry['body_fat'], 1) . '%' : 'N/A'; ?>
                                </div>
                                <?php if (isset($changes['body_fat'])): ?>
                                    <div class="summary-change <?php echo $changes['body_fat']['direction']; ?>">
                                        <?php if ($changes['body_fat']['direction'] !== 'none'): ?>
                                            <i class="fas fa-arrow-<?php echo $changes['body_fat']['direction']; ?>"></i>
                                            <?php echo number_format($changes['body_fat']['value'], 1); ?>%
                                        <?php else: ?>
                                            No change
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="summary-item">
                                <div class="summary-label">Muscle Mass</div>
                                <div class="summary-value">
                                    <?php echo $lastEntry['muscle_mass'] ? number_format($lastEntry['muscle_mass'], 1) . ' kg' : 'N/A'; ?>
                                </div>
                                <?php if (isset($changes['muscle_mass'])): ?>
                                    <div class="summary-change <?php echo $changes['muscle_mass']['direction']; ?>">
                                        <?php if ($changes['muscle_mass']['direction'] !== 'none'): ?>
                                            <i class="fas fa-arrow-<?php echo $changes['muscle_mass']['direction']; ?>"></i>
                                            <?php echo number_format($changes['muscle_mass']['value'], 1); ?> kg
                                        <?php else: ?>
                                            No change
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Charts -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-line"></i> Progress Charts</h2>
                        <div class="chart-tabs" id="chartTabs">
                            <button class="chart-tab active" data-chart="body-composition">Body Composition</button>
                            <button class="chart-tab" data-chart="measurements">Measurements</button>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="chart-container active" id="body-composition-chart">
                            <canvas id="bodyCompositionChart"></canvas>
                        </div>
                        <div class="chart-container" id="measurements-chart">
                            <canvas id="measurementsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Progress History -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Progress History</h2>
                    </div>
                    <div class="card-content">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Weight (kg)</th>
                                        <th>Body Fat (%)</th>
                                        <th>Muscle Mass (kg)</th>
                                        <th>Measurements (cm)</th>
                                        <th>Trainer</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_reverse($progressData) as $entry): ?>
                                        <tr>
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
                                                <div class="trainer-info">
                                                    <?php if (!empty($entry['trainer_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($entry['trainer_image']); ?>" alt="Trainer" class="trainer-avatar">
                                                    <?php else: ?>
                                                        <div class="trainer-avatar-placeholder">
                                                            <?php echo strtoupper(substr($entry['trainer_name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($entry['trainer_name']); ?></span>
                                                </div>
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
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Progress Photos -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-images"></i> Progress Photos</h2>
                    <button class="btn btn-sm" id="uploadPhotoBtn">
                        <i class="fas fa-upload"></i> Upload Photo
                    </button>
                </div>
                <div class="card-content">
                    <?php if (empty($progressPhotos)): ?>
                        <div class="empty-state">
                            <i class="fas fa-images"></i>
                            <h3>No Progress Photos</h3>
                            <p>You haven't uploaded any progress photos yet.</p>
                            <button class="btn" id="uploadPhotoEmptyBtn">Upload Your First Photo</button>
                        </div>
                    <?php else: ?>
                        <div class="progress-photos">
                            <?php foreach ($progressPhotos as $photo): ?>
                                <div class="photo-item">
                                    <div class="photo-date"><?php echo formatDate($photo['photo_date']); ?></div>
                                    <div class="photo-image">
                                        <img src="<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="Progress Photo">
                                    </div>
                                    <div class="photo-actions">
                                        <button class="btn btn-sm" onclick="viewPhoto('<?php echo htmlspecialchars($photo['photo_url']); ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deletePhoto(<?php echo $photo['id']; ?>)">
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
    
    <!-- Upload Photo Modal -->
    <div class="modal" id="uploadPhotoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Progress Photo</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="upload-progress-photo.php" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="photo_date">Photo Date</label>
                        <input type="date" id="photo_date" name="photo_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="photo_file">Select Photo</label>
                        <input type="file" id="photo_file" name="photo_file" class="form-control" accept="image/*" required>
                        <div class="form-text">Max file size: 5MB. Recommended dimensions: 800x600 pixels.</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="photo_type">Photo Type</label>
                        <select id="photo_type" name="photo_type" class="form-control">
                            <option value="front">Front View</option>
                            <option value="side">Side View</option>
                            <option value="back">Back View</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="photo_notes">Notes (Optional)</label>
                        <textarea id="photo_notes" name="photo_notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Photo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Photo Modal -->
    <div class="modal" id="viewPhotoModal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>Progress Photo</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="photo-viewer">
                    <img id="viewPhotoImage" src="/placeholder.svg" alt="Progress Photo">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Log Progress Modal -->
    <div class="modal" id="logProgressModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Log Progress</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="info-message">
                    <i class="fas fa-info-circle"></i>
                    <p>Progress tracking is managed by your trainer. Please contact your trainer to schedule a progress assessment.</p>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline close-modal">Close</button>
                    <a href="trainers.php" class="btn btn-primary">Contact Trainer</a>
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
    
    // Modal functionality
    const modals = document.querySelectorAll('.modal');
    const modalTriggers = [
        { id: 'uploadPhotoBtn', modal: 'uploadPhotoModal' },
        { id: 'uploadPhotoEmptyBtn', modal: 'uploadPhotoModal' },
        { id: 'logProgressBtn', modal: 'logProgressModal' }
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
    
    // View photo
    function viewPhoto(url) {
        const viewPhotoImage = document.getElementById('viewPhotoImage');
        if (viewPhotoImage) {
            viewPhotoImage.src = url;
            document.getElementById('viewPhotoModal').classList.add('show');
        }
    }
    
    // Delete photo
    function deletePhoto(photoId) {
        if (confirm('Are you sure you want to delete this photo? This action cannot be undone.')) {
            window.location.href = 'delete-progress-photo.php?id=' + photoId;
        }
    }
    
    // Chart tabs
    document.querySelectorAll('.chart-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            // Update active tab
            document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Show corresponding chart
            const chartId = this.getAttribute('data-chart');
            document.querySelectorAll('.chart-container').forEach(chart => {
                chart.classList.remove('active');
            });
            const targetChart = document.getElementById(chartId + '-chart');
            if (targetChart) {
                targetChart.classList.add('active');
            }
        });
    });
    
    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        const chartLabels = <?php echo json_encode($chartLabels); ?>;
        
        // Body Composition Chart
        const bodyCompCtx = document.getElementById('bodyCompositionChart');
        if (bodyCompCtx && chartLabels && chartLabels.length > 0) {
            new Chart(bodyCompCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Weight (kg)',
                            data: <?php echo json_encode($weightData); ?>,
                            backgroundColor: 'rgba(255, 102, 0, 0.2)',
                            borderColor: 'rgba(255, 102, 0, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Body Fat (%)',
                            data: <?php echo json_encode($bodyFatData); ?>,
                            backgroundColor: 'rgba(220, 53, 69, 0.2)',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Muscle Mass (kg)',
                            data: <?php echo json_encode($muscleMassData); ?>,
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
        
        // Measurements Chart
        const measurementsCtx = document.getElementById('measurementsChart');
        if (measurementsCtx && chartLabels && chartLabels.length > 0) {
            new Chart(measurementsCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Chest (cm)',
                            data: <?php echo json_encode($chestData); ?>,
                            backgroundColor: 'rgba(0, 123, 255, 0.2)',
                            borderColor: 'rgba(0, 123, 255, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Waist (cm)',
                            data: <?php echo json_encode($waistData); ?>,
                            backgroundColor: 'rgba(255, 193, 7, 0.2)',
                            borderColor: 'rgba(255, 193, 7, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Hips (cm)',
                            data: <?php echo json_encode($hipsData); ?>,
                            backgroundColor: 'rgba(111, 66, 193, 0.2)',
                            borderColor: 'rgba(111, 66, 193, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Arms (cm)',
                            data: <?php echo json_encode($armsData); ?>,
                            backgroundColor: 'rgba(23, 162, 184, 0.2)',
                            borderColor: 'rgba(23, 162, 184, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Thighs (cm)',
                            data: <?php echo json_encode($thighsData); ?>,
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
    });
</script>
</body>
</html>
