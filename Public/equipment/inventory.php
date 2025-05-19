<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include theme helper
require_once 'dashboard-theme-helper.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? 'Equipment Manager';

// Get theme preference
$theme = getThemePreference($userId);
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Check if inventory table exists
$tableCheckStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'inventory'
");
$tableCheckStmt->execute();
$inventoryTableExists = $tableCheckStmt->fetchColumn();

// Create inventory table if it doesn't exist
if (!$inventoryTableExists) {
    $createTableStmt = $conn->prepare("
        CREATE TABLE IF NOT EXISTS inventory (
            id INT NOT NULL AUTO_INCREMENT,
            item_name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            unit_price DECIMAL(10,2) DEFAULT 0.00,
            supplier VARCHAR(100) DEFAULT NULL,
            location VARCHAR(100) DEFAULT NULL,
            min_quantity INT DEFAULT 5,
            description TEXT,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )
    ");
    $createTableStmt->execute();
}

// Get inventory items
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'item_name';
$order = isset($_GET['order']) ? $_GET['order'] : 'asc';

// Build query
$query = "SELECT * FROM inventory WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (item_name LIKE :search OR category LIKE :search OR supplier LIKE :search)";
}

if ($category != 'all') {
    $query .= " AND category = :category";
}

$query .= " ORDER BY " . $sort . " " . $order;

$stmt = $conn->prepare($query);

if (!empty($search)) {
    $searchParam = '%' . $search . '%';
    $stmt->bindParam(':search', $searchParam);
}

if ($category != 'all') {
    $stmt->bindParam(':category', $category);
}

$stmt->execute();
$inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categoryStmt = $conn->prepare("SELECT DISTINCT category FROM inventory ORDER BY category");
$categoryStmt->execute();
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new inventory item
    if (isset($_POST['add_item'])) {
        $itemName = $_POST['item_name'];
        $category = $_POST['category'];
        $quantity = $_POST['quantity'];
        $unitPrice = $_POST['unit_price'];
        $supplier = $_POST['supplier'];
        $location = $_POST['location'];
        $minQuantity = $_POST['min_quantity'];
        $description = $_POST['description'];
        
        $stmt = $conn->prepare("
            INSERT INTO inventory (item_name, category, quantity, unit_price, supplier, location, min_quantity, description, created_by)
            VALUES (:item_name, :category, :quantity, :unit_price, :supplier, :location, :min_quantity, :description, :created_by)
        ");
        
        $stmt->bindParam(':item_name', $itemName);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':unit_price', $unitPrice);
        $stmt->bindParam(':supplier', $supplier);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':min_quantity', $minQuantity);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':created_by', $userId);
        
        if ($stmt->execute()) {
            $successMessage = "Item added successfully!";
            // Redirect to avoid form resubmission
            header("Location: inventory.php?success=added");
            exit();
        } else {
            $errorMessage = "Failed to add item.";
        }
    }
    
    // Update inventory item
    if (isset($_POST['update_item'])) {
        $itemId = $_POST['item_id'];
        $itemName = $_POST['item_name'];
        $category = $_POST['category'];
        $quantity = $_POST['quantity'];
        $unitPrice = $_POST['unit_price'];
        $supplier = $_POST['supplier'];
        $location = $_POST['location'];
        $minQuantity = $_POST['min_quantity'];
        $description = $_POST['description'];
        
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET item_name = :item_name, 
                category = :category, 
                quantity = :quantity, 
                unit_price = :unit_price, 
                supplier = :supplier, 
                location = :location, 
                min_quantity = :min_quantity, 
                description = :description
            WHERE id = :id
        ");
        
        $stmt->bindParam(':item_name', $itemName);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':unit_price', $unitPrice);
        $stmt->bindParam(':supplier', $supplier);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':min_quantity', $minQuantity);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':id', $itemId);
        
        if ($stmt->execute()) {
            $successMessage = "Item updated successfully!";
            // Redirect to avoid form resubmission
            header("Location: inventory.php?success=updated");
            exit();
        } else {
            $errorMessage = "Failed to update item.";
        }
    }
    
    // Delete inventory item
    if (isset($_POST['delete_item'])) {
        $itemId = $_POST['item_id'];
        
        $stmt = $conn->prepare("DELETE FROM inventory WHERE id = :id");
        $stmt->bindParam(':id', $itemId);
        
        if ($stmt->execute()) {
            $successMessage = "Item deleted successfully!";
            // Redirect to avoid form resubmission
            header("Location: inventory.php?success=deleted");
            exit();
        } else {
            $errorMessage = "Failed to delete item.";
        }
    }
    
    // Adjust inventory quantity
    if (isset($_POST['adjust_quantity'])) {
        $itemId = $_POST['item_id'];
        $adjustmentType = $_POST['adjustment_type'];
        $adjustmentQuantity = $_POST['adjustment_quantity'];
        
        // Get current quantity
        $stmt = $conn->prepare("SELECT quantity FROM inventory WHERE id = :id");
        $stmt->bindParam(':id', $itemId);
        $stmt->execute();
        $currentQuantity = $stmt->fetchColumn();
        
        // Calculate new quantity
        $newQuantity = $currentQuantity;
        if ($adjustmentType === 'add') {
            $newQuantity += $adjustmentQuantity;
        } elseif ($adjustmentType === 'subtract') {
            $newQuantity -= $adjustmentQuantity;
            if ($newQuantity < 0) {
                $newQuantity = 0;
            }
        } elseif ($adjustmentType === 'set') {
            $newQuantity = $adjustmentQuantity;
        }
        
        // Update quantity
        $stmt = $conn->prepare("UPDATE inventory SET quantity = :quantity WHERE id = :id");
        $stmt->bindParam(':quantity', $newQuantity);
        $stmt->bindParam(':id', $itemId);
        
        if ($stmt->execute()) {
            $successMessage = "Quantity adjusted successfully!";
            // Redirect to avoid form resubmission
            header("Location: inventory.php?success=adjusted");
            exit();
        } else {
            $errorMessage = "Failed to adjust quantity.";
        }
    }
}

