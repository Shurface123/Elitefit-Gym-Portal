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

// Create enhanced inventory table if it doesn't exist
$createTableStmt = $conn->prepare("
    CREATE TABLE IF NOT EXISTS inventory (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        min_quantity INT NOT NULL DEFAULT 5,
        max_quantity INT DEFAULT NULL,
        unit_price DECIMAL(10,2) DEFAULT 0.00,
        cost_price DECIMAL(10,2) DEFAULT 0.00,
        supplier VARCHAR(255) DEFAULT NULL,
        supplier_contact VARCHAR(255) DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        barcode VARCHAR(100) DEFAULT NULL,
        sku VARCHAR(100) DEFAULT NULL,
        expiry_date DATE DEFAULT NULL,
        description TEXT,
        notes TEXT,
        status ENUM('Active', 'Discontinued', 'Backordered', 'Out of Stock') DEFAULT 'Active',
        reorder_point INT DEFAULT 10,
        last_ordered DATE DEFAULT NULL,
        last_restocked DATE DEFAULT NULL,
        total_value DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
        profit_margin DECIMAL(5,2) DEFAULT 0.00,
        weight DECIMAL(8,2) DEFAULT NULL,
        dimensions VARCHAR(100) DEFAULT NULL,
        brand VARCHAR(100) DEFAULT NULL,
        model VARCHAR(100) DEFAULT NULL,
        warranty_period INT DEFAULT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        tags TEXT,
        created_by INT DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_sku (sku),
        UNIQUE KEY unique_barcode (barcode),
        INDEX idx_category (category),
        INDEX idx_status (status),
        INDEX idx_quantity (quantity),
        INDEX idx_supplier (supplier),
        INDEX idx_location (location),
        INDEX idx_expiry_date (expiry_date),
        FULLTEXT KEY ft_search (name, description, tags, brand, model)
    )
");
$createTableStmt->execute();

// Create inventory transactions table with consistent column naming
$createTransactionsStmt = $conn->prepare("
    CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INT NOT NULL AUTO_INCREMENT,
        inventory_id INT NOT NULL,
        transaction_type ENUM('IN', 'OUT', 'ADJUSTMENT', 'TRANSFER', 'RETURN', 'DAMAGE', 'EXPIRED') NOT NULL,
        quantity_change INT NOT NULL,
        previous_quantity INT NOT NULL,
        new_quantity INT NOT NULL,
        unit_cost DECIMAL(10,2) DEFAULT 0.00,
        total_cost DECIMAL(12,2) DEFAULT 0.00,
        reference_number VARCHAR(100) DEFAULT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        notes TEXT,
        location_from VARCHAR(255) DEFAULT NULL,
        location_to VARCHAR(255) DEFAULT NULL,
        user_id INT NOT NULL,
        recorded_by INT NOT NULL,
        transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
        INDEX idx_inventory_id (inventory_id),
        INDEX idx_transaction_type (transaction_type),
        INDEX idx_transaction_date (transaction_date),
        INDEX idx_user_id (user_id),
        INDEX idx_recorded_by (recorded_by)
    )
");
$createTransactionsStmt->execute();

// Create inventory alerts table
$createAlertsStmt = $conn->prepare("
    CREATE TABLE IF NOT EXISTS inventory_alerts (
        id INT NOT NULL AUTO_INCREMENT,
        inventory_id INT NOT NULL,
        alert_type ENUM('LOW_STOCK', 'OUT_OF_STOCK', 'OVERSTOCK', 'EXPIRING', 'EXPIRED') NOT NULL,
        alert_level ENUM('INFO', 'WARNING', 'CRITICAL') NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
        INDEX idx_inventory_id (inventory_id),
        INDEX idx_alert_type (alert_type),
        INDEX idx_is_read (is_read)
    )
");
$createAlertsStmt->execute();

// Add new inventory item
if (isset($_POST['add_inventory'])) {
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $quantity = (int)$_POST['quantity'];
    $min_quantity = (int)$_POST['min_quantity'];
    $max_quantity = !empty($_POST['max_quantity']) ? (int)$_POST['max_quantity'] : null;
    $unit_price = (float)$_POST['unit_price'];
    $cost_price = (float)$_POST['cost_price'];
    $supplier = trim($_POST['supplier']);
    $supplier_contact = trim($_POST['supplier_contact']);
    $location = trim($_POST['location']);
    $barcode = trim($_POST['barcode']);
    $sku = trim($_POST['sku']);
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $description = trim($_POST['description']);
    $notes = trim($_POST['notes']);
    $status = $_POST['status'];
    $reorder_point = (int)$_POST['reorder_point'];
    $profit_margin = (float)$_POST['profit_margin'];
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $dimensions = trim($_POST['dimensions']);
    $brand = trim($_POST['brand']);
    $model = trim($_POST['model']);
    $warranty_period = !empty($_POST['warranty_period']) ? (int)$_POST['warranty_period'] : null;
    $tags = trim($_POST['tags']);

    if (!empty($name) && !empty($category)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO inventory (name, category, quantity, min_quantity, max_quantity, unit_price, cost_price, supplier, supplier_contact, location, barcode, sku, expiry_date, description, notes, status, reorder_point, profit_margin, weight, dimensions, brand, model, warranty_period, tags, created_by, updated_by)
                VALUES (:name, :category, :quantity, :min_quantity, :max_quantity, :unit_price, :cost_price, :supplier, :supplier_contact, :location, :barcode, :sku, :expiry_date, :description, :notes, :status, :reorder_point, :profit_margin, :weight, :dimensions, :brand, :model, :warranty_period, :tags, :created_by, :updated_by)
            ");
            
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':category', $category);
            $stmt->bindParam(':quantity', $quantity);
            $stmt->bindParam(':min_quantity', $min_quantity);
            $stmt->bindParam(':max_quantity', $max_quantity);
            $stmt->bindParam(':unit_price', $unit_price);
            $stmt->bindParam(':cost_price', $cost_price);
            $stmt->bindParam(':supplier', $supplier);
            $stmt->bindParam(':supplier_contact', $supplier_contact);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':barcode', $barcode);
            $stmt->bindParam(':sku', $sku);
            $stmt->bindParam(':expiry_date', $expiry_date);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':reorder_point', $reorder_point);
            $stmt->bindParam(':profit_margin', $profit_margin);
            $stmt->bindParam(':weight', $weight);
            $stmt->bindParam(':dimensions', $dimensions);
            $stmt->bindParam(':brand', $brand);
            $stmt->bindParam(':model', $model);
            $stmt->bindParam(':warranty_period', $warranty_period);
            $stmt->bindParam(':tags', $tags);
            $stmt->bindParam(':created_by', $userId);
            $stmt->bindParam(':updated_by', $userId);
            
            if ($stmt->execute()) {
                $inventoryId = $conn->lastInsertId();
                
                // Log initial stock transaction with both user_id and recorded_by
                $transStmt = $conn->prepare("
                    INSERT INTO inventory_transactions (inventory_id, transaction_type, quantity_change, previous_quantity, new_quantity, unit_cost, total_cost, reason, user_id, recorded_by)
                    VALUES (:inventory_id, 'IN', :quantity_change, 0, :new_quantity, :unit_cost, :total_cost, 'Initial Stock', :user_id, :recorded_by)
                ");
                $total_cost = $quantity * $cost_price;
                $transStmt->bindParam(':inventory_id', $inventoryId);
                $transStmt->bindParam(':quantity_change', $quantity);
                $transStmt->bindParam(':new_quantity', $quantity);
                $transStmt->bindParam(':unit_cost', $cost_price);
                $transStmt->bindParam(':total_cost', $total_cost);
                $transStmt->bindParam(':user_id', $userId);
                $transStmt->bindParam(':recorded_by', $userId);
                $transStmt->execute();
                
                // Log activity
                $logStmt = $conn->prepare("
                    INSERT INTO activity_log (user_id, action)
                    VALUES (:user_id, :action)
                ");
                $action = "Added new inventory item: $name (Quantity: $quantity)";
                $logStmt->bindParam(':user_id', $userId);
                $logStmt->bindParam(':action', $action);
                $logStmt->execute();
                
                $message = "Inventory item added successfully!";
                $messageType = "success";
            } else {
                $message = "Error adding inventory item.";
                $messageType = "danger";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $message = "Error: SKU or Barcode already exists.";
            } else {
                $message = "Error adding inventory item: " . $e->getMessage();
            }
            $messageType = "danger";
        }
    } else {
        $message = "Name and category are required.";
        $messageType = "warning";
    }
}

