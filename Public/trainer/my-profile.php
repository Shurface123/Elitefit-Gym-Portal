<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';
requireRole('Trainer');

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

// Handle theme switching
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_theme'])) {
    $newTheme = $_POST['theme'] === 'light' ? 'light' : 'dark';
    
    try {
        // Ensure trainer_settings table exists
        $conn->exec("CREATE TABLE IF NOT EXISTS trainer_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            theme_preference VARCHAR(20) DEFAULT 'dark',
            notification_email TINYINT(1) DEFAULT 1,
            notification_sms TINYINT(1) DEFAULT 0,
            auto_confirm_appointments TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $stmt = $conn->prepare("
            INSERT INTO trainer_settings (user_id, theme_preference) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE theme_preference = VALUES(theme_preference)
        ");
        $stmt->execute([$userId, $newTheme]);
        
        header("Location: my-profile.php");
        exit;
    } catch (PDOException $e) {
        $error = "Error updating theme: " . $e->getMessage();
    }
}

// Get theme preference
$theme = 'dark';
try {
    $stmt = $conn->prepare("SELECT theme_preference FROM trainer_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $themeResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $theme = $themeResult ? $themeResult['theme_preference'] : 'dark';
} catch (PDOException $e) {
    // Use default theme
}

// Function to check if column exists in table
function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Function to add updated_at column if it doesn't exist
function ensureUpdatedAtColumn($conn, $tableName) {
    if (!columnExists($conn, $tableName, 'updated_at')) {
        try {
            $conn->exec("ALTER TABLE `$tableName` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to add updated_at column to $tableName: " . $e->getMessage());
            return false;
        }
    }
    return true;
}

// Handle profile updates
$updateMessage = '';
$updateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        // Ensure trainer_profiles table exists with proper structure
        $conn->exec("CREATE TABLE IF NOT EXISTS trainer_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            specialization VARCHAR(255),
            experience_years INT,
            certification TEXT,
            bio TEXT,
            hourly_rate DECIMAL(10,2),
            profile_image VARCHAR(500),
            social_media JSON,
            availability JSON,
            languages JSON,
            achievements JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $experienceYears = !empty($_POST['experience_years']) ? (int)$_POST['experience_years'] : null;
        $certification = trim($_POST['certification'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $hourlyRate = !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : null;
        
        // Handle social media with proper validation
        $socialMedia = json_encode([
            'instagram' => filter_var(trim($_POST['instagram'] ?? ''), FILTER_SANITIZE_URL),
            'facebook' => filter_var(trim($_POST['facebook'] ?? ''), FILTER_SANITIZE_URL),
            'linkedin' => filter_var(trim($_POST['linkedin'] ?? ''), FILTER_SANITIZE_URL),
            'youtube' => filter_var(trim($_POST['youtube'] ?? ''), FILTER_SANITIZE_URL)
        ]);
        
        // Handle languages with proper parsing
        $languagesInput = trim($_POST['languages'] ?? '');
        $languages = json_encode(array_filter(array_map('trim', explode(',', $languagesInput))));
        
        // Handle achievements with proper parsing
        $achievementsInput = trim($_POST['achievements'] ?? '');
        $achievements = json_encode(array_filter(array_map('trim', explode(',', $achievementsInput))));
        
        // Handle availability with validation
        $availability = json_encode([
            'monday' => [
                'start' => trim($_POST['monday_start'] ?? ''), 
                'end' => trim($_POST['monday_end'] ?? '')
            ],
            'tuesday' => [
                'start' => trim($_POST['tuesday_start'] ?? ''), 
                'end' => trim($_POST['tuesday_end'] ?? '')
            ],
            'wednesday' => [
                'start' => trim($_POST['wednesday_start'] ?? ''), 
                'end' => trim($_POST['wednesday_end'] ?? '')
            ],
            'thursday' => [
                'start' => trim($_POST['thursday_start'] ?? ''), 
                'end' => trim($_POST['thursday_end'] ?? '')
            ],
            'friday' => [
                'start' => trim($_POST['friday_start'] ?? ''), 
                'end' => trim($_POST['friday_end'] ?? '')
            ],
            'saturday' => [
                'start' => trim($_POST['saturday_start'] ?? ''), 
                'end' => trim($_POST['saturday_end'] ?? '')
            ],
            'sunday' => [
                'start' => trim($_POST['sunday_start'] ?? ''), 
                'end' => trim($_POST['sunday_end'] ?? '')
            ]
        ]);
        
        // Handle profile image upload with improved error handling
        $profileImage = null;
        $currentProfileImage = '';
        
        // Get current profile image first
        $stmt = $conn->prepare("SELECT profile_image FROM trainer_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $currentProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentProfileImage = $currentProfile['profile_image'] ?? '';
        
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/profiles/';
            
            // Create directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Failed to create upload directory");
                }
            }
            
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $_FILES['profile_image']['type'];
            
            if (!in_array($fileType, $allowedTypes)) {
                throw new Exception("Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.");
            }
            
            // Validate file size (max 5MB)
            if ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
                throw new Exception("File size too large. Maximum 5MB allowed.");
            }
            
            $fileExtension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
            $fileName = 'trainer_' . $userId . '_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadDir . $fileName;
            
            // Delete old profile image if it exists
            if (!empty($currentProfileImage)) {
                $oldImagePath = __DIR__ . '/../' . $currentProfileImage;
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                $profileImage = 'uploads/profiles/' . $fileName;
                
                // Verify the uploaded file exists and is readable
                if (!file_exists($uploadPath) || !is_readable($uploadPath)) {
                    throw new Exception("Failed to verify uploaded file");
                }
            } else {
                throw new Exception("Failed to upload profile image");
            }
        } else {
            // Keep existing profile image if no new one is uploaded
            $profileImage = $currentProfileImage;
        }
        
        // Begin transaction for data consistency
        $conn->beginTransaction();
        
        try {
            // Check if updated_at column exists in users table and add if necessary
            $hasUpdatedAt = ensureUpdatedAtColumn($conn, 'users');
            
            // Update users table - build query dynamically based on available columns
            $userUpdateFields = ['name = ?', 'email = ?'];
            $userUpdateValues = [$name, $email];
            
            // Add phone if it's provided
            if (!empty($phone)) {
                $userUpdateFields[] = 'phone = ?';
                $userUpdateValues[] = $phone;
            }
            
            // Add updated_at if column exists
            if ($hasUpdatedAt) {
                $userUpdateFields[] = 'updated_at = CURRENT_TIMESTAMP';
            }
            
            // Add user ID for WHERE clause
            $userUpdateValues[] = $userId;
            
            $userUpdateQuery = "UPDATE users SET " . implode(', ', $userUpdateFields) . " WHERE id = ?";
            $stmt = $conn->prepare($userUpdateQuery);
            $stmt->execute($userUpdateValues);
            
            // Check if trainer profile exists
            $stmt = $conn->prepare("SELECT id FROM trainer_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $profileExists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($profileExists) {
                // Update existing profile
                $updateQuery = "UPDATE trainer_profiles SET 
                    specialization = ?, 
                    experience_years = ?, 
                    certification = ?, 
                    bio = ?, 
                    hourly_rate = ?, 
                    social_media = ?, 
                    availability = ?, 
                    languages = ?, 
                    achievements = ?, 
                    updated_at = CURRENT_TIMESTAMP";
                
                $params = [$specialization, $experienceYears, $certification, $bio, $hourlyRate, $socialMedia, $availability, $languages, $achievements];
                
                if ($profileImage !== null) {
                    $updateQuery .= ", profile_image = ?";
                    $params[] = $profileImage;
                }
                
                $updateQuery .= " WHERE user_id = ?";
                $params[] = $userId;
                
                $stmt = $conn->prepare($updateQuery);
                $stmt->execute($params);
            } else {
                // Insert new profile
                $insertQuery = "INSERT INTO trainer_profiles 
                    (user_id, specialization, experience_years, certification, bio, hourly_rate, profile_image, social_media, availability, languages, achievements) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($insertQuery);
                $stmt->execute([$userId, $specialization, $experienceYears, $certification, $bio, $hourlyRate, $profileImage, $socialMedia, $availability, $languages, $achievements]);
            }
            
            // Commit transaction
            $conn->commit();
            
            $updateMessage = 'Profile updated successfully!';
            
            // Refresh the page to show updated data
            header("Location: my-profile.php?updated=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $updateError = 'Error updating profile: ' . $e->getMessage();
        error_log("Profile update error for user $userId: " . $e->getMessage());
    }
}

// Show success message if redirected after update
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $updateMessage = 'Profile updated successfully!';
}

// Get trainer profile data with improved query
try {
    // Check what columns exist in users table
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'users'
    ");
    $stmt->execute();
    $userColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build dynamic query based on available columns
    $selectFields = ['u.id', 'u.name', 'u.email'];
    
    if (in_array('phone', $userColumns)) {
        $selectFields[] = 'u.phone';
    }
    if (in_array('created_at', $userColumns)) {
        $selectFields[] = 'u.created_at as user_created_at';
    }
    if (in_array('updated_at', $userColumns)) {
        $selectFields[] = 'u.updated_at as user_updated_at';
    }
    
    // Add trainer profile fields
    $selectFields = array_merge($selectFields, [
        'tp.specialization', 'tp.experience_years', 'tp.certification', 'tp.bio', 
        'tp.hourly_rate', 'tp.profile_image', 'tp.social_media', 'tp.availability', 
        'tp.languages', 'tp.achievements', 'tp.created_at as profile_created_at',
        'tp.updated_at as profile_updated_at'
    ]);
    
    $query = "SELECT " . implode(', ', $selectFields) . "
        FROM users u
        LEFT JOIN trainer_profiles tp ON u.id = tp.user_id
        WHERE u.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$userId]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trainer) {
        throw new Exception("Trainer not found");
    }
    
    // Set default values for missing fields
    if (!isset($trainer['phone'])) {
        $trainer['phone'] = '';
    }
    
    // Parse JSON fields safely
    $socialMedia = [];
    $availability = [];
    $languages = [];
    $achievements = [];
    
    if (!empty($trainer['social_media'])) {
        $socialMedia = json_decode($trainer['social_media'], true) ?: [];
    }
    
    if (!empty($trainer['availability'])) {
        $availability = json_decode($trainer['availability'], true) ?: [];
    }
    
    if (!empty($trainer['languages'])) {
        $languages = json_decode($trainer['languages'], true) ?: [];
    }
    
    if (!empty($trainer['achievements'])) {
        $achievements = json_decode($trainer['achievements'], true) ?: [];
    }
    
} catch (Exception $e) {
    $updateError = 'Error loading profile data: ' . $e->getMessage();
    error_log("Profile load error for user $userId: " . $e->getMessage());
    
    // Set default values if profile loading fails
    $trainer = [
        'id' => $userId,
        'name' => $userName,
        'email' => $_SESSION['email'] ?? '',
        'phone' => '',
        'specialization' => '',
        'experience_years' => null,
        'certification' => '',
        'bio' => '',
        'hourly_rate' => null,
        'profile_image' => ''
    ];
    $socialMedia = [];
    $availability = [];
    $languages = [];
    $achievements = [];
}

