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

// Add new equipment
if (isset($_POST['add_equipment'])) {
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $status = $_POST['status'];
    $location = trim($_POST['location']);
    $purchase_date = $_POST['purchase_date'] ?? null;
    $warranty_expiry = $_POST['warranty_expiry'] ?? null;
    $cost = $_POST['cost'] ?? null;
    $serial_number = trim($_POST['serial_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    
    if (!empty($name) && !empty($type)) {
        $stmt = $conn->prepare("
            INSERT INTO equipment (name, type, status, location, purchase_date, warranty_expiry, cost, serial_number, manufacturer, updated_by)
            VALUES (:name, :type, :status, :location, :purchase_date, :warranty_expiry, :cost, :serial_number, :manufacturer, :updated_by)
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':purchase_date', $purchase_date);
        $stmt->bindParam(':warranty_expiry', $warranty_expiry);
        $stmt->bindParam(':cost', $cost);
        $stmt->bindParam(':serial_number', $serial_number);
        $stmt->bindParam(':manufacturer', $manufacturer);
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
    $purchase_date = $_POST['purchase_date'] ?? null;
    $warranty_expiry = $_POST['warranty_expiry'] ?? null;
    $cost = $_POST['cost'] ?? null;
    $serial_number = trim($_POST['serial_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    
    if (!empty($name) && !empty($type)) {
        $stmt = $conn->prepare("
            UPDATE equipment 
            SET name = :name, type = :type, status = :status, location = :location, 
                purchase_date = :purchase_date, warranty_expiry = :warranty_expiry, 
                cost = :cost, serial_number = :serial_number, manufacturer = :manufacturer, updated_by = :updated_by
            WHERE id = :id
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':purchase_date', $purchase_date);
        $stmt->bindParam(':warranty_expiry', $warranty_expiry);
        $stmt->bindParam(':cost', $cost);
        $stmt->bindParam(':serial_number', $serial_number);
        $stmt->bindParam(':manufacturer', $manufacturer);
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

// Bulk operations
if (isset($_POST['bulk_action']) && isset($_POST['selected_equipment'])) {
    $action = $_POST['bulk_action'];
    $selectedIds = $_POST['selected_equipment'];
    
    if ($action === 'delete') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("DELETE FROM equipment WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " equipment items deleted successfully!";
            $messageType = "success";
        }
    } elseif ($action === 'maintenance') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("UPDATE equipment SET status = 'Maintenance' WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " equipment items marked for maintenance!";
            $messageType = "success";
        }
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

// Get equipment statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'In Use' THEN 1 ELSE 0 END) as in_use,
        SUM(CASE WHEN status = 'Maintenance' THEN 1 ELSE 0 END) as maintenance,
        SUM(CASE WHEN status = 'Out of Order' THEN 1 ELSE 0 END) as out_of_order
    FROM equipment
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get equipment list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Build query
$query = "SELECT id, name, type, status, location, last_maintenance_date, created_at, purchase_date, warranty_expiry, cost, serial_number, manufacturer FROM equipment WHERE 1=1";
$countQuery = "SELECT COUNT(*) FROM equipment WHERE 1=1";
$params = [];

if (!empty($search)) {
    $searchTerm = "%$search%";
    $query .= " AND (name LIKE :search OR type LIKE :search OR location LIKE :search OR serial_number LIKE :search OR manufacturer LIKE :search)";
    $countQuery .= " AND (name LIKE :search OR type LIKE :search OR location LIKE :search OR serial_number LIKE :search OR manufacturer LIKE :search)";
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

// Define allowed sort fields and order directions
$allowedSorts = ['name', 'type', 'status', 'location', 'created_at', 'purchase_date'];
$allowedOrders = ['ASC', 'DESC'];

// Validate inputs
$sortBy = (isset($sortBy) && in_array($sortBy, $allowedSorts)) ? $sortBy : 'name';
$sortOrder = (isset($sortOrder) && in_array(strtoupper($sortOrder), $allowedOrders)) ? strtoupper($sortOrder) : 'ASC';

// Append sorting and pagination
$query .= " ORDER BY $sortBy $sortOrder LIMIT :limit OFFSET :offset";
$params[':limit'] = (int) $limit;
$params[':offset'] = (int) $offset;

// Prepare and bind parameters
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, in_array($key, [':limit', ':offset']) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            grid-template-columns: 2fr 1fr 1fr auto auto;
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
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: slideIn 0.3s ease;
            overflow: hidden;
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
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
                <li><a href="equipment.php" class="active"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
                <li><a href="report.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="calendar.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>Equipment Management</h1>
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
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total Equipment</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3><?php echo $stats['available']; ?></h3>
                    <p>Available</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                    <div class="icon">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <h3><?php echo $stats['in_use']; ?></h3>
                    <p>In Use</p>
                </div>
                <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                    <div class="icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3><?php echo $stats['maintenance']; ?></h3>
                    <p>Maintenance</p>
                </div>
            </div>
            
            <!-- Search and Filters -->
            <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.4s;">
                <div class="card-header">
                    <h3><i class="fas fa-search"></i> Search & Filters</h3>
                    <button class="btn" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Equipment
                    </button>
                </div>
                <div class="card-body">
                    <form action="" method="GET" class="search-filters">
                        <div class="form-group">
                            <label for="search">Search Equipment</label>
                            <input type="text" id="search" name="search" class="form-control" placeholder="Search by name, type, location, serial..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="form-group">
                            <label for="type">Equipment Type</label>
                            <select id="type" name="type" class="form-control">
                                <option value="">All Types</option>
                                <?php foreach ($equipmentTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="Available" <?php echo $statusFilter === 'Available' ? 'selected' : ''; ?>>Available</option>
                                <option value="In Use" <?php echo $statusFilter === 'In Use' ? 'selected' : ''; ?>>In Use</option>
                                <option value="Maintenance" <?php echo $statusFilter === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="Out of Order" <?php echo $statusFilter === 'Out of Order' ? 'selected' : ''; ?>>Out of Order</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                        <div class="form-group">
                            <a href="equipment.php" class="btn btn-secondary">
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
                    <input type="hidden" name="selected_equipment" id="selectedEquipment">
                    <select name="bulk_action" class="form-control" style="width: auto;">
                        <option value="">Choose Action</option>
                        <option value="maintenance">Mark for Maintenance</option>
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
            
            <!-- Equipment List -->
            <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.5s;">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Equipment List</h3>
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
                                    <th><a href="?sort=name&order=<?php echo $sortBy === 'name' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Name <?php if($sortBy === 'name') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                    <th><a href="?sort=type&order=<?php echo $sortBy === 'type' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Type <?php if($sortBy === 'type') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                    <th><a href="?sort=status&order=<?php echo $sortBy === 'status' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Status <?php if($sortBy === 'status') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                    <th>Location</th>
                                    <th>Serial Number</th>
                                    <th>Manufacturer</th>
                                    <th>Purchase Date</th>
                                    <th>Warranty</th>
                                    <th>Cost</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($equipmentList)): ?>
                                    <?php foreach ($equipmentList as $equipment): ?>
                                        <tr>
                                            <td class="checkbox-column">
                                                <input type="checkbox" class="equipment-checkbox custom-checkbox" value="<?php echo $equipment['id']; ?>" onchange="updateSelection()">
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($equipment['name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?php echo htmlspecialchars($equipment['type']); ?></span>
                                            </td>
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
                                                        case 'Out of Order':
                                                            $statusClass = 'badge-danger';
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
                                            <td><?php echo htmlspecialchars($equipment['serial_number'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($equipment['manufacturer'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php 
                                                    echo $equipment['purchase_date'] 
                                                        ? date('M d, Y', strtotime($equipment['purchase_date'])) 
                                                        : 'N/A';
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    if ($equipment['warranty_expiry']) {
                                                        $warrantyDate = strtotime($equipment['warranty_expiry']);
                                                        $isExpired = $warrantyDate < time();
                                                        echo '<span class="badge ' . ($isExpired ? 'badge-danger' : 'badge-success') . '">';
                                                        echo date('M d, Y', $warrantyDate);
                                                        echo '</span>';
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    echo $equipment['cost'] 
                                                        ? '$' . number_format($equipment['cost'], 2) 
                                                        : 'N/A';
                                                ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 5px;">
                                                    <button class="btn btn-sm" onclick="viewEquipment(<?php echo $equipment['id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-warning" onclick="openEditModal(<?php echo $equipment['id']; ?>, '<?php echo addslashes($equipment['name']); ?>', '<?php echo addslashes($equipment['type']); ?>', '<?php echo $equipment['status']; ?>', '<?php echo addslashes($equipment['location'] ?? ''); ?>', '<?php echo $equipment['purchase_date'] ?? ''; ?>', '<?php echo $equipment['warranty_expiry'] ?? ''; ?>', '<?php echo $equipment['cost'] ?? ''; ?>', '<?php echo addslashes($equipment['serial_number'] ?? ''); ?>', '<?php echo addslashes($equipment['manufacturer'] ?? ''); ?>')" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="maintenance.php?action=schedule&equipment_id=<?php echo $equipment['id']; ?>" class="btn btn-sm btn-info" title="Schedule Maintenance">
                                                        <i class="fas fa-tools"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $equipment['id']; ?>, '<?php echo addslashes($equipment['name']); ?>')" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="11" class="text-center" style="padding: 40px;">
                                            <i class="fas fa-search fa-3x" style="color: #ccc; margin-bottom: 15px;"></i>
                                            <h4>No equipment found</h4>
                                            <p>Try adjusting your search criteria or add new equipment.</p>
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
        </div>
    </div>
    
    <!-- Add Equipment Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Add New Equipment</h2>
                <button class="close" onclick="closeAddModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form action="" method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label for="name">Equipment Name *</label>
                            <input type="text" id="name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="type">Equipment Type *</label>
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
                            <label for="status">Status *</label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="Available">Available</option>
                                <option value="In Use">In Use</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Out of Order">Out of Order</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="location">Location</label>
                            <input type="text" id="location" name="location" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="serial_number">Serial Number</label>
                            <input type="text" id="serial_number" name="serial_number" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="manufacturer">Manufacturer</label>
                            <input type="text" id="manufacturer" name="manufacturer" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="purchase_date">Purchase Date</label>
                            <input type="date" id="purchase_date" name="purchase_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="warranty_expiry">Warranty Expiry</label>
                            <input type="date" id="warranty_expiry" name="warranty_expiry" class="form-control">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="cost">Cost ($)</label>
                            <input type="number" id="cost" name="cost" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                        <button type="button" class="btn btn-secondary" onclick="closeAddModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="add_equipment" class="btn">
                            <i class="fas fa-plus"></i> Add Equipment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Equipment Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Equipment</h2>
                <button class="close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form action="" method="POST">
                    <input type="hidden" id="edit_equipment_id" name="equipment_id">
                    <div style="display: grid; grid-template-columns: 
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label for="edit_name">Equipment Name *</label>
                            <input type="text" id="edit_name" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_type">Equipment Type *</label>
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
                            <label for="edit_status">Status *</label>
                            <select id="edit_status" name="status" class="form-control" required>
                                <option value="Available">Available</option>
                                <option value="In Use">In Use</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Out of Order">Out of Order</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_location">Location</label>
                            <input type="text" id="edit_location" name="location" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_serial_number">Serial Number</label>
                            <input type="text" id="edit_serial_number" name="serial_number" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_manufacturer">Manufacturer</label>
                            <input type="text" id="edit_manufacturer" name="manufacturer" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_purchase_date">Purchase Date</label>
                            <input type="date" id="edit_purchase_date" name="purchase_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_warranty_expiry">Warranty Expiry</label>
                            <input type="date" id="edit_warranty_expiry" name="warranty_expiry" class="form-control">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="edit_cost">Cost ($)</label>
                            <input type="number" id="edit_cost" name="cost" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="update_equipment" class="btn">
                            <i class="fas fa-save"></i> Update Equipment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Equipment Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-info-circle"></i> Equipment Details</h2>
                <button class="close" onclick="closeViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="equipmentDetails">
                <!-- Equipment details will be loaded here -->
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
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
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function openEditModal(id, name, type, status, location, purchaseDate, warrantyExpiry, cost, serialNumber, manufacturer) {
            document.getElementById('edit_equipment_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_type').value = type;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_location').value = location;
            document.getElementById('edit_purchase_date').value = purchaseDate;
            document.getElementById('edit_warranty_expiry').value = warrantyExpiry;
            document.getElementById('edit_cost').value = cost;
            document.getElementById('edit_serial_number').value = serialNumber;
            document.getElementById('edit_manufacturer').value = manufacturer;
            document.getElementById('editModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function viewEquipment(id) {
            // Load equipment details via AJAX
            $.ajax({
                url: 'get-equipment-details.php',
                type: 'GET',
                data: { id: id },
                success: function(response) {
                    document.getElementById('equipmentDetails').innerHTML = response;
                    document.getElementById('viewModal').style.display = 'block';
                    document.body.style.overflow = 'hidden';
                },
                error: function() {
                    alert('Error loading equipment details');
                }
            });
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Delete confirmation
        function confirmDelete(id, name) {
            if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
                window.location.href = `equipment.php?action=delete&id=${id}`;
            }
        }
        
        // Bulk selection functions
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.equipment-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelection();
        }
        
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.equipment-checkbox:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            if (selectedIds.length > 0) {
                bulkActions.classList.add('show');
                selectedCount.textContent = `${selectedIds.length} item${selectedIds.length > 1 ? 's' : ''} selected`;
                document.getElementById('selectedEquipment').value = selectedIds.join(',');
            } else {
                bulkActions.classList.remove('show');
            }
            
            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.equipment-checkbox');
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = selectedIds.length === allCheckboxes.length;
            selectAll.indeterminate = selectedIds.length > 0 && selectedIds.length < allCheckboxes.length;
        }
        
        function clearSelection() {
            document.querySelectorAll('.equipment-checkbox').forEach(cb => cb.checked = false);
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
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addModal', 'editModal', 'viewModal'];
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
                const modals = ['addModal', 'editModal', 'viewModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal.style.display === 'block') {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }
                });
            }
            
            // Ctrl+N to add new equipment
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openAddModal();
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
        
        // Initialize tooltips and animations
        document.addEventListener('DOMContentLoaded', function() {
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
        
        // Export functionality
        function exportData(format) {
            const url = new URL('export-equipment.php', window.location.origin);
            url.searchParams.set('format', format);
            
            // Add current filters
            const searchParams = new URLSearchParams(window.location.search);
            ['search', 'type', 'status'].forEach(param => {
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
                        <title>Equipment List - EliteFit Gym</title>
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
                        <h1>Equipment List - EliteFit Gym</h1>
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