// Bulk operations
if (isset($_POST['bulk_action']) && isset($_POST['selected_items'])) {
    $action = $_POST['bulk_action'];
    $selectedIds = explode(',', $_POST['selected_items']);

    if ($action === 'delete') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("DELETE FROM inventory WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " inventory items deleted successfully!";
            $messageType = "success";
        }
    } elseif ($action === 'discontinue') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("UPDATE inventory SET status = 'Discontinued' WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " inventory items marked as discontinued!";
            $messageType = "success";
        }
    } elseif ($action === 'reorder') {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("UPDATE inventory SET last_ordered = CURRENT_DATE WHERE id IN ($placeholders)");
        if ($stmt->execute($selectedIds)) {
            $message = count($selectedIds) . " inventory items marked for reorder!";
            $messageType = "success";
        }
    } elseif ($action === 'export') {
        // Export selected items to CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="inventory_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Name', 'Category', 'Quantity', 'Unit Price', 'Total Value', 'Status']);
        
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $conn->prepare("SELECT id, name, category, quantity, unit_price, total_value, status FROM inventory WHERE id IN ($placeholders)");
        $stmt->execute($selectedIds);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
}

// Get inventory categories for dropdown
$categoryStmt = $conn->prepare("SELECT DISTINCT category FROM inventory ORDER BY category");
$categoryStmt->execute();
$inventoryCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

// Get suppliers for dropdown
$supplierStmt = $conn->prepare("SELECT DISTINCT supplier FROM inventory WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier");
$supplierStmt->execute();
$suppliers = $supplierStmt->fetchAll(PDO::FETCH_COLUMN);

