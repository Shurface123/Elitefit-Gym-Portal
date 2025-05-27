<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include theme helper
require_once 'dashboard-theme-helper.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Get theme preference (default to dark)
$theme = getThemePreference($userId) ?: 'dark';
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Handle form submissions
$message = '';
$messageType = '';

// Schedule maintenance
if (isset($_POST['schedule_maintenance'])) {
    $equipmentId = $_POST['equipment_id'];
    $scheduledDate = $_POST['scheduled_date'];
    $description = trim($_POST['description']);
    $priority = $_POST['priority'] ?? 'Medium';
    $estimatedDuration = $_POST['estimated_duration'] ?? null;
    $assignedTechnician = $_POST['assigned_technician'] ?? null;
    $maintenanceType = $_POST['maintenance_type'] ?? 'Routine';
    $cost = $_POST['cost'] ?? null;
    $status = 'Scheduled';
    
    if (!empty($equipmentId) && !empty($scheduledDate)) {
        $stmt = $conn->prepare("
            INSERT INTO maintenance_schedule (equipment_id, scheduled_date, description, priority, estimated_duration, assigned_technician, maintenance_type, cost, status, created_by)
            VALUES (:equipment_id, :scheduled_date, :description, :priority, :estimated_duration, :assigned_technician, :maintenance_type, :cost, :status, :created_by)
        ");
        $stmt->bindParam(':equipment_id', $equipmentId);
        $stmt->bindParam(':scheduled_date', $scheduledDate);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':estimated_duration', $estimatedDuration);
        $stmt->bindParam(':assigned_technician', $assignedTechnician);
        $stmt->bindParam(':maintenance_type', $maintenanceType);
        $stmt->bindParam(':cost', $cost);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':created_by', $userId);
        
        if ($stmt->execute()) {
            $maintenanceId = $conn->lastInsertId();
            
            // Get equipment name for activity log
            $nameStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
            $nameStmt->bindParam(':id', $equipmentId);
            $nameStmt->execute();
            $equipmentName = $nameStmt->fetchColumn();
            
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, equipment_id, action)
                VALUES (:user_id, :equipment_id, :action)
            ");
            $action = "Scheduled $maintenanceType maintenance for $equipmentName on " . date('Y-m-d', strtotime($scheduledDate));
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':equipment_id', $equipmentId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            $message = "Maintenance scheduled successfully!";
            $messageType = "success";
        } else {
            $message = "Error scheduling maintenance.";
            $messageType = "danger";
        }
    } else {
        $message = "Equipment and scheduled date are required.";
        $messageType = "warning";
    }
}

// Update maintenance status
if (isset($_POST['update_maintenance'])) {
    $maintenanceId = $_POST['maintenance_id'];
    $status = $_POST['status'];
    $description = trim($_POST['description']);
    $priority = $_POST['priority'];
    $estimatedDuration = $_POST['estimated_duration'] ?? null;
    $assignedTechnician = $_POST['assigned_technician'] ?? null;
    $actualCost = $_POST['actual_cost'] ?? null;
    $completionNotes = trim($_POST['completion_notes'] ?? '');
    
    $stmt = $conn->prepare("
        UPDATE maintenance_schedule 
        SET status = :status, description = :description, priority = :priority, 
            estimated_duration = :estimated_duration, assigned_technician = :assigned_technician,
            actual_cost = :actual_cost, completion_notes = :completion_notes, updated_by = :updated_by
        WHERE id = :id
    ");
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':priority', $priority);
    $stmt->bindParam(':estimated_duration', $estimatedDuration);
    $stmt->bindParam(':assigned_technician', $assignedTechnician);
    $stmt->bindParam(':actual_cost', $actualCost);
    $stmt->bindParam(':completion_notes', $completionNotes);
    $stmt->bindParam(':updated_by', $userId);
    $stmt->bindParam(':id', $maintenanceId);
    
    if ($stmt->execute()) {
        // Get equipment details for activity log
        $detailsStmt = $conn->prepare("
            SELECT e.id, e.name, m.scheduled_date 
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.id = :id
        ");
        $detailsStmt->bindParam(':id', $maintenanceId);
        $detailsStmt->execute();
        $details = $detailsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, equipment_id, action)
            VALUES (:user_id, :equipment_id, :action)
        ");
        $action = "Updated maintenance status to $status for " . $details['name'];
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':equipment_id', $details['id']);
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        // If maintenance is completed, update equipment's last maintenance date
        if ($status === 'Completed') {
            $updateStmt = $conn->prepare("
                UPDATE equipment 
                SET last_maintenance_date = CURRENT_DATE, status = 'Available'
                WHERE id = :equipment_id
            ");
            $updateStmt->bindParam(':equipment_id', $details['id']);
            $updateStmt->execute();
        } elseif ($status === 'In Progress') {
            $updateStmt = $conn->prepare("
                UPDATE equipment 
                SET status = 'Maintenance'
                WHERE id = :equipment_id
            ");
            $updateStmt->bindParam(':equipment_id', $details['id']);
            $updateStmt->execute();
        }
        
        $message = "Maintenance updated successfully!";
        $messageType = "success";
    } else {
        $message = "Error updating maintenance.";
        $messageType = "danger";
    }
}

// Bulk operations
if (isset($_POST['bulk_action']) && isset($_POST['selected_maintenance'])) {
    $action = $_POST['bulk_action'];
    $selectedIds = $_POST['selected_maintenance'];
    
    if ($action === 'complete') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("UPDATE maintenance_schedule SET status = 'Completed' WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " maintenance tasks marked as completed!";
            $messageType = "success";
        }
    } elseif ($action === 'delete') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("DELETE FROM maintenance_schedule WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " maintenance tasks deleted!";
            $messageType = "success";
        }
    }
}

