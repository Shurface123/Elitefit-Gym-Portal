<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Connect to database
$conn = connectDB();

// Get user data
$userId = $_SESSION['user_id'];

// Set response header
header('Content-Type: application/json');

// Check if request is AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Get action
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Process based on action
switch ($action) {
    case 'add':
        // Add new inventory item
        $name = trim($_POST['name']);
        $category = trim($_POST['category']);
        $quantity = (int)$_POST['quantity'];
        $min_quantity = (int)$_POST['min_quantity'];
        $unit_price = (float)$_POST['unit_price'];
        $supplier = trim($_POST['supplier']);
        $location = trim($_POST['location']);
        $description = trim($_POST['description']);
        
        if (empty($name) || empty($category) || $quantity < 0 || $min_quantity < 0) {
            echo json_encode(['success' => false, 'message' => 'Name, category, and valid quantities are required.']);
            exit;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO inventory (name, category, quantity, min_quantity, unit_price, supplier, location, description, updated_by)
            VALUES (:name, :category, :quantity, :min_quantity, :unit_price, :supplier, :location, :description, :updated_by)
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
        $stmt->bindParam(':min_quantity', $min_quantity, PDO::PARAM_INT);
        $stmt->bindParam(':unit_price', $unit_price);
        $stmt->bindParam(':supplier', $supplier);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':updated_by', $userId);
        
        if ($stmt->execute()) {
            $inventoryId = $conn->lastInsertId();
            
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, action)
                VALUES (:user_id, :action)
            ");
            $action = "Added new inventory item: $name (Quantity: $quantity)";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // Get the newly added item
            $getStmt = $conn->prepare("
                SELECT i.*, u.name as updated_by_name
                FROM inventory i
                LEFT JOIN users u ON i.updated_by = u.id
                WHERE i.id = :id
            ");
            $getStmt->bindParam(':id', $inventoryId);
            $getStmt->execute();
            $newItem = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Inventory item added successfully!',
                'item' => $newItem
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error adding inventory item.']);
        }
        break;
        
    case 'update':
        // Update inventory item
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $category = trim($_POST['category']);
        $quantity = (int)$_POST['quantity'];
        $min_quantity = (int)$_POST['min_quantity'];
        $unit_price = (float)$_POST['unit_price'];
        $supplier = trim($_POST['supplier']);
        $location = trim($_POST['location']);
        $description = trim($_POST['description']);
        
        if (empty($name) || empty($category) || $quantity < 0 || $min_quantity < 0) {
            echo json_encode(['success' => false, 'message' => 'Name, category, and valid quantities are required.']);
            exit;
        }
        
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET name = :name, category = :category, quantity = :quantity, min_quantity = :min_quantity, 
                unit_price = :unit_price, supplier = :supplier, location = :location, 
                description = :description, updated_by = :updated_by, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
        $stmt->bindParam(':min_quantity', $min_quantity, PDO::PARAM_INT);
        $stmt->bindParam(':unit_price', $unit_price);
        $stmt->bindParam(':supplier', $supplier);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':updated_by', $userId);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, action)
                VALUES (:user_id, :action)
            ");
            $action = "Updated inventory item: $name (New Quantity: $quantity)";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // Get the updated item
            $getStmt = $conn->prepare("
                SELECT i.*, u.name as updated_by_name
                FROM inventory i
                LEFT JOIN users u ON i.updated_by = u.id
                WHERE i.id = :id
            ");
            $getStmt->bindParam(':id', $id);
            $getStmt->execute();
            $updatedItem = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Inventory item updated successfully!',
                'item' => $updatedItem
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating inventory item.']);
        }
        break;
        
    case 'delete':
        // Delete inventory item
        $id = $_POST['id'];
        
        // Get item name for activity log
        $nameStmt = $conn->prepare("SELECT name FROM inventory WHERE id = :id");
        $nameStmt->bindParam(':id', $id);
        $nameStmt->execute();
        $itemName = $nameStmt->fetchColumn();
        
        $stmt = $conn->prepare("DELETE FROM inventory WHERE id = :id");
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, action)
                VALUES (:user_id, :action)
            ");
            $action = "Deleted inventory item: $itemName";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Inventory item deleted successfully!'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting inventory item.']);
        }
        break;
        
    case 'adjust':
        // Adjust inventory quantity
        $id = $_POST['id'];
        $adjustment = (int)$_POST['adjustment'];
        $reason = trim($_POST['reason']);
        
        // Get current quantity and name
        $currentStmt = $conn->prepare("SELECT name, quantity FROM inventory WHERE id = :id");
        $currentStmt->bindParam(':id', $id);
        $currentStmt->execute();
        $currentData = $currentStmt->fetch(PDO::FETCH_ASSOC);
        $currentQuantity = $currentData['quantity'];
        $itemName = $currentData['name'];
        
        // Calculate new quantity
        $newQuantity = $currentQuantity + $adjustment;
        
        // Ensure quantity doesn't go below zero
        if ($newQuantity < 0) {
            echo json_encode(['success' => false, 'message' => 'Error: Adjustment would result in negative quantity.']);
            exit;
        }
        
        // Update quantity
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET quantity = :quantity, updated_by = :updated_by, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->bindParam(':quantity', $newQuantity, PDO::PARAM_INT);
        $stmt->bindParam(':updated_by', $userId);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Log inventory transaction
            $transactionStmt = $conn->prepare("
                INSERT INTO inventory_transactions (inventory_id, previous_quantity, adjustment, new_quantity, reason, user_id)
                VALUES (:inventory_id, :previous_quantity, :adjustment, :new_quantity, :reason, :user_id)
            ");
            $transactionStmt->bindParam(':inventory_id', $id);
            $transactionStmt->bindParam(':previous_quantity', $currentQuantity);
            $transactionStmt->bindParam(':adjustment', $adjustment);
            $transactionStmt->bindParam(':new_quantity', $newQuantity);
            $transactionStmt->bindParam(':reason', $reason);
            $transactionStmt->bindParam(':user_id', $userId);
            $transactionStmt->execute();
            
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, action)
                VALUES (:user_id, :action)
            ");
            $action = "Adjusted inventory for $itemName: $adjustment units ($reason). New quantity: $newQuantity";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // Get the updated item
            $getStmt = $conn->prepare("
                SELECT i.*, u.name as updated_by_name
                FROM inventory i
                LEFT JOIN users u ON i.updated_by = u.id
                WHERE i.id = :id
            ");
            $getStmt->bindParam(':id', $id);
            $getStmt->execute();
            $updatedItem = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Inventory quantity adjusted successfully!',
                'item' => $updatedItem
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error adjusting inventory quantity.']);
        }
        break;
        
    case 'get':
        // Get inventory item details
        $id = $_POST['id'];
        
        $stmt = $conn->prepare("
            SELECT i.*, u.name as updated_by_name
            FROM inventory i
            LEFT JOIN users u ON i.updated_by = u.id
            WHERE i.id = :id
        ");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            echo json_encode([
                'success' => true, 
                'item' => $item
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        }
        break;
        
    case 'list':
        // Get inventory list with filters
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';
        $category = isset($_POST['category']) ? trim($_POST['category']) : '';
        $stockStatus = isset($_POST['stock_status']) ? trim($_POST['stock_status']) : '';
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
        $offset = ($page - 1) * $limit;
        
        // Build query
        $query = "
            SELECT i.*, u.name as updated_by_name
            FROM inventory i
            LEFT JOIN users u ON i.updated_by = u.id
            WHERE 1=1
        ";
        $countQuery = "SELECT COUNT(*) FROM inventory WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $searchTerm = "%$search%";
            $query .= " AND (i.name LIKE :search OR i.supplier LIKE :search OR i.location LIKE :search OR i.description LIKE :search)";
            $countQuery .= " AND (name LIKE :search OR supplier LIKE :search OR location LIKE :search OR description LIKE :search)";
            $params[':search'] = $searchTerm;
        }
        
        if (!empty($category)) {
            $query .= " AND i.category = :category";
            $countQuery .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        if (!empty($stockStatus)) {
            switch ($stockStatus) {
                case 'low':
                    $query .= " AND i.quantity <= i.min_quantity AND i.quantity > 0";
                    $countQuery .= " AND quantity <= min_quantity AND quantity > 0";
                    break;
                case 'out':
                    $query .= " AND i.quantity = 0";
                    $countQuery .= " AND quantity = 0";
                    break;
                case 'in':
                    $query .= " AND i.quantity > i.min_quantity";
                    $countQuery .= " AND quantity > min_quantity";
                    break;
            }
        }
        
        // Add sorting and pagination
        $query .= " ORDER BY i.name ASC LIMIT :limit OFFSET :offset";
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
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
        
        echo json_encode([
            'success' => true,
            'items' => $items,
            'pagination' => [
                'total' => $totalCount,
                'pages' => $totalPages,
                'current' => $page,
                'limit' => $limit
            ]
        ]);
        break;
        
    case 'categories':
        // Get inventory categories
        $stmt = $conn->prepare("SELECT DISTINCT category FROM inventory ORDER BY category");
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);
        break;
        
    case 'stats':
        // Get inventory statistics
        $statsStmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(quantity) as total_quantity,
                COUNT(CASE WHEN quantity <= min_quantity AND quantity > 0 THEN 1 END) as low_stock_count,
                COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock_count,
                SUM(quantity * unit_price) as total_value
            FROM inventory
        ");
        $statsStmt->execute();
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        break;
        
    case 'transactions':
        // Get recent inventory transactions
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 5;
        
        $query = "
            SELECT t.*, i.name as item_name, u.name as user_name
            FROM inventory_transactions t
            JOIN inventory i ON t.inventory_id = i.id
            JOIN users u ON t.user_id = u.id
        ";
        $params = [];
        
        if ($id) {
            $query .= " WHERE t.inventory_id = :id";
            $params[':id'] = $id;
        }
        
        $query .= " ORDER BY t.transaction_date DESC LIMIT :limit";
        $params[':limit'] = $limit;
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            if ($key == ':limit') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
