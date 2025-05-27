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

// Set default theme to dark
$theme = 'dark';

// Enhanced theme preference system
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

    if (!$tableExists) {
        $conn->exec("
            CREATE TABLE trainer_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                theme_preference VARCHAR(20) DEFAULT 'dark',
                notification_email TINYINT(1) DEFAULT 1,
                notification_sms TINYINT(1) DEFAULT 0,
                auto_confirm_appointments TINYINT(1) DEFAULT 0,
                default_session_duration INT DEFAULT 60,
                working_hours_start TIME DEFAULT '06:00:00',
                working_hours_end TIME DEFAULT '22:00:00',
                break_duration INT DEFAULT 15,
                max_daily_sessions INT DEFAULT 12,
                booking_advance_days INT DEFAULT 30,
                cancellation_hours INT DEFAULT 24,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id)
            )
        ");
        
        $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id, theme_preference) VALUES (?, 'dark')");
        $stmt->execute([$userId]);
    }

    $stmt = $conn->prepare("SELECT * FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($settings && isset($settings['theme_preference'])) {
        $theme = $settings['theme_preference'];
    }
} catch (PDOException $e) {
    // Use default theme on error
}

// Get all active gym members (actual registered members)
$members = [];
try {
    // Fetch all active members from the users table with role 'Member'
    $memberQuery = "
        SELECT u.id, u.name, u.email, u.profile_image, u.phone, u.date_of_birth, u.created_at,
               COUNT(DISTINCT pt.id) as progress_entries,
               MAX(pt.tracking_date) as last_progress_date,
               COUNT(DISTINCT ts.id) as total_sessions
        FROM users u
        LEFT JOIN progress_tracking pt ON u.id = pt.member_id AND pt.trainer_id = ?
        LEFT JOIN trainer_schedule ts ON u.id = ts.member_id AND ts.trainer_id = ?
        WHERE u.role = 'Member' AND u.status = 'active'
        GROUP BY u.id, u.name, u.email, u.profile_image, u.phone, u.date_of_birth, u.created_at
        ORDER BY u.name ASC
    ";
    
    $memberStmt = $conn->prepare($memberQuery);
    $memberStmt->execute([$userId, $userId]);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching members: " . $e->getMessage());
}

// Get member filter from URL parameter
$memberFilter = isset($_GET['member_id']) ? intval($_GET['member_id']) : 0;
$dateFilter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';

// Enhanced progress tracking table
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
                visceral_fat DECIMAL(5,2) NULL,
                bmr INT NULL,
                chest DECIMAL(5,2) NULL,
                waist DECIMAL(5,2) NULL,
                hips DECIMAL(5,2) NULL,
                arms DECIMAL(5,2) NULL,
                thighs DECIMAL(5,2) NULL,
                neck DECIMAL(5,2) NULL,
                forearms DECIMAL(5,2) NULL,
                calves DECIMAL(5,2) NULL,
                workout_performance JSON NULL,
                fitness_goals TEXT NULL,
                achievements TEXT NULL,
                challenges TEXT NULL,
                notes TEXT NULL,
                photos JSON NULL,
                mood_rating INT DEFAULT 5,
                energy_level INT DEFAULT 5,
                sleep_quality INT DEFAULT 5,
                stress_level INT DEFAULT 5,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (trainer_id),
                INDEX (member_id),
                INDEX (tracking_date),
                UNIQUE KEY unique_member_date (trainer_id, member_id, tracking_date)
            )
        ");
    }
} catch (PDOException $e) {
    error_log("Error creating progress_tracking table: " . $e->getMessage());
}

// Get progress data with advanced filtering
$progressData = [];
$memberDetails = null;

