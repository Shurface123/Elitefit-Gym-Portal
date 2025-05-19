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

// Get theme preference
$theme = getThemePreference($userId);
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
    $status = 'Scheduled';
    
    if (!empty($equipmentId) && !empty($scheduledDate)) {
        $stmt = $conn->prepare("
            INSERT INTO maintenance_schedule (equipment_id, scheduled_date, description, status)
            VALUES (:equipment_id, :scheduled_date, :description, :status)
        ");
        $stmt->bindParam(':equipment_id', $equipmentId);
        $stmt->bindParam(':scheduled_date', $scheduledDate);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':status', $status);
        
        if ($stmt->execute()) {
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
            $action = "Scheduled maintenance for $equipmentName on " . date('Y-m-d', strtotime($scheduledDate));
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
    
    $stmt = $conn->prepare("
        UPDATE maintenance_schedule 
        SET status = :status, description = :description
        WHERE id = :id
    ");
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':description', $description);
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
                SET last_maintenance_date = CURRENT_DATE
                WHERE id = :equipment_id
            ");
            $updateStmt->bindParam(':equipment_id', $details['id']);
            $updateStmt->execute();
        }
        
        $message = "Maintenance status updated successfully!";
        $messageType = "success";
    } else {
        $message = "Error updating maintenance status.";
        $messageType = "danger";
    }
}

