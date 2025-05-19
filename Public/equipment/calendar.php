<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include database connection
$conn = connectDB();

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Get theme preference - handle missing theme column
try {
    // First check if the theme column exists
    $checkColumnStmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'user_preferences' 
        AND COLUMN_NAME = 'theme'
    ");
    $checkColumnStmt->execute();
    $columnExists = $checkColumnStmt->fetchColumn();
    
    if ($columnExists) {
        // If column exists, get the theme
        $themeStmt = $conn->prepare("SELECT theme FROM user_preferences WHERE user_id = :user_id");
        $themeStmt->bindParam(':user_id', $userId);
        $themeStmt->execute();
        $theme = $themeStmt->fetchColumn();
    } else {
        // If column doesn't exist, try to add it
        try {
            $conn->exec("ALTER TABLE user_preferences ADD COLUMN theme VARCHAR(20) DEFAULT 'light'");
            error_log("Added 'theme' column to user_preferences table");
            $theme = 'light'; // Default for new column
        } catch (PDOException $e) {
            error_log("Failed to add theme column: " . $e->getMessage());
            $theme = 'light'; // Default if we can't add the column
        }
    }
} catch (PDOException $e) {
    error_log("Error checking theme preference: " . $e->getMessage());
    $theme = 'light'; // Default to light theme if there's an error
}