// Quick complete maintenance
if (isset($_GET['action']) && $_GET['action'] == 'complete' && isset($_GET['id'])) {
    $maintenanceId = $_GET['id'];
    
    // Get equipment details for activity log
    $detailsStmt = $conn->prepare("
        SELECT e.id, e.name, m.scheduled_date 
        FROM maintenance_schedule m
        JOIN equipment e ON m.equipment_id = e.id
        WHERE m.id = :id
    ");
    $detailsStmt->bindParam(':id', $maintenanceId);
    $detailsStmt->execute();
    $details = $detailsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Update maintenance status
    $stmt = $conn->prepare("
        UPDATE maintenance_schedule 
        SET status = 'Completed', completed_date = CURRENT_DATE
        WHERE id = :id
    ");
    $stmt->bindParam(':id', $maintenanceId);
    
    if ($stmt->execute()) {
        // Update equipment's last maintenance date and status
        $updateStmt = $conn->prepare("
            UPDATE equipment 
            SET last_maintenance_date = CURRENT_DATE, status = 'Available'
            WHERE id = :equipment_id
        ");
        $updateStmt->bindParam(':equipment_id', $details['id']);
        $updateStmt->execute();
        
        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, equipment_id, action)
            VALUES (:user_id, :equipment_id, :action)
        ");
        $action = "Completed maintenance for " . $details['name'];
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':equipment_id', $details['id']);
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        $message = "Maintenance marked as completed!";
        $messageType = "success";
    } else {
        $message = "Error completing maintenance.";
        $messageType = "danger";
    }
}

// Delete maintenance
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $maintenanceId = $_GET['id'];
    
    // Get equipment details for activity log
    $detailsStmt = $conn->prepare("
        SELECT e.id, e.name, m.scheduled_date 
        FROM maintenance_schedule m
        JOIN equipment e ON m.equipment_id = e.id
        WHERE m.id = :id
    ");
    $detailsStmt->bindParam(':id', $maintenanceId);
    $detailsStmt->execute();
    $details = $detailsStmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $conn->prepare("DELETE FROM maintenance_schedule WHERE id = :id");
    $stmt->bindParam(':id', $maintenanceId);
    
    if ($stmt->execute()) {
        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, equipment_id, action)
            VALUES (:user_id, :equipment_id, :action)
        ");
        $action = "Deleted maintenance schedule for " . $details['name'];
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':equipment_id', $details['id']);
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        $message = "Maintenance schedule deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Error deleting maintenance schedule.";
        $messageType = "danger";
    }
}

// Get equipment list for dropdown
$equipmentStmt = $conn->prepare("SELECT id, name, type, status FROM equipment ORDER BY name");
$equipmentStmt->execute();
$equipmentList = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get technicians list
$technicianStmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'Technician' ORDER BY name");
$technicianStmt->execute();
$technicians = $technicianStmt->fetchAll(PDO::FETCH_ASSOC);

// Get maintenance statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN scheduled_date < CURRENT_DATE AND status != 'Completed' THEN 1 ELSE 0 END) as past_due
    FROM maintenance_schedule
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get maintenance list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$priorityFilter = isset($_GET['priority']) ? trim($_GET['priority']) : '';
$dateFilter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : '';
$technicianFilter = isset($_GET['technician']) ? trim($_GET['technician']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'scheduled_date';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Build query
$query = "
    SELECT m.id, e.name as equipment_name, e.type as equipment_type, m.scheduled_date, m.description, 
           m.status, m.priority, m.maintenance_type, m.estimated_duration, m.assigned_technician,
           m.cost, m.actual_cost, m.created_at, m.completed_date, m.completion_notes,
           t.name as technician_name
    FROM maintenance_schedule m
    JOIN equipment e ON m.equipment_id = e.id
    LEFT JOIN users t ON m.assigned_technician = t.id
    WHERE 1=1
";
$countQuery = "
    SELECT COUNT(*)
    FROM maintenance_schedule m
    JOIN equipment e ON m.equipment_id = e.id
    LEFT JOIN users t ON m.assigned_technician = t.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $searchTerm = "%$search%";
    $query .= " AND (e.name LIKE :search OR m.description LIKE :search OR t.name LIKE :search)";
    $countQuery .= " AND (e.name LIKE :search OR m.description LIKE :search OR t.name LIKE :search)";
    $params[':search'] = $searchTerm;
}

if (!empty($statusFilter)) {
    $query .= " AND m.status = :status";
    $countQuery .= " AND m.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($priorityFilter)) {
    $query .= " AND m.priority = :priority";
    $countQuery .= " AND m.priority = :priority";
    $params[':priority'] = $priorityFilter;
}

if (!empty($technicianFilter)) {
    $query .= " AND m.assigned_technician = :technician";
    $countQuery .= " AND m.assigned_technician = :technician";
    $params[':technician'] = $technicianFilter;
}

