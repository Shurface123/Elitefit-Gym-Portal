<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "User ID is required";
    header("Location: archived-users.php");
    exit;
}

$archivedUserId = (int)$_GET['id'];

// Connect to database
$conn = connectDB();

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Get archived user details
    $stmt = $conn->prepare("SELECT * FROM archived_users WHERE id = ?");
    $stmt->execute([$archivedUserId]);
    $archivedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$archivedUser) {
        throw new Exception("Archived user not found");
    }
    
    // Parse the archived user data
    $userData = json_decode($archivedUser['user_data'], true);
    if (!$userData) {
        throw new Exception("Invalid archived user data");
    }
    
    // Check if the original user still exists and get current status
    $checkStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $checkStmt->execute([$archivedUser['original_id']]);
    $originalUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $restoredUserId = null;
    $userDataBefore = null;
    $userDataAfter = null;
    
    if ($originalUser) {
        // If the original user exists, restore their data and set status to Active
        $userDataBefore = json_encode($originalUser);
        
        // Prepare update fields from archived data (excluding id)
        $updateFields = [];
        $updateValues = [];
        
        // Get all columns from users table
        $columnsStmt = $conn->prepare("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME != 'id'
        ");
        $columnsStmt->execute();
        $availableColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build update query with archived data
        foreach ($userData as $field => $value) {
            if ($field !== 'id' && in_array($field, $availableColumns)) {
                $updateFields[] = "$field = ?";
                $updateValues[] = $value;
            }
        }
        
        // Ensure status is set to Active
        if (!array_key_exists('status', $userData)) {
            $updateFields[] = "status = ?";
            $updateValues[] = 'Active';
        } else {
            // Find status in updateFields and ensure it's Active
            $statusFound = false;
            foreach ($userData as $field => $value) {
                if ($field === 'status') {
                    $statusFound = true;
                    break;
                }
            }
            if (!$statusFound) {
                $updateFields[] = "status = ?";
                $updateValues[] = 'Active';
            } else {
                // Replace status value with Active
                $statusIndex = array_search('status', array_keys($userData));
                if ($statusIndex !== false) {
                    $updateValues[$statusIndex] = 'Active';
                }
            }
        }
        
        // Add user ID for WHERE clause
        $updateValues[] = $archivedUser['original_id'];
        
        // Execute update
        $updateSql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($updateValues);
        
        $restoredUserId = $archivedUser['original_id'];
        
        // Get updated user data
        $updatedStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $updatedStmt->execute([$restoredUserId]);
        $userDataAfter = json_encode($updatedStmt->fetch(PDO::FETCH_ASSOC));
        
        // Log status change
        $statusStmt = $conn->prepare("
            INSERT INTO user_status_history 
            (user_id, old_status, new_status, changed_by, change_reason, ip_address) 
            VALUES (?, ?, 'Active', ?, 'User restored from archive', ?)
        ");
        $statusStmt->execute([
            $restoredUserId,
            $originalUser['status'],
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR']
        ]);
        
    } else {
        // If the original user doesn't exist, restore with the original user ID
        $userDataBefore = json_encode(['status' => 'not_exists']);
        
        // Get all columns from users table
        $columnsStmt = $conn->prepare("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users'
        ");
        $columnsStmt->execute();
        $availableColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Prepare insert fields and values
        $fields = [];
        $placeholders = [];
        $values = [];
        
        // Include the original user ID
        if (in_array('id', $availableColumns)) {
            $fields[] = 'id';
            $placeholders[] = '?';
            $values[] = $archivedUser['original_id'];
        }
        
        // Add other fields from archived data
        foreach ($userData as $field => $value) {
            if ($field !== 'id' && in_array($field, $availableColumns)) {
                $fields[] = $field;
                $placeholders[] = '?';
                $values[] = $value;
            }
        }
        
        // Ensure status is set to Active
        if (!in_array('status', $fields)) {
            $fields[] = 'status';
            $placeholders[] = '?';
            $values[] = 'Active';
        } else {
            $statusIndex = array_search('status', $fields);
            if ($statusIndex !== false) {
                $values[$statusIndex] = 'Active';
            }
        }
        
        // Create SQL query
        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        // Execute query
        $insertStmt = $conn->prepare($sql);
        $insertStmt->execute($values);
        
        // Use the original user ID as the restored user ID
        $restoredUserId = $archivedUser['original_id'];
        
        // Get restored user data
        $restoredStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $restoredStmt->execute([$restoredUserId]);
        $userDataAfter = json_encode($restoredStmt->fetch(PDO::FETCH_ASSOC));
        
        // Log status change for restored user
        $statusStmt = $conn->prepare("
            INSERT INTO user_status_history 
            (user_id, old_status, new_status, changed_by, change_reason, ip_address) 
            VALUES (?, 'Archived', 'Active', ?, 'User restored from archive (recreated)', ?)
        ");
        $statusStmt->execute([
            $restoredUserId,
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR']
        ]);
    }
    
    // Log the restoration in restore_logs table
    $restoreLogStmt = $conn->prepare("
        INSERT INTO restore_logs 
        (archived_user_id, original_user_id, restored_user_id, admin_id, restore_reason, 
         restore_status, user_data_before, user_data_after, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?, 'success', ?, ?, ?, ?)
    ");
    
    $restoreReason = isset($_POST['restore_reason']) ? $_POST['restore_reason'] : 'User restoration requested by admin';
    
    $restoreLogStmt->execute([
        $archivedUserId,
        $archivedUser['original_id'],
        $restoredUserId,
        $_SESSION['user_id'],
        $restoreReason,
        $userDataBefore,
        $userDataAfter,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Update archived_users table with restore information
    $updateArchivedStmt = $conn->prepare("
        UPDATE archived_users 
        SET restore_count = restore_count + 1, last_restore_date = NOW() 
        WHERE id = ?
    ");
    $updateArchivedStmt->execute([$archivedUserId]);
    
    // Log the action in admin_logs
    $adminId = $_SESSION['user_id'];
    
    // Check the structure of admin_logs table to determine available columns
    $checkColumnsStmt = $conn->prepare("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'admin_logs'
    ");
    $checkColumnsStmt->execute();
    $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If admin_logs table doesn't exist, create it
    if (empty($columns)) {
        $createTableStmt = $conn->prepare("
            CREATE TABLE admin_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                log_message TEXT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45),
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $createTableStmt->execute();
        $columns = ['id', 'admin_id', 'action', 'log_message', 'timestamp', 'ip_address'];
    }
    
    // Prepare log message
    $logMessage = "Restored archived user: " . ($userData['name'] ?? $archivedUser['name']) . 
                  " (" . ($userData['email'] ?? $archivedUser['email']) . ") " .
                  "with role " . ($userData['role'] ?? $archivedUser['role']) . 
                  " - User ID: " . $restoredUserId;
    
    // Build dynamic SQL query based on available columns
    $sql = "INSERT INTO admin_logs (";
    $params = [];
    $placeholders = [];
    
    // Always include admin_id and action
    $sql .= "admin_id, action";
    $params[] = $adminId;
    $params[] = "restore_user";
    $placeholders[] = "?";
    $placeholders[] = "?";
    
    // Check for message column (could be 'details' or 'log_message')
    if (in_array('details', $columns)) {
        $sql .= ", details";
        $params[] = $logMessage;
        $placeholders[] = "?";
    } elseif (in_array('log_message', $columns)) {
        $sql .= ", log_message";
        $params[] = $logMessage;
        $placeholders[] = "?";
    }
    
    // Check for ip_address column
    if (in_array('ip_address', $columns)) {
        $sql .= ", ip_address";
        $params[] = $_SERVER['REMOTE_ADDR'];
        $placeholders[] = "?";
    }
    
    // Complete the SQL query
    $sql .= ") VALUES (" . implode(", ", $placeholders) . ")";
    
    // Execute the log query
    $logStmt = $conn->prepare($sql);
    $logStmt->execute($params);
    
    // Optional: Delete the archived user record (uncomment if you want to remove it after restore)
    // $deleteStmt = $conn->prepare("DELETE FROM archived_users WHERE id = ?");
    // $deleteStmt->execute([$archivedUserId]);
    
    // Commit transaction
    $conn->commit();
    
    // Set success message with user ID information
    $_SESSION['success_message'] = "User " . ($userData['name'] ?? $archivedUser['name']) . 
                                  " (ID: " . $restoredUserId . ") has been successfully restored and is now active.";
    
    // Redirect back to archived users page
    header("Location: archived-users.php");
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log the error in restore_logs table
    try {
        $errorLogStmt = $conn->prepare("
            INSERT INTO restore_logs 
            (archived_user_id, original_user_id, admin_id, restore_status, error_message, ip_address, user_agent) 
            VALUES (?, ?, ?, 'failed', ?, ?, ?)
        ");
        $errorLogStmt->execute([
            $archivedUserId,
            $archivedUser['original_id'] ?? 0,
            $_SESSION['user_id'],
            $e->getMessage(),
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $logError) {
        // If logging fails, continue with error handling
        error_log("Failed to log restore error: " . $logError->getMessage());
    }
    
    // Set error message
    $_SESSION['error_message'] = "Error restoring user: " . $e->getMessage();
    
    // Redirect back to archived users page
    header("Location: archived-users.php");
    exit;
}
?>