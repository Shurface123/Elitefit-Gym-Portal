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
    case 'schedule':
        // Schedule maintenance
        $equipmentId = $_POST['equipment_id'];
        $scheduledDate = $_POST['scheduled_date'];
        $description = trim($_POST['description']);
        $priority = trim($_POST['priority']);
        $assignedTo = isset($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
        
        if (empty($equipmentId) || empty($scheduledDate) || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Equipment, date, and description are required.']);
            exit;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO maintenance_schedule (equipment_id, scheduled_date, description, priority, assigned_to, status, created_by)
            VALUES (:equipment_id, :scheduled_date, :description, :priority, :assigned_to, 'Scheduled', :created_by)
        ");
        $stmt->bindParam(':equipment_id', $equipmentId);
        $stmt->bindParam(':scheduled_date', $scheduledDate);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':assigned_to', $assignedTo);
        $stmt->bindParam(':created_by', $userId);
        
        if ($stmt->execute()) {
            $maintenanceId = $conn->lastInsertId();
            
            // Get equipment name
            $equipStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
            $equipStmt->bindParam(':id', $equipmentId);
            $equipStmt->execute();
            $equipmentName = $equipStmt->fetchColumn();
            
            // Update equipment status if needed
            if (isset($_POST['update_status']) && $_POST['update_status'] == 1) {
                $updateStmt = $conn->prepare("
                    UPDATE equipment 
                    SET status = 'Maintenance', updated_by = :updated_by, updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->bindParam(':updated_by', $userId);
                $updateStmt->bindParam(':id', $equipmentId);
                $updateStmt->execute();
            }
            
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, equipment_id, action)
                VALUES (:user_id, :equipment_id, :action)
            ");
            $action = "Scheduled maintenance for $equipmentName on $scheduledDate";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':equipment_id', $equipmentId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // Get the newly added maintenance
            $getStmt = $conn->prepare("
                SELECT m.*, e.name as equipment_name, u.name as assigned_to_name
                FROM maintenance_schedule m
                JOIN equipment e ON m.equipment_id = e.id
                LEFT JOIN users u ON m.assigned_to = u.id
                WHERE m.id = :id
            ");
            $getStmt->bindParam(':id', $maintenanceId);
            $getStmt->execute();
            $newMaintenance = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Maintenance scheduled successfully!',
                'maintenance' => $newMaintenance
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error scheduling maintenance.']);
        }
        break;
        
    case 'update':
        // Update maintenance
        $id = $_POST['id'];
        $scheduledDate = $_POST['scheduled_date'];
        $description = trim($_POST['description']);
        $priority = trim($_POST['priority']);
        $status = trim($_POST['status']);
        $assignedTo = isset($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
        $completionNotes = trim($_POST['completion_notes'] ?? '');
        
        if (empty($scheduledDate) || empty($description) || empty($status)) {
            echo json_encode(['success' => false, 'message' => 'Date, description, and status are required.']);
            exit;
        }
        
        // Get current maintenance info
        $currentStmt = $conn->prepare("
            SELECT equipment_id, status FROM maintenance_schedule WHERE id = :id
        ");
        $currentStmt->bindParam(':id', $id);
        $currentStmt->execute();
        $currentInfo = $currentStmt->fetch(PDO::FETCH_ASSOC);
        $equipmentId = $currentInfo['equipment_id'];
        $oldStatus = $currentInfo['status'];
        
        // Prepare update query
        $query = "
            UPDATE maintenance_schedule 
            SET scheduled_date = :scheduled_date, description = :description, 
                priority = :priority, status = :status, assigned_to = :assigned_to
        ";
        
        // Add completion fields if status is Completed
        if ($status === 'Completed') {
            $query .= ", completion_date = NOW(), completion_notes = :completion_notes";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':scheduled_date', $scheduledDate);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':assigned_to', $assignedTo);
        
        if ($status === 'Completed') {
            $stmt->bindParam(':completion_notes', $completionNotes);
        }
        
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Get equipment name
            $equipStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
            $equipStmt->bindParam(':id', $equipmentId);
            $equipStmt->execute();
            $equipmentName = $equipStmt->fetchColumn();
            
            // Update equipment status if maintenance is completed
            if ($status === 'Completed' && isset($_POST['update_status']) && $_POST['update_status'] == 1) {
                $updateStmt = $conn->prepare("
                    UPDATE equipment 
                    SET status = 'Available', updated_by = :updated_by, updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->bindParam(':updated_by', $userId);
                $updateStmt->bindParam(':id', $equipmentId);
                $updateStmt->execute();
            }
            
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, equipment_id, action)
                VALUES (:user_id, :equipment_id, :action)
            ");
            
            if ($oldStatus !== $status) {
                $action = "Updated maintenance status for $equipmentName from $oldStatus to $status";
            } else {
                $action = "Updated maintenance details for $equipmentName";
            }
            
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':equipment_id', $equipmentId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            // Get the updated maintenance
            $getStmt = $conn->prepare("
                SELECT m.*, e.name as equipment_name, u.name as assigned_to_name
                FROM maintenance_schedule m
                JOIN equipment e ON m.equipment_id = e.id
                LEFT JOIN users u ON m.assigned_to = u.id
                WHERE m.id = :id
            ");
            $getStmt->bindParam(':id', $id);
            $getStmt->execute();
            $updatedMaintenance = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Maintenance updated successfully!',
                'maintenance' => $updatedMaintenance
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating maintenance.']);
        }
        break;
        
    case 'delete':
        // Delete maintenance
        $id = $_POST['id'];
        
        // Get maintenance info for activity log
        $infoStmt = $conn->prepare("
            SELECT m.equipment_id, e.name as equipment_name
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.id = :id
        ");
        $infoStmt->bindParam(':id', $id);
        $infoStmt->execute();
        $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$info) {
            echo json_encode(['success' => false, 'message' => 'Maintenance record not found.']);
            exit;
        }
        
        $equipmentId = $info['equipment_id'];
        $equipmentName = $info['equipment_name'];
        
        $stmt = $conn->prepare("DELETE FROM maintenance_schedule WHERE id = :id");
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Log activity
            $logStmt = $conn->prepare("
                INSERT INTO activity_log (user_id, equipment_id, action)
                VALUES (:user_id, :equipment_id, :action)
            ");
            $action = "Deleted maintenance record for $equipmentName";
            $logStmt->bindParam(':user_id', $userId);
            $logStmt->bindParam(':equipment_id', $equipmentId);
            $logStmt->bindParam(':action', $action);
            $logStmt->execute();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Maintenance record deleted successfully!'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting maintenance record.']);
        }
        break;
        
    case 'get':
        // Get maintenance details
        $id = $_POST['id'];
        
        $stmt = $conn->prepare("
            SELECT m.*, e.name as equipment_name, u.name as assigned_to_name
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            LEFT JOIN users u ON m.assigned_to = u.id
            WHERE m.id = :id
        ");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $maintenance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($maintenance) {
            echo json_encode([
                'success' => true, 
                'maintenance' => $maintenance
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Maintenance record not found.']);
        }
        break;
        
    case 'list':
        // Get maintenance list with filters
        $equipmentId = isset($_POST['equipment_id']) ? $_POST['equipment_id'] : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $priority = isset($_POST['priority']) ? trim($_POST['priority']) : '';
        $dateFrom = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
        $dateTo = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
        $offset = ($page - 1) * $limit;
        
        // Build query
        $query = "
            SELECT m.*, e.name as equipment_name, e.type as equipment_type, u.name as assigned_to_name
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            LEFT JOIN users u ON m.assigned_to = u.id
            WHERE 1=1
        ";
        $countQuery = "
            SELECT COUNT(*)
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($equipmentId) {
            $query .= " AND m.equipment_id = :equipment_id";
            $countQuery .= " AND m.equipment_id = :equipment_id";
            $params[':equipment_id'] = $equipmentId;
        }
        
        if (!empty($status)) {
            $query .= " AND m.status = :status";
            $countQuery .= " AND m.status = :status";
            $params[':status'] = $status;
        }
        
        if (!empty($priority)) {
            $query .= " AND m.priority = :priority";
            $countQuery .= " AND m.priority = :priority";
            $params[':priority'] = $priority;
        }
        
        if (!empty($dateFrom)) {
            $query .= " AND m.scheduled_date >= :date_from";
            $countQuery .= " AND m.scheduled_date >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        
        if (!empty($dateTo)) {
            $query .= " AND m.scheduled_date <= :date_to";
            $countQuery .= " AND m.scheduled_date <= :date_to";
            $params[':date_to'] = $dateTo;
        }
        
        // Add sorting and pagination
        $query .= " ORDER BY m.scheduled_date ASC LIMIT :limit OFFSET :offset";
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
        
        echo json_encode([
            'success' => true,
            'maintenance' => $maintenanceList,
            'pagination' => [
                'total' => $totalCount,
                'pages' => $totalPages,
                'current' => $page,
                'limit' => $limit
            ]
        ]);
        break;
        
    case 'stats':
        // Get maintenance statistics
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high_priority,
                SUM(CASE WHEN priority = 'Medium' THEN 1 ELSE 0 END) as medium_priority,
                SUM(CASE WHEN priority = 'Low' THEN 1 ELSE 0 END) as low_priority,
                COUNT(DISTINCT equipment_id) as equipment_count
            FROM maintenance_schedule
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get upcoming maintenance
        $upcomingStmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM maintenance_schedule
            WHERE status = 'Scheduled'
            AND scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $upcomingStmt->execute();
        $upcoming = $upcomingStmt->fetchColumn();
        
        // Get overdue maintenance
        $overdueStmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM maintenance_schedule
            WHERE status = 'Scheduled'
            AND scheduled_date < CURDATE()
        ");
        $overdueStmt->execute();
        $overdue = $overdueStmt->fetchColumn();
        
        $stats['upcoming'] = $upcoming;
        $stats['overdue'] = $overdue;
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        break;
        
    case 'calendar':
        // Get maintenance for calendar view
        $start = $_POST['start']; // Start date
        $end = $_POST['end']; // End date
        
        $stmt = $conn->prepare("
            SELECT 
                m.id,
                m.equipment_id,
                CONCAT(e.name, ' - ', m.description) as title,
                m.scheduled_date as start,
                DATE_ADD(m.scheduled_date, INTERVAL 1 HOUR) as end,
                m.status,
                m.priority,
                CASE 
                    WHEN m.priority = 'High' THEN '#dc3545'
                    WHEN m.priority = 'Medium' THEN '#fd7e14'
                    ELSE '#28a745'
                END as color
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.scheduled_date BETWEEN :start AND :end
        ");
        $stmt->bindParam(':start', $start);
        $stmt->bindParam(':end', $end);
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'events' => $events
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