if (!empty($dateFilter)) {
    switch ($dateFilter) {
        case 'today':
            $query .= " AND DATE(m.scheduled_date) = CURRENT_DATE";
            $countQuery .= " AND DATE(m.scheduled_date) = CURRENT_DATE";
            break;
        case 'this_week':
            $query .= " AND YEARWEEK(m.scheduled_date, 1) = YEARWEEK(CURRENT_DATE, 1)";
            $countQuery .= " AND YEARWEEK(m.scheduled_date, 1) = YEARWEEK(CURRENT_DATE, 1)";
            break;
        case 'this_month':
            $query .= " AND MONTH(m.scheduled_date) = MONTH(CURRENT_DATE) AND YEAR(m.scheduled_date) = YEAR(CURRENT_DATE)";
            $countQuery .= " AND MONTH(m.scheduled_date) = MONTH(CURRENT_DATE) AND YEAR(m.scheduled_date) = YEAR(CURRENT_DATE)";
            break;
        case 'overdue':
            $query .= " AND m.scheduled_date < CURRENT_DATE AND m.status != 'Completed'";
            $countQuery .= " AND m.scheduled_date < CURRENT_DATE AND m.status != 'Completed'";
            break;
    }
}

// Add sorting
$allowedSorts = ['scheduled_date', 'equipment_name', 'status', 'priority', 'created_at'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'scheduled_date';
}
$query .= " ORDER BY $sortBy $sortOrder LIMIT :limit OFFSET :offset";
$params[':limit'] = $limit;
$params[':offset'] = $offset;