try {
    if ($memberFilter > 0) {
        // Get member details
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'Member' AND status = 'active'");
        $stmt->execute([$memberFilter]);
        $memberDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Build date filter condition
        $dateCondition = "";
        $dateParams = [$userId, $memberFilter];
        
        switch ($dateFilter) {
            case 'week':
                $dateCondition = " AND tracking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $dateCondition = " AND tracking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                break;
            case '3months':
                $dateCondition = " AND tracking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                break;
            case '6months':
                $dateCondition = " AND tracking_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)";
                break;
            case 'year':
                $dateCondition = " AND tracking_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
                break;
        }
        
        // Get progress data for specific member
        $stmt = $conn->prepare("
            SELECT * FROM progress_tracking
            WHERE trainer_id = ? AND member_id = ? $dateCondition
            ORDER BY tracking_date DESC
        ");
        $stmt->execute($dateParams);
        $progressData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Get latest progress entry for each member
        $stmt = $conn->prepare("
            SELECT pt.*, u.name, u.profile_image, u.email
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
    error_log("Error fetching progress data: " . $e->getMessage());
}

// Get analytics data for dashboard
$analyticsData = [];
try {
    if ($memberFilter > 0) {
        // Member-specific analytics
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_entries,
                MIN(tracking_date) as first_entry,
                MAX(tracking_date) as last_entry,
                AVG(weight) as avg_weight,
                AVG(body_fat) as avg_body_fat,
                AVG(muscle_mass) as avg_muscle_mass,
                AVG(mood_rating) as avg_mood,
                AVG(energy_level) as avg_energy,
                AVG(sleep_quality) as avg_sleep,
                AVG(stress_level) as avg_stress
            FROM progress_tracking 
            WHERE trainer_id = ? AND member_id = ?
        ");
        $stmt->execute([$userId, $memberFilter]);
        $analyticsData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get progress trends
        $stmt = $conn->prepare("
            SELECT 
                tracking_date,
                weight,
                body_fat,
                muscle_mass,
                mood_rating,
                energy_level
            FROM progress_tracking 
            WHERE trainer_id = ? AND member_id = ?
            ORDER BY tracking_date ASC
        ");
        $stmt->execute([$userId, $memberFilter]);
        $trendData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $analyticsData['trends'] = $trendData;
    } else {
        // Overall analytics
        $stmt = $conn->prepare("
            SELECT 
                COUNT(DISTINCT member_id) as active_members,
                COUNT(*) as total_entries,
                AVG(weight) as avg_weight,
                AVG(body_fat) as avg_body_fat,
                AVG(muscle_mass) as avg_muscle_mass
            FROM progress_tracking 
            WHERE trainer_id = ?
        ");
        $stmt->execute([$userId]);
        $analyticsData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching analytics data: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_progress'])) {
        try {
            $memberId = $_POST['member_id'];
            $trackingDate = $_POST['tracking_date'];
            
            // Verify member exists and is active
            $memberCheckStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'Member' AND status = 'active'");
            $memberCheckStmt->execute([$memberId]);
            $memberExists = $memberCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$memberExists) {
                throw new Exception('Selected member is not valid or not active');
            }
            
            // Collect all form data
            $formData = [
                'weight' => !empty($_POST['weight']) ? $_POST['weight'] : null,
                'body_fat' => !empty($_POST['body_fat']) ? $_POST['body_fat'] : null,
                'muscle_mass' => !empty($_POST['muscle_mass']) ? $_POST['muscle_mass'] : null,
                'visceral_fat' => !empty($_POST['visceral_fat']) ? $_POST['visceral_fat'] : null,
                'bmr' => !empty($_POST['bmr']) ? $_POST['bmr'] : null,
                'chest' => !empty($_POST['chest']) ? $_POST['chest'] : null,
                'waist' => !empty($_POST['waist']) ? $_POST['waist'] : null,
                'hips' => !empty($_POST['hips']) ? $_POST['hips'] : null,
                'arms' => !empty($_POST['arms']) ? $_POST['arms'] : null,
                'thighs' => !empty($_POST['thighs']) ? $_POST['thighs'] : null,
                'neck' => !empty($_POST['neck']) ? $_POST['neck'] : null,
                'forearms' => !empty($_POST['forearms']) ? $_POST['forearms'] : null,
                'calves' => !empty($_POST['calves']) ? $_POST['calves'] : null,
                'fitness_goals' => $_POST['fitness_goals'] ?? '',
                'achievements' => $_POST['achievements'] ?? '',
                'challenges' => $_POST['challenges'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'mood_rating' => intval($_POST['mood_rating'] ?? 5),
                'energy_level' => intval($_POST['energy_level'] ?? 5),
                'sleep_quality' => intval($_POST['sleep_quality'] ?? 5),
                'stress_level' => intval($_POST['stress_level'] ?? 5)
            ];
            
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
                    SET weight = ?, body_fat = ?, muscle_mass = ?, visceral_fat = ?, bmr = ?,
                        chest = ?, waist = ?, hips = ?, arms = ?, thighs = ?, neck = ?, 
                        forearms = ?, calves = ?, fitness_goals = ?, achievements = ?, 
                        challenges = ?, notes = ?, mood_rating = ?, energy_level = ?, 
                        sleep_quality = ?, stress_level = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $formData['weight'], $formData['body_fat'], $formData['muscle_mass'],
                    $formData['visceral_fat'], $formData['bmr'], $formData['chest'],
                    $formData['waist'], $formData['hips'], $formData['arms'],
                    $formData['thighs'], $formData['neck'], $formData['forearms'],
                    $formData['calves'], $formData['fitness_goals'], $formData['achievements'],
                    $formData['challenges'], $formData['notes'], $formData['mood_rating'],
                    $formData['energy_level'], $formData['sleep_quality'], $formData['stress_level'],
                    $existingEntry['id']
                ]);
                
                $message = 'Progress data updated successfully for ' . $memberExists['name'] . '!';
            } else {
                // Insert new entry
                $stmt = $conn->prepare("
                    INSERT INTO progress_tracking
                    (trainer_id, member_id, tracking_date, weight, body_fat, muscle_mass, 
                     visceral_fat, bmr, chest, waist, hips, arms, thighs, neck, forearms, 
                     calves, fitness_goals, achievements, challenges, notes, mood_rating, 
                     energy_level, sleep_quality, stress_level)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $memberId, $trackingDate, $formData['weight'], $formData['body_fat'],
                    $formData['muscle_mass'], $formData['visceral_fat'], $formData['bmr'],
                    $formData['chest'], $formData['waist'], $formData['hips'], $formData['arms'],
                    $formData['thighs'], $formData['neck'], $formData['forearms'], $formData['calves'],
                    $formData['fitness_goals'], $formData['achievements'], $formData['challenges'],
                    $formData['notes'], $formData['mood_rating'], $formData['energy_level'],
                    $formData['sleep_quality'], $formData['stress_level']
                ]);
                
                $message = 'Progress data added successfully for ' . $memberExists['name'] . '!';
            }
            
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: progress-tracking.php?member_id=$memberId&added=1");
            exit;
        } catch (Exception $e) {
            $message = 'Error saving progress data: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete_progress'])) {
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

// Helper functions
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function calculateBMI($weight, $height) {
    if ($weight && $height) {
        return round($weight / (($height / 100) ** 2), 1);
    }
    return null;
}

function getBMICategory($bmi) {
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 25) return 'Normal';
    if ($bmi < 30) return 'Overweight';
    return 'Obese';
}

function getProgressTrend($data, $field) {
    if (count($data) < 2) return 'stable';
    
    $recent = array_slice($data, -2);
    $change = $recent[1][$field] - $recent[0][$field];
    
    if (abs($change) < 0.1) return 'stable';
    return $change > 0 ? 'up' : 'down';
}

// Prepare chart data
$chartData = [
    'weight' => ['labels' => [], 'values' => []],
    'bodyFat' => ['labels' => [], 'values' => []],
    'muscleMass' => ['labels' => [], 'values' => []],
    'measurements' => ['labels' => [], 'chest' => [], 'waist' => [], 'hips' => [], 'arms' => [], 'thighs' => []],
    'wellness' => ['labels' => [], 'mood' => [], 'energy' => [], 'sleep' => [], 'stress' => []]
];

if ($memberFilter > 0 && !empty($analyticsData['trends'])) {
    foreach ($analyticsData['trends'] as $entry) {
        $formattedDate = date('M j', strtotime($entry['tracking_date']));
        
        $chartData['weight']['labels'][] = $formattedDate;
        $chartData['weight']['values'][] = $entry['weight'];
        
        $chartData['bodyFat']['labels'][] = $formattedDate;
        $chartData['bodyFat']['values'][] = $entry['body_fat'];
        
        $chartData['muscleMass']['labels'][] = $formattedDate;
        $chartData['muscleMass']['values'][] = $entry['muscle_mass'];
        
        $chartData['wellness']['labels'][] = $formattedDate;
        $chartData['wellness']['mood'][] = $entry['mood_rating'];
        $chartData['wellness']['energy'][] = $entry['energy_level'];
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Progress Tracking - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Dark theme (default) */
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --secondary: #2c2c2c;
            --background: #121212;
            --surface: #1e1e1e;
            --surface-variant: #2a2a2a;
            --on-background: #ffffff;
            --on-surface: #e0e0e0;
            --on-surface-variant: #b0b0b0;
            --border: #333333;
            --success: #4caf50;
            --warning: #ff9800;
            --error: #f44336;
            --info: #2196f3;
        }

        [data-theme="light"] {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --secondary: #f5f5f5;
            --background: #ffffff;
            --surface: #f8f9fa;
            --surface-variant: #e9ecef;
            --on-background: #212529;
            --on-surface: #495057;
            --on-surface-variant: #6c757d;
            --border: #dee2e6;
            --success: #28a745;
            --warning: #ffc107;
            --error: #dc3545;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background);
            color: var(--on-background);
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Enhanced Sidebar */
        .sidebar {
            width: 280px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .sidebar-header h2 {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.5rem;
            margin-top: 0.5rem;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section-title {
            padding: 0 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--on-surface-variant);
            margin-bottom: 1rem;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 2rem;
            color: var(--on-surface);
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background: var(--surface-variant);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu a.active {
            background: var(--surface-variant);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 600;
        }

        .sidebar-menu i {
            width: 20px;
            margin-right: 1rem;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            background: var(--background);
        }

        /* Enhanced Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--on-background);
            margin-bottom: 0.25rem;
        }

        .header p {
            color: var(--on-surface-variant);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Theme Toggle */
        .theme-toggle {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .theme-toggle:hover {
            background: var(--surface-variant);
        }

        /* Enhanced Cards */
        .card {
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }

        .card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-variant);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-content {
            padding: 2rem;
        }

        /* Enhanced Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--on-surface);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            background: var(--surface-variant);
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #d32f2f;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Analytics Dashboard */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .analytics-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .analytics-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary);
        }

        .analytics-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .analytics-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }

        .analytics-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .analytics-label {
            color: var(--on-surface-variant);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .analytics-trend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            margin-top: 0.5rem;
            font-size: 0.8rem;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--error); }
        .trend-stable { color: var(--on-surface-variant); }

        /* Enhanced Filters */
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--on-surface);
            font-size: 0.875rem;
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--background);
            color: var(--on-background);
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            position: relative;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--on-surface);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Progress Table */
        .table-responsive {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface);
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            background: var(--surface-variant);
            font-weight: 600;
            color: var(--on-surface);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tr:hover {
            background: var(--surface-variant);
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .member-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }

        .member-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }

        /* Enhanced Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--surface);
            border-radius: 12px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface-variant);
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--on-surface);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--on-surface-variant);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .close-modal:hover {
            background: var(--surface);
            color: var(--on-surface);
        }

        .modal-body {
            padding: 2rem;
        }

        /* Enhanced Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--on-surface);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        /* Rating System */
        .rating-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .rating-input {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .rating-slider {
            flex: 1;
            height: 6px;
            border-radius: 3px;
            background: var(--surface-variant);
            outline: none;
            -webkit-appearance: none;
        }

        .rating-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
        }

        .rating-value {
            min-width: 30px;
            text-align: center;
            font-weight: 600;
            color: var(--primary);
        }

        /* Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            color: var(--error);
            border: 1px solid rgba(244, 67, 54, 0.2);
        }

        .alert .close {
            position: absolute;
            top: 0.5rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        .alert .close:hover {
            opacity: 1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--on-surface-variant);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        /* Mobile Responsiveness */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 1.2rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .header {
                padding-top: 4rem;
            }

            .analytics-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .charts-container {
                grid-template-columns: 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Member count indicator */
        .member-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }

        /* Progress indicators */
        .progress-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .progress-bar {
            width: 60px;
            height: 4px;
            background: var(--surface-variant);
            border-radius: 2px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard-container">
        <!-- Enhanced Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div style="display: flex; align-items: center;">
                    <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                    <h2>EliteFit Gym</h2>
                </div>
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
            <!-- Enhanced Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-chart-line" style="color: var(--primary);"></i> Advanced Progress Tracking</h1>
                    <p>Comprehensive fitness analytics and member progress monitoring</p>
                </div>
                <div class="header-actions">
                    <div class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-moon" id="themeIcon"></i>
                        <span id="themeText">Dark</span>
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addProgressModal')">
                        <i class="fas fa-plus"></i> Add Progress Data
                    </button>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Analytics Dashboard -->
            <?php if ($memberFilter > 0 && !empty($analyticsData)): ?>
                <div class="analytics-grid">
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['total_entries'] ?? 0; ?></div>
                        <div class="analytics-label">Total Entries</div>
                        <?php if (!empty($analyticsData['first_entry'])): ?>
                            <div class="analytics-trend">
                                <span>Since <?php echo date('M Y', strtotime($analyticsData['first_entry'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-weight"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_weight'] ? number_format($analyticsData['avg_weight'], 1) : '-'; ?></div>
                        <div class="analytics-label">Avg Weight (kg)</div>
                        <?php if (!empty($analyticsData['trends']) && count($analyticsData['trends']) >= 2): ?>
                            <?php $trend = getProgressTrend($analyticsData['trends'], 'weight'); ?>
                            <div class="analytics-trend trend-<?php echo $trend; ?>">
                                <i class="fas fa-arrow-<?php echo $trend === 'up' ? 'up' : ($trend === 'down' ? 'down' : 'right'); ?>"></i>
                                <span><?php echo ucfirst($trend); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_body_fat'] ? number_format($analyticsData['avg_body_fat'], 1) : '-'; ?></div>
                        <div class="analytics-label">Avg Body Fat (%)</div>
                        <?php if (!empty($analyticsData['trends']) && count($analyticsData['trends']) >= 2): ?>
                            <?php $trend = getProgressTrend($analyticsData['trends'], 'body_fat'); ?>
                            <div class="analytics-trend trend-<?php echo $trend; ?>">
                                <i class="fas fa-arrow-<?php echo $trend === 'up' ? 'up' : ($trend === 'down' ? 'down' : 'right'); ?>"></i>
                                <span><?php echo ucfirst($trend); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-dumbbell"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_muscle_mass'] ? number_format($analyticsData['avg_muscle_mass'], 1) : '-'; ?></div>
                        <div class="analytics-label">Avg Muscle Mass (kg)</div>
                        <?php if (!empty($analyticsData['trends']) && count($analyticsData['trends']) >= 2): ?>
                            <?php $trend = getProgressTrend($analyticsData['trends'], 'muscle_mass'); ?>
                            <div class="analytics-trend trend-<?php echo $trend; ?>">
                                <i class="fas fa-arrow-<?php echo $trend === 'up' ? 'up' : ($trend === 'down' ? 'down' : 'right'); ?>"></i>
                                <span><?php echo ucfirst($trend); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-smile"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_mood'] ? number_format($analyticsData['avg_mood'], 1) : '-'; ?></div>
                        <div class="analytics-label">Avg Mood Rating</div>
                        <div class="progress-indicator">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo ($analyticsData['avg_mood'] ?? 0) * 20; ?>%;"></div>
                            </div>
                            <span>/5</span>
                        </div>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_energy'] ? number_format($analyticsData['avg_energy'], 1) : '-'; ?></div>
                        <div class="analytics-label">Avg Energy Level</div>
                        <div class="progress-indicator">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo ($analyticsData['avg_energy'] ?? 0) * 20; ?>%;"></div>
                            </div>
                            <span>/5</span>
                        </div>
                    </div>
                </div>
            <?php elseif ($memberFilter == 0 && !empty($analyticsData)): ?>
                <div class="analytics-grid">
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['active_members'] ?? 0; ?></div>
                        <div class="analytics-label">Active Members</div>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['total_entries'] ?? 0; ?></div>
                        <div class="analytics-label">Total Progress Entries</div>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-weight"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_weight'] ? number_format($analyticsData['avg_weight'], 1) : '-'; ?></div>
                        <div class="analytics-label">Overall Avg Weight (kg)</div>
                    </div>
                    
                    <div class="analytics-card">
                        <div class="analytics-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="analytics-value"><?php echo $analyticsData['avg_body_fat'] ? number_format($analyticsData['avg_body_fat'], 1) : '-'; ?></div>
                        <div class="analytics-label">Overall Avg Body Fat (%)</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Enhanced Filters -->
            <div class="card">
                <div class="card-content">
                    <div class="filters">
                        <div class="filter-group">
                            <label for="member-filter">Select Member:
                                <span class="member-count"><?php echo count($members); ?> available</span>
                            </label>
                            <select id="member-filter" class="form-control">
                                <option value="0">All Members Overview</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?php echo $member['id']; ?>" <?php echo $memberFilter == $member['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['name']); ?>
                                        <?php if ($member['progress_entries'] > 0): ?>
                                            (<?php echo $member['progress_entries']; ?> entries)
                                        <?php else: ?>
                                            (No data yet)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($memberFilter > 0): ?>
                            <div class="filter-group">
                                <label for="date-filter">Time Period:</label>
                                <select id="date-filter" class="form-control">
                                    <option value="all" <?php echo $dateFilter === 'all' ? 'selected' : ''; ?>>All Time</option>
                                    <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                    <option value="month" <?php echo $dateFilter === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                    <option value="3months" <?php echo $dateFilter === '3months' ? 'selected' : ''; ?>>Last 3 Months</option>
                                    <option value="6months" <?php echo $dateFilter === '6months' ? 'selected' : ''; ?>>Last 6 Months</option>
                                    <option value="year" <?php echo $dateFilter === 'year' ? 'selected' : ''; ?>>Last Year</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <button class="btn btn-primary" onclick="openModal('addProgressModal')">
                                    <i class="fas fa-plus"></i> Add Progress Data
                                </button>
                            </div>
                            
                            <div class="filter-group">
                                <label>&nbsp;</label>
                                <button class="btn btn-outline" onclick="exportProgressReport()">
                                    <i class="fas fa-download"></i> Export Report
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Advanced Charts -->
            <?php if ($memberFilter > 0 && !empty($chartData['weight']['values'])): ?>
                <div class="charts-container">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Weight Progress</h3>
                            <div class="chart-controls">
                                <button class="btn btn-sm btn-outline" onclick="toggleChartType('weightChart')">
                                    <i class="fas fa-chart-line"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="weightChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Body Composition</h3>
                            <div class="chart-controls">
                                <button class="btn btn-sm btn-outline" onclick="toggleChartType('bodyCompositionChart')">
                                    <i class="fas fa-chart-area"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="bodyCompositionChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Wellness Metrics</h3>
                            <div class="chart-controls">
                                <button class="btn btn-sm btn-outline" onclick="toggleChartType('wellnessChart')">
                                    <i class="fas fa-chart-radar"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="wellnessChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Progress Summary</h3>
                            <div class="chart-controls">
                                <button class="btn btn-sm btn-outline" onclick="toggleChartType('progressSummaryChart')">
                                    <i class="fas fa-chart-pie"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="progressSummaryChart"></canvas>
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
                            <i class="fas fa-table"></i> Recent Progress Data Overview
                        <?php endif; ?>
                    </h2>
                    <div style="display: flex; gap: 1rem;">
                        <?php if ($memberFilter > 0): ?>
                            <button class="btn btn-sm btn-outline" onclick="generateProgressReport()">
                                <i class="fas fa-file-pdf"></i> Generate Report
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-primary" onclick="openModal('addProgressModal')">
                            <i class="fas fa-plus"></i> Add Entry
                        </button>
                    </div>
                </div>
                <div class="card-content">
                    <?php if (empty($progressData)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>No progress data available</p>
                            <?php if ($memberFilter > 0): ?>
                                <p style="margin-bottom: 2rem; font-size: 0.9rem;">Start tracking progress for <?php echo htmlspecialchars($memberDetails['name'] ?? ''); ?></p>
                                <button class="btn btn-primary" onclick="openModal('addProgressModal')">
                                    <i class="fas fa-plus"></i> Add First Progress Entry
                                </button>
                            <?php else: ?>
                                <p style="margin-bottom: 2rem; font-size: 0.9rem;">Select a member to view and track their progress</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <?php if ($memberFilter == 0): ?>
                                            <th>Member</th>
                                        <?php endif; ?>
                                        <th>Date</th>
                                        <th>Weight (kg)</th>
                                        <th>Body Fat (%)</th>
                                        <th>Muscle Mass (kg)</th>
                                        <th>BMR</th>
                                        <th>Measurements</th>
                                        <th>Wellness</th>
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
                                            <td>
                                                <?php if ($entry['weight']): ?>
                                                    <strong><?php echo number_format($entry['weight'], 1); ?></strong>
                                                <?php else: ?>
                                                    <span style="color: var(--on-surface-variant);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($entry['body_fat']): ?>
                                                    <strong><?php echo number_format($entry['body_fat'], 1); ?>%</strong>
                                                <?php else: ?>
                                                    <span style="color: var(--on-surface-variant);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($entry['muscle_mass']): ?>
                                                    <strong><?php echo number_format($entry['muscle_mass'], 1); ?></strong>
                                                <?php else: ?>
                                                    <span style="color: var(--on-surface-variant);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($entry['bmr']): ?>
                                                    <strong><?php echo number_format($entry['bmr']); ?></strong> cal
                                                <?php else: ?>
                                                    <span style="color: var(--on-surface-variant);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($entry['chest'] || $entry['waist'] || $entry['hips'] || $entry['arms'] || $entry['thighs']): ?>
                                                    <div class="measurements-summary" style="cursor: pointer;" onclick="showMeasurements(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-ruler" style="color: var(--primary);"></i>
                                                        <span style="margin-left: 0.5rem;">View Details</span>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: var(--on-surface-variant);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="wellness-summary">
                                                    <div style="display: flex; gap: 0.5rem; font-size: 0.8rem;">
                                                        <span title="Mood">😊 <?php echo $entry['mood_rating']; ?></span>
                                                        <span title="Energy">⚡ <?php echo $entry['energy_level']; ?></span>
                                                        <span title="Sleep">😴 <?php echo $entry['sleep_quality']; ?></span>
                                                        <span title="Stress">😰 <?php echo $entry['stress_level']; ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($entry['notes'])): ?>
                                                    <div class="notes-preview" style="cursor: pointer;" onclick="showNotes(<?php echo $entry['id']; ?>)">
                                                        <i class="fas fa-sticky-note" style="color: var(--primary);"></i>
                                                        <span style="margin-left: 0.5rem;">View Notes</span>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: var(--on-surface-variant);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 0.5rem;">
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
    
    <!-- Enhanced Add Progress Modal -->
    <div class="modal" id="addProgressModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> Add Comprehensive Progress Data</h3>
                <button class="close-modal" onclick="closeModal('addProgressModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form action="progress-tracking.php" method="post" id="addProgressForm">
                    <input type="hidden" name="add_progress" value="1">
                    
                    <!-- Basic Information -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-info-circle"></i> Basic Information
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="member_id">Member *</label>
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
                                <label for="tracking_date">Date *</label>
                                <input type="date" id="tracking_date" name="tracking_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Body Composition -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-weight"></i> Body Composition
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="weight">Weight (kg)</label>
                                <input type="number" id="weight" name="weight" class="form-control" step="0.1" min="0" max="300">
                            </div>
                            
                            <div class="form-group">
                                <label for="body_fat">Body Fat (%)</label>
                                <input type="number" id="body_fat" name="body_fat" class="form-control" step="0.1" min="0" max="50">
                            </div>
                            
                            <div class="form-group">
                                <label for="muscle_mass">Muscle Mass (kg)</label>
                                <input type="number" id="muscle_mass" name="muscle_mass" class="form-control" step="0.1" min="0" max="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="visceral_fat">Visceral Fat (%)</label>
                                <input type="number" id="visceral_fat" name="visceral_fat" class="form-control" step="0.1" min="0" max="30">
                            </div>
                            
                            <div class="form-group">
                                <label for="bmr">BMR (calories)</label>
                                <input type="number" id="bmr" name="bmr" class="form-control" min="800" max="3000">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Body Measurements -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-ruler"></i> Body Measurements (cm)
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="chest">Chest</label>
                                <input type="number" id="chest" name="chest" class="form-control" step="0.1" min="0" max="200">
                            </div>
                            
                            <div class="form-group">
                                <label for="waist">Waist</label>
                                <input type="number" id="waist" name="waist" class="form-control" step="0.1" min="0" max="200">
                            </div>
                            
                            <div class="form-group">
                                <label for="hips">Hips</label>
                                <input type="number" id="hips" name="hips" class="form-control" step="0.1" min="0" max="200">
                            </div>
                            
                            <div class="form-group">
                                <label for="arms">Arms</label>
                                <input type="number" id="arms" name="arms" class="form-control" step="0.1" min="0" max="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="thighs">Thighs</label>
                                <input type="number" id="thighs" name="thighs" class="form-control" step="0.1" min="0" max="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="neck">Neck</label>
                                <input type="number" id="neck" name="neck" class="form-control" step="0.1" min="0" max="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="forearms">Forearms</label>
                                <input type="number" id="forearms" name="forearms" class="form-control" step="0.1" min="0" max="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="calves">Calves</label>
                                <input type="number" id="calves" name="calves" class="form-control" step="0.1" min="0" max="100">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Wellness Metrics -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-heart"></i> Wellness Metrics
                        </h4>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <div class="rating-group">
                                    <label for="mood_rating">Mood Rating</label>
                                    <div class="rating-input">
                                        <input type="range" id="mood_rating" name="mood_rating" class="rating-slider" min="1" max="5" value="5">
                                        <span class="rating-value" id="mood_value">5</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="rating-group">
                                    <label for="energy_level">Energy Level</label>
                                    <div class="rating-input">
                                        <input type="range" id="energy_level" name="energy_level" class="rating-slider" min="1" max="5" value="5">
                                        <span class="rating-value" id="energy_value">5</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="rating-group">
                                    <label for="sleep_quality">Sleep Quality</label>
                                    <div class="rating-input">
                                        <input type="range" id="sleep_quality" name="sleep_quality" class="rating-slider" min="1" max="5" value="5">
                                        <span class="rating-value" id="sleep_value">5</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="rating-group">
                                    <label for="stress_level">Stress Level</label>
                                    <div class="rating-input">
                                        <input type="range" id="stress_level" name="stress_level" class="rating-slider" min="1" max="5" value="3">
                                        <span class="rating-value" id="stress_value">3</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Goals and Notes -->
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-target"></i> Goals & Notes
                        </h4>
                        
                        <div class="form-group">
                            <label for="fitness_goals">Current Fitness Goals</label>
                            <textarea id="fitness_goals" name="fitness_goals" class="form-control" rows="2" 
                                      placeholder="What are the member's current fitness goals?"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="achievements">Recent Achievements</label>
                            <textarea id="achievements" name="achievements" class="form-control" rows="2" 
                                      placeholder="What has the member achieved recently?"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="challenges">Current Challenges</label>
                            <textarea id="challenges" name="challenges" class="form-control" rows="2" 
                                      placeholder="What challenges is the member facing?"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Additional Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3" 
                                      placeholder="Any additional observations, recommendations, or notes..."></textarea>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addProgressModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Progress Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteConfirmModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
                <button class="close-modal" onclick="closeModal('deleteConfirmModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this progress entry? This action cannot be undone.</p>
                
                <form action="progress-tracking.php" method="post">
                    <input type="hidden" name="delete_progress" value="1">
                    <input type="hidden" id="delete_progress_id" name="delete_progress_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('deleteConfirmModal')">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Entry
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Theme management
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            
            // Update theme toggle button
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            
            if (newTheme === 'dark') {
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark';
            } else {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light';
            }
            
            // Save theme preference
            fetch('update_theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ theme: newTheme })
            });
        }

        // Initialize theme
        document.addEventListener('DOMContentLoaded', function() {
            const theme = '<?php echo $theme; ?>';
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            
            if (theme === 'dark') {
                themeIcon.className = 'fas fa-moon';
                themeText.textContent = 'Dark';
            } else {
                themeIcon.className = 'fas fa-sun';
                themeText.textContent = 'Light';
            }
        });

        // Modal management
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        });

        // Filter functionality
        document.getElementById('member-filter').addEventListener('change', function() {
            const memberId = this.value;
            const dateFilter = document.getElementById('date-filter') ? document.getElementById('date-filter').value : 'all';
            
            let url = `progress-tracking.php`;
            const params = [];
            
            if (memberId > 0) params.push(`member_id=${memberId}`);
            if (dateFilter !== 'all') params.push(`date_filter=${dateFilter}`);
            
            if (params.length > 0) {
                url += '?' + params.join('&');
            }
            
            window.location.href = url;
        });

        if (document.getElementById('date-filter')) {
            document.getElementById('date-filter').addEventListener('change', function() {
                const memberId = document.getElementById('member-filter').value;
                const dateFilter = this.value;
                
                let url = `progress-tracking.php`;
                const params = [];
                
                if (memberId > 0) params.push(`member_id=${memberId}`);
                if (dateFilter !== 'all') params.push(`date_filter=${dateFilter}`);
                
                if (params.length > 0) {
                    url += '?' + params.join('&');
                }
                
                window.location.href = url;
            });
        }

        // Rating sliders
        document.querySelectorAll('.rating-slider').forEach(slider => {
            const valueDisplay = document.getElementById(slider.id.replace('_', '_') + '_value');
            if (valueDisplay) {
                slider.addEventListener('input', function() {
                    valueDisplay.textContent = this.value;
                });
            }
        });

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
        });

        function initializeCharts() {
            const chartData = <?php echo json_encode($chartData); ?>;
            
            // Weight Progress Chart
            if (document.getElementById('weightChart') && chartData.weight.values.length > 0) {
                const ctx = document.getElementById('weightChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.weight.labels,
                        datasets: [{
                            label: 'Weight (kg)',
                            data: chartData.weight.values,
                            backgroundColor: 'rgba(255, 107, 53, 0.1)',
                            borderColor: '#ff6b35',
                            borderWidth: 3,
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: '#b0b0b0'
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: '#b0b0b0'
                                }
                            }
                        }
                    }
                });
            }
            
            // Body Composition Chart
            if (document.getElementById('bodyCompositionChart') && chartData.bodyFat.values.length > 0) {
                const ctx = document.getElementById('bodyCompositionChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.bodyFat.labels,
                        datasets: [{
                            label: 'Body Fat (%)',
                            data: chartData.bodyFat.values,
                            backgroundColor: 'rgba(244, 67, 54, 0.1)',
                            borderColor: '#f44336',
                            borderWidth: 3,
                            tension: 0.4
                        }, {
                            label: 'Muscle Mass (kg)',
                            data: chartData.muscleMass.values,
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            borderColor: '#4caf50',
                            borderWidth: 3,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: '#e0e0e0'
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: '#b0b0b0'
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: '#b0b0b0'
                                }
                            }
                        }
                    }
                });
            }
            
            // Wellness Chart
            if (document.getElementById('wellnessChart') && chartData.wellness.mood.length > 0) {
                const ctx = document.getElementById('wellnessChart').getContext('2d');
                new Chart(ctx, {
                    type: 'radar',
                    data: {
                        labels: ['Mood', 'Energy', 'Sleep', 'Stress (Inverted)'],
                        datasets: [{
                            label: 'Wellness Metrics',
                            data: [
                                chartData.wellness.mood[chartData.wellness.mood.length - 1],
                                chartData.wellness.energy[chartData.wellness.energy.length - 1],
                                5, // Sleep placeholder
                                5 - 3 // Stress inverted placeholder
                            ],
                            backgroundColor: 'rgba(255, 107, 53, 0.2)',
                            borderColor: '#ff6b35',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            r: {
                                beginAtZero: true,
                                max: 5,
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                pointLabels: {
                                    color: '#e0e0e0'
                                },
                                ticks: {
                                    color: '#b0b0b0',
                                    backdropColor: 'transparent'
                                }
                            }
                        }
                    }
                });
            }
            
            // Progress Summary Pie Chart
            if (document.getElementById('progressSummaryChart')) {
                const ctx = document.getElementById('progressSummaryChart').getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Weight Progress', 'Body Fat Reduction', 'Muscle Gain', 'Wellness'],
                        datasets: [{
                            data: [25, 30, 35, 10],
                            backgroundColor: [
                                '#ff6b35',
                                '#f44336',
                                '#4caf50',
                                '#2196f3'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#e0e0e0',
                                    padding: 20
                                }
                            }
                        }
                    }
                });
            }
        }

        // Progress management functions
        function editProgress(progressId) {
            // Implementation for editing progress
            window.location.href = `edit_progress.php?id=${progressId}`;
        }

        function confirmDelete(progressId) {
            document.getElementById('delete_progress_id').value = progressId;
            openModal('deleteConfirmModal');
        }

        function showMeasurements(progressId) {
            // Implementation for showing detailed measurements
            alert('Detailed measurements view - to be implemented');
        }

        function showNotes(progressId) {
            // Implementation for showing detailed notes
            alert('Detailed notes view - to be implemented');
        }

        function exportProgressReport() {
            const memberId = document.getElementById('member-filter').value;
            const dateFilter = document.getElementById('date-filter') ? document.getElementById('date-filter').value : 'all';
            
            let url = `export_progress_report.php`;
            const params = [];
            
            if (memberId > 0) params.push(`member_id=${memberId}`);
            if (dateFilter !== 'all') params.push(`date_filter=${dateFilter}`);
            
            if (params.length > 0) {
                url += '?' + params.join('&');
            }
            
            window.open(url, '_blank');
        }

        function generateProgressReport() {
            const memberId = document.getElementById('member-filter').value;
            if (memberId > 0) {
                window.open(`generate_progress_pdf.php?member_id=${memberId}`, '_blank');
            }
        }

        function toggleChartType(chartId) {
            // Implementation for toggling chart types
            console.log('Toggle chart type for:', chartId);
        }

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });

        // Form validation
        document.getElementById('addProgressForm').addEventListener('submit', function(e) {
            const memberId = document.getElementById('member_id').value;
            
            if (!memberId) {
                e.preventDefault();
                alert('Please select a member for this progress entry.');
                document.getElementById('member_id').focus();
                return false;
            }
        });
    </script>
</body>
</html>