// Get performance statistics with proper error handling and default values
$stats = [
    'members' => 0,
    'sessions_month' => 0,
    'workout_plans' => 0,
    'avg_rating' => 0.0,
    'review_count' => 0
];

try {
    // Ensure trainer_members table exists
    $conn->exec("CREATE TABLE IF NOT EXISTS trainer_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT NOT NULL,
        status VARCHAR(20) DEFAULT 'active',
        assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_assignment (trainer_id, member_id),
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Total members
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM trainer_members WHERE trainer_id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['members'] = $result['total'] ?? 0;
    
    // Ensure trainer_schedule table exists
    $conn->exec("CREATE TABLE IF NOT EXISTS trainer_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT NOT NULL,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Total sessions this month
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM trainer_schedule 
        WHERE trainer_id = ? AND MONTH(start_time) = MONTH(CURRENT_DATE()) AND YEAR(start_time) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['sessions_month'] = $result['total'] ?? 0;
    
    // Ensure workout_plans table exists
    $conn->exec("CREATE TABLE IF NOT EXISTS workout_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT NULL,
        title VARCHAR(100) NOT NULL,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Total workout plans
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM workout_plans WHERE trainer_id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['workout_plans'] = $result['total'] ?? 0;
    
    // Ensure trainer_reviews table exists
    $conn->exec("CREATE TABLE IF NOT EXISTS trainer_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        member_id INT NOT NULL,
        rating DECIMAL(2,1) NOT NULL,
        review_text TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Average rating
    $stmt = $conn->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as review_count 
        FROM trainer_reviews 
        WHERE trainer_id = ?
    ");
    $stmt->execute([$userId]);
    $ratingData = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['avg_rating'] = round($ratingData['avg_rating'] ?? 0, 1);
    $stats['review_count'] = $ratingData['review_count'] ?? 0;
} catch (PDOException $e) {
    // Handle missing tables gracefully - stats already have default values
    error_log("Error fetching stats: " . $e->getMessage());
}

// Get recent activity with real timestamps
$recentActivity = [];
try {
    // Ensure trainer_activity table exists
    $conn->exec("CREATE TABLE IF NOT EXISTS trainer_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        activity_type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Check if there are any activities, if not, create some sample ones with real timestamps
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM trainer_activity WHERE trainer_id = ?");
    $stmt->execute([$userId]);
    $activityCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($activityCount == 0) {
        // Insert some sample activities with real timestamps
        $sampleActivities = [
            ['member', 'Member Assigned', 'New member assigned to training program'],
            ['workout', 'Workout Plan Created', 'Created new strength training program'],
            ['session', 'Session Completed', 'Completed training session with client']
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO trainer_activity (trainer_id, activity_type, title, description, created_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($sampleActivities as $index => $activity) {
            // Create timestamps going back in time (most recent first)
            $timestamp = date('Y-m-d H:i:s', strtotime("-{$index} hours"));
            $stmt->execute([$userId, $activity[0], $activity[1], $activity[2], $timestamp]);
        }
    }
    
    $stmt = $conn->prepare("
        SELECT activity_type, title, description, created_at 
        FROM trainer_activity 
        WHERE trainer_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle missing table gracefully
    error_log("Error fetching recent activity: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff8800;
            --primary-dark: #e67700;
            --primary-light: #ffaa33;
            --secondary: #333333;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }

        [data-theme="dark"] {
            --bg: #000000;
            --card-bg: #111111;
            --text: #ffffff;
            --text-secondary: #cccccc;
            --border: #333333;
            --accent: #222222;
            --shadow: rgba(255, 136, 0, 0.1);
        }

        [data-theme="light"] {
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --text: #333333;
            --text-secondary: #666666;
            --border: #dee2e6;
            --accent: #f1f3f4;
            --shadow: rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: all 0.3s ease;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--card-bg);
            border-right: 1px solid var(--border);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            text-align: center;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .sidebar-header h2 {
            color: var(--text);
            margin-top: 1rem;
            font-weight: 700;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section-title {
            padding: 0 2rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1rem;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 1rem 2rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu li a:hover,
        .sidebar-menu li a.active {
            background: var(--accent);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu li a i {
            margin-right: 1rem;
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            background: var(--bg);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-switch {
            position: relative;
            width: 60px;
            height: 30px;
            background: var(--border);
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .theme-switch.active {
            background: var(--primary);
        }

        .theme-switch::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 24px;
            height: 24px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .theme-switch.active::after {
            transform: translateX(30px);
        }

        .card {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 0;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px var(--shadow);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px var(--shadow);
        }

        .card-header {
            padding: 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header i {
            color: var(--primary);
        }

        .card-content {
            padding: 2rem;
        }

        .profile-overview {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            text-align: center;
        }

        .avatar-container {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary);
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .avatar-upload {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .avatar-upload:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .profile-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text);
        }

        .profile-info p {
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .profile-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 500;
            background: var(--primary);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--accent);
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--accent);
            color: var(--text);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 136, 0, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .availability-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .day-schedule {
            background: var(--accent);
            border-radius: 0.5rem;
            padding: 1rem;
            border: 1px solid var(--border);
        }

        .day-schedule h4 {
            margin-bottom: 1rem;
            color: var(--text);
            font-size: 1rem;
        }

        .time-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .time-inputs input {
            flex: 1;
        }

        .social-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(40, 167, 96, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: var(--accent);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content h4 {
            margin-bottom: 0.25rem;
            color: var(--text);
        }

        .activity-content p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .activity-time {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1000;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.75rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                z-index: 999;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 4rem;
            }

            .profile-overview {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .availability-grid {
                grid-template-columns: 1fr;
            }
        }

        .rating-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .stars {
            color: #ffc107;
        }

        .languages-tags,
        .achievements-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .tag {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border);
        }

        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .image-preview {
            max-width: 200px;
            max-height: 200px;
            margin-top: 1rem;
            border-radius: 0.5rem;
            border: 2px solid var(--border);
        }
    </style>
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
                    <li><a href="nutrition.php"><i class="fas fa-apple-alt"></i> <span>Nutrition</span></a></li>
                    <li><a href="assessment.php"><i class="fas fa-clipboard-check"></i> <span>Assessments</span></a></li>
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
                <div class="theme-toggle">
                    <i class="fas fa-sun"></i>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="switch_theme" value="1">
                        <input type="hidden" name="theme" value="<?php echo $theme === 'dark' ? 'light' : 'dark'; ?>">
                        <div class="theme-switch <?php echo $theme === 'dark' ? 'active' : ''; ?>" onclick="this.parentElement.submit()"></div>
                    </form>
                    <i class="fas fa-moon"></i>
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

            <!-- Profile Overview -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-circle"></i> Profile Overview</h2>
                </div>
                <div class="card-content">
                    <div class="profile-overview">
                        <div class="profile-avatar">
                            <div class="avatar-container">
                                <div class="avatar" id="avatarDisplay">
                                    <?php if (!empty($trainer['profile_image']) && file_exists(__DIR__ . '/../' . $trainer['profile_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($trainer['profile_image']); ?>?v=<?php echo time(); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <button class="avatar-upload" onclick="document.getElementById('profile_image').click()" type="button">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="profile-info">
                            <h3><?php echo htmlspecialchars($trainer['name']); ?></h3>
                            <p><?php echo htmlspecialchars($trainer['email']); ?></p>
                            
                            <div class="profile-badges">
                                <?php if (!empty($trainer['specialization'])): ?>
                                    <span class="badge"><?php echo htmlspecialchars($trainer['specialization']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($trainer['experience_years'])): ?>
                                    <span class="badge"><?php echo $trainer['experience_years']; ?> Years Experience</span>
                                <?php endif; ?>
                                <?php if (!empty($trainer['certification'])): ?>
                                    <span class="badge">Certified</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($stats['avg_rating'] > 0): ?>
                                <div class="rating-display">
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star<?php echo $i <= $stats['avg_rating'] ? '' : '-o'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span><?php echo $stats['avg_rating']; ?> (<?php echo $stats['review_count']; ?> reviews)</span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($languages)): ?>
                                <div class="languages-tags">
                                    <?php foreach ($languages as $language): ?>
                                        <?php if (!empty(trim($language))): ?>
                                            <span class="tag"><?php echo htmlspecialchars(trim($language)); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($trainer['bio'])): ?>
                                <p style="margin-top: 1rem; font-style: italic;"><?php echo htmlspecialchars($trainer['bio']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Statistics -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-line"></i> Performance Statistics</h2>
                </div>
                <div class="card-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['members']; ?></div>
                            <div class="stat-label">Active Members</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['sessions_month']; ?></div>
                            <div class="stat-label">Sessions This Month</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['workout_plans']; ?></div>
                            <div class="stat-label">Workout Plans</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-value"><?php echo $stats['avg_rating']; ?></div>
                            <div class="stat-label">Average Rating</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-edit"></i> Edit Profile Information</h2>
                </div>
                <div class="card-content">
                    <form action="" method="post" enctype="multipart/form-data" id="profileForm">
                        <input type="hidden" name="update_profile" value="1">
                        <input type="file" id="profile_image" name="profile_image" style="display: none;" accept="image/*" onchange="previewImage(this)">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Full Name *</label>
                                <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($trainer['name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address *</label>
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
                                <input type="number" id="experience_years" name="experience_years" class="form-control" value="<?php echo htmlspecialchars($trainer['experience_years'] ?? ''); ?>" min="0" max="50">
                            </div>
                            
                            <div class="form-group">
                                <label for="hourly_rate">Hourly Rate ($)</label>
                                <input type="number" id="hourly_rate" name="hourly_rate" class="form-control" value="<?php echo htmlspecialchars($trainer['hourly_rate'] ?? ''); ?>" min="0" step="0.01">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="certification">Certifications</label>
                                <input type="text" id="certification" name="certification" class="form-control" value="<?php echo htmlspecialchars($trainer['certification'] ?? ''); ?>" placeholder="e.g., NASM, ACE, ISSA">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="bio">Professional Bio</label>
                                <textarea id="bio" name="bio" class="form-control" rows="4" placeholder="Tell us about your fitness journey and expertise..."><?php echo htmlspecialchars($trainer['bio'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="languages">Languages (comma-separated)</label>
                                <input type="text" id="languages" name="languages" class="form-control" value="<?php echo htmlspecialchars(implode(', ', $languages)); ?>" placeholder="English, Spanish, French">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="achievements">Achievements (comma-separated)</label>
                                <input type="text" id="achievements" name="achievements" class="form-control" value="<?php echo htmlspecialchars(implode(', ', $achievements)); ?>" placeholder="Certified Personal Trainer, Nutrition Specialist">
                            </div>
                        </div>

                        <!-- Social Media Links -->
                        <h3 style="margin: 2rem 0 1rem; color: var(--text);">Social Media Links</h3>
                        <div class="social-links">
                            <div class="form-group">
                                <label for="instagram"><i class="fab fa-instagram"></i> Instagram</label>
                                <input type="url" id="instagram" name="instagram" class="form-control" value="<?php echo htmlspecialchars($socialMedia['instagram'] ?? ''); ?>" placeholder="https://instagram.com/username">
                            </div>
                            
                            <div class="form-group">
                                <label for="facebook"><i class="fab fa-facebook"></i> Facebook</label>
                                <input type="url" id="facebook" name="facebook" class="form-control" value="<?php echo htmlspecialchars($socialMedia['facebook'] ?? ''); ?>" placeholder="https://facebook.com/username">
                            </div>
                            
                            <div class="form-group">
                                <label for="linkedin"><i class="fab fa-linkedin"></i> LinkedIn</label>
                                <input type="url" id="linkedin" name="linkedin" class="form-control" value="<?php echo htmlspecialchars($socialMedia['linkedin'] ?? ''); ?>" placeholder="https://linkedin.com/in/username">
                            </div>
                            
                            <div class="form-group">
                                <label for="youtube"><i class="fab fa-youtube"></i> YouTube</label>
                                <input type="url" id="youtube" name="youtube" class="form-control" value="<?php echo htmlspecialchars($socialMedia['youtube'] ?? ''); ?>" placeholder="https://youtube.com/channel/username">
                            </div>
                        </div>

                        <!-- Availability Schedule -->
                        <h3 style="margin: 2rem 0 1rem; color: var(--text);">Weekly Availability</h3>
                        <div class="availability-grid">
                            <?php 
                            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                            $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            
                            foreach ($days as $index => $day): 
                                $dayAvailability = $availability[$day] ?? ['start' => '', 'end' => ''];
                            ?>
                                <div class="day-schedule">
                                    <h4><?php echo $dayNames[$index]; ?></h4>
                                    <div class="time-inputs">
                                        <input type="time" name="<?php echo $day; ?>_start" class="form-control" value="<?php echo htmlspecialchars($dayAvailability['start']); ?>">
                                        <span>to</span>
                                        <input type="time" name="<?php echo $day; ?>_end" class="form-control" value="<?php echo htmlspecialchars($dayAvailability['end']); ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline" onclick="resetForm()">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                            <button type="submit" class="btn" id="saveBtn">
                                <i class="fas fa-save"></i> Save Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Activity -->
            <?php if (!empty($recentActivity)): ?>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-clock"></i> Recent Activity</h2>
                </div>
                <div class="card-content">
                    <div class="activity-feed">
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-<?php echo $activity['activity_type'] === 'member' ? 'user-plus' : ($activity['activity_type'] === 'workout' ? 'dumbbell' : 'calendar'); ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <h4><?php echo htmlspecialchars($activity['title']); ?></h4>
                                    <p><?php echo htmlspecialchars($activity['description']); ?></p>
                                    <div class="activity-time"><?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Profile image preview function
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPEG, PNG, GIF, or WebP)');
                    input.value = '';
                    return;
                }
                
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const avatarDisplay = document.getElementById('avatarDisplay');
                    avatarDisplay.innerHTML = `<img src="${e.target.result}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" alt="Profile Preview">`;
                };
                reader.readAsDataURL(file);
            }
        }

        // Reset form function
        function resetForm() {
            if (confirm('Are you sure you want to reset all changes?')) {
                location.reload();
            }
        }

        // Form submission with loading state
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            const saveBtn = document.getElementById('saveBtn');
            const form = this;
            
            // Add loading state
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            form.classList.add('loading');
            
            // Validate required fields
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--danger)';
                    isValid = false;
                } else {
                    field.style.borderColor = 'var(--border)';
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Profile';
                form.classList.remove('loading');
                alert('Please fill in all required fields');
                return;
            }
            
            // If validation passes, form will submit normally
        });

        // Auto-save draft functionality
        let saveTimeout;
        const formInputs = document.querySelectorAll('input, textarea, select');
        
        formInputs.forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(() => {
                    // Save draft to localStorage
                    const formData = new FormData(document.getElementById('profileForm'));
                    const draftData = {};
                    for (let [key, value] of formData.entries()) {
                        if (key !== 'profile_image') { // Don't save file input
                            draftData[key] = value;
                        }
                    }
                    localStorage.setItem('profileDraft_' + <?php echo $userId; ?>, JSON.stringify(draftData));
                }, 2000);
            });
        });

        // Load draft on page load
        window.addEventListener('load', function() {
            const draft = localStorage.getItem('profileDraft_' + <?php echo $userId; ?>);
            if (draft) {
                try {
                    const draftData = JSON.parse(draft);
                    Object.keys(draftData).forEach(key => {
                        const input = document.querySelector(`[name="${key}"]`);
                        if (input && input.value === '' && draftData[key]) {
                            input.value = draftData[key];
                        }
                    });
                } catch (e) {
                    console.error('Error loading draft:', e);
                }
            }
        });

        // Clear draft on successful save
        document.getElementById('profileForm').addEventListener('submit', function() {
            localStorage.removeItem('profileDraft_' + <?php echo $userId; ?>);
        });

        // Real-time validation
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email && !emailRegex.test(email)) {
                this.style.borderColor = 'var(--danger)';
                this.title = 'Please enter a valid email address';
            } else {
                this.style.borderColor = 'var(--border)';
                this.title = '';
            }
        });

        // Phone number formatting
        document.getElementById('phone').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length >= 6) {
                value = value.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
            } else if (value.length >= 3) {
                value = value.replace(/(\d{3})(\d{0,3})/, '($1) $2');
            }
            this.value = value;
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, 300);
            });
        }, 5000);

        // Smooth scroll to form on edit button click
        function scrollToForm() {
            document.querySelector('#profileForm').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        // Add click handler to profile overview for quick edit
        document.querySelector('.profile-overview').addEventListener('click', function(e) {
            if (e.target.closest('.avatar-upload')) {
                return; // Don't scroll if clicking upload button
            }
            scrollToForm();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.getElementById('profileForm').dispatchEvent(new Event('submit'));
            }
            
            // Escape to reset form
            if (e.key === 'Escape') {
                resetForm();
            }
        });

        // Form field animations
        const formControls = document.querySelectorAll('.form-control');
        formControls.forEach(control => {
            control.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
                this.parentElement.style.transition = 'transform 0.2s ease';
            });
            
            control.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });

        // Success animation for saved profile
        <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
        setTimeout(function() {
            const profileOverview = document.querySelector('.profile-overview');
            profileOverview.style.animation = 'pulse 0.6s ease-in-out';
        }, 500);
        <?php endif; ?>
    </script>

    <style>
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .form-group {
            transition: transform 0.2s ease;
        }
        
        .alert {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
    </style>
</body>
</html>