// Execute queries
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    if ($key == ':limit' || $key == ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$maintenanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countStmt = $conn->prepare($countQuery);
foreach ($params as $key => $value) {
    if ($key != ':limit' && $key != ':offset') {
        $countStmt->bindValue($key, $value);
    }
}
$countStmt->execute();
$totalCount = $countStmt->fetchColumn();
$totalPages = ceil($totalCount / $limit);

// Get maintenance details for edit
$maintenanceDetails = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $maintenanceId = $_GET['id'];
    $detailsStmt = $conn->prepare("
        SELECT m.*, e.name as equipment_name, e.id as equipment_id
        FROM maintenance_schedule m
        JOIN equipment e ON m.equipment_id = e.id
        WHERE m.id = :id
    ");
    $detailsStmt->bindParam(':id', $maintenanceId);
    $detailsStmt->execute();
    $maintenanceDetails = $detailsStmt->fetch(PDO::FETCH_ASSOC);
}

// Get equipment details for scheduling maintenance
$equipmentDetails = null;
if (isset($_GET['action']) && $_GET['action'] == 'schedule' && isset($_GET['equipment_id'])) {
    $equipmentId = $_GET['equipment_id'];
    $detailsStmt = $conn->prepare("SELECT id, name, type FROM equipment WHERE id = :id");
    $detailsStmt->bindParam(':id', $equipmentId);
    $detailsStmt->execute();
    $equipmentDetails = $detailsStmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Management - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary-orange: #ff6600;
            --primary-orange-dark: #e55a00;
            --primary-orange-light: #ff8533;
            --black: #000000;
            --dark-gray: #1a1a1a;
            --medium-gray: #2d2d2d;
            --light-gray: #3d3d3d;
            --text-light: #ffffff;
            --text-dark: #000000;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            --shadow-dark: 0 4px 20px rgba(0, 0, 0, 0.3);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--text-dark);
            min-height: 100vh;
            transition: var(--transition);
        }
        
        body.dark-theme {
            background: linear-gradient(135deg, var(--black) 0%, var(--dark-gray) 100%);
            color: var(--text-light);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--black) 0%, var(--dark-gray) 100%);
            color: var(--text-light);
            padding: 25px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: var(--transition);
            box-shadow: var(--shadow-dark);
        }
        
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: var(--medium-gray);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-orange);
            border-radius: 3px;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--primary-orange);
        }
        
        .sidebar-header .logo {
            width: 50px;
            height: 50px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .sidebar-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-light);
            margin: 0;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 8px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 18px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-menu a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 102, 0, 0.1), transparent);
            transition: var(--transition);
        }
        
        .sidebar-menu a:hover::before {
            left: 100%;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: var(--text-light);
            transform: translateX(5px);
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
        }
        
        .sidebar-menu a i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: var(--transition);
            min-height: 100vh;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 20px 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dark-theme .header {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--black);
            margin: 0;
            background: linear-gradient(45deg, var(--black), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .dark-theme .header h1 {
            background: linear-gradient(45deg, var(--text-light), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-controls {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .theme-switch {
            display: flex;
            align-items: center;
            background: var(--medium-gray);
            padding: 8px;
            border-radius: 25px;
            transition: var(--transition);
        }
        
        .theme-switch label {
            margin: 0 10px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 20px;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .theme-switch label.active {
            background: var(--primary-orange);
            color: white;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            position: relative;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            margin-right: 12px;
            border: 3px solid var(--primary-orange);
            transition: var(--transition);
        }
        
        .user-avatar:hover {
            transform: scale(1.1);
        }
        
        .user-details h4 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
        }
        
        .user-details p {
            margin: 0;
            font-size: 0.8rem;
            opacity: 0.7;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .dark-theme .stat-card {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-orange), var(--primary-orange-light));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card .icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: white;
            font-size: 1.5rem;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 5px 0;
            color: var(--primary-orange);
        }
        
        .stat-card p {
            margin: 0;
            opacity: 0.8;
            font-weight: 500;
        }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .dark-theme .card {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .card-header {
            padding: 25px 30px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(255, 102, 0, 0.05), rgba(255, 102, 0, 0.02));
        }
        
        .dark-theme .card-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: linear-gradient(135deg, rgba(255, 102, 0, 0.1), rgba(255, 102, 0, 0.05));
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--black);
        }
        
        .dark-theme .card-header h3 {
            color: var(--text-light);
        }
        
        .card-body {
            padding: 30px;
        }
        
        .search-filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 15px;
            margin-bottom: 25px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--black);
            font-size: 0.9rem;
        }
        
        .dark-theme .form-group label {
            color: var(--text-light);
        }
        
        .form-control {
            padding: 12px 16px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-size: 0.95rem;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .dark-theme .form-control {
            background: rgba(45, 45, 45, 0.8);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
        }
        
        .form-control:focus {
            border-color: var(--primary-orange);
            outline: 0;
            box-shadow: 0 0 0 3px rgba(255, 102, 0, 0.1);
            background: white;
        }
        
        .dark-theme .form-control:focus {
            background: var(--medium-gray);
        }
        
        .btn {
            padding: 12px 24px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.95rem;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: var(--transition);
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, var(--medium-gray), var(--light-gray));
        }
        
        .btn-success {
            background: linear-gradient(45deg, var(--success), #34ce57);
        }
        
        .btn-danger {
            background: linear-gradient(45deg, var(--danger), #e74c3c);
        }
        
        .btn-warning {
            background: linear-gradient(45deg, var(--warning), #f39c12);
            color: var(--black);
        }
        
        .btn-info {
            background: linear-gradient(45deg, var(--info), #3498db);
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.1);
        }
        
        .dark-theme .table-container {
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background: white;
        }
        
        .dark-theme .table {
            background: var(--medium-gray);
        }
        
        .table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 700;
            color: var(--black);
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: 2px solid var(--primary-orange);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .dark-theme .table th {
            color: var(--text-light);
            background: linear-gradient(135deg, var(--light-gray), var(--medium-gray));
        }
        
        .table td {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }
        
        .dark-theme .table td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .table tbody tr {
            transition: var(--transition);
        }
        
        .table tbody tr:hover {
            background: rgba(255, 102, 0, 0.05);
            transform: scale(1.01);
        }
        
        .dark-theme .table tbody tr:hover {
            background: rgba(255, 102, 0, 0.1);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-success {
            background: linear-gradient(45deg, var(--success), #27ae60);
        }
        
        .badge-warning {
            background: linear-gradient(45deg, var(--warning), #f39c12);
            color: var(--black);
        }
        
        .badge-info {
            background: linear-gradient(45deg, var(--info), #3498db);
        }
        
        .badge-danger {
            background: linear-gradient(45deg, var(--danger), #e74c3c);
        }
        
        .badge-secondary {
            background: linear-gradient(45deg, var(--medium-gray), var(--light-gray));
        }
        
        .priority-high {
            background: linear-gradient(45deg, var(--danger), #e74c3c);
        }
        
        .priority-medium {
            background: linear-gradient(45deg, var(--warning), #f39c12);
            color: var(--black);
        }
        
        .priority-low {
            background: linear-gradient(45deg, var(--info), #3498db);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background: white;
            margin: 3% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: slideIn 0.3s ease;
            overflow: hidden;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .dark-theme .modal-content {
            background: var(--medium-gray);
        }
        
        .modal-header {
            padding: 25px 30px;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 700;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: var(--transition);
        }
        
        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }
        
        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 30px 0 0;
            justify-content: center;
            gap: 8px;
        }
        
        .pagination a {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 15px;
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.1);
            color: var(--black);
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 600;
            min-width: 45px;
        }
        
        .dark-theme .pagination a {
            background: var(--medium-gray);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
        }
        
        .pagination a:hover, .pagination .active a {
            background: var(--primary-orange);
            color: white;
            border-color: var(--primary-orange);
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            border-left: 4px solid;
            animation: slideInDown 0.3s ease;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left-color: var(--warning);
        }
        
        .bulk-actions {
            display: none;
            background: var(--primary-orange);
            color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            align-items: center;
            gap: 15px;
            animation: slideInDown 0.3s ease;
        }
        
        .bulk-actions.show {
            display: flex;
        }
        
        .checkbox-column {
            width: 50px;
            text-align: center;
        }
        
        .custom-checkbox {
            width: 20px;
            height: 20px;
            accent-color: var(--primary-orange);
        }
        
        .maintenance-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .maintenance-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary-orange);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: var(--border-radius);
            border-left: 3px solid var(--primary-orange);
        }
        
        .dark-theme .timeline-item {
            background: rgba(45, 45, 45, 0.8);
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -18px;
            top: 20px;
            width: 12px;
            height: 12px;
            background: var(--primary-orange);
            border-radius: 50%;
            border: 3px solid white;
        }
        
        .dark-theme .timeline-item::before {
            border-color: var(--medium-gray);
        }
        
        .calendar-view {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .calendar-day {
            background: white;
            padding: 10px;
            min-height: 80px;
            position: relative;
        }
        
        .dark-theme .calendar-day {
            background: var(--medium-gray);
        }
        
        .calendar-day.other-month {
            opacity: 0.3;
        }
        
        .calendar-day.today {
            background: rgba(255, 102, 0, 0.1);
        }
        
        .maintenance-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            position: absolute;
            top: 5px;
            right: 5px;
        }
        
        .maintenance-dot.high {
            background: var(--danger);
        }
        
        .maintenance-dot.medium {
            background: var(--warning);
        }
        
        .maintenance-dot.low {
            background: var(--info);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @media (max-width: 1200px) {
            .search-filters {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }
            
            .sidebar-header h2,
            .sidebar-menu a span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-controls {
                align-self: flex-end;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                font-size: 0.9rem;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }
    </style>
</head>
<body class="<?php echo $theme === 'light' ? '' : 'dark-theme'; ?>">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="equipment.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php" class="active"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
                <li><a href="calendar.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a></li>
                <li><a href="report.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>Maintenance Management</h1>
                <div class="header-controls">
                    <div class="theme-switch">
                        <label class="<?php echo $theme === 'light' ? 'active' : ''; ?>" onclick="switchTheme('light')">
                            <i class="fas fa-sun"></i> Light
                        </label>
                        <label class="<?php echo $theme === 'dark' ? 'active' : ''; ?>" onclick="switchTheme('dark')">
                            <i class="fas fa-moon"></i> Dark
                        </label>
                    </div>
                    <div class="user-info">
                        <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar" class="user-avatar">
                        <div class="user-details">
                            <h4><?php echo htmlspecialchars($userName); ?></h4>
                            <p>Equipment Manager</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?> animate__animated animate__fadeInDown">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card animate__animated animate__fadeInUp">
                    <div class="icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total Maintenance</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                    <div class="icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3><?php echo $stats['scheduled']; ?></h3>
                    <p>Scheduled</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                    <div class="icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <h3><?php echo $stats['in_progress']; ?></h3>
                    <p>In Progress</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3><?php echo $stats['completed']; ?></h3>
                    <p>Completed</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.4s;">
                    <div class="icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3><?php echo $stats['past_due']; ?></h3>
                    <p>Past Due</p>
                </div>
            </div>
            
            <?php if (isset($_GET['action']) && $_GET['action'] == 'schedule'): ?>
                <!-- Schedule Maintenance Form -->
                <div class="card animate__animated animate__fadeInUp">
                    <div class="card-header">
                        <h3><i class="fas fa-plus"></i> Schedule Maintenance</h3>
                        <a href="maintenance.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label for="equipment_id">Equipment *</label>
                                    <?php if ($equipmentDetails): ?>
                                        <input type="hidden" name="equipment_id" value="<?php echo $equipmentDetails['id']; ?>">
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($equipmentDetails['name'] . ' (' . $equipmentDetails['type'] . ')'); ?>" readonly>
                                    <?php else: ?>
                                        <select id="equipment_id" name="equipment_id" class="form-control" required>
                                            <option value="">Select Equipment</option>
                                            <?php foreach ($equipmentList as $equipment): ?>
                                                <option value="<?php echo $equipment['id']; ?>">
                                                    <?php echo htmlspecialchars($equipment['name'] . ' (' . $equipment['type'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label for="maintenance_type">Maintenance Type *</label>
                                    <select id="maintenance_type" name="maintenance_type" class="form-control" required>
                                        <option value="Routine">Routine Maintenance</option>
                                        <option value="Preventive">Preventive Maintenance</option>
                                        <option value="Corrective">Corrective Maintenance</option>
                                        <option value="Emergency">Emergency Repair</option>
                                        <option value="Inspection">Safety Inspection</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="scheduled_date">Scheduled Date *</label>
                                    <input type="date" id="scheduled_date" name="scheduled_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="priority">Priority</label>
                                    <select id="priority" name="priority" class="form-control">
                                        <option value="Low">Low</option>
                                        <option value="Medium" selected>Medium</option>
                                        <option value="High">High</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="estimated_duration">Estimated Duration (hours)</label>
                                    <input type="number" id="estimated_duration" name="estimated_duration" class="form-control" step="0.5" min="0.5">
                                </div>
                                <div class="form-group">
                                    <label for="assigned_technician">Assigned Technician</label>
                                    <select id="assigned_technician" name="assigned_technician" class="form-control">
                                        <option value="">Select Technician</option>
                                        <?php foreach ($technicians as $technician): ?>
                                            <option value="<?php echo $technician['id']; ?>">
                                                <?php echo htmlspecialchars($technician['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="cost">Estimated Cost ($)</label>
                                    <input type="number" id="cost" name="cost" class="form-control" step="0.01" min="0">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label for="description">Description</label>
                                    <textarea id="description" name="description" class="form-control" rows="4" placeholder="Describe the maintenance work to be performed..."></textarea>
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                                <a href="maintenance.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" name="schedule_maintenance" class="btn">
                                    <i class="fas fa-calendar-plus"></i> Schedule Maintenance
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php elseif (isset($_GET['action']) && $_GET['action'] == 'edit' && $maintenanceDetails): ?>
                <!-- Edit Maintenance Form -->
                <div class="card animate__animated animate__fadeInUp">
                    <div class="card-header">
                        <h3><i class="fas fa-edit"></i> Edit Maintenance</h3>
                        <a href="maintenance.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <input type="hidden" name="maintenance_id" value="<?php echo $maintenanceDetails['id']; ?>">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label for="equipment_name">Equipment</label>
                                    <input type="text" id="equipment_name" class="form-control" value="<?php echo htmlspecialchars($maintenanceDetails['equipment_name']); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label for="status">Status *</label>
                                    <select id="status" name="status" class="form-control" required>
                                        <option value="Scheduled" <?php echo $maintenanceDetails['status'] === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="In Progress" <?php echo $maintenanceDetails['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="Completed" <?php echo $maintenanceDetails['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="Cancelled" <?php echo $maintenanceDetails['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>><?php echo $maintenanceDetails['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        <option value="Overdue" <?php echo $maintenanceDetails['status'] === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="priority">Priority</label>
                                    <select id="priority" name="priority" class="form-control">
                                        <option value="Low" <?php echo $maintenanceDetails['priority'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="Medium" <?php echo $maintenanceDetails['priority'] === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="High" <?php echo $maintenanceDetails['priority'] === 'High' ? 'selected' : ''; ?>>High</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="estimated_duration">Estimated Duration (hours)</label>
                                    <input type="number" id="estimated_duration" name="estimated_duration" class="form-control" step="0.5" min="0.5" value="<?php echo $maintenanceDetails['estimated_duration']; ?>">
                                </div>
                                <div class="form-group">
                                    <label for="assigned_technician">Assigned Technician</label>
                                    <select id="assigned_technician" name="assigned_technician" class="form-control">
                                        <option value="">Select Technician</option>
                                        <?php foreach ($technicians as $technician): ?>
                                            <option value="<?php echo $technician['id']; ?>" <?php echo $maintenanceDetails['assigned_technician'] == $technician['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($technician['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="actual_cost">Actual Cost ($)</label>
                                    <input type="number" id="actual_cost" name="actual_cost" class="form-control" step="0.01" min="0" value="<?php echo $maintenanceDetails['actual_cost']; ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label for="description">Description</label>
                                    <textarea id="description" name="description" class="form-control" rows="3"><?php echo htmlspecialchars($maintenanceDetails['description']); ?></textarea>
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label for="completion_notes">Completion Notes</label>
                                    <textarea id="completion_notes" name="completion_notes" class="form-control" rows="3" placeholder="Add notes about the completed work..."><?php echo htmlspecialchars($maintenanceDetails['completion_notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                                <a href="maintenance.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" name="update_maintenance" class="btn">
                                    <i class="fas fa-save"></i> Update Maintenance
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Search and Filters -->
                <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.5s;">
                    <div class="card-header">
                        <h3><i class="fas fa-search"></i> Search & Filters</h3>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-info" onclick="toggleCalendarView()">
                                <i class="fas fa-calendar"></i> Calendar View
                            </button>
                            <a href="maintenance.php?action=schedule" class="btn">
                                <i class="fas fa-plus"></i> Schedule Maintenance
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="" method="GET" class="search-filters">
                            <div class="form-group">
                                <label for="search">Search</label>
                                <input type="text" id="search" name="search" class="form-control" placeholder="Search equipment, description, or technician..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="Scheduled" <?php echo $statusFilter === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="In Progress" <?php echo $statusFilter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Overdue" <?php echo $statusFilter === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                    <option value="Cancelled" <?php echo $statusFilter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select id="priority" name="priority" class="form-control">
                                    <option value="">All Priorities</option>
                                    <option value="High" <?php echo $priorityFilter === 'High' ? 'selected' : ''; ?>>High</option>
                                    <option value="Medium" <?php echo $priorityFilter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="Low" <?php echo $priorityFilter === 'Low' ? 'selected' : ''; ?>>Low</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="date_filter">Date Filter</label>
                                <select id="date_filter" name="date_filter" class="form-control">
                                    <option value="">All Dates</option>
                                    <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="this_week" <?php echo $dateFilter === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="this_month" <?php echo $dateFilter === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="overdue" <?php echo $dateFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </div>
                            <div class="form-group">
                                <a href="maintenance.php" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulkActions">
                    <span id="selectedCount">0 items selected</span>
                    <form action="" method="POST" style="display: flex; gap: 10px; align-items: center;">
                        <input type="hidden" name="selected_maintenance" id="selectedMaintenance">
                        <select name="bulk_action" class="form-control" style="width: auto;">
                            <option value="">Choose Action</option>
                            <option value="complete">Mark as Completed</option>
                            <option value="delete">Delete Selected</option>
                        </select>
                        <button type="submit" class="btn btn-warning" onclick="return confirm('Are you sure you want to perform this action?')">
                            <i class="fas fa-play"></i> Execute
                        </button>
                    </form>
                    <button class="btn btn-secondary" onclick="clearSelection()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                
                <!-- Calendar View (Hidden by default) -->
                <div class="card" id="calendarView" style="display: none;">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar"></i> Maintenance Calendar</h3>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button class="btn btn-sm btn-secondary" onclick="changeMonth(-1)">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <span id="currentMonth" style="font-weight: 600; min-width: 150px; text-align: center;"></span>
                            <button class="btn btn-sm btn-secondary" onclick="changeMonth(1)">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="calendar-view" id="calendarGrid">
                            <!-- Calendar will be generated by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <!-- Maintenance List -->
                <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.6s;" id="maintenanceList">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Maintenance Schedule</h3>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <span>Total: <?php echo $totalCount; ?> items</span>
                            <select onchange="changeLimit(this.value)" class="form-control" style="width: auto;">
                                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 per page</option>
                                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-column">
                                            <input type="checkbox" id="selectAll" class="custom-checkbox" onchange="toggleSelectAll()">
                                        </th>
                                        <th><a href="?sort=equipment_name&order=<?php echo $sortBy === 'equipment_name' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Equipment <?php if($sortBy === 'equipment_name') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th>Type</th>
                                        <th><a href="?sort=scheduled_date&order=<?php echo $sortBy === 'scheduled_date' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Scheduled Date <?php if($sortBy === 'scheduled_date') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th>Maintenance Type</th>
                                        <th><a href="?sort=priority&order=<?php echo $sortBy === 'priority' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Priority <?php if($sortBy === 'priority') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th><a href="?sort=status&order=<?php echo $sortBy === 'status' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Status <?php if($sortBy === 'status') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                        <th>Technician</th>
                                        <th>Duration</th>
                                        <th>Cost</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($maintenanceList)): ?>
                                        <?php foreach ($maintenanceList as $maintenance): ?>
                                            <tr>
                                                <td class="checkbox-column">
                                                    <input type="checkbox" class="maintenance-checkbox custom-checkbox" value="<?php echo $maintenance['id']; ?>" onchange="updateSelection()">
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($maintenance['equipment_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo htmlspecialchars($maintenance['equipment_type']); ?></span>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $scheduledDate = strtotime($maintenance['scheduled_date']);
                                                        $isOverdue = $scheduledDate < time() && $maintenance['status'] !== 'Completed';
                                                        echo '<span class="' . ($isOverdue ? 'text-danger' : '') . '">';
                                                        echo date('M d, Y', $scheduledDate);
                                                        if ($isOverdue) echo ' <i class="fas fa-exclamation-triangle"></i>';
                                                        echo '</span>';
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($maintenance['maintenance_type']); ?></td>
                                                <td>
                                                    <?php 
                                                        $priorityClass = '';
                                                        switch ($maintenance['priority']) {
                                                            case 'High':
                                                                $priorityClass = 'priority-high';
                                                                break;
                                                            case 'Medium':
                                                                $priorityClass = 'priority-medium';
                                                                break;
                                                            case 'Low':
                                                                $priorityClass = 'priority-low';
                                                                break;
                                                        }
                                                    ?>
                                                    <span class="badge <?php echo $priorityClass; ?>">
                                                        <?php echo htmlspecialchars($maintenance['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $statusClass = '';
                                                        switch ($maintenance['status']) {
                                                            case 'Scheduled':
                                                                $statusClass = 'badge-info';
                                                                break;
                                                            case 'In Progress':
                                                                $statusClass = 'badge-warning';
                                                                break;
                                                            case 'Completed':
                                                                $statusClass = 'badge-success';
                                                                break;
                                                            case 'Overdue':
                                                                $statusClass = 'badge-danger';
                                                                break;
                                                            case 'Cancelled':
                                                                $statusClass = 'badge-secondary';
                                                                break;
                                                        }
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <?php echo htmlspecialchars($maintenance['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($maintenance['technician_name'] ?? 'Unassigned'); ?></td>
                                                <td><?php echo $maintenance['estimated_duration'] ? $maintenance['estimated_duration'] . 'h' : 'N/A'; ?></td>
                                                <td>
                                                    <?php 
                                                        if ($maintenance['actual_cost']) {
                                                            echo '$' . number_format($maintenance['actual_cost'], 2);
                                                        } elseif ($maintenance['cost']) {
                                                            echo '$' . number_format($maintenance['cost'], 2) . ' (est.)';
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                    ?>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 5px;">
                                                        <button class="btn btn-sm" onclick="viewMaintenance(<?php echo $maintenance['id']; ?>)" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a href="maintenance.php?action=edit&id=<?php echo $maintenance['id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($maintenance['status'] !== 'Completed'): ?>
                                                            <a href="maintenance.php?action=complete&id=<?php echo $maintenance['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark this maintenance as completed?')" title="Complete">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $maintenance['id']; ?>, '<?php echo addslashes($maintenance['equipment_name']); ?>')" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center" style="padding: 40px;">
                                                <i class="fas fa-calendar-times fa-3x" style="color: #ccc; margin-bottom: 15px;"></i>
                                                <h4>No maintenance schedules found</h4>
                                                <p>Try adjusting your search criteria or schedule new maintenance.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li><a href="?page=1&<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="First"><i class="fas fa-angle-double-left"></i></a></li>
                                    <li><a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="Previous"><i class="fas fa-angle-left"></i></a></li>
                                <?php endif; ?>
                                
                                <?php 
                                    $start = max(1, $page - 2);
                                    $end = min($totalPages, $page + 2);
                                    for ($i = $start; $i <= $end; $i++): 
                                ?>
                                    <li class="<?php echo $i === $page ? 'active' : ''; ?>">
                                        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <li><a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="Next"><i class="fas fa-angle-right"></i></a></li>
                                    <li><a href="?page=<?php echo $totalPages; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" title="Last"><i class="fas fa-angle-double-right"></i></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Maintenance Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-info-circle"></i> Maintenance Details</h2>
                <button class="close" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="maintenanceDetails">
                <!-- Maintenance details will be loaded here -->
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Theme switching
        function switchTheme(theme) {
            // Update UI immediately
            document.body.classList.toggle('dark-theme', theme === 'dark');
            
            // Update active labels
            document.querySelectorAll('.theme-switch label').forEach(label => {
                label.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Save theme preference via AJAX
            $.ajax({
                url: 'save-theme.php',
                type: 'POST',
                data: { theme: theme },
                dataType: 'json',
                success: function(response) {
                    if (!response.success) {
                        console.error('Failed to save theme preference');
                    }
                },
                error: function() {
                    console.error('Error saving theme preference');
                }
            });
        }
        
        // View maintenance details
        function viewMaintenance(id) {
            // Load maintenance details via AJAX
            $.ajax({
                url: 'get-maintenance-details.php',
                type: 'GET',
                data: { id: id },
                success: function(response) {
                    document.getElementById('maintenanceDetails').innerHTML = response;
                    document.getElementById('viewModal').style.display = 'block';
                    document.body.style.overflow = 'hidden';
                },
                error: function() {
                    alert('Error loading maintenance details');
                }
            });
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Delete confirmation
        function confirmDelete(id, equipmentName) {
            if (confirm(`Are you sure you want to delete the maintenance schedule for "${equipmentName}"? This action cannot be undone.`)) {
                window.location.href = `maintenance.php?action=delete&id=${id}`;
            }
        }
        
        // Bulk selection functions
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.maintenance-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelection();
        }
        
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.maintenance-checkbox:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            if (selectedIds.length > 0) {
                bulkActions.classList.add('show');
                selectedCount.textContent = `${selectedIds.length} item${selectedIds.length > 1 ? 's' : ''} selected`;
                document.getElementById('selectedMaintenance').value = selectedIds.join(',');
            } else {
                bulkActions.classList.remove('show');
            }
            
            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.maintenance-checkbox');
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = selectedIds.length === allCheckboxes.length;
            selectAll.indeterminate = selectedIds.length > 0 && selectedIds.length < allCheckboxes.length;
        }
        
        function clearSelection() {
            document.querySelectorAll('.maintenance-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }
        
        // Change items per page
        function changeLimit(limit) {
            const url = new URL(window.location);
            url.searchParams.set('limit', limit);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        }
        
        // Calendar view functionality
        let currentCalendarDate = new Date();
        
        function toggleCalendarView() {
            const calendarView = document.getElementById('calendarView');
            const maintenanceList = document.getElementById('maintenanceList');
            
            if (calendarView.style.display === 'none') {
                calendarView.style.display = 'block';
                maintenanceList.style.display = 'none';
                generateCalendar();
            } else {
                calendarView.style.display = 'none';
                maintenanceList.style.display = 'block';
            }
        }
        
        function changeMonth(direction) {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + direction);
            generateCalendar();
        }
        
        function generateCalendar() {
            const monthNames = ["January", "February", "March", "April", "May", "June",
                "July", "August", "September", "October", "November", "December"];
            
            document.getElementById('currentMonth').textContent = 
                `${monthNames[currentCalendarDate.getMonth()]} ${currentCalendarDate.getFullYear()}`;
            
            // This would be populated with actual maintenance data via AJAX
            const calendarGrid = document.getElementById('calendarGrid');
            calendarGrid.innerHTML = '<div style="grid-column: span 7; text-align: center; padding: 20px;">Calendar functionality would be implemented here with maintenance data</div>';
        }
        
        // Initialize date picker
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#scheduled_date", {
                minDate: "today",
                dateFormat: "Y-m-d"
            });
            
            // Add loading animation to buttons
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        submitBtn.disabled = true;
                    }
                });
            });
            
            // Add smooth scrolling to pagination
            document.querySelectorAll('.pagination a').forEach(link => {
                link.addEventListener('click', function() {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            });
        });
        
        // Real-time search (debounced)
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['viewModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key to close modals
            if (e.key === 'Escape') {
                const modals = ['viewModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal.style.display === 'block') {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
            
            // Ctrl+N to schedule new maintenance
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'maintenance.php?action=schedule';
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
        
        // Export functionality
        function exportData(format) {
            const url = new URL('export-maintenance.php', window.location.origin);
            url.searchParams.set('format', format);
            
            // Add current filters
            const searchParams = new URLSearchParams(window.location.search);
            ['search', 'status', 'priority', 'date_filter', 'technician'].forEach(param => {
                if (searchParams.has(param)) {
                    url.searchParams.set(param, searchParams.get(param));
                }
            });
            
            window.open(url.toString(), '_blank');
        }
        
        // Print functionality
        function printTable() {
            const printWindow = window.open('', '_blank');
            const tableHTML = document.querySelector('.table-container').innerHTML;
            
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Maintenance Schedule - EliteFit Gym</title>
                        <style>
                            body { font-family: Arial, sans-serif; }
                            table { width: 100%; border-collapse: collapse; }
                            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                            th { background-color: #f2f2f2; }
                            .badge { padding: 2px 6px; border-radius: 3px; font-size: 0.8em; }
                            .badge-success { background-color: #28a745; color: white; }
                            .badge-warning { background-color: #ffc107; color: black; }
                            .badge-info { background-color: #17a2b8; color: white; }
                            .badge-danger { background-color: #dc3545; color: white; }
                        </style>
                    </head>
                    <body>
                        <h1>Maintenance Schedule - EliteFit Gym</h1>
                        <p>Generated on: ${new Date().toLocaleDateString()}</p>
                        ${tableHTML}
                    </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.print();
        }
    </script>
</body>
</html>