// Get locations for dropdown
$locationStmt = $conn->prepare("SELECT DISTINCT location FROM inventory WHERE location IS NOT NULL AND location != '' ORDER BY location");
$locationStmt->execute();
$locations = $locationStmt->fetchAll(PDO::FETCH_COLUMN);

// Get inventory statistics
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_items,
        SUM(quantity) as total_quantity,
        COUNT(CASE WHEN quantity <= reorder_point THEN 1 END) as low_stock_count,
        COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock_count,
        COUNT(CASE WHEN status = 'Active' THEN 1 END) as active_items,
        COUNT(CASE WHEN status = 'Discontinued' THEN 1 END) as discontinued_items,
        SUM(total_value) as total_value,
        COUNT(CASE WHEN expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) AND expiry_date IS NOT NULL THEN 1 END) as expiring_soon,
        COUNT(CASE WHEN expiry_date < CURRENT_DATE AND expiry_date IS NOT NULL THEN 1 END) as expired,
        COUNT(CASE WHEN max_quantity IS NOT NULL AND quantity > max_quantity THEN 1 END) as overstock_count,
        AVG(profit_margin) as avg_profit_margin,
        COUNT(DISTINCT category) as total_categories,
        COUNT(DISTINCT supplier) as total_suppliers
    FROM inventory
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get recent alerts
$alertsStmt = $conn->prepare("
    SELECT a.*, i.name as item_name 
    FROM inventory_alerts a 
    JOIN inventory i ON a.inventory_id = i.id 
    WHERE a.is_read = FALSE 
    ORDER BY a.created_at DESC 
    LIMIT 10
");
$alertsStmt->execute();
$alerts = $alertsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get inventory list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$categoryFilter = isset($_GET['category']) ? trim($_GET['category']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$supplierFilter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$locationFilter = isset($_GET['location']) ? trim($_GET['location']) : '';
$stockFilter = isset($_GET['stock_filter']) ? trim($_GET['stock_filter']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Build query
$query = "
    SELECT id, name, category, quantity, min_quantity, max_quantity, unit_price, cost_price, supplier, location, barcode, sku, expiry_date, status, reorder_point, total_value, profit_margin, brand, model, created_at, updated_at
    FROM inventory 
    WHERE 1=1
";
$countQuery = "SELECT COUNT(*) FROM inventory WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (MATCH(name, description, tags, brand, model) AGAINST(:search IN NATURAL LANGUAGE MODE) OR name LIKE :search_like OR category LIKE :search_like OR supplier LIKE :search_like OR location LIKE :search_like OR barcode LIKE :search_like OR sku LIKE :search_like)";
    $countQuery .= " AND (MATCH(name, description, tags, brand, model) AGAINST(:search IN NATURAL LANGUAGE MODE) OR name LIKE :search_like OR category LIKE :search_like OR supplier LIKE :search_like OR location LIKE :search_like OR barcode LIKE :search_like OR sku LIKE :search_like)";
    $params[':search'] = $search;
    $params[':search_like'] = "%$search%";
}

if (!empty($categoryFilter)) {
    $query .= " AND category = :category";
    $countQuery .= " AND category = :category";
    $params[':category'] = $categoryFilter;
}

if (!empty($statusFilter)) {
    $query .= " AND status = :status";
    $countQuery .= " AND status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($supplierFilter)) {
    $query .= " AND supplier = :supplier";
    $countQuery .= " AND supplier = :supplier";
    $params[':supplier'] = $supplierFilter;
}

if (!empty($locationFilter)) {
    $query .= " AND location = :location";
    $countQuery .= " AND location = :location";
    $params[':location'] = $locationFilter;
}

if (!empty($stockFilter)) {
    switch ($stockFilter) {
        case 'low':
            $query .= " AND quantity <= reorder_point AND quantity > 0";
            $countQuery .= " AND quantity <= reorder_point AND quantity > 0";
            break;
        case 'out':
            $query .= " AND quantity = 0";
            $countQuery .= " AND quantity = 0";
            break;
        case 'overstock':
            $query .= " AND max_quantity IS NOT NULL AND quantity > max_quantity";
            $countQuery .= " AND max_quantity IS NOT NULL AND quantity > max_quantity";
            break;
        case 'expiring':
            $query .= " AND expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) AND expiry_date IS NOT NULL";
            $countQuery .= " AND expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) AND expiry_date IS NOT NULL";
            break;
        case 'expired':
            $query .= " AND expiry_date < CURRENT_DATE AND expiry_date IS NOT NULL";
            $countQuery .= " AND expiry_date < CURRENT_DATE AND expiry_date IS NOT NULL";
            break;
    }
}

// Add sorting
$allowedSorts = ['name', 'category', 'quantity', 'unit_price', 'supplier', 'status', 'created_at', 'total_value', 'profit_margin'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'name';
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
$inventoryList = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Advanced Inventory Management - EliteFit Gym</title>
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
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
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
            overflow-x: hidden;
        }
        
        body.dark-theme {
            background: linear-gradient(135deg, var(--black) 0%, var(--dark-gray) 100%);
            color: var(--text-light);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            position: relative;
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
            backdrop-filter: blur(20px);
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
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
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
            position: relative;
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
            border: 1px solid var(--glass-border);
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-orange), var(--primary-orange-light));
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
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .theme-switch label {
            margin: 0 10px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 20px;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .theme-switch label.active {
            background: var(--primary-orange);
            color: white;
            box-shadow: 0 2px 8px rgba(255, 102, 0, 0.3);
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
            box-shadow: 0 0 20px rgba(255, 102, 0, 0.5);
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--glass-border);
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
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
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
            box-shadow: 0 8px 20px rgba(255, 102, 0, 0.3);
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
        
        .stat-card .trend {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .trend.up {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }
        
        .trend.down {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid var(--glass-border);
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
            position: relative;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .dark-theme .card-header h3 {
            color: var(--text-light);
        }
        
        .card-body {
            padding: 30px;
        }
        
        .search-filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto auto;
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
            backdrop-filter: blur(10px);
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
            gap: 8px;
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
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(10px);
        }
        
        .dark-theme .table-container {
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
            background: rgba(45, 45, 45, 0.5);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background: transparent;
        }
        
        .table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 700;
            color: var(--black);
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.8), rgba(233, 236, 239, 0.8));
            border-bottom: 2px solid var(--primary-orange);
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
        }
        
        .dark-theme .table th {
            color: var(--text-light);
            background: linear-gradient(135deg, rgba(61, 61, 61, 0.8), rgba(45, 45, 45, 0.8));
        }
        
        .table td {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            background: rgba(255, 255, 255, 0.3);
        }
        
        .dark-theme .table td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(45, 45, 45, 0.3);
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
            gap: 5px;
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            margin: 2% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: slideIn 0.3s ease;
            border: 1px solid var(--glass-border);
        }
        
        .dark-theme .modal-content {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .modal-header {
            padding: 25px 30px;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
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
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
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
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(0, 0, 0, 0.1);
            color: var(--black);
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 600;
            min-width: 45px;
        }
        
        .dark-theme .pagination a {
            background: rgba(45, 45, 45, 0.8);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
        }
        
        .pagination a:hover, .pagination .active a {
            background: var(--primary-orange);
            color: white;
            border-color: var(--primary-orange);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            border-left: 4px solid;
            animation: slideInDown 0.3s ease;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 10px;
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
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            align-items: center;
            gap: 15px;
            animation: slideInDown 0.3s ease;
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
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
        
        .stock-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 2s infinite;
        }
        
        .stock-indicator.high {
            background: var(--success);
        }
        
        .stock-indicator.medium {
            background: var(--warning);
        }
        
        .stock-indicator.low {
            background: var(--danger);
        }
        
        .stock-indicator.out {
            background: #6c757d;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-grid-full {
            grid-column: 1 / -1;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .alerts-panel {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 350px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1500;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--glass-border);
            display: none;
        }
        
        .dark-theme .alerts-panel {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .alert-item {
            padding: 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            transition: var(--transition);
        }
        
        .dark-theme .alert-item {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .alert-item:hover {
            background: rgba(255, 102, 0, 0.05);
        }
        
        .floating-action-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
            transition: var(--transition);
            z-index: 1000;
        }
        
        .floating-action-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(255, 102, 0, 0.4);
        }
        
        .progress-ring {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: conic-gradient(var(--primary-orange) 0deg, var(--primary-orange) var(--progress, 0deg), rgba(0,0,0,0.1) var(--progress, 0deg));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-orange);
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
        
        @media (max-width: 1400px) {
            .search-filters {
                grid-template-columns: 1fr 1fr 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 1200px) {
            .search-filters {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .form-grid {
                grid-template-columns: 1fr;
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
                margin: 5% auto;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .export-buttons {
                flex-wrap: wrap;
            }
            
            .alerts-panel {
                width: 90%;
                right: 5%;
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
            <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
            <li><a href="inventory.php" class="active"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
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
            <h1><i class="fas fa-warehouse"></i> Advanced Inventory Management</h1>
            <div class="header-controls">
                <div class="theme-switch">
                    <label class="<?php echo $theme === 'light' ? 'active' : ''; ?>" onclick="switchTheme('light')">
                        <i class="fas fa-sun"></i> Light
                    </label>
                    <label class="<?php echo $theme === 'dark' ? 'active' : ''; ?>" onclick="switchTheme('dark')">
                        <i class="fas fa-moon"></i> Dark
                    </label>
                </div>
                <button class="btn btn-info btn-sm" onclick="toggleAlertsPanel()">
                    <i class="fas fa-bell"></i> Alerts
                    <?php if (count($alerts) > 0): ?>
                        <span class="badge badge-danger" style="margin-left: 5px;"><?php echo count($alerts); ?></span>
                    <?php endif; ?>
                </button>
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
                    <i class="fas fa-boxes"></i>
                </div>
                <h3><?php echo number_format($stats['total_items']); ?></h3>
                <p>Total Items</p>
                <div class="trend up">
                    <i class="fas fa-arrow-up"></i> 12%
                </div>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                <div class="icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h3><?php echo number_format($stats['total_quantity'] ?? 0); ?></h3>
                <p>Total Quantity</p>
                <div class="trend up">
                    <i class="fas fa-arrow-up"></i> 8%
                </div>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                <div class="icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3><?php echo $stats['low_stock_count']; ?></h3>
                <p>Low Stock Items</p>
                <?php if ($stats['low_stock_count'] > 0): ?>
                    <div class="trend down">
                        <i class="fas fa-arrow-down"></i> Alert
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                <div class="icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <h3>$<?php echo number_format($stats['total_value'] ?? 0, 2); ?></h3>
                <p>Total Value</p>
                <div class="trend up">
                    <i class="fas fa-arrow-up"></i> 15%
                </div>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.4s;">
                <div class="icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h3><?php echo $stats['out_of_stock_count']; ?></h3>
                <p>Out of Stock</p>
                <?php if ($stats['out_of_stock_count'] > 0): ?>
                    <div class="trend down">
                        <i class="fas fa-arrow-down"></i> Critical
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.5s;">
                <div class="icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3><?php echo $stats['expiring_soon']; ?></h3>
                <p>Expiring Soon</p>
                <?php if ($stats['expiring_soon'] > 0): ?>
                    <div class="trend down">
                        <i class="fas fa-arrow-down"></i> Warning
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <button class="btn" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Item
            </button>
            <button class="btn btn-info" onclick="openBulkModal()">
                <i class="fas fa-upload"></i> Bulk Import
            </button>
            <button class="btn btn-warning" onclick="generateReports()">
                <i class="fas fa-chart-bar"></i> Generate Reports
            </button>
            <div class="export-buttons">
                <button class="btn btn-success" onclick="exportData('excel')">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
                <button class="btn btn-secondary" onclick="exportData('pdf')">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button class="btn btn-info" onclick="printInventory()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
        
        <!-- Search and Filters -->
        <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.6s;">
            <div class="card-header">
                <h3><i class="fas fa-search"></i> Advanced Search & Filters</h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button class="btn btn-info btn-sm" onclick="toggleAdvancedFilters()">
                        <i class="fas fa-filter"></i> Advanced Filters
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="saveSearchPreset()">
                        <i class="fas fa-save"></i> Save Search
                    </button>
                </div>
            </div>
            <div class="card-body">
                <form action="" method="GET" class="search-filters">
                    <div class="form-group">
                        <label for="search">Search Inventory</label>
                        <input type="text" id="search" name="search" class="form-control" placeholder="Search by name, SKU, barcode, brand..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($inventoryCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Discontinued" <?php echo $statusFilter === 'Discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                            <option value="Backordered" <?php echo $statusFilter === 'Backordered' ? 'selected' : ''; ?>>Backordered</option>
                            <option value="Out of Stock" <?php echo $statusFilter === 'Out of Stock' ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="supplier">Supplier</label>
                        <select id="supplier" name="supplier" class="form-control">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo $supplierFilter === $supplier ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location">Location</label>
                        <select id="location" name="location" class="form-control">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $locationFilter === $location ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stock_filter">Stock Level</label>
                        <select id="stock_filter" name="stock_filter" class="form-control">
                            <option value="">All Levels</option>
                            <option value="low" <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out" <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                            <option value="overstock" <?php echo $stockFilter === 'overstock' ? 'selected' : ''; ?>>Overstock</option>
                            <option value="expiring" <?php echo $stockFilter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
                            <option value="expired" <?php echo $stockFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                    <div class="form-group">
                        <a href="inventory.php" class="btn btn-secondary">
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
                <input type="hidden" name="selected_items" id="selectedItems">
                <select name="bulk_action" class="form-control" style="width: auto;">
                    <option value="">Choose Action</option>
                    <option value="discontinue">Mark as Discontinued</option>
                    <option value="reorder">Mark for Reorder</option>
                    <option value="export">Export Selected</option>
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
        
        <!-- Inventory List -->
        <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.7s;">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Inventory Items</h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span>Total: <?php echo number_format($totalCount); ?> items</span>
                    <select onchange="changeLimit(this.value)" class="form-control" style="width: auto;">
                        <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20 per page</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                        <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200 per page</option>
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
                                <th><a href="?sort=category&order=<?php echo $sortBy === 'category' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Category <?php if($sortBy === 'category') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                <th>Stock Level</th>
                                <th><a href="?sort=quantity&order=<?php echo $sortBy === 'quantity' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Quantity <?php if($sortBy === 'quantity') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                <th>Reorder Point</th>
                                <th><a href="?sort=unit_price&order=<?php echo $sortBy === 'unit_price' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Unit Price <?php if($sortBy === 'unit_price') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                <th>Supplier</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th><a href="?sort=total_value&order=<?php echo $sortBy === 'total_value' && $sortOrder === 'ASC' ? 'desc' : 'asc'; ?>&<?php echo http_build_query($_GET); ?>" style="color: inherit; text-decoration: none;">Total Value <?php if($sortBy === 'total_value') echo $sortOrder === 'ASC' ? '↑' : '↓'; ?></a></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inventoryList)): ?>
                                <?php foreach ($inventoryList as $item): ?>
                                    <tr>
                                        <td class="checkbox-column">
                                            <input type="checkbox" class="item-checkbox custom-checkbox" value="<?php echo $item['id']; ?>" onchange="updateSelection()">
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center;">
                                                <?php if (!empty($item['barcode'])): ?>
                                                    <i class="fas fa-barcode" style="margin-right: 8px; color: var(--primary-orange);" title="Barcode: <?php echo htmlspecialchars($item['barcode']); ?>"></i>
                                                <?php endif; ?>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <br><small style="opacity: 0.7;">SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['brand'])): ?>
                                                        <br><small style="opacity: 0.7; color: var(--primary-orange);"><?php echo htmlspecialchars($item['brand']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo htmlspecialchars($item['category']); ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                                $stockLevel = 'high';
                                                $stockClass = 'success';
                                                $progressPercent = 100;
                                                
                                                if ($item['quantity'] == 0) {
                                                    $stockLevel = 'out';
                                                    $stockClass = 'secondary';
                                                    $progressPercent = 0;
                                                } elseif ($item['quantity'] <= $item['reorder_point']) {
                                                    $stockLevel = 'low';
                                                    $stockClass = 'danger';
                                                    $progressPercent = 25;
                                                } elseif ($item['quantity'] <= $item['min_quantity']) {
                                                    $stockLevel = 'medium';
                                                    $stockClass = 'warning';
                                                    $progressPercent = 60;
                                                }
                                            ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span class="stock-indicator <?php echo $stockLevel; ?>"></span>
                                                <div class="progress-ring" style="--progress: <?php echo $progressPercent * 3.6; ?>deg;">
                                                    <?php echo $progressPercent; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="font-size: 1.1rem;"><?php echo number_format($item['quantity']); ?></strong>
                                            <?php if ($item['quantity'] <= $item['reorder_point'] && $item['quantity'] > 0): ?>
                                                <i class="fas fa-exclamation-triangle" style="color: var(--danger); margin-left: 5px;" title="Below reorder point"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small style="opacity: 0.8;">
                                                Reorder: <?php echo $item['reorder_point']; ?><br>
                                                Min: <?php echo $item['min_quantity']; ?>
                                                <?php if ($item['max_quantity']): ?>
                                                    <br>Max: <?php echo $item['max_quantity']; ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div>
                                                <strong>$<?php echo number_format($item['unit_price'], 2); ?></strong>
                                                <?php if ($item['cost_price'] > 0): ?>
                                                    <br><small style="opacity: 0.7;">Cost: $<?php echo number_format($item['cost_price'], 2); ?></small>
                                                    <?php if ($item['profit_margin'] > 0): ?>
                                                        <br><small style="color: var(--success);">Margin: <?php echo number_format($item['profit_margin'], 1); ?>%</small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['supplier'])): ?>
                                                <div><?php echo htmlspecialchars($item['supplier']); ?></div>
                                            <?php else: ?>
                                                <span style="opacity: 0.5;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['location'])): ?>
                                                <i class="fas fa-map-marker-alt" style="color: var(--primary-orange); margin-right: 5px;"></i>
                                                <?php echo htmlspecialchars($item['location']); ?>
                                            <?php else: ?>
                                                <span style="opacity: 0.5;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $statusClass = '';
                                                switch ($item['status']) {
                                                    case 'Active':
                                                        $statusClass = 'badge-success';
                                                        break;
                                                    case 'Discontinued':
                                                        $statusClass = 'badge-danger';
                                                        break;
                                                    case 'Backordered':
                                                        $statusClass = 'badge-warning';
                                                        break;
                                                    case 'Out of Stock':
                                                        $statusClass = 'badge-secondary';
                                                        break;
                                                    default:
                                                        $statusClass = 'badge-info';
                                                }
                                            ?>
                                            <span class="badge <?php echo $statusClass; ?>">
                                                <?php echo htmlspecialchars($item['status']); ?>
                                            </span>
                                            <?php if (!empty($item['expiry_date']) && strtotime($item['expiry_date']) <= strtotime('+30 days')): ?>
                                                <br><small style="color: var(--danger);">
                                                    <i class="fas fa-clock"></i> Expires: <?php echo date('M d, Y', strtotime($item['expiry_date'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: var(--primary-orange); font-size: 1.1rem;">
                                                $<?php echo number_format($item['total_value'], 2); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <button class="btn btn-sm" onclick="viewItem(<?php echo $item['id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="openEditModal(<?php echo $item['id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-info" onclick="openAdjustModal(<?php echo $item['id']; ?>, '<?php echo addslashes($item['name']); ?>', <?php echo $item['quantity']; ?>)" title="Adjust Quantity">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="openTransactionModal(<?php echo $item['id']; ?>)" title="View Transactions">
                                                    <i class="fas fa-history"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $item['id']; ?>, '<?php echo addslashes($item['name']); ?>')" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center" style="padding: 40px;">
                                        <i class="fas fa-search fa-3x" style="color: #ccc; margin-bottom: 15px;"></i>
                                        <h4>No inventory items found</h4>
                                        <p>Try adjusting your search criteria or add new inventory items.</p>
                                        <button class="btn" onclick="openAddModal()">
                                            <i class="fas fa-plus"></i> Add First Item
                                        </button>
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

<!-- Alerts Panel -->
<div class="alerts-panel" id="alertsPanel">
    <div style="padding: 20px; border-bottom: 1px solid rgba(0,0,0,0.1); background: var(--primary-orange); color: white;">
        <h4 style="margin: 0; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-bell"></i> Inventory Alerts
        </h4>
    </div>
    <div style="max-height: 300px; overflow-y: auto;">
        <?php if (!empty($alerts)): ?>
            <?php foreach ($alerts as $alert): ?>
                <div class="alert-item">
                    <div style="display: flex; justify-content: between; align-items: start; gap: 10px;">
                        <div style="flex: 1;">
                            <strong><?php echo htmlspecialchars($alert['item_name']); ?></strong>
                            <p style="margin: 5px 0; font-size: 0.9rem;"><?php echo htmlspecialchars($alert['message']); ?></p>
                            <small style="opacity: 0.7;"><?php echo date('M d, Y H:i', strtotime($alert['created_at'])); ?></small>
                        </div>
                        <span class="badge badge-<?php echo $alert['alert_level'] === 'CRITICAL' ? 'danger' : ($alert['alert_level'] === 'WARNING' ? 'warning' : 'info'); ?>">
                            <?php echo $alert['alert_level']; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert-item">
                <p style="text-align: center; opacity: 0.7; margin: 20px 0;">No alerts at this time</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Inventory Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus"></i> Add New Inventory Item</h2>
            <button class="close" onclick="closeAddModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form action="" method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Item Name *</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="category">Category *</label>
                        <input type="text" id="category" name="category" class="form-control" list="categoryList" required>
                        <datalist id="categoryList">
                            <?php foreach ($inventoryCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="sku">SKU</label>
                        <input type="text" id="sku" name="sku" class="form-control" placeholder="Stock Keeping Unit">
                    </div>
                    <div class="form-group">
                        <label for="barcode">Barcode</label>
                        <input type="text" id="barcode" name="barcode" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="quantity">Initial Quantity *</label>
                        <input type="number" id="quantity" name="quantity" class="form-control" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="min_quantity">Minimum Quantity *</label>
                        <input type="number" id="min_quantity" name="min_quantity" class="form-control" min="1" value="5" required>
                    </div>
                    <div class="form-group">
                        <label for="max_quantity">Maximum Quantity</label>
                        <input type="number" id="max_quantity" name="max_quantity" class="form-control" min="1">
                    </div>
                    <div class="form-group">
                        <label for="reorder_point">Reorder Point *</label>
                        <input type="number" id="reorder_point" name="reorder_point" class="form-control" min="1" value="10" required>
                    </div>
                    <div class="form-group">
                        <label for="unit_price">Unit Price ($) *</label>
                        <input type="number" id="unit_price" name="unit_price" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="cost_price">Cost Price ($)</label>
                        <input type="number" id="cost_price" name="cost_price" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label for="profit_margin">Profit Margin (%)</label>
                        <input type="number" id="profit_margin" name="profit_margin" class="form-control" step="0.1" min="0" max="100">
                    </div>
                    <div class="form-group">
                        <label for="supplier">Supplier</label>
                        <input type="text" id="supplier" name="supplier" class="form-control" list="supplierList">
                        <datalist id="supplierList">
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo htmlspecialchars($supplier); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="supplier_contact">Supplier Contact</label>
                        <input type="text" id="supplier_contact" name="supplier_contact" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control" list="locationList">
                        <datalist id="locationList">
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="model">Model</label>
                        <input type="text" id="model" name="model" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="weight">Weight (kg)</label>
                        <input type="number" id="weight" name="weight" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label for="dimensions">Dimensions</label>
                        <input type="text" id="dimensions" name="dimensions" class="form-control" placeholder="L x W x H">
                    </div>
                    <div class="form-group">
                        <label for="warranty_period">Warranty (months)</label>
                        <input type="number" id="warranty_period" name="warranty_period" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label for="expiry_date">Expiry Date</label>
                        <input type="date" id="expiry_date" name="expiry_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="Active">Active</option>
                            <option value="Discontinued">Discontinued</option>
                            <option value="Backordered">Backordered</option>
                            <option value="Out of Stock">Out of Stock</option>
                        </select>
                    </div>
                    <div class="form-group form-grid-full">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group form-grid-full">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group form-grid-full">
                        <label for="tags">Tags (comma separated)</label>
                        <input type="text" id="tags" name="tags" class="form-control" placeholder="fitness, equipment, cardio">
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_inventory" class="btn">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Floating Action Button -->
<button class="floating-action-btn" onclick="openAddModal()" title="Add New Item">
    <i class="fas fa-plus"></i>
</button>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Global variables
    let currentItemId = null;
    let currentQuantity = 0;
    
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
    
    // Toggle alerts panel
    function toggleAlertsPanel() {
        const panel = document.getElementById('alertsPanel');
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
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
    
    // Bulk selection functions
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.item-checkbox');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
        
        updateSelection();
    }
    
    function updateSelection() {
        const checkboxes = document.querySelectorAll('.item-checkbox:checked');
        const selectedIds = Array.from(checkboxes).map(cb => cb.value);
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        
        if (selectedIds.length > 0) {
            bulkActions.classList.add('show');
            selectedCount.textContent = `${selectedIds.length} item${selectedIds.length > 1 ? 's' : ''} selected`;
            document.getElementById('selectedItems').value = selectedIds.join(',');
        } else {
            bulkActions.classList.remove('show');
        }
        
        // Update select all checkbox
        const allCheckboxes = document.querySelectorAll('.item-checkbox');
        const selectAll = document.getElementById('selectAll');
        selectAll.checked = selectedIds.length === allCheckboxes.length;
        selectAll.indeterminate = selectedIds.length > 0 && selectedIds.length < allCheckboxes.length;
    }
    
    function clearSelection() {
        document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAll').checked = false;
        updateSelection();
    }
    
    function changeLimit(limit) {
        const url = new URL(window.location);
        url.searchParams.set('limit', limit);
        url.searchParams.set('page', '1'); // Reset to first page
        window.location.href = url.toString();
    }
    
    // Export functions
    function exportData(format) {
        const url = new URL('export-inventory.php', window.location.origin);
        url.searchParams.set('format', format);
        
        // Add current filters
        const searchParams = new URLSearchParams(window.location.search);
        ['search', 'category', 'status', 'supplier', 'location', 'stock_filter'].forEach(param => {
            if (searchParams.has(param)) {
                url.searchParams.set(param, searchParams.get(param));
            }
        });
        
        window.open(url.toString(), '_blank');
    }
    
    function printInventory() {
        const printWindow = window.open('', '_blank');
        const tableHTML = document.querySelector('.table-container').innerHTML;
        
        printWindow.document.write(`
            <html>
                <head>
                    <title>Advanced Inventory Report - EliteFit Gym</title>
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
                        .badge-secondary { background-color: #6c757d; color: white; }
                    </style>
                </head>
                <body>
                    <h1>Advanced Inventory Report - EliteFit Gym</h1>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    <p>Total Items: <?php echo number_format($totalCount); ?></p>
                    <p>Total Value: $<?php echo number_format($stats['total_value'], 2); ?></p>
                    ${tableHTML}
                </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.print();
    }
    
    // Advanced features
    function toggleAdvancedFilters() {
        alert('Advanced filters feature coming soon! This will include date ranges, custom fields, and saved filter presets.');
    }
    
    function saveSearchPreset() {
        alert('Save search preset feature coming soon! This will allow you to save and quickly apply frequently used search criteria.');
    }
    
    function openBulkModal() {
        alert('Bulk import feature coming soon! This will allow you to import inventory data from CSV/Excel files.');
    }
    
    function generateReports() {
        alert('Advanced reporting feature coming soon! This will include inventory valuation, turnover analysis, and custom reports.');
    }
    
    function viewItem(id) {
        alert('View item details feature coming soon! This will show comprehensive item information including transaction history.');
    }
    
    function openEditModal(id) {
        alert('Edit item feature coming soon! This will allow you to modify all item properties.');
    }
    
    function openAdjustModal(id, name, quantity) {
        alert(`Adjust quantity feature coming soon! This will allow you to adjust stock levels for ${name} (current: ${quantity}).`);
    }
    
    function openTransactionModal(id) {
        alert('Transaction history feature coming soon! This will show all stock movements for this item.');
    }
    
    function confirmDelete(id, name) {
        if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
            window.location.href = `inventory.php?action=delete&id=${id}`;
        }
    }
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = ['addModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target === modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
        
        // Close alerts panel when clicking outside
        const alertsPanel = document.getElementById('alertsPanel');
        if (!alertsPanel.contains(event.target) && !event.target.closest('[onclick="toggleAlertsPanel()"]')) {
            alertsPanel.style.display = 'none';
        }
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Escape key to close modals and panels
        if (e.key === 'Escape') {
            const modals = ['addModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal.style.display === 'block') {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
            
            document.getElementById('alertsPanel').style.display = 'none';
        }
        
        // Ctrl+N to add new item
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            openAddModal();
        }
        
        // Ctrl+A to select all
        if (e.ctrlKey && e.key === 'a' && !e.target.matches('input, textarea')) {
            e.preventDefault();
            document.getElementById('selectAll').checked = true;
            toggleSelectAll();
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
    
    // Initialize features
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize date picker
        flatpickr("#expiry_date", {
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
        
        // Real-time search (debounced)
        let searchTimeout;
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 500);
            });
        }
        
        // Auto-calculate profit margin
        const unitPriceInput = document.getElementById('unit_price');
        const costPriceInput = document.getElementById('cost_price');
        const profitMarginInput = document.getElementById('profit_margin');
        
        function calculateProfitMargin() {
            const unitPrice = parseFloat(unitPriceInput.value) || 0;
            const costPrice = parseFloat(costPriceInput.value) || 0;
            
            if (unitPrice > 0 && costPrice > 0) {
                const margin = ((unitPrice - costPrice) / unitPrice) * 100;
                profitMarginInput.value = margin.toFixed(2);
            }
        }
        
        if (unitPriceInput && costPriceInput && profitMarginInput) {
            unitPriceInput.addEventListener('input', calculateProfitMargin);
            costPriceInput.addEventListener('input', calculateProfitMargin);
        }
    });
</script>
</body>
</html>