// Get equipment list for filters
$equipmentStmt = $conn->prepare("SELECT id, name, type FROM equipment ORDER BY name");
$equipmentStmt->execute();
$equipmentList = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get maintenance types for filters - FIX: Check if maintenance_type column exists
try {
    // First check if the maintenance_type column exists in maintenance_schedule
    $checkMaintenanceTypeStmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'maintenance_schedule' 
        AND COLUMN_NAME = 'maintenance_type'
    ");
    $checkMaintenanceTypeStmt->execute();
    $maintenanceTypeExists = $checkMaintenanceTypeStmt->fetchColumn();
    
    if ($maintenanceTypeExists) {
        // If column exists, get the maintenance types
        $maintenanceTypesStmt = $conn->prepare("
            SELECT DISTINCT maintenance_type FROM maintenance_schedule 
            UNION 
            SELECT DISTINCT description FROM maintenance 
            ORDER BY maintenance_type
        ");
        $maintenanceTypesStmt->execute();
        $maintenanceTypes = $maintenanceTypesStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // If column doesn't exist, just get descriptions from maintenance
        $maintenanceTypesStmt = $conn->prepare("
            SELECT DISTINCT description FROM maintenance 
            ORDER BY description
        ");
        $maintenanceTypesStmt->execute();
        $maintenanceTypes = $maintenanceTypesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Try to add the column to maintenance_schedule if the table exists
        try {
            // Check if maintenance_schedule table exists
            $checkTableStmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'maintenance_schedule'
            ");
            $checkTableStmt->execute();
            $tableExists = $checkTableStmt->fetchColumn();
            
            if ($tableExists) {
                $conn->exec("ALTER TABLE maintenance_schedule ADD COLUMN maintenance_type VARCHAR(100) NOT NULL DEFAULT 'Routine Maintenance' COMMENT 'Type of maintenance: routine, repair, inspection, etc.'");
                error_log("Added 'maintenance_type' column to maintenance_schedule table");
            }
        } catch (PDOException $e) {
            error_log("Failed to add maintenance_type column: " . $e->getMessage());
        }
    }
} catch (PDOException $e) {
    error_log("Error checking maintenance types: " . $e->getMessage());
    $maintenanceTypes = []; // Default to empty array if there's an error
}

// Get staff list for filters
$staffStmt = $conn->prepare("
    SELECT id, name 
    FROM users 
    WHERE role = 'EquipmentManager' OR role = 'Maintenance' 
    ORDER BY name
");
$staffStmt->execute();
$staffList = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle event creation/update/deletion via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'create':
            // Create new event
            try {
                $title = $_POST['title'];
                $start = $_POST['start'];
                $end = $_POST['end'] ?? date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
                $allDay = isset($_POST['allDay']) ? (int)$_POST['allDay'] : 0;
                $equipmentId = $_POST['equipment_id'] ?? null;
                $eventType = $_POST['event_type'];
                $description = $_POST['description'] ?? '';
                $color = $_POST['color'] ?? '#ff6600';
                
                if ($eventType === 'maintenance') {
                    // Check if maintenance_schedule table exists
                    $checkTableStmt = $conn->prepare("
                        SELECT COUNT(*) 
                        FROM information_schema.TABLES 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'maintenance_schedule'
                    ");
                    $checkTableStmt->execute();
                    $tableExists = $checkTableStmt->fetchColumn();
                    
                    if ($tableExists) {
                        // Check if maintenance_type column exists
                        $checkColumnStmt = $conn->prepare("
                            SELECT COUNT(*) 
                            FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'maintenance_schedule' 
                            AND COLUMN_NAME = 'maintenance_type'
                        ");
                        $checkColumnStmt->execute();
                        $columnExists = $checkColumnStmt->fetchColumn();
                        
                        if (!$columnExists) {
                            // Add maintenance_type column if it doesn't exist
                            $conn->exec("ALTER TABLE maintenance_schedule ADD COLUMN maintenance_type VARCHAR(100) NOT NULL DEFAULT 'Routine Maintenance' COMMENT 'Type of maintenance: routine, repair, inspection, etc.'");
                        }
                        
                        // Create maintenance schedule
                        $stmt = $conn->prepare("
                            INSERT INTO maintenance_schedule 
                            (equipment_id, scheduled_date, maintenance_type, description, status, created_by) 
                            VALUES (:equipment_id, :scheduled_date, :maintenance_type, :description, 'Scheduled', :created_by)
                        ");
                        $stmt->bindParam(':equipment_id', $equipmentId);
                        $stmt->bindParam(':scheduled_date', $start);
                        $stmt->bindParam(':maintenance_type', $title);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':created_by', $userId);
                        $stmt->execute();
                        
                        $eventId = $conn->lastInsertId();
                    } else {
                        // If maintenance_schedule table doesn't exist, create a regular calendar event
                        $stmt = $conn->prepare("
                            INSERT INTO calendar_events 
                            (title, start_date, end_date, all_day, equipment_id, event_type, description, color, created_by) 
                            VALUES (:title, :start_date, :end_date, :all_day, :equipment_id, :event_type, :description, :color, :created_by)
                        ");
                        $stmt->bindParam(':title', $title);
                        $stmt->bindParam(':start_date', $start);
                        $stmt->bindParam(':end_date', $end);
                        $stmt->bindParam(':all_day', $allDay);
                        $stmt->bindParam(':equipment_id', $equipmentId);
                        $stmt->bindParam(':event_type', $eventType);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':color', $color);
                        $stmt->bindParam(':created_by', $userId);
                        $stmt->execute();
                        
                        $eventId = $conn->lastInsertId();
                    }
                    
                    // Log activity
                    try {
                        // Check if activity_log table exists
                        $checkTableStmt = $conn->prepare("
                            SELECT COUNT(*) 
                            FROM information_schema.TABLES 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'activity_log'
                        ");
                        $checkTableStmt->execute();
                        $tableExists = $checkTableStmt->fetchColumn();
                        
                        if ($tableExists) {
                            $logStmt = $conn->prepare("
                                INSERT INTO activity_log (user_id, equipment_id, action, timestamp)
                                VALUES (:user_id, :equipment_id, :action, NOW())
                            ");
                            
                            // Get equipment name
                            $nameStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
                            $nameStmt->bindParam(':id', $equipmentId);
                            $nameStmt->execute();
                            $equipmentName = $nameStmt->fetchColumn();
                            
                            $action = "Scheduled maintenance for $equipmentName on " . date('Y-m-d', strtotime($start));
                            $logStmt->bindParam(':user_id', $userId);
                            $logStmt->bindParam(':equipment_id', $equipmentId);
                            $logStmt->bindParam(':action', $action);
                            $logStmt->execute();
                        }
                    } catch (PDOException $e) {
                        error_log("Error logging activity: " . $e->getMessage());
                    }
                } else {
                    // Create calendar event
                    $stmt = $conn->prepare("
                        INSERT INTO calendar_events 
                        (title, start_date, end_date, all_day, equipment_id, event_type, description, color, created_by) 
                        VALUES (:title, :start_date, :end_date, :all_day, :equipment_id, :event_type, :description, :color, :created_by)
                    ");
                    $stmt->bindParam(':title', $title);
                    $stmt->bindParam(':start_date', $start);
                    $stmt->bindParam(':end_date', $end);
                    $stmt->bindParam(':all_day', $allDay);
                    $stmt->bindParam(':equipment_id', $equipmentId);
                    $stmt->bindParam(':event_type', $eventType);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':color', $color);
                    $stmt->bindParam(':created_by', $userId);
                    $stmt->execute();
                    
                    $eventId = $conn->lastInsertId();
                }
                
                echo json_encode([
                    'success' => true,
                    'id' => $eventId,
                    'message' => 'Event created successfully'
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error creating event: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'update':
            // Update existing event
            try {
                $id = $_POST['id'];
                $title = $_POST['title'];
                $start = $_POST['start'];
                $end = $_POST['end'] ?? date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
                $allDay = isset($_POST['allDay']) ? (int)$_POST['allDay'] : 0;
                $eventType = $_POST['event_type'];
                $description = $_POST['description'] ?? '';
                
                if ($eventType === 'maintenance') {
                    // Check if maintenance_schedule table exists
                    $checkTableStmt = $conn->prepare("
                        SELECT COUNT(*) 
                        FROM information_schema.TABLES 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'maintenance_schedule'
                    ");
                    $checkTableStmt->execute();
                    $tableExists = $checkTableStmt->fetchColumn();
                    
                    if ($tableExists) {
                        // Check if maintenance_type column exists
                        $checkColumnStmt = $conn->prepare("
                            SELECT COUNT(*) 
                            FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'maintenance_schedule' 
                            AND COLUMN_NAME = 'maintenance_type'
                        ");
                        $checkColumnStmt->execute();
                        $columnExists = $checkColumnStmt->fetchColumn();
                        
                        if ($columnExists) {
                            // Update maintenance schedule
                            $stmt = $conn->prepare("
                                UPDATE maintenance_schedule 
                                SET scheduled_date = :scheduled_date,
                                    maintenance_type = :maintenance_type,
                                    description = :description
                                WHERE id = :id
                            ");
                            $stmt->bindParam(':scheduled_date', $start);
                            $stmt->bindParam(':maintenance_type', $title);
                            $stmt->bindParam(':description', $description);
                            $stmt->bindParam(':id', $id);
                            $stmt->execute();
                        } else {
                            // If maintenance_type column doesn't exist, update without it
                            $stmt = $conn->prepare("
                                UPDATE maintenance_schedule 
                                SET scheduled_date = :scheduled_date,
                                    description = :description
                                WHERE id = :id
                            ");
                            $stmt->bindParam(':scheduled_date', $start);
                            $stmt->bindParam(':description', $description);
                            $stmt->bindParam(':id', $id);
                            $stmt->execute();
                        }
                    } else {
                        // If maintenance_schedule table doesn't exist, update calendar_events
                        $stmt = $conn->prepare("
                            UPDATE calendar_events 
                            SET title = :title,
                                start_date = :start_date,
                                end_date = :end_date,
                                all_day = :all_day,
                                description = :description
                            WHERE id = :id
                        ");
                        $stmt->bindParam(':title', $title);
                        $stmt->bindParam(':start_date', $start);
                        $stmt->bindParam(':end_date', $end);
                        $stmt->bindParam(':all_day', $allDay);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':id', $id);
                        $stmt->execute();
                    }
                } else {
                    // Update calendar event
                    $stmt = $conn->prepare("
                        UPDATE calendar_events 
                        SET title = :title,
                            start_date = :start_date,
                            end_date = :end_date,
                            all_day = :all_day,
                            description = :description
                        WHERE id = :id
                    ");
                    $stmt->bindParam(':title', $title);
                    $stmt->bindParam(':start_date', $start);
                    $stmt->bindParam(':end_date', $end);
                    $stmt->bindParam(':all_day', $allDay);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':id', $id);
                    $stmt->execute();
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Event updated successfully'
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error updating event: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'delete':
            // Delete event
            try {
                $id = $_POST['id'];
                $eventType = $_POST['event_type'];
                
                if ($eventType === 'maintenance') {
                    // Check if maintenance_schedule table exists
                    $checkTableStmt = $conn->prepare("
                        SELECT COUNT(*) 
                        FROM information_schema.TABLES 
                        WHERE TABLE_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'maintenance_schedule'
                    ");
                    $checkTableStmt->execute();
                    $tableExists = $checkTableStmt->fetchColumn();
                    
                    if ($tableExists) {
                        try {
                            // Get equipment ID for activity log
                            $infoStmt = $conn->prepare("
                                SELECT m.equipment_id, e.name as equipment_name
                                FROM maintenance_schedule m
                                JOIN equipment e ON m.equipment_id = e.id
                                WHERE m.id = :id
                            ");
                            $infoStmt->bindParam(':id', $id);
                            $infoStmt->execute();
                            $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Delete maintenance schedule
                            $stmt = $conn->prepare("DELETE FROM maintenance_schedule WHERE id = :id");
                            $stmt->bindParam(':id', $id);
                            $stmt->execute();
                            
                            // Log activity if activity_log table exists
                            $checkTableStmt = $conn->prepare("
                                SELECT COUNT(*) 
                                FROM information_schema.TABLES 
                                WHERE TABLE_SCHEMA = DATABASE() 
                                AND TABLE_NAME = 'activity_log'
                            ");
                            $checkTableStmt->execute();
                            $tableExists = $checkTableStmt->fetchColumn();
                            
                            if ($tableExists && $info) {
                                $logStmt = $conn->prepare("
                                    INSERT INTO activity_log (user_id, equipment_id, action, timestamp)
                                    VALUES (:user_id, :equipment_id, :action, NOW())
                                ");
                                $action = "Deleted maintenance schedule for " . $info['equipment_name'];
                                $logStmt->bindParam(':user_id', $userId);
                                $logStmt->bindParam(':equipment_id', $info['equipment_id']);
                                $logStmt->bindParam(':action', $action);
                                $logStmt->execute();
                            }
                        } catch (PDOException $e) {
                            // If there's an error with maintenance_schedule, try deleting from calendar_events
                            $stmt = $conn->prepare("DELETE FROM calendar_events WHERE id = :id");
                            $stmt->bindParam(':id', $id);
                            $stmt->execute();
                        }
                    } else {
                        // If maintenance_schedule table doesn't exist, delete from calendar_events
                        $stmt = $conn->prepare("DELETE FROM calendar_events WHERE id = :id");
                        $stmt->bindParam(':id', $id);
                        $stmt->execute();
                    }
                } else {
                    // Delete calendar event
                    $stmt = $conn->prepare("DELETE FROM calendar_events WHERE id = :id");
                    $stmt->bindParam(':id', $id);
                    $stmt->execute();
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Event deleted successfully'
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error deleting event: ' . $e->getMessage()
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
    }
    exit;
}

// Check if calendar_events table exists and create it if it doesn't
try {
    $tableExists = false;
    $checkStmt = $conn->prepare("SHOW TABLES LIKE 'calendar_events'");
    $checkStmt->execute();
    if ($checkStmt->rowCount() > 0) {
        $tableExists = true;
    }
    
    if (!$tableExists) {
        $createTableSQL = "
            CREATE TABLE calendar_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                start_date DATETIME NOT NULL,
                end_date DATETIME NOT NULL,
                all_day TINYINT(1) DEFAULT 0,
                equipment_id INT NULL,
                event_type VARCHAR(50) NOT NULL,
                description TEXT NULL,
                color VARCHAR(20) DEFAULT '#ff6600',
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
            )
        ";
        $conn->exec($createTableSQL);
    }
} catch (PDOException $e) {
    // Handle error silently
    error_log("Error checking/creating calendar_events table: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar - Equipment Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #ff6600;
            --primary-dark: #e65c00;
            --primary-light: #ff8533;
            --secondary: #333;
            --secondary-dark: #222;
            --secondary-light: #444;
            --light: #f8f9fa;
            --dark: #121212;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--secondary);
            min-height: 100vh;
            transition: var(--transition);
        }
        
        body.dark-theme {
            background-color: var(--dark);
            color: #f5f5f5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 260px;
            background-color: var(--secondary);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: var(--transition);
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
        }
        
        .dark-theme .sidebar {
            background-color: #1a1a1a;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.3);
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 10px;
            color: var(--primary);
            font-weight: 600;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #ddd;
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 102, 0, 0.1);
            color: var(--primary);
        }
        
        .sidebar-menu a.active {
            background-color: var(--primary);
            color: white;
            box-shadow: 0 2px 5px rgba(255, 102, 0, 0.2);
        }
        
        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .sidebar-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.8rem;
            color: #aaa;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            transition: var(--transition);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 900;
        }
        
        .dark-theme .header {
            background-color: #1e1e1e;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }
        
        .header h1 {
            font-size: 1.8rem;
            color: var(--secondary);
            font-weight: 600;
        }
        
        .dark-theme .header h1 {
            color: #f5f5f5;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            position: relative;
        }
        
        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }
        
        .user-info .dropdown {
            position: relative;
            margin-left: 10px;
        }
        
        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: var(--border-radius);
            transition: var(--transition);
        }
        
        .user-info .dropdown-toggle:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-theme .user-info .dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .user-info .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 10px 0;
            min-width: 200px;
            z-index: 1000;
            display: none;
            margin-top: 10px;
        }
        
        .dark-theme .user-info .dropdown-menu {
            background-color: #1e1e1e;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .user-info .dropdown-menu.show {
            display: block;
        }
        
        .user-info .dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .dark-theme .user-info .dropdown-menu a {
            color: #f5f5f5;
        }
        
        .user-info .dropdown-menu a:hover {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--primary);
        }
        
        .dark-theme .user-info .dropdown-menu a:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .user-info .dropdown-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .user-info .dropdown-divider {
            height: 1px;
            background-color: #eee;
            margin: 5px 0;
        }
        
        .dark-theme .user-info .dropdown-divider {
            background-color: #333;
        }
        
        .theme-switch {
            display: flex;
            align-items: center;
            margin-right: 15px;
        }
        
        .theme-switch label {
            margin: 0 10px 0 0;
            cursor: pointer;
            color: var(--secondary);
        }
        
        .dark-theme .theme-switch label {
            color: #f5f5f5;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .dark-theme .card {
            background-color: #1e1e1e;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }
        
        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dark-theme .card-header {
            border-bottom: 1px solid #333;
        }
        
        .card-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--secondary);
            margin: 0;
        }
        
        .dark-theme .card-header h3 {
            color: #f5f5f5;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn {
            padding: 8px 15px;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--secondary);
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: var(--secondary-light);
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-warning {
            background-color: var(--warning);
            color: #212529;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .btn-info {
            background-color: var(--info);
            color: white;
        }
        
        .btn-info:hover {
            background-color: #138496;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8rem;
        }
        
        .btn-lg {
            padding: 12px 20px;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--secondary);
        }
        
        .dark-theme .form-group label {
            color: #f5f5f5;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: white;
            color: var(--secondary);
        }
        
        .dark-theme .form-control {
            background-color: #2d2d2d;
            border-color: #444;
            color: #f5f5f5;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 102, 0, 0.2);
        }
        
        .dark-theme .form-control:focus {
            box-shadow: 0 0 0 3px rgba(255, 102, 0, 0.4);
        }
        
        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .form-check-input {
            margin-right: 10px;
        }
        
        .form-check-label {
            cursor: pointer;
        }
        
        /* Calendar Styles */
        .calendar-container {
            height: calc(100vh - 200px);
            min-height: 600px;
        }
        
        .fc {
            height: 100%;
        }
        
        .fc-toolbar-title {
            font-size: 1.5rem !important;
            font-weight: 600;
        }
        
        .dark-theme .fc-toolbar-title {
            color: #f5f5f5;
        }
        
        .fc .fc-button {
            background-color: var(--secondary);
            border-color: var(--secondary);
        }
        
        .fc .fc-button:hover {
            background-color: var(--secondary-light);
            border-color: var(--secondary-light);
        }
        
        .fc .fc-button-primary:not(:disabled).fc-button-active,
        .fc .fc-button-primary:not(:disabled):active {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .fc-day-today {
            background-color: rgba(255, 102, 0, 0.1) !important;
        }
        
        .dark-theme .fc-day-today {
            background-color: rgba(255, 102, 0, 0.2) !important;
        }
        
        .fc-event {
            cursor: pointer;
            border-radius: 4px;
            padding: 2px 4px;
            font-size: 0.85rem;
        }
        
        .fc-event-title {
            font-weight: 500;
        }
        
        .fc-event-time {
            font-weight: 400;
            opacity: 0.8;
        }
        
        .dark-theme .fc-theme-standard .fc-scrollgrid,
        .dark-theme .fc-theme-standard td,
        .dark-theme .fc-theme-standard th {
            border-color: #333;
        }
        
        .dark-theme .fc-col-header-cell-cushion,
        .dark-theme .fc-daygrid-day-number {
            color: #f5f5f5;
        }
        
        .dark-theme .fc-list-day-cushion {
            background-color: #2d2d2d;
        }
        
        .dark-theme .fc-list-event:hover td {
            background-color: #333;
        }
        
        .dark-theme .fc-list-event-title a {
            color: #f5f5f5;
        }
        
        /* Event Types */
        .event-maintenance {
            background-color: var(--danger);
            border-color: var(--danger);
        }
        
        .event-booking {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .event-training {
            background-color: var(--success);
            border-color: var(--success);
        }
        
        .event-other {
            background-color: var(--info);
            border-color: var(--info);
        }
        
        /* Filter Panel */
        .filter-panel {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }
        
        /* Color Picker */
        .color-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: var(--transition);
        }
        
        .color-option.selected {
            border-color: #333;
            transform: scale(1.1);
        }
        
        .dark-theme .color-option.selected {
            border-color: #fff;
        }
        
        /* Event Legend */
        .event-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .calendar-container {
                height: calc(100vh - 250px);
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }
            
            .sidebar-header h2, .sidebar-menu a span, .sidebar-footer {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .calendar-container {
                height: calc(100vh - 300px);
            }
            
            .fc-toolbar {
                flex-direction: column;
                gap: 10px;
            }
            
            .fc-toolbar-chunk {
                display: flex;
                justify-content: center;
                width: 100%;
            }
            
            .filter-panel {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
        }
        
        /* SweetAlert2 Dark Theme */
        .dark-theme .swal2-popup {
            background-color: #2d2d2d;
            color: #f5f5f5;
        }
        
        .dark-theme .swal2-title,
        .dark-theme .swal2-content {
            color: #f5f5f5;
        }
        
        .dark-theme .swal2-input,
        .dark-theme .swal2-textarea,
        .dark-theme .swal2-select {
            background-color: #333;
            color: #f5f5f5;
            border-color: #444;
        }
        
        /* Select2 Dark Theme */
        .dark-theme .select2-container--default .select2-selection--single,
        .dark-theme .select2-selection--multiple {
            background-color: #2d2d2d;
            border-color: #444;
        }
        
        .dark-theme .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #f5f5f5;
        }
        
        .dark-theme .select2-dropdown {
            background-color: #2d2d2d;
            border-color: #444;
        }
        
        .dark-theme .select2-search__field {
            background-color: #333;
            color: #f5f5f5;
        }
        
        .dark-theme .select2-results__option {
            color: #f5f5f5;
        }
        
        .dark-theme .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary);
        }
    </style>
</head>
<body class="<?php echo $theme === 'dark' ? 'dark-theme' : ''; ?>">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                <li><a href="equipment.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php"><i class="fas fa-boxes"></i> <span>Inventory</span></a></li>
                <li><a href="report.php"><i class="fas fa-chart-bar"></i> <span>Reports</span></a></li>
                <li><a href="calendar.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
            <div class="sidebar-footer">
                <p>EliteFit Gym Management</p>
                <p>Version 2.0</p>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-calendar-alt"></i> Equipment Calendar</h1>
                <div class="header-actions">
                    <div class="theme-switch">
                        <label for="theme-toggle">
                            <i class="fas <?php echo $theme === 'dark' ? 'fa-sun' : 'fa-moon'; ?>" style="color: <?php echo $theme === 'dark' ? 'var(--primary)' : '#aaa'; ?>"></i>
                        </label>
                        <label class="switch">
                            <input type="checkbox" id="theme-toggle" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="user-info">
                        <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar">
                        <div class="dropdown">
                            <div class="dropdown-toggle" onclick="toggleDropdown()">
                                <span><?php echo htmlspecialchars($userName); ?></span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="dropdown-menu" id="userDropdown">
                                <a href="profile.php"><i class="fas fa-user"></i> My Profile</a>
                                <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                                <div class="dropdown-divider"></div>
                                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Event Legend -->
            <div class="card">
                <div class="card-body">
                    <div class="event-legend">
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: var(--danger);"></div>
                            <span>Maintenance</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: var(--primary);"></div>
                            <span>Equipment Booking</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: var(--success);"></div>
                            <span>Training Session</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background-color: var(--info);"></div>
                            <span>Other Events</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Panel -->
            <div class="card">
                <div class="card-header">
                    <h3>Filters</h3>
                    <button id="add-event-btn" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Event
                    </button>
                </div>
                <div class="card-body">
                    <div class="filter-panel">
                        <div class="filter-group">
                            <label for="equipment-filter">Equipment</label>
                            <select id="equipment-filter" class="form-control select2" multiple>
                                <option value="all" selected>All Equipment</option>
                                <?php foreach ($equipmentList as $equipment): ?>
                                    <option value="<?php echo $equipment['id']; ?>">
                                        <?php echo htmlspecialchars($equipment['name']); ?> (<?php echo htmlspecialchars($equipment['type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="event-type-filter">Event Type</label>
                            <select id="event-type-filter" class="form-control select2" multiple>
                                <option value="all" selected>All Events</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="booking">Equipment Booking</option>
                                <option value="training">Training Session</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="date-range">Date Range</label>
                            <input type="text" id="date-range" class="form-control date-range-picker" placeholder="Select date range">
                        </div>
                        <div class="filter-actions">
                            <button id="apply-filters" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <button id="reset-filters" class="btn btn-secondary">
                                <i class="fas fa-sync-alt"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Calendar -->
            <div class="card">
                <div class="card-body">
                    <div id="calendar" class="calendar-container"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Save Theme Form (Hidden) -->
    <form id="themeForm" action="save-theme.php" method="POST" style="display: none;">
        <input type="hidden" name="theme" id="themeInput" value="<?php echo $theme; ?>">
    </form>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2
            $('.select2').select2({
                theme: "classic",
                width: '100%'
            });
            
            // Initialize Flatpickr for date range
            flatpickr(".date-range-picker", {
                mode: "range",
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
                wrap: true
            });
            
            // Initialize FullCalendar
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
                },
                themeSystem: 'bootstrap',
                navLinks: true,
                editable: true,
                selectable: true,
                selectMirror: true,
                dayMaxEvents: true,
                nowIndicator: true,
                businessHours: {
                    daysOfWeek: [1, 2, 3, 4, 5], // Monday - Friday
                    startTime: '06:00',
                    endTime: '22:00',
                },
                initialDate: '2025-05-12', // Current date in 2025
                events: function(info, successCallback, failureCallback) {
                    // Get events from both maintenance_schedule and calendar_events tables
                    $.ajax({
                        url: 'get_calendar_events.php',
                        type: 'GET',
                        data: {
                            start: info.startStr,
                            end: info.endStr,
                            equipment: $('#equipment-filter').val(),
                            event_type: $('#event-type-filter').val(),
                            date_range: $('#date-range').val()
                        },
                        success: function(response) {
                            var events = [];
                            
                            // Process maintenance events
                            if (response.maintenance && response.maintenance.length > 0) {
                                response.maintenance.forEach(function(item) {
                                    events.push({
                                        id: 'm_' + item.id,
                                        title: item.maintenance_type || 'Maintenance',
                                        start: item.scheduled_date,
                                        allDay: true,
                                        backgroundColor: '#dc3545',
                                        borderColor: '#dc3545',
                                        classNames: ['event-maintenance'],
                                        extendedProps: {
                                            equipment_id: item.equipment_id,
                                            equipment_name: item.equipment_name,
                                            description: item.description,
                                            status: item.status,
                                            event_type: 'maintenance'
                                        }
                                    });
                                });
                            }
                            
                            // Process calendar events
                            if (response.events && response.events.length > 0) {
                                response.events.forEach(function(item) {
                                    var backgroundColor, borderColor, className;
                                    
                                    switch(item.event_type) {
                                        case 'booking':
                                            backgroundColor = '#ff6600';
                                            borderColor = '#ff6600';
                                            className = 'event-booking';
                                            break;
                                        case 'training':
                                            backgroundColor = '#28a745';
                                            borderColor = '#28a745';
                                            className = 'event-training';
                                            break;
                                        case 'other':
                                            backgroundColor = '#17a2b8';
                                            borderColor = '#17a2b8';
                                            className = 'event-other';
                                            break;
                                        default:
                                            backgroundColor = item.color || '#ff6600';
                                            borderColor = item.color || '#ff6600';
                                            className = 'event-other';
                                    }
                                    
                                    events.push({
                                        id: 'e_' + item.id,
                                        title: item.title,
                                        start: item.start_date,
                                        end: item.end_date,
                                        allDay: item.all_day == 1,
                                        backgroundColor: backgroundColor,
                                        borderColor: borderColor,
                                        classNames: [className],
                                        extendedProps: {
                                            equipment_id: item.equipment_id,
                                            equipment_name: item.equipment_name,
                                            description: item.description,
                                            event_type: item.event_type
                                        }
                                    });
                                });
                            }
                            
                            successCallback(events);
                        },
                        error: function(error) {
                            console.error("Error fetching events:", error);
                            failureCallback(error);
                        }
                    });
                },
                select: function(info) {
                    showEventModal('create', {
                        start: info.startStr,
                        end: info.endStr,
                        allDay: info.allDay
                    });
                },
                eventClick: function(info) {
                    showEventModal('edit', {
                        id: info.event.id,
                        title: info.event.title,
                        start: info.event.startStr,
                        end: info.event.endStr,
                        allDay: info.event.allDay,
                        equipment_id: info.event.extendedProps.equipment_id,
                        equipment_name: info.event.extendedProps.equipment_name,
                        description: info.event.extendedProps.description,
                        event_type: info.event.extendedProps.event_type,
                        status: info.event.extendedProps.status
                    });
                },
                eventDrop: function(info) {
                    updateEventDates(info.event);
                },
                eventResize: function(info) {
                    updateEventDates(info.event);
                }
            });
            
            calendar.render();
            
            // Function to update event dates when dragged or resized
            function updateEventDates(event) {
                var eventId = event.id;
                var eventType = event.extendedProps.event_type;
                var startDate = event.startStr;
                var endDate = event.endStr;
                var allDay = event.allDay;
                
                $.ajax({
                    url: 'calendar.php',
                    type: 'POST',
                    data: {
                        action: 'update',
                        id: eventId.replace(/^[me]_/, ''),
                        event_type: eventType,
                        start: startDate,
                        end: endDate,
                        allDay: allDay ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Event Updated',
                                text: 'The event has been updated successfully.',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 3000
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'Failed to update event.',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 3000
                            });
                            calendar.refetchEvents();
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to update event. Please try again.',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000
                        });
                        calendar.refetchEvents();
                    }
                });
            }
            
            // Function to show event modal (create or edit)
            function showEventModal(mode, eventData) {
                var title = mode === 'create' ? 'Add New Event' : 'Edit Event';
                var confirmButtonText = mode === 'create' ? 'Create Event' : 'Update Event';
                
                var eventTypeOptions = `
                    <option value="maintenance" ${eventData.event_type === 'maintenance' ? 'selected' : ''}>Maintenance</option>
                    <option value="booking" ${eventData.event_type === 'booking' ? 'selected' : ''}>Equipment Booking</option>
                    <option value="training" ${eventData.event_type === 'training' ? 'selected' : ''}>Training Session</option>
                    <option value="other" ${eventData.event_type === 'other' || !eventData.event_type ? 'selected' : ''}>Other</option>
                `;
                
                var equipmentOptions = '<option value="">Select Equipment</option>';
                <?php foreach ($equipmentList as $equipment): ?>
                    equipmentOptions += `<option value="<?php echo $equipment['id']; ?>" ${eventData.equipment_id == <?php echo $equipment['id']; ?> ? 'selected' : ''}><?php echo htmlspecialchars($equipment['name']); ?> (<?php echo htmlspecialchars($equipment['type']); ?>)</option>`;
                <?php endforeach; ?>
                
                var colorOptions = '';
                var colors = ['#ff6600', '#dc3545', '#28a745', '#17a2b8', '#6610f2', '#fd7e14', '#ffc107', '#20c997'];
                colors.forEach(function(color) {
                    colorOptions += `<div class="color-option" style="background-color: ${color};" data-color="${color}"></div>`;
                });
                
                var startDate = eventData.start ? new Date(eventData.start) : new Date();
                var endDate = eventData.end ? new Date(eventData.end) : new Date(startDate.getTime() + 60 * 60 * 1000);
                
                var formattedStartDate = startDate.toISOString().slice(0, 16);
                var formattedEndDate = endDate.toISOString().slice(0, 16);
                
                Swal.fire({
                    title: title,
                    html: `
                        <form id="event-form" class="text-start">
                            <div class="form-group">
                                <label for="event-title">Event Title</label>
                                <input type="text" id="event-title" class="form-control" value="${eventData.title || ''}" required>
                            </div>
                            <div class="form-group">
                                <label for="event-type">Event Type</label>
                                <select id="event-type" class="form-control">
                                    ${eventTypeOptions}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="event-equipment">Equipment</label>
                                <select id="event-equipment" class="form-control">
                                    ${equipmentOptions}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="event-start">Start Date/Time</label>
                                <input type="datetime-local" id="event-start" class="form-control" value="${formattedStartDate}" required>
                            </div>
                            <div class="form-group">
                                <label for="event-end">End Date/Time</label>
                                <input type="datetime-local" id="event-end" class="form-control" value="${formattedEndDate}">
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="event-all-day" class="form-check-input" ${eventData.allDay ? 'checked' : ''}>
                                <label for="event-all-day" class="form-check-label">All Day Event</label>
                            </div>
                            <div class="form-group">
                                <label for="event-description">Description</label>
                                <textarea id="event-description" class="form-control" rows="3">${eventData.description || ''}</textarea>
                            </div>
                            <div class="form-group" id="color-picker-container">
                                <label>Event Color</label>
                                <div class="color-picker">
                                    ${colorOptions}
                                </div>
                            </div>
                        </form>
                    `,
                    showCancelButton: true,
                    confirmButtonText: confirmButtonText,
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#ff6600',
                    cancelButtonColor: '#6c757d',
                    customClass: {
                        container: document.body.classList.contains('dark-theme') ? 'dark-theme' : ''
                    },
                    didOpen: () => {
                        // Initialize color picker
                        const colorOptions = document.querySelectorAll('.color-picker .color-option');
                        colorOptions.forEach(option => {
                            option.addEventListener('click', function() {
                                colorOptions.forEach(opt => opt.classList.remove('selected'));
                                this.classList.add('selected');
                            });
                            
                            // Select default color
                            if (option.getAttribute('data-color') === '#ff6600') {
                                option.classList.add('selected');
                            }
                        });
                        
                        // Show/hide color picker based on event type
                        const eventTypeSelect = document.getElementById('event-type');
                        const colorPickerContainer = document.getElementById('color-picker-container');
                        
                        function toggleColorPicker() {
                            if (eventTypeSelect.value === 'maintenance') {
                                colorPickerContainer.style.display = 'none';
                            } else {
                                colorPickerContainer.style.display = 'block';
                            }
                        }
                        
                        toggleColorPicker();
                        eventTypeSelect.addEventListener('change', toggleColorPicker);
                        
                        // Toggle end date based on all day checkbox
                        const allDayCheckbox = document.getElementById('event-all-day');
                        const endDateInput = document.getElementById('event-end');
                        
                        function toggleEndDate() {
                            if (allDayCheckbox.checked) {
                                endDateInput.disabled = true;
                            } else {
                                endDateInput.disabled = false;
                            }
                        }
                        
                        toggleEndDate();
                        allDayCheckbox.addEventListener('change', toggleEndDate);
                    },
                    preConfirm: () => {
                        const title = document.getElementById('event-title').value;
                        const eventType = document.getElementById('event-type').value;
                        const equipmentId = document.getElementById('event-equipment').value;
                        const start = document.getElementById('event-start').value;
                        const end = document.getElementById('event-end').value;
                        const allDay = document.getElementById('event-all-day').checked;
                        const description = document.getElementById('event-description').value;
                        
                        let color = '#ff6600';
                        const selectedColor = document.querySelector('.color-picker .color-option.selected');
                        if (selectedColor) {
                            color = selectedColor.getAttribute('data-color');
                        }
                        
                        if (!title) {
                            Swal.showValidationMessage('Please enter a title');
                            return false;
                        }
                        
                        if (eventType === 'maintenance' && !equipmentId) {
                            Swal.showValidationMessage('Please select equipment for maintenance');
                            return false;
                        }
                        
                        if (!start) {
                            Swal.showValidationMessage('Please select a start date/time');
                            return false;
                        }
                        
                        return {
                            title,
                            event_type: eventType,
                            equipment_id: equipmentId,
                            start,
                            end,
                            allDay,
                            description,
                            color
                        };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = result.value;
                        
                        // Add ID if editing
                        if (mode === 'edit') {
                            formData.id = eventData.id.replace(/^[me]_/, '');
                        }
                        
                        // Add action
                        formData.action = mode;
                        
                        // Save event
                        $.ajax({
                            url: 'calendar.php',
                            type: 'POST',
                            data: formData,
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: mode === 'create' ? 'Event Created' : 'Event Updated',
                                        text: response.message,
                                        toast: true,
                                        position: 'top-end',
                                        showConfirmButton: false,
                                        timer: 3000
                                    });
                                    calendar.refetchEvents();
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: response.message || 'Failed to save event.',
                                        toast: true,
                                        position: 'top-end',
                                        showConfirmButton: false,
                                        timer: 3000
                                    });
                                }
                            },
                            error: function() {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: 'Failed to save event. Please try again.',
                                    toast: true,
                                    position: 'top-end',
                                    showConfirmButton: false,
                                    timer: 3000
                                });
                            }
                        });
                    }
                });
                
                // Add delete button if editing
                if (mode === 'edit') {
                    Swal.update({
                        showDenyButton: true,
                        denyButtonText: 'Delete',
                        denyButtonColor: '#dc3545'
                    });
                    
                    const denyButton = Swal.getDenyButton();
                    
                    if (denyButton) {
                        denyButton.addEventListener('click', () => {
                            Swal.fire({
                                title: 'Delete Event',
                                text: 'Are you sure you want to delete this event?',
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonText: 'Yes, delete it',
                                cancelButtonText: 'Cancel',
                                confirmButtonColor: '#dc3545',
                                cancelButtonColor: '#6c757d'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    // Delete event
                                    $.ajax({
                                        url: 'calendar.php',
                                        type: 'POST',
                                        data: {
                                            action: 'delete',
                                            id: eventData.id.replace(/^[me]_/, ''),
                                            event_type: eventData.event_type
                                        },
                                        success: function(response) {
                                            if (response.success) {
                                                Swal.fire({
                                                    icon: 'success',
                                                    title: 'Event Deleted',
                                                    text: response.message,
                                                    toast: true,
                                                    position: 'top-end',
                                                    showConfirmButton: false,
                                                    timer: 3000
                                                });
                                                calendar.refetchEvents();
                                            } else {
                                                Swal.fire({
                                                    icon: 'error',
                                                    title: 'Error',
                                                    text: response.message || 'Failed to delete event.',
                                                    toast: true,
                                                    position: 'top-end',
                                                    showConfirmButton: false,
                                                    timer: 3000
                                                });
                                            }
                                        },
                                        error: function() {
                                            Swal.fire({
                                                icon: 'error',
                                                title: 'Error',
                                                text: 'Failed to delete event. Please try again.',
                                                toast: true,
                                                position: 'top-end',
                                                showConfirmButton: false,
                                                timer: 3000
                                            });
                                        }
                                    });
                                }
                            });
                        });
                    }
                }
            }
            
            // Add Event button
            document.getElementById('add-event-btn').addEventListener('click', function() {
                showEventModal('create', {
                    start: new Date().toISOString(),
                    allDay: false
                });
            });
            
            // Apply filters
            document.getElementById('apply-filters').addEventListener('click', function() {
                calendar.refetchEvents();
            });
            
            // Reset filters
            document.getElementById('reset-filters').addEventListener('click', function() {
                $('#equipment-filter').val('all').trigger('change');
                $('#event-type-filter').val('all').trigger('change');
                $('#date-range').val('');
                calendar.refetchEvents();
            });
            
            // Toggle user dropdown
            function toggleDropdown() {
                document.getElementById('userDropdown').classList.toggle('show');
            }
            
            // Close dropdown when clicking outside
            window.onclick = function(event) {
                if (!event.target.matches('.dropdown-toggle') && !event.target.matches('.dropdown-toggle *')) {
                    var dropdowns = document.getElementsByClassName('dropdown-menu');
                    for (var i = 0; i < dropdowns.length; i++) {
                        var openDropdown = dropdowns[i];
                        if (openDropdown.classList.contains('show')) {
                            openDropdown.classList.remove('show');
                        }
                    }
                }
            }
            
            // Theme toggle
            document.getElementById('theme-toggle').addEventListener('change', function() {
                const theme = this.checked ? 'dark' : 'light';
                document.getElementById('themeInput').value = theme;
                document.getElementById('themeForm').submit();
            });
        });
    </script>
</body>
</html>
