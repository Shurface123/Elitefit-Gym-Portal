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

// Add new equipment
if (isset($_POST['add_equipment'])) {
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $status = $_POST['status'];
    $location = trim($_POST['location']);
    
    if (!empty($name) && !empty($type)) {
        $stmt = $conn->prepare("
            INSERT INTO equipment (name, type, status, location, updated_by)
            VALUES (:name, :type, :status, :location, :updated_by)
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':updated_by', $userId);
        
        if ($stmt->execute()) {
            $equipmentId = $conn->lastInsertId();
            
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, equipment_id, action)
                VALUES (:user_id, :equipment_id, :action)
            ");
            $action = "Added new equipment: $name";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':equipment_id', $equipmentId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            $message = "Equipment added successfully!";
            $messageType = "success";
        } else {
            $message = "Error adding equipment.";
            $messageType = "danger";
        }
    } else {
        $message = "Name and type are required.";
        $messageType = "warning";
    }
}

// Update equipment
if (isset($_POST['update_equipment'])) {
    $id = $_POST['equipment_id'];
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $status = $_POST['status'];
    $location = trim($_POST['location']);
    
    if (!empty($name) && !empty($type)) {
        $stmt = $conn->prepare("
            UPDATE equipment 
            SET name = :name, type = :type, status = :status, location = :location, updated_by = :updated_by
            WHERE id = :id
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':updated_by', $userId);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, equipment_id, action)
                VALUES (:user_id, :equipment_id, :action)
            ");
            $action = "Updated equipment: $name";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':equipment_id', $id);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            $message = "Equipment updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating equipment.";
            $messageType = "danger";
        }
    } else {
        $message = "Name and type are required.";
        $messageType = "warning";
    }
}

// Delete equipment
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Get equipment name for activity log
    $nameStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
    $nameStmt->bindParam(':id', $id);
    $nameStmt->execute();
    $equipmentName = $nameStmt->fetchColumn();
    
    $stmt = $conn->prepare("DELETE FROM equipment WHERE id = :id");
    $stmt->bindParam(':id', $id);
    
    if ($stmt->execute()) {
        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action)
            VALUES (:user_id, :action)
        ");
        $action = "Deleted equipment: $equipmentName";
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        $message = "Equipment deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Error deleting equipment.";
        $messageType = "danger";
    }
}

// Get equipment types for dropdown
$typeStmt = $conn->prepare("SELECT name FROM equipment_types ORDER BY name");
$typeStmt->execute();
$equipmentTypes = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

// Get equipment list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build query
$query = "SELECT id, name, type, status, location, last_maintenance_date, created_at FROM equipment WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM equipment WHERE 1=1";
$params = [];

if (!empty($search)) {
    $searchTerm = "%$search%";
    $query .= " AND (name LIKE :search OR type LIKE :search OR location LIKE :search)";
    $countQuery .= " AND (name LIKE :search OR type LIKE :search OR location LIKE :search)";
    $params[':search'] = $searchTerm;
}

if (!empty($typeFilter)) {
    $query .= " AND type = :type";
    $countQuery .= " AND type = :type";
    $params[':type'] = $typeFilter;
}

if (!empty($statusFilter)) {
    $query .= " AND status = :status";
    $countQuery .= " AND status = :status";
    $params[':status'] = $statusFilter;
}