// Complete maintenance (quick action)
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
        SET status = 'Completed'
        WHERE id = :id
    ");
    $stmt->bindParam(':id', $maintenanceId);
    
    if ($stmt->execute()) {
        // Update equipment's last maintenance date
        $updateStmt = $conn->prepare("
            UPDATE equipment 
            SET last_maintenance_date = CURRENT_DATE
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
$equipmentStmt = $conn->prepare("SELECT id, name FROM equipment ORDER BY name");
$equipmentStmt->execute();
$equipmentList = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get maintenance list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$dateFilter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : '';

// Build query
$query = "
    SELECT m.id, e.name as equipment_name, m.scheduled_date, m.description, m.status, m.created_at
    FROM maintenance_schedule m
    JOIN equipment e ON m.equipment_id = e.id
    WHERE 1=1
";
$countQuery = "
    SELECT COUNT(*)
    FROM maintenance_schedule m
    JOIN equipment e ON m.equipment_id = e.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $searchTerm = "%$search%";
    $query .= " AND (e.name LIKE :search OR m.description LIKE :search)";
    $countQuery .= " AND (e.name LIKE :search OR m.description LIKE :search)";
    $params[':search'] = $searchTerm;
}

if (!empty($statusFilter)) {
    $query .= " AND m.status = :status";
    $countQuery .= " AND m.status = :status";
    $params[':status'] = $statusFilter;
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
$query .= " ORDER BY m.scheduled_date DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $limit;
$params[':offset'] = $offset;

// Execute queries
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    if ($key == ':limit') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else if ($key == ':offset') {
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
        SELECT m.*, e.name as equipment_name
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
    $detailsStmt = $conn->prepare("SELECT id, name FROM equipment WHERE id = :id");
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff6600;
            --primary-dark: #e65c00;
            --secondary: #333;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 8px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
            min-height: 100vh;
        }
        
        body.dark-theme {
            background-color: #1a1a1a;
            color: #f5f5f5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--secondary);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 10px;
            color: var(--primary);
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
            padding: 10px;
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all 0.3s;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: var(--primary);
        }
        
        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .dark-theme .header {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .header h1 {
            font-size: 1.8rem;
            color: var(--secondary);
        }
        
        .dark-theme .header h1 {
            color: #f5f5f5;
        }
        
        .user-info {
            display: flex;
            align-items: center;
        }
        
        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .user-info .dropdown {
            position: relative;
        }
        
        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        
        .user-info .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 10px 0;
            min-width: 180px;
            z-index: 1000;
            display: none;
        }
        
        .dark-theme .user-info .dropdown-menu {
            background-color: #2d2d2d;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .user-info .dropdown-menu.show {
            display: block;
        }
        
        .user-info .dropdown-menu a {
            display: block;
            padding: 8px 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .dark-theme .user-info .dropdown-menu a {
            color: #f5f5f5;
        }
        
        .user-info .dropdown-menu a:hover {
            background-color: #f8f9fa;
        }
        
        .dark-theme .user-info .dropdown-menu a:hover {
            background-color: #3d3d3d;
        }
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .dark-theme .card {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dark-theme .card-header {
            border-bottom: 1px solid #3d3d3d;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .dark-theme .table th, 
        .dark-theme .table td {
            border-bottom: 1px solid #3d3d3d;
        }
        
        .table th {
            font-weight: 600;
            color: var(--secondary);
            background-color: #f8f9fa;
        }
        
        .dark-theme .table th {
            color: #f5f5f5;
            background-color: #3d3d3d;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }
        
        .badge-success {
            background-color: var(--success);
        }
        
        .badge-warning {
            background-color: var(--warning);
        }
        
        .badge-info {
            background-color: var(--info);
        }
        
        .badge-danger {
            background-color: var(--danger);
        }
        
        .btn {
            padding: 8px 15px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.9rem;
        }
        
        .btn-secondary {
            background-color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background-color: #444;
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-danger {
            background-color: var(--danger);
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
        
        .theme-switch {
            display: flex;
            align-items: center;
            margin-right: 20px;
        }
        
        .theme-switch label {
            margin: 0 10px 0 0;
            cursor: pointer;
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
        
        .search-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .form-control {
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .dark-theme .form-control {
            background-color: #2d2d2d;
            border-color: #3d3d3d;
            color: #f5f5f5;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(255, 102, 0, 0.25);
        }
        
        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 20px 0 0;
            justify-content: center;
        }
        
        .pagination li {
            margin: 0 5px;
        }
        
        .pagination a {
            display: block;
            padding: 5px 10px;
            background-color: white;
            border: 1px solid #ddd;
            color: var(--primary);
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .dark-theme .pagination a {
            background-color: #2d2d2d;
            border-color: #3d3d3d;
            color: var(--primary);
        }
        
        .pagination a:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .pagination .active a {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .alert {
            padding: 10px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .dark-theme .alert-success {
            background-color: #155724;
            color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .dark-theme .alert-danger {
            background-color: #721c24;
            color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .dark-theme .alert-warning {
            background-color: #856404;
            color: #fff3cd;
            border: 1px solid #ffeeba;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }
            
            .sidebar-header h2, .sidebar-menu a span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .search-filters {
                flex-direction: column;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .user-info {
                margin-top: 10px;
                align-self: flex-end;
            }
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
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="equipment.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php" class="active"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
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
                <div class="d-flex align-items-center">
                    <div class="theme-switch">
                        <label for="theme-toggle">
                            <i class="fas fa-moon" style="color: <?php echo $theme === 'dark' ? 'var(--primary)' : '#aaa'; ?>"></i>
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
                                <i class="fas fa-chevron-down ml-2"></i>
                            </div>
                            <div class="dropdown-menu" id="userDropdown">
                                <a href="settings.php"><i class="fas fa-user"></i> Profile</a>
                                <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['action']) && $_GET['action'] == 'schedule'): ?>
                <!-- Schedule Maintenance Form -->
                <div class="card">
                    <div class="card-header">
                        <h3>Schedule Maintenance</h3>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <div class="form-group">
                                <label for="equipment_id">Equipment</label>
                                <?php if ($equipmentDetails): ?>
                                    <input type="hidden" name="equipment_id" value="<?php echo $equipmentDetails['id']; ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($equipmentDetails['name']); ?>" readonly>
                                <?php else: ?>
                                    <select id="equipment_id" name="equipment_id" class="form-control" required>
                                        <option value="">Select Equipment</option>
                                        <?php foreach ($equipmentList as $equipment): ?>
                                            <option value="<?php echo $equipment['id']; ?>">
                                                <?php echo htmlspecialchars($equipment['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="scheduled_date">Scheduled Date</label>
                                <input type="date" id="scheduled_date" name="scheduled_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <button type="submit" name="schedule_maintenance" class="btn btn-primary">Schedule Maintenance</button>
                                <a href="maintenance.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php elseif (isset($_GET['action']) && $_GET['action'] == 'edit' && $maintenanceDetails): ?>
                <!-- Edit Maintenance Form -->
                <div class="card">
                    <div class="card-header">
                        <h3>Edit Maintenance</h3>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <input type="hidden" name="maintenance_id" value="<?php echo $maintenanceDetails['id']; ?>">
                            <div class="form-group">
                                <label for="equipment_name">Equipment</label>
                                <input type="text" id="equipment_name" class="form-control" value="<?php echo htmlspecialchars($maintenanceDetails['equipment_name']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="scheduled_date">Scheduled Date</label>
                                <input type="date" id="scheduled_date" name="scheduled_date" class="form-control" value="<?php echo $maintenanceDetails['scheduled_date']; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control" required>
                                    <option value="Scheduled" <?php echo $maintenanceDetails['status'] === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="In Progress" <?php echo $maintenanceDetails['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed" <?php echo $maintenanceDetails['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Overdue" <?php echo $maintenanceDetails['status'] === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" class="form-control" rows="3"><?php echo htmlspecialchars($maintenanceDetails['description']); ?></textarea>
                            </div>
                            <div class="form-group">
                                <button type="submit" name="update_maintenance" class="btn btn-primary">Update Maintenance</button>
                                <a href="maintenance.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Search and Filters -->
                <div class="card">
                    <div class="card-header">
                        <h3>Search & Filters</h3>
                        <a href="maintenance.php?action=schedule" class="btn btn-primary">Schedule Maintenance</a>
                    </div>
                    <div class="card-body">
                        <form action="" method="GET" class="search-filters">
                            <div class="form-group" style="flex: 2;">
                                <input type="text" name="search" class="form-control" placeholder="Search equipment or description..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="Scheduled" <?php echo $statusFilter === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="In Progress" <?php echo $statusFilter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Overdue" <?php echo $statusFilter === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <select name="date_filter" class="form-control">
                                    <option value="">All Dates</option>
                                    <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="this_week" <?php echo $dateFilter === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="this_month" <?php echo $dateFilter === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="overdue" <?php echo $dateFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 0;">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="maintenance.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Maintenance List -->
                <div class="card">
                    <div class="card-header">
                        <h3>Maintenance Schedule</h3>
                        <span>Total: <?php echo $totalCount; ?> items</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Equipment</th>
                                        <th>Scheduled Date</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($maintenanceList)): ?>
                                        <?php foreach ($maintenanceList as $maintenance): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($maintenance['equipment_name']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($maintenance['scheduled_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($maintenance['description']); ?></td>
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
                                                            default:
                                                                $statusClass = 'badge-secondary';
                                                        }
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <?php echo htmlspecialchars($maintenance['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($maintenance['created_at'])); ?></td>
                                                <td>
                                                    <a href="maintenance.php?action=edit&id=<?php echo $maintenance['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($maintenance['status'] !== 'Completed'): ?>
                                                        <a href="maintenance.php?action=complete&id=<?php echo $maintenance['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark this maintenance as completed?')">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="maintenance.php?action=delete&id=<?php echo $maintenance['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this maintenance schedule?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No maintenance schedules found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li><a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&date_filter=<?php echo urlencode($dateFilter); ?>">Previous</a></li>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="<?php echo $i === $page ? 'active' : ''; ?>">
                                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&date_filter=<?php echo urlencode($dateFilter); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <li><a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&date_filter=<?php echo urlencode($dateFilter); ?>">Next</a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
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
            
            // Save theme preference via AJAX
            $.ajax({
                url: 'save-theme.php',
                type: 'POST',
                data: { theme: theme },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Toggle body class
                        document.body.classList.toggle('dark-theme', theme === 'dark');
                    }
                }
            });
        });
    </script>
</body>
</html>
