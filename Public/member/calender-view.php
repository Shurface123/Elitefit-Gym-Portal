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

// Get current month and year from URL parameters or use current date
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Validate month and year
if ($month < 1 || $month > 12) {
    $month = intval(date('m'));
}

if ($year < 2000 || $year > 2100) {
    $year = intval(date('Y'));
}

// Calculate previous and next month
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Get first day of the month
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$numDays = date('t', $firstDay);
$firstDayOfWeek = date('w', $firstDay); // 0 (Sunday) to 6 (Saturday)

// Get all appointments for the month
$startDate = date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
$endDate = date('Y-m-d', mktime(0, 0, 0, $month + 1, 0, $year));

$appointmentsStmt = $conn->prepare("
    SELECT ts.id, ts.title, ts.start_time, ts.end_time, ts.status,
           u.name as trainer_name, u.profile_image as trainer_image
    FROM trainer_schedule ts
    JOIN users u ON ts.trainer_id = u.id
    WHERE ts.member_id = ? 
    AND ts.start_time BETWEEN ? AND ?
    ORDER BY ts.start_time ASC
");
$appointmentsStmt->execute([$userId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Organize appointments by day
$appointmentsByDay = [];
foreach ($appointments as $appointment) {
    $day = intval(date('j', strtotime($appointment['start_time'])));
    if (!isset($appointmentsByDay[$day])) {
        $appointmentsByDay[$day] = [];
    }
    $appointmentsByDay[$day][] = $appointment;
}

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
    return date('g:i A', strtotime($time));
}

// Get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'scheduled':
            return 'info';
        case 'confirmed':
            return 'success';
        case 'completed':
            return 'secondary';
        case 'cancelled':
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
    <title>Calendar View - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/member-dashboard.css">
    <style>
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .calendar-title {
            font-size: 1.5rem;
            font-weight: 500;
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .calendar {
            width: 100%;
            border-collapse: collapse;
        }
        
        .calendar th {
            padding: 10px;
            text-align: center;
            font-weight: 500;
            border-bottom: 1px solid var(--card-border);
        }
        
        .calendar td {
            width: 14.28%;
            height: 120px;
            padding: 5px;
            vertical-align: top;
            border: 1px solid var(--card-border);
        }
        
        .calendar .day-number {
            font-weight: 500;
            margin-bottom: 5px;
            text-align: right;
        }
        
        .calendar .today {
            background-color: var(--primary-light);
            border: 1px solid var(--primary);
        }
        
        .calendar .other-month {
            background-color: rgba(var(--card-bg-rgb), 0.5);
            color: rgba(var(--foreground-rgb), 0.5);
        }
        
        .appointment-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .appointment-dot.scheduled {
            background-color: var(--info);
        }
        
        .appointment-dot.confirmed {
            background-color: var(--success);
        }
        
        .appointment-dot.completed {
            background-color: var(--secondary);
        }
        
        .appointment-dot.cancelled {
            background-color: var(--danger);
        }
        
        .day-appointments {
            max-height: 100px;
            overflow-y: auto;
            font-size: 0.8rem;
        }
        
        .day-appointment {
            padding: 3px 5px;
            margin-bottom: 3px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .day-appointment:hover {
            background-color: var(--primary-light);
        }
        
        .day-appointment.scheduled {
            border-left: 3px solid var(--info);
        }
        
        .day-appointment.confirmed {
            border-left: 3px solid var(--success);
        }
        
        .day-appointment.completed {
            border-left: 3px solid var(--secondary);
        }
        
        .day-appointment.cancelled {
            border-left: 3px solid var(--danger);
            text-decoration: line-through;
            opacity: 0.7;
        }
        
        .appointment-details {
            display: none;
            position: absolute;
            z-index: 100;
            width: 250px;
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 10px;
        }
        
        .appointment-details h4 {
            margin-top: 0;
            margin-bottom: 5px;
        }
        
        .appointment-details .time {
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .appointment-details .trainer {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .appointment-details .actions {
            display: flex;
            justify-content: flex-end;
            gap: 5px;
            margin-top: 10px;
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
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
                    <h1>Calendar View</h1>
                    <p>View your appointments in a monthly calendar</p>
                </div>
                <div class="header-actions">
                    <a href="appointments.php" class="btn btn-outline">
                        <i class="fas fa-list"></i> List View
                    </a>
                    <a href="book-session.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Book Session
                    </a>
                </div>
            </div>
            
            <!-- Calendar -->
            <div class="card">
                <div class="card-content">
                    <div class="calendar-header">
                        <div class="calendar-title">
                            <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                        </div>
                        <div class="calendar-nav">
                            <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn btn-sm btn-outline">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                            <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="btn btn-sm">
                                Today
                            </a>
                            <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn btn-sm btn-outline">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <table class="calendar">
                        <thead>
                            <tr>
                                <th>Sunday</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                                <th>Saturday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                                // Create calendar
                                $dayCount = 1;
                                $currentDay = 1;
                                
                                // Determine the number of rows needed
                                $numRows = ceil(($numDays + $firstDayOfWeek) / 7);
                                
                                for ($row = 0; $row < $numRows; $row++) {
                                    echo "<tr>";
                                    
                                    for ($col = 0; $col < 7; $col++) {
                                        if (($row == 0 && $col < $firstDayOfWeek) || ($currentDay > $numDays)) {
                                            // Empty cells before the first day or after the last day
                                            echo "<td class='other-month'></td>";
                                        } else {
                                            // Check if this is today
                                            $isToday = ($currentDay == date('j') && $month == date('m') && $year == date('Y'));
                                            $cellClass = $isToday ? 'today' : '';
                                            
                                            echo "<td class='$cellClass'>";
                                            echo "<div class='day-number'>$currentDay</div>";
                                            
                                            // Display appointments for this day
                                            if (isset($appointmentsByDay[$currentDay])) {
                                                echo "<div class='day-appointments'>";
                                                foreach ($appointmentsByDay[$currentDay] as $appointment) {
                                                    $appointmentId = $appointment['id'];
                                                    $status = $appointment['status'];
                                                    $time = formatTime($appointment['start_time']);
                                                    $title = htmlspecialchars($appointment['title']);
                                                    
                                                    echo "<div class='day-appointment $status' data-appointment-id='$appointmentId'>";
                                                    echo "<span class='appointment-dot $status'></span>";
                                                    echo "$time $title";
                                                    echo "</div>";
                                                    
                                                    // Create hidden appointment details
                                                    echo "<div id='appointment-details-$appointmentId' class='appointment-details'>";
                                                    echo "<h4>$title</h4>";
                                                    echo "<div class='time'>" . formatTime($appointment['start_time']) . " - " . formatTime($appointment['end_time']) . "</div>";
                                                    echo "<div class='date'>" . formatDate($appointment['start_time']) . "</div>";
                                                    echo "<div class='status'><span class='status-badge " . $status . "'>" . ucfirst($status) . "</span></div>";
                                                    echo "<div class='trainer'><i class='fas fa-user'></i> " . htmlspecialchars($appointment['trainer_name']) . "</div>";
                                                    
                                                    // Only show actions for future appointments that aren't cancelled
                                                    if (strtotime($appointment['start_time']) > time() && $status !== 'cancelled') {
                                                        echo "<div class='actions'>";
                                                        echo "<a href='appointments.php?cancel=" . $appointmentId . "' class='btn btn-sm btn-danger'>Cancel</a>";
                                                        echo "</div>";
                                                    }
                                                    
                                                    echo "</div>";
                                                }
                                                echo "</div>";
                                            }
                                            
                                            echo "</td>";
                                            $currentDay++;
                                        }
                                    }
                                    
                                    echo "</tr>";
                                    
                                    // Break if we've displayed all days
                                    if ($currentDay > $numDays) {
                                        break;
                                    }
                                }
                            ?>
                        </tbody>
                    </table>
                    
                    <div class="legend">
                        <div class="legend-item">
                            <span class="appointment-dot scheduled"></span>
                            <span>Scheduled</span>
                        </div>
                        <div class="legend-item">
                            <span class="appointment-dot confirmed"></span>
                            <span>Confirmed</span>
                        </div>
                        <div class="legend-item">
                            <span class="appointment-dot completed"></span>
                            <span>Completed</span>
                        </div>
                        <div class="legend-item">
                            <span class="appointment-dot cancelled"></span>
                            <span>Cancelled</span>
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
        
        // Appointment details popup
        const appointmentItems = document.querySelectorAll('.day-appointment');
        let currentPopup = null;
        
        appointmentItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                
                const appointmentId = this.getAttribute('data-appointment-id');
                const detailsElement = document.getElementById(`appointment-details-${appointmentId}`);
                
                // Close any open popup
                if (currentPopup && currentPopup !== detailsElement) {
                    currentPopup.style.display = 'none';
                }
                
                // Toggle this popup
                if (detailsElement.style.display === 'block') {
                    detailsElement.style.display = 'none';
                    currentPopup = null;
                } else {
                    // Position the popup
                    const rect = this.getBoundingClientRect();
                    detailsElement.style.top = (rect.bottom + window.scrollY) + 'px';
                    detailsElement.style.left = (rect.left + window.scrollX) + 'px';
                    
                    detailsElement.style.display = 'block';
                    currentPopup = detailsElement;
                }
            });
        });
        
        // Close popup when clicking elsewhere
        document.addEventListener('click', function() {
            if (currentPopup) {
                currentPopup.style.display = 'none';
                currentPopup = null;
            }
        });
    </script>
</body>
</html>