// Get success/error messages from URL parameters
$successMessage = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $successMessage = "Item added successfully!";
            break;
        case 'updated':
            $successMessage = "Item updated successfully!";
            break;
        case 'deleted':
            $successMessage = "Item deleted successfully!";
            break;
        case 'adjusted':
            $successMessage = "Quantity adjusted successfully!";
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff6600;
            --primary-dark: #e65c00;
            --secondary: #222222;
            --light: #f8f9fa;
            --dark: #121212;
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
            color: var(--secondary);
            min-height: 100vh;
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
            overflow: hidden;
        }
        
        .dark-theme .card {
            background-color: #2d2d2d;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .card-header {
            padding: 15px 20px;
            background-color: var(--secondary);
            color: white;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body {
            padding: 20px;
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
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .dark-theme .form-control {
            background-color: #333;
            border-color: #444;
            color: #fff;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th, table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .dark-theme table th, 
        .dark-theme table td {
            border-bottom: 1px solid #444;
        }
        
        table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .dark-theme table th {
            background-color: #333;
        }
        
        table tr:hover {
            background-color: #f5f5f5;
        }
        
        .dark-theme table tr:hover {
            background-color: #3a3a3a;
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
        
        .badge-danger {
            background-color: var(--danger);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 50%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .dark-theme .modal-content {
            background-color: #2d2d2d;
            color: #fff;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        .dark-theme .close:hover {
            color: white;
        }
        
        .alert {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .dark-theme .alert-success {
            background-color: #1e4a30;
            color: #d4edda;
            border: 1px solid #2c663a;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .dark-theme .alert-danger {
            background-color: #4a1e1e;
            color: #f8d7da;
            border: 1px solid #662c2c;
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            margin-top: 5px;
        }
        
        .dark-theme .progress-bar {
            background-color: #444;
        }
        
        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            background-color: var(--danger);
        }
        
        .progress-bar-fill.low {
            background-color: var(--danger);
        }
        
        .progress-bar-fill.medium {
            background-color: var(--warning);
        }
        
        .progress-bar-fill.high {
            background-color: var(--success);
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 90%;
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
                <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php" class="active"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
                <li><a href="report.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <h1>Inventory Management</h1>
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
            
            <!-- Success/Error Messages -->
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $successMessage; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?>
                </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="card">
                <div class="card-header">
                    <h3>Inventory Filters</h3>
                </div>
                <div class="card-body">
                    <form action="inventory.php" method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Item name, category, supplier...">
                        </div>
                        
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select name="category" id="category" class="form-control">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="sort">Sort By</label>
                            <select name="sort" id="sort" class="form-control">
                                <option value="item_name" <?php echo $sort === 'item_name' ? 'selected' : ''; ?>>Item Name</option>
                                <option value="category" <?php echo $sort === 'category' ? 'selected' : ''; ?>>Category</option>
                                <option value="quantity" <?php echo $sort === 'quantity' ? 'selected' : ''; ?>>Quantity</option>
                                <option value="unit_price" <?php echo $sort === 'unit_price' ? 'selected' : ''; ?>>Unit Price</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="order">Order</label>
                            <select name="order" id="order" class="form-control">
                                <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                                <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Descending</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-success"><i class="fas fa-filter"></i> Apply Filters</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Inventory List -->
            <div class="card">
                <div class="card-header">
                    <h3>Inventory Items</h3>
                    <button class="btn" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Item</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Min. Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Supplier</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inventoryItems)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center">No inventory items found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inventoryItems as $item): ?>
                                        <?php 
                                            $ratio = $item['quantity'] / $item['min_quantity'];
                                            $statusClass = $ratio <= 0.3 ? 'badge-danger' : ($ratio <= 0.7 ? 'badge-warning' : 'badge-success');
                                            $statusText = $ratio <= 0.3 ? 'Low Stock' : ($ratio <= 0.7 ? 'Medium Stock' : 'In Stock');
                                        ?>
                                        <tr>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td><?php echo $item['min_quantity']; ?></td>
                                            <td>$<?php echo number_format($item['unit_price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($item['supplier'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($item['location'] ?? 'N/A'); ?></td>
                                            <td><span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                            <td>
                                                <button class="btn btn-sm" onclick="openViewModal(<?php echo $item['id']; ?>)"><i class="fas fa-eye"></i></button>
                                                <button class="btn btn-sm btn-success" onclick="openEditModal(<?php echo $item['id']; ?>)"><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-sm btn-warning" onclick="openAdjustModal(<?php echo $item['id']; ?>)"><i class="fas fa-sync-alt"></i></button>
                                                <button class="btn btn-sm btn-danger" onclick="openDeleteModal(<?php echo $item['id']; ?>)"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Item Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAddModal()">&times;</span>
            <h2>Add New Inventory Item</h2>
            <form action="inventory.php" method="POST">
                <div class="form-group">
                    <label for="add_item_name">Item Name</label>
                    <input type="text" name="item_name" id="add_item_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="add_category">Category</label>
                    <input type="text" name="category" id="add_category" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="add_quantity">Quantity</label>
                    <input type="number" name="quantity" id="add_quantity" class="form-control" min="0" required>
                </div>
                <div class="form-group">
                    <label for="add_unit_price">Unit Price ($)</label>
                    <input type="number" name="unit_price" id="add_unit_price" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="add_supplier">Supplier</label>
                    <input type="text" name="supplier" id="add_supplier" class="form-control">
                </div>
                <div class="form-group">
                    <label for="add_location">Location</label>
                    <input type="text" name="location" id="add_location" class="form-control">
                </div>
                <div class="form-group">
                    <label for="add_min_quantity">Minimum Quantity</label>
                    <input type="number" name="min_quantity" id="add_min_quantity" class="form-control" min="1" value="5" required>
                </div>
                <div class="form-group">
                    <label for="add_description">Description</label>
                    <textarea name="description" id="add_description" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" name="add_item" class="btn btn-success">Add Item</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Item Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2>Edit Inventory Item</h2>
            <form action="inventory.php" method="POST">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="form-group">
                    <label for="edit_item_name">Item Name</label>
                    <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_category">Category</label>
                    <input type="text" name="category" id="edit_category" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_quantity">Quantity</label>
                    <input type="number" name="quantity" id="edit_quantity" class="form-control" min="0" required>
                </div>
                <div class="form-group">
                    <label for="edit_unit_price">Unit Price ($)</label>
                    <input type="number" name="unit_price" id="edit_unit_price" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="edit_supplier">Supplier</label>
                    <input type="text" name="supplier" id="edit_supplier" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit_location">Location</label>
                    <input type="text" name="location" id="edit_location" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit_min_quantity">Minimum Quantity</label>
                    <input type="number" name="min_quantity" id="edit_min_quantity" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" name="update_item" class="btn btn-success">Update Item</button>
            </form>
        </div>
    </div>
    
    <!-- View Item Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeViewModal()">&times;</span>
            <h2>Item Details</h2>
            <div id="item_details"></div>
        </div>
    </div>
    
    <!-- Delete Item Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Delete Item</h2>
            <p>Are you sure you want to delete this item? This action cannot be undone.</p>
            <form action="inventory.php" method="POST">
                <input type="hidden" name="item_id" id="delete_item_id">
                <button type="submit" name="delete_item" class="btn btn-danger">Delete</button>
                <button type="button" class="btn" onclick="closeDeleteModal()">Cancel</button>
            </form>
        </div>
    </div>
    
    <!-- Adjust Quantity Modal -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeAdjustModal()">&times;</span>
            <h2>Adjust Quantity</h2>
            <form action="inventory.php" method="POST">
                <input type="hidden" name="item_id" id="adjust_item_id">
                <div class="form-group">
                    <label for="adjustment_type">Adjustment Type</label>
                    <select name="adjustment_type" id="adjustment_type" class="form-control" required>
                        <option value="add">Add to Current Quantity</option>
                        <option value="subtract">Subtract from Current Quantity</option>
                        <option value="set">Set to Specific Quantity</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="adjustment_quantity">Quantity</label>
                    <input type="number" name="adjustment_quantity" id="adjustment_quantity" class="form-control" min="1" required>
                </div>
                <button type="submit" name="adjust_quantity" class="btn btn-warning">Adjust Quantity</button>
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
        
        // Modal functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function openEditModal(id) {
            // Fetch item data via AJAX
            $.ajax({
                url: 'get_inventory_item.php',
                type: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    document.getElementById('edit_item_id').value = data.id;
                    document.getElementById('edit_item_name').value = data.item_name;
                    document.getElementById('edit_category').value = data.category;
                    document.getElementById('edit_quantity').value = data.quantity;
                    document.getElementById('edit_unit_price').value = data.unit_price;
                    document.getElementById('edit_supplier').value = data.supplier;
                    document.getElementById('edit_location').value = data.location;
                    document.getElementById('edit_min_quantity').value = data.min_quantity;
                    document.getElementById('edit_description').value = data.description;
                    
                    document.getElementById('editModal').style.display = 'block';
                },
                error: function() {
                    alert('Error fetching item data. Please try again.');
                }
            });
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function openViewModal(id) {
            // Fetch item data via AJAX
            $.ajax({
                url: 'get_inventory_item.php',
                type: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    let ratio = data.quantity / data.min_quantity;
                    let statusClass = ratio <= 0.3 ? 'badge-danger' : (ratio <= 0.7 ? 'badge-warning' : 'badge-success');
                    let statusText = ratio <= 0.3 ? 'Low Stock' : (ratio <= 0.7 ? 'Medium Stock' : 'In Stock');
                    
                    let detailsHtml = `
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Item Name:</div>
                                <div>${data.item_name}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Category:</div>
                                <div>${data.category}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Quantity:</div>
                                <div>${data.quantity}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Min. Quantity:</div>
                                <div>${data.min_quantity}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Unit Price:</div>
                                <div>$${parseFloat(data.unit_price).toFixed(2)}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Supplier:</div>
                                <div>${data.supplier || 'N/A'}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Location:</div>
                                <div>${data.location || 'N/A'}</div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Status:</div>
                                <div><span class="badge ${statusClass}">${statusText}</span></div>
                            </div>
                            <div style="display: flex; margin-bottom: 10px;">
                                <div style="width: 150px; font-weight: bold;">Description:</div>
                                <div>${data.description || 'N/A'}</div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('item_details').innerHTML = detailsHtml;
                    document.getElementById('viewModal').style.display = 'block';
                },
                error: function() {
                    alert('Error fetching item data. Please try again.');
                }
            });
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        function openDeleteModal(id) {
            document.getElementById('delete_item_id').value = id;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function openAdjustModal(id) {
            document.getElementById('adjust_item_id').value = id;
            document.getElementById('adjustModal').style.display = 'block';
        }
        
        function closeAdjustModal() {
            document.getElementById('adjustModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