// Add sorting
$query .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";
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
$equipmentList = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Management - EliteFit Gym</title>
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
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: var(--border-radius);
            width: 80%;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        
        .dark-theme .modal-content {
            background-color: #2d2d2d;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .close {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
        }
        
        .close:hover {
            color: var(--primary);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
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
                <li><a href="equipment.php" class="active"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
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
                <h1>Equipment Management</h1>
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
            
            <!-- Search and Filters -->
            <div class="card">
                <div class="card-header">
                    <h3>Search & Filters</h3>
                    <button class="btn btn-primary" onclick="openAddModal()">Add New Equipment</button>
                </div>
                <div class="card-body">
                    <form action="" method="GET" class="search-filters">
                        <div class="form-group" style="flex: 2;">
                            <input type="text" name="search" class="form-control" placeholder="Search equipment..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <select name="type" class="form-control">
                                <option value="">All Types</option>
                                <?php foreach ($equipmentTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="Available" <?php echo $statusFilter === 'Available' ? 'selected' : ''; ?>>Available</option>
                                <option value="In Use" <?php echo $statusFilter === 'In Use' ? 'selected' : ''; ?>>In Use</option>
                                <option value="Maintenance" <?php echo $statusFilter === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 0;">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="equipment.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Equipment List -->
            <div class="card">
                <div class="card-header">
                    <h3>Equipment List</h3>
                    <span>Total: <?php echo $totalCount; ?> items</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th>Last Maintenance</th>
                                    <th>Added On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($equipmentList)): ?>
                                    <?php foreach ($equipmentList as $equipment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($equipment['name']); ?></td>
                                            <td><?php echo htmlspecialchars($equipment['type']); ?></td>
                                            <td>
                                                <?php 
                                                    $statusClass = '';
                                                    switch ($equipment['status']) {
                                                        case 'Available':
                                                            $statusClass = 'badge-success';
                                                            break;
                                                        case 'In Use':
                                                            $statusClass = 'badge-info';
                                                            break;
                                                        case 'Maintenance':
                                                            $statusClass = 'badge-warning';
                                                            break;
                                                        default:
                                                            $statusClass = 'badge-secondary';
                                                    }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>">
                                                    <?php echo htmlspecialchars($equipment['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($equipment['location'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php 
                                                    echo $equipment['last_maintenance_date'] 
                                                        ? date('M d, Y', strtotime($equipment['last_maintenance_date'])) 
                                                        : 'Never';
                                                ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($equipment['created_at'])); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="openEditModal(<?php echo $equipment['id']; ?>, '<?php echo addslashes($equipment['name']); ?>', '<?php echo addslashes($equipment['type']); ?>', '<?php echo $equipment['status']; ?>', '<?php echo addslashes($equipment['location'] ?? ''); ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="maintenance.php?action=schedule&equipment_id=<?php echo $equipment['id']; ?>" class="btn btn-sm btn-warning">
                                                    <i class="fas fa-tools"></i>
                                                </a>
                                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $equipment['id']; ?>, '<?php echo addslashes($equipment['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No equipment found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li><a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeFilter); ?>&status=<?php echo urlencode($statusFilter); ?>">Previous</a></li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="<?php echo $i === $page ? 'active' : ''; ?>">
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeFilter); ?>&status=<?php echo urlencode($statusFilter); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li><a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeFilter); ?>&status=<?php echo urlencode($statusFilter); ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Equipment Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAddModal()">&times;</span>
            <h2>Add New Equipment</h2>
            <form action="" method="POST">
                <div class="form-group">
                    <label for="name">Equipment Name</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="type">Equipment Type</label>
                    <select id="type" name="type" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach ($equipmentTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>">
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="Available">Available</option>
                        <option value="In Use">In Use</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" class="form-control">
                </div>
                <div class="form-group">
                    <button type="submit" name="add_equipment" class="btn btn-primary">Add Equipment</button>
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Equipment Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2>Edit Equipment</h2>
            <form action="" method="POST">
                <input type="hidden" id="edit_equipment_id" name="equipment_id">
                <div class="form-group">
                    <label for="edit_name">Equipment Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_type">Equipment Type</label>
                    <select id="edit_type" name="type" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach ($equipmentTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>">
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" class="form-control" required>
                        <option value="Available">Available</option>
                        <option value="In Use">In Use</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_location">Location</label>
                    <input type="text" id="edit_location" name="location" class="form-control">
                </div>
                <div class="form-group">
                    <button type="submit" name="update_equipment" class="btn btn-primary">Update Equipment</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
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
        
        // Add Equipment Modal
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        // Edit Equipment Modal
        function openEditModal(id, name, type, status, location) {
            document.getElementById('edit_equipment_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_type').value = type;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_location').value = location;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Delete Confirmation
        function confirmDelete(id, name) {
            if (confirm('Are you sure you want to delete ' + name + '?')) {
                window.location.href = 'equipment.php?action=delete&id=' + id;
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('addModal')) {
                closeAddModal();
            }
            if (event.target == document.getElementById('editModal')) {
                closeEditModal();
            }
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
    </script>
</body>
